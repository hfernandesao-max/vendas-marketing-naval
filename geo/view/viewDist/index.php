<?php require_once dirname(dirname(__DIR__)) . '/auth/auth_check.php'; naval_pagina_exigir('geo-distribuidores', '../../../'); ?>
<!DOCTYPE html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="../../style.css" />
  <link rel="stylesheet" href="../../theme.css" />
  <title>GeoMobilidade Naval</title>

  <script src="../../vendor/lucide-1.62.0.min.js"></script>

  <script src="../../vendor/papaparse-5.3.0.min.js"></script>
  <script src="viewScriptdist.js" defer></script>
  <?= naval_google_maps_script(['libraries' => 'drawing']) ?>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/OverlappingMarkerSpiderfier/1.0.3/oms.min.js"></script>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;700;800&display=swap" rel="stylesheet" />
</head>

<body>
  <div class="app-layout">
    <!-- Header -->
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="map"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="../../index.php" title="Página inicial" class="nav-btn">
          <i data-lucide="layout-grid"></i> Início
        </a>
        <a href="../../geo.php" title="Voltar para a optimização de rotas" class="nav-btn">
          <i data-lucide="route"></i> Rotas
        </a>
        <a href="../index.php" title="Ir para a delimitação de zonas" class="nav-btn">
          <i data-lucide="map-pinned"></i> Zonas
        </a>
        <a href="../../reports.php" title="Ir para os relatórios" class="nav-btn">
          <i data-lucide="bar-chart-3"></i> Reports
        </a>
        <a href="../../auth/logout.php?t=<?= htmlspecialchars(naval_token_saida(), ENT_QUOTES, 'UTF-8') ?>" title="Terminar sessão" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
          <i data-lucide="log-out"></i> Sair
        </a>
      </div>
    </header>

    <!-- Corpo Principal -->
    <div class="app-body">
      <!-- Sidebar Esquerda -->
      <aside class="sidebar">

        <!-- Seção de Ações / Ferramentas -->
        <div class="sidebar-panel">
          <h2>Ferramentas de distribuidor</h2>

          <div class="tool-group">
            <label>Estado do cliente</label>
            <div class="category-select-wrapper">
              <select id="selectStatus" class="premium-select">
                <option value="Ativo" selected>Activo</option>
                <option value="Inativo">Inactivo</option>
                <option value="Pendente">Pendente</option>
                <option value="Todos">Todos</option>
              </select>
            </div>
          </div>

          <div class="actions-grid">
            <button id="load" onclick="loadButtonClicked()" class="action-btn"
              title="Carregar ficheiro CSV com os clientes">
              <i data-lucide="upload"></i> <span>Importar CSV</span>
            </button>
            <input type="file" id="csvFile" accept=".csv" style="display:none;" />

            <button onclick="ativarModoDesenho()" class="action-btn primary" title="Delimitar zonas">
              <i data-lucide="waypoints"></i> <span>Desenhar zona</span>
            </button>

            <button onclick="desativarModoDesenho()" class="action-btn" title="Editar pontos na zona">
              <i data-lucide="hand"></i> <span>Editar zona</span>
            </button>

            <button onclick="importarPolygons()" class="action-btn" title="Importar zonas">
              <i data-lucide="cloud-download"></i> <span>Importar zonas</span>
            </button>

            <button onclick="exportarPolygons()" class="action-btn" title="Exportar zonas">
              <i data-lucide="file-down"></i> <span>Exportar zonas</span>
            </button>
          </div>
        </div>

        <!-- Informações de Estado -->
        <div class="sidebar-panel info-panel">
          <div class="info-item">
            <i data-lucide="file-text"></i>
            <span id="csvDocName">Nenhum ficheiro</span>
          </div>
          <div class="info-item">
            <i data-lucide="users"></i>
            <span id="textClients">Total: 0</span>
          </div>
        </div>

        <!-- Detalhes do Marcador -->
        <div class="sidebar-panel details-panel">
          <h2>Detalhes</h2>
          <div id="markerDetailsCard" class="marker-card empty">
            <i data-lucide="mouse-pointer-click"></i>
            <p>Seleccione um marcador ou zona no mapa.</p>
          </div>
        </div>

      </aside>

      <!-- Mapa -->
      <main class="map-container">
        <div id="map"></div>
      </main>
    </div>
  </div>

  <script>
    lucide.createIcons();
  </script>
<?= naval_geo_restricoes(['geo-zonas-gerir' => ['ativarModoDesenho', 'desativarModoDesenho', 'importarPolygons', 'importarPolygonsDoArquivo', 'exportarPolygons', 'removerZona', 'limparPolygonsCache', 'editarNomePolygon', 'editarNomePolygonById', 'editarCorPolygon', 'alterarCorGrupo', 'mostrarMenuContextoPolygon', 'exportarClientesDentroPolygonCSV', 'exportarClientesForaPolygonCSV', 'exportarClientesPolygonCSV', 'exportarResumoZonasCSV']]) ?>
</body>

</html>
