/* NCE-Campus Guest Voucher - Start. Ersteller: Patrick Brandt */

import { $ } from './util.js';
import { api } from './api.js';
import { state } from './state.js';
import { showLogin } from './login.js';
import { showApp } from './app.js';

$('alertClose').addEventListener('click', () => $('alertDlg').close());

(async () => {
  try { state.cfg = await api('config'); }
  catch (e) { document.body.textContent = 'Server nicht erreichbar: ' + e.message; return; }
  if (state.cfg.loggedIn) showApp(); else showLogin();
})();
