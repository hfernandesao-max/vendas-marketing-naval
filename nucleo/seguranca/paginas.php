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
        . '<p style="color:#A1A1AA;margin:0 0 20px">O seu perfil (' . htmlspecialchars(naval_perfil_nome(naval_perfil())) . ') não inclui esta área. Se precisa dela, peça o acesso ao responsável da sua área.</p>'
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

/**
 * Script do Google Maps com a chave do .env (GOOGLE_MAPS_API_KEY), nunca escrita no código.
 * A chave de navegador fica visível na página (é assim que o Google Maps funciona): tem de estar
 * restringida na Google Cloud Console aos endereços do portal (HTTP referrers) e só à Maps JavaScript API.
 * $parametros: outros parâmetros do URL (por exemplo ['libraries' => 'drawing']).
 */
function naval_google_maps_script(array $parametros = []): string
{
    $chave = naval_config('GOOGLE_MAPS_API_KEY');
    if ($chave === '') {
        error_log('[geo] GOOGLE_MAPS_API_KEY em falta no .env: o mapa não carrega.');
        return '<script>console.error("Mapa indisponível: falta GOOGLE_MAPS_API_KEY no .env do servidor.");</script>';
    }
    $url = 'https://maps.googleapis.com/maps/api/js?' . http_build_query(['key' => $chave, 'v' => '3.64'] + $parametros + ['callback' => 'initMap'], '', '&', PHP_QUERY_RFC3986);
    return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
