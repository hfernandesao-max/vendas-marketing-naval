<?php require_once __DIR__ . '/auth/auth_check.php'; ?>
<!doctype html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="theme.css" />
  <title>GeoMobilidade Naval · Optimização de rotas</title>

  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
  <script src="script.js" defer></script>
  <script
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAlk6zhL-VjbGA6FuTRf250gKeEgXMMiMY&v=3.64&callback=initMap"
    defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/OverlappingMarkerSpiderfier/1.0.3/oms.min.js"></script>

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet" />
</head>

<body>
  <div class="app-layout">
    <!-- Header -->
    <header class="app-header">
      <div class="header-brand">
        <i data-lucide="route"></i>
        <h1>GeoMobilidade<span>Naval</span></h1>
      </div>
      <div class="header-nav">
        <a href="index.php" title="Página inicial" class="nav-btn">
          <i data-lucide="layout-grid"></i> Início
        </a>
        <a href="view/index.php" title="Ir para a delimitação de zonas" class="nav-btn">
          <i data-lucide="map-pinned"></i> Zonas
        </a>
        <a href="view/viewDist/index.php" title="Ir para os distribuidores" class="nav-btn">
          <i data-lucide="map"></i> Distribuidores
        </a>
        <a href="reports.php" title="Ir para os relatórios" class="nav-btn">
          <i data-lucide="bar-chart-3"></i> Reports
        </a>
        <a href="auth/logout.php" title="Terminar sessão" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
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
          <h2>Ferramentas de rota</h2>

          <div class="tool-group">
            <label>Categoria de cliente</label>
            <div class="category-select-wrapper">
              <select id="selectCategory" class="premium-select">
                <option value="2ª FEIRA - A" selected>A - Segunda-feira, quinta-feira e sábado</option>
                <option value="3ª FEIRA - B">B - Terça-feira e sexta-feira</option>
                <option value="4ª FEIRA - C">C - Quarta-feira</option>
                <option value="2ª FEIRA">2ª FEIRA</option>
                <option value="3ª FEIRA">3ª FEIRA</option>
                <option value="4ª FEIRA">4ª FEIRA</option>
                <option value="5ª FEIRA">5ª FEIRA</option>
                <option value="6ª FEIRA">6ª FEIRA</option>
                <option value="SÁBADO">SÁBADO</option>
                <option value="DOMINGO">DOMINGO</option>
              </select>
            </div>
          </div>

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
            <button id="load" onclick="handleLoadClick()" class="action-btn" title="Carregar ficheiro CSV">
              <i data-lucide="upload"></i> <span>Importar CSV</span>
            </button>
            <input type="file" id="csvFile" accept=".csv" style="display:none;" />

            <button id="startRouting" class="action-btn primary" title="Optimizar a rota">
              <i data-lucide="route"></i> <span>Optimizar</span>
            </button>

            <button id="downloadCSV" class="action-btn" title="Descarregar rota optimizada">
              <i data-lucide="download"></i> <span>Exportar</span>
            </button>
          </div>
        </div>

        <!-- Informações de Estado -->
        <div class="sidebar-panel info-panel">
          <div class="info-item">
            <i data-lucide="file-text"></i>
            <span id="csvDocName">Nenhum ficheiro carregado</span>
          </div>
          <div class="info-item">
            <i data-lucide="users"></i>
            <span id="textClients">Total: 0</span>
          </div>
          <div class="info-item">
            <i data-lucide="navigation"></i>
            <span id="totalDistance">Distância: 0 km</span>
          </div>
        </div>

        <!-- Pesquisa -->
        <div class="sidebar-panel search-panel">
          <h2>Pesquisar no mapa</h2>
          <div class="search-wrapper">
            <i data-lucide="search" class="search-icon"></i>
            <input type="text" id="searchInput" placeholder="Nome, Código ou Vendedor..." />
          </div>
          <div id="searchResults" class="search-results"></div>
        </div>

        <!-- Detalhes do Marcador -->
        <div class="sidebar-panel details-panel">
          <h2>Detalhes do cliente</h2>
          <div id="markerDetailsCard" class="marker-card empty">
            <i data-lucide="mouse-pointer-click"></i>
            <p>Seleccione um marcador no mapa para ver os detalhes.</p>
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
</body>

</html>
