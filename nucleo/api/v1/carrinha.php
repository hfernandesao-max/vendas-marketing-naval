<?php
/**
 * Conciliação da carrinha (nucleo/servicos/carrinha.php): carregado × vendido × devolvido por rota e dia, com a
 * contagem do técnico do armazém e a aprovação das diferenças.
 *
 * GET  v1/conciliacao-carrinha?data=AAAA-MM-DD[&actualizar=1]       recurso «conciliacao-carrinha» (Vr: só as suas rotas)
 * POST v1/conciliacao-carrinha {accao: preparar_folha, data, rota, csrf}                             «conciliacao-carrinha» V
 * POST v1/conciliacao-carrinha {accao: actualizar_abertas_anteriores, csrf}    actualiza históricos ainda abertos; só SADM
 * POST v1/conciliacao-carrinha {accao: contar, data, rota, contagem: {codigo: quantidade}, csrf}      «conciliacao-carrinha» G
 * POST v1/conciliacao-carrinha {accao: validar, data, rota, csrf}                                   quem contou
 * POST v1/conciliacao-carrinha {accao: aprovar, data, rota, motivos: {codigo: {motivo, nota}}, csrf}  «conciliacao-coordenacao» G
 * POST v1/conciliacao-carrinha {accao: fechar, data, rota, csrf}                                    «conciliacao-fecho» G
 * POST v1/conciliacao-carrinha {accao: corrigir_registo, data, rotas: [..], contado_por, validado_por, motivo, csrf}  só SADM
 * POST v1/conciliacao-carrinha {accao: deposito, rota, data, documento, linhas: [{codigo, artigo, quantidade, preco}], pagamento,
 *      cliente, nota, csrf}        venda por depósito (facturada no SAP); quem aprova (coordenação ou fecho)
 * POST v1/conciliacao-carrinha {accao: deposito_factura, id, factura, csrf}   o número da factura SAP
 */
defined('NAVAL_API') || exit;
naval_servico('carrinha');
naval_exigir(['conciliacao-carrinha']);
$gere = naval_pode('conciliacao-carrinha', 'G');
$pode = ['contar' => $gere, 'coordenar' => naval_pode('conciliacao-coordenacao', 'G'), 'fechar' => naval_pode('conciliacao-fecho', 'G')];
$eu = (array) naval_utilizador();

if (!naval_e_post()) {
    $data = (string) ($_GET['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($data) || $data > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    $corrigir = naval_perfil() === 'SADM';
    naval_servico('calendario');
    $vista = naval_carr_vista($data, naval_rotas(), $gere, ($_GET['actualizar'] ?? '') === '1', $pode['coordenar'] || $pode['fechar']);
    // vendas por depósito (SAP) de cada rota, de 30 dias antes a 7 dias depois do dia escolhido
    $deps = array_values(array_filter(naval_carr_depositos(), function ($x) use ($data) { return (string) $x['data'] >= date('Y-m-d', strtotime($data . ' 12:00') - 30 * 86400) && (string) $x['data'] <= date('Y-m-d', strtotime($data . ' 12:00') + 7 * 86400); }));
    foreach ($vista['rotas'] as &$rd) $rd['depositos'] = array_values(array_filter($deps, function ($x) use ($rd) { return (string) $x['rota'] === (string) $rd['rota']; }));
    unset($rd);
    // facturas desse dia fora do horário de vendas, por rota (o aviso é extra: sem Pinguim BO, fica sem ele)
    try {
        naval_servico('fora-horario');
        $fh = naval_fh_periodo($data, $data) ?? [];
        foreach ($vista['rotas'] as &$rv) {
            $rv['fora_horario'] = array_values(array_filter($fh, function ($x) use ($rv) { return (string) $x['rota'] === (string) $rv['rota']; }));
        }
        unset($rv);
    } catch (Throwable $e) {
        error_log('[fora do horário] ' . $e->getMessage());
    }
    naval_json_assinado(['ok' => true, 'gere' => $gere, 'pode' => $pode + ['corrigir' => $corrigir], 'eu' => (string) ($eu['login'] ?? '')]
        + ($corrigir ? ['utilizadores' => naval_carr_contas()] : []) + ['horario' => naval_cal_para_pagina($data)] + $vista);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'actualizar_abertas_anteriores') {
    if (naval_perfil() !== 'SADM') {
        naval_registar('alteracao_recusada', ['accao' => $accao, 'motivo' => 'perfil']);
        throw new NavalErro('Só o super administrador pode actualizar as conciliações históricas em aberto.', 403);
    }
    naval_json_assinado(['ok' => true, 'resultado' => naval_carr_actualizar_abertas_anteriores($eu, (string) ($p['depois_de'] ?? ''))]);
}
if ($accao === 'corrigir_registo') {
    // correcção de quem contou / validou: só o super administrador, com motivo, fica no histórico de cada rota
    if (naval_perfil() !== 'SADM') {
        naval_registar('alteracao_recusada', ['accao' => 'carrinha_corrigir_registo', 'motivo' => 'perfil']);
        throw new NavalErro('Só o super administrador corrige o registo da contagem.', 403);
    }
    $data = (string) ($p['data'] ?? '');
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
    $feitas = naval_carr_corrigir_registo($data, array_slice((array) ($p['rotas'] ?? []), 0, 500), (string) ($p['contado_por'] ?? ''),
        (string) ($p['validado_por'] ?? ''), (string) ($p['motivo'] ?? ''), $eu);
    naval_json_assinado(['ok' => true, 'rotas' => $feitas]);
}
if ($accao === 'deposito' || $accao === 'deposito_factura') {
    if (!$pode['coordenar'] && !$pode['fechar']) {
        naval_registar('alteracao_recusada', ['accao' => 'carrinha_' . $accao, 'motivo' => 'perfil']);
        throw new NavalErro('Só a coordenação ou o responsável registam vendas por depósito.', 403);
    }
    if ($accao === 'deposito_factura') {
        $dx = array_values(array_filter(naval_carr_depositos(), function ($x) use ($p) { return (string) $x['id'] === (string) ($p['id'] ?? ''); }))[0] ?? null;
        if ($dx && !naval_rota_permitida((string) $dx['rota'])) throw new NavalErro('Sem acesso a esta rota.', 403);
    }
    if ($accao === 'deposito_factura') naval_json_assinado(['ok' => true, 'deposito' => naval_carr_deposito_factura((string) ($p['id'] ?? ''), (string) ($p['factura'] ?? ''), $eu)]);
    $rota = (string) ($p['rota'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
    naval_json_assinado(['ok' => true, 'deposito' => naval_carr_deposito_registar($rota, (string) ($p['data'] ?? ''), (string) ($p['documento'] ?? ''),
        array_slice((array) ($p['linhas'] ?? []), 0, 200), (string) ($p['pagamento'] ?? ''), (string) ($p['cliente'] ?? ''), (string) ($p['nota'] ?? ''), $eu)]);
}
$precisa = ['contar' => 'contar', 'validar' => 'contar', 'aprovar' => 'coordenar', 'fechar' => 'fechar'][$accao] ?? 'contar';
if ($accao !== 'preparar_folha' && !$pode[$precisa]) {
    naval_registar('alteracao_recusada', ['accao' => 'carrinha_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro($precisa === 'contar' ? 'O seu perfil não permite contar a conciliação das carrinhas.'
        : 'O seu perfil não permite aprovar este nível da conciliação das carrinhas.', 403);
}
$data = (string) ($p['data'] ?? '');
$rota = (string) ($p['rota'] ?? '');
if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
if ($accao === 'preparar_folha') {
    naval_json_assinado(['ok' => true] + naval_carr_preparar_folha($data, $rota, $eu));
}
switch ($accao) {
    case 'contar':
        naval_json_assinado(['ok' => true] + naval_carr_contar($data, $rota, array_slice((array) ($p['contagem'] ?? []), 0, 2000, true), $eu));
    case 'validar':
        naval_json_assinado(['ok' => true] + naval_carr_validar($data, $rota, $eu));
    case 'aprovar':
        naval_json_assinado(['ok' => true] + naval_carr_aprovar($data, $rota, array_slice((array) ($p['motivos'] ?? []), 0, 2000, true), $eu));
    case 'fechar':
        naval_json_assinado(['ok' => true] + naval_carr_fechar($data, $rota, $eu));
}
throw new NavalErro('Acção desconhecida.', 400);
