<?php
// Comunicação AGT: estado de cada documento a partir de DOCHEADEREXTRA (26046 request_number, 26003 motivo de cancelamento).
naval_servico('agt');

teste('AGT: com pedido comunicado, sem pedido por comunicar; anulado pelo documento ou pelo motivo', function () {
    $base = ['DOC_ID' => 1, 'ROTA' => '1001 ', 'TIPO_DOC' => 'FR', 'TIPO_DESC' => 'Factura-recibo', 'DOCUMENTO' => 'FR 1/1', 'TOTAL' => 1000.004,
        'DATA_DOCUMENTO' => '2026-10-24', 'DATA_FISCAL' => '2026-10-24', 'ANULADO' => 0, 'ENTIDADE' => 'Loja A  ', 'PEDIDO_POSICAO' => 1];
    $sem = $base + ['PEDIDO_AGT' => null, 'MOTIVO_ANULACAO' => null];
    $com = $base + ['PEDIDO_AGT' => '559728934618656132', 'MOTIVO_ANULACAO' => null];
    $anuladoDoc = ['ANULADO' => 1] + $com;
    $anuladoMotivo = $com;
    $anuladoMotivo['MOTIVO_ANULACAO'] = 'Anulado';
    [$a, $b, $c, $d] = naval_agt_montar([$sem, $com, $anuladoDoc, $anuladoMotivo]);
    igual('por_comunicar', $a['estado']);
    igual(null, $a['pedido_agt']);
    igual('1001', $a['rota']);
    igual(1000.0, $a['total']);
    igual('Loja A', $a['entidade']);
    verdade(!$a['anulado'] && !$b['anulado']);
    igual('comunicado', $b['estado']);
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
