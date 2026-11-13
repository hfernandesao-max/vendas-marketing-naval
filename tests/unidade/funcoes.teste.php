<?php
// Atribuição automática de acessos por funções (nucleo/seguranca/funcoes.php): permissões calculadas, separação de
// funções e actualização das contas quando uma função muda.

function fn_limpar(): void
{
    @unlink(naval_ficheiro_funcoes());
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
