<?php
/**
 * Modo apresentação (recurso «modo-apresentacao», G): a pessoa activa-o antes de apresentar o portal e a app num
 * projector ou LCD. Por um período escolhido (30 min a 4 h), a sessão termina só após 60 minutos sem actividade, o
 * portal não volta sozinho ao painel e a app pode ver-se no ecrã do computador. Começo e fim ficam registados
 * (ISO/IEC 27001 A.8.15); acaba sozinho à hora marcada, ao terminar a sessão ou quando a pessoa o termina.
 *
 * GET  v1/modo-apresentacao                       estado: {activo, ate, minutos}
 * POST {accao: iniciar, minutos: 30|60|120|180|240, csrf}
 * POST {accao: terminar, csrf}
 * POST {accao: chamar, csrf}   avisa o telemóvel da pessoa (notificação) para seguir a apresentação; um toque abre a
 *                              app já no mesmo ecrã. Nada se envia se o telemóvel já estiver a seguir.
 */
defined('NAVAL_API') || exit;
naval_exigir(['modo-apresentacao']);
$estado = function (): array {
    $ate = naval_apresentacao_ate();
    return ['ok' => true, 'activo' => $ate > 0, 'ate' => $ate ?: null, 'inactiva_s' => naval_sessao_inactiva_s(), 'minutos' => NAVAL_APRESENTACAO_MINUTOS];
};
if (!naval_e_post()) naval_json($estado());

$p = naval_pedido();
naval_exigir_csrf($p);
if (!naval_pode('modo-apresentacao', 'G')) throw new NavalErro('O seu perfil não permite o modo apresentação.', 403);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'iniciar') {
    $min = (int) ($p['minutos'] ?? 0);
    if (!in_array($min, NAVAL_APRESENTACAO_MINUTOS, true)) throw new NavalErro('Escolha a duração da apresentação.', 400);
    // nunca para além da duração máxima da sessão (10 horas desde a entrada)
    $ate = min(time() + $min * 60, (int) ($_SESSION['login_time'] ?? time()) + NAVAL_SESSAO_MAXIMA);
    $_SESSION['apresentacao_ate'] = $ate;
    naval_registar('modo_apresentacao_iniciado', ['minutos' => $min, 'ate' => date('Y-m-d H:i', $ate)]);
    naval_servico('espelho');
    naval_espelho_activar((string) (((array) naval_utilizador())['login'] ?? ''), $ate);
} elseif ($accao === 'terminar') {
    if (!empty($_SESSION['apresentacao_ate'])) naval_registar('modo_apresentacao_terminado', ['motivo' => 'pessoa']);
    unset($_SESSION['apresentacao_ate']);
    naval_servico('espelho');
    naval_espelho_activar((string) (((array) naval_utilizador())['login'] ?? ''), 0);
} elseif ($accao === 'chamar') {
    if (!naval_apresentacao_ate()) throw new NavalErro('O modo apresentação não está activo.', 409);
    $login = (string) (((array) naval_utilizador())['login'] ?? '');
    naval_servico('espelho');
    if (naval_espelho_telemovel_a_seguir($login)) naval_json($estado() + ['telemovel' => 'a-seguir']);
    // um aviso a cada 30 segundos, no máximo
    if ((int) ($_SESSION['apresentacao_chamada'] ?? 0) > time() - 30) naval_json($estado() + ['telemovel' => 'avisado']);
    $_SESSION['apresentacao_chamada'] = time();
    naval_servico('notificacoes');
    $n = naval_notif_pessoal($login, 'Apresentação no computador', 'Toque para mostrar no telemóvel o mesmo ecrã da apresentação.', [
        'url' => '../gestao/', 'tag' => 'apresentacao',
        'textos' => [
            'en' => ['Presentation on the computer', 'Tap to show the same screen as the presentation on your phone.'],
            'ti' => ['ምቕራብ ኣብ ኮምፒተር', 'ኣብ ሞባይልካ ከምቲ ምቕራብ ሓደ ዓይነት ስክሪን ንምርኣይ ጠውቕ።'],
        ],
    ]);
    naval_registar('modo_apresentacao_telemovel', ['entregues' => $n]);
    naval_json($estado() + ['telemovel' => $n > 0 ? 'avisado' : 'sem-notificacoes']);
} else {
    throw new NavalErro('Acção desconhecida.', 400);
}
naval_json($estado());
