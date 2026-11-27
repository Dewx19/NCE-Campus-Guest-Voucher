<?php
/*
 * NCE-Campus Guest Voucher - Allgemeine Helfer
 * Ersteller: Patrick Brandt
 *
 * JSON-Antworten, Feldsuche in NCE-Antworten, Zufallscodes, Voucher-Kennung.
 */

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
/** Erstes Array unter dem Schluessel $key (auch verschachtelt). */
function find_key($a, $key, $depth = 0) {
    if (!is_array($a) || $depth > 4) return null;
    if (isset($a[$key]) && is_array($a[$key])) return $a[$key];
    foreach ($a as $v) if (is_array($v) && ($r = find_key($v, $key, $depth + 1)) !== null) return $r;
    return null;
}
