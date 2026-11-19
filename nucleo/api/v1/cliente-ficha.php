<?php
/**
 * Ficha do cliente (Clientes › Carteira de clientes): dados, localização, atribuição e visitas, a partir do OLAP
 * (nucleo/servicos/olap-clientes.php). As vendas e os pagamentos da ficha vêm das vendas do Pinguim BO já carregadas.
 *
 * GET v1/clientes/ficha?codigo=<código do cliente>[&actualizar=1]    recurso «clientes» (Vr: só os clientes das suas rotas)
 *   → {ok, origem: 'olap', cliente, localizacao, atribuicao, visitas, resumo_visitas, janela_dias}
 *   Sem OLAP configurado: 503; cliente que não está no OLAP ou fora do âmbito da pessoa: 404 (a mesma resposta).
 */
defined('NAVAL_API') || exit;
naval_servico('olap');
naval_servico('olap-clientes');
naval_exigir(['clientes']);

$codigo = trim((string) ($_GET['codigo'] ?? ''));
if (!naval_olapcli_codigo_valido($codigo)) throw new NavalErro('Código de cliente inválido.', 400);
if (!naval_olap_configurado()) throw new NavalErro('O servidor OLAP não está configurado.', 503);

$fresco = ($_GET['actualizar'] ?? '') === '1';
try {
    // a versão na chave muda quando as colunas lidas mudam (uma cópia antiga não serve à ficha nova)
    $bruto = naval_cache('olap-cliente-v2|' . $codigo, 600, function () use ($codigo) { return naval_olapcli_ler($codigo); }, $fresco);
} catch (Throwable $e) {
    error_log('[ficha do cliente OLAP] ' . $e->getMessage());
    throw new NavalErro('Não foi possível ler o OLAP.', 503);
}
$naoEncontrado = 'Cliente sem registo no OLAP.';
if (!$bruto) throw new NavalErro($naoEncontrado, 404);
$ficha = naval_olapcli_ficha($bruto, naval_ve_nif());

// âmbito por rotas: a rota actual no OLAP ou a rota do cliente na carteira do portal
$rotas = naval_rotas();
$rotasCarteira = [];
if ($rotas !== null) {
    foreach ((array) naval_json_ler(naval_dados_reports() . '/clientes.json') as $c) {
        if ((string) ($c['codigo'] ?? '') === $codigo) $rotasCarteira[] = (string) ($c['rota'] ?? '');
    }
}
if (!naval_olapcli_no_ambito($ficha, $rotas, $rotasCarteira)) {
    naval_registar('acesso_negado', ['recurso' => 'clientes', 'motivo' => 'rota', 'cliente' => $codigo]);
    throw new NavalErro($naoEncontrado, 404);
}
naval_json(['ok' => true, 'origem' => 'olap'] + $ficha);
