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
* **Passcodes** liefert der NCE im Feld `userName` mit – Liste, QR-Code und Druck funktionieren daher auch für Voucher, die direkt im NCE angelegt wurden. **Passwörter** von Benutzer/Passwort-Vouchern gibt die NBI nicht zurück; sie sind nur direkt nach dem Anlegen sichtbar (bis die Seite neu geladen wird).
* Neue Voucher tragen im Beschreibungsfeld eine Markierung `VM:<Batch>-<Nr>`, darüber ordnet die App sie dem Anlage-Durchlauf zu.

### QR-Code

Der QR-Code verbindet das Handy mit dem Gast-WLAN (`WIFI:S:<SSID>;T:nopass;;`). Danach öffnet sich die Portal-Anmeldeseite, dort wird der Code eingegeben.

* **Einstellungen** (oben rechts): Texte (Überschrift, Hinweise, Fußzeile, `{ssid}` als Platzhalter), angezeigte Felder und Layout (Karten je Zeile, QR-Größe, Schriftgröße, Abstand, Ausrichtung, Rahmen) der Druckkarten, mit Vorschau. Gespeichert in `data/print.json` für alle Benutzer der Installation.
* Die App liest die SSIDs aller Sites aus dem NCE und zeigt nur die mit **Open + Portal** (andere werden ausgeblendet). Ist die SSID-Konfiguration nicht lesbar, zeigt sie die SSIDs mit aktuell angemeldeten Portal-Gästen. Eine feste SSID kann zusätzlich über `qr.ssid` in `config.php` vorgegeben werden.
* Der Knopf **Rohdaten** zeigt die probierten Abfragepfade und Beispiel-Datensätze mit der Einordnung (Portal / verschlüsselt).

### Sicherheit

* Der NCE-Token bleibt in der PHP-Session (HttpOnly-Cookie, `SameSite=Strict`, Abmeldung nach Inaktivität).
* **TLS:** Fehlt eine CA-Datei, übernimmt die App beim ersten Login das Zertifikat des NCE (`data/nce-ca-<Ziel>.pem`) – noch bevor Zugangsdaten gesendet werden – und prüft ab dann jede Verbindung dagegen. Nach einer Erneuerung durch Huawei schlägt die Prüfung fehl; dann die Datei löschen, beim nächsten Login wird das neue Zertifikat übernommen. Alternativ eigene CA-Datei per `caFile` oder `nce-ca.pem` im App-Ordner.
* **Login-Schutz:** nach 5 Fehlversuchen je IP in 15 Minuten gesperrt (`data/login_attempts.json`).
* `.htaccess` sperrt `config.example.php`, `.pem`/`.json`-Dateien, `data/` und versteckte Ordner.

## Automatischer Upload (GitHub Actions)

`.github/workflows/deploy.yml` lädt jede Änderung auf `main` per FTPS (lftp) auf den Webspace. Ohne FTP-Secrets wird der Upload übersprungen.

* **Nie hochgeladen oder verändert:** `config.php`, `data/`-Laufzeitdaten, Zertifikatsdateien, `README.md`, Git-Daten. Auf dem Server wird nichts gelöscht.
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
| SSIDs je Site | mehrere übliche Pfade, siehe `SSID_CANDIDATES` in `api.php` |
| Online-User (Rückfall für SSIDs) | `GET /controller/campus/v1/accountservice/onlineusers` |
