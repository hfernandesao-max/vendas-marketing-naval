<?php
/**
 * Radar do dia (nucleo/servicos/radar.php): sugestões de visitas a juntar ao dia de cada rota dentro da capacidade
 * (recuperar clientes parados e conquistar potenciais clientes perto do caminho) e as que adiar quando o dia passa a
 * capacidade.
 *
 * GET v1/geo/radar[?dia=AAAA-MM-DD]   recurso «geo-prospeccao» (Vr: só as suas rotas)
 */
defined('NAVAL_API') || exit;
naval_exigir(['geo-prospeccao']);
naval_servico('radar');
$dia = (string) ($_GET['dia'] ?? date('Y-m-d'));
if (!naval_data_valida($dia) || $dia < date('Y-m-d', time() - 7 * 86400) || $dia > date('Y-m-d', time() + 14 * 86400)) {
    throw new NavalErro('Dia inválido: de 7 dias atrás a 14 dias à frente.', 400);
}
naval_json(['ok' => true] + naval_radar_dia($dia, naval_rotas()));
