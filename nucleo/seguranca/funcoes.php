<?php
/**
 * Atribuição automática de acessos por funções (controlo de acessos baseado em funções — RBAC, NIST/INCITS 359,
 * com restrições de separação de funções; ISO/IEC 27001:2022 A.5.15 controlo de acessos, A.5.3 segregação de funções,
 * A.5.18 direitos de acesso, A.8.2 acessos privilegiados).
 *
 *   - Uma FUNÇÃO é o que a pessoa faz no trabalho (ex.: «Tesouraria — lançar a folha de caixa», «Aprovar a folha de
 *     caixa»), com o conjunto mínimo de permissões que precisa (menor privilégio), o âmbito (só as suas rotas ou todas)
 *     e se exige verificação em dois passos.
 *   - O super administrador diz só as FUNÇÕES de cada pessoa; o portal calcula as permissões (a maior de cada página
 *     entre as funções), cria ou reutiliza um perfil gerado para esse conjunto de funções e atribui-o à conta.
 *   - Combinações proibidas (separação de funções) são recusadas: ex.: lançar e aprovar a mesma caixa.
 *   - Quando uma função muda, todas as contas com essa função ficam actualizadas sozinhas.
 *   - O nível A (administrar) nunca vem de funções: é só do SADM.
 *
 * Catálogo em dados-privados/funcoes.json; sem ficheiro, o catálogo inicial abaixo (um por perfil do sistema, mais as
 * funções de aprovação e de controlo, que se juntam às outras).
 */
defined('NAVAL_NUCLEO') || exit;

const NAVAL_FUNCAO_GERADO = 'F';   // prefixo dos perfis gerados (F + 7 caracteres do resumo do conjunto de funções)

/** Catálogo inicial: as funções-base copiam os perfis do sistema; as complementares juntam-se a elas. */
function naval_funcoes_iniciais(): array
{
    $m = naval_matriz_base();
    $dePerfil = function (string $p) use ($m) {
        $out = [];
        foreach ($m as $rec => $col) if (($col[$p] ?? '') !== '' && $col[$p] !== 'A') $out[$rec] = $col[$p];
        return $out;
    };
    $f = [];
    $base = [
        'TI' => ['Tecnologias de informação', 'Suporte técnico, equipamentos e integrações.'],
        'DIR' => ['Direcção', 'Consulta de toda a informação de gestão.'],
        'COM' => ['Direcção comercial e marketing', 'Gestão das vendas, clientes, terreno e objectivos.'],
        'SUP' => ['Supervisor de vendas', 'Acompanhamento das suas rotas e promotores.'],
        'PRO' => ['Promotor de vendas', 'As suas vendas, clientes e carrinha.'],
        'FIN' => ['Finanças e tesouraria', 'Cobranças, contas a receber e fecho de caixa.'],
        'BO' => ['Apoio administrativo RTM', 'Documentos, sincronização e apoio às rotas.'],
        'LOG' => ['Logística', 'Cargas, stock, requisições e contagem das carrinhas.'],
        'GEO' => ['Geomobilidade', 'Mapas, rotas e prospecção.'],
        'AUD' => ['Auditoria', 'Consulta e exportação para auditoria; sem alterar.'],
    ];
    foreach ($base as $p => [$nome, $desc]) {
        $f['P-' . $p] = ['nome' => $nome, 'descricao' => $desc, 'tipo' => 'base', 'permissoes' => $dePerfil($p),
            'por_rota' => in_array($p, NAVAL_POR_ROTA, true), 'dois_passos' => in_array($p, NAVAL_PERFIS_SISTEMA_MFA, true), 'conflitos' => [], 'activo' => true];
    }
    $comp = function (string $nome, string $desc, array $perm, array $conflitos = [], bool $mfa = false) {
        return ['nome' => $nome, 'descricao' => $desc, 'tipo' => 'complementar', 'permissoes' => $perm, 'por_rota' => false,
            'dois_passos' => $mfa, 'conflitos' => $conflitos, 'activo' => true];
    };
    $f['C-CAIXA-LANCAR'] = $comp('Lançar e validar o fecho de caixa', 'Quem recebe o dinheiro: lança entregas, talões e depósitos e valida com a contagem.',
        ['caixa-marketing' => 'G', 'relatorio-vendas' => 'V'], ['C-CAIXA-APROVAR-1', 'C-CAIXA-APROVAR']);
    $f['C-CAIXA-APROVAR-1'] = $comp('Aprovar o fecho de caixa (1.º nível)', 'Superior de quem lança: aprova os depósitos e a folha validada.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao' => 'G'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR'], true);
    $f['C-CAIXA-APROVAR'] = $comp('Aprovar o fecho de caixa (final)', 'Responsável máximo: aprovação final da folha e dos depósitos.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao-final' => 'G', 'relatorio-vendas' => 'V'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR-1'], true);
    $f['C-CONC-CONTAR'] = $comp('Contar as carrinhas', 'Técnico do armazém: contagem às cegas e validação da conciliação.',
        ['conciliacao-carrinha' => 'G', 'stock-carrinha' => 'V'], ['C-CONC-COORD', 'C-CONC-FECHO']);
    $f['C-CONC-COORD'] = $comp('Aprovar conciliações (coordenador)', '1.º nível de aprovação das diferenças da carrinha.',
        ['conciliacao-carrinha' => 'V', 'conciliacao-coordenacao' => 'G'], ['C-CONC-CONTAR', 'C-CONC-FECHO'], true);
    $f['C-CONC-FECHO'] = $comp('Fechar conciliações (responsável)', '2.º nível: aprovação final e fecho da conciliação.',
        ['conciliacao-carrinha' => 'V', 'conciliacao-fecho' => 'G'], ['C-CONC-CONTAR', 'C-CONC-COORD'], true);
    // só permissões que existem na matriz e níveis que cada página admite
    foreach ($f as $c => $x) $f[$c]['permissoes'] = naval_funcoes_limpar((array) $x['permissoes']);
    return $f;
}

/** Só páginas da matriz e níveis que cada uma admite; nunca o A (administrar). */
function naval_funcoes_limpar(array $perm): array
{
    $m = naval_matriz_base();
    $out = [];
    foreach ($perm as $rec => $nivel) {
        $nivel = (string) $nivel;
        if (!isset($m[$rec]) || $nivel === '' || $nivel === 'A' || !in_array($nivel, naval_niveis_recurso((string) $rec), true)) continue;
        $out[(string) $rec] = $nivel;
    }
    ksort($out);
    return $out;
}

function naval_ficheiro_funcoes(): string
{
    return naval_pasta_privada() . '/funcoes.json';
}

/** Catálogo em vigor {código: função}. */
function naval_funcoes(bool $recarregar = false): array
{
    static $f = null;
    if ($f !== null && !$recarregar) return $f;
    $g = naval_json_ler(naval_ficheiro_funcoes());
    $f = $g ? (array) $g : naval_funcoes_iniciais();
    return $f;
}

/** Permissões efectivas de um conjunto de funções: o maior nível de cada página. */
function naval_funcoes_permissoes(array $codigos): array
{
    $f = naval_funcoes();
    $out = [];
    foreach ($codigos as $c) {
        foreach ((array) ($f[$c]['permissoes'] ?? []) as $rec => $nivel) {
            if (!isset($out[$rec]) || (NAVAL_ORDEM_NIVEIS[$nivel] ?? 0) > (NAVAL_ORDEM_NIVEIS[$out[$rec]] ?? 0)) $out[$rec] = $nivel;
        }
    }
    ksort($out);
    return $out;
}

/** Conflitos de separação de funções num conjunto: [[a, b], …] (cada par uma vez). */
function naval_funcoes_conflitos(array $codigos): array
{
    $f = naval_funcoes();
    $out = [];
    foreach ($codigos as $a) {
        foreach ((array) ($f[$a]['conflitos'] ?? []) as $b) {
            if (in_array($b, $codigos, true)) {
                $par = [$a, $b];
                sort($par);
                $out[implode('|', $par)] = $par;
            }
        }
    }
    return array_values($out);
}

/** Código do perfil gerado para um conjunto de funções (estável: o mesmo conjunto dá sempre o mesmo código). */
function naval_funcoes_codigo(array $codigos): string
{
    sort($codigos);
    return NAVAL_FUNCAO_GERADO . strtoupper(substr(hash('sha256', implode('|', $codigos)), 0, 7));
}

/**
 * Valida o conjunto de funções e devolve o perfil gerado (criado ou actualizado em perfis.json) para atribuir à conta.
 * Recusa funções inexistentes, inactivas, conjuntos vazios e combinações proibidas.
 */
function naval_funcoes_perfil(array $codigos, string $por): string
{
    $f = naval_funcoes();
    $codigos = array_values(array_unique(array_map('strval', $codigos)));
    sort($codigos);
    if (!$codigos) throw new NavalErro('Escolha pelo menos uma função.', 400);
    foreach ($codigos as $c) {
        if (empty($f[$c]) || empty($f[$c]['activo'])) throw new NavalErro('Função inválida: ' . $c . '.', 400);
    }
    $conf = naval_funcoes_conflitos($codigos);
    if ($conf) {
        $nomes = array_map(function ($p) use ($f) { return '«' . $f[$p[0]]['nome'] . '» e «' . $f[$p[1]]['nome'] . '»'; }, $conf);
        throw new NavalErro('Separação de funções: a mesma pessoa não pode ter ' . implode('; ', $nomes) . '.', 400);
    }
    $codigo = naval_funcoes_codigo($codigos);
    naval_funcoes_gravar_perfil($codigo, $codigos, $por);
    return $codigo;
}

/** Cria ou actualiza o perfil gerado de um conjunto de funções. */
function naval_funcoes_gravar_perfil(string $codigo, array $codigos, string $por): void
{
    $f = naval_funcoes();
    $criados = naval_perfis_criados(true);
    $x = $criados[$codigo] ?? ['criado' => date('Y-m-d H:i'), 'criado_por' => $por];
    $x['nome'] = mb_substr(implode(' + ', array_map(function ($c) use ($f) { return (string) $f[$c]['nome']; }, $codigos)), 0, 60);
    $x['base'] = null;
    $x['gerado'] = true;
    $x['funcoes'] = $codigos;
    $x['matriz'] = naval_funcoes_permissoes($codigos);
    $x['por_rota'] = (bool) array_filter($codigos, function ($c) use ($f) { return !empty($f[$c]['por_rota']); });
    $x['dois_passos'] = (bool) array_filter($codigos, function ($c) use ($f) { return !empty($f[$c]['dois_passos']); });
    $x['activo'] = true;
    $x['alterado'] = date('Y-m-d H:i');
    $x['alterado_por'] = $por;
    $criados[$codigo] = $x;
    naval_gravar_perfis($criados);
}

/**
 * Grava uma função (nova ou alterada) e actualiza sozinhos todos os perfis gerados que a usam. Devolve o número de
 * contas afectadas. Uma função com contas não se desactiva.
 */
function naval_funcoes_gravar(array $p, string $por): int
{
    $f = naval_funcoes();
    $codigo = strtoupper(trim((string) ($p['codigo'] ?? '')));
    if (!preg_match('/^[A-Z][A-Z0-9-]{1,23}$/', $codigo)) throw new NavalErro('O código da função tem de ter 2 a 24 letras, números ou hífenes, a começar por uma letra.', 400);
    $nome = naval_texto($p['nome'] ?? '', 80);
    if ($nome === '') throw new NavalErro('Indique o nome da função.', 400);
    $novo = !isset($f[$codigo]);
    $conflitos = array_values(array_filter(array_map('strval', (array) ($p['conflitos'] ?? [])), function ($c) use ($f, $codigo) { return $c !== $codigo && isset($f[$c]); }));
    $activo = !array_key_exists('activo', $p) || !empty($p['activo']);
    $contas = naval_funcoes_contas($codigo);
    if (!$activo && $contas) throw new NavalErro('Há contas com esta função: retire-a das contas antes de a desactivar.', 409);
    $f[$codigo] = ['nome' => $nome, 'descricao' => naval_texto($p['descricao'] ?? '', 200), 'tipo' => ($f[$codigo]['tipo'] ?? 'complementar'),
        'permissoes' => naval_funcoes_limpar((array) ($p['permissoes'] ?? [])), 'por_rota' => !empty($p['por_rota']), 'dois_passos' => !empty($p['dois_passos']),
        'conflitos' => $conflitos, 'activo' => $activo] + ($novo ? ['criada' => date('Y-m-d H:i'), 'criada_por' => $por] : array_intersect_key($f[$codigo], ['criada' => 1, 'criada_por' => 1]));
    // os conflitos são simétricos
    foreach ($f as $c => $x) {
        if ($c === $codigo) continue;
        $l = array_values(array_diff((array) ($x['conflitos'] ?? []), [$codigo]));
        if (in_array($c, $conflitos, true)) $l[] = $codigo;
        $f[$c]['conflitos'] = $l;
    }
    naval_json_gravar(naval_ficheiro_funcoes(), $f);
    naval_funcoes(true);
    // perfis gerados que usam esta função: recalculam-se
    foreach (naval_perfis_criados(true) as $pc => $x) {
        if (!empty($x['gerado']) && in_array($codigo, (array) ($x['funcoes'] ?? []), true)) naval_funcoes_gravar_perfil($pc, (array) $x['funcoes'], $por);
    }
    naval_registar($novo ? 'funcao_criada' : 'funcao_alterada', ['funcao' => $codigo, 'contas' => count($contas)]);
    return count($contas);
}

/** Contas com uma função (pelo perfil gerado). */
function naval_funcoes_contas(string $codigo): array
{
    $gerados = [];
    foreach (naval_perfis_criados() as $pc => $x) if (!empty($x['gerado']) && in_array($codigo, (array) ($x['funcoes'] ?? []), true)) $gerados[$pc] = true;
    $out = [];
    foreach (naval_utilizadores() as $l => $u) if (isset($gerados[(string) ($u['perfil'] ?? '')])) $out[] = (string) $l;
    return $out;
}
