<?php
/**
 * Folha de caixa diária do Marketing (nucleo/servicos/caixa.php). Recurso «caixa-marketing»: V ver, G registar,
 * fechar, aprovar e reabrir (a aprovação por outra pessoa que não quem registou ou fechou).
 *
 * GET  v1/caixa?dia=AAAA-MM-DD
 * POST {accao: gravar, dia, versao, saldo_inicial?, entregas: [{rota, empresa, valor, nota}], tpa: [{rota, banco, empresa, valor}],
 *       saidas: [{tipo, empresa, descricao, valor}], csrf}
 * POST {accao: fechar, dia, versao, contagem, explicacao, csrf}
 * POST {accao: aprovar, dia, versao, csrf}
 * POST {accao: reabrir, dia, versao, motivo, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('caixa');
naval_servico('rotas');
naval_exigir(['caixa-marketing']);
$gere = naval_pode('caixa-marketing', 'G');

if (!naval_e_post()) {
    $dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
    if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    naval_json(['ok' => true, 'gere' => $gere, 'caixa' => naval_caixa_ler($dia), 'promotores' => (object) naval_promotores(),
        'saidas' => NAVAL_CAIXA_SAIDAS, 'bancos' => NAVAL_CAIXA_BANCOS, 'empresas' => NAVAL_CAIXA_EMPRESAS]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!$gere) throw new NavalErro('O seu perfil não permite registar a caixa.', 403);
$dia = (string) ($p['dia'] ?? '');
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');
$versao = isset($p['versao']) && is_numeric($p['versao']) ? (int) $p['versao'] : null;
switch ((string) ($p['accao'] ?? '')) {
    case 'gravar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_gravar($dia, $p, $versao, $login)]);
    case 'fechar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_fechar($dia, $p['contagem'] ?? null, (string) ($p['explicacao'] ?? ''), $versao, $login)]);
    case 'aprovar':
        naval_json(['ok' => true, 'caixa' => naval_caixa_aprovar($dia, $versao, $login)]);
    case 'reabrir':
        naval_json(['ok' => true, 'caixa' => naval_caixa_reabrir($dia, (string) ($p['motivo'] ?? ''), $versao, $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
