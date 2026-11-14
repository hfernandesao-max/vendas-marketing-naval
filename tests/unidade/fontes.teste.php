<?php
// Camada de fontes: OLAP só nos dias carregados e conferidos; hoje sempre do Pinguim BO; disjuntor; conferência.
naval_servico('fontes');

teste('fontes: sem OLAP configurado, tudo do Pinguim BO (qualquer que seja o FONTE_VENDAS)', function () {
    igual('pinguim', naval_fontes_modo('vendas'));
    igual([['pinguim', '2026-11-10', '2026-11-13']], naval_fontes_plano('20261110', '20261113'));
    igual('pinguim', naval_fontes_resumo('20261110', '20261113'));
});

teste('fontes: contrato rtm — todas as colunas das vistas, ou a lista das que faltam', function () {
    $col = [];
    foreach (NAVAL_FONTES_VISTAS as $v => $cs) foreach ($cs as $c) $col[$v][$c] = true;
    verdade(naval_fontes_contrato_avaliar($col)['ok']);
    unset($col['f_pagamento']['MEIO_CODIGO'], $col['ctl_carga']);
    $r = naval_fontes_contrato_avaliar($col);
    verdade(!$r['ok']);
    verdade(in_array('rtm.f_pagamento.MEIO_CODIGO', $r['faltam'], true));
    verdade(in_array('rtm.ctl_carga.FIM', $r['faltam'], true));
});

teste('fontes: corte conferido — só dias anteriores a hoje, com carga concluída e conferência depois dessa carga', function () {
    $cargas = [
        '2026-11-10' => ['fim' => '2026-11-11 02:10:00', 'estado' => 'concluida', 'linhas' => 10, 'versao' => '1.0'],
        '2026-11-11' => ['fim' => '2026-11-12 02:10:00', 'estado' => 'concluida', 'linhas' => 10, 'versao' => '1.0'],   // recarregado depois da conferência
        '2026-11-12' => ['fim' => '2026-11-13 02:10:00', 'estado' => 'a_correr', 'linhas' => 0, 'versao' => '1.0'],
        '2026-11-09' => ['fim' => '2026-11-10 02:10:00', 'estado' => 'concluida', 'linhas' => 10, 'versao' => '2.0'],   // versão maior do contrato
        '2026-11-13' => ['fim' => '2026-11-13 12:00:00', 'estado' => 'concluida', 'linhas' => 10, 'versao' => '1.0'],   // hoje: nunca
    ];
    $conf = [
        '2026-11-09' => ['ok' => true, 'carga_fim' => '2026-11-10 02:10:00'],
        '2026-11-10' => ['ok' => true, 'carga_fim' => '2026-11-11 02:10:00'],
        '2026-11-11' => ['ok' => true, 'carga_fim' => '2026-11-11 23:00:00'],
        '2026-11-13' => ['ok' => true, 'carga_fim' => '2026-11-13 12:00:00'],
    ];
    $dias = ['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'];
    igual(['2026-11-10'], naval_fontes_dias_conferidos($dias, '2026-11-13', $cargas, function ($d) use ($conf) { return $conf[$d] ?? null; }));
    // conferência com diferenças: não passa
    $conf['2026-11-10']['ok'] = false;
    igual([], naval_fontes_dias_conferidos($dias, '2026-11-13', $cargas, function ($d) use ($conf) { return $conf[$d] ?? null; }));
});

teste('fontes: segmentos seguidos por fonte', function () {
    $dias = naval_fontes_dias('20261108', '20261113');
    igual(6, count($dias));
    igual([['pinguim', '2026-11-08', '2026-11-08'], ['olap', '2026-11-09', '2026-11-10'], ['pinguim', '2026-11-11', '2026-11-11'], ['olap', '2026-11-12', '2026-11-12'], ['pinguim', '2026-11-13', '2026-11-13']],
        naval_fontes_segmentos($dias, ['2026-11-09', '2026-11-10', '2026-11-12']));
});

teste('fontes: conferência — resumo por rota e diferenças em documentos, totais, linhas e pagamentos por meio', function () {
    $v = [
        ['rota' => '1001', 'total' => 1000.0, 'total_sem_iva' => 877.19, 'pagamentos' => [['codigo' => 'NU', 'valor' => 600.0], ['codigo' => 'MC', 'valor' => 400.0]]],
        ['rota' => '1001', 'total' => -100.0, 'total_sem_iva' => -87.72, 'pagamentos' => []],
        ['rota' => '1002', 'total' => 500.0, 'total_sem_iva' => 438.6, 'pagamentos' => [['codigo' => 'NU', 'valor' => 500.0]]],
    ];
    $a = [['rota' => '1001', 'quantidade' => 3.0, 'gross_total' => 1000.0], ['rota' => '1002', 'quantidade' => 1.0, 'gross_total' => 500.0]];
    $p = naval_fontes_resumo_dia($v, $a);
    igual(2, $p['1001']['documentos']);
    igual(900.0, $p['1001']['total']);
    igual(['MC' => 400.0, 'NU' => 600.0], $p['1001']['pagamentos']);
    igual([], naval_fontes_diferencas($p, $p));
    $o = $p;
    $o['1002']['total'] = 499.99;
    $o['1001']['pagamentos']['NU'] = 550.0;
    unset($o['1003']);
    $d = naval_fontes_diferencas($p, $o);
    igual(2, count($d));
    igual(['rota' => '1001', 'medida' => 'pagamento NU', 'pinguim' => 600.0, 'olap' => 550.0], $d[0]);
    igual('total', $d[1]['medida']);
    // rota que só existe numa das fontes
    $o['1004'] = $p['1002'];
    verdade(count(naval_fontes_diferencas($p, $o)) > 2);
});

teste('fontes: disjuntor — 3 falhas em 5 minutos param o OLAP durante 5 minutos; um sucesso fecha-o', function () {
    $t = 1800000000;
    naval_fontes_disjuntor(true, '', $t);
    naval_fontes_disjuntor(false, 'tempo esgotado', $t);
    naval_fontes_disjuntor(false, 'tempo esgotado', $t + 10);
    verdade(!naval_fontes_disjuntor_aberto($t + 20));
    $e = naval_fontes_disjuntor(false, 'tempo esgotado', $t + 20);
    igual($t + 320, $e['aberto_ate']);
    verdade(naval_fontes_disjuntor_aberto($t + 100));
    verdade(!naval_fontes_disjuntor_aberto($t + 400));
    // falhas espaçadas (fora da janela de 5 minutos) não abrem
    naval_fontes_disjuntor(true, '', $t);
    naval_fontes_disjuntor(false, 'x', $t);
    naval_fontes_disjuntor(false, 'x', $t + 400);
    $e = naval_fontes_disjuntor(false, 'x', $t + 800);
    verdade(empty($e['aberto_ate']));
    naval_fontes_disjuntor(true, '', $t);
});

teste('fontes: regras dos anulados iguais às do Pinguim BO, nas colunas do contrato', function () {
    $c = naval_fontes_condicao_validos();
    verdade(strpos($c, 'D.ELIMINADO = 0') === 0);
    verdade(strpos($c, 'MOTIVO_ANULACAO') !== false);   // motivo de cancelamento (regra activa por omissão)
    naval_bd_so_leitura('SELECT 1 FROM rtm.f_documento D WHERE ' . $c);
    verdade(true);
});
