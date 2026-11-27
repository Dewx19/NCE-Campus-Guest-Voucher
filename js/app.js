/* NCE-Campus Guest Voucher - Hauptansicht nach der Anmeldung. Ersteller: Patrick Brandt */

import { $ } from './util.js';
import { state } from './state.js';
import { initGroups } from './vouchers.js';
import { loadSsids } from './ssids.js';
import { loadPrintSettings } from './print.js';

const TLS_TXT = { verified: '🔒 Zertifikat geprüft', system: '🔒 Zertifikat geprüft (System-CA)', unverified: '⚠ Zertifikat ungeprüft', http: '⚠ ohne TLS' };
export function showApp() {
  $('loginView').hidden = true; $('appView').hidden = false;
  $('whoami').textContent = [state.cfg.user, state.cfg.targetName].filter(Boolean).join(' · ');
  const t = state.cfg.tls || {};
  $('tlsInfo').textContent = TLS_TXT[t.mode] || '';
  $('tlsInfo').title = t.mode === 'verified'
    ? [t.subject && 'Zertifikat: ' + t.subject, t.checkName && 'geprüft gegen den Namen ' + t.checkName, t.validTo && 'gültig bis ' + new Date(t.validTo * 1000).toLocaleDateString('de-DE'),
       t.source === 'auto' && t.since ? 'automatisch übernommen am ' + new Date(t.since * 1000).toLocaleDateString('de-DE') : null].filter(Boolean).join('\n')
    : '';
  notice($('appNotice'));
  $('maxBatch').textContent = state.cfg.guest.maxBatch;
  $('fCount').max = state.cfg.guest.maxBatch;
  initGroups(); loadSsids(); loadPrintSettings();
}

/** Hinweis bei unverschluesselter Verbindung zum Webspace. */
export function notice(box) {
  const insecure = state.cfg && state.cfg.insecureTransport;
  box.hidden = !insecure;
  box.textContent = insecure ? 'Keine HTTPS-Verbindung: Zugangsdaten werden unverschlüsselt übertragen. Bitte die Seite nur über https:// betreiben.' : '';
}
