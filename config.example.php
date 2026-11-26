<?php
/*
 * NCE-Campus Guest Voucher - Konfiguration (Vorlage)
 * Ersteller: Patrick Brandt
 *
 * Optional: Ohne config.php nutzt die App die eingebauten Standardwerte (Ziel "Public Cloud Northbound API",
 * 80.158.58.176:18002). Zum Anpassen als config.php speichern; nur die Werte eintragen, die abweichen sollen
 * ('targets' ersetzt die Standard-Ziele komplett). config.php gehoert NICHT ins Repository (siehe .gitignore). Diese Datei gibt nur ein Array zurueck; beim direkten
 * Aufruf im Browser wird nichts ausgegeben.
 */
return [
    // NCE-Ziele (Northbound-API). Ab zwei Eintraegen erscheint beim Login ein Auswahlfeld; der erste ist die Vorauswahl.
    'targets' => [
        [
            // Public Cloud Northbound API
            'name'          => 'Public Cloud Northbound API',
            'protocol'      => 'https',
            'host'          => '80.158.58.176',
            'port'          => 18002,
            'tlsVerify'     => true,   // false nur zu Testzwecken
            'tlsServerName' => '',     // optional: Name im NCE-Zertifikat, wenn 'host' eine IP ist
            'caFile'        => '',     // optional: eigene CA-Datei (PEM); sonst wird das Zertifikat beim ersten Login uebernommen
        ],
    ],

    'nce' => [
        'timeoutSec' => 20,
    ],

    'security' => [
        'sessionMinutes' => 30,   // Abmeldung nach Inaktivitaet
        'loginMaxFails'  => 5,    // Fehlversuche je IP ...
        'loginWindowMin' => 15,   // ... innerhalb dieser Minuten
    ],

    'guest' => [
        'defaultGroupPath'  => 'ROOT\\Guest', // Vorauswahl der Gast-Benutzergruppe (Pfad wie im NCE)
        'roleIds'           => [],            // optional: Rollen-IDs fuer neue Gaeste
        'defaultType'       => 6,             // 6 = Passcode, 20 = Benutzername + Passwort
        'defaultValidDays'  => 1,
        'maxBatch'          => 200,
        'createConcurrency' => 4,             // parallele Anlage-Aufrufe gegen den NCE
        'codePrefix'        => 'WG-',         // Praefix fuer erzeugte Benutzernamen
        // Code-Regeln - an die Passwortrichtlinie im NCE anpassen (per NBI nicht abfragbar)
        'passcode'          => ['length' => 10, 'special' => false],
        'password'          => ['length' => 10, 'special' => true],
    ],

    'qr' => [
        'ssid' => '', // optional: Vorgabe fuer das Gast-WLAN im QR-Code (sonst Auswahl in der App)
    ],
];
