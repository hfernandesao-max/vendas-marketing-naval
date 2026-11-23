<?php
/**
 * Facturas fora do horário de vendas (nucleo/servicos/fora-horario.php): lista, indicador por promotor e autorizações.
 * Recursos «conciliacao-carrinha» ou «alertas» (Vr: só as suas rotas); autorizar: o coordenador
 * («conciliacao-coordenacao» G).
 *
 * GET  v1/fora-horario?de=AAAA-MM-DD&ate=AAAA-MM-DD          até 62 dias (por omissão os últimos 30)
 * POST {accao: autorizar, rota, dia, ate (HH:MM), motivo, csrf}
 */
defined('NAVAL_API') || exit;
naval_servico('fora-horario');
naval_exigir(['conciliacao-carrinha', 'alertas']);
$coordena = naval_pode('conciliacao-coordenacao', 'G');
$rotas = naval_rotas();
$filtrar = function (array $l) use ($rotas) {
    return $rotas === null ? $l : array_values(array_filter($l, function ($x) use ($rotas) { return in_array((string) $x['rota'], $rotas, true); }));
};
$vista = function (string $de, string $ate) use ($filtrar, $coordena) {
    $l = naval_fh_periodo($de, $ate);
    if ($l === null) throw new NavalErro('Sem ligação ao Pinguim BO.', 503);
    $l = $filtrar($l);
    naval_servico('calendario');
    return ['ok' => true, 'de' => $de, 'ate' => $ate, 'pode_autorizar' => $coordena, 'lista' => $l, 'por_promotor' => naval_fh_por_promotor($l),
        'autorizacoes' => $filtrar(naval_fh_autorizacoes($de, date('Y-m-d', strtotime($ate . ' 12:00') + 7 * 86400))),
        'horario_hoje' => naval_cal_dia(date('Y-m-d'))['vendas'], 'tolerancia' => max(0, (int) naval_config('FORA_HORARIO_TOLERANCIA', '10'))];
};
if (!naval_e_post()) {
    $ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
    $de = (string) ($_GET['de'] ?? date('Y-m-d', time() - 29 * 86400));
    if (!naval_data_valida($de) || !naval_data_valida($ate) || $de > $ate) throw new NavalErro('Intervalo de datas inválido.', 400);
    if ((strtotime($ate) - strtotime($de)) / 86400 > 62) throw new NavalErro('O período máximo é de 62 dias.', 400);
    @set_time_limit(120);
    naval_json($vista($de, $ate));
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (!$coordena) throw new NavalErro('Só o coordenador autoriza vendas fora do horário.', 403);
if ((string) ($p['accao'] ?? '') !== 'autorizar') throw new NavalErro('Acção desconhecida.', 400);
$rota = (string) ($p['rota'] ?? '');
if (!naval_rota_permitida($rota)) throw new NavalErro('Sem acesso a esta rota.', 403);
$u = (array) naval_utilizador();
$a = naval_fh_autorizar($rota, (string) ($p['dia'] ?? ''), (string) ($p['ate'] ?? ''), (string) ($p['motivo'] ?? ''), (string) ($u['login'] ?? ''));
naval_json(['ok' => true, 'autorizacao' => $a]);
