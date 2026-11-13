<?php
/**
 * Painel da carrinha (nucleo/servicos/carrinha.php, naval_carr_painel): por rota e dia, o fecho de rota (conciliação),
 * a carga e as ligações a outros sistemas. Só leitura; contar e aprovar fazem-se na Conciliação da carrinha.
 *
 * GET v1/painel-carrinha?data=AAAA-MM-DD[&actualizar=1]   recurso «conciliacao-carrinha» (Vr: só as suas rotas)
 */
defined('NAVAL_API') || exit;
naval_servico('carrinha');
naval_exigir(['conciliacao-carrinha']);
$data = (string) ($_GET['data'] ?? date('Y-m-d'));
if (!naval_data_valida($data) || $data > date('Y-m-d')) throw new NavalErro('Data inválida.', 400);
naval_json_assinado(['ok' => true] + naval_carr_painel($data, naval_rotas(), naval_pode('conciliacao-carrinha', 'G'), ($_GET['actualizar'] ?? '') === '1'));
