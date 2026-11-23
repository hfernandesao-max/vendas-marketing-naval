<?php
// Atribuição automática de acessos por funções (nucleo/seguranca/funcoes.php): permissões calculadas, separação de
// funções e actualização das contas quando uma função muda.

function fn_limpar(): void
{
    @unlink(naval_ficheiro_funcoes());
    @unlink(naval_funcoes_ficheiro_ajustes());
    naval_gravar_perfis([]);
    naval_funcoes(true);
}

teste('funções: catálogo inicial (uma por perfil do sistema + aprovação e controlo), sem nível A', function () {
    fn_limpar();
    $f = naval_funcoes();
    verdade(isset($f['P-PRO'], $f['P-FIN'], $f['C-CAIXA-LANCAR'], $f['C-CAIXA-APROVAR'], $f['C-CONC-CONTAR']));
    foreach ($f as $c => $x) foreach ($x['permissoes'] as $rec => $n) verdade($n !== 'A', "$c/$rec sem administrar");
    igual('G', $f['C-CAIXA-APROVAR']['permissoes']['caixa-aprovacao-final']);
    verdade(in_array('C-CAIXA-LANCAR', $f['C-CAIXA-APROVAR']['conflitos'], true), 'lançar × aprovar a caixa');
});

teste('funções: perfil gerado com a maior permissão de cada página; o mesmo conjunto dá o mesmo perfil', function () {
    fn_limpar();
    $p = naval_funcoes_perfil(['P-FIN', 'C-CAIXA-LANCAR'], 'admin');
    verdade((bool) preg_match('/^F[0-9A-F]{7}$/', $p), $p);
    igual($p, naval_funcoes_perfil(['C-CAIXA-LANCAR', 'P-FIN'], 'admin'), 'ordem das funções não importa');
    $m = naval_matriz();
    igual('G', $m['caixa-marketing'][$p], 'caixa: G (lançar)');
    igual('', $m['caixa-aprovacao-final'][$p], 'sem aprovação final');
    igual(naval_matriz_base()['pendentes']['FIN'], $m['pendentes'][$p], 'herda a função-base FIN');
    verdade(isset(naval_perfis()[$p]), 'perfil activo');
    $c = naval_perfis_criados();
    igual(['C-CAIXA-LANCAR', 'P-FIN'], $c[$p]['funcoes']);
    verdade(!empty($c[$p]['gerado']));
    // a matriz não altera um perfil gerado (só as funções o mudam)
    naval_gravar_matriz(['caixa-marketing' => [$p => 'V']]);
    igual('G', naval_matriz(true)['caixa-marketing'][$p]);
    naval_gravar_matriz([]);
});

teste('funções: separação de funções recusa lançar e aprovar a mesma caixa; função inexistente ou vazio recusados', function () {
    fn_limpar();
    lanca(400, function () { naval_funcoes_perfil(['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil(['C-CONC-CONTAR', 'C-CONC-FECHO'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil(['NAO-EXISTE'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil([], 'admin'); });
    igual([['C-CAIXA-APROVAR', 'C-CAIXA-LANCAR']], naval_funcoes_conflitos(['C-CAIXA-LANCAR', 'C-CAIXA-APROVAR', 'P-DIR']));
});

teste('funções: alterar uma função actualiza sozinho os perfis gerados; conflitos simétricos; âmbito e dois passos', function () {
    fn_limpar();
    $p = naval_funcoes_perfil(['P-SUP', 'C-CAIXA-APROVAR'], 'admin');
    verdade(in_array($p, naval_perfis_por_rota(), true), 'SUP: só as suas rotas');
    verdade(in_array($p, naval_perfis_mfa(), true), 'aprovar a caixa: dois passos');
    $f = naval_funcoes()['C-CAIXA-APROVAR'];
    $f['permissoes']['relatorio-vendas'] = 'E';
    naval_funcoes_gravar(['codigo' => 'C-CAIXA-APROVAR'] + $f, 'admin');
    igual('E', naval_matriz(true)['relatorio-vendas'][$p], 'o perfil gerado acompanha a função');
    // função nova com conflito: fica nos dois lados
    naval_funcoes_gravar(['codigo' => 'C-DEPOSITOS', 'nome' => 'Levar depósitos ao banco', 'permissoes' => ['caixa-marketing' => 'V', 'administrar' => 'A'],
        'conflitos' => ['C-CAIXA-APROVAR']], 'admin');
    $todas = naval_funcoes();
    verdade(in_array('C-DEPOSITOS', $todas['C-CAIXA-APROVAR']['conflitos'], true), 'conflito simétrico');
    igual(['caixa-marketing' => 'V'], $todas['C-DEPOSITOS']['permissoes'], 'sem A nem páginas inexistentes');
    fn_limpar();
});

teste('funções: regras do sistema — a logística não aprova nem fecha a conciliação; as finanças não aprovam a caixa', function () {
    fn_limpar();
    lanca(400, function () { naval_funcoes_perfil(['P-LOG', 'C-CONC-COORD'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil(['P-LOG', 'C-CONC-FECHO'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil(['P-FIN', 'C-CAIXA-APROVAR-1'], 'admin'); });
    lanca(400, function () { naval_funcoes_perfil(['P-FIN', 'C-CAIXA-APROVAR'], 'admin'); });
    $f = naval_funcoes();
    verdade(in_array('P-LOG', $f['C-CONC-COORD']['conflitos_sistema'], true), 'simétrico e marcado como do sistema');
    // retirar a regra no portal não a retira: volta sempre ao ler
    $log = $f['P-LOG'];
    naval_funcoes_gravar(['codigo' => 'P-LOG', 'conflitos' => []] + $log, 'admin');
    verdade(in_array('C-CONC-COORD', naval_funcoes(true)['P-LOG']['conflitos'], true), 'regra mantém-se');
    verdade(!isset(json_decode((string) file_get_contents(naval_ficheiro_funcoes()), true)['P-LOG']['conflitos_sistema']), 'não se grava');
    fn_limpar();
});

teste('funções: ajuste do sistema ao catálogo gravado (apoio administrativo vê a conciliação) aplica-se uma vez e actualiza os perfis', function () {
    fn_limpar();
    igual('V', naval_funcoes()['P-BO']['permissoes']['conciliacao-carrinha'] ?? '', 'catálogo inicial já com V');
    // catálogo gravado antes do ajuste: P-BO sem a conciliação, e um perfil gerado com P-BO
    $g = naval_funcoes_iniciais();
    unset($g['P-BO']['permissoes']['conciliacao-carrinha']);
    naval_json_gravar(naval_ficheiro_funcoes(), $g);
    naval_json_gravar(naval_funcoes_ficheiro_ajustes(), ['2026-11-bo-conciliacao' => '2026-11-01 00:00']);
    naval_funcoes(true);
    $p = naval_funcoes_perfil(['P-BO'], 'admin');
    igual('', naval_matriz(true)['conciliacao-carrinha'][$p], 'antes do ajuste');
    @unlink(naval_funcoes_ficheiro_ajustes());
    igual('V', naval_funcoes(true)['P-BO']['permissoes']['conciliacao-carrinha'], 'ajustado');
    igual('V', naval_matriz(true)['conciliacao-carrinha'][$p], 'perfil gerado actualizado');
    verdade(isset(naval_json_ler(naval_funcoes_ficheiro_ajustes())['2026-11-bo-conciliacao']), 'marcado como feito');
    // depois de feito, uma alteração do super administrador mantém-se
    $bo = naval_funcoes()['P-BO'];
    unset($bo['permissoes']['conciliacao-carrinha']);
    naval_funcoes_gravar(['codigo' => 'P-BO'] + $bo, 'admin');
    igual('', naval_funcoes(true)['P-BO']['permissoes']['conciliacao-carrinha'] ?? '', 'não volta a aplicar');
    igual('V', naval_matriz_base()['conciliacao-carrinha']['BO'], 'perfil do sistema BO também vê');
    fn_limpar();
});

/** Contas de ensaio (lista nova), guardando a original para repor. */
function fn_contas(array $contas): ?string
{
    $f = naval_ficheiro_utilizadores();
    $antes = is_file($f) ? (string) file_get_contents($f) : null;
    $d = [];
    foreach ($contas as $l => $x) $d[$l] = $x + ['nome' => ucfirst($l), 'email' => $l . '@naval.co.ao', 'activo' => true, 'idioma' => 'pt'];
    naval_gravar_utilizadores($d);
    return $antes;
}

function fn_repor(?string $antes): void
{
    $f = naval_ficheiro_utilizadores();
    if ($antes === null) @unlink($f); else file_put_contents($f, $antes);
}

teste('funções: dono validado; contas em conflito; passagem para funções só sem mudança de acessos; funções temporárias', function () {
    fn_limpar();
    $antes = fn_contas([
        'chefe' => ['perfil' => 'LOG'],
        'ana' => ['perfil' => 'LOG'],
        'rui' => ['perfil' => 'COM'],
        'desligado' => ['perfil' => 'LOG', 'activo' => false],
    ]);
    try {
        $log = naval_funcoes()['P-LOG'];
        lanca(400, function () use ($log) { naval_funcoes_gravar(['codigo' => 'P-LOG', 'dono' => 'desligado'] + $log, 'admin'); });
        naval_funcoes_gravar(['codigo' => 'P-LOG', 'dono' => 'chefe'] + $log, 'admin');
        igual('chefe', naval_funcoes(true)['P-LOG']['dono']);
        // matriz com uma alteração na coluna COM: o Rui não passa sozinho (mudaria um acesso)
        naval_gravar_matriz(['ranking' => ['COM' => 'V']]);
        $sem = [];
        foreach (naval_funcoes_sem_funcoes() as $x) $sem[$x['login']] = $x;
        igual([], $sem['ana']['diferencas'], 'LOG = P-LOG');
        igual('P-LOG', $sem['ana']['funcao']);
        verdade((bool) $sem['rui']['diferencas'], 'COM alterado na matriz');
        verdade(!isset($sem['desligado']), 'contas desligadas fora');
        $r = naval_funcoes_converter(['ana', 'rui'], 'admin');
        igual(['ana'], $r['convertidas']);
        igual(['rui'], $r['ficam']);
        $u = naval_utilizadores();
        igual(['P-LOG'], $u['ana']['funcoes']);
        igual(naval_matriz(true)['plano-cargas']['LOG'], naval_matriz()['plano-cargas'][$u['ana']['perfil']], 'mesmos acessos');
        naval_gravar_matriz([]);
        // conta com combinação que passou a ser proibida (gravada antes da regra)
        $d = naval_utilizadores();
        $d['ana']['funcoes'] = ['C-CONC-COORD', 'P-LOG'];
        naval_gravar_utilizadores($d);
        igual([['C-CONC-COORD', 'P-LOG']], array_column(naval_funcoes_contas_conflito(), 'funcoes'));
        // temporária: no dia a seguir ao último dia sai sozinha; sem funções, a conta desliga-se
        $d['ana']['funcoes'] = ['C-CONC-CONTAR', 'P-LOG'];
        $d['ana']['funcoes_fim'] = ['C-CONC-CONTAR' => '2026-11-17'];
        $d['chefe']['funcoes'] = ['P-LOG'];
        $d['chefe']['funcoes_fim'] = ['P-LOG' => '2026-11-17'];
        naval_gravar_utilizadores($d);
        igual([], naval_funcoes_expirar(strtotime('2026-11-17 23:00')), 'no último dia ainda tem');
        $m = naval_funcoes_expirar(strtotime('2026-11-18 02:00'));
        igual(['ana', 'chefe'], array_keys($m));
        $u = naval_utilizadores();
        igual(['P-LOG'], $u['ana']['funcoes']);
        verdade(!isset($u['ana']['funcoes_fim']));
        igual(naval_funcoes_codigo(['P-LOG']), $u['ana']['perfil'], 'perfil recalculado');
        verdade(empty($u['chefe']['activo']), 'sem funções: desligada');
    } finally {
        naval_gravar_matriz([]);
        fn_repor($antes);
        fn_limpar();
    }
});

teste('funções: coordenação operacional em todas as rotas; ficheiro SAP; quem aprova a caixa vê o Multicaixa por TPA', function () {
    fn_limpar();
    $f = naval_funcoes();
    $c = $f['P-COORD'];
    verdade(!$c['por_rota'] && $c['dois_passos']);
    igual('G', $c['permissoes']['requisicoes-carrinha']);
    igual('V', $c['permissoes']['vendas-dia'], 'Vr do supervisor passa a V (todas as rotas)');
    igual('E', $c['permissoes']['cadastros-clientes']);
    igual('V', $c['permissoes']['stock-armazem']);
    igual(['sap-exportar' => 'E'], $f['C-SAP-EXPORTAR']['permissoes']);
    igual('V', $f['C-CAIXA-APROVAR-1']['permissoes']['multicaixa-tpa']);
    igual('V', $f['C-CAIXA-APROVAR']['permissoes']['multicaixa-tpa']);
    // catálogo gravado antes: os ajustes juntam o Multicaixa às duas aprovações
    $g = naval_funcoes_iniciais();
    unset($g['C-CAIXA-APROVAR-1']['permissoes']['multicaixa-tpa'], $g['C-CAIXA-APROVAR']['permissoes']['multicaixa-tpa'], $g['P-COORD'], $g['C-SAP-EXPORTAR']);
    naval_json_gravar(naval_ficheiro_funcoes(), $g);
    naval_json_gravar(naval_funcoes_ficheiro_ajustes(), ['2026-11-bo-conciliacao' => 'x'] + array_fill_keys(array_keys(NAVAL_CONTAS_AJUSTES), 'x'));
    $f = naval_funcoes(true);
    igual('V', $f['C-CAIXA-APROVAR-1']['permissoes']['multicaixa-tpa']);
    verdade(isset($f['P-COORD'], $f['C-SAP-EXPORTAR']), 'funções novas juntam-se ao catálogo gravado');
    fn_limpar();
});

teste('contas: reconfiguração pedida pelo SADM aplica-se uma vez, pelo nome, com o estado anterior registado e os donos', function () {
    fn_limpar();
    $antes = fn_contas([
        'michael.samson' => ['nome' => 'Michael  Samson', 'perfil' => 'DIR'],
        'nathan' => ['nome' => 'Nathan Alemayehu', 'perfil' => 'SUP', 'rotas' => ['1001']],
        'outro' => ['nome' => 'Nathan Alemayehu', 'perfil' => 'COM', 'activo' => false],
    ]);
    try {
        @unlink(naval_funcoes_ficheiro_ajustes());
        naval_funcoes(true);
        $u = naval_utilizadores();
        // as reconfigurações aplicam-se por ordem: a última de cada pessoa é a que fica
        igual(['C-CAIXA-APROVAR', 'C-CONC-FECHO', 'C-SAP-EXPORTAR', 'P-RESP'], $u['michael.samson']['funcoes']);
        igual(naval_funcoes_codigo($u['michael.samson']['funcoes']), $u['michael.samson']['perfil']);
        igual(['C-CONC-COORD', 'C-PO-APROVAR', 'P-COORD', 'P-STOCK'], $u['nathan']['funcoes']);
        igual([], $u['nathan']['rotas'], 'todas as rotas');
        $m = naval_matriz(true);
        igual('G', $m['caixa-aprovacao-final'][$u['michael.samson']['perfil']]);
        igual('G', $m['conciliacao-fecho'][$u['michael.samson']['perfil']]);
        igual('E', $m['sap-exportar'][$u['michael.samson']['perfil']]);
        igual('E', $m['relatorio-vendas'][$u['michael.samson']['perfil']]);
        igual('G', $m['conciliacao-coordenacao'][$u['nathan']['perfil']]);
        igual('G', $m['requisicoes-aprovacao'][$u['nathan']['perfil']], 'o Nathan (coordenador) aprova as PO para a carga');
        igual('', $m['caixa-aprovacao'][$u['nathan']['perfil']] ?? '', 'o Nathan não aprova a caixa');
        $f = naval_funcoes();
        igual('michael.samson', $f['C-CONC-COORD']['dono']);
        igual('michael.samson', $f['P-COORD']['dono']);
        igual('nathan', $f['P-PRO']['dono'], 'o Nathan, responsável dos vendedores, autoriza os pedidos de vendedor');
        igual('nathan', $f['C-CONC-CONTAR']['dono']);
        igual('', $m['acessos'][$u['michael.samson']['perfil']] ?? '', 'o Michael não administra o portal');
        igual('E', $m['stock-carrinha'][$u['nathan']['perfil']], 'o Nathan vê todo o stock');
        igual('', (string) ($f['C-SAP-EXPORTAR']['dono'] ?? ''));
        $r = naval_json_ler(naval_funcoes_ficheiro_ajustes());
        igual(['perfil' => 'DIR', 'funcoes' => []], $r['2026-11-michael-samson']['antes']);
        verdade($r['2026-11-nathan-alemayehu']['ok']);
        // uma vez só: alterações seguintes do super administrador mantêm-se
        $d = naval_utilizadores();
        $d['nathan']['funcoes'] = ['P-COORD'];
        naval_gravar_utilizadores($d);
        naval_funcoes(true);
        igual(['P-COORD'], naval_utilizadores()['nathan']['funcoes']);
        // conta ambígua: não muda nada e fica registado
        @unlink(naval_funcoes_ficheiro_ajustes());
        $d['nathan2'] = $d['nathan'];
        naval_gravar_utilizadores($d);
        naval_funcoes(true);
        $r = naval_json_ler(naval_funcoes_ficheiro_ajustes());
        verdade(!$r['2026-11-nathan-alemayehu']['ok']);
        igual('há mais de uma conta activa com este nome', $r['2026-11-nathan-alemayehu']['motivo']);
        igual(['P-COORD'], naval_utilizadores()['nathan']['funcoes']);
    } finally {
        fn_repor($antes);
        fn_limpar();
    }
});

teste('função «Controlo de stock»: só o stock de todos os armazéns e carrinhas e as vendas por artigo; dois passos; todas as rotas', function () {
    $f = naval_funcoes_iniciais()['P-STOCK'];
    igual(['pagina-principal' => 'V', 'stock-armazem' => 'E', 'stock-carrinha' => 'E', 'suporte' => 'V', 'vendas-artigo' => 'E', 'vendas-promotor' => 'E',
        'vendas-promotor-artigo' => 'E'], $f['permissoes']);
    igual([true, false, 'base'], [$f['dois_passos'], $f['por_rota'], $f['tipo']]);
    foreach (['caixa-marketing', 'clientes', 'documentos', 'pagamentos', 'acessos', 'conciliacao-carrinha', 'requisicoes-carrinha', 'excel-completo'] as $r)
        igual(false, isset($f['permissoes'][$r]), 'sem ' . $r);
});

teste('função «Responsável da área de negócio»: todos os módulos de gestão, sem administração nem configurações; combinações do Michael e do Nathan permitidas', function () {
    $f = naval_funcoes_iniciais();
    $p = $f['P-RESP']['permissoes'];
    foreach (['acessos', 'actualizacoes', 'modulos', 'conformidade', 'registos', 'admin-utilizadores', 'admin-config', 'geo-zonas-gerir', 'equipamentos-administrar'] as $r)
        igual(false, isset($p[$r]), 'sem ' . $r);
    igual(['V', 'V'], [$p['valor-portal'] ?? 'V', $p['calendario'] ?? 'V'], 'parâmetros e calendário só para consulta');
    foreach (['vendas-artigo', 'perdas', 'relatorio-vendas', 'stock-armazem', 'conciliacao-carrinha', 'pendentes'] as $r) verdade(isset($p[$r]), 'com ' . $r);
    igual(true, $f['P-RESP']['dois_passos']);
    igual([], naval_funcoes_conflitos(['P-RESP', 'C-CAIXA-APROVAR', 'C-CONC-FECHO', 'C-SAP-EXPORTAR']), 'Michael');
    igual([], naval_funcoes_conflitos(['P-COORD', 'C-CONC-COORD', 'C-PO-APROVAR', 'P-STOCK']), 'Nathan');
    igual([], naval_funcoes_conflitos(['P-STOCK', 'C-CONC-CONTAR']), 'Manuel');
    verdade((bool) naval_funcoes_conflitos(['C-CONC-CONTAR', 'C-CONC-COORD']), 'quem conta não aprova a contagem');
});
