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
    naval_servico('bd');
    if (!naval_bd_configurada()) return null;
    naval_servico('calendario');
    $dia = date('Y-m-d', $agora);
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
    return naval_sync_vendas_por_sincronizar($rows, $agora, (string) (naval_cal_dia($dia)['tipo'] ?? ''));
}
