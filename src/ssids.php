<?php
/*
 * NCE-Campus Guest Voucher - SSIDs
 * Ersteller: Patrick Brandt
 *
 * Gast-WLANs (Open + Portal) fuer den WLAN-QR-Code.
 */

const SSID_CANDIDATES = [
    '/controller/campus/v3/networkconfig/site/%s/apssid',
    '/controller/campus/v3/networkconfig/site/%s/apssidconfig',
    '/controller/campus/v1/networkconfig/site/%s/apssid',
    '/controller/campus/v1/networkservice/networkconfig/wlan/sites/%s/ssids',
];
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

const PORTAL_MODES_ON = ['portalthirdcontroller', 'portalthirdindirect', 'portalthird'];
/**
 * Einordnung einer SSID. Massgeblich sind die dokumentierten Felder:
 *   ssidAuth.mode        "open" = offenes WLAN
 *   ssidAuth.portal.mode portalDisable | portalThirdController (integriert) | portalThirdIndirect (vermittelt) | portalThird (extern)
 * Nur wenn der NCE diese Felder nicht liefert, wird ueber die Feldnamen geschaetzt.
 */
function classify_ssid($rec) {
    $auth = find_key($rec, 'ssidAuth');
    $mode = $auth && isset($auth['mode']) && is_scalar($auth['mode']) ? strtolower((string)$auth['mode']) : null;
    $pMode = $auth && is_array($auth['portal'] ?? null) && isset($auth['portal']['mode']) && is_scalar($auth['portal']['mode'])
        ? strtolower((string)$auth['portal']['mode']) : null;
    if ($pMode !== null) {
        $portal = in_array($pMode, PORTAL_MODES_ON, true);
        $open = $mode === null || $mode === 'open';
        return ['mode' => $mode, 'portalMode' => $auth['portal']['mode'], 'portal' => $portal, 'openPortal' => $portal && $open, 'basis' => 'ssidAuth'];
    }
    // Rueckfall: Schaetzung ueber Feldnamen/Werte
    $portal = false; $secured = false; $open = false;
    foreach (flat($rec) as $k => $v) {
        $isAuthKey = preg_match('/(auth|secur|encrypt|policy|mode|type)/', $k);
        $off = preg_match('/^(false|0|off|disable|disabled|none|null|portaldisable|)$/', $v) || strpos($v, 'disable') !== false;
        if (preg_match('/(portal|webauth|pushpage)/', $k) && !$off && !preg_match('/(free|escape|bypass)/', $k)) $portal = true;
        if ($isAuthKey && preg_match('/(portal|webauth|web-auth)/', $v) && !$off) $portal = true;
        if ($mode === null) {
            if ($isAuthKey && preg_match('/(wpa|psk|802\.1x|dot1x|ppsk|wep|sae|enterprise)/', $v)) $secured = true;
            if ($isAuthKey && preg_match('/^(open|none|no-?auth|open-?portal|open\+portal)/', $v)) $open = true;
        }
    }
    if ($mode !== null) { $open = $mode === 'open'; $secured = !$open; }
    return ['mode' => $mode, 'portalMode' => null, 'portal' => $portal, 'openPortal' => $portal && (!$secured || $open), 'basis' => 'geschaetzt'];
}
function load_ssids($token, $raw = false) {
    $sites = load_sites($token);
    $found = []; $errors = []; $samples = []; $tried = [];
    $ep = $_SESSION['ssidEp'] ?? null;
    foreach (array_slice($sites, 0, 100, true) as $sid => $sname) {
        $hit = null; $last = null;
        foreach ($ep !== null ? [$ep] : array_keys(SSID_CANDIDATES) as $ci) {
            $r = nce('GET', sprintf(SSID_CANDIDATES[$ci], rawurlencode($sid)), $token);
            if ($r['status'] === 401) lost_session();
            $ok = nce_ok($r); $last = $r;
            if ($ep === null) $tried[] = ['pfad' => SSID_CANDIDATES[$ci], 'ergebnis' => $ok ? 'ok' : nce_msg($r)];
            if ($ok) { $hit = $r; if ($ep === null) { $ep = $ci; $_SESSION['ssidEp'] = $ci; } break; }
        }
        if (!$hit) { if ($ep === null) break; $errors[] = $sname . ': ' . nce_msg($last); continue; }
        foreach (rows_of($hit['json']) as $rec) {
            if (!is_array($rec)) continue;
            $name = pick($rec, ['ssidName', 'ssid', 'name']);
            if ($name === null || $name === '') continue;
            $c = classify_ssid($rec);
            if ($raw && count($samples) < 3) $samples[] = ['site' => $sname, 'einordnung' => $c, 'nceDatensatz' => $rec];
            $key = (string)$name;
            if (!isset($found[$key])) $found[$key] = ['ssid' => $key, 'openPortal' => false, 'sites' => [], 'source' => 'config'];
            $found[$key]['openPortal'] = $found[$key]['openPortal'] || $c['openPortal'];
            $found[$key]['sites'][$sname] = true;
        }
    }
    // Rueckfall: SSIDs, an denen gerade Gaeste per Portal angemeldet sind
    $online = [];
    if (!$found) {
        $r = nce('GET', '/controller/campus/v1/accountservice/onlineusers', $token, null, ['pageIndex' => 1, 'pageSize' => 100]);
        if ($r['status'] === 401) lost_session();
        if (nce_ok($r)) {
            foreach (rows_of($r['json']) as $u) {
                if (!is_array($u)) continue;
                $ss = pick($u, ['ssid', 'accessSsid', 'apSsid', 'ssidName']);
                if ($ss === null || $ss === '') continue;
                if (!isset($found[$ss])) $found[$ss] = ['ssid' => (string)$ss, 'openPortal' => true, 'sites' => [], 'source' => 'online', 'users' => 0];
                $found[$ss]['users']++;
                if ($s = pick($u, ['siteName'])) $found[$ss]['sites'][$s] = true;
                if ($raw && count($online) < 2) $online[] = $u;
            }
        } else $errors[] = 'Online-User: ' . nce_msg($r);
    }
    $list = array_values(array_map(function ($x) { $x['sites'] = array_keys($x['sites']); sort($x['sites']); return $x; }, $found));
    usort($list, function ($a, $b) { return [$b['openPortal'], $a['ssid']] <=> [$a['openPortal'], $b['ssid']]; });
    $res = ['ssids' => $list, 'sites' => count($sites), 'errors' => array_slice($errors, 0, 10)];
    if ($raw) $res += ['endpoint' => $ep !== null ? SSID_CANDIDATES[$ep] : null, 'versuchtePfade' => $tried, 'beispiele' => $samples, 'onlineUserBeispiele' => $online];
    return $res;
}
