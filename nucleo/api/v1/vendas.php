<?php
/**
 * Vendas do Pinguim BO → JSON (formato de vendas.json), com o âmbito do perfil.
 *
 * GET v1/vendas?start_date=AAAAMMDD&end_date=AAAAMMDD[&include_items=1]   vendas (e linhas de artigos)
 * GET v1/vendas/estado                       estado da ligação ao Pinguim BO (indicador do portal)
 * GET v1/vendas/diagnostico                  diagnóstico do servidor (super administrador)
 * GET v1/vendas/estrutura?start_date=AAAAMMDD secções e campos da exportação, sem dados pessoais (super administrador)
 *
 * Em caso de falha devolve sempre JSON {ok:false, erro, detalhe} com o código HTTP adequado.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
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
naval_validar_periodo($de, $ate);

// Cache no servidor (fase 4): o mesmo período não volta a ser consultado durante 2 min (se inclui hoje)
// ou 30 min (período fechado); «fresco=1» (botão Actualizar) consulta de novo. O âmbito do perfil
// (rotas, NIF) aplica-se depois, a cada pedido.
$chave = 'vendas|' . $de . '|' . $ate . '|' . ($itens ? 1 : 0) . '|' . hash('sha256', (string) json_encode(naval_promotores()));
$d = naval_cache($chave, naval_cache_periodo($ate), function () use ($de, $ate, $itens) {
    $d = naval_vendas_do_xml(naval_pinguim_xml($de, $ate), naval_promotores(), $itens);
    return $d;
}, ($_GET['fresco'] ?? '') === '1');
$d = naval_vendas_ambito($d, naval_rotas(), naval_ve_nif(), naval_ve_vendas());
naval_json($itens ? $d : $d['vendas']);
