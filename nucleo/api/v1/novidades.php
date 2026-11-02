<?php
/**
 * Resumo das actualizações do portal (só o super administrador; ver nucleo/servicos/novidades.php).
 *
 * GET:  {ok, actualizacoes: [{id, detectada_em, versao, seccoes: [{id, data, titulo, itens}]}]}  as que ainda não fechou
 * POST {csrf, vistas: [id, ...]}: marca-as como vistas (a janela não volta a aparecer)
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('novidades');

$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
if (($u['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador vê o resumo das actualizações.', 403);
$GLOBALS['naval_api_sensivel'] = true;   // depende do que esta conta já viu: nunca em cache

if (!naval_e_post()) naval_json(['ok' => true, 'actualizacoes' => naval_nov_pendentes((string) $u['login'])]);

$p = naval_pedido();
naval_exigir_csrf($p);
$ids = is_array($p['vistas'] ?? null) ? array_slice($p['vistas'], 0, 50) : [];
naval_json(['ok' => true, 'marcadas' => naval_nov_marcar((string) $u['login'], $ids)]);
