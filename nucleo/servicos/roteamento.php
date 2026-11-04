<?php
/**
 * Optimização automática das rotas (sem intervenção humana). Todas as noites, depois do cálculo da carteira:
 *   1. as visitas de cada rota para hoje e os 6 dias seguintes vêm do mesmo plano das «Visitas do dia» (carteira:
 *      dia habitual de compra e compras em atraso, do Pinguim BO);
 *   2. as coordenadas vêm dos clientes do Pinguim BO (as mesmas da GeoMobilidade);
 *   3. o ponto de partida de cada rota é o configurado (ROTAS_PARTIDA_<rota> ou ROTAS_PARTIDA) ou, sem configuração,
 *      o ponto onde a carrinha começa o dia nas localizações do servidor OLAP (mediana das últimas saídas);
 *   4. a sequência resolve o problema do caixeiro-viajante com heurísticas de referência (vizinho mais próximo, 2-opt
 *      e Or-opt), sobre distâncias geodésicas corrigidas pela sinuosidade das estradas (ROTAS_FACTOR_ESTRADA);
 *   5. a hora prevista de chegada a cada cliente soma o trajecto (ROTAS_VELOCIDADE_KMH) e o tempo no cliente
 *      (ROTAS_TEMPO_CLIENTE_MIN) a partir da hora de saída (ROTAS_HORA_INICIO); assinala-se a rota que passa a jornada
 *      (ROTAS_JORNADA_H).
 * O resultado fica em dados-privados/roteamento/AAAA-MM-DD.json: o mapa «Optimização de rotas» e as «Visitas do dia»
 * usam-no (a ficha de visitas sai já pela ordem do percurso).
 */

const NAVAL_ROT_DIAS = 7;   // hoje e os 6 dias seguintes

function naval_rot_pasta(): string
{
    return naval_pasta('roteamento');
}

/** Ponto "lat,lng" do .env (ou null). */
function naval_rot_ponto(string $v): ?array
{
    if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,;]\s*(-?\d+(?:\.\d+)?)\s*$/', $v, $m)) return null;
    return naval_geo_valida($m[1], $m[2]) ? ['lat' => (float) $m[1], 'lng' => (float) $m[2]] : null;
}

function naval_rot_cfg(): array
{
    $num = function (string $k, float $omissao, float $min, float $max): float {
        $v = naval_config($k, '');
        return is_numeric($v) ? max($min, min($max, (float) $v)) : $omissao;
    };
    $hora = naval_config('ROTAS_HORA_INICIO', '08:00');
    return [
        'velocidade' => $num('ROTAS_VELOCIDADE_KMH', 20.0, 5.0, 80.0),
        'servico' => $num('ROTAS_TEMPO_CLIENTE_MIN', 12.0, 1.0, 120.0),
        'factor' => $num('ROTAS_FACTOR_ESTRADA', 1.35, 1.0, 3.0),
        'jornada' => $num('ROTAS_JORNADA_H', 9.0, 1.0, 16.0),
        'inicio' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora) ? $hora : '08:00',
        'regressa' => naval_config('ROTAS_REGRESSO', '1') !== '0',   // a carrinha volta ao ponto de partida
    ];
}

/** Distância por estrada estimada, em km. */
function naval_rot_km(array $a, array $b, float $factor): float
{
    return naval_geo_dist((float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng']) / 1000 * $factor;
}

/** Comprimento de uma sequência (índices de $pts), com partida e regresso opcionais. */
function naval_rot_comprimento(array $ordem, array $pts, ?array $partida, bool $regressa, float $f): float
{
    $km = 0.0;
    $ant = $partida;
    foreach ($ordem as $i) {
        if ($ant !== null) $km += naval_rot_km($ant, $pts[$i], $f);
        $ant = $pts[$i];
    }
    if ($partida !== null && $regressa && $ant !== null && $ordem) $km += naval_rot_km($ant, $partida, $f);
    return $km;
}

/**
 * Melhor ordem de visita (índices de $pts). Com partida: percurso a partir dela (e regresso, se $regressa). Sem
 * partida: caminho aberto, tentando cada ponto como início (a carrinha começa onde for melhor).
 */
function naval_rot_sequencia(array $pts, ?array $partida, bool $regressa = true, float $f = 1.35): array
{
    $n = count($pts);
    if ($n <= 1) return array_keys($pts);
    $d = [];
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) $d[$i][$j] = $d[$j][$i] = naval_rot_km($pts[$i], $pts[$j], $f);
    $dp = [];
    if ($partida !== null) for ($i = 0; $i < $n; $i++) $dp[$i] = naval_rot_km($partida, $pts[$i], $f);
    $custo = function (array $o) use ($d, $dp, $partida, $regressa): float {
        $k = $partida !== null ? $dp[$o[0]] : 0.0;
        for ($i = 1, $m = count($o); $i < $m; $i++) $k += $d[$o[$i - 1]][$o[$i]];
        if ($partida !== null && $regressa) $k += $dp[$o[count($o) - 1]];
        return $k;
    };
    $vizinho = function (int $ini) use ($d, $n): array {
        $livre = array_fill(0, $n, true);
        $o = [$ini];
        $livre[$ini] = false;
        for ($k = 1; $k < $n; $k++) {
            $act = $o[$k - 1];
            $melhor = -1;
            foreach ($livre as $j => $l) if ($l && ($melhor < 0 || $d[$act][$j] < $d[$act][$melhor])) $melhor = $j;
            $o[] = $melhor;
            $livre[$melhor] = false;
        }
        return $o;
    };
    $melhorar = function (array $o) use ($custo, $n): array {
        $actual = $custo($o);
        for ($volta = 0, $mudou = true; $mudou && $volta < 50; $volta++) {
            $mudou = false;
            // 2-opt: inverter troços
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $t = $o;
                    array_splice($t, $i, $j - $i + 1, array_reverse(array_slice($o, $i, $j - $i + 1)));
                    $k = $custo($t);
                    if ($k < $actual - 1e-9) {
                        [$o, $actual, $mudou] = [$t, $k, true];
                    }
                }
            }
            // Or-opt: mudar troços de 1 a 3 clientes para outra posição
            for ($len = 1; $len <= 3; $len++) {
                for ($i = 0; $i + $len <= $n; $i++) {
                    $seg = array_slice($o, $i, $len);
                    $resto = $o;
                    array_splice($resto, $i, $len);
                    for ($p = 0; $p <= count($resto); $p++) {
                        if ($p === $i) continue;
                        $t = $resto;
                        array_splice($t, $p, 0, $seg);
                        $k = $custo($t);
                        if ($k < $actual - 1e-9) {
                            [$o, $actual, $mudou] = [$t, $k, true];
                            continue 3;
                        }
                    }
                }
            }
        }
        return $o;
    };
    if ($partida !== null) {
        $ini = array_keys($dp, min($dp))[0];
        return $melhorar($vizinho((int) $ini));
    }
    $melhor = null;
    $melhorK = INF;
    $passo = $n > 12 ? (int) ceil($n / 8) : 1;   // rotas grandes: 8 pontos de partida espalhados chegam
    foreach (range(0, $n - 1) as $ini) {
        if ($ini % $passo !== 0) continue;
        $o = $melhorar($vizinho($ini));
        $k = $custo($o);
        if ($k < $melhorK) [$melhor, $melhorK] = [$o, $k];
    }
    return $melhor;
}

/** Ponto de saída de cada rota pelas localizações do OLAP guardadas: mediana do primeiro ponto do dia (até 14 dias). */
function naval_rot_partidas_olap(int $agora): array
{
    $primeiro = [];
    for ($k = 1; $k <= 14; $k++) {
        $f = naval_pasta('olap') . '/' . date('Y-m-d', $agora - $k * 86400) . '.json';
        if (!is_file($f)) continue;
        naval_servico('olap');
        foreach (naval_olap_normalizar((array) (naval_json_ler($f)['linhas'] ?? [])) as $p) {
            if (!isset($primeiro[$p['r']][$k])) $primeiro[$p['r']][$k] = $p;
        }
    }
    $out = [];
    foreach ($primeiro as $r => $dias) {
        if (count($dias) < 3) continue;
        $out[(string) $r] = ['lat' => round(naval_mediana(array_column($dias, 'lat')), 6), 'lng' => round(naval_mediana(array_column($dias, 'lng')), 6), 'origem' => 'olap'];
    }
    return $out;
}

/** Ponto de partida de uma rota: configurado para a rota, estimado pelo OLAP, configurado para todas, ou nenhum. */
function naval_rot_partida(string $rota, array $olap): ?array
{
    $p = naval_rot_ponto(naval_config('ROTAS_PARTIDA_' . $rota, ''));
    if ($p) return $p + ['origem' => 'configurada'];
    if (isset($olap[$rota])) return $olap[$rota];
    $p = naval_rot_ponto(naval_config('ROTAS_PARTIDA', ''));
    return $p ? $p + ['origem' => 'configurada'] : null;
}

/** Hora HH:MM somada de minutos. */
function naval_rot_hora(string $inicio, float $min): string
{
    [$h, $m] = array_map('intval', explode(':', $inicio));
    $t = $h * 60 + $m + (int) round($min);
    return sprintf('%02d:%02d', intdiv($t, 60) % 24, $t % 60);
}

/**
 * Percurso optimizado de uma rota num dia: $visitas (fichas das Visitas do dia: c, n, estado, media…) e $coord
 * ([código => [lat, lng]]). Devolve {paragens:[{ordem, c, n, lat, lng, km, chegada, estado}], sem_coordenadas, km,
 * minutos, saida, regresso, acima_jornada, partida}.
 */
function naval_rot_percurso(array $visitas, array $coord, ?array $partida, array $cfg): array
{
    $pts = [];
    $fichas = [];
    $sem = [];
    foreach ($visitas as $v) {
        $c = (string) $v['c'];
        if (!isset($coord[$c])) {
            $sem[] = ['c' => $c, 'n' => (string) ($v['n'] ?? '')];
            continue;
        }
        $pts[] = $coord[$c];
        $fichas[] = $v;
    }
    $ordem = naval_rot_sequencia($pts, $partida, $cfg['regressa'], $cfg['factor']);
    $paragens = [];
    $km = 0.0;
    $min = 0.0;
    $ant = $partida;
    foreach ($ordem as $k => $i) {
        $troco = $ant !== null ? naval_rot_km($ant, $pts[$i], $cfg['factor']) : 0.0;
        $km += $troco;
        $min += $troco / $cfg['velocidade'] * 60;
        $paragens[] = ['ordem' => $k + 1, 'c' => (string) $fichas[$i]['c'], 'n' => (string) ($fichas[$i]['n'] ?? ''), 'lat' => $pts[$i]['lat'], 'lng' => $pts[$i]['lng'],
            'km' => round($km, 1), 'chegada' => naval_rot_hora($cfg['inicio'], $min), 'estado' => (string) ($fichas[$i]['estado'] ?? '')];
        $min += $cfg['servico'];
        $ant = $pts[$i];
    }
    if ($partida !== null && $cfg['regressa'] && $ant !== null && $paragens) {
        $troco = naval_rot_km($ant, $partida, $cfg['factor']);
        $km += $troco;
        $min += $troco / $cfg['velocidade'] * 60;
    }
    return ['paragens' => $paragens, 'sem_coordenadas' => $sem, 'km' => round($km, 1), 'minutos' => (int) round($min),
        'saida' => $cfg['inicio'], 'regresso' => naval_rot_hora($cfg['inicio'], $min), 'acima_jornada' => $min > $cfg['jornada'] * 60,
        'partida' => $partida];
}

/** Plano de todas as rotas para os dias pedidos (AAAA-MM-DD), a partir da carteira e das coordenadas. */
function naval_rot_planear(array $carteira, array $coord, array $dias, array $partidasOlap, array $cfg): array
{
    naval_servico('carteira');
    $out = [];
    foreach ($dias as $dia) {
        if (naval_dia_semana($dia) === 7) continue;   // domingo sem visitas
        foreach (naval_cart_visitas($carteira, $dia, null) as $rota => $visitas) {
            $out[$dia][(string) $rota] = naval_rot_percurso($visitas, $coord, naval_rot_partida((string) $rota, $partidasOlap), $cfg);
        }
    }
    return $out;
}

/** Tarefa da noite (depois do cálculo da carteira): uma vez por dia. Devolve o que fez. */
function naval_rot_noite(int $agora): array
{
    $hoje = date('Y-m-d', $agora);
    $f = naval_rot_pasta() . '/' . $hoje . '.json';
    if (date('H', $agora) < '04' || is_file($f)) return [];
    naval_servico('carteira');
    naval_servico('prospeccao');
    naval_servico('analise');
    $cart = naval_cart_ler();
    if (!$cart || substr((string) ($cart['gerado'] ?? ''), 0, 10) !== $hoje) return [];   // espera pela carteira desta noite
    $ag = naval_analise_agregados(date('Y-m-d', $agora - 56 * 86400), date('Y-m-d', $agora - 86400));
    $coord = [];
    foreach ((array) (naval_geo_clientes($ag)['clientes'] ?? []) as $c) $coord[(string) $c['c']] = ['lat' => (float) $c['lat'], 'lng' => (float) $c['lng']];
    $dias = [];
    for ($k = 0; $k < NAVAL_ROT_DIAS; $k++) $dias[] = date('Y-m-d', strtotime($hoje . ' 12:00') + $k * 86400);
    $cfg = naval_rot_cfg();
    $plano = naval_rot_planear($cart, $coord, $dias, naval_rot_partidas_olap($agora), $cfg);
    naval_json_gravar($f, ['gerado' => date('Y-m-d H:i:s', $agora), 'cfg' => $cfg, 'dias' => $plano], false);
    foreach (glob(naval_rot_pasta() . '/????-??-??.json') ?: [] as $x) if (basename($x) < date('Y-m-d', $agora - 15 * 86400) . '.json') @unlink($x);
    $n = 0;
    foreach ($plano as $rs) $n += count($rs);
    return ['percursos' => $n];
}

/** Plano mais recente (até 15 dias). */
function naval_rot_ler(): array
{
    $fs = glob(naval_rot_pasta() . '/????-??-??.json') ?: [];
    rsort($fs);
    return $fs ? naval_json_ler($fs[0]) : [];
}

/** Vista no âmbito de quem pede: dias disponíveis e percursos das rotas permitidas num dia. */
function naval_rot_vista(?array $rotas, string $dia): array
{
    $p = naval_rot_ler();
    $dias = array_keys((array) ($p['dias'] ?? []));
    if (!in_array($dia, $dias, true)) $dia = $dias[0] ?? $dia;
    $percursos = (array) ($p['dias'][$dia] ?? []);
    if ($rotas !== null) $percursos = array_intersect_key($percursos, array_flip($rotas));
    ksort($percursos);
    return ['gerado' => $p['gerado'] ?? null, 'dias' => $dias, 'dia' => $dia, 'cfg' => $p['cfg'] ?? naval_rot_cfg(), 'percursos' => (object) $percursos];
}

/** Ordem de visita (código → ordem) de cada rota num dia, para as Visitas do dia saírem pela ordem do percurso. */
function naval_rot_ordem(string $dia): array
{
    $p = naval_rot_ler();
    $out = [];
    foreach ((array) ($p['dias'][$dia] ?? []) as $r => $x) foreach ((array) ($x['paragens'] ?? []) as $s) $out[(string) $r][(string) $s['c']] = (int) $s['ordem'];
    return $out;
}
