</div><!-- /.page-body -->
</div><!-- /#main -->

<!-- Toast container -->
<div id="scan-toast-container"></div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Live clock ───────────────────────────────────────────────
function updateClock() {
  const el = document.getElementById('live-clock');
  if (el) el.textContent = new Date().toLocaleTimeString('es-PE', {hour12: false});
}
updateClock();
setInterval(updateClock, 1000);

// ── Sidebar toggle (mobile) ───────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}

// ── Toast notifications ───────────────────────────────────────
function showToast(tipo, titulo, subtitulo = '', duracion = 4000) {
  const c    = document.getElementById('scan-toast-container');
  const icon = tipo === 'success' ? '✅' : '❌';
  const div  = document.createElement('div');
  div.className = `scan-toast ${tipo}`;
  div.innerHTML = `
    <div class="t-icon">${icon}</div>
    <div class="t-body">
      <div class="t-title">${titulo}</div>
      ${subtitulo ? `<div class="t-sub">${subtitulo}</div>` : ''}
    </div>`;
  c.appendChild(div);
  setTimeout(() => div.remove(), duracion);
}

// ── Sound feedback ────────────────────────────────────────────
function playBeep(ok = true) {
  try {
    const ctx  = new (window.AudioContext || window.webkitAudioContext)();
    const osc  = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.frequency.value = ok ? 880 : 260;
    osc.type = ok ? 'sine' : 'square';
    gain.gain.setValueAtTime(.4, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + .35);
    osc.start(ctx.currentTime);
    osc.stop(ctx.currentTime + .35);
  } catch(e) {}
}
</script>
</body>
</html>