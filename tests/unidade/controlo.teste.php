<?php
// Controlo de gestão (nucleo/servicos/controlo.php): fecho previsto do mês, clientes, artigos, rotas e riscos.
naval_servico('analise');
naval_servico('controlo');

teste('controlo: fecho previsto = vendido até hoje + habitual dos dias que faltam; clientes novos e por recuperar', function () {
    $agora = strtotime('2026-11-12 15:00');
    $ag = [];
    // 90 dias: 100 000 Kz por dia útil (segunda a sábado), domingos a zero; rota 1001
    for ($t = strtotime('2026-08-14 12:00'); $t <= $agora; $t += 86400) {
        $d = date('Y-m-d', $t);
        $v = (int) date('N', $t) === 7 ? 0 : 100000;
        $ag[$d] = ['rotas' => $v ? ['1001' => ['t' => $v, 'd' => 4, 'nc' => 0]] : [], 'clientes' => [], 'artigos' => [], 'ca' => []];
    }
    // clientes: A compra sempre; B só em Outubro (por recuperar); C só este mês (novo)
    foreach (['2026-10-05', '2026-10-20', '2026-11-03', '2026-11-10'] as $d) $ag[$d]['clientes']['A'] = ['n' => 'Loja A', 'r' => '1001', 't' => 50000, 'd' => 1];
    $ag['2026-10-15']['clientes']['B'] = ['n' => 'Cantina B', 'r' => '1001', 't' => 30000, 'd' => 2];
    $ag['2026-11-11']['clientes']['C'] = ['n' => 'Mercado C', 'r' => '1001', 't' => 20000, 'd' => 1];
    $ag['2026-11-11']['artigos']['1001'] = ['10000009' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 10, 't' => 80000]];
    $ag['2026-10-06']['artigos']['1001'] = ['10000009' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 5, 't' => 40000]];
    $ag['2026-11-11']['rotas']['1001']['nc'] = 1;
    $c = naval_controlo($ag, $agora, ['1001' => 'Andrea']);
    // 12-11: 11 dias até ontem (9 úteis + 2 domingos... 1, 8 = domingos) → 9 × 100 000; hoje 100 000; faltam 18 dias (3 domingos) → 15 × 100 000
    igual(1000000.0, $c['fecho']['ate_hoje']);
    igual(2500000.0, $c['fecho']['previsto']);
    igual(2700000.0, $c['fecho']['mes_anterior'], 'Outubro: 27 dias úteis');
    igual(-0.074, $c['fecho']['variacao']);
    igual(2, $c['clientes']['activos']);
    igual(1, $c['clientes']['novos'], 'C não comprou nos 60 dias antes');
    igual(1, $c['clientes']['por_recuperar']);
    igual('Cantina B', $c['clientes']['recuperar'][0]['nome']);
    igual(30000.0, $c['clientes']['valor_por_recuperar']);
    igual('Fraldas', $c['artigos'][0]['nome']);
    igual(1.0, $c['artigos'][0]['variacao'], 'o dobro dos mesmos dias de Outubro');
    igual('1001', $c['rotas'][0]['rota']);
    igual('Andrea', $c['rotas'][0]['promotor']);
    igual(10, $c['rotas'][0]['dias']);
    igual(4.0, $c['rotas'][0]['docs_dia']);
    igual(25000.0, $c['rotas'][0]['valor_medio']);
    igual(1, $c['riscos']['notas_credito']);
    igual(1.0, $c['riscos']['top5_clientes']);
    igual(['rota' => '1001', 'peso' => 1.0], $c['riscos']['maior_rota']);
});
