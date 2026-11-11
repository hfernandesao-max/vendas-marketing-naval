<?php
/**
 * Entrada rápida da app instalada pelo código QR: Face ID (ou impressão digital) num só toque, como nas apps dos
 * bancos. A página já leva o desafio da chave de acesso (menos uma ida e volta ao servidor) e pede a biometria
 * logo ao abrir, quando o telemóvel o permite. Na app nunca se pede a palavra-passe: Face ID; sem Face ID, a impressão
 * digital; se a biometria falhar, o próprio telemóvel pede o seu código de bloqueio. Regras: nucleo/servicos/app-telemovel.php.
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('app-telemovel');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$destino = (string) ($_GET['redirect'] ?? '');
if (!in_array($destino, array_values(NAVAL_APP_DESTINO), true)) $destino = '../reports/';
// equipamento: cookie da app ou, sem ele (dados do navegador limpos), o identificador guardado na própria app
$id = (string) ($_COOKIE[naval_app_cookie_nome()] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $id)) $id = (string) ($_GET['eq'] ?? '');
$eq = preg_match('/^[a-f0-9]{24}$/', $id) ? naval_app_activo($id) : null;
if ($eq && ($_COOKIE[naval_app_cookie_nome()] ?? '') !== $id) naval_app_cookie($id);

// sessão ainda aberta neste equipamento: directo à app
if ($eq && naval_autenticado() && ($_SESSION['app_equipamento'] ?? '') === $id) {
    header('Location: ' . NAVAL_APP_DESTINO[$eq['app']]);
    exit;
}
// sem a chave do telemóvel (activação interrompida há muito): a app tem de ser activada de novo pelo código QR
$semChave = $eq && !$eq['credenciais'];
if (!$eq && $id !== '') naval_app_cookie('');
$u = $eq ? (naval_utilizadores()[$eq['login']] ?? []) : [];
$pre = $eq && !$semChave ? ['equipamento' => $id, 'opcoes' => naval_wa_opcoes_entrada($eq)] : null;
$ios = naval_app_so((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) === 'ios';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="referrer" content="no-referrer" />
  <title>Entrar · Portal RTM</title>
  <meta name="theme-color" content="#273777">
  <?= naval_app_cabeca() ?>
</head>
<body>
  <main class="caixa" id="entrar" data-destino="<?= htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') ?>">
    <?php naval_app_escrever_logo($pre !== null); ?>
<?php if (!$eq || $semChave): ?>
    <h1 data-t><?= $eq ? 'Active de novo a app' : 'App desactivada' ?></h1>
    <div class="msg erro" role="alert" data-t><?= $eq ? 'A activação deste telemóvel ficou a meio, sem o Face ID. No portal, abra «App no telemóvel» e leia um código QR novo.' : 'Esta app foi desactivada ou activada noutro equipamento. No portal, abra «App no telemóvel» e leia o código QR.' ?></div>
<?php else: ?>
    <h1><?= htmlspecialchars(NAVAL_APP_NOMES[$eq['app']], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="quem"><?= htmlspecialchars((string) ($u['nome'] ?? $eq['login']), ENT_QUOTES, 'UTF-8') ?></p>
    <div class="msg erro" role="alert" id="ent-erro" hidden></div>
    <div class="bio" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3H5a2 2 0 0 0-2 2v2M17 3h2a2 2 0 0 1 2 2v2M7 21H5a2 2 0 0 1-2-2v-2M17 21h2a2 2 0 0 0 2-2v-2M9 9v1M15 9v1M12 9v4h-1M9 16c1.7 1.3 4.3 1.3 6 0"/></svg></div>
    <button type="button" class="botao" id="ent-bio" data-t><?= $ios ? 'Entrar com Face ID' : 'Entrar com a impressão digital' ?></button>
    <p class="validade" data-t>Se a biometria falhar, o telemóvel pede o seu código de bloqueio.</p>
    <?php naval_app_escrever_json('ent-opcoes', $pre); ?>
<?php endif; ?>
  </main>
  <?= naval_app_scripts() ?>
  <script>
    (function () {
      var o = document.getElementById('ent-opcoes');
      if (!o) return;
      var pre = JSON.parse(o.textContent);
      var m = document.getElementById('entrar');
      var b = document.getElementById('ent-bio');
      var tr = function (s) {
        return window.loginT ? window.loginT(s) : s;
      };
      NavalApp.guardarEquipamento(pre.equipamento);
      var aPedir = false;
      function entrar(auto) {
        if (aPedir) return;
        aPedir = true;
        b.disabled = true;
        document.getElementById('app-logo').classList.remove('parado');
        // o desafio vem na página; se já foi usado (segunda tentativa), pede outro
        var usar = pre;
        pre = null;
        NavalApp.entrar(usar).then(function (r) {
          aPedir = false;
          b.disabled = false;
          if (r.ok) return location.replace(r.destino || m.dataset.destino);
          if (r.desactivada || r.sem_chave) return location.reload(); // a página explica o que fazer
          document.getElementById('app-logo').classList.add('parado');
          if (r.cancelado && auto) {
            pre = usar; // o telemóvel não deixou pedir sem toque: o mesmo desafio serve para o botão
            return;
          }
          if (!r.cancelado) {
            var e = document.getElementById('ent-erro');
            e.textContent = tr(r.erro || 'Sem resposta do servidor.');
            e.hidden = false;
          }
        });
      }
      b.addEventListener('click', function () {
        entrar(false);
      });
      // como nas apps dos bancos: a biometria aparece logo ao abrir (quando o telemóvel o permite)
      entrar(true);
    })();
  </script>
</body>
</html>
