<?php
/**
 * Conciliação da carrinha (fecho diário da rota, «route settlement») e limite de peso da carga.
 *
 * Por rota, dia e artigo, a partir do Pinguim BO (só SELECT):
 *   inicial   stock da carrinha no fim do dia anterior (dbo.GET_STOCK)
 *   carregado entradas na carrinha no dia (movimentos de armazém que não são vendas)
 *   vendido   quantidades vendidas pela rota (linhas dos documentos, já líquidas das notas de crédito)
 *   devolvido saídas da carrinha para o armazém no dia
 *   final     stock da carrinha no fim do dia (dbo.GET_STOCK)
 * Quantidade que tem de voltar na carrinha: inicial + carregado − vendido. O técnico do armazém conta o que voltou
 * (contagem cega: não vê o esperado antes de gravar) e as diferenças seguem um circuito de aprovação em dois níveis,
 * com segregação de funções (ninguém aprova o que contou nem aprova duas vezes):
 *   por_contar → (técnico grava a contagem) por_validar → (o mesmo técnico valida ou reconta) por_aprovar
 *   → (coordenador aprova, com o motivo de cada diferença) por_fechar → (responsável aprova) aprovada (fechada).
 * Sem diferenças nem alertas, a contagem validada fecha sozinha (gestão por excepção; CARRINHA_FECHO_AUTOMATICO=0 desliga).
 * Quem pode: contar e validar «conciliacao-carrinha» G; 1.º nível «conciliacao-coordenacao» G; 2.º nível «conciliacao-fecho» G.
 * Sem os movimentos separados nesta base de dados, carregado e devolvido saem do saldo (final − inicial + vendido) e
 * o esperado é o stock final da carrinha (contagem antes de lançar a devolução).
 *
 * Ficheiros (fora da pasta pública): dados-privados/carrinha/AAAA-MM-DD/<rota>.json (contagem, motivos, estado,
 * histórico) e _dados.json (fotografia dos números do dia, para os números que o técnico viu ficarem registados).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/cargas.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/requisicoes.php';

const NAVAL_CARR_MOTIVOS = [
    'avaria' => 'Produto danificado',
    'validade' => 'Fora de validade',
    'erro_carga' => 'Erro na carga',
    'venda_nao_registada' => 'Venda não registada',
    'erro_registo' => 'Erro de registo no sistema',
    'falta' => 'Falta sem justificação',
    'outro' => 'Outro (explicar na nota)',
];
const NAVAL_CARR_TOLERANCIA = 0.0001;
const NAVAL_CARR_ESTADOS = ['por_contar', 'por_validar', 'por_aprovar', 'por_fechar', 'aprovada'];

/** Segregação de funções: a mesma pessoa não pode fazer dois passos do circuito (CARRINHA_SEGREGACAO=0 desliga). */
function naval_carr_segregar(array $reg, string $login, array $campos): void
{
    if (naval_config('CARRINHA_SEGREGACAO', '1') === '0') return;
    foreach ($campos as $c) {
        if ($login !== '' && strcasecmp((string) ($reg[$c] ?? ''), $login) === 0) {
            throw new NavalErro('Segregação de funções: quem contou ou já aprovou esta conciliação não pode aprovar este passo.', 403);
        }
    }
}

function naval_carr_pasta(string $data): string
{
    if (!naval_data_valida($data)) throw new NavalErro('Data inválida.');
    return naval_pasta('carrinha/' . $data);
}

/** Registo da rota no dia (contagem, motivos, estado, histórico). */
function naval_carr_ler(string $data, string $rota): array
{
    $r = naval_json_ler(naval_carr_pasta($data) . '/' . naval_carr_nome_rota($rota) . '.json');
    return $r + ['rota' => $rota, 'data' => $data, 'estado' => '', 'contagem' => null, 'motivos' => [], 'historico' => []];
}

function naval_carr_nome_rota(string $rota): string
{
    if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) throw new NavalErro('Rota inválida.', 400);
    return $rota;
}

// ── Fecho da carrinha antes de nova carga ────────────────────────────────────────────────────────────────────────

/**
 * Regra de fecho antes da carga (liquidação da rota antes da saída seguinte, como na distribuição directa): o armazém só
 * confirma uma carga na carrinha quando as conciliações dos dias anteriores dessa rota estão fechadas (aprovadas).
 * Vale para os dias com movimento desde que a regra entrou em vigor (carrinha/_regra-fecho.json, criado na primeira
 * verificação: os dias antigos ficam como estavam) e até CARRINHA_FECHO_DIAS para trás (7 por omissão).
 * CARRINHA_FECHO_ANTES_CARGA=0 desliga. Excepção: autorização de quem fecha as conciliações, com motivo; a carga
 * fica então fora da contagem dos dias por fechar (naval_carr_juntar_posterior).
 */
function naval_carr_regra_desde(): string
{
    $f = naval_pasta('carrinha') . '/_regra-fecho.json';
    $g = naval_json_ler($f);
    if (!empty($g['desde']) && naval_data_valida((string) $g['desde'])) return (string) $g['desde'];
    $hoje = date('Y-m-d');
    naval_json_gravar($f, ['desde' => $hoje, 'criado_em' => date('Y-m-d H:i:s')]);
    return $hoje;
}

/**
 * Conciliações por fechar da rota nos dias anteriores a $hoje: {pendentes: [{data, estado}], verificado, erro}.
 * Sem o Pinguim BO e sem a fotografia do dia, não se consegue verificar (verificado = false): conta como por fechar.
 */
function naval_carr_fechos_pendentes(string $rota, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    $out = ['pendentes' => [], 'verificado' => true, 'erro' => null];
    if (naval_config('CARRINHA_FECHO_ANTES_CARGA', '1') === '0') return $out;
    $dias = max(1, min(31, (int) naval_config('CARRINHA_FECHO_DIAS', '7')));
    $desde = max(naval_carr_regra_desde(), date('Y-m-d', strtotime($hoje . ' 12:00') - $dias * 86400));
    for ($t = strtotime($hoje . ' 12:00') - 86400; date('Y-m-d', $t) >= $desde; $t -= 86400) {
        $dia = date('Y-m-d', $t);
        try {
            $v = naval_carr_vista($dia, [$rota], true);
        } catch (NavalErro $e) {
            $out['verificado'] = false;
            $out['erro'] = $e->getMessage();
            $out['pendentes'][] = ['data' => $dia, 'estado' => 'por_verificar'];
            break;   // sem base de dados: os dias mais antigos também não se verificam
        }
        foreach ($v['rotas'] as $r) {
            if ($r['rota'] === $rota && $r['resumo']['estado'] !== 'aprovada') $out['pendentes'][] = ['data' => $dia, 'estado' => $r['resumo']['estado']];
        }
    }
    return $out;
}

/** Texto da recusa: «o fecho de 13-11-2026 (por aprovar) e de 12-11-2026 (por contar)». */
function naval_carr_pendentes_texto(array $pendentes): string
{
    $n = ['por_contar' => 'por contar', 'por_validar' => 'por validar', 'por_aprovar' => 'por aprovar', 'por_fechar' => 'por fechar', 'por_verificar' => 'sem dados do Pinguim BO'];
    return implode(', ', array_map(function ($p) use ($n) { return naval_data_pt($p['data']) . ' (' . ($n[$p['estado']] ?? $p['estado']) . ')'; }, $pendentes));
}

/** Quantidades das cargas posteriores já na carrinha antes da contagem: {codigo: quantidade}. */
function naval_carr_posteriores(array $reg): array
{
    $q = [];
    foreach ((array) ($reg['cargas_posteriores'] ?? []) as $c) {
        foreach ((array) ($c['linhas'] ?? []) as $cod => $x) $q[(string) $cod] = round(($q[(string) $cod] ?? 0) + (float) $x, 3);
    }
    return $q;
}

/**
 * Carga autorizada antes do fecho: nos dias ainda sem folha, fica registada na conciliação e soma-se ao que se conta
 * (não entra na conciliação do dia). Depois de congelar a folha ou gravar a contagem, a base fica fechada.
 */
function naval_carr_juntar_posterior(string $dia, string $rota, string $numero, array $linhas, string $autorizado, string $em): bool
{
    return naval_exclusivo('carrinha-' . $dia . '-' . naval_carr_nome_rota($rota), function () use ($dia, $rota, $numero, $linhas, $autorizado, $em) {
        $reg = naval_carr_ler($dia, $rota);
        if (!empty($reg['folha_congelada_em']) || $reg['contagem'] !== null || in_array($reg['estado'], ['por_validar', 'por_aprovar', 'por_fechar', 'aprovada'], true)) return false;
        foreach ((array) ($reg['cargas_posteriores'] ?? []) as $c) if (($c['numero'] ?? '') === $numero) return false;
        $reg['cargas_posteriores'][] = ['numero' => $numero, 'linhas' => $linhas, 'confirmado_em' => $em, 'autorizado_por' => $autorizado];
        $reg['historico'][] = ['em' => $em, 'por' => 'portal', 'accao' => 'carga_posterior', 'requisicao' => $numero];
        naval_json_gravar(naval_carr_pasta($dia) . '/' . $rota . '.json', $reg);
        return true;
    });
}

// ── Corte do dia: a carga para o dia seguinte não entra na contagem ───────────────────────────────────────────────

/**
 * Corte do dia (início do carregamento, calendário: 16:00 de segunda a sexta). A conciliação do dia vai até ao corte: o
 * que tem de estar na carrinha à contagem é o stock no corte. O que entra depois (a carga para o dia seguinte) e o que
 * sai depois (devolução ao armazém) não entra na contagem: passa a stock efectivo da carrinha no dia seguinte (o
 * inicial, às 00:00). Vale a partir de NAVAL_CARR_CORTE_DESDE; as conciliações fechadas antes ficam como estavam.
 * CARRINHA_CORTE=0 desliga.
 */
const NAVAL_CARR_CORTE_DESDE = '2026-11-16';

/** Hora do corte do dia (HH:MM) ou null (folga, regra desligada ou dia anterior à regra). */
function naval_carr_corte(string $data): ?string
{
    if (naval_config('CARRINHA_CORTE', '1') === '0' || $data < NAVAL_CARR_CORTE_DESDE) return null;
    naval_servico('calendario');
    $c = naval_cal_dia($data);
    return $c['carga'][0] ?? null;
}

/**
 * Stock de uma rota no corte, por artigo: {codigo: quantidade, …} e a origem — «fotografia» (tirada antes do corte),
 * «documentos» (sem fotografia: inicial − vendido + entradas − saídas dos documentos de stock do Pinguim BO registados
 * antes do corte) ou «estimado» (sem os documentos: inicial − vendido + cargas confirmadas no portal antes do corte).
 * Null antes do corte.
 */
function naval_carr_stock_corte(string $data, string $rota, array $r, ?string $hora = null): ?array
{
    if ($hora === null) {
        $hora = naval_carr_corte($data);
        if ($hora === null || ($data === date('Y-m-d') && date('H:i') < $hora)) return null;
    }
    $foto = naval_json_ler(naval_carr_pasta($data) . '/_corte.json');
    if ($foto && isset($foto['rotas'])) {
        $q = [];
        foreach ((array) ($foto['rotas'][$rota] ?? []) as $c => $x) $q[(string) $c] = (float) ($x['q'] ?? 0);
        // vendas registadas depois da fotografia (ex.: facturas às 18:30): já não estão na carrinha quando se conta
        $vd = 0.0;
        foreach ((array) ($r['mov'] ?? []) as $c => $x) {
            if (!is_array($x) || empty($x['vd'])) continue;
            $q[(string) $c] = round(($q[(string) $c] ?? 0) - (float) $x['vd'], 3);
            $vd += (float) $x['vd'];
        }
        return ['q' => $q, 'origem' => 'fotografia', 'hora' => (string) ($foto['hora'] ?? $hora), 'vendido_depois' => round($vd, 3)];
    }
    $q = [];
    foreach ((array) ($r['s0'] ?? []) as $c => $x) $q[(string) $c] = (float) ($x['q'] ?? 0);
    foreach ((array) ($r['v'] ?? []) as $c => $x) $q[(string) $c] = ($q[(string) $c] ?? 0) - (float) ($x['q'] ?? 0);
    $mov = $r['mov'] ?? null;
    if (is_array($mov) && naval_carr_mov_com_hora($mov)) {
        // com os documentos de stock do Pinguim BO e a hora de registo: entradas e saídas registadas antes do corte
        foreach ($mov as $c => $x) $q[(string) $c] = ($q[(string) $c] ?? 0) + (float) ($x['ca'] ?? 0) - (float) ($x['da'] ?? 0);
        return ['q' => $q, 'origem' => 'documentos', 'hora' => $hora];
    }
    foreach (naval_carr_cargas_portal($data, $rota, $hora) as $c => $x) $q[(string) $c] = ($q[(string) $c] ?? 0) + $x;
    return ['q' => $q, 'origem' => 'estimado', 'hora' => $hora];
}

/** Os movimentos têm a separação antes/depois do corte (lidos dos documentos com a hora de registo)? */
function naval_carr_mov_com_hora(array $mov): bool
{
    foreach ($mov as $x) return is_array($x) && array_key_exists('ca', $x);
    return true;   // sem movimentos nenhuns no dia: nada entrou nem saiu antes do corte
}

/** Cargas confirmadas no portal para a rota no dia antes do corte: {codigo: quantidade entregue}. */
function naval_carr_cargas_portal(string $data, string $rota, string $hora): array
{
    $q = [];
    foreach ((array) glob(naval_req_pasta(substr($data, 0, 4)) . '/RQ-*.json') as $f) {
        $r = naval_json_ler((string) $f);
        if (!$r || $r['estado'] !== 'carregada' || (string) $r['rota'] !== $rota) continue;
        $em = (string) ($r['confirmado_em'] ?? '');
        if (substr($em, 0, 10) !== $data || substr($em, 11, 5) >= $hora) continue;
        foreach ($r['linhas'] as $l) $q[(string) $l['codigo']] = ($q[(string) $l['codigo']] ?? 0) + (float) ($l['entregue'] ?? $l['quantidade']);
    }
    return $q;
}

/**
 * Números da rota para a conciliação: os do Pinguim BO (ou os gravados na contagem), as cargas posteriores autorizadas
 * e o stock no corte/na base congelada. Nas conciliações já fechadas sem corte gravado, ficam como foram aprovadas.
 */
function naval_carr_base(string $data, string $rota, array $r, array $reg): array
{
    $r['post'] = naval_carr_posteriores($reg);
    if (!isset($r['corte']) && empty($reg['base_congelada_em']) && empty($reg['folha_congelada_em']) && ($reg['estado'] ?? '') !== 'aprovada') {
        $c = naval_carr_stock_corte($data, $rota, $r);
        if ($c !== null) $r['corte'] = $c;
    }
    return $r;
}

/** Rotina periódica: tira a fotografia do stock das carrinhas nos 20 minutos antes do corte (uma vez a cada 5 min). */
function naval_carr_foto_corte(int $agora): bool
{
    $dia = date('Y-m-d', $agora);
    $hora = naval_carr_corte($dia);
    if ($hora === null || !naval_bd_configurada()) return false;
    $limite = strtotime($dia . ' ' . $hora);
    if ($agora >= $limite || $agora < $limite - 1200) return false;
    $f = naval_carr_pasta($dia) . '/_corte.json';
    if (is_file($f) && $agora - (int) filemtime($f) < 300) return false;
    naval_carr_dados($dia, true);
    return true;
}

// ── Dados do Pinguim BO ──────────────────────────────────────────────────────────────────────────────────────────

/** Stock das carrinhas na data: {rota: {codigo: ['n' => nome, 'q' => quantidade]}}; null sem base de dados. */
function naval_carr_stock(string $data): ?array
{
    $s = naval_stock_sql($data, '');
    if ($s === null) return null;
    $out = [];
    foreach ((array) ($s['linhas_carrinhas'] ?? []) as $l) {
        $r = (string) ($l['rota'] ?? '');
        $c = (string) ($l['codigo'] ?? '');
        if ($r === '' || $c === '') continue;
        $out[$r][$c] = ['n' => (string) ($l['artigo'] ?? $c), 'q' => round(($out[$r][$c]['q'] ?? 0) + (float) ($l['quantidade'] ?? 0), 3)];
    }
    return $out;
}

/** Vendido por rota e artigo no dia: {rota: {codigo: ['n', 'u', 'q', 't']}} (notas de crédito descontadas). */
function naval_carr_vendido(array $artigos): array
{
    $out = [];
    foreach ($artigos as $a) {
        $r = (string) ($a['rota'] ?? '');
        $c = (string) ($a['codigo'] ?? '');
        if ($r === '' || $c === '') continue;
        $x = $out[$r][$c] ?? ['n' => (string) ($a['artigo'] ?? $c), 'u' => (string) ($a['unidade'] ?? ''), 'q' => 0.0, 't' => 0.0];
        $x['q'] = round($x['q'] + (float) ($a['quantidade'] ?? 0), 3);
        $x['t'] = round($x['t'] + (float) ($a['total'] ?? 0), 2);
        $out[$r][$c] = $x;
    }
    return $out;
}

/**
 * Entradas e saídas das carrinhas no dia, sem as vendas, lidas das linhas dos documentos de stock do Pinguim BO
 * (DOCMOVDETAIL × DOCHEADER × DOCCONFIG.STOCK_SIGNAL: guias de transporte GTC +1, saídas de armazém SA −1, …), pela loja
 * do documento (a carrinha): {rota: {codigo: ['c' => carregado, 'd' => devolvido, 'ca' => carregado antes do corte,
 * 'da' => devolvido antes do corte]}}. A hora é a do registo do documento (CLOCK_DATE). null sem ligação ou se a
 * consulta falhar (o cálculo usa então o saldo do dia).
 */
function naval_carr_movimentos_sql(string $data, ?string $hora = null): ?array
{
    if (!naval_bd_configurada()) return null;
    $corte = $hora ?? ((naval_carr_corte($data) ?? '23:59') . ':00');
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $corte)) throw new InvalidArgumentException('Hora de referência inválida.');
    naval_servico('vendas');
    $validos = naval_vendas_condicao_validos();
    try {
        // Separa movimentos e vendas antes/depois da hora de referência (CLOCK_DATE).
        $rows = naval_bd_consultar("
            SELECT COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA,
                   LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
                   CAST(CASE WHEN COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < :limite THEN 1 ELSE 0 END AS INT) AS ANTES,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND DMD.QNT * DC.STOCK_SIGNAL > 0 THEN ABS(DMD.QNT) ELSE 0 END) AS FLOAT) AS ENTRADAS,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND DMD.QNT * DC.STOCK_SIGNAL < 0 THEN ABS(DMD.QNT) ELSE 0 END) AS FLOAT) AS SAIDAS,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 THEN DMD.QNT * DH.CALC_SALES_SIGNAL ELSE 0 END) AS FLOAT) AS VENDIDO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE S ON S.ID = DH.STORE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0
              AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, :ate)
              AND ((COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND COALESCE(DC.STOCK_SIGNAL, 0) <> 0)
                   OR (COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 AND $validos))
            GROUP BY COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)), LTRIM(RTRIM(DMD.PRODUCT_CODE)),
                     CASE WHEN COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < :limite2 THEN 1 ELSE 0 END",
            [':de' => naval_bd_data($data), ':ate' => naval_bd_data($data), ':limite' => $data . ' ' . $corte, ':limite2' => $data . ' ' . $corte]);
    } catch (Throwable $e) {
        error_log('[carrinha movimentos SQL] ' . $e->getMessage());
        return null;
    }
    return naval_carr_movimentos_agrupar($rows, array_keys(naval_promotores()));
}

/** Agrupa as linhas da consulta dos movimentos por rota (só as rotas conhecidas) e artigo (função pura, testável). */
function naval_carr_movimentos_agrupar(array $rows, array $rotas): array
{
    $rotas = array_flip(array_map('strval', $rotas));
    $out = [];
    foreach ($rows as $r) {
        $rota = trim((string) $r['ROTA']);
        if (!preg_match('/^\d{4}$/', $rota) || ($rotas && !isset($rotas[$rota]))) continue;
        $c = trim((string) $r['CODIGO']);
        if ($c === '') continue;
        $x = $out[$rota][$c] ?? ['c' => 0.0, 'd' => 0.0, 'ca' => 0.0, 'da' => 0.0, 'va' => 0.0, 'vd' => 0.0];
        $x['c'] = round($x['c'] + (float) $r['ENTRADAS'], 3);
        $x['d'] = round($x['d'] + (float) $r['SAIDAS'], 3);
        if ((int) $r['ANTES'] === 1) {
            $x['ca'] = round($x['ca'] + (float) $r['ENTRADAS'], 3);
            $x['da'] = round($x['da'] + (float) $r['SAIDAS'], 3);
            $x['va'] = round($x['va'] + (float) ($r['VENDIDO'] ?? 0), 3);
        } else {
            $x['vd'] = round($x['vd'] + (float) ($r['VENDIDO'] ?? 0), 3);   // vendido depois do corte
        }
        $out[$rota][$c] = $x;
    }
    return $out;
}

/**
 * Números do dia para todas as rotas: {fonte, movimentos (separados ou saldo), gerado, rotas: {rota: {s0, s1, v, mov}}}.
 * Usa a fotografia _dados.json se for recente (5 min; dias fechados: a última), senão lê o Pinguim BO e grava-a.
 */
function naval_carr_dados(string $data, bool $actualizar = false): array
{
    $f = naval_carr_pasta($data) . '/_dados.json';
    $foto = naval_json_ler($f);
    $fechado = $data < date('Y-m-d');
    if ($foto && !$actualizar && ($fechado || (time() - (int) @filemtime($f)) < 300)) return $foto;
    $antesDoCorte = !$fechado && ($c = naval_carr_corte($data)) !== null && date('H:i') < $c;
    $s1 = naval_carr_stock($data);
    if ($s1 === null) {
        if ($foto) return $foto;
        throw new NavalErro('Sem ligação à base de dados do Pinguim BO: a conciliação precisa do stock das carrinhas.', 503);
    }
    $s0 = naval_carr_stock(date('Y-m-d', strtotime($data . ' 12:00') - 86400)) ?? [];
    $linhas = naval_vendas_artigos_sql($data, $data, naval_promotores()) ?? [];
    $v = naval_carr_vendido($linhas);
    $mov = naval_carr_movimentos_sql($data);
    $rotas = [];
    foreach (array_unique(array_merge(array_keys($s0), array_keys($s1), array_keys($v), array_keys($mov ?? []))) as $r) {
        $rotas[(string) $r] = ['s0' => $s0[$r] ?? [], 's1' => $s1[$r] ?? [], 'v' => $v[$r] ?? [], 'mov' => $mov === null ? null : ($mov[$r] ?? [])];
    }
    ksort($rotas);
    $d = ['data' => $data, 'gerado' => date('Y-m-d H:i:s'), 'movimentos' => $mov === null ? 'saldo' : 'separados', 'precos' => naval_carr_precos($v), 'rotas' => $rotas];
    naval_json_gravar($f, $d, false);
    // fotografia do stock das carrinhas antes do corte (início do carregamento): é o que tem de estar na carrinha à contagem
    if ($antesDoCorte) {
        $corte = [];
        foreach ($rotas as $r => $x) $corte[$r] = $x['s1'];
        naval_json_gravar(naval_carr_pasta($data) . '/_corte.json', ['data' => $data, 'hora' => date('H:i'), 'rotas' => $corte], false);
    }
    return $d;
}

/**
 * Congela a base da contagem com o stock e os movimentos do Pinguim BO até ao instante indicado.
 */
function naval_carr_congelar_base(array $r, string $hora, ?array $stock, ?array $mov): array
{
    $q = [];
    foreach ($stock ?? (array) ($r['s1'] ?? []) as $c => $x) $q[(string) $c] = ['n' => (string) ($x['n'] ?? $c), 'q' => (float) ($x['q'] ?? 0)];
    $r['s1'] = $q;
    $r['corte'] = ['q' => array_map(function ($x) { return (float) $x['q']; }, $q), 'origem' => 'contagem', 'hora' => $hora];
    if ($mov !== null) {
        $r['mov'] = $mov;
        foreach ($mov as $c => $x) {
            if (!isset($r['v'][$c])) $r['v'][$c] = ['n' => (string) ($q[$c]['n'] ?? $c), 'u' => '', 'q' => 0.0, 't' => 0.0];
            $r['v'][$c]['q'] = (float) ($x['va'] ?? 0);
        }
        foreach ($r['v'] as $c => &$x) if (!isset($mov[$c])) $x['q'] = 0.0;
        unset($x);
    } else $r['mov'] = null;
    return $r;
}

/** Data/hora de referência: dias passados usam o corte operacional, se houver; hoje usa corte ou instante actual. */
function naval_carr_hora_referencia(string $data): string
{
    $corte = naval_carr_corte($data);
    if ($data < date('Y-m-d')) return $data . ' ' . ($corte !== null ? $corte . ':00' : '23:59:59');
    if ($corte !== null && date('H:i:s') >= ($corte . ':00')) return $data . ' ' . $corte . ':00';
    return date('Y-m-d H:i:s');
}

/** Recalcula e congela stock e movimentos do Pinguim BO para a hora indicada. */
function naval_carr_base_no_instante(string $data, string $rota, array $r, string $referencia): array
{
    if (!naval_bd_configurada()) return $r;
    $stock = naval_carr_stock($referencia);
    $movimentos = naval_carr_movimentos_sql($data, substr($referencia, 11, 8));
    if ($stock === null || $movimentos === null) {
        throw new NavalErro('Não foi possível congelar o stock e os movimentos no momento da contagem. Tente novamente.', 503,
            ['codigo' => 'contagem_base_indisponivel']);
    }
    return naval_carr_congelar_base($r, $referencia, (array) ($stock[$rota] ?? []), (array) ($movimentos[$rota] ?? []));
}

/** Congela a base na primeira geração da folha; as reimpressões devolvem a fotografia já guardada. */
function naval_carr_preparar_folha(string $data, string $rota, array $eu): array
{
    if ($data > date('Y-m-d')) throw new NavalErro('Não se prepara uma contagem num dia que ainda não chegou.', 400);
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if (!in_array($reg['estado'], ['', 'por_contar'], true) || $reg['contagem'] !== null) throw new NavalErro('Esta rota já tem uma contagem iniciada.', 409);
        $congelada = !empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em']);
        if ($congelada && !is_array($reg['base'] ?? null)) {
            throw new NavalErro('A base congelada da folha de contagem está incompleta. Contacte a administração.', 503);
        }
        if (!$congelada) {
            $d = naval_carr_dados($data, true);
            $r = $d['rotas'][$rota] ?? null;
            if ($r === null) throw new NavalErro('Esta rota não teve movimentos na carrinha neste dia.', 404);
            $r['post'] = naval_carr_posteriores($reg);
            $referencia = naval_carr_hora_referencia($data);
            $r = naval_carr_base_no_instante($data, $rota, $r, $referencia);
            $reg['base'] = array_diff_key($r, ['post' => 1]);
            $reg['precos'] = $d['precos'];
            $reg['folha_congelada_em'] = date('Y-m-d H:i:s');
            $reg['folha_congelada_por'] = (string) ($eu['login'] ?? '');
            $reg['base_congelada_em'] = $reg['folha_congelada_em'];
            $reg['base_congelada_por'] = $reg['folha_congelada_por'];
            $reg['base_congelada_motivo'] = 'primeira_folha';
            $reg['historico'][] = ['em' => $reg['folha_congelada_em'], 'por' => $reg['folha_congelada_por'],
                'accao' => 'folha_contagem_congelada', 'referencia' => $referencia];
            naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
            naval_registar('carrinha_folha_congelada', ['data' => $data, 'rota' => $rota, 'referencia' => $referencia]);
        }
        $base = (array) $reg['base'];
        $base['post'] = naval_carr_posteriores($reg);
        $linhas = naval_carr_linhas($base, null, (array) ($reg['precos'] ?? []));
        return ['linhas' => naval_carr_cega($linhas),
            'congelada_em' => (string) ($base['corte']['hora'] ?? $reg['base_congelada_em'] ?? $reg['folha_congelada_em'] ?? '')];
    });
}

/**
 * Actualiza, em bloco, bases históricas de rotas por contar, usando o stock e os movimentos do Pinguim BO
 * no corte de cada dia. Nunca altera registos com contagem, aprovação ou base já congelada.
 */
function naval_carr_actualizar_abertas_anteriores(array $eu, string $depoisDe = ''): array
{
    if (!naval_bd_configurada()) throw new NavalErro('A ligação à base de dados do Pinguim BO não está configurada.', 503);
    $hoje = date('Y-m-d');
    if ($depoisDe !== '' && (!naval_data_valida($depoisDe) || $depoisDe >= $hoje)) {
        throw new NavalErro('Ponto de continuação inválido para a actualização histórica.', 400);
    }
    $ficheiros = glob(naval_pasta('carrinha') . '/*/_dados.json') ?: [];
    $dias = [];
    foreach ($ficheiros as $f) {
        $dia = basename(dirname($f));
        if (!naval_data_valida($dia) || $dia >= $hoje || ($depoisDe !== '' && $dia <= $depoisDe)) continue;
        $foto = naval_json_ler($f);
        if (empty($foto['rotas']) || !is_array($foto['rotas'])) continue;
        foreach (array_keys($foto['rotas']) as $rota) {
            if (!preg_match('/^[A-Za-z0-9]{1,10}$/', (string) $rota)) continue;
            $reg = naval_carr_ler($dia, (string) $rota);
            if (in_array(($reg['estado'] ?? ''), ['', 'por_contar'], true) && ($reg['contagem'] ?? null) === null
                && empty($reg['base_congelada_em']) && empty($reg['folha_congelada_em'])) {
                $dias[$dia][] = (string) $rota;
            }
        }
    }
    ksort($dias);
    $mais = count($dias) > 10;
    if ($mais) $dias = array_slice($dias, 0, 10, true);
    $resultado = ['actualizadas' => [], 'ignoradas' => 0, 'falhas' => []];
    foreach ($dias as $data => $rotas) {
        try {
            $d = naval_carr_dados($data, true);
            $referencia = naval_carr_hora_referencia($data);
            $stock = naval_carr_stock($referencia);
            $stockInicial = naval_carr_stock(date('Y-m-d', strtotime($data . ' 12:00') - 86400));
            $movimentos = naval_carr_movimentos_sql($data, substr($referencia, 11, 8));
            $artigos = naval_vendas_artigos_pinguim($data, $data, naval_promotores());
            if ($stock === null || $stockInicial === null || $movimentos === null || $artigos === null) {
                throw new NavalErro('O stock inicial/final, as vendas ou os movimentos históricos não puderam ser lidos do Pinguim BO.', 503);
            }
            $vendido = naval_carr_vendido($artigos);
            $d['precos'] = naval_carr_precos($vendido);
            $codigosRota = array_unique(array_merge(array_keys($d['rotas']), array_keys($stockInicial), array_keys($stock), array_keys($vendido), array_keys($movimentos)));
            foreach ($codigosRota as $codigo) {
                $d['rotas'][$codigo] = array_merge((array) ($d['rotas'][$codigo] ?? []), [
                    's0' => $stockInicial[$codigo] ?? [],
                    'v' => $vendido[$codigo] ?? [],
                    'mov' => $movimentos[$codigo] ?? [],
                ]);
            }
            foreach ($rotas as $rota) {
                $rota = (string) $rota;
                try {
                    $actualizada = naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use (
                        $data, $rota, $d, $referencia, $stock, $movimentos, $eu
                    ) {
                        $reg = naval_carr_ler($data, $rota);
                        if (!in_array(($reg['estado'] ?? ''), ['', 'por_contar'], true) || ($reg['contagem'] ?? null) !== null
                            || !empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em'])) return false;
                        $r = $d['rotas'][$rota] ?? null;
                        if (!is_array($r)) return false;
                        $r['post'] = naval_carr_posteriores($reg);
                        $r = naval_carr_congelar_base($r, $referencia, (array) ($stock[$rota] ?? []), (array) ($movimentos[$rota] ?? []));
                        if (!naval_carr_linhas($r, null, (array) $d['precos'])) return false;
                        $reg['base'] = array_diff_key($r, ['post' => 1]);
                        $reg['precos'] = (array) $d['precos'];
                        $reg['base_congelada_em'] = date('Y-m-d H:i:s');
                        $reg['base_congelada_por'] = (string) ($eu['login'] ?? '');
                        $reg['base_congelada_motivo'] = 'actualizacao_retroactiva';
                        $reg['historico'][] = ['em' => $reg['base_congelada_em'], 'por' => $reg['base_congelada_por'],
                            'accao' => 'base_actualizada_retroactivamente', 'referencia' => $referencia];
                        naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
                        return true;
                    });
                    if ($actualizada) $resultado['actualizadas'][] = ['data' => $data, 'rota' => $rota, 'referencia' => $referencia];
                    else $resultado['ignoradas']++;
                } catch (Throwable $e) {
                    error_log('[carrinha actualizar abertas] ' . $data . '/' . $rota . ': ' . $e->getMessage());
                    $resultado['falhas'][] = ['data' => $data, 'rota' => $rota, 'erro' => $e->getMessage()];
                }
            }
        } catch (Throwable $e) {
            error_log('[carrinha actualizar abertas] ' . $data . ': ' . $e->getMessage());
            $resultado['falhas'][] = ['data' => $data, 'erro' => $e->getMessage()];
        }
    }
    naval_registar('carrinha_abertas_actualizadas', ['actualizadas' => count($resultado['actualizadas']),
        'ignoradas' => $resultado['ignoradas'], 'falhas' => count($resultado['falhas'])]);
    $resultado['mais'] = $mais;
    $datasProcessadas = array_keys($dias);
    $resultado['continuar_de'] = $datasProcessadas ? (string) end($datasProcessadas) : $depoisDe;
    return $resultado;
}

/** Preço médio de venda de cada artigo no dia (todas as rotas): para dar valor às diferenças. */
function naval_carr_precos(array $vendido): array
{
    $q = [];
    $t = [];
    foreach ($vendido as $arts) foreach ($arts as $c => $x) {
        if ($x['q'] <= 0) continue;
        $q[$c] = ($q[$c] ?? 0) + $x['q'];
        $t[$c] = ($t[$c] ?? 0) + $x['t'];
    }
    $p = [];
    foreach ($q as $c => $n) $p[$c] = round($t[$c] / $n, 2);
    return $p;
}

// ── Cálculo (sem acesso a dados: testável) ──────────────────────────────────────────────────────────────────────

/**
 * Linhas da conciliação de uma rota. $r: {s0, s1, v, mov (null = sem movimentos separados)}; $contagem: {codigo: q} ou null.
 * Cada linha: codigo, artigo, unidade, inicial, carregado, vendido, devolvido, final, esperado, contado, diferenca,
 * preco, valor, alertas [negativo | sem_carga | nao_explicado].
 */
function naval_carr_linhas(array $r, ?array $contagem, array $precos = []): array
{
    $s0 = (array) ($r['s0'] ?? []);
    $s1 = (array) ($r['s1'] ?? []);
    $v = (array) ($r['v'] ?? []);
    $mov = $r['mov'] ?? null;
    $post = (array) ($r['post'] ?? []);   // carga de um dia seguinte já na carrinha antes da contagem (excepção autorizada)
    $codigos = array_keys($s0 + $s1 + $v + (array) ($mov ?? []) + (array) ($contagem ?? []) + $post + (array) ($r['corte']['q'] ?? []));
    $out = [];
    foreach ($codigos as $c) {
        $c = (string) $c;
        $ini = (float) ($s0[$c]['q'] ?? 0);
        $fim = (float) ($s1[$c]['q'] ?? 0);
        $ven = (float) ($v[$c]['q'] ?? 0);
        $alertas = [];
        $corte = isset($r['corte']['q']) ? (array) $r['corte']['q'] : null;
        $depois = 0.0;
        $deDocs = false;
        if ($corte !== null) {
            // até à referência congelada: o que entrou e saiu até esse instante; o restante fica de fora
            $noCorte = (float) ($corte[$c] ?? 0);
            $saldo = $noCorte - $ini + $ven;
            $car = max(0.0, $saldo);
            $dev = max(0.0, -$saldo);
            if (is_array($mov) && naval_carr_mov_com_hora($mov) && in_array(($r['corte']['origem'] ?? ''), ['documentos', 'contagem'], true)) {
                $car = (float) ($mov[$c]['ca'] ?? 0);   // carregado e devolvido até ao corte, documento a documento
                $dev = (float) ($mov[$c]['da'] ?? 0);
                $deDocs = true;
            }
            $esperado = $noCorte;
            $depois = round($fim - $noCorte, 3);
        } elseif (is_array($mov)) {
            $car = (float) ($mov[$c]['c'] ?? 0);
            $dev = (float) ($mov[$c]['d'] ?? 0);
            $esperado = $ini + $car - $ven;
            if (abs($esperado - $dev - $fim) > NAVAL_CARR_TOLERANCIA) $alertas[] = 'nao_explicado';
        } else {
            $saldo = $fim - $ini + $ven;
            $car = max(0.0, $saldo);
            $dev = max(0.0, -$saldo);
            $esperado = $fim;
        }
        if ($fim < -NAVAL_CARR_TOLERANCIA || $esperado < -NAVAL_CARR_TOLERANCIA) $alertas[] = 'negativo';
        // a carga posterior está fisicamente na carrinha: soma-se ao que se conta, para não entrar na conciliação do dia
        $pos = (float) ($post[$c] ?? 0);
        if ($ven > $ini + $car + NAVAL_CARR_TOLERANCIA) $alertas[] = 'sem_carga';
        $contado = ($contagem !== null && array_key_exists($c, $contagem)) ? (float) $contagem[$c] : ($contagem !== null ? 0.0 : null);
        $dif = $contado === null ? null : round($contado - $esperado - $pos, 3);
        $preco = (float) ($precos[$c] ?? (($v[$c]['q'] ?? 0) > 0 ? $v[$c]['t'] / $v[$c]['q'] : 0));
        if (!$ini && !$fim && !$ven && !$car && !$dev && !$contado && !$pos && !$depois) continue;
        // nada para contar: o artigo não devia estar na carrinha no fim do dia e não houve vendas (ex.: stock do dia
        // anterior retirado ou corrigido no Pinguim BO). Sem alertas e sem contagem feita, não entra na conciliação.
        $zero = function (float $x): bool { return abs($x) <= NAVAL_CARR_TOLERANCIA; };
        // (com o corte, a carga só para amanhã também fica de fora: não há nada dela para contar hoje)
        // (um artigo com documento de entrada ou saída antes do corte fica, para se ver o movimento: ex. devolução ao armazém)
        if (!$alertas && $zero($esperado) && ($corte !== null || $zero($fim)) && $zero($ven) && !$contado && $zero($pos) && !($deDocs && ($car > 0 || $dev > 0))) continue;
        $out[] = [
            'codigo' => $c,
            'artigo' => (string) ($v[$c]['n'] ?? $s1[$c]['n'] ?? $s0[$c]['n'] ?? $c),
            'unidade' => (string) ($v[$c]['u'] ?? ''),
            'inicial' => round($ini, 3), 'carregado' => round($car, 3), 'vendido' => round($ven, 3), 'devolvido' => round($dev, 3),
            'final' => round($fim, 3), 'esperado' => round($esperado, 3), 'depois_do_corte' => $depois, 'posterior' => round($pos, 3), 'contado' => $contado, 'diferenca' => $dif,
            'preco' => round($preco, 2), 'valor' => $dif === null ? null : round($dif * $preco, 2), 'alertas' => $alertas,
        ];
    }
    usort($out, function ($a, $b) {
        return [count($b['alertas']) > 0, abs((float) $b['diferenca'])] <=> [count($a['alertas']) > 0, abs((float) $a['diferenca'])] ?: strcmp($a['artigo'], $b['artigo']);
    });
    return $out;
}

/** Resumo e estado de uma rota: sem_movimento | por_contar | por_validar | por_aprovar | por_fechar | aprovada. */
function naval_carr_resumo(array $linhas, array $reg): array
{
    $dif = array_values(array_filter($linhas, function ($l) { return $l['diferenca'] !== null && abs($l['diferenca']) > NAVAL_CARR_TOLERANCIA; }));
    $estado = (string) ($reg['estado'] ?? '');
    if ($estado === '') $estado = !$linhas ? 'sem_movimento' : ($reg['contagem'] === null ? 'por_contar' : 'por_aprovar');
    $falta = 0.0;
    $sobra = 0.0;
    foreach ($dif as $l) {
        if ($l['valor'] < 0) $falta += -$l['valor'];
        else $sobra += $l['valor'];
    }
    return [
        'estado' => $estado,
        'artigos' => count($linhas),
        'alertas' => count(array_filter($linhas, function ($l) { return (bool) $l['alertas']; })),
        'diferencas' => count($dif),
        'falta_kz' => round($falta, 2),
        'sobra_kz' => round($sobra, 2),
        'vendido' => round(array_sum(array_column($linhas, 'vendido')), 3),
        'carregado' => round(array_sum(array_column($linhas, 'carregado')), 3),
    ];
}

/** A conciliação fecha sozinha? Contagem feita, nenhuma diferença e nenhum alerta (stock negativo, venda sem carga…). */
function naval_carr_fecha_sozinha(array $resumo): bool
{
    return $resumo['diferencas'] === 0 && $resumo['alertas'] === 0 && $resumo['artigos'] > 0;
}

/**
 * Painel da carrinha (um ecrã por carrinha e dia): fecho de rota, carga e as ligações a outros sistemas. Junta a
 * conciliação (stock inicial e final do Pinguim BO, carregado, vendido, devolvido, contagem e aprovação) com o peso da
 * carga e o estado dos conectores das contagens da solução do Pinguim BO, da Quatenus e do CCTV (blocos preparados:
 * passam a ter dados quando cada ligação existir). Contagem cega: quem conta não vê totais antes de contar.
 */
function naval_carr_painel(string $data, ?array $rotas, bool $gere, bool $actualizar = false): array
{
    naval_servico('modulos');
    $v = naval_carr_vista($data, $rotas, $gere, $actualizar);
    $pesos = $data === date('Y-m-d') ? naval_carga_na_carrinha_seguro() : [];
    $con = naval_conectores();
    $out = [];
    foreach ($v['rotas'] as $r) {
        $res = $r['resumo'];
        $cega = !empty($r['cega']);
        $fecho = (string) $res['estado'];
        if ($fecho === 'aprovada') $fecho = ($r['aprovado_por'] ?? '') === 'automatico' ? 'fechada_auto' : 'aprovada';
        $out[] = [
            'rota' => $r['rota'], 'promotor' => $r['promotor'], 'fecho' => $fecho,
            'artigos' => $res['artigos'], 'alertas' => $cega ? null : $res['alertas'], 'diferencas' => $cega ? null : $res['diferencas'],
            'falta_kz' => $cega ? null : $res['falta_kz'], 'sobra_kz' => $cega ? null : $res['sobra_kz'],
            'carregado' => $cega ? null : $res['carregado'], 'vendido' => $cega ? null : $res['vendido'],
            'contado_por' => $r['contado_por'], 'contado_em' => $r['contado_em'], 'validado_por' => $r['validado_por'], 'validado_em' => $r['validado_em'],
            'coordenado_por' => $r['coordenado_por'], 'coordenado_em' => $r['coordenado_em'], 'aprovado_por' => $r['aprovado_por'], 'aprovado_em' => $r['aprovado_em'],
            'peso_kg' => isset($pesos[$r['rota']]) ? round((float) $pesos[$r['rota']], 1) : null,
        ];
    }
    $fontes = [
        ['id' => 'pinguim-sql', 'bloco' => 'Stock, vendas e movimentos', 'estado' => $con['pinguim-sql']['estado']],
        ['id' => 'contagens', 'bloco' => 'Contagens da solução do Pinguim BO', 'estado' => naval_config('PINGUIM_CONTAGENS_TABELA') !== '' ? 'ligado' : 'por_ligar'],
        ['id' => 'quatenus', 'bloco' => 'Percurso, paragens e aberturas de porta (Quatenus)', 'estado' => $con['quatenus']['estado']],
        ['id' => 'cctv', 'bloco' => 'Imagens da carga e da descarga (CCTV)', 'estado' => $con['cctv']['estado']],
    ];
    return ['data' => $data, 'gerado' => $v['gerado'], 'movimentos' => $v['movimentos'], 'rotas' => $out, 'fontes' => $fontes];
}

/** Retira os números que revelam o esperado (contagem cega), deixando o artigo e a unidade. */
function naval_carr_cega(array $linhas): array
{
    return array_map(function ($l) {
        return ['codigo' => $l['codigo'], 'artigo' => $l['artigo'], 'unidade' => $l['unidade'], 'contado' => $l['contado'], 'alertas' => []];
    }, $linhas);
}

/**
 * Vista do dia para o portal: rotas do âmbito, com linhas, resumo e registo. Contagem às cegas: enquanto a rota está
 * por contar, as quantidades do sistema só vão para quem coordena ou fecha a conciliação ($veEsperado) e não conta;
 * quem conta e quem só consulta (ex.: quem acompanha a contagem) vê os artigos sem números.
 */
function naval_carr_vista(string $data, ?array $rotas, bool $gere, bool $actualizar = false, bool $veEsperado = false): array
{
    $d = naval_carr_dados($data, $actualizar);
    $cega = naval_config('CARRINHA_CONTAGEM_CEGA', '1') !== '0';
    $promotores = naval_promotores();
    $out = [];
    foreach ($d['rotas'] as $rota => $r) {
        $rota = (string) $rota;
        if ($rotas !== null && !in_array($rota, $rotas, true)) continue;
        $reg = naval_carr_ler($data, $rota);
        // depois de impressa a folha, usa a base congelada que também será usada ao gravar e validar a contagem
        $base = naval_carr_base($data, $rota, is_array($reg['base'] ?? null) ? $reg['base'] : $r, $reg);
        $linhas = naval_carr_linhas($base, $reg['contagem'], (array) ($reg['precos'] ?? $d['precos'] ?? []));
        $resumo = naval_carr_resumo($linhas, $reg);
        if ($resumo['estado'] === 'sem_movimento') continue;
        $oculta = $cega && $resumo['estado'] === 'por_contar' && ($gere || !$veEsperado);
        if ($oculta) $linhas = naval_carr_cega($linhas);
        $out[] = ['rota' => $rota, 'promotor' => $promotores[$rota] ?? $rota, 'resumo' => $resumo, 'linhas' => $linhas,
            'cega' => $gere && $cega && $resumo['estado'] === 'por_contar', 'quantidades_ocultas' => $oculta,
            'contado_por' => $reg['contado_por'] ?? null, 'contado_em' => $reg['contado_em'] ?? null,
            'validado_por' => $reg['validado_por'] ?? null, 'validado_em' => $reg['validado_em'] ?? null,
            'coordenado_por' => $reg['coordenado_por'] ?? null, 'coordenado_em' => $reg['coordenado_em'] ?? null,
            'aprovado_por' => $reg['aprovado_por'] ?? null, 'aprovado_em' => $reg['aprovado_em'] ?? null,
            'motivos' => (object) ($reg['motivos'] ?? []), 'historico' => array_slice((array) $reg['historico'], -20),
            'requisicao' => naval_req_da_conciliacao_numero($data, $rota),
            'corte' => isset($base['corte']) ? ['hora' => $base['corte']['hora'], 'origem' => $base['corte']['origem'],
                'vendido_depois' => (float) ($base['corte']['vendido_depois'] ?? 0)] : null,
            'cargas_posteriores' => array_map(function ($x) { return ['numero' => $x['numero'], 'confirmado_em' => $x['confirmado_em'], 'autorizado_por' => $x['autorizado_por'] ?? '']; }, (array) ($reg['cargas_posteriores'] ?? []))];
    }
    return ['data' => $data, 'gerado' => $d['gerado'], 'movimentos' => $d['movimentos'], 'rotas' => $out, 'motivos' => NAVAL_CARR_MOTIVOS];
}

/** Grava a contagem do técnico (estado → por_validar) e reutiliza a base congelada na primeira folha. */
function naval_carr_contar(string $data, string $rota, array $contagem, array $eu): array
{
    if ($data > date('Y-m-d')) throw new NavalErro('Não se conta uma carrinha num dia que ainda não chegou.', 400);
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $contagem, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] === 'aprovada') throw new NavalErro('Esta conciliação já foi aprovada.', 409);
        $d = naval_carr_dados($data, true);
        $contadoEm = date('Y-m-d H:i:s');
        $congelada = (!empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em'])) && is_array($reg['base'] ?? null);
        if ((!empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em'])) && !$congelada) {
            throw new NavalErro('A base congelada da folha de contagem está incompleta. Contacte a administração.', 503);
        }
        $r = $congelada ? (array) $reg['base'] : ($d['rotas'][$rota] ?? null);
        if ($r === null) throw new NavalErro('Esta rota não teve movimentos na carrinha neste dia.', 404);
        $r['post'] = naval_carr_posteriores($reg);
        if (!$congelada) $r = naval_carr_base_no_instante($data, $rota, $r, naval_carr_hora_referencia($data));
        $precos = $congelada ? (array) ($reg['precos'] ?? $d['precos']) : $d['precos'];
        $codigos = array_flip(array_map('strval', array_keys($r['s0'] + $r['s1'] + $r['v'] + (array) ($r['mov'] ?? []) + (array) ($r['post'] ?? []))));
        $c = [];
        foreach ($contagem as $cod => $q) {
            $cod = (string) $cod;
            if (!isset($codigos[$cod]) || !is_numeric($q)) continue;
            $q = (float) $q;
            if ($q < 0 || $q > 1000000) throw new NavalErro('Quantidade inválida no artigo ' . $cod . '.', 400);
            $c[$cod] = round($q, 3);
        }
        $novo = $reg['contagem'] !== null;
        $reg = array_merge($reg, ['contagem' => $c, 'base' => $r, 'precos' => $precos,
            'folha_congelada_em' => $reg['folha_congelada_em'] ?? $reg['base_congelada_em'] ?? $contadoEm,
            'folha_congelada_por' => $reg['folha_congelada_por'] ?? $reg['base_congelada_por'] ?? (string) $eu['login'],
            'base_congelada_em' => $reg['base_congelada_em'] ?? $reg['folha_congelada_em'] ?? $contadoEm,
            'base_congelada_por' => $reg['base_congelada_por'] ?? $reg['folha_congelada_por'] ?? (string) $eu['login'],
            'base_congelada_motivo' => $reg['base_congelada_motivo'] ?? 'contagem_directa', 'estado' => 'por_validar', 'motivos' => [],
            'contado_por' => (string) $eu['login'], 'contado_em' => $contadoEm, 'validado_por' => null, 'validado_em' => null,
            'coordenado_por' => null, 'coordenado_em' => null, 'aprovado_por' => null, 'aprovado_em' => null]);
        $reg['base'] = array_diff_key($r, ['post' => 1]);
        $linhas = naval_carr_linhas($r, $c, $precos);
        $res = naval_carr_resumo($linhas, $reg);
        $reg['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => (string) $eu['login'], 'accao' => $novo ? 'recontagem' : 'contagem',
            'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz'], 'sobra_kz' => $res['sobra_kz']];
        naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
        naval_registar('carrinha_contagem', ['data' => $data, 'rota' => $rota, 'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz']]);
        return ['linhas' => $linhas, 'resumo' => $res];
    });
}

/**
 * O técnico que contou valida a contagem (estado → por_aprovar). Gestão por excepção (fecho de rota da distribuição
 * directa, «route settlement»): contagem igual ao esperado em todos os artigos e sem alertas → fecha sozinha.
 */
function naval_carr_validar(string $data, string $rota, array $eu): array
{
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] !== 'por_validar' || !is_array($reg['base'] ?? null)) throw new NavalErro('Esta contagem não está por validar.', 409);
        if (strcasecmp((string) ($reg['contado_por'] ?? ''), (string) $eu['login']) !== 0) {
            throw new NavalErro('Só quem fez a contagem a pode validar.', 403);
        }
        $linhas = naval_carr_linhas(naval_carr_base($data, $rota, (array) $reg['base'], $reg), $reg['contagem'], (array) ($reg['precos'] ?? []));
        $agora = date('Y-m-d H:i:s');
        $reg['estado'] = 'por_aprovar';
        $reg['validado_por'] = (string) $eu['login'];
        $reg['validado_em'] = $agora;
        $res = naval_carr_resumo($linhas, $reg);
        $reg['historico'][] = ['em' => $agora, 'por' => (string) $eu['login'], 'accao' => 'validada', 'diferencas' => $res['diferencas'],
            'falta_kz' => $res['falta_kz'], 'sobra_kz' => $res['sobra_kz']];
        if (naval_carr_fecha_sozinha($res) && naval_config('CARRINHA_FECHO_AUTOMATICO', '1') !== '0') {
            $reg['estado'] = 'aprovada';
            $reg['aprovado_por'] = 'automatico';
            $reg['aprovado_em'] = $agora;
            $reg['historico'][] = ['em' => $agora, 'por' => 'automatico', 'accao' => 'fecho_automatico', 'diferencas' => 0, 'falta_kz' => 0, 'sobra_kz' => 0];
            $res = naval_carr_resumo($linhas, $reg);
        }
        naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
        naval_registar('carrinha_validada', ['data' => $data, 'rota' => $rota, 'diferencas' => $res['diferencas'], 'fecho_automatico' => $reg['estado'] === 'aprovada']);
        // Reposição: requisição ao armazém com o que foi vendido, em rascunho para o promotor rever (não trava a validação)
        $req = null;
        try {
            $q = naval_req_da_conciliacao($data, $rota, $linhas, (string) $eu['login']);
            $req = $q ? $q['numero'] : null;
        } catch (Throwable $e) {
            error_log('[requisicao conciliacao] ' . $e->getMessage());
        }
        return ['linhas' => $linhas, 'resumo' => $res, 'requisicao' => $req];
    });
}

/**
 * 1.º nível (coordenador): aprova a contagem validada, com o motivo de cada diferença (e nota em «outro»);
 * estado → por_fechar. Quem contou não aprova (segregação de funções).
 */
function naval_carr_aprovar(string $data, string $rota, array $motivos, array $eu): array
{
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $motivos, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] === 'aprovada') throw new NavalErro('Esta conciliação já foi aprovada.', 409);
        if ($reg['contagem'] === null || !is_array($reg['base'] ?? null)) throw new NavalErro('Conte a carrinha antes de aprovar.', 409);
        if ($reg['estado'] === 'por_validar') throw new NavalErro('A contagem ainda não foi validada por quem a fez.', 409);
        if ($reg['estado'] !== 'por_aprovar') throw new NavalErro('Esta conciliação já foi aprovada pelo coordenador.', 409);
        naval_carr_segregar($reg, (string) $eu['login'], ['contado_por']);
        $linhas = naval_carr_linhas(naval_carr_base($data, $rota, (array) $reg['base'], $reg), $reg['contagem'], (array) ($reg['precos'] ?? []));
        $m = [];
        $faltam = [];
        foreach ($linhas as $l) {
            if ($l['diferenca'] === null || abs($l['diferenca']) <= NAVAL_CARR_TOLERANCIA) continue;
            $x = (array) ($motivos[$l['codigo']] ?? []);
            $mot = (string) ($x['motivo'] ?? '');
            $nota = naval_texto($x['nota'] ?? '', 300);
            if (!isset(NAVAL_CARR_MOTIVOS[$mot]) || ($mot === 'outro' && mb_strlen($nota) < 5)) {
                $faltam[] = $l['artigo'];
                continue;
            }
            $m[$l['codigo']] = ['motivo' => $mot, 'nota' => $nota];
        }
        if ($faltam) throw new NavalErro('Indique o motivo de cada diferença:', 400, ['erros' => $faltam]);
        $reg['motivos'] = $m;
        $reg['estado'] = 'por_fechar';
        $reg['coordenado_por'] = (string) $eu['login'];
        $reg['coordenado_em'] = date('Y-m-d H:i:s');
        $res = naval_carr_resumo($linhas, $reg);
        $reg['historico'][] = ['em' => $reg['coordenado_em'], 'por' => $reg['coordenado_por'], 'accao' => 'aprovada_coordenador', 'diferencas' => $res['diferencas'],
            'falta_kz' => $res['falta_kz'], 'sobra_kz' => $res['sobra_kz']];
        naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
        naval_registar('carrinha_aprovada_coordenador', ['data' => $data, 'rota' => $rota, 'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz']]);
        return ['linhas' => $linhas, 'resumo' => $res];
    });
}

/**
 * 2.º nível (responsável): aprovação final, que fecha a conciliação (estado → aprovada). Nem quem contou nem o
 * coordenador que aprovou o 1.º nível a podem fazer. Avisa a Direcção se a falta passar o limite.
 */
function naval_carr_fechar(string $data, string $rota, array $eu): array
{
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] === 'aprovada') throw new NavalErro('Esta conciliação já foi aprovada.', 409);
        if ($reg['estado'] !== 'por_fechar' || !is_array($reg['base'] ?? null)) throw new NavalErro('Falta a aprovação do coordenador.', 409);
        naval_carr_segregar($reg, (string) $eu['login'], ['contado_por', 'coordenado_por']);
        $linhas = naval_carr_linhas(naval_carr_base($data, $rota, (array) $reg['base'], $reg), $reg['contagem'], (array) ($reg['precos'] ?? []));
        $m = (array) ($reg['motivos'] ?? []);
        $reg['estado'] = 'aprovada';
        $reg['aprovado_por'] = (string) $eu['login'];
        $reg['aprovado_em'] = date('Y-m-d H:i:s');
        $res = naval_carr_resumo($linhas, $reg);
        $reg['historico'][] = ['em' => $reg['aprovado_em'], 'por' => $reg['aprovado_por'], 'accao' => 'aprovada', 'diferencas' => $res['diferencas'],
            'falta_kz' => $res['falta_kz'], 'sobra_kz' => $res['sobra_kz']];
        naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
        naval_registar('carrinha_aprovada', ['data' => $data, 'rota' => $rota, 'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz']]);
        $limite = (float) naval_config('CARRINHA_LIMITE_KZ', '50000');
        if ($res['falta_kz'] >= $limite && $limite > 0) {
            try {
                naval_carr_avisar_falta($data, $rota, $linhas, $m, $res, $eu);
            } catch (Throwable $e) {
                error_log('[carrinha aviso] ' . $e->getMessage());
            }
        }
        return ['linhas' => $linhas, 'resumo' => $res];
    });
}

function naval_carr_avisar_falta(string $data, string $rota, array $linhas, array $motivos, array $res, array $eu): void
{
    $dest = array_values(array_filter(naval_destinatarios_recurso('conciliacao-carrinha', 'V', $rota), function ($d) use ($eu) {
        return strcasecmp($d['email'], (string) ($eu['email'] ?? '')) !== 0;
    }));
    if (!$dest) return;
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $td = 'style="padding:4px 8px;border-bottom:1px solid #dde3ee;font:13px Segoe UI,Arial,sans-serif"';
    $linhasHtml = '';
    foreach ($linhas as $l) {
        if ($l['diferenca'] === null || abs($l['diferenca']) <= NAVAL_CARR_TOLERANCIA) continue;
        $linhasHtml .= '<tr><td ' . $td . '>' . $h($l['artigo']) . '</td><td ' . $td . ' align="right">' . $h(number_format($l['diferenca'], 0, ',', ' '))
            . '</td><td ' . $td . ' align="right">' . $h(number_format((float) $l['valor'], 2, ',', ' ')) . ' Kz</td><td ' . $td . '>'
            . $h(NAVAL_CARR_MOTIVOS[$motivos[$l['codigo']]['motivo'] ?? ''] ?? '') . ' ' . $h($motivos[$l['codigo']]['nota'] ?? '') . '</td></tr>';
    }
    $html = '<div style="font:14px Segoe UI,Arial,sans-serif;color:#0a2a52;text-align:justify">'
        . '<p>A conciliação da carrinha da rota <b>' . $h($rota) . '</b> de ' . $h(naval_data_pt($data)) . ' foi aprovada por ' . $h($eu['nome'] ?? $eu['login'])
        . ' com uma falta de <b>' . $h(number_format($res['falta_kz'], 2, ',', ' ')) . ' Kz</b>, acima do limite definido.</p>'
        . '<table cellspacing="0" style="border-collapse:collapse"><tr><th ' . $td . ' align="left">Artigo</th><th ' . $td . '>Diferença</th><th ' . $td
        . '>Valor</th><th ' . $td . ' align="left">Motivo</th></tr>' . $linhasHtml . '</table>'
        . '<p>Detalhe no Portal RTM › Logística › Conciliação da carrinha.</p></div>';
    naval_enviar_email($dest, 'Conciliação da carrinha · rota ' . $rota . ' · falta de ' . number_format($res['falta_kz'], 0, ',', ' ') . ' Kz', $html);
}

/**
 * Aviso do fim do dia (CARRINHA_HORA_AVISO, 19:30) a quem conta e aprova: rotas do dia ainda por contar ou por aprovar,
 * com as que já têm alertas automáticos. Uma vez por dia. Devolve o número de rotas pendentes ou null se não correu.
 */
function naval_carr_aviso_diario(int $agora): ?int
{
    $hora = naval_config('CARRINHA_HORA_AVISO', '19:30');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) $hora = '19:30';
    $dia = date('Y-m-d', $agora);
    $marca = naval_carr_pasta($dia) . '/_aviso.json';
    if (date('H:i', $agora) < $hora || is_file($marca)) return null;
    naval_json_gravar($marca, ['em' => date('Y-m-d H:i:s', $agora)]);
    $v = naval_carr_vista($dia, null, false, true);
    $pend = array_values(array_filter($v['rotas'], function ($r) { return in_array($r['resumo']['estado'], ['por_contar', 'por_validar', 'por_aprovar', 'por_fechar'], true); }));
    if (!$pend) return 0;
    $dest = naval_destinatarios_recurso('conciliacao-carrinha', 'G');
    if (!$dest) return count($pend);
    $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $li = '';
    foreach ($pend as $r) {
        $li .= '<li>Rota <b>' . $h($r['rota']) . '</b> · ' . $h($r['promotor']) . ' · '
            . (['por_contar' => 'por contar', 'por_validar' => 'contada, por validar por quem contou', 'por_aprovar' => 'por aprovar pelo coordenador',
                'por_fechar' => 'por aprovar pelo responsável'][$r['resumo']['estado']] ?? $r['resumo']['estado'])
            . ($r['resumo']['diferencas'] ? ' (' . $r['resumo']['diferencas'] . ' diferença(s))' : '')
            . ($r['resumo']['alertas'] ? ' · <b>' . $r['resumo']['alertas'] . ' artigo(s) com alerta automático</b>' : '') . '</li>';
    }
    $url = rtrim(naval_config('PORTAL_URL'), '/');
    naval_enviar_email($dest, 'Conciliação das carrinhas · ' . naval_data_pt($dia) . ' · ' . count($pend) . ' rota(s) pendente(s)',
        '<div style="font:14px Segoe UI,Arial,sans-serif;color:#0a2a52;text-align:justify"><p>Carrinhas que regressaram hoje e ainda aguardam a contagem ou a aprovação do armazém:</p><ul>'
        . $li . '</ul><p>Contar e aprovar no Portal RTM › Logística › Conciliação da carrinha' . ($url !== '' ? ' (' . $h($url) . '/reports/#conciliacao-carrinha)' : '') . '.</p></div>');
    return count($pend);
}

// ── Limite de peso da carga ──────────────────────────────────────────────────────────────────────────────────────

/** Peso unitário de cada artigo no Pinguim BO (kg), se a tabela PRODUCT tiver a coluna; {} se não houver. */
function naval_carga_pesos_sku(): array
{
    static $p = null;
    if ($p !== null) return $p;
    $p = [];
    if (!naval_bd_configurada()) return $p;
    try {
        $cols = array_column(naval_bd_consultar("
            SELECT UPPER(COLUMN_NAME) AS C FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo' AND UPPER(TABLE_NAME) = 'PRODUCT'"), 'C');
        $col = '';
        foreach (['NET_WEIGHT', 'GROSS_WEIGHT', 'WEIGHT', 'PESO'] as $c) if (in_array($c, $cols, true)) { $col = $c; break; }
        if ($col === '') return $p;
        foreach (naval_bd_consultar("SELECT P.CODE AS CODIGO, CAST(P.$col AS FLOAT) AS PESO FROM PRODUCT P WHERE P.$col > 0") as $r) {
            $p[trim((string) $r['CODIGO'])] = (float) $r['PESO'];
        }
    } catch (Throwable $e) {
        error_log('[pesos SQL] ' . $e->getMessage());
    }
    return $p;
}

/**
 * Estado da carga de cada rota do plano: capacidade da viatura (kg, pela matrícula), peso que ainda está na carrinha
 * (stock actual no Pinguim × peso de cada artigo: baixa à medida que vende), peso planeado (quantidade × kg por
 * unidade de carga do artigo do plano), disponível e se excede. $naCarrinha: {rota: kg} (null = ler o Pinguim BO).
 */
function naval_carga_estado(array $rotasPlano, array $listas, ?array $naCarrinha = null): array
{
    $cap = (array) ($listas['capacidades'] ?? []);
    $peso = [];
    foreach ((array) $listas['artigos'] as $a) $peso[$a['id']] = (float) ($a['peso'] ?? 0);
    if ($naCarrinha === null) $naCarrinha = naval_carga_na_carrinha();
    $out = [];
    foreach ($rotasPlano as $r) {
        $rota = (string) ($r['rota'] ?? '');
        if ($rota === '') continue;
        $mat = strtoupper(trim((string) ($r['matricula'] ?? '')));
        $c = $mat !== '' && isset($cap[$mat]) ? (float) $cap[$mat] : null;
        $plan = 0.0;
        $semPeso = [];
        foreach ((array) ($r['quantidades'] ?? []) as $id => $q) {
            if ((float) $q <= 0) continue;
            if (empty($peso[$id])) $semPeso[] = (string) $id;
            $plan += (float) $q * ($peso[$id] ?? 0);
        }
        // com requisição, as quantidades do plano já são o total à saída (com as existências na carrinha)
        $na = empty($r['carga_total']) ? (float) ($naCarrinha[$rota] ?? 0) : 0.0;
        $out[$rota] = ['matricula' => $mat, 'capacidade' => $c, 'na_carrinha' => round($na, 1), 'planeado' => round($plan, 1),
            'disponivel' => $c === null ? null : round($c - $na, 1), 'excede' => $c !== null && !empty($r['sai']) && $plan + $na > $c + 0.05,
            'sem_peso' => $semPeso];
    }
    return $out;
}

/** Peso (kg) do que está agora em cada carrinha, pelo stock do Pinguim BO e o peso de cada artigo. */
function naval_carga_na_carrinha(): array
{
    $pesos = naval_carga_pesos_sku();
    if (!$pesos) return [];
    $s = naval_carr_stock(date('Y-m-d')) ?? [];
    $out = [];
    foreach ($s as $rota => $arts) foreach ($arts as $c => $x) if ($x['q'] > 0) $out[$rota] = ($out[$rota] ?? 0) + $x['q'] * ($pesos[$c] ?? 0);
    return $out;
}

/** Erros de capacidade (para bloquear a gravação e a publicação do plano). */
function naval_carga_erros(array $estado): array
{
    $e = [];
    foreach ($estado as $rota => $x) {
        if (!$x['excede']) continue;
        $e[] = sprintf('Rota %s (%s): a carga planeada (%s kg) mais o que ainda está na carrinha (%s kg) passa a capacidade da viatura (%s kg). Só pode carregar mais %s kg.',
            $rota, $x['matricula'], naval_carga_kg($x['planeado']), naval_carga_kg($x['na_carrinha']), naval_carga_kg((float) $x['capacidade']), naval_carga_kg(max(0, (float) $x['disponivel'])));
    }
    return $e;
}

function naval_carga_kg(float $v): string
{
    return number_format($v, 0, ',', ' ');
}

/** Como naval_carga_na_carrinha, sem nunca falhar (sem base de dados: {} e o limite conta só a carga planeada). */
function naval_carga_na_carrinha_seguro(): array
{
    try {
        return naval_carga_na_carrinha();
    } catch (Throwable $e) {
        error_log('[peso na carrinha] ' . $e->getMessage());
        return [];
    }
}

/** Utilizadores activos do portal (para escolher quem contou / validou numa correcção), por ordem alfabética. */
function naval_carr_contas(): array
{
    $out = [];
    foreach (naval_utilizadores() as $login => $u) if (!empty($u['activo']) && ($u['perfil'] ?? '') !== 'EXT') $out[] = (string) $login;
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Correcção do registo de quem contou e de quem validou (só o super administrador), para contagens feitas antes do
 * circuito actual ou registadas na conta de outra pessoa. Não muda números, estado nem aprovações: só os nomes, com
 * motivo obrigatório, e o que estava antes fica no histórico (registo de auditoria rastreável).
 * $rotas: rotas do dia a corrigir (só as já contadas). Devolve as rotas corrigidas.
 */
function naval_carr_corrigir_registo(string $data, array $rotas, string $contado, string $validado, string $motivo, array $eu): array
{
    // só contas existentes e activas do portal (utilizador no formato primeiro.último), escolhidas da lista
    $contas = naval_carr_contas();
    $nome = function (string $s, string $o) use ($contas) {
        $s = trim($s);
        if ($s === '') return null;
        if (!in_array($s, $contas, true)) throw new NavalErro('Escolha da lista o utilizador em «' . $o . '».', 400);
        return $s;
    };
    $contado = $nome($contado, 'Contado por');
    $validado = $nome($validado, 'Validada por');
    if ($contado === null && $validado === null) throw new NavalErro('Indique quem contou ou quem validou.', 400);
    $motivo = naval_texto($motivo, 300);
    if (mb_strlen($motivo) < 10) throw new NavalErro('Indique o motivo da correcção.', 400);
    $feitas = [];
    foreach (array_unique(array_map('strval', $rotas)) as $rota) {
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) continue;
        $ok = naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $contado, $validado, $motivo, $eu) {
            $reg = naval_carr_ler($data, $rota);
            if ($reg['contagem'] === null) return false;
            $antes = ['contado_por' => $reg['contado_por'] ?? null, 'validado_por' => $reg['validado_por'] ?? null];
            if ($contado !== null) $reg['contado_por'] = $contado;
            if ($validado !== null) {
                $reg['validado_por'] = $validado;
                // validação sem data registada (contagens anteriores ao passo «validar»): a da contagem
                if (empty($reg['validado_em'])) $reg['validado_em'] = $reg['contado_em'] ?? null;
            }
            $depois = ['contado_por' => $reg['contado_por'], 'validado_por' => $reg['validado_por']];
            if ($antes === $depois) return false;
            $reg['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => (string) $eu['login'], 'accao' => 'correccao_registo', 'antes' => $antes, 'depois' => $depois, 'motivo' => $motivo];
            naval_json_gravar(naval_carr_pasta($data) . '/' . $rota . '.json', $reg);
            return true;
        });
        if ($ok) $feitas[] = $rota;
    }
    if ($feitas) naval_registar('carrinha_correccao_registo', ['data' => $data, 'rotas' => $feitas, 'motivo' => $motivo]);
    return $feitas;
}
