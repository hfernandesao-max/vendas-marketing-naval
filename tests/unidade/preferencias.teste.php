<?php
// Preferências pessoais: menu lateral e funções favoritas (validadas no servidor).
naval_servico('preferencias');

teste('Preferências: favoritos só com identificadores válidos, sem repetidos e no máximo 6', function () {
    igual([], naval_pref_favoritos('vendas'));
    igual(['vendas-dia', 'agt'], naval_pref_favoritos(['vendas-dia', 'agt', 'vendas-dia', '../x', 'A B', 7, str_repeat('a', 41)]));
    igual(6, count(naval_pref_favoritos(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])));
    $n = naval_pref_normalizar(['barra' => 'oculta', 'lado' => 'x', 'favoritos' => ['agt']]);
    igual(['barra' => 'oculta', 'lado' => 'esquerda', 'favoritos' => ['agt']], $n);
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
