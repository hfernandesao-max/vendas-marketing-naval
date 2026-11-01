<?php
/**
 * Entrada da app «Equipamentos» (start_url do manifest, instalada no iPhone pelo Safari) e regresso depois de
 * iniciar sessão a partir da página pública do código QR: abre a ficha lida (guardada na sessão) ou a lista.
 */
define('NAVAL_AUTH_REDIRECT', '../equipamentos/');
require_once dirname(__DIR__) . '/geo/auth/auth_check.php';
header('Cache-Control: no-store');

$id = (string) ($_SESSION['atv_qr'] ?? '');
unset($_SESSION['atv_qr']);
if (!naval_pode('equipamentos')) {
    // sem acesso ao módulo: a página pública da etiqueta lida, ou a página principal
    header('Location: ' . (preg_match('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $id) ? 'ficha.php?id=' . rawurlencode($id) : '../'), true, 302);
    exit;
}
$destino = preg_match('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $id) ? '#equipamentos?id=' . rawurlencode($id) . '&origem=qr' : '#equipamentos';
header('Location: ../reports/' . $destino, true, 302);
