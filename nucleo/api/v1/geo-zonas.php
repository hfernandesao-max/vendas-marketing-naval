<?php
/**
 * Zonas de entrega desenhadas no mapa, guardadas no servidor (partilhadas por todos, com registo de quem alterou).
 *
 * GET  v1/geo-zonas                {zonas: [{id, paths: [{lat, lng}], fillColor, strokeColor, strokeWeight, label}], alterado}
 *                                   recursos «geo-zonas» ou «geo-distribuidores» (V)
 * POST v1/geo-zonas {zonas, csrf}  substitui as zonas — recurso «geo-zonas-gerir» (G)
 */
defined('NAVAL_API') || exit;
naval_exigir(['geo-zonas', 'geo-distribuidores']);
naval_servico('prospeccao');
$f = naval_geo_pasta() . '/zonas.json';
if (!naval_e_post()) {
    $z = naval_json_ler($f);
    naval_json(['ok' => true, 'zonas' => array_values((array) ($z['zonas'] ?? [])), 'alterado' => $z['alterado'] ?? null]);
}
if (!naval_pode('geo-zonas-gerir', 'G')) throw new NavalErro('Sem permissão para alterar as zonas.', 403);
$p = naval_pedido();
naval_exigir_csrf($p);
$cor = function ($v): string { return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : '#1a73c9'; };
$zonas = [];
foreach (array_slice((array) ($p['zonas'] ?? []), 0, 500) as $z) {
    if (!is_array($z)) continue;
    $pts = [];
    foreach (array_slice((array) ($z['paths'] ?? []), 0, 2000) as $q) {
        if (is_array($q) && naval_geo_valida($q['lat'] ?? null, $q['lng'] ?? null)) $pts[] = ['lat' => round((float) $q['lat'], 6), 'lng' => round((float) $q['lng'], 6)];
    }
    if (count($pts) < 3) continue;
    $zonas[] = ['id' => substr(preg_replace('/[^0-9A-Za-z._-]/', '', (string) ($z['id'] ?? '')), 0, 40) ?: bin2hex(random_bytes(6)), 'paths' => $pts,
        'fillColor' => $cor($z['fillColor'] ?? ''), 'strokeColor' => $cor($z['strokeColor'] ?? ''),
        'strokeWeight' => max(1, min(10, (int) ($z['strokeWeight'] ?? 2))), 'label' => naval_texto((string) ($z['label'] ?? ''), 80)];
}
$eu = (array) naval_utilizador();
naval_exclusivo('geo-zonas', function () use ($f, $zonas, $eu) {
    naval_json_gravar($f, ['alterado' => ['por' => $eu['login'] ?? '', 'em' => date('Y-m-d H:i:s')], 'zonas' => $zonas]);
});
naval_registar('geo_zonas_gravadas', ['zonas' => count($zonas)]);
naval_json(['ok' => true, 'zonas' => $zonas]);
