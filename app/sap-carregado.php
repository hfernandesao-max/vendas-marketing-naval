<?php
/**
 * «Já carreguei no SAP»: página aberta pela ligação do email do envio automático (nucleo/servicos/sap-envio.php).
 * Sem conta no portal: a autorização é o código de uso único da ligação (só a impressão digital está guardada, 14
 * dias). Pede o nome de quem carregou e a referência do SAP, marca o lote como carregado e avisa, no mesmo assunto,
 * quem continua o trabalho. Em inglês e português (os destinatários podem não falar português).
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
naval_servico('sap-envio');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$n = (int) ($_POST['l'] ?? $_GET['l'] ?? 0);
$codigo = (string) ($_POST['c'] ?? $_GET['c'] ?? '');
$erro = '';
$feito = null;
if (naval_e_post()) {
    try {
        if (!hash_equals(naval_csrf(), (string) ($_POST['csrf'] ?? ''))) throw new NavalErro('The page expired: open the link again. · A página expirou: abra a ligação de novo.', 403);
        $feito = naval_sap_envio_confirmar_ligacao($n, $codigo, (string) ($_POST['nome'] ?? ''), (string) ($_POST['referencia'] ?? ''));
    } catch (NavalErro $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[SAP carregado] ' . $e->getMessage());
        $erro = 'It was not possible to save. Try again. · Não foi possível guardar. Tente de novo.';
    }
}
$lote = $feito ? null : naval_sap_envio_lote_da_ligacao($n, $codigo);
if (!$feito && !$lote && !$erro) naval_registar('sap_ligacao_recusada', ['lote' => $n]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="referrer" content="no-referrer" />
  <title>Loaded into SAP · Portal RTM</title>
  <?= naval_app_cabeca() ?>
  <style>
    #sap-carregado form { display: grid; gap: 6px; margin-top: 12px; text-align: left; }
    #sap-carregado label { font-weight: 600; font-size: 14px; margin-top: 8px; }
    #sap-carregado input { font: inherit; padding: 10px 12px; border: 1px solid #C9D2E3; border-radius: 8px; width: 100%; box-sizing: border-box; }
    #sap-carregado button { margin-top: 14px; }
  </style>
</head>
<body>
  <main class="caixa" id="sap-carregado">
    <?= naval_app_logo(false) ?>
<?php if ($feito): ?>
    <h1>Thank you · Obrigado</h1>
    <p class="nota" id="sap-ok">Batch <?= htmlspecialchars((string) ($feito['numero']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> is recorded as loaded into SAP (reference <?= htmlspecialchars((string) ($feito['carregado']['referencia'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>). The team was told by email to continue.<br />
      O lote <?= htmlspecialchars((string) ($feito['numero']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> ficou registado como carregado no SAP (referência <?= htmlspecialchars((string) ($feito['carregado']['referencia'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>). A equipa foi avisada por email para continuar.</p>
<?php elseif (!$lote): ?>
    <h1>Link no longer valid · Ligação já não válida</h1>
    <?php if ($erro): ?><div class="msg erro" role="alert"><?= htmlspecialchars((string) ($erro), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <p class="nota" id="sap-invalida">The batch may already be recorded as loaded, or the link expired. If needed, record it in the portal (SAP page).<br />
      O lote pode já estar registado como carregado, ou a ligação expirou. Se for preciso, registe-o no portal (página do SAP).</p>
<?php else: ?>
    <h1>Loaded into SAP? · Carregado no SAP?</h1>
    <p class="nota"><b><?= htmlspecialchars((string) ($lote['ficheiro']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b><br />
      <?= htmlspecialchars((string) ($lote['n_documentos']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> documents / documentos · <?= htmlspecialchars((string) (number_format((float) $lote['total'], 2, ',', ' ')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> Kz</p>
    <?php if ($erro): ?><div class="msg erro" role="alert"><?= htmlspecialchars((string) ($erro), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" action="sap-carregado.php" class="form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars((string) (naval_csrf()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <input type="hidden" name="l" value="<?= htmlspecialchars((string) ($n), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <input type="hidden" name="c" value="<?= htmlspecialchars((string) ($codigo), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <label for="sap-nome">Your name · O seu nome</label>
      <input id="sap-nome" name="nome" required minlength="2" maxlength="80" autocomplete="name" value="<?= htmlspecialchars((string) ($_POST['nome'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <label for="sap-ref">SAP reference · Referência do SAP</label>
      <input id="sap-ref" name="referencia" required minlength="2" maxlength="60" value="<?= htmlspecialchars((string) ($_POST['referencia'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
      <button type="submit" class="botao" id="sap-confirmar">Confirm loaded into SAP · Confirmar carregado no SAP</button>
    </form>
<?php endif; ?>
  </main>
  <?= naval_faixa_ensaio() ?>
</body>
</html>
