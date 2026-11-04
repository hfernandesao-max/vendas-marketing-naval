<?php
// Comunicação AGT: colunas e estados da consulta validada no Pinguim BO (campo 26098), anulação pelo motivo (26003).
naval_servico('agt');

$agtBase = ['DOC_ID' => 2, 'ROTA' => '1001 ', 'LOJA' => 'Rota 1001', 'TIPO_DOC' => 'FR', 'TIPO_DESC' => 'Factura-recibo', 'DOCUMENTO' => 'FR FR0126S78519N 6',
    'DOCUMENTO_AGT' => 'FR FR0126S78519N/6', 'TOTAL' => '1000.00', 'DATA_DOCUMENTO' => '2026-11-03', 'DATA_FISCAL' => '2026-11-03', 'ENTIDADE' => 'Loja A  ',
    'ESTADO_DOCUMENTO' => 8002, 'DATA_DOCUMENTO_TXT' => '03/11/2026', 'DATA_FISCAL_TXT' => '03/11/2026', 'AGT_DATA' => '2026-11-03 10:00:00', 'MOTIVO_ANULACAO' => null];

teste('AGT: Estado AGT e Mensagem de erro tal como vêm da consulta; estado interno para indicadores e alertas', function () use ($agtBase) {
    $est = function (array $x) use ($agtBase) { return naval_agt_montar([$x + $agtBase])[0]; };
    $a = $est(['ESTADO_AGT' => 'Enviado', 'MENSAGEM_ERRO' => '', 'AGT_CODIGO' => 3]);
    igual(['comunicado', 'Enviado', null, ''], [$a['estado'], $a['estado_agt'], $a['erro_tipo'], $a['mensagem_erro']]);
    igual('FR FR0126S78519N/6', $a['documento_agt']);
    igual('FR FR0126S78519N 6', $a['documento']);
    igual(['1001', 'Rota 1001', 'Loja A', '8002', 1000.0], [$a['rota'], $a['loja'], $a['entidade'], $a['estado_documento'], $a['total']]);
    verdade(!$a['anulado']);
    igual(['03/11/2026', '03/11/2026', '2026-11-03'], [$a['data_documento_txt'], $a['data_fiscal_txt'], $a['data']], 'datas como na consulta');
    igual('pendente', $est(['ESTADO_AGT' => 'Pendente', 'MENSAGEM_ERRO' => '', 'AGT_CODIGO' => 2])['estado']);
    $json = '{"errorList":[{"idError":"E40","descriptionError":"customerTaxID 5000 desconhecido no sistema da AGT"}]}';
    $e = $est(['ESTADO_AGT' => 'Erro', 'MENSAGEM_ERRO' => $json, 'AGT_CODIGO' => 5]);
    igual(['erro', 'erro', $json], [$e['estado'], $e['erro_tipo'], $e['mensagem_erro']], 'mensagem sem alterações');
    igual('customerTaxID 5000 desconhecido no sistema da AGT', $e['mensagem_agt'], 'texto legível para os alertas');
    $n = $est(['ESTADO_AGT' => 'Não enviado', 'MENSAGEM_ERRO' => 'Falha genérica', 'AGT_CODIGO' => 7]);
    igual(['erro', 'nao_enviado', 'Falha genérica'], [$n['estado'], $n['erro_tipo'], $n['mensagem_erro']]);
    $m = $est(['ESTADO_AGT' => 'Enviado', 'MENSAGEM_ERRO' => '', 'MOTIVO_ANULACAO' => 'Anulado']);
    verdade($m['anulado']);
    igual('Anulado', $m['motivo_anulacao']);
});

teste('AGT: consulta só de leitura igual à validada (CROSS APPLY 26098, estados, mensagem), limitada ao período', function () {
    $sql = naval_agt_sql([]);
    naval_bd_so_leitura($sql);   // lança excepção se não for só SELECT
    verdade(strpos($sql, 'CROSS APPLY') !== false, 'só documentos que comunicaram com a AGT');
    verdade(strpos($sql, 'DHE.DOCEXTRA_ID = 26098') !== false);
    verdade(strpos($sql, 'ORDER BY CASE WHEN DHE.INTEGER_VALUE <> 2 THEN 0 ELSE 1 END, DHE.CLOCK_DATE DESC') !== false);
    foreach (["WHEN AGT.INTEGER_VALUE = 3 THEN 'Enviado'", "WHEN AGT.INTEGER_VALUE = 5 THEN 'Erro'", "WHEN AGT.INTEGER_VALUE = 2 THEN 'Pendente'",
        "ELSE 'Não enviado'", "AGT.STRING_VALUE LIKE N'%Process completed successfully%'", "AGT.STRING_VALUE LIKE N'%já consta no repositório%'",
        'ELSE AGT.STRING_VALUE', 'DH.CALC_TOTAL_W_TAX * COALESCE(DH.CALC_SALES_SIGNAL, 1)', 'COALESCE(DH.ENTITY_NAME, E.DESCRIPTION)',
        "WHEN DH.DOC_PREFIX LIKE '%/' THEN", 'WHERE DH.DELETED = 0', 'ORDER BY S.CODE ASC, DH.DOC_NUMBER DESC', 'CONVERT(VARCHAR(10), DH.DOC_DATE, 103)', 'CONVERT(VARCHAR(10), DH.FISCAL_DATE, 103)', 'DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO'] as $x)
        verdade(strpos($sql, $x) !== false, $x);
    verdade(strpos($sql, 'DH.DOC_DATE >= :de') !== false && strpos($sql, 'DH.DOC_DATE <= :ate') !== false);
    verdade(strpos($sql, 'E.DOCEXTRA_ID = 26003') !== false);
});

teste('AGT: motivo de cancelamento com o mesmo nome noutras séries (DOCEXTRA por nome)', function () {
    $sql = naval_agt_sql(['ID', 'DESCRIPTION', 'CODE']);
    naval_bd_so_leitura($sql);
    verdade(strpos($sql, "LOWER(LTRIM(RTRIM(X.DESCRIPTION))) = 'motivo de cancelamento'") !== false);
    verdade(strpos($sql, '(E.DOCEXTRA_ID = 26003 OR E.DOCEXTRA_ID IN (SELECT X.ID FROM DOCEXTRA X WHERE') !== false);
});
