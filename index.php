<?php
declare(strict_types=1);

// O hub só é apresentado depois de uma autenticação válida.
$projectPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
define('NAVAL_AUTH_BASE_PATH', ($projectPath === '' ? '' : $projectPath) . '/geo/');
define('NAVAL_AUTH_REDIRECT', '../');
require_once __DIR__ . '/geo/auth/auth_check.php';

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
