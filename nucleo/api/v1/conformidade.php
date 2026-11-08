<?php
/**
 * Administração › Conformidade (nucleo/servicos/conformidade.php): prova dos controlos automáticos (registos
 * invioláveis, contas sem uso, revisão de acessos, cópias e recuperação, retenção).
 *
 * GET:  estado (recurso «conformidade»: administrador da plataforma e auditoria; o super administrador sempre)
 * GET ?verificar=1   verifica agora a cadeia dos registos
 * POST {accao: confirmar_revisao, trimestre, notas, csrf}   só o super administrador
 * POST {accao: aprovar_documento, doc, notas, csrf}         só o super administrador (SGSI, nucleo/servicos/sgsi.php)
 */
defined('NAVAL_API') || exit;
naval_servico('conformidade');
naval_servico('sgsi');
naval_exigir(['conformidade']);
$eu = (array) naval_utilizador();
$GLOBALS['naval_api_sensivel'] = true;

if (!naval_e_post()) {
    $v = naval_conf_vista();
    if (($_GET['verificar'] ?? '') === '1') $v['verificacao'] = naval_registos_verificar();
    naval_json(['ok' => true, 'pode_confirmar' => ($eu['perfil'] ?? '') === 'SADM', 'sgsi' => naval_sgsi_vista()] + $v);
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador confirma a revisão de acessos e aprova os documentos.', 403);
$accao = (string) ($p['accao'] ?? '');
if ($accao === 'confirmar_revisao') {
    naval_conf_revisao_confirmar((string) ($p['trimestre'] ?? ''), $eu, (string) ($p['notas'] ?? ''));
} elseif ($accao === 'aprovar_documento') {
    naval_sgsi_aprovar((string) ($p['doc'] ?? ''), $eu, (string) ($p['notas'] ?? ''));
} else {
    throw new NavalErro('Acção desconhecida.', 400);
}
naval_json(['ok' => true, 'pode_confirmar' => true, 'sgsi' => naval_sgsi_vista()] + naval_conf_vista());
