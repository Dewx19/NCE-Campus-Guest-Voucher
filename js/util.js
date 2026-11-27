/* NCE-Campus Guest Voucher - Helfer (DOM, Datum, localStorage, Meldungen). Ersteller: Patrick Brandt */

export const $ = (id) => document.getElementById(id);
// ---------- Helfer ----------
export const el = (tag, props = {}, ...kids) => {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v == null) continue;
    if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v); else n.setAttribute(k, v);
  }
  kids.flat().forEach((c) => c != null && n.append(c));
  return n;
};
export const fmtDate = (ms) => (ms ? new Date(ms).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '–');
export const store = {
  get: (k) => { try { return localStorage.getItem(k); } catch (_) { return null; } },
  set: (k, v) => { try { localStorage.setItem(k, v); } catch (_) { /* egal */ } },
};
export function toast(msg) { const t = $('toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 3500); }
export function alertBox(title, text) { $('alertTitle').textContent = title; $('alertText').textContent = text; $('alertDlg').showModal(); }
