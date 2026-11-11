/**
 * Cliente de la API interna. Envía el token CSRF en toda petición mutante,
 * mantiene cookies de mismo origen y valida el sobre { success, data, error }.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

async function request(method, url, body) {
  const headers = { Accept: 'application/json' };
  const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = csrf();
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body ?? {});
  }
  const res = await fetch(url, init);
  let payload = null;
  try { payload = await res.json(); } catch { /* respuesta no JSON */ }
  if (res.status === 401) {
    window.location.assign('/login');
    throw new ApiError('NO_AUTENTICADO', 'Sesión expirada', 401);
  }
  if (!res.ok || !payload || payload.success !== true) {
    const err = payload?.error ?? {};
    throw new ApiError(err.code ?? 'ERROR', err.message ?? 'No fue posible completar la operación.', res.status);
  }
  return payload.data;
}

export const api = {
  get: (url) => request('GET', url),
  post: (url, body) => request('POST', url, body),
};
