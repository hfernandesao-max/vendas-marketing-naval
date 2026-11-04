<?php
/**
 * Percursos optimizados automaticamente todas as noites (nucleo/servicos/roteamento.php).
 *
 * GET v1/rotas-optimizadas?dia=AAAA-MM-DD   {gerado, dias, dia, cfg, percursos: {rota: {paragens, km, minutos, …}}}
 * Recurso «geo-rotas» (Vr: só as rotas da pessoa). Os dados vêm do Pinguim BO (clientes, coordenadas e vendas).
 */
defined('NAVAL_API') || exit;
naval_exigir(['geo-rotas']);
naval_servico('prospeccao');
naval_servico('roteamento');
$dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
$v = naval_rot_vista(naval_rotas(), $dia);
$v['promotores'] = (object) array_intersect_key(naval_promotores(), (array) $v['percursos']);
naval_json(['ok' => true] + $v);
