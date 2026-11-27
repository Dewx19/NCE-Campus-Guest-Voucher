<?php
/*
 * NCE-Campus Guest Voucher - Sitzung und Login-Schutz
 * Ersteller: Patrick Brandt
 *
 * PHP-Session (NCE-Token bleibt serverseitig) und Sperre nach Fehlversuchen (Datei statt Datenbank).
 */

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

/** Anmeldung am NCE: Ziel waehlen, Login-Sperre pruefen, Zertifikat sichern, Token holen, Session starten. */
function login($b) {
    global $CFG;
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
    return ['ok' => true, 'user' => $user, 'certPinned' => $pinned];
}

/** Abmelden: NCE-Token freigeben (Fehler egal) und Session beenden. */
function logout() {
    if (isset($_SESSION['token']) && target_apply($_SESSION['targetId'] ?? 0)) {
        try { nce('DELETE', '/controller/v2/tokens', $_SESSION['token'], ['token' => $_SESSION['token']]); } catch (Throwable $e) { /* egal */ }
    }
    session_end_clear();
}

/** Angemeldete Sitzung pruefen und das NCE-Ziel aktivieren. Rueckgabe: NCE-Token. */
function require_login() {
    if (!isset($_SESSION['token'])) send(401, ['error' => 'Nicht angemeldet.']);
    if (!target_apply($_SESSION['targetId'] ?? 0)) { session_end_clear(); send(401, ['error' => 'Das NCE-Ziel dieser Sitzung existiert nicht mehr - bitte neu anmelden.']); }
    $_SESSION['last'] = time();
    return $_SESSION['token'];
}
