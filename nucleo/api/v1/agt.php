<?php
/**
 * Comunicação AGT (ver nucleo/servicos/agt.php).
 * GET v1/agt?start_date=AAAAMMDD&end_date=AAAAMMDD
 *   → {disponivel, de, ate, documentos: [{rota, tipo, documento, total, data, data_fiscal, anulado, motivo_anulacao,
 *      entidade, pedido_agt, estado}]}
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('agt');
naval_exigir(['agt']);
@set_time_limit(180);

if (!naval_bd_configurada()) {
    naval_json(['disponivel' => false, 'erro' => 'A base de dados do Pinguim BO não está configurada no servidor (.env: DB_HOST, DB_NAME, DB_USER, DB_PASS).']);
}
$hoje = date('Ymd');
$de = (string) preg_replace('/\D/', '', (string) ($_GET['start_date'] ?? $hoje));
$ate = (string) preg_replace('/\D/', '', (string) ($_GET['end_date'] ?? $de));
if (!preg_match('/^\d{8}$/', $de) || !preg_match('/^\d{8}$/', $ate)) throw new NavalErro('Datas inválidas.', 400, ['detalhe' => 'Use start_date e end_date no formato AAAAMMDD.']);
naval_validar_periodo($de, $ate);
try {
    $d = naval_cache('agt|' . $de . '|' . $ate, naval_cache_periodo($ate), function () use ($de, $ate) { return naval_agt($de, $ate); }, ($_GET['fresco'] ?? '') === '1');
} catch (Throwable $e) {
    error_log('[agt] ' . $e->getMessage());
    naval_json(['disponivel' => false, 'erro' => 'Sem ligação à base de dados do Pinguim BO.'] + naval_detalhe($e->getMessage()));
    exit;
}
$d['documentos'] = naval_filtrar_rotas($d['documentos']);
naval_json(['disponivel' => true] + $d);
