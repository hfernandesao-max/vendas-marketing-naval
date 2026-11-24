<?php
/**
 * Abertura e fecho do dia no Pinguim BO: onde estão e como ficam registados (no servidor, na pasta do portal).
 * Só SELECT — não escreve nada no Pinguim BO.
 *
 *     php ferramentas/pinguim-turnos.php                                   últimos 7 dias, todas as rotas
 *     php ferramentas/pinguim-turnos.php --de=2026-11-19 --ate=2026-11-24 --rota=1002
 *
 * Mostra:
 *   1. os tipos de documento do período, com quantos foram emitidos num dia diferente da data que levam (DOC_DATE ≠ dia
 *      do CLOCK_DATE) e a hora da primeira e da última emissão; os que parecem de abertura ou fecho vêm marcados;
 *   2. as tabelas cujo nome sugere turnos, sessões, caixa ou fecho de dia, com as colunas e o número de linhas;
 *   3. por rota e dia, os documentos dos tipos marcados (abertura/fecho), com a data que levam e a hora de emissão;
 *   4. as linhas mais recentes das tabelas de turno que tenham coluna de loja/rota (só as 5 últimas de cada).
 * Serve para ligar o portal à abertura e ao fecho automáticos do dia que vão ser configurados no Pinguim BO.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/nucleo/arranque.php';
naval_servico('bd');

$o = getopt('', ['de::', 'ate::', 'rota::']);
$ate = naval_bd_data((string) ($o['ate'] ?? date('Y-m-d')));
$de = naval_bd_data((string) ($o['de'] ?? date('Y-m-d', strtotime($ate . ' 12:00') - 6 * 86400)));
$rota = isset($o['rota']) ? preg_replace('/\D/', '', (string) $o['rota']) : '';
$PADRAO = '/ABER|FECH|FCL|TURN|CAIX|SHIFT|CLOS|OPEN|SESS|DAYEND|Z ?REP/i';
$p = [':de' => $de, ':ate' => $ate];

function turnos_linha(array $cols, array $larg): string
{
    $s = '';
    foreach ($cols as $i => $c) {
        $c = mb_strimwidth((string) $c, 0, $larg[$i], '…');
        $s .= str_pad($c, $larg[$i] + 2 + strlen($c) - mb_strlen($c));
    }
    return rtrim($s) . "\n";
}

echo 'Pinguim BO: abertura e fecho do dia, de ' . $de . ' a ' . $ate . ($rota !== '' ? ', rota ' . $rota : '') . " (só leitura)\n\n";

// 1. tipos de documento
echo "1. Tipos de documento do período (* = parece abertura ou fecho)\n";
$tipos = naval_bd_consultar("
    SELECT DC.CODE AS COD, DC.DESCRIPTION AS DESCR, COUNT(*) AS N,
           SUM(CASE WHEN DH.CLOCK_DATE IS NOT NULL AND CAST(DH.CLOCK_DATE AS DATE) <> CAST(DH.DOC_DATE AS DATE) THEN 1 ELSE 0 END) AS OUTRO_DIA,
           MIN(CONVERT(VARCHAR(5), DH.CLOCK_DATE, 108)) AS PRIMEIRA, MAX(CONVERT(VARCHAR(5), DH.CLOCK_DATE, 108)) AS ULTIMA
    FROM DOCHEADER DH
    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
    LEFT JOIN STORE S ON S.ID = DH.STORE_ID
    WHERE DH.DELETED = 0 AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, CAST(:ate AS DATE))"
    . ($rota !== '' ? ' AND COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) = :rota' : '') . "
    GROUP BY DC.CODE, DC.DESCRIPTION
    ORDER BY DC.CODE", $rota !== '' ? $p + [':rota' => $rota] : $p);
$larg = [2, 8, 44, 7, 10, 9, 7];
echo turnos_linha(['', 'Tipo', 'Descrição', 'Docs', 'Outro dia', 'Primeira', 'Última'], $larg);
$marcados = [];
foreach ($tipos as $t) {
    $m = preg_match($PADRAO, $t['COD'] . ' ' . $t['DESCR']) === 1;
    if ($m) $marcados[] = (string) $t['COD'];
    echo turnos_linha([$m ? '*' : '', $t['COD'], $t['DESCR'], $t['N'], $t['OUTRO_DIA'], $t['PRIMEIRA'], $t['ULTIMA']], $larg);
}

// 2. tabelas candidatas
echo "\n2. Tabelas que podem ter turnos, sessões, caixa ou fecho de dia\n";
$tabs = naval_bd_consultar("
    SELECT T.name AS TABELA, SUM(P.rows) AS LINHAS
    FROM sys.tables T
    INNER JOIN sys.partitions P ON P.object_id = T.object_id AND P.index_id IN (0, 1)
    GROUP BY T.name
    ORDER BY T.name");
$cand = [];
foreach ($tabs as $t) {
    if (preg_match('/CLOS|CASH|FECH|CAIXA|SHIFT|TURN|DAYEND|ZREPORT|SESSION|OPEN|ABERT|POS_?DAY|WORKDAY/i', (string) $t['TABELA']) !== 1) continue;
    $cols = naval_bd_consultar("SELECT COLUMN_NAME AS C, DATA_TYPE AS T FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t ORDER BY ORDINAL_POSITION", [':t' => $t['TABELA']]);
    $nomes = array_map(function ($c) { return $c['C'] . ' (' . $c['T'] . ')'; }, $cols);
    echo '  ' . $t['TABELA'] . ' — ' . (int) $t['LINHAS'] . " linhas\n    " . implode(', ', $nomes) . "\n";
    $cand[] = ['tabela' => (string) $t['TABELA'], 'linhas' => (int) $t['LINHAS'], 'colunas' => array_column($cols, 'C')];
}
if (!$cand) echo "  (nenhuma)\n";

// 3. documentos de abertura e fecho, por rota e dia
echo "\n3. Documentos de abertura/fecho (tipos marcados com *), por rota e dia\n";
if (!$marcados) {
    echo "  (nenhum tipo marcado no período)\n";
} else {
    $pp = $p;
    $marcas = [];
    foreach ($marcados as $i => $c) {
        $marcas[] = ':m' . $i;
        $pp[':m' . $i] = $c;
    }
    if ($rota !== '') $pp[':rota'] = $rota;
    $docs = naval_bd_consultar("
        SELECT COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA, DC.CODE AS COD,
               LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, ''))) AS PREF, DH.DOC_NUMBER AS NUM,
               CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA, CONVERT(VARCHAR(19), DH.CLOCK_DATE, 120) AS EMITIDO,
               CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT) AS ANULADO, CAST(DH.CALC_TOTAL_W_TAX AS FLOAT) AS TOTAL
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        WHERE DH.DELETED = 0 AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, CAST(:ate AS DATE))
          AND DC.CODE IN (" . implode(', ', $marcas) . ")"
        . ($rota !== '' ? ' AND COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) = :rota' : '') . "
        ORDER BY ROTA, DH.DOC_DATE, DH.CLOCK_DATE", $pp);
    $larg = [6, 6, 22, 11, 20, 10, 14];
    echo turnos_linha(['Rota', 'Tipo', 'Documento', 'Data', 'Emitido', 'Dia certo', 'Total'], $larg);
    foreach ($docs as $d) {
        $certo = substr((string) $d['EMITIDO'], 0, 10) === (string) $d['DATA'];
        echo turnos_linha([$d['ROTA'], $d['COD'], trim($d['COD'] . ' ' . $d['PREF'] . '/' . $d['NUM']) . ((int) $d['ANULADO'] ? ' (anulado)' : ''),
            $d['DATA'], $d['EMITIDO'], $d['EMITIDO'] === null ? '?' : ($certo ? 'sim' : 'NÃO'), number_format((float) $d['TOTAL'], 2, ',', ' ')], $larg);
    }
    if (!$docs) echo "  (nenhum)\n";
}

// 4. linhas recentes das tabelas de turno com coluna de loja
echo "\n4. Linhas mais recentes das tabelas de turno (5 por tabela)\n";
$mostradas = 0;
foreach ($cand as $c) {
    if ($c['linhas'] === 0) continue;
    $loja = null;
    $data = null;
    foreach ($c['colunas'] as $col) {
        if ($loja === null && preg_match('/^STORE_?ID$|^STORE$|LOJA/i', $col)) $loja = $col;
        if ($data === null && preg_match('/DATE|DATA|TIMESTAMP|OPEN|CLOSE/i', $col)) $data = $col;
    }
    if ($loja === null) continue;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $c['tabela'] . ($data ?? '') . $loja)) continue;
    $linhas = naval_bd_consultar('SELECT TOP 5 * FROM [' . $c['tabela'] . ']' . ($data !== null ? ' ORDER BY [' . $data . '] DESC' : ''));
    echo '  ' . $c['tabela'] . ($data !== null ? ' (por ' . $data . ')' : '') . ":\n";
    foreach ($linhas as $l) {
        $par = [];
        foreach ($l as $k => $v) if ($v !== null && $v !== '') $par[] = $k . '=' . mb_strimwidth((string) $v, 0, 40, '…');
        echo '    ' . implode(' · ', $par) . "\n";
    }
    $mostradas++;
}
if (!$mostradas) echo "  (nenhuma tabela de turno com coluna de loja)\n";
echo "\nEnvie este resultado para ligar o portal à abertura e ao fecho automáticos do dia.\n";
