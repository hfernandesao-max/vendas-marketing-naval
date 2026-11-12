<?php
// Folha de caixa, controlos de prontidão e Relatório de Vendas diário; conector SAP OData.
naval_servico('relatorio-vendas');

teste('caixa: saldo do dia anterior, saldo teórico, contagem com diferença explicada, aprovação por outra pessoa', function () {
    $d1 = date('Y-m-d', time() - 6 * 86400);
    $d2 = date('Y-m-d', time() - 5 * 86400);
    $c = naval_caixa_ler($d1);
    igual('manual', $c['saldo_inicial_origem'], 'primeiro dia: saldo inicial à mão');
    $c = naval_caixa_gravar($d1, ['saldo_inicial' => 16696620.01, 'entregas' => [['rota' => '1001', 'notas' => ['5000' => 2522, 'moedas' => 2050]]],
        'saidas' => [['tipo' => 'fundo_maneio', 'empresa' => 'FOODTEC', 'valor' => 6000000], ['tipo' => 'fundo_maneio', 'empresa' => 'HIGITEC', 'valor' => 4000000],
            ['tipo' => 'transferencia_gestao', 'valor' => 5000000]]], 0, 'josefa');
    igual(14308670.01, $c['saldo_teorico'], 'como a folha de 12-11-2026');
    lanca(400, function () use ($d1, $c) { naval_caixa_gravar($d1, ['saidas' => [['tipo' => 'fundo_maneio', 'valor' => 1]]], $c['versao'], 'josefa'); });
    lanca(400, function () use ($d1, $c) { naval_caixa_fechar($d1, 14308000, '', $c['versao'], 'josefa'); });
    $c = naval_caixa_fechar($d1, 14308000, 'falta de troco no fim do dia', $c['versao'], 'josefa');
    igual(-670.01, $c['diferenca']);
    lanca(403, function () use ($d1, $c) { naval_caixa_aprovar($d1, $c['versao'], 'josefa'); });
    lanca(403, function () use ($d1, $c) { naval_caixa_aprovar($d1, $c['versao'], 'sara', [2]); }); // sem o 1.º nível
    $c = naval_caixa_aprovar($d1, $c['versao'], 'sara', [1]);
    igual('verificada', $c['estado'], '1.º nível');
    lanca(403, function () use ($d1, $c) { naval_caixa_aprovar($d1, $c['versao'], 'sara', [1, 2]); }); // quem aprovou o 1.º nível não aprova o final
    lanca(403, function () use ($d1, $c) { naval_caixa_aprovar($d1, $c['versao'], 'michael', [1]); }); // sem o nível final
    $c = naval_caixa_aprovar($d1, $c['versao'], 'michael', [2]);
    igual('aprovada', $c['estado']);
    igual('sara', $c['verificada_por']);
    lanca(409, function () use ($d1, $c) { naval_caixa_reabrir($d1, 'corrigir valores', $c['versao'], 'fin'); });
    $n = naval_caixa_ler($d2);
    igual(14308000.0, $n['saldo_inicial'], 'saldo inicial = contagem aprovada do dia anterior');
});

$GLOBALS['rv_fontes'] = function (string $dia, array $mudar = []) {
    return $mudar + [
        'vendas' => [
            ['rota' => '1001', 'documento' => 'FR A/1', 'total' => 1000.0, 'nc' => false, 'pagamentos' => [['codigo' => 'NU', 'valor' => 600.0], ['codigo' => 'MC', 'valor' => 400.0]]],
            ['rota' => '1001', 'documento' => 'FR A/2', 'total' => 500.0, 'nc' => false, 'pagamentos' => []],
            ['rota' => '1002', 'documento' => 'NC B/1', 'total' => -200.0, 'nc' => true, 'pagamentos' => []],
        ],
        'artigos' => [['rota' => '1001', 'documento' => 'FR A/1', 'codigo' => 'A1', 'artigo' => 'Fraldas', 'unidade' => 'CAR', 'quantidade' => 2, 'total' => 1000.0]],
        'sync' => [['rota' => '1001', 'ultima' => $dia . ' 19:10:00'], ['rota' => '1002', 'ultima' => $dia . ' 18:30:00']],
        'carrinhas' => [['rota' => '1001', 'promotor' => 'Ana', 'resumo' => ['estado' => 'aprovada'],
            'linhas' => [['codigo' => 'A1', 'artigo' => 'Fraldas', 'inicial' => 5, 'carregado' => 10, 'vendido' => 2, 'devolvido' => 0, 'final' => 13, 'contado' => 13, 'diferenca' => 0]]]],
        'lotes' => [['numero' => 4, 'estado' => 'carregado', 'documentos' => ['FR A/1', 'FR A/2', 'NC B/1']]],
        'caixa' => naval_caixa_totais(['estado' => 'aprovada', 'aprovada_por' => 'fin', 'saldo_inicial' => 100.0, 'entregas' => [['rota' => '1001', 'valor' => 550.0]],
            'tpa' => [['rota' => '1001', 'banco' => 'BPC', 'valor' => 400.0]], 'saidas' => [], 'contagem_fisica' => 650.0]),
        'sap_configurado' => false, 'sap' => null,
    ];
};

teste('prontidão: só gera com tudo completo; cada fonte em falta diz o que falta', function () {
    $dia = date('Y-m-d', time() - 4 * 86400);
    $f = $GLOBALS['rv_fontes'];
    $ok = naval_rv_controlos($dia, $f($dia));
    verdade($ok['pronto'], 'tudo completo');
    igual('por_ligar', $ok['controlos']['sap_odata']['estado'], 'SAP por ligar não impede');
    $x = naval_rv_controlos($dia, $f($dia, ['sync' => [['rota' => '1001', 'ultima' => $dia . ' 12:00:00']]]));
    igual(false, $x['pronto']);
    igual(['1001', '1002'], $x['controlos']['pinguim']['rotas'], 'rotas por sincronizar depois do fecho');
    $x = naval_rv_controlos($dia, $f($dia, ['lotes' => [['numero' => 4, 'estado' => 'emitido', 'documentos' => ['FR A/1', 'FR A/2', 'NC B/1']]]]));
    igual('pendente', $x['controlos']['sap_ficheiro']['estado'], 'lote emitido mas não confirmado no SAP');
    $x = naval_rv_controlos($dia, $f($dia, ['carrinhas' => [['rota' => '1001', 'resumo' => ['estado' => 'por_fechar'], 'linhas' => []]]]));
    igual(['1001'], $x['controlos']['conciliacao']['rotas'], 'carrinha com documentos de venda por fechar');
    $x = naval_rv_controlos($dia, $f($dia, ['carrinhas' => [$f($dia)['carrinhas'][0], ['rota' => '1011', 'resumo' => ['estado' => 'por_contar'],
        'linhas' => [['codigo' => 'A1', 'vendido' => 0, 'carregado' => 0]]]]]));
    igual('ok', $x['controlos']['conciliacao']['estado'], 'carrinha sem vendas não impede o relatório');
    $x = naval_rv_controlos($dia, $f($dia, ['caixa' => ['estado' => 'fechada']]));
    igual('pendente', $x['controlos']['caixa']['estado']);
    $x = naval_rv_controlos($dia, $f($dia, ['vendas' => [], 'sync' => []]));
    igual('erro', $x['controlos']['pinguim']['estado'], 'Pinguim sem documentos mas a carrinha vendeu: falha de dados');
    $vazio = $f($dia, ['vendas' => [], 'sync' => []]);
    $vazio['carrinhas'][0]['linhas'][0]['vendido'] = 0;
    $x = naval_rv_controlos($dia, $vazio);
    igual(['ok', true], [$x['controlos']['pinguim']['estado'], $x['controlos']['pinguim']['sem_vendas']], 'feriado: dia sem vendas');
    $x = naval_rv_controlos($dia, $f($dia, ['vendas' => null]));
    igual('erro', $x['controlos']['pinguim']['estado']);
    lanca(409, function () use ($dia, $f) { naval_rv_gerar($dia, '', 'dir', $f($dia, ['caixa' => ['estado' => 'aberta']])); });
});

teste('relatório: cobranças por promotor face à caixa e ao TPA; congelado, com versão e impressão digital', function () {
    $dia = date('Y-m-d', time() - 4 * 86400);
    $f = $GLOBALS['rv_fontes'];
    $r = naval_rv_gerar($dia, '', 'dir', $f($dia));
    igual('RV-' . str_replace('-', '', $dia) . '-v1', $r['numero']);
    $p = array_column($r['dados']['promotores'], null, 'rota');
    igual([1500.0, 600.0, 400.0, 500.0, 550.0, -50.0, 0.0], [$p['1001']['vendas'], $p['1001']['dinheiro'], $p['1001']['multicaixa'], $p['1001']['por_receber'],
        $p['1001']['entregue'], $p['1001']['dif_dinheiro'], $p['1001']['dif_tpa']]);
    igual(200.0, $p['1002']['notas_credito']);
    igual(1300.0, $r['dados']['resumo']['vendas'], 'líquido das notas de crédito');
    igual(64, strlen($r['sha256']));
    lanca(409, function () use ($dia, $f) { naval_rv_gerar($dia, '', 'dir', $f($dia)); });   // segunda versão sem motivo
    igual(2, naval_rv_gerar($dia, 'caixa corrigida', 'dir', $f($dia))['versao']);
    igual([1, 2], array_column(naval_rv_versoes($dia), 'versao'));
});

teste('SAP OData: facturação paginada, empresas e meios pelo tipo de factura', function () {
    $paginas = [
        ['d' => ['results' => [['BillingDocument' => '1150021199', 'BillingDocumentType' => 'ZBV', 'SalesOrganization' => '1100',
            'to_Item' => ['results' => [['Material' => '000000000010000332', 'BillingDocumentItemText' => 'FRALDAS', 'BillingQuantity' => '100', 'NetAmount' => '1298245.61',
                'TaxAmount' => '181754.39', 'StorageLocation' => 'MR13']]]]], '__next' => 'A_BillingDocument?$skiptoken=1']],
        ['d' => ['results' => [['BillingDocument' => '1010007406', 'BillingDocumentType' => 'ZDP', 'SalesOrganization' => '1000',
            'to_Item' => ['results' => [['Material' => '10000135', 'BillingQuantity' => '750', 'NetAmount' => '19875000', 'TaxAmount' => '0']]]]]]],
    ];
    $i = 0;
    $http = function ($u) use (&$paginas, &$i) { return ['corpo' => json_encode($paginas[$i++]), 'codigo' => 200]; };
    $l = naval_sapod_facturacao('2026-11-12', $http);
    igual(2, count($l));
    igual(['HIGITEC', 'dinheiro', '10000332', 1480000.0, 14800.0], [$l[0]['empresa'], $l[0]['meio'], $l[0]['codigo'], $l[0]['bruto'], $l[0]['preco']]);
    $res = naval_sapod_resumo($l);
    igual(19875000.0, $res['FOODTEC']['deposito']);
    igual(1480000.0, $res['HIGITEC']['dinheiro']);
    $erro = '';
    try {
        naval_sapod_ler('/x', [], function () { return ['corpo' => false, 'codigo' => 401]; });
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
    verdade(strpos($erro, 'HTTP 401') !== false, 'SAP sem autorização: erro claro');
});

teste('caixa: o que cada promotor recebeu em dinheiro e Multicaixa no Pinguim BO (notas de crédito de fora)', function () {
    $e = naval_caixa_esperado('2026-11-12', $GLOBALS['rv_fontes']('2026-11-12')['vendas']);
    igual(['1001' => ['documentos' => 2, 'dinheiro' => 600.0, 'multicaixa' => 400.0]], $e);
});

teste('caixa por notas: o valor da entrega e a contagem física saem das notas e moedas contadas', function () {
    igual([['5000' => 3, '1000' => 2, 'moedas' => 150.0], 17150.0], naval_caixa_por_notas(['5000' => '3', '2000' => '', '1000' => 2, '500' => 0, 'moedas' => '150']));
    igual([null, null], naval_caixa_por_notas([]));
    lanca(400, function () { naval_caixa_por_notas(['3000' => 1]); });
    lanca(400, function () { naval_caixa_por_notas(['5000' => 1.5]); });
    $dia = date('Y-m-d', time() - 9 * 86400);
    lanca(400, function () use ($dia) { naval_caixa_gravar($dia, ['entregas' => [['rota' => '1001', 'valor' => 500]]], 0, 'josefa'); });   // sem notas
    lanca(400, function () use ($dia) { naval_caixa_gravar($dia, ['tpa' => [['rota' => '1001', 'banco' => 'BPC', 'valor' => 500]]], 0, 'josefa'); });   // sem talão
    $c = naval_caixa_gravar($dia, ['saldo_inicial' => 0, 'entregas' => [['rota' => '1001', 'valor' => 1, 'notas' => ['5000' => 2, 'moedas' => 50]]],
        'tpa' => [['rota' => '1001', 'banco' => 'BPC', 'talao' => 'L-0042', 'valor' => 3000]]], 0, 'josefa');
    igual('L-0042', $c['tpa'][0]['talao']);
    igual([10050.0, ['5000' => 2, 'moedas' => 50.0]], [$c['entregas'][0]['valor'], $c['entregas'][0]['notas']], 'o valor escrito à mão cede às notas');
    $c = naval_caixa_fechar($dia, null, '', $c['versao'], 'josefa', ['5000' => 2, 'moedas' => 50]);
    igual([10050.0, 0.0], [$c['contagem_fisica'], $c['diferenca']]);
});
