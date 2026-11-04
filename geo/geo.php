<?php require_once __DIR__ . '/auth/auth_check.php'; naval_pagina_exigir('geo-rotas', '../'); ?>
<!doctype html>
<html lang="pt">

<head>
  <script src="embutido.js?v=<?= (int) @filemtime(__DIR__ . '/embutido.js') ?>" data-rota="geo-rotas"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="style.css?v=<?= (int) @filemtime(__DIR__ . '/style.css') ?>" />
  <link rel="stylesheet" href="theme.css?v=<?= (int) @filemtime(__DIR__ . '/theme.css') ?>" />
  <link rel="stylesheet" href="prospeccao.css?v=<?= (int) @filemtime(__DIR__ . '/prospeccao.css') ?>" />
  <title>GeoMobilidade Naval · Optimização de rotas</title>
  <script src="vendor/lucide-1.62.0.min.js?v=<?= (int) @filemtime(__DIR__ . '/vendor/lucide-1.62.0.min.js') ?>"></script>
  <script src="rotas.js?v=<?= (int) @filemtime(__DIR__ . '/rotas.js') ?>" defer></script>
  <?= naval_google_maps_script() ?>
</head>

<body>
  <div class="app-layout">
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="route"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="../reports/" target="_top" class="nav-btn"><i data-lucide="layout-grid"></i> Portal</a>
      </div>
    </header>

    <div class="app-body pp-corpo">
      <aside class="sidebar pp-lado">
        <div class="sidebar-panel">
          <h2>Percurso optimizado</h2>
          <div class="pp-filtros">
            <label>Dia
              <select id="rtDia" class="premium-select"></select>
            </label>
            <label>Rota
              <select id="rtRota" class="premium-select"></select>
            </label>
          </div>
          <div id="rtAviso" class="pp-aviso" hidden></div>
          <div id="rtIndicadores" class="pp-ind"></div>
          <button id="rtExportar" type="button" class="action-btn" hidden><i data-lucide="download"></i> <span>Excel do percurso</span></button>
        </div>
        <div class="sidebar-panel">
          <h2 id="rtTituloLista">Ordem de visita</h2>
          <div id="rtLista" class="pp-lista"><p class="pp-nota">A carregar…</p></div>
        </div>
        <p id="rtFonte" class="pp-nota pp-fontes"></p>
      </aside>
      <main class="map-container">
        <div id="map"></div>
      </main>
    </div>
  </div>
  <script>lucide.createIcons();</script>
<?= naval_geo_restricoes(['geo-rotas-descarregar' => ['#rtExportar']]) ?>
</body>

</html>
