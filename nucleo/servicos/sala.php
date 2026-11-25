<?php
/**
 * Dados do Painel da sala comercial (ecrã de TV), por local do ecrã (ver nucleo/api/v1/sala.php):
 *   - «interna»: valores em Kz, nomes dos promotores (só no reconhecimento positivo) e gráficos em Kz;
 *   - «aberta» (open space): só percentagens, tendências, índices e contagens — sem Kz, sem nomes, sem classificações.
 * Ecrãs: hoje, comparacoes (todos os comparativos de gestão, cada um com as datas dos dois períodos e «sem histórico»
 * quando não há dados para comparar), ritmo (vendas por hora), tendencia (últimos 14 dias), visitas (plano do dia), rotas (rotas no terreno),
 * clientes (compras hoje e no mês), rank-hoje e rank-mes (só sala interna) e destaques.
 * Nunca: clientes, NIF, documentos, caixa, faltas, perdas, anulados ou risco por pessoa; nunca o último lugar.
 * As funções naval_sala_* que recebem os dados são puras (testadas); a leitura das fontes fica em naval_sala_dados().
 */
defined('NAVAL_NUCLEO') || exit;

/** Ecrãs do painel, pela ordem de origem. */
const NAVAL_SALA_ECRAS = ['hoje', 'acumulado', 'previsao', 'comparacoes', 'ritmo', 'calor', 'tendencia', 'visitas', 'funil', 'rotas', 'mapa', 'clientes',
    'pareto', 'artigos', 'quadrante', 'stock', 'operacao', 'recordes', 'contagem', 'rank-hoje', 'rank-mes', 'destaques', 'momentos'];
/** Ecrãs que podem estar num espaço aberto (na versão sem valores em Kz nem nomes). */
const NAVAL_SALA_ECRAS_ABERTA = ['hoje', 'acumulado', 'previsao', 'comparacoes', 'ritmo', 'calor', 'tendencia', 'visitas', 'funil', 'rotas', 'clientes',
    'recordes', 'contagem', 'destaques', 'momentos'];
/** Configurações por omissão anteriores (antes dos ecrãs novos): ligações nunca personalizadas passam a ver todos. */
const NAVAL_SALA_ANTIGAS = [['hoje', 'rank-hoje', 'rank-mes', 'destaques'], ['hoje', 'destaques']];
/** «Todos os ecrãs» de versões anteriores: quem os escolheu todos passa a ver também os novos. */
const NAVAL_SALA_TODOS_ANTES = [['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'rank-hoje', 'rank-mes', 'destaques'],
    ['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'destaques']];
const NAVAL_SALA_LOCAIS = ['interna', 'aberta'];

/**
 * Versão que um ecrã deve ter: a dos ficheiros do painel (muda a cada actualização do portal) mais o último pedido de
 * «Recarregar ecrã» feito no portal. O ecrã que a vê mudar recarrega-se sozinho (em até 2 minutos).
 */
function naval_sala_versao(array $ligacao = []): string
{
    static $ficheiros = null;
    if ($ficheiros === null) {
        $raiz = dirname(__DIR__, 2) . '/sala/';
        $ficheiros = substr(md5(implode('|', array_map(function ($f) use ($raiz) { return (string) @filemtime($raiz . $f) . '|' . (string) @filesize($raiz . $f); },
            ['sala.js', 'sala.css', 'index.html']))), 0, 10);
    }
    $r = (string) ($ligacao['recarregar_em'] ?? '');
    return $r === '' ? $ficheiros : $ficheiros . '-' . substr(md5($r), 0, 6);
}

/** Ecrãs de uma ligação, limitados ao que o local permite. */
function naval_sala_ecras_local(array $ecras, string $local): array
{
    return array_values(array_filter($ecras, function ($e) use ($local) {
        return in_array($e, $local === 'aberta' ? NAVAL_SALA_ECRAS_ABERTA : NAVAL_SALA_ECRAS, true);
    }));
}

/** Configuração efectiva: a da ligação (se foi escolhida no portal) ou todos os ecrãs do local, 20 s cada. */
function naval_sala_config(?array $config, string $local): array
{
    $todos = $local === 'aberta' ? NAVAL_SALA_ECRAS_ABERTA : NAVAL_SALA_ECRAS;
    $esc = array_values((array) ($config['ecras'] ?? []));
    $ord = $esc;
    sort($ord);
    $todosAntes = false;
    foreach (NAVAL_SALA_TODOS_ANTES as $t) {
        sort($t);
        if ($t === $ord) $todosAntes = true;
    }
    if (!$config || $todosAntes || (empty($config['personalizado']) && in_array($esc, NAVAL_SALA_ANTIGAS, true))) {
        return ['ecras' => $todos, 'segundos' => (int) ($config['segundos'] ?? 20) ?: 20];
    }
    $e = naval_sala_ecras_local((array) ($config['ecras'] ?? []), $local);
    $seg = (int) ($config['segundos'] ?? 20);
    return ['ecras' => $e ?: $todos, 'segundos' => in_array($seg, [10, 20, 30, 60], true) ? $seg : 20];
}

/**
 * Dados novos dos ecrãs (função pura). $ag: agregados diários {dia: {rotas, clientes}}; $horas: {HH: vendas} de hoje;
 * $plano: plano de visitas de hoje {rota: [{c}]}|null; $rotas: linhas de naval_indicadores (rota, promotor, hoje,
 * previsto_hoje); $compras: compras de naval_indicadores (hoje, mes, mes_anterior).
 */
function naval_sala_extra(array $ag, string $hoje, array $horas, ?array $plano, array $rotas, array $compras): array
{
    // 14 dias até hoje (inclusive), total líquido sem IVA
    $serie = [];
    foreach (naval_analise_dias(date('Y-m-d', strtotime($hoje . ' 12:00') - 13 * 86400), $hoje) as $d) {
        $s = 0.0;
        foreach ((array) ($ag[$d]['rotas'] ?? []) as $r) $s += (float) $r['t'];
        $serie[] = ['d' => $d, 't' => round($s, 2)];
    }
    // vendas por hora de hoje (das 06:00 às 20:00)
    $h = [];
    for ($k = 6; $k <= 20; $k++) $h[] = ['h' => sprintf('%02d', $k), 'n' => (int) ($horas[sprintf('%02d', $k)] ?? 0)];
    // visitas do plano de hoje: planeados, quantos já compraram, compras fora do plano
    $compraram = [];
    foreach ((array) ($ag[$hoje]['clientes'] ?? []) as $c => $x) if ((float) ($x['t'] ?? 0) > 0) $compraram[(string) $x['r']][(string) $c] = true;
    $vis = null;
    if ($plano !== null) {
        $tot = ['planeados' => 0, 'compraram' => 0, 'fora_plano' => 0];
        $porRota = [];
        foreach (array_unique(array_merge(array_keys($plano), array_keys($compraram))) as $r) {
            $r = (string) $r;
            $p = [];
            foreach ((array) ($plano[$r] ?? []) as $c) $p[(string) $c['c']] = true;
            $comp = $compraram[$r] ?? [];
            $x = ['rota' => $r, 'planeados' => count($p), 'compraram' => count(array_intersect_key($p, $comp)), 'fora_plano' => count(array_diff_key($comp, $p))];
            foreach (['planeados', 'compraram', 'fora_plano'] as $k) $tot[$k] += $x[$k];
            if ($x['planeados'] > 0) $porRota[] = $x + ['conversao' => round($x['compraram'] / $x['planeados'], 3)];
        }
        $tot['conversao'] = $tot['planeados'] ? round($tot['compraram'] / $tot['planeados'], 3) : null;
        // reconhecimento positivo: as 5 rotas de melhor conversão (com pelo menos uma compra), nunca as últimas
        $melhores = array_values(array_filter($porRota, function ($x) { return $x['compraram'] > 0; }));
        usort($melhores, function ($a, $b) { return [$b['conversao'], $b['compraram']] <=> [$a['conversao'], $a['compraram']]; });
        $vis = $tot + ['melhores' => array_slice($melhores, 0, 5)];
    }
    // rotas no terreno: a vender hoje e com o objectivo atingido
    $aVender = 0;
    $objectivo = [];
    foreach ($rotas as $r) {
        if ((float) ($r['hoje'] ?? 0) > 0) $aVender++;
        if (!empty($r['previsto_hoje']) && (float) $r['hoje'] >= (float) $r['previsto_hoje']) {
            $objectivo[] = ['rota' => (string) $r['rota'], 'promotor' => (string) ($r['promotor'] ?? ''), 'progresso' => round((float) $r['hoje'] / (float) $r['previsto_hoje'], 3)];
        }
    }
    usort($objectivo, function ($a, $b) { return $b['progresso'] <=> $a['progresso']; });
    return [
        'serie' => $serie,
        'horas' => $h,
        'visitas' => $vis,
        'terreno' => ['rotas' => count($rotas), 'a_vender' => $aVender, 'objectivo' => $objectivo],
        'clientes' => ['hoje' => (int) ($compras['hoje']['compras'] ?? 0), 'mes' => (int) ($compras['mes']['compras'] ?? 0),
            'mes_anterior' => (int) ($compras['mes_anterior']['compras'] ?? 0), 'valor_medio_mes' => $compras['mes']['valor_medio'] ?? null],
    ];
}

/**
 * Comparativos de gestão (função pura), sempre com dias fechados (até ontem: o dia de hoje ainda está a decorrer e
 * compara-se com o objectivo), cada um com as datas dos dois períodos. Uma comparação só se faz quando há dados de
 * todo o período de referência (desde o primeiro dia com vendas); sem isso fica «sem histórico», com a data de início.
 * Devolve {inicio, linhas: [{id, nome, actual: {de, ate, t}, ref: {de, ate, t}|null, variacao|null, motivo|null}]}.
 */
function naval_sala_comparacoes(array $ag, string $hoje): array
{
    $tot = function (string $d) use ($ag) {
        $s = 0.0;
        foreach ((array) ($ag[$d]['rotas'] ?? []) as $r) $s += (float) $r['t'];
        return $s;
    };
    $dias = array_keys($ag);
    sort($dias);
    $inicio = null;
    foreach ($dias as $d) {
        if ($d < $hoje && $tot($d) > 0) {
            $inicio = $d;
            break;
        }
    }
    $mais = function (string $d, int $n) { return date('Y-m-d', strtotime($d . ' 12:00') + $n * 86400); };
    $soma = function (string $de, string $ate) use ($tot) {
        $s = 0.0;
        foreach (naval_analise_dias($de, $ate) as $d) $s += $tot($d);
        return round($s, 2);
    };
    $ontem = $mais($hoje, -1);
    $l = [];
    $linha = function (string $id, string $nome, string $de, string $ate, ?string $rde, ?string $rate, ?float $refValor = null, string $nota = '') use (&$l, $soma, $inicio) {
        $a = ['de' => $de, 'ate' => $ate, 't' => $soma($de, $ate)];
        $ref = null;
        $motivo = null;
        if ($rde !== null && $inicio !== null && $rde >= $inicio) {
            $ref = ['de' => $rde, 'ate' => $rate, 't' => $refValor ?? $soma($rde, $rate)];
        } else {
            $motivo = $inicio === null ? 'sem histórico' : 'sem histórico · dados desde ' . date('d-m-Y', strtotime($inicio . ' 12:00'));
        }
        $var = $ref && $ref['t'] > 0 ? round($a['t'] / $ref['t'] - 1, 3) : null;
        if ($ref && $ref['t'] <= 0) $motivo = 'sem vendas no período de comparação';
        $l[] = ['id' => $id, 'nome' => $nome, 'actual' => $a, 'ref' => $ref, 'variacao' => $var, 'motivo' => $motivo, 'nota' => $nota];
    };
    // 1. ontem
    $linha('ontem_anterior', 'Ontem × dia anterior', $ontem, $ontem, $mais($ontem, -1), $mais($ontem, -1));
    $linha('ontem_semana', 'Ontem × mesmo dia, semana passada', $ontem, $ontem, $mais($ontem, -7), $mais($ontem, -7));
    // habitual: mediana dos mesmos dias da semana nas 6 semanas anteriores (só as que têm dados)
    $iguais = [];
    for ($k = 1; $k <= 6; $k++) {
        $d = $mais($ontem, -7 * $k);
        if ($inicio !== null && $d >= $inicio) $iguais[$d] = $tot($d);
    }
    if (count($iguais) >= 3) {
        ksort($iguais);
        $linha('ontem_habitual', 'Ontem × habitual (' . count($iguais) . ' semanas)', $ontem, $ontem, (string) array_keys($iguais)[0], (string) array_keys($iguais)[count($iguais) - 1],
            round(naval_mediana(array_values($iguais)), 2), 'mesmo dia da semana');
    } else {
        $linha('ontem_habitual', 'Ontem × habitual', $ontem, $ontem, null, null);
        $l[count($l) - 1]['motivo'] = 'sem histórico · precisa de 3 semanas (há ' . count($iguais) . ')';
    }
    // 2. semana e mês até ontem, face aos mesmos dias do período anterior
    $iniSem = $mais($hoje, -((int) date('N', strtotime($hoje . ' 12:00')) - 1));
    if ($iniSem <= $ontem) $linha('semana', 'Semana até ontem × semana passada', $iniSem, $ontem, $mais($iniSem, -7), $mais($ontem, -7));
    $iniMes = substr($hoje, 0, 8) . '01';
    if ($iniMes <= $ontem) {
        $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
        $fimAnt = min(date('Y-m-d', strtotime($iniAnt . ' 12:00') + ((int) substr($ontem, 8, 2) - 1) * 86400), $mais($iniMes, -1));
        $linha('mes', 'Mês até ontem × mês anterior', $iniMes, $ontem, $iniAnt, $fimAnt);
    }
    // 3. janelas móveis
    $linha('7dias', 'Últimos 7 dias × 7 anteriores', $mais($ontem, -6), $ontem, $mais($ontem, -13), $mais($ontem, -7));
    $linha('30dias', 'Últimos 30 dias × 30 anteriores', $mais($ontem, -29), $ontem, $mais($ontem, -59), $mais($ontem, -30));
    // 4. homólogos: os mesmos dias do ano anterior
    $ano = function (string $d) { return date('Y-m-d', strtotime($d . ' 12:00 -1 year')); };
    if ($iniMes <= $ontem) $linha('mes_homologo', 'Mês até ontem × ano anterior', $iniMes, $ontem, $ano($iniMes), $ano($ontem));
    $iniAno = substr($hoje, 0, 4) . '-01-01';
    if ($iniAno <= $ontem) $linha('ano_homologo', 'Ano até ontem × ano anterior', $iniAno, $ontem, $ano($iniAno), $ano($ontem));
    return ['inicio' => $inicio, 'linhas' => $l];
}

// ── Ecrãs de gestão avançados (funções puras, testadas) ─────────────────────────────────────────────────────
// Ritmo acumulado × objectivo, previsão de fecho, mapa de calor, funil, recordes, contagem do mês, artigos, Pareto
// dos clientes, quadrante das rotas, cobertura de stock, saúde da operação, mapa das rotas e momentos do dia.

/** Total de um dia nos agregados (líquido, sem IVA). */
function naval_sala_total_dia(array $ag, string $d): float
{
    $s = 0.0;
    foreach ((array) ($ag[$d]['rotas'] ?? []) as $r) $s += (float) $r['t'];
    return $s;
}

/** Acumulado de um dia por hora (06..20; o que se vende antes das 06h entra nas 06h e depois das 20h nas 20h). */
function naval_sala_cumul(array $porHora): array
{
    $out = [];
    for ($k = 6; $k <= 20; $k++) {
        $s = 0.0;
        foreach ($porHora as $h => $v) if ($k === 20 || (int) $h <= $k) $s += (float) $v;
        $out[sprintf('%02d', $k)] = round($s, 2);
    }
    return $out;
}

/**
 * Ritmo acumulado do dia contra o objectivo e contra um dia normal. $hist: {dia: {HH: valor}} dos dias anteriores;
 * $horasHoje: {HH: valor} de hoje; $previsto: objectivo do dia (previsão); $horaAgora: hora actual (0–23).
 * Dia normal: média dos últimos 4 dias iguais da semana (ou, com menos de 2, dos últimos 20 dias com vendas).
 * Objectivo ao longo do dia: o objectivo × a parte do dia normal já vendida a cada hora.
 */
function naval_sala_acumulado(array $hist, string $hoje, array $horasHoje, ?float $previsto, int $horaAgora): array
{
    $dias = array_keys($hist);
    rsort($dias);
    $dow = date('N', strtotime($hoje . ' 12:00'));
    $tot = function ($d) use ($hist) { return array_sum(array_map('floatval', (array) $hist[$d])); };
    $ref = [];
    foreach ($dias as $d) if ($d < $hoje && date('N', strtotime($d . ' 12:00')) === $dow && $tot($d) > 0 && count($ref) < 4) $ref[] = $d;
    $tipo = 'mesmo_dia';
    if (count($ref) < 2) {
        $ref = [];
        foreach ($dias as $d) if ($d < $hoje && $tot($d) > 0 && count($ref) < 20) $ref[] = $d;
        $tipo = 'dias_com_vendas';
    }
    $hab = array_fill_keys(array_keys(naval_sala_cumul([])), 0.0);
    foreach ($ref as $d) foreach (naval_sala_cumul((array) $hist[$d]) as $h => $v) $hab[$h] += $v / count($ref);
    $fim = $hab['20'] ?? 0.0;
    $hc = naval_sala_cumul($horasHoje);
    $agora = sprintf('%02d', max(6, min(20, $horaAgora)));
    $linhas = [];
    foreach ($hab as $h => $v) {
        $parte = $fim > 0 ? $v / $fim : null;
        $linhas[] = ['h' => $h, 'hoje' => $horaAgora >= 6 && $h <= $agora ? $hc[$h] : null, 'habitual' => $ref ? round($v, 2) : null,
            'objectivo' => $previsto && $parte !== null ? round($previsto * $parte, 2) : null, 'parte' => $parte === null ? null : round($parte, 3)];
    }
    $total = $hc['20'];
    $parteAgora = $horaAgora < 6 || $fim <= 0 ? 0.0 : $hab[$agora] / $fim;
    $objAgora = $previsto ? $previsto * $parteAgora : 0.0;
    return ['horas' => $linhas, 'ref' => ['dias' => count($ref), 'tipo' => $tipo], 'total' => $total, 'previsto' => $previsto,
        'parte_agora' => round($parteAgora, 3),
        // «à frente / atrás do ritmo»: só a partir de 10 % do dia normal (antes, uma venda grande distorce tudo)
        'ritmo' => $objAgora > 0 && $parteAgora >= 0.1 ? round($total / $objAgora - 1, 3) : null,
        'estimado' => $parteAgora >= 0.15 ? round($total / $parteAgora, 2) : null];
}

/**
 * Previsão de fecho do dia e do mês. $acum: naval_sala_acumulado; $prevHoje: {previsto, minimo, maximo}|null;
 * $ag: agregados; $restantes: {dia: {p: previsto, folga: bool}} dos dias do mês depois de hoje.
 */
function naval_sala_previsao(array $acum, ?array $prevHoje, array $ag, string $hoje, array $restantes): array
{
    $prev = $prevHoje['previsto'] ?? null;
    $estDia = $acum['estimado'] ?? null;
    $usar = $estDia ?? max((float) $acum['total'], (float) $prev);
    $esc = $prev > 0 ? $usar / $prev : 1;
    $dia = ['actual' => $acum['total'], 'estimado' => round($usar, 2), 'objectivo' => $prev, 'pelo_ritmo' => $estDia !== null,
        'minimo' => isset($prevHoje['minimo']) ? round(max((float) $acum['total'], $prevHoje['minimo'] * $esc), 2) : null,
        'maximo' => isset($prevHoje['maximo']) ? round($prevHoje['maximo'] * $esc, 2) : null,
        'progresso' => $prev > 0 ? round($usar / $prev, 3) : null];
    $iniMes = substr($hoje, 0, 8) . '01';
    $ateOntem = 0.0;
    foreach (naval_analise_dias($iniMes, date('Y-m-d', strtotime($hoje . ' 12:00') - 86400)) as $d) if ($d < $hoje) $ateOntem += naval_sala_total_dia($ag, $d);
    $resto = 0.0;
    $diasResto = 0;
    foreach ($restantes as $x) {
        if (!empty($x['folga'])) continue;
        $resto += (float) $x['p'];
        $diasResto++;
    }
    $estMes = $ateOntem + $usar + $resto;
    $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $fimAnt = date('Y-m-t', strtotime($iniAnt . ' 12:00'));
    $completo = false;
    foreach (array_keys($ag) as $d) if ($d <= $iniAnt && naval_sala_total_dia($ag, $d) > 0) $completo = true;
    $mesAnt = 0.0;
    foreach (naval_analise_dias($iniAnt, $fimAnt) as $d) $mesAnt += naval_sala_total_dia($ag, $d);
    $mes = ['ate_ontem' => round($ateOntem, 2), 'hoje' => round($usar, 2), 'resto' => round($resto, 2), 'dias_resto' => $diasResto,
        'estimado' => round($estMes, 2), 'mes_anterior' => $completo ? round($mesAnt, 2) : null,
        'variacao' => $completo && $mesAnt > 0 ? round($estMes / $mesAnt - 1, 3) : null, 'ref' => ['de' => $iniAnt, 'ate' => $fimAnt]];
    return ['dia' => $dia, 'mes' => $mes];
}

/** Mapa de calor das últimas 4 semanas: dia da semana × hora, em índice (100 = hora mais forte). */
function naval_sala_calor(array $hist, string $hoje): array
{
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - 28 * 86400);
    $soma = $vezes = [];
    foreach ($hist as $d => $hs) {
        if ($d < $de || $d >= $hoje || array_sum(array_map('floatval', (array) $hs)) <= 0) continue;
        $w = (int) date('N', strtotime($d . ' 12:00'));
        $vezes[$w] = ($vezes[$w] ?? 0) + 1;
        foreach ((array) $hs as $h => $v) {
            $k = sprintf('%02d', max(6, min(20, (int) $h)));
            $soma[$w][$k] = ($soma[$w][$k] ?? 0) + (float) $v;
        }
    }
    $max = 0.0;
    $med = [];
    foreach ($soma as $w => $hs) foreach ($hs as $h => $v) $max = max($max, $med[$w][$h] = $v / $vezes[$w]);
    $nomes = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
    $linhas = [];
    $pico = null;
    foreach ($nomes as $w => $n) {
        if (empty($vezes[$w])) continue;
        $l = ['dia' => $n, 'v' => []];
        for ($k = 6; $k <= 20; $k++) {
            $v = $med[$w][sprintf('%02d', $k)] ?? 0.0;
            $i = $max > 0 ? (int) round($v / $max * 100) : 0;
            $l['v'][] = $i;
            if ($i === 100 && !$pico) $pico = ['dia' => $n, 'h' => sprintf('%02d', $k)];
        }
        $linhas[] = $l;
    }
    return ['de' => $de, 'ate' => date('Y-m-d', strtotime($hoje . ' 12:00') - 86400), 'linhas' => $linhas, 'pico' => $pico, 'semanas' => (int) round(array_sum($vezes) / max(1, count($vezes)))];
}

/**
 * Funil das visitas de hoje: planeados → compraram → com prova de visita (GPS da venda junto ao cliente) → compra
 * acima do valor médio. $gps: {cliente: 'ok'|'longe'|'sem_coordenadas'} das vendas de hoje (null sem OLAP).
 */
function naval_sala_funil(?array $plano, array $clientesHoje, ?array $gps, ?float $valorMedio): ?array
{
    if ($plano === null) return null;
    $p = [];
    foreach ($plano as $r => $l) foreach ((array) $l as $c) $p[(string) $c['c']] = true;
    $comp = [];
    foreach ($clientesHoje as $c => $x) if ((float) ($x['t'] ?? 0) > 0) $comp[(string) $c] = (float) $x['t'];
    $doPlano = array_intersect_key($comp, $p);
    $etapas = [['id' => 'planeados', 'nome' => 'Clientes no plano', 'n' => count($p)], ['id' => 'compraram', 'nome' => 'Compraram', 'n' => count($doPlano)]];
    if ($gps !== null) {
        $etapas[] = ['id' => 'prova', 'nome' => 'Com prova de visita (GPS)', 'n' => count(array_filter(array_keys($doPlano), function ($c) use ($gps) { return ($gps[$c] ?? '') === 'ok'; }))];
    }
    if ($valorMedio) $etapas[] = ['id' => 'acima', 'nome' => 'Compra acima da média', 'n' => count(array_filter($doPlano, function ($t) use ($valorMedio) { return $t >= $valorMedio; }))];
    return ['etapas' => $etapas, 'fora_plano' => count(array_diff_key($comp, $p))];
}

/** Recordes: melhor dia, melhor dia igual ao de hoje, posição de ontem e do mês, e se hoje já bateu algum. */
function naval_sala_recordes(array $ag, string $hoje, float $hojeTotal): ?array
{
    $dias = [];
    foreach (array_keys($ag) as $d) if ($d < $hoje && ($t = naval_sala_total_dia($ag, $d)) > 0) $dias[$d] = $t;
    if (count($dias) < 5) return null;
    arsort($dias);
    $melhor = ['d' => (string) key($dias), 't' => round((float) reset($dias), 2)];
    $dow = date('N', strtotime($hoje . ' 12:00'));
    $igual = null;
    foreach ($dias as $d => $t) if (date('N', strtotime($d . ' 12:00')) === $dow) { $igual = ['d' => (string) $d, 't' => round($t, 2)]; break; }
    $ontem = date('Y-m-d', strtotime($hoje . ' 12:00') - 86400);
    $pos = isset($dias[$ontem]) ? array_search($ontem, array_keys($dias), true) + 1 : null;
    $meses = [];
    $mesAct = substr($hoje, 0, 7);
    ksort($dias);
    $inicio = (string) key($dias);
    // meses completos (o primeiro mês com dados só conta se começou nos primeiros 3 dias)
    foreach ($dias as $d => $t) {
        $m = substr($d, 0, 7);
        if ($m === $mesAct || ($m === substr($inicio, 0, 7) && (int) substr($inicio, 8, 2) > 3)) continue;
        $meses[$m] = ($meses[$m] ?? 0) + $t;
    }
    $mesAte = $hojeTotal;
    foreach ($dias as $d => $t) if (substr($d, 0, 7) === $mesAct) $mesAte += $t;
    arsort($meses);
    $melhorMes = $meses ? ['m' => (string) key($meses), 't' => round((float) reset($meses), 2)] : null;
    return ['desde' => $inicio, 'dias' => count($dias), 'melhor_dia' => $melhor, 'melhor_igual' => $igual,
        'ontem' => $pos === null ? null : ['posicao' => $pos, 'de' => count($dias)],
        'melhor_mes' => $melhorMes, 'meses' => count($meses),
        'mes_posicao' => $meses ? 1 + count(array_filter($meses, function ($t) use ($mesAte) { return $t > $mesAte; })) : null,
        'hoje' => ['t' => round($hojeTotal, 2), 'face_recorde' => $melhor['t'] > 0 ? round($hojeTotal / $melhor['t'], 3) : null,
            'bate_recorde' => $hojeTotal > $melhor['t'], 'bate_igual' => $igual !== null && $hojeTotal > $igual['t']]];
}

/** Contagem decrescente do mês: dias de trabalho que faltam e o ritmo diário para igualar o mês passado e o recorde. */
function naval_sala_contagem(array $ag, string $hoje, array $restantes, ?array $recordes): array
{
    $iniMes = substr($hoje, 0, 8) . '01';
    $ate = 0.0;
    $diasVenda = 0;
    foreach (naval_analise_dias($iniMes, $hoje) as $d) {
        $t = naval_sala_total_dia($ag, $d);
        $ate += $t;
        if ($d < $hoje && $t > 0) $diasVenda++;
    }
    $dias = 1 + count(array_filter($restantes, function ($x) { return empty($x['folga']); }));
    $media = $diasVenda ? ($ate - naval_sala_total_dia($ag, $hoje)) / $diasVenda : null;
    $iniAnt = date('Y-m-01', strtotime($iniMes . ' 12:00 -1 month'));
    $mesAnt = 0.0;
    foreach (naval_analise_dias($iniAnt, date('Y-m-t', strtotime($iniAnt . ' 12:00'))) as $d) $mesAnt += naval_sala_total_dia($ag, $d);
    $completo = false;
    foreach (array_keys($ag) as $d) if ($d <= $iniAnt && naval_sala_total_dia($ag, $d) > 0) $completo = true;
    $meta = function (?float $alvo) use ($ate, $dias, $media) {
        if (!$alvo) return null;
        $falta = max(0.0, $alvo - $ate);
        $porDia = $falta / max(1, $dias);
        return ['alvo' => round($alvo, 2), 'falta' => round($falta, 2), 'por_dia' => round($porDia, 2), 'atingido' => $falta <= 0,
            'face_media' => $media ? round($porDia / $media - 1, 3) : null];
    };
    $fimMes = date('Y-m-t', strtotime($hoje . ' 12:00'));
    return ['dias_trabalho' => $dias, 'dias_calendario' => (int) ((strtotime($fimMes . ' 12:00') - strtotime($hoje . ' 12:00')) / 86400) + 1, 'fim' => $fimMes,
        'mes_ate_hoje' => round($ate, 2), 'media_dia' => $media === null ? null : round($media, 2),
        'igualar' => $completo ? $meta($mesAnt) : null, 'recorde' => $recordes && $recordes['melhor_mes'] ? $meta($recordes['melhor_mes']['t']) + ['m' => $recordes['melhor_mes']['m']] : null];
}

/** Artigos que mais sobem e descem: últimos 7 dias fechados face à média semanal das 4 semanas anteriores. */
function naval_sala_artigos(array $ag, string $hoje): ?array
{
    $mais = function (int $n) use ($hoje) { return date('Y-m-d', strtotime($hoje . ' 12:00') + $n * 86400); };
    $soma = function (string $de, string $ate) use ($ag) {
        $o = [];
        foreach (naval_analise_dias($de, $ate) as $d) foreach ((array) ($ag[$d]['artigos'] ?? []) as $l) foreach ((array) $l as $c => $x) {
            $o[$c]['n'] = (string) $x['n'];
            $o[$c]['t'] = ($o[$c]['t'] ?? 0) + (float) $x['t'];
        }
        return $o;
    };
    $refDe = $mais(-35);
    $tem = false;
    foreach (array_keys($ag) as $d) if ($d <= $refDe && naval_sala_total_dia($ag, $d) > 0) $tem = true;
    if (!$tem) return null;
    $a = $soma($mais(-7), $mais(-1));
    $b = $soma($refDe, $mais(-8));
    $totB = array_sum(array_column($b, 't')) / 4;
    $l = [];
    foreach ($b as $c => $x) {
        $ref = $x['t'] / 4;
        if ($ref < max(1.0, $totB * 0.005)) continue;   // artigos com peso (pelo menos 0,5 % das vendas)
        $v = ($a[$c]['t'] ?? 0) / $ref - 1;
        if (abs($v) > 3) continue;
        $l[] = ['c' => (string) $c, 'n' => $x['n'], 'var' => round($v, 3), 't' => round($a[$c]['t'] ?? 0, 2), 'ref' => round($ref, 2)];
    }
    $sobem = array_values(array_filter($l, function ($x) { return $x['var'] > 0.05; }));
    $descem = array_values(array_filter($l, function ($x) { return $x['var'] < -0.05; }));
    usort($sobem, function ($x, $y) { return $y['var'] <=> $x['var']; });
    usort($descem, function ($x, $y) { return $x['var'] <=> $y['var']; });
    return ['actual' => ['de' => $mais(-7), 'ate' => $mais(-1)], 'ref' => ['de' => $refDe, 'ate' => $mais(-8)],
        'sobem' => array_slice($sobem, 0, 5), 'descem' => array_slice($descem, 0, 5)];
}

/** Pareto dos clientes (90 dias fechados): quantos fazem 80 % das vendas e quantos desses estão parados há 14 dias. */
function naval_sala_pareto(array $ag, string $hoje): ?array
{
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - 90 * 86400);
    $t = $ult = [];
    $ini = null;
    foreach ($ag as $d => $x) {
        if ($d < $de || $d >= $hoje) continue;
        foreach ((array) ($x['clientes'] ?? []) as $c => $v) {
            if ((float) $v['t'] <= 0) continue;
            $t[$c] = ($t[$c] ?? 0) + (float) $v['t'];
            $ult[$c] = max($ult[$c] ?? '', (string) $d);
            $ini = $ini === null ? $d : min($ini, $d);
        }
    }
    if (count($t) < 10) return null;
    arsort($t);
    $total = array_sum($t);
    $n = count($t);
    $acc = 0.0;
    $n80 = 0;
    $curva = [];
    $i = 0;
    $grandes = [];
    foreach ($t as $c => $v) {
        $i++;
        if ($acc < 0.8 * $total) { $n80++; $grandes[] = $c; }
        $acc += $v;
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $p) if ($i === (int) max(1, round($n * $p / 100))) $curva[$p] = round($acc / $total, 3);
    }
    $limite = date('Y-m-d', strtotime($hoje . ' 12:00') - 14 * 86400);
    $parados = array_values(array_filter($grandes, function ($c) use ($ult, $limite) { return $ult[$c] < $limite; }));
    $valorParados = 0.0;
    foreach ($parados as $c) $valorParados += $t[$c];
    return ['de' => $ini, 'ate' => date('Y-m-d', strtotime($hoje . ' 12:00') - 86400), 'clientes' => $n, 'n80' => $n80, 'parte80' => round($n80 / $n, 3),
        'curva' => array_map(function ($p, $v) { return ['p' => $p, 'v' => $v]; }, array_keys($curva), array_values($curva)),
        'parados' => count($parados), 'parados_peso' => round($valorParados / $total, 3)];
}

/** Quadrante das rotas (28 dias fechados): compras por dia de venda × valor médio por compra. */
function naval_sala_quadrante(array $ag, string $hoje, array $promotores): array
{
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - 28 * 86400);
    $r = [];
    foreach ($ag as $d => $x) {
        if ($d < $de || $d >= $hoje) continue;
        foreach ((array) ($x['rotas'] ?? []) as $rt => $v) {
            if ((int) ($v['d'] ?? 0) <= 0) continue;
            $r[$rt]['t'] = ($r[$rt]['t'] ?? 0) + (float) $v['t'];
            $r[$rt]['d'] = ($r[$rt]['d'] ?? 0) + (int) $v['d'];
            $r[$rt]['dias'] = ($r[$rt]['dias'] ?? 0) + 1;
        }
    }
    $out = [];
    foreach ($r as $rt => $x) {
        $out[] = ['rota' => (string) $rt, 'promotor' => (string) ($promotores[$rt] ?? ''), 'compras_dia' => round($x['d'] / $x['dias'], 1),
            'valor_medio' => round($x['t'] / $x['d'], 2), 'dias' => $x['dias']];
    }
    usort($out, function ($a, $b) { return strcmp($a['rota'], $b['rota']); });
    $med = function ($k) use ($out) { return $out ? naval_mediana(array_column($out, $k)) : null; };
    return ['de' => $de, 'rotas' => $out, 'mediana' => ['compras_dia' => $med('compras_dia'), 'valor_medio' => $med('valor_medio')]];
}

/** Cobertura do stock dos armazéns: dias de venda que o stock aguenta, ao ritmo médio dos últimos 28 dias. */
function naval_sala_stock(?array $armazens, array $ag, string $hoje): ?array
{
    if ($armazens === null) return null;
    $st = [];
    foreach ($armazens as $a) foreach ((array) ($a['itens'] ?? []) as $i) {
        $c = trim((string) ($i['codigo'] ?? ''));
        if ($c !== '') $st[$c] = ($st[$c] ?? 0) + (float) ($i['quantidade'] ?? 0);
    }
    $de = date('Y-m-d', strtotime($hoje . ' 12:00') - 28 * 86400);
    $q = $nome = [];
    $diasVenda = 0;
    foreach ($ag as $d => $x) {
        if ($d < $de || $d >= $hoje || naval_sala_total_dia($ag, $d) <= 0) continue;
        $diasVenda++;
        foreach ((array) ($x['artigos'] ?? []) as $l) foreach ((array) $l as $c => $v) {
            $q[$c] = ($q[$c] ?? 0) + (float) $v['q'];
            $nome[$c] = (string) $v['n'];
        }
    }
    if (!$diasVenda) return null;
    $l = [];
    foreach ($q as $c => $tq) {
        $m = $tq / $diasVenda;
        if ($m <= 0) continue;
        $s = max(0.0, (float) ($st[$c] ?? 0));
        $l[] = ['c' => (string) $c, 'n' => $nome[$c], 'stock' => round($s, 1), 'dia' => round($m, 1), 'dias' => round($s / $m, 1)];
    }
    usort($l, function ($a, $b) { return [$a['dias'], -$a['dia']] <=> [$b['dias'], -$b['dia']]; });
    return ['artigos' => count($l), 'ruptura' => count(array_filter($l, function ($x) { return $x['stock'] <= 0; })),
        'criticos' => count(array_filter($l, function ($x) { return $x['dias'] > 0 && $x['dias'] < 3; })),
        'lista' => array_slice($l, 0, 8), 'dias_venda' => $diasVenda];
}

/** Saúde da operação: o que espera por alguém (pendentes de todos) e os dias do Pinguim BO por fechar. */
function naval_sala_operacao(?array $pendentes, ?int $pinguimPorFechar): ?array
{
    if ($pendentes === null && $pinguimPorFechar === null) return null;
    $grupos = [
        'caixa' => ['Fecho de caixa por aprovar', ['caixa', 'deposito']],
        'carrinha' => ['Conciliações das carrinhas por fechar', ['carrinha']],
        'po' => ['PO por validar ou aprovar', ['po', 'po_validar', 'po_validar_stock']],
        'cais' => ['Cargas por conferir e guias por gerar', ['po_conferir', 'ordem', 'entrega']],
        'sap' => ['Ficheiros SAP por confirmar', ['sap']],
        'plano' => ['Planos de carga por fazer', ['plano']],
    ];
    $l = [];
    foreach ($grupos as $id => [$nome, $tipos]) {
        $it = array_filter((array) $pendentes, function ($x) use ($tipos) { return in_array($x['tipo'] ?? '', $tipos, true); });
        $l[] = ['id' => $id, 'nome' => $nome, 'n' => count($it), 'atraso' => count(array_filter($it, function ($x) { return !empty($x['atraso']); }))];
    }
    if ($pinguimPorFechar !== null) $l[] = ['id' => 'pinguim', 'nome' => 'Dias por fechar no Pinguim BO', 'n' => $pinguimPorFechar, 'atraso' => $pinguimPorFechar];
    return ['linhas' => $l, 'total' => array_sum(array_column($l, 'n')), 'atraso' => array_sum(array_column($l, 'atraso'))];
}

/** Mapa das rotas de hoje (sala interna): última posição, percurso simplificado e territórios. */
function naval_sala_mapa(?array $terreno, array $territorios, array $promotores): ?array
{
    if ($terreno === null) return null;
    $reduzir = function (array $pts, int $max) {
        $n = count($pts);
        if ($n <= $max) return array_values($pts);
        $o = [];
        for ($i = 0; $i < $max; $i++) $o[] = $pts[(int) round($i * ($n - 1) / ($max - 1))];
        return $o;
    };
    $rotas = [];
    foreach ($terreno as $r) {
        $p = (array) ($r['percurso'] ?? []);
        if (!$p) continue;
        $u = end($p);
        $vis = (array) ($r['visitas'] ?? []);
        $rotas[] = ['rota' => (string) $r['rota'], 'promotor' => (string) ($promotores[$r['rota']] ?? ''), 'pos' => [(float) $u[0], (float) $u[1]], 'hora' => (string) ($u[2] ?? ''),
            'percurso' => array_map(function ($x) { return [round((float) $x[0], 5), round((float) $x[1], 5)]; }, $reduzir($p, 80)),
            'vendas' => count($vis), 'prova' => count(array_filter($vis, function ($v) { return ($v['prova'] ?? '') === 'ok'; })), 'km' => $r['km'] ?? null];
    }
    $terr = [];
    foreach ($territorios as $rt => $pol) if ($pol) $terr[(string) $rt] = array_map(function ($x) {
        return [round((float) ($x['lat'] ?? $x[0] ?? 0), 5), round((float) ($x['lng'] ?? $x[1] ?? 0), 5)];
    }, $reduzir(array_values((array) $pol), 60));
    return ['rotas' => $rotas, 'territorios' => (object) $terr];
}

/** Momentos do dia: acontecimentos a celebrar (o ecrã mostra-os uma vez, quando aparecem). */
function naval_sala_momentos(array $r, ?array $recordes, array $objectivo): array
{
    $hoje = substr((string) ($r['hora'] ?? date('Y-m-d')), 0, 10);
    $m = [];
    if (($r['hoje']['progresso'] ?? 0) >= 1) $m[] = ['id' => $hoje . '|obj-dia', 'tipo' => 'objectivo_dia', 'titulo' => 'Objectivo do dia atingido'];
    if ($recordes && $recordes['hoje']['bate_recorde']) $m[] = ['id' => $hoje . '|recorde', 'tipo' => 'recorde', 'titulo' => 'Novo recorde: o melhor dia de sempre'];
    elseif ($recordes && $recordes['hoje']['bate_igual']) $m[] = ['id' => $hoje . '|recorde-igual', 'tipo' => 'recorde', 'titulo' => 'Melhor ' . ['', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado', 'domingo'][(int) date('N', strtotime($hoje . ' 12:00'))] . '-feira de sempre'];
    foreach ($objectivo as $o) $m[] = ['id' => $hoje . '|rota-' . $o['rota'], 'tipo' => 'rota', 'titulo' => 'Rota ' . $o['rota'] . ' atingiu o objectivo de hoje', 'rota' => $o['rota'], 'promotor' => $o['promotor'] ?? ''];
    return $m;
}

/** Os ecrãs avançados para o open space: sem Kz nem nomes (índices, percentagens, contagens e datas). */
function naval_sala_avancados_aberta(array $x): array
{
    $a = $x['acumulado'] ?? null;
    if ($a) {
        $base = $a['previsto'] ?: (end($a['horas'])['habitual'] ?? 0);
        $ind = function ($v) use ($base) { return $v === null || !$base ? null : (int) round($v / $base * 100); };
        $a = ['horas' => array_map(function ($h) use ($ind) { return ['h' => $h['h'], 'hoje' => $ind($h['hoje']), 'habitual' => $ind($h['habitual']), 'objectivo' => $ind($h['objectivo'])]; }, $a['horas']),
            'ref' => $a['ref'], 'ritmo' => $a['ritmo'], 'base' => $a['previsto'] ? 'objectivo' : 'habitual'];
    }
    $p = $x['previsao'] ?? null;
    if ($p) $p = ['dia' => ['progresso' => $p['dia']['progresso'], 'pelo_ritmo' => $p['dia']['pelo_ritmo']],
        'mes' => ['variacao' => $p['mes']['variacao'], 'dias_resto' => $p['mes']['dias_resto'], 'ref' => $p['mes']['ref']]];
    $rc = $x['recordes'] ?? null;
    if ($rc) $rc = ['desde' => $rc['desde'], 'dias' => $rc['dias'], 'melhor_dia' => ['d' => $rc['melhor_dia']['d']], 'melhor_igual' => $rc['melhor_igual'] ? ['d' => $rc['melhor_igual']['d']] : null,
        'ontem' => $rc['ontem'], 'melhor_mes' => $rc['melhor_mes'] ? ['m' => $rc['melhor_mes']['m']] : null, 'meses' => $rc['meses'], 'mes_posicao' => $rc['mes_posicao'],
        'hoje' => ['face_recorde' => $rc['hoje']['face_recorde'], 'bate_recorde' => $rc['hoje']['bate_recorde'], 'bate_igual' => $rc['hoje']['bate_igual']]];
    $c = $x['contagem'] ?? null;
    if ($c) {
        $sem = function ($m) { return $m === null ? null : array_intersect_key($m, array_flip(['atingido', 'face_media', 'm'])); };
        $c = ['dias_trabalho' => $c['dias_trabalho'], 'dias_calendario' => $c['dias_calendario'], 'fim' => $c['fim'], 'igualar' => $sem($c['igualar']), 'recorde' => $sem($c['recorde'])];
    }
    return ['acumulado' => $a, 'previsao' => $p, 'calor' => $x['calor'] ?? null, 'funil' => $x['funil'] ?? null, 'recordes' => $rc, 'contagem' => $c,
        'momentos' => array_map(function ($m) { unset($m['promotor']); return $m; }, (array) ($x['momentos'] ?? []))];
}

/** Dados para um ecrã em espaço aberto (função pura): percentagens, índices, tendências e contagens; sem Kz nem nomes. */
function naval_sala_aberta(array $r): array
{
    $hoje = (array) ($r['hoje'] ?? []);
    $c = (array) ($r['compras']['hoje'] ?? []);
    $x = (array) ($r['extra'] ?? []);
    // 14 dias em índice: 100 = média dos dias com vendas (sem o valor em Kz)
    $comVendas = array_values(array_filter(array_column((array) ($x['serie'] ?? []), 't'), function ($v) { return $v > 0; }));
    $media = $comVendas ? array_sum($comVendas) / count($comVendas) : 0;
    $vis = $x['visitas'] ?? null;
    return [
        'hoje' => ['progresso' => $hoje['progresso'] ?? null, 'documentos' => (int) ($hoje['documentos'] ?? 0)],
        'ontem' => ['variacao' => $r['ontem']['variacao'] ?? null],
        'semana' => ['variacao' => $r['semana']['variacao'] ?? null],
        'mes' => ['variacao' => $r['mes']['variacao'] ?? null],
        'compras' => ['hoje' => ['compras' => (int) ($c['compras'] ?? 0)]],
        'rotas' => array_map(function ($x) {
            return ['rota' => (string) $x['rota'],
                'progresso_hoje' => !empty($x['previsto_hoje']) ? round((float) $x['hoje'] / (float) $x['previsto_hoje'], 3) : null,
                'variacao_mes' => $x['variacao_mes'] ?? null, 'sequencia' => (int) ($x['sequencia'] ?? 0),
                'ontem_acima' => (float) ($x['habitual_ontem'] ?? 0) > 0 && (float) ($x['ontem'] ?? 0) > (float) $x['habitual_ontem']];
        }, (array) ($r['rotas'] ?? [])),
        'serie' => array_map(function ($s) use ($media) { return ['d' => $s['d'], 'indice' => $media > 0 ? (int) round($s['t'] / $media * 100) : null]; }, (array) ($x['serie'] ?? [])),
        'horas' => (array) ($x['horas'] ?? []),
        'visitas' => $vis === null ? null : array_intersect_key($vis, array_flip(['planeados', 'compraram', 'fora_plano', 'conversao'])),
        'terreno' => ['rotas' => (int) ($x['terreno']['rotas'] ?? 0), 'a_vender' => (int) ($x['terreno']['a_vender'] ?? 0),
            'objectivo' => array_map(function ($o) { return ['rota' => $o['rota']]; }, (array) ($x['terreno']['objectivo'] ?? []))],
        'clientes' => array_intersect_key((array) ($x['clientes'] ?? []), array_flip(['hoje', 'mes', 'mes_anterior'])),
        'comparacoes' => isset($x['comparacoes']) ? ['inicio' => $x['comparacoes']['inicio'], 'linhas' => array_map(function ($c) {
            unset($c['actual']['t']);
            if ($c['ref']) unset($c['ref']['t']);
            return $c;
        }, (array) $x['comparacoes']['linhas'])] : null,
    ] + naval_sala_avancados_aberta($x);
}

/** Dados da sala interna (função pura): totais por rota e o nome do promotor, sem clientes, documentos nem NIF. */
function naval_sala_interna(array $r): array
{
    return ['hoje' => $r['hoje'], 'ontem' => $r['ontem'], 'semana' => $r['semana'], 'mes' => $r['mes'], 'compras' => $r['compras'] ?? null,
        'rotas' => array_map(function ($x) {
            return array_intersect_key($x, array_flip(['rota', 'promotor', 'hoje', 'previsto_hoje', 'ontem', 'habitual_ontem', 'mes', 'mes_anterior', 'variacao_mes', 'sequencia']));
        }, (array) $r['rotas'])] + (array) ($r['extra'] ?? []);
}

/** Lê as fontes (agregados, vendas por hora de hoje e plano de visitas), guardado 2 minutos para todos os ecrãs. */
function naval_sala_dados(): array
{
    return naval_cache('sala|' . date('Y-m-d H') . floor((int) date('i') / 2), 120, function () {
        naval_servico('analise');
        naval_servico('previsao');
        naval_servico('rotas');
        $agora = time();
        $hoje = date('Y-m-d', $agora);
        // desde 1 de Janeiro do ano anterior: comparativos homólogos (os dias fechados ficam gravados e não voltam ao Pinguim BO)
        $ag = naval_analise_agregados((date('Y', $agora) - 1) . '-01-01', $hoje);
        $promotores = naval_promotores();
        $r = naval_indicadores($agora, null, $ag, $promotores);
        $horas = $horasValor = [];
        try {
            naval_servico('vendas');
            $v = naval_vendas_sql(date('Ymd', $agora), date('Ymd', $agora), $promotores, false);
            foreach ((array) ($v['vendas'] ?? []) as $x) {
                if (($x['data'] ?? $hoje) !== $hoje) continue;
                $hh = substr((string) ($x['hora'] ?? ''), 0, 2);
                if (preg_match('/^\d\d$/', $hh)) $horasValor[$hh] = round(($horasValor[$hh] ?? 0) + (float) ($x['total_sem_iva'] ?? $x['total'] ?? 0), 2);
                if (!empty($x['nc'])) continue;
                if (preg_match('/^\d\d$/', $hh)) $horas[$hh] = ($horas[$hh] ?? 0) + 1;
            }
        } catch (Throwable $e) {
            error_log('[sala] vendas por hora: ' . $e->getMessage());
        }
        $plano = null;
        try {
            naval_servico('conversao');
            $plano = naval_conv_plano($hoje);
        } catch (Throwable $e) {
            error_log('[sala] plano de visitas: ' . $e->getMessage());
        }
        $r['extra'] = naval_sala_extra($ag, $hoje, $horas, $plano, (array) $r['rotas'], (array) ($r['compras'] ?? []));
        $r['extra']['comparacoes'] = naval_sala_comparacoes($ag, $hoje);
        $r['extra'] += naval_sala_avancados($ag, $hoje, $agora, $horasValor, $plano, $r, $promotores);
        return $r;
    });
}

/** Vendas por hora dos últimos 35 dias fechados {dia: {HH: valor sem IVA}}, lidas uma vez por dia. */
function naval_sala_horas_hist(string $hoje, array $promotores): array
{
    return naval_cache('sala-horas|' . $hoje, 86400, function () use ($hoje, $promotores) {
        naval_servico('vendas');
        $t = strtotime($hoje . ' 12:00');
        $v = naval_vendas_sql(date('Ymd', $t - 35 * 86400), date('Ymd', $t - 86400), $promotores, false);
        $o = [];
        foreach (naval_vendas_efectivas((array) ($v['vendas'] ?? [])) as $x) {
            $hh = substr((string) ($x['hora'] ?? ''), 0, 2);
            if (!preg_match('/^\d\d$/', $hh)) continue;
            $d = (string) ($x['data'] ?? '');
            $o[$d][$hh] = round(($o[$d][$hh] ?? 0) + (float) ($x['total_sem_iva'] ?? $x['total'] ?? 0), 2);
        }
        return $o;
    });
}

/** Os ecrãs avançados (cada fonte à parte: uma que falhe não deixa o painel sem os outros). */
function naval_sala_avancados(array $ag, string $hoje, int $agora, array $horasValor, ?array $plano, array $r, array $promotores): array
{
    $tenta = function (string $o, callable $f) {
        try {
            return $f();
        } catch (Throwable $e) {
            error_log('[sala] ' . $o . ': ' . $e->getMessage());
            return null;
        }
    };
    $ontem = date('Y-m-d', strtotime($hoje . ' 12:00') - 86400);
    $serie = [];
    foreach (array_keys($ag) as $d) if ($d < $hoje) $serie[$d] = naval_sala_total_dia($ag, $d);
    $prevHoje = $tenta('previsão de hoje', function () use ($serie, $hoje, $ontem) { return naval_previsao_dia($serie, $hoje, $ontem); });
    $previsto = $r['hoje']['previsto'] ?? ($prevHoje['previsto'] ?? null);
    // dias do mês depois de hoje: previsão de cada um (folgas do calendário a zero)
    $com = array_values(array_filter(array_slice($serie, -28, null, true), function ($v) { return $v > 0; }));
    $media = $com ? array_sum($com) / count($com) : 0.0;
    $restantes = [];
    for ($t = strtotime($hoje . ' 12:00') + 86400; date('Y-m', $t) === substr($hoje, 0, 7); $t += 86400) {
        $d = date('Y-m-d', $t);
        $p = $tenta('previsão do mês', function () use ($serie, $d, $ontem) { return naval_previsao_dia($serie, $d, $ontem); });
        $restantes[$d] = ['p' => $p === null ? $media : (float) $p['previsto'], 'folga' => !empty($p['folga'])];
    }
    $hist = $tenta('vendas por hora', function () use ($hoje, $promotores) { return naval_sala_horas_hist($hoje, $promotores); }) ?? [];
    $acum = naval_sala_acumulado($hist, $hoje, $horasValor, $previsto ? (float) $previsto : null, (int) date('G', $agora));
    $recordes = naval_sala_recordes($ag, $hoje, (float) ($r['hoje']['total'] ?? 0));
    // GPS das vendas de hoje (OLAP): prova de visita do funil e mapa das rotas
    $terreno = $tenta('terreno', function () use ($hoje) {
        naval_servico('olap');
        naval_servico('prospeccao');
        if (!naval_olap_configurado()) return null;
        $d = naval_olap_dia($hoje, null);
        $g = naval_json_ler(naval_geo_pasta() . '/vista.json');
        $terr = [];
        foreach ((array) ($g['rotas'] ?? []) as $x) $terr[(string) $x['rota']] = (array) ($x['territorio'] ?? []);
        return ['t' => naval_olap_terreno((array) $d['pontos'], (array) ($g['clientes'] ?? []), $terr, (int) naval_geo_num('GEO_VISITAS_DIA', 30)), 'terr' => $terr];
    });
    $gps = null;
    if ($terreno) {
        $gps = [];
        foreach ((array) $terreno['t']['rotas'] as $x) foreach ((array) $x['visitas'] as $v) if ($v['cli'] !== '' && ($gps[$v['cli']] ?? '') !== 'ok') $gps[$v['cli']] = $v['prova'];
    }
    $stock = $tenta('stock', function () use ($hoje) {
        return naval_cache('sala-stock|' . $hoje . '|' . floor(time() / 1800), 1800, function () use ($hoje) {
            naval_servico('stock');
            $d = naval_stock($hoje, '');
            return $d === null ? null : array_map(function ($a) {
                return ['itens' => array_map(function ($i) { return ['codigo' => $i['codigo'] ?? '', 'quantidade' => $i['quantidade'] ?? 0]; }, (array) ($a['itens'] ?? []))];
            }, (array) ($d['armazens'] ?? []));
        });
    });
    $operacao = $tenta('operação', function () use ($hoje) {
        return naval_cache('sala-operacao|' . $hoje . '|' . floor(time() / 600), 600, function () use ($hoje) {
            naval_servico('pendentes');
            $todos = array_fill_keys(['caixa1', 'caixa2', 'contar', 'coordenar', 'fechar', 'po', 'validar_po', 'conferir', 'validar', 'tesouraria', 'plano', 'sap'], true);
            $p = naval_pendentes('', null, $todos, time());
            $porFechar = null;
            try {
                naval_servico('pinguim-dia');
                $l = naval_pdia_ler(date('Y-m-d', strtotime($hoje . ' 12:00') - 3 * 86400), date('Y-m-d', strtotime($hoje . ' 12:00') - 86400));
                $porFechar = (int) ($l['contagens']['por_fechar'] ?? 0);
            } catch (Throwable $e) {
                error_log('[sala] Pinguim BO dias: ' . $e->getMessage());
            }
            return ['itens' => array_map(function ($x) { return ['tipo' => $x['tipo'] ?? '', 'atraso' => !empty($x['atraso'])]; }, (array) $p['itens']), 'pinguim' => $porFechar];
        });
    });
    $prevDia = $previsto ? ['previsto' => (float) $previsto] + ($prevHoje ?? []) : null;
    return [
        'acumulado' => $acum,
        'previsao' => naval_sala_previsao($acum, $prevDia, $ag, $hoje, $restantes),
        'calor' => $hist ? naval_sala_calor($hist, $hoje) : null,
        'funil' => naval_sala_funil($plano, (array) ($ag[$hoje]['clientes'] ?? []), $gps, isset($r['compras']['mes']['valor_medio']) ? (float) $r['compras']['mes']['valor_medio'] : null),
        'recordes' => $recordes,
        'contagem' => naval_sala_contagem($ag, $hoje, $restantes, $recordes),
        'artigos' => naval_sala_artigos($ag, $hoje),
        'pareto' => naval_sala_pareto($ag, $hoje),
        'quadrante' => naval_sala_quadrante($ag, $hoje, $promotores),
        'stock' => naval_sala_stock($stock, $ag, $hoje),
        'operacao' => $operacao === null ? null : naval_sala_operacao($operacao['itens'], $operacao['pinguim']),
        'mapa' => $terreno ? naval_sala_mapa((array) $terreno['t']['rotas'], $terreno['terr'], $promotores) : null,
        'momentos' => naval_sala_momentos($r, $recordes, (array) ($r['extra']['terreno']['objectivo'] ?? [])),
    ];
}

/** Resposta para um ecrã: só o que o local pode mostrar. */
function naval_sala_resposta(string $local, ?array $config, ?array $dados = null): array
{
    $r = $dados ?? naval_sala_dados();
    $base = ['ok' => true, 'local' => $local, 'config' => naval_sala_config($config, $local), 'hora' => $r['hora']];
    return $base + ($local === 'aberta' ? naval_sala_aberta($r) : naval_sala_interna($r));
}
