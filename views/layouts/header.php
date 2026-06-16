<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'SistemaQR Control') ?> — SistemaQR</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📲</text></svg>">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary:   #0f4c81;
      --primary-light: #e8f1fb;
      --accent:    #f59e0b;
      --accent-bg: #fffbeb;
      --success:   #059669;
      --danger:    #dc2626;
      --gray-50:   #f8fafc;
      --gray-100:  #f1f5f9;
      --gray-200:  #e2e8f0;
      --gray-600:  #475569;
      --gray-800:  #1e293b;
      --sidebar-w: 260px;
      --font-main: 'Figtree', system-ui, sans-serif;
      --font-mono: 'JetBrains Mono', monospace;
    }
    * { box-sizing: border-box; }
    body {
      font-family: var(--font-main);
      background: var(--gray-50);
      color: var(--gray-800);
    }

    /* ── Sidebar ── */
    #sidebar {
      position: fixed;
      top: 0; left: 0; bottom: 0;
      width: var(--sidebar-w);
      background: var(--primary);
      display: flex;
      flex-direction: column;
      z-index: 1000;
      transition: transform .25s ease;
    }
    #sidebar .brand {
      padding: 1.5rem 1.25rem 1rem;
      border-bottom: 1px solid rgba(255,255,255,.12);
    }
    #sidebar .brand h5 {
      color: #fff;
      font-weight: 700;
      font-size: 1.1rem;
      margin: 0;
      letter-spacing: -.02em;
    }
    #sidebar .brand small {
      color: rgba(255,255,255,.55);
      font-size: .72rem;
    }
    #sidebar nav { flex: 1; overflow-y: auto; padding: .75rem 0; }
    #sidebar nav .nav-label {
      padding: .5rem 1.25rem .25rem;
      font-size: .65rem;
      font-weight: 600;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: rgba(255,255,255,.4);
    }
    #sidebar nav a {
      display: flex;
      align-items: center;
      gap: .65rem;
      padding: .55rem 1.25rem;
      color: rgba(255,255,255,.78);
      text-decoration: none;
      font-size: .88rem;
      font-weight: 500;
      border-radius: 0 6px 6px 0;
      margin-right: .75rem;
      transition: background .15s, color .15s;
    }
    #sidebar nav a:hover, #sidebar nav a.active {
      background: rgba(255,255,255,.15);
      color: #fff;
    }
    #sidebar nav a.active { background: rgba(255,255,255,.2); }
    #sidebar nav a i { font-size: 1.1rem; width: 1.3rem; text-align: center; }

    #sidebar .clock-bar {
      padding: .9rem 1.25rem;
      border-top: 1px solid rgba(255,255,255,.12);
      color: rgba(255,255,255,.7);
      font-size: .8rem;
    }
    #sidebar .clock-bar #live-clock {
      font-family: var(--font-mono);
      font-size: 1.1rem;
      color: #fff;
      font-weight: 500;
    }

    /* ── Main content ── */
    #main {
      margin-left: var(--sidebar-w);
      min-height: 100vh;
    }
    #topbar {
      background: #fff;
      border-bottom: 1px solid var(--gray-200);
      padding: .8rem 1.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    #topbar h1 {
      font-size: 1.1rem;
      font-weight: 600;
      margin: 0;
      color: var(--gray-800);
    }
    #topbar .badge-date {
      background: var(--primary-light);
      color: var(--primary);
      font-size: .72rem;
      font-weight: 600;
      padding: .25rem .6rem;
      border-radius: 6px;
    }

    .page-body { padding: 1.5rem; }

    /* ── Cards ── */
    .card {
      border: 1px solid var(--gray-200);
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,.04);
    }
    .stat-card {
      border-radius: 12px;
      padding: 1.25rem;
      background: #fff;
      border: 1px solid var(--gray-200);
      display: flex;
      flex-direction: column;
      gap: .3rem;
    }
    .stat-card .stat-icon {
      width: 40px; height: 40px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem;
      margin-bottom: .4rem;
    }
    .stat-card .stat-value {
      font-size: 2rem;
      font-weight: 700;
      line-height: 1;
      color: var(--gray-800);
    }
    .stat-card .stat-label {
      font-size: .78rem;
      color: var(--gray-600);
      font-weight: 500;
    }

    /* ── Badges tipo evento ── */
    .badge-INGRESO        { background:#dcfce7; color:#166534; }
    .badge-SALIDA_BREAK   { background:#fef9c3; color:#854d0e; }
    .badge-INGRESO_BREAK  { background:#dbeafe; color:#1e40af; }
    .badge-SALIDA_TRABAJO { background:#fee2e2; color:#991b1b; }
    .badge-DESAYUNO       { background:#fef3c7; color:#92400e; }
    .badge-ALMUERZO       { background:#ede9fe; color:#4c1d95; }
    .badge-CENA           { background:#e0e7ff; color:#312e81; }
    .evt-badge {
      display: inline-block;
      font-size: .72rem;
      font-weight: 600;
      padding: .22rem .55rem;
      border-radius: 5px;
      letter-spacing: .02em;
    }

    /* ── Tabla ── */
    .table th {
      font-size: .72rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--gray-600);
      border-bottom-width: 1px;
    }
    .table td { font-size: .85rem; vertical-align: middle; }
    .table tbody tr:hover { background: var(--gray-50); }

    /* ── Toast notif ── */
    #scan-toast-container {
      position: fixed; bottom: 2rem; right: 2rem;
      z-index: 9999; display: flex; flex-direction: column; gap: .5rem;
    }
    .scan-toast {
      background: #fff;
      border: 1.5px solid var(--gray-200);
      border-radius: 12px;
      padding: 1rem 1.25rem;
      min-width: 280px;
      max-width: 360px;
      box-shadow: 0 8px 24px rgba(0,0,0,.12);
      animation: slideIn .25s ease;
      display: flex; gap: .75rem; align-items: flex-start;
    }
    .scan-toast.success { border-left: 4px solid var(--success); }
    .scan-toast.error   { border-left: 4px solid var(--danger); }
    @keyframes slideIn {
      from { opacity:0; transform: translateX(24px); }
      to   { opacity:1; transform: translateX(0); }
    }
    .scan-toast .t-icon { font-size: 1.4rem; }
    .scan-toast .t-body { flex: 1; }
    .scan-toast .t-title { font-weight: 600; font-size: .9rem; }
    .scan-toast .t-sub   { font-size: .8rem; color: var(--gray-600); margin-top: .1rem; }

    /* ── QR Scanner ── */
    #qr-region { border-radius: 12px; overflow: hidden; }
    #qr-region video { border-radius: 12px; }

    /* ── Responsive ── */
    @media (max-width: 768px) {
      #sidebar { transform: translateX(-100%); }
      #sidebar.open { transform: translateX(0); }
      #main { margin-left: 0; }
      .page-body { padding: 1rem; }
    }
  </style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside id="sidebar">
  <div class="brand">
    <h5>📲 SistemaQR</h5>
    <small>Control de Asistencia</small>
  </div>
  <nav>
    <div class="nav-label">Principal</div>
    <a href="<?= BASE_URL ?>/index.php" class="<?= ($activeNav??'')==='dashboard'?'active':'' ?>">
      <i class="bi bi-grid-1x2-fill"></i> Dashboard
    </a>
    <a href="<?= BASE_URL ?>/views/scanner/index.php" class="<?= ($activeNav??'')==='scanner'?'active':'' ?>">
      <i class="bi bi-qr-code-scan"></i> Escanear QR
    </a>
    <a href="<?= BASE_URL ?>/views/reportes/carga_historica.php" class="<?= ($activeNav??'')==='carga-historica'?'active':'' ?>">
      <i class="bi bi-pencil-square"></i> Transcribir Hojas
    </a>

    <div class="nav-label">Registros</div>
    <a href="<?= BASE_URL ?>/views/comedor/index.php" class="<?= ($activeNav??'')==='comedor'?'active':'' ?>">
      <i class="bi bi-cup-hot-fill"></i> Comedor
    </a>
    <a href="<?= BASE_URL ?>/views/asistencia/index.php" class="<?= ($activeNav??'')==='asistencia'?'active':'' ?>">
      <i class="bi bi-clock-history"></i> Asistencia Laboral
    </a>

    <div class="nav-label">Administración</div>
    <a href="<?= BASE_URL ?>/views/trabajadores/index.php" class="<?= ($activeNav??'')==='trabajadores'?'active':'' ?>">
      <i class="bi bi-people-fill"></i> Trabajadores
    </a>
    <a href="<?= BASE_URL ?>/views/visitantes/index.php" class="<?= ($activeNav??'')==='visitantes'?'active':'' ?>">
      <i class="bi bi-person-badge-fill"></i> Visitantes
    </a>

    <div class="nav-label">Reportes</div>
    <a href="<?= BASE_URL ?>/views/reportes/index.php" class="<?= ($activeNav??'')==='reportes'?'active':'' ?>">
      <i class="bi bi-file-earmark-spreadsheet-fill"></i> Exportar Excel
    </a>
  </nav>

  <div class="clock-bar">
    <div style="font-size:.7rem;opacity:.6;margin-bottom:.2rem">HORA LOCAL</div>
    <div id="live-clock">--:--:--</div>
  </div>
</aside>

<!-- ── Main ── -->
<div id="main">
  <div id="topbar">
    <button class="btn btn-sm btn-outline-secondary d-md-none" onclick="toggleSidebar()">
      <i class="bi bi-list"></i>
    </button>
    <h1><?= htmlspecialchars($pageTitle ?? '') ?></h1>
    <div class="ms-auto badge-date"><?= date('d M Y') ?></div>
  </div>
  <div class="page-body">