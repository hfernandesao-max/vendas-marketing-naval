<?php
/**
 * Carteira de clientes (nucleo/servicos/carteira.php), calculada todas as noites a partir do Pinguim BO.
 *
 * GET v1/carteira?vista=visitas&dia=AAAA-MM-DD    visitas do dia por rota com a ficha de preparação   recurso «visitas-dia» (Vr: só as suas rotas)
 * GET v1/carteira?vista=qualidade                  anomalias de vendas e qualidade dos dados            recurso «qualidade-dados»
 */
defined('NAVAL_API') || exit;
naval_servico('carteira');
$vista = (string) ($_GET['vista'] ?? 'visitas');
if (!in_array($vista, ['visitas', 'qualidade'], true)) throw new NavalErro('Vista desconhecida.', 400);
naval_exigir([$vista === 'visitas' ? 'visitas-dia' : 'qualidade-dados']);
$dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
$v = naval_cart_vista($dia, naval_rotas());
// o plano do dia fica congelado na primeira leitura (se o cálculo da manhã ainda não o guardou)
if ($vista === 'visitas' && $dia === date('Y-m-d')) {
    try {
        naval_servico('conversao');
        naval_conv_plano($dia);
    } catch (Throwable $e) {
        error_log('[plano de visitas] ' . $e->getMessage());
    }
}
if ($vista === 'visitas') {
    unset($v['anomalias'], $v['qualidade']);
    // o risco de crédito e os valores em aberto só para quem vê os pendentes
    if (!naval_pode('pendentes')) foreach ($v['visitas'] as &$l) foreach ($l as &$c) unset($c['risco']);
    unset($l, $c);
} else {
    unset($v['visitas']);
}
naval_json(['ok' => true] + $v);
