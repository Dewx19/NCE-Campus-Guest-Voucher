/* NCE-Campus Guest Voucher - Gast-WLAN (SSID) und Rohdaten-Dialog. Ersteller: Patrick Brandt */

import { $, el, store, toast } from './util.js';
import { api, quiet } from './api.js';
import { state } from './state.js';
import { currentSsid } from './qr.js';
import { render } from './vouchers.js';

export async function loadSsids() {
  const sel = $('ssidSel'), info = $('ssidInfo');
  const saved = store.get('gv_ssid');
  const pre = saved !== null ? saved : state.cfg.ssid;
  let list = [];
  info.textContent = 'Suche SSIDs im NCE …';
  try {
    const r = await api('ssids');
    list = r.ssids || [];
    const all = list;
    list = all.filter((x) => x.openPortal); // nur Open + Portal anzeigen
    info.textContent = !all.length ? 'Keine SSIDs gefunden – „Rohdaten“ zeigt die Antworten des NCE.'
      : !list.length ? `Keine SSID mit Open + Portal erkannt (${all.length} andere ausgeblendet) – bitte „Rohdaten“ prüfen.`
      : `${list.length} SSID(s) mit Open + Portal (aus ${r.sites} Site(s))` + (all.length > list.length ? `, ${all.length - list.length} weitere ausgeblendet.` : '.');
  } catch (e) { if (!quiet(e)) info.textContent = 'SSIDs nicht abrufbar (' + e.message + ').'; }
  const opt = (x) => el('option', { value: x.ssid, text: x.ssid + (x.sites.length ? ` (${x.sites.join(', ')})` : '') });
  // Vorgabe aus config.php (qr.ssid) nur, wenn sie nicht ohnehin erkannt wurde
  const cfgSsid = state.cfg.ssid && !list.some((x) => x.ssid === state.cfg.ssid) ? state.cfg.ssid : '';
  sel.replaceChildren(
    el('option', { value: '', text: '– SSID wählen –' }),
    ...list.map(opt),
    ...(cfgSsid ? [el('option', { value: cfgSsid, text: cfgSsid + ' (Vorgabe aus config.php)' })] : []));
  if (pre && list.some((x) => x.ssid === pre)) sel.value = pre;
  else if (cfgSsid) sel.value = cfgSsid;
  else if (list.length === 1) sel.value = list[0].ssid;
  ssidChanged();
}
function ssidChanged() { store.set('gv_ssid', currentSsid()); render(); }
$('ssidSel').addEventListener('change', ssidChanged);
async function showRaw(route, title, info) {
  $('rawTitle').textContent = title; $('rawInfo').textContent = info;
  $('rawPre').textContent = 'Lade …'; $('rawDlg').showModal();
  try { $('rawPre').textContent = JSON.stringify(await api(route), null, 2); }
  catch (e) { if (quiet(e)) $('rawDlg').close(); else $('rawPre').textContent = 'Fehler: ' + e.message; }
}
$('ssidRaw').addEventListener('click', () => showRaw('ssids/raw', 'NCE-Rohdaten (SSIDs)',
  'Probierte Abfragepfade, Beispiel-Datensätze mit Einordnung (ssidAuth.mode, Portal) und ggf. Online-User – zur Prüfung der Erkennung „Open + Portal“.'));
$('voucherRaw').addEventListener('click', () => {
  if (!$('groupSel').value) return toast('Bitte zuerst eine Gast-Benutzergruppe wählen.');
  showRaw('vouchers/raw', 'NCE-Rohdaten (Voucher)',
    'Antwort des NCE für die gewählte Gruppe: alle gelieferten Feldnamen, mögliche Code-Felder, bis zu 5 Beispiel-Datensätze und Versuche einer Detailabfrage. Enthält ggf. Zugangscodes – nur intern weitergeben.');
});
$('rawCopy').addEventListener('click', async () => {
  try { await navigator.clipboard.writeText($('rawPre').textContent); toast('In die Zwischenablage kopiert.'); }
  catch (_) { toast('Kopieren nicht möglich – bitte markieren und kopieren.'); }
});
$('rawClose').addEventListener('click', () => $('rawDlg').close());
