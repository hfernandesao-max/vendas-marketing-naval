<?php
/**
 * Alertas de anomalias (fase 1 da IA no portal): regras estatísticas explicáveis sobre os agregados diários
 * (nucleo/servicos/analise.php). Nenhuma regra decide nada: o alerta diz o que aconteceu, quanto era de esperar
 * e porquê, e a pessoa verifica.
 *
 * Comparações sempre com o MESMO dia da semana (as rotas do RTM têm um ciclo semanal) e com estatística robusta
 * (mediana e desvio absoluto mediano), para um dia invulgar no histórico não esconder nem inventar alertas.
 *
 * Alerta: {id, tipo, gravidade (critico | aviso | info), rota, titulo, detalhe, valor, esperado, dia, pagina}.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/analise.php';

const NAVAL_ALERTA_GRAVIDADES = ['critico' => 0, 'aviso' => 1, 'info' => 2];

/** Parâmetros das regras (podem afinar-se no .env sem alterar o código). */
function naval_alertas_parametros(): array
{
    $n = function (string $k, float $omissao) { $v = naval_config($k, ''); return is_numeric($v) ? (float) $v : $omissao; };
    return [
        'semanas' => (int) $n('ALERTAS_SEMANAS', 6),               // histórico do mesmo dia da semana
        'queda_dia' => $n('ALERTAS_QUEDA_DIA', 0.5),               // dia abaixo de 50 % do esperado
        'queda_semana' => $n('ALERTAS_QUEDA_SEMANA', 0.25),        // últimos 7 dias 25 % abaixo da média das 4 semanas anteriores
        'subida_semana' => $n('ALERTAS_SUBIDA_SEMANA', 0.4),       // destaque: 40 % acima
        'cliente_dias' => (int) $n('ALERTAS_CLIENTE_DIAS', 30),     // cliente regular sem compras há 30 dias ou mais
        'agt_dias' => (int) $n('ALERTAS_AGT_DIAS', 2),             // documento por comunicar há mais de 2 dias
        'mdm_dias' => (int) $n('ALERTAS_MDM_DIAS', 2),             // equipamento sem contacto com o MDM há mais de 2 dias
    ];
}

/**
 * Alertas de vendas por rota para o dia $dia (normalmente ontem, o último dia completo) e para os 7 dias até $dia.
 * $agregados: pelo menos as 6 semanas anteriores a $dia (naval_analise_agregados), já com o âmbito de quem pede.
 */
function naval_alertas_rotas(array $agregados, string $dia, array $p, array $promotores = []): array
{
    $out = [];
    $t0 = strtotime($dia . ' 12:00');
    foreach (naval_analise_rotas($agregados) as $rota) {
        $serie = naval_analise_serie($agregados, $rota);
        $nome = $rota . (isset($promotores[$rota]) ? ' · ' . $promotores[$rota] : '');
        $hist = naval_mesmos_dias($serie, $dia, $p['semanas']);
        $comVendas = array_filter($hist, function ($v) { return $v > 0; });
        $real = (float) ($serie[$dia] ?? 0);
        // 1. Dia: rota habitual sem vendas, ou muito abaixo do mesmo dia da semana
        if (count($hist) >= 3 && count($comVendas) >= max(3, (int) ceil(count($hist) * 0.6))) {
            $esperado = naval_mediana($hist);
            $mad = naval_mad($hist);
            if ($real <= 0 && $esperado > 0) {
                $out[] = ['id' => "sem-vendas|$rota|$dia", 'tipo' => 'rota-sem-vendas', 'gravidade' => 'critico', 'rota' => $rota,
                    'titulo' => "Rota $nome sem vendas em " . naval_data_pt($dia),
                    'detalhe' => 'Vendeu em ' . count($comVendas) . ' das últimas ' . count($hist) . ' ' . naval_dia_nome($dia)
                        . ' (mediana ' . naval_kz($esperado) . '). Confirmar se a rota saiu e se o equipamento sincronizou.',
                    'valor' => 0.0, 'esperado' => round($esperado, 2), 'dia' => $dia, 'pagina' => 'vendas-dia',
                    'p' => ['nome' => $nome, 'dia' => $dia, 'com' => count($comVendas), 'n' => count($hist), 'ds' => naval_dia_semana($dia), 'esperado' => round($esperado)]];
            } elseif ($esperado > 0 && $real < $p['queda_dia'] * $esperado && $esperado - $real > max(2.5 * $mad, 0.1 * $esperado)) {
                $out[] = ['id' => "queda-dia|$rota|$dia", 'tipo' => 'queda-dia', 'gravidade' => 'aviso', 'rota' => $rota,
                    'titulo' => "Rota $nome: vendas de " . naval_data_pt($dia) . ' ' . naval_pct($real / $esperado - 1) . ' face ao habitual',
                    'detalhe' => naval_kz($real) . ' contra uma mediana de ' . naval_kz($esperado) . ' nas últimas ' . count($hist) . ' '
                        . naval_dia_nome($dia) . '.',
                    'valor' => round($real, 2), 'esperado' => round($esperado, 2), 'dia' => $dia, 'pagina' => 'vendas-dia',
                    'p' => ['nome' => $nome, 'dia' => $dia, 'var' => round($real / $esperado - 1, 3), 'real' => round($real), 'esperado' => round($esperado), 'n' => count($hist), 'ds' => naval_dia_semana($dia)]];
            }
        }
        // 2. Semana: últimos 7 dias contra a média das 4 semanas anteriores
        $semana = 0.0;
        $anteriores = [0.0, 0.0, 0.0, 0.0];
        $diasAnt = 0;
        for ($k = 0; $k < 35; $k++) {
            $d = date('Y-m-d', $t0 - $k * 86400);
            if (!array_key_exists($d, $serie)) continue;
            if ($k < 7) $semana += $serie[$d];
            else {
                $anteriores[intdiv($k - 7, 7)] += $serie[$d];
                $diasAnt++;
            }
        }
        if ($diasAnt < 21) continue;   // histórico insuficiente (rota nova ou agregados em falta)
        $media = array_sum($anteriores) / 4;
        if ($media <= 0) continue;
        $var = $semana / $media - 1;
        $de = date('Y-m-d', $t0 - 6 * 86400);
        if ($var <= -$p['queda_semana']) {
            $out[] = ['id' => "queda-semana|$rota|$dia", 'tipo' => 'queda-semana', 'gravidade' => $var <= -0.4 ? 'critico' : 'aviso', 'rota' => $rota,
                'titulo' => "Rota $nome: semana " . naval_pct($var) . ' face às 4 anteriores',
                'detalhe' => naval_kz($semana) . ' de ' . naval_data_pt($de) . ' a ' . naval_data_pt($dia) . ', contra uma média semanal de '
                    . naval_kz($media) . '.',
                'valor' => round($semana, 2), 'esperado' => round($media, 2), 'dia' => $dia, 'pagina' => 'vendas-promotor',
                'p' => ['nome' => $nome, 'var' => round($var, 3), 'semana' => round($semana), 'de' => $de, 'dia' => $dia, 'media' => round($media)]];
        } elseif ($var >= $p['subida_semana']) {
            $out[] = ['id' => "subida-semana|$rota|$dia", 'tipo' => 'subida-semana', 'gravidade' => 'info', 'rota' => $rota,
                'titulo' => "Rota $nome: semana " . naval_pct($var) . ' face às 4 anteriores',
                'detalhe' => naval_kz($semana) . ' de ' . naval_data_pt($de) . ' a ' . naval_data_pt($dia) . ', contra uma média semanal de '
                    . naval_kz($media) . '. Boa prática a partilhar com as outras rotas?',
                'valor' => round($semana, 2), 'esperado' => round($media, 2), 'dia' => $dia, 'pagina' => 'vendas-promotor',
                'p' => ['nome' => $nome, 'var' => round($var, 3), 'semana' => round($semana), 'de' => $de, 'dia' => $dia, 'media' => round($media)]];
        }
    }
    return $out;
}

/**
 * Clientes regulares que deixaram de comprar: pelo menos 3 dias de compras no histórico e sem compras há
 * max(cliente_dias, 2 × intervalo habitual). Os 15 de maior valor, por ordem de valor.
 */
function naval_alertas_clientes(array $agregados, string $dia, array $p, int $max = 15): array
{
    $c = [];
    foreach ($agregados as $d => $x) {
        if ($d > $dia) continue;
        foreach (($x['clientes'] ?? []) as $cod => $cl) {
            if ((float) $cl['t'] <= 0) continue;
            if (!isset($c[$cod])) $c[$cod] = ['n' => $cl['n'], 'r' => $cl['r'], 'dias' => [], 't' => 0.0];
            $c[$cod]['dias'][] = $d;
            $c[$cod]['t'] += (float) $cl['t'];
            if ($cl['n'] !== '') $c[$cod]['n'] = $cl['n'];
            $c[$cod]['r'] = $cl['r'];
        }
    }
    $out = [];
    $t0 = strtotime($dia . ' 12:00');
    foreach ($c as $cod => $x) {
        if (count($x['dias']) < 3) continue;
        sort($x['dias']);
        $int = [];
        for ($i = 1; $i < count($x['dias']); $i++) $int[] = (strtotime($x['dias'][$i] . ' 12:00') - strtotime($x['dias'][$i - 1] . ' 12:00')) / 86400;
        $habitual = naval_mediana($int);
        $ultimo = end($x['dias']);
        $sem = (int) round(($t0 - strtotime($ultimo . ' 12:00')) / 86400);
        if ($sem < max($p['cliente_dias'], 2 * $habitual)) continue;
        $nome = $x['n'] !== '' ? $x['n'] : $cod;
        $out[] = ['id' => "cliente|$cod", 'tipo' => 'cliente-parado', 'gravidade' => 'aviso', 'rota' => (string) $x['r'],
            'titulo' => "$nome (rota {$x['r']}) sem compras há $sem dias",
            'detalhe' => 'Comprava em média a cada ' . max(1, (int) round($habitual)) . ' dias (' . count($x['dias']) . ' compras, '
                . naval_kz($x['t']) . ' no período analisado). Última compra: ' . naval_data_pt($ultimo) . '.',
            'valor' => round($x['t'], 2), 'esperado' => null, 'dia' => $ultimo, 'pagina' => 'vendas-cliente', 'cliente' => (string) $cod,
            'p' => ['nome' => $nome, 'rota' => (string) $x['r'], 'sem' => $sem, 'habitual' => max(1, (int) round($habitual)), 'n' => count($x['dias']), 't' => round($x['t']), 'ultimo' => $ultimo]];
    }
    usort($out, function ($a, $b) { return $b['valor'] <=> $a['valor']; });
    return array_slice($out, 0, $max);
}

/** Documentos por comunicar à AGT há mais de agt_dias dias (até $dia). $docs: naval_agt()['documentos']. */
function naval_alertas_agt(array $docs, string $dia, array $p): array
{
    $limite = date('Y-m-d', strtotime($dia . ' 12:00') - $p['agt_dias'] * 86400);
    $porRota = [];
    foreach ($docs as $d) {
        if (($d['estado'] ?? '') !== 'por_comunicar' || !empty($d['anulado'])) continue;
        $data = substr((string) ($d['data'] ?? ''), 0, 10);
        if ($data === '' || $data > $limite) continue;
        $r = (string) ($d['rota'] ?? '');
        if (!isset($porRota[$r])) $porRota[$r] = ['n' => 0, 'mais_antigo' => $data, 't' => 0.0];
        $porRota[$r]['n']++;
        $porRota[$r]['t'] += (float) ($d['total'] ?? 0);
        if ($data < $porRota[$r]['mais_antigo']) $porRota[$r]['mais_antigo'] = $data;
    }
    $out = [];
    foreach ($porRota as $r => $x) {
        $antigo = (int) round((strtotime($dia . ' 12:00') - strtotime($x['mais_antigo'] . ' 12:00')) / 86400);
        $out[] = ['id' => "agt|$r", 'tipo' => 'agt-por-comunicar', 'gravidade' => $antigo > 7 ? 'critico' : 'aviso', 'rota' => $r,
            'titulo' => $x['n'] . ($x['n'] === 1 ? ' documento' : ' documentos') . " da rota $r por comunicar à AGT",
            'detalhe' => 'O mais antigo é de ' . naval_data_pt($x['mais_antigo']) . " ($antigo dias); total " . naval_kz($x['t']) . '.',
            'valor' => (float) $x['n'], 'esperado' => 0.0, 'dia' => $x['mais_antigo'], 'pagina' => 'agt',
            'p' => ['n' => $x['n'], 'rota' => (string) $r, 'antigo' => $antigo, 'data' => $x['mais_antigo'], 't' => round($x['t'])]];
    }
    return $out;
}

/**
 * Equipamentos sem contacto com o MDM há mais de mdm_dias dias. $dispositivos: naval_mdm_dispositivos().
 * A hora do último contacto vem em last_contact_time (milissegundos, segundos ou data); sem esse campo, não há alerta.
 */
function naval_alertas_mdm(array $dispositivos, int $agora, array $p): array
{
    $out = [];
    foreach ($dispositivos as $d) {
        $v = $d['last_contact_time'] ?? ($d['last_contacted_time'] ?? null);
        if ($v === null || $v === '') continue;
        $t = is_numeric($v) ? (int) ((float) $v > 1e11 ? (float) $v / 1000 : (float) $v) : (int) strtotime((string) $v);
        if ($t <= 0) continue;
        $dias = (int) floor(($agora - $t) / 86400);
        if ($dias <= $p['mdm_dias']) continue;
        $nome = (string) ($d['device_name'] ?? ($d['device_id'] ?? ''));
        $rota = preg_match('/^(\d{4})\b/', $nome, $m) ? $m[1] : '';
        $out[] = ['id' => 'mdm|' . ($d['device_id'] ?? $nome), 'tipo' => 'mdm-sem-contacto', 'gravidade' => $dias > 7 ? 'critico' : 'aviso', 'rota' => $rota,
            'titulo' => "Equipamento «{$nome}» sem contacto com o MDM há $dias dias",
            'detalhe' => 'Último contacto: ' . date('d-m-Y H:i', $t) . '. Pode estar desligado, sem rede ou fora de uso.',
            'valor' => (float) $dias, 'esperado' => null, 'dia' => date('Y-m-d', $t), 'pagina' => 'equipamentos',
            'p' => ['nome' => $nome, 'dias' => $dias, 'quando' => date('Y-m-d H:i', $t)]];
    }
    return $out;
}

/** Ordena: crítico, aviso, informação; depois rota e título. */
function naval_alertas_ordenar(array $a): array
{
    usort($a, function ($x, $y) {
        return [NAVAL_ALERTA_GRAVIDADES[$x['gravidade']] ?? 9, $x['rota'], $x['titulo']] <=> [NAVAL_ALERTA_GRAVIDADES[$y['gravidade']] ?? 9, $y['rota'], $y['titulo']];
    });
    return $a;
}

/**
 * Todos os alertas para $dia (omissão: ontem), com o âmbito indicado ($rotas null = todas). $fontes limita as fontes
 * externas (agt, mdm) a quem as pode ver. Falhas de uma fonte não impedem as outras: ficam em «indisponivel».
 */
function naval_alertas(?string $dia = null, ?array $rotas = null, bool $veValores = true, array $fontes = ['vendas', 'clientes', 'agt', 'mdm']): array
{
    $dia = $dia ?: date('Y-m-d', time() - 86400);
    $p = naval_alertas_parametros();
    $out = [];
    $indisponivel = [];
    $de = date('Y-m-d', strtotime($dia . ' 12:00') - NAVAL_ANALISE_HISTORICO * 86400);
    if ($veValores && (in_array('vendas', $fontes, true) || in_array('clientes', $fontes, true))) {
        try {
            $ag = naval_analise_ambito(naval_analise_agregados($de, $dia), $rotas, true);
            if (in_array('vendas', $fontes, true)) $out = array_merge($out, naval_alertas_rotas($ag, $dia, $p, naval_promotores()));
            if (in_array('clientes', $fontes, true)) $out = array_merge($out, naval_alertas_clientes($ag, $dia, $p));
        } catch (Throwable $e) {
            error_log('[alertas vendas] ' . $e->getMessage());
            $indisponivel[] = 'vendas';
        }
    }
    if (in_array('agt', $fontes, true)) {
        try {
            naval_servico('bd');
            naval_servico('agt');
            if (naval_bd_configurada()) {
                $docs = naval_agt(date('Ymd', strtotime($dia . ' 12:00') - 30 * 86400), str_replace('-', '', $dia))['documentos'];
                if ($rotas !== null) $docs = array_filter($docs, function ($d) use ($rotas) { return in_array((string) $d['rota'], $rotas, true); });
                $out = array_merge($out, naval_alertas_agt($docs, $dia, $p));
            }
        } catch (Throwable $e) {
            error_log('[alertas AGT] ' . $e->getMessage());
            $indisponivel[] = 'agt';
        }
    }
    if (in_array('mdm', $fontes, true)) {
        try {
            naval_servico('mdm');
            if (naval_mdm_configurada()) {
                $m = naval_alertas_mdm(naval_mdm_dispositivos(['platform' => 2]), time(), $p);
                if ($rotas !== null) $m = array_filter($m, function ($a) use ($rotas) { return in_array($a['rota'], $rotas, true); });
                $out = array_merge($out, $m);
            }
        } catch (Throwable $e) {
            error_log('[alertas MDM] ' . $e->getMessage());
            $indisponivel[] = 'mdm';
        }
    }
    return ['dia' => $dia, 'alertas' => naval_alertas_ordenar(array_values($out)), 'indisponivel' => $indisponivel, 'gerado' => date('Y-m-d H:i:s')];
}

/** Fontes externas dos alertas que a pessoa pode ver (AGT e MDM só com acesso às páginas respectivas). */
function naval_alertas_fontes(): array
{
    $f = ['vendas', 'clientes'];
    if (naval_pode('agt')) $f[] = 'agt';
    if (naval_pode('equipamentos')) $f[] = 'mdm';
    return $f;
}

/** Nome do dia da semana no plural («segundas-feiras»), para os textos dos alertas. */
function naval_dia_nome(string $dia): string
{
    return ['', 'segundas-feiras', 'terças-feiras', 'quartas-feiras', 'quintas-feiras', 'sextas-feiras', 'sábados', 'domingos'][naval_dia_semana($dia)];
}

/** Variação em percentagem com sinal (−35 %, +12 %). */
function naval_pct(float $v): string
{
    $p = (int) round($v * 100);
    return ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . ' %';
}
