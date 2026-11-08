<?php
// Conformidade (ISO/IEC 27001, 27701, ISO 22301): registos invioláveis, contas sem uso, revisão de acessos, cópia
// cifrada com teste de recuperação, retenção e mascaramento dos dados pessoais do ensaio.
naval_servico('conformidade');
naval_servico('suporte');

teste('registos invioláveis: cadeia SHA-256 íntegra; alterar, apagar ou cortar uma linha nota-se', function () {
    $dir = naval_registos_pasta();
    foreach (glob($dir . '/acessos-*.jsonl') ?: [] as $f) @unlink($f);
    @unlink(naval_registos_cadeia_ficheiro());
    for ($i = 0; $i < 5; $i++) naval_registar('teste_cadeia', ['i' => $i, 'texto' => 'ação «' . $i . '» / ok'], 'tester');
    $v = naval_registos_verificar();
    verdade($v['ok'], json_encode($v['falhas']));
    igual(5, $v['encadeadas']);
    $f = $dir . '/acessos-' . date('Y-m') . '.jsonl';
    $orig = (string) file_get_contents($f);
    $linhas = explode("\n", trim($orig));
    // conteúdo alterado
    file_put_contents($f, str_replace('"i":2', '"i":9', $orig));
    $v = naval_registos_verificar();
    verdade(!$v['ok'] && $v['falhas'][0][2] === 'conteúdo alterado', json_encode($v['falhas']));
    // linha apagada no meio
    file_put_contents($f, implode("\n", array_merge(array_slice($linhas, 0, 2), array_slice($linhas, 3))) . "\n");
    $v = naval_registos_verificar();
    verdade(!$v['ok'] && strpos($v['falhas'][0][2], 'apagada') !== false, json_encode($v['falhas']));
    // fim cortado (a última linha desaparece)
    file_put_contents($f, implode("\n", array_slice($linhas, 0, 4)) . "\n");
    $v = naval_registos_verificar();
    verdade(!$v['ok'] && strpos(end($v['falhas'])[2], 'últimas linhas') !== false, json_encode($v['falhas']));
    file_put_contents($f, $orig);
    verdade(naval_registos_verificar()['ok'], 'reposto');
    // a leitura para a página não mostra os resumos
    verdade(!isset(naval_ler_registos(1)[0]['h']), 'sem h na leitura');
});

teste('contas sem uso: 90 dias sem entrar desliga (nunca o SADM); 14 dias antes aparece «a desligar»', function () {
    $agora = time();
    $us = naval_utilizadores();
    $us['velha'] = ['nome' => 'Conta velha', 'perfil' => 'COM', 'activo' => true, 'criado' => date('Y-m-d', $agora - 120 * 86400)];
    $us['quase'] = ['nome' => 'Quase', 'perfil' => 'COM', 'activo' => true, 'criado' => date('Y-m-d', $agora - 80 * 86400)];
    $us['chefe'] = ['nome' => 'Chefe', 'perfil' => 'SADM', 'activo' => true, 'criado' => date('Y-m-d', $agora - 400 * 86400)];
    $us['recente'] = ['nome' => 'Recente', 'perfil' => 'COM', 'activo' => true, 'criado' => date('Y-m-d', $agora - 200 * 86400)];
    naval_gravar_utilizadores($us);
    naval_conf_marcar_entrada('recente');
    $r = naval_conf_contas_sem_uso($agora);
    igual(['velha'], array_column($r['desligadas'], 'login'));
    igual(['quase'], array_column($r['a_desligar'], 'login'));
    $depois = naval_utilizadores();
    verdade(empty($depois['velha']['activo']) && $depois['velha']['desactivado']['motivo'] === 'sem_uso', 'desligada');
    verdade(!empty($depois['chefe']['activo']) && !empty($depois['recente']['activo']) && !empty($depois['quase']['activo']), 'as outras ficam');
});

teste('revisão de acessos: abre uma vez por trimestre, lista as contas activas e confirma-se uma vez', function () {
    $agora = time();
    $tri = naval_conf_trimestre($agora);
    @unlink(naval_conf_pasta() . '/revisao-' . $tri . '.json');
    igual($tri, naval_conf_revisao_abrir($agora));
    igual(null, naval_conf_revisao_abrir($agora), 'só uma vez por trimestre');
    $r = naval_json_ler(naval_conf_pasta() . '/revisao-' . $tri . '.json');
    verdade(in_array('recente', array_column($r['contas'], 'login'), true) && !in_array('velha', array_column($r['contas'], 'login'), true), 'só as activas');
    $c = naval_conf_revisao_confirmar($tri, ['login' => 'henrique'], 'revisto');
    igual('henrique', $c['confirmada']['por']);
    lanca(409, function () use ($tri) { naval_conf_revisao_confirmar($tri, ['login' => 'outro'], ''); });
    lanca(400, function () { naval_conf_revisao_confirmar('2026-T9', ['login' => 'x'], ''); });
});

teste('cópia diária: cifrada (AES-256-GCM), sem sessões nem cache; o teste de recuperação confere tudo e nota danos', function () {
    $priv = naval_pasta_privada();
    @mkdir($priv . '/sessoes', 0770, true);
    file_put_contents($priv . '/sessoes/sess_x', 'segredo');
    file_put_contents($priv . '/teste-copia.json', '{"a":1}');
    $c = naval_conf_copiar(time());
    verdade($c['cifrada'] && $c['ficheiros'] > 0, json_encode($c));
    $f = naval_pasta('copias-dados') . '/' . $c['ficheiro'];
    igual('NAVALC1', substr((string) file_get_contents($f), 0, 7));
    verdade(strpos((string) file_get_contents($f), 'teste-copia') === false, 'o conteúdo não se lê sem a chave');
    $j = naval_conf_ler_copia($f);
    verdade(isset($j['manifesto']['teste-copia.json']) && !isset($j['manifesto']['sessoes/sess_x']), 'sem sessões');
    $t = naval_conf_testar_recuperacao($f);
    verdade($t['ok'], json_encode($t));
    // cópia danificada: um byte trocado no fim
    $d = (string) file_get_contents($f);
    file_put_contents($f . '.mau', substr($d, 0, -1) . chr(ord(substr($d, -1)) ^ 1));
    $falhou = false;
    try {
        naval_conf_ler_copia($f . '.mau');
    } catch (RuntimeException $e) {
        $falhou = true;
    }
    verdade($falhou, 'uma cópia danificada não se lê');
    @unlink($f . '.mau');
    @unlink($priv . '/teste-copia.json');
});

teste('retenção: apaga o que passou do prazo, pela data do nome ou do ficheiro; mantém o resto', function () {
    $priv = naval_pasta_privada();
    @mkdir($priv . '/email-saida', 0770, true);
    file_put_contents($priv . '/email-saida/velho.eml', 'x');
    touch($priv . '/email-saida/velho.eml', time() - 200 * 86400);
    file_put_contents($priv . '/email-saida/novo.eml', 'x');
    $velha = date('Y-m-d', time() - 2000 * 86400);
    @mkdir($priv . '/carrinha/' . $velha, 0770, true);
    file_put_contents($priv . '/carrinha/' . $velha . '/1001.json', '{}');
    $r = naval_conf_retencao(time());
    verdade($r['email'] >= 1 && $r['carrinha'] >= 1, json_encode($r));
    verdade(!is_file($priv . '/email-saida/velho.eml') && is_file($priv . '/email-saida/novo.eml'), 'email');
    verdade(!is_dir($priv . '/carrinha/' . $velha), 'carrinha antiga');
    verdade(is_file(naval_registos_cadeia_ficheiro()), 'a cadeia nunca se apaga');
});

teste('ensaio: NIF, telefones, e-mails e moradas mascarados; e-mails dos administradores de sistema ficam', function () {
    $d = sys_get_temp_dir() . '/ens-' . getmypid();
    @mkdir($d . '/sub', 0770, true);
    file_put_contents($d . '/utilizadores.json', json_encode([
        'ana' => ['nome' => 'Ana', 'perfil' => 'COM', 'email' => 'ana@naval.co.ao', 'telefone' => '923000111'],
        'ti' => ['nome' => 'TI', 'perfil' => 'TI', 'email' => 'ti@naval.co.ao'],
    ]));
    file_put_contents($d . '/sub/clientes.json', json_encode([['nome' => 'Loja', 'nif' => '5417000000', 'morada' => 'Rua 1'], ['nome' => 'Loja 2', 'nif' => '5417000000']]));
    $n = naval_conf_mascarar_pasta($d);
    igual(5, $n);
    $u = json_decode((string) file_get_contents($d . '/utilizadores.json'), true);
    verdade(strpos($u['ana']['email'], '@ensaio.invalid') !== false && $u['ti']['email'] === 'ti@naval.co.ao', json_encode($u));
    verdade($u['ana']['telefone'] !== '923000111', 'telefone');
    $c = json_decode((string) file_get_contents($d . '/sub/clientes.json'), true);
    verdade(strpos($c[0]['nif'], 'MASC-') === 0 && $c[0]['nif'] === $c[1]['nif'], 'mesmo NIF, mesmo pseudónimo (as ligações mantêm-se)');
    igual('Loja', $c[0]['nome']);
    foreach (['/utilizadores.json', '/sub/clientes.json'] as $x) @unlink($d . $x);
    @rmdir($d . '/sub');
    @rmdir($d);
});

teste('suporte: abrir, prazo pela prioridade, comentar; só quem abriu avalia; validações', function () {
    @unlink(naval_sup_ficheiro());
    $eu = ['login' => 'ana', 'nome' => 'Ana'];
    $p = naval_sup_criar($eu, ['tipo' => 'incidente', 'prioridade' => 'P2', 'titulo' => 'Telemóvel não sincroniza', 'descricao' => 'Rota 1005']);
    igual('SUP-000001', $p['id']);
    igual(date('Y-m-d H:i:s', strtotime($p['criado_em']) + 8 * 3600), $p['prazo']);
    $q = naval_sup_criar($eu, ['tipo' => 'pedido', 'prioridade' => 'P4', 'titulo' => 'Novo acesso']);
    igual('SUP-000002', $q['id']);
    $c = naval_sup_accao($eu, $p['id'], 'comentar', ['texto' => 'Continua']);
    igual(['criado', 'comentar'], array_column($c['historico'], 'accao'));
    lanca(403, function () use ($eu, $p) { naval_sup_accao($eu, $p['id'], 'resolver', ['texto' => 'x']); });
    lanca(409, function () use ($eu, $p) { naval_sup_accao($eu, $p['id'], 'avaliar', ['satisfacao' => 5]); });
    lanca(404, function () use ($p) { naval_sup_accao(['login' => 'bruno'], $p['id'], 'comentar', ['texto' => 'x']); });
    lanca(400, function () use ($eu) { naval_sup_criar($eu, ['tipo' => 'x', 'titulo' => 'abcd']); });
    lanca(400, function () use ($eu) { naval_sup_criar($eu, ['tipo' => 'pedido', 'prioridade' => 'P3', 'titulo' => 'ab']); });
    $v = naval_sup_vista($eu, time());
    igual(2, $v['indicadores']['abertos']);
    igual(0, count(naval_sup_vista(['login' => 'bruno'], time())['pedidos']), 'cada um vê os seus');
});
