<?php
/**
 * Conciliação da carrinha (nucleo/servicos/carrinha.php): carregado × vendido × devolvido por rota e dia, com a
 * contagem do técnico do armazém e a aprovação das diferenças.
 *
 * GET  v1/conciliacao-carrinha?data=AAAA-MM-DD[&actualizar=1]       recurso «conciliacao-carrinha» (Vr: só as suas rotas)
 * POST v1/conciliacao-carrinha {accao: contar, data, rota, contagem: {codigo: quantidade}, csrf}      «conciliacao-carrinha» G
 * POST v1/conciliacao-carrinha {accao: validar, data, rota, csrf}                                   quem contou
 * POST v1/conciliacao-carrinha {accao: aprovar, data, rota, motivos: {codigo: {motivo, nota}}, csrf}  «conciliacao-coordenacao» G
 * POST v1/conciliacao-carrinha {accao: fechar, data, rota, csrf}                                    «conciliacao-fecho» G
 * POST v1/conciliacao-carrinha {accao: corrigir_registo, data, rotas: [..], contado_por, validado_por, motivo, csrf}  só SADM
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
    naval_json(['ok' => true, 'gere' => $gere, 'pode' => $pode + ['corrigir' => naval_perfil() === 'SADM'], 'eu' => (string) ($eu['login'] ?? '')] + naval_carr_vista($data, naval_rotas(), $gere, ($_GET['actualizar'] ?? '') === '1'));
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
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
    naval_json(['ok' => true, 'rotas' => $feitas]);
}
$precisa = ['contar' => 'contar', 'validar' => 'contar', 'aprovar' => 'coordenar', 'fechar' => 'fechar'][$accao] ?? 'contar';
if (!$pode[$precisa]) {
    naval_registar('alteracao_recusada', ['accao' => 'carrinha_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro($precisa === 'contar' ? 'O seu perfil não permite contar a conciliação das carrinhas.'
        : 'O seu perfil não permite aprovar este nível da conciliação das carrinhas.', 403);
}
$data = (string) ($p['data'] ?? '');
$rota = (string) ($p['rota'] ?? '');
if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
switch ($accao) {
    case 'contar':
        naval_json(['ok' => true] + naval_carr_contar($data, $rota, array_slice((array) ($p['contagem'] ?? []), 0, 2000, true), $eu));
    case 'validar':
        naval_json(['ok' => true] + naval_carr_validar($data, $rota, $eu));
    case 'aprovar':
        naval_json(['ok' => true] + naval_carr_aprovar($data, $rota, array_slice((array) ($p['motivos'] ?? []), 0, 2000, true), $eu));
    case 'fechar':
        naval_json(['ok' => true] + naval_carr_fechar($data, $rota, $eu));
}
throw new NavalErro('Acção desconhecida.', 400);
