<?php
// Base de conhecimento viva: gerada do próprio portal (páginas, CHANGELOG, testes e serviços), procura e acessos.
naval_servico('conhecimento');

teste('base de conhecimento: gera-se do portal — todas as páginas, as novidades com as páginas, as regras dos testes e o funcionamento', function () {
    $b = naval_conh_construir();
    $c = $b['contagem'];
    verdade($c['pagina'] >= 45, 'páginas: ' . $c['pagina']);
    verdade($c['novidade'] >= 200 && $c['regra'] >= 500 && $c['funcionamento'] >= 60, 'contagens: ' . json_encode($c));
    $por = [];
    foreach ($b['artigos'] as $a) $por[$a['tipo'] . '|' . $a['titulo']] = $a;
    $sinc = $por['pagina|Sincronização dos telemóveis'];
    igual(['sincronizacao'], $sinc['paginas'], 'página');
    verdade(strpos($sinc['texto'], 'abertura e o fecho do dia') !== false, 'explicação do código da página');
    $lote = $por['novidade|Ficheiro para o SAP: lotes complementares'];
    igual(['sap-exportar'], $lote['paginas'], 'páginas da novidade (CHANGELOG)');
    verdade(strpos($lote['texto'], '<!--') === false && strpos($lote['texto'], '**') === false, 'sem marcas');
    // a regra deste próprio ficheiro também entra (os testes são as regras)
    verdade(isset($por['regra|dia no Pinguim BO: abertura certa ou na véspera; fecho a tempo, atrasado, por fechar ou ainda aberto']), 'regra dos testes unitários');
    // páginas sem explicação no código: no máximo 2 (a base mostra-o)
    $sem = array_filter($b['artigos'], function ($a) { return $a['tipo'] === 'pagina' && strpos($a['texto'], "\n") === false; });
    verdade(count($sem) <= 2, 'páginas sem explicação: ' . implode(', ', array_column($sem, 'titulo')));
});

teste('base de conhecimento: procura pelo sentido das palavras (BM25), a página actual primeiro e só o que a pessoa pode ver', function () {
    $todos = naval_conh_construir()['artigos'];
    $r = naval_conh_procurar($todos, 'lote complementar SAP', null, 3);
    verdade(in_array('sap-exportar', $r[0]['paginas'], true), 'primeiro: ' . $r[0]['titulo']);
    $r = naval_conh_procurar($todos, 'dia por fechar pinguim', null, 3);
    verdade(in_array('sincronizacao', $r[0]['paginas'], true), 'primeiro: ' . $r[0]['titulo']);
    igual([], naval_conh_procurar($todos, 'zzzzqqq'), 'nada');
    // só a Sincronização à vista: nada só do SAP, nada interno
    $vis = naval_conh_visiveis($todos, function ($p) { return $p === 'sincronizacao'; }, false);
    foreach ($vis as $a) {
        verdade(empty($a['interno']), 'interno à vista: ' . $a['titulo']);
        verdade(!$a['paginas'] || in_array('sincronizacao', $a['paginas'], true), 'página não visível: ' . $a['titulo']);
    }
    verdade(count(naval_conh_visiveis($todos, function () { return true; }, true)) === count($todos), 'administração vê tudo');
    $p = naval_conh_pagina($vis, 'sincronizacao');
    igual('Sincronização dos telemóveis', $p['pagina']['titulo'], 'página');
    verdade(count($p['regras']) > 0 && count($p['novidades']) > 0 && count($p['novidades']) <= 5, 'regras e novidades');
    $datas = array_map('naval_conh_data_iso', array_column($p['novidades'], 'data'));
    $ord = $datas;
    rsort($ord);
    igual($ord, $datas, 'novidades da mais recente para a mais antiga');
});
