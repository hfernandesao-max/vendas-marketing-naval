<?php
/**
 * Camada de análise: agregados diários das vendas do Pinguim BO, partilhados pelos alertas, pela previsão, pelo
 * assistente e pelo resumo diário. Fonte única: a mesma das páginas de vendas (SQL Server, só SELECT; se não responder,
 * a exportação REST), nunca ficheiros Excel.
 *
 * Cada dia fica resumido em dados-privados/analise/AAAA-MM-DD.json (por rota, por cliente e por artigo, sem NIF):
 *   - dias com mais de NAVAL_ANALISE_DIAS_ABERTOS dias ficam gravados (as vendas desses dias já não mudam);
 *   - os dias recentes voltam a consultar-se ao fim de 10 minutos.
 * Os dias em falta consultam-se de uma só vez, em blocos de até 31 dias.
 *
 * As funções de cálculo (naval_analise_*) recebem os agregados e não lêem nada: são testadas sem base de dados.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/rotas.php';

const NAVAL_ANALISE_DIAS_ABERTOS = 3;    // dias recentes que ainda podem mudar (notas de crédito, sincronização tardia)
const NAVAL_ANALISE_HISTORICO = 120;     // dias de histórico usados nos alertas e na previsão
const NAVAL_ANALISE_VERSAO = 3;          // muda quando o formato dos agregados muda (os antigos voltam a calcular-se)

function naval_analise_pasta(): string
{
    return naval_pasta('analise');
}

/**
 * Resumo de um dia a partir das vendas e das linhas de artigos (formato de v1/vendas, sem âmbito aplicado).
 * {rotas: {rota: {t, ti, d: documentos, nc: notas de crédito}}, clientes: {código: {n, r, t, ti, d}},
 *  artigos: {rota: {código: {n, u, q, t, ti}}}, ca: {cliente: {código: quantidade}}} (ca: compras de cada cliente por
 *  artigo, base das sugestões de encomenda do cartaz QR). Totais líquidos (as notas de crédito já vêm em negativo).
 * Base de valor (prática internacional, IFRS 15): t = vendas líquidas SEM IVA (o IVA é da AGT, não é receita);
 * ti = o mesmo com IVA (para comparar com o que o cliente paga). Sem o valor sem IVA do documento, usa-se o total.
 */
function naval_analise_resumir(array $vendas, array $artigos): array
{
    $r = ['rotas' => [], 'clientes' => [], 'artigos' => [], 'ca' => []];
    $docCliente = [];
    foreach ($vendas as $v) {
        if (empty($v['nc']) && (string) ($v['cliente_codigo'] ?? '') !== '') $docCliente[(string) ($v['documento'] ?? '')] = (string) $v['cliente_codigo'];
        $rota = (string) ($v['rota'] ?? '');
        if ($rota === '') continue;
        $si = isset($v['total_sem_iva']) ? (float) $v['total_sem_iva'] : (float) ($v['total'] ?? 0);
        if (!isset($r['rotas'][$rota])) $r['rotas'][$rota] = ['t' => 0.0, 'ti' => 0.0, 'd' => 0, 'nc' => 0];
        $r['rotas'][$rota]['t'] += $si;
        $r['rotas'][$rota]['ti'] += (float) ($v['total'] ?? 0);
        if (!empty($v['nc'])) $r['rotas'][$rota]['nc']++;
        else $r['rotas'][$rota]['d']++;
        $c = (string) ($v['cliente_codigo'] ?? '');
        if ($c === '' || !empty($v['nc'])) continue;
        if (!isset($r['clientes'][$c])) $r['clientes'][$c] = ['n' => (string) ($v['cliente'] ?? ''), 'r' => $rota, 't' => 0.0, 'ti' => 0.0, 'd' => 0];
        $r['clientes'][$c]['t'] += $si;
        $r['clientes'][$c]['ti'] += (float) ($v['total'] ?? 0);
        $r['clientes'][$c]['d']++;
    }
    foreach ($artigos as $a) {
        $rota = (string) ($a['rota'] ?? '');
        $cod = (string) ($a['codigo'] ?? '');
        if ($rota === '' || $cod === '') continue;
        if (!isset($r['artigos'][$rota][$cod])) {
            $r['artigos'][$rota][$cod] = ['n' => (string) ($a['artigo'] ?? ''), 'u' => (string) ($a['unidade'] ?? ''), 'q' => 0.0, 't' => 0.0, 'ti' => 0.0];
        }
        $r['artigos'][$rota][$cod]['q'] += (float) ($a['quantidade'] ?? 0);
        $r['artigos'][$rota][$cod]['t'] += (float) ($a['valor_sem_iva'] ?? $a['total'] ?? 0);
        $r['artigos'][$rota][$cod]['ti'] += (float) ($a['total'] ?? 0);
        $cl = $docCliente[(string) ($a['documento'] ?? '')] ?? '';
        if ($cl !== '' && empty($a['nc'])) $r['ca'][$cl][$cod] = round(($r['ca'][$cl][$cod] ?? 0) + (float) ($a['quantidade'] ?? 0), 3);
    }
    foreach (['rotas', 'clientes'] as $g) foreach ($r[$g] as $k => $x) [$r[$g][$k]['t'], $r[$g][$k]['ti']] = [round($x['t'], 2), round($x['ti'], 2)];
    foreach ($r['artigos'] as $rt => $l) foreach ($l as $k => $x) [$r['artigos'][$rt][$k]['t'], $r['artigos'][$rt][$k]['ti']] = [round($x['t'], 2), round($x['ti'], 2)];
    return $r;
}

/** Lista dos dias AAAA-MM-DD de $de a $ate (inclusive). */
function naval_analise_dias(string $de, string $ate): array
{
    $out = [];
    for ($t = strtotime($de . ' 12:00'), $f = strtotime($ate . ' 12:00'); $t <= $f; $t += 86400) $out[] = date('Y-m-d', $t);
    return $out;
}

/**
 * Agregados diários de $de a $ate (AAAA-MM-DD): {dia: resumo}. Dias sem vendas vêm com o resumo vazio (zero conta
 * como valor real). $leitor (testes) substitui a consulta ao Pinguim BO: function(de, ate): {vendas, artigos}.
 */
function naval_analise_agregados(string $de, string $ate, ?callable $leitor = null): array
{
    $pasta = naval_analise_pasta();
    $hoje = date('Y-m-d');
    $limiteFixo = date('Y-m-d', strtotime($hoje . ' 12:00') - NAVAL_ANALISE_DIAS_ABERTOS * 86400);
    $out = [];
    $faltam = [];
    foreach (naval_analise_dias($de, min($ate, $hoje)) as $dia) {
        $f = $pasta . '/' . $dia . '.json';
        $x = is_file($f) ? naval_json_ler($f) : [];
        $valido = ($x['v'] ?? 0) === NAVAL_ANALISE_VERSAO
            && ($dia < $limiteFixo ? !empty($x['fixo']) || filemtime($f) >= strtotime($dia . ' 23:59') + NAVAL_ANALISE_DIAS_ABERTOS * 86400
                : filemtime($f) >= time() - 600);
        if ($valido) $out[$dia] = $x;
        else $faltam[] = $dia;
    }
    // dias em falta seguidos consultam-se juntos (blocos de até 31 dias)
    $blocos = [];
    foreach ($faltam as $dia) {
        $n = count($blocos);
        if ($n && count($blocos[$n - 1]) < 31 && date('Y-m-d', strtotime(end($blocos[$n - 1]) . ' 12:00') + 86400) === $dia) $blocos[$n - 1][] = $dia;
        else $blocos[] = [$dia];
    }
    foreach ($blocos as $b) {
        $ini = $b[0];
        $fim = end($b);
        $d = $leitor ? $leitor($ini, $fim) : naval_analise_ler_pinguim($ini, $fim);
        $porDia = [];
        foreach ((array) ($d['vendas'] ?? []) as $v) $porDia[(string) ($v['data'] ?? '')]['vendas'][] = $v;
        foreach ((array) ($d['artigos'] ?? []) as $a) $porDia[(string) ($a['data'] ?? '')]['artigos'][] = $a;
        foreach ($b as $dia) {
            $res = naval_analise_resumir($porDia[$dia]['vendas'] ?? [], $porDia[$dia]['artigos'] ?? []);
            $res = ['v' => NAVAL_ANALISE_VERSAO, 'dia' => $dia, 'fixo' => $dia < $limiteFixo] + $res;
            naval_json_gravar($pasta . '/' . $dia . '.json', $res, false);
            $out[$dia] = $res;
        }
    }
    ksort($out);
    return $out;
}

/** Vendas e linhas de artigos de um período (AAAA-MM-DD) lidas do Pinguim BO, sem âmbito: só para os agregados. */
function naval_analise_ler_pinguim(string $de, string $ate): array
{
    naval_servico('bd');
    naval_servico('pinguim');
    naval_servico('vendas');
    @set_time_limit(300);
    $a = str_replace('-', '', $de);
    $b = str_replace('-', '', $ate);
    $prom = naval_promotores();
    $x = naval_vendas_sql($a, $b, $prom, true);
    if ($x === null) $x = naval_vendas_do_xml(naval_pinguim_xml($a, $b), $prom, true);
    return $x;
}

/**
 * Âmbito de quem pede: só as rotas permitidas ($rotas null = todas); sem páginas de vendas ($veValores false) os
 * valores e os clientes saem e ficam só as quantidades por artigo (como em naval_vendas_ambito).
 */
function naval_analise_ambito(array $agregados, ?array $rotas, bool $veValores): array
{
    foreach ($agregados as $dia => $x) {
        if ($rotas !== null) {
            $x['rotas'] = array_intersect_key($x['rotas'] ?? [], array_flip($rotas));
            $x['artigos'] = array_intersect_key($x['artigos'] ?? [], array_flip($rotas));
            $x['clientes'] = array_filter($x['clientes'] ?? [], function ($c) use ($rotas) { return in_array((string) $c['r'], $rotas, true); });
            $x['ca'] = array_intersect_key($x['ca'] ?? [], $x['clientes']);
        }
        if (!$veValores) {
            foreach ($x['rotas'] as $k => $r) $x['rotas'][$k]['t'] = null;
            $x['clientes'] = [];
            $x['ca'] = [];
            foreach ($x['artigos'] as $rt => $arts) foreach ($arts as $c => $a) $x['artigos'][$rt][$c]['t'] = null;
        }
        $agregados[$dia] = $x;
    }
    return $agregados;
}

// ───────────────────────────── Estatística ─────────────────────────────

function naval_mediana(array $v): float
{
    $v = array_values($v);
    $n = count($v);
    if (!$n) return 0.0;
    sort($v);
    return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
}

/** Desvio absoluto mediano (MAD) escalado para ser comparável ao desvio-padrão (×1,4826). */
function naval_mad(array $v): float
{
    $m = naval_mediana($v);
    return 1.4826 * naval_mediana(array_map(function ($x) use ($m) { return abs($x - $m); }, $v));
}

/** Série diária de vendas de uma rota (ou de todas, $rota null): {dia: total}. */
/** Série diária de vendas de uma rota (ou do total): $campo 't' = sem IVA (base de gestão) ou 'ti' = com IVA. */
function naval_analise_serie(array $agregados, ?string $rota, string $campo = 't'): array
{
    $s = [];
    foreach ($agregados as $dia => $x) {
        $t = 0.0;
        foreach (($x['rotas'] ?? []) as $rt => $r) if ($rota === null || (string) $rt === $rota) $t += (float) ($r[$campo] ?? $r['t']);
        $s[$dia] = round($t, 2);
    }
    return $s;
}

/** Rotas com vendas nos agregados (por ordem). */
function naval_analise_rotas(array $agregados): array
{
    $r = [];
    foreach ($agregados as $x) foreach (array_keys($x['rotas'] ?? []) as $rt) $r[(string) $rt] = true;
    $r = array_keys($r);
    sort($r);
    return array_map('strval', $r);
}

/** Dia da semana ISO (1 = segunda … 7 = domingo). */
function naval_dia_semana(string $dia): int
{
    return (int) date('N', strtotime($dia . ' 12:00'));
}

/**
 * Valores do mesmo dia da semana nas $semanas anteriores a $dia (só dias presentes na série).
 * Base dos alertas e da previsão: as vendas do RTM têm um ciclo semanal forte (rotas por dia da semana).
 */
function naval_mesmos_dias(array $serie, string $dia, int $semanas): array
{
    $out = [];
    for ($k = 1; $k <= $semanas; $k++) {
        $d = date('Y-m-d', strtotime($dia . ' 12:00') - $k * 7 * 86400);
        if (array_key_exists($d, $serie)) $out[$d] = (float) $serie[$d];
    }
    return $out;
}

/** Formata um valor em kwanzas para os textos (1 234 567 Kz). */
function naval_kz(float $v): string
{
    return number_format($v, 0, ',', ' ') . ' Kz';
}
