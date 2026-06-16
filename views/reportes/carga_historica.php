<?php
require_once dirname(__DIR__) . '/../config/config.php';
$pageTitle = 'Carga de Registros Históricos';
$activeNav = 'carga-historica';
require_once dirname(__DIR__) . '/layouts/header.php';
?>

<style>
/* Estilos para el contenedor flotante de sugerencias por fila */
.ac-row-container {
  position: relative;
}
.ac-row-list {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  z-index: 1050;
  background: #fff;
  border: 1px solid var(--gray-200);
  border-radius: 0 0 8px 8px;
  max-height: 200px;
  overflow-y: auto;
  box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.ac-row-item {
  padding: .5rem .75rem;
  cursor: pointer;
  font-size: .85rem;
  border-bottom: 1px solid var(--gray-100);
}
.ac-row-item:hover, .ac-row-item.selected {
  background: var(--primary-light);
  color: var(--primary);
}
.ac-row-item:last-child { border-bottom: none; }
</style>

<div class="card mb-4">
  <div class="card-header bg-white py-3 px-4">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.4rem">📝</span>
      <span class="fw-700 fs-6">Transcribir Hojas Físicas al Sistema</span>
    </div>
    <small class="text-muted">Busca por el nombre de la persona. Usa la tecla <strong>F2</strong> para añadir filas y completa la fecha y hora de la hoja.</small>
  </div>
  <div class="card-body">
    <div class="table-responsive" style="overflow-y: visible; min-height: 350px;">
      <table class="table table-bordered align-middle" id="tabla-historico">
        <thead class="table-light">
          <tr>
            <th style="width: 15%">Tipo Persona *</th>
            <th style="width: 35%">Escribe Nombre Completo *</th>
            <th style="width: 15%">Fecha *</th>
            <th style="width: 13%">Hora *</th>
            <th style="width: 17%">Tipo Marcación / Consumo *</th>
            <th style="width: 5%"></th>
          </tr>
        </thead>
        <tbody id="lote-tbody">
          <!-- Filas automáticas -->
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-outline-primary fw-600" onclick="agregarFila()">
        <i class="bi bi-plus-circle"></i> Añadir Fila (F2)
      </button>
      <button class="btn btn-success fw-600 px-5" onclick="guardarLoteHistorico()">
        <i class="bi bi-cloud-upload-fill"></i> Guardar Todo el Bloque
      </button>
    </div>
  </div>
</div>

<script>
let contadorFilas = 0;

document.addEventListener('DOMContentLoaded', () => {
  for(let i=0; i<3; i++) agregarFila();
  
  document.addEventListener('keydown', (e) => {
    if (e.key === 'F2') { e.preventDefault(); agregarFila(); }
  });
});

function agregarFila() {
  contadorFilas++;
  const tbody = document.getElementById('lote-tbody');
  const tr = document.createElement('tr');
  tr.id = `fila-${contadorFilas}`;
  
  tr.innerHTML = `
    <td>
      <select class="form-select form-select-sm" onchange="cambiarTipoPersona(${contadorFilas}, this.value)" data-campo="tipo_persona" id="tipo-${contadorFilas}">
        <option value="TRABAJADOR">Trabajador</option>
        <option value="VISITANTE">Visitante</option>
      </select>
    </td>
    <td class="ac-row-container">
      <input type="hidden" data-campo="id_persona" id="id-val-${contadorFilas}">
      <input type="text" class="form-control form-control-sm" 
             placeholder="Escribe apellido o nombre..." 
             id="txt-buscar-${contadorFilas}" 
             autocomplete="off"
             oninput="buscarPersonaEnVivo(${contadorFilas}, this.value)"
             required>
      <div id="suggestions-${contadorFilas}" class="ac-row-list d-none"></div>
    </td>
    <td>
      <input type="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" data-campo="fecha" required>
    </td>
    <td>
      <input type="time" class="form-control form-control-sm" placeholder="HH:MM" data-campo="hora" required>
    </td>
    <td>
      <select class="form-select form-select-sm" id="select-evento-${contadorFilas}" data-campo="tipo_evento">
        <option value="INGRESO">🟢 Ingreso Trabajo</option>
        <option value="SALIDA_BREAK">🟡 Salida a Break</option>
        <option value="REGRESO_BREAK">🔵 Retorno de Break</option>
        <option value="SALIDA_TRABAJO">🔴 Salida de Trabajo</option>
        <option value="DESAYUNO">☕ Desayuno</option>
        <option value="ALMUERZO">🍽️ Almuerzo</option>
        <option value="CENA">🌙 Cena</option>
      </select>
    </td>
    <td class="text-center">
      <button class="btn btn-sm btn-outline-danger border-0" onclick="eliminarFila(${contadorFilas})">
        <i class="bi bi-trash"></i>
      </button>
    </td>
  `;
  tbody.appendChild(tr);
  document.getElementById(`txt-buscar-${contadorFilas}`).focus();
}

function cambiarTipoPersona(id, valor) {
  document.getElementById(`id-val-${id}`).value = '';
  document.getElementById(`txt-buscar-${id}`).value = '';
  document.getElementById(`suggestions-${id}`).classList.add('d-none');
  
  const selectEvt = document.getElementById(`select-evento-${id}`);
  
  if (valor === 'VISITANTE') {
    selectEvt.innerHTML = `
      <option value="DESAYUNO">☕ Desayuno</option>
      <option value="ALMUERZO">🍽️ Almuerzo</option>
      <option value="CENA">🌙 Cena</option>
    `;
  } else {
    selectEvt.innerHTML = `
      <option value="INGRESO">🟢 Ingreso Trabajo</option>
      <option value="SALIDA_BREAK">🟡 Salida a Break</option>
      <option value="REGRESO_BREAK">🔵 Retorno de Break</option>
      <option value="SALIDA_TRABAJO">🔴 Salida de Trabajo</option>
      <option value="DESAYUNO">☕ Desayuno</option>
      <option value="ALMUERZO">🍽️ Almuerzo</option>
      <option value="CENA">🌙 Cena</option>
    `;
  }
}

async function buscarPersonaEnVivo(id, texto) {
  const list = document.getElementById(`suggestions-${id}`);
  const tipo = document.getElementById(`tipo-${id}`).value;
  
  if (texto.trim().length < 2) {
    list.classList.add('d-none');
    return;
  }

  const endpoint = tipo === 'TRABAJADOR' ? 'trabajadores' : 'visitantes';
  const queryParam = tipo === 'TRABAJADOR' ? 'q' : 'search';

  try {
    const r = await fetch(`/asistencia-filomena-100/api/index.php?action=${endpoint}&${queryParam}=${encodeURIComponent(texto.trim())}`, {
      method: 'GET',
      headers: { 'Accept': 'application/json', 'ngrok-skip-browser-warning': 'true' }
    });
    const j = await r.json();
    const data = j.data || [];

    if (!data.length) {
      list.innerHTML = '<div class="p-2 text-muted small text-center bg-white">Sin coincidencias</div>';
      list.classList.remove('d-none');
      return;
    }

    list.innerHTML = data.map(p => {
      const dbId = tipo === 'TRABAJADOR' ? p.id_trabajador : p.id_visitante;
      const dbNombre = tipo === 'TRABAJADOR' ? p.nombre_completo : p.nombre;
      const metaInfo = p.nombre_area || p.empresa || '—';

      // ⚠️ MUESTRA EL BADGE DE INACTIVO: Si el trabajador tiene activo == 0, le inyectamos la alerta visual
      const estadoStr = (tipo === 'TRABAJADOR' && p.activo !== undefined && parseInt(p.activo) === 0) 
        ? ' <span class="badge bg-danger" style="font-size:.65rem; padding: .15rem .3rem;">Inactivo</span>' 
        : '';

      return `
        <div class="ac-row-item" onclick="seleccionarPersonaFila(${id}, ${dbId}, '${dbNombre.replace(/'/g, "\\'")}')">
          <strong>${dbNombre}</strong> ${estadoStr} <span class="text-muted small">(${metaInfo})</span>
        </div>
      `;
    }).join('');
    
    list.classList.remove('d-none');
  } catch (e) {
    console.error(e);
  }
}

function seleccionarPersonaFila(filaId, personaId, nombre) {
  document.getElementById(`id-val-${filaId}`).value = personaId;
  document.getElementById(`txt-buscar-${filaId}`).value = nombre;
  document.getElementById(`suggestions-${filaId}`).classList.add('d-none');
}

function eliminarFila(id) {
  const fila = document.getElementById(`fila-${id}`);
  if (fila) fila.remove();
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.ac-row-container')) {
    document.querySelectorAll('.ac-row-list').forEach(l => l.classList.add('d-none'));
  }
});

// ── GUARDAR EL BLOQUE COMPLETO DE FILAS EN LA API ─────────────────
async function guardarLoteHistorico() {
  const tbody = document.getElementById('lote-tbody');
  const filas = tbody.querySelectorAll('tr');
  const registros = [];
  let validacionCompleta = true;

  filas.forEach((fila, index) => {
    const num = index + 1;
    const tipo_persona = fila.querySelector('[data-campo="tipo_persona"]').value;
    const id_persona = fila.querySelector('[data-campo="id_persona"]').value;
    const nombre_txt = fila.querySelector('input[type="text"]').value;
    const fecha = fila.querySelector('[data-campo="fecha"]').value;
    const hora = fila.querySelector('[data-campo="hora"]').value;
    const tipo_evento = fila.querySelector('[data-campo="tipo_evento"]').value;

    if (nombre_txt !== '') {
      if (!id_persona) {
        alert(`❌ Fila ${num}: Debes seleccionar un nombre válido de la lista flotante sugerida.`);
        validacionCompleta = false;
        return;
      }
      if (!hora) {
        alert(`❌ Fila ${num}: La hora es obligatoria.`);
        validacionCompleta = false;
        return;
      }
      registros.push({ tipo_persona, id_persona, fecha, hora, tipo_evento });
    }
  });

  if (!validacionCompleta) return;
  if (registros.length === 0) {
    alert("❌ Digita y selecciona al menos una persona para guardar.");
    return;
  }

  try {
    const res = await fetch('/asistencia-filomena-100/api/index.php?action=carga-historica', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'ngrok-skip-browser-warning': 'true' },
      body: JSON.stringify({ registros })
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(`✅ Procesado con éxito:\n${json.message}`);
      tbody.innerHTML = '';
      for(let i=0; i<3; i++) agregarFila();
    } else {
        alert("⚠️ Error:\n" + json.message);
    }
} catch (e) {
    console.error(e);alert("Error de conexión.");
}
}
</script>
<?php require_once dirname(__DIR__) . '/layouts/footer.php'; ?>