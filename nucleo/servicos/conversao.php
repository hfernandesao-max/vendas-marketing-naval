<?php
/**
 * Conversão das visitas (indicadores de execução no ponto de venda usados na distribuição directa):
 *   - Plano do dia congelado: a lista de visitas de cada rota (carteira: dia habitual de compra ou compra em atraso)
 *     fica guardada no início do dia (dados-privados/visitas-plano/AAAA-MM-DD.json), para medir contra o que estava
 *     planeado e não contra uma lista que muda durante o dia;
 *   - Conversão do plano («strike rate»): clientes planeados que compraram ÷ clientes planeados;
 *   - Vendas fora do plano: clientes que compraram sem estarem no plano (oportunidade ou plano desactualizado);
 *   - Peso do plano nas vendas: valor vendido a clientes planeados ÷ valor total;
 *   - Com o servidor OLAP ligado (localização das visitas): cobertura do plano (planeados visitados ÷ planeados) e
 *     conversão das visitas (visitados que compraram ÷ visitados); sem OLAP estes ficam «por ligar».
 *   - Planeados sem compra: a lista para o supervisor seguir no dia seguinte, os de maior compra média primeiro.
 * Funções puras para o cálculo (testáveis); a leitura das fontes fica em naval_conversao().
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/carteira.php';
require_once __DIR__ . '/analise.php';

function naval_conv_ficheiro(string $dia): string
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    return naval_pasta('visitas-plano') . '/' . $dia . '.json';
}

/**
 * Plano de visitas do dia, congelado na primeira leitura do próprio dia (ou antes): {rota: [{c, n, motivo, media}]}.
 * Dias passados sem plano guardado devolvem null (não se reconstrói o passado com dados de hoje).
 */
function naval_conv_plano(string $dia, ?array $carteira = null): ?array
{
    $f = naval_conv_ficheiro($dia);
    $g = naval_json_ler($f);
    if ($g) return (array) $g['rotas'];
    if ($dia < date('Y-m-d')) return null;
    $carteira = $carteira ?? naval_cart_ler();
    if (!$carteira) return null;
    $rotas = [];
    foreach (naval_cart_visitas($carteira, $dia, null) as $r => $l) {
        $rotas[(string) $r] = array_map(function ($c) {
            return ['c' => (string) $c['c'], 'n' => (string) $c['n'], 'motivo' => (string) ($c['motivo'] ?? ''), 'media' => (float) ($c['media'] ?? 0)];
        }, $l);
    }
    naval_json_gravar($f, ['dia' => $dia, 'congelado_em' => date('Y-m-d H:i:s'), 'base' => $carteira['dia'] ?? null, 'rotas' => $rotas], false);
    return $rotas;
}

/**
 * Indicadores do período. $planos: {dia: {rota: [{c, n, media}]}|null}; $agregados: {dia: {clientes: {c: {r, t}}}};
 * $visitas (OLAP): {dia: {rota: [clientes visitados]}}|null. $rotas: âmbito (null = todas).
 */
function naval_conv_calcular(array $planos, array $agregados, ?array $visitas, ?array $rotas, array $promotores = []): array
{
    $porRota = [];
    $seguir = [];
    $novo = function ($r) use ($promotores) {
        return ['rota' => (string) $r, 'promotor' => $promotores[(string) $r] ?? '', 'dias' => 0, 'planeados' => 0, 'planeados_compra' => 0, 'valor_planeados' => 0.0,
            'fora_plano' => 0, 'valor_fora' => 0.0, 'visitados' => null, 'visitados_compra' => null, 'planeados_visitados' => null];
    };
    foreach ($planos as $dia => $plano) {
        if ($plano === null) continue;
        $compras = [];
        foreach ((array) ($agregados[$dia]['clientes'] ?? []) as $c => $x) if ((float) $x['t'] > 0) $compras[(string) $x['r']][(string) $c] = (float) $x['t'];
        $rotasDia = array_unique(array_merge(array_keys($plano), array_keys($compras)));
        foreach ($rotasDia as $r) {
            $r = (string) $r;
            if ($rotas !== null && !in_array($r, $rotas, true)) continue;
            $p = $porRota[$r] ?? $novo($r);
            $p['dias']++;
            $plan = [];
            foreach ((array) ($plano[$r] ?? []) as $c) $plan[(string) $c['c']] = $c;
            $comp = $compras[$r] ?? [];
            $p['planeados'] += count($plan);
            foreach ($plan as $c => $x) {
                if (isset($comp[$c])) {
                    $p['planeados_compra']++;
                    $p['valor_planeados'] += $comp[$c];
                } else {
                    $seguir[] = ['dia' => $dia, 'rota' => $r, 'c' => $c, 'n' => $x['n'], 'media' => (float) ($x['media'] ?? 0), 'motivo' => $x['motivo'] ?? ''];
                }
            }
            foreach ($comp as $c => $v) {
                if (isset($plan[$c])) continue;
                $p['fora_plano']++;
                $p['valor_fora'] += $v;
            }
            $vis = $visitas[$dia][$r] ?? null;
            if ($vis !== null) {
                $vis = array_flip(array_map('strval', $vis));
                $p['visitados'] = ($p['visitados'] ?? 0) + count($vis);
                $p['visitados_compra'] = ($p['visitados_compra'] ?? 0) + count(array_intersect_key($vis, $comp));
                $p['planeados_visitados'] = ($p['planeados_visitados'] ?? 0) + count(array_intersect_key($vis, $plan));
            }
            $porRota[$r] = $p;
        }
    }
    $taxas = function (array $p) {
        $tot = $p['valor_planeados'] + $p['valor_fora'];
        return $p + [
            'conversao_plano' => $p['planeados'] ? round($p['planeados_compra'] / $p['planeados'], 3) : null,
            'peso_plano' => $tot > 0 ? round($p['valor_planeados'] / $tot, 3) : null,
            'cobertura' => $p['planeados_visitados'] !== null && $p['planeados'] ? round($p['planeados_visitados'] / $p['planeados'], 3) : null,
            'conversao_visitas' => $p['visitados'] ? round($p['visitados_compra'] / $p['visitados'], 3) : null,
        ];
    };
    $lista = array_map($taxas, array_values($porRota));
    usort($lista, function ($a, $b) { return [$a['conversao_plano'] ?? 2] <=> [$b['conversao_plano'] ?? 2] ?: strcmp($a['rota'], $b['rota']); });
    $tot = $novo('');
    foreach ($lista as $p) {
        foreach (['planeados', 'planeados_compra', 'valor_planeados', 'fora_plano', 'valor_fora'] as $k) $tot[$k] += $p[$k];
        foreach (['visitados', 'visitados_compra', 'planeados_visitados'] as $k) if ($p[$k] !== null) $tot[$k] = ($tot[$k] ?? 0) + $p[$k];
    }
    $tot['dias'] = count(array_filter($planos, function ($x) { return $x !== null; }));
    foreach (['valor_planeados', 'valor_fora'] as $k) $tot[$k] = round($tot[$k], 2);
    foreach ($lista as &$p) foreach (['valor_planeados', 'valor_fora'] as $k) $p[$k] = round($p[$k], 2);
    unset($p);
    usort($seguir, function ($a, $b) { return [$b['dia'], $b['media']] <=> [$a['dia'], $a['media']]; });
    return ['rotas' => $lista, 'total' => $taxas($tot), 'seguir' => array_slice($seguir, 0, 300),
        'dias_sem_plano' => array_keys(array_filter($planos, function ($x) { return $x === null; })), 'olap' => $visitas !== null];
}

/** Clientes visitados por dia e rota, a partir dos pontos do OLAP com cliente (null se o OLAP não estiver ligado). */
function naval_conv_visitas(array $dias, ?array $rotas): ?array
{
    naval_servico('olap');
    if (!naval_olap_configurado()) return null;
    $out = [];
    foreach ($dias as $d) {
        $o = naval_olap_dia($d, $rotas);
        foreach ((array) $o['pontos'] as $p) if ((string) $p['cli'] !== '') $out[$d][(string) $p['r']][(string) $p['cli']] = (string) $p['cli'];
        foreach ($out[$d] ?? [] as $r => $l) $out[$d][$r] = array_values($l);
    }
    return $out;
}

/** Indicadores do período (AAAA-MM-DD), no âmbito $rotas, lidos das fontes. Período até 62 dias. */
function naval_conversao(string $de, string $ate, ?array $rotas): array
{
    $ate = min($ate, date('Y-m-d'));
    if ($de > $ate) throw new NavalErro('Período inválido.', 400);
    $dias = naval_analise_dias($de, $ate);
    if (count($dias) > 62) throw new NavalErro('Escolha um período até 62 dias.', 400);
    $planos = [];
    foreach ($dias as $d) $planos[$d] = naval_conv_plano($d);
    $ag = naval_analise_agregados($de, $ate);
    return ['de' => $de, 'ate' => $ate] + naval_conv_calcular($planos, $ag, naval_conv_visitas($dias, $rotas), $rotas, naval_promotores());
}
