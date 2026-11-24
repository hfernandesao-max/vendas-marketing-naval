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

teste('stock no corte é reconstruído pelo saldo de abertura e movimentos até à hora', function () {
    $mov = [
        '10000332' => ['va' => 19.0, 'vd' => 0.0, 'ca' => 0.0, 'da' => 0.0],
        '10000386' => ['va' => 0.0, 'vd' => 0.0, 'ca' => 105.0, 'da' => 0.0],
        '10000292' => ['va' => 0.0, 'vd' => 0.0, 'ca' => 0.0, 'da' => 3.0],
    ];
    $stock = naval_carr_stock_por_movimentos([
        '10000332' => ['n' => 'Fraldas', 'q' => 80.0],
        '10000386' => ['n' => 'Sabão', 'q' => 0.0],
        '10000292' => ['n' => 'Sabão verde', 'q' => 7.0],
    ], $mov);
    igual([61.0, 105.0, 4.0], [
        $stock['10000332']['q'],
        $stock['10000386']['q'],
        $stock['10000292']['q'],
    ], 'stock inicial + entradas − saídas − vendas antes do corte');

    $movDepois = ['10000332' => ['va' => 0.0, 'vd' => 10.0, 'ca' => 0.0, 'da' => 0.0]];
    $stock = naval_carr_stock_por_movimentos(
        ['10000332' => ['n' => 'Fraldas', 'q' => 150.0]],
        $movDepois
    );
    igual(150.0, $stock['10000332']['q'], 'a venda depois do corte não reduz o saldo contado');
});

teste('actualização histórica inclui apenas registos abertos ainda não corrigidos', function () {
    foreach (['', 'por_contar', 'por_validar', 'por_aprovar', 'por_fechar'] as $estado) {
        verdade(naval_carr_registo_aberto_actualizavel(['estado' => $estado]), 'estado aberto ' . $estado);
    }
    verdade(!naval_carr_registo_aberto_actualizavel(['estado' => 'aprovada']), 'conciliação fechada não muda');
    verdade(!naval_carr_registo_aberto_actualizavel([
        'estado' => 'por_validar', 'base_congelada_motivo' => 'corte_movimentos_v1',
    ]), 'base já actualizada não é processada outra vez');
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

teste('contar e aprovar: motivo livre até 255 caracteres obrigatório por diferença; aprovada não volta a mudar', function () {
    $data = date('Y-m-d');
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', ['data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0, 'B2' => 500.0], 'rotas' => ['1001' => $GLOBALS['cc_rota']]]);
    // naval_carr_dados(…, true) relê o Pinguim BO; sem base de dados usa a fotografia gravada
    $eu = ['login' => 'tec.armazem', 'nome' => 'Técnico', 'email' => ''];
    // por contar: quem conta e quem só consulta não vêem as quantidades do sistema; a coordenação e o fecho vêem
    $l = naval_carr_vista($data, null, false)['rotas'][0];
    verdade($l['quantidades_ocultas'] && !$l['cega'], 'só consulta: oculto, sem formulário');
    igual(['codigo', 'artigo', 'unidade', 'contado', 'alertas'], array_keys($l['linhas'][0]));
    $l = naval_carr_vista($data, null, true)['rotas'][0];
    verdade($l['quantidades_ocultas'] && $l['cega'], 'quem conta: às cegas');
    $l = naval_carr_vista($data, null, false, false, true)['rotas'][0];
    verdade(!$l['quantidades_ocultas'] && isset($l['linhas'][0]['esperado']), 'coordenação: vê o esperado');
    verdade(naval_carr_vista($data, null, true, false, true)['rotas'][0]['quantidades_ocultas'], 'quem conta nunca vê, mesmo com outra função');
    lanca(400, function () use ($data, $eu) { naval_carr_contar($data, '1001', ['A1' => -1], $eu); });
    $r = naval_carr_contar($data, '1001', ['A1' => 3, 'B2' => 2, 'XX' => 9], $eu);
    igual(1, $r['resumo']['diferencas'], 'artigo desconhecido ignorado');
    naval_carr_validar($data, '1001', $eu);
    $coord = ['login' => 'coord', 'nome' => 'Coordenador', 'email' => ''];
    lanca(400, function () use ($data, $coord) { naval_carr_aprovar($data, '1001', [], $coord); });
    lanca(400, function () use ($data, $coord) { naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => str_repeat('x', 256)]], $coord); });
    $motivo = str_repeat('x', 255);
    naval_carr_aprovar($data, '1001', ['A1' => ['motivo' => $motivo]], $coord);
    $a = naval_carr_fechar($data, '1001', ['login' => 'resp', 'nome' => 'Responsável', 'email' => '']);
    igual('aprovada', $a['resumo']['estado']);
    lanca(409, function () use ($data, $eu) { naval_carr_contar($data, '1001', ['A1' => 4], $eu); });
    $v = naval_carr_vista($data, null, true);
    igual('aprovada', $v['rotas'][0]['resumo']['estado']);
    igual(4, count($v['rotas'][0]['historico']));
    igual($motivo, naval_carr_ler($data, '1001')['motivos']['A1']['motivo']);
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
    igual('avaria', $reg['motivos']['A1']['motivo'], 'motivos já usados continuam aceites');
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

teste('corte do dia: a carga para o dia seguinte não entra na contagem (fotografia antes do corte ou estimativa)', function () {
    // rota 1005 a 16-11-2026: 64 de manhã, 27 vendidas, 150 carregadas às 16:30 para o dia seguinte; contadas 37
    $r = ['s0' => ['A' => ['n' => 'Fraldas', 'q' => 64.0], 'B' => ['n' => 'Sabão', 'q' => 9.0]],
        's1' => ['A' => ['n' => 'Fraldas', 'q' => 187.0], 'B' => ['n' => 'Sabão', 'q' => 4.0], 'N' => ['n' => 'Só amanhã', 'q' => 30.0]],
        'v' => ['A' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 27.0, 't' => 405000.0], 'B' => ['n' => 'Sabão', 'u' => 'UN', 'q' => 5.0, 't' => 5000.0]], 'mov' => null];
    $sem = naval_carr_linhas($r, ['A' => 37, 'B' => 4]);
    igual(-150.0, array_values(array_filter($sem, function ($l) { return $l['codigo'] === 'A'; }))[0]['diferenca'], 'sem corte: a carga de amanhã aparece como falta');
    $data = date('Y-m-d', time() - 2 * 86400);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
    $c = naval_carr_stock_corte($data, '1005', $r, '16:00');
    igual(['estimado', 37.0, 4.0], [$c['origem'], $c['q']['A'], $c['q']['B']]);
    $l = naval_carr_linhas($r + ['corte' => $c], ['A' => 37, 'B' => 4]);
    $a = array_values(array_filter($l, function ($x) { return $x['codigo'] === 'A'; }))[0];
    igual([0.0, 37.0, 0.0, 150.0], [$a['carregado'], $a['esperado'], $a['diferenca'], $a['depois_do_corte']]);
    igual([], array_values(array_filter($l, function ($x) { return $x['codigo'] === 'N'; })), 'artigo só da carga de amanhã: nada para contar');
    igual(0, naval_carr_resumo($l, ['estado' => 'por_aprovar', 'contagem' => ['A' => 37]])['diferencas']);
    // com a fotografia tirada antes do corte, vale a fotografia (inclui uma carga de manhã de 10)
    naval_json_gravar(naval_carr_pasta($data) . '/_corte.json', ['data' => $data, 'hora' => '15:55', 'rotas' => ['1005' => ['A' => ['n' => 'Fraldas', 'q' => 47.0]]]]);
    $c = naval_carr_stock_corte($data, '1005', $r, '16:00');
    igual(['fotografia', '15:55', 47.0], [$c['origem'], $c['hora'], $c['q']['A']]);
    $a = array_values(array_filter(naval_carr_linhas($r + ['corte' => $c], ['A' => 47]), function ($x) { return $x['codigo'] === 'A'; }))[0];
    igual([10.0, 47.0, 0.0], [$a['carregado'], $a['esperado'], $a['diferenca']]);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
    // conciliação já aprovada sem corte gravado: fica como foi aprovada
    igual(false, isset(naval_carr_base($data, '1005', $r, ['estado' => 'aprovada'])['corte']));
});

teste('corte pelos documentos do Pinguim BO (DOCMOVDETAIL): carga de manhã e saída de armazém antes do corte contam', function () {
    // rota 1005 a 16-11-2026: GTC às 08:54 (+15 sabão verde, +15 sabão castanho), SA às 11:39 (−12 sabão branco),
    // GTC às 16:00:33 (+200 fraldas, para o dia seguinte); contados 22, 22 e 0
    $rows = [
        ['ROTA' => '1005', 'CODIGO' => '10000292', 'ANTES' => 1, 'ENTRADAS' => 15.0, 'SAIDAS' => 0.0],
        ['ROTA' => '1005', 'CODIGO' => '10000293', 'ANTES' => 1, 'ENTRADAS' => 15.0, 'SAIDAS' => 0.0],
        ['ROTA' => '1005', 'CODIGO' => '10000382', 'ANTES' => 1, 'ENTRADAS' => 0.0, 'SAIDAS' => 12.0],
        ['ROTA' => '1005', 'CODIGO' => '10000332', 'ANTES' => 1, 'ENTRADAS' => 0.0, 'SAIDAS' => 0.0, 'VENDIDO' => 27.0],
        ['ROTA' => '1005', 'CODIGO' => '10000332', 'ANTES' => 0, 'ENTRADAS' => 200.0, 'SAIDAS' => 0.0],
        ['ROTA' => '1000', 'CODIGO' => '10000382', 'ANTES' => 1, 'ENTRADAS' => 12.0, 'SAIDAS' => 0.0],   // armazém principal: não é carrinha
    ];
    $mov = naval_carr_movimentos_agrupar($rows, ['1005', '1006']);
    igual(['1005'], array_map('strval', array_keys($mov)));
    igual(['c' => 200.0, 'd' => 0.0, 'ca' => 0.0, 'da' => 0.0, 'va' => 27.0, 'vd' => 0.0], $mov['1005']['10000332']);
    $r = ['s0' => ['10000292' => ['n' => 'Sabão verde', 'q' => 7.0], '10000293' => ['n' => 'Sabão castanho', 'q' => 7.0], '10000382' => ['n' => 'Sabão branco', 'q' => 12.0],
        '10000332' => ['n' => 'Fraldas', 'q' => 64.0]],
        's1' => ['10000292' => ['n' => 'Sabão verde', 'q' => 22.0], '10000293' => ['n' => 'Sabão castanho', 'q' => 22.0], '10000332' => ['n' => 'Fraldas', 'q' => 237.0]],
        'v' => ['10000332' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 27.0, 't' => 405000.0]], 'mov' => $mov['1005']];
    $data = date('Y-m-d', time() - 3 * 86400);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
    $c = naval_carr_stock_corte($data, '1005', $r, '16:00');
    igual(['documentos', 22.0, 22.0, 0.0, 37.0], [$c['origem'], $c['q']['10000292'], $c['q']['10000293'], $c['q']['10000382'], $c['q']['10000332']]);
    $l = naval_carr_linhas($r + ['corte' => $c], ['10000292' => 22, '10000293' => 22, '10000382' => 0, '10000332' => 37]);
    $por = array_column($l, null, 'codigo');
    igual([15.0, 0.0, 0.0], [$por['10000292']['carregado'], $por['10000292']['devolvido'], $por['10000292']['diferenca']], 'carga de manhã');
    igual([0.0, 12.0, 0.0], [$por['10000382']['carregado'], $por['10000382']['devolvido'], $por['10000382']['diferenca']], 'saída de armazém');
    igual([0.0, 0.0, 200.0], [$por['10000332']['carregado'], $por['10000332']['diferenca'], $por['10000332']['depois_do_corte']], 'carga das 16:00 para amanhã');
    igual(0, naval_carr_resumo($l, ['estado' => 'por_validar', 'contagem' => ['10000292' => 22]])['diferencas']);
    // sem a separação por hora (dados antigos): a estimativa pelas cargas do portal, como antes
    $r['mov'] = ['10000292' => ['c' => 15.0, 'd' => 0.0]];
    igual('estimado', naval_carr_stock_corte($data, '1005', $r, '16:00')['origem']);
});

teste('corte por movimentos horários: as vendas depois do corte não reduzem o saldo contado', function () {
    // rota 1009: 40 fraldas no início, três facturas depois das 18:25 (30 fraldas), fotografia antiga desactualizada
    $rows = [
        ['ROTA' => '1009', 'CODIGO' => '10000332', 'ANTES' => 0, 'ENTRADAS' => 0.0, 'SAIDAS' => 0.0, 'VENDIDO' => 30.0],
        ['ROTA' => '1009', 'CODIGO' => '10000015', 'ANTES' => 1, 'ENTRADAS' => 0.0, 'SAIDAS' => 0.0, 'VENDIDO' => 5.0],
    ];
    $mov = naval_carr_movimentos_agrupar($rows, ['1009']);
    igual([30.0, 0.0, 5.0], [$mov['1009']['10000332']['vd'], $mov['1009']['10000015']['vd'], $mov['1009']['10000015']['va']], 'separa vendas antes e depois do corte');
    $data = date('Y-m-d', time() - 5 * 86400);
    naval_json_gravar(naval_carr_pasta($data) . '/_corte.json', ['hora' => '15:50', 'rotas' => ['1009' => ['10000332' => ['q' => 10.0], '10000015' => ['q' => 6.0]]]]);
    $c = naval_carr_stock_corte($data, '1009', [
        's0' => ['10000332' => ['n' => 'Fraldas', 'q' => 40.0], '10000015' => ['n' => 'Produto B', 'q' => 6.0]],
        'mov' => $mov['1009'],
    ], '16:00');
    igual(['documentos', 40.0, 1.0, 30.0], [$c['origem'], $c['q']['10000332'], $c['q']['10000015'], $c['vendido_depois']]);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
});

teste('a base da contagem fica congelada e não recebe uma fotografia posterior', function () {
    $r = [
        's0' => ['10000332' => ['n' => 'Fraldas', 'q' => 64.0]],
        's1' => ['10000332' => ['n' => 'Fraldas', 'q' => 4.0]],
        'v' => ['10000332' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 60.0, 't' => 900000.0]],
        'mov' => null,
    ];
    $mov = ['10000332' => ['c' => 0.0, 'd' => 0.0, 'ca' => 0.0, 'da' => 0.0, 'va' => 25.0, 'vd' => 35.0]];
    $base = naval_carr_congelar_base($r, '2026-11-16 15:34:00', [
        '10000332' => ['n' => 'Fraldas', 'q' => 39.0],
    ], $mov);
    $linha = naval_carr_linhas($base, ['10000332' => 10], ['10000332' => 15000.0])[0];
    igual([25.0, 39.0, -29.0], [$linha['vendido'], $linha['esperado'], $linha['diferenca']]);
    igual([4.0, -35.0], [$base['s1']['10000332']['q'], $linha['depois_do_corte']],
        'preserva o saldo de fim do dia e mostra as vendas posteriores ao corte');
    $data = date('Y-m-d', time() - 2 * 86400);
    naval_json_gravar(naval_carr_pasta($data) . '/_corte.json', [
        'data' => $data, 'hora' => '15:50',
        'rotas' => ['1004' => ['10000332' => ['n' => 'Fraldas', 'q' => 10.0]]],
    ]);
    $vista = naval_carr_base($data, '1004', $base, ['estado' => 'por_validar']);
    igual(['contagem', '2026-11-16 15:34:00', 39.0], [$vista['corte']['origem'], $vista['corte']['hora'], $vista['corte']['q']['10000332']]);
    igual(-29.0, naval_carr_linhas($vista, ['10000332' => 10], ['10000332' => 15000.0])[0]['diferenca']);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
});

teste('a primeira folha congela a base, a reimpressão reutiliza-a e a contagem mantém-na', function () {
    // rota própria: a 1001 do mesmo dia já foi fechada pelo teste do fecho por excepção
    $data = date('Y-m-d', time() - 86400);
    igual($data . ' 23:59:59', naval_carr_hora_referencia($data), 'sem regra de corte: referência histórica ao fim do dia');
    $r = [
        's0' => ['A1' => ['n' => 'Fraldas', 'q' => 10.0]],
        's1' => ['A1' => ['n' => 'Fraldas', 'q' => 5.0]],
        'v' => ['A1' => ['n' => 'Fraldas', 'u' => 'CX', 'q' => 5.0, 't' => 5000.0]],
        'mov' => ['A1' => ['c' => 0.0, 'd' => 0.0]],
    ];
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', [
        'data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0], 'rotas' => ['1007' => $r],
    ]);
    $eu = ['login' => 'tec.armazem'];
    verdade(naval_carr_juntar_posterior($data, '1007', 'RQ-1', ['B1' => 2.0], 'coord', date('Y-m-d H:i:s')), 'carga anterior à folha');
    $primeira = naval_carr_preparar_folha($data, '1007', $eu);
    $codigos = array_column($primeira['linhas'], 'codigo');
    sort($codigos);   // a folha vem ordenada pelo nome do artigo; aqui só interessa que a carga autorizada esteja lá
    igual(['A1', 'B1'], $codigos, 'a folha inclui as cargas já autorizadas');
    $base = naval_carr_ler($data, '1007');
    naval_json_gravar(naval_carr_pasta($data) . '/_corte.json', [
        'data' => $data, 'hora' => '15:50', 'rotas' => ['1007' => ['A1' => ['n' => 'Fraldas', 'q' => 0.0]]],
    ]);
    $alterada = $r;
    $alterada['s1'] = ['A1' => ['n' => 'Fraldas', 'q' => 0.0]];
    naval_json_gravar(naval_carr_pasta($data) . '/_dados.json', [
        'data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados',
        'precos' => ['A1' => 1000.0], 'rotas' => ['1007' => $alterada],
    ]);
    $segunda = naval_carr_preparar_folha($data, '1007', $eu);
    igual($primeira, $segunda, 'reimpressão não muda a base nem a lista');
    igual($base['base'], naval_carr_ler($data, '1007')['base']);
    igual(1, count(array_filter(naval_carr_ler($data, '1007')['historico'], function ($h) { return $h['accao'] === 'folha_contagem_congelada'; })));
    verdade(!naval_carr_juntar_posterior($data, '1007', 'RQ-2', ['B1' => 3.0], 'coord', date('Y-m-d H:i:s')), 'carga posterior à folha não altera a contagem');
    $contagem = naval_carr_contar($data, '1007', ['A1' => 5.0, 'B1' => 2.0], $eu);
    $linhas = array_column($contagem['linhas'], null, 'codigo');
    igual([5.0, 0.0, 2.0, 0.0], [$linhas['A1']['esperado'], $linhas['A1']['diferenca'], $linhas['B1']['posterior'], $linhas['B1']['diferenca']]);
    @unlink(naval_carr_pasta($data) . '/_corte.json');
});

teste('movimentos pelo armazém da linha (como o stock): a guia conta na carrinha, o armazém central fica de fora', function () {
    $rows = [
        // guia de transporte na loja 1010 para o armazém da carrinha 1010
        ['ROTA' => '1010', 'ARM_COD' => '10001010', 'ARM' => 'Armazém 1010 - FHR PIV', 'CODIGO' => '10000332', 'ANTES' => 1, 'ENTRADAS' => 100.0, 'SAIDAS' => 0.0],
        // a saída do armazém central no mesmo documento não é da carrinha
        ['ROTA' => '1010', 'ARM_COD' => '1000', 'ARM' => 'HIGITEC PIV - NON FOOD', 'CODIGO' => '10000332', 'ANTES' => 1, 'ENTRADAS' => 0.0, 'SAIDAS' => 100.0],
        // documento lançado na loja central mas para o armazém da carrinha 1003: é da 1003
        ['ROTA' => '1000', 'ARM_COD' => '10001003', 'ARM' => 'Armazém 1003 - FHR PIV', 'CODIGO' => '10000292', 'ANTES' => 1, 'ENTRADAS' => 5.0, 'SAIDAS' => 0.0],
    ];
    $mov = naval_carr_movimentos_agrupar($rows, ['1003', '1010']);
    igual(['c' => 100.0, 'd' => 0.0, 'ca' => 100.0, 'da' => 0.0, 'va' => 0.0, 'vd' => 0.0], $mov['1010']['10000332']);
    igual(5.0, $mov['1003']['10000292']['ca']);
});

teste('revisão contra o Pinguim BO: uma guia anulada depois de congelar a base aparece como diferença', function () {
    // 19-11 rota 1010: congelada com o inicial de 50 (a GTC A1000/93 de 17-11 ainda não estava anulada); hoje o inicial é 0
    $mov = ['10000332' => ['c' => 100.0, 'd' => 0.0, 'ca' => 100.0, 'da' => 0.0, 'va' => 0.0, 'vd' => 0.0]];
    $base = function (float $ini) use ($mov) {
        $r = ['s0' => ['10000332' => ['n' => 'Fraldas', 'q' => $ini]], 's1' => ['10000332' => ['n' => 'Fraldas', 'q' => 100.0]], 'v' => []];
        return naval_carr_congelar_base($r, '2026-11-19 15:27:18', naval_carr_stock_por_movimentos($r['s0'], $mov, $r), $mov);
    };
    $dif = naval_carr_comparar_bases($base(50.0), $base(0.0));
    igual(1, count($dif));
    igual(['inicial' => [50.0, 0.0], 'esperado' => [150.0, 100.0]], $dif[0]['mudou']);
    igual([], naval_carr_comparar_bases($base(0.0), $base(0.0)));
});

teste('inventário do Pinguim BO: conta no dia seguinte, na conciliação e na prova; a prova apanha o que não se explica', function () {
    // INV A1000/49 de 11-11 na carrinha 1011: 65 → 0 (entra no stock do Pinguim BO a 12-11)
    $inv = naval_carr_inventarios_agrupar([
        ['ARM_COD' => '10001011', 'ARM' => 'Armazém 1011 - FHR PIV', 'CODIGO' => '10000009', 'DOC' => 'INV A1000/49', 'ACERTO' => -65.0],
        ['ARM_COD' => '1000', 'ARM' => 'HIGITEC PIV - NON FOOD', 'CODIGO' => '10000009', 'DOC' => 'INV A1000/50', 'ACERTO' => 10.0],   // armazém central
    ]);
    igual(['1011'], array_map('strval', array_keys($inv)));
    $mov = naval_carr_movimentos_com_inventario([], $inv);
    igual([-65.0, -65.0, ['INV A1000/49']], [$mov['1011']['10000009']['i'], $mov['1011']['10000009']['ia'], $mov['1011']['10000009']['idocs']]);
    $s0 = ['1011' => ['10000009' => ['n' => 'Massa', 'q' => 65.0]]];
    $s1 = ['1011' => []];
    igual([], naval_carr_prova_calcular($s0, $s1, $mov)['falhas'], 'com o inventário bate');
    $p = naval_carr_prova_calcular($s0, $s1, []);
    igual([1, 65.0, 0.0], [count($p['falhas']), $p['falhas'][0]['esperado'], $p['falhas'][0]['final']], 'sem o inventário não bate');
    // na conciliação: coluna inventário e o esperado já com o acerto
    $l = naval_carr_linhas(['s0' => $s0['1011'], 's1' => [], 'v' => [], 'mov' => $mov['1011']], null);
    igual([65.0, -65.0, 0.0, []], [$l[0]['inicial'], $l[0]['inventario'], $l[0]['esperado'], $l[0]['alertas']]);
});

teste('prova do dia: a aprovação de uma rota que não bate com o Pinguim BO fica bloqueada', function () {
    $data = date('Y-m-d', time() - 9 * 86400);
    naval_json_gravar(naval_carr_pasta($data) . '/_prova.json', ['data' => $data, 'ok' => false, 'falhas' => [
        ['rota' => '1004', 'codigo' => 'X1', 'artigo' => 'Fraldas', 'esperado' => 10, 'final' => 4]]]);
    igual(1, count(naval_carr_prova_rota($data, '1004')));
    igual([], naval_carr_prova_rota($data, '1005'));
    $erro = null;
    try {
        naval_carr_exigir_prova($data, '1004');
    } catch (NavalErro $e) {
        $erro = $e->estado();
    }
    igual(409, $erro);
    naval_carr_exigir_prova($data, '1005');
    @unlink(naval_carr_pasta($data) . '/_prova.json');
});

teste('referência da contagem: o momento em que se contou, no máximo o corte; nunca a hora da folha impressa antes', function () {
    // rota 1004, 20-11: folha às 13:29 (antes da venda das 13:57), contagem às 16:12, corte às 16:00 (sem corte nos testes:
    // limite 23:59:59)
    $dia = '2026-11-20';
    $reg = ['folha_congelada_em' => '2026-11-20 13:29:58', 'historico' => [['accao' => 'folha_contagem_congelada', 'referencia' => '2026-11-20 13:29:58']],
        'contagem' => ['10000332' => 36], 'contado_em' => '2026-11-20 16:12:00', 'base' => ['corte' => ['hora' => '2026-11-20 13:29:58']]];
    igual('2026-11-20 16:12:00', naval_carr_referencia_contagem($reg, $dia), 'conta a hora da contagem, não a da folha');
    $reg['contado_em'] = '2026-11-21 09:00:00';   // escrita no dia seguinte: a contagem física é a do fim do dia da conciliação
    igual('2026-11-20 23:59:59', naval_carr_referencia_contagem($reg, $dia));
    // ainda sem contagem: a folha (para a folha impressa mostrar o mesmo que se vai contar)
    unset($reg['contagem'], $reg['contado_em']);
    igual('2026-11-20 13:29:58', naval_carr_referencia_contagem($reg, $dia), 'sem contagem: a hora da folha');
});

teste('carga do dia seguinte: guia à tarde depois da última venda fica fora da contagem; de manhã ou com vendas depois conta', function () {
    $l = function ($rota, $art, $hora, $ent, $vend, $antes = 1) {
        return ['ROTA' => $rota, 'ARM_COD' => '1000' . $rota, 'ARM' => 'Armazém ' . $rota . ' - FHR PIV', 'CODIGO' => $art, 'HORA' => '2026-11-20 ' . $hora,
            'ANTES' => $antes, 'ENTRADAS' => $ent, 'SAIDAS' => 0.0, 'VENDIDO' => $vend];
    };
    $mov = naval_carr_movimentos_agrupar([
        // rota 1004: venda às 13:57, guia GTC A1000/122 às 15:42 (antes do corte das 16:00) → carga de amanhã
        $l('1004', 'N', '13:57:31', 0.0, 57.0), $l('1004', 'N', '15:42:21', 25.0, 0.0),
        // rota 1003: guia de manhã e vendas depois → carga do próprio dia
        $l('1003', 'N', '08:31:07', 40.0, 0.0), $l('1003', 'N', '11:20:00', 0.0, 10.0),
        // rota 1005: guia à tarde mas o promotor ainda vendeu depois → do próprio dia
        $l('1005', 'N', '14:10:00', 30.0, 0.0), $l('1005', 'N', '14:40:00', 0.0, 5.0),
        // rota 1010: sem vendas no dia, guia à tarde → carga de amanhã
        $l('1010', 'N', '15:04:53', 100.0, 0.0),
    ], ['1003', '1004', '1005', '1010']);
    igual([25.0, 0.0, 25.0, 57.0], [$mov['1004']['N']['c'], $mov['1004']['N']['ca'], $mov['1004']['N']['cs'], $mov['1004']['N']['va']], 'carga de amanhã');
    igual([40.0, null], [$mov['1003']['N']['ca'], $mov['1003']['N']['cs'] ?? null], 'guia de manhã');
    igual([30.0, null], [$mov['1005']['N']['ca'], $mov['1005']['N']['cs'] ?? null], 'vendeu depois da guia');
    igual([0.0, 100.0], [$mov['1010']['N']['ca'], $mov['1010']['N']['cs']], 'sem vendas: carga de amanhã');
    // na conciliação: o esperado é o que estava na carrinha (93 − 57 = 36); a carga de amanhã aparece «depois do corte»
    $r = ['s0' => ['N' => ['n' => 'Fraldas', 'q' => 93.0]], 's1' => ['N' => ['n' => 'Fraldas', 'q' => 61.0]], 'v' => ['N' => ['n' => 'Fraldas', 'q' => 57.0]], 'mov' => $mov['1004']];
    $r = naval_carr_congelar_base($r, '2026-11-20 16:00:00', naval_carr_stock_por_movimentos($r['s0'], $mov['1004'], $r), $mov['1004']);
    $x = naval_carr_linhas($r, ['N' => 36])[0];
    igual([0.0, 57.0, 36.0, 0.0, 25.0], [$x['carregado'], $x['vendido'], $x['esperado'], $x['diferenca'], $x['depois_do_corte']]);
});

teste('contagem em atraso: a carga da tarde de um dia anterior já estava na carrinha; só no dia da contagem conta como carga de amanhã', function () {
    $l = function ($dia, $hora, $ent, $vend) {
        return ['ROTA' => '1004', 'ARM_COD' => '10001004', 'ARM' => 'Armazém 1004 - FHR PIV', 'CODIGO' => 'N', 'HORA' => $dia . ' ' . $hora,
            'ANTES' => 1, 'ENTRADAS' => $ent, 'SAIDAS' => 0.0, 'VENDIDO' => $vend];
    };
    // conciliação de 19-11 contada a 20-11 às 12:38: guia da tarde de 19-11 (para 20-11) já na carrinha; vendas de 20-11 de manhã
    $rows = [$l('2026-11-19', '11:00:00', 0.0, 18.0), $l('2026-11-19', '15:20:36', 50.0, 0.0), $l('2026-11-20', '10:00:00', 0.0, 12.0)];
    $mov = naval_carr_movimentos_agrupar($rows, ['1004'], [], '2026-11-20');
    igual([50.0, null, 30.0], [$mov['1004']['N']['ca'], $mov['1004']['N']['cs'] ?? null, $mov['1004']['N']['va']]);
    // a mesma guia, numa contagem feita no próprio dia 19-11, é a carga de amanhã
    $mov = naval_carr_movimentos_agrupar([$rows[0], $rows[1]], ['1004'], [], '2026-11-19');
    igual([0.0, 50.0], [$mov['1004']['N']['ca'], $mov['1004']['N']['cs']]);
});

teste('regularização documentada: o acerto posterior da Logística no Pinguim BO explica a diferença (no todo ou em parte)', function () {
    $linhas = [
        ['codigo' => '292', 'diferenca' => -30.0], ['codigo' => '332', 'diferenca' => -350.0],
        ['codigo' => '385', 'diferenca' => -20.0], ['codigo' => '015', 'diferenca' => 20.0], ['codigo' => '999', 'diferenca' => 0.0],
    ];
    $acertos = [
        ['rota' => '1011', 'codigo' => '292', 'doc' => 'SA A1000/118', 'em' => '2026-11-21 08:55:00', 'efeito' => -30.0],
        ['rota' => '1011', 'codigo' => '332', 'doc' => 'SA A1000/119', 'em' => '2026-11-21 08:58:00', 'efeito' => -300.0],
        ['rota' => '1011', 'codigo' => '385', 'doc' => 'SA A1000/120', 'em' => '2026-11-20 10:00:00', 'efeito' => -20.0],   // antes da contagem: não conta
        ['rota' => '1011', 'codigo' => '015', 'doc' => 'SA A1000/121', 'em' => '2026-11-21 09:00:00', 'efeito' => -5.0],    // sentido contrário
        ['rota' => '1008', 'codigo' => '292', 'doc' => 'SA A1000/122', 'em' => '2026-11-21 09:00:00', 'efeito' => -30.0],   // outra carrinha
    ];
    $r = naval_carr_regularizacoes($linhas, $acertos, '1011', '2026-11-20 16:00:00');
    igual(['292', '332'], array_map('strval', array_keys($r)));
    igual([-30.0, 'SA A1000/118'], [$r['292']['q'], $r['292']['doc']]);
    igual(-300.0, $r['332']['q'], 'acerto menor do que a diferença: explica só essa parte');
    // na conciliação: o que fica por explicar e o valor só da parte que falta
    $base = ['s0' => ['332' => ['n' => 'Fraldas', 'q' => 450.0]], 's1' => [], 'v' => [], 'mov' => [], 'regs' => $r];
    $l = naval_carr_linhas($base, ['332' => 100.0], ['332' => 15000.0])[0];
    igual([-350.0, -50.0, -750000.0, 'SA A1000/119'], [$l['diferenca_bruta'], $l['diferenca'], $l['valor'], $l['regularizado']['doc']]);
});

teste('venda registada depois da contagem (impressora avariada): explica a falta só no que excede o contado, até à carga seguinte', function () {
    $ev = function ($cod, $tipo, $em, $q, $doc) { return ['rota' => '1008', 'codigo' => $cod, 'doc' => $doc, 'em' => $em, 'efeito' => $q, 'tipo' => $tipo]; };
    $acertos = [
        $ev('385', 'venda', '2026-11-18 14:39:00', -20.0, 'FR 4'),                                     // contou 0, vendeu 20 no dia seguinte
        $ev('500', 'venda', '2026-11-18 14:39:00', -20.0, 'FR 4'),                                     // contou 30: os 20 podiam ser dos contados
        $ev('600', 'carga', '2026-11-18 08:00:00', 40.0, 'GTC 99'), $ev('600', 'venda', '2026-11-18 10:00:00', -20.0, 'FR 5'),   // carga antes da venda
    ];
    $linhas = [
        ['codigo' => '385', 'diferenca' => -20.0, 'contado' => 0.0],
        ['codigo' => '500', 'diferenca' => -20.0, 'contado' => 30.0],
        ['codigo' => '600', 'diferenca' => -20.0, 'contado' => 0.0],
    ];
    $r = naval_carr_regularizacoes($linhas, $acertos, '1008', '2026-11-17 16:00:00');
    igual(['385'], array_map('strval', array_keys($r)), 'só a 385 fica explicada');
    igual([-20.0, 'FR 4'], [$r['385']['q'], $r['385']['doc']]);
});

teste('devolução lançada no fecho (a partir das 15:30) é depois da contagem; diagnóstico de guia anulada e de sentido trocado', function () {
    $row = ['ROTA' => '1007', 'ARM_COD' => '10001007', 'ARM' => 'Armazém 1007 - FHR PIV', 'CODIGO' => 'N', 'ANTES' => 1, 'VENDIDO' => 0.0];
    $mov = naval_carr_movimentos_agrupar([
        $row + ['HORA' => '2026-11-18 08:24:00', 'ENTRADAS' => 150.0, 'SAIDAS' => 0.0],
        $row + ['HORA' => '2026-11-18 15:36:00', 'ENTRADAS' => 0.0, 'SAIDAS' => 110.0],    // SA 90 no fecho: depois da contagem
        ['HORA' => '2026-11-18 11:00:00', 'ENTRADAS' => 0.0, 'SAIDAS' => 5.0, 'CODIGO' => 'M'] + $row,   // devolução de manhã: conta
    ], ['1007'], [], '2026-11-18', '15:30:00');
    igual([150.0, 0.0, 110.0], [$mov['1007']['N']['ca'], $mov['1007']['N']['da'], $mov['1007']['N']['df']]);
    igual(5.0, $mov['1007']['M']['da']);
    $guias = [
        ['rota' => '1005', 'codigo' => '292', 'doc' => 'GTC A1000/61', 'hora' => '2026-11-16 08:54:00', 'q' => 15.0, 'anulada' => true],
        ['rota' => '1011', 'codigo' => '332', 'doc' => 'GTC A1000/121', 'hora' => '2026-11-20 15:40:00', 'q' => 50.0, 'anulada' => false],
    ];
    $d = naval_carr_diagnosticos([['codigo' => '292', 'diferenca' => 15.0]], $guias, '1005');
    igual(['guia_anulada', 'GTC A1000/61'], [$d['292']['tipo'], $d['292']['doc']]);
    $d = naval_carr_diagnosticos([['codigo' => '332', 'diferenca' => -50.0], ['codigo' => '292', 'diferenca' => -7.0]], $guias, '1011');
    igual(['sentido_trocado', 'GTC A1000/121'], [$d['332']['tipo'], $d['332']['doc']]);
    igual(1, count($d), 'sem guia igual à diferença, sem diagnóstico');
});

teste('regularização: facturas de segunda-feira para lá do contado e da carga dessa manhã explicam a falta de sábado (rota 1011, 21-11)', function () {
    $ref = '2026-11-21 14:00:00';
    $ev = function ($cod, $tipo, $em, $q, $doc) { return ['rota' => '1011', 'codigo' => $cod, 'doc' => $doc, 'em' => $em, 'efeito' => $q, 'tipo' => $tipo]; };
    $ac = [
        $ev('292', 'carga', '2026-11-23 08:26:52', 30.0, 'GTC A1000/131'), $ev('293', 'carga', '2026-11-23 08:26:52', 50.0, 'GTC A1000/131'),
        $ev('015', 'venda', '2026-11-23 11:04:01', -3.0, 'FR FR0126S77687N/4'), $ev('292', 'venda', '2026-11-23 11:04:01', -2.0, 'FR FR0126S77687N/4'),
        $ev('293', 'venda', '2026-11-23 11:04:01', -2.0, 'FR FR0126S77687N/4'), $ev('332', 'venda', '2026-11-23 11:04:01', -3.0, 'FR FR0126S77687N/4'),
        $ev('386', 'venda', '2026-11-23 11:04:01', -1.0, 'FR FR0126S77687N/4'),
        $ev('292', 'venda', '2026-11-23 11:15:37', -13.0, 'FR FR0126S77687N/5'), $ev('293', 'venda', '2026-11-23 11:15:37', -13.0, 'FR FR0126S77687N/5'),
        $ev('292', 'venda', '2026-11-23 11:20:52', -10.0, 'FR FR0126S77687N/6'), $ev('293', 'venda', '2026-11-23 11:20:52', -10.0, 'FR FR0126S77687N/6'),
        $ev('386', 'venda', '2026-11-23 11:20:52', -5.0, 'FR FR0126S77687N/6'),
        $ev('292', 'venda', '2026-11-23 12:35:06', -30.0, 'FR FR0126S77687N/7'), $ev('293', 'venda', '2026-11-23 12:35:06', -20.0, 'FR FR0126S77687N/7'),
        $ev('386', 'venda', '2026-11-23 12:35:06', -10.0, 'FR FR0126S77687N/7'),
        $ev('292', 'venda', '2026-11-23 12:44:04', -25.0, 'FR FR0126S77687N/8'), $ev('293', 'venda', '2026-11-23 12:44:04', -25.0, 'FR FR0126S77687N/8'),
        $ev('386', 'venda', '2026-11-23 12:47:16', -20.0, 'FR FR0126S77687N/9'), $ev('293', 'venda', '2026-11-23 15:45:57', -29.0, 'FR FR0126S77687N/10'),
        $ev('332', 'venda', '2026-11-23 15:47:34', -50.0, 'FR FR0126S77687N/11'),
        $ev('293', 'carga', '2026-11-23 16:21:35', 24.0, 'GTC A1000/144'), $ev('386', 'carga', '2026-11-23 16:21:35', 15.0, 'GTC A1000/144'),
        $ev('292', 'venda', '2026-11-27 10:00:00', -40.0, 'FR FR0126S77687N/30'),   // fora da janela: não conta
    ];
    $linhas = [
        ['codigo' => '332', 'diferenca' => -103.0, 'contado' => 97.0],
        ['codigo' => '292', 'diferenca' => -50.0, 'contado' => 0.0],
        ['codigo' => '293', 'diferenca' => -50.0, 'contado' => 0.0],
        ['codigo' => '015', 'diferenca' => -13.0, 'contado' => 7.0],
        ['codigo' => '386', 'diferenca' => -26.0, 'contado' => 19.0],
    ];
    $r = naval_carr_regularizacoes($linhas, $ac, '1011', $ref);
    // com a contagem de segunda-feira às 16:00 (97 fraldas, 7 Mimo, 1 do 293, 9 do 386): o facturado entre as duas
    // contagens que não saiu da carrinha já tinha saído antes da de sábado
    $p = naval_carr_regularizacoes($linhas, $ac, '1011', $ref, null, ['em' => '2026-11-23 16:00:00', 'q' => ['332' => 97.0, '015' => 7.0, '293' => 1.0, '386' => 9.0, '292' => 0.0]]);
    igual([-53.0, -3.0, -50.0, -26.0, -50.0], [$p['332']['q'], $p['015']['q'], $p['293']['q'], $p['386']['q'], $p['292']['q']]);
    igual(-50.0, $r['292']['q'], '292: carregou 30 e facturou 80 → os 50 de sábado');
    // venda à consignação (para facturar no SAP): a Logística tira as 50 fraldas e as 10 Mimo da carrinha com uma saída de armazém
    $ac2 = array_merge($ac, [$ev('332', 'acerto', '2026-11-24 09:00:00', -50.0, 'SA A1000/150'), $ev('015', 'acerto', '2026-11-24 09:00:00', -10.0, 'SA A1000/150')]);
    $c = naval_carr_regularizacoes($linhas, $ac2, '1011', $ref, null, ['em' => '2026-11-23 16:00:00', 'q' => ['332' => 97.0, '015' => 7.0, '293' => 1.0, '386' => 9.0, '292' => 0.0]]);
    igual([-103.0, -13.0], [$c['332']['q'], $c['015']['q']], 'facturas + saída da consignação explicam toda a falta de sábado');
    igual(true, strpos($c['332']['doc'], 'SA A1000/150') !== false);
    igual(-49.0, $r['293']['q'], '293: até à carga das 16:21, 99 facturados para 50 carregados → 49');
    igual(-17.0, $r['386']['q'], '386: 36 facturados para 19 contados → 17');
    igual(false, isset($r['332']), '332: vendeu 53 dos 97 contados — não prova nada');
    igual(false, isset($r['015']), '015: vendeu 3 dos 7 contados — não prova nada');
    igual(true, strpos($r['292']['doc'], 'FR FR0126S77687N/8') !== false);
});

teste('regularização: vendas facturadas depois da contagem com o contado devolvido ao armazém (rota 1007, 21-11): explicam pelas facturas', function () {
    $ref = '2026-11-21 14:00:00';
    $ac = [
        ['rota' => '1007', 'codigo' => '10000332', 'doc' => 'FR FR0126S77774N/4', 'em' => '2026-11-23 08:26:42', 'efeito' => -10.0, 'tipo' => 'venda'],
        ['rota' => '1007', 'codigo' => '10000015', 'doc' => 'FR FR0126S77774N/5', 'em' => '2026-11-23 08:35:06', 'efeito' => -5.0, 'tipo' => 'venda'],
        ['rota' => '1007', 'codigo' => '10000332', 'doc' => 'SA A1000/130', 'em' => '2026-11-23 08:51:56', 'efeito' => -90.0, 'tipo' => 'acerto'],
        ['rota' => '1007', 'codigo' => '10000015', 'doc' => 'SA A1000/130', 'em' => '2026-11-23 08:51:56', 'efeito' => -10.0, 'tipo' => 'acerto'],
        ['rota' => '1007', 'codigo' => '10000292', 'doc' => 'SA A1000/130', 'em' => '2026-11-23 08:51:56', 'efeito' => -14.0, 'tipo' => 'acerto'],
    ];
    $linhas = [
        ['codigo' => '10000332', 'diferenca' => -10.0, 'contado' => 90.0],
        ['codigo' => '10000015', 'diferenca' => -5.0, 'contado' => 10.0],
        ['codigo' => '10000292', 'diferenca' => -5.0, 'contado' => 14.0],   // sem venda depois: a devolução do contado não explica
    ];
    $r = naval_carr_regularizacoes($linhas, $ac, '1007', $ref);
    igual(['q' => -10.0, 'doc' => 'FR FR0126S77774N/4, contado devolvido em SA A1000/130', 'em' => '2026-11-23 08:26:42'], $r['10000332']);
    igual(-5.0, $r['10000015']['q']);
    igual(false, isset($r['10000292']), 'a falta sem venda registada continua por explicar');
    // venda depois só de parte da falta, com o contado devolvido: o resto fica por explicar (não vai para a SA)
    $r2 = naval_carr_regularizacoes([['codigo' => '10000332', 'diferenca' => -15.0, 'contado' => 90.0]], $ac, '1007', $ref);
    igual(-10.0, $r2['10000332']['q']);
});

teste('regularização: vendas entre o corte e a contagem física já tinham saído da carrinha (rota 1009, 23-11)', function () {
    $ref = '2026-11-23 16:00:00';
    $ev = function ($cod, $tipo, $em, $q, $doc) { return ['rota' => '1009', 'codigo' => $cod, 'doc' => $doc, 'em' => $em, 'efeito' => $q, 'tipo' => $tipo]; };
    $ac = [
        $ev('292', 'venda', '2026-11-23 16:18:02', -150.0, 'FR FR0126S77745N/13'),
        $ev('332', 'venda', '2026-11-23 16:42:09', -50.0, 'FR FR0126S77745N/14'),
        $ev('385', 'venda', '2026-11-23 17:30:00', -5.0, 'FR FR0126S77745N/15'),   // depois da contagem: estava na carrinha
    ];
    $linhas = [
        ['codigo' => '292', 'diferenca' => -150.0, 'contado' => 0.0],
        ['codigo' => '332', 'diferenca' => -50.0, 'contado' => 147.0],
        ['codigo' => '385', 'diferenca' => -5.0, 'contado' => 20.0],
    ];
    $r = naval_carr_regularizacoes($linhas, $ac, '1009', $ref, '2026-11-23 16:47:00');
    igual(['q' => -150.0, 'doc' => 'FR FR0126S77745N/13', 'em' => '2026-11-23 16:18:02'], $r['292']);
    igual(['q' => -50.0, 'doc' => 'FR FR0126S77745N/14', 'em' => '2026-11-23 16:42:09'], $r['332'], 'contou 147 = 197 − 50 vendidos antes de contar');
    igual(false, isset($r['385']), 'venda depois da contagem não explica');
    // sem a hora da contagem (ou gravada muito depois do corte): só a conservação, que não prova a 332
    $s = naval_carr_regularizacoes($linhas, $ac, '1009', $ref, '2026-11-24 09:00:00');
    igual([true, false], [isset($s['292']), isset($s['332'])]);
});

teste('referência da contagem: a hora congelada na base prevalece sobre o horário actual', function () {
    $dia = '2026-11-21';
    $reg = ['contagem' => ['A' => 1.0], 'contado_em' => '2026-11-21 14:40:00', 'base' => ['corte' => ['hora' => '2026-11-21 14:00:00', 'origem' => 'contagem']]];
    igual('2026-11-21 14:00:00', naval_carr_referencia_contagem($reg, $dia));
});

teste('diagnóstico: falta e sobra da mesma quantidade em dois artigos com o mesmo preço é provável troca de artigo (rota 1010, 23-11)', function () {
    $linhas = [
        ['codigo' => '10000386', 'artigo' => 'Sabão verde 850 g', 'diferenca' => -5.0, 'preco' => 27500.0],
        ['codigo' => '10000385', 'artigo' => 'Sabão castanho 850 g', 'diferenca' => 5.0, 'preco' => 27500.0],
        ['codigo' => '10000332', 'artigo' => 'Fraldas', 'diferenca' => -5.0, 'preco' => 14800.0],   // preço diferente: não
    ];
    $d = naval_carr_diagnosticos($linhas, [], '1010');
    igual(['troca_artigo', 'troca_artigo'], [$d['10000386']['tipo'], $d['10000385']['tipo']]);
    igual(['10000385', '10000386'], [$d['10000386']['doc'], $d['10000385']['doc']]);
    igual(false, isset($d['10000332']));
    igual(true, strpos($d['10000386']['texto'], 'saída de 5 10000386 e entrada de 5 10000385') !== false);
    igual(true, isset(NAVAL_CARR_MOTIVOS['troca_artigo']) && isset(NAVAL_CARR_MOTIVOS['consignacao']));
});

teste('venda por depósito (facturada no SAP): registo validado para seguir a factura SAP; não regulariza a carrinha (rota 1011, GRN 9916)', function () {
    $eu = ['login' => 'coord'];
    lanca(400, function () use ($eu) { naval_carr_deposito_registar('1011', date('Y-m-d'), '', [['codigo' => '10000332', 'quantidade' => 50, 'preco' => 14800]], 'transferencia', '', '', $eu); });
    lanca(400, function () use ($eu) { naval_carr_deposito_registar('1011', date('Y-m-d'), 'GRN 9916', [], 'transferencia', '', '', $eu); });
    lanca(400, function () use ($eu) { naval_carr_deposito_registar('1011', date('Y-m-d'), 'GRN 9916', [['codigo' => '10000332', 'quantidade' => 50, 'preco' => 14800]], 'cheque', '', '', $eu); });
    $d = naval_carr_deposito_registar('1011', date('Y-m-d'), 'GRN 9916', [
        ['codigo' => '10000332', 'artigo' => 'Fraldas Naval', 'quantidade' => 50, 'preco' => 14800],
        ['codigo' => '10000015', 'artigo' => 'Fraldas Mimo', 'quantidade' => 10, 'preco' => 12500],
    ], 'transferencia', '', 'Venda por depósito, recibo assinado pelo Robel', $eu);
    igual([865000.0, null, 'Transferência'], [$d['total'], $d['factura_sap'], $d['pagamento_nome']]);
    lanca(409, function () use ($eu) { naval_carr_deposito_registar('1011', date('Y-m-d'), 'grn 9916', [['codigo' => '10000332', 'quantidade' => 1, 'preco' => 1]], 'transferencia', '', '', $eu); });
    // sábado: as 53 fraldas pelas facturas de segunda (Pinguim BO); as 50 da venda por depósito só pela SA da Logística
    $ev = function ($cod, $tipo, $em, $q, $doc) { return ['rota' => '1011', 'codigo' => $cod, 'doc' => $doc, 'em' => $em, 'efeito' => $q, 'tipo' => $tipo]; };
    $acertos = [$ev('332', 'venda', '2026-11-23 11:04:01', -3.0, 'FR 4'), $ev('332', 'venda', '2026-11-23 15:47:34', -50.0, 'FR 11')];
    $r = naval_carr_regularizacoes([['codigo' => '332', 'diferenca' => -103.0, 'contado' => 97.0]], $acertos, '1011', '2026-11-21 14:00:00', null,
        ['em' => '2026-11-23 16:00:00', 'q' => ['332' => 97.0]]);
    igual(-53.0, $r['332']['q'], 'sem a SA, as 50 da venda por depósito ficam por explicar');
    $acertos[] = $ev('332', 'acerto', '2026-11-24 10:02:25', -50.0, 'SA A1000/144');
    $r = naval_carr_regularizacoes([['codigo' => '332', 'diferenca' => -103.0, 'contado' => 97.0]], $acertos, '1011', '2026-11-21 14:00:00', null,
        ['em' => '2026-11-23 16:00:00', 'q' => ['332' => 97.0]]);
    igual(-103.0, $r['332']['q']);
    igual(true, strpos($r['332']['doc'], 'SA A1000/144') !== false);
    $f = naval_carr_deposito_factura($d['id'], 'FT 2026/123', $eu);
    igual('FT 2026/123', $f['factura_sap']);
});

teste('regularização exacta: acerto da Logística com a quantidade da falta explica-a, mesmo maior do que o contado (rota 1011, 23-11)', function () {
    // contou 7 MIMO (faltavam 10) e 97 NAVAL (faltavam 50); a SA A1000/144 de 24-11 tirou 10 MIMO e 50 NAVAL (venda por
    // depósito GRN 9916); depois, facturas normais do dia 24
    $linhas = [['codigo' => '10000015', 'diferenca' => -10.0, 'contado' => 7.0], ['codigo' => '10000332', 'diferenca' => -50.0, 'contado' => 97.0]];
    $ac = [
        ['rota' => '1011', 'codigo' => '10000015', 'doc' => 'SA A1000/144', 'em' => '2026-11-24 10:02:25', 'efeito' => -10.0, 'tipo' => 'acerto'],
        ['rota' => '1011', 'codigo' => '10000332', 'doc' => 'SA A1000/144', 'em' => '2026-11-24 10:02:25', 'efeito' => -50.0, 'tipo' => 'acerto'],
        ['rota' => '1011', 'codigo' => '10000332', 'doc' => 'FR FR0126S77687N/13', 'em' => '2026-11-24 10:29:27', 'efeito' => -50.0, 'tipo' => 'venda'],
        ['rota' => '1011', 'codigo' => '10000015', 'doc' => 'FR FR0126S77687N/16', 'em' => '2026-11-24 14:38:22', 'efeito' => -7.0, 'tipo' => 'venda'],
    ];
    $r = naval_carr_regularizacoes($linhas, $ac, '1011', '2026-11-23 16:00:00', '2026-11-23 16:14:00');
    igual(['q' => -10.0, 'doc' => 'SA A1000/144', 'em' => '2026-11-24 10:02:25'], $r['10000015']);
    igual(['q' => -50.0, 'doc' => 'SA A1000/144', 'em' => '2026-11-24 10:02:25'], $r['10000332']);
    // a mesma saída com outra quantidade, maior do que o contado, continua a ser a devolução do contado (não explica)
    $ac2 = [['rota' => '1011', 'codigo' => '10000015', 'doc' => 'SA A1000/150', 'em' => '2026-11-24 10:02:25', 'efeito' => -7.0, 'tipo' => 'acerto']];
    igual([], naval_carr_regularizacoes([['codigo' => '10000015', 'diferenca' => -10.0, 'contado' => 7.0]], $ac2, '1011', '2026-11-23 16:00:00', '2026-11-23 16:14:00'));
});

teste('revisão: mudança da base sem efeito no stock esperado não altera a conciliação (rota 1005, 17-11)', function () {
    igual(false, naval_carr_diferencas_com_efeito([['codigo' => '10000292', 'mudou' => ['carregado' => [15, 20], 'devolvido' => [0, 5]]], ['codigo' => '10000382', 'mudou' => ['inicial' => [0, 12], 'devolvido' => [0, 12]]]]));
    igual(true, naval_carr_diferencas_com_efeito([['codigo' => '10000015', 'mudou' => ['inicial' => [15, 14], 'esperado' => [15, 14]]]]));
});

teste('saída de armazém entre o corte e a contagem física explica a falta (rota 1009, 24-11: SA A1000/154, vendas SAP)', function () {
    // corte às 15:30, a Logística lança às 16:31 a saída das 147 fraldas vendidas no SAP, contagem às 16:40 (0 na carrinha)
    $ac = [['rota' => '1009', 'codigo' => '10000332', 'doc' => 'SA A1000/154', 'em' => '2026-11-24 16:31:25', 'efeito' => -147.0, 'tipo' => 'acerto']];
    $r = naval_carr_regularizacoes([['codigo' => '10000332', 'diferenca' => -147.0, 'contado' => 0.0]], $ac, '1009', '2026-11-24 15:30:00', '2026-11-24 16:40:00');
    igual(['q' => -147.0, 'doc' => 'SA A1000/154', 'em' => '2026-11-24 16:31:25'], $r['10000332']);
    // a mesma saída depois da contagem continua a explicar (regra do acerto exacto)
    $r = naval_carr_regularizacoes([['codigo' => '10000332', 'diferenca' => -147.0, 'contado' => 0.0]], $ac, '1009', '2026-11-24 15:30:00', '2026-11-24 16:20:00');
    igual(-147.0, $r['10000332']['q']);
    // uma carga (entrada) nesse intervalo não explica faltas
    $ac2 = [['rota' => '1009', 'codigo' => '10000332', 'doc' => 'GTC A1000/200', 'em' => '2026-11-24 16:00:00', 'efeito' => 50.0, 'tipo' => 'carga']];
    igual([], naval_carr_regularizacoes([['codigo' => '10000332', 'diferenca' => -147.0, 'contado' => 0.0]], $ac2, '1009', '2026-11-24 15:30:00', '2026-11-24 16:40:00'));
});

teste('acerto lançado depois de outra contagem só explica a anterior se a diferença se manteve (rota 1011: SA 144 sim, SA 156 não)', function () {
    $sa = function ($cod, $em, $q, $doc) { return ['rota' => '1011', 'codigo' => $cod, 'doc' => $doc, 'em' => $em, 'efeito' => $q, 'tipo' => 'acerto']; };
    // 20-11: faltavam 15 sabões castanhos; a 23-11 a contagem batia; a SA 156 de 24-11 é da contagem de 24-11
    $seg = [['em' => '2026-11-21 14:00:00', 'dif' => ['293' => -15.0]], ['em' => '2026-11-23 16:00:00', 'dif' => ['293' => 0.0]], ['em' => '2026-11-24 15:30:00', 'dif' => ['293' => -15.0]]];
    $r = naval_carr_regularizacoes([['codigo' => '293', 'diferenca' => -15.0, 'contado' => 0.0]], [$sa('293', '2026-11-24 16:53:56', -15.0, 'SA A1000/156')], '1011', '2026-11-20 16:00:00', null, null, $seg);
    igual([], $r);
    // 21-11: faltavam 50 fraldas e a 23-11 ainda faltavam: a SA 144 de 24-11 explica as duas contagens
    $seg = [['em' => '2026-11-23 16:00:00', 'dif' => ['332' => -50.0]]];
    $r = naval_carr_regularizacoes([['codigo' => '332', 'diferenca' => -50.0, 'contado' => 97.0]], [$sa('332', '2026-11-24 10:02:25', -50.0, 'SA A1000/144')], '1011', '2026-11-21 14:00:00', null, null, $seg);
    igual(-50.0, $r['332']['q']);
});
