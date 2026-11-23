<?php
/**
 * Inventário dos armazéns (nucleo/servicos/inventario.php), para a app Naval Operações.
 * Contar: «conciliacao-carrinha» G; validar: «requisicoes-validacao» G; aprovar: «conciliacao-fecho» G.
 *
 * GET  v1/inventario                         {pode, motivos, lista (60 dias)}
 * GET  v1/inventario?armazens=1              armazéns físicos (sem as carrinhas)
 * GET  v1/inventario?barras=1                {código de barras: código do artigo} (Pinguim BO)
 * GET  v1/inventario?numero=INV-AAAA-NNNNNN  o inventário; em contagem, às cegas (sem o stock do sistema)
 * POST {accao: criar, armazem} · {accao: gravar|terminar, numero, contagem: {codigo: q}, extra: {codigo: nome}}
 *      {accao: validar, numero, motivos: {codigo: {motivo, nota}}, recontar: [codigo]} · {accao: aprovar, numero}
 *      {accao: anular, numero, motivo}                                        (todos com csrf)
 */
defined('NAVAL_API') || exit;
naval_servico('inventario');
$pode = naval_inv_pode();
if (!$pode['contar'] && !$pode['validar'] && !$pode['aprovar']) throw new NavalErro('Sem permissão para os inventários.', 403);
$eu = (string) ((naval_utilizador() ?: [])['login'] ?? '');

/** O inventário para quem o pede: em contagem fica sempre às cegas (também para quem valida, até terminar). */
$vista = function (array $inv) use ($pode) {
    $cega = $inv['estado'] === 'em_contagem' || (!$pode['validar'] && !$pode['aprovar']);
    $saida = array_diff_key($inv, ['base' => 1, 'contagem' => 1, 'extra' => 1]);
    return ['ok' => true, 'pode' => $pode, 'motivos' => NAVAL_INV_MOTIVOS, 'inventario' => $saida + ['linhas' => naval_inv_linhas($inv, $cega), 'cega' => $cega]
        + ($cega ? [] : ['resumo' => naval_inv_resumo($inv)])];
};

if (!naval_e_post()) {
    if (($_GET['armazens'] ?? '') === '1') {
        @set_time_limit(120);
        naval_json(['ok' => true, 'armazens' => naval_inv_armazens()]);
    }
    if (($_GET['barras'] ?? '') === '1') naval_json(['ok' => true, 'barras' => (object) naval_inv_codigos_barras()]);
    if (isset($_GET['numero'])) naval_json($vista(naval_inv_ler((string) $_GET['numero'])));
    naval_json(['ok' => true, 'pode' => $pode, 'eu' => $eu, 'motivos' => NAVAL_INV_MOTIVOS, 'lista' => naval_inv_lista()]);
}

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
$numero = (string) ($p['numero'] ?? '');
switch ($accao) {
    case 'criar':
        if (!$pode['contar']) throw new NavalErro('O seu perfil não permite contar armazéns.', 403);
        @set_time_limit(120);
        naval_json($vista(naval_inv_criar((string) ($p['armazem'] ?? ''), $eu)));
        break;
    case 'gravar':
    case 'terminar':
        if (!$pode['contar']) throw new NavalErro('O seu perfil não permite contar armazéns.', 403);
        naval_json($vista(naval_inv_contar($numero, (array) ($p['contagem'] ?? []), (array) ($p['extra'] ?? []), $eu, $accao === 'terminar')));
        break;
    case 'validar':
        if (!$pode['validar']) throw new NavalErro('O seu perfil não permite validar inventários.', 403);
        naval_json($vista(naval_inv_validar($numero, $eu, (array) ($p['motivos'] ?? []), (array) ($p['recontar'] ?? []))));
        break;
    case 'aprovar':
        if (!$pode['aprovar']) throw new NavalErro('O seu perfil não permite aprovar inventários.', 403);
        naval_json($vista(naval_inv_aprovar($numero, $eu)));
        break;
    case 'anular':
        if (!$pode['validar'] && !$pode['aprovar']) throw new NavalErro('Só o controlo de stock ou o responsável anulam um inventário.', 403);
        naval_json($vista(naval_inv_anular($numero, $eu, (string) ($p['motivo'] ?? ''))));
        break;
}
throw new NavalErro('Acção desconhecida.', 400);
