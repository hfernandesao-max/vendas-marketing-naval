<?php
// API do GitHub simulada para os testes do actualizador (php -S 127.0.0.1:<porta> github-simulado.php).
// GITHUB_SIMULADO_DIR: pasta com versoes.json ([{tag, notas}], da mais antiga para a mais recente) ou versao.txt (uma
// versão), e os pacotes (portal-rtm-<versão>.zip e .sha256).
// Como no GitHub: a API exige o token; o ficheiro é entregue por um endereço temporário (302) que recusa o token.
$dir = (string) getenv('GITHUB_SIMULADO_DIR');
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

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
// GitHub App: JWT RS256 assinado com a chave da App (verificado com app-publica.pem) → credencial de instalação
$credencial = substr($auth, 7);
if (substr_count($credencial, '.') === 2 && is_file($dir . '/app-publica.pem')) {
    [$h, $p, $sig] = explode('.', $credencial);
    $dec = function ($x) { return (string) base64_decode(strtr($x, '-_', '+/') . str_repeat('=', (4 - strlen($x) % 4) % 4)); };
    $dados = json_decode($dec($p), true) ?: [];
    $valido = openssl_verify($h . '.' . $p, $dec($sig), (string) file_get_contents($dir . '/app-publica.pem'), OPENSSL_ALGO_SHA256) === 1
        && ($dados['iss'] ?? '') === '12345' && ($dados['exp'] ?? 0) > time() && ($dados['exp'] ?? 0) - ($dados['iat'] ?? 0) <= 660;
    header('Content-Type: application/json');
    if (!$valido) {
        http_response_code(401);
        echo '{"message":"A JSON web token could not be decoded"}';
        return;
    }
    if (preg_match('#^/repos/[\w.-]+/[\w.-]+/installation$#', $uri)) {
        echo '{"id":77}';
        return;
    }
    if ($uri === '/app/installations/77/access_tokens' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        http_response_code(201);
        echo json_encode(['token' => 'instalacao-teste', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
        return;
    }
    http_response_code(404);
    return;
}
if (!in_array($auth, ['Bearer github_pat_teste_0123456789abcdefghij', 'Bearer instalacao-teste'], true)) {
    http_response_code(401);
    echo '{"message":"Bad credentials"}';
    return;
}
if ($auth === 'Bearer github_pat_teste_0123456789abcdefghij') header('github-authentication-token-expiration: ' . gmdate('Y-m-d H:i:s', time() + 10 * 86400) . ' UTC');
$lista = is_file($dir . '/versoes.json') ? (json_decode((string) file_get_contents($dir . '/versoes.json'), true) ?: [])
    : [['tag' => trim((string) @file_get_contents($dir . '/versao.txt')), 'notas' => "Alterações:\n- teste"]];
$release = function (array $v, int $i) use ($base) {
    $zip = 'portal-rtm-' . $v['tag'] . '.zip';
    return ['tag_name' => $v['tag'], 'target_commitish' => 'abcdef1234567', 'published_at' => gmdate('Y-m-d\TH:i:s\Z', 1790000000 + $i * 3600),
        'body' => $v['notas'] ?? '', 'draft' => false, 'prerelease' => false, 'assets' => [
            ['name' => $zip, 'url' => $base . '/repos/o/r/releases/assets/' . $v['tag'] . '~zip'],
            ['name' => $zip . '.sha256', 'url' => $base . '/repos/o/r/releases/assets/' . $v['tag'] . '~sha'],
        ]];
};
header('Content-Type: application/json');
if (preg_match('#^/repos/[\w.-]+/[\w.-]+/releases/latest$#', $uri)) {
    echo json_encode($release(end($lista), count($lista) - 1));
    return;
}
if (preg_match('#^/repos/[\w.-]+/[\w.-]+/releases$#', $uri)) {
    $out = [];
    foreach ($lista as $i => $v) $out[] = $release($v, $i);
    echo json_encode(array_reverse($out));
    return;
}
if (preg_match('#^/repos/[\w.-]+/[\w.-]+/releases/assets/([\w.\-]+)~(zip|sha)$#', $uri, $m)) {
    if (($_SERVER['HTTP_ACCEPT'] ?? '') !== 'application/octet-stream') {
        http_response_code(415);
        return;
    }
    $zip = 'portal-rtm-' . $m[1] . '.zip';
    header('Location: ' . $base . '/descarga/' . ($m[2] === 'zip' ? $zip : $zip . '.sha256'), true, 302);
    return;
}
http_response_code(404);
