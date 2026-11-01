<?php
/**
 * Teste de Consulta SQL: Stock Histórico Otimizado (Solução B com dbo.GET_STOCK)
 * GeoMobilidade Naval · Grupo Naval Angola
 *
 * Executa o SELECT de teste e devolve os dados em JSON ou visualização de diagnóstico.
 * Parâmetros opcionais via GET ou CLI:
 *   ?data=20261024&armazem=1001&format=json
 *
 * Uso, na pasta do site: php ferramentas/teste-stock-sql.php 20261024 todos --json
 * Só SELECT (nucleo/servicos/bd.php).
 */

declare(strict_types=1);

// Só na linha de comandos do servidor (a pasta ferramentas/ não é servida pelo Apache)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../nucleo/arranque.php';
naval_servico('bd');
naval_servico('stock');

$dataRaw = (string) ($argv[1] ?? date('Ymd'));
$armazem = trim((string) ($argv[2] ?? 'todos'));

$inicioTempo = microtime(true);

function normalizarDataSql(string $d): string
{
    $clean = preg_replace('/[^0-9]/', '', $d);
    if (strlen($clean) === 8) {
        return substr($clean, 0, 4) . '-' . substr($clean, 4, 2) . '-' . substr($clean, 6, 2);
    }
    return $d;
}

$dataSql = normalizarDataSql($dataRaw);

$resposta = [
    'ok'            => false,
    'servidor'      => '',
    'base_dados'    => '',
    'data'          => $dataSql,
    'armazem'       => $armazem,
    'tempo_ms'      => 0,
    'total_caixas'  => 0,
    'total_linhas'  => 0,
    'linhas'        => [],
    'erro'          => null
];

try {
    $resposta['servidor'] = naval_config('DB_HOST');
    $resposta['base_dados'] = naval_config('DB_NAME');

    // Exactamente a mesma consulta do portal (nucleo/servicos/stock.php): só colunas que existem nesta base de dados
    [$sql, $params] = naval_stock_consulta($dataSql, $armazem, naval_stock_colunas());

    $linhas = naval_bd_consultar($sql, $params);

    $fimTempo = microtime(true);
    $tempoMs = round(($fimTempo - $inicioTempo) * 1000, 2);

    $totalCaixas = 0.0;
    foreach ($linhas as $r) {
        $totalCaixas += (float) ($r['QUANTIDADE'] ?? 0);
    }

    $resposta['ok']           = true;
    $resposta['tempo_ms']     = $tempoMs;
    $resposta['total_caixas'] = $totalCaixas;
    $resposta['total_linhas'] = count($linhas);
    $resposta['linhas']       = $linhas;

} catch (Throwable $e) {
    $fimTempo = microtime(true);
    $resposta['tempo_ms'] = round(($fimTempo - $inicioTempo) * 1000, 2);
    $resposta['erro'] = $e->getMessage();
}

// ── Resposta (JSON na consola) ──
echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($resposta['ok'] ? 0 : 1);
