<?php require_once __DIR__ . '/auth/auth_check.php'; naval_pagina_exigir('geo-ao-vivo', '../'); ?>
<!doctype html>
<html lang="pt">

<head>
  <script src="embutido.js?v=<?= (int) @filemtime(__DIR__ . '/embutido.js') ?>" data-rota="geo-ao-vivo"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="style.css?v=<?= (int) @filemtime(__DIR__ . '/style.css') ?>" />
  <link rel="stylesheet" href="theme.css?v=<?= (int) @filemtime(__DIR__ . '/theme.css') ?>" />
  <link rel="stylesheet" href="prospeccao.css?v=<?= (int) @filemtime(__DIR__ . '/prospeccao.css') ?>" />
  <title>GeoMobilidade Naval · Mapa de clientes ao vivo</title>
  <style>
    .av-leg { display: grid; gap: 4px; margin: 6px 0 2px; }
    .av-leg button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px 8px; border: 1px solid rgba(127, 140, 160, .35); border-radius: 8px;
      background: transparent; color: inherit; font: inherit; cursor: pointer; text-align: left; }
    .av-leg button[aria-pressed="false"] { opacity: .45; }
    .av-leg b { margin-left: auto; font-variant-numeric: tabular-nums; }
    .av-sw { width: 14px; height: 14px; border-radius: 50%; flex: none; box-sizing: border-box; }
    .av-sw.anel { background: transparent !important; border: 3px solid; }
    .av-sw.quad { border-radius: 2px; }
    .av-sw.losango { border-radius: 2px; transform: rotate(45deg) scale(.8); }
    .av-estado { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: .78rem; font-weight: 600; color: #fff; }
    .av-hora { font-variant-numeric: tabular-nums; }
    .av-novo { outline: 2px solid #f5b547; outline-offset: 1px; }
  </style>
  <script src="vendor/lucide-1.62.0.min.js?v=<?= (int) @filemtime(__DIR__ . '/vendor/lucide-1.62.0.min.js') ?>"></script>
  <script src="ao-vivo.js?v=<?= (int) @filemtime(__DIR__ . '/ao-vivo.js') ?>" defer></script>
  <?= naval_google_maps_script() ?>
</head>

<body>
  <div class="app-layout">
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="radio"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="index.php" class="nav-btn"><i data-lucide="layout-grid"></i> Início</a>
        <?php if (naval_pode('geo-terreno')): ?><a href="terreno.php" class="nav-btn"><i data-lucide="footprints"></i> Percurso do dia</a><?php endif; ?>
        <a href="auth/logout.php?t=<?= htmlspecialchars(naval_token_saida(), ENT_QUOTES, 'UTF-8') ?>" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
          <i data-lucide="log-out"></i> Sair
        </a>
      </div>
    </header>

    <div class="app-body pp-corpo">
      <aside class="sidebar pp-lado">
        <div class="sidebar-panel">
          <h2>Clientes ao vivo</h2>
          <div class="pp-filtros">
            <label>Dia
              <input id="avDia" type="date" class="premium-select" />
            </label>
            <label>Rota
              <select id="avRota" class="premium-select"></select>
            </label>
          </div>
          <div id="avAviso" class="pp-aviso" hidden></div>
          <p id="avEstado" class="pp-nota" aria-live="polite"></p>
          <div id="avLegenda" class="av-leg" role="group" aria-label="Estados (carregue para mostrar ou esconder)"></div>
          <div id="avIndicadores" class="pp-ind"></div>
        </div>
        <div class="sidebar-panel">
          <h2 id="avTituloLista">Clientes</h2>
          <input id="avProcura" type="search" class="premium-select" placeholder="Procurar cliente ou código" aria-label="Procurar cliente ou código" />
          <div id="avLista" class="pp-lista"><p class="pp-nota">A carregar…</p></div>
        </div>
        <p id="avFonte" class="pp-nota pp-fontes"></p>
      </aside>
      <main class="map-container">
        <div id="map"></div>
      </main>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>

</html>
