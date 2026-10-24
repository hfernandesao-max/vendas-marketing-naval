<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

 $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$configuredBasePath = defined('NAVAL_AUTH_BASE_PATH') ? NAVAL_AUTH_BASE_PATH : null;
$configuredRedirect = defined('NAVAL_AUTH_REDIRECT') ? NAVAL_AUTH_REDIRECT : null;

if (!isset($_SESSION['usuario_autenticado']) || $_SESSION['usuario_autenticado'] !== true) {
    // A API dos relatórios deve responder JSON, não uma página de login.
    if (preg_match('#/reports/data/api\.php$#i', $scriptName)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Autenticação necessária.']);
        exit;
    }

    // No servidor Apache em C:\Apache24\htdocs\geo com o domínio geo.naval.co.ao,
    // a aplicação é servida na raiz ('/'). Se acessada sob subdiretório '/geo/', preserva o caminho.
    $basePath = $configuredBasePath ?: '/';
    if (!$configuredBasePath && preg_match('#^(.*)/geo/$#i', $scriptDir . '/', $matches)) {
        $basePath = $matches[1] . '/geo/';
    } elseif (!$configuredBasePath && preg_match('#^(.*)/reports(?:/.*)?/$#i', $scriptDir . '/', $matches)) {
        $basePath = $matches[1] . '/geo/';
    }

    // Determinar destino pretendido para redirecionamento após o login
    $relTarget = $configuredRedirect ?: '';
    if ($relTarget === '' && preg_match('#/reports/index\.php$#i', $scriptName)) {
        $relTarget = '../reports/';
    } elseif ($relTarget === '' && preg_match('#(?:^|/geo/)(geo\.php|reports\.php|view/index\.php|view/viewDist/index\.php)$#i', $scriptName, $m)) {
        $relTarget = $m[1];
    }

    $redirectParam = $relTarget !== '' ? ('?redirect=' . urlencode($relTarget)) : '';
    header('Location: ' . $basePath . 'login.php' . $redirectParam);
    exit;
}
