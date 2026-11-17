<?php
// Formas de pagamento do RTM: lista branca, banco do TPA por rota e reconciliação por dia e promotor.
naval_servico('formas-pagamento');
naval_servico('caixa');

teste('formas de pagamento: lista branca (NU, MC, MCB); código fora da tabela ou sem forma é excepção', function () {
    $cfg = NAVAL_FP_OMISSAO;
    igual(['numerario', 'multicaixa', 'multicaixa', 'compensacao', 'invalida', 'invalida', 'invalida'],
        array_map(function ($c) use ($cfg) { return naval_fp_forma($c, $cfg); }, ['NU', 'mc', 'MCB', 'CMP', 'TB', '?', '']));
    igual(['MC', 'MCB'], naval_fp_codigos_multicaixa($cfg));
    $v = ['rota' => '1011', 'documento' => 'FR A/1', 'pagamentos' => [['codigo' => '?', 'meio' => 'Sem meio indicado', 'valor' => 780000.0],
        ['codigo' => 'MCB', 'meio' => 'Multicaixa - BPC', 'valor' => 880000.0], ['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 10.0]]];
    igual(['forma_invalida', 'banco_diferente'], array_column(naval_fp_excecoes_documento($v, $cfg), 'tipo'), 'rota 1011 tem TPA Atlântico');
});

teste('formas de pagamento: gravar valida códigos, formas e bancos; regista quem alterou', function () {
    lanca(400, function () { naval_fp_gravar(['codigos' => ['MCA' => ['forma' => 'cheque']]], 'henrique'); });
    lanca(400, function () { naval_fp_gravar(['codigos' => ['CMP' => ['forma' => 'numerario']]], 'henrique'); });
    lanca(400, function () { naval_fp_gravar(['codigos' => ['MC' => ['forma' => 'multicaixa', 'banco' => 'XPTO']]], 'henrique'); });
    $c = naval_fp_gravar(['codigos' => ['NU' => ['forma' => 'numerario', 'banco' => 'BPC'], 'mca' => ['forma' => 'multicaixa', 'banco' => 'ATLANTICO']],
        'rotas' => ['1001' => 'BPC', '1008' => 'ATLANTICO', '1009' => '']], 'henrique');
    igual(['MCA' => ['forma' => 'multicaixa', 'banco' => 'ATLANTICO'], 'NU' => ['forma' => 'numerario', 'banco' => '']], $c['codigos'], 'numerário sem banco');
    igual(['1001' => 'BPC', '1008' => 'ATLANTICO'], $c['rotas']);
    igual('henrique', $c['alterado_por']);
    @unlink(naval_fp_ficheiro());
    igual(NAVAL_FP_OMISSAO['rotas'], naval_fp_config()['rotas']);
});

teste('formas de pagamento: reconciliação por dia e promotor (Pinguim BO × caixa × TPA) e provável troca de forma', function () {
    // Nelson a 20-10-2026: Pinguim BO tudo Multicaixa (795 800); caixa recebeu 577 700 em dinheiro e 218 100 em talões
    $vendas = [
        ['data' => '2026-10-20', 'rota' => '1004', 'documento' => 'FR 1', 'total' => 500000.0, 'nc' => false, 'pagamentos' => [['codigo' => 'MC', 'valor' => 500000.0]]],
        ['data' => '2026-10-20', 'rota' => '1004', 'documento' => 'FR 2', 'total' => 295800.0, 'nc' => false, 'pagamentos' => [['codigo' => 'MC', 'valor' => 295800.0]]],
        ['data' => '2026-10-21', 'rota' => '1011', 'documento' => 'FR 3', 'total' => 1660000.0, 'nc' => false,
            'pagamentos' => [['codigo' => '?', 'meio' => 'Sem meio indicado', 'valor' => 780000.0], ['codigo' => 'MC', 'valor' => 880000.0]]],
        ['data' => '2026-10-21', 'rota' => '1001', 'documento' => 'FR 4', 'total' => 1000.0, 'nc' => false, 'pagamentos' => []],
    ];
    $caixas = ['2026-10-20' => ['entregas' => [['rota' => '1004', 'valor' => 577700.0]], 'tpa' => [['rota' => '1004', 'valor' => 218100.0]]]];
    $r = naval_fp_reconciliar($vendas, $caixas, ['1004' => 'Nelson Bumba', '1011' => 'Robel Tesfamichael', '1001' => 'Andrea Santos'], NAVAL_FP_OMISSAO);
    $l = array_column($r['linhas'], null, 'rota');
    igual([0.0, 795800.0, 577700.0, 218100.0, 577700.0, -577700.0, 577700.0],
        [$l['1004']['numerario'], $l['1004']['multicaixa'], $l['1004']['entregue'], $l['1004']['talaos'], $l['1004']['dif_numerario'], $l['1004']['dif_multicaixa'], $l['1004']['provavel_troca']]);
    igual(['BPC', 'ATLANTICO'], [$l['1004']['banco_tpa'], $l['1011']['banco_tpa']]);
    igual([780000.0, 880000.0, null, null], [$l['1011']['invalida'], $l['1011']['multicaixa'], $l['1011']['entregue'], $l['1011']['dif_numerario']], 'sem folha de caixa');
    igual(1000.0, $l['1001']['por_receber']);
    igual(['provavel_troca', 'forma_invalida'], array_column($r['excepcoes'], 'tipo'));
    igual([2456800.0, 1675800.0, 577700.0], [$r['totais']['total'], $r['totais']['multicaixa'], $r['totais']['entregue']]);
});

teste('correcção da forma de pagamento: justificação, valor igual ao cobrado, quatro olhos; o original fica', function () {
    @unlink(naval_fp_correcoes_ficheiro());
    $cfg = NAVAL_FP_OMISSAO;
    $docs = [
        ['documento' => 'FR A1004/10', 'hora' => '09:10', 'cliente' => 'Loja 1', 'total' => 500000.0, 'nc' => false, 'corrigivel' => true,
            'pinguim' => ['numerario' => 0.0, 'multicaixa' => 500000.0, 'invalida' => 0.0, 'total_pago' => 500000.0], 'meio_pinguim' => 'Multicaixa', 'correcoes' => []],
        ['documento' => 'NC A1004/2', 'hora' => '12:00', 'cliente' => 'Loja 1', 'total' => -1000.0, 'nc' => true, 'corrigivel' => false,
            'pinguim' => ['numerario' => -1000.0, 'multicaixa' => 0.0, 'invalida' => 0.0, 'total_pago' => -1000.0], 'meio_pinguim' => 'Numerário', 'correcoes' => []],
    ];
    $j = 'O cliente pagou em dinheiro; o promotor escolheu Multicaixa por engano.';
    lanca(400, function () use ($docs) { naval_fp_pedir('2026-10-20', '1004', [['documento' => 'FR A1004/10', 'numerario' => 500000, 'multicaixa' => 0]], 'engano', '', 'josefa', $docs); });
    lanca(400, function () use ($docs, $j) { naval_fp_pedir('2026-10-20', '1004', [['documento' => 'FR A1004/10', 'numerario' => 400000, 'multicaixa' => 0]], $j, '', 'josefa', $docs); });
    lanca(400, function () use ($docs, $j) { naval_fp_pedir('2026-10-20', '1004', [['documento' => 'NC A1004/2', 'numerario' => 0, 'multicaixa' => -1000]], $j, '', 'josefa', $docs); });
    lanca(400, function () use ($docs, $j) { naval_fp_pedir('2026-10-20', '1004', [['documento' => 'FR A1004/10', 'numerario' => 0, 'multicaixa' => 500000]], $j, '', 'josefa', $docs); }); // igual
    $c = naval_fp_pedir('2026-10-20', '1004', [['documento' => 'FR A1004/10', 'numerario' => 377700, 'multicaixa' => 122300]], $j, 'talão TPA 00444134 período 103', 'josefa', $docs);
    igual(['FP-2026-00001', 'pendente', 377700.0, 122300.0], [$c[0]['id'], $c[0]['estado'], $c[0]['numerario'], $c[0]['multicaixa']]);
    lanca(409, function () use ($docs, $j) { naval_fp_pedir('2026-10-20', '1004', [['documento' => 'FR A1004/10', 'numerario' => 500000, 'multicaixa' => 0]], $j, '', 'josefa', $docs); });
    // pendente: ainda não conta
    $v = [['data' => '2026-10-20', 'rota' => '1004', 'documento' => 'FR A1004/10', 'total' => 500000.0, 'nc' => false, 'pagamentos' => [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 500000.0]]]];
    igual($v, naval_fp_aplicar($v));
    lanca(403, function () { naval_fp_decidir('FP-2026-00001', true, '', 'josefa'); }); // quem pede não aprova
    lanca(400, function () { naval_fp_decidir('FP-2026-00001', false, '', 'sara'); }); // recusar pede motivo
    $a = naval_fp_decidir('FP-2026-00001', true, 'confirmado com a folha de caixa', 'sara');
    igual(['aprovada', 'sara'], [$a['estado'], $a['decidido_por']]);
    $x = naval_fp_aplicar($v)[0];
    igual(['FP-2026-00001', [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 500000.0]]], [$x['forma_corrigida'], $x['pagamentos_pinguim']]);
    igual(['NU' => 377700.0, 'MC' => 122300.0], array_column($x['pagamentos'], 'valor', 'codigo'));
    // na reconciliação: a correcção aprovada acaba com a «provável troca»
    $caixas = ['2026-10-20' => ['entregas' => [['rota' => '1004', 'valor' => 377700.0]], 'tpa' => [['rota' => '1004', 'valor' => 122300.0]]]];
    $r = naval_fp_reconciliar(naval_fp_aplicar($v), $caixas, ['1004' => 'Nelson Bumba'], $cfg);
    igual([0.0, 0.0, 0.0, 1, []], [$r['linhas'][0]['dif_numerario'], $r['linhas'][0]['dif_multicaixa'], $r['linhas'][0]['provavel_troca'], $r['linhas'][0]['corrigidos'], $r['excepcoes']]);
    // anular: volta a contar o Pinguim BO; o histórico fica
    lanca(400, function () { naval_fp_anular('FP-2026-00001', '', 'sara'); });
    $an = naval_fp_anular('FP-2026-00001', 'pedido em duplicado', 'sara');
    igual(['anulada', ['pedida', 'aprovada', 'anulada']], [$an['estado'], array_column($an['historico'], 'accao')]);
    igual($v, naval_fp_aplicar($v));
    // TPA de outra empresa: talão da Higitec numa venda da Naval
    $r2 = naval_fp_reconciliar([], ['2026-10-21' => ['entregas' => [], 'tpa' => [['rota' => '1006', 'empresa' => 'HIGITEC', 'tpa' => '00445739', 'valor' => 356500.0]]]], ['1006' => 'Rosa Vemba'], $cfg);
    igual(['tpa_outra_empresa', 356500.0], [$r2['excepcoes'][0]['tipo'], $r2['excepcoes'][0]['valor']]);
    @unlink(naval_fp_correcoes_ficheiro());
});
