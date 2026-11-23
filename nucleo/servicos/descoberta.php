<?php
/**
 * Descoberta automática de potenciais clientes (GeoMobilidade), contínua e sem intervenção manual, em Luanda e
 * Ícolo e Bengo. É a primeira etapa da prospecção (nucleo/servicos/prospeccao.php):
 *
 *   ingestão ─► normalização e qualidade ─► deduplicação ─► ICP Score ─► território (geofence) da rota ─► radar do dia
 *
 *   - ingestão: OpenStreetMap (Overpass, toda a área, uma vez por semana), Google Places (grelha de ~1 km onde há
 *     clientes ou comércio conhecido, com limite mensal) e fontes externas (n8n, Make, Apify, Scrapfly, Clay…) que
 *     enviam listas pelo webhook v1/geo/entrada com a chave GEO_ENTRADA_CHAVE;
 *   - normalização: tipo de estabelecimento na taxonomia do portfólio (NAVAL_DESC_TIPOS), palavras-chave no nome
 *     (NAVAL_DESC_PALAVRAS), telefone angolano em 9 dígitos, sítio sem «www»;
 *   - qualidade: coordenadas válidas dentro da área, nome ou tipo conhecido, ligado ao portfólio (higiene e limpeza
 *     e/ou alimentar), nunca dados pessoais além do contacto público do estabelecimento;
 *   - deduplicação (prospeccao.php): o mesmo estabelecimento em várias fontes (25 m e nome parecido, ou o mesmo
 *     telefone ou sítio até 500 m) junta-se num só; quem já é cliente (30 m, ou 80 m com nome parecido) sai;
 *   - ICP Score (0 a 100): adequação ao portfólio, potencial de volume do tipo, contacto válido, confiança das fontes e
 *     viabilidade na zona (distância aos clientes da rota, que é a «geofence» de cada um dos vendedores).
 *
 * Ficheiros (dados-privados/geo/): externos.json (estabelecimentos recebidos pelo webhook, 90 dias sem voltarem a ser
 * enviados apagam-se) e recalcular.json (pedido de novo cálculo depois de uma entrada externa).
 */
defined('NAVAL_NUCLEO') || exit;

/** Área de trabalho [sul, oeste, norte, leste]: províncias de Luanda e Ícolo e Bengo (GEO_AREA no .env). */
const NAVAL_DESC_AREA = [-9.75, 12.95, -8.55, 14.20];

/**
 * Taxonomia: tipo (OpenStreetMap shop/amenity/tourism, Google Places ou fonte externa) => [afinidade, volume].
 * afinidade: ambos | alimentar | higiene (higiene e limpeza). volume: potencial de compra recorrente (0 a 30).
 */
const NAVAL_DESC_TIPOS = [
    // retalho alimentar e grosso: as duas linhas
    'wholesale' => ['ambos', 30], 'wholesaler' => ['ambos', 30], 'cash_and_carry' => ['ambos', 30], 'warehouse' => ['ambos', 26],
    'supermarket' => ['ambos', 30], 'hypermarket' => ['ambos', 30], 'grocery_store' => ['ambos', 28], 'grocery' => ['ambos', 28],
    'convenience' => ['ambos', 26], 'convenience_store' => ['ambos', 26], 'general' => ['ambos', 26], 'food_store' => ['alimentar', 24],
    'variety_store' => ['ambos', 22], 'discount_store' => ['ambos', 22], 'department_store' => ['ambos', 20], 'market' => ['ambos', 24],
    'marketplace' => ['ambos', 24], 'kiosk' => ['ambos', 18], 'store' => ['ambos', 12],
    // higiene e limpeza
    'chemist' => ['higiene', 24], 'drugstore' => ['higiene', 22], 'household_linen' => ['higiene', 10], 'houseware' => ['higiene', 18],
    'home_goods_store' => ['higiene', 18], 'cosmetics' => ['higiene', 16], 'beauty' => ['higiene', 10], 'hardware' => ['higiene', 8],
    'pharmacy' => ['higiene', 14], 'laundry' => ['higiene', 16], 'dry_cleaning' => ['higiene', 12],
    // instituições: consumo recorrente de higiene e limpeza (e cantina)
    'school' => ['higiene', 18], 'college' => ['higiene', 14], 'university' => ['higiene', 14], 'kindergarten' => ['higiene', 14],
    'clinic' => ['higiene', 18], 'hospital' => ['higiene', 22], 'doctors' => ['higiene', 10], 'dentist' => ['higiene', 8],
    // restauração e alojamento: massa, farinhas, arroz, óleo e limpeza
    'bakery' => ['alimentar', 22], 'pastry' => ['alimentar', 18], 'confectionery' => ['alimentar', 14], 'canteen' => ['ambos', 22],
    'restaurant' => ['ambos', 16], 'food_court' => ['ambos', 16], 'fast_food' => ['ambos', 12], 'meal_takeaway' => ['ambos', 12],
    'cafe' => ['ambos', 10], 'catering' => ['ambos', 22], 'hotel' => ['ambos', 22], 'lodging' => ['ambos', 18], 'guest_house' => ['ambos', 14],
    'hostel' => ['ambos', 12], 'motel' => ['ambos', 12],
    // alimentar especializado
    'beverages' => ['alimentar', 10], 'liquor_store' => ['alimentar', 8], 'alcohol' => ['alimentar', 8], 'deli' => ['alimentar', 12],
    'greengrocer' => ['alimentar', 8], 'butcher' => ['alimentar', 8], 'frozen_food' => ['alimentar', 10], 'bar' => ['alimentar', 6],
];

/**
 * Palavras-chave no nome (sem acentos, minúsculas) => [tipo que indicam, afinidade, bónus de volume]. A primeira que
 * aparece no nome manda. Linguagem do comércio de Luanda: «comércio geral», «armazém», «cantina», «cash».
 */
const NAVAL_DESC_PALAVRAS = [
    'cash and carry' => ['cash_and_carry', 'ambos', 10], 'cash & carry' => ['cash_and_carry', 'ambos', 10], 'grossista' => ['wholesale', 'ambos', 10],
    'armazem' => ['warehouse', 'ambos', 8], 'distribuid' => ['wholesale', 'ambos', 8], 'hipermercado' => ['hypermarket', 'ambos', 8],
    'supermercado' => ['supermarket', 'ambos', 6], 'minimercado' => ['convenience', 'ambos', 4], 'mini mercado' => ['convenience', 'ambos', 4],
    'mercearia' => ['grocery', 'ambos', 4], 'cantina' => ['general', 'ambos', 4], 'comercio geral' => ['general', 'ambos', 4],
    'comercial' => ['general', 'ambos', 2], 'mercado' => ['marketplace', 'ambos', 4], 'bazar' => ['variety_store', 'ambos', 2],
    'drogaria' => ['chemist', 'higiene', 6], 'higiene' => ['chemist', 'higiene', 6], 'limpeza' => ['chemist', 'higiene', 6],
    'detergente' => ['chemist', 'higiene', 6], 'lavandaria' => ['laundry', 'higiene', 4], 'utilidades' => ['houseware', 'higiene', 4],
    'panificadora' => ['bakery', 'alimentar', 8], 'padaria' => ['bakery', 'alimentar', 6], 'pastelaria' => ['pastry', 'alimentar', 4],
    'catering' => ['catering', 'ambos', 8], 'refeitorio' => ['canteen', 'ambos', 6], 'restaurante' => ['restaurant', 'ambos', 2],
    'hotel' => ['hotel', 'ambos', 4], 'hospedaria' => ['guest_house', 'ambos', 2], 'pensao' => ['guest_house', 'ambos', 2],
    'residencial' => ['guest_house', 'ambos', 2], 'colegio' => ['school', 'higiene', 2], 'escola' => ['school', 'higiene', 2],
    'clinica' => ['clinic', 'higiene', 4], 'hospital' => ['hospital', 'higiene', 4], 'centro medico' => ['clinic', 'higiene', 4],
];

/** Etiquetas do OpenStreetMap a ler (Overpass): chave => valores. */
const NAVAL_DESC_OSM = [
    'shop' => 'convenience|supermarket|kiosk|general|variety_store|beverages|chemist|cosmetics|wholesale|grocery|greengrocer|butcher|bakery|pastry|confectionery|alcohol|deli|frozen_food|houseware|hardware|department_store|discount|food|household_linen',
    'amenity' => 'bar|cafe|restaurant|fast_food|food_court|canteen|pharmacy|marketplace|school|college|university|kindergarten|clinic|hospital|doctors',
    'tourism' => 'hotel|guest_house|hostel|motel',
];

/** Tipos pedidos ao Google Places (API New, «Table A»), por ordem de interesse (máx. 50). */
const NAVAL_DESC_GOOGLE = ['supermarket', 'grocery_store', 'convenience_store', 'market', 'wholesaler', 'discount_store', 'department_store',
    'food_store', 'home_goods_store', 'drugstore', 'pharmacy', 'bakery', 'restaurant', 'hotel', 'lodging', 'school', 'hospital', 'liquor_store'];

/** Afinidades com o texto mostrado no portal. */
const NAVAL_DESC_AFINIDADES = ['ambos' => 'Higiene e limpeza + alimentar', 'alimentar' => 'Alimentar', 'higiene' => 'Higiene e limpeza'];

/** Área de trabalho: GEO_AREA («sul,oeste,norte,leste») ou Luanda + Ícolo e Bengo. */
function naval_desc_area(): array
{
    $v = array_map('trim', explode(',', (string) naval_config('GEO_AREA', '')));
    if (count($v) === 4 && count(array_filter($v, 'is_numeric')) === 4) {
        $a = array_map('floatval', $v);
        if ($a[0] < $a[2] && $a[1] < $a[3]) return $a;
    }
    return NAVAL_DESC_AREA;
}

function naval_desc_na_area(float $lat, float $lng, ?array $area = null): bool
{
    $a = $area ?? naval_desc_area();
    return $lat >= $a[0] && $lat <= $a[2] && $lng >= $a[1] && $lng <= $a[3];
}

/** Texto sem acentos, em minúsculas e com espaços simples (para palavras-chave e comparação de nomes). */
function naval_desc_simples(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    return (string) preg_replace('/\s+/', ' ', $s);
}

/** Telefone em 9 dígitos (Angola, sem o 244); '' se não parecer um número válido. */
function naval_desc_telefone(string $t): string
{
    $d = (string) preg_replace('/\D/', '', $t);
    if (strlen($d) === 12 && strncmp($d, '244', 3) === 0) $d = substr($d, 3);
    if (strlen($d) === 14 && strncmp($d, '00244', 5) === 0) $d = substr($d, 5);
    return preg_match('/^[29]\d{8}$/', $d) ? $d : '';
}

/** Sítio sem esquema, «www.» e barra final ('' se não for um endereço). */
function naval_desc_sitio(string $u): string
{
    $u = strtolower(trim($u));
    $u = (string) preg_replace('#^https?://#', '', $u);
    $u = (string) preg_replace('#^www\.#', '', $u);
    $u = rtrim((string) strtok($u, '?#'), '/');
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(\/\S*)?$/', $u) ? substr($u, 0, 120) : '';
}

/**
 * Classifica um estabelecimento: tipo final, afinidade e bónus (pelas palavras do nome, que podem corrigir um tipo
 * genérico como «store» ou vazio). null se nada o liga ao portfólio.
 */
function naval_desc_classificar(string $tipo, string $nome): ?array
{
    $n = naval_desc_simples($nome);
    $pal = null;
    if ($n !== '') foreach (NAVAL_DESC_PALAVRAS as $k => $x) if (strpos($n, $k) !== false) { $pal = $x; break; }
    $t = NAVAL_DESC_TIPOS[$tipo] ?? null;
    if ($t === null && $pal === null) return null;
    if ($pal !== null && ($t === null || $tipo === 'store' || $t[1] < NAVAL_DESC_TIPOS[$pal[0]][1])) {
        // o nome diz mais do que o tipo: «Armazém X» marcado como loja genérica é um armazém
        return ['t' => $pal[0], 'a' => $pal[1], 'b' => $pal[2]];
    }
    return ['t' => $tipo, 'a' => (string) $t[0], 'b' => $pal[2] ?? 0];
}

/**
 * Regras de qualidade de um estabelecimento já classificado: devolve '' se é aceite ou o motivo da recusa.
 */
function naval_desc_qualidade(array $p, ?array $area = null): string
{
    if (!isset($p['lat'], $p['lng']) || !is_numeric($p['lat']) || !is_numeric($p['lng'])) return 'sem coordenadas';
    $la = (float) $p['lat'];
    $lo = (float) $p['lng'];
    if (abs($la) < 0.0001 || abs($lo) < 0.0001) return 'coordenadas nulas';
    if (!naval_desc_na_area($la, $lo, $area)) return 'fora de Luanda e Ícolo e Bengo';
    $n = trim((string) ($p['n'] ?? ''));
    if ($n !== '' && (mb_strlen($n, 'UTF-8') < 2 || !preg_match('/\pL/u', $n))) return 'nome inválido';
    if (naval_desc_classificar((string) ($p['t'] ?? ''), $n) === null) return 'fora do portfólio (higiene, limpeza e alimentar)';
    if ($n === '' && (NAVAL_DESC_TIPOS[(string) ($p['t'] ?? '')][1] ?? 0) < 18) return 'sem nome e tipo de pouco volume';
    return '';
}

/**
 * ICP Score (0 a 100) de um potencial cliente:
 *   adequação ao portfólio (ambos 25, uma linha 18), potencial de volume (tipo 0 a 30 + palavras do nome até 10),
 *   contacto válido (telefone 6, sítio 2), confiança das fontes (2 ou mais 15, Google 10, externa 8, OpenStreetMap 6)
 *   e viabilidade na zona: distância ao cliente da rota mais perto (≤ 300 m 15, ≤ 800 m 9, ≤ 1,5 km 4) e outros
 *   estabelecimentos perto (zona comercial, até 6).
 * Devolve [s, afinidade, tipo].
 */
function naval_desc_icp(array $p, ?int $distCliente, int $densidade): array
{
    $c = naval_desc_classificar((string) ($p['t'] ?? ''), (string) ($p['n'] ?? '')) ?? ['t' => (string) ($p['t'] ?? ''), 'a' => 'ambos', 'b' => 0];
    $f = (array) ($p['f'] ?? []);
    $s = ($c['a'] === 'ambos' ? 25 : 18)
        + (NAVAL_DESC_TIPOS[$c['t']][1] ?? 8) + min(10, (int) $c['b'])
        + (($p['tel'] ?? '') !== '' ? 6 : 0) + (($p['web'] ?? '') !== '' ? 2 : 0)
        + (count($f) > 1 ? 15 : (in_array('google', $f, true) ? 10 : (preg_grep('/^x:/', $f) ? 8 : 6)))
        + ($distCliente === null ? 0 : ($distCliente <= 300 ? 15 : ($distCliente <= 800 ? 9 : ($distCliente <= 1500 ? 4 : 0))))
        + min(6, max(0, $densidade) * 2);
    return [min(100, $s), $c['a'], $c['t']];
}

// ── Entrada externa (webhook) ───────────────────────────────────────────────────────────────────────────────────

function naval_desc_externos_ficheiro(): string
{
    return naval_pasta('geo') . '/externos.json';
}

/**
 * Normaliza um item recebido pelo webhook. Campos: nome, lat, lng, categoria (tipo da taxonomia ou do Google/OSM),
 * telefone, website, morada, id_externo. Devolve [ponto, ''] ou [null, motivo].
 */
function naval_desc_normalizar_externo(array $x, string $fonte): array
{
    $lat = $x['lat'] ?? $x['latitude'] ?? null;
    $lng = $x['lng'] ?? $x['longitude'] ?? $x['lon'] ?? null;
    $nome = naval_texto((string) ($x['nome'] ?? $x['name'] ?? ''), 80);
    $cat = strtolower(trim((string) ($x['categoria'] ?? $x['tipo'] ?? $x['category'] ?? '')));
    $cat = (string) preg_replace('/[^a-z_]/', '_', $cat);
    $p = ['n' => $nome, 't' => $cat, 'lat' => is_numeric($lat) ? round((float) $lat, 6) : null, 'lng' => is_numeric($lng) ? round((float) $lng, 6) : null,
        'tel' => naval_desc_telefone((string) ($x['telefone'] ?? $x['phone'] ?? '')), 'web' => naval_desc_sitio((string) ($x['website'] ?? $x['site'] ?? '')),
        'mor' => naval_texto((string) ($x['morada'] ?? $x['address'] ?? ''), 120)];
    $motivo = naval_desc_qualidade($p);
    if ($motivo !== '') return [null, $motivo];
    $c = (array) naval_desc_classificar($cat, $nome);
    $p['t'] = (string) $c['t'];
    $ref = trim((string) ($x['id_externo'] ?? $x['id'] ?? ''));
    $chave = $ref !== '' ? $fonte . '|' . $ref : $fonte . '|' . naval_desc_simples($nome) . '|' . round((float) $p['lat'], 4) . '|' . round((float) $p['lng'], 4);
    // nosemgrep: weak-crypto -- resumo não criptográfico (identificador/versão), sem uso de segurança
    $p['id'] = 'x:' . substr(sha1($chave), 0, 16);
    $p['f'] = ['x:' . $fonte];
    return [$p, ''];
}

/**
 * Recebe uma lista de estabelecimentos de uma fonte externa (até 500 por pedido; até GEO_EXTERNOS_MAX guardados).
 * Devolve {recebidos, aceites, novos, rejeitados: [{i, motivo}]} e pede um novo cálculo da prospecção.
 */
function naval_desc_receber(array $itens, string $fonte, ?int $agora = null): array
{
    $agora = $agora ?? time();
    $fonte = substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower($fonte)), 0, 30) ?: 'externa';
    if (count($itens) > 500) throw new NavalErro('No máximo 500 estabelecimentos por pedido.', 413);
    $ok = [];
    $rej = [];
    foreach (array_values($itens) as $i => $x) {
        if (!is_array($x)) { $rej[] = ['i' => $i, 'motivo' => 'item inválido']; continue; }
        [$p, $m] = naval_desc_normalizar_externo($x, $fonte);
        if ($p === null) $rej[] = ['i' => $i, 'motivo' => $m];
        else $ok[$p['id']] = $p;
    }
    $novos = naval_exclusivo('geo-externos', function () use ($ok, $agora) {
        $f = naval_desc_externos_ficheiro();
        $e = naval_json_ler($f);
        $dias = (int) naval_config('GEO_EXTERNOS_DIAS', '90');
        foreach ($e as $id => $p) if (($p['visto'] ?? 0) < $agora - $dias * 86400) unset($e[$id]);
        $n = 0;
        foreach ($ok as $id => $p) {
            if (!isset($e[$id])) $n++;
            $e[$id] = $p + ['visto' => $agora];
            $e[$id]['visto'] = $agora;
        }
        $max = (int) naval_config('GEO_EXTERNOS_MAX', '20000');
        if (count($e) > $max) {
            uasort($e, function ($a, $b) { return $b['visto'] <=> $a['visto']; });
            $e = array_slice($e, 0, $max, true);
        }
        naval_json_gravar($f, $e, false);
        return $n;
    });
    if ($ok) naval_json_gravar(naval_pasta('geo') . '/recalcular.json', ['pedido' => $agora, 'fonte' => $fonte, 'novos' => $novos], false);
    return ['recebidos' => count($itens), 'aceites' => count($ok), 'novos' => $novos, 'rejeitados' => array_slice($rej, 0, 100)];
}

/** Estabelecimentos das fontes externas no formato comum das fontes. */
function naval_desc_externos_pontos(): array
{
    $out = [];
    foreach (naval_json_ler(naval_desc_externos_ficheiro()) as $id => $p) {
        if (!is_array($p) || !isset($p['lat'], $p['lng'])) continue;
        $out[] = ['id' => (string) $id, 'n' => (string) ($p['n'] ?? ''), 't' => (string) ($p['t'] ?? ''), 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
            'f' => (array) ($p['f'] ?? ['x:externa'])] + array_filter(['tel' => (string) ($p['tel'] ?? ''), 'web' => (string) ($p['web'] ?? ''), 'mor' => (string) ($p['mor'] ?? '')]);
    }
    return $out;
}

/** Pedido de novo cálculo pendente (entrada externa há mais de 10 minutos, para juntar envios seguidos). */
function naval_desc_recalculo_pendente(int $agora, string $ultimoCalculo): bool
{
    $r = naval_json_ler(naval_pasta('geo') . '/recalcular.json');
    $pedido = (int) ($r['pedido'] ?? 0);
    return $pedido > 0 && $pedido <= $agora - 600 && date('Y-m-d H:i:s', $pedido) > $ultimoCalculo;
}
