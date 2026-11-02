<?php
/**
 * Painel de gestão RTM (Route-to-Market / distribuição porta-a-porta): os indicadores e gráficos de referência
 * internacional no sector, calculados a partir dos agregados diários (nucleo/servicos/analise.php):
 *   - tendência diária com média móvel de 7 dias e previsão;          - curva ABC (Pareto) de clientes e de artigos;
 *   - mês até hoje por rota face ao mesmo período do mês anterior;      - distribuição numérica por artigo;
 *   - ponte (cascata) da variação do mês por rota;                      - valor médio e artigos por compra, por rota;
 *   - mapa de calor rota × dia da semana;                               - clientes activos, novos e perdidos por mês.
 * «Compra» = um cliente com vendas num dia (visita produtiva); «artigos por compra» = artigos diferentes nessa compra.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/previsao.php';

const NAVAL_PAINEL_DIAS = 210;   // 7 meses: 6 meses de carteira com 1 de referência

/** Curva ABC: valores por ordem decrescente → pontos (% de itens, % acumulada do valor), itens até 80 % e classes. */
function naval_painel_abc(array $valores, int $pontos = 60): array
{
    $v = array_values(array_filter($valores, function ($x) { return $x > 0; }));
    rsort($v);
    $n = count($v);
    $total = array_sum($v);
    if (!$n || $total <= 0) return ['n' => 0, 'ate80' => 0, 'classes' => ['A' => 0, 'B' => 0, 'C' => 0], 'curva' => []];
    $acum = 0.0;
    $curva = [[0, 0]];
    $ate80 = 0;
    $cl = ['A' => 0, 'B' => 0, 'C' => 0];
    $passo = max(1, (int) floor($n / $pontos));
    foreach ($v as $i => $x) {
        $antes = $acum / $total;
        $acum += $x;
        if ($antes < 0.8) {
            $cl['A']++;
            $ate80 = $i + 1;
        } elseif ($antes < 0.95) $cl['B']++;
        else $cl['C']++;
        if (($i + 1) % $passo === 0 || $i === $n - 1) $curva[] = [round(($i + 1) / $n, 4), round($acum / $total, 4)];
    }
    return ['n' => $n, 'ate80' => $ate80, 'classes' => $cl, 'curva' => $curva];
}

/** Indicadores e séries do painel para o dia $hoje, com os agregados já no âmbito de quem pede (até $hoje inclusive). */
function naval_painel(array $ag, string $hoje, array $promotores = [], array $nomesArtigos = []): array
{
    $t0 = strtotime($hoje . ' 12:00');
    $dia = function (int $k) use ($t0) { return date('Y-m-d', $t0 - $k * 86400); };
    $ontem = $dia(1);
    $tot = function (string $d, ?string $rota = null) use ($ag) {
        $s = 0.0;
        foreach (($ag[$d]['rotas'] ?? []) as $rt => $r) if ($rota === null || (string) $rt === $rota) $s += (float) $r['t'];
        return $s;
    };
    $soma = function (string $de, string $ate, ?string $rota = null) use ($tot) {
        $s = 0.0;
        foreach (naval_analise_dias($de, $ate) as $d) $s += $tot($d, $rota);
        return $s;
    };
    $rotas = naval_analise_rotas($ag);

    // 1. Tendência: 90 dias, média móvel de 7 dias e previsão de 7 dias
    $serie = [];
    for ($k = 89; $k >= 0; $k--) $serie[$dia($k)] = round($tot($dia($k)), 2);
    $mm = [];
    $chaves = array_keys($serie);
    foreach ($chaves as $i => $d) $mm[$d] = $i >= 6 ? round(array_sum(array_slice($serie, $i - 6, 7)) / 7, 2) : null;
    $prev = naval_previsao_vendas(array_filter($ag, function ($d) use ($hoje) { return $d < $hoje; }, ARRAY_FILTER_USE_KEY), $ontem, 7, $promotores);
    $tendencia = ['dias' => array_map(function ($d) use ($serie, $mm) { return ['dia' => $d, 'vendas' => $serie[$d], 'mm7' => $mm[$d]]; }, $chaves),
        'previsao' => is_array($prev['total']) ? array_map(function ($x) { return ['dia' => $x['dia'], 'previsto' => $x['previsto'], 'minimo' => $x['minimo'], 'maximo' => $x['maximo']]; },
            $prev['total']['dias']) : [], 'hoje_incompleto' => true];

    // 2 e 3. Mês até hoje por rota face aos mesmos dias do mês anterior (balas e ponte)
    $iniMes = date('Y-m-01', $t0);
    $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $fimAnt = date('Y-m-d', min(strtotime($iniAnt . ' 12:00') + ((int) date('j', $t0) - 1) * 86400, strtotime($iniMes . ' 12:00') - 86400));
    $mes = [];
    foreach ($rotas as $rt) {
        $a = $soma($iniMes, $hoje, $rt);
        $b = $soma($iniAnt, $fimAnt, $rt);
        if ($a == 0 && $b == 0) continue;
        $mes[] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? '', 'actual' => round($a, 2), 'anterior' => round($b, 2), 'variacao' => $b > 0 ? round($a / $b - 1, 3) : null,
            'contributo' => round($a - $b, 2)];
    }
    usort($mes, function ($x, $y) { return $y['actual'] <=> $x['actual']; });
    $mesTot = ['actual' => round(array_sum(array_column($mes, 'actual')), 2), 'anterior' => round(array_sum(array_column($mes, 'anterior')), 2), 'de' => $iniMes,
        'anterior_de' => $iniAnt, 'anterior_ate' => $fimAnt];

    // 4 a 7. Últimos 90 dias (ABC) e últimos 30 dias (distribuição, valor e artigos por compra)
    $cli90 = $art90 = [];
    $activos30 = [];
    $porArtigo30 = [];
    $porRota30 = [];
    for ($k = 89; $k >= 0; $k--) {
        $d = $dia($k);
        $x = $ag[$d] ?? [];
        foreach (($x['clientes'] ?? []) as $c => $cl) $cli90[$c] = ($cli90[$c] ?? 0) + (float) $cl['t'];
        foreach (($x['artigos'] ?? []) as $arts) foreach ($arts as $cod => $a) $art90[$cod] = ($art90[$cod] ?? 0) + (float) $a['t'];
        if ($k >= 30) continue;
        foreach (($x['clientes'] ?? []) as $c => $cl) {
            if ((float) $cl['t'] <= 0) continue;
            $activos30[$c] = true;
            $rt = (string) $cl['r'];
            if (!isset($porRota30[$rt])) $porRota30[$rt] = ['compras' => 0, 'valor' => 0.0, 'skus' => 0, 'com_artigos' => 0];
            $porRota30[$rt]['compras']++;
            $porRota30[$rt]['valor'] += (float) $cl['t'];
            $n = count(array_filter((array) ($x['ca'][$c] ?? []), function ($q) { return $q > 0; }));
            if ($n) {
                $porRota30[$rt]['skus'] += $n;
                $porRota30[$rt]['com_artigos']++;
            }
            foreach ((array) ($x['ca'][$c] ?? []) as $cod => $q) if ($q > 0) $porArtigo30[$cod][$c] = true;
        }
    }
    foreach ($ag as $x) foreach (($x['artigos'] ?? []) as $arts) foreach ($arts as $cod => $a) if (!isset($nomesArtigos[$cod]) && $a['n'] !== '') $nomesArtigos[$cod] = $a['n'];
    $nActivos = count($activos30);
    arsort($art90);
    $distribuicao = [];
    foreach (array_slice(array_keys($art90), 0, 15) as $cod) {
        $distribuicao[] = ['codigo' => (string) $cod, 'nome' => $nomesArtigos[$cod] ?? (string) $cod, 'clientes' => count($porArtigo30[$cod] ?? []),
            'distribuicao' => $nActivos ? round(count($porArtigo30[$cod] ?? []) / $nActivos, 3) : null, 'vendas_90' => round($art90[$cod], 2)];
    }
    $porRota = [];
    foreach ($porRota30 as $rt => $x) {
        $porRota[] = ['rota' => (string) $rt, 'promotor' => $promotores[$rt] ?? '', 'compras' => $x['compras'], 'vendas' => round($x['valor'], 2),
            'valor_medio' => round($x['valor'] / max(1, $x['compras']), 2), 'artigos_por_compra' => $x['com_artigos'] ? round($x['skus'] / $x['com_artigos'], 2) : null];
    }
    usort($porRota, function ($a, $b) { return $b['vendas'] <=> $a['vendas']; });
    $compras = array_sum(array_column($porRota, 'compras'));
    $comArt = array_sum(array_column($porRota30, 'com_artigos'));

    // 8. Mapa de calor: média de vendas por rota e dia da semana nas últimas 8 semanas (dias completos)
    $calor = [];
    foreach ($rotas as $rt) {
        $l = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? '', 'dias' => array_fill(1, 7, 0.0)];
        for ($k = 1; $k <= 56; $k++) $l['dias'][naval_dia_semana($dia($k))] += $tot($dia($k), $rt) / 8;
        $l['dias'] = array_map(function ($v) { return round($v, 2); }, array_values($l['dias']));
        $calor[] = $l;
    }
    usort($calor, function ($a, $b) { return array_sum($b['dias']) <=> array_sum($a['dias']); });

    // 9. Carteira por mês: activos, novos (sem compras nos 3 meses anteriores) e perdidos (compraram no mês anterior, não neste)
    $porMes = [];
    foreach ($ag as $d => $x) foreach (($x['clientes'] ?? []) as $c => $cl) if ((float) $cl['t'] > 0) $porMes[substr($d, 0, 7)][$c] = true;
    $meses = [];
    for ($m = 3; $m >= 0; $m--) {
        $mm = date('Y-m', strtotime($iniMes . " 12:00 -$m month"));
        $antes = [];
        for ($j = 1; $j <= 3; $j++) $antes += $porMes[date('Y-m', strtotime($mm . "-01 12:00 -$j month"))] ?? [];
        $actual = $porMes[$mm] ?? [];
        $anterior = $porMes[date('Y-m', strtotime($mm . '-01 12:00 -1 month'))] ?? [];
        $meses[] = ['mes' => $mm, 'activos' => count($actual), 'novos' => count(array_diff_key($actual, $antes)), 'perdidos' => count(array_diff_key($anterior, $actual)),
            'incompleto' => $m === 0];
    }

    return [
        'hoje' => $hoje,
        'kpis' => ['vendas_mes' => $mesTot['actual'], 'variacao_mes' => $mesTot['anterior'] > 0 ? round($mesTot['actual'] / $mesTot['anterior'] - 1, 3) : null,
            'clientes_activos_30' => $nActivos, 'compras_30' => $compras, 'valor_medio_compra' => $compras ? round(array_sum(array_column($porRota, 'vendas')) / $compras, 2) : null,
            'artigos_por_compra' => $comArt ? round(array_sum(array_column($porRota30, 'skus')) / $comArt, 2) : null,
            'clientes_80' => naval_painel_abc($cli90)['ate80'], 'clientes_90' => count(array_filter($cli90, function ($v) { return $v > 0; }))],
        'tendencia' => $tendencia,
        'mes' => ['total' => $mesTot, 'rotas' => $mes],
        'abc_clientes' => naval_painel_abc($cli90),
        'abc_artigos' => naval_painel_abc($art90),
        'distribuicao' => $distribuicao,
        'por_rota' => $porRota,
        'calor' => $calor,
        'carteira' => $meses,
    ];
}
