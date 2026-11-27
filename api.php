<?php
/*
 * NCE-Campus Guest Voucher - API
 * Ersteller: Patrick Brandt
 *
 * Proxy zum iMaster NCE-Campus (Northbound-API). Alle Daten kommen live aus dem NCE, es gibt keine Datenbank.
 * Der NCE-Token bleibt in der PHP-Session (serverseitig), der Browser erhaelt nur ein HttpOnly-Session-Cookie.
 * Voraussetzungen: PHP >= 7.4 mit den Erweiterungen curl, openssl, json.
 *
 * NBI-Endpunkte (iMaster NCE-Campus V300R025C10 NBI Guide):
 *   POST   /controller/v2/tokens                                    Token holen
 *   DELETE /controller/v2/tokens                                    Token freigeben
 *   GET    /controller/campus/v1/accountservice/usergroups          Benutzergruppen
 *   GET    /controller/campus/v2/accountservice/accessuser/users    Gaeste einer Gruppe abfragen
 *   POST   /controller/campus/v2/accountservice/accessuser/guest    Gast anlegen
 *   POST   /controller/campus/v1/accountservice/users/batch-delete  Gaeste loeschen
 *   GET    /controller/campus/v3/sites                              Sites (fuer die SSID-Suche)
 *   GET    /controller/campus/v3/networkconfig/site/{id}/apssid     SSIDs je Site fuer den WLAN-QR-Code
 */
@set_time_limit(120);
require __DIR__ . '/src/bootstrap.php';

// =====================================================================
// Routen - die Logik steckt in src/
// =====================================================================
$https = session_boot();
$route = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'gv')) {
    send(415, ['error' => 'Content-Type application/json und X-Requested-With erforderlich']);
}
if (!function_exists('curl_init')) send(500, ['error' => 'Die PHP-Erweiterung curl wird benoetigt.']);

// --- ohne Anmeldung ---
if ($method === 'GET'  && $route === 'config') send(200, app_config($https));
if ($method === 'POST' && $route === 'login')  send(200, login(read_json()));
if ($method === 'POST' && $route === 'logout') { logout(); send(200, ['ok' => true]); }

// --- ab hier nur angemeldet ---
$token = require_login();

switch ("$method $route") {
    case 'GET print-settings':
        send(200, ['settings' => print_settings(), 'defaults' => PRINT_DEFAULTS]);
    case 'POST print-settings':
        send(200, ['ok' => true, 'settings' => print_save(read_json())]);

    case 'GET groups':
        send(200, ['groups' => fetch_groups($token), 'current' => $_SESSION['groupId'] ?? null]);
    case 'POST group':
        $id = (string)(read_json()['id'] ?? '');
        if (!in_array($id, array_column(fetch_groups($token), 'id'), true)) send(400, ['error' => 'Unbekannte Benutzergruppe.']);
        $_SESSION['groupId'] = $id;
        send(200, ['ok' => true, 'current' => $id]);

    case 'GET ssids':
        send(200, load_ssids($token, false));
    case 'GET ssids/raw':
        send(200, load_ssids($token, true));

    case 'GET vouchers':
    case 'GET vouchers/raw':
        $groupId = require_group();
        list($all, $total) = fetch_group_users($token, $groupId);
        if ($route === 'vouchers/raw') voucher_raw($token, $groupId, $all, $total);
        send(200, ['total' => $total, 'vouchers' => voucher_list($all)]);
    case 'POST vouchers':
        send(200, voucher_create($token, require_group(), read_json()));
    case 'POST vouchers/delete':
        $ids = [];
        foreach ((read_json()['ids'] ?? []) as $id) if (is_string($id) && $id !== '') $ids[] = $id;
        $ids = array_slice(array_values(array_unique($ids)), 0, 1000);
        if (!$ids) send(400, ['error' => 'Keine Voucher ausgewaehlt.']);
        send(200, voucher_delete($token, require_group(), $ids));
}
send(404, ['error' => 'Unbekannte Route']);
