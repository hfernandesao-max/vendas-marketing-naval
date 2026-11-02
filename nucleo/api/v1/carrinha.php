<?php
/**
 * Conciliação da carrinha (nucleo/servicos/carrinha.php): carregado × vendido × devolvido por rota e dia, com a
 * contagem do técnico do armazém e a aprovação das diferenças.
 *
 * GET  v1/conciliacao-carrinha?data=AAAA-MM-DD[&actualizar=1]       recurso «conciliacao-carrinha» (Vr: só as suas rotas)
 * POST v1/conciliacao-carrinha {accao: contar, data, rota, contagem: {codigo: quantidade}, csrf}      nível G
 * POST v1/conciliacao-carrinha {accao: aprovar, data, rota, motivos: {codigo: {motivo, nota}}, csrf}  nível G
 */
defined('NAVAL_API') || exit;
naval_servico('carrinha');
naval_exigir(['conciliacao-carrinha']);
$gere = naval_pode('conciliacao-carrinha', 'G');

if (!naval_e_post()) {
    $data = (string) ($_GET['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($data) || $data > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
    naval_json(['ok' => true, 'gere' => $gere] + naval_carr_vista($data, naval_rotas(), $gere, ($_GET['actualizar'] ?? '') === '1'));
}

$p = naval_pedido();
naval_exigir_csrf($p);
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'carrinha_' . (string) ($p['accao'] ?? ''), 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite contar nem aprovar a conciliação das carrinhas.', 403);
}
$data = (string) ($p['data'] ?? '');
$rota = (string) ($p['rota'] ?? '');
if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
$eu = (array) naval_utilizador();
switch ((string) ($p['accao'] ?? '')) {
    case 'contar':
        naval_json(['ok' => true] + naval_carr_contar($data, $rota, array_slice((array) ($p['contagem'] ?? []), 0, 2000, true), $eu));
    case 'aprovar':
        naval_json(['ok' => true] + naval_carr_aprovar($data, $rota, array_slice((array) ($p['motivos'] ?? []), 0, 2000, true), $eu));
}
throw new NavalErro('Acção desconhecida.', 400);
