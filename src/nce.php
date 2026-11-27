<?php
/*
 * NCE-Campus Guest Voucher - NCE-Client
 * Ersteller: Patrick Brandt
 *
 * cURL-Zugriff auf die Northbound-API inklusive paralleler POSTs und einheitlicher Fehlerauswertung.
 */

function nce_curl($method, $path, $token = null, $body = null, $query = null) {
    global $CFG, $NCE;
    $host = $NCE['host'];
    $resolve = null;
    $name = $NCE['protocol'] === 'https' ? tls_name() : $host;
    if ($name !== '' && strcasecmp($name, $host) !== 0) {
        $ip = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $resolve = [$name . ':' . $NCE['port'] . ':' . $ip]; // per IP verbinden, Zertifikat gegen den Namen pruefen
        $host = $name;
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
/** Schluessel fuer ein cURL-Handle: ab PHP 8 ein Objekt, unter PHP 7.4 eine Ressource. */
function handle_key($ch) { return is_object($ch) ? spl_object_id($ch) : (int)$ch; }
/** Mehrere POSTs mit begrenzter Parallelitaet. Rueckgabe in Eingabereihenfolge. */
function nce_pool_post($path, $token, $bodies, $concurrency) {
    $mh = curl_multi_init();
    $results = []; $active = []; $next = 0; $n = count($bodies);
    while ($next < $n || $active) {
        while ($next < $n && count($active) < $concurrency) {
            $ch = nce_curl('POST', $path, $token, $bodies[$next]);
            curl_multi_add_handle($mh, $ch);
            $active[handle_key($ch)] = $next++;
        }
        do { $mrc = curl_multi_exec($mh, $running); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        if ($running) curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle']; $id = handle_key($ch);
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
