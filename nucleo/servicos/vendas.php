<?php
/**
 * Vendas do RTM a partir da exportação do Pinguim BO: documentos FR/FT, pagamentos e linhas de artigos,
 * e o âmbito de cada perfil (rotas, NIF e Logística só com quantidades).
 */
defined('NAVAL_NUCLEO') || exit;

/** Meios de pagamento sem descrição no XML; os nomes que vêm do Pinguim BO prevalecem. */
const NAVAL_MEIOS_PAGAMENTO = ['NU' => 'Numerário', 'MC' => 'Multicaixa', 'TB' => 'Transferência Bancária', 'CS' => 'Advance'];

/** Páginas que mostram vendas: sem nenhuma, o perfil só vê quantidades por artigo. */
const NAVAL_PAGINAS_VENDAS = ['vendas-promotor', 'vendas-dia', 'vendas-cliente', 'ranking', 'documentos', 'pagamentos', 'auditoria', 'vendas-marca', 'plano-visitas', 'sap-exportar', 'pendentes'];

/**
 * Converte a exportação em vendas (formato de vendas.json) e, com $itens, nas linhas de artigos.
 * $promotores: {rota: promotor}. Devolve ['vendas' => [...], 'artigos' => [...]].
 */
function naval_vendas_do_xml(SimpleXMLElement $xml, array $promotores, bool $itens): array
{
    $entidades = [];
    if (isset($xml->entities)) {
        foreach ($xml->entities->entity as $ent) {
            $entidades[(string) $ent->code] = ['desc' => (string) $ent->description, 'tax' => (string) $ent->tax_number];
        }
    }
    $meios = NAVAL_MEIOS_PAGAMENTO;
    if (isset($xml->payment_methods)) {
        foreach ($xml->payment_methods->payment_method as $pm) {
            $code = (string) $pm->code;
            $desc = trim((string) $pm->description);
            if ($code !== '' && $desc !== '') $meios[$code] = $desc;
        }
    }

    $vendas = [];
    $artigos = [];
    if (!isset($xml->transactions)) return ['vendas' => $vendas, 'artigos' => $artigos];
    foreach ($xml->transactions->transaction as $tx) {
        $tipo = (string) $tx->document_type;
        // Apenas documentos de venda (Factura-recibo / Factura), sem os anulados
        if (($tipo !== 'FR' && $tipo !== 'FT') || (string) $tx->deleted === '1') continue;

        $rota = (string) $tx->store_code;
        $entidade = (string) $tx->entity_code;
        $total = (float) (string) $tx->total;
        $data = (string) $tx->transaction_date;
        $doc = (string) $tx->document_nr;
        $nif = (string) $tx->tax_number;
        $cliente = $entidades[$entidade]['desc'] ?? '';
        if (!$cliente) $cliente = $nif === '999999999' ? 'Consumidor Final' : '';

        $pagamentos = naval_vendas_pagamentos($tx, $total, $meios);
        $pago = round(array_sum(array_column($pagamentos, 'valor')), 2);
        $promotor = $promotores[$rota] ?? $rota;

        $vendas[] = [
            'chave' => $doc,
            'data' => $data,
            'hora' => substr((string) $tx->transaction_time, 0, 5),
            'rota' => $rota,
            'promotor' => $promotor,
            'tipo' => $tipo === 'FR' ? 'Factura-recibo' : 'Factura',
            'documento' => $doc,
            'cliente' => $cliente,
            'cliente_codigo' => $entidade,
            'nif' => $nif ?: ($entidades[$entidade]['tax'] ?? ''),
            'liquidado' => $pago >= round($total, 2),
            'total' => $total,
            'valor_pago' => $pago,
            'pagamentos' => $pagamentos,
            'meio_pagamento' => $pagamentos ? implode(', ', array_column($pagamentos, 'meio')) : null,
        ];

        if ($itens && isset($tx->detail)) {
            $n = 0;
            foreach ($tx->detail->line as $l) {
                $n++;
                $artigos[] = [
                    'chave' => $doc . '|' . $n,
                    'data' => $data,
                    'rota' => $rota,
                    'promotor' => $promotor,
                    'documento' => $doc,
                    'codigo' => (string) $l->product_code,
                    'artigo' => (string) $l->product_description,
                    'unidade' => (string) $l->uom,
                    'quantidade' => (float) (string) $l->quantity,
                    'preco_unitario' => (float) (string) $l->base_amount,
                    'valor_sem_iva' => (float) (string) $l->total,
                    'desconto' => (float) (string) $l->discount_amount,
                    'iva' => (float) (string) $l->tax_amount,
                    'total' => (float) (string) $l->total + (float) (string) $l->tax_amount,
                ];
            }
        }
    }
    return ['vendas' => $vendas, 'artigos' => $artigos];
}

/**
 * Pagamentos de um documento (rodapé), por meio de pagamento. O troco sai do numerário (ou, sem
 * numerário, do meio com maior valor), para o recebido nunca exceder o total da venda.
 */
function naval_vendas_pagamentos(SimpleXMLElement $tx, float $total, array $meios): array
{
    $porMeio = [];
    if (isset($tx->footer)) {
        foreach ($tx->footer->line as $fl) {
            $valor = (float) (string) $fl->amount;
            if ($valor == 0.0) continue;
            $k = trim((string) $fl->payment_code);
            $k = $k !== '' ? $k : '?';
            $porMeio[$k] = ($porMeio[$k] ?? 0.0) + $valor;
        }
    }
    $excesso = round(array_sum($porMeio) - $total, 2);
    if ($excesso > 0 && $total > 0) {
        $k = isset($porMeio['NU']) ? 'NU' : array_search(max($porMeio), $porMeio, true);
        $porMeio[$k] = max(0.0, $porMeio[$k] - $excesso);
    }
    $out = [];
    foreach ($porMeio as $k => $v) {
        if (round($v, 2) == 0.0) continue;
        $out[] = ['codigo' => (string) $k, 'meio' => $meios[$k] ?? ($k === '?' ? 'Sem meio indicado' : (string) $k), 'valor' => round($v, 2)];
    }
    return $out;
}

/**
 * Âmbito do utilizador: só as suas rotas (SUP e PRO), NIF só para quem o pode ver; sem páginas
 * de vendas (por exemplo, Logística) só quantidades por artigo, sem valores nem documentos.
 * $rotas: rotas permitidas (null = todas); $veNif; $veVendas.
 */
function naval_vendas_ambito(array $d, ?array $rotas, bool $veNif, bool $veVendas): array
{
    if ($rotas !== null) {
        $dentro = function ($x) use ($rotas) { return in_array((string) $x['rota'], $rotas, true); };
        $d['vendas'] = array_values(array_filter($d['vendas'], $dentro));
        $d['artigos'] = array_values(array_filter($d['artigos'], $dentro));
    }
    if (!$veNif) {
        foreach ($d['vendas'] as $i => $v) $d['vendas'][$i]['nif'] = '';
    }
    if (!$veVendas) {
        $d['vendas'] = [];
        foreach ($d['artigos'] as $i => $a) {
            foreach (['preco_unitario', 'valor_sem_iva', 'desconto', 'iva', 'total'] as $k) $d['artigos'][$i][$k] = null;
            $d['artigos'][$i]['documento'] = '';
        }
    }
    return $d;
}

/** O utilizador actual vê vendas (alguma página de vendas na matriz)? */
function naval_ve_vendas(): bool
{
    foreach (NAVAL_PAGINAS_VENDAS as $rec) if (naval_pode($rec)) return true;
    return false;
}
