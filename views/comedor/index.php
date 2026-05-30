<?php
require_once dirname(__DIR__) . '/../../config/config.php';
$pageTitle = 'Historial Comedor';
$activeNav = 'comedor';
require_once dirname(__DIR__) . '/../layout_header.php';
?>

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
      <div class="col-6 col-md-2">
        <label class="form-label mb-1 small fw-600">Área</label>
        <select id="f-area" class="form-select form-select-sm">
          <option value="">Todas</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label mb-1 small fw-600">Tipo comida</label>
        <select id="f-tipo" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="DESAYUNO">Desayuno</option>
          <option value="ALMUERZO">Almuerzo</option>
          <option value="CENA">Cena</option>
        </select>
      </div>
      <div class="col-12 col-md-4 d-flex gap-2 justify-content-md-end">
        <button class="btn btn-primary btn-sm px-3" onclick="loadComedor()">
          <i class="bi bi-search"></i> Buscar
        </button>
        <a id="export-btn" href="#" class="btn btn-success btn-sm px-3">
          <i class="bi bi-file-earmark-spreadsheet"></i> Exportar
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Summary chips -->
<div id="summary-row" class="d-flex gap-3 mb-3 flex-wrap"></div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center gap-2 bg-white py-3 px-4">
    <i class="bi bi-cup-hot text-warning"></i>
    <span class="fw-600">Registros de comedor</span>
    <span id="total-badge" class="badge bg-secondary ms-2">0</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th class="ps-4">Fecha/Hora</th>
            <th>Tipo</th>
            <th>Trabajador</th>
            <th>DNI</th>
            <th>Área</th>
            <th>Empresa</th>
          </tr>
        </thead>
        <tbody id="comedor-tbody">
          <tr><td colspan="6" class="text-center py-4 text-muted">Cargando...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const BADGE_CLASS = {DESAYUNO:'badge-DESAYUNO',ALMUERZO:'badge-ALMUERZO',CENA:'badge-CENA'};
const LABEL = {DESAYUNO:'☕ Desayuno',ALMUERZO:'🍽️ Almuerzo',CENA:'🌙 Cena'};

async function loadAreas() {
  const r = await fetch('<?= BASE_URL ?>/api/index.php?/api/areas');
  const j = await r.json();
  const sel = document.getElementById('f-area');
  j.data.forEach(a => {
    const o = document.createElement('option');
    o.value = a.id_area;
    o.textContent = a.nombre_area;
    sel.appendChild(o);
  });
}

async function loadComedor() {
  const desde = document.getElementById('f-desde').value;
  const hasta = document.getElementById('f-hasta').value;
  const area  = document.getElementById('f-area').value;
  const tipo  = document.getElementById('f-tipo').value;

  const params = new URLSearchParams({desde,hasta});
  if (area) params.append('area', area);
  if (tipo) params.append('tipo', tipo);

  // Update export link
  const exportUrl = `<?= BASE_URL ?>/api/index.php?/api/export/comedor&${params}`;
  document.getElementById('export-btn').href = exportUrl;

  const tbody = document.getElementById('comedor-tbody');
  tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Cargando...</td></tr>';

  try {
    const r = await fetch(`<?= BASE_URL ?>/api/index.php?/api/comedor&${params}`);
    const j = await r.json();
    if (!j.success) throw new Error(j.message);

    document.getElementById('total-badge').textContent = j.data.length;

    // Summary
    const counts = {DESAYUNO:0,ALMUERZO:0,CENA:0};
    j.data.forEach(row => { if (counts[row.tipo_evento] !== undefined) counts[row.tipo_evento]++; });
    const sr = document.getElementById('summary-row');
    sr.innerHTML = Object.entries(counts).map(([t,c]) =>
      `<span class="evt-badge ${BADGE_CLASS[t]}" style="font-size:.8rem;padding:.3rem .7rem">${LABEL[t]}: ${c}</span>`
    ).join('');

    if (!j.data.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted">Sin registros en el período seleccionado</td></tr>';
      return;
    }

    tbody.innerHTML = j.data.map(row => {
      const dt    = row.fecha_hora.split(' ');
      const fecha = dt[0];
      const hora  = dt[1]?.substring(0,8) ?? '';
      return `<tr>
        <td class="ps-4">
          <span style="font-size:.85rem">${fecha}</span><br>
          <span style="font-family:monospace;font-size:.78rem;color:var(--gray-600)">${hora}</span>
        </td>
        <td><span class="evt-badge ${BADGE_CLASS[row.tipo_evento]||''}">${LABEL[row.tipo_evento]||row.tipo_evento}</span></td>
        <td><span class="fw-500">${row.nombre_completo}</span></td>
        <td><span style="font-family:monospace;font-size:.83rem">${row.dni}</span></td>
        <td><small>${row.nombre_area}</small></td>
        <td><small class="text-muted">${row.empresa||'—'}</small></td>
      </tr>`;
    }).join('');
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger">Error: ${e.message}</td></tr>`;
  }
}

loadAreas().then(() => loadComedor());
</script>

<?php require_once dirname(__DIR__) . '/../layout_footer.php'; ?>