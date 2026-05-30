<?php
require_once dirname(__DIR__) . '/../config/config.php';
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once dirname(__DIR__) . '/layout_header.php';
?>

<div id="dash-content">
  <!-- Stat cards row -->
  <div class="row g-3 mb-4" id="stat-row">
    <?php
    $stats = [
      ['id'=>'s-desayunos',    'icon'=>'☕', 'color'=>'#fffbeb','bcolor'=>'#d97706','label'=>'Desayunos hoy'],
      ['id'=>'s-almuerzos',    'icon'=>'🍽️','color'=>'#f5f3ff','bcolor'=>'#7c3aed','label'=>'Almuerzos hoy'],
      ['id'=>'s-cenas',        'icon'=>'🌙', 'color'=>'#eff6ff','bcolor'=>'#1d4ed8','label'=>'Cenas hoy'],
      ['id'=>'s-trabajadores', 'icon'=>'👷', 'color'=>'#f0fdf4','bcolor'=>'#15803d','label'=>'Trabajadores atendidos'],
      ['id'=>'s-visitantes',   'icon'=>'🪪', 'color'=>'#fef2f2','bcolor'=>'#dc2626','label'=>'Visitantes hoy'],
      ['id'=>'s-ingresos',     'icon'=>'🟢', 'color'=>'#f0fdf4','bcolor'=>'#059669','label'=>'Ingresos laborales'],
      ['id'=>'s-salidas',      'icon'=>'🔴', 'color'=>'#fef2f2','bcolor'=>'#dc2626','label'=>'Salidas laborales'],
    ];
    foreach ($stats as $s): ?>
      <div class="col-6 col-md-4 col-xl-3">
        <div class="stat-card">
          <div class="stat-icon" style="background:<?= $s['color'] ?>">
            <?= $s['icon'] ?>
          </div>
          <div class="stat-value" id="<?= $s['id'] ?>">—</div>
          <div class="stat-label"><?= $s['label'] ?></div>
        </div>
      </div>
    <?php endforeach ?>
  </div>

  <div class="row g-3">
    <!-- Últimas marcaciones -->
    <div class="col-12 col-xl-8">
      <div class="card">
        <div class="card-header d-flex align-items-center gap-2 bg-white py-3 px-4" style="border-radius:12px 12px 0 0">
          <i class="bi bi-activity text-primary"></i>
          <span class="fw-600">Últimas marcaciones de hoy</span>
          <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="loadDashboard()">
            <i class="bi bi-arrow-clockwise"></i> Actualizar
          </button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr>
                  <th class="ps-4">Hora</th>
                  <th>Trabajador</th>
                  <th>Área</th>
                  <th>Evento</th>
                </tr>
              </thead>
              <tbody id="recent-tbody">
                <tr><td colspan="4" class="text-center py-4 text-muted">Cargando...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Acceso rápido -->
    <div class="col-12 col-xl-4">
      <div class="card p-4 d-flex flex-column gap-3">
        <h6 class="fw-600 mb-1">Acceso rápido</h6>
        <a href="<?= BASE_URL ?>/views/scanner/index.php?modulo=comedor" class="btn btn-warning d-flex align-items-center gap-2 fw-600">
          <i class="bi bi-qr-code-scan fs-5"></i> Escanear Comedor
        </a>
        <a href="<?= BASE_URL ?>/views/scanner/index.php?modulo=laboral" class="btn btn-primary d-flex align-items-center gap-2 fw-600">
          <i class="bi bi-qr-code-scan fs-5"></i> Escanear Asistencia
        </a>
        <hr class="my-1">
        <a href="<?= BASE_URL ?>/views/reportes/index.php" class="btn btn-outline-success d-flex align-items-center gap-2">
          <i class="bi bi-file-earmark-spreadsheet"></i> Exportar a Excel
        </a>
        <a href="<?= BASE_URL ?>/views/trabajadores/index.php" class="btn btn-outline-secondary d-flex align-items-center gap-2">
          <i class="bi bi-people"></i> Gestionar Trabajadores
        </a>

        <!-- Comida activa -->
        <div class="mt-2 p-3 rounded-3" style="background:var(--primary-light)">
          <div style="font-size:.72rem;font-weight:600;color:var(--primary);text-transform:uppercase;letter-spacing:.06em">Turno activo ahora</div>
          <div id="turno-activo" class="fw-700 mt-1" style="color:var(--primary);font-size:1.1rem">—</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const TIPOS = {
  INGRESO:'badge-INGRESO',SALIDA_BREAK:'badge-SALIDA_BREAK',
  INGRESO_BREAK:'badge-INGRESO_BREAK',SALIDA_TRABAJO:'badge-SALIDA_TRABAJO',
  DESAYUNO:'badge-DESAYUNO',ALMUERZO:'badge-ALMUERZO',CENA:'badge-CENA',
};
const LABELS = {
  INGRESO:'Ingreso',SALIDA_BREAK:'Salida break',INGRESO_BREAK:'Regreso break',
  SALIDA_TRABAJO:'Salida',DESAYUNO:'Desayuno',ALMUERZO:'Almuerzo',CENA:'Cena',
};

function detectarTurno() {
  const h = new Date().getHours() * 100 + new Date().getMinutes();
  if (h >= 500  && h <= 959)  return '☕ Desayuno (05:00–09:59)';
  if (h >= 1000 && h <= 1559) return '🍽️ Almuerzo (10:00–15:59)';
  return '🌙 Cena (16:00–23:59)';
}

async function loadDashboard() {
  try {
    const r = await fetch('<?= BASE_URL ?>/api/index.php?/api/dashboard');
    const j = await r.json();
    if (!j.success) return;
    const d = j.data;

    document.getElementById('s-desayunos').textContent    = d.desayunos;
    document.getElementById('s-almuerzos').textContent    = d.almuerzos;
    document.getElementById('s-cenas').textContent        = d.cenas;
    document.getElementById('s-trabajadores').textContent = d.total_trabajadores;
    document.getElementById('s-visitantes').textContent   = d.total_visitantes;
    document.getElementById('s-ingresos').textContent     = d.ingresos_laborales;
    document.getElementById('s-salidas').textContent      = d.salidas_laborales;

    const tbody = document.getElementById('recent-tbody');
    if (!d.ultimas_marcaciones.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted">Sin marcaciones hoy</td></tr>';
      return;
    }
    tbody.innerHTML = d.ultimas_marcaciones.map(m => {
      const hora  = m.fecha_hora.split(' ')[1].substring(0,8);
      const badge = TIPOS[m.tipo_evento] || '';
      const lbl   = LABELS[m.tipo_evento] || m.tipo_evento;
      return `<tr>
        <td class="ps-4"><span style="font-family:var(--bs-font-monospace);font-size:.83rem">${hora}</span></td>
        <td><span class="fw-500">${m.nombre_completo}</span><br><small class="text-muted">${m.dni}</small></td>
        <td><small>${m.nombre_area}</small></td>
        <td><span class="evt-badge ${badge}">${lbl}</span></td>
      </tr>`;
    }).join('');
  } catch(e) { console.error(e); }
}

document.getElementById('turno-activo').textContent = detectarTurno();
loadDashboard();
setInterval(loadDashboard, 30000);
</script>

<?php require_once dirname(__DIR__) . '/layout_footer.php'; ?>