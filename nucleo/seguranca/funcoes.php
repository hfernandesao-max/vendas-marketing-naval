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

/**
 * Separação de funções imposta pelo sistema (ISO/IEC 27001 A.5.3): estes pares juntam-se sempre aos conflitos do
 * catálogo, mesmo gravado, e não se retiram no portal. Quem conta a carrinha (Logística) não aprova nem fecha a
 * conciliação; quem lança a caixa (Finanças) não a aprova.
 */
const NAVAL_FUNCOES_CONFLITOS_SISTEMA = [
    ['P-LOG', 'C-CONC-COORD'], ['P-LOG', 'C-CONC-FECHO'],
    ['P-FIN', 'C-CAIXA-APROVAR-1'], ['P-FIN', 'C-CAIXA-APROVAR'],
];

/** Ajustes ao catálogo gravado, aplicados uma só vez cada (id => [função, página, nível mínimo]). */
const NAVAL_FUNCOES_AJUSTES = [
    '2026-11-bo-conciliacao' => ['P-BO', 'conciliacao-carrinha', 'V'],   // o apoio administrativo vê a conciliação e imprime a folha
];

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
    // Tesouraria (operação de caixa): menor privilégio (ISO/IEC 27001:2022 A.5.15, A.5.18, A.8.2, A.8.3) — recebimentos,
    // cobranças e contas a receber, com o fecho de caixa pela função «Lançar e validar o fecho de caixa». Sem logística,
    // equipamentos, integrações, inteligência de negócio nem exportações completas. Separação de funções (A.5.3): quem
    // recebe o dinheiro não aprova a caixa nem confere o Multicaixa com o extracto (só consulta).
    $f['P-TES'] = ['nome' => 'Tesouraria', 'descricao' => 'Recebimentos, cobranças e contas a receber. Juntar «Lançar e validar o fecho de caixa».',
        'tipo' => 'base', 'permissoes' => ['pagina-principal' => 'V', 'inicio' => 'V', 'alertas' => 'V', 'suporte' => 'V', 'documentos' => 'V',
            'relatorio-vendas' => 'V', 'clientes' => 'V', 'pagamentos' => 'E', 'pendentes' => 'E', 'multicaixa-tpa' => 'V'],
        'por_rota' => false, 'dois_passos' => true, 'conflitos' => ['C-CAIXA-APROVAR-1', 'C-CAIXA-APROVAR'], 'activo' => true];
    $comp = function (string $nome, string $desc, array $perm, array $conflitos = [], bool $mfa = false) {
        return ['nome' => $nome, 'descricao' => $desc, 'tipo' => 'complementar', 'permissoes' => $perm, 'por_rota' => false,
            'dois_passos' => $mfa, 'conflitos' => $conflitos, 'activo' => true];
    };
    $f['C-CAIXA-LANCAR'] = $comp('Lançar e validar o fecho de caixa', 'Quem recebe o dinheiro: lança entregas, talões e depósitos e valida com a contagem.',
        ['caixa-marketing' => 'G', 'relatorio-vendas' => 'V'], ['C-CAIXA-APROVAR-1', 'C-CAIXA-APROVAR']);
    $f['C-CAIXA-APROVAR-1'] = $comp('Aprovar o fecho de caixa (1.º nível)', 'Superior de quem lança: aprova os depósitos e a folha validada.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao' => 'G'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR', 'P-TES'], true);
    $f['C-CAIXA-APROVAR'] = $comp('Aprovar o fecho de caixa (final)', 'Responsável máximo: aprovação final da folha e dos depósitos.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao-final' => 'G', 'relatorio-vendas' => 'V'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR-1', 'P-TES'], true);
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
    // as funções gravadas mantêm-se como estão; as do sistema acrescentadas depois (ex.: P-TES) juntam-se ao catálogo
    $f = naval_funcoes_regras($g ? (array) $g + naval_funcoes_iniciais() : naval_funcoes_iniciais());
    if ($g && naval_funcoes_ajustes_pendentes()) $f = naval_funcoes_regras(naval_funcoes_ajustar((array) $g) + naval_funcoes_iniciais());
    return $f;
}

/** Junta ao catálogo os conflitos impostos pelo sistema (simétricos), marcados em «conflitos_sistema». */
function naval_funcoes_regras(array $f): array
{
    foreach (NAVAL_FUNCOES_CONFLITOS_SISTEMA as $par) {
        if (!isset($f[$par[0]], $f[$par[1]])) continue;
        foreach ([[$par[0], $par[1]], [$par[1], $par[0]]] as $xy) {
            foreach (['conflitos', 'conflitos_sistema'] as $k) {
                $l = array_map('strval', (array) ($f[$xy[0]][$k] ?? []));
                if (!in_array($xy[1], $l, true)) $l[] = $xy[1];
                $f[$xy[0]][$k] = $l;
            }
        }
    }
    return $f;
}

function naval_funcoes_ficheiro_ajustes(): string
{
    return naval_pasta_privada() . '/funcoes-ajustes.json';
}

/** Ajustes do sistema ainda por aplicar ao catálogo gravado. */
function naval_funcoes_ajustes_pendentes(): array
{
    $feitos = naval_json_ler(naval_funcoes_ficheiro_ajustes());
    return array_diff_key(NAVAL_FUNCOES_AJUSTES, $feitos);
}

/**
 * Aplica os ajustes pendentes ao catálogo gravado (sobe o nível da página quando está abaixo do mínimo), grava, e
 * recalcula os perfis gerados que usam as funções ajustadas. Fica registado. Devolve o catálogo gravado ajustado.
 */
function naval_funcoes_ajustar(array $g): array
{
    $pend = naval_funcoes_ajustes_pendentes();
    $feitos = naval_json_ler(naval_funcoes_ficheiro_ajustes());
    $mudadas = [];
    foreach ($pend as $id => $a) {
        [$cod, $rec, $nivel] = $a;
        if (isset($g[$cod]) && (NAVAL_ORDEM_NIVEIS[(string) ($g[$cod]['permissoes'][$rec] ?? '')] ?? 0) < NAVAL_ORDEM_NIVEIS[$nivel]) {
            $g[$cod]['permissoes'][$rec] = $nivel;
            $g[$cod]['permissoes'] = naval_funcoes_limpar((array) $g[$cod]['permissoes']);
            $mudadas[$cod] = true;
        }
        $feitos[$id] = date('Y-m-d H:i');
    }
    if ($mudadas) naval_json_gravar(naval_ficheiro_funcoes(), $g);
    naval_json_gravar(naval_funcoes_ficheiro_ajustes(), $feitos);
    if ($mudadas) {
        $f = naval_funcoes_regras($g + naval_funcoes_iniciais());
        foreach (naval_perfis_criados(true) as $pc => $x) {
            if (empty($x['gerado']) || !array_intersect(array_keys($mudadas), (array) ($x['funcoes'] ?? []))) continue;
            $x['matriz'] = naval_funcoes_permissoes_de($f, (array) $x['funcoes']);
            $x['alterado'] = date('Y-m-d H:i');
            $x['alterado_por'] = 'sistema';
            $todos = naval_perfis_criados(true);
            $todos[$pc] = $x;
            naval_gravar_perfis($todos);
        }
        naval_registar('funcoes_ajustadas', ['ajustes' => array_keys($pend), 'funcoes' => array_keys($mudadas)], 'sistema');
    }
    return $g;
}

/** Permissões efectivas de um conjunto de funções: o maior nível de cada página. */
function naval_funcoes_permissoes(array $codigos): array
{
    return naval_funcoes_permissoes_de(naval_funcoes(), $codigos);
}

/** Permissões efectivas de um conjunto de funções num catálogo dado. */
function naval_funcoes_permissoes_de(array $f, array $codigos): array
{
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
    // dono da função (ISO/IEC 27001 A.5.9 e A.5.18): aprova os pedidos de acesso e revê, por trimestre, quem a tem
    $dono = array_key_exists('dono', $p) ? trim((string) $p['dono']) : (string) ($f[$codigo]['dono'] ?? '');
    if ($dono !== '') {
        $u = naval_utilizadores()[$dono] ?? null;
        if (!$u || !naval_conta_valida($u)) throw new NavalErro('O dono da função tem de ser uma conta activa.', 400);
    }
    $activo = !array_key_exists('activo', $p) || !empty($p['activo']);
    $contas = naval_funcoes_contas($codigo);
    if (!$activo && $contas) throw new NavalErro('Há contas com esta função: retire-a das contas antes de a desactivar.', 409);
    $f[$codigo] = ['nome' => $nome, 'descricao' => naval_texto($p['descricao'] ?? '', 200), 'tipo' => ($f[$codigo]['tipo'] ?? 'complementar'),
        'permissoes' => naval_funcoes_limpar((array) ($p['permissoes'] ?? [])), 'por_rota' => !empty($p['por_rota']), 'dois_passos' => !empty($p['dois_passos']),
        'conflitos' => $conflitos, 'dono' => $dono, 'activo' => $activo] + ($novo ? ['criada' => date('Y-m-d H:i'), 'criada_por' => $por] : array_intersect_key($f[$codigo], ['criada' => 1, 'criada_por' => 1]));
    // os conflitos são simétricos
    foreach ($f as $c => $x) {
        if ($c === $codigo) continue;
        $l = array_values(array_diff((array) ($x['conflitos'] ?? []), [$codigo]));
        if (in_array($c, $conflitos, true)) $l[] = $codigo;
        $f[$c]['conflitos'] = $l;
    }
    // os conflitos impostos pelo sistema não se gravam (juntam-se sempre ao ler)
    foreach ($f as $c => $x) unset($f[$c]['conflitos_sistema']);
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

/** Perfis do sistema que têm uma função-base equivalente (P-xx), para passar as contas antigas para funções. */
const NAVAL_FUNCOES_DE_PERFIL = ['TI', 'DIR', 'COM', 'SUP', 'PRO', 'FIN', 'BO', 'LOG', 'GEO', 'AUD'];

/** Contas cujas funções formam hoje uma combinação proibida (ex.: depois de uma nova regra do sistema). */
function naval_funcoes_contas_conflito(): array
{
    $f = naval_funcoes();
    $out = [];
    foreach (naval_utilizadores() as $l => $u) {
        if (empty($u['funcoes']) || !naval_conta_valida($u)) continue;
        foreach (naval_funcoes_conflitos((array) $u['funcoes']) as $par) {
            $out[] = ['login' => (string) $l, 'nome' => (string) ($u['nome'] ?? $l), 'funcoes' => $par,
                'nomes' => [(string) ($f[$par[0]]['nome'] ?? $par[0]), (string) ($f[$par[1]]['nome'] ?? $par[1])]];
        }
    }
    return $out;
}

/**
 * Contas activas ainda sem funções (perfil manual), com o que muda se passarem para a função-base equivalente:
 * as páginas em que o nível da função difere do perfil em vigor. Sem diferenças, a conversão não muda nenhum acesso.
 */
function naval_funcoes_sem_funcoes(): array
{
    $f = naval_funcoes();
    $m = naval_matriz();
    $out = [];
    foreach (naval_utilizadores() as $l => $u) {
        $perfil = (string) ($u['perfil'] ?? '');
        if (!empty($u['funcoes']) || !naval_conta_valida($u) || $perfil === 'SADM' || !empty($u['compatibilidade'])) continue;
        $funcao = in_array($perfil, NAVAL_FUNCOES_DE_PERFIL, true) && isset($f['P-' . $perfil]) ? 'P-' . $perfil : null;
        $dif = [];
        if ($funcao) {
            $nova = naval_funcoes_permissoes([$funcao]);
            foreach ($m as $rec => $col) {
                $a = (string) ($col[$perfil] ?? '');
                $b = (string) ($nova[$rec] ?? '');
                if ($a === 'A') $a = '';
                if ($a !== $b) $dif[] = ['pagina' => (string) $rec, 'actual' => $a, 'funcao' => $b];
            }
        }
        $out[] = ['login' => (string) $l, 'nome' => (string) ($u['nome'] ?? $l), 'perfil' => $perfil, 'perfil_nome' => naval_perfil_nome($perfil),
            'funcao' => $funcao, 'diferencas' => $dif];
    }
    return $out;
}

/**
 * Passa para a função-base equivalente as contas indicadas que não mudam de acessos (sem diferenças). As outras
 * ficam como estão e voltam na resposta: primeiro ajusta-se a função ou o perfil, ou atribuem-se as funções à mão.
 */
function naval_funcoes_converter(array $logins, string $por): array
{
    $lista = [];
    foreach (naval_funcoes_sem_funcoes() as $x) $lista[$x['login']] = $x;
    $d = naval_utilizadores();
    $feitas = $ficam = [];
    foreach (array_values(array_unique(array_map('strval', $logins))) as $l) {
        $x = $lista[$l] ?? null;
        if (!$x || !$x['funcao'] || $x['diferencas']) {
            $ficam[] = $l;
            continue;
        }
        $d[$l]['perfil'] = naval_funcoes_perfil([$x['funcao']], $por);
        $d[$l]['funcoes'] = [$x['funcao']];
        $d[$l]['alterado'] = date('Y-m-d H:i');
        $d[$l]['alterado_por'] = $por;
        $feitas[] = $l;
    }
    if ($feitas) {
        naval_gravar_utilizadores($d);
        foreach ($feitas as $l) naval_registar('conta_alterada', ['conta' => $l, 'funcoes' => $lista[$l]['funcao'], 'perfil' => $d[$l]['perfil'], 'motivo' => 'passagem para funções sem mudança de acessos']);
    }
    return ['convertidas' => $feitas, 'ficam' => $ficam];
}

/**
 * Funções temporárias (substituições, férias, projectos): cada função pode ter um último dia («funcoes_fim»). No dia
 * seguinte sai da conta sozinha; o perfil é recalculado e, sem nenhuma função, a conta fica desligada. Tudo registado.
 */
function naval_funcoes_expirar(int $agora): array
{
    $hoje = date('Y-m-d', $agora);
    $d = naval_utilizadores();
    $mud = [];
    foreach ($d as $l => $u) {
        $fim = (array) ($u['funcoes_fim'] ?? []);
        $sai = array_keys(array_filter($fim, function ($dia) use ($hoje) { return (string) $dia !== '' && (string) $dia < $hoje; }));
        if (!$sai) continue;
        $resto = array_values(array_diff((array) ($u['funcoes'] ?? []), $sai));
        foreach ($sai as $c) unset($fim[$c]);
        if ($fim) $d[$l]['funcoes_fim'] = $fim; else unset($d[$l]['funcoes_fim']);
        if ($resto) {
            try {
                $d[$l]['perfil'] = naval_funcoes_perfil($resto, 'sistema');
            } catch (NavalErro $e) {
                // o que fica é uma combinação que deixou de ser permitida: desliga-se e o super administrador decide
                $d[$l]['activo'] = false;
            }
            $d[$l]['funcoes'] = $resto;
        } else {
            unset($d[$l]['funcoes']);
            $d[$l]['activo'] = false;
        }
        $d[$l]['alterado'] = date('Y-m-d H:i', $agora);
        $d[$l]['alterado_por'] = 'sistema';
        $mud[(string) $l] = ['saiu' => $sai, 'desligada' => empty($d[$l]['activo'])];
    }
    if ($mud) {
        naval_gravar_utilizadores($d);
        foreach ($mud as $l => $x) naval_registar('funcao_expirada', ['conta' => $l, 'funcoes' => $x['saiu'], 'desligada' => $x['desligada']], 'sistema');
    }
    return $mud;
}
