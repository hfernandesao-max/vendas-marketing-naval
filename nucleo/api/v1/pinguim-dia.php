<?php
/**
 * Abertura e fecho do dia de cada rota no Pinguim BO (ver nucleo/servicos/pinguim-dia.php), só SELECT.
 * GET ?de=AAAA-MM-DD&ate=AAAA-MM-DD (por omissão os últimos 7 dias) →
 *   {disponivel, de, ate, agora, limite, linhas: [{rota, dia, aberto_em, abertura, fechado_em, documento, fecho, …}],
 *    contagens, excepcoes}
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('bd');
naval_servico('pinguim-dia');
naval_servico('rotas');
naval_exigir(['sincronizacao']);

if (!naval_bd_configurada()) {
    naval_json(['disponivel' => false, 'erro' => 'A base de dados do Pinguim BO não está configurada no servidor (.env: DB_HOST, DB_NAME, DB_USER, DB_PASS).']);
}
$ate = naval_data_valida($_GET['ate'] ?? null) ? (string) $_GET['ate'] : date('Y-m-d');
$de = naval_data_valida($_GET['de'] ?? null) ? (string) $_GET['de'] : date('Y-m-d', strtotime($ate . ' 12:00') - 6 * 86400);
if ($de > $ate || strtotime($ate) - strtotime($de) > 62 * 86400) throw new NavalErro('Período inválido (até 62 dias).', 400);
try {
    $d = naval_cache('pinguim-dia-' . $de . '-' . $ate, 120, function () use ($de, $ate) { return naval_pdia_ler($de, $ate); }, ($_GET['fresco'] ?? '') === '1');
} catch (Throwable $e) {
    error_log('[pinguim dia] ' . $e->getMessage());
    naval_json(['disponivel' => false, 'erro' => 'Sem ligação à base de dados do Pinguim BO.'] + naval_detalhe($e->getMessage()));
    exit;
}
$d['linhas'] = naval_filtrar_rotas($d['linhas']);
naval_json(['disponivel' => true] + $d + naval_pdia_resumo($d['linhas']));
