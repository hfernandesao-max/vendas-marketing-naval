<?php
/**
 * Vendas por sincronizar (alerta da Vigia e notificação): rota carregada, sem vendas no Pinguim BO desde a carga e com o
 * telemóvel sem sincronizar há mais de SYNC_ALERTA_HORAS horas. Só SELECT no Pinguim BO. Separado de sincronizacao.php,
 * que tem só a consulta definida pela gestão para a página Sincronização.
 */
defined('NAVAL_NUCLEO') || exit;

// ── Vendas por sincronizar ─────────────────────────────────────────────────────────────────────────────────────

/**
 * Rotas com carga recente e sem vendas no Pinguim BO desde essa carga, cujo telemóvel não sincroniza há mais de
 * SYNC_ALERTA_HORAS horas (6): o promotor está a vender mas as vendas (e o dinheiro recebido) ficaram no telemóvel.
 * Função pura: $linhas = [{ROTA (código), ULTIMA_CARGA, ULTIMA_VENDA, ULTIMA_SINCRONIZACAO}] (datas AAAA-MM-DD HH:MM:SS).
 * Só em dias de trabalho entre as 12:00 e as 20:00 (de manhã a rota ainda pode não ter vendido); carga dos últimos 3
 * dias. Devolve [{rota, carga, venda, sincronizacao, horas}].
 */
function naval_sync_vendas_por_sincronizar(array $linhas, int $agora, ?string $tipoDia = null): array
{
    $hm = date('H:i', $agora);
    if ($tipoDia === 'folga' || $hm < '12:00' || $hm > '20:00') return [];
    $limite = max(1, (int) naval_config('SYNC_ALERTA_HORAS', '6')) * 3600;
    $out = [];
    foreach ($linhas as $l) {
        $rota = trim((string) ($l['ROTA'] ?? ''));
        $carga = (string) ($l['ULTIMA_CARGA'] ?? '');
        if (!preg_match('/^\d{4}$/', $rota) || $carga === '' || strtotime($carga) < $agora - 3 * 86400) continue;
        $venda = (string) ($l['ULTIMA_VENDA'] ?? '');
        if ($venda !== '' && $venda >= $carga) continue;   // vendeu depois da carga: as vendas estão a chegar
        $sync = (string) ($l['ULTIMA_SINCRONIZACAO'] ?? '');
        $sem = $sync === '' ? PHP_INT_MAX : $agora - strtotime($sync);
        if ($sem < $limite) continue;
        $out[] = ['rota' => $rota, 'carga' => $carga, 'venda' => $venda ?: null, 'sincronizacao' => $sync ?: null,
            'horas' => $sync === '' ? null : (int) floor($sem / 3600)];
    }
    return $out;
}

/** Lê do Pinguim BO (só SELECT) a última carga, venda e sincronização de cada rota e aplica a regra. null sem ligação. */
function naval_sync_por_sincronizar(?int $agora = null): ?array
{
    $agora = $agora ?? time();
    $rows = naval_sync_rotas_dados($agora);
    if ($rows === null) return null;
    naval_servico('calendario');
    return naval_sync_vendas_por_sincronizar(array_values($rows), $agora, (string) (naval_cal_dia(date('Y-m-d', $agora))['tipo'] ?? ''));
}

/**
 * Última carga (GTC dos últimos 3 dias), última venda e última sincronização do telemóvel de cada rota, do Pinguim BO
 * (só SELECT), guardadas 5 minutos para todos: {rota: {ROTA, ULTIMA_CARGA, ULTIMA_VENDA, ULTIMA_SINCRONIZACAO}}.
 */
function naval_sync_rotas_dados(?int $agora = null): ?array
{
    $agora = $agora ?? time();
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    $cache = naval_pasta('fontes') . '/rotas-sincronizacao.json';
    $c = naval_json_ler($cache);
    if ($c && $agora - (int) ($c['t'] ?? 0) < 300 && $agora >= (int) ($c['t'] ?? 0)) return (array) $c['rotas'];
    $desde = date('Y-m-d', $agora - 3 * 86400);
    try {
        $rows = naval_bd_consultar("
            SELECT S.CODE AS ROTA,
                   (SELECT CONVERT(VARCHAR(19), MAX(DH.CLOCK_DATE), 120) FROM DOCHEADER DH JOIN DOCCONFIG DC ON DC.ID = DH.DOCCONFIG_ID
                     WHERE DH.STORE_ID = S.ID AND DH.DELETED = 0 AND DC.CODE = 'GTC' AND COALESCE(DH.CALC_ISCANCELED, 0) = 0 AND DH.DOC_DATE >= :desde) AS ULTIMA_CARGA,
                   (SELECT CONVERT(VARCHAR(19), MAX(DH.CLOCK_DATE), 120) FROM DOCHEADER DH
                     WHERE DH.STORE_ID = S.ID AND DH.DELETED = 0 AND COALESCE(DH.CALC_SALES_SIGNAL, 0) <> 0) AS ULTIMA_VENDA,
                   (SELECT CONVERT(VARCHAR(19), MAX(SPH.END_TIMESTAMP), 120) FROM SYNC_PHSE_HISTORY SPH WHERE SPH.STORE_ID = S.ID) AS ULTIMA_SINCRONIZACAO
            FROM STORE S WHERE S.CODE LIKE '10[0-9][0-9]' AND S.CODE <> '1000'", [':desde' => $desde]);
    } catch (Throwable $e) {
        error_log('[vendas por sincronizar] ' . $e->getMessage());
        return null;
    }
    $out = [];
    foreach ($rows as $r) $out[trim((string) $r['ROTA'])] = $r;
    naval_json_gravar($cache, ['t' => $agora, 'rotas' => $out], false);
    return $out;
}

/**
 * Avisos da rota na conciliação (função pura, testada): porque é que o Pinguim BO pode não ter as vendas do dia.
 *   - sincronizacao: o telemóvel não sincronizou depois do fim das vendas do dia (a referência da contagem): as vendas
 *     feitas podem estar só no telemóvel;
 *   - sem_faturas: a rota não tem nenhuma factura no Pinguim BO há mais de 2 dias, mas tem carga recente: o promotor
 *     pode estar a entregar sem emitir factura no Winrest (documento fiscal em falta).
 * $dados: {ULTIMA_CARGA, ULTIMA_VENDA, ULTIMA_SINCRONIZACAO}. Devolve [{tipo, desde, texto}].
 */
function naval_sync_avisos_rota(?array $dados, string $dia, string $referencia): array
{
    if (!$dados) return [];
    $out = [];
    $sync = (string) ($dados['ULTIMA_SINCRONIZACAO'] ?? '');
    $venda = (string) ($dados['ULTIMA_VENDA'] ?? '');
    $carga = (string) ($dados['ULTIMA_CARGA'] ?? '');
    $fmt = function (string $d) { return $d === '' ? '—' : date('d-m-Y H:i', (int) strtotime($d)); };
    // uma hora de folga: o telemóvel sincroniza de tempos a tempos, não a cada venda
    if ($sync === '' || $sync < date('Y-m-d H:i:s', (int) strtotime($referencia) - 3600)) {
        $out[] = ['tipo' => 'sincronizacao', 'desde' => $sync ?: null,
            'texto' => 'O telemóvel desta rota não sincroniza com o Pinguim BO desde ' . $fmt($sync) . ': as vendas do dia podem estar só no telemóvel. Sincronize no Winrest e a conciliação actualiza-se sozinha.'];
    }
    $limite = date('Y-m-d H:i:s', strtotime($dia . ' 23:59:59') - 2 * 86400);
    // com o telemóvel parado, a falta de facturas é consequência e não outro problema
    if (!$out && $carga !== '' && ($venda === '' || $venda < $limite)) {
        $out[] = ['tipo' => 'sem_faturas', 'desde' => $venda ?: null,
            'texto' => 'Esta rota não tem facturas no Pinguim BO desde ' . ($venda === '' ? 'sempre' : date('d-m-Y', (int) strtotime($venda))) . ', mas tem carga: confirme com o promotor se está a emitir as facturas no Winrest (venda sem documento fiscal).'];
    }
    return $out;
}
