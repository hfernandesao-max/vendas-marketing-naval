<?php
/**
 * Base de conhecimento viva do portal: gera-se sozinha a partir do próprio portal, em cada versão, e nunca fica
 * desactualizada. Fontes (todas do repositório; nada é escrito à mão para a base):
 *   - páginas: o catálogo (reports/js/paginas/catalogo.js: nome, área, família) e a explicação no início do código de
 *     cada página (reports/js/paginas/*.js);
 *   - novidades: o CHANGELOG.md (cada alteração com as páginas a que diz respeito);
 *   - regras: os nomes dos testes (tests/portal/*.spec.js e tests/unidade/*.teste.php), que são as regras de negócio
 *     escritas em português e provadas a cada versão;
 *   - funcionamento: a explicação no início de cada serviço do servidor (nucleo/servicos/*.php).
 * O pacote de cada versão leva a base construída (scripts/construir-pacote.php → nucleo/conhecimento/base.json, com os
 * testes, que não vão para o servidor); sem esse ficheiro (desenvolvimento), constrói-se na hora com o que houver.
 *
 * Cada pessoa só encontra o que diz respeito às páginas que pode ver (matriz de acessos); o funcionamento interno que
 * não pertence a nenhuma página fica só para a administração do sistema.
 */
defined('NAVAL_NUCLEO') || exit;

/** Serviço do servidor → página (quando o nome do ficheiro não é o da página). */
const NAVAL_CONH_SERVICO_PAGINA = [
    'carrinha' => 'conciliacao-carrinha', 'carrinha-cortes' => 'conciliacao-carrinha', 'sap' => 'sap-exportar', 'sap-envio' => 'sap-exportar',
    'pinguim-dia' => 'sincronizacao', 'sincronizacao-alertas' => 'sincronizacao', 'caixa' => 'caixa-marketing', 'caixa-entregas' => 'caixa-marketing',
    'requisicoes' => 'requisicoes-carrinha', 'cargas' => 'plano-cargas', 'ordens' => 'cargas-po', 'agt' => 'agt', 'previsao' => 'previsao',
    'pendentes' => 'inicio', 'formas-pagamento' => 'relatorio-vendas', 'calendario' => 'calendario', 'equipamentos' => 'equipamentos',
];
const NAVAL_CONH_PALAVRAS_VAZIAS = ['a', 'o', 'as', 'os', 'de', 'do', 'da', 'dos', 'das', 'e', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma', 'que', 'com',
    'por', 'para', 'se', 'ao', 'aos', 'ou', 'como', 'mais', 'mas', 'nao', 'sem', 'sao', 'foi', 'ser', 'esta', 'este', 'isto', 'qual', 'quais', 'quando',
    'porque', 'porqu', 'onde', 'faco', 'fazer', 'pode', 'posso', 'the', 'and', 'what', 'how', 'why'];

function naval_conh_raiz(): string
{
    return dirname(__DIR__, 2);
}

/** Texto sem marcas de Markdown nem espaços a mais. */
function naval_conh_limpar(string $s): string
{
    $s = (string) preg_replace('/<!--.*?-->/s', '', $s);
    $s = (string) preg_replace('/\*\*|`|^\s*[-*]\s+/m', '', $s);
    $s = (string) preg_replace('/[ \t]+/', ' ', $s);
    return trim((string) preg_replace("/\n{3,}/", "\n\n", $s));
}

/** Páginas do catálogo: {id: {id, nome, grupo, familia}}. */
function naval_conh_paginas(string $raiz): array
{
    $js = (string) @file_get_contents($raiz . '/reports/js/paginas/catalogo.js');
    $out = [];
    preg_match_all("/\{ id: '([a-z0-9-]+)', grupo: (null|'[^']*'), icon: '[^']*', nome: '([^']+)'/", $js, $m, PREG_SET_ORDER);
    foreach ($m as $x) $out[$x[1]] = ['id' => $x[1], 'nome' => $x[3], 'grupo' => $x[2] === 'null' ? '' : trim($x[2], "'"), 'familia' => ''];
    if (preg_match('/export const FAMILIAS = \{(.*?)\n\};/s', $js, $f)) {
        preg_match_all("/^\s*'?([^':\n]+?)'?: \[([^\]]*)\]/m", $f[1], $fm, PREG_SET_ORDER);
        foreach ($fm as $x) {
            preg_match_all("/'([a-z0-9-]+)'/", $x[2], $ids);
            foreach ($ids[1] as $id) if (isset($out[$id])) $out[$id]['familia'] = trim($x[1]);
        }
    }
    return $out;
}

/** Explicação no início do código de cada página: {id: texto}. */
function naval_conh_explicacoes(string $raiz, array $paginas): array
{
    $out = [];
    $camel = [];
    foreach (array_keys($paginas) as $id) $camel[str_replace('-', '', $id)] = $id;
    foreach (glob($raiz . '/reports/js/paginas/*.js') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        $linhas = [];
        foreach (preg_split('/\r?\n/', $src) ?: [] as $l) {
            if (!preg_match('#^//\s?(.*)$#', $l, $m)) break;
            $linhas[] = $m[1];
        }
        $txt = trim(implode("\n", $linhas));
        if ($txt === '') continue;
        preg_match_all("/^R(?:\.([a-zA-Z]+)|\['([a-z0-9-]+)'\])\s*=/m", $src, $r, PREG_SET_ORDER);
        foreach ($r as $x) {
            $id = $x[2] ?? '';
            if ($id === '' && isset($camel[strtolower($x[1])])) $id = $camel[strtolower($x[1])];
            if ($id !== '' && isset($paginas[$id]) && !isset($out[$id])) $out[$id] = $txt;
        }
    }
    return $out;
}

/** Alterações do CHANGELOG.md: [{data, titulo, paginas, tipo, texto}]. */
function naval_conh_novidades(string $raiz): array
{
    $md = str_replace("\r\n", "\n", (string) @file_get_contents($raiz . '/CHANGELOG.md'));
    $out = [];
    $data = '';
    foreach (preg_split('/^(?=## |### )/m', $md) ?: [] as $bloco) {
        if (preg_match('/^## (\d{2}-\d{2}-\d{4})/', $bloco, $m)) {
            $data = $m[1];
            continue;
        }
        if (!preg_match('/^### ([^\n]+)\n(.*)$/s', $bloco, $m) || $data === '') continue;
        preg_match('/<!-- paginas: ([^>]*) -->/', $m[2], $p);
        preg_match('/<!-- tipo: ([a-z]+) -->/', $m[2], $t);
        $out[] = ['data' => $data, 'titulo' => trim($m[1]), 'paginas' => isset($p[1]) ? array_values(array_filter(array_map('trim', explode(',', $p[1])))) : [],
            'tipo' => $t[1] ?? '', 'texto' => naval_conh_limpar($m[2])];
    }
    return $out;
}

/** Regras provadas pelos testes: [{titulo, paginas, origem}]. */
function naval_conh_regras(string $raiz, array $paginas): array
{
    $out = [];
    foreach (glob($raiz . '/tests/portal/*.spec.js') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        preg_match_all("/#([a-z0-9-]+)/", $src, $h);
        $pgs = array_values(array_unique(array_filter($h[1], function ($x) use ($paginas) { return isset($paginas[$x]) && $x !== 'inicio'; })));
        preg_match_all("/^test(?:\.skip)?\(\s*(['\"`])(.+?)\\1,/m", $src, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (strpos($x[2], '${') !== false) continue;
            $out[] = ['titulo' => trim((string) preg_replace('/\s*@\w+/', '', stripcslashes($x[2]))), 'paginas' => count($pgs) <= 3 ? $pgs : [], 'origem' => 'portal/' . basename($f)];
        }
    }
    foreach (glob($raiz . '/tests/unidade/*.teste.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        $pgs = [];
        preg_match_all("/naval_servico\('([a-z0-9-]+)'\)/", $src, $s);
        foreach ($s[1] as $sv) {
            $pg = NAVAL_CONH_SERVICO_PAGINA[$sv] ?? $sv;
            if (isset($paginas[$pg])) $pgs[$pg] = true;
        }
        preg_match_all("/^teste\('((?:[^'\\\\]|\\\\.)+)'/m", $src, $m);
        foreach ($m[1] as $nome) $out[] = ['titulo' => stripslashes($nome), 'paginas' => count($pgs) <= 3 ? array_keys($pgs) : [], 'origem' => 'unidade/' . basename($f)];
    }
    return $out;
}

/** Explicação no início de cada serviço do servidor: [{servico, paginas, texto}]. */
function naval_conh_servicos(string $raiz, array $paginas): array
{
    $out = [];
    foreach (glob($raiz . '/nucleo/servicos/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        if (!preg_match('#^<\?php\s*/\*\*(.*?)\*/#s', $src, $m)) continue;
        $txt = trim((string) preg_replace('/^\s*\* ?/m', '', $m[1]));
        if (mb_strlen($txt) < 60) continue;
        $sv = basename($f, '.php');
        $pg = NAVAL_CONH_SERVICO_PAGINA[$sv] ?? $sv;
        $out[] = ['servico' => $sv, 'paginas' => isset($paginas[$pg]) ? [$pg] : [], 'texto' => $txt];
    }
    return $out;
}

/** Constrói a base a partir do repositório (ou do que houver no servidor). */
function naval_conh_construir(?string $raiz = null): array
{
    $raiz = $raiz ?? naval_conh_raiz();
    $paginas = naval_conh_paginas($raiz);
    $expl = naval_conh_explicacoes($raiz, $paginas);
    $a = [];
    $add = function (string $tipo, string $titulo, string $texto, array $pgs, array $extra = []) use (&$a) {
        $id = $tipo . '-' . substr(sha1($tipo . '|' . $titulo . '|' . implode(',', $pgs)), 0, 10);
        $a[] = ['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo, 'texto' => $texto, 'paginas' => array_values($pgs)] + $extra;
    };
    foreach ($paginas as $p) {
        $onde = trim(implode(' › ', array_filter([$p['grupo'], $p['familia'] !== $p['nome'] ? $p['familia'] : ''])));
        $add('pagina', $p['nome'], trim(($onde !== '' ? 'Onde: ' . $onde . ".\n" : '') . ($expl[$p['id']] ?? '')), [$p['id']], ['url' => '#' . $p['id']]);
    }
    foreach (naval_conh_novidades($raiz) as $n) {
        $add('novidade', $n['titulo'], $n['texto'], array_values(array_filter($n['paginas'], function ($x) use ($paginas) { return isset($paginas[$x]); })),
            ['data' => $n['data'], 'categoria' => $n['tipo']]);
    }
    foreach (naval_conh_regras($raiz, $paginas) as $r) $add('regra', $r['titulo'], '', $r['paginas'], ['origem' => $r['origem']]);
    foreach (naval_conh_servicos($raiz, $paginas) as $s) {
        // título: a primeira frase da explicação (até ao primeiro «:», «.» ou «(»), no máximo 90 caracteres
        $prim = trim((string) preg_split('/[:.(]\s|
\s*
/', $s['texto'])[0]);
        $add('funcionamento', mb_strimwidth((string) preg_replace('/\s+/', ' ', $prim), 0, 90, '…'), $s['texto'], $s['paginas'], ['interno' => !$s['paginas'], 'servico' => $s['servico']]);
    }
    $conta = array_count_values(array_column($a, 'tipo'));
    return ['formato' => 1, 'gerada_em' => date('Y-m-d H:i:s'), 'contagem' => $conta, 'artigos' => $a];
}

/** Base em uso: a do pacote da versão instalada ou, sem ela, construída na hora (guardada 10 minutos). */
function naval_conh_base(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $f = naval_conh_raiz() . '/nucleo/conhecimento/base.json';
    if (is_file($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j) && isset($j['artigos'])) return $b = $j;
    }
    return $b = naval_cache('conhecimento-base', 600, function () { return naval_conh_construir(); });
}

/** Palavras de pesquisa: minúsculas, sem acentos nem palavras vazias, reduzidas aos primeiros 6 caracteres. */
function naval_conh_palavras(string $s): array
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'ü' => 'u']);
    preg_match_all('/[a-z0-9]+/', $s, $m);
    $out = [];
    foreach ($m[0] as $w) {
        if (strlen($w) < 2 || in_array($w, NAVAL_CONH_PALAVRAS_VAZIAS, true)) continue;
        $out[] = substr($w, 0, 6);
    }
    return $out;
}

/**
 * Artigos que a pessoa pode ver: com pelo menos uma página visível (ou nenhuma página e não interno); o
 * funcionamento interno só para quem administra o sistema. $ve: callable(página) → bool.
 */
function naval_conh_visiveis(array $artigos, callable $ve, bool $admin): array
{
    return array_values(array_filter($artigos, function ($x) use ($ve, $admin) {
        if (!empty($x['interno'])) return $admin;
        if (!$x['paginas']) return true;
        foreach ($x['paginas'] as $p) if ($ve($p)) return true;
        return false;
    }));
}

/**
 * Procura (função pura): BM25 (o método dos motores de pesquisa), com o título a valer o triplo, um bónus para a
 * página actual e, em caso de empate, as novidades mais recentes primeiro. Devolve os $limite melhores, com
 * {…, pontos, excerto}. Sem pergunta e com página: tudo o que é dessa página.
 */
function naval_conh_procurar(array $artigos, string $q, ?string $pagina = null, int $limite = 8, array $tipos = []): array
{
    $qp = array_values(array_unique(naval_conh_palavras($q)));
    $docs = [];
    $df = [];
    $soma = 0;
    foreach ($artigos as $i => $x) {
        if ($tipos && !in_array($x['tipo'], $tipos, true)) continue;
        $tt = naval_conh_palavras($x['titulo'] . ' ' . $x['titulo'] . ' ' . $x['titulo']);
        $tx = naval_conh_palavras($x['texto'] . ' ' . implode(' ', $x['paginas']));
        $todas = array_merge($tt, $tx);
        $docs[$i] = [array_count_values($todas), count($todas)];
        $soma += count($todas);
        foreach (array_keys($docs[$i][0]) as $w) $df[$w] = ($df[$w] ?? 0) + 1;
    }
    $n = max(1, count($docs));
    $media = max(1, $soma / $n);
    $res = [];
    foreach ($docs as $i => [$c, $len]) {
        $p = 0.0;
        $achou = 0;
        foreach ($qp as $w) {
            $f = $c[$w] ?? 0;
            if (!$f) continue;
            $achou++;
            $idf = log(1 + ($n - $df[$w] + 0.5) / ($df[$w] + 0.5));
            $p += $idf * ($f * 2.2) / ($f + 1.2 * (0.25 + 0.75 * $len / $media));
        }
        if ($qp && !$achou) continue;
        if ($qp) $p *= $achou / count($qp);   // mais palavras da pergunta → mais acima
        $daPagina = $pagina !== null && in_array($pagina, $artigos[$i]['paginas'], true);
        if ($daPagina) $p += $qp ? 1.0 : 1.0;
        if ($p <= 0) continue;
        $res[] = ['i' => $i, 'p' => $p, 'd' => naval_conh_data_iso($artigos[$i]['data'] ?? '')];
    }
    usort($res, function ($a, $b) { return [$b['p'], $b['d']] <=> [$a['p'], $a['d']]; });
    $out = [];
    foreach (array_slice($res, 0, $limite) as $r) {
        $x = $artigos[$r['i']];
        unset($x['interno']);
        $x['pontos'] = round($r['p'], 3);
        $x['excerto'] = mb_strimwidth((string) preg_replace('/\s+/', ' ', $x['texto']), 0, 280, '…');
        $out[] = $x;
    }
    return $out;
}

function naval_conh_data_iso(string $d): string
{
    return preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $d, $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : '';
}

/** Páginas que a pessoa autenticada pode ver (Início e Alertas sempre). */
function naval_conh_ve_pagina(string $id): bool
{
    return in_array($id, ['inicio', 'alertas'], true) || naval_pode($id);
}

/** O que diz respeito a uma página: a explicação, as novidades (últimas 5) e as regras provadas. */
function naval_conh_pagina(array $artigos, string $pagina): array
{
    $da = array_values(array_filter($artigos, function ($x) use ($pagina) { return in_array($pagina, $x['paginas'], true); }));
    $tipo = function (string $t) use ($da) { return array_values(array_filter($da, function ($x) use ($t) { return $x['tipo'] === $t; })); };
    $nov = $tipo('novidade');
    usort($nov, function ($a, $b) { return naval_conh_data_iso($b['data'] ?? '') <=> naval_conh_data_iso($a['data'] ?? ''); });
    $limpa = function (array $l) { return array_map(function ($x) { unset($x['interno']); return $x; }, $l); };
    return ['pagina' => $limpa($tipo('pagina'))[0] ?? null, 'novidades' => $limpa(array_slice($nov, 0, 5)), 'regras' => $limpa($tipo('regra')),
        'funcionamento' => $limpa($tipo('funcionamento'))];
}
