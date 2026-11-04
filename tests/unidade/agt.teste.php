<?php
// Comunicação AGT: estado de cada documento a partir de DOCHEADEREXTRA (26046 request_number, 26003 motivo de cancelamento).
naval_servico('agt');

teste('AGT: com pedido comunicado, sem registo enviado (como no Pinguim BO); anulado pelo documento ou pelo motivo', function () {
    $base = ['DOC_ID' => 1, 'ROTA' => '1001 ', 'TIPO_DOC' => 'FR', 'TIPO_DESC' => 'Factura-recibo', 'DOCUMENTO' => 'FR 1/1', 'TOTAL' => 1000.004,
        'DATA_DOCUMENTO' => '2026-10-24', 'DATA_FISCAL' => '2026-10-24', 'ANULADO' => 0, 'ENTIDADE' => 'Loja A  ', 'PEDIDO_POSICAO' => 1];
    $sem = $base + ['PEDIDO_AGT' => null, 'MOTIVO_ANULACAO' => null];
    $com = $base + ['PEDIDO_AGT' => '559728934618656132', 'MOTIVO_ANULACAO' => null];
    $anuladoDoc = ['ANULADO' => 1] + $com;
    $anuladoMotivo = $com;
    $anuladoMotivo['MOTIVO_ANULACAO'] = 'Anulado';
    [$a, $b, $c, $d] = naval_agt_montar([$sem, $com, $anuladoDoc, $anuladoMotivo]);
    igual('comunicado', $a['estado'], 'sem estado nem erro registado: enviado, como o Pinguim BO mostra');
    igual('sem_registo', $a['evidencia']);
    igual(null, $a['pedido_agt']);
    igual('1001', $a['rota']);
    igual(1000.0, $a['total']);
    igual('Loja A', $a['entidade']);
    verdade(!$a['anulado'] && !$b['anulado']);
    igual('comunicado', $b['estado']);
    igual('559728934618656132', $b['pedido_agt']);
    igual('pedido', $b['evidencia']);
    verdade($c['anulado'] && $d['anulado']);
    igual('Anulado', $d['motivo_anulacao']);
});

teste('AGT: consulta só de leitura com os campos extra 26046 e 26003', function () {
    $sql = naval_agt_sql([]);
    naval_bd_so_leitura($sql);   // lança excepção se não for só SELECT
    verdade(strpos($sql, 'E.DOCEXTRA_ID = 26046') !== false);
    verdade(strpos($sql, 'E.DOCEXTRA_ID = 26003') !== false);
    verdade(strpos($sql, 'INTEGER_VALUE') !== false, 'pedido também em número');
});

teste('AGT: campos extra com o mesmo nome noutras séries (DOCEXTRA por nome)', function () {
    $sql = naval_agt_sql(['ID', 'DESCRIPTION', 'CODE']);
    naval_bd_so_leitura($sql);
    verdade(strpos($sql, "LOWER(LTRIM(RTRIM(X.CODE))) = 'request_number'") !== false, $sql);
    verdade(strpos($sql, "LOWER(LTRIM(RTRIM(X.DESCRIPTION))) = 'motivo de cancelamento'") !== false);
    verdade(strpos($sql, '(E.DOCEXTRA_ID = 26046 OR E.DOCEXTRA_ID IN (SELECT X.ID FROM DOCEXTRA X WHERE') !== false);
});

teste('AGT: estado devolvido pela AGT (Enviado, Erro, Pendente) e mensagem de erro mandam sobre o pedido', function () {
    $base = ['DOC_ID' => 2, 'ROTA' => '1001', 'TIPO_DOC' => 'FT', 'TIPO_DESC' => 'Factura', 'DOCUMENTO' => 'FT 1/2', 'TOTAL' => 10,
        'DATA_DOCUMENTO' => '2026-11-03', 'DATA_FISCAL' => '2026-11-03', 'ANULADO' => 0, 'ENTIDADE' => 'X', 'PEDIDO_AGT' => '123', 'MOTIVO_ANULACAO' => null,
        'ESTADO_DOCUMENTO' => 8002, 'LOJA' => 'Rota 1001'];
    $erro = naval_agt_montar([$base + ['ESTADO_AGT' => 'Erro', 'MENSAGEM_AGT' => 'customerTaxID 5000 desconhecido no sistema da AGT']])[0];
    igual(['erro', 'estado', 'customerTaxID 5000 desconhecido no sistema da AGT'], [$erro['estado'], $erro['evidencia'], $erro['mensagem_agt']]);
    igual('8002', $erro['estado_documento']);
    igual('Rota 1001', $erro['loja']);
    $so = naval_agt_montar([$base + ['ESTADO_AGT' => null, 'MENSAGEM_AGT' => 'desconhecido no sistema da AGT']])[0];
    igual(['erro', 'mensagem'], [$so['estado'], $so['evidencia']], 'mensagem de erro sem estado: com erro');
    igual('pendente', naval_agt_montar([$base + ['ESTADO_AGT' => 'Pendente', 'MENSAGEM_AGT' => null]])[0]['estado']);
    $env = naval_agt_montar([$base + ['ESTADO_AGT' => ' enviado ', 'MENSAGEM_AGT' => null]])[0];
    igual(['comunicado', 'estado'], [$env['estado'], $env['evidencia']]);
});

teste('AGT: consulta com o estado e a mensagem da AGT, sempre limitada ao período', function () {
    $sql = naval_agt_sql([]);
    naval_bd_so_leitura($sql);
    verdade(strpos($sql, "IN ('Enviado', 'Erro', 'Pendente')") !== false);
    verdade(strpos($sql, "X.DESCRIPTION LIKE '%Estado AGT%'") !== false);
    verdade(strpos($sql, "E.STRING_VALUE LIKE '%customerTaxID%'") !== false);
    verdade(strpos($sql, "E.STRING_VALUE LIKE '%desconhecido no sistema da AGT%'") !== false);
    verdade(strpos($sql, 'DH.DOC_DATE >= :de') !== false && strpos($sql, 'DH.DOC_DATE <= :ate') !== false);
    verdade(strpos($sql, 'DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO') !== false);
});
