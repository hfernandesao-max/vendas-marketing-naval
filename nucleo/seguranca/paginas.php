<?php
/** Páginas HTML protegidas (GEO e entrada do portal): página 403 e restrições das funções de edição. */
defined('NAVAL_NUCLEO') || exit;

/** Página protegida: sem acesso ao recurso, mostra uma página 403 com ligação para a página principal. */
function naval_pagina_exigir(string $recurso, string $voltar): void
{
    if (naval_pode($recurso)) return;
    naval_registar('acesso_negado', ['recursos' => [$recurso]]);
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Sem acesso · GeoMobilidade Naval</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#06080F;color:#F5F5F7;font:15px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif">'
        . '<div style="max-width:420px;padding:24px;text-align:center"><h1 style="font-size:22px;margin:0 0 8px">Sem acesso a esta página</h1>'
        . '<p style="color:#A1A1AA;margin:0 0 20px">O seu perfil (' . htmlspecialchars(NAVAL_PERFIS[naval_perfil()] ?? naval_perfil()) . ') não inclui esta área. Se precisa dela, peça o acesso ao responsável da sua área.</p>'
        . '<a href="' . htmlspecialchars($voltar) . '" style="display:inline-block;padding:10px 18px;border-radius:999px;background:#0A84FF;color:#fff;text-decoration:none;font-weight:600">Voltar à página principal</a></div></body></html>';
    exit;
}

/**
 * Funções de edição do GEO que o perfil não pode usar: esconde os botões e desactiva as funções
 * no navegador (mostram um aviso). Devolve o HTML a colocar antes de </body>.
 */
function naval_geo_restricoes(array $grupos): string
{
    $funcoes = [];
    foreach ($grupos as $recurso => $nomes) {
        $minimo = strpos($recurso, 'descarregar') !== false ? 'Er' : 'G';
        $ok = $minimo === 'G' ? naval_pode($recurso, 'G') : naval_pode_exportar($recurso);
        if (!$ok) $funcoes = array_merge($funcoes, $nomes);
    }
    if (!$funcoes) return '';
    $css = [];
    foreach ($funcoes as $f) {
        if ($f[0] === '#') $css[] = $f;
        else $css[] = '[onclick^="' . $f . '"]';
    }
    $js = array_values(array_filter($funcoes, function ($f) { return $f[0] !== '#'; }));
    return '<style>' . implode(',', $css) . '{display:none!important}</style>'
        . '<script>(function(){var n=' . json_encode($js) . ';n.forEach(function(f){window[f]=function(){alert("O seu perfil não permite esta acção.");};});})();</script>';
}
