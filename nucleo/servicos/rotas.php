<?php
/** Rotas do portal (reports/data/rotas.json): número da rota e promotor. */
defined('NAVAL_NUCLEO') || exit;

/** Lista de rotas [{rota, promotor}], com a rota como texto. */
function naval_rotas_portal(): array
{
    $out = [];
    foreach (naval_json_ler(naval_dados_reports() . '/rotas.json') as $r) {
        if (!is_array($r) || !isset($r['rota'])) continue;
        $out[] = ['rota' => (string) $r['rota'], 'promotor' => (string) ($r['promotor'] ?? '')];
    }
    return $out;
}

/** Promotor de cada rota: {rota: promotor}. */
function naval_promotores(): array
{
    $m = [];
    foreach (naval_rotas_portal() as $r) if ($r['promotor'] !== '') $m[$r['rota']] = $r['promotor'];
    return $m;
}

/** Versão das rotas (muda quando um promotor ou uma rota muda): entra nas chaves das caches com nomes de promotores. */
function naval_rotas_versao(): string
{
    $f = naval_dados_reports() . '/rotas.json';
    return is_file($f) ? (string) filemtime($f) : '0';
}

/**
 * Rotas e promotores a partir de linhas STORE/ENTITY do Pinguim BO ({ROTA, DESCRICAO}): a descrição
 * «1001 - FHR PIV - Andrea Santos» dá o promotor na última parte; sem ela, fica o número da rota.
 */
function naval_rotas_de_pinguim(array $linhas): array
{
    $out = [];
    foreach ($linhas as $r) {
        $rota = trim((string) ($r['ROTA'] ?? ''));
        if (!preg_match('/^10\d\d$/', $rota) || isset($out[$rota])) continue;
        $partes = array_map('trim', explode(' - ', trim((string) ($r['DESCRICAO'] ?? ''))));
        $p = count($partes) >= 3 ? (string) end($partes) : '';
        $out[$rota] = ['chave' => $rota, 'rota' => $rota, 'promotor' => $p !== '' ? $p : $rota];
    }
    ksort($out);
    return array_values($out);
}

/**
 * Sincronização automática (rotina de 5 em 5 minutos): rotas e promotores lidos do Pinguim BO (só SELECT). Rotas novas
 * entram sozinhas; mudança de nome fica registada e todas as tabelas passam a mostrar o nome novo (as caches que levam
 * nomes usam naval_rotas_versao). Sem ligação, fica a lista anterior. Devolve as mudanças [{rota, antes, depois}].
 * $linhas (testes) substitui a consulta.
 */
function naval_rotas_sincronizar(?array $linhas = null): array
{
    if ($linhas === null) {
        if (naval_ensaio() || !function_exists('naval_servico')) return [];
        naval_servico('bd');
        if (!naval_bd_configurada()) return [];
        try {
            $linhas = naval_bd_consultar("
                SELECT S.CODE AS ROTA, COALESCE(NULLIF(E.DESCRIPTION, ''), S.DESCRIPTION) AS DESCRICAO
                FROM STORE S
                LEFT JOIN ENTITY E ON E.CODE = S.CODE AND E.DELETED = 0
                WHERE S.DELETED = 0 AND S.CODE LIKE '10[0-9][0-9]'
                ORDER BY S.CODE");
        } catch (Throwable $e) {
            error_log('[rotas] ' . $e->getMessage());
            return [];
        }
    }
    $novas = naval_rotas_de_pinguim($linhas);
    if (!$novas) return [];
    $antes = naval_promotores();
    $mud = [];
    foreach ($novas as $r) {
        if (($antes[$r['rota']] ?? null) !== $r['promotor']) $mud[] = ['rota' => $r['rota'], 'antes' => $antes[$r['rota']] ?? null, 'depois' => $r['promotor']];
    }
    $rotasAntes = array_map(function ($x) { return $x['rota']; }, naval_rotas_portal());
    $saiu = array_diff($rotasAntes, array_column($novas, 'rota'));
    if (!$mud && !$saiu) return [];
    naval_json_gravar(naval_dados_reports() . '/rotas.json', $novas);
    if (function_exists('naval_registar')) foreach ($mud as $m) naval_registar('promotor_actualizado', $m);
    return $mud;
}

/** Só as rotas a que o utilizador tem acesso (os elementos têm o campo «rota»). */
function naval_filtrar_rotas(array $lista): array
{
    $ok = naval_rotas();
    if ($ok === null) return array_values($lista);
    return array_values(array_filter($lista, function ($x) use ($ok) { return in_array((string) ($x['rota'] ?? ''), $ok, true); }));
}
