<?php
// Perdas e tempo parado por rota; calendário editável no portal e aviso da véspera de um dia de descanso.
naval_servico('perdas');
naval_servico('calendario');

teste('perdas: faltas, falta de dinheiro na entrega e custo do tempo parado por rota; decisões comerciais à parte', function () {
    $f = [
        'vendas' => [['rota' => '1004', 'total' => 100000.0, 'nc' => false], ['rota' => '1004', 'total' => -5000.0, 'nc' => true], ['rota' => '1007', 'total' => 50000.0, 'nc' => false]],
        'artigos' => [['rota' => '1004', 'desconto' => 1200.0, 'nc' => false], ['rota' => '1004', 'desconto' => 300.0, 'nc' => true]],
        'anulados' => [['rota' => '1007', 'documento' => 'FR 1/9', 'data' => '2026-11-20', 'total' => 8000.0]],
        'carrinhas' => [['rota' => '1004', 'falta_kz' => 2500.0, 'sobra_kz' => 0.0, 'motivos' => ['Avaria' => 1]], ['rota' => '1007', 'falta_kz' => 0.0, 'sobra_kz' => 900.0, 'motivos' => []]],
        'entregas' => [['rota' => '1004', 'dif_kz' => -1000.0], ['rota' => '1007', 'dif_kz' => 500.0]],
        'parado' => ['1004' => ['min' => 90, 'km' => 42.5], '1007' => ['min' => 30, 'km' => 10]],
    ];
    $x = naval_perdas_juntar($f, ['1004' => 'Ana', '1007' => 'Rui'], 2000.0);
    $r = array_column($x['rotas'], null, 'rota');
    igual([100000.0, 5000.0, 1, 1200.0], [$r['1004']['vendas'], $r['1004']['nc_kz'], $r['1004']['nc_n'], $r['1004']['desconto_kz']]);
    igual(3000.0, $r['1004']['parado_kz'], '90 min × 2000 Kz/h');
    igual(6500.0, $r['1004']['perdas_kz'], 'falta 2500 + entrega 1000 + parado 3000');
    igual(1000.0, $r['1007']['perdas_kz'], 'sobra na entrega não é perda; só o tempo parado');
    igual([1, 8000.0], [$r['1007']['anulados_n'], $r['1007']['anulados_kz']]);
    igual(['Avaria' => 1], $r['1004']['motivos']);
    igual('1004', $x['rotas'][0]['rota'], 'maiores perdas primeiro');
    igual([7500.0, -500.0, 120], [$x['total']['perdas_kz'], $x['total']['entrega_dif_kz'], $x['total']['parado_min']]);
    // sem custo por hora: os minutos ficam, o custo fica por definir
    $y = naval_perdas_juntar($f, [], 0.0);
    igual([null, null, 3500.0], [$y['custo_hora'], $y['rotas'][0]['parado_kz'], $y['rotas'][0]['perdas_kz']]);
});

teste('calendário: folga e dia de trabalho definidos no portal entram em todo o portal; motivo obrigatório; só de hoje em diante', function () {
    $d = date('Y-m-d', strtotime('next wednesday', time() + 86400));
    igual(false, naval_cal_folga($d));
    naval_cal_definir($d, 'folga', 'Ponte da empresa', 'dir');
    verdade(isset(naval_cal_lista('CALENDARIO_FOLGAS')[$d]), 'a folga do portal entra na lista');
    $e = array_values(array_filter(naval_cal_excepcoes(date('Y-m-d'), 30), function ($x) use ($d) { return $x['dia'] === $d; }));
    igual(['folga', 'Ponte da empresa', 'dir', true], [$e[0]['tipo'], $e[0]['motivo'], $e[0]['por'], $e[0]['portal']]);
    lanca(400, function () use ($d) { naval_cal_definir($d, 'folga', '', 'dir'); });
    lanca(400, function () { naval_cal_definir('2020-01-01', 'folga', 'Passado', 'dir'); });
    naval_cal_definir($d, '', '', 'dir');
    igual([], array_values(array_filter(naval_cal_excepcoes(date('Y-m-d'), 30), function ($x) use ($d) { return $x['dia'] === $d; })), 'volta ao normal');
    // feriado: aparece sozinho; 25-12 é feriado nacional
    $natal = date('Y') . '-12-25';
    if ($natal >= date('Y-m-d')) {
        $n = array_values(array_filter(naval_cal_excepcoes(date('Y') . '-12-01', 40), function ($x) use ($natal) { return $x['dia'] === $natal; }));
        igual('feriado', $n[0]['tipo']);
    }
});

teste('calendário: aviso na véspera de um feriado ou folga (não na véspera de domingo), uma vez', function () {
    $avisos = [];
    $not = function ($tit, $corpo, $tx, $chave) use (&$avisos) { $avisos[] = [$tit, $chave]; };
    // 2030-12-24 (terça) → 25 é feriado (Natal)
    igual('2030-12-25', naval_cal_aviso_vespera(strtotime('2030-12-24 17:45:00'), $not));
    igual(['Amanhã (25-12-2030) não se trabalha', 'vespera-2030-12-25'], $avisos[0]);
    igual(null, naval_cal_aviso_vespera(strtotime('2030-12-24 18:30:00'), $not), 'uma só vez');
    igual(null, naval_cal_aviso_vespera(strtotime('2030-12-24 12:00:00'), $not), 'antes das 17:30');
    igual(null, naval_cal_aviso_vespera(strtotime('2030-12-28 17:45:00'), $not), 'sábado → domingo: toda a gente sabe');
});
