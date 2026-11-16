<?php
/**
 * Multicaixa por TPA (nucleo/servicos/tpa.php). Recurso «multicaixa-tpa»: V ver o relatório; G também registar os
 * terminais, marcar «Conferido no extracto» e escolher quem recebe o resumo de fim de dia.
 *
 * GET  v1/multicaixa-tpa?de=AAAA-MM-DD&ate=AAAA-MM-DD     relatório do período (até 62 dias) e registo dos terminais
 * POST {accao: terminal, tpa, estab, empresa, banco, conta, rota, activo, nota, csrf}
 * POST {accao: conferir | retirar_conferencia, dia, tpa, periodo, ref, data, csrf}
 * POST {accao: destinatarios, destinatarios: [email…], csrf}
 */
defined('NAVAL_API') || exit;
naval_exigir(['multicaixa-tpa']);
naval_servico('caixa');
naval_servico('rotas');
naval_servico('tpa');
$gere = naval_pode('multicaixa-tpa', 'G');
$login = (string) (((array) naval_utilizador())['login'] ?? '');
$base = function () use ($gere) {
    return ['ok' => true, 'gere' => $gere, 'terminais' => array_values(naval_tpa_terminais()), 'empresas' => NAVAL_CAIXA_EMPRESAS,
        'bancos' => NAVAL_CAIXA_BANCOS, 'promotores' => (object) naval_promotores(),
        'destinatarios' => $gere ? (array) (naval_tpa_config()['destinatarios'] ?? []) : null];
};

if (!naval_e_post()) {
    $ate = (string) ($_GET['ate'] ?? date('Y-m-d', time() - 86400));
    $de = (string) ($_GET['de'] ?? $ate);
    naval_json($base() + ['relatorio' => naval_tpa_relatorio($de, $ate)]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!$gere) throw new NavalErro('O seu perfil só permite ver o relatório.', 403);
switch ((string) ($p['accao'] ?? '')) {
    case 'terminal':
        naval_tpa_gravar($p, $login);
        naval_json($base());
    case 'conferir':
    case 'retirar_conferencia':
        naval_tpa_conferir((string) ($p['dia'] ?? ''), (string) ($p['tpa'] ?? ''), ltrim((string) ($p['periodo'] ?? ''), '0') ?: '0',
            ($p['accao'] ?? '') === 'conferir', (string) ($p['ref'] ?? ''), (string) ($p['data'] ?? ''), $login);
        naval_json(['ok' => true]);
    case 'destinatarios':
        naval_json($base() + ['destinatarios' => naval_tpa_destinatarios((array) ($p['destinatarios'] ?? []), $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
