<?php
/**
 * Vendas do Pinguim BO → JSON (formato de vendas.json), com o âmbito do perfil.
 *
 * GET v1/vendas?start_date=AAAAMMDD&end_date=AAAAMMDD[&include_items=1]   vendas (e linhas de artigos)
 * GET v1/vendas?start_date=AAAAMMDD&end_date=AAAAMMDD&include_items=only   só as linhas de artigos ({artigos: [...]}):
 *                                            o portal abre com os documentos e pede as linhas quando são precisas
 * GET v1/vendas/estado                       estado da ligação ao Pinguim BO (indicador do portal)
 * GET v1/vendas/diagnostico                  diagnóstico do servidor (super administrador)
 * GET v1/vendas/estrutura?start_date=AAAAMMDD secções e campos da exportação, sem dados pessoais (super administrador)
 * Fonte: primeiro a base de dados do Pinguim BO (SQL Server, só SELECT); se não responder, a
 * exportação REST do Pinguim BO (XML).
 *
 * Em caso de falha devolve sempre JSON {ok:false, erro, detalhe} com o código HTTP adequado.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('bd');
naval_servico('pinguim');
naval_servico('vendas');
naval_servico('rotas');

// Consultas ao Pinguim BO podem demorar; no Windows o limite do PHP conta tempo real
@set_time_limit(180);
@ini_set('memory_limit', '512M');
naval_pinguim_requisitos();

// Estado da ligação: primeiro o SQL Server; se falhar, a exportação REST
if (($_GET['ping'] ?? '') === '1') {
    if (naval_bd_configurada()) {
        try {
            naval_json(naval_bd_estado() + ['erro' => null, 'hora' => date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            if (!naval_pinguim_configurado()) naval_json(['ok' => false, 'erro' => 'Sem ligação ao SQL Server: ' . $e->getMessage()]);
        }
    }
    naval_json(naval_pinguim_estado());
}

naval_exigir(array_merge(['inicio', 'vendas-artigo'], NAVAL_PAGINAS_VENDAS));
$dataAaaammdd = function (string $param, string $omissao) {
    return (string) preg_replace('/\D/', '', (string) ($_GET[$param] ?? $omissao));
};

if (($_GET['estrutura'] ?? '') === '1') {
    naval_exigir(['admin-config']);
    $de = $dataAaaammdd('start_date', date('Ymd'));
    $ate = $dataAaaammdd('end_date', $de);
    if (naval_bd_configurada()) {
        naval_servico('estrutura');
        try {
            naval_json(naval_bd_estrutura($de, $ate));
        } catch (Throwable $e) {
            error_log('[estrutura SQL] ' . $e->getMessage());   // sem base de dados: estrutura da exportação REST
        }
    }
    $bytes = 0;
    $xml = naval_pinguim_xml($de, $ate, 120, $bytes);
    naval_json(['periodo' => "$de a $ate"] + naval_pinguim_estrutura($xml, $bytes));
}
if (($_GET['diag'] ?? '') === '1') {
    naval_exigir(['admin-config']);
    $bd = ['configurada' => naval_bd_configurada(), 'drivers_pdo' => class_exists('PDO') ? PDO::getAvailableDrivers() : []];
    try {
        if ($bd['configurada']) $bd += naval_bd_estado();
    } catch (Throwable $e) {
        $bd += ['ok' => false, 'erro' => $e->getMessage()];
    }
    naval_json(['base_de_dados' => $bd] + naval_pinguim_diagnostico());
}

$de = (string) ($_GET['start_date'] ?? '');
$ate = (string) ($_GET['end_date'] ?? '');
$modo = (string) ($_GET['include_items'] ?? '');
$soArtigos = $modo === 'only';
$itens = $modo === '1';
if (!preg_match('/^\d{8}$/', $de) || !preg_match('/^\d{8}$/', $ate)) {
    throw new NavalErro('Datas inválidas.', 400, ['detalhe' => 'Use start_date e end_date no formato AAAAMMDD.']);
}
naval_validar_periodo($de, $ate);

// Cache no servidor (fase 4): o mesmo período não volta a ser consultado durante 2 min (se inclui hoje)
// ou 30 min (período fechado); «fresco=1» (botão Actualizar) consulta de novo. Documentos e linhas de artigos têm
// entradas próprias (as linhas só se consultam quando pedidas). O âmbito do perfil (rotas, NIF) aplica-se depois,
// a cada pedido.
$prom = naval_promotores();
$base = $de . '|' . $ate . '|' . hash('sha256', (string) json_encode($prom));
$chaves = [];
if (!$soArtigos) $chaves['vendas'] = 'vendas2|' . $base;
if ($itens || $soArtigos) $chaves['artigos'] = 'artigos|' . $base;
$tempo = naval_cache_periodo($ate);
$fresco = ($_GET['fresco'] ?? '') === '1';
$codigo = [__FILE__, dirname(__DIR__, 2) . '/servicos/vendas.php'];
$metas = function () use ($chaves, $tempo) {
    $m = [];
    foreach ($chaves as $k) {
        $x = naval_cache_meta($k, $tempo);
        if ($x === null) return null;
        $m[] = $x;
    }
    return $m;
};
// Pedido condicional: se as entradas da cache não mudaram, 304 sem ler nem converter os dados
if (!$fresco) naval_etag_antecipado($metas(), $codigo);

$d = ['vendas' => [], 'artigos' => []];
if (isset($chaves['vendas'])) {
    $d['vendas'] = naval_cache($chaves['vendas'], $tempo, function () use ($de, $ate, $prom) {
        // Prioridade 1: base de dados do Pinguim BO; prioridade 2: exportação REST
        $x = naval_vendas_sql($de, $ate, $prom, false);
        if ($x === null) $x = naval_vendas_do_xml(naval_pinguim_xml($de, $ate), $prom, false);
        return $x['vendas'];
    }, $fresco);
}
if (isset($chaves['artigos'])) {
    $d['artigos'] = naval_cache($chaves['artigos'], $tempo, function () use ($de, $ate, $prom) {
        $x = naval_vendas_artigos_sql($de, $ate, $prom);
        if ($x === null) $x = naval_vendas_do_xml(naval_pinguim_xml($de, $ate), $prom, true)['artigos'];
        return $x;
    }, $fresco);
}
// ETag da resposta a partir das entradas agora gravadas: o pedido seguinte pode acabar logo no 304
naval_etag_antecipado($metas(), $codigo);
$d = naval_vendas_ambito($d, naval_rotas(), naval_ve_nif(), naval_ve_vendas());
if ($soArtigos) naval_json(['artigos' => $d['artigos']]);
naval_json($itens ? $d : $d['vendas']);
