<?php
/**
 * Perdas e tempo parado por rota (nucleo/servicos/perdas.php), das bases de dados: Pinguim BO (vendas, notas de
 * crédito, descontos, anulados), conciliação das carrinhas, entrega do dinheiro e OLAP (tempo parado).
 * Recurso «perdas» (Vr: só as suas rotas).
 *
 * GET v1/perdas?de=AAAA-MM-DD&ate=AAAA-MM-DD[&actualizar=1]    até 31 dias; guardado 10 minutos
 */
defined('NAVAL_API') || exit;
naval_servico('perdas');
naval_exigir(['perdas']);
@set_time_limit(180);
$de = (string) ($_GET['de'] ?? date('Y-m-01'));
$ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
if ($ate > date('Y-m-d')) $ate = date('Y-m-d');
$rotas = naval_rotas();
$chave = hash('sha256', $de . '|' . $ate . '|' . json_encode($rotas));
$f = naval_pasta('perdas') . '/' . substr($chave, 0, 24) . '.json';
$c = naval_json_ler($f);
if ($c && ($_GET['actualizar'] ?? '') !== '1' && (int) ($c['t'] ?? 0) > time() - 600) naval_json(['ok' => true, 'cache' => true] + (array) $c['v']);
$v = naval_perdas_vista($de, $ate, $rotas);
naval_json_gravar($f, ['t' => time(), 'v' => $v], false);
naval_json(['ok' => true] + $v);
