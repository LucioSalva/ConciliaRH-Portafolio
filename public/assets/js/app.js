/**
 * ConciliaRH — comportamiento de la interfaz (ES2022, sin frameworks).
 * Principios: mejora progresiva (todo funciona sin JS salvo el sondeo de procesos),
 * sin HTML inyectado desde datos (sólo textContent), CSRF en toda petición mutante.
 */
import { api } from './modules/api.js';
import { toast } from './modules/toast.js';
import { initNotifications } from './modules/notifications.js';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/* ---------- Sidebar en móvil ---------- */
function initSidebar() {
  const sidebar = $('#sidebar');
  const toggle = $('[data-sidebar-toggle]');
  if (!sidebar || !toggle) return;
  let backdrop = null;
  const close = () => {
    sidebar.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    backdrop?.remove();
    backdrop = null;
  };
  const open = () => {
    sidebar.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
    backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    backdrop.addEventListener('click', close);
    document.body.appendChild(backdrop);
    sidebar.querySelector('a, button')?.focus();
  };
  toggle.addEventListener('click', () => (sidebar.classList.contains('is-open') ? close() : open()));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sidebar.classList.contains('is-open')) { close(); toggle.focus(); } });
}

/* ---------- Navegación contraíble en escritorio (riel de iconos) ----------
   Preferencia del navegador (localStorage, con try/catch); sin preferencia, contraída entre 992 y 1199 px (tableta horizontal). */
function initSidebarRail() {
  const btn = $('[data-sidebar-collapse]');
  if (!btn) return;
  const KEY = 'conciliarh.nav.contraida';
  const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
  const write = (v) => { try { localStorage.setItem(KEY, v); } catch { /* sin almacenamiento: sólo esta página */ } };
  const apply = (on) => {
    document.body.classList.toggle('nav-rail', on);
    btn.setAttribute('aria-pressed', String(on));
    const label = on ? 'Expandir la navegación' : 'Contraer la navegación';
    btn.setAttribute('aria-label', label);
    btn.title = label;
  };
  const pref = read();
  apply(pref === null ? window.matchMedia('(min-width: 992px) and (max-width: 1199.98px)').matches : pref === '1');
  btn.addEventListener('click', () => {
    const on = !document.body.classList.contains('nav-rail');
    apply(on);
    write(on ? '1' : '0');
  });
}

/* ---------- Matriz de permisos: búsqueda y marcar por módulo ----------
   Los permisos exclusivos de GOD llegan deshabilitados: «Marcar todos» nunca los toca (el servidor también los rechaza). */
function initPermMatrix() {
  const form = $('[data-perm-matrix]');
  if (!form) return;
  const q = $('[data-perm-search]', form);
  const total = $('[data-perm-count]', form);
  const norm = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const refresh = () => {
    const term = norm(q?.value.trim() ?? '');
    let shown = 0;
    $$('[data-perm-module]', form).forEach((mod) => {
      const items = $$('[data-perm-item]', mod);
      let visible = 0;
      items.forEach((it) => { const ok = !term || norm(it.textContent).includes(term); it.hidden = !ok; if (ok) visible += 1; });
      mod.hidden = visible === 0;
      shown += visible;
      const boxes = items.map((it) => $('input[type=checkbox]', it));
      const c = $('[data-perm-module-count]', mod);
      if (c) c.textContent = `(${boxes.filter((b) => b.checked).length} de ${boxes.length})`;
      // El texto se calcula sobre el MISMO conjunto sobre el que actúa el botón: visibles y editables
      const t = $('[data-perm-toggle]', mod);
      const editable = items.filter((it) => !it.hidden).map((it) => $('input[type=checkbox]', it)).filter((b) => b && !b.disabled);
      if (t) { t.hidden = editable.length === 0; t.textContent = editable.every((b) => b.checked) ? 'Quitar todos' : 'Marcar todos'; }
    });
    if (total) total.textContent = term ? `${shown} permiso(s) coinciden` : '';
  };
  form.addEventListener('change', refresh);
  q?.addEventListener('input', refresh);
  q?.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  $$('[data-perm-toggle]', form).forEach((btn) => btn.addEventListener('click', () => {
    const mod = btn.closest('[data-perm-module]');
    const boxes = $$('[data-perm-item]:not([hidden]) input[type=checkbox]:not(:disabled)', mod);
    const on = !boxes.every((b) => b.checked);
    boxes.forEach((b) => { b.checked = on; });
    refresh();
  }));
  refresh();
}

/* ---------- Confirmación de operaciones sensibles ----------
   <form data-confirm="Mensaje" data-confirm-title="Título" data-confirm-variant="danger"
         data-confirm-reason="motivo">  (reason: exige texto y lo copia al campo indicado) */
/**
 * Variantes del modal de confirmación (docs/DESIGN_SYSTEM.md §28): cada una con icono, filete, botón y texto propios,
 * para que la gravedad no dependa sólo del color. Un formulario elige con data-confirm-variant y puede ajustar
 * data-confirm-accept (texto del botón) y data-confirm-note (nota bajo el mensaje).
 */
const CONFIRM_VARIANTS = {
  confirm: { header: 'modal-header--wine', icon: 'bi-check2-circle', button: 'btn-confirm', accept: 'Confirmar', note: '' },
  danger: { header: 'modal-header--danger', icon: 'bi-exclamation-octagon', button: 'btn-delete', accept: 'Sí, continuar', note: '' },
  warning: { header: 'modal-header--warning', icon: 'bi-exclamation-triangle', button: 'btn-confirm', accept: 'Continuar', note: '' },
  info: { header: 'modal-header--info', icon: 'bi-info-circle', button: 'btn-confirm', accept: 'Entendido', note: '' },
  approval: { header: 'modal-header--approval', icon: 'bi-people', button: 'btn-confirm', accept: 'Enviar a aprobación',
    note: 'No se aplica todavía: otra persona con permiso de aprobación deberá revisarla. Usted no puede aprobar su propia solicitud.' },
};

function initConfirm() {
  const modalEl = $('#confirmModal');
  if (!modalEl || !window.bootstrap) return;
  const modal = new window.bootstrap.Modal(modalEl);
  const title = $('[data-confirm-title-slot]', modalEl);
  const body = $('[data-confirm-body-slot]', modalEl);
  const reasonWrap = $('[data-confirm-reason-wrap]', modalEl);
  const reason = $('#confirmReason', modalEl);
  const reasonError = $('#confirmReasonError', modalEl);
  const accept = $('[data-confirm-accept]', modalEl);
  const header = $('.modal-header', modalEl);
  const icon = $('[data-confirm-icon]', modalEl);
  const note = $('[data-confirm-note]', modalEl);
  const acceptLabel = $('[data-confirm-accept-label]', modalEl);
  let pending = null;
  let returnFocus = null; // el modal se abre por código: Bootstrap no devuelve el foco, lo hacemos aquí
  let submitter = null;   // botón que envió el formulario: su name/value deben viajar al confirmar

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
    e.preventDefault();
    pending = form;
    submitter = e.submitter && form.contains(e.submitter) ? e.submitter : null;
    returnFocus = submitter ?? (form.contains(document.activeElement) ? document.activeElement : form.querySelector('[type="submit"]'));
    title.textContent = form.dataset.confirmTitle || 'Confirmar operación';
    body.textContent = form.dataset.confirm;
    const v = Object.hasOwn(CONFIRM_VARIANTS, form.dataset.confirmVariant ?? '') ? CONFIRM_VARIANTS[form.dataset.confirmVariant] : CONFIRM_VARIANTS.confirm;
    header.classList.remove(...Object.values(CONFIRM_VARIANTS).map((x) => x.header));
    header.classList.add(v.header);
    icon.className = `bi ${v.icon} modal-title__icon`;
    accept.className = `btn-action ${v.button}`;
    acceptLabel.textContent = form.dataset.confirmAccept || v.accept;
    note.textContent = form.dataset.confirmNote || v.note;
    note.hidden = note.textContent === '';
    const needsReason = Boolean(form.dataset.confirmReason);
    reasonWrap.hidden = !needsReason;
    reason.value = '';
    reason.required = needsReason;
    setReasonError(false);
    modal.show();
  });

  // El error del motivo se anuncia (aria-invalid + aria-describedby) y sólo se muestra cuando existe
  function setReasonError(on) {
    reason.classList.toggle('is-invalid', on);
    if (on) reason.setAttribute('aria-invalid', 'true'); else reason.removeAttribute('aria-invalid');
    // El error sólo se referencia cuando existe (un texto referido se anuncia aunque esté oculto)
    reason.setAttribute('aria-describedby', on ? 'confirmReasonHelp confirmReasonError' : 'confirmReasonHelp');
    if (reasonError) reasonError.hidden = !on;
  }

  modalEl.addEventListener('shown.bs.modal', () => (reasonWrap.hidden ? accept : reason).focus());
  modalEl.addEventListener('hidden.bs.modal', () => {
    // Cancelado (Esc, «Cancelar» o la X): el foco vuelve al botón que abrió la confirmación
    if (pending && returnFocus?.isConnected) returnFocus.focus();
    pending = null;
    returnFocus = null;
  });

  accept.addEventListener('click', () => {
    if (!pending) return;
    const field = pending.dataset.confirmReason;
    if (field) {
      const text = reason.value.trim();
      if (text.length < 5) {
        setReasonError(true);
        reason.focus();
        return;
      }
      let input = pending.querySelector(`input[name="${CSS.escape(field)}"]`);
      if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = field;
        pending.appendChild(input);
      }
      input.value = text;
    }
    const form = pending;
    const by = submitter;
    returnFocus = null;
    submitter = null;
    pending = null;
    modal.hide();
    // La marca dura sólo este envío: una descarga (sin navegación) o volver con el historial vuelve a pedir confirmación
    form.dataset.confirmed = '1';
    if (form.requestSubmit) form.requestSubmit(by ?? undefined); else form.submit();
    delete form.dataset.confirmed;
  });
}

/* ---------- Selección múltiple (revisión masiva): conteo y monto sólo informativos; el servidor revalida todo ---------- */
function initSelection() {
  $$('[data-bulk-form]').forEach((form) => {
    const boxes = () => $$('input[name="items[]"]', form);
    const count = $('[data-select-count]', form);
    const sum = $('[data-select-sum]', form);
    const all = $('[data-select-all]', form);
    const refresh = () => {
      const on = boxes().filter((b) => b.checked);
      if (count) count.textContent = String(on.length);
      if (sum) {
        const cents = on.reduce((acc, b) => acc + Math.round(Number(b.dataset.importe || 0) * 100), 0);
        sum.textContent = (cents / 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      if (all) all.checked = on.length > 0 && on.length === boxes().length;
    };
    all?.addEventListener('change', () => { boxes().forEach((b) => { b.checked = all.checked; }); refresh(); });
    form.addEventListener('change', (e) => { if (e.target.name === 'items[]') refresh(); });
    refresh();
  });
}

/* ---------- Toasts iniciales (mensajes flash del servidor) ---------- */
function initFlashes() {
  $$('[data-flash]').forEach((el) => toast.adopt(el));
}

/* ---------- Mostrar / ocultar contraseña ---------- */
function initPasswordToggles() {
  $$('[data-toggle-password]').forEach((btn) => {
    const input = document.getElementById(btn.dataset.togglePassword);
    if (!input) return;
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', String(show));
      btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
      btn.querySelector('i')?.classList.toggle('bi-eye', !show);
      btn.querySelector('i')?.classList.toggle('bi-eye-slash', show);
    });
  });
}

/* ---------- Formularios: evitar doble envío y mostrar cargador ---------- */
/* Al volver con «Atrás» (bfcache) o si se detuvo la navegación, ningún formulario debe quedarse «ocupado» */
function resetBusyState() {
  $$('form[aria-busy="true"]').forEach((f) => f.removeAttribute('aria-busy'));
  $$('.search.is-loading').forEach((b) => { b.classList.remove('is-loading'); const st = $('.search__status', b); if (st) st.textContent = ''; });
  $$('[data-busy-disabled]').forEach((btn) => { btn.disabled = false; btn.removeAttribute('data-busy-disabled'); $$('.loader', btn).forEach((l) => l.remove()); });
}
window.addEventListener('pageshow', (e) => { if (e.persisted) resetBusyState(); });

function initBusyForms() {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || !form.hasAttribute('data-busy')) return;
    const btn = form.querySelector('[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.dataset.busyDisabled = '1';   // sólo éstos se reactivan al volver con «Atrás»
      const spinner = document.createElement('span');
      spinner.className = 'loader';
      spinner.setAttribute('aria-hidden', 'true');
      btn.prepend(spinner);
    }
    form.setAttribute('aria-busy', 'true');
  });
}

/* ---------- Filtros: auto-envío y buscador ---------- */
function initFilters() {
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.requestSubmit()));
  $$('[data-search-clear]').forEach((btn) => {
    const input = document.getElementById(btn.dataset.searchClear);
    if (!input) return;
    const sync = () => { btn.hidden = input.value === ''; };
    sync();
    input.addEventListener('input', sync);
    // Limpiar avisa a los filtros locales (input) y sólo envía si el buscador pertenece a un formulario de filtros (GET)
    btn.addEventListener('click', () => {
      input.value = ''; sync(); input.dispatchEvent(new Event('input', { bubbles: true }));
      if (input.form && (input.form.method || 'get').toLowerCase() === 'get') input.form.requestSubmit();
      input.focus();
    });
    // Esc dentro del buscador limpia (teclado); el botón de limpiar sigue siendo la vía visible
    input.addEventListener('keydown', (e) => { if (e.key === 'Escape' && input.value !== '') { e.preventDefault(); btn.click(); } });
  });
  // Búsqueda en curso: el mismo indicador en todos los buscadores (icono girando + estado para lectores de pantalla)
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || e.defaultPrevented || (form.method || 'get').toLowerCase() !== 'get') return;
    const box = form.querySelector('.search');
    if (!box) return;
    box.classList.add('is-loading');
    form.setAttribute('aria-busy', 'true');
    let st = box.querySelector('.search__status');
    if (!st) { st = document.createElement('span'); st.className = 'search__status'; st.setAttribute('role', 'status'); box.appendChild(st); }
    st.textContent = 'Buscando…';
  });
}

/* ---------- Zona de carga (arrastrar y soltar como complemento del input estándar) ---------- */
function initDropzones() {
  $$('[data-dropzone]').forEach((zone) => {
    const input = $('input[type="file"]', zone);
    const status = $('.dropzone__status', zone);
    const maxBytes = Number(zone.dataset.maxBytes) || 0;
    if (!input) return;
    const show = () => {
      const file = input.files?.[0];
      status.className = 'dropzone__status mb-0';
      if (!file) { status.textContent = ''; return; }
      const ext = file.name.split('.').pop().toLowerCase();
      const mb = (file.size / 1048576).toFixed(1);
      if (!['xls', 'xlsx', 'xlsm'].includes(ext)) {
        status.classList.add('dropzone__status--error');
        status.textContent = `«${file.name}»: extensión no permitida.`;
      } else if (maxBytes && file.size > maxBytes) {
        status.classList.add('dropzone__status--error');
        status.textContent = `«${file.name}» (${mb} MB) excede el máximo permitido.`;
      } else if (file.size === 0) {
        status.classList.add('dropzone__status--error');
        status.textContent = `«${file.name}» está vacío.`;
      } else {
        status.classList.add('dropzone__status--ok');
        status.textContent = `Listo para cargar: «${file.name}» (${mb} MB). El servidor volverá a validarlo.`;
      }
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('is-dragover'); }));
    // dragleave también se dispara al pasar sobre elementos hijos: sólo se quita el resaltado al salir de la zona
    zone.addEventListener('dragleave', (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('is-dragover'); });
    zone.addEventListener('drop', (e) => { e.preventDefault(); zone.classList.remove('is-dragover'); });
    zone.addEventListener('drop', (e) => {
      const files = e.dataTransfer?.files;
      if (!files || files.length === 0) return;
      const dt = new DataTransfer();
      dt.items.add(files[0]);          // un archivo por carga
      input.files = dt.files;
      show();
      input.focus();
    });
  });
}

/* ---------- Tablas responsive (docs/DESIGN_SYSTEM.md §11.1) ----------
   A · .table-stack: cada <td> recibe data-label con el texto de SU <th> (respetando colspan). La etiqueta vive en un
   solo lugar (el encabezado), así que no se escribe a mano en cada vista. Un data-label explícito se respeta.
   Los roles ARIA conservan la semántica de tabla cuando el CSS cambia display (Safari/VoiceOver la pierde).
   [data-stack-ready] activa el modo tarjetas: sin JS la tabla queda con desplazamiento controlado. */
function initResponsiveTables(root = document) {
  $$('table.table-stack', root).forEach((table) => {
    const headRow = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!headRow) return;
    const labels = [];
    Array.from(headRow.cells).forEach((th) => {
      const text = th.textContent.replace(/\s+/g, ' ').trim();
      for (let i = 0; i < (th.colSpan || 1); i += 1) labels.push(text);
    });
    table.setAttribute('role', 'table');
    Array.from(table.querySelectorAll(':scope > thead, :scope > tbody, :scope > tfoot')).forEach((g) => g.setAttribute('role', 'rowgroup'));
    Array.from(headRow.cells).forEach((th) => th.setAttribute('role', 'columnheader'));
    Array.from(table.rows).forEach((tr) => {
      tr.setAttribute('role', 'row');
      if (tr.parentElement === table.tHead) return;
      let col = 0;
      Array.from(tr.cells).forEach((cell) => {
        cell.setAttribute('role', cell.tagName === 'TH' ? 'rowheader' : 'cell');
        if (!cell.hasAttribute('data-label')) cell.dataset.label = cell.classList.contains('cell-actions') ? '' : (labels[col] ?? '');
        col += cell.colSpan || 1;
      });
    });
    table.dataset.stackReady = '';
  });
}

/* ---------- C · Desplazamiento horizontal controlado ----------
   Si una tabla no cabe, su contenedor se vuelve una región con foco (teclado: flechas) y nombre (el <caption>),
   y muestra sombras en los bordes. Se recalcula al cambiar el tamaño (ResizeObserver; sin lecturas en cada scroll). */
function initScrollRegions(root = document) {
  const regions = $$('.table-responsive', root);
  if (!regions.length || !('ResizeObserver' in window)) return;
  const update = (box) => {
    // Horizontal (tabla que no cabe) o vertical (.table-scroll con encabezado fijo): en ambos casos se opera con teclado
    const scrollable = box.scrollWidth > box.clientWidth + 1 || box.scrollHeight > box.clientHeight + 1;
    if (scrollable === box.classList.contains('is-scrollable')) return;
    box.classList.toggle('is-scrollable', scrollable);
    if (scrollable) {
      const caption = box.querySelector('caption')?.textContent.trim();
      box.tabIndex = 0;
      box.setAttribute('role', 'region');
      box.setAttribute('aria-label', `${caption || 'Tabla'} (desplazable)`);
    } else {
      box.removeAttribute('tabindex');
      box.removeAttribute('role');
      box.removeAttribute('aria-label');
    }
  };
  const ro = new ResizeObserver((entries) => entries.forEach((en) => update(en.target)));
  regions.forEach((box) => ro.observe(box));
  // El ancho del contenido cambia al cargar las fuentes sin que cambie el contenedor
  document.fonts?.ready.then(() => regions.forEach(update));
}

/* ---------- Barras de progreso (sin estilos en línea en el HTML: CSP) ---------- */
function paintProgress(root = document) {
  $$('[data-progress]', root).forEach((bar) => {
    const v = Math.max(0, Math.min(100, Number(bar.dataset.progress) || 0));
    bar.style.width = `${v}%`;
    // El atributo accesible vive en el contenedor con role="progressbar"
    (bar.closest('[role="progressbar"]') ?? bar).setAttribute('aria-valuenow', String(v));
  });
}

/* ---------- Sondeo del estado de un proceso ---------- */
const FINAL_STATES = new Set(['COMPLETADO', 'COMPLETADO_CON_INCIDENCIAS', 'ERROR', 'CANCELADO']);
function initJobPolling() {
  const el = $('[data-job-poll]');
  if (!el) return;
  const id = el.dataset.jobPoll;
  const initial = el.dataset.jobState;
  // data-job-poll-url permite sondear un recurso que envuelve el trabajo (p. ej. /api/importaciones/{id},
  // que sólo exige archivos.ver) en lugar de /api/procesos/{id} (procesos.ver).
  const url = el.dataset.jobPollUrl || `/api/procesos/${encodeURIComponent(id)}`;
  if (FINAL_STATES.has(initial)) return;
  let failures = 0;
  let polls = 0;
  const MAX_POLLS = 450; // ~15 min: el sondeo no mantiene viva la sesión y no debe correr indefinidamente
  const tick = async () => {
    polls += 1;
    if (polls > MAX_POLLS) { toast.show('info', 'El proceso sigue en curso. Recargue la página para ver su estado.'); return; }
    try {
      const data = await api.get(url);
      const job = el.dataset.jobPollUrl ? (data.proceso || {}) : data;
      failures = 0;
      const stateEl = $('[data-job-state-text]', el);
      if (stateEl) stateEl.textContent = job.etapa ? `${job.estado} · ${job.etapa}` : job.estado;
      const bar = $('[data-progress]', el);
      if (bar) { bar.dataset.progress = String(job.progreso ?? 0); paintProgress(el); }
      if (FINAL_STATES.has(job.estado)) {
        toast.show(job.estado === 'ERROR' ? 'error' : 'success', `El proceso #${id} terminó: ${job.estado}.`);
        setTimeout(() => window.location.reload(), 900);
        return;
      }
    } catch (err) {
      failures += 1;
      if (failures >= 5) { toast.show('warning', 'No fue posible consultar el estado del proceso. Recargue la página.'); return; }
    }
    setTimeout(tick, 2000);
  };
  setTimeout(tick, 1500);
}

document.addEventListener('DOMContentLoaded', () => {
  initSidebar();
  initSidebarRail();
  initPermMatrix();
  initNotifications();
  initConfirm();
  initFlashes();
  initPasswordToggles();
  initBusyForms();
  initFilters();
  paintProgress();
  initJobPolling();
  initDropzones();
  initSelection();
  initResponsiveTables();
  initScrollRegions();
});
