<?php
/**
 * Conector SAP S/4HANA, só leitura, pelos serviços OData normalizados da SAP (API Business Hub):
 *   API_BILLING_DOCUMENT_SRV  A_BillingDocument (+ to_Item)  facturação de todas as empresas do dia
 * Configuração no .env (a TI do SAP cria um utilizador técnico só de leitura, com o papel de comunicação
 * SAP_COM_0120 ou equivalente):
 *   SAP_ODATA_URL        https://servidor-sap:44300   (sem barra no fim)
 *   SAP_ODATA_UTILIZADOR / SAP_ODATA_SENHA
 *   SAP_ODATA_CLIENTE    mandante (sap-client), opcional
 *   SAP_EMPRESAS         organizações de vendas e nomes, por omissão «2000=NAVAL,1000=FOODTEC,1100=HIGITEC,1300=REFITECH»
 *   SAP_TIPOS            tipos de factura e o que são, por omissão «ZBV=dinheiro,ZDP=deposito,ZF2=credito»
 * Sem configuração o conector fica «por ligar» e o Relatório de Vendas mostra só o que vem do Pinguim BO.
 */
defined('NAVAL_NUCLEO') || exit;
require_once dirname(__DIR__) . '/cliente-http.php';

function naval_sapod_configurado(): bool
{
    return naval_config('SAP_ODATA_URL') !== '' && naval_config('SAP_ODATA_UTILIZADOR') !== '';
}

/** «a=b,c=d» → [a => b, c => d]. */
function naval_sapod_mapa(string $txt, bool $maiusculas = true): array
{
    $m = [];
    foreach (explode(',', $txt) as $par) {
        $x = explode('=', $par, 2);
        if (count($x) === 2 && trim($x[0]) !== '') $m[trim($x[0])] = $maiusculas ? strtoupper(trim($x[1])) : strtolower(trim($x[1]));
    }
    return $m;
}
function naval_sapod_empresas(): array
{
    return naval_sapod_mapa(naval_config('SAP_EMPRESAS', '2000=NAVAL,1000=FOODTEC,1100=HIGITEC,1300=REFITECH'));
}
function naval_sapod_tipos(): array
{
    return naval_sapod_mapa(naval_config('SAP_TIPOS', 'ZBV=dinheiro,ZDP=deposito,ZF2=credito'), false);
}

/** Pedido OData (JSON), com paginação pelo «__next». Lança RuntimeException se o SAP não responder. */
function naval_sapod_ler(string $caminho, array $q, ?callable $http = null): array
{
    $base = rtrim(naval_config('SAP_ODATA_URL'), '/');
    $q['$format'] = 'json';
    if (naval_config('SAP_ODATA_CLIENTE') !== '') $q['sap-client'] = naval_config('SAP_ODATA_CLIENTE');
    $url = $base . $caminho . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $http = $http ?? function (string $u) {
        return naval_http_pedido('GET', $u, ['cabecalhos' => ['Accept: application/json'], 'utilizador' => naval_config('SAP_ODATA_UTILIZADOR'),
            'senha' => naval_config('SAP_ODATA_SENHA'), 'tempo' => 120]);
    };
    $out = [];
    for ($pag = 0; $url !== '' && $pag < 200; $pag++) {
        $r = $http($url);
        if ($r['corpo'] === false || $r['codigo'] !== 200) throw new RuntimeException('SAP OData: HTTP ' . $r['codigo'] . ' ' . ($r['erro'] ?? ''));
        $j = json_decode((string) $r['corpo'], true);
        $d = $j['d'] ?? null;
        if (!is_array($d)) throw new RuntimeException('SAP OData: resposta sem dados.');
        foreach ((array) ($d['results'] ?? []) as $x) $out[] = $x;
        $url = (string) ($d['__next'] ?? '');
        if ($url !== '' && strpos($url, 'http') !== 0) $url = $base . '/' . ltrim($url, '/');
    }
    return $out;
}

/** Valor OData (decimal em texto) → float. */
function naval_sapod_num($v): float
{
    return is_numeric($v) ? (float) $v : 0.0;
}

/**
 * Facturação do dia no SAP, linha a linha (como o «Sales register»): empresa, documento, tipo, depósito, artigo,
 * quantidade, preço e valor bruto. Facturas anuladas ficam de fora.
 */
function naval_sapod_facturacao(string $dia, ?callable $http = null): array
{
    $docs = naval_sapod_ler('/sap/opu/odata/sap/API_BILLING_DOCUMENT_SRV/A_BillingDocument', [
        '$filter' => "BillingDocumentDate eq datetime'" . $dia . "T00:00:00' and BillingDocumentIsCancelled eq false",
        '$expand' => 'to_Item',
        '$select' => 'BillingDocument,BillingDocumentType,SalesOrganization,BillingDocumentDate,TransactionCurrency,to_Item/BillingDocumentItem,'
            . 'to_Item/Material,to_Item/BillingDocumentItemText,to_Item/BillingQuantity,to_Item/BillingQuantityUnit,to_Item/NetAmount,to_Item/TaxAmount,'
            . 'to_Item/StorageLocation,to_Item/Plant',
    ], $http);
    $empresas = naval_sapod_empresas();
    $tipos = naval_sapod_tipos();
    $linhas = [];
    foreach ($docs as $d) {
        foreach ((array) ($d['to_Item']['results'] ?? []) as $i) {
            $q = naval_sapod_num($i['BillingQuantity'] ?? 0);
            $bruto = round(naval_sapod_num($i['NetAmount'] ?? 0) + naval_sapod_num($i['TaxAmount'] ?? 0), 2);
            $linhas[] = [
                'organizacao' => (string) ($d['SalesOrganization'] ?? ''), 'empresa' => $empresas[(string) ($d['SalesOrganization'] ?? '')] ?? (string) ($d['SalesOrganization'] ?? ''),
                'documento' => (string) ($d['BillingDocument'] ?? ''), 'tipo' => (string) ($d['BillingDocumentType'] ?? ''),
                'meio' => $tipos[(string) ($d['BillingDocumentType'] ?? '')] ?? 'outro',
                'deposito' => (string) ($i['StorageLocation'] ?? ''), 'codigo' => ltrim((string) ($i['Material'] ?? ''), '0'), 'artigo' => (string) ($i['BillingDocumentItemText'] ?? ''),
                'quantidade' => $q, 'unidade' => (string) ($i['BillingQuantityUnit'] ?? ''), 'preco' => $q ? round($bruto / $q, 2) : 0.0, 'bruto' => $bruto,
            ];
        }
    }
    return $linhas;
}

/** Resumo por empresa e meio (como o «Sales report»): {empresa: {dinheiro, deposito, credito, outro, total}}. */
function naval_sapod_resumo(array $linhas): array
{
    $out = [];
    foreach (naval_sapod_empresas() as $nome) $out[$nome] = ['dinheiro' => 0.0, 'deposito' => 0.0, 'credito' => 0.0, 'outro' => 0.0, 'total' => 0.0];
    foreach ($linhas as $l) {
        $e = $l['empresa'];
        $out[$e] = $out[$e] ?? ['dinheiro' => 0.0, 'deposito' => 0.0, 'credito' => 0.0, 'outro' => 0.0, 'total' => 0.0];
        $m = in_array($l['meio'], ['dinheiro', 'deposito', 'credito'], true) ? $l['meio'] : 'outro';
        $out[$e][$m] = round($out[$e][$m] + $l['bruto'], 2);
        $out[$e]['total'] = round($out[$e]['total'] + $l['bruto'], 2);
    }
    return $out;
}
