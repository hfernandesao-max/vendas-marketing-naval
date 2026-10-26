<?php
/**
 * Ficheiro de vendas RTM para o SAP (ver nucleo/servicos/sap.php).
 *
 * GET               configuração, lotes e documentos já exportados
 * GET ?modelo=1     modelo original do SAP (o portal escreve os dados por cima, mantendo formatação, tema e larguras)
 * GET ?xlsx=N       Excel do lote N
 * POST {accao: config | lote, csrf, ...}   nível E (Financeiro e Back-office)
 */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('sap');
naval_exigir(['sap-exportar']);
$podeGerar = naval_pode_exportar('sap-exportar');

if (!naval_e_post()) {
    if (isset($_GET['modelo'])) naval_enviar_ficheiro(naval_raiz() . NAVAL_SAP_MODELO, '', NAVAL_TIPO_XLSX);
    if (isset($_GET['xlsx'])) {
        $n = (int) $_GET['xlsx'];
        $lote = naval_sap_lote($n);
        naval_registar('exportacao', ['pagina' => 'sap-exportar', 'lote' => $n]);
        naval_enviar_ficheiro($lote['ficheiro'], $lote['nome'], NAVAL_TIPO_XLSX);
    }
    naval_json(naval_sap_resumo() + ['pode_gerar' => $podeGerar]);
}

$q = naval_pedido();
naval_exigir_csrf($q);
if (!$podeGerar) {
    naval_registar('alteracao_recusada', ['accao' => 'sap_' . ($q['accao'] ?? ''), 'motivo' => 'perfil']);
    throw new NavalErro('O seu perfil não permite gerar o ficheiro para o SAP.', 403);
}
switch ($q['accao'] ?? '') {
    case 'config':
        naval_json(['ok' => true, 'config' => naval_sap_gravar_config((array) ($q['config'] ?? []))]);
    case 'lote':
        $xlsx = base64_decode((string) ($q['xlsx'] ?? ''), true);
        naval_json(['ok' => true, 'lote' => naval_sap_novo_lote($q, $xlsx === false ? '' : $xlsx, (array) naval_utilizador())]);
}
throw new NavalErro('Acção desconhecida.');
