<?php
// Rastreabilidade de um artigo: ligações entre locais a partir dos documentos (transferências, vendas, entradas, inventários).
naval_servico('rastreabilidade');

teste('rastreabilidade: guia central → carrinha, venda → clientes, nota de crédito ← clientes, compra ← fornecedores, inventário', function () {
    $local = function ($cod, $nome) { return ['id' => 'A:' . $cod, 'tipo' => $cod === '1151' ? 'central' : 'carrinha', 'cod' => $cod, 'nome' => $nome]; };
    $l = [
        ['DOC_ID' => 1, 'DOC' => 'FC/1', 'HORA' => '2026-11-20 08:00:00', 'TIPO' => 'FC', 'VENDA' => 0, 'ENTIDADE' => 'Fornecedor X', 'ARM_COD' => '1151', 'ARM' => 'HIGITEC', 'EFEITO' => 500],
        ['DOC_ID' => 2, 'DOC' => 'GTC/7', 'HORA' => '2026-11-21 07:00:00', 'TIPO' => 'GTC', 'VENDA' => 0, 'ARM_COD' => '1151', 'ARM' => 'HIGITEC', 'EFEITO' => -50],
        ['DOC_ID' => 2, 'DOC' => 'GTC/7', 'HORA' => '2026-11-21 07:00:00', 'TIPO' => 'GTC', 'VENDA' => 0, 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => 50],
        ['DOC_ID' => 3, 'DOC' => 'FR/26', 'HORA' => '2026-11-21 15:34:00', 'TIPO' => 'FR', 'VENDA' => 1, 'ENTIDADE' => 'BAILO', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => -30],
        ['DOC_ID' => 4, 'DOC' => 'NC/2', 'HORA' => '2026-11-22 10:00:00', 'TIPO' => 'NC', 'VENDA' => 1, 'ENTIDADE' => 'BAILO', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => 2],
        ['DOC_ID' => 'INV-9', 'DOC' => 'INV/9', 'HORA' => '2026-11-23 00:00:00', 'TIPO' => 'INV', 'VENDA' => 0, 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => -1],
    ];
    $r = naval_rast_montar($l, $local);
    $lig = [];
    foreach ($r['ligacoes'] as $x) $lig[$x['de'] . '>' . $x['para']] = $x['q'];
    igual(['FORN>A:1151' => 500.0, 'A:1151>A:10001010' => 50.0, 'A:10001010>CLI' => 30.0, 'CLI>A:10001010' => 2.0, 'A:10001010>INV' => 1.0], $lig);
    $nos = array_column($r['nos'], null, 'id');
    igual([500.0, 50.0], [$nos['A:1151']['entradas'], $nos['A:1151']['saidas']]);
    igual([52.0, 31.0], [$nos['A:10001010']['entradas'], $nos['A:10001010']['saidas']]);
    igual('GTC/7', $r['ligacoes'][1]['docs'][0]['doc'], 'cada ligação leva os documentos');
    igual(5, $r['documentos']);
});

teste('rastreabilidade: SA + GTC gravadas ao mesmo tempo = uma só transferência (também entre carrinhas e DA + EA); SA sem guia = vendas SAP', function () {
    $local = function ($cod, $nome) { return ['id' => 'A:' . $cod, 'tipo' => $cod === '1151' ? 'central' : 'carrinha', 'cod' => $cod, 'nome' => $nome]; };
    $l = [
        ['DOC_ID' => 10, 'DOC' => 'SA A1000/150', 'HORA' => '2026-11-25 07:00:00', 'TIPO' => 'SA', 'VENDA' => 0, 'ARM_COD' => '1151', 'ARM' => 'HIGITEC', 'EFEITO' => -400],
        ['DOC_ID' => 11, 'DOC' => 'GTC A1000/150', 'HORA' => '2026-11-25 07:00:01', 'TIPO' => 'GTC', 'VENDA' => 0, 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => 400],
        // carrinha 1010 → carrinha 1008
        ['DOC_ID' => 12, 'DOC' => 'SA A1000/151', 'HORA' => '2026-11-26 07:00:00', 'TIPO' => 'SA', 'VENDA' => 0, 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => -80],
        ['DOC_ID' => 13, 'DOC' => 'GTC A1000/151', 'HORA' => '2026-11-26 07:00:01', 'TIPO' => 'GTC', 'VENDA' => 0, 'ARM_COD' => '10001008', 'ARM' => 'Carrinha 1008', 'EFEITO' => 80],
        // devolução da carrinha 1008 ao armazém central (DA + EA)
        ['DOC_ID' => 14, 'DOC' => 'EA A1000/34', 'HORA' => '2026-11-27 16:28:16', 'TIPO' => 'EA', 'VENDA' => 0, 'ARM_COD' => '1151', 'ARM' => 'HIGITEC', 'EFEITO' => 30],
        ['DOC_ID' => 15, 'DOC' => 'DA A1000/32', 'HORA' => '2026-11-27 16:28:17', 'TIPO' => 'DA', 'VENDA' => 0, 'ARM_COD' => '10001008', 'ARM' => 'Carrinha 1008', 'EFEITO' => -30],
        // SA sem guia: venda feita no SAP; guia sem SA: vem de fornecedores e entradas
        ['DOC_ID' => 16, 'DOC' => 'SA A1000/152', 'HORA' => '2026-11-27 10:00:00', 'TIPO' => 'SA', 'VENDA' => 0, 'ARM_COD' => '1151', 'ARM' => 'HIGITEC', 'EFEITO' => -20],
        ['DOC_ID' => 17, 'DOC' => 'GTC A1000/153', 'HORA' => '2026-11-28 07:00:00', 'TIPO' => 'GTC', 'VENDA' => 0, 'ARM_COD' => '10001008', 'ARM' => 'Carrinha 1008', 'EFEITO' => 10],
        // venda FA sem o sinal de venda no cabeçalho: vai na mesma para clientes
        ['DOC_ID' => 18, 'DOC' => 'FA/3', 'HORA' => '2026-11-26 12:00:00', 'TIPO' => 'FA', 'VENDA' => 0, 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'EFEITO' => -20],
    ];
    $r = naval_rast_montar($l, $local);
    $lig = [];
    foreach ($r['ligacoes'] as $x) $lig[$x['de'] . '>' . $x['para']] = [$x['q'], array_column($x['docs'], 'doc')];
    igual([400.0, ['SA A1000/150', 'GTC A1000/150']], $lig['A:1151>A:10001010'], 'SA e guia na mesma seta');
    igual([80.0, ['SA A1000/151', 'GTC A1000/151']], $lig['A:10001010>A:10001008'], 'entre carrinhas');
    igual([30.0, ['EA A1000/34', 'DA A1000/32']], $lig['A:10001008>A:1151'], 'devolução da carrinha ao armazém central');
    igual([20.0, ['SA A1000/152']], $lig['A:1151>SAP'], 'SA sem guia: vendas SAP');
    igual([10.0, ['GTC A1000/153']], $lig['FORN>A:10001008']);
    igual([20.0, ['FA/3']], $lig['A:10001010>CLI']);
    verdade(!isset($lig['A:1151>SAI']) && !isset($lig['FORN>A:10001010']), 'a SA com guia não é saída nem a guia entrada externa');
    $nos = array_column($r['nos'], null, 'id');
    igual([420.0, 30.0], [$nos['A:1151']['saidas'], $nos['A:1151']['entradas']]);
});
