<?php
/**
 * Matriz de acessos («Matriz de acessos · Plataforma RTM e GeoMobilidade Naval», 26-10-2026):
 *   - 12 perfis (SADM de emergência + 10 perfis de trabalho + EXT, técnicos externos só a ver);
 *   - nível por recurso: '' sem acesso, V ver, Vr ver só as suas rotas, E exportar,
 *     Er exportar só as suas rotas, G gerir, A administrar;
 *   - âmbito por rota (SUP e PRO) e dados pessoais (NIF) só para quem precisa.
 * A matriz do código pode ser alterada pelo super administrador no portal (dados-privados/matriz.json),
 * que também pode criar perfis novos (ver perfis.php): cada um passa a ser uma coluna da matriz.
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
    'BO'   => 'Apoio administrativo RTM',
    'LOG'  => 'Logística e Armazém',
    'GEO'  => 'Planeamento de rotas',
    'AUD'  => 'Auditoria e Controlo',
    'EXT'  => 'Técnico externo (validação, só leitura)',
];

// Ordem das colunas da matriz: TI DIR COM SUP PRO FIN BO LOG GEO AUD EXT ('-' = sem acesso).
// O SADM tem 'A' em tudo.
// EXT — técnicos externos que só validam o portal (menor privilégio: ISO 27001 A.5.15/A.8.2, NIST SP 800-53 AC-6):
// só ver (V), todas as rotas, sem gerir nem administrar, só exporta os documentos e a AGT para Excel, sem NIF nem dados pessoais, sem registos nem
// matriz de acessos, sem o assistente de IA; dois passos obrigatórios. A conta deve ter prazo («--ate»).
const NAVAL_COLUNAS = ['TI', 'DIR', 'COM', 'SUP', 'PRO', 'FIN', 'BO', 'LOG', 'GEO', 'AUD', 'EXT'];
const NAVAL_MATRIZ_TXT = [
    // REPORTS
    'pagina-principal'     => 'V  V  V  V  V  V  V  V  V  V  V',
    'inicio'               => 'V  E  E  Vr Vr E  V  V  -  V  V',
    'vendas-promotor'      => 'V  E  E  Vr Vr E  -  -  -  E  V',
    'vendas-dia'           => 'V  E  E  Vr Vr E  -  -  -  E  V',
    'vendas-cliente'       => 'V  E  E  Er Vr E  -  -  -  E  V',
    'ranking'              => 'V  E  E  Vr Vr V  -  -  -  V  V',
    // documentos e AGT: todos os perfis que os vêem exportam para Excel (âmbito por rota nos SUP e PRO)
    'documentos'           => 'E  E  E  Er Er E  E  -  -  E  E',
    'pagamentos'           => 'V  E  V  Vr Vr E  -  -  -  E  V',
    'pendentes'            => 'V  E  V  Vr Vr E  -  -  -  E  V',
    'auditoria'            => 'V  V  E  Vr -  E  V  -  -  E  V',
    'registo-cargas'       => 'V  V  E  Vr Vr V  -  E  -  V  V',
    'stock-carrinha'       => 'V  V  E  Vr Vr V  -  E  -  V  V',
    'stock-armazem'        => 'V  V  E  -  -  V  -  E  -  V  V',
    'vendas-artigo'        => 'V  E  E  Vr Vr E  -  E  -  E  V',
    'vendas-marca'         => 'V  E  E  Vr Vr E  -  -  -  E  V',
    'plano-cargas'         => 'V  E  E  Vr -  -  V  G  V  E  V',
    'conciliacao-carrinha' => 'V  V  V  Vr Vr V  -  G  -  V  V',
    'sap-exportar'         => 'V  -  -  -  -  E  E  -  -  V  -',
    'agt'                  => 'E  E  -  -  -  E  E  -  -  E  E',
    'cadastros'            => 'V  V  E  Vr Vr -  E  -  V  V  V',
    'cadastros-analise'    => 'V  V  E  Vr Vr -  E  -  -  V  V',
    'cadastros-clientes'   => 'V  -  E  Er Vr -  E  -  V  V  V',
    'pedidos-clientes'     => 'V  V  V  Vr -  -  G  -  -  V  V',
    'clientes-qr'          => 'G  -  G  -  -  -  G  -  -  -  -',
    'clientes'             => 'V  -  E  Er Vr V  E  -  V  V  V',
    // carteira: visitas do dia com a ficha de preparação; anomalias de vendas e qualidade dos dados
    'visitas-dia'          => 'V  V  V  Vr Vr -  V  -  V  V  V',
    'qualidade-dados'      => 'V  V  V  Vr -  V  V  V  V  V  V',
    'plano-visitas'        => 'V  V  E  Vr Vr -  V  -  V  V  V',
    'sincronizacao'        => 'V  E  E  Er -  -  V  E  -  E  V',
    'excel-completo'       => '-  E  E  -  -  E  -  -  -  E  -',
    'exportar-dados'       => '-  E  -  -  -  E  -  -  -  E  -',
    'estrutura-dados'      => 'E  -  -  -  -  -  -  -  -  E  -',
    'nif'                  => 'V  -  V  V  V  V  V  -  -  V  -',
    // Equipamentos (smartphones e impressoras do terreno)
    'equipamentos'         => 'V  V  -  Vr -  V  V  E  -  E  V',
    'equipamentos-movimentar' => '-  -  -  -  -  -  G  G  -  -  -',
    'equipamentos-administrar' => 'G  -  -  -  -  -  -  G  -  -  -',
    // Gestão (análise e IA): alertas, previsão, assistente, resumo diário, app de gestão e painel da sala comercial
    'painel-gestao'        => 'V  V  V  Vr -  V  -  -  -  V  V',
    'alertas'              => 'V  V  V  Vr -  V  -  -  -  V  V',
    'previsao'             => 'V  V  V  Vr -  -  -  -  -  V  V',
    'assistente'           => '-  V  -  -  -  -  -  -  -  -  -',
    'resumo-diario'        => '-  V  -  -  -  -  -  -  -  -  -',
    'fecho-dia'            => '-  V  V  V  -  V  -  -  -  V  -',
    'app-gestao'           => '-  V  V  Vr -  V  -  -  -  -  -',
    'painel-sala'          => 'G  -  G  -  -  -  -  -  -  -  -',
    // GEO
    'geo-rotas'            => 'V  V  V  Vr Vr -  -  V  G  V  V',
    'geo-rotas-importar'   => 'G  -  -  -  -  -  G  -  G  -  -',
    'geo-rotas-descarregar' => 'V  -  E  Er Er -  -  E  E  -  -',
    'geo-zonas'            => 'V  V  V  Vr -  -  V  V  G  V  V',
    'geo-zonas-gerir'      => 'G  -  -  -  -  -  -  -  G  -  -',
    'geo-distribuidores'   => 'V  V  V  Vr -  -  V  V  G  V  V',
    'geo-prospeccao'       => 'V  V  V  Vr Vr -  -  -  G  V  V',
    // terreno do dia (localização do OLAP): dado pessoal dos vendedores, só a quem acompanha as rotas (Lei 22/11)
    'geo-terreno'          => 'V  V  V  Vr Vr -  -  -  G  V  -',
    // Administração
    'acessos'              => 'E  -  -  -  -  -  -  -  -  E  -',
    'admin-utilizadores'   => '-  -  -  -  -  -  -  -  -  -  -',
    'admin-config'         => '-  -  -  -  -  -  -  -  -  -  -',
    'registos'             => 'V  -  -  -  -  -  -  -  -  V  -',
    // aprovação das versões do portal: só o super administrador (não editável na matriz)
    'actualizacoes'        => '-  -  -  -  -  -  -  -  -  -  -',
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
    // Perfis criados no portal: a «matriz original» é a cópia das permissões do perfil de origem
    foreach (naval_perfis_criados() as $c => $x) {
        if (empty($x['activo'])) continue;
        foreach ($m as $rec => $col) {
            $nivel = (string) ($x['matriz'][$rec] ?? '');
            $m[$rec][$c] = in_array($nivel, naval_niveis_recurso($rec), true) ? $nivel : '';
        }
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
            if (in_array($p, naval_colunas(), true) && in_array($nivel, naval_niveis_recurso($rec), true)) $m[$rec][$p] = $nivel;
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
        case 'actualizacoes':
            return [];
        case 'acessos':
            return ['', 'V', 'E'];   // exportar contas, perfis, matriz e registos (revisão de acessos / auditoria)
        case 'pagina-principal':
        case 'fecho-dia':
        case 'registos':
        case 'nif':
        case 'assistente':
        case 'resumo-diario':
            return ['', 'V'];
        case 'app-gestao':
            return ['', 'Vr', 'V'];
        case 'painel-sala':
        case 'clientes-qr':
            return ['', 'G'];
        case 'pedidos-clientes':
        case 'conciliacao-carrinha':
            return ['', 'Vr', 'V', 'G'];
        case 'excel-completo':
        case 'estrutura-dados':
        case 'exportar-dados':
            return ['', 'E'];
        case 'geo-rotas-importar':
        case 'geo-zonas-gerir':
        case 'equipamentos-movimentar':
        case 'equipamentos-administrar':
            return ['', 'G'];
        case 'geo-rotas-descarregar':
            return ['', 'V', 'Er', 'E'];
        case 'sap-exportar':
            return ['', 'V', 'E'];
        case 'plano-cargas':
            return ['', 'Vr', 'V', 'E', 'G'];
        case 'geo-rotas':
        case 'geo-prospeccao':
        case 'geo-terreno':
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
            if (!in_array($p, naval_colunas(), true) || !in_array($nivel, naval_niveis_recurso($rec), true)) continue;
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
    if (in_array($u['perfil'], naval_perfis_por_rota(), true)) return array_values(array_map('strval', $u['rotas'] ?? []));
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
