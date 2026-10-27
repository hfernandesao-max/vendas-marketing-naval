<?php
require_once __DIR__ . '/../nucleo/arranque.php';
naval_iniciar_sessao();

$allowedRedirects = ['geo.php', 'reports.php', 'view/index.php', 'view/viewDist/index.php', 'index.php', '../reports/', '../'];
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '../';
if (!in_array($redirect, $allowedRedirects, true)) {
    $redirect = 'geo.php';
}

// Se já estiver autenticado (sessão ainda válida), redirecciona para a aplicação pretendida
if (!empty($_SESSION['usuario_autenticado']) && naval_sessao_valida()) {
    header('Location: ' . $redirect);
    exit;
}

$erro = '';
$passo = 'senha';
$usuarios = naval_utilizadores();

// Conclui a entrada: nova sessão, perfil, registo e redireccionamento
function naval_concluir_login(string $login, array $u, string $redirect, bool $comMfa): void
{
    session_regenerate_id(true);
    unset($_SESSION['mfa_pendente'], $_SESSION['csrf']);   // novo identificador e novo código CSRF para a sessão autenticada
    $_SESSION['usuario_autenticado'] = true;
    $_SESSION['usuario_login'] = $login;
    $_SESSION['usuario_nome'] = $u['nome'] ?? $login;
    $_SESSION['usuario_perfil'] = $u['perfil'] ?? '';
    $_SESSION['login_time'] = time();
    $_SESSION['ultima_actividade'] = time();
    naval_limpar_falhas($login);
    naval_registar('entrada', ['dois_passos' => $comMfa], $login);
    if (!$comMfa && in_array($u['perfil'] ?? '', naval_perfis_mfa(), true)) naval_registar('dois_passos_em_falta', [], $login);
    header('Location: ' . $redirect);
    exit;
}

// Depois de confirmada a identidade: palavra-passe temporária obriga a escolher uma nova antes de entrar
function naval_apos_verificacao(string $login, array $u, string $redirect, bool $comMfa): void
{
    if (!empty($u['trocar_senha'])) {
        unset($_SESSION['mfa_pendente']);
        $_SESSION['troca_pendente'] = ['login' => $login, 'mfa' => $comMfa, 'ate' => time() + 600];
        return;
    }
    naval_concluir_login($login, $u, $redirect, $comMfa);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(naval_csrf(), (string) ($_POST['csrf'] ?? ''))) {
    // Protecção CSRF do formulário de entrada (evita «login CSRF»): o código vem da sessão
    $erro = 'A página de entrada expirou. Tente de novo.';
    if (!empty($_SESSION['troca_pendente'])) $passo = 'nova';
    elseif (!empty($_SESSION['mfa_pendente'])) $passo = 'mfa';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && naval_ip_suspenso($ip)) {
    // Demasiadas tentativas falhadas a partir deste endereço (força bruta): suspende durante 15 minutos
    http_response_code(429);
    header('Retry-After: ' . NAVAL_IP_JANELA);
    naval_registar('entrada_suspensa_ip', [], trim((string) ($_POST['usuario'] ?? $_SESSION['mfa_pendente'] ?? '')));
    $erro = 'Demasiadas tentativas falhadas a partir deste endereço. Aguarde 15 minutos e tente de novo.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $troca = $_SESSION['troca_pendente'] ?? null;
    if (isset($_POST['nova_senha']) && $troca && $troca['ate'] >= time()) {
        // Nova palavra-passe (obrigatória após uma palavra-passe temporária)
        $login = $troca['login'];
        $u = $usuarios[$login] ?? null;
        $nova = (string) $_POST['nova_senha'];
        $passo = 'nova';
        if (!$u || !naval_conta_valida($u)) {
            unset($_SESSION['troca_pendente']);
            $passo = 'senha';
            $erro = 'Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.';
        } elseif ($nova !== (string) ($_POST['confirmar_senha'] ?? '')) {
            $erro = 'As duas palavras-passe não são iguais.';
        } elseif ($f = naval_senha_fraca($nova)) {
            $erro = $f;
        } elseif (password_verify($nova, (string) $u['senha'])) {
            $erro = 'Escolha uma palavra-passe diferente da temporária.';
        } else {
            $u['senha'] = password_hash($nova, PASSWORD_DEFAULT);
            unset($u['trocar_senha']);
            $usuarios[$login] = $u;
            naval_gravar_utilizadores($usuarios);
            naval_registar('senha_alterada', [], $login);
            unset($_SESSION['troca_pendente']);
            naval_concluir_login($login, $u, $redirect, (bool) $troca['mfa']);
        }
    } elseif (isset($_POST['codigo']) && !empty($_SESSION['mfa_pendente'])) {
        // 2.º passo: código da aplicação de autenticação
        $login = $_SESSION['mfa_pendente'];
        $u = $usuarios[$login] ?? null;
        $passo = 'mfa';
        if (naval_bloqueado($login)) {
            unset($_SESSION['mfa_pendente']);
            $passo = 'senha';
            $erro = 'A conta está bloqueada após ' . NAVAL_MAX_TENTATIVAS . ' tentativas falhadas. Peça o desbloqueio ao super administrador.';
        } elseif ($u && naval_totp_aceitar($login, (string) ($u['mfa'] ?? ''), (string) $_POST['codigo'])) {
            naval_apos_verificacao($login, $u, $redirect, true);
            $passo = 'nova';
        } else {
            $resta = naval_falha_login($login);
            naval_ip_falha($ip);
            naval_registar('entrada_falhada', ['motivo' => 'codigo'], $login);
            if ($resta === 0) {
                unset($_SESSION['mfa_pendente']);
                $passo = 'senha';
                $erro = 'A conta está bloqueada após ' . NAVAL_MAX_TENTATIVAS . ' tentativas falhadas. Peça o desbloqueio ao super administrador.';
            } else {
                $erro = 'Código inválido. ' . ($resta === 1 ? 'Resta 1 tentativa' : 'Restam ' . $resta . ' tentativas') . ' antes de a conta ser bloqueada.';
            }
        }
    } else {
        $usuario = trim($_POST['usuario'] ?? '');
        $senha = $_POST['senha'] ?? '';
        unset($_SESSION['mfa_pendente'], $_SESSION['troca_pendente']);
        // Aceita o utilizador ou o email associado à conta
        [$loginEncontrado] = naval_procurar_conta($usuario);
        if ($loginEncontrado) $usuario = $loginEncontrado;

        if (empty($usuario) || empty($senha)) {
            $erro = 'Por favor, preencha todos os campos.';
        } elseif (naval_bloqueado($usuario)) {
            naval_registar('entrada_bloqueada', [], $usuario);
            $erro = 'A conta está bloqueada após ' . NAVAL_MAX_TENTATIVAS . ' tentativas falhadas. Peça o desbloqueio ao super administrador.';
        } elseif (password_verify($senha, isset($usuarios[$usuario]) ? (string) $usuarios[$usuario]['senha'] : NAVAL_HASH_FICTICIO) && isset($usuarios[$usuario])) {
            // (conta inexistente: verifica um hash fictício, para o tempo de resposta não revelar se a conta existe)
            $u = $usuarios[$usuario];
            if (empty($u['compatibilidade']) && !naval_email_valido($u['email'] ?? null)) {
                naval_registar('entrada_recusada', ['motivo' => 'sem_email'], $usuario);
                $erro = 'Esta conta não tem email associado. Peça ao administrador da plataforma para o associar.';
            } elseif (!naval_conta_valida($u)) {
                naval_registar('entrada_recusada', ['motivo' => 'conta_inactiva'], $usuario);
                $erro = 'Esta conta está desactivada ou expirou. Contacte o administrador da plataforma.';
            } elseif (!empty($u['mfa'])) {
                $_SESSION['mfa_pendente'] = $usuario;
                $passo = 'mfa';
            } else {
                naval_apos_verificacao($usuario, $u, $redirect, false);
                $passo = 'nova';
            }
        } else {
            $resta = naval_falha_login($usuario);
            naval_ip_falha($ip);
            naval_registar('entrada_falhada', ['motivo' => 'senha'], $usuario);
            $erro = $resta === 0 ? 'A conta está bloqueada após ' . NAVAL_MAX_TENTATIVAS . ' tentativas falhadas. Peça o desbloqueio ao super administrador.'
                : 'Utilizador ou palavra-passe incorrectos. ' . ($resta === 1 ? 'Resta 1 tentativa' : 'Restam ' . $resta . ' tentativas') . ' antes de a conta ser bloqueada.';
        }
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
  <script src="vendor/lucide-1.62.0.min.js"></script>
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
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(naval_csrf()) ?>" />
<?php if ($passo === 'nova'): ?>
      <p style="margin:0 2px 16px;font-size:13px;line-height:1.5;color:#c9d0e0">Entrou com uma palavra-passe temporária. Escolha agora a sua palavra-passe: pelo menos 10 caracteres, com letras e números.</p>
      <div class="form-group">
        <label for="nova_senha">Nova palavra-passe</label>
        <div class="input-wrapper">
          <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
          <input type="password" id="nova_senha" name="nova_senha" minlength="10" autocomplete="new-password" required autofocus />
        </div>
      </div>
      <div class="form-group">
        <label for="confirmar_senha">Confirmar a nova palavra-passe</label>
        <div class="input-wrapper">
          <i data-lucide="lock" style="width: 18px; height: 18px;"></i>
          <input type="password" id="confirmar_senha" name="confirmar_senha" minlength="10" autocomplete="new-password" required />
        </div>
      </div>
<?php elseif ($passo === 'mfa'): ?>
      <div class="form-group">
        <label for="codigo">Código de verificação</label>
        <div class="input-wrapper">
          <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
          <input type="text" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="Código de 6 dígitos" autocomplete="one-time-code" required autofocus />
        </div>
        <p style="margin:8px 2px 0;font-size:12.5px;opacity:.75">Abra a aplicação de autenticação no telemóvel e insira o código de GeoMobilidade Naval.</p>
      </div>
<?php else: ?>
      <div class="form-group">
        <label for="usuario">Utilizador ou email</label>
        <div class="input-wrapper">
          <i data-lucide="user" style="width: 18px; height: 18px;"></i>
          <input type="text" id="usuario" name="usuario" placeholder="Utilizador ou email" autocomplete="username" required autofocus />
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

<?php endif; ?>
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
    if (togglePassword) togglePassword.addEventListener('click', () => {
      const reveal = password.type === 'password';
      password.type = reveal ? 'text' : 'password';
      togglePassword.setAttribute('aria-label', reveal ? 'Ocultar palavra-passe' : 'Mostrar palavra-passe');
      togglePassword.innerHTML = `<i data-lucide="${reveal ? 'eye-off' : 'eye'}" style="width: 17px; height: 17px;"></i>`;
      lucide.createIcons();
    });
  </script>
</body>
</html>
