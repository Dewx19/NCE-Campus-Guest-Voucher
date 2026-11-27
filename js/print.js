/* NCE-Campus Guest Voucher - Druckkarten und Einstellungen der Druckvorlage. Ersteller: Patrick Brandt */

import { $, el, fmtDate, toast } from './util.js';
import { api } from './api.js';
import { state } from './state.js';
import { qrSvg, currentSsid, wifiQr, codeOf, qrText } from './qr.js';

export async function loadPrintSettings() {
  try { const r = await api('print-settings'); state.print = r.settings; state.printDefaults = r.defaults; }
  catch (_) { /* Vorgaben verwenden */ }
}

/** Layout-Werte als CSS-Variablen auf den Druck- bzw. Vorschaubereich legen. */
function applyLayout(area, p) {
  area.style.setProperty('--cols', p.columns);
  area.style.setProperty('--qr', p.qrSize + 'mm');
  area.style.setProperty('--fs', p.fontScale / 100);
  area.style.setProperty('--gap', p.gap + 'mm');
  area.dataset.align = p.align;
  area.dataset.border = p.border ? '1' : '0';
  area.dataset.rounded = p.rounded ? '1' : '0';
}

function buildCard(v, ssid, p) {
  const fill = (t) => String(t || '').split('{ssid}').join(ssid);
  const holder = el('div', { class: 'q' }); holder.innerHTML = qrSvg(wifiQr(ssid));
  const valid = v.validUntil ? `gültig bis ${fmtDate(v.validUntil)}` : '';
  return el('div', { class: 'pcard' },
    p.title ? el('div', { class: 't', text: fill(p.title) }) : null,
    holder,
    el('div', { class: 'c', text: codeOf(v) }),
    v.password ? el('div', { class: 'c', text: v.password }) : null,
    p.showSsid ? el('div', { class: 'w', text: 'WLAN: ' + ssid }) : null,
    el('div', { class: 'h', text: fill(v.passcode ? p.hintPasscode : p.hintUser) }),
    p.showValid && valid ? el('div', { class: 's', text: valid }) : null,
    p.showDesc && v.description ? el('div', { class: 's', text: v.description }) : null,
    p.footer ? el('div', { class: 'f', text: fill(p.footer) }) : null);
}

export function printCards(list) {
  const pa = $('printArea'); pa.replaceChildren();
  const ssid = currentSsid();
  applyLayout(pa, state.print);
  for (const v of list) if (qrText(v)) pa.append(buildCard(v, ssid, state.print));
  if (pa.children.length) window.print();
  else toast('Bitte zuerst oben das Gast-WLAN wählen.');
}

// ---------- Einstellungen (Druckvorlage) ----------
const S_TEXT = { title: 'sTitle', hintPasscode: 'sHintPasscode', hintUser: 'sHintUser', footer: 'sFooter' };
const S_BOOL = { showSsid: 'sShowSsid', showValid: 'sShowValid', showDesc: 'sShowDesc', border: 'sBorder', rounded: 'sRounded' };
const S_NUM = { columns: 'sColumns', qrSize: 'sQrSize', fontScale: 'sFontScale', gap: 'sGap' };
function fillSettings(p) {
  for (const [k, id] of Object.entries(S_TEXT)) $(id).value = p[k] || '';
  for (const [k, id] of Object.entries(S_BOOL)) $(id).checked = !!p[k];
  for (const [k, id] of Object.entries(S_NUM)) $(id).value = String(p[k]);
  $('sAlign').value = p.align;
  preview();
}
function readSettings() {
  const p = {};
  for (const [k, id] of Object.entries(S_TEXT)) p[k] = $(id).value.trim();
  for (const [k, id] of Object.entries(S_BOOL)) p[k] = $(id).checked;
  for (const [k, id] of Object.entries(S_NUM)) p[k] = Number($(id).value);
  p.align = $('sAlign').value;
  return p;
}
function preview() {
  const p = readSettings();
  $('sQrSizeV').textContent = p.qrSize; $('sFontScaleV').textContent = p.fontScale; $('sGapV').textContent = p.gap;
  const area = $('previewArea'); area.replaceChildren();
  applyLayout(area, p);
  const ssid = currentSsid() || 'Gast-WLAN';
  const until = Date.now() + 86400000;
  area.append(buildCard({ passcode: 'Ab3dEf7hJk', validUntil: until, description: 'Besucher' }, ssid, p));
  if (p.columns > 1) area.append(buildCard({ userName: 'WG-4821', password: 'xY7#mQ2pLs', validUntil: until, description: 'Besucher' }, ssid, p));
}
$('settingsBtn').addEventListener('click', async () => {
  $('settingsError').hidden = true;
  await loadPrintSettings();
  fillSettings(state.print);
  $('settingsDlg').showModal();
});
$('settingsForm').addEventListener('input', preview);
$('settingsForm').addEventListener('change', preview);
$('settingsCancel').addEventListener('click', () => $('settingsDlg').close());
$('settingsReset').addEventListener('click', () => fillSettings(state.printDefaults));
$('settingsForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('settingsSave').disabled = true;
  try {
    const r = await api('print-settings', 'POST', { settings: readSettings() });
    state.print = r.settings;
    $('settingsDlg').close(); toast('Druckeinstellungen gespeichert.');
  } catch (err) { $('settingsError').hidden = false; $('settingsError').textContent = err.message; }
  finally { $('settingsSave').disabled = false; }
});
$('printBtn').addEventListener('click', () => printCards(state.vouchers.filter((v) => state.selected.has(v.id))));
