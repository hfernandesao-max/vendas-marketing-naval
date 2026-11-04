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
 * Os identificadores podem mudar-se no .env: AGT_PEDIDO_ID e AGT_ANULACAO_ID. Como cada tipo de documento / série
 * pode ter o seu próprio campo extra com o mesmo nome, contam também todos os campos chamados request_number
 * (AGT_PEDIDO_NOME) e «Motivo de cancelamento» (AGT_ANULACAO_NOME); o valor pode estar em texto ou em número.
 *
 * Estado da comunicação (como no Pinguim BO, 04-11-2026): o Pinguim BO grava num campo extra o estado devolvido pela
 * AGT («Enviado», «Erro» ou «Pendente»; campo com a descrição «Estado AGT») e, quando a AGT recusa, a mensagem de erro
 * (ex.: «customerTaxID … desconhecido no sistema da AGT»). Regra, do mais forte para o mais fraco:
 *   1. estado registado: Erro → com erro; Pendente → pendente; Enviado → comunicado;
 *   2. mensagem de erro da AGT sem estado → com erro;
 *   3. número do pedido (request_number) → comunicado;
 *   4. sem nada registado → comunicado «sem registo de erro», como o Pinguim BO o mostra (AGT_SEM_REGISTO=enviado;
 *      com AGT_SEM_REGISTO=por_comunicar fica por comunicar).
 * Cada documento de vendas do período aparece uma vez, incluindo os anulados; a consulta é sempre limitada ao período.
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

/**
 * Condição SQL para os campos extra de um nome (ex.: request_number). No Pinguim BO cada tipo de documento / série
 * pode ter o seu próprio campo extra com o mesmo nome (identificadores diferentes): procurar só pelo identificador
 * fixo deixava como «por comunicar» todos os documentos das outras séries. Usa o identificador configurado e, se a
 * tabela DOCEXTRA tiver uma coluna de nome (NAME, CODE, DESCRIPTION…), todos os campos com esse nome.
 * $colunas: colunas de DOCEXTRA (testes); null = ler do INFORMATION_SCHEMA.
 */
function naval_agt_condicao(string $id, string $nome, ?array $colunas = null): string
{
    if ($colunas === null) {
        $colunas = [];
        try {
            $colunas = array_column(naval_bd_consultar("
                SELECT UPPER(COLUMN_NAME) AS C FROM INFORMATION_SCHEMA.COLUMNS
                WHERE UPPER(TABLE_NAME) = 'DOCEXTRA' AND (UPPER(COLUMN_NAME) = 'ID' OR DATA_TYPE IN ('varchar', 'nvarchar', 'char', 'nchar'))"), 'C');
        } catch (Throwable $e) {
            $colunas = [];
        }
    }
    $nome = preg_replace('/[^A-Za-z0-9_ ]/', '', $nome);
    $cols = array_values(array_intersect(['NAME', 'CODE', 'DESCRIPTION', 'KEYNAME', 'LABEL', 'FIELD_NAME'], array_map('strtoupper', $colunas)));
    if (!$cols || $nome === '' || !in_array('ID', array_map('strtoupper', $colunas), true)) return "E.DOCEXTRA_ID = $id";
    $or = implode(' OR ', array_map(function ($c) use ($nome) { return "LOWER(LTRIM(RTRIM(X.$c))) = '" . strtolower($nome) . "'"; }, $cols));
    return "(E.DOCEXTRA_ID = $id OR E.DOCEXTRA_ID IN (SELECT X.ID FROM DOCEXTRA X WHERE $or))";
}

/** Textos das mensagens de erro da AGT (AGT_ERRO_TEXTOS, separados por «|»), limpos para entrarem num LIKE. */
function naval_agt_textos_erro(): array
{
    $out = [];
    foreach (explode('|', (string) naval_config('AGT_ERRO_TEXTOS', 'customerTaxID|desconhecido no sistema da AGT')) as $x) {
        $x = trim((string) preg_replace("/[^\\p{L}\\p{N} ._-]/u", '', $x));
        if ($x !== '') $out[] = $x;
    }
    return $out;
}

/** Consulta: documentos de vendas do período, o estado e a mensagem da AGT, o pedido e o motivo de anulação. */
function naval_agt_sql(?array $colunasExtra = null): string
{
    $pedido = naval_agt_campo('AGT_PEDIDO_ID', '26046');
    $anulacao = naval_agt_campo('AGT_ANULACAO_ID', '26003');
    $condPedido = naval_agt_condicao($pedido, naval_config('AGT_PEDIDO_NOME', 'request_number'), $colunasExtra);
    $condAnulacao = naval_agt_condicao($anulacao, naval_config('AGT_ANULACAO_NOME', 'Motivo de cancelamento'), $colunasExtra);
    $erros = '';
    foreach (naval_agt_textos_erro() as $x) $erros .= "E.STRING_VALUE LIKE '%" . $x . "%' OR ";
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
            DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO,
            COALESCE(STORE.DESCRIPTION, STORE.CODE) AS LOJA,
            EST.STRING_VALUE AS ESTADO_AGT,
            MSG.STRING_VALUE AS MENSAGEM_AGT,
            PED.STRING_VALUE AS PEDIDO_AGT,
            CAST(PED.NDX AS INT) AS PEDIDO_POSICAO,
            ANU.STRING_VALUE AS MOTIVO_ANULACAO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
        OUTER APPLY (
            -- o número do pedido pode vir em texto ou em número (INTEGER_VALUE), conforme a versão do Pinguim BO
            SELECT TOP 1 COALESCE(NULLIF(LTRIM(RTRIM(E.STRING_VALUE)), ''), CAST(NULLIF(E.INTEGER_VALUE, 0) AS VARCHAR(30))) AS STRING_VALUE, E.NDX
            FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND $condPedido AND E.DELETED = 0
              AND COALESCE(NULLIF(LTRIM(RTRIM(E.STRING_VALUE)), ''), CAST(NULLIF(E.INTEGER_VALUE, 0) AS VARCHAR(30))) IS NOT NULL
            ORDER BY E.CLOCK_DATE DESC
        ) PED
        OUTER APPLY (
            SELECT TOP 1 E.STRING_VALUE
            FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND $condAnulacao AND E.DELETED = 0
            ORDER BY E.CLOCK_DATE DESC
        ) ANU
        OUTER APPLY (
            -- estado devolvido pela AGT (o registo mais recente)
            SELECT TOP 1 LTRIM(RTRIM(E.STRING_VALUE)) AS STRING_VALUE
            FROM DOCHEADEREXTRA E
            LEFT JOIN DOCEXTRA X ON X.ID = E.DOCEXTRA_ID
            WHERE E.DOCHEADER_ID = DH.ID AND E.DELETED = 0
              AND (LTRIM(RTRIM(E.STRING_VALUE)) IN ('Enviado', 'Erro', 'Pendente')
                   OR X.DESCRIPTION LIKE '%Estado AGT%' OR X.DESCRIPTION LIKE '%AGT%Status%')
            ORDER BY E.CLOCK_DATE DESC
        ) EST
        OUTER APPLY (
            -- mensagem de erro da AGT (o registo mais recente)
            SELECT TOP 1 E.STRING_VALUE
            FROM DOCHEADEREXTRA E
            LEFT JOIN DOCEXTRA X ON X.ID = E.DOCEXTRA_ID
            WHERE E.DOCHEADER_ID = DH.ID AND E.DELETED = 0
              AND ($erros X.DESCRIPTION LIKE '%Erro%AGT%' OR X.DESCRIPTION LIKE '%Mensagem%AGT%')
            ORDER BY E.CLOCK_DATE DESC
        ) MSG
        WHERE DH.CALC_SALES_SIGNAL <> 0
          AND DH.DOC_DATE >= :de
          AND DH.DOC_DATE <= :ate
        ORDER BY DH.DOC_DATE DESC, DH.ID DESC";
}

/** Estado da comunicação (regra no topo do ficheiro): estado, evidência, pedido e mensagem da AGT. */
function naval_agt_estado(array $l): array
{
    $pedido = trim((string) ($l['PEDIDO_AGT'] ?? ''));
    $est = strtolower(trim((string) ($l['ESTADO_AGT'] ?? '')));
    $msg = trim((string) ($l['MENSAGEM_AGT'] ?? ''));
    $base = ['pedido_agt' => $pedido !== '' ? $pedido : null, 'mensagem_agt' => $msg !== '' ? naval_texto($msg, 300) : null];
    if ($est === 'erro' || ($est === '' && $msg !== '')) return ['estado' => 'erro', 'evidencia' => $est === 'erro' ? 'estado' : 'mensagem'] + $base;
    if ($est === 'pendente') return ['estado' => 'pendente', 'evidencia' => 'estado'] + $base;
    if ($est === 'enviado') return ['estado' => 'comunicado', 'evidencia' => 'estado'] + $base;
    if ($pedido !== '') return ['estado' => 'comunicado', 'evidencia' => 'pedido'] + $base;
    $semRegisto = naval_config('AGT_SEM_REGISTO', 'enviado') === 'por_comunicar' ? 'por_comunicar' : 'comunicado';
    return ['estado' => $semRegisto, 'evidencia' => 'sem_registo'] + $base;
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
            'loja' => trim((string) ($l['LOJA'] ?? '')),
            'estado_documento' => isset($l['ESTADO_DOCUMENTO']) ? (string) $l['ESTADO_DOCUMENTO'] : null,
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
