<?php
require_once dirname(__DIR__) . '/../../config/config.php';
$pageTitle = 'Exportar Reportes';
$activeNav = 'reportes';
require_once dirname(__DIR__) . '/../layout_header.php';
?>

<div class="row g-4">

  <!-- Reporte Comedor -->
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white py-3 px-4" style="border-radius:12px 12px 0 0">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:1.4rem">🍽️</span>
          <span class="fw-700 fs-6">Reporte de Comedor</span>
        </div>
        <small class="text-muted">Desayunos, almuerzos y cenas por período</small>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label small fw-600">Desde</label>
            <input type="date" id="c-desde" class="form-control" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small fw-600">Hasta</label>
            <input type="date" id="c-hasta" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label small fw-600">Área (opcional)</label>
            <select id="c-area" class="form-select">
              <option value="">Todas las áreas</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small fw-600">Tipo de comida</label>
            <select id="c-tipo" class="form-select">
              <option value="">Todos</option>
              <option value="DESAYUNO">Desayuno</option>
              <option value="ALMUERZO">Almuerzo</option>
              <option value="CENA">Cena</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label small fw-600">Tipo de persona</label>
            <select id="c-persona" class="form-select">
              <option value="todos">Todos (Trab. + Visitantes)</option>
              <option value="trabajador">Solo trabajadores</option>
              <option value="visitante">Solo visitantes</option>
            </select>
          </div>
        </div>

        <div class="p-3 rounded-3 mb-3" style="background:var(--gray-50);border:1px solid var(--gray-200)">
          <div class="small fw-600 mb-1 text-muted">Columnas exportadas:</div>
          <div class="small">Fecha · Hora · Tipo · <strong>Tipo Persona</strong> · Nombre · DNI · Área · Cargo · Empresa</div>
        </div>

        <a id="btn-export-comedor" href="#"
           class="btn btn-success w-100 d-flex align-items-center justify-content-center gap-2 fw-600"
           onclick="exportar('comedor')">
          <i class="bi bi-file-earmark-spreadsheet-fill fs-5"></i>
          Descargar CSV — Comedor
        </a>
      </div>
    </div>
  </div>

  <!-- Reporte Asistencia -->
  <div class="col-12 col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white py-3 px-4" style="border-radius:12px 12px 0 0">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:1.4rem">🏭</span>
          <span class="fw-700 fs-6">Reporte de Asistencia Laboral</span>
        </div>
        <small class="text-muted">Ingresos, breaks, salidas y horas trabajadas</small>
      </div>
      <div class="card-body">
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label small fw-600">Desde</label>
            <input type="date" id="a-desde" class="form-control" value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small fw-600">Hasta</label>
            <input type="date" id="a-hasta" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-12">
            <label class="form-label small fw-600">Área (opcional)</label>
            <select id="a-area" class="form-select">
              <option value="">Todas las áreas</option>
            </select>
          </div>
        </div>

        <div class="p-3 rounded-3 mb-3" style="background:var(--gray-50);border:1px solid var(--gray-200)">
          <div class="small fw-600 mb-1 text-muted">Columnas exportadas:</div>
          <div class="small">Fecha · Trabajador · DNI · Área · Cargo · Empresa · Ingreso · Salida Break · Ingreso Break · Salida · Horas Netas · Horas Prog. · Diferencia · Tipo</div>
        </div>

        <a id="btn-export-asist" href="#"
           class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2 fw-600"
           onclick="exportar('asistencia')">
          <i class="bi bi-file-earmark-spreadsheet-fill fs-5"></i>
          Descargar CSV — Asistencia
        </a>
      </div>
    </div>
  </div>

  <!-- Tips -->
  <div class="col-12">
    <div class="card" style="background:var(--accent-bg);border-color:#fde68a">
      <div class="card-body py-3 px-4">
        <div class="d-flex gap-3 align-items-start">
          <span style="font-size:1.5rem">💡</span>
          <div>
            <div class="fw-600 mb-1">Cómo abrir el CSV en Excel</div>
            <ol class="mb-0 small">
              <li>Descarga el archivo CSV</li>
              <li>Abre Excel → <strong>Datos</strong> → <strong>Desde texto/CSV</strong></li>
              <li>Selecciona el archivo; elige <strong>delimitador: punto y coma (;)</strong></li>
              <li>Confirma <strong>codificación UTF-8</strong> para ver tildes correctamente</li>
              <li>Haz clic en <strong>Cargar</strong></li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
async function loadAreas() {
  const r = await fetch('<?= BASE_URL ?>/api/index.php?/api/areas');
  const j = await r.json();
  ['c-area','a-area'].forEach(id => {
    const sel = document.getElementById(id);
    j.data.forEach(a => {
      const o = document.createElement('option');
      o.value = a.id_area; o.textContent = a.nombre_area;
      sel.appendChild(o.cloneNode(true));
    });
  });
}

function exportar(tipo) {
  let url;
  if (tipo === 'comedor') {
    const p = new URLSearchParams({
      desde: document.getElementById('c-desde').value,
      hasta: document.getElementById('c-hasta').value,
    });
    const area    = document.getElementById('c-area').value;
    const comTipo = document.getElementById('c-tipo').value;
    const persona = document.getElementById('c-persona').value;
    if (area)    p.append('area', area);
    if (comTipo) p.append('tipo', comTipo);
    if (persona && persona !== 'todos') p.append('persona', persona);
    url = `<?= BASE_URL ?>/api/index.php?/api/export/comedor&${p}`;
  } else {
    const p = new URLSearchParams({
      desde: document.getElementById('a-desde').value,
      hasta: document.getElementById('a-hasta').value,
    });
    const area = document.getElementById('a-area').value;
    if (area) p.append('area', area);
    url = `<?= BASE_URL ?>/api/index.php?/api/export/asistencia&${p}`;
  }
  window.location.href = url;
  showToast('success', 'Descarga iniciada', 'El archivo se guardará en tu carpeta de descargas');
  return false;
}

loadAreas();
</script>

<?php require_once dirname(__DIR__) . '/../layout_footer.php'; ?>