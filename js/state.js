/* NCE-Campus Guest Voucher - gemeinsamer Zustand der Oberflaeche. Ersteller: Patrick Brandt */

export const state = {
  cfg: null,
  vouchers: [],
  selected: new Set(),
  lastResult: [],
  // Zugangsdaten der in dieser Sitzung angelegten Voucher (nur im Arbeitsspeicher, weg nach Neuladen).
  // Schluessel: Markierung im Beschreibungsfeld (VM:<Batch>-<Nr>)
  known: new Map(),
};

// Druckvorlage: Vorgaben, bis die gespeicherten Einstellungen geladen sind
export const PRINT_FALLBACK = {
  title: 'WLAN Gast-Zugang', hintPasscode: 'QR-Code scannen, dann den Passcode auf der Anmeldeseite eingeben',
  hintUser: 'QR-Code scannen, dann Benutzername und Passwort auf der Anmeldeseite eingeben', footer: '',
  showSsid: true, showValid: true, showDesc: false, columns: 3, qrSize: 42, fontScale: 100, align: 'center', border: true, rounded: true, gap: 10,
};
state.print = { ...PRINT_FALLBACK };
state.printDefaults = { ...PRINT_FALLBACK };
