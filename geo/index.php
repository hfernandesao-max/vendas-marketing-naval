<?php
declare(strict_types=1);

// A raiz do GEO abre diretamente a aplicação principal. A autenticação e o
// redirecionamento seguro permanecem centralizados no controlo já existente.
require_once __DIR__ . '/auth/auth_check.php';

header('Location: geo.php', true, 302);
exit;

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

$isLoggedIn = isset($_SESSION['usuario_autenticado']) && $_SESSION['usuario_autenticado'] === true;
$userName = $_SESSION['usuario_nome'] ?? 'Utilizador';
$userLogin = $_SESSION['usuario_login'] ?? '';
$userProfile = $_SESSION['usuario_perfil'] ?? 'Operador';
?>
<!DOCTYPE html>
<html lang="pt">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>GeoMobilidade Naval &bull; Portal Operacional</title>

  <!-- Google Fonts: Poppins -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

  <!-- Icons -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

  <!-- Theme Styles -->
  <link rel="stylesheet" href="theme.css" />

  <style>
    *, *::before, *::after {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      min-height: 100vh;
      background: radial-gradient(circle at 50% 0%, #162a45 0%, #0d1527 45%, #070b14 100%);
      color: #e6e9f0;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow-x: hidden;
    }

    /* Ambient background lights */
    .ambient-glow {
      position: absolute;
      width: 600px;
      height: 600px;
      border-radius: 50%;
      pointer-events: none;
      filter: blur(120px);
      opacity: 0.15;
      z-index: 0;
    }

    .ambient-left {
      top: -100px;
      left: -150px;
      background: #0ea5e9;
    }

    .ambient-right {
      bottom: -100px;
      right: -150px;
      background: #3b82f6;
    }

    /* Top Navigation Bar */
    .portal-nav {
      position: relative;
      z-index: 10;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1.25rem 3rem;
      background: rgba(13, 21, 39, 0.7);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .brand-group {
      display: flex;
      align-items: center;
      gap: 1rem;
      text-decoration: none;
      color: inherit;
    }

    .brand-group img {
      height: 44px;
      width: auto;
      object-fit: contain;
      filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.4));
    }

    .brand-title {
      display: flex;
      flex-direction: column;
    }

    .brand-title h1 {
      font-size: 1.35rem;
      font-weight: 700;
      letter-spacing: -0.3px;
      color: #ffffff;
      line-height: 1.2;
    }

    .brand-title h1 span {
      font-weight: 300;
      color: #93c5fd;
    }

    .brand-badge {
      font-size: 0.65rem;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      font-weight: 600;
      color: #60a5fa;
      background: rgba(37, 99, 235, 0.15);
      border: 1px solid rgba(59, 130, 246, 0.25);
      padding: 0.15rem 0.5rem;
      border-radius: 999px;
      width: fit-content;
      margin-top: 0.2rem;
    }

    .nav-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .user-pill {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.45rem 0.9rem;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 999px;
      font-size: 0.82rem;
      color: #cbd5e1;
    }

    .user-pill .status-dot {
      width: 8px;
      height: 8px;
      background: #10b981;
      border-radius: 50%;
      box-shadow: 0 0 8px #10b981;
    }

    .btn-login-header {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.55rem 1.2rem;
      background: #1A365D;
      color: #ffffff;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
      border: 1px solid #2B6CB0;
      transition: all 0.2s ease;
    }

    .btn-login-header:hover {
      background: #2B6CB0;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(43, 108, 176, 0.35);
    }

    .btn-logout-header {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.85rem;
      background: rgba(239, 68, 68, 0.12);
      border: 1px solid rgba(239, 68, 68, 0.25);
      color: #fca5a5;
      border-radius: 8px;
      text-decoration: none;
      font-size: 0.8rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }

    .btn-logout-header:hover {
      background: rgba(239, 68, 68, 0.25);
      color: #ffffff;
    }

    /* Main Container */
    .portal-main {
      position: relative;
      z-index: 1;
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 3rem 1.5rem;
      max-width: 1240px;
      margin: 0 auto;
      width: 100%;
    }

    /* Hero section */
    .hero-header {
      text-align: center;
      max-width: 760px;
      margin-bottom: 3.5rem;
      animation: fadeInDown 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .hero-tag {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.4rem 1rem;
      background: rgba(59, 130, 246, 0.1);
      border: 1px solid rgba(59, 130, 246, 0.25);
      border-radius: 999px;
      color: #93c5fd;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 0.8px;
      text-transform: uppercase;
      margin-bottom: 1.25rem;
    }

    .hero-title {
      font-size: clamp(2rem, 4vw, 2.85rem);
      font-weight: 800;
      letter-spacing: -1px;
      color: #ffffff;
      line-height: 1.2;
      margin-bottom: 1rem;
    }

    .hero-title span {
      background: linear-gradient(135deg, #60a5fa 0%, #38bdf8 50%, #93c5fd 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .hero-description {
      font-size: 1.05rem;
      color: #94a3b8;
      font-weight: 300;
      line-height: 1.6;
    }

    /* The Two Big Buttons Grid */
    .portal-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(320px, 460px));
      gap: 2.25rem;
      width: 100%;
      justify-content: center;
      animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* Big Module Card Button */
    .module-card {
      position: relative;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      text-decoration: none;
      color: inherit;
      background: linear-gradient(180deg, rgba(26, 41, 69, 0.7) 0%, rgba(15, 23, 42, 0.85) 100%);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 20px;
      padding: 2.5rem 2.25rem;
      min-height: 380px;
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.1);
      transition: all 0.35s cubic-bezier(0.2, 0.8, 0.2, 1);
      overflow: hidden;
    }

    /* Glow backdrop on cards */
    .module-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 140px;
      pointer-events: none;
      opacity: 0.12;
      transition: opacity 0.35s ease;
      border-radius: 20px 20px 0 0;
    }

    /* REPORTS Card Specific Theme (Emerald / Indigo) */
    .card-reports::before {
      background: radial-gradient(circle at 50% 0%, #38bdf8 0%, transparent 70%);
    }

    .card-reports:hover {
      border-color: rgba(56, 189, 248, 0.5);
      transform: translateY(-8px);
      box-shadow: 0 25px 50px rgba(14, 165, 233, 0.22), 0 0 30px rgba(56, 189, 248, 0.15);
    }

    .card-reports:hover::before {
      opacity: 0.25;
    }

    /* GEO Card Specific Theme (Royal Blue / Cyan) */
    .card-geo::before {
      background: radial-gradient(circle at 50% 0%, #3b82f6 0%, transparent 70%);
    }

    .card-geo:hover {
      border-color: rgba(59, 130, 246, 0.6);
      transform: translateY(-8px);
      box-shadow: 0 25px 50px rgba(37, 99, 235, 0.25), 0 0 35px rgba(59, 130, 246, 0.2);
    }

    .card-geo:hover::before {
      opacity: 0.3;
    }

    /* Card Top Content */
    .card-top {
      position: relative;
      z-index: 2;
    }

    .card-header-flex {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1.75rem;
    }

    .card-icon-box {
      width: 72px;
      height: 72px;
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.3s ease;
    }

    .card-reports .card-icon-box {
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.2) 0%, rgba(2, 132, 199, 0.4) 100%);
      border: 1px solid rgba(56, 189, 248, 0.3);
      color: #38bdf8;
    }

    .card-geo .card-icon-box {
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.25) 0%, rgba(29, 78, 216, 0.45) 100%);
      border: 1px solid rgba(96, 165, 250, 0.35);
      color: #60a5fa;
    }

    .module-card:hover .card-icon-box {
      transform: scale(1.08) rotate(2deg);
    }

    .card-badge-status {
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.8px;
      text-transform: uppercase;
      padding: 0.35rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #cbd5e1;
    }

    .card-title-group h2 {
      font-size: 2.1rem;
      font-weight: 800;
      letter-spacing: 0.5px;
      color: #ffffff;
      margin-bottom: 0.4rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .card-reports .card-title-group h2 {
      color: #f0f9ff;
    }

    .card-subtitle {
      font-size: 0.85rem;
      font-weight: 500;
      color: #60a5fa;
      margin-bottom: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .card-description {
      font-size: 0.95rem;
      color: #94a3b8;
      line-height: 1.55;
      font-weight: 300;
      margin-bottom: 1.5rem;
    }

    .feature-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 1.75rem;
    }

    .feature-tag {
      font-size: 0.75rem;
      padding: 0.25rem 0.65rem;
      border-radius: 6px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.08);
      color: #94a3b8;
    }

    /* Card Bottom CTA Button */
    .card-action-btn {
      position: relative;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.4rem;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      transition: all 0.25s ease;
      cursor: pointer;
    }

    .card-reports .card-action-btn {
      background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
      color: #ffffff;
      box-shadow: 0 4px 15px rgba(2, 132, 199, 0.3);
    }

    .card-reports:hover .card-action-btn {
      background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
      box-shadow: 0 6px 20px rgba(14, 165, 233, 0.45);
    }

    .card-geo .card-action-btn {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      color: #ffffff;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
    }

    .card-geo:hover .card-action-btn {
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5);
    }

    .card-action-btn i {
      transition: transform 0.25s ease;
    }

    .module-card:hover .card-action-btn i {
      transform: translateX(5px);
    }

    /* Footer */
    .portal-footer {
      position: relative;
      z-index: 10;
      text-align: center;
      padding: 1.5rem;
      font-size: 0.8rem;
      color: #64748b;
      border-top: 1px solid rgba(255, 255, 255, 0.05);
      background: rgba(7, 11, 20, 0.6);
    }

    /* Animations */
    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(25px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Responsive */
    @media (max-width: 820px) {
      .portal-nav {
        padding: 1rem 1.5rem;
      }

      .portal-grid {
        grid-template-columns: 1fr;
        max-width: 480px;
      }

      .module-card {
        min-height: auto;
        padding: 2rem 1.75rem;
      }

      .hero-title {
        font-size: 1.85rem;
      }
    }
  </style>
</head>

<body>
  <!-- Ambient background lighting effects -->
  <div class="ambient-glow ambient-left"></div>
  <div class="ambient-glow ambient-right"></div>

  <!-- Top Navigation Bar -->
  <header class="portal-nav">
    <a href="index.php" class="brand-group" title="GeoMobilidade Naval">
      <img src="imgs/naval.png" alt="Logótipo Naval" />
      <div class="brand-title">
        <h1>Mobilidade<span>Naval</span></h1>
        <span class="brand-badge">Portal Central</span>
      </div>
    </a>

    <div class="nav-actions">
      <?php if ($isLoggedIn): ?>
        <div class="user-pill" title="Sessão iniciada como <?= htmlspecialchars($userName) ?>">
          <span class="status-dot"></span>
          <span><?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userProfile) ?>)</span>
        </div>
        <a href="auth/logout.php" class="btn-logout-header" title="Terminar sessão">
          <i data-lucide="log-out" style="width: 15px; height: 15px;"></i>
          <span>Sair</span>
        </a>
      <?php else: ?>
        <a href="login.php" class="btn-login-header" id="btnHeaderLogin" title="Aceder à conta">
          <i data-lucide="lock" style="width: 15px; height: 15px;"></i>
          <span>Iniciar Sessão</span>
        </a>
      <?php endif; ?>
    </div>
  </header>

  <!-- Main Content Container -->
  <main class="portal-main">

    <!-- Hero Title & Context -->
    <div class="hero-header">
      <div class="hero-tag">
        <i data-lucide="layers" style="width: 14px; height: 14px;"></i>
        <span>Plataforma Integrada Naval</span>
      </div>
      <h1 class="hero-title">Selecione o seu <span>Módulo Operacional</span></h1>
      <p class="hero-description">
        Escolha entre a central analítica de relatórios ou a ferramenta de otimização geográfica e roteirização da frota.
      </p>
    </div>

    <!-- The 2 Big Buttons: REPORTS & GEO -->
    <div class="portal-grid">

      <!-- 1. BOTAO GRANDE: REPORTS -->
      <a href="reports.php" class="module-card card-reports" id="btnModuleReports" title="Abrir Módulo de Relatórios">
        <div class="card-top">
          <div class="card-header-flex">
            <div class="card-icon-box">
              <i data-lucide="bar-chart-3" style="width: 36px; height: 36px;"></i>
            </div>
            <span class="card-badge-status">Auditoria & Indicadores</span>
          </div>

          <div class="card-title-group">
            <h2>REPORTS</h2>
            <div class="card-subtitle">Inteligência Operacional</div>
          </div>

          <p class="card-description">
            Consulte indicadores de desempenho, relatórios diários de frotas e distribuições, e realize o download de dados consolidados em formato CSV.
          </p>

          <div class="feature-tags">
            <span class="feature-tag"><i data-lucide="file-spreadsheet" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Exportações CSV</span>
            <span class="feature-tag"><i data-lucide="trending-up" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Métricas de Desempenho</span>
            <span class="feature-tag"><i data-lucide="history" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Histórico de Auditoria</span>
          </div>
        </div>

        <div class="card-action-btn">
          <span>Abrir Relatórios</span>
          <i data-lucide="arrow-right" style="width: 20px; height: 20px;"></i>
        </div>
      </a>

      <!-- 2. BOTAO GRANDE: GEO -->
      <a href="geo.php" class="module-card card-geo" id="btnModuleGeo" title="Abrir GeoMobilidade e Rotas">
        <div class="card-top">
          <div class="card-header-flex">
            <div class="card-icon-box">
              <i data-lucide="route" style="width: 36px; height: 36px;"></i>
            </div>
            <span class="card-badge-status">Otimização & Frotas</span>
          </div>

          <div class="card-title-group">
            <h2>GEO</h2>
            <div class="card-subtitle">GeoMobilidade & Mapas</div>
          </div>

          <p class="card-description">
            Planeamento inteligente de rotas com Google Maps, delimitação de zonas de entrega poligonais e gestão georreferenciada de distribuidores.
          </p>

          <div class="feature-tags">
            <span class="feature-tag"><i data-lucide="navigation" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Otimização de Rota</span>
            <span class="feature-tag"><i data-lucide="map-pinned" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Delimitação de Zonas</span>
            <span class="feature-tag"><i data-lucide="truck" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle; margin-right: 3px;"></i> Gestão Distribuidores</span>
          </div>
        </div>

        <div class="card-action-btn">
          <span>Aceder ao GEO</span>
          <i data-lucide="arrow-right" style="width: 20px; height: 20px;"></i>
        </div>
      </a>

    </div>

  </main>

  <!-- Footer -->
  <footer class="portal-footer">
    &copy; <?= date('Y') ?> GeoMobilidade Naval &bull; Todos os direitos reservados &bull; Sistema Integrado de Operações v2.4
  </footer>

  <script>
    // Initialize Lucide icons
    lucide.createIcons();
  </script>
</body>

</html>
