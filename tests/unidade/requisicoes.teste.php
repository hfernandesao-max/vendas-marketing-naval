<?php
// Requisições de reposição da carrinha ao armazém (nucleo/servicos/requisicoes.php).
naval_servico('carrinha');

teste('conciliação validada cria a requisição com o vendido (uma só por rota e dia, nunca duplicada)', function () {
    $data = date('Y-m-d', time() - 3 * 86400);
    $linhas = [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'unidade' => 'CX', 'vendido' => 26.0], ['codigo' => 'B2', 'artigo' => 'Sabão', 'unidade' => 'UN', 'vendido' => 0.0]];
    $r = naval_req_da_conciliacao($data, '1001', $linhas, 'tec');
    verdade((bool) preg_match('/^PO-\d{4}-\d{6}$/', $r['numero']), 'número sequencial do ano');
    igual('rascunho', $r['estado']);
    igual([['codigo' => 'A1', 'quantidade' => 26.0, 'sugerida' => 26.0, 'origem' => 'vendido']],
        array_map(function ($l) { return ['codigo' => $l['codigo'], 'quantidade' => $l['quantidade'], 'sugerida' => $l['sugerida'], 'origem' => $l['origem']]; }, $r['linhas']),
        'só os artigos vendidos');
    igual('conciliacao', $r['origem']['tipo']);
    igual($r['numero'], naval_req_da_conciliacao($data, '1001', $linhas, 'tec')['numero'], 'repetir não cria outra');
    igual($r['numero'], naval_req_da_conciliacao_numero($data, '1001'));
    igual(null, naval_req_da_conciliacao($data, '1002', [['codigo' => 'A1', 'artigo' => 'X', 'vendido' => 0]], 'tec'), 'sem vendas, sem requisição');
});

teste('rascunho editável (com controlo de versão); emitida fica fechada; 2.ª via; carga confirmada por outra pessoa', function () {
    $r = naval_req_criar('1003', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'unidade' => 'CX', 'quantidade' => 5, 'sugerida' => 999, 'origem' => 'vendido']], 'pro');
    igual('adicionado', $r['linhas'][0]['origem'], 'origem não vem do navegador num pedido manual');
    lanca(400, function () use ($r) { naval_req_gravar($r['numero'], [['codigo' => 'A1', 'quantidade' => -1]], '', $r['versao'], 'pro'); });
    $g = naval_req_gravar($r['numero'], [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 8], ['codigo' => 'C3', 'artigo' => 'Detergente', 'quantidade' => 2],
        ['codigo' => 'D4', 'artigo' => 'Zero', 'quantidade' => 0]], 'mais detergente', $r['versao'], 'pro');
    igual(['A1' => 8.0, 'C3' => 2.0], array_column($g['linhas'], 'quantidade', 'codigo'), 'linhas a zero saem');
    lanca(409, function () use ($r) { naval_req_gravar($r['numero'], [], '', $r['versao'], 'pro'); }); // versão antiga
    $e = naval_req_emitir($r['numero'], $g['versao'], 'pro');
    igual('emitida', $e['estado']);
    lanca(409, function () use ($e) { naval_req_gravar($e['numero'], [['codigo' => 'A1', 'quantidade' => 1]], '', $e['versao'], 'pro'); });
    lanca(409, function () use ($e) { naval_req_anular($e['numero'], 'já não é precisa', null, 'pro'); });
    igual(2, count(naval_req_reimprimir($e['numero'], 'pro')['impressoes']), '2.ª via registada');
    // por aprovar: não se carrega; quem criou/emitiu não aprova; aprovada uma só vez
    verdade(naval_req_precisa_aprovacao($e) && !naval_req_pronta_carga($e));
    lanca(409, function () use ($e) { naval_req_confirmar($e['numero'], [], 'log'); });
    // circuito: emitida → validada pelo controlo de stock → aprovada pelo superior (três pessoas diferentes)
    lanca(409, function () use ($e) { naval_req_aprovar($e['numero'], 'sup'); });   // falta a validação
    lanca(403, function () use ($e) { naval_req_validar_quantidades($e['numero'], [], '', 'pro'); });   // quem emitiu não valida
    naval_req_validar_quantidades($e['numero'], [], '', 'teresa.gomes');
    lanca(403, function () use ($e) { naval_req_aprovar($e['numero'], 'pro'); });
    lanca(403, function () use ($e) { naval_req_aprovar($e['numero'], 'teresa.gomes'); });   // quem validou não aprova
    $ap = naval_req_aprovar($e['numero'], 'sup');
    verdade(naval_req_pronta_carga($ap) && $ap['aprovada_por'] === 'sup');
    lanca(409, function () use ($e) { naval_req_aprovar($e['numero'], 'sup'); });
    lanca(403, function () use ($e) { naval_req_confirmar($e['numero'], [], 'pro'); }); // quem emitiu não confirma
    $c = naval_req_confirmar($e['numero'], ['C3' => 1], 'log', ['C3' => 'falta_armazem']);
    igual('carregada', $c['estado']);
    igual(['A1' => 8.0, 'C3' => 1.0], array_column($c['linhas'], 'entregue', 'codigo'), 'por omissão, o requisitado');
    lanca(409, function () use ($e) { naval_req_confirmar($e['numero'], [], 'log'); });
    $carga = naval_req_carga(date('Y-m-d'), ['1003']);
    igual('carregada', $carga['1003'][0]['estado']);
    igual([], naval_req_carga(date('Y-m-d'), ['9999']), 'âmbito por rota');
});

teste('anular um rascunho exige motivo; a lista do período mostra o estado', function () {
    $r = naval_req_criar('1004', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 1]], 'pro');
    lanca(400, function () use ($r) { naval_req_anular($r['numero'], '', $r['versao'], 'pro'); });
    igual('anulada', naval_req_anular($r['numero'], 'pedido em duplicado', $r['versao'], 'pro')['estado']);
    $l = array_column(naval_req_listar(date('Y-m-d'), date('Y-m-d'), ['1004']), 'estado', 'numero');
    igual('anulada', $l[$r['numero']]);
});

teste('requisições: só se registam ao gravar com artigos; um só rascunho por rota e dia; vazios antigos anulados', function () {
    $hoje = date('Y-m-d');
    $ontem = date('Y-m-d', time() - 86400);
    $art = [['codigo' => 'X', 'artigo' => 'Óleo', 'quantidade' => 2]];
    lanca(400, function () use ($hoje) { naval_req_criar('2001', $hoje, [], 'henrique'); });   // «Criar» sem artigos não regista nada
    lanca(400, function () use ($hoje) { naval_req_criar('2001', $hoje, [['codigo' => 'X', 'artigo' => 'Óleo', 'quantidade' => 0]], 'henrique'); });
    $a = naval_req_criar('2001', $hoje, $art, 'henrique');
    lanca(409, function () use ($hoje, $art) { naval_req_criar('2001', $hoje, $art, 'henrique'); });   // já há rascunho
    // rascunhos vazios antigos (de versões anteriores): a lista anula-os sozinha
    $c = naval_req_criar('2002', $ontem, $art, 'henrique');
    lanca(400, function () use ($c) { naval_req_gravar($c['numero'], [], '', $c['versao'], 'henrique'); });   // não grava vazia
    $c['linhas'] = [];
    naval_req_gravar_ficheiro($c);   // como os rascunhos vazios criados antes desta versão
    naval_req_listar($ontem, $hoje, null);
    igual(['anulada', 'automatico'], [naval_req_ler($c['numero'])['estado'], naval_req_ler($c['numero'])['anulada_por']]);
    igual('rascunho', naval_req_ler($a['numero'])['estado'], 'com artigos não se anula');
    $a['linhas'] = [];
    naval_req_gravar_ficheiro($a);
    igual([$a['numero']], naval_req_anular_vazios($hoje, 'henrique', ['2001']));
});

teste('requisições: nunca mais do que o stock do armazém principal (criar, gravar, emitir); a automática fica limitada', function () {
    $GLOBALS['naval_req_stock'] = ['A1' => 10.0, 'B2' => 0.0];
    try {
        $data = '2026-11-20';
        // automática: o vendido fica como sugerido e a quantidade limitada ao stock (B2 sem stock sai)
        $r = naval_req_da_conciliacao($data, '1007', [['codigo' => 'A1', 'artigo' => 'Fraldas', 'vendido' => 25], ['codigo' => 'B2', 'artigo' => 'Sabão', 'vendido' => 4]], 'tec');
        igual([['A1', 25.0, 10.0]], array_map(function ($l) { return [$l['codigo'], $l['sugerida'], $l['quantidade']]; }, $r['linhas']));
        // gravar acima do stock: recusado; dentro: aceite
        lanca(400, function () use ($r) { naval_req_gravar($r['numero'], [['codigo' => 'A1', 'artigo' => 'Fraldas', 'quantidade' => 11]], '', $r['versao'], 'sup'); });
        $g = naval_req_gravar($r['numero'], [['codigo' => 'A1', 'artigo' => 'Fraldas', 'quantidade' => 8]], '', $r['versao'], 'sup');
        // manual: artigo sem stock no armazém principal não se pede
        lanca(400, function () use ($data) { naval_req_criar('1008', $data, [['codigo' => 'B2', 'artigo' => 'Sabão', 'quantidade' => 1]], 'sup'); });
        // o stock baixou entretanto: emitir recusa
        $GLOBALS['naval_req_stock'] = ['A1' => 5.0];
        lanca(400, function () use ($g) { naval_req_emitir($g['numero'], $g['versao'], 'sup'); });
        // sem ligação ao Pinguim BO (stock desconhecido): não bloqueia a operação
        $GLOBALS['naval_req_stock'] = null;
        igual('emitida', naval_req_emitir($g['numero'], $g['versao'], 'sup')['estado']);
    } finally {
        unset($GLOBALS['naval_req_stock']);
    }
});

teste('fecho antes da carga: só se carrega com as conciliações anteriores fechadas; excepção autorizada fica fora da contagem', function () {
    $regra = naval_pasta('carrinha') . '/_regra-fecho.json';
    $tinha = is_file($regra) ? file_get_contents($regra) : null;
    $ontem = date('Y-m-d', time() - 86400);
    $rota = '1077';
    naval_json_gravar($regra, ['desde' => $ontem]);
    @unlink(naval_carr_pasta($ontem) . '/' . $rota . '.json');
    $dados = naval_json_ler(naval_carr_pasta($ontem) . '/_dados.json');
    $base = ['s0' => ['A1' => ['n' => 'Fraldas M', 'q' => 10.0]], 's1' => ['A1' => ['n' => 'Fraldas M', 'q' => 4.0]], 'v' => ['A1' => ['n' => 'Fraldas M', 'u' => 'CX', 'q' => 6.0, 't' => 6000.0]], 'mov' => ['A1' => ['c' => 0.0, 'd' => 0.0]]];
    naval_json_gravar(naval_carr_pasta($ontem) . '/_dados.json', ['data' => $ontem, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => 'separados', 'precos' => ['A1' => 1000.0],
        'rotas' => ((array) ($dados['rotas'] ?? [])) + [$rota => $base]]);
    $f = naval_carr_fechos_pendentes($rota);
    igual([['data' => $ontem, 'estado' => 'por_contar']], $f['pendentes']);
    $r = naval_req_criar($rota, date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 5]], 'pro');
    $r = naval_req_emitir($r['numero'], $r['versao'], 'pro');
    naval_req_validar_quantidades($r['numero'], [], '', 'teresa.gomes');
    naval_req_aprovar($r['numero'], 'sup');
    $e = lanca(409, function () use ($r) { naval_req_confirmar($r['numero'], [], 'arm'); });
    verdade(strpos($e->getMessage(), 'ainda não tem o fecho de ' . naval_data_pt($ontem) . ' (por contar)') !== false, $e->getMessage());
    lanca(400, function () use ($r) { naval_req_autorizar_carga($r['numero'], 'curto', 'resp'); });
    $a = naval_req_autorizar_carga($r['numero'], 'Rota com clientes à espera; conciliação ao fim da manhã', 'resp');
    igual([$ontem], $a['excepcao_fecho']['dias']);
    lanca(403, function () use ($r) { naval_req_confirmar($r['numero'], [], 'resp'); });   // quem autorizou não confirma
    $c = naval_req_confirmar($r['numero'], ['A1' => 5], 'arm', ['A1' => 'falta_armazem']);
    igual('carregada', $c['estado']);
    // a carga está na carrinha antes da contagem de ontem: conta-se, mas não entra na conciliação
    $reg = naval_carr_ler($ontem, $rota);
    igual([$r['numero']], array_column($reg['cargas_posteriores'], 'numero'));
    $res = naval_carr_contar($ontem, $rota, ['A1' => 9], ['login' => 'tec', 'nome' => 'Técnico', 'email' => '']);
    igual(0, $res['resumo']['diferencas'], '4 esperados + 5 da carga posterior = 9 contados');
    igual(5.0, $res['linhas'][0]['posterior']);
    // fechada a conciliação de ontem (sem diferenças fecha sozinha), a carga seguinte já não precisa de autorização
    naval_carr_validar($ontem, $rota, ['login' => 'tec', 'nome' => 'Técnico', 'email' => '']);
    igual([], naval_carr_fechos_pendentes($rota)['pendentes']);
    // sem base de dados nem fotografia de um dia: não se consegue verificar — conta como por fechar (uma só vez)
    naval_json_gravar($regra, ['desde' => date('Y-m-d', time() - 5 * 86400)]);
    $sem = array_values(array_filter(naval_carr_fechos_pendentes($rota)['pendentes'], function ($p) { return $p['estado'] === 'por_verificar'; }));
    verdade(count($sem) <= 1, 'uma só entrada por verificar');
    @unlink(naval_carr_pasta($ontem) . '/' . $rota . '.json');
    if ($dados) naval_json_gravar(naval_carr_pasta($ontem) . '/_dados.json', $dados); else @unlink(naval_carr_pasta($ontem) . '/_dados.json');
    if ($tinha === null) @unlink($regra); else file_put_contents($regra, $tinha);
});

teste('plano de cargas: a requisição emitida fixa o documento, as quantidades e o armazém de carga (o ecrã não os muda)', function () {
    naval_servico('cargas');
    $GLOBALS['naval_req_armazens_teste'] = ['F1' => 'RTM HIGITEC PIV – NON FOOD', 'S1' => 'RTM REFITEC/FOODTEC PIV – FOOD'];
    $hoje = date('Y-m-d');
    $r = naval_req_criar('1017', $hoje, [['codigo' => 'F1', 'artigo' => 'Fraldas M', 'quantidade' => 12], ['codigo' => 'S1', 'artigo' => 'Sabão azul', 'quantidade' => 30]], 'pro');
    $rasc = naval_plano_carga($hoje, ['artigos' => NAVAL_ARTIGOS_PADRAO], ['1017']);
    igual([[$r['numero']], []], [$rasc['rotas']['1017']['rascunhos'], $rasc['rotas']['1017']['requisicoes']], 'rascunho: avisa, não carrega');
    igual([0.0, 12.0], [$rasc['rotas']['1017']['grupos']['fraldas']['carregar'], $rasc['rotas']['1017']['grupos']['fraldas']['por_emitir']], 'rascunho: mostrado como por emitir');
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro');
    igual(['F1' => 'RTM HIGITEC PIV – NON FOOD', 'S1' => 'RTM REFITEC/FOODTEC PIV – FOOD'], array_column($e['linhas'], 'armazem', 'codigo'), 'armazém gravado na emissão');
    $listas = ['artigos' => NAVAL_ARTIGOS_PADRAO];
    $ecra = ['data' => $hoje, 'rotas' => [['rota' => '1017', 'factura' => 'X', 'armazem' => 'HIGITEC', 'quantidades' => ['fraldas' => 999, 'sabao' => 1, 'sabonete' => 5]]]];
    // existências na carrinha (contadas) + requisitado = total à saída, seja o que for que venha do ecrã
    $base = naval_plano_carga($hoje, $listas, ['1017'])['rotas']['1017'];
    $comNa = $base;
    foreach (['fraldas' => 7, 'sabao' => 0, 'sabonete' => 3] as $g => $na) $comNa['grupos'][$g]['na'] = $na;
    $cg = ['rotas' => ['1017' => ['stock' => 'contado'] + $comNa]];
    $p = naval_plano_com_requisicoes($ecra, $listas, $cg);
    $x = $p['rotas'][0];
    igual($e['numero'], $x['factura']);
    igual(['fraldas' => 19, 'sabao' => 30, 'sabonete' => 3], $x['quantidades'], 'existências + requisição, não o ecrã');
    verdade($x['carga_total'], 'o limite de peso não soma as existências outra vez');
    igual(0.0, naval_carga_estado($p['rotas'], $listas + ['capacidades' => []], ['1017' => 500.0])['1017']['na_carrinha']);
    // sem Pinguim BO (existências desconhecidas): o do ecrã, nunca abaixo do requisitado
    $sem = naval_plano_com_requisicoes($ecra, $listas, ['rotas' => ['1017' => ['stock' => 'sem_dados'] + $base]]);
    igual(['fraldas' => 999, 'sabao' => 30, 'sabonete' => 5], $sem['rotas'][0]['quantidades']);
    igual('RTM HIGITEC PIV – NON FOOD + RTM REFITEC/FOODTEC PIV – FOOD', $x['armazem']);
    $c = naval_plano_carga($hoje, $listas, ['1017']);
    igual(['RTM HIGITEC PIV – NON FOOD', 'RTM REFITEC/FOODTEC PIV – FOOD'], $c['rotas']['1017']['armazens']);
    // sem PO mas com o stock da carrinha conhecido: a carga é só o que já está na carrinha (o ecrã não a muda)
    $soNa = naval_plano_com_requisicoes(['data' => $hoje, 'rotas' => [['rota' => '1018', 'factura' => '', 'armazem' => 'HIGITEC', 'quantidades' => ['fraldas' => 50]]]], $listas,
        ['rotas' => ['1018' => ['stock' => 'contado', 'requisicoes' => [], 'armazens' => [], 'grupos' => ['fraldas' => ['na' => 4, 'carregar' => 0], 'sabao' => ['na' => 2, 'carregar' => 0]]]]]);
    igual(['fraldas' => 4, 'sabao' => 2, 'sabonete' => 0], $soNa['rotas'][0]['quantidades']);
    unset($GLOBALS['naval_req_armazens_teste']);
});

teste('plano de cargas: marca, modelo, transportadora e telefone vêm das tabelas (dados mestre), não do ecrã', function () {
    naval_servico('cargas');
    $listas = ['artigos' => NAVAL_ARTIGOS_PADRAO, 'frota' => [['matricula' => 'LD-58-73-GY', 'marca' => 'Mitsubishi', 'modelo' => 'Canter', 'transportadora' => 'Velotrans']],
        'motoristas' => [['nome' => 'Adão Paulo', 'telefone' => '923000111']]];
    $p = naval_plano_com_requisicoes(['data' => date('Y-m-d'), 'rotas' => [['rota' => '1001', 'motorista' => 'adão paulo', 'telefone' => '', 'matricula' => 'ld 58 73 gy',
        'viatura' => 'Outra', 'transportadora' => 'Qualquer', 'quantidades' => []]]], $listas, ['rotas' => []]);
    $r = $p['rotas'][0];
    igual(['Mitsubishi Canter', 'Velotrans', '923000111'], [$r['viatura'], $r['transportadora'], $r['telefone']]);
    // fora das tabelas: fica o que veio
    $q = naval_plano_dados_mestre(['rota' => '1002', 'motorista' => 'Outro', 'telefone' => '9', 'matricula' => 'XX-1', 'viatura' => 'V', 'transportadora' => 'T'], $listas);
    igual(['V', 'T', '9'], [$q['viatura'], $q['transportadora'], $q['telefone']]);
});

teste('PO: a reposição da contagem que ficou em rascunho volta a tentar a emissão automática', function () {
    $GLOBALS['naval_req_armazens_teste'] = ['F1' => 'RTM HIGITEC PIV – NON FOOD'];
    $r = naval_req_criar('1019', date('Y-m-d'), [['codigo' => 'F1', 'artigo' => 'Fraldas M', 'quantidade' => 3]], 'automatico', ['tipo' => 'conciliacao', 'data' => date('Y-m-d'), 'rota' => '1019']);
    $m = naval_req_criar('1019', date('Y-m-d'), [['codigo' => 'F1', 'artigo' => 'Fraldas M', 'quantidade' => 1]], 'pro');
    $e = naval_req_reemitir_reposicoes();
    verdade(in_array($r['numero'], $e, true), 'a reposição foi emitida');
    verdade(!in_array($m['numero'], $e, true), 'o rascunho manual fica para o vendedor');
    igual('rascunho', naval_req_ler($m['numero'])['estado']);
    unset($GLOBALS['naval_req_armazens_teste']);
});

teste('PO: fechar com motivo; as esquecidas fecham-se sozinhas e os rascunhos antigos anulam-se', function () {
    $r = naval_req_criar('1005', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 2]], 'pro');
    lanca(409, function () use ($r) { naval_req_fechar($r['numero'], 'não vai ser carregada', 'pro'); });   // rascunho: anula-se
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro');
    lanca(400, function () use ($e) { naval_req_fechar($e['numero'], 'x', 'pro'); });
    $f = naval_req_fechar($e['numero'], 'O promotor já não precisa', 'pro');
    igual(['fechada', 'pro', 'O promotor já não precisa'], [$f['estado'], $f['fechada_por'], $f['motivo_fecho']]);
    lanca(409, function () use ($e) { naval_req_confirmar($e['numero'], [], 'log'); });
    // esquecidas: emitida e rascunho de há 5 dias (a regra é 3)
    $dia = date('Y-m-d', time() - 5 * 86400);
    $velha = naval_req_emitir(($x = naval_req_criar('1006', $dia, [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 1]], 'pro'))['numero'], $x['versao'], 'pro');
    $rasc = naval_req_criar('1007', $dia, [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 1]], 'pro');
    $nova = naval_req_emitir(($y = naval_req_criar('1008', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 1]], 'pro'))['numero'], $y['versao'], 'pro');
    $o = naval_req_fechar_antigas();
    verdade(in_array($velha['numero'], $o['fechadas'], true) && in_array($rasc['numero'], $o['anuladas'], true), json_encode($o));
    igual(['fechada', 'anulada', 'emitida'], [naval_req_ler($velha['numero'])['estado'], naval_req_ler($rasc['numero'])['estado'], naval_req_ler($nova['numero'])['estado']]);
    igual('automatico', naval_req_ler($velha['numero'])['fechada_por']);
});

teste('PO: aprovação automática só dentro das regras; o resto vai ao coordenador com o motivo', function () {
    naval_servico('requisicoes');
    $po = function (array $linhas, string $tipo = 'conciliacao') {
        return ['origem' => ['tipo' => $tipo, 'data' => '2026-11-20', 'rota' => '1004'], 'linhas' => $linhas];
    };
    $vend = ['codigo' => 'A1', 'quantidade' => 10, 'sugerida' => 10, 'origem' => 'vendido'];
    $semDif = ['estado' => 'por_aprovar', 'historico' => [['accao' => 'validada', 'diferencas' => 0]]];
    $comDif = ['estado' => 'por_aprovar', 'historico' => [['accao' => 'validada', 'diferencas' => 3]]];
    igual(['ok' => true, 'motivos' => []], naval_req_regras_auto($po([$vend]), $semDif), 'reposição pelo vendido, conciliação sem diferenças');
    verdade(naval_req_regras_auto($po([$vend]), ['estado' => 'aprovada', 'historico' => [['accao' => 'validada', 'diferencas' => 2]]])['ok'], 'diferenças já aprovadas');
    $x = naval_req_regras_auto($po([$vend]), $comDif);
    igual(['a conciliação desse dia tem diferenças por aprovar'], $x['motivos']);
    $x = naval_req_regras_auto($po([['quantidade' => 12] + $vend]), $semDif);
    igual(['acima do vendido: A1'], $x['motivos']);
    $x = naval_req_regras_auto($po([$vend, ['codigo' => 'B2', 'quantidade' => 5, 'sugerida' => null, 'origem' => 'adicionado']]), $semDif);
    igual(['artigo(s) acrescentado(s) que não foram vendidos: B2'], $x['motivos']);
    $x = naval_req_regras_auto($po([['codigo' => 'A1', 'quantidade' => 4, 'sugerida' => null, 'origem' => 'adicionado']], 'manual'), null);
    igual(false, $x['ok'], 'pedido manual vai sempre ao coordenador');
    verdade(in_array('pedido manual (não vem da reposição pelo vendido)', $x['motivos'], true));
    verdade(naval_req_regras_auto($po([['quantidade' => 6] + $vend]), $semDif)['ok'], 'pedir menos do que o vendido (falta no armazém) também passa');
});

teste('PO: o controlo de stock ajusta as quantidades (com motivo) e valida; quem valida não aprova; a função não se junta à de aprovar', function () {
    naval_servico('requisicoes');
    $r = naval_req_criar('1013', date('Y-m-d'), [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 40], ['codigo' => 'B2', 'artigo' => 'Sabão', 'quantidade' => 10]], 'pro13');
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro13');
    verdade(naval_req_precisa_validacao($e));
    lanca(400, function () use ($e) { naval_req_validar_quantidades($e['numero'], ['A1' => 30], '', 'teresa.gomes'); });   // ajuste sem motivo
    $v = naval_req_validar_quantidades($e['numero'], ['A1' => 30, 'B2' => 10], 'Só há 30 no armazém', 'teresa.gomes');
    igual([30.0, 40.0], [$v['linhas'][0]['quantidade'], $v['linhas'][0]['pedida']]);
    igual([['codigo' => 'A1', 'de' => 40.0, 'para' => 30.0]], $v['ajuste_validacao']['alteracoes'], 'só o que mudou');
    igual('teresa.gomes', $v['validada_por']);
    lanca(409, function () use ($e) { naval_req_validar_quantidades($e['numero'], [], '', 'teresa.gomes'); });   // uma só vez
    verdade((bool) naval_funcoes_conflitos(['C-PO-VALIDAR', 'C-PO-APROVAR']), 'validar e aprovar são funções separadas');
});

teste('PO não satisfeita no dia: o que falta (com o motivo) e a taxa de serviço do armazém', function () {
    naval_servico('requisicoes');
    $emitida = ['numero' => 'PO-2026-000101', 'estado' => 'emitida', 'rota' => '1001', 'data' => '2026-11-27',
        'linhas' => [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 4]]];
    $parcial = ['numero' => 'PO-2026-000102', 'estado' => 'carregada', 'rota' => '1002', 'data' => '2026-11-27', 'linhas' => [
        ['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 10, 'entregue' => 6, 'motivo' => ['codigo' => 'falta_armazem', 'texto' => '']],
        ['codigo' => 'B2', 'artigo' => 'Sabão', 'quantidade' => 5, 'entregue' => 5]]];
    $l = naval_req_por_satisfazer($emitida);
    igual([['A1', 4.0, 'PO-2026-000101', 'Não carregada no dia', 'transitado']], array_map(function ($x) { return [$x['codigo'], $x['quantidade'], $x['transitado_de'], $x['motivo_origem'], $x['origem']]; }, $l));
    $l = naval_req_por_satisfazer($parcial);
    igual([['A1', 4.0, 'Falta no armazém']], array_map(function ($x) { return [$x['codigo'], $x['quantidade'], $x['motivo_origem']]; }, $l), 'só a diferença');
    igual([], naval_req_por_satisfazer(['estado' => 'rascunho'] + $emitida), 'rascunho não transita');
    $fechada = ['estado' => 'fechada', 'motivo_fecho' => 'Não carregada no dia: transitou para PO-2026-000110.', 'transitada_para' => 'PO-2026-000110'] + $emitida;
    $s = naval_req_servico([$fechada, $parcial, $emitida]);
    igual([19.0, 11.0, 0.5789, 8.0], [$s['resumo']['pedido'], $s['resumo']['carregado'], $s['resumo']['taxa'], $s['resumo']['em_falta']], 'a emitida ainda no dia não conta');
    igual(['A1', 8.0], [$s['por_artigo'][0]['codigo'], $s['por_artigo'][0]['em_falta']]);
    igual(['1001' => 0.0, '1002' => 0.7333], array_column($s['por_rota'], 'taxa', 'rota'));
    igual(['PO-2026-000101', 'PO-2026-000102'], array_column($s['nao_satisfeitas'], 'numero'));
    igual('PO-2026-000110', $s['nao_satisfeitas'][0]['transitada_para']);
});

teste('PO emitida e não carregada até ao fim da carga: fecha e transita para um rascunho do dia de trabalho seguinte (uma só vez)', function () {
    naval_servico('requisicoes');
    $hoje = date('Y-m-d');
    $r = naval_req_criar('1021', $hoje, [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'quantidade' => 3]], 'pro21');
    $e = naval_req_emitir($r['numero'], $r['versao'], 'pro21');
    igual([], array_intersect_key(naval_req_transitar(strtotime($hoje . ' 08:00')), ['1021' => 1]), 'antes da carga não mexe');
    $t = naval_req_transitar(strtotime($hoje . ' 23:30'));
    verdade(isset($t['1021']), json_encode($t));
    $o = naval_req_ler($e['numero']);
    igual(['fechada', $t['1021']], [$o['estado'], $o['transitada_para']]);
    $n = naval_req_ler($t['1021']);
    igual(['rascunho', 'transitada', naval_req_dia_seguinte($hoje)], [$n['estado'], $n['origem']['tipo'], $n['data']]);
    verdade($n['data'] > $hoje && !naval_cal_folga($n['data']), 'dia de trabalho seguinte');
    igual([['A1', 3.0, 'transitado', $e['numero'], 'Não carregada no dia']], array_map(function ($x) { return [$x['codigo'], (float) $x['quantidade'], $x['origem'], $x['transitado_de'], $x['motivo_origem']]; }, $n['linhas']));
    verdade(!isset(naval_req_transitar(strtotime($hoje . ' 23:45'))['1021']), 'não transita duas vezes');
});
