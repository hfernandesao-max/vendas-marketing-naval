<?php
/**
 * Ficha do cliente a partir do OLAP (armazém de dados do Pinguim BO, esquema dbo), só de leitura:
 *  - dados do cliente e localização (DIM_CLIENT: tipologia, município, GPS);
 *  - atribuição actual (DIM_CLIENT_HISTORY sem END_DATE: rota, vendedor, coordenador, gestor, distribuidor, canal,
 *    subcanal e dias de visita; VISIT_DAY1 = domingo … VISIT_DAY7 = sábado, a convenção de SYSDIM_DATE.WEEKDAY);
 *  - visitas dos promotores (FACT_R1_MAP: resultado ou motivo de não venda, presencial ou remota, distância do GPS,
 *    sítio da venda, tempo no cliente e deslocação até ao cliente seguinte do mesmo promotor no mesmo dia).
 * Vendas e pagamentos não vêm daqui: o OLAP não tem meios de pagamento e só guarda o último mês de vendas, por isso a
 * ficha usa as vendas do Pinguim BO que o portal já carrega (as mesmas regras de todos os ecrãs).
 * O cliente liga-se ao Pinguim BO pelo código (DIM_CLIENT.CODE = código da entidade, 10 dígitos).
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('bd');
naval_servico('olap');

/** Permanência máxima aceite numa visita (segundos): acima disto a hora de fim é um esquecimento de fechar a visita. */
const NAVAL_OLAPCLI_PERMANENCIA_MAX = 4 * 3600;

/** Abaixo desta distância (m) a visita seguinte é no mesmo sítio e não conta como deslocação. */
const NAVAL_OLAPCLI_MESMO_SITIO_M = 50;

/** Dias de visita pela ordem das colunas VISIT_DAY1..7 (1 = domingo). */
const NAVAL_OLAPCLI_DIAS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

/** Janela das visitas lidas (dias) e máximo de linhas devolvidas. */
const NAVAL_OLAPCLI_VISITAS_DIAS = 180;
const NAVAL_OLAPCLI_VISITAS_MAX = 300;

/** Código de cliente aceite: só algarismos e letras (o do Pinguim BO tem 10 algarismos). */
function naval_olapcli_codigo_valido(string $c): bool
{
    return (bool) preg_match('/^[A-Za-z0-9]{1,20}$/', $c);
}

/** Lê do OLAP o cliente, a atribuição actual e as visitas recentes (linhas em bruto); null se o cliente não existir. */
function naval_olapcli_ler(string $codigo): ?array
{
    $pdo = naval_bd_criar('OLAP');
    $q = function (string $sql, array $p) use ($pdo) {
        naval_bd_so_leitura($sql);
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $cli = $q('SELECT TOP 1 C.ID, C.CODE, C.DESCRIPTION, C.FISCALNAME, C.TAX_NUMBER, C.TYPOLOGY, C.STATE_PROVINCE, C.CITY, C.LATITUDE, C.LONGITUDE, C.UPDATE_DATE
        FROM dbo.DIM_CLIENT C WHERE C.CODE = :c ORDER BY C.ID DESC', [':c' => $codigo]);
    if (!$cli) return null;
    $id = (string) $cli[0]['ID'];
    $hist = $q('SELECT TOP 1 H.START_DATE, R.CODE ROTA, R.DESCRIPTION ROTA_NOME, S.DESCRIPTION VENDEDOR, CO.DESCRIPTION COORDENADOR, M.DESCRIPTION GESTOR,
            D.DESCRIPTION DISTRIBUIDOR, CH.DESCRIPTION CANAL, SC.DESCRIPTION SUBCANAL,
            H.VISIT_DAY1, H.VISIT_DAY2, H.VISIT_DAY3, H.VISIT_DAY4, H.VISIT_DAY5, H.VISIT_DAY6, H.VISIT_DAY7
        FROM dbo.DIM_CLIENT_HISTORY H
        LEFT JOIN dbo.DIM_ROUTE R ON R.ID = H.DIM_ROUTE_ID
        LEFT JOIN dbo.DIM_SALESMAN S ON S.ID = H.DIM_SALESMAN_ID
        LEFT JOIN dbo.DIM_COORDINATOR CO ON CO.ID = H.DIM_COORDINATOR_ID
        LEFT JOIN dbo.DIM_MANAGER M ON M.ID = H.DIM_MANAGER_ID
        LEFT JOIN dbo.DIM_DISTRIBUTOR D ON D.ID = H.DIM_DISTRIBUTOR_ID
        LEFT JOIN dbo.DIM_DISTRIBUTION_CHANNEL CH ON CH.ID = H.DIM_DISTRIBUTION_CHANNEL_ID
        LEFT JOIN dbo.DIM_DISTRIBUTION_SUBCHANNEL SC ON SC.ID = H.DIM_DISTRIBUTION_SUBCHANNEL_ID
        WHERE H.DIM_CLIENT_ID = :id AND (H.END_DATE IS NULL OR H.END_DATE > GETDATE())
        ORDER BY H.START_DATE DESC', [':id' => $id]);
    $desde = (int) date('Ymd', time() - NAVAL_OLAPCLI_VISITAS_DIAS * 86400);
    // visita seguinte do mesmo promotor no mesmo dia (LEAD): tempo de deslocação e distância até ao próximo cliente;
    // só se lêem os dias dos promotores que visitaram este cliente
    $vis = $q('WITH D AS (
            SELECT F.*, LEAD(F.DIM_START_TIME_ID) OVER (PARTITION BY F.DIM_SALESMAN_ID, F.DIM_DATE_ID ORDER BY F.DIM_START_TIME_ID, F.ID) PROX_INICIO,
                LEAD(F.LAT) OVER (PARTITION BY F.DIM_SALESMAN_ID, F.DIM_DATE_ID ORDER BY F.DIM_START_TIME_ID, F.ID) PROX_LAT,
                LEAD(F.LNG) OVER (PARTITION BY F.DIM_SALESMAN_ID, F.DIM_DATE_ID ORDER BY F.DIM_START_TIME_ID, F.ID) PROX_LNG,
                LEAD(F.DIM_CLIENT_ID) OVER (PARTITION BY F.DIM_SALESMAN_ID, F.DIM_DATE_ID ORDER BY F.DIM_START_TIME_ID, F.ID) PROX_CLIENTE_ID
            FROM dbo.FACT_R1_MAP F
            WHERE F.DIM_DATE_ID >= :desde AND F.DIM_SALESMAN_ID IN (SELECT X.DIM_SALESMAN_ID FROM dbo.FACT_R1_MAP X WHERE X.DIM_CLIENT_ID = :id)
        )
        SELECT TOP ' . NAVAL_OLAPCLI_VISITAS_MAX . ' D.DIM_DATE_ID, T.TIME_DESCRIPTION HORA, D.DIM_START_TIME_ID INICIO, D.DIM_END_DATE_ID, D.DIM_END_TIME_ID FIM,
            R.CODE ROTA, S.DESCRIPTION VENDEDOR, MO.DESCRIPTION RESULTADO, D.PRESENCIAL, D.REMOTE, D.NON_GPS, D.DOC_TOTAL, D.DOC_FULLNUMBER, D.DISTANCE,
            D.LAT, D.LNG, D.PROX_INICIO, D.PROX_LAT, D.PROX_LNG, PC.CODE PROX_CODIGO, PC.DESCRIPTION PROX_CLIENTE
        FROM D
        LEFT JOIN dbo.SYSDIM_TIME T ON T.ID = D.DIM_START_TIME_ID
        LEFT JOIN dbo.DIM_ROUTE R ON R.ID = D.DIM_ROUTE_ID
        LEFT JOIN dbo.DIM_SALESMAN S ON S.ID = D.DIM_SALESMAN_ID
        LEFT JOIN dbo.DIM_OUTCOME_MOTIVE MO ON MO.ID = D.DIM_OUTCOME_MOTIVE_ID
        LEFT JOIN dbo.DIM_CLIENT PC ON PC.ID = D.PROX_CLIENTE_ID
        WHERE D.DIM_CLIENT_ID = :id2
        ORDER BY D.DIM_DATE_ID DESC, D.DIM_START_TIME_ID DESC', [':id' => $id, ':id2' => $id, ':desde' => $desde]);
    return ['cliente' => $cli[0], 'atribuicao' => $hist[0] ?? null, 'visitas' => $vis];
}

/** Texto do OLAP em UTF-8, sem espaços à volta (null se vazio). */
function naval_olapcli_txt($v): ?string
{
    if ($v === null) return null;
    $s = trim(naval_bd_utf8((string) $v));
    return $s === '' ? null : $s;
}

/** Pessoa ou entidade atribuída: os marcadores do OLAP («AINDA SEM COORDENADOR», «AINDA SEM GERENTE») contam como vazio. */
function naval_olapcli_quem($v): ?string
{
    $s = naval_olapcli_txt($v);
    return $s === null || preg_match('/^AINDA SEM\b/i', $s) ? null : $s;
}

/** Coordenadas do OLAP dentro de Angola, ou null. */
function naval_olapcli_ponto($la, $lo): ?array
{
    if (!is_numeric($la) || !is_numeric($lo)) return null;
    $la = (float) $la;
    $lo = (float) $lo;
    return $la >= -18.1 && $la <= -4.3 && $lo >= 11.6 && $lo <= 24.1 ? [round($la, 6), round($lo, 6)] : null;
}

/**
 * Tempos de uma visita (segundos do dia do OLAP): permanência no cliente (fim − início, só no mesmo dia e até
 * NAVAL_OLAPCLI_PERMANENCIA_MAX), deslocação até à visita seguinte do mesmo promotor (início seguinte − fim; sem
 * fim válido, fica só o intervalo entre inícios) e a distância em linha recta até ao cliente seguinte.
 */
function naval_olapcli_tempos(array $v): array
{
    $ini = is_numeric($v['INICIO'] ?? null) ? (int) $v['INICIO'] : null;
    $fim = is_numeric($v['FIM'] ?? null) ? (int) $v['FIM'] : null;
    $prox = is_numeric($v['PROX_INICIO'] ?? null) ? (int) $v['PROX_INICIO'] : null;
    $mesmoDia = (string) ($v['DIM_END_DATE_ID'] ?? '') === (string) ($v['DIM_DATE_ID'] ?? '');
    $perm = $ini !== null && $fim !== null && $mesmoDia && $fim > $ini && $fim - $ini <= NAVAL_OLAPCLI_PERMANENCIA_MAX ? $fim - $ini : null;
    $desl = $prox !== null && $perm !== null && $prox >= $fim ? $prox - $fim : null;
    $interv = $prox !== null && $ini !== null && $prox >= $ini ? $prox - $ini : null;
    $aqui = naval_olapcli_ponto($v['LAT'] ?? null, $v['LNG'] ?? null);
    $ali = naval_olapcli_ponto($v['PROX_LAT'] ?? null, $v['PROX_LNG'] ?? null);
    $metros = $aqui && $ali ? (int) round(naval_olap_dist($aqui[0], $aqui[1], $ali[0], $ali[1])) : null;
    // seguinte no mesmo sítio (ex.: encomendas remotas seguidas): não houve deslocação a medir
    $mesmoSitio = $metros !== null && $metros < NAVAL_OLAPCLI_MESMO_SITIO_M;
    return [
        'permanencia_min' => $perm === null ? null : (int) round($perm / 60),
        'deslocacao_min' => $desl === null || $mesmoSitio ? null : (int) round($desl / 60),
        'intervalo_min' => $interv === null ? null : (int) round($interv / 60),
        'proximo_m' => $metros,
        'proximo_mesmo_sitio' => $mesmoSitio,
        'lat' => $aqui[0] ?? null,
        'lng' => $aqui[1] ?? null,
    ];
}

/** Mediana de uma lista de números (null sem valores). */
function naval_olapcli_mediana(array $xs): ?int
{
    $xs = array_values(array_filter($xs, function ($x) { return $x !== null; }));
    if (!$xs) return null;
    sort($xs);
    return (int) $xs[intdiv(count($xs), 2)];
}

/**
 * Ficha normalizada a partir das linhas em bruto de naval_olapcli_ler (função pura, testada): textos em UTF-8,
 * coordenadas só dentro de Angola, dias de visita por nome, visitas com data ISO e resumo por resultado.
 */
function naval_olapcli_ficha(array $bruto, bool $veNif): array
{
    $c = $bruto['cliente'];
    $la = is_numeric($c['LATITUDE'] ?? null) ? (float) $c['LATITUDE'] : null;
    $lo = is_numeric($c['LONGITUDE'] ?? null) ? (float) $c['LONGITUDE'] : null;
    $gps = $la !== null && $lo !== null && $la >= -18.1 && $la <= -4.3 && $lo >= 11.6 && $lo <= 24.1;
    $h = $bruto['atribuicao'];
    $dias = [];
    if ($h) foreach (NAVAL_OLAPCLI_DIAS as $i => $d) if ((int) ($h['VISIT_DAY' . ($i + 1)] ?? 0) === 1) $dias[] = $d;
    $visitas = [];
    $resumo = [];
    foreach ($bruto['visitas'] as $v) {
        $id = (string) ($v['DIM_DATE_ID'] ?? '');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $id, $m)) continue;
        $res = naval_olapcli_txt($v['RESULTADO'] ?? null) ?? 'Desconhecido';
        $modo = (int) ($v['PRESENCIAL'] ?? 0) === 1 ? 'presencial' : ((int) ($v['REMOTE'] ?? 0) === 1 ? 'remota' : 'outra');
        $valor = is_numeric($v['DOC_TOTAL'] ?? null) ? round((float) $v['DOC_TOTAL'], 2) : null;
        $visitas[] = [
            'data' => "$m[1]-$m[2]-$m[3]",
            'hora' => substr((string) ($v['HORA'] ?? ''), 0, 5),
            'rota' => naval_olapcli_txt($v['ROTA'] ?? null),
            'vendedor' => naval_olapcli_txt($v['VENDEDOR'] ?? null),
            'resultado' => $res,
            'venda' => $res === 'Venda' || ($valor !== null && $valor > 0),
            'modo' => $modo,
            'sem_gps' => (int) ($v['NON_GPS'] ?? 0) === 1,
            'valor' => $valor,
            'documento' => naval_olapcli_txt($v['DOC_FULLNUMBER'] ?? null),
            'distancia' => is_numeric($v['DISTANCE'] ?? null) ? (int) round((float) $v['DISTANCE']) : null,
            'proximo_cliente' => naval_olapcli_txt($v['PROX_CLIENTE'] ?? null),
            'proximo_codigo' => naval_olapcli_txt($v['PROX_CODIGO'] ?? null),
        ] + naval_olapcli_tempos($v);
        $resumo[$res] = ($resumo[$res] ?? 0) + 1;
    }
    arsort($resumo);
    $comDist = array_values(array_filter(array_column($visitas, 'distancia'), function ($d) { return $d !== null; }));
    sort($comDist);
    return [
        'cliente' => [
            'codigo' => (string) $c['CODE'],
            // identificador interno (ENTITY.ID do Pinguim BO): é por ele que as vendas identificam o cliente
            'id' => (string) $c['ID'],
            'nome' => naval_olapcli_txt($c['DESCRIPTION'] ?? null),
            'nome_fiscal' => naval_olapcli_txt($c['FISCALNAME'] ?? null),
            'nif' => $veNif ? naval_olapcli_txt($c['TAX_NUMBER'] ?? null) : null,
            'tipologia' => naval_olapcli_txt($c['TYPOLOGY'] ?? null),
            'actualizado' => !empty($c['UPDATE_DATE']) ? date('Y-m-d H:i', (int) strtotime((string) $c['UPDATE_DATE'])) : null,
        ],
        'localizacao' => [
            // a coluna STATE_PROVINCE do OLAP traz o município (Viana, Cazenga…); CITY vem quase sempre vazia
            'municipio' => naval_olapcli_txt($c['STATE_PROVINCE'] ?? null),
            'cidade' => naval_olapcli_txt($c['CITY'] ?? null),
            'lat' => $gps ? round($la, 6) : null,
            'lng' => $gps ? round($lo, 6) : null,
            'gps_invalido' => !$gps && ($la !== null || $lo !== null),
        ],
        'atribuicao' => $h ? [
            'rota' => naval_olapcli_txt($h['ROTA'] ?? null),
            'rota_nome' => naval_olapcli_txt($h['ROTA_NOME'] ?? null),
            'vendedor' => naval_olapcli_quem($h['VENDEDOR'] ?? null),
            'coordenador' => naval_olapcli_quem($h['COORDENADOR'] ?? null),
            'gestor' => naval_olapcli_quem($h['GESTOR'] ?? null),
            'distribuidor' => naval_olapcli_quem($h['DISTRIBUIDOR'] ?? null),
            'canal' => naval_olapcli_txt($h['CANAL'] ?? null),
            'subcanal' => naval_olapcli_txt($h['SUBCANAL'] ?? null),
            'desde' => !empty($h['START_DATE']) ? substr((string) $h['START_DATE'], 0, 10) : null,
            'dias_visita' => $dias,
        ] : null,
        'visitas' => $visitas,
        'resumo_visitas' => [
            'total' => count($visitas),
            'com_venda' => count(array_filter($visitas, function ($v) { return $v['venda']; })),
            'presenciais' => count(array_filter($visitas, function ($v) { return $v['modo'] === 'presencial'; })),
            'remotas' => count(array_filter($visitas, function ($v) { return $v['modo'] === 'remota'; })),
            'distancia_mediana' => $comDist ? $comDist[intdiv(count($comDist), 2)] : null,
            'permanencia_mediana_min' => naval_olapcli_mediana(array_column($visitas, 'permanencia_min')),
            'deslocacao_mediana_min' => naval_olapcli_mediana(array_column($visitas, 'deslocacao_min')),
            'com_tempo' => count(array_filter($visitas, function ($v) { return $v['permanencia_min'] !== null; })),
            'ultima' => $visitas[0]['data'] ?? null,
            'por_resultado' => array_map(function ($k, $n) { return ['resultado' => (string) $k, 'n' => $n]; }, array_keys($resumo), array_values($resumo)),
        ],
        'janela_dias' => NAVAL_OLAPCLI_VISITAS_DIAS,
    ];
}

/** A pessoa pode ver este cliente? Com âmbito por rotas, a rota actual do OLAP (ou a da carteira) tem de ser sua. */
function naval_olapcli_no_ambito(array $ficha, ?array $rotas, array $rotasCarteira = []): bool
{
    if ($rotas === null) return true;
    $r = array_filter(array_merge([(string) ($ficha['atribuicao']['rota'] ?? '')], $rotasCarteira), function ($x) { return (string) $x !== ''; });
    return (bool) array_intersect($r, $rotas);
}
