<?php
/**
 * Suporte e incidentes (nucleo/servicos/suporte.php; ISO/IEC 20000-1, ISO/IEC 27035).
 *
 * GET:  pedidos visíveis (os seus; quem gere, todos) e indicadores; ?anexo=SUP-000123 → captura de ecrã do pedido
 * POST {accao: criar, tipo, servico, impacto, urgencia, titulo, descricao, dados_pessoais, contexto, anexo}
 *      {accao: comentar|atender|prioridade|resolver|reabrir|avaliar, id, texto, …}   sempre com csrf
 *      {accao: encaminhamento, …} configuração do envio para a equipa de TI; {accao: reenviar, id} (quem gere)
 */
defined('NAVAL_API') || exit;
naval_servico('suporte');
naval_exigir(['suporte']);
$eu = (array) naval_utilizador();
$GLOBALS['naval_api_sensivel'] = true;

naval_servico('suporte-encaminhar');
if (!naval_e_post()) {
    if (isset($_GET['anexo'])) {
        $id = (string) $_GET['anexo'];
        $p = naval_sup_todos()[$id] ?? null;
        if (!preg_match('/^SUP-\d{6}$/', $id) || !$p || !naval_sup_visivel($p, $eu) || empty($p['anexos'][0])) throw new NavalErro('Captura não encontrada.', 404);
        $a = $p['anexos'][0];
        naval_registar('suporte_anexo', ['id' => $id]);
        naval_enviar_ficheiro(naval_sup_pasta_anexos() . '/' . basename((string) $a['ficheiro']), (string) $a['nome'], (string) $a['tipo']);
    }
    naval_json(['ok' => true, 'eu' => (string) $eu['login']] + naval_sup_vista($eu, time())
        + (naval_sup_gere() ? ['encaminhamento' => naval_sup_enc_config()] : []));
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'encaminhamento') {
    if (!naval_sup_gere()) throw new NavalErro('Sem permissão.', 403);
    $cfg = naval_sup_enc_gravar($p, (string) $eu['login']);
    naval_json(['ok' => true, 'eu' => (string) $eu['login'], 'encaminhamento' => $cfg] + naval_sup_vista($eu, time()));
}
if ($accao === 'reenviar') {
    $id = (string) ($p['id'] ?? '');
    if (!naval_sup_gere()) throw new NavalErro('Sem permissão.', 403);
    if (!preg_match('/^SUP-\d{6}$/', $id) || !isset(naval_sup_todos()[$id])) throw new NavalErro('Pedido inválido.', 400);
    naval_exclusivo('suporte', function () use ($id) {
        $t = naval_sup_todos();
        if (($t[$id]['encaminhamento']['estado'] ?? '') !== 'enviado') {
            $t[$id]['encaminhamento'] = ['estado' => 'por_enviar', 'tentativas' => 0, 'confirmado' => (bool) ($t[$id]['encaminhamento']['confirmado'] ?? false)];
            naval_json_gravar(naval_sup_ficheiro(), $t);
        }
    });
    $r = naval_sup_encaminhar($id) ?? naval_sup_todos()[$id];
    naval_json(['ok' => true, 'eu' => (string) $eu['login'], 'pedido' => naval_sup_publico($r, time()), 'encaminhamento' => naval_sup_enc_config()] + naval_sup_vista($eu, time()));
}
if ($accao === 'criar') {
    $r = naval_sup_criar($eu, $p);
} else {
    $id = (string) ($p['id'] ?? '');
    if (!preg_match('/^SUP-\d{6}$/', $id)) throw new NavalErro('Pedido inválido.', 400);
    $r = naval_sup_accao($eu, $id, $accao, $p);
}
naval_json(['ok' => true, 'eu' => (string) $eu['login'], 'pedido' => naval_sup_publico($r, time())] + naval_sup_vista($eu, time()));
