<?php
/**
 * Números dos contadores do menu do Terreno (o que pede acção), só das páginas que a pessoa vê e no seu âmbito:
 *
 * GET v1/terreno/contagens   → {ok, contagens: {'rotas-propostas': pendentes, ocorrencias: abertas hoje}}
 */
defined('NAVAL_API') || exit;
$rotas = naval_rotas();
$c = [];
if (naval_pode('rotas-propostas')) {
    naval_servico('rotas-propostas');
    $c['rotas-propostas'] = (int) naval_rp_vista($rotas)['pendentes'];
}
if (naval_pode('ocorrencias')) {
    naval_servico('ocorrencias');
    $dia = date('Y-m-d');
    try {
        $l = naval_cache('ocorrencias|' . $dia . '|' . json_encode($rotas), 300, function () use ($dia, $rotas) { return naval_oco_lista($dia, $rotas); });
        $c['ocorrencias'] = count(array_filter(naval_alt_aplicar($l['ocorrencias'], time()), function ($o) { return $o['activo']; }));
    } catch (Throwable $e) {
        error_log('[contagens ocorrências] ' . $e->getMessage());
    }
}
naval_json(['ok' => true, 'contagens' => (object) $c]);
