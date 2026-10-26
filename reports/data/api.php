<?php
/**
 * Vendas do Pinguim BO → JSON (formato de vendas.json), com o âmbito do perfil.
 *
 * GET ?start_date=AAAAMMDD&end_date=AAAAMMDD[&include_items=1]   vendas (e linhas de artigos)
 * GET ?ping=1                                 estado da ligação ao Pinguim BO (indicador do portal)
 * GET ?diag=1                                 diagnóstico do servidor (super administrador)
 * GET ?estrutura=1&start_date=AAAAMMDD        secções e campos da exportação, sem dados pessoais (super administrador)
 *
 * Em caso de falha devolve sempre JSON {ok:false, erro, detalhe} com o código HTTP adequado.
 */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('pinguim');
naval_servico('vendas');
naval_servico('rotas');

// Consultas ao Pinguim BO podem demorar; no Windows o limite do PHP conta tempo real
@set_time_limit(180);
@ini_set('memory_limit', '512M');
naval_pinguim_requisitos();

if (($_GET['ping'] ?? '') === '1') naval_json(naval_pinguim_estado());

naval_exigir(array_merge(['inicio', 'vendas-artigo'], NAVAL_PAGINAS_VENDAS));
$dataAaaammdd = function (string $param, string $omissao) {
    return (string) preg_replace('/\D/', '', (string) ($_GET[$param] ?? $omissao));
};

if (($_GET['estrutura'] ?? '') === '1') {
    naval_exigir(['admin-config']);
    $de = $dataAaaammdd('start_date', date('Ymd'));
    $ate = $dataAaaammdd('end_date', $de);
    $bytes = 0;
    $xml = naval_pinguim_xml($de, $ate, 120, $bytes);
    naval_json(['periodo' => "$de a $ate"] + naval_pinguim_estrutura($xml, $bytes));
}
if (($_GET['diag'] ?? '') === '1') {
    naval_exigir(['admin-config']);
    naval_json(naval_pinguim_diagnostico());
}

$de = (string) ($_GET['start_date'] ?? '');
$ate = (string) ($_GET['end_date'] ?? '');
$itens = ($_GET['include_items'] ?? '') === '1';
if (!preg_match('/^\d{8}$/', $de) || !preg_match('/^\d{8}$/', $ate)) {
    throw new NavalErro('Datas inválidas.', 400, ['detalhe' => 'Use start_date e end_date no formato AAAAMMDD.']);
}

$d = naval_vendas_do_xml(naval_pinguim_xml($de, $ate), naval_promotores(), $itens);
$d = naval_vendas_ambito($d, naval_rotas(), naval_ve_nif(), naval_ve_vendas());
naval_json($itens ? $d : $d['vendas']);
