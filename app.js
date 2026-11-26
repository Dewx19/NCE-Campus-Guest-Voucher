/* NCE-Campus Guest Voucher - Frontend. Ersteller: Patrick Brandt */
'use strict';
(() => {
  const $ = (id) => document.getElementById(id);
  const state = {
    cfg: null,
    vouchers: [],
    selected: new Set(),
    lastResult: [],
    // Zugangsdaten der in dieser Sitzung angelegten Voucher (nur im Arbeitsspeicher, weg nach Neuladen).
    // Schluessel: Markierung im Beschreibungsfeld (VM:<Batch>-<Nr>)
    known: new Map(),
  };

  // ---------- Helfer ----------
  const el = (tag, props = {}, ...kids) => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (v == null) continue;
      if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v;
      else if (k.startsWith('on')) n.addEventListener(k.slice(2), v); else n.setAttribute(k, v);
    }
    kids.flat().forEach((c) => c != null && n.append(c));
    return n;
  };
  const fmtDate = (ms) => (ms ? new Date(ms).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '–');
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch (_) { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch (_) { /* egal */ } },
  };
  function toast(msg) { const t = $('toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 3500); }
  function alertBox(title, text) { $('alertTitle').textContent = title; $('alertText').textContent = text; $('alertDlg').showModal(); }
  $('alertClose').addEventListener('click', () => $('alertDlg').close());

  async function api(path, method = 'GET', body) {
    const res = await fetch('api.php?r=' + path, {
      method, credentials: 'same-origin',
      headers: method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-Requested-With': 'gv' },
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = null; try { data = await res.json(); } catch (_) { /* leer */ }
    if (res.status === 401 && path !== 'login') { showLogin(data && data.error); throw new Error('unauthorized'); }
    if (!res.ok) throw new Error((data && data.error) || `HTTP ${res.status}`);
    return data;
  }
  const quiet = (e) => e.message === 'unauthorized';

  // ---------- QR-Code: immer "WLAN verbinden" ----------
  function qrSvg(text, opts = { cellSize: 4, margin: 0, scalable: true }) {
    const q = qrcode(0, 'M'); q.addData(text); q.make(); return q.createSvgTag(opts);
  }
  const currentSsid = () => ($('ssidSel').value === '__manual' ? $('ssidManual').value.trim() : $('ssidSel').value) || '';
  // Standardformat WIFI:S:<ssid>;T:nopass;;  (Sonderzeichen \ ; , : " werden maskiert)
  const wifiQr = (ssid) => 'WIFI:S:' + ssid.replace(/([\\;,:"])/g, '\\$1') + ';T:nopass;;';
  const codeOf = (v) => v.passcode || v.userName || '';
  const hasCreds = (v) => !!(v.passcode || (v.userName && v.password));
  // Anzeigename fuer Meldungen: Code/Benutzer, sonst Markierung (VM:<Batch>-<Nr>) oder Beschreibung
  const labelOf = (v) => codeOf(v) || v.marker || v.description || 'Voucher';
  /** QR-Inhalt fuer einen Voucher - nur wenn SSID gewaehlt und die Zugangsdaten bekannt sind. */
  function qrText(v) { const ssid = currentSsid(); return ssid && hasCreds(v) ? wifiQr(ssid) : null; }

  // ---------- Login ----------
  function notice(box) {
    const insecure = state.cfg && state.cfg.insecureTransport;
    box.hidden = !insecure;
    box.textContent = insecure ? 'Keine HTTPS-Verbindung: Zugangsdaten werden unverschlüsselt übertragen. Bitte die Seite nur über https:// betreiben.' : '';
  }
  function showLogin(msg) {
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

  // ---------- App ----------
  const TLS_TXT = { verified: '🔒 Zertifikat geprüft', system: '🔒 Zertifikat geprüft (System-CA)', unverified: '⚠ Zertifikat ungeprüft', http: '⚠ ohne TLS' };
  function showApp() {
    $('loginView').hidden = true; $('appView').hidden = false;
    $('whoami').textContent = [state.cfg.user, state.cfg.targetName].filter(Boolean).join(' · ');
    const t = state.cfg.tls || {};
    $('tlsInfo').textContent = TLS_TXT[t.mode] || '';
    $('tlsInfo').title = t.mode === 'verified'
      ? [t.subject && 'Zertifikat: ' + t.subject, t.validTo && 'gültig bis ' + new Date(t.validTo * 1000).toLocaleDateString('de-DE'),
         t.source === 'auto' && t.since ? 'automatisch übernommen am ' + new Date(t.since * 1000).toLocaleDateString('de-DE') : null].filter(Boolean).join('\n')
      : '';
    notice($('appNotice'));
    $('maxBatch').textContent = state.cfg.guest.maxBatch;
    $('fCount').max = state.cfg.guest.maxBatch;
    initGroups(); loadSsids(); loadPrintSettings();
  }

  // ---------- Gast-WLAN (SSID) ----------
  async function loadSsids() {
    const sel = $('ssidSel'), info = $('ssidInfo');
    const saved = store.get('gv_ssid');
    const pre = saved !== null ? saved : state.cfg.ssid;
    let list = [], hidden = new Set();
    info.textContent = 'Suche SSIDs im NCE …';
    try {
      const r = await api('ssids');
      list = r.ssids || [];
      const all = list;
      list = all.filter((x) => x.openPortal); // nur Open + Portal anzeigen
      info.textContent = !all.length ? 'Keine SSIDs gefunden – bitte SSID eingeben. „Rohdaten“ zeigt die Antworten des NCE.'
        : !list.length ? `Keine SSID mit Open + Portal erkannt (${all.length} andere ausgeblendet) – bitte SSID eingeben oder „Rohdaten“ prüfen.`
        : all[0].source === 'online' ? `Keine SSID-Konfiguration lesbar – ${list.length} SSID(s) mit angemeldeten Portal-Gästen.`
        : `${list.length} SSID(s) mit Open + Portal (aus ${r.sites} Site(s))` + (all.length > list.length ? `, ${all.length - list.length} weitere ausgeblendet.` : '.');
      hidden = new Set(all.filter((x) => !x.openPortal).map((x) => x.ssid));
    } catch (e) { if (!quiet(e)) info.textContent = 'SSIDs nicht abrufbar (' + e.message + ') – bitte SSID eingeben.'; }
    const opt = (x) => el('option', { value: x.ssid, text: x.ssid + (x.sites.length ? ` (${x.sites.join(', ')})` : '') + (x.source === 'online' ? ` – ${x.users} Gast/Gäste online` : '') });
    sel.replaceChildren(
      el('option', { value: '', text: '– SSID wählen –' }),
      ...list.map(opt),
      el('option', { value: '__manual', text: 'Andere SSID eingeben …' }));
    if (pre && list.some((x) => x.ssid === pre)) sel.value = pre;
    else if (pre && !hidden.has(pre)) { sel.value = '__manual'; $('ssidManual').value = pre; }
    else if (list.length === 1) sel.value = list[0].ssid;
    $('ssidManual').hidden = sel.value !== '__manual';
    ssidChanged();
  }
  function ssidChanged() { store.set('gv_ssid', currentSsid()); render(); }
  $('ssidSel').addEventListener('change', () => {
    const m = $('ssidSel').value === '__manual'; $('ssidManual').hidden = !m; if (m) $('ssidManual').focus();
    ssidChanged();
  });
  $('ssidManual').addEventListener('change', ssidChanged);
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

  // ---------- Gast-Benutzergruppe ----------
  const normPath = (s) => (s || '').toLowerCase().replace(/[\\/]+/g, '\\').replace(/^\\|\\$/g, '');
  async function initGroups() {
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

  async function loadVouchers() {
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
  function render() {
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

  // ---------- Druck ----------
  const PRINT_FALLBACK = {
    title: 'WLAN Gast-Zugang', hintPasscode: 'QR-Code scannen, dann den Passcode auf der Anmeldeseite eingeben',
    hintUser: 'QR-Code scannen, dann Benutzername und Passwort auf der Anmeldeseite eingeben', footer: '',
    showSsid: true, showValid: true, showDesc: false, columns: 3, qrSize: 42, fontScale: 100, align: 'center', border: true, rounded: true, gap: 10,
  };
  state.print = { ...PRINT_FALLBACK };
  state.printDefaults = { ...PRINT_FALLBACK };
  async function loadPrintSettings() {
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

  function printCards(list) {
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

  // ---------- Start ----------
  (async () => {
    try { state.cfg = await api('config'); }
    catch (e) { document.body.textContent = 'Server nicht erreichbar: ' + e.message; return; }
    if (state.cfg.loggedIn) showApp(); else showLogin();
  })();
})();
