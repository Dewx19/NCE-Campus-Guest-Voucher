<?php
// Unit-Tests der Backend-Funktionen aus src/ (eingebunden von tests/run.php)

// --- SSID-Einordnung laut NBI-Guide ---
$s = function ($mode, $portal) { return ['name' => 'X', 'ssidAuth' => ['mode' => $mode, 'portal' => ['mode' => $portal]]]; };
same('SSID open + portalThirdController', classify_ssid($s('open', 'portalThirdController'))['openPortal'], true);
same('SSID open + portalThirdIndirect', classify_ssid($s('open', 'portalThirdIndirect'))['openPortal'], true);
same('SSID open + portalThird', classify_ssid($s('open', 'portalThird'))['openPortal'], true);
same('SSID open + portalDisable', classify_ssid($s('open', 'portalDisable'))['openPortal'], false);
same('SSID open + portal.mode null', classify_ssid($s('open', null))['openPortal'], false);
same('SSID wpa2-psk + Portal', classify_ssid($s('wpa2-psk', 'portalThirdController'))['openPortal'], false);
same('SSID ohne ssidAuth', classify_ssid(['name' => 'X', 'portalEnable' => true])['openPortal'], false);

// --- Echte NCE-Antwort: SSIDs (tests/fixtures/nce-apssid.json) ---
$fx = json_decode(file_get_contents(__DIR__ . '/../fixtures/nce-apssid.json'), true);
$got = [];
foreach (rows_of($fx) as $rec) { $c = classify_ssid($rec); $got[$rec['name']] = [$c['mode'], $c['portalMode'], $c['portal'], $c['openPortal']]; }
ksort($got);
same('Fixture SSIDs: Einordnung', $got, [
    'Corp-PPSK'  => ['ppsk', null, false, false],
    'Guest-Wifi' => ['open', 'portalThirdController', true, true],
    'Shop-PSK'   => ['psk', null, false, false],
]);

// --- Voucher-Kennung im Beschreibungsfeld ---
same('marker_of', marker_of('VM:B12AB-007 | Messe'), ['VM:B12AB-007', 'B12AB']);
same('marker_of ohne Kennung', marker_of('Rezeption'), [null, null]);
same('strip_marker', strip_marker('VM:B12AB-007 | Messe | Halle 3'), 'Messe | Halle 3');

// --- Zufallscodes ---
$c = mixed_code(10, false);
check('mixed_code: Laenge und Zeichen', strlen($c) === 10 && preg_match('/^[A-Za-z0-9]+$/', $c) === 1, $c);
check('mixed_code: Gross, klein, Ziffer', preg_match('/[A-Z]/', $c) && preg_match('/[a-z]/', $c) && preg_match('/[0-9]/', $c), $c);
check('mixed_code: keine verwechselbaren Zeichen', !preg_match('/[O0Il1]/', implode('', array_map(function () { return mixed_code(12); }, range(1, 50)))));
$c = mixed_code(12, true);
check('mixed_code mit Sonderzeichen', preg_match('/[!@$*_-]/', $c) === 1, $c);

// --- NCE-Antworten auswerten ---
same('rows_of Liste', rows_of(['data' => [['id' => 1]]]), [['id' => 1]]);
same('rows_of verschachtelt', rows_of(['data' => ['list' => [['id' => 2]], 'total' => 1]]), [['id' => 2]]);
same('rows_of leer', rows_of(['errcode' => '0']), []);
same('fail_id aus data', fail_id(['data' => 'u7', 'errcode' => '1']), 'u7');
same('fail_id aus id', fail_id(['id' => 'u8']), 'u8');
same('fail_id aus data-Objekt', fail_id(['data' => ['id' => 'u9']]), 'u9');

// --- Echte NCE-Antwort: Gastkonten (tests/fixtures/nce-accessuser-users.json) ---
$fx = json_decode(file_get_contents(__DIR__ . '/../fixtures/nce-accessuser-users.json'), true);
$list = voucher_list(rows_of($fx));
same('Fixture Voucher: nur Typ 6 und 20', array_column($list, 'userType'), [6, 20]);
same('Fixture Typ 6: Passcode aus userName, Kennung', [$list[0]['passcode'], $list[0]['userName'], $list[0]['marker'], $list[0]['batch']], ['Ab3dEf7hJk', null, 'VM:BTEST0001-001', 'BTEST0001']);
same('Fixture Typ 6: vaildPeriod null', [$list[0]['validUntil'], $list[0]['expired']], [null, false]);
same('Fixture Typ 20: Benutzername, kein Passwort', [$list[1]['userName'], $list[1]['passcode'], $list[1]['password']], ['GastTest01', null, null]);
same('Fixture: accountStatus wird nicht ausgewertet', array_key_exists('disabled', $list[0]), false);
same('Fixture: loeschbar nur Typ 6/20', array_keys(deletable_ids(rows_of($fx))), ['00000000-0000-4000-8000-000000000006', '00000000-0000-4000-8000-000000000020']);

// --- Voucher-Liste: Grenzfaelle ---
$list = voucher_list([
    ['id' => 'a', 'userType' => 6, 'userName' => 'Pc12345678', 'description' => 'VM:BX1Y-001 | Gast', 'vaildPeriod' => 1000],
    ['id' => 'b', 'userType' => 20, 'userName' => '', 'description' => ''],
    ['userType' => 6, 'userName' => 'ohne-id'],
    ['id' => 'c', 'userType' => '6', 'userName' => 'TypAlsText'],
    ['id' => 'd', 'userType' => 1, 'userName' => 'admin'],
]);
same('Liste: ohne ID und fremde Typen ignoriert', array_column($list, 'id'), ['a', 'b', 'c']);
same('Liste: abgelaufen', $list[0]['expired'], true);
same('Liste: leerer Benutzername = null', $list[1]['userName'], null);

// --- Druckeinstellungen ---
$p = print_clean(['title' => "A\tB", 'columns' => '2', 'fontScale' => 999, 'gap' => -5, 'border' => 0, 'align' => 'left', 'unbekannt' => 1]);
same('print_clean', [$p['title'], $p['columns'], $p['fontScale'], $p['gap'], $p['border'], $p['align'], isset($p['unbekannt'])], ['A B', 2, 160, 0, false, 'left', false]);
same('print_clean: Laenge begrenzt', strlen(print_clean(['footer' => str_repeat('x', 500)])['footer']), 200);

// --- Standardwerte ---
same('Standard-Ziel', [$CFG['targets'][0]['host'], $CFG['targets'][0]['port']], ['80.158.58.176', 18002]);

// --- TLS: Pruefname aus dem uebernommenen Zertifikat, wenn per IP verbunden wird ---
if (function_exists('openssl_pkey_new')) {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $crt = openssl_csr_sign(openssl_csr_new(['commonName' => 'nce.example.test'], $key), null, $key, 2);
    openssl_x509_export($crt, $pem);
    $NCE = ['_id' => 7, 'protocol' => 'https', 'host' => '192.0.2.10', 'port' => 18002, 'tlsVerify' => true, 'tlsServerName' => '', 'caFile' => ''];
    @mkdir(DATA_DIR, 0700, true);
    file_put_contents(DATA_DIR . '/nce-ca-7.pem', $pem);
    same('tls_name: Name aus Zertifikat statt IP', tls_name(), 'nce.example.test');
    $NCE['tlsServerName'] = 'fest.example.test';
    same('tls_name: tlsServerName hat Vorrang', tls_name(), 'fest.example.test');
    $NCE['tlsServerName'] = ''; $NCE['host'] = 'nce.example.test';
    same('tls_name: Host passt zum Zertifikat', tls_name(), 'nce.example.test');
    $NCE = null;
}
