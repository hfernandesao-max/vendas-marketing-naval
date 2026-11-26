<?php
/**
 * Extracto dos movimentos de stock: todas as entradas e saídas de artigos no Pinguim BO (só SELECT), documento a
 * documento, por armazém (armazéns centrais e carrinhas) e artigo, num período (até 31 dias).
 *
 * Efeito no stock de cada linha: quantidade × sinal de stock da linha (DOCMOVDETAIL.STK_SIGNAL) ou, sem ele, do tipo de
 * documento (DOCCONFIG.STOCK_SIGNAL) — a mesma regra da conciliação da carrinha: vendas e saídas negativas, cargas,
 * compras e devoluções ao armazém positivas. Os inventários (INVENTORYHEADER com SET_QNT = 1) entram com o acerto
 * (contado − teórico). Documentos anulados ou apagados ficam de fora.
 *
 * Com um só armazém e um só artigo, o extracto traz o saldo: o stock no fim do dia anterior ao período (dbo.GET_STOCK)
 * e o saldo depois de cada movimento, como um extracto bancário.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MOV_DIAS_MAX = 31;
const NAVAL_MOV_LINHAS_MAX = 20000;

/** Período válido (AAAA-MM-DD), até NAVAL_MOV_DIAS_MAX dias. */
function naval_mov_periodo(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate . ' 12:00') - strtotime($de . ' 12:00')) / 86400 + 1 > NAVAL_MOV_DIAS_MAX) {
        throw new NavalErro('Escolha um período até ' . NAVAL_MOV_DIAS_MAX . ' dias.', 400);
    }
    return [$de, $ate];
}

/**
 * Função pura (testada). $linhas: [{HORA, DOC, TIPO, TIPO_NOME, ARM_COD, ARM, CODIGO, ARTIGO, EFEITO}];
 * $saldoInicial: stock no início do período (só com um armazém e um artigo) ou null.
 * Devolve {linhas: [{em, doc, tipo, tipo_nome, armazem_cod, armazem, codigo, artigo, entrada, saida, saldo?}],
 * resumo: {movimentos, documentos, entradas, saidas, liquido}, por_artigo: [{codigo, artigo, entradas, saidas, liquido}],
 * por_tipo: [{tipo, tipo_nome, entradas, saidas, liquido, documentos}], por_armazem: [{armazem_cod, armazem, …}],
 * saldo_inicial, saldo_final}.
 */
function naval_mov_montar(array $linhas, ?float $saldoInicial = null): array
{
    usort($linhas, function ($a, $b) { return [(string) $a['HORA'], (string) $a['DOC']] <=> [(string) $b['HORA'], (string) $b['DOC']]; });
    $out = [];
    $saldo = $saldoInicial;
    $porArt = $porTipo = $porArm = [];
    $docs = [];
    $ent = $sai = 0.0;
    foreach ($linhas as $l) {
        $q = round((float) $l['EFEITO'], 3);
        if (abs($q) < 0.0005) continue;
        $c = trim((string) $l['CODIGO']);
        $x = ['em' => substr((string) $l['HORA'], 0, 19), 'doc' => trim((string) $l['DOC']), 'tipo' => trim((string) $l['TIPO']),
            'tipo_nome' => trim((string) ($l['TIPO_NOME'] ?? '')), 'armazem_cod' => trim((string) ($l['ARM_COD'] ?? '')), 'armazem' => trim((string) ($l['ARM'] ?? '')),
            'codigo' => $c, 'artigo' => trim((string) ($l['ARTIGO'] ?? '')) ?: $c, 'entrada' => $q > 0 ? $q : 0.0, 'saida' => $q < 0 ? -$q : 0.0];
        if ($saldo !== null) {
            $saldo = round($saldo + $q, 3);
            $x['saldo'] = $saldo;
        }
        $out[] = $x;
        $docs[$x['doc']] = true;
        $ent += $x['entrada'];
        $sai += $x['saida'];
        if (!isset($porArt[$c])) $porArt[$c] = ['codigo' => $c, 'artigo' => $x['artigo'], 'entradas' => 0.0, 'saidas' => 0.0];
        $porArt[$c]['entradas'] += $x['entrada'];
        $porArt[$c]['saidas'] += $x['saida'];
        // totais por tipo de documento e por armazém (com o número de documentos de cada um)
        $porTipo = naval_mov_somar($porTipo, $x['tipo'], ['tipo' => $x['tipo'], 'tipo_nome' => $x['tipo_nome']], $x);
        $porArm = naval_mov_somar($porArm, $x['armazem_cod'], ['armazem_cod' => $x['armazem_cod'], 'armazem' => $x['armazem']], $x);
    }
    $fechar = function (array $grupo) {
        $l = array_values(array_map(function ($a) {
            $n = count($a['docs']);
            unset($a['docs']);
            return $a + ['documentos' => $n, 'liquido' => round($a['entradas'] - $a['saidas'], 3)];
        }, $grupo));
        foreach ($l as &$x) [$x['entradas'], $x['saidas']] = [round($x['entradas'], 3), round($x['saidas'], 3)];
        unset($x);
        usort($l, function ($a, $b) { return ($b['entradas'] + $b['saidas']) <=> ($a['entradas'] + $a['saidas']); });
        return $l;
    };
    $pa = array_values(array_map(function ($a) {
        return ['codigo' => $a['codigo'], 'artigo' => $a['artigo'], 'entradas' => round($a['entradas'], 3), 'saidas' => round($a['saidas'], 3),
            'liquido' => round($a['entradas'] - $a['saidas'], 3)];
    }, $porArt));
    usort($pa, function ($a, $b) { return ($b['entradas'] + $b['saidas']) <=> ($a['entradas'] + $a['saidas']); });
    return ['linhas' => $out, 'resumo' => ['movimentos' => count($out), 'documentos' => count($docs), 'entradas' => round($ent, 3), 'saidas' => round($sai, 3),
        'liquido' => round($ent - $sai, 3)], 'por_artigo' => $pa, 'por_tipo' => $fechar($porTipo), 'por_armazem' => $fechar($porArm),
        'saldo_inicial' => $saldoInicial, 'saldo_final' => $saldo];
}

/** Soma um movimento a um grupo de totais (por tipo ou por armazém), com os documentos distintos. */
function naval_mov_somar(array $grupo, string $chave, array $base, array $x): array
{
    $g = $grupo[$chave] ?? $base + ['entradas' => 0.0, 'saidas' => 0.0, 'docs' => []];
    $g['entradas'] += $x['entrada'];
    $g['saidas'] += $x['saida'];
    $g['docs'][$x['doc']] = true;
    $grupo[$chave] = $g;
    return $grupo;
}

/** Lê os movimentos do Pinguim BO e monta o extracto. $armazem: código do armazém ('' = todos); $artigo: código ou texto. */
function naval_mov_extracto(string $de, string $ate, string $armazem = '', string $artigo = '', string $tipo = ''): array
{
    [$de, $ate] = naval_mov_periodo($de, $ate);
    naval_servico('bd');
    if (!naval_bd_configurada()) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    $armazem = trim($armazem);
    $artigo = trim($artigo);
    $tipo = strtoupper(trim($tipo));
    if ($armazem !== '' && !preg_match('/^[A-Za-z0-9._-]{1,30}$/', $armazem)) throw new NavalErro('Armazém inválido.', 400);
    if ($tipo !== '' && !preg_match('/^[A-Z0-9]{1,10}$/', $tipo)) throw new NavalErro('Tipo de documento inválido.', 400);
    $artigo = mb_substr($artigo, 0, 60);
    $p = [':de' => $de, ':ate' => $ate];
    $f = '';
    $fi = '';
    if ($armazem !== '') {
        $f .= ' AND LTRIM(RTRIM(WE.CODE)) = :arm';
        $fi .= ' AND LTRIM(RTRIM(E.CODE)) = :arm';
        $p[':arm'] = $armazem;
    }
    if ($artigo !== '') {
        $f .= ' AND (LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art OR P.DESCRIPTION LIKE :artl)';
        $fi .= ' AND (LTRIM(RTRIM(P.CODE)) = :art OR P.DESCRIPTION LIKE :artl)';
        $p[':art'] = $artigo;
        $p[':artl'] = '%' . str_replace(['%', '_'], ['[%]', '[_]'], $artigo) . '%';
    }
    $soDocs = $tipo !== '' && $tipo !== 'INV';
    if ($soDocs) {
        $f .= ' AND LTRIM(RTRIM(DC.CODE)) = :tipo';
        $p[':tipo'] = $tipo;
    }
    $linhas = [];
    if ($tipo !== 'INV') {
        $linhas = naval_bd_consultar("
            SELECT TOP " . NAVAL_MOV_LINHAS_MAX . " CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   DC.CODE AS TIPO, MAX(DC.DESCRIPTION) AS TIPO_NOME, COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM,
                   LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, MAX(COALESCE(P.DESCRIPTION, DMD.DESCRIPTION)) AS ARTIGO,
                   CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0
              AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))$f
            GROUP BY COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, WE.CODE, DMD.PRODUCT_CODE
            ORDER BY HORA", $p);
    }
    if (!$soDocs) {
        $pi = $p;
        unset($pi[':tipo']);
        foreach (naval_bd_consultar("
            SELECT CONVERT(VARCHAR(19), IH.INVENTORY_DATE, 120) AS HORA, LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   'INV' AS TIPO, 'Inventário (acerto: contado − teórico)' AS TIPO_NOME, E.CODE AS ARM_COD, MAX(E.DESCRIPTION) AS ARM,
                   LTRIM(RTRIM(P.CODE)) AS CODIGO, MAX(P.DESCRIPTION) AS ARTIGO, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS EFEITO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND IH.INVENTORY_DATE >= :de AND IH.INVENTORY_DATE < DATEADD(day, 1, CAST(:ate AS DATE))$fi
            GROUP BY IH.INVENTORY_DATE, IH.DOC_PREFIX, IH.DOC_NUMBER, E.CODE, P.CODE", $pi) as $r) $linhas[] = $r;
    }
    // saldo: só com um armazém e um artigo (código exacto) — o stock no fim do dia anterior ao período
    $saldo = null;
    if ($armazem !== '' && $artigo !== '' && $tipo === '') {
        naval_servico('stock');
        $antes = date('Y-m-d', strtotime($de . ' 12:00') - 86400);
        $st = naval_stock($antes, $armazem);
        $saldo = 0.0;
        foreach ((array) ($st['armazens'] ?? []) as $a) {
            if ((string) ($a['cod_armazem'] ?? '') !== $armazem) continue;
            foreach ((array) ($a['itens'] ?? []) as $i) if (trim((string) ($i['codigo'] ?? '')) === $artigo) $saldo += (float) ($i['quantidade'] ?? 0);
        }
        // carrinhas: o armazém vem em cada linha
        foreach ((array) ($st['carrinhas'] ?? []) as $a) {
            foreach ((array) ($a['itens'] ?? []) as $i) {
                if ((string) ($i['cod_armazem'] ?? '') === $armazem && trim((string) ($i['codigo'] ?? '')) === $artigo) $saldo += (float) ($i['quantidade'] ?? 0);
            }
        }
    }
    $r = naval_mov_montar($linhas, $saldo);
    // armazéns com movimentos no período (para o filtro)
    $arms = naval_cache('mov-armazens|' . $de . '|' . $ate, 600, function () use ($de, $ate) {
        return array_map(function ($x) { return ['codigo' => trim((string) $x['COD']), 'nome' => trim((string) $x['NOME'])]; }, naval_bd_consultar("
            SELECT DISTINCT LTRIM(RTRIM(WE.CODE)) AS COD, WE.DESCRIPTION AS NOME
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))
            ORDER BY NOME", [':de' => $de, ':ate' => $ate]));
    });
    return $r + ['de' => $de, 'ate' => $ate, 'armazem' => $armazem, 'artigo' => $artigo, 'tipo' => $tipo, 'armazens' => $arms,
        'limitado' => count($linhas) >= NAVAL_MOV_LINHAS_MAX];
}
