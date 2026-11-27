# NCE-Campus Guest Voucher

> [!CAUTION]
> **Haftungsausschluss: Dieses Repository wird nicht von Huawei zur Verfügung gestellt. Huawei ist für dieses Repository und seinen Inhalt in keiner Weise verantwortlich.**
>
> **Jeder Nutzer soll und muss den Code selbst verifizieren und validieren, bevor er ihn einsetzt. Der Code dient lediglich als Inspiration und wird ohne jede Gewähr bereitgestellt.**

Ersteller: Patrick Brandt

Web-App für WLAN-Gast-Voucher im iMaster NCE-Campus: Login gegen den NCE, Voucher-Liste, Einzel- und Batch-Anlage, Löschen, QR-Code je Voucher und Druckkarten.

* **Ohne Datenbank.** Alle Voucher und Daten kommen bei jedem Laden live aus dem NCE. Es werden keine Codes gespeichert.
* Läuft auf normalem PHP-Webspace: PHP ≥ 7.4 mit **curl**, **openssl** und **json**. Kein Node, kein Composer.

## Einrichtung

1. Optional: `config.example.php` als `config.php` speichern und anpassen. Ohne `config.php` gelten die eingebauten Standardwerte (Ziel „Public Cloud Northbound API“, `80.158.58.176:18002`). **`config.php` nie ins Repository legen** (siehe `.gitignore`).
2. Alle Dateien hochladen, auch den Ordner `data/` mit seiner `.htaccess`. Der Ordner `data/` muss für PHP beschreibbar sein.
3. Seite per HTTPS aufrufen und mit dem NCE-Northbound-Account anmelden (Typ *Third-party*, Rolle *Open Api Operator*).

## Funktionsweise

### Live-Daten

* Die Liste kommt bei jedem Laden direkt aus dem NCE (Gäste der gewählten Benutzergruppe).
* **Nur Gast-Voucher:** Die App verwaltet ausschließlich `userType` 6 (Passcode) und 20 (Benutzer/Passwort). Andere Konten derselben Gruppe (z. B. Typ 24) werden weder angezeigt noch gelöscht.
* **Status** aus `accountStatus` (am NCE V300R025C10 verifiziert) und Ablaufdatum (`vaildPeriod`):

  | Status | Bedingung |
  |---|---|
  | Deaktiviert | `accountStatus = 3` |
  | Abgelaufen | `vaildPeriod` liegt in der Vergangenheit |
  | Aktiv | `accountStatus = 0` – Voucher wurde eingelöst bzw. aktiviert |
  | Bereit zur Nutzung | `accountStatus = 1` – wartet auf die erste Nutzung; bei Gültigkeit ab erster Nutzung beginnt die Laufzeit erst dann („Gültig bis: ab erster Nutzung“) |
  | Unbekannt | anderer oder fehlender `accountStatus` |
* **Passcodes** liefert der NCE im Feld `userName` mit – Liste, QR-Code und Druck funktionieren daher auch für Voucher, die direkt im NCE angelegt wurden. **Passwörter** von Benutzer/Passwort-Vouchern gibt die NBI nicht zurück; sie sind nur direkt nach dem Anlegen sichtbar (bis die Seite neu geladen wird).
* Neue Voucher tragen im Beschreibungsfeld eine Markierung `VM:<Batch>-<Nr>`, darüber ordnet die App sie dem Anlage-Durchlauf zu.

### QR-Code

Der QR-Code verbindet das Handy mit dem Gast-WLAN (`WIFI:S:<SSID>;T:nopass;;`). Danach öffnet sich die Portal-Anmeldeseite, dort wird der Code eingegeben.

* **Löschen** nur für Gastkonten (Typ 6 / 20) der gewählten Gruppe – das prüft der Server vor jedem Löschauftrag.
* **Einstellungen** (oben rechts): Texte (Überschrift, Hinweise, Fußzeile, `{ssid}` als Platzhalter), angezeigte Felder und Layout (Karten je Zeile, QR-Größe, Schriftgröße, Abstand, Ausrichtung, Rahmen) der Druckkarten, mit Vorschau. Gespeichert in `data/print.json` für alle Benutzer der Installation.
* Die App liest die SSIDs aller Sites aus dem NCE (`GET /controller/campus/v3/networkconfig/site/{siteId}/apssid`) und zeigt nur die mit **Open + Portal** (andere werden ausgeblendet): `ssidAuth.mode = open` und `ssidAuth.portal.mode` = `portalThirdController`, `portalThirdIndirect` oder `portalThird`. Eine feste SSID kann zusätzlich über `qr.ssid` in `config.php` vorgegeben werden.
* Der Knopf **Rohdaten** zeigt die probierten Abfragepfade und Beispiel-Datensätze mit der Einordnung (Portal / verschlüsselt).

### Sicherheit

* Der NCE-Token bleibt in der PHP-Session (HttpOnly-Cookie, `SameSite=Strict`, Abmeldung nach Inaktivität).
* **TLS:** Fehlt eine CA-Datei, übernimmt die App beim ersten Login das Zertifikat des NCE (`data/nce-ca-<Ziel>.pem`) – noch bevor Zugangsdaten gesendet werden – und prüft ab dann jede Verbindung dagegen. Nach einer Erneuerung durch Huawei schlägt die Prüfung fehl; dann die Datei löschen, beim nächsten Login wird das neue Zertifikat übernommen. Alternativ eigene CA-Datei per `caFile` oder `nce-ca.pem` im App-Ordner.
* **Login-Schutz:** nach 5 Fehlversuchen je IP in 15 Minuten gesperrt (`data/login_attempts.json`).
* `.htaccess` sperrt `config.example.php`, `.pem`/`.json`-Dateien, `data/`, `src/`, `tests/` und versteckte Ordner.
* **HTTPS erzwingen:** In der `.htaccess` steht eine auskommentierte Weiterleitung auf `https://`. Einschalten, sobald der Webspace ein gültiges Zertifikat für die Domain hat. Unter HTTPS ist das Session-Cookie automatisch `Secure`.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `index.html`, `style.css` | Oberfläche |
| `js/` | Frontend als ES-Module ohne Build: `main.js` (Start), `login.js`, `app.js`, `vouchers.js` (Gruppe, Liste, Anlegen, Löschen), `ssids.js`, `print.js` (Druck, Einstellungen), `qr.js`, `api.js`, `state.js`, `util.js` |
| `api.php` | Schlanker Router: prüft Anfrage und Anmeldung, ruft die Funktionen aus `src/` auf |
| `src/` | Backend: `settings.php` (Standardwerte, `config.php`), `nce.php` (Northbound-Client), `tls.php`, `auth.php` (Session, Login, Login-Sperre), `vouchers.php`, `ssids.php`, `print.php`, `util.php` |
| `vendor/qrcode.js` | QR-Code-Erzeugung (MIT-Lizenz) |
| `data/` | Laufzeitdaten: übernommenes Zertifikat, Login-Sperre, Druckeinstellungen |
| `tests/` | Automatische Tests mit Mock-NCE (wird nicht hochgeladen) |

## Tests

```
php tests/run.php
```

* **Unit-Tests** (`tests/unit/`): SSID-Einordnung, Voucher-Kennung, Zufallscodes, Auswertung der NCE-Antworten, Druckeinstellungen, Zertifikatsname.
* **Echte NCE-Antworten** (`tests/fixtures/`): anonymisierte Antworten eines iMaster NCE-Campus V300R025C10 für Gastkonten (Typ 6, 20, 24) und SSIDs (open/Portal, PSK, PPSK). Unit- und Ablauftests laufen gegen diese Daten.
* **Ablauftests:** `tests/mock-nce.php` bildet die Northbound-API mit diesen Daten nach (Passcode in `userName`, `vaildPeriod = null`, `portal.mode = null` bei PSK/PPSK, `fail[].data`, max. 100 Einträge je Seite). Der Testlauf startet Mock und App mit dem PHP-Webserver und spielt Login, Gruppen, SSIDs, Anlegen, Liste, Löschen, Druckeinstellungen und Login-Sperre durch.
* Die Tests nutzen eigene temporäre Ordner (`GV_DATA_DIR`) und lassen `data/` unberührt.

## Automatischer Upload (GitHub Actions)

`.github/workflows/deploy.yml` testet jede Änderung (`tests/run.php` mit PHP 7.4 und 8.3) und lädt sie danach per FTPS (lftp) auf den Webspace. Schlagen die Tests fehl, wird nichts hochgeladen. Ohne FTP-Secrets wird der Upload übersprungen.

* **Nie hochgeladen oder verändert:** `config.php`, `data/`-Laufzeitdaten, Zertifikatsdateien, `README.md`, `tests/`, Git-Daten. Auf dem Server wird nichts gelöscht.
* **Sicherheitsnetz:** Der Upload bricht ab, wenn im Zielordner Dateien liegen, aber keine App (`api.php` und `index.html`).
* **Probelauf:** *Actions → Deploy per FTP → Run workflow* mit „Nur Zielordner anzeigen“.

Einstellungen unter *Settings → Secrets and variables → Actions*:

| Name | Art | Inhalt |
|---|---|---|
| `FTP_IP` (oder `FTP_SERVER`) | Secret | IP oder Hostname des FTP-Servers |
| `FTP_USER` (oder `FTP_USERNAME`) | Secret | FTP-Benutzer |
| `FTP_PASS` (oder `FTP_PASSWORD`) | Secret | FTP-Passwort |
| `FTP_DIR` | Variable, optional | Zielordner mit `/` am Ende; ohne Angabe der Stammordner des FTP-Benutzers |
| `FTP_PROTOCOL` | Variable, optional | `ftps` (Standard) oder `ftp` |
| `FTP_PORT` | Variable, optional | Standard 21 |
| `FTP_SSL_FINGERPRINT` | Variable, optional | SHA1-Fingerabdruck des FTP-Zertifikats, das akzeptiert wird, auch wenn sein Name nicht zum Host passt |
| `FTP_SSL_VERIFY` | Variable, optional | `yes` (Standard); `no` schaltet die Prüfung ganz ab (nicht empfohlen) |

## NBI-Schnittstellen

| Funktion | Aufruf |
|---|---|
| Login / Logout | `POST` / `DELETE /controller/v2/tokens` |
| Benutzergruppen | `GET /controller/campus/v1/accountservice/usergroups` |
| Liste | `GET /controller/campus/v2/accountservice/accessuser/users` |
| Anlegen | `POST /controller/campus/v2/accountservice/accessuser/guest` (`userType` 6 = Passcode, 20 = Benutzer/Passwort) |
| Löschen | `POST /controller/campus/v1/accountservice/users/batch-delete` |
| Sites | `GET /controller/campus/v3/sites` |
| SSIDs je Site | `GET /controller/campus/v3/networkconfig/site/{siteId}/apssid` |
