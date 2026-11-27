/* NCE-Campus Guest Voucher - Zugriff auf api.php. Ersteller: Patrick Brandt */

let onUnauthorized = () => {};
/** Wird bei 401 (Sitzung abgelaufen) aufgerufen - setzt die Anmeldung (login.js). */
export function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

export async function api(path, method = 'GET', body) {
  const res = await fetch('api.php?r=' + path, {
    method, credentials: 'same-origin',
    headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-Requested-With': 'gv' },
    body: body ? JSON.stringify(body) : undefined,
  });
  let data = null; try { data = await res.json(); } catch (_) { /* leer */ }
  if (res.status === 401 && path !== 'login') { onUnauthorized(data && data.error); throw new Error('unauthorized'); }
  if (!res.ok) throw new Error((data && data.error) || `HTTP ${res.status}`);
  return data;
}
export const quiet = (e) => e.message === 'unauthorized';
