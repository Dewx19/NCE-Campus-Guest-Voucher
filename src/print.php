<?php
/*
 * NCE-Campus Guest Voucher - Druckeinstellungen
 * Ersteller: Patrick Brandt
 *
 * Texte und Layout der Voucher-Druckkarten, gespeichert in data/print.json.
 */

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

/** Einstellungen speichern (oder zuruecksetzen) und den gespeicherten Stand zurueckgeben. */
function print_save($in) {
    $o = !empty($in['reset']) ? PRINT_DEFAULTS : print_clean($in['settings'] ?? []);
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
    if (@file_put_contents(DATA_DIR . '/print.json', json_encode($o, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        send(500, ['error' => 'Einstellungen konnten nicht gespeichert werden - ist der Ordner data/ fuer PHP beschreibbar?']);
    }
    return $o;
}
