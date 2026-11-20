<?php
/**
 * Presença dos telemóveis na sala de resultados (app «Naval Sala»; nucleo/servicos/sala-presenca.php).
 *
 * POST v1/sala/presenca
 *   Cabeçalho: Authorization: Bearer <SALA_APP_CHAVE>   (sem a chave no .env, desligado: 404)
 *   Corpo: {evento: entrada|entrada_sem_disparo|saida|sem_permissao_sobreposicao, app, modelo, modo: app|kiosk,
 *           dispositivo_mdm, wifi_sala, winrest_aberto}
 *   Resposta: {ok, na_sala, kiosk_ate}
 */
defined('NAVAL_API') || exit;   // rota pública (sem sessão): autenticada pela chave própria da app da sala
naval_servico('sala-presenca');
$chave = (string) naval_config('SALA_APP_CHAVE');
if (strlen($chave) < 32) throw new NavalErro('Não encontrado.', 404);
$h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$dada = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
if ($dada === '' || !hash_equals($chave, $dada)) {
    error_log('[sala presença] chave recusada de ' . naval_ip_cliente());
    throw new NavalErro('Chave inválida.', 401);
}
naval_json(['ok' => true] + naval_sala_presenca(naval_pedido()));
