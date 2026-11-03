<?php
/**
 * Apresentação do portal: números reais do negócio e da automação, recalculados a cada pedido (com cache curta).
 *
 * Negócio: dos agregados diários do Pinguim BO (naval_analise_agregados), já no âmbito de quem pede.
 * Automação: do que o portal fez sozinho (ficheiros da rotina diária: resumos, cálculos da noite, planos propostos,
 * fechos do dia e versões instaladas) e da carteira calculada esta noite.
 * Só agregados: sem clientes, documentos, NIF nem nomes.
 */
const NAVAL_APX_MESES = 6;          // meses mostrados no gráfico (o actual, até hoje, e os 5 anteriores)
const NAVAL_APX_DIAS_AUTOMACAO = 30; // janela da contagem do que o portal fez sozinho

/** Primeiro dia do mês de $dia deslocado $n meses (n negativo = para trás). */
function naval_apx_mes(string $dia, int $n): string
{
    return date('Y-m-01', strtotime(substr($dia, 0, 7) . '-01 12:00 ' . ($n >= 0 ? '+' : '') . $n . ' month'));
}

/**
 * Indicadores de negócio a partir dos agregados diários (já no âmbito). $hoje AAAA-MM-DD.
 * {meses:[{mes, total, documentos, clientes, parcial}], mes:{total, anterior, variacao, documentos, clientes, rotas,
 *  valor_medio}, clientes_30, dias_com_dados, desde, ate}
 */
function naval_apx_negocio(array $ag, string $hoje): array
{
    $meses = [];
    for ($k = NAVAL_APX_MESES - 1; $k >= 0; $k--) {
        $m = substr(naval_apx_mes($hoje, -$k), 0, 7);
        $meses[$m] = ['mes' => $m, 'total' => 0.0, 'documentos' => 0, 'clientes' => [], 'parcial' => $k === 0];
    }
    $iniMes = substr($hoje, 0, 8) . '01';
    $iniAnt = naval_apx_mes($hoje, -1);
    $fimAnt = date('Y-m-d', min(strtotime($iniAnt . ' 12:00') + ((int) substr($hoje, 8, 2) - 1) * 86400, strtotime($iniMes . ' 12:00') - 86400));
    $limite30 = date('Y-m-d', strtotime($hoje . ' 12:00') - 29 * 86400);
    $mes = ['total' => 0.0, 'anterior' => 0.0, 'documentos' => 0, 'clientes' => [], 'rotas' => []];
    $c30 = [];
    $dias = 0;
    $desde = null;
    $compras = 0;
    $valorCompras = 0.0;
    foreach ($ag as $dia => $x) {
        if ($dia > $hoje) continue;
        $tot = 0.0;
        $docs = 0;
        foreach ((array) ($x['rotas'] ?? []) as $rt => $r) {
            $tot += (float) ($r['t'] ?? 0);
            $docs += (int) ($r['d'] ?? 0);
            if ($dia >= $iniMes && (int) ($r['d'] ?? 0) > 0) $mes['rotas'][(string) $rt] = true;
        }
        if ($docs > 0) {
            $dias++;
            if ($desde === null || $dia < $desde) $desde = $dia;
        }
        $m = substr($dia, 0, 7);
        if (isset($meses[$m])) {
            $meses[$m]['total'] += $tot;
            $meses[$m]['documentos'] += $docs;
        }
        foreach ((array) ($x['clientes'] ?? []) as $c => $cl) {
            if (isset($meses[$m])) $meses[$m]['clientes'][$c] = true;
            if ($dia >= $limite30) $c30[$c] = true;
            if ($dia >= $iniMes) {
                $mes['clientes'][$c] = true;
                if ((float) ($cl['t'] ?? 0) > 0) {
                    $compras++;
                    $valorCompras += (float) $cl['t'];
                }
            }
        }
        if ($dia >= $iniMes) {
            $mes['total'] += $tot;
            $mes['documentos'] += $docs;
        } elseif ($dia >= $iniAnt && $dia <= $fimAnt) $mes['anterior'] += $tot;
    }
    return [
        'meses' => array_values(array_map(function ($x) {
            return ['mes' => $x['mes'], 'total' => round($x['total'], 2), 'documentos' => $x['documentos'], 'clientes' => count($x['clientes']), 'parcial' => $x['parcial']];
        }, $meses)),
        'mes' => ['de' => $iniMes, 'total' => round($mes['total'], 2), 'anterior' => round($mes['anterior'], 2),
            'variacao' => $mes['anterior'] > 0 ? round($mes['total'] / $mes['anterior'] - 1, 3) : null,
            'documentos' => $mes['documentos'], 'clientes' => count($mes['clientes']), 'rotas' => count($mes['rotas']),
            'valor_medio' => $compras ? round($valorCompras / $compras, 2) : null],
        'clientes_30' => count($c30),
        'dias_com_dados' => $dias,
        'desde' => $desde,
        'ate' => $hoje,
    ];
}

/** Ficheiros AAAA-MM-DD da pasta (com prefixo opcional) dos últimos $dias dias até $hoje; $ok filtra o conteúdo. */
function naval_apx_contar(string $pasta, string $prefixo, string $hoje, int $dias, ?callable $ok = null): int
{
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - ($dias - 1) * 86400);
    $n = 0;
    foreach (glob($pasta . '/' . $prefixo . '????-??-??.json') ?: [] as $f) {
        $d = substr(basename($f, '.json'), -10);
        if ($d < $de || $d > $hoje) continue;
        if ($ok && !$ok(naval_json_ler($f))) continue;
        $n++;
    }
    return $n;
}

/**
 * O que o portal fez sozinho nos últimos 30 dias e o estado da carteira calculada esta noite (no âmbito de $rotas).
 * Sem $comCarteira (quem pede não vê vendas) a parte da carteira fica a null.
 */
function naval_apx_automacao(string $hoje, ?array $rotas, bool $comCarteira): array
{
    $n = NAVAL_APX_DIAS_AUTOMACAO;
    $versoes = 0;
    naval_servico('actualizacao');
    $f = naval_act_pasta() . '/registo.jsonl';
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - ($n - 1) * 86400);
    if (is_file($f)) {
        foreach (array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -2000) as $l) {
            $j = json_decode($l, true);
            if (is_array($j) && ($j['accao'] ?? '') === 'actualizada' && substr((string) ($j['data'] ?? ''), 0, 10) >= $de) $versoes++;
        }
    }
    $out = [
        'dias' => $n,
        'resumos' => naval_apx_contar(naval_pasta('resumo'), '', $hoje, $n, function ($x) { return empty($x['erro']); }),
        'calculos' => naval_apx_contar(naval_pasta('carteira'), '', $hoje, $n),
        'planos' => naval_apx_contar(naval_pasta('operacao'), 'plano-', $hoje, $n),
        'fechos' => naval_apx_contar(naval_pasta('operacao'), 'fecho-', $hoje, $n),
        'versoes' => $versoes,
        'carteira' => null,
    ];
    if (!$comCarteira) return $out;
    naval_servico('carteira');
    $c = naval_cart_ler();
    if (!$c) return $out;
    $dentro = function (string $rota) use ($rotas) { return $rotas === null || in_array($rota, $rotas, true); };
    $estados = ['em_dia' => 0, 'a_abrandar' => 0, 'parado' => 0];
    $encomendas = 0;
    $valorParados = 0.0;
    foreach ((array) ($c['clientes'] ?? []) as $x) {
        if (!$dentro((string) ($x['r'] ?? ''))) continue;
        $e = (string) ($x['estado'] ?? '');
        if (isset($estados[$e])) $estados[$e]++;
        if (!empty($x['encomenda'])) $encomendas++;
        if ($e === 'parado') $valorParados += (float) ($x['media'] ?? 0);
    }
    $out['carteira'] = $estados + [
        'calculado' => (string) ($c['gerado'] ?? ''),
        'encomendas' => $encomendas,
        'valor_parados' => round($valorParados, 2),
        'anomalias' => count(array_filter((array) ($c['anomalias'] ?? []), function ($x) use ($dentro) { return $dentro((string) ($x['rota'] ?? '')); })),
        'qualidade' => count(array_filter((array) ($c['qualidade'] ?? []), function ($x) use ($dentro, $rotas) { return $rotas === null || ((string) ($x['rota'] ?? '') !== '' && $dentro((string) $x['rota'])); })),
    ];
    return $out;
}
