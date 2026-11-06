<?php
/**
 * Início da app instalada (start_url do manifesto, com o código QR). Na primeira abertura activa a app neste
 * equipamento (abre a sessão) e pede o Face ID ou a impressão digital; nas seguintes segue para a app (com a sessão
 * aberta) ou para a entrada com a biometria.
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$codigo = (string) ($_GET['c'] ?? '');
// sessão da app ainda aberta neste equipamento: directo à app
$eq = naval_autenticado() && !empty($_SESSION['app_equipamento']) ? naval_app_activo((string) $_SESSION['app_equipamento']) : null;
if ($eq) {
    header('Location: ' . NAVAL_APP_DESTINO[$eq['app']]);
    exit;
}
$c = naval_app_codigo($codigo);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="referrer" content="no-referrer" />
  <title>Activar a app · Portal RTM</title>
  <meta name="theme-color" content="#273777">
  <link rel="stylesheet" href="../assets/fontes/inter.css" />
  <link rel="stylesheet" href="app.css" />
  <script src="../reports/js/tema-arranque.js"></script>
</head>
<body>
  <main class="caixa" id="activar" data-codigo="<?= htmlspecialchars($c ? $codigo : '', ENT_QUOTES, 'UTF-8') ?>">
    <img class="logo" src="icones/icone-192.png" alt="Grupo Naval" width="64" height="64" />
    <h1 id="act-titulo" data-t>A activar a app…</h1>
    <p class="quem" id="act-quem"></p>
    <div class="msg erro" role="alert" id="act-erro" hidden></div>

    <section id="act-navegador" hidden>
      <p class="nota" data-t>A app activa-se ao abri-la pelo ícone no ecrã principal, não no navegador.</p>
    </section>

    <section id="act-bio" hidden>
      <div class="bio" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3H5a2 2 0 0 0-2 2v2M17 3h2a2 2 0 0 1 2 2v2M7 21H5a2 2 0 0 1-2-2v-2M17 21h2a2 2 0 0 0 2-2v-2M9 9v1M15 9v1M12 9v4h-1M9 16c1.7 1.3 4.3 1.3 6 0"/></svg></div>
      <p class="nota" id="act-bio-t"></p>
      <button type="button" class="botao" id="act-bio-b"></button>
      <button type="button" class="botao sec" id="act-depois" data-t>Agora não</button>
    </section>

    <section id="act-sem-bio" hidden>
      <p class="nota" data-t>Este telemóvel não tem Face ID, impressão digital nem código de bloqueio activos. Nas próximas entradas use a palavra-passe e o código de verificação.</p>
      <button type="button" class="botao" id="act-continuar" data-t>Continuar</button>
    </section>
  </main>
  <script src="registar.js" defer></script>
  <script src="app.js"></script>
  <script src="../geo/login-idioma.js"></script>
  <script>
    (function () {
      var tr = function (s) {
        return window.loginT ? window.loginT(s) : s;
      };
      var $ = function (id) {
        return document.getElementById(id);
      };
      var c = $('activar').dataset.codigo;
      var destino = '../reports/';
      try {
        destino = localStorage.getItem('naval-app-destino') || destino;
      } catch (e) {}
      function erro(t) {
        $('act-titulo').textContent = tr('Não foi possível activar a app');
        $('act-erro').textContent = tr(t);
        $('act-erro').hidden = false;
      }
      function seguir() {
        location.replace(destino);
      }
      if (!c) {
        // código já usado (a app abre sempre por aqui): entrada com a biometria ou a palavra-passe deste equipamento
        if (NavalApp.equipamento()) location.replace('../geo/login.php?redirect=' + encodeURIComponent(destino));
        else erro('Este código QR expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.');
        return;
      }
      if (!NavalApp.instalada()) {
        $('act-titulo').textContent = tr('Abra a app pelo ícone');
        $('act-navegador').hidden = false;
        return;
      }
      NavalApp.pedir({ f: 'activar', codigo: c }).then(function (r) {
        if (!r.ok) return erro(r.erro || 'Sem resposta do servidor.');
        NavalApp.guardarEquipamento(r.equipamento);
        destino = r.destino;
        try {
          localStorage.setItem('naval-app-destino', destino);
        } catch (e) {}
        $('act-titulo').textContent = tr('App activada');
        $('act-quem').textContent = r.pessoa;
        NavalApp.temBiometria().then(function (tem) {
          if (!tem) {
            $('act-sem-bio').hidden = false;
            return;
          }
          var nome = NavalApp.nomeBiometria();
          $('act-bio-t').textContent = tr(nome === 'Face ID' ? 'Proteja a entrada com o Face ID: nas próximas vezes basta olhar para o telemóvel.' : 'Proteja a entrada com a impressão digital: nas próximas vezes basta tocar no leitor.');
          $('act-bio-b').textContent = tr(nome === 'Face ID' ? 'Activar o Face ID' : 'Activar a impressão digital');
          $('act-bio').hidden = false;
        });
      });
      $('act-bio-b').addEventListener('click', function () {
        $('act-bio-b').disabled = true;
        NavalApp.registar().then(function (r) {
          $('act-bio-b').disabled = false;
          if (r.ok) return seguir();
          $('act-erro').textContent = tr(r.erro || 'A biometria não foi activada.');
          $('act-erro').hidden = false;
        });
      });
      $('act-depois').addEventListener('click', seguir);
      $('act-continuar').addEventListener('click', seguir);
    })();
  </script>
</body>
</html>
