<?php
/**
 * Página «Acessos» do portal: utilizadores e perfis, matriz de acessos e registos de actividade.
 * Só leitura: a criação e alteração de contas faz-se no servidor com ferramentas/utilizadores.php.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';
naval_exigir(['acessos']);

// Desbloqueio de contas: só o super administrador, com o código anti-falsificação da sessão
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $erro = null;
    if (naval_perfil() !== 'SADM') {
        $erro = 'Só o super administrador pode desbloquear contas.';
    } elseif (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($p['csrf'] ?? ''))) {
        $erro = 'Pedido inválido. Actualize a página e tente de novo.';
    }
    if ($erro) {
        naval_registar('desbloqueio_recusado', ['conta' => (string) ($p['conta'] ?? '')]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => $erro], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ok = ($p['accao'] ?? '') === 'desbloquear' && naval_desbloquear((string) ($p['conta'] ?? ''), naval_utilizador()['login']);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}
$bloq = naval_contas_bloqueadas();

$ultimas = [];
foreach (naval_ler_registos(2000) as $r) {
    if (($r['accao'] ?? '') === 'entrada' && !isset($ultimas[$r['utilizador']])) $ultimas[$r['utilizador']] = $r['data'];
}
$us = [];
foreach (naval_utilizadores() as $login => $u) {
    $us[] = [
        'login' => $login, 'nome' => $u['nome'] ?? $login, 'email' => $u['email'] ?? '', 'perfil' => $u['perfil'] ?? '',
        'email_ok' => naval_email_valido($u['email'] ?? null), 'bloqueado' => isset($bloq[$login]), 'bloqueado_em' => $bloq[$login] ?? null,
        'perfil_nome' => NAVAL_PERFIS[$u['perfil'] ?? ''] ?? ($u['perfil'] ?? ''),
        'rotas' => in_array($u['perfil'] ?? '', NAVAL_POR_ROTA, true) ? array_values($u['rotas'] ?? []) : null,
        'activo' => naval_conta_valida($u), 'valido_ate' => $u['valido_ate'] ?? null,
        'dois_passos' => !empty($u['mfa']), 'compatibilidade' => !empty($u['compatibilidade']),
        'ultima_entrada' => $ultimas[$login] ?? null,
    ];
}
echo json_encode([
    'perfis' => NAVAL_PERFIS,
    'matriz' => naval_matriz(),
    'utilizadores' => $us,
    'registos' => naval_pode('registos') ? naval_ler_registos(300) : [],
    'pode_desbloquear' => naval_perfil() === 'SADM',
], JSON_UNESCAPED_UNICODE);
