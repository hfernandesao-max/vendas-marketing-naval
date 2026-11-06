<?php
/**
 * Manifesto da app instalada pelo código QR (W3C Web App Manifest): nome da app do perfil e início em activar.php
 * com o código, para a app ficar activada neste equipamento ao abrir pela primeira vez. Sem código válido, não há
 * manifesto (a app não se instala de outra forma).
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_servico('app-telemovel');
$codigo = (string) ($_GET['c'] ?? '');
$c = naval_app_codigo($codigo);
if (!$c) {
    http_response_code(404);
    exit;
}
naval_app_manifesto($c, $codigo);
