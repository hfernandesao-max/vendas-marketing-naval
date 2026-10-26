<?php
require_once dirname(__DIR__, 2) . '/nucleo/arranque.php';
naval_iniciar_sessao();

// Registo da saída
if (!empty($_SESSION['usuario_login'])) naval_registar('saida');

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
        $params['domain'],
        $params['secure'],
        $params['httponly']
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
