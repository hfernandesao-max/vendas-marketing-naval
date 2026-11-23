<?php
// Agentes autónomos: fecho do dia, reposição automática, risco por vendedor e relatório semanal.
naval_servico('agentes');

teste('agente do fecho: a cada rota com vendas hoje, o passo exacto que falta; só a quem é da rota', function () {
    $dia = '2030-01-10';   // quinta-feira
    $dados = [
        '1004' => ['ULTIMA_VENDA' => $dia . ' 14:50:00', 'ULTIMA_SINCRONIZACAO' => $dia . ' 13:00:00'],   // não sincronizou depois das 15:00
        '1007' => ['ULTIMA_VENDA' => $dia . ' 14:00:00', 'ULTIMA_SINCRONIZACAO' => $dia . ' 15:20:00'],   // tudo certo
        '1009' => ['ULTIMA_VENDA' => '2030-01-09 14:00:00', 'ULTIMA_SINCRONIZACAO' => '2030-01-09 16:00:00'],   // sem vendas hoje
    ];
    $carr = [['rota' => '1004', 'resumo' => ['estado' => 'por_contar']], ['rota' => '1007', 'resumo' => ['estado' => 'por_validar']]];
    igual(['1004' => ['sincronizar', 'dinheiro', 'carrinha']], naval_ag_fecho_faltas($dados, $carr, ['1007'], [], $dia, '15:00'));
    igual(['1004' => ['sincronizar', 'carrinha']], naval_ag_fecho_faltas($dados, $carr, ['1007'], ['1004'], $dia, '15:00'), 'dinheiro já contado pela tesouraria');
    $env = [];
    $r = naval_ag_fecho(strtotime($dia . ' 16:05:00'), ['dados' => $dados, 'carrinhas' => $carr, 'declaradas' => ['1007'], 'contadas' => [],
        'contas' => ['pro4' => ['activo' => true, 'perfil' => 'PRO', 'rotas' => ['1004'], 'email' => 'p4@exemplo.ao', 'compatibilidade' => true],
            'pro7' => ['activo' => true, 'perfil' => 'PRO', 'rotas' => ['1007'], 'email' => 'p7@exemplo.ao', 'compatibilidade' => true]],
        'notificar' => function ($logins, $tx, $chave) use (&$env) { $env[] = [$logins, $tx['pt'][1]]; }]);
    igual(1, $r['avisadas']);
    igual([[['pro4'], 'Falta: sincronize o telemóvel no Winrest; registe na app as notas que entrega; a carrinha ainda não foi contada pela Logística.']], $env);
    igual(null, naval_ag_fecho(strtotime($dia . ' 15:30:00'), ['dados' => $dados]), 'antes do fim da janela do fecho não avisa');
    igual(null, naval_ag_fecho(strtotime('2030-01-13 16:30:00'), ['dados' => $dados]), 'domingo: nada');
});

teste('reposição automática: a PO da contagem validada sai emitida sozinha; desligada, fica em rascunho', function () {
    naval_servico('requisicoes');
    $data = date('Y-m-d');
    $r = naval_req_da_conciliacao($data, '1012', [['codigo' => 'A1', 'artigo' => 'Fraldas', 'unidade' => 'UN', 'vendido' => 6]], 'tec');
    igual('rascunho', $r['estado']);
    $e = naval_req_reposicao_auto($r);
    igual(['emitida', 'automatico'], [$e['estado'], $e['emitida_por']]);
    // fora das regras (sem conciliação registada): fica por aprovar, com o motivo para o coordenador
    verdade(empty($e['aprovada_em']));
    verdade(!empty($e['regras_auto']['motivos']), 'com o motivo para o coordenador');
    igual($e, naval_req_reposicao_auto($e), 'já emitida: não mexe');
});

teste('risco por vendedor: cada indicador face à mediana das rotas; o dobro dá 10 pontos, o triplo 20', function () {
    $r = function ($rota, $anul, $falta) { return ['rota' => $rota, 'vendas' => 100000.0, 'anulados_kz' => $anul, 'falta_kz' => $falta, 'entrega_dif_kz' => 0.0, 'desconto_kz' => 0.0, 'nc_kz' => 0.0]; };
    $x = naval_ag_risco([$r('1001', 1000.0, 500.0), $r('1002', 1000.0, 500.0), $r('1003', 3000.0, 1000.0), $r('1004', 1000.0, 500.0)]);
    igual('1003', (string) array_key_first($x), 'a de maior risco primeiro');
    igual(30, $x['1003']['pontos'], 'anulados no triplo da mediana (20) + faltas no dobro (10)');
    igual(['anulados', 'faltas'], array_column($x['1003']['fatores'], 'indicador'));
    igual(0, $x['1001']['pontos']);
    // o agente avisa a Direcção acima do limiar, uma vez por dia
    $avisos = [];
    // 1003: anulados, faltas e descontos muito acima das outras rotas → 60 pontos (o limiar)
    $perdas = function () use ($r) { return ['rotas' => [$r('1001', 100.0, 0.0), $r('1002', 100.0, 0.0), ['desconto_kz' => 9000.0] + $r('1003', 9000.0, 9000.0)]]; };
    @unlink(naval_pasta('agentes') . '/risco-ultimo.json');
    @unlink(naval_pasta('agentes') . '/risco-ultimo.json');
    $f = naval_pasta('agentes') . '/risco-ultimo.json';
    $g = naval_ag_risco_ciclo(strtotime('2030-01-10 08:00:00'), $perdas, function ($tx, $chave) use (&$avisos) { $avisos[] = $chave; });
    igual(['1003'], $g['altos']);
    igual(['risco-2030-01-10'], $avisos);
    igual(null, naval_ag_risco_ciclo(strtotime('2030-01-10 09:00:00'), $perdas, function () {}), 'uma vez por dia');
    verdade(is_file($f));
});

teste('relatório semanal: semana face à anterior por rota, com as perdas e o risco', function () {
    $d = naval_ag_semana_dados(
        [['rota' => '1004', 'total' => 1200.0], ['rota' => '1004', 'total' => -100.0, 'nc' => true], ['rota' => '1007', 'total' => 500.0]],
        [['rota' => '1004', 'total' => 1000.0], ['rota' => '1007', 'total' => 1000.0]],
        ['total' => ['perdas_kz' => 300.0, 'falta_kz' => 200.0, 'parado_min' => 125]], ['1007' => ['pontos' => 65]], ['1004' => 'Ana', '1007' => 'Rui']);
    igual([1700.0, 2000.0, -0.15], [$d['semana'], $d['anterior'], $d['variacao']]);
    igual(['1004', 0.2, 0], [$d['rotas'][0]['rota'], $d['rotas'][0]['variacao'], $d['rotas'][0]['risco']]);
    igual(['1007', -0.5, 65], [$d['rotas'][1]['rota'], $d['rotas'][1]['variacao'], $d['rotas'][1]['risco']]);
    igual([300.0, 125], [$d['perdas_kz'], $d['parado_min']]);
});
