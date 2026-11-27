<?php
/*
 * Automatische Tests fuer NCE-Campus Guest Voucher.
 * Aufruf: php tests/run.php   (braucht PHP-CLI mit curl, openssl, json)
 *
 * 1. Unit-Tests der Backend-Funktionen (tests/unit/*.php)
 * 2. Ablauftests: startet den Mock-NCE (tests/mock-nce.php) und die App mit dem PHP-Webserver
 *    und spielt Login, Gruppen, SSIDs, Anlegen, Liste, Loeschen, Druckeinstellungen und Login-Sperre durch.
 */
error_reporting(E_ALL);
$ROOT = dirname(__DIR__);
$failures = 0; $passed = 0;

function check($name, $cond, $detail = '') {
    global $failures, $passed;
    if ($cond) { $passed++; echo "  ok   $name\n"; }
    else { $failures++; echo "  FAIL $name" . ($detail !== '' ? "\n       $detail" : '') . "\n"; }
}
function same($name, $actual, $expected) {
    check($name, $actual === $expected, 'erwartet ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', erhalten ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------- Unit-Tests
echo "Unit-Tests\n";
putenv('GV_DATA_DIR=' . ($unitData = sys_get_temp_dir() . '/gv-unit-' . getmypid()));
@mkdir($unitData, 0700, true);
require $ROOT . '/src/bootstrap.php';
foreach (glob(__DIR__ . '/unit/*.php') as $f) { echo ' ' . basename($f) . "\n"; require $f; }

// ---------------------------------------------------------------- Ablauftests
echo "\nAblauftests (Mock-NCE)\n";
function free_port() { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = stream_socket_get_name($s, false); fclose($s); return (int)substr($n, strrpos($n, ':') + 1); }
function start_server($port, $docroot, $router, $env) {
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot];
    if ($router) $cmd[] = $router;
    $base = array_filter(getenv(), function ($k) { return stripos($k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $docroot, $env + $base);
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) return $p; usleep(100000); }
    fwrite(STDERR, "Server auf Port $port startet nicht\n"); exit(2);
}
$tmp = sys_get_temp_dir() . '/gv-test-' . getmypid();
@mkdir("$tmp/mock", 0700, true); @mkdir("$tmp/data", 0700, true);
$mockPort = free_port(); $appPort = free_port();
$mock = start_server($mockPort, __DIR__, __DIR__ . '/mock-nce.php', ['MOCK_STATE' => "$tmp/mock"]);
$app = start_server($appPort, $ROOT, null, ['NCE_PROTOCOL' => 'http', 'NCE_HOST' => '127.0.0.1', 'NCE_PORT' => (string)$mockPort, 'GV_DATA_DIR' => "$tmp/data"]);
register_shutdown_function(function () use ($mock, $app, $tmp) {
    foreach ([$mock, $app] as $p) { proc_terminate($p); proc_close($p); }
    exec('rm -rf ' . escapeshellarg($tmp));
});

$jar = "$tmp/cookies.txt";
function call($method, $route, $body = null, $headers = null) {
    global $appPort, $jar;
    $ch = curl_init("http://127.0.0.1:$appPort/api.php?r=" . $route);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_PROXY => '',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers ?? ['Content-Type: application/json', 'X-Requested-With: gv']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $text = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, json_decode((string)$text, true)];
}

// Grundlagen
[$st, $j] = call('GET', 'config');
same('config: nicht angemeldet', $j['loggedIn'] ?? null, false);
same('config: Standard-Ziel', $j['targets'][0]['name'] ?? null, 'Public Cloud Northbound API');
[$st] = call('POST', 'login', ['username' => 'api', 'password' => 'secret'], ['Content-Type: text/plain']);
same('POST ohne JSON/X-Requested-With wird abgewiesen', $st, 415);
[$st] = call('GET', 'groups');
same('ohne Login: 401', $st, 401);

// Login
[$st, $j] = call('POST', 'login', ['username' => 'api', 'password' => 'falsch']);
same('falsches Passwort: 401', $st, 401);
[$st, $j] = call('POST', 'login', ['username' => 'api', 'password' => 'secret']);
same('Login', $st, 200);

// Benutzergruppen (130 Gruppen, max. 100 je Seite)
[$st, $j] = call('GET', 'groups');
same('Gruppen: alle Seiten geladen', count($j['groups'] ?? []), 130);
[$st] = call('POST', 'group', ['id' => 'gibt-es-nicht']);
same('unbekannte Gruppe wird abgelehnt', $st, 400);
[$st] = call('GET', 'vouchers');
same('Voucher ohne Gruppe: 409', $st, 409);
[$st] = call('POST', 'group', ['id' => 'g-guest']);
same('Gruppe waehlen', $st, 200);

// SSIDs: nur open + aktives Portal
[$st, $j] = call('GET', 'ssids');
$op = []; foreach ($j['ssids'] ?? [] as $s) if ($s['openPortal']) $op[] = $s['ssid'];
sort($op);
same('SSIDs: Open + Portal erkannt', $op, ['Event-Gast', 'Guest-Wifi']);
$gw = array_values(array_filter($j['ssids'] ?? [], function ($s) { return $s['ssid'] === 'Guest-Wifi'; }))[0] ?? [];
same('SSID auf mehreren Sites zusammengefasst', $gw['sites'] ?? null, ['Filiale', 'Zentrale']);

// Im NCE angelegter Voucher: Passcode aus userName
[$st, $j] = call('GET', 'vouchers');
$nce = array_values(array_filter($j['vouchers'] ?? [], function ($v) { return $v['id'] === 'nce-1'; }))[0] ?? [];
same('NCE-Voucher: Passcode aus userName', $nce['passcode'] ?? null, 'NcePass7x');
same('NCE-Voucher: Beschreibung', $nce['description'] ?? null, 'Rezeption');

// Anlegen: Passcode-Batch (30 -> zwei Teilstuecke wie im Browser) und Benutzer/Passwort
[$st, $a] = call('POST', 'vouchers', ['userType' => 6, 'count' => 25, 'offset' => 0, 'effectiveType' => 0, 'validDays' => 2, 'description' => 'Messe | Halle 3']);
[$st, $b] = call('POST', 'vouchers', ['userType' => 6, 'count' => 5, 'offset' => 25, 'batch' => $a['batch'] ?? '', 'effectiveType' => 0, 'validDays' => 2, 'description' => 'Messe | Halle 3']);
same('Batch: 25 + 5 angelegt', [$a['created'] ?? null, $b['created'] ?? null], [25, 5]);
$codes = array_column(array_merge($a['results'] ?? [], $b['results'] ?? []), 'passcode');
check('Batch: Passcodes eindeutig, 10 Zeichen', count(array_unique($codes)) === 30 && !array_filter($codes, function ($c) { return strlen($c) !== 10; }));
same('Batch: fortlaufende Kennungen', $b['results'][0]['marker'] ?? null, 'VM:' . ($a['batch'] ?? '') . '-026');
[$st, $c] = call('POST', 'vouchers', ['userType' => 20, 'count' => 1, 'effectiveType' => 1, 'effectiveUnit' => 'hour', 'effectiveTime' => 4, 'prefix' => 'GAST-']);
same('Benutzer/Passwort angelegt', $c['created'] ?? null, 1);
check('Benutzername mit Praefix', strpos($c['results'][0]['userName'] ?? '', 'GAST-') === 0);
[$st, $j] = call('POST', 'vouchers', ['userType' => 6, 'count' => 1, 'offset' => 500]);
same('Batch ueber maxBatch wird abgelehnt', $st, 400);

// Liste nach dem Anlegen
[$st, $j] = call('GET', 'vouchers');
$vs = $j['vouchers'] ?? [];
same('Liste: 1 + 30 + 1 Voucher', count($vs), 32);
$byCode = []; foreach ($vs as $v) if ($v['passcode']) $byCode[$v['passcode']] = $v;
same('Liste: angelegter Passcode wieder lesbar', isset($byCode[$codes[0]]), true);
$v0 = $byCode[$codes[0]] ?? [];
same('Liste: Beschreibung ohne Kennung', $v0['description'] ?? null, 'Messe | Halle 3');
same('Liste: Batch-Zuordnung', $v0['batch'] ?? null, $a['batch'] ?? '');
check('Liste: Ablauf in ~2 Tagen', abs(($v0['validUntil'] ?? 0) / 1000 - (time() + 2 * 86400)) < 120);
$up = array_values(array_filter($vs, function ($v) { return $v['userType'] === 20; }))[0] ?? [];
same('Liste: Passwort wird nicht geliefert', array_key_exists('password', $up) ? $up['password'] : 'fehlt', null);

// Loeschen: Gastkonten der Gruppe ja, fremde Konten nein, NCE-Fehler mit ID aus fail[].data
$ids = [$v0['id'], 'staff-1', 'erfunden-99'];
[$st, $j] = call('POST', 'vouchers/delete', ['ids' => $ids]);
same('Loeschen: Gast-Voucher geloescht', $j['deleted'] ?? null, [$v0['id']]);
$failedIds = array_column($j['failed'] ?? [], 'id'); sort($failedIds);
same('Loeschen: fremde/unbekannte IDs abgelehnt', $failedIds, ['erfunden-99', 'staff-1']);
// Voucher, den der NCE ablehnt (Gast online)
$st0 = json_decode(file_get_contents("$tmp/mock/state.json"), true);
foreach ($st0['users'] as &$u) if ($u['id'] === 'nce-1') $u['description'] = 'Rezeption ONLINE'; unset($u);
file_put_contents("$tmp/mock/state.json", json_encode($st0));
[$st, $j] = call('POST', 'vouchers/delete', ['ids' => ['nce-1']]);
same('Loeschen: NCE-Ablehnung der richtigen ID zugeordnet', $j['failed'][0]['id'] ?? null, 'nce-1');
check('Loeschen: Grund vom NCE', strpos($j['failed'][0]['reason'] ?? '', 'online') !== false, json_encode($j));

// Druckeinstellungen
[$st, $j] = call('POST', 'print-settings', ['settings' => ['title' => "Willkommen\n{ssid}", 'columns' => 9, 'qrSize' => 5, 'showDesc' => true, 'align' => 'rechts']]);
same('Druck: Steuerzeichen entfernt', $j['settings']['title'] ?? null, 'Willkommen {ssid}');
same('Druck: Werte begrenzt', [$j['settings']['columns'] ?? null, $j['settings']['qrSize'] ?? null, $j['settings']['align'] ?? null], [4, 20, 'center']);
[$st, $j] = call('GET', 'print-settings');
same('Druck: gespeichert', $j['settings']['showDesc'] ?? null, true);
[$st, $j] = call('POST', 'print-settings', ['reset' => true]);
same('Druck: zuruecksetzen', $j['settings']['title'] ?? null, 'WLAN Gast-Zugang');

// Abmelden, Login-Sperre
[$st] = call('POST', 'logout', []);
same('Logout', $st, 200);
[$st] = call('GET', 'vouchers');
same('nach Logout: 401', $st, 401);
for ($i = 0; $i < 5; $i++) call('POST', 'login', ['username' => 'api', 'password' => 'falsch']);
[$st] = call('POST', 'login', ['username' => 'api', 'password' => 'secret']);
same('Login-Sperre nach 5 Fehlversuchen: 429', $st, 429);

exec('rm -rf ' . escapeshellarg($unitData));
echo "\n$passed bestanden, $failures fehlgeschlagen\n";
exit($failures ? 1 : 0);
