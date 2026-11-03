<?php
/**
 * Terreno do dia (GeoMobilidade): percurso de cada rota, paragens, prova de visita, tempos, saídas de zona e
 * coordenadas sugeridas pelas vendas, a partir da localização registada no servidor OLAP (nucleo/servicos/olap.php).
 *
 * GET v1/terreno?data=AAAA-MM-DD[&actualizar=1]    recurso «geo-terreno» (Vr: só as suas rotas)
 * GET v1/terreno?estado=1                          estado da ligação ao OLAP (nível G)
 */
defined('NAVAL_API') || exit;
naval_servico('olap');
naval_servico('prospeccao');
naval_exigir(['geo-terreno']);
if (isset($_GET['estado'])) {
    if (!naval_pode('geo-terreno', 'G') && naval_perfil() !== 'SADM') throw new NavalErro('Sem permissão.', 403);
    naval_json(['ok' => true] + naval_olap_estado());
}
$data = (string) ($_GET['data'] ?? date('Y-m-d'));
if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
$rotas = naval_rotas();
$d = naval_olap_dia($data, $rotas, ($_GET['actualizar'] ?? '') === '1' && naval_pode('geo-terreno', 'G'));
$g = naval_json_ler(naval_geo_pasta() . '/vista.json');
$clientes = array_values(array_filter((array) ($g['clientes'] ?? []), function ($c) use ($rotas) { return $rotas === null || in_array((string) $c['r'], $rotas, true); }));
$terr = [];
foreach ((array) ($g['rotas'] ?? []) as $r) $terr[(string) $r['rota']] = (array) ($r['territorio'] ?? []);
$t = naval_olap_terreno($d['pontos'], $clientes, $terr, (int) naval_geo_num('GEO_VISITAS_DIA', 30));
naval_json(['ok' => true, 'data' => $data, 'origem' => $d['origem'], 'erro' => $d['erro'], 'olap' => naval_olap_configurado()] + $t
    + ['territorios' => $rotas === null ? $terr : array_intersect_key($terr, array_flip($rotas))]);
