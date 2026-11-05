<?php
// Recuperação da conta pela própria pessoa (OWASP Forgot Password, ASVS 2.5, NIST SP 800-63B).
naval_servico('recuperacao');

/** Contas de teste (lista privada temporária) e a última ligação enviada por correio electrónico. */
function rec_contas(): void
{
    naval_gravar_utilizadores([
        'ana' => ['nome' => 'Ana Teste', 'email' => 'ana@naval.co.ao', 'senha' => password_hash('Antiga-frase-segura-1', PASSWORD_DEFAULT), 'perfil' => 'DIR', 'rotas' => [], 'activo' => true, 'idioma' => 'pt'],
        'rui' => ['nome' => 'Rui Teste', 'email' => 'rui@naval.co.ao', 'senha' => password_hash('Antiga-frase-segura-2', PASSWORD_DEFAULT), 'perfil' => 'SADM', 'rotas' => [], 'activo' => true, 'idioma' => 'pt', 'mfa' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'],
        'inactiva' => ['nome' => 'Sem acesso', 'email' => 'x@naval.co.ao', 'senha' => '', 'perfil' => 'DIR', 'rotas' => [], 'activo' => false, 'idioma' => 'pt'],
    ]);
    foreach (glob(naval_pasta('recuperacao') . '/*') ?: [] as $f) @unlink($f);
    foreach (glob(naval_pasta('limites') . '/rec-*') ?: [] as $f) @unlink($f);
    foreach (glob(naval_pasta('email-saida') . '/*') ?: [] as $f) @unlink($f);
}
function rec_ultima_ligacao(): string
{
    $l = glob(naval_pasta('email-saida') . '/*.html') ?: [];
    sort($l);
    $html = (string) file_get_contents((string) end($l));
    preg_match('/recuperar\.php\?t=([a-f0-9]{64})/', $html, $m);
    return $m[1] ?? '';
}

teste('Recuperação: só envia para contas activas com correio; ligação de uso único guardada só como resumo', function () {
    rec_contas();
    igual('sem_conta', naval_rec_pedir_agora('nao-existe', '10.0.0.1'));
    igual('sem_conta', naval_rec_pedir_agora('inactiva', '10.0.0.1'));
    igual([], glob(naval_pasta('email-saida') . '/*.html') ?: []);
    igual('enviado', naval_rec_pedir_agora('ana@naval.co.ao', '10.0.0.1'));
    $t = rec_ultima_ligacao();
    igual(64, strlen($t));
    verdade(strpos((string) file_get_contents((string) (glob(naval_pasta('email-saida') . '/*.html') ?: [''])[0]), 'https://portal.teste/geo/recuperar.php?t=') !== false, 'endereço do PORTAL_URL');
    // no servidor não fica o código, só o resumo
    $todos = implode('', array_map('file_get_contents', glob(naval_pasta('recuperacao') . '/*') ?: []));
    verdade(strpos($todos, $t) === false, 'o código não fica guardado');
    igual('ana', naval_rec_ligacao($t)[0]);
    igual(null, naval_rec_ligacao('abc'));
    igual(null, naval_rec_ligacao(str_repeat('0', 64)));
    // um pedido novo anula a ligação anterior
    foreach (glob(naval_pasta('email-saida') . '/*') ?: [] as $f) @unlink($f);
    naval_rec_pedir_agora('ana', '10.0.0.1');
    $t2 = rec_ultima_ligacao();
    verdade($t2 !== $t);
    igual(null, naval_rec_ligacao($t));
    igual('ana', naval_rec_ligacao($t2)[0]);
});

teste('Recuperação: palavra-passe nova, desbloqueio, sessões terminadas, uso único e aviso por correio', function () {
    rec_contas();
    for ($i = 0; $i < NAVAL_MAX_TENTATIVAS; $i++) naval_falha_login('ana');
    verdade(naval_bloqueado('ana'), 'bloqueada');
    $versao = (int) (naval_utilizadores()['ana']['versao'] ?? 0);
    naval_rec_pedir_agora('ana', '10.0.0.2');
    $t = rec_ultima_ligacao();
    igual('As duas palavras-passe não são iguais.', naval_rec_concluir($t, 'Uma-frase-nova-segura', 'outra', '', '10.0.0.2'));
    verdade(is_string(naval_rec_concluir($t, 'curta', 'curta', '', '10.0.0.2')), 'regras da palavra-passe');
    igual(null, naval_rec_concluir($t, 'Uma-frase-nova-segura', 'Uma-frase-nova-segura', '', '10.0.0.2'));
    $u = naval_utilizadores()['ana'];
    verdade(password_verify('Uma-frase-nova-segura', $u['senha']), 'palavra-passe gravada');
    igual($versao + 1, (int) $u['versao'], 'sessões abertas terminam');
    verdade(!naval_bloqueado('ana'), 'desbloqueada');
    igual(null, naval_rec_ligacao($t), 'uso único');
    igual('A ligação expirou ou já foi usada. Peça uma nova.', naval_rec_concluir($t, 'Outra-frase-nova-segura', 'Outra-frase-nova-segura', '', '10.0.0.2'));
    $avisos = array_filter(glob(naval_pasta('email-saida') . '/*.json') ?: [], function ($f) {
        return strpos((string) file_get_contents($f), 'foi alterada') !== false;
    });
    igual(1, count($avisos), 'aviso de alteração por correio');
});

teste('Recuperação: com dois passos activos pede o código; 3 códigos errados anulam a ligação', function () {
    rec_contas();
    naval_rec_pedir_agora('rui', '10.0.0.3');
    $t = rec_ultima_ligacao();
    igual('Código de verificação inválido. Restam 2 tentativas.', naval_rec_concluir($t, 'Uma-frase-nova-segura', 'Uma-frase-nova-segura', '000000', '10.0.0.3'));
    igual('Código de verificação inválido. Resta 1 tentativa.', naval_rec_concluir($t, 'Uma-frase-nova-segura', 'Uma-frase-nova-segura', '', '10.0.0.3'));
    igual('Código inválido. Por segurança, a ligação foi anulada: peça uma nova.', naval_rec_concluir($t, 'Uma-frase-nova-segura', 'Uma-frase-nova-segura', '111111', '10.0.0.3'));
    igual(null, naval_rec_ligacao($t));
    // com o código certo conclui
    foreach (glob(naval_pasta('email-saida') . '/*') ?: [] as $f) @unlink($f);
    naval_rec_pedir_agora('rui', '10.0.0.3');
    $t = rec_ultima_ligacao();
    $codigo = naval_totp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', (int) floor(time() / 30));
    @unlink(naval_pasta('bloqueios') . '/totp-' . hash('sha256', 'rui') . '.json');
    igual(null, naval_rec_concluir($t, 'Uma-frase-nova-segura', 'Uma-frase-nova-segura', $codigo, '10.0.0.3'));
});

teste('Recuperação: limites por endereço IP e por conta; resposta com tempo mínimo igual exista ou não a conta', function () {
    rec_contas();
    for ($i = 0; $i < NAVAL_REC_MAX_CONTA; $i++) igual('enviado', naval_rec_pedir_agora('ana', '10.0.1.' . $i));
    igual('limite_conta', naval_rec_pedir_agora('ana', '10.0.1.9'));
    for ($i = 0; $i < NAVAL_REC_MAX_IP; $i++) naval_rec_pedir_agora('x' . $i, '10.0.2.1');
    igual('limite_ip', naval_rec_pedir_agora('ana', '10.0.2.1'));
    $t0 = microtime(true);
    naval_rec_pedir('nao-existe', '10.0.3.1');
    verdade(microtime(true) - $t0 >= NAVAL_REC_TEMPO_MINIMO, 'tempo mínimo');
});
