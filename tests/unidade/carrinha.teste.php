<?php
// Conciliação da carrinha e limite de peso da carga (nucleo/servicos/carrinha.php).
naval_servico('carrinha');

$GLOBALS['cc_rota'] = [
    's0' => ['A1' => ['n' => 'Fraldas M', 'q' => 10.0], 'B2' => ['n' => 'Sabão', 'q' => 5.0]],
    's1' => ['A1' => ['n' => 'Fraldas M', 'q' => 4.0], 'B2' => ['n' => 'Sabão', 'q' => 0.0]],
    'v' => ['A1' => ['n' => 'Fraldas M', 'u' => 'CX', 'q' => 26.0, 't' => 26000.0], 'B2' => ['n' => 'Sabão', 'u' => 'UN', 'q' => 3.0, 't' => 1500.0]],
    'mov' => ['A1' => ['c' => 20.0, 'd' => 0.0], 'B2' => ['c' => 0.0, 'd' => 2.0]],
];

teste('conciliação com movimentos separados: tem de voltar = inicial + carregado − vendido', function () {
    $l = array_column(naval_carr_linhas($GLOBALS['cc_rota'], null), null, 'codigo');
    igual(4.0, $l['A1']['esperado']);
    igual(2.0, $l['B2']['esperado'], '5 − 3 vendidos (2 devolvidos ao armazém)');
    igual([], $l['A1']['alertas']);
    igual(null, $l['A1']['diferenca'], 'sem contagem não há diferença');
    igual(1000.0, $l['A1']['preco']);
});

teste('contagem: diferenças com sinal e valor ao preço de venda; artigo não contado conta como zero', function () {
    $l = array_column(naval_carr_linhas($GLOBALS['cc_rota'], ['A1' => 3]), null, 'codigo');
    igual(-1.0, $l['A1']['diferenca']);
    igual(-1000.0, $l['A1']['valor']);
    igual(-2.0, $l['B2']['diferenca'], 'não contado = 0');
    $r = naval_carr_resumo(array_values($l), ['estado' => '', 'contagem' => ['A1' => 3]]);
    igual('por_aprovar', $r['estado']);
    igual(2, $r['diferencas']);
    igual(2000.0, $r['falta_kz'], '1000 + 2 × 500');
});

teste('alertas automáticos: venda acima do disponível, stock negativo e movimento sem documento', function () {
    $r = $GLOBALS['cc_rota'];
    $r['v']['A1']['q'] = 40.0;          // 10 + 20 carregados: vendeu 40
    $r['s1']['A1']['q'] = -10.0;
    $r['mov']['B2']['d'] = 0.0;         // o stock final diz 0 mas não há devolução registada
    $l = array_column(naval_carr_linhas($r, null), null, 'codigo');
    verdade(in_array('sem_carga', $l['A1']['alertas'], true), 'sem_carga');
    verdade(in_array('negativo', $l['A1']['alertas'], true), 'negativo');
    verdade(in_array('nao_explicado', $l['B2']['alertas'], true), 'nao_explicado');
    igual('A1', naval_carr_linhas($r, null)[0]['codigo'], 'linhas com alertas primeiro');
});

teste('sem movimentos separados: carregado e devolvido pelo saldo; tem de voltar = stock final', function () {
    $r = $GLOBALS['cc_rota'];
    $r['mov'] = null;
    $l = array_column(naval_carr_linhas($r, null), null, 'codigo');
    igual(20.0, $l['A1']['carregado'], '4 − 10 + 26');
    igual(0.0, $l['A1']['devolvido']);
    igual(4.0, $l['A1']['esperado']);
    igual(2.0, $l['B2']['devolvido'], '0 − 5 + 3 = −2');
    igual(0.0, $l['B2']['esperado']);
});

teste('contagem cega: sem os números do sistema', function () {
    $c = naval_carr_cega(naval_carr_linhas($GLOBALS['cc_rota'], null));
    igual(['codigo', 'artigo', 'unidade', 'contado', 'alertas'], array_keys($c[0]));
});

teste('contar e aprovar: motivo obrigatório por diferença; «outro» exige nota; aprovada não volta a mudar', function () {
    $data = date('Y-m-d');
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', ['data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0, 'B2' => 500.0], 'rotas' => ['1001' => $GLOBALS['cc_rota']]]);
    // naval_carr_dados(…, true) relê o Pinguim BO; sem base de dados usa a fotografia gravada
    $eu = ['login' => 'tec.armazem', 'nome' => 'Técnico', 'email' => ''];
    lanca(400, function () use ($data, $eu) { naval_carr_contar($data, '1001', ['A1' => -1], $eu); });
    $r = naval_carr_contar($data, '1001', ['A1' => 3, 'B2' => 2, 'XX' => 9], $eu);
    igual(1, $r['resumo']['diferencas'], 'artigo desconhecido ignorado');
    lanca(400, function () use ($data, $eu) { naval_carr_aprovar($data, '1001', [], $eu); });
    lanca(400, function () use ($data, $eu) { naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => 'outro', 'nota' => '']], $eu); });
    $a = naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => 'avaria', 'nota' => 'caixa molhada']], $eu);
    igual('aprovada', $a['resumo']['estado']);
    lanca(409, function () use ($data, $eu) { naval_carr_contar($data, '1001', ['A1' => 4], $eu); });
    $v = naval_carr_vista($data, null, true);
    igual('aprovada', $v['rotas'][0]['resumo']['estado']);
    igual(2, count($v['rotas'][0]['historico']));
    igual([], naval_carr_vista($data, ['1002'], true)['rotas'], 'âmbito por rota');
    lanca(400, function () use ($eu) { naval_carr_contar(date('Y-m-d', time() + 2 * 86400), '1001', [], $eu); });
});

teste('limite de peso: planeado + o que está na carrinha não passa a capacidade da viatura', function () {
    $listas = ['artigos' => [['id' => 'fraldas', 'nome' => 'Fraldas', 'peso' => 8.0], ['id' => 'sabao', 'nome' => 'Sabão', 'peso' => 20.0]],
        'capacidades' => ['LD-12-34-AB' => 1000.0]];
    $rotas = [['rota' => '1001', 'sai' => true, 'matricula' => 'ld-12-34-ab', 'quantidades' => ['fraldas' => 50, 'sabao' => 20]]];
    $e = naval_carga_estado($rotas, $listas, ['1001' => 150.0]);
    igual(800.0, $e['1001']['planeado']);
    igual(850.0, $e['1001']['disponivel'], 'depois de vender, só pode carregar a diferença');
    igual(false, $e['1001']['excede']);
    $e = naval_carga_estado($rotas, $listas, ['1001' => 250.0]);
    igual(true, $e['1001']['excede']);
    igual(1, count(naval_carga_erros($e)));
    $rotas[0]['sai'] = false;
    igual(false, naval_carga_estado($rotas, $listas, ['1001' => 250.0])['1001']['excede'], 'rota que não sai não bloqueia');
    $rotas[0]['matricula'] = 'OUTRA';
    $rotas[0]['sai'] = true;
    igual(null, naval_carga_estado($rotas, $listas, [])['1001']['capacidade'], 'sem capacidade definida não bloqueia');
});

teste('listas do plano: peso dos artigos e capacidade das viaturas validados', function () {
    naval_servico('cargas');
    $l = naval_plano_actualizar_listas(['artigos' => [['nome' => 'Fraldas', 'peso' => '8,5'], ['nome' => 'Sabão', 'peso' => '-3']],
        'capacidades' => ['ld-1' => '1200', '' => 5, 'X' => 0]], naval_plano_listas());
    igual(8.5, $l['artigos'][0]['peso']);
    verdade(!isset($l['artigos'][1]['peso']), 'peso negativo ignorado');
    igual(['LD-1' => 1200.0], $l['capacidades']);
    lanca(400, function () { naval_plano_actualizar_listas(['capacidades' => ['Y' => 99999]], naval_plano_listas()); });
});
