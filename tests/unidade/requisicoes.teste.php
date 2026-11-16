<?php
// Requisições de reposição da carrinha ao armazém (nucleo/servicos/requisicoes.php).
naval_servico('carrinha');

teste('conciliação validada cria a requisição com o vendido (uma só por rota e dia, nunca duplicada)', function () {
    $data = date('Y-m-d', time() - 3 * 86400);
    $linhas = [['codigo' => 'A1', 'artigo' => 'Fraldas M', 'unidade' => 'CX', 'vendido' => 26.0], ['codigo' => 'B2', 'artigo' => 'Sabão', 'unidade' => 'UN', 'vendido' => 0.0]];
    $r = naval_req_da_conciliacao($data, '1001', $linhas, 'tec');
    verdade((bool) preg_match('/^RQ-\d{4}-\d{6}$/', $r['numero']), 'número sequencial do ano');
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
    lanca(403, function () use ($e) { naval_req_confirmar($e['numero'], [], 'pro'); }); // quem emitiu não confirma
    $c = naval_req_confirmar($e['numero'], ['C3' => 1], 'log');
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
    $e = lanca(409, function () use ($r) { naval_req_confirmar($r['numero'], [], 'arm'); });
    verdade(strpos($e->getMessage(), 'ainda não tem o fecho de ' . naval_data_pt($ontem) . ' (por contar)') !== false, $e->getMessage());
    lanca(400, function () use ($r) { naval_req_autorizar_carga($r['numero'], 'curto', 'resp'); });
    $a = naval_req_autorizar_carga($r['numero'], 'Rota com clientes à espera; conciliação ao fim da manhã', 'resp');
    igual([$ontem], $a['excepcao_fecho']['dias']);
    lanca(403, function () use ($r) { naval_req_confirmar($r['numero'], [], 'resp'); });   // quem autorizou não confirma
    $c = naval_req_confirmar($r['numero'], ['A1' => 5], 'arm');
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
