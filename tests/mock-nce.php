<?php
/*
 * Mock des iMaster NCE-Campus (Northbound-API) fuer die automatischen Tests.
 * Start: MOCK_STATE=<ordner> php -S 127.0.0.1:<port> tests/mock-nce.php
 *
 * Bildet die Antwortformate laut NBI-Guide nach. Grundlage sind echte, anonymisierte NCE-Antworten
 * (tests/fixtures/*.json), inklusive der am echten NCE beobachteten Details:
 *   - Passcode-Voucher (userType 6): der Passcode steht im Feld userName
 *   - SSIDs: ssidAuth.mode / ssidAuth.portal.mode (bei PSK/PPSK portal.mode = null)
 *   - weitere Kontotypen (z. B. userType 24) in derselben Gruppe, vaildPeriod null, accountStatus 1
 *   - batch-delete: fehlgeschlagene IDs in fail[].data
 *   - pageSize max. 100 (Benutzergruppen, Sites)
 * Zugangsdaten im Mock: api / secret (Testwerte, kein echtes Konto).
 */
$stateDir = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/nce-mock';
@mkdir($stateDir, 0700, true);
$stateFile = $stateDir . '/state.json';
const TOKEN = 'mock-token-1';

function out($status, $obj) { http_response_code($status); header('Content-Type: application/json'); echo json_encode($obj); exit; }
function ok($data = null, $extra = []) { out(200, ['errcode' => '0', 'errmsg' => '', 'data' => $data] + $extra); }
function err($status, $code, $msg) { out($status, ['errcode' => $code, 'errmsg' => $msg]); }

$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state = json_decode(stream_get_contents($fp), true);
if (!is_array($state)) {
    $groups = [['id' => 'g-guest', 'name' => 'Guest', 'fullPathName' => 'ROOT\\Guest'], ['id' => 'g-staff', 'name' => 'Staff', 'fullPathName' => 'ROOT\\Staff']];
    for ($i = 1; $i <= 128; $i++) $groups[] = ['id' => "g-$i", 'name' => "Gruppe $i", 'fullPathName' => sprintf('ROOT\\Filiale-%03d', $i)];
    // Gast-Gruppe: die drei echten Konten aus dem NCE (Typ 24, 6, 20) + ein Konto in einer anderen Gruppe
    $real = json_decode(file_get_contents(__DIR__ . '/fixtures/nce-accessuser-users.json'), true)['data'];
    foreach ($real as &$u) $u['userGroupId'] = 'g-guest'; unset($u);
    $real[] = ['id' => 'staff-1', 'userGroupId' => 'g-staff', 'userType' => 20, 'userName' => 'mitarbeiter', 'description' => '', 'accountStatus' => 1, 'vaildPeriod' => null];
    $state = ['seq' => 0, 'groups' => $groups, 'users' => $real, 'log' => []];
}
function save() { global $fp, $state; ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($state)); fflush($fp); flock($fp, LOCK_UN); }
register_shutdown_function('save');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$state['log'][] = $method . ' ' . $_SERVER['REQUEST_URI'];
$state['log'] = array_slice($state['log'], -200);

// --- Token ---
if ($path === '/controller/v2/tokens' && $method === 'POST') {
    if (($body['userName'] ?? '') === 'api' && ($body['password'] ?? '') === 'secret') ok(['token_id' => TOKEN, 'expiredDate' => date('c', time() + 1800)]);
    err(401, '0x0f01', 'Incorrect user name or password.');
}
if ($path === '/controller/v2/tokens' && $method === 'DELETE') ok();
if (($_SERVER['HTTP_X_ACCESS_TOKEN'] ?? '') !== TOKEN) err(401, '0x0f02', 'Token invalid.');

function page($rows) {
    $size = (int)($_GET['pageSize'] ?? 20); $idx = max(1, (int)($_GET['pageIndex'] ?? 1));
    if ($size > 100) err(400, '0x0f10', 'pageSize must be between 1 and 100.');
    ok(array_slice($rows, ($idx - 1) * $size, $size), ['totalRecords' => count($rows), 'pageIndex' => $idx, 'pageSize' => $size]);
}

// --- Benutzergruppen / Sites ---
if ($path === '/controller/campus/v1/accountservice/usergroups') page($state['groups']);
if ($path === '/controller/campus/v3/sites') page([['id' => 's1', 'name' => 'Zentrale'], ['id' => 's2', 'name' => 'Filiale']]);

// --- SSIDs (dokumentierter Pfad) ---
// Site s1: echte NCE-Datensaetze (Guest-Wifi open/portalThirdController, Shop-PSK psk/null, Corp-PPSK ppsk/null)
// Site s2: weitere Portal-Varianten laut NBI-Guide
if (preg_match('#^/controller/campus/v3/networkconfig/site/([^/]+)/apssid$#', $path, $m)) {
    if ($m[1] === 's1') ok(json_decode(file_get_contents(__DIR__ . '/fixtures/nce-apssid.json'), true)['data']);
    $ssid = function ($name, $mode, $portal) { return ['id' => md5($name), 'name' => $name, 'enable' => true,
        'ssidAuth' => ['mode' => $mode, 'portal' => ['mode' => $portal]]]; };
    ok([$ssid('Guest-Wifi', 'open', 'portalThirdIndirect'), $ssid('Event-Gast', 'open', 'portalThird'), $ssid('Lobby-Open', 'open', 'portalDisable')]);
}

// --- Gaeste ---
if ($path === '/controller/campus/v2/accountservice/accessuser/users' && $method === 'GET') {
    $g = $_GET['userGroupId'] ?? '';
    $rows = array_values(array_filter($state['users'], function ($u) use ($g) { return $u['userGroupId'] === $g; }));
    $size = min(1000, (int)($_GET['pageSize'] ?? 20)); $idx = max(1, (int)($_GET['pageIndex'] ?? 1));
    $rows = array_map(function ($u) { unset($u['password']); return $u; }, $rows); // Passwoerter gibt die NBI nicht zurueck
    ok(array_slice($rows, ($idx - 1) * $size, $size), ['totalRecords' => count($rows)]);
}
if ($path === '/controller/campus/v2/accountservice/accessuser/guest' && $method === 'POST') {
    $type = (int)($body['userType'] ?? 0);
    if (!in_array($body['userGroupId'] ?? '', array_column($state['groups'], 'id'), true)) err(400, '0x0f20', 'User group does not exist.');
    if ($type === 6 && empty($body['passcode'])) err(400, '0x0f21', 'passcode is required.');
    if ($type === 20 && (empty($body['userName']) || empty($body['password']))) err(400, '0x0f22', 'userName and password are required.');
    if (!in_array($type, [6, 20], true)) err(400, '0x0f23', 'Invalid userType.');
    $until = isset($body['validPeriodLong']) ? (int)$body['validPeriodLong'] : null;
    $state['users'][] = ['id' => 'u' . (++$state['seq']), 'userGroupId' => $body['userGroupId'], 'userType' => $type,
        'userName' => $type === 6 ? $body['passcode'] : $body['userName'], 'password' => $body['password'] ?? null,
        'description' => (string)($body['description'] ?? ''), 'vaildPeriod' => $until, 'accountStatus' => 0,
        'maxAccessNum' => $body['maxAccessNum'] ?? null];
    out(201, ['errcode' => '0', 'errmsg' => '']);
}
if ($path === '/controller/campus/v1/accountservice/users/batch-delete' && $method === 'POST') {
    $success = []; $fail = [];
    foreach ((array)($body['allUserIds'] ?? []) as $id) {
        $i = array_search($id, array_column($state['users'], 'id'), true);
        if ($i === false) { $fail[] = ['data' => $id, 'errcode' => '0x0f30', 'errmsg' => 'The user does not exist.']; continue; }
        if (strpos($state['users'][$i]['description'], 'ONLINE') !== false) { $fail[] = ['data' => $id, 'errcode' => '0x02180012', 'errmsg' => 'The user is online and cannot be deleted.']; continue; }
        array_splice($state['users'], $i, 1); $success[] = $id;
    }
    out(200, ['errcode' => '0', 'errmsg' => '', 'success' => $success, 'fail' => $fail]);
}

err(404, '0x0f99', 'Not found: ' . $method . ' ' . $path);
