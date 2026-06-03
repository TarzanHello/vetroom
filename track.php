<?php
declare(strict_types=1);

// VetRoom landing tracking endpoint (cookie consent + heatmap events)
// Stores events in the main VetRoom SQLite DB so the platform admin can review them.

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    // Support form-encoded fallback
    $payload = $_POST;
}

$event = strtolower(trim((string)($payload['event_type'] ?? '')));
$allowed = ['consent','pageview','click'];
if (!in_array($event, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Bad event_type'], JSON_UNESCAPED_UNICODE);
    exit;
}

$consentId = (string)($payload['consent_id'] ?? '');
$consentObj = is_array($payload['consent'] ?? null) ? $payload['consent'] : null;

// Try cookie fallback
if ($consentId === '' && !empty($_COOKIE['vr_consent'])) {
    $cRaw = (string)$_COOKIE['vr_consent'];
    $cJson = json_decode(urldecode($cRaw), true);
    if (is_array($cJson) && !empty($cJson['consent_id'])) {
        $consentId = (string)$cJson['consent_id'];
        if ($consentObj === null) $consentObj = $cJson;
    }
}

if ($consentId === '' || !preg_match('/^[a-z0-9\-]{8,64}$/i', $consentId)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing consent_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$necessary = 1;
$profiling = 0;
if ($consentObj) {
    $necessary = (int)($consentObj['necessary'] ?? 1) ? 1 : 0;
    $profiling = (int)($consentObj['profiling'] ?? 0) ? 1 : 0;
}

// Rate limit (very small, per IP)
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($ip !== '') {
    $dir = __DIR__ . '/app/data/ratelimit';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $key = hash('sha256', $ip);
    $file = $dir . '/track_' . $key . '.json';
    $now = time();
    $window = 60; // 1 minute
    $max = 120;   // 120 events/minute per IP
    $data = ['hits' => []];
    if (is_file($file)) {
        $raw2 = @file_get_contents($file);
        $decoded = json_decode((string)$raw2, true);
        if (is_array($decoded)) $data = $decoded;
    }
    $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];
    $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t >= ($now - $window)));
    if (count($hits) >= $max) {
        http_response_code(429);
        echo json_encode(['ok'=>false,'error'=>'Rate limited'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hits[] = $now;
    $data['hits'] = $hits;
    @file_put_contents($file, json_encode($data));
}

// Collect optional fields
$pagePath = trim((string)($payload['page_path'] ?? ''));
if ($pagePath === '' && isset($_SERVER['HTTP_REFERER'])) {
    $pagePath = (string)($_SERVER['HTTP_REFERER'] ?? '');
}
if (strlen($pagePath) > 300) $pagePath = substr($pagePath, 0, 300);

$ref = trim((string)($payload['referrer'] ?? ''));
if (strlen($ref) > 500) $ref = substr($ref, 0, 500);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if (strlen($ua) > 500) $ua = substr($ua, 0, 500);

$x = isset($payload['x_percent']) ? (float)$payload['x_percent'] : null;
$y = isset($payload['y_percent']) ? (float)$payload['y_percent'] : null;
$vw = isset($payload['viewport_w']) ? (int)$payload['viewport_w'] : null;
$vh = isset($payload['viewport_h']) ? (int)$payload['viewport_h'] : null;
$dh = isset($payload['doc_h']) ? (int)$payload['doc_h'] : null;
$sy = isset($payload['scroll_y']) ? (int)$payload['scroll_y'] : null;

// Sanitize percentages
if ($x !== null && ($x < 0 || $x > 1)) $x = null;
if ($y !== null && ($y < 0 || $y > 1)) $y = null;

$meta = $payload['meta'] ?? null;
$metaJson = null;
if (is_array($meta)) {
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($metaJson) && strlen($metaJson) > 4000) {
        $metaJson = substr($metaJson, 0, 4000);
    }
}

try {
    require_once __DIR__ . '/app/config.php';
    require_once __DIR__ . '/app/db.php';
    $db = vetroom_db();
    $nowIso = date('c');

    // Upsert consent row
    $st = $db->prepare("SELECT id FROM cookie_consents WHERE consent_id=? LIMIT 1");
    $st->execute([$consentId]);
    $existingId = $st->fetchColumn();
    if ($existingId) {
        $db->prepare("UPDATE cookie_consents SET necessary=?, profiling=?, last_seen=?, last_path=?, referrer=?, ip=?, user_agent=? WHERE consent_id=?")
           ->execute([$necessary, $profiling, $nowIso, $pagePath, $ref, $ip, $ua, $consentId]);
    } else {
        $db->prepare("INSERT INTO cookie_consents (consent_id, necessary, profiling, ip, user_agent, first_seen, last_seen, last_path, referrer) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$consentId, $necessary, $profiling, $ip, $ua, $nowIso, $nowIso, $pagePath, $ref]);
    }

    // Store events only if profiling consent is ON (except 'consent')
    if ($event === 'consent' || $profiling === 1) {
        $db->prepare("INSERT INTO cookie_events (consent_id, event_type, page_path, x_percent, y_percent, viewport_w, viewport_h, doc_h, scroll_y, meta_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$consentId, $event, $pagePath, $x, $y, $vw, $vh, $dh, $sy, $metaJson, $nowIso]);
    }

    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB error'], JSON_UNESCAPED_UNICODE);
}
