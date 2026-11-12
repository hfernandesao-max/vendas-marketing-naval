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
