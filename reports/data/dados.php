<?php
/**
 * Ficheiros de dados fixos do portal (rotas, plano de cargas e carteira antiga), servidos com
 * as permissões do utilizador: só as suas rotas e NIF só para quem o pode ver.
 * Os ficheiros .json desta pasta não são acessíveis directamente (ver .htaccess).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';

$f = $_GET['f'] ?? '';
$regras = [
    'rotas'    => ['pagina-principal'],
    'cargas'   => ['stock-carrinha', 'stock-armazem', 'inicio'],
    'clientes' => ['clientes', 'plano-visitas', 'auditoria'],
];
if (!isset($regras[$f])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Ficheiro desconhecido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
naval_exigir($regras[$f]);
$d = json_decode((string) @file_get_contents(__DIR__ . '/' . $f . '.json'), true);
if (!is_array($d)) $d = [];
if ($f !== 'rotas') {
    $d = array_values(array_filter($d, function ($x) { return naval_rota_permitida($x['rota'] ?? ''); }));
}
if ($f === 'clientes' && !naval_ve_nif()) {
    foreach ($d as $i => $x) $d[$i]['nif'] = '';
}
echo json_encode($d, JSON_UNESCAPED_UNICODE);
