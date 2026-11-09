<?php
/**
 * Inteligência de negócio › Valor do portal (nucleo/servicos/valor.php): quanto o portal rende à Naval, por mês.
 *
 * GET  ?meses=12                                  recurso «valor-portal» (V)
 * POST {accao: parametros, parametros, csrf}      nível G (Direcção) ou super administrador; fica registado
 */
defined('NAVAL_API') || exit;
naval_servico('valor');
naval_exigir(['valor-portal']);
$eu = (array) naval_utilizador();
$gere = in_array(naval_nivel('valor-portal'), ['G', 'A'], true);

if (naval_e_post()) {
    $p = naval_pedido();
    naval_exigir_csrf($p);
    if (!$gere) throw new NavalErro('Só a Direcção altera os parâmetros do cálculo.', 403);
    if (($p['accao'] ?? '') !== 'parametros') throw new NavalErro('Acção desconhecida.', 400);
    naval_valor_gravar_parametros((array) ($p['parametros'] ?? []), $eu);
}
naval_json(['ok' => true, 'gere' => $gere] + naval_valor_vista((int) ($_GET['meses'] ?? 12), time()));
