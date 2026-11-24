<?php
/**
 * Preferências de cada pessoa (seguem a conta em qualquer computador ou telemóvel): como quer o menu lateral.
 *   barra: fixa (sempre visível) | compacta (só ícones; abre ao passar) | oculta (abre com o botão Menu)
 *   lado:  esquerda | direita
 *   densidade: confortavel | compacta (altura das linhas das tabelas)
 *   favoritos: até 6 funções fixadas no topo do menu (identificadores das páginas, por ordem de escolha)
 *   colunas: arranjo das colunas de cada tabela, «página:tabela» => {o: ordem, h: escondidas, w: {coluna: largura px}}
 * Boas práticas de navegação (WAI-ARIA, Fluent «navigation pane», Material «navigation rail»): o utilizador escolhe
 * o modo e a posição; a escolha é pessoal e não altera os acessos.
 */

const NAVAL_PREFERENCIAS = [
    'barra' => ['fixa', 'compacta', 'oculta'],
    'lado' => ['esquerda', 'direita'],
    'densidade' => ['confortavel', 'compacta'],
];

function naval_pref_ficheiro(string $login): string
{
    return naval_pasta('preferencias') . '/' . hash('sha256', strtolower($login)) . '.json';
}

/** Preferências válidas (com os valores de origem para o que falta). */
function naval_pref_normalizar(array $p): array
{
    $out = [];
    foreach (NAVAL_PREFERENCIAS as $k => $validos) $out[$k] = in_array($p[$k] ?? null, $validos, true) ? $p[$k] : $validos[0];
    $out['favoritos'] = naval_pref_favoritos($p['favoritos'] ?? []);
    $out['colunas'] = naval_pref_colunas($p['colunas'] ?? []);
    return $out;
}

const NAVAL_PREF_TABELAS_MAX = 400;   // tabelas com arranjo próprio por pessoa
const NAVAL_PREF_COLUNAS_MAX = 120;   // colunas por tabela

/** Arranjo de uma tabela válido (ou null se vazio/inválido). */
function naval_pref_layout($v): ?array
{
    if (!is_array($v)) return null;
    $ids = function ($l) {
        if (!is_array($l)) return [];
        $out = [];
        foreach ($l as $x) {
            if (!is_string($x) || $x === '' || mb_strlen($x) > 60 || in_array($x, $out, true)) continue;
            $out[] = $x;
            if (count($out) >= NAVAL_PREF_COLUNAS_MAX) break;
        }
        return $out;
    };
    $w = [];
    $wv = $v['w'] ?? [];
    if (is_object($wv)) $wv = (array) $wv;
    foreach ((is_array($wv) ? $wv : []) as $k => $px) {
        if (!is_string($k) || $k === '' || mb_strlen($k) > 60 || !is_numeric($px)) continue;
        $w[$k] = max(48, min(900, (int) $px));
        if (count($w) >= NAVAL_PREF_COLUNAS_MAX) break;
    }
    $x = ['o' => $ids($v['o'] ?? []), 'h' => $ids($v['h'] ?? []), 'w' => (object) $w];
    return $x['o'] || $x['h'] || $w ? $x : null;
}

/** Arranjos das tabelas: chaves «página:tabela» (letras, algarismos, hífen, ponto, sublinhado), no máximo 400. */
function naval_pref_colunas($v): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $k => $l) {
        if (!is_string($k) || !preg_match('/^[a-z0-9-]{1,40}:[A-Za-z0-9_.:-]{1,100}$/', $k)) continue;
        $x = naval_pref_layout($l);
        if ($x === null) continue;
        $out[$k] = $x;
        if (count($out) >= NAVAL_PREF_TABELAS_MAX) break;
    }
    return $out;
}

/** Favoritos válidos: identificadores de páginas (letras, algarismos e hífen), sem repetidos, no máximo 6. */
function naval_pref_favoritos($v): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        if (!is_string($x) || !preg_match('/^[a-z0-9-]{1,40}$/', $x) || in_array($x, $out, true)) continue;
        $out[] = $x;
        if (count($out) >= 6) break;
    }
    return $out;
}

function naval_pref_ler(string $login): array
{
    return naval_pref_normalizar(naval_json_ler(naval_pref_ficheiro($login)));
}

/** Junta as alterações (só chaves e valores conhecidos) e grava. */
function naval_pref_gravar(string $login, array $alteracoes): array
{
    $actual = naval_pref_ler($login);
    foreach (NAVAL_PREFERENCIAS as $k => $validos) if (in_array($alteracoes[$k] ?? null, $validos, true)) $actual[$k] = $alteracoes[$k];
    if (array_key_exists('favoritos', $alteracoes)) $actual['favoritos'] = naval_pref_favoritos($alteracoes['favoritos']);
    // colunas: só as tabelas enviadas mudam; null repõe a tabela de origem
    if (is_array($alteracoes['colunas'] ?? null)) {
        $c = $actual['colunas'];
        foreach ($alteracoes['colunas'] as $k => $l) {
            if (!is_string($k)) continue;
            unset($c[$k]);
            if ($l !== null) $c[$k] = $l;
        }
        $actual['colunas'] = naval_pref_colunas($c);
    }
    naval_json_gravar(naval_pref_ficheiro($login), $actual);
    return $actual;
}
