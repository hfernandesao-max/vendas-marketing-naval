<?php
/**
 * Documentos válidos e anulados (Pinguim BO, só SELECT): uma linha por artigo de cada documento, com a rota e o
 * promotor, o estado (válido ou anulado), o motivo da anulação que o vendedor escolheu no telemóvel (o mesmo que vai
 * para a AGT: DOCHEADEREXTRA, campo «Motivo de cancelamento», ver agt.php) e a hora a que foi registado.
 *
 * Anulado = documento cancelado no Pinguim BO (CALC_ISCANCELED), com motivo de cancelamento registado, ou num estado
 * de anulado configurado (PINGUIM_ESTADO_ANULADO) — a mesma regra das vendas (vendas.php) e da AGT.
 * Tipos: «vendas» (facturas FR/FA/FT e notas de crédito NC), «cargas» (saídas de armazém SA, guias GTC, devoluções DA e
 * entradas EA) ou «todos»; os códigos vêm do .env (MOV_TIPOS_*), como no extracto de movimentos.
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('movimentos');

const NAVAL_DOCEST_GRUPOS = ['vendas', 'cargas', 'todos'];
const NAVAL_DOCEST_DIAS_MAX = 366;

/** Códigos dos tipos de documento de um grupo (vendas, cargas); [] = todos. */
function naval_docest_tipos(string $grupo): array
{
    $t = naval_mov_tipos();
    if ($grupo === 'vendas') return array_values(array_unique(array_merge($t['venda'], $t['devolucao'])));
    if ($grupo === 'cargas') return naval_mov_tipos_par($t);
    return [];
}

/** Consulta das linhas (só SELECT). $tipos: códigos já validados (só [A-Z0-9]); [] = todos os tipos com artigos. */
function naval_docest_sql(array $tipos): string
{
    naval_servico('agt');
    $anulacao = naval_agt_campo('AGT_ANULACAO_ID', '26003');
    $cond = naval_agt_condicao($anulacao, naval_config('AGT_ANULACAO_NOME', 'Motivo de cancelamento'));
    $tipos = array_values(array_filter($tipos, function ($x) { return (bool) preg_match('/^[A-Z0-9]{1,10}$/', (string) $x); }));
    $fTipo = $tipos ? " AND LTRIM(RTRIM(DC.CODE)) IN ('" . implode("', '", $tipos) . "')" : '';
    return "
        SELECT DH.ID AS DOC_ID, CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DIA, CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
               LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
               LTRIM(RTRIM(DC.CODE)) AS TIPO, DC.DESCRIPTION AS TIPO_NOME, LTRIM(RTRIM(COALESCE(S.CODE, ''))) AS ROTA, COALESCE(S.DESCRIPTION, '') AS LOJA,
               DMD.LINE_NUMBER AS LINHA, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, COALESCE(P.DESCRIPTION, DMD.DESCRIPTION) AS ARTIGO,
               CAST(DMD.QNT AS FLOAT) AS QNT, CAST(COALESCE(DMD.CALC_TOTAL_W_TAX, DMD.TOTAL, 0) AS FLOAT) AS VALOR,
               CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT) AS CANCELADO, DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO, COALESCE(DH.ENTITY_NAME, '') AS ENTIDADE,
               CAST(COALESCE(DH.CALC_SALES_SIGNAL, 0) AS INT) AS VENDA, ANU.STRING_VALUE AS MOTIVO, CONVERT(VARCHAR(19), ANU.CLOCK_DATE, 120) AS MOTIVO_EM
        FROM DOCMOVDETAIL DMD
        INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
        OUTER APPLY (
            SELECT TOP 1 E.STRING_VALUE, E.CLOCK_DATE FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND $cond AND E.DELETED = 0 ORDER BY E.CLOCK_DATE DESC
        ) ANU
        WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND DMD.PRODUCT_CODE IS NOT NULL AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) <> ''
          AND DH.DOC_DATE >= :de AND DH.DOC_DATE < :ate$fTipo
        ORDER BY DH.DOC_DATE, DH.ID, DMD.LINE_NUMBER";
}

/**
 * Função pura (testada): as linhas da consulta → linhas do relatório. $promotores: {rota: nome}; $estadosAnulados:
 * DOCSTATUS_ID que contam como anulado; $veClientes: false tira o nome do cliente das vendas.
 */
function naval_docest_linhas(array $linhas, array $promotores, array $estadosAnulados = [], bool $veClientes = true): array
{
    $out = [];
    foreach ($linhas as $l) {
        $rota = trim((string) ($l['ROTA'] ?? ''));
        if (!preg_match('/^\d{3,5}$/', $rota) && preg_match('/^\s*(\d{3,5})/', (string) ($l['LOJA'] ?? ''), $m)) $rota = $m[1];
        $motivo = trim((string) ($l['MOTIVO'] ?? ''));
        $anulado = (int) ($l['CANCELADO'] ?? 0) === 1 || $motivo !== '' || in_array((int) ($l['ESTADO_DOCUMENTO'] ?? -1), $estadosAnulados, true);
        $out[] = [
            'doc_id' => (string) $l['DOC_ID'], 'data' => (string) $l['DIA'], 'hora' => substr((string) $l['HORA'], 11, 5), 'documento' => trim((string) $l['DOC']),
            // a loja 1000 é a Logística (documentos do armazém central: SA, EA), não um promotor
            'tipo' => trim((string) $l['TIPO']), 'tipo_nome' => trim((string) ($l['TIPO_NOME'] ?? '')), 'rota' => $rota,
            'promotor' => (string) ($promotores[$rota] ?? ($rota === '1000' ? 'Logística · armazém central' : '')),
            'codigo' => trim((string) $l['CODIGO']), 'artigo' => trim((string) ($l['ARTIGO'] ?? '')), 'quantidade' => round((float) $l['QNT'], 3),
            'valor' => round((float) $l['VALOR'], 2), 'estado' => $anulado ? 'anulado' : 'valido', 'motivo' => $anulado ? $motivo : '',
            'anulado_em' => $anulado && !empty($l['MOTIVO_EM']) ? substr((string) $l['MOTIVO_EM'], 0, 16) : '',
            'cliente' => $veClientes || empty($l['VENDA']) ? trim((string) ($l['ENTIDADE'] ?? '')) : '',
        ];
    }
    return $out;
}

/** Função pura (testada): totais por estado, por promotor (com a % de anulações) e por motivo — por documento. */
function naval_docest_resumo(array $linhas): array
{
    $docs = [];
    foreach ($linhas as $l) {
        $k = $l['doc_id'];
        $docs[$k] = $docs[$k] ?? ['rota' => $l['rota'], 'promotor' => $l['promotor'], 'estado' => $l['estado'], 'motivo' => $l['motivo'], 'valor' => 0.0];
        $docs[$k]['valor'] += $l['valor'];
    }
    $r = ['documentos' => count($docs), 'linhas' => count($linhas), 'validos' => 0, 'anulados' => 0, 'valor_valido' => 0.0, 'valor_anulado' => 0.0];
    $porRota = $porMotivo = [];
    foreach ($docs as $d) {
        $a = $d['estado'] === 'anulado';
        $r[$a ? 'anulados' : 'validos']++;
        $r[$a ? 'valor_anulado' : 'valor_valido'] += $d['valor'];
        $k = $d['rota'] !== '' ? $d['rota'] : '—';
        $porRota[$k] = $porRota[$k] ?? ['rota' => $k, 'promotor' => $d['promotor'], 'documentos' => 0, 'anulados' => 0, 'valor_anulado' => 0.0];
        $porRota[$k]['documentos']++;
        if ($a) {
            $porRota[$k]['anulados']++;
            $porRota[$k]['valor_anulado'] += $d['valor'];
            $m = $d['motivo'] !== '' ? $d['motivo'] : 'Sem motivo registado';
            $porMotivo[$m] = $porMotivo[$m] ?? ['motivo' => $m, 'documentos' => 0, 'valor' => 0.0];
            $porMotivo[$m]['documentos']++;
            $porMotivo[$m]['valor'] += $d['valor'];
        }
    }
    foreach ($porRota as &$x) {
        $x['pct_anulados'] = round($x['anulados'] / max(1, $x['documentos']), 4);
        $x['valor_anulado'] = round($x['valor_anulado'], 2);
    }
    unset($x);
    foreach ($porMotivo as &$x) $x['valor'] = round($x['valor'], 2);
    unset($x);
    ksort($porRota, SORT_NATURAL);
    usort($porMotivo, function ($a, $b) { return $b['documentos'] <=> $a['documentos']; });
    $r['valor_valido'] = round($r['valor_valido'], 2);
    $r['valor_anulado'] = round($r['valor_anulado'], 2);
    $r['pct_anulados'] = $r['documentos'] ? round($r['anulados'] / $r['documentos'], 4) : 0.0;
    return ['resumo' => $r, 'por_promotor' => array_values($porRota), 'por_motivo' => $porMotivo];
}

/** Período válido (AAAA-MM-DD), até NAVAL_DOCEST_DIAS_MAX dias. */
function naval_docest_periodo(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate . ' 12:00') - strtotime($de . ' 12:00')) / 86400 + 1 > NAVAL_DOCEST_DIAS_MAX) {
        throw new NavalErro('Escolha um período até ' . NAVAL_DOCEST_DIAS_MAX . ' dias.', 400);
    }
    return [$de, $ate];
}

/** Lê o Pinguim BO: as linhas do período e do grupo de tipos (vendas, cargas, todos). */
function naval_docest_ler(string $de, string $ate, string $grupo, bool $veClientes): array
{
    [$de, $ate] = naval_docest_periodo($de, $ate);
    if (!in_array($grupo, NAVAL_DOCEST_GRUPOS, true)) throw new NavalErro('Tipo de documentos inválido.', 400);
    naval_servico('bd');
    naval_servico('vendas');
    naval_servico('rotas');
    if (!naval_bd_configurada()) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    $l = naval_bd_consultar(naval_docest_sql(naval_docest_tipos($grupo)), [':de' => $de, ':ate' => date('Y-m-d', strtotime($ate . ' 12:00') + 86400)]);
    return naval_docest_linhas($l, naval_promotores(), naval_vendas_estados_anulados(), $veClientes);
}
