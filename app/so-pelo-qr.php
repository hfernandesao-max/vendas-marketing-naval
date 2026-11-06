<?php
/** A app só funciona nos equipamentos registados pelo código QR pessoal do portal (ver app/instalar.php). */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title>Instalar pelo código QR · Portal RTM</title>
  <?= naval_app_cabeca() ?>
</head>
<body>
  <main class="caixa" id="so-qr">
    <?= naval_app_logo(false) ?>
    <h1 data-t>Instale a app pelo código QR</h1>
    <p class="nota" data-t>A app só funciona nos equipamentos registados. Para a instalar neste telemóvel:</p>
    <ol class="passos">
      <li><span data-t>Num computador, entre no portal e abra o seu nome › «App no telemóvel».</span></li>
      <li><span data-t>Leia o código QR com a câmara deste telemóvel e siga os passos.</span></li>
    </ol>
    <p class="nota" data-t>Se já tinha a app noutro telemóvel, esse deixa de funcionar: cada pessoa tem um equipamento activo.</p>
<?php if (naval_autenticado()): ?>
    <form method="post" action="../geo/auth/logout.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
      <button type="submit" class="botao sec" data-t id="so-qr-sair">Terminar sessão</button>
    </form>
<?php endif; ?>
  </main>
  <?= naval_app_scripts() ?>
</body>
</html>
