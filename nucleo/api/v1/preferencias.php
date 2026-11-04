<?php
/**
 * Preferências pessoais da conta (menu lateral: modo e posição; funções favoritas).
 *
 * GET  v1/preferencias                                     {preferencias: {barra, lado, favoritos}}
 * POST v1/preferencias {barra?, lado?, favoritos?, csrf}   guarda e devolve as preferências
 */
defined('NAVAL_API') || exit;
$GLOBALS['naval_api_sensivel'] = true;
naval_servico('preferencias');
$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
$login = (string) $eu['login'];
if (!naval_e_post()) naval_json(['ok' => true, 'preferencias' => naval_pref_ler($login)]);
$p = naval_pedido();
naval_exigir_csrf($p);
naval_json(['ok' => true, 'preferencias' => naval_pref_gravar($login, $p)]);
