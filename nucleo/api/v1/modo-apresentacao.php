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
} elseif ($accao === 'terminar') {
    if (!empty($_SESSION['apresentacao_ate'])) naval_registar('modo_apresentacao_terminado', ['motivo' => 'pessoa']);
    unset($_SESSION['apresentacao_ate']);
} else {
    throw new NavalErro('Acção desconhecida.', 400);
}
naval_json($estado());
