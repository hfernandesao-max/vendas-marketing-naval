<?php
/**
 * Matriz de acessos («Matriz de acessos · Plataforma RTM e GeoMobilidade Naval», 26-10-2026):
 *   - 11 perfis (SADM de emergência + 10 perfis de trabalho);
 *   - nível por recurso: '' sem acesso, V ver, Vr ver só as suas rotas, E exportar,
 *     Er exportar só as suas rotas, G gerir, A administrar;
 *   - âmbito por rota (SUP e PRO) e dados pessoais (NIF) só para quem precisa.
 * A matriz do código pode ser alterada pelo super administrador no portal (dados-privados/matriz.json).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_PERFIS = [
    'SADM' => 'Super administrador',
    'TI'   => 'Administrador da plataforma',
    'DIR'  => 'Administração e Direcção',
    'COM'  => 'Direcção Comercial e Marketing',
    'SUP'  => 'Supervisor de rotas',
    'PRO'  => 'Promotor',
    'FIN'  => 'Financeiro e Tesouraria',
    'BO'   => 'Back-office RTM',
    'LOG'  => 'Logística e Armazém',
    'GEO'  => 'Planeamento de rotas',
    'AUD'  => 'Auditoria e Controlo',
];

// Ordem das colunas da matriz: TI DIR COM SUP PRO FIN BO LOG GEO AUD ('-' = sem acesso).
// O SADM tem 'A' em tudo.
const NAVAL_COLUNAS = ['TI', 'DIR', 'COM', 'SUP', 'PRO', 'FIN', 'BO', 'LOG', 'GEO', 'AUD'];
const NAVAL_MATRIZ_TXT = [
    // REPORTS
    'pagina-principal'     => 'V  V  V  V  V  V  V  V  V  V',
    'inicio'               => 'V  E  E  Vr Vr E  V  V  -  V',
    'vendas-promotor'      => 'V  E  E  Vr Vr E  -  -  -  E',
    'vendas-dia'           => 'V  E  E  Vr Vr E  -  -  -  E',
    'vendas-cliente'       => 'V  E  E  Er Vr E  -  -  -  E',
    'ranking'              => 'V  E  E  Vr Vr V  -  -  -  V',
    'documentos'           => 'V  E  E  Er Vr E  V  -  -  E',
    'pagamentos'           => 'V  E  V  Vr Vr E  -  -  -  E',
    'pendentes'            => 'V  E  V  Vr Vr E  -  -  -  E',
    'auditoria'            => 'V  V  E  Vr -  E  V  -  -  E',
    'stock-carrinha'       => 'V  V  E  Vr Vr V  -  E  -  V',
    'stock-armazem'        => 'V  V  E  -  -  V  -  E  -  V',
    'vendas-artigo'        => 'V  E  E  Vr Vr E  -  E  -  E',
    'vendas-marca'         => 'V  E  E  Vr Vr E  -  -  -  E',
    'plano-cargas'         => 'V  V  V  Vr -  -  V  G  V  V',
    'sap-exportar'         => 'V  -  -  -  -  E  E  -  -  V',
    'cadastros'            => 'V  V  E  Vr Vr -  E  -  V  V',
    'cadastros-analise'    => 'V  V  E  Vr Vr -  E  -  -  V',
    'cadastros-clientes'   => 'V  -  E  Er Vr -  E  -  V  V',
    'clientes'             => 'V  -  E  Er Vr V  E  -  V  V',
    'plano-visitas'        => 'V  V  E  Vr Vr -  V  -  V  V',
    'excel-completo'       => '-  E  E  -  -  E  -  -  -  E',
    'nif'                  => 'V  -  V  V  V  V  V  -  -  V',
    // GEO
    'geo-rotas'            => 'V  V  V  Vr Vr -  -  V  G  V',
    'geo-rotas-importar'   => 'G  -  -  -  -  -  G  -  G  -',
    'geo-rotas-descarregar' => 'V  -  E  Er Er -  -  E  E  -',
    'geo-zonas'            => 'V  V  V  Vr -  -  V  V  G  V',
    'geo-zonas-gerir'      => 'G  -  -  -  -  -  -  -  G  -',
    'geo-distribuidores'   => 'V  V  V  Vr -  -  V  V  G  V',
    // Administração
    'acessos'              => 'V  -  -  -  -  -  -  -  -  V',
    'admin-utilizadores'   => '-  -  -  -  -  -  -  -  -  -',
    'admin-config'         => '-  -  -  -  -  -  -  -  -  -',
    'registos'             => 'V  -  -  -  -  -  -  -  -  V',
];
// Quem vê o NIF dos clientes: linha «nif» da matriz (editável no portal)
// Perfis em que o âmbito é sempre limitado às rotas atribuídas
const NAVAL_POR_ROTA = ['SUP', 'PRO'];
const NAVAL_ORDEM_NIVEIS = ['' => 0, 'Vr' => 1, 'V' => 2, 'Er' => 3, 'E' => 4, 'G' => 5, 'A' => 6];

/** Matriz original (a do código), sem as alterações feitas no portal. */
function naval_matriz_base(): array
{
    $m = [];
    foreach (NAVAL_MATRIZ_TXT as $rec => $txt) {
        $v = preg_split('/\s+/', trim($txt));
        foreach (NAVAL_COLUNAS as $i => $p) $m[$rec][$p] = ($v[$i] ?? '-') === '-' ? '' : $v[$i];
        $m[$rec]['SADM'] = 'A';
    }
    return $m;
}

function naval_ficheiro_matriz(): string
{
    return naval_pasta_privada() . '/matriz.json';
}

/** Alterações à matriz feitas pelo super administrador no portal: {recurso: {perfil: nível}}. */
function naval_matriz_alteracoes(): array
{
    return naval_json_ler(naval_ficheiro_matriz());
}

/** Matriz em vigor: a original com as alterações gravadas (só valores permitidos). */
function naval_matriz(bool $recarregar = false): array
{
    static $m = null;
    if ($m !== null && !$recarregar) return $m;
    $m = naval_matriz_base();
    foreach (naval_matriz_alteracoes() as $rec => $col) {
        if (!isset($m[$rec]) || !is_array($col)) continue;
        foreach ($col as $p => $nivel) {
            if (in_array($p, NAVAL_COLUNAS, true) && in_array($nivel, naval_niveis_recurso($rec), true)) $m[$rec][$p] = $nivel;
        }
    }
    return $m;
}

/** Níveis que cada página ou função admite na matriz. As funções de administração não são editáveis. */
function naval_niveis_recurso(string $rec): array
{
    switch ($rec) {
        case 'admin-utilizadores':
        case 'admin-config':
            return [];
        case 'pagina-principal':
        case 'acessos':
        case 'registos':
        case 'nif':
            return ['', 'V'];
        case 'excel-completo':
            return ['', 'E'];
        case 'geo-rotas-importar':
        case 'geo-zonas-gerir':
            return ['', 'G'];
        case 'geo-rotas-descarregar':
            return ['', 'V', 'Er', 'E'];
        case 'sap-exportar':
            return ['', 'V', 'E'];
        case 'plano-cargas':
            return ['', 'Vr', 'V', 'E', 'G'];
        case 'geo-rotas':
        case 'geo-zonas':
        case 'geo-distribuidores':
            return ['', 'Vr', 'V', 'G'];
        default:
            return ['', 'Vr', 'V', 'Er', 'E'];
    }
}

/** Grava as alterações à matriz (só as diferenças em relação à original). */
function naval_gravar_matriz(array $nova): void
{
    $base = naval_matriz_base();
    $dif = [];
    foreach ($nova as $rec => $col) {
        if (!isset($base[$rec]) || !is_array($col)) continue;
        foreach ($col as $p => $nivel) {
            if (!in_array($p, NAVAL_COLUNAS, true) || !in_array($nivel, naval_niveis_recurso($rec), true)) continue;
            if ($base[$rec][$p] !== $nivel) $dif[$rec][$p] = $nivel;
        }
    }
    naval_json_gravar(naval_ficheiro_matriz(), $dif);
    naval_matriz(true);
}

/** Nível do utilizador actual num recurso ('' = sem acesso). */
function naval_nivel(string $recurso): string
{
    $p = naval_perfil();
    if ($p === '') return '';
    return naval_matriz()[$recurso][$p] ?? '';
}

function naval_pode(string $recurso, string $minimo = 'Vr'): bool
{
    return NAVAL_ORDEM_NIVEIS[naval_nivel($recurso)] >= NAVAL_ORDEM_NIVEIS[$minimo];
}

/** Pode exportar este recurso (E, Er, G ou A). */
function naval_pode_exportar(string $recurso): bool
{
    return in_array(naval_nivel($recurso), ['E', 'Er', 'G', 'A'], true);
}

/** Rotas a que o utilizador tem acesso: null = todas. */
function naval_rotas(): ?array
{
    $u = naval_utilizador();
    if (!$u) return [];
    if (in_array($u['perfil'], NAVAL_POR_ROTA, true)) return array_values(array_map('strval', $u['rotas'] ?? []));
    return null;
}

function naval_rota_permitida($rota): bool
{
    $r = naval_rotas();
    return $r === null || in_array((string) $rota, $r, true);
}

function naval_ve_nif(): bool
{
    return naval_nivel('nif') !== '';
}

/** Pelo menos um dos recursos com acesso; sem isso responde 403 em JSON. */
function naval_exigir(array $recursos): void
{
    foreach ($recursos as $r) if (naval_pode($r)) return;
    naval_registar('acesso_negado', ['recursos' => $recursos]);
    naval_json(['ok' => false, 'erro' => 'Sem permissão para estes dados.', 'detalhe' => 'Perfil ' . naval_perfil()], 403);
}
