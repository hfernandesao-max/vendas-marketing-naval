<?php
/**
 * Ficheiros de dados fixos do portal (rotas, plano de cargas e carteira antiga), com as permissões
 * do utilizador: só as suas rotas e NIF só para quem o pode ver. Os .json desta pasta não são
 * acessíveis directamente (ver .htaccess).
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('rotas');

$f = (string) ($_GET['f'] ?? '');
$regras = [
    'rotas' => ['pagina-principal'],
    'cargas' => ['stock-carrinha', 'stock-armazem', 'inicio'],
    'clientes' => ['clientes', 'plano-visitas', 'auditoria'],
];
if (!isset($regras[$f])) throw new NavalErro('Ficheiro desconhecido.');
naval_exigir($regras[$f]);
$d = naval_json_ler(naval_raiz() . '/reports/data/' . $f . '.json');

// Plano de cargas: os dias publicados no portal juntam-se ao histórico (e substituem o mesmo dia e rota)
if ($f === 'cargas') {
    naval_servico('cargas');
    $porChave = [];
    foreach ($d as $x) $porChave[$x['chave'] ?? (($x['data'] ?? '') . '|' . ($x['rota'] ?? ''))] = $x;
    foreach (naval_plano_para_cargas() as $x) $porChave[$x['chave']] = $x;
    $d = array_values($porChave);
}
if ($f !== 'rotas') $d = naval_filtrar_rotas($d);
if ($f === 'clientes' && !naval_ve_nif()) {
    foreach ($d as $i => $x) $d[$i]['nif'] = '';
}
naval_json($d);
