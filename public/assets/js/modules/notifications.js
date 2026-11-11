/**
 * Campana de notificaciones del encabezado (docs/NOTIFICATIONS.md §4).
 * Sondeo MODERADO: cada 60 s y sólo con la pestaña visible; al volver a ella, una consulta inmediata.
 * Sin HTML desde datos: todo se arma con textContent. Abrir una notificación es un POST con CSRF (formulario del servidor).
 *
 * Marcado (partials/topbar.php; el panel es un dropdown de Bootstrap):
 *   <div class="dropdown notif" data-notif data-notif-url="/api/notificaciones/resumen">
 *     <button data-bs-toggle="dropdown" data-notif-toggle>… <span data-notif-count hidden></span></button>
 *     <span class="visually-hidden" aria-live="polite" data-notif-live></span>
 *     <div class="dropdown-menu"><ul data-notif-list></ul><p data-notif-empty>…</p>
 *       <template data-notif-item> (li con [data-f=titulo|mensaje|fecha|area|icono], form[data-f=form] y botón) </template></div>
 *   </div>
 */
import { api } from './api.js';

const INTERVAL_MS = 60000;

export function initNotifications(root = document) {
  const box = root.querySelector('[data-notif]');
  if (!box) return;
  const url = box.dataset.notifUrl || '/api/notificaciones/resumen';
  const count = box.querySelector('[data-notif-count]');
  const live = box.querySelector('[data-notif-live]');
  const list = box.querySelector('[data-notif-list]');
  const empty = box.querySelector('[data-notif-empty]');
  const tpl = box.querySelector('template[data-notif-item]');
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
  let timer = null;
  let last = null;

  const render = (data) => {
    const n = Number(data?.no_leidas ?? 0);
    if (count) {
      count.hidden = n === 0;
      count.textContent = n > 99 ? '99+' : String(n);
    }
    box.dataset.unread = String(n);
    const btn = box.querySelector('[data-notif-toggle]');
    if (btn) btn.setAttribute('aria-label', n === 0 ? 'Notificaciones: ninguna sin leer' : `Notificaciones: ${n} sin leer`);
    if (live && last !== null && n > last) live.textContent = `${n} notificación(es) sin leer`;
    last = n;
    if (!list || !tpl) return;
    list.replaceChildren();
    for (const it of data?.ultimas ?? []) {
      const node = tpl.content.firstElementChild.cloneNode(true);
      node.classList.toggle('is-unread', it.estado === 'NO_LEIDA');
      node.classList.toggle('is-high', it.prioridad === 'ALTA');
      const set = (f, v) => { const el = node.querySelector(`[data-f="${f}"]`); if (el) el.textContent = v ?? ''; };
      set('titulo', it.titulo);
      set('mensaje', it.mensaje);
      set('area', it.area);
      set('fecha', it.created_at ? new Date(it.created_at).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '');
      const icon = node.querySelector('[data-f="icono"]');
      if (icon && /^bi-[a-z0-9-]+$/.test(it.icono ?? '')) icon.classList.add(it.icono);
      const form = node.querySelector('form[data-f="form"]');
      if (form) {
        form.action = `/notificaciones/${Number(it.id)}/abrir`;
        const t = form.querySelector('input[name="_token"]');
        if (t) t.value = token;
      }
      list.appendChild(node);
    }
    if (empty) empty.hidden = (data?.ultimas ?? []).length > 0;
  };

  const refresh = async () => {
    try {
      render(await api.get(url));
    } catch { /* sin conexión o sesión vencida (api.js redirige en 401): se reintenta en el siguiente ciclo */ }
  };
  const schedule = () => {
    clearInterval(timer);
    timer = document.visibilityState === 'visible' ? setInterval(refresh, INTERVAL_MS) : null;
  };
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
    schedule();
  });
  refresh();
  schedule();
}
