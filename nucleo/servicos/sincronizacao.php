<?php
/**
 * Sincronização dos smartphones RTM com o Pinguim BO (tabela SYNC_PHSE_HISTORY), só com SELECT.
 *
 * Uma única consulta, definida pela gestão: a última sincronização terminada de cada rota (loja STORE),
 * da mais recente para a mais antiga, sem a loja de formação. O portal acrescenta só o que se calcula a
 * partir dela: o código da rota (para o âmbito e os filtros), as horas desde a última sincronização e o
 * estado pelos limites do .env (SYNC_ALERTA_HORAS, por omissão 2; SYNC_CRITICO_HORAS, por omissão 24).
 */
defined('NAVAL_NUCLEO') || exit;
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/rotas.php';

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

/** Consulta da última sincronização por rota (a única que o portal faz para esta página). */
const NAVAL_SYNC_SQL = "
    SELECT
        S.DESCRIPTION AS ROTA,
        CONVERT(VARCHAR(19), MAX(SPH.END_TIMESTAMP), 120) AS ULTIMA_SINCRONIZACAO
    FROM SYNC_PHSE_HISTORY AS SPH
    LEFT JOIN STORE AS S
        ON S.ID = SPH.STORE_ID
    WHERE S.DESCRIPTION <> '9999-GRUPO NAVAL FORMAÇÃO'
    GROUP BY
        SPH.STORE_ID,
        S.DESCRIPTION
    ORDER BY
        MAX(SPH.END_TIMESTAMP) DESC";

/** Estado da sincronização de todas as rotas. Lança RuntimeException se a base de dados não responder. */
function naval_sincronizacao(?int $agora = null): array
{
    return naval_sync_montar(naval_bd_consultar(NAVAL_SYNC_SQL), $agora ?? time(), naval_sync_limites());
}

/** Linhas da consulta → rotas com horas desde a última sincronização e estado (separado para os testes). */
function naval_sync_montar(array $linhas, int $agora, array $lim): array
{
    $promotores = naval_promotores();
    $rotas = [];
    foreach ($linhas as $l) {
        $descricao = trim((string) ($l['ROTA'] ?? ''));
        $rota = naval_sync_rota('', $descricao);
        $ultima = !empty($l['ULTIMA_SINCRONIZACAO']) ? substr((string) $l['ULTIMA_SINCRONIZACAO'], 0, 19) : null;
        $min = $ultima ? max(0, (int) floor(($agora - strtotime($ultima)) / 60)) : null;
        $rotas[] = ['rota' => $rota, 'descricao' => $descricao, 'promotor' => $promotores[$rota] ?? '', 'ultima' => $ultima,
            'minutos' => $min, 'horas' => $min !== null ? round($min / 60, 1) : null, 'estado' => naval_sync_estado($min, $lim)];
    }
    // ordem da consulta: da sincronização mais recente para a mais antiga
    return ['agora' => date('Y-m-d H:i:s', $agora), 'limites' => $lim, 'rotas' => $rotas];
}
