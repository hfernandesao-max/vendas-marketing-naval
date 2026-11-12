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

teste('nada para contar: stock do dia anterior retirado ou corrigido no Pinguim BO, sem vendas, não pede contagem', function () {
    // rota 1011 em 12-11-2026: 90 caixas no fim do dia 11, corrigidas no Pinguim BO para zero, nenhuma venda
    $r = ['s0' => ['10000009' => ['n' => 'Fraldas', 'q' => 65.0], '10000386' => ['n' => 'Sabão', 'q' => 15.0]], 's1' => [], 'v' => [], 'mov' => null];
    igual([], naval_carr_linhas($r, null));
    igual('sem_movimento', naval_carr_resumo([], ['estado' => '', 'contagem' => null])['estado']);
    // com movimentos separados e a devolução registada, também não há nada para contar
    $r['mov'] = ['10000009' => ['c' => 0.0, 'd' => 65.0], '10000386' => ['c' => 0.0, 'd' => 15.0]];
    $l = naval_carr_linhas($r, null);
    igual(2, count($l), 'tem de voltar = 65 e 15: o que saiu para o armazém tem de ser contado');
    // havendo stock no fim ou vendas, a linha fica
    $r = ['s0' => ['A' => ['n' => 'A', 'q' => 5.0]], 's1' => ['A' => ['n' => 'A', 'q' => 2.0]], 'v' => [], 'mov' => null];
    igual(1, count(naval_carr_linhas($r, null)));
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
    naval_carr_validar($data, '1001', $eu);
    $coord = ['login' => 'coord', 'nome' => 'Coordenador', 'email' => ''];
    lanca(400, function () use ($data, $coord) { naval_carr_aprovar($data, '1001', [], $coord); });
    lanca(400, function () use ($data, $coord) { naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => 'outro', 'nota' => '']], $coord); });
    naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => 'avaria', 'nota' => 'caixa molhada']], $coord);
    $a = naval_carr_fechar($data, '1001', ['login' => 'resp', 'nome' => 'Responsável', 'email' => '']);
    igual('aprovada', $a['resumo']['estado']);
    lanca(409, function () use ($data, $eu) { naval_carr_contar($data, '1001', ['A1' => 4], $eu); });
    $v = naval_carr_vista($data, null, true);
    igual('aprovada', $v['rotas'][0]['resumo']['estado']);
    igual(4, count($v['rotas'][0]['historico']));
    igual([], naval_carr_vista($data, ['1002'], true)['rotas'], 'âmbito por rota');
    lanca(400, function () use ($eu) { naval_carr_contar(date('Y-m-d', time() + 2 * 86400), '1001', [], $eu); });
});

teste('fecho por excepção: contagem igual ao esperado e sem alertas fecha sozinha; painel da carrinha', function () {
    $data = date('Y-m-d', time() - 86400);
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', ['data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0, 'B2' => 500.0], 'rotas' => ['1001' => $GLOBALS['cc_rota'], '1002' => $GLOBALS['cc_rota']]]);
    $eu = ['login' => 'tec.armazem', 'nome' => 'Técnico', 'email' => ''];
    $r = naval_carr_contar($data, '1001', ['A1' => 4, 'B2' => 2], $eu);
    igual('por_validar', $r['resumo']['estado'], 'quem contou valida primeiro');
    lanca(403, function () use ($data) { naval_carr_validar($data, '1001', ['login' => 'outra.pessoa']); });
    $r = naval_carr_validar($data, '1001', $eu);
    igual('aprovada', $r['resumo']['estado'], 'validada e sem diferenças fecha sozinha');
    $reg = naval_carr_ler($data, '1001');
    igual('automatico', $reg['aprovado_por']);
    igual(['contagem', 'validada', 'fecho_automatico'], array_column($reg['historico'], 'accao'));
    $p = naval_carr_painel($data, null, true);
    $porRota = array_column($p['rotas'], null, 'rota');
    igual('fechada_auto', $porRota['1001']['fecho']);
    igual(0, $porRota['1001']['diferencas']);
    igual('por_contar', $porRota['1002']['fecho']);
    igual(null, $porRota['1002']['carregado'], 'contagem cega: quem conta não vê os totais antes de contar');
    igual(['pinguim-sql', 'contagens', 'quatenus', 'cctv'], array_column($p['fontes'], 'id'));
    igual('por_ligar', array_column($p['fontes'], 'estado', 'id')['quatenus']);
    $r = naval_carr_contar($data, '1002', ['A1' => 3, 'B2' => 2], $eu);
    igual('por_validar', $r['resumo']['estado']);
    igual('por_aprovar', naval_carr_validar($data, '1002', $eu)['resumo']['estado'], 'com diferenças vai a aprovação');
});

teste('dois níveis de aprovação com segregação de funções: coordenador e, por fim, responsável', function () {
    $data = date('Y-m-d', time() - 2 * 86400);
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', ['data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0, 'B2' => 500.0], 'rotas' => ['1001' => $GLOBALS['cc_rota']]]);
    $tec = ['login' => 'tec', 'nome' => 'Técnico', 'email' => ''];
    $coord = ['login' => 'coord', 'nome' => 'Coordenador', 'email' => ''];
    $resp = ['login' => 'resp', 'nome' => 'Responsável', 'email' => ''];
    $mot = ['A1' => ['motivo' => 'avaria'], 'B2' => ['motivo' => 'falta']];
    naval_carr_contar($data, '1001', ['A1' => 3, 'B2' => 2], $tec);
    lanca(409, function () use ($data, $coord, $mot) { naval_carr_aprovar($data, '1001', $mot, $coord); });
    naval_carr_validar($data, '1001', $tec);
    lanca(409, function () use ($data, $resp) { naval_carr_fechar($data, '1001', $resp); });
    lanca(403, function () use ($data, $tec, $mot) { naval_carr_aprovar($data, '1001', $mot, $tec); });
    igual('por_fechar', naval_carr_aprovar($data, '1001', $mot, $coord)['resumo']['estado']);
    lanca(403, function () use ($data, $coord) { naval_carr_fechar($data, '1001', $coord); });
    lanca(403, function () use ($data, $tec) { naval_carr_fechar($data, '1001', $tec); });
    igual('aprovada', naval_carr_fechar($data, '1001', $resp)['resumo']['estado']);
    $reg = naval_carr_ler($data, '1001');
    igual(['tec', 'tec', 'coord', 'resp'], [$reg['contado_por'], $reg['validado_por'], $reg['coordenado_por'], $reg['aprovado_por']]);
    igual(['contagem', 'validada', 'aprovada_coordenador', 'aprovada'], array_column($reg['historico'], 'accao'));
    igual('avaria', $reg['motivos']['A1']['motivo']);
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
