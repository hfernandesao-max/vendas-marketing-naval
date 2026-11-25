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

teste('painel da sala: ritmo acumulado × objectivo e × dia normal; previsão de fecho do dia e do mês', function () {
    // 4 quartas-feiras anteriores: 100 às 08h e 300 às 14h (400 no dia)
    $hist = [];
    foreach (['2026-11-04', '2026-11-11', '2026-11-18', '2026-10-28'] as $d) $hist[$d] = ['08' => 100.0, '14' => 300.0];
    $a = naval_sala_acumulado($hist, '2026-11-25', ['07' => 50.0, '08' => 100.0], 800.0, 10);
    igual(['mesmo_dia', 4], [$a['ref']['tipo'], $a['ref']['dias']], 'dia normal: as 4 quartas anteriores');
    $h = [];
    foreach ($a['horas'] as $x) $h[$x['h']] = $x;
    igual([150.0, 100.0, 200.0], [$h['10']['hoje'], $h['10']['habitual'], $h['10']['objectivo']], 'às 10h: hoje 150, normal 100, objectivo 800 × 25 %');
    igual([null, 400.0, 800.0], [$h['15']['hoje'], $h['20']['habitual'], $h['20']['objectivo']], 'horas futuras sem valor de hoje');
    igual(-0.25, $a['ritmo'], '25 % atrás do ritmo do objectivo');
    igual(600.0, $a['estimado'], 'fecho estimado pelo ritmo: 150 / 25 %');
    $ag = [];
    foreach (naval_analise_dias('2026-10-01', '2026-11-24') as $d) $ag[$d] = ['rotas' => ['1001' => ['t' => 100.0]]];
    $p = naval_sala_previsao($a, ['previsto' => 800.0, 'minimo' => 600.0, 'maximo' => 1000.0], $ag, '2026-11-25', ['2026-11-26' => ['p' => 500.0, 'folga' => false], '2026-11-29' => ['p' => 0.0, 'folga' => true]]);
    igual([600.0, 0.75, true], [$p['dia']['estimado'], $p['dia']['progresso'], $p['dia']['pelo_ritmo']], 'dia');
    igual([2400.0, 600.0, 500.0, 3500.0, 3100.0], [$p['mes']['ate_ontem'], $p['mes']['hoje'], $p['mes']['resto'], $p['mes']['estimado'], $p['mes']['mes_anterior']], 'mês: até ontem + hoje + o resto');
    igual(1, $p['mes']['dias_resto'], 'folgas não contam');
    // open space: índices (100 = objectivo do dia), sem Kz
    $ab = naval_sala_avancados_aberta(['acumulado' => $a, 'previsao' => $p]);
    igual([19, 25, 100], [$ab['acumulado']['horas'][4]['hoje'], $ab['acumulado']['horas'][4]['objectivo'], end($ab['acumulado']['horas'])['objectivo']], 'índices');
    verdade(strpos((string) json_encode($ab), 'estimado') === false && strpos((string) json_encode($ab), 'mes_anterior') === false, 'sem valores');
});

teste('painel da sala: mapa de calor, recordes, contagem do mês e momentos', function () {
    $hist = ['2026-11-20' => ['10' => 50.0, '15' => 100.0], '2026-11-23' => ['09' => 20.0]];
    $c = naval_sala_calor($hist, '2026-11-25');
    igual(['seg', 'sex'], array_column($c['linhas'], 'dia'), 'só os dias da semana com vendas');
    igual(['dia' => 'sex', 'h' => '15'], $c['pico'], 'hora mais forte');
    igual([50, 100], [$c['linhas'][1]['v'][4], $c['linhas'][1]['v'][9]], 'índice: 100 = a hora mais forte');
    $ag = [];
    foreach (naval_analise_dias('2026-10-01', '2026-11-24') as $d) $ag[$d] = ['rotas' => ['1001' => ['t' => $d === '2026-11-18' ? 900.0 : ($d === '2026-11-24' ? 500.0 : 100.0)]]];
    $r = naval_sala_recordes($ag, '2026-11-25', 950.0);
    igual(['2026-11-18', 900.0], [$r['melhor_dia']['d'], $r['melhor_dia']['t']], 'melhor dia');
    igual('2026-11-18', $r['melhor_igual']['d'], 'melhor quarta-feira');
    igual(['posicao' => 2, 'de' => 55], $r['ontem'], 'ontem foi o 2.º melhor dia');
    igual(['2026-10', 3100.0], [$r['melhor_mes']['m'], $r['melhor_mes']['t']], 'melhor mês completo');
    verdade($r['hoje']['bate_recorde'], 'hoje bate o recorde');
    $k = naval_sala_contagem($ag, '2026-11-25', ['2026-11-26' => ['folga' => false], '2026-11-29' => ['folga' => true]], $r);
    igual([2, 6], [$k['dias_trabalho'], $k['dias_calendario']], 'hoje + amanhã; 6 dias de calendário');
    verdade($k['igualar']['atingido'], 'mês anterior já igualado');
    $m = naval_sala_momentos(['hora' => '2026-11-25 10:00', 'hoje' => ['progresso' => 1.1]], $r, [['rota' => '1003', 'promotor' => 'Eva']]);
    igual(['objectivo_dia', 'recorde', 'rota'], array_column($m, 'tipo'), 'momentos');
    $ab = naval_sala_avancados_aberta(['momentos' => $m, 'recordes' => $r, 'contagem' => $k]);
    $j = (string) json_encode($ab);
    verdade(strpos($j, 'Eva') === false && strpos($j, '"t"') === false && strpos($j, 'alvo') === false, 'open space sem nomes nem Kz');
});

teste('painel da sala: funil, artigos, Pareto, quadrante, stock, operação e mapa', function () {
    $f = naval_sala_funil(['1001' => [['c' => 'A'], ['c' => 'B'], ['c' => 'C']]], ['A' => ['t' => 500.0], 'B' => ['t' => 50.0], 'Z' => ['t' => 10.0]], ['A' => 'ok', 'B' => 'longe'], 100.0);
    igual([3, 2, 1, 1], array_column($f['etapas'], 'n'), 'planeados, compraram, GPS, acima da média');
    igual(1, $f['fora_plano']);
    igual(3, count(naval_sala_funil(['1001' => [['c' => 'A']]], [], null, 100.0)['etapas']), 'sem GPS: sem a etapa da prova');
    $ag = [];
    foreach (naval_analise_dias('2026-10-10', '2026-11-24') as $d) {
        $recente = $d >= '2026-11-18';
        $cl = [];
        for ($i = 1; $i <= 20; $i++) $cl['C' . $i] = ['t' => $i <= 2 ? 1000.0 : 10.0, 'r' => '1001'];
        if ($d >= '2026-11-05') unset($cl['C2']);
        $ag[$d] = ['rotas' => ['1001' => ['t' => 100.0, 'd' => 4], '1002' => ['t' => 300.0, 'd' => 2]], 'clientes' => $cl,
            'artigos' => ['1001' => ['X' => ['n' => 'Leite', 'q' => 10.0, 't' => $recente ? 150.0 : 100.0], 'Y' => ['n' => 'Agua', 'q' => 5.0, 't' => $recente ? 50.0 : 100.0]]]];
    }
    $a = naval_sala_artigos($ag, '2026-11-25');
    igual([['X', 0.5]], array_map(function ($x) { return [$x['c'], $x['var']]; }, $a['sobem']), 'sobe 50 %');
    igual([['Y', -0.5]], array_map(function ($x) { return [$x['c'], $x['var']]; }, $a['descem']), 'desce 50 %');
    $p = naval_sala_pareto($ag, '2026-11-25');
    igual([20, 2, 1], [$p['clientes'], $p['n80'], $p['parados']], 'dois clientes fazem 80 %; um parado');
    $q = naval_sala_quadrante($ag, '2026-11-25', ['1001' => 'Ana']);
    igual([['1001', 'Ana', 4.0, 25.0], ['1002', '', 2.0, 150.0]], array_map(function ($x) { return [$x['rota'], $x['promotor'], $x['compras_dia'], $x['valor_medio']]; }, $q['rotas']), 'compras por dia e valor médio');
    $s = naval_sala_stock([['itens' => [['codigo' => 'X', 'quantidade' => 20], ['codigo' => 'Y', 'quantidade' => 0]]]], $ag, '2026-11-25');
    igual([['Y', 0.0], ['X', 2.0]], array_map(function ($x) { return [$x['c'], $x['dias']]; }, $s['lista']), 'menor cobertura primeiro');
    igual([1, 1], [$s['ruptura'], $s['criticos']]);
    $o = naval_sala_operacao([['tipo' => 'carrinha', 'atraso' => true], ['tipo' => 'po'], ['tipo' => 'po_validar']], 2);
    igual([5, 3], [$o['total'], $o['atraso']], 'pendentes de todos e dias do Pinguim BO por fechar');
    $m = naval_sala_mapa([['rota' => '1001', 'percurso' => [[-8.8, 13.2, '08:00'], [-8.81, 13.21, '09:30']], 'visitas' => [['prova' => 'ok'], ['prova' => 'longe']], 'km' => 4.2]],
        ['1001' => [['lat' => -8.7, 'lng' => 13.1]]], ['1001' => 'Ana']);
    igual([[-8.81, 13.21], '09:30', 2, 1, 'Ana'], [$m['rotas'][0]['pos'], $m['rotas'][0]['hora'], $m['rotas'][0]['vendas'], $m['rotas'][0]['prova'], $m['rotas'][0]['promotor']], 'última posição');
    $ab = naval_sala_aberta(['hoje' => [], 'ontem' => [], 'semana' => [], 'mes' => [], 'rotas' => [], 'extra' => ['mapa' => $m, 'pareto' => $p, 'quadrante' => $q, 'stock' => $s, 'operacao' => $o, 'artigos' => $a]]);
    verdade(!array_intersect(['mapa', 'pareto', 'quadrante', 'stock', 'operacao', 'artigos'], array_keys($ab)), 'só na sala interna');
});

teste('painel da sala: quem escolheu todos os ecrãs antigos passa a ver também os novos', function () {
    igual(NAVAL_SALA_ECRAS_ABERTA, naval_sala_config(['ecras' => ['hoje', 'destaques', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes'], 'personalizado' => true], 'aberta')['ecras']);
    igual(['hoje', 'ritmo'], naval_sala_config(['ecras' => ['hoje', 'ritmo'], 'personalizado' => true], 'aberta')['ecras'], 'uma escolha parcial mantém-se');
});
