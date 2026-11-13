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
