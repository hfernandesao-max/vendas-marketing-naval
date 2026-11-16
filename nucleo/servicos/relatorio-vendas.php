<?php
/**
 * Consolidado diário de vendas RTM da Naval (Relatório de Vendas diário do Marketing): junta num só documento o que hoje
 * vem em vários ficheiros (resumo de vendas, registo de vendas, folha de caixa e stock), lido das bases de dados e nunca
 * de Excel. Âmbito: só as vendas RTM (Pinguim BO), facturadas pela Naval — a única empresa que factura no RTM.
 *
 * Segue a prática do sector para o fecho diário de vendas e tesouraria (reconciliação vendas × cobranças × caixa × banco,
 * «proof totals», gestão por excepção, separação de funções e fotografia inalterável com assinaturas):
 *   - resumo no formato do SALES REPORT: vendas (brutas, notas de crédito, líquidas) por meio de cobrança, Multicaixa e
 *     depósitos por banco e conta, e caixa (saldo inicial + dinheiro entregue − depósitos = saldo final);
 *   - conferências automáticas com resultado «confere» / «diferença» (tolerância 0,00 Kz: os valores são exactos, a
 *     duas casas decimais) e a lista de excepções primeiro (o que precisa de explicação), por promotor;
 *   - os nomes dos promotores vêm sempre da tabela de rotas (um só nome por promotor em todo o documento).
 *
 * Fecho do dia antes do relatório (prática de «close readiness» / controlo de completude dos dados): o relatório só
 * se gera quando todas as fontes do dia estão completas e fechadas:
 *   1. Pinguim BO: todos os telemóveis das rotas com vendas sincronizaram depois do fim das vendas do dia (calendário de
 *      trabalho: 15:00 de segunda a sexta, 13:00 ao sábado; RELATORIO_HORA_FECHO no .env substitui-o);
 *   2. conciliação de todas as carrinhas com movimento fechada (contagem, validação e aprovações);
 *   3. vendas do dia no SAP: todos os documentos estão num ficheiro de carregamento marcado «carregado no SAP»;
 *   4. folha de caixa do dia validada e aprovada nos dois níveis;
 * Uma vez gerado, o relatório fica congelado (fotografia dos dados, com impressão digital SHA-256 e número de versão):
 * o PDF e o Excel saem sempre da mesma fotografia. Uma correcção posterior gera uma nova versão, com motivo.
 * Ao gerar, segue por correio um resumo para quem tem acesso ao relatório na matriz (sem os perfis limitados a rotas),
 * com a ligação para o portal (RELATORIO_VENDAS_EMAIL=0 no .env desliga o envio).
 * Ficheiros: dados-privados/relatorio-vendas/AAAA-MM-DD/v<n>.json.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/caixa.php';

/** Controlos de prontidão do dia, a partir das fontes já lidas (função pura, testável). */
function naval_rv_controlos(string $dia, array $f): array
{
    $c = [];
    // 1. sincronização dos telemóveis
    $rotasVendas = array_values(array_unique(array_map(function ($v) { return (string) $v['rota']; }, (array) ($f['vendas'] ?? []))));
    sort($rotasVendas);
    if ($f['vendas'] === null) {
        $c['pinguim'] = ['estado' => 'erro', 'detalhe' => 'Sem ligação ao Pinguim BO.'];
    } else {
        $hora = naval_rv_hora_fecho($dia);
        $limite = $dia . ' ' . $hora . ':00';
        $ultima = [];
        foreach ((array) ($f['sync'] ?? []) as $s) $ultima[(string) $s['rota']] = (string) ($s['ultima'] ?? '');
        $faltam = array_values(array_filter($rotasVendas, function ($r) use ($ultima, $limite) { return ($ultima[$r] ?? '') < $limite; }));
        // dia sem vendas: só é «completo» se as carrinhas também não venderam nada (feriado ou dia sem actividade);
        // carrinhas com vendas e o Pinguim BO sem documentos é uma falha de dados, não um dia vazio
        $vendeuCarrinha = [];
        foreach ((array) ($f['carrinhas'] ?? []) as $r) {
            foreach ((array) ($r['linhas'] ?? []) as $l) if ((float) ($l['vendido'] ?? 0) > 0) $vendeuCarrinha[(string) $r['rota']] = true;
        }
        if ($faltam) {
            $c['pinguim'] = ['estado' => 'pendente', 'detalhe' => 'Rotas sem sincronizar depois das ' . $hora . ': ' . implode(', ', $faltam) . '.', 'rotas' => $faltam];
        } elseif (!$rotasVendas && $vendeuCarrinha) {
            $c['pinguim'] = ['estado' => 'erro', 'detalhe' => 'Sem documentos de venda no Pinguim BO, mas as carrinhas ' . implode(', ', array_keys($vendeuCarrinha))
                . ' venderam artigos: confirme a sincronização e a ligação.', 'rotas' => array_keys($vendeuCarrinha)];
        } elseif (!$rotasVendas) {
            $c['pinguim'] = ['estado' => 'ok', 'detalhe' => 'Sem vendas neste dia (feriado ou dia sem actividade).', 'sem_vendas' => true];
        } else {
            $c['pinguim'] = ['estado' => 'ok', 'detalhe' => count($rotasVendas) . ' rotas com vendas, todas sincronizadas depois das ' . $hora . '.'];
        }
    }
    // 2. conciliação das carrinhas
    if ($f['carrinhas'] === null) {
        $c['conciliacao'] = ['estado' => 'erro', 'detalhe' => 'Não foi possível ler a conciliação das carrinhas.'];
    } else {
        // só contam as carrinhas que venderam no dia (artigos vendidos na conciliação ou documentos no Pinguim BO);
        // carrinhas paradas, sem vendas, não impedem o relatório
        $comVendas = array_flip($rotasVendas);
        $abertas = [];
        $contam = 0;
        foreach ($f['carrinhas'] as $r) {
            $vendeu = isset($comVendas[(string) $r['rota']]);
            foreach ((array) ($r['linhas'] ?? []) as $l) if ((float) ($l['vendido'] ?? 0) > 0) $vendeu = true;
            if (!$vendeu) continue;
            $contam++;
            if (($r['resumo']['estado'] ?? '') !== 'aprovada') $abertas[] = $r['rota'];
        }
        $c['conciliacao'] = $abertas
            ? ['estado' => 'pendente', 'detalhe' => 'Carrinhas com vendas por fechar: ' . implode(', ', $abertas) . '.', 'rotas' => $abertas]
            : ['estado' => 'ok', 'detalhe' => $contam ? $contam . ' carrinhas com vendas conciliadas e fechadas.' : 'Nenhuma carrinha vendeu neste dia.'];
    }
    // 3. vendas carregadas no SAP
    if ($f['vendas'] !== null) {
        $lotes = (array) ($f['lotes'] ?? []);
        $estadoLote = array_column($lotes, 'estado', 'numero');
        $emitidos = naval_sap_exportados_rv($lotes);
        $fora = 0;
        $porCarregar = [];
        foreach ($f['vendas'] as $v) {
            $l = $emitidos[(string) $v['documento']] ?? null;
            if ($l === null) $fora++;
            elseif (($estadoLote[$l] ?? '') !== 'carregado') $porCarregar[$l] = true;
        }
        $c['sap_ficheiro'] = !$f['vendas'] ? ['estado' => 'ok', 'detalhe' => 'Sem documentos de venda para carregar no SAP neste dia.'] : ($fora
            ? ['estado' => 'pendente', 'detalhe' => $fora . ' documento(s) ainda sem ficheiro para o SAP.']
            : ($porCarregar ? ['estado' => 'pendente', 'detalhe' => 'Ficheiro(s) por confirmar como carregados no SAP: lote ' . implode(', ', array_keys($porCarregar)) . '.']
                : ['estado' => 'ok', 'detalhe' => count($f['vendas']) . ' documentos carregados no SAP.']));
    }
    // 4. caixa
    $cx = $f['caixa'];
    $c['caixa'] = ($cx['estado'] ?? '') === 'aprovada'
        ? ['estado' => 'ok', 'detalhe' => 'Fecho de caixa aprovado por ' . ($cx['aprovada_por'] ?? '') . '.']
        : ['estado' => 'pendente', 'detalhe' => ($cx['estado'] ?? 'aberta') === 'fechada' ? 'Fecho de caixa validado, por aprovar no 1.º nível.' : (($cx['estado'] ?? '') === 'verificada' ? 'Fecho de caixa aprovado no 1.º nível, falta a aprovação final.' : 'Fecho de caixa por lançar e validar.')];
    $bloqueia = array_filter($c, function ($x) { return in_array($x['estado'], ['pendente', 'erro'], true); });
    return ['controlos' => $c, 'pronto' => !$bloqueia, 'dia' => $dia];
}

/** Documentos → número do lote válido (como naval_sap_exportados, sem depender de sap.php). */
function naval_sap_exportados_rv(array $lotes): array
{
    $out = [];
    foreach ($lotes as $l) {
        if (($l['estado'] ?? 'emitido') === 'anulado') continue;
        foreach ((array) ($l['documentos'] ?? []) as $d) if (!isset($out[$d])) $out[$d] = $l['numero'];
    }
    return $out;
}

/** Meio de cobrança: a mesma regra do portal (reports/js/meios.js, catMeio). */
function naval_rv_meio(array $p): string
{
    $c = strtoupper((string) ($p['codigo'] ?? ''));
    $m = strtoupper((string) ($p['meio'] ?? ''));
    if ($c === 'CMP') return 'compensacao';
    if ($c === 'NU' || preg_match('/NUMER|DINHEIRO|CASH/u', $m)) return 'dinheiro';
    if ($c === 'MC' || preg_match('/MULTI|TPA|CART|CARD/u', $m)) return 'multicaixa';
    return 'outro';
}

/**
 * O que cada promotor recebeu no dia, segundo o Pinguim BO: {rota: {documentos, dinheiro, multicaixa}} (para a folha
 * de caixa conferir o que é entregue). null sem ligação ao Pinguim BO.
 */
function naval_caixa_esperado(string $dia, ?array $vendas = null): ?array
{
    if ($vendas === null) {
        naval_servico('bd');
        naval_servico('vendas');
        naval_servico('rotas');
        $d8 = str_replace('-', '', $dia);
        $vendas = (naval_vendas_sql($d8, $d8, naval_promotores(), false) ?? ['vendas' => null])['vendas'];
        if ($vendas === null) return null;
    }
    $out = [];
    foreach ($vendas as $v) {
        if (!empty($v['nc']) || !empty($v['substituicao'])) continue;   // substituição: sem venda nem dinheiro
        $r = (string) $v['rota'];
        $out[$r] = $out[$r] ?? ['documentos' => 0, 'dinheiro' => 0.0, 'multicaixa' => 0.0];
        $out[$r]['documentos']++;
        foreach ((array) ($v['pagamentos'] ?? []) as $pg) {
            $m = naval_rv_meio((array) $pg);
            if ($m === 'dinheiro' || $m === 'multicaixa') $out[$r][$m] = round($out[$r][$m] + (float) ($pg['valor'] ?? 0), 2);
        }
    }
    ksort($out);
    return $out;
}

/** Conteúdo do relatório (função pura, a partir das fontes já lidas). */
function naval_rv_montar(string $dia, array $f, array $promotores): array
{
    $r2 = function ($v) { return round((float) $v, 2); };
    // substituições de facturas de dias anteriores: não são vendas nem dinheiro do dia (vendas.php)
    naval_servico('vendas');
    $artigos = naval_vendas_efectivas_artigos((array) ($f['artigos'] ?? []), (array) $f['vendas']);
    $vendas = naval_vendas_efectivas((array) $f['vendas']);
    $caixa = (array) $f['caixa'];
    // 1. por promotor: vendas, cobranças por meio (Pinguim BO) e o que foi entregue na caixa e nos talões do TPA
    $prom = [];
    $novo = function ($rota) use ($promotores) {
        return ['rota' => (string) $rota, 'promotor' => $promotores[(string) $rota] ?? (string) $rota, 'documentos' => 0, 'notas_credito' => 0.0, 'vendas' => 0.0,
            'dinheiro' => 0.0, 'multicaixa' => 0.0, 'outros' => 0.0, 'por_receber' => 0.0, 'entregue' => 0.0, 'tpa' => 0.0];
    };
    foreach ($vendas as $v) {
        $k = (string) $v['rota'];
        $prom[$k] = $prom[$k] ?? $novo($k);
        $p = $prom[$k];
        if (!empty($v['nc'])) {
            $p['notas_credito'] += -(float) $v['total'];
            $p['vendas'] += (float) $v['total'];
        } else {
            $p['documentos'] += 1;
            $p['vendas'] += (float) $v['total'];
            $pago = 0.0;
            foreach ((array) ($v['pagamentos'] ?? []) as $pg) {
                $cat = naval_rv_meio((array) $pg);
                if ($cat === 'compensacao') continue;
                $val = (float) ($pg['valor'] ?? 0);
                $pago += $val;
                if ($cat === 'dinheiro') $p['dinheiro'] += $val;
                elseif ($cat === 'multicaixa') $p['multicaixa'] += $val;
                else $p['outros'] += $val;
            }
            $p['por_receber'] += max(0.0, (float) $v['total'] - $pago);
        }
        $prom[$k] = $p;
    }
    foreach ((array) ($caixa['entregas'] ?? []) as $e) {
        $prom[$e['rota']] = $prom[$e['rota']] ?? $novo($e['rota']);
        $prom[$e['rota']]['entregue'] += (float) $e['valor'];
    }
    foreach ((array) ($caixa['tpa'] ?? []) as $e) {
        $prom[$e['rota']] = $prom[$e['rota']] ?? $novo($e['rota']);
        $prom[$e['rota']]['tpa'] += (float) $e['valor'];
    }
    foreach ($prom as $k => $p) {
        foreach (['notas_credito', 'vendas', 'dinheiro', 'multicaixa', 'outros', 'por_receber', 'entregue', 'tpa'] as $c) $p[$c] = $r2($p[$c]);
        $p['dif_dinheiro'] = $r2($p['entregue'] - $p['dinheiro']);
        $p['dif_tpa'] = $r2($p['tpa'] - $p['multicaixa']);
        $prom[$k] = $p;
    }
    ksort($prom);
    $prom = array_values($prom);
    $soma = function (string $k) use ($prom, $r2) { return $r2(array_sum(array_column($prom, $k))); };
    $resumo = [
        'documentos' => array_sum(array_column($prom, 'documentos')), 'vendas' => $soma('vendas'), 'notas_credito' => $soma('notas_credito'),
        'dinheiro' => $soma('dinheiro'), 'multicaixa' => $soma('multicaixa'), 'outros' => $soma('outros'), 'por_receber' => $soma('por_receber'),
        'entregue' => $soma('entregue'), 'tpa' => $soma('tpa'), 'dif_dinheiro' => $soma('dif_dinheiro'), 'dif_tpa' => $soma('dif_tpa'),
        'promotores' => count(array_filter($prom, function ($p) { return $p['documentos'] > 0; })),
    ];
    // 2. registo de vendas (linha a linha, por promotor e documento)
    $registo = [];
    foreach ($artigos as $a) {
        $q = (float) ($a['quantidade'] ?? 0);
        $registo[] = ['rota' => (string) $a['rota'], 'promotor' => $promotores[(string) $a['rota']] ?? (string) $a['rota'], 'documento' => (string) $a['documento'],
            'codigo' => (string) $a['codigo'], 'artigo' => (string) $a['artigo'], 'unidade' => (string) ($a['unidade'] ?? ''), 'quantidade' => $q,
            'preco' => $q ? $r2((float) $a['total'] / $q) : 0.0, 'valor' => $r2($a['total']), 'nc' => !empty($a['nc'])];
    }
    usort($registo, function ($a, $b) { return [$a['rota'], $a['documento'], $a['codigo']] <=> [$b['rota'], $b['documento'], $b['codigo']]; });
    // 3. stock das carrinhas (inicial, carregado, vendido, devolvido, final) da conciliação
    $stock = [];
    foreach ((array) $f['carrinhas'] as $r) {
        foreach ((array) $r['linhas'] as $l) {
            $stock[] = ['rota' => (string) $r['rota'], 'promotor' => (string) $r['promotor'], 'codigo' => (string) $l['codigo'], 'artigo' => (string) $l['artigo'],
                'unidade' => (string) ($l['unidade'] ?? ''), 'inicial' => (float) ($l['inicial'] ?? 0), 'carregado' => (float) ($l['carregado'] ?? 0),
                'vendido' => (float) ($l['vendido'] ?? 0), 'devolvido' => (float) ($l['devolvido'] ?? 0), 'final' => (float) ($l['final'] ?? 0),
                'contado' => $l['contado'] ?? null, 'diferenca' => $l['diferenca'] ?? null];
        }
    }
    // 4. caixa
    $cx = [
        'saldo_inicial' => $caixa['saldo_inicial'] ?? null, 'entregas' => $caixa['total_entregas'] ?? 0, 'tpa' => (array) ($caixa['tpa'] ?? []),
        'total_tpa' => $caixa['total_tpa'] ?? 0, 'saidas' => (array) ($caixa['saidas'] ?? []), 'total_saidas' => $caixa['total_saidas'] ?? 0,
        'saldo_teorico' => $caixa['saldo_teorico'] ?? null, 'contagem_fisica' => $caixa['contagem_fisica'] ?? null, 'diferenca' => $caixa['diferenca'] ?? null,
        'explicacao' => $caixa['explicacao'] ?? '', 'fechada_por' => $caixa['fechada_por'] ?? null, 'aprovada_por' => $caixa['aprovada_por'] ?? null,
    ];
    $dados = ['dia' => $dia, 'resumo' => $resumo, 'promotores' => $prom, 'registo' => $registo, 'stock' => $stock, 'caixa' => $cx];
    $dados['consolidado'] = naval_rv_consolidado($vendas, $dados);
    return $dados;
}

/**
 * Consolidado no formato do SALES REPORT (só Naval, canal RTM), com as conferências e as excepções (função pura).
 * $vendas: documentos efectivos do dia (sem substituições); $d: o resto do relatório já montado.
 */
function naval_rv_consolidado(array $vendas, array $d): array
{
    $r2 = function ($v) { return round((float) $v, 2); };
    $zero = function ($v) { return abs((float) $v) < 0.005; };
    // 1. vendas e meios de cobrança (documento a documento, do Pinguim BO)
    $m = ['brutas' => 0.0, 'notas_credito' => 0.0, 'dinheiro' => 0.0, 'multicaixa' => 0.0, 'outros' => 0.0, 'compensacao' => 0.0, 'por_receber' => 0.0, 'excesso' => 0.0];
    $docs = 0;
    $nc = 0;
    foreach ($vendas as $v) {
        $total = (float) $v['total'];
        if (!empty($v['nc'])) {
            $m['notas_credito'] += -$total;
            $nc++;
            continue;
        }
        $docs++;
        $m['brutas'] += $total;
        $pago = 0.0;
        foreach ((array) ($v['pagamentos'] ?? []) as $pg) {
            $cat = naval_rv_meio((array) $pg);
            $val = (float) ($pg['valor'] ?? 0);
            if ($cat === 'compensacao') {   // como em «por promotor»: a compensação não é cobrança, fica por receber
                $m['compensacao'] += $val;
                continue;
            }
            $pago += $val;
            $m[$cat === 'outro' ? 'outros' : $cat] += $val;
        }
        if ($pago < $total) $m['por_receber'] += $total - $pago;
        else $m['excesso'] += $pago - $total;
    }
    foreach ($m as $k => $x) $m[$k] = $r2($x);
    $liquidas = $r2($m['brutas'] - $m['notas_credito']);
    $cx = (array) $d['caixa'];
    $res = (array) $d['resumo'];
    // 2. bancos: Multicaixa (talões do TPA, com a conta do registo dos terminais) e depósitos (lacres) por banco e conta
    $bancos = [];
    $linha = function ($banco, $conta) use (&$bancos) {
        $k = $banco . '|' . $conta;
        if (!isset($bancos[$k])) $bancos[$k] = ['banco' => (string) $banco, 'conta' => (string) $conta, 'multicaixa' => 0.0, 'talaos' => 0, 'deposito' => 0.0, 'lacres' => 0];
        return $k;
    };
    foreach ((array) ($cx['tpa'] ?? []) as $t) {
        $k = $linha($t['banco'] ?? '', $t['conta'] ?? '');
        $bancos[$k]['multicaixa'] += (float) ($t['valor'] ?? 0);
        $bancos[$k]['talaos']++;
    }
    $depositos = 0.0;
    foreach ((array) ($cx['saidas'] ?? []) as $s) {
        if (($s['tipo'] ?? 'deposito_banco') !== 'deposito_banco') continue;
        $k = $linha($s['banco'] ?? '', '');
        $bancos[$k]['deposito'] += (float) ($s['valor'] ?? 0);
        $bancos[$k]['lacres']++;
        $depositos += (float) ($s['valor'] ?? 0);
    }
    foreach ($bancos as $k => $b) {
        $b['multicaixa'] = $r2($b['multicaixa']);
        $b['deposito'] = $r2($b['deposito']);
        $b['total'] = $r2($b['multicaixa'] + $b['deposito']);
        $bancos[$k] = $b;
    }
    ksort($bancos);
    // 3. conferências («proof totals»): cada uma compara duas fontes independentes
    $conf = [];
    $c = function (string $id, string $nome, string $a, $va, string $b, $vb) use (&$conf, $r2, $zero) {
        $dif = $va === null || $vb === null ? null : $r2((float) $vb - (float) $va);
        $conf[] = ['id' => $id, 'nome' => $nome, 'origem' => $a, 'valor_origem' => $va === null ? null : $r2($va), 'destino' => $b,
            'valor_destino' => $vb === null ? null : $r2($vb), 'diferenca' => $dif, 'estado' => $dif === null ? 'sem_dados' : ($zero($dif) ? 'confere' : 'diferenca')];
    };
    $c('cobrancas', 'Vendas brutas = cobranças + por receber', 'Vendas brutas (Pinguim BO)', $m['brutas'],
        'Dinheiro + Multicaixa + outros + por receber − cobrado a mais', $m['dinheiro'] + $m['multicaixa'] + $m['outros'] + $m['por_receber'] - $m['excesso']);
    $c('por_promotor', 'Total por promotor = total do dia', 'Vendas líquidas do dia', $liquidas, 'Soma das vendas por promotor', $res['vendas'] ?? 0);
    $c('dinheiro', 'Dinheiro recebido = dinheiro entregue na caixa', 'Recebido em dinheiro (Pinguim BO)', $m['dinheiro'], 'Entregue na caixa (folha de caixa)', $res['entregue'] ?? 0);
    $c('multicaixa', 'Multicaixa recebido = talões do TPA', 'Recebido em Multicaixa (Pinguim BO)', $m['multicaixa'], 'Talões de fecho do TPA', $res['tpa'] ?? 0);
    $teorico = $cx['saldo_inicial'] === null ? null : (float) $cx['saldo_inicial'] + (float) ($cx['entregas'] ?? 0) - (float) ($cx['total_saidas'] ?? 0);
    $c('caixa', 'Saldo teórico da caixa = contagem física', 'Saldo inicial + entregue − depósitos', $teorico, 'Contagem física', $cx['contagem_fisica'] ?? null);
    $difStock = array_values(array_filter((array) $d['stock'], function ($l) use ($zero) { return $l['diferenca'] !== null && !$zero($l['diferenca']); }));
    $conf[] = ['id' => 'stock', 'nome' => 'Stock contado = stock teórico nas carrinhas', 'origem' => 'Stock final teórico', 'valor_origem' => null,
        'destino' => 'Contagem das carrinhas', 'valor_destino' => null, 'diferenca' => count($difStock), 'unidade' => 'linhas',
        'estado' => $difStock ? 'diferenca' : 'confere'];
    // 4. excepções (gestão por excepção: o que precisa de explicação, primeiro o maior valor)
    $exc = [];
    foreach ((array) $d['promotores'] as $p) {
        $nome = $p['rota'] . ' · ' . $p['promotor'];
        if (!$zero($p['dif_dinheiro'])) $exc[] = ['tipo' => 'dinheiro', 'rota' => $p['rota'], 'promotor' => $nome, 'valor' => $p['dif_dinheiro'],
            'detalhe' => ($p['dif_dinheiro'] < 0 ? 'Falta' : 'Sobra') . ' de dinheiro: recebido ' . naval_rv_kz($p['dinheiro']) . ', entregue ' . naval_rv_kz($p['entregue']) . '.'];
        if (!$zero($p['dif_tpa'])) $exc[] = ['tipo' => 'multicaixa', 'rota' => $p['rota'], 'promotor' => $nome, 'valor' => $p['dif_tpa'],
            'detalhe' => 'Multicaixa: recebido ' . naval_rv_kz($p['multicaixa']) . ', talões do TPA ' . naval_rv_kz($p['tpa']) . '.'];
        if (!$zero($p['por_receber'])) $exc[] = ['tipo' => 'por_receber', 'rota' => $p['rota'], 'promotor' => $nome, 'valor' => $p['por_receber'],
            'detalhe' => 'Vendas sem cobrança no dia (crédito).'];
    }
    foreach ($difStock as $l) {
        $exc[] = ['tipo' => 'stock', 'rota' => $l['rota'], 'promotor' => $l['rota'] . ' · ' . $l['promotor'], 'valor' => (float) $l['diferenca'], 'unidade' => $l['unidade'] ?? '',
            'detalhe' => 'Stock: ' . $l['codigo'] . ' ' . $l['artigo'] . ' — teórico ' . naval_rv_n($l['final']) . ', contado ' . naval_rv_n($l['contado']) . '.'];
    }
    if ($cx['diferenca'] !== null && !$zero($cx['diferenca'])) {
        $exc[] = ['tipo' => 'caixa', 'rota' => '', 'promotor' => 'Tesouraria', 'valor' => (float) $cx['diferenca'],
            'detalhe' => 'Diferença na contagem da caixa' . ($cx['explicacao'] ? ': ' . $cx['explicacao'] : '.')];
    }
    $ordem = ['caixa' => 0, 'dinheiro' => 1, 'multicaixa' => 2, 'stock' => 3, 'por_receber' => 4];
    usort($exc, function ($a, $b) use ($ordem) { return [$ordem[$a['tipo']], -abs($a['valor'])] <=> [$ordem[$b['tipo']], -abs($b['valor'])]; });
    $falhas = count(array_filter($conf, function ($x) { return $x['estado'] === 'diferenca'; }));
    return [
        'empresa' => 'NAVAL', 'canal' => 'RTM',
        'vendas' => ['documentos' => $docs, 'notas_credito_documentos' => $nc, 'brutas' => $m['brutas'], 'notas_credito' => $m['notas_credito'], 'liquidas' => $liquidas],
        'meios' => ['dinheiro' => $m['dinheiro'], 'multicaixa' => $m['multicaixa'], 'outros' => $m['outros'], 'compensacao' => $m['compensacao'],
            'por_receber' => $m['por_receber'], 'excesso' => $m['excesso']],
        'bancos' => array_values($bancos),
        'caixa' => ['saldo_inicial' => $cx['saldo_inicial'], 'entregue' => $r2($cx['entregas'] ?? 0), 'depositos' => $r2($depositos),
            'saldo_teorico' => $cx['saldo_teorico'], 'contagem_fisica' => $cx['contagem_fisica'], 'diferenca' => $cx['diferenca']],
        'conferencias' => $conf, 'excepcoes' => $exc,
        'estado' => $falhas ? 'com_diferencas' : 'confere', 'diferencas' => $falhas,
    ];
}

/** Kz em texto (para as excepções): 1 234 567,89 Kz. */
function naval_rv_kz($v): string
{
    return number_format((float) $v, 2, ',', ' ') . ' Kz';
}

function naval_rv_n($v): string
{
    return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
}

/** Lê todas as fontes do dia (cada uma pode falhar sem impedir as outras de aparecer nos controlos). */
function naval_rv_fontes(string $dia): array
{
    naval_servico('bd');
    naval_servico('vendas');
    naval_servico('rotas');
    naval_servico('carrinha');
    naval_servico('sap');
    naval_servico('sincronizacao');
    $d8 = str_replace('-', '', $dia);
    $v = naval_vendas_sql($d8, $d8, naval_promotores(), true);
    $f = ['vendas' => $v['vendas'] ?? null, 'artigos' => $v['artigos'] ?? null];
    if ($f['vendas'] !== null && !$f['artigos']) $f['artigos'] = naval_vendas_artigos_sql($d8, $d8, naval_promotores()) ?? [];
    try {
        $f['sync'] = naval_sincronizacao()['rotas'];
    } catch (Throwable $e) {
        $f['sync'] = [];
    }
    try {
        $f['carrinhas'] = naval_carr_vista($dia, null, false)['rotas'];
    } catch (Throwable $e) {
        $f['carrinhas'] = null;
    }
    $f['lotes'] = naval_sap_lotes();
    $f['caixa'] = naval_caixa_ler($dia);
    return $f;
}

function naval_rv_pasta(string $dia): string
{
    if (!naval_data_valida($dia)) throw new NavalErro('Data inválida.', 400);
    return naval_pasta('relatorio-vendas/' . $dia);
}

/** Versões geradas do dia (sem o conteúdo). */
function naval_rv_versoes(string $dia): array
{
    $out = [];
    foreach ((array) glob(naval_rv_pasta($dia) . '/v*.json') as $f) {
        $r = naval_json_ler((string) $f);
        if ($r) $out[] = array_diff_key($r, ['dados' => 1]);
    }
    usort($out, function ($a, $b) { return $a['versao'] <=> $b['versao']; });
    return $out;
}

function naval_rv_ler(string $dia, int $versao): ?array
{
    return naval_json_ler(naval_rv_pasta($dia) . '/v' . $versao . '.json') ?: null;
}

/**
 * Gera (congela) o relatório do dia, só com todas as fontes completas. Uma segunda versão precisa de motivo.
 * $fontes permite os testes; em produção lê-se tudo das bases de dados.
 */
/**
 * Hora de fecho das vendas do dia: a do calendário de trabalho (15:00 de segunda a sexta, 13:00 ao sábado), salvo
 * RELATORIO_HORA_FECHO no .env (HH:MM), que a substitui em todos os dias.
 */
function naval_rv_hora_fecho(string $dia): string
{
    $h = naval_config('RELATORIO_HORA_FECHO', '');
    if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) return $h;
    naval_servico('calendario');
    return naval_cal_fim_vendas($dia);
}

function naval_rv_gerar(string $dia, string $motivo, string $login, ?array $fontes = null): array
{
    if ($dia >= date('Y-m-d') && date('H:i') < naval_rv_hora_fecho($dia)) {
        throw new NavalErro('O dia ainda não fechou: o relatório gera-se depois das ' . naval_rv_hora_fecho($dia) . '.', 409);
    }
    $f = $fontes ?? naval_rv_fontes($dia);
    $ctl = naval_rv_controlos($dia, $f);
    if (!$ctl['pronto']) throw new NavalErro('O relatório só se gera com todas as fontes do dia completas.', 409, ['controlos' => $ctl['controlos']]);
    $r = naval_exclusivo('relatorio-vendas-' . $dia, function () use ($dia, $motivo, $login, $f, $ctl) {
        $versoes = naval_rv_versoes($dia);
        $motivo = naval_texto($motivo, 300);
        if ($versoes && mb_strlen($motivo) < 5) throw new NavalErro('Já existe o relatório deste dia: indique o motivo da nova versão.', 409, ['codigo' => 'versao']);
        $n = $versoes ? max(array_column($versoes, 'versao')) + 1 : 1;
        $dados = naval_rv_montar($dia, $f, naval_promotores());
        $sha = hash('sha256', (string) json_encode($dados, JSON_UNESCAPED_UNICODE));
        $r = ['numero' => 'RV-' . str_replace('-', '', $dia) . '-v' . $n, 'dia' => $dia, 'versao' => $n, 'gerado_em' => date('Y-m-d H:i:s'), 'gerado_por' => $login,
            'motivo' => $n > 1 ? $motivo : '', 'sha256' => $sha, 'controlos' => $ctl['controlos'], 'dados' => $dados];
        naval_json_gravar(naval_rv_pasta($dia) . '/v' . $n . '.json', $r);
        naval_registar('relatorio_vendas_gerado', ['dia' => $dia, 'versao' => $n, 'sha256' => $sha]);
        return $r;
    });
    $r['envio'] = naval_rv_enviar($r);
    return $r;
}

/** Destinatários do resumo por correio: quem vê o relatório na matriz, sem os perfis limitados a rotas (vê o dia todo). */
function naval_rv_destinatarios(): array
{
    naval_servico('email');
    $porRota = naval_perfis_por_rota();
    $m = naval_matriz();
    $out = [];
    foreach (naval_utilizadores() as $login => $u) {
        $p = (string) ($u['perfil'] ?? '');
        if ($p === '' || in_array($p, $porRota, true)) continue;
        $nivel = (string) ($m['relatorio-vendas'][$p] ?? '');
        if ($nivel === '' || NAVAL_ORDEM_NIVEIS[$nivel] < NAVAL_ORDEM_NIVEIS['V'] || !naval_recurso_disponivel('relatorio-vendas', $p)) continue;
        if (!naval_conta_valida($u) || !naval_email_valido($u['email'] ?? null)) continue;
        $out[strtolower(trim((string) $u['email']))] = ['email' => trim((string) $u['email']), 'nome' => (string) ($u['nome'] ?? $login)];
    }
    return array_values($out);
}

/** Envia o resumo do consolidado gerado (nunca lança excepções: o relatório já está gravado). */
function naval_rv_enviar(array $r): array
{
    try {
        if (naval_config('RELATORIO_VENDAS_EMAIL', '1') === '0') return ['ok' => false, 'erro' => 'Envio desligado (RELATORIO_VENDAS_EMAIL=0).'];
        naval_servico('email');
        if (!naval_email_configurado()) return ['ok' => false, 'erro' => 'O envio por correio electrónico não está configurado no servidor.'];
        $para = naval_rv_destinatarios();
        if (!$para) return ['ok' => false, 'erro' => 'Ninguém com acesso ao relatório tem correio electrónico.'];
        $c = (array) ($r['dados']['consolidado'] ?? []);
        $assunto = 'Consolidado diário de vendas RTM · Naval · ' . naval_data_pt($r['dia']) . ' · ' . $r['numero']
            . (($c['diferencas'] ?? 0) ? ' · ' . $c['diferencas'] . ' conferência(s) com diferença' : ' · tudo confere');
        $x = naval_enviar_email($para, $assunto, naval_rv_email_html($r));
        naval_registar('relatorio_vendas_enviado', ['dia' => $r['dia'], 'versao' => $r['versao'], 'destinatarios' => count($para), 'ok' => !empty($x['ok'])]);
        return ['ok' => !empty($x['ok']), 'destinatarios' => count($para), 'erro' => $x['erro'] ?? null];
    } catch (Throwable $e) {
        error_log('[relatorio-vendas envio] ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não foi possível enviar o resumo por correio.'];
    }
}

/** Corpo do correio: resumo, conferências e excepções, com a ligação para o relatório no portal (os detalhes ficam lá). */
function naval_rv_email_html(array $r): string
{
    $h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $c = (array) ($r['dados']['consolidado'] ?? []);
    $v = (array) ($c['vendas'] ?? []);
    $m = (array) ($c['meios'] ?? []);
    $cx = (array) ($c['caixa'] ?? []);
    $td = 'style="padding:6px 10px;border-bottom:1px solid #E3E8F2;text-align:justify"';
    $tdv = 'style="padding:6px 10px;border-bottom:1px solid #E3E8F2;text-align:right;white-space:nowrap"';
    $th = 'style="padding:6px 10px;background:#273777;color:#fff;text-align:left;font-size:12px"';
    $l = function ($a, $b, $forte = false) use ($h, $td, $tdv) {
        return '<tr><td ' . $td . '>' . ($forte ? '<b>' . $h($a) . '</b>' : $h($a)) . '</td><td ' . $tdv . '>' . ($forte ? '<b>' . $h($b) . '</b>' : $h($b)) . '</td></tr>';
    };
    $kz = function ($x) { return $x === null ? '—' : naval_rv_kz($x); };
    $res = '<table style="border-collapse:collapse;width:100%;margin:0 0 16px"><thead><tr><th ' . $th . ' colspan="2">Resumo do dia</th></tr></thead><tbody>'
        . $l('Vendas brutas (' . ($v['documentos'] ?? 0) . ' documentos)', $kz($v['brutas'] ?? 0))
        . $l('Notas de crédito (' . ($v['notas_credito_documentos'] ?? 0) . ')', $kz(-($v['notas_credito'] ?? 0)))
        . $l('Vendas líquidas', $kz($v['liquidas'] ?? 0), true)
        . $l('Dinheiro', $kz($m['dinheiro'] ?? 0)) . $l('Multicaixa', $kz($m['multicaixa'] ?? 0))
        . (($m['outros'] ?? 0) ? $l('Outros meios', $kz($m['outros'])) : '') . (($m['compensacao'] ?? 0) ? $l('Compensação', $kz($m['compensacao'])) : '')
        . $l('Por receber (crédito)', $kz($m['por_receber'] ?? 0))
        . $l('Caixa: saldo inicial', $kz($cx['saldo_inicial'] ?? null)) . $l('Caixa: dinheiro entregue', $kz($cx['entregue'] ?? 0))
        . $l('Caixa: depósitos no banco', $kz(-($cx['depositos'] ?? 0))) . $l('Caixa: saldo final (contagem física)', $kz($cx['contagem_fisica'] ?? null), true)
        . '</tbody></table>';
    $conf = '';
    foreach ((array) ($c['conferencias'] ?? []) as $x) {
        $ok = $x['estado'] === 'confere';
        $dif = ($x['unidade'] ?? '') === 'linhas' ? ($x['diferenca'] . ' linha(s)') : $kz($x['diferenca']);
        $conf .= '<tr><td ' . $td . '>' . $h($x['nome']) . '</td><td ' . $tdv . '>' . ($ok ? '<span style="color:#1F4E9E">Confere</span>' : '<b style="color:#B42318">Diferença · ' . $h($dif) . '</b>') . '</td></tr>';
    }
    $exc = '';
    foreach (array_slice((array) ($c['excepcoes'] ?? []), 0, 30) as $x) {
        $exc .= '<tr><td ' . $td . '>' . $h($x['promotor']) . '</td><td ' . $td . '>' . $h($x['detalhe']) . '</td></tr>';
    }
    $n = count((array) ($c['excepcoes'] ?? []));
    $link = rtrim(naval_config('PORTAL_URL', 'https://geo.naval.co.ao:2051'), '/') . '/reports/#relatorio-vendas';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;color:#0B1F3A;max-width:720px">'
        . '<h2 style="color:#273777;margin:0 0 4px">Consolidado diário de vendas RTM · Naval</h2>'
        . '<p style="margin:0 0 14px;color:#44546D">' . $h(naval_data_pt($r['dia'])) . ' · ' . $h($r['numero']) . ' · gerado por ' . $h($r['gerado_por']) . ' em '
        . $h(naval_data_pt(substr($r['gerado_em'], 0, 10)) . ' ' . substr($r['gerado_em'], 11, 5)) . ($r['motivo'] ? ' · motivo: ' . $h($r['motivo']) : '') . '</p>'
        . $res
        . '<table style="border-collapse:collapse;width:100%;margin:0 0 16px"><thead><tr><th ' . $th . '>Conferência</th><th ' . $th . '>Resultado</th></tr></thead><tbody>' . $conf . '</tbody></table>'
        . ($exc ? '<table style="border-collapse:collapse;width:100%;margin:0 0 16px"><thead><tr><th ' . $th . '>Excepção</th><th ' . $th . '>Detalhe</th></tr></thead><tbody>' . $exc . '</tbody></table>'
            . ($n > 30 ? '<p style="font-size:12px;color:#44546D">E mais ' . ($n - 30) . ' excepção(ões) no portal.</p>' : '') : '<p>Sem excepções: tudo confere.</p>')
        . '<p style="margin:16px 0"><a href="' . $h($link) . '" style="background:#0057B8;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Abrir o consolidado no portal (PDF e Excel)</a></p>'
        . '<p style="font-size:11px;color:#8A97B3;text-align:justify">Fotografia congelada · SHA-256 ' . $h($r['sha256']) . '. Dados do Pinguim BO e da folha de caixa do portal. Mensagem automática do Portal RTM · Grupo Naval.</p></div>';
}
