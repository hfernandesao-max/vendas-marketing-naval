<?php
/**
 * Rotas propostas (GeoMobilidade): quando a carteira o exige, o portal propõe uma rota nova e espera pela aprovação.
 *
 * Origem das propostas (todas as noites, depois do cálculo da prospecção — naval_geo_noite):
 *  - «divisao»: rota acima de GEO_LIMIAR_ROTA (90 %) de ocupação 14 dias seguidos; propõe passar para uma rota nova a
 *    metade do território mais afastada (naval_geo_dividir), com os clientes dessa metade;
 *  - «zona»: zona sem rota com muitos potenciais clientes (naval_geo_zonas_sem_rota); propõe uma rota nova para a
 *    prospectar, com o território de 2 km à volta.
 * Cada proposta traz o impacto: clientes, visitas por semana face à capacidade (GEO_VISITAS_DIA × GEO_DIAS_SEMANA),
 * ocupação da rota de origem depois da divisão, vendas por semana e peso por dia de visita (quantidades vendidas ×
 * kg de cada artigo do Pinguim BO) face à capacidade de carga (ROTAS_CAPACIDADE_KG ou a mediana da frota activa).
 *
 * Estados: pendente → aprovada → activa (quando as vendas do Pinguim BO já mostram os clientes na rota nova)
 *          pendente → rejeitada · pendente → substituida (a situação deixou de existir antes da decisão).
 * Aprovar (nível G em «geo-prospeccao»; nunca quem não decide) indica o código da rota nova e o promotor. O portal
 * só lê o Pinguim BO: a atribuição fica no portal (dados-privados/geo/atribuicoes.json) e as visitas do dia, os
 * percursos e os mapas usam-na; sai a lista dos clientes para registar a rota no Pinguim BO. Um cliente que já esteja
 * noutra atribuição aprovada não é atribuído duas vezes (conflito assinalado na decisão).
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('prospeccao');

const NAVAL_RP_ESTADOS = ['pendente' => 'Pendente de aprovação', 'aprovada' => 'Aprovada: por registar no Pinguim BO', 'activa' => 'Activa no Pinguim BO',
    'rejeitada' => 'Rejeitada', 'substituida' => 'Substituída (a situação mudou antes da decisão)'];

/** Parte dos clientes atribuídos que tem de aparecer nas vendas com a rota nova para a proposta passar a activa. */
const NAVAL_RP_ACTIVA = 0.8;

function naval_rp_ficheiro(): string
{
    return naval_geo_pasta() . '/propostas.json';
}

function naval_rp_ficheiro_atribuicoes(): string
{
    return naval_geo_pasta() . '/atribuicoes.json';
}

/** Atribuições aprovadas no portal: {cliente: {r, proposta, em, por, origem}} (lidas uma vez por pedido). */
function naval_rp_atribuicoes(bool $fresco = false): array
{
    static $a = null;
    if ($a === null || $fresco) $a = naval_json_ler(naval_rp_ficheiro_atribuicoes());
    return $a;
}

/** Rota efectiva de um cliente: a atribuída no portal, se houver; senão a das vendas. */
function naval_rp_rota(string $cliente, string $rota, ?array $atrib = null): string
{
    $atrib = $atrib ?? naval_rp_atribuicoes();
    return isset($atrib[$cliente]['r']) ? (string) $atrib[$cliente]['r'] : $rota;
}

/** Aplica as atribuições a uma lista de clientes [{c, r, …}] (função pura com $atrib). */
function naval_rp_aplicar(array $clientes, ?array $atrib = null, string $campoCodigo = 'c', string $campoRota = 'r'): array
{
    $atrib = $atrib ?? naval_rp_atribuicoes();
    if (!$atrib) return $clientes;
    foreach ($clientes as $k => $c) {
        $cod = (string) ($c[$campoCodigo] ?? '');
        if ($cod !== '' && isset($atrib[$cod]['r'])) $clientes[$k][$campoRota] = (string) $atrib[$cod]['r'];
    }
    return $clientes;
}

/** Territórios das rotas novas de zona aprovadas: {rota: polígono} (para atribuir os potenciais clientes). */
function naval_rp_territorios_zona(?array $propostas = null): array
{
    $ps = $propostas ?? (array) (naval_json_ler(naval_rp_ficheiro())['propostas'] ?? []);
    $out = [];
    foreach ($ps as $p) {
        if (($p['tipo'] ?? '') !== 'zona' || !in_array($p['estado'] ?? '', ['aprovada', 'activa'], true)) continue;
        $r = (string) ($p['decisao']['rota_nova'] ?? '');
        if ($r !== '' && !empty($p['territorio'])) $out[$r] = $p['territorio'];
    }
    return $out;
}

/** Polígono quadrado de $lado metros à volta de um ponto (território de uma zona sem rota). */
function naval_rp_quadrado(float $lat, float $lng, float $lado = 2000.0): array
{
    $dLat = ($lado / 2) / 110540;
    $dLng = ($lado / 2) / (111320 * max(0.2, cos(deg2rad($lat))));
    $r = function ($x) { return round($x, 6); };
    return [[$r($lat - $dLat), $r($lng - $dLng)], [$r($lat - $dLat), $r($lng + $dLng)], [$r($lat + $dLat), $r($lng + $dLng)], [$r($lat + $dLat), $r($lng - $dLng)]];
}

/**
 * Capacidade de carga de referência (kg) para uma rota nova: ROTAS_CAPACIDADE_KG ou a mediana das viaturas activas
 * da frota do plano de cargas; null sem frota conhecida.
 */
function naval_rp_capacidade_kg(?array $listas = null): ?float
{
    $cfg = (float) naval_config('ROTAS_CAPACIDADE_KG', '0');
    if ($cfg > 0) return $cfg;
    if ($listas === null) {
        naval_servico('cargas');
        $listas = naval_plano_listas();
    }
    $kg = array_values(array_filter(array_map('floatval', (array) ($listas['capacidades'] ?? [])), function ($x) { return $x > 0; }));
    if (!$kg) return null;
    sort($kg);
    return $kg[intdiv(count($kg), 2)];
}

/**
 * Impacto de um conjunto de clientes (função pura): visitas por semana (ritmo de compras f), vendas por semana sem
 * IVA e peso por dia de visita, a partir dos agregados por dia ($agregados: {dia: {clientes: {c: {t}}, ca: {c: {sku: q}}}}).
 * $pesos: {sku: kg}. Devolve também os artigos sem peso conhecido (o peso fica por baixo).
 */
function naval_rp_impacto(array $clientes, array $agregados, array $pesos, float $capVisitas, ?float $capKg, int $diasSemana): array
{
    $cods = [];
    foreach ($clientes as $c) $cods[(string) $c['c']] = max(1, (int) ($c['f'] ?? 1));
    $vendas = 0.0;
    $kg = 0.0;
    $semPeso = [];
    foreach ($agregados as $ag) {
        foreach ((array) ($ag['clientes'] ?? []) as $c => $x) if (isset($cods[(string) $c])) $vendas += (float) ($x['t'] ?? 0);
        foreach ((array) ($ag['ca'] ?? []) as $c => $arts) {
            if (!isset($cods[(string) $c])) continue;
            foreach ((array) $arts as $sku => $q) {
                if (isset($pesos[(string) $sku])) $kg += (float) $q * (float) $pesos[(string) $sku];
                else $semPeso[(string) $sku] = true;
            }
        }
    }
    $semanas = max(1, count($agregados) / 7);
    $visitas = array_sum($cods);
    // sem nenhum peso de artigo conhecido, o peso fica por saber (nunca «0 kg»)
    $kgDia = !$pesos ? null : ($diasSemana > 0 ? $kg / $semanas / $diasSemana : 0.0);
    return [
        'clientes' => count($cods),
        'visitas_semana' => $visitas,
        'capacidade_visitas' => (int) $capVisitas,
        'ocupacao' => $capVisitas > 0 ? round($visitas / $capVisitas, 3) : null,
        'vendas_semana' => round($vendas / $semanas, 2),
        'kg_dia' => $kgDia === null ? null : round($kgDia, 1),
        'capacidade_kg' => $capKg,
        'carga' => $capKg && $kgDia !== null ? round($kgDia / $capKg, 3) : null,
        'artigos_sem_peso' => count($semPeso),
    ];
}

/** Chave que identifica a situação de uma proposta (uma proposta aberta por rota cheia e por zona). */
function naval_rp_chave(array $p): string
{
    return $p['tipo'] === 'divisao' ? 'divisao:' . $p['origem_rota'] : 'zona:' . round((float) $p['centro'][0], 2) . ':' . round((float) $p['centro'][1], 2);
}

/**
 * Propostas novas a partir da vista da prospecção (função pura): $cheias = rotas acima do limiar há 14 dias;
 * $existentes: propostas guardadas; $atrib: atribuições já aprovadas (esses clientes não entram noutra proposta).
 * Devolve [novas, chaves das situações que se mantêm].
 */
function naval_rp_gerar(array $vista, array $cheias, array $existentes, array $atrib, array $agregados, array $pesos, ?float $capKg, string $hoje): array
{
    $par = (array) ($vista['parametros'] ?? []);
    $capV = (float) ($par['visitas_dia'] ?? 30) * (float) ($par['dias_semana'] ?? 6);
    $dias = (int) ($par['dias_semana'] ?? 6);
    $abertas = [];
    foreach ($existentes as $p) if (in_array($p['estado'], ['pendente', 'aprovada'], true)) $abertas[naval_rp_chave($p)] = true;
    $porRota = [];
    foreach ((array) ($vista['clientes'] ?? []) as $c) if (($c['r'] ?? '') !== '' && !isset($atrib[(string) $c['c']])) $porRota[(string) $c['r']][] = $c;
    $info = array_column((array) ($vista['rotas'] ?? []), null, 'rota');
    $novas = [];
    $mantem = [];
    foreach ($cheias as $r) {
        $r = (string) $r;
        $cs = $porRota[$r] ?? [];
        if (count($cs) < 2) continue;
        $p = ['tipo' => 'divisao', 'origem_rota' => $r];
        $mantem[] = naval_rp_chave($p);
        if (isset($abertas[naval_rp_chave($p)])) continue;
        [$fica, $passa] = naval_geo_dividir($cs);
        // passa para a rota nova a metade mais pequena (a rota de origem mantém a maior parte da carteira)
        if (count($passa) > count($fica)) [$fica, $passa] = [$passa, $fica];
        if (!$passa) continue;
        $imp = naval_rp_impacto($passa, $agregados, $pesos, $capV, $capKg, $dias);
        $orig = naval_rp_impacto($fica, $agregados, $pesos, $capV, $capKg, $dias);
        $novas[] = $p + [
            'motivo' => sprintf('Rota %s com %d%% de ocupação há 14 dias seguidos (%d visitas por semana para %d).', $r,
                (int) round(((float) ($info[$r]['ocupacao'] ?? 0)) * 100), (int) ($info[$r]['visitas_semana'] ?? 0), (int) $capV),
            'territorio' => naval_geo_territorio($passa),
            'centro' => [round(array_sum(array_column($passa, 'lat')) / count($passa), 5), round(array_sum(array_column($passa, 'lng')) / count($passa), 5)],
            'clientes' => array_values(array_map(function ($c) { return (string) $c['c']; }, $passa)),
            'potenciais' => 0,
            'impacto' => $imp + ['ocupacao_origem_antes' => $info[$r]['ocupacao'] ?? null, 'ocupacao_origem_depois' => $orig['ocupacao']],
            'criada' => $hoje,
        ];
    }
    foreach ((array) ($vista['zonas_sem_rota'] ?? []) as $z) {
        $p = ['tipo' => 'zona', 'origem_rota' => '', 'centro' => [(float) $z['lat'], (float) $z['lng']]];
        $mantem[] = naval_rp_chave($p);
        if (isset($abertas[naval_rp_chave($p)])) continue;
        $n = (int) $z['potenciais'];
        $novas[] = $p + [
            'motivo' => sprintf('%d estabelecimentos comerciais numa zona sem rota.', $n),
            'territorio' => naval_rp_quadrado((float) $z['lat'], (float) $z['lng']),
            'clientes' => [],
            'potenciais' => $n,
            'impacto' => ['clientes' => 0, 'visitas_semana' => $n, 'capacidade_visitas' => (int) $capV, 'ocupacao' => $capV > 0 ? round($n / $capV, 3) : null,
                'vendas_semana' => null, 'kg_dia' => null, 'capacidade_kg' => $capKg, 'carga' => null, 'artigos_sem_peso' => 0],
            'criada' => $hoje,
        ];
    }
    return [$novas, $mantem];
}

/**
 * Junta as propostas novas às guardadas (função pura): numera RP-0001…, passa a «substituida» a pendente cuja
 * situação deixou de existir e a «activa» a aprovada cujos clientes já vendem na rota nova ($rotaVendas: {c: rota}).
 */
function naval_rp_juntar(array $guardado, array $novas, array $mantem, array $rotaVendas, string $hoje): array
{
    $ps = (array) ($guardado['propostas'] ?? []);
    $seq = (int) ($guardado['seq'] ?? 0);
    $mantem = array_flip($mantem);
    foreach ($ps as &$p) {
        if ($p['estado'] === 'pendente' && !isset($mantem[naval_rp_chave($p)])) {
            $p['estado'] = 'substituida';
            $p['historico'][] = ['em' => $hoje, 'accao' => 'substituida'];
        }
        if ($p['estado'] === 'aprovada' && $p['clientes']) {
            $nova = (string) $p['decisao']['rota_nova'];
            $ja = count(array_filter($p['decisao']['atribuidos'] ?? $p['clientes'], function ($c) use ($rotaVendas, $nova) { return ($rotaVendas[$c] ?? '') === $nova; }));
            if ($ja >= NAVAL_RP_ACTIVA * count($p['decisao']['atribuidos'] ?? $p['clientes'])) {
                $p['estado'] = 'activa';
                $p['historico'][] = ['em' => $hoje, 'accao' => 'activa'];
            }
        }
    }
    unset($p);
    foreach ($novas as $n) {
        $seq++;
        $ps[] = ['id' => sprintf('RP-%04d', $seq), 'estado' => 'pendente'] + $n + ['decisao' => null, 'historico' => [['em' => $hoje, 'accao' => 'criada']]];
    }
    return ['seq' => $seq, 'propostas' => $ps];
}

/** Tarefa da noite, depois do cálculo da prospecção: gera, actualiza e grava as propostas. Devolve as novas. */
function naval_rp_noite(array $vista, array $agregados): array
{
    naval_servico('carrinha');
    $cheias = naval_geo_rotas_cheias(naval_json_ler(naval_geo_pasta() . '/ocupacao.json'), (float) ($vista['parametros']['limiar'] ?? 0.9));
    $hab = naval_geo_habitos($agregados);
    $rotaVendas = array_map(function ($h) { return (string) $h['r']; }, $hab);
    // ritmo de compras (f) de cada cliente na vista, para as visitas por semana
    foreach ($vista['clientes'] as &$c) $c['f'] = $hab[(string) $c['c']]['f'] ?? 1;
    unset($c);
    $pesos = naval_carga_pesos_sku();
    $capKg = naval_rp_capacidade_kg();
    return naval_exclusivo('rotas-propostas', function () use ($vista, $cheias, $agregados, $pesos, $capKg, $rotaVendas) {
        $g = naval_json_ler(naval_rp_ficheiro());
        $hoje = date('Y-m-d');
        [$novas, $mantem] = naval_rp_gerar($vista, $cheias, (array) ($g['propostas'] ?? []), naval_rp_atribuicoes(true), $agregados, $pesos, $capKg, $hoje);
        naval_json_gravar(naval_rp_ficheiro(), naval_rp_juntar($g, $novas, $mantem, $rotaVendas, $hoje));
        return $novas;
    });
}

/**
 * Decide uma proposta pendente (função pura sobre o estado guardado): aprovar atribui à rota nova os clientes que
 * ainda não estejam noutra atribuição (os outros vão em «conflitos»); rejeitar fecha-a com a nota.
 * $rotasExistentes: códigos de rota já usados (a rota nova não pode ser uma delas, excepto a da própria proposta).
 * Devolve [guardado, atribuicoes, resultado].
 */
function naval_rp_decidir(array $guardado, array $atrib, string $id, string $accao, array $dados, array $eu, array $rotasExistentes, string $agora): array
{
    $ps = (array) ($guardado['propostas'] ?? []);
    $i = null;
    foreach ($ps as $k => $p) if ($p['id'] === $id) $i = $k;
    if ($i === null) throw new NavalErro('Proposta desconhecida.', 404);
    if ($ps[$i]['estado'] !== 'pendente') throw new NavalErro('Esta proposta já foi decidida.', 409);
    $nota = naval_texto((string) ($dados['nota'] ?? ''), 300);
    if ($accao === 'rejeitar') {
        if (mb_strlen($nota) < 5) throw new NavalErro('Indique o motivo da rejeição.', 400);
        $ps[$i]['estado'] = 'rejeitada';
        $ps[$i]['decisao'] = ['accao' => 'rejeitar', 'por' => (string) $eu['login'], 'nome' => (string) ($eu['nome'] ?? $eu['login']), 'em' => $agora, 'nota' => $nota];
        $ps[$i]['historico'][] = ['em' => $agora, 'accao' => 'rejeitada', 'por' => (string) $eu['login']];
        return [['propostas' => $ps] + $guardado, $atrib, ['id' => $id, 'estado' => 'rejeitada']];
    }
    if ($accao !== 'aprovar') throw new NavalErro('Acção desconhecida.', 400);
    $rota = strtoupper(trim((string) ($dados['rota_nova'] ?? '')));
    if (!preg_match('/^[A-Z0-9_-]{1,20}$/', $rota)) throw new NavalErro('Indique o código da rota nova (letras, algarismos, «-» ou «_»).', 400);
    if (in_array($rota, array_map('strtoupper', $rotasExistentes), true)) throw new NavalErro('A rota ' . $rota . ' já existe: escolha um código novo.', 409);
    foreach ($ps as $p) {
        if (in_array($p['estado'], ['aprovada', 'activa'], true) && strtoupper((string) ($p['decisao']['rota_nova'] ?? '')) === $rota) {
            throw new NavalErro('A rota ' . $rota . ' já foi usada na proposta ' . $p['id'] . '.', 409);
        }
    }
    $promotor = naval_texto((string) ($dados['promotor'] ?? ''), 80);
    $atribuidos = [];
    $conflitos = [];
    foreach ($ps[$i]['clientes'] as $c) {
        if (isset($atrib[$c])) {
            $conflitos[] = ['c' => $c, 'rota' => $atrib[$c]['r'], 'proposta' => $atrib[$c]['proposta']];
            continue;
        }
        $atrib[$c] = ['r' => $rota, 'proposta' => $id, 'em' => $agora, 'por' => (string) $eu['login'], 'origem' => (string) $ps[$i]['origem_rota']];
        $atribuidos[] = $c;
    }
    $ps[$i]['estado'] = 'aprovada';
    $ps[$i]['decisao'] = ['accao' => 'aprovar', 'por' => (string) $eu['login'], 'nome' => (string) ($eu['nome'] ?? $eu['login']), 'em' => $agora, 'nota' => $nota,
        'rota_nova' => $rota, 'promotor' => $promotor, 'atribuidos' => $atribuidos, 'conflitos' => $conflitos];
    $ps[$i]['historico'][] = ['em' => $agora, 'accao' => 'aprovada', 'por' => (string) $eu['login']];
    return [['propostas' => $ps] + $guardado, $atrib, ['id' => $id, 'estado' => 'aprovada', 'rota_nova' => $rota, 'atribuidos' => count($atribuidos), 'conflitos' => $conflitos]];
}

/** Aprova ou rejeita (com o ficheiro trancado): grava as propostas e as atribuições e regista a decisão. */
function naval_rp_decisao(string $id, string $accao, array $dados, array $eu): array
{
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $rotas = array_values(array_unique(array_merge(array_column((array) ($v['rotas'] ?? []), 'rota'), array_keys(naval_promotores()))));
    return naval_exclusivo('rotas-propostas', function () use ($id, $accao, $dados, $eu, $rotas) {
        [$g, $a, $r] = naval_rp_decidir(naval_json_ler(naval_rp_ficheiro()), naval_rp_atribuicoes(true), $id, $accao, $dados, $eu, array_map('strval', $rotas), date('Y-m-d H:i:s'));
        naval_json_gravar(naval_rp_ficheiro_atribuicoes(), $a);
        naval_json_gravar(naval_rp_ficheiro(), $g);
        naval_rp_atribuicoes(true);
        naval_registar('rota_proposta_' . ($accao === 'aprovar' ? 'aprovada' : 'rejeitada'), ['proposta' => $id] + array_intersect_key($r, array_flip(['rota_nova', 'atribuidos'])));
        return $r;
    });
}

/**
 * Lista das propostas para o ecrã de aprovação, no âmbito de quem pede ($rotas null = todas): cada uma com os
 * clientes (código, nome, rota actual) para registar no Pinguim BO. Quem só vê as suas rotas vê as propostas que
 * as tocam (divisão da sua rota ou rota nova sua).
 */
function naval_rp_vista(?array $rotas): array
{
    $g = naval_json_ler(naval_rp_ficheiro());
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $cli = [];
    foreach ((array) ($v['clientes'] ?? []) as $c) $cli[(string) $c['c']] = $c;
    $out = [];
    foreach (array_reverse((array) ($g['propostas'] ?? [])) as $p) {
        $toca = $rotas === null || in_array((string) $p['origem_rota'], $rotas, true) || in_array((string) ($p['decisao']['rota_nova'] ?? ''), $rotas, true);
        if (!$toca) continue;
        $p['clientes'] = array_map(function ($c) use ($cli, $p) {
            $x = $cli[$c] ?? [];
            return ['c' => $c, 'n' => (string) ($x['n'] ?? ''), 'rota' => (string) $p['origem_rota'], 'lat' => $x['lat'] ?? null, 'lng' => $x['lng'] ?? null];
        }, (array) $p['clientes']);
        $p['estado_nome'] = NAVAL_RP_ESTADOS[$p['estado']] ?? $p['estado'];
        $out[] = $p;
    }
    return ['propostas' => $out, 'estados' => NAVAL_RP_ESTADOS, 'pendentes' => count(array_filter($out, function ($p) { return $p['estado'] === 'pendente'; }))];
}
