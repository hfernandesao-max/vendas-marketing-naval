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
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');
$nome = NAVAL_APP_NOMES[$c['app']];
// o código já foi validado (32 caracteres de [A-Za-z0-9_-]) e o JSON escapa o resto
echo json_encode([   // nosemgrep -- resposta JSON (não HTML), com o código já validado
    'id' => 'app-' . $c['app'],
    'name' => 'Grupo Naval · ' . $nome,
    'short_name' => $nome,
    'description' => $c['app'] === 'gestao' ? 'Vendas do dia, rotas, alertas e assistente de gestão do Grupo Naval.' : 'Portal RTM do Grupo Naval.',
    'lang' => 'pt-PT',
    'dir' => 'ltr',
    'start_url' => 'activar.php?c=' . $codigo,
    'scope' => '../',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#060d20',
    'theme_color' => '#273777',
    'icons' => [
        ['src' => 'icones/icone-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icones/icone-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icones/icone-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
