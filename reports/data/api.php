<?php
/**
 * API Proxy — Pinguim BO → JSON (formato vendas.json)
 *
 * Recebe:  ?start_date=YYYYMMDD&end_date=YYYYMMDD
 * Devolve: JSON array com o mesmo schema de vendas.json
 */
header('Content-Type: application/json; charset=utf-8');

// ── Credenciais do .env ──
$envFile = __DIR__ . '/../../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $env[trim($key)] = trim($val);
        }
    }
}

$username = $env['USERNAME'] ?? '';
$password = $env['PASSWORD'] ?? '';

// ── Parâmetros ──
$start = $_GET['start_date'] ?? '';
$end   = $_GET['end_date']   ?? '';

if (!preg_match('/^\d{8}$/', $start) || !preg_match('/^\d{8}$/', $end)) {
    echo json_encode([]);
    exit;
}

// ── Chamada à API do Pinguim BO ──
$url = "https://bo.naval.co.ao:2083/rest/exportdata?start_date=$start&end_date=$end";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => "$username:$password",
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_TIMEOUT        => 60,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode([]);
    exit;
}

// ── Parse XML ──
$xml = @simplexml_load_string($response);
if (!$xml) {
    echo json_encode([]);
    exit;
}

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
if (file_exists($rotasFile)) {
    $rotasData = json_decode(file_get_contents($rotasFile), true);
    if (is_array($rotasData)) {
        foreach ($rotasData as $r) {
            $rotas[$r['rota']] = $r['promotor'];
        }
    }
}

// ── Converter transacções FR/FT → vendas ──
$vendas = [];
if (isset($xml->transactions)) {
    foreach ($xml->transactions->transaction as $tx) {
        $docType = (string) $tx->document_type;
        // Apenas documentos de venda (Factura-recibo / Fatura)
        if ($docType !== 'FR' && $docType !== 'FT') continue;
        if ((string) $tx->deleted === '1') continue;

        $storeCode  = (string) $tx->store_code;
        $entityCode = (string) $tx->entity_code;
        $total      = floatval((string) $tx->total);
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
        $valorPago = 0;
        if (isset($tx->footer)) {
            foreach ($tx->footer->line as $fl) {
                $pCode = (string) $fl->payment_code;
                $pAmt  = floatval((string) $fl->amount);
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
            'meio_pagamento'  => !empty($meios) ? implode(', ', $meios) : null,
        ];
    }
}

echo json_encode($vendas, JSON_UNESCAPED_UNICODE);
