<?php
/**
 * Inteligência da carteira de clientes, calculada todas as noites a partir das vendas do Pinguim BO (agregados diários
 * dos últimos 120 dias; nunca de ficheiros Excel) e guardada em dados-privados/carteira/AAAA-MM-DD.json:
 *
 *  - Ritmo de compra de cada cliente (intervalo mediano entre compras) e estado: em dia, a abrandar ou parado.
 *    O alerta chega antes dos 14 dias fixos: compara cada cliente com o seu próprio ritmo.
 *  - Encomenda sugerida: artigos que o cliente compra habitualmente e a quantidade mediana por compra.
 *  - Deixou de comprar: artigos habituais que faltaram nas últimas compras.
 *  - A propor: artigos que a maioria dos clientes da mesma rota compra e este cliente nunca comprou (venda cruzada).
 *  - Risco de crédito: facturas em aberto e há quantos dias (classificação baixo / médio / alto).
 *  - Visitas do dia: os clientes de cada rota para um dia (dia habitual de compra ou ritmo vencido) com a ficha de
 *    preparação da visita (o que compra, o que deixou de comprar, o que propor e o risco).
 *  - Anomalias de vendas e qualidade dos dados (tarefas para corrigir na origem).
 *
 * Os cálculos são funções puras (testáveis); a leitura do Pinguim BO e a gravação ficam em naval_cart_noite().
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/analise.php';

const NAVAL_CART_MIN_COMPRAS = 3;      // compras na janela para ter ritmo próprio
const NAVAL_CART_FAIXA_HABITUAL = 0.4; // artigo habitual: comprado em 40 % das compras do cliente
const NAVAL_CART_PENETRACAO = 0.4;     // artigo a propor: comprado por 40 % dos clientes da rota

function naval_cart_pasta(): string
{
    return naval_pasta('carteira');
}

/** Dias inteiros entre duas datas AAAA-MM-DD. */
function naval_cart_dias(string $de, string $ate): int
{
    return (int) round((strtotime($ate . ' 12:00') - strtotime($de . ' 12:00')) / 86400);
}

/**
 * Histórico de cada cliente nos agregados: {cliente: {n, r (rota mais recente), datas[], t (total), art: {cod: [qtd por compra]}}}
 * e os nomes dos artigos {cod: nome}.
 */
function naval_cart_historico(array $agregados): array
{
    ksort($agregados);
    $cl = [];
    $nomes = [];
    foreach ($agregados as $dia => $ag) {
        foreach ((array) ($ag['artigos'] ?? []) as $arts) foreach ((array) $arts as $cod => $a) if (!isset($nomes[$cod]) && ($a['n'] ?? '') !== '') $nomes[(string) $cod] = (string) $a['n'];
        foreach ((array) ($ag['clientes'] ?? []) as $c => $x) {
            $c = (string) $c;
            $h = $cl[$c] ?? ['n' => '', 'r' => '', 'datas' => [], 't' => 0.0, 'art' => []];
            if (($x['n'] ?? '') !== '') $h['n'] = (string) $x['n'];
            if (($x['r'] ?? '') !== '') $h['r'] = (string) $x['r'];
            $h['datas'][] = (string) $dia;
            $h['t'] += (float) ($x['t'] ?? 0);
            foreach ((array) (($ag['ca'] ?? [])[$c] ?? []) as $cod => $q) if ((float) $q > 0) $h['art'][(string) $cod][] = (float) $q;
            $cl[$c] = $h;
        }
    }
    return [$cl, $nomes];
}

/**
 * Ritmo de um cliente: intervalo mediano entre compras (dias), dias desde a última e estado.
 * em_dia: até 1,25 × o intervalo; a_abrandar: até 2 ×; parado: mais de 2 × (e pelo menos 7 dias).
 */
function naval_cart_ritmo(array $datas, string $hoje): ?array
{
    $datas = array_values(array_unique($datas));
    sort($datas);
    if (count($datas) < NAVAL_CART_MIN_COMPRAS) return null;
    $gaps = [];
    for ($i = 1; $i < count($datas); $i++) $gaps[] = naval_cart_dias($datas[$i - 1], $datas[$i]);
    $int = max(1.0, naval_mediana($gaps));
    $ult = end($datas);
    $dias = naval_cart_dias($ult, $hoje);
    $razao = $dias / $int;
    $estado = $razao <= 1.25 ? 'em_dia' : ($razao <= 2 || $dias < 7 ? 'a_abrandar' : 'parado');
    return ['intervalo' => round($int, 1), 'ult' => $ult, 'dias' => $dias, 'atraso' => round($razao, 2), 'estado' => $estado,
        'proxima' => date('Y-m-d', strtotime($ult . ' 12:00') + (int) round($int) * 86400), 'compras' => count($datas)];
}

/** Encomenda sugerida: artigos habituais (≥ 40 % das compras) com a quantidade mediana das últimas 6 compras. */
function naval_cart_encomenda(array $h, array $nomes): array
{
    $n = count(array_unique($h['datas']));
    $out = [];
    foreach ($h['art'] as $cod => $qs) {
        if ($n === 0 || count($qs) / $n < NAVAL_CART_FAIXA_HABITUAL) continue;
        $q = naval_mediana(array_slice($qs, -6));
        if ($q <= 0) continue;
        $out[] = ['cod' => (string) $cod, 'n' => $nomes[$cod] ?? (string) $cod, 'q' => round($q, 2), 'freq' => round(count($qs) / $n, 2)];
    }
    usort($out, function ($a, $b) { return $b['freq'] <=> $a['freq'] ?: strcmp($a['cod'], $b['cod']); });
    return array_slice($out, 0, 8);
}

/** Artigos habituais que faltaram nas últimas 3 compras (o cliente deixou de os comprar connosco). */
function naval_cart_deixou(array $h, array $agregados, string $cliente, array $nomes): array
{
    $datas = array_values(array_unique($h['datas']));
    sort($datas);
    if (count($datas) < 5) return [];
    $ult = array_slice($datas, -3);
    $out = [];
    foreach ($h['art'] as $cod => $qs) {
        if (count($qs) / count($datas) < NAVAL_CART_FAIXA_HABITUAL) continue;
        $recente = false;
        foreach ($ult as $d) if ((float) ((($agregados[$d]['ca'] ?? [])[$cliente] ?? [])[$cod] ?? 0) > 0) $recente = true;
        if (!$recente) $out[] = ['cod' => (string) $cod, 'n' => $nomes[$cod] ?? (string) $cod];
    }
    return array_slice($out, 0, 3);
}

/** Penetração de cada artigo por rota: {rota: {cod: fracção de clientes da rota que o compraram}}. */
function naval_cart_penetracao(array $hist): array
{
    $clientesRota = [];
    $compradores = [];
    foreach ($hist as $c => $h) {
        if ($h['r'] === '') continue;
        $clientesRota[$h['r']] = ($clientesRota[$h['r']] ?? 0) + 1;
        foreach (array_keys($h['art']) as $cod) $compradores[$h['r']][$cod] = ($compradores[$h['r']][$cod] ?? 0) + 1;
    }
    $out = [];
    foreach ($compradores as $r => $arts) {
        foreach ($arts as $cod => $n) $out[$r][(string) $cod] = round($n / max(1, $clientesRota[$r]), 3);
        arsort($out[$r]);
    }
    return $out;
}

/** Venda cruzada: os 2 artigos mais comprados na rota (≥ 40 % dos clientes) que este cliente nunca comprou. */
function naval_cart_propor(array $h, array $pen, array $nomes): array
{
    $out = [];
    foreach ((array) ($pen[$h['r']] ?? []) as $cod => $p) {
        if ($p < NAVAL_CART_PENETRACAO) break;
        if (isset($h['art'][$cod])) continue;
        $out[] = ['cod' => (string) $cod, 'n' => $nomes[$cod] ?? (string) $cod, 'pen' => $p];
        if (count($out) >= 2) break;
    }
    return $out;
}

/**
 * Risco de crédito por cliente a partir dos documentos (vendas com total, valor pago e data):
 * alto = factura em aberto há mais de 30 dias; médio = em aberto há 16 a 30 dias ou em aberto acima de 2 compras
 * médias; baixo = o resto. Devolve {cliente: {risco, aberto, dias}}.
 */
function naval_cart_risco(array $vendas, string $hoje): array
{
    $cl = [];
    foreach ($vendas as $v) {
        if (!empty($v['nc'])) continue;
        $c = (string) ($v['cliente_codigo'] ?? '');
        if ($c === '') continue;
        $x = $cl[$c] ?? ['aberto' => 0.0, 'dias' => 0, 'total' => 0.0, 'n' => 0];
        $tot = (float) ($v['total'] ?? 0);
        $pend = max(0.0, $tot - (float) ($v['valor_pago'] ?? ($v['liquidado'] ?? false ? $tot : 0)));
        $x['total'] += $tot;
        $x['n']++;
        if ($pend > 0.5) {
            $x['aberto'] += $pend;
            $x['dias'] = max($x['dias'], naval_cart_dias((string) $v['data'], $hoje));
        }
        $cl[$c] = $x;
    }
    $out = [];
    foreach ($cl as $c => $x) {
        $media = $x['total'] / max(1, $x['n']);
        $risco = $x['dias'] > 30 ? 'alto' : (($x['dias'] > 15 || ($x['aberto'] > 2 * $media && $x['aberto'] > 0)) ? 'medio' : 'baixo');
        $out[$c] = ['risco' => $risco, 'aberto' => round($x['aberto'], 2), 'dias' => $x['dias']];
    }
    return $out;
}

/**
 * Anomalias de vendas (prática de auditoria contínua): notas de crédito acima de 20 % das vendas do cliente,
 * vários documentos do mesmo cliente no mesmo dia, documentos liquidados sem meio de cobrança e descontos muito
 * acima do habitual do artigo (acima de 3 × a mediana e de 10 %).
 */
function naval_cart_anomalias(array $vendas, array $artigos): array
{
    $out = [];
    $porCli = [];
    $porDia = [];
    foreach ($vendas as $v) {
        $c = (string) ($v['cliente_codigo'] ?? '');
        if ($c === '') continue;
        $k = $c . '|' . ($v['data'] ?? '');
        if (!empty($v['nc'])) $porCli[$c]['nc'] = ($porCli[$c]['nc'] ?? 0) + abs((float) $v['total']);
        else {
            $porCli[$c]['v'] = ($porCli[$c]['v'] ?? 0) + (float) $v['total'];
            $porDia[$k][] = $v;
        }
        $porCli[$c]['n'] = (string) ($v['cliente'] ?? '');
        $porCli[$c]['r'] = (string) ($v['rota'] ?? '');
        foreach ((array) ($v['pagamentos'] ?? []) as $p) {
            if (($p['codigo'] ?? '') === '?' && (float) $p['valor'] > 0) {
                $out[] = ['tipo' => 'sem_meio', 'rota' => (string) $v['rota'], 'cliente' => (string) ($v['cliente'] ?? ''), 'documento' => (string) $v['documento'],
                    'data' => (string) $v['data'], 'valor' => round((float) $p['valor'], 2), 'texto' => 'Liquidado sem meio de cobrança no Pinguim BO'];
            }
        }
    }
    foreach ($porCli as $c => $x) {
        $vend = (float) ($x['v'] ?? 0);
        $nc = (float) ($x['nc'] ?? 0);
        if ($nc > 0 && $nc > 0.2 * max($vend, 1)) {
            $out[] = ['tipo' => 'notas_credito', 'rota' => $x['r'], 'cliente' => $x['n'], 'documento' => '', 'data' => '', 'valor' => round($nc, 2),
                'texto' => 'Notas de crédito acima de 20 % das vendas do cliente (' . ($vend > 0 ? round($nc / $vend * 100) : 100) . ' %)'];
        }
    }
    foreach ($porDia as $k => $docs) {
        if (count($docs) < 3) continue;
        $out[] = ['tipo' => 'repetidos', 'rota' => (string) $docs[0]['rota'], 'cliente' => (string) ($docs[0]['cliente'] ?? ''), 'documento' => implode(', ', array_column($docs, 'documento')),
            'data' => (string) $docs[0]['data'], 'valor' => round(array_sum(array_column($docs, 'total')), 2), 'texto' => count($docs) . ' documentos do mesmo cliente no mesmo dia'];
    }
    $desc = [];
    foreach ($artigos as $a) {
        $bruto = (float) ($a['valor_sem_iva'] ?? 0) + (float) ($a['desconto'] ?? 0);
        if ($bruto <= 0 || !empty($a['nc'])) continue;
        $desc[(string) $a['codigo']][] = ['p' => (float) $a['desconto'] / $bruto, 'a' => $a];
    }
    foreach ($desc as $cod => $xs) {
        $med = naval_mediana(array_column($xs, 'p'));
        foreach ($xs as $x) {
            if ($x['p'] > 0.1 && $x['p'] > 3 * $med) {
                $a = $x['a'];
                $out[] = ['tipo' => 'desconto', 'rota' => (string) ($a['rota'] ?? ''), 'cliente' => '', 'documento' => (string) ($a['documento'] ?? ''), 'data' => (string) ($a['data'] ?? ''),
                    'valor' => round((float) $a['desconto'], 2), 'texto' => 'Desconto de ' . round($x['p'] * 100) . ' % em ' . ($a['artigo'] ?? $cod) . ' (habitual ' . round($med * 100) . ' %)'];
            }
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['data'], $a['data']) ?: $b['valor'] <=> $a['valor']; });
    return array_slice($out, 0, 300);
}

/**
 * Carteira completa a partir dos agregados (120 dias) e dos documentos recentes (90 dias): clientes com ritmo,
 * encomenda, deixou de comprar, a propor e risco, mais os dias habituais de compra.
 */
function naval_cart_calcular(array $agregados, array $vendas, string $hoje): array
{
    [$hist, $nomes] = naval_cart_historico($agregados);
    $pen = naval_cart_penetracao($hist);
    $risco = naval_cart_risco($vendas, $hoje);
    $clientes = [];
    foreach ($hist as $c => $h) {
        $r = naval_cart_ritmo($h['datas'], $hoje);
        if ($r === null) continue;
        $dias = [];
        foreach (array_unique($h['datas']) as $d) $dias[naval_dia_semana($d)] = ($dias[naval_dia_semana($d)] ?? 0) + 1;
        arsort($dias);
        $clientes[$c] = ['c' => (string) $c, 'n' => $h['n'], 'r' => $h['r'], 'dia' => (int) array_keys($dias)[0], 'media' => round($h['t'] / max(1, count($h['datas'])), 2)]
            + $r + ['encomenda' => naval_cart_encomenda($h, $nomes), 'deixou' => naval_cart_deixou($h, $agregados, (string) $c, $nomes),
            'propor' => naval_cart_propor($h, $pen, $nomes), 'risco' => $risco[$c] ?? ['risco' => 'baixo', 'aberto' => 0, 'dias' => 0]];
    }
    return ['gerado' => date('Y-m-d H:i:s'), 'dia' => $hoje, 'clientes' => $clientes];
}

/**
 * Visitas de um dia por rota: clientes do dia habitual de compra e os de ritmo vencido (próxima compra até ao dia),
 * os parados primeiro e, dentro de cada estado, os de maior valor por compra.
 */
function naval_cart_visitas(array $carteira, string $dia, ?array $rotas): array
{
    $ds = naval_dia_semana($dia);
    $ordem = ['parado' => 0, 'a_abrandar' => 1, 'em_dia' => 2];
    $out = [];
    foreach ((array) ($carteira['clientes'] ?? []) as $c) {
        if ($rotas !== null && !in_array((string) $c['r'], $rotas, true)) continue;
        $habitual = (int) $c['dia'] === $ds;
        $vencido = $c['proxima'] <= $dia && $c['estado'] !== 'em_dia';
        if (!$habitual && !$vencido) continue;
        $c['motivo'] = $habitual ? ($vencido ? 'dia habitual e compra em atraso' : 'dia habitual de compra') : 'compra em atraso';
        $out[$c['r']][] = $c;
    }
    ksort($out);
    foreach ($out as &$l) {
        usort($l, function ($a, $b) use ($ordem) { return $ordem[$a['estado']] <=> $ordem[$b['estado']] ?: $b['media'] <=> $a['media']; });
    }
    unset($l);
    return $out;
}

/** Ficheiro da carteira mais recente (até 3 dias). */
function naval_cart_ler(): array
{
    $fs = glob(naval_cart_pasta() . '/????-??-??.json') ?: [];
    rsort($fs);
    return $fs ? naval_json_ler($fs[0]) : [];
}

/**
 * Cálculo nocturno (04:00–07:00, uma vez por dia): carteira, anomalias e qualidade dos dados. Só leitura no
 * Pinguim BO. Devolve o que fez.
 */
function naval_cart_noite(int $agora): array
{
    $hoje = date('Y-m-d', $agora);
    $f = naval_cart_pasta() . '/' . $hoje . '.json';
    if (date('H', $agora) < '04' || date('H', $agora) >= '07' || is_file($f)) return [];
    naval_servico('analise');
    naval_servico('vendas');
    @set_time_limit(600);
    $ag = naval_analise_agregados(date('Y-m-d', $agora - 120 * 86400), date('Y-m-d', $agora - 86400));
    $de = date('Ymd', $agora - 90 * 86400);
    $ate = date('Ymd', $agora - 86400);
    $vendas = naval_vendas_efectivas((array) ((naval_vendas_sql($de, $ate, naval_promotores(), false) ?? ['vendas' => []])['vendas']));
    $recentes = naval_vendas_sql(date('Ymd', $agora - 7 * 86400), $ate, naval_promotores(), true) ?? ['vendas' => [], 'artigos' => []];
    $recentes['artigos'] = naval_vendas_efectivas_artigos((array) $recentes['artigos'], (array) $recentes['vendas']);
    $recentes['vendas'] = naval_vendas_efectivas((array) $recentes['vendas']);
    $cart = naval_cart_calcular($ag, $vendas, $hoje);
    $cart['anomalias'] = naval_cart_anomalias((array) $recentes['vendas'], (array) $recentes['artigos']);
    $cart['qualidade'] = naval_cart_qualidade($recentes);
    naval_json_gravar($f, $cart, false);
    // plano de visitas do dia congelado logo de manhã (base da conversão das visitas)
    try {
        naval_servico('conversao');
        naval_conv_plano($hoje, $cart);
    } catch (Throwable $e) {
        error_log('[plano de visitas] ' . $e->getMessage());
    }
    foreach (glob(naval_cart_pasta() . '/????-??-??.json') ?: [] as $x) if (basename($x) < date('Y-m-d', $agora - 15 * 86400) . '.json') @unlink($x);
    return ['clientes' => count($cart['clientes']), 'anomalias' => count($cart['anomalias'])];
}

/**
 * Qualidade dos dados (uma tarefa por problema): clientes com compras sem coordenadas e clientes no mesmo ponto
 * (da prospecção), artigos vendidos sem peso (limite de carga), documentos sem meio de cobrança e documentos por
 * comunicar à AGT há mais de 2 dias.
 */
function naval_cart_qualidade(array $recentes): array
{
    $q = [];
    try {
        naval_servico('prospeccao');
        $k = (array) (naval_json_ler(naval_geo_pasta() . '/vista.json')['carteira'] ?? []);
        foreach ((array) ($k['sem_gps'] ?? []) as $x) $q[] = ['tipo' => 'cliente_sem_gps', 'rota' => (string) $x['r'], 'texto' => 'Cliente ' . $x['n'] . ' (' . $x['c'] . ') sem coordenadas válidas'];
        foreach ((array) ($k['duplicados'] ?? []) as $g) $q[] = ['tipo' => 'clientes_mesmo_ponto', 'rota' => (string) $g['r'], 'texto' => count($g['clientes']) . ' clientes no mesmo ponto: ' . implode(', ', array_column($g['clientes'], 'n'))];
    } catch (Throwable $e) {
        error_log('[qualidade geo] ' . $e->getMessage());
    }
    try {
        naval_servico('carrinha');
        $pesos = naval_carga_pesos_sku();
        $vistos = [];
        foreach ((array) ($recentes['artigos'] ?? []) as $a) {
            $cod = (string) ($a['codigo'] ?? '');
            if ($cod === '' || isset($vistos[$cod]) || (float) ($pesos[$cod] ?? 0) > 0) continue;
            $vistos[$cod] = true;
            $q[] = ['tipo' => 'artigo_sem_peso', 'rota' => '', 'texto' => 'Artigo ' . ($a['artigo'] ?? $cod) . ' (' . $cod . ') sem peso no Pinguim BO'];
        }
    } catch (Throwable $e) {
        error_log('[qualidade pesos] ' . $e->getMessage());
    }
    foreach ((array) ($recentes['vendas'] ?? []) as $v) {
        foreach ((array) ($v['pagamentos'] ?? []) as $p) {
            if (($p['codigo'] ?? '') === '?' && (float) $p['valor'] > 0) {
                $q[] = ['tipo' => 'documento_sem_meio', 'rota' => (string) $v['rota'], 'texto' => 'Documento ' . $v['documento'] . ' de ' . naval_data_pt((string) $v['data']) . ' sem meio de cobrança'];
            }
        }
    }
    try {
        naval_servico('agt');
        foreach (naval_agt(date('Ymd', time() - 9 * 86400), date('Ymd', time() - 2 * 86400))['documentos'] as $d) {
            if ($d['estado'] === 'por_comunicar' && !$d['anulado']) $q[] = ['tipo' => 'agt_por_comunicar', 'rota' => (string) $d['rota'], 'texto' => 'Documento ' . $d['documento'] . ' de ' . naval_data_pt($d['data']) . ' por comunicar à AGT'];
            if ($d['estado'] === 'erro' && !$d['anulado']) $q[] = ['tipo' => 'agt_erro', 'rota' => (string) $d['rota'], 'texto' => 'Documento ' . $d['documento'] . ' de ' . naval_data_pt($d['data']) . ' recusado pela AGT' . (!empty($d['mensagem_agt']) ? ': ' . $d['mensagem_agt'] : '')];
        }
    } catch (Throwable $e) {
        error_log('[qualidade AGT] ' . $e->getMessage());
    }
    return array_slice($q, 0, 500);
}

/** Vista no âmbito de quem pede: visitas do dia, anomalias e qualidade só das suas rotas. */
function naval_cart_vista(string $dia, ?array $rotas): array
{
    $c = naval_cart_ler();
    $dentro = function ($x) use ($rotas) { return $rotas === null || in_array((string) ($x['rota'] ?? ''), $rotas, true); };
    $visitas = $c ? naval_cart_visitas($c, $dia, $rotas) : [];
    // pela ordem do percurso optimizado de cada rota (optimização automática da noite), quando existe
    naval_servico('roteamento');
    $ordem = naval_rot_ordem($dia);
    foreach ($visitas as $r => &$l) {
        if (empty($ordem[$r])) continue;
        foreach ($l as &$x) $x['ordem'] = $ordem[$r][(string) $x['c']] ?? null;
        unset($x);
        // ordenação estável: os clientes sem coordenadas ficam no fim, pela ordem de prioridade que já tinham
        $i = 0;
        foreach ($l as &$x) $x['_i'] = $i++;
        unset($x);
        usort($l, function ($a, $b) { return [$a['ordem'] ?? PHP_INT_MAX, $a['_i']] <=> [$b['ordem'] ?? PHP_INT_MAX, $b['_i']]; });
        foreach ($l as &$x) unset($x['_i']);
        unset($x);
    }
    unset($l);
    return ['gerado' => $c['gerado'] ?? null, 'base' => $c['dia'] ?? null, 'dia' => $dia, 'visitas' => $visitas,
        'anomalias' => array_values(array_filter((array) ($c['anomalias'] ?? []), $dentro)),
        'qualidade' => array_values(array_filter((array) ($c['qualidade'] ?? []), function ($x) use ($dentro, $rotas) { return $rotas === null || ($x['rota'] !== '' && $dentro($x)); }))];
}
