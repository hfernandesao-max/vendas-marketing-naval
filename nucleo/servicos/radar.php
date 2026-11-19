<?php
/**
 * Radar do dia (GeoMobilidade, fase 3): ajusta as visitas de cada rota ao dia sem a sobrecarregar.
 *
 * Para cada rota e dia:
 *   - planeadas: as visitas do plano (carteira: dia habitual de compra ou ritmo vencido, naval_cart_visitas);
 *   - capacidade: GEO_VISITAS_DIA (30) visitas por dia e por vendedor;
 *   - lugares livres = capacidade − planeadas (nunca negativo), até GEO_RADAR_MAX (8) sugestões por rota;
 *   - sugestões, por esta ordem de valor:
 *       1. recuperar: clientes regulares da rota sem compras há 14 dias ou mais, perto do caminho do dia (os que
 *          valem mais primeiro; os de 60 dias ou mais à frente);
 *       2. conquistar: potenciais clientes do território da rota (geofence), do dia em que a rota passa na zona ou sem
 *          dia, a até GEO_RADAR_DESVIO (1 km) de uma visita do dia, pelo ICP Score menos o desvio;
 *     intercaladas (recuperar, conquistar, …) para o dia ter das duas;
 *   - acima da capacidade: as visitas «em dia» de menor valor a adiar (excesso), nunca as paradas ou a abrandar.
 *
 * O cálculo (naval_radar_montar) não lê dados: testável. naval_radar_dia junta os ficheiros da noite.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/prospeccao.php';
require_once __DIR__ . '/carteira.php';

/**
 * $plano: {rota: [visitas do plano]}; $coord: {código: [lat, lng]}; $potenciais e $esquecidos da prospecção;
 * $rotasVistas: rotas a mostrar (as do plano e as do território); $ds: dia da semana (1 = segunda).
 */
function naval_radar_montar(array $plano, array $coord, array $potenciais, array $esquecidos, array $rotasVistas, int $ds, array $cfg = []): array
{
    $cap = (int) ($cfg['capacidade'] ?? 30);
    $max = (int) ($cfg['max'] ?? 8);
    $desvio = (float) ($cfg['desvio'] ?? 1000);
    $fora = ['nao_interessado' => 1, 'nao_existe' => 1, 'outro_fornecedor' => 1];
    $rotas = array_values(array_unique(array_map('strval', array_merge(array_keys($plano), $rotasVistas))));
    sort($rotas);
    $out = [];
    foreach ($rotas as $r) {
        $vis = array_values((array) ($plano[$r] ?? []));
        $planeadas = count($vis);
        $livres = max(0, $cap - $planeadas);
        $no = [];   // códigos já no plano
        $pts = [];  // pontos do caminho do dia
        foreach ($vis as $v) {
            $no[(string) $v['c']] = true;
            if (isset($coord[(string) $v['c']])) $pts[] = $coord[(string) $v['c']];
        }
        $perto = function (float $la, float $lo) use ($pts): ?int {
            $m = null;
            foreach ($pts as [$a, $b]) {
                $d = naval_geo_dist($la, $lo, (float) $a, (float) $b);
                if ($m === null || $d < $m) $m = $d;
            }
            return $m === null ? null : (int) round($m);
        };
        // 1. recuperar
        $rec = [];
        foreach ($esquecidos as $e) {
            if ((string) ($e['r'] ?? '') !== $r || isset($no[(string) $e['c']])) continue;
            $d = isset($e['lat'], $e['lng']) ? $perto((float) $e['lat'], (float) $e['lng']) : null;
            if ($pts && ($d === null || $d > $desvio * 2)) continue;   // recuperar vale um desvio maior (até 2 km)
            $rec[] = ['tipo' => 'recuperar', 'id' => (string) $e['c'], 'n' => (string) ($e['n'] ?? $e['c']), 'lat' => $e['lat'] ?? null, 'lng' => $e['lng'] ?? null,
                'desvio_m' => $d, 'valor' => (float) ($e['media'] ?? 0) * (int) ($e['compras'] ?? 1), 'dias' => (int) ($e['dias'] ?? 0),
                'motivo' => 'Cliente regular sem compras há ' . (int) ($e['dias'] ?? 0) . ' dias (' . number_format((float) ($e['media'] ?? 0), 0, ',', ' ') . ' Kz por compra)'];
        }
        usort($rec, function ($a, $b) { return ($b['dias'] >= 60) <=> ($a['dias'] >= 60) ?: $b['valor'] <=> $a['valor'] ?: strcmp($a['id'], $b['id']); });
        // 2. conquistar
        $con = [];
        foreach ($potenciais as $p) {
            if ((string) ($p['r'] ?? '') !== $r || isset($fora[(string) ($p['e'] ?? '')])) continue;
            if (!empty($p['d']) && (int) $p['d'] !== $ds) continue;
            $d = $perto((float) $p['lat'], (float) $p['lng']);
            if ($pts && ($d === null || $d > $desvio)) continue;
            $nota = (int) ($p['s'] ?? 0) - ($d === null ? 0 : (int) floor($d / 100));
            $con[] = ['tipo' => 'conquistar', 'id' => (string) $p['id'], 'n' => (string) ($p['n'] ?? '') ?: 'Estabelecimento sem nome', 'lat' => $p['lat'], 'lng' => $p['lng'],
                'desvio_m' => $d, 's' => (int) ($p['s'] ?? 0), 'a' => (string) ($p['a'] ?? ''), 't' => (string) ($p['t'] ?? ''), 'nota' => $nota,
                'motivo' => 'Potencial cliente (ICP ' . (int) ($p['s'] ?? 0) . ')' . ($d !== null ? ' a ' . $d . ' m de uma visita do dia' : '')];
        }
        usort($con, function ($a, $b) { return $b['nota'] <=> $a['nota'] ?: strcmp($a['id'], $b['id']); });
        $sug = [];
        $n = min($livres, $max);
        for ($i = 0; count($sug) < $n && ($i < count($rec) || $i < count($con)); $i++) {
            if (isset($rec[$i])) $sug[] = $rec[$i];
            if (count($sug) < $n && isset($con[$i])) $sug[] = $con[$i];
        }
        foreach ($sug as &$s) unset($s['valor'], $s['nota']);
        unset($s);
        // acima da capacidade: adiar as «em dia» de menor valor (o plano vem ordenado: parados primeiro, maior valor primeiro)
        $adiar = [];
        $excesso = max(0, $planeadas - $cap);
        if ($excesso) {
            for ($i = $planeadas - 1; $i >= 0 && count($adiar) < $excesso; $i--) {
                if (($vis[$i]['estado'] ?? '') !== 'em_dia') continue;
                $adiar[] = ['id' => (string) $vis[$i]['c'], 'n' => (string) ($vis[$i]['n'] ?? $vis[$i]['c']), 'motivo' => 'Em dia, de menor valor: passa para a próxima visita'];
            }
        }
        $out[] = ['rota' => $r, 'planeadas' => $planeadas, 'capacidade' => $cap, 'livres' => $livres, 'excesso' => $excesso,
            'candidatos' => count($rec) + count($con), 'sugestoes' => $sug, 'adiar' => $adiar];
    }
    return $out;
}

/** Radar de um dia no âmbito de quem pede ($rotas null = todas). */
function naval_radar_dia(string $dia, ?array $rotas): array
{
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $plano = naval_cart_visitas(naval_cart_ler(), $dia, $rotas);
    $coord = [];
    foreach ((array) ($v['clientes'] ?? []) as $c) $coord[(string) $c['c']] = [(float) $c['lat'], (float) $c['lng']];
    $estados = naval_json_ler(naval_geo_pasta() . '/estados.json');
    $pot = [];
    foreach ((array) ($v['potenciais'] ?? []) as $p) {
        if ($rotas !== null && !in_array((string) ($p['r'] ?? ''), $rotas, true)) continue;
        foreach ((array) ($p['ids'] ?? [$p['id']]) as $id) if (isset($estados[$id])) { $p['e'] = $estados[$id]['e']; break; }
        $pot[] = $p;
    }
    $esq = array_values(array_filter((array) ($v['carteira']['esquecidos'] ?? []), function ($e) use ($rotas) {
        return $rotas === null || in_array((string) ($e['r'] ?? ''), $rotas, true);
    }));
    $vistas = [];
    foreach ((array) ($v['rotas'] ?? []) as $r) if ($rotas === null || in_array((string) $r['rota'], $rotas, true)) $vistas[] = (string) $r['rota'];
    $l = naval_radar_montar($plano, $coord, $pot, $esq, $vistas, naval_dia_semana($dia), [
        'capacidade' => (int) naval_geo_num('GEO_VISITAS_DIA', 30), 'max' => (int) naval_geo_num('GEO_RADAR_MAX', 8), 'desvio' => naval_geo_num('GEO_RADAR_DESVIO', 1000)]);
    $prom = naval_promotores();
    foreach ($l as &$x) $x['promotor'] = $prom[$x['rota']] ?? '';
    unset($x);
    return ['dia' => $dia, 'gerado' => $v['gerado'] ?? null, 'rotas' => $l, 'afinidades' => NAVAL_DESC_AFINIDADES];
}
