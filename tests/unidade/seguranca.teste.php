<?php
// Matriz de acessos, contas e verificação em dois passos.

teste('matriz original: SADM com A em tudo; colunas por perfil', function () {
    $m = naval_matriz_base();
    igual('A', $m['vendas-dia']['SADM']);
    igual('Vr', $m['vendas-dia']['SUP']);
    igual('', $m['sap-exportar']['DIR']);
    igual('G', $m['plano-cargas']['LOG']);
    igual(count(NAVAL_MATRIZ_TXT), count($m));
});

teste('níveis permitidos por recurso', function () {
    igual([], naval_niveis_recurso('admin-config'));
    igual(['', 'V', 'E'], naval_niveis_recurso('sap-exportar'));
    igual(['', 'Vr', 'V', 'Er', 'E'], naval_niveis_recurso('vendas-dia'));
});

teste('alterações à matriz: só as diferenças são gravadas; níveis inválidos ignorados', function () {
    $m = naval_matriz_base();
    $m['ranking']['BO'] = 'V';
    $m['ranking']['DIR'] = 'G';        // não permitido em ranking
    naval_gravar_matriz($m);
    igual(['ranking' => ['BO' => 'V']], naval_matriz_alteracoes());
    igual('V', naval_matriz()['ranking']['BO']);
    naval_gravar_matriz(naval_matriz_base());
    igual([], naval_matriz_alteracoes());
});

teste('sem sessão não há utilizador nem acesso', function () {
    igual(null, naval_utilizador());
    igual('', naval_nivel('inicio'));
    verdade(!naval_pode('inicio'));
    igual([], naval_rotas());
});

teste('contas: validade, email obrigatório e data de fim', function () {
    $u = ['perfil' => 'COM', 'activo' => true, 'email' => 'a@naval.co.ao'];
    verdade(naval_conta_valida($u));
    verdade(!naval_conta_valida(['email' => ''] + $u));
    verdade(naval_conta_valida(['email' => '', 'compatibilidade' => true] + $u));
    verdade(!naval_conta_valida(['valido_ate' => '2020-01-01'] + $u));
    verdade(!naval_conta_valida(['perfil' => 'XPTO'] + $u));
    verdade(!naval_conta_valida(['activo' => false] + $u));
});

teste('palavras-passe: temporária com 16 caracteres; regras da escolhida', function () {
    igual(16, strlen(naval_senha_temporaria()));
    verdade(naval_senha_fraca('curta1') !== null);
    verdade(naval_senha_fraca('sónumeros1234') === null);
    verdade(naval_senha_fraca('semnumerosaqui') === null, 'sem regras de composição (ASVS 2.1.9)');
    verdade(naval_senha_fraca('onze-carac.') !== null, 'mínimo 12 (ASVS 2.1.1)');
    verdade(naval_senha_fraca('password1234') !== null, 'comum / divulgada (ASVS 2.1.7)');
    verdade(naval_senha_fraca('Naval2026!!!') !== null, 'nome da empresa com números');
    verdade(naval_senha_fraca('joao.silva2026', 'joao.silva') !== null, 'nome da conta com números');
    verdade(naval_senha_fraca('aaaaaaaaaaaaaa') !== null, 'repetida');
    verdade(naval_senha_fraca(str_repeat('x7', 40)) !== null, 'acima de 72 bytes (limite do bcrypt)');
    verdade(naval_senha_fraca('uma frase com espaços e acentuação') === null, 'frases aceites');
});

teste('TOTP (RFC 6238, SHA-1): vector de teste e janela de ±30 s', function () {
    $seg = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // «12345678901234567890» em base32
    igual('287082', naval_totp($seg, 1));          // T = 59 s → 94287082
    igual('081804', naval_totp($seg, 37037036));   // T = 1111111109 s → 07081804
    $agora = naval_totp($seg, (int) floor(time() / 30));
    verdade(naval_totp_valido($seg, substr($agora, 0, 3) . ' ' . substr($agora, 3)));
    verdade(!naval_totp_valido($seg, '12345'));
    igual(32, strlen(naval_novo_segredo()));
});

teste('bloqueio à 3.ª tentativa e desbloqueio', function () {
    igual(2, naval_falha_login('Teste.Bloqueio'));
    igual(1, naval_falha_login('teste.bloqueio'));
    verdade(!naval_bloqueado('teste.bloqueio'));
    igual(0, naval_falha_login('teste.bloqueio'));
    verdade(naval_bloqueado('TESTE.BLOQUEIO'));
    // conta inexistente: bloqueada, mas o texto escrito não é guardado nem listado ao super administrador
    verdade(!isset(naval_contas_bloqueadas()['teste.bloqueio']));
    verdade(naval_desbloquear('teste.bloqueio', 'unidade'));
    verdade(!naval_bloqueado('teste.bloqueio'));
});

teste('registos de actividade: uma linha JSON por acção, mais recentes primeiro', function () {
    naval_registar('primeira', ['x' => 1], 'unidade');
    naval_registar('segunda', [], 'unidade');
    $r = naval_ler_registos(2);
    igual('segunda', $r[0]['accao']);
    igual('primeira', $r[1]['accao']);
    igual(['x' => 1], $r[1]['detalhe']);
});

teste('dois passos: cada código só é aceite uma vez (sem reutilização)', function () {
    $seg = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $agora = naval_totp($seg, (int) floor(time() / 30));
    verdade(naval_totp_aceitar('teste.totp', $seg, $agora), 'primeira utilização');
    verdade(!naval_totp_aceitar('teste.totp', $seg, $agora), 'reutilização recusada');
    verdade(!naval_totp_aceitar('teste.totp', $seg, '000000') || $agora === '000000');
    verdade(!naval_totp_aceitar('teste.totp', '', $agora), 'sem segredo');
});

teste('limite por IP: suspende após as falhas permitidas na janela, sem bloquear contas', function () {
    $ip = '203.0.113.' . random_int(1, 254);
    verdade(!naval_ip_suspenso($ip));
    for ($i = 0; $i < naval_ip_max_falhas() - 1; $i++) naval_ip_falha($ip);
    verdade(!naval_ip_suspenso($ip), 'ainda dentro do limite');
    naval_ip_falha($ip);
    verdade(naval_ip_suspenso($ip), 'suspenso no limite');
    verdade(!naval_ip_suspenso('203.0.113.255'), 'outros IP não são afectados');
    verdade(!naval_ip_suspenso(''), 'sem IP');
    // falhas antigas (fora da janela) não contam
    naval_json_gravar(naval_ip_ficheiro($ip), ['falhas' => array_fill(0, 50, time() - NAVAL_IP_JANELA - 1)], false);
    verdade(!naval_ip_suspenso($ip), 'janela expirada');
});

teste('hash fictício: verificação de contas inexistentes leva o mesmo custo e nunca é aceite', function () {
    igual(10, password_get_info(NAVAL_HASH_FICTICIO)['options']['cost'] ?? 0);
    verdade(!password_verify('', NAVAL_HASH_FICTICIO));
    verdade(!password_verify('Teste-12345!', NAVAL_HASH_FICTICIO));
});

teste('período: datas reais, início ≤ fim e no máximo 400 dias', function () {
    naval_validar_periodo('20261001', '20261027');
    naval_validar_periodo('2025-10-01', '2026-10-27');   // aceita AAAA-MM-DD (≤ 400 dias)
    lanca(400, function () { naval_validar_periodo('20261340', '20261341'); });
    lanca(400, function () { naval_validar_periodo('20261027', '20261001'); });
    lanca(400, function () { naval_validar_periodo('20240101', '20261027'); });
    lanca(400, function () { naval_validar_periodo('abc', '20261027'); });
});

teste('configuração: chaves em falta só pelo nome e erro 503 sem valores', function () {

    igual(['NAO_EXISTE', 'VAZIO'], naval_config_em_falta(['DADOS_PRIVADOS', 'NAO_EXISTE', 'VAZIO']));
    $e = lanca(503, function () { naval_exigir_config(['DADOS_PRIVADOS', 'NAO_EXISTE'], 'o teste'); });
    verdade(strpos($e->getMessage(), 'NAO_EXISTE') !== false);
    verdade(strpos($e->getMessage(), naval_config('DADOS_PRIVADOS')) === false, 'não revela valores');
    verdade(!naval_debug(), 'diagnóstico desligado por omissão');
});

teste('detalhe técnico dos erros: escondido fora do modo de diagnóstico e dos perfis SADM/TI', function () {
    igual([], naval_detalhe('SQLSTATE[08001] servidor 10.0.0.1'));
    igual([], naval_detalhe(''));
});

naval_servico('pinguim');
teste('Pinguim BO: sem endereços predefinidos no código; só http(s) e verificação TLS ligada por omissão', function () {
    $c = naval_pinguim_config();
    igual([], $c['enderecos']);
    verdade($c['verificar_ssl']);
    verdade(!naval_pinguim_configurado());
});


teste('palavra-passe temporária expira em 24 h e muda a versão das credenciais', function () {
    $u = naval_marcar_temporaria(['versao' => 2], 'Temp-ABCDEFGH23');
    verdade(!empty($u['trocar_senha']) && $u['versao'] === 3 && password_verify('Temp-ABCDEFGH23', $u['senha']));
    verdade(!naval_temporaria_expirada($u));
    $u['temporaria_ate'] = time() - 1;
    verdade(naval_temporaria_expirada($u));
    verdade(!naval_temporaria_expirada(['trocar_senha' => true]), 'contas antigas sem data');
});

teste('dois passos obrigatórios: inscrição pedida só a perfis marcados e sem segredo', function () {
    $mfa = naval_perfis_mfa();
    verdade(in_array('SADM', $mfa, true));
    verdade(naval_mfa_inscricao_obrigatoria(['perfil' => 'SADM']));
    verdade(!naval_mfa_inscricao_obrigatoria(['perfil' => 'SADM', 'mfa' => 'ABC']));
    verdade(!naval_mfa_inscricao_obrigatoria(['perfil' => 'SADM', 'compatibilidade' => true]), 'lista antiga: depois de activar a gestão');
});

teste('bloqueio exclusivo reentrante e IP do cliente só por proxy de confiança', function () {
    igual(7, naval_exclusivo('teste', function () { return naval_exclusivo('teste', function () { return 7; }); }));
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
    igual('10.0.0.5', naval_ip_cliente(), 'sem PROXY_CONFIAVEIS ignora X-Forwarded-For');
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
});

teste('registos: texto escrito numa conta inexistente não é guardado', function () {
    $id = naval_id_registo('MinhaSenha#2026');
    verdade(strpos($id, 'desconhecido-') === 0 && strpos($id, 'Senha') === false);
});
