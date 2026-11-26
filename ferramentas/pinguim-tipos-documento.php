<?php
/**
 * Tipos de documento do Pinguim BO e o que cada um faz ao stock (no servidor, na pasta do portal).
 * Só SELECT — não escreve nada no Pinguim BO.
 *
 *     php ferramentas/pinguim-tipos-documento.php
 *     php ferramentas/pinguim-tipos-documento.php --dias=30
 *
 * Mostra, para confirmar a natureza de cada documento no extracto de movimentos e na rastreabilidade:
 *   1. cada tipo com movimento de stock no período: descrição, sinal de stock, se conta como venda, quantos documentos
 *      e quanto entrou e saiu nos armazéns centrais e nas carrinhas — e a natureza que o portal lhe dá;
 *   2. as colunas de DOCHEADER e DOCMOVDETAIL que podem guardar o documento de origem (SA → GTC gerada a partir dela);
 *   3. as últimas saídas de armazém (SA), dos armazéns centrais e das carrinhas, e a guia que o portal lhes liga (a
 *      gravada ao mesmo tempo, com a mesma quantidade) — ou «venda SAP» quando não há guia.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');
naval_servico('stock');
naval_servico('movimentos');

$o = getopt('', ['dias::']);
$dias = max(1, min(366, (int) ($o['dias'] ?? 90)));
$de = date('Y-m-d', time() - ($dias - 1) * 86400);
$ate = date('Y-m-d');
$tipos = naval_mov_tipos();
$conf = naval_stock_carrinhas_confirmadas();
$carrinha = function (string $cod, string $nome) use ($conf) { return naval_stock_rota_armazem($cod, $nome, $conf)[0] !== ''; };
$n = function ($v) { return str_pad(number_format((float) $v, 0, ',', ' '), 11, ' ', STR_PAD_LEFT); };

echo "Pinguim BO: tipos de documento com movimento de stock de $de a $ate (só leitura)\n";
echo 'Tipos no portal: vendas ' . implode(', ', $tipos['venda']) . ' · devoluções ' . implode(', ', $tipos['devolucao']) . ' · guias ' . implode(', ', $tipos['guia'])
    . ' · saídas de armazém ' . implode(', ', $tipos['saida_armazem']) . " (.env MOV_TIPOS_*)\n\n";

// 1. tipos de documento
$linhas = naval_bd_consultar("
    SELECT LTRIM(RTRIM(DC.CODE)) AS TIPO, MAX(DC.DESCRIPTION) AS NOME, MAX(DC.STOCK_SIGNAL) AS SINAL, MAX(COALESCE(DH.CALC_SALES_SIGNAL, 0)) AS VENDA,
           LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM, COUNT(DISTINCT DH.ID) AS DOCS,
           CAST(SUM(CASE WHEN DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL) > 0 THEN DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL) ELSE 0 END) AS FLOAT) AS ENT,
           CAST(SUM(CASE WHEN DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL) < 0 THEN -DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL) ELSE 0 END) AS FLOAT) AS SAI
    FROM DOCMOVDETAIL DMD
    INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
    LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
    WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
      AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))
    GROUP BY LTRIM(RTRIM(DC.CODE)), WE.CODE", [':de' => $de, ':ate' => $ate]);
$porTipo = [];
foreach ($linhas as $l) {
    $t = $l['TIPO'];
    $lado = $carrinha((string) $l['ARM_COD'], (string) $l['ARM']) ? 'car' : 'cen';
    $porTipo[$t] = $porTipo[$t] ?? ['nome' => trim((string) $l['NOME']), 'sinal' => $l['SINAL'], 'venda' => (int) $l['VENDA'], 'docs' => 0, 'cen' => [0, 0], 'car' => [0, 0]];
    $porTipo[$t]['docs'] += (int) $l['DOCS'];
    $porTipo[$t]['venda'] = max($porTipo[$t]['venda'], (int) $l['VENDA']);
    $porTipo[$t][$lado][0] += (float) $l['ENT'];
    $porTipo[$t][$lado][1] += (float) $l['SAI'];
}
ksort($porTipo);
$natureza = function (string $t) use ($tipos) {
    if (in_array($t, $tipos['venda'], true)) return 'venda (saída efectiva)';
    if (in_array($t, $tipos['devolucao'], true)) return 'devolução (abate às vendas)';
    if (in_array($t, $tipos['guia'], true)) return 'guia (transferência recebida)';
    if (in_array($t, $tipos['saida_armazem'], true)) return 'SA: transferência com guia, senão venda SAP';
    if (in_array($t, $tipos['devolucao_armazem'], true)) return 'DA: devolução da carrinha (com EA)';
    if (in_array($t, $tipos['entrada_armazem'], true)) return 'EA: devolução da carrinha com DA, senão compra';
    return 'entrada externa / outra saída';
};
echo "1. Tipos de documento\n";
echo '   ' . str_pad('Tipo', 8) . str_pad('Descrição', 34) . 'Sinal Venda  Docs' . '   Centrais: entrou     saiu' . '  Carrinhas: entrou     saiu   Natureza no portal' . "\n";
foreach ($porTipo as $t => $x) {
    $nome = mb_substr($x['nome'], 0, 32);
    echo '   ' . str_pad($t, 8) . $nome . str_repeat(' ', 34 - mb_strlen($nome))
        . str_pad((string) $x['sinal'], 6) . str_pad($x['venda'] ? 'sim' : '—', 6) . str_pad((string) $x['docs'], 6, ' ', STR_PAD_LEFT)
        . '  ' . $n($x['cen'][0]) . $n($x['cen'][1]) . '    ' . $n($x['car'][0]) . $n($x['car'][1]) . '   ' . $natureza($t) . "\n";
}
$semVenda = array_filter($tipos['venda'], function ($t) use ($porTipo) { return !isset($porTipo[$t]); });
if ($semVenda) echo "\n   Atenção: sem movimento no período para " . implode(', ', $semVenda) . " (confirme o código nas vendas).\n";
$vendaFora = array_filter(array_keys($porTipo), function ($t) use ($porTipo, $tipos) { return $porTipo[$t]['venda'] && !in_array($t, array_merge($tipos['venda'], $tipos['devolucao']), true); });
if ($vendaFora) echo '   Atenção: contam como venda no Pinguim BO mas não estão em MOV_TIPOS_VENDA/MOV_TIPOS_DEVOLUCAO: ' . implode(', ', $vendaFora) . "\n";

// 2. colunas que podem guardar o documento de origem
echo "\n2. Colunas com o documento de origem (SA → guia gerada a partir dela)\n";
foreach (naval_bd_consultar("
    SELECT TABLE_NAME AS T, COLUMN_NAME AS C, DATA_TYPE AS D FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME IN ('DOCHEADER', 'DOCMOVDETAIL', 'DOCDETAIL')
      AND (COLUMN_NAME LIKE '%ORIG%' OR COLUMN_NAME LIKE '%PARENT%' OR COLUMN_NAME LIKE '%SOURCE%' OR COLUMN_NAME LIKE '%RELAT%'
           OR COLUMN_NAME LIKE '%REF%' OR COLUMN_NAME LIKE '%LINK%' OR COLUMN_NAME LIKE '%FROM%' OR COLUMN_NAME LIKE '%BASE%')
    ORDER BY TABLE_NAME, ORDINAL_POSITION") as $c) echo '   ' . str_pad($c['T'] . '.' . $c['C'], 44) . $c['D'] . "\n";
foreach (naval_bd_consultar("
    SELECT TABLE_NAME AS T FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_NAME LIKE '%DOC%' AND (TABLE_NAME LIKE '%RELAT%' OR TABLE_NAME LIKE '%LINK%' OR TABLE_NAME LIKE '%ORIG%' OR TABLE_NAME LIKE '%CONVER%')
    ORDER BY TABLE_NAME") as $t) echo '   tabela ' . $t['T'] . "\n";

// 3. últimas SA dos armazéns centrais e a guia ligada pelo portal
echo "\n3. Últimas saídas de armazém e a guia que o portal lhes liga\n";
if (!$tipos['saida_armazem'] || !$tipos['guia']) {
    echo "   (sem tipos de saída de armazém ou de guia no .env)\n";
    exit(0);
}
$em = function (array $l) { return "('" . implode("', '", $l) . "')"; };
$sa = naval_bd_consultar("
    SELECT TOP 400 CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
           LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC, LTRIM(RTRIM(DC.CODE)) AS TIPO,
           LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
           CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
    FROM DOCMOVDETAIL DMD
    INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
    LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
    WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND LTRIM(RTRIM(DC.CODE)) IN " . $em($tipos['saida_armazem']) . "
      AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= DATEADD(day, -14, CAST(:ate AS DATE))
    GROUP BY COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, WE.CODE, DMD.PRODUCT_CODE
    HAVING SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) < 0
    ORDER BY HORA DESC", [':ate' => $ate]);
$deSa = $sa ? substr((string) end($sa)['HORA'], 0, 10) : $ate;
$pares = naval_mov_pares('', [':de' => $deSa, ':ate' => $ate], $tipos);
$c = naval_mov_classificar($sa, $pares, $tipos);
$mostradas = 0;
foreach ($c as $l) {
    if (++$mostradas > 60) break;
    $lado = $carrinha((string) $l['ARM_COD'], (string) $l['ARM']) ? 'carrinha' : 'central';
    echo '   ' . $l['HORA'] . '  ' . str_pad($l['DOC'], 18) . str_pad($l['ARM_COD'] . ' ' . $lado, 20) . str_pad($l['CODIGO'], 12) . $n(-$l['EFEITO']) . '   '
        . ($l['NATUREZA'] === 'transf_enviada' ? 'transferência → ' . implode(', ', $l['PAR'] ?? []) : 'VENDA SAP (sem entrada noutro armazém)') . "\n";
}
if (!$mostradas) echo "   sem saídas de armazém nos últimos 14 dias\n";
echo "\nA SA e a guia (GTC) de uma transferência são gravadas ao mesmo tempo e com a mesma quantidade; a SA sem guia é uma venda SAP.\n";
