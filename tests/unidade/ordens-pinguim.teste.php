<?php
// Ordens do portal ao Pinguim BO (nucleo/servicos/ordens-pinguim.php): idempotência, robô (prazo, tentativas,
// recusa de negócio), execução manual e confirmação pelo documento do Pinguim BO.
naval_servico('ordens-pinguim');
naval_servico('requisicoes');

function op_req(string $num): array
{
    return ['numero' => $num, 'rota' => '1008', 'promotor' => 'Daniel', 'confirmado_em' => '2026-11-20 07:20:00',
        'linhas' => [['codigo' => 'A1', 'artigo' => 'Fralda', 'quantidade' => 400, 'entregue' => 400], ['codigo' => 'B2', 'artigo' => 'Sabão', 'quantidade' => 30, 'entregue' => 28],
            ['codigo' => 'Z9', 'artigo' => 'Nada', 'quantidade' => 5, 'entregue' => 0]]];
}

teste('ordens: uma por requisição (idempotente), só as linhas carregadas, destino 100010NN', function () {
    $GLOBALS['naval_req_armazens_teste'] = ['A1' => 'HIGITEC PIV', 'B2' => 'HIGITEC PIV'];
    $o = naval_ord_da_requisicao(op_req('PO-2026-000901'), 'bo');
    igual(['GUIA-PO-2026-000901', 'por_executar', '10001008', 'PO-2026-000901'], [$o['id'], $o['estado'], $o['armazem_destino'], $o['referencia']]);
    igual([['A1', 400.0], ['B2', 28.0]], array_map(function ($l) { return [$l['codigo'], $l['quantidade']]; }, $o['linhas']), 'o que não foi carregado não entra');
    igual('HIGITEC PIV', $o['linhas'][0]['armazem_origem']);
    $o2 = naval_ord_da_requisicao(op_req('PO-2026-000901'), 'outro');
    igual($o['criada_em'], $o2['criada_em'], 'a segunda vez devolve a mesma ordem');
    igual('bo', $o2['criada_por']);
});

teste('robô: reserva com prazo, falha técnica com espera crescente, recusa de negócio e documento gerado', function () {
    $o = naval_ord_da_requisicao(op_req('PO-2026-000902'), 'bo');
    $robo = function ($x) { $x['modo'] = 'robo'; return $x; };
    naval_ord_alterar($o['id'], $robo);   // ORDENS_PINGUIM_MODO=robo
    $t = time() + 1;
    $r = naval_ord_reclamar('robo:a', $t);
    verdade($r !== null, 'entrega uma ordem');
    while ($r && $r['id'] !== 'GUIA-PO-2026-000902') $r = naval_ord_reclamar('robo:a', $t);
    igual('GUIA-PO-2026-000902', $r['id']);
    igual('em_execucao', $r['estado']);
    verdade(naval_ord_reclamar('robo:b', $t + 60) === null || naval_ord_ler('GUIA-PO-2026-000902')['executor'] === 'robo:a', 'outro robô não a apanha dentro do prazo');
    lanca(409, function () { naval_ord_resultado('GUIA-PO-2026-000902', 'robo:b', true, 'GTC 1'); });
    $f = naval_ord_resultado('GUIA-PO-2026-000902', 'robo:a', false, '', 'tempo esgotado', 'tecnico', $t);
    igual('por_executar', $f['estado']);
    igual(date('Y-m-d H:i:s', $t + 60), $f['proxima'], '1.ª falha: espera 1 minuto');
    $r = naval_ord_reclamar('robo:a', $t + 61);
    while ($r && $r['id'] !== 'GUIA-PO-2026-000902') $r = naval_ord_reclamar('robo:a', $t + 61);
    $n = naval_ord_resultado('GUIA-PO-2026-000902', 'robo:a', false, '', 'Stock insuficiente', 'negocio', $t + 62);
    igual('recusada', $n['estado'], 'erro de negócio não se repete: volta ao Back-Office');
    igual('Stock insuficiente', $n['erro']);
    $p = naval_ord_repetir('GUIA-PO-2026-000902', 'bo');
    igual(['por_executar', 0], [$p['estado'], $p['tentativas']]);
    naval_ord_alterar($p['id'], $robo);
    $r = naval_ord_reclamar('robo:a', time() + 1);
    while ($r && $r['id'] !== 'GUIA-PO-2026-000902') $r = naval_ord_reclamar('robo:a', time() + 1);
    $g = naval_ord_resultado('GUIA-PO-2026-000902', 'robo:a', true, 'GTC 2026/0042');
    igual(['gerada', 'GTC 2026/0042'], [$g['estado'], $g['documento']['numero']]);
});

teste('confirmação pelo Pinguim BO: pelo número ou pela rota, dia e linhas; diferente é divergente', function () {
    $o = ['rota' => '1008', 'data' => '2026-11-20', 'documento' => null, 'linhas' => [['codigo' => 'A1', 'quantidade' => 400], ['codigo' => 'B2', 'quantidade' => 28]]];
    $docs = [
        ['id' => '1', 'numero' => 'GTC 2026/0040', 'rota' => '1008', 'em' => '2026-11-19 07:00:00', 'linhas' => ['A1' => 400, 'B2' => 28]],   // dia anterior
        ['id' => '2', 'numero' => 'GTC 2026/0041', 'rota' => '1001', 'em' => '2026-11-20 07:30:00', 'linhas' => ['A1' => 400, 'B2' => 28]],   // outra rota
        ['id' => '3', 'numero' => 'GTC 2026/0042', 'rota' => '1008', 'em' => '2026-11-20 07:45:00', 'linhas' => ['B2' => -28, 'A1' => 400]],
    ];
    [$d, $igual] = naval_ord_casar($o, $docs);
    igual(['3', true], [$d['id'], $igual], 'mesma rota, a partir do dia, mesmas linhas (o sinal não conta)');
    igual([null, false], naval_ord_casar($o, $docs, ['3' => true]), 'documento já ligado a outra ordem');
    $o['documento'] = ['numero' => 'GTC 2026/0041'];
    [$d, $igual] = naval_ord_casar($o, $docs);
    igual(['2', true], [$d['id'], $igual], 'com o número conhecido, manda o número');
    $o['documento'] = ['numero' => 'GTC2026/0040'];
    $docs[0]['linhas'] = ['A1' => 400, 'B2' => 30];
    [$d, $igual] = naval_ord_casar($o, $docs);
    igual(['1', false], [$d['id'], $igual], 'número certo, linhas diferentes: divergente');
});

teste('Back-Office: executada à mão (com número) e cancelada (com motivo)', function () {
    $o = naval_ord_da_requisicao(op_req('PO-2026-000903'), 'bo');
    lanca(400, function () use ($o) { naval_ord_executada_manual($o['id'], '', 'bo'); });
    $m = naval_ord_executada_manual($o['id'], 'GTC 2026/0050', 'bo');
    igual(['gerada', 'bo'], [$m['estado'], $m['documento']['por']]);
    lanca(409, function () use ($o) { naval_ord_executada_manual($o['id'], 'GTC 2026/0051', 'bo'); });
    lanca(400, function () use ($o) { naval_ord_cancelar($o['id'], 'x', 'bo'); });
    igual('cancelada', naval_ord_cancelar($o['id'], 'Guia anulada no Pinguim BO', 'bo')['estado']);
    lanca(409, function () use ($o) { naval_ord_cancelar($o['id'], 'outra vez sim', 'bo'); });
});
