<?php
/**
 * Formas de pagamento do RTM: quais são válidas, a que forma corresponde cada código do Pinguim BO (PAYMETHOD.CODE) e o
 * banco do TPA de cada rota. Controlo de meios de pagamento por lista branca (prática de controlo de caixa e de
 * tesouraria; ISO/IEC 27001:2022 A.8.2 e COSO, actividades de controlo): só Numerário e Multicaixa são válidos no RTM;
 * um código que não esteja na tabela, ou um documento sem forma de pagamento, é uma excepção a corrigir — nunca se
 * adivinha a forma pelo nome.
 *
 *  - Códigos: NU → Numerário; MC → Multicaixa (sem banco); MCB → Multicaixa BPC. Um código novo no Pinguim BO (ex.:
 *    Multicaixa de outro banco) acrescenta-se na tabela, no portal (Multicaixa por TPA › Formas de pagamento).
 *  - Banco do TPA por rota (o terminal entregue a cada promotor): o Multicaixa de um banco diferente do da rota é
 *    assinalado (TPA de outro promotor ou forma mal escolhida).
 *  - Reconciliação por dia e promotor (o mapa «Rtm sales reconciliation»): Pinguim BO por forma de pagamento × o que
 *    foi entregue na caixa (dinheiro) e os talões do TPA, com as diferenças. Multicaixa a mais no Pinguim BO com
 *    dinheiro a mais na caixa, no mesmo valor, é «provável numerário registado como Multicaixa»: o documento fiscal não
 *    se altera; a correcção faz-se na origem (o promotor escolhe a forma certa) e a diferença explica-se na caixa.
 * Ficheiro: dados-privados/formas-pagamento/config.json (valores por omissão abaixo, até alguém gravar).
 */
defined('NAVAL_NUCLEO') || exit;

/** Formas válidas no RTM. */
const NAVAL_FP_FORMAS = ['numerario' => 'Numerário', 'multicaixa' => 'Multicaixa'];

/** Códigos técnicos do portal, que não são formas de pagamento (compensação com nota de crédito, substituição). */
const NAVAL_FP_TECNICOS = ['CMP'];

/** Tabela por omissão: códigos do Pinguim BO e banco do TPA de cada rota (indicado pelo Marketing a 17-11-2026). */
const NAVAL_FP_OMISSAO = [
    'codigos' => [
        'NU' => ['forma' => 'numerario', 'banco' => ''],
        'MC' => ['forma' => 'multicaixa', 'banco' => ''],
        'MCB' => ['forma' => 'multicaixa', 'banco' => 'BPC'],
    ],
    'rotas' => [
        '1001' => 'BPC', '1002' => 'BPC', '1003' => 'BPC', '1004' => 'BPC', '1005' => 'BPC', '1006' => 'BPC', '1007' => 'BPC',
        '1008' => 'ATLANTICO', '1009' => 'BPC', '1010' => 'ATLANTICO', '1011' => 'ATLANTICO', '1012' => 'BPC',
    ],
];

function naval_fp_ficheiro(): string
{
    return naval_pasta('formas-pagamento') . '/config.json';
}

/** Tabela em vigor: {codigos: {COD: {forma, banco}}, rotas: {rota: banco}, alterado_por, alterado_em}. */
function naval_fp_config(): array
{
    $g = naval_json_ler(naval_fp_ficheiro());
    $c = isset($g['codigos']) ? $g : NAVAL_FP_OMISSAO + ['alterado_por' => '', 'alterado_em' => ''];
    $c['codigos'] = (array) $c['codigos'];
    $c['rotas'] = (array) ($c['rotas'] ?? []);
    ksort($c['codigos']);
    ksort($c['rotas']);
    // banco do TPA de cada rota em vigor: o do registo dos terminais, quando a rota tem terminal; senão o da tabela
    naval_servico('tpa');
    $c['bancos_rotas'] = naval_fp_bancos_rotas($c);
    return $c;
}

/** Grava a tabela (validada). */
function naval_fp_gravar(array $p, string $login): array
{
    naval_servico('caixa');
    $cod = [];
    foreach (array_slice((array) ($p['codigos'] ?? []), 0, 50, true) as $k => $x) {
        $k = strtoupper(trim((string) (is_array($x) && isset($x['codigo']) ? $x['codigo'] : $k)));
        if ($k === '') continue;
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $k)) throw new NavalErro('Código de forma de pagamento inválido: ' . $k . ' (o código do Pinguim BO, ex.: NU, MC, MCB).', 400);
        if (in_array($k, NAVAL_FP_TECNICOS, true)) throw new NavalErro('O código ' . $k . ' é do portal (compensação), não é uma forma de pagamento.', 400);
        $forma = (string) ($x['forma'] ?? '');
        if (!isset(NAVAL_FP_FORMAS[$forma])) throw new NavalErro('Escolha a forma de pagamento do código ' . $k . ' (Numerário ou Multicaixa).', 400);
        $banco = strtoupper((string) ($x['banco'] ?? ''));
        if ($banco !== '' && !in_array($banco, NAVAL_CAIXA_BANCOS, true)) throw new NavalErro('Banco inválido no código ' . $k . '.', 400);
        if ($forma === 'numerario') $banco = '';
        $cod[$k] = ['forma' => $forma, 'banco' => $banco];
    }
    if (!$cod) throw new NavalErro('Indique pelo menos uma forma de pagamento válida.', 400);
    $rotas = [];
    foreach (array_slice((array) ($p['rotas'] ?? []), 0, 200, true) as $rota => $banco) {
        $rota = (string) $rota;
        $banco = strtoupper((string) $banco);
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) throw new NavalErro('Rota inválida: ' . $rota . '.', 400);
        if ($banco === '') continue;
        if (!in_array($banco, NAVAL_CAIXA_BANCOS, true)) throw new NavalErro('Banco inválido na rota ' . $rota . '.', 400);
        $rotas[$rota] = $banco;
    }
    $antes = naval_fp_config();
    $novo = ['codigos' => $cod, 'rotas' => $rotas, 'alterado_por' => $login, 'alterado_em' => date('Y-m-d H:i:s')];
    naval_exclusivo('formas-pagamento', function () use ($novo) { naval_json_gravar(naval_fp_ficheiro(), $novo); });
    naval_registar('formas_pagamento', ['antes' => ['codigos' => $antes['codigos'], 'rotas' => $antes['rotas']], 'depois' => ['codigos' => $cod, 'rotas' => $rotas]]);
    return naval_fp_config();
}

/** Forma de um pagamento: numerario | multicaixa | compensacao | invalida (código fora da tabela ou sem forma). */
function naval_fp_forma(string $codigo, ?array $cfg = null): string
{
    $codigo = strtoupper(trim($codigo));
    if (in_array($codigo, NAVAL_FP_TECNICOS, true)) return 'compensacao';
    $cfg = $cfg ?? naval_fp_config();
    return (string) ($cfg['codigos'][$codigo]['forma'] ?? 'invalida');
}

/** Códigos do Pinguim BO que são Multicaixa (para as consultas do TPA). */
function naval_fp_codigos_multicaixa(?array $cfg = null): array
{
    $cfg = $cfg ?? naval_fp_config();
    return array_map('strval', array_keys(array_filter($cfg['codigos'], function ($x) { return ($x['forma'] ?? '') === 'multicaixa'; })));
}

/**
 * Excepções de forma de pagamento de um documento: forma não válida (código fora da tabela ou sem forma) e Multicaixa de
 * um banco diferente do TPA da rota. [{tipo, codigo, meio, valor, detalhe}].
 */
function naval_fp_excecoes_documento(array $v, array $cfg): array
{
    $out = [];
    $rota = (string) ($v['rota'] ?? '');
    $bancoRota = (string) (($cfg['bancos_rotas'] ?? $cfg['rotas'])[$rota] ?? '');
    foreach ((array) ($v['pagamentos'] ?? []) as $p) {
        $cod = strtoupper(trim((string) ($p['codigo'] ?? '')));
        $val = (float) ($p['valor'] ?? 0);
        if (abs($val) < 0.005) continue;
        $forma = naval_fp_forma($cod, $cfg);
        if ($forma === 'invalida') {
            $out[] = ['tipo' => 'forma_invalida', 'codigo' => $cod, 'meio' => (string) ($p['meio'] ?? ''), 'valor' => round($val, 2),
                'detalhe' => ($cod === '' || $cod === '?' ? 'Sem forma de pagamento no Pinguim BO' : 'Forma de pagamento não válida no RTM: ' . $cod . ' · ' . ($p['meio'] ?? '')) . '.'];
        } elseif ($forma === 'multicaixa') {
            $banco = (string) ($cfg['codigos'][$cod]['banco'] ?? '');
            if ($banco !== '' && $bancoRota !== '' && $banco !== $bancoRota) {
                $out[] = ['tipo' => 'banco_diferente', 'codigo' => $cod, 'meio' => (string) ($p['meio'] ?? ''), 'valor' => round($val, 2),
                    'detalhe' => 'Multicaixa ' . $banco . ' numa rota com TPA ' . $bancoRota . ' (TPA de outro promotor ou forma mal escolhida).'];
            }
        }
    }
    return $out;
}

/**
 * Reconciliação por dia e promotor (função pura): vendas do Pinguim BO por forma de pagamento × entregue na caixa e
 * talões do TPA. $caixas: {dia: folha de caixa}. Devolve {linhas, excepcoes, totais}.
 */
function naval_fp_reconciliar(array $vendas, array $caixas, array $promotores, array $cfg): array
{
    $r2 = function ($v) { return round((float) $v, 2); };
    $L = [];
    $exc = [];
    $linha = function ($dia, $rota) use (&$L, $promotores, $cfg) {
        $k = $dia . '|' . $rota;
        if (!isset($L[$k])) {
            $L[$k] = ['dia' => $dia, 'rota' => $rota, 'promotor' => (string) ($promotores[$rota] ?? ''), 'banco_tpa' => (string) (($cfg['bancos_rotas'] ?? $cfg['rotas'])[$rota] ?? ''),
                'documentos' => 0, 'corrigiveis' => 0, 'corrigidos' => 0, 'por_aprovar' => 0, 'numerario' => 0.0, 'multicaixa' => 0.0, 'invalida' => 0.0, 'por_receber' => 0.0, 'total' => 0.0,
                'entregue' => null, 'talaos' => null];
        }
        return $k;
    };
    foreach ($vendas as $v) {
        if (!empty($v['substituicao'])) continue;   // rectificação de outro dia: nem venda nem dinheiro do dia
        $dia = substr((string) $v['data'], 0, 10);
        $k = $linha($dia, (string) $v['rota']);
        $L[$k]['documentos']++;
        if (naval_fp_corrigivel($v)) $L[$k]['corrigiveis']++;
        if (!empty($v['forma_corrigida'])) $L[$k]['corrigidos']++;
        $L[$k]['total'] += (float) $v['total'];
        $pago = 0.0;
        foreach ((array) ($v['pagamentos'] ?? []) as $p) {
            $f = naval_fp_forma((string) ($p['codigo'] ?? ''), $cfg);
            if ($f === 'compensacao') continue;
            $L[$k][$f] += (float) ($p['valor'] ?? 0);
            $pago += (float) ($p['valor'] ?? 0);
        }
        if (empty($v['nc']) && $pago < (float) $v['total'] - 0.005) $L[$k]['por_receber'] += (float) $v['total'] - $pago;
        foreach (naval_fp_excecoes_documento($v, $cfg) as $e) {
            $exc[] = $e + ['dia' => $dia, 'rota' => (string) $v['rota'], 'promotor' => (string) ($promotores[(string) $v['rota']] ?? ''), 'documento' => (string) ($v['documento'] ?? '')];
        }
    }
    // caixa: dinheiro entregue e talões do TPA por rota
    foreach ($caixas as $dia => $c) {
        foreach ((array) ($c['entregas'] ?? []) as $e) {
            $k = $linha($dia, (string) $e['rota']);
            $L[$k]['entregue'] = (float) $L[$k]['entregue'] + (float) ($e['valor'] ?? 0);
        }
        foreach ((array) ($c['tpa'] ?? []) as $e) {
            $k = $linha($dia, (string) $e['rota']);
            $L[$k]['talaos'] = (float) $L[$k]['talaos'] + (float) ($e['valor'] ?? 0);
            // talão de um TPA de outra empresa (ex.: Higitec): a venda é da Naval, mas o dinheiro entra noutra conta
            $emp = strtoupper((string) ($e['empresa'] ?? 'NAVAL'));
            if ($emp !== '' && $emp !== 'NAVAL' && (float) ($e['valor'] ?? 0) > 0) {
                $exc[] = ['tipo' => 'tpa_outra_empresa', 'dia' => $dia, 'rota' => (string) $e['rota'], 'promotor' => (string) ($promotores[(string) $e['rota']] ?? ''),
                    'documento' => (string) ($e['tpa'] ?? ($e['talao'] ?? '')), 'codigo' => '', 'meio' => '', 'valor' => round((float) $e['valor'], 2),
                    'detalhe' => 'Multicaixa da Naval recebido num TPA da ' . ucfirst(strtolower($emp)) . ': o valor entra na conta da ' . ucfirst(strtolower($emp)) . ' (acerto entre empresas).'];
            }
        }
    }
    $tot = ['documentos' => 0, 'corrigidos' => 0, 'numerario' => 0.0, 'multicaixa' => 0.0, 'invalida' => 0.0, 'por_receber' => 0.0, 'total' => 0.0, 'entregue' => 0.0, 'talaos' => 0.0];
    foreach ($L as $k => $l) {
        foreach (['numerario', 'multicaixa', 'invalida', 'por_receber', 'total'] as $c) $l[$c] = $r2($l[$c]);
        $temCaixa = isset($caixas[$l['dia']]);
        if ($temCaixa) {
            $l['entregue'] = $r2($l['entregue'] ?? 0);
            $l['talaos'] = $r2($l['talaos'] ?? 0);
        }
        $l['dif_numerario'] = $l['entregue'] === null ? null : $r2($l['entregue'] - $l['numerario']);
        $l['dif_multicaixa'] = $l['talaos'] === null ? null : $r2($l['talaos'] - $l['multicaixa']);
        // Multicaixa a mais no Pinguim BO e dinheiro a mais na caixa: forma mal escolhida no telemóvel
        $l['provavel_troca'] = $l['dif_numerario'] !== null && $l['dif_multicaixa'] !== null && $l['dif_numerario'] > 0.005 && $l['dif_multicaixa'] < -0.005
            ? $r2(min($l['dif_numerario'], -$l['dif_multicaixa'])) : 0.0;
        if ($l['provavel_troca'] > 0) {
            $exc[] = ['tipo' => 'provavel_troca', 'dia' => $l['dia'], 'rota' => $l['rota'], 'promotor' => $l['promotor'], 'documento' => '', 'codigo' => '', 'meio' => '',
                'valor' => $l['provavel_troca'], 'detalhe' => 'Provável numerário registado como Multicaixa: a caixa recebeu mais dinheiro e o TPA menos do que o Pinguim BO indica.'];
        }
        foreach (array_keys($tot) as $c) $tot[$c] += (float) ($l[$c] ?? 0);
        $L[$k] = $l;
    }
    foreach ($tot as $c => $v) $tot[$c] = in_array($c, ['documentos', 'corrigidos'], true) ? (int) $v : $r2($v);
    ksort($L);
    $ordem = ['forma_invalida' => 0, 'banco_diferente' => 1, 'provavel_troca' => 2, 'tpa_outra_empresa' => 3];
    usort($exc, function ($a, $b) use ($ordem) { return [$a['dia'], $ordem[$a['tipo']], $a['rota']] <=> [$b['dia'], $ordem[$b['tipo']], $b['rota']]; });
    return ['linhas' => array_values($L), 'excepcoes' => $exc, 'totais' => $tot];
}

/** Reconciliação de um período (até 62 dias), lida do Pinguim BO e das folhas de caixa. */
function naval_fp_reconciliacao(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate) - strtotime($de)) / 86400 > 62) throw new NavalErro('Escolha um período de, no máximo, 62 dias.', 400);
    naval_servico('bd');
    naval_servico('vendas');
    naval_servico('rotas');
    naval_servico('caixa');
    $prom = naval_promotores();
    $v = naval_vendas_sql(str_replace('-', '', $de), str_replace('-', '', $ate), $prom, false);
    $caixas = [];
    for ($t = strtotime($de . ' 12:00'); $t <= strtotime($ate . ' 12:00'); $t += 86400) {
        $d = date('Y-m-d', $t);
        if (is_file(naval_caixa_ficheiro($d))) $caixas[$d] = naval_caixa_ler($d);
    }
    $cfg = naval_fp_config();
    $r = naval_fp_reconciliar(naval_fp_aplicar((array) ($v['vendas'] ?? [])), $caixas, $prom, $cfg) + ['pinguim_ligado' => $v !== null, 'de' => $de, 'ate' => $ate];
    // correcções por aprovar em cada dia e rota: a linha não volta a pedir correcção dos mesmos documentos
    $pend = [];
    foreach (naval_fp_correcoes() as $c) if ($c['estado'] === 'pendente') $pend[$c['dia'] . '|' . $c['rota']] = ($pend[$c['dia'] . '|' . $c['rota']] ?? 0) + 1;
    foreach ($r['linhas'] as $i => $l) $r['linhas'][$i]['por_aprovar'] = $pend[$l['dia'] . '|' . $l['rota']] ?? 0;
    return $r;
}

/*
 * Correcção da forma de pagamento (reclassificação), com justificação e aprovação por outra pessoa.
 *
 * O documento fiscal emitido no Pinguim BO nunca se altera (é o registo legal da venda). Quando a forma registada no
 * momento da venda não é a que efectivamente se recebeu (ex.: dinheiro registado como Multicaixa), a Tesouraria pede a
 * correcção no portal, documento a documento, com a justificação e a evidência (talão do TPA, folha de caixa); outra
 * pessoa, do 1.º nível de aprovação da caixa, aprova ou recusa (segregação de funções, «quatro olhos»). Só a correcção
 * aprovada conta nos relatórios (folha de caixa, Multicaixa por TPA, consolidado, reconciliação), sempre com o original
 * ao lado. Nada se apaga: recusar e anular ficam no histórico, com quem, quando e porquê (ISO/IEC 27001:2022 A.5.3
 * segregação de funções e A.8.15 registos; ISO 9001:2015 7.5.3 controlo da informação documentada; COSO).
 * Ficheiro: dados-privados/formas-pagamento/correcoes.json.
 */

const NAVAL_FP_JUSTIFICACAO_MIN = 20;

function naval_fp_correcoes_ficheiro(): string
{
    return naval_pasta('formas-pagamento') . '/correcoes.json';
}

/** Todas as correcções (pedidas, aprovadas, recusadas e anuladas), a mais recente primeiro. */
function naval_fp_correcoes(): array
{
    $l = (array) (naval_json_ler(naval_fp_correcoes_ficheiro(), ['correcoes' => []])['correcoes'] ?? []);
    usort($l, function ($a, $b) { return strcmp((string) $b['pedido_em'], (string) $a['pedido_em']) ?: strcmp((string) $b['id'], (string) $a['id']); });
    return $l;
}

/** Correcções aprovadas em vigor, por documento: {documento: correcção}. */
function naval_fp_correcoes_aprovadas(): array
{
    $out = [];
    foreach (naval_fp_correcoes() as $c) if ($c['estado'] === 'aprovada') $out[(string) $c['documento']] = $c;
    return $out;
}

/** Valor por forma de um documento, como está no Pinguim BO: {numerario, multicaixa, invalida, total_pago}. */
function naval_fp_formas_documento(array $v, array $cfg): array
{
    $x = ['numerario' => 0.0, 'multicaixa' => 0.0, 'invalida' => 0.0];
    $porCodigo = [];
    foreach ((array) ($v['pagamentos'] ?? []) as $p) {
        $f = naval_fp_forma((string) ($p['codigo'] ?? ''), $cfg);
        if ($f === 'compensacao') continue;
        $x[$f] += (float) ($p['valor'] ?? 0);
        if ($f === 'multicaixa') $porCodigo[strtoupper((string) $p['codigo'])] = ($porCodigo[strtoupper((string) $p['codigo'])] ?? 0) + (float) ($p['valor'] ?? 0);
    }
    foreach ($x as $k => $val) $x[$k] = round($val, 2);
    arsort($porCodigo);
    $x['codigo_multicaixa'] = (string) (key($porCodigo) ?? '');   // o código de Multicaixa do documento (o de maior valor)
    $x['total_pago'] = round($x['numerario'] + $x['multicaixa'] + $x['invalida'], 2);
    return $x;
}

/** Os documentos que se podem corrigir: vendas com cobrança (não notas de crédito nem substituições). */
function naval_fp_corrigivel(array $v): bool
{
    return empty($v['nc']) && empty($v['substituicao']) && (float) ($v['total'] ?? 0) > 0;
}

/**
 * Aplica as correcções aprovadas aos documentos (função pura com $aprovadas): os pagamentos do documento passam a ser
 * os corrigidos (Numerário NU e Multicaixa), com a compensação que já tinha; fica o original em 'pagamentos_pinguim'
 * e o número da correcção em 'forma_corrigida'.
 */
function naval_fp_aplicar(array $vendas, ?array $aprovadas = null): array
{
    $aprovadas = $aprovadas ?? naval_fp_correcoes_aprovadas();
    if (!$aprovadas) return $vendas;
    foreach ($vendas as $i => $v) {
        $c = $aprovadas[(string) ($v['documento'] ?? '')] ?? null;
        if (!$c || substr((string) $v['data'], 0, 10) !== $c['dia'] || (string) $v['rota'] !== (string) $c['rota']) continue;
        $novos = array_values(array_filter((array) ($v['pagamentos'] ?? []), function ($p) { return strtoupper((string) ($p['codigo'] ?? '')) === 'CMP'; }));
        if ($c['numerario'] > 0) $novos[] = ['codigo' => 'NU', 'meio' => 'Numerário (corrigido ' . $c['id'] . ')', 'valor' => (float) $c['numerario'], 'corrigido' => $c['id']];
        if ($c['multicaixa'] > 0) $novos[] = ['codigo' => $c['codigo_multicaixa'] ?? 'MC', 'meio' => 'Multicaixa ' . ($c['codigo_multicaixa'] ?? 'MC') . ' (corrigido ' . $c['id'] . ')', 'valor' => (float) $c['multicaixa'], 'corrigido' => $c['id']];
        $vendas[$i]['pagamentos_pinguim'] = $v['pagamentos'] ?? [];
        $vendas[$i]['pagamentos'] = $novos;
        $vendas[$i]['forma_corrigida'] = $c['id'];
        $vendas[$i]['meio_pagamento'] = implode(', ', array_unique(array_column($novos, 'meio')));
    }
    return $vendas;
}

/** Documentos de um dia e rota (do Pinguim BO), com as formas e a correcção de cada um, para o ecrã de correcção. */
function naval_fp_documentos(string $dia, string $rota): array
{
    if (!naval_data_valida($dia) || !preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) throw new NavalErro('Dia ou rota inválidos.', 400);
    naval_servico('bd');
    naval_servico('vendas');
    naval_servico('rotas');
    $d8 = str_replace('-', '', $dia);
    $v = naval_vendas_sql($d8, $d8, naval_promotores(), false);
    if ($v === null) throw new NavalErro('Sem ligação ao Pinguim BO: não é possível ler os documentos.', 503);
    $cfg = naval_fp_config();
    $porDoc = [];
    foreach (naval_fp_correcoes() as $c) {
        if ($c['dia'] === $dia && (string) $c['rota'] === $rota) $porDoc[(string) $c['documento']][] = $c;
    }
    $out = [];
    foreach ((array) $v['vendas'] as $d) {
        if ((string) $d['rota'] !== $rota || substr((string) $d['data'], 0, 10) !== $dia) continue;
        $out[] = ['documento' => (string) $d['documento'], 'hora' => (string) ($d['hora'] ?? ''), 'cliente' => (string) ($d['cliente'] ?? ''),
            'total' => round((float) $d['total'], 2), 'nc' => !empty($d['nc']), 'corrigivel' => naval_fp_corrigivel($d),
            'pinguim' => naval_fp_formas_documento($d, $cfg), 'meio_pinguim' => (string) ($d['meio_pagamento'] ?? ''),
            'correcoes' => $porDoc[(string) $d['documento']] ?? []];
    }
    return $out;
}

/** Número da próxima correcção: FP-AAAA-00001 (sequencial por ano, sem falhas). */
function naval_fp_numero(array $lista, string $ano): string
{
    $n = 0;
    foreach ($lista as $c) if (preg_match('/^FP-' . $ano . '-(\d{5})$/', (string) $c['id'], $m)) $n = max($n, (int) $m[1]);
    return sprintf('FP-%s-%05d', $ano, $n + 1);
}

/**
 * Pede a correcção da forma de pagamento de um ou mais documentos do mesmo dia e rota (a Tesouraria). Cada documento:
 * {documento, numerario, multicaixa}; numerário + Multicaixa = o valor cobrado no documento. Justificação obrigatória.
 */
function naval_fp_pedir(string $dia, string $rota, array $docs, string $justificacao, string $evidencia, string $login, ?array $doPinguim = null): array
{
    $justificacao = trim(naval_texto($justificacao, 600));
    $evidencia = trim(naval_texto($evidencia, 200));
    if (mb_strlen($justificacao) < NAVAL_FP_JUSTIFICACAO_MIN) {
        throw new NavalErro('Escreva a justificação da correcção (pelo menos ' . NAVAL_FP_JUSTIFICACAO_MIN . ' caracteres): o que se recebeu de facto e como se confirmou.', 400, ['campo' => 'justificacao']);
    }
    // uma justificação com sentido (prova para a auditoria): pelo menos três palavras, não letras ao acaso
    preg_match_all('/[\p{L}\p{N}]{2,}/u', $justificacao, $palavras);
    if (count($palavras[0]) < 3 || preg_match('/^(\S)\1*$/u', preg_replace('/\s+/u', '', $justificacao))) {
        throw new NavalErro('Escreva a justificação em palavras (pelo menos três): o que se recebeu de facto e como se confirmou.', 400, ['campo' => 'justificacao']);
    }
    if (!$docs) throw new NavalErro('Indique os documentos a corrigir.', 400);
    $pinguim = [];
    foreach ($doPinguim ?? naval_fp_documentos($dia, $rota) as $d) $pinguim[$d['documento']] = $d;
    $cfg = naval_fp_config();
    $mc = naval_fp_codigos_multicaixa($cfg);
    return naval_exclusivo('formas-pagamento-correcoes', function () use ($dia, $rota, $docs, $justificacao, $evidencia, $login, $pinguim, $mc) {
        $lista = (array) (naval_json_ler(naval_fp_correcoes_ficheiro(), ['correcoes' => []])['correcoes'] ?? []);
        $novos = [];
        $lote = '';
        foreach (array_slice($docs, 0, 100) as $x) {
            $doc = (string) ($x['documento'] ?? '');
            $p = $pinguim[$doc] ?? null;
            if (!$p) throw new NavalErro('O documento ' . $doc . ' não é da rota ' . $rota . ' em ' . naval_data_pt($dia) . '.', 400);
            if (!$p['corrigivel']) throw new NavalErro('O documento ' . $doc . ' não se corrige (nota de crédito ou substituição).', 400);
            foreach ($lista as $c) {
                if ($c['documento'] === $doc && in_array($c['estado'], ['pendente', 'aprovada'], true)) {
                    throw new NavalErro('O documento ' . $doc . ' já tem a correcção ' . $c['id'] . ' ' . ($c['estado'] === 'pendente' ? 'por aprovar' : 'aprovada (anule-a primeiro)') . '.', 409);
                }
            }
            $nu = round(max(0.0, (float) ($x['numerario'] ?? 0)), 2);
            $mcv = round(max(0.0, (float) ($x['multicaixa'] ?? 0)), 2);
            // banco do Multicaixa: um dos códigos de Multicaixa da tabela (ex.: MC, MCB); sem indicação, o do documento
            $codPinguim = (string) ($p['pinguim']['codigo_multicaixa'] ?? '') ?: ($mc[0] ?? 'MC');
            $codMc = strtoupper(trim((string) ($x['codigo_multicaixa'] ?? ''))) ?: $codPinguim;
            if ($mcv > 0 && !in_array($codMc, $mc, true)) throw new NavalErro('No documento ' . $doc . ', escolha o Multicaixa da tabela das formas de pagamento.', 400);
            $alvo = (float) $p['pinguim']['total_pago'];
            if ($alvo <= 0) $alvo = (float) $p['total'];   // sem forma de pagamento: o total do documento
            if (abs($nu + $mcv - $alvo) >= 0.01) {
                throw new NavalErro('No documento ' . $doc . ', Numerário + Multicaixa tem de dar ' . naval_rv_kz_fp($alvo) . ' (o valor cobrado).', 400);
            }
            $mesmoCodigo = $mcv < 0.01 || $codMc === $codPinguim;
            if (abs($nu - $p['pinguim']['numerario']) < 0.01 && abs($mcv - $p['pinguim']['multicaixa']) < 0.01 && $p['pinguim']['invalida'] < 0.01 && $mesmoCodigo) continue;   // igual ao Pinguim BO
            $id = naval_fp_numero(array_merge($lista, $novos), substr($dia, 0, 4));
            $lote = $lote ?: $id;
            $agora = date('Y-m-d H:i:s');
            $novos[] = ['id' => $id, 'lote' => $lote, 'dia' => $dia, 'rota' => $rota, 'documento' => $doc, 'total' => (float) $p['total'],
                'pinguim' => $p['pinguim'], 'meio_pinguim' => $p['meio_pinguim'], 'numerario' => $nu, 'multicaixa' => $mcv, 'codigo_multicaixa' => $codMc,
                'justificacao' => $justificacao, 'evidencia' => $evidencia, 'estado' => 'pendente', 'pedido_por' => $login, 'pedido_em' => $agora,
                'decidido_por' => '', 'decidido_em' => '', 'motivo' => '', 'historico' => [['accao' => 'pedida', 'por' => $login, 'em' => $agora]]];
        }
        if (!$novos) throw new NavalErro('Nenhum documento muda: as formas indicadas são as do Pinguim BO.', 400);
        naval_json_gravar(naval_fp_correcoes_ficheiro(), ['correcoes' => array_merge($lista, $novos)]);
        naval_registar('formas_pagamento_correcao_pedida', ['dia' => $dia, 'rota' => $rota, 'correcoes' => array_column($novos, 'id')]);
        return $novos;
    });
}

/** Aprova ou recusa uma correcção pendente (outra pessoa, do 1.º nível de aprovação da caixa). */
function naval_fp_decidir(string $id, bool $aprovar, string $motivo, string $login): array
{
    $motivo = trim(naval_texto($motivo, 300));
    if (!$aprovar && mb_strlen($motivo) < 5) throw new NavalErro('Escreva o motivo da recusa.', 400, ['campo' => 'motivo']);
    return naval_fp_alterar($id, function ($c) use ($aprovar, $motivo, $login) {
        if ($c['estado'] !== 'pendente') throw new NavalErro('A correcção ' . $c['id'] . ' já não está por aprovar.', 409);
        if (strcasecmp($c['pedido_por'], $login) === 0) throw new NavalErro('Quem pede a correcção não a aprova: tem de ser outra pessoa.', 403);
        $c['estado'] = $aprovar ? 'aprovada' : 'recusada';
        $c['decidido_por'] = $login;
        $c['decidido_em'] = date('Y-m-d H:i:s');
        $c['motivo'] = $motivo;
        $c['historico'][] = ['accao' => $c['estado'], 'por' => $login, 'em' => $c['decidido_em'], 'motivo' => $motivo];
        return $c;
    }, $aprovar ? 'formas_pagamento_correcao_aprovada' : 'formas_pagamento_correcao_recusada');
}

/** Anula uma correcção aprovada (volta a contar a forma do Pinguim BO), com motivo; fica no histórico. */
function naval_fp_anular(string $id, string $motivo, string $login): array
{
    $motivo = trim(naval_texto($motivo, 300));
    if (mb_strlen($motivo) < 5) throw new NavalErro('Escreva o motivo da anulação.', 400, ['campo' => 'motivo']);
    return naval_fp_alterar($id, function ($c) use ($motivo, $login) {
        if (!in_array($c['estado'], ['aprovada', 'pendente'], true)) throw new NavalErro('A correcção ' . $c['id'] . ' não está em vigor.', 409);
        $c['estado'] = 'anulada';
        $c['historico'][] = ['accao' => 'anulada', 'por' => $login, 'em' => date('Y-m-d H:i:s'), 'motivo' => $motivo];
        return $c;
    }, 'formas_pagamento_correcao_anulada');
}

function naval_fp_alterar(string $id, callable $fn, string $evento): array
{
    return naval_exclusivo('formas-pagamento-correcoes', function () use ($id, $fn, $evento) {
        $lista = (array) (naval_json_ler(naval_fp_correcoes_ficheiro(), ['correcoes' => []])['correcoes'] ?? []);
        foreach ($lista as $i => $c) {
            if ($c['id'] !== $id) continue;
            $lista[$i] = $fn($c);
            naval_json_gravar(naval_fp_correcoes_ficheiro(), ['correcoes' => $lista]);
            naval_registar($evento, ['id' => $id, 'documento' => $c['documento'], 'dia' => $c['dia'], 'rota' => $c['rota']]);
            return $lista[$i];
        }
        throw new NavalErro('Correcção não encontrada.', 404);
    });
}

function naval_rv_kz_fp($v): string
{
    return number_format((float) $v, 2, ',', ' ') . ' Kz';
}

/** Bancos do TPA por rota: o registo dos terminais (activos, com rota) manda; a tabela das formas completa o resto. */
function naval_fp_bancos_rotas(array $cfg): array
{
    $b = (array) ($cfg['rotas'] ?? []);
    if (function_exists('naval_tpa_terminais')) {
        $porRota = [];
        foreach (naval_tpa_terminais() as $t) if (!empty($t['activo']) && ($t['rota'] ?? '') !== '') $porRota[(string) $t['rota']][(string) $t['banco']] = true;
        foreach ($porRota as $rota => $bancos) if (count($bancos) === 1) $b[$rota] = (string) key($bancos);
    }
    ksort($b);
    return $b;
}
