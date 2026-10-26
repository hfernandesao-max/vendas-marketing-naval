<?php
/**
 * Sessão actual para o portal: utilizador, perfil, rotas e nível de acesso em cada página.
 * O portal usa isto para mostrar só o que o perfil pode ver; os dados são filtrados no servidor.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';

$u = naval_utilizador();
if (!$u) {
    http_response_code(401);
    echo json_encode(['erro' => 'Sessão terminada.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$perm = [];
foreach (array_keys(naval_matriz()) as $rec) $perm[$rec] = naval_nivel($rec);
echo json_encode([
    'login' => $u['login'],
    'nome' => $u['nome'] ?? $u['login'],
    'perfil' => $u['perfil'],
    'perfil_nome' => NAVAL_PERFIS[$u['perfil']] ?? $u['perfil'],
    'rotas' => naval_rotas(),
    'nif' => naval_ve_nif(),
    'permissoes' => $perm,
    'email' => $u['email'] ?? '',
    'csrf' => $_SESSION['csrf'],
    'dois_passos' => !empty($u['mfa']),
    'compatibilidade' => !empty($u['compatibilidade']),
    'sessao' => ['inactiva_s' => NAVAL_SESSAO_INACTIVA, 'maxima_s' => NAVAL_SESSAO_MAXIMA],
], JSON_UNESCAPED_UNICODE);
