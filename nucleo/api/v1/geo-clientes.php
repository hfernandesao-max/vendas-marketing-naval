<?php
/**
 * Clientes com coordenadas para os mapas da GeoMobilidade (Zonas de entrega e Distribuidores), lidos do Pinguim BO
 * pela mesma consulta da prospecção (nunca de ficheiros). No âmbito de quem pede (SUP e PRO: só as suas rotas).
 *
 * GET v1/geo-clientes   {clientes: [{Código, Nome, Vendedor, Rota, Dia, Estado, Latitude, Longitude, Distribuidor?,
 *                        «Tipo de estabelecimento»?}], origem, gerado}
 * Recursos «geo-zonas» ou «geo-distribuidores» (V).
 */
defined('NAVAL_API') || exit;
naval_exigir(['geo-zonas', 'geo-distribuidores']);
naval_servico('prospeccao');
naval_servico('analise');
@set_time_limit(300);
$rotas = naval_rotas();
$ag = naval_analise_agregados(date('Y-m-d', time() - 56 * 86400), date('Y-m-d', time() - 86400));
$d = naval_geo_clientes($ag);
$prom = naval_promotores();
$dias = [1 => 'SEGUNDA-FEIRA', 2 => 'TERÇA-FEIRA', 3 => 'QUARTA-FEIRA', 4 => 'QUINTA-FEIRA', 5 => 'SEXTA-FEIRA', 6 => 'SÁBADO', 7 => 'DOMINGO'];
$out = [];
foreach ((array) ($d['clientes'] ?? []) as $c) {
    $r = (string) ($c['r'] ?? '');
    if ($rotas !== null && !in_array($r, $rotas, true)) continue;
    $x = ['Código' => (string) $c['c'], 'Nome' => (string) $c['n'], 'Rota' => $r,
        'Vendedor' => $r !== '' ? $r . (isset($prom[$r]) && $prom[$r] !== '' ? ' · ' . $prom[$r] : '') : 'Sem rota',
        'Dia' => $dias[(int) ($c['d'] ?? 0)] ?? '', 'Estado' => $r !== '' ? 'Activo' : 'Inactivo',
        'Latitude' => (float) $c['lat'], 'Longitude' => (float) $c['lng']];
    if (isset($c['dist'])) $x['Distribuidor'] = $c['dist'];
    if (isset($c['tipo'])) $x['Tipo de estabelecimento'] = $c['tipo'];
    $out[] = $x;
}
naval_json(['ok' => true, 'origem' => $d['origem'] ?? '', 'gerado' => $d['gerado'] ?? null, 'erro' => $d['erro'] ?? null, 'clientes' => $out]);
