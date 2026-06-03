<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

vetroom_require_platform_login();

$appId = (int)($_GET['app_id'] ?? 0);
$file = basename((string)($_GET['file'] ?? ''));

$db = vetroom_db();
$st = $db->prepare("SELECT data_json FROM clinic_applications WHERE id=? LIMIT 1");
$st->execute([$appId]);
$dataJson = $st->fetchColumn();
if (!$dataJson) vr_abort_not_found();

$data = json_decode((string)$dataJson, true) ?: [];
$docs = $data['docs'] ?? [];
$path = null;
foreach ($docs as $k=>$p) {
    if (basename($p) === $file) { $path = $p; break; }
}
if (!$path) vr_abort_not_found();

$full = __DIR__ . '/' . ltrim($path,'/');
$real = realpath($full);
if ($real === false || !is_file($real)) vr_abort_not_found();

// Security: allow downloads only from known upload roots
$allowedRoots = [
    realpath(__DIR__ . '/data/uploads') ?: '',
    realpath(__DIR__ . '/data/private_uploads') ?: '',
];
$ok = false;
foreach ($allowedRoots as $root) {
    if ($root !== '' && strpos($real, $root) === 0) { $ok = true; break; }
}
if (!$ok) vr_abort_not_found();

$mime = 'application/octet-stream';
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
if (in_array($ext,['pdf'])) $mime='application/pdf';
if (in_array($ext,['jpg','jpeg'])) $mime='image/jpeg';
if (in_array($ext,['png'])) $mime='image/png';

header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.basename($real).'"');
header('Content-Length: '.filesize($real));
readfile($real);
exit;
