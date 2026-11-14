<?php
/**
 * Explorador de relatórios (nucleo/servicos/relatorios.php). Recurso «relatorios»: V criar e guardar os seus
 * relatórios; G também partilhar com todos. «relatorios-certificar» G: certificar (selo «certificado»).
 *
 * GET  v1/relatorios                                    relatórios que a pessoa vê (seus, partilhados, certificados)
 * GET  v1/relatorios?fonte=caixa&de=AAAA-MM-DD&ate=…    fecho de caixa por período       (recurso «caixa-marketing»)
 * GET  v1/relatorios?fonte=conciliacao&de=…&ate=…       conciliação das carrinhas         (recurso «conciliacao-carrinha»)
 * POST {accao: guardar, id?, versao?, nome, descricao, visibilidade: pessoal|partilhado, definicao, csrf}
 * POST {accao: apagar, id, csrf}
 * POST {accao: certificar | retirar_certificacao, id, csrf}
 */
defined('NAVAL_API') || exit;
naval_exigir(['relatorios']);
naval_servico('relatorios');
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');
$sadm = naval_perfil() === 'SADM';
$pode = [
    'partilhar' => naval_pode('relatorios', 'G'),
    'certificar' => naval_pode('relatorios-certificar', 'G'),
];

if (!naval_e_post()) {
    $fonte = (string) ($_GET['fonte'] ?? '');
    if ($fonte !== '') {
        $de = (string) ($_GET['de'] ?? '');
        $ate = (string) ($_GET['ate'] ?? '');
        if ($fonte === 'caixa') {
            naval_exigir(['caixa-marketing']);
            naval_json(['ok' => true] + naval_rel_caixa($de, $ate));
        }
        if ($fonte === 'conciliacao') {
            naval_exigir(['conciliacao-carrinha']);
            naval_json(['ok' => true] + naval_rel_conciliacao($de, $ate, naval_rotas()));
        }
        throw new NavalErro('Fonte desconhecida.', 400);
    }
    naval_json(['ok' => true, 'eu' => $login, 'pode' => $pode, 'relatorios' => naval_rel_lista($login)]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
$id = (string) ($p['id'] ?? '');
if ($accao === 'guardar') {
    $r = naval_rel_guardar($p, $eu, $pode['partilhar']);
} elseif ($accao === 'apagar') {
    naval_rel_apagar($id, $eu, $sadm);
    $r = null;
} elseif ($accao === 'certificar' || $accao === 'retirar_certificacao') {
    if (!$pode['certificar']) throw new NavalErro('O seu perfil não permite certificar relatórios.', 403);
    $r = naval_rel_certificar($id, $accao === 'certificar', $eu, $sadm);
} else {
    throw new NavalErro('Acção desconhecida.', 400);
}
naval_json(['ok' => true, 'relatorio' => $r, 'pode' => $pode, 'relatorios' => naval_rel_lista($login)]);
