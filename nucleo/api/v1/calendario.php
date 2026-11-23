<?php
/**
 * Calendário de trabalho (nucleo/servicos/calendario.php): horário, feriados, folgas da empresa e dias de trabalho
 * excepcionais. Recurso «calendario» (V ver; G definir folgas e dias de trabalho, com motivo).
 *
 * GET  v1/calendario[?de=AAAA-MM-DD]                       horário e próximos dias fora do normal (120 dias)
 * POST {accao: definir, dia, tipo: folga|trabalho|'', motivo, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('calendario');
naval_exigir(['calendario']);
$gere = naval_pode('calendario', 'G');
$vista = function (string $de) use ($gere) {
    $hoje = naval_cal_dia(date('Y-m-d'));
    return ['ok' => true, 'gere' => $gere, 'de' => $de, 'hoje' => $hoje,
        // o horário em vigor hoje e, se já estiver decidida, a próxima mudança (com a data)
        'horario' => naval_cal_horario(date('Y-m-d')), 'horario_proximo' => naval_cal_horario_proximo(date('Y-m-d')),
        'excepcoes' => naval_cal_excepcoes($de, 120)];
};
if (!naval_e_post()) {
    $de = (string) ($_GET['de'] ?? date('Y-m-d'));
    if (!naval_data_valida($de)) throw new NavalErro('Data inválida.', 400);
    naval_json($vista($de));
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (!$gere) throw new NavalErro('O seu perfil não permite alterar o calendário.', 403);
if ((string) ($p['accao'] ?? '') !== 'definir') throw new NavalErro('Acção desconhecida.', 400);
$u = (array) naval_utilizador();
naval_cal_definir((string) ($p['dia'] ?? ''), (string) ($p['tipo'] ?? ''), (string) ($p['motivo'] ?? ''), (string) ($u['login'] ?? ''));
naval_json($vista(date('Y-m-d')));
