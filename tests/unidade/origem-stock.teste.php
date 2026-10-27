<?php
// SAP storage location pela origem do stock (armazéns principais → viaturas).
naval_servico('origem-stock');

teste('origem do stock: rota do armazém da viatura pelo código ou pelo nome', function () {
    igual('1007', naval_origem_rota('10001007'));
    igual('1011', naval_origem_rota('X', 'Armazém 1011 - Viatura'));
    igual('', naval_origem_rota('1151', 'HIGITEC PIV - NON FOOD'));
});

teste('origem do stock: cada artigo pelo armazém de onde veio mais; a rota pela localização predominante', function () {
    $o = naval_origem_montar([
        ['1001', '1151', 'FRALDA', 100], ['1001', '1150', 'OLEO', 30], ['1001', '1150', 'FRALDA', 5],
        ['1002', '1150', 'OLEO', 50], ['1002', '9999', 'OUTRO', 10], ['1003', '1151', 'SABAO', -20],
    ], NAVAL_ARMAZENS_PRINCIPAIS, 'teste', '');
    igual('RTM2', $o['rotas']['1001']['artigos']['FRALDA']);
    igual('RTM1', $o['rotas']['1001']['artigos']['OLEO']);
    igual('RTM2', $o['rotas']['1001']['local']);
    igual('RTM1', $o['rotas']['1002']['local']);
    verdade(!isset($o['rotas']['1002']['artigos']['OUTRO']), 'armazém que não é principal é ignorado');
    igual('RTM2', $o['rotas']['1003']['local'], 'saldo negativo conta como movimento');
    igual('RTM1', $o['artigos']['OLEO']);
});

teste('origem do stock: a partir dos saldos (artigo que saiu do armazém principal e está na viatura)', function () {
    $o = naval_origem_saldos(NAVAL_ARMAZENS_PRINCIPAIS, [
        'linhas_armazens' => [
            ['cod_armazem' => '1151', 'codigo' => 'F1', 'quantidade' => -300],
            ['cod_armazem' => '1150', 'codigo' => 'A1', 'quantidade' => -40],
            ['cod_armazem' => '1150', 'codigo' => 'Z0', 'quantidade' => 0],
        ],
        'linhas_carrinhas' => [
            ['data' => '2026-10-20', 'rota' => '1001', 'codigo' => 'F1', 'quantidade' => 56],
            ['data' => '2026-10-24', 'rota' => '1004', 'codigo' => 'A1', 'quantidade' => 12],
            ['data' => '2026-10-24', 'rota' => '1004', 'codigo' => 'Z0', 'quantidade' => 3],
        ],
    ]);
    igual('RTM2', $o['rotas']['1001']['local']);
    igual('RTM1', $o['rotas']['1004']['local']);
    igual('20-10-2026 a 24-10-2026', $o['periodo']);
    igual(null, naval_origem_saldos(NAVAL_ARMAZENS_PRINCIPAIS, ['linhas_armazens' => [], 'linhas_carrinhas' => []]));
});

teste('origem do stock: a consulta das transferências passa a guarda de só leitura', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/nucleo/servicos/origem-stock.php');
    preg_match('/naval_bd_consultar\("(.*?)" \. implode/s', $src, $m);
    verdade(!empty($m[1]), 'consulta encontrada');
    naval_bd_so_leitura($m[1] . ':p0, :p1)
            GROUP BY EV.CODE');
});
