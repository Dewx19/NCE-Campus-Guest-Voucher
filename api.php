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
 *   GET    SSID-Konfiguration je Site (siehe SSID_CANDIDATES)       SSIDs fuer den WLAN-QR-Code
 *   GET    /controller/campus/v1/accountservice/onlineusers         Rueckfall: SSIDs mit Portal-Anmeldungen
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(120);

// Standardwerte - eine optionale config.php ueberschreibt sie (targets wird dabei komplett ersetzt)
$DEFAULTS = [
    'targets' => [
        [
            'name'          => 'Public Cloud Northbound API', // Anzeigename/Kommentar des Ziels
            'protocol'      => 'https',
            'host'          => '80.158.58.176',
            'port'          => 18002,
            'tlsVerify'     => true,
            'tlsServerName' => '',
            'caFile'        => '',
        ],
    ],
    'nce'      => ['timeoutSec' => 20],
    'security' => ['sessionMinutes' => 30, 'loginMaxFails' => 5, 'loginWindowMin' => 15],
    'guest'    => [
        'defaultGroupPath' => 'ROOT\\Guest', 'roleIds' => [], 'defaultType' => 6, 'defaultValidDays' => 1,
        'maxBatch' => 200, 'createConcurrency' => 4, 'codePrefix' => 'WG-',
        'passcode' => ['length' => 10, 'special' => false], 'password' => ['length' => 10, 'special' => true],
    ],
    'qr'       => ['ssid' => ''],
];
$CFG = $DEFAULTS;
if (is_file(__DIR__ . '/config.php')) {
    $own = require __DIR__ . '/config.php';
    if (is_array($own)) {
        foreach ($own as $k => $v) {
            $CFG[$k] = ($k !== 'targets' && is_array($v) && isset($CFG[$k]) && is_array($CFG[$k])) ? array_replace_recursive($CFG[$k], $v) : $v;
        }
    }
}
const DATA_DIR = __DIR__ . '/data';

// Umgebungsvariablen (nur fuer lokale Tests) ueberschreiben das erste Ziel
foreach (['NCE_PROTOCOL' => 'protocol', 'NCE_HOST' => 'host', 'NCE_PORT' => 'port', 'NCE_TLS_SERVERNAME' => 'tlsServerName'] as $env => $key) {
    $v = getenv($env);
    if ($v !== false && isset($CFG['targets'][0])) $CFG['targets'][0][$key] = $key === 'port' ? (int)$v : $v;
}
if (getenv('NCE_TLS_VERIFY') === 'false' && isset($CFG['targets'][0])) $CFG['targets'][0]['tlsVerify'] = false;

$NCE = null; // aktives NCE-Ziel

// =====================================================================
// Allgemeine Helfer
// =====================================================================
function is_ph($v) { return is_string($v) && preg_match('/<<.*>>/s', $v) === 1; }

function send($status, $obj) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function read_json() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    if (!is_array($j)) send(400, ['error' => 'Ungueltiges JSON']);
    return $j;
}

function is_list_arr($a) { return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1)); }

/** Erster nicht-leerer Wert zu einem der Schluessel (auch in verschachtelten Objekten). */
function pick($a, $keys) {
    foreach ($keys as $k) if (isset($a[$k]) && !is_array($a[$k]) && $a[$k] !== '') return $a[$k];
    foreach ($a as $v) if (is_array($v) && !is_list_arr($v)) { $x = pick($v, $keys); if ($x !== null) return $x; }
    return null;
}

/** Liste der Datensaetze einer NCE-Antwort (data, data.list ...). */
function rows_of($j) {
    $d = $j['data'] ?? null;
    if (is_list_arr($d)) return $d;
    if (is_array($d)) { foreach ($d as $v) if (is_list_arr($v) && $v && is_array($v[0])) return $v; return [$d]; }
    return [];
}

// Zufallscodes
const A_UP = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const A_LO = 'abcdefghijkmnpqrstuvwxyz';
const A_DI = '23456789';
const A_SP = '!@$*-_'; // Sonderzeichen, die in QR-Codes und beim Abtippen unkritisch sind
function rnd_from($set, $n) { $s = ''; $l = strlen($set); for ($i = 0; $i < $n; $i++) $s .= $set[random_int(0, $l - 1)]; return $s; }
/** Mind. 1 Gross-, 1 Kleinbuchstabe, 1 Ziffer (+ optional 1 Sonderzeichen), gemischt. */
function mixed_code($n, $special = false) {
    $c = [rnd_from(A_UP, 1), rnd_from(A_LO, 1), rnd_from(A_DI, 1)];
    if ($special) $c[] = rnd_from(A_SP, 1);
    while (count($c) < $n) $c[] = rnd_from(A_UP . A_LO . A_DI, 1);
    for ($i = count($c) - 1; $i > 0; $i--) { $j = random_int(0, $i); $t = $c[$i]; $c[$i] = $c[$j]; $c[$j] = $t; }
    return implode('', $c);
}

// Markierung im Beschreibungsfeld: "VM:<Batch>-<Nr> | <Freitext>" ordnet Voucher einem Anlage-Durchlauf zu
function marker_of($desc) { return (is_string($desc) && preg_match('/^(VM:([A-Z0-9]+)-\d+)/', $desc, $m)) ? [$m[1], $m[2]] : [null, null]; }
function strip_marker($desc) { return trim(preg_replace('/^VM:[A-Z0-9-]+\s*\|?\s*/', '', (string)$desc)); }

// =====================================================================
// NCE-Ziele (aus config.php)
// =====================================================================
function targets_public() {
    global $CFG;
    $out = [];
    foreach (($CFG['targets'] ?? []) as $i => $t) {
        if (!empty($t['host']) && !is_ph($t['host'])) $out[] = ['id' => $i, 'name' => (string)($t['name'] ?? ('Ziel ' . ($i + 1)))];
    }
    return $out;
}
function target_apply($id) {
    global $CFG, $NCE;
    $t = $CFG['targets'][$id] ?? null;
    if (!$t || empty($t['host']) || is_ph($t['host'])) return false;
    $NCE = array_merge(['name' => '', 'protocol' => 'https', 'port' => 18002, 'tlsVerify' => true, 'tlsServerName' => '', 'caFile' => ''], $t);
    $NCE['_id'] = (int)$id;
    return true;
}

// =====================================================================
// TLS: CA-Datei bzw. beim ersten Login uebernommenes Zertifikat
// =====================================================================
/** Reihenfolge: caFile aus config.php > nce-ca-<Ziel>.pem bzw. nce-ca.pem im App-Ordner > data/nce-ca-<Ziel>.pem (automatisch) */
function ca_candidates() {
    global $NCE;
    $id = (int)$NCE['_id'];
    $f = trim((string)$NCE['caFile']);
    if ($f !== '') return [['config', preg_match('#^(/|[A-Za-z]:[\\\\/])#', $f) ? $f : __DIR__ . '/' . $f]];
    return [['app', __DIR__ . "/nce-ca-$id.pem"], ['app', __DIR__ . '/nce-ca.pem'], ['auto', DATA_DIR . "/nce-ca-$id.pem"]];
}
function ca_file($withSource = false) {
    $c = ca_candidates();
    if ($c[0][0] === 'config') return $withSource ? $c[0] : $c[0][1];
    foreach ($c as $x) if (is_file($x[1])) return $withSource ? $x : $x[1];
    return null;
}
function tls_needed() { global $NCE; return $NCE['protocol'] === 'https' && $NCE['tlsVerify'] !== false; }

/** Zertifikatskette ohne Pruefung abrufen - dabei werden keine Zugangsdaten gesendet. */
function tls_fetch_chain() {
    global $NCE;
    if (!function_exists('openssl_x509_export')) return [null, 'PHP-Erweiterung openssl fehlt - Zertifikat kann nicht automatisch uebernommen werden.'];
    $sni = $NCE['tlsServerName'] !== '' ? $NCE['tlsServerName'] : $NCE['host'];
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert_chain' => true, 'verify_peer' => false, 'verify_peer_name' => false,
        'allow_self_signed' => true, 'SNI_enabled' => true, 'peer_name' => $sni,
    ]]);
    $host = strpos($NCE['host'], ':') !== false ? '[' . $NCE['host'] . ']' : $NCE['host'];
    $sock = @stream_socket_client("ssl://$host:{$NCE['port']}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return [null, "Zertifikat konnte nicht abgerufen werden: $errstr ($errno)"];
    $params = stream_context_get_params($sock);
    fclose($sock);
    $pem = '';
    foreach (($params['options']['ssl']['peer_certificate_chain'] ?? []) as $c) { $out = ''; if (openssl_x509_export($c, $out)) $pem .= trim($out) . "\n"; }
    return $pem === '' ? [null, 'Der NCE hat kein Zertifikat geliefert.'] : [$pem, null];
}
/** Fehlt eine CA-Datei, Zertifikat beim ersten Login uebernehmen (Vertrauen bei erster Verbindung). */
function tls_auto_pin() {
    if (!tls_needed() || ca_file()) return false;
    list($pem, $err) = tls_fetch_chain();
    if ($err) send(502, ['error' => $err]);
    $c = ca_candidates(); $file = $c[count($c) - 1][1];
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
    if (@file_put_contents($file, $pem, LOCK_EX) === false) send(500, ['error' => 'Das NCE-Zertifikat konnte nicht in data/ gespeichert werden - bitte Schreibrechte fuer den Ordner data/ setzen.']);
    @chmod($file, 0600);
    return true;
}
function tls_info() {
    global $NCE;
    if ($NCE['protocol'] !== 'https') return ['mode' => 'http'];
    if ($NCE['tlsVerify'] === false) return ['mode' => 'unverified'];
    $x = ca_file(true);
    if (!$x) return ['mode' => 'system'];
    $info = ['mode' => 'verified', 'source' => $x[0], 'since' => is_file($x[1]) ? filemtime($x[1]) : null];
    $pem = @file_get_contents($x[1]);
    if ($pem && function_exists('openssl_x509_parse') && ($c = @openssl_x509_parse($pem))) {
        $info['subject'] = $c['subject']['CN'] ?? null;
        $info['validTo'] = $c['validTo_time_t'] ?? null;
    }
    return $info;
}
function tls_hint($r) {
    if (stripos((string)$r['err'], 'certificate') === false && stripos((string)$r['err'], 'SSL') === false) return '';
    $x = ca_file(true);
    if (!$x) return ' Hinweis: Der Webspace kennt die Zertifizierungsstelle des NCE nicht.';
    if (!is_file($x[1])) return ' Hinweis: Die CA-Datei ' . basename($x[1]) . ' wurde nicht gefunden (caFile in config.php pruefen).';
    $where = $x[0] === 'auto' ? 'data/' . basename($x[1]) : basename($x[1]);
    return " Hinweis: Das gespeicherte NCE-Zertifikat ($where) passt nicht mehr - z. B. nach einer Erneuerung. "
         . 'Datei per FTP loeschen; beim naechsten Login wird das aktuelle Zertifikat uebernommen.';
}

// =====================================================================
// NCE-Client (cURL)
// =====================================================================
function nce_curl($method, $path, $token = null, $body = null, $query = null) {
    global $CFG, $NCE;
    $host = $NCE['host'];
    $resolve = null;
    if ($NCE['protocol'] === 'https' && $NCE['tlsServerName'] !== '' && $NCE['tlsServerName'] !== $host) {
        $resolve = [$NCE['tlsServerName'] . ':' . $NCE['port'] . ':' . $host]; // per IP verbinden, Zertifikat gegen den Namen pruefen
        $host = $NCE['tlsServerName'];
    }
    $url = $NCE['protocol'] . '://' . $host . ':' . $NCE['port'] . $path;
    if ($query) {
        $q = array_filter($query, function ($v) { return $v !== null && $v !== ''; });
        if ($q) $url .= '?' . http_build_query($q);
    }
    $h = ['Accept: application/json', 'Accept-Language: en-US'];
    if ($body !== null) $h[] = 'Content-Type: application/json;charset=UTF-8';
    if ($token) $h[] = 'X-ACCESS-TOKEN: ' . $token;
    $verify = tls_needed();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => (int)($CFG['nce']['timeoutSec'] ?? 20),
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    if ($verify && ($ca = ca_file())) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    return $ch;
}
function nce_result($ch, $text, $err) {
    $json = null;
    if (is_string($text) && $text !== '') { $j = json_decode($text, true); if (is_array($j)) $json = $j; }
    return ['status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => $json, 'err' => $err];
}
function nce($method, $path, $token = null, $body = null, $query = null) {
    $ch = nce_curl($method, $path, $token, $body, $query);
    $text = curl_exec($ch);
    $r = nce_result($ch, $text, $text === false ? curl_error($ch) : null);
    curl_close($ch);
    return $r;
}
/** Mehrere POSTs mit begrenzter Parallelitaet. Rueckgabe in Eingabereihenfolge. */
function nce_pool_post($path, $token, $bodies, $concurrency) {
    $mh = curl_multi_init();
    $results = []; $active = []; $next = 0; $n = count($bodies);
    while ($next < $n || $active) {
        while ($next < $n && count($active) < $concurrency) {
            $ch = nce_curl('POST', $path, $token, $bodies[$next]);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = $next++;
        }
        do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        if ($running) curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle']; $id = spl_object_id($ch);
            $results[$active[$id]] = nce_result($ch, curl_multi_getcontent($ch), $info['result'] !== CURLE_OK ? curl_error($ch) : null);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            unset($active[$id]);
        }
    }
    curl_multi_close($mh);
    ksort($results);
    return $results;
}
function nce_ok($r) {
    return !$r['err'] && in_array($r['status'], [200, 201], true) && is_array($r['json'])
        && (!isset($r['json']['errcode']) || (string)$r['json']['errcode'] === '0');
}
function nce_msg($r) {
    if ($r['err']) return $r['err'] . tls_hint($r);
    $j = $r['json'];
    if ($j && (isset($j['errmsg']) || isset($j['errcode']))) return trim(($j['errmsg'] ?? '') . ' (errcode ' . ($j['errcode'] ?? '?') . ')');
    return 'HTTP ' . $r['status'];
}
/** Antwort pruefen: bei 401 Sitzung beenden, sonst 502 mit Meldung. */
function nce_check($r, $what) {
    if ($r['status'] === 401) lost_session();
    if (!nce_ok($r)) send(502, ['error' => "$what fehlgeschlagen: " . nce_msg($r)]);
}

// =====================================================================
// Login-Schutz (Datei statt Datenbank)
// =====================================================================
/** Zaehlt Fehlversuche je IP im Zeitfenster; $add = true traegt einen Fehlversuch ein. */
function login_attempts($ip, $windowSec, $add) {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
    $fh = @fopen(DATA_DIR . '/login_attempts.json', 'c+');
    if (!$fh) return 0; // ohne Schreibrechte kein Schutz, der Login funktioniert trotzdem
    flock($fh, LOCK_EX);
    $all = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    $now = time();
    foreach ($all as $k => $list) {
        $all[$k] = array_values(array_filter($list, function ($t) use ($now) { return $t > $now - 86400; }));
        if (!$all[$k]) unset($all[$k]);
    }
    if ($add) $all[$ip][] = $now;
    $count = count(array_filter($all[$ip] ?? [], function ($t) use ($now, $windowSec) { return $t > $now - $windowSec; }));
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all));
    flock($fh, LOCK_UN); fclose($fh);
    return $count;
}

// =====================================================================
// Druckeinstellungen (Datei data/print.json, fuer alle Benutzer dieser Installation)
// =====================================================================
const PRINT_DEFAULTS = [
    'title'        => 'WLAN Gast-Zugang',
    'hintPasscode' => 'QR-Code scannen, dann den Passcode auf der Anmeldeseite eingeben',
    'hintUser'     => 'QR-Code scannen, dann Benutzername und Passwort auf der Anmeldeseite eingeben',
    'footer'       => '',
    'showSsid'     => true,
    'showValid'    => true,
    'showDesc'     => false,
    'columns'      => 3,      // Karten je Zeile (1-4)
    'qrSize'       => 42,     // QR-Code-Breite in mm
    'fontScale'    => 100,    // Schriftgroesse in %
    'align'        => 'center',
    'border'       => true,
    'rounded'      => true,
    'gap'          => 10,     // Abstand zwischen den Karten in mm
];
function print_settings() {
    $j = @json_decode((string)@file_get_contents(DATA_DIR . '/print.json'), true);
    return print_clean(is_array($j) ? $j : []);
}
function print_clean($in) {
    $o = PRINT_DEFAULTS;
    foreach (['title' => 80, 'hintPasscode' => 200, 'hintUser' => 200, 'footer' => 200] as $k => $max) {
        if (isset($in[$k]) && is_string($in[$k])) { $t = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $in[$k])); $o[$k] = function_exists('mb_substr') ? mb_substr($t, 0, $max) : substr($t, 0, $max); }
    }
    foreach (['showSsid', 'showValid', 'showDesc', 'border', 'rounded'] as $k) if (isset($in[$k])) $o[$k] = (bool)$in[$k];
    $lim = ['columns' => [1, 4], 'qrSize' => [20, 90], 'fontScale' => [70, 160], 'gap' => [0, 20]];
    foreach ($lim as $k => [$lo, $hi]) if (isset($in[$k]) && is_numeric($in[$k])) $o[$k] = max($lo, min($hi, (int)$in[$k]));
    if (isset($in['align']) && in_array($in['align'], ['left', 'center'], true)) $o['align'] = $in['align'];
    return $o;
}

// =====================================================================
// Session
// =====================================================================
function session_boot() {
    global $CFG;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('gvsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $idle = (int)($CFG['security']['sessionMinutes'] ?? 30) * 60;
    if (isset($_SESSION['token']) && time() - ($_SESSION['last'] ?? 0) > $idle) $_SESSION = [];
    return $https;
}
function session_end_clear() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']); }
    @session_destroy();
}
function lost_session() { session_end_clear(); send(401, ['error' => 'NCE-Token abgelaufen - bitte neu anmelden.']); }

// =====================================================================
// Benutzergruppen
// =====================================================================
function fetch_groups($token) {
    $r = nce('GET', '/controller/campus/v1/accountservice/usergroups', $token, null, ['pageIndex' => 1, 'pageSize' => 1000]);
    nce_check($r, 'Abfrage der Benutzergruppen');
    $out = [];
    foreach (rows_of($r['json']) as $g) {
        if (!empty($g['id'])) $out[] = ['id' => $g['id'], 'name' => $g['name'] ?? '', 'path' => $g['fullPathName'] ?? ($g['name'] ?? '')];
    }
    usort($out, function ($x, $y) { return strcasecmp($x['path'], $y['path']); });
    return $out;
}
function require_group() {
    if (empty($_SESSION['groupId'])) send(409, ['error' => 'Keine Gast-Benutzergruppe ausgewaehlt.', 'needGroup' => true]);
    return $_SESSION['groupId'];
}

// =====================================================================
// SSIDs fuer den WLAN-QR-Code
// Die SSID-Abfrage ist in der oeffentlichen Huawei-Doku nicht beschrieben: es werden die ueblichen Pfade probiert,
// der erste funktionierende wird fuer die Sitzung gemerkt. Antworten werden tolerant ausgewertet.
// =====================================================================
const SSID_CANDIDATES = [
    '/controller/campus/v3/networkconfig/site/%s/apssidconfig',
    '/controller/campus/v3/networkconfig/site/%s/apssid',
    '/controller/campus/v1/networkconfig/site/%s/apssid',
    '/controller/campus/v1/networkservice/networkconfig/wlan/sites/%s/ssids',
];
function load_sites($token) {
    foreach ([1000, 100, 20, null] as $size) {
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
/** Alle Felder flach als [pfad => wert], klein geschrieben. */
function flat($a, $prefix = '') {
    $out = [];
    foreach ($a as $k => $v) {
        $key = strtolower($prefix . (is_string($k) ? $k : ''));
        if (is_array($v)) $out += flat($v, $key . '.');
        else $out[$key] = is_bool($v) ? ($v ? 'true' : 'false') : strtolower((string)$v);
    }
    return $out;
}
/** Einordnung einer SSID: Portal-Authentifizierung? Verschluesselt (PSK/802.1X)?
 *  Liefert der NCE ssidAuth.mode (z. B. "open"), ist dieses Feld fuer offen/verschluesselt massgeblich. */
function classify_ssid($rec) {
    $portal = false; $secured = false; $open = false;
    $f = flat($rec);
    $mode = null;
    foreach ($f as $k => $v) if (preg_match('/(^|\.)ssidauth\.(mode|authmode)$/', $k)) { $mode = $v; break; }
    foreach ($f as $k => $v) {
        $isAuthKey = preg_match('/(auth|secur|encrypt|policy|mode|type)/', $k);
        $off = preg_match('/^(false|0|off|disable|disabled|none|null|)$/', $v);
        if (preg_match('/(portal|webauth|pushpage)/', $k) && !$off && !preg_match('/(free|escape|bypass)/', $k)) $portal = true;
        if ($isAuthKey && preg_match('/(portal|webauth|web-auth)/', $v)) $portal = true;
        if ($mode === null) {
            if ($isAuthKey && preg_match('/(wpa|psk|802\.1x|dot1x|ppsk|wep|sae|enterprise)/', $v)) $secured = true;
            if ($isAuthKey && preg_match('/^(open|none|no-?auth|open-?portal|open\+portal)/', $v)) $open = true;
        }
    }
    if ($mode !== null) {
        $open = (bool)preg_match('/^(open|none|no-?auth)/', $mode);
        $secured = !$open;
        if (strpos($mode, 'portal') !== false) { $portal = true; $open = true; $secured = false; }
    }
    return ['mode' => $mode, 'portal' => $portal, 'openPortal' => $portal && (!$secured || $open)];
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

/** Code/Passwort aus einem NCE-Datensatz - maskierte Werte (****) zaehlen nicht. */
function secret_of($u, $keys) {
    $v = pick($u, $keys);
    if ($v === null) return null;
    $v = (string)$v;
    return ($v === '' || preg_match('/^[*\x{2022}]+$/u', $v)) ? null : $v;
}

/** Rohdaten der Voucher-Abfrage zur Pruefung, welche Felder der NCE liefert (nur angemeldet). */
function voucher_raw($token, $groupId, $all, $total) {
    $fields = [];
    foreach ($all as $u) if (is_array($u)) foreach (array_keys(flat($u)) as $k) $fields[$k] = ($fields[$k] ?? 0) + 1;
    ksort($fields);
    $hint = array_values(array_filter(array_keys($fields), function ($k) { return preg_match('/(pass|code|pwd|secret|key)/', $k); }));
    $res = [
        'abfrage'      => ['pfad' => '/controller/campus/v2/accountservice/accessuser/users', 'userGroupId' => $groupId],
        'anzahl'       => count($all),
        'totalRecords' => $total,
        'felder'       => $fields,
        'moeglicheCodeFelder' => $hint,
        'beispiele'    => array_slice($all, 0, 5),
    ];
    // Versuch: Detailabfrage eines einzelnen Vouchers (liefert evtl. mehr Felder als die Liste)
    $first = null;
    foreach ($all as $u) if (is_array($u) && !empty($u['id'])) { $first = $u; if ((int)($u['userType'] ?? 0) === 6) break; }
    if ($first) {
        foreach (['/controller/campus/v2/accountservice/accessuser/users/%s', '/controller/campus/v1/accountservice/users/%s'] as $path) {
            $r = nce('GET', sprintf($path, rawurlencode((string)$first['id'])), $token);
            if ($r['status'] === 401) lost_session();
            $res['detailVersuche'][] = ['pfad' => $path, 'id' => (string)$first['id'], 'status' => $r['status'],
                'ergebnis' => nce_ok($r) ? ($r['json']['data'] ?? $r['json']) : nce_msg($r)];
        }
    }
    send(200, $res);
}

// =====================================================================
// Routen
// =====================================================================
$https = session_boot();
$route = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'gv')) {
    send(415, ['error' => 'Content-Type application/json und X-Requested-With erforderlich']);
}
if (!function_exists('curl_init')) send(500, ['error' => 'Die PHP-Erweiterung curl wird benoetigt.']);

if ($method === 'GET' && $route === 'config') {
    $tid = $_SESSION['targetId'] ?? null;
    $g = $CFG['guest'] ?? [];
    send(200, [
        'loggedIn'   => isset($_SESSION['token']),
        'user'       => $_SESSION['user'] ?? null,
        'targets'    => targets_public(),
        'targetName' => $tid !== null ? ($CFG['targets'][$tid]['name'] ?? null) : null,
        'tls'        => ($tid !== null && isset($_SESSION['token']) && target_apply($tid)) ? tls_info() : null,
        'insecureTransport' => !$https && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true),
        'guest' => [
            'defaultGroupPath' => $g['defaultGroupPath'] ?? 'ROOT\\Guest',
            'defaultType'      => (int)($g['defaultType'] ?? 6),
            'defaultValidDays' => (int)($g['defaultValidDays'] ?? 1),
            'maxBatch'         => (int)($g['maxBatch'] ?? 200),
            'codePrefix'       => (string)($g['codePrefix'] ?? 'WG-'),
        ],
        'ssid' => (string)($CFG['qr']['ssid'] ?? ''),
    ]);
}

if ($method === 'POST' && $route === 'login') {
    $b = read_json();
    $user = trim((string)($b['username'] ?? '')); $pass = (string)($b['password'] ?? '');
    if ($user === '' || $pass === '') send(400, ['error' => 'Benutzername und Passwort erforderlich.']);
    $tlist = targets_public();
    if (!$tlist) send(503, ['error' => 'Kein NCE-Ziel konfiguriert: targets in config.php ausfuellen.']);
    $tid = array_key_exists('target', $b) ? (int)$b['target'] : $tlist[0]['id'];
    if (!in_array($tid, array_column($tlist, 'id'), true) || !target_apply($tid)) send(400, ['error' => 'Unbekanntes NCE-Ziel.']);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    $sec = $CFG['security'] ?? [];
    $window = 60 * (int)($sec['loginWindowMin'] ?? 15);
    if (login_attempts($ip, $window, false) >= (int)($sec['loginMaxFails'] ?? 5)) send(429, ['error' => 'Zu viele fehlgeschlagene Anmeldungen. Bitte spaeter erneut versuchen.']);
    $pinned = tls_auto_pin(); // erst Zertifikat sichern, dann Zugangsdaten nur ueber gepruefte Verbindung senden
    $r = nce('POST', '/controller/v2/tokens', null, ['userName' => $user, 'password' => $pass]);
    $tok = $r['json']['data']['token_id'] ?? null;
    if ($r['status'] !== 200 || !$tok) {
        if ($r['status'] === 401) {
            login_attempts($ip, $window, true);
            send(401, ['error' => 'Anmeldung vom NCE abgelehnt (' . nce_msg($r) . '). Moegliche Ursachen: falsche Zugangsdaten, kein Northbound-Account (Typ Third-party, Rolle Open Api Operator), Passwort abgelaufen oder Account gesperrt.']);
        }
        send(502, ['error' => 'NCE nicht erreichbar oder unerwartete Antwort: ' . nce_msg($r)]);
    }
    session_regenerate_id(true);
    $_SESSION = ['token' => $tok, 'user' => $user, 'last' => time(), 'targetId' => $tid];
    send(200, ['ok' => true, 'user' => $user, 'certPinned' => $pinned]);
}

if ($method === 'POST' && $route === 'logout') {
    if (isset($_SESSION['token']) && target_apply($_SESSION['targetId'] ?? 0)) {
        try { nce('DELETE', '/controller/v2/tokens', $_SESSION['token'], ['token' => $_SESSION['token']]); } catch (Throwable $e) { /* egal */ }
    }
    session_end_clear();
    send(200, ['ok' => true]);
}

// --- ab hier nur angemeldet ---
if (!isset($_SESSION['token'])) send(401, ['error' => 'Nicht angemeldet.']);
if (!target_apply($_SESSION['targetId'] ?? 0)) { session_end_clear(); send(401, ['error' => 'Das NCE-Ziel dieser Sitzung existiert nicht mehr - bitte neu anmelden.']); }
$token = $_SESSION['token'];
$_SESSION['last'] = time();

if ($method === 'GET' && $route === 'print-settings') {
    send(200, ['settings' => print_settings(), 'defaults' => PRINT_DEFAULTS]);
}

if ($method === 'POST' && $route === 'print-settings') {
    $in = read_json();
    $o = !empty($in['reset']) ? PRINT_DEFAULTS : print_clean($in['settings'] ?? []);
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
    if (@file_put_contents(DATA_DIR . '/print.json', json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        send(500, ['error' => 'Einstellungen konnten nicht gespeichert werden - ist der Ordner data/ fuer PHP beschreibbar?']);
    }
    send(200, ['ok' => true, 'settings' => $o]);
}

if ($method === 'GET' && $route === 'groups') {
    send(200, ['groups' => fetch_groups($token), 'current' => $_SESSION['groupId'] ?? null]);
}

if ($method === 'POST' && $route === 'group') {
    $id = (string)(read_json()['id'] ?? '');
    if (!in_array($id, array_column(fetch_groups($token), 'id'), true)) send(400, ['error' => 'Unbekannte Benutzergruppe.']);
    $_SESSION['groupId'] = $id;
    send(200, ['ok' => true, 'current' => $id]);
}

if ($method === 'GET' && ($route === 'ssids' || $route === 'ssids/raw')) {
    if ($route === 'ssids/raw') unset($_SESSION['ssidEp']); // Rohdaten: alle Pfade neu probieren
    send(200, load_ssids($token, $route === 'ssids/raw'));
}

if ($method === 'GET' && ($route === 'vouchers' || $route === 'vouchers/raw')) {
    // Live aus dem NCE. Passcodes und Passwoerter liefert die NBI in der Regel nicht zurueck - sie werden nur
    // angezeigt, wenn der NCE sie mitliefert (oder direkt nach dem Anlegen im Browser).
    $groupId = require_group();
    $all = []; $total = 0;
    for ($page = 1; $page <= 10; $page++) {
        $r = nce('GET', '/controller/campus/v2/accountservice/accessuser/users', $token, null, [
            'userGroupId' => $groupId, 'pageIndex' => $page, 'pageSize' => 1000, 'sortKey' => 'userName', 'sortDir' => 'ASC']);
        nce_check($r, 'Abfrage der Voucher');
        $data = rows_of($r['json']);
        $total = (int)($r['json']['totalRecords'] ?? count($data));
        $all = array_merge($all, $data);
        if (count($all) >= $total || !$data) break;
    }
    if ($route === 'vouchers/raw') voucher_raw($token, $groupId, $all, $total);
    $nowMs = (int)(microtime(true) * 1000);
    $vouchers = [];
    foreach ($all as $u) {
        if (!is_array($u) || empty($u['id'])) continue;
        list($marker, $batch) = marker_of($u['description'] ?? '');
        $type = (int)($u['userType'] ?? 0);
        $until = (int)($u['vaildPeriod'] ?? $u['validPeriod'] ?? 0) ?: null; // "vaildPeriod" (sic) laut NBI-Guide
        $vouchers[] = [
            'id'          => (string)$u['id'],
            'userType'    => $type,
            'userName'    => $type === 6 ? null : (($u['userName'] ?? '') ?: null),
            'passcode'    => $type === 6 ? secret_of($u, ['passcode', 'passCode', 'accessCode', 'guestPasscode', 'password', 'passWord']) : null,
            'password'    => $type === 6 ? null : secret_of($u, ['password', 'passWord']),
            'description' => strip_marker($u['description'] ?? ''),
            'marker'      => $marker,
            'batch'       => $batch,
            'validUntil'  => $until,
            'expired'     => $until ? $until < $nowMs : false,
            'disabled'    => (int)($u['accountStatus'] ?? 0) === 3,
            'maxAccessNum'=> $u['maxAccessNum'] ?? null,
        ];
    }
    send(200, ['total' => $total, 'vouchers' => $vouchers]);
}

if ($method === 'POST' && $route === 'vouchers') {
    // Der Browser sendet grosse Batches in Teilstuecken (max. 25 je Aufruf), damit keine PHP-Zeitlimits greifen.
    $groupId = require_group();
    $b = read_json(); $g = $CFG['guest'] ?? [];
    $maxBatch = (int)($g['maxBatch'] ?? 200);
    $offset = max(0, (int)($b['offset'] ?? 0));
    if ($offset >= $maxBatch) send(400, ['error' => 'Maximale Batch-Groesse ueberschritten.']);
    $count = max(1, min((int)($b['count'] ?? 1), 25, $maxBatch - $offset));
    $type = (int)($b['userType'] ?? 6) === 20 ? 20 : 6;
    $prefix = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['prefix'] ?? ($g['codePrefix'] ?? 'WG-'))), 0, 12);
    $free = substr(str_replace(["\r", "\n"], ' ', (string)($b['description'] ?? '')), 0, 150);
    $batch = (string)($b['batch'] ?? '');
    if (!preg_match('/^[A-Z0-9]{4,12}$/', $batch)) $batch = 'B' . strtoupper(base_convert((string)time(), 10, 36)) . rnd_from(A_UP . A_DI, 3);

    $base = ['userType' => $type, 'userGroupId' => $groupId, 'nextUpdateUserpass' => false, 'name' => ''];
    if (!empty($g['roleIds'])) $base['roleIds'] = $g['roleIds'];
    if (isset($b['maxAccessNum']) && $b['maxAccessNum'] !== '') $base['maxAccessNum'] = (string)(int)$b['maxAccessNum'];
    if ((int)($b['effectiveType'] ?? 1) === 1) { // gueltig ab erstem Login fuer eine Dauer
        $unit = in_array($b['effectiveUnit'] ?? '', ['min', 'hour', 'day'], true) ? $b['effectiveUnit'] : 'day';
        $lim = ['min' => 59, 'hour' => 23, 'day' => 365][$unit];
        $base += ['effectiveType' => 1, 'effectiveUnit' => $unit, 'effectiveTime' => max(1, min((int)($b['effectiveTime'] ?? 1), $lim))];
    } else { // fester Ablauf ab Erstellung
        $days = max(1, min((int)($b['validDays'] ?? ($g['defaultValidDays'] ?? 1)), 365));
        $base += ['effectiveType' => 0, 'validPeriodLong' => (int)(microtime(true) * 1000) + $days * 86400000];
    }
    // Code-Regeln (die NBI bietet keine Abfrage der Passwortrichtlinie; Werte in config.php an die NCE-Richtlinie anpassen)
    $pw = $g['password'] ?? []; $pc = $g['passcode'] ?? [];
    $pwLen = max(6, min((int)($pw['length'] ?? 10), 32)); $pwSpecial = (bool)($pw['special'] ?? true);
    $pcLen = max(4, min((int)($pc['length'] ?? 10), 12)); $pcSpecial = (bool)($pc['special'] ?? false);

    $jobs = [];
    for ($i = 0; $i < $count; $i++) {
        $marker = 'VM:' . $batch . '-' . str_pad((string)($offset + $i + 1), 3, '0', STR_PAD_LEFT);
        $body = $base + ['description' => $free !== '' ? "$marker | $free" : $marker];
        if ($type === 6) $creds = ['passcode' => mixed_code($pcLen, $pcSpecial)];
        else $creds = ['userName' => $prefix . rnd_from(A_UP . A_DI, 8), 'password' => mixed_code($pwLen, $pwSpecial)];
        $jobs[] = ['marker' => $marker, 'body' => $body + $creds, 'creds' => $creds];
    }
    $resp = nce_pool_post('/controller/campus/v2/accountservice/accessuser/guest', $token, array_column($jobs, 'body'), max(1, (int)($g['createConcurrency'] ?? 4)));
    $ok = []; $failed = []; $aborted = false;
    foreach ($jobs as $i => $j) {
        $r = $resp[$i];
        if ($r['status'] === 401) { $aborted = true; continue; }
        if (nce_ok($r) || (!$r['err'] && in_array($r['status'], [200, 201], true) && !$r['json'])) $ok[] = ['marker' => $j['marker']] + $j['creds'];
        else $failed[] = ['marker' => $j['marker'], 'error' => nce_msg($r)];
    }
    if ($aborted && !$ok) lost_session();
    send(200, ['batch' => $batch, 'requested' => $count, 'created' => count($ok), 'failed' => $failed, 'results' => $ok, 'aborted' => $aborted]);
}

if ($method === 'POST' && $route === 'vouchers/delete') {
    $ids = [];
    foreach ((read_json()['ids'] ?? []) as $id) if (is_string($id) && $id !== '') $ids[] = $id;
    $ids = array_slice(array_values(array_unique($ids)), 0, 1000);
    if (!$ids) send(400, ['error' => 'Keine Voucher ausgewaehlt.']);
    $r = nce('POST', '/controller/campus/v1/accountservice/users/batch-delete', $token, ['allUserIds' => $ids]);
    if ($r['status'] === 401) lost_session();
    if ($r['err'] || $r['status'] !== 200 || !$r['json']) send(502, ['error' => 'Loeschen fehlgeschlagen: ' . nce_msg($r)]);
    $deleted = array_values(array_filter($r['json']['success'] ?? [], 'is_string'));
    // Fehlgeschlagene mit Grund (die NBI liefert IDs oder Objekte mit errcode/errmsg)
    $failed = [];
    foreach (($r['json']['fail'] ?? []) as $f) {
        if (is_array($f)) $failed[] = ['id' => (string)($f['id'] ?? $f['userId'] ?? ''), 'reason' => trim(($f['errmsg'] ?? $f['reason'] ?? $f['message'] ?? '') . (isset($f['errcode']) ? ' (errcode ' . $f['errcode'] . ')' : ''))];
        else $failed[] = ['id' => (string)$f, 'reason' => ''];
    }
    if (!$deleted && !$failed && isset($r['json']['errcode']) && (string)$r['json']['errcode'] !== '0') $failed[] = ['id' => '', 'reason' => nce_msg($r)];
    send(200, ['deleted' => $deleted, 'failed' => $failed]);
}

send(404, ['error' => 'Unbekannte Route']);
