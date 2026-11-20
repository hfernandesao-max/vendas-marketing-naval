<?php
/**
 * Página comum das Zonas de entrega (geofences das rotas) e dos Distribuidores, ambas automáticas (geo/zonas.js).
 * Quem inclui define $modo ('zonas' ou 'distribuidores') e $base (caminho até geo/, com a barra final), depois de
 * naval_pagina_exigir.
 */
defined('NAVAL_NUCLEO') || exit;
$modo = ($modo ?? 'zonas') === 'distribuidores' ? 'distribuidores' : 'zonas';
$base = $base ?? '../';
$v = function (string $f) use ($base) { return htmlspecialchars($base . $f, ENT_QUOTES, 'UTF-8') . '?v=' . (int) @filemtime(__DIR__ . '/' . $f); };
$titulo = $modo === 'zonas' ? 'Geofences das rotas' : 'Distribuidores e as suas zonas';
?>
<!doctype html>
<html lang="pt">

<head>
  <script src="<?= $v('embutido.js') ?>" data-rota="<?= $modo === 'zonas' ? 'geo-zonas' : 'geo-distribuidores' ?>"></script>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="<?= htmlspecialchars(naval_csrf(), ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="stylesheet" href="<?= $v('style.css') ?>" />
  <link rel="stylesheet" href="<?= $v('theme.css') ?>" />
  <link rel="stylesheet" href="<?= $v('zonas.css') ?>" />
  <title>GeoMobilidade Naval · <?= $modo === 'zonas' ? 'Zonas de entrega' : 'Distribuidores' ?></title>
  <script src="<?= $v('vendor/lucide-1.62.0.min.js') ?>"></script>
  <script src="<?= $v('geo-dados.js') ?>"></script>
  <script src="<?= $v('geo-cores.js') ?>"></script>
  <script src="<?= $v('pontos-canvas.js') ?>"></script>
  <script src="<?= $v('zonas.js') ?>" defer></script>
  <?= naval_google_maps_script() ?>
</head>

<body data-modo="<?= $modo ?>">
  <div class="app-layout">
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="<?= $modo === 'zonas' ? 'map-pinned' : 'map' ?>"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>index.php" class="nav-btn"><i data-lucide="layout-grid"></i> Início</a>
        <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>geo.php" class="nav-btn"><i data-lucide="route"></i> Rotas</a>
        <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>auth/logout.php?t=<?= htmlspecialchars(naval_token_saida(), ENT_QUOTES, 'UTF-8') ?>" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
          <i data-lucide="log-out"></i> Sair
        </a>
      </div>
    </header>

    <div class="app-body z-corpo">
      <aside class="sidebar z-lado">
        <div class="sidebar-panel">
          <h2><?= $titulo ?></h2>
          <label class="z-campo">Estado do cliente
            <select id="zEstado" class="premium-select">
              <option value="Activo" selected>Activos</option>
              <option value="Inactivo">Inactivos (sem compras há 56 dias)</option>
              <option value="Todos">Todos</option>
            </select>
          </label>
          <div id="zIndicadores" class="z-ind"></div>
          <p id="zFonte" class="z-nota">A carregar…</p>
        </div>
        <div class="sidebar-panel">
          <h2><?= $modo === 'zonas' ? 'Rotas' : 'Distribuidores' ?></h2>
          <div id="zLegenda" class="z-legenda" role="group" aria-label="Legenda e filtro do mapa"></div>
        </div>
        <div class="sidebar-panel">
          <h2>Cliente</h2>
          <div id="zDetalhe" class="z-detalhe"></div>
        </div>
        <div class="sidebar-panel">
          <h2>Exportar</h2>
          <div class="z-exportar">
            <button type="button" class="action-btn" data-exporta="todos"><i data-lucide="file-spreadsheet"></i> <span>Todos os clientes</span></button>
            <?php if ($modo === 'zonas'): ?>
            <button type="button" class="action-btn" data-exporta="dentro"><i data-lucide="check-circle"></i> <span>Dentro das geofences</span></button>
            <button type="button" class="action-btn" data-exporta="fora"><i data-lucide="alert-triangle"></i> <span>Fora das geofences</span></button>
            <?php endif; ?>
            <button type="button" class="action-btn" data-exporta="resumo"><i data-lucide="table"></i> <span>Resumo por <?= $modo === 'zonas' ? 'rota' : 'distribuidor' ?></span></button>
            <button type="button" class="action-btn" data-exporta="geojson"><i data-lucide="map"></i> <span>Zonas (GeoJSON)</span></button>
          </div>
          <p class="z-nota">Ficheiros CSV para o Excel. As zonas em GeoJSON abrem no Google Earth, QGIS ou My Maps.</p>
        </div>
      </aside>
      <main class="map-container">
        <div id="map" role="region" aria-label="Mapa"></div>
      </main>
    </div>
  </div>
  <script>lucide.createIcons();</script>
</body>

</html>
