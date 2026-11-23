<?php
/**
 * Páginas HTML protegidas: abre a sessão (núcleo do back-end) e, sem sessão válida, envia para a
 * página de entrada com o regresso à página pedida. Os serviços JSON usam nucleo/api.php.
 */
require_once dirname(__DIR__, 2) . '/nucleo/arranque.php';
naval_iniciar_sessao();

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$configuredBasePath = defined('NAVAL_AUTH_BASE_PATH') ? NAVAL_AUTH_BASE_PATH : null;
$configuredRedirect = defined('NAVAL_AUTH_REDIRECT') ? NAVAL_AUTH_REDIRECT : null;

if (!naval_autenticado()) {
    // No servidor Apache em C:\Apache24\htdocs\geo com o domínio geo.naval.co.ao,
    // a aplicação é servida na raiz ('/'). Se acessada sob subdiretório '/geo/', preserva o caminho.
    $basePath = $configuredBasePath ?: '/';
    if (!$configuredBasePath && preg_match('#^(.*)/geo/$#i', $scriptDir . '/', $matches)) {
        $basePath = $matches[1] . '/geo/';
    } elseif (!$configuredBasePath && preg_match('#^(.*)/(?:reports|equipamentos|gestao|operacoes)(?:/.*)?/$#i', $scriptDir . '/', $matches)) {
        $basePath = $matches[1] . '/geo/';
    }

    // Determinar destino pretendido para redirecionamento após o login
    $relTarget = $configuredRedirect ?: '';
    if ($relTarget === '' && preg_match('#/reports/index\.php$#i', $scriptName)) {
        $relTarget = '../reports/';
    } elseif ($relTarget === '' && preg_match('#(?:^|/geo/)(geo\.php|prospeccao\.php|reports\.php|view/index\.php|view/viewDist/index\.php)$#i', $scriptName, $m)) {
        $relTarget = $m[1];
    }

    $redirectParam = $relTarget !== '' ? ('?redirect=' . urlencode($relTarget)) : '';
    // App instalada pelo código QR (cookie do equipamento): entrada rápida com Face ID ou impressão digital
    $temEq = false;
    foreach ($_COOKIE as $k => $v) {
        if (preg_match('/^naval_eq(_(gestao|operacoes|portal))?' . (naval_ensaio() ? '_ensaio' : '') . '$/', (string) $k) && preg_match('/^[a-f0-9]{24}$/', (string) $v)) $temEq = true;
    }
    if ($temEq && preg_match('#^(.*)/geo/$#', $basePath, $mm)) {
        header('Location: ' . $mm[1] . '/app/entrar.php' . $redirectParam);
        exit;
    }
    header('Location: ' . $basePath . 'login.php' . $redirectParam);
    exit;
}
