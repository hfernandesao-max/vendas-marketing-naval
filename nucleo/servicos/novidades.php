<?php
/**
 * Resumo das actualizações do portal para o super administrador (janela na primeira entrada depois de cada
 * actualização). A origem é o registo de alterações do portal (CHANGELOG.md, na raiz; o Apache não o serve).
 *
 * Funciona com qualquer forma de actualização (manual ou automática): o servidor guarda as secções do registo
 * que já conhecia (pasta privada novidades/conhecidas.json); secções novas no CHANGELOG.md formam uma
 * «actualização» (novidades/actualizacoes.json), com a versão instalada pelo actualizador quando existe. Na primeira
 * vez, a base é o registo de alterações da versão do servidor juntada ao GitHub (nucleo/recursos/novidades-base.json).
 * Cada super administrador vê as actualizações que ainda não fechou (novidades/vistas.json).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_NOV_MAX = 10;   // actualizações guardadas e mostradas

function naval_nov_ficheiro_changelog(): string
{
    return naval_raiz() . '/CHANGELOG.md';
}

/**
 * Secções do registo de alterações: [{id, data, titulo, itens}]. «## <data>» abre um dia, «### <título>» uma
 * secção, «- » um ponto (as linhas seguintes com espaços continuam o mesmo ponto). O id depende só do dia e do
 * título: corrigir o texto de uma secção já publicada não a volta a mostrar como nova.
 */
function naval_nov_seccoes(string $md): array
{
    $out = [];
    $data = '';
    $actual = null;
    $ids = [];
    foreach (preg_split('/\r\n|\n|\r/', $md) ?: [] as $l) {
        if (preg_match('/^##\s+(.+?)\s*$/', $l, $m) && strpos($l, '###') !== 0) {
            naval_nov_fechar($actual, $out);
            $data = $m[1];
            continue;
        }
        if (preg_match('/^###\s+(.+?)\s*$/', $l, $m)) {
            naval_nov_fechar($actual, $out);
            if ($data === '') continue;
            $id = substr(hash('sha256', $data . '|' . $m[1]), 0, 16);
            for ($n = 2; isset($ids[$id]); $n++) $id = substr(hash('sha256', $data . '|' . $m[1] . '|' . $n), 0, 16);
            $ids[$id] = true;
            $actual = ['id' => $id, 'data' => $data, 'titulo' => $m[1], 'itens' => []];
            continue;
        }
        if ($actual === null) continue;
        if (preg_match('/^[-*]\s+(.*)$/', $l, $m)) {
            $actual['itens'][] = trim($m[1]);
        } elseif (preg_match('/^\s+(\S.*)$/', $l, $m) && $actual['itens']) {
            $actual['itens'][count($actual['itens']) - 1] .= ' ' . trim($m[1]);
        }
    }
    naval_nov_fechar($actual, $out);
    return $out;
}

/**
 * Fecha a secção em curso (só fica se tiver pontos).
 *
 * @param-out null $actual
 */
function naval_nov_fechar(?array &$actual, array &$out): void
{
    if ($actual !== null && $actual['itens']) $out[] = $actual;
    $actual = null;
}

/** Identificadores das secções que o servidor já conhecia (na primeira vez, os da versão de base). */
function naval_nov_conhecidas(): array
{
    $f = naval_pasta('novidades') . '/conhecidas.json';
    if (is_file($f)) return naval_json_ler($f);
    return naval_json_ler(naval_raiz() . '/nucleo/recursos/novidades-base.json');
}

/**
 * Regista como actualização as secções novas do registo de alterações (uma vez por actualização instalada).
 * Devolve as actualizações guardadas (a mais recente primeiro).
 */
function naval_nov_detectar(): array
{
    return naval_exclusivo('novidades', function () {
        $dir = naval_pasta('novidades');
        $lista = naval_json_ler($dir . '/actualizacoes.json');
        $md = @file_get_contents(naval_nov_ficheiro_changelog());
        if (!is_string($md) || $md === '') return $lista;
        $seccoes = naval_nov_seccoes($md);
        $conhecidas = array_flip(naval_nov_conhecidas());
        $novas = array_values(array_filter($seccoes, function ($s) use ($conhecidas) { return !isset($conhecidas[$s['id']]); }));
        if (!$novas) return $lista;
        $estado = naval_json_ler(naval_pasta('actualizacoes') . '/estado.json');
        array_unshift($lista, [
            'id' => date('YmdHis') . '-' . substr(hash('sha256', (string) json_encode(array_column($novas, 'id'))), 0, 8),
            'detectada_em' => date('Y-m-d H:i:s'),
            'versao' => (string) ($estado['versao'] ?? ''),
            'seccoes' => $novas,
        ]);
        $lista = array_slice($lista, 0, NAVAL_NOV_MAX);
        naval_json_gravar($dir . '/actualizacoes.json', $lista);
        naval_json_gravar($dir . '/conhecidas.json', array_values(array_unique(array_merge(array_keys($conhecidas), array_column($seccoes, 'id')))));
        naval_registar('novidades_detectadas', ['seccoes' => count($novas), 'versao' => (string) ($estado['versao'] ?? '')], 'portal');
        return $lista;
    });
}

/** Actualizações que esta conta ainda não fechou. */
function naval_nov_pendentes(string $login): array
{
    $lista = naval_nov_detectar();
    $vistas = naval_json_ler(naval_pasta('novidades') . '/vistas.json');
    $minhas = array_flip((array) ($vistas[$login] ?? []));
    return array_values(array_filter($lista, function ($a) use ($minhas) { return !isset($minhas[$a['id']]); }));
}

/** Marca actualizações como vistas por esta conta (só identificadores existentes). */
function naval_nov_marcar(string $login, array $ids): int
{
    return naval_exclusivo('novidades', function () use ($login, $ids) {
        $dir = naval_pasta('novidades');
        $existentes = array_column(naval_json_ler($dir . '/actualizacoes.json'), 'id');
        $ids = array_values(array_intersect(array_map('strval', $ids), $existentes));
        $vistas = naval_json_ler($dir . '/vistas.json');
        $vistas[$login] = array_values(array_intersect(array_unique(array_merge((array) ($vistas[$login] ?? []), $ids)), $existentes));
        naval_json_gravar($dir . '/vistas.json', $vistas);
        return count($ids);
    });
}
