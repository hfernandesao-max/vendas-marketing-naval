<?php
/**
 * Multicaixa por TPA (nucleo/servicos/tpa.php). Recurso «multicaixa-tpa»: V ver o relatório; G também registar os
 * terminais, marcar «Conferido no extracto» e escolher quem recebe o resumo de fim de dia.
 *
 * GET  v1/multicaixa-tpa?de=AAAA-MM-DD&ate=AAAA-MM-DD     relatório do período (até 62 dias) e registo dos terminais
 * POST {accao: terminal, tpa, estab, empresa, banco, conta, rota, serie, situacao, defeito, nota, csrf}
 * POST {accao: associar, tpa, rota, csrf}   (associa o promotor ao terminal; fica a cadeia de custódia)
 * POST {accao: conferir | retirar_conferencia, dia, tpa, periodo, ref, data, csrf}
 * POST {accao: destinatarios, destinatarios: [email…], csrf}
 * GET  v1/multicaixa-tpa?formas=1&de=…&ate=…              formas de pagamento: tabela e reconciliação por dia e promotor
 * POST {accao: formas, codigos: {COD: {forma, banco}}, rotas: {rota: banco}, csrf}   (G)
 * GET  v1/multicaixa-tpa?formas_docs=1&dia=…&rota=…        documentos do dia e rota, para corrigir a forma de pagamento
 * POST {accao: corrigir, dia, rota, docs: [{documento, numerario, multicaixa}], justificacao, evidencia, csrf}
 *      (pedir a correcção: «caixa-marketing» G, a Tesouraria)
 * POST {accao: aprovar_correcao | recusar_correcao | anular_correcao, id, motivo, csrf}
 *      (decidir: «caixa-aprovacao» G, outra pessoa que não quem pediu)
 */
defined('NAVAL_API') || exit;
naval_exigir(['multicaixa-tpa']);
naval_servico('caixa');
naval_servico('rotas');
naval_servico('tpa');
naval_servico('formas-pagamento');
$gere = naval_pode('multicaixa-tpa', 'G');
$pedeCorr = naval_pode('caixa-marketing', 'G');
$aprovaCorr = naval_pode('caixa-aprovacao', 'G');
$login = (string) (((array) naval_utilizador())['login'] ?? '');
$base = function () use ($gere) {
    return ['ok' => true, 'gere' => $gere, 'terminais' => array_values(naval_tpa_terminais()), 'situacoes' => NAVAL_TPA_SITUACOES, 'empresas' => NAVAL_CAIXA_EMPRESAS,
        'bancos' => NAVAL_CAIXA_BANCOS, 'promotores' => (object) naval_promotores(),
        'destinatarios' => $gere ? (array) (naval_tpa_config()['destinatarios'] ?? []) : null];
};

if (!naval_e_post()) {
    $ate = (string) ($_GET['ate'] ?? date('Y-m-d', time() - 86400));
    $de = (string) ($_GET['de'] ?? $ate);
    if (($_GET['formas_docs'] ?? '') === '1') {
        naval_json(['ok' => true, 'documentos' => naval_fp_documentos((string) ($_GET['dia'] ?? ''), (string) ($_GET['rota'] ?? ''))]);
    }
    if (($_GET['formas'] ?? '') === '1') {
        $corr = array_values(array_filter(naval_fp_correcoes(), function ($c) use ($de, $ate) {
            return $c['estado'] === 'pendente' || ($c['dia'] >= $de && $c['dia'] <= $ate);
        }));
        naval_json($base() + ['formas' => naval_fp_config(), 'formas_validas' => NAVAL_FP_FORMAS, 'reconciliacao' => naval_fp_reconciliacao($de, $ate),
            'correcoes' => $corr, 'pede_correcao' => $pedeCorr, 'aprova_correcao' => $aprovaCorr, 'eu' => $login]);
    }
    naval_json($base() + ['relatorio' => naval_tpa_relatorio($de, $ate)]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
// correcção da forma de pagamento: pede a Tesouraria, decide o 1.º nível de aprovação da caixa (outra pessoa)
switch ((string) ($p['accao'] ?? '')) {
    case 'corrigir':
        if (!$pedeCorr) throw new NavalErro('Só a Tesouraria (quem lança a caixa) pede a correcção da forma de pagamento.', 403);
        naval_json(['ok' => true, 'correcoes' => naval_fp_pedir((string) ($p['dia'] ?? ''), (string) ($p['rota'] ?? ''), (array) ($p['docs'] ?? []),
            (string) ($p['justificacao'] ?? ''), (string) ($p['evidencia'] ?? ''), $login)]);
    case 'aprovar_correcao':
    case 'recusar_correcao':
        if (!$aprovaCorr) throw new NavalErro('Só quem aprova a caixa (1.º nível) decide as correcções da forma de pagamento.', 403);
        naval_json(['ok' => true, 'correcao' => naval_fp_decidir((string) ($p['id'] ?? ''), $p['accao'] === 'aprovar_correcao', (string) ($p['motivo'] ?? ''), $login)]);
    case 'anular_correcao':
        if (!$aprovaCorr) throw new NavalErro('Só quem aprova a caixa (1.º nível) anula uma correcção.', 403);
        naval_json(['ok' => true, 'correcao' => naval_fp_anular((string) ($p['id'] ?? ''), (string) ($p['motivo'] ?? ''), $login)]);
}
if (!$gere) throw new NavalErro('O seu perfil só permite ver o relatório.', 403);
switch ((string) ($p['accao'] ?? '')) {
    case 'terminal':
        naval_tpa_gravar($p, $login);
        naval_json($base());
    case 'associar':
        $a = naval_tpa_associar(preg_replace('/\D/', '', (string) ($p['tpa'] ?? '')), (string) ($p['rota'] ?? ''), $login);
        naval_json($base() + ['outros_tpa' => $a['outros']]);   // o promotor já tem outro TPA em funcionamento
    case 'conferir':
    case 'retirar_conferencia':
        naval_tpa_conferir((string) ($p['dia'] ?? ''), (string) ($p['tpa'] ?? ''), ltrim((string) ($p['periodo'] ?? ''), '0') ?: '0',
            ($p['accao'] ?? '') === 'conferir', (string) ($p['ref'] ?? ''), (string) ($p['data'] ?? ''), $login);
        naval_json(['ok' => true]);
    case 'formas':
        naval_json(['ok' => true, 'formas' => naval_fp_gravar($p, $login)]);
    case 'destinatarios':
        naval_json($base() + ['destinatarios' => naval_tpa_destinatarios((array) ($p['destinatarios'] ?? []), $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
