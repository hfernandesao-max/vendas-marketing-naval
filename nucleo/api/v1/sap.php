<?php
/**
 * Ficheiro de vendas RTM para o SAP (ver nucleo/servicos/sap.php).
 *
 * GET               configuração, lotes e documentos já exportados
 * GET ?modelo=1     modelo original do SAP (o portal escreve os dados por cima, mantendo formatação, tema e larguras)
 * GET ?xlsx=N       segunda via do Excel do lote N (só auditoria: TI, AUD)
 * POST {accao: config | lote | confirmar | anular | envio_teste, csrf, ...}   nível E (Financeiro e Back-office)
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('sap');
naval_exigir(['sap-exportar']);
$podeGerar = naval_pode_exportar('sap-exportar');

if (!naval_e_post()) {
    if (isset($_GET['modelo'])) naval_enviar_ficheiro(naval_raiz() . NAVAL_SAP_MODELO, '', NAVAL_TIPO_XLSX);
    if (isset($_GET['xlsx'])) {
        $n = (int) $_GET['xlsx'];
        $lote = naval_sap_segunda_via($n);
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
    case 'confirmar':
        $lote = naval_sap_confirmar((int) ($q['lote'] ?? 0), (string) ($q['referencia'] ?? ''), (array) naval_utilizador());
        // quem continua o trabalho é avisado no mesmo assunto do envio (envio automático)
        naval_servico('sap-envio');
        try {
            naval_sap_envio_avisar_carregado($lote);
        } catch (Throwable $e) {
            error_log('[aviso SAP carregado] ' . $e->getMessage());
        }
        naval_json(['ok' => true, 'lote' => $lote]);
    case 'envio_teste':
        // email de teste do envio automático: não emite lote nem marca documentos
        naval_servico('sap-envio');
        naval_json(['ok' => true, 'teste' => naval_sap_envio_teste((array) ($q['envio'] ?? []), (array) naval_utilizador())]);
    case 'anular':
        naval_json(['ok' => true, 'lote' => naval_sap_anular((int) ($q['lote'] ?? 0), (string) ($q['motivo'] ?? ''), (array) naval_utilizador())]);
}
throw new NavalErro('Acção desconhecida.');
