<?php require_once __DIR__ . '/auth/auth_check.php'; naval_pagina_exigir('geo-prospeccao', '../'); ?>
<!doctype html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <link rel="stylesheet" href="prospeccao.css" />
  <title>GeoMobilidade Naval · Prospecção</title>
  <script src="vendor/lucide-1.62.0.min.js"></script>
  <script src="prospeccao.js" defer></script>
  <?= naval_google_maps_script() ?>
</head>

<body>
  <div class="app-layout">
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="radar"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="index.php" class="nav-btn"><i data-lucide="layout-grid"></i> Início</a>
        <a href="geo.php" class="nav-btn"><i data-lucide="route"></i> Rotas</a>
        <a href="view/index.php" class="nav-btn"><i data-lucide="map-pinned"></i> Zonas</a>
        <a href="auth/logout.php?t=<?= htmlspecialchars(naval_token_saida(), ENT_QUOTES, 'UTF-8') ?>" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
          <i data-lucide="log-out"></i> Sair
        </a>
      </div>
    </header>

    <div class="app-body pp-corpo">
      <aside class="sidebar pp-lado">
        <div class="sidebar-panel">
          <h2>Onde procurar clientes novos</h2>
          <div class="pp-filtros">
            <label>Rota
              <select id="ppRota" class="premium-select"></select>
            </label>
            <label>Dia de visita
              <select id="ppDia" class="premium-select">
                <option value="hoje">Hoje</option>
                <option value="1">Segunda-feira</option>
                <option value="2">Terça-feira</option>
                <option value="3">Quarta-feira</option>
                <option value="4">Quinta-feira</option>
                <option value="5">Sexta-feira</option>
                <option value="6">Sábado</option>
                <option value="todos">Todos os dias</option>
              </select>
            </label>
          </div>
          <div id="ppAviso" class="pp-aviso" hidden></div>
          <div id="ppIndicadores" class="pp-ind"></div>
          <button id="ppRecalcular" class="action-btn" hidden><i data-lucide="refresh-cw"></i> <span>Recalcular agora</span></button>
        </div>
        <div class="sidebar-panel">
          <h2 id="ppTituloLista">Potenciais clientes</h2>
          <div id="ppLista" class="pp-lista"><p class="pp-nota">A carregar…</p></div>
        </div>
        <p id="ppFontes" class="pp-nota pp-fontes"></p>
      </aside>
      <main class="map-container">
        <div id="map"></div>
      </main>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>

</html>
