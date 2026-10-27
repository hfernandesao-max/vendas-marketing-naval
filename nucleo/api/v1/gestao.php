<?php
/**
 * Página «Acessos» do portal: utilizadores, matriz de acessos e registos de actividade
 * (ver nucleo/servicos/gestao.php).
 *
 * GET: perfis com acesso à página Acessos.
 * POST {accao, csrf, confirmacao, ...}: só o super administrador, com confirmação da identidade.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('gestao');
naval_exigir(['acessos']);

if (!naval_e_post()) naval_json(naval_gestao_vista());

$p = naval_pedido();
$accao = (string) ($p['accao'] ?? '');
$eu = (array) naval_utilizador();
if ($accao !== 'activar_gestao') naval_gestao_autorizar($eu, $accao);
elseif (!naval_gestao_pode_activar($eu)) throw new NavalErro('A gestão de contas já está activa ou esta conta não a pode activar.', 403);
naval_exigir_csrf($p);
$pedirConfirmacao = naval_gestao_confirmar($eu, $accao, (string) ($p['confirmacao'] ?? ''));
if ($pedirConfirmacao) naval_json($pedirConfirmacao);

$login = trim((string) ($p['conta'] ?? ''));
// Todas as alterações em exclusivo: contas, perfis e matriz são lidos e gravados sem outro pedido pelo meio
naval_exclusivo('utilizadores', function () use ($accao, $p, $eu, $login) {
switch ($accao) {
    case 'activar_gestao':
        naval_json(['ok' => true] + naval_gestao_activar($p, $eu));
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
    case 'guardar_perfil':
        naval_json(['ok' => true] + naval_gestao_guardar_perfil($p, $eu));
    case 'apagar_perfil':
        naval_gestao_apagar_perfil((string) ($p['codigo'] ?? ''));
        naval_json(['ok' => true]);
    case 'repor_matriz':
        naval_gravar_matriz(naval_matriz_base());
        naval_registar('matriz_reposta', []);
        naval_json(['ok' => true]);
}
});
throw new NavalErro('Acção desconhecida.');
