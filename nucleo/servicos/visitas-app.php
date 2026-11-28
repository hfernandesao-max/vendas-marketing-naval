<?php
/**
 * Mapa de clientes ao vivo: um ponto por cliente com o estado do dia (com venda, com encomenda, em curso, recusa,
 * visitado sem venda), juntando as três fontes como no guia «Mapa de clientes em tempo real»:
 *
 *   - aplicação móvel (MariaDB prd1337, ligação BOAPP_*, sessão READ ONLY): as visitas de hoje (outcome) com o
 *     check-in/check-out, o GPS e a distância ao cliente, o resultado, o motivo e o documento (id_sell);
 *   - Pinguim BO (SQL Server, só SELECT, sem bloqueios): o tipo de cada documento (id_sell = DOCHEADER.ID) e os
 *     clientes com venda hoje sem visita registada (ex.: entregas);
 *   - GPS dos clientes já guardado pela prospecção (clientes-AAAA-MM-DD.json, ENTITY.ID): posição de quem não tem
 *     GPS de visita. Nenhuma consulta nova para isto.
 *
 * Chaves: customer.pingwin_id = ENTITY.ID (texto) · outcome.id_sell = DOCHEADER.ID (texto). Ids da aplicação são
 * inteiros; os do Pinguim BO comparam-se sempre como texto.
 *
 * Leitura: incremental por outcome.updated_at (marca − 2 min) e completa do dia por outcome.start_date de 30 em 30
 * minutos (apanha visitas apagadas e mudanças de dia). Nunca mais do que uma leitura das fontes a cada 2 minutos,
 * seja quem for que a peça (agendador ou páginas): o resto lê o ficheiro guardado.
 * Erros da ligação à aplicação registam só o tipo e o código da excepção, nunca a mensagem (pode trazer o servidor
 * ou o utilizador).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';

const NAVAL_VAPP_INTERVALO = 110;          // segundos entre leituras das fontes (≈ 2 min)
const NAVAL_VAPP_COMPLETA = 1800;          // leitura completa do dia de 30 em 30 minutos
const NAVAL_VAPP_RECUO = 120;              // a leitura incremental recua 2 min da marca (relógios e transacções)
const NAVAL_VAPP_LOTE = 500;               // clientes da aplicação lidos em lotes de 500 pela chave primária
const NAVAL_VAPP_RAIO_M = 50;              // presencial: visita a menos de 50 m do cliente (VISITAS_RAIO_PRESENCIAL_M)
const NAVAL_VAPP_TIPOS_VENDA = ['FR', 'FT', 'FA'];

// Estados do cliente no dia e a prioridade quando há várias visitas ou documentos (o primeiro ganha)
const NAVAL_VAPP_VENDA = 1;
const NAVAL_VAPP_ENCOMENDA = 3;
const NAVAL_VAPP_EM_CURSO = 2;
const NAVAL_VAPP_RECUSA = 0;
const NAVAL_VAPP_SEM_VENDA = 4;
const NAVAL_VAPP_PRIORIDADE = [NAVAL_VAPP_VENDA, NAVAL_VAPP_ENCOMENDA, NAVAL_VAPP_EM_CURSO, NAVAL_VAPP_RECUSA, NAVAL_VAPP_SEM_VENDA];
const NAVAL_VAPP_NOMES = [NAVAL_VAPP_VENDA => 'Com venda', NAVAL_VAPP_ENCOMENDA => 'Com encomenda', NAVAL_VAPP_EM_CURSO => 'Em curso',
    NAVAL_VAPP_RECUSA => 'Recusa', NAVAL_VAPP_SEM_VENDA => 'Visitado sem venda'];

function naval_vapp_configurado(): bool
{
    return naval_config('BOAPP_DSN') !== '' || naval_config('BOAPP_HOST') !== '';
}

/** Tipos de documento do Pinguim BO que são encomenda (não venda): VISITAS_TIPOS_ENCOMENDA=ENC,ENCO2 (vazio por omissão). */
function naval_vapp_tipos_encomenda(): array
{
    $t = array_filter(array_map(function ($x) { return strtoupper(trim($x)); }, explode(',', naval_config('VISITAS_TIPOS_ENCOMENDA', ''))));
    return array_values(array_filter($t, function ($x) { return (bool) preg_match('/^[A-Z0-9]{1,10}$/', $x); }));
}

function naval_vapp_pasta(): string
{
    return naval_pasta('visitas-app');
}

// ── Funções puras (testadas) ──────────────────────────────────────────────────────────────────────────────────────

/** Coordenada válida (texto com vírgula aceite); 0, vazio ou fora de Angola = null. */
function naval_vapp_coord($la, $lo): ?array
{
    $f = function ($v) {
        if ($v === null || is_bool($v)) return null;
        $s = str_replace(',', '.', trim((string) $v));
        return is_numeric($s) ? (float) $s : null;
    };
    $a = $f($la);
    $o = $f($lo);
    if ($a === null || $o === null || ($a == 0 && $o == 0)) return null;
    if ($a < -18.1 || $a > -4.3 || $o < 11.6 || $o > 24.1) return null;
    return [round($a, 6), round($o, 6)];
}

function naval_vapp_dist_m(array $a, array $b): float
{
    $r = 6371000.0;
    $p1 = deg2rad($a[0]);
    $p2 = deg2rad($b[0]);
    $dp = $p2 - $p1;
    $dl = deg2rad($b[1] - $a[1]);
    $h = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($h)));
}

/** HH:MM de uma hora da aplicação (TIME, DATETIME ou texto); '' sem hora. */
function naval_vapp_hora($v): string
{
    if ($v === null) return '';
    return preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', trim((string) $v), $m) ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2] : '';
}

/**
 * Presença de uma visita (D105 do guia): 'p' presencial (< raio), 'r' remota, 'g' sem GPS. Com o GPS da visita e do
 * cliente, a distância calcula-se aqui (a coluna distance da aplicação está em km arredondados ao inteiro: 186 m
 * ficam «0», não serve para os 50 m; e mede até (0, 0) quando o cliente não tem coordenadas). Sem o GPS do cliente,
 * vale o tipo de check-in que a aplicação guardou (checkin_type: Presencial | Remota). Devolve [presença, metros|null].
 */
function naval_vapp_presenca(?array $visita, ?array $cliente, string $checkinTipo = '', float $raioM = NAVAL_VAPP_RAIO_M): array
{
    if ($visita !== null && $cliente !== null) {
        $m = naval_vapp_dist_m($visita, $cliente);
        return [$m < $raioM ? 'p' : 'r', (int) round($m)];
    }
    $t = mb_strtolower(trim($checkinTipo), 'UTF-8');
    if ($t === 'presencial') return ['p', null];
    if ($t === 'remota') return ['r', null];
    return ['g', null];
}

/**
 * Estado de uma visita (D121 do guia): em curso (check-in sem check-out, sem resultado e sem venda), com venda ou com
 * encomenda (pelo documento no Pinguim BO; com id_sell e valor, ainda sem o documento lido = venda por confirmar),
 * recusa (resultado «Recusa com Motivo») ou visitado sem venda. $docs: [DOCHEADER.ID texto => ['tipo' => …]].
 */
function naval_vapp_estado_visita(array $v, array $docs, array $tiposEncomenda): array
{
    $idSell = trim((string) ($v['id_sell'] ?? ''));
    $result = trim((string) ($v['result'] ?? ''));
    $total = (float) ($v['total_sell'] ?? 0);
    $d = ($idSell !== '' && $idSell !== '0') ? ($docs[$idSell] ?? null) : null;
    if ($d !== null) {
        $tipo = strtoupper((string) $d['tipo']);
        if (in_array($tipo, $tiposEncomenda, true)) return [NAVAL_VAPP_ENCOMENDA, false];
        if (in_array($tipo, NAVAL_VAPP_TIPOS_VENDA, true)) return [NAVAL_VAPP_VENDA, false];
    } elseif (strcasecmp($result, 'Venda') === 0 || ($idSell !== '' && $idSell !== '0' && $total > 0)) {
        // a aplicação registou a venda e o documento ainda não chegou ao Pinguim BO (ou não foi lido): por confirmar
        return [NAVAL_VAPP_VENDA, true];
    }
    if (naval_vapp_hora($v['end_hour'] ?? null) === '' && $result === '' && $total == 0) return [NAVAL_VAPP_EM_CURSO, false];
    if (stripos($result, 'recusa') !== false) return [NAVAL_VAPP_RECUSA, false];
    return [NAVAL_VAPP_SEM_VENDA, false];
}

/**
 * Junta as linhas lidas da aplicação ao estado guardado do dia. Leitura completa: as linhas do dia substituem
 * tudo. Incremental: cada linha actualiza a sua visita; apagada (deleted = 1) ou mudada para outro dia sai.
 * Devolve o estado com a marca (maior updated_at visto).
 */
function naval_vapp_fundir(array $estado, array $linhas, string $dia, bool $completa): array
{
    $vis = $completa ? [] : (array) ($estado['visitas'] ?? []);
    $marca = (string) ($estado['marca'] ?? '');
    foreach ($linhas as $l) {
        $id = (string) (int) ($l['id'] ?? 0);
        if ($id === '0') continue;
        $u = (string) ($l['updated_at'] ?? '');
        if ($u > $marca) $marca = $u;
        $diaLinha = substr((string) ($l['start_date'] ?? ''), 0, 10);
        if ((int) ($l['deleted'] ?? 0) === 1 || $diaLinha !== $dia) {
            unset($vis[$id]);
            continue;
        }
        $vis[$id] = [
            'id_sell' => trim((string) ($l['id_sell'] ?? '')),
            'customer_id' => (int) ($l['customer_id'] ?? 0),
            'route_id' => (int) ($l['original_route_id'] ?? 0),
            'motive_id' => (int) ($l['motive_id'] ?? 0),
            'result' => trim((string) ($l['result'] ?? '')),
            'total_sell' => (float) ($l['total_sell'] ?? 0),
            'quant_sell' => (float) ($l['quant_sell'] ?? 0),
            'lat' => $l['lat'] ?? null,
            'lng' => $l['lng'] ?? null,
            'checkin_type' => trim((string) ($l['checkin_type'] ?? '')),
            'start_hour' => naval_vapp_hora($l['start_hour'] ?? null),
            'end_hour' => naval_vapp_hora($l['end_hour'] ?? null),
        ];
    }
    return ['visitas' => $vis, 'marca' => $marca] + $estado;
}

/** Documento do Pinguim BO de cliente eventual / consumidor final (fica fora do mapa e conta-se à parte). */
function naval_vapp_eventual(array $d): bool
{
    $nif = preg_replace('/\D/', '', (string) ($d['nif'] ?? ''));
    $nome = mb_strtolower(trim((string) ($d['nome'] ?? '')), 'UTF-8');
    return $nif === '999999999' || $nome === '' || strpos($nome, 'consumidor final') !== false || strpos($nome, 'cliente eventual') !== false;
}

/**
 * Mapa de clientes do dia (função pura). Um ponto por cliente: chave = pingwin_id (texto); sem pingwin_id,
 * 'app:<customer_id>' (contado à parte). Os documentos de venda/encomenda do Pinguim BO de hoje juntam-se pela
 * mesma chave (ENTITY_ID); os de cliente eventual ficam de fora (contados). Posição: GPS da última visita; sem ele,
 * GPS do cliente (Pinguim BO ou aplicação); sem nenhum, o cliente conta como «sem GPS» e não se desenha.
 *
 * $estado: ['visitas' => [id => …], 'clientes' => [app_id => [code, name, lat, lng, pingwin_id]],
 *           'rotas' => [app_id => [code, name, pingwin_id]], 'motivos' => [id => title]]
 * $docs: [DOCHEADER.ID => ['tipo', 'cliente' (ENTITY_ID), 'nome', 'nif', 'rota', 'hora', 'total']]
 * $gps: [ENTITY_ID => [lat, lng, nome]]
 *
 * Ponto: {k, n, c, r, lat, lng, og 'v'|'c', e, vis, h, vv, ve, cx, m, p, dm, pend, sv}
 */
function naval_vapp_montar(array $estado, array $docs, array $gps, array $tiposEncomenda = [], float $raioM = NAVAL_VAPP_RAIO_M): array
{
    $clientesApp = (array) ($estado['clientes'] ?? []);
    $rotasApp = (array) ($estado['rotas'] ?? []);
    $motivos = (array) ($estado['motivos'] ?? []);
    $ordem = array_flip(NAVAL_VAPP_PRIORIDADE);
    $pts = [];
    $semPingwin = 0;
    $usados = [];
    $visitas = (array) ($estado['visitas'] ?? []);
    // pela hora de início: a última visita fica por cima (posição, presença, motivo da última recusa)
    uasort($visitas, function ($a, $b) { return strcmp((string) $a['start_hour'], (string) $b['start_hour']); });
    foreach ($visitas as $id => $v) {
        $cli = $clientesApp[(string) $v['customer_id']] ?? null;
        $pw = $cli !== null ? trim((string) ($cli[4] ?? '')) : '';
        if ($pw === '0') $pw = '';
        $k = $pw !== '' ? $pw : 'app:' . (int) $v['customer_id'];
        if ($pw === '' && !isset($pts[$k])) $semPingwin++;
        [$e, $pend] = naval_vapp_estado_visita($v, $docs, $tiposEncomenda);
        $posV = naval_vapp_coord($v['lat'], $v['lng']);
        $posC = ($pw !== '' && isset($gps[$pw])) ? naval_vapp_coord($gps[$pw][0], $gps[$pw][1]) : null;
        if ($posC === null && $cli !== null) $posC = naval_vapp_coord($cli[2] ?? null, $cli[3] ?? null);
        [$p, $dm] = naval_vapp_presenca($posV, $posC, (string) ($v['checkin_type'] ?? ''), $raioM);
        $rota = $rotasApp[(string) $v['route_id']] ?? null;
        $x = $pts[$k] ?? ['k' => $k, 'n' => $cli !== null ? naval_texto((string) $cli[1], 80) : ('Cliente ' . (int) $v['customer_id']),
            'c' => $cli !== null ? (string) $cli[0] : '', 'r' => '', 'lat' => null, 'lng' => null, 'og' => '', 'e' => NAVAL_VAPP_SEM_VENDA, 'vis' => 0,
            'h' => '', 'vv' => 0.0, 've' => 0.0, 'cx' => 0.0, 'm' => '', 'p' => 'g', 'dm' => null, 'pend' => false, 'sv' => false, '_ord' => 99];
        $x['vis']++;
        $x['h'] = max($x['h'], $v['end_hour'] !== '' ? $v['end_hour'] : $v['start_hour']);
        if ($rota !== null) $x['r'] = (string) $rota[0];
        $pos = $posV ?? $posC;
        if ($pos !== null) {
            $x['lat'] = $pos[0];
            $x['lng'] = $pos[1];
            $x['og'] = $posV !== null ? 'v' : 'c';
        }
        $x['p'] = $p;
        $x['dm'] = $dm;
        if ($e === NAVAL_VAPP_RECUSA) $x['m'] = (string) ($motivos[(string) $v['motive_id']] ?? '');
        if ($e === NAVAL_VAPP_VENDA) {
            $doc = $docs[$v['id_sell']] ?? null;
            if ($doc !== null) $usados[$v['id_sell']] = true;
            $x['vv'] += $doc !== null ? (float) $doc['total'] : (float) $v['total_sell'];
            $x['cx'] += (float) $v['quant_sell'];
            if ($pend) $x['pend'] = true;
        } elseif ($e === NAVAL_VAPP_ENCOMENDA) {
            $doc = $docs[$v['id_sell']] ?? null;
            if ($doc !== null) $usados[$v['id_sell']] = true;
            $x['ve'] += $doc !== null ? (float) $doc['total'] : (float) $v['total_sell'];
        }
        if ($ordem[$e] < $x['_ord']) {
            $x['_ord'] = $ordem[$e];
            $x['e'] = $e;
        }
        $pts[$k] = $x;
    }
    // documentos do Pinguim BO sem visita registada (entregas, vendas fora da aplicação)
    $eventuais = 0;
    foreach ($docs as $id => $d) {
        if (isset($usados[(string) $id])) continue;
        $tipo = strtoupper((string) $d['tipo']);
        $enc = in_array($tipo, $tiposEncomenda, true);
        if (!$enc && !in_array($tipo, NAVAL_VAPP_TIPOS_VENDA, true)) continue;
        if (naval_vapp_eventual($d)) {
            $eventuais++;
            continue;
        }
        $k = trim((string) $d['cliente']);
        if ($k === '') continue;
        $x = $pts[$k] ?? null;
        if ($x === null) {
            $pos = isset($gps[$k]) ? naval_vapp_coord($gps[$k][0], $gps[$k][1]) : null;
            $x = ['k' => $k, 'n' => naval_texto((string) ($d['nome'] ?? ($gps[$k][2] ?? $k)), 80), 'c' => $k, 'r' => (string) ($d['rota'] ?? ''),
                'lat' => $pos[0] ?? null, 'lng' => $pos[1] ?? null, 'og' => $pos !== null ? 'c' : '', 'e' => NAVAL_VAPP_SEM_VENDA, 'vis' => 0,
                'h' => '', 'vv' => 0.0, 've' => 0.0, 'cx' => 0.0, 'm' => '', 'p' => 'g', 'dm' => null, 'pend' => false, 'sv' => true, '_ord' => 99];
        }
        $x['h'] = max($x['h'], (string) ($d['hora'] ?? ''));
        $e = $enc ? NAVAL_VAPP_ENCOMENDA : NAVAL_VAPP_VENDA;
        if ($enc) $x['ve'] += (float) $d['total'];
        else $x['vv'] += (float) $d['total'];
        if ($ordem[$e] < $x['_ord']) {
            $x['_ord'] = $ordem[$e];
            $x['e'] = $e;
        }
        $pts[$k] = $x;
    }
    $out = [];
    $semGps = 0;
    $tot = ['clientes' => 0, 'visitas' => 0, 'valor' => 0.0, 'encomendado' => 0.0, 'caixas' => 0.0, 'remotas' => 0, 'sem_visita' => 0,
        'estados' => array_fill_keys(array_map('strval', NAVAL_VAPP_PRIORIDADE), 0)];
    foreach ($pts as $x) {
        unset($x['_ord']);
        $x['vv'] = round($x['vv'], 2);
        $x['ve'] = round($x['ve'], 2);
        $x['cx'] = round($x['cx'], 3);
        $tot['clientes']++;
        $tot['visitas'] += $x['vis'];
        $tot['valor'] += $x['vv'];
        $tot['encomendado'] += $x['ve'];
        $tot['caixas'] += $x['cx'];
        $tot['estados'][(string) $x['e']]++;
        if ($x['p'] === 'r') $tot['remotas']++;
        if ($x['sv']) $tot['sem_visita']++;
        if ($x['lat'] === null) $semGps++;
        $out[] = $x;
    }
    usort($out, function ($a, $b) { return strcmp($b['h'], $a['h']) ?: strcmp($a['k'], $b['k']); });
    $tot['valor'] = round($tot['valor'], 2);
    $tot['encomendado'] = round($tot['encomendado'], 2);
    $tot['caixas'] = round($tot['caixas'], 3);
    $tot['sem_gps'] = $semGps;
    $tot['sem_pingwin'] = $semPingwin;
    $tot['eventuais'] = $eventuais;
    return ['pontos' => $out, 'totais' => $tot, 'versao' => substr(md5((string) json_encode($out)), 0, 16)];
}

/** Só as rotas do âmbito (null = todas), com os totais refeitos; sem valores para quem não vê vendas. */
function naval_vapp_ambito(array $mapa, ?array $rotas, bool $veValores): array
{
    $pts = $mapa['pontos'] ?? [];
    if ($rotas !== null) $pts = array_values(array_filter($pts, function ($p) use ($rotas) { return in_array((string) $p['r'], $rotas, true); }));
    if (!$veValores) $pts = array_map(function ($p) { $p['vv'] = null; $p['ve'] = null; return $p; }, $pts);
    if ($rotas === null && $veValores) return $mapa;
    $tot = ['clientes' => count($pts), 'visitas' => 0, 'valor' => $veValores ? 0.0 : null, 'encomendado' => $veValores ? 0.0 : null, 'caixas' => 0.0,
        'remotas' => 0, 'sem_visita' => 0, 'estados' => array_fill_keys(array_map('strval', NAVAL_VAPP_PRIORIDADE), 0), 'sem_gps' => 0,
        'sem_pingwin' => $rotas === null ? (int) ($mapa['totais']['sem_pingwin'] ?? 0) : null, 'eventuais' => $rotas === null ? (int) ($mapa['totais']['eventuais'] ?? 0) : null];
    foreach ($pts as $p) {
        $tot['visitas'] += $p['vis'];
        if ($veValores) {
            $tot['valor'] += $p['vv'];
            $tot['encomendado'] += $p['ve'];
        }
        $tot['caixas'] += $p['cx'];
        $tot['estados'][(string) $p['e']]++;
        if ($p['p'] === 'r') $tot['remotas']++;
        if ($p['sv']) $tot['sem_visita']++;
        if ($p['lat'] === null) $tot['sem_gps']++;
    }
    return ['pontos' => $pts, 'totais' => $tot, 'versao' => substr(md5((string) json_encode($pts)), 0, 16)] + $mapa;
}

// ── Leitura das fontes (só leitura) ──────────────────────────────────────────────────────────────────────────────

/** Ligação à aplicação: sessão só de leitura, sem esperar por bloqueios, no fuso de Luanda. */
function naval_vapp_pdo(): PDO
{
    $pdo = naval_bd_criar('BOAPP');
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec("SET time_zone = '+01:00'");
    return $pdo;
}

/** Visitas lidas da aplicação: completas do dia (start_date) ou alteradas desde a marca (updated_at). */
function naval_vapp_ler_visitas(PDO $pdo, string $dia, bool $completa, string $marca): array
{
    $cols = 'id, id_sell, customer_id, original_route_id, motive_id, result, total_sell, quant_sell, lat, lng, checkin_type,
             start_date, start_hour, end_hour, deleted, updated_at';
    if ($completa) {
        return naval_bd_consultar_em($pdo, "SELECT $cols FROM outcome WHERE start_date >= :d0 AND start_date < :d1",
            [':d0' => $dia, ':d1' => date('Y-m-d', strtotime($dia . ' 12:00') + 86400)]);
    }
    $desde = date('Y-m-d H:i:s', strtotime($marca) - NAVAL_VAPP_RECUO);
    return naval_bd_consultar_em($pdo, "SELECT $cols FROM outcome WHERE updated_at >= :m", [':m' => $desde]);
}

/** Clientes da aplicação ainda não guardados (lotes pela chave primária): [id => [code, name, lat, lng, pingwin_id]]. */
function naval_vapp_ler_clientes(PDO $pdo, array $ids): array
{
    $o = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), NAVAL_VAPP_LOTE) as $lote) {
        $lote = array_filter($lote);
        if (!$lote) continue;
        foreach (naval_bd_consultar_em($pdo, 'SELECT id, code, name, lat, lng, pingwin_id FROM customer WHERE id IN (' . implode(',', $lote) . ')') as $c) {
            $o[(string) (int) $c['id']] = [trim((string) $c['code']), trim((string) $c['name']), $c['lat'], $c['lng'], trim((string) ($c['pingwin_id'] ?? ''))];
        }
    }
    return $o;
}

/** Documentos de venda (e encomenda) do Pinguim BO no dia, para o tipo de cada id_sell e as vendas sem visita. */
function naval_vapp_docs_pinguim(string $dia, array $tiposEncomenda): array
{
    naval_servico('vendas');
    $tipos = array_values(array_unique(array_merge(NAVAL_VAPP_TIPOS_VENDA, $tiposEncomenda)));
    $sql = "
        SELECT DH.ID AS DOC_ID, DC.CODE AS TIPO, DH.ENTITY_ID AS CLIENTE, DH.ENTITY_NAME AS NOME, DH.ENTITY_TAXNUM AS NIF,
               COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA,
               CASE WHEN DH.CLOCK_DATE IS NOT NULL THEN CONVERT(VARCHAR(5), DH.CLOCK_DATE, 108) ELSE '' END AS HORA,
               CAST(DH.CALC_TOTAL_W_TAX AS FLOAT) AS TOTAL
        FROM DOCHEADER DH WITH (NOLOCK)
        INNER JOIN DOCCONFIG DC WITH (NOLOCK) ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S WITH (NOLOCK) ON S.ID = DH.STORE_ID
        WHERE " . naval_vendas_condicao_validos() . "
          AND DC.CODE IN ('" . implode("', '", $tipos) . "')
          AND DH.DOC_DATE >= :de AND DH.DOC_DATE < :ate";
    $o = [];
    foreach (naval_bd_consultar($sql, [':de' => naval_bd_data($dia), ':ate' => naval_bd_data(date('Y-m-d', strtotime($dia . ' 12:00') + 86400))]) as $d) {
        $o[(string) $d['DOC_ID']] = ['tipo' => trim((string) $d['TIPO']), 'cliente' => trim((string) $d['CLIENTE']), 'nome' => naval_bd_utf8((string) $d['NOME']),
            'nif' => trim((string) $d['NIF']), 'rota' => trim((string) $d['ROTA']), 'hora' => (string) $d['HORA'], 'total' => round((float) $d['TOTAL'], 2)];
    }
    return $o;
}

/** GPS dos clientes do Pinguim BO já guardado pela prospecção (o ficheiro mais recente): [ENTITY_ID => [lat, lng, nome]]. */
function naval_vapp_gps_clientes(): array
{
    naval_servico('prospeccao');
    $fs = glob(naval_geo_pasta() . '/clientes-*.json') ?: [];
    rsort($fs);
    $o = [];
    foreach ((array) (($fs ? naval_json_ler($fs[0]) : [])['clientes'] ?? []) as $c) $o[(string) $c['c']] = [$c['lat'], $c['lng'], (string) ($c['n'] ?? '')];
    return $o;
}

/** Registo de uma falha sem a mensagem (pode trazer o servidor, a base ou o utilizador). */
function naval_vapp_registar_falha(string $onde, Throwable $e): string
{
    $t = get_class($e) . ' ' . (string) $e->getCode();
    error_log('[visitas app] ' . $onde . ': ' . $t);
    return $t;
}

/**
 * Lê as fontes (no máximo uma vez a cada NAVAL_VAPP_INTERVALO segundos) e grava o estado e o mapa do dia.
 * Devolve o mapa guardado (com 'atualizado_em', 'erro', 'ms'). Só para hoje: os dias anteriores ficam como foram
 * gravados no fim do dia.
 */
function naval_vapp_actualizar(?int $agora = null, bool $forcar = false): array
{
    $agora = $agora ?? time();
    $dia = date('Y-m-d', $agora);
    $fMapa = naval_vapp_pasta() . '/mapa-' . $dia . '.json';
    if (!naval_vapp_configurado()) return ['erro' => 'A ligação à aplicação móvel (BOAPP_* no .env) não está configurada.'] + (naval_json_ler($fMapa) ?: []);
    return naval_exclusivo('visitas-app', function () use ($agora, $dia, $fMapa, $forcar) {
        $fEst = naval_vapp_pasta() . '/estado-' . $dia . '.json';
        $est = naval_json_ler($fEst) ?: ['dia' => $dia];
        $mapa = naval_json_ler($fMapa) ?: [];
        if (!$forcar && (int) ($est['lido_em'] ?? 0) > $agora - NAVAL_VAPP_INTERVALO && $mapa) return $mapa;
        $t0 = microtime(true);
        $erro = null;
        // completa: a primeira do dia, de 30 em 30 minutos, ou sem marca para a incremental
        $completa = (int) ($est['completa_em'] ?? 0) <= $agora - NAVAL_VAPP_COMPLETA || (string) ($est['marca'] ?? '') === '';
        try {
            $pdo = naval_vapp_pdo();
            $linhas = naval_vapp_ler_visitas($pdo, $dia, $completa, (string) ($est['marca'] ?? ''));
            $est = naval_vapp_fundir($est, $linhas, $dia, $completa);
            if ($completa) $est['completa_em'] = $agora;
            $faltam = [];
            foreach ($est['visitas'] as $v) if (!isset($est['clientes'][(string) $v['customer_id']])) $faltam[] = $v['customer_id'];
            if ($faltam) $est['clientes'] = naval_vapp_ler_clientes($pdo, $faltam) + (array) ($est['clientes'] ?? []);
            if ($completa || empty($est['rotas'])) {
                $est['rotas'] = [];
                foreach (naval_bd_consultar_em($pdo, 'SELECT id, code, name, pingwin_id FROM route') as $r) {
                    $est['rotas'][(string) (int) $r['id']] = [trim((string) $r['code']), trim((string) $r['name']), trim((string) ($r['pingwin_id'] ?? ''))];
                }
                $est['motivos'] = [];
                foreach (naval_bd_consultar_em($pdo, 'SELECT id, title FROM motive') as $m) $est['motivos'][(string) (int) $m['id']] = trim((string) $m['title']);
            }
            $pdo = null;
        } catch (Throwable $e) {
            $erro = 'A aplicação móvel não respondeu (' . naval_vapp_registar_falha('aplicação', $e) . ').';
        }
        $msApp = (int) round((microtime(true) - $t0) * 1000);
        $tiposEnc = naval_vapp_tipos_encomenda();
        $docs = (array) ($est['docs'] ?? []);
        $t1 = microtime(true);
        try {
            if (naval_bd_configurada()) $docs = naval_vapp_docs_pinguim($dia, $tiposEnc);
        } catch (Throwable $e) {
            error_log('[visitas app] Pinguim BO: ' . $e->getMessage());
            $erro = $erro ?? 'O Pinguim BO não respondeu: os tipos de documento são os da última leitura.';
        }
        $msPinguim = (int) round((microtime(true) - $t1) * 1000);
        $est['docs'] = $docs;
        $est['lido_em'] = $agora;
        naval_json_gravar($fEst, $est, false);
        $m = naval_vapp_montar($est, $docs, naval_vapp_gps_clientes(), $tiposEnc, (float) naval_config('VISITAS_RAIO_PRESENCIAL_M', (string) NAVAL_VAPP_RAIO_M));
        $mapa = $m + ['dia' => $dia, 'atualizado_em' => date('Y-m-d H:i:s', $agora), 'erro' => $erro,
            'ms' => ['aplicacao' => $msApp, 'pinguim' => $msPinguim, 'leitura' => $completa ? 'completa' : 'incremental', 'linhas' => count($est['visitas'] ?? [])]];
        naval_json_gravar($fMapa, $mapa, false);
        // os ficheiros do dia ficam 40 dias (o mapa) e 3 dias (o estado de trabalho)
        foreach (glob(naval_vapp_pasta() . '/*.json') ?: [] as $f) {
            $b = basename($f);
            if ((strpos($b, 'estado-') === 0 && $b < 'estado-' . date('Y-m-d', $agora - 3 * 86400)) || (strpos($b, 'mapa-') === 0 && $b < 'mapa-' . date('Y-m-d', $agora - 40 * 86400))) @unlink($f);
        }
        return $mapa;
    });
}

/** Mapa de um dia para as páginas: hoje lê (no máximo de 2 em 2 min); antes, o que ficou gravado. */
function naval_vapp_mapa(string $dia, ?int $agora = null): array
{
    $agora = $agora ?? time();
    if ($dia === date('Y-m-d', $agora)) return naval_vapp_actualizar($agora);
    return (naval_json_ler(naval_vapp_pasta() . '/mapa-' . $dia . '.json') ?: []) + ['dia' => $dia, 'pontos' => [], 'totais' => null,
        'erro' => 'Sem o mapa deste dia: o mapa ao vivo guarda os dias desde que a ligação à aplicação móvel ficou activa.'];
}
