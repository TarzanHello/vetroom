<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

vetroom_require_platform_login();

$db = vetroom_db();
$docId = (int)($_GET['doc_id'] ?? 0);
$inline = isset($_GET['inline']) ? (int)$_GET['inline'] : 0;
if ($docId <= 0) {
    http_response_code(400);
    echo "Bad Request";
    exit;
}

$st = $db->prepare("SELECT * FROM documents WHERE id=? LIMIT 1");
$st->execute([$docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    echo "Not Found";
    exit;
}

$rel = (string)($doc['filename'] ?? '');
if ($rel === '') {
    http_response_code(404);
    echo "File missing";
    exit;
}

// Resolve safe absolute path (must be inside /app/data)
$rel = ltrim($rel, '/');
if (strpos($rel, '..') !== false) {
    http_response_code(400);
    echo "Invalid path";
    exit;
}
$abs = __DIR__ . '/' . $rel;
$real = realpath($abs);
if ($real === false) {
    http_response_code(404);
    echo "Not Found";
    exit;
}
$base = realpath(__DIR__ . '/data');
if ($base && strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$mime = (string)($doc['mime_type'] ?? 'application/octet-stream');
$name = (string)($doc['original_name'] ?? ('documento-' . $docId));

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime);
if ($inline === 1) {
    header('Content-Disposition: inline; filename="' . addslashes($name) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
}
header('Content-Length: ' . filesize($real));

readfile($real);
exit;
