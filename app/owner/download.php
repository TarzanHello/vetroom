<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../consent.php';

// Authenticated download endpoint for OWNERS.
// Only definitive visit PDFs are exposed to the owner portal.

vetroom_require_owner_login('login.php');

$db = vetroom_db();
$sess = vetroom_owner_current_user();

$accountId = (int)($sess['id'] ?? 0);
$ownerId   = (int)($sess['owner_id'] ?? 0);
$clinicId  = (int)($sess['clinic_id'] ?? 0);

$docId = (int)($_GET['doc_id'] ?? 0);
if ($docId <= 0 || $ownerId <= 0 || $clinicId <= 0 || $accountId <= 0) {
    vr_abort_not_found();
}

// Ensure the current session still has an ACTIVE relationship for this clinic.
try {
    $stRel = $db->prepare(
        "SELECT 1
           FROM vet_owner_relations
          WHERE clinic_id=? AND owner_id=? AND owner_account_id=? AND status='active'
          LIMIT 1"
    );
    $stRel->execute([$clinicId, $ownerId, $accountId]);
    if (!$stRel->fetchColumn()) {
        vr_abort_not_found();
    }
} catch (Throwable $t) {
    vr_abort_not_found();
}

// Load document row and enforce ownership + role.
$stmt = $db->prepare("SELECT * FROM documents WHERE id=? AND clinic_id=? AND owner_id=? LIMIT 1");
$stmt->execute([$docId, $clinicId, $ownerId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    vr_abort_not_found();
}

if ((string)($doc['doc_role'] ?? '') !== 'VISIT_PDF_FINAL') {
    // Only definitive PDFs can be downloaded from the owner portal.
    vr_abort_not_found();
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

$abs = __DIR__ . '/../' . $rel;
$real = realpath($abs);
if ($real === false || !is_file($real)) {
    vr_abort_not_found();
}

// Ensure the file is inside an allowed directory.
$allowed = [
    realpath(__DIR__ . '/../data/private_uploads') ?: '',
    realpath(__DIR__ . '/../data/uploads') ?: '', // legacy support
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
