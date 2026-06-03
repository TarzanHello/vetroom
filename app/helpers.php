<?php
require_once __DIR__ . '/db.php';
// lazy: require_once __DIR__ . '/lib/minipdf.php';


/**
 * Escape HTML.
 */
function vr_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * ==========================
 * URL helpers
 * ==========================
 *
 * Build absolute URLs for links inside /app.
 * - Works when the app is deployed in a subfolder (e.g. /vetroom/app).
 * - Uses the current request host/scheme by default.
 * - Optional override: define('VETROOM_BASE_URL', 'https://www.vetroom.it/app') in config.php
 */
function vr_request_scheme(): string {
    $https = (string)($_SERVER['HTTPS'] ?? '');
    return (!empty($https) && $https !== 'off') ? 'https' : 'http';
}

function vr_request_host(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // Best-effort hardening (strip CRLF to avoid header injection in mail bodies).
    $host = preg_replace('/[
]+/', '', $host);
    return $host;
}


/**
 * Returns base URL pointing to the /app folder (without trailing slash).
 */
function vr_app_base_url(): string {
    // Optional explicit base URL (recommended in production).
    if (defined('VETROOM_BASE_URL')) {
        $b = rtrim((string) VETROOM_BASE_URL, '/');
        if ($b !== '') return $b;
    }

    $scheme = vr_request_scheme();
    $host = vr_request_host();

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = '';
    if (preg_match('#^(.*?/app)(/|$)#', $script, $m)) {
        $basePath = (string)$m[1];
    } else {
        $basePath = rtrim(str_replace('\\', '/', dirname($script)), '/');
    }
    if ($basePath === '') $basePath = '/';

    return $scheme . '://' . $host . $basePath;
}

/**
 * Builds an absolute URL inside /app (staff_register.php, register.php, membership.php, ...).
 *
 * Accepts either:
 * - a relative path like "staff_register.php?token=..."
 * - an absolute URL (returned unchanged)
 */
function vr_app_url(string $path): string {
    $path = trim($path);
    if ($path === '') return vr_app_base_url() . '/';
    if (preg_match('#^https?://#i', $path)) return $path;

    if ($path[0] !== '/') $path = '/' . $path;
    return vr_app_base_url() . $path;
}

/**
 * Centralized QR generator URL.
 * Returns an absolute URL to /app/qr.php that will render a QR code for the given text.
 *
 * NOTE: qr.php currently uses a redirect-based generator, so swapping to a local encoder later
 * will not require changing any page that uses this function.
 */
function vr_qr_url(string $data, int $size = 220): string {
    $size = max(120, min(600, $size));
    return vr_app_url('qr.php?size=' . $size . '&data=' . urlencode($data));
}


/**
 * ==========================
 * CSRF helpers (Staff app)
 * ==========================
 *
 * The staff app (app/index.php) uses a dedicated session cookie (see config.php).
 * We implement a synchronizer token pattern with a stable, per-session token.
 *
 * - Session key: vr_staff_csrf_token
 * - POST field:  csrf_token
 */
function vr_staff_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Best-effort: sessions should already be started by config.php.
        return '';
    }
    if (empty($_SESSION['vr_staff_csrf_token'])) {
        try {
            $_SESSION['vr_staff_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $t) {
            // Fallback if random_bytes is unavailable.
            $_SESSION['vr_staff_csrf_token'] = sha1(uniqid('', true) . mt_rand());
        }
    }
    return (string)$_SESSION['vr_staff_csrf_token'];
}

/**
 * Returns an HTML hidden input for CSRF, to be placed inside a POST form.
 */
function vr_staff_csrf_field(): string {
    $t = vr_staff_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . vr_h($t) . '">';
}

/**
 * Validate CSRF token for staff app POSTs.
 */
function vr_staff_csrf_is_valid(): bool {
    $posted = (string)($_POST['csrf_token'] ?? '');
    $sess = (string)($_SESSION['vr_staff_csrf_token'] ?? '');
    if ($posted === '' || $sess === '') return false;
    return hash_equals($sess, $posted);
}


/**
 * ==========================
 * CSRF helpers (Owner portal)
 * ==========================
 *
 * The owner portal (app/owner/*) uses a dedicated session cookie (see config.php).
 * Token is stored per-session.
 *
 * - Session key: vr_owner_csrf_token
 * - POST field:  csrf_token
 */
function vr_owner_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['vr_owner_csrf_token'])) {
        try {
            $_SESSION['vr_owner_csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $t) {
            $_SESSION['vr_owner_csrf_token'] = sha1(uniqid('', true) . mt_rand());
        }
    }
    return (string)$_SESSION['vr_owner_csrf_token'];
}

function vr_owner_csrf_field(): string {
    $t = vr_owner_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . vr_h($t) . '">';
}

function vr_owner_csrf_is_valid(): bool {
    $posted = (string)($_POST['csrf_token'] ?? '');
    $sess = (string)($_SESSION['vr_owner_csrf_token'] ?? '');
    if ($posted === '' || $sess === '') return false;
    return hash_equals($sess, $posted);
}


/**
 * Data di oggi (YYYY-MM-DD).
 */
function vr_today_date(): string {
    return date('Y-m-d');
}

/**
 * Timestamp ISO.
 */
function vr_now_iso(): string {
    return date('c');
}

/**
 * Valida un codice fiscale (controllo base: lunghezza/caratteri).
 *
 * Note:
 * - Per compatibilità UX lato staff, qui non imponiamo il check digit.
 * - Per il portale proprietario usare vr_cf_is_valid_strict().
 */
function vr_cf_is_valid(string $cf): bool {
    $cf = strtoupper(trim($cf));
    $cf = preg_replace('/\s+/', '', $cf);
    if ($cf === '') {
        return true; // opzionale
    }
    if (strlen($cf) !== 16) {
        return false;
    }
    return (bool)preg_match('/^[A-Z0-9]{16}$/', $cf);
}

/**
 * Validazione Codice Fiscale con check digit (16° carattere).
 *
 * Usata per l'Identity del portale proprietario.
 */
function vr_cf_is_valid_strict(string $cf): bool {
    $cf = strtoupper(trim($cf));
    $cf = preg_replace('/\s+/', '', $cf);
    if ($cf === '') {
        return true; // opzionale (il chiamante può renderlo obbligatorio)
    }
    if (strlen($cf) !== 16) {
        return false;
    }
    if (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
        return false;
    }
    $cf15 = substr($cf, 0, 15);
    $chk = substr($cf, 15, 1);
    $exp = vr_cf_compute_check_digit($cf15);
    if ($exp === '') return false;
    return hash_equals($exp, $chk);
}

/**
 * Compute Codice Fiscale check digit (16th character).
 *
 * Implements the standard odd/even position tables used by Italian CF.
 */
function vr_cf_compute_check_digit(string $cf15): string {
    $cf15 = strtoupper(trim($cf15));
    $cf15 = preg_replace('/\s+/', '', $cf15);
    if (strlen($cf15) !== 15) return '';
    if (!preg_match('/^[A-Z0-9]{15}$/', $cf15)) return '';

    $odd = [
        '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
        'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
        'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
        'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23,
    ];
    $even = [
        '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
        'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
        'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
        'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25,
    ];

    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $ch = $cf15[$i];
        $pos = $i + 1; // 1-based
        if ($pos % 2 === 1) {
            $sum += (int)($odd[$ch] ?? -999);
        } else {
            $sum += (int)($even[$ch] ?? -999);
        }
    }
    if ($sum < 0) return '';
    $rem = $sum % 26;
    return chr(ord('A') + $rem);
}

/**
 * Ritorna il lunedì della settimana della data indicata.
 */
function vr_week_monday(string $date): string {
    $ts = strtotime($date);
    $dow = (int)date('N', $ts); // 1..7
    $mondayTs = $ts - ($dow - 1) * 86400;
    return date('Y-m-d', $mondayTs);
}

/**
 * Ritorna array con 7 date (lun..dom) della settimana.
 */
function vr_week_days(string $monday): array {
    $base = strtotime($monday);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = date('Y-m-d', $base + $i * 86400);
    }
    return $days;
}

/**
 * Info mese (gestisce overflow).
 */
function vr_month_info(int $year, int $month): array {
    $dt = DateTime::createFromFormat('Y-n-j', $year . '-' . $month . '-1');
    if (!$dt) {
        $dt = new DateTime();
    }
    $year = (int)$dt->format('Y');
    $month = (int)$dt->format('n');
    $firstDay = $dt->format('Y-m-01');
    $lastDay = $dt->format('Y-m-t');
    return [
        'year' => $year,
        'month' => $month,
        'first_day' => $firstDay,
        'last_day' => $lastDay,
    ];
}

/**
 * Genera gli slot disponibili/occupati per una data.
 * Ritorna array di ['time' => 'HH:MM', 'busy' => bool].
 */
function vetroom_get_slots_status(int $clinicId, string $date): array {
    $db = vetroom_db();
    $weekday = (int)date('N', strtotime($date)); // 1..7

    // Disponibilità per quel giorno
    $stmt = $db->prepare("SELECT * FROM availabilities WHERE clinic_id = ? AND weekday = ?");
    $stmt->execute([$clinicId, $weekday]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }

    $start = (string)($row['start_time'] ?? '');
    $end = (string)($row['end_time'] ?? '');
    $start2 = (string)($row['start_time2'] ?? '');
    $end2 = (string)($row['end_time2'] ?? '');

    $slotMin = (int)($row['slot_minutes'] ?? 30);
    if ($slotMin <= 0) {
        $slotMin = 30;
    }

    $ranges = [];
    if (trim($start) !== '' && trim($end) !== '') {
        $ranges[] = [$start, $end];
    }
    if (trim($start2) !== '' && trim($end2) !== '') {
        $ranges[] = [$start2, $end2];
    }

    if (empty($ranges)) {
        return [];
    }

    // Appuntamenti esistenti
    $stmtA = $db->prepare("SELECT time FROM appointments WHERE clinic_id = ? AND date = ?");
    $stmtA->execute([$clinicId, $date]);
    $busy = [];
    while ($r = $stmtA->fetch(PDO::FETCH_ASSOC)) {
        $busy[$r['time']] = true;
    }

    $slots = [];
    foreach ($ranges as $rg) {
        $rs = (string)($rg[0] ?? '');
        $re = (string)($rg[1] ?? '');
        if ($rs === '' || $re === '') continue;
        $cur = strtotime($date . ' ' . $rs);
        $endTs = strtotime($date . ' ' . $re);
        if (!$cur || !$endTs) continue;

        while ($cur < $endTs) {
            $t = date('H:i', $cur);
            $slots[] = [
                'time' => $t,
                'busy' => isset($busy[$t]),
            ];
            $cur += $slotMin * 60;
        }
    }

    // Ensure chronological order and remove duplicates (just in case)
    $seen = [];
    $uniq = [];
    foreach ($slots as $s) {
        $tt = (string)($s['time'] ?? '');
        if ($tt === '' || isset($seen[$tt])) continue;
        $seen[$tt] = true;
        $uniq[] = $s;
    }
    usort($uniq, function($a, $b){
        return strcmp((string)$a['time'], (string)$b['time']);
    });

    return $uniq;
}


/**
 * Recupera i dati intestazione del veterinario.
 */
function vr_get_vet_header(int $clinicId): ?array {
    static $cache = [];
    if (isset($cache[$clinicId])) {
        return $cache[$clinicId];
    }
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM vet_settings WHERE clinic_id = ? LIMIT 1");
    $stmt->execute([$clinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $cache[$clinicId] = $row;
    return $row;
}

/**
 * Recupera la scheda proprietario.
 */
function vr_get_owner(int $clinicId, int $ownerId): ?array {
    if ($ownerId <= 0) return null;
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM owners WHERE id = ? AND clinic_id = ? LIMIT 1");
    $stmt->execute([$ownerId, $clinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Helper per decodificare JSON form_data in sicurezza.
 */
function vr_decode_form_data(?string $json): array {
    if (!$json) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}


/**
 * Default PDF engine settings for "Scheda visita" (A4).
 * Units:
 * - *_cm in centimeters
 * - *_px in screen pixels (converted to points)
 * - *_pt in typographic points
 */
function vr_pdf_settings_defaults(): array {
    // New spec (VETROOM2_8+): each printed element is a configurable "field".
    // Units:
    // - *_cm in centimeters
    // - *_px in screen pixels (converted to points)
    // - *_pt in typographic points
    return [
        // --- Line (accent) ---
        'accent_color' => '#067481',
        'line_y_cm' => 4.5,
        'line_thickness_px' => 3,

        // --- Logo ---
        'logo_x_cm' => 2.0,
        'logo_y_cm' => 2.0,
        'logo_size_cm' => 3.0,

        // --- Title + visit date (single text field) ---
        'title_x_cm' => 6.0,
        'title_y_cm' => 3.5,
        'title_w_cm' => 7.0,
        'title_h_cm' => 2.0,
        'title_border' => 0,
        'title_border_px' => 1,
        'title_align' => 'L', // L/C/R
        'title_font_size' => 13,
        'title_font_style' => 'B', // '', B, I, BI
        'subtitle_font_size' => 10,
        'subtitle_font_style' => '',
        'subtitle_offset_cm' => 0.6, // relative to title_y

        // --- Vet data ---
        'vet_x_cm' => 13.0,
        'vet_y_cm' => 2.0,
        'vet_w_cm' => 6.0,
        'vet_h_cm' => 3.5,
        'vet_border' => 0,
        'vet_border_px' => 1,
        'vet_align' => 'L',
        'vet_font_size' => 9,
        'vet_font_style' => '',

        // --- Animal data (discursive) ---
        'animal_x_cm' => 2.0,
        'animal_y_cm' => 6.0,
        'animal_w_cm' => 17.0,
        'animal_h_cm' => 3.0,
        'animal_border' => 0,
        'animal_border_px' => 1,
        'animal_align' => 'L',
        'animal_font_size' => 11,
        'animal_font_style' => '',

        // --- Visit data (form boxes + diagnosis/therapy/notes) ---
        // visit_y_cm = -1 means "auto under animal data".
        'visit_x_cm' => 2.0,
        'visit_y_cm' => -1.0,
        'visit_w_cm' => 8.0,
        'visit_h_cm' => 20.0,
        'visit_border' => 0,
        'visit_border_px' => 1,
        'visit_align' => 'L',
        'visit_font_size' => 10,
        'visit_font_style' => '',

        // --- Owner data (scheda proprietario) ---
        // owner_y_cm = -1 means "auto aligned with diagnosis block".
        'owner_x_cm' => 11.0,
        'owner_y_cm' => -1.0,
        'owner_w_cm' => 8.0,
        'owner_h_cm' => 8.0,
        'owner_border' => 0,
        'owner_border_px' => 1,
        'owner_align' => 'R',
        'owner_font_size' => 10,
        'owner_font_style' => '',

        // --- Global text flow ---
        'line_height_pt' => 14,
        'blank_lines_after_patient' => 2,
        'gap_after_form_pt' => 6,
        'border_color' => '#067481',
    ];
}

function vr_pdf_settings_normalize(array $in): array {
    $d = vr_pdf_settings_defaults();
    $out = $d;

    // --- Backward compatibility (older JSON keys) ---
    // Older versions used keys like vet_box_*, subtitle_y_cm, margin_left_cm, etc.
    // Map them to the new schema when the new keys are not present.
    if (isset($in['vet_box_x_cm']) && !isset($in['vet_x_cm'])) $in['vet_x_cm'] = $in['vet_box_x_cm'];
    if (isset($in['vet_box_y_cm']) && !isset($in['vet_y_cm'])) $in['vet_y_cm'] = $in['vet_box_y_cm'];
    if (isset($in['vet_box_w_cm']) && !isset($in['vet_w_cm'])) $in['vet_w_cm'] = $in['vet_box_w_cm'];
    if (isset($in['vet_box_h_cm']) && !isset($in['vet_h_cm'])) $in['vet_h_cm'] = $in['vet_box_h_cm'];

    // Old subtitle absolute y -> new offset inside title field
    if (isset($in['subtitle_y_cm']) && !isset($in['subtitle_offset_cm']) && isset($in['title_y_cm'])
        && is_numeric($in['subtitle_y_cm']) && is_numeric($in['title_y_cm'])) {
        $in['subtitle_offset_cm'] = max(0.0, (float)$in['subtitle_y_cm'] - (float)$in['title_y_cm']);
    }

    // Old body/margins -> new animal/visit/owner fields
    if ((isset($in['margin_left_cm']) || isset($in['margin_right_cm']) || isset($in['body_start_y_cm']))
        && (!isset($in['animal_x_cm']) || !isset($in['animal_y_cm']) || !isset($in['animal_w_cm']))) {
        $ml = is_numeric($in['margin_left_cm'] ?? null) ? (float)$in['margin_left_cm'] : (float)$d['animal_x_cm'];
        $mr = is_numeric($in['margin_right_cm'] ?? null) ? (float)$in['margin_right_cm'] : (float)(21.0 - $d['animal_x_cm'] - $d['animal_w_cm']);
        $usable = max(2.0, 21.0 - $ml - $mr);
        if (!isset($in['animal_x_cm'])) $in['animal_x_cm'] = $ml;
        if (!isset($in['animal_y_cm']) && isset($in['body_start_y_cm'])) $in['animal_y_cm'] = $in['body_start_y_cm'];
        if (!isset($in['animal_w_cm'])) $in['animal_w_cm'] = $usable;

        // Visit/Owner columns derived from previous bottom columns model
        $gap = is_numeric($in['bottom_gap_cm'] ?? null) ? (float)$in['bottom_gap_cm'] : 1.0;
        $colW = max(2.0, ($usable - $gap) / 2.0);
        if (!isset($in['visit_x_cm'])) $in['visit_x_cm'] = $ml;
        if (!isset($in['visit_w_cm'])) $in['visit_w_cm'] = $colW;
        if (!isset($in['owner_x_cm'])) $in['owner_x_cm'] = $ml + $colW + $gap;
        if (!isset($in['owner_w_cm'])) $in['owner_w_cm'] = $colW;
    }

    // Old font keys -> new block fonts
    if (isset($in['body_font_size']) && !isset($in['animal_font_size'])) $in['animal_font_size'] = $in['body_font_size'];
    if (isset($in['form_font_size']) && !isset($in['visit_font_size'])) $in['visit_font_size'] = $in['form_font_size'];
    if (isset($in['bottom_font_size']) && !isset($in['owner_font_size'])) $in['owner_font_size'] = $in['bottom_font_size'];


    // Helper clamp
    $clamp = function($v, float $min, float $max, float $fallback) {
        if ($v === null || $v === '') return $fallback;
        if (!is_numeric($v)) return $fallback;
        $f = (float)$v;
        if ($f < $min) $f = $min;
        if ($f > $max) $f = $max;
        return $f;
    };

    // Colors
    if (isset($in['accent_color'])) {
        $hex = strtoupper(trim((string)$in['accent_color']));
        if ($hex !== '') {
            if ($hex[0] !== '#') $hex = '#' . $hex;
            if (preg_match('/^#[0-9A-F]{6}$/', $hex)) {
                $out['accent_color'] = $hex;
            }
        }
    }

    // --- Positions / sizes (cm) ---
    $out['line_y_cm'] = $clamp($in['line_y_cm'] ?? null, 0.0, 29.7, (float)$d['line_y_cm']);
    $out['logo_x_cm'] = $clamp($in['logo_x_cm'] ?? null, 0.0, 21.0, (float)$d['logo_x_cm']);
    $out['logo_y_cm'] = $clamp($in['logo_y_cm'] ?? null, 0.0, 29.7, (float)$d['logo_y_cm']);
    $out['logo_size_cm'] = $clamp($in['logo_size_cm'] ?? null, 0.5, 10.0, (float)$d['logo_size_cm']);

    $out['title_x_cm'] = $clamp($in['title_x_cm'] ?? null, 0.0, 21.0, (float)$d['title_x_cm']);
    $out['title_y_cm'] = $clamp($in['title_y_cm'] ?? null, 0.0, 29.7, (float)$d['title_y_cm']);
    $out['title_w_cm'] = $clamp($in['title_w_cm'] ?? null, 1.0, 21.0, (float)$d['title_w_cm']);
    $out['title_h_cm'] = $clamp($in['title_h_cm'] ?? null, 0.5, 10.0, (float)$d['title_h_cm']);

    $out['vet_x_cm'] = $clamp($in['vet_x_cm'] ?? null, 0.0, 21.0, (float)$d['vet_x_cm']);
    $out['vet_y_cm'] = $clamp($in['vet_y_cm'] ?? null, 0.0, 29.7, (float)$d['vet_y_cm']);
    $out['vet_w_cm'] = $clamp($in['vet_w_cm'] ?? null, 1.0, 21.0, (float)$d['vet_w_cm']);
    $out['vet_h_cm'] = $clamp($in['vet_h_cm'] ?? null, 0.5, 15.0, (float)$d['vet_h_cm']);

    $out['animal_x_cm'] = $clamp($in['animal_x_cm'] ?? null, 0.0, 21.0, (float)$d['animal_x_cm']);
    $out['animal_y_cm'] = $clamp($in['animal_y_cm'] ?? null, 0.0, 29.7, (float)$d['animal_y_cm']);
    $out['animal_w_cm'] = $clamp($in['animal_w_cm'] ?? null, 1.0, 21.0, (float)$d['animal_w_cm']);
    $out['animal_h_cm'] = $clamp($in['animal_h_cm'] ?? null, 0.5, 25.0, (float)$d['animal_h_cm']);

    // visit_y_cm and owner_y_cm allow -1 for auto
    $out['visit_x_cm'] = $clamp($in['visit_x_cm'] ?? null, 0.0, 21.0, (float)$d['visit_x_cm']);
    $out['visit_y_cm'] = $clamp($in['visit_y_cm'] ?? null, -1.0, 29.7, (float)$d['visit_y_cm']);
    $out['visit_w_cm'] = $clamp($in['visit_w_cm'] ?? null, 1.0, 21.0, (float)$d['visit_w_cm']);
    $out['visit_h_cm'] = $clamp($in['visit_h_cm'] ?? null, 0.5, 28.0, (float)$d['visit_h_cm']);

    $out['owner_x_cm'] = $clamp($in['owner_x_cm'] ?? null, 0.0, 21.0, (float)$d['owner_x_cm']);
    $out['owner_y_cm'] = $clamp($in['owner_y_cm'] ?? null, -1.0, 29.7, (float)$d['owner_y_cm']);
    $out['owner_w_cm'] = $clamp($in['owner_w_cm'] ?? null, 1.0, 21.0, (float)$d['owner_w_cm']);
    $out['owner_h_cm'] = $clamp($in['owner_h_cm'] ?? null, 0.5, 28.0, (float)$d['owner_h_cm']);

    // --- Pixels / fonts / misc ---
    $out['line_thickness_px'] = (int)round($clamp($in['line_thickness_px'] ?? null, 1.0, 12.0, (float)$d['line_thickness_px']));

    $out['title_border_px'] = (int)round($clamp($in['title_border_px'] ?? null, 0.0, 12.0, (float)$d['title_border_px']));
    $out['vet_border_px'] = (int)round($clamp($in['vet_border_px'] ?? null, 0.0, 12.0, (float)$d['vet_border_px']));
    $out['animal_border_px'] = (int)round($clamp($in['animal_border_px'] ?? null, 0.0, 12.0, (float)$d['animal_border_px']));
    $out['visit_border_px'] = (int)round($clamp($in['visit_border_px'] ?? null, 0.0, 12.0, (float)$d['visit_border_px']));
    $out['owner_border_px'] = (int)round($clamp($in['owner_border_px'] ?? null, 0.0, 12.0, (float)$d['owner_border_px']));

    $out['title_font_size'] = (int)round($clamp($in['title_font_size'] ?? null, 8.0, 30.0, (float)$d['title_font_size']));
    $out['subtitle_font_size'] = (int)round($clamp($in['subtitle_font_size'] ?? null, 6.0, 22.0, (float)$d['subtitle_font_size']));
    $out['vet_font_size'] = (int)round($clamp($in['vet_font_size'] ?? null, 6.0, 16.0, (float)$d['vet_font_size']));
    $out['animal_font_size'] = (int)round($clamp($in['animal_font_size'] ?? null, 8.0, 18.0, (float)$d['animal_font_size']));
    $out['visit_font_size'] = (int)round($clamp($in['visit_font_size'] ?? null, 8.0, 16.0, (float)$d['visit_font_size']));
    $out['owner_font_size'] = (int)round($clamp($in['owner_font_size'] ?? null, 8.0, 16.0, (float)$d['owner_font_size']));

    $out['line_height_pt'] = (int)round($clamp($in['line_height_pt'] ?? null, 10.0, 28.0, (float)$d['line_height_pt']));
    $out['blank_lines_after_patient'] = (int)round($clamp($in['blank_lines_after_patient'] ?? null, 0.0, 10.0, (float)$d['blank_lines_after_patient']));
    $out['gap_after_form_pt'] = (int)round($clamp($in['gap_after_form_pt'] ?? null, 0.0, 40.0, (float)$d['gap_after_form_pt']));
    $out['subtitle_offset_cm'] = $clamp($in['subtitle_offset_cm'] ?? null, 0.0, 10.0, (float)$d['subtitle_offset_cm']);

    // Border toggles
    $out['title_border'] = !empty($in['title_border']) ? 1 : 0;
    $out['vet_border'] = !empty($in['vet_border']) ? 1 : 0;
    $out['animal_border'] = !empty($in['animal_border']) ? 1 : 0;
    $out['visit_border'] = !empty($in['visit_border']) ? 1 : 0;
    $out['owner_border'] = !empty($in['owner_border']) ? 1 : 0;

    // Alignment + styles
    $validAlign = ['L','C','R'];
    $validStyle = ['', 'B', 'I', 'BI', 'IB'];
    $a = strtoupper(trim((string)($in['title_align'] ?? $d['title_align'])));
    $out['title_align'] = in_array($a, $validAlign, true) ? $a : (string)$d['title_align'];
    $a = strtoupper(trim((string)($in['vet_align'] ?? $d['vet_align'])));
    $out['vet_align'] = in_array($a, $validAlign, true) ? $a : (string)$d['vet_align'];
    $a = strtoupper(trim((string)($in['animal_align'] ?? $d['animal_align'])));
    $out['animal_align'] = in_array($a, $validAlign, true) ? $a : (string)$d['animal_align'];
    $a = strtoupper(trim((string)($in['visit_align'] ?? $d['visit_align'])));
    $out['visit_align'] = in_array($a, $validAlign, true) ? $a : (string)$d['visit_align'];
    $a = strtoupper(trim((string)($in['owner_align'] ?? $d['owner_align'])));
    $out['owner_align'] = in_array($a, $validAlign, true) ? $a : (string)$d['owner_align'];

    $s = strtoupper(trim((string)($in['title_font_style'] ?? $d['title_font_style'])));
    $out['title_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['title_font_style'];
    $s = strtoupper(trim((string)($in['subtitle_font_style'] ?? $d['subtitle_font_style'])));
    $out['subtitle_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['subtitle_font_style'];
    $s = strtoupper(trim((string)($in['vet_font_style'] ?? $d['vet_font_style'])));
    $out['vet_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['vet_font_style'];
    $s = strtoupper(trim((string)($in['animal_font_style'] ?? $d['animal_font_style'])));
    $out['animal_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['animal_font_style'];
    $s = strtoupper(trim((string)($in['visit_font_style'] ?? $d['visit_font_style'])));
    $out['visit_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['visit_font_style'];
    $s = strtoupper(trim((string)($in['owner_font_style'] ?? $d['owner_font_style'])));
    $out['owner_font_style'] = in_array($s, $validStyle, true) ? ($s === 'IB' ? 'BI' : $s) : (string)$d['owner_font_style'];

    // Border color (defaults to accent)
    $hexB = strtoupper(trim((string)($in['border_color'] ?? $d['border_color'])));
    if ($hexB !== '' && $hexB[0] !== '#') $hexB = '#' . $hexB;
    if (!preg_match('/^#[0-9A-F]{6}$/', $hexB)) {
        $hexB = (string)$d['border_color'];
    }
    $out['border_color'] = $hexB;

    return $out;
}

function vr_pdf_settings_from_json(?string $json): array {
    $base = vr_pdf_settings_defaults();
    if (!$json) return $base;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return $base;
    return vr_pdf_settings_normalize($arr);
}



/**
 * Sanitize file name to safe ASCII (replace spaces and special chars).
 */
function vr_sanitize_filename(string $name): string {
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $name);
    $name = trim($name, '_');
    if ($name === '') $name = 'file';
    return $name;
}

/**
 * Create a safe machine key/slug from a human label.
 * Used for custom visit forms (field keys, form slugs).
 */
function vr_slugify_key(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tmp !== false) $s = $tmp;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '') $s = 'field';
    return $s;
}

/**
 * Safely delete a file that is stored inside the application folder (typically under /app/data).
 *
 * Accepts a relative path as stored in DB (e.g. "data/private_uploads/docs/file.pdf").
 * Prevents path traversal.
 */
function vr_safe_unlink_rel(string $relPath, array $allowedRelBases = ['data/private_uploads','data/uploads']): bool {
    $relPath = trim($relPath);
    if ($relPath === '') return false;
    if (strpos($relPath, "\0") !== false) return false;
    $relPath = ltrim($relPath, "/\\");
    if (strpos($relPath, '..') !== false) return false;

    $abs = __DIR__ . '/' . $relPath;
    $real = realpath($abs);
    if (!$real) {
        // Already missing -> consider it deleted.
        return true;
    }

    foreach ($allowedRelBases as $baseRel) {
        $baseRel = trim($baseRel, "/\\");
        $baseAbs = realpath(__DIR__ . '/' . $baseRel);
        if (!$baseAbs) continue;
        $baseAbs = rtrim($baseAbs, "/\\") . DIRECTORY_SEPARATOR;
        if (substr($real, 0, strlen($baseAbs)) === $baseAbs) {
            return @unlink($real);
        }
    }
    return false;
}

/**
 * Generate a PDF for a CLINICAL visit and save it under data/uploads.
 * Returns the stored relative path (for documents table) or null on failure.
 */


function vr_generate_visit_pdf(array $header=null, array $pet, array $visit, array $formData, int $clinicId, array $owner=null, array $attachments=[], bool $isFinal = false): ?string {
    require_once __DIR__ . '/lib/pdf_templates.php';

    // Store generated visit PDFs in a private folder (not directly web-accessible).
    // Multi-tenant safe path: keep each clinic's files separated.
    $uploadDir = __DIR__ . '/data/private_uploads/clinic_' . (int)$clinicId . '/visit_pdfs';
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

    $kind = strtoupper(trim((string)($visit['visit_kind'] ?? 'CLINICA')));
    // Template key (per "scheda visita")
    // - clinica
    // - oftalmo
    // - form:<ID> (custom modules)
    $tplKey = 'clinica';
    if ($kind === 'OFTALMO') {
        $tplKey = 'oftalmo';
    } elseif ($kind === 'CUSTOM') {
        $fid = (int)($visit['visit_form_id'] ?? 0);
        $tplKey = ($fid > 0) ? ('form:' . $fid) : 'custom';
    }

    if ($owner === null) {
        $oid = (int)($visit['owner_id'] ?? ($pet['owner_id'] ?? 0));
        $owner = vr_get_owner($clinicId, $oid) ?? [];
    }

    $animal = $pet['name'] ?? 'Animale';
    $ownerSurname = $owner['surname'] ?? ($visit['owner_surname'] ?? ($pet['owner_surname'] ?? 'Proprietario'));

    // File naming rules:
    // - Drafts:    BOZZA_<TITOLO VISITA>_<NOME ANIMALE>_<COGNOME OWNER>.pdf
    // - Definitive:<TITOLO VISITA>_<NOME ANIMALE>_<COGNOME OWNER>.pdf
    $title = trim((string)($visit['title'] ?? ''));
    if ($title === '') {
        if ($kind === 'OFTALMO') {
            $title = 'Visita oftalmologica';
        } elseif ($kind === 'CUSTOM') {
            $title = (string)($visit['visit_form_name'] ?? 'Visita personalizzata');
        } else {
            $title = 'Visita clinica';
        }
    }
    $prefix = $isFinal ? '' : 'BOZZA_';
    $base = $prefix . vr_sanitize_filename($title) . '_' . vr_sanitize_filename($animal) . '_' . vr_sanitize_filename((string)$ownerSurname) . '.pdf';

    $destPath = $uploadDir . '/' . $base;
    $try = 1;
    while (file_exists($destPath) && $try < 50) {
        $destPath = $uploadDir . '/' . preg_replace('/\.pdf$/', '_' . $try . '.pdf', $base);
        $try++;
    }

    $bundle = vr_build_data_bundle($header, $pet, $visit, $formData, $owner ?? []);
    if (vr_generate_from_template($tplKey, $bundle, $destPath, $attachments)) {
        return 'data/private_uploads/clinic_' . (int)$clinicId . '/visit_pdfs/' . basename($destPath);
    }
    return null;
}

// Backward compatible alias
function vr_generate_clinical_pdf(array $header=null, array $pet, array $visit, array $formData, int $clinicId): ?string {
    $visit['visit_kind'] = $visit['visit_kind'] ?? 'CLINICA';
    return vr_generate_visit_pdf($header, $pet, $visit, $formData, $clinicId, null);
}




/**
 * ==========================
 *  PLATFORM / TENANT SAFETY
 * ==========================
 */

function vr_abort_not_found(): void {
    http_response_code(404);
    echo "<h1>404</h1><p>Risorsa non trovata.</p>";
    exit;
}

/**
 * Tracks active sessions (staff or platform).
 */
function vetroom_touch_session(string $type, int $userId, ?int $clinicId): void {
    try {
        $db = vetroom_db();
        $sid = session_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = vr_now_iso();
        $stmt = $db->prepare("
            INSERT INTO active_sessions (session_id, type, user_id, clinic_id, ip, user_agent, created_at, last_seen)
            VALUES (:sid,:type,:uid,:cid,:ip,:ua,:now,:now)
            ON CONFLICT(session_id) DO UPDATE SET
                type=excluded.type,
                user_id=excluded.user_id,
                clinic_id=excluded.clinic_id,
                ip=excluded.ip,
                user_agent=excluded.user_agent,
                last_seen=excluded.last_seen
        ");
        $stmt->execute([
            ':sid'=>$sid,
            ':type'=>$type,
            ':uid'=>$userId,
            ':cid'=>$clinicId,
            ':ip'=>$ip,
            ':ua'=>$ua,
            ':now'=>$now
        ]);
    } catch (Throwable $e) {
        // fail silently
    }
}


// (removed duplicate vr_get_owner definition)

function vr_get_pet(int $clinicId, int $petId): ?array {
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM pets WHERE id=? AND clinic_id=? LIMIT 1");
    $stmt->execute([$petId,$clinicId]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function vr_get_visit(int $clinicId, int $visitId): ?array {
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM visits WHERE id=? AND clinic_id=? LIMIT 1");
    $stmt->execute([$visitId,$clinicId]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function vr_get_document(int $clinicId, int $docId): ?array {
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM documents WHERE id=? AND clinic_id=? LIMIT 1");
    $stmt->execute([$docId,$clinicId]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function vr_get_appointment(int $clinicId, int $apptId): ?array {
    $db = vetroom_db();
    $stmt = $db->prepare("SELECT * FROM appointments WHERE id=? AND clinic_id=? LIMIT 1");
    $stmt->execute([$apptId,$clinicId]);
    $r=$stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function vr_require_owner(int $clinicId, int $ownerId): array {
    $r = vr_get_owner($clinicId,$ownerId);
    if (!$r) vr_abort_not_found();
    return $r;
}
function vr_require_pet(int $clinicId, int $petId): array {
    $r = vr_get_pet($clinicId,$petId);
    if (!$r) vr_abort_not_found();
    return $r;
}
function vr_require_visit(int $clinicId, int $visitId): array {
    $r = vr_get_visit($clinicId,$visitId);
    if (!$r) vr_abort_not_found();
    return $r;
}
function vr_require_document(int $clinicId, int $docId): array {
    $r = vr_get_document($clinicId,$docId);
    if (!$r) vr_abort_not_found();
    return $r;
}
function vr_require_appointment(int $clinicId, int $apptId): array {
    $r = vr_get_appointment($clinicId,$apptId);
    if (!$r) vr_abort_not_found();
    return $r;
}