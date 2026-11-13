<?php
/**
 * Previsão de vendas por rota e sugestão de quantidades para o plano de cargas.
 *
 * Método (simples, explicável e verificável, adequado a séries diárias curtas com ciclo semanal):
 *   - base: média ponderada do MESMO dia da semana nas últimas 6 semanas (pesos 6, 5, 4, 3, 2, 1: o mais recente pesa mais);
 *   - tendência: vendas das últimas 4 semanas ÷ 4 semanas anteriores, amortecida (raiz quadrada) e limitada a ±20 %;
 *   - intervalo de 80 %: ± 1,28 × desvio absoluto mediano do mesmo dia da semana;
 *   - qualidade: o mesmo método aplicado aos últimos 14 dias com os dados de antes de cada um (backtest),
 *     medida pelo erro absoluto ponderado (WAPE = Σ|real − previsto| ÷ Σ real). A página mostra-a sempre.
 *
 * Plano de cargas: as quantidades sugeridas para uma rota são a média ponderada das últimas cargas publicadas dessa rota
 * (as unidades são as do plano, não as das vendas) × a tendência de vendas da rota. A Logística confirma sempre.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/calendario.php';
require_once __DIR__ . '/analise.php';

const NAVAL_PREVISAO_PESOS = [6, 5, 4, 3, 2, 1];
const NAVAL_PREVISAO_TENDENCIA_MAX = 0.2;

/** Tendência amortecida de uma série diária até $dia (inclusive): factor entre 0,8 e 1,2 (1 = estável). */
function naval_previsao_tendencia(array $serie, string $dia): float
{
    $t0 = strtotime($dia . ' 12:00');
    $rec = $ant = 0.0;
    $n1 = $n2 = 0;
    for ($k = 0; $k < 56; $k++) {
        $d = date('Y-m-d', $t0 - $k * 86400);
        if (!array_key_exists($d, $serie)) continue;
        if ($k < 28) {
            $rec += $serie[$d];
            $n1++;
        } else {
            $ant += $serie[$d];
            $n2++;
        }
    }
    if ($n1 < 21 || $n2 < 21 || $ant <= 0 || $rec <= 0) return 1.0;
    $f = sqrt(($rec / $n1) / ($ant / $n2));
    return max(1 - NAVAL_PREVISAO_TENDENCIA_MAX, min(1 + NAVAL_PREVISAO_TENDENCIA_MAX, $f));
}

/**
 * Previsão de um dia $alvo a partir da série conhecida até $ultimo (inclusive; $alvo > $ultimo).
 * Devolve {previsto, minimo, maximo, base, tendencia, n} ou null sem histórico suficiente (menos de 3 semanas).
 */
function naval_previsao_dia(array $serie, string $alvo, string $ultimo): ?array
{
    // calendário de trabalho: num dia de folga (domingo, feriado, folga da empresa) não há vendas a prever;
    // e os feriados do passado não entram na média (o zero desse dia não é o habitual)
    if (naval_cal_folga($alvo)) return ['previsto' => 0.0, 'minimo' => 0.0, 'maximo' => 0.0, 'base' => 0.0, 'tendencia' => 1.0, 'n' => 0, 'folga' => true];
    $vals = [];
    $soma = $pesos = 0.0;
    $t = strtotime($alvo . ' 12:00');
    $lim = strtotime($ultimo . ' 12:00');
    $k = 0;
    for ($s = 1; count($vals) < count(NAVAL_PREVISAO_PESOS) && $s <= 12; $s++) {
        $tt = $t - $s * 7 * 86400;
        if ($tt > $lim) continue;
        $d = date('Y-m-d', $tt);
        if (!array_key_exists($d, $serie) || naval_cal_folga($d)) continue;
        $w = NAVAL_PREVISAO_PESOS[$k++];
        $vals[] = (float) $serie[$d];
        $soma += $w * $serie[$d];
        $pesos += $w;
    }
    if (count($vals) < 3) return null;
    $base = $soma / $pesos;
    $tend = naval_previsao_tendencia($serie, $ultimo);
    $prev = $base * $tend;
    $mad = naval_mad($vals);
    return ['previsto' => round($prev, 2), 'minimo' => round(max(0, $prev - 1.28 * $mad), 2), 'maximo' => round($prev + 1.28 * $mad, 2),
        'base' => round($base, 2), 'tendencia' => round($tend, 3), 'n' => count($vals)];
}

/** Erro do método nos últimos $dias dias da série (cada dia previsto só com os dados anteriores): {wape, precisao, dias}. */
function naval_previsao_qualidade(array $serie, string $ultimo, int $dias = 14): array
{
    $erro = $real = 0.0;
    $n = 0;
    $t0 = strtotime($ultimo . ' 12:00');
    for ($k = $dias - 1; $k >= 0; $k--) {
        $d = date('Y-m-d', $t0 - $k * 86400);
        if (!array_key_exists($d, $serie)) continue;
        $p = naval_previsao_dia($serie, $d, date('Y-m-d', strtotime($d . ' 12:00') - 86400));
        if ($p === null) continue;
        $erro += abs($serie[$d] - $p['previsto']);
        $real += abs($serie[$d]);
        $n++;
    }
    if (!$n || $real <= 0) return ['wape' => null, 'precisao' => null, 'dias' => $n];
    $w = $erro / $real;
    return ['wape' => round($w, 3), 'precisao' => round(max(0, 1 - $w), 3), 'dias' => $n];
}

/**
 * Previsão dos próximos $h dias depois de $ultimo, por rota e no total, com a qualidade de cada uma.
 * {ultimo, dias: [AAAA-MM-DD…], rotas: [{rota, promotor, dias: [{dia, previsto, minimo, maximo}], semana, tendencia, qualidade,
 *  historico: {dia: real} (últimos 28 dias)}], total: {…}}.
 */
function naval_previsao_vendas(array $agregados, string $ultimo, int $h = 7, array $promotores = []): array
{
    $dias = [];
    for ($i = 1; $i <= $h; $i++) $dias[] = date('Y-m-d', strtotime($ultimo . ' 12:00') + $i * 86400);
    $uma = function (array $serie) use ($dias, $ultimo) {
        $out = [];
        $sem = 0.0;
        foreach ($dias as $d) {
            $p = naval_previsao_dia($serie, $d, $ultimo);
            if ($p === null) return null;
            $out[] = ['dia' => $d] + $p;
            $sem += $p['previsto'];
        }
        $hist = [];
        for ($k = 27; $k >= 0; $k--) {
            $d = date('Y-m-d', strtotime($ultimo . ' 12:00') - $k * 86400);
            if (array_key_exists($d, $serie)) $hist[$d] = $serie[$d];
        }
        return ['dias' => $out, 'semana' => round($sem, 2), 'tendencia' => naval_previsao_tendencia($serie, $ultimo),
            'qualidade' => naval_previsao_qualidade($serie, $ultimo), 'historico' => $hist];
    };
    $rotas = [];
    $sem = [];
    foreach (naval_analise_rotas($agregados) as $rota) {
        $x = $uma(naval_analise_serie($agregados, $rota));
        if ($x === null) {
            $sem[] = $rota;
            continue;
        }
        $rotas[] = ['rota' => $rota, 'promotor' => $promotores[$rota] ?? ''] + $x;
    }
    return ['ultimo' => $ultimo, 'dias' => $dias, 'rotas' => $rotas, 'total' => $uma(naval_analise_serie($agregados, null)),
        'sem_historico' => $sem, 'metodo' => 'media-ponderada-mesmo-dia-semana'];
}

/**
 * Quantidades sugeridas para o plano de cargas de $data, por rota: média ponderada das últimas cargas publicadas (pesos
 * 6…1, até 6 cargas) × tendência de vendas da rota. $planos: naval_plano_dias() (mais recentes primeiro).
 * {rota: {quantidades: {artigo: n}, cargas: n, tendencia: f, ultima: AAAA-MM-DD}}. Arredonda para cima (não faltar carga).
 */
function naval_previsao_cargas(array $planos, string $data, array $tendencias = []): array
{
    $hist = [];
    foreach ($planos as $p) {
        if (($p['data'] ?? '') >= $data || empty($p['publicado']['rotas'])) continue;
        foreach ($p['publicado']['rotas'] as $r) {
            $rt = (string) ($r['rota'] ?? '');
            if ($rt === '' || empty($r['sai']) || count($hist[$rt] ?? []) >= count(NAVAL_PREVISAO_PESOS)) continue;
            $q = array_filter((array) ($r['quantidades'] ?? []), 'is_numeric');
            if (!$q || array_sum($q) <= 0) continue;
            $hist[$rt][] = ['data' => $p['data'], 'q' => $q];
        }
    }
    $out = [];
    foreach ($hist as $rt => $cargas) {
        if (count($cargas) < 2) continue;   // uma só carga: o pré-preenchimento normal (cópia) já a usa
        $tend = (float) ($tendencias[$rt] ?? 1.0);
        $soma = [];
        $pesos = 0;
        foreach ($cargas as $i => $c) {
            $w = NAVAL_PREVISAO_PESOS[$i];
            $pesos += $w;
            foreach ($c['q'] as $art => $v) $soma[$art] = ($soma[$art] ?? 0) + $w * (float) $v;
        }
        $q = [];
        foreach ($soma as $art => $v) $q[$art] = (int) ceil(round($v / $pesos * $tend, 6));
        $out[$rt] = ['quantidades' => $q, 'cargas' => count($cargas), 'tendencia' => round($tend, 3), 'ultima' => $cargas[0]['data']];
    }
    return $out;
}

/**
 * Indicadores do dia (app de gestão e painel da sala comercial), no âmbito $rotas (null = todas):
 * hoje até agora face à previsão do dia, ontem face ao habitual, semana corrente e mês até hoje face aos mesmos dias do
 * período anterior, e classificação das rotas (hoje e mês, com a variação de cada rota face a si própria).
 * $agregados (testes): agregados já lidos; omissão: os últimos 90 dias até hoje.
 */
function naval_indicadores(int $agora, ?array $rotas, ?array $agregados = null, array $promotores = []): array
{
    $hoje = date('Y-m-d', $agora);
    $ontem = date('Y-m-d', $agora - 86400);
    if ($agregados === null) {
        $agregados = naval_analise_agregados(date('Y-m-d', $agora - 90 * 86400), $hoje);
        $promotores = naval_promotores();
    }
    $ag = naval_analise_ambito($agregados, $rotas, true);
    $t = function (string $d, ?string $rota = null) use ($ag) {
        $s = 0.0;
        foreach (($ag[$d]['rotas'] ?? []) as $rt => $r) if ($rota === null || (string) $rt === $rota) $s += (float) $r['t'];
        return $s;
    };
    $docs = function (string $d) use ($ag) {
        $n = 0;
        foreach (($ag[$d]['rotas'] ?? []) as $r) $n += (int) $r['d'];
        return $n;
    };
    $periodo = function (string $de, string $ate, ?string $rota = null) use ($t) {
        $s = 0.0;
        foreach (naval_analise_dias($de, $ate) as $d) $s += $t($d, $rota);
        return $s;
    };
    $var = function (float $a, float $b) { return $b > 0 ? round($a / $b - 1, 3) : null; };
    // períodos: semana desde segunda-feira e mês desde o dia 1, face aos mesmos dias do período anterior
    $iniSem = date('Y-m-d', $agora - ((int) date('N', $agora) - 1) * 86400);
    $iniSemAnt = date('Y-m-d', strtotime($iniSem . ' 12:00') - 7 * 86400);
    $hojeSemAnt = date('Y-m-d', $agora - 7 * 86400);
    $iniMes = date('Y-m-01', $agora);
    $iniMesAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $fimMesAnt = date('Y-m-d', min(strtotime($iniMesAnt . ' 12:00') + ((int) date('j', $agora) - 1) * 86400, strtotime($iniMes . ' 12:00') - 86400));

    $serieTot = naval_analise_serie(array_filter($ag, function ($d) use ($hoje) { return $d < $hoje; }, ARRAY_FILTER_USE_KEY), null);
    $prevHoje = naval_previsao_dia($serieTot, $hoje, $ontem);
    $rank = [];
    foreach (naval_analise_rotas($ag) as $rt) {
        $s = naval_analise_serie(array_filter($ag, function ($d) use ($hoje) { return $d < $hoje; }, ARRAY_FILTER_USE_KEY), $rt);
        $p = naval_previsao_dia($s, $hoje, $ontem);
        $mes = $periodo($iniMes, $hoje, $rt);
        $mesAnt = $periodo($iniMesAnt, $fimMesAnt, $rt);
        $rank[] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? '', 'hoje' => round($t($hoje, $rt), 2), 'previsto_hoje' => $p ? $p['previsto'] : null,
            'ontem' => round($t($ontem, $rt), 2), 'habitual_ontem' => round(naval_mediana(naval_mesmos_dias($s, $ontem, 6)), 2),
            'mes' => round($mes, 2), 'mes_anterior' => round($mesAnt, 2), 'variacao_mes' => $var($mes, $mesAnt),
            'sequencia' => naval_sequencia_acima($s, $ontem),
            // últimos 14 dias (para o mini-gráfico da rota na app)
            'serie' => array_map(function ($d) use ($t, $rt) { return round($t($d, $rt), 2); }, naval_analise_dias(date('Y-m-d', $agora - 13 * 86400), $hoje))];
    }
    usort($rank, function ($a, $b) { return $b['mes'] <=> $a['mes']; });
    // valor e artigos por compra (compra = cliente com vendas num dia): hoje e mês até hoje
    $compras = function (string $de, string $ate) use ($ag) {
        $n = $v = $skus = $comArt = 0;
        foreach (naval_analise_dias($de, $ate) as $d) {
            foreach (($ag[$d]['clientes'] ?? []) as $c => $cl) {
                if ((float) $cl['t'] <= 0) continue;
                $n++;
                $v += (float) $cl['t'];
                $k = count(array_filter((array) ($ag[$d]['ca'][$c] ?? []), function ($q) { return $q > 0; }));
                if ($k) {
                    $skus += $k;
                    $comArt++;
                }
            }
        }
        return ['compras' => $n, 'valor_medio' => $n ? round($v / $n, 2) : null, 'artigos_por_compra' => $comArt ? round($skus / $comArt, 2) : null];
    };
    // série diária desde o início do mês anterior (gráficos da app: barras do dia, semana e mês acumulado)
    $serie = [];
    $ultimaVenda = null;
    foreach (naval_analise_dias($iniMesAnt, $hoje) as $d) {
        $v = round($t($d), 2);
        $serie[] = ['d' => $d, 't' => $v];
    }
    foreach (array_keys($ag) as $d) if ($d <= $hoje && $t($d) > 0) $ultimaVenda = max((string) $ultimaVenda, $d);
    $hojeT = $t($hoje);
    $ontemT = $t($ontem);
    $habOntem = naval_mediana(naval_mesmos_dias($serieTot, $ontem, 6));
    return [
        'hora' => date('Y-m-d H:i', $agora),
        'hoje' => ['dia' => $hoje, 'total' => round($hojeT, 2), 'documentos' => $docs($hoje), 'previsto' => $prevHoje ? $prevHoje['previsto'] : null,
            'progresso' => $prevHoje && $prevHoje['previsto'] > 0 ? round($hojeT / $prevHoje['previsto'], 3) : null],
        'ontem' => ['dia' => $ontem, 'total' => round($ontemT, 2), 'documentos' => $docs($ontem), 'habitual' => round($habOntem, 2), 'variacao' => $var($ontemT, $habOntem)],
        'semana' => ['de' => $iniSem, 'total' => round($periodo($iniSem, $hoje), 2), 'anterior' => round($periodo($iniSemAnt, $hojeSemAnt), 2),
            'variacao' => $var($periodo($iniSem, $hoje), $periodo($iniSemAnt, $hojeSemAnt))],
        'mes' => ['de' => $iniMes, 'total' => round($periodo($iniMes, $hoje), 2), 'anterior' => round($periodo($iniMesAnt, $fimMesAnt), 2),
            'variacao' => $var($periodo($iniMes, $hoje), $periodo($iniMesAnt, $fimMesAnt))],
        'rotas' => $rank,
        'serie' => $serie,
        'ultima_venda' => $ultimaVenda,
        'compras' => ['hoje' => $compras($hoje, $hoje), 'mes' => $compras($iniMes, $hoje), 'mes_anterior' => $compras($iniMesAnt, $fimMesAnt)],
        'controlo' => (function () use ($ag, $agora, $promotores) {
            naval_servico('controlo');
            return naval_controlo($ag, $agora, $promotores);
        })(),
    ];
}

/** Dias de trabalho seguidos (até $dia) em que a rota vendeu acima do seu habitual (mediana do mesmo dia da semana). */
function naval_sequencia_acima(array $serie, string $dia): int
{
    $n = 0;
    for ($k = 0; $k < 60; $k++) {
        $d = date('Y-m-d', strtotime($dia . ' 12:00') - $k * 86400);
        if (!array_key_exists($d, $serie)) break;
        $hab = naval_mediana(naval_mesmos_dias($serie, $d, 6));
        if ($hab <= 0) continue;   // dia sem trabalho habitual (ex.: domingo): não conta nem interrompe
        if ($serie[$d] > $hab) $n++;
        else break;
    }
    return $n;
}
