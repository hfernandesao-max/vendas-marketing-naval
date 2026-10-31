<?php
/**
 * Comunicação dos documentos à AGT (Administração Geral Tributária), lida do Pinguim BO só com SELECT.
 *
 * Fonte: DOCHEADEREXTRA (campos extra de cada documento: DOCHEADER_ID, DOCEXTRA_ID, NDX, CLOCK_DATE,
 * INTEGER_VALUE, STRING_VALUE, DELETED), ligada ao DOCHEADER por DOCHEADER_ID. Campos extra usados
 * (tabela DOCEXTRA do Pinguim BO, confirmados em 30-10-2026):
 *   26046 request_number          → número do pedido de comunicação à AGT (STRING_VALUE); NDX = posição no envio
 *   26003 Motivo de cancelamento  → anulação do documento (STRING_VALUE = motivo, ex. «Anulado»)
 * (Os outros campos extra, 26039 «no GUI» e 26064 «EventSet ID», não são da AGT.)
 * Os identificadores podem mudar-se no .env: AGT_PEDIDO_ID e AGT_ANULACAO_ID.
 *
 * Cada documento de vendas do período aparece uma vez, com ou sem pedido à AGT (sem pedido = por comunicar),
 * incluindo os anulados. O Pinguim BO guarda o pedido, não a resposta da AGT: a página não mostra erros da AGT.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';

/** Identificador de um campo extra (só dígitos, para entrar na consulta sem risco). */
function naval_agt_campo(string $chave, string $omissao): string
{
    $v = trim((string) naval_config($chave, $omissao));
    return preg_match('/^\d{1,18}$/', $v) === 1 ? $v : $omissao;
}

/** Consulta: documentos de vendas do período, o pedido à AGT e o motivo de anulação de cada um. */
function naval_agt_sql(): string
{
    $pedido = naval_agt_campo('AGT_PEDIDO_ID', '26046');
    $anulacao = naval_agt_campo('AGT_ANULACAO_ID', '26003');
    return "
        SELECT
            DH.ID AS DOC_ID,
            COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA,
            DC.CODE AS TIPO_DOC,
            DC.DESCRIPTION AS TIPO_DESC,
            RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
            CAST(CASE WHEN DH.CALC_SALES_SIGNAL < 0 THEN -ABS(DH.CALC_TOTAL_W_TAX) ELSE DH.CALC_TOTAL_W_TAX END AS FLOAT) AS TOTAL,
            CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA_DOCUMENTO,
            CONVERT(VARCHAR(10), DH.FISCAL_DATE, 120) AS DATA_FISCAL,
            CAST(DH.DELETED AS INT) AS ANULADO,
            DH.ENTITY_NAME AS ENTIDADE,
            PED.STRING_VALUE AS PEDIDO_AGT,
            CAST(PED.NDX AS INT) AS PEDIDO_POSICAO,
            ANU.STRING_VALUE AS MOTIVO_ANULACAO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
        OUTER APPLY (
            SELECT TOP 1 E.STRING_VALUE, E.NDX
            FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND E.DOCEXTRA_ID = $pedido AND E.DELETED = 0
            ORDER BY E.CLOCK_DATE DESC
        ) PED
        OUTER APPLY (
            SELECT TOP 1 E.STRING_VALUE
            FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND E.DOCEXTRA_ID = $anulacao AND E.DELETED = 0
            ORDER BY E.CLOCK_DATE DESC
        ) ANU
        WHERE DH.CALC_SALES_SIGNAL <> 0
          AND DH.DOC_DATE >= :de
          AND DH.DOC_DATE <= :ate
        ORDER BY DH.DOC_DATE DESC, DH.ID DESC";
}

/** Estado da comunicação: com número de pedido → comunicado; sem pedido → por comunicar. */
function naval_agt_estado(array $l): array
{
    $pedido = trim((string) ($l['PEDIDO_AGT'] ?? ''));
    if ($pedido === '') return ['estado' => 'por_comunicar', 'pedido_agt' => null];
    return ['estado' => 'comunicado', 'pedido_agt' => $pedido];
}

/** Linhas da consulta → documentos para o portal (separado para os testes). */
function naval_agt_montar(array $linhas): array
{
    $promotores = naval_promotores();
    $docs = [];
    foreach ($linhas as $l) {
        $rota = trim((string) ($l['ROTA'] ?? ''));
        $motivo = trim((string) ($l['MOTIVO_ANULACAO'] ?? ''));
        $docs[] = [
            'chave' => (string) $l['DOC_ID'],
            'rota' => $rota,
            'promotor' => $promotores[$rota] ?? '',
            'tipo' => (string) $l['TIPO_DESC'],
            'tipo_codigo' => (string) $l['TIPO_DOC'],
            'documento' => (string) $l['DOCUMENTO'],
            'total' => round((float) $l['TOTAL'], 2),
            'data' => (string) $l['DATA_DOCUMENTO'],
            'data_fiscal' => $l['DATA_FISCAL'] !== null ? (string) $l['DATA_FISCAL'] : null,
            // Anulado: marcado no documento ou com motivo de cancelamento registado
            'anulado' => (int) $l['ANULADO'] === 1 || $motivo !== '',
            'motivo_anulacao' => $motivo !== '' ? $motivo : null,
            'entidade' => trim((string) ($l['ENTIDADE'] ?? '')),
        ] + naval_agt_estado($l);
    }
    return $docs;
}

/** Documentos do período (datas AAAAMMDD) com o estado da comunicação à AGT. Lança excepção sem base de dados. */
function naval_agt(string $inicio, string $fim): array
{
    $p = [':de' => naval_bd_data($inicio), ':ate' => naval_bd_data($fim)];
    return ['de' => $p[':de'], 'ate' => $p[':ate'], 'documentos' => naval_agt_montar(naval_bd_consultar(naval_agt_sql(), $p))];
}
