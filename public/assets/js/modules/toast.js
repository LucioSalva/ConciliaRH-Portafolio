/**
 * Notificaciones tipo toast con el componente .notice (icono + texto; nunca sólo color).
 * Los mensajes se insertan con textContent (sin HTML).
 */
const ICONS = {
  success: 'bi-check-circle-fill',
  error: 'bi-x-octagon-fill',
  warning: 'bi-exclamation-triangle-fill',
  info: 'bi-info-circle-fill',
};
const TITLES = { success: 'Listo', error: 'Error', warning: 'Atención', info: 'Aviso' };
const AUTO_HIDE = { success: 6000, info: 7000 };

function stack() {
  let el = document.getElementById('toastStack');
  if (!el) {
    el = document.createElement('div');
    el.id = 'toastStack';
    el.className = 'toast-stack';
    document.body.appendChild(el);
  }
  return el;
}

function wire(el, type) {
  el.querySelector('.notice__close')?.addEventListener('click', () => el.remove());
  const ms = AUTO_HIDE[type];
  if (ms) {
    let timer = setTimeout(() => el.remove(), ms);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { clearTimeout(timer); timer = setTimeout(() => el.remove(), 2500); });
  }
}

export const toast = {
  show(type, message) {
    const t = ICONS[type] ? type : 'info';
    const el = document.createElement('div');
    el.className = `notice notice--${t === 'error' ? 'error' : t}`;
    el.setAttribute('role', t === 'error' || t === 'warning' ? 'alert' : 'status');
    const icon = document.createElement('i');
    icon.className = `bi ${ICONS[t]} notice__icon`;
    icon.setAttribute('aria-hidden', 'true');
    const body = document.createElement('div');
    body.className = 'notice__body';
    const title = document.createElement('p');
    title.className = 'notice__title';
    title.textContent = TITLES[t];
    const text = document.createElement('div');
    text.textContent = message;
    body.append(title, text);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'notice__close';
    close.setAttribute('aria-label', 'Cerrar notificación');
    close.textContent = '×';
    el.append(icon, body, close);
    stack().appendChild(el);
    wire(el, t);
  },
  adopt(el) {
    wire(el, el.dataset.flash);
  },
};
