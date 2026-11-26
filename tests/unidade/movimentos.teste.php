<?php
// Extracto dos movimentos de stock: entradas e saídas por documento, resumo por artigo e saldo corrido.
naval_servico('movimentos');

teste('extracto de movimentos: entradas e saídas por ordem cronológica, resumo por artigo e saldo corrido (um armazém e um artigo)', function () {
    $l = [
        ['HORA' => '2026-11-25 16:59:00', 'DOC' => 'FR0126S77782N/26', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ARM_COD' => '100010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'ARTIGO' => 'FRALDAS NAVAL', 'EFEITO' => -1],
        ['HORA' => '2026-11-25 07:10:00', 'DOC' => 'GTC A1000/150', 'TIPO' => 'GTC', 'TIPO_NOME' => 'Guia de transporte', 'ARM_COD' => '100010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'ARTIGO' => 'FRALDAS NAVAL', 'EFEITO' => 50],
        ['HORA' => '2026-11-25 10:00:00', 'DOC' => 'FR0126S77782N/21', 'TIPO' => 'FR', 'TIPO_NOME' => 'Factura-recibo', 'ARM_COD' => '100010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'ARTIGO' => 'FRALDAS NAVAL', 'EFEITO' => -50],
        ['HORA' => '2026-11-25 11:00:00', 'DOC' => 'X/1', 'TIPO' => 'X', 'ARM_COD' => '100010', 'CODIGO' => '10000332', 'EFEITO' => 0],
    ];
    $r = naval_mov_montar($l, 70.0);
    igual(['GTC A1000/150', 'FR0126S77782N/21', 'FR0126S77782N/26'], array_column($r['linhas'], 'doc'), 'por hora; linhas sem efeito ficam de fora');
    igual([120.0, 70.0, 69.0], array_column($r['linhas'], 'saldo'), 'saldo depois de cada movimento');
    igual([50.0, 0.0], [$r['linhas'][0]['entrada'], $r['linhas'][0]['saida']]);
    igual(['movimentos' => 3, 'documentos' => 3, 'entradas' => 50.0, 'saidas' => 51.0, 'liquido' => -1.0], $r['resumo']);
    igual([70.0, 69.0], [$r['saldo_inicial'], $r['saldo_final']]);
    igual([['codigo' => '10000332', 'artigo' => 'FRALDAS NAVAL', 'entradas' => 50.0, 'saidas' => 51.0, 'liquido' => -1.0]], $r['por_artigo']);
    igual([['FR', 0.0, 51.0, -51.0, 2], ['GTC', 50.0, 0.0, 50.0, 1]], array_map(function ($x) { return [$x['tipo'], $x['entradas'], $x['saidas'], $x['liquido'], $x['documentos']]; }, $r['por_tipo']), 'totais por tipo');
    igual([['100010', 50.0, 51.0, -1.0, 3]], array_map(function ($x) { return [$x['armazem_cod'], $x['entradas'], $x['saidas'], $x['liquido'], $x['documentos']]; }, $r['por_armazem']), 'totais por armazém');
    // sem um só armazém e artigo: sem saldo
    verdade(!isset(naval_mov_montar($l)['linhas'][0]['saldo']), 'sem saldo');
});

teste('extracto de movimentos: período até 31 dias', function () {
    igual(['2026-11-01', '2026-11-30'], naval_mov_periodo('2026-11-01', '2026-11-30'));
    $erro = '';
    try { naval_mov_periodo('2026-10-01', '2026-11-30'); } catch (NavalErro $e) { $erro = $e->getMessage(); }
    verdade(strpos($erro, '31 dias') !== false, 'mais de 31 dias recusa');
});
