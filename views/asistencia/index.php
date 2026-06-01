<?php
require_once dirname(__DIR__) . '/../config/config.php';
$pageTitle = 'Asistencia Laboral';
$activeNav = 'asistencia';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<style>
.horas-chip {
  display: inline-block;
  font-family: monospace;
  font-size: .8rem;
  font-weight: 600;
  padding: .2rem .5rem;
  border-radius: 5px;
}
.horas-extra   { background: #dcfce7; color: #166534; }
.horas-deficit { background: #fee2e2; color: #991b1b; }
.horas-ok      { background: #dbeafe; color: #1e40af; }
</style>

<!-- Filters -->
<div class="card mb-4">
  <div class="card-body py-3 px-4">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label mb-1 small fw-600">Desde</label>
        <input type="date" id="f-desde" class="form-control form-control-sm"
               value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1 small fw-600">Hasta</label>
        <input type="date" id="f-hasta" class="form-control form-control-sm"
               value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label mb-1 small fw-600">Área</label>
        <select id="f-area" class="form-select form-select-sm">
          <option value="">Todas las áreas</option>
        </select>
      </div>
      <div class="col-12 col-md-5 d-flex gap-2 justify-content-md-end">
        <button class="btn btn-primary btn-sm px-3" onclick="loadAsistencia()">
          <i class="bi bi-search"></i> Buscar
        </button>
        <a id="export-btn" href="#" class="btn btn-success btn-sm px-3">
          <i class="bi bi-file-earmark-spreadsheet"></i> Excel
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center gap-2 bg-white py-3 px-4">
    <i class="bi bi-clock-history text-primary"></i>
    <span class="fw-600">Registro de asistencia laboral</span>
    <span id="total-badge" class="badge bg-secondary ms-2">0</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-4">Fecha</th>
            <th>Trabajador</th>
            <th>Área</th>
            <th class="text-center">Ingreso</th>
            <th class="text-center">S. Break</th>
            <th class="text-center">I. Break</th>
            <th class="text-center">Salida</th>
            <th class="text-center">H. Netas</th>
            <th class="text-center">Prog.</th>
            <th class="text-center">Diferencia</th>
          </tr>
        </thead>
        <tbody id="asist-tbody">
          <tr><td colspan="10" class="text-center py-4 text-muted">Cargando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function fmtHora(dt) {
  if (!dt || dt === '—') return '<span class="text-muted">—</span>';
  
  // ⚠️ BLINDAJE: Si la variable ya viene formateada como hora pura (HH:MM:SS), evitamos el split
  let horaLimpia = dt;
  if (dt.includes(' ')) {
    horaLimpia = dt.split(' ')[1];
  }
  
  return `<span style="font-family:monospace;font-size:.82rem">${horaLimpia.substring(0, 5)}</span>`;
}

function fmtHoras(h, tipo) {
  if (h === null || h === undefined || h === '') return '<span class="text-muted">—</span>';
  const cls = tipo === 'extra' ? 'horas-extra' : (tipo === 'deficitaria' ? 'horas-deficit' : 'horas-ok');
  return `<span class="horas-chip ${cls}">${h}h</span>`;
}

function fmtDiff(d, tipo) {
  if (d === null || d === undefined) return '<span class="text-muted">—</span>';
  const sign  = tipo === 'extra' ? '+' : '-';
  const color = tipo === 'extra' ? '#166534' : '#991b1b';
  return `<span style="font-family:monospace;font-size:.82rem;font-weight:600;color:${color}">${sign}${Math.abs(d)}h</span>`;
}

async function loadAreas() {
  try {
    // ⚠️ CORRECCIÓN 1: Formato cambiado a parámetro nativo ?action=areas y cabecera de Ngrok añadida
    const r = await fetch('<?= BASE_URL ?>/api/index.php?action=areas', {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'ngrok-skip-browser-warning': 'true' // Salta la alerta de Ngrok en móviles
      }
    });
    
    if (!r.ok) throw new Error(`Error ${r.status}`);
    const j = await r.json();
    
    if (!j.success || !j.data) return;

    const sel = document.getElementById('f-area');
    sel.innerHTML = '<option value="">Todas las áreas</option>'; // Limpiamos duplicados
    
    j.data.forEach(a => {
      const o = document.createElement('option');
      o.value = a.id_area; 
      o.textContent = a.nombre_area;
      sel.appendChild(o);
    });
  } catch (e) {
    console.error("Error al cargar áreas en asistencia:", e);
  }
}

async function loadAsistencia() {
  const desde = document.getElementById('f-desde').value;
  const hasta = document.getElementById('f-hasta').value;
  const area  = document.getElementById('f-area').value;

  const params = new URLSearchParams({desde, hasta});
  if (area) params.append('area', area);

  document.getElementById('export-btn').href =
    `<?= BASE_URL ?>/api/index.php?action=export/asistencia&${params}`;

  const tbody = document.getElementById('asist-tbody');
  tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-muted">Cargando...</td></tr>';

  try {
    const r = await fetch(`<?= BASE_URL ?>/api/index.php?action=asistencia&${params}`, {
      method: 'GET',
      headers: { 'Accept': 'application/json', 'ngrok-skip-browser-warning': 'true' }
    });
    
    // ── DEPURACIÓN CRÍTICA: Captura el error real de PHP ──
    const textoCrudo = await r.text();
    if (textoCrudo.includes('<br />') || textoCrudo.includes('<b>')) {
       console.error("❌ ERROR CRÍTICO DETECTADO EN TU VISTA SQL:");
       console.log(textoCrudo); // <--- AQUÍ VERÁS EL ERROR REAL DE LARAGON
       tbody.innerHTML = '<tr><td colspan="10" class="text-center py-4 text-danger fw-bold">Error interno de SQL/Vista. Revisa la consola F12.</td></tr>';
       return;
    }

    const j = JSON.parse(textoCrudo);
    if (!j.success) throw new Error(j.message);

    document.getElementById('total-badge').textContent = j.data.length;

    if (!j.data.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="text-center py-5 text-muted">Sin registros en el período</td></tr>';
      return;
    }

    tbody.innerHTML = j.data.map(row => {
      // Ajustamos dinámicamente si tus campos de horas netas usan nombres alternos en el JSON
      const netas = row.horas_netas !== undefined ? row.horas_netas : (row.horas_trabajadas || '—');
      const diff  = row.diferencia !== undefined ? row.diferencia : (row.diferencia_horas || '—');

      return `<tr>
        <td class="ps-4"><span style="font-size:.85rem">${row.fecha||'—'}</span></td>
        <td>
          <span class="fw-500">${row.nombre_completo || row.nombre}</span><br>
          <small class="text-muted" style="font-family:monospace">${row.dni || '—'}</small>
        </td>
        <td><small>${row.nombre_area || '—'}</small></td>
        <td class="text-center">${fmtHora(row.hora_ingreso)}</td>
        <td class="text-center">${fmtHora(row.hora_salida_break)}</td>
        <td class="text-center">${fmtHora(row.hora_ingreso_break)}</td>
        <td class="text-center">${fmtHora(row.hora_salida_trabajo)}</td>
        <td class="text-center">${fmtHoras(netas, row.tipo_diferencia)}</td>
        <td class="text-center"><span class="horas-chip horas-ok">${row.horas_programadas}h</span></td>
        <td class="text-center">${fmtDiff(diff, row.tipo_diferencia)}</td>
      </tr>`;
    }).join('');
    
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4 text-danger">Error al procesar la lista: ${e.message}</td></tr>`;
    console.error("Error completo en loadAsistencia:", e);
  }
}

loadAreas().then(() => loadAsistencia());
</script>

<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>