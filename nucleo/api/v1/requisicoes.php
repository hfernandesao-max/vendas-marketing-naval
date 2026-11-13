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
 * POST {accao: confirmar_carga, numero, entregue: {codigo: quantidade}, csrf}   o armazém confirma a carga
 * GET  v1/requisicoes?carga=1&data=AAAA-MM-DD          por rota: emitidas por carregar e carregadas no dia (stock)
 */
defined('NAVAL_API') || exit;
naval_servico('rotas');
naval_servico('requisicoes');
naval_exigir(['requisicoes-carrinha']);
$gere = naval_pode('requisicoes-carrinha', 'G');
$eu = (array) naval_utilizador();
$login = (string) ($eu['login'] ?? '');

if (!naval_e_post()) {
    if (($_GET['carga'] ?? '') === '1') {
        $data = (string) ($_GET['data'] ?? date('Y-m-d'));
        if (!naval_data_valida($data)) throw new NavalErro('Data inválida.', 400);
        naval_json_assinado(['ok' => true, 'gere' => $gere, 'eu' => $login, 'rotas' => (object) naval_req_carga($data, naval_rotas())]);
    }
    if (($_GET['catalogo'] ?? '') === '1') naval_json_assinado(['ok' => true, 'artigos' => naval_req_catalogo()]);
    if (isset($_GET['numero'])) {
        $r = naval_req_ler((string) $_GET['numero']);
        if (!$r || !naval_rota_permitida($r['rota'])) throw new NavalErro('Requisição não encontrada.', 404);
        naval_json_assinado(['ok' => true, 'gere' => $gere, 'requisicao' => $r]);
    }
    $de = (string) ($_GET['de'] ?? date('Y-m-d', time() - 30 * 86400));
    $ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    $rotas = naval_rotas();
    $promotores = naval_promotores();
    if ($rotas !== null) $promotores = array_intersect_key($promotores, array_flip($rotas));
    naval_json_assinado(['ok' => true, 'gere' => $gere, 'requisicoes' => naval_req_listar($de, $ate, $rotas), 'rotas' => (object) $promotores]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'requisicao_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite criar nem alterar requisições.', 403);
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
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_confirmar($numero, (array) ($p['entregue'] ?? []), $login)]);
    case 'anular':
        naval_json_assinado(['ok' => true, 'requisicao' => naval_req_anular($numero, (string) ($p['motivo'] ?? ''), $versao, $login)]);
}
throw new NavalErro('Acção desconhecida.', 400);
