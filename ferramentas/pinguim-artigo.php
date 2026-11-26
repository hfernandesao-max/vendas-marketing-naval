<?php
/**
 * Prova do stock de um artigo no Pinguim BO (no servidor, na pasta do portal). Só SELECT — não escreve nada.
 *
 *     php ferramentas/pinguim-artigo.php --artigo=10000332
 *     php ferramentas/pinguim-artigo.php --artigo=10000332 --de=2026-10-01 > C:\Temp\artigo.txt
 *
 * Mostra:
 *   1. o stock de hoje em cada armazém calculado pelo próprio Pinguim BO (dbo.GET_STOCK) ao lado da soma de todos os
 *      movimentos do artigo (documentos + acertos de inventário): se as duas colunas forem iguais, o extracto está completo;
 *   2. todas as linhas do artigo desde --de com o ID da linha, PARENT_ID e LINKTYPE (a ligação do Pinguim BO entre a
 *      linha e a linha de onde foi gerada, p. ex. GTC → SA, EA → DA) e o documento dessa linha de origem;
 *   3. as saídas (SA, DA) que nenhuma linha usa como origem e as entradas sem origem;
 *   4. o cabeçalho completo (todas as colunas com valor) dos documentos de saída sem destino, para saber o que foram.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

$o = getopt('', ['artigo:', 'de::']);
$art = trim((string) ($o['artigo'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $art)) {
    fwrite(STDERR, "Uso: php ferramentas/pinguim-artigo.php --artigo=10000332 [--de=2026-10-01]\n");
    exit(1);
}
$de = (string) ($o['de'] ?? date('Y-m-d', time() - 90 * 86400));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-d', time() - 90 * 86400);
$n = function ($v, int $w = 9) { return str_pad(number_format((float) $v, 0, ',', ' '), $w, ' ', STR_PAD_LEFT); };
$c = function ($v, int $w) { $v = (string) $v; $v = mb_substr($v, 0, $w - 1); return $v . str_repeat(' ', max(1, $w - mb_strlen($v))); };

echo "Pinguim BO: prova do stock do artigo $art (só leitura) · gerado " . date('Y-m-d H:i') . "\n\n";

// 1. stock do Pinguim BO e soma de todos os movimentos, por armazém
$mov = [];
foreach (naval_bd_consultar("
    SELECT LTRIM(RTRIM(WE.CODE)) AS COD, MAX(WE.DESCRIPTION) AS ARM, CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS Q
    FROM DOCMOVDETAIL DMD
    INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
    INNER JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
    WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
      AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art
    GROUP BY WE.CODE", [':art' => $art]) as $r) $mov[$r['COD']] = ['arm' => $r['ARM'], 'doc' => (float) $r['Q'], 'inv' => 0.0, 'stk' => null];
foreach (naval_bd_consultar("
    SELECT LTRIM(RTRIM(E.CODE)) AS COD, MAX(E.DESCRIPTION) AS ARM, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS Q
    FROM INVENTORYHEADER IH
    INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
    INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
    INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
    WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND LTRIM(RTRIM(P.CODE)) = :art
    GROUP BY E.CODE", [':art' => $art]) as $r) {
    $mov[$r['COD']] = $mov[$r['COD']] ?? ['arm' => $r['ARM'], 'doc' => 0.0, 'inv' => 0.0, 'stk' => null];
    $mov[$r['COD']]['inv'] = (float) $r['Q'];
}
foreach (naval_bd_consultar("
    SELECT LTRIM(RTRIM(E.CODE)) AS COD, MAX(E.DESCRIPTION) AS ARM, CAST(MAX(STK.QNT) AS FLOAT) AS Q
    FROM (SELECT DISTINCT DMD.WAREHOUSE_ID AS WID FROM DOCMOVDETAIL DMD WHERE DMD.DELETED = 0 AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art1
          UNION SELECT IH.WAREHOUSE_ID FROM INVENTORYHEADER IH INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID
                INNER JOIN PRODUCT PP ON PP.ID = ID.PRODUCT_ID WHERE IH.DELETED = 0 AND LTRIM(RTRIM(PP.CODE)) = :art2) W
    INNER JOIN ENTITY E ON E.ID = W.WID
    CROSS JOIN (SELECT TOP 1 ID FROM PRODUCT WHERE LTRIM(RTRIM(CODE)) = :art3) P
    CROSS APPLY dbo.GET_STOCK(:agora, P.ID, E.ID, 1, NULL, 1, 1) STK
    GROUP BY E.CODE", [':art1' => $art, ':art2' => $art, ':art3' => $art, ':agora' => date('Y-m-d') . ' 23:59:59']) as $r) {
    $mov[$r['COD']] = $mov[$r['COD']] ?? ['arm' => $r['ARM'], 'doc' => 0.0, 'inv' => 0.0, 'stk' => null];
    $mov[$r['COD']]['stk'] = (float) $r['Q'];
}
ksort($mov);
echo "1. Stock de hoje por armazém: Pinguim BO (GET_STOCK) e soma de todos os movimentos (desde sempre)\n";
echo '   ' . $c('Armazém', 12) . $c('Nome', 30) . '  Documentos   Acertos     Soma    Pinguim   Diferença' . "\n";
$t = ['doc' => 0.0, 'inv' => 0.0, 'stk' => 0.0];
foreach ($mov as $cod => $x) {
    $soma = $x['doc'] + $x['inv'];
    $dif = $x['stk'] === null ? null : round($x['stk'] - $soma, 3);
    $t['doc'] += $x['doc'];
    $t['inv'] += $x['inv'];
    $t['stk'] += (float) $x['stk'];
    echo '   ' . $c($cod, 12) . $c($x['arm'], 30) . $n($x['doc'], 11) . $n($x['inv'], 10) . $n($soma) . $n($x['stk'] ?? 0, 11)
        . ($dif === null ? '   (sem GET_STOCK)' : ($dif == 0 ? '   ok' : '   ' . $n($dif, 8) . '  ← DIFERENÇA')) . "\n";
}
echo '   ' . $c('TOTAL', 42) . $n($t['doc'], 11) . $n($t['inv'], 10) . $n($t['doc'] + $t['inv']) . $n($t['stk'], 11) . "\n";

// 2. linhas do artigo com a ligação à linha de origem
$linhas = naval_bd_consultar("
    SELECT DMD.ID AS LID, DMD.PARENT_ID AS PID, DMD.LINKTYPE AS LT, CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
           DH.ID AS DID, LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC, LTRIM(RTRIM(DC.CODE)) AS TIPO,
           LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM, CAST(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL) AS FLOAT) AS EF,
           PD.ID AS P_DMD, LTRIM(RTRIM(PC.CODE)) AS P_TIPO, LTRIM(RTRIM(COALESCE(PH.DOC_PREFIX, PC.CODE))) + '/' + CAST(PH.DOC_NUMBER AS VARCHAR(20)) AS P_DOC,
           LTRIM(RTRIM(COALESCE(PW.CODE, ''))) AS P_ARM, LTRIM(RTRIM(PD.PRODUCT_CODE)) AS P_ART
    FROM DOCMOVDETAIL DMD
    INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
    LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
    LEFT JOIN DOCMOVDETAIL PD ON PD.ID = DMD.PARENT_ID
    LEFT JOIN DOCHEADER PH ON PH.ID = PD.DOCHEADER_ID
    LEFT JOIN DOCCONFIG PC ON PC.ID = PH.DOCCONFIG_ID
    LEFT JOIN ENTITY PW ON PW.ID = PD.WAREHOUSE_ID
    WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
      AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de
    ORDER BY HORA, DMD.ID", [':art' => $art, ':de' => $de]);
echo "\n2. Linhas desde $de com a ligação do Pinguim BO à linha de origem (PARENT_ID → documento)\n";
echo '   ' . $c('Data e hora', 20) . $c('Documento', 22) . $c('Armazém', 11) . '  Efeito  ' . $c('ID linha', 11) . $c('PARENT_ID', 11) . $c('LINK', 5) . "Origem\n";
$usadas = [];
foreach ($linhas as $l) {
    if ($l['PID'] !== null && $l['P_DMD'] !== null) $usadas[(string) $l['P_DMD']] = true;
    $orig = $l['PID'] === null ? '' : ($l['P_DMD'] === null ? '(não está em DOCMOVDETAIL)' : $l['P_DOC'] . ' · ' . $l['P_ARM'] . ($l['P_ART'] !== $art ? ' · artigo ' . $l['P_ART'] : ''));
    echo '   ' . $c($l['HORA'], 20) . $c($l['DOC'], 22) . $c($l['ARM'], 11) . $n($l['EF'], 7) . '  ' . $c($l['LID'], 11) . $c($l['PID'] ?? '—', 11)
        . $c($l['LT'] ?? '—', 5) . $orig . "\n";
}
// linhas de outros artigos ou fora do período que apontam para as linhas deste artigo
$ids = array_map(function ($l) { return (int) $l['LID']; }, $linhas);
$filhos = [];
foreach (array_chunk($ids, 500) as $bloco) {
    foreach (naval_bd_consultar("
        SELECT DMD.PARENT_ID AS PID, LTRIM(RTRIM(DC.CODE)) AS TIPO, LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
               LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM, DMD.DELETED AS APAGADA, DH.DELETED AS DOC_APAGADO, COALESCE(DH.CALC_ISCANCELED, 0) AS ANULADO,
               CAST(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) AS FLOAT) AS EF
        FROM DOCMOVDETAIL DMD
        INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
        WHERE DMD.PARENT_ID IN (" . implode(',', $bloco) . ')') as $f) $filhos[(string) $f['PID']][] = $f;
}

// 3. saídas sem destino e entradas sem origem
echo "\n3. Saídas (SA, DA) que nenhuma linha usa como origem · entradas (GTC, EA) sem origem\n";
$semDestino = [];
foreach ($linhas as $l) {
    $id = (string) $l['LID'];
    if ($l['EF'] < 0 && in_array($l['TIPO'], ['SA', 'DA'], true)) {
        $fs = $filhos[$id] ?? [];
        $vivos = array_filter($fs, function ($f) { return !$f['APAGADA'] && !$f['DOC_APAGADO'] && !$f['ANULADO']; });
        if (!$vivos) {
            $semDestino[(string) $l['DID']] = $l['DOC'];
            $extra = $fs ? ' · tem ' . count($fs) . ' linha(s) filha(s) apagada(s) ou anulada(s): '
                . implode(', ', array_map(function ($f) { return $f['DOC'] . ($f['ANULADO'] ? ' anulado' : ' apagado'); }, $fs)) : '';
            echo '   SAÍDA SEM DESTINO  ' . $c($l['HORA'], 20) . $c($l['DOC'], 22) . $c($l['ARM'], 11) . $n($l['EF'], 7) . $extra . "\n";
        } else {
            $q = array_sum(array_map(function ($f) { return (float) $f['EF']; }, $vivos));
            if (abs($q + (float) $l['EF']) > 0.0005)
                echo '   QUANTIDADE DIFERENTE ' . $c($l['DOC'], 22) . $n($l['EF'], 7) . ' → ' . implode(', ', array_map(function ($f) { return $f['DOC'] . ' ' . $f['ARM'] . ' ' . $f['EF']; }, $vivos)) . "\n";
        }
    }
    if ($l['EF'] > 0 && in_array($l['TIPO'], ['GTC', 'EA'], true) && $l['P_DMD'] === null)
        echo '   ENTRADA SEM ORIGEM ' . $c($l['HORA'], 20) . $c($l['DOC'], 22) . $c($l['ARM'], 11) . $n($l['EF'], 7) . "\n";
}

// 4. cabeçalho completo dos documentos de saída sem destino
echo "\n4. Cabeçalho completo dos documentos de saída sem destino (colunas com valor)\n";
if (!$semDestino) echo "   nenhum\n";
foreach (array_slice($semDestino, 0, 40, true) as $did => $doc) {
    $h = naval_bd_consultar('SELECT * FROM DOCHEADER WHERE ID = :id', [':id' => (int) $did])[0] ?? [];
    echo "   --- $doc (DOCHEADER.ID $did)\n";
    foreach ($h as $k => $v) {
        if ($v === null || trim((string) $v) === '' || (is_numeric($v) && (float) $v == 0)) continue;
        echo '      ' . $c($k, 34) . mb_substr(preg_replace('/\s+/', ' ', (string) $v), 0, 120) . "\n";
    }
}
echo "\nFim. Nada foi alterado no Pinguim BO.\n";
