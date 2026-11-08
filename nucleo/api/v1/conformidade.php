<?php
/**
 * Administração › Conformidade (nucleo/servicos/conformidade.php): prova dos controlos automáticos (registos
 * invioláveis, contas sem uso, revisão de acessos, cópias e recuperação, retenção).
 *
 * GET:  estado (recurso «conformidade»: administrador da plataforma e auditoria; o super administrador sempre)
 * GET ?verificar=1   verifica agora a cadeia dos registos
 * POST {accao: confirmar_revisao, trimestre, notas, csrf}   só o super administrador
 */
defined('NAVAL_API') || exit;
naval_servico('conformidade');
naval_exigir(['conformidade']);
$eu = (array) naval_utilizador();
$GLOBALS['naval_api_sensivel'] = true;

if (!naval_e_post()) {
    $v = naval_conf_vista();
    if (($_GET['verificar'] ?? '') === '1') $v['verificacao'] = naval_registos_verificar();
    naval_json(['ok' => true, 'pode_confirmar' => ($eu['perfil'] ?? '') === 'SADM'] + $v);
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador confirma a revisão de acessos.', 403);
if (($p['accao'] ?? '') !== 'confirmar_revisao') throw new NavalErro('Acção desconhecida.', 400);
naval_conf_revisao_confirmar((string) ($p['trimestre'] ?? ''), $eu, (string) ($p['notas'] ?? ''));
naval_json(['ok' => true, 'pode_confirmar' => true] + naval_conf_vista());
