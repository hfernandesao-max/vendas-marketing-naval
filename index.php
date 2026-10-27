<?php
declare(strict_types=1);

// O hub só é apresentado depois de uma autenticação válida.
$projectPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
define('NAVAL_AUTH_BASE_PATH', ($projectPath === '' ? '' : $projectPath) . '/geo/');
define('NAVAL_AUTH_REDIRECT', '../');
require_once __DIR__ . '/geo/auth/auth_check.php';

header('Content-Type: text/html; charset=utf-8');
// ligação «Terminar sessão» com o código anti-falsificação da sessão (ver geo/auth/logout.php)
echo str_replace('href="geo/auth/logout.php"', 'href="geo/auth/logout.php?t=' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '"', (string) file_get_contents(__DIR__ . '/index.html'));
