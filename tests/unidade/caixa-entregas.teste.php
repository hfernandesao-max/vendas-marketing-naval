<?php
// Entrega do dinheiro pelo vendedor (app Naval Operações): declaração por notas, correcção até a tesouraria contar,
// conferência declarado × contado × Pinguim BO e o pendente da tesouraria.
naval_servico('caixa-entregas');

teste('entrega de dinheiro: o vendedor declara por notas; corrige até a tesouraria contar; depois fica bloqueada', function () {
    $dia = date('Y-m-d');
    $d = naval_cxe_declarar($dia, '1004', ['5000' => 10, '1000' => 3, 'moedas' => 150], 'pro4');
    igual([53150.0, 'pro4', 'declarada'], [$d['total'], $d['por'], $d['historico'][0]['accao']]);
    $d = naval_cxe_declarar($dia, '1004', ['5000' => 10, '2000' => 1], 'pro4');
    igual([52000.0, 2, 'corrigida'], [$d['total'], count($d['historico']), $d['historico'][1]['accao']]);
    igual(['1004'], array_map('strval', array_keys(naval_cxe_ler($dia, ['1004', '1005']))));
    igual([], naval_cxe_ler($dia, ['1005']), 'só as rotas da pessoa');
    lanca(400, function () use ($dia) { naval_cxe_declarar($dia, '1004', ['3000' => 1], 'pro4'); });   // nota que não existe
    lanca(400, function () use ($dia) { naval_cxe_declarar($dia, '1004', [], 'pro4'); });
    // pendente da tesouraria: dinheiro para contar
    naval_servico('pendentes');
    $p = array_values(array_filter(naval_pendentes('tes', null, ['tesouraria' => true], time(), [])['itens'], function ($x) { return $x['tipo'] === 'entrega' && $x['rota'] === '1004'; }));
    igual([52000.0, 'pro4'], [$p[0]['valor'], $p[0]['por']]);
    igual('Rota 1004: contar o dinheiro entregue (52 000 Kz declarados)', naval_pendente_texto($p[0]));
});

teste('entrega de dinheiro: conferência declarado × contado na caixa × recebido no Pinguim BO', function () {
    $dec = ['1004' => ['total' => 52000.0], '1007' => ['total' => 10000.0]];
    $caixa = ['entregas' => [['rota' => '1004', 'valor' => 51000.0], ['rota' => '1009', 'valor' => 3000.0]]];
    $esp = ['1004' => ['dinheiro' => 52000.0], '1007' => ['dinheiro' => 10000.0], '1009' => ['dinheiro' => 3000.0]];
    $c = naval_cxe_conferencia($dec, $caixa, $esp);
    igual(['1004', '1007', '1009'], array_map('strval', array_keys($c)));
    igual([52000.0, 51000.0, 52000.0, -1000.0, -1000.0], array_values($c['1004']), 'faltam 1000: o vendedor declarou o certo, a contagem não bate');
    igual([10000.0, null, 10000.0, null, null], array_values($c['1007']), 'declarado e ainda por contar');
    igual([null, 3000.0, 3000.0, null, 0.0], array_values($c['1009']), 'sem declaração na app');
    verdade(naval_cxe_registada($caixa, '1004'));
    igual(false, naval_cxe_registada($caixa, '1007'));
});
