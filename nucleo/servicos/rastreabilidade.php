<?php
/**
 * Rastreabilidade de um artigo (Pinguim BO, só SELECT): o percurso completo num período — fornecedores → armazéns
 * centrais → carrinhas → clientes, com as devoluções, as saídas de armazém e os acertos de inventário — e o detalhe de
 * cada documento.
 *
 * Como se liga cada passo (o mesmo critério da conciliação da carrinha):
 *   - um documento com saída num local e entrada noutro (ex.: guia de transporte do armazém central para a carrinha) é
 *     uma transferência entre esses dois locais;
 *   - venda (CALC_SALES_SIGNAL ≠ 0) com saída de um local: «local → clientes»; nota de crédito (entrada): «clientes → local»;
 *   - outra entrada sem saída (compra, devolução ao fornecedor anulada, entrada avulsa): «fornecedores e entradas → local»;
 *   - outra saída sem entrada (saída de armazém, consumo, quebra): «local → saídas e quebras»;
 *   - inventário (INVENTORYHEADER, SET_QNT = 1): «acertos de inventário ↔ local» com o acerto (contado − teórico).
 * Cada ligação leva os documentos (com a data), para o fluxo animado dia a dia e para abrir cada documento.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_RAST_DIAS_MAX = 93;

/** Tipo de um local pelo código do armazém: carrinha (rota) ou armazém central. */
function naval_rast_local(string $cod, string $nome, array $conf): array
{
    naval_servico('stock');
    [$rota] = naval_stock_rota_armazem($cod, $nome, $conf);
    return $rota !== '' ? ['id' => 'A:' . $cod, 'tipo' => 'carrinha', 'rota' => $rota, 'cod' => $cod, 'nome' => $nome]
        : ['id' => 'A:' . $cod, 'tipo' => 'central', 'rota' => '', 'cod' => $cod, 'nome' => $nome];
}

/**
 * Função pura (testada). $linhas: [{DOC_ID, DOC, HORA, TIPO, TIPO_NOME, VENDA (0|1), ENTIDADE, ARM_COD, ARM, EFEITO}] do
 * artigo; $locais: função cod,nome → local (naval_rast_local). Devolve {nos: [{id, tipo, nome, rota?, entradas, saidas}],
 * ligacoes: [{de, para, q, docs: [{id, doc, em, q, tipo}]}], documentos: n}.
 */
function naval_rast_montar(array $linhas, callable $local): array
{
    $nos = [
        'FORN' => ['id' => 'FORN', 'tipo' => 'fornecedores', 'nome' => 'Fornecedores e entradas'],
        'CLI' => ['id' => 'CLI', 'tipo' => 'clientes', 'nome' => 'Clientes'],
        'SAI' => ['id' => 'SAI', 'tipo' => 'saidas', 'nome' => 'Saídas e quebras'],
        'INV' => ['id' => 'INV', 'tipo' => 'inventario', 'nome' => 'Acertos de inventário'],
    ];
    $porDoc = [];
    foreach ($linhas as $l) {
        $q = round((float) $l['EFEITO'], 3);
        if (abs($q) < 0.0005) continue;
        $k = (string) $l['DOC_ID'];
        $porDoc[$k]['cab'] = ['id' => $k, 'doc' => trim((string) $l['DOC']), 'em' => substr((string) $l['HORA'], 0, 19), 'tipo' => trim((string) $l['TIPO']),
            'tipo_nome' => trim((string) ($l['TIPO_NOME'] ?? '')), 'venda' => !empty($l['VENDA']), 'entidade' => trim((string) ($l['ENTIDADE'] ?? ''))];
        $lc = $local(trim((string) ($l['ARM_COD'] ?? '')), trim((string) ($l['ARM'] ?? '')));
        $nos[$lc['id']] = $nos[$lc['id']] ?? $lc;
        $porDoc[$k]['q'][$lc['id']] = round(($porDoc[$k]['q'][$lc['id']] ?? 0) + $q, 3);
    }
    $lig = [];
    $somar = function (string $de, string $para, float $q, array $cab) use (&$lig) {
        if ($q < 0.0005) return;
        $k = $de . '>' . $para;
        if (!isset($lig[$k])) $lig[$k] = ['de' => $de, 'para' => $para, 'q' => 0.0, 'docs' => []];
        $lig[$k]['q'] = round($lig[$k]['q'] + $q, 3);
        $lig[$k]['docs'][] = ['id' => $cab['id'], 'doc' => $cab['doc'], 'em' => $cab['em'], 'q' => round($q, 3), 'tipo' => $cab['tipo'], 'tipo_nome' => $cab['tipo_nome'],
            'entidade' => $cab['entidade']];
    };
    foreach ($porDoc as $d) {
        $cab = $d['cab'];
        $sai = array_filter($d['q'], function ($q) { return $q < 0; });
        $ent = array_filter($d['q'], function ($q) { return $q > 0; });
        if ($cab['tipo'] === 'INV') {
            foreach ($ent as $n => $q) $somar('INV', $n, $q, $cab);
            foreach ($sai as $n => $q) $somar($n, 'INV', -$q, $cab);
            continue;
        }
        // transferência: as saídas de um local repartem-se pelas entradas noutros, pela ordem
        $restoE = $ent;
        foreach ($sai as $ns => $qs) {
            $falta = -$qs;
            foreach ($restoE as $ne => $qe) {
                if ($falta < 0.0005) break;
                $m = min($falta, $qe);
                $somar($ns, $ne, $m, $cab);
                $restoE[$ne] = round($qe - $m, 3);
                $falta = round($falta - $m, 3);
            }
            if ($falta >= 0.0005) $somar($ns, $cab['venda'] ? 'CLI' : 'SAI', $falta, $cab);
        }
        foreach ($restoE as $ne => $qe) if ($qe >= 0.0005) $somar($cab['venda'] ? 'CLI' : 'FORN', $ne, $qe, $cab);
    }
    foreach ($nos as &$n) $n += ['entradas' => 0.0, 'saidas' => 0.0];
    unset($n);
    foreach ($lig as $l) {
        $nos[$l['para']]['entradas'] = round($nos[$l['para']]['entradas'] + $l['q'], 3);
        $nos[$l['de']]['saidas'] = round($nos[$l['de']]['saidas'] + $l['q'], 3);
    }
    foreach ($lig as &$l) usort($l['docs'], function ($a, $b) { return strcmp($a['em'], $b['em']); });
    unset($l);
    // só os nós com movimento (os quatro fixos aparecem quando têm ligações)
    $usados = [];
    foreach ($lig as $l) $usados[$l['de']] = $usados[$l['para']] = true;
    return ['nos' => array_values(array_intersect_key($nos, $usados)), 'ligacoes' => array_values($lig), 'documentos' => count($porDoc)];
}

/** Percurso de um artigo (código exacto) no período, com o stock inicial e final de cada local. */
function naval_rast_artigo(string $artigo, string $de, string $ate): array
{
    $artigo = trim($artigo);
    if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $artigo)) throw new NavalErro('Indique o código do artigo.', 400);
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate . ' 12:00') - strtotime($de . ' 12:00')) / 86400 + 1 > NAVAL_RAST_DIAS_MAX) throw new NavalErro('Escolha um período até ' . NAVAL_RAST_DIAS_MAX . ' dias.', 400);
    naval_servico('bd');
    naval_servico('stock');
    if (!naval_bd_configurada()) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    $p = [':de' => $de, ':ate' => $ate, ':art' => $artigo];
    $docs = naval_bd_consultar("
        SELECT DH.ID AS DOC_ID, LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
               CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA, DC.CODE AS TIPO, MAX(DC.DESCRIPTION) AS TIPO_NOME,
               MAX(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 THEN 1 ELSE 0 END) AS VENDA, MAX(COALESCE(DH.ENTITY_NAME, '')) AS ENTIDADE,
               COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM,
               CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
        FROM DOCMOVDETAIL DMD
        INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
        WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0
          AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0 AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art
          AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))
        GROUP BY DH.ID, DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), WE.CODE", $p);
    $inv = naval_bd_consultar("
        SELECT 'INV-' + CAST(IH.ID AS VARCHAR(20)) AS DOC_ID, LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
               CONVERT(VARCHAR(19), IH.INVENTORY_DATE, 120) AS HORA, 'INV' AS TIPO, 'Inventário (contado − teórico)' AS TIPO_NOME, 0 AS VENDA, '' AS ENTIDADE,
               E.CODE AS ARM_COD, MAX(E.DESCRIPTION) AS ARM, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS EFEITO
        FROM INVENTORYHEADER IH
        INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
        INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
        INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
        WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND LTRIM(RTRIM(P.CODE)) = :art
          AND IH.INVENTORY_DATE >= :de AND IH.INVENTORY_DATE < DATEADD(day, 1, CAST(:ate AS DATE))
        GROUP BY IH.ID, IH.DOC_PREFIX, IH.DOC_NUMBER, IH.INVENTORY_DATE, E.CODE", $p);
    $conf = naval_stock_carrinhas_confirmadas();
    $r = naval_rast_montar(array_merge($docs, $inv), function ($cod, $nome) use ($conf) { return naval_rast_local($cod, $nome, $conf); });
    // stock de cada local no início (fim do dia anterior) e no fim do período
    $stockEm = function (string $dia) use ($artigo) {
        $s = naval_stock($dia, '');
        $o = [];
        foreach ((array) ($s['armazens'] ?? []) as $a) foreach ((array) ($a['itens'] ?? []) as $i) {
            if (trim((string) ($i['codigo'] ?? '')) === $artigo) $o['A:' . ($a['cod_armazem'] ?? '')] = ($o['A:' . ($a['cod_armazem'] ?? '')] ?? 0) + (float) $i['quantidade'];
        }
        foreach ((array) ($s['carrinhas'] ?? []) as $c) foreach ((array) ($c['itens'] ?? []) as $i) {
            if (trim((string) ($i['codigo'] ?? '')) === $artigo) $o['A:' . ($i['cod_armazem'] ?? '')] = ($o['A:' . ($i['cod_armazem'] ?? '')] ?? 0) + (float) $i['quantidade'];
        }
        return $o;
    };
    $ini = $stockEm(date('Y-m-d', strtotime($de . ' 12:00') - 86400));
    $fim = $stockEm($ate);
    $ids = array_column($r['nos'], 'id');
    foreach (array_unique(array_merge(array_keys($ini), array_keys($fim))) as $id) {
        if (!in_array($id, $ids, true)) {
            $r['nos'][] = naval_rast_local(substr($id, 2), '', $conf) + ['entradas' => 0.0, 'saidas' => 0.0];
            $ids[] = $id;
        }
    }
    foreach ($r['nos'] as &$n) {
        if (strpos($n['id'], 'A:') !== 0) continue;
        $n['inicial'] = round((float) ($ini[$n['id']] ?? 0), 3);
        $n['final'] = round((float) ($fim[$n['id']] ?? 0), 3);
        // balanço: inicial + entradas − saídas = final? (o que não bate fica à vista)
        $n['diferenca'] = round($n['final'] - ($n['inicial'] + $n['entradas'] - $n['saidas']), 3);
    }
    unset($n);
    $nome = naval_bd_consultar("SELECT TOP 1 DESCRIPTION AS N FROM PRODUCT WHERE LTRIM(RTRIM(CODE)) = :art", [':art' => $artigo]);
    return $r + ['artigo' => $artigo, 'nome' => (string) ($nome[0]['N'] ?? $artigo), 'de' => $de, 'ate' => $ate];
}

/** Detalhe de um documento do Pinguim BO: cabeçalho e todas as linhas. Os clientes só para quem vê os clientes. */
function naval_rast_documento(string $id, bool $veClientes): array
{
    naval_servico('bd');
    if (!naval_bd_configurada()) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    if (preg_match('/^INV-(\d{1,12})$/', $id, $m)) {
        $l = naval_bd_consultar("
            SELECT LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC, CONVERT(VARCHAR(19), IH.INVENTORY_DATE, 120) AS HORA,
                   E.CODE AS ARM_COD, E.DESCRIPTION AS ARM, LTRIM(RTRIM(P.CODE)) AS CODIGO, P.DESCRIPTION AS ARTIGO,
                   CAST(ID.THEORETICAL_QNT AS FLOAT) AS TEORICO, CAST(ID.PHYSICAL_QNT AS FLOAT) AS CONTADO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.ID = :id ORDER BY P.CODE", [':id' => (int) $m[1]]);
        if (!$l) throw new NavalErro('Documento não encontrado.', 404);
        return ['id' => $id, 'doc' => $l[0]['DOC'], 'em' => $l[0]['HORA'], 'tipo' => 'INV', 'tipo_nome' => 'Inventário', 'entidade' => '',
            'linhas' => array_map(function ($x) {
                return ['codigo' => $x['CODIGO'], 'artigo' => $x['ARTIGO'], 'armazem' => trim($x['ARM_COD'] . ' · ' . $x['ARM']), 'teorico' => (float) $x['TEORICO'],
                    'contado' => (float) $x['CONTADO'], 'quantidade' => round((float) $x['CONTADO'] - (float) $x['TEORICO'], 3)];
            }, $l)];
    }
    if (!preg_match('/^\d{1,12}$/', $id)) throw new NavalErro('Documento inválido.', 400);
    $l = naval_bd_consultar("
        SELECT LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC, CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
               DC.CODE AS TIPO, DC.DESCRIPTION AS TIPO_NOME, COALESCE(DH.ENTITY_NAME, '') AS ENTIDADE, CAST(COALESCE(DH.CALC_TOTAL_W_TAX, 0) AS FLOAT) AS TOTAL_DOC,
               COALESCE(S.CODE, '') AS LOJA, CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT) AS ANULADO, CAST(COALESCE(DH.CALC_SALES_SIGNAL, 0) AS INT) AS VENDA,
               DMD.LINE_NUMBER AS LINHA, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, COALESCE(P.DESCRIPTION, DMD.DESCRIPTION) AS ARTIGO,
               COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(WE.DESCRIPTION, '') AS ARM, CAST(DMD.QNT AS FLOAT) AS QNT,
               CAST(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) AS FLOAT) AS EFEITO, CAST(COALESCE(DMD.PRICE, 0) AS FLOAT) AS PRECO,
               CAST(COALESCE(DMD.CALC_TOTAL_W_TAX, DMD.TOTAL, 0) AS FLOAT) AS TOTAL
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        INNER JOIN DOCMOVDETAIL DMD ON DMD.DOCHEADER_ID = DH.ID AND DMD.DELETED = 0
        LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
        WHERE DH.ID = :id ORDER BY DMD.LINE_NUMBER", [':id' => (int) $id]);
    if (!$l) throw new NavalErro('Documento não encontrado.', 404);
    $c = $l[0];
    $ent = (string) $c['ENTIDADE'];
    if ((int) $c['VENDA'] !== 0 && !$veClientes) $ent = '';   // o cliente só para quem vê os clientes
    return ['id' => $id, 'doc' => $c['DOC'], 'em' => $c['HORA'], 'tipo' => $c['TIPO'], 'tipo_nome' => $c['TIPO_NOME'], 'entidade' => $ent, 'loja' => $c['LOJA'],
        'anulado' => (int) $c['ANULADO'] === 1, 'total' => round((float) $c['TOTAL_DOC'], 2),
        'linhas' => array_map(function ($x) {
            return ['codigo' => $x['CODIGO'], 'artigo' => $x['ARTIGO'], 'armazem' => trim($x['ARM_COD'] . ' · ' . $x['ARM'], ' ·'), 'quantidade' => (float) $x['QNT'],
                'efeito' => round((float) $x['EFEITO'], 3), 'preco' => round((float) $x['PRECO'], 2), 'total' => round((float) $x['TOTAL'], 2)];
        }, $l)];
}
