<?php
/**
 * Perdas e tempo parado por rota (gestão das perdas, «shrinkage» e produtividade do terreno), tudo das bases de dados:
 *   - Pinguim BO (só SELECT): vendas, notas de crédito, descontos por linha e documentos anulados;
 *   - conciliação das carrinhas (registos do portal): faltas e sobras em Kz, com o motivo de cada diferença;
 *   - entrega do dinheiro (app Naval Operações): diferença entre o que o vendedor declarou e o que a tesouraria contou;
 *   - OLAP (localização das visitas): minutos parados sem vender (paragens sem venda) e quilómetros.
 * Custo do tempo parado: PERDAS_CUSTO_HORA (Kz por hora da rota: vendedor + motorista + viatura); sem ele, só os
 * minutos (o custo fica «por definir»).
 * O período vai até 31 dias (o tempo parado lê um dia de cada vez do OLAP, guardado em cache).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PERDAS_MAX_DIAS = 31;

/**
 * Junta as fontes por rota (função pura, testada). $f: vendas, artigos, anulados [{rota, documento, data, total}],
 * carrinhas [{rota, falta_kz, sobra_kz, motivos: {motivo: n}}], entregas [{rota, dif_kz}], parado {rota: {min, km}},
 * promotores {rota: nome}; $custoHora: Kz/hora (0 = por definir).
 */
function naval_perdas_juntar(array $f, array $promotores, float $custoHora): array
{
    $r = [];
    $linha = function (string $rota) use (&$r, $promotores) {
        if (!isset($r[$rota])) $r[$rota] = ['rota' => $rota, 'promotor' => (string) ($promotores[$rota] ?? $rota), 'vendas' => 0.0, 'nc_kz' => 0.0, 'nc_n' => 0,
            'desconto_kz' => 0.0, 'anulados_n' => 0, 'anulados_kz' => 0.0, 'falta_kz' => 0.0, 'sobra_kz' => 0.0, 'entrega_dif_kz' => 0.0,
            'parado_min' => 0, 'km' => 0.0, 'parado_kz' => null, 'perdas_kz' => 0.0, 'perdas_pct' => null, 'motivos' => []];
    };
    foreach ((array) ($f['vendas'] ?? []) as $v) {
        $rota = (string) $v['rota'];
        $linha($rota);
        if (!empty($v['nc'])) {
            $r[$rota]['nc_kz'] += abs((float) $v['total']);
            $r[$rota]['nc_n']++;
        } else $r[$rota]['vendas'] += (float) $v['total'];
    }
    foreach ((array) ($f['artigos'] ?? []) as $a) {
        if (!empty($a['nc'])) continue;
        $linha((string) $a['rota']);
        $r[(string) $a['rota']]['desconto_kz'] += abs((float) ($a['desconto'] ?? 0));
    }
    foreach ((array) ($f['anulados'] ?? []) as $x) {
        $rota = (string) $x['rota'];
        $linha($rota);
        $r[$rota]['anulados_n']++;
        $r[$rota]['anulados_kz'] += abs((float) $x['total']);
    }
    foreach ((array) ($f['carrinhas'] ?? []) as $c) {
        $rota = (string) $c['rota'];
        $linha($rota);
        $r[$rota]['falta_kz'] += (float) $c['falta_kz'];
        $r[$rota]['sobra_kz'] += (float) $c['sobra_kz'];
        foreach ((array) ($c['motivos'] ?? []) as $m => $n) $r[$rota]['motivos'][$m] = ($r[$rota]['motivos'][$m] ?? 0) + (int) $n;
    }
    foreach ((array) ($f['entregas'] ?? []) as $e) {
        $rota = (string) $e['rota'];
        $linha($rota);
        $r[$rota]['entrega_dif_kz'] += (float) $e['dif_kz'];
    }
    foreach ((array) ($f['parado'] ?? []) as $rota => $p) {
        $linha((string) $rota);
        $r[(string) $rota]['parado_min'] += (int) ($p['min'] ?? 0);
        $r[(string) $rota]['km'] += (float) ($p['km'] ?? 0);
    }
    $tot = ['vendas' => 0.0, 'nc_kz' => 0.0, 'desconto_kz' => 0.0, 'anulados_kz' => 0.0, 'anulados_n' => 0, 'falta_kz' => 0.0, 'sobra_kz' => 0.0,
        'entrega_dif_kz' => 0.0, 'parado_min' => 0, 'parado_kz' => $custoHora > 0 ? 0.0 : null, 'perdas_kz' => 0.0];
    foreach ($r as &$x) {
        foreach (['vendas', 'nc_kz', 'desconto_kz', 'anulados_kz', 'falta_kz', 'sobra_kz', 'entrega_dif_kz', 'km'] as $k) $x[$k] = round($x[$k], 2);
        $x['parado_kz'] = $custoHora > 0 ? round($x['parado_min'] / 60 * $custoHora, 2) : null;
        // perdas efectivas: faltas na carrinha, falta de dinheiro na entrega e o custo do tempo parado (notas de crédito,
        // descontos e anulações mostram-se à parte: são decisões comerciais, não perdas por si)
        $x['perdas_kz'] = round($x['falta_kz'] + max(0.0, -$x['entrega_dif_kz']) + (float) ($x['parado_kz'] ?? 0), 2);
        $x['perdas_pct'] = $x['vendas'] > 0 ? round($x['perdas_kz'] / $x['vendas'], 4) : null;
        foreach ($tot as $k => $v) {
            if ($k === 'parado_kz' && $v === null) continue;
            if ($k === 'entrega_dif_kz') $tot[$k] += $x[$k];
            elseif (isset($x[$k])) $tot[$k] += $x[$k];
        }
    }
    unset($x);
    foreach ($tot as $k => $v) if (is_float($v)) $tot[$k] = round($v, 2);
    $tot['perdas_pct'] = $tot['vendas'] > 0 ? round($tot['perdas_kz'] / $tot['vendas'], 4) : null;
    /** @var array<int, array<string, mixed>> $rotas */
    $rotas = array_values($r);
    usort($rotas, function ($a, $b) {
        $c = (float) $b['perdas_kz'] <=> (float) $a['perdas_kz'];
        return $c !== 0 ? $c : strcmp((string) $a['rota'], (string) $b['rota']);
    });
    return ['rotas' => $rotas, 'total' => $tot, 'custo_hora' => $custoHora > 0 ? $custoHora : null];
}

/** Faltas e sobras das carrinhas no período (registos da conciliação), com os motivos das diferenças. */
function naval_perdas_carrinhas(string $de, string $ate, ?array $rotas): array
{
    $out = [];
    foreach (glob(naval_pasta('carrinha') . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $dir) {
        $dia = basename($dir);
        if ($dia < $de || $dia > $ate) continue;
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (basename($f)[0] === '_') continue;
            $rota = basename($f, '.json');
            if ($rotas !== null && !in_array($rota, $rotas, true)) continue;
            $reg = naval_json_ler($f);
            if (!$reg || !is_array($reg['contagem'] ?? null)) continue;
            $falta = 0.0;
            $sobra = 0.0;
            foreach (array_reverse((array) ($reg['historico'] ?? [])) as $h) {
                if (isset($h['falta_kz'])) {
                    $falta = (float) $h['falta_kz'];
                    $sobra = (float) ($h['sobra_kz'] ?? 0);
                    break;
                }
            }
            $mot = [];
            foreach ((array) ($reg['motivos'] ?? []) as $m) {
                $c = is_array($m) ? (string) ($m['motivo'] ?? $m['codigo'] ?? '') : (string) $m;
                if ($c !== '') $mot[$c] = ($mot[$c] ?? 0) + 1;
            }
            $out[] = ['rota' => $rota, 'dia' => $dia, 'falta_kz' => $falta, 'sobra_kz' => $sobra, 'motivos' => $mot];
        }
    }
    return $out;
}

/** Diferenças da entrega de dinheiro (declarado na app × contado na folha de caixa), por rota e dia. */
function naval_perdas_entregas(string $de, string $ate, ?array $rotas): array
{
    naval_servico('caixa-entregas');
    $out = [];
    for ($t = strtotime($de . ' 12:00'); $t <= strtotime($ate . ' 12:00'); $t += 86400) {
        $dia = date('Y-m-d', $t);
        $dec = naval_cxe_ler($dia, $rotas);
        if (!$dec) continue;
        foreach (naval_cxe_conferencia($dec, naval_caixa_ler($dia), null) as $rota => $c) {
            if ($c['dif_declarado'] !== null && abs($c['dif_declarado']) >= 0.01) $out[] = ['rota' => (string) $rota, 'dia' => $dia, 'dif_kz' => $c['dif_declarado']];
        }
    }
    return $out;
}

/** Documentos anulados no Pinguim BO no período, por rota (loja da rota). null sem ligação. */
function naval_perdas_anulados(string $de, string $ate): ?array
{
    naval_servico('bd');
    naval_servico('vendas');
    $cond = naval_vendas_condicao_anulados();
    if (!naval_bd_configurada() || $cond === '') return null;
    try {
        $l = naval_bd_consultar("
            SELECT RTRIM(LTRIM(COALESCE(DH.DOC_PREFIX, '') + ' ' + CAST(DH.DOC_NUMBER AS VARCHAR(20)))) AS DOCUMENTO,
                   COALESCE(STORE.CODE, SUBSTRING(STORE.DESCRIPTION, 1, 4)) AS ROTA, CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DIA,
                   CAST(DH.CALC_TOTAL_W_TAX AS FLOAT) AS TOTAL
            FROM DOCHEADER DH
            LEFT JOIN STORE ON STORE.ID = DH.STORE_ID
            WHERE DH.DELETED = 0 AND $cond AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, :ate)",
            [':de' => naval_bd_data($de), ':ate' => naval_bd_data($ate)]);
    } catch (Throwable $e) {
        error_log('[perdas anulados] ' . $e->getMessage());
        return null;
    }
    return array_map(function ($x) { return ['documento' => (string) $x['DOCUMENTO'], 'rota' => trim((string) $x['ROTA']), 'data' => (string) $x['DIA'], 'total' => round((float) $x['TOTAL'], 2)]; }, $l);
}

/** Minutos parados sem vender e quilómetros por rota no período (OLAP, um dia de cada vez, em cache). */
function naval_perdas_parado(string $de, string $ate, ?array $rotas): ?array
{
    naval_servico('olap');
    naval_servico('prospeccao');
    naval_servico('calendario');
    if (!naval_olap_configurado()) return null;
    $g = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $clientes = (array) ($g['clientes'] ?? []);
    $terr = [];
    foreach ((array) ($g['rotas'] ?? []) as $r) $terr[(string) $r['rota']] = (array) ($r['territorio'] ?? []);
    $out = [];
    for ($t = strtotime($de . ' 12:00'); $t <= strtotime($ate . ' 12:00'); $t += 86400) {
        $dia = date('Y-m-d', $t);
        if (naval_cal_folga($dia)) continue;
        $d = naval_olap_dia($dia, $rotas);
        if (!$d['pontos']) continue;
        $x = naval_olap_terreno($d['pontos'], $clientes, $terr);
        foreach ((array) ($x['rotas'] ?? []) as $r) {
            $rota = (string) ($r['rota'] ?? '');
            if ($rota === '') continue;
            $out[$rota]['min'] = ($out[$rota]['min'] ?? 0) + (int) ($r['min_parado_sem_venda'] ?? 0);
            $out[$rota]['km'] = ($out[$rota]['km'] ?? 0) + (float) ($r['km'] ?? 0);
        }
    }
    return $out;
}

/** Vista das perdas no período (de, ate em AAAA-MM-DD), no âmbito $rotas. Cada fonte sem ligação fica em «fontes». */
function naval_perdas_vista(string $de, string $ate, ?array $rotas): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate) - strtotime($de)) / 86400 + 1 > NAVAL_PERDAS_MAX_DIAS) throw new NavalErro('Escolha um período até ' . NAVAL_PERDAS_MAX_DIAS . ' dias.', 400);
    naval_servico('vendas');
    naval_servico('rotas');
    $promotores = naval_promotores();
    $fontes = [];
    $v = naval_vendas_sql(str_replace('-', '', $de), str_replace('-', '', $ate), $promotores, true);
    $fontes['pinguim'] = $v !== null;
    $no = function (array $l) use ($rotas) { return $rotas === null ? $l : array_values(array_filter($l, function ($x) use ($rotas) { return in_array((string) $x['rota'], $rotas, true); })); };
    $vendas = $v !== null ? $no(naval_vendas_efectivas((array) $v['vendas'])) : [];
    $artigos = $v !== null ? $no(naval_vendas_efectivas_artigos((array) ($v['artigos'] ?? []), (array) $v['vendas'])) : [];
    $anul = naval_perdas_anulados($de, $ate);
    $anul = $anul === null ? [] : $no($anul);
    $parado = null;
    try {
        $parado = naval_perdas_parado($de, $ate, $rotas);
    } catch (Throwable $e) {
        error_log('[perdas parado] ' . $e->getMessage());
    }
    $fontes['olap'] = $parado !== null;
    $carr = naval_perdas_carrinhas($de, $ate, $rotas);
    $ent = naval_perdas_entregas($de, $ate, $rotas);
    $j = naval_perdas_juntar(['vendas' => $vendas, 'artigos' => $artigos, 'anulados' => $anul, 'carrinhas' => $carr, 'entregas' => $ent, 'parado' => $parado ?? []],
        $promotores, (float) naval_config('PERDAS_CUSTO_HORA', '0'));
    usort($anul, function ($a, $b) { return strcmp($b['data'], $a['data']); });
    $carrDif = array_values(array_filter($carr, function ($c) { return $c['falta_kz'] > 0 || $c['sobra_kz'] > 0; }));
    usort($carrDif, function ($a, $b) { return $b['falta_kz'] <=> $a['falta_kz']; });
    naval_servico('agentes');
    $j['risco'] = (object) naval_ag_risco($j['rotas']);
    return ['de' => $de, 'ate' => $ate, 'fontes' => $fontes] + $j + ['anulados' => array_slice($anul, 0, 200), 'faltas' => array_slice($carrDif, 0, 200), 'entregas' => $ent];
}
