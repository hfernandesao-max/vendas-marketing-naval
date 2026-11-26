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
