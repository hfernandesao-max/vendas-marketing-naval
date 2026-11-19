<?php
/**
 * Ocorrências do terreno (nucleo/servicos/ocorrencias.php).
 *
 * GET  v1/ocorrencias[?data=AAAA-MM-DD]       recurso «ocorrencias» (Vr: só as suas rotas) → {ok, dia, ocorrencias, tipos, abertas, trata}
 * POST v1/ocorrencias {id, accao, nota, ate, data, csrf}    tratar (assumir, resolver, silenciar, reabrir, largar):
 *      nível G, ou o supervisor (Vr) nas ocorrências das suas rotas; quem só vê (TI, Auditoria) não trata.
 */
defined('NAVAL_API') || exit;
naval_servico('ocorrencias');
naval_exigir(['ocorrencias']);

$rotas = naval_rotas();
$trata = naval_pode('ocorrencias', 'G') || naval_nivel('ocorrencias') === 'Vr';
$dia = (string) ($_GET['data'] ?? '');
if (naval_e_post()) {
    $p = naval_pedido();
    naval_exigir_csrf($p);
    $dia = (string) ($p['data'] ?? '');
}
if ($dia === '') $dia = date('Y-m-d');
if (!naval_data_valida($dia) || $dia > date('Y-m-d')) throw new NavalErro('Dia inválido (AAAA-MM-DD, até hoje).', 400);

$ler = function () use ($dia, $rotas) {
    return naval_cache('ocorrencias|' . $dia . '|' . json_encode($rotas), 300, function () use ($dia, $rotas) { return naval_oco_lista($dia, $rotas); });
};

if (naval_e_post()) {
    if (!$trata) {
        naval_registar('alteracao_recusada', ['accao' => 'ocorrencia', 'motivo' => 'perfil']);
        throw new NavalErro('Sem permissão para tratar ocorrências.', 403);
    }
    $id = (string) ($p['id'] ?? '');
    // só se trata uma ocorrência que a pessoa vê (no seu âmbito e nesse dia)
    if (strpos($id, 'oco|') !== 0 || !in_array($id, array_column($ler()['ocorrencias'], 'id'), true)) throw new NavalErro('Ocorrência desconhecida.', 404);
    $eu = (array) naval_utilizador();
    $reg = naval_alt_tratar($id, (string) ($p['accao'] ?? ''), (string) ($p['nota'] ?? ''), isset($p['ate']) ? (string) $p['ate'] : null, (string) ($eu['login'] ?? ''), time());
    naval_json(['ok' => true, 'tratamento' => $reg]);
}
$r = $ler();
// o tratamento muda mais depressa do que a cópia de 5 minutos: aplica-se sempre o registo actual
$r['ocorrencias'] = naval_alt_aplicar($r['ocorrencias'], time());
$r['abertas'] = count(array_filter($r['ocorrencias'], function ($o) { return $o['activo']; }));
naval_json(['ok' => true, 'trata' => $trata] + $r);
