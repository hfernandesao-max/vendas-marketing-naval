<?php
/**
 * Regista as exportações para Excel e confirma que o perfil as pode fazer.
 * O portal chama este serviço antes de gerar cada ficheiro; sem «ok» não exporta.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../geo/auth/auth_check.php';

$p = json_decode((string) file_get_contents('php://input'), true) ?: [];
$pagina = preg_replace('/[^a-z0-9-]/', '', (string) ($p['pagina'] ?? ''));
$completo = !empty($p['completo']);
$recurso = $completo ? 'excel-completo' : $pagina;
if ($recurso === '' || !naval_pode_exportar($recurso)) {
    naval_registar('exportacao_recusada', ['pagina' => $pagina, 'completo' => $completo]);
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'O seu perfil não permite exportar esta informação.'], JSON_UNESCAPED_UNICODE);
    exit;
}
naval_registar('exportacao', [
    'pagina' => $pagina, 'completo' => $completo,
    'tabela' => preg_replace('/^(.{0,120}).*$/us', '$1', (string) ($p['tabela'] ?? '')),
    'periodo' => preg_replace('/^(.{0,40}).*$/us', '$1', (string) ($p['periodo'] ?? '')),
    'linhas' => (int) ($p['linhas'] ?? 0),
]);
echo json_encode(['ok' => true]);
