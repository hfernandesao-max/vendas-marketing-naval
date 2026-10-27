<?php
/**
 * Sincronização dos smartphones RTM com o Pinguim BO (ver nucleo/servicos/sincronizacao.php).
 * GET → {disponivel, agora, limites, rotas: [{rota, promotor, ultima, minutos, hoje, sete_dias, dias, estado}]}
 */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('sincronizacao');
naval_exigir(['sincronizacao']);

if (!naval_bd_configurada()) {
    naval_json(['disponivel' => false, 'erro' => 'A base de dados do Pinguim BO não está configurada no servidor (.env: DB_HOST, DB_NAME, DB_USER, DB_PASS).']);
}
try {
    $d = naval_sincronizacao();
} catch (Throwable $e) {
    error_log('[sincronizacao] ' . $e->getMessage());
    naval_json(['disponivel' => false, 'erro' => 'Sem ligação à base de dados do Pinguim BO.', 'detalhe' => $e->getMessage()]);
    exit;
}
$d['rotas'] = naval_filtrar_rotas($d['rotas']);
naval_json(['disponivel' => true] + $d);
