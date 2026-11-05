<?php
/**
 * Recuperar o acesso (palavra-passe esquecida ou conta bloqueada), pela própria pessoa e sem sessão aberta.
 * Passo 1: utilizador ou correio electrónico → ligação de uso único enviada para o correio da ficha (resposta igual
 * exista ou não a conta). Passo 2: a ligação abre esta página com o código; com dois passos activos pede também o
 * código da aplicação; escolhe a palavra-passe nova. Regras e segurança: nucleo/servicos/recuperacao.php.
 */
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();
naval_servico('recuperacao');

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // o código da ligação nunca sai desta página para outro sítio

$erro = '';
$estado = 'pedir';   // pedir | enviado | definir | concluido | invalido
$codigo = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$ip = naval_ip_cliente();
$ligacao = $codigo !== '' ? naval_rec_ligacao($codigo) : null;
$comMfa = $ligacao && !empty($ligacao[1]['mfa']);

if ($codigo !== '') $estado = $ligacao ? 'definir' : 'invalido';

if (naval_e_post()) {
    if (!hash_equals(naval_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        $erro = 'A página expirou. Tente de novo.';
    } elseif ($codigo === '') {
        naval_rec_pedir((string) ($_POST['usuario'] ?? ''), $ip);
        $estado = 'enviado';   // a mesma resposta em todos os casos (não revela que contas existem)
    } elseif ($ligacao) {
        $r = naval_rec_concluir($codigo, (string) ($_POST['nova'] ?? ''), (string) ($_POST['confirmar'] ?? ''), (string) ($_POST['codigo'] ?? ''), $ip);
        if ($r === null) $estado = 'concluido';
        else {
            $erro = $r;
            $estado = naval_rec_ligacao($codigo) ? 'definir' : 'invalido';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="referrer" content="no-referrer" />
  <title>Recuperar o acesso · GeoMobilidade Naval</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <!-- Tema (Sistema / Claro / Escuro): o mesmo do portal, aplicado antes de desenhar -->
  <script src="../reports/js/tema-arranque.js"></script>
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1.5rem;
      background: radial-gradient(circle at 50% -20%, rgba(35, 74, 146, .38), transparent 38rem), #060d20; }
    .caixa { width: 100%; max-width: 440px; background: var(--sidebar-bg); border: 1px solid var(--gray-600);
      border-radius: 14px; padding: 2rem; color: #fff; }
    .logo { display: block; width: 56px; height: 56px; margin: 0 auto 12px; object-fit: contain; }
    h1 { font-size: 1.35rem; margin-bottom: .4rem; text-align: center; }
    p.nota { color: var(--gray-300); font-size: .85rem; line-height: 1.55; margin-bottom: 1.2rem; text-align: justify; }
    label { display: block; font-size: .82rem; color: var(--gray-300); margin: .9rem 0 .35rem; }
    input[type=password], input[type=text] { width: 100%; padding: .7rem .8rem; border-radius: 8px; border: 1px solid var(--gray-600);
      background: rgba(255,255,255,.04); color: #fff; font-size: .95rem; }
    input:focus-visible { outline: 2px solid #45adf0; outline-offset: 1px; }
    button, a.botao { display: block; box-sizing: border-box; margin-top: 1.4rem; width: 100%; padding: .75rem; border: 0; border-radius: 8px;
      background: #2a6fe0; color: #fff; font-weight: 600; font-size: .95rem; cursor: pointer; text-align: center; text-decoration: none; }
    .msg { padding: .75rem .9rem; border-radius: 8px; font-size: .85rem; line-height: 1.5; margin-bottom: 1rem; text-align: justify; }
    .msg.erro { background: rgba(255,69,58,.12); border: 1px solid rgba(255,69,58,.35); color: #ffb4ae; }
    .msg.ok { background: rgba(42,111,224,.15); border: 1px solid rgba(42,111,224,.45); color: #cfe0ff; }
    a.voltar { display: block; text-align: center; margin-top: 1rem; color: var(--gray-300); font-size: .85rem; }
    @font-face { font-family: 'Noto Sans Ethiopic'; font-style: normal; font-weight: 400; font-display: swap;
      src: url('../reports/css/fontes/noto-sans-ethiopic-ethiopic-400-normal.woff2') format('woff2'); unicode-range: U+1200-1399, U+2D80-2DDE, U+AB01-AB2E; }
    body, input, button { font-family: 'Noto Sans Ethiopic', 'Poppins', 'Segoe UI', Arial, sans-serif; }
    :root[data-theme='light'] { color-scheme: light; }
    :root[data-theme='light'] body { background: #eef2f8; }
    :root[data-theme='light'] .caixa { background: #fff; border-color: rgba(10, 42, 82, .12); color: #0b1f3a;
      box-shadow: 0 1px 2px rgba(10, 30, 60, .06), 0 24px 50px -26px rgba(10, 30, 60, .35); }
    :root[data-theme='light'] p.nota, :root[data-theme='light'] label, :root[data-theme='light'] a.voltar { color: #44546d; }
    :root[data-theme='light'] input[type=password], :root[data-theme='light'] input[type=text] { background: #f7f9fc; border-color: rgba(10, 42, 82, .22); color: #0b1f3a; }
    :root[data-theme='light'] button, :root[data-theme='light'] a.botao { background: #0057b8; }
    :root[data-theme='light'] .msg.erro { background: #fdecec; border-color: rgba(192, 38, 45, .4); color: #a3242a; }
    :root[data-theme='light'] .msg.ok { background: #e8f0fb; border-color: rgba(0, 87, 184, .35); color: #0b4a94; }
  </style>
</head>
<body>
  <main class="caixa">
    <img class="logo" src="../assets/naval-emblema.png" alt="Grupo Naval" width="56" height="56" />
    <h1 data-t>Recuperar o acesso</h1>
<?php if ($erro !== ''): ?>
    <div class="msg erro" role="alert" data-t><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($estado === 'enviado'): ?>
    <div class="msg ok" role="status" data-t id="rec-enviado">Se os dados corresponderem a uma conta activa, enviámos para o correio electrónico dessa conta uma ligação para escolher uma nova palavra-passe. A ligação é válida durante 30 minutos e só pode ser usada uma vez.</div>
    <p class="nota" data-t>Não recebeu? Confirme a pasta de lixo electrónico ou peça ajuda ao super administrador da plataforma.</p>
<?php elseif ($estado === 'concluido'): ?>
    <div class="msg ok" role="status" data-t id="rec-concluido">Palavra-passe alterada e conta desbloqueada. As sessões que estavam abertas foram terminadas. Entre com a nova palavra-passe.</div>
    <a class="botao" href="login.php" data-t>Iniciar sessão</a>
<?php elseif ($estado === 'invalido'): ?>
    <div class="msg erro" role="alert" data-t id="rec-invalido">A ligação expirou ou já foi usada. Peça uma nova.</div>
    <a class="botao" href="recuperar.php" data-t>Pedir uma nova ligação</a>
<?php elseif ($estado === 'definir'): ?>
    <p class="nota" data-t>Escolha a nova palavra-passe: pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. A conta fica desbloqueada e as sessões abertas terminam.</p>
    <form method="post" action="recuperar.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
      <input type="hidden" name="t" value="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>" />
<?php if ($comMfa): ?>
      <label for="codigo" data-t>Código da aplicação de autenticação</label>
      <input type="text" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus />
<?php endif; ?>
      <label for="nova" data-t>Nova palavra-passe</label>
      <input type="password" id="nova" name="nova" minlength="12" maxlength="64" autocomplete="new-password" required <?= $comMfa ? '' : 'autofocus' ?> />
      <label for="confirmar" data-t>Confirmar a nova palavra-passe</label>
      <input type="password" id="confirmar" name="confirmar" minlength="12" maxlength="64" autocomplete="new-password" required />
      <button type="submit" data-t>Guardar e desbloquear</button>
    </form>
<?php else: ?>
    <p class="nota" data-t>Esqueceu-se da palavra-passe ou a conta ficou bloqueada? Indique o seu utilizador ou correio electrónico: enviamos para o correio da conta uma ligação para escolher uma nova palavra-passe e desbloquear a conta.</p>
    <form method="post" action="recuperar.php">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
      <label for="usuario" data-t>Utilizador ou correio electrónico</label>
      <input type="text" id="usuario" name="usuario" autocomplete="username" required autofocus />
      <button type="submit" data-t>Enviar a ligação</button>
    </form>
<?php endif; ?>
    <a class="voltar" href="login.php" data-t>Voltar à página de entrada</a>
  </main>
  <!-- Idioma escolhido (PT/EN/TI), o mesmo do portal -->
  <script src="login-idioma.js"></script>
</body>
</html>
