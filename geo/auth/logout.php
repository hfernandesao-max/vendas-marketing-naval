<?php
require_once dirname(__DIR__, 2) . '/nucleo/arranque.php';
naval_iniciar_sessao();

// Protecção CSRF: só termina a sessão com o código anti-falsificação da sessão (POST ou ?t= nas ligações
// «Terminar sessão» geradas pelo servidor). Sem ele (por exemplo, uma imagem noutro site a apontar para
// aqui), pede confirmação em vez de terminar a sessão.
$token = (string) ($_POST['csrf'] ?? $_GET['t'] ?? '');
if (!empty($_SESSION['usuario_autenticado']) && !hash_equals(naval_csrf(), $token)) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $t = htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Terminar sessão</title><style>body{font-family:Arial,sans-serif;background:#0f1d3a;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . 'form{background:#16264a;padding:2rem;border-radius:10px;text-align:center}button{background:#2a6fe0;color:#fff;border:0;border-radius:6px;padding:.6rem 1.4rem;font-size:1rem;cursor:pointer}</style></head>'
        . '<body><form method="post" action="logout.php"><p>Terminar a sessão na plataforma?</p><input type="hidden" name="csrf" value="' . $t . '">'
        . '<button type="submit">Terminar sessão</button></form></body></html>';
    exit;
}

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
