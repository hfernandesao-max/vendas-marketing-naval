<?php
/**
 * Vistas guardadas por cada pessoa (ordem dos gráficos do painel principal arrastados no ecrã).
 *
 * GET  v1/vistas                              {vistas: {pagina: [chaves dos gráficos, por ordem]}}
 * POST v1/vistas {pagina, ordem|null, csrf}   guarda a ordem (null repõe a ordem de origem)
 * Sem guardar, a ordem arrastada vale só até ao fim da sessão (fica no navegador, presa ao momento da entrada).
 */
defined('NAVAL_API') || exit;
$GLOBALS['naval_api_sensivel'] = true;
$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
$f = naval_pasta('vistas') . '/' . hash('sha256', strtolower((string) $eu['login'])) . '.json';
$vistas = naval_json_ler($f);
if (!naval_e_post()) naval_json(['ok' => true, 'vistas' => (object) $vistas]);
$p = naval_pedido();
naval_exigir_csrf($p);
$pagina = (string) ($p['pagina'] ?? '');
if (!in_array($pagina, ['inicio'], true)) throw new NavalErro('Página desconhecida.', 400);
if (($p['ordem'] ?? null) === null) unset($vistas[$pagina]);
else {
    $ordem = [];
    foreach (array_slice((array) $p['ordem'], 0, 30) as $k) if (is_string($k) && preg_match('/^[a-zA-Z]{1,30}$/', $k)) $ordem[] = $k;
    $vistas[$pagina] = array_values(array_unique($ordem));
}
naval_json_gravar($f, $vistas);
naval_json(['ok' => true, 'vistas' => (object) $vistas]);
