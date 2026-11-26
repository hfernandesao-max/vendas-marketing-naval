<?php
// Extracto dos movimentos de stock: entradas e saídas por documento, resumo por artigo e saldo corrido.
naval_servico('movimentos');

/** Só as chaves de base (entradas, saídas…), sem os totais por natureza. */
function teste_mov_base(array $a, array $k = ['movimentos', 'documentos', 'entradas', 'saidas', 'liquido', 'codigo', 'artigo']): array
{
    return array_intersect_key($a, array_flip($k));
}

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
    igual(['movimentos' => 3, 'documentos' => 3, 'entradas' => 50.0, 'saidas' => 51.0, 'liquido' => -1.0], teste_mov_base($r['resumo']));
    igual([70.0, 69.0], [$r['saldo_inicial'], $r['saldo_final']]);
    igual([['codigo' => '10000332', 'artigo' => 'FRALDAS NAVAL', 'entradas' => 50.0, 'saidas' => 51.0, 'liquido' => -1.0]], array_map('teste_mov_base', $r['por_artigo']));
    igual([['FR', 0.0, 51.0, -51.0, 2], ['GTC', 50.0, 0.0, 50.0, 1]], array_map(function ($x) { return [$x['tipo'], $x['entradas'], $x['saidas'], $x['liquido'], $x['documentos']]; }, $r['por_tipo']), 'totais por tipo');
    igual([['100010', 50.0, 51.0, -1.0, 3]], array_map(function ($x) { return [$x['armazem_cod'], $x['entradas'], $x['saidas'], $x['liquido'], $x['documentos']]; }, $r['por_armazem']), 'totais por armazém');
    // sem um só armazém e artigo: sem saldo
    verdade(!isset(naval_mov_montar($l)['linhas'][0]['saldo']), 'sem saldo');
});

teste('extracto de movimentos: período até 366 dias (um ano)', function () {
    igual(['2025-11-27', '2026-11-27'], naval_mov_periodo('2025-11-27', '2026-11-27'), 'um ano e um dia cabe');
    $erro = '';
    try { naval_mov_periodo('2025-11-01', '2026-11-30'); } catch (NavalErro $e) { $erro = $e->getMessage(); }
    verdade(strpos($erro, '366 dias') !== false, 'mais de 366 dias recusa');
});

teste('extracto de movimentos: com mais de 20 000 linhas, os totais vêm agregados do SQL (documentos e inventários somados)', function () {
    $l = [
        ['NIVEL' => 'tipo', 'CHAVE' => 'FR', 'NOME' => 'Factura-recibo', 'ENT' => 0, 'SAI' => 900, 'MOV' => 30000, 'DOCS' => 12000],
        ['NIVEL' => 'tipo', 'CHAVE' => 'GTC', 'NOME' => 'Guia de transporte', 'ENT' => 1000, 'SAI' => 0, 'MOV' => 400, 'DOCS' => 200],
        ['NIVEL' => 'arm', 'CHAVE' => '10001010', 'NOME' => 'Carrinha 1010', 'ENT' => 1000, 'SAI' => 900, 'MOV' => 30400, 'DOCS' => 12200],
        ['NIVEL' => 'art', 'CHAVE' => '10000332', 'NOME' => 'FRALDAS NAVAL', 'ENT' => 1000, 'SAI' => 900, 'MOV' => 30400, 'DOCS' => 12200],
        ['NIVEL' => 'tot', 'CHAVE' => '', 'NOME' => null, 'ENT' => 1000, 'SAI' => 900, 'MOV' => 30400, 'DOCS' => 12200],
        // inventários (segunda consulta)
        ['NIVEL' => 'tipo', 'CHAVE' => 'INV', 'NOME' => 'Inventário', 'ENT' => 0, 'SAI' => 2, 'MOV' => 1, 'DOCS' => 1],
        ['NIVEL' => 'arm', 'CHAVE' => '10001010', 'NOME' => 'Carrinha 1010', 'ENT' => 0, 'SAI' => 2, 'MOV' => 1, 'DOCS' => 1],
        ['NIVEL' => 'art', 'CHAVE' => '10000332', 'NOME' => 'FRALDAS NAVAL', 'ENT' => 0, 'SAI' => 2, 'MOV' => 1, 'DOCS' => 1],
        ['NIVEL' => 'tot', 'CHAVE' => '', 'NOME' => null, 'ENT' => 0, 'SAI' => 2, 'MOV' => 1, 'DOCS' => 1],
    ];
    $r = naval_mov_totais($l);
    igual(['movimentos' => 30401, 'documentos' => 12201, 'entradas' => 1000.0, 'saidas' => 902.0, 'liquido' => 98.0], teste_mov_base($r['resumo']));
    igual(['GTC', 'FR', 'INV'], array_column($r['por_tipo'], 'tipo'), 'do maior para o menor movimento');
    igual(['tipo' => 'FR', 'tipo_nome' => 'Factura-recibo', 'entradas' => 0.0, 'saidas' => 900.0, 'liquido' => -900.0, 'documentos' => 12000], $r['por_tipo'][1]);
    igual([['armazem_cod' => '10001010', 'armazem' => 'Carrinha 1010', 'entradas' => 1000.0, 'saidas' => 902.0, 'liquido' => 98.0, 'documentos' => 12201]], array_map(function ($a) { return teste_mov_base($a, ['armazem_cod', 'armazem', 'entradas', 'saidas', 'liquido', 'documentos']); }, $r['por_armazem']));
    igual([['codigo' => '10000332', 'artigo' => 'FRALDAS NAVAL', 'entradas' => 1000.0, 'saidas' => 902.0, 'liquido' => 98.0]], array_map('teste_mov_base', $r['por_artigo']));
    verdade(strpos(naval_mov_sql_totais('SELECT 1') , 'GROUPING SETS') !== false, 'agregado no SQL Server');
});

teste('natureza: só FR e FA são saídas efectivas e a NC abate-as; SA do armazém central com guia para a carrinha é transferência', function () {
    $l = [
        // armazém central → carrinha 1010: SA de 400 e a guia GTC de 400 no mesmo dia
        ['HORA' => '2026-11-25 07:00:00', 'DOC' => 'SA A1000/150', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'ARM' => 'HIGITEC PIV - NON FOOD', 'CODIGO' => '10000332', 'EFEITO' => -400, 'CARRINHA' => false],
        ['HORA' => '2026-11-25 07:05:00', 'DOC' => 'GTC A1000/150', 'TIPO' => 'GTC', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'EFEITO' => 400, 'CARRINHA' => true],
        // SA de 120 com guia de 100 no dia seguinte: 100 transferência, 20 sem guia
        ['HORA' => '2026-11-25 17:00:00', 'DOC' => 'SA A1000/151', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'ARM' => 'HIGITEC PIV - NON FOOD', 'CODIGO' => '10000292', 'EFEITO' => -120, 'CARRINHA' => false],
        // vendas e nota de crédito na carrinha
        ['HORA' => '2026-11-25 10:00:00', 'DOC' => 'FR/21', 'TIPO' => 'FR', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'EFEITO' => -50, 'CARRINHA' => true],
        ['HORA' => '2026-11-25 11:00:00', 'DOC' => 'FA/3', 'TIPO' => 'FA', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'EFEITO' => -30, 'CARRINHA' => true],
        ['HORA' => '2026-11-25 12:00:00', 'DOC' => 'NC/1', 'TIPO' => 'NC', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'EFEITO' => 5, 'CARRINHA' => true],
        // SA na carrinha (a Logística tira mercadoria): outra saída, nunca transferência
        ['HORA' => '2026-11-25 16:00:00', 'DOC' => 'SA A1000/152', 'TIPO' => 'SA', 'ARM_COD' => '10001010', 'ARM' => 'Carrinha 1010', 'CODIGO' => '10000332', 'EFEITO' => -10, 'CARRINHA' => true],
        // compra no armazém central
        ['HORA' => '2026-11-24 09:00:00', 'DOC' => 'VFA/9', 'TIPO' => 'VFA', 'ARM_COD' => '1151', 'ARM' => 'HIGITEC PIV - NON FOOD', 'CODIGO' => '10000332', 'EFEITO' => 1000, 'CARRINHA' => false],
    ];
    $guias = ['10000332' => ['2026-11-25' => 400], '10000292' => ['2026-11-26' => 100]];
    $c = naval_mov_classificar($l, $guias);
    $n = array_map(function ($x) { return [$x['DOC'], $x['NATUREZA'], $x['EFEITO']]; }, $c);
    igual([
        ['VFA/9', 'entrada', 1000], ['SA A1000/150', 'transf_enviada', -400.0], ['GTC A1000/150', 'transf_recebida', 400], ['FR/21', 'venda', -50], ['FA/3', 'venda', -30],
        ['NC/1', 'devolucao', 5], ['SA A1000/152', 'outra_saida', -10], ['SA A1000/151', 'transf_enviada', -100.0], ['SA A1000/151', 'sem_guia', -20.0],
    ], $n, 'cada linha com a sua natureza; a SA sem guia completa divide-se');
    $r = naval_mov_montar($c);
    igual(75.0, $r['resumo']['saidas_efectivas'], 'FR 50 + FA 30 − NC 5');
    igual([80.0, 5.0, 500.0, 400.0, 1000.0, 20.0, 10.0], [$r['resumo']['vendas'], $r['resumo']['devolucoes'], $r['resumo']['transf_enviadas'], $r['resumo']['transf_recebidas'],
        $r['resumo']['entradas_externas'], $r['resumo']['sem_guia'], $r['resumo']['outras_saidas']]);
    $arm = array_column($r['por_armazem'], null, 'armazem_cod');
    igual([0.0, 500.0, 20.0], [$arm['1151']['saidas_efectivas'], $arm['1151']['transf_enviadas'], $arm['1151']['sem_guia']], 'armazém central: nada vendido, 500 transferidos');
    igual([75.0, 400.0, 10.0], [$arm['10001010']['saidas_efectivas'], $arm['10001010']['transf_recebidas'], $arm['10001010']['outras_saidas']], 'carrinha: vendeu 75, recebeu 400');
    igual(['venda', 'devolucao', 'transf_enviada', 'transf_recebida', 'entrada', 'sem_guia', 'outra_saida'], array_column($r['por_natureza'], 'natureza'));
    igual('transf_enviada', $r['linhas'][1]['natureza']);
    // sem guias: a SA do armazém central fica sem guia
    igual('sem_guia', naval_mov_classificar([$l[0]])[0]['NATUREZA']);
});

teste('natureza no SQL: só códigos simples vão no texto; a SA de saída fica para ligar às guias', function () {
    $sql = naval_mov_sql_natureza(['venda' => ['FR', 'FA', "X'; DROP"], 'devolucao' => ['NC'], 'guia' => ['GTC'], 'saida_armazem' => []]);
    verdade(strpos($sql, "TIPO IN ('FR', 'FA')") !== false, 'vendas');
    verdade(strpos($sql, 'DROP') === false, 'código inválido fica de fora');
    verdade(strpos($sql, '1 = 0') !== false, 'lista vazia não casa nada');
    $r = naval_mov_totais([
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'venda', 'NOME' => null, 'ENT' => 0, 'SAI' => 800, 'MOV' => 10, 'DOCS' => 8],
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'devolucao', 'NOME' => null, 'ENT' => 20, 'SAI' => 0, 'MOV' => 1, 'DOCS' => 1],
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'sa', 'NOME' => null, 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'armnat', 'CHAVE' => '1151', 'NAT' => 'sa', 'NOME' => null, 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'arm', 'CHAVE' => '1151', 'NOME' => 'HIGITEC', 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'tot', 'CHAVE' => '', 'NOME' => null, 'ENT' => 20, 'SAI' => 1300, 'MOV' => 13, 'DOCS' => 11],
    ], [
        ['DOC' => 'SA/1', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -400, 'NATUREZA' => 'transf_enviada'],
        ['DOC' => 'SA/2', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -100, 'NATUREZA' => 'sem_guia'],
    ]);
    igual([780.0, 400.0, 100.0], [$r['resumo']['saidas_efectivas'], $r['resumo']['transf_enviadas'], $r['resumo']['sem_guia']], 'a SA vem das linhas classificadas');
    igual([400.0, 100.0], [$r['por_armazem'][0]['transf_enviadas'], $r['por_armazem'][0]['sem_guia']]);
});
