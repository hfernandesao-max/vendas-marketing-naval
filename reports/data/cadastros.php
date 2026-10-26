<?php
/** Cadastros de clientes RTM → JSON para o portal (Cadastros, Carteira e Plano de visitas), com o âmbito do perfil. */
require_once __DIR__ . '/../../nucleo/api.php';
naval_servico('cadastros');
naval_exigir(['cadastros', 'cadastros-analise', 'cadastros-clientes', 'clientes', 'plano-visitas', 'auditoria', 'inicio']);

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
