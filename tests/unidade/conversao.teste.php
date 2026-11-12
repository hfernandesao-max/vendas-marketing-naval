<?php
// Conversão das visitas (nucleo/servicos/conversao.php): plano congelado × compras × visitas do OLAP.
naval_servico('conversao');

$GLOBALS['conv_fontes'] = function () {
    $planos = [
        '2026-10-26' => ['1001' => [['c' => 'A', 'n' => 'Loja A', 'media' => 50000], ['c' => 'B', 'n' => 'Loja B', 'media' => 80000], ['c' => 'C', 'n' => 'Loja C', 'media' => 10000]]],
        '2026-10-27' => null,   // dia sem plano guardado
    ];
    $ag = ['2026-10-26' => ['clientes' => ['A' => ['r' => '1001', 't' => 60000], 'X' => ['r' => '1001', 't' => 20000], 'Y' => ['r' => '1002', 't' => 5000]]]];
    return [$planos, $ag];
};

teste('conversão do plano, vendas fora do plano, peso do plano e planeados sem compra para seguir', function () {
    [$planos, $ag] = $GLOBALS['conv_fontes']();
    $r = naval_conv_calcular($planos, $ag, null, null, ['1001' => 'Andrea']);
    $p = array_column($r['rotas'], null, 'rota')['1001'];
    igual([3, 1, 0.333, 1, 0.75], [$p['planeados'], $p['planeados_compra'], $p['conversao_plano'], $p['fora_plano'], $p['peso_plano']]);
    igual(null, $p['conversao_visitas'], 'sem OLAP: por ligar');
    igual(['B', 'C'], array_column(array_filter($r['seguir'], function ($x) { return $x['rota'] === '1001'; }), 'c'), 'maior compra média primeiro');
    igual(['2026-10-27'], $r['dias_sem_plano']);
    igual(false, $r['olap']);
    $s = array_column($r['rotas'], null, 'rota')['1002'];
    igual([0, 1, null], [$s['planeados'], $s['fora_plano'], $s['conversao_plano']], 'rota sem plano: só vendas fora do plano');
    igual([3, 1, 0.333, 2], [$r['total']['planeados'], $r['total']['planeados_compra'], $r['total']['conversao_plano'], $r['total']['fora_plano']], 'total das rotas');
    igual(['1001'], array_column(naval_conv_calcular($planos, $ag, null, ['1001'])['rotas'], 'rota'), 'âmbito por rota');
});

teste('com o OLAP: cobertura do plano e conversão das visitas', function () {
    [$planos, $ag] = $GLOBALS['conv_fontes']();
    $r = naval_conv_calcular($planos, $ag, ['2026-10-26' => ['1001' => ['A', 'B', 'X', 'Z']]], ['1001']);
    $p = $r['rotas'][0];
    igual([4, 2, 2, 0.667, 0.5], [$p['visitados'], $p['visitados_compra'], $p['planeados_visitados'], $p['cobertura'], $p['conversao_visitas']]);
});

teste('plano do dia: congelado na primeira leitura; dias passados sem plano não se reconstroem', function () {
    $hoje = date('Y-m-d');
    @unlink(naval_conv_ficheiro($hoje));
    $cart = ['dia' => $hoje, 'clientes' => [
        'A' => ['c' => 'A', 'n' => 'Loja A', 'r' => '1001', 'dia' => naval_dia_semana($hoje), 'media' => 100.0, 'proxima' => '2099-01-01', 'estado' => 'em_dia'],
        'B' => ['c' => 'B', 'n' => 'Loja B', 'r' => '1001', 'dia' => (naval_dia_semana($hoje) % 7) + 1, 'media' => 50.0, 'proxima' => '2099-01-01', 'estado' => 'em_dia'],
    ]];
    $p = naval_conv_plano($hoje, $cart);
    igual(['A'], array_column($p['1001'], 'c'), 'só o cliente do dia habitual');
    $cart['clientes']['B']['dia'] = naval_dia_semana($hoje);
    igual(['A'], array_column(naval_conv_plano($hoje, $cart)['1001'], 'c'), 'mudou a carteira mas o plano do dia está congelado');
    igual(null, naval_conv_plano('2020-01-01', $cart));
});
