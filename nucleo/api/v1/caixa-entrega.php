<?php
/**
 * Entrega do dinheiro pelo vendedor (nucleo/servicos/caixa-entregas.php), na app «Naval Operações».
 * Recurso «caixa-entrega» G (o vendedor, nas suas rotas).
 *
 * GET  v1/caixa-entrega?dia=AAAA-MM-DD     rotas da pessoa: o vendido em dinheiro no Pinguim BO, a declaração e o estado
 * POST {accao: declarar, dia, rota, notas: {nota: quantidade, moedas: valor}, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('caixa-entregas');
naval_servico('rotas');
naval_exigir(['caixa-entrega']);
$u = (array) naval_utilizador();
$rotasConta = naval_rotas();
$promotores = naval_promotores();
$minhas = $rotasConta === null ? array_map('strval', array_keys($promotores)) : $rotasConta;

if (!naval_e_post()) {
    $dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
    if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    $esperado = null;
    try {
        naval_servico('relatorio-vendas');
        $esperado = naval_caixa_esperado($dia);
    } catch (Throwable $e) {
        error_log('[entrega de dinheiro] ' . $e->getMessage());
    }
    $caixa = naval_caixa_ler($dia);
    $dec = naval_cxe_ler($dia, $minhas);
    $rotas = [];
    foreach ($minhas as $r) {
        $rotas[] = ['rota' => $r, 'promotor' => $promotores[$r] ?? $r, 'vendido_dinheiro' => $esperado !== null ? round((float) ($esperado[$r]['dinheiro'] ?? 0), 2) : null,
            'declaracao' => $dec[$r] ?? null, 'contada' => naval_cxe_registada($caixa, $r)];
    }
    naval_json(['ok' => true, 'dia' => $dia, 'notas' => naval_caixa_notas(), 'folha' => $caixa['estado'] ?? 'aberta', 'rotas' => $rotas]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!naval_pode('caixa-entrega', 'G')) throw new NavalErro('O seu perfil não permite registar entregas de dinheiro.', 403);
if ((string) ($p['accao'] ?? '') !== 'declarar') throw new NavalErro('Acção desconhecida.', 400);
$rota = (string) ($p['rota'] ?? '');
if (!in_array($rota, $minhas, true)) throw new NavalErro('Rota fora do seu âmbito.', 403);
$dia = (string) ($p['dia'] ?? date('Y-m-d'));
naval_json(['ok' => true, 'declaracao' => naval_cxe_declarar($dia, $rota, $p['notas'] ?? null, (string) ($u['login'] ?? ''))]);
