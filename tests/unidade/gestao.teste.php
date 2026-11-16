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

teste('dispensa de dois passos: por conta, com motivo e prazo (até 90 dias); nunca SADM nem a própria conta; expira sozinha', function () {
    $f = naval_ficheiro_utilizadores();
    @unlink($f);
    $h = password_hash('x', PASSWORD_DEFAULT);
    naval_gravar_utilizadores([
        'chefe' => ['nome' => 'Chefe', 'perfil' => 'SADM', 'email' => 'chefe@naval.co.ao', 'senha' => $h, 'activo' => true],
        'outro' => ['nome' => 'Outro', 'perfil' => 'SADM', 'email' => 'outro@naval.co.ao', 'senha' => $h, 'activo' => true],
        'josefa' => ['nome' => 'Josefa Bento', 'perfil' => 'DIR', 'email' => 'josefa@naval.co.ao', 'senha' => $h, 'activo' => true],
    ]);
    $eu = ['login' => 'chefe', 'perfil' => 'SADM'];
    $agora = strtotime('2026-11-16 11:00');
    verdade(naval_mfa_inscricao_obrigatoria(naval_utilizadores()['josefa']), 'DIR sem dispensa: obrigatória');
    lanca(400, function () use ($eu, $agora) { naval_gestao_dispensa_mfa('josefa', 'curto', 30, $eu, $agora); });
    lanca(400, function () use ($eu, $agora) { naval_gestao_dispensa_mfa('josefa', 'Telemóvel avariado, em reparação', 91, $eu, $agora); });
    lanca(400, function () use ($eu, $agora) { naval_gestao_dispensa_mfa('outro', 'Telemóvel avariado, em reparação', 30, $eu, $agora); });   // SADM
    lanca(400, function () use ($agora) { naval_gestao_dispensa_mfa('josefa', 'Telemóvel avariado, em reparação', 30, ['login' => 'josefa', 'perfil' => 'SADM'], $agora); });
    $r = naval_gestao_dispensa_mfa('josefa', '  Telemóvel   avariado, em reparação ', 30, $eu, $agora);
    igual(['Telemóvel avariado, em reparação', '2026-12-15', 'chefe'], [$r['dispensa']['motivo'], $r['dispensa']['ate'], $r['dispensa']['por']]);
    $u = naval_utilizadores()['josefa'];
    verdade(naval_mfa_dispensa($u, strtotime('2026-12-15 23:00')) !== null, 'em vigor no último dia');
    verdade(naval_mfa_dispensa($u, strtotime('2026-12-16 00:01')) === null, 'expirada no dia seguinte');
    verdade(naval_mfa_dispensa(['perfil' => 'SADM'] + $u, $agora) === null, 'nunca para SADM (mesmo que mude de perfil)');
    igual('2026-12-15', array_column(array_filter(naval_gestao_vista()['utilizadores'] ?? [], function ($x) { return $x['login'] === 'josefa'; }), 'dispensa_dois_passos')[0]['ate'] ?? null);
    naval_gestao_dispensa_mfa('josefa', '', 0, $eu, $agora);
    verdade(!isset(naval_utilizadores()['josefa']['mfa_dispensa']), 'retirada');
    unlink($f);
});

teste('retirar dois passos com dispensa numa só operação: tudo ou nada; sem prazo, configura de novo na entrada', function () {
    $f = naval_ficheiro_utilizadores();
    @unlink($f);
    $h = password_hash('x', PASSWORD_DEFAULT);
    naval_gravar_utilizadores([
        'chefe' => ['nome' => 'Chefe', 'perfil' => 'SADM', 'email' => 'chefe@naval.co.ao', 'senha' => $h, 'activo' => true, 'mfa' => 'AAAA'],
        'josefa' => ['nome' => 'Josefa Bento', 'perfil' => 'DIR', 'email' => 'josefa@naval.co.ao', 'senha' => $h, 'activo' => true, 'mfa' => 'BBBB'],
    ]);
    $eu = ['login' => 'chefe', 'perfil' => 'SADM'];
    $agora = time();
    lanca(400, function () use ($eu, $agora) { naval_gestao_dois_passos('josefa', false, $eu, 'curto', 30, $agora); });
    igual('BBBB', naval_utilizadores()['josefa']['mfa'] ?? null);   // nada mudou
    $r = naval_gestao_dois_passos('josefa', false, $eu, 'Telemóvel avariado, em reparação', 7, $agora);
    $u = naval_utilizadores()['josefa'];
    verdade(!isset($u['mfa']), 'retirados');
    igual(date('Y-m-d', $agora + 6 * 86400), $r['dispensa']['ate']);
    verdade(!naval_mfa_inscricao_obrigatoria($u), 'com dispensa não pede para configurar');
    // sem prazo: retira e volta a pedir na entrada seguinte
    naval_gestao_dispensa_mfa('josefa', '', 0, $eu, $agora);
    naval_gestao_dois_passos('josefa', true, $eu);
    naval_gestao_dois_passos('josefa', false, $eu);
    verdade(naval_mfa_inscricao_obrigatoria(naval_utilizadores()['josefa']), 'sem dispensa: configura de novo');
    unlink($f);
});

teste('função Tesouraria: menor privilégio e separação de funções; junta-se a catálogos já gravados', function () {
    $p = naval_funcoes_permissoes(['P-TES', 'C-CAIXA-LANCAR']);
    igual('G', $p['caixa-marketing']);
    igual('V', $p['multicaixa-tpa']);
    foreach (['registo-cargas', 'stock-armazem', 'equipamentos', 'sap-exportar', 'relatorios', 'exportar-dados', 'excel-completo', 'conciliacao-fecho', 'caixa-aprovacao', 'caixa-aprovacao-final'] as $r) {
        verdade(!isset($p[$r]), 'sem ' . $r);
    }
    igual(1, count(naval_funcoes_conflitos(['P-TES', 'C-CAIXA-APROVAR'])));
    igual(1, count(naval_funcoes_conflitos(['P-TES', 'C-CAIXA-APROVAR-1'])));
    $fx = naval_ficheiro_funcoes();
    $tinha = is_file($fx) ? file_get_contents($fx) : null;
    $sem = naval_funcoes_iniciais();
    unset($sem['P-TES']);
    $sem['P-FIN']['nome'] = 'Finanças (alterada)';
    naval_json_gravar($fx, $sem);
    $f = naval_funcoes(true);
    verdade(isset($f['P-TES']), 'acrescentada ao catálogo gravado');
    igual('Finanças (alterada)', $f['P-FIN']['nome']);
    if ($tinha === null) unlink($fx); else file_put_contents($fx, $tinha);
    naval_funcoes(true);
});
