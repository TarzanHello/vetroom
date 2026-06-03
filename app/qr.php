<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Centralized QR generator endpoint.
 *
 * IMPORTANT:
 * - The staff UI enforces a strict CSP: img-src 'self' data:
 * - Therefore we cannot rely on client-side redirects to external QR services.
 *
 * This endpoint fetches a PNG from a remote QR renderer server-side and returns it
 * as an image/png from this same origin. This keeps QR rendering compatible with
 * CSP now, while still allowing us to swap to a fully-local encoder later.
 *
 * GET params:
 * - data: string (required)
 * - size: int (optional, default 220, clamped 120..600)
 */

$data = (string)($_GET['data'] ?? '');
$size = (int)($_GET['size'] ?? 220);
$size = max(120, min(600, $size));

if ($data === '' || strlen($data) > 1400) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "QR: parametro data mancante o troppo lungo.";
    exit;
}

// Avoid caching (links contain short-lived tokens).
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

// Remote PNG (server-side fetch; browser sees a same-origin image).
$remote = 'https://api.qrserver.com/v1/create-qr-code/?format=png&size=' . $size . 'x' . $size . '&data=' . urlencode($data);

$png = false;

// Prefer cURL if available (better timeouts/ssl handling).
if (function_exists('curl_init')) {
    $ch = curl_init($remote);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 7);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VetRoomQR/1.0');
    $png = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) {
        $png = false;
    }
}

// Fallback to file_get_contents if allowed.
if ($png === false && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 7, 'header' => "User-Agent: VetRoomQR/1.0\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $png = @file_get_contents($remote, false, $ctx);
}

if ($png === false || $png === '' ) {
    http_response_code(502);
    header('Content-Type: image/svg+xml; charset=utf-8');
    $msg = 'QR non disponibile';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 '.$size.' '.$size.'">'
       . '<rect x="0" y="0" width="'.$size.'" height="'.$size.'" fill="#ffffff" stroke="#e5e7eb" />'
       . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#111827" font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="14">'.$msg.'</text>'
       . '</svg>';
    exit;
}

header('Content-Type: image/png');
echo $png;
exit;
