<?php
/**
 * API Proxy — Pinguim BO → JSON (formato vendas.json)
 *
 * Recebe:  ?start_date=YYYYMMDD&end_date=YYYYMMDD&include_items=1
 * Devolve: JSON array com o mesmo schema de vendas.json. Com include_items=1,
 * devolve também as linhas de artigos da mesma consulta.
 *
 * Diagnóstico (com sessão iniciada): ?diag=1 mostra a versão do PHP, as extensões,
 * o .env, os limites de tempo e memória e o tempo de resposta do Pinguim BO.
 *
 * Em caso de falha devolve sempre JSON {"erro": "...", "detalhe": "..."} com o
 * código HTTP adequado, em vez de um 500 sem explicação.
 */

// ── Erros: nunca imprimir HTML de erro; guardar no log e responder em JSON ──
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

function responder(int $codigo, array $dados): void
{
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    $json = json_encode($dados, $flags);
    if ($json === false) {
        if (!headers_sent()) http_response_code(500);
        $json = json_encode(['erro' => 'Não foi possível converter os dados para JSON.', 'detalhe' => json_last_error_msg()]);
    }
    echo $json;
    exit;
}

function falhar(int $codigo, string $erro, string $detalhe = ''): void
{
    error_log('[reports/api.php] ' . $erro . ($detalhe !== '' ? ' — ' . $detalhe : ''));
    responder($codigo, ['erro' => $erro, 'detalhe' => $detalhe]);
}

// Erros fatais (função inexistente, tempo ou memória esgotados) passam a JSON legível
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @ini_set('memory_limit', '-1'); // folga para conseguir responder depois de falta de memória
        $msg = $e['message'];
        $erro = 'Erro interno no servidor.';
        if (stripos($msg, 'curl_init') !== false) $erro = 'A extensão cURL do PHP não está activa no servidor (php.ini: extension=curl).';
        elseif (stripos($msg, 'simplexml') !== false) $erro = 'A extensão SimpleXML do PHP não está activa no servidor.';
        elseif (stripos($msg, 'Maximum execution time') !== false) $erro = 'O Pinguim BO demorou demasiado a responder. Experimente um período mais curto.';
        elseif (stripos($msg, 'Allowed memory size') !== false) $erro = 'Memória do PHP insuficiente para este período. Experimente um período mais curto.';
        error_log('[reports/api.php] FATAL ' . $msg . ' em ' . $e['file'] . ':' . $e['line']);
        responder(500, ['erro' => $erro, 'detalhe' => $msg . ' (' . basename($e['file']) . ':' . $e['line'] . ')']);
    }
});

// ── Autenticação (sem sessão responde 401 em JSON) ──
$authFile = __DIR__ . '/../../geo/auth/auth_check.php';
if (!is_file($authFile)) {
    falhar(500, 'Ficheiro de autenticação não encontrado.', 'Esperado em: ' . $authFile);
}
require_once $authFile;

// Consultas ao Pinguim BO podem demorar; no Windows o limite do PHP conta tempo real
@set_time_limit(180);
@ini_set('memory_limit', '512M');

// ── Requisitos do PHP ──
// Sem cURL usa-se o cliente HTTP nativo do PHP (precisa de allow_url_fopen; para https, da extensão openssl)
if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    falhar(500, 'O PHP do servidor não consegue fazer pedidos HTTP.', 'Active "extension=curl" ou "allow_url_fopen=On" no php.ini e reinicie o Apache.');
}
if (!function_exists('simplexml_load_string')) {
    falhar(500, 'A extensão SimpleXML do PHP não está activa no servidor.', 'Active "extension=simplexml" no php.ini e reinicie o Apache.');
}

// ── Credenciais do .env ──
$envFile = __DIR__ . '/../../.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($val), "\"'");
        }
    }
}
$username = $env['USERNAME'] ?? (getenv('PINGUIM_USERNAME') ?: '');
$password = $env['PASSWORD'] ?? (getenv('PINGUIM_PASSWORD') ?: '');
// Endereços da API do Pinguim BO, por ordem de tentativa (separados por vírgula no .env).
// No servidor a API responde localmente na porta 8089; fora do servidor usa-se o acesso externo 2053.
$boUrls = array_values(array_filter(array_map(
    function ($u) { return rtrim(trim($u), '?'); },
    explode(',', $env['PINGUIM_URL'] ?? 'http://127.0.0.1:8089/rest/exportdata,https://bo.naval.co.ao:2053/rest/exportdata')
)));

function chamarPinguim(string $url, string $user, string $pass, int $timeout = 150, int $connect = 15): array
{
    if (!function_exists('curl_init')) return chamarSemCurl($url, $user, $pass, $timeout, $connect);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => $connect,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',
    ]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $res = [
        'body'  => $body,
        'http'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno' => curl_errno($ch),
        'erro'  => curl_error($ch),
        'seg'   => round(microtime(true) - $t0, 2),
        'url'   => $url,
    ];
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    return $res;
}

// Alternativa sem cURL: pedido HTTP com o cliente nativo do PHP (file_get_contents)
function chamarSemCurl(string $url, string $user, string $pass, int $timeout, int $connect): array
{
    $t0 = microtime(true);
    $res = ['body' => false, 'http' => 0, 'errno' => 0, 'erro' => '', 'seg' => 0, 'url' => $url];
    if (stripos($url, 'https://') === 0 && !extension_loaded('openssl')) {
        $res['errno'] = -1;
        $res['erro'] = 'https sem a extensão openssl nem cURL';
        return $res;
    }
    // Teste rápido da ligação, para não esperar o tempo total num endereço que não responde
    $p = parse_url($url);
    $host = $p['host'] ?? '';
    $port = $p['port'] ?? ((($p['scheme'] ?? 'http') === 'https') ? 443 : 80);
    $sock = @fsockopen($host, (int) $port, $errno, $errstr, $connect);
    if (!$sock) {
        $res['errno'] = $errno ?: -1;
        $res['erro'] = 'Sem ligação a ' . $host . ':' . $port . ($errstr ? ' (' . trim($errstr) . ')' : '');
        $res['seg'] = round(microtime(true) - $t0, 2);
        return $res;
    }
    fclose($sock);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => 'Authorization: Basic ' . base64_encode("$user:$pass") . "\r\nConnection: close\r\n",
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $hdrs = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    foreach ($hdrs as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $res['http'] = (int) $m[1];
    }
    if ($body === false && !$hdrs) {
        $e = error_get_last();
        $res['errno'] = -1;
        $res['erro'] = $e ? $e['message'] : 'Pedido sem resposta';
    }
    $res['body'] = $body;
    $res['seg'] = round(microtime(true) - $t0, 2);
    return $res;
}

// Tenta cada endereço até um aceitar a ligação; devolve o resultado do primeiro que responde
function chamarPrimeiro(array $bases, string $query, string $user, string $pass, int $timeout = 150): array
{
    $tentativas = [];
    foreach ($bases as $i => $base) {
        $res = chamarPinguim($base . '?' . $query, $user, $pass, $timeout, $i < count($bases) - 1 ? 5 : 15);
        if ($res['errno'] === 0) { $res['tentativas'] = $tentativas; return $res; }
        $tentativas[] = $base . ': ' . $res['erro'];
    }
    $res['tentativas'] = $tentativas;
    return $res;
}

// ── Diagnóstico ──
if (($_GET['diag'] ?? '') === '1') {
    $hoje = date('Ymd');
    $teste = chamarPrimeiro($boUrls, "start_date=$hoje&end_date=$hoje", $username, $password, 60);
    responder(200, [
        'php'                => PHP_VERSION,
        'curl'               => function_exists('curl_version') ? (curl_version()['version'] ?? true) : false,
        'cliente_http'       => function_exists('curl_init') ? 'cURL' : 'nativo do PHP (sem cURL)',
        'allow_url_fopen'    => ini_get('allow_url_fopen'),
        'openssl'            => extension_loaded('openssl'),
        'php_ini'            => php_ini_loaded_file(),
        'simplexml'          => function_exists('simplexml_load_string'),
        'env_encontrado'     => is_file($envFile),
        'credenciais'        => $username !== '' && $password !== '',
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit'       => ini_get('memory_limit'),
        'pinguim_urls'       => $boUrls,
        'pinguim_usado'      => $teste['errno'] === 0 ? $teste['url'] : null,
        'pinguim_falhas'     => $teste['tentativas'],
        'pinguim_http'       => $teste['http'],
        'pinguim_erro'       => $teste['erro'],
        'pinguim_segundos'   => $teste['seg'],
        'pinguim_bytes'      => is_string($teste['body']) ? strlen($teste['body']) : 0,
    ]);
}

if ($username === '' || $password === '') {
    falhar(500, 'Faltam as credenciais do Pinguim BO.', is_file($envFile) ? 'O .env não tem USERNAME e PASSWORD.' : 'Ficheiro .env não encontrado em: ' . realpath(__DIR__ . '/../..'));
}

// ── Parâmetros ──
$start = $_GET['start_date'] ?? '';
$end   = $_GET['end_date']   ?? '';
$includeItems = ($_GET['include_items'] ?? '') === '1';

if (!preg_match('/^\d{8}$/', $start) || !preg_match('/^\d{8}$/', $end)) {
    falhar(400, 'Datas inválidas.', 'Use start_date e end_date no formato AAAAMMDD.');
}

// ── Chamada à API do Pinguim BO ──
$r = chamarPrimeiro($boUrls, "start_date=$start&end_date=$end", $username, $password);
if ($r['errno'] !== 0) {
    falhar(502, 'Não foi possível contactar o Pinguim BO.', implode(' | ', $r['tentativas']));
}
if ($r['http'] === 401 || $r['http'] === 403) {
    falhar(502, 'O Pinguim BO recusou as credenciais do .env.', 'HTTP ' . $r['http']);
}
if ($r['http'] !== 200 || !$r['body']) {
    falhar(502, 'O Pinguim BO respondeu com erro.', 'HTTP ' . $r['http']);
}

// ── Parse XML ──
libxml_use_internal_errors(true);
$xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE);
if (!$xml) {
    $e = libxml_get_errors();
    falhar(502, 'A resposta do Pinguim BO não é XML válido.', $e ? trim($e[0]->message) : substr($r['body'], 0, 200));
}
unset($r);

// ── Mapa de entidades (entity_code → nome, nif) ──
$entities = [];
if (isset($xml->entities)) {
    foreach ($xml->entities->entity as $ent) {
        $code = (string) $ent->code;
        $entities[$code] = [
            'desc' => (string) $ent->description,
            'tax'  => (string) $ent->tax_number,
        ];
    }
}

// ── Mapa de meios de pagamento ──
$pmMap = [
    'NU' => 'Numerário',
    'MC' => 'Multicaixa',
    'TB' => 'Transferência Bancária',
    'CS' => 'Advance',
];
// Enriquecer com os que vierem no XML
if (isset($xml->payment_methods)) {
    foreach ($xml->payment_methods->payment_method as $pm) {
        $code = (string) $pm->code;
        if (!isset($pmMap[$code])) {
            $pmMap[$code] = (string) $pm->description;
        }
    }
}

// ── Mapa de promotores (rotas.json) ──
$rotasFile = __DIR__ . '/rotas.json';
$rotas = [];
if (is_file($rotasFile)) {
    $rotasData = json_decode((string) file_get_contents($rotasFile), true);
    if (is_array($rotasData)) {
        foreach ($rotasData as $rt) {
            if (isset($rt['rota'], $rt['promotor'])) $rotas[$rt['rota']] = $rt['promotor'];
        }
    }
}

// ── Converter transacções FR/FT → vendas ──
$vendas = [];
$artigos = [];
if (isset($xml->transactions)) {
    foreach ($xml->transactions->transaction as $tx) {
        $docType = (string) $tx->document_type;
        // Apenas documentos de venda (Factura-recibo / Fatura)
        if ($docType !== 'FR' && $docType !== 'FT') continue;
        if ((string) $tx->deleted === '1') continue;

        $storeCode  = (string) $tx->store_code;
        $entityCode = (string) $tx->entity_code;
        $total      = (float) (string) $tx->total;
        $date       = (string) $tx->transaction_date;
        $time       = substr((string) $tx->transaction_time, 0, 5);
        $docNr      = (string) $tx->document_nr;
        $taxNr      = (string) $tx->tax_number;

        // Nome do cliente
        $clientName = isset($entities[$entityCode]) ? $entities[$entityCode]['desc'] : '';
        if (!$clientName) {
            $clientName = ($taxNr === '999999999') ? 'Consumidor Final' : '';
        }

        // Meios de pagamento (footer)
        $meios     = [];
        $valorPago = 0.0;
        if (isset($tx->footer)) {
            foreach ($tx->footer->line as $fl) {
                $pCode = (string) $fl->payment_code;
                $pAmt  = (float) (string) $fl->amount;
                $valorPago += $pAmt;
                if ($pCode !== '') {
                    $meios[] = $pmMap[$pCode] ?? $pCode;
                }
            }
        }

        $promotor = $rotas[$storeCode] ?? $storeCode;

        $vendas[] = [
            'chave'           => $docNr,
            'data'            => $date,
            'hora'            => $time,
            'rota'            => $storeCode,
            'promotor'        => $promotor,
            'tipo'            => $docType === 'FR' ? 'Factura-recibo' : 'Fatura',
            'documento'       => $docNr,
            'cliente'         => $clientName,
            'nif'             => $taxNr ?: ($entities[$entityCode]['tax'] ?? ''),
            'liquidado'       => $valorPago >= $total,
            'total'           => $total,
            'meio_pagamento'  => !empty($meios) ? implode(', ', array_unique($meios)) : null,
        ];

        if ($includeItems && isset($tx->detail)) {
            $linhaN = 0;
            foreach ($tx->detail->line as $line) {
                $linhaN++;
                $artigos[] = [
                    'chave'          => $docNr . '|' . $linhaN,
                    'data'           => $date,
                    'rota'           => $storeCode,
                    'promotor'       => $promotor,
                    'documento'      => $docNr,
                    'codigo'         => (string) $line->product_code,
                    'artigo'         => (string) $line->product_description,
                    'unidade'        => (string) $line->uom,
                    'quantidade'     => (float) (string) $line->quantity,
                    'preco_unitario' => (float) (string) $line->base_amount,
                    'valor_sem_iva'  => (float) (string) $line->total,
                    'desconto'       => (float) (string) $line->discount_amount,
                    'iva'            => (float) (string) $line->tax_amount,
                    'total'          => (float) (string) $line->total + (float) (string) $line->tax_amount,
                ];
            }
        }
    }
}

responder(200, $includeItems ? ['vendas' => $vendas, 'artigos' => $artigos] : $vendas);
