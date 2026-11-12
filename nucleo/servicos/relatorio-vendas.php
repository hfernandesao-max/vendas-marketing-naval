<?php
/**
 * Relatório de Vendas diário do Marketing: junta num só documento o que hoje vem em quatro ficheiros (resumo de vendas,
 * registo de vendas, folha de caixa e stock), lido das bases de dados e nunca de Excel.
 *
 * Fecho do dia antes do relatório (prática de «close readiness» / controlo de completude dos dados): o relatório só
 * se gera quando todas as fontes do dia estão completas e fechadas:
 *   1. Pinguim BO: todos os telemóveis das rotas com vendas sincronizaram depois da hora de fecho (RELATORIO_HORA_FECHO);
 *   2. conciliação de todas as carrinhas com movimento fechada (contagem, validação e aprovações);
 *   3. vendas do dia no SAP: todos os documentos estão num ficheiro de carregamento marcado «carregado no SAP»;
 *   4. folha de caixa do dia fechada e aprovada;
 *   5. SAP (facturação das outras empresas): lida pelo conector OData; enquanto não estiver ligado fica «por ligar»
 *      e essa parte do relatório sai assinalada (não impede o resto).
 * Uma vez gerado, o relatório fica congelado (fotografia dos dados, com impressão digital SHA-256 e número de versão):
 * o PDF e o Excel saem sempre da mesma fotografia. Uma correcção posterior gera uma nova versão, com motivo.
 * Ficheiros: dados-privados/relatorio-vendas/AAAA-MM-DD/v<n>.json.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/caixa.php';
require_once __DIR__ . '/sap-odata.php';

/** Controlos de prontidão do dia, a partir das fontes já lidas (função pura, testável). */
function naval_rv_controlos(string $dia, array $f): array
{
    $c = [];
    // 1. sincronização dos telemóveis
    $rotasVendas = array_values(array_unique(array_map(function ($v) { return (string) $v['rota']; }, (array) ($f['vendas'] ?? []))));
    sort($rotasVendas);
    if ($f['vendas'] === null) {
        $c['pinguim'] = ['estado' => 'erro', 'detalhe' => 'Sem ligação ao Pinguim BO.'];
    } else {
        $hora = naval_config('RELATORIO_HORA_FECHO', '18:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = '18:00';
        $limite = $dia . ' ' . $hora . ':00';
        $ultima = [];
        foreach ((array) ($f['sync'] ?? []) as $s) $ultima[(string) $s['rota']] = (string) ($s['ultima'] ?? '');
        $faltam = array_values(array_filter($rotasVendas, function ($r) use ($ultima, $limite) { return ($ultima[$r] ?? '') < $limite; }));
        $c['pinguim'] = $faltam
            ? ['estado' => 'pendente', 'detalhe' => 'Rotas sem sincronizar depois das ' . $hora . ': ' . implode(', ', $faltam) . '.', 'rotas' => $faltam]
            : ['estado' => 'ok', 'detalhe' => count($rotasVendas) . ' rotas com vendas, todas sincronizadas depois das ' . $hora . '.'];
    }
    // 2. conciliação das carrinhas
    if ($f['carrinhas'] === null) {
        $c['conciliacao'] = ['estado' => 'erro', 'detalhe' => 'Não foi possível ler a conciliação das carrinhas.'];
    } else {
        $abertas = [];
        foreach ($f['carrinhas'] as $r) if (($r['resumo']['estado'] ?? '') !== 'aprovada') $abertas[] = $r['rota'];
        $c['conciliacao'] = $abertas
            ? ['estado' => 'pendente', 'detalhe' => 'Carrinhas por fechar: ' . implode(', ', $abertas) . '.', 'rotas' => $abertas]
            : ['estado' => 'ok', 'detalhe' => count($f['carrinhas']) . ' carrinhas conciliadas e fechadas.'];
    }
    // 3. vendas carregadas no SAP
    if ($f['vendas'] !== null) {
        $lotes = (array) ($f['lotes'] ?? []);
        $estadoLote = array_column($lotes, 'estado', 'numero');
        $emitidos = naval_sap_exportados_rv($lotes);
        $fora = 0;
        $porCarregar = [];
        foreach ($f['vendas'] as $v) {
            $l = $emitidos[(string) $v['documento']] ?? null;
            if ($l === null) $fora++;
            elseif (($estadoLote[$l] ?? '') !== 'carregado') $porCarregar[$l] = true;
        }
        $c['sap_ficheiro'] = $fora
            ? ['estado' => 'pendente', 'detalhe' => $fora . ' documento(s) ainda sem ficheiro para o SAP.']
            : ($porCarregar ? ['estado' => 'pendente', 'detalhe' => 'Ficheiro(s) por confirmar como carregados no SAP: lote ' . implode(', ', array_keys($porCarregar)) . '.']
                : ['estado' => 'ok', 'detalhe' => count($f['vendas']) . ' documentos carregados no SAP.']);
    }
    // 4. caixa
    $cx = $f['caixa'];
    $c['caixa'] = ($cx['estado'] ?? '') === 'aprovada'
        ? ['estado' => 'ok', 'detalhe' => 'Folha de caixa aprovada por ' . ($cx['aprovada_por'] ?? '') . '.']
        : ['estado' => 'pendente', 'detalhe' => ($cx['estado'] ?? 'aberta') === 'fechada' ? 'Folha de caixa fechada, por aprovar.' : 'Folha de caixa por fechar.'];
    // 5. SAP (outras empresas)
    $c['sap_odata'] = !($f['sap_configurado'] ?? false)
        ? ['estado' => 'por_ligar', 'detalhe' => 'Conector SAP por ligar: o relatório mostra só as vendas RTM do Pinguim BO.']
        : ($f['sap'] === null ? ['estado' => 'erro', 'detalhe' => 'O SAP não respondeu.'] : ['estado' => 'ok', 'detalhe' => count($f['sap']) . ' linhas de facturação lidas do SAP.']);
    $bloqueia = array_filter($c, function ($x) { return in_array($x['estado'], ['pendente', 'erro'], true); });
    return ['controlos' => $c, 'pronto' => !$bloqueia, 'dia' => $dia];
}

/** Documentos → número do lote válido (como naval_sap_exportados, sem depender de sap.php). */
function naval_sap_exportados_rv(array $lotes): array
{
    $out = [];
    foreach ($lotes as $l) {
        if (($l['estado'] ?? 'emitido') === 'anulado') continue;
        foreach ((array) ($l['documentos'] ?? []) as $d) if (!isset($out[$d])) $out[$d] = $l['numero'];
    }
    return $out;
}

/** Meio de cobrança: a mesma regra do portal (reports/js/meios.js, catMeio). */
function naval_rv_meio(array $p): string
{
    $c = strtoupper((string) ($p['codigo'] ?? ''));
    $m = strtoupper((string) ($p['meio'] ?? ''));
    if ($c === 'CMP') return 'compensacao';
    if ($c === 'NU' || preg_match('/NUMER|DINHEIRO|CASH/u', $m)) return 'dinheiro';
    if ($c === 'MC' || preg_match('/MULTI|TPA|CART|CARD/u', $m)) return 'multicaixa';
    return 'outro';
}

/** Conteúdo do relatório (função pura, a partir das fontes já lidas). */
function naval_rv_montar(string $dia, array $f, array $promotores): array
{
    $r2 = function ($v) { return round((float) $v, 2); };
    $vendas = (array) $f['vendas'];
    $artigos = (array) ($f['artigos'] ?? []);
    $caixa = (array) $f['caixa'];
    // 1. por promotor: vendas, cobranças por meio (Pinguim BO) e o que foi entregue na caixa e nos talões do TPA
    $prom = [];
    $novo = function ($rota) use ($promotores) {
        return ['rota' => (string) $rota, 'promotor' => $promotores[(string) $rota] ?? (string) $rota, 'documentos' => 0, 'notas_credito' => 0.0, 'vendas' => 0.0,
            'dinheiro' => 0.0, 'multicaixa' => 0.0, 'outros' => 0.0, 'por_receber' => 0.0, 'entregue' => 0.0, 'tpa' => 0.0];
    };
    foreach ($vendas as $v) {
        $k = (string) $v['rota'];
        $prom[$k] = $prom[$k] ?? $novo($k);
        $p = $prom[$k];
        if (!empty($v['nc'])) {
            $p['notas_credito'] += -(float) $v['total'];
            $p['vendas'] += (float) $v['total'];
        } else {
            $p['documentos'] += 1;
            $p['vendas'] += (float) $v['total'];
            $pago = 0.0;
            foreach ((array) ($v['pagamentos'] ?? []) as $pg) {
                $cat = naval_rv_meio((array) $pg);
                if ($cat === 'compensacao') continue;
                $val = (float) ($pg['valor'] ?? 0);
                $pago += $val;
                if ($cat === 'dinheiro') $p['dinheiro'] += $val;
                elseif ($cat === 'multicaixa') $p['multicaixa'] += $val;
                else $p['outros'] += $val;
            }
            $p['por_receber'] += max(0.0, (float) $v['total'] - $pago);
        }
        $prom[$k] = $p;
    }
    foreach ((array) ($caixa['entregas'] ?? []) as $e) {
        $prom[$e['rota']] = $prom[$e['rota']] ?? $novo($e['rota']);
        $prom[$e['rota']]['entregue'] += (float) $e['valor'];
    }
    foreach ((array) ($caixa['tpa'] ?? []) as $e) {
        $prom[$e['rota']] = $prom[$e['rota']] ?? $novo($e['rota']);
        $prom[$e['rota']]['tpa'] += (float) $e['valor'];
    }
    foreach ($prom as $k => $p) {
        foreach (['notas_credito', 'vendas', 'dinheiro', 'multicaixa', 'outros', 'por_receber', 'entregue', 'tpa'] as $c) $p[$c] = $r2($p[$c]);
        $p['dif_dinheiro'] = $r2($p['entregue'] - $p['dinheiro']);
        $p['dif_tpa'] = $r2($p['tpa'] - $p['multicaixa']);
        $prom[$k] = $p;
    }
    ksort($prom);
    $prom = array_values($prom);
    $soma = function (string $k) use ($prom, $r2) { return $r2(array_sum(array_column($prom, $k))); };
    $resumo = [
        'documentos' => array_sum(array_column($prom, 'documentos')), 'vendas' => $soma('vendas'), 'notas_credito' => $soma('notas_credito'),
        'dinheiro' => $soma('dinheiro'), 'multicaixa' => $soma('multicaixa'), 'outros' => $soma('outros'), 'por_receber' => $soma('por_receber'),
        'entregue' => $soma('entregue'), 'tpa' => $soma('tpa'), 'dif_dinheiro' => $soma('dif_dinheiro'), 'dif_tpa' => $soma('dif_tpa'),
        'promotores' => count(array_filter($prom, function ($p) { return $p['documentos'] > 0; })),
    ];
    // 2. registo de vendas (linha a linha, por promotor e documento)
    $registo = [];
    foreach ($artigos as $a) {
        $q = (float) ($a['quantidade'] ?? 0);
        $registo[] = ['rota' => (string) $a['rota'], 'promotor' => $promotores[(string) $a['rota']] ?? (string) $a['rota'], 'documento' => (string) $a['documento'],
            'codigo' => (string) $a['codigo'], 'artigo' => (string) $a['artigo'], 'unidade' => (string) ($a['unidade'] ?? ''), 'quantidade' => $q,
            'preco' => $q ? $r2((float) $a['total'] / $q) : 0.0, 'valor' => $r2($a['total']), 'nc' => !empty($a['nc'])];
    }
    usort($registo, function ($a, $b) { return [$a['rota'], $a['documento'], $a['codigo']] <=> [$b['rota'], $b['documento'], $b['codigo']]; });
    // 3. stock das carrinhas (inicial, carregado, vendido, devolvido, final) da conciliação
    $stock = [];
    foreach ((array) $f['carrinhas'] as $r) {
        foreach ((array) $r['linhas'] as $l) {
            $stock[] = ['rota' => (string) $r['rota'], 'promotor' => (string) $r['promotor'], 'codigo' => (string) $l['codigo'], 'artigo' => (string) $l['artigo'],
                'unidade' => (string) ($l['unidade'] ?? ''), 'inicial' => (float) ($l['inicial'] ?? 0), 'carregado' => (float) ($l['carregado'] ?? 0),
                'vendido' => (float) ($l['vendido'] ?? 0), 'devolvido' => (float) ($l['devolvido'] ?? 0), 'final' => (float) ($l['final'] ?? 0),
                'contado' => $l['contado'] ?? null, 'diferenca' => $l['diferenca'] ?? null];
        }
    }
    // 4. caixa
    $cx = [
        'saldo_inicial' => $caixa['saldo_inicial'] ?? null, 'entregas' => $caixa['total_entregas'] ?? 0, 'tpa' => (array) ($caixa['tpa'] ?? []),
        'total_tpa' => $caixa['total_tpa'] ?? 0, 'saidas' => (array) ($caixa['saidas'] ?? []), 'total_saidas' => $caixa['total_saidas'] ?? 0,
        'saldo_teorico' => $caixa['saldo_teorico'] ?? null, 'contagem_fisica' => $caixa['contagem_fisica'] ?? null, 'diferenca' => $caixa['diferenca'] ?? null,
        'explicacao' => $caixa['explicacao'] ?? '', 'fechada_por' => $caixa['fechada_por'] ?? null, 'aprovada_por' => $caixa['aprovada_por'] ?? null,
    ];
    // 5. SAP (todas as empresas), quando ligado
    $sap = $f['sap'] === null ? null : ['resumo' => naval_sapod_resumo((array) $f['sap']), 'linhas' => (array) $f['sap']];
    return ['dia' => $dia, 'resumo' => $resumo, 'promotores' => $prom, 'registo' => $registo, 'stock' => $stock, 'caixa' => $cx, 'sap' => $sap];
}

/** Lê todas as fontes do dia (cada uma pode falhar sem impedir as outras de aparecer nos controlos). */
function naval_rv_fontes(string $dia): array
{
    naval_servico('bd');
    naval_servico('vendas');
    naval_servico('rotas');
    naval_servico('carrinha');
    naval_servico('sap');
    naval_servico('sincronizacao');
    $d8 = str_replace('-', '', $dia);
    $v = naval_vendas_sql($d8, $d8, naval_promotores(), true);
    $f = ['vendas' => $v['vendas'] ?? null, 'artigos' => $v['artigos'] ?? null];
    if ($f['vendas'] !== null && !$f['artigos']) $f['artigos'] = naval_vendas_artigos_sql($d8, $d8, naval_promotores()) ?? [];
    try {
        $f['sync'] = naval_sincronizacao()['rotas'];
    } catch (Throwable $e) {
        $f['sync'] = [];
    }
    try {
        $f['carrinhas'] = naval_carr_vista($dia, null, false)['rotas'];
    } catch (Throwable $e) {
        $f['carrinhas'] = null;
    }
    $f['lotes'] = naval_sap_lotes();
    $f['caixa'] = naval_caixa_ler($dia);
    $f['sap_configurado'] = naval_sapod_configurado();
    $f['sap'] = null;
    if ($f['sap_configurado']) {
        try {
            $f['sap'] = naval_sapod_facturacao($dia);
        } catch (Throwable $e) {
            error_log('[relatorio SAP] ' . $e->getMessage());
        }
    }
    return $f;
}

function naval_rv_pasta(string $dia): string
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    return naval_pasta('relatorio-vendas/' . $dia);
}

/** Versões geradas do dia (sem o conteúdo). */
function naval_rv_versoes(string $dia): array
{
    $out = [];
    foreach ((array) glob(naval_rv_pasta($dia) . '/v*.json') as $f) {
        $r = naval_json_ler((string) $f);
        if ($r) $out[] = array_diff_key($r, ['dados' => 1]);
    }
    usort($out, function ($a, $b) { return $a['versao'] <=> $b['versao']; });
    return $out;
}

function naval_rv_ler(string $dia, int $versao): ?array
{
    return naval_json_ler(naval_rv_pasta($dia) . '/v' . $versao . '.json') ?: null;
}

/**
 * Gera (congela) o relatório do dia, só com todas as fontes completas. Uma segunda versão precisa de motivo.
 * $fontes permite os testes; em produção lê-se tudo das bases de dados.
 */
function naval_rv_gerar(string $dia, string $motivo, string $login, ?array $fontes = null): array
{
    if ($dia >= date('Y-m-d') && date('H:i') < naval_config('RELATORIO_HORA_FECHO', '18:00')) {
        throw new NavalErro('O dia ainda não fechou: o relatório gera-se depois das ' . naval_config('RELATORIO_HORA_FECHO', '18:00') . '.', 409);
    }
    $f = $fontes ?? naval_rv_fontes($dia);
    $ctl = naval_rv_controlos($dia, $f);
    if (!$ctl['pronto']) throw new NavalErro('O relatório só se gera com todas as fontes do dia completas.', 409, ['controlos' => $ctl['controlos']]);
    return naval_exclusivo('relatorio-vendas-' . $dia, function () use ($dia, $motivo, $login, $f, $ctl) {
        $versoes = naval_rv_versoes($dia);
        $motivo = naval_texto($motivo, 300);
        if ($versoes && mb_strlen($motivo) < 5) throw new NavalErro('Já existe o relatório deste dia: indique o motivo da nova versão.', 409, ['codigo' => 'versao']);
        $n = $versoes ? max(array_column($versoes, 'versao')) + 1 : 1;
        $dados = naval_rv_montar($dia, $f, naval_promotores());
        $sha = hash('sha256', (string) json_encode($dados, JSON_UNESCAPED_UNICODE));
        $r = ['numero' => 'RV-' . str_replace('-', '', $dia) . '-v' . $n, 'dia' => $dia, 'versao' => $n, 'gerado_em' => date('Y-m-d H:i:s'), 'gerado_por' => $login,
            'motivo' => $n > 1 ? $motivo : '', 'sha256' => $sha, 'controlos' => $ctl['controlos'], 'dados' => $dados];
        naval_json_gravar(naval_rv_pasta($dia) . '/v' . $n . '.json', $r);
        naval_registar('relatorio_vendas_gerado', ['dia' => $dia, 'versao' => $n, 'sha256' => $sha]);
        return $r;
    });
}
