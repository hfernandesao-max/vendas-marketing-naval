<?php require_once dirname(dirname(__DIR__)) . '/auth/auth_check.php'; ?>
<!DOCTYPE html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="../../style.css" />
  <link rel="stylesheet" href="../../theme.css" />
  <title>GeoMobilidadeNaval</title>

  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/PapaParse/5.3.0/papaparse.min.js"></script>
  <script src="viewScriptdist.js" defer></script>
  <script
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAlk6zhL-VjbGA6FuTRf250gKeEgXMMiMY&v=3.64&libraries=drawing&callback=initMap"
    defer></script>

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
        <a href="../../index.php" title="Página Inicial / Hub" class="nav-btn">
          <i data-lucide="layout-grid"></i> Início
        </a>
        <a href="../../geo.php" title="Voltar para a optimização de rotas" class="nav-btn">
          <i data-lucide="route"></i> Rotas
        </a>
        <a href="../index.php" title="Ir para Delimitação de Zonas" class="nav-btn">
          <i data-lucide="map-pinned"></i> Zonas
        </a>
        <a href="../../reports.php" title="Ir para Relatórios" class="nav-btn">
          <i data-lucide="bar-chart-3"></i> Reports
        </a>
        <a href="../../auth/logout.php" title="Terminar sessão" class="nav-btn" style="color: #fca5a5; margin-left: 0.5rem;">
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
          <h2>Ferramentas de Distribuidor</h2>

          <div class="tool-group">
            <label>Estado do Cliente</label>
            <div class="category-select-wrapper">
              <select id="selectStatus" class="premium-select">
                <option value="Ativo" selected>Ativo</option>
                <option value="Inativo">Inativo</option>
                <option value="Pendente">Pendente</option>
                <option value="Todos">Todos</option>
              </select>
            </div>
          </div>

          <div class="actions-grid">
            <button id="load" onclick="loadButtonClicked()" class="action-btn"
              title="Carregar ficheiro csv contendo os clientes">
              <i data-lucide="upload"></i> <span>Importar CSV</span>
            </button>
            <input type="file" id="csvFile" accept=".csv" style="display:none;" />

            <button onclick="ativarModoDesenho()" class="action-btn primary" title="Delimitar zonas">
              <i data-lucide="waypoints"></i> <span>Desenhar Zona</span>
            </button>

            <button onclick="desativarModoDesenho()" class="action-btn" title="Editar pontos na zona">
              <i data-lucide="hand"></i> <span>Editar Zona</span>
            </button>

            <button onclick="importarPolygons()" class="action-btn" title="Importar Zonas">
              <i data-lucide="cloud-download"></i> <span>Importar Zonas</span>
            </button>

            <button onclick="exportarPolygons()" class="action-btn" title="Exportar Zonas">
              <i data-lucide="file-down"></i> <span>Exportar Zonas</span>
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
            <p>Selecione um marcador ou zona no mapa.</p>
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
