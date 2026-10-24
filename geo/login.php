<?php
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

$allowedRedirects = ['geo.php', 'reports.php', 'view/index.php', 'view/viewDist/index.php', 'index.php', '../reports/', '../'];
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '../';
if (!in_array($redirect, $allowedRedirects, true)) {
    $redirect = 'geo.php';
}

// Se já estiver autenticado, redireciona para a aplicação pretendida
if (isset($_SESSION['usuario_autenticado']) && $_SESSION['usuario_autenticado'] === true) {
    header('Location: ' . $redirect);
    exit;
}

$erro = '';
$usuarios = require __DIR__ . '/auth/config_users.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (empty($usuario) || empty($senha)) {
        $erro = 'Por favor, preencha todos os campos.';
    } elseif (isset($usuarios[$usuario]) && password_verify($senha, $usuarios[$usuario]['senha'])) {
        // Regenerar ID da sessão para prevenir Session Fixation
        session_regenerate_id(true);

        $_SESSION['usuario_autenticado'] = true;
        $_SESSION['usuario_login'] = $usuario;
        $_SESSION['usuario_nome'] = $usuarios[$usuario]['nome'];
        $_SESSION['usuario_perfil'] = $usuarios[$usuario]['perfil'];
        $_SESSION['login_time'] = time();

        header('Location: ' . $redirect);
        exit;
    } else {
        $erro = 'Utilizador ou palavra-passe incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Iniciar sessão · GeoMobilidade Naval</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: linear-gradient(135deg, var(--gray-900) 0%, #111a2e 50%, var(--gray-900) 100%);
      padding: 1.5rem;
    }
    .login-container {
      width: 100%;
      max-width: 420px;
      background: var(--sidebar-bg);
      border: 1px solid var(--gray-600);
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
      padding: 2.5rem 2rem;
      animation: fadeIn 0.3s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .login-header {
      text-align: center;
      margin-bottom: 2rem;
    }
    .login-header .brand-logo {
      display: block;
      margin: 0 auto 1.25rem;
      max-width: 140px;
      height: auto;
      object-fit: contain;
      filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.3));
    }
    .login-header h1 {
      font-size: 1.6rem;
      color: #ffffff;
      font-weight: 700;
      letter-spacing: -0.5px;
    }
    .login-header h1 span {
      font-weight: 300;
      color: var(--gray-300);
    }
    .login-header p {
      color: var(--gray-300);
      font-size: 0.85rem;
      margin-top: 0.4rem;
    }
    .form-group {
      margin-bottom: 1.25rem;
    }
    .form-group label {
      display: block;
      font-size: 0.85rem;
      color: var(--gray-300);
      margin-bottom: 0.5rem;
      font-weight: 500;
    }
    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .input-wrapper i {
      position: absolute;
      left: 0.9rem;
      color: var(--gray-400);
      pointer-events: none;
    }
    .input-wrapper input {
      width: 100%;
      padding: 0.75rem 0.9rem 0.75rem 2.6rem;
      border-radius: 8px;
      border: 1px solid var(--gray-600);
      background: var(--gray-800);
      color: #ffffff;
      font-family: inherit;
      font-size: 0.95rem;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }
    .input-wrapper input:focus {
      border-color: var(--app-blue-light);
      box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.25);
    }
    .btn-submit {
      width: 100%;
      padding: 0.85rem;
      margin-top: 0.5rem;
      background: var(--app-blue);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 1rem;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      transition: background 0.2s, transform 0.1s;
    }
    .btn-submit:hover {
      background: var(--app-blue-light);
    }
    .btn-submit:active {
      transform: scale(0.99);
    }
    .error-alert {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.75rem 1rem;
      background: rgba(153, 27, 27, 0.25);
      border: 1px solid var(--error);
      color: #fca5a5;
      border-radius: 8px;
      margin-bottom: 1.25rem;
      font-size: 0.85rem;
    }
    .login-footer {
      text-align: center;
      margin-top: 1.5rem;
      font-size: 0.75rem;
      color: var(--gray-400);
    }

    /* Identidade visual do acesso ao Portal Naval. */
    body {
      min-height: 100svh;
      padding: 32px 20px;
      background:
        radial-gradient(circle at 50% -20%, rgba(35, 74, 146, .38), transparent 38rem),
        radial-gradient(circle at 8% 0%, rgba(28, 72, 145, .22), transparent 30rem),
        #060d20;
      color: #f5f7ff;
      overflow: hidden;
    }

    body::before,
    body::after {
      content: '';
      position: fixed;
      pointer-events: none;
      z-index: -1;
      border-radius: 50%;
      filter: blur(90px);
      opacity: .26;
    }

    body::before { width: 340px; height: 340px; top: -150px; left: -110px; background: #1c5bd2; }
    body::after { width: 300px; height: 300px; right: -130px; bottom: -160px; background: #0d3a90; }

    .login-container {
      width: min(100%, 400px);
      max-width: 400px;
      padding: 38px 30px 27px;
      border: 1px solid rgba(115, 147, 207, .2);
      border-radius: 22px;
      background: linear-gradient(150deg, rgba(23, 39, 78, .91), rgba(13, 25, 54, .94));
      box-shadow: 0 28px 64px rgba(0, 0, 0, .4), inset 0 1px 0 rgba(255, 255, 255, .035);
      animation: login-enter .5s cubic-bezier(.2, .8, .2, 1);
      backdrop-filter: blur(16px);
    }

    @keyframes login-enter {
      from { opacity: 0; transform: translateY(16px) scale(.985); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .login-header { margin-bottom: 30px; }

    .brand-emblem {
      width: 72px;
      height: 72px;
      display: grid;
      place-items: center;
      margin: 0 auto 10px;
      border: 2px solid rgba(96, 151, 255, .55);
      border-radius: 50%;
      background:
        repeating-radial-gradient(ellipse at center 64%, transparent 0 7px, rgba(57, 137, 239, .95) 8px 12px, transparent 13px 18px),
        linear-gradient(135deg, #172755, #10214c);
      box-shadow: inset 0 0 18px rgba(57, 137, 239, .3), 0 8px 23px rgba(0, 0, 0, .3);
      overflow: hidden;
    }

    .brand-emblem span {
      width: 54px;
      height: 54px;
      border: 2px solid rgba(116, 161, 255, .72);
      border-radius: 50%;
      opacity: .8;
    }

    .brand-name {
      margin: 0;
      color: #fff;
      font-size: 24px;
      font-weight: 700;
      letter-spacing: .24em;
      text-indent: .24em;
    }

    .login-header h1 {
      margin-top: 12px;
      color: #f7f9ff;
      font-size: 23px;
      font-weight: 400;
      letter-spacing: -.05em;
    }

    .login-header h1 strong { font-weight: 700; }
    .login-header h1 span { color: #c8d0e6; font-weight: 300; }

    .module-label {
      margin: 7px 0 0;
      color: #45adf0;
      font-size: 10px;
      font-weight: 700;
      letter-spacing: .18em;
      text-transform: uppercase;
    }

    .form-group { margin-bottom: 17px; }
    .form-group label { margin-bottom: 7px; color: #d3d9e9; font-size: 13px; font-weight: 400; }
    .input-wrapper input {
      height: 52px;
      padding: 0 46px 0 44px;
      border: 1px solid rgba(126, 151, 204, .24);
      border-radius: 13px;
      background: rgba(6, 14, 34, .57);
      color: #fff;
      font-size: 14px;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, .025);
    }
    .input-wrapper input::placeholder { color: #737e9e; }
    .input-wrapper input:focus {
      border-color: #3677ef;
      box-shadow: 0 0 0 3px rgba(51, 113, 237, .18), inset 0 1px 0 rgba(255, 255, 255, .025);
    }
    .input-wrapper > i { left: 16px; color: #aeb9d0; }

    .password-toggle {
      position: absolute;
      right: 12px;
      display: grid;
      width: 30px;
      height: 30px;
      place-items: center;
      border: 0;
      border-radius: 8px;
      background: transparent;
      color: #9da9c3;
      cursor: pointer;
    }
    .password-toggle:hover, .password-toggle:focus-visible { color: #fff; background: rgba(255, 255, 255, .07); outline: none; }

    .btn-submit {
      min-height: 52px;
      margin-top: 18px;
      border: 1px solid rgba(112, 157, 255, .5);
      border-radius: 12px;
      background: linear-gradient(105deg, #2469ec, #3976ee);
      box-shadow: 0 11px 22px rgba(28, 93, 224, .27), inset 0 1px 0 rgba(255, 255, 255, .18);
      font-size: 15px;
    }
    .btn-submit:hover { background: linear-gradient(105deg, #3076fb, #4a82f4); box-shadow: 0 14px 28px rgba(28, 93, 224, .38); transform: translateY(-1px); }

    .return-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 19px;
      color: #c9d0e0;
      text-decoration: none;
      font-size: 13px;
      transition: color .18s ease;
    }
    .return-link:hover { color: #fff; }

    .login-footer {
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid rgba(125, 146, 191, .16);
      color: #7b86a2;
      font-size: 11px;
      line-height: 1.55;
    }
    .error-alert { border-color: rgba(255, 113, 113, .48); background: rgba(130, 29, 45, .3); }

    @media (max-width: 480px) {
      body { padding: 18px; overflow: auto; }
      .login-container { padding: 32px 22px 24px; border-radius: 18px; }
    }
  </style>
</head>
<body>

  <div class="login-container">
    <div class="login-header">
      <div class="brand-emblem" aria-hidden="true"><span></span></div>
      <p class="brand-name">NAVAL</p>
      <h1><strong>GeoMobilidade</strong><span>Naval</span></h1>
      <p class="module-label">Módulo operacional</p>
    </div>

    <?php if (!empty($erro)): ?>
      <div class="error-alert">
        <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
        <span><?= htmlspecialchars($erro) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>" />
      <div class="form-group">
        <label for="usuario">Utilizador</label>
        <div class="input-wrapper">
          <i data-lucide="user" style="width: 18px; height: 18px;"></i>
          <input type="text" id="usuario" name="usuario" placeholder="Insira o seu utilizador" autocomplete="username" required autofocus />
        </div>
      </div>

      <div class="form-group">
        <label for="senha">Palavra-passe</label>
        <div class="input-wrapper">
          <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
          <input type="password" id="senha" name="senha" placeholder="Insira a sua palavra-passe" autocomplete="current-password" required />
          <button class="password-toggle" type="button" id="toggle-password" aria-label="Mostrar palavra-passe">
            <i data-lucide="eye" style="width: 17px; height: 17px;"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-submit">
        <span>Entrar</span>
        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
      </button>

      <div style="text-align: center;">
        <a href="../" class="return-link">
          <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
          <span>Voltar à página inicial</span>
        </a>
      </div>
    </form>

    <div class="login-footer">
      &copy; <?= date('Y') ?> GeoMobilidade Naval &bull; Todos os direitos reservados.
    </div>
  </div>

  <script>
    lucide.createIcons();
    const password = document.getElementById('senha');
    const togglePassword = document.getElementById('toggle-password');
    togglePassword.addEventListener('click', () => {
      const reveal = password.type === 'password';
      password.type = reveal ? 'text' : 'password';
      togglePassword.setAttribute('aria-label', reveal ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe');
      togglePassword.innerHTML = `<i data-lucide="${reveal ? 'eye-off' : 'eye'}" style="width: 17px; height: 17px;"></i>`;
      lucide.createIcons();
    });
  </script>
</body>
</html>
