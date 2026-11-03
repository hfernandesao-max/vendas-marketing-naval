<?php
/**
 * Ligação ao servidor OLAP (localização registada pela aplicação de vendas e pelo registo de clientes), só de
 * leitura, preparada antes de o servidor existir: no dia em que estiver pronto, basta preencher o .env.
 *
 * .env: OLAP_HOST, OLAP_PORT, OLAP_NAME, OLAP_USER, OLAP_PASS (ou OLAP_DSN), como as outras bases de dados
 * (naval_bd_criar). A consulta vem de ferramentas/sql/olap-localizacao.sql (só SELECT, com :de e :ate) ou, sem
 * ficheiro, da vista indicada em OLAP_VISTA. Cada linha tem de trazer as colunas do contrato:
 *
 *   DATA_HORA (AAAA-MM-DD HH:MM:SS) · ROTA · LATITUDE · LONGITUDE      obrigatórias
 *   DOCUMENTO · CLIENTE · PRECISAO (metros) · TIPO (venda | cliente | ponto)   facultativas
 *
 * O portal normaliza, valida (coordenadas dentro de Angola, precisão até OLAP_PRECISAO_MAX metros) e guarda uma
 * cópia diária em dados-privados/olap para os ecrãs funcionarem mesmo com o OLAP indisponível.
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/analise.php';

const NAVAL_OLAP_COLUNAS = ['DATA_HORA', 'ROTA', 'LATITUDE', 'LONGITUDE'];

function naval_olap_configurado(): bool
{
    return naval_config('OLAP_DSN') !== '' || naval_config('OLAP_HOST') !== '';
}

/** Consulta de localização (ficheiro SQL próprio ou vista); [sql, origem] ou [null, motivo]. */
function naval_olap_consulta(): array
{
    $f = naval_raiz() . '/ferramentas/sql/olap-localizacao.sql';
    if (is_file($f)) {
        $sql = trim((string) file_get_contents($f));
        try {
            naval_bd_so_leitura($sql);
        } catch (Throwable $e) {
            return [null, 'ferramentas/sql/olap-localizacao.sql tem de ser só uma consulta SELECT.'];
        }
        return [$sql, 'ferramentas/sql/olap-localizacao.sql'];
    }
    $vista = naval_config('OLAP_VISTA', 'dbo.vw_localizacao');
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $vista)) return [null, 'OLAP_VISTA inválida.'];
    return ["SELECT * FROM $vista WHERE DATA_HORA >= :de AND DATA_HORA < :ate", 'vista ' . $vista];
}

/**
 * Normaliza as linhas do OLAP para o formato do portal: [{t: AAAA-MM-DD HH:MM:SS, r, lat, lng, p, doc, cli, tipo}].
 * Linhas sem as colunas obrigatórias, fora de Angola ou com precisão pior do que o limite ficam de fora.
 */
function naval_olap_normalizar(array $linhas, ?array $rotas = null): array
{
    $max = (float) naval_config('OLAP_PRECISAO_MAX', '100');
    $out = [];
    foreach ($linhas as $l) {
        $l = array_change_key_case($l, CASE_UPPER);
        foreach (NAVAL_OLAP_COLUNAS as $c) if (!isset($l[$c]) || $l[$c] === '') continue 2;
        $la = (float) $l['LATITUDE'];
        $lo = (float) $l['LONGITUDE'];
        if ($la < -18.1 || $la > -4.3 || $lo < 11.6 || $lo > 24.1) continue;
        $p = isset($l['PRECISAO']) && $l['PRECISAO'] !== '' ? (float) $l['PRECISAO'] : null;
        if ($p !== null && $p > $max) continue;
        $t = date('Y-m-d H:i:s', (int) strtotime((string) $l['DATA_HORA']));
        $r = trim((string) $l['ROTA']);
        if ($rotas !== null && !in_array($r, $rotas, true)) continue;
        $tipo = strtolower(trim((string) ($l['TIPO'] ?? '')));
        $out[] = ['t' => $t, 'r' => $r, 'lat' => round($la, 6), 'lng' => round($lo, 6), 'p' => $p, 'doc' => trim((string) ($l['DOCUMENTO'] ?? '')),
            'cli' => trim((string) ($l['CLIENTE'] ?? '')), 'tipo' => in_array($tipo, ['venda', 'cliente', 'ponto'], true) ? $tipo : (($l['DOCUMENTO'] ?? '') !== '' ? 'venda' : 'ponto')];
    }
    usort($out, function ($a, $b) { return strcmp($a['r'], $b['r']) ?: strcmp($a['t'], $b['t']); });
    return $out;
}

/**
 * Localizações de um dia (AAAA-MM-DD). Lê o OLAP (cópia do dia guardada) e, sem ligação, a última cópia desse dia.
 * Devolve ['origem', 'erro', 'pontos'].
 */
function naval_olap_dia(string $dia, ?array $rotas = null, bool $actualizar = false): array
{
    $f = naval_pasta('olap') . '/' . $dia . '.json';
    $c = naval_json_ler($f);
    $fresco = $c && ($dia < date('Y-m-d') || filemtime($f) >= time() - 600);
    if (!$fresco || $actualizar) {
        if (!naval_olap_configurado()) return ['origem' => '', 'erro' => $c ? null : 'O servidor OLAP ainda não está configurado (OLAP_* no .env).', 'pontos' => naval_olap_normalizar((array) ($c['linhas'] ?? []), $rotas)];
        [$sql, $origem] = naval_olap_consulta();
        if ($sql === null) return ['origem' => '', 'erro' => $origem, 'pontos' => []];
        try {
            $pdo = naval_bd_criar('OLAP');
            $st = $pdo->prepare($sql);
            $st->execute([':de' => $dia . ' 00:00:00', ':ate' => date('Y-m-d', strtotime($dia . ' 12:00') + 86400) . ' 00:00:00']);
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
            $c = ['origem' => $origem, 'em' => date('Y-m-d H:i:s'), 'linhas' => $linhas];
            naval_json_gravar($f, $c, false);
        } catch (Throwable $e) {
            error_log('[OLAP] ' . $e->getMessage());
            if (!$c) return ['origem' => $origem, 'erro' => 'O servidor OLAP não respondeu.', 'pontos' => []];
        }
    }
    return ['origem' => (string) ($c['origem'] ?? ''), 'erro' => null, 'pontos' => naval_olap_normalizar((array) ($c['linhas'] ?? []), $rotas)];
}

/** Estado da ligação (página de estado): configurado, a responder, origem e pontos de ontem com o contrato cumprido. */
function naval_olap_estado(): array
{
    if (!naval_olap_configurado()) return ['configurado' => false, 'ok' => false, 'mensagem' => 'Por configurar: preencher OLAP_* no .env do servidor.'];
    $ontem = date('Y-m-d', time() - 86400);
    $d = naval_olap_dia($ontem, null, true);
    return ['configurado' => true, 'ok' => $d['erro'] === null, 'origem' => $d['origem'], 'pontos_ontem' => count($d['pontos']),
        'mensagem' => $d['erro'] ?? (count($d['pontos']) . ' pontos de localização válidos ontem.')];
}

// ── Terreno do dia: percurso, paragens, prova de visita, tempos, saídas de zona e coordenadas sugeridas ──────────

/** Distância em metros entre dois pontos (fórmula de haversine). */
function naval_olap_dist(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = 6371000.0;
    $dla = deg2rad($la2 - $la1);
    $dlo = deg2rad($lo2 - $lo1);
    $a = sin($dla / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dlo / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** Ponto dentro de um polígono [[lat, lng], …] (raio cruzado). */
function naval_olap_dentro(float $la, float $lo, array $pol): bool
{
    $n = count($pol);
    if ($n < 3) return true;
    $dentro = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$ya, $xa] = $pol[$i];
        [$yb, $xb] = $pol[$j];
        if ((($ya > $la) !== ($yb > $la)) && ($lo < ($xb - $xa) * ($la - $ya) / (($yb - $ya) ?: 1e-12) + $xa)) $dentro = !$dentro;
    }
    return $dentro;
}

/** Paragens: pontos seguidos a menos de 60 m uns dos outros; [{lat, lng, de, ate, min, pontos}]. */
function naval_olap_paragens(array $pts, float $raio = 60.0): array
{
    $out = [];
    $cur = null;
    foreach ($pts as $p) {
        if ($cur !== null && naval_olap_dist($cur['lat'], $cur['lng'], $p['lat'], $p['lng']) <= $raio) {
            $n = $cur['pontos'];
            $cur['lat'] = ($cur['lat'] * $n + $p['lat']) / ($n + 1);
            $cur['lng'] = ($cur['lng'] * $n + $p['lng']) / ($n + 1);
            $cur['ate'] = $p['t'];
            $cur['pontos']++;
            continue;
        }
        if ($cur !== null) $out[] = $cur;
        $cur = ['lat' => $p['lat'], 'lng' => $p['lng'], 'de' => $p['t'], 'ate' => $p['t'], 'pontos' => 1];
    }
    if ($cur !== null) $out[] = $cur;
    foreach ($out as &$s) {
        $s['min'] = (int) round((strtotime($s['ate']) - strtotime($s['de'])) / 60);
        $s['lat'] = round($s['lat'], 6);
        $s['lng'] = round($s['lng'], 6);
    }
    unset($s);
    return $out;
}

/**
 * Terreno de um dia por rota. $pontos: naval_olap_normalizar; $clientes: [{c, n, lat, lng, r}]; $territorios:
 * {rota: polígono}. Prova de visita: venda a mais de OLAP_RAIO_VISITA (150 m) das coordenadas do cliente fica
 * assinalada. Coordenadas sugeridas: cliente com 3 ou mais vendas no mesmo sítio, a mais de 50 m do registo.
 */
function naval_olap_terreno(array $pontos, array $clientes, array $territorios, int $objectivo = 30): array
{
    $raio = (float) naval_config('OLAP_RAIO_VISITA', '150');
    $pos = [];
    foreach ($clientes as $c) $pos[(string) $c['c']] = $c;
    $porRota = [];
    foreach ($pontos as $p) $porRota[$p['r']][] = $p;
    $out = [];
    $vendasCli = [];
    foreach ($porRota as $r => $pts) {
        $vendas = array_values(array_filter($pts, function ($p) { return $p['tipo'] === 'venda'; }));
        $visitas = [];
        foreach ($vendas as $v) {
            $c = $pos[$v['cli']] ?? null;
            $d = $c ? naval_olap_dist($v['lat'], $v['lng'], (float) $c['lat'], (float) $c['lng']) : null;
            $visitas[] = ['t' => $v['t'], 'doc' => $v['doc'], 'cli' => $v['cli'], 'n' => $c['n'] ?? $v['cli'], 'lat' => $v['lat'], 'lng' => $v['lng'],
                'dist' => $d === null ? null : (int) round($d), 'prova' => $d === null ? 'sem_coordenadas' : ($d <= $raio ? 'ok' : 'longe')];
            if ($v['cli'] !== '') $vendasCli[$v['cli']][] = $v;
        }
        $par = naval_olap_paragens($pts);
        $emCliente = 0;
        foreach ($par as &$s) {
            $s['cliente'] = null;
            foreach ($visitas as $vi) if (naval_olap_dist($s['lat'], $s['lng'], $vi['lat'], $vi['lng']) <= $raio && $vi['t'] >= $s['de'] && $vi['t'] <= $s['ate']) { $s['cliente'] = $vi['n']; break; }
            if ($s['cliente'] !== null) $emCliente += $s['min'];
        }
        unset($s);
        $ini = $pts[0]['t'];
        $fim = end($pts)['t'];
        $total = (int) round((strtotime($fim) - strtotime($ini)) / 60);
        $parado = array_sum(array_column($par, 'min'));
        $fora = 0;
        $pol = $territorios[$r] ?? [];
        foreach ($pts as $p) if ($pol && !naval_olap_dentro($p['lat'], $p['lng'], $pol)) $fora++;
        $km = 0.0;
        for ($i = 1; $i < count($pts); $i++) $km += naval_olap_dist($pts[$i - 1]['lat'], $pts[$i - 1]['lng'], $pts[$i]['lat'], $pts[$i]['lng']) / 1000;
        $clientesVis = count(array_unique(array_filter(array_column($visitas, 'cli'))));
        $out[$r] = ['rota' => (string) $r, 'inicio' => substr($ini, 11, 5), 'fim' => substr($fim, 11, 5), 'min_total' => $total, 'min_cliente' => $emCliente,
            'min_deslocacao' => max(0, $total - $parado), 'min_parado_sem_venda' => max(0, $parado - $emCliente), 'km' => round($km, 1),
            'clientes' => $clientesVis, 'objectivo' => $objectivo, 'vendas_longe' => count(array_filter($visitas, function ($v) { return $v['prova'] === 'longe'; })),
            'pontos_fora' => $fora, 'fora_pct' => round($fora / max(1, count($pts)), 3),
            'percurso' => array_map(function ($p) { return [$p['lat'], $p['lng'], substr($p['t'], 11, 5)]; }, $pts), 'paragens' => $par, 'visitas' => $visitas];
    }
    ksort($out);
    // coordenadas sugeridas pelas vendas (mediana das posições, 3 ou mais vendas)
    $sug = [];
    foreach ($vendasCli as $cli => $vs) {
        if (count($vs) < 3) continue;
        $la = naval_mediana(array_column($vs, 'lat'));
        $lo = naval_mediana(array_column($vs, 'lng'));
        $c = $pos[$cli] ?? null;
        $d = $c ? naval_olap_dist($la, $lo, (float) $c['lat'], (float) $c['lng']) : null;
        if ($d !== null && $d <= 50) continue;
        $sug[] = ['c' => (string) $cli, 'n' => $c['n'] ?? (string) $cli, 'r' => $c['r'] ?? $vs[0]['r'], 'lat' => round($la, 6), 'lng' => round($lo, 6), 'vendas' => count($vs),
            'actual' => $c ? [(float) $c['lat'], (float) $c['lng']] : null, 'dist' => $d === null ? null : (int) round($d)];
    }
    return ['rotas' => array_values($out), 'coordenadas' => $sug];
}
