<?php
// Indicadores da app (nucleo/servicos/previsao.php): valores sem IVA e com IVA, e o último dia de trabalho para o
// aviso «sem vendas desde…» (não dispara depois de um domingo ou de um feriado).
naval_servico('previsao');
naval_servico('controlo');

teste('indicadores: com IVA ao lado do sem IVA; último dia de trabalho pelo calendário', function () {
    $dia = function (float $t, float $ti) { return ['rotas' => ['1005' => ['t' => $t, 'ti' => $ti, 'd' => 1, 'c' => 1, 'nc' => 0]], 'artigos' => []]; };
    $ag = [
        '2026-11-14' => $dia(100000, 114000),   // sábado
        '2026-11-16' => $dia(200000, 228000),   // segunda-feira
    ];
    $r = naval_indicadores(strtotime('2026-11-16 10:00'), null, $ag, ['1005' => 'Olindo']);
    igual([200000.0, 228000.0], [$r['hoje']['total'], $r['hoje']['total_iva']]);
    igual(228000.0, $r['semana']['total_iva']);
    igual(342000.0, $r['mes']['total_iva']);
    // segunda-feira: o último dia de trabalho foi sábado (domingo é folga), não «ontem»
    igual('2026-11-14', $r['dia_util_anterior']);
    igual('2026-11-15', $r['ontem']['dia']);
    // 11-11 é feriado (Dia da Independência): na quinta-feira, o anterior de trabalho é 10-11
    igual('2026-11-10', naval_indicadores(strtotime('2026-11-12 10:00'), null, $ag, [])['dia_util_anterior']);
});
