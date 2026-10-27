<?php
/** Sessão PHP da plataforma: cookie só por HTTP, mesmo site, e validade (ver naval_sessao_valida). */
defined('NAVAL_NUCLEO') || exit;

/** Abre a sessão (se ainda não estiver aberta) e termina-a se tiver expirado ou a conta deixar de ser válida. */
function naval_iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // HTTPS obrigatório (EXIGIR_HTTPS=1 no .env): pedidos em HTTP são redireccionados para HTTPS
        if (naval_config('EXIGIR_HTTPS', '0') === '1' && !naval_https() && !headers_sent() && PHP_SAPI !== 'cli') {
            $host = (string) parse_url(naval_config('PORTAL_URL'), PHP_URL_HOST) ?: (string) preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? ''));
            $porta = (string) parse_url(naval_config('PORTAL_URL'), PHP_URL_PORT);
            header('Location: https://' . $host . ($porta !== '' ? ':' . $porta : '') . (string) ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
        if (!headers_sent()) {
            // Boas práticas OWASP: só cookies (nunca o identificador no URL), modo estrito (recusa
            // identificadores que o servidor não criou), cookie só por HTTP, mesmo site e, em HTTPS, Secure.
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            @ini_set('session.use_trans_sid', '0');
            @ini_set('session.cookie_httponly', '1');
            @ini_set('session.sid_length', '48');
            @ini_set('session.sid_bits_per_character', '6');
            $seguro = naval_https();
            // Nome próprio do cookie; em HTTPS com o prefixo __Host- (só este site, só HTTPS, caminho /)
            session_name($seguro ? '__Host-NAVALSESS' : 'NAVALSESS');
            if (PHP_VERSION_ID >= 70300) session_set_cookie_params(['path' => '/', 'secure' => $seguro, 'httponly' => true, 'samesite' => 'Lax']);
            else session_set_cookie_params(0, '/; samesite=Lax', '', $seguro, true);   // PHP 7.1 e 7.2
            naval_cabecalhos_seguranca();
        }
        session_start();
    }
    if (!empty($_SESSION['usuario_autenticado'])) naval_sessao_valida();
}

function naval_autenticado(): bool
{
    return isset($_SESSION['usuario_autenticado']) && $_SESSION['usuario_autenticado'] === true;
}

/**
 * Cabeçalhos de segurança das respostas PHP (também no .htaccess, para os ficheiros estáticos).
 * Content-Security-Policy em duas partes (implementação faseada recomendada pela OWASP):
 *  - aplicada já: proíbe enquadrar o portal noutros sites, mudar a base dos endereços, enviar
 *    formulários para fora e plugins;
 *  - só em relatório (Report-Only): a lista de origens de scripts, estilos e imagens (Google Maps,
 *    fontes, bibliotecas). As violações aparecem na consola do navegador; quando não houver nenhuma,
 *    pode passar a aplicada (CSP_APLICAR=1 no .env).
 */
function naval_cabecalhos_seguranca(): void
{
    static $feito = false;
    if ($feito || headers_sent()) return;
    $feito = true;
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');   // páginas e dados autenticados não ficam em caches (ASVS 8.2.1)
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), payment=(), usb=(), geolocation=(self)');
    header_remove('X-Powered-By');
    if (naval_https()) header('Strict-Transport-Security: max-age=31536000');
    $base = "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";
    $origens = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://maps.googleapis.com https://maps.gstatic.com https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com; "
        . "connect-src 'self' https://*.googleapis.com https://*.gstatic.com https://*.google.com; worker-src 'self' blob:";
    if (naval_config('CSP_APLICAR', '0') === '1') {
        header('Content-Security-Policy: ' . $origens . '; ' . $base);
    } else {
        header('Content-Security-Policy: ' . $base);
        header('Content-Security-Policy-Report-Only: ' . $origens);
    }
}
