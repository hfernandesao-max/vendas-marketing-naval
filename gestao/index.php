<?php
/**
 * App de gestão «Naval Gestão» (aplicação web instalável no iPhone e no Android): indicadores do dia, rotas, alertas e
 * assistente, só de leitura, para os perfis com «App de gestão» na matriz de acessos. Mesma entrada, dois passos e
 * âmbito do portal; os dados vêm da API central (v1/indicadores, v1/alertas, v1/assistente).
 */
define('NAVAL_AUTH_REDIRECT', '../gestao/');
require_once dirname(__DIR__) . '/geo/auth/auth_check.php';
header('Cache-Control: no-store');
if (!naval_pode('app-gestao')) {
    header('Location: ../reports/', true, 302);
    exit;
}
// Só nos equipamentos registados: a app instala-se e activa-se apenas pelo código QR pessoal do portal
naval_servico('app-telemovel');
if (empty($_SESSION['app_equipamento']) || !naval_app_activo((string) $_SESSION['app_equipamento'])) {
    header('Location: ../app/so-pelo-qr.php', true, 302);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$meta = '<meta name="csrf-token" content="' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '">';
echo preg_replace('/<head>/i', '<head>' . "\n  " . $meta, (string) file_get_contents(__DIR__ . '/index.html'), 1);
