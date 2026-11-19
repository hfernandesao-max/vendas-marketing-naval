<?php
/**
 * Rotas propostas (nucleo/servicos/rotas-propostas.php): fila de aprovação das rotas novas.
 *
 * GET  v1/rotas-propostas                                        recurso «rotas-propostas» (Vr: as propostas das suas rotas)
 *   → {ok, propostas: [{id, tipo, estado, estado_nome, origem_rota, motivo, territorio, centro, clientes: [{c, n, rota}],
 *      potenciais, impacto, decisao, historico}], estados, pendentes, decide}
 * POST v1/rotas-propostas {accao: aprovar, id, rota_nova, promotor, nota, csrf}    nível G
 * POST v1/rotas-propostas {accao: rejeitar, id, nota, csrf}                         nível G
 */
defined('NAVAL_API') || exit;
naval_servico('rotas-propostas');
naval_exigir(['rotas-propostas']);

$decide = naval_pode('rotas-propostas', 'G');
if (naval_e_post()) {
    if (!$decide) {
        naval_registar('alteracao_recusada', ['accao' => 'rotas_propostas', 'motivo' => 'perfil']);
        throw new NavalErro('Sem permissão para decidir as rotas propostas.', 403);
    }
    $p = naval_pedido();
    naval_exigir_csrf($p);
    $accao = (string) ($p['accao'] ?? '');
    if (!in_array($accao, ['aprovar', 'rejeitar'], true)) throw new NavalErro('Acção desconhecida.', 400);
    $id = (string) ($p['id'] ?? '');
    if (!preg_match('/^RP-\d{4,6}$/', $id)) throw new NavalErro('Proposta desconhecida.', 404);
    $eu = naval_utilizador();
    naval_json(['ok' => true] + naval_rp_decisao($id, $accao, $p, ['login' => (string) $eu['login'], 'nome' => (string) ($eu['nome'] ?? $eu['login'])]));
}
naval_json(['ok' => true, 'decide' => $decide] + naval_rp_vista(naval_rotas()));
