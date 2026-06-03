<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/consent.php';

// Authenticated download endpoint for staff.
// Use this for visit PDFs and attachments (files should be stored under data/private_uploads).

vetroom_require_login();

$user = vetroom_current_user();
$clinicId = (int)($user['clinic_id'] ?? 0);

$docId = (int)($_GET['doc_id'] ?? 0);
if ($docId <= 0) {
    vr_abort_not_found();
}

$db = vetroom_db();
$stmt = $db->prepare("SELECT * FROM documents WHERE id = ? AND clinic_id = ? LIMIT 1");
$stmt->execute([$docId, $clinicId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    vr_abort_not_found();
}

// RBAC hardening: SECRETARY must not access visit-related documents (including visit PDFs and attachments).
// This prevents data leaks via direct links.
if (vr_policy_role($user) === 'SECRETARY') {
    $vid = (int)($doc['visit_id'] ?? 0);
    if ($vid > 0) {
        vr_abort_not_found();
    }
    $dr = strtoupper((string)($doc['doc_role'] ?? ''));
    if ($dr !== '' && (str_starts_with($dr, 'VISIT_PDF') || $dr === 'ATTACHMENT')) {
        vr_abort_not_found();
    }
}

// Consent gating: non-admin staff can download documents only for ACTIVE owner links.
if (!vr_policy_is_admin($user)) {
    $ownerId = (int)($doc['owner_id'] ?? 0);
    if ($ownerId > 0 && !vr_consent_is_owner_active($db, $clinicId, $ownerId)) {
        vr_abort_not_found();
    }
}

$rel = (string)($doc['filename'] ?? '');
if ($rel === '') {
    vr_abort_not_found();
}

// Normalize and prevent path traversal.
$rel = ltrim(str_replace('\\', '/', $rel), '/');
if (strpos($rel, '..') !== false || strpos($rel, ':') !== false) {
    vr_abort_not_found();
}

$abs = __DIR__ . '/' . $rel;
$real = realpath($abs);
if ($real === false || !is_file($real)) {
    vr_abort_not_found();
}

// Ensure the file is inside an allowed directory.
$allowed = [
    realpath(__DIR__ . '/data/private_uploads') ?: '',
    realpath(__DIR__ . '/data/uploads') ?: '', // legacy support
];
$okPrefix = false;
foreach ($allowed as $base) {
    if ($base !== '' && strncmp($real, $base, strlen($base)) === 0) {
        $okPrefix = true;
        break;
    }
}
if (!$okPrefix) {
    vr_abort_not_found();
}

$inline = (int)($_GET['inline'] ?? 0) === 1;

$mime = (string)($doc['mime_type'] ?? '');
if ($mime === '') {
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

$name = (string)($doc['original_name'] ?? '');
if (trim($name) === '') {
    $name = basename($real);
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($name) . '"');

readfile($real);
exit;
