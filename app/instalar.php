<?php
/**
 * Instalar a app no telemóvel (aberta pela câmara ao ler o código QR do portal). Reconhece o sistema:
 *  - Android: botão «Instalar a app» (um toque);
 *  - iPhone/iPad: a Apple não deixa um site instalar apps sozinho; mostra os toques Partilhar › Adicionar ao ecrã principal.
 * O manifesto desta página (manifesto.php) leva o código: a app instalada abre em activar.php, que a activa neste
 * equipamento. Sem código válido não há instalação (é a única forma de instalar a app).
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$codigo = (string) ($_GET['c'] ?? '');
$c = naval_app_codigo($codigo);
$u = $c ? (naval_utilizadores()[$c['login']] ?? null) : null;
$nomeApp = $c ? NAVAL_APP_NOMES[$c['app']] : '';
$so = naval_app_so((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="referrer" content="no-referrer" />
  <title>Instalar a app · Portal RTM</title>
<?php if ($c): ?>
  <link rel="manifest" href="manifesto.php?c=<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="theme-color" content="#273777">
  <link rel="apple-touch-icon" sizes="180x180" href="icones/apple-touch-icon-180.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($nomeApp, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
<?php endif; ?>
  <?= naval_app_cabeca() ?>
</head>
<body>
  <main class="caixa" id="instalar" data-codigo="<?= htmlspecialchars($c ? $codigo : '', ENT_QUOTES, 'UTF-8') ?>">
    <?= naval_app_logo(false) ?>
<?php if (!$c): ?>
    <h1 data-t>Código expirado</h1>
    <div class="msg erro" role="alert" data-t id="inst-invalido">Este código QR expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.</div>
    <p class="nota" data-t>A app só se instala pelo código QR pessoal do portal: assim fica registado quem a instalou e em que equipamento.</p>
<?php else: ?>
    <h1><span data-t>Instalar a app</span> «<?= htmlspecialchars($nomeApp, ENT_QUOTES, 'UTF-8') ?>»</h1>
    <p class="quem"><?= htmlspecialchars((string) ($u['nome'] ?? $c['login']), ENT_QUOTES, 'UTF-8') ?></p>

    <section id="inst-ios"<?= $so === 'ios' ? '' : ' hidden' ?>>
      <div class="msg erro" id="inst-safari" hidden data-t>Abra este endereço no Safari: só o Safari consegue instalar a app no iPhone.</div>
      <ol class="passos">
        <li><span><span data-t>Toque em Partilhar</span> <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg> <span data-t>na barra do Safari (se não aparecer, toque primeiro em ⋯).</span></span></li>
        <li><span data-t>Escolha «Adicionar ao ecrã principal».</span></li>
        <li><span data-t>Deixe ligado «Abrir como app web» e toque em «Adicionar».</span></li>
        <li><span data-t>Abra a app pelo ícone no ecrã principal: fica activada neste iPhone e pede o Face ID.</span></li>
      </ol>
    </section>

    <section id="inst-android"<?= $so === 'android' ? '' : ' hidden' ?>>
      <p class="nota" data-t>Toque em «Instalar a app». Depois, abra-a pelo ícone: fica activada neste telemóvel e pede a biometria.</p>
      <button type="button" class="botao" id="inst-botao" data-t>Instalar a app</button>
      <ol class="passos" id="inst-menu" hidden>
        <li><span data-t>Abra o menu ⋮ do navegador.</span></li>
        <li><span data-t>Escolha «Instalar aplicação» ou «Adicionar ao ecrã principal».</span></li>
        <li><span data-t>Abra a app pelo ícone no ecrã principal.</span></li>
      </ol>
      <div class="msg ok" id="inst-feito" hidden data-t>App instalada. Abra-a pelo ícone no ecrã principal para a activar.</div>
    </section>

    <section id="inst-outro"<?= $so === 'outro' ? '' : ' hidden' ?>>
      <p class="nota" data-t>Abra esta página no telemóvel: leia o código QR do portal com a câmara do telemóvel.</p>
    </section>

    <p class="validade"><span data-t>Código válido até</span> <?= htmlspecialchars(date('H:i', (int) $c['ate']), ENT_QUOTES, 'UTF-8') ?> · <span data-t>uso único</span></p>
<?php endif; ?>
  </main>
  <?= naval_app_scripts() ?>
  <script>
    (function () {
      var m = document.getElementById('instalar');
      var c = m.dataset.codigo;
      if (!c) return;
      // aberta já como app (o iPhone pode abrir a página onde foi adicionada): segue para a activação
      if (NavalApp.instalada()) {
        location.replace('activar.php?c=' + encodeURIComponent(c));
        return;
      }
      // o servidor já mostrou os passos do sistema; o iPad recente identifica-se como Mac e corrige-se aqui
      var so = NavalApp.so();
      ['inst-ios', 'inst-android', 'inst-outro'].forEach(function (id) {
        document.getElementById(id).hidden = id !== 'inst-' + so;
      });
      if (so === 'ios' && /CriOS|FxiOS|EdgiOS|OPiOS/.test(navigator.userAgent)) document.getElementById('inst-safari').hidden = false;
      if (so !== 'android') return;
      // Android: o service worker (página «Sem ligação») regista-se depois de a página aparecer
      window.addEventListener('load', function () {
        var r = document.createElement('script');
        r.src = 'registar.js';
        document.body.appendChild(r);
      });
      var pedido = null;
      var botao = document.getElementById('inst-botao');
      window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        pedido = e;
      });
      window.addEventListener('appinstalled', function () {
        botao.hidden = true;
        document.getElementById('inst-feito').hidden = false;
      });
      botao.addEventListener('click', function () {
        if (!pedido) {
          // navegador sem instalação directa (ou já a preparar): passos pelo menu
          document.getElementById('inst-menu').hidden = false;
          return;
        }
        pedido.prompt();
        pedido.userChoice.then(function (r) {
          if (r.outcome === 'accepted') {
            botao.hidden = true;
            document.getElementById('inst-feito').hidden = false;
          }
          pedido = null;
        });
      });
    })();
  </script>
</body>
</html>
