<?php
/**
 * POST v1/auth/token — troca a sessão aberta na página de entrada (palavra-passe, dois passos, bloqueio)
 * por um token de acesso JWT de 15 minutos para a API. O front-end guarda-o só em memória e pede outro
 * antes de expirar. Exige o código CSRF da sessão no cabeçalho X-CSRF-Token (um cabeçalho próprio também
 * obriga o navegador a pedir autorização prévia a outros sites, que a API não concede).
 */
defined('NAVAL_API') || exit;

naval_iniciar_sessao();
if (!naval_autenticado() || !($u = naval_utilizador())) {
    throw new NavalErro('Autenticação necessária.', 401, ['codigo' => 'sessao_terminada']);
}
if (!hash_equals(naval_csrf(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    throw new NavalErro('Pedido inválido. Actualize a página e tente de novo.', 403, ['codigo' => 'csrf']);
}
naval_api_ritmo('token|' . $u['login'], max(10, (int) naval_config('API_RITMO_TOKEN', '30')));   // tokens por minuto por pessoa
$GLOBALS['naval_api_sensivel'] = true;   // nunca em cache nem comprimido
naval_json(['ok' => true] + naval_jwt_emitir($u, session_id()));
