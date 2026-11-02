<?php
/**
 * Página «Actualizações» do portal: aprovação das versões publicadas no GitHub (só o super administrador).
 * Ver nucleo/servicos/actualizacao.php («Aprovação das versões»).
 *
 * GET:  versão instalada, versões à espera de decisão (com o resumo das alterações), histórico e última execução
 * POST {accao: aprovar|reprovar|cancelar|reverter, versao, urgente, motivo, csrf, confirmacao}
 *       com confirmação da identidade (palavra-passe ou código de dois passos), como na página Acessos
 */
defined('NAVAL_API') || exit;   // só pela API central (api/index.php): token JWT já validado
naval_servico('actualizacao');

$eu = (array) naval_utilizador();
if (!$eu) throw new NavalErro('Sessão terminada.', 401);
if (($eu['perfil'] ?? '') !== 'SADM') throw new NavalErro('Só o super administrador aprova as actualizações do portal.', 403);
$GLOBALS['naval_api_sensivel'] = true;   // nunca em cache

if (!naval_e_post()) naval_json(['ok' => true] + naval_act_vista_portal());

$p = naval_pedido();
naval_exigir_csrf($p);
$accao = (string) ($p['accao'] ?? '');
if (!in_array($accao, ['aprovar', 'reprovar', 'cancelar', 'reverter'], true)) throw new NavalErro('Acção desconhecida.', 400);
naval_servico('gestao');
$pedirConfirmacao = naval_gestao_confirmar($eu, 'actualizacao_' . $accao, (string) ($p['confirmacao'] ?? ''));
if ($pedirConfirmacao) naval_json($pedirConfirmacao);

try {
    if ($accao === 'reverter') {
        naval_act_pedir_reversao((string) $eu['login']);
        naval_json(['ok' => true]);
    }
    $versao = (string) ($p['versao'] ?? '');
    if (!preg_match('/^v?[\w.\-]{1,40}$/', $versao)) throw new NavalErro('Versão inválida.', 400);
    $d = naval_act_decidir($versao, $accao, (string) $eu['login'], (string) ($p['motivo'] ?? ''), !empty($p['urgente']));
    unset($d['sha256']);
    naval_json(['ok' => true, 'decisao' => $d]);
} catch (NavalActualizacaoErro $e) {
    throw new NavalErro($e->getMessage(), 502);
}
