<?php
/**
 * Espelho da apresentação (nucleo/servicos/espelho.php): a app de gestão no ecrã do computador e no telemóvel da
 * mesma conta mostram o mesmo ecrã enquanto o modo apresentação estiver activo. Recurso «modo-apresentacao».
 *
 * GET  v1/espelho?seq=N              {activo, ate, seq[, origem, estado]} (o estado só quando mudou desde N)
 * POST {accao: estado, cliente, estado, csrf}   grava o ecrã de quem conduz → {seq}
 */
defined('NAVAL_API') || exit;
naval_exigir(['modo-apresentacao']);
naval_servico('espelho');
$login = (string) (((array) naval_utilizador())['login'] ?? '');
if ($login === '') throw new NavalErro('Sessão sem conta.', 401);

if (!naval_e_post()) {
    $d = naval_espelho_ler($login);
    $activo = (int) $d['ate'] > time();
    // leitura feita pela app no telemóvel (equipamento registado): o computador sabe que o telemóvel já está a seguir
    if ($activo && !empty($_SESSION['app_equipamento'])) naval_espelho_marcar_telemovel($login);
    $r = ['ok' => true, 'activo' => $activo, 'ate' => $activo ? (int) $d['ate'] : null, 'seq' => (int) $d['seq']];
    if ($activo && (int) ($_GET['seq'] ?? -1) !== (int) $d['seq']) $r += ['origem' => (string) $d['origem'], 'estado' => $d['estado']];
    header('Cache-Control: no-store');
    naval_json($r);
}
$p = naval_pedido();
naval_exigir_csrf($p);
if (($p['accao'] ?? '') !== 'estado') throw new NavalErro('Acção desconhecida.', 400);
naval_json(['ok' => true, 'seq' => naval_espelho_gravar($login, (string) ($p['cliente'] ?? ''), $p['estado'] ?? null)]);
