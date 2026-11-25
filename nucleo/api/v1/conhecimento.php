<?php
/**
 * Base de conhecimento do portal (nucleo/servicos/conhecimento.php): só o que diz respeito às páginas que a pessoa
 * pode ver; o funcionamento interno sem página só para a administração do sistema (SADM).
 *
 * GET v1/conhecimento?q=texto[&pagina=id][&tipo=pagina|novidade|regra|funcionamento]  → {ok, artigos: [...]}
 * GET v1/conhecimento?pagina=id                                                      → {ok, pagina, novidades, regras, funcionamento}
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('conhecimento');
$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
$admin = ($eu['perfil'] ?? '') === 'SADM';
$base = naval_conh_base();
$artigos = naval_conh_visiveis($base['artigos'], 'naval_conh_ve_pagina', $admin);
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
$pagina = preg_match('/^[a-z0-9-]{2,40}$/', (string) ($_GET['pagina'] ?? '')) ? (string) $_GET['pagina'] : null;
if ($pagina !== null && !naval_conh_ve_pagina($pagina)) $pagina = null;
$tipo = in_array($_GET['tipo'] ?? '', ['pagina', 'novidade', 'regra', 'funcionamento'], true) ? [(string) $_GET['tipo']] : [];
if ($q === '') {
    if ($pagina === null) throw new NavalErro('Indique o que procura.', 400);
    naval_json(['ok' => true, 'gerada_em' => $base['gerada_em'] ?? null] + naval_conh_pagina($artigos, $pagina));
}
naval_json(['ok' => true, 'artigos' => naval_conh_procurar($artigos, $q, $pagina, 10, $tipo)]);
