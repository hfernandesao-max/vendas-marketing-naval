<?php
/**
 * Origem do stock de cada viatura RTM: de que armazém principal veio cada artigo, para determinar a
 * SAP storage location das vendas (determinação automática, como a «storage location determination»
 * do SAP: a localização da venda é a do armazém que forneceu a mercadoria).
 *
 * Armazéns principais (configuráveis em Correspondência com o SAP):
 *   1150  RTM REFITEC/FOODTEC PIV – FOOD     → RTM1
 *   1151  RTM HIGITEC PIV – NON FOOD         → RTM2
 *
 * Fontes, por ordem:
 *   1. Transferências registadas no Pinguim BO (DOCMOV): documentos com saída de um armazém principal e
 *      entrada num armazém de viatura (100010NN), só com SELECT;
 *   2. Saldos de stock (stock.json / dbo.GET_STOCK): os artigos que saíram de cada armazém principal
 *      (saldo diferente de zero) e que estão ou estiveram em cada viatura.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
if (is_file(__DIR__ . '/stock.php')) require_once __DIR__ . '/stock.php';   // serviço de stock, quando o projecto o tem

const NAVAL_ARMAZENS_PRINCIPAIS = [
    '1150' => ['nome' => 'RTM REFITEC/FOODTEC PIV – FOOD', 'local' => 'RTM1'],
    '1151' => ['nome' => 'RTM HIGITEC PIV – NON FOOD', 'local' => 'RTM2'],
];

/** Rota de um armazém de viatura: código 100010NN ou «… 10NN …» no nome. '' se não for de viatura. */
function naval_origem_rota(string $codigo, string $nome = ''): string
{
    if (preg_match('/^100010(\d{2})$/', trim($codigo), $m)) return '10' . $m[1];
    if ($nome !== '' && preg_match('/\b(10\d{2})\b/', $nome, $m) && !preg_match('/^115\d$/', trim($codigo))) return $m[1];
    return '';
}

/**
 * Junta movimentos [rota, armazém principal, artigo, quantidade] numa decisão por rota e por artigo.
 * Cada artigo fica com o armazém de onde veio mais quantidade; cada rota com a localização predominante.
 */
function naval_origem_montar(array $movimentos, array $principais, string $fonte, string $periodo): array
{
    $porRotaArt = [];
    $porArt = [];
    foreach ($movimentos as [$rota, $arm, $art, $q]) {
        if (!isset($principais[$arm]) || $rota === '' || $art === '') continue;
        $q = abs((float) $q);
        if ($q <= 0) continue;
        $porRotaArt[$rota][$art][$arm] = ($porRotaArt[$rota][$art][$arm] ?? 0) + $q;
        $porArt[$art][$arm] = ($porArt[$art][$arm] ?? 0) + $q;
    }
    $maior = function (array $q) { arsort($q); return (string) key($q); };
    $rotas = [];
    foreach ($porRotaArt as $rota => $arts) {
        $porLocal = [];
        $porArmazem = [];
        $artigos = [];
        foreach ($arts as $art => $q) {
            $arm = $maior($q);
            $local = $principais[$arm]['local'];
            $artigos[$art] = $local;
            $porLocal[$local] = ($porLocal[$local] ?? 0) + array_sum($q);
            foreach ($q as $a => $v) $porArmazem[$a] = ($porArmazem[$a] ?? 0) + $v;
        }
        arsort($porLocal);
        reset($porLocal);
        $rotas[(string) $rota] = ['local' => (string) key($porLocal), 'locais' => $porLocal, 'armazens' => $porArmazem, 'artigos' => $artigos];
    }
    ksort($rotas);
    $artigos = [];
    foreach ($porArt as $art => $q) $artigos[$art] = $principais[$maior($q)]['local'];
    return ['fonte' => $fonte, 'periodo' => $periodo, 'principais' => $principais, 'rotas' => $rotas, 'artigos' => $artigos];
}

/** 1. Transferências do Pinguim BO (SELECT). null se não houver base de dados ou a consulta falhar. */
function naval_origem_transferencias(array $principais, string $desde): ?array
{
    if (!naval_bd_configurada()) return null;
    $cods = array_keys($principais);
    $marcas = [];
    $params = [':desde' => $desde];
    foreach (array_values($cods) as $i => $c) {
        $marcas[] = ':p' . $i;
        $params[':p' . $i] = (string) $c;
    }
    try {
        $linhas = naval_bd_consultar("
            SELECT EV.CODE AS VIATURA, EV.DESCRIPTION AS NOME_VIATURA, EP.CODE AS PRINCIPAL, PR.CODE AS ARTIGO,
                   COUNT(DISTINCT MV.DOCHEADER_ID) AS DOCUMENTOS,
                   CONVERT(VARCHAR(10), MIN(DH.DOC_DATE), 120) AS DE, CONVERT(VARCHAR(10), MAX(DH.DOC_DATE), 120) AS ATE
            FROM DOCMOV MV
            INNER JOIN DOCMOV MP ON MP.DOCHEADER_ID = MV.DOCHEADER_ID AND MP.PRODUCT_ID = MV.PRODUCT_ID
                                AND MP.DELETED = 0 AND MP.WAREHOUSE_ID <> MV.WAREHOUSE_ID
            INNER JOIN DOCHEADER DH ON DH.ID = MV.DOCHEADER_ID AND DH.DELETED = 0
            INNER JOIN ENTITY EV ON EV.ID = MV.WAREHOUSE_ID
            INNER JOIN ENTITY EP ON EP.ID = MP.WAREHOUSE_ID
            INNER JOIN PRODUCT PR ON PR.ID = MV.PRODUCT_ID
            WHERE MV.DELETED = 0 AND DH.DOC_DATE >= :desde AND EP.CODE IN (" . implode(', ', $marcas) . ")
            GROUP BY EV.CODE, EV.DESCRIPTION, EP.CODE, PR.CODE", $params);
    } catch (Throwable $e) {
        error_log('[origem do stock SQL] ' . $e->getMessage());
        return null;
    }
    $mov = [];
    $de = null;
    $ate = null;
    foreach ($linhas as $l) {
        $rota = naval_origem_rota((string) $l['VIATURA'], (string) ($l['NOME_VIATURA'] ?? ''));
        if ($rota === '') continue;
        $mov[] = [$rota, trim((string) $l['PRINCIPAL']), trim((string) $l['ARTIGO']), (float) $l['DOCUMENTOS']];
        $de = $de === null || $l['DE'] < $de ? $l['DE'] : $de;
        $ate = $ate === null || $l['ATE'] > $ate ? $l['ATE'] : $ate;
    }
    if (!$mov) return null;
    return naval_origem_montar($mov, $principais, 'transferências de stock do Pinguim BO (documentos)', naval_data_pt((string) $de) . ' a ' . naval_data_pt((string) $ate));
}

/** 2. Saldos de stock: artigo que saiu do armazém principal (saldo ≠ 0) e está/esteve na viatura. */
function naval_origem_saldos(array $principais, ?array $stock): ?array
{
    if (!$stock) return null;
    $saiu = [];
    foreach ((array) ($stock['linhas_armazens'] ?? []) as $l) {
        $arm = trim((string) ($l['cod_armazem'] ?? ''));
        if (isset($principais[$arm]) && (float) ($l['quantidade'] ?? 0) != 0) {
            $art = trim((string) ($l['codigo'] ?? ''));
            $saiu[$art][$arm] = ($saiu[$art][$arm] ?? 0) + abs((float) $l['quantidade']);
        }
    }
    $mov = [];
    $datas = [];
    foreach ((array) ($stock['linhas_carrinhas'] ?? []) as $l) {
        $q = (float) ($l['quantidade'] ?? 0);
        $art = trim((string) ($l['codigo'] ?? ''));
        if ($q == 0 || empty($saiu[$art])) continue;
        // a quantidade da viatura reparte-se pelos armazéns principais de onde o artigo saiu
        $tot = array_sum($saiu[$art]);
        foreach ($saiu[$art] as $arm => $s) $mov[] = [(string) ($l['rota'] ?? ''), $arm, $art, abs($q) * $s / $tot];
        if (!empty($l['data'])) $datas[$l['data']] = true;
    }
    if (!$mov) return null;
    ksort($datas);
    $d = array_keys($datas);
    $periodo = $d ? naval_data_pt($d[0]) . ' a ' . naval_data_pt(end($d)) : '';
    return naval_origem_montar($mov, $principais, 'saldos de stock dos armazéns principais e das viaturas', $periodo);
}

/**
 * Determinação para o ficheiro do SAP: transferências; sem elas, saldos de stock; sem nada, vazio.
 * Guardada 15 minutos (a leitura do stock é pesada); muda logo se os armazéns principais mudarem.
 */
function naval_origem_stock(array $principais, int $dias = 90): array
{
    $cache = naval_pasta('sap') . '/origens.json';
    $c = naval_json_ler($cache);
    if ($c && ($c['chave'] ?? '') === md5((string) json_encode($principais)) && (int) ($c['em'] ?? 0) > time() - 900) return $c['dados'];
    $r = naval_origem_calcular($principais, $dias);
    naval_json_gravar($cache, ['chave' => md5((string) json_encode($principais)), 'em' => time(), 'dados' => $r]);
    return $r;
}

function naval_origem_calcular(array $principais, int $dias): array
{
    $r = naval_origem_transferencias($principais, date('Y-m-d', time() - $dias * 86400));
    if ($r) return $r;
    $stock = null;
    if (function_exists('naval_stock')) $stock = naval_stock(date('Y-m-d'));
    if (!$stock) $stock = naval_json_ler(naval_raiz() . '/reports/data/stock.json') ?: null;   // projecto sem o serviço de stock
    $r = naval_origem_saldos($principais, $stock);
    return $r ?: ['fonte' => '', 'periodo' => '', 'principais' => $principais, 'rotas' => [], 'artigos' => []];
}
