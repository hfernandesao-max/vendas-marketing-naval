<?php
/**
 * Prospecção e territórios das rotas (GeoMobilidade): diz a cada vendedor onde ir procurar clientes novos.
 *
 * Fontes:
 *   - clientes e coordenadas: Pinguim BO (só SELECT), onde os promotores já registam os clientes com GPS e fotografia;
 *     rota de cada cliente e dia habitual de visita: vendas dos últimos 56 dias (agregados de análise);
 *   - estabelecimentos comerciais conhecidos: OpenStreetMap (Overpass, gratuito, uma vez por semana) e Google Places
 *     (API «New», com limite mensal de pedidos). Pelas regras do Google, de cada lugar só o identificador pode ficar
 *     guardado; as coordenadas apagam-se ao fim de 30 dias (GEO_GOOGLE_DIAS) se o lugar não voltar a ser visto.
 * Cada estabelecimento que não está a menos de 30 m de um cliente (ou a 80 m com nome parecido) é um potencial
 * cliente. Entra no território da rota dos clientes mais próximos (até 1,5 km) e no dia em que essa rota visita a
 * zona; fora dos territórios fica como «zona sem rota» (espaço para uma rota nova). Quando um cliente novo aparece
 * no Pinguim BO no sítio de um potencial cliente, este conta como convertido.
 *
 * Território de cada rota: polígono à volta dos seus clientes (invólucro convexo com margem), que cresce sozinho à
 * medida que os clientes aumentam. Capacidade: visitas por semana (cada cliente 1 a 3 visitas, pelo ritmo de compras)
 * face a GEO_VISITAS_DIA × GEO_DIAS_SEMANA (30 × 6). Acima de GEO_LIMIAR_ROTA (90 %) durante 14 dias seguidos, avisa por
 * correio que é preciso uma rota nova (vendedor + viatura), com a proposta de divisão do território.
 *
 * Ficheiros (dados-privados/geo/): clientes-AAAA-MM-DD.json, osm.json, google.json, estados.json (o que os vendedores
 * disseram de cada potencial cliente), ocupacao.json (histórico) e vista.json (resultado do último cálculo).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';
require_once __DIR__ . '/analise.php';
require_once __DIR__ . '/email.php';
require_once dirname(__DIR__) . '/cliente-http.php';

const NAVAL_GEO_TIPOS_OSM = 'convenience|supermarket|kiosk|general|variety_store|beverages|chemist|cosmetics|wholesale|grocery|greengrocer|butcher|bakery|alcohol|deli|frozen_food|houseware|hardware';
const NAVAL_GEO_AMENIDADES_OSM = 'bar|cafe|restaurant|fast_food|pharmacy|marketplace';
const NAVAL_GEO_TIPOS_GOOGLE = ['convenience_store', 'grocery_store', 'supermarket', 'market', 'liquor_store', 'pharmacy', 'drugstore'];
// peso do tipo de estabelecimento na prioridade (o que mais vende higiene e fraldas porta-a-porta)
const NAVAL_GEO_PESO_TIPO = [
    'supermarket' => 30, 'grocery_store' => 30, 'convenience' => 30, 'convenience_store' => 30, 'general' => 28, 'grocery' => 28, 'variety_store' => 26,
    'wholesale' => 26, 'market' => 25, 'marketplace' => 25, 'kiosk' => 22, 'chemist' => 22, 'cosmetics' => 20, 'pharmacy' => 18, 'drugstore' => 18,
    'beverages' => 14, 'liquor_store' => 14, 'alcohol' => 14, 'bakery' => 12, 'deli' => 12, 'houseware' => 12, 'hardware' => 8, 'greengrocer' => 10,
    'butcher' => 8, 'frozen_food' => 10, 'bar' => 8, 'cafe' => 8, 'restaurant' => 6, 'fast_food' => 6,
];
const NAVAL_GEO_ESTADOS = ['visitado' => 'Visitado, ainda a decidir', 'interessado' => 'Interessado: a preparar a primeira compra', 'nao_interessado' => 'Não interessado', 'nao_existe' => 'Não existe / fechou', 'outro_fornecedor' => 'Compra a outro fornecedor'];

/** Primeira chave de um array já ordenado (array_key_first, compatível com o PHP 7.1); '' se vazio. */
function naval_geo_chave_maior(array $a)
{
    reset($a);
    $k = key($a);
    return $k === null ? '' : $k;
}

function naval_geo_pasta(): string
{
    return naval_pasta('geo');
}

function naval_geo_num(string $chave, float $omissao): float
{
    $v = naval_config($chave, '');
    return is_numeric($v) ? (float) $v : $omissao;
}

// ── Geometria (metros, em torno de Luanda chega a aproximação equiretangular nas distâncias curtas) ─────────────

function naval_geo_dist(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = 6371000.0;
    $p1 = deg2rad($la1);
    $p2 = deg2rad($la2);
    $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lo2 - $lo1) / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** Coordenadas plausíveis (Angola: latitude −18,1 a −4,3; longitude 11,6 a 24,1), sem o ponto (0, 0). */
function naval_geo_valida($la, $lo): bool
{
    if (!is_numeric($la) || !is_numeric($lo)) return false;
    $la = (float) $la;
    $lo = (float) $lo;
    return $la >= -18.1 && $la <= -4.3 && $lo >= 11.6 && $lo <= 24.1;
}

/** Índice em grelha (células de ~1,1 km) para procurar vizinhos depressa. */
function naval_geo_indice(array $pontos): array
{
    $g = [];
    foreach ($pontos as $i => $p) $g[(int) floor($p['lat'] * 100) . ':' . (int) floor($p['lng'] * 100)][] = $i;
    return $g;
}

/** Vizinhos de ($la, $lo) até $raio metros (máx. 2 km), ordenados pela distância: [[índice, distância], ...]. */
function naval_geo_vizinhos(array $pontos, array $indice, float $la, float $lo, float $raio): array
{
    $out = [];
    $cy = (int) floor($la * 100);
    $cx = (int) floor($lo * 100);
    $n = (int) ceil($raio / 1000) + 1;
    for ($dy = -$n; $dy <= $n; $dy++) for ($dx = -$n; $dx <= $n; $dx++) {
        foreach ($indice[($cy + $dy) . ':' . ($cx + $dx)] ?? [] as $i) {
            $d = naval_geo_dist($la, $lo, $pontos[$i]['lat'], $pontos[$i]['lng']);
            if ($d <= $raio) $out[] = [$i, $d];
        }
    }
    usort($out, function ($a, $b) { return $a[1] <=> $b[1]; });
    return $out;
}

/** Nome simplificado para comparar (sem acentos, sem palavras genéricas de comércio). */
function naval_geo_nome_chave(string $n): string
{
    $n = mb_strtolower(trim($n), 'UTF-8');
    $n = strtr($n, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $n = (string) preg_replace('/\b(cantina|loja|mini|mercado|minimercado|supermercado|comercio|comercial|armazem|cubata|bar|lda|sa|e|de|da|do|dos|das)\b/u', ' ', $n);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $n));
}

function naval_geo_nomes_parecidos(string $a, string $b): bool
{
    $a = naval_geo_nome_chave($a);
    $b = naval_geo_nome_chave($b);
    if ($a === '' || $b === '') return false;
    if (strpos($a, $b) !== false || strpos($b, $a) !== false) return true;
    similar_text($a, $b, $pct);
    return $pct >= 70;
}

/** Invólucro convexo (cadeia monótona) de pontos [lat, lng]; devolve os vértices por ordem. */
function naval_geo_involucro(array $pts): array
{
    $pts = array_values(array_unique(array_map(function ($p) { return round($p[0], 6) . ',' . round($p[1], 6); }, $pts)));
    $pts = array_map(function ($s) { return array_map('floatval', explode(',', $s)); }, $pts);
    if (count($pts) < 3) return $pts;
    usort($pts, function ($a, $b) { return [$a[1], $a[0]] <=> [$b[1], $b[0]]; });
    $cruz = function ($o, $a, $b) { return ($a[1] - $o[1]) * ($b[0] - $o[0]) - ($a[0] - $o[0]) * ($b[1] - $o[1]); };
    $baixo = [];
    foreach ($pts as $p) {
        while (count($baixo) >= 2 && $cruz($baixo[count($baixo) - 2], $baixo[count($baixo) - 1], $p) <= 0) array_pop($baixo);
        $baixo[] = $p;
    }
    $cima = [];
    foreach (array_reverse($pts) as $p) {
        while (count($cima) >= 2 && $cruz($cima[count($cima) - 2], $cima[count($cima) - 1], $p) <= 0) array_pop($cima);
        $cima[] = $p;
    }
    array_pop($baixo);
    array_pop($cima);
    return array_merge($baixo, $cima);
}

/**
 * Território de uma rota: invólucro dos seus clientes (sem os que estão a mais de 5 km da mediana, para um cliente
 * mal georreferenciado não esticar a zona), alargado $margem metros para fora. Polígono [[lat, lng], ...].
 */
function naval_geo_territorio(array $clientes, float $margem = 300.0): array
{
    if (!$clientes) return [];
    $la = array_column($clientes, 'lat');
    $lo = array_column($clientes, 'lng');
    sort($la);
    sort($lo);
    $mla = $la[intdiv(count($la), 2)];
    $mlo = $lo[intdiv(count($lo), 2)];
    $pts = [];
    foreach ($clientes as $c) if (naval_geo_dist($c['lat'], $c['lng'], $mla, $mlo) <= 5000) $pts[] = [$c['lat'], $c['lng']];
    if (!$pts) return [];
    // margem: cada vértice do invólucro rodeado por 12 pontos a $margem metros e novo invólucro (zona tampão)
    $h = naval_geo_involucro($pts);
    $cla = array_sum(array_column($h, 0)) / count($h);
    $mLat = $margem / 111320;
    $mLng = $margem / (111320 * cos(deg2rad($cla)));
    $buf = [];
    foreach ($h as $p) for ($k = 0; $k < 12; $k++) {
        $a = $k * M_PI / 6;
        $buf[] = [$p[0] + $mLat * sin($a), $p[1] + $mLng * cos($a)];
    }
    return array_map(function ($p) { return [round($p[0], 6), round($p[1], 6)]; }, naval_geo_involucro($buf));
}

/** Ponto dentro do polígono (lançamento de raio). */
function naval_geo_dentro(float $la, float $lo, array $pol): bool
{
    $dentro = false;
    $n = count($pol);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = $pol[$i];
        [$yj, $xj] = $pol[$j];
        if ((($yi > $la) !== ($yj > $la)) && ($lo < ($xj - $xi) * ($la - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) $dentro = !$dentro;
    }
    return $dentro;
}

/** Divide pontos em dois grupos (k-médias com k = 2, a partir do par mais afastado). Devolve [grupo A, grupo B]. */
function naval_geo_dividir(array $pts): array
{
    if (count($pts) < 2) return [$pts, []];
    $a = $pts[0];
    $far = function ($ref) use ($pts) {
        $m = -1;
        $q = $ref;
        foreach ($pts as $p) if (($d = naval_geo_dist($ref['lat'], $ref['lng'], $p['lat'], $p['lng'])) > $m) { $m = $d; $q = $p; }
        return $q;
    };
    $c1 = $far($a);
    $c2 = $far($c1);
    $g = [[], []];
    for ($it = 0; $it < 12; $it++) {
        $g = [[], []];
        foreach ($pts as $p) {
            $k = naval_geo_dist($p['lat'], $p['lng'], $c1['lat'], $c1['lng']) <= naval_geo_dist($p['lat'], $p['lng'], $c2['lat'], $c2['lng']) ? 0 : 1;
            $g[$k][] = $p;
        }
        if (!$g[0] || !$g[1]) break;
        $c1 = ['lat' => array_sum(array_column($g[0], 'lat')) / count($g[0]), 'lng' => array_sum(array_column($g[0], 'lng')) / count($g[0])];
        $c2 = ['lat' => array_sum(array_column($g[1], 'lat')) / count($g[1]), 'lng' => array_sum(array_column($g[1], 'lng')) / count($g[1])];
    }
    return $g;
}

// ── Clientes do Pinguim BO ──────────────────────────────────────────────────────────────────────────────────────

/**
 * Consulta (só SELECT) dos clientes com coordenadas. Usa ferramentas/sql/geo-clientes.sql se existir (colunas
 * CODIGO, NOME, LATITUDE, LONGITUDE [, CRIADO]); senão procura sozinha, em INFORMATION_SCHEMA, as colunas de
 * latitude e longitude na tabela ENTITY (onde o Pinguim BO guarda os clientes, com o ID usado nas vendas).
 * Devolve [sql, origem] ou [null, motivo].
 */
function naval_geo_consulta_clientes(): array
{
    $f = naval_raiz() . '/ferramentas/sql/geo-clientes.sql';
    if (is_file($f)) {
        $sql = trim((string) file_get_contents($f));
        if (!preg_match('/^\s*(--[^\n]*\n\s*)*(SELECT|WITH)\b/i', $sql) || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|EXEC|EXECUTE|TRUNCATE|GRANT)\b/i', $sql)) {
            return [null, 'ferramentas/sql/geo-clientes.sql tem de ser só uma consulta SELECT.'];
        }
        return [$sql, 'ferramentas/sql/geo-clientes.sql'];
    }
    $cols = [];
    foreach (naval_bd_consultar("
        SELECT UPPER(COLUMN_NAME) AS C FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo' AND UPPER(TABLE_NAME) = 'ENTITY'") as $r) $cols[$r['C']] = true;
    $acha = function (array $nomes) use ($cols) { foreach ($nomes as $n) if (isset($cols[$n])) return $n; return ''; };
    $lat = $acha(['LATITUDE', 'GPS_LATITUDE', 'GEO_LATITUDE', 'LAT', 'GPS_LAT']);
    $lng = $acha(['LONGITUDE', 'GPS_LONGITUDE', 'GEO_LONGITUDE', 'LNG', 'LON', 'GPS_LNG', 'GPS_LON']);
    if ($lat === '' || $lng === '') return [null, 'A tabela ENTITY do Pinguim BO não tem colunas de latitude e longitude reconhecidas: criar ferramentas/sql/geo-clientes.sql.'];
    $nome = $acha(['NAME', 'DESCRIPTION', 'COMMERCIAL_NAME']);
    $criado = $acha(['CREATION_DATE', 'CREATED', 'CREATED_AT', 'INSERT_DATE', 'DATE_CREATED']);
    $sql = "SELECT E.ID AS CODIGO, " . ($nome !== '' ? "E.$nome" : "CAST(E.ID AS VARCHAR(20))") . " AS NOME,
                   CAST(E.$lat AS FLOAT) AS LATITUDE, CAST(E.$lng AS FLOAT) AS LONGITUDE"
        . ($criado !== '' ? ", CONVERT(VARCHAR(10), E.$criado, 120) AS CRIADO" : '') . "
            FROM ENTITY E
            WHERE E.$lat IS NOT NULL AND E.$lng IS NOT NULL AND E.$lat <> 0 AND E.$lng <> 0"
        . (isset($cols['DELETED']) ? ' AND E.DELETED = 0' : '');
    return [$sql, 'ENTITY.' . $lat . '/' . $lng];
}

/**
 * Clientes com coordenadas válidas, rota e dia habitual de visita (das vendas): [{c, n, lat, lng, r, d, f, criado}].
 * Uma leitura por dia (clientes-AAAA-MM-DD.json). $agregados: últimos 56 dias (rota, dia e ritmo de compras).
 */
function naval_geo_clientes(array $agregados, bool $actualizar = false): array
{
    $f = naval_geo_pasta() . '/clientes-' . date('Y-m-d') . '.json';
    $cache = naval_json_ler($f);
    if ($cache && !$actualizar) return $cache;
    if (!naval_bd_configurada()) {
        // sem base de dados: o último ficheiro guardado (nunca dados de exportações em Excel)
        $ult = glob(naval_geo_pasta() . '/clientes-*.json') ?: [];
        rsort($ult);
        return $ult ? naval_json_ler($ult[0]) : ['origem' => '', 'erro' => 'Sem ligação à base de dados do Pinguim BO.', 'clientes' => []];
    }
    [$sql, $origem] = naval_geo_consulta_clientes();
    if ($sql === null) return ['origem' => '', 'erro' => $origem, 'clientes' => []];
    try {
        $rows = naval_bd_consultar($sql);
    } catch (Throwable $e) {
        error_log('[geo clientes SQL] ' . $e->getMessage());
        return $cache ?: ['origem' => $origem, 'erro' => 'A consulta dos clientes no Pinguim BO falhou.', 'clientes' => []];
    }
    $habitos = naval_geo_habitos($agregados);
    $out = [];
    foreach ($rows as $r) {
        if (!naval_geo_valida($r['LATITUDE'] ?? null, $r['LONGITUDE'] ?? null)) continue;
        $c = trim((string) $r['CODIGO']);
        $h = $habitos[$c] ?? null;
        $out[] = ['c' => $c, 'n' => naval_texto($r['NOME'] ?? '', 80), 'lat' => round((float) $r['LATITUDE'], 6), 'lng' => round((float) $r['LONGITUDE'], 6),
            'r' => $h['r'] ?? '', 'd' => $h['d'] ?? null, 'f' => $h['f'] ?? 1, 'criado' => (string) ($r['CRIADO'] ?? '')];
    }
    $d = ['origem' => $origem, 'gerado' => date('Y-m-d H:i:s'), 'clientes' => $out];
    naval_json_gravar($f, $d, false);
    foreach (glob(naval_geo_pasta() . '/clientes-*.json') ?: [] as $x) if (basename($x) < 'clientes-' . date('Y-m-d', time() - 40 * 86400)) @unlink($x);
    return $d;
}

/**
 * Hábitos de compra de cada cliente nas vendas: rota mais frequente, dia da semana mais frequente (1 = segunda) e
 * ritmo de visitas por semana (1 a 3, pelas semanas com compras e o n.º de dias com compras).
 */
function naval_geo_habitos(array $agregados): array
{
    $rota = [];
    $dia = [];
    $dias = [];
    $semanas = [];
    foreach ($agregados as $data => $ag) {
        $ds = naval_dia_semana((string) $data);
        $sem = date('o-W', strtotime($data . ' 12:00'));
        foreach ((array) ($ag['clientes'] ?? []) as $c => $x) {
            $c = (string) $c;
            $r = (string) ($x['r'] ?? '');
            if ($r !== '') $rota[$c][$r] = ($rota[$c][$r] ?? 0) + 1;
            $dia[$c][$ds] = ($dia[$c][$ds] ?? 0) + 1;
            $dias[$c] = ($dias[$c] ?? 0) + 1;
            $semanas[$c][$sem] = true;
        }
    }
    $n = max(1, (int) ceil(count($agregados) / 7));
    $out = [];
    foreach ($dias as $c => $k) {
        $rc = $rota[$c] ?? [];
        arsort($rc);
        arsort($dia[$c]);
        $porSemana = $k / max(1, count($semanas[$c]));
        $out[$c] = ['r' => (string) naval_geo_chave_maior($rc), 'd' => (int) naval_geo_chave_maior($dia[$c]),
            'f' => max(1, min(3, (int) round($porSemana))), 'regular' => count($semanas[$c]) >= min(3, $n)];
    }
    return $out;
}

// ── Estabelecimentos comerciais: OpenStreetMap e Google Places ──────────────────────────────────────────────────

/** Caixa [sul, oeste, norte, leste] dos clientes, com 3 km de margem; null sem clientes. */
function naval_geo_caixa(array $clientes): ?array
{
    if (!$clientes) return null;
    $la = array_column($clientes, 'lat');
    $lo = array_column($clientes, 'lng');
    $m = 0.03;
    return [round(min($la) - $m, 4), round(min($lo) - $m, 4), round(max($la) + $m, 4), round(max($lo) + $m, 4)];
}

/** Lê do OpenStreetMap (Overpass) os estabelecimentos comerciais da caixa. Devolve [{id, n, t, lat, lng, f: osm}]. */
function naval_geo_ler_osm(array $caixa, ?callable $http = null): array
{
    $bb = implode(',', $caixa);
    $q = '[out:json][timeout:90];('
        . 'nwr["shop"~"^(' . NAVAL_GEO_TIPOS_OSM . ')$"](' . $bb . ');'
        . 'nwr["amenity"~"^(' . NAVAL_GEO_AMENIDADES_OSM . ')$"](' . $bb . ');'
        . ');out center tags;';
    $url = naval_config('GEO_OVERPASS_URL', 'https://overpass-api.de/api/interpreter');
    $r = ($http ?? 'naval_http_pedido')('POST', $url, ['corpo' => http_build_query(['data' => $q]), 'tempo' => 120,
        'cabecalhos' => ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: PortalRTM-GrupoNaval/1.0']]);
    if ($r['corpo'] === false || $r['codigo'] !== 200) throw new RuntimeException('OpenStreetMap: HTTP ' . $r['codigo'] . ' ' . $r['erro']);
    $j = json_decode((string) $r['corpo'], true);
    $out = [];
    foreach ((array) ($j['elements'] ?? []) as $e) {
        $la = $e['lat'] ?? $e['center']['lat'] ?? null;
        $lo = $e['lon'] ?? $e['center']['lon'] ?? null;
        if (!naval_geo_valida($la, $lo)) continue;
        $tg = (array) ($e['tags'] ?? []);
        $tipo = (string) ($tg['shop'] ?? $tg['amenity'] ?? '');
        $out[] = ['id' => 'osm:' . substr((string) ($e['type'] ?? 'n'), 0, 1) . ($e['id'] ?? ''), 'n' => naval_texto($tg['name'] ?? '', 80), 't' => $tipo,
            'lat' => round((float) $la, 6), 'lng' => round((float) $lo, 6), 'f' => ['osm']];
    }
    return $out;
}

/**
 * Células de ~1 km (centros) onde procurar no Google: as que têm clientes e as vizinhas (território e arredores).
 * Chave «lat:lng» em centésimos de grau.
 */
function naval_geo_celulas(array $clientes): array
{
    $c = [];
    foreach ($clientes as $x) {
        $y = (int) floor($x['lat'] * 100);
        $z = (int) floor($x['lng'] * 100);
        for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) $c[($y + $dy) . ':' . ($z + $dx)] = true;
    }
    ksort($c);
    return array_keys($c);
}

/**
 * Pedidos ao Google Places (searchNearby) nesta passagem, dentro do limite do mês (GEO_GOOGLE_PEDIDOS_MES, 4500) e
 * de $maximo por passagem: renova primeiro as células nunca vistas e as mais antigas. Apaga os lugares não vistos há
 * mais de GEO_GOOGLE_DIAS (30). Devolve o n.º de pedidos feitos. Sem GOOGLE_PLACES_API_KEY não faz nada.
 */
function naval_geo_google_passagem(array $celulas, int $maximo, ?callable $http = null, ?int $agora = null, array $cfg = []): int
{
    $agora = $agora ?? time();
    $chave = (string) ($cfg['chave'] ?? naval_config('GOOGLE_PLACES_API_KEY'));
    $f = naval_geo_pasta() . '/google.json';
    $g = naval_json_ler($f) + ['lugares' => [], 'celulas' => [], 'meses' => []];
    // regras do Google: coordenadas guardadas no máximo 30 dias
    $dias = (int) naval_geo_num('GEO_GOOGLE_DIAS', 30);
    foreach ($g['lugares'] as $id => $l) if (($l['visto'] ?? 0) < $agora - $dias * 86400) unset($g['lugares'][$id]);
    $feitos = 0;
    $mes = date('Y-m', $agora);
    $limite = (int) ($cfg['limite'] ?? naval_geo_num('GEO_GOOGLE_PEDIDOS_MES', 4500));
    if ($chave !== '' && $limite > 0) {
        usort($celulas, function ($a, $b) use ($g) { return ($g['celulas'][$a] ?? 0) <=> ($g['celulas'][$b] ?? 0); });
        foreach ($celulas as $cel) {
            if ($feitos >= $maximo || ($g['meses'][$mes] ?? 0) >= $limite) break;
            if (($g['celulas'][$cel] ?? 0) > $agora - 25 * 86400) break;   // as restantes foram vistas há menos de 25 dias
            [$y, $x] = array_map('intval', explode(':', $cel));
            $corpo = json_encode(['includedTypes' => NAVAL_GEO_TIPOS_GOOGLE, 'maxResultCount' => 20, 'rankPreference' => 'DISTANCE',
                'locationRestriction' => ['circle' => ['center' => ['latitude' => ($y + 0.5) / 100, 'longitude' => ($x + 0.5) / 100], 'radius' => 800.0]]]);
            $r = ($http ?? 'naval_http_pedido')('POST', 'https://places.googleapis.com/v1/places:searchNearby', ['corpo' => $corpo, 'tempo' => 30,
                'cabecalhos' => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $chave, 'X-Goog-FieldMask: places.id,places.displayName,places.location,places.primaryType']]);
            $feitos++;
            $g['meses'][$mes] = ($g['meses'][$mes] ?? 0) + 1;
            if ($r['corpo'] === false || $r['codigo'] !== 200) {
                error_log('[geo Google Places] HTTP ' . $r['codigo'] . ' ' . substr((string) $r['corpo'], 0, 300));
                if (in_array($r['codigo'], [400, 401, 403, 429], true)) break;   // chave, quota ou pedido inválido: não insistir
                continue;
            }
            $g['celulas'][$cel] = $agora;
            foreach ((array) (json_decode((string) $r['corpo'], true)['places'] ?? []) as $p) {
                $la = $p['location']['latitude'] ?? null;
                $lo = $p['location']['longitude'] ?? null;
                if (empty($p['id']) || !naval_geo_valida($la, $lo)) continue;
                $g['lugares'][(string) $p['id']] = ['n' => naval_texto($p['displayName']['text'] ?? '', 80), 't' => (string) ($p['primaryType'] ?? ''),
                    'lat' => round((float) $la, 6), 'lng' => round((float) $lo, 6), 'visto' => $agora];
            }
        }
    }
    $g['meses'] = array_slice($g['meses'], -13, null, true);
    naval_json_gravar($f, $g, false);
    return $feitos;
}

/** Lugares do Google ainda dentro do prazo, no formato comum. */
function naval_geo_google_pontos(): array
{
    $out = [];
    foreach ((array) (naval_json_ler(naval_geo_pasta() . '/google.json')['lugares'] ?? []) as $id => $l) {
        $out[] = ['id' => 'g:' . $id, 'n' => (string) $l['n'], 't' => (string) $l['t'], 'lat' => (float) $l['lat'], 'lng' => (float) $l['lng'], 'f' => ['google']];
    }
    return $out;
}

// ── Cálculo (sem acesso a dados: testável) ──────────────────────────────────────────────────────────────────────

/**
 * Junta as fontes num só ponto por estabelecimento (a menos de 25 m e com nome parecido, ou sem nome e o mesmo tipo)
 * e retira os que já são clientes (a menos de 30 m, ou a menos de 80 m com nome parecido). Devolve
 * [potenciais, convertidos: {id: código do cliente}]: um potencial já visto antes que agora é cliente conta como
 * convertido.
 */
function naval_geo_potenciais(array $pontos, array $clientes, array $vistosAntes = []): array
{
    $junt = [];
    $idx = [];
    foreach ($pontos as $p) {
        $hit = null;
        foreach (naval_geo_vizinhos($junt, $idx, $p['lat'], $p['lng'], 25) as [$i]) {
            $q = $junt[$i];
            if (naval_geo_nomes_parecidos($p['n'], $q['n']) || (($p['n'] === '' || $q['n'] === '') && $p['t'] === $q['t'])) { $hit = $i; break; }
        }
        if ($hit === null) {
            $p['ids'] = [$p['id']];
            $junt[] = $p;
            $idx[(int) floor($p['lat'] * 100) . ':' . (int) floor($p['lng'] * 100)][] = count($junt) - 1;
            continue;
        }
        $junt[$hit]['f'] = array_values(array_unique(array_merge($junt[$hit]['f'], $p['f'])));
        $junt[$hit]['ids'][] = $p['id'];
        if ($junt[$hit]['n'] === '') $junt[$hit]['n'] = $p['n'];
    }
    $ic = naval_geo_indice($clientes);
    $pot = [];
    $conv = [];
    foreach ($junt as $p) {
        $cliente = null;
        foreach (naval_geo_vizinhos($clientes, $ic, $p['lat'], $p['lng'], 80) as [$i, $d]) {
            if ($d <= 30 || naval_geo_nomes_parecidos($p['n'], $clientes[$i]['n'])) { $cliente = $clientes[$i]; break; }
        }
        if ($cliente !== null) {
            foreach ($p['ids'] as $id) if (isset($vistosAntes[$id])) { $conv[$id] = $cliente['c']; break; }
            continue;
        }
        $pot[] = $p;
    }
    return [$pot, $conv];
}

/**
 * Rota e dia de cada potencial cliente: a rota que domina os 5 clientes mais próximos (até $raio m) e o dia em que
 * essa rota visita esses clientes; mais longe, sem rota (zona sem cobertura). Junta a prioridade s (0 a 100).
 */
function naval_geo_atribuir(array $pot, array $clientes, float $raio = 1500.0): array
{
    $ic = naval_geo_indice($clientes);
    $ip = naval_geo_indice($pot);
    foreach ($pot as $k => $p) {
        $viz = array_slice(array_filter(naval_geo_vizinhos($clientes, $ic, $p['lat'], $p['lng'], $raio), function ($v) use ($clientes) {
            return $clientes[$v[0]]['r'] !== '';
        }), 0, 5);
        $r = '';
        $d = null;
        $dist = null;
        if ($viz) {
            $votos = [];
            foreach ($viz as [$i, $dd]) $votos[$clientes[$i]['r']] = ($votos[$clientes[$i]['r']] ?? 0) + 1 / max(50, $dd);
            arsort($votos);
            $r = (string) naval_geo_chave_maior($votos);
            $dias = [];
            foreach ($viz as [$i]) if ($clientes[$i]['r'] === $r && $clientes[$i]['d']) $dias[$clientes[$i]['d']] = ($dias[$clientes[$i]['d']] ?? 0) + 1;
            arsort($dias);
            $d = $dias ? (int) naval_geo_chave_maior($dias) : null;
            $dist = (int) round($viz[0][1]);
        }
        // prioridade: confiança (n.º de fontes), tipo de estabelecimento, outros potenciais perto (zona comercial) e
        // proximidade de clientes da rota (cabe na volta do dia)
        $densidade = count(naval_geo_vizinhos($pot, $ip, $p['lat'], $p['lng'], 300)) - 1;
        $s = (count($p['f']) > 1 ? 35 : (in_array('google', $p['f'], true) ? 22 : 18))
            + (NAVAL_GEO_PESO_TIPO[$p['t']] ?? 8)
            + min(15, $densidade * 3)
            + ($dist === null ? 0 : ($dist <= 300 ? 20 : ($dist <= 800 ? 12 : 5)));
        $pot[$k] += ['r' => $r, 'd' => $d, 'dc' => $dist, 's' => min(100, $s)];
    }
    usort($pot, function ($a, $b) { return $b['s'] <=> $a['s'] ?: strcmp($a['id'], $b['id']); });
    return $pot;
}

/**
 * Indicadores de cada rota: clientes, visitas por semana (ritmo de compras), capacidade, ocupação, ocupação se
 * ganhar os potenciais do território, território (polígono) e proposta de divisão quando passa o limiar.
 */
function naval_geo_rotas(array $clientes, array $pot, array $promotores, array $convertidos = []): array
{
    $cap = naval_geo_num('GEO_VISITAS_DIA', 30) * naval_geo_num('GEO_DIAS_SEMANA', 6);
    $limiar = naval_geo_num('GEO_LIMIAR_ROTA', 0.9);
    $porRota = [];
    foreach ($clientes as $c) if ($c['r'] !== '') $porRota[$c['r']][] = $c;
    $potRota = [];
    foreach ($pot as $p) if ($p['r'] !== '') $potRota[$p['r']] = ($potRota[$p['r']] ?? 0) + 1;
    $conv = [];
    foreach ($convertidos as $x) if (($x['em'] ?? '') >= date('Y-m-d', time() - 30 * 86400)) $conv[$x['r'] ?? ''] = ($conv[$x['r'] ?? ''] ?? 0) + 1;
    ksort($porRota);
    $out = [];
    foreach ($porRota as $r => $cs) {
        $carga = array_sum(array_column($cs, 'f'));
        $np = $potRota[$r] ?? 0;
        $oc = $cap > 0 ? $carga / $cap : 0;
        $x = ['rota' => (string) $r, 'promotor' => $promotores[$r] ?? (string) $r, 'clientes' => count($cs), 'visitas_semana' => $carga,
            'capacidade' => (int) $cap, 'ocupacao' => round($oc, 3), 'potenciais' => $np, 'ocupacao_potencial' => round($cap > 0 ? ($carga + $np) / $cap : 0, 3),
            'cobertura' => round(count($cs) / max(1, count($cs) + $np), 3), 'convertidos_30d' => $conv[$r] ?? 0,
            'territorio' => naval_geo_territorio($cs), 'cheia' => $oc >= $limiar];
        if ($x['cheia']) {
            [$a, $b] = naval_geo_dividir($cs);
            $x['divisao'] = [['clientes' => count($a), 'territorio' => naval_geo_territorio($a)], ['clientes' => count($b), 'territorio' => naval_geo_territorio($b)]];
        }
        $out[] = $x;
    }
    return $out;
}

/** Zonas sem rota com muitos potenciais clientes (células de ~2 km): candidatas a uma rota nova. */
function naval_geo_zonas_sem_rota(array $pot, int $minimo = 0): array
{
    $minimo = $minimo ?: (int) naval_geo_num('GEO_MIN_ZONA_NOVA', 40);
    $g = [];
    foreach ($pot as $p) {
        if ($p['r'] !== '') continue;
        $k = (int) floor($p['lat'] * 50) . ':' . (int) floor($p['lng'] * 50);
        $g[$k][] = $p;
    }
    $out = [];
    foreach ($g as $ps) {
        if (count($ps) < $minimo) continue;
        $out[] = ['lat' => round(array_sum(array_column($ps, 'lat')) / count($ps), 5), 'lng' => round(array_sum(array_column($ps, 'lng')) / count($ps), 5), 'potenciais' => count($ps)];
    }
    usort($out, function ($a, $b) { return $b['potenciais'] <=> $a['potenciais']; });
    return $out;
}

// ── Cálculo completo, histórico, avisos e vista ─────────────────────────────────────────────────────────────────

/**
 * Carteira: clientes regulares que deixaram de comprar (recuperação de clientes) e qualidade das coordenadas.
 * $agregados: vendas dos últimos 120 dias por dia. Regular = compras em 3 ou mais dias da janela.
 * Escalões de dias sem compras: 14–29, 30–59, 60 ou mais (prática de gestão de carteira / «churn»).
 * Devolve ['esquecidos' => [...], 'sem_gps' => [...], 'duplicados' => [...]].
 */
function naval_geo_carteira(array $agregados, array $clientes, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    $t0 = strtotime($hoje . ' 12:00');
    $info = [];
    ksort($agregados);
    foreach ($agregados as $data => $ag) {
        foreach ((array) ($ag['clientes'] ?? []) as $c => $x) {
            $c = (string) $c;
            $i = $info[$c] ?? ['n' => '', 'r' => '', 'dias' => 0, 't' => 0.0, 'ult' => ''];
            $i['n'] = (string) ($x['n'] ?? $i['n']) ?: $i['n'];
            $i['r'] = (string) ($x['r'] ?? '') ?: $i['r'];   // a rota mais recente
            $i['dias']++;
            $i['t'] += (float) ($x['t'] ?? 0);
            $i['ult'] = (string) $data;
            $info[$c] = $i;
        }
    }
    $pos = [];
    foreach ($clientes as $c) $pos[(string) $c['c']] = $c;
    $esq = [];
    $sem = [];
    foreach ($info as $c => $i) {
        if (!isset($pos[$c])) {
            $sem[] = ['c' => $c, 'n' => $i['n'], 'r' => $i['r'], 'compras' => $i['dias'], 'ult' => $i['ult']];
        }
        if ($i['dias'] < 3) continue;
        $dias = (int) floor(($t0 - strtotime($i['ult'] . ' 12:00')) / 86400);
        if ($dias < 14) continue;
        $e = ['c' => $c, 'n' => $i['n'], 'r' => $i['r'], 'ult' => $i['ult'], 'dias' => $dias, 'faixa' => $dias >= 60 ? 60 : ($dias >= 30 ? 30 : 14),
            'compras' => $i['dias'], 'media' => round($i['t'] / max(1, $i['dias']), 2)];
        if (isset($pos[$c])) $e += ['lat' => $pos[$c]['lat'], 'lng' => $pos[$c]['lng']];
        $esq[] = $e;
    }
    // primeiro os que valiam mais por compra e compravam mais vezes (valor em risco)
    usort($esq, function ($a, $b) { return ($b['media'] * $b['compras']) <=> ($a['media'] * $a['compras']) ?: strcmp($a['c'], $b['c']); });
    usort($sem, function ($a, $b) { return $b['compras'] <=> $a['compras'] ?: strcmp($a['c'], $b['c']); });
    // clientes diferentes no mesmo ponto (a menos de 5 m): coordenadas copiadas ou registadas no escritório
    $idx = naval_geo_indice($clientes);
    $visto = [];
    $dup = [];
    foreach ($clientes as $k => $c) {
        if (isset($visto[$k])) continue;
        $grupo = [];
        foreach (naval_geo_vizinhos($clientes, $idx, $c['lat'], $c['lng'], 5) as [$j]) {
            if ((string) $clientes[$j]['c'] === (string) $c['c']) continue;
            $grupo[$j] = true;
        }
        if (!$grupo) continue;
        $grupo[$k] = true;
        foreach (array_keys($grupo) as $j) $visto[$j] = true;
        $dup[] = ['lat' => $c['lat'], 'lng' => $c['lng'], 'r' => $c['r'], 'clientes' => array_map(function ($j) use ($clientes) {
            return ['c' => (string) $clientes[$j]['c'], 'n' => $clientes[$j]['n'], 'r' => $clientes[$j]['r']];
        }, array_keys($grupo))];
    }
    return ['esquecidos' => array_slice($esq, 0, 400), 'sem_gps' => array_slice($sem, 0, 400), 'duplicados' => array_slice($dup, 0, 200)];
}

/**
 * Funil da prospecção por rota: por visitar → visitado → interessado → cliente (convertido), mais os que não
 * avançam (não interessado, compra a outro fornecedor). Lido no momento (os estados mudam durante o dia).
 */
function naval_geo_funil(array $potenciais, array $estados, array $convertidos, ?string $hoje = null): array
{
    $desde = date('Y-m-d', strtotime(($hoje ?? date('Y-m-d')) . ' 12:00') - 90 * 86400);
    $f = [];
    $vazio = ['por_visitar' => 0, 'visitado' => 0, 'interessado' => 0, 'nao_interessado' => 0, 'outro_fornecedor' => 0, 'convertidos' => 0];
    foreach ($potenciais as $p) {
        $r = (string) ($p['r'] ?? '');
        $f[$r] = $f[$r] ?? $vazio;
        $e = '';
        foreach ((array) ($p['ids'] ?? [$p['id']]) as $id) if (isset($estados[$id])) { $e = (string) $estados[$id]['e']; break; }
        if ($e === '') $f[$r]['por_visitar']++;
        elseif (isset($f[$r][$e])) $f[$r][$e]++;
    }
    foreach ($convertidos as $x) {
        if (($x['em'] ?? '') < $desde) continue;
        $r = (string) ($x['r'] ?? '');
        $f[$r] = $f[$r] ?? $vazio;
        $f[$r]['convertidos']++;
    }
    foreach ($f as $r => $x) {
        $trabalhados = $x['visitado'] + $x['interessado'] + $x['nao_interessado'] + $x['outro_fornecedor'] + $x['convertidos'];
        $f[$r]['taxa'] = $trabalhados ? round($x['convertidos'] / $trabalhados, 3) : null;
    }
    ksort($f);
    return $f;
}

/**
 * Recalcula tudo e grava vista.json. $fontes: pontos já lidos (testes); null = osm.json + google.json.
 * $agregados: null = últimos 56 dias de vendas.
 */
function naval_geo_calcular(?array $agregados = null, ?array $fontes = null, ?array $clientesDados = null, ?array $agregados120 = null): array
{
    if ($agregados120 === null) {
        $agregados120 = $agregados ?? naval_analise_agregados(date('Y-m-d', time() - 120 * 86400), date('Y-m-d', time() - 86400));
    }
    $agregados = $agregados ?? array_filter($agregados120, function ($d) { return $d >= date('Y-m-d', time() - 56 * 86400); }, ARRAY_FILTER_USE_KEY);
    $cd = $clientesDados ?? naval_geo_clientes($agregados);
    $clientes = array_values(array_filter((array) $cd['clientes'], function ($c) { return naval_geo_valida($c['lat'], $c['lng']); }));
    $fontes = $fontes ?? array_merge((array) (naval_json_ler(naval_geo_pasta() . '/osm.json')['pontos'] ?? []), naval_geo_google_pontos());
    $estados = naval_json_ler(naval_geo_pasta() . '/estados.json');
    $antes = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $vistos = [];
    foreach ((array) ($antes['potenciais'] ?? []) as $p) foreach ((array) ($p['ids'] ?? [$p['id']]) as $id) $vistos[$id] = $p['r'] ?? '';
    [$pot, $conv] = naval_geo_potenciais($fontes, $clientes, $vistos);
    // quem disse «não existe» tira-o da lista de vez (só o identificador fica guardado)
    $pot = array_values(array_filter($pot, function ($p) use ($estados) {
        foreach ($p['ids'] as $id) if (($estados[$id]['e'] ?? '') === 'nao_existe') return false;
        return true;
    }));
    $pot = naval_geo_atribuir($pot, $clientes, naval_geo_num('GEO_RAIO_TERRITORIO', 1500));
    foreach ($pot as &$p) {
        foreach ($p['ids'] as $id) if (isset($estados[$id])) { $p['e'] = $estados[$id]['e']; $p['em'] = $estados[$id]['em']; break; }
    }
    unset($p);
    // conversões: guardadas com a data e a rota (indicador por vendedor)
    $fc = naval_geo_pasta() . '/convertidos.json';
    $convertidos = naval_json_ler($fc);
    foreach ($conv as $id => $cod) if (!isset($convertidos[$id])) $convertidos[$id] = ['c' => $cod, 'r' => $vistos[$id] ?? '', 'em' => date('Y-m-d')];
    naval_json_gravar($fc, $convertidos, false);
    $rotas = naval_geo_rotas($clientes, $pot, naval_promotores(), $convertidos);
    $v = ['gerado' => date('Y-m-d H:i:s'), 'origem_clientes' => $cd['origem'] ?? '', 'erro_clientes' => $cd['erro'] ?? null,
        'fontes' => naval_geo_estado_fontes(), 'parametros' => ['visitas_dia' => naval_geo_num('GEO_VISITAS_DIA', 30), 'dias_semana' => naval_geo_num('GEO_DIAS_SEMANA', 6),
        'limiar' => naval_geo_num('GEO_LIMIAR_ROTA', 0.9)],
        'rotas' => $rotas, 'zonas_sem_rota' => naval_geo_zonas_sem_rota($pot),
        'clientes' => array_map(function ($c) { return ['c' => $c['c'], 'n' => $c['n'], 'lat' => $c['lat'], 'lng' => $c['lng'], 'r' => $c['r'], 'd' => $c['d']]; }, $clientes),
        'potenciais' => $pot, 'carteira' => naval_geo_carteira($agregados120, $clientes)];
    naval_json_gravar(naval_geo_pasta() . '/vista.json', $v, false);
    naval_geo_historico($rotas);
    return $v;
}

function naval_geo_estado_fontes(): array
{
    $o = naval_json_ler(naval_geo_pasta() . '/osm.json');
    $g = naval_json_ler(naval_geo_pasta() . '/google.json');
    return ['osm_em' => $o['em'] ?? null, 'osm' => count((array) ($o['pontos'] ?? [])), 'google' => count((array) ($g['lugares'] ?? [])),
        'google_mes' => (int) (($g['meses'] ?? [])[date('Y-m')] ?? 0), 'google_limite' => (int) naval_geo_num('GEO_GOOGLE_PEDIDOS_MES', 4500),
        'google_activo' => naval_config('GOOGLE_PLACES_API_KEY') !== ''];
}

/** Guarda a ocupação do dia de cada rota (últimos 60 dias). */
function naval_geo_historico(array $rotas, ?string $dia = null): void
{
    $dia = $dia ?? date('Y-m-d');
    $f = naval_geo_pasta() . '/ocupacao.json';
    $h = naval_json_ler($f);
    foreach ($rotas as $r) {
        $h[$r['rota']][$dia] = $r['ocupacao'];
        ksort($h[$r['rota']]);
        $h[$r['rota']] = array_slice($h[$r['rota']], -60, null, true);
    }
    naval_json_gravar($f, $h, false);
}

/** Rotas acima do limiar em todos os últimos $dias dias registados (sem falhas de dias). */
function naval_geo_rotas_cheias(array $historico, float $limiar, int $dias = 14, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    $out = [];
    foreach ($historico as $rota => $serie) {
        $ok = true;
        for ($k = 0; $k < $dias; $k++) {
            $d = date('Y-m-d', strtotime($hoje . ' 12:00') - $k * 86400);
            if (!isset($serie[$d]) || $serie[$d] < $limiar) { $ok = false; break; }
        }
        if ($ok) $out[] = (string) $rota;
    }
    return $out;
}

/** Avisos por correio: rota cheia (nova rota: vendedor + viatura) e zonas sem rota; cada um no máximo de 30 em 30 dias. */
function naval_geo_avisar(array $v): array
{
    $f = naval_geo_pasta() . '/avisos.json';
    $av = naval_json_ler($f);
    $cheias = naval_geo_rotas_cheias(naval_json_ler(naval_geo_pasta() . '/ocupacao.json'), (float) $v['parametros']['limiar']);
    $novas = [];
    foreach ($cheias as $r) if (($av['rota:' . $r] ?? '') < date('Y-m-d', time() - 30 * 86400)) $novas[] = $r;
    $zonas = array_values(array_filter($v['zonas_sem_rota'], function ($z) use ($av) {
        return ($av['zona:' . round($z['lat'], 2) . ':' . round($z['lng'], 2)] ?? '') < date('Y-m-d', time() - 30 * 86400);
    }));
    if (!$novas && !$zonas) return [];
    $dest = naval_destinatarios_recurso('geo-prospeccao', 'V');
    if (!$dest) return [];
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $porRota = array_column($v['rotas'], null, 'rota');
    $html = '<div style="font:14px Segoe UI,Arial,sans-serif;color:#0a2a52;text-align:justify">';
    foreach ($novas as $r) {
        $x = $porRota[$r];
        $html .= '<p><b>Rota ' . $h($r) . ' (' . $h($x['promotor']) . ') atingiu o máximo de clientes que consegue atender.</b> Ocupação de '
            . $h(round($x['ocupacao'] * 100)) . ' % há 14 dias seguidos (' . $h($x['visitas_semana']) . ' visitas por semana para uma capacidade de '
            . $h($x['capacidade']) . '), com mais ' . $h($x['potenciais']) . ' potenciais clientes no território. É preciso criar uma <b>nova rota (vendedor + viatura)</b>.';
        if (!empty($x['divisao'])) $html .= ' Proposta: dividir o território em duas zonas, com ' . $h($x['divisao'][0]['clientes']) . ' e ' . $h($x['divisao'][1]['clientes']) . ' clientes (ver o mapa).';
        $html .= '</p>';
        $av['rota:' . $r] = date('Y-m-d');
    }
    if ($zonas) {
        $html .= '<p><b>Zonas sem rota com muitos potenciais clientes:</b></p><ul>';
        foreach (array_slice($zonas, 0, 10) as $z) {
            $html .= '<li>' . $h($z['potenciais']) . ' estabelecimentos perto de <a href="https://www.google.com/maps?q=' . $h($z['lat'] . ',' . $z['lng']) . '">'
                . $h($z['lat'] . ', ' . $z['lng']) . '</a></li>';
            $av['zona:' . round($z['lat'], 2) . ':' . round($z['lng'], 2)] = date('Y-m-d');
        }
        $html .= '</ul>';
    }
    $url = rtrim(naval_config('PORTAL_URL'), '/');
    $html .= '<p>Mapa dos territórios e da prospecção: GeoMobilidade › Prospecção' . ($url !== '' ? ' (' . $h($url) . '/geo/prospeccao.php)' : '') . '.</p></div>';
    $r = naval_enviar_email($dest, ($novas ? 'Rota cheia: criar nova rota (vendedor + viatura)' : 'Zonas sem rota com potenciais clientes') . ' · GeoMobilidade', $html);
    if ($r['ok']) naval_json_gravar($f, $av);
    return ['rotas' => $novas, 'zonas' => count($zonas), 'email' => $r['ok']];
}

/**
 * Tarefa da noite (chamada de 5 em 5 minutos entre GEO_HORA_INICIO e as 06:00): lê o OpenStreetMap uma vez por
 * semana, faz até 40 pedidos ao Google por passagem e recalcula uma vez por dia, com os avisos.
 */
function naval_geo_noite(int $agora): array
{
    $ini = naval_config('GEO_HORA_INICIO', '01:00');
    if (date('H:i', $agora) < $ini || date('H', $agora) >= '06') return [];
    $feito = [];
    $agregados120 = naval_analise_agregados(date('Y-m-d', $agora - 120 * 86400), date('Y-m-d', $agora - 86400));
    $agregados = array_filter($agregados120, function ($d) use ($agora) { return $d >= date('Y-m-d', $agora - 56 * 86400); }, ARRAY_FILTER_USE_KEY);
    $cd = naval_geo_clientes($agregados);
    $clientes = (array) ($cd['clientes'] ?? []);
    $fo = naval_geo_pasta() . '/osm.json';
    $osm = naval_json_ler($fo);
    if ($clientes && naval_config('GEO_OSM_ACTIVO', '1') !== '0' && ($osm['em'] ?? '') < date('Y-m-d', $agora - 7 * 86400)) {
        try {
            $pts = naval_geo_ler_osm((array) naval_geo_caixa($clientes));
            naval_json_gravar($fo, ['em' => date('Y-m-d', $agora), 'pontos' => $pts], false);
            $feito['osm'] = count($pts);
        } catch (Throwable $e) {
            error_log('[geo OSM] ' . $e->getMessage());
            naval_json_gravar($fo, ['em' => date('Y-m-d', $agora - 6 * 86400)] + $osm, false);   // tenta de novo amanhã
        }
    }
    if ($clientes) $feito['google'] = naval_geo_google_passagem(naval_geo_celulas($clientes), 40, null, $agora);
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    if (substr((string) ($v['gerado'] ?? ''), 0, 10) !== date('Y-m-d', $agora) && date('H', $agora) >= '04') {
        $v = naval_geo_calcular($agregados, null, $cd, $agregados120);
        $feito['calculo'] = count($v['potenciais']);
        $feito['avisos'] = naval_geo_avisar($v);
    }
    return $feito;
}

/** Vista no âmbito de quem pede: as suas rotas (SUP e PRO) e, para os outros, também as zonas sem rota. */
function naval_geo_vista(?array $rotas, bool $actualizar = false): array
{
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    if (!$v || $actualizar) $v = naval_geo_calcular();
    if ($rotas !== null) {
        $dentro = function ($x) use ($rotas) { return in_array((string) ($x['r'] ?? $x['rota'] ?? ''), $rotas, true); };
        $v['rotas'] = array_values(array_filter($v['rotas'], $dentro));
        $v['clientes'] = array_values(array_filter($v['clientes'], $dentro));
        $v['potenciais'] = array_values(array_filter($v['potenciais'], $dentro));
        $v['zonas_sem_rota'] = [];
        foreach (['esquecidos', 'sem_gps', 'duplicados'] as $k) {
            $v['carteira'][$k] = array_values(array_filter((array) ($v['carteira'][$k] ?? []), $dentro));
        }
    }
    $v['carteira'] = ($v['carteira'] ?? []) + ['esquecidos' => [], 'sem_gps' => [], 'duplicados' => []];
    $funil = naval_geo_funil($v['potenciais'], naval_json_ler(naval_geo_pasta() . '/estados.json'), naval_json_ler(naval_geo_pasta() . '/convertidos.json'));
    $v['funil'] = $rotas === null ? $funil : array_intersect_key($funil, array_flip($rotas));
    foreach ($v['potenciais'] as &$p) unset($p['ids']);
    unset($p);
    $v['estados'] = NAVAL_GEO_ESTADOS;
    return $v;
}

/** O vendedor regista o que encontrou num potencial cliente (fica no histórico; «não existe» tira-o do mapa). */
function naval_geo_marcar(string $id, string $estado, array $eu, ?array $rotas): array
{
    if (!preg_match('/^(osm:[nwr]\d{1,15}|g:[A-Za-z0-9_-]{10,200})$/', $id)) throw new NavalErro('Potencial cliente desconhecido.', 404);
    if ($estado !== '' && !isset(NAVAL_GEO_ESTADOS[$estado])) throw new NavalErro('Estado inválido.', 400);
    $v = naval_json_ler(naval_geo_pasta() . '/vista.json');
    $p = null;
    foreach ((array) ($v['potenciais'] ?? []) as $x) if (in_array($id, (array) ($x['ids'] ?? [$x['id']]), true)) $p = $x;
    if ($p === null || ($rotas !== null && !in_array((string) $p['r'], $rotas, true))) throw new NavalErro('Potencial cliente desconhecido.', 404);
    return naval_exclusivo('geo-estados', function () use ($p, $estado, $eu) {
        $f = naval_geo_pasta() . '/estados.json';
        $e = naval_json_ler($f);
        foreach ((array) ($p['ids'] ?? [$p['id']]) as $i) {
            if ($estado === '') unset($e[$i]);
            else $e[$i] = ['e' => $estado, 'por' => (string) $eu['login'], 'em' => date('Y-m-d H:i:s'), 'r' => (string) $p['r']];
        }
        naval_json_gravar($f, $e);
        naval_registar('geo_potencial', ['id' => $p['id'], 'estado' => $estado, 'rota' => $p['r']]);
        return ['id' => $p['id'], 'e' => $estado];
    });
}
