<?php
/**
 * Camada de fontes (arquitectura de dados do Portal RTM): cada domínio lê da fonte que serve a decisão.
 *
 *   - Hoje (D): sempre o Pinguim BO, directo (Ao vivo, conciliação das 15:30, fecho de caixa).
 *   - Dias anteriores: o OLAP, mas só os dias com a carga concluída (rtm.ctl_carga) E a conferência com o Pinguim BO
 *     aprovada depois dessa carga («corte conferido»); os outros continuam a vir do Pinguim BO.
 *   - Escolha por domínio no .env: FONTE_VENDAS = pinguim (por omissão) | paralelo (lê do Pinguim BO e confere o OLAP
 *     todas as manhãs, sem o mostrar) | olap (mostra do OLAP os dias conferidos).
 *
 * Contrato de dados (vistas no esquema rtm do OLAP, versão NAVAL_FONTES_CONTRATO): o portal confirma as colunas antes
 * de usar o OLAP; sem o contrato completo, lê do Pinguim BO. As regras do portal (IVA, notas de crédito, anulados,
 * devoluções e substituições) aplicam-se do mesmo modo às duas fontes: o OLAP entrega os factos em bruto.
 *
 * Continuidade (ISO 22301): disjuntor — 3 falhas do OLAP em 5 minutos param as tentativas durante 5 minutos e o portal
 * lê do Pinguim BO. Conferência diária (ISO/IEC 25012: completude, exactidão, consistência) dos últimos 7 dias: número
 * de documentos, totais com e sem IVA, linhas, quantidades e pagamentos por meio, por dia e rota; diferenças vão por
 * correio e ficam na página Conformidade.
 */
defined('NAVAL_NUCLEO') || exit;
naval_servico('bd');

const NAVAL_FONTES_CONTRATO = '1.0';
const NAVAL_FONTES_JANELA = 7;           // dias conferidos (e recarregados pela carga nocturna)
const NAVAL_FONTES_DISJUNTOR = [3, 300, 300];   // falhas, em segundos, pausa em segundos

/** Colunas obrigatórias de cada vista do contrato (esquema rtm). */
const NAVAL_FONTES_VISTAS = [
    'f_documento' => ['DOC_ID', 'DATA', 'HORA', 'ROTA', 'TIPO_CODIGO', 'TIPO_DESCRICAO', 'DOCUMENTO', 'CLIENTE', 'CLIENTE_CODIGO', 'NIF',
        'LIQUIDADO', 'SINAL_VENDAS', 'TOTAL_COM_IVA', 'TOTAL_PAGO', 'ELIMINADO', 'ESTADO_ID', 'MOTIVO_ANULACAO'],
    'f_venda_linha' => ['DOC_ID', 'LINHA', 'CODIGO', 'ARTIGO', 'UNIDADE', 'QUANTIDADE', 'PRECO', 'VALOR_SEM_IVA', 'DESCONTO', 'IVA',
        'TOTAL_COM_IVA', 'TOTAL_BRUTO', 'ELIMINADA'],
    'f_pagamento' => ['DOC_ID', 'MEIO_CODIGO', 'MEIO', 'VALOR', 'ELIMINADO'],
    'ctl_carga' => ['DIA', 'INICIO', 'FIM', 'ESTADO', 'LINHAS', 'VERSAO_CONTRATO'],
];

function naval_fontes_pasta(): string
{
    return naval_pasta('fontes');
}

/** Modo de um domínio: pinguim | paralelo | olap (sem OLAP configurado, sempre pinguim). */
function naval_fontes_modo(string $dominio = 'vendas'): string
{
    $m = strtolower(trim(naval_config('FONTE_' . strtoupper($dominio), 'pinguim')));
    if (!in_array($m, ['pinguim', 'paralelo', 'olap'], true)) $m = 'pinguim';
    return $m !== 'pinguim' && !naval_fontes_olap_configurado() ? 'pinguim' : $m;
}

function naval_fontes_olap_configurado(): bool
{
    return naval_config('OLAP_DSN') !== '' || naval_config('OLAP_HOST') !== '';
}

/** Ligação ao OLAP (uma por pedido). */
function naval_fontes_olap(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    return $pdo = naval_bd_criar('OLAP');
}

/** SELECT no OLAP com o disjuntor: as falhas contam e, com o disjuntor aberto, nem se tenta. */
function naval_fontes_olap_consultar(string $sql, array $p = []): array
{
    if (naval_fontes_disjuntor_aberto()) throw new RuntimeException('OLAP em pausa depois de falhas seguidas (disjuntor aberto).');
    try {
        $r = naval_bd_consultar_em(naval_fontes_olap(), $sql, $p);
        naval_fontes_disjuntor(true);
        return $r;
    } catch (Throwable $e) {
        naval_fontes_disjuntor(false, $e->getMessage());
        throw $e;
    }
}

/** Estado do disjuntor do OLAP: {falhas: [carimbos], aberto_ate, erro}. */
function naval_fontes_disjuntor_estado(): array
{
    return (array) naval_json_ler(naval_fontes_pasta() . '/disjuntor-olap.json', []);
}

function naval_fontes_disjuntor_aberto(?int $agora = null): bool
{
    return (int) (naval_fontes_disjuntor_estado()['aberto_ate'] ?? 0) > ($agora ?? time());
}

/** Regista o resultado de uma consulta ao OLAP; 3 falhas em 5 minutos abrem o disjuntor durante 5 minutos. */
function naval_fontes_disjuntor(bool $ok, string $erro = '', ?int $agora = null): array
{
    $agora = $agora ?? time();
    $f = naval_fontes_pasta() . '/disjuntor-olap.json';
    $e = naval_fontes_disjuntor_estado();
    if ($ok) {
        if (!$e) return [];
        $e = [];
    } else {
        [$n, $janela, $pausa] = NAVAL_FONTES_DISJUNTOR;
        $e['falhas'] = array_values(array_filter(array_merge((array) ($e['falhas'] ?? []), [$agora]), function ($t) use ($agora, $janela) { return $t > $agora - $janela; }));
        $e['erro'] = mb_substr($erro, 0, 300);
        if (count($e['falhas']) >= $n) {
            $e['aberto_ate'] = $agora + $pausa;
            $e['falhas'] = [];
            error_log('[fontes] disjuntor do OLAP aberto até ' . date('H:i:s', $e['aberto_ate']) . ': ' . $e['erro']);
        }
    }
    try {
        naval_json_gravar($f, $e);
    } catch (Throwable $x) {
        /* sem pasta: o disjuntor vale só para este pedido */
    }
    return $e;
}

/** Confirma o contrato no OLAP (colunas das vistas rtm.*), com cache de 1 hora. {ok, faltam: [vista.coluna], erro}. */
function naval_fontes_contrato(bool $fresco = false): array
{
    if (!naval_fontes_olap_configurado()) return ['ok' => false, 'faltam' => [], 'erro' => 'OLAP por configurar.'];
    return naval_cache('fontes-contrato|' . NAVAL_FONTES_CONTRATO, 3600, function () {
        try {
            $col = [];
            foreach (naval_fontes_olap_consultar("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'rtm'") as $l) {
                $col[strtolower((string) $l['TABLE_NAME'])][strtoupper((string) $l['COLUMN_NAME'])] = true;
            }
            return naval_fontes_contrato_avaliar($col);
        } catch (Throwable $e) {
            return ['ok' => false, 'faltam' => [], 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }
    }, $fresco);
}

/** Compara as colunas existentes ({vista: {COLUNA: true}}) com o contrato (função pura, testada). */
function naval_fontes_contrato_avaliar(array $col): array
{
    $faltam = [];
    foreach (NAVAL_FONTES_VISTAS as $v => $cs) {
        foreach ($cs as $c) if (empty($col[$v][$c])) $faltam[] = 'rtm.' . $v . '.' . $c;
    }
    return ['ok' => !$faltam, 'faltam' => $faltam, 'erro' => null, 'versao' => NAVAL_FONTES_CONTRATO];
}

/** Cargas do OLAP por dia (rtm.ctl_carga), com cache de 10 minutos: {AAAA-MM-DD: {fim, estado, linhas}}. */
function naval_fontes_cargas(string $de, string $ate, bool $fresco = false): array
{
    if (!naval_fontes_olap_configurado()) return [];
    return naval_cache('fontes-cargas|' . $de . '|' . $ate, 600, function () use ($de, $ate) {
        try {
            $out = [];
            foreach (naval_fontes_olap_consultar("SELECT CONVERT(VARCHAR(10), DIA, 120) AS DIA, CONVERT(VARCHAR(19), FIM, 120) AS FIM, ESTADO, LINHAS, VERSAO_CONTRATO
                FROM rtm.ctl_carga WHERE DIA >= :de AND DIA <= :ate", [':de' => $de, ':ate' => $ate]) as $l) {
                $d = (string) $l['DIA'];
                // a carga mais recente de cada dia
                if (!isset($out[$d]) || (string) $l['FIM'] > $out[$d]['fim']) {
                    $out[$d] = ['fim' => (string) $l['FIM'], 'estado' => strtolower(trim((string) $l['ESTADO'])), 'linhas' => (int) $l['LINHAS'], 'versao' => (string) $l['VERSAO_CONTRATO']];
                }
            }
            return $out;
        } catch (Throwable $e) {
            error_log('[fontes] cargas: ' . $e->getMessage());
            return null;   // não fica na cache: volta a tentar no próximo pedido
        }
    }, $fresco) ?? [];
}

/** Resultado da conferência de um dia (ficheiro), ou null. */
function naval_fontes_conferencia(string $dia): ?array
{
    $c = naval_json_ler(naval_fontes_pasta() . '/conferencia-' . $dia . '.json');
    return $c ?: null;
}

/**
 * Dias do período que podem vir do OLAP (corte conferido): antes de hoje, carga concluída com a versão do contrato,
 * conferência aprovada DEPOIS dessa carga (uma recarga obriga a conferir de novo). Função pura (testada).
 */
function naval_fontes_dias_conferidos(array $dias, string $hoje, array $cargas, callable $conferencia): array
{
    $ok = [];
    foreach ($dias as $d) {
        if ($d >= $hoje) continue;
        $c = $cargas[$d] ?? null;
        if (!$c || !in_array($c['estado'], ['concluida', 'concluída', 'ok', 'sucesso'], true) || $c['fim'] === '') continue;
        if (($c['versao'] ?? NAVAL_FONTES_CONTRATO) !== '' && explode('.', (string) ($c['versao'] ?? NAVAL_FONTES_CONTRATO))[0] !== explode('.', NAVAL_FONTES_CONTRATO)[0]) continue;
        $f = $conferencia($d);
        if (!$f || empty($f['ok']) || (string) ($f['carga_fim'] ?? '') !== $c['fim']) continue;
        $ok[] = $d;
    }
    return $ok;
}

/** Dias AAAA-MM-DD de um período (datas AAAAMMDD ou AAAA-MM-DD). */
function naval_fontes_dias(string $inicio, string $fim): array
{
    $a = naval_bd_data($inicio);
    $b = naval_bd_data($fim);
    $out = [];
    for ($t = strtotime($a . ' 12:00'); $t <= strtotime($b . ' 12:00') && count($out) < 1100; $t += 86400) $out[] = date('Y-m-d', $t);
    return $out;
}

/** Agrupa dias seguidos com a mesma fonte: [[fonte, de, ate], …] (função pura, testada). */
function naval_fontes_segmentos(array $dias, array $olap): array
{
    $seg = [];
    $set = array_flip($olap);
    foreach ($dias as $d) {
        $f = isset($set[$d]) ? 'olap' : 'pinguim';
        $n = count($seg);
        if ($n && $seg[$n - 1][0] === $f && date('Y-m-d', strtotime($seg[$n - 1][2] . ' 12:00') + 86400) === $d) $seg[$n - 1][2] = $d;
        else $seg[] = [$f, $d, $d];
    }
    return $seg;
}

/** Fonte de cada parte do período, para mostrar («Dados até …»): pinguim, olap ou misto. */
function naval_fontes_resumo(string $inicio, string $fim): string
{
    $fs = array_unique(array_column(naval_fontes_plano($inicio, $fim), 0));
    return count($fs) > 1 ? 'misto' : ($fs[0] ?? 'pinguim');
}

/** Plano de leitura do período: segmentos [fonte, de, ate]. */
function naval_fontes_plano(string $inicio, string $fim): array
{
    $dias = naval_fontes_dias($inicio, $fim);
    $olap = [];
    // pedidos fiscais (ficheiro para o SAP): sempre do Pinguim BO
    if (empty($GLOBALS['naval_fontes_fiscal']) && naval_fontes_modo('vendas') === 'olap' && !naval_fontes_disjuntor_aberto() && naval_fontes_contrato()['ok']) {
        $hoje = date('Y-m-d');
        $passados = array_values(array_filter($dias, function ($d) use ($hoje) { return $d < $hoje; }));
        if ($passados) {
            $cargas = naval_fontes_cargas($passados[0], end($passados));
            $olap = naval_fontes_dias_conferidos($passados, $hoje, $cargas, 'naval_fontes_conferencia');
        }
    }
    return naval_fontes_segmentos($dias, $olap);
}

/**
 * Vendas do período pela camada de fontes, sem as regras finais (devoluções e substituições, aplicadas por quem chama
 * ao conjunto). $soArtigos: só as linhas. Se um segmento do OLAP falhar, lê-se esse segmento do Pinguim BO.
 * null se nenhuma fonte responder (o portal usa então a exportação REST).
 */
function naval_fontes_vendas(string $inicio, string $fim, array $promotores, bool $itens, bool $soArtigos = false): ?array
{
    $vendas = [];
    $artigos = [];
    foreach (naval_fontes_plano($inicio, $fim) as [$f, $de, $ate]) {
        $x = null;
        if ($f === 'olap') {
            try {
                $x = naval_fontes_vendas_olap($de, $ate, $promotores, $itens || $soArtigos, $soArtigos);
            } catch (Throwable $e) {
                error_log('[fontes] OLAP ' . $de . '…' . $ate . ': ' . $e->getMessage() . ' — lido do Pinguim BO');
                $x = null;
            }
        }
        if ($x === null) {
            if ($soArtigos) {
                $a = naval_vendas_artigos_pinguim(str_replace('-', '', $de), str_replace('-', '', $ate), $promotores);
                $x = $a === null ? null : ['vendas' => [], 'artigos' => $a];
            } else {
                $x = naval_vendas_pinguim(str_replace('-', '', $de), str_replace('-', '', $ate), $promotores, $itens);
            }
        }
        if ($x === null) return null;
        $vendas = array_merge($vendas, $x['vendas']);
        $artigos = array_merge($artigos, $x['artigos']);
    }
    // a mesma ordem da consulta directa: dia mais recente primeiro (estável dentro do dia)
    $ord = function (array $l): array {
        $i = 0;
        foreach ($l as $k => $v) $l[$k]['__i'] = $i++;
        usort($l, function ($a, $b) { return strcmp((string) $b['data'], (string) $a['data']) ?: $a['__i'] <=> $b['__i']; });
        foreach ($l as $k => $v) unset($l[$k]['__i']);
        return $l;
    };
    return ['vendas' => $ord($vendas), 'artigos' => $ord($artigos)];
}

/** Condição dos documentos válidos no contrato (as mesmas regras de naval_vendas_condicao_validos), alias D. */
function naval_fontes_condicao_validos(): string
{
    naval_servico('vendas');
    $c = [];
    $ids = naval_vendas_estados_anulados();
    if ($ids) $c[] = 'COALESCE(D.ESTADO_ID, 0) IN (' . implode(', ', $ids) . ')';
    if (naval_vendas_extra_anulacao() > 0) $c[] = "LTRIM(RTRIM(COALESCE(D.MOTIVO_ANULACAO, ''))) <> ''";
    return 'D.ELIMINADO = 0' . ($c ? ' AND NOT (' . implode(' OR ', $c) . ')' : '');
}

/** Documentos e linhas do período lidos do OLAP (contrato rtm), no formato do Pinguim BO. Lança excepção se falhar. */
function naval_fontes_vendas_olap(string $de, string $ate, array $promotores, bool $itens, bool $soArtigos = false): array
{
    naval_servico('vendas');
    $p = [':de' => $de, ':ate' => $ate];
    $v = naval_fontes_condicao_validos();
    $sinal = '((D.TOTAL_COM_IVA * D.SINAL_VENDAS) > 0 OR (D.SINAL_VENDAS < 0 AND D.TOTAL_COM_IVA <> 0))';
    $vendas = [];
    if (!$soArtigos) {
        $docs = naval_fontes_olap_consultar("
            SELECT D.DOC_ID, CONVERT(VARCHAR(10), D.DATA, 120) AS DATA, D.HORA, D.ROTA, D.TIPO_CODIGO AS TIPO_DOC, D.TIPO_DESCRICAO AS TIPO_DESC,
                D.DOCUMENTO, D.CLIENTE, D.CLIENTE_CODIGO, D.NIF, CAST(D.LIQUIDADO AS INT) AS LIQUIDADO,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN 1 ELSE 0 END AS INT) AS NC,
                CAST((CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(D.TOTAL_COM_IVA) ELSE D.TOTAL_COM_IVA * D.SINAL_VENDAS END) AS FLOAT) AS TOTAL,
                CAST(D.TOTAL_PAGO AS FLOAT) AS TOTAL_PAID
            FROM rtm.f_documento D
            WHERE $v AND $sinal AND D.DATA >= :de AND D.DATA <= :ate
            ORDER BY D.DATA DESC, D.DOC_ID DESC", $p);
        $semIva = [];
        foreach (naval_fontes_olap_consultar("
            SELECT L.DOC_ID, CAST(SUM(L.VALOR_SEM_IVA) AS FLOAT) AS SEM_IVA
            FROM rtm.f_venda_linha L INNER JOIN rtm.f_documento D ON D.DOC_ID = L.DOC_ID
            WHERE L.ELIMINADA = 0 AND $v AND D.DATA >= :de AND D.DATA <= :ate
            GROUP BY L.DOC_ID", $p) as $l) {
            $semIva[$l['DOC_ID']] = (float) $l['SEM_IVA'];
        }
        $pags = [];
        foreach (naval_fontes_olap_consultar("
            SELECT P.DOC_ID, P.MEIO_CODIGO AS CODIGO, P.MEIO, CAST(P.VALOR AS FLOAT) AS VALOR
            FROM rtm.f_pagamento P INNER JOIN rtm.f_documento D ON D.DOC_ID = P.DOC_ID
            WHERE P.ELIMINADO = 0 AND $v AND D.DATA >= :de AND D.DATA <= :ate", $p) as $pg) {
            $pags[$pg['DOC_ID']][] = ['codigo' => $pg['CODIGO'] ?? '?', 'meio' => $pg['MEIO'] ?? 'Sem meio indicado', 'valor' => (float) $pg['VALOR']];
        }
        $vendas = naval_vendas_montar($docs, $semIva, $pags, $promotores);
    }
    $artigos = [];
    if ($itens) {
        foreach (naval_fontes_olap_consultar("
            SELECT D.DOCUMENTO, CONVERT(VARCHAR(10), D.DATA, 120) AS DATA, D.ROTA, L.LINHA,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN 1 ELSE 0 END AS INT) AS NC,
                L.CODIGO, L.ARTIGO, COALESCE(L.UNIDADE, 'UN') AS UNIDADE,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.QUANTIDADE) ELSE L.QUANTIDADE END AS FLOAT) AS QUANTIDADE,
                CAST(L.PRECO AS FLOAT) AS PRECO_UNITARIO,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.VALOR_SEM_IVA) ELSE L.VALOR_SEM_IVA END AS FLOAT) AS VALOR_SEM_IVA,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.DESCONTO) ELSE L.DESCONTO END AS FLOAT) AS DESCONTO,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.IVA) ELSE L.IVA END AS FLOAT) AS IVA,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.TOTAL_COM_IVA) ELSE L.TOTAL_COM_IVA END AS FLOAT) AS TOTAL,
                CAST(CASE WHEN D.SINAL_VENDAS < 0 THEN -ABS(L.TOTAL_BRUTO) ELSE L.TOTAL_BRUTO END AS FLOAT) AS GROSS_TOTAL
            FROM rtm.f_venda_linha L INNER JOIN rtm.f_documento D ON D.DOC_ID = L.DOC_ID
            WHERE L.ELIMINADA = 0 AND $v AND $sinal AND D.DATA >= :de AND D.DATA <= :ate
            ORDER BY D.DATA DESC, D.DOC_ID DESC, L.LINHA ASC", $p) as $a) {
            $artigos[] = naval_vendas_artigo($a, $promotores);
        }
    }
    return ['vendas' => $vendas, 'artigos' => $artigos];
}

/**
 * Resumo de um dia para a conferência (função pura, testada): por rota — documentos, total com e sem IVA, pagamentos
 * por meio, linhas, quantidade e total bruto das linhas. Valores arredondados a 2 casas.
 */
function naval_fontes_resumo_dia(array $vendas, array $artigos): array
{
    $r = [];
    $z = ['documentos' => 0, 'total' => 0.0, 'sem_iva' => 0.0, 'linhas' => 0, 'quantidade' => 0.0, 'bruto' => 0.0, 'pagamentos' => []];
    foreach ($vendas as $v) {
        $k = (string) $v['rota'];
        $r[$k] = $r[$k] ?? $z;
        $r[$k]['documentos']++;
        $r[$k]['total'] += (float) $v['total'];
        $r[$k]['sem_iva'] += (float) ($v['total_sem_iva'] ?? 0);
        foreach ((array) ($v['pagamentos'] ?? []) as $p) {
            $m = (string) ($p['codigo'] ?? '?');
            $r[$k]['pagamentos'][$m] = ($r[$k]['pagamentos'][$m] ?? 0) + (float) $p['valor'];
        }
    }
    foreach ($artigos as $a) {
        $k = (string) $a['rota'];
        $r[$k] = $r[$k] ?? $z;
        $r[$k]['linhas']++;
        $r[$k]['quantidade'] += (float) $a['quantidade'];
        $r[$k]['bruto'] += (float) ($a['gross_total'] ?? 0);
    }
    foreach ($r as $k => $x) {
        foreach (['total', 'sem_iva', 'quantidade', 'bruto'] as $c) $r[$k][$c] = round($x[$c], 2);
        foreach ($x['pagamentos'] as $m => $val) $r[$k]['pagamentos'][$m] = round($val, 2);
        ksort($r[$k]['pagamentos']);
    }
    ksort($r);
    return $r;
}

/** Diferenças entre dois resumos (Pinguim BO × OLAP): [{rota, medida, pinguim, olap}] (função pura, testada). */
function naval_fontes_diferencas(array $pinguim, array $olap): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($pinguim), array_keys($olap))) as $rota) {
        $a = $pinguim[$rota] ?? null;
        $b = $olap[$rota] ?? null;
        foreach (['documentos', 'total', 'sem_iva', 'linhas', 'quantidade', 'bruto'] as $m) {
            $x = $a[$m] ?? 0;
            $y = $b[$m] ?? 0;
            if (abs((float) $x - (float) $y) >= 0.005) $out[] = ['rota' => (string) $rota, 'medida' => $m, 'pinguim' => $x, 'olap' => $y];
        }
        $meios = array_unique(array_merge(array_keys((array) ($a['pagamentos'] ?? [])), array_keys((array) ($b['pagamentos'] ?? []))));
        sort($meios);
        foreach ($meios as $mm) {
            $x = $a['pagamentos'][$mm] ?? 0;
            $y = $b['pagamentos'][$mm] ?? 0;
            if (abs((float) $x - (float) $y) >= 0.005) $out[] = ['rota' => (string) $rota, 'medida' => 'pagamento ' . $mm, 'pinguim' => $x, 'olap' => $y];
        }
    }
    return $out;
}

/** Confere um dia: lê as duas fontes, compara e grava o resultado com a hora de fim da carga conferida. */
function naval_fontes_conferir_dia(string $dia, array $carga): array
{
    $prom = naval_promotores();
    $d8 = str_replace('-', '', $dia);
    $pv = naval_vendas_pinguim($d8, $d8, $prom, true);
    if ($pv === null) throw new RuntimeException('o Pinguim BO não respondeu');
    $ov = naval_fontes_vendas_olap($dia, $dia, $prom, true);
    $dif = naval_fontes_diferencas(naval_fontes_resumo_dia($pv['vendas'], $pv['artigos']), naval_fontes_resumo_dia($ov['vendas'], $ov['artigos']));
    $r = ['dia' => $dia, 'em' => date('Y-m-d H:i:s'), 'carga_fim' => (string) $carga['fim'], 'ok' => !$dif, 'diferencas' => array_slice($dif, 0, 200),
        'documentos' => count($pv['vendas']), 'linhas' => count($pv['artigos'])];
    naval_json_gravar(naval_fontes_pasta() . '/conferencia-' . $dia . '.json', $r);
    return $r;
}

/**
 * Conferência diária dos últimos 7 dias (tarefa periódica, uma vez por dia a partir de FONTES_CONFERENCIA_HORA, 06:00,
 * e de novo quando uma recarga muda a hora de fim de um dia). Só corre com o OLAP configurado e FONTE_VENDAS em
 * paralelo ou olap. Diferenças e falhas vão por correio (ACTUALIZACAO_EMAIL). Devolve o resumo ou null.
 */
function naval_fontes_noite(int $agora): ?array
{
    if (naval_fontes_modo('vendas') === 'pinguim' || naval_ensaio()) return null;
    if (date('H:i', $agora) < naval_config('FONTES_CONFERENCIA_HORA', '06:00')) return null;
    $hoje = date('Y-m-d', $agora);
    $de = date('Y-m-d', $agora - NAVAL_FONTES_JANELA * 86400);
    $ate = date('Y-m-d', $agora - 86400);
    return naval_exclusivo('fontes-conferencia', function () use ($de, $ate, $hoje) {
        $marca = naval_fontes_pasta() . '/noite-' . $hoje . '.json';
        $contrato = naval_fontes_contrato(!is_file($marca));
        $res = ['dia' => $hoje, 'contrato' => $contrato, 'dias' => [], 'problemas' => []];
        if (!$contrato['ok']) {
            if (!is_file($marca)) {
                $res['problemas'][] = 'contrato do OLAP incompleto: ' . ($contrato['erro'] ?? implode(', ', array_slice($contrato['faltam'], 0, 10)));
                naval_fontes_avisar($res['problemas']);
                naval_json_gravar($marca, $res);
            }
            return $res;
        }
        $cargas = naval_fontes_cargas($de, $ate);
        foreach (naval_fontes_dias($de, $ate) as $d) {
            $c = $cargas[$d] ?? null;
            $f = naval_fontes_conferencia($d);
            if (!$c) {
                $res['dias'][$d] = 'sem carga';
                if (!is_file($marca)) $res['problemas'][] = $d . ': sem carga concluída no OLAP (rtm.ctl_carga)';
                continue;
            }
            if ($f && (string) ($f['carga_fim'] ?? '') === $c['fim']) {
                $res['dias'][$d] = $f['ok'] ? 'conferido' : 'com diferenças';
                continue;   // já conferido depois desta carga
            }
            try {
                $r = naval_fontes_conferir_dia($d, $c);
                $res['dias'][$d] = $r['ok'] ? 'conferido' : 'com diferenças';
                if (!$r['ok']) $res['problemas'][] = $d . ': ' . count($r['diferencas']) . ' diferenças (a primeira: rota ' . $r['diferencas'][0]['rota'] . ', ' . $r['diferencas'][0]['medida'] . ' Pinguim BO ' . $r['diferencas'][0]['pinguim'] . ' × OLAP ' . $r['diferencas'][0]['olap'] . ')';
            } catch (Throwable $e) {
                $res['dias'][$d] = 'erro';
                $res['problemas'][] = $d . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
        }
        if ($res['problemas']) naval_fontes_avisar($res['problemas']);
        naval_json_gravar($marca, $res);
        return $res;
    });
}

function naval_fontes_avisar(array $linhas): void
{
    try {
        if (!function_exists('naval_act_avisar')) naval_servico('actualizacao');
        naval_act_avisar('conferência OLAP × Pinguim BO', array_merge(['A conferência diária das fontes de dados encontrou:'], $linhas,
            ['Os dias com diferenças continuam a ser lidos do Pinguim BO. Detalhe em Administração › Conformidade.']));
    } catch (Throwable $e) {
        error_log('[fontes] aviso: ' . $e->getMessage());
    }
}

/** Estado das fontes (indicador do portal e página Conformidade). */
function naval_fontes_estado(bool $detalhe = false): array
{
    $modo = naval_fontes_modo('vendas');
    $r = ['vendas' => $modo, 'olap' => naval_fontes_olap_configurado(), 'contrato' => NAVAL_FONTES_CONTRATO];
    if (!$r['olap']) return $r;
    $dj = naval_fontes_disjuntor_estado();
    $r['disjuntor'] = (int) ($dj['aberto_ate'] ?? 0) > time() ? date('Y-m-d H:i:s', (int) $dj['aberto_ate']) : null;
    // último dia conferido (o histórico do OLAP está actualizado até esse dia)
    $ultimo = null;
    $dias = [];
    for ($i = 1; $i <= NAVAL_FONTES_JANELA; $i++) {
        $d = date('Y-m-d', time() - $i * 86400);
        $c = naval_fontes_conferencia($d);
        if ($c && $c['ok'] && !$ultimo) $ultimo = ['dia' => $d, 'carga_fim' => $c['carga_fim']];
        if ($detalhe) $dias[] = ['dia' => $d, 'conferido' => $c ? (bool) $c['ok'] : null, 'em' => $c['em'] ?? null, 'carga_fim' => $c['carga_fim'] ?? null,
            'diferencas' => $c ? array_slice((array) ($c['diferencas'] ?? []), 0, 20) : [], 'documentos' => $c['documentos'] ?? null];
    }
    $r['conferido_ate'] = $ultimo;
    if ($detalhe) {
        $k = naval_fontes_contrato();
        $r['contrato_ok'] = $k['ok'];
        $r['contrato_faltam'] = array_slice((array) $k['faltam'], 0, 30);
        $r['contrato_erro'] = $k['erro'];
        $r['dias'] = $dias;
    }
    return $r;
}
