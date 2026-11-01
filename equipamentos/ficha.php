<?php
/**
 * Destino do código QR das etiquetas (https://id.naval.co.ao/a/NAV-SMT-0001 → 301 → aqui, ?id=NAV-SMT-0001).
 *  - com sessão e permissão «Equipamentos»: abre a ficha no portal (e regista a leitura do QR);
 *  - sem sessão (ou sem permissão): página pública «propriedade do Grupo Naval», com o ID e o contacto de
 *    devolução — sem consultar a base de dados, para não revelar se o ID existe nem dados internos.
 * O ID é convertido para maiúsculas e validado antes de qualquer uso; leituras limitadas por IP.
 */
require_once dirname(__DIR__) . '/nucleo/arranque.php';
require_once dirname(__DIR__) . '/nucleo/api/router.php';   // naval_api_ritmo
naval_iniciar_sessao();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$id = strtoupper(trim((string) ($_GET['id'] ?? '')));
$valido = (bool) preg_match('/^[A-Z]{3}-[A-Z]{3}-\d{4}$/', $id);
try {
    naval_api_ritmo('qr|' . naval_ip_cliente(), 60);
} catch (Throwable $e) {
    http_response_code(429);
    $valido = false;
}
if (!$valido && http_response_code() !== 429) http_response_code(404);

$entrou = naval_autenticado() && naval_utilizador() !== null;
if ($valido && $entrou && naval_pode('equipamentos')) {
    header('Location: ../reports/#equipamentos?id=' . rawurlencode($id) . '&origem=qr', true, 302);
    exit;
}
if ($valido && !$entrou) $_SESSION['atv_qr'] = $id;   // depois de entrar, abre esta ficha (equipamentos/index.php)

$tel = naval_config('ATV_CONTACTO_DEVOLUCAO_TEL');
$email = naval_config('ATV_CONTACTO_DEVOLUCAO_EMAIL');
$h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#273777">
<link rel="manifest" href="../manifest.webmanifest">
<link rel="apple-touch-icon" sizes="180x180" href="../app/icones/apple-touch-icon-180.png">
<title><?= $valido ? $h($id) . ' · ' : '' ?>Grupo Naval</title>
<style>
  :root { --azul: #273777; --fundo: #f3f5fa; --cartao: #fff; --texto: #1c2340; --suave: #5b6478; --linha: #dfe4ef; --ceu: #9fd0ff; }
  @media (prefers-color-scheme: dark) { :root { --fundo: #08122b; --cartao: #13224a; --texto: #f2f5fc; --suave: #a9b7d3; --linha: #263a6b; } }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--fundo); color: var(--texto); font: 16px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }
  header { background: var(--azul); color: #fff; padding: calc(28px + env(safe-area-inset-top)) 20px 28px; text-align: center; }
  header img { width: 72px; height: 72px; }
  header .s { margin: 12px 0 2px; color: var(--ceu); font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
  header h1 { margin: 0; font: 700 30px/1.2 ui-monospace, 'SF Mono', Menlo, Consolas, monospace; letter-spacing: .02em; }
  main { max-width: 520px; margin: 0 auto; padding: 18px 16px 40px; display: grid; gap: 14px; }
  .c { background: var(--cartao); border: 1px solid var(--linha); border-radius: 18px; padding: 18px 20px; }
  .c h2 { margin: 0 0 6px; font-size: 19px; }
  .c p { margin: 6px 0 0; color: var(--suave); text-align: justify; hyphens: auto; -webkit-hyphens: auto; }
  .c a { color: inherit; }
  .lig { display: block; margin-top: 8px; font-weight: 650; font-size: 18px; text-decoration: none; color: var(--texto); }
  .btn { display: block; text-align: center; background: var(--azul); color: #fff; text-decoration: none; font-weight: 650; padding: 15px; border-radius: 14px; }
  @media (prefers-color-scheme: dark) { .btn { background: #3f8cff; } }
  footer { text-align: center; color: var(--suave); font-size: 12.5px; }
</style>
</head>
<body>
<header>
  <img src="../assets/naval-emblema.png" width="72" height="72" alt="Grupo Naval">
  <?php if ($valido): ?>
  <p class="s">ID do activo</p>
  <h1><?= $h($id) ?></h1>
  <?php else: ?>
  <h1 style="font-family: inherit; font-size: 24px; margin-top: 12px;"><?= http_response_code() === 429 ? 'Demasiadas leituras' : 'Código não reconhecido' ?></h1>
  <?php endif; ?>
</header>
<main>
  <?php if ($valido): ?>
  <section class="c">
    <h2>Este equipamento é propriedade do Grupo Naval</h2>
    <p>Se o encontrou, por favor devolva-o ao Grupo Naval ou contacte-nos. O equipamento está inscrito na gestão de equipamentos da empresa e pode ser bloqueado à distância.</p>
    <?php if ($tel !== ''): ?><a class="lig" href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $tel)) ?>"><?= $h($tel) ?></a><?php endif; ?>
    <?php if ($email !== ''): ?><a class="lig" href="mailto:<?= $h($email) ?>?subject=<?= rawurlencode('Equipamento ' . $id) ?>"><?= $h($email) ?></a><?php endif; ?>
  </section>
  <?php if ($entrou): ?>
  <section class="c"><p>A sua conta não tem acesso à gestão de equipamentos. Peça o acesso ao administrador da plataforma.</p></section>
  <?php else: ?>
  <a class="btn" href="../geo/login.php?redirect=<?= rawurlencode('../equipamentos/') ?>">Iniciar sessão</a>
  <p style="text-align:center;color:var(--suave);font-size:14px;margin:0">Colaboradores do Grupo Naval: inicie sessão para abrir a ficha do equipamento.</p>
  <?php endif; ?>
  <?php else: ?>
  <section class="c">
    <h2><?= http_response_code() === 429 ? 'Aguarde um momento' : 'Este código não corresponde a um equipamento do Grupo Naval' ?></h2>
    <p><?= http_response_code() === 429 ? 'Foram feitas muitas leituras a partir desta ligação. Tente de novo dentro de um minuto.' : 'Confirme que leu a etiqueta completa (formato NAV-SMT-0001).' ?></p>
  </section>
  <?php endif; ?>
  <footer>Grupo Naval · Portal RTM</footer>
</main>
</body>
</html>
