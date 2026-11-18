<?php
// Leitura do registo de alterações para o resumo das actualizações (nucleo/servicos/novidades.php).
naval_servico('novidades');

teste('Novidades: secções por dia e título, pontos com continuação, ids estáveis e únicos', function () {
    $md = "# Registo\n\nIntro.\n\n## 02-11-2026\n\n### Velocidade\n- **Compressão:** HTML e\n  CSS comprimidos.\n- Cache.\n\n### Vazia\n\n## 01-11-2026\n\n### Velocidade\n- Outro dia.\n### Velocidade\n- Repetida.\n";
    $s = naval_nov_seccoes($md);
    igual(['02-11-2026|Velocidade', '01-11-2026|Velocidade', '01-11-2026|Velocidade'], array_map(function ($x) { return $x['data'] . '|' . $x['titulo']; }, $s));
    igual(['**Compressão:** HTML e CSS comprimidos.', 'Cache.'], $s[0]['itens']);
    igual(3, count(array_unique(array_column($s, 'id'))), 'ids únicos');
    // corrigir o texto não muda o id
    igual($s[0]['id'], naval_nov_seccoes(str_replace('Cache.', 'Cache longa.', $md))[0]['id']);
});

teste('novidades: páginas de cada secção (<!-- paginas: … -->) e selo «Novo» só nas últimas 24 horas', function () {
    $md = "## 13-11-2026\n\n### Substituição de facturas\n\n<!-- paginas: pagamentos, vendas-promotor -->\n\n- Ponto.\n\n### Sem páginas\n\n- Outro.\n";
    $s = naval_nov_seccoes($md);
    igual(['pagamentos', 'vendas-promotor'], $s[0]['paginas']);
    igual(['Ponto.'], $s[0]['itens']);
    igual([], $s[1]['paginas']);
    $agora = strtotime('2026-11-13 15:00');
    $lista = [
        ['id' => 'a2', 'detectada_em' => '2026-11-13 10:00:00', 'versao' => 'v2', 'seccoes' => [$s[0]]],
        ['id' => 'a1', 'detectada_em' => '2026-11-12 09:00:00', 'versao' => 'v1', 'seccoes' => [['id' => 'x', 'titulo' => 'Antiga', 'itens' => ['p'], 'paginas' => ['inicio']]]],
    ];
    $r = naval_nov_recentes($agora, 24, $lista);
    igual(['pagamentos', 'vendas-promotor'], array_column($r, 'pagina'));
    igual('Substituição de facturas', $r[0]['titulo']);
    igual(strtotime('2026-11-13 10:00:00'), $r[0]['desde_ts']);
    // 24 horas depois já não é novo
    igual([], naval_nov_recentes(strtotime('2026-11-14 10:00'), 24, $lista));
});

teste('novidades: aviso aos utilizadores (linhas «> »): título, o que muda, passos, traduções e continuação', function () {
    $md = "## 18-11-2026\n\n### Técnico\n\n<!-- paginas: caixa-marketing -->\n<!-- publico: todos -->\n\n"
        . "> titulo: Caixa nova\n> titulo [en]: New cash\n> - O que muda\n> continua aqui.\n> [en] What changes\n> [ti] ለውጢ\n>\n> 1. Primeiro passo\n> [en] First step\n> 2. Segundo\n\n- Ponto técnico.\n\n"
        . "### Sem aviso\n\n- Só técnico.\n";
    $s = naval_nov_seccoes($md);
    igual(['Ponto técnico.'], $s[0]['itens'], 'as linhas «> » não entram nos pontos técnicos');
    igual('todos', $s[0]['publico']);
    igual(['pt' => 'Caixa nova', 'en' => 'New cash'], $s[0]['aviso']['titulo']);
    igual([['pt' => 'O que muda continua aqui.', 'en' => 'What changes', 'ti' => 'ለውጢ']], $s[0]['aviso']['resumo']);
    igual([['pt' => 'Primeiro passo', 'en' => 'First step'], ['pt' => 'Segundo']], $s[0]['aviso']['passos']);
    verdade(!isset($s[1]['aviso']));
    // público: todos, sem páginas, ou quem vê alguma das páginas
    $ve = function ($p) { return $p === 'caixa-marketing'; };
    $nao = function () { return false; };
    verdade(naval_nov_para($s[0], $nao), 'todos');
    unset($s[0]['publico']);
    verdade(naval_nov_para($s[0], $ve) && !naval_nov_para($s[0], $nao), 'só quem vê a página');
    verdade(!naval_nov_para($s[1], $ve), 'sem aviso não aparece');
});

teste('novidades: avisos por confirmar, «Li e compreendi» registado e adesão por actualização', function () {
    $dir = naval_pasta('novidades');
    $guardar = [];
    foreach (['actualizacoes.json', 'confirmacoes.json', 'conhecidas.json'] as $f) $guardar[$f] = is_file($dir . '/' . $f) ? (string) file_get_contents($dir . '/' . $f) : null;
    $uf = naval_ficheiro_utilizadores();
    $uAntes = is_file($uf) ? (string) file_get_contents($uf) : null;
    try {
        $aviso = ['titulo' => ['pt' => 'X'], 'resumo' => [['pt' => 'Muda']], 'passos' => []];
        $lista = [['id' => 'a1', 'detectada_em' => '2026-11-18 09:00:00', 'versao' => 'v1', 'seccoes' => [
            ['id' => 's1', 'data' => '18-11-2026', 'titulo' => 'Caixa', 'itens' => ['p'], 'paginas' => ['caixa-marketing'], 'tipo' => '', 'aviso' => $aviso],
            ['id' => 's2', 'data' => '18-11-2026', 'titulo' => 'Técnico', 'itens' => ['p'], 'paginas' => ['caixa-marketing'], 'tipo' => ''],
        ]]];
        naval_json_gravar($dir . '/actualizacoes.json', $lista);
        @unlink($dir . '/confirmacoes.json');
        // o CHANGELOG não traz secções novas: a lista fica a guardada
        naval_json_gravar($dir . '/conhecidas.json', array_column(naval_nov_seccoes((string) file_get_contents(naval_nov_ficheiro_changelog())), 'id'));
        naval_gravar_utilizadores([
            'tes' => ['nome' => 'Tesouraria', 'email' => 'tes@naval.co.ao', 'perfil' => 'FIN', 'activo' => true],
            'geo' => ['nome' => 'Geo', 'email' => 'geo@naval.co.ao', 'perfil' => 'GEO', 'activo' => true],
        ]);
        $a = naval_nov_adesao();
        igual(1, count($a));
        igual(['destinatarios' => 1, 'confirmaram' => 0, 'faltam' => ['Tesouraria']], array_intersect_key($a[0], ['destinatarios' => 1, 'confirmaram' => 1, 'faltam' => 1]), 'só quem vê a caixa');
        igual(1, naval_nov_confirmar('tes', ['a1', 'inexistente']));
        $h = naval_json_ler($dir . '/confirmacoes.json')['a1']['tes'];
        naval_nov_confirmar('tes', ['a1']);
        igual($h, naval_json_ler($dir . '/confirmacoes.json')['a1']['tes'], 'repetir não muda a hora registada');
        $c = naval_json_ler($dir . '/confirmacoes.json');
        verdade(isset($c['a1']['tes']) && !isset($c['inexistente']));
        $a = naval_nov_adesao();
        igual([1, 1, []], [$a[0]['destinatarios'], $a[0]['confirmaram'], $a[0]['faltam']]);
    } finally {
        foreach ($guardar as $f => $x) if ($x === null) @unlink($dir . '/' . $f); else file_put_contents($dir . '/' . $f, $x);
        if ($uAntes === null) @unlink($uf); else file_put_contents($uf, $uAntes);
    }
});
