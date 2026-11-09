<?php
// Valor do portal para a Naval: contagens reais (carrinhas, ficheiros SAP, relatórios, utilizadores) e a fórmula.
naval_servico('valor');

teste('valor: conta carrinhas, fechos automáticos, faltas, ficheiros SAP, relatórios e utilizadores do mês', function () {
    $agora = time();
    $m = date('Y-m', $agora);
    $mc = '2019-03';   // mês só deste teste (outros testes gravam carrinhas no mês actual)
    $dia = $mc . '-02';
    $dir = naval_pasta('carrinha/' . $dia);
    foreach (glob($dir . '/*.json') ?: [] as $f) @unlink($f);
    naval_json_gravar($dir . '/1001.json', ['estado' => 'aprovada', 'aprovado_por' => 'automatico', 'historico' => [['falta_kz' => 0, 'sobra_kz' => 0]]]);
    naval_json_gravar($dir . '/1002.json', ['estado' => 'aprovada', 'aprovado_por' => 'ana', 'historico' => [['falta_kz' => 9000], ['falta_kz' => 5000, 'sobra_kz' => 200]]]);
    naval_json_gravar($dir . '/1003.json', ['estado' => 'por_aprovar', 'historico' => [['falta_kz' => 100]]]);
    naval_json_gravar($dir . '/_dados.json', ['rotas' => []]);
    naval_json_gravar(naval_pasta('sap') . '/lotes.json', [
        ['numero' => 1, 'em' => $mc . '-03 10:00:00'],
        ['numero' => 2, 'em' => $mc . '-04 10:00:00', 'anulado' => ['motivo' => 'x']],
        ['numero' => 3, 'em' => '2020-01-04 10:00:00'],
    ]);
    $antes = naval_valor_registos([$m])[$m];
    naval_registar('exportacao', ['pagina' => 'vendas-dia'], 'ana');
    naval_registar('exportacao', ['pagina' => 'documentos'], 'ana');
    naval_registar('entrada', [], 'utilizador-valor-1');
    naval_registar('entrada', [], 'utilizador-valor-1');
    naval_registar('entrada', [], 'utilizador-valor-2');
    $reg = naval_valor_registos([$m])[$m];
    igual($antes['exportacoes'] + 2, $reg['exportacoes']);
    verdade($reg['utilizadores'] >= 2, 'utilizadores distintos');
    $c = naval_valor_carrinhas([$mc])[$mc];
    igual(3, $c['rotas']);
    igual(2, $c['conciliadas']);
    igual(1, $c['automaticas']);
    igual(5000.0, $c['falta_kz'], 'conta a última contagem aprovada, não as anteriores');
    igual(1, naval_valor_sap([$mc])[$mc], 'sem os anulados nem outros meses');
});

teste('valor: fórmula; parâmetros a zero ficam por definir e não entram no total', function () {
    $c = ['rotas' => 10, 'conciliadas' => 40, 'automaticas' => 30, 'falta_kz' => 20000.0, 'sobra_kz' => 0.0];
    $reg = ['exportacoes' => 12, 'utilizadores' => 25];
    $p = array_map(function ($x) { return (float) $x[1]; }, NAVAL_VALOR_PARAMETROS);
    $r = naval_valor_calcular($c, 2, $reg, $p);
    // (30×20 + 40×15 + 2×120 + 12×15) ÷ 60 = 1620 ÷ 60 = 27 h
    igual(27.0, $r['horas']);
    igual(null, $r['tempo_kz']);
    igual(null, $r['total_kz']);
    $p['custo_hora_kz'] = 3000;
    $p['perda_referencia_kz'] = 10000;
    $p['licenca_utilizador_kz'] = 50000;
    $p['reconstrucao_kz'] = 120000000;
    $p['anos_amortizacao'] = 5;
    $r = naval_valor_calcular($c, 2, $reg, $p);
    igual(81000.0, $r['tempo_kz']);
    igual(80000.0, $r['stock_kz'], '10 000 × 10 rotas − 20 000 de faltas');
    igual(1250000.0, $r['licencas_kz']);
    igual(2000000.0, $r['construcao_kz']);
    igual(3411000.0, $r['total_kz']);
    $c['falta_kz'] = 500000.0;
    igual(0.0, naval_valor_calcular($c, 2, $reg, $p)['stock_kz'], 'nunca abaixo de zero');
});

teste('valor: gravar parâmetros valida, regista e a vista mostra o que falta definir', function () {
    @unlink(naval_valor_ficheiro());
    $v = naval_valor_vista(3, time());
    igual(3, count($v['meses']));
    igual(['custo_hora_kz', 'perda_referencia_kz', 'licenca_utilizador_kz', 'reconstrucao_kz'], $v['por_definir']);
    lanca(400, function () { naval_valor_gravar_parametros(['custo_hora_kz' => -1], ['login' => 'dir']); });
    lanca(400, function () { naval_valor_gravar_parametros(['anos_amortizacao' => 0], ['login' => 'dir']); });
    $p = naval_valor_gravar_parametros(['custo_hora_kz' => 3000, 'nao_existe' => 5], ['login' => 'dir']);
    igual(3000.0, $p['custo_hora_kz']);
    verdade(!isset($p['nao_existe']), 'só os parâmetros conhecidos');
    $v = naval_valor_vista(3, time());
    verdade(!in_array('custo_hora_kz', $v['por_definir'], true) && $v['totais']['tempo_kz'] !== null, json_encode($v['totais']));
    igual('dir', $v['alterado_por']);
});
