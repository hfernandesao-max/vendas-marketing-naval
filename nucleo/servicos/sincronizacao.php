<?php
/**
 * Sincronização dos smartphones RTM com o Pinguim BO (tabela SYNC_PHSE_HISTORY), só com SELECT.
 *
 * Para cada rota (loja STORE): a última sincronização terminada, quantas houve hoje e nos últimos 7 dias,
 * e o histórico diário dos últimos 14 dias. O estado segue a prática dos sistemas de gestão de
 * dispositivos («último contacto»): em dia, atenção ou crítico conforme as horas desde a última
 * sincronização (.env: SYNC_ALERTA_HORAS, por omissão 2; SYNC_CRITICO_HORAS, por omissão 24).
 * As rotas do portal que nunca sincronizaram aparecem como «Sem registo».
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';

const NAVAL_SYNC_EXCLUIR = '9999-GRUPO NAVAL FORMAÇÃO';   // loja de formação, fora do controlo

function naval_sync_limites(): array
{
    $alerta = max(0.25, (float) naval_config('SYNC_ALERTA_HORAS', '2'));
    $critico = max($alerta, (float) naval_config('SYNC_CRITICO_HORAS', '24'));
    return ['alerta_horas' => $alerta, 'critico_horas' => $critico];
}

/** Estado de uma rota pelos minutos desde a última sincronização (null = nunca). */
function naval_sync_estado(?int $minutos, array $lim): string
{
    if ($minutos === null) return 'sem_registo';
    if ($minutos <= $lim['alerta_horas'] * 60) return 'em_dia';
    if ($minutos <= $lim['critico_horas'] * 60) return 'atencao';
    return 'critico';
}

/** Rota a partir do código ou da descrição da loja («1004 - FHR PIV» → 1004). */
function naval_sync_rota(string $codigo, string $descricao): string
{
    if (preg_match('/^\d{3,5}$/', trim($codigo))) return trim($codigo);
    if (preg_match('/^\s*(\d{3,5})/', $descricao, $m)) return $m[1];
    return trim($codigo) !== '' ? trim($codigo) : trim($descricao);
}

/** Estado da sincronização de todas as rotas. Lança RuntimeException se a base de dados não responder. */
function naval_sincronizacao(?int $agora = null): array
{
    $agora = $agora ?? time();
    $lim = naval_sync_limites();
    $hoje = date('Y-m-d', $agora);
    $p = [':excl' => NAVAL_SYNC_EXCLUIR, ':hoje' => $hoje, ':d7' => date('Y-m-d', $agora - 6 * 86400)];
    $linhas = naval_bd_consultar("
        SELECT
            S.CODE AS CODIGO,
            S.DESCRIPTION AS LOJA,
            CONVERT(VARCHAR(19), MAX(SPH.END_TIMESTAMP), 120) AS ULTIMA,
            SUM(CASE WHEN SPH.END_TIMESTAMP >= :hoje THEN 1 ELSE 0 END) AS HOJE,
            SUM(CASE WHEN SPH.END_TIMESTAMP >= :d7 THEN 1 ELSE 0 END) AS SETE_DIAS
        FROM SYNC_PHSE_HISTORY AS SPH
        LEFT JOIN STORE AS S ON S.ID = SPH.STORE_ID
        WHERE S.DESCRIPTION <> :excl
        GROUP BY SPH.STORE_ID, S.CODE, S.DESCRIPTION
        ORDER BY MAX(SPH.END_TIMESTAMP) DESC", $p);
    $dias = naval_bd_consultar("
        SELECT S.CODE AS CODIGO, S.DESCRIPTION AS LOJA, CONVERT(VARCHAR(10), SPH.END_TIMESTAMP, 120) AS DIA, COUNT(*) AS N
        FROM SYNC_PHSE_HISTORY AS SPH
        LEFT JOIN STORE AS S ON S.ID = SPH.STORE_ID
        WHERE S.DESCRIPTION <> :excl AND SPH.END_TIMESTAMP >= :d14
        GROUP BY S.CODE, S.DESCRIPTION, CONVERT(VARCHAR(10), SPH.END_TIMESTAMP, 120)", [':excl' => NAVAL_SYNC_EXCLUIR, ':d14' => date('Y-m-d', $agora - 13 * 86400)]);
    return naval_sync_montar($linhas, $dias, $agora, $lim);
}

/** Junta as linhas da base de dados com as rotas do portal (separado para os testes). */
function naval_sync_montar(array $linhas, array $dias, int $agora, array $lim): array
{
    $promotores = naval_promotores();
    $porRota = [];
    foreach ($linhas as $l) {
        $rota = naval_sync_rota((string) ($l['CODIGO'] ?? ''), (string) ($l['LOJA'] ?? ''));
        $ultima = $l['ULTIMA'] ? substr((string) $l['ULTIMA'], 0, 19) : null;
        $min = $ultima ? (int) floor(($agora - strtotime($ultima)) / 60) : null;
        $porRota[$rota] = ['rota' => $rota, 'loja' => (string) ($l['LOJA'] ?? ''), 'promotor' => $promotores[$rota] ?? '', 'ultima' => $ultima,
            'minutos' => $min !== null ? max(0, $min) : null, 'hoje' => (int) $l['HOJE'], 'sete_dias' => (int) $l['SETE_DIAS'], 'dias' => []];
    }
    foreach ($promotores as $rota => $nome) {
        if (!isset($porRota[$rota])) $porRota[$rota] = ['rota' => (string) $rota, 'loja' => '', 'promotor' => $nome, 'ultima' => null, 'minutos' => null, 'hoje' => 0, 'sete_dias' => 0, 'dias' => []];
    }
    foreach ($dias as $d) {
        $rota = naval_sync_rota((string) ($d['CODIGO'] ?? ''), (string) ($d['LOJA'] ?? ''));
        if (isset($porRota[$rota])) $porRota[$rota]['dias'][(string) $d['DIA']] = (int) $d['N'];
    }
    foreach ($porRota as $k => $r) $porRota[$k]['estado'] = naval_sync_estado($r['minutos'], $lim);
    ksort($porRota);
    return ['agora' => date('Y-m-d H:i:s', $agora), 'limites' => $lim, 'rotas' => array_values($porRota)];
}
