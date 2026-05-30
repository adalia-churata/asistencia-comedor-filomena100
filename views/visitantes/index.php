<?php
require_once dirname(__DIR__) . '/../../config/config.php';
$pageTitle = 'Visitantes';
$activeNav = 'visitantes';
require_once dirname(__DIR__) . '/../layout_header.php';
?>

<style>
.vis-card {
  background: #fff; border: 1px solid var(--gray-200);
  border-radius: 12px; padding: 1rem 1.1rem;
  display: flex; align-items: center; gap: .85rem;
  transition: box-shadow .15s, border-color .15s; cursor: pointer;
}
.vis-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); border-color: #93c5fd; }
.vis-avatar {
  width: 44px; height: 44px; border-radius: 10px;
  background: var(--primary-light); display: flex;
  align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;
}
.vis-name    { font-weight: 600; font-size: .9rem; }
.vis-empresa { font-size: .78rem; color: var(--gray-600); }
.vis-dni     { font-size: .72rem; font-family: monospace; color: var(--gray-600); }

.comida-pip {
  display: inline-block; width: 8px; height: 8px;
  border-radius: 50%; margin-right: 2px;
  background: var(--gray-200);
}
.comida-pip.done { background: var(--success); }

/* Historial modal */
#modal-historial .evt-row {
  display: flex; align-items: center; gap: .75rem;
  padding: .55rem 0; border-bottom: 1px solid var(--gray-100);
  font-size: .85rem;
}
#modal-historial .evt-row:last-child { border-bottom: none; }
</style>

<!-- Toolbar -->
<div class="d-flex gap-3 align-items-center mb-4 flex-wrap">
  <div class="input-group" style="max-width:360px">
    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
    <input type="text" id="search-q" class="form-control border-start-0"
           placeholder="Buscar por nombre, DNI o empresa..." oninput="loadVisitantes()">
  </div>
  <div class="ms-auto d-flex gap-2">
    <div class="d-flex align-items-center gap-2 small text-muted">
      <span id="total-count" class="fw-600 text-dark">—</span> visitantes
    </div>
    <button class="btn btn-success fw-600"
            data-bs-toggle="modal" data-bs-target="#modal-form" onclick="openNew()">
      <i class="bi bi-person-plus-fill"></i> Nuevo visitante
    </button>
  </div>
</div>

<!-- Stats rápidas del día -->
<div id="stats-hoy" class="row g-2 mb-4"></div>

<!-- Grid de visitantes -->
<div id="vis-grid" class="row g-2">
  <div class="col-12 text-center py-5 text-muted">
    <i class="bi bi-arrow-clockwise fs-3 d-block mb-2 opacity-25"></i> Cargando...
  </div>
</div>

<!-- ─── Modal: crear / editar visitante ─── -->
<div class="modal fade" id="modal-form" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15)">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <h5 class="modal-title fw-700" id="modal-form-title">Nuevo visitante</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pb-4">
        <input type="hidden" id="f-id">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label small fw-600">Nombre completo *</label>
            <input type="text" id="f-nombre" class="form-control"
                   placeholder="Apellidos y nombres">
          </div>
          <div class="col-12">
            <label class="form-label small fw-600">Empresa / Institución *</label>
            <input type="text" id="f-empresa" class="form-control"
                   placeholder="Empresa u organización">
          </div>
          <div class="col-6">
            <label class="form-label small fw-600">DNI <span class="text-muted">(opcional)</span></label>
            <input type="text" id="f-dni" class="form-control" maxlength="15" placeholder="12345678">
          </div>
          <div class="col-6" id="activo-row" style="display:none">
            <label class="form-label small fw-600">Estado</label>
            <select id="f-activo" class="form-select">
              <option value="1">Activo</option>
              <option value="0">Inactivo</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success fw-600 px-4" onclick="guardar()">
          <i class="bi bi-check-lg"></i> Guardar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ─── Modal: QR del visitante ─── -->
<div class="modal fade" id="modal-qr" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15)">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <h5 class="modal-title fw-700">Código QR</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center px-4 pb-4">
        <div id="qr-vis-nombre" class="fw-700 mb-1"></div>
        <div id="qr-vis-empresa" class="text-muted small mb-3"></div>
        <div id="qr-container"
             style="display:inline-block;background:#fff;padding:1rem;
                    border-radius:12px;border:1px solid var(--gray-200)" class="mb-3">
        </div>
        <div id="qr-code-txt" class="text-muted small mb-3"
             style="font-family:monospace"></div>
        <div class="d-flex gap-2 justify-content-center">
          <button class="btn btn-outline-primary btn-sm" onclick="imprimirQR()">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <button class="btn btn-outline-secondary btn-sm" onclick="copiarCodigo()">
            <i class="bi bi-clipboard"></i> Copiar código
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ─── Modal: historial de un visitante ─── -->
<div class="modal fade" id="modal-historial" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.15)">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <div>
          <h5 class="modal-title fw-700" id="hist-nombre">Historial</h5>
          <small class="text-muted" id="hist-empresa"></small>
        </div>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pb-4">
        <div id="hist-body">
          <div class="text-center py-4 text-muted">Cargando historial...</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- QRCode.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
const EVT_LABELS = {
  DESAYUNO:'☕ Desayuno', ALMUERZO:'🍽️ Almuerzo', CENA:'🌙 Cena',
  INGRESO:'🟢 Ingreso', SALIDA:'🔴 Salida',
};
const EVT_BADGE = {
  DESAYUNO:'badge-DESAYUNO', ALMUERZO:'badge-ALMUERZO', CENA:'badge-CENA',
  INGRESO:'badge-INGRESO', SALIDA:'badge-SALIDA_TRABAJO',
};

let currentQRCode = null;
let currentQRId   = null;

// ── Cargar y renderizar visitantes ────────────────────────────
async function loadVisitantes() {
  const q = document.getElementById('search-q').value.trim();
  const grid = document.getElementById('vis-grid');
  grid.innerHTML = '<div class="col-12 text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando...</div>';

  try {
    const r = await fetch(`<?= BASE_URL ?>/api/index.php?/api/visitantes&q=${encodeURIComponent(q)}`);
    const j = await r.json();
    const data = j.data || [];

    document.getElementById('total-count').textContent = data.length;

    if (!data.length) {
      grid.innerHTML = `<div class="col-12 text-center py-5 text-muted">
        <i class="bi bi-person-x fs-2 d-block mb-2 opacity-25"></i>
        ${q ? 'Sin resultados para "'+q+'"' : 'No hay visitantes registrados'}
      </div>`;
      return;
    }

    grid.innerHTML = data.map(v => {
      const diasStr = v.dias_visitados > 0
        ? `<span class="badge bg-light text-dark border" style="font-size:.68rem">${v.dias_visitados} visita${v.dias_visitados>1?'s':''}</span>`
        : '';
      const ultimaStr = v.ultima_visita
        ? `<span class="text-muted" style="font-size:.72rem">Última: ${v.ultima_visita.split(' ')[0]}</span>`
        : '';

      return `<div class="col-12 col-sm-6 col-xl-4">
        <div class="vis-card" onclick="verHistorial(${v.id_visitante})">
          <div class="vis-avatar">🪪</div>
          <div class="flex-grow-1 min-w-0">
            <div class="vis-name text-truncate">${v.nombre}</div>
            <div class="vis-empresa text-truncate">🏢 ${v.empresa}</div>
            ${v.dni ? `<div class="vis-dni">DNI: ${v.dni}</div>` : ''}
            <div class="d-flex align-items-center gap-2 mt-1">${diasStr} ${ultimaStr}</div>
          </div>
          <div class="d-flex flex-column gap-1">
            <button class="btn btn-xs btn-outline-secondary"
                    onclick="event.stopPropagation();editVis(${v.id_visitante})"
                    data-bs-toggle="modal" data-bs-target="#modal-form"
                    title="Editar">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-xs btn-outline-primary"
                    onclick="event.stopPropagation();mostrarQR(${v.id_visitante},'${v.nombre.replace(/'/g,"\\'")}','${v.empresa.replace(/'/g,"\\'")}');
                             bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-qr')).show()"
                    title="Ver QR">
              <i class="bi bi-qr-code"></i>
            </button>
          </div>
        </div>
      </div>`;
    }).join('');

  } catch(e) {
    grid.innerHTML = `<div class="col-12 text-center py-4 text-danger">Error al cargar visitantes</div>`;
  }
}

// ── Estadísticas del día ──────────────────────────────────────
async function loadStatsHoy() {
  try {
    const r = await fetch('<?= BASE_URL ?>/api/index.php?/api/dashboard');
    const j = await r.json();
    if (!j.success) return;
    const d = j.data;
    document.getElementById('stats-hoy').innerHTML = `
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon" style="background:#fffbeb">☕</div>
          <div class="stat-value">${d.desayunos_visitantes||0}</div>
          <div class="stat-label">Desayunos visitantes hoy</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon" style="background:#f5f3ff">🍽️</div>
          <div class="stat-value">${d.almuerzos_visitantes||0}</div>
          <div class="stat-label">Almuerzos visitantes hoy</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon" style="background:#eff6ff">🌙</div>
          <div class="stat-value">${d.cenas_visitantes||0}</div>
          <div class="stat-label">Cenas visitantes hoy</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card">
          <div class="stat-icon" style="background:#f0fdf4">🪪</div>
          <div class="stat-value">${d.total_visitantes||0}</div>
          <div class="stat-label">Visitantes atendidos hoy</div>
        </div>
      </div>`;
  } catch(e) {}
}

// ── CRUD ─────────────────────────────────────────────────────
function openNew() {
  document.getElementById('modal-form-title').textContent = 'Nuevo visitante';
  document.getElementById('f-id').value      = '';
  document.getElementById('f-nombre').value  = '';
  document.getElementById('f-empresa').value = '';
  document.getElementById('f-dni').value     = '';
  document.getElementById('activo-row').style.display = 'none';
}

async function editVis(id) {
  try {
    const r = await fetch(`<?= BASE_URL ?>/api/index.php?/api/visitantes/${id}`);
    const j = await r.json();
    if (!j.success) { showToast('error','No se pudo cargar el visitante'); return; }
    const v = j.data;
    document.getElementById('modal-form-title').textContent = 'Editar visitante';
    document.getElementById('f-id').value      = v.id_visitante;
    document.getElementById('f-nombre').value  = v.nombre;
    document.getElementById('f-empresa').value = v.empresa;
    document.getElementById('f-dni').value     = v.dni || '';
    document.getElementById('f-activo').value  = v.activo;
    document.getElementById('activo-row').style.display = 'block';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-form')).show();
  } catch(e) { showToast('error','Error de conexión'); }
}

async function guardar() {
  const id      = document.getElementById('f-id').value;
  const nombre  = document.getElementById('f-nombre').value.trim();
  const empresa = document.getElementById('f-empresa').value.trim();
  const dni     = document.getElementById('f-dni').value.trim();

  if (!nombre)  { showToast('error','El nombre es obligatorio'); return; }
  if (!empresa) { showToast('error','La empresa es obligatoria'); return; }

  const payload = { nombre, empresa, dni: dni || null };
  if (id) payload.activo = document.getElementById('f-activo').value;

  const url    = id ? `<?= BASE_URL ?>/api/index.php?/api/visitantes/${id}` : `<?= BASE_URL ?>/api/index.php?/api/visitantes`;
  const method = id ? 'PUT' : 'POST';

  try {
    const r = await fetch(url, { method, headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const j = await r.json();
    if (j.success) {
      bootstrap.Modal.getInstance(document.getElementById('modal-form')).hide();
      showToast('success', j.message);
      loadVisitantes();
    } else {
      showToast('error', j.message);
    }
  } catch(e) { showToast('error','Error de conexión'); }
}

// ── QR ───────────────────────────────────────────────────────
function mostrarQR(id, nombre, empresa) {
  currentQRId = id;
  document.getElementById('qr-vis-nombre').textContent  = nombre;
  document.getElementById('qr-vis-empresa').textContent = '🏢 ' + empresa;
  document.getElementById('qr-code-txt').textContent    = 'Código: ' + id;
  const cont = document.getElementById('qr-container');
  cont.innerHTML = '';
  currentQRCode = new QRCode(cont, {
    text: String(id),
    width: 200, height: 200,
    colorDark: '#0f4c81', colorLight: '#fff',
    correctLevel: QRCode.CorrectLevel.H,
  });
}

function imprimirQR() {
  const cvs    = document.querySelector('#qr-container canvas');
  const img    = cvs?.toDataURL('image/png') ?? '';
  const nombre = document.getElementById('qr-vis-nombre').textContent;
  const emp    = document.getElementById('qr-vis-empresa').textContent;
  const cod    = document.getElementById('qr-code-txt').textContent;
  const w      = window.open('','_blank');
  w.document.write(`<html><head><title>QR ${nombre}</title>
    <style>body{text-align:center;font-family:sans-serif;padding:2rem}
    h3{margin-bottom:.5rem}p{color:#666;font-size:.9rem}</style></head>
    <body><h3>${nombre}</h3><p>${emp}</p>
    <img src="${img}" style="width:200px;height:200px;border-radius:12px">
    <p style="font-family:monospace;margin-top:.75rem">${cod}</p>
    <script>window.print();window.close();<\/script></body></html>`);
  w.document.close();
}

function copiarCodigo() {
  navigator.clipboard.writeText(String(currentQRId)).then(() => {
    showToast('success', 'Código copiado: ' + currentQRId);
  });
}

// ── Historial ────────────────────────────────────────────────
async function verHistorial(id) {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-historial')).show();
  document.getElementById('hist-body').innerHTML =
    '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Cargando...</div>';

  try {
    const r = await fetch(`<?= BASE_URL ?>/api/index.php?/api/visitantes/${id}/historial`);
    const j = await r.json();
    if (!j.success) throw new Error(j.message);

    const v = j.data.visitante;
    document.getElementById('hist-nombre').textContent  = v.nombre;
    document.getElementById('hist-empresa').textContent = '🏢 ' + v.empresa + (v.dni ? ' · DNI: '+v.dni : '');

    const evts = j.data.eventos;
    if (!evts.length) {
      document.getElementById('hist-body').innerHTML =
        '<div class="text-center py-4 text-muted">Sin eventos registrados</div>';
      return;
    }

    document.getElementById('hist-body').innerHTML = evts.map(e => {
      const dt   = e.fecha_hora.split(' ');
      const badge = EVT_BADGE[e.tipo_evento] || '';
      const lbl   = EVT_LABELS[e.tipo_evento] || e.tipo_evento;
      return `<div class="evt-row">
        <span class="evt-badge ${badge}" style="min-width:110px;text-align:center">${lbl}</span>
        <span style="font-family:monospace;font-size:.8rem;color:var(--gray-600)">${dt[0]}</span>
        <span style="font-family:monospace;font-size:.85rem;font-weight:600">${dt[1]?.substring(0,8)||''}</span>
        ${e.observacion ? `<small class="text-muted ms-auto">${e.observacion}</small>` : ''}
      </div>`;
    }).join('');

  } catch(e) {
    document.getElementById('hist-body').innerHTML =
      `<div class="text-center py-4 text-danger">${e.message}</div>`;
  }
}

// ── Init ─────────────────────────────────────────────────────
loadStatsHoy();
loadVisitantes();
</script>

<?php require_once dirname(__DIR__) . '/../layout_footer.php'; ?>