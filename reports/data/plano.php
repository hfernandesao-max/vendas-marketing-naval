<?php
/**
 * Plano de cargas (ver nucleo/servicos/cargas.php).
 *
 * GET  ?data=AAAA-MM-DD     plano do dia, listas e rotas
 * GET  ?historico=1         dias com plano
 * GET  ?xlsx=AAAA-MM-DD     Excel da última publicação desse dia
 * POST {accao: guardar | publicar | reenviar | listas | teste_email, csrf, ...}
 *
 * Ver: nível V (ou V·r, só as suas rotas). Preencher, publicar e gerir as listas: nível G (Logística).
 */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('cargas');
naval_exigir(['plano-cargas']);
$gere = naval_plano_gere();

// ---------------- Leitura ----------------
if (!naval_e_post()) {
    if (isset($_GET['xlsx'])) {
        $data = (string) $_GET['xlsx'];
        $p = naval_data_valida($data) ? naval_plano_ler($data) : null;
        $v = (int) ($p['publicado']['versao'] ?? 0);
        $f = naval_plano_ficheiro_xlsx($data, $v);
        if (!$v || !is_file($f) || !naval_pode_exportar('plano-cargas') || naval_rotas() !== null) throw new NavalErro('Excel não disponível.', 404);
        naval_registar('exportacao', ['pagina' => 'plano-cargas', 'data' => $data, 'versao' => $v]);
        naval_enviar_ficheiro($f, 'PLANO_DE_CARGAS_' . str_replace('-', '.', naval_data_pt($data)) . '.xlsx', NAVAL_TIPO_XLSX);
    }
    if (isset($_GET['historico'])) naval_json(['dias' => naval_plano_historico($gere)]);

    $data = (string) ($_GET['data'] ?? date('Y-m-d'));
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.');
    $listas = naval_plano_listas();
    if (!$gere) $listas['destinatarios'] = [];
    naval_json([
        'data' => $data,
        'plano' => naval_plano_vista(naval_plano_ler($data), $gere),
        'anterior' => naval_plano_anterior($data, $gere),
        'rotas' => naval_filtrar_rotas(naval_rotas_portal()),
        'listas' => $listas,
        'gere' => $gere,
        'exportar' => naval_pode_exportar('plano-cargas') && naval_rotas() === null,
        'email_configurado' => naval_email_configurado(),
        'ligacao' => naval_plano_ligacao(),
    ]);
}

// ---------------- Alterações ----------------
$q = naval_pedido();
$accao = (string) ($q['accao'] ?? '');
naval_exigir_csrf($q);
if (!$gere) {
    naval_registar('alteracao_recusada', ['accao' => 'plano_' . $accao, 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite alterar o plano de cargas.', 403);
}
$eu = (array) naval_utilizador();
$listas = naval_plano_listas();

switch ($accao) {
    case 'guardar':
    case 'publicar':
        $p = naval_plano_actualizar((string) ($q['data'] ?? ''), (int) ($q['rev'] ?? -1), (array) ($q['rotas'] ?? []), $eu, $listas['artigos']);
        if ($accao === 'guardar') {
            naval_plano_gravar($p);
            naval_json(['ok' => true, 'plano' => $p]);
        }
        $xlsx = base64_decode((string) ($q['xlsx'] ?? ''), true);
        naval_json(['ok' => true] + naval_plano_publicar($p, $xlsx === false ? '' : $xlsx, (string) ($q['nota'] ?? ''), $eu, $listas));
    case 'reenviar':
        $r = naval_plano_reenviar((string) ($q['data'] ?? ''), $listas, $eu);
        naval_json(['ok' => $r['email']['ok'], 'erro' => $r['email']['erro']] + $r);
    case 'listas':
        naval_json(['ok' => true, 'listas' => naval_plano_actualizar_listas((array) ($q['listas'] ?? []), $listas)]);
    case 'teste_email':
        $r = naval_enviar_email([['email' => $eu['email'] ?? '', 'nome' => $eu['nome'] ?? '']], 'Teste de envio · Portal RTM',
            '<p style="font-family:Segoe UI,Arial,sans-serif">Este é um email de teste do Portal RTM. Se o recebeu, o envio do plano de cargas está a funcionar.</p>');
        naval_registar('email_teste', ['ok' => $r['ok']]);
        naval_json(['ok' => $r['ok'], 'erro' => $r['erro'], 'para' => $eu['email'] ?? '']);
}
throw new NavalErro('Acção desconhecida.');
