<?php
/**
 * Preferências de cada pessoa (seguem a conta em qualquer computador ou telemóvel): como quer o menu lateral.
 *   barra: fixa (sempre visível) | compacta (só ícones; abre ao passar) | oculta (abre com o botão Menu)
 *   lado:  esquerda | direita
 *   favoritos: até 6 funções fixadas no topo do menu (identificadores das páginas, por ordem de escolha)
 * Boas práticas de navegação (WAI-ARIA, Fluent «navigation pane», Material «navigation rail»): o utilizador escolhe
 * o modo e a posição; a escolha é pessoal e não altera os acessos.
 */

const NAVAL_PREFERENCIAS = [
    'barra' => ['fixa', 'compacta', 'oculta'],
    'lado' => ['esquerda', 'direita'],
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
    naval_json_gravar(naval_pref_ficheiro($login), $actual);
    return $actual;
}
