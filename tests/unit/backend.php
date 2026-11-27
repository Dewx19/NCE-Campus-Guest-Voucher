<?php
// Unit-Tests der Backend-Funktionen aus src/ (eingebunden von tests/run.php)

// --- SSID-Einordnung laut NBI-Guide ---
$s = function ($mode, $portal) { return ['name' => 'X', 'ssidAuth' => ['mode' => $mode, 'portal' => ['mode' => $portal]]]; };
same('SSID open + portalThirdController', classify_ssid($s('open', 'portalThirdController'))['openPortal'], true);
same('SSID open + portalThirdIndirect', classify_ssid($s('open', 'portalThirdIndirect'))['openPortal'], true);
same('SSID open + portalThird', classify_ssid($s('open', 'portalThird'))['openPortal'], true);
same('SSID open + portalDisable', classify_ssid($s('open', 'portalDisable'))['openPortal'], false);
same('SSID wpa2-psk + Portal', classify_ssid($s('wpa2-psk', 'portalThirdController'))['openPortal'], false);
same('SSID ssidAuth verschachtelt', classify_ssid(['wlan' => $s('open', 'portalThird')])['openPortal'], true);
same('SSID Einordnung nach Doku', classify_ssid($s('open', 'portalThird'))['basis'], 'ssidAuth');
same('SSID ohne portal.mode: Schaetzung', classify_ssid(['ssidAuth' => ['mode' => 'open'], 'portalEnable' => 'disable'])['basis'], 'geschaetzt');

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
same('secret_of maskiert', secret_of(['password' => '******'], ['password']), null);
same('secret_of Wert', secret_of(['userName' => 'Ab3d'], ['passcode', 'userName']), 'Ab3d');

// --- Voucher-Liste ---
$list = voucher_list([
    ['id' => 'a', 'userType' => 6, 'userName' => 'Pc12345678', 'description' => 'VM:BX1Y-001 | Gast', 'vaildPeriod' => 1000, 'accountStatus' => 0],
    ['id' => 'b', 'userType' => 20, 'userName' => 'WG-AB12CD34', 'description' => '', 'accountStatus' => 3],
    ['userType' => 6, 'userName' => 'ohne-id'],
]);
same('voucher_list: Datensaetze ohne ID ignoriert', count($list), 2);
same('voucher_list: Passcode aus userName', [$list[0]['passcode'], $list[0]['userName']], ['Pc12345678', null]);
same('voucher_list: abgelaufen', $list[0]['expired'], true);
same('voucher_list: Benutzername Typ 20', [$list[1]['userName'], $list[1]['passcode'], $list[1]['password']], ['WG-AB12CD34', null, null]);
same('voucher_list: deaktiviert', $list[1]['disabled'], true);
same('deletable_ids: nur Typ 6/20', array_keys(deletable_ids([['id' => 'a', 'userType' => 6], ['id' => 'b', 'userType' => 20], ['id' => 'c', 'userType' => 1], ['id' => 'd']])), ['a', 'b']);

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
