<?php
/**
 * Inventário dos armazéns (contagem física pela app RTM Operações), segundo as boas práticas de controlo de stock:
 *   - contagem às cegas: quem conta nunca vê o stock do sistema; a base (stock do Pinguim BO no armazém) fica
 *     congelada no momento em que a contagem começa (corte) — o armazém não deve ter movimentos durante a contagem;
 *   - leitura do código de barras pela câmara (ou procura na lista de artigos desse armazém);
 *   - circuito com segregação de funções: contado (técnico de armazém) → validado (controlo de stock: vê as
 *     diferenças, pede recontagem dos artigos duvidosos ou valida com o motivo de cada diferença) → aprovado
 *     (responsável); três pessoas diferentes. O acerto no Pinguim BO é lançado pela Logística (o portal só lê);
 *   - tudo registado (quem, quando, o que mudou).
 * Acessos: contar «conciliacao-carrinha» G; validar «requisicoes-validacao» G; aprovar «conciliacao-fecho» G.
 * Só SELECT no Pinguim BO.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_INV_ESTADOS = ['em_contagem', 'por_validar', 'por_aprovar', 'aprovado', 'anulado'];
const NAVAL_INV_MOTIVOS = [
    'erro_contagem_anterior' => 'Erro numa contagem anterior',
    'movimento_nao_registado' => 'Movimento não registado no Pinguim BO',
    'avaria' => 'Produto danificado',
    'validade' => 'Fora de validade',
    'troca_artigo' => 'Troca de artigo',
    'falta' => 'Falta sem justificação',
    'outro' => 'Outro (explicar na nota)',
];

function naval_inv_pasta(): string
{
    return naval_pasta('inventarios');
}

function naval_inv_ficheiro(string $numero): string
{
    if (!preg_match('/^INV-(\d{4})-\d{6}$/', $numero, $m)) throw new NavalErro('Número de inventário inválido.', 400);
    return naval_inv_pasta() . '/' . $m[1] . '/' . $numero . '.json';
}

function naval_inv_ler(string $numero): array
{
    $x = naval_json_ler(naval_inv_ficheiro($numero));
    if (!$x) throw new NavalErro('Inventário não encontrado.', 404);
    return $x;
}

function naval_inv_gravar_reg(array $inv): void
{
    $f = naval_inv_ficheiro((string) $inv['numero']);
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0770, true);
    naval_json_gravar($f, $inv);
}

/** Quem pode o quê (o super administrador pode tudo, para testar; a segregação continua a valer). */
function naval_inv_pode(): array
{
    $sadm = naval_perfil() === 'SADM';
    return ['contar' => $sadm || naval_pode('conciliacao-carrinha', 'G'), 'validar' => $sadm || naval_pode('requisicoes-validacao', 'G'),
        'aprovar' => $sadm || naval_pode('conciliacao-fecho', 'G')];
}

/** Armazéns físicos (sem as carrinhas) com o número de artigos em stock: [{codigo, nome, artigos}]. Guardado 30 minutos. */
function naval_inv_armazens(): array
{
    naval_servico('stock');
    return naval_cache('inventario-armazens|' . date('Y-m-d') . '|' . floor(time() / 1800), 1800, function () {
        $d = naval_stock(date('Y-m-d'), '');
        $out = [];
        foreach ((array) ($d['armazens'] ?? []) as $a) {
            $c = (string) ($a['cod_armazem'] ?? '');
            if ($c === '') continue;
            $out[] = ['codigo' => $c, 'nome' => (string) ($a['armazem'] ?? $c), 'artigos' => count((array) ($a['itens'] ?? []))];
        }
        usort($out, function ($a, $b) { return strcmp($a['nome'], $b['nome']); });
        return $out;
    });
}

/** Stock do Pinguim BO num armazém agora: {codigo: {artigo, q}} (a base congelada da contagem). */
function naval_inv_stock_armazem(string $cod): array
{
    naval_servico('stock');
    $d = naval_stock(date('Y-m-d'), $cod);
    $out = [];
    foreach ((array) ($d['armazens'] ?? []) as $a) {
        if ((string) ($a['cod_armazem'] ?? '') !== $cod) continue;
        foreach ((array) ($a['itens'] ?? []) as $i) {
            $c = trim((string) ($i['codigo'] ?? ''));
            if ($c === '') continue;
            $out[$c] = ['artigo' => (string) ($i['produto'] ?? $c), 'q' => round(($out[$c]['q'] ?? 0) + (float) ($i['quantidade'] ?? 0), 3)];
        }
        $nome = (string) ($a['armazem'] ?? $cod);
    }
    return ['nome' => $nome ?? $cod, 'itens' => $out];
}

/** Número seguinte do ano (INV-AAAA-NNNNNN). */
function naval_inv_numero(): string
{
    $ano = date('Y');
    $n = 0;
    foreach (glob(naval_inv_pasta() . '/' . $ano . '/INV-' . $ano . '-*.json') ?: [] as $f) $n = max($n, (int) substr(basename($f, '.json'), -6));
    return sprintf('INV-%s-%06d', $ano, $n + 1);
}

function naval_inv_hist(array &$inv, string $accao, string $por, array $extra = []): void
{
    $inv['historico'][] = ['em' => date('Y-m-d H:i:s'), 'por' => $por, 'accao' => $accao] + $extra;
}

/** Começa a contagem de um armazém: congela o stock do sistema nesse instante (corte). */
function naval_inv_criar(string $armazem, string $login, ?array $stock = null): array
{
    $armazem = trim($armazem);
    if ($armazem === '' || mb_strlen($armazem) > 40) throw new NavalErro('Escolha o armazém.', 400);
    $s = $stock ?? naval_inv_stock_armazem($armazem);
    return naval_exclusivo('inventarios', function () use ($armazem, $login, $s) {
        // um só inventário aberto por armazém (duas contagens ao mesmo tempo baralham o corte)
        foreach (naval_inv_lista(null, 90) as $x) {
            if ($x['armazem'] === $armazem && in_array($x['estado'], ['em_contagem', 'por_validar', 'por_aprovar'], true)) {
                throw new NavalErro('Este armazém já tem o inventário ' . $x['numero'] . ' em curso.', 409, ['numero' => $x['numero']]);
            }
        }
        $agora = date('Y-m-d H:i:s');
        $inv = ['numero' => naval_inv_numero(), 'armazem' => $armazem, 'armazem_nome' => (string) $s['nome'], 'estado' => 'em_contagem', 'criado_por' => $login,
            'criado_em' => $agora, 'base_em' => $agora, 'base' => (object) $s['itens'], 'contagem' => (object) [], 'extra' => (object) [], 'recontar' => [],
            'motivos' => (object) [], 'contado_por' => null, 'contado_em' => null, 'validado_por' => null, 'validado_em' => null,
            'aprovado_por' => null, 'aprovado_em' => null, 'historico' => []];
        naval_inv_hist($inv, 'criado', $login, ['artigos' => count($s['itens'])]);
        naval_inv_gravar_reg($inv);
        naval_registar('inventario_criado', ['numero' => $inv['numero'], 'armazem' => $armazem]);
        return $inv;
    });
}

/** Linhas da contagem (função pura): base × contado, com a diferença; $cega esconde o sistema (quem conta). */
function naval_inv_linhas(array $inv, bool $cega): array
{
    $base = (array) ($inv['base'] ?? []);
    $cont = (array) ($inv['contagem'] ?? []);
    $extra = (array) ($inv['extra'] ?? []);
    $rec = array_flip(array_map('strval', (array) ($inv['recontar'] ?? [])));
    $out = [];
    foreach (array_keys($base + $extra + $cont) as $c) {
        $c = (string) $c;
        $sis = isset($base[$c]) ? (float) ((array) $base[$c])['q'] : 0.0;
        $q = array_key_exists($c, $cont) && $cont[$c] !== null ? (float) $cont[$c] : null;
        $l = ['codigo' => $c, 'artigo' => (string) (((array) ($base[$c] ?? $extra[$c] ?? []))['artigo'] ?? $c), 'contado' => $q, 'recontar' => isset($rec[$c]),
            'fora_da_lista' => !isset($base[$c])];
        if (!$cega) {
            $l['sistema'] = round($sis, 3);
            $l['diferenca'] = $q === null ? null : round($q - $sis, 3);
        }
        $out[] = $l;
    }
    usort($out, function ($a, $b) { return strcmp($a['artigo'], $b['artigo']); });
    return $out;
}

/** Resumo (função pura): artigos, contados, diferenças (só quem não conta às cegas). */
function naval_inv_resumo(array $inv): array
{
    $l = naval_inv_linhas($inv, false);
    $dif = array_filter($l, function ($x) { return $x['diferenca'] !== null && abs($x['diferenca']) > 0.0001; });
    return ['artigos' => count($l), 'contados' => count(array_filter($l, function ($x) { return $x['contado'] !== null; })), 'diferencas' => count($dif),
        'faltas' => round(-array_sum(array_map(function ($x) { return min(0, $x['diferenca']); }, $dif)), 3),
        'sobras' => round(array_sum(array_map(function ($x) { return max(0, $x['diferenca']); }, $dif)), 3)];
}

/** Grava a contagem (parcial ou final). $terminar: segue para a validação (todos os artigos da lista contados). */
function naval_inv_contar(string $numero, array $contagem, array $extra, string $login, bool $terminar): array
{
    return naval_exclusivo('inventario-' . $numero, function () use ($numero, $contagem, $extra, $login, $terminar) {
        $inv = naval_inv_ler($numero);
        if ($inv['estado'] !== 'em_contagem') throw new NavalErro('Este inventário já não está em contagem.', 409);
        $c = (array) $inv['contagem'];
        foreach ($contagem as $cod => $q) {
            $cod = (string) $cod;
            if (!preg_match('/^[A-Za-z0-9._\/-]{1,40}$/', $cod)) continue;
            if ($q === '' || $q === null) {
                unset($c[$cod]);
                continue;
            }
            if (!is_numeric($q) || (float) $q < 0 || (float) $q > 10000000) throw new NavalErro('Quantidade inválida no artigo ' . $cod . '.', 400);
            $c[$cod] = round((float) $q, 3);
        }
        $ex = (array) $inv['extra'];
        foreach ($extra as $cod => $nome) {
            $cod = (string) $cod;
            if (!preg_match('/^[A-Za-z0-9._\/-]{1,40}$/', $cod) || isset(((array) $inv['base'])[$cod])) continue;
            $ex[$cod] = ['artigo' => mb_substr(trim((string) $nome) ?: $cod, 0, 120)];
        }
        $inv['contagem'] = (object) $c;
        $inv['extra'] = (object) $ex;
        if ($terminar) {
            $falta = array_filter(naval_inv_linhas($inv, true), function ($x) { return $x['contado'] === null; });
            if ($falta) throw new NavalErro('Faltam contar ' . count($falta) . ' artigo(s): conte-os (0 se não houver) antes de terminar.', 409,
                ['em_falta' => array_column($falta, 'codigo')]);
            $inv['estado'] = 'por_validar';
            $inv['contado_por'] = $login;
            $inv['contado_em'] = date('Y-m-d H:i:s');
            $inv['recontar'] = [];
            naval_inv_hist($inv, 'contado', $login, naval_inv_resumo($inv));
        } else naval_inv_hist($inv, 'gravado', $login, ['contados' => count($c)]);
        naval_inv_gravar_reg($inv);
        if ($terminar) naval_registar('inventario_contado', ['numero' => $numero] + naval_inv_resumo($inv));
        return $inv;
    });
}

/**
 * Validação (controlo de stock, nunca quem contou): com $recontar (códigos), volta à contagem só desses artigos;
 * senão valida, com o motivo de cada diferença, e segue para a aprovação.
 */
function naval_inv_validar(string $numero, string $login, array $motivos, array $recontar): array
{
    return naval_exclusivo('inventario-' . $numero, function () use ($numero, $login, $motivos, $recontar) {
        $inv = naval_inv_ler($numero);
        if ($inv['estado'] !== 'por_validar') throw new NavalErro('Este inventário não está por validar.', 409);
        if ($login === $inv['contado_por']) throw new NavalErro('Segregação de funções: quem contou não valida.', 403);
        $recontar = array_values(array_unique(array_filter(array_map('strval', $recontar), function ($c) { return preg_match('/^[A-Za-z0-9._\/-]{1,40}$/', $c); })));
        if ($recontar) {
            $c = (array) $inv['contagem'];
            foreach ($recontar as $cod) unset($c[$cod]);
            $inv['contagem'] = (object) $c;
            $inv['recontar'] = $recontar;
            $inv['estado'] = 'em_contagem';
            naval_inv_hist($inv, 'recontagem_pedida', $login, ['artigos' => $recontar]);
            naval_inv_gravar_reg($inv);
            naval_registar('inventario_recontagem', ['numero' => $numero, 'artigos' => count($recontar)]);
            return $inv;
        }
        $m = [];
        foreach (naval_inv_linhas($inv, false) as $l) {
            if ($l['diferenca'] === null || abs($l['diferenca']) <= 0.0001) continue;
            $x = (array) ($motivos[$l['codigo']] ?? []);
            $mot = (string) ($x['motivo'] ?? '');
            if (!isset(NAVAL_INV_MOTIVOS[$mot])) throw new NavalErro('Indique o motivo da diferença em ' . $l['artigo'] . '.', 400, ['codigo' => $l['codigo']]);
            $nota = mb_substr(trim((string) ($x['nota'] ?? '')), 0, 200);
            if ($mot === 'outro' && mb_strlen($nota) < 5) throw new NavalErro('Explique o motivo em ' . $l['artigo'] . '.', 400, ['codigo' => $l['codigo']]);
            $m[$l['codigo']] = ['motivo' => $mot, 'nota' => $nota];
        }
        $inv['motivos'] = (object) $m;
        $inv['estado'] = 'por_aprovar';
        $inv['validado_por'] = $login;
        $inv['validado_em'] = date('Y-m-d H:i:s');
        naval_inv_hist($inv, 'validado', $login, ['diferencas' => count($m)]);
        naval_inv_gravar_reg($inv);
        naval_registar('inventario_validado', ['numero' => $numero, 'diferencas' => count($m)]);
        return $inv;
    });
}

/** Aprovação final (responsável: nem quem contou nem quem validou). A Logística lança o acerto no Pinguim BO. */
function naval_inv_aprovar(string $numero, string $login): array
{
    return naval_exclusivo('inventario-' . $numero, function () use ($numero, $login) {
        $inv = naval_inv_ler($numero);
        if ($inv['estado'] !== 'por_aprovar') throw new NavalErro('Este inventário não está por aprovar.', 409);
        if ($login === $inv['contado_por'] || $login === $inv['validado_por']) throw new NavalErro('Segregação de funções: quem contou ou validou não aprova.', 403);
        $inv['estado'] = 'aprovado';
        $inv['aprovado_por'] = $login;
        $inv['aprovado_em'] = date('Y-m-d H:i:s');
        naval_inv_hist($inv, 'aprovado', $login, naval_inv_resumo($inv));
        naval_inv_gravar_reg($inv);
        naval_registar('inventario_aprovado', ['numero' => $numero] + naval_inv_resumo($inv));
        return $inv;
    });
}

function naval_inv_anular(string $numero, string $login, string $motivo): array
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5) throw new NavalErro('Indique o motivo da anulação.', 400);
    return naval_exclusivo('inventario-' . $numero, function () use ($numero, $login, $motivo) {
        $inv = naval_inv_ler($numero);
        if (in_array($inv['estado'], ['aprovado', 'anulado'], true)) throw new NavalErro('Este inventário já está fechado.', 409);
        $inv['estado'] = 'anulado';
        naval_inv_hist($inv, 'anulado', $login, ['motivo' => mb_substr($motivo, 0, 200)]);
        naval_inv_gravar_reg($inv);
        naval_registar('inventario_anulado', ['numero' => $numero]);
        return $inv;
    });
}

/** Inventários dos últimos $dias (mais recente primeiro), sem as linhas: [{numero, armazem, armazem_nome, estado, ...}]. */
function naval_inv_lista(?array $estados = null, int $dias = 60): array
{
    $out = [];
    $desde = date('Y-m-d', time() - $dias * 86400);
    foreach ([date('Y'), (string) ((int) date('Y') - 1)] as $ano) {
        foreach (glob(naval_inv_pasta() . '/' . $ano . '/INV-*.json') ?: [] as $f) {
            $x = naval_json_ler($f);
            if (!$x || substr((string) $x['criado_em'], 0, 10) < $desde || ($estados !== null && !in_array($x['estado'], $estados, true))) continue;
            $out[] = array_intersect_key($x, array_flip(['numero', 'armazem', 'armazem_nome', 'estado', 'criado_por', 'criado_em', 'base_em', 'contado_por', 'contado_em',
                'validado_por', 'validado_em', 'aprovado_por', 'aprovado_em'])) + ['resumo' => naval_inv_resumo($x), 'recontar' => count((array) ($x['recontar'] ?? []))];
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['criado_em'], $a['criado_em']); });
    return $out;
}

/**
 * Códigos de barras dos artigos no Pinguim BO (só SELECT): {código de barras: código do artigo}. A coluna descobre-se
 * na estrutura da base de dados (INFORMATION_SCHEMA: tabela de produtos com BARCODE/EAN, ou tabela própria dos
 * códigos de barras); guardado 12 horas. Sem ligação ou sem coluna: [] (a leitura usa então o código do artigo).
 */
function naval_inv_codigos_barras(): array
{
    naval_servico('bd');
    if (!naval_bd_configurada()) return [];
    return (array) naval_cache('inventario-barras|' . date('Y-m-d') . '|' . floor(time() / 43200), 43200, function () {
        try {
            $cols = naval_bd_consultar("SELECT TABLE_NAME AS T, COLUMN_NAME AS C FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo' AND (UPPER(COLUMN_NAME) IN ('BARCODE', 'BAR_CODE', 'EAN', 'EAN13', 'CODEBAR', 'BARCODE_VALUE', 'CODE_BAR')
                   OR UPPER(TABLE_NAME) LIKE '%BARCODE%') ORDER BY TABLE_NAME", []);
        } catch (Throwable $e) {
            error_log('[inventário códigos de barras] ' . $e->getMessage());
            return [];
        }
        $porTabela = [];
        foreach ($cols as $r) $porTabela[strtoupper((string) $r['T'])][] = strtoupper((string) $r['C']);
        $consultas = [];
        foreach ($porTabela as $tab => $cs) {
            if (!preg_match('/^[A-Z0-9_]{1,60}$/', $tab)) continue;
            $col = null;
            foreach (['BARCODE', 'BAR_CODE', 'EAN', 'EAN13', 'CODEBAR', 'BARCODE_VALUE', 'CODE_BAR', 'CODE'] as $k) if (in_array($k, $cs, true)) { $col = $k; break; }
            if ($col === null) continue;
            if ($tab === 'PRODUCT') $consultas[] = "SELECT LTRIM(RTRIM(CAST($col AS VARCHAR(60)))) AS B, LTRIM(RTRIM(CODE)) AS P FROM PRODUCT WHERE DELETED = 0 AND $col IS NOT NULL";
            elseif (strpos($tab, 'BARCODE') !== false && in_array('PRODUCT_ID', $cs, true) && $col !== 'CODE') {
                $consultas[] = "SELECT LTRIM(RTRIM(CAST(B.$col AS VARCHAR(60)))) AS B, LTRIM(RTRIM(P.CODE)) AS P FROM $tab B INNER JOIN PRODUCT P ON P.ID = B.PRODUCT_ID WHERE B.$col IS NOT NULL"
                    . (in_array('DELETED', $cs, true) ? ' AND B.DELETED = 0' : '') . ' AND P.DELETED = 0';
            }
        }
        $out = [];
        foreach ($consultas as $q) {
            try {
                foreach (naval_bd_consultar($q, []) as $r) {
                    $b = (string) $r['B'];
                    if ($b !== '' && strlen($b) <= 60 && !isset($out[$b])) $out[$b] = (string) $r['P'];
                }
            } catch (Throwable $e) {
                error_log('[inventário códigos de barras] ' . $e->getMessage());
            }
        }
        return $out;
    });
}
