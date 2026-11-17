<?php
/**
 * Pedidos de acesso e revisão pelo dono de cada função (nucleo/servicos/acessos-pedidos.php). Na página Suporte, para
 * todos os que a vêem; aplicar o pedido faz-se em Acessos (gestao.php, só o super administrador).
 *
 * GET:  pedidos que a pessoa vê (os seus, os da sua conta e os que autoriza; o SADM todos), funções e contas, e a
 *       parte da revisão de acessos aberta que lhe cabe como dono
 * POST {accao: criar, conta, funcoes: [..], fim, motivo, csrf}
 *      {accao: aprovar|recusar|anular, id, nota, csrf}
 *      {accao: revisao, retirar: [{conta, funcao}], notas, csrf}   o dono confirma a sua parte da revisão
 */
defined('NAVAL_API') || exit;
naval_servico('acessos-pedidos');
naval_exigir(['suporte']);
$eu = (array) naval_utilizador();
$GLOBALS['naval_api_sensivel'] = true;
if (!naval_e_post()) naval_json(['ok' => true, 'eu' => (string) ($eu['login'] ?? '')] + naval_pa_vista($eu));

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'criar') {
    $r = naval_pa_criar($p, $eu);
    naval_json(['ok' => true, 'id' => $r['id'], 'eu' => (string) $eu['login']] + naval_pa_vista($eu));
}
if ($accao === 'revisao') {
    naval_pa_revisao_confirmar($eu, (array) ($p['retirar'] ?? []), (string) ($p['notas'] ?? ''));
    naval_json(['ok' => true, 'eu' => (string) $eu['login']] + naval_pa_vista($eu));
}
if (in_array($accao, ['aprovar', 'recusar', 'anular'], true)) {
    $r = naval_pa_decidir((string) ($p['id'] ?? ''), $accao, $eu, (string) ($p['nota'] ?? ''));
    naval_json(['ok' => true, 'id' => $r['id'], 'eu' => (string) $eu['login']] + naval_pa_vista($eu));
}
throw new NavalErro('Acção desconhecida.', 400);
