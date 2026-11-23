<?php
/**
 * Inteligência de negócio › Análises de gestão: as análises que as melhores empresas de bens de consumo e de
 * distribuição usam, calculadas sobre os agregados diários das vendas do Pinguim BO (nucleo/servicos/analise.php,
 * só SELECT) e sobre o stock da função dbo.GET_STOCK. Só dias completos (até ontem).
 *
 *   1. RFM (recência, frequência, valor): segmentação dos clientes em 8 grupos, com a acção recomendada a cada um e o
 *      valor anual em risco (clientes valiosos que deixaram de comprar).
 *   2. Coortes de retenção: dos clientes que compraram pela primeira vez em cada mês, quantos voltam nos meses seguintes.
 *   3. Valor do cliente (CLV) por rota: valor mensal por cliente ÷ taxa mensal de perda de clientes.
 *   4. Ponte preço-volume-mix: a variação do mês até ontem face aos mesmos dias do mês anterior, separada em preço,
 *      volume, mix de artigos, artigos novos e artigos perdidos (análise de margem das multinacionais de FMCG).
 *   5. Matriz ABC-XYZ dos artigos: valor (ABC) × regularidade da procura semanal (XYZ, coeficiente de variação),
 *      com a política de stock recomendada para cada célula.
 *   6. Cesto de compras (regras de associação): artigos comprados juntos (suporte, confiança, lift) e quantos
 *      clientes compram um e ainda não compram o outro (venda cruzada).
 *   7. Concentração (índice Herfindahl-Hirschman): dependência de poucos clientes, artigos ou rotas.
 *   8. Produtividade das rotas face à melhor prática interna (quartil superior): compras por dia e valor por compra,
 *      com o potencial em Kz de cada rota chegar ao quartil superior.
 *   9. Cobertura de stock: dias de venda que o stock (armazéns e carrinhas) cobre, roturas, excesso e stock parado,
 *      com o capital empatado (ao preço médio de venda sem IVA).
 *
 * As funções recebem os dados e não lêem nada: são testadas sem base de dados.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/analise.php';

const NAVAL_AG_DIAS = 210;              // 7 meses de agregados (6 de coortes com 1 de referência)
const NAVAL_AG_RFM_DIAS = 180;
const NAVAL_AG_SEGMENTOS = [
    'campeoes' => ['Campeões', 'Compram muito, muitas vezes e recentemente. Proteger: prioridade na carga e nas visitas, lançamentos primeiro.'],
    'leais' => ['Leais', 'Compradores regulares. Aumentar o cesto: propor os artigos que ainda não compram (venda cruzada).'],
    'promissores' => ['Promissores', 'Compraram há pouco, mas pouco. Converter: visita de seguimento e encomenda sugerida.'],
    'atencao' => ['Precisam de atenção', 'Abaixo do habitual. Rever o dia de visita e a encomenda sugerida.'],
    'nao_perder' => ['Não pode perder', 'Eram dos melhores e deixaram de comprar. Visita do coordenador esta semana.'],
    'em_risco' => ['Em risco', 'Compravam com regularidade e estão a afastar-se. Contacto do vendedor e condições.'],
    'hibernar' => ['A hibernar', 'Pouco valor e há algum tempo sem comprar. Incluir na rota quando houver lugar livre.'],
    'perdidos' => ['Perdidos', 'Sem compras há muito tempo. Confirmar se o ponto de venda ainda existe; senão, retirar da rota.'],
];
const NAVAL_AG_XYZ = [
    'AX' => 'Stock baixo e reposição automática frequente (procura previsível).',
    'AY' => 'Stock de segurança moderado; rever a previsão todas as semanas.',
    'AZ' => 'Alto valor e procura irregular: reposição por encomenda, acompanhar de perto.',
    'BX' => 'Reposição automática com stock de segurança normal.',
    'BY' => 'Stock de segurança normal; rever todos os meses.',
    'BZ' => 'Reduzir o stock; carregar por encomenda.',
    'CX' => 'Reposição automática com lotes maiores (pouco esforço).',
    'CY' => 'Lotes maiores, pouco stock de segurança.',
    'CZ' => 'Candidato a sair do sortido ou a venda só por encomenda.',
];

/** Pontuação 1 a 5 por quintil (posição média na ordem crescente: empates com a mesma nota, ao meio do grupo). */
function naval_ag_quintis(array $valores): array
{
    $ord = array_values($valores);
    sort($ord);
    $n = count($ord);
    $pos = [];
    foreach ($ord as $i => $v) $pos[(string) $v][] = $i;
    $out = [];
    foreach ($valores as $k => $v) {
        $p = $pos[(string) $v];
        $out[$k] = min(5, 1 + (int) floor(5 * (($p[0] + end($p)) / 2) / $n));
    }
    return $out;
}

/** Percentil p (0..1) com interpolação linear. */
function naval_ag_percentil(array $v, float $p): ?float
{
    $v = array_values($v);
    $n = count($v);
    if (!$n) return null;
    sort($v);
    $x = ($n - 1) * $p;
    $i = (int) floor($x);
    return $i + 1 < $n ? $v[$i] + ($x - $i) * ($v[$i + 1] - $v[$i]) : (float) $v[$n - 1];
}

/** Segmento RFM a partir da recência (R) e da média da frequência e do valor (FM), notas 1 a 5. */
function naval_ag_segmento(int $r, int $fm): string
{
    if ($r >= 4) return $fm >= 4 ? 'campeoes' : ($fm >= 3 ? 'leais' : 'promissores');
    if ($r === 3) return $fm >= 3 ? 'leais' : 'atencao';
    if ($fm >= 4) return 'nao_perder';
    if ($fm === 3) return 'em_risco';
    return $r === 2 ? 'hibernar' : 'perdidos';
}

/** Lista de dias de $ag de $de a $ate (inclusive). */
function naval_ag_janela(array $ag, string $de, string $ate): array
{
    return array_filter($ag, function ($d) use ($de, $ate) { return $d >= $de && $d <= $ate; }, ARRAY_FILTER_USE_KEY);
}

function naval_ag_menos(string $dia, int $n): string
{
    return date('Y-m-d', strtotime($dia . ' 12:00') - $n * 86400);
}

/** 1. RFM dos clientes nos últimos 180 dias até $fim. */
function naval_ag_rfm(array $ag, string $fim, int $lista = 1000): array
{
    $cl = [];
    foreach (naval_ag_janela($ag, naval_ag_menos($fim, NAVAL_AG_RFM_DIAS - 1), $fim) as $d => $x) {
        foreach (($x['clientes'] ?? []) as $c => $v) {
            if ((float) $v['t'] <= 0) continue;
            $c = (string) $c;
            if (!isset($cl[$c])) $cl[$c] = ['codigo' => $c, 'nome' => (string) $v['n'], 'rota' => (string) $v['r'], 'ultima' => $d, 'f' => 0, 'm' => 0.0];
            $cl[$c]['f']++;
            $cl[$c]['m'] += (float) $v['t'];
            if ($d >= $cl[$c]['ultima']) {
                $cl[$c]['ultima'] = $d;
                $cl[$c]['rota'] = (string) $v['r'];
            }
        }
    }
    $t0 = strtotime($fim . ' 12:00');
    $rec = $fr = $mo = [];
    foreach ($cl as $c => $x) {
        $cl[$c]['recencia'] = (int) round(($t0 - strtotime($x['ultima'] . ' 12:00')) / 86400);
        $rec[$c] = -$cl[$c]['recencia'];   // mais recente = nota maior
        $fr[$c] = $x['f'];
        $mo[$c] = round($x['m'], 2);
    }
    [$qr, $qf, $qm] = [naval_ag_quintis($rec), naval_ag_quintis($fr), naval_ag_quintis($mo)];
    $seg = [];
    foreach (NAVAL_AG_SEGMENTOS as $k => $s) $seg[$k] = ['segmento' => $k, 'nome' => $s[0], 'accao' => $s[1], 'clientes' => 0, 'valor' => 0.0, 'recencia' => 0];
    $total = 0.0;
    foreach ($cl as $c => $x) {
        $fm = (int) round(($qf[$c] + $qm[$c]) / 2);
        $s = naval_ag_segmento($qr[$c], $fm);
        $cl[$c] += ['r' => $qr[$c], 'fq' => $qf[$c], 'mq' => $qm[$c], 'segmento' => $s];
        $cl[$c]['m'] = round($x['m'], 2);
        $seg[$s]['clientes']++;
        $seg[$s]['valor'] += $x['m'];
        $seg[$s]['recencia'] += $cl[$c]['recencia'];
        $total += $x['m'];
    }
    foreach ($seg as $k => $s) {
        $seg[$k]['valor'] = round($s['valor'], 2);
        $seg[$k]['pct_valor'] = $total > 0 ? round($s['valor'] / $total, 4) : null;
        $seg[$k]['recencia'] = $s['clientes'] ? (int) round($s['recencia'] / $s['clientes']) : null;
    }
    $risco = ($seg['nao_perder']['valor'] + $seg['em_risco']['valor']) * 365 / NAVAL_AG_RFM_DIAS;
    $lst = array_values($cl);
    usort($lst, function ($a, $b) { return $b['m'] <=> $a['m']; });
    $lst = array_map(function ($x) {
        return ['cliente_codigo' => $x['codigo'], 'cliente' => $x['nome'], 'rota' => $x['rota'], 'segmento' => $x['segmento'], 'recencia' => $x['recencia'],
            'compras' => $x['f'], 'valor' => $x['m'], 'rfm' => $x['r'] . $x['fq'] . $x['mq'], 'ultima' => $x['ultima']];
    }, array_slice($lst, 0, $lista));
    return ['dias' => NAVAL_AG_RFM_DIAS, 'clientes' => count($cl), 'valor' => round($total, 2), 'valor_anual_em_risco' => round($risco, 2),
        'segmentos' => array_values($seg), 'lista' => $lst];
}

/** Meses AAAA-MM de $de a $ate. */
function naval_ag_meses(string $de, string $ate): array
{
    $out = [];
    for ($m = substr($de, 0, 7); $m <= substr($ate, 0, 7); $m = date('Y-m', strtotime($m . '-01 12:00 +1 month'))) $out[] = $m;
    return $out;
}

/** {cliente: {AAAA-MM: true}} e rota mais recente de cada cliente. */
function naval_ag_por_mes(array $ag): array
{
    $pm = [];
    $rota = [];
    ksort($ag);
    foreach ($ag as $d => $x) foreach (($x['clientes'] ?? []) as $c => $v) {
        if ((float) $v['t'] <= 0) continue;
        $pm[(string) $c][substr($d, 0, 7)] = true;
        $rota[(string) $c] = (string) $v['r'];
    }
    return [$pm, $rota];
}

/**
 * 2. Coortes de retenção por mês da primeira compra. O primeiro mês dos dados não forma coorte (os clientes desse mês
 * podem ter comprado antes). O último mês, se for o mês em curso, vem marcado como incompleto.
 */
function naval_ag_coortes(array $ag, string $fim): array
{
    if (!$ag) return ['meses' => [], 'coortes' => [], 'retencao_media' => []];
    $dias = array_keys($ag);
    sort($dias);
    $meses = naval_ag_meses($dias[0], $fim);
    [$pm] = naval_ag_por_mes(naval_ag_janela($ag, $dias[0], $fim));
    $idx = array_flip($meses);
    $coortes = [];
    foreach ($pm as $c => $ms) {
        $prim = min(array_keys($ms));
        if ($prim === $meses[0]) continue;
        $coortes[$prim]['n'] = ($coortes[$prim]['n'] ?? 0) + 1;
        foreach (array_keys($ms) as $m) {
            $k = $idx[$m] - $idx[$prim];
            $coortes[$prim]['k'][$k] = ($coortes[$prim]['k'][$k] ?? 0) + 1;
        }
    }
    ksort($coortes);
    $incompleto = $fim < date('Y-m-t', strtotime($fim . ' 12:00'));
    $out = [];
    $soma = [];
    foreach ($coortes as $m => $x) {
        $ret = [];
        for ($k = 0; $k <= count($meses) - 1 - $idx[$m]; $k++) $ret[] = round(($x['k'][$k] ?? 0) / $x['n'], 4);
        foreach ($ret as $k => $v) if ($k > 0 && !($incompleto && $idx[$m] + $k === count($meses) - 1)) $soma[$k][] = $v;
        $out[] = ['coorte' => $m, 'clientes' => $x['n'], 'retencao' => $ret];
    }
    $media = [];
    foreach ($soma as $k => $v) $media[$k] = round(array_sum($v) / count($v), 4);
    ksort($media);
    return ['meses' => $meses, 'ultimo_incompleto' => $incompleto, 'coortes' => $out, 'retencao_media' => $media];
}

/**
 * 3. Valor do cliente (CLV) por rota: valor mensal médio por cliente activo (180 dias) ÷ taxa mensal de perda (clientes
 * de cada um dos últimos 3 meses completos que não compraram no mês seguinte; mínimo 2 %), até 60 meses de vida.
 */
function naval_ag_clv(array $ag, string $fim, array $promotores = []): array
{
    [$pm, $rotaCli] = naval_ag_por_mes($ag);
    $rfmJan = naval_ag_janela($ag, naval_ag_menos($fim, NAVAL_AG_RFM_DIAS - 1), $fim);
    $valor = $act = [];
    foreach ($rfmJan as $x) foreach (($x['clientes'] ?? []) as $c => $v) if ((float) $v['t'] > 0) {
        $rt = (string) $v['r'];
        $valor[$rt] = ($valor[$rt] ?? 0) + (float) $v['t'];
        $act[$rt][(string) $c] = true;
    }
    // meses completos: o mês de $fim só conta se $fim for o último dia do mês
    $ultimoCompleto = $fim === date('Y-m-t', strtotime($fim . ' 12:00')) ? substr($fim, 0, 7) : date('Y-m', strtotime(substr($fim, 0, 7) . '-01 12:00 -1 month'));
    $perdas = [];
    for ($j = 3; $j >= 1; $j--) {
        $m0 = date('Y-m', strtotime($ultimoCompleto . "-01 12:00 -$j month"));
        $m1 = date('Y-m', strtotime($m0 . '-01 12:00 +1 month'));
        foreach ($pm as $c => $ms) {
            if (empty($ms[$m0])) continue;
            $rt = $rotaCli[$c] ?? '';
            $perdas[$rt]['base'] = ($perdas[$rt]['base'] ?? 0) + 1;
            if (empty($ms[$m1])) $perdas[$rt]['perdidos'] = ($perdas[$rt]['perdidos'] ?? 0) + 1;
        }
    }
    $out = [];
    foreach ($valor as $rt => $v) {
        $n = count($act[$rt]);
        $mensal = $v / $n / (NAVAL_AG_RFM_DIAS / 30.4);
        $base = $perdas[$rt]['base'] ?? 0;
        $churn = $base ? ($perdas[$rt]['perdidos'] ?? 0) / $base : null;
        $vida = $churn !== null ? min(60, 1 / max(0.02, $churn)) : null;
        $out[] = ['rota' => (string) $rt, 'promotor' => $promotores[$rt] ?? '', 'clientes' => $n, 'valor_mensal_cliente' => round($mensal, 2),
            'perda_mensal' => $churn !== null ? round($churn, 4) : null, 'vida_meses' => $vida !== null ? round($vida, 1) : null,
            'clv' => $vida !== null ? round($mensal * $vida, 2) : null, 'valor_carteira' => $vida !== null ? round($mensal * $vida * $n, 2) : null];
    }
    usort($out, function ($a, $b) { return ($b['valor_carteira'] ?? 0) <=> ($a['valor_carteira'] ?? 0); });
    return $out;
}

/** Quantidade e valor por artigo (todas as rotas ou uma) num período. */
function naval_ag_artigos(array $ag, ?string $rota = null): array
{
    $a = [];
    foreach ($ag as $x) foreach (($x['artigos'] ?? []) as $rt => $l) {
        if ($rota !== null && (string) $rt !== $rota) continue;
        foreach ($l as $cod => $v) {
            $cod = (string) $cod;
            if (!isset($a[$cod])) $a[$cod] = ['n' => (string) $v['n'], 'u' => (string) ($v['u'] ?? ''), 'q' => 0.0, 't' => 0.0];
            $a[$cod]['q'] += (float) $v['q'];
            $a[$cod]['t'] += (float) $v['t'];
        }
    }
    return $a;
}

/** Decomposição preço-volume-mix entre dois conjuntos de artigos {cod: {q, t}}. */
function naval_ag_pvm_calc(array $a0, array $a1): array
{
    $r = ['anterior' => 0.0, 'actual' => 0.0, 'preco' => 0.0, 'volume' => 0.0, 'mix' => 0.0, 'novos' => 0.0, 'perdidos' => 0.0, 'artigos' => []];
    $q0c = $q1c = $r0c = $r1aP0 = 0.0;
    foreach ($a0 as $x) $r['anterior'] += $x['t'];
    foreach ($a1 as $x) $r['actual'] += $x['t'];
    foreach ($a1 as $cod => $x) {
        $y = $a0[$cod] ?? null;
        if (!$y || $y['q'] <= 0 || $y['t'] <= 0 || $x['q'] <= 0) {
            // sem venda no período anterior: artigo novo; os casos sem quantidade (devoluções) ficam no mix pelo acerto final
            if (!$y || $y['t'] <= 0) $r['novos'] += $x['t'];
            continue;
        }
        $p0 = $y['t'] / $y['q'];
        $p1 = $x['t'] / $x['q'];
        $ef = ($p1 - $p0) * $x['q'];
        $r['preco'] += $ef;
        $q0c += $y['q'];
        $q1c += $x['q'];
        $r0c += $y['t'];
        $r1aP0 += $x['q'] * $p0;
        $r['artigos'][$cod] = ['codigo' => (string) $cod, 'nome' => $x['n'], 'preco_anterior' => round($p0, 2), 'preco_actual' => round($p1, 2),
            'variacao_preco' => round($p1 / $p0 - 1, 4), 'efeito_preco' => round($ef, 2), 'quantidade_anterior' => round($y['q'], 2), 'quantidade_actual' => round($x['q'], 2),
            'efeito_quantidade' => round(($x['q'] - $y['q']) * $p0, 2)];
    }
    foreach ($a0 as $cod => $y) if (!isset($a1[$cod]) && $y['t'] > 0) $r['perdidos'] -= $y['t'];
    $pMedio0 = $q0c > 0 ? $r0c / $q0c : 0;
    $r['volume'] = ($q1c - $q0c) * $pMedio0;
    $r['mix'] = $r1aP0 - $q1c * $pMedio0;
    // o que sobra do arredondamento e de artigos com valor sem quantidade fica no mix (a ponte fecha sempre)
    $r['mix'] += ($r['actual'] - $r['anterior']) - ($r['preco'] + $r['volume'] + $r['mix'] + $r['novos'] + $r['perdidos']);
    foreach (['anterior', 'actual', 'preco', 'volume', 'mix', 'novos', 'perdidos'] as $k) $r[$k] = round($r[$k], 2);
    return $r;
}

/** 4. Ponte preço-volume-mix: mês até $fim face aos mesmos dias do mês anterior (total e por rota). */
function naval_ag_pvm(array $ag, string $fim, array $promotores = []): array
{
    $t0 = strtotime($fim . ' 12:00');
    $iniMes = date('Y-m-01', $t0);
    $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $fimAnt = date('Y-m-d', min(strtotime($iniAnt . ' 12:00') + ((int) date('j', $t0) - 1) * 86400, strtotime($iniMes . ' 12:00') - 86400));
    $j1 = naval_ag_janela($ag, $iniMes, $fim);
    $j0 = naval_ag_janela($ag, $iniAnt, $fimAnt);
    $tot = naval_ag_pvm_calc(naval_ag_artigos($j0), naval_ag_artigos($j1));
    $arts = array_values($tot['artigos']);
    usort($arts, function ($a, $b) { return abs($b['efeito_preco']) <=> abs($a['efeito_preco']); });
    unset($tot['artigos']);
    $rotas = [];
    foreach (naval_analise_rotas($j0 + $j1) as $rt) {
        $x = naval_ag_pvm_calc(naval_ag_artigos($j0, $rt), naval_ag_artigos($j1, $rt));
        unset($x['artigos']);
        $rotas[] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? ''] + $x + ['variacao' => round($x['actual'] - $x['anterior'], 2)];
    }
    usort($rotas, function ($a, $b) { return $b['actual'] <=> $a['actual']; });
    return ['de' => $iniMes, 'ate' => $fim, 'anterior_de' => $iniAnt, 'anterior_ate' => $fimAnt, 'total' => $tot, 'rotas' => $rotas, 'artigos' => array_slice($arts, 0, 30)];
}

/** 5. Matriz ABC-XYZ: valor de 13 semanas (ABC: 80 % / 95 %) × coeficiente de variação da quantidade semanal (XYZ). */
function naval_ag_abc_xyz(array $ag, string $fim): array
{
    $sem = 13;
    $ini = naval_ag_menos($fim, $sem * 7 - 1);
    $q = $v = $nomes = [];
    foreach (naval_ag_janela($ag, $ini, $fim) as $d => $x) {
        $w = (int) floor((strtotime($d . ' 12:00') - strtotime($ini . ' 12:00')) / (7 * 86400));
        foreach (($x['artigos'] ?? []) as $l) foreach ($l as $cod => $a) {
            $cod = (string) $cod;
            $q[$cod][$w] = ($q[$cod][$w] ?? 0) + (float) $a['q'];
            $v[$cod] = ($v[$cod] ?? 0) + (float) $a['t'];
            if ($a['n'] !== '') $nomes[$cod] = (string) $a['n'];
        }
    }
    $v = array_filter($v, function ($x) { return $x > 0; });
    arsort($v);
    $total = array_sum($v);
    $acum = 0.0;
    $matriz = [];
    foreach (['A', 'B', 'C'] as $a) foreach (['X', 'Y', 'Z'] as $b) $matriz[$a . $b] = ['celula' => $a . $b, 'artigos' => 0, 'valor' => 0.0, 'politica' => NAVAL_AG_XYZ[$a . $b]];
    $lst = [];
    foreach ($v as $cod => $val) {
        $antes = $total > 0 ? $acum / $total : 0;
        $acum += $val;
        $abc = $antes < 0.8 ? 'A' : ($antes < 0.95 ? 'B' : 'C');
        $serie = [];
        for ($w = 0; $w < $sem; $w++) $serie[] = max(0.0, (float) ($q[$cod][$w] ?? 0));
        $media = array_sum($serie) / $sem;
        $dp = 0.0;
        foreach ($serie as $s) $dp += ($s - $media) ** 2;
        $dp = sqrt($dp / $sem);
        $cv = $media > 0 ? $dp / $media : null;
        $xyz = $cv === null ? 'Z' : ($cv <= 0.5 ? 'X' : ($cv <= 1.0 ? 'Y' : 'Z'));
        $matriz[$abc . $xyz]['artigos']++;
        $matriz[$abc . $xyz]['valor'] += $val;
        $lst[] = ['codigo' => $cod, 'artigo' => $nomes[$cod] ?? $cod, 'abc' => $abc, 'xyz' => $xyz, 'celula' => $abc . $xyz, 'valor' => round($val, 2),
            'pct_valor' => $total > 0 ? round($val / $total, 4) : null, 'media_semanal' => round($media, 2), 'cv' => $cv !== null ? round($cv, 3) : null,
            'semanas_com_venda' => count(array_filter($serie, function ($s) { return $s > 0; }))];
    }
    foreach ($matriz as $k => $m) {
        $matriz[$k]['valor'] = round($m['valor'], 2);
        $matriz[$k]['pct_valor'] = $total > 0 ? round($m['valor'] / $total, 4) : null;
    }
    return ['semanas' => $sem, 'de' => $ini, 'ate' => $fim, 'matriz' => array_values($matriz), 'artigos' => $lst];
}

/**
 * 6. Regras de associação (cesto = compras de um cliente num dia, últimos 90 dias): pares com suporte mínimo e lift
 * acima de 1,2, e os clientes que compram A (2 vezes ou mais) e nunca compraram B — a oportunidade de venda cruzada.
 */
function naval_ag_cesto(array $ag, string $fim, array $nomes = [], int $max = 25): array
{
    $cestos = [];
    $cliArt = [];
    foreach (naval_ag_janela($ag, naval_ag_menos($fim, 89), $fim) as $x) {
        foreach (($x['ca'] ?? []) as $c => $arts) {
            $s = [];
            foreach ($arts as $cod => $qq) if ($qq > 0) {
                $s[] = (string) $cod;
                $cliArt[(string) $c][(string) $cod] = ($cliArt[(string) $c][(string) $cod] ?? 0) + 1;
            }
            if ($s) $cestos[] = $s;
        }
        foreach (($x['artigos'] ?? []) as $l) foreach ($l as $cod => $a) if (!isset($nomes[(string) $cod]) && $a['n'] !== '') $nomes[(string) $cod] = (string) $a['n'];
    }
    $n = count($cestos);
    if ($n < 20) return ['cestos' => $n, 'regras' => [], 'artigos_por_cesto' => null];
    $cnt = [];
    $linhas = 0;
    foreach ($cestos as $s) {
        $linhas += count($s);
        foreach ($s as $a) $cnt[$a] = ($cnt[$a] ?? 0) + 1;
    }
    $minItem = max(10, (int) ceil(0.01 * $n));
    $freq = array_filter($cnt, function ($c) use ($minItem) { return $c >= $minItem; });
    $par = [];
    foreach ($cestos as $s) {
        $s = array_values(array_filter($s, function ($a) use ($freq) { return isset($freq[$a]); }));
        sort($s, SORT_STRING);
        $k = count($s);
        for ($i = 0; $i < $k; $i++) for ($j = $i + 1; $j < $k; $j++) $par[$s[$i] . '|' . $s[$j]] = ($par[$s[$i] . '|' . $s[$j]] ?? 0) + 1;
    }
    $minPar = max(5, (int) ceil(0.005 * $n));
    $regras = [];
    foreach ($par as $k => $ab) {
        if ($ab < $minPar) continue;
        [$a, $b] = explode('|', $k);
        $lift = $ab * $n / ($cnt[$a] * $cnt[$b]);
        if ($lift < 1.2) continue;
        // direcção: do artigo mais comprado para o menos comprado (é o que se propõe a quem já compra o primeiro)
        if ($cnt[$b] > $cnt[$a]) [$a, $b] = [$b, $a];
        $oport = 0;
        foreach ($cliArt as $arts) if (($arts[$a] ?? 0) >= 2 && empty($arts[$b])) $oport++;
        $regras[] = ['se' => $a, 'se_nome' => $nomes[$a] ?? $a, 'entao' => $b, 'entao_nome' => $nomes[$b] ?? $b, 'cestos' => $ab, 'suporte' => round($ab / $n, 4),
            'confianca' => round($ab / $cnt[$a], 4), 'lift' => round($lift, 2), 'clientes_oportunidade' => $oport];
    }
    usort($regras, function ($x, $y) { return [$y['clientes_oportunidade'] * $y['confianca'], $y['lift']] <=> [$x['clientes_oportunidade'] * $x['confianca'], $x['lift']]; });
    return ['cestos' => $n, 'artigos_por_cesto' => round($linhas / $n, 2), 'regras' => array_slice($regras, 0, $max)];
}

/** Índice Herfindahl-Hirschman (0 a 10 000) e quotas dos maiores. */
function naval_ag_hhi(array $valores): array
{
    $v = array_values(array_filter($valores, function ($x) { return $x > 0; }));
    rsort($v);
    $t = array_sum($v);
    if ($t <= 0) return ['n' => 0, 'hhi' => null, 'nivel' => null, 'maior' => null, 'top10' => null, 'top20pct' => null];
    $h = 0.0;
    foreach ($v as $x) $h += ($x / $t * 100) ** 2;
    $n = count($v);
    return ['n' => $n, 'hhi' => (int) round($h), 'nivel' => $h < 1500 ? 'baixa' : ($h <= 2500 ? 'moderada' : 'alta'), 'maior' => round($v[0] / $t, 4),
        'top10' => round(array_sum(array_slice($v, 0, 10)) / $t, 4), 'top20pct' => round(array_sum(array_slice($v, 0, max(1, (int) ceil($n * 0.2)))) / $t, 4)];
}

/** 7. Concentração das vendas (90 dias): clientes, artigos e rotas; por rota, a quota dos 5 maiores clientes. */
function naval_ag_concentracao(array $ag, string $fim, array $promotores = []): array
{
    $cli = $art = $rot = $cliRota = [];
    foreach (naval_ag_janela($ag, naval_ag_menos($fim, 89), $fim) as $x) {
        foreach (($x['clientes'] ?? []) as $c => $v) {
            $cli[(string) $c] = ($cli[(string) $c] ?? 0) + (float) $v['t'];
            $cliRota[(string) $v['r']][(string) $c] = ($cliRota[(string) $v['r']][(string) $c] ?? 0) + (float) $v['t'];
        }
        foreach (($x['artigos'] ?? []) as $l) foreach ($l as $cod => $a) $art[(string) $cod] = ($art[(string) $cod] ?? 0) + (float) $a['t'];
        foreach (($x['rotas'] ?? []) as $rt => $r) $rot[(string) $rt] = ($rot[(string) $rt] ?? 0) + (float) $r['t'];
    }
    $porRota = [];
    foreach ($cliRota as $rt => $l) {
        $l = array_filter($l, function ($x) { return $x > 0; });
        arsort($l);
        $t = array_sum($l);
        if ($t <= 0) continue;
        $porRota[] = ['rota' => (string) $rt, 'promotor' => $promotores[$rt] ?? '', 'clientes' => count($l), 'top5' => round(array_sum(array_slice($l, 0, 5)) / $t, 4),
            'maior' => round(reset($l) / $t, 4), 'hhi' => naval_ag_hhi($l)['hhi']];
    }
    usort($porRota, function ($a, $b) { return $b['top5'] <=> $a['top5']; });
    return ['clientes' => naval_ag_hhi($cli), 'artigos' => naval_ag_hhi($art), 'rotas' => naval_ag_hhi($rot), 'por_rota' => $porRota];
}

/**
 * 8. Produtividade das rotas (últimas 4 semanas): compras por dia de venda e valor por compra face ao quartil superior
 * das rotas (melhor prática interna); potencial = o que a rota venderia ao chegar ao quartil superior em cada um.
 */
function naval_ag_produtividade(array $ag, string $fim, array $promotores = []): array
{
    $r = [];
    foreach (naval_ag_janela($ag, naval_ag_menos($fim, 27), $fim) as $d => $x) {
        foreach (($x['rotas'] ?? []) as $rt => $v) {
            $rt = (string) $rt;
            if (!isset($r[$rt])) $r[$rt] = ['dias' => 0, 'vendas' => 0.0, 'compras' => 0, 'clientes' => [], 'linhas' => 0, 'com_linhas' => 0];
            if ((float) $v['t'] > 0) $r[$rt]['dias']++;
            $r[$rt]['vendas'] += (float) $v['t'];
        }
        foreach (($x['clientes'] ?? []) as $c => $v) {
            $rt = (string) $v['r'];
            if ((float) $v['t'] <= 0 || !isset($r[$rt])) continue;
            $r[$rt]['compras']++;
            $r[$rt]['clientes'][(string) $c] = true;
            $n = count(array_filter((array) ($x['ca'][$c] ?? []), function ($q) { return $q > 0; }));
            if ($n) {
                $r[$rt]['linhas'] += $n;
                $r[$rt]['com_linhas']++;
            }
        }
    }
    $lst = [];
    foreach ($r as $rt => $x) {
        if (!$x['dias'] || !$x['compras']) continue;
        $lst[$rt] = ['rota' => $rt, 'promotor' => $promotores[$rt] ?? '', 'dias' => $x['dias'], 'vendas' => round($x['vendas'], 2), 'compras' => $x['compras'],
            'clientes' => count($x['clientes']), 'compras_dia' => round($x['compras'] / $x['dias'], 2), 'valor_compra' => round($x['vendas'] / $x['compras'], 2),
            'linhas_compra' => $x['com_linhas'] ? round($x['linhas'] / $x['com_linhas'], 2) : null, 'vendas_dia' => round($x['vendas'] / $x['dias'], 2),
            'frequencia' => round($x['compras'] / max(1, count($x['clientes'])), 2)];
    }
    $p75cd = naval_ag_percentil(array_column($lst, 'compras_dia'), 0.75);
    $p75vc = naval_ag_percentil(array_column($lst, 'valor_compra'), 0.75);
    $med = naval_ag_percentil(array_column($lst, 'vendas_dia'), 0.5);
    $pot = 0.0;
    foreach ($lst as $rt => $x) {
        $gVc = max(0, $p75vc - $x['valor_compra']) * $x['compras'];
        $gCd = max(0, $p75cd - $x['compras_dia']) * $x['dias'] * $x['valor_compra'];
        $lst[$rt] += ['potencial_valor_compra' => round($gVc, 2), 'potencial_compras' => round($gCd, 2), 'potencial' => round($gVc + $gCd, 2),
            'indice' => $med ? round($x['vendas_dia'] / $med, 3) : null,
            'alavanca' => $gVc + $gCd <= 0 ? 'referencia' : ($gVc >= $gCd ? 'valor' : 'cobertura')];
        $pot += $gVc + $gCd;
    }
    $lst = array_values($lst);
    usort($lst, function ($a, $b) { return $b['potencial'] <=> $a['potencial']; });
    return ['dias' => 28, 'referencia' => ['compras_dia' => $p75cd !== null ? round($p75cd, 2) : null, 'valor_compra' => $p75vc !== null ? round($p75vc, 2) : null,
        'vendas_dia_mediana' => $med !== null ? round($med, 2) : null], 'potencial_total' => round($pot, 2), 'rotas' => $lst];
}

/**
 * 9. Cobertura de stock: $stock = [{codigo, artigo, quantidade, onde: 'armazem'|'carrinha'}] (dbo.GET_STOCK). Venda
 * média por dia de venda nas últimas 4 semanas; estados: rotura (sem stock e com venda), crítico (< 5 dias), normal,
 * excesso (> 45 dias) e parado (stock sem venda em 4 semanas). Capital ao preço médio sem IVA de 13 semanas.
 */
function naval_ag_cobertura(array $ag, string $fim, array $stock, array $abc = []): array
{
    $jan = naval_ag_janela($ag, naval_ag_menos($fim, 27), $fim);
    $diasVenda = count(array_filter($jan, function ($x) { return !empty($x['rotas']); }));
    $q28 = naval_ag_artigos($jan);
    $a91 = naval_ag_artigos(naval_ag_janela($ag, naval_ag_menos($fim, 90), $fim));
    $st = [];
    foreach ($stock as $l) {
        $cod = trim((string) ($l['codigo'] ?? ''));
        if ($cod === '') continue;
        if (!isset($st[$cod])) $st[$cod] = ['artigo' => (string) ($l['artigo'] ?? $l['produto'] ?? ''), 'armazem' => 0.0, 'carrinha' => 0.0];
        $st[$cod][($l['onde'] ?? '') === 'carrinha' ? 'carrinha' : 'armazem'] += (float) ($l['quantidade'] ?? 0);
    }
    foreach ($q28 as $cod => $x) if (!isset($st[$cod]) && $x['q'] > 0) $st[$cod] = ['artigo' => $x['n'], 'armazem' => 0.0, 'carrinha' => 0.0];
    $lst = [];
    $res = ['rotura' => 0, 'critico' => 0, 'normal' => 0, 'excesso' => 0, 'parado' => 0];
    $capital = ['total' => 0.0, 'excesso' => 0.0, 'parado' => 0.0];
    foreach ($st as $cod => $x) {
        $tot = $x['armazem'] + $x['carrinha'];
        $vd = $diasVenda ? max(0.0, ($q28[$cod]['q'] ?? 0)) / $diasVenda : 0.0;
        $preco = isset($a91[$cod]) && $a91[$cod]['q'] > 0 ? $a91[$cod]['t'] / $a91[$cod]['q'] : null;
        $cob = $vd > 0 ? $tot / $vd : null;
        if ($tot <= 0 && $vd <= 0) continue;
        $estado = $tot <= 0 ? 'rotura' : ($vd <= 0 ? 'parado' : ($cob < 5 ? 'critico' : ($cob > 45 ? 'excesso' : 'normal')));
        $res[$estado]++;
        $val = $preco !== null && $tot > 0 ? $tot * $preco : null;
        if ($val !== null) {
            $capital['total'] += $val;
            if ($estado === 'parado') $capital['parado'] += $val;
            if ($estado === 'excesso') $capital['excesso'] += ($tot - 45 * $vd) * $preco;
        }
        $lst[] = ['codigo' => (string) $cod, 'artigo' => $x['artigo'] ?: ($q28[$cod]['n'] ?? (string) $cod), 'abc' => $abc[$cod] ?? null, 'armazem' => round($x['armazem'], 2),
            'carrinhas' => round($x['carrinha'], 2), 'stock' => round($tot, 2), 'venda_dia' => round($vd, 2), 'cobertura_dias' => $cob !== null ? round($cob, 1) : null,
            'estado' => $estado, 'capital' => $val !== null ? round($val, 2) : null];
    }
    $ordem = ['rotura' => 0, 'critico' => 1, 'excesso' => 2, 'parado' => 3, 'normal' => 4];
    usort($lst, function ($a, $b) use ($ordem) { return [$ordem[$a['estado']], -($a['capital'] ?? 0)] <=> [$ordem[$b['estado']], -($b['capital'] ?? 0)]; });
    return ['dias_venda' => $diasVenda, 'resumo' => $res, 'capital' => array_map(function ($v) { return round($v, 2); }, $capital), 'artigos' => $lst];
}

/** Todas as análises (agregados já no âmbito de quem pede). $stock null: sem acesso ou sem ligação ao stock. */
function naval_ag_analises(array $ag, string $fim, array $promotores = [], ?array $stock = null): array
{
    $ag = naval_ag_janela($ag, '0000-00-00', $fim);
    $abc = naval_ag_abc_xyz($ag, $fim);
    $classe = [];
    foreach ($abc['artigos'] as $a) $classe[$a['codigo']] = $a['celula'];
    $nomes = [];
    foreach ($abc['artigos'] as $a) $nomes[$a['codigo']] = $a['artigo'];
    return [
        'ate' => $fim,
        'rfm' => naval_ag_rfm($ag, $fim),
        'coortes' => naval_ag_coortes($ag, $fim),
        'clv' => naval_ag_clv($ag, $fim, $promotores),
        'pvm' => naval_ag_pvm($ag, $fim, $promotores),
        'abc_xyz' => $abc,
        'cesto' => naval_ag_cesto($ag, $fim, $nomes),
        'concentracao' => naval_ag_concentracao($ag, $fim, $promotores),
        'produtividade' => naval_ag_produtividade($ag, $fim, $promotores),
        'cobertura' => $stock !== null ? naval_ag_cobertura($ag, $fim, $stock, $classe) : null,
    ];
}

/**
 * Destaques das análises para o relatório semanal e para a IA: valor em risco e os clientes «Não pode perder» de maior
 * valor, a ponte do mês, a rota com mais potencial e as roturas de stock.
 */
function naval_ag_destaques(array $a, int $n = 5): array
{
    $np = array_values(array_filter($a['rfm']['lista'] ?? [], function ($x) { return $x['segmento'] === 'nao_perder'; }));
    $prod = $a['produtividade']['rotas'] ?? [];
    $rot = array_values(array_filter($a['cobertura']['artigos'] ?? [], function ($x) { return $x['estado'] === 'rotura'; }));
    $t = $a['pvm']['total'] ?? [];
    return [
        'valor_anual_em_risco' => (float) ($a['rfm']['valor_anual_em_risco'] ?? 0),
        'nao_perder' => array_map(function ($x) { return ['cliente' => $x['cliente'], 'rota' => $x['rota'], 'valor' => $x['valor'], 'dias' => $x['recencia']]; }, array_slice($np, 0, $n)),
        'pvm' => ['preco' => (float) ($t['preco'] ?? 0), 'volume' => (float) ($t['volume'] ?? 0), 'mix' => (float) ($t['mix'] ?? 0)],
        'potencial_total' => (float) ($a['produtividade']['potencial_total'] ?? 0),
        'maior_potencial' => $prod && $prod[0]['potencial'] > 0 ? ['rota' => $prod[0]['rota'], 'promotor' => $prod[0]['promotor'], 'potencial' => $prod[0]['potencial'],
            'alavanca' => $prod[0]['alavanca']] : null,
        'roturas' => array_map(function ($x) { return $x['artigo']; }, array_slice($rot, 0, $n)),
    ];
}

/** Linhas de stock de naval_stock() no formato de naval_ag_cobertura. */
function naval_ag_linhas_stock(array $d): array
{
    $out = [];
    foreach ((array) ($d['linhas_armazens'] ?? []) as $l) $out[] = ['codigo' => $l['codigo'] ?? '', 'artigo' => $l['artigo'] ?? $l['produto'] ?? '', 'quantidade' => $l['quantidade'] ?? 0, 'onde' => 'armazem'];
    foreach ((array) ($d['linhas_carrinhas'] ?? []) as $l) $out[] = ['codigo' => $l['codigo'] ?? '', 'artigo' => $l['artigo'] ?? $l['produto'] ?? '', 'quantidade' => $l['quantidade'] ?? 0, 'onde' => 'carrinha'];
    return $out;
}
