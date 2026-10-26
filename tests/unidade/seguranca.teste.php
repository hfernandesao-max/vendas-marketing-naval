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
    verdade(naval_senha_fraca('semnumerosaqui') !== null);
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
    verdade(isset(naval_contas_bloqueadas()['teste.bloqueio']));
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
