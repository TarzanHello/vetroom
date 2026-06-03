<?php
declare(strict_types=1);

// Basic security hardening (safe defaults)
@ini_set('session.use_strict_mode', '1');
@ini_set('session.use_only_cookies', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');

/**
 * Sessioni separate tra:
 * - Piattaforma (superuser)  -> platform.php, install.php, register.php, download_admin.php
 * - Gestionale (staff/vet)   -> index.php
 *
 * Questo evita loop di redirect e interferenze tra login admin e login veterinari.
 */
if (session_status() === PHP_SESSION_NONE) {
    $scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptPath = str_replace('\\', '/', $scriptPath);
    $script = basename($scriptPath);

    // Endpoints that do not need sessions (avoid setting a PHPSESSID-style cookie on the landing)
    $noSessionScripts = ['send.php', 'track.php'];
    if (!in_array($script, $noSessionScripts, true)) {
    // Harden cookie flags. (Must be set before session_start.)
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $params = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        @session_set_cookie_params([
            'lifetime' => 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $platformScripts = [
        'platform.php',
        'install.php',
        'register.php',
        'download_admin.php',
        'platform_doc.php',
        'platform_twofa_export.php',
    ];

    // OWNER portal uses its own dedicated session cookie.
    // NOTE: Do NOT rely on basename alone (owner has its own login.php/register.php).
    $isOwner = (strpos($scriptPath, '/app/owner/') !== false);
    if ($isOwner) {
        @session_name('vetroom_owner');
    } elseif (in_array($script, $platformScripts, true)) {
        @session_name('vetroom_platform');
    } else {
        @session_name('vetroom_staff');
    }
    session_start();
    }
}

// Security headers (best effort; won't break CLI)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

// Percorso del database SQLite per VetRoom 2
define('VETROOM_DB_PATH', __DIR__ . '/data/vetroom2.sqlite');

// Email di default per l'invio (meglio se sullo stesso dominio del sito).
define('VETROOM_MAIL_FROM', 'no-reply@vetroom.it');

// Destinatario richieste landing / contatti
define('VETROOM_CONTACT_TO', 'info@vetroom.it');

// Nome applicazione
define('VETROOM_APP_NAME', 'VetRoom');
