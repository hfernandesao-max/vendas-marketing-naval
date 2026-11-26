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

teste('natureza: transferência = saída e entrada gravadas ao mesmo tempo com a mesma quantidade; SA sem entrada = venda SAP', function () {
    $l = [
        // armazém central → carrinha 1010: SA de 400 e a guia GTC de 400 um segundo depois
        ['HORA' => '2026-11-25 07:00:00', 'DOC' => 'SA A1000/150', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -400],
        ['HORA' => '2026-11-25 07:00:01', 'DOC' => 'GTC A1000/150', 'TIPO' => 'GTC', 'ARM_COD' => '10001010', 'CODIGO' => '10000332', 'EFEITO' => 400],
        // carrinha 1003 → carrinha 1006 (SA na carrinha e GTC na outra)
        ['HORA' => '2026-11-25 08:38:53', 'DOC' => 'SA A1000/129', 'TIPO' => 'SA', 'ARM_COD' => '10001003', 'CODIGO' => '10000332', 'EFEITO' => -46],
        ['HORA' => '2026-11-25 08:38:55', 'DOC' => 'GTC A1000/133', 'TIPO' => 'GTC', 'ARM_COD' => '10001006', 'CODIGO' => '10000332', 'EFEITO' => 46],
        // devolução da carrinha ao armazém central: DA + EA (a EA gravada um segundo antes)
        ['HORA' => '2026-11-25 16:28:16', 'DOC' => 'EA A1000/34', 'TIPO' => 'EA', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => 89],
        ['HORA' => '2026-11-25 16:28:17', 'DOC' => 'DA A1000/32', 'TIPO' => 'DA', 'ARM_COD' => '10001006', 'CODIGO' => '10000332', 'EFEITO' => -89],
        // SA sem entrada noutro armazém (do central e da carrinha): vendas SAP
        ['HORA' => '2026-11-25 10:31:49', 'DOC' => 'SA A1000/98', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -150],
        ['HORA' => '2026-11-25 08:55:01', 'DOC' => 'SA A1000/118', 'TIPO' => 'SA', 'ARM_COD' => '10001008', 'CODIGO' => '10000332', 'EFEITO' => -250],
        // a mesma quantidade mas outro artigo, ou longe no tempo: não é par
        ['HORA' => '2026-11-25 12:00:00', 'DOC' => 'SA A1000/160', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'CODIGO' => '10000292', 'EFEITO' => -20],
        ['HORA' => '2026-11-25 12:00:01', 'DOC' => 'GTC A1000/170', 'TIPO' => 'GTC', 'ARM_COD' => '10001001', 'CODIGO' => '10000293', 'EFEITO' => 20],
        ['HORA' => '2026-11-25 13:00:00', 'DOC' => 'SA A1000/161', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'CODIGO' => '10000292', 'EFEITO' => -30],
        ['HORA' => '2026-11-25 13:40:00', 'DOC' => 'GTC A1000/171', 'TIPO' => 'GTC', 'ARM_COD' => '10001001', 'CODIGO' => '10000292', 'EFEITO' => 30],
        // uma SA repartida por duas guias no mesmo segundo
        ['HORA' => '2026-11-25 14:00:00', 'DOC' => 'SA A1000/162', 'TIPO' => 'SA', 'ARM_COD' => '1151', 'CODIGO' => '10000292', 'EFEITO' => -60],
        ['HORA' => '2026-11-25 14:00:01', 'DOC' => 'GTC A1000/172', 'TIPO' => 'GTC', 'ARM_COD' => '10001001', 'CODIGO' => '10000292', 'EFEITO' => 40],
        ['HORA' => '2026-11-25 14:00:01', 'DOC' => 'GTC A1000/173', 'TIPO' => 'GTC', 'ARM_COD' => '10001002', 'CODIGO' => '10000292', 'EFEITO' => 20],
        // compra (EA sem DA), vendas e nota de crédito
        ['HORA' => '2026-11-24 09:00:00', 'DOC' => 'EA A1000/31', 'TIPO' => 'EA', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => 3000],
        ['HORA' => '2026-11-25 10:00:00', 'DOC' => 'FR/21', 'TIPO' => 'FR', 'ARM_COD' => '10001010', 'CODIGO' => '10000332', 'EFEITO' => -50],
        ['HORA' => '2026-11-25 11:00:00', 'DOC' => 'FA/3', 'TIPO' => 'FA', 'ARM_COD' => '10001010', 'CODIGO' => '10000332', 'EFEITO' => -30],
        ['HORA' => '2026-11-25 12:00:00', 'DOC' => 'NC/1', 'TIPO' => 'NC', 'ARM_COD' => '10001010', 'CODIGO' => '10000332', 'EFEITO' => 5],
        // DA sem EA: outra saída
        ['HORA' => '2026-11-25 15:00:00', 'DOC' => 'DA A1000/40', 'TIPO' => 'DA', 'ARM_COD' => '10001010', 'CODIGO' => '10000332', 'EFEITO' => -3],
    ];
    $c = naval_mov_classificar($l, naval_mov_emparelhar($l));
    $n = array_column(array_map(function ($x) { return [$x['DOC'], $x['NATUREZA']]; }, $c), 1, 0);
    igual([
        'SA A1000/150' => 'transf_enviada', 'GTC A1000/150' => 'transf_recebida', 'SA A1000/129' => 'transf_enviada', 'GTC A1000/133' => 'transf_recebida',
        'EA A1000/34' => 'transf_recebida', 'DA A1000/32' => 'transf_enviada', 'SA A1000/98' => 'venda_sap', 'SA A1000/118' => 'venda_sap',
        'SA A1000/160' => 'venda_sap', 'GTC A1000/170' => 'entrada', 'SA A1000/161' => 'venda_sap', 'GTC A1000/171' => 'entrada',
        'SA A1000/162' => 'transf_enviada', 'GTC A1000/172' => 'transf_recebida', 'GTC A1000/173' => 'transf_recebida',
        'EA A1000/31' => 'entrada', 'FR/21' => 'venda', 'FA/3' => 'venda', 'NC/1' => 'devolucao', 'DA A1000/40' => 'outra_saida',
    ], $n, 'cada linha com a sua natureza');
    igual(['GTC A1000/150'], $c[0]['PAR'], 'a SA leva o documento da guia');
    igual(['GTC A1000/172', 'GTC A1000/173'], $c[12]['PAR'], 'SA repartida por duas guias');
    $r = naval_mov_montar($c);
    igual([75.0, 80.0, 5.0, 450.0, 525.0, 3050.0], [$r['resumo']['vendas_pinguim'], $r['resumo']['vendas'], $r['resumo']['devolucoes'], $r['resumo']['vendas_sap'],
        $r['resumo']['saidas_efectivas'], $r['resumo']['entradas_externas']], 'vendas Pinguim 75 + SAP 450 = saídas efectivas 525; compra 3000 + GTC sem SA 50');
    igual(['GTC A1000/150'], $r['linhas'][array_search('SA A1000/150', array_column($r['linhas'], 'doc'), true)]['par']);
    igual(['venda', 'devolucao', 'venda_sap', 'transf_enviada', 'transf_recebida', 'entrada', 'outra_saida'], array_column($r['por_natureza'], 'natureza'));
});

teste('natureza: a guia feita uns minutos depois da SA, com os mesmos artigos e quantidades, também é a transferência (documento a documento)', function () {
    $x = function ($h, $doc, $tipo, $arm, $c, $q) { return ['HORA' => '2026-11-17 ' . $h, 'DOC' => $doc, 'TIPO' => $tipo, 'ARM_COD' => $arm, 'CODIGO' => $c, 'EFEITO' => $q]; };
    $l = [
        // SA A1000/74 (4 artigos) e a guia 160 s depois com os mesmos 4 artigos: transferência
        $x('10:16:58', 'SA A1000/74', 'SA', '1151', '10000333', -20), $x('10:16:58', 'SA A1000/74', 'SA', '1151', '10000385', -30),
        $x('10:16:58', 'SA A1000/74', 'SA', '1151', '10000386', -20), $x('10:16:58', 'SA A1000/74', 'SA', '1151', '10000256', -10),
        $x('10:19:38', 'GTC A1000/80', 'GTC', '10001003', '10000333', 20), $x('10:19:38', 'GTC A1000/80', 'GTC', '10001003', '10000385', 30),
        $x('10:19:38', 'GTC A1000/80', 'GTC', '10001003', '10000386', 20), $x('10:19:38', 'GTC A1000/80', 'GTC', '10001003', '10000256', 10),
        // SA A1000/103 (3 artigos) e uma guia 390 s depois só com 2 deles: não é a mesma (fica venda SAP e entrada)
        $x('15:08:24', 'SA A1000/103', 'SA', '1151', '10000292', -10), $x('15:08:24', 'SA A1000/103', 'SA', '1151', '10000293', -10),
        $x('15:08:24', 'SA A1000/103', 'SA', '1151', '10000333', -5),
        $x('15:14:54', 'GTC A1000/110', 'GTC', '10001005', '10000292', 10), $x('15:14:54', 'GTC A1000/110', 'GTC', '10001005', '10000293', 10),
        // SA A1000/98 de 150 e a guia de 150 de outra SA (a 1 s dela): a guia já tem par, a SA/98 é venda SAP
        $x('10:31:49', 'SA A1000/98', 'SA', '1151', '10000332', -150),
        $x('10:34:41', 'SA A1000/99', 'SA', '1151', '10000332', -150), $x('10:34:42', 'GTC A1000/106', 'GTC', '10001011', '10000332', 150),
    ];
    $n = array_column(array_map(function ($y) { return [$y['DOC'] . ' ' . $y['CODIGO'], $y['NATUREZA']]; }, naval_mov_classificar($l, naval_mov_emparelhar($l))), 1, 0);
    igual('transf_enviada', $n['SA A1000/74 10000256']);
    igual('transf_recebida', $n['GTC A1000/80 10000385']);
    igual(['venda_sap', 'venda_sap', 'venda_sap', 'entrada', 'entrada'], [$n['SA A1000/103 10000292'], $n['SA A1000/103 10000293'], $n['SA A1000/103 10000333'],
        $n['GTC A1000/110 10000292'], $n['GTC A1000/110 10000293']], 'artigos diferentes: não é par');
    igual(['venda_sap', 'transf_enviada', 'transf_recebida'], [$n['SA A1000/98 10000332'], $n['SA A1000/99 10000332'], $n['GTC A1000/106 10000332']]);
    // mais de 30 minutos: não é par
    $l2 = [$x('10:00:00', 'SA/1', 'SA', '1151', '1', -5), $x('10:31:00', 'GTC/1', 'GTC', '10001001', '1', 5)];
    igual([], naval_mov_emparelhar($l2));
});

teste('natureza: a ligação do Pinguim BO (DOCHEADERSOURCE) manda — guia gerada a partir da SA; SA com a guia anulada; SA sem guia', function () {
    $x = function ($h, $doc, $tipo, $arm, $c, $q) { return ['HORA' => '2026-11-' . $h, 'DOC' => $doc, 'TIPO' => $tipo, 'ARM_COD' => $arm, 'CODIGO' => $c, 'EFEITO' => $q]; };
    $l = [
        // SA A1000/102: a guia GTC A1000/109 foi anulada (não aparece nas linhas, que só trazem documentos válidos)
        $x('19 15:04:51', 'SA A1000/102', 'SA', '1151', '10000332', -100),
        // SA A1000/74 sem guia nenhuma; a GTC A1000/84 (160 s depois, mesmos artigos) é da SA A1000/75
        $x('17 10:16:58', 'SA A1000/74', 'SA', '1151', '10000333', -20),
        $x('17 10:19:37', 'SA A1000/75', 'SA', '1151', '10000333', -20),
        $x('17 10:19:38', 'GTC A1000/84', 'GTC', '10001004', '10000333', 20),
        // ligação com a guia gravada 10 minutos depois: a ligação basta
        $x('19 15:14:52', 'SA A1000/105', 'SA', '1151', '10000292', -10),
        $x('19 15:25:00', 'GTC A1000/112', 'GTC', '10001005', '10000292', 10),
    ];
    $lig = [['de' => 'SA A1000/102', 'para' => 'GTC A1000/109', 'anulado' => true], ['de' => 'SA A1000/75', 'para' => 'GTC A1000/84', 'anulado' => false],
        ['de' => 'SA A1000/105', 'para' => 'GTC A1000/112', 'anulado' => false]];
    $c = naval_mov_classificar($l, naval_mov_emparelhar($l, NAVAL_MOV_TIPOS_OMISSAO, NAVAL_MOV_PAR_SEGUNDOS, NAVAL_MOV_PAR_DOC_SEGUNDOS, $lig));
    $n = array_column(array_map(function ($y) { return [$y['DOC'], $y['NATUREZA']]; }, $c), 1, 0);
    igual(['SA A1000/102' => 'guia_anulada', 'SA A1000/74' => 'venda_sap', 'SA A1000/75' => 'transf_enviada', 'GTC A1000/84' => 'transf_recebida',
        'SA A1000/105' => 'transf_enviada', 'GTC A1000/112' => 'transf_recebida'], $n);
    igual(['GTC A1000/109 (anulada)'], $c[0]['PAR'], 'mostra a guia anulada');
    $r = naval_mov_montar($c);
    igual([100.0, 20.0], [$r['resumo']['guias_anuladas'], $r['resumo']['vendas_sap']], 'a guia anulada não é venda');
    $b = array_column(naval_mov_balanco($r['linhas'], [], []), null, 'armazem_cod');
    igual([100.0, 20.0, 30.0, -150.0], [$b['1151']['anuladas'], $b['1151']['vendas_sap'], $b['1151']['enviado'], $b['1151']['final']]);
    // sem a ligação (tabela que não exista), a SA A1000/74 continua venda SAP: a guia A1000/84 tem par a 1 s
    igual('venda_sap', array_column(naval_mov_classificar($l, naval_mov_emparelhar($l)), 'NATUREZA', 'DOC')['SA A1000/74']);
});

teste('artigo 10000332 (Pinguim BO, 01-10 a 26-11-2026): o balanço de cada armazém dá o stock do Pinguim BO', function () {
    $l = [];
    $f = array_map(function ($s) { return explode(';', $s); }, (array) file(__DIR__ . '/dados-movimentos-10000332.csv', FILE_IGNORE_NEW_LINES));
    foreach (array_slice($f, 1) as $x) if (count($x) === 5) $l[] = ['HORA' => $x[0], 'DOC' => $x[1], 'TIPO' => $x[2], 'ARM_COD' => $x[3], 'ARM' => 'Armazém ' . $x[3],
        'CODIGO' => '10000332', 'EFEITO' => (float) $x[4]];
    igual(239, count($l), 'todas as linhas do extracto');
    $c = naval_mov_classificar($l, naval_mov_emparelhar($l));
    $r = naval_mov_montar($c);
    $s = $r['resumo'];
    igual([3000.0, 1988.0, 0.0, 998.0, 2986.0, 50.0], [$s['entradas_externas'], $s['vendas'], $s['devolucoes'], $s['vendas_sap'], $s['saidas_efectivas'], $s['acertos']],
        'compra 3000 · vendas FR 1988 · vendas SAP 998 · acertos +50');
    igual(0.0, round($s['transf_enviadas'] - $s['transf_recebidas'], 3), 'tudo o que foi transferido chegou ao outro armazém');
    $sap = array_values(array_map(function ($x) { return $x['doc'] . ' ' . $x['armazem_cod'] . ' ' . $x['saida']; },
        array_filter($r['linhas'], function ($x) { return $x['natureza'] === 'venda_sap'; })));
    igual(['SA A1000/98 1151 150', 'SA A1000/102 1151 100', 'SA A1000/113 1151 1', 'SA A1000/118 10001008 250', 'SA A1000/119 10001011 300',
        'SA A1000/144 10001011 50', 'SA A1000/154 10001009 147'], $sap, 'as 7 SA sem guia');
    // stock do Pinguim BO (dbo.GET_STOCK a 26-11-2026), confirmado com ferramentas/pinguim-artigo.php
    $pinguim = ['1151' => 0, '10001001' => 26, '10001002' => 12, '10001003' => 3, '10001004' => 15, '10001005' => 8, '10001006' => 0, '10001007' => 0,
        '10001008' => 0, '10001009' => 0, '10001010' => 0, '10001011' => 0];
    $fim = [];
    foreach ($pinguim as $a => $q) $fim[(string) $a] = ['nome' => 'Armazém ' . $a, 'q' => (float) $q];
    $b = array_column(naval_mov_balanco($r['linhas'], [], $fim), null, 'armazem_cod');
    $calc = array_map(function ($x) { return $x['final']; }, array_intersect_key($b, $pinguim));
    $esperado = array_map('floatval', $pinguim);
    ksort($calc);
    ksort($esperado);
    igual($esperado, $calc, 'stock calculado = stock do Pinguim BO');
    igual([], array_values(array_filter(array_column($b, 'diferenca'))), 'nenhuma diferença');
    igual(64.0, array_sum(array_column($b, 'final')), 'total 64');
    igual([0.0, 207.0, 153.0, 28.0, 0.0, 26.0], [$b['10001001']['inicial'], $b['10001001']['recebido'], $b['10001001']['vendas'], $b['10001001']['enviado'],
        $b['10001001']['vendas_sap'], $b['10001001']['final']], 'carrinha 1001: recebeu 207, vendeu 153, passou 28 à 1003, ficam 26');
    igual([3000.0, 179.0, 200.0, 3128.0, 251.0, 0.0], [$b['1151']['compras'], $b['1151']['recebido'], $b['1151']['acertos'], $b['1151']['enviado'], $b['1151']['vendas_sap'],
        $b['1151']['final']], 'armazém 1151: compra 3000 + devoluções 179 + acerto 200 − transferido 3128 − vendas SAP 251 = 0');
});

teste('natureza no SQL: só códigos simples vão no texto; as SA, DA, GTC e EA ficam para emparelhar em PHP', function () {
    $sql = naval_mov_sql_natureza(['venda' => ['FR', 'FA', "X'; DROP"], 'devolucao' => ['NC'], 'guia' => ['GTC'], 'saida_armazem' => [], 'devolucao_armazem' => [],
        'entrada_armazem' => []]);
    verdade(strpos($sql, "TIPO IN ('FR', 'FA')") !== false, 'vendas');
    verdade(strpos($sql, "TIPO IN ('GTC') THEN 'par'") !== false, 'guia para emparelhar');
    verdade(strpos($sql, 'DROP') === false, 'código inválido fica de fora');
    $r = naval_mov_totais([
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'venda', 'NOME' => null, 'ENT' => 0, 'SAI' => 800, 'MOV' => 10, 'DOCS' => 8],
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'devolucao', 'NOME' => null, 'ENT' => 20, 'SAI' => 0, 'MOV' => 1, 'DOCS' => 1],
        ['NIVEL' => 'nat', 'CHAVE' => '', 'NAT' => 'par', 'NOME' => null, 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'armnat', 'CHAVE' => '1151', 'NAT' => 'par', 'NOME' => null, 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'arm', 'CHAVE' => '1151', 'NOME' => 'HIGITEC', 'ENT' => 0, 'SAI' => 500, 'MOV' => 2, 'DOCS' => 2],
        ['NIVEL' => 'tot', 'CHAVE' => '', 'NOME' => null, 'ENT' => 20, 'SAI' => 1300, 'MOV' => 13, 'DOCS' => 11],
    ], [
        ['DOC' => 'SA/1', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -400, 'NATUREZA' => 'transf_enviada'],
        ['DOC' => 'SA/2', 'ARM_COD' => '1151', 'CODIGO' => '10000332', 'EFEITO' => -100, 'NATUREZA' => 'venda_sap'],
    ]);
    igual([880.0, 780.0, 400.0, 100.0], [$r['resumo']['saidas_efectivas'], $r['resumo']['vendas_pinguim'], $r['resumo']['transf_enviadas'], $r['resumo']['vendas_sap']],
        'as SA vêm das linhas emparelhadas');
    igual([400.0, 100.0], [$r['por_armazem'][0]['transf_enviadas'], $r['por_armazem'][0]['vendas_sap']]);
});
