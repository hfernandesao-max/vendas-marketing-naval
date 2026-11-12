<?php
/**
 * Notificações no telemóvel (nucleo/servicos/notificacoes.php).
 *
 * GET  v1/notificacoes[?endpoint=…]                       chave pública, tipos que a pessoa pode receber, preferências,
 *                                                         caixa (50 últimas) e se este telefone está subscrito
 * POST {accao: subscrever, subscricao: {endpoint, keys}}  liga as notificações neste telefone
 * POST {accao: cancelar, endpoint}                        desliga neste telefone
 * POST {accao: preferencias, tipos: {tipo: bool}, silencio: {de, ate} | false}
 * POST {accao: testar}                                    envia uma notificação de teste a todos os telefones da pessoa
 * POST {accao: lidas}                                     marca a caixa como lida (o número no ícone da app volta a 0)
 * Todas as acções com o código CSRF da sessão; cada pessoa só mexe nas suas subscrições.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('notificacoes');
$u = naval_utilizador();
if (!$u) throw new NavalErro('Sessão terminada.', 401);
$GLOBALS['naval_api_sensivel'] = true;
$login = (string) $u['login'];

if (!naval_e_post()) naval_json(['ok' => true] + naval_notif_vista($u, (string) ($_GET['endpoint'] ?? '')));
$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
$endpoint = '';
if ($accao === 'subscrever') {
    $s = naval_notif_subscrever($login, (array) ($p['subscricao'] ?? []), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $endpoint = $s['endpoint'];
} elseif ($accao === 'cancelar') {
    naval_notif_cancelar($login, (string) ($p['endpoint'] ?? ''));
} elseif ($accao === 'preferencias') {
    naval_notif_gravar_preferencias($login, $p);
    $endpoint = (string) ($p['endpoint'] ?? '');
} elseif ($accao === 'testar') {
    $subs = array_filter(naval_notif_subscricoes(), function ($s) use ($login) { return $s['login'] === $login; });
    if (!$subs) throw new NavalErro('Este telefone ainda não tem as notificações ligadas.', 409);
    $msg = ['id' => substr(bin2hex(random_bytes(8)), 0, 16), 'tipo' => 'teste', 'titulo' => 'Notificações ligadas', 'corpo' => 'Vai receber aqui os alertas do Portal RTM.',
        'url' => '../gestao/#alertas', 'tag' => 'teste', 'gravidade' => 'info', 'data' => date('Y-m-d H:i:s')];
    $ok = 0;
    foreach ($subs as $s) {
        $c = naval_webpush_entregar($s, $msg + ['nao_lidas' => 0], time());
        if ($c >= 200 && $c < 300) $ok++;
    }
    if (!$ok) throw new NavalErro('O serviço de notificações do telefone não aceitou a mensagem. Volte a ligar as notificações.', 502);
    $endpoint = (string) ($p['endpoint'] ?? '');
} elseif ($accao === 'lidas') {
    naval_notif_marcar_lidas($login);
    $endpoint = (string) ($p['endpoint'] ?? '');
} else {
    throw new NavalErro('Acção desconhecida.', 400);
}
naval_json(['ok' => true] + naval_notif_vista($u, $endpoint));
