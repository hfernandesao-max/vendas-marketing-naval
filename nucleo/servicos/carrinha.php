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
 * Entradas e saídas das carrinhas no dia, sem as vendas: {rota: {codigo: ['c' => carregado, 'd' => devolvido]}}.
 * Só se esta base de dados tiver o sinal de stock dos movimentos (DOCMOV.STOCK_SIGNAL ou DOCCONFIG.STOCK_SIGNAL);
 * caso contrário devolve null e o cálculo usa o saldo do dia.
 */
function naval_carr_movimentos_sql(string $data): ?array
{
    if (!naval_bd_configurada()) return null;
    try {
        $col = [];
        foreach (naval_bd_consultar("
            SELECT UPPER(TABLE_NAME) AS T, UPPER(COLUMN_NAME) AS C
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'dbo' AND UPPER(TABLE_NAME) IN ('DOCMOV', 'DOCCONFIG', 'DOCHEADER')") as $r) {
            $col[$r['T']][$r['C']] = true;
        }
        foreach (['WAREHOUSE_ID', 'PRODUCT_ID', 'QNT', 'DOCHEADER_ID'] as $c) if (empty($col['DOCMOV'][$c])) return null;
        $sinal = !empty($col['DOCMOV']['STOCK_SIGNAL']) ? 'MV.STOCK_SIGNAL' : (!empty($col['DOCCONFIG']['STOCK_SIGNAL']) ? 'DC.STOCK_SIGNAL' : '');
        if ($sinal === '') return null;
        $apagado = (!empty($col['DOCMOV']['DELETED']) ? ' AND MV.DELETED = 0' : '') . (!empty($col['DOCHEADER']['DELETED']) ? ' AND DH.DELETED = 0' : '');
        $rows = naval_bd_consultar("
            SELECT E.CODE AS COD_ARMAZEM, E.DESCRIPTION AS ARMAZEM, P.CODE AS CODIGO,
                   CAST(SUM(CASE WHEN MV.QNT * $sinal > 0 THEN ABS(MV.QNT) ELSE 0 END) AS FLOAT) AS ENTRADAS,
                   CAST(SUM(CASE WHEN MV.QNT * $sinal < 0 THEN ABS(MV.QNT) ELSE 0 END) AS FLOAT) AS SAIDAS
            FROM DOCMOV MV
            INNER JOIN DOCHEADER DH ON DH.ID = MV.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            INNER JOIN ENTITY E ON E.ID = MV.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = MV.PRODUCT_ID
            WHERE DH.DOC_DATE >= :de AND DH.DOC_DATE <= :ate
              AND COALESCE(DH.CALC_SALES_SIGNAL, 0) = 0
              AND (E.CODE LIKE '100010%' OR E.DESCRIPTION LIKE '%10[0-9][0-9]%')" . $apagado . "
            GROUP BY E.CODE, E.DESCRIPTION, P.CODE", [':de' => naval_bd_data($data), ':ate' => naval_bd_data($data)]);
    } catch (Throwable $e) {
        error_log('[carrinha movimentos SQL] ' . $e->getMessage());
        return null;
    }
    $out = [];
    foreach ($rows as $r) {
        $rota = naval_carr_rota_armazem((string) $r['COD_ARMAZEM'], (string) $r['ARMAZEM']);
        if ($rota === '') continue;
        $c = trim((string) $r['CODIGO']);
        $out[$rota][$c] = ['c' => round((float) $r['ENTRADAS'], 3), 'd' => round((float) $r['SAIDAS'], 3)];
    }
    return $out;
}

/** Rota da carrinha a partir do armazém (100010NN ou «… 10NN»), como no stock. */
function naval_carr_rota_armazem(string $cod, string $nome): string
{
    if (preg_match('/^100010(\d{2})$/', trim($cod), $m)) return '10' . $m[1];
    if (preg_match('/10\d{2}/', $nome, $m)) return $m[0];
    return '';
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
    return $d;
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
    $codigos = array_keys($s0 + $s1 + $v + (array) ($mov ?? []) + (array) ($contagem ?? []));
    $out = [];
    foreach ($codigos as $c) {
        $c = (string) $c;
        $ini = (float) ($s0[$c]['q'] ?? 0);
        $fim = (float) ($s1[$c]['q'] ?? 0);
        $ven = (float) ($v[$c]['q'] ?? 0);
        $alertas = [];
        if (is_array($mov)) {
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
        if ($ven > $ini + $car + NAVAL_CARR_TOLERANCIA) $alertas[] = 'sem_carga';
        $contado = ($contagem !== null && array_key_exists($c, $contagem)) ? (float) $contagem[$c] : ($contagem !== null ? 0.0 : null);
        $dif = $contado === null ? null : round($contado - $esperado, 3);
        $preco = (float) ($precos[$c] ?? (($v[$c]['q'] ?? 0) > 0 ? $v[$c]['t'] / $v[$c]['q'] : 0));
        if (!$ini && !$fim && !$ven && !$car && !$dev && !$contado) continue;
        // nada para contar: o artigo não devia estar na carrinha no fim do dia e não houve vendas (ex.: stock do dia
        // anterior retirado ou corrigido no Pinguim BO). Sem alertas e sem contagem feita, não entra na conciliação.
        $zero = function (float $x): bool { return abs($x) <= NAVAL_CARR_TOLERANCIA; };
        if (!$alertas && $zero($esperado) && $zero($fim) && $zero($ven) && !$contado) continue;
        $out[] = [
            'codigo' => $c,
            'artigo' => (string) ($v[$c]['n'] ?? $s1[$c]['n'] ?? $s0[$c]['n'] ?? $c),
            'unidade' => (string) ($v[$c]['u'] ?? ''),
            'inicial' => round($ini, 3), 'carregado' => round($car, 3), 'vendido' => round($ven, 3), 'devolvido' => round($dev, 3),
            'final' => round($fim, 3), 'esperado' => round($esperado, 3), 'contado' => $contado, 'diferenca' => $dif,
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

/** Vista do dia para o portal: rotas do âmbito, com linhas, resumo e registo. */
function naval_carr_vista(string $data, ?array $rotas, bool $gere, bool $actualizar = false): array
{
    $d = naval_carr_dados($data, $actualizar);
    $cega = naval_config('CARRINHA_CONTAGEM_CEGA', '1') !== '0';
    $promotores = naval_promotores();
    $out = [];
    foreach ($d['rotas'] as $rota => $r) {
        $rota = (string) $rota;
        if ($rotas !== null && !in_array($rota, $rotas, true)) continue;
        $reg = naval_carr_ler($data, $rota);
        // depois de contada, usa os números que estavam em vigor na contagem (o que o técnico viu e aprovou)
        $base = is_array($reg['base'] ?? null) ? $reg['base'] : $r;
        $linhas = naval_carr_linhas($base, $reg['contagem'], (array) ($reg['precos'] ?? $d['precos'] ?? []));
        $resumo = naval_carr_resumo($linhas, $reg);
        if ($resumo['estado'] === 'sem_movimento') continue;
        if ($gere && $cega && $resumo['estado'] === 'por_contar') $linhas = naval_carr_cega($linhas);
        $out[] = ['rota' => $rota, 'promotor' => $promotores[$rota] ?? $rota, 'resumo' => $resumo, 'linhas' => $linhas,
            'cega' => $gere && $cega && $resumo['estado'] === 'por_contar',
            'contado_por' => $reg['contado_por'] ?? null, 'contado_em' => $reg['contado_em'] ?? null,
            'validado_por' => $reg['validado_por'] ?? null, 'validado_em' => $reg['validado_em'] ?? null,
            'coordenado_por' => $reg['coordenado_por'] ?? null, 'coordenado_em' => $reg['coordenado_em'] ?? null,
            'aprovado_por' => $reg['aprovado_por'] ?? null, 'aprovado_em' => $reg['aprovado_em'] ?? null,
            'motivos' => (object) ($reg['motivos'] ?? []), 'historico' => array_slice((array) $reg['historico'], -20),
            'requisicao' => naval_req_da_conciliacao_numero($data, $rota)];
    }
    return ['data' => $data, 'gerado' => $d['gerado'], 'movimentos' => $d['movimentos'], 'rotas' => $out, 'motivos' => NAVAL_CARR_MOTIVOS];
}

/** Grava a contagem do técnico (estado → por_validar) e a fotografia dos números em vigor. */
function naval_carr_contar(string $data, string $rota, array $contagem, array $eu): array
{
    if ($data > date('Y-m-d')) throw new NavalErro('Não se conta uma carrinha num dia que ainda não chegou.', 400);
    return naval_exclusivo('carrinha-' . $data . '-' . naval_carr_nome_rota($rota), function () use ($data, $rota, $contagem, $eu) {
        $reg = naval_carr_ler($data, $rota);
        if ($reg['estado'] === 'aprovada') throw new NavalErro('Esta conciliação já foi aprovada.', 409);
        $d = naval_carr_dados($data, true);
        $r = $d['rotas'][$rota] ?? null;
        if ($r === null) throw new NavalErro('Esta rota não teve movimentos na carrinha neste dia.', 404);
        $codigos = array_flip(array_map('strval', array_keys($r['s0'] + $r['s1'] + $r['v'] + (array) ($r['mov'] ?? []))));
        $c = [];
        foreach ($contagem as $cod => $q) {
            $cod = (string) $cod;
            if (!isset($codigos[$cod]) || !is_numeric($q)) continue;
            $q = (float) $q;
            if ($q < 0 || $q > 1000000) throw new NavalErro('Quantidade inválida no artigo ' . $cod . '.', 400);
            $c[$cod] = round($q, 3);
        }
        $novo = $reg['contagem'] !== null;
        $reg = array_merge($reg, ['contagem' => $c, 'base' => $r, 'precos' => $d['precos'], 'estado' => 'por_validar', 'motivos' => [],
            'contado_por' => (string) $eu['login'], 'contado_em' => date('Y-m-d H:i:s'), 'validado_por' => null, 'validado_em' => null,
            'coordenado_por' => null, 'coordenado_em' => null, 'aprovado_por' => null, 'aprovado_em' => null]);
        $linhas = naval_carr_linhas($r, $c, $d['precos']);
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
        $linhas = naval_carr_linhas($reg['base'], $reg['contagem'], (array) ($reg['precos'] ?? []));
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
        $linhas = naval_carr_linhas($reg['base'], $reg['contagem'], (array) ($reg['precos'] ?? []));
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
        $linhas = naval_carr_linhas($reg['base'], $reg['contagem'], (array) ($reg['precos'] ?? []));
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
        $na = (float) ($naCarrinha[$rota] ?? 0);
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
