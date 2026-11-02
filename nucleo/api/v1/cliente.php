<?php
/**
 * Página pública do código QR do cliente (c/?k=<código>), sem conta: ver nucleo/servicos/clientes-qr.php.
 *
 * GET  v1/cliente?k=<código>     nome do estabelecimento, rota, artigos que se podem encomendar e texto do consentimento
 * POST v1/cliente {k, tipo: encomenda | dados | consentimento, dados, site}   pedido pendente de validação → {ok, id}
 *      «site» é um campo-armadilha escondido: um robô preenche-o e o pedido é ignorado (resposta igual, sem gravar).
 */
defined('NAVAL_API') || exit;
naval_servico('clientes-qr');
$GLOBALS['naval_api_sensivel'] = true;

$artigos = function (array $cl) {
    return naval_cache('cqr-artigos|' . $cl['rota'] . '|' . date('Y-m-d'), 3600, function () use ($cl) {
        try {
            return naval_cqr_artigos(naval_analise_agregados(date('Y-m-d', time() - 90 * 86400), date('Y-m-d', time() - 86400)), $cl['rota']);
        } catch (Throwable $e) {
            error_log('[cliente QR artigos] ' . $e->getMessage());
            return [];
        }
    });
};

if (!naval_e_post()) {
    $cl = naval_cqr_cliente((string) ($_GET['k'] ?? ''));
    if (!$cl) throw new NavalErro('Código inválido ou substituído. Peça um cartaz novo ao seu promotor.', 404);
    // sugestão pelas compras habituais do cliente (só quantidades: sem preços, valores nem datas)
    $sug = naval_cache('cqr-sugestao|' . $cl['cliente'] . '|' . date('Y-m-d'), 3600, function () use ($cl) {
        try {
            $hoje = date('Y-m-d');
            return naval_cqr_sugestao(naval_analise_agregados(date('Y-m-d', time() - NAVAL_ANALISE_HISTORICO * 86400), date('Y-m-d', time() - 86400)), $cl['cliente'], $cl['rota'], $hoje);
        } catch (Throwable $e) {
            error_log('[cliente QR sugestão] ' . $e->getMessage());
            return ['habituais' => [], 'cruzada' => []];
        }
    });
    // página pública (o cartaz está à vista de todos): só a quantidade sugerida e se está na altura de repor; nunca o número
    // de compras nem o intervalo entre compras (padrões do negócio do cliente). QR_SUGESTAO=0 desliga a sugestão.
    $sug = naval_config('QR_SUGESTAO', '1') === '0' ? ['habituais' => [], 'cruzada' => []] : [
        'habituais' => array_map(function ($h) { return ['codigo' => $h['codigo'], 'quantidade' => $h['quantidade'], 'repor' => $h['repor']]; }, $sug['habituais']),
        'cruzada' => $sug['cruzada']];
    naval_json(['ok' => true, 'nome' => $cl['nome'], 'rota' => $cl['rota'], 'artigos' => $artigos($cl), 'sugestao' => $sug, 'consentimento' => NAVAL_CQR_TEXTO_CONSENTIMENTO]);
}
$p = naval_pedido();
if (trim((string) ($p['site'] ?? '')) !== '') naval_json(['ok' => true, 'id' => 'recebido']);   // robô: sem gravar
$cl = naval_cqr_cliente((string) ($p['k'] ?? ''));
if (!$cl) throw new NavalErro('Código inválido ou substituído. Peça um cartaz novo ao seu promotor.', 404);
$r = naval_cqr_pedir($cl['codigo'], (string) ($p['tipo'] ?? ''), is_array($p['dados'] ?? null) ? $p['dados'] : [], naval_ip_cliente(),
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $artigos($cl));
naval_json(['ok' => true] + $r);
