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
        $erro = 'Utilizador ou palavra-passe incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login - MobilidadeNaval</title>
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
  </style>
</head>
<body>

  <div class="login-container">
    <div class="login-header">
      <img src="imgs/naval.png" alt="Logótipo Naval" class="brand-logo" />
      <h1>MobilidadeNaval<span>Naval</span></h1>
    </div>

    <?php if (!empty($erro)): ?>
      <div class="error-alert">
        <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
        <span><?= htmlspecialchars($erro) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>" />
      <div class="form-group">
        <label for="usuario">Utilizador</label>
        <div class="input-wrapper">
          <i data-lucide="user" style="width: 18px; height: 18px;"></i>
          <input type="text" id="usuario" name="usuario" placeholder="Insira o seu utilizador" required autofocus />
        </div>
      </div>

      <div class="form-group">
        <label for="senha">Palavra-passe</label>
        <div class="input-wrapper">
          <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
          <input type="password" id="senha" name="senha" placeholder="••••••••" required />
        </div>
      </div>

      <button type="submit" class="btn-submit">
        <span>Entrar</span>
        <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
      </button>

      <div style="text-align: center; margin-top: 1.25rem;">
        <a href="index.php" style="display: inline-flex; align-items: center; gap: 0.4rem; color: var(--gray-300); text-decoration: none; font-size: 0.85rem; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='var(--gray-300)'">
          <i data-lucide="arrow-left" style="width: 16px; height: 16px;"></i>
          <span>Voltar à Página Inicial</span>
        </a>
      </div>
    </form>

    <div class="login-footer">
      &copy; <?= date('Y') ?> MobilidadeNaval &bull; Todos os direitos reservados.
    </div>
  </div>

  <script>
    lucide.createIcons();
  </script>
</body>
</html>
