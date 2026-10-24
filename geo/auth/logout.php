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

// Destruir todas as variáveis de sessão
$_SESSION = [];

// Limpar cookie de sessão se existir
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}

// Destruir a sessão
session_destroy();

// Redirecionar para login
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

// No servidor Apache em C:\Apache24\htdocs\geo com o domínio geo.naval.co.ao,
// a aplicação é servida na raiz ('/'). Se acessada sob subdiretório '/geo/', preserva o caminho.
$basePath = '/';
if (preg_match('#^(.*?/geo/)#i', $scriptDir . '/', $matches)) {
    $basePath = $matches[1];
}

header('Location: ' . $basePath . 'login.php');
exit;
