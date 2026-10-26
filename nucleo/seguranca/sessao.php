<?php
/** Sessão PHP da plataforma: cookie só por HTTP, mesmo site, e validade (ver naval_sessao_valida). */
defined('NAVAL_NUCLEO') || exit;

/** Abre a sessão (se ainda não estiver aberta) e termina-a se tiver expirado ou a conta deixar de ser válida. */
function naval_iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            if (PHP_VERSION_ID >= 70300) session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            else session_set_cookie_params(0, '/; samesite=Lax', '', false, true);   // PHP 7.1 e 7.2
        }
        session_start();
    }
    if (!empty($_SESSION['usuario_autenticado'])) naval_sessao_valida();
}

function naval_autenticado(): bool
{
    return isset($_SESSION['usuario_autenticado']) && $_SESSION['usuario_autenticado'] === true;
}
