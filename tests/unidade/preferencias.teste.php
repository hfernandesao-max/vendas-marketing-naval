<?php
// Preferências pessoais: menu lateral e funções favoritas (validadas no servidor).
naval_servico('preferencias');

teste('Preferências: favoritos só com identificadores válidos, sem repetidos e no máximo 6', function () {
    igual([], naval_pref_favoritos('vendas'));
    igual(['vendas-dia', 'agt'], naval_pref_favoritos(['vendas-dia', 'agt', 'vendas-dia', '../x', 'A B', 7, str_repeat('a', 41)]));
    igual(6, count(naval_pref_favoritos(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])));
    $n = naval_pref_normalizar(['barra' => 'oculta', 'lado' => 'x', 'favoritos' => ['agt']]);
    igual(['barra' => 'oculta', 'lado' => 'esquerda', 'favoritos' => ['agt'], 'colunas' => []], $n);
    igual([], naval_pref_normalizar([])['favoritos']);
});

teste('Preferências: gravar favoritos não mexe no menu lateral (e vice-versa)', function () {
    $login = 'teste-pref-' . bin2hex(random_bytes(4));
    naval_pref_gravar($login, ['barra' => 'compacta']);
    $p = naval_pref_gravar($login, ['favoritos' => ['agt', 'acessos']]);
    igual(['compacta', ['agt', 'acessos']], [$p['barra'], $p['favoritos']]);
    $p = naval_pref_gravar($login, ['lado' => 'direita']);
    igual(['direita', ['agt', 'acessos']], [$p['lado'], $p['favoritos']]);
    @unlink(naval_pref_ficheiro($login));
});

teste('preferências: colunas de cada tabela (ordem, escondidas, largura) seguem a conta; só as tabelas enviadas mudam', function () {
    $login = 'teste.colunas';
    @unlink(naval_pref_ficheiro($login));
    $p = naval_pref_gravar($login, ['colunas' => ['vendas-promotor:vp' => ['o' => ['docs', 'rota'], 'h' => ['docs'], 'w' => ['rota' => 5000, 'x' => 'a']],
        'stock-armazem:sa-detalhe' => ['h' => ['produto']], 'MAL chave' => ['h' => ['a']], 'inicio:vazio' => ['o' => []]]]);
    igual(['vendas-promotor:vp', 'stock-armazem:sa-detalhe'], array_keys($p['colunas']), 'chaves válidas e arranjos não vazios');
    igual(['o' => ['docs', 'rota'], 'h' => ['docs'], 'w' => ['rota' => 900]], json_decode(json_encode($p['colunas']['vendas-promotor:vp']), true), 'largura limitada; valores inválidos fora');
    // outra tabela muda; a primeira fica; null repõe
    $p = naval_pref_gravar($login, ['colunas' => ['stock-armazem:sa-detalhe' => null]]);
    igual(['vendas-promotor:vp'], array_keys($p['colunas']));
    igual(['rota' => 900], json_decode(json_encode(naval_pref_ler($login)['colunas']['vendas-promotor:vp']['w']), true), 'a largura gravada volta a ler-se');
    @unlink(naval_pref_ficheiro($login));
});
