<?php
/**
 * Página «Acessos» do portal: utilizadores, matriz de acessos e registos de actividade
 * (ver nucleo/servicos/gestao.php).
 *
 * GET: perfis com acesso à página Acessos.
 * POST {accao, csrf, confirmacao, ...}: só o super administrador, com confirmação da identidade.
 */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('gestao');
naval_exigir(['acessos']);

if (!naval_e_post()) naval_json(naval_gestao_vista());

$p = naval_pedido();
$accao = (string) ($p['accao'] ?? '');
$eu = (array) naval_utilizador();
naval_gestao_autorizar($eu, $accao);
naval_exigir_csrf($p);
$pedirConfirmacao = naval_gestao_confirmar($eu, $accao, (string) ($p['confirmacao'] ?? ''));
if ($pedirConfirmacao) naval_json($pedirConfirmacao);

$login = trim((string) ($p['conta'] ?? ''));
switch ($accao) {
    case 'desbloquear':
        naval_json(['ok' => naval_desbloquear($login, $eu['login'])]);
    case 'guardar_conta':
        naval_json(['ok' => true] + naval_gestao_guardar_conta($p, $eu));
    case 'senha':
        naval_json(['ok' => true, 'senha' => naval_gestao_repor_senha($login)]);
    case 'dois_passos':
        naval_json(['ok' => true] + naval_gestao_dois_passos($login, !empty($p['activar']), $eu));
    case 'matriz':
        naval_json(['ok' => true, 'alteracoes' => naval_gestao_matriz(is_array($p['matriz'] ?? null) ? $p['matriz'] : [])]);
    case 'repor_matriz':
        naval_gravar_matriz(naval_matriz_base());
        naval_registar('matriz_reposta', []);
        naval_json(['ok' => true]);
}
throw new NavalErro('Acção desconhecida.');
