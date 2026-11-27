/* NCE-Campus Guest Voucher - WLAN-QR-Code (immer "WLAN verbinden"). Ersteller: Patrick Brandt */

import { $ } from './util.js';

// qrcode() kommt aus vendor/qrcode.js (klassisches Skript, vor den Modulen geladen)
export function qrSvg(text, opts = { cellSize: 4, margin: 0, scalable: true }) {
  const q = qrcode(0, 'M'); q.addData(text); q.make(); return q.createSvgTag(opts);
}
export const currentSsid = () => $('ssidSel').value || '';
// Standardformat WIFI:S:<ssid>;T:nopass;;  (Sonderzeichen \ ; , : " werden maskiert)
export const wifiQr = (ssid) => 'WIFI:S:' + ssid.replace(/([\\;,:"])/g, '\\$1') + ';T:nopass;;';
export const codeOf = (v) => v.passcode || v.userName || '';
export const hasCreds = (v) => !!(v.passcode || (v.userName && v.password));
// Anzeigename fuer Meldungen: Code/Benutzer, sonst Markierung (VM:<Batch>-<Nr>) oder Beschreibung
export const labelOf = (v) => codeOf(v) || v.marker || v.description || 'Voucher';
/** QR-Inhalt fuer einen Voucher - nur wenn SSID gewaehlt und die Zugangsdaten bekannt sind. */
export function qrText(v) { const ssid = currentSsid(); return ssid && hasCreds(v) ? wifiQr(ssid) : null; }
