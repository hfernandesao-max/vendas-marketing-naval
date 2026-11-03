<?php require_once __DIR__ . '/auth/auth_check.php'; naval_pagina_exigir('geo-terreno', '../'); ?>
<!doctype html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <link rel="stylesheet" href="prospeccao.css" />
  <title>GeoMobilidade Naval · Terreno do dia</title>
  <script src="vendor/lucide-1.62.0.min.js"></script>
  <script src="terreno.js" defer></script>
  <?= naval_google_maps_script() ?>
</head>

<body>
  <div class="app-layout">
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="footprints"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="index.php" class="nav-btn"><i data-lucide="layout-grid"></i> Início</a>
        <a href="geo.php" class="nav-btn"><i data-lucide="route"></i> Rotas</a>
        <?php if (naval_pode('geo-prospeccao')): ?><a href="prospeccao.php" class="nav-btn"><i data-lucide="radar"></i> Prospecção</a><?php endif; ?>
        <a href="auth/logout.php?t=<?= htmlspecialchars(naval_token_saida(), ENT_QUOTES, 'UTF-8') ?>" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
          <i data-lucide="log-out"></i> Sair
        </a>
      </div>
    </header>

    <div class="app-body pp-corpo">
      <aside class="sidebar pp-lado">
        <div class="sidebar-panel">
          <h2>Terreno do dia</h2>
          <div class="pp-filtros">
            <label>Dia
              <input id="tdDia" type="date" class="premium-select" />
            </label>
            <label>Rota
              <select id="tdRota" class="premium-select"></select>
            </label>
          </div>
          <div id="tdAviso" class="pp-aviso" hidden></div>
          <div id="tdIndicadores" class="pp-ind"></div>
          <div id="tdRepeticao" class="td-rep" hidden>
            <button id="tdPlay" type="button" class="pp-tab on" aria-label="Repetir o dia">▶ Repetir o dia</button>
            <input id="tdTempo" type="range" min="0" max="0" value="0" aria-label="Momento do dia" />
            <span id="tdHora" class="pp-nota"></span>
          </div>
        </div>
        <div class="sidebar-panel">
          <div class="pp-tabs" role="tablist" aria-label="O que mostrar">
            <button type="button" role="tab" class="pp-tab on" data-vista="visitas" aria-selected="true">Visitas</button>
            <button type="button" role="tab" class="pp-tab" data-vista="paragens" aria-selected="false">Paragens</button>
            <button type="button" role="tab" class="pp-tab" data-vista="coordenadas" aria-selected="false">Coordenadas</button>
          </div>
          <h2 id="tdTituloLista">Visitas</h2>
          <div id="tdLista" class="pp-lista"><p class="pp-nota">A carregar…</p></div>
        </div>
        <p id="tdFonte" class="pp-nota pp-fontes"></p>
      </aside>
      <main class="map-container">
        <div id="map"></div>
      </main>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>

</html>
