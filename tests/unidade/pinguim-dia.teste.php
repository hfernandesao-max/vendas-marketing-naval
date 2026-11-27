<?php
// Abertura e fecho do dia no Pinguim BO (SESSION e FCL), com os casos reais de 17 a 25-11-2026.
naval_servico('pinguim-dia');
naval_servico('relatorio-vendas');

teste('dia no Pinguim BO: abertura certa ou na véspera; fecho a tempo, atrasado, por fechar ou ainda aberto', function () {
    $agora = strtotime('2026-11-25 10:00:00');
    $sessoes = [
        ['ROTA' => '1011', 'DIA' => '2026-11-25', 'ABERTO_EM' => '2026-11-25 07:32:37'],
        ['ROTA' => '1010', 'DIA' => '2026-11-25', 'ABERTO_EM' => '2026-11-24 16:01:26'],   // aberto na véspera
        ['ROTA' => '', 'DESCR' => '1002 - FHR PIV', 'DIA' => '2026-11-19', 'ABERTO_EM' => '2026-11-19 06:50:00'],
        ['ROTA' => '9999', 'DIA' => '2026-11-25', 'ABERTO_EM' => '2026-11-25 07:00:00'],    // formação: fica de fora
    ];
    $fechos = [
        ['ROTA' => '1011', 'DIA' => '2026-11-24', 'FECHADO_EM' => '2026-11-25 07:32:37', 'DOCUMENTO' => 'FCL A1011/22'],
        ['ROTA' => '1006', 'DIA' => '2026-11-21', 'FECHADO_EM' => '2026-11-22 01:00:00', 'DOCUMENTO' => 'FCL A1006/16'],
        ['ROTA' => '1002', 'DIA' => '2026-11-19', 'FECHADO_EM' => '2026-11-23 15:46:15', 'DOCUMENTO' => 'FCL A1002/18'],
    ];
    $docs = [
        ['ROTA' => '1002', 'DIA' => '2026-11-19', 'N' => 12, 'VENDAS' => 10, 'NOUTRO_DIA' => 4, 'ULTIMO' => '2026-11-23 15:46:15'],
        ['ROTA' => '1004', 'DIA' => '2026-11-24', 'N' => 20, 'VENDAS' => 18, 'NOUTRO_DIA' => 0, 'ULTIMO' => '2026-11-24 13:05:32'],
        ['ROTA' => '1005', 'DIA' => '2026-11-25', 'N' => 3, 'VENDAS' => 3, 'NOUTRO_DIA' => 0, 'ULTIMO' => '2026-11-25 09:40:00'],
    ];
    $l = naval_pdia_montar($sessoes, $fechos, $docs, $agora, '06:00');
    $por = [];
    foreach ($l as $x) $por[$x['rota'] . '|' . $x['dia']] = $x;
    igual(false, isset($por['9999|2026-11-25']), 'loja de formação fora');
    igual(['certa', 'aberto'], [$por['1011|2026-11-25']['abertura'], $por['1011|2026-11-25']['fecho']], 'hoje: aberto de manhã e ainda aberto');
    igual('antecipada', $por['1010|2026-11-25']['abertura'], 'dia 25 aberto a 24 às 16:01');
    igual(['atrasado', 1, 'FCL A1011/22'], [$por['1011|2026-11-24']['fecho'], $por['1011|2026-11-24']['atraso_horas'], $por['1011|2026-11-24']['documento']], 'fechado às 07:32 do dia seguinte');
    igual('a_tempo', $por['1006|2026-11-21']['fecho'], 'fecho automático à 01:00');
    igual(['atrasado', 81, 4], [$por['1002|2026-11-19']['fecho'], $por['1002|2026-11-19']['atraso_horas'], $por['1002|2026-11-19']['noutro_dia']], 'dia 19 fechado só a 23, com vendas de outros dias');
    igual('certa', $por['1002|2026-11-19']['abertura'], 'rota pela descrição da loja');
    igual(['por_fechar', 4], [$por['1004|2026-11-24']['fecho'], $por['1004|2026-11-24']['atraso_horas']], 'vendeu a 24 e não fechou');
    $r = naval_pdia_resumo($l);
    $c = $r['contagens'];
    igual([1, 2, 1, 3, 1, 4], [$c['a_tempo'], $c['atrasado'], $c['por_fechar'], $c['aberto'], $c['antecipada'], $c['noutro_dia']], 'contagens');
    igual(4, count($r['excepcoes']), 'excepções: 1011/24, 1002/19, 1004/24 e 1010/25');
    $a = naval_pdia_avisos($l, $agora);
    igual(['dia_fechar|1004|2026-11-24', 'dia_abrir|1010|2026-11-25'], array_column($a, 'chave'), 'avisos: por fechar e aberto na véspera (o 1002 de 19-11 já é antigo)');
});

teste('dia no Pinguim BO: o fecho feito no telemóvel (SESSION, operação 12) conta, mesmo sem FCL (rotas 1001, 1002 e 1005, 24 a 26-11)', function () {
    $agora = strtotime('2026-11-27 08:49:00');
    $sessoes = [['ROTA' => '1001', 'DIA' => '2026-11-26', 'ABERTO_EM' => '2026-11-25 17:00:12'], ['ROTA' => '1005', 'DIA' => '2026-11-26', 'ABERTO_EM' => '2026-11-25 13:54:23'],
        ['ROTA' => '1002', 'DIA' => '2026-11-24', 'ABERTO_EM' => '2026-11-23 16:14:13']];
    $fechos = [['ROTA' => '1005', 'DIA' => '2026-11-26', 'FECHADO_EM' => '2026-11-27 08:40:01', 'DOCUMENTO' => 'FCL A1005/20']];
    $docs = [['ROTA' => '1001', 'DIA' => '2026-11-26', 'N' => 7, 'VENDAS' => 5, 'NOUTRO_DIA' => 0, 'ULTIMO' => '2026-11-26 15:18:24'],
        ['ROTA' => '1005', 'DIA' => '2026-11-26', 'N' => 8, 'VENDAS' => 7, 'NOUTRO_DIA' => 0, 'ULTIMO' => '2026-11-26 13:57:09'],
        ['ROTA' => '1002', 'DIA' => '2026-11-24', 'N' => 5, 'VENDAS' => 3, 'NOUTRO_DIA' => 0, 'ULTIMO' => '2026-11-24 15:14:36']];
    $fd = [
        ['ROTA' => '1001', 'DIA' => '2026-11-26', 'FECHADO_EM' => '2026-11-26 15:18:24'],                 // fechou no telemóvel, sem FCL
        ['ROTA' => '1005', 'DIA' => '2026-11-26', 'FECHADO_EM' => '2026-11-26 13:57:09'],                 // fechou no telemóvel…
        ['ROTA' => '1005', 'DIA' => '2026-11-26', 'FECHADO_EM' => '2026-11-27 08:40:01'],                 // …e de novo com o FCL de manhã
        ['ROTA' => '1002', 'DIA' => '2026-11-24', 'FECHADO_EM' => '2026-11-23 16:36:42'],                 // fecho antes de vender (reabriu)…
        ['ROTA' => '1002', 'DIA' => '2026-11-24', 'FECHADO_EM' => '2026-11-24 15:14:36'],                 // …o fecho verdadeiro
    ];
    $por = array_column(naval_pdia_montar($sessoes, $fechos, $docs, $agora, '06:00', $fd), null, 'dia');
    $l = [];
    foreach (naval_pdia_montar($sessoes, $fechos, $docs, $agora, '06:00', $fd) as $x) $l[$x['rota']] = $x;
    igual(['2026-11-26 15:18:24', 'a_tempo', 'Fecho do dia no telemóvel'], [$l['1001']['fechado_em'], $l['1001']['fecho'], $l['1001']['documento']], '1001: fechado, a contagem pode gravar-se');
    igual(['2026-11-26 13:57:09', 'a_tempo'], [$l['1005']['fechado_em'], $l['1005']['fecho']], '1005: conta o fecho do telemóvel, não o FCL da manhã seguinte');
    igual('2026-11-24 15:14:36', $l['1002']['fechado_em'], '1002: o fecho depois do último documento, não o de antes de vender');
    igual(['fecho_pinguim' => '2026-11-26 15:18:24'], naval_carr_decisao_fecho(array_values($l), '1001', '2026-11-26', false, ''), 'a contagem da 1001 grava-se sem excepção');
    verdade(count($por) > 0);
});

teste('fecho do dia: o dia fechado no Pinguim BO (FCL) prova as vendas completas, mesmo sem sincronizar depois das 15:00', function () {
    $dia = date('Y-m-d', time() - 4 * 86400);
    $f = $GLOBALS['rv_fontes'];
    $sem = $f($dia, ['sync' => [['rota' => '1001', 'ultima' => $dia . ' 12:00:00'], ['rota' => '1002', 'ultima' => $dia . ' 11:00:00']]]);
    $x = naval_rv_controlos($dia, $sem);
    igual(['pendente', ['1001', '1002']], [$x['controlos']['pinguim']['estado'], $x['controlos']['pinguim']['rotas']], 'sem fecho nem sincronização');
    $x = naval_rv_controlos($dia, ['fechos' => ['1001' => date('Y-m-d', strtotime($dia) + 86400) . ' 01:00:00']] + $sem);
    igual(['pendente', ['1002']], [$x['controlos']['pinguim']['estado'], $x['controlos']['pinguim']['rotas']], 'a 1001 fechou o dia');
    $x = naval_rv_controlos($dia, ['fechos' => ['1001' => $dia . ' 23:00:00', '1002' => $dia . ' 23:10:00']] + $sem);
    igual(['ok', '2 rotas com vendas: todas com o dia fechado no Pinguim BO.'], [$x['controlos']['pinguim']['estado'], $x['controlos']['pinguim']['detalhe']], 'as duas fecharam');
});

teste('vendas depois do fecho do dia: só depois do FCL feito no próprio dia; um FCL de madrugada não marca as vendas da manhã seguinte', function () {
    $linhas = [
        ['rota' => '1010', 'dia' => '2026-11-26', 'fechado_em' => '2026-11-26 15:30:00'],
        ['rota' => '1001', 'dia' => '2026-11-25', 'fechado_em' => '2026-11-26 01:00:00'],   // FCL de madrugada
        ['rota' => '1002', 'dia' => '2026-11-26', 'fechado_em' => null],
    ];
    $vendas = [
        ['ROTA' => '1010', 'DOC' => 'FR0126S77782N/30', 'HORA' => '2026-11-26 16:12:00', 'TOTAL' => 15000],
        ['ROTA' => '1010', 'DOC' => 'FR0126S77782N/29', 'HORA' => '2026-11-26 15:20:00', 'TOTAL' => 9000],
        ['ROTA' => '1001', 'DOC' => 'FR0126S78519N/40', 'HORA' => '2026-11-26 08:30:00', 'TOTAL' => 5000],
        ['ROTA' => '1002', 'DOC' => 'FR0126S77755N/30', 'HORA' => '2026-11-26 17:00:00', 'TOTAL' => 7000],
    ];
    $r = naval_pdia_vendas_depois($linhas, $vendas);
    igual([['1010', 'FR0126S77782N/30', '2026-11-26 15:30:00']], array_map(function ($x) { return [$x['rota'], $x['doc'], $x['fechado_em']]; }, $r));
});
