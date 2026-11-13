<?php
/**
 * Objectivos de vendas automáticos, por rota e por mês, calculados a partir das vendas diárias do Pinguim BO.
 *
 * Prática das empresas de bens de consumo (planeamento comercial com base estatística e «ratchet» de crescimento):
 *   1. base: a previsão de cada dia do mês (média ponderada do mesmo dia da semana nas últimas 6 semanas × tendência,
 *      ver previsao.php), com os dados até ao último dia do mês anterior — nada do próprio mês entra no cálculo;
 *   2. objectivo de crescimento: base × (1 + OBJECTIVOS_CRESCIMENTO_ALVO, por omissão 5%);
 *   3. nunca descer (regra pedida pela gestão): o ritmo por dia útil do objectivo nunca fica abaixo do maior entre
 *      o ritmo realizado e o ritmo do objectivo do mês anterior, mais OBJECTIVOS_CRESCIMENTO_MIN (por omissão 3%).
 *      O ritmo por dia útil torna justa a comparação entre meses com números de dias úteis diferentes;
 *   4. arredondado para cima ao milhar de Kz e repartido pelos dias úteis segundo o padrão de cada dia da semana;
 *   Dias úteis e ritmo seguem o calendário de trabalho (calendario.php): segunda a sexta dia completo, sábado meio dia
 *   (≈ 0,73 de um dia completo), sábado à tarde, domingo e feriados de folga. O ritmo compara-se por «dia completo
 *   equivalente», para que um mês com mais sábados ou feriados não pareça mais fraco;
 *   5. congelado no início do mês (dados-privados/objectivos/AAAA-MM.json): não muda durante o mês, para ser um
 *      compromisso e não um alvo móvel. O mês seguinte calcula-se sozinho.
 * Acompanhamento: realizado no mês, objectivo até hoje, cumprimento, desvio, ritmo diário necessário para chegar ao
 * objectivo e projecção do fecho do mês (ritmo actual sobre os dias úteis que faltam).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/previsao.php';
require_once __DIR__ . '/calendario.php';

function naval_obj_parametros(): array
{
    $n = function (string $k, float $omissao, float $max) {
        $v = (float) str_replace(',', '.', naval_config($k, (string) $omissao));
        return max(0.0, min($max, $v));
    };
    return ['crescimento_min' => $n('OBJECTIVOS_CRESCIMENTO_MIN', 0.03, 0.5), 'crescimento_alvo' => $n('OBJECTIVOS_CRESCIMENTO_ALVO', 0.05, 0.5)];
}

/** Dias do mês AAAA-MM. */
function naval_obj_dias(string $mes): array
{
    return naval_analise_dias($mes . '-01', date('Y-m-t', strtotime($mes . '-01 12:00')));
}

/**
 * Objectivo de uma série (uma rota ou o total) para o mês. $anterior: objectivo guardado do mês anterior (ou null).
 * Devolve null sem histórico nenhum.
 */
function naval_obj_calcular(array $serie, string $mes, ?array $anterior, array $par): ?array
{
    $ini = $mes . '-01';
    $ultimo = date('Y-m-d', strtotime($ini . ' 12:00') - 86400);
    $serie = array_filter($serie, function ($d) use ($ultimo) { return $d <= $ultimo; }, ARRAY_FILTER_USE_KEY);
    if (!$serie || array_sum($serie) <= 0) return null;
    // dias de trabalho do mês e o seu peso (calendário de trabalho: sábado meio dia; domingos e feriados de folga)
    $uteis = array_values(array_filter(naval_obj_dias($mes), function ($d) { return !naval_cal_folga($d); }));
    $equiv = array_sum(array_map('naval_cal_peso', $uteis));
    if (!$uteis || $equiv <= 0) return null;
    // 1. base: previsão de cada dia útil do mês
    $baseDia = [];
    foreach ($uteis as $d) {
        $p = naval_previsao_dia($serie, $d, $ultimo);
        $baseDia[$d] = $p ? (float) $p['previsto'] : null;
    }
    $comBase = array_filter($baseDia, function ($v) { return $v !== null; });
    $base = count($comBase) === count($uteis) ? array_sum($comBase) : null;
    // 3. ritmo do mês anterior (realizado nos dias úteis com dados) e do objectivo anterior
    $mesAnt = substr($ultimo, 0, 7);
    $realAnt = 0.0;
    $diasAnt = 0;
    $equivAnt = 0.0;
    foreach (naval_obj_dias($mesAnt) as $d) {
        if (!array_key_exists($d, $serie) || naval_cal_folga($d)) continue;
        $realAnt += (float) $serie[$d];
        $diasAnt++;
        $equivAnt += naval_cal_peso($d);
    }
    // ritmo por dia completo equivalente (objectivos antigos, sem equivalentes: por dia útil)
    $ritmoReal = $equivAnt > 0 ? $realAnt / $equivAnt : 0.0;
    $eqObjAnt = $anterior ? (float) ($anterior['dias_equivalentes'] ?? $anterior['dias_uteis'] ?? 0) : 0.0;
    $ritmoObj = $eqObjAnt > 0 ? (float) $anterior['objectivo'] / $eqObjAnt : 0.0;
    $minimo = max($ritmoReal, $ritmoObj) * (1 + $par['crescimento_min']) * $equiv;
    $alvo = $base !== null ? $base * (1 + $par['crescimento_alvo']) : 0.0;
    $obj = max($alvo, $minimo);
    if ($obj <= 0) return null;
    $obj = ceil($obj / 1000) * 1000;
    // 4. repartição pelos dias úteis, com o peso de cada dia na previsão (sem previsão: por igual)
    $pesos = [];
    foreach ($uteis as $d) $pesos[$d] = $base !== null && $base > 0 ? max(0.0, (float) $baseDia[$d]) : naval_cal_peso($d);
    $sp = array_sum($pesos) ?: 1.0;
    $dias = [];
    foreach ($uteis as $d) $dias[$d] = round($obj * $pesos[$d] / $sp, 2);
    return [
        'objectivo' => $obj, 'dias_uteis' => count($uteis), 'dias_equivalentes' => round($equiv, 3), 'dias' => $dias,
        'base' => $base !== null ? round($base, 2) : null, 'mes_anterior' => round($realAnt, 2), 'dias_anterior' => $diasAnt,
        'objectivo_anterior' => $anterior['objectivo'] ?? null,
        'criterio' => $alvo >= $minimo ? 'tendencia' : 'minimo',
        'crescimento' => $ritmoReal > 0 ? round(($obj / $equiv) / $ritmoReal - 1, 3) : null,
    ];
}

function naval_obj_ficheiro(string $mes): string
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) throw new NavalErro('Mês inválido.', 400);
    return naval_pasta('objectivos') . '/' . $mes . '.json';
}

/**
 * Objectivos do mês de todas as rotas: lidos do ficheiro congelado ou, na primeira vez, calculados e guardados.
 * $agregados: agregados diários de todas as rotas (pelo menos 90 dias antes do mês).
 */
function naval_objectivos_mes(string $mes, array $agregados, array $promotores = [], ?array $par = null): array
{
    $f = naval_obj_ficheiro($mes);
    $g = naval_json_ler($f);
    if ($g) return $g;
    return naval_exclusivo('objectivos-' . $mes, function () use ($f, $mes, $agregados, $promotores, $par) {
        $g = naval_json_ler($f);
        if ($g) return $g;
        $par = $par ?? naval_obj_parametros();
        $ant = naval_json_ler(naval_obj_ficheiro(date('Y-m', strtotime($mes . '-01 12:00 -1 month'))));
        $rotas = [];
        foreach (naval_analise_rotas($agregados) as $rt) {
            $o = naval_obj_calcular(naval_analise_serie($agregados, $rt), $mes, $ant['rotas'][$rt] ?? null, $par);
            if ($o) $rotas[$rt] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? ''] + $o;
        }
        $g = ['mes' => $mes, 'calculado_em' => date('Y-m-d H:i:s'), 'parametros' => $par, 'metodo' => 'previsao-mesmo-dia-semana+crescimento+minimo-crescente',
            'rotas' => $rotas];
        if ($rotas) {
            naval_json_gravar($f, $g);
            naval_registar('objectivos_calculados', ['mes' => $mes, 'rotas' => count($rotas), 'total' => array_sum(array_column($rotas, 'objectivo'))]);
        }
        return $g;
    });
}

/**
 * Acompanhamento dos objectivos até $hoje (inclusive), no âmbito $rotas (null = todas).
 * {mes, rotas: [...], total: {...}}, cada um com objectivo, realizado, objectivo_ate_hoje, cumprimento, ritmo,
 * desvio, falta, necessario_dia, projeccao, estado (acima | no_caminho | abaixo).
 */
function naval_objectivos_estado(array $obj, array $agregados, string $hoje, ?array $rotas): array
{
    $acomp = function (array $o, array $serie) use ($hoje) {
        $real = 0.0;
        foreach ($serie as $d => $v) if (substr($d, 0, 7) === substr($hoje, 0, 7) && $d <= $hoje) $real += (float) $v;
        $ateHoje = 0.0;
        $restantes = 0;
        $eqFeito = $eqFalta = 0.0;
        foreach ($o['dias'] as $d => $v) {
            // pesos do calendário de trabalho (sábado meio dia); um feriado decidido depois do cálculo não conta
            $w = naval_cal_peso($d);
            if ($d <= $hoje) {
                $ateHoje += $v;
                $eqFeito += $w;
            } elseif ($w > 0) {
                $restantes++;
                $eqFalta += $w;
            }
        }
        $falta = max(0.0, $o['objectivo'] - $real);
        $ritmo = $ateHoje > 0 ? $real / $ateHoje : null;
        // projecção: ritmo por dia completo já feito, aplicado aos dias completos equivalentes que faltam
        $porDia = $eqFeito > 0 ? $real / $eqFeito : 0.0;
        $proj = $real + $porDia * $eqFalta;
        return [
            'objectivo' => $o['objectivo'], 'dias_uteis' => (int) $o['dias_uteis'], 'realizado' => round($real, 2), 'objectivo_ate_hoje' => round($ateHoje, 2),
            'cumprimento' => $o['objectivo'] > 0 ? round($real / $o['objectivo'], 3) : null, 'ritmo' => $ritmo !== null ? round($ritmo, 3) : null,
            'desvio' => round($real - $ateHoje, 2), 'falta' => round($falta, 2), 'dias_restantes' => $restantes,
            'necessario_dia' => $eqFalta > 0 ? round($falta / $eqFalta, 2) : null, 'projeccao' => round($proj, 2),
            'estado' => $ritmo === null ? 'no_caminho' : ($ritmo >= 1 ? 'acima' : ($ritmo >= 0.95 ? 'no_caminho' : 'abaixo')),
            'criterio' => $o['criterio'] ?? null, 'crescimento' => $o['crescimento'] ?? null, 'mes_anterior' => $o['mes_anterior'] ?? null,
        ];
    };
    $out = [];
    $tot = ['objectivo' => 0.0, 'dias' => [], 'dias_uteis' => 0];
    foreach ($obj['rotas'] ?? [] as $rt => $o) {
        if ($rotas !== null && !in_array((string) $rt, $rotas, true)) continue;
        $out[] = ['rota' => (string) $rt, 'promotor' => $o['promotor'] ?? ''] + $acomp($o, naval_analise_serie($agregados, (string) $rt));
        $tot['objectivo'] += $o['objectivo'];
        foreach ($o['dias'] as $d => $v) $tot['dias'][$d] = ($tot['dias'][$d] ?? 0) + $v;
        $tot['dias_uteis'] = max($tot['dias_uteis'], (int) $o['dias_uteis']);
    }
    usort($out, function ($a, $b) { return [$a['estado'] !== 'abaixo', $a['ritmo']] <=> [$b['estado'] !== 'abaixo', $b['ritmo']]; });
    $serieTot = [];
    foreach ($out as $r) foreach (naval_analise_serie($agregados, $r['rota']) as $d => $v) $serieTot[$d] = ($serieTot[$d] ?? 0) + $v;
    $tot['dias_uteis'] = count($tot['dias']);
    $total = $out ? $acomp($tot, $serieTot) : null;
    return ['mes' => $obj['mes'] ?? substr($hoje, 0, 7), 'calculado_em' => $obj['calculado_em'] ?? null, 'parametros' => $obj['parametros'] ?? null,
        'rotas' => $out, 'total' => $total];
}

/** Objectivos do mês de $hoje com o acompanhamento, prontos para a API (lê os agregados de 120 dias). */
function naval_objectivos(string $hoje, ?array $rotas): array
{
    $mes = substr($hoje, 0, 7);
    $ag = naval_analise_agregados(date('Y-m-d', strtotime($mes . '-01 12:00') - 120 * 86400), $hoje);
    $obj = naval_objectivos_mes($mes, array_filter($ag, function ($d) use ($mes) { return $d < $mes . '-01'; }, ARRAY_FILTER_USE_KEY), naval_promotores());
    return naval_objectivos_estado($obj, $ag, $hoje, $rotas);
}
