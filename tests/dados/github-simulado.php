<?php
// API do GitHub simulada para os testes do actualizador (php -S 127.0.0.1:<porta> github-simulado.php).
// GITHUB_SIMULADO_DIR: pasta com versao.txt e o pacote (portal-rtm-<versão>.zip e .sha256).
// Como no GitHub: a API exige o token; o ficheiro é entregue por um endereço temporário (302) que recusa o token.
$dir = (string) getenv('GITHUB_SIMULADO_DIR');
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$versao = trim((string) @file_get_contents($dir . '/versao.txt'));
$zip = 'portal-rtm-' . $versao . '.zip';

if (strpos($uri, '/descarga/') === 0) {
    if ($auth !== '') {
        http_response_code(400);   // o armazenamento do GitHub recusa pedidos com dois métodos de autenticação
        echo 'token enviado para o endereço temporário';
        return;
    }
    $f = $dir . '/' . basename($uri);
    if (!is_file($f)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: application/octet-stream');
    readfile($f);
    return;
}
if ($auth !== 'Bearer github_pat_teste_0123456789abcdefghij') {
    http_response_code(401);
    echo '{"message":"Bad credentials"}';
    return;
}
header('github-authentication-token-expiration: ' . gmdate('Y-m-d H:i:s', time() + 10 * 86400) . ' UTC');
if (preg_match('#^/repos/[\w.-]+/[\w.-]+/releases/latest$#', $uri)) {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => $versao, 'target_commitish' => 'abcdef1234567', 'published_at' => gmdate('c'), 'assets' => [
        ['name' => $zip, 'url' => $base . '/repos/o/r/releases/assets/1'],
        ['name' => $zip . '.sha256', 'url' => $base . '/repos/o/r/releases/assets/2'],
    ]]);
    return;
}
if (preg_match('#^/repos/[\w.-]+/[\w.-]+/releases/assets/([12])$#', $uri, $m)) {
    if (($_SERVER['HTTP_ACCEPT'] ?? '') !== 'application/octet-stream') {
        http_response_code(415);
        return;
    }
    header('Location: ' . $base . '/descarga/' . ($m[1] === '1' ? $zip : $zip . '.sha256'), true, 302);
    return;
}
http_response_code(404);
