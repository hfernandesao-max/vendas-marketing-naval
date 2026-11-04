<?php
// Comunicação AGT: estado de cada documento a partir de DOCHEADEREXTRA (26046 request_number, 26003 motivo de cancelamento).
naval_servico('agt');

teste('AGT: sem resposta, o pedido fica pendente e sem nada por comunicar; anulado pelo documento ou pelo motivo', function () {
    $base = ['DOC_ID' => 1, 'ROTA' => '1001 ', 'TIPO_DOC' => 'FR', 'TIPO_DESC' => 'Factura-recibo', 'DOCUMENTO' => 'FR 1/1', 'TOTAL' => 1000.004,
        'DATA_DOCUMENTO' => '2026-10-24', 'DATA_FISCAL' => '2026-10-24', 'ANULADO' => 0, 'ENTIDADE' => 'Loja A  ', 'PEDIDO_POSICAO' => 1];
    $sem = $base + ['PEDIDO_AGT' => null, 'MOTIVO_ANULACAO' => null];
    $com = $base + ['PEDIDO_AGT' => '559728934618656132', 'MOTIVO_ANULACAO' => null];
    $anuladoDoc = ['ANULADO' => 1] + $com;
    $anuladoMotivo = $com;
    $anuladoMotivo['MOTIVO_ANULACAO'] = 'Anulado';
    [$a, $b, $c, $d] = naval_agt_montar([$sem, $com, $anuladoDoc, $anuladoMotivo]);
    igual(['por_comunicar', 'sem_registo'], [$a['estado'], $a['evidencia']]);
    igual(null, $a['pedido_agt']);
    igual('1001', $a['rota']);
    igual(1000.0, $a['total']);
    igual('Loja A', $a['entidade']);
    verdade(!$a['anulado'] && !$b['anulado']);
    igual(['pendente', 'pedido'], [$b['estado'], $b['evidencia']], 'pedido sem resposta da AGT');
    igual('559728934618656132', $b['pedido_agt']);
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

teste('AGT: resposta do campo 26098 — 3 enviado, 2 pendente, 5 ou outro erro; textos de sucesso; mensagem do JSON', function () {
    $base = ['DOC_ID' => 2, 'ROTA' => '1001', 'TIPO_DOC' => 'FT', 'TIPO_DESC' => 'Factura', 'DOCUMENTO' => 'FT 1/2', 'TOTAL' => 10,
        'DATA_DOCUMENTO' => '2026-11-03', 'DATA_FISCAL' => '2026-11-03', 'ANULADO' => 0, 'ENTIDADE' => 'X', 'PEDIDO_AGT' => '123', 'MOTIVO_ANULACAO' => null,
        'ESTADO_DOCUMENTO' => 8002, 'LOJA' => 'Rota 1001', 'AGT_DATA' => '2026-11-03 10:00:00'];
    $est = function (array $x) use ($base) { return naval_agt_montar([$x + $base])[0]; };
    igual('comunicado', $est(['AGT_CODIGO' => 3, 'AGT_TEXTO' => '{}'])['estado']);
    igual('pendente', $est(['AGT_CODIGO' => 2, 'AGT_TEXTO' => '{"documentNo":"FT 1/2"}'])['estado']);
    igual('comunicado', $est(['AGT_CODIGO' => 5, 'AGT_TEXTO' => 'O documento já consta no repositório da AGT'])['estado'], 'texto de sucesso manda sobre o código');
    igual('comunicado', $est(['AGT_CODIGO' => null, 'AGT_TEXTO' => 'Process completed successfully'])['estado']);
    $e = $est(['AGT_CODIGO' => 5, 'AGT_TEXTO' => '{"errorList":[{"idError":"E40","descriptionError":"customerTaxID 5000 desconhecido no sistema da AGT"}]}']);
    igual(['erro', 'customerTaxID 5000 desconhecido no sistema da AGT'], [$e['estado'], $e['mensagem_agt']]);
    igual('8002', $e['estado_documento']);
    igual('Rota 1001', $e['loja']);
    igual('2026-11-03 10:00:00', $e['resposta_agt']);
    igual(['erro', 'Falha genérica'], [$est(['AGT_CODIGO' => 7, 'AGT_TEXTO' => 'Falha genérica'])['estado'], $est(['AGT_CODIGO' => 7, 'AGT_TEXTO' => 'Falha genérica'])['mensagem_agt']]);
});

teste('AGT: consulta com a resposta da AGT (26098, final primeiro), sempre limitada ao período', function () {
    $sql = naval_agt_sql([]);
    naval_bd_so_leitura($sql);
    verdade(strpos($sql, 'E.DOCEXTRA_ID = 26098') !== false);
    verdade(strpos($sql, 'ORDER BY CASE WHEN E.INTEGER_VALUE <> 2 THEN 0 ELSE 1 END, E.CLOCK_DATE DESC') !== false);
    verdade(strpos($sql, 'DH.DOC_DATE >= :de') !== false && strpos($sql, 'DH.DOC_DATE <= :ate') !== false);
    verdade(strpos($sql, 'DH.DOCSTATUS_ID AS ESTADO_DOCUMENTO') !== false);
});
