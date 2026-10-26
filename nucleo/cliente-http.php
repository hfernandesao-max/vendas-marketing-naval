<?php
/**
 * Cliente HTTP para os serviços externos (Pinguim BO, Microsoft 365).
 * Usa cURL quando existe; sem cURL, o cliente nativo do PHP (precisa de allow_url_fopen e,
 * para https, da extensão openssl).
 */
defined('NAVAL_NUCLEO') || exit;

/**
 * Pedido HTTP. Opções: cabecalhos (lista), corpo, utilizador e senha (autenticação básica),
 * tempo (s, total), ligacao (s, até ligar), verificar_ssl (bool, por omissão true), sem_curl (testes).
 * Devolve: corpo (string|false), codigo (HTTP), errno (0 = houve resposta), erro, seg, url.
 */
function naval_http_pedido(string $metodo, string $url, array $o = []): array
{
    $o += ['cabecalhos' => [], 'corpo' => null, 'utilizador' => null, 'senha' => '', 'tempo' => 30, 'ligacao' => 10, 'verificar_ssl' => true, 'sem_curl' => false];
    $t0 = microtime(true);
    if (function_exists('curl_init') && !$o['sem_curl']) {
        $ch = curl_init($url);
        $op = [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $o['cabecalhos'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $o['tempo'], CURLOPT_CONNECTTIMEOUT => $o['ligacao'], CURLOPT_ENCODING => '',
        ];
        if ($o['corpo'] !== null) $op[CURLOPT_POSTFIELDS] = $o['corpo'];
        if ($o['utilizador'] !== null) $op[CURLOPT_USERPWD] = $o['utilizador'] . ':' . $o['senha'];
        if (!$o['verificar_ssl']) { $op[CURLOPT_SSL_VERIFYPEER] = false; $op[CURLOPT_SSL_VERIFYHOST] = 0; }
        curl_setopt_array($ch, $op);
        $corpo = curl_exec($ch);
        $r = ['corpo' => is_string($corpo) ? $corpo : false, 'codigo' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch),
            'erro' => curl_error($ch), 'seg' => round(microtime(true) - $t0, 2), 'url' => $url];
        if (PHP_VERSION_ID < 80000) curl_close($ch);
        return $r;
    }

    $r = ['corpo' => false, 'codigo' => 0, 'errno' => 0, 'erro' => '', 'seg' => 0, 'url' => $url];
    if (stripos($url, 'https://') === 0 && !extension_loaded('openssl')) {
        $r['errno'] = -1;
        $r['erro'] = 'https sem a extensão openssl nem cURL';
        return $r;
    }
    // Teste rápido da ligação, para não esperar o tempo total num endereço que não responde
    $p = parse_url($url);
    $host = (string) ($p['host'] ?? '');
    $porta = (int) ($p['port'] ?? ((($p['scheme'] ?? 'http') === 'https') ? 443 : 80));
    $sock = @fsockopen($host, $porta, $errno, $errstr, (float) $o['ligacao']);
    if (!$sock) {
        $r['errno'] = $errno ?: -1;
        $r['erro'] = 'Sem ligação a ' . $host . ':' . $porta . ($errstr ? ' (' . trim($errstr) . ')' : '');
        $r['seg'] = round(microtime(true) - $t0, 2);
        return $r;
    }
    fclose($sock);
    $cab = $o['cabecalhos'];
    if ($o['utilizador'] !== null) $cab[] = 'Authorization: Basic ' . base64_encode($o['utilizador'] . ':' . $o['senha']);
    $cab[] = 'Connection: close';
    $http = ['method' => $metodo, 'header' => implode("\r\n", $cab), 'timeout' => $o['tempo'], 'ignore_errors' => true];
    if ($o['corpo'] !== null) $http['content'] = $o['corpo'];
    $ctx = stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => (bool) $o['verificar_ssl'], 'verify_peer_name' => (bool) $o['verificar_ssl']]]);
    $corpo = @file_get_contents($url, false, $ctx);
    // Cabeçalhos da resposta: função do PHP 8.4+; antes, a variável $http_response_header
    $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : ($http_response_header ?? []); // @phpstan-ignore nullCoalesce.variable
    foreach ($hdrs as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $r['codigo'] = (int) $m[1];
    }
    if ($corpo === false && !$hdrs) {
        $e = error_get_last();
        $r['errno'] = -1;
        $r['erro'] = ($e ? $e['message'] : 'Pedido sem resposta') . (extension_loaded('openssl') ? '' : ' (a extensão openssl do PHP não está activa)');
    }
    $r['corpo'] = $corpo;
    $r['seg'] = round(microtime(true) - $t0, 2);
    return $r;
}
