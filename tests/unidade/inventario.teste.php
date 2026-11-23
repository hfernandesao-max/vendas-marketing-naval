<?php
// Inventário dos armazéns (nucleo/servicos/inventario.php): contagem às cegas, recontagem, motivos e segregação.
naval_servico('inventario');

teste('inventário: contagem às cegas, terminar só com tudo contado, validar com motivos (não quem contou), aprovar (terceira pessoa)', function () {
    $stock = ['nome' => 'Armazém Central', 'itens' => ['A1' => ['artigo' => 'Fraldas M', 'q' => 100.0], 'B2' => ['artigo' => 'Sabão verde', 'q' => 40.0], 'C3' => ['artigo' => 'Lixívia', 'q' => 10.0]]];
    $inv = naval_inv_criar('1150', 'tec', $stock);
    igual(['em_contagem', 'Armazém Central', 3], [$inv['estado'], $inv['armazem_nome'], count((array) $inv['base'])]);
    // um só inventário aberto por armazém
    lanca(409, function () use ($stock) { naval_inv_criar('1150', 'tec', $stock); });
    // às cegas: quem conta não vê o sistema
    $l = naval_inv_linhas($inv, true);
    igual(false, array_key_exists('sistema', $l[0]));
    $n = $inv['numero'];
    // contagem parcial e artigo fora da lista (encontrado no armazém)
    $inv = naval_inv_contar($n, ['A1' => 98, 'B2' => 40], ['Z9' => 'Detergente em pó'], 'tec', false);
    igual('em_contagem', $inv['estado']);
    $x = lanca(409, function () use ($n) { naval_inv_contar($n, [], [], 'tec', true); });
    igual(['Z9', 'C3'], $x->extra()['em_falta'], 'pela ordem do nome do artigo');
    $inv = naval_inv_contar($n, ['C3' => 10, 'Z9' => 5], [], 'tec', true);
    igual(['por_validar', 'tec'], [$inv['estado'], $inv['contado_por']]);
    igual(['artigos' => 4, 'contados' => 4, 'diferencas' => 2, 'faltas' => 2.0, 'sobras' => 5.0], naval_inv_resumo($inv));
    // quem contou não valida; a validação pede o motivo de cada diferença
    lanca(403, function () use ($n) { naval_inv_validar($n, 'tec', [], []); });
    lanca(400, function () use ($n) { naval_inv_validar($n, 'ctrl', ['A1' => ['motivo' => 'avaria']], []); });
    // recontagem só dos artigos duvidosos: volta à contagem, sem o número anterior desses artigos
    $inv = naval_inv_validar($n, 'ctrl', [], ['A1']);
    igual(['em_contagem', ['A1']], [$inv['estado'], $inv['recontar']]);
    igual([null, true], [array_column(naval_inv_linhas($inv, true), null, 'codigo')['A1']['contado'], array_column(naval_inv_linhas($inv, true), null, 'codigo')['A1']['recontar']]);
    $inv = naval_inv_contar($n, ['A1' => 99], [], 'tec', true);
    $inv = naval_inv_validar($n, 'ctrl', ['A1' => ['motivo' => 'avaria'], 'Z9' => ['motivo' => 'movimento_nao_registado', 'nota' => 'GTC por lançar']], []);
    igual(['por_aprovar', 'ctrl'], [$inv['estado'], $inv['validado_por']]);
    // quem contou ou validou não aprova
    lanca(403, function () use ($n) { naval_inv_aprovar($n, 'tec'); });
    lanca(403, function () use ($n) { naval_inv_aprovar($n, 'ctrl'); });
    $inv = naval_inv_aprovar($n, 'resp');
    igual(['aprovado', 'resp'], [$inv['estado'], $inv['aprovado_por']]);
    igual(['criado', 'gravado', 'contado', 'recontagem_pedida', 'contado', 'validado', 'aprovado'], array_column($inv['historico'], 'accao'));
    // fechado: já não se anula; um novo inventário do mesmo armazém já pode começar
    lanca(409, function () use ($n) { naval_inv_anular($n, 'ctrl', 'Contagem repetida'); });
    $novo = naval_inv_criar('1150', 'tec', $stock);
    igual(true, $novo['numero'] !== $n);
    igual(true, in_array($n, array_column(naval_inv_lista(), 'numero'), true));
});
