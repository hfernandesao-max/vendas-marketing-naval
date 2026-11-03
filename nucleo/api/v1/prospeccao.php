<?php
/**
 * Prospecção e territórios das rotas (nucleo/servicos/prospeccao.php).
 *
 * GET  v1/prospeccao[?actualizar=1]                         recurso «geo-prospeccao» (Vr: só as suas rotas); actualizar: nível G
 * POST v1/prospeccao {accao: marcar, id, estado, csrf}       o vendedor regista o que encontrou (nível Vr, na sua rota)
 */
defined('NAVAL_API') || exit;
naval_servico('prospeccao');
naval_exigir(['geo-prospeccao']);
$gere = naval_pode('geo-prospeccao', 'G');

if (!naval_e_post()) {
    $act = ($_GET['actualizar'] ?? '') === '1';
    if ($act && !$gere) throw new NavalErro('O seu perfil não permite recalcular os territórios.', 403);
    naval_json(['ok' => true, 'gere' => $gere] + naval_geo_vista(naval_rotas(), $act));
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (($p['accao'] ?? '') !== 'marcar') throw new NavalErro('Acção desconhecida.', 400);
naval_json(['ok' => true] + naval_geo_marcar((string) ($p['id'] ?? ''), (string) ($p['estado'] ?? ''), (array) naval_utilizador(), naval_rotas()));
