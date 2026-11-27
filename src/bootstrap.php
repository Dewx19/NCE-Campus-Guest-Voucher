<?php
/*
 * NCE-Campus Guest Voucher - Laden der Backend-Bausteine
 * Ersteller: Patrick Brandt
 *
 * Wird von api.php und von den Tests (tests/run.php) eingebunden. Startet keine Session und gibt nichts aus.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('APP_DIR', dirname(__DIR__));

require __DIR__ . '/util.php';      // JSON-Antworten, Feldsuche, Zufallscodes
require __DIR__ . '/settings.php';  // Standardwerte, config.php, NCE-Ziele
require __DIR__ . '/tls.php';       // Zertifikat des NCE
require __DIR__ . '/nce.php';       // Northbound-API-Client
require __DIR__ . '/auth.php';      // Session, Login-Sperre
require __DIR__ . '/print.php';     // Druckeinstellungen
require __DIR__ . '/vouchers.php';  // Benutzergruppen, Voucher
require __DIR__ . '/ssids.php';     // Gast-WLANs
