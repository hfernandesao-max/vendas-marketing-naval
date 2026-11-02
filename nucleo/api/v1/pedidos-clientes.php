<?php
/**
 * Back-office: pedidos feitos pelos clientes no código QR e cartazes A4 (nucleo/servicos/clientes-qr.php).
 *
 * GET  v1/pedidos-clientes                       pendentes e decididos nos últimos 30 dias — recurso «pedidos-clientes»
 * POST v1/pedidos-clientes {id, accao: aprovar | recusar, motivo, correccoes, csrf}   só nível G
 * GET  v1/clientes-qr?rota=1001                  clientes da rota e estado do cartaz — recurso «clientes-qr»
 * POST v1/clientes-qr {rota, clientes: [{cliente}], novo, csrf}   códigos para imprimir (novo: substitui os anteriores); só clientes
 *                                              com vendas na rota (nome e rota lidos no servidor)
 */
defined('NAVAL_API') || exit;
naval_servico('clientes-qr');
$GLOBALS['naval_api_sensivel'] = true;
$eu = (array) naval_utilizador();

if (($_GET['qr'] ?? '') === '1') {
    if (!naval_pode('clientes-qr', 'G')) throw new NavalErro('Sem permissão para os cartazes dos clientes.', 403);
    if (!naval_e_post()) {
        $rota = (string) ($_GET['rota'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
        $ag = naval_analise_agregados(date('Y-m-d', time() - NAVAL_ANALISE_HISTORICO * 86400), date('Y-m-d', time() - 86400));
        naval_json(['ok' => true, 'rota' => $rota, 'rotas' => naval_filtrar_rotas(naval_rotas_portal()), 'clientes' => naval_cqr_clientes_rota($ag, $rota)]);
    }
    $p = naval_pedido();
    naval_exigir_csrf($p);
    // cliente, nome e rota vêm sempre do servidor (vendas da rota), nunca do navegador
    $rota = (string) ($p['rota'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota) || !naval_rota_permitida($rota)) throw new NavalErro('Rota inválida.', 400);
    $pedidos = array_flip(array_map('strval', array_column(array_filter(array_slice((array) ($p['clientes'] ?? []), 0, 500), 'is_array'), 'cliente')));
    $ag = naval_analise_agregados(date('Y-m-d', time() - NAVAL_ANALISE_HISTORICO * 86400), date('Y-m-d', time() - 86400));
    $cl = array_values(array_filter(naval_cqr_clientes_rota($ag, $rota), function ($c) use ($pedidos) { return isset($pedidos[$c['cliente']]); }));
    if (!$cl) throw new NavalErro('Escolha pelo menos um cliente da rota.', 400);
    naval_json(['ok' => true, 'codigos' => naval_cqr_codigos($cl, (string) $eu['login'], !empty($p['novo']))]);
}

naval_exigir(['pedidos-clientes']);
if (!naval_e_post()) {
    $pedidos = naval_cqr_pedidos(naval_rotas());
    // encomendas por validar: cada linha comparada com o habitual do cliente (apoio à decisão do back-office)
    if (array_filter($pedidos, function ($p) { return $p['estado'] === 'pendente' && $p['tipo'] === 'encomenda'; })) {
        try {
            $ag = naval_analise_agregados(date('Y-m-d', time() - NAVAL_ANALISE_HISTORICO * 86400), date('Y-m-d', time() - 86400));
            foreach ($pedidos as &$p) {
                if ($p['estado'] !== 'pendente' || $p['tipo'] !== 'encomenda') continue;
                $p['verificacao'] = naval_cqr_verificar($p['conteudo']['linhas'], naval_cqr_sugestao($ag, $p['cliente'], $p['rota'], date('Y-m-d')));
            }
            unset($p);
        } catch (Throwable $e) {
            error_log('[pedidos verificação] ' . $e->getMessage());
        }
    }
    // IP e navegador de quem fez o pedido: só para a administração da plataforma (minimização de dados)
    if (!in_array((string) ($eu['perfil'] ?? ''), ['SADM', 'TI'], true)) {
        foreach ($pedidos as &$p) unset($p['ip'], $p['navegador']);
        unset($p);
    }
    naval_json(['ok' => true, 'pedidos' => $pedidos, 'gere' => naval_pode('pedidos-clientes', 'G')]);
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (!naval_pode('pedidos-clientes', 'G')) throw new NavalErro('O seu perfil não permite validar pedidos dos clientes.', 403);
$id = (string) ($p['id'] ?? '');
if (!preg_match('/^\d{12}-[a-f0-9]{6}$/', $id)) throw new NavalErro('Pedido desconhecido.', 404);
$antes = naval_json_ler(naval_cqr_pasta('pedidos') . '/' . $id . '.json');
if ($antes && !naval_rota_permitida($antes['rota'] ?? '')) throw new NavalErro('Pedido desconhecido.', 404);
naval_json(['ok' => true, 'pedido' => naval_cqr_decidir($id, (string) ($p['accao'] ?? ''), (string) ($p['motivo'] ?? ''),
    is_array($p['correccoes'] ?? null) ? $p['correccoes'] : [], $eu)]);
