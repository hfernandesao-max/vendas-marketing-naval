<?php
/**
 * Validação das cargas e ordens ao Pinguim BO (nucleo/servicos/ordens-pinguim.php). Recurso «validacao-carga»:
 * V ver, G validar e tratar as ordens (sempre no âmbito das rotas da pessoa).
 *
 * GET  v1/ordens-pinguim[?data=AAAA-MM-DD]   {por_validar: [cargas conferidas no cais], ordens: [...], modo, gere, eu}
 * POST {accao: executada_manual, id, documento, csrf}   o Back-Office criou o documento no Pinguim BO (número)
 * POST {accao: repetir, id, csrf}                       volta a pôr na fila uma ordem recusada ou divergente
 * POST {accao: cancelar, id, motivo, csrf}
 * (validar uma carga: POST v1/requisicoes {accao: validar})
 */
defined('NAVAL_API') || exit;
naval_exigir(['validacao-carga']);
naval_servico('rotas');
naval_servico('requisicoes');
naval_servico('ordens-pinguim');
$gere = naval_pode('validacao-carga', 'G');
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');
$rotas = naval_rotas();

if (!naval_e_post()) {
    $data = (string) ($_GET['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
    // cargas conferidas no cais à espera do Back-Office (de qualquer dia até à data) e as validadas no dia
    $porValidar = [];
    foreach (array_unique([substr($data, 0, 4), (string) ((int) substr($data, 0, 4) - 1)]) as $ano) {
        foreach (naval_req_ficheiros($ano) as $f) {
            $r = naval_json_ler((string) $f);
            if (!$r || $r['estado'] !== 'carregada' || ($r['fase'] ?? '') !== 'conferida') continue;
            if ($rotas !== null && !in_array((string) $r['rota'], $rotas, true)) continue;
            if (substr((string) $r['confirmado_em'], 0, 10) > $data) continue;
            unset($r['historico']);
            $r['diferencas'] = count(array_filter($r['linhas'], function ($l) { return abs((float) ($l['entregue'] ?? 0) - (float) $l['quantidade']) > 0.0001; }));
            $porValidar[] = $r;
        }
    }
    usort($porValidar, function ($a, $b) { return strcmp((string) $a['confirmado_em'], (string) $b['confirmado_em']); });
    naval_json_assinado(['ok' => true, 'gere' => $gere, 'eu' => $login, 'modo' => naval_ord_modo(), 'por_validar' => $porValidar,
        'ordens' => naval_ord_listar($rotas), 'motivos' => NAVAL_REQ_MOTIVOS]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'ordem_' . (string) ($p['accao'] ?? ''), 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite tratar as ordens ao Pinguim BO.', 403);
}
$id = (string) ($p['id'] ?? '');
$o = naval_ord_ler($id);
if (!$o || !naval_rota_permitida($o['rota'])) throw new NavalErro('Ordem não encontrada.', 404);
switch ((string) ($p['accao'] ?? '')) {
    case 'executada_manual':
        naval_json_assinado(['ok' => true, 'ordem' => naval_ord_executada_manual($id, (string) ($p['documento'] ?? ''), $login)]);
    case 'repetir':
        naval_json_assinado(['ok' => true, 'ordem' => naval_ord_repetir($id, $login)]);
    case 'cancelar':
        naval_json_assinado(['ok' => true, 'ordem' => naval_ord_cancelar($id, (string) ($p['motivo'] ?? ''), $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
