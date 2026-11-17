<?php
// Pedidos de acesso autorizados pelo dono da função e aplicados pelo super administrador; revisão trimestral por dono
// (nucleo/servicos/acessos-pedidos.php).
naval_servico('acessos-pedidos');
naval_servico('conformidade');

/** Ensaio com contas na lista nova, o dono da P-LOG e sem pedidos; devolve o que repor. */
function pa_ensaio(): array
{
    $f = naval_ficheiro_utilizadores();
    $antes = is_file($f) ? (string) file_get_contents($f) : null;
    @unlink(naval_ficheiro_funcoes());
    @unlink(naval_funcoes_ficheiro_ajustes());
    @unlink(naval_pa_ficheiro());
    naval_gravar_perfis([]);
    naval_funcoes(true);
    $base = ['activo' => true, 'idioma' => 'pt'];
    $d = [
        'sadm' => $base + ['nome' => 'Super', 'email' => 'sadm@naval.co.ao', 'perfil' => 'SADM'],
        'sadm2' => $base + ['nome' => 'Super 2', 'email' => 'sadm2@naval.co.ao', 'perfil' => 'SADM'],
        'chefe' => $base + ['nome' => 'Chefe Logística', 'email' => 'chefe@naval.co.ao', 'perfil' => 'COM'],
        'ana' => $base + ['nome' => 'Ana', 'email' => 'ana@naval.co.ao', 'funcoes' => ['P-LOG'], 'perfil' => ''],
        'rui' => $base + ['nome' => 'Rui', 'email' => 'rui@naval.co.ao', 'perfil' => 'COM'],
    ];
    $d['ana']['perfil'] = naval_funcoes_perfil(['P-LOG'], 'teste');
    naval_gravar_utilizadores($d);
    $c = naval_funcoes()['C-CONC-CONTAR'];
    naval_funcoes_gravar(['codigo' => 'C-CONC-CONTAR', 'dono' => 'chefe'] + $c, 'teste');
    return ['antes' => $antes];
}

function pa_repor(array $e): void
{
    $f = naval_ficheiro_utilizadores();
    if ($e['antes'] === null) @unlink($f); else file_put_contents($f, $e['antes']);
    @unlink(naval_ficheiro_funcoes());
    @unlink(naval_pa_ficheiro());
    naval_gravar_perfis([]);
    naval_funcoes(true);
}

$eu = function (string $l): array {
    $u = naval_utilizadores()[$l];
    return ['login' => $l, 'perfil' => $u['perfil']];
};

teste('pedidos de acesso: pedir valida; o dono autoriza (nunca quem pediu nem o próprio); o SADM aplica e não para si', function () use ($eu) {
    $e = pa_ensaio();
    try {
        // validações ao pedir
        lanca(409, function () use ($eu) { naval_pa_criar(['conta' => 'rui', 'funcoes' => ['C-CONC-CONTAR'], 'motivo' => 'Ajuda na contagem'], $eu('ana')); });
        lanca(409, function () use ($eu) { naval_pa_criar(['conta' => 'ana', 'funcoes' => ['P-LOG'], 'motivo' => 'Já tem a função'], $eu('ana')); });
        lanca(400, function () use ($eu) { naval_pa_criar(['conta' => 'ana', 'funcoes' => ['C-CONC-COORD'], 'motivo' => 'Coordenar a logística'], $eu('ana')); });
        lanca(400, function () use ($eu) { naval_pa_criar(['conta' => 'ana', 'funcoes' => ['C-CONC-CONTAR'], 'motivo' => 'curto'], $eu('ana')); });
        lanca(400, function () use ($eu) { naval_pa_criar(['conta' => 'ana', 'funcoes' => ['C-CONC-CONTAR'], 'motivo' => 'Contar as carrinhas', 'fim' => '2020-01-01'], $eu('ana')); });
        $p = naval_pa_criar(['conta' => 'ana', 'funcoes' => ['C-CONC-CONTAR'], 'motivo' => 'Substitui o técnico do armazém', 'fim' => '2099-12-31'], $eu('ana'));
        igual('PA-000001', $p['id']);
        igual('pendente', $p['estado']);
        lanca(409, function () use ($eu) { naval_pa_criar(['conta' => 'ana', 'funcoes' => ['C-CONC-CONTAR'], 'motivo' => 'Outra vez o mesmo'], $eu('ana')); });
        // quem autoriza: o dono; quem pediu e o SADM (havendo dono) não
        lanca(403, function () use ($eu) { naval_pa_decidir('PA-000001', 'aprovar', $eu('ana')); });
        lanca(403, function () use ($eu) { naval_pa_decidir('PA-000001', 'aprovar', $eu('sadm')); });
        lanca(403, function () use ($eu) { naval_pa_decidir('PA-000001', 'aprovar', $eu('rui')); });
        $v = naval_pa_vista($eu('chefe'));
        igual(1, $v['por_decidir'], 'o dono vê o que tem de decidir');
        igual(['chefe'], array_keys((array) $v['funcoes_contas']), 'só as funções da própria pessoa');
        igual([], naval_pa_vista($eu('rui'))['pedidos'], 'quem não tem nada com o pedido não o vê');
        lanca(409, function () use ($eu) { naval_pa_aplicar('PA-000001', $eu('sadm')); });
        $p = naval_pa_decidir('PA-000001', 'aprovar', $eu('chefe'));
        igual('aprovado', $p['estado']);
        igual(1, count(naval_pa_para_aplicar()));
        lanca(403, function () use ($eu) { naval_pa_aplicar('PA-000001', $eu('chefe')); });
        $r = naval_pa_aplicar('PA-000001', $eu('sadm'));
        igual('aplicado', $r['estado']);
        $u = naval_utilizadores()['ana'];
        igual(['C-CONC-CONTAR', 'P-LOG'], $u['funcoes']);
        igual(['C-CONC-CONTAR' => '2099-12-31'], $u['funcoes_fim'], 'temporária com o último dia');
        igual(naval_funcoes_codigo(['C-CONC-CONTAR', 'P-LOG']), $u['perfil']);
        // função sem dono: autoriza o SADM; o SADM não aplica nem autoriza para a sua própria conta
        $d = naval_utilizadores();
        $d['sadm2']['funcoes'] = ['P-TI'];
        $d['sadm2']['perfil'] = 'SADM';
        naval_gravar_utilizadores($d);
        $p2 = naval_pa_criar(['conta' => 'ana', 'funcoes' => ['P-GEO'], 'motivo' => 'Prospecção da zona norte'], $eu('chefe'));
        igual('', naval_pa_publico($p2, $eu('sadm'))['funcoes'][0]['aprovador'], 'sem dono');
        naval_pa_decidir($p2['id'], 'aprovar', $eu('sadm'));
        $p3 = naval_pa_criar(['conta' => 'sadm2', 'funcoes' => ['P-AUD'], 'motivo' => 'Auditoria interna do trimestre'], $eu('chefe'));
        lanca(403, function () use ($eu, $p3) { naval_pa_decidir($p3['id'], 'aprovar', $eu('sadm2')); });
        naval_pa_decidir($p3['id'], 'aprovar', $eu('sadm'));
        lanca(403, function () use ($eu, $p3) { naval_pa_aplicar($p3['id'], $eu('sadm2')); });
        // recusar exige motivo; anular só quem pediu
        $p4 = naval_pa_criar(['conta' => 'ana', 'funcoes' => ['P-AUD'], 'motivo' => 'Consulta para auditoria'], $eu('ana'));
        lanca(403, function () use ($eu, $p4) { naval_pa_decidir($p4['id'], 'anular', $eu('rui')); });
        lanca(400, function () use ($eu, $p4) { naval_pa_decidir($p4['id'], 'recusar', $eu('sadm'), ''); });
        igual('recusado', naval_pa_decidir($p4['id'], 'recusar', $eu('sadm'), 'Não precisa')['estado']);
    } finally {
        pa_repor($e);
    }
});

teste('revisão de acessos: cada dono confirma a sua parte; sem todas, o SADM só fecha com a razão escrita', function () use ($eu) {
    $e = pa_ensaio();
    $dir = naval_conf_pasta();
    foreach (glob($dir . '/revisao-*.json') ?: [] as $f) @rename($f, $f . '.bak');
    try {
        $d = naval_utilizadores();
        $d['ana']['funcoes'] = ['C-CONC-CONTAR', 'P-LOG'];
        $d['ana']['perfil'] = naval_funcoes_perfil($d['ana']['funcoes'], 'teste');
        naval_gravar_utilizadores($d);
        $tri = naval_conf_revisao_abrir(strtotime('2026-11-17 02:00'));
        igual('2026-T4', $tri);
        $r = naval_pa_revisao_area($eu('chefe'));
        igual([['conta' => 'ana', 'nome' => 'Ana', 'funcao' => 'C-CONC-CONTAR', 'funcao_nome' => 'Contar as carrinhas', 'ate' => '']], $r['contas']);
        igual(null, naval_pa_revisao_area($eu('rui')), 'só os donos');
        lanca(409, function () use ($eu) { naval_conf_revisao_confirmar('2026-T4', $eu('sadm'), ''); });
        lanca(400, function () use ($eu) { naval_pa_revisao_confirmar($eu('chefe'), [['conta' => 'ana', 'funcao' => 'C-CONC-CONTAR']], ''); });
        $c = naval_pa_revisao_confirmar($eu('chefe'), [['conta' => 'ana', 'funcao' => 'C-CONC-CONTAR'], ['conta' => 'rui', 'funcao' => 'P-LOG']], 'Já não conta');
        igual([['conta' => 'ana', 'funcao' => 'C-CONC-CONTAR']], $c['retirar'], 'só o que é da sua área');
        lanca(409, function () use ($eu) { naval_pa_revisao_confirmar($eu('chefe'), [], ''); });
        $f = naval_conf_revisao_confirmar('2026-T4', $eu('sadm'), '');
        igual([], $f['confirmada']['donos_em_falta']);
    } finally {
        foreach (glob($dir . '/revisao-*.json') ?: [] as $f) @unlink($f);
        foreach (glob($dir . '/revisao-*.json.bak') ?: [] as $f) @rename($f, substr($f, 0, -4));
        pa_repor($e);
    }
});
