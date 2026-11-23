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
    // quem aprova a caixa vê o Multicaixa por TPA: o 1.º nível decide aí as correcções da forma de pagamento
    '2026-11-caixa-tpa-1' => ['C-CAIXA-APROVAR-1', 'multicaixa-tpa', 'V'],
    '2026-11-caixa-tpa-final' => ['C-CAIXA-APROVAR', 'multicaixa-tpa', 'V'],
];

/**
 * Reconfiguração de contas pedida pelo super administrador, aplicada uma só vez depois da actualização (id => conta
 * pelo nome, funções, funções de que fica dono e quem pediu). A conta tem de ser única e activa; as funções substituem
 * as que tinha (o perfil é recalculado) e tudo fica registado com o estado anterior. Se não se puder aplicar (conta
 * não encontrada, ambígua ou combinação proibida), não muda nada e fica registado para o super administrador decidir.
 */
const NAVAL_CONTAS_AJUSTES = [
    // responsável da área de negócio RTM: aprovação final da caixa e da conciliação, ficheiro SAP, mobilidade das rotas
    // e consolidado das vendas; dono das funções da operação que coordena
    '2026-11-michael-samson' => ['nome' => 'Michael Samson', 'funcoes' => ['P-COM', 'C-CAIXA-APROVAR', 'C-CONC-FECHO', 'C-SAP-EXPORTAR'],
        'dono_de' => ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR-1', 'C-CAIXA-APROVAR', 'C-CONC-CONTAR', 'C-CONC-COORD', 'C-CONC-FECHO', 'P-SUP', 'P-PRO', 'P-COORD'],
        'pedido' => 'HF, 18-11-2026'],
    // coordenador de todos os promotores: gestão operacional e 1.º nível de aprovação das diferenças de stock
    '2026-11-nathan-alemayehu' => ['nome' => 'Nathan Alemayehu', 'funcoes' => ['P-COORD', 'C-CONC-COORD'], 'dono_de' => [], 'pedido' => 'HF, 18-11-2026'],
    // o coordenador aprova as PO para a carga no cais
    '2026-11-nathan-po' => ['nome' => 'Nathan Alemayehu', 'funcoes' => ['P-COORD', 'C-CONC-COORD', 'C-PO-APROVAR'], 'dono_de' => [], 'pedido' => 'HF, 20-11-2026'],
    // controlo de stock (todos os armazéns e carrinhas por artigo, vendas por artigo) e contagem das carrinhas
    // responsável máximo da área de negócio RTM: todos os módulos de gestão, sem administração nem configurações
    '2026-11-michael-responsavel' => ['nome' => 'Michael Samson', 'funcoes' => ['P-RESP', 'C-CAIXA-APROVAR', 'C-CONC-FECHO', 'C-SAP-EXPORTAR'], 'dono_de' => [], 'pedido' => 'HF, 23-11-2026'],
    // responsável de todos os vendedores: coordena, aprova as contagens (1.º nível) e as PO e vê todo o stock; autoriza
    // os pedidos de acesso de vendedor, de contagem e de controlo de stock
    '2026-11-nathan-stock' => ['nome' => 'Nathan Alemayehu', 'funcoes' => ['P-COORD', 'C-CONC-COORD', 'C-PO-APROVAR', 'P-STOCK'], 'dono_de' => ['P-PRO', 'C-CONC-CONTAR', 'P-STOCK'], 'pedido' => 'HF, 23-11-2026'],
    // controlo de stock que valida as PO dos vendedores (ajusta as quantidades) antes da aprovação do Nathan
    '2026-11-teresa-validar' => ['nome' => 'Teresa Gomes', 'funcoes' => ['P-STOCK', 'C-PO-VALIDAR'], 'dono_de' => [], 'pedido' => 'HF, 23-11-2026'],
    '2026-11-manuel-queia' => ['nome' => 'Manuel Queia', 'funcoes' => ['P-STOCK', 'C-CONC-CONTAR'], 'dono_de' => [], 'pedido' => 'HF, 23-11-2026'],
];

/**
 * Contas novas pedidas pelo super administrador e criadas uma só vez, na instalação (pela tarefa periódica ou no
 * primeiro pedido): utilizador, nome, email e funções (o perfil gera-se das funções). A palavra-passe inicial é
 * aleatória e ninguém a conhece: o SADM entrega-a pela via segura habitual («Repor palavra-passe» em Acessos, que a
 * mostra só a ele, válida 24 horas; no primeiro acesso a pessoa muda-a e configura os dois passos). Se o utilizador ou
 * o email já existirem, não cria nada e fica registado.
 */
const NAVAL_CONTAS_NOVAS = [
    // controlo de stock: stock de todos os armazéns e carrinhas por artigo, vendas por artigo e por promotor
    '2026-11-teresa-gomes' => ['login' => 'teresa.gomes', 'nome' => 'Teresa Gomes', 'email' => 'teresa.gomes@foodtec.co.ao', 'funcoes' => ['C-PO-VALIDAR', 'P-STOCK'],
        'idioma' => 'pt', 'pedido' => 'HF, 23-11-2026'],
];

function naval_contas_novas_ficheiro(): string
{
    return naval_pasta_privada() . '/contas-novas.json';
}

/** Pendentes (CONTAS_NOVAS=0 no .env desliga: ambientes de teste e pré-produção). $lista: testes. */
function naval_contas_novas_pendentes(?array $lista = null): array
{
    if ($lista === null && naval_config('CONTAS_NOVAS', '1') === '0') return [];
    return array_diff_key($lista ?? NAVAL_CONTAS_NOVAS, naval_json_ler(naval_contas_novas_ficheiro()));
}

/** Cria as contas novas pendentes (uma vez cada). Devolve {id: {ok, login, motivo?}}. */
function naval_contas_novas_criar(?array $lista = null): array
{
    static $emCurso = false;
    if ($emCurso || !naval_contas_novas_pendentes($lista)) return [];
    $emCurso = true;
    try {
        return naval_exclusivo('utilizadores', function () use ($lista) {
            $res = [];
            foreach (naval_contas_novas_pendentes($lista) as $id => $c) {
                $res[$id] = naval_contas_nova_uma((array) $c);
                $feitos = naval_json_ler(naval_contas_novas_ficheiro());
                $feitos[$id] = ['em' => date('Y-m-d H:i')] + $res[$id];
                naval_json_gravar(naval_contas_novas_ficheiro(), $feitos);
            }
            return $res;
        });
    } finally {
        $emCurso = false;
    }
}

function naval_contas_nova_uma(array $c): array
{
    $login = strtolower(trim((string) ($c['login'] ?? '')));
    $email = trim((string) ($c['email'] ?? ''));
    $falha = function (string $motivo) use ($login) {
        naval_registar('conta_nova_nao_criada', ['conta' => $login, 'motivo' => $motivo], 'sistema');
        return ['ok' => false, 'login' => $login, 'motivo' => $motivo];
    };
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $login)) return $falha('utilizador inválido');
    if (!naval_email_valido($email)) return $falha('email inválido');
    $d = naval_utilizadores();
    if (isset($d[$login])) return $falha('o utilizador já existe');
    foreach ($d as $l => $x) if (strcasecmp(trim((string) ($x['email'] ?? '')), $email) === 0) return $falha('o email já está na conta ' . $l);
    $funcoes = array_values(array_unique(array_map('strval', (array) ($c['funcoes'] ?? []))));
    sort($funcoes);
    $f = naval_funcoes();
    foreach ($funcoes as $x) if (empty($f[$x]['activo'])) return $falha('a função ' . $x . ' não existe ou está desactivada');
    if (naval_funcoes_conflitos($funcoes)) return $falha('as funções formam uma combinação proibida');
    try {
        $perfil = naval_funcoes_perfil($funcoes, 'sistema');
    } catch (NavalErro $e) {
        return $falha($e->getMessage());
    }
    $u = ['nome' => (string) $c['nome'], 'email' => $email, 'perfil' => $perfil, 'funcoes' => $funcoes, 'rotas' => [], 'activo' => true,
        'idioma' => (string) ($c['idioma'] ?? 'pt'), 'alterado' => date('Y-m-d H:i'), 'alterado_por' => 'sistema'];
    // palavra-passe aleatória que ninguém conhece: a conta só se usa depois de o SADM repor a palavra-passe
    $u = naval_marcar_temporaria($u, naval_senha_temporaria() . bin2hex(random_bytes(8)));
    $d[$login] = $u;
    naval_gravar_utilizadores($d);
    naval_registar('conta_criada', ['conta' => $login, 'nome' => $u['nome'], 'email' => $email, 'perfil' => $perfil, 'funcoes' => implode(', ', $funcoes),
        'motivo' => 'conta pedida pelo super administrador (' . (string) ($c['pedido'] ?? '') . '); palavra-passe a entregar pelo SADM'], 'sistema');
    return ['ok' => true, 'login' => $login, 'perfil' => $perfil];
}

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
    // Coordenação comercial e operacional: o supervisor de vendas em todas as rotas (sem limite por rota), com o stock
    // do armazém para acompanhar as cargas e as requisições. Junta-se «Aprovar conciliações (coordenador)».
    $coord = [];
    foreach ($dePerfil('SUP') as $rec => $n) $coord[$rec] = ['Vr' => 'V', 'Er' => 'E'][$n] ?? $n;
    $f['P-COORD'] = ['nome' => 'Coordenação comercial e operacional', 'descricao' => 'Coordena todos os promotores e as rotas: vendas, clientes, cargas, stock, requisições e terreno.',
        'tipo' => 'base', 'permissoes' => $coord + ['stock-armazem' => 'V', 'plano-cargas' => 'V'], 'por_rota' => false, 'dois_passos' => true, 'conflitos' => [], 'activo' => true];
    // Controlo de stock (consulta): menor privilégio (ISO/IEC 27001:2022 A.5.15, A.5.18, A.8.2, A.8.3) — o stock de todos
    // os armazéns e de todas as carrinhas, por artigo, e as vendas por artigo e por promotor para o comparar com a saída; exporta para
    // Excel (cada exportação fica registada). Só consulta: sem preços de compra, clientes, caixa, cargas nem aprovações;
    // todas as rotas (sem limite por rota) e entrada em dois passos.
    $f['P-STOCK'] = ['nome' => 'Controlo de stock', 'descricao' => 'Consulta do stock de todos os armazéns e carrinhas por artigo e das vendas por artigo e por promotor.',
        'tipo' => 'base', 'permissoes' => ['pagina-principal' => 'V', 'stock-armazem' => 'E', 'stock-carrinha' => 'E', 'vendas-artigo' => 'E',
            'vendas-promotor' => 'E', 'vendas-promotor-artigo' => 'E', 'suporte' => 'V'],
        'por_rota' => false, 'dois_passos' => true, 'conflitos' => [], 'activo' => true];
    // Responsável da área de negócio: tudo o que a Direcção e a Direcção comercial vêem e fazem (o nível mais alto de
    // cada uma), sem a administração do portal nem configurações (menor privilégio, ISO/IEC 27001:2022 A.5.15 e A.8.2):
    // fora acessos, actualizações, módulos, conformidade, registos, utilizadores, configuração, gestão das zonas e
    // administração dos equipamentos; os parâmetros do valor do portal e o calendário ficam só para consulta. As
    // aprovações (caixa final, fecho das conciliações, ficheiro SAP) juntam-se pelas funções complementares.
    $ordem = NAVAL_ORDEM_NIVEIS;
    $resp = $dePerfil('COM');
    foreach ($dePerfil('DIR') as $rec => $n) if (!isset($resp[$rec]) || $ordem[$n] > $ordem[$resp[$rec]]) $resp[$rec] = $n;
    foreach (['acessos', 'actualizacoes', 'modulos', 'conformidade', 'registos', 'admin-utilizadores', 'admin-config', 'geo-zonas-gerir', 'equipamentos-administrar'] as $rec) unset($resp[$rec]);
    foreach (['valor-portal', 'calendario'] as $rec) if (isset($resp[$rec])) $resp[$rec] = 'V';
    $f['P-RESP'] = ['nome' => 'Responsável da área de negócio', 'descricao' => 'Todos os módulos de gestão (vendas, clientes, terreno, logística, cobranças, inteligência), sem administração nem configurações.',
        'tipo' => 'base', 'permissoes' => $resp, 'por_rota' => false, 'dois_passos' => true, 'conflitos' => [], 'activo' => true];
    $comp = function (string $nome, string $desc, array $perm, array $conflitos = [], bool $mfa = false) {
        return ['nome' => $nome, 'descricao' => $desc, 'tipo' => 'complementar', 'permissoes' => $perm, 'por_rota' => false,
            'dois_passos' => $mfa, 'conflitos' => $conflitos, 'activo' => true];
    };
    $f['C-CAIXA-LANCAR'] = $comp('Lançar e validar o fecho de caixa', 'Quem recebe o dinheiro: lança entregas, talões e depósitos e valida com a contagem.',
        ['caixa-marketing' => 'G', 'relatorio-vendas' => 'V'], ['C-CAIXA-APROVAR-1', 'C-CAIXA-APROVAR']);
    $f['C-CAIXA-APROVAR-1'] = $comp('Aprovar o fecho de caixa (1.º nível)', 'Superior de quem lança: aprova os depósitos e a folha validada.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao' => 'G', 'multicaixa-tpa' => 'V'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR', 'P-TES'], true);
    $f['C-CAIXA-APROVAR'] = $comp('Aprovar o fecho de caixa (final)', 'Responsável máximo: aprovação final da folha e dos depósitos.',
        ['caixa-marketing' => 'V', 'caixa-aprovacao-final' => 'G', 'relatorio-vendas' => 'V', 'multicaixa-tpa' => 'V'], ['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR-1', 'P-TES'], true);
    $f['C-CONC-CONTAR'] = $comp('Contar as carrinhas', 'Técnico do armazém: contagem às cegas e validação da conciliação.',
        ['conciliacao-carrinha' => 'G', 'stock-carrinha' => 'V'], ['C-CONC-COORD', 'C-CONC-FECHO']);
    $f['C-CONC-COORD'] = $comp('Aprovar conciliações (coordenador)', '1.º nível de aprovação das diferenças da carrinha.',
        ['conciliacao-carrinha' => 'V', 'conciliacao-coordenacao' => 'G'], ['C-CONC-CONTAR', 'C-CONC-FECHO'], true);
    $f['C-CONC-FECHO'] = $comp('Fechar conciliações (responsável)', '2.º nível: aprovação final e fecho da conciliação.',
        ['conciliacao-carrinha' => 'V', 'conciliacao-fecho' => 'G'], ['C-CONC-CONTAR', 'C-CONC-COORD'], true);
    // PO: o coordenador aprova a PO para a carga no cais (quem a criou ou emitiu não a aprova: regra do servidor)
    $f['C-PO-APROVAR'] = $comp('Aprovar as PO para a carga', 'Coordenador: aprova as PO emitidas pelos promotores antes da carga no cais.',
        ['requisicoes-carrinha' => 'V', 'requisicoes-aprovacao' => 'G', 'cargas-po' => 'V', 'stock-armazem' => 'V'], ['C-PO-VALIDAR'], true);
    // PO: o controlo de stock ajusta as quantidades e valida antes da aprovação; quem valida não aprova (separação)
    $f['C-PO-VALIDAR'] = $comp('Validar as PO (controlo de stock)', 'Ajusta as quantidades pedidas ao stock do armazém e valida a PO antes da aprovação do superior.',
        ['requisicoes-carrinha' => 'V', 'requisicoes-validacao' => 'G', 'cargas-po' => 'V', 'stock-armazem' => 'V'], ['C-PO-APROVAR'], true);
    // ficheiro para o SAP: gerar e enviar, sem alterar a estrutura do ficheiro; cada geração fica registada
    $f['C-SAP-EXPORTAR'] = $comp('Gerar e enviar o ficheiro SAP', 'Gera a exportação das vendas para o SAP (estrutura fixa) e envia-a.', ['sap-exportar' => 'E'], [], true);
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
    if (naval_contas_novas_pendentes()) naval_contas_novas_criar();   // primeiro as contas novas: as reconfigurações seguintes já as encontram
    if (naval_contas_ajustes_pendentes()) naval_contas_ajustar();   // pode recarregar o catálogo (donos)
    return $f;
}

function naval_contas_ajustes_pendentes(): array
{
    return array_diff_key(NAVAL_CONTAS_AJUSTES, naval_json_ler(naval_funcoes_ficheiro_ajustes()));
}

/** Aplica, uma vez cada, as reconfigurações de contas pendentes (ver NAVAL_CONTAS_AJUSTES). */
function naval_contas_ajustar(): array
{
    static $emCurso = false;
    if ($emCurso || !naval_contas_ajustes_pendentes()) return [];
    $emCurso = true;
    try {
        return naval_exclusivo('utilizadores', function () {
            $res = [];
            foreach (naval_contas_ajustes_pendentes() as $id => $a) {
                $res[$id] = naval_contas_ajuste_um((array) $a);
                $feitos = naval_json_ler(naval_funcoes_ficheiro_ajustes());
                $feitos[$id] = ['em' => date('Y-m-d H:i')] + $res[$id];
                naval_json_gravar(naval_funcoes_ficheiro_ajustes(), $feitos);
            }
            return $res;
        });
    } finally {
        $emCurso = false;
    }
}

function naval_contas_nome_normal(string $n): string
{
    $n = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $n)));
    return strtr($n, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
}

/** Uma reconfiguração: conta única e activa (pelo nome), funções sem conflitos; depois os donos das funções. */
function naval_contas_ajuste_um(array $a): array
{
    $nome = naval_contas_nome_normal((string) ($a['nome'] ?? ''));
    $d = naval_utilizadores();
    $cand = [];
    foreach ($d as $l => $u) {
        if (!naval_conta_valida($u) || ($u['perfil'] ?? '') === 'SADM') continue;
        if (naval_contas_nome_normal((string) ($u['nome'] ?? '')) === $nome) $cand[] = (string) $l;
    }
    $falha = function (string $motivo) use ($a) {
        naval_registar('conta_ajuste_nao_aplicado', ['nome' => (string) ($a['nome'] ?? ''), 'motivo' => $motivo], 'sistema');
        return ['ok' => false, 'nome' => (string) ($a['nome'] ?? ''), 'motivo' => $motivo];
    };
    if (count($cand) !== 1) return $falha(count($cand) ? 'há mais de uma conta activa com este nome' : 'não há nenhuma conta activa com este nome');
    $f = naval_funcoes();
    $funcoes = array_values(array_unique(array_map('strval', (array) ($a['funcoes'] ?? []))));
    sort($funcoes);
    foreach ($funcoes as $c) if (empty($f[$c]['activo'])) return $falha('a função ' . $c . ' não existe ou está desactivada');
    if (naval_funcoes_conflitos($funcoes)) return $falha('as funções formam uma combinação proibida');
    $l = $cand[0];
    $u = $d[$l];
    $antes = ['perfil' => (string) ($u['perfil'] ?? ''), 'funcoes' => array_values((array) ($u['funcoes'] ?? []))];
    try {
        $perfil = naval_funcoes_perfil($funcoes, 'sistema');
    } catch (NavalErro $e) {
        return $falha($e->getMessage());
    }
    $u['perfil'] = $perfil;
    $u['funcoes'] = $funcoes;
    $fim = array_intersect_key((array) ($u['funcoes_fim'] ?? []), array_flip($funcoes));
    if ($fim) $u['funcoes_fim'] = $fim; else unset($u['funcoes_fim']);
    if (!in_array($perfil, naval_perfis_por_rota(), true)) $u['rotas'] = [];
    $u['alterado'] = date('Y-m-d H:i');
    $u['alterado_por'] = 'sistema';
    $d[$l] = $u;
    naval_gravar_utilizadores($d);
    naval_registar('conta_alterada', ['conta' => $l, 'funcoes' => implode(', ', $funcoes), 'perfil' => $perfil, 'antes' => $antes,
        'motivo' => 'reconfiguração pedida pelo super administrador (' . (string) ($a['pedido'] ?? '') . ')'], 'sistema');
    // donos das funções (autorizam os pedidos de acesso e revêem quem as tem)
    $donos = array_values(array_filter(array_map('strval', (array) ($a['dono_de'] ?? [])), function ($c) use ($f) { return isset($f[$c]); }));
    if ($donos) {
        $g = naval_json_ler(naval_ficheiro_funcoes());
        $g = ($g ? (array) $g : []) + naval_funcoes_iniciais();
        foreach ($donos as $c) {
            $g[$c]['dono'] = $l;
            unset($g[$c]['conflitos_sistema']);
        }
        naval_json_gravar(naval_ficheiro_funcoes(), $g);
        naval_funcoes(true);
        naval_registar('funcoes_donos', ['dono' => $l, 'funcoes' => $donos], 'sistema');
    }
    return ['ok' => true, 'conta' => $l, 'funcoes' => $funcoes, 'perfil' => $perfil, 'antes' => $antes, 'dono_de' => $donos];
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
 * Passagem de todas as contas para funções (padrão RBAC: todo o acesso vem de funções, NIST/INCITS 359; ISO/IEC 27001
 * A.5.15 e A.5.18), sem retirar nem acrescentar nenhum acesso:
 *   - perfil com função-base equivalente que dá exactamente os mesmos acessos: passa para essa função;
 *   - senão: função de transição «T-<perfil>» com as permissões exactas do perfil em vigor (âmbito por rota e dois
 *     passos incluídos), partilhada pelas contas desse perfil e marcada para o dono a rever na revisão trimestral.
 * Fica tudo registado (conta, perfil anterior, função). Uma só vez (marca em dados-privados); FUNCOES_MIGRAR=0 desliga.
 * Devolve {convertidas: [login], transicao: {T-xx: [login]}, ficam: [login]}.
 */
function naval_funcoes_migrar(string $por = 'sistema'): array
{
    $res = ['convertidas' => [], 'transicao' => [], 'ficam' => []];
    $lista = naval_funcoes_sem_funcoes();
    if (!$lista) return $res;
    $f = naval_funcoes();
    $m = naval_matriz();
    $perfis = naval_perfis_todos();
    $sem = [];
    foreach ($lista as $x) {
        if ($x['funcao'] && !$x['diferencas']) $res['convertidas'][] = $x['login'];
        else $sem[$x['perfil']][] = $x['login'];
    }
    if ($res['convertidas']) naval_funcoes_converter($res['convertidas'], $por);
    foreach ($sem as $perfil => $logins) {
        if (!isset($perfis[$perfil]) || empty($perfis[$perfil]['activo'])) {
            array_push($res['ficam'], ...$logins);   // perfil inexistente ou desligado: o super administrador decide
            continue;
        }
        $codigo = substr('T-' . preg_replace('/[^A-Z0-9-]/', '', strtoupper($perfil)), 0, 24);
        if (strlen($codigo) < 3) $codigo = 'T-' . strtoupper(substr(hash('sha256', $perfil), 0, 6));
        $perm = [];
        foreach ($m as $rec => $col) {
            $n = (string) ($col[$perfil] ?? '');
            if ($n !== '' && $n !== 'A') $perm[$rec] = $n;
        }
        if (!isset($f[$codigo])) {
            $g = naval_json_ler(naval_ficheiro_funcoes());
            $g = $g ?: naval_funcoes_iniciais();
            $g[$codigo] = ['nome' => mb_substr('Transição · ' . $perfis[$perfil]['nome'], 0, 80),
                'descricao' => 'Os acessos que o perfil «' . $perfis[$perfil]['nome'] . '» tinha na passagem para funções, sem tirar nem pôr. Rever na revisão trimestral: trocar pelas funções do trabalho.',
                'tipo' => 'base', 'permissoes' => $perm, 'por_rota' => !empty($perfis[$perfil]['por_rota']), 'dois_passos' => !empty($perfis[$perfil]['dois_passos']),
                'conflitos' => [], 'dono' => '', 'activo' => true, 'transicao' => true, 'perfil_origem' => $perfil, 'criada' => date('Y-m-d H:i'), 'criada_por' => $por];
            naval_json_gravar(naval_ficheiro_funcoes(), $g);
            $f = naval_funcoes(true);
            naval_registar('funcao_criada', ['funcao' => $codigo, 'transicao' => true, 'perfil' => $perfil]);
        }
        $d = naval_utilizadores();
        $gerado = naval_funcoes_perfil([$codigo], $por);
        foreach ($logins as $l) {
            if (!isset($d[$l])) continue;
            $antes = (string) ($d[$l]['perfil'] ?? '');
            $d[$l]['perfil'] = $gerado;
            $d[$l]['funcoes'] = [$codigo];
            $d[$l]['alterado'] = date('Y-m-d H:i');
            $d[$l]['alterado_por'] = $por;
            $res['transicao'][$codigo][] = $l;
            naval_registar('conta_alterada', ['conta' => $l, 'funcoes' => $codigo, 'perfil' => $gerado, 'perfil_anterior' => $antes, 'motivo' => 'passagem para funções sem mudança de acessos (transição)']);
        }
        naval_gravar_utilizadores($d);
    }
    return $res;
}

/** Migração para funções, uma só vez (rotina periódica). Devolve o resultado, ou null se já foi feita ou está desligada. */
function naval_funcoes_migrar_uma_vez(int $agora): ?array
{
    if (naval_config('FUNCOES_MIGRAR', '1') === '0') return null;
    $marca = naval_pasta('funcoes') . '/migracao.json';
    if (is_file($marca)) return null;
    $r = naval_exclusivo('utilizadores', function () { return naval_funcoes_migrar('sistema'); });
    naval_json_gravar($marca, ['em' => date('Y-m-d H:i:s', $agora), 'convertidas' => count($r['convertidas']),
        'transicao' => array_map('count', $r['transicao']), 'ficam' => $r['ficam']]);
    naval_registar('funcoes_migracao', ['convertidas' => count($r['convertidas']), 'transicao' => array_map('count', $r['transicao']), 'ficam' => count($r['ficam'])], 'sistema');
    return $r;
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
