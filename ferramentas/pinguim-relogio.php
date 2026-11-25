<?php
/**
 * Relógio dos telemóveis: que horas o Pinguim BO guarda de cada documento (no servidor, na pasta do portal).
 * Só SELECT — não escreve nada no Pinguim BO.
 *
 *     php ferramentas/pinguim-relogio.php --doc=FR0126S77782N/26
 *     php ferramentas/pinguim-relogio.php --doc=FR0126S77782N/26 --dia=2026-11-25
 *
 * Mostra:
 *   1. todas as colunas de data e hora da tabela DOCHEADER (nome e tipo);
 *   2. os valores dessas colunas no documento indicado (CLOCK_DATE é a hora do telemóvel; procura-se uma hora do
 *      servidor — criação, recepção ou sincronização — para comparar com ela);
 *   3. as colunas de data e hora das tabelas de sincronização (SYNC*) e as sincronizações da loja do documento nesse dia.
 * Serve para o portal avisar quando o relógio de um telemóvel está errado (hora das facturas ≠ hora real).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

$o = getopt('', ['doc:', 'dia::']);
$doc = trim((string) ($o['doc'] ?? ''));
if (!preg_match('#^([A-Za-z0-9]+)/(\d{1,9})$#', $doc, $m)) {
    fwrite(STDERR, "Indique o documento: --doc=FR0126S77782N/26\n");
    exit(1);
}
[, $prefixo, $numero] = $m;

$colunas = function (string $tabela): array {
    return naval_bd_consultar("
        SELECT COLUMN_NAME AS C, DATA_TYPE AS T FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_NAME = :t AND DATA_TYPE IN ('datetime', 'datetime2', 'smalldatetime', 'datetimeoffset', 'date', 'time')
        ORDER BY ORDINAL_POSITION", [':t' => $tabela]);
};

echo "Pinguim BO: horas guardadas do documento $doc (só leitura)\n\n";
$cols = $colunas('DOCHEADER');
echo "1. Colunas de data e hora em DOCHEADER\n";
foreach ($cols as $c) echo '   ' . str_pad($c['C'], 32) . $c['T'] . "\n";

echo "\n2. Valores no documento\n";
$sel = implode(', ', array_map(function ($c) { return 'CONVERT(VARCHAR(23), DH.[' . str_replace(']', '', $c['C']) . '], 121) AS [' . str_replace(']', '', $c['C']) . ']'; }, $cols));
$linhas = naval_bd_consultar("
    SELECT TOP 5 DH.ID, S.CODE AS LOJA, S.ID AS LOJA_ID, $sel
    FROM DOCHEADER DH
    LEFT JOIN STORE S ON S.ID = DH.STORE_ID
    WHERE LTRIM(RTRIM(DH.DOC_PREFIX)) = :p AND DH.DOC_NUMBER = :n", [':p' => $prefixo, ':n' => (int) $numero]);
if (!$linhas) {
    echo "   documento não encontrado (prefixo $prefixo, número $numero)\n";
    exit(0);
}
foreach ($linhas as $l) {
    foreach ($l as $k => $v) echo '   ' . str_pad((string) $k, 32) . ($v === null ? '—' : (string) $v) . "\n";
    echo "\n";
}
$loja = (int) $linhas[0]['LOJA_ID'];
$dia = (string) ($o['dia'] ?? substr((string) ($linhas[0]['CLOCK_DATE'] ?? $linhas[0]['DOC_DATE'] ?? date('Y-m-d')), 0, 10));

echo "3. Sincronizações da loja " . $linhas[0]['LOJA'] . " em $dia\n";
$tabs = naval_bd_consultar("SELECT TABLE_NAME AS N FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE 'SYNC%' ORDER BY TABLE_NAME");
foreach ($tabs as $t) {
    $n = (string) $t['N'];
    $cs = $colunas($n);
    $temLoja = naval_bd_consultar("SELECT COUNT(*) AS X FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t AND COLUMN_NAME = 'STORE_ID'", [':t' => $n]);
    echo '   ' . $n . ': ' . ($cs ? implode(', ', array_column($cs, 'C')) : 'sem colunas de data') . "\n";
    if (!$cs || empty($temLoja[0]['X'])) continue;
    $c0 = str_replace(']', '', $cs[0]['C']);
    $sel = implode(', ', array_map(function ($c) { $x = str_replace(']', '', $c['C']); return 'CONVERT(VARCHAR(19), [' . $x . '], 120) AS [' . $x . ']'; }, $cs));
    try {
        $rows = naval_bd_consultar("SELECT TOP 30 $sel FROM [$n] WHERE STORE_ID = :l AND [$c0] >= :d AND [$c0] < DATEADD(day, 1, CAST(:d AS DATE)) ORDER BY [$c0]",
            [':l' => $loja, ':d' => $dia]);
    } catch (Throwable $e) {
        echo '      (não foi possível ler: ' . $e->getMessage() . ")\n";
        continue;
    }
    foreach ($rows as $r) echo '      ' . implode('  ', array_map(function ($v) { return $v === null ? '—' : (string) $v; }, $r)) . "\n";
}
echo "\nCompare a CLOCK_DATE (hora do telemóvel) com as horas do servidor e com a sincronização que trouxe o documento.\n";
