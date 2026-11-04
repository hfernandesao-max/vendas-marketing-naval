<?php
declare(strict_types=1);

// Raiz do site: só depois de uma autenticação válida, e encaminha para o portal.
$projectPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
define('NAVAL_AUTH_BASE_PATH', ($projectPath === '' ? '' : $projectPath) . '/geo/');
define('NAVAL_AUTH_REDIRECT', '../');
require_once __DIR__ . '/geo/auth/auth_check.php';

// Plataforma única: depois de entrar, o utilizador vai directo ao portal (Início), sem página intermédia de escolha.
// O GeoMobilidade está dentro do portal (menu GeoMobilidade); a antiga página de escolha deixou de ser necessária.
header('Location: reports/', true, 302);
exit;
