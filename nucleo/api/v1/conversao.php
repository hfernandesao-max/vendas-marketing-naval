<?php
/**
 * Conversão das visitas (nucleo/servicos/conversao.php): plano do dia congelado × compras do Pinguim BO × visitas do
 * OLAP. Recurso «conversao-visitas» (Vr: só as suas rotas).
 *
 * GET v1/conversao?de=AAAA-MM-DD&ate=AAAA-MM-DD   (até 62 dias)
 */
defined('NAVAL_API') || exit;
naval_servico('conversao');
naval_servico('vendas');
naval_servico('rotas');
naval_exigir(['conversao-visitas']);
@set_time_limit(180);
$de = (string) ($_GET['de'] ?? date('Y-m-01'));
$ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
if (!naval_data_valida($de) || !naval_data_valida($ate)) throw new NavalErro('Datas inválidas.', 400);
$r = naval_conversao($de, $ate, naval_rotas());
// o valor das vendas só para quem vê as páginas de vendas
if (!naval_ve_vendas()) {
    foreach ($r['rotas'] as &$x) unset($x['valor_planeados'], $x['valor_fora']);
    unset($x, $r['total']['valor_planeados'], $r['total']['valor_fora']);
}
naval_json(['ok' => true] + $r);
