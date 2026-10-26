<?php
/**
 * Sessão actual para o portal: utilizador, perfil, rotas e nível de acesso em cada página.
 * O portal usa isto para mostrar só o que o perfil pode ver; os dados são filtrados no servidor.
 */
require_once __DIR__ . '/../../nucleo/api.php';

$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
$perm = [];
foreach (array_keys(naval_matriz()) as $rec) $perm[$rec] = naval_nivel($rec);
naval_json([
    'login' => $u['login'],
    'nome' => $u['nome'] ?? $u['login'],
    'perfil' => $u['perfil'],
    'perfil_nome' => naval_perfil_nome($u['perfil']),
    'rotas' => naval_rotas(),
    'nif' => naval_ve_nif(),
    'permissoes' => $perm,
    'email' => $u['email'] ?? '',
    'csrf' => naval_csrf(),
    'dois_passos' => !empty($u['mfa']),
    'compatibilidade' => !empty($u['compatibilidade']),
    'sessao' => ['inactiva_s' => NAVAL_SESSAO_INACTIVA, 'maxima_s' => NAVAL_SESSAO_MAXIMA],
]);
