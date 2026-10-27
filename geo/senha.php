<?php
/**
 * Mudar a palavra-passe (a própria pessoa, com a sessão aberta): pede a palavra-passe actual e a nova
 * (ASVS 2.1.5 e 2.1.6). Regras: nucleo/seguranca/contas.php (naval_senha_fraca). Depois de mudar, as
 * outras sessões abertas da conta terminam (versão das credenciais); esta continua.
 */
require_once __DIR__ . '/auth/auth_check.php';

$eu = naval_utilizador();
$erro = '';
$ok = false;
if (!$eu || !empty($eu['compatibilidade'])) {
    $erro = 'As contas ainda estão na lista antiga: a palavra-passe muda-se depois de activar a gestão de contas no portal.';
} elseif (naval_e_post()) {
    $actual = (string) ($_POST['actual'] ?? '');
    $nova = (string) ($_POST['nova'] ?? '');
    if (!hash_equals(naval_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        $erro = 'A página expirou. Tente de novo.';
    } elseif (!password_verify($actual, (string) ($eu['senha'] ?? ''))) {
        $_SESSION['senha_falhas'] = (int) ($_SESSION['senha_falhas'] ?? 0) + 1;
        naval_registar('senha_alteracao_falhada', []);
        if ($_SESSION['senha_falhas'] >= NAVAL_MAX_TENTATIVAS) {
            naval_registar('sessao_terminada', ['motivo' => 'senha_actual']);
            $_SESSION = [];
            session_destroy();
            header('Location: login.php');
            exit;
        }
        $erro = 'A palavra-passe actual está incorrecta.';
    } elseif ($nova !== (string) ($_POST['confirmar'] ?? '')) {
        $erro = 'As duas palavras-passe novas não são iguais.';
    } elseif ($f = naval_senha_fraca($nova, (string) $eu['login'], (string) ($eu['email'] ?? ''))) {
        $erro = $f;
    } elseif (password_verify($nova, (string) $eu['senha'])) {
        $erro = 'A palavra-passe nova tem de ser diferente da actual.';
    } else {
        $login = (string) $eu['login'];
        $u = naval_exclusivo('utilizadores', function () use ($login, $nova) {
            $todos = naval_utilizadores();
            $x = $todos[$login];
            $x['senha'] = password_hash($nova, PASSWORD_DEFAULT);
            unset($x['trocar_senha'], $x['temporaria_ate']);
            $x = naval_nova_versao($x);
            $todos[$login] = $x;
            naval_gravar_utilizadores($todos);
            return $x;
        });
        session_regenerate_id(true);
        $_SESSION['versao_credencial'] = (int) ($u['versao'] ?? 0);   // esta sessão continua; as outras terminam
        $_SESSION['senha_falhas'] = 0;
        naval_registar('senha_alterada', ['por' => 'proprio']);
        $ok = true;
    }
}
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Mudar a palavra-passe · GeoMobilidade Naval</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 1.5rem;
      background: linear-gradient(135deg, var(--gray-900) 0%, #111a2e 50%, var(--gray-900) 100%); }
    .caixa { width: 100%; max-width: 420px; background: var(--sidebar-bg); border: 1px solid var(--gray-600);
      border-radius: 12px; padding: 2rem; color: #fff; }
    h1 { font-size: 1.35rem; margin-bottom: .4rem; }
    p.nota { color: var(--gray-300); font-size: .82rem; line-height: 1.5; margin-bottom: 1.2rem; }
    label { display: block; font-size: .82rem; color: var(--gray-300); margin: .9rem 0 .35rem; }
    input[type=password] { width: 100%; padding: .7rem .8rem; border-radius: 8px; border: 1px solid var(--gray-600);
      background: rgba(255,255,255,.04); color: #fff; font-size: .95rem; }
    button { margin-top: 1.4rem; width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: #2a6fe0;
      color: #fff; font-weight: 600; font-size: .95rem; cursor: pointer; }
    .msg { padding: .7rem .9rem; border-radius: 8px; font-size: .85rem; margin-bottom: 1rem; }
    .msg.erro { background: rgba(255,69,58,.12); border: 1px solid rgba(255,69,58,.35); color: #ffb4ae; }
    .msg.ok { background: rgba(42,111,224,.15); border: 1px solid rgba(42,111,224,.45); color: #cfe0ff; }
    a.voltar { display: block; text-align: center; margin-top: 1rem; color: var(--gray-300); font-size: .85rem; }
  </style>
</head>
<body>
  <div class="caixa">
    <h1>Mudar a palavra-passe</h1>
    <p class="nota">Pelo menos 12 caracteres (pode ser uma frase), diferente do nome da conta e que não seja comum. As outras sessões abertas com esta conta terminam.</p>
<?php if ($erro !== ''): ?>
    <div class="msg erro" role="alert"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif ($ok): ?>
    <div class="msg ok" role="status">Palavra-passe alterada.</div>
<?php endif; ?>
<?php if (!$ok && $eu && empty($eu['compatibilidade'])): ?>
    <form method="post" action="senha.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
      <label for="actual">Palavra-passe actual</label>
      <input type="password" id="actual" name="actual" autocomplete="current-password" required autofocus />
      <label for="nova">Nova palavra-passe</label>
      <input type="password" id="nova" name="nova" minlength="12" maxlength="64" autocomplete="new-password" required />
      <label for="confirmar">Confirmar a nova palavra-passe</label>
      <input type="password" id="confirmar" name="confirmar" minlength="12" maxlength="64" autocomplete="new-password" required />
      <button type="submit">Guardar</button>
    </form>
<?php endif; ?>
    <a class="voltar" href="../">Voltar à página inicial</a>
  </div>
</body>
</html>
