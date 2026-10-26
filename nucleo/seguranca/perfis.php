<?php
/**
 * Perfis de acesso dinâmicos.
 *
 * Os 11 perfis do sistema (SADM … AUD) vêm do código e não se alteram. O super administrador pode criar
 * perfis novos no portal: código, nome, perfil de origem (as permissões são copiadas dele e ficam como a
 * «matriz original» desse perfil), âmbito só das suas rotas e verificação em dois passos obrigatória.
 * Ficam em dados-privados/perfis.json e passam a ser colunas da matriz de acessos.
 * Um perfil criado nunca recebe o nível A (administrar): esse é só do SADM.
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PERFIS_SISTEMA_MFA = ['SADM', 'TI', 'DIR'];

function naval_ficheiro_perfis(): string
{
    return naval_pasta_privada() . '/perfis.json';
}

/** Perfis criados no portal: {código: {nome, base, por_rota, dois_passos, activo, matriz, criado, ...}}. */
function naval_perfis_criados(bool $recarregar = false): array
{
    static $p = null;
    if ($p === null || $recarregar) {
        $p = [];
        foreach (naval_json_ler(naval_ficheiro_perfis()) as $c => $x) {
            if (is_array($x) && preg_match('/^[A-Z][A-Z0-9]{1,7}$/', (string) $c) && !isset(NAVAL_PERFIS[$c])) $p[(string) $c] = $x;
        }
    }
    return $p;
}

function naval_gravar_perfis(array $p): void
{
    ksort($p);
    naval_json_gravar(naval_ficheiro_perfis(), $p);
    naval_perfis_criados(true);
    naval_matriz(true);
}

/** Todos os perfis com as suas características (sistema primeiro, depois os criados). */
function naval_perfis_todos(): array
{
    $out = [];
    foreach (NAVAL_PERFIS as $c => $nome) {
        $out[$c] = ['codigo' => $c, 'nome' => $nome, 'sistema' => true, 'activo' => true, 'base' => null,
            'por_rota' => in_array($c, NAVAL_POR_ROTA, true), 'dois_passos' => in_array($c, NAVAL_PERFIS_SISTEMA_MFA, true)];
    }
    foreach (naval_perfis_criados() as $c => $x) {
        $out[$c] = ['codigo' => $c, 'nome' => (string) ($x['nome'] ?? $c), 'sistema' => false, 'activo' => !empty($x['activo']), 'base' => $x['base'] ?? null,
            'por_rota' => !empty($x['por_rota']), 'dois_passos' => !empty($x['dois_passos'])] + array_intersect_key($x, array_flip(['criado', 'criado_por', 'alterado', 'alterado_por']));
    }
    return $out;
}

/** Perfis activos: {código: nome}. */
function naval_perfis(): array
{
    $out = [];
    foreach (naval_perfis_todos() as $c => $x) if ($x['activo']) $out[$c] = $x['nome'];
    return $out;
}

function naval_perfil_nome(string $c): string
{
    $t = naval_perfis_todos();
    return isset($t[$c]) ? $t[$c]['nome'] : $c;
}

/** Colunas editáveis da matriz: os perfis do sistema (excepto SADM) e os criados activos. */
function naval_colunas(): array
{
    $c = NAVAL_COLUNAS;
    foreach (naval_perfis_criados() as $k => $x) if (!empty($x['activo'])) $c[] = $k;
    return $c;
}

/** Perfis em que o âmbito é sempre limitado às rotas atribuídas. */
function naval_perfis_por_rota(): array
{
    return array_keys(array_filter(naval_perfis_todos(), function ($x) { return $x['activo'] && $x['por_rota']; }));
}

/** Perfis que têm de usar a verificação em dois passos. */
function naval_perfis_mfa(): array
{
    return array_keys(array_filter(naval_perfis_todos(), function ($x) { return $x['activo'] && $x['dois_passos']; }));
}
