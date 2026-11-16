<?php
/**
 * Destino do código QR das etiquetas (https://id.naval.co.ao/a/NAV-SMT-0001 → 302 → aqui, ?id=NAV-SMT-0001; regras em ferramentas/apache).
 *  - com sessão e permissão «Equipamentos»: abre a ficha no portal (e regista a leitura do QR);
 *  - sem sessão (ou sem permissão): página pública, em português e inglês, «propriedade do Grupo Naval», com o ID, o tipo
 *    (deduzido do ID) e o contacto de devolução gravado no portal — a resposta não depende da base de dados, para não
 *    revelar se o ID existe nem dados internos (ver nucleo/servicos/equipamentos-qr.php: a leitura fica registada e, se o
 *    equipamento estiver dado como perdido ou roubado, quem administra é avisado);
 *  - ?publica=1: a mesma página pública, para pré-visualizar com sessão iniciada (sem registar leitura).
 * O ID é convertido para maiúsculas e validado antes de qualquer uso; leituras limitadas por IP (60 por minuto).
 * Cabeçalhos próprios, mais estritos do que os do portal: CSP sem scripts, sem enquadramento, sem formulários.
 */
require_once dirname(__DIR__) . '/nucleo/arranque.php';
require_once dirname(__DIR__) . '/nucleo/api/router.php';   // naval_api_ritmo
naval_servico('equipamentos-qr');
naval_iniciar_sessao();
$nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header_remove('Content-Security-Policy-Report-Only');
header_remove('Reporting-Endpoints');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'nonce-$nonce'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$id = naval_atv_qr_id((string) ($_GET['id'] ?? ''));
$valido = $id !== '';
$excesso = false;
try {
    naval_api_ritmo('qr|' . naval_ip_cliente(), 60);
} catch (Throwable $e) {
    http_response_code(429);
    header('Retry-After: 60');
    $excesso = true;
    $valido = false;
}
if (!$valido && !$excesso) http_response_code(404);

$entrou = naval_autenticado() && naval_utilizador() !== null;
$previsualizar = ($_GET['publica'] ?? '') === '1' && $entrou;
if ($valido && $entrou && !$previsualizar && naval_pode('equipamentos')) {
    header('Location: ../reports/#equipamentos?id=' . rawurlencode($id) . '&origem=qr', true, 302);
    exit;
}
if ($valido && !$entrou) {
    $_SESSION['atv_qr'] = $id;   // depois de entrar, abre esta ficha (equipamentos/index.php)
    naval_atv_qr_leitura_publica($id);
}

$contacto = naval_atv_contacto();
$tel = $contacto['tel'];
$email = $contacto['email'];
$telLigacao = preg_replace('/[^0-9+]/', '', $tel);
[$tipoPt, $tipoEn] = $valido ? naval_atv_qr_tipo($id) : ['', ''];
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#273777">
<link rel="apple-touch-icon" sizes="180x180" href="../app/icones/apple-touch-icon-180.png">
<title><?= $valido ? htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' · ' : '' ?>Grupo Naval</title>
<style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  :root { --fundo: #eef2f8; --cartao: #fff; --texto: #0b1f3a; --suave: #44546d; --linha: #d5ddeb; --pri: #0057b8; --pri-txt: #fff; --faixa: #273777; }
  @media (prefers-color-scheme: dark) {
    :root { --fundo: #0b1220; --cartao: #131c2e; --texto: #e8edf6; --suave: #a9b5c8; --linha: #26324a; --pri: #3f8cff; --pri-txt: #06101f; --faixa: #3a4f9e; }
  }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; background: var(--fundo); color: var(--texto); font: 16px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
         display: flex; align-items: center; justify-content: center; padding: calc(24px + env(safe-area-inset-top)) 16px calc(24px + env(safe-area-inset-bottom)); }
  main { width: 100%; max-width: 440px; background: var(--cartao); border: 1px solid var(--linha); border-radius: 20px; padding: 28px 24px; }
  .logo { display: block; width: 88px; height: 88px; margin: 0 auto 18px; }
  h1 { font-size: 21px; line-height: 1.3; margin: 0 0 6px; text-align: center; }
  p { margin: 0 0 6px; text-align: justify; text-align-last: center; hyphens: auto; -webkit-hyphens: auto; }
  .en { color: var(--suave); font-size: 14px; }
  .id { margin: 20px 0; border: 1px solid var(--linha); border-radius: 14px; overflow: hidden; text-align: center; }
  .id .t { background: var(--faixa); color: #fff; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; padding: 6px; }
  .id .n { font: 700 24px/1.2 ui-monospace, 'SF Mono', Menlo, Consolas, monospace; padding: 14px 8px; letter-spacing: .02em; }
  .acc { display: grid; gap: 10px; margin-top: 18px; }
  .acc a { display: flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 12px; border-radius: 12px; text-decoration: none; font-weight: 600; text-align: center; }
  .acc a.p { background: var(--pri); color: var(--pri-txt); }
  .acc a.s { border: 1px solid var(--linha); color: var(--texto); }
  .acc a:focus-visible { outline: 3px solid var(--pri); outline-offset: 2px; }
  .nota { margin-top: 20px; font-size: 13px; color: var(--suave); }
  .sep { border: 0; border-top: 1px solid var(--linha); margin: 22px 0 16px; }
  .pre { margin: 0 0 16px; padding: 8px 12px; border-radius: 10px; background: var(--fundo); color: var(--suave); font-size: 13px; text-align: center; }
  footer { margin-top: 18px; text-align: center; color: var(--suave); font-size: 12.5px; }
  @media (prefers-reduced-motion: no-preference) { .acc a { transition: filter .15s; } .acc a:hover { filter: brightness(1.08); } }
</style>
</head>
<body>
<main>
  <?php if ($previsualizar): ?><p class="pre">Pré-visualização da página pública (o que vê quem lê o código sem sessão iniciada).</p><?php endif; ?>
  <img class="logo" src="../assets/naval-emblema-160.png" width="88" height="88" alt="Grupo Naval">
<?php if ($valido): ?>
  <h1>Este equipamento é propriedade do Grupo Naval</h1>
  <p class="en" lang="en">This device is the property of Grupo Naval.</p>
  <div class="id">
    <div class="t"><?= htmlspecialchars($tipoPt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if ($tipoEn !== $tipoPt): ?> · <span lang="en"><?= htmlspecialchars($tipoEn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?></div>
    <div class="n"><?= htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  </div>
  <p>Se o encontrou, por favor contacte-nos para o devolver. O equipamento está registado e pode ser bloqueado à distância.</p>
  <p class="en" lang="en">If you found it, please contact us to return it. This device is registered and can be locked remotely.</p>
  <?php if ($telLigacao !== '' || $email !== ''): ?>
  <div class="acc">
    <?php if ($telLigacao !== ''): ?><a class="p" href="tel:<?= htmlspecialchars($telLigacao, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Ligar · <span lang="en">Call</span> <?= htmlspecialchars($tel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a><?php endif; ?>
    <?php if ($email !== ''): ?><a class="<?= $telLigacao !== '' ? 's' : 'p' ?>" href="mailto:<?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?subject=<?= htmlspecialchars(rawurlencode('Equipamento encontrado ' . $id), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Enviar mensagem · <span lang="en">Email</span> <?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a><?php endif; ?>
  </div>
  <?php endif; ?>
  <p class="nota">Não retire a etiqueta.<br><span lang="en">Please do not remove the label.</span></p>
  <hr class="sep">
  <?php if ($entrou && !$previsualizar): ?>
  <p class="nota">A sua conta não tem acesso à gestão de equipamentos. Peça o acesso ao administrador da plataforma.</p>
  <?php elseif (!$entrou): ?>
  <div class="acc"><a class="s" href="../geo/login.php?redirect=<?= htmlspecialchars(rawurlencode('../equipamentos/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Colaboradores: iniciar sessão</a></div>
  <p class="nota">Os colaboradores do Grupo Naval iniciam sessão para abrir a ficha do equipamento.</p>
  <?php endif; ?>
<?php elseif ($excesso): ?>
  <h1>Demasiadas leituras</h1>
  <p class="en" lang="en">Too many scans.</p>
  <p class="nota">Foram feitas muitas leituras a partir desta ligação. Tente de novo dentro de um minuto.<br><span lang="en">Please try again in a minute.</span></p>
<?php else: ?>
  <h1>Código não reconhecido</h1>
  <p class="en" lang="en">Code not recognised.</p>
  <p class="nota">Leia de novo o código QR da etiqueta (formato NAV-SMT-0001).<br><span lang="en">Please scan the label's QR code again.</span></p>
<?php endif; ?>
  <footer>Grupo Naval</footer>
</main>
</body>
</html>
