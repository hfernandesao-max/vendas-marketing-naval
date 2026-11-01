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

teste('sincronização: estado pelos limites, código da rota e horas desde a última sincronização', function () {
    $lim = ['alerta_horas' => 2, 'critico_horas' => 24];
    igual('em_dia', naval_sync_estado(30, $lim));
    igual('atencao', naval_sync_estado(180, $lim));
    igual('critico', naval_sync_estado(25 * 60, $lim));
    igual('sem_registo', naval_sync_estado(null, $lim));
    igual('1004', naval_sync_rota('', '1004 - FHR PIV'));
    $agora = strtotime('2026-10-26 10:00:00');
    $m = naval_sync_montar([
        ['ROTA' => '1004 - FHR PIV', 'ULTIMA_SINCRONIZACAO' => '2026-10-26 09:30:00'],
        ['ROTA' => '1001 - FHR PIV', 'ULTIMA_SINCRONIZACAO' => '2026-10-25 06:00:00'],
    ], $agora, $lim);
    igual(['1004', '1001'], array_column($m['rotas'], 'rota'));   // ordem da consulta (mais recente primeiro)
    igual('1004 - FHR PIV', $m['rotas'][0]['descricao']);
    igual(30, $m['rotas'][0]['minutos']);
    igual(0.5, $m['rotas'][0]['horas']);
    igual('em_dia', $m['rotas'][0]['estado']);
    igual(28.0, $m['rotas'][1]['horas']);
    igual('critico', $m['rotas'][1]['estado']);
});

teste('sincronização: uma única consulta, a definida pela gestão, só de leitura', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/nucleo/servicos/sincronizacao.php');
    igual(1, substr_count($src, 'naval_bd_consultar('));
    naval_bd_so_leitura(NAVAL_SYNC_SQL);
    foreach (['S.DESCRIPTION AS ROTA', 'MAX(SPH.END_TIMESTAMP)', 'FROM SYNC_PHSE_HISTORY AS SPH', 'LEFT JOIN STORE AS S', "S.DESCRIPTION <> '9999-GRUPO NAVAL FORMAÇÃO'", 'SPH.STORE_ID,', 'DESC'] as $x) {
        verdade(strpos(NAVAL_SYNC_SQL, $x) !== false, 'falta ' . $x);
    }
    $recusou = false;
    try {
        naval_bd_so_leitura('UPDATE SYNC_PHSE_HISTORY SET END_TIMESTAMP = NULL');
    } catch (RuntimeException $e) {
        $recusou = true;
    }
    verdade($recusou, 'UPDATE recusado');
});
