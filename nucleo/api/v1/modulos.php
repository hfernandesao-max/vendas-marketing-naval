<?php
/**
 * Administração › Módulos (só o super administrador): fichas dos módulos, estado (ligado, piloto, desligado) e
 * conectores. Ver nucleo/servicos/modulos.php.
 *
 * GET:  módulos e conectores
 * POST {id, estado: ligado|piloto|desligado, motivo, csrf, confirmacao} com confirmação da identidade (palavra-passe
 *       ou código de dois passos), como na página Acessos
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado

$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
if (($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador gere os módulos do portal.', 403);
$GLOBALS['naval_api_sensivel'] = true;   // nunca em cache

if (!naval_e_post()) naval_json(['ok' => true] + naval_modulos_vista());

$p = naval_pedido();
naval_exigir_csrf($p);
$id = (string) ($p['id'] ?? '');
$estado = (string) ($p['estado'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,40}$/', $id)) throw new NavalErro('Módulo inválido.', 400);
naval_servico('gestao');
$pedirConfirmacao = naval_gestao_confirmar($eu, 'modulo_' . $estado, (string) ($p['confirmacao'] ?? ''));
if ($pedirConfirmacao) naval_json($pedirConfirmacao);
naval_json(['ok' => true, 'modulo' => naval_modulo_definir($id, $estado, (string) ($p['motivo'] ?? ''))] + naval_modulos_vista());
