<?php
/*
 * NCE-Campus Guest Voucher - Konfiguration
 * Ersteller: Patrick Brandt
 *
 * Eingebaute Standardwerte, optionale config.php, NCE-Ziele.
 */

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
if (is_file(APP_DIR . '/config.php')) {
    $own = require APP_DIR . '/config.php';
    if (is_array($own)) {
        foreach ($own as $k => $v) {
            $CFG[$k] = ($k !== 'targets' && is_array($v) && isset($CFG[$k]) && is_array($CFG[$k])) ? array_replace_recursive($CFG[$k], $v) : $v;
        }
    }
}
define('DATA_DIR', getenv('GV_DATA_DIR') ?: APP_DIR . '/data'); // GV_DATA_DIR nur fuer Tests

// Umgebungsvariablen (nur fuer lokale Tests) ueberschreiben das erste Ziel
foreach (['NCE_PROTOCOL' => 'protocol', 'NCE_HOST' => 'host', 'NCE_PORT' => 'port', 'NCE_TLS_SERVERNAME' => 'tlsServerName'] as $env => $key) {
    $v = getenv($env);
    if ($v !== false && isset($CFG['targets'][0])) $CFG['targets'][0][$key] = $key === 'port' ? (int)$v : $v;
}
if (getenv('NCE_TLS_VERIFY') === 'false' && isset($CFG['targets'][0])) $CFG['targets'][0]['tlsVerify'] = false;

$NCE = null; // aktives NCE-Ziel

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

/** Oeffentliche Einstellungen fuer die Oberflaeche (keine Zugangsdaten, keine Hosts). */
function app_config($https) {
    global $CFG;
    $tid = $_SESSION['targetId'] ?? null;
    $g = $CFG['guest'] ?? [];
    return [
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
    ];
}
