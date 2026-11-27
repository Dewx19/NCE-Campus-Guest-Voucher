/* NCE-Campus Guest Voucher - Benutzergruppe, Voucher-Liste, QR-Dialog, Loeschen, Anlegen. Ersteller: Patrick Brandt */

import { $, el, fmtDate, store, toast, alertBox } from './util.js';
import { api, quiet } from './api.js';
import { state } from './state.js';
import { qrSvg, currentSsid, codeOf, labelOf, qrText } from './qr.js';
import { printCards } from './print.js';

// ---------- Gast-Benutzergruppe ----------
const normPath = (s) => (s || '').toLowerCase().replace(/[\\/]+/g, '\\').replace(/^\\|\\$/g, '');
export async function initGroups() {
  $('loading').hidden = false; $('empty').hidden = true; $('tbody').replaceChildren(); $('createBtn').disabled = true;
  let d;
  try { d = await api('groups'); }
  catch (e) { if (!quiet(e)) { $('loading').hidden = true; $('empty').hidden = false; $('empty').textContent = 'Benutzergruppen konnten nicht geladen werden: ' + e.message; } return; }
  const sel = $('groupSel');
  sel.replaceChildren(el('option', { value: '', text: '– bitte wählen –' }), ...d.groups.map((g) => el('option', { value: g.id, text: g.path || g.name })));
  let cur = d.current && d.groups.some((g) => g.id === d.current) ? d.current : null;
  if (!cur) {
    const last = store.get('gv_group');
    const defP = normPath(state.cfg.guest.defaultGroupPath);
    const def = defP && (d.groups.find((g) => normPath(g.path) === defP) || d.groups.find((g) => normPath(g.path).endsWith('\\' + defP)));
    const pick = (last && d.groups.find((g) => g.id === last)) || def || (d.groups.length === 1 ? d.groups[0] : null);
    if (pick) { try { await api('group', 'POST', { id: pick.id }); cur = pick.id; } catch (e) { if (quiet(e)) return; } }
  }
  sel.value = cur || '';
  groupChanged(cur);
}
function groupChanged(id) {
  $('createBtn').disabled = !id;
  $('groupInfo').textContent = '';
  if (id) { loadVouchers(); return; }
  state.vouchers = []; state.selected.clear();
  $('loading').hidden = true; $('tbody').replaceChildren();
  $('summary').textContent = 'Bitte Gast-Benutzergruppe wählen.';
  $('empty').hidden = false; $('empty').textContent = 'Wähle oben die Benutzergruppe, in der die Gast-Voucher verwaltet werden.';
  updateButtons();
}
$('groupSel').addEventListener('change', async (ev) => {
  const id = ev.target.value; if (!id) return groupChanged(null);
  try { await api('group', 'POST', { id }); store.set('gv_group', id); state.selected.clear(); groupChanged(id); }
  catch (e) { if (!quiet(e)) toast('Fehler: ' + e.message); }
});

// ---------- Voucher-Liste (live) ----------
const statusOf = (v) => (v.disabled ? 'disabled' : v.expired ? 'expired' : 'active');
const STATUS = { active: 'Aktiv', expired: 'Abgelaufen', disabled: 'Deaktiviert' };
const typeLabel = (t) => (t === 6 ? 'Passcode' : t === 20 ? 'Benutzer/Passwort' : `Typ ${t}`);

export async function loadVouchers() {
  $('loading').hidden = false; $('empty').hidden = true;
  try {
    const d = await api('vouchers');
    // Zugangsdaten aus dieser Sitzung ergaenzen, falls der NCE sie nicht liefert
    state.vouchers = d.vouchers.map((v) => { const k = v.marker && state.known.get(v.marker); return k ? { ...v, ...k } : v; });
    state.selected = new Set([...state.selected].filter((id) => state.vouchers.some((v) => v.id === id)));
    render();
  } catch (e) {
    if (!quiet(e)) { $('loading').hidden = true; $('summary').textContent = 'Fehler beim Laden.'; $('empty').hidden = false; $('empty').textContent = e.message; }
  }
}
function filtered() {
  const q = $('search').value.trim().toLowerCase(), st = $('statusFilter').value;
  return state.vouchers.filter((v) => (!st || statusOf(v) === st) &&
    (!q || [v.passcode, v.userName, v.description, v.batch].some((x) => (x || '').toLowerCase().includes(q))));
}
export function render() {
  if (!$('groupSel').value) return;
  $('loading').hidden = true;
  const rows = filtered();
  const act = state.vouchers.filter((v) => statusOf(v) === 'active').length;
  $('summary').textContent = `${state.vouchers.length} Voucher · ${act} aktiv`;
  $('empty').hidden = rows.length > 0;
  $('empty').textContent = state.vouchers.length ? 'Keine Treffer für den Filter.' : 'Noch keine Voucher vorhanden.';
  const ssid = currentSsid();
  $('tbody').replaceChildren(...rows.map((v) => {
    const q = qrText(v);
    let qrCell;
    if (q) { qrCell = el('button', { class: 'qr-thumb', type: 'button', 'data-qr': v.id, title: 'QR-Code vergrößern' }); qrCell.innerHTML = qrSvg(q); }
    else qrCell = el('span', { class: 'na', text: '–', title: !ssid ? 'Bitte oben das Gast-WLAN wählen' : 'Zugangsdaten nur direkt nach dem Anlegen sichtbar' });
    return el('tr', {},
      el('td', { class: 'chk' }, el('input', { type: 'checkbox', 'data-id': v.id, 'aria-label': 'Auswählen', checked: state.selected.has(v.id) ? '' : null })),
      el('td', {}, codeOf(v) ? el('span', { class: 'mono', text: codeOf(v) }) : el('span', { class: 'na', text: 'nur bei Anlage sichtbar' })),
      el('td', {}, v.password ? el('span', { class: 'mono', text: v.password }) : el('span', { class: 'na', text: v.userType === 20 ? 'nur bei Anlage sichtbar' : '–' })),
      el('td', { text: typeLabel(v.userType) }),
      el('td', { text: fmtDate(v.validUntil) }),
      el('td', {}, el('span', { class: `pill ${statusOf(v)}`, text: STATUS[statusOf(v)] })),
      el('td', { class: 'desc', text: v.description || '' }),
      el('td', {}, qrCell),
      el('td', { class: 'row-actions' }, el('button', { class: 'btn sm danger', type: 'button', 'data-del': v.id, text: 'Löschen' })));
  }));
  $('checkAll').checked = rows.length > 0 && rows.every((v) => state.selected.has(v.id));
  updateButtons();
}
function updateButtons() {
  const n = state.selected.size;
  $('deleteBtn').disabled = n === 0; $('deleteBtn').textContent = n ? `Löschen (${n})` : 'Löschen';
  $('printBtn').disabled = !state.vouchers.some((v) => state.selected.has(v.id) && qrText(v));
}
$('tbody').addEventListener('click', (ev) => {
  const t = ev.target.closest('[data-qr],[data-del],input[data-id]'); if (!t) return;
  if (t.dataset.qr) openQr(state.vouchers.find((v) => v.id === t.dataset.qr));
  else if (t.dataset.del) askDelete([state.vouchers.find((v) => v.id === t.dataset.del)]);
  else {
    if (t.checked) state.selected.add(t.dataset.id); else state.selected.delete(t.dataset.id);
    $('checkAll').checked = filtered().every((v) => state.selected.has(v.id)); updateButtons();
  }
});
$('checkAll').addEventListener('change', (ev) => { filtered().forEach((v) => (ev.target.checked ? state.selected.add(v.id) : state.selected.delete(v.id))); render(); });
$('search').addEventListener('input', render);
$('statusFilter').addEventListener('change', render);
$('refreshBtn').addEventListener('click', loadVouchers);

// ---------- QR-Dialog ----------
let qrCurrent = null;
function openQr(v) {
  const q = qrText(v); if (!q) return;
  qrCurrent = { v, q };
  $('qrBox').innerHTML = qrSvg(q);
  $('qrCode').textContent = codeOf(v);
  $('qrNote').textContent = `Der QR-Code verbindet mit dem WLAN „${currentSsid()}“. Danach öffnet sich die Anmeldeseite – dort ${v.passcode ? 'den Passcode' : 'Benutzername und Passwort'} eingeben.` + (v.password ? ` Passwort: ${v.password}` : '');
  $('qrText').textContent = q;
  $('qrDlg').showModal();
}
$('qrDownload').addEventListener('click', () => {
  if (!qrCurrent) return;
  const svg = qrSvg(qrCurrent.q, { cellSize: 8, margin: 4, scalable: false });
  const a = el('a', { href: URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' })), download: `voucher-${codeOf(qrCurrent.v)}.svg` });
  document.body.append(a); a.click(); a.remove();
});
$('qrClose').addEventListener('click', () => $('qrDlg').close());

// ---------- Löschen ----------
let pendingDelete = [];
function askDelete(list) {
  if (!list.length) return;
  pendingDelete = list;
  const name = labelOf(list[0]);
  $('confirmText').textContent = list.length === 1
    ? `Voucher „${name}“ wird im iMaster NCE-Campus gelöscht. Das kann nicht rückgängig gemacht werden.`
    : `${list.length} Voucher werden im iMaster NCE-Campus gelöscht. Das kann nicht rückgängig gemacht werden.`;
  $('confirmDlg').showModal();
}
$('confirmNo').addEventListener('click', () => $('confirmDlg').close());
$('confirmYes').addEventListener('click', async () => {
  const btn = $('confirmYes'); btn.disabled = true;
  try {
    const r = await api('vouchers/delete', 'POST', { ids: pendingDelete.map((v) => v.id) });
    $('confirmDlg').close();
    toast(`${r.deleted.length} gelöscht${r.failed.length ? `, ${r.failed.length} fehlgeschlagen` : ''}`);
    if (r.failed.length) {
      const name = (id) => { const v = state.vouchers.find((x) => x.id === id); return v ? labelOf(v) : (id || '?'); };
      const reasons = r.failed.map((f) => `• ${name(f.id)}: ${f.reason || 'kein Grund vom NCE gemeldet'}`).join('\n');
      const hint = /online/i.test(reasons) ? '\n\nDer Gast ist vermutlich noch angemeldet. Im NCE bei den Online-Usern abmelden und dann erneut löschen.' : '';
      alertBox('Löschen fehlgeschlagen', 'Der NCE hat das Löschen abgelehnt:\n' + reasons + hint);
    }
    r.deleted.forEach((id) => state.selected.delete(id));
    await loadVouchers();
  } catch (e) { if (!quiet(e)) { $('confirmDlg').close(); toast('Fehler: ' + e.message); } }
  finally { btn.disabled = false; }
});
$('deleteBtn').addEventListener('click', () => askDelete(state.vouchers.filter((v) => state.selected.has(v.id))));

// ---------- Anlegen ----------
let mode = 'single';
function syncCreateForm() {
  document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.mode === mode));
  document.querySelectorAll('.batch-only').forEach((n) => (n.hidden = mode !== 'batch'));
  document.querySelectorAll('.only-user').forEach((n) => (n.hidden = $('fType').value !== '20'));
  document.querySelectorAll('.eff0').forEach((n) => (n.hidden = $('fEff').value !== '0'));
  document.querySelectorAll('.eff1').forEach((n) => (n.hidden = $('fEff').value !== '1'));
  $('batchHint').hidden = mode !== 'batch';
  $('createSubmit').textContent = mode === 'batch' ? 'Batch anlegen' : 'Anlegen';
}
document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => { mode = t.dataset.mode; syncCreateForm(); }));
['fType', 'fEff'].forEach((i) => $(i).addEventListener('change', syncCreateForm));
$('createBtn').addEventListener('click', () => {
  const g = state.cfg.guest;
  $('fType').value = String(g.defaultType); $('fPrefix').value = g.codePrefix; $('fDays').value = g.defaultValidDays;
  $('fEff').value = '1'; $('fTime').value = 1; $('fUnit').value = 'day';
  $('fCount').value = mode === 'batch' ? 10 : 1; $('createError').hidden = true;
  syncCreateForm(); $('createDlg').showModal();
});
$('createCancel').addEventListener('click', () => $('createDlg').close());
$('createForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const btn = $('createSubmit'), label = btn.textContent;
  btn.disabled = true; $('createError').hidden = true;
  const body = {
    userType: Number($('fType').value), prefix: $('fPrefix').value,
    effectiveType: Number($('fEff').value), validDays: Number($('fDays').value), effectiveTime: Number($('fTime').value), effectiveUnit: $('fUnit').value,
    maxAccessNum: $('fMax').value, description: $('fDesc').value,
  };
  const total = mode === 'batch' ? Math.max(1, Math.min(Number($('fCount').value) || 1, state.cfg.guest.maxBatch)) : 1;
  const CHUNK = 25, batch = 'B' + Date.now().toString(36).toUpperCase().slice(-8);
  const agg = { created: 0, requested: total, failed: [], results: [], aborted: false };
  try {
    for (let off = 0; off < total && !agg.aborted; off += CHUNK) {
      btn.textContent = total > CHUNK ? `Wird angelegt… ${off}/${total}` : 'Wird angelegt…';
      let r;
      try { r = await api('vouchers', 'POST', { ...body, count: Math.min(CHUNK, total - off), offset: off, batch }); }
      catch (e) { if (quiet(e) || (!agg.created && !agg.failed.length)) throw e; agg.failed.push({ error: e.message }); agg.aborted = true; break; }
      agg.created += r.created; agg.failed.push(...r.failed); agg.results.push(...r.results); agg.aborted = agg.aborted || r.aborted;
    }
    // Zugangsdaten fuer diese Sitzung merken (Liste, QR, Druck)
    agg.results.forEach((x) => state.known.set(x.marker, { passcode: x.passcode || null, userName: x.userName || null, password: x.password || null }));
    $('createDlg').close(); showResult(agg); loadVouchers();
  } catch (e) { if (!quiet(e)) { $('createError').textContent = e.message; $('createError').hidden = false; } }
  finally { btn.disabled = false; btn.textContent = label; }
});

function showResult(r) {
  state.lastResult = r.results.map((x) => ({ passcode: x.passcode || null, userName: x.userName || null, password: x.password || null, userType: x.passcode ? 6 : 20, validUntil: null, description: '' }));
  $('resultTitle').textContent = `${r.created} von ${r.requested} Voucher angelegt`;
  const body = $('resultBody'); body.replaceChildren();
  if (r.results.length) {
    const rows = state.lastResult.map((v) => {
      const q = qrText(v), cell = el('td');
      if (q) { const s = el('span', { class: 'qr-thumb static' }); s.innerHTML = qrSvg(q); cell.append(s); }
      return el('tr', {}, el('td', {}, el('span', { class: 'mono', text: codeOf(v) })), el('td', {}, v.password ? el('span', { class: 'mono', text: v.password }) : '–'), cell);
    });
    body.append(el('div', { class: 'result-list' }, el('table', {},
      el('thead', {}, el('tr', {}, el('th', { text: 'Voucher' }), el('th', { text: 'Passwort' }), el('th', { text: 'QR' }))),
      el('tbody', {}, rows))));
  }
  if (r.failed.length) body.append(el('div', { class: 'error' }, el('strong', { text: `${r.failed.length} fehlgeschlagen: ` }), [...new Set(r.failed.map((f) => f.error))].join('; ')));
  if (r.aborted) body.append(el('div', { class: 'error', text: 'Sitzung während der Anlage abgelaufen – restliche Voucher wurden nicht angelegt.' }));
  $('resultPrint').disabled = !r.results.length;
  $('resultDlg').showModal();
}
$('resultClose').addEventListener('click', () => $('resultDlg').close());
$('resultPrint').addEventListener('click', () => printCards(state.lastResult));
