<?php
/**
 * Robô do Pinguim BO (ferramentas/robo-pinguim.mjs): pede a próxima ordem e devolve o resultado. Servidor a servidor,
 * sem sessão: «Authorization: Bearer <ROBO_PINGUIM_CHAVE>» (32 caracteres ou mais; sem chave no .env, desligado: 404).
 *
 * GET  v1/robo/ordens?robo=nome          {ok, ordem: {...} | null}   reserva a ordem por 10 minutos
 * POST v1/robo/ordens {id, robo, ok: true, documento}                 documento gerado no Pinguim BO
 * POST v1/robo/ordens {id, robo, ok: false, erro, tipo_erro: negocio|tecnico}
 */
defined('NAVAL_API') || exit;   // rota pública (sem sessão): autenticada pela chave própria
naval_servico('ordens-pinguim');
$chave = (string) naval_config('ROBO_PINGUIM_CHAVE');
if (strlen($chave) < 32) throw new NavalErro('Não encontrado.', 404);
$h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$dada = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
if ($dada === '' || !hash_equals($chave, $dada)) {
    error_log('[robô Pinguim BO] chave recusada de ' . naval_ip_cliente());
    throw new NavalErro('Chave inválida.', 401);
}
$limpo = function ($s) { return substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $s), 0, 40) ?: 'robo'; };
if (!naval_e_post()) {
    $robo = 'robo:' . $limpo($_GET['robo'] ?? 'robo');
    naval_json(['ok' => true, 'ordem' => naval_ord_reclamar($robo)]);
}
$p = naval_pedido();
$robo = 'robo:' . $limpo($p['robo'] ?? 'robo');
$o = naval_ord_resultado((string) ($p['id'] ?? ''), $robo, !empty($p['ok']), (string) ($p['documento'] ?? ''), (string) ($p['erro'] ?? ''),
    ($p['tipo_erro'] ?? '') === 'negocio' ? 'negocio' : 'tecnico');
naval_registar('ordem_pinguim_robo', ['id' => $o['id'], 'estado' => $o['estado'], 'documento' => $o['documento']['numero'] ?? null], $robo);
naval_json(['ok' => true, 'estado' => $o['estado']]);
