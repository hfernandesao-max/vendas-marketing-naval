<?php
// Substituição de uma factura de um dia anterior (nucleo/servicos/vendas.php, naval_vendas_substituicoes): nota de
// crédito + factura nova ao mesmo cliente no mesmo dia. Sem venda nova nem dinheiro; o ficheiro para o SAP não muda.
naval_servico('vendas');
naval_servico('analise');

function sb_doc(string $doc, string $hora, float $total, bool $nc, array $pags, string $cli = 'C100', ?float $si = null): array
{
    return ['chave' => $doc, 'documento' => $doc, 'data' => '2026-11-13', 'hora' => $hora, 'rota' => '1008', 'promotor' => 'Daniel', 'nc' => $nc,
        'cliente' => 'Cliente', 'cliente_codigo' => $cli, 'nif' => '5000000000', 'liquidado' => true, 'total' => $total,
        'total_sem_iva' => $si ?? round($total / 1.14, 2), 'valor_pago' => $nc ? 0.0 : $total, 'pagamentos' => $pags, 'meio_pagamento' => ''];
}

teste('substituição: nota de crédito de uma factura anterior e factura nova no mesmo dia — sem venda nem dinheiro', function () {
    $v = naval_vendas_substituicoes(naval_vendas_devolucoes([
        sb_doc('NC 1008/2026/3', '10:00', -1500000, true, [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => -1500000.0]]),
        sb_doc('FR 1008/2026/90', '10:05', 1480000, false, [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 1480000.0]]),
        sb_doc('FR 1008/2026/91', '11:00', 50000, false, [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 50000.0]], 'C200'),
    ]));
    igual(['nota' => 'NC 1008/2026/3', 'factura' => 'FR 1008/2026/90', 'desconto' => 20000.0, 'desconto_sem_iva' => 17543.86], $v[0]['substituicao']);
    igual($v[0]['substituicao'], $v[1]['substituicao']);
    verdade(empty($v[2]['substituicao']), 'outra venda não é tocada');
    // meios de cobrança: tudo compensação (fora do dinheiro e do Multicaixa); o documento fica para o SAP
    igual(['CMP', 'CMP'], array_column($v[0]['pagamentos'], 'codigo'));
    igual(-1500000.0, array_sum(array_column($v[0]['pagamentos'], 'valor')));
    igual([['CMP', 1480000.0]], array_map(function ($p) { return [$p['codigo'], $p['valor']]; }, $v[1]['pagamentos']));
    igual([['MC', -1500000.0]], array_map(function ($p) { return [$p['codigo'], $p['valor']]; }, $v[0]['pagamentos_origem']));
    igual([['MC', 1480000.0]], array_map(function ($p) { return [$p['codigo'], $p['valor']]; }, $v[1]['pagamentos_origem']));
    // agregados (indicadores, app, previsão, objectivos): só a venda verdadeira do dia
    $r = naval_analise_resumir($v, [['rota' => '1008', 'codigo' => 'A1', 'documento' => 'FR 1008/2026/90', 'quantidade' => 1, 'total' => 1480000, 'valor_sem_iva' => 1298245.61]]);
    igual(1, $r['rotas']['1008']['d']);
    igual(0, $r['rotas']['1008']['nc']);
    igual(50000.0, $r['rotas']['1008']['ti']);
    igual([], $r['artigos']['1008'] ?? []);
});

teste('substituição: anulação de uma factura do próprio dia e consumidor final não são substituições', function () {
    // factura de hoje anulada por nota de crédito do mesmo valor (emitida depois) e refeita: anulação normal
    $v = naval_vendas_substituicoes([
        sb_doc('FR 1', '09:00', 100000, false, [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 100000.0]]),
        sb_doc('NC 1', '09:30', -100000, true, [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => -100000.0]]),
        sb_doc('FR 2', '09:40', 90000, false, [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 90000.0]]),
    ]);
    igual([false, false, false], array_map(function ($x) { return !empty($x['substituicao']); }, $v));
    // consumidor final (sem cliente identificado)
    $cf = function ($d) { $d['cliente_codigo'] = ''; $d['nif'] = '999999999'; return $d; };
    $v = naval_vendas_substituicoes([$cf(sb_doc('NC 2', '10:00', -50000, true, [])), $cf(sb_doc('FR 3', '10:05', 45000, false, []))]);
    igual([false, false], array_map(function ($x) { return !empty($x['substituicao']); }, $v));
    // factura nova muito abaixo do valor da nota (menos de metade): é outra venda
    $v = naval_vendas_substituicoes([sb_doc('NC 3', '10:00', -500000, true, []), sb_doc('FR 4', '10:05', 120000, false, [])]);
    igual([false, false], array_map(function ($x) { return !empty($x['substituicao']); }, $v));
});

teste('substituição: fecho do dia, dinheiro esperado na caixa e vendas efectivas alinhados com o portal', function () {
    naval_servico('operacao');
    naval_servico('relatorio-vendas');
    $v = naval_vendas_substituicoes(naval_vendas_devolucoes([
        sb_doc('NC 1008/2026/3', '10:00', -1500000, true, [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => -1500000.0]]),
        sb_doc('FR 1008/2026/90', '10:05', 1480000, false, [['codigo' => 'MC', 'meio' => 'Multicaixa', 'valor' => 1480000.0]]),
        sb_doc('FR 1008/2026/91', '11:00', 50000, false, [['codigo' => 'NU', 'meio' => 'Numerário', 'valor' => 50000.0]], 'C200'),
    ]));
    igual(['FR 1008/2026/91'], array_column(naval_vendas_efectivas($v), 'documento'));
    $f = naval_op_fecho(naval_vendas_efectivas($v));
    igual([1, 50000.0, 0.0, 50000.0], [$f['1008']['docs'], $f['1008']['vendas'], $f['1008']['nc'], $f['1008']['recebido']]);
    // a folha de caixa espera só o dinheiro que entrou (sem Multicaixa a negativo)
    igual(['documentos' => 1, 'dinheiro' => 50000.0, 'multicaixa' => 0.0], naval_caixa_esperado('2026-11-13', $v)['1008']);
});
