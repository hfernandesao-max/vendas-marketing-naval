<?php
/** Cadastros de clientes RTM → JSON para o portal (Cadastros, Carteira e Plano de visitas), com o âmbito do perfil. */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('cadastros');
naval_exigir(['cadastros', 'cadastros-analise', 'cadastros-clientes', 'clientes', 'plano-visitas', 'auditoria', 'inicio']);

// Pedido condicional resolvido antes de ler e filtrar o ficheiro (pode ter vários MB)
$fich = naval_cadastros_ficheiro();
naval_etag_antecipado($fich === null ? null : naval_meta_ficheiros([$fich]), [__FILE__, dirname(__DIR__, 2) . '/servicos/cadastros.php']);
$d = naval_cadastros_ler();
if ($d === null) {
    throw new NavalErro('Dados de cadastros não encontrados.', 404, ['detalhe' => 'Configure RTM_CLIENTES_JSON no .env ou clone o repositório rtm-clientes ao lado da pasta do site.']);
}
$pode = function (array $recursos) {
    foreach ($recursos as $r) if (naval_pode($r)) return true;
    return false;
};
naval_json(naval_cadastros_ambito($d, naval_rotas(), naval_ve_nif(),
    $pode(['cadastros', 'cadastros-analise', 'cadastros-clientes']), $pode(['clientes', 'plano-visitas', 'auditoria'])));
