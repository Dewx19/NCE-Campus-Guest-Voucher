<?php
/*
 * NCE-Campus Guest Voucher - Benutzergruppen und Voucher
 * Ersteller: Patrick Brandt
 *
 * Gruppen, Gastkonten lesen/anlegen/loeschen.
 */

function fetch_groups($token) {
    $out = []; $seen = [];
    for ($page = 1; $page <= 100; $page++) {
        $r = nce('GET', '/controller/campus/v1/accountservice/usergroups', $token, null, ['pageIndex' => $page, 'pageSize' => 100]);
        nce_check($r, 'Abfrage der Benutzergruppen');
        $rows = rows_of($r['json']);
        foreach ($rows as $g) {
            if (empty($g['id']) || isset($seen[$g['id']])) continue;
            $seen[$g['id']] = true;
            $out[] = ['id' => $g['id'], 'name' => $g['name'] ?? '', 'path' => $g['fullPathName'] ?? ($g['name'] ?? '')];
        }
        $total = (int)($r['json']['totalRecords'] ?? 0);
        if (count($rows) < 100 || ($total && count($seen) >= $total)) break;
    }
    usort($out, function ($x, $y) { return strcasecmp($x['path'], $y['path']); });
    return $out;
}
/**
 * Status eines Vouchers. Reihenfolge: deaktiviert > abgelaufen > aktiv (eingeloest) > bereit (noch nicht eingeloest).
 *   disabled  accountStatus 3
 *   expired   Ablaufdatum (vaildPeriod) liegt in der Vergangenheit
 *   active    accountStatus 0 - eingeloest bzw. aktiviert
 *   ready     accountStatus 1 - wartet auf die erste Nutzung
 *   unknown   anderer oder fehlender accountStatus
 */
function voucher_status($accountStatus, $validUntil, $nowMs) {
    if ($accountStatus === 3) return 'disabled';
    if ($validUntil && $validUntil < $nowMs) return 'expired';
    if ($accountStatus === 0) return 'active';
    if ($accountStatus === 1) return 'ready';
    return 'unknown';
}

// Die App ist eine Gast-Voucher-Verwaltung: nur Passcode (6) und Benutzer/Passwort (20)
const VOUCHER_TYPES = [6, 20];
function is_voucher($u) { return is_array($u) && !empty($u['id']) && in_array((int)($u['userType'] ?? -1), VOUCHER_TYPES, true); }

/** Betroffene Benutzer-ID eines fehlgeschlagenen Loeschauftrags (dokumentiert: fail[].data). */
function fail_id($f) {
    foreach (['data', 'id', 'userId'] as $k) {
        $v = $f[$k] ?? null;
        if (is_array($v)) $v = $v['id'] ?? $v['userId'] ?? null;
        if (is_scalar($v) && (string)$v !== '') return (string)$v;
    }
    return '';
}
/** Alle Zugangskonten einer Benutzergruppe live aus dem NCE: [liste, totalRecords]. */
function fetch_group_users($token, $groupId) {
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
    return [$all, $total];
}
function require_group() {
    if (empty($_SESSION['groupId'])) send(409, ['error' => 'Keine Gast-Benutzergruppe ausgewaehlt.', 'needGroup' => true]);
    return $_SESSION['groupId'];
}


/** Rohdaten der Voucher-Abfrage zur Pruefung, welche Felder der NCE liefert (nur angemeldet). */
function voucher_raw($token, $groupId, $all, $total) {
    $fields = []; $types = [];
    foreach ($all as $u) {
        if (!is_array($u)) continue;
        foreach (array_keys(flat($u)) as $k) $fields[$k] = ($fields[$k] ?? 0) + 1;
        $t = (int)($u['userType'] ?? 0);
        $types[$t] = ($types[$t] ?? 0) + 1;
    }
    ksort($fields); ksort($types);
    $hint = array_values(array_filter(array_keys($fields), function ($k) { return preg_match('/(pass|code|pwd|secret|key)/', $k); }));
    send(200, [
        'abfrage'      => ['pfad' => '/controller/campus/v2/accountservice/accessuser/users', 'userGroupId' => $groupId],
        'anzahl'       => count($all),
        'totalRecords' => $total,
        'userTypes'    => $types,
        'hinweis'      => 'Die App verwaltet nur userType ' . implode(' und ', VOUCHER_TYPES) . '; andere Typen werden nicht angezeigt und nicht geloescht.',
        'felder'       => $fields,
        'moeglicheCodeFelder' => $hint,
        'beispiele'    => array_slice($all, 0, 10),
    ]);
}

/**
 * NCE-Gastkonten (nur Typ 6 und 20) in das Format der Oberflaeche bringen. Grundlage: echte NCE-Antworten
 * (tests/fixtures/nce-accessuser-users.json):
 *   - Typ 6: der Passcode steht in userName
 *   - Typ 20: userName ist der Benutzername; Passwoerter gibt die NBI nicht zurueck
 *   - vaildPeriod (sic) kann null sein (z. B. gueltig ab erstem Login) - dann gibt es kein Ablaufdatum
 *   - accountStatus (am NCE V300R025C10 verifiziert): 1 = noch nicht eingeloest, 0 = eingeloest/aktiviert, 3 = deaktiviert
 *     Bei Gueltigkeit ab erster Nutzung beginnt die Laufzeit erst mit der Einloesung (vaildPeriod bis dahin null).
 */
function voucher_list($all) {
    $nowMs = (int)(microtime(true) * 1000);
    $out = [];
    foreach ($all as $u) {
        if (!is_voucher($u)) continue;
        list($marker, $batch) = marker_of($u['description'] ?? '');
        $type = (int)$u['userType'];
        $name = is_scalar($u['userName'] ?? null) && (string)$u['userName'] !== '' ? (string)$u['userName'] : null;
        $until = is_numeric($u['vaildPeriod'] ?? null) ? (int)$u['vaildPeriod'] : null;
        $acc = is_numeric($u['accountStatus'] ?? null) ? (int)$u['accountStatus'] : null;
        $out[] = [
            'id'           => (string)$u['id'],
            'userType'     => $type,
            'userName'     => $type === 20 ? $name : null,
            'passcode'     => $type === 6 ? $name : null,
            'password'     => null,
            'description'  => strip_marker($u['description'] ?? ''),
            'marker'       => $marker,
            'batch'        => $batch,
            'validUntil'   => $until ?: null,
            'accountStatus'=> $acc,
            'status'       => voucher_status($acc, $until, $nowMs),
            'maxAccessNum' => $u['maxAccessNum'] ?? null,
        ];
    }
    return $out;
}

/**
 * Voucher anlegen. Der Browser sendet grosse Batches in Teilstuecken (max. 25 je Aufruf),
 * damit keine PHP-Zeitlimits greifen. Rueckgabe: Ergebnis fuer die Oberflaeche.
 */
function voucher_create($token, $groupId, $b) {
    global $CFG;
    $g = $CFG['guest'] ?? [];
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
    return ['batch' => $batch, 'requested' => $count, 'created' => count($ok), 'failed' => $failed, 'results' => $ok, 'aborted' => $aborted];
}

/** IDs, die geloescht werden duerfen: nur Gastkonten (Typ 6 / 20) der gewaehlten Gruppe. */
function deletable_ids($all) {
    $allowed = [];
    foreach ($all as $u) if (is_voucher($u)) $allowed[(string)$u['id']] = true;
    return $allowed;
}

/** Voucher loeschen. Rueckgabe: ['deleted' => [ids], 'failed' => [['id', 'reason']]]. */
function voucher_delete($token, $groupId, $ids) {
    list($all) = fetch_group_users($token, $groupId);
    $allowed = deletable_ids($all);
    $failed = [];
    foreach ($ids as $id) if (!isset($allowed[$id])) $failed[] = ['id' => $id, 'reason' => 'Kein Gast-Voucher der gewaehlten Gruppe - nicht geloescht.'];
    $ids = array_values(array_filter($ids, function ($id) use ($allowed) { return isset($allowed[$id]); }));
    if (!$ids) return ['deleted' => [], 'failed' => $failed];

    $r = nce('POST', '/controller/campus/v1/accountservice/users/batch-delete', $token, ['allUserIds' => $ids]);
    if ($r['status'] === 401) lost_session();
    if ($r['err'] || $r['status'] !== 200 || !$r['json']) send(502, ['error' => 'Loeschen fehlgeschlagen: ' . nce_msg($r)]);
    $deleted = array_values(array_filter($r['json']['success'] ?? [], 'is_string'));
    foreach (($r['json']['fail'] ?? []) as $f) {
        if (is_array($f)) $failed[] = ['id' => fail_id($f), 'reason' => trim(($f['errmsg'] ?? $f['reason'] ?? $f['message'] ?? '') . (isset($f['errcode']) ? ' (errcode ' . $f['errcode'] . ')' : ''))];
        else $failed[] = ['id' => (string)$f, 'reason' => ''];
    }
    if (!$deleted && !$failed && isset($r['json']['errcode']) && (string)$r['json']['errcode'] !== '0') $failed[] = ['id' => '', 'reason' => nce_msg($r)];
    return ['deleted' => $deleted, 'failed' => $failed];
}
