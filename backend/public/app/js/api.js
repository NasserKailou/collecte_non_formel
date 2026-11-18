// Accès à l'API /api/v1 en mode session : cookie HttpOnly (posé par le serveur) + en-tête anti-CSRF.
const BASE = new URL('../api/v1', window.location.href).pathname.replace(/\/$/, '');
let csrfToken = null;
const listeners = { unauthenticated: [] };

export class ApiError extends Error {
  constructor(status, error) {
    super(error?.message || `Erreur ${status}`);
    this.status = status;
    this.code = error?.code || 'ERROR';
    this.details = error?.details || {};
  }
}

export function setCsrf(token) { csrfToken = token; }
export function on(event, fn) { listeners[event].push(fn); }

/** Appel JSON. Renvoie l'enveloppe {data, meta} (ou null pour 204). */
export async function api(method, path, body) {
  const opt = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (body instanceof FormData) {
    opt.body = body;
  } else if (body !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(body);
  }
  if (method !== 'GET' && csrfToken) opt.headers['X-CSRF-Token'] = csrfToken;
  let res;
  try {
    res = await fetch(BASE + path, opt);
  } catch {
    throw new ApiError(0, { code: 'NETWORK', message: 'Serveur injoignable. Vérifier la connexion.' });
  }
  if (res.status === 204) return null;
  const json = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && !path.startsWith('/auth/')) listeners.unauthenticated.forEach((fn) => fn());
    throw new ApiError(res.status, json?.error);
  }
  return json;
}

export const get = (path, query) => api('GET', path + qs(query));
export const post = (path, body) => api('POST', path, body ?? {});
export const patch = (path, body) => api('PATCH', path, body);
export const put = (path, body) => api('PUT', path, body);
export const del = (path, body) => api('DELETE', path, body);

/** Chaîne de requête sans les valeurs vides. */
export function qs(query) {
  if (!query) return '';
  const p = new URLSearchParams();
  Object.entries(query).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') p.append(k, String(v)); });
  const s = p.toString();
  return s ? `?${s}` : '';
}

/** Téléchargement d'un fichier servi par l'API (rapport, modèle, pièce jointe). */
export async function download(path, fallbackName = 'fichier') {
  const res = await fetch(BASE + path, { credentials: 'same-origin' });
  if (!res.ok) {
    const json = await res.json().catch(() => null);
    throw new ApiError(res.status, json?.error);
  }
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') || '';
  const name = (cd.match(/filename="([^"]+)"/) || [])[1] || fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

/** URL d'objet pour afficher une image protégée (pièce jointe). */
export async function blobUrl(path) {
  const res = await fetch(BASE + path, { credentials: 'same-origin' });
  if (!res.ok) throw new ApiError(res.status, (await res.json().catch(() => null))?.error);
  return URL.createObjectURL(await res.blob());
}
