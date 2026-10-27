<?php
/**
 * Estrutura de dados (dicionário de dados) para o Excel «Estrutura de dados»: catálogo da base de dados
 * do Pinguim BO (só SELECT às vistas do catálogo, sem dados de negócio) e o modelo de acessos do portal.
 * Só para perfis com o nível E em «estrutura-dados»; cada pedido fica nos registos como exportação.
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('dicionario');

if (!naval_pode_exportar('estrutura-dados')) {
    naval_registar('exportacao_recusada', ['pagina' => 'estrutura-dados']);
    throw new NavalErro('O seu perfil não permite exportar a estrutura de dados.', 403);
}
$out = ['gerado' => date('Y-m-d H:i:s'), 'perfis' => naval_perfis(), 'matriz' => naval_matriz()];
if (!naval_bd_configurada()) {
    $out['bd'] = ['disponivel' => false, 'erro' => 'A ligação à base de dados do Pinguim BO não está configurada no .env do servidor.'];
} else {
    try {
        $out['bd'] = ['disponivel' => true] + naval_bd_dicionario();
    } catch (Throwable $e) {
        error_log('[estrutura-dados] ' . $e->getMessage());
        $out['bd'] = ['disponivel' => false, 'erro' => 'Não foi possível ler a estrutura da base de dados do Pinguim BO.'];
    }
}
naval_registar('exportacao', [
    'pagina' => 'estrutura-dados', 'completo' => false, 'tabela' => 'Estrutura de dados',
    'linhas' => count($out['bd']['colunas'] ?? []),
]);
naval_json($out);
