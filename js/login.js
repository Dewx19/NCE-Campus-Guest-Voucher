/* NCE-Campus Guest Voucher - Anmeldung und Abmeldung. Ersteller: Patrick Brandt */

import { $, el, store, toast } from './util.js';
import { api, setUnauthorizedHandler } from './api.js';
import { state } from './state.js';
import { showApp, notice } from './app.js';

export function showLogin(msg) {
  $('appView').hidden = true; $('loginView').hidden = false;
  const t = (state.cfg && state.cfg.targets) || [];
  const sel = $('loginTargetSel');
  sel.replaceChildren(...t.map((x) => el('option', { value: x.id, text: x.name })));
  const last = store.get('gv_target');
  if (last !== null && t.some((x) => String(x.id) === last)) sel.value = last;
  $('loginTargetField').hidden = t.length < 2;
  $('loginTargetName').hidden = t.length !== 1;
  $('loginTargetName').textContent = t.length === 1 ? 'NCE-Ziel: ' + t[0].name : '';
  notice($('loginNotice'));
  if (state.cfg && !t.length) { $('loginNotice').hidden = false; $('loginNotice').textContent = 'Kein NCE-Ziel konfiguriert – bitte targets in config.php ausfüllen.'; }
  $('loginError').hidden = !msg; $('loginError').textContent = msg || '';
  $('loginPass').value = '';
  $('loginUser').focus();
}
$('loginForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const b = $('loginBtn'); b.disabled = true; b.textContent = 'Anmelden…'; $('loginError').hidden = true;
  const body = { username: $('loginUser').value.trim(), password: $('loginPass').value };
  if (!$('loginTargetField').hidden) { body.target = Number($('loginTargetSel').value); store.set('gv_target', String(body.target)); }
  try {
    const r = await api('login', 'POST', body);
    state.cfg = await api('config');
    showApp();
    if (r.certPinned) toast('NCE-Zertifikat übernommen – die Verbindung wird ab jetzt geprüft');
  } catch (e) { $('loginError').textContent = e.message; $('loginError').hidden = false; $('loginPass').value = ''; }
  finally { b.disabled = false; b.textContent = 'Anmelden'; }
});
$('logoutBtn').addEventListener('click', async () => {
  try { await api('logout', 'POST', {}); } catch (_) { /* egal */ }
  state.selected.clear(); state.known.clear(); state.vouchers = [];
  try { state.cfg = await api('config'); } catch (_) { /* egal */ }
  showLogin();
});
setUnauthorizedHandler(showLogin);
