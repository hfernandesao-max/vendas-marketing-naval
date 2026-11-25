<?php
/**
 * Abertura e fecho do dia de cada rota no Pinguim BO (só SELECT), para o portal saber se cada dia de venda foi aberto
 * e fechado no dia certo — o que vai passar a ser feito automaticamente pelo Pinguim BO.
 *
 * Onde está no Pinguim BO (visto com ferramentas/pinguim-turnos.php em 25-11-2026):
 *   - tabela SESSION: cada abertura de dia, de turno e de caixa da loja (rota), com o dia de trabalho (SESSION_DATE) e a
 *     hora local em que foi feita (LOCAL_TIMESTAMP). A primeira linha de um SESSION_DATE é a abertura desse dia;
 *   - documento FCL («Fecho financeiro»): o fecho do dia; leva a data do dia que fecha (DOC_DATE) e a hora em que foi
 *     feito (CLOCK_DATE). Fechado o dia, o telemóvel já não emite documentos com essa data;
 *   - documentos emitidos depois do dia com a data desse dia (DOC_DATE < dia do CLOCK_DATE): sinal de que o dia ficou
 *     aberto e se vendeu nos dias seguintes com a data antiga (ex.: rota 1002, dia 19-11 fechado só a 23-11).
 *
 * Regras (gestão por excepção):
 *   - abertura «certa» quando foi feita no próprio dia; «antecipada» quando o dia foi aberto na véspera (as vendas
 *     feitas depois dessa hora levam a data do dia seguinte);
 *   - fecho «a tempo» até PINGUIM_FECHO_LIMITE (06:00) do dia seguinte; depois disso, «atrasado»; sem FCL depois do
 *     limite, «por fechar» (alerta); antes do limite, o dia ainda está «aberto» (normal);
 *   - o fecho (FCL) é a prova de que as vendas do dia estão completas no Pinguim BO: entra no Fecho do dia
 *     (relatório de vendas), a par da sincronização depois do fim das vendas.
 */
defined('NAVAL_NUCLEO') || exit;

/** Hora limite do fecho, no dia seguinte (HH:MM; .env PINGUIM_FECHO_LIMITE, por omissão 06:00). */
function naval_pdia_limite(): string
{
    $h = naval_config('PINGUIM_FECHO_LIMITE', '06:00');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : '06:00';
}

/** Rota a partir do código ou da descrição da loja («1004 - FHR PIV» → 1004). */
function naval_pdia_rota(string $codigo, string $descricao = ''): string
{
    $c = trim($codigo);
    if (preg_match('/^\d{3,5}$/', $c)) return $c;
    if (preg_match('/^\s*(\d{3,5})/', $descricao, $m)) return $m[1];
    return $c !== '' ? $c : trim($descricao);
}

/**
 * Junta as três leituras numa linha por rota e dia (função pura, testada).
 *   $sessoes:   [{ROTA, DIA, ABERTO_EM}]               (primeira linha da SESSION de cada dia de trabalho)
 *   $fechos:    [{ROTA, DIA, FECHADO_EM, DOCUMENTO}]   (FCL)
 *   $docs:      [{ROTA, DIA, N, VENDAS, NOUTRO_DIA, ULTIMO}] (documentos do dia; vendas emitidas noutro dia)
 * Devolve [{rota, dia, aberto_em, abertura, fechado_em, documento, fecho, atraso_horas, documentos, vendas,
 * noutro_dia, ultimo}], da rota e do dia. Rotas fora de 10NN (formação, armazém) ficam de fora.
 */
function naval_pdia_montar(array $sessoes, array $fechos, array $docs, int $agora, ?string $limite = null): array
{
    $limite = $limite ?? naval_pdia_limite();
    $l = [];
    $linha = function (string $rota, string $dia) use (&$l) {
        $k = $rota . '|' . $dia;
        if (!isset($l[$k])) {
            $l[$k] = ['rota' => $rota, 'dia' => $dia, 'aberto_em' => null, 'abertura' => null, 'fechado_em' => null, 'documento' => null,
                'fecho' => null, 'atraso_horas' => null, 'documentos' => 0, 'vendas' => 0, 'noutro_dia' => 0, 'ultimo' => null];
        }
        return $k;
    };
    $ok = function (string $rota, string $dia) { return preg_match('/^10\d\d$/', $rota) && $rota !== '1000' && naval_data_valida($dia); };
    foreach ($sessoes as $s) {
        $rota = naval_pdia_rota((string) ($s['ROTA'] ?? ''), (string) ($s['DESCR'] ?? ''));
        $dia = substr((string) ($s['DIA'] ?? ''), 0, 10);
        if (!$ok($rota, $dia) || empty($s['ABERTO_EM'])) continue;
        $k = $linha($rota, $dia);
        $em = substr((string) $s['ABERTO_EM'], 0, 19);
        if ($l[$k]['aberto_em'] === null || $em < $l[$k]['aberto_em']) $l[$k]['aberto_em'] = $em;
    }
    foreach ($fechos as $f) {
        $rota = naval_pdia_rota((string) ($f['ROTA'] ?? ''), (string) ($f['DESCR'] ?? ''));
        $dia = substr((string) ($f['DIA'] ?? ''), 0, 10);
        if (!$ok($rota, $dia) || empty($f['FECHADO_EM'])) continue;
        $k = $linha($rota, $dia);
        $em = substr((string) $f['FECHADO_EM'], 0, 19);
        if ($l[$k]['fechado_em'] === null || $em < $l[$k]['fechado_em']) {
            $l[$k]['fechado_em'] = $em;
            $l[$k]['documento'] = trim((string) ($f['DOCUMENTO'] ?? '')) ?: null;
        }
    }
    foreach ($docs as $d) {
        $rota = naval_pdia_rota((string) ($d['ROTA'] ?? ''), (string) ($d['DESCR'] ?? ''));
        $dia = substr((string) ($d['DIA'] ?? ''), 0, 10);
        if (!$ok($rota, $dia)) continue;
        $k = $linha($rota, $dia);
        $l[$k]['documentos'] += (int) ($d['N'] ?? 0);
        $l[$k]['vendas'] += (int) ($d['VENDAS'] ?? 0);
        $l[$k]['noutro_dia'] += (int) ($d['NOUTRO_DIA'] ?? 0);
        $u = !empty($d['ULTIMO']) ? substr((string) $d['ULTIMO'], 0, 19) : null;
        if ($u !== null && ($l[$k]['ultimo'] === null || $u > $l[$k]['ultimo'])) $l[$k]['ultimo'] = $u;
    }
    $agoraTxt = date('Y-m-d H:i:s', $agora);
    foreach ($l as &$x) {
        if ($x['aberto_em'] !== null) {
            $d = substr($x['aberto_em'], 0, 10);
            $x['abertura'] = $d === $x['dia'] ? 'certa' : ($d < $x['dia'] ? 'antecipada' : 'atrasada');
        }
        $prazo = date('Y-m-d', strtotime($x['dia'] . ' 12:00') + 86400) . ' ' . $limite . ':00';
        if ($x['fechado_em'] !== null) {
            $x['fecho'] = $x['fechado_em'] <= $prazo ? 'a_tempo' : 'atrasado';
            if ($x['fecho'] === 'atrasado') $x['atraso_horas'] = (int) floor((strtotime($x['fechado_em']) - strtotime($prazo)) / 3600);
        } elseif ($agoraTxt > $prazo) {
            $x['fecho'] = 'por_fechar';
            $x['atraso_horas'] = (int) floor(($agora - strtotime($prazo)) / 3600);
        } else {
            $x['fecho'] = 'aberto';
        }
    }
    unset($x);
    $out = array_values($l);
    usort($out, function ($a, $b) { return [$a['rota'], $a['dia']] <=> [$b['rota'], $b['dia']]; });
    return $out;
}

/** Resumo para os indicadores e os alertas: contagens e as excepções (o que precisa de acção). */
function naval_pdia_resumo(array $linhas): array
{
    $c = ['dias' => count($linhas), 'a_tempo' => 0, 'atrasado' => 0, 'por_fechar' => 0, 'aberto' => 0, 'antecipada' => 0, 'noutro_dia' => 0];
    $exc = [];
    foreach ($linhas as $x) {
        $c[$x['fecho']] = ($c[$x['fecho']] ?? 0) + 1;
        if ($x['abertura'] === 'antecipada') $c['antecipada']++;
        $c['noutro_dia'] += $x['noutro_dia'];
        if ($x['fecho'] === 'por_fechar' || $x['fecho'] === 'atrasado' || $x['abertura'] === 'antecipada' || $x['noutro_dia'] > 0) $exc[] = $x;
    }
    return ['contagens' => $c, 'excepcoes' => $exc];
}

/** Lê o Pinguim BO (só SELECT) de $de a $ate. Lança RuntimeException sem ligação. */
function naval_pdia_ler(string $de, string $ate, ?int $agora = null): array
{
    naval_servico('bd');
    naval_servico('vendas');
    $p = [':de' => $de, ':ate' => $ate];
    $sessoes = naval_bd_consultar("
        SELECT S.CODE AS ROTA, S.DESCRIPTION AS DESCR, CONVERT(VARCHAR(10), SE.SESSION_DATE, 120) AS DIA,
               CONVERT(VARCHAR(19), MIN(SE.LOCAL_TIMESTAMP), 120) AS ABERTO_EM
        FROM [SESSION] SE
        LEFT JOIN STORE S ON S.ID = SE.STORE_ID
        WHERE SE.DELETED = 0 AND SE.SESSION_DATE >= :de AND SE.SESSION_DATE <= :ate
        GROUP BY S.CODE, S.DESCRIPTION, SE.SESSION_DATE", $p);
    $fechos = naval_bd_consultar("
        SELECT S.CODE AS ROTA, S.DESCRIPTION AS DESCR, CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DIA,
               CONVERT(VARCHAR(19), DH.CLOCK_DATE, 120) AS FECHADO_EM,
               DC.CODE + ' ' + LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, ''))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOCUMENTO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        WHERE DH.DELETED = 0 AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND DC.CODE = 'FCL'
          AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, CAST(:ate AS DATE))", $p);
    $docs = naval_bd_consultar("
        SELECT S.CODE AS ROTA, S.DESCRIPTION AS DESCR, CONVERT(VARCHAR(10), DH.DOC_DATE, 120) AS DIA, COUNT(*) AS N,
               SUM(CASE WHEN DC.CODE IN " . naval_vendas_tipos_sql() . " THEN 1 ELSE 0 END) AS VENDAS,
               SUM(CASE WHEN DC.CODE IN " . naval_vendas_tipos_sql() . " AND DH.CLOCK_DATE IS NOT NULL
                         AND CAST(DH.CLOCK_DATE AS DATE) > CAST(DH.DOC_DATE AS DATE) THEN 1 ELSE 0 END) AS NOUTRO_DIA,
               CONVERT(VARCHAR(19), MAX(DH.CLOCK_DATE), 120) AS ULTIMO
        FROM DOCHEADER DH
        INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
        LEFT JOIN STORE S ON S.ID = DH.STORE_ID
        WHERE " . naval_vendas_condicao_validos() . " AND DC.CODE <> 'FCL'
          AND DH.DOC_DATE >= :de AND DH.DOC_DATE < DATEADD(day, 1, CAST(:ate AS DATE))
        GROUP BY S.CODE, S.DESCRIPTION, CONVERT(VARCHAR(10), DH.DOC_DATE, 120)", $p);
    $agora = $agora ?? time();
    $linhas = naval_pdia_montar($sessoes, $fechos, $docs, $agora);
    return ['de' => $de, 'ate' => $ate, 'agora' => date('Y-m-d H:i:s', $agora), 'limite' => naval_pdia_limite(), 'linhas' => $linhas] + naval_pdia_resumo($linhas);
}

/** Rotas com o dia $dia fechado no Pinguim BO (FCL): {rota: fechado_em}. null sem ligação. */
function naval_pdia_fechados(string $dia): ?array
{
    try {
        $r = naval_cache('pinguim-dia-' . $dia, 300, function () use ($dia) { return naval_pdia_ler($dia, $dia); });
    } catch (Throwable $e) {
        error_log('[pinguim dia] ' . $e->getMessage());
        return null;
    }
    $out = [];
    foreach ((array) ($r['linhas'] ?? []) as $x) if ($x['dia'] === $dia && $x['fechado_em'] !== null) $out[$x['rota']] = $x['fechado_em'];
    return $out;
}

/**
 * Avisos do dia (função pura): rotas com o dia por fechar depois do limite e rotas que abriram o dia de hoje na
 * véspera. Só dias de trabalho dos últimos 3 dias. Devolve [{tipo, rota, dia, chave, horas, em}].
 */
function naval_pdia_avisos(array $linhas, int $agora): array
{
    $out = [];
    $desde = date('Y-m-d', $agora - 3 * 86400);
    foreach ($linhas as $x) {
        if ($x['dia'] < $desde) continue;
        if ($x['fecho'] === 'por_fechar' && ($x['documentos'] > 0 || $x['aberto_em'] !== null)) {
            $out[] = ['tipo' => 'por_fechar', 'rota' => $x['rota'], 'dia' => $x['dia'], 'chave' => 'dia_fechar|' . $x['rota'] . '|' . $x['dia'], 'horas' => $x['atraso_horas'], 'em' => null];
        }
        if ($x['abertura'] === 'antecipada' && $x['dia'] === date('Y-m-d', $agora)) {
            $out[] = ['tipo' => 'antecipada', 'rota' => $x['rota'], 'dia' => $x['dia'], 'chave' => 'dia_abrir|' . $x['rota'] . '|' . $x['dia'], 'horas' => null, 'em' => $x['aberto_em']];
        }
    }
    return $out;
}

// ── Vendas depois do fecho do dia ───────────────────────────────────────────────────────────────────────────────
// Com o dia fechado (FCL), o telemóvel não emite mais documentos com essa data. Uma venda da mesma rota, no mesmo dia,
// depois da hora do FCL, só existe se o vendedor reabriu o dia (nova sessão) — exactamente o que a regra «contagem só
// com o dia fechado» quer evitar. O coordenador é avisado logo e a conciliação mostra-a.

/**
 * Função pura (testada). $linhas: naval_pdia_montar; $vendas: [{ROTA, DESCR, DOC, HORA (CLOCK_DATE), TOTAL}].
 * Devolve [{rota, dia, doc, hora, total, fechado_em}] — só os fechos feitos no próprio dia (um FCL de madrugada fecha o
 * dia anterior; as vendas da manhã seguinte são do dia novo).
 */
function naval_pdia_vendas_depois(array $linhas, array $vendas): array
{
    $fecho = [];
    foreach ($linhas as $x) {
        if ($x['fechado_em'] !== null && substr($x['fechado_em'], 0, 10) === $x['dia']) $fecho[$x['rota']][$x['dia']] = $x['fechado_em'];
    }
    $out = [];
    foreach ($vendas as $v) {
        $rota = naval_pdia_rota((string) ($v['ROTA'] ?? ''), (string) ($v['DESCR'] ?? ''));
        $hora = substr((string) ($v['HORA'] ?? ''), 0, 19);
        $dia = substr($hora, 0, 10);
        $f = $fecho[$rota][$dia] ?? null;
        if ($f === null || $hora <= $f) continue;
        $out[] = ['rota' => $rota, 'dia' => $dia, 'doc' => trim((string) ($v['DOC'] ?? '')), 'hora' => $hora, 'total' => round((float) ($v['TOTAL'] ?? 0), 2), 'fechado_em' => $f];
    }
    usort($out, function ($a, $b) { return [$a['rota'], $a['hora']] <=> [$b['rota'], $b['hora']]; });
    return $out;
}

/** Vendas feitas num dia depois do fecho (FCL) desse dia, lidas do Pinguim BO (só SELECT; 5 minutos em cache). */
function naval_pdia_depois_fecho(string $dia, bool $fresco = false): array
{
    return naval_cache('pinguim-depois-fecho-' . $dia, 300, function () use ($dia) {
        naval_servico('bd');
        naval_servico('vendas');
        $l = naval_pdia_ler($dia, $dia);
        $v = naval_bd_consultar("
            SELECT S.CODE AS ROTA, S.DESCRIPTION AS DESCR,
                   LTRIM(RTRIM(COALESCE(DH.DOC_PREFIX, DC.CODE))) + '/' + CAST(DH.DOC_NUMBER AS VARCHAR(20)) AS DOC,
                   CONVERT(VARCHAR(19), DH.CLOCK_DATE, 120) AS HORA, DH.CALC_TOTAL_W_TAX AS TOTAL
            FROM DOCHEADER DH
            INNER JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
            LEFT JOIN STORE S ON S.ID = DH.STORE_ID
            WHERE " . naval_vendas_condicao_validos() . " AND DC.CODE IN " . naval_vendas_tipos_sql() . "
              AND DH.CLOCK_DATE >= :de AND DH.CLOCK_DATE < DATEADD(day, 1, CAST(:de AS DATE))", [':de' => $dia]);
        return naval_pdia_vendas_depois((array) $l['linhas'], $v);
    }, $fresco);
}

/**
 * Ciclo (tarefas periódicas): das 12:00 às 23:59, no máximo a cada 10 minutos, avisa o coordenador de cada venda depois
 * do fecho do dia (notificação «venda_depois_fecho», uma vez por documento). PINGUIM_DEPOIS_FECHO=0 desliga.
 */
function naval_pdia_depois_ciclo(int $agora): ?array
{
    if (naval_config('PINGUIM_DEPOIS_FECHO', '1') === '0' || date('H', $agora) < '12') return null;
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    $marca = naval_pasta('limites') . '/pinguim-depois-fecho';
    if (is_file($marca) && filemtime($marca) > $agora - 600) return null;
    @touch($marca);
    try {
        $l = naval_pdia_depois_fecho(date('Y-m-d', $agora), true);
    } catch (Throwable $e) {
        error_log('[pinguim depois do fecho] ' . $e->getMessage());
        return null;
    }
    naval_servico('notificacoes');
    $feitos = [];
    foreach ($l as $x) {
        $tx = naval_notif_txt('depois_fecho', ['r' => $x['rota'], 'doc' => $x['doc'], 'h' => substr($x['hora'], 11, 5), 'f' => substr($x['fechado_em'], 11, 5),
            'v' => number_format($x['total'], 0, ',', ' ')]);
        $n = naval_notificar('venda_depois_fecho', $tx['pt'][0], $tx['pt'][1], ['chave' => 'depois_fecho|' . $x['rota'] . '|' . $x['doc'], 'rota' => $x['rota'],
            'url' => '../reports/#conciliacao-carrinha', 'textos' => $tx], $agora);
        if (empty($n['repetida'])) $feitos[] = $x['doc'];
    }
    if ($feitos) naval_registar('pinguim_venda_depois_fecho', ['documentos' => $feitos], 'sistema');
    return $feitos ?: null;
}

/**
 * Ciclo (tarefas periódicas): de hora a hora, a partir da hora limite, avisa quem acompanha as rotas (notificação
 * «dia_por_fechar», uma vez por rota e dia). Devolve os avisos enviados, ou null fora de horas ou sem Pinguim BO.
 */
function naval_pdia_ciclo(int $agora): ?array
{
    if (naval_config('PINGUIM_DIA_AVISOS', '1') === '0') return null;
    if (date('H:i', $agora) < naval_pdia_limite() || (int) date('i', $agora) >= 5) return null;
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    try {
        $r = naval_pdia_ler(date('Y-m-d', $agora - 3 * 86400), date('Y-m-d', $agora), $agora);
    } catch (Throwable $e) {
        error_log('[pinguim dia] ' . $e->getMessage());
        return null;
    }
    naval_servico('notificacoes');
    $feitos = [];
    foreach (naval_pdia_avisos($r['linhas'], $agora) as $a) {
        $dm = date('d-m', strtotime($a['dia'] . ' 12:00'));
        $tx = $a['tipo'] === 'por_fechar'
            ? naval_notif_txt('dia_fechar', ['r' => $a['rota'], 'd' => $dm])
            : naval_notif_txt('dia_abrir', ['r' => $a['rota'], 'd' => $dm, 'h' => substr((string) $a['em'], 11, 5) . ' de ' . date('d-m', strtotime((string) $a['em']))]);
        $x = naval_notificar('dia_por_fechar', $tx['pt'][0], $tx['pt'][1], ['chave' => $a['chave'], 'rota' => $a['rota'], 'url' => '../reports/#sincronizacao', 'textos' => $tx], $agora);
        if (empty($x['repetida'])) $feitos[] = $a['chave'];
    }
    return $feitos ?: null;
}
