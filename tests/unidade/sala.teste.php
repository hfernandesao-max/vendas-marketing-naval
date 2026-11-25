<?php
// Painel da sala: ecrãs novos (ritmo, 14 dias, visitas, rotas, clientes) e o que cada local pode mostrar.
naval_servico('analise');
naval_servico('sala');

teste('painel da sala: ritmo por hora, 14 dias, visitas do plano, rotas no terreno e clientes', function () {
    $hoje = '2026-11-25';
    $ag = [
        $hoje => ['rotas' => ['1001' => ['t' => 300.0], '1002' => ['t' => 100.0]],
            'clientes' => ['A' => ['r' => '1001', 't' => 200.0], 'B' => ['r' => '1001', 't' => 100.0], 'C' => ['r' => '1002', 't' => 100.0]]],
        '2026-11-24' => ['rotas' => ['1001' => ['t' => 200.0]], 'clientes' => []],
    ];
    $plano = ['1001' => [['c' => 'A'], ['c' => 'X']], '1002' => [['c' => 'Y']]];
    $rotas = [['rota' => '1001', 'promotor' => 'Ana Lima', 'hoje' => 300.0, 'previsto_hoje' => 250.0], ['rota' => '1002', 'promotor' => 'Rui', 'hoje' => 100.0, 'previsto_hoje' => 400.0],
        ['rota' => '1003', 'promotor' => 'Eva', 'hoje' => 0.0, 'previsto_hoje' => 300.0]];
    $compras = ['hoje' => ['compras' => 3], 'mes' => ['compras' => 40, 'valor_medio' => 150.0], 'mes_anterior' => ['compras' => 30]];
    $x = naval_sala_extra($ag, $hoje, ['09' => 2, '10' => 5], $plano, $rotas, $compras);
    igual(14, count($x['serie']), '14 dias');
    igual(['d' => $hoje, 't' => 400.0], end($x['serie']), 'hoje no fim');
    igual(['h' => '10', 'n' => 5], $x['horas'][4], 'vendas por hora (das 06h)');
    igual([3, 1, 2, 0.333], [$x['visitas']['planeados'], $x['visitas']['compraram'], $x['visitas']['fora_plano'], $x['visitas']['conversao']], 'plano: A comprou; B e C fora do plano');
    igual(['1001'], array_column($x['visitas']['melhores'], 'rota'), 'só as rotas com compras do plano (nunca as últimas)');
    igual([3, 2, ['1001']], [$x['terreno']['rotas'], $x['terreno']['a_vender'], array_column($x['terreno']['objectivo'], 'rota')], 'rotas a vender e com o objectivo');
    igual([3, 40, 30, 150.0], array_values($x['clientes']), 'clientes');
});

teste('painel da sala em open space: índice em vez de Kz, sem nomes, sem a lista por rota das visitas nem o valor médio', function () {
    $hoje = '2026-11-25';
    $ag = [$hoje => ['rotas' => ['1001' => ['t' => 300.0]], 'clientes' => []], '2026-11-24' => ['rotas' => ['1001' => ['t' => 100.0]], 'clientes' => []]];
    $rotas = [['rota' => '1001', 'promotor' => 'Ana Lima', 'hoje' => 300.0, 'previsto_hoje' => 250.0, 'ontem' => 100.0, 'habitual_ontem' => 80.0, 'variacao_mes' => 0.2, 'sequencia' => 1]];
    $compras = ['hoje' => ['compras' => 3], 'mes' => ['compras' => 40, 'valor_medio' => 150.0], 'mes_anterior' => ['compras' => 30]];
    $r = ['hora' => $hoje . ' 10:00', 'hoje' => ['progresso' => 1.2, 'documentos' => 7, 'total' => 300.0], 'ontem' => ['variacao' => 0.1, 'total' => 100.0],
        'semana' => ['variacao' => 0.0], 'mes' => ['variacao' => 0.5], 'compras' => $compras, 'rotas' => $rotas,
        'extra' => naval_sala_extra($ag, $hoje, [], [], $rotas, $compras)];
    $a = naval_sala_aberta($r);
    $j = (string) json_encode($a);
    verdade(strpos($j, 'Ana') === false && strpos($j, 'promotor') === false, 'sem nomes');
    verdade(strpos($j, '"t"') === false && strpos($j, 'total') === false && strpos($j, 'valor_medio') === false && strpos($j, 'melhores') === false, 'sem Kz');
    igual([150, 50], [end($a['serie'])['indice'], $a['serie'][12]['indice']], 'índice: 100 = média dos dias com vendas (200)');
    igual([['rota' => '1001']], $a['terreno']['objectivo'], 'objectivo só com o número da rota');
    $i = naval_sala_interna($r);
    igual(['Ana Lima', 150.0], [$i['terreno']['objectivo'][0]['promotor'], $i['clientes']['valor_medio_mes']], 'sala interna com nome e valor médio');
});

teste('painel da sala: ligações antigas nunca personalizadas passam a ver todos os ecrãs; as escolhidas no portal mantêm-se', function () {
    igual(NAVAL_SALA_ECRAS, naval_sala_config(['ecras' => ['hoje', 'rank-hoje', 'rank-mes', 'destaques'], 'segundos' => 20], 'interna')['ecras'], 'antiga interna');
    igual(NAVAL_SALA_ECRAS_ABERTA, naval_sala_config(['ecras' => ['hoje', 'destaques']], 'aberta')['ecras'], 'antiga aberta');
    igual(NAVAL_SALA_ECRAS_ABERTA, naval_sala_config(null, 'aberta')['ecras'], 'sem configuração');
    igual(['ecras' => ['hoje', 'destaques'], 'segundos' => 30], naval_sala_config(['ecras' => ['hoje', 'destaques'], 'segundos' => 30, 'personalizado' => true], 'interna'), 'escolhida no portal');
    igual(['visitas'], naval_sala_config(['ecras' => ['rank-mes', 'visitas'], 'personalizado' => true], 'aberta')['ecras'], 'classificações nunca em open space');
});

teste('painel da sala: comparativos com as datas dos dois períodos; sem histórico diz desde quando há dados (nunca % absurdas)', function () {
    $ag = [];
    // vendas desde 2026-11-03 (segunda-feira): 100 por dia; 2026-11-24 (terça) 150
    foreach (naval_analise_dias('2026-11-03', '2026-11-25') as $d) $ag[$d] = ['rotas' => ['1001' => ['t' => $d === '2026-11-24' ? 150.0 : 100.0]], 'clientes' => []];
    $c = naval_sala_comparacoes($ag, '2026-11-25');
    igual('2026-11-03', $c['inicio'], 'primeiro dia com vendas');
    $por = [];
    foreach ($c['linhas'] as $x) $por[$x['id']] = $x;
    igual([150.0, 100.0, 0.5], [$por['ontem_anterior']['actual']['t'], $por['ontem_anterior']['ref']['t'], $por['ontem_anterior']['variacao']], 'ontem × dia anterior');
    igual(['2026-11-17', '2026-11-17', 0.5], [$por['ontem_semana']['ref']['de'], $por['ontem_semana']['ref']['ate'], $por['ontem_semana']['variacao']], 'mesmo dia da semana passada');
    igual('Ontem × habitual (3 semanas)', $por['ontem_habitual']['nome'], 'habitual com as semanas que há');
    igual(['2026-11-23', '2026-11-24', '2026-11-16', '2026-11-17'], [$por['semana']['actual']['de'], $por['semana']['actual']['ate'], $por['semana']['ref']['de'], $por['semana']['ref']['ate']], 'semana até ontem');
    igual(null, $por['mes']['ref'], 'mês anterior sem dados');
    igual('sem histórico · dados desde 03-11-2026', $por['mes']['motivo'], 'diz desde quando');
    igual([null, null], [$por['30dias']['variacao'], $por['ano_homologo']['variacao']], '30 dias e homólogo sem histórico');
    // open space: sem os valores
    $a = naval_sala_aberta(['hoje' => [], 'ontem' => [], 'semana' => [], 'mes' => [], 'rotas' => [], 'extra' => ['comparacoes' => $c]]);
    verdade(strpos((string) json_encode($a['comparacoes']), '"t"') === false, 'sem Kz no open space');
    igual(0.5, $a['comparacoes']['linhas'][0]['variacao'], 'com a variação');
});
