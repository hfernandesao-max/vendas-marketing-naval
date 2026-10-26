<?php
// Activação da gestão de contas no portal (passagem da lista antiga para a lista nova) e sincronização.
naval_servico('gestao');
naval_servico('sincronizacao');

teste('activar a gestão: contas antigas passam para a lista nova; quem activa fica SADM', function () {
    $f = naval_ficheiro_utilizadores();
    @unlink($f);
    $antigas = naval_utilizadores();
    verdade(!empty($antigas['admin']['compatibilidade']), 'lista antiga em compatibilidade');
    $eu = $antigas['admin'] + ['login' => 'admin'];
    verdade(naval_gestao_pode_activar($eu));
    verdade(!naval_gestao_pode_activar($antigas['michael.samson'] + ['login' => 'michael.samson']), 'só TI ou SADM activa');

    $ok = ['admin' => ['email' => 'admin@naval.co.ao'], 'michael.samson' => ['email' => 'michael.samson@naval.co.ao', 'perfil' => 'DIR', 'activo' => true]];
    // controlo dual, email obrigatório e único, rotas nos perfis por rota
    lanca(400, function () use ($eu, $ok) { naval_gestao_activar(['contas' => $ok, 'aprovado_por' => ['Uma Pessoa']], $eu); });
    lanca(400, function () use ($eu, $ok) {
        $c = $ok; $c['michael.samson']['email'] = '';
        naval_gestao_activar(['contas' => $c, 'aprovado_por' => ['A', 'B']], $eu);
    });
    lanca(400, function () use ($eu, $ok) {
        $c = $ok; $c['michael.samson']['email'] = 'ADMIN@naval.co.ao';
        naval_gestao_activar(['contas' => $c, 'aprovado_por' => ['A', 'B']], $eu);
    });
    lanca(400, function () use ($eu, $ok) {
        $c = $ok; $c['michael.samson']['perfil'] = 'SUP';
        naval_gestao_activar(['contas' => $c, 'aprovado_por' => ['A', 'B']], $eu);
    });
    verdade(!is_file($f), 'nada gravado enquanto houver erros');

    $r = naval_gestao_activar(['contas' => $ok, 'aprovado_por' => ['Pessoa A', 'Pessoa B']], $eu);
    igual(2, $r['contas']);
    $d = json_decode((string) file_get_contents($f), true);
    igual('SADM', $d['admin']['perfil']);
    igual('DIR', $d['michael.samson']['perfil']);
    igual($antigas['admin']['senha'], $d['admin']['senha'], 'palavra-passe mantida');
    verdade(naval_conta_valida($d['michael.samson']));
    verdade(!isset($d['admin']['compatibilidade']));
    // uma só vez
    verdade(!naval_gestao_pode_activar($eu));
    unlink($f);
});

teste('sincronização: estado pelos limites, código da rota e montagem com as rotas do portal', function () {
    $lim = ['alerta_horas' => 2, 'critico_horas' => 24];
    igual('em_dia', naval_sync_estado(30, $lim));
    igual('atencao', naval_sync_estado(180, $lim));
    igual('critico', naval_sync_estado(25 * 60, $lim));
    igual('sem_registo', naval_sync_estado(null, $lim));
    igual('1004', naval_sync_rota('', '1004 - FHR PIV'));
    $agora = strtotime('2026-10-26 10:00:00');
    $m = naval_sync_montar(
        [['CODIGO' => '', 'LOJA' => '1004 - FHR PIV', 'ULTIMA' => '2026-10-26 09:30:00', 'HOJE' => 3, 'SETE_DIAS' => 20]],
        [['CODIGO' => '', 'LOJA' => '1004 - FHR PIV', 'DIA' => '2026-10-26', 'N' => 3]],
        $agora, $lim);
    $r = array_values(array_filter($m['rotas'], function ($x) { return $x['rota'] === '1004'; }))[0];
    igual(30, $r['minutos']);
    igual('em_dia', $r['estado']);
    igual(['2026-10-26' => 3], $r['dias']);
    foreach ($m['rotas'] as $x) if ($x['rota'] !== '1004') igual('sem_registo', $x['estado']);
});

teste('sincronização: as consultas passam a guarda de só leitura (só SELECT)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/nucleo/servicos/sincronizacao.php');
    preg_match_all('/naval_bd_consultar\("(.*?)"/s', $src, $m);
    igual(2, count($m[1]));
    foreach ($m[1] as $sql) naval_bd_so_leitura($sql);
    $recusou = false;
    try {
        naval_bd_so_leitura('UPDATE SYNC_PHSE_HISTORY SET END_TIMESTAMP = NULL');
    } catch (RuntimeException $e) {
        $recusou = true;
    }
    verdade($recusou, 'UPDATE recusado');
});
