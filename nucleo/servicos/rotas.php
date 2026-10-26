<?php
/** Rotas do portal (reports/data/rotas.json): número da rota e promotor. */
defined('NAVAL_NUCLEO') || exit;

/** Lista de rotas [{rota, promotor}], com a rota como texto. */
function naval_rotas_portal(): array
{
    $out = [];
    foreach (naval_json_ler(naval_raiz() . '/reports/data/rotas.json') as $r) {
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

/** Só as rotas a que o utilizador tem acesso (os elementos têm o campo «rota»). */
function naval_filtrar_rotas(array $lista): array
{
    $ok = naval_rotas();
    if ($ok === null) return array_values($lista);
    return array_values(array_filter($lista, function ($x) use ($ok) { return in_array((string) ($x['rota'] ?? ''), $ok, true); }));
}
