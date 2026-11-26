<?php
/**
 * Extracto dos movimentos de stock: todas as entradas e saídas de artigos no Pinguim BO (só SELECT), documento a
 * documento, por armazém (armazéns centrais e carrinhas) e artigo, num período (até 366 dias: um ano completo).
 *
 * Efeito no stock de cada linha: quantidade × sinal de stock da linha (DOCMOVDETAIL.STK_SIGNAL) ou, sem ele, do tipo de
 * documento (DOCCONFIG.STOCK_SIGNAL) — a mesma regra da conciliação da carrinha: vendas e saídas negativas, cargas,
 * compras e devoluções ao armazém positivas. Os inventários (INVENTORYHEADER com SET_QNT = 1) entram com o acerto
 * (contado − teórico). Documentos anulados ou apagados ficam de fora.
 *
 * Natureza de cada movimento (naval_mov_classificar): só as vendas FR e FA são saídas efectivas e a NC (nota de
 * crédito) abate-as. A saída de armazém (SA) do armazém central que dá origem a uma guia de transporte (GTC) para a
 * carrinha é uma transferência entre armazéns, não uma saída: liga-se à guia do mesmo artigo nesse dia ou no seguinte.
 * A SA do armazém central sem guia fica à parte («saída de armazém sem guia»: em trânsito ou outra saída). Os tipos
 * vêm do .env (MOV_TIPOS_VENDA, MOV_TIPOS_DEVOLUCAO, MOV_TIPOS_GUIA, MOV_TIPOS_SAIDA_ARMAZEM).
 *
 * Com um só armazém e um só artigo, o extracto traz o saldo: o stock no fim do dia anterior ao período (dbo.GET_STOCK)
 * e o saldo depois de cada movimento, como um extracto bancário.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MOV_DIAS_MAX = 366;
const NAVAL_MOV_LINHAS_MAX = 20000;
/** Tipos de documento por omissão: vendas (saídas efectivas), devoluções de clientes, guias e saídas de armazém. */
const NAVAL_MOV_TIPOS_OMISSAO = ['venda' => ['FR', 'FA', 'FT'], 'devolucao' => ['NC'], 'guia' => ['GTC'], 'saida_armazem' => ['SA']];
/** Naturezas, pela ordem em que aparecem. */
const NAVAL_MOV_NATUREZAS = ['venda', 'devolucao', 'transf_enviada', 'transf_recebida', 'entrada', 'sem_guia', 'outra_saida', 'acerto'];

/** Tipos de documento de cada natureza (.env, com os de omissão); só códigos simples, para irem no SQL como texto. */
function naval_mov_tipos(): array
{
    $r = [];
    foreach (['venda' => 'MOV_TIPOS_VENDA', 'devolucao' => 'MOV_TIPOS_DEVOLUCAO', 'guia' => 'MOV_TIPOS_GUIA', 'saida_armazem' => 'MOV_TIPOS_SAIDA_ARMAZEM'] as $k => $chave) {
        $v = (string) naval_config($chave, implode(',', NAVAL_MOV_TIPOS_OMISSAO[$k]));
        $r[$k] = array_values(array_filter(array_map(function ($x) { return strtoupper(trim($x)); }, explode(',', $v)), function ($x) {
            return (bool) preg_match('/^[A-Z0-9]{1,10}$/', $x);
        }));
    }
    return $r;
}

/**
 * Função pura (testada): a natureza de cada linha (NATUREZA). $linhas: [{HORA, DOC, TIPO, CODIGO, EFEITO, CARRINHA?}];
 * $guias: [código => [dia => quantidade]] que as guias (GTC) puseram nas carrinhas. A SA do armazém central é
 * transferência até à quantidade das guias do mesmo artigo nesse dia ou no seguinte (pela ordem das horas); o resto
 * fica «sem_guia». A linha dividida em duas (transferência e sem guia) mantém o documento.
 */
function naval_mov_classificar(array $linhas, array $guias = [], array $tipos = NAVAL_MOV_TIPOS_OMISSAO): array
{
    usort($linhas, function ($a, $b) { return [(string) $a['HORA'], (string) $a['DOC']] <=> [(string) $b['HORA'], (string) $b['DOC']]; });
    $out = [];
    foreach ($linhas as $l) {
        $t = strtoupper(trim((string) $l['TIPO']));
        $q = (float) $l['EFEITO'];
        if ($t === 'INV') $n = 'acerto';
        elseif (in_array($t, $tipos['venda'], true)) $n = 'venda';
        elseif (in_array($t, $tipos['devolucao'], true)) $n = 'devolucao';
        elseif (in_array($t, $tipos['guia'], true)) $n = $q > 0 ? 'transf_recebida' : 'transf_enviada';
        elseif (in_array($t, $tipos['saida_armazem'], true) && $q < 0 && empty($l['CARRINHA'])) {
            $c = trim((string) $l['CODIGO']);
            $dia = substr((string) $l['HORA'], 0, 10);
            $falta = round(-$q, 3);
            $m = 0.0;
            foreach ([$dia, date('Y-m-d', (int) strtotime($dia . ' 12:00') + 86400)] as $d) {
                $u = min($falta - $m, (float) ($guias[$c][$d] ?? 0));
                if ($u <= 0) continue;
                $guias[$c][$d] = round($guias[$c][$d] - $u, 3);
                $m = round($m + $u, 3);
            }
            if ($m >= 0.0005) $out[] = ['EFEITO' => -$m, 'NATUREZA' => 'transf_enviada'] + $l;
            if ($falta - $m >= 0.0005) $out[] = ['EFEITO' => -round($falta - $m, 3), 'NATUREZA' => 'sem_guia'] + $l;
            continue;
        } else $n = $q > 0 ? 'entrada' : 'outra_saida';
        $out[] = ['NATUREZA' => $n] + $l;
    }
    return $out;
}

/** Totais que interessam à gestão a partir do líquido de cada natureza: saídas efectivas = vendas FR e FA − NC. */
function naval_mov_derivados(array $nat): array
{
    $g = function (string $k) use ($nat) { return (float) ($nat[$k] ?? 0); };
    return ['saidas_efectivas' => round(-($g('venda') + $g('devolucao')), 3), 'vendas' => round(-$g('venda'), 3), 'devolucoes' => round($g('devolucao'), 3),
        'transf_enviadas' => round(-$g('transf_enviada'), 3), 'transf_recebidas' => round($g('transf_recebida'), 3), 'entradas_externas' => round($g('entrada'), 3),
        'sem_guia' => round(-$g('sem_guia'), 3), 'outras_saidas' => round(-$g('outra_saida'), 3), 'acertos' => round($g('acerto'), 3)];
}

/** Lista por natureza (para o quadro «Totais por natureza»): [{natureza, entradas, saidas, liquido, documentos}]. */
function naval_mov_lista_naturezas(array $grupos): array
{
    $l = [];
    foreach (NAVAL_MOV_NATUREZAS as $n) {
        if (!isset($grupos[$n])) continue;
        $a = $grupos[$n];
        $l[] = ['natureza' => $n, 'entradas' => round($a['entradas'], 3), 'saidas' => round($a['saidas'], 3), 'liquido' => round($a['entradas'] - $a['saidas'], 3),
            'documentos' => is_array($a['docs']) ? count($a['docs']) : (int) $a['docs']];
    }
    return $l;
}

/** Período válido (AAAA-MM-DD), até NAVAL_MOV_DIAS_MAX dias. */
function naval_mov_periodo(string $de, string $ate): array
{
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Período inválido.', 400);
    if ((strtotime($ate . ' 12:00') - strtotime($de . ' 12:00')) / 86400 + 1 > NAVAL_MOV_DIAS_MAX) {
        throw new NavalErro('Escolha um período até ' . NAVAL_MOV_DIAS_MAX . ' dias.', 400);
    }
    return [$de, $ate];
}

/**
 * Função pura (testada). $linhas: [{HORA, DOC, TIPO, TIPO_NOME, ARM_COD, ARM, CODIGO, ARTIGO, EFEITO}];
 * $saldoInicial: stock no início do período (só com um armazém e um artigo) ou null.
 * Devolve {linhas: [{em, doc, tipo, tipo_nome, armazem_cod, armazem, codigo, artigo, entrada, saida, saldo?}],
 * resumo: {movimentos, documentos, entradas, saidas, liquido}, por_artigo: [{codigo, artigo, entradas, saidas, liquido}],
 * por_tipo: [{tipo, tipo_nome, entradas, saidas, liquido, documentos}], por_armazem: [{armazem_cod, armazem, …}],
 * saldo_inicial, saldo_final}.
 */
function naval_mov_montar(array $linhas, ?float $saldoInicial = null): array
{
    if ($linhas && !array_key_exists('NATUREZA', $linhas[0])) $linhas = naval_mov_classificar($linhas);
    usort($linhas, function ($a, $b) { return [(string) $a['HORA'], (string) $a['DOC']] <=> [(string) $b['HORA'], (string) $b['DOC']]; });
    $out = [];
    $saldo = $saldoInicial;
    $porArt = $porTipo = $porArm = $porNat = [];
    $natTot = [];
    $docs = [];
    $ent = $sai = 0.0;
    foreach ($linhas as $l) {
        $q = round((float) $l['EFEITO'], 3);
        if (abs($q) < 0.0005) continue;
        $c = trim((string) $l['CODIGO']);
        $x = ['em' => substr((string) $l['HORA'], 0, 19), 'doc' => trim((string) $l['DOC']), 'tipo' => trim((string) $l['TIPO']),
            'tipo_nome' => trim((string) ($l['TIPO_NOME'] ?? '')), 'armazem_cod' => trim((string) ($l['ARM_COD'] ?? '')), 'armazem' => trim((string) ($l['ARM'] ?? '')),
            'codigo' => $c, 'artigo' => trim((string) ($l['ARTIGO'] ?? '')) ?: $c, 'entrada' => $q > 0 ? $q : 0.0, 'saida' => $q < 0 ? -$q : 0.0,
            'natureza' => (string) ($l['NATUREZA'] ?? ($q > 0 ? 'entrada' : 'outra_saida'))];
        if ($saldo !== null) {
            $saldo = round($saldo + $q, 3);
            $x['saldo'] = $saldo;
        }
        $out[] = $x;
        $docs[$x['doc']] = true;
        $ent += $x['entrada'];
        $sai += $x['saida'];
        if (!isset($porArt[$c])) $porArt[$c] = ['codigo' => $c, 'artigo' => $x['artigo'], 'entradas' => 0.0, 'saidas' => 0.0, 'nat' => []];
        $porArt[$c]['entradas'] += $x['entrada'];
        $porArt[$c]['saidas'] += $x['saida'];
        $porArt[$c]['nat'][$x['natureza']] = ($porArt[$c]['nat'][$x['natureza']] ?? 0) + $q;
        $natTot[$x['natureza']] = ($natTot[$x['natureza']] ?? 0) + $q;
        $porNat = naval_mov_somar($porNat, $x['natureza'], [], $x);
        // totais por tipo de documento e por armazém (com o número de documentos de cada um)
        $porTipo = naval_mov_somar($porTipo, $x['tipo'], ['tipo' => $x['tipo'], 'tipo_nome' => $x['tipo_nome']], $x);
        $porArm = naval_mov_somar($porArm, $x['armazem_cod'], ['armazem_cod' => $x['armazem_cod'], 'armazem' => $x['armazem']], $x);
    }
    $fechar = function (array $grupo) {
        $l = array_values(array_map(function ($a) {
            $n = count($a['docs']);
            $nat = $a['nat'];
            unset($a['docs'], $a['nat']);
            return $a + ['documentos' => $n, 'liquido' => round($a['entradas'] - $a['saidas'], 3)] + naval_mov_derivados($nat);
        }, $grupo));
        foreach ($l as &$x) [$x['entradas'], $x['saidas']] = [round($x['entradas'], 3), round($x['saidas'], 3)];
        unset($x);
        usort($l, function ($a, $b) { return ($b['entradas'] + $b['saidas']) <=> ($a['entradas'] + $a['saidas']); });
        return $l;
    };
    $pa = array_values(array_map(function ($a) {
        return ['codigo' => $a['codigo'], 'artigo' => $a['artigo'], 'entradas' => round($a['entradas'], 3), 'saidas' => round($a['saidas'], 3),
            'liquido' => round($a['entradas'] - $a['saidas'], 3)] + naval_mov_derivados($a['nat']);
    }, $porArt));
    usort($pa, function ($a, $b) { return ($b['entradas'] + $b['saidas']) <=> ($a['entradas'] + $a['saidas']); });
    return ['linhas' => $out, 'resumo' => ['movimentos' => count($out), 'documentos' => count($docs), 'entradas' => round($ent, 3), 'saidas' => round($sai, 3),
        'liquido' => round($ent - $sai, 3)] + naval_mov_derivados($natTot), 'por_artigo' => $pa, 'por_tipo' => $fechar($porTipo), 'por_armazem' => $fechar($porArm),
        'por_natureza' => naval_mov_lista_naturezas($porNat),
        'saldo_inicial' => $saldoInicial, 'saldo_final' => $saldo];
}

/** Totais de uma consulta de linhas (HORA, DOC, TIPO, ARM_COD, CODIGO, EFEITO…), agregados no SQL Server por tipo, armazém, artigo e geral. */
function naval_mov_sql_totais(string $sqlLinhas, array $tipos = NAVAL_MOV_TIPOS_OMISSAO): string
{
    $nat = naval_mov_sql_natureza($tipos);
    return "SELECT CASE WHEN GROUPING(TIPO) = 0 THEN 'tipo' WHEN GROUPING(ARM_COD) = 0 AND GROUPING(NAT) = 0 THEN 'armnat' WHEN GROUPING(ARM_COD) = 0 THEN 'arm'
                        WHEN GROUPING(CODIGO) = 0 AND GROUPING(NAT) = 0 THEN 'artnat' WHEN GROUPING(CODIGO) = 0 THEN 'art' WHEN GROUPING(NAT) = 0 THEN 'nat' ELSE 'tot' END AS NIVEL,
                   COALESCE(TIPO, ARM_COD, CODIGO, '') AS CHAVE, NAT,
                   CASE WHEN GROUPING(TIPO) = 0 THEN MAX(TIPO_NOME) WHEN GROUPING(ARM_COD) = 0 THEN MAX(ARM) ELSE MAX(ARTIGO) END AS NOME,
                   CAST(SUM(CASE WHEN EFEITO > 0 THEN EFEITO ELSE 0 END) AS FLOAT) AS ENT, CAST(SUM(CASE WHEN EFEITO < 0 THEN -EFEITO ELSE 0 END) AS FLOAT) AS SAI,
                   COUNT(*) AS MOV, COUNT(DISTINCT DOC) AS DOCS
            FROM (SELECT L0.*, $nat AS NAT FROM ($sqlLinhas) L0 WHERE ABS(L0.EFEITO) >= 0.0005) L
            GROUP BY GROUPING SETS ((TIPO), (ARM_COD), (CODIGO), (), (NAT), (ARM_COD, NAT), (CODIGO, NAT))";
}

/** A natureza no SQL (mesma regra de naval_mov_classificar); a SA de saída fica «sa», para se ligar às guias em PHP. */
function naval_mov_sql_natureza(array $tipos): string
{
    $em = function (array $l) {
        $l = array_values(array_filter($l, function ($x) { return (bool) preg_match('/^[A-Z0-9]{1,10}$/', (string) $x); }));
        return $l ? "TIPO IN ('" . implode("', '", $l) . "')" : '1 = 0';
    };
    return "CASE WHEN TIPO = 'INV' THEN 'acerto' WHEN " . $em($tipos['venda']) . " THEN 'venda' WHEN " . $em($tipos['devolucao']) . " THEN 'devolucao'
                 WHEN " . $em($tipos['guia']) . " THEN CASE WHEN EFEITO > 0 THEN 'transf_recebida' ELSE 'transf_enviada' END
                 WHEN " . $em($tipos['saida_armazem']) . " AND EFEITO < 0 THEN 'sa' WHEN EFEITO > 0 THEN 'entrada' ELSE 'outra_saida' END";
}

/**
 * Função pura (testada): junta os totais agregados no SQL (dos documentos e dos inventários, que nunca partilham
 * documentos) no formato de naval_mov_montar: resumo, por_tipo, por_armazem e por_artigo.
 */
function naval_mov_totais(array $linhas, array $saidasArmazem = []): array
{
    $g = ['tipo' => [], 'arm' => [], 'art' => [], 'tot' => []];
    // líquido de cada natureza: geral, por armazém e por artigo; a SA vem já classificada (ligada ou não às guias)
    $nat = ['nat' => [], 'armnat' => [], 'artnat' => []];
    $natDocs = [];
    $saDocs = [];
    $somarNat = function (string $nivel, string $k, string $n, float $q) use (&$nat) {
        $nat[$nivel][$k][$n] = ($nat[$nivel][$k][$n] ?? 0) + $q;
    };
    foreach ($saidasArmazem as $s) {
        $q = (float) $s['EFEITO'];
        $n = (string) $s['NATUREZA'];
        $somarNat('nat', '', $n, $q);
        $somarNat('armnat', trim((string) $s['ARM_COD']), $n, $q);
        $somarNat('artnat', trim((string) $s['CODIGO']), $n, $q);
        $saDocs[$n][trim((string) $s['DOC'])] = true;
        $natDocs[$n]['entradas'] = ($natDocs[$n]['entradas'] ?? 0) + max(0, $q);
        $natDocs[$n]['saidas'] = ($natDocs[$n]['saidas'] ?? 0) + max(0, -$q);
    }
    foreach ($linhas as $x) {
        $n = (string) $x['NIVEL'];
        if (isset($nat[$n])) {
            $nn = (string) ($x['NAT'] ?? '');
            if ($nn === '' || $nn === 'sa') continue;
            $somarNat($n, $n === 'nat' ? '' : trim((string) $x['CHAVE']), $nn, (float) $x['ENT'] - (float) $x['SAI']);
            if ($n === 'nat') {
                $natDocs[$nn]['docs'] = ($natDocs[$nn]['docs'] ?? 0) + (int) $x['DOCS'];
                $natDocs[$nn]['entradas'] = ($natDocs[$nn]['entradas'] ?? 0) + (float) $x['ENT'];
                $natDocs[$nn]['saidas'] = ($natDocs[$nn]['saidas'] ?? 0) + (float) $x['SAI'];
            }
            continue;
        }
        if (!isset($g[$n])) continue;
        $k = trim((string) $x['CHAVE']);
        $a = $g[$n][$k] ?? ['chave' => $k, 'nome' => '', 'entradas' => 0.0, 'saidas' => 0.0, 'movimentos' => 0, 'documentos' => 0];
        if ($a['nome'] === '') $a['nome'] = trim((string) ($x['NOME'] ?? ''));
        $a['entradas'] += (float) $x['ENT'];
        $a['saidas'] += (float) $x['SAI'];
        $a['movimentos'] += (int) $x['MOV'];
        $a['documentos'] += (int) $x['DOCS'];
        $g[$n][$k] = $a;
    }
    foreach ($saDocs as $n => $docs) $natDocs[$n]['docs'] = ($natDocs[$n]['docs'] ?? 0) + count($docs);
    $lista = function (array $grupo, string $cod, string $nome, bool $comDocs, ?array $naturezas = null) {
        $l = [];
        foreach ($grupo as $a) {
            $x = [$cod => $a['chave'], $nome => $a['nome'] !== '' ? $a['nome'] : $a['chave'], 'entradas' => round($a['entradas'], 3), 'saidas' => round($a['saidas'], 3),
                'liquido' => round($a['entradas'] - $a['saidas'], 3)];
            if ($comDocs) $x['documentos'] = $a['documentos'];
            if ($naturezas !== null) $x += naval_mov_derivados($naturezas[$a['chave']] ?? []);
            $l[] = $x;
        }
        usort($l, function ($a, $b) { return ($b['entradas'] + $b['saidas']) <=> ($a['entradas'] + $a['saidas']); });
        return $l;
    };
    $t = ['entradas' => 0.0, 'saidas' => 0.0, 'movimentos' => 0, 'documentos' => 0];
    foreach ($g['tot'] as $a) {
        $t['entradas'] += $a['entradas'];
        $t['saidas'] += $a['saidas'];
        $t['movimentos'] += $a['movimentos'];
        $t['documentos'] += $a['documentos'];
    }
    return ['resumo' => ['movimentos' => $t['movimentos'], 'documentos' => $t['documentos'], 'entradas' => round($t['entradas'], 3), 'saidas' => round($t['saidas'], 3),
        'liquido' => round($t['entradas'] - $t['saidas'], 3)] + naval_mov_derivados($nat['nat'][''] ?? []), 'por_tipo' => $lista($g['tipo'], 'tipo', 'tipo_nome', true),
        'por_armazem' => $lista($g['arm'], 'armazem_cod', 'armazem', true, $nat['armnat']), 'por_artigo' => $lista($g['art'], 'codigo', 'artigo', false, $nat['artnat']),
        'por_natureza' => naval_mov_lista_naturezas(array_map(function ($a) { return $a + ['entradas' => 0.0, 'saidas' => 0.0, 'docs' => 0]; }, $natDocs))];
}

/** Soma um movimento a um grupo de totais (por tipo ou por armazém), com os documentos distintos. */
function naval_mov_somar(array $grupo, string $chave, array $base, array $x): array
{
    $g = $grupo[$chave] ?? $base + ['entradas' => 0.0, 'saidas' => 0.0, 'docs' => [], 'nat' => []];
    $g['entradas'] += $x['entrada'];
    $g['saidas'] += $x['saida'];
    $g['docs'][$x['doc']] = true;
    $g['nat'][$x['natureza']] = ($g['nat'][$x['natureza']] ?? 0) + $x['entrada'] - $x['saida'];
    $grupo[$chave] = $g;
    return $grupo;
}

/**
 * O que as guias (tipos $guia, ex.: GTC) puseram nas carrinhas, por artigo e dia, do início do período ao dia a seguir
 * ao fim: [código => [dia => quantidade]]. Só as entradas em armazéns de carrinhas.
 */
function naval_mov_guias(string $fArt, array $p, array $guia, array $conf): array
{
    if (!$guia) return [];
    $em = "('" . implode("', '", $guia) . "')";
    $g = [];
    foreach (naval_bd_consultar("
        SELECT LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, CONVERT(VARCHAR(10), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS DIA,
               LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM,
               CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS Q
        FROM DOCMOVDETAIL DMD
        INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
        LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
        WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND LTRIM(RTRIM(DC.CODE)) IN $em
          AND DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) > 0
          AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 2, CAST(:ate AS DATE))$fArt
        GROUP BY LTRIM(RTRIM(DMD.PRODUCT_CODE)), CONVERT(VARCHAR(10), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120), WE.CODE", $p) as $x) {
        if (naval_stock_rota_armazem((string) $x['ARM_COD'], (string) $x['ARM'], $conf)[0] === '') continue;
        $g[$x['CODIGO']][$x['DIA']] = round(($g[$x['CODIGO']][$x['DIA']] ?? 0) + (float) $x['Q'], 3);
    }
    return $g;
}

/** Lê os movimentos do Pinguim BO e monta o extracto. $armazem: código do armazém ('' = todos); $artigo: código ou texto. */
function naval_mov_extracto(string $de, string $ate, string $armazem = '', string $artigo = '', string $tipo = ''): array
{
    [$de, $ate] = naval_mov_periodo($de, $ate);
    naval_servico('bd');
    if (!naval_bd_configurada()) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    $armazem = trim($armazem);
    $artigo = trim($artigo);
    $tipo = strtoupper(trim($tipo));
    if ($armazem !== '' && !preg_match('/^[A-Za-z0-9._-]{1,30}$/', $armazem)) throw new NavalErro('Armazém inválido.', 400);
    if ($tipo !== '' && !preg_match('/^[A-Z0-9]{1,10}$/', $tipo)) throw new NavalErro('Tipo de documento inválido.', 400);
    $artigo = mb_substr($artigo, 0, 60);
    $p = [':de' => $de, ':ate' => $ate];
    $f = '';
    $fi = '';
    if ($armazem !== '') {
        $f .= ' AND LTRIM(RTRIM(WE.CODE)) = :arm';
        $fi .= ' AND LTRIM(RTRIM(E.CODE)) = :arm';
        $p[':arm'] = $armazem;
    }
    if ($artigo !== '') {
        $f .= ' AND (LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art OR P.DESCRIPTION LIKE :artl)';
        $fi .= ' AND (LTRIM(RTRIM(P.CODE)) = :art OR P.DESCRIPTION LIKE :artl)';
        $p[':art'] = $artigo;
        $p[':artl'] = '%' . str_replace(['%', '_'], ['[%]', '[_]'], $artigo) . '%';
    }
    $soDocs = $tipo !== '' && $tipo !== 'INV';
    if ($soDocs) {
        $f .= ' AND LTRIM(RTRIM(DC.CODE)) = :tipo';
        $p[':tipo'] = $tipo;
    }
    // as mesmas linhas servem o extracto (as primeiras NAVAL_MOV_LINHAS_MAX) e os totais completos (agregados no SQL)
    $sqlDocs = "
            SELECT CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   LTRIM(RTRIM(DC.CODE)) AS TIPO, MAX(DC.DESCRIPTION) AS TIPO_NOME, LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM_COD, COALESCE(MAX(WE.DESCRIPTION), '') AS ARM,
                   LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO, MAX(COALESCE(P.DESCRIPTION, DMD.DESCRIPTION)) AS ARTIGO,
                   CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0
              AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))$f
            GROUP BY COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, WE.CODE, DMD.PRODUCT_CODE";
    $pi = $p;
    unset($pi[':tipo']);
    $sqlInv = "
            SELECT CONVERT(VARCHAR(19), IH.INVENTORY_DATE, 120) AS HORA, LTRIM(RTRIM(COALESCE(IH.DOC_PREFIX, 'INV'))) + '/' + CAST(IH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   'INV' AS TIPO, 'Inventário (acerto: contado − teórico)' AS TIPO_NOME, LTRIM(RTRIM(E.CODE)) AS ARM_COD, MAX(E.DESCRIPTION) AS ARM,
                   LTRIM(RTRIM(P.CODE)) AS CODIGO, MAX(P.DESCRIPTION) AS ARTIGO, CAST(SUM(ID.PHYSICAL_QNT - ID.THEORETICAL_QNT) AS FLOAT) AS EFEITO
            FROM INVENTORYHEADER IH
            INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID AND ID.DELETED = 0
            INNER JOIN ENTITY E ON E.ID = IH.WAREHOUSE_ID
            INNER JOIN PRODUCT P ON P.ID = ID.PRODUCT_ID
            WHERE IH.DELETED = 0 AND IH.SET_QNT = 1 AND IH.INVENTORY_DATE >= :de AND IH.INVENTORY_DATE < DATEADD(day, 1, CAST(:ate AS DATE))$fi
            GROUP BY IH.INVENTORY_DATE, IH.DOC_PREFIX, IH.DOC_NUMBER, E.CODE, P.CODE";
    $linhas = [];
    $cortado = false;
    if ($tipo !== 'INV') {
        $linhas = naval_bd_consultar('SELECT TOP ' . NAVAL_MOV_LINHAS_MAX . ' * FROM (' . $sqlDocs . ') X ORDER BY HORA, DOC', $p);
        $cortado = count($linhas) >= NAVAL_MOV_LINHAS_MAX;
    }
    if (!$soDocs) foreach (naval_bd_consultar($sqlInv, $pi) as $r) $linhas[] = $r;
    // natureza: a SA do armazém central liga-se às guias (GTC) das carrinhas do mesmo artigo nesse dia ou no seguinte
    $tipos = naval_mov_tipos();
    naval_servico('stock');
    $conf = naval_stock_carrinhas_confirmadas();
    $marcar = function (array $ls) use ($conf) {
        $e = [];
        foreach ($ls as &$l) {
            $k = trim((string) ($l['ARM_COD'] ?? ''));
            if (!isset($e[$k])) $e[$k] = naval_stock_rota_armazem($k, trim((string) ($l['ARM'] ?? '')), $conf)[0] !== '';
            $l['CARRINHA'] = $e[$k];
        }
        unset($l);
        return $ls;
    };
    $fArt = $artigo !== '' ? ' AND (LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art OR P.DESCRIPTION LIKE :artl)' : '';
    $pArt = [':de' => $de, ':ate' => $ate] + ($artigo !== '' ? [':art' => $p[':art'], ':artl' => $p[':artl']] : []);
    $classificar = function (array $ls) use ($marcar, $tipos, $fArt, $pArt, $conf, $de, $ate) {
        $ls = $marcar($ls);
        $temSa = false;
        foreach ($ls as $l) if (!$l['CARRINHA'] && (float) $l['EFEITO'] < 0 && in_array(strtoupper(trim((string) $l['TIPO'])), $tipos['saida_armazem'], true)) $temSa = true;
        return naval_mov_classificar($ls, $temSa ? naval_mov_guias($fArt, $pArt, $tipos['guia'], $conf) : [], $tipos);
    };
    $linhas = $classificar($linhas);
    // saldo: só com um armazém e um artigo (código exacto) — o stock no fim do dia anterior ao período
    $saldo = null;
    if ($armazem !== '' && $artigo !== '' && $tipo === '') {
        naval_servico('stock');
        $antes = date('Y-m-d', strtotime($de . ' 12:00') - 86400);
        $st = naval_stock($antes, $armazem);
        $saldo = 0.0;
        foreach ((array) ($st['armazens'] ?? []) as $a) {
            if ((string) ($a['cod_armazem'] ?? '') !== $armazem) continue;
            foreach ((array) ($a['itens'] ?? []) as $i) if (trim((string) ($i['codigo'] ?? '')) === $artigo) $saldo += (float) ($i['quantidade'] ?? 0);
        }
        // carrinhas: o armazém vem em cada linha
        foreach ((array) ($st['carrinhas'] ?? []) as $a) {
            foreach ((array) ($a['itens'] ?? []) as $i) {
                if ((string) ($i['cod_armazem'] ?? '') === $armazem && trim((string) ($i['codigo'] ?? '')) === $artigo) $saldo += (float) ($i['quantidade'] ?? 0);
            }
        }
    }
    $r = naval_mov_montar($linhas, $saldo);
    // muitos movimentos (ex.: um ano de todos os armazéns): o extracto mostra os primeiros, mas os totais são de todos
    if ($cortado) {
        $tot = [];
        foreach ($soDocs ? [[$sqlDocs, $p]] : [[$sqlDocs, $p], [$sqlInv, $pi]] as [$sql, $par]) {
            foreach (naval_bd_consultar(naval_mov_sql_totais($sql, $tipos), $par) as $x) $tot[] = $x;
        }
        // as saídas de armazém de todo o período (poucas), ligadas às guias como no extracto
        $sa = [];
        if ($tipos['saida_armazem']) {
            $sa = naval_bd_consultar("SELECT * FROM ($sqlDocs) X WHERE EFEITO < 0 AND TIPO IN ('" . implode("', '", $tipos['saida_armazem']) . "')", $p);
            $sa = array_values(array_filter($classificar($sa), function ($l) { return in_array($l['NATUREZA'], ['transf_enviada', 'sem_guia', 'outra_saida'], true); }));
        }
        $r = array_merge($r, naval_mov_totais($tot, $sa));
    }
    // armazéns com movimentos no período (para o filtro)
    $arms = naval_cache('mov-armazens|' . $de . '|' . $ate, 600, function () use ($de, $ate) {
        return array_map(function ($x) { return ['codigo' => trim((string) $x['COD']), 'nome' => trim((string) $x['NOME'])]; }, naval_bd_consultar("
            SELECT DISTINCT LTRIM(RTRIM(WE.CODE)) AS COD, WE.DESCRIPTION AS NOME
            FROM DOCMOVDETAIL DMD
            INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
            INNER JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
            WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < DATEADD(day, 1, CAST(:ate AS DATE))
            ORDER BY NOME", [':de' => $de, ':ate' => $ate]));
    });
    return $r + ['de' => $de, 'ate' => $ate, 'armazem' => $armazem, 'artigo' => $artigo, 'tipo' => $tipo, 'armazens' => $arms,
        'limitado' => $cortado];
}
