<?php
/**
 * Requisições de reposição da carrinha ao armazém (nucleo/servicos/requisicoes.php). Recurso «requisicoes-carrinha»:
 * V/Vr ver (Vr: só as suas rotas), G criar, alterar, emitir e anular (sempre dentro das rotas de cada pessoa).
 *
 * GET  v1/requisicoes?de=AAAA-MM-DD&ate=AAAA-MM-DD     lista do período
 * GET  v1/requisicoes?numero=RQ-AAAA-000001            uma requisição, com o histórico
 * GET  v1/requisicoes?catalogo=1                       artigos que se podem requisitar (disponível nos armazéns)
 * POST {accao: criar, rota, data?, linhas?, nota?, csrf}
 * POST {accao: gravar, numero, versao, linhas: [{codigo, artigo, unidade, quantidade}], nota, csrf}
 * POST {accao: emitir, numero, versao, csrf}            fecha o documento (PDF / impressão)
 * POST {accao: reimprimir, numero, csrf}                2.ª via de um documento emitido
 * POST {accao: anular, numero, versao, motivo, csrf}
 * POST {accao: aprovar, numero, csrf}                   aprova a PO para a carga («requisicoes-aprovacao» G; quem a criou ou emitiu não)
 * POST {accao: fechar, numero, motivo, csrf}            fecha uma PO emitida que não vai ser carregada
 * POST {accao: confirmar_carga, numero, entregue: {codigo: quantidade}, motivos: {codigo: {codigo, texto}}, csrf}
 *      o Fiel de Armazém confere a carga no cais (motivo obrigatório em cada diferença; só com
 *      os fechos das carrinhas feitos, ou com a autorização de carga antes do fecho)
 * POST {accao: autorizar_carga, numero, motivo, csrf}   carga antes do fecho (quem fecha as conciliações, «conciliacao-fecho» G)
 * GET  v1/requisicoes?carga=1&data=AAAA-MM-DD          por rota: emitidas por carregar e carregadas no dia (stock)
 * POST {accao: validar, numero, csrf}                  Back-Office valida a carga conferida e o portal ordena a Guia de
 *      Remessa ao Pinguim BO («validacao-carga» G; quem emitiu ou conferiu não valida)
 */
defined('NAVAL_API') || exit;
naval_servico('rotas');
naval_servico('requisicoes');
naval_exigir(['requisicoes-carrinha', 'validacao-carga']);   // o Back-Office valida as cargas sem gerir requisições
$gere = naval_pode('requisicoes-carrinha', 'G');
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');

if (!naval_e_post()) {
    if (($_GET['carga'] ?? '') === '1') {
        $data = (string) ($_GET['data'] ?? date('Y-m-d'));
        if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
        naval_servico('calendario');
        $carga = naval_req_carga($data, naval_rotas());
        naval_json_assinado(['ok' => true, 'gere' => $gere, 'eu' => $login, 'horario' => naval_cal_para_pagina($data), 'rotas' => (object) $carga,
            'fechos' => (object) ($data === date('Y-m-d') ? naval_req_fechos($carga) : []), 'autoriza' => naval_pode('conciliacao-fecho', 'G')]);
    }
    if (($_GET['catalogo'] ?? '') === '1') {
        $art = naval_req_catalogo();
        // sem o stock do Pinguim BO: o ecrã diz porquê (em vez de esconder a coluna do stock)
        naval_json_assinado(['ok' => true, 'artigos' => $art, 'stock_erro' => $GLOBALS['naval_req_catalogo_erro'] ?? null]);
    }
    if (isset($_GET['numero'])) {
        $r = naval_req_ler((string) $_GET['numero']);
        if (!$r || !naval_rota_permitida($r['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
        naval_json_assinado(['ok' => true, 'gere' => $gere, 'aprova' => naval_pode('requisicoes-aprovacao', 'G'), 'requisicao' => $r + ['pronta_carga' => naval_req_pronta_carga($r),
            'precisa_aprovacao' => naval_req_precisa_aprovacao($r)], 'motivos' => NAVAL_REQ_MOTIVOS, 'eu' => $login]);
    }
    $de = (string) ($_GET['de'] ?? date('Y-m-d', time() - 30 * 86400));
    $ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    $rotas = naval_rotas();
    $promotores = naval_promotores();
    if ($rotas !== null) $promotores = array_intersect_key($promotores, array_flip($rotas));
    $lista = array_map(function ($r) { return $r + ['pronta_carga' => naval_req_pronta_carga($r), 'precisa_aprovacao' => naval_req_precisa_aprovacao($r)]; }, naval_req_listar($de, $ate, $rotas));
    naval_json_assinado(['ok' => true, 'gere' => $gere, 'aprova' => naval_pode('requisicoes-aprovacao', 'G'), 'eu' => $login, 'requisicoes' => $lista, 'rotas' => (object) $promotores]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
// autorização de carga antes do fecho: quem fecha as conciliações (não precisa de gerir requisições)
if ($accao === 'autorizar_carga') {
    if (!naval_pode('conciliacao-fecho', 'G')) {
        naval_registar('alteracao_recusada', ['accao' => 'autorizar_carga', 'motivo' => 'perfil']);
        throw new NavalErro('Só quem fecha as conciliações das carrinhas autoriza uma carga antes do fecho.', 403);
    }
    $alvo = naval_req_ler((string) ($p['numero'] ?? ''));
    if (!$alvo || !naval_rota_permitida($alvo['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
    naval_json_assinado(['ok' => true, 'requisicao' => naval_req_autorizar_carga((string) $p['numero'], (string) ($p['motivo'] ?? ''), $login)]);
}
// aprovação da PO para a carga: quem responde pela rota (não precisa de gerir requisições)
if ($accao === 'aprovar') {
    if (!naval_pode('requisicoes-aprovacao', 'G')) {
        naval_registar('alteracao_recusada', ['accao' => 'aprovar_po', 'motivo' => 'perfil']);
        throw new NavalErro('O seu perfil não permite aprovar PO.', 403);
    }
    $alvo = naval_req_ler((string) ($p['numero'] ?? ''));
    if (!$alvo || !naval_rota_permitida($alvo['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
    naval_json_assinado(['ok' => true, 'requisicao' => naval_req_aprovar((string) $p['numero'], $login)]);
}
// validação do Back-Office (não precisa de gerir requisições)
if ($accao === 'validar') {
    if (!naval_pode('validacao-carga', 'G')) {
        naval_registar('alteracao_recusada', ['accao' => 'validar_carga', 'motivo' => 'perfil']);
        throw new NavalErro('O seu perfil não permite validar cargas.', 403);
    }
    $alvo = naval_req_ler((string) ($p['numero'] ?? ''));
    if (!$alvo || !naval_rota_permitida($alvo['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
    naval_json_assinado(['ok' => true, 'requisicao' => naval_req_validar((string) $p['numero'], $login)]);
}
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'requisicao_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite criar nem alterar requisições.', 403);
}
if ($accao === 'anular_vazios') {
    $dia = (string) ($p['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    naval_json_assinado(['ok' => true, 'anuladas' => naval_req_anular_vazios($dia, $login, naval_rotas())]);
}
// âmbito: as acções sobre uma requisição só nas rotas da pessoa (fora do âmbito, como se não existisse)
if ($accao !== 'criar') {
    $alvo = naval_req_ler((string) ($p['numero'] ?? ''));
    if (!$alvo || !naval_rota_permitida($alvo['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
}
$versao = isset($p['versao']) && is_numeric($p['versao']) ? (int) $p['versao'] : null;
$numero = (string) ($p['numero'] ?? '');
switch ($accao) {
    case 'criar':
        $rota = (string) ($p['rota'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
        $data = (string) ($p['data'] ?? date('Y-m-d'));
        if (!naval_data_valida($data) || $data > date('Y-m-d', time() + 7 * 86400)) throw new NavalErro('Data inválida.', 400);
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_criar($rota, $data, (array) ($p['linhas'] ?? []), $login, ['tipo' => 'manual'], (string) ($p['nota'] ?? ''))]);
    case 'gravar':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_gravar($numero, (array) ($p['linhas'] ?? []), (string) ($p['nota'] ?? ''), $versao, $login)]);
    case 'emitir':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_emitir($numero, $versao, $login)]);
    case 'reimprimir':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_reimprimir($numero, $login)]);
    case 'confirmar_carga':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_confirmar($numero, (array) ($p['entregue'] ?? []), $login, (array) ($p['motivos'] ?? []))]);
    case 'anular':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_anular($numero, (string) ($p['motivo'] ?? ''), $versao, $login)]);
    case 'fechar':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_fechar($numero, (string) ($p['motivo'] ?? ''), $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
