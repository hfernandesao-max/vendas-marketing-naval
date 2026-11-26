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
 * Natureza de cada movimento (naval_mov_classificar), confirmada no Pinguim BO com o artigo 10000332:
 *   - vendas FR e FA (e FT) são vendas no Pinguim BO; a NC (nota de crédito) abate-as;
 *   - uma transferência entre armazéns são dois documentos gravados ao mesmo tempo (a 1 ou 2 segundos) e com a mesma
 *     quantidade: a saída (SA, ou DA da carrinha) num armazém e a entrada (GTC, ou EA) noutro — do armazém central
 *     para a carrinha, entre carrinhas ou da carrinha de volta ao armazém central (DA + EA). O Pinguim BO não guarda a
 *     ligação (DOCMOVDETAIL.PARENT_ID vazio), por isso o par faz-se pela hora e pela quantidade (naval_mov_emparelhar);
 *   - a SA sem entrada noutro armazém é uma venda feita no SAP com stock do Pinguim BO («venda SAP»): saída efectiva;
 *   - a EA sem saída noutro armazém é uma compra (entrada externa); acertos de inventário à parte.
 * Os tipos vêm do .env (MOV_TIPOS_VENDA, MOV_TIPOS_DEVOLUCAO, MOV_TIPOS_GUIA, MOV_TIPOS_SAIDA_ARMAZEM,
 * MOV_TIPOS_DEVOLUCAO_ARMAZEM, MOV_TIPOS_ENTRADA_ARMAZEM).
 *
 * Com um só armazém e um só artigo, o extracto traz o saldo: o stock no fim do dia anterior ao período (dbo.GET_STOCK)
 * e o saldo depois de cada movimento, como um extracto bancário. Com um artigo (código exacto), traz o balanço de cada
 * armazém (inicial + entradas − saídas = final) ao lado do stock do próprio Pinguim BO.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_MOV_DIAS_MAX = 366;
const NAVAL_MOV_LINHAS_MAX = 20000;
/** Segundos entre a saída e a entrada de uma transferência (o Pinguim BO grava-as a 1 ou 2 segundos). */
const NAVAL_MOV_PAR_SEGUNDOS = 10;
/** Segundos para o par documento a documento (a guia feita uns minutos depois da SA, com os mesmos artigos). */
const NAVAL_MOV_PAR_DOC_SEGUNDOS = 1800;
/** Tipos de documento por omissão de cada natureza. */
const NAVAL_MOV_TIPOS_OMISSAO = ['venda' => ['FR', 'FA', 'FT'], 'devolucao' => ['NC'], 'guia' => ['GTC'], 'saida_armazem' => ['SA'],
    'devolucao_armazem' => ['DA'], 'entrada_armazem' => ['EA']];
/** Naturezas, pela ordem em que aparecem. */
const NAVAL_MOV_NATUREZAS = ['venda', 'devolucao', 'venda_sap', 'transf_enviada', 'transf_recebida', 'guia_anulada', 'entrada', 'outra_saida', 'acerto'];

/** Tipos de documento de cada natureza (.env, com os de omissão); só códigos simples, para irem no SQL como texto. */
function naval_mov_tipos(): array
{
    $r = [];
    foreach (['venda' => 'MOV_TIPOS_VENDA', 'devolucao' => 'MOV_TIPOS_DEVOLUCAO', 'guia' => 'MOV_TIPOS_GUIA', 'saida_armazem' => 'MOV_TIPOS_SAIDA_ARMAZEM',
        'devolucao_armazem' => 'MOV_TIPOS_DEVOLUCAO_ARMAZEM', 'entrada_armazem' => 'MOV_TIPOS_ENTRADA_ARMAZEM'] as $k => $chave) {
        $v = (string) naval_config($chave, implode(',', NAVAL_MOV_TIPOS_OMISSAO[$k]));
        $r[$k] = array_values(array_filter(array_map(function ($x) { return strtoupper(trim($x)); }, explode(',', $v)), function ($x) {
            return (bool) preg_match('/^[A-Z0-9]{1,10}$/', $x);
        }));
    }
    return $r;
}

/** Tipos que podem ser metade de uma transferência: saídas (SA, DA) e entradas (GTC, EA). */
function naval_mov_tipos_par(array $tipos): array
{
    return array_values(array_unique(array_merge($tipos['saida_armazem'] ?? [], $tipos['devolucao_armazem'] ?? [], $tipos['guia'] ?? [], $tipos['entrada_armazem'] ?? [])));
}

/** Chave de uma linha (documento, armazém, artigo) — a mesma no extracto, nos pares e na rastreabilidade. */
function naval_mov_chave(array $l): string
{
    return trim((string) $l['DOC']) . '|' . trim((string) ($l['ARM_COD'] ?? '')) . '|' . trim((string) ($l['CODIGO'] ?? ''));
}

/**
 * Função pura (testada): as transferências entre armazéns. $linhas: [{HORA, DOC, TIPO, ARM_COD, CODIGO, EFEITO}] das
 * saídas (SA, DA) e entradas (GTC, EA). Uma saída e uma entrada do mesmo artigo, em armazéns diferentes, com a mesma
 * quantidade e gravadas até $seg segundos uma da outra são a mesma transferência (a mais próxima no tempo); a saída
 * repartida por várias entradas (ou o contrário) na mesma janela também. Depois, documento a documento: uma saída e
 * uma entrada ainda sem par, com exactamente os mesmos artigos e quantidades, noutro armazém e até $segDoc segundos
 * (a guia feita uns minutos depois da SA, com os mesmos artigos). Antes de tudo, a ligação exacta do Pinguim BO
 * ($ligacoes, de DOCHEADERSOURCE: [{de, para, anulado}] com os nomes dos documentos): a guia gerada a partir da SA é a
 * transferência; a SA cuja guia foi anulada (e não tem outra) fica «guia_anulada» — saiu do armazém sem entrar noutro.
 * Os documentos com ligação não entram nos pares pela hora. Devolve [chave => ['natureza' => transf_enviada|
 * transf_recebida|guia_anulada, 'com' => [[chave, quantidade], …], 'guia'?]] só das linhas emparelhadas ou anuladas.
 */
function naval_mov_emparelhar(array $linhas, array $tipos = NAVAL_MOV_TIPOS_OMISSAO, int $seg = NAVAL_MOV_PAR_SEGUNDOS, int $segDoc = NAVAL_MOV_PAR_DOC_SEGUNDOS,
    array $ligacoes = []): array
{
    [$r, $ligados] = naval_mov_emparelhar_ligacoes($linhas, $ligacoes);
    if ($ligados) $linhas = array_values(array_filter($linhas, function ($l) use ($ligados) { return !isset($ligados[trim((string) $l['DOC'])]); }));
    // artigo a artigo (as chaves levam o código, não se cruzam): com um ano de todos os artigos não fica lento
    $porArt = [];
    foreach ($linhas as $l) $porArt[trim((string) ($l['CODIGO'] ?? ''))][] = $l;
    foreach ($porArt as $g) $r += naval_mov_emparelhar_artigo($g, $tipos, $seg);
    return naval_mov_emparelhar_documentos($linhas, $r, $tipos, $segDoc);
}

/**
 * Função pura: os pares pela ligação do Pinguim BO (documento gerado a partir de outro). Em cada ligação válida, as
 * linhas de sinais contrários do mesmo artigo, em armazéns diferentes, emparelham-se; a origem só com ligações anuladas
 * fica «guia_anulada». Devolve [pares, documentos ligados (que já não entram nos pares pela hora)].
 */
function naval_mov_emparelhar_ligacoes(array $linhas, array $ligacoes): array
{
    if (!$ligacoes) return [[], []];
    $porDoc = [];
    foreach ($linhas as $l) {
        $q = round((float) $l['EFEITO'], 3);
        if (abs($q) < 0.0005) continue;
        $porDoc[trim((string) $l['DOC'])][] = ['k' => naval_mov_chave($l), 'q' => $q, 'c' => trim((string) ($l['CODIGO'] ?? '')), 'a' => trim((string) ($l['ARM_COD'] ?? ''))];
    }
    $r = $ligados = $validas = $anuladas = [];
    foreach ($ligacoes as $g) {
        $de = trim((string) $g['de']);
        $para = trim((string) $g['para']);
        if (!isset($porDoc[$de])) continue;
        if (!empty($g['anulado'])) {
            $anuladas[$de][] = $para;
            continue;
        }
        $validas[$de] = true;
        if (!isset($porDoc[$para])) continue;
        $ligados[$de] = $ligados[$para] = true;
        foreach ($porDoc[$de] as $x) {
            foreach ($porDoc[$para] as $y) {
                if ($x['c'] !== $y['c'] || $x['a'] === $y['a'] || ($x['q'] < 0) === ($y['q'] < 0) || isset($r[$x['k']]) || isset($r[$y['k']])) continue;
                [$s, $e] = $x['q'] < 0 ? [$x, $y] : [$y, $x];
                $q = round(min(-$s['q'], $e['q']), 3);
                $r[$s['k']] = ['natureza' => 'transf_enviada', 'com' => [[$e['k'], $q]]];
                $r[$e['k']] = ['natureza' => 'transf_recebida', 'com' => [[$s['k'], $q]]];
                break;
            }
        }
    }
    // a SA cuja guia foi anulada, sem outra guia válida: saiu do armazém e não entrou em nenhum
    foreach ($anuladas as $de => $guias) {
        if (isset($validas[$de])) continue;
        $ligados[$de] = true;
        foreach ($porDoc[$de] as $x) if ($x['q'] < 0) $r[$x['k']] = ['natureza' => 'guia_anulada', 'com' => [], 'guia' => implode(', ', array_unique($guias))];
    }
    return [$r, $ligados];
}

/**
 * Função pura: o par documento a documento das saídas e entradas que ficaram sem par linha a linha. A saída e a
 * entrada têm de ter os mesmos artigos com as mesmas quantidades (todas as linhas sem par), num só armazém cada, em
 * armazéns diferentes e até $segDoc segundos; a mais próxima no tempo.
 */
function naval_mov_emparelhar_documentos(array $linhas, array $r, array $tipos, int $segDoc): array
{
    $tSai = array_merge($tipos['saida_armazem'] ?? [], $tipos['devolucao_armazem'] ?? []);
    $tEnt = array_merge($tipos['guia'] ?? [], $tipos['entrada_armazem'] ?? []);
    $docs = [];
    foreach ($linhas as $l) {
        $t = strtoupper(trim((string) $l['TIPO']));
        $q = round((float) $l['EFEITO'], 3);
        $lado = $q < -0.0005 && in_array($t, $tSai, true) ? 's' : ($q > 0.0005 && in_array($t, $tEnt, true) ? 'e' : '');
        if ($lado === '') continue;
        $d = $lado . '|' . trim((string) $l['DOC']);
        $docs[$d] = $docs[$d] ?? ['lado' => $lado, 't' => (int) strtotime(substr((string) $l['HORA'], 0, 19)), 'arm' => [], 'art' => [], 'k' => [], 'livre' => true];
        $k = naval_mov_chave($l);
        if (isset($r[$k])) $docs[$d]['livre'] = false;
        $docs[$d]['arm'][trim((string) ($l['ARM_COD'] ?? ''))] = true;
        $c = trim((string) ($l['CODIGO'] ?? ''));
        $docs[$d]['art'][$c] = round(($docs[$d]['art'][$c] ?? 0) + abs($q), 3);
        $docs[$d]['k'][$c] = $k;
    }
    $livres = array_filter($docs, function ($d) { return $d['livre'] && count($d['arm']) === 1; });
    foreach ($livres as &$d) ksort($d['art']);
    unset($d);
    $sai = array_filter($livres, function ($d) { return $d['lado'] === 's'; });
    $ent = array_filter($livres, function ($d) { return $d['lado'] === 'e'; });
    uasort($sai, function ($a, $b) { return $a['t'] <=> $b['t']; });
    $usada = [];
    foreach ($sai as $s) {
        $melhor = null;
        foreach ($ent as $j => $e) {
            if (isset($usada[$j]) || $e['art'] !== $s['art'] || key($e['arm']) === key($s['arm'])) continue;
            $dt = abs($e['t'] - $s['t']);
            if ($dt <= $segDoc && ($melhor === null || $dt < $melhor[1])) $melhor = [$j, $dt];
        }
        if ($melhor === null) continue;
        $usada[$melhor[0]] = true;
        $e = $ent[$melhor[0]];
        foreach ($s['art'] as $c => $q) {
            $r[$s['k'][$c]] = ['natureza' => 'transf_enviada', 'com' => [[$e['k'][$c], $q]]];
            $r[$e['k'][$c]] = ['natureza' => 'transf_recebida', 'com' => [[$s['k'][$c], $q]]];
        }
    }
    return $r;
}

/** Função pura: o par linha a linha de um só artigo (naval_mov_emparelhar). */
function naval_mov_emparelhar_artigo(array $linhas, array $tipos, int $seg): array
{
    $tSai = array_merge($tipos['saida_armazem'] ?? [], $tipos['devolucao_armazem'] ?? []);
    $tEnt = array_merge($tipos['guia'] ?? [], $tipos['entrada_armazem'] ?? []);
    $sai = $ent = [];
    foreach ($linhas as $l) {
        $t = strtoupper(trim((string) $l['TIPO']));
        $q = round((float) $l['EFEITO'], 3);
        $x = ['k' => naval_mov_chave($l), 't' => (int) strtotime(substr((string) $l['HORA'], 0, 19)), 'q' => abs($q), 'a' => trim((string) ($l['ARM_COD'] ?? '')),
            'c' => trim((string) ($l['CODIGO'] ?? ''))];
        if ($q < -0.0005 && in_array($t, $tSai, true)) $sai[] = $x;
        elseif ($q > 0.0005 && in_array($t, $tEnt, true)) $ent[] = $x;
    }
    $porHora = function ($a, $b) { return [$a['t'], $a['k']] <=> [$b['t'], $b['k']]; };
    usort($sai, $porHora);
    usort($ent, $porHora);
    $r = [];
    $ligar = function (array $s, array $e, float $q) use (&$r) {
        $r[$s['k']]['natureza'] = 'transf_enviada';
        $r[$s['k']]['com'][] = [$e['k'], $q];
        $r[$e['k']]['natureza'] = 'transf_recebida';
        $r[$e['k']]['com'][] = [$s['k'], $q];
    };
    $usadaE = $usadaS = [];
    // 1.º: uma saída para uma entrada, a mesma quantidade, a mais próxima no tempo
    foreach ($sai as $i => $s) {
        $melhor = null;
        foreach ($ent as $j => $e) {
            if (isset($usadaE[$j]) || $e['c'] !== $s['c'] || $e['a'] === $s['a'] || abs($e['q'] - $s['q']) > 0.0005) continue;
            $d = abs($e['t'] - $s['t']);
            if ($d <= $seg && ($melhor === null || $d < $melhor[1])) $melhor = [$j, $d];
        }
        if ($melhor === null) continue;
        $usadaE[$melhor[0]] = $usadaS[$i] = true;
        $ligar($s, $ent[$melhor[0]], $s['q']);
    }
    // 2.º: uma saída repartida por várias entradas (ou várias saídas para uma entrada) na mesma janela, a somar certo
    $repartir = function (array $um, array $muitos, array &$usUm, array &$usMuitos, bool $inverso) use ($seg, $ligar) {
        foreach ($um as $i => $u) {
            if (isset($usUm[$i])) continue;
            $junto = [];
            $soma = 0.0;
            foreach ($muitos as $j => $m) {
                if (isset($usMuitos[$j]) || $m['c'] !== $u['c'] || $m['a'] === $u['a'] || abs($m['t'] - $u['t']) > $seg) continue;
                $junto[] = $j;
                $soma = round($soma + $m['q'], 3);
            }
            if (count($junto) < 2 || abs($soma - $u['q']) > 0.0005) continue;
            $usUm[$i] = true;
            foreach ($junto as $j) {
                $usMuitos[$j] = true;
                if ($inverso) $ligar($muitos[$j], $u, $muitos[$j]['q']);
                else $ligar($u, $muitos[$j], $muitos[$j]['q']);
            }
        }
    };
    $repartir($sai, $ent, $usadaS, $usadaE, false);
    $repartir($ent, $sai, $usadaE, $usadaS, true);
    return $r;
}

/**
 * Função pura (testada): a natureza de cada linha (NATUREZA). $linhas: [{HORA, DOC, TIPO, ARM_COD, CODIGO, EFEITO}];
 * $pares: naval_mov_emparelhar das saídas e entradas do período (de todos os armazéns). Venda FR/FA/FT, nota de crédito
 * NC, transferência enviada ou recebida (o par), SA sem par = venda SAP, outra entrada = compra (entrada externa),
 * outra saída (DA sem par, quebras, consumos) e acerto de inventário.
 */
function naval_mov_classificar(array $linhas, array $pares = [], array $tipos = NAVAL_MOV_TIPOS_OMISSAO): array
{
    foreach ($linhas as &$l) {
        $t = strtoupper(trim((string) $l['TIPO']));
        $q = (float) $l['EFEITO'];
        $k = naval_mov_chave($l);
        if ($t === 'INV') $n = 'acerto';
        elseif (in_array($t, $tipos['venda'], true)) $n = 'venda';
        elseif (in_array($t, $tipos['devolucao'], true)) $n = 'devolucao';
        elseif (isset($pares[$k])) $n = $pares[$k]['natureza'];
        elseif ($q < 0 && in_array($t, $tipos['saida_armazem'], true)) $n = 'venda_sap';
        else $n = $q > 0 ? 'entrada' : 'outra_saida';
        $l['NATUREZA'] = $n;
        if (isset($pares[$k])) $l['PAR'] = isset($pares[$k]['guia']) ? [$pares[$k]['guia'] . ' (anulada)']
            : array_map(function ($c) { return explode('|', $c[0])[0]; }, $pares[$k]['com']);
    }
    unset($l);
    return $linhas;
}

/**
 * Totais que interessam à gestão a partir do líquido de cada natureza: vendas no Pinguim BO (FR e FA − NC), vendas
 * SAP (SA sem transferência) e saídas efectivas = as duas.
 */
function naval_mov_derivados(array $nat): array
{
    $g = function (string $k) use ($nat) { return (float) ($nat[$k] ?? 0); };
    $pinguim = round(-($g('venda') + $g('devolucao')), 3);
    return ['saidas_efectivas' => round($pinguim - $g('venda_sap'), 3), 'vendas_pinguim' => $pinguim, 'vendas' => round(-$g('venda'), 3),
        'devolucoes' => round($g('devolucao'), 3), 'vendas_sap' => round(-$g('venda_sap'), 3),
        'transf_enviadas' => round(-$g('transf_enviada'), 3), 'transf_recebidas' => round($g('transf_recebida'), 3), 'entradas_externas' => round($g('entrada'), 3),
        'outras_saidas' => round(-$g('outra_saida'), 3), 'guias_anuladas' => round(-$g('guia_anulada'), 3), 'acertos' => round($g('acerto'), 3)];
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
        if (!empty($l['PAR'])) $x['par'] = array_values(array_unique($l['PAR']));
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

/**
 * A natureza no SQL (mesma regra de naval_mov_classificar). As saídas e entradas que podem ser metade de uma
 * transferência (SA, DA, GTC, EA) ficam «par»: classificam-se em PHP, emparelhadas pela hora e pela quantidade.
 */
function naval_mov_sql_natureza(array $tipos): string
{
    $em = function (array $l) {
        $l = array_values(array_filter($l, function ($x) { return (bool) preg_match('/^[A-Z0-9]{1,10}$/', (string) $x); }));
        return $l ? "TIPO IN ('" . implode("', '", $l) . "')" : '1 = 0';
    };
    return "CASE WHEN TIPO = 'INV' THEN 'acerto' WHEN " . $em($tipos['venda']) . " THEN 'venda' WHEN " . $em($tipos['devolucao']) . " THEN 'devolucao'
                 WHEN " . $em(naval_mov_tipos_par($tipos)) . " THEN 'par' WHEN EFEITO > 0 THEN 'entrada' ELSE 'outra_saida' END";
}

/**
 * Função pura (testada): junta os totais agregados no SQL (dos documentos e dos inventários, que nunca partilham
 * documentos) no formato de naval_mov_montar: resumo, por_tipo, por_armazem e por_artigo.
 */
function naval_mov_totais(array $linhas, array $saidasArmazem = []): array
{
    $g = ['tipo' => [], 'arm' => [], 'art' => [], 'tot' => []];
    // líquido de cada natureza: geral, por armazém e por artigo; as SA, DA, GTC e EA vêm já classificadas (emparelhadas)
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
            if ($nn === '' || $nn === 'par') continue;
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
 * As saídas e entradas que podem ser metade de uma transferência (SA, DA, GTC, EA), de todos os armazéns, do dia
 * antes ao dia depois do período (a outra metade pode cair do outro lado da meia-noite), já emparelhadas:
 * naval_mov_emparelhar. $fArt / $p: filtro do artigo (:art, :artl) e período (:de, :ate).
 */
function naval_mov_pares(string $fArt, array $p, array $tipos): array
{
    $tp = naval_mov_tipos_par($tipos);
    if (!$tp) return [];
    // com filtro de artigo: os documentos inteiros que o têm (o par documento a documento compara todas as linhas,
    // e assim o extracto de um artigo classifica igual ao de todos)
    if ($fArt !== '') $fArt = " AND DH.ID IN (SELECT DMD.DOCHEADER_ID FROM DOCMOVDETAIL DMD
              LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE)) WHERE DMD.DELETED = 0$fArt)";
    $l = naval_bd_consultar("
        SELECT CONVERT(VARCHAR(19), COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), 120) AS HORA,
               LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC, LTRIM(RTRIM(DC.CODE)) AS TIPO,
               LTRIM(RTRIM(COALESCE(WE.CODE, ''))) AS ARM_COD, LTRIM(RTRIM(DMD.PRODUCT_CODE)) AS CODIGO,
               CAST(SUM(DMD.QNT * COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL)) AS FLOAT) AS EFEITO
        FROM DOCMOVDETAIL DMD
        INNER JOIN DOCHEADER DH ON DH.ID = DMD.DOCHEADER_ID
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN ENTITY WE ON WE.ID = DMD.WAREHOUSE_ID
        LEFT JOIN PRODUCT P ON LTRIM(RTRIM(P.CODE)) = LTRIM(RTRIM(DMD.PRODUCT_CODE))
        WHERE DMD.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND LTRIM(RTRIM(DC.CODE)) IN ('" . implode("', '", $tp) . "')
          AND COALESCE(DMD.STK_SIGNAL, DC.STOCK_SIGNAL, 0) <> 0
          AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < :ate$fArt
        GROUP BY COALESCE(DH.CLOCK_DATE, DH.DOC_DATE), DH.DOC_PREFIX, DC.CODE, DH.DOC_NUMBER, WE.CODE, DMD.PRODUCT_CODE",
        // as datas já calculadas: o ODBC pode passar o parâmetro como «text», que o SQL Server não converte em data
        $par = [':de' => date('Y-m-d', strtotime($p[':de'] . ' 12:00') - 86400), ':ate' => date('Y-m-d', strtotime($p[':ate'] . ' 12:00') + 2 * 86400)] + $p);
    // a ligação exacta do Pinguim BO: o documento gerado a partir de outro (SA → GTC), também a guia anulada
    $lig = [];
    try {
        $lig = naval_bd_consultar("
            SELECT LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DE,
                   LTRIM(RTRIM(COALESCE(T.DOC_PREFIX, TC.CODE))) + '/' + CAST(T.DOC_NUMBER AS VARCHAR(20)) AS PARA,
                   CASE WHEN T.DELETED <> 0 OR COALESCE(T.CALC_ISCANCELED, 0) <> 0 THEN 1 ELSE 0 END AS ANULADO
            FROM DOCHEADERSOURCE DS
            INNER JOIN DOCHEADER DH ON DH.ID = DS.SRC_DOCHEADER_ID
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            INNER JOIN DOCHEADER T ON T.ID = DS.DOCHEADER_ID
            INNER JOIN DOCCONFIG TC ON TC.ID = T.DOCCONFIG_ID
            WHERE DS.DELETED = 0 AND DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0
              AND LTRIM(RTRIM(DC.CODE)) IN ('" . implode("', '", $tp) . "') AND LTRIM(RTRIM(TC.CODE)) IN ('" . implode("', '", $tp) . "')
              AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) >= :de AND COALESCE(DH.CLOCK_DATE, DH.DOC_DATE) < :ate$fArt", $par);
    } catch (Throwable $e) {
        error_log('[movimentos] ligação entre documentos (DOCHEADERSOURCE): ' . $e->getMessage());
    }
    $lig = array_map(function ($x) { return ['de' => (string) $x['DE'], 'para' => (string) $x['PARA'], 'anulado' => (int) $x['ANULADO'] === 1]; }, $lig);
    return naval_mov_emparelhar($l, $tipos, NAVAL_MOV_PAR_SEGUNDOS, NAVAL_MOV_PAR_DOC_SEGUNDOS, $lig);
}

/**
 * Função pura (testada): o balanço de cada armazém num período — stock inicial + entradas (compras, recebido de outro
 * armazém, acertos +) − saídas (vendas no Pinguim BO, vendas SAP, enviado para outro armazém, outras saídas, acertos −)
 * = stock final calculado, ao lado do stock final do Pinguim BO. $linhas: as linhas de naval_mov_montar (com
 * natureza); $ini / $fim: [cód. armazém => ['nome' => …, 'q' => …]] do Pinguim BO (dbo.GET_STOCK).
 */
function naval_mov_balanco(array $linhas, array $ini, array $fim): array
{
    $b = [];
    $novo = function (string $nome) {
        return ['armazem' => $nome, 'inicial' => 0.0, 'compras' => 0.0, 'recebido' => 0.0, 'vendas' => 0.0, 'vendas_sap' => 0.0, 'enviado' => 0.0,
            'anuladas' => 0.0, 'outras' => 0.0, 'acertos' => 0.0];
    };
    foreach ($linhas as $l) {
        $a = (string) $l['armazem_cod'];
        $b[$a] = $b[$a] ?? $novo((string) $l['armazem']);
        $q = (float) $l['entrada'] - (float) $l['saida'];
        $m = ['entrada' => 'compras', 'transf_recebida' => 'recebido', 'venda' => 'vendas', 'devolucao' => 'vendas', 'venda_sap' => 'vendas_sap',
            'transf_enviada' => 'enviado', 'guia_anulada' => 'anuladas', 'outra_saida' => 'outras', 'acerto' => 'acertos'][$l['natureza']] ?? ($q > 0 ? 'compras' : 'outras');
        // as saídas guardam-se positivas (vendas, enviado…); compras, recebido e acertos com o sinal
        $b[$a][$m] += in_array($m, ['compras', 'recebido', 'acertos'], true) ? $q : -$q;
    }
    foreach ([$ini, $fim] as $s) foreach ($s as $a => $x) $b[(string) $a] = $b[(string) $a] ?? $novo((string) $x['nome']);
    $out = [];
    foreach ($b as $a => $x) {
        $x = array_map(function ($v) { return is_float($v) ? round($v, 3) : $v; }, $x);
        $x['inicial'] = round((float) ($ini[$a]['q'] ?? 0), 3);
        $x['entradas'] = round($x['compras'] + $x['recebido'] + max(0, $x['acertos']), 3);
        $x['saidas'] = round($x['vendas'] + $x['vendas_sap'] + $x['enviado'] + $x['anuladas'] + $x['outras'] + max(0, -$x['acertos']), 3);
        $x['final'] = round($x['inicial'] + $x['entradas'] - $x['saidas'], 3);
        $x['pinguim'] = isset($fim[$a]) ? round((float) $fim[$a]['q'], 3) : 0.0;
        $x['diferenca'] = round($x['pinguim'] - $x['final'], 3);
        if (abs($x['inicial']) + abs($x['entradas']) + abs($x['saidas']) + abs($x['pinguim']) < 0.0005) continue;
        $out[] = ['armazem_cod' => (string) $a] + $x;
    }
    usort($out, function ($p, $q) { return strcmp($p['armazem_cod'], $q['armazem_cod']); });
    return $out;
}

/**
 * Stock de um artigo (código exacto) em cada armazém onde já teve movimento, no fim de $dia, calculado pelo próprio
 * Pinguim BO (dbo.GET_STOCK): [cód. armazém => ['nome' => …, 'q' => …]].
 */
function naval_mov_stock_artigo(string $codigo, string $dia): array
{
    $o = [];
    foreach (naval_bd_consultar("
        SELECT LTRIM(RTRIM(E.CODE)) AS COD, MAX(E.DESCRIPTION) AS NOME, CAST(MAX(STK.QNT) AS FLOAT) AS Q
        FROM (SELECT DISTINCT DMD.WAREHOUSE_ID AS WID FROM DOCMOVDETAIL DMD WHERE DMD.DELETED = 0 AND LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :a1
              UNION SELECT IH.WAREHOUSE_ID FROM INVENTORYHEADER IH INNER JOIN INVENTORYDETAIL ID ON ID.INVENTORYHEADER_ID = IH.ID
                    INNER JOIN PRODUCT PP ON PP.ID = ID.PRODUCT_ID WHERE IH.DELETED = 0 AND LTRIM(RTRIM(PP.CODE)) = :a2) W
        INNER JOIN ENTITY E ON E.ID = W.WID
        CROSS JOIN (SELECT TOP 1 ID FROM PRODUCT WHERE LTRIM(RTRIM(CODE)) = :a3) P
        CROSS APPLY dbo.GET_STOCK(:dia, P.ID, E.ID, 1, NULL, 1, 1) STK
        GROUP BY E.CODE", [':a1' => $codigo, ':a2' => $codigo, ':a3' => $codigo, ':dia' => $dia . ' 23:59:59']) as $r) {
        $o[(string) $r['COD']] = ['nome' => trim((string) $r['NOME']), 'q' => (float) $r['Q']];
    }
    return $o;
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
    // natureza: as saídas (SA, DA) e entradas (GTC, EA) emparelham-se pela hora e pela quantidade, em todos os
    // armazéns (mesmo com o filtro de um armazém, a outra metade da transferência está noutro)
    $tipos = naval_mov_tipos();
    $tp = naval_mov_tipos_par($tipos);
    $fArt = $artigo !== '' ? ' AND (LTRIM(RTRIM(DMD.PRODUCT_CODE)) = :art OR P.DESCRIPTION LIKE :artl)' : '';
    $pArt = [':de' => $de, ':ate' => $ate] + ($artigo !== '' ? [':art' => $p[':art'], ':artl' => $p[':artl']] : []);
    $pares = null;
    $classificar = function (array $ls) use ($tipos, $tp, $fArt, $pArt, &$pares) {
        $tem = false;
        foreach ($ls as $l) if (in_array(strtoupper(trim((string) $l['TIPO'])), $tp, true)) $tem = true;
        if ($tem && $pares === null) $pares = naval_mov_pares($fArt, $pArt, $tipos);
        return naval_mov_classificar($ls, $pares ?? [], $tipos);
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
        // as saídas e entradas de armazém de todo o período (SA, DA, GTC, EA), emparelhadas como no extracto
        $sa = $tp ? $classificar(naval_bd_consultar("SELECT * FROM ($sqlDocs) X WHERE TIPO IN ('" . implode("', '", $tp) . "')", $p)) : [];
        $r = array_merge($r, naval_mov_totais($tot, $sa));
    }
    // balanço de cada armazém com o stock do Pinguim BO (um artigo, código exacto, sem filtro de tipo)
    $r['balanco'] = null;
    if ($artigo !== '' && $tipo === '' && !$cortado && preg_match('/^[A-Za-z0-9._-]{1,30}$/', $artigo)) {
        $ini = naval_mov_stock_artigo($artigo, date('Y-m-d', strtotime($de . ' 12:00') - 86400));
        $fim = naval_mov_stock_artigo($artigo, $ate);
        $soArt = array_values(array_filter($r['linhas'], function ($l) use ($artigo) { return $l['codigo'] === $artigo; }));
        if ($soArt || $ini || $fim) {
            if ($armazem !== '') {
                $ini = array_intersect_key($ini, [$armazem => 1]);
                $fim = array_intersect_key($fim, [$armazem => 1]);
            }
            $r['balanco'] = naval_mov_balanco($soArt, $ini, $fim);
        }
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
