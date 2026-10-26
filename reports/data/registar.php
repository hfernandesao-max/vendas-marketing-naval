<?php
/**
 * Regista as exportações para Excel e confirma que o perfil as pode fazer.
 * O portal chama este serviço antes de gerar cada ficheiro; sem «ok» não exporta.
 */
require_once __DIR__ . '/../../nucleo/api.php';

$p = naval_pedido();
$pagina = (string) preg_replace('/[^a-z0-9-]/', '', (string) ($p['pagina'] ?? ''));
$completo = !empty($p['completo']);
$recurso = $completo ? 'excel-completo' : $pagina;
if ($recurso === '' || !naval_pode_exportar($recurso)) {
    naval_registar('exportacao_recusada', ['pagina' => $pagina, 'completo' => $completo]);
    throw new NavalErro('O seu perfil não permite exportar esta informação.', 403);
}
naval_registar('exportacao', [
    'pagina' => $pagina, 'completo' => $completo,
    'tabela' => naval_texto($p['tabela'] ?? '', 120),
    'periodo' => naval_texto($p['periodo'] ?? '', 40),
    'linhas' => (int) ($p['linhas'] ?? 0),
]);
naval_json(['ok' => true]);
