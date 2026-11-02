<?php
/**
 * Vendas do RTM a partir da exportação do Pinguim BO: documentos FR/FT, notas de crédito, pagamentos e
 * linhas de artigos, e o âmbito de cada perfil (rotas, NIF e Logística só com quantidades).
 *
 * Notas de crédito: vêm com o total (e as linhas) em negativo e 'nc' => true, para o total de vendas ser
 * sempre líquido (facturas − notas de crédito) em todos os ecrãs. Não têm pagamentos nem valor por receber:
 * o recebido e os pendentes continuam a ser os das facturas, como o Pinguim BO os regista.
 */
defined('NAVAL_NUCLEO') || exit;

/** Meios de pagamento sem descrição no XML; os nomes que vêm do Pinguim BO prevalecem. */
const NAVAL_MEIOS_PAGAMENTO = ['NU' => 'Numerário', 'MC' => 'Multicaixa', 'TB' => 'Transferência Bancária', 'CS' => 'Advance'];

/** Páginas que mostram vendas: sem nenhuma, o perfil só vê quantidades por artigo. */
const NAVAL_PAGINAS_VENDAS = ['vendas-promotor', 'vendas-dia', 'vendas-cliente', 'ranking', 'documentos', 'pagamentos', 'auditoria', 'vendas-marca', 'plano-visitas', 'sap-exportar', 'pendentes'];

/**
 * Converte a exportação em vendas (formato de vendas.json) e, com $itens, nas linhas de artigos.
 * $promotores: {rota: promotor}. Devolve ['vendas' => [...], 'artigos' => [...]].
 */
function naval_vendas_do_xml(SimpleXMLElement $xml, array $promotores, bool $itens): array
{
    $entidades = [];
    if (isset($xml->entities)) {
        foreach ($xml->entities->entity as $ent) {
            $entidades[(string) $ent->code] = ['desc' => (string) $ent->description, 'tax' => (string) $ent->tax_number];
        }
    }
    $meios = NAVAL_MEIOS_PAGAMENTO;
    if (isset($xml->payment_methods)) {
        foreach ($xml->payment_methods->payment_method as $pm) {
            $code = (string) $pm->code;
            $desc = trim((string) $pm->description);
            if ($code !== '' && $desc !== '') $meios[$code] = $desc;
        }
    }

    $vendas = [];
    $artigos = [];
    if (!isset($xml->transactions)) return ['vendas' => $vendas, 'artigos' => $artigos];
    foreach ($xml->transactions->transaction as $tx) {
        $tipo = (string) $tx->document_type;
        // Documentos de venda (Factura-recibo / Factura) e notas de crédito, sem os anulados
        if (!in_array($tipo, ['FR', 'FT', 'NC'], true) || (string) $tx->deleted === '1') continue;
        $nc = $tipo === 'NC';
        // Nota de crédito: o valor desconta às vendas, qualquer que seja o sinal com que vem na exportação
        $sinal = function (float $v) use ($nc) { return $nc ? -abs($v) : $v; };

        $rota = (string) $tx->store_code;
        $entidade = (string) $tx->entity_code;
        $total = $sinal((float) (string) $tx->total);
        $data = (string) $tx->transaction_date;
        $doc = (string) $tx->document_nr;
        $nif = (string) $tx->tax_number;
        $cliente = $entidades[$entidade]['desc'] ?? '';
        if (!$cliente) $cliente = $nif === '999999999' ? 'Consumidor Final' : '';

        $pagamentos = $nc ? [] : naval_vendas_pagamentos($tx, $total, $meios);
        $pago = round(array_sum(array_column($pagamentos, 'valor')), 2);
        $promotor = $promotores[$rota] ?? $rota;

        $vendas[] = [
            'chave' => $doc,
            'data' => $data,
            'hora' => substr((string) $tx->transaction_time, 0, 5),
            'rota' => $rota,
            'promotor' => $promotor,
            'tipo' => $nc ? 'Nota de crédito' : ($tipo === 'FR' ? 'Factura-recibo' : 'Factura'),
            'tipo_codigo' => $tipo,
            'nc' => $nc,
            'documento' => $doc,
            'cliente' => $cliente,
            'cliente_codigo' => $entidade,
            'nif' => $nif ?: ($entidades[$entidade]['tax'] ?? ''),
            'liquidado' => $nc || $pago >= round($total, 2),
            'total' => $total,
            'valor_pago' => $pago,
            'pagamentos' => $pagamentos,
            'meio_pagamento' => $pagamentos ? implode(', ', array_column($pagamentos, 'meio')) : null,
        ];

        if ($itens && isset($tx->detail)) {
            $n = 0;
            foreach ($tx->detail->line as $l) {
                $n++;
                $artigos[] = [
                    'chave' => $doc . '|' . $n,
                    'data' => $data,
                    'rota' => $rota,
                    'promotor' => $promotor,
                    'documento' => $doc,
                    'codigo' => (string) $l->product_code,
                    'artigo' => (string) $l->product_description,
                    'unidade' => (string) $l->uom,
                    'quantidade' => $sinal((float) (string) $l->quantity),
                    'preco_unitario' => (float) (string) $l->base_amount,
                    'valor_sem_iva' => $sinal((float) (string) $l->total),
                    'desconto' => $sinal((float) (string) $l->discount_amount),
                    'iva' => $sinal((float) (string) $l->tax_amount),
                    'total' => $sinal((float) (string) $l->total + (float) (string) $l->tax_amount),
                    'nc' => $nc,
                ];
            }
        }
    }
    return ['vendas' => $vendas, 'artigos' => $artigos];
}

/**
 * Pagamentos de um documento (rodapé), por meio de pagamento. O troco sai do numerário (ou, sem
 * numerário, do meio com maior valor), para o recebido nunca exceder o total da venda.
 */
function naval_vendas_pagamentos(SimpleXMLElement $tx, float $total, array $meios): array
{
    $porMeio = [];
    if (isset($tx->footer)) {
        foreach ($tx->footer->line as $fl) {
            $valor = (float) (string) $fl->amount;
            if ($valor == 0.0) continue;
            $k = trim((string) $fl->payment_code);
            $k = $k !== '' ? $k : '?';
            $porMeio[$k] = ($porMeio[$k] ?? 0.0) + $valor;
        }
    }
    $excesso = round(array_sum($porMeio) - $total, 2);
    if ($excesso > 0 && $total > 0) {
        $k = isset($porMeio['NU']) ? 'NU' : array_search(max($porMeio), $porMeio, true);
        $porMeio[$k] = max(0.0, $porMeio[$k] - $excesso);
    }
    $out = [];
    foreach ($porMeio as $k => $v) {
        if (round($v, 2) == 0.0) continue;
        $out[] = ['codigo' => (string) $k, 'meio' => $meios[$k] ?? ($k === '?' ? 'Sem meio indicado' : (string) $k), 'valor' => round($v, 2)];
    }
    return $out;
}

/**
 * Âmbito do utilizador: só as suas rotas (SUP e PRO), NIF só para quem o pode ver; sem páginas
 * de vendas (por exemplo, Logística) só quantidades por artigo, sem valores nem documentos.
 * $rotas: rotas permitidas (null = todas); $veNif; $veVendas.
 */
function naval_vendas_ambito(array $d, ?array $rotas, bool $veNif, bool $veVendas): array
{
    if ($rotas !== null) {
        $dentro = function ($x) use ($rotas) { return in_array((string) $x['rota'], $rotas, true); };
        $d['vendas'] = array_values(array_filter($d['vendas'], $dentro));
        $d['artigos'] = array_values(array_filter($d['artigos'], $dentro));
    }
    if (!$veNif) {
        foreach ($d['vendas'] as $i => $v) $d['vendas'][$i]['nif'] = '';
    }
    if (!$veVendas) {
        $d['vendas'] = [];
        foreach ($d['artigos'] as $i => $a) {
            foreach (['preco_unitario', 'valor_sem_iva', 'desconto', 'iva', 'total', 'gross_total'] as $k) $d['artigos'][$i][$k] = null;
            $d['artigos'][$i]['documento'] = '';
        }
    }
    return $d;
}

/** O utilizador actual vê vendas (alguma página de vendas na matriz)? */
function naval_ve_vendas(): bool
{
    foreach (NAVAL_PAGINAS_VENDAS as $rec) if (naval_pode($rec)) return true;
    return false;
}

// ── Vendas lidas directamente da base de dados do Pinguim BO (só SELECT) ──

/**
 * Vendas e linhas de artigos do período (datas AAAAMMDD) lidas do SQL Server: DOCHEADER + DOCCONFIG +
 * STORE (documentos não anulados: vendas com total positivo e notas de crédito, as de sinal de vendas
 * negativo, com o valor em negativo), DOCFOOTER + PAYMETHOD (pagamentos, só das vendas) e
 * DOCMOVDETAIL + UNIT (artigos). Devolve null se a base de dados não responder (o portal usa então a
 * exportação REST). Mesmo formato que naval_vendas_do_xml().
 */
function naval_vendas_sql(string $inicio, string $fim, array $promotores, bool $itens): ?array
{
    if (!naval_bd_configurada()) return null;
    try {
        $p = [':de' => naval_bd_data($inicio), ':ate' => naval_bd_data($fim)];

        // 1. Documentos de venda
        $docs = naval_bd_consultar("
            SELECT
                DH.ID AS DOC_ID,
                CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA,
                RIGHT('0' + CAST(COALESCE(DH.CLOCK_HOUR / 60, 0) AS VARCHAR(2)), 2) + ':' + RIGHT('0' + CAST(COALESCE(DH.CLOCK_HOUR % 60, 0) AS VARCHAR(2)), 2) AS HORA,
                COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA,
                DC.CODE AS TIPO_DOC,
                DC.DESCRIPTION AS TIPO_DESC,
                RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
                DH.ENTITY_NAME AS CLIENTE,
                DH.ENTITY_ID AS CLIENTE_CODIGO,
                DH.ENTITY_TAXNUM AS NIF,
                CAST(DH.PAID AS INT) AS LIQUIDADO,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN 1 ELSE 0 END AS INT) AS NC,
                CAST((CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DH.CALC_TOTAL_W_TAX) ELSE DH.CALC_TOTAL_W_TAX * DH.CALC_SALES_SIGNAL END) AS FLOAT) AS TOTAL,
                CAST(DH.TOTAL_PAID AS FLOAT) AS TOTAL_PAID
            FROM DOCHEADER DH
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
            WHERE DH.DELETED = 0
              AND ((DH.CALC_TOTAL_W_TAX * DH.CALC_SALES_SIGNAL) > 0 OR (DH.CALC_SALES_SIGNAL < 0 AND DH.CALC_TOTAL_W_TAX <> 0))
              AND DH.DOC_DATE >= :de
              AND DH.DOC_DATE <= :ate
            ORDER BY DH.DOC_DATE DESC, DH.ID DESC", $p);

        // 2. Pagamentos por documento
        $pagamentosPorDoc = [];
        foreach (naval_bd_consultar("
            SELECT DF.DOCHEADER_ID, PM.CODE AS CODIGO, PM.DESCRIPTION AS MEIO, CAST(DF.PAYVALUE AS FLOAT) AS VALOR
            FROM DOCFOOTER DF
            INNER JOIN DOCHEADER DH ON DH.ID = DF.DOCHEADER_ID
            LEFT JOIN PAYMETHOD PM ON PM.ID = DF.PAYMETHOD_ID
            WHERE DF.DELETED = 0
              AND DH.DOC_DATE >= :de
              AND DH.DOC_DATE <= :ate", $p) as $pg) {
            $pagamentosPorDoc[$pg['DOCHEADER_ID']][] = ['codigo' => $pg['CODIGO'] ?? '?', 'meio' => $pg['MEIO'] ?? 'Sem meio indicado', 'valor' => (float) $pg['VALOR']];
        }

        $vendas = [];
        foreach ($docs as $d) {
            $nc = (int) $d['NC'] === 1;
            // Nota de crédito: sem pagamentos nem valor por receber (o recebido é o das facturas)
            $pags = $nc ? [] : ($pagamentosPorDoc[$d['DOC_ID']] ?? []);
            $rota = trim((string) $d['ROTA']);
            $total = (float) $d['TOTAL'];
            if (!$nc && !$pags && $d['LIQUIDADO']) $pags[] = ['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => $total];
            $vendas[] = [
                'chave' => $d['DOCUMENTO'],
                'data' => $d['DATA'],
                'hora' => $d['HORA'],
                'rota' => $rota,
                'promotor' => $promotores[$rota] ?? $rota,
                'tipo' => $d['TIPO_DESC'],
                'tipo_codigo' => (string) $d['TIPO_DOC'],
                'nc' => $nc,
                'documento' => $d['DOCUMENTO'],
                'cliente' => $d['CLIENTE'],
                'cliente_codigo' => (string) $d['CLIENTE_CODIGO'],
                'nif' => $d['NIF'],
                'liquidado' => $nc || (bool) $d['LIQUIDADO'],
                'total' => $total,
                'valor_pago' => $nc ? 0.0 : (float) $d['TOTAL_PAID'],
                'pagamentos' => $pags,
                'meio_pagamento' => $pags ? implode(', ', array_column($pags, 'meio')) : null,
            ];
        }

        // 3. Linhas de artigos (consulta própria: o portal pede-as só quando uma página precisa delas)
        $artigos = [];
        if ($itens && $docs) {
            $artigos = naval_vendas_artigos_sql($inicio, $fim, $promotores);
            if ($artigos === null) return null;
        }
        return ['vendas' => $vendas, 'artigos' => $artigos];
    } catch (Throwable $e) {
        error_log('[vendas SQL] ' . $e->getMessage());
        return null;
    }
}

/**
 * Linhas de artigos das vendas do período (datas AAAAMMDD), lidas do SQL Server (DOCMOVDETAIL + UNIT), no formato
 * de naval_vendas_sql(). Consulta separada dos documentos: o portal abre com os documentos e pede as linhas só
 * quando uma página (ou uma exportação) precisa delas. null se a base de dados não responder.
 */
function naval_vendas_artigos_sql(string $inicio, string $fim, array $promotores): ?array
{
    if (!naval_bd_configurada()) return null;
    try {
        $p = [':de' => naval_bd_data($inicio), ':ate' => naval_bd_data($fim)];
        $artigos = [];
        foreach (naval_bd_consultar("
            SELECT
                RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
                CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA,
                COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA,
                DMD.LINE_NUMBER AS LINHA,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN 1 ELSE 0 END AS INT) AS NC,
                DMD.PRODUCT_CODE AS CODIGO,
                DMD.DESCRIPTION AS ARTIGO,
                COALESCE(U.SHORTNAME, 'UN') AS UNIDADE,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.QNT) ELSE DMD.QNT END AS FLOAT) AS QUANTIDADE,
                CAST(DMD.PRICE AS FLOAT) AS PRECO_UNITARIO,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.TOTAL) ELSE DMD.TOTAL END AS FLOAT) AS VALOR_SEM_IVA,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.DISCOUNT_VALUE) ELSE DMD.DISCOUNT_VALUE END AS FLOAT) AS DESCONTO,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.CALC_TOTAL_TAX_VALUE) ELSE DMD.CALC_TOTAL_TAX_VALUE END AS FLOAT) AS IVA,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.CALC_TOTAL_W_TAX) ELSE DMD.CALC_TOTAL_W_TAX END AS FLOAT) AS TOTAL,
                CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DMD.GROSS_TOTAL) ELSE DMD.GROSS_TOTAL END AS FLOAT) AS GROSS_TOTAL
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
            LEFT JOIN UNIT U ON U.ID = DMD.UNIT_ID
            WHERE DMD.DELETED = 0
              AND DH.DELETED = 0
              AND ((DH.CALC_TOTAL_W_TAX * DH.CALC_SALES_SIGNAL) > 0 OR (DH.CALC_SALES_SIGNAL < 0 AND DH.CALC_TOTAL_W_TAX <> 0))
              AND DH.DOC_DATE >= :de
              AND DH.DOC_DATE <= :ate
            ORDER BY DH.DOC_DATE DESC, DH.ID DESC, DMD.LINE_NUMBER ASC", $p) as $a) {
            $r = trim((string) $a['ROTA']);
            $artigos[] = [
                'chave' => $a['DOCUMENTO'] . '|' . $a['LINHA'],
                'data' => $a['DATA'],
                'rota' => $r,
                'promotor' => $promotores[$r] ?? $r,
                'documento' => $a['DOCUMENTO'],
                'codigo' => $a['CODIGO'],
                'artigo' => $a['ARTIGO'],
                'unidade' => $a['UNIDADE'],
                'quantidade' => (float) $a['QUANTIDADE'],
                'preco_unitario' => (float) $a['PRECO_UNITARIO'],
                'valor_sem_iva' => (float) $a['VALOR_SEM_IVA'],
                'desconto' => (float) $a['DESCONTO'],
                'iva' => (float) $a['IVA'],
                'total' => (float) $a['TOTAL'],
                // GROSSSALES do ficheiro para o SAP: DOCMOVDETAIL.GROSS_TOTAL (null se a coluna vier vazia)
                'gross_total' => $a['GROSS_TOTAL'] === null ? null : (float) $a['GROSS_TOTAL'],
                'nc' => (int) $a['NC'] === 1,
            ];
        }
        return $artigos;
    } catch (Throwable $e) {
        error_log('[artigos SQL] ' . $e->getMessage());
        return null;
    }
}
