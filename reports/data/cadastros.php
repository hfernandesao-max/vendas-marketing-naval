<?php
/**
 * Cadastros de clientes RTM → JSON para o portal (páginas Cadastros, Carteira e Plano de visitas).
 *
 * Os dados são gerados pelo repositório privado rtm-clientes (GitHub Actions, a partir das
 * exportações «Clientes» do backoffice do RTM) no ficheiro saida/portal_rtm.json.
 *
 * Onde procura o ficheiro, por esta ordem:
 *   1. RTM_CLIENTES_JSON no .env (caminho completo);
 *   2. reports/data/cadastros.json (cópia manual);
 *   3. ../rtm-clientes/saida/portal_rtm.json ao lado da pasta do site (clone do repositório rtm-clientes).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$authFile = __DIR__ . '/../../geo/auth/auth_check.php';
if (is_file($authFile)) {
    require_once $authFile;
}

$env = [];
$envFile = __DIR__ . '/../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

$candidatos = array_values(array_filter([
    $env['RTM_CLIENTES_JSON'] ?? '',
    __DIR__ . '/cadastros.json',
    __DIR__ . '/../../../rtm-clientes/saida/portal_rtm.json',
]));
foreach ($candidatos as $f) {
    if (is_file($f)) {
        readfile($f);
        exit;
    }
}

http_response_code(404);
echo json_encode([
    'erro' => 'Dados de cadastros não encontrados.',
    'detalhe' => 'Configure RTM_CLIENTES_JSON no .env ou clone o repositório rtm-clientes ao lado da pasta do site.',
], JSON_UNESCAPED_UNICODE);
