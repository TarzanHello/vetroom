<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Download the 2FA code card as a text file.
// Allowed contexts:
// - During first 2FA setup (pending 2FA session) => exports the newly generated codes
// - After password recovery (session-stored codes) => exports the regenerated codes
// - While logged in as platform user => exports current codes from DB

$mode = (string)($_GET['mode'] ?? 'current');
$mode = strtolower(trim($mode));

$codes = null;

if ($mode === 'new') {
    $pending = vetroom_platform_twofa_pending();
    $codes = is_array($pending['new_codes'] ?? null) ? $pending['new_codes'] : null;
} elseif ($mode === 'reset') {
    $codes = is_array($_SESSION['vetroom_platform_forgot_codes'] ?? null) ? $_SESSION['vetroom_platform_forgot_codes'] : null;
} else {
    // current
    $u = vetroom_platform_current_user();
    if ($u) {
        $db = vetroom_db();
        $codes = vr_platform_twofa_get_codes($db, (int)($u['id'] ?? 0));
    }
}

if (!is_array($codes) || !$codes) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Download non autorizzato o codici non disponibili.";
    exit;
}

$ts = date('Ymd_His');
$filename = 'vetroom_admin_2fa_codes_' . $ts . '.txt';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "VetRoom Platform - Tabella codici 2FA\n";
echo "Generata: " . date('c') . "\n\n";
echo "CONSERVA QUESTA TABELLA IN UN LUOGO SICURO.\n";
echo "In fase di login ti verranno richieste alcune cifre di specifici codici.\n\n";

ksort($codes);
foreach ($codes as $i => $c) {
    $i = (int)$i;
    $c = (string)$c;
    if ($i < 1 || $i > 16) continue;
    echo str_pad((string)$i, 2, '0', STR_PAD_LEFT) . ": " . $c . "\n";
}

// Reduce exposure: clear recovery codes after exporting.
if ($mode === 'reset') {
    unset($_SESSION['vetroom_platform_forgot_codes']);
}

exit;
