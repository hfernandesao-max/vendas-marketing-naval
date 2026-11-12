<?php
/**
 * Folha de caixa diária do Marketing (nucleo/servicos/caixa.php). Recurso «caixa-marketing»: V ver, G registar,
 * fechar e reabrir. Aprovar: recurso «caixa-aprovacao» G, o superior hierárquico de quem regista (nunca quem registou
 * ou fechou). Quem aprova também pode reabrir uma caixa fechada para correcção.
 *
 * GET  v1/caixa?dia=AAAA-MM-DD
 * POST {accao: gravar, dia, versao, saldo_inicial?, entregas: [{rota, empresa, valor, notas?: {nota: quantidade, moedas}, nota}], tpa: [{rota, banco, empresa, valor}],
 *       saidas: [{tipo, empresa, descricao, valor}], csrf}
 * POST {accao: fechar, dia, versao, contagem, notas?: {5000: n, …, moedas: valor}, explicacao, csrf}
 * POST {accao: aprovar, dia, versao, csrf}
 * POST {accao: reabrir, dia, versao, motivo, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('caixa');
naval_servico('rotas');
naval_exigir(['caixa-marketing']);
$gere = naval_pode('caixa-marketing', 'G');
$aprova = naval_pode('caixa-aprovacao', 'G');

if (!naval_e_post()) {
    $dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
    if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    // o que cada promotor recebeu no Pinguim BO (dinheiro e Multicaixa), para conferir as entregas
    $esperado = null;
    try {
        naval_servico('relatorio-vendas');
        $esperado = naval_caixa_esperado($dia);
    } catch (Throwable $e) {
        error_log('[caixa esperado] ' . $e->getMessage());
    }
    naval_json(['ok' => true, 'gere' => $gere, 'aprova' => $aprova, 'caixa' => naval_caixa_ler($dia), 'esperado' => $esperado === null ? null : (object) $esperado,
        'promotores' => (object) naval_promotores(), 'notas' => naval_caixa_notas(),
        'saidas' => NAVAL_CAIXA_SAIDAS, 'bancos' => NAVAL_CAIXA_BANCOS, 'empresas' => NAVAL_CAIXA_EMPRESAS]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$dia = (string) ($p['dia'] ?? '');
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');
$versao = isset($p['versao']) && is_numeric($p['versao']) ? (int) $p['versao'] : null;
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'aprovar' && !$aprova) throw new NavalErro('A caixa é aprovada pelo superior hierárquico de quem a regista.', 403);
if ($accao === 'reabrir' && !$gere && !$aprova) throw new NavalErro('O seu perfil não permite reabrir a caixa.', 403);
if (in_array($accao, ['gravar', 'fechar'], true) && !$gere) throw new NavalErro('O seu perfil não permite registar a caixa.', 403);
switch ($accao) {
    case 'gravar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_gravar($dia, $p, $versao, $login)]);
    case 'fechar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_fechar($dia, $p['contagem'] ?? null, (string) ($p['explicacao'] ?? ''), $versao, $login, $p['notas'] ?? null)]);
    case 'aprovar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_aprovar($dia, $versao, $login)]);
    case 'reabrir':
        naval_json(['ok' => true, 'caixa' => naval_caixa_reabrir($dia, (string) ($p['motivo'] ?? ''), $versao, $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
