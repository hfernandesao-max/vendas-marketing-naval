<?php
/**
 * Inteligência de negócio › Valor do portal: quanto o portal rende à Naval, mês a mês, com os dados reais.
 *
 * Contagens reais (nunca estimadas):
 *   carrinhas    dados-privados/carrinha/AAAA-MM-DD/<rota>.json: rotas conciliadas, fechos automáticos,
 *                faltas e sobras (Kz) aprovadas
 *   SAP          dados-privados/sap/lotes.json: ficheiros emitidos (sem os anulados)
 *   relatórios   registos de auditoria: exportações de relatórios e utilizadores distintos que entraram no mês
 *   anulados     Pinguim BO (só SELECT): documentos com DOCHEADER.DOCSTATUS_ID anulado (8002) que o portal deixou
 *                fora das vendas e do SAP. É valor corrigido, não poupança: mostra-se à parte e não entra no total.
 *
 * Parâmetros (definidos no portal por quem tem o nível G, com registo; nunca de Excel): o custo de uma hora de
 * trabalho, os minutos que cada tarefa automática poupa, a perda média de stock por rota antes do portal, a licença
 * de uma solução comercial equivalente por utilizador e o custo de reconstrução do portal com a amortização.
 * Um parâmetro a zero fica «por definir» e a parcela correspondente não entra no total.
 *
 * Fórmula por mês:
 *   horas      = (fechos automáticos × min_fecho + rotas conciliadas × min_conciliacao + ficheiros SAP × min_sap
 *                 + exportações × min_relatorio) ÷ 60
 *   tempo      = horas × custo da hora
 *   stock      = max(0, perda de referência × rotas activas − faltas aprovadas)
 *   licenças   = utilizadores activos × licença por utilizador
 *   construção = custo de reconstrução ÷ (anos de amortização × 12)
 *   total      = tempo + stock + licenças + construção
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/vendas.php';

const NAVAL_VALOR_PARAMETROS = [
    'custo_hora_kz' => ['Custo de uma hora de trabalho (Kz)', 0],
    'min_fecho' => ['Minutos poupados por rota fechada automaticamente', 20],
    'min_conciliacao' => ['Minutos poupados por rota conciliada no portal (em vez de papel ou Excel)', 15],
    'min_sap' => ['Minutos poupados por ficheiro SAP gerado', 120],
    'min_relatorio' => ['Minutos poupados por relatório exportado', 15],
    'perda_referencia_kz' => ['Perda média de stock por rota e por mês antes do portal (Kz)', 0],
    'licenca_utilizador_kz' => ['Licença de uma solução comercial equivalente, por utilizador e por mês (Kz)', 0],
    'reconstrucao_kz' => ['Custo de fazer o portal de novo com uma empresa de software (Kz)', 0],
    'anos_amortizacao' => ['Anos de amortização do custo de reconstrução', 5],
    'cambio_eur_kz' => ['Câmbio: Kz por 1 euro (para mostrar também em euros)', 0],
];

function naval_valor_ficheiro(): string
{
    return naval_pasta('valor') . '/parametros.json';
}

function naval_valor_parametros(): array
{
    $g = naval_json_ler(naval_valor_ficheiro());
    $out = [];
    foreach (NAVAL_VALOR_PARAMETROS as $k => [, $omissao]) $out[$k] = isset($g[$k]) && is_numeric($g[$k]) ? (float) $g[$k] : (float) $omissao;
    return $out;
}

function naval_valor_gravar_parametros(array $novos, array $eu): array
{
    $actual = naval_valor_parametros();
    $mudou = [];
    foreach (NAVAL_VALOR_PARAMETROS as $k => $_) {
        if (!array_key_exists($k, $novos)) continue;
        if (!is_numeric($novos[$k]) || (float) $novos[$k] < 0 || (float) $novos[$k] > 1e12) throw new NavalErro('Valor inválido num dos parâmetros.', 400, ['campo' => $k]);
        $v = round((float) $novos[$k], 2);
        if ($k === 'anos_amortizacao' && $v < 1) throw new NavalErro('A amortização tem de ser de pelo menos 1 ano.', 400);
        if ($v !== $actual[$k]) $mudou[$k] = [$actual[$k], $v];
        $actual[$k] = $v;
    }
    naval_json_gravar(naval_valor_ficheiro(), $actual + ['alterado_em' => date('Y-m-d H:i:s'), 'alterado_por' => (string) ($eu['login'] ?? '')]);
    if ($mudou) naval_registar('valor_parametros', ['alteracoes' => $mudou]);
    return $actual;
}

/** Meses AAAA-MM do mais antigo ao actual. */
function naval_valor_meses(int $n, int $agora): array
{
    $out = [];
    $base = strtotime(date('Y-m-01', $agora));
    for ($i = $n - 1; $i >= 0; $i--) $out[] = date('Y-m', (int) strtotime("-$i month", $base));
    return $out;
}

/** Carrinhas por mês: rotas activas, conciliadas, fechos automáticos, faltas e sobras aprovadas. */
function naval_valor_carrinhas(array $meses): array
{
    $out = array_fill_keys($meses, ['rotas' => 0, 'conciliadas' => 0, 'automaticas' => 0, 'falta_kz' => 0.0, 'sobra_kz' => 0.0]);
    $rotasMes = [];
    foreach (glob(naval_pasta('carrinha') . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $dir) {
        $m = substr(basename($dir), 0, 7);
        if (!isset($out[$m])) continue;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (basename($f)[0] === '_') continue;
            $r = naval_json_ler($f);
            if (!$r) continue;
            $rotasMes[$m][basename($f, '.json')] = true;
            if (($r['estado'] ?? '') !== 'aprovada') continue;
            $out[$m]['conciliadas']++;
            if (($r['aprovado_por'] ?? '') === 'automatico') $out[$m]['automaticas']++;
            $ult = end($r['historico']) ?: [];
            $out[$m]['falta_kz'] += (float) ($ult['falta_kz'] ?? 0);
            $out[$m]['sobra_kz'] += (float) ($ult['sobra_kz'] ?? 0);
        }
    }
    foreach ($rotasMes as $m => $rs) $out[$m]['rotas'] = count($rs);
    return $out;
}

/** Ficheiros SAP emitidos por mês (os anulados não contam). */
function naval_valor_sap(array $meses): array
{
    $out = array_fill_keys($meses, 0);
    foreach (naval_json_ler(naval_pasta('sap') . '/lotes.json') as $l) {
        if (!is_array($l) || !empty($l['anulado'])) continue;
        $m = substr((string) ($l['em'] ?? ''), 0, 7);
        if (isset($out[$m])) $out[$m]++;
    }
    return $out;
}

/** Registos de auditoria por mês: exportações de relatórios e utilizadores distintos que entraram. */
function naval_valor_registos(array $meses): array
{
    $out = [];
    foreach ($meses as $m) {
        $exp = 0;
        $quem = [];
        $f = naval_registos_pasta() . '/acessos-' . $m . '.jsonl';
        $h = is_file($f) ? fopen($f, 'r') : false;
        while ($h && ($linha = fgets($h)) !== false) {
            // leitura rápida: só descodifica as linhas que interessam
            if (strpos($linha, '"accao":"exportacao"') !== false) $exp++;
            elseif (strpos($linha, '"accao":"entrada"') !== false) {
                $j = json_decode($linha, true);
                if (!empty($j['utilizador'])) $quem[$j['utilizador']] = true;
            }
        }
        if ($h) fclose($h);
        $out[$m] = ['exportacoes' => $exp, 'utilizadores' => count($quem)];
    }
    return $out;
}

/** Documentos anulados no Pinguim BO por mês (quantidade e valor); null sem base de dados. */
function naval_valor_anulados(array $meses): ?array
{
    if (!naval_bd_configurada() || !$meses) return null;
    $ids = implode(', ', naval_vendas_estados_anulados());
    $de = $meses[0] . '-01';
    $ate = date('Y-m-t', (int) strtotime(end($meses) . '-01'));
    try {
        $linhas = naval_bd_consultar("
            SELECT CONVERT(VARCHAR(7), DH.DOC_DATE, 120) AS MES,
                   COUNT(*) AS N,
                   CAST(SUM(ABS(DH.CALC_TOTAL_W_TAX)) AS FLOAT) AS VALOR
            FROM DOCHEADER DH
            WHERE DH.DELETED = 0
              AND DH.DOCSTATUS_ID IN ($ids)
              AND DH.DOC_DATE >= :de
              AND DH.DOC_DATE <= :ate
            GROUP BY CONVERT(VARCHAR(7), DH.DOC_DATE, 120)", [':de' => naval_bd_data($de), ':ate' => naval_bd_data($ate)]);
    } catch (Throwable $e) {
        error_log('[valor anulados] ' . $e->getMessage());
        return null;
    }
    $out = array_fill_keys($meses, ['documentos' => 0, 'valor_kz' => 0.0]);
    foreach ($linhas as $l) {
        if (isset($out[$l['MES']])) $out[$l['MES']] = ['documentos' => (int) $l['N'], 'valor_kz' => round((float) $l['VALOR'], 2)];
    }
    return $out;
}

/** Aplica a fórmula a um mês. Cada parcela é null quando falta o parâmetro de que depende. */
function naval_valor_calcular(array $c, int $sap, array $reg, array $p): array
{
    $horas = ($c['automaticas'] * $p['min_fecho'] + $c['conciliadas'] * $p['min_conciliacao'] + $sap * $p['min_sap'] + $reg['exportacoes'] * $p['min_relatorio']) / 60;
    $tempo = $p['custo_hora_kz'] > 0 ? $horas * $p['custo_hora_kz'] : null;
    $stock = $p['perda_referencia_kz'] > 0 && $c['rotas'] > 0 ? max(0.0, $p['perda_referencia_kz'] * $c['rotas'] - $c['falta_kz']) : null;
    $licencas = $p['licenca_utilizador_kz'] > 0 ? $reg['utilizadores'] * $p['licenca_utilizador_kz'] : null;
    $construcao = $p['reconstrucao_kz'] > 0 ? $p['reconstrucao_kz'] / (max(1, $p['anos_amortizacao']) * 12) : null;
    $partes = array_filter([$tempo, $stock, $licencas, $construcao], function ($x) { return $x !== null; });
    return [
        'horas' => round($horas, 1),
        'tempo_kz' => $tempo === null ? null : round($tempo, 2),
        'stock_kz' => $stock === null ? null : round($stock, 2),
        'licencas_kz' => $licencas === null ? null : round($licencas, 2),
        'construcao_kz' => $construcao === null ? null : round($construcao, 2),
        'total_kz' => $partes ? round(array_sum($partes), 2) : null,
    ];
}

/** Valor do portal nos últimos $n meses (o actual incluído), com os totais e o que falta definir. */
function naval_valor_vista(int $n, int $agora): array
{
    $n = max(1, min(24, $n));
    $meses = naval_valor_meses($n, $agora);
    $p = naval_valor_parametros();
    $car = naval_valor_carrinhas($meses);
    $sap = naval_valor_sap($meses);
    $reg = naval_valor_registos($meses);
    $anul = naval_valor_anulados($meses);
    $linhas = [];
    foreach ($meses as $m) {
        $linhas[] = ['mes' => $m] + $car[$m] + ['ficheiros_sap' => $sap[$m]] + $reg[$m]
            + ['anulados' => $anul[$m]['documentos'] ?? null, 'anulados_kz' => $anul[$m]['valor_kz'] ?? null]
            + naval_valor_calcular($car[$m], $sap[$m], $reg[$m], $p);
    }
    $soma = function (string $k) use ($linhas) {
        $v = array_filter(array_column($linhas, $k), function ($x) { return $x !== null; });
        return $v ? round(array_sum($v), 2) : null;
    };
    $tot = [];
    foreach (['horas', 'tempo_kz', 'stock_kz', 'licencas_kz', 'construcao_kz', 'total_kz', 'falta_kz', 'conciliadas', 'automaticas', 'ficheiros_sap', 'exportacoes', 'anulados', 'anulados_kz'] as $k) $tot[$k] = $soma($k);
    $mesesComDados = count(array_filter($linhas, function ($l) { return $l['conciliadas'] || $l['ficheiros_sap'] || $l['exportacoes']; }));
    $tot['anual_kz'] = $tot['total_kz'] !== null && $mesesComDados ? round($tot['total_kz'] / $mesesComDados * 12, 2) : null;
    $falta = [];
    foreach (['custo_hora_kz', 'perda_referencia_kz', 'licenca_utilizador_kz', 'reconstrucao_kz'] as $k) if ($p[$k] <= 0) $falta[] = $k;
    $g = naval_json_ler(naval_valor_ficheiro());
    return [
        'meses' => $linhas, 'totais' => $tot, 'meses_com_dados' => $mesesComDados,
        'parametros' => $p, 'nomes' => array_map(function ($x) { return $x[0]; }, NAVAL_VALOR_PARAMETROS),
        'por_definir' => $falta, 'pinguim' => $anul !== null,
        'alterado_em' => $g['alterado_em'] ?? null, 'alterado_por' => $g['alterado_por'] ?? null,
    ];
}
