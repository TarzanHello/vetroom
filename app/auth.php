<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/crypto.php';

/*
 * Helpers for password recovery
 */
function vr_norm_secret_answer(string $a): string {
    $a = trim($a);
    $a = preg_replace('/\s+/', ' ', $a);
    if (function_exists('mb_strtolower')) return (string)mb_strtolower($a);
    return strtolower($a);
}

/*
 * ==========================
 *  PLATFORM 2FA (code card)
 * ==========================
 */

function vr_platform_twofa_generate_codes(): array {
    $codes = [];
    for ($i = 1; $i <= 16; $i++) {
        $n = random_int(0, 9999);
        $codes[$i] = str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }
    return $codes;
}

function vr_platform_twofa_store_codes(PDO $db, int $platformUserId, array $codes): void {
    $db->prepare("DELETE FROM platform_2fa_cards WHERE platform_user_id=?")->execute([$platformUserId]);
    $now = date('c');
    $ins = $db->prepare("INSERT INTO platform_2fa_cards (platform_user_id, code_index, code_enc, nonce, tag, created_at) VALUES (?,?,?,?,?,?)");
    foreach ($codes as $idx => $code) {
        $idx = (int)$idx;
        if ($idx < 1 || $idx > 16) continue;
        $enc = vr_crypto_encrypt((string)$code);
        $ins->execute([
            $platformUserId,
            $idx,
            (string)($enc['enc'] ?? ''),
            $enc['nonce'] ?? null,
            $enc['tag'] ?? null,
            $now,
        ]);
    }
}

/**
 * Returns plaintext codes (index => 4-digit string).
 * Ensures a card exists.
 */
function vr_platform_twofa_get_codes(PDO $db, int $platformUserId): array {
    $rows = $db->prepare("SELECT code_index, code_enc, nonce, tag FROM platform_2fa_cards WHERE platform_user_id=? ORDER BY code_index ASC");
    $rows->execute([$platformUserId]);
    $out = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $idx = (int)($r['code_index'] ?? 0);
        if ($idx < 1 || $idx > 16) continue;
        $code = vr_crypto_decrypt($r['code_enc'] ?? null, $r['nonce'] ?? null, $r['tag'] ?? null);
        if (preg_match('/^\d{4}$/', $code)) {
            $out[$idx] = $code;
        }
    }
    if (count($out) === 16) return $out;
    // Create fresh codes if missing/corrupt.
    $codes = vr_platform_twofa_generate_codes();
    vr_platform_twofa_store_codes($db, $platformUserId, $codes);
    return $codes;
}

/**
 * Challenge example:
 *  - code 1: positions 3,4
 *  - code 8: positions 1,2
 */
function vr_platform_twofa_make_challenge(): array {
    $i1 = random_int(1, 16);
    do { $i2 = random_int(1, 16); } while ($i2 === $i1);
    $pairs = [[1,2],[2,3],[3,4]];
    $p1 = $pairs[random_int(0, count($pairs)-1)];
    $p2 = $pairs[random_int(0, count($pairs)-1)];
    return [
        ['code_index' => $i1, 'positions' => $p1],
        ['code_index' => $i2, 'positions' => $p2],
    ];
}

function vr_platform_twofa_expected_digits(array $codes, array $challenge): array {
    $expected = [];
    foreach ($challenge as $block) {
        $idx = (int)($block['code_index'] ?? 0);
        $pos = $block['positions'] ?? [];
        $code = (string)($codes[$idx] ?? '');
        if (!preg_match('/^\d{4}$/', $code)) {
            $expected[] = '';
            $expected[] = '';
            continue;
        }
        $p1 = (int)($pos[0] ?? 0);
        $p2 = (int)($pos[1] ?? 0);
        $expected[] = ($p1 >= 1 && $p1 <= 4) ? substr($code, $p1-1, 1) : '';
        $expected[] = ($p2 >= 1 && $p2 <= 4) ? substr($code, $p2-1, 1) : '';
    }
    return $expected;
}

function vetroom_platform_twofa_pending(): ?array {
    $p = $_SESSION['vetroom_platform_2fa_pending'] ?? null;
    if (!is_array($p)) return null;
    // Expire after 10 minutes
    $created = (int)($p['created_ts'] ?? 0);
    if ($created > 0 && (time() - $created) > 600) {
        unset($_SESSION['vetroom_platform_2fa_pending']);
        return null;
    }
    return $p;
}

function vetroom_platform_twofa_clear_pending(): void {
    unset($_SESSION['vetroom_platform_2fa_pending']);
}

function vetroom_platform_twofa_start_pending(array $u, ?array $newCodes = null): void {
    $challenge = vr_platform_twofa_make_challenge();
    $_SESSION['vetroom_platform_2fa_pending'] = [
        'created_ts' => time(),
        'user' => [
            'id' => (int)($u['id'] ?? 0),
            'email' => (string)($u['email'] ?? ''),
            'name' => (string)($u['name'] ?? ''),
            'role' => (string)($u['role'] ?? ''),
        ],
        'challenge' => $challenge,
        'tries' => 0,
        'new_codes' => (is_array($newCodes) && $newCodes) ? $newCodes : null,
    ];
}

function vetroom_platform_twofa_verify_and_login(string $d1, string $d2, string $d3, string $d4) {
    $pending = vetroom_platform_twofa_pending();
    if (!$pending) return 'Sessione 2FA scaduta. Rifai il login.';

    $u = $pending['user'] ?? [];
    $userId = (int)($u['id'] ?? 0);
    if ($userId <= 0) return 'Sessione 2FA non valida. Rifai il login.';

    $db = vetroom_db();
    $email = (string)($u['email'] ?? '');
    $ip = vr_auth_client_ip();
    if ($ip !== '' && vr_auth_rate_limited($db, $email, $ip, 8, 900)) {
        vetroom_platform_twofa_clear_pending();
        return 'Troppi tentativi. Riprova tra qualche minuto.';
    }

    $challenge = $pending['challenge'] ?? [];
    $codes = vr_platform_twofa_get_codes($db, $userId);
    $expected = vr_platform_twofa_expected_digits($codes, is_array($challenge) ? $challenge : []);

    $in = [trim($d1), trim($d2), trim($d3), trim($d4)];
    foreach ($in as $k => $v) {
        if (!preg_match('/^\d$/', $v)) $in[$k] = '';
    }
    $ok = true;
    for ($i = 0; $i < 4; $i++) {
        if (($expected[$i] ?? '') === '' || $in[$i] === '' || !hash_equals((string)$expected[$i], (string)$in[$i])) {
            $ok = false;
            break;
        }
    }

    if (!$ok) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        $_SESSION['vetroom_platform_2fa_pending']['tries'] = (int)($_SESSION['vetroom_platform_2fa_pending']['tries'] ?? 0) + 1;
        if ((int)($_SESSION['vetroom_platform_2fa_pending']['tries'] ?? 0) >= 5) {
            vetroom_platform_twofa_clear_pending();
            return 'Troppi tentativi 2FA. Rifai il login.';
        }
        return 'Codice 2FA non valido.';
    }

    // Success: finalize login
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }
    unset($_SESSION['vetroom_user']);
    $_SESSION['vetroom_platform_user'] = [
        'id' => $userId,
        'email' => (string)($u['email'] ?? ''),
        'name' => (string)($u['name'] ?? ''),
        'role' => (string)($u['role'] ?? ''),
        'type' => 'PLATFORM',
    ];
    vetroom_touch_session('PLATFORM', $userId, null);
    vr_auth_record_login_attempt($db, $email, $ip, 1);
    vetroom_platform_twofa_clear_pending();
    return true;
}

function vr_auth_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function vr_auth_record_login_attempt(PDO $db, string $email, string $ip, int $success): void {
    try {
        $stmt = $db->prepare("INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([strtolower(trim($email)), $ip, (int)$success, date('c')]);
    } catch (Throwable $t) {
        // ignore
    }
}

function vr_auth_rate_limited(PDO $db, string $email, string $ip, int $maxFails = 10, int $windowSeconds = 900): bool {
    try {
        $stmt = $db->prepare("SELECT created_at, success FROM login_attempts WHERE (email=? OR ip=?) ORDER BY id DESC LIMIT 80");
        $stmt->execute([strtolower(trim($email)), $ip]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cut = time() - $windowSeconds;
        $fails = 0;
        foreach ($rows as $r) {
            if ((int)($r['success'] ?? 0) !== 0) continue;
            $ts = strtotime((string)($r['created_at'] ?? ''));
            if ($ts !== false && $ts >= $cut) {
                $fails++;
                if ($fails >= $maxFails) return true;
            }
        }
    } catch (Throwable $t) {
        // ignore
    }
    return false;
}

/**
 * ==========================
 *  AUTH - PLATFORM (ADMIN)
 * ==========================
 */

function vetroom_platform_current_user(): ?array {
    return $_SESSION['vetroom_platform_user'] ?? null;
}

function vetroom_login_platform(string $email, string $password) {
    $email = trim(strtolower($email));
    if ($email === '' || $password === '') return 'Inserisci email e password.';

    $db = vetroom_db();
    $ip = vr_auth_client_ip();
    if ($ip !== '' && vr_auth_rate_limited($db, $email, $ip, 8, 900)) {
        return 'Troppi tentativi di login. Riprova tra qualche minuto.';
    }
    $stmt = $db->prepare("SELECT * FROM platform_users WHERE lower(email)=? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Credenziali non valide.';
    }
    if (!password_verify($password, $u['password_hash'])) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Credenziali non valide.';
    }

    // If 2FA is enabled for this platform user, start the pending flow.
    // NOTE: we do NOT finalize the session here.
    if ((int)($u['twofa_enabled'] ?? 1) === 1) {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) return 'Credenziali non valide.';

        // Ensure the code card exists. If missing, generate a new one and show it ONCE.
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM platform_2fa_cards WHERE platform_user_id=?");
        $cntStmt->execute([$uid]);
        $cnt = (int)($cntStmt->fetchColumn() ?: 0);
        if ($cnt < 16) {
            $codes = vr_platform_twofa_generate_codes();
            vr_platform_twofa_store_codes($db, $uid, $codes);
            vetroom_platform_twofa_start_pending($u, $codes);
        } else {
            vetroom_platform_twofa_start_pending($u, null);
        }
        return '2FA_REQUIRED';
    }

    // Security: rotate session id on login
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    // Keep environments separated: if there is a staff session in the same cookie, drop it.
    unset($_SESSION['vetroom_user']);

    $_SESSION['vetroom_platform_user'] = [
        'id' => (int)$u['id'],
        'email' => (string)$u['email'],
        'name' => (string)$u['name'],
        'role' => (string)$u['role'],
        'type' => 'PLATFORM',
    ];

    vetroom_touch_session('PLATFORM', (int)$u['id'], null);

    vr_auth_record_login_attempt($db, $email, $ip, 1);

    return true;
}

function vetroom_logout_platform(): void {
    if (isset($_SESSION['vetroom_platform_user'])) {
        $sid = session_id();
        $db = vetroom_db();
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE session_id=?");
        $stmt->execute([$sid]);
    }
    unset($_SESSION['vetroom_platform_user']);
    vetroom_platform_twofa_clear_pending();
}

function vetroom_require_platform_login(): void {
    if (!vetroom_platform_current_user()) {
        header('Location: platform.php?login=1');
        exit;
    }
}

/**
 * ==========================
 *  AUTH - STAFF (VETROOM)
 * ==========================
 */

/**
 * Utente loggato (staff) oppure null.
 */
function vetroom_current_user(): ?array {
    $u = $_SESSION['vetroom_user'] ?? null;
    if (!$u) return null;

    // Defensive: keep the session bound to an ACTIVE clinic membership.
    // This is required for secretary multi-clinic, where users can switch clinic context.
    try {
        $db = vetroom_db();
        $uid = (int)($u['id'] ?? 0);
        $cid = (int)($u['clinic_id'] ?? 0);
        if ($uid <= 0) return null;

        // Refresh user core fields
        $stU = $db->prepare("SELECT id,name,email,is_active,role,COALESCE(max_clinics,1) AS max_clinics FROM users WHERE id=? LIMIT 1");
        $stU->execute([$uid]);
        $ur = $stU->fetch(PDO::FETCH_ASSOC);
        if (!$ur || (int)($ur['is_active'] ?? 0) !== 1) {
            vetroom_logout();
            return null;
        }

        // Ensure we still have an active membership for the current clinic.
        $st = $db->prepare("SELECT uc.role, uc.is_active AS mem_active, c.is_active AS clinic_active, c.status AS clinic_status
                            FROM user_clinic uc
                            JOIN clinics c ON c.id = uc.clinic_id
                            WHERE uc.user_id=? AND uc.clinic_id=?
                            LIMIT 1");
        $st->execute([$uid, $cid]);
        $mem = $st->fetch(PDO::FETCH_ASSOC);

        $ok = false;
        $memRole = null;
        if ($mem) {
            $memActive = (int)($mem['mem_active'] ?? 0);
            $cActive   = (int)($mem['clinic_active'] ?? 0);
            $cStatus   = strtoupper((string)($mem['clinic_status'] ?? ''));
            $memRole   = (string)($mem['role'] ?? '');
            if ($memActive === 1 && $cActive === 1 && $cStatus !== 'SUSPENDED' && $cStatus !== 'REVOKED' && $cStatus !== 'PENDING') {
                $ok = true;
            }
        }

        // If not ok, try to switch to another active membership instead of logging out.
        if (!$ok) {
            $st2 = $db->prepare("SELECT uc.clinic_id, uc.role, c.is_active AS clinic_active, c.status AS clinic_status
                                 FROM user_clinic uc
                                 JOIN clinics c ON c.id = uc.clinic_id
                                 WHERE uc.user_id=? AND uc.is_active=1
                                 ORDER BY uc.id ASC");
            $st2->execute([$uid]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            $picked = null;
            foreach ($rows as $r) {
                if ((int)($r['clinic_active'] ?? 0) !== 1) continue;
                $cst = strtoupper((string)($r['clinic_status'] ?? ''));
                if ($cst === 'SUSPENDED' || $cst === 'REVOKED' || $cst === 'PENDING') continue;
                $picked = $r;
                break;
            }
            if (!$picked) {
                // No active memberships. Allow unassigned login for SECRETARY/STAFF.
                $roleDbUp = strtoupper((string)($ur['role'] ?? ($_SESSION['vetroom_user']['role'] ?? '')));
                if (in_array($roleDbUp, ['SECRETARY','STAFF'], true)) {
                    $_SESSION['vetroom_user']['clinic_id'] = 0;
                    $_SESSION['vetroom_user']['role'] = (string)($ur['role'] ?? ($_SESSION['vetroom_user']['role'] ?? 'SECRETARY'));
                    $_SESSION['vetroom_user']['role_label'] = vr_staff_role_label($roleDbUp);
                    vetroom_touch_session('STAFF', $uid, null);
                    return $_SESSION['vetroom_user'];
                }

                vetroom_logout();
                return null;
            }

            $cid = (int)$picked['clinic_id'];
            $memRole = (string)($picked['role'] ?? '');
            $_SESSION['vetroom_user']['clinic_id'] = $cid;
        }

        // Refresh session fields (name/email/role label) without changing identity.
        $_SESSION['vetroom_user']['name'] = (string)($ur['name'] ?? ($_SESSION['vetroom_user']['name'] ?? ''));
        $_SESSION['vetroom_user']['email'] = (string)($ur['email'] ?? ($_SESSION['vetroom_user']['email'] ?? ''));
        $_SESSION['vetroom_user']['max_clinics'] = (int)($ur['max_clinics'] ?? 1);

        // Keep role in sync with DB (unassigned secretaries have no membership role).
        if (!empty($ur['role'])) {
            $roleDbUp = strtoupper((string)$ur['role']);
            $_SESSION['vetroom_user']['role'] = (string)$ur['role'];
            $_SESSION['vetroom_user']['role_label'] = vr_staff_role_label($roleDbUp);
        }

        if ($memRole !== null && $memRole !== '') {
            $roleUp = strtoupper($memRole);
            $_SESSION['vetroom_user']['role'] = $memRole;
            $_SESSION['vetroom_user']['role_label'] = vr_staff_role_label($roleUp);
        }

        vetroom_touch_session('STAFF', $uid, ((int)($_SESSION['vetroom_user']['clinic_id'] ?? 0)) > 0 ? (int)$_SESSION['vetroom_user']['clinic_id'] : null);
        return $_SESSION['vetroom_user'];
    } catch (Throwable $t) {
        // If DB is unavailable, fail closed.
        vetroom_logout();
        return null;
    }
}

/**
 * Human label for staff roles.
 */
function vr_staff_role_label(string $roleUp): string {
    return match($roleUp) {
        'CHIEF' => 'Chief',
        'ADMIN' => 'Chief', // legacy
        'VET' => 'Veterinario',
        'STAFF' => 'Segreteria',
        'SECRETARY' => 'Segreteria',
        'READONLY' => 'Solo lettura',
        default => 'Segreteria',
    };
}

/**
 * Ritorna clinic_id corrente.
 */
function vetroom_current_clinic_id(): ?int {
    $u = vetroom_current_user();
    if (!$u) return null;
    return (int)$u['clinic_id'];
}

/**
 * Login per utenti di studio (ADMIN / VET / STAFF / READONLY).
 * L'accesso è consentito solo se:
 * - utente is_active = 1
 * - clinica is_active = 1
 */
function vetroom_login_staff(string $email, string $password) {
    $email = trim(strtolower($email));
    if ($email === '' || $password === '') {
        return 'Inserisci email e password.';
    }

    $db = vetroom_db();
    $ip = vr_auth_client_ip();
    if ($ip !== '' && vr_auth_rate_limited($db, $email, $ip, 10, 900)) {
        return 'Troppi tentativi di login. Riprova tra qualche minuto.';
    }
    $stmt = $db->prepare("SELECT * FROM users WHERE lower(email)=? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Credenziali non valide.';
    }

    if (!password_verify($password, $user['password_hash'])) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Credenziali non valide.';
    }

    $isActive = (int)($user['is_active'] ?? 1);
    if ($isActive !== 1) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Account non attivo. Attendi la validazione.';
    }

    $uid = (int)$user['id'];

    // Ensure a base membership exists for legacy installs (idempotent).
    // NOTE: PLATFORM can create SECRETARY accounts before any clinic affiliation.
    // Those accounts must NOT be auto-attached to the SYSTEM placeholder clinic.
    try {
        $cnt = (int)($db->query("SELECT COUNT(1) FROM user_clinic WHERE user_id=".$uid)->fetchColumn() ?: 0);
        if ($cnt === 0) {
            $ts = date('c');
            $role0 = strtoupper((string)($user['role'] ?? ''));
            $sysClinicId = vetroom_system_clinic_id($db);
            $isSystemUser = ($sysClinicId > 0 && (int)($user['clinic_id'] ?? 0) === $sysClinicId);

            if (!(in_array($role0, ['SECRETARY','STAFF'], true) && $isSystemUser)) {
                $db->prepare("INSERT OR IGNORE INTO user_clinic (user_id,clinic_id,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$uid, (int)$user['clinic_id'], (string)$user['role'], (int)($user['is_active'] ?? 1), $ts, $ts]);
            }
        }
    } catch (Throwable $t) {
        // ignore
    }

    // Pick the first ACTIVE clinic membership.
    $mstmt = $db->prepare("SELECT uc.clinic_id, uc.role, uc.is_active AS mem_active,
                                  c.is_active AS clinic_is_active, c.status AS clinic_status
                           FROM user_clinic uc
                           JOIN clinics c ON c.id = uc.clinic_id
                           WHERE uc.user_id=? AND uc.is_active=1
                           ORDER BY uc.id ASC");
    $mstmt->execute([$uid]);
    $memberships = $mstmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$memberships) {
        $role0 = strtoupper((string)($user['role'] ?? ''));
        if (in_array($role0, ['SECRETARY','STAFF'], true)) {
            // Allow login without an active clinic. The user will be able to
            // generate membership QR/links and wait for CHIEF activation.
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_regenerate_id(true);
            }
            unset($_SESSION['vetroom_platform_user']);

            $_SESSION['vetroom_user'] = [
                'id'          => (int)$user['id'],
                'name'        => (string)($user['name'] ?? ''),
                'email'       => (string)($user['email'] ?? ''),
                'type'        => 'STAFF',
                'clinic_id'   => 0,
                'role'        => (string)($user['role'] ?? 'SECRETARY'),
                'role_label'  => vr_staff_role_label($role0),
                'max_clinics' => (int)($user['max_clinics'] ?? 1),
                'unassigned'  => 1,
            ];

            vetroom_touch_session('STAFF', (int)$user['id'], null);
            vr_auth_record_login_attempt($db, $email, $ip, 1);
            return true;
        }

        vr_auth_record_login_attempt($db, $email, $ip, 0);
        return 'Nessuna clinica associata. Contatta ADMIN.';
    }

    $picked = null;
    $firstBlockedStatus = '';
    foreach ($memberships as $m) {
        $cActive = (int)($m['clinic_is_active'] ?? 0);
        $cst = strtoupper((string)($m['clinic_status'] ?? ''));
        if ($cActive !== 1) {
            if ($firstBlockedStatus === '') $firstBlockedStatus = $cst;
            continue;
        }
        if ($cst === 'SUSPENDED' || $cst === 'REVOKED' || $cst === 'PENDING') {
            if ($firstBlockedStatus === '') $firstBlockedStatus = $cst;
            continue;
        }
        $picked = $m;
        break;
    }
    if (!$picked) {
        vr_auth_record_login_attempt($db, $email, $ip, 0);
        if ($firstBlockedStatus === 'SUSPENDED') return 'La tua struttura è sospesa. Contatta l\'assistenza.';
        if ($firstBlockedStatus === 'REVOKED') return 'La tua struttura è stata revocata. Contatta l\'assistenza.';
        return 'La tua struttura non è ancora abilitata. Attendi la validazione.';
    }

    $clinicId = (int)$picked['clinic_id'];
    $role = (string)($picked['role'] ?? $user['role']);
    $roleUp = strtoupper($role);
    $roleLabel = vr_staff_role_label($roleUp);
    $maxClinics = (int)($user['max_clinics'] ?? 1);

    // Security: rotate session id on login
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    // Keep environments separated: if there is a platform session in the same cookie, drop it.
    unset($_SESSION['vetroom_platform_user']);

    $_SESSION['vetroom_user'] = [
        'id'         => (int)$user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'type'       => 'STAFF',
        'clinic_id'  => $clinicId,
        'role'       => $role,
        'role_label' => $roleLabel,
        // Secretary multi-clinic global quota
        'max_clinics' => $maxClinics,
    ];

    vetroom_touch_session('STAFF', (int)$user['id'], $clinicId);

    vr_auth_record_login_attempt($db, $email, $ip, 1);

    return true;
}

/**
 * Logout staff.
 */
function vetroom_logout(): void {
    if (isset($_SESSION['vetroom_user'])) {
        $sid = session_id();
        $db = vetroom_db();
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE session_id=?");
        $stmt->execute([$sid]);
    }
    unset($_SESSION['vetroom_user']);
}

/**
 * Richiede login staff.
 */
function vetroom_require_login(): void {
    if (!vetroom_current_user()) {
        header('Location: index.php');
        exit;
    }
}


/**
 * ==========================
 *  AUTH - OWNER (client portal)
 * ==========================
 *
 * Dedicated session (cookie) is configured in config.php for /app/owner/*.
 * We store the authenticated account in $_SESSION['vetroom_owner_user'].
 */

function vetroom_owner_current_user(): ?array {
    $u = $_SESSION['vetroom_owner_user'] ?? null;
    if (is_array($u) && !empty($u['id'])) {
        $acctId = (int)($u['id'] ?? 0);
        $clinicId = isset($u['clinic_id']) && $u['clinic_id'] !== null ? (int)$u['clinic_id'] : null;
        if ($acctId > 0) {
            vetroom_touch_session('OWNER', $acctId, $clinicId);
        }
        return $u;
    }
    return null;
}

/**
 * Owner login via Codice Fiscale + password.
 */
function vetroom_login_owner(string $fiscalCode, string $password) {
    $cf = strtoupper(trim($fiscalCode));
    $cf = preg_replace('/\s+/', '', $cf);
    if ($cf === '' || $password === '') {
        return 'Inserisci codice fiscale e password.';
    }
    if (!vr_cf_is_valid_strict($cf)) {
        return 'Codice fiscale non valido.';
    }

    $db = vetroom_db();
    $ip = vr_auth_client_ip();
    if ($ip !== '' && vr_auth_rate_limited($db, $cf, $ip, 10, 900)) {
        return 'Troppi tentativi di login. Riprova tra qualche minuto.';
    }

    $stmt = $db->prepare("SELECT * FROM owner_accounts WHERE fiscal_code=? LIMIT 1");
    $stmt->execute([$cf]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        vr_auth_record_login_attempt($db, $cf, $ip, 0);
        return 'Credenziali non valide.';
    }
    if (!password_verify($password, (string)($acc['password_hash'] ?? ''))) {
        vr_auth_record_login_attempt($db, $cf, $ip, 0);
        return 'Credenziali non valide.';
    }

    $accId = (int)($acc['id'] ?? 0);
    if ($accId <= 0) {
        vr_auth_record_login_attempt($db, $cf, $ip, 0);
        return 'Credenziali non valide.';
    }

    // Find the linked owner profile (anagrafica) if present.
    $stmtO = $db->prepare("SELECT o.id, o.clinic_id, c.name AS clinic_name FROM owners o JOIN clinics c ON c.id=o.clinic_id WHERE o.owner_account_id=? ORDER BY o.updated_at DESC, o.id DESC LIMIT 1");
    $stmtO->execute([$accId]);
    $o = $stmtO->fetch(PDO::FETCH_ASSOC);
    $ownerId = $o ? (int)($o['id'] ?? 0) : 0;
    $clinicId = $o ? (int)($o['clinic_id'] ?? 0) : 0;
    $clinicName = $o ? (string)($o['clinic_name'] ?? '') : '';

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    // Best-effort separation: if this session was previously used for other contexts, drop those keys.
    unset($_SESSION['vetroom_user']);
    unset($_SESSION['vetroom_platform_user']);

    $_SESSION['vetroom_owner_user'] = [
        'id' => $accId,
        'fiscal_code' => $cf,
        'email' => (string)($acc['email'] ?? ''),
        'type' => 'OWNER',
        'owner_id' => $ownerId > 0 ? $ownerId : null,
        'clinic_id' => $clinicId > 0 ? $clinicId : null,
        'clinic_name' => $clinicName,
    ];

    vetroom_touch_session('OWNER', $accId, $clinicId > 0 ? $clinicId : null);
    vr_auth_record_login_attempt($db, $cf, $ip, 1);

    // Update last seen timestamp in account (optional, no schema dependency besides updated_at)
    try {
        $db->prepare("UPDATE owner_accounts SET updated_at=? WHERE id=?")->execute([date('c'), $accId]);
    } catch (Throwable $t) { /* ignore */ }

    return true;
}

function vetroom_logout_owner(): void {
    if (isset($_SESSION['vetroom_owner_user'])) {
        $sid = session_id();
        $db = vetroom_db();
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE session_id=?");
        $stmt->execute([$sid]);
    }
    unset($_SESSION['vetroom_owner_user']);
}

function vetroom_require_owner_login(string $loginPath = 'login.php'): void {
    if (!vetroom_owner_current_user()) {
        header('Location: ' . $loginPath);
        exit;
    }
}
