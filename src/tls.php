<?php
/*
 * NCE-Campus Guest Voucher - TLS
 * Ersteller: Patrick Brandt
 *
 * Vertrauen in das NCE-Zertifikat: CA-Datei oder beim ersten Login uebernommenes Zertifikat (Trust on first use).
 */

/** Reihenfolge: caFile aus config.php > nce-ca-<Ziel>.pem bzw. nce-ca.pem im App-Ordner > data/nce-ca-<Ziel>.pem (automatisch) */
function ca_candidates() {
    global $NCE;
    $id = (int)$NCE['_id'];
    $f = trim((string)$NCE['caFile']);
    if ($f !== '') return [['config', preg_match('#^(/|[A-Za-z]:[\\\\/])#', $f) ? $f : APP_DIR . '/' . $f]];
    return [['app', APP_DIR . "/nce-ca-$id.pem"], ['app', APP_DIR . '/nce-ca.pem'], ['auto', DATA_DIR . "/nce-ca-$id.pem"]];
}
function ca_file($withSource = false) {
    $c = ca_candidates();
    if ($c[0][0] === 'config') return $withSource ? $c[0] : $c[0][1];
    foreach ($c as $x) if (is_file($x[1])) return $withSource ? $x : $x[1];
    return null;
}
function tls_needed() { global $NCE; return $NCE['protocol'] === 'https' && $NCE['tlsVerify'] !== false; }

/** Zertifikatskette ohne Pruefung abrufen - dabei werden keine Zugangsdaten gesendet. */
function tls_fetch_chain() {
    global $NCE;
    if (!function_exists('openssl_x509_export')) return [null, 'PHP-Erweiterung openssl fehlt - Zertifikat kann nicht automatisch uebernommen werden.'];
    $sni = $NCE['tlsServerName'] !== '' ? $NCE['tlsServerName'] : $NCE['host'];
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert_chain' => true, 'verify_peer' => false, 'verify_peer_name' => false,
        'allow_self_signed' => true, 'SNI_enabled' => true, 'peer_name' => $sni,
    ]]);
    $host = strpos($NCE['host'], ':') !== false ? '[' . $NCE['host'] . ']' : $NCE['host'];
    $sock = @stream_socket_client("ssl://$host:{$NCE['port']}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return [null, "Zertifikat konnte nicht abgerufen werden: $errstr ($errno)"];
    $params = stream_context_get_params($sock);
    fclose($sock);
    $pem = '';
    foreach (($params['options']['ssl']['peer_certificate_chain'] ?? []) as $c) { $out = ''; if (openssl_x509_export($c, $out)) $pem .= trim($out) . "\n"; }
    return $pem === '' ? [null, 'Der NCE hat kein Zertifikat geliefert.'] : [$pem, null];
}
/** Fehlt eine CA-Datei, Zertifikat beim ersten Login uebernehmen (Vertrauen bei erster Verbindung). */
function tls_auto_pin() {
    if (!tls_needed() || ca_file()) return false;
    list($pem, $err) = tls_fetch_chain();
    if ($err) send(502, ['error' => $err]);
    $c = ca_candidates(); $file = $c[count($c) - 1][1];
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0700, true);
    if (@file_put_contents($file, $pem, LOCK_EX) === false) send(500, ['error' => 'Das NCE-Zertifikat konnte nicht in data/ gespeichert werden - bitte Schreibrechte fuer den Ordner data/ setzen.']);
    @chmod($file, 0600);
    return true;
}
function tls_info() {
    global $NCE;
    if ($NCE['protocol'] !== 'https') return ['mode' => 'http'];
    if ($NCE['tlsVerify'] === false) return ['mode' => 'unverified'];
    $x = ca_file(true);
    if (!$x) return ['mode' => 'system'];
    $info = ['mode' => 'verified', 'source' => $x[0], 'since' => is_file($x[1]) ? filemtime($x[1]) : null];
    $pem = @file_get_contents($x[1]);
    if ($pem && function_exists('openssl_x509_parse') && ($c = @openssl_x509_parse($pem))) {
        $info['subject'] = $c['subject']['CN'] ?? null;
        $info['validTo'] = $c['validTo_time_t'] ?? null;
    }
    $n = tls_name();
    if (strcasecmp($n, $NCE['host']) !== 0) $info['checkName'] = $n;
    return $info;
}
/**
 * Name, gegen den das NCE-Zertifikat geprueft wird. Vorrang hat tlsServerName aus der Konfiguration.
 * Sonst: Ist das Zertifikat nicht auf den Host bzw. die IP ausgestellt, wird der erste Name aus dem
 * gespeicherten Zertifikat (SAN, sonst CN) verwendet - verbunden wird weiterhin mit der IP.
 */
function tls_name() {
    global $NCE;
    static $cache = [];
    if ($NCE['tlsServerName'] !== '') return $NCE['tlsServerName'];
    $host = strtolower($NCE['host']);
    if (!tls_needed() || !($ca = ca_file()) || !is_file($ca) || !function_exists('openssl_x509_parse')) return $host;
    $key = $ca . '|' . @filemtime($ca) . '|' . $host;
    if (isset($cache[$key])) return $cache[$key];
    $pem = (string)@file_get_contents($ca);
    if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) || !($c = @openssl_x509_parse($m[0]))) return $cache[$key] = $host;
    $dns = []; $ips = [];
    foreach (explode(',', (string)($c['extensions']['subjectAltName'] ?? '')) as $e) {
        $e = trim($e);
        if (stripos($e, 'DNS:') === 0) $dns[] = strtolower(trim(substr($e, 4)));
        elseif (stripos($e, 'IP Address:') === 0) $ips[] = strtolower(trim(substr($e, 11)));
    }
    $cn = strtolower((string)($c['subject']['CN'] ?? ''));
    if (in_array($host, $ips, true) || in_array($host, $dns, true)) return $cache[$key] = $host;
    foreach ($dns as $d) if (strpos($d, '*.') === 0 && preg_match('/^[^.]+' . preg_quote(substr($d, 1), '/') . '$/', $host)) return $cache[$key] = $host;
    if (!$dns && $cn === $host) return $cache[$key] = $host;
    $name = $dns[0] ?? $cn;
    if ($name === '') return $cache[$key] = $host;
    if (strpos($name, '*.') === 0) $name = 'nce' . substr($name, 1); // Wildcard: beliebigen passenden Namen verwenden
    return $cache[$key] = $name;
}
function tls_hint($r) {
    if (stripos((string)$r['err'], 'certificate') === false && stripos((string)$r['err'], 'SSL') === false) return '';
    $x = ca_file(true);
    if (preg_match('/(subject name|host name|hostname)/i', (string)$r['err'])) {
        return ' Hinweis: Das Zertifikat ist nicht auf diesen Host ausgestellt. In config.php tlsServerName auf den Namen im Zertifikat setzen.';
    }
    if (!$x) return ' Hinweis: Der Webspace kennt die Zertifizierungsstelle des NCE nicht.';
    if (!is_file($x[1])) return ' Hinweis: Die CA-Datei ' . basename($x[1]) . ' wurde nicht gefunden (caFile in config.php pruefen).';
    $where = $x[0] === 'auto' ? 'data/' . basename($x[1]) : basename($x[1]);
    return " Hinweis: Das gespeicherte NCE-Zertifikat ($where) passt nicht mehr - z. B. nach einer Erneuerung. "
         . 'Datei per FTP loeschen; beim naechsten Login wird das aktuelle Zertifikat uebernommen.';
}
