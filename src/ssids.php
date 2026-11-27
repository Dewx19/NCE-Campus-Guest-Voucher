<?php
/*
 * NCE-Campus Guest Voucher - SSIDs
 * Ersteller: Patrick Brandt
 *
 * Gast-WLANs (Open + Portal) fuer den WLAN-QR-Code. Grundlage sind die dokumentierten Felder des NBI-Guides,
 * bestaetigt durch echte NCE-Antworten (tests/fixtures/nce-apssid.json).
 */

// SSID-Konfiguration je Site (NBI-Guide, am NCE V300R025C10 bestaetigt)
const SSID_ENDPOINT = '/controller/campus/v3/networkconfig/site/%s/apssid';
// ssidAuth.portal.mode: portalDisable / null = kein Portal
const PORTAL_MODES_ON = ['portalThirdController', 'portalThirdIndirect', 'portalThird'];

function load_sites($token) {
    foreach ([100, null] as $size) { // NBI: pageSize max. 100; Rueckfall ohne Paging-Parameter
        $map = []; $ok = true;
        for ($page = 1; $page <= 50; $page++) {
            $r = nce('GET', '/controller/campus/v3/sites', $token, null, $size ? ['pageIndex' => $page, 'pageSize' => $size] : null);
            if ($r['status'] === 401) lost_session();
            if (!nce_ok($r)) { $ok = false; break; }
            $rows = rows_of($r['json']);
            foreach ($rows as $x) if (is_array($x) && ($id = pick($x, ['id', 'siteId'])) !== null) $map[(string)$id] = (string)(pick($x, ['name', 'siteName']) ?? $id);
            $total = (int)($r['json']['totalRecords'] ?? count($rows));
            if (!$size || count($map) >= $total || count($rows) < $size) break;
        }
        if ($ok) return $map;
    }
    return [];
}

/**
 * Einordnung einer SSID nach NBI-Guide:
 *   ssidAuth.mode        "open" = offenes WLAN (sonst psk, ppsk, ...)
 *   ssidAuth.portal.mode portalThirdController (integriert) | portalThirdIndirect (vermittelt) | portalThird (extern);
 *                        portalDisable oder null = kein Portal
 * Gast-WLAN fuer den QR-Code ("WLAN verbinden" ohne Passwort) = offen UND Portal aktiv.
 */
function classify_ssid($rec) {
    $auth = is_array($rec['ssidAuth'] ?? null) ? $rec['ssidAuth'] : [];
    $mode = isset($auth['mode']) && is_scalar($auth['mode']) ? (string)$auth['mode'] : null;
    $pMode = is_array($auth['portal'] ?? null) && isset($auth['portal']['mode']) && is_scalar($auth['portal']['mode']) ? (string)$auth['portal']['mode'] : null;
    $portal = in_array($pMode, PORTAL_MODES_ON, true);
    return ['mode' => $mode, 'portalMode' => $pMode, 'portal' => $portal, 'openPortal' => $mode === 'open' && $portal];
}

/** Alle SSIDs aller Sites, zusammengefasst nach Name. $raw = true liefert zusaetzlich die NCE-Datensaetze. */
function load_ssids($token, $raw = false) {
    $sites = load_sites($token);
    $found = []; $errors = []; $samples = [];
    foreach (array_slice($sites, 0, 100, true) as $sid => $sname) {
        $r = nce('GET', sprintf(SSID_ENDPOINT, rawurlencode($sid)), $token);
        if ($r['status'] === 401) lost_session();
        if (!nce_ok($r)) { $errors[] = $sname . ': ' . nce_msg($r); continue; }
        foreach (rows_of($r['json']) as $rec) {
            if (!is_array($rec)) continue;
            $name = $rec['name'] ?? null;
            if (!is_scalar($name) || (string)$name === '') continue;
            $c = classify_ssid($rec);
            if ($raw && count($samples) < 50) $samples[] = ['site' => $sname, 'einordnung' => $c, 'nceDatensatz' => $rec];
            $key = (string)$name;
            if (!isset($found[$key])) $found[$key] = ['ssid' => $key, 'openPortal' => false, 'sites' => []];
            $found[$key]['openPortal'] = $found[$key]['openPortal'] || $c['openPortal'];
            $found[$key]['sites'][$sname] = true;
        }
    }
    $list = array_values(array_map(function ($x) { $x['sites'] = array_keys($x['sites']); sort($x['sites']); return $x; }, $found));
    usort($list, function ($a, $b) { return [$b['openPortal'], $a['ssid']] <=> [$a['openPortal'], $b['ssid']]; });
    $res = ['ssids' => $list, 'sites' => count($sites), 'errors' => array_slice($errors, 0, 10)];
    if ($raw) $res += ['endpoint' => SSID_ENDPOINT, 'beispiele' => $samples];
    return $res;
}
