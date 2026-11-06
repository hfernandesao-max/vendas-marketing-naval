<?php
/**
 * Sessão actual para o portal: utilizador, perfil, rotas e nível de acesso em cada página.
 * O portal usa isto para mostrar só o que o perfil pode ver; os dados são filtrados no servidor.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado

$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
naval_servico('preferencias');
$perm = [];
foreach (array_keys(naval_matriz()) as $rec) $perm[$rec] = naval_nivel($rec);
$GLOBALS['naval_api_sensivel'] = true;   // tem o código CSRF: nunca em cache nem comprimido
naval_json([
    'login' => $u['login'],
    'nome' => $u['nome'] ?? $u['login'],
    'perfil' => $u['perfil'],
    'perfil_nome' => naval_perfil_nome($u['perfil']),
    'rotas' => naval_rotas(),
    'nif' => naval_ve_nif(),
    'permissoes' => $perm,
    'email' => $u['email'] ?? '',
    // idioma da ficha da conta e momento da entrada: o portal abre nesse idioma a cada nova entrada
    'idioma' => naval_idioma_conta($u),
    'entrada' => (int) ($_SESSION['login_time'] ?? 0),
    'csrf' => naval_csrf(),
    'dois_passos' => !empty($u['mfa']),
    'compatibilidade' => !empty($u['compatibilidade']),
    // preferências pessoais (menu lateral): seguem a conta em qualquer dispositivo
    'preferencias' => naval_pref_ler((string) $u['login']),
    'sessao' => ['inactiva_s' => NAVAL_SESSAO_INACTIVA, 'maxima_s' => NAVAL_SESSAO_MAXIMA],
    // app instalada pelo código QR (equipamento registado) ou null: fora disso, o portal não funciona como app
    'app' => !empty($_SESSION['app_equipamento']) ? (string) $_SESSION['app_equipamento'] : null,
]);
