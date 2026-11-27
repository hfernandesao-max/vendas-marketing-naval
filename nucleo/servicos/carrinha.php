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
    'troca_artigo' => 'Troca de artigo na entrega',
    'consignacao' => 'Venda à consignação (facturar no SAP)',
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
            // o dia sem actividade não precisa de contagem: não fica por fechar
            if ($r['rota'] === $rota && !in_array($r['resumo']['estado'], ['aprovada', 'sem_actividade'], true)) $out['pendentes'][] = ['data' => $dia, 'estado' => $r['resumo']['estado']];
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
 * Stock de uma rota no corte, por artigo: {codigo: quantidade, …}. Prioriza o saldo de abertura mais os movimentos
 * do Pinguim BO registados até à hora; fotografias antigas são usadas apenas quando não há movimentos horários.
 * Null antes do corte.
 */
function naval_carr_stock_corte(string $data, string $rota, array $r, ?string $hora = null): ?array
{
    if ($hora === null) {
        $hora = naval_carr_corte($data);
        if ($hora === null || ($data === date('Y-m-d') && date('H:i') < $hora)) return null;
    }
    $mov = $r['mov'] ?? null;
    if (is_array($mov) && naval_carr_mov_com_hora($mov)) {
        $stock = naval_carr_stock_por_movimentos((array) ($r['s0'] ?? []), $mov, $r);
        $q = array_map(function ($x) { return (float) ($x['q'] ?? 0); }, $stock);
        $vendidoDepois = 0.0;
        foreach ($mov as $x) $vendidoDepois += (float) ($x['vd'] ?? 0);
        return ['q' => $q, 'origem' => 'documentos', 'hora' => $hora, 'vendido_depois' => round($vendidoDepois, 3)];
    }
    $foto = naval_json_ler(naval_carr_pasta($data) . '/_corte.json');
    if ($foto && isset($foto['rotas'])) {
        $q = [];
        foreach ((array) ($foto['rotas'][$rota] ?? []) as $c => $x) $q[(string) $c] = (float) ($x['q'] ?? 0);
        $vendidoDepois = 0.0;
        foreach ((array) ($mov ?? []) as $c => $x) {
            if (!is_array($x) || empty($x['vd'])) continue;
            $q[(string) $c] = round(($q[(string) $c] ?? 0) - (float) $x['vd'], 3);
            $vendidoDepois += (float) $x['vd'];
        }
        return ['q' => $q, 'origem' => 'fotografia', 'hora' => (string) ($foto['hora'] ?? $hora), 'vendido_depois' => round($vendidoDepois, 3)];
    }
    $q = [];
    foreach ((array) ($r['s0'] ?? []) as $c => $x) $q[(string) $c] = (float) ($x['q'] ?? 0);
    foreach ((array) ($r['v'] ?? []) as $c => $x) $q[(string) $c] = ($q[(string) $c] ?? 0) - (float) ($x['q'] ?? 0);
    foreach (naval_carr_cargas_portal($data, $rota, $hora) as $c => $x) $q[(string) $c] = ($q[(string) $c] ?? 0) + $x;
    return ['q' => $q, 'origem' => 'estimado', 'hora' => $hora];
}

/** Reconstrói o saldo no corte a partir do stock de abertura e dos movimentos registados até à hora. */
function naval_carr_stock_por_movimentos(array $inicial, array $mov, array $dadosRota = []): array
{
    $q = [];
    foreach ($inicial as $codigo => $x) {
        $codigo = (string) $codigo;
        $q[$codigo] = ['n' => (string) ($x['n'] ?? $codigo), 'q' => (float) ($x['q'] ?? 0)];
    }
    foreach ($mov as $codigo => $x) {
        $codigo = (string) $codigo;
        if (!isset($q[$codigo])) {
            $nome = $dadosRota['s1'][$codigo]['n'] ?? $dadosRota['v'][$codigo]['n'] ?? $codigo;
            $q[$codigo] = ['n' => (string) $nome, 'q' => 0.0];
        }
        $q[$codigo]['q'] = round($q[$codigo]['q'] - (float) ($x['va'] ?? 0)
            + (float) ($x['ca'] ?? 0) - (float) ($x['da'] ?? 0) + (float) ($x['ia'] ?? 0), 3);
    }
    return $q;
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
    foreach (naval_req_ficheiros(substr($data, 0, 4)) as $f) {
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
    $r['regs'] = (array) ($reg['regularizacoes'] ?? []);   // diferenças explicadas por acertos posteriores no Pinguim BO
    $r['diags'] = (array) ($reg['diagnosticos'] ?? []);    // diagnóstico das que ficam (documento a corrigir)
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
 * (DOCMOVDETAIL × DOCHEADER × DOCCONFIG.STOCK_SIGNAL: guias de transporte GTC +1, saídas de armazém SA −1, …), pelo
 * armazém da linha (o mesmo do stock, dbo.GET_STOCK; sem armazém, pela loja do documento). Os documentos anulados não
 * contam (como no stock do Pinguim BO): uma guia anulada não é carga. {rota: {codigo: ['c' => carregado, 'd' => devolvido, 'ca' => carregado antes do corte,
 * 'da' => devolvido antes do corte]}}. A hora é a do registo do documento (CLOCK_DATE). null sem ligação ou se a
 * consulta falhar (o cálculo usa então o saldo do dia).
 */
function naval_carr_movimentos_sql(string $data, ?string $hora = null, ?string $ate = null): ?array
{
    if (!naval_bd_configurada()) return null;
    // $ate: contagem feita noutro dia: movimentos desde o início de $data até $ate às $hora (o instante da contagem)
    $ate = $ate !== null && naval_data_valida($ate) && $ate > $data ? $ate : $data;
    $corte = $hora ?? ((naval_carr_corte($ate) ?? '23:59') . ':00');
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $corte)) throw new InvalidArgumentException('Hora de referência inválida.');
    naval_servico('vendas');
    $validos = naval_vendas_condicao_validos();
    $anulados = naval_vendas_condicao_anulados();
    $naoAnulado = 'COALESCE(DH.CALC_ISCANCELED, 0) = 0' . ($anulados !== '' ? ' AND NOT ' . $anulados : '');
    try {
        // Separa movimentos e vendas antes/depois da hora de referência (CLOCK_DATE).
        $rows = naval_bd_consultar("
            SELECT COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)) AS ROTA,
                   COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(WE.DESCRIPTION, '') AS ARM,
                   LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
                   CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
                   Corte.ANTES,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND DMD.QNT * DC.STOCK_SIGNAL > 0 THEN ABS(DMD.QNT) ELSE 0 END) AS FLOAT) AS ENTRADAS,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND DMD.QNT * DC.STOCK_SIGNAL < 0 THEN ABS(DMD.QNT) ELSE 0 END) AS FLOAT) AS SAIDAS,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 THEN DMD.QNT * DH.CALC_SALES_SIGNAL ELSE 0 END) AS FLOAT) AS VENDIDO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE S ON S.ID = DH.STORE_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            CROSS APPLY (VALUES (CAST(CASE WHEN COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < :limite THEN 1 ELSE 0 END AS INT))) Corte(ANTES)
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0
              AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, :ate)
              AND $naoAnulado
              AND ((COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND COALESCE(DC.STOCK_SIGNAL, 0) <> 0)
                   OR (COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 AND $validos))
            GROUP BY COALESCE(S.CODE, SUBSTRING(S.DESCRIPTION, 1, 4)), COALESCE(WE.CODE, ''), COALESCE(WE.DESCRIPTION, ''), LTRIM(RTRIM(DMD.PRODUCT_CODE)),
                     DH.ID, CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120), Corte.ANTES",
            [':de' => naval_bd_data($data), ':ate' => naval_bd_data($ate), ':limite' => $ate . ' ' . $corte]);
    } catch (Throwable $e) {
        error_log('[carrinha movimentos SQL] ' . $e->getMessage());
        return null;
    }
    // início da janela do fecho (contagem das carrinhas): as devoluções lançadas a partir daí são depois da contagem
    $inicioFecho = null;
    if (naval_carr_corte($ate) !== null) {
        naval_servico('calendario');
        $f = naval_cal_dia($ate)['fecho'][0] ?? null;
        if (is_string($f) && preg_match('/^\d{2}:\d{2}$/', $f)) $inicioFecho = $f . ':00';
    }
    $mov = naval_carr_movimentos_agrupar($rows, array_keys(naval_promotores()), naval_stock_carrinhas_confirmadas(), $ate, $inicioFecho);
    // inventários das carrinhas de cada dia do intervalo: sem eles o portal não explicaria o stock do Pinguim BO
    $inv = [];
    for ($d = $data; $d <= $ate; $d = date('Y-m-d', strtotime($d . ' 12:00') + 86400)) {
        $x = naval_carr_inventarios_sql($d);
        if ($x === null) return null;
        foreach ($x as $rota => $arts) {
            foreach ($arts as $c => $v) {
                $inv[$rota][$c]['q'] = round(($inv[$rota][$c]['q'] ?? 0) + $v['q'], 3);
                $inv[$rota][$c]['docs'] = array_values(array_unique(array_merge($inv[$rota][$c]['docs'] ?? [], $v['docs'])));
            }
        }
    }
    return naval_carr_movimentos_com_inventario($mov, $inv);
}

/** Movimentos desde o início do dia da conciliação até ao instante de referência (que pode ser de outro dia). */
function naval_carr_movimentos_ate(string $data, string $referencia): ?array
{
    return naval_carr_movimentos_sql($data, substr($referencia, 11, 8), substr($referencia, 0, 10));
}

/** Hora a partir da qual uma carga na carrinha depois da última venda do dia é a carga do dia seguinte. */
const NAVAL_CARR_CARGA_TARDE = '12:00:00';

/**
 * Agrupa as linhas da consulta dos movimentos por rota (só as rotas conhecidas) e artigo (função pura, testável). A rota
 * sai do armazém da linha (ARM_COD/ARM, a mesma regra do stock: armazém confirmado, código 100010NN ou nome com 10NN);
 * uma linha num armazém central não é da carrinha. Sem armazém na linha, vale a loja do documento (ROTA).
 *
 * Carga do dia seguinte (regra automática, tirada do que está registado no Pinguim BO): uma entrada na carrinha (guia
 * de transporte) registada à tarde (a partir das 12:00) e depois da última venda da rota nesse dia é a carga para o dia
 * seguinte: a carrinha já acabou de vender e está a ser carregada para amanhã. Não entra no que tinha de estar na
 * carrinha à contagem (fica «depois do corte»), mesmo lançada antes da hora do corte; entra no inicial do dia seguinte.
 * As guias da manhã, ou com vendas depois delas, são do próprio dia. Devoluções (saídas da carrinha) lançadas a partir do
 * início da janela do fecho (15:30, calendário) são depois da contagem: conta-se e só depois se lança a devolução.
 */
function naval_carr_movimentos_agrupar(array $rows, array $rotas, array $confirmadas = [], ?string $diaLimite = null, ?string $inicioFecho = null): array
{
    $rotas = array_flip(array_map('strval', $rotas));
    $rotaDe = function (array $r) use ($confirmadas, $rotas): string {
        $armCod = trim((string) ($r['ARM_COD'] ?? ''));
        $arm = trim((string) ($r['ARM'] ?? ''));
        if ($armCod !== '' || $arm !== '') {
            [$rota] = naval_stock_rota_armazem($armCod, $arm, $confirmadas);
            if ($rota === '') return '';   // armazém central: o movimento não é da carrinha
        } else {
            $rota = trim((string) $r['ROTA']);
        }
        return preg_match('/^\d{4}$/', $rota) && (!$rotas || isset($rotas[$rota])) ? $rota : '';
    };
    // última venda de cada rota em cada dia (com hora)
    $ultimaVenda = [];
    foreach ($rows as $r) {
        $h = (string) ($r['HORA'] ?? '');
        if ($h === '' || abs((float) ($r['VENDIDO'] ?? 0)) <= 0.0001) continue;
        $rota = $rotaDe($r);
        $k = $rota . '|' . substr($h, 0, 10);
        if ($rota !== '' && ($ultimaVenda[$k] ?? '') < $h) $ultimaVenda[$k] = $h;
    }
    $out = [];
    foreach ($rows as $r) {
        $rota = $rotaDe($r);
        if ($rota === '') continue;
        $c = trim((string) $r['CODIGO']);
        if ($c === '') continue;
        $x = $out[$rota][$c] ?? ['c' => 0.0, 'd' => 0.0, 'ca' => 0.0, 'da' => 0.0, 'va' => 0.0, 'vd' => 0.0];
        $x['c'] = round($x['c'] + (float) $r['ENTRADAS'], 3);
        $x['d'] = round($x['d'] + (float) $r['SAIDAS'], 3);
        $h = (string) ($r['HORA'] ?? '');
        $soEntrada = (float) $r['ENTRADAS'] > 0 && (float) $r['SAIDAS'] <= 0 && abs((float) ($r['VENDIDO'] ?? 0)) <= 0.0001;
        // só no dia da contagem: a carga da tarde de um dia anterior já estava na carrinha quando se contou
        $cargaSeguinte = $soEntrada && $h !== '' && ($diaLimite === null || substr($h, 0, 10) === $diaLimite)
            && substr($h, 11) >= NAVAL_CARR_CARGA_TARDE && $h > ($ultimaVenda[$rota . '|' . substr($h, 0, 10)] ?? '');
        // devolução lançada na janela do fecho (a partir das 15:30): conta-se a carrinha e só depois se lança a devolução
        // ao armazém (saída de armazém / devolução), por isso essa mercadoria ainda estava na carrinha quando se contou
        $soSaida = (float) $r['SAIDAS'] > 0 && (float) $r['ENTRADAS'] <= 0 && abs((float) ($r['VENDIDO'] ?? 0)) <= 0.0001;
        $devolucaoNoFecho = $soSaida && $inicioFecho !== null && $h !== '' && $diaLimite !== null && substr($h, 0, 10) === $diaLimite
            && substr($h, 11) >= $inicioFecho;
        if ($cargaSeguinte) {
            $x['cs'] = round(($x['cs'] ?? 0) + (float) $r['ENTRADAS'], 3);   // carga para o dia seguinte (fora da contagem)
        } elseif ($devolucaoNoFecho) {
            $x['df'] = round(($x['df'] ?? 0) + (float) $r['SAIDAS'], 3);     // devolução depois da contagem (fora da contagem)
        } elseif ((int) $r['ANTES'] === 1) {
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
    $stockInicial = naval_carr_stock(date('Y-m-d', strtotime($data . ' 12:00') - 86400));
    $movimentos = naval_carr_movimentos_ate($data, $referencia);
    if ($stockInicial === null || $movimentos === null) {
        throw new NavalErro('Não foi possível congelar o stock e os movimentos no momento da contagem. Tente novamente.', 503,
            ['codigo' => 'contagem_base_indisponivel']);
    }
    $mov = (array) ($movimentos[$rota] ?? []);
    $stockCorte = naval_carr_stock_por_movimentos((array) ($stockInicial[$rota] ?? []), $mov, $r);
    return naval_carr_congelar_base($r, $referencia, $stockCorte, $mov);
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

/** Só conciliações ainda abertas podem receber a actualização histórica; as já tratadas ficam intactas. */
function naval_carr_registo_aberto_actualizavel(array $reg): bool
{
    return in_array(($reg['estado'] ?? ''), ['', 'por_contar', 'por_validar', 'por_aprovar', 'por_fechar'], true)
        && ($reg['base_congelada_motivo'] ?? '') !== 'corte_movimentos_v1';
}

/**
 * «Actualizar bases históricas em aberto» (SADM): revê, em bloco e por páginas de 10 dias, as conciliações anteriores
 * a hoje contra o Pinguim BO de agora, com a mesma regra da revisão automática (naval_carr_rever_pinguim):
 *   - volta a congelar no instante da folha de contagem (nunca à hora do corte: a carga para o dia seguinte lançada
 *     entre a folha e o corte não estava na carrinha quando se contou);
 *   - só mexe nas que mudaram mesmo no Pinguim BO; as iguais ficam como estão (sem perder validações);
 *   - nas abertas que mudaram, a contagem fica e a validação e a aprovação do coordenador voltam a ser pedidas;
 *   - as fechadas não se alteram: ficam assinaladas para revisão.
 * Devolve {actualizadas: [{data, rota, referencia, contagem_preservada}], ignoradas, assinaladas, falhas, mais, continuar_de}.
 */
function naval_carr_actualizar_abertas_anteriores(array $eu, string $depoisDe = ''): array
{
    if (!naval_bd_configurada()) throw new NavalErro('A ligação à base de dados do Pinguim BO não está configurada.', 503);
    $hoje = date('Y-m-d');
    if ($depoisDe !== '' && (!naval_data_valida($depoisDe) || $depoisDe >= $hoje)) {
        throw new NavalErro('Ponto de continuação inválido para a actualização histórica.', 400);
    }
    $dias = [];
    foreach (glob(naval_pasta('carrinha') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $dia = basename($d);
        if (!naval_data_valida($dia) || $dia >= $hoje || ($depoisDe !== '' && $dia <= $depoisDe)) continue;
        if (glob($d . '/[0-9]*.json')) $dias[] = $dia;
    }
    sort($dias);
    $mais = count($dias) > 10;
    $dias = array_slice($dias, 0, 10);
    $res = ['actualizadas' => [], 'ignoradas' => 0, 'assinaladas' => 0, 'falhas' => []];
    foreach ($dias as $dia) {
        $r = naval_carr_rever_pinguim($dia, $dia, $eu);
        foreach ($r['actualizadas'] as $a) {
            $res['actualizadas'][] = ['data' => $a['data'], 'rota' => $a['rota'], 'referencia' => $a['referencia'] ?? null,
                'contagem_preservada' => !empty($a['contagem_preservada'])];
        }
        $res['ignoradas'] += (int) $r['iguais'];
        $res['assinaladas'] += count($r['aprovadas_com_diferencas']);
        $res['falhas'] = array_merge($res['falhas'], $r['falhas']);
    }
    naval_registar('carrinha_abertas_actualizadas', ['actualizadas' => count($res['actualizadas']), 'ignoradas' => $res['ignoradas'],
        'assinaladas' => $res['assinaladas'], 'falhas' => count($res['falhas'])]);
    $res['mais'] = $mais;
    $res['continuar_de'] = $dias ? (string) end($dias) : $depoisDe;
    return $res;
}

/**
 * Números frescos do Pinguim BO para um dia passado ou de hoje: os de naval_carr_dados (gravados de novo em _dados.json)
 * com o stock inicial e final, as vendas e os movimentos até à hora de referência do dia. Null sem ligação.
 */
function naval_carr_dados_frescos(string $data): ?array
{
    if (!naval_bd_configurada()) return null;
    $d = naval_carr_dados($data, true);
    $referencia = naval_carr_hora_referencia($data);
    $stockFim = naval_carr_stock($data);
    $stockInicial = naval_carr_stock(date('Y-m-d', strtotime($data . ' 12:00') - 86400));
    $movimentos = naval_carr_movimentos_sql($data, substr($referencia, 11, 8));
    $artigos = naval_vendas_artigos_pinguim($data, $data, naval_promotores());
    if ($stockFim === null || $stockInicial === null || $movimentos === null || $artigos === null) return null;
    $vendido = naval_carr_vendido($artigos);
    $d['precos'] = naval_carr_precos($vendido);
    foreach (array_unique(array_merge(array_keys($d['rotas']), array_keys($stockInicial), array_keys($stockFim), array_keys($vendido), array_keys($movimentos))) as $rota) {
        $d['rotas'][(string) $rota] = array_merge((array) ($d['rotas'][$rota] ?? []), ['s0' => $stockInicial[$rota] ?? [], 's1' => $stockFim[$rota] ?? [],
            'v' => $vendido[$rota] ?? [], 'mov' => $movimentos[$rota] ?? []]);
    }
    $d['referencia'] = $referencia;
    $d['movimentos_rota'] = $movimentos;
    return $d;
}

/**
 * Diferenças entre duas bases de uma rota (função pura, testada): por artigo, os números que mudaram
 * (inicial, carregado, vendido, devolvido, inventário, esperado). Lista vazia = iguais.
 */
/** Alguma das mudanças da base altera o stock esperado (e por isso a diferença da contagem)? (função pura) */
function naval_carr_diferencas_com_efeito(array $dif): bool
{
    foreach ($dif as $x) if (isset($x['mudou']['esperado'])) return true;
    return false;
}

/**
 * Documentos do Pinguim BO (só SELECT) que mudaram a base de um dia depois de ela ter sido congelada: com a data desse
 * dia ou anterior mas emitidos depois dele (turno do Winrest aberto) e os anulados dessa semana, nos artigos indicados
 * da carrinha: [{doc, tipo, data, emitido, anulado, codigo, q}]. Para explicar as conciliações aprovadas que o Pinguim BO
 * alterou (ferramentas/carrinha-rever-pinguim.php). null sem ligação.
 */
function naval_carr_causas_alteracao(string $rota, string $dia, array $codigos): ?array
{
    if (!naval_bd_configurada() || !$codigos || !preg_match('/^\d{1,8}$/', $rota) || !naval_data_valida($dia)) return null;
    $depois = date('Y-m-d', strtotime($dia . ' 12:00') + 86400);
    $p = [':depois' => $depois, ':depois2' => $depois, ':semana' => date('Y-m-d', strtotime($dia . ' 12:00') - 7 * 86400),
        ':arm1' => '1000' . $rota, ':arm2' => $rota];
    $ph = [];
    foreach (array_values(array_unique(array_map('strval', $codigos))) as $i => $c) {
        $ph[] = ':c' . $i;
        $p[':c' . $i] = $c;
    }
    try {
        $rows = naval_bd_consultar("
            SELECT DC.CODE AS TIPO, LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DATA, CONVERT(VARCHAR(19), DH.CLOCK_DATE, 120) AS EMITIDO,
                   MAX(CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT)) AS ANULADO, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
                   CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS Q
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND LTRIM(RTRIM(WE.CODE)) IN (:arm1, :arm2)
              AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) IN (" . implode(', ', $ph) . ")
              AND DH.DOC_DATE < :depois AND DH.DOC_DATE >= :semana
              AND (DH.CLOCK_DATE >= :depois2 OR COALESCE(DH.CALC_ISCANCELED, 0) = 1)
            GROUP BY DC.CODE, DH.DOC_PREFIX, DH.DOC_NUMBER, DH.DOC_DATE, DH.CLOCK_DATE, DMD.PRODUCT_CODE
            ORDER BY DH.CLOCK_DATE", $p);
    } catch (Throwable $e) {
        error_log('[carrinha causas] ' . $e->getMessage());
        return null;
    }
    return array_map(function ($r) {
        return ['doc' => (string) $r['DOC'], 'tipo' => (string) $r['TIPO'], 'data' => (string) $r['DATA'], 'emitido' => (string) $r['EMITIDO'],
            'anulado' => (int) $r['ANULADO'] === 1, 'codigo' => (string) $r['CODIGO'], 'q' => round((float) $r['Q'], 3)];
    }, $rows);
}

function naval_carr_comparar_bases(array $antiga, array $nova): array
{
    $idx = function (array $base) {
        $o = [];
        foreach (naval_carr_linhas($base, null) as $l) $o[$l['codigo']] = $l;
        return $o;
    };
    $a = $idx($antiga);
    $b = $idx($nova);
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $c) {
        $mud = [];
        foreach (['inicial', 'carregado', 'vendido', 'devolvido', 'inventario', 'esperado'] as $k) {
            $x = (float) ($a[$c][$k] ?? 0);
            $y = (float) ($b[$c][$k] ?? 0);
            if (abs($x - $y) > NAVAL_CARR_TOLERANCIA) $mud[$k] = [$x, $y];
        }
        if ($mud) $out[] = ['codigo' => (string) $c, 'artigo' => (string) ($b[$c]['artigo'] ?? $a[$c]['artigo'] ?? $c), 'mudou' => $mud];
    }
    return $out;
}

/**
 * Instante de referência da contagem (regra de «cut-off» das contagens físicas): o stock esperado é o do momento em
 * que se contou, até à hora do corte do dia (o que entra ou sai depois do corte é do dia seguinte). A folha impressa
 * antes não conta: entre a folha e a contagem a carrinha continua a vender (ex.: rota 1004, 20-11: folha às 13:29,
 * venda às 13:57, contagem às 16:12 → referência 16:00).
 *   - com contagem gravada no próprio dia: a hora da contagem, no máximo a hora do corte;
 *   - com contagem gravada noutro dia: o corte do dia da conciliação (a contagem física é a do fim desse dia, escrita
 *     no portal mais tarde; a hora em que se escreve não é a hora em que se contou);
 *   - sem contagem: a hora da folha (no máximo a do corte), senão a da base, senão a do dia.
 */
function naval_carr_referencia_contagem(array $reg, string $dia): string
{
    $ok = function ($x) use ($dia) { return is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $x) && substr($x, 0, 10) === $dia; };
    $corte = naval_carr_corte($dia);
    $limite = $dia . ' ' . ($corte !== null ? $corte . ':00' : '23:59:59');
    // base já congelada: vale a hora gravada nela (uma mudança de horário nunca mexe numa conciliação já feita)
    $congelada = $reg['base']['corte']['hora'] ?? null;
    if ($ok($congelada) && ($reg['base']['corte']['origem'] ?? '') === 'contagem') $limite = (string) $congelada;
    $contado = (string) ($reg['contado_em'] ?? '');
    if (is_array($reg['contagem'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} /', $contado)) {
        // gravada noutro dia: a contagem física foi feita no fim do dia da conciliação e escrita depois (confirmado nos
        // dados do Pinguim BO: as rotas 1001, 1002, 1004 e 1005 batem ao artigo no corte do próprio dia e não à hora
        // em que foram escritas) → o corte do dia da conciliação
        return $ok($contado) && $contado < $limite ? $contado : $limite;
    }
    foreach ((array) ($reg['historico'] ?? []) as $h) {
        if (($h['accao'] ?? '') === 'folha_contagem_congelada' && $ok($h['referencia'] ?? null)) return min((string) $h['referencia'], $limite);
    }
    $folha = (string) ($reg['folha_congelada_em'] ?? '');
    if ($ok($folha)) return min($folha, $limite);
    $base = $reg['base']['corte']['hora'] ?? null;
    return $ok($base) ? (string) $base : naval_carr_hora_referencia($dia);
}

/**
 * Revê as conciliações de um intervalo de dias contra o Pinguim BO de agora (documentos anulados, corrigidos ou
 * lançados depois de a base ter sido congelada). Por rota com base congelada:
 *  - aberta (por contar até por fechar): a base passa a ser a do Pinguim BO de agora; a contagem fica, a validação e
 *    a aprovação do coordenador caem (é preciso voltar a validar e aprovar) e o histórico regista o que mudou;
 *  - fechada (aprovada): não se altera o que foi aprovado; fica marcada «revisao_pinguim» com as diferenças, para a
 *    Logística e a Direcção decidirem (ISO 27001 A.8.15: nada aprovado muda em silêncio).
 * As rotas sem base congelada já usam os números frescos (_dados.json regravado). $ensaio: só diz o que faria.
 */
function naval_carr_rever_pinguim(string $desde, string $ate, array $eu, bool $ensaio = false): array
{
    if (!naval_data_valida($desde) || !naval_data_valida($ate) || $desde > $ate) throw new NavalErro('Intervalo de datas inválido.', 400);
    if ($ate > date('Y-m-d')) $ate = date('Y-m-d');
    $res = ['dias' => 0, 'actualizadas' => [], 'aprovadas_com_diferencas' => [], 'sem_efeito' => [], 'regularizadas' => [], 'iguais' => 0, 'falhas' => []];
    for ($dia = $desde; $dia <= $ate; $dia = date('Y-m-d', strtotime($dia . ' 12:00') + 86400)) {
        // hoje só depois do corte (antes dele a base ainda não está fechada)
        if ($dia === date('Y-m-d') && ($c = naval_carr_corte($dia)) !== null && date('H:i') < $c) continue;
        $regs = glob(naval_pasta('carrinha') . '/' . $dia . '/[0-9]*.json') ?: [];
        if (!$regs) continue;
        try {
            $d = naval_carr_dados_frescos($dia);
            if ($d === null) throw new NavalErro('O Pinguim BO não respondeu.', 503);
        } catch (Throwable $e) {
            $res['falhas'][] = ['data' => $dia, 'erro' => $e->getMessage()];
            continue;
        }
        $res['dias']++;
        $movPorHora = [];
        foreach ($regs as $f) {
            $rota = basename($f, '.json');
            try {
                $r = naval_exclusivo('carrinha-' . $dia . '-' . naval_carr_nome_rota($rota), function () use ($dia, $rota, $d, $eu, $ensaio, &$movPorHora) {
                    $reg = naval_carr_ler($dia, $rota);
                    if (!is_array($reg['base'] ?? null)) return 'igual';
                    // o instante da contagem física (no máximo o corte), nunca o da folha impressa antes
                    $ref = naval_carr_referencia_contagem($reg, $dia);
                    if (!array_key_exists($ref, $movPorHora)) $movPorHora[$ref] = $ref === $d['referencia'] ? $d['movimentos_rota'] : naval_carr_movimentos_ate($dia, $ref);
                    if ($movPorHora[$ref] === null) throw new NavalErro('O Pinguim BO não respondeu (movimentos até ' . $ref . ').', 503);
                    $fresca = (array) ($d['rotas'][$rota] ?? ['s0' => [], 's1' => [], 'v' => [], 'mov' => []]);
                    $mov = (array) ($movPorHora[$ref][$rota] ?? []);
                    $fresca = naval_carr_congelar_base($fresca, $ref, naval_carr_stock_por_movimentos((array) ($fresca['s0'] ?? []), $mov, $fresca), $mov);
                    $dif = naval_carr_comparar_bases((array) $reg['base'], $fresca);
                    if (!$dif) {
                        $rg = naval_carr_rever_regularizar($reg, $dia, $rota, $ref, $ensaio);
                        return $rg ? ['regularizada', $rg] : 'igual';
                    }
                    $agora = date('Y-m-d H:i:s');
                    $quem = (string) ($eu['login'] ?? 'sistema');
                    // mudanças que não alteram o stock esperado (ex.: rota 1005, 17-11: carga de 5 e devolução de 5 lançadas
                    // depois; saída anulada e devolvida de novo): a conciliação não muda. Nas aprovadas nada se assinala;
                    // nas abertas a base acompanha o Pinguim BO sem voltar a pedir a validação e as aprovações
                    if (!naval_carr_diferencas_com_efeito($dif)) {
                        if ($ensaio || ($reg['estado'] ?? '') === 'aprovada') return ['sem_efeito', $dif];
                        $reg['base'] = array_diff_key($fresca, ['post' => 1]);
                        $reg['precos'] = (array) $d['precos'];
                        $reg['base_congelada_em'] = $agora;
                        $reg['base_congelada_por'] = $quem;
                        $reg['base_congelada_motivo'] = 'pinguim_alterado_sem_efeito';
                        $reg['historico'][] = ['em' => $agora, 'por' => $quem, 'accao' => 'base_actualizada_pinguim_sem_efeito', 'referencia' => $ref, 'diferencas' => $dif];
                        naval_json_gravar(naval_carr_pasta($dia) . '/' . $rota . '.json', $reg);
                        return ['sem_efeito', $dif];
                    }
                    if (($reg['estado'] ?? '') === 'aprovada') {
                        if ($ensaio) return ['aprovada', $dif];
                        $ja = $reg['revisao_pinguim']['diferencas'] ?? null;
                        if ($ja === $dif) return ['aprovada', $dif];   // já assinalada com estas diferenças
                        $reg['revisao_pinguim'] = ['em' => $agora, 'diferencas' => $dif];
                        $reg['historico'][] = ['em' => $agora, 'por' => $quem, 'accao' => 'pinguim_alterado_depois_da_aprovacao', 'diferencas' => $dif];
                        naval_json_gravar(naval_carr_pasta($dia) . '/' . $rota . '.json', $reg);
                        naval_registar('carrinha_pinguim_alterado_aprovada', ['data' => $dia, 'rota' => $rota, 'artigos' => count($dif)]);
                        return ['aprovada', $dif];
                    }
                    if ($ensaio) return ['aberta', $dif, is_array($reg['contagem'] ?? null), $ref];
                    $contagem = is_array($reg['contagem'] ?? null);
                    $anteriores = array_keys(array_filter(['validado_por' => $reg['validado_por'] ?? null, 'coordenado_por' => $reg['coordenado_por'] ?? null]));
                    $reg['base'] = array_diff_key($fresca, ['post' => 1]);
                    $reg['precos'] = (array) $d['precos'];
                    $reg['base_congelada_em'] = $agora;
                    $reg['base_congelada_por'] = $quem;
                    $reg['base_congelada_motivo'] = 'pinguim_alterado';
                    unset($reg['revisao_pinguim']);
                    if ($contagem && in_array($reg['estado'] ?? '', ['por_aprovar', 'por_fechar'], true)) {
                        $reg['estado'] = 'por_validar';
                        foreach (['validado_por', 'validado_em', 'coordenado_por', 'coordenado_em'] as $k) $reg[$k] = null;
                        $reg['motivos'] = [];
                    }
                    $reg['regularizacoes'] = [];
                    $reg['historico'][] = ['em' => $agora, 'por' => $quem, 'accao' => 'base_actualizada_pinguim', 'referencia' => $ref,
                        'diferencas' => $dif, 'contagem_preservada' => $contagem, 'aprovacoes_invalidadas' => $anteriores];
                    naval_json_gravar(naval_carr_pasta($dia) . '/' . $rota . '.json', $reg);
                    naval_registar('carrinha_base_actualizada_pinguim', ['data' => $dia, 'rota' => $rota, 'artigos' => count($dif)]);
                    return ['aberta', $dif, $contagem, $ref];
                });
                if ($r === 'igual') $res['iguais']++;
                elseif ($r[0] === 'regularizada') $res['regularizadas'][] = ['data' => $dia, 'rota' => $rota, 'artigos' => $r[1]];
                elseif ($r[0] === 'aprovada') $res['aprovadas_com_diferencas'][] = ['data' => $dia, 'rota' => $rota, 'diferencas' => $r[1]];
                elseif ($r[0] === 'sem_efeito') $res['sem_efeito'][] = ['data' => $dia, 'rota' => $rota, 'diferencas' => $r[1]];
                else $res['actualizadas'][] = ['data' => $dia, 'rota' => $rota, 'diferencas' => $r[1], 'contagem_preservada' => $r[2], 'referencia' => $r[3]];
            } catch (Throwable $e) {
                $res['falhas'][] = ['data' => $dia, 'rota' => $rota, 'erro' => $e->getMessage()];
            }
        }
    }
    return $res;
}

/**
 * Regularização documentada de uma conciliação com contagem (na revisão): liga cada diferença ao acerto posterior do
 * Pinguim BO que a corrige e grava-o; sem diferenças por explicar, uma conciliação já validada fecha sozinha (gestão
 * por excepção, como no fecho sem diferenças). As aprovadas não mudam. Devolve os artigos regularizados de novo.
 */
/** As contagens seguintes da rota (até 10 dias depois): [{em, dif: {codigo: diferença antes das regularizações}}]. */
function naval_carr_contagens_seguintes(string $dia, string $rota): array
{
    $out = [];
    for ($i = 1; $i <= 10; $i++) {
        $d = date('Y-m-d', strtotime($dia . ' 12:00') + $i * 86400);
        if ($d > date('Y-m-d')) break;
        $r = naval_carr_ler($d, $rota);
        if (!is_array($r['contagem'] ?? null) || !$r['contagem'] || !is_array($r['base'] ?? null)) continue;
        $base = (array) $r['base'];
        $base['post'] = naval_carr_posteriores($r);
        $dif = [];
        foreach (naval_carr_linhas($base, $r['contagem'], (array) ($r['precos'] ?? [])) as $l) $dif[(string) $l['codigo']] = (float) ($l['diferenca_bruta'] ?? $l['diferenca'] ?? 0);
        $out[] = ['em' => naval_carr_referencia_contagem($r, $d), 'dif' => $dif];
    }
    return $out;
}

/** A contagem seguinte da rota (até NAVAL_CARR_CONSERVACAO_DIAS dias depois): {em, q} ou null. */
function naval_carr_contagem_seguinte(string $dia, string $rota): ?array
{
    for ($i = 1; $i <= NAVAL_CARR_CONSERVACAO_DIAS; $i++) {
        $d = date('Y-m-d', strtotime($dia . ' 12:00') + $i * 86400);
        if ($d > date('Y-m-d')) break;
        $r = naval_carr_ler($d, $rota);
        if (!is_array($r['contagem'] ?? null) || !$r['contagem']) continue;
        return ['em' => naval_carr_referencia_contagem($r, $d), 'q' => (array) $r['contagem']];
    }
    return null;
}

// ── Vendas por depósito (facturadas no SAP) ──────────────────────────────────────────────────────────────────
/**
 * Venda por depósito: mercadoria que saiu da carrinha para um cliente com o recibo «Cash sales receipt
 * confirmation» (GRN) e que é facturada no SAP, não no Winrest. O coordenador regista-a na conciliação (documento,
 * data, artigos, quantidades, preço, forma de pagamento) para seguir a factura SAP («por emitir» até se indicar o
 * número). Não desconta a carrinha nem explica faltas: a conciliação só se regulariza com documentos do Pinguim BO — a
 * saída de armazém (SA) que a Logística lança (o portal só lê). Ex.: rota 1011, GRN 9916 → SA A1000/144.
 */
function naval_carr_depositos_ficheiro(): string
{
    return naval_pasta('carrinha') . '/_depositos.json';
}

function naval_carr_depositos(?string $rota = null): array
{
    $l = array_values(array_filter((array) naval_json_ler(naval_carr_depositos_ficheiro()), 'is_array'));
    return $rota === null ? $l : array_values(array_filter($l, function ($x) use ($rota) { return (string) $x['rota'] === $rota; }));
}

/** Regista a venda por depósito e revê logo as conciliações abertas dessa carrinha (desde 3 dias antes da venda). */
function naval_carr_deposito_registar(string $rota, string $data, string $documento, array $linhas, string $pagamento, string $cliente, string $nota, array $eu): array
{
    $documento = trim($documento);
    if (!preg_match('/^[A-Za-z0-9 .\/-]{2,40}$/', $documento)) throw new NavalErro('Indique o número do documento (ex.: GRN 9916).', 400);
    if (!naval_data_valida($data) || $data > date('Y-m-d') || $data < date('Y-m-d', time() - 30 * 86400)) throw new NavalErro('Data da venda inválida.', 400);
    $pags = ['transferencia' => 'Transferência', 'numerario' => 'Numerário', 'multicaixa' => 'Multicaixa', 'credito' => 'A crédito (por receber)'];
    if (!isset($pags[$pagamento])) throw new NavalErro('Escolha a forma de pagamento.', 400);
    $ls = [];
    $total = 0.0;
    foreach (array_slice($linhas, 0, 200) as $l) {
        $l = (array) $l;
        $c = trim((string) ($l['codigo'] ?? ''));
        $q = $l['quantidade'] ?? null;
        $pr = $l['preco'] ?? 0;
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,40}$/', $c) || !is_numeric($q) || (float) $q <= 0 || !is_numeric($pr) || (float) $pr < 0) continue;
        $ls[] = ['codigo' => $c, 'artigo' => mb_substr(trim((string) ($l['artigo'] ?? $c)), 0, 120), 'quantidade' => round((float) $q, 3), 'preco' => round((float) $pr, 2)];
        $total += (float) $q * (float) $pr;
    }
    if (!$ls) throw new NavalErro('Indique pelo menos um artigo com a quantidade.', 400);
    $login = (string) ($eu['login'] ?? '');
    $dep = ['id' => substr(bin2hex(random_bytes(6)), 0, 12), 'rota' => $rota, 'data' => $data, 'documento' => $documento, 'linhas' => $ls,
        'total' => round($total, 2), 'pagamento' => $pagamento, 'pagamento_nome' => $pags[$pagamento], 'cliente' => mb_substr(trim($cliente), 0, 120),
        'nota' => mb_substr(trim($nota), 0, 300), 'factura_sap' => null, 'registado_por' => $login, 'registado_em' => date('Y-m-d H:i:s')];
    naval_exclusivo('carrinha-depositos', function () use ($dep) {
        $l = naval_carr_depositos();
        foreach ($l as $x) {
            if ((string) $x['rota'] === $dep['rota'] && strcasecmp((string) $x['documento'], $dep['documento']) === 0) throw new NavalErro('Este documento já está registado nesta rota.', 409);
        }
        $l[] = $dep;
        naval_json_gravar(naval_carr_depositos_ficheiro(), $l);
    });
    naval_registar('carrinha_venda_deposito', ['rota' => $rota, 'documento' => $documento, 'total' => $dep['total'], 'artigos' => count($ls)]);
    // as conciliações abertas da carrinha passam já a ter a falta explicada
    try {
        naval_carr_rever_pinguim(date('Y-m-d', strtotime($data . ' 12:00') - 3 * 86400), date('Y-m-d'), $eu);
    } catch (Throwable $e) {
        error_log('[venda por depósito] revisão: ' . $e->getMessage());
    }
    return $dep;
}

/** Número da factura SAP da venda por depósito (fecha o acompanhamento). */
function naval_carr_deposito_factura(string $id, string $factura, array $eu): array
{
    $factura = trim($factura);
    if (!preg_match('/^[A-Za-z0-9 .\/-]{2,40}$/', $factura)) throw new NavalErro('Indique o número da factura SAP.', 400);
    return naval_exclusivo('carrinha-depositos', function () use ($id, $factura, $eu) {
        $l = naval_carr_depositos();
        foreach ($l as $k => $x) {
            if ((string) $x['id'] !== $id) continue;
            $l[$k]['factura_sap'] = $factura;
            $l[$k]['factura_por'] = (string) ($eu['login'] ?? '');
            $l[$k]['factura_em'] = date('Y-m-d H:i:s');
            naval_json_gravar(naval_carr_depositos_ficheiro(), $l);
            naval_registar('carrinha_deposito_factura', ['rota' => $x['rota'], 'documento' => $x['documento'], 'factura' => $factura]);
            return $l[$k];
        }
        throw new NavalErro('Venda por depósito não encontrada.', 404);
    });
}

/**
 * Regularizações e diagnóstico de uma conciliação com as regras de agora (sem gravar): [regularizações, linhas depois
 * das regularizações, diagnósticos]. null sem ligação ao Pinguim BO.
 */
function naval_carr_regularizar_calcular(array $reg, string $dia, string $rota, string $ref): ?array
{
    static $acertos = [];
    $desde = substr($ref, 0, 10) . ' 00:00:00';
    if (!array_key_exists($desde, $acertos)) $acertos[$desde] = naval_carr_acertos_sql($desde);
    if ($acertos[$desde] === null) return null;
    $base = (array) $reg['base'];
    $base['post'] = naval_carr_posteriores($reg);
    $linhas = naval_carr_linhas($base, $reg['contagem'], (array) ($reg['precos'] ?? []));
    // só os documentos do Pinguim BO regularizam: uma factura emitida no SAP (venda por depósito registada no portal)
    // nunca desconta a carrinha; a falta fica regularizada pela saída de armazém (SA) que a Logística lança no Pinguim BO
    $todos = $acertos[$desde];
    $novas = naval_carr_regularizacoes($linhas, $todos, $rota, $ref, isset($reg['contado_em']) ? (string) $reg['contado_em'] : null,
        naval_carr_contagem_seguinte($dia, $rota), naval_carr_contagens_seguintes($dia, $rota));
    // diagnóstico do que fica por explicar (guia anulada com a mercadoria na carrinha, guia com o sentido trocado)
    static $guias = [];
    if (!array_key_exists($dia, $guias)) $guias[$dia] = naval_carr_guias_dia($dia) ?? [];
    $base['regs'] = $novas;
    $linhasDepois = naval_carr_linhas($base, $reg['contagem'], (array) ($reg['precos'] ?? []));
    return [$novas, $linhasDepois, naval_carr_diagnosticos($linhasDepois, $guias[$dia], $rota)];
}

/**
 * Todos os documentos do Pinguim BO (só SELECT) que mexeram nos artigos $codigos da carrinha $rota desde $desde,
 * incluindo os anulados e os inventários: {codigo: [{doc, tipo, em, q (efeito no stock da carrinha), anulado}]}.
 * Para o detalhe das diferenças por explicar (ferramentas/carrinha-rever-pinguim.php). null sem ligação.
 */
function naval_carr_detalhe_pinguim(string $rota, array $codigos, string $desde): ?array
{
    if (!naval_bd_configurada() || !$codigos || !preg_match('/^\d{1,8}$/', $rota)) return null;
    $cods = array_values(array_unique(array_map('strval', $codigos)));
    $ph = [];
    $p = [':desde' => $desde, ':arm1' => '1000' . $rota, ':arm2' => $rota];
    foreach ($cods as $i => $c) {
        $ph[] = ':c' . $i;
        $p[':c' . $i] = $c;
    }
    $in = implode(', ', $ph);
    try {
        $docs = naval_bd_consultar("
            SELECT LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, DC.CODE AS TIPO,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA, MAX(CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT)) AS ANULADO,
                   CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :desde
              AND LTRIM(RTRIM(WE.CODE)) IN (:arm1, :arm2)
              AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) IN ($in)
            GROUP BY DMD.PRODUCT_CODE, DC.CODE, DH.DOC_PREFIX, DH.DOC_NUMBER, COALESCE(DH.CLOCK_DATE, DH.DOC_DATE)", $p);
        $inv = naval_bd_consultar("
            SELECT LTRIM(RTRIM(P.CODE)) AS CODIGO, 'INV' AS TIPO, LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), IH.INVENTORY_DATE, 120) AS HORA, 0 AS ANULADO, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS EFEITO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND IH.INVENTORY_DATE >= :desde AND LTRIM(RTRIM(E.CODE)) IN (:arm1, :arm2)
              AND LTRIM(RTRIM(P.CODE)) IN ($in)
            GROUP BY P.CODE, IH.DOC_PREFIX, IH.DOC_NUMBER, IH.INVENTORY_DATE", $p);
    } catch (Throwable $e) {
        error_log('[carrinha detalhe Pinguim] ' . $e->getMessage());
        return null;
    }
    $out = [];
    foreach (array_merge($docs, $inv) as $r) {
        $out[(string) $r['CODIGO']][] = ['doc' => (string) $r['DOC'], 'tipo' => (string) $r['TIPO'], 'em' => (string) $r['HORA'], 'q' => round((float) $r['EFEITO'], 3),
            'anulado' => (int) $r['ANULADO'] === 1];
    }
    foreach ($out as $c => $l) {
        usort($l, function ($a, $b) { return strcmp($a['em'], $b['em']); });
        $out[$c] = $l;
    }
    return $out;
}

/**
 * Diferenças por explicar de uma conciliação aberta com as regras de agora (o que a revisão deixa para pessoas):
 * [{codigo, artigo, contado, esperado, diferenca, preco, regularizado, diagnostico, motivo}]. null sem ligação.
 */
function naval_carr_por_explicar(array $reg, string $dia, string $rota): ?array
{
    if (!is_array($reg['contagem'] ?? null) || !is_array($reg['base'] ?? null)) return [];
    $calc = naval_carr_regularizar_calcular($reg, $dia, $rota, naval_carr_referencia_contagem($reg, $dia));
    if ($calc === null) return null;
    [, $linhas, $diags] = $calc;
    $out = [];
    foreach ($linhas as $l) {
        if (abs((float) ($l['diferenca'] ?? 0)) <= NAVAL_CARR_TOLERANCIA) continue;
        $c = (string) $l['codigo'];
        $out[] = ['codigo' => $c, 'artigo' => (string) ($l['artigo'] ?? ''), 'contado' => $l['contado'] ?? null, 'esperado' => $l['esperado'] ?? null,
            'diferenca' => (float) $l['diferenca'], 'preco' => (float) ($l['preco'] ?? 0), 'regularizado' => $l['regularizado'] ?? null,
            'diagnostico' => $diags[$c] ?? null, 'motivo' => $reg['motivos'][$c] ?? null];
    }
    return $out;
}

function naval_carr_rever_regularizar(array $reg, string $dia, string $rota, string $ref, bool $ensaio): array
{
    if (!is_array($reg['contagem'] ?? null) || ($reg['estado'] ?? '') === 'aprovada') return [];
    $calc = naval_carr_regularizar_calcular($reg, $dia, $rota, $ref);
    if ($calc === null) return [];
    [$novas, $linhasDepois, $diags] = $calc;
    $antes = (array) ($reg['regularizacoes'] ?? []);
    // validada e sem nada por explicar: fecha sozinha mesmo sem regularizações novas (gestão por excepção)
    // (também sem artigos: contada e sem nada que o Pinguim BO diga que devia lá estar, não há nada para aprovar)
    $resAgora = naval_carr_resumo($linhasDepois, $reg);
    $fecha = ($reg['estado'] ?? '') === 'por_aprovar' && $resAgora['diferencas'] === 0 && $resAgora['alertas'] === 0 && naval_config('CARRINHA_FECHO_AUTOMATICO', '1') !== '0';
    if ($novas == $antes && $diags == (array) ($reg['diagnosticos'] ?? []) && !$fecha) return [];
    if ($ensaio) return array_keys($novas);
    $agora = date('Y-m-d H:i:s');
    $reg['diagnosticos'] = $diags;
    if ($novas != $antes) {
        $reg['regularizacoes'] = $novas;
        $reg['historico'][] = ['em' => $agora, 'por' => 'sistema', 'accao' => 'regularizada_pinguim', 'regularizacoes' => $novas];
    }
    $res = naval_carr_resumo($linhasDepois, $reg);
    if ($fecha || (($reg['estado'] ?? '') === 'por_aprovar' && naval_carr_fecha_sozinha($res) && naval_config('CARRINHA_FECHO_AUTOMATICO', '1') !== '0')) {
        $reg['estado'] = 'aprovada';
        $reg['aprovado_por'] = 'automatico';
        $reg['aprovado_em'] = $agora;
        $reg['historico'][] = ['em' => $agora, 'por' => 'automatico', 'accao' => 'fecho_automatico', 'diferencas' => 0, 'falta_kz' => 0, 'sobra_kz' => 0,
            'motivo' => $novas ? 'todas as diferenças regularizadas no Pinguim BO' : 'sem diferenças com o Pinguim BO'];
    }
    naval_json_gravar(naval_carr_pasta($dia) . '/' . $rota . '.json', $reg);
    naval_registar('carrinha_regularizada_pinguim', ['data' => $dia, 'rota' => $rota, 'artigos' => array_keys($novas), 'fechada' => $reg['estado'] === 'aprovada']);
    if ($reg['estado'] === 'aprovada') {
        naval_servico('eventos');
        naval_evento_seguro('carrinha.descarga_conciliada', ['data' => $dia, 'rota' => $rota, 'automatica' => true]);
    }
    return $novas ? array_keys($novas) : ['fecho_automatico'];
}

/**
 * Revisão imediata das conciliações contadas e ainda abertas de um dia (no máximo a cada 5 minutos por rota): as saídas
 * do Pinguim BO que explicam uma diferença (ex.: venda entre o corte e a contagem física, sincronizada mais tarde por
 * falha do telemóvel — rota 1001, 25-11: FR 35 às 16:59, contagem às 17:22) explicam-na logo que chegam, antes de quem
 * contou validar, sem esperar pela revisão de hora a hora. Devolve as rotas revistas.
 */
function naval_carr_regularizar_agora(string $dia, array $rotas): array
{
    if (!naval_bd_configurada() || naval_config('CARRINHA_REVER_PINGUIM', '1') === '0') return [];
    $feitas = [];
    foreach (array_slice($rotas, 0, 40) as $rota) {
        $rota = (string) $rota;
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/', $rota)) continue;
        $marca = naval_pasta('limites') . '/carrinha-rever-' . substr(hash('sha256', $dia . '|' . $rota), 0, 16);
        if (is_file($marca) && filemtime($marca) > time() - 300) continue;
        @touch($marca);
        try {
            $r = naval_exclusivo('carrinha-' . $dia . '-' . naval_carr_nome_rota($rota), function () use ($dia, $rota) {
                $reg = naval_carr_ler($dia, $rota);
                if (!is_array($reg['contagem'] ?? null) || !is_array($reg['base'] ?? null) || !in_array($reg['estado'] ?? '', ['por_validar', 'por_aprovar', 'por_fechar'], true)) return [];
                return naval_carr_rever_regularizar($reg, $dia, $rota, naval_carr_referencia_contagem($reg, $dia), false);
            });
            if ($r) $feitas[] = $rota;
        } catch (Throwable $e) {
            error_log('[carrinha revisão imediata] ' . $rota . ': ' . $e->getMessage());
        }
    }
    return $feitas;
}

/**
 * Rotina periódica: de hora a hora revê os últimos 3 dias e, de madrugada (a partir das 03:30), os últimos 45 contra
 * o Pinguim BO, para que uma guia anulada ou corrigida depois da contagem nunca fique esquecida numa base congelada.
 * Quando as regras mudam (nova versão deste ficheiro), a revisão dos 45 dias corre logo no ciclo seguinte: as regras
 * novas aplicam-se de imediato às conciliações passadas ainda não aprovadas, e daí em diante a todas.
 * CARRINHA_REVER_PINGUIM=0 desliga.
 */
function naval_carr_rever_tick(int $agora): ?array
{
    if (!naval_bd_configurada() || naval_config('CARRINHA_REVER_PINGUIM', '1') === '0') return null;
    $f = naval_pasta('carrinha') . '/_revisao.json';
    $e = naval_json_ler($f) ?: [];
    $hoje = date('Y-m-d', $agora);
    $regras = (string) hash_file('sha256', __FILE__);
    $longa = (date('H:i', $agora) >= '03:30' && ($e['longa'] ?? '') !== $hoje) || ($e['regras'] ?? '') !== $regras;
    if (!$longa && $agora - (int) ($e['curta'] ?? 0) < 3600) return null;
    $dias = $longa ? 45 : 3;
    if ($longa) {
        $e['longa'] = $hoje;
        $e['regras'] = $regras;
    }
    $e['curta'] = $agora;
    naval_json_gravar($f, $e, false);
    $r = naval_carr_rever_pinguim(date('Y-m-d', $agora - ($dias - 1) * 86400), $hoje, ['login' => 'sistema']);
    if ($r['actualizadas'] || $r['aprovadas_com_diferencas']) {
        naval_registar('carrinha_revisao_pinguim', ['dias' => $dias, 'actualizadas' => count($r['actualizadas']), 'aprovadas_com_diferencas' => count($r['aprovadas_com_diferencas'])]);
    }
    return $r;
}

/**
 * Acertos de inventário das carrinhas que contam no dia (função pura do resultado: naval_carr_inventarios_agrupar).
 * Um inventário do Pinguim BO (INVENTORYHEADER com SET_QNT = 1: põe o stock igual ao contado) com a data D entra no
 * stock (dbo.GET_STOCK) a partir de D+1: o fim de D ainda não o tem, o fim de D+1 já tem (confirmado em todas as
 * carrinhas desde 01-10-2026). Por isso conta na conciliação de D+1, antes do corte. Acerto = contado − teórico.
 * {rota: {codigo: ['q' => acerto, 'docs' => ['INV A1000/49', …]]}}; null sem ligação ou se a consulta falhar.
 */
function naval_carr_inventarios_sql(string $data): ?array
{
    if (!naval_bd_configurada()) return null;
    $dia = date('Y-m-d', strtotime($data . ' 12:00') - 86400);
    try {
        $rows = naval_bd_consultar("
            SELECT E.CODE AS ARM_COD, E.DESCRIPTION AS ARM, LTRIM(RTRIM(P.CODE)) AS CODIGO,
                   LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS ACERTO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND IH.INVENTORY_DATE >= :de AND IH.INVENTORY_DATE < DATEADD(day, 1, :ate)
            GROUP BY E.CODE, E.DESCRIPTION, P.CODE, IH.DOC_PREFIX, IH.DOC_NUMBER", [':de' => naval_bd_data($dia), ':ate' => naval_bd_data($dia)]);
    } catch (Throwable $e) {
        error_log('[carrinha inventários SQL] ' . $e->getMessage());
        return null;
    }
    return naval_carr_inventarios_agrupar($rows, naval_stock_carrinhas_confirmadas());
}

/** Agrupa os acertos de inventário por rota (armazém da carrinha) e artigo; ignora armazéns centrais e acertos nulos. */
function naval_carr_inventarios_agrupar(array $rows, array $confirmadas = []): array
{
    $out = [];
    foreach ($rows as $r) {
        [$rota] = naval_stock_rota_armazem(trim((string) ($r['ARM_COD'] ?? '')), trim((string) ($r['ARM'] ?? '')), $confirmadas);
        $c = trim((string) ($r['CODIGO'] ?? ''));
        $q = (float) ($r['ACERTO'] ?? 0);
        if ($rota === '' || $c === '' || abs($q) <= NAVAL_CARR_TOLERANCIA) continue;
        $x = $out[$rota][$c] ?? ['q' => 0.0, 'docs' => []];
        $x['q'] = round($x['q'] + $q, 3);
        $doc = trim((string) ($r['DOC'] ?? ''));
        if ($doc !== '' && !in_array($doc, $x['docs'], true)) $x['docs'][] = $doc;
        $out[$rota][$c] = $x;
    }
    return $out;
}

/** Junta os acertos de inventário aos movimentos do dia: 'i' (no dia) e 'ia' (antes do corte: entra às 00:00). */
function naval_carr_movimentos_com_inventario(array $mov, array $inv): array
{
    foreach ($inv as $rota => $arts) {
        foreach ($arts as $c => $x) {
            $m = $mov[$rota][$c] ?? ['c' => 0.0, 'd' => 0.0, 'ca' => 0.0, 'da' => 0.0, 'va' => 0.0, 'vd' => 0.0];
            $m['i'] = (float) $x['q'];
            $m['ia'] = (float) $x['q'];
            $m['idocs'] = (array) $x['docs'];
            $mov[(string) $rota][(string) $c] = $m;
        }
    }
    return $mov;
}

// ── Prova diária: o portal tem de explicar todo o stock das carrinhas do Pinguim BO ─────────────────────────────

/**
 * Prova de um dia (função pura, testada): por rota e artigo, inicial (stock do Pinguim BO no fim do dia anterior)
 * + carregado − devolvido − vendido ± inventário tem de dar o stock final do Pinguim BO. Devolve as linhas que não
 * batem: [{rota, codigo, artigo, inicial, carregado, devolvido, vendido, inventario, esperado, final}] e o total visto.
 */
function naval_carr_prova_calcular(array $s0, array $s1, array $mov): array
{
    $falhas = [];
    $vistas = 0;
    foreach (array_unique(array_merge(array_keys($s0), array_keys($s1), array_keys($mov))) as $rota) {
        $rota = (string) $rota;
        foreach (array_unique(array_merge(array_keys((array) ($s0[$rota] ?? [])), array_keys((array) ($s1[$rota] ?? [])), array_keys((array) ($mov[$rota] ?? [])))) as $c) {
            $c = (string) $c;
            $m = (array) ($mov[$rota][$c] ?? []);
            $ini = (float) ($s0[$rota][$c]['q'] ?? 0);
            $fim = (float) ($s1[$rota][$c]['q'] ?? 0);
            $car = (float) ($m['c'] ?? 0);
            $dev = (float) ($m['d'] ?? 0);
            $ven = (float) ($m['va'] ?? 0) + (float) ($m['vd'] ?? 0);
            $inv = (float) ($m['i'] ?? 0);
            $esp = round($ini + $car - $dev - $ven + $inv, 3);
            $vistas++;
            if (abs($esp - $fim) <= 0.001) continue;
            $falhas[] = ['rota' => $rota, 'codigo' => $c, 'artigo' => (string) ($s1[$rota][$c]['n'] ?? $s0[$rota][$c]['n'] ?? $c),
                'inicial' => $ini, 'carregado' => $car, 'devolvido' => $dev, 'vendido' => $ven, 'inventario' => $inv, 'esperado' => $esp, 'final' => $fim];
        }
    }
    return ['vistas' => $vistas, 'falhas' => $falhas];
}

/** Prova de um dia fechado, lida do Pinguim BO e guardada em carrinha/AAAA-MM-DD/_prova.json. Null sem ligação. */
function naval_carr_prova_dia(string $data): ?array
{
    if (!naval_data_valida($data) || $data >= date('Y-m-d') || !naval_bd_configurada()) return null;
    $s0 = naval_carr_stock(date('Y-m-d', strtotime($data . ' 12:00') - 86400));
    $s1 = naval_carr_stock($data);
    $mov = naval_carr_movimentos_sql($data, '23:59:59');
    if ($s0 === null || $s1 === null || $mov === null) return null;
    $r = naval_carr_prova_calcular($s0, $s1, $mov);
    $p = ['data' => $data, 'em' => date('Y-m-d H:i:s'), 'ok' => !$r['falhas'], 'vistas' => $r['vistas'], 'falhas' => $r['falhas']];
    naval_json_gravar(naval_carr_pasta($data) . '/_prova.json', $p, false);
    if ($r['falhas']) naval_registar('carrinha_prova_falhou', ['data' => $data, 'linhas' => count($r['falhas']), 'rotas' => array_values(array_unique(array_column($r['falhas'], 'rota')))]);
    return $p;
}

/** Linhas da prova guardada que não batem, de uma rota ([] sem prova ou se bate). */
function naval_carr_prova_rota(string $data, string $rota): array
{
    $p = naval_json_ler(naval_carr_pasta($data) . '/_prova.json');
    if (!$p || !empty($p['ok'])) return [];
    return array_values(array_filter((array) ($p['falhas'] ?? []), function ($f) use ($rota) { return (string) $f['rota'] === $rota; }));
}

/** A aprovação de uma rota cujo dia não bate com o Pinguim BO fica bloqueada até a diferença ser explicada. */
function naval_carr_exigir_prova(string $data, string $rota): void
{
    if (naval_config('CARRINHA_PROVA_BLOQUEIA', '1') === '0') return;
    $f = naval_carr_prova_rota($data, $rota);
    if (!$f) return;
    throw new NavalErro('O stock desta carrinha neste dia não bate com o Pinguim BO (' . count($f) . ' artigo(s): '
        . implode(', ', array_slice(array_column($f, 'artigo'), 0, 3)) . '). Corrija no Pinguim BO ou peça à Informática para analisar antes de aprovar.', 409,
        ['codigo' => 'prova_pinguim', 'linhas' => $f]);
}

/**
 * Acertos de stock lançados no Pinguim BO nas carrinhas a partir de um instante (regularização documentada): saídas
 * de armazém e devoluções (SA, DA: o que a Logística tira da carrinha) e inventários (contado − teórico). As guias de
 * carga (GTC) não contam: são operação normal, não acerto. Documentos anulados ficam de fora.
 * Lista de [rota, codigo, doc, em, efeito] por ordem de hora; null sem ligação.
 */
function naval_carr_acertos_sql(string $desde): ?array
{
    if (!naval_bd_configurada()) return null;
    naval_servico('vendas');
    $anulados = naval_vendas_condicao_anulados();
    $naoAnulado = 'COALESCE(DH.CALC_ISCANCELED, 0) = 0' . ($anulados !== '' ? ' AND NOT ' . $anulados : '');
    $validos = naval_vendas_condicao_validos();
    try {
        $rows = naval_bd_consultar("
            SELECT COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(WE.DESCRIPTION, '') AS ARM, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
                   CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 THEN 'venda' WHEN DC.CODE = 'GTC' THEN 'carga' ELSE 'acerto' END AS TIPO,
                   CAST(SUM(CASE WHEN COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 THEN -DMD.QNT * DH.CALC_SALES_SIGNAL ELSE DMD.QNT * DC.STOCK_SIGNAL END) AS FLOAT) AS EFEITO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND $naoAnulado
              AND ((COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0 AND COALESCE(DC.STOCK_SIGNAL, 0) <> 0) OR (COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0 AND $validos))
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) > :desde
            GROUP BY WE.CODE, WE.DESCRIPTION, DMD.PRODUCT_CODE, DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), DH.CALC_SALES_SIGNAL",
            [':desde' => $desde]);
        $inv = naval_bd_consultar("
            SELECT E.CODE AS ARM_COD, E.DESCRIPTION AS ARM, LTRIM(RTRIM(P.CODE)) AS CODIGO,
                   LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), DATEADD(day, 1, IH.INVENTORY_DATE), 120) AS HORA,
                   'acerto' AS TIPO, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS EFEITO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND DATEADD(day, 1, IH.INVENTORY_DATE) > :desde
            GROUP BY E.CODE, E.DESCRIPTION, P.CODE, IH.DOC_PREFIX, IH.DOC_NUMBER, IH.INVENTORY_DATE", [':desde' => $desde]);
    } catch (Throwable $e) {
        error_log('[carrinha acertos SQL] ' . $e->getMessage());
        return null;
    }
    $conf = naval_stock_carrinhas_confirmadas();
    $out = [];
    foreach (array_merge($rows, $inv) as $r) {
        [$rota] = naval_stock_rota_armazem(trim((string) $r['ARM_COD']), trim((string) $r['ARM']), $conf);
        $q = round((float) $r['EFEITO'], 3);
        if ($rota === '' || abs($q) <= NAVAL_CARR_TOLERANCIA) continue;
        $out[] = ['rota' => $rota, 'codigo' => trim((string) $r['CODIGO']), 'doc' => trim((string) $r['DOC']), 'em' => (string) $r['HORA'], 'efeito' => $q,
            'tipo' => (string) ($r['TIPO'] ?? 'acerto')];
    }
    usort($out, function ($a, $b) { return strcmp($a['em'], $b['em']); });
    return $out;
}

/**
 * Regularização documentada (função pura, testada): o que o Pinguim BO registou depois da contagem e explica a
 * diferença, na mesma carrinha e artigo, sem decisão humana:
 *   0. conservação da contagem: vendas + devoluções depois da contagem, para lá do contado mais as cargas seguintes
 *      (a carrinha nunca fica negativa; as vendas registadas depois explicam a falta; a devolução do contado não é
 *      explicação, só se identifica);
 *   1. acerto da Logística (saída de armazém, devolução ou inventário) — o primeiro depois da contagem, no mesmo
 *      sentido da diferença, explica-a até à quantidade desse acerto;
 *   2. venda registada depois da contagem (ex.: impressora avariada, factura emitida no dia seguinte) — numa falta, se a
 *      carrinha vendeu depois da contagem, antes de nova carga desse artigo, mais do que tinha contado, esse excesso
 *      saiu antes da contagem e só foi registado depois; explica até ao excesso. Não esconde faltas reais: o que podia
 *      ter saído do que foi contado não conta.
 * Um mesmo documento pode explicar a mesma diferença em dias seguidos. As guias de carga (GTC) nunca explicam: são o
 * limite da regra 2. Devolve {codigo: {q (a parte explicada, com sinal), doc, em}}.
 */
if (!defined('NAVAL_CARR_CONSERVACAO_DIAS')) define('NAVAL_CARR_CONSERVACAO_DIAS', 3);
if (!defined('NAVAL_CARR_CONTAGEM_JANELA')) define('NAVAL_CARR_CONTAGEM_JANELA', 120);

function naval_carr_regularizacoes(array $linhas, array $acertos, string $rota, string $referencia, ?string $contadoEm = null, ?array $proxima = null, ?array $seguintes = null): array
{
    // contagens seguintes da carrinha ([{em, dif: {codigo: diferença}}]): um acerto da Logística lançado depois de outra
    // contagem só explica esta se a diferença se manteve, no mesmo sentido, em todas as contagens até ele (ex.: rota 1011:
    // a falta das 50 fraldas de 21-11 continuava a 23-11, a SA A1000/144 de 24-11 explica as duas; os 15 sabões
    // castanhos da SA A1000/156 de 24-11 não explicam a contagem de 20-11, porque a de 23-11 já batia)
    $manteve = function (string $codigo, string $em, float $d) use ($seguintes): bool {
        foreach ((array) $seguintes as $s) {
            if ((string) ($s['em'] ?? '') >= $em) break;
            $x = $s['dif'][$codigo] ?? 0.0;
            if (abs((float) $x) <= NAVAL_CARR_TOLERANCIA || (((float) $x) < 0) !== ($d < 0)) return false;
        }
        return true;
    };
    // contagem seguinte da mesma carrinha ({em: referência dela, q: {codigo: contado}}): prova física de que o que foi
    // facturado entre as duas contagens não saiu da carrinha nesse intervalo (ex.: rota 1011, contou 97 fraldas no
    // sábado e 97 na segunda-feira, com 53 facturadas pelo meio → essas 53 já tinham saído antes da contagem de sábado)
    if ($proxima !== null && (!is_string($proxima['em'] ?? null) || $proxima['em'] <= $referencia || !is_array($proxima['q'] ?? null))) $proxima = null;
    // hora da contagem física, quando foi gravada pouco depois do corte (no próprio dia, até NAVAL_CARR_CONTAGEM_JANELA
    // minutos): as vendas entre o corte e essa hora já tinham saído da carrinha quando se contou
    $fisica = null;
    if (is_string($contadoEm) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $contadoEm) && substr($contadoEm, 0, 10) === substr($referencia, 0, 10)
        && $contadoEm > $referencia && strtotime($contadoEm) - strtotime($referencia) <= NAVAL_CARR_CONTAGEM_JANELA * 60) $fisica = $contadoEm;
    $refL = $fisica ?? $referencia;
    $out = [];
    foreach ($linhas as $l) {
        $d = $l['diferenca_bruta'] ?? $l['diferenca'];
        if ($d === null || abs((float) $d) <= NAVAL_CARR_TOLERANCIA) continue;
        $d = (float) $d;
        $codigo = (string) $l['codigo'];
        $falta = abs($d);
        $explicado = 0.0;
        $docs = [];
        $em = '';
        $esvaziou = false;
        // -1. saída entre o corte e a contagem física — venda ou saída de armazém da Logística (ex.: rota 1009, 23-11: corte
        //     às 16:00, facturas às 16:18 e 16:42, contagem às 16:47; rota 1009, 24-11: corte às 15:30, SA A1000/154 às 16:31
        //     com as 147 fraldas vendidas no SAP, contagem às 16:40): a mercadoria já não estava na carrinha quando se
        //     contou; explica a falta até a essas saídas
        $explicadoC = 0.0;
        $docsC = [];
        $emC = '';
        if ($d < 0 && $fisica !== null) {
            foreach ($acertos as $a) {
                if ($a['rota'] !== $rota || (string) $a['codigo'] !== $codigo || !in_array($a['tipo'] ?? 'acerto', ['venda', 'acerto'], true) || $referencia >= $a['em'] || $a['em'] > $fisica) continue;
                if ((float) $a['efeito'] >= 0) continue;
                $explicadoC += -(float) $a['efeito'];
                $docsC[] = $a['doc'];
                $emC = $emC ?: $a['em'];
            }
            $explicadoC = min($falta, $explicadoC);
            if ($explicadoC >= $falta - NAVAL_CARR_TOLERANCIA) {
                $out[$codigo] = ['q' => round(-$explicadoC, 3), 'doc' => implode(', ', array_values(array_unique($docsC))), 'em' => $emC];
                continue;
            }
            $falta -= $explicadoC;
        }
        // regularização exacta: o primeiro acerto da Logística depois da contagem, no sentido da diferença e com a
        // quantidade exacta do que falta explicar, é a regularização documentada — não a devolução do contado, mesmo que
        // seja mais do que se contou (ex.: rota 1011, 23-11: contou 7 fraldas MIMO, faltavam 10; a SA A1000/144 tirou as
        // 10 da venda por depósito GRN 9916)
        foreach ($acertos as $a) {
            if ($a['rota'] !== $rota || (string) $a['codigo'] !== $codigo || $a['em'] <= $refL || ($a['tipo'] ?? 'acerto') !== 'acerto') continue;
            if (!$manteve($codigo, (string) $a['em'], $d)) break;
            if (((float) $a['efeito'] < 0) === ($d < 0) && abs(abs((float) $a['efeito']) - $falta) <= NAVAL_CARR_TOLERANCIA) {
                $out[$codigo] = ['q' => round(($d < 0 ? -1 : 1) * ($falta + $explicadoC), 3), 'doc' => implode(', ', array_values(array_unique(array_merge($docsC, [$a['doc']])))),
                    'em' => $emC ?: $a['em']];
                continue 2;
            }
            break;
        }
        // 0. conservação da contagem (falta): a carrinha nunca tem stock negativo. Em cada momento depois da contagem, o
        //    que saiu (vendas e devoluções ao armazém) só podia sair do contado mais o que entretanto se carregou; o que
        //    passa disso já tinha saído antes da contagem e só foi registado depois. Explica até a esse excesso (o maior
        //    em qualquer momento), só pelas vendas; a devolução do contado fica identificada. Ex.: rota 1007, 21-11
        //    (vendas de sábado facturadas na segunda-feira e o contado devolvido na SA 130); rota 1011, 21-11 (contou 0
        //    sabões, na segunda-feira carregou 30 e facturou 80: os 50 a mais são os que faltavam no sábado).
        //    Janela de NAVAL_CARR_CONSERVACAO_DIAS dias (cobre o fim-de-semana; depois, há novas contagens).
        if ($d < 0 && isset($l['contado'])) {
            $vend = 0.0;
            $saiu = 0.0;
            $carga = 0.0;
            $docsV = [];
            $docsD = [];
            $emV = '';
            $melhor = 0.0;
            $docsMelhor = [];
            $limite = date('Y-m-d H:i:s', strtotime($referencia) + NAVAL_CARR_CONSERVACAO_DIAS * 86400);
            foreach ($acertos as $a) {
                if ($a['rota'] !== $rota || (string) $a['codigo'] !== $codigo || $a['em'] <= $refL) continue;
                if ($a['em'] > $limite || ($proxima !== null && $a['em'] > $proxima['em'])) break;
                $tipo = $a['tipo'] ?? 'acerto';
                if ((float) $a['efeito'] > 0) {
                    // carga (ou outra entrada) depois da contagem: passa a poder sair também daí
                    $carga += (float) $a['efeito'];
                    continue;
                }
                $saiu += -(float) $a['efeito'];
                if ($tipo === 'venda') {
                    $vend += -(float) $a['efeito'];
                    $docsV[] = $a['doc'];
                    $emV = $emV ?: $a['em'];
                } else $docsD[] = $a['doc'];
                $agora = min($saiu - (float) $l['contado'] - $carga, $vend);
                if ($agora > $melhor + NAVAL_CARR_TOLERANCIA) {
                    $melhor = $agora;
                    $docsMelhor = [array_values(array_unique($docsV)), array_values(array_unique($docsD))];
                }
            }
            // na contagem seguinte havia na carrinha q: o que saiu até lá, para lá de contado + cargas − q, já tinha saído
            if ($proxima !== null && isset($proxima['q'][$codigo]) && is_numeric($proxima['q'][$codigo])) {
                $agora = min($saiu - (float) $l['contado'] - $carga + (float) $proxima['q'][$codigo], $vend);
                if ($agora > $melhor + NAVAL_CARR_TOLERANCIA) {
                    $melhor = $agora;
                    $docsMelhor = [array_values(array_unique($docsV)), array_values(array_unique($docsD))];
                }
            }
            // o contado voltou ao armazém (a carrinha foi esvaziada): as devoluções não explicam faltas
            $esvaziou = $saiu - $vend >= (float) $l['contado'] - NAVAL_CARR_TOLERANCIA && $saiu - $vend > NAVAL_CARR_TOLERANCIA;
            if ($melhor > NAVAL_CARR_TOLERANCIA) {
                $explicado = min($falta, $melhor);
                $docs = $docsMelhor[0];
                if ($docsMelhor[1]) $docs[] = 'contado devolvido em ' . implode(', ', $docsMelhor[1]);
                $em = $emV;
            }
        }
        if ($explicado >= $falta - NAVAL_CARR_TOLERANCIA) {
            $out[$codigo] = ['q' => round(-($explicado + $explicadoC), 3), 'doc' => implode(', ', array_values(array_unique(array_merge($docsC, $docs)))), 'em' => $emC ?: $em];
            continue;
        }
        if ($explicado > NAVAL_CARR_TOLERANCIA) {
            // parte explicada pelas vendas; o resto segue as regras seguintes
            $falta -= $explicado;
        }
        $explicado0 = $explicado;
        $docs0 = $docs;
        $em0 = $em;
        $explicado = 0.0;
        $docs = [];
        $em = '';
        // 1. o primeiro acerto da Logística depois da contagem, no que as vendas não explicaram (não a devolução do contado);
        //    ex.: rota 1011, 21-11: 53 fraldas explicadas pelas facturas; as 50 restantes, entregues em venda à consignação, pela
        //    saída de armazém que a Logística lança na carrinha
        foreach ($esvaziou ? [] : $acertos as $a) {
            if ($a['rota'] !== $rota || (string) $a['codigo'] !== $codigo || $a['em'] <= $refL || ($a['tipo'] ?? 'acerto') !== 'acerto') continue;
            if (!$manteve($codigo, (string) $a['em'], $d)) break;
            if (($a['efeito'] < 0) === ($d < 0)) {
                $explicado = min(abs((float) $a['efeito']), $falta);
                $docs[] = $a['doc'];
                $em = $a['em'];
            }
            break;
        }
        // 2. falta: vendas registadas depois da contagem, até à carga seguinte do artigo, para lá do que foi contado
        if ($d < 0 && !$esvaziou && $explicado0 <= NAVAL_CARR_TOLERANCIA && $explicado < $falta - NAVAL_CARR_TOLERANCIA && isset($l['contado'])) {
            $vendido = 0.0;
            $docsV = [];
            foreach ($acertos as $a) {
                if ($a['rota'] !== $rota || (string) $a['codigo'] !== $codigo || $a['em'] <= $refL) continue;
                $tipo = $a['tipo'] ?? 'acerto';
                if ($tipo === 'carga' && $a['efeito'] > 0) break;
                if ($tipo !== 'venda') continue;
                $vendido += -(float) $a['efeito'];
                $docsV[] = $a['doc'];
                $emV = $a['em'];
            }
            $excesso = $vendido - (float) $l['contado'];
            if ($excesso > NAVAL_CARR_TOLERANCIA) {
                $explicado = min($falta, $explicado + $excesso);
                $docs = array_merge($docs, array_values(array_unique($docsV)));
                $em = $em ?: ($emV ?? '');
            }
        }
        $explicado += $explicado0;
        if ($explicado0 > NAVAL_CARR_TOLERANCIA) {
            $docs = array_merge($docs0, $docs);
            $em = $em0 ?: $em;
        }
        if ($explicadoC > NAVAL_CARR_TOLERANCIA) {
            $explicado += $explicadoC;
            $docs = array_merge($docsC, $docs);
            $em = $emC ?: $em;
        }
        if ($explicado > NAVAL_CARR_TOLERANCIA) {
            $out[$codigo] = ['q' => round(($d < 0 ? -1 : 1) * $explicado, 3), 'doc' => implode(', ', array_values(array_unique($docs))), 'em' => $em];
        }
    }
    return $out;
}

/**
 * Guias de transporte (GTC) para as carrinhas num dia, incluindo as anuladas: [rota, codigo, doc, hora, q, anulada].
 * Para o diagnóstico das diferenças que ficam por explicar. null sem ligação.
 */
function naval_carr_guias_dia(string $dia): ?array
{
    if (!naval_bd_configurada()) return null;
    try {
        $rows = naval_bd_consultar("
            SELECT COALESCE(WE.CODE, '') AS ARM_COD, COALESCE(WE.DESCRIPTION, '') AS ARM, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA, CAST(SUM(DMD.QNT) AS FLOAT) AS Q,
                   MAX(CAST(COALESCE(DH.CALC_ISCANCELED, 0) AS INT)) AS ANULADA
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND DC.CODE = 'GTC' AND DMD.QNT * DC.STOCK_SIGNAL > 0
              AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, :ate)
            GROUP BY WE.CODE, WE.DESCRIPTION, DMD.PRODUCT_CODE, DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, COALESCE(DH.CLOCK_DATE, DH.DOC_DATE)",
            [':de' => naval_bd_data($dia), ':ate' => naval_bd_data($dia)]);
    } catch (Throwable $e) {
        error_log('[carrinha guias do dia] ' . $e->getMessage());
        return null;
    }
    $conf = naval_stock_carrinhas_confirmadas();
    $out = [];
    foreach ($rows as $r) {
        [$rota] = naval_stock_rota_armazem(trim((string) $r['ARM_COD']), trim((string) $r['ARM']), $conf);
        if ($rota === '') continue;
        $out[] = ['rota' => $rota, 'codigo' => trim((string) $r['CODIGO']), 'doc' => trim((string) $r['DOC']), 'hora' => (string) $r['HORA'],
            'q' => round((float) $r['Q'], 3), 'anulada' => (int) $r['ANULADA'] === 1];
    }
    return $out;
}

/**
 * Diagnóstico automático das diferenças que ficam por explicar (função pura, testada), pelo que está registado no
 * Pinguim BO — para quem corrige saber exactamente o documento:
 *   - sobra igual a uma guia desse dia que foi anulada: a mercadoria foi carregada; a guia não devia ter sido anulada;
 *   - falta igual a uma guia da tarde para a carrinha: provável devolução ao armazém lançada como carga (sentido trocado).
 * Devolve {codigo: {tipo, doc, texto}}.
 */
function naval_carr_diagnosticos(array $linhas, array $guias, string $rota): array
{
    $out = [];
    foreach ($linhas as $l) {
        $d = (float) ($l['diferenca'] ?? 0);
        if (abs($d) <= NAVAL_CARR_TOLERANCIA) continue;
        foreach ($guias as $g) {
            if ($g['rota'] !== $rota || $g['codigo'] !== (string) $l['codigo'] || abs($g['q'] - abs($d)) > NAVAL_CARR_TOLERANCIA) continue;
            if ($d > 0 && $g['anulada']) {
                $out[(string) $l['codigo']] = ['tipo' => 'guia_anulada', 'doc' => $g['doc'],
                    'texto' => 'Sobra igual à guia ' . $g['doc'] . ' (' . substr($g['hora'], 11, 5) . '), anulada no Pinguim BO: a mercadoria foi carregada; confirme com a Logística e reponha a guia.'];
                break;
            }
            if ($d < 0 && !$g['anulada'] && substr($g['hora'], 11) >= NAVAL_CARR_CARGA_TARDE) {
                $out[(string) $l['codigo']] = ['tipo' => 'sentido_trocado', 'doc' => $g['doc'],
                    'texto' => 'Falta igual à guia ' . $g['doc'] . ' (' . substr($g['hora'], 11, 5) . '), lançada como carga: provável devolução ao armazém registada com o sentido trocado; confirme com a Logística.'];
                break;
            }
        }
    }
    // troca de artigo na entrega: falta num artigo e sobra da mesma quantidade noutro, com o mesmo preço (ex.: rota 1010,
    // 23-11: −5 sabão verde e +5 sabão castanho, ambos a 27 500 Kz); a Logística acerta o stock da carrinha (saída de um
    // e entrada do outro) e o valor do par é zero
    $livres = array_values(array_filter($linhas, function ($l) use ($out) {
        return abs((float) ($l['diferenca'] ?? 0)) > NAVAL_CARR_TOLERANCIA && !isset($out[(string) $l['codigo']]) && (float) ($l['preco'] ?? 0) > 0;
    }));
    foreach ($livres as $a) {
        if ((float) $a['diferenca'] >= 0 || isset($out[(string) $a['codigo']])) continue;
        foreach ($livres as $b) {
            if ((float) $b['diferenca'] <= 0 || isset($out[(string) $b['codigo']])) continue;
            if (abs((float) $a['diferenca'] + (float) $b['diferenca']) > NAVAL_CARR_TOLERANCIA) continue;
            $pa = (float) $a['preco'];
            $pb = (float) $b['preco'];
            if (abs($pa - $pb) > 0.01 * max($pa, $pb)) continue;
            $q = abs((float) $a['diferenca']);
            $na = (string) ($a['artigo'] ?? $a['codigo']);
            $nb = (string) ($b['artigo'] ?? $b['codigo']);
            $txt = 'Falta de ' . $q . ' em ' . $na . ' igual à sobra em ' . $nb . ', com o mesmo preço: provável troca de artigo na entrega (valor zero). A Logística acerta o stock da carrinha: saída de ' . $q . ' ' . $a['codigo'] . ' e entrada de ' . $q . ' ' . $b['codigo'] . '.';
            $out[(string) $a['codigo']] = ['tipo' => 'troca_artigo', 'doc' => (string) $b['codigo'], 'texto' => $txt];
            $out[(string) $b['codigo']] = ['tipo' => 'troca_artigo', 'doc' => (string) $a['codigo'], 'texto' => $txt];
            break;
        }
    }
    return $out;
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
        $inv = (float) ($mov[$c]['i'] ?? 0);   // acerto de inventário do Pinguim BO (conta no dia, antes do corte)
        $alertas = [];
        $corte = isset($r['corte']['q']) ? (array) $r['corte']['q'] : null;
        $depois = 0.0;
        $deDocs = false;
        if ($corte !== null) {
            // até à referência congelada: o que entrou e saiu até esse instante; o restante fica de fora
            $noCorte = (float) ($corte[$c] ?? 0);
            $saldo = $noCorte - $ini + $ven - $inv;
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
            $esperado = $ini + $car - $ven + $inv;
            if (abs($esperado - $dev - $fim) > NAVAL_CARR_TOLERANCIA) $alertas[] = 'nao_explicado';
        } else {
            $saldo = $fim - $ini + $ven - $inv;
            $car = max(0.0, $saldo);
            $dev = max(0.0, -$saldo);
            $esperado = $fim;
        }
        if ($fim < -NAVAL_CARR_TOLERANCIA || $esperado < -NAVAL_CARR_TOLERANCIA) $alertas[] = 'negativo';
        // a carga posterior está fisicamente na carrinha: soma-se ao que se conta, para não entrar na conciliação do dia
        $pos = (float) ($post[$c] ?? 0);
        if ($ven > $ini + $car + max(0.0, $inv) + NAVAL_CARR_TOLERANCIA) $alertas[] = 'sem_carga';
        $contado = ($contagem !== null && array_key_exists($c, $contagem)) ? (float) $contagem[$c] : ($contagem !== null ? 0.0 : null);
        $dif = $contado === null ? null : round($contado - $esperado - $pos, 3);
        // regularização documentada: a parte da diferença que um acerto posterior no Pinguim BO já corrigiu
        $difBruta = $dif;
        $regz = $r['regs'][$c] ?? null;
        $regularizado = null;
        if ($dif !== null && is_array($regz) && abs((float) $regz['q']) > NAVAL_CARR_TOLERANCIA && (((float) $regz['q'] < 0) === ($dif < 0))) {
            $qr = ($dif < 0 ? -1 : 1) * min(abs((float) $regz['q']), abs($dif));
            $dif = round($dif - $qr, 3);
            $regularizado = ['q' => round($qr, 3), 'doc' => (string) $regz['doc'], 'em' => (string) $regz['em']];
        }
        $preco = (float) ($precos[$c] ?? (($v[$c]['q'] ?? 0) > 0 ? (float) ($v[$c]['t'] ?? 0) / $v[$c]['q'] : 0));
        if (!$ini && !$fim && !$ven && !$car && !$dev && !$inv && !$contado && !$pos && !$depois) continue;
        // nada para contar: o artigo não devia estar na carrinha no fim do dia e não houve vendas (ex.: stock do dia
        // anterior retirado ou corrigido no Pinguim BO). Sem alertas e sem contagem feita, não entra na conciliação.
        $zero = function (float $x): bool { return abs($x) <= NAVAL_CARR_TOLERANCIA; };
        // (com o corte, a carga só para amanhã também fica de fora: não há nada dela para contar hoje)
        // (um artigo com documento de entrada ou saída antes do corte fica, para se ver o movimento: ex. devolução ao armazém)
        if (!$alertas && $zero($esperado) && ($corte !== null || $zero($fim)) && $zero($ven) && !$contado && $zero($pos) && !($deDocs && ($car > 0 || $dev > 0)) && $zero($inv)) continue;
        $out[] = [
            'codigo' => $c,
            'artigo' => (string) ($v[$c]['n'] ?? $s1[$c]['n'] ?? $s0[$c]['n'] ?? $c),
            'unidade' => (string) ($v[$c]['u'] ?? ''),
            'inicial' => round($ini, 3), 'carregado' => round($car, 3), 'vendido' => round($ven, 3), 'devolvido' => round($dev, 3),
            'inventario' => round($inv, 3), 'inventario_docs' => (array) ($mov[$c]['idocs'] ?? []),
            'final' => round($fim, 3), 'esperado' => round($esperado, 3), 'depois_do_corte' => $depois, 'posterior' => round($pos, 3), 'contado' => $contado, 'diferenca' => $dif,
            'preco' => round($preco, 2), 'valor' => $dif === null ? null : round($dif * $preco, 2), 'alertas' => $alertas,
            'diferenca_bruta' => $difBruta, 'regularizado' => $regularizado,
            'diagnostico' => $dif !== null && abs($dif) > NAVAL_CARR_TOLERANCIA ? ($r['diags'][$c] ?? null) : null,
        ];
    }
    usort($out, function ($a, $b) {
        return [count($b['alertas']) > 0, abs((float) $b['diferenca'])] <=> [count($a['alertas']) > 0, abs((float) $a['diferenca'])] ?: strcmp($a['artigo'], $b['artigo']);
    });
    return $out;
}

/**
 * Função pura (testada): dias de trabalho seguidos sem actividade até $data (incluído), desde o dia a seguir ao último
 * movimento $ultimo (AAAA-MM-DD, ou null = nenhum nos últimos 60 dias). $folga: dia → é folga? (calendário).
 */
function naval_carr_contar_parados(string $data, ?string $ultimo, callable $folga): int
{
    $n = 0;
    $limite = $ultimo ?? date('Y-m-d', strtotime($data . ' 12:00') - 60 * 86400);
    for ($t = strtotime($data . ' 12:00'); date('Y-m-d', $t) > $limite; $t -= 86400) if (!$folga(date('Y-m-d', $t))) $n++;
    return $n;
}

/**
 * Dias de trabalho seguidos sem actividade da carrinha da rota até $data: o último dia anterior com algum documento no
 * armazém da carrinha (Pinguim BO, só SELECT; uma leitura por dia para todas as rotas, 10 minutos em cache).
 */
function naval_carr_dias_parados(string $data, string $rota): int
{
    static $ult = [];
    if (!isset($ult[$data])) {
        $ult[$data] = naval_cache('carrinha-ultimo-mov|' . $data, 600, function () use ($data) {
            $o = [];
            try {
                naval_servico('bd');
                foreach (naval_bd_consultar("
                    SELECT LTRIM(RTRIM(WE.CODE)) AS ARM, CONVERT(VARCHAR(10), MAX(DH.DOC_DATE), 120) AS ULTIMO
                    FROM DOCMOVDETAIL DMD
                    INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
                    INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
                    INNER JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
                    WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
                      AND LTRIM(RTRIM(WE.CODE)) LIKE '100010%' AND DH.DOC_DATE >= :de AND DH.DOC_DATE < :ate
                    GROUP BY WE.CODE", [':de' => date('Y-m-d', strtotime($data . ' 12:00') - 60 * 86400), ':ate' => $data]) as $x) {
                    $o[substr((string) $x['ARM'], -4)] = (string) $x['ULTIMO'];
                }
            } catch (Throwable $e) {
                error_log('[carrinha dias parados] ' . $e->getMessage());
            }
            return $o;
        });
    }
    naval_servico('calendario');
    return naval_carr_contar_parados($data, $ult[$data][$rota] ?? null, 'naval_cal_folga');
}

/** A carrinha teve movimento no dia (carga, venda, devolução ou acerto de inventário)? */
function naval_carr_teve_movimento(array $linhas): bool
{
    foreach ($linhas as $l) {
        foreach (['carregado', 'vendido', 'devolvido', 'inventario'] as $k) {
            if (abs((float) ($l[$k] ?? 0)) > NAVAL_CARR_TOLERANCIA) return true;
        }
    }
    return false;
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
    naval_servico('sincronizacao-alertas');
    try {
        $avisosDados = naval_sync_rotas_dados();
    } catch (Throwable $e) {
        $avisosDados = null;
    }
    $promotores = naval_promotores();
    // dia de descanso (domingo, feriado ou folga da empresa): só entram as carrinhas que tiveram movimento nesse dia
    // ou que já começaram a ser contadas; as outras ficam como no último dia de trabalho e não se contam
    naval_servico('calendario');
    $descanso = naval_cal_folga($data);
    $diaAcabou = $data < date('Y-m-d') || ($data === date('Y-m-d') && date('H:i') >= (string) (naval_cal_dia($data)['vendas'][1] ?? '15:00'));
    $exigeFecho = naval_carr_exige_fecho($data) && naval_bd_configurada();
    $fechados = null;
    $depoisFecho = [];
    if ($exigeFecho) {
        naval_servico('pinguim-dia');
        $fechados = naval_pdia_fechados($data);
        try {
            foreach (naval_pdia_depois_fecho($data) as $x) $depoisFecho[$x['rota']][] = $x;
        } catch (Throwable $e) {
            error_log('[carrinha depois do fecho] ' . $e->getMessage());
        }
    }
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
        if ($descanso && $resumo['estado'] === 'por_contar' && !naval_carr_teve_movimento($linhas)) continue;
        // dia de trabalho sem nenhum documento da carrinha no Pinguim BO (sem vendas, cargas, transferências, devoluções
        // nem inventário): não precisa de contagem e o stock passa intacto. Calculado a cada leitura: se o telemóvel
        // sincronizar mais tarde vendas com esta data, volta a «por contar». Ao fim de CARRINHA_CONTROLO_DIAS dias de
        // trabalho seguidos sem actividade, pede uma contagem de controlo (mercadoria parada sem ninguém a conferir).
        // (hoje, só depois do fim das vendas: de manhã ainda ninguém vendeu)
        if ($resumo['estado'] === 'por_contar' && !naval_carr_teve_movimento($linhas) && naval_config('CARRINHA_SEM_ACTIVIDADE', '1') !== '0' && $diaAcabou) {
            $parados = naval_carr_dias_parados($data, $rota);
            if ($parados < max(2, (int) naval_config('CARRINHA_CONTROLO_DIAS', '5'))) $resumo['estado'] = 'sem_actividade';
            else $resumo['controlo'] = $parados;
            $resumo['dias_parados'] = $parados;
        }
        $oculta = $cega && $resumo['estado'] === 'por_contar' && ($gere || !$veEsperado);
        if ($oculta) $linhas = naval_carr_cega($linhas);
        $out[] = ['rota' => $rota, 'promotor' => $promotores[$rota] ?? $rota, 'resumo' => $resumo, 'linhas' => $linhas,
            'cega' => $gere && $cega && $resumo['estado'] === 'por_contar', 'quantidades_ocultas' => $oculta,
            'exige_fecho' => $exigeFecho && $fechados !== null, 'fecho_pinguim' => $fechados[$rota] ?? ($reg['fecho_pinguim']['fecho_pinguim'] ?? null),
            'depois_fecho' => $depoisFecho[$rota] ?? [],
            'contado_por' => $reg['contado_por'] ?? null, 'contado_em' => $reg['contado_em'] ?? null,
            'validado_por' => $reg['validado_por'] ?? null, 'validado_em' => $reg['validado_em'] ?? null,
            'coordenado_por' => $reg['coordenado_por'] ?? null, 'coordenado_em' => $reg['coordenado_em'] ?? null,
            'aprovado_por' => $reg['aprovado_por'] ?? null, 'aprovado_em' => $reg['aprovado_em'] ?? null,
            'motivos' => (object) ($reg['motivos'] ?? []), 'historico' => array_slice((array) $reg['historico'], -20), 'revisao_pinguim' => $reg['revisao_pinguim'] ?? null,
            'prova' => naval_carr_prova_rota($data, $rota),
            'requisicao' => naval_req_da_conciliacao_numero($data, $rota),
            'corte' => isset($base['corte']) ? ['hora' => $base['corte']['hora'], 'origem' => $base['corte']['origem'],
                'vendido_depois' => (float) ($base['corte']['vendido_depois'] ?? 0)] : null,
            'cargas_posteriores' => array_map(function ($x) { return ['numero' => $x['numero'], 'confirmado_em' => $x['confirmado_em'], 'autorizado_por' => $x['autorizado_por'] ?? '']; }, (array) ($reg['cargas_posteriores'] ?? [])),
            // porque é que o Pinguim BO pode não ter as vendas do dia (telemóvel sem sincronizar, rota sem facturas)
            'avisos_rota' => $resumo['estado'] === 'aprovada' || $avisosDados === null || $descanso ? []
                : naval_sync_avisos_rota($avisosDados[$rota] ?? null, $data, naval_carr_referencia_contagem($reg, $data))];
    }
    return ['data' => $data, 'gerado' => $d['gerado'], 'movimentos' => $d['movimentos'], 'rotas' => $out, 'motivos' => NAVAL_CARR_MOTIVOS];
}

/** Grava a contagem do técnico (estado → por_validar) e reutiliza a base congelada na primeira folha. */
// ── Contagem só com o dia fechado no Pinguim BO ───────────────────────────────────────────────────────────────────
//
// Durante a contagem não pode sair mais nenhum documento da carrinha. O portal não escreve no Pinguim BO, por isso
// não bloqueia o telemóvel: exige a prova de que o telemóvel já não emite — o fecho do dia da rota feito no
// telemóvel (SESSION, operação 12; ou o documento FCL) registado no Pinguim BO. Sem ele — ou sem ligação ao Pinguim BO para o confirmar — a contagem não se
// grava; o super administrador pode abrir uma excepção com motivo (fica no histórico). CARRINHA_EXIGIR_FECHO=0 desliga.
const NAVAL_CARR_FECHO_DESDE = '2026-11-26';

/** A regra vale neste dia? */
function naval_carr_exige_fecho(string $data): bool
{
    return naval_config('CARRINHA_EXIGIR_FECHO', '1') !== '0' && $data >= NAVAL_CARR_FECHO_DESDE;
}

/**
 * Decisão (função pura, testada): $linhas do Pinguim BO para o dia (naval_pdia_ler; null sem ligação). Devolve o que
 * fica no histórico ({fecho_pinguim, sem_ligacao?, excepcao?}) ou lança o erro que diz ao técnico o que fazer.
 */
function naval_carr_decisao_fecho(?array $linhas, string $rota, string $dia, bool $sadm, string $excepcao): array
{
    $excepcao = trim($excepcao);
    if ($linhas === null) {
        // sem ligação não há prova de que o telemóvel já não emite: recusa (o super administrador pode abrir excepção)
        if ($sadm && mb_strlen($excepcao) >= 5) return ['fecho_pinguim' => null, 'sem_ligacao' => true, 'excepcao' => mb_substr($excepcao, 0, 300)];
        throw new NavalErro('Não foi possível confirmar no Pinguim BO que o dia da rota ' . $rota . ' está fechado. Tente de novo dentro de alguns minutos.', 503,
            ['codigo' => 'fecho_por_confirmar']);
    }
    foreach ($linhas as $x) {
        if ((string) ($x['rota'] ?? '') === $rota && ($x['dia'] ?? '') === $dia && !empty($x['fechado_em'])) return ['fecho_pinguim' => (string) $x['fechado_em']];
    }
    if ($sadm && mb_strlen($excepcao) >= 5) return ['fecho_pinguim' => null, 'excepcao' => mb_substr($excepcao, 0, 300)];
    throw new NavalErro('O dia da rota ' . $rota . ' ainda está aberto no Pinguim BO. O vendedor fecha o dia no telemóvel e só depois se conta: assim não sai mais nenhum documento durante a contagem.',
        409, ['codigo' => 'dia_aberto_pinguim']);
}

/** Lê o fecho no Pinguim BO agora (sem cache: o vendedor acabou de fechar o dia) e decide. Null se a regra não vale. */
function naval_carr_exigir_fecho(string $data, string $rota, array $eu, string $excepcao = ''): ?array
{
    if (!naval_carr_exige_fecho($data) || !naval_bd_configurada()) return null;
    naval_servico('pinguim-dia');
    try {
        $l = (array) naval_pdia_ler($data, $data)['linhas'];
    } catch (Throwable $e) {
        error_log('[carrinha fecho do dia] ' . $e->getMessage());
        $l = null;
    }
    try {
        $d = naval_carr_decisao_fecho($l, $rota, $data, naval_perfil() === 'SADM', $excepcao);
    } catch (NavalErro $e) {
        // a recusa fica no registo de actividade: quem tentou contar, quando, e porquê (dia aberto ou sem ligação)
        naval_registar('carrinha_contagem_recusada', ['data' => $data, 'rota' => $rota, 'motivo' => $l === null ? 'sem_ligacao' : 'dia_aberto']);
        throw $e;
    }
    if (isset($d['excepcao'])) naval_registar('carrinha_contagem_sem_fecho', ['data' => $data, 'rota' => $rota, 'motivo' => $d['excepcao']]);
    return $d;
}

function naval_carr_contar(string $data, string $rota, array $contagem, array $eu, string $excepcaoFecho = ''): array
{
    if ($data > date('Y-m-d')) throw new NavalErro('Não se conta uma carrinha num dia que ainda não chegou.', 400);
    $fecho = naval_carr_exigir_fecho($data, $rota, $eu, $excepcaoFecho);
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $contagem, $eu, $fecho) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] === 'aprovada') throw new NavalErro('Esta conciliação já foi aprovada.', 409);
        $d = naval_carr_dados($data, true);
        $contadoEm = date('Y-m-d H:i:s');
        $congelada = (!empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em'])) && is_array($reg['base'] ?? null);
        if ((!empty($reg['base_congelada_em']) || !empty($reg['folha_congelada_em'])) && !$congelada) {
            throw new NavalErro('A base congelada da folha de contagem está incompleta. Contacte a administração.', 503);
        }
        // a base é a do momento da contagem (no máximo o corte), mesmo com a folha impressa antes: entre a folha e a
        // contagem a carrinha continua a vender e a receber; a folha congelada só serve para imprimir
        $r = $d['rotas'][$rota] ?? ($congelada ? (array) $reg['base'] : null);
        if ($r === null) throw new NavalErro('Esta rota não teve movimentos na carrinha neste dia.', 404);
        $r['post'] = naval_carr_posteriores($reg);
        $referencia = naval_carr_referencia_contagem(['contagem' => [], 'contado_em' => $contadoEm], $data);
        if (naval_bd_configurada()) $r = naval_carr_base_no_instante($data, $rota, $r, $referencia);
        elseif ($congelada) $r = (array) $reg['base'] + ['post' => $r['post']];
        $precos = $d['precos'] ?? (array) ($reg['precos'] ?? []);
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
            'referencia' => $referencia, 'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz'], 'sobra_kz' => $res['sobra_kz']] + ($fecho ?? []);
        if ($fecho !== null) $reg['fecho_pinguim'] = $fecho;
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
        naval_servico('eventos');
        if ($res['diferencas'] > 0) naval_evento_seguro('carrinha.diferenca_detectada', ['data' => $data, 'rota' => $rota, 'diferencas' => $res['diferencas'], 'falta_kz' => $res['falta_kz']]);
        if ($reg['estado'] === 'aprovada') naval_evento_seguro('carrinha.descarga_conciliada', ['data' => $data, 'rota' => $rota, 'automatica' => true]);
        // Reposição: requisição ao armazém com o que foi vendido, em rascunho para o promotor rever (não trava a validação)
        $req = null;
        try {
            $q = naval_req_da_conciliacao($data, $rota, $linhas, (string) $eu['login']);
            // reposição automática: emitida e, dentro das regras, aprovada sozinha
            if ($q) $q = naval_req_reposicao_auto($q);
            $req = $q ? $q['numero'] : null;
        } catch (Throwable $e) {
            error_log('[requisicao conciliacao] ' . $e->getMessage());
        }
        return ['linhas' => $linhas, 'resumo' => $res, 'requisicao' => $req];
    });
}

/**
 * 1.º nível (coordenador): aprova a contagem validada, com um motivo de até 255 caracteres por diferença;
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
        naval_carr_exigir_prova($data, $rota);
        $linhas = naval_carr_linhas(naval_carr_base($data, $rota, (array) $reg['base'], $reg), $reg['contagem'], (array) ($reg['precos'] ?? []));
        $m = [];
        $faltam = [];
        foreach ($linhas as $l) {
            if ($l['diferenca'] === null || abs($l['diferenca']) <= NAVAL_CARR_TOLERANCIA) continue;
            $x = (array) ($motivos[$l['codigo']] ?? []);
            $motivoEntrada = $x['motivo'] ?? null;
            $mot = naval_texto(is_string($motivoEntrada) ? $motivoEntrada : '', 256);
            $nota = naval_texto($x['nota'] ?? '', 300);
            if ($mot === '' || mb_strlen($mot) > 255) {
                $faltam[] = $l['artigo'];
                continue;
            }
            $m[$l['codigo']] = ['motivo' => $mot, 'nota' => $nota];
        }
        if ($faltam) throw new NavalErro('Indique um motivo de até 255 caracteres para cada diferença:', 400, ['erros' => $faltam]);
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
        naval_carr_exigir_prova($data, $rota);
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
        naval_servico('eventos');
        naval_evento_seguro('carrinha.descarga_conciliada', ['data' => $data, 'rota' => $rota, 'automatica' => false]);
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
        $motivo = (string) ($motivos[$l['codigo']]['motivo'] ?? '');
        $textoMotivo = NAVAL_CARR_MOTIVOS[$motivo] ?? $motivo;
        $nota = (string) ($motivos[$l['codigo']]['nota'] ?? '');
        $linhasHtml .= '<tr><td ' . $td . '>' . $h($l['artigo']) . '</td><td ' . $td . ' align="right">' . $h(number_format($l['diferenca'], 0, ',', ' '))
            . '</td><td ' . $td . ' align="right">' . $h(number_format((float) $l['valor'], 2, ',', ' ')) . ' Kz</td><td ' . $td . '>'
            . $h($textoMotivo . ($nota !== '' ? ' · ' . $nota : '')) . '</td></tr>';
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
    naval_servico('calendario');
    if (naval_cal_folga($dia)) return null;   // dia de descanso: não há fecho de carrinhas a lembrar
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
