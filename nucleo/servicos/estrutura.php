<?php
/**
 * Estrutura da base de dados do Pinguim BO (super administrador), só com SELECT: tipos de documento do
 * período, meios de pagamento, lojas/rotas e tabelas com o número aproximado de linhas. Serve para
 * encontrar dados ainda não usados pelo portal (por exemplo, o fecho de dia do RTM). Sem dados pessoais.
 */
defined('NAVAL_NUCLEO') || exit;

function naval_bd_estrutura(string $inicio, string $fim): array
{
    $p = [':de' => naval_bd_data($inicio), ':ate' => naval_bd_data($fim)];
    $out = ['origem' => 'SQL Server (' . naval_config('DB_NAME', 'PingWinBO') . ')', 'periodo' => naval_data_pt($p[':de']) . ' a ' . naval_data_pt($p[':ate'])];
    $out['tipos_de_documento'] = naval_bd_consultar("
        SELECT DC.CODE AS codigo, DC.DESCRIPTION AS descricao, COUNT(*) AS documentos,
               CAST(SUM(DH.CALC_TOTAL_W_TAX * DH.CALC_SALES_SIGNAL) AS FLOAT) AS total
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        WHERE DH.DELETED = 0 AND DH.DOC_DATE >= :de AND DH.DOC_DATE <= :ate
        GROUP BY DC.CODE, DC.DESCRIPTION
        ORDER BY COUNT(*) DESC", $p);
    $out['meios_de_pagamento'] = naval_bd_consultar('SELECT CODE AS codigo, DESCRIPTION AS descricao FROM PAYMETHOD ORDER BY CODE');
    $out['lojas'] = naval_bd_consultar('SELECT CODE AS codigo, DESCRIPTION AS descricao FROM STORE ORDER BY CODE');
    try {
        // Tabelas e linhas aproximadas (SQL Server); noutras bases de dados só os nomes
        $out['tabelas'] = naval_bd_consultar("
            SELECT T.name AS tabela, SUM(P.rows) AS linhas
            FROM sys.tables T
            INNER JOIN sys.partitions P ON P.object_id = T.object_id AND P.index_id IN (0, 1)
            GROUP BY T.name
            ORDER BY T.name");
    } catch (Throwable $e) {
        $out['tabelas'] = naval_bd_consultar("SELECT TABLE_NAME AS tabela FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    }
    // Tabelas que podem ter o fecho de dia / caixa
    $out['possivel_fecho_de_dia'] = array_values(array_filter(array_column($out['tabelas'], 'tabela'), function ($n) {
        return preg_match('/CLOS|CASH|FECH|CAIXA|SHIFT|TURN|DAYEND|ZREPORT|SESSION/i', (string) $n) === 1;
    }));
    return $out;
}
