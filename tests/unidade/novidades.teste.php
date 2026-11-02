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
