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
$eqApp = !empty($_SESSION['app_equipamento']) ? naval_app_activo((string) $_SESSION['app_equipamento']) : null;
if (!$eqApp) {
    header('Location: ../app/so-pelo-qr.php', true, 302);
    exit;
}
// a app só abre com a chave do telemóvel (Face ID, impressão digital ou código de bloqueio): termina a activação
if (!$eqApp['credenciais']) {
    header('Location: ../app/activar.php', true, 302);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
require_once dirname(__DIR__) . '/nucleo/versoes.php';
$meta = '<meta name="csrf-token" content="' . htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') . '">' . naval_meta_token();
$html = (string) preg_replace('/<head>/i', '<head>' . "\n  " . $meta, (string) file_get_contents(__DIR__ . '/index.html'), 1);
// Velocidade (abrir a app em menos de 1 segundo na rede móvel): estilos dentro da página (menos um pedido), letra do
// sistema do telemóvel (sem descarregar letras) e módulos com versão no endereço, guardados no telemóvel até mudarem
// (import map e pré-carregamento de toda a cadeia de módulos, sem esperar pelos import)
$css = (string) file_get_contents(__DIR__ . '/app.css');
$html = str_replace('<link rel="stylesheet" href="app.css" />', '<style>' . $css . '</style>', $html);
echo str_ireplace('</body>', naval_faixa_ensaio() . '</body>', naval_portal_versionar($html, dirname(__DIR__) . '/reports', [], '../reports/', 'js/app-gestao.js'));
