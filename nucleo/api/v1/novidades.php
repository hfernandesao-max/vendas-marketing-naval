<?php
/**
 * Actualizações do portal (ver nucleo/servicos/novidades.php).
 *
 * GET:  {ok, avisos: [...]}  para todos: o aviso de cada actualização que lhe diz respeito e ainda não confirmou
 *       o super administrador recebe também {actualizacoes: [...]} (resumo completo por fechar) e, com ?adesao=1,
 *       {adesao: [...]} (quem já tomou conhecimento de cada aviso)
 * POST {csrf, vistas: [id, ...]}     super administrador: o resumo não volta a aparecer
 *      {csrf, confirmar: [id, ...]}  «Li e compreendi»: regista a tomada de conhecimento
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('novidades');

$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
$sadm = ($u['perfil'] ?? '') === 'SADM';
$login = (string) $u['login'];
$GLOBALS['naval_api_sensivel'] = true;   // depende do que esta conta já viu: nunca em cache

if (!naval_e_post()) {
    // todos: os avisos por confirmar que lhe dizem respeito; o super administrador também o resumo completo e a adesão
    $r = ['ok' => true, 'avisos' => naval_nov_avisos($login)];
    if ($sadm) $r += ['actualizacoes' => naval_nov_pendentes($login), 'adesao' => ($_GET['adesao'] ?? '') === '1' ? naval_nov_adesao() : null];
    naval_json($r);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$conf = is_array($p['confirmar'] ?? null) ? array_slice($p['confirmar'], 0, 50) : [];
$ids = is_array($p['vistas'] ?? null) ? array_slice($p['vistas'], 0, 50) : [];
if ($ids && !$sadm) throw new NavalErro('Só o super administrador vê o resumo das actualizações.', 403);
naval_json(['ok' => true, 'marcadas' => $ids ? naval_nov_marcar($login, $ids) : 0, 'confirmadas' => $conf ? naval_nov_confirmar($login, $conf) : 0]);
