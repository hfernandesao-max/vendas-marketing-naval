<?php
// Módulos do portal (nucleo/servicos/modulos.php): as fichas cobrem tudo, uma só vez, e o estado respeita as regras.
naval_servico('modulos');
require_once dirname(__DIR__, 2) . '/nucleo/api/router.php';

/** Páginas do catálogo do portal (reports/js/paginas/catalogo.js). */
function modulos_paginas_catalogo(): array
{
    preg_match_all("/\\{ id: '([a-z0-9-]+)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/reports/js/paginas/catalogo.js'), $m);
    return $m[1];
}

teste('módulos: fichas válidas, versão semântica, dependências que existem e sem ciclos', function () {
    $m = naval_modulos();
    verdade(count($m) >= 10, 'poucos módulos');
    foreach ($m as $id => $x) {
        verdade((bool) preg_match('/^\d+\.\d+\.\d+$/', $x['versao']), 'versão de ' . $id);
        verdade($x['nome'] !== '' && $x['descricao'] !== '', 'nome e descrição de ' . $id);
        foreach ($x['depende'] as $d) verdade(isset($m[$d]), $id . ' depende de ' . $d . ', que não existe');
        foreach ($x['conectores'] as $c) verdade(isset(NAVAL_CONECTORES[$c]), $id . ': conector desconhecido ' . $c);
        verdade(in_array($x['ensaio'], ['normal', 'so_leitura'], true), 'modo do ensaio de ' . $id);
    }
    // sem ciclos: cada cadeia de dependências termina
    $visitar = function (string $id, array $caminho) use (&$visitar, $m) {
        verdade(!in_array($id, $caminho, true), 'ciclo de dependências: ' . implode(' → ', array_merge($caminho, [$id])));
        foreach ($m[$id]['depende'] as $d) $visitar($d, array_merge($caminho, [$id]));
    };
    foreach (array_keys($m) as $id) $visitar($id, []);
    verdade($m['nucleo']['nucleo'] && $m['administracao']['nucleo'], 'núcleo e administração são do núcleo');
});

teste('módulos: cada página, função da matriz e rota da API pertence a um e um só módulo', function () {
    $paginas = [];
    $recursos = [];
    $rotas = [];
    foreach (naval_modulos() as $id => $x) {
        foreach ($x['paginas'] as $p) $paginas[$p][] = $id;
        foreach ($x['recursos'] as $r) $recursos[$r][] = $id;
        foreach ($x['api'] as $r) $rotas[$r][] = $id;
    }
    foreach (modulos_paginas_catalogo() as $p) igual(1, count($paginas[$p] ?? []), 'página ' . $p . ' em ' . json_encode($paginas[$p] ?? []));
    foreach (array_keys($paginas) as $p) verdade(in_array($p, modulos_paginas_catalogo(), true), 'página da ficha que não existe no catálogo: ' . $p);
    foreach (array_keys(naval_matriz_base()) as $r) igual(1, count($recursos[$r] ?? []), 'função ' . $r . ' em ' . json_encode($recursos[$r] ?? []));
    foreach (array_keys($recursos) as $r) verdade(isset(naval_matriz_base()[$r]), 'função da ficha que não existe na matriz: ' . $r);
    foreach (array_keys(naval_api_rotas()) as $r) igual(1, count($rotas[$r] ?? []), 'rota ' . $r . ' em ' . json_encode($rotas[$r] ?? []));
});

teste('módulos: ligado para todos, piloto só para SADM e TI, desligado para ninguém; o núcleo nunca se desliga', function () {
    @unlink(naval_modulos_ficheiro());
    naval_modulos_estados(true);
    verdade(naval_modulo_disponivel('sap', 'FIN') && naval_recurso_disponivel('sap-exportar', 'FIN'), 'ligado por omissão');
    naval_modulo_definir('sap', 'piloto', 'ensaio com a Tesouraria');
    verdade(!naval_modulo_disponivel('sap', 'FIN') && naval_modulo_disponivel('sap', 'TI') && naval_modulo_disponivel('sap', 'SADM'), 'piloto');
    verdade(!naval_recurso_disponivel('sap-exportar', 'BO') && naval_recurso_disponivel('documentos', 'BO'), 'recurso do módulo em piloto');
    naval_modulo_definir('sap', 'desligado');
    verdade(!naval_modulo_disponivel('sap', 'SADM'), 'desligado também para o SADM');
    lanca(409, function () { naval_modulo_definir('nucleo', 'desligado'); });
    lanca(409, function () { naval_modulo_definir('administracao', 'piloto'); });
    lanca(400, function () { naval_modulo_definir('sap', 'meio'); });
    lanca(404, function () { naval_modulo_definir('nao-existe', 'ligado'); });
    // dependências: «Clientes» não se desliga enquanto «Terreno» (que depende dele) estiver ligado
    lanca(409, function () { naval_modulo_definir('clientes', 'desligado'); });
    naval_modulo_definir('sap', 'ligado');
    igual('ligado', naval_modulos_estados()['sap']);
    $v = naval_modulos_vista();
    verdade(count($v['modulos']) === count(naval_modulos()) && $v['conectores'], 'vista');
    igual('por_ligar', array_column($v['conectores'], 'estado', 'id')['quatenus'], 'Quatenus por ligar');
    igual('ligado', array_column($v['conectores'], 'estado', 'id')['sap'], 'SAP (ficheiro) sempre disponível');
});

teste('módulos: fora do ensaio, nenhum módulo é só de leitura', function () {
    verdade(!naval_ensaio(), 'os testes correm na produção');
    verdade(!naval_modulo_so_leitura_no_ensaio('equipamentos'), 'equipamentos só lê apenas no ensaio');
    igual('so_leitura', naval_modulos()['equipamentos']['ensaio']);
    verdade(naval_ensaio_permitido(['perfil' => 'PRO']), 'fora do ensaio todos entram');
    igual('', naval_faixa_ensaio(), 'sem faixa na produção');
});
