<?php
/**
 * App no telemóvel: código QR pessoal para instalar, equipamento activo e, para quem gere os utilizadores, a lista de
 * todos os equipamentos registados. Regras: nucleo/servicos/app-telemovel.php.
 *
 * GET  v1/app-telemovel                              {meu, app, nome, nesta_app, lista?, resumo?}
 * POST v1/app-telemovel {accao:'codigo', csrf}       novo código QR (10 minutos, uso único)
 * POST v1/app-telemovel {accao:'desactivar', id, csrf}  desactiva um equipamento (o próprio ou, com a gestão de utilizadores, qualquer um)
 */
defined('NAVAL_API') || exit;
$GLOBALS['naval_api_sensivel'] = true;
naval_servico('app-telemovel');
$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
$login = (string) $eu['login'];
$admin = naval_pode('admin-utilizadores');

if (!naval_e_post()) {
    $lista = naval_app_lista();
    $meu = null;
    foreach ($lista as $x) if ($x['login'] === $login && $x['estado'] === 'activo' && naval_app_activo($x['id'])) $meu = naval_app_resumo($x);
    $app = naval_app_da_conta();
    $r = ['ok' => true, 'meu' => $meu, 'app' => $app, 'nome' => NAVAL_APP_NOMES[$app], 'nesta_app' => !empty($_SESSION['app_equipamento'])];
    if ($admin) {
        $nomes = [];
        foreach (naval_utilizadores() as $l => $u) $nomes[$l] = (string) ($u['nome'] ?? $l);
        $l = array_map(function ($x) use ($nomes) { return naval_app_resumo($x) + ['nome' => $nomes[$x['login']] ?? $x['login']]; }, array_values($lista));
        usort($l, function ($a, $b) { return [$a['estado'] !== 'activo', $b['activado']] <=> [$b['estado'] !== 'activo', $a['activado']]; });
        $r['lista'] = $l;
        $r['resumo'] = ['activos' => count(array_filter($l, function ($x) { return $x['estado'] === 'activo'; })), 'registados' => count($l),
            'pessoas' => count(array_unique(array_column($l, 'login')))];
    }
    naval_json($r);
}
$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'codigo') {
    naval_json(['ok' => true] + naval_app_codigo_novo($login, naval_app_da_conta()));
}
if ($accao === 'desactivar') {
    $id = (string) ($p['id'] ?? '');
    $x = naval_app_lista()[$id] ?? null;
    if (!$x || ($x['login'] !== $login && !$admin)) throw new NavalErro('Sem permissão para este equipamento.', 403);
    if (!naval_app_desactivar($id, $login, $x['login'] === $login ? 'pelo_proprio' : 'pelo_administrador')) throw new NavalErro('Este equipamento já não está activo.', 409);
    naval_json(['ok' => true]);
}
throw new NavalErro('Acção desconhecida.', 400);
