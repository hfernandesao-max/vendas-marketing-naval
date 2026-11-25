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
const NAVAL_SALA_ECRAS = ['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'rank-hoje', 'rank-mes', 'destaques'];
/** Ecrãs que podem estar num espaço aberto (na versão sem valores em Kz nem nomes). */
const NAVAL_SALA_ECRAS_ABERTA = ['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'destaques'];
/** Configurações por omissão anteriores (antes dos ecrãs novos): ligações nunca personalizadas passam a ver todos. */
const NAVAL_SALA_ANTIGAS = [['hoje', 'rank-hoje', 'rank-mes', 'destaques'], ['hoje', 'destaques']];
const NAVAL_SALA_LOCAIS = ['interna', 'aberta'];

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
    if (!$config || (empty($config['personalizado']) && in_array(array_values((array) ($config['ecras'] ?? [])), NAVAL_SALA_ANTIGAS, true))) {
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
    ];
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
        $horas = [];
        try {
            naval_servico('vendas');
            $v = naval_vendas_sql(date('Ymd', $agora), date('Ymd', $agora), $promotores, false);
            foreach ((array) ($v['vendas'] ?? []) as $x) {
                if (!empty($x['nc']) || ($x['data'] ?? $hoje) !== $hoje) continue;
                $hh = substr((string) ($x['hora'] ?? ''), 0, 2);
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
        return $r;
    });
}

/** Resposta para um ecrã: só o que o local pode mostrar. */
function naval_sala_resposta(string $local, ?array $config, ?array $dados = null): array
{
    $r = $dados ?? naval_sala_dados();
    $base = ['ok' => true, 'local' => $local, 'config' => naval_sala_config($config, $local), 'hora' => $r['hora']];
    return $base + ($local === 'aberta' ? naval_sala_aberta($r) : naval_sala_interna($r));
}
