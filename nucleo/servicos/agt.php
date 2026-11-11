<?php
/**
 * Comunicação dos documentos à AGT (Administração Geral Tributária), lida do Pinguim BO só com SELECT.
 *
 * Fonte: DOCHEADEREXTRA (campos extra de cada documento: DOCHEADER_ID, DOCEXTRA_ID, NDX, CLOCK_DATE,
 * INTEGER_VALUE, STRING_VALUE, DELETED), ligada ao DOCHEADER por DOCHEADER_ID. Campos extra usados
 * (tabela DOCEXTRA do Pinguim BO):
 *   26098 resposta da AGT         → estado e texto da resposta (AGT_ESTADO_ID)
 *   26003 Motivo de cancelamento  → anulação do documento (STRING_VALUE = motivo, ex. «Anulado»; AGT_ANULACAO_ID)
 * Como cada tipo de documento / série pode ter o seu próprio campo de anulação com o mesmo nome, conta também todos os
 * campos chamados «Motivo de cancelamento» (AGT_ANULACAO_NOME).
 *
 * Estado da comunicação (consulta do Pinguim BO validada pelo utilizador em 04-11-2026, colunas e estados sem alterações):
 * campo extra 26098 (AGT_ESTADO_ID), a resposta da AGT a cada envio. Só entram os documentos com este registo (CROSS
 * APPLY) e não eliminados (DH.DELETED = 0). Um documento pode ter vários registos: conta a resposta final
 * (INTEGER_VALUE diferente de 2) mais recente. Estado AGT, pela ordem:
 *   INTEGER_VALUE 3 → «Enviado»; 5 → «Erro»; 2 → «Pendente»;
 *   texto «Process completed successfully» ou «já consta no repositório» (AGT_SUCESSO_TEXTOS) → «Enviado»;
 *   outro → «Não enviado».
 * Mensagem de erro: vazia quando enviado ou pendente; nos outros casos, o texto da resposta tal como está.
 * A consulta do portal é sempre limitada ao período escolhido.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';
require_once __DIR__ . '/vendas.php';

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

/** Textos que a AGT devolve quando aceita o documento (AGT_SUCESSO_TEXTOS, separados por «|»), limpos para um LIKE. */
function naval_agt_textos_sucesso(): array
{
    $out = [];
    foreach (explode('|', (string) naval_config('AGT_SUCESSO_TEXTOS', 'Process completed successfully|já consta no repositório')) as $x) {
        $x = trim((string) preg_replace("/[^\\p{L}\\p{N} ._-]/u", '', $x));
        if ($x !== '') $out[] = $x;
    }
    return $out;
}

/** Expressões do estado AGT e da mensagem de erro (iguais à consulta validada; textos de sucesso do .env). */
function naval_agt_casos(): array
{
    $likes = implode(' OR ', array_map(function ($x) { return "AGT.STRING_VALUE LIKE N'%" . $x . "%'"; }, naval_agt_textos_sucesso() ?: ['Process completed successfully']));
    $estado = "CASE
                WHEN AGT.INTEGER_VALUE = 3 THEN 'Enviado'
                WHEN AGT.INTEGER_VALUE = 5 THEN 'Erro'
                WHEN AGT.INTEGER_VALUE = 2 THEN 'Pendente'
                WHEN $likes THEN 'Enviado'
                ELSE 'Não enviado'
            END";
    $mensagem = "CASE
                WHEN AGT.INTEGER_VALUE = 3 OR $likes THEN ''
                WHEN AGT.INTEGER_VALUE = 2 THEN ''
                ELSE AGT.STRING_VALUE
            END";
    return [$estado, $mensagem];
}

/** Consulta validada (colunas e estados do Pinguim BO), limitada ao período e com a rota e o motivo de anulação. */
function naval_agt_sql(?array $colunasExtra = null): string
{
    $anulacao = naval_agt_campo('AGT_ANULACAO_ID', '26003');
    $condAnulacao = naval_agt_condicao($anulacao, naval_config('AGT_ANULACAO_NOME', 'Motivo de cancelamento'), $colunasExtra);
    $estadoId = naval_agt_campo('AGT_ESTADO_ID', '26098');
    [$estado, $mensagem] = naval_agt_casos();
    return "
        SELECT
            DH.ID AS DOC_ID,
            COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA,
            COALESCE(S.DESCRIPTION, S.CODE) AS LOJA,
            DC.CODE AS TIPO_DOC,
            DC.DESCRIPTION AS TIPO_DESC,
            CASE
                WHEN DH.DOC_PREFIX LIKE '%/' THEN RTRIM(DH.DOC_PREFIX) + CAST(DH.DOC_NUMBER AS VARCHAR(20))
                ELSE RTRIM(DH.DOC_PREFIX) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20))
            END AS DOCUMENTO_AGT,
            RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
            CAST(DH.CALC_TOTAL_W_TAX * COALESCE(DH.CALC_SALES_SIGNAL, 1) AS DECIMAL(18, 2)) AS TOTAL,
            DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO,
            CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA_DOCUMENTO,
            CONVERT(VARCHAR(10), DH.FISCAL_DATE, 120) AS DATA_FISCAL,
            CONVERT(VARCHAR(10), DH.DOC_DATE, 103) AS DATA_DOCUMENTO_TXT,
            CONVERT(VARCHAR(10), DH.FISCAL_DATE, 103) AS DATA_FISCAL_TXT,
            COALESCE(DH.ENTITY_NAME, E.DESCRIPTION) AS ENTIDADE,
            $estado AS ESTADO_AGT,
            $mensagem AS MENSAGEM_ERRO,
            CAST(AGT.INTEGER_VALUE AS INT) AS AGT_CODIGO,
            CONVERT(VARCHAR(19), AGT.CLOCK_DATE, 120) AS AGT_DATA,
            ANU.STRING_VALUE AS MOTIVO_ANULACAO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        LEFT JOIN ENTITY E ON E.ID = DH.ENTITY_ID
        -- só os documentos que comunicaram com a AGT (campo 26098); a resposta final (código diferente de 2) primeiro
        CROSS APPLY (
            SELECT TOP 1 DHE.STRING_VALUE, DHE.INTEGER_VALUE, DHE.CLOCK_DATE
            FROM DOCHEADEREXTRA DHE
            WHERE DHE.DOCHEADER_ID = DH.ID AND DHE.DOCEXTRA_ID = $estadoId AND DHE.DELETED = 0
            ORDER BY CASE WHEN DHE.INTEGER_VALUE <> 2 THEN 0 ELSE 1 END, DHE.CLOCK_DATE DESC
        ) AGT
        OUTER APPLY (
            SELECT TOP 1 E.STRING_VALUE
            FROM DOCHEADEREXTRA E
            WHERE E.DOCHEADER_ID = DH.ID AND $condAnulacao AND E.DELETED = 0
            ORDER BY E.CLOCK_DATE DESC
        ) ANU
        WHERE DH.DELETED = 0
          AND DH.DOC_DATE >= :de
          AND DH.DOC_DATE <= :ate
        ORDER BY S.CODE ASC, DH.DOC_NUMBER DESC";
}

/** Mensagem legível da resposta da AGT: o texto, ou num JSON a primeira mensagem/descrição/erro que tiver. */
function naval_agt_mensagem(string $t): string
{
    $t = trim($t);
    $j = $t !== '' && ($t[0] === '{' || $t[0] === '[') ? json_decode($t, true) : null;
    if (is_array($j)) {
        // primeiro descrições e mensagens; só depois campos de erro (que podem ser códigos, ex. «E40»)
        $achar = function ($v, string $padrao) use (&$achar) {
            if (!is_array($v)) return null;
            foreach ($v as $k => $x) {
                if (is_string($x) && $x !== '' && preg_match($padrao, (string) $k)) return $x;
            }
            foreach ($v as $x) if (($r = $achar($x, $padrao)) !== null) return $r;
            return null;
        };
        $m = $achar($j, '/mens|message|descr|motivo|reason|texto|text/i') ?? $achar($j, '/erro|error/i');
        if ($m !== null) return naval_texto($m, 300);
    }
    return naval_texto($t, 300);
}

/**
 * Estado da comunicação a partir das colunas da consulta: «Estado AGT» e «Mensagem de erro» tal como vêm do Pinguim BO,
 * e o estado interno do portal (comunicado, pendente ou erro) para indicadores, alertas e qualidade dos dados.
 */
function naval_agt_estado(array $l): array
{
    $rotulo = trim((string) ($l['ESTADO_AGT'] ?? ''));
    $msg = (string) ($l['MENSAGEM_ERRO'] ?? '');
    $mapa = ['Enviado' => 'comunicado', 'Pendente' => 'pendente', 'Erro' => 'erro', 'Não enviado' => 'erro'];
    if (!isset($mapa[$rotulo])) $rotulo = 'Não enviado';
    $estado = $mapa[$rotulo];
    return [
        'estado' => $estado,
        'estado_agt' => $rotulo,
        'erro_tipo' => $estado === 'erro' ? ($rotulo === 'Erro' ? 'erro' : 'nao_enviado') : null,
        'mensagem_erro' => $msg,
        'mensagem_agt' => $estado === 'erro' ? (trim($msg) !== '' ? naval_agt_mensagem($msg) : $rotulo) : null,
        'codigo_agt' => isset($l['AGT_CODIGO']) && $l['AGT_CODIGO'] !== '' ? (int) $l['AGT_CODIGO'] : null,
        'resposta_agt' => isset($l['AGT_DATA']) ? (string) $l['AGT_DATA'] : null,
    ];
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
            'documento_agt' => (string) ($l['DOCUMENTO_AGT'] ?? $l['DOCUMENTO']),
            // datas como na consulta validada (dd/mm/aaaa); data e data_fiscal (aaaa-mm-dd) para filtros e alertas
            'data_documento_txt' => isset($l['DATA_DOCUMENTO_TXT']) ? (string) $l['DATA_DOCUMENTO_TXT'] : null,
            'data_fiscal_txt' => isset($l['DATA_FISCAL_TXT']) ? (string) $l['DATA_FISCAL_TXT'] : null,
            'total' => round((float) $l['TOTAL'], 2),
            'data' => (string) $l['DATA_DOCUMENTO'],
            'data_fiscal' => $l['DATA_FISCAL'] !== null ? (string) $l['DATA_FISCAL'] : null,
            // Anulado: motivo de cancelamento registado ou, se configurado, um estado de anulado (PINGUIM_ESTADO_ANULADO)
            'anulado' => $motivo !== '' || (isset($l['ESTADO_DOCUMENTO']) && in_array((int) $l['ESTADO_DOCUMENTO'], naval_vendas_estados_anulados(), true)),
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
