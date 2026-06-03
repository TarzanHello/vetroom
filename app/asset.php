<?php
/**
 * Authenticated asset endpoint.
 *
 * NOTE:
 * The /app/data folder is intentionally blocked from direct web access.
 * Some UI pages (template editor, settings previews) still need to display
 * uploaded images (logo, stamp, template backgrounds).
 *
 * This endpoint streams safe, clinic-scoped assets after authentication.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/visit_templates_repo.php';

vetroom_require_login();

$user = vetroom_current_user();
$clinicId = (int)($user['clinic_id'] ?? 0);

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$sheet = (string)($_GET['sheet'] ?? '');

if ($clinicId <= 0) {
    vr_abort_not_found();
}

$db = vetroom_db();

// Resolve the relative path from DB in a clinic-scoped way.
$rel = '';

if ($type === 'logo' || $type === 'stamp') {
    $hdr = vr_get_vet_header($clinicId) ?? [];
    if ($type === 'logo') {
        $rel = trim((string)($hdr['logo_path'] ?? ''));
    } else {
        $rel = trim((string)($hdr['stamp_path'] ?? ''));
    }
} elseif ($type === 'template_bg') {
    $sheetKey = vr_visit_sheet_key_normalize($sheet === '' ? 'clinica' : $sheet);
    $cfg = vr_visit_template_get($db, $clinicId, $sheetKey) ?: [];
    $rel = trim((string)($cfg['bg_path'] ?? ''));

    // Fallback to built-in previews if the clinic doesn't have a custom background.
    if ($rel === '') {
        if ($sheetKey === 'oftalmo') {
            $rel = 'public/templates/scheda_visita_oftalmologica_vetroom.png';
        } else {
            $rel = 'public/templates/scheda_visita_clinica_vetroom.png';
        }
    }
} else {
    vr_abort_not_found();
}

if ($rel === '') {
    vr_abort_not_found();
}

// Normalize and prevent traversal.
$rel = ltrim(str_replace('\\', '/', $rel), '/');
if (strpos($rel, '..') !== false || strpos($rel, ':') !== false || strpos($rel, "\0") !== false) {
    vr_abort_not_found();
}

$abs = __DIR__ . '/' . $rel;
$real = realpath($abs);
if ($real === false || !is_file($real)) {
    vr_abort_not_found();
}

// Allow only known safe bases.
$allowedBases = [
    realpath(__DIR__ . '/data/uploads') ?: '',
    realpath(__DIR__ . '/public/templates') ?: '',
];
$ok = false;
foreach ($allowedBases as $base) {
    if ($base === '') continue;
    $base = rtrim($base, "/\\") . DIRECTORY_SEPARATOR;
    if (strncmp($real, $base, strlen($base)) === 0) {
        $ok = true;
        break;
    }
}
if (!$ok) {
    vr_abort_not_found();
}

$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
};

// Avoid caching issues when the same filename is overwritten (common for template backgrounds).
if (!headers_sent()) {
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($real) . '"');
}

readfile($real);
exit;
