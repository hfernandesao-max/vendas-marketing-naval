<?php
/**
 * Suporte e incidentes (nucleo/servicos/suporte.php; ISO/IEC 20000-1, ISO/IEC 27035).
 *
 * GET:  pedidos visíveis (os seus; quem gere, todos) e indicadores
 * POST {accao: criar, tipo, prioridade, titulo, descricao, dados_pessoais}
 *      {accao: comentar|atender|prioridade|resolver|reabrir|avaliar, id, texto, …}   sempre com csrf
 */
defined('NAVAL_API') || exit;
naval_servico('suporte');
naval_exigir(['suporte']);
$eu = (array) naval_utilizador();
$GLOBALS['naval_api_sensivel'] = true;

if (!naval_e_post()) naval_json(['ok' => true, 'eu' => (string) $eu['login']] + naval_sup_vista($eu, time()));

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'criar') {
    $r = naval_sup_criar($eu, $p);
} else {
    $id = (string) ($p['id'] ?? '');
    if (!preg_match('/^SUP-\d{6}$/', $id)) throw new NavalErro('Pedido inválido.', 400);
    $r = naval_sup_accao($eu, $id, $accao, $p);
}
naval_json(['ok' => true, 'eu' => (string) $eu['login'], 'pedido' => naval_sup_publico($r, time())] + naval_sup_vista($eu, time()));
