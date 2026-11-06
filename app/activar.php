<?php
/**
 * Início da app instalada (start_url do manifesto, com o código QR). Na primeira abertura activa a app neste
 * equipamento e cria a chave de acesso do telemóvel (obrigatória): Face ID; sem Face ID, a impressão digital; sem
 * biometria, o código de bloqueio do telemóvel. Na app nunca se pede a palavra-passe. Nas aberturas seguintes segue
 * para a app (sessão aberta) ou para a entrada rápida com a biometria.
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$codigo = (string) ($_GET['c'] ?? '');
// sessão da app ainda aberta neste equipamento: directo à app
$eq = naval_autenticado() && !empty($_SESSION['app_equipamento']) ? naval_app_activo((string) $_SESSION['app_equipamento']) : null;
if ($eq && $eq['credenciais']) {
    header('Location: ' . NAVAL_APP_DESTINO[$eq['app']]);
    exit;
}
// sessão da app aberta mas sem a chave do telemóvel (activação interrompida): termina a activação da biometria
$pendente = $eq ? ['destino' => NAVAL_APP_DESTINO[$eq['app']], 'pessoa' => (string) ((naval_utilizador() ?: [])['nome'] ?? $eq['login'])] : null;
$c = naval_app_codigo($codigo);
// código já usado (a app abre sempre por aqui): directo à entrada rápida com a biometria, sem passar pelo navegador
$reg = $c ? null : naval_app_codigo_registo($codigo);
if (!$pendente && !$c && $reg && preg_match('/^[a-f0-9]{24}$/', (string) ($_COOKIE['naval_eq'] ?? ''))) {
    header('Location: entrar.php?redirect=' . rawurlencode(NAVAL_APP_DESTINO[$reg['app']] ?? '../reports/'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="referrer" content="no-referrer" />
  <title>Activar a app · Portal RTM</title>
  <meta name="theme-color" content="#273777">
  <?= naval_app_cabeca() ?>
</head>
<body>
  <main class="caixa" id="activar" data-codigo="<?= htmlspecialchars($c ? $codigo : '', ENT_QUOTES, 'UTF-8') ?>" data-pendente="<?= htmlspecialchars($pendente ? (string) json_encode($pendente, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '', ENT_QUOTES, 'UTF-8') ?>">
    <?= naval_app_logo(true) ?>
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
    </section>

    <section id="act-sem-bio" hidden>
      <p class="nota" data-t>A app entra só com o Face ID, a impressão digital ou o código de bloqueio do telemóvel, e este telemóvel não tem nenhum activo.</p>
      <ol class="passos">
        <li><span data-t>Abra as Definições do telemóvel e active o Face ID, a impressão digital ou, pelo menos, o código de bloqueio.</span></li>
        <li><span data-t>Volte a abrir a app pelo ícone (o código QR continua válido durante 10 minutos).</span></li>
      </ol>
      <button type="button" class="botao" id="act-repetir" data-t>Já activei: tentar de novo</button>
    </section>
  </main>
  <?= naval_app_scripts() ?>
  <script src="registar.js" defer></script>
  <script>
    (function () {
      var tr = function (s) {
        return window.loginT ? window.loginT(s) : s;
      };
      var $ = function (id) {
        return document.getElementById(id);
      };
      var c = $('activar').dataset.codigo;
      var opcoes = null;
      var destino = '../reports/';
      try {
        destino = localStorage.getItem('naval-app-destino') || destino;
      } catch (e) {}
      function pronto() {
        $('app-logo').classList.add('parado');
      }
      function erro(t) {
        pronto();
        $('act-titulo').textContent = tr('Não foi possível activar a app');
        $('act-erro').textContent = tr(t);
        $('act-erro').hidden = false;
      }
      function seguir() {
        location.replace(destino);
      }
      function pedirBiometria(pessoa) {
        $('act-titulo').textContent = tr('App activada');
        $('act-quem').textContent = pessoa;
        var nome = NavalApp.nomeBiometria();
        $('act-bio-t').textContent = tr(
          nome === 'Face ID'
            ? 'Último passo: active o Face ID. A app entra sempre assim, sem palavra-passe (se o Face ID falhar, o telemóvel pede o seu código de bloqueio).'
            : 'Último passo: active a impressão digital. A app entra sempre assim, sem palavra-passe (se falhar, o telemóvel pede o seu código de bloqueio).',
        );
        $('act-bio-b').textContent = tr(nome === 'Face ID' ? 'Activar o Face ID' : 'Activar a impressão digital');
        $('act-bio').hidden = false;
        pronto();
      }
      var pend = $('activar').dataset.pendente;
      if (pend) {
        // activação interrompida antes do Face ID: termina-a (a app não abre sem a chave do telemóvel)
        pend = JSON.parse(pend);
        destino = pend.destino;
        pedirBiometria(pend.pessoa);
        return ligar();
      }
      if (!c) {
        // código já usado (a app abre sempre por aqui): entrada rápida com a biometria deste equipamento
        if (NavalApp.equipamento()) location.replace('entrar.php?redirect=' + encodeURIComponent(destino) + '&eq=' + NavalApp.equipamento());
        else erro('Este código QR expirou ou já foi usado. No portal, abra «App no telemóvel» e leia um código novo.');
        return;
      }
      if (!NavalApp.instalada()) {
        pronto();
        $('act-titulo').textContent = tr('Abra a app pelo ícone');
        $('act-navegador').hidden = false;
        return;
      }
      // primeiro confirma que o telemóvel tem Face ID, impressão digital ou código de bloqueio: sem isso não activa
      // (o código QR não se gasta e serve depois de activar a protecção nas Definições)
      function activar() {
        $('act-sem-bio').hidden = true;
        NavalApp.temBiometria().then(function (tem) {
          if (!tem) {
            pronto();
            $('act-titulo').textContent = tr('Active a protecção do telemóvel');
            $('act-sem-bio').hidden = false;
            return;
          }
          NavalApp.pedir({ f: 'activar', codigo: c }).then(function (r) {
            if (!r.ok) return erro(r.erro || 'Sem resposta do servidor.');
            NavalApp.guardarEquipamento(r.equipamento);
            destino = r.destino;
            opcoes = r.opcoes;
            try {
              localStorage.setItem('naval-app-destino', destino);
            } catch (e) {}
            pedirBiometria(r.pessoa);
          });
        });
      }
      activar();
      $('act-repetir').addEventListener('click', activar);
      ligar();
      function ligar() {
      $('act-bio-b').addEventListener('click', function () {
        $('act-bio-b').disabled = true;
        NavalApp.registar(opcoes).then(function (r) {
          $('act-bio-b').disabled = false;
          if (r.ok) return seguir();
          $('act-erro').textContent = tr(r.erro || 'A biometria não foi activada.');
          $('act-erro').hidden = false;
        });
      });
      }
    })();
  </script>
</body>
</html>
