<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/auth.php';

// Nota: la piattaforma usa una sessione separata (vedi config.php).
// Anche se un utente è loggato nel gestionale, qui deve poter accedere al login admin.

$page = $_GET['page'] ?? 'dashboard';

function vr_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}
function vr_csrf_check(): void {
    $t = $_POST['csrf_token'] ?? '';
    if (!$t || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
        http_response_code(400);
        echo "CSRF token non valido.";
        exit;
    }
}

$db = vetroom_db();

if (isset($_GET['logout'])) {
    vetroom_logout_platform();
    header('Location: platform.php?login=1');
    exit;
}

// If a 2FA flow is pending, redirect to the 2FA page unless the user is already logged in.
$pending2fa = vetroom_platform_twofa_pending();
if (!vetroom_platform_current_user() && $pending2fa) {
    if (!in_array($page, ['twofa','login','forgot'], true) && !isset($_GET['login'])) {
        header('Location: platform.php?page=twofa');
        exit;
    }
}

// 2FA challenge page (before full login)
if ($page === 'twofa') {
    $err = null;
    $pending = vetroom_platform_twofa_pending();
    if (!$pending) {
        header('Location: platform.php?login=1');
        exit;
    }
    $challenge = is_array($pending['challenge'] ?? null) ? $pending['challenge'] : [];
    $newCodes = is_array($pending['new_codes'] ?? null) ? $pending['new_codes'] : null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vr_csrf_check();
        if (isset($_POST['cancel'])) {
            vetroom_platform_twofa_clear_pending();
            header('Location: platform.php?login=1');
            exit;
        }
        $res = vetroom_platform_twofa_verify_and_login(
            (string)($_POST['d1'] ?? ''),
            (string)($_POST['d2'] ?? ''),
            (string)($_POST['d3'] ?? ''),
            (string)($_POST['d4'] ?? '')
        );
        if ($res === true) {
            header('Location: platform.php');
            exit;
        }
        $err = (string)$res;
        $pending = vetroom_platform_twofa_pending();
        $challenge = is_array($pending['challenge'] ?? null) ? $pending['challenge'] : $challenge;
        $newCodes = is_array($pending['new_codes'] ?? null) ? $pending['new_codes'] : $newCodes;
    }

    ?><!doctype html>
    <html lang="it"><head>
        <meta charset="utf-8"><title>VetRoom Platform - 2FA</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="public/styles.css">
    
        <script src="public/ux.js" defer></script>
</head><body>
    <div class="vr-login-wrap"><div class="vr-login-card" style="max-width:680px;">
        <div class="vr-login-title">Verifica a 2 fattori (Admin)</div>
        <div class="vr-login-sub">Inserisci le cifre richieste dalla tua tabella codici.</div>

        <?php if ($err): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($err); ?></div><?php endif; ?>

        <?php if (is_array($newCodes) && $newCodes): ?>
            <div class="vr-alert vr-alert-info" style="margin-top:10px;">
                <b>Prima configurazione 2FA:</b> questi sono i tuoi 16 codici (4 cifre). Scaricali e conservali in un luogo sicuro.
            </div>
            <div style="margin:10px 0;">
                <a class="vr-btn" href="platform_twofa_export.php?mode=new" target="_blank" rel="noopener">Scarica tabella codici</a>
            </div>
            <div style="max-height:240px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;border-radius:10px;padding:10px;">
                <table class="vr-table" style="width:100%;background:white;">
                    <thead><tr><th>#</th><th>Codice</th></tr></thead><tbody>
                    <?php foreach ($newCodes as $i=>$c): ?>
                        <tr><td><?php echo (int)$i; ?></td><td><code><?php echo vr_h($c); ?></code></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php
            $b1 = $challenge[0] ?? null;
            $b2 = $challenge[1] ?? null;
            $c1 = (int)($b1['code_index'] ?? 0);
            $p11 = (int)($b1['positions'][0] ?? 0);
            $p12 = (int)($b1['positions'][1] ?? 0);
            $c2 = (int)($b2['code_index'] ?? 0);
            $p21 = (int)($b2['positions'][0] ?? 0);
            $p22 = (int)($b2['positions'][1] ?? 0);
        ?>

        <div class="vr-alert vr-alert-info" style="margin-top:10px;">
            Inserisci:
            <ul style="margin:6px 0 0 18px;">
                <li><?php echo vr_h($p11); ?>ª e <?php echo vr_h($p12); ?>ª cifra del codice <?php echo vr_h($c1); ?></li>
                <li><?php echo vr_h($p21); ?>ª e <?php echo vr_h($p22); ?>ª cifra del codice <?php echo vr_h($c2); ?></li>
            </ul>
        </div>

        <form method="post" style="margin-top:12px;">
            <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input class="vr-input" inputmode="numeric" pattern="\d" maxlength="1" name="d1" style="width:64px;text-align:center;font-size:18px;" required>
                <input class="vr-input" inputmode="numeric" pattern="\d" maxlength="1" name="d2" style="width:64px;text-align:center;font-size:18px;" required>
                <input class="vr-input" inputmode="numeric" pattern="\d" maxlength="1" name="d3" style="width:64px;text-align:center;font-size:18px;" required>
                <input class="vr-input" inputmode="numeric" pattern="\d" maxlength="1" name="d4" style="width:64px;text-align:center;font-size:18px;" required>
            </div>
            <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
                <button class="vr-btn" type="submit">Verifica</button>
                <button class="vr-btn vr-btn-secondary" name="cancel" value="1" type="submit">Annulla</button>
            </div>
        </form>
        <p style="margin-top:12px;opacity:.85;font-size:13px;">Se non hai più la tabella codici, usa la funzione “Recupero password”.</p>
        <p style="margin-top:8px;"><a href="platform.php?page=forgot">Recupero password</a></p>
    </div></div>
    </body></html><?php
    exit;
}

// Login
if (($page === 'login') || isset($_GET['login'])) {
    $err = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vr_csrf_check();
        $res = vetroom_login_platform($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($res === true) {
            header('Location: platform.php');
            exit;
        }
        if ($res === '2FA_REQUIRED') {
            header('Location: platform.php?page=twofa');
            exit;
        }
        $err = (string)$res;
    }
    ?><!doctype html>
    <html lang="it"><head>
        <meta charset="utf-8"><title>VetRoom Platform</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="public/styles.css">
    </head><body>
    <div class="vr-login-wrap"><div class="vr-login-card">
        <div class="vr-login-title">Piattaforma - Superuser</div>
        <?php if ($err): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($err); ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
            <label>Email</label>
            <input class="vr-input" type="email" name="email" required>
            <label>Password</label>
            <input class="vr-input" type="password" name="password" required>
            <button class="vr-btn" type="submit">Accedi</button>
        </form>
        <p style="margin-top:10px;"><a href="platform.php?page=forgot">Password dimenticata?</a></p>
        <p style="margin-top:12px;"><a href="index.php">Vai al gestionale (vet)</a></p>
    </div></div>
    </body></html><?php
    exit;
}

// Password recovery (platform)
if ($page === 'forgot') {
    $err = null;
    $ok = null;
    $step = (string)($_POST['step'] ?? '1');
    $email = trim(strtolower((string)($_POST['email'] ?? ($_GET['email'] ?? ''))));
    $question = '';

    if ($email !== '') {
        $st = $db->prepare("SELECT id, secret_question FROM platform_users WHERE lower(email)=? LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $question = (string)($row['secret_question'] ?? '');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        vr_csrf_check();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Inserisci una email valida.';
            $step = '1';
        } else {
            $st = $db->prepare("SELECT * FROM platform_users WHERE lower(email)=? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                $err = 'Account non trovato.';
                $step = '1';
            } else {
                $q = (string)($u['secret_question'] ?? '');
                $question = $q;
                if ($q === '' || empty($u['secret_answer_hash'])) {
                    $err = 'Recupero non disponibile: imposta prima la domanda segreta nella sezione Sicurezza (dopo login) o contatta l\'assistenza.';
                    $step = '1';
                } else {
                    if ($step === '2') {
                        $answer = vr_norm_secret_answer((string)($_POST['secret_answer'] ?? ''));
                        $newPass = (string)($_POST['new_password'] ?? '');
                        $newPass2 = (string)($_POST['new_password2'] ?? '');
                        if ($answer === '' || !password_verify($answer, (string)$u['secret_answer_hash'])) {
                            $err = 'Risposta segreta non valida.';
                        } elseif (strlen($newPass) < 8) {
                            $err = 'La nuova password deve avere almeno 8 caratteri.';
                        } elseif ($newPass !== $newPass2) {
                            $err = 'Le password non coincidono.';
                        } else {
                            $now = vr_now_iso();
                            $db->beginTransaction();
                            try {
                                $db->prepare("UPDATE platform_users SET password_hash=?, updated_at=? WHERE id=?")
                                   ->execute([password_hash($newPass, PASSWORD_DEFAULT), $now, (int)$u['id']]);

                                // Also regenerate 2FA codes (so the admin can recover even if they lost the old card).
                                $codes = vr_platform_twofa_generate_codes();
                                vr_platform_twofa_store_codes($db, (int)$u['id'], $codes);
                                $db->commit();
                                $_SESSION['vetroom_platform_forgot_codes'] = $codes;
                                $ok = 'Password aggiornata. Scarica la nuova tabella codici 2FA e poi accedi.';
                            } catch (Throwable $t) {
                                if ($db->inTransaction()) $db->rollBack();
                                $err = 'Errore durante il recupero: ' . $t->getMessage();
                            }
                        }
                    } else {
                        $step = '2';
                    }
                }
            }
        }
    }

    $codesAfterReset = is_array($_SESSION['vetroom_platform_forgot_codes'] ?? null) ? $_SESSION['vetroom_platform_forgot_codes'] : null;
    ?><!doctype html>
    <html lang="it"><head>
        <meta charset="utf-8"><title>VetRoom Platform - Recupero password</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="public/styles.css">
    </head><body>
    <div class="vr-login-wrap"><div class="vr-login-card" style="max-width:720px;">
        <div class="vr-login-title">Recupero password (Admin)</div>
        <?php if ($err): ?><div class="vr-alert vr-alert-error"><?php echo vr_h($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="vr-alert vr-alert-success"><?php echo vr_h($ok); ?></div><?php endif; ?>

        <?php if (is_array($codesAfterReset) && $codesAfterReset): ?>
            <div class="vr-alert vr-alert-info" style="margin-top:10px;">
                <b>Nuova tabella codici 2FA generata.</b> Scaricala e conservala.
            </div>
            <div style="margin:10px 0;">
                <a class="vr-btn" href="platform_twofa_export.php?mode=reset" target="_blank" rel="noopener">Scarica tabella codici</a>
                <a class="vr-btn vr-btn-secondary" href="platform.php?login=1">Vai al login</a>
            </div>
            <div style="max-height:240px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;border-radius:10px;padding:10px;">
                <table class="vr-table" style="width:100%;background:white;"><thead><tr><th>#</th><th>Codice</th></tr></thead><tbody>
                <?php foreach ($codesAfterReset as $i=>$c): ?>
                    <tr><td><?php echo (int)$i; ?></td><td><code><?php echo vr_h($c); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php else: ?>
            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                <input type="hidden" name="step" value="<?php echo vr_h($step); ?>">
                <label>Email</label>
                <input class="vr-input" type="email" name="email" required value="<?php echo vr_h($email); ?>">

                <?php if ($step === '2' && $question !== ''): ?>
                    <div class="vr-alert vr-alert-info" style="margin-top:10px;">Domanda segreta: <b><?php echo vr_h($question); ?></b></div>
                    <label>Risposta segreta</label>
                    <input class="vr-input" type="password" name="secret_answer" required>
                    <label>Nuova password</label>
                    <input class="vr-input" type="password" name="new_password" required>
                    <label>Ripeti nuova password</label>
                    <input class="vr-input" type="password" name="new_password2" required>
                    <button class="vr-btn" type="submit" style="margin-top:10px;">Reset password</button>
                <?php else: ?>
                    <button class="vr-btn" type="submit" style="margin-top:10px;">Continua</button>
                <?php endif; ?>
            </form>

            <p style="margin-top:12px;"><a href="platform.php?login=1">Torna al login</a></p>
        <?php endif; ?>
    </div></div>
    </body></html><?php
    exit;
}

vetroom_require_platform_login();
$admin = vetroom_platform_current_user();
vetroom_touch_session('PLATFORM', (int)$admin['id'], null);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vr_csrf_check();
    $action = $_POST['action'] ?? '';

    // --- Security (platform admin) ---
    if ($action === 'update_platform_secret') {
        $q = trim((string)($_POST['secret_question'] ?? ''));
        $a = trim((string)($_POST['secret_answer'] ?? ''));
        if ($q === '' || $a === '') {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Domanda/risposta segreta obbligatoria.'];
        } else {
            $hash = password_hash(vr_norm_secret_answer($a), PASSWORD_DEFAULT);
            $now = vr_now_iso();
            $db->prepare("UPDATE platform_users SET secret_question=?, secret_answer_hash=?, updated_at=? WHERE id=?")
               ->execute([$q, $hash, $now, (int)$admin['id']]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Domanda segreta aggiornata.'];
        }
        header('Location: platform.php?page=security');
        exit;
    }

    if ($action === 'regenerate_twofa') {
        try {
            $codes = vr_platform_twofa_generate_codes();
            vr_platform_twofa_store_codes($db, (int)$admin['id'], $codes);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Nuova tabella codici 2FA generata.'];
            $_SESSION['vetroom_platform_show_codes'] = $codes;
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore generazione codici: '.$t->getMessage()];
        }
        header('Location: platform.php?page=security');
        exit;
    }

    // --- Vet profiles / clinic operations ---
    if ($action === 'clinic_suspend' || $action === 'clinic_reactivate' || $action === 'clinic_revoke') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        if ($clinicId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Clinic non valida.'];
            header('Location: platform.php?page=profiles');
            exit;
        }
        $now = vr_now_iso();
        $active = 1;
        $status = 'ACTIVE';
        if ($action === 'clinic_suspend') { $active = 0; $status = 'SUSPENDED'; }
        if ($action === 'clinic_revoke') { $active = 0; $status = 'REVOKED'; }
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE clinics SET is_active=?, status=?, updated_at=? WHERE id=?")
               ->execute([$active, $status, $now, $clinicId]);
            $db->prepare("UPDATE users SET is_active=?, updated_at=? WHERE clinic_id=?")
               ->execute([$active, $now, $clinicId]);
            $db->prepare("UPDATE user_clinic SET is_active=?, updated_at=? WHERE clinic_id=?")
               ->execute([$active, $now, $clinicId]);
            $db->commit();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Stato clinica aggiornato: '.$status];
        } catch (Throwable $t) {
            $db->rollBack();
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=profiles');
        exit;
    }

    // --- Quota requests (CHIEF -> PLATFORM) ---
    if ($action === 'quota_request_approve' || $action === 'quota_request_deny') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Richiesta non valida.'];
            header('Location: platform.php?page=quota_requests');
            exit;
        }
        $note = trim((string)($_POST['admin_note'] ?? ''));
        try {
            $db->beginTransaction();
            $r = $db->prepare("SELECT * FROM quota_requests WHERE id=? LIMIT 1");
            $r->execute([$reqId]);
            $req = $r->fetch(PDO::FETCH_ASSOC);
            if (!$req) {
                throw new Exception('Richiesta non trovata.');
            }
            if ((string)$req['status'] !== 'NEW') {
                throw new Exception('Richiesta già processata.');
            }
            $now = vr_now_iso();
            $status = ($action === 'quota_request_approve') ? 'APPROVED' : 'DENIED';
            if ($status === 'APPROVED') {
                $clinicId = (int)($req['clinic_id'] ?? 0);
                $maxV = max(1, (int)($req['requested_max_vets'] ?? 1));
                $maxS = max(0, (int)($req['requested_max_secretaries'] ?? 0));
                $db->prepare("UPDATE clinics SET max_vets=?, max_secretaries=?, updated_at=? WHERE id=?")
                   ->execute([$maxV,$maxS,$now,$clinicId]);
            }
            $db->prepare("UPDATE quota_requests SET status=?, admin_note=?, decided_by_platform_user_id=?, decided_at=? WHERE id=?")
               ->execute([$status,$note,(int)$admin['id'],$now,$reqId]);
            $db->commit();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Richiesta aggiornata: '.$status];
        } catch (Throwable $t) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=quota_requests');
        exit;
    }

    // --- Secretaries (multi-clinic quota + memberships) ---
    if ($action === 'create_secretary_invite') {
        $email = trim((string)($_POST['email'] ?? ''));
        $expDays = (int)($_POST['expires_days'] ?? 7);
        if ($expDays < 1) $expDays = 1;
        if ($expDays > 30) $expDays = 30;
        $maxClinics = max(1, (int)($_POST['max_clinics'] ?? 1));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Email non valida.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }

        try {
            // PLATFORM invite: no clinic association at creation.
            // We store a placeholder clinic_id (SYSTEM) for FK integrity.
            $sysClinicId = vetroom_system_clinic_id($db);
            if ($sysClinicId <= 0) {
                throw new Exception('Impossibile inizializzare clinica di sistema.');
            }

            $token = bin2hex(random_bytes(20));
            $tokenHash = hash('sha256', $token);
            $now = vr_now_iso();
            $expires = (new DateTime($now, new DateTimeZone('UTC')))->modify('+' . $expDays . ' days')->format(DateTime::ATOM);

            $db->prepare("INSERT INTO staff_invitations (clinic_id, email, role, token_hash, expires_at, created_by_user_id, created_by_platform_user_id, max_clinics, created_at)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$sysClinicId, $email, 'SECRETARY', $tokenHash, $expires, null, (int)$admin['id'], $maxClinics, $now]);

            $link = vr_app_url('staff_register.php?token=' . urlencode($token));

            $subject = 'Invito VetRoom — Segretaria';
            $body = "Ciao,

" .
                    "Sei stata invitata a creare un account SEGRETARIA su VetRoom.
" .
                    "Link (valido " . $expDays . " giorni):
" . $link . "

" .
                    "Dopo la registrazione il profilo deve essere validato da ADMIN.\n" .
                    "Successivamente potrai affiliarti alle cliniche tramite QR/Link (attivazione dal CHIEF).\n

" .
                    "— VetRoom
";

            $ok = vr_mail_send($email, $subject, $body);

            if ($ok) {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Invito creato e inviato via email. Link: ' . $link];
            } else {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Invito creato ma email non inviata (config mail). Copia il link: ' . $link];
            }
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore creazione invito: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'secretary_invite_revoke') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        if ($inviteId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Invito non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $st = $db->prepare("SELECT id, email, role, used_at, revoked_at, created_by_platform_user_id FROM staff_invitations WHERE id=? LIMIT 1");
            $st->execute([$inviteId]);
            $iv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$iv) throw new Exception('Invito non trovato.');
            if (empty($iv['created_by_platform_user_id'])) throw new Exception('Puoi revocare solo inviti generati da ADMIN.');
            if (!empty($iv['used_at'])) throw new Exception('Invito già utilizzato.');
            if (!empty($iv['revoked_at'])) throw new Exception('Invito già revocato.');

            $role = strtoupper((string)($iv['role'] ?? ''));
            if (!in_array($role, ['SECRETARY','STAFF'], true)) throw new Exception('Invito non è per segretaria.');

            $now = vr_now_iso();
            $db->prepare("UPDATE staff_invitations SET revoked_at=? WHERE id=?")->execute([$now, $inviteId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Invito revocato.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'secretary_invite_resend') {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        if ($inviteId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Invito non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $st = $db->prepare("SELECT * FROM staff_invitations WHERE id=? LIMIT 1");
            $st->execute([$inviteId]);
            $iv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$iv) throw new Exception('Invito non trovato.');
            if (empty($iv['created_by_platform_user_id'])) throw new Exception('Puoi reinviare solo inviti generati da ADMIN.');
            if (!empty($iv['used_at'])) throw new Exception('Invito già utilizzato.');
            if (!empty($iv['revoked_at'])) throw new Exception('Invito revocato (crea un nuovo invito).');

            $role = strtoupper((string)($iv['role'] ?? ''));
            if (!in_array($role, ['SECRETARY','STAFF'], true)) throw new Exception('Invito non è per segretaria.');

            // Preserve the original duration (best-effort) or fallback to 7 days.
            $days = 7;
            try {
                $createdTs = strtotime((string)($iv['created_at'] ?? ''));
                $expTs = strtotime((string)($iv['expires_at'] ?? ''));
                if ($createdTs && $expTs && $expTs > $createdTs) {
                    $days = (int)ceil(($expTs - $createdTs) / 86400);
                }
            } catch (Throwable $t2) {
                // ignore
            }
            if ($days < 1) $days = 1;
            if ($days > 30) $days = 30;

            $token = bin2hex(random_bytes(20));
            $tokenHash = hash('sha256', $token);
            $now = vr_now_iso();
            $expires = (new DateTime($now, new DateTimeZone('UTC')))->modify('+' . $days . ' days')->format(DateTime::ATOM);

            $db->prepare("UPDATE staff_invitations SET token_hash=?, expires_at=? WHERE id=?")
               ->execute([$tokenHash, $expires, $inviteId]);

            $email = (string)($iv['email'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Email invito non valida.');

            $link = vr_app_url('staff_register.php?token=' . urlencode($token));
            $subject = 'Invito VetRoom — Segretaria (link aggiornato)';
            $body = "Ciao,\n\n".
                    "Ecco il link aggiornato per creare il tuo account SEGRETARIA su VetRoom (valido ".$days." giorni):\n".
                    $link."\n\n".
                    "Dopo la registrazione il profilo deve essere validato da ADMIN.\n\n— VetRoom\n";

            $ok = vr_mail_send($email, $subject, $body);
            if ($ok) {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Link rigenerato e reinviato. Link: '.$link];
            } else {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Link rigenerato ma email non inviata (config mail). Copia il link: '.$link];
            }
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'secretary_update_max_clinics') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newMax = max(1, (int)($_POST['max_clinics'] ?? 1));
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Utente non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $u = $db->prepare("SELECT id, COALESCE(max_clinics,1) AS max_clinics, role FROM users WHERE id=? LIMIT 1");
            $u->execute([$userId]);
            $usr = $u->fetch(PDO::FETCH_ASSOC);
            if (!$usr) throw new Exception('Utente non trovato.');
            $role = strtoupper((string)($usr['role'] ?? ''));
            if ($role !== 'SECRETARY' && $role !== 'STAFF') throw new Exception('Utente non è una segretaria.');

            $active = (int)($db->query("SELECT COUNT(1)
                                FROM user_clinic uc
                                JOIN clinics c ON c.id=uc.clinic_id
                                WHERE uc.user_id=".(int)$userId." AND uc.is_active=1
                                  AND UPPER(uc.role) IN ('SECRETARY','STAFF')
                                  AND c.is_active=1 AND UPPER(c.status)='ACTIVE'")->fetchColumn() ?: 0);
            if ($newMax < $active) {
                throw new Exception('Non puoi impostare max_clinics < delle cliniche attive (' . $active . '). Revoca prima una membership.');
            }

            $now = vr_now_iso();
            $db->prepare("UPDATE users SET max_clinics=?, updated_at=? WHERE id=?")
               ->execute([$newMax, $now, $userId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'max_clinics aggiornato a '.$newMax];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'secretary_validate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Utente non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $u = $db->prepare("SELECT id, role, is_active, validated_at FROM users WHERE id=? LIMIT 1");
            $u->execute([$userId]);
            $usr = $u->fetch(PDO::FETCH_ASSOC);
            if (!$usr) throw new Exception('Utente non trovato.');
            $role = strtoupper((string)($usr['role'] ?? ''));
            if (!in_array($role, ['SECRETARY','STAFF'], true)) throw new Exception('Utente non è una segretaria.');

            $now = vr_now_iso();
            // Validate + activate
            $db->prepare("UPDATE users
                          SET is_active=1,
                              validated_at=COALESCE(validated_at, ?),
                              validated_by_platform_user_id=?,
                              updated_at=?
                          WHERE id=?")
               ->execute([$now, (int)$admin['id'], $now, $userId]);

            $_SESSION['flash'] = ['type'=>'success','msg'=>'Profilo segretaria validato e attivato.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        $redir = (string)($_POST['redirect'] ?? 'platform.php?page=secretaries');
        if (!str_starts_with($redir, 'platform.php')) $redir = 'platform.php?page=secretaries';
        header('Location: '.$redir);
        exit;
    }

    if ($action === 'secretary_suspend' || $action === 'secretary_reactivate') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Utente non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        $active = ($action === 'secretary_reactivate') ? 1 : 0;
        $now = vr_now_iso();
        try {
            if ($active === 1) {
                // Reactivating implies the profile is valid for login.
                $db->prepare("UPDATE users
                              SET is_active=1,
                                  validated_at=COALESCE(validated_at, ?),
                                  validated_by_platform_user_id=COALESCE(validated_by_platform_user_id, ?),
                                  updated_at=?
                              WHERE id=?")
                   ->execute([$now, (int)$admin['id'], $now, $userId]);
            } else {
                $db->prepare("UPDATE users SET is_active=0, updated_at=? WHERE id=?")
                   ->execute([$now, $userId]);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Stato account aggiornato.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'secretary_revoke_membership') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        if ($userId <= 0 || $clinicId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Parametri non validi.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $now = vr_now_iso();
            $db->prepare("UPDATE user_clinic SET is_active=0, updated_at=? WHERE user_id=? AND clinic_id=?")
               ->execute([$now, $userId, $clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Membership revocata.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretary_view&id=' . $userId);
        exit;
    }

    // Global membership management (overview page)
    if (in_array($action, ['secretary_membership_suspend','secretary_membership_reactivate','secretary_membership_delete'], true)) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        if ($userId <= 0 || $clinicId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Parametri non validi.'];
            header('Location: platform.php?page=secretary_memberships');
            exit;
        }
        try {
            $now = vr_now_iso();
            if ($action === 'secretary_membership_delete') {
                $db->prepare("DELETE FROM user_clinic WHERE user_id=? AND clinic_id=? AND UPPER(role) IN ('SECRETARY','STAFF')")
                   ->execute([$userId, $clinicId]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Membership rimossa.'];
            } else {
                $active = ($action === 'secretary_membership_reactivate') ? 1 : 0;
                $db->prepare("UPDATE user_clinic SET is_active=?, updated_at=? WHERE user_id=? AND clinic_id=? AND UPPER(role) IN ('SECRETARY','STAFF')")
                   ->execute([$active, $now, $userId, $clinicId]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>($active? 'Membership riattivata.' : 'Membership sospesa.')];
            }
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretary_memberships');
        exit;
    }

    if ($action === 'secretary_token_delete') {
        $tokId = (int)($_POST['token_id'] ?? 0);
        if ($tokId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Token non valido.'];
            header('Location: platform.php?page=secretary_memberships');
            exit;
        }
        try {
            $db->prepare("DELETE FROM secretary_membership_tokens WHERE id=?")
               ->execute([$tokId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Token rimosso.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretary_memberships');
        exit;
    }

    if ($action === 'secretary_close') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Utente non valido.'];
            header('Location: platform.php?page=secretaries');
            exit;
        }
        try {
            $db->beginTransaction();
            $now = vr_now_iso();
            $db->prepare("UPDATE users SET is_active=0, updated_at=? WHERE id=?")
               ->execute([$now, $userId]);
            $db->prepare("UPDATE user_clinic SET is_active=0, updated_at=? WHERE user_id=?")
               ->execute([$now, $userId]);
            $db->commit();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Account segretaria chiuso (tutte le memberships revocate).'];
        } catch (Throwable $t) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
        }
        header('Location: platform.php?page=secretaries');
        exit;
    }

    if ($action === 'clinic_delete') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        if ($clinicId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Clinic non valida.'];
            header('Location: platform.php?page=profiles');
            exit;
        }

        // Copy owners/pets to Admin DB (so they survive the deletion)
        $now = vr_now_iso();
        try {
            $db->beginTransaction();

            // Owners
            $own = $db->prepare("SELECT * FROM owners WHERE clinic_id=?");
            $own->execute([$clinicId]);
            $owners = $own->fetchAll(PDO::FETCH_ASSOC);
            foreach ($owners as $o) {
                $db->prepare("INSERT INTO admin_owners (source_clinic_id, source_owner_id, name, surname, birth_date, fiscal_code, email, phone, address_street, address_number, address_city, address_province, address_state, address_zip, billing_is_different, billing_street, billing_number, billing_city, billing_province, billing_state, billing_zip, created_at, updated_at, synced_at, source_deleted, source_deleted_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                              ON CONFLICT(source_clinic_id, source_owner_id) DO UPDATE SET
                                name=excluded.name, surname=excluded.surname, birth_date=excluded.birth_date, fiscal_code=excluded.fiscal_code, email=excluded.email, phone=excluded.phone,
                                address_street=excluded.address_street, address_number=excluded.address_number, address_city=excluded.address_city, address_province=excluded.address_province, address_state=excluded.address_state, address_zip=excluded.address_zip,
                                billing_is_different=excluded.billing_is_different, billing_street=excluded.billing_street, billing_number=excluded.billing_number, billing_city=excluded.billing_city, billing_province=excluded.billing_province, billing_state=excluded.billing_state, billing_zip=excluded.billing_zip,
                                updated_at=excluded.updated_at, synced_at=excluded.synced_at, source_deleted=0, source_deleted_at=NULL")
                   ->execute([
                       $clinicId,
                       (int)$o['id'],
                       (string)($o['name'] ?? ''),
                       (string)($o['surname'] ?? ''),
                       $o['birth_date'] ?? null,
                       $o['fiscal_code'] ?? null,
                       $o['email'] ?? null,
                       $o['phone'] ?? null,
                       $o['address_street'] ?? null,
                       $o['address_number'] ?? null,
                       $o['address_city'] ?? null,
                       $o['address_province'] ?? null,
                       $o['address_state'] ?? null,
                       $o['address_zip'] ?? null,
                       (int)($o['billing_is_different'] ?? 0),
                       $o['billing_street'] ?? null,
                       $o['billing_number'] ?? null,
                       $o['billing_city'] ?? null,
                       $o['billing_province'] ?? null,
                       $o['billing_state'] ?? null,
                       $o['billing_zip'] ?? null,
                       $o['created_at'] ?? null,
                       $o['updated_at'] ?? null,
                       $now,
                       0,
                       null
                   ]);
            }

            // Pets
            $pet = $db->prepare("SELECT * FROM pets WHERE clinic_id=?");
            $pet->execute([$clinicId]);
            $pets = $pet->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pets as $p) {
                $db->prepare("INSERT INTO admin_pets (source_clinic_id, source_pet_id, source_owner_id, name, species, breed, birth_date, sex, neuter_status, weight_kg, microchip, notes, created_at, updated_at, synced_at, source_deleted, source_deleted_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                              ON CONFLICT(source_clinic_id, source_pet_id) DO UPDATE SET
                                source_owner_id=excluded.source_owner_id, name=excluded.name, species=excluded.species, breed=excluded.breed, birth_date=excluded.birth_date, sex=excluded.sex, neuter_status=excluded.neuter_status,
                                weight_kg=excluded.weight_kg, microchip=excluded.microchip, notes=excluded.notes, updated_at=excluded.updated_at, synced_at=excluded.synced_at, source_deleted=0, source_deleted_at=NULL")
                   ->execute([
                       $clinicId,
                       (int)$p['id'],
                       (int)($p['owner_id'] ?? 0),
                       (string)($p['name'] ?? ''),
                       $p['species'] ?? null,
                       $p['breed'] ?? null,
                       $p['birth_date'] ?? null,
                       $p['sex'] ?? null,
                       $p['neuter_status'] ?? null,
                       $p['weight_kg'] ?? null,
                       $p['microchip'] ?? null,
                       $p['notes'] ?? null,
                       $p['created_at'] ?? null,
                       $p['updated_at'] ?? null,
                       $now,
                       0,
                       null
                   ]);
            }

            // Delete files belonging to the clinic (documents + template backgrounds + header assets)
            $docs = $db->prepare("SELECT filename FROM documents WHERE clinic_id=?");
            $docs->execute([$clinicId]);
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['filename'])) vr_safe_unlink_rel((string)$r['filename']);
            }
            $tpl = $db->prepare("SELECT bg_path FROM visit_templates WHERE clinic_id=?");
            $tpl->execute([$clinicId]);
            foreach ($tpl->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['bg_path'])) vr_safe_unlink_rel((string)$r['bg_path']);
            }
            $set = $db->prepare("SELECT logo_path, stamp_path FROM vet_settings WHERE clinic_id=?");
            $set->execute([$clinicId]);
            $sRow = $set->fetch(PDO::FETCH_ASSOC);
            if ($sRow) {
                if (!empty($sRow['logo_path'])) vr_safe_unlink_rel((string)$sRow['logo_path']);
                if (!empty($sRow['stamp_path'])) vr_safe_unlink_rel((string)$sRow['stamp_path']);
            }

            // Finally delete the clinic (FK cascades will remove all clinic-specific data)
            $db->prepare("DELETE FROM clinics WHERE id=?")->execute([$clinicId]);
            $db->commit();
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Clinica eliminata (dati clinica rimossi; proprietari/pazienti preservati nel database admin).'];
        } catch (Throwable $t) {
            $db->rollBack();
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore eliminazione clinica: '.$t->getMessage()];
        }
        header('Location: platform.php?page=profiles');
        exit;
    }

    // --- Cookie data management ---
    if ($action === 'cookie_delete_consent') {
        $cid = trim((string)($_POST['consent_id'] ?? ''));
        if ($cid !== '') {
            try {
                $db->beginTransaction();
                $db->prepare("DELETE FROM cookie_events WHERE consent_id=?")->execute([$cid]);
                $db->prepare("DELETE FROM cookie_consents WHERE consent_id=?")->execute([$cid]);
                $db->commit();
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Profilo cookie eliminato.'];
            } catch (Throwable $t) {
                $db->rollBack();
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$t->getMessage()];
            }
        }
        header('Location: platform.php?page=cookies');
        exit;
    }

    // --- Profile management (files/visits/templates/appointments) ---
    if ($action === 'doc_rename') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $docId = (int)($_POST['doc_id'] ?? 0);
        $newName = trim((string)($_POST['new_name'] ?? ''));
        if ($clinicId > 0 && $docId > 0 && $newName !== '') {
            $db->prepare("UPDATE documents SET original_name=? WHERE id=? AND clinic_id=?")
               ->execute([$newName, $docId, $clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Nome file aggiornato.'];
        } else {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Dati non validi.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=docs');
        exit;
    }
    if ($action === 'doc_delete') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($clinicId > 0 && $docId > 0) {
            $st = $db->prepare("SELECT filename FROM documents WHERE id=? AND clinic_id=? LIMIT 1");
            $st->execute([$docId, $clinicId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['filename'])) vr_safe_unlink_rel((string)$row['filename']);
                $db->prepare("DELETE FROM documents WHERE id=? AND clinic_id=?")
                   ->execute([$docId, $clinicId]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'File eliminato.'];
            } else {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'File non trovato.'];
            }
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=docs');
        exit;
    }
    if ($action === 'visit_update') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $visitId = (int)($_POST['visit_id'] ?? 0);
        $diag = trim((string)($_POST['diagnosis'] ?? ''));
        $ther = trim((string)($_POST['therapy'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($clinicId > 0 && $visitId > 0) {
            $db->prepare("UPDATE visits SET diagnosis=?, therapy=?, notes=?, updated_at=? WHERE id=? AND clinic_id=?")
               ->execute([$diag,$ther,$notes,vr_now_iso(),$visitId,$clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Visita aggiornata.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=visits');
        exit;
    }
    if ($action === 'visit_delete') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $visitId = (int)($_POST['visit_id'] ?? 0);
        if ($clinicId > 0 && $visitId > 0) {
            // Delete any stored PDF file path (best effort)
            $st = $db->prepare("SELECT pdf_path FROM visits WHERE id=? AND clinic_id=? LIMIT 1");
            $st->execute([$visitId,$clinicId]);
            $pdf = (string)($st->fetchColumn() ?: '');
            if ($pdf !== '') vr_safe_unlink_rel($pdf);
            $db->prepare("DELETE FROM visits WHERE id=? AND clinic_id=?")
               ->execute([$visitId,$clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Visita eliminata.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=visits');
        exit;
    }
    if ($action === 'appt_update') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $apptId = (int)($_POST['appt_id'] ?? 0);
        if ($clinicId > 0 && $apptId > 0) {
            $at = trim((string)($_POST['appointment_at'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            $kind = trim((string)($_POST['kind'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $db->prepare("UPDATE appointments SET appointment_at=?, status=?, kind=?, notes=?, updated_at=? WHERE id=? AND clinic_id=?")
               ->execute([$at,$status,$kind,$notes,vr_now_iso(),$apptId,$clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Appuntamento aggiornato.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=appointments');
        exit;
    }
    if ($action === 'appt_delete') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $apptId = (int)($_POST['appt_id'] ?? 0);
        if ($clinicId > 0 && $apptId > 0) {
            $db->prepare("DELETE FROM appointments WHERE id=? AND clinic_id=?")
               ->execute([$apptId,$clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Appuntamento eliminato.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=appointments');
        exit;
    }
    if ($action === 'template_toggle') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($clinicId > 0 && $templateId > 0) {
            $st = $db->prepare("SELECT enabled FROM visit_templates WHERE id=? AND clinic_id=? LIMIT 1");
            $st->execute([$templateId, $clinicId]);
            $cur = (int)($st->fetchColumn() ?: 0);
            $new = $cur ? 0 : 1;
            $db->prepare("UPDATE visit_templates SET enabled=?, updated_at=? WHERE id=? AND clinic_id=?")
               ->execute([$new, vr_now_iso(), $templateId, $clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Template aggiornato.'];
        }
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=templates');
        exit;
    }

    if ($action === 'template_bg_delete') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($clinicId > 0 && $templateId > 0) {
            $st = $db->prepare("SELECT bg_path FROM visit_templates WHERE id=? AND clinic_id=? LIMIT 1");
            $st->execute([$templateId, $clinicId]);
            $bg = (string)($st->fetchColumn() ?: '');
            if ($bg !== '') vr_safe_unlink_rel($bg);
            $db->prepare("UPDATE visit_templates SET bg_path=NULL, updated_at=? WHERE id=? AND clinic_id=?")
               ->execute([vr_now_iso(), $templateId, $clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Background eliminato.'];
        }
        header('Location: platform.php?page=profile_template_edit&clinic_id='.$clinicId.'&template_id='.$templateId);
        exit;
    }

    if ($action === 'template_update') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($clinicId <= 0 || $templateId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Dati non validi.'];
            header('Location: platform.php?page=profiles');
            exit;
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $overlay = trim((string)($_POST['overlay_header'] ?? ''));
        $mapJson = trim((string)($_POST['field_map_json'] ?? ''));

        if ($mapJson !== '') {
            $tmp = json_decode($mapJson, true);
            if ($tmp === null && json_last_error() !== JSON_ERROR_NONE) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'JSON non valido nella mappa campi.'];
                header('Location: platform.php?page=profile_template_edit&clinic_id='.$clinicId.'&template_id='.$templateId);
                exit;
            }
        }

        // Current bg
        $st = $db->prepare("SELECT bg_path FROM visit_templates WHERE id=? AND clinic_id=? LIMIT 1");
        $st->execute([$templateId, $clinicId]);
        $oldBg = (string)($st->fetchColumn() ?: '');
        $newBg = $oldBg;

        // Handle upload
        if (isset($_FILES['bg_file']) && is_array($_FILES['bg_file']) && ($_FILES['bg_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string)($_FILES['bg_file']['tmp_name'] ?? '');
            $size = (int)($_FILES['bg_file']['size'] ?? 0);
            if ($size > 5 * 1024 * 1024) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'File troppo grande (max 5MB).'];
                header('Location: platform.php?page=profile_template_edit&clinic_id='.$clinicId.'&template_id='.$templateId);
                exit;
            }
            $mime = '';
            if (class_exists('finfo')) {
                $fi = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$fi->file($tmpName);
            }
            $ext = '';
            if ($mime === 'application/pdf') $ext = 'pdf';
            elseif ($mime === 'image/png') $ext = 'png';
            elseif ($mime === 'image/jpeg') $ext = 'jpg';
            elseif ($mime === 'image/webp') $ext = 'webp';
            else {
                // Fallback: extension-based check (for hosts without fileinfo)
                $origName = (string)($_FILES['bg_file']['name'] ?? '');
                $origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (in_array($origExt, ['pdf','png','jpg','jpeg','webp'], true)) {
                    $ext = $origExt === 'jpeg' ? 'jpg' : $origExt;
                }
            }
            if ($ext === '') {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Tipo file non supportato: '.vr_h($mime).'.'];
                header('Location: platform.php?page=profile_template_edit&clinic_id='.$clinicId.'&template_id='.$templateId);
                exit;
            }

            $dirRel = 'data/uploads/templates/clinic_' . $clinicId;
            $dirAbs = __DIR__ . '/' . $dirRel;
            if (!is_dir($dirAbs)) {
                @mkdir($dirAbs, 0775, true);
            }
            $fname = 'template_' . $templateId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destAbs = $dirAbs . '/' . $fname;
            if (!@move_uploaded_file($tmpName, $destAbs)) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Upload non riuscito.'];
                header('Location: platform.php?page=profile_template_edit&clinic_id='.$clinicId.'&template_id='.$templateId);
                exit;
            }
            $newBg = $dirRel . '/' . $fname;
            if ($oldBg !== '' && $oldBg !== $newBg) {
                vr_safe_unlink_rel($oldBg);
            }
        }

        $db->prepare("UPDATE visit_templates SET name=?, overlay_header=?, field_map_json=?, bg_path=?, updated_at=? WHERE id=? AND clinic_id=?")
           ->execute([$name,$overlay,$mapJson,$newBg,vr_now_iso(),$templateId,$clinicId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Template salvato.'];
        header('Location: platform.php?page=profile_view&clinic_id='.$clinicId.'&tab=templates');
        exit;
    }
    if ($action === 'create_invite') {
        $email = trim(strtolower($_POST['invite_email'] ?? ''));
        $days = (int)($_POST['invite_days'] ?? 2);
        $maxVets = max(1, (int)($_POST['invite_max_vets'] ?? 1));
        $maxSecretaries = max(0, (int)($_POST['invite_max_secretaries'] ?? 0));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Email non valida.'];
        } else {
            $token = bin2hex(random_bytes(24));
            $expires = (new DateTimeImmutable())->modify('+' . max(1,$days) . ' days')->format(DateTime::ATOM);
            $now = vr_now_iso();
            $stmt = $db->prepare("INSERT INTO invitations (email, token, max_vets, max_secretaries, expires_at, created_at) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$email,$token,$maxVets,$maxSecretaries,$expires,$now]);
            // Build invite link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $base = $scheme . '://' . $host . $dir;
            $link = $base . '/register.php?token=' . urlencode($token);

            // Best-effort email
            $body = "Ciao,\n\n";
            $body .= "Sei stato invitato a registrarti su VetRoom.\n";
            $body .= "Profilo struttura (impostato dall'ADMIN): max veterinari={$maxVets}, max segreteria={$maxSecretaries}.\n\n";
            $body .= "Link di registrazione (valido fino a: {$expires}):\n{$link}\n\n";
            $body .= "Se non hai richiesto tu questo invito, puoi ignorare questa email.\n";

            $sent = @vr_mail_send($email, 'Invito VetRoom', $body);

            // Mark landing lead as invited (best effort)
            try {
                $db->prepare("UPDATE leads SET status='INVITED' WHERE email=? AND status='NEW'")->execute([$email]);
            } catch (Throwable $t) {
                // ignore
            }

            if ($sent) {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Invito creato e inviato via email.'];
            } else {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Invito creato, ma invio email non riuscito. Copia il link manualmente dalla pagina Inviti.'];
            }
            $_SESSION['last_invite_token'] = $token;
        }
        header('Location: platform.php?page=invites');
        exit;
    }

    if ($action === 'update_clinic_limits') {
        $clinicId = (int)($_POST['clinic_id'] ?? 0);
        $tab = (string)($_POST['tab'] ?? '');
        $maxVets = max(1, (int)($_POST['max_vets'] ?? 1));
        $maxSecretaries = max(0, (int)($_POST['max_secretaries'] ?? 0));
        if ($clinicId <= 0) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Clinica non valida.'];
            header('Location: platform.php?page=profiles');
            exit;
        }
        try {
            $db->prepare("UPDATE clinics SET max_vets=?, max_secretaries=?, updated_at=? WHERE id=?")
               ->execute([$maxVets, $maxSecretaries, vr_now_iso(), $clinicId]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Limiti struttura aggiornati.'];
        } catch (Throwable $t) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore aggiornamento limiti: '.$t->getMessage()];
        }
        $to = 'platform.php?page=profile_view&clinic_id=' . $clinicId;
        if ($tab !== '') $to .= '&tab=' . urlencode($tab);
        header('Location: '.$to);
        exit;
    }

    if ($action === 'approve_application') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $app = $db->prepare("SELECT * FROM clinic_applications WHERE id=? LIMIT 1");
        $app->execute([$appId]);
        $row = $app->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Richiesta non trovata.'];
        } else {
            $now = vr_now_iso();
            $db->beginTransaction();
            try {
                // activate clinic + user
                $db->prepare("UPDATE clinics SET is_active=1, status='ACTIVE', updated_at=? WHERE id=?")->execute([$now,(int)$row['clinic_id']]);
                $db->prepare("UPDATE users SET is_active=1, updated_at=? WHERE id=?")->execute([$now,(int)$row['user_id']]);
                $db->prepare("UPDATE clinic_applications SET status='APPROVED', reviewed_at=?, review_notes=? WHERE id=?")->execute([$now,$notes,$appId]);
                $db->commit();
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Struttura approvata e attivata.'];
            } catch (Throwable $e) {
                $db->rollBack();
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore approvazione: '.$e->getMessage()];
            }
        }
        header('Location: platform.php?page=applications');
        exit;
    }

    if ($action === 'suspend_application' || $action === 'revoke_application' || $action === 'reactivate_application') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $app = $db->prepare("SELECT * FROM clinic_applications WHERE id=? LIMIT 1");
        $app->execute([$appId]);
        $row = $app->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Richiesta non trovata.'];
        } else {
            $now = vr_now_iso();
            $db->beginTransaction();
            try {
                $clinicId = (int)($row['clinic_id'] ?? 0);
                $userId = (int)($row['user_id'] ?? 0);
                $newAppStatus = 'APPROVED';
                $clinicStatus = 'ACTIVE';
                $active = 1;
                if ($action === 'suspend_application') {
                    $newAppStatus = 'SUSPENDED';
                    $clinicStatus = 'SUSPENDED';
                    $active = 0;
                } elseif ($action === 'revoke_application') {
                    $newAppStatus = 'REVOKED';
                    $clinicStatus = 'REVOKED';
                    $active = 0;
                } elseif ($action === 'reactivate_application') {
                    $newAppStatus = 'APPROVED';
                    $clinicStatus = 'ACTIVE';
                    $active = 1;
                }

                if ($clinicId > 0) {
                    $db->prepare("UPDATE clinics SET is_active=?, status=?, updated_at=? WHERE id=?")
                       ->execute([$active, $clinicStatus, $now, $clinicId]);
                }
                if ($userId > 0) {
                    $db->prepare("UPDATE users SET is_active=?, updated_at=? WHERE id=?")
                       ->execute([$active, $now, $userId]);
                }
                $db->prepare("UPDATE clinic_applications SET status=?, reviewed_at=?, review_notes=? WHERE id=?")
                   ->execute([$newAppStatus, $now, $notes, $appId]);

                $db->commit();

                $msg = ($action === 'suspend_application') ? 'Struttura sospesa.' : (($action === 'revoke_application') ? 'Struttura revocata.' : 'Struttura riattivata.');
                $_SESSION['flash'] = ['type'=>'success','msg'=>$msg];
            } catch (Throwable $e) {
                $db->rollBack();
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Errore: '.$e->getMessage()];
            }
        }
        header('Location: platform.php?page=applications');
        exit;
    }

    if ($action === 'reject_application') {
        $appId = (int)($_POST['app_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $now = vr_now_iso();
        $db->prepare("UPDATE clinic_applications SET status='REJECTED', reviewed_at=?, review_notes=? WHERE id=?")->execute([$now,$notes,$appId]);
        // keep clinic/user inactive
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Richiesta rifiutata.'];
        header('Location: platform.php?page=applications');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$lastToken = $_SESSION['last_invite_token'] ?? null;

function vr_platform_nav(string $active): void {
    // Deprecated: the admin area now uses a complete lateral sidebar.
    // Kept for compatibility with old links (no output).
    return;
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>VetRoom Platform</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="public/styles.css">
  <script src="public/ux.js" defer></script>
</head>
<body>
<div class="vr-admin-layout" id="vrAdminLayout">
  <aside class="vr-admin-sidebar" id="vrAdminSidebar" aria-label="Navigazione Platform">
    <div style="font-weight:800;font-size:16px;margin-bottom:12px;">VetRoom</div>
    <div style="font-size:12px;opacity:.7;margin-bottom:10px;">Platform superuser</div>

    <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 6px;">Panoramica</div>
    <a class="vr-nav-item <?php echo ($page==='dashboard') ? 'active' : ''; ?>" href="platform.php?page=dashboard">Dashboard</a>
    <a class="vr-nav-item <?php echo ($page==='sessions') ? 'active' : ''; ?>" href="platform.php?page=sessions">Sessioni attive</a>

    <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 6px;">Acquisizione</div>
    <a class="vr-nav-item <?php echo ($page==='leads') ? 'active' : ''; ?>" href="platform.php?page=leads">Richieste landing</a>
    <a class="vr-nav-item <?php echo ($page==='invites') ? 'active' : ''; ?>" href="platform.php?page=invites">Inviti</a>
    <a class="vr-nav-item <?php echo ($page==='applications' || $page==='application_view') ? 'active' : ''; ?>" href="platform.php?page=applications">Richieste cliniche</a>

    <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 6px;">Gestione</div>
    <a class="vr-nav-item <?php echo ($page==='profiles' || $page==='profile_view' || str_starts_with($page,'profile_')) ? 'active' : ''; ?>" href="platform.php?page=profiles">Profili Vet</a>
    <a class="vr-nav-item <?php echo (in_array($page,['secretaries','secretary_view'], true)) ? 'active' : ''; ?>" href="platform.php?page=secretaries">Segretarie</a>
    <a class="vr-nav-item <?php echo ($page==='secretary_memberships') ? 'active' : ''; ?>" href="platform.php?page=secretary_memberships">Membership segreteria</a>
    <a class="vr-nav-item <?php echo ($page==='quota_requests') ? 'active' : ''; ?>" href="platform.php?page=quota_requests">Richieste Quote</a>
    <a class="vr-nav-item <?php echo ($page==='admin_db') ? 'active' : ''; ?>" href="platform.php?page=admin_db&tab=owners">Database Admin</a>

    <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:.08em;margin:12px 0 6px;">Sicurezza & tracking</div>
    <a class="vr-nav-item <?php echo ($page==='security') ? 'active' : ''; ?>" href="platform.php?page=security">Sicurezza</a>
    <a class="vr-nav-item <?php echo ($page==='cookies') ? 'active' : ''; ?>" href="platform.php?page=cookies">Privacy/Cookies</a>
    <a class="vr-nav-item <?php echo ($page==='heatmap') ? 'active' : ''; ?>" href="platform.php?page=heatmap">Heatmap</a>

    <div style="height:10px;"></div>
    <a class="vr-nav-item" href="platform.php?logout=1">Logout</a>
  </aside>
  <main class="vr-admin-main">

  <!-- Mobile topbar (sidebar drawer trigger) -->
  <div class="vr-admin-topbar" role="banner">
    <button class="vr-admin-menu-btn" type="button" data-vr-admin-menu aria-label="Apri menu" aria-controls="vrAdminSidebar" aria-expanded="false">☰</button>
    <div class="vr-admin-topbar-title">
      <div class="vr-admin-topbar-brand">VetRoom Platform</div>
      <div class="vr-admin-topbar-sub">Superuser: <?php echo vr_h($admin['email']); ?></div>
    </div>
    <a class="vr-admin-topbar-logout" href="platform.php?logout=1">Esci</a>
  </div>

  <h2 class="vr-admin-page-title">VetRoom Platform</h2>
  <div class="vr-admin-page-sub">Superuser: <?php echo vr_h($admin['email']); ?></div>

  <!-- Topnav removed: use sidebar navigation -->

  <?php if ($flash): ?>
    <div class="vr-alert <?php echo $flash['type']==='error'?'vr-alert-error':'vr-alert-success'; ?>">
      <?php echo vr_h($flash['msg']); ?>
    </div>
  <?php endif; ?>

  <?php if ($page === 'dashboard'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Stato</h3>
      <?php
        $totClinics = (int)$db->query("SELECT COUNT(*) FROM clinics WHERE UPPER(status) <> 'SYSTEM'")->fetchColumn();
        $activeClinics = (int)$db->query("SELECT COUNT(*) FROM clinics WHERE is_active=1 AND UPPER(status) <> 'SYSTEM'")->fetchColumn();
        $pendingApps = (int)$db->query("SELECT COUNT(*) FROM clinic_applications WHERE status='PENDING'")->fetchColumn();
      ?>
      <ul>
        <li>Cliniche totali: <?php echo (int)$totClinics; ?></li>
        <li>Cliniche attive: <?php echo (int)$activeClinics; ?></li>
        <li>Richieste in attesa: <?php echo (int)$pendingApps; ?></li>
      </ul>
      <p>Usa <b>Inviti</b> per generare un link di onboarding. Le strutture potranno accedere al gestionale solo dopo approvazione.</p>
    </div>

  <?php elseif ($page === 'sessions'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Sessioni attive</h3>
      <?php
        $rows = $db->query("SELECT * FROM active_sessions ORDER BY last_seen DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "<p>Nessuna sessione attiva.</p>"; }
        else {
            echo "<div style='overflow:auto;'><table class='vr-table' style='width:100%'><thead><tr>
                <th>Tipo</th><th>User</th><th>Clinica</th><th>IP</th><th>Ultimo accesso</th></tr></thead><tbody>";
            foreach ($rows as $r) {
                $uLabel = '';
                if ($r['type']==='PLATFORM') {
                    $st=$db->prepare("SELECT email FROM platform_users WHERE id=?"); $st->execute([(int)$r['user_id']]);
                    $uLabel = (string)$st->fetchColumn();
                } else {
                    $st=$db->prepare("SELECT email FROM users WHERE id=?"); $st->execute([(int)$r['user_id']]);
                    $uLabel = (string)$st->fetchColumn();
                }
                $cLabel = '';
                if (!empty($r['clinic_id'])) {
                    $st=$db->prepare("SELECT name FROM clinics WHERE id=?"); $st->execute([(int)$r['clinic_id']]);
                    $cLabel = (string)$st->fetchColumn();
                }
                echo "<tr><td>".vr_h($r['type'])."</td><td>".vr_h($uLabel)."</td><td>".vr_h($cLabel)."</td><td>".vr_h($r['ip'])."</td><td>".vr_h($r['last_seen'])."</td></tr>";
            }
            echo "</tbody></table></div>";
        }
      ?>
    </div>

  <?php elseif ($page === 'leads'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Richieste arrivate dalla landing</h3>
      <p style="font-size:13px;margin:0 0 10px;opacity:.8">Le richieste vengono salvate nel DB (anche se la consegna email è instabile). Da qui puoi creare un invito in 1 click.</p>
      <?php
        $leads = $db->query("SELECT * FROM leads ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        if (!$leads) {
            echo "<p>Nessuna richiesta.</p>";
        } else {
            echo "<div style='overflow:auto;'><table class='vr-table' style='width:100%'><thead><tr>";
            echo "<th>ID</th><th>Data</th><th>Nome</th><th>Email</th><th>Studio</th><th>Città</th><th>Stato</th><th>Azioni</th>";
            echo "</tr></thead><tbody>";
            foreach ($leads as $l) {
                $email = (string)($l['email'] ?? '');
                $msgPreview = trim((string)($l['message'] ?? ''));
                $mlen = function_exists('mb_strlen') ? mb_strlen($msgPreview) : strlen($msgPreview);
                if ($mlen > 60) {
                    $msgPreview = (function_exists('mb_substr') ? mb_substr($msgPreview, 0, 60) : substr($msgPreview, 0, 60)) . '…';
                }
                echo "<tr>";
                echo "<td>".(int)$l['id']."</td>";
                echo "<td>".vr_h($l['created_at'])."</td>";
                echo "<td>".vr_h($l['full_name'])."</td>";
                echo "<td><div>".vr_h($email)."</div><div style='font-size:12px;opacity:.75'>".vr_h($msgPreview)."</div></td>";
                echo "<td>".vr_h($l['clinic'])."</td>";
                echo "<td>".vr_h($l['city'])."</td>";
                echo "<td>".vr_h($l['status'])."</td>";
                echo "<td>";
                echo "<form method='post' style='margin:0;display:inline-block'>";
                echo "<input type='hidden' name='csrf_token' value='".vr_h(vr_csrf_token())."'>";
                echo "<input type='hidden' name='action' value='create_invite'>";
                echo "<input type='hidden' name='invite_email' value='".vr_h($email)."'>";
                echo "<input type='hidden' name='invite_days' value='2'>";
                echo "<input class='vr-input' type='number' name='invite_max_vets' min='1' max='50' value='1' style='width:96px;display:inline-block;margin-right:6px;' title='Max veterinari'>";
                echo "<input class='vr-input' type='number' name='invite_max_secretaries' min='0' max='200' value='0' style='width:110px;display:inline-block;margin-right:6px;' title='Max segretarie'>";
                echo "<button class='vr-btn vr-btn-secondary' type='submit'>Crea invito</button>";
                echo "</form>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }
      ?>
    </div>

  <?php elseif ($page === 'quota_requests'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Richieste Quote (CHIEF → Platform)</h3>
      <p style="opacity:.85">Quando una struttura vuole aggiungere veterinari o segreteria, invia una richiesta. Qui puoi approvare e impostare i limiti ufficiali.</p>
      <?php
        $rows = $db->query("SELECT qr.*, c.name AS clinic_name,
                (SELECT name FROM users u WHERE u.clinic_id=qr.clinic_id AND UPPER(u.role)='CHIEF' ORDER BY id LIMIT 1) AS chief_name,
                (SELECT email FROM users u WHERE u.clinic_id=qr.clinic_id AND UPPER(u.role)='CHIEF' ORDER BY id LIMIT 1) AS chief_email
              FROM quota_requests qr
              JOIN clinics c ON c.id = qr.clinic_id
              ORDER BY CASE qr.status WHEN 'NEW' THEN 0 WHEN 'APPROVED' THEN 1 WHEN 'DENIED' THEN 2 ELSE 3 END, qr.created_at DESC
              LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
      ?>

      <?php if (!$rows): ?>
        <p>Nessuna richiesta.</p>
      <?php else: ?>
        <div style="overflow:auto">
          <table class="vr-table" style="width:100%">
            <thead>
              <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Clinica</th>
                <th>Chief</th>
                <th>Richiesta</th>
                <th>Stato</th>
                <th>Azioni</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo vr_h((string)$r['created_at']); ?></td>
                <td><?php echo vr_h((string)$r['clinic_name']); ?></td>
                <td><?php echo vr_h((string)$r['chief_name'] . ' <' . (string)$r['chief_email'] . '>'); ?></td>
                <td>VET: <b><?php echo (int)$r['requested_max_vets']; ?></b>, SEG: <b><?php echo (int)$r['requested_max_secretaries']; ?></b></td>
                <td><?php echo vr_h((string)$r['status']); ?></td>
                <td style="white-space:nowrap">
                  <?php if ((string)$r['status'] === 'NEW'): ?>
                    <form method="post" style="display:inline-block;margin:0;">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                      <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                      <input class="vr-input" name="admin_note" placeholder="Nota (opzionale)" style="width:240px;display:inline-block;margin-right:6px;">
                      <button class="vr-btn" type="submit" name="action" value="quota_request_approve">Approva</button>
                      <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="quota_request_deny" onclick="return confirm('Rifiutare questa richiesta?');">Rifiuta</button>
                    </form>
                  <?php else: ?>
                    <span style="color:#666;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($page === 'profiles'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Profili Vet (gestione completa)</h3>
      <p style="opacity:.85">Da qui l'admin può sospendere/riattivare/revocare un profilo, eliminarlo, e accedere ai dati (schede visita, documenti, template, appuntamenti).</p>
      <?php
        $q = $db->query("SELECT
              c.id, c.name, c.status, c.is_active, c.created_at,
              (SELECT email FROM users u WHERE u.clinic_id=c.id AND (u.role='CHIEF' OR u.role='ADMIN') ORDER BY id LIMIT 1) AS admin_email,
              (SELECT name FROM users u WHERE u.clinic_id=c.id AND (u.role='CHIEF' OR u.role='ADMIN') ORDER BY id LIMIT 1) AS admin_name,
              (SELECT COUNT(*) FROM visits v WHERE v.clinic_id=c.id) AS visit_count,
              (SELECT COUNT(*) FROM appointments a WHERE a.clinic_id=c.id) AS appt_count,
              (SELECT COUNT(*) FROM documents d WHERE d.clinic_id=c.id) AS doc_count
            FROM clinics c
            WHERE UPPER(c.status) <> 'SYSTEM'
            ORDER BY c.created_at DESC");
        $clinics = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
      ?>

      <div style="overflow:auto">
        <table class="vr-table" style="width:100%">
          <thead>
            <tr>
              <th>ID</th>
              <th>Clinica</th>
              <th>Chief</th>
              <th>Stato</th>
              <th>Visite</th>
              <th>Appuntamenti</th>
              <th>Documenti</th>
              <th>Azioni</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($clinics as $c): ?>
            <tr>
              <td><?php echo (int)$c['id']; ?></td>
              <td><?php echo vr_h($c['name']); ?></td>
              <td><?php echo vr_h(($c['admin_name'] ?? '') . ' <' . ($c['admin_email'] ?? '') . '>'); ?></td>
              <td><?php echo vr_h(($c['status'] ?? '') . ((int)$c['is_active']===1 ? ' (attivo)' : ' (non attivo)')); ?></td>
              <td><?php echo (int)($c['visit_count'] ?? 0); ?></td>
              <td><?php echo (int)($c['appt_count'] ?? 0); ?></td>
              <td><?php echo (int)($c['doc_count'] ?? 0); ?></td>
              <td style="white-space:nowrap">
                <a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_view&clinic_id=<?php echo (int)$c['id']; ?>">Apri</a>
                <form method="post" style="display:inline-block">
                  <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                  <input type="hidden" name="clinic_id" value="<?php echo (int)$c['id']; ?>">
                  <?php if ((int)$c['is_active']===1): ?>
                    <button class="vr-btn" type="submit" name="action" value="clinic_suspend">Sospendi</button>
                    <button class="vr-btn" type="submit" name="action" value="clinic_revoke" onclick="return confirm('Revocare questo profilo?');">Revoca</button>
                  <?php else: ?>
                    <button class="vr-btn" type="submit" name="action" value="clinic_reactivate">Riattiva</button>
                  <?php endif; ?>
                  <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="clinic_delete" onclick="return confirm('ELIMINAZIONE DEFINITIVA: cancella clinica e tutti i dati della clinica. Continuare?');">Elimina</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($page === 'secretaries'): ?>
    <?php
      $secretaries = $db->query("SELECT u.id, u.name, u.email, u.is_active, u.validated_at, COALESCE(u.max_clinics,1) AS max_clinics,
                 (SELECT COUNT(1)
                    FROM user_clinic uc
                    JOIN clinics c ON c.id=uc.clinic_id
                   WHERE uc.user_id=u.id
                     AND uc.is_active=1
                     AND UPPER(uc.role) IN ('SECRETARY','STAFF')
                     AND c.is_active=1
                     AND UPPER(c.status)='ACTIVE'
                 ) AS active_memberships
            FROM users u
           WHERE UPPER(u.role) IN ('SECRETARY','STAFF')
           ORDER BY u.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

      // Track PLATFORM secretary invitations
      $secInvites = $db->query("SELECT si.id, si.email, COALESCE(si.max_clinics,1) AS max_clinics, si.expires_at, si.used_at, si.revoked_at, si.created_at,
                                      si.used_by_user_id,
                                      u.id AS user_id, u.name AS user_name, u.is_active AS user_is_active, u.validated_at AS user_validated_at
                                 FROM staff_invitations si
                                 LEFT JOIN users u ON u.id = si.used_by_user_id
                                WHERE UPPER(si.role) IN ('SECRETARY','STAFF')
                                  AND si.created_by_platform_user_id IS NOT NULL
                                ORDER BY si.created_at DESC
                                LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div class="vr-card" style="padding:14px;">
      <h3>Segretarie</h3>
      <p style="margin:6px 0 0 0;color:#666;font-size:13px;">
        Gestione quota <b>max cliniche</b> (per segretaria) e membership per cliniche.
      </p>

      <div style="margin-top:14px;padding:12px;border:1px solid #eee;border-radius:12px;">
        <h4 style="margin:0 0 10px 0;">Invita segretaria (ADMIN)</h4>
        <form method="post" action="platform.php?page=secretaries" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
          <input type="hidden" name="action" value="create_secretary_invite">
          <div style="min-width:240px;flex:1;">
            <label style="display:block;font-size:12px;color:#555;margin:0 0 6px 2px;">Email segretaria</label>
            <input name="email" type="email" class="vr-input" required>
          </div>
          <div style="width:160px;">
            <label style="display:block;font-size:12px;color:#555;margin:0 0 6px 2px;">Scadenza (giorni)</label>
            <input name="expires_days" type="number" min="1" max="30" step="1" value="7" class="vr-input" required>
          </div>
          <div style="width:160px;">
            <label style="display:block;font-size:12px;color:#555;margin:0 0 6px 2px;">Max cliniche</label>
            <input name="max_clinics" type="number" min="1" step="1" value="1" class="vr-input" required>
          </div>
          <div>
            <button class="vr-btn vr-btn-primary" type="submit">Crea invito</button>
          </div>
        </form>
        <p style="margin:10px 0 0 0;color:#666;font-size:12px;">
          L'invito genera un link per <code>staff_register.php</code>. Dopo la registrazione il profilo resta <b>DA VALIDARE</b> finché ADMIN non lo approva.
        </p>
      </div>

      <div style="margin-top:14px;overflow:auto;">
        <h4 style="margin:0 0 10px 0;">Inviti segretarie (ADMIN)</h4>
        <table class="vr-table">
          <thead>
            <tr>
              <th>Email</th>
              <th>Max cliniche</th>
              <th>Creato</th>
              <th>Scade</th>
              <th>Stato invito</th>
              <th>Account</th>
              <th style="width:260px;">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$secInvites): ?>
              <tr><td colspan="7" style="color:#666;">Nessun invito trovato.</td></tr>
            <?php else: foreach ($secInvites as $iv):
              $ivId = (int)($iv['id'] ?? 0);
              $ivUsed = !empty($iv['used_at']);
              $ivRevoked = !empty($iv['revoked_at']);
              $expTs = strtotime((string)($iv['expires_at'] ?? ''));
              $isExpired = ($expTs !== false && $expTs < time());
              $ivStatus = 'IN ATTESA';
              if ($ivRevoked) $ivStatus = 'REVOCATO';
              elseif ($ivUsed) $ivStatus = 'USATO';
              elseif ($isExpired) $ivStatus = 'SCADUTO';

              $uId = (int)($iv['user_id'] ?? 0);
              $uName = (string)($iv['user_name'] ?? '');
              $uActive = ((int)($iv['user_is_active'] ?? 0) === 1);
              $uValidated = !empty($iv['user_validated_at']);
            ?>
              <tr>
                <td><?php echo vr_h((string)($iv['email'] ?? '')); ?></td>
                <td><?php echo (int)($iv['max_clinics'] ?? 1); ?></td>
                <td style="white-space:nowrap"><?php echo vr_h((string)($iv['created_at'] ?? '')); ?></td>
                <td style="white-space:nowrap"><?php echo vr_h((string)($iv['expires_at'] ?? '')); ?></td>
                <td>
                  <?php if ($ivStatus === 'USATO'): ?>
                    <span class="vr-badge vr-badge-ok">USATO</span>
                  <?php elseif ($ivStatus === 'IN ATTESA'): ?>
                    <span class="vr-badge vr-badge-warn">IN ATTESA</span>
                  <?php else: ?>
                    <span class="vr-badge vr-badge-warn"><?php echo vr_h($ivStatus); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($uId > 0): ?>
                    <a class="vr-link" href="platform.php?page=secretary_view&id=<?php echo $uId; ?>"><?php echo vr_h($uName !== '' ? $uName : ('Segretaria #'.$uId)); ?></a>
                    <div style="font-size:12px;color:#666;">
                      <?php if ($uActive): ?>ATTIVO<?php else: ?>NON ATTIVO<?php endif; ?>
                      <?php if (!$uValidated): ?> • DA VALIDARE<?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span style="color:#999;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($uId > 0 && !$uValidated): ?>
                    <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                      <input type="hidden" name="action" value="secretary_validate">
                      <input type="hidden" name="user_id" value="<?php echo $uId; ?>">
                      <input type="hidden" name="redirect" value="platform.php?page=secretaries">
                      <button class="vr-btn" type="submit">Valida profilo</button>
                    </form>
                    <span style="color:#ccc;margin:0 6px;">|</span>
                  <?php endif; ?>

                  <?php if (!$ivUsed && !$ivRevoked): ?>
                    <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                      <input type="hidden" name="action" value="secretary_invite_resend">
                      <input type="hidden" name="invite_id" value="<?php echo $ivId; ?>">
                      <button class="vr-btn" type="submit">Reinvia link</button>
                    </form>
                    <span style="color:#ccc;margin:0 6px;">|</span>
                    <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                      <input type="hidden" name="action" value="secretary_invite_revoke">
                      <input type="hidden" name="invite_id" value="<?php echo $ivId; ?>">
                      <button class="vr-btn" type="submit" onclick="return confirm('Revocare questo invito?');">Revoca</button>
                    </form>
                  <?php else: ?>
                    <span style="color:#999;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <p style="margin:10px 0 0 0;color:#666;font-size:12px;">
          Nota: per sicurezza i token non vengono salvati in chiaro. Il tasto <b>Reinvia link</b> rigenera un nuovo token e lo invia via email.
        </p>
      </div>

      <div style="margin-top:14px;overflow:auto;">
        <table class="vr-table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Email</th>
              <th>Stato</th>
              <th>Max cliniche</th>
              <th>Cliniche attive</th>
              <th style="width:240px;">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$secretaries): ?>
              <tr><td colspan="6" style="color:#666;">Nessuna segretaria trovata.</td></tr>
            <?php else: foreach ($secretaries as $s):
              $sid = (int)$s['id'];
              $sActive = ((int)($s['is_active'] ?? 0) === 1);
              $sValidated = !empty($s['validated_at']);
              $maxC = max(1, (int)($s['max_clinics'] ?? 1));
              $actM = (int)($s['active_memberships'] ?? 0);
            ?>
              <tr>
                <td><?php echo vr_h((string)($s['name'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($s['email'] ?? '')); ?></td>
                <td>
                  <?php if ($sActive): ?>
                    <span class="vr-badge vr-badge-ok">ATTIVA</span>
                  <?php else: ?>
                    <?php if (!$sValidated): ?>
                      <span class="vr-badge vr-badge-warn">DA VALIDARE</span>
                    <?php else: ?>
                      <span class="vr-badge vr-badge-warn">SOSPESA</span>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" action="platform.php?page=secretaries" style="display:inline-flex;gap:6px;align-items:center;">
                    <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                    <input type="hidden" name="action" value="secretary_update_max_clinics">
                    <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                    <input name="max_clinics" type="number" min="1" step="1" value="<?php echo $maxC; ?>" class="vr-input" style="width:90px;padding:6px 10px;">
                    <button class="vr-btn" type="submit">Salva</button>
                  </form>
                </td>
                <td><?php echo $actM; ?></td>
                <td>
                  <a class="vr-link" href="platform.php?page=secretary_view&id=<?php echo $sid; ?>">Dettagli</a>
                  <span style="margin:0 8px;color:#ccc;">|</span>
                  <?php if ($sActive): ?>
                    <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                      <input type="hidden" name="action" value="secretary_suspend">
                      <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                      <button class="vr-btn" type="submit" onclick="return confirm('Sospendere questa segretaria?');">Sospendi</button>
                    </form>
                  <?php else: ?>
                    <?php if (!$sValidated): ?>
                      <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                        <input type="hidden" name="action" value="secretary_validate">
                        <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="redirect" value="platform.php?page=secretaries">
                        <button class="vr-btn" type="submit">Valida</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="platform.php?page=secretaries" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                        <input type="hidden" name="action" value="secretary_reactivate">
                        <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                        <button class="vr-btn" type="submit">Riattiva</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($page === 'secretary_view'): ?>
    <?php
      $sid = (int)($_GET['id'] ?? 0);
      $sec = null;
      if ($sid > 0) {
        $st = $db->prepare("SELECT id, name, email, is_active, validated_at, COALESCE(max_clinics,1) AS max_clinics, created_at FROM users WHERE id=? AND UPPER(role) IN ('SECRETARY','STAFF') LIMIT 1");
        $st->execute([$sid]);
        $sec = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      $memberships = [];
      $activeMemberships = 0;
      if ($sec) {
        $m = $db->prepare("SELECT uc.clinic_id, uc.is_active AS membership_active, uc.role, uc.created_at,
                                   c.name AS clinic_name, c.status AS clinic_status, c.is_active AS clinic_is_active
                              FROM user_clinic uc
                              JOIN clinics c ON c.id=uc.clinic_id
                             WHERE uc.user_id=? AND UPPER(uc.role) IN ('SECRETARY','STAFF')
                             ORDER BY c.name ASC");
        $m->execute([$sid]);
        $memberships = $m->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($memberships as $mr) {
          if ((int)($mr['membership_active'] ?? 0) === 1 && (int)($mr['clinic_is_active'] ?? 0) === 1 && strtoupper((string)($mr['clinic_status'] ?? '')) === 'ACTIVE') {
            $activeMemberships++;
          }
        }
      }
    ?>

    <div class="vr-card" style="padding:14px;">
      <?php if (!$sec): ?>
        <h3>Segretaria non trovata</h3>
        <p style="color:#666;font-size:13px;">ID non valido o utente non è una segretaria.</p>
      <?php else: ?>
        <h3>Segretaria: <?php echo vr_h((string)($sec['name'] ?? '')); ?></h3>
        <?php $secValidated = !empty($sec['validated_at']); ?>
        <p style="margin:6px 0 0 0;color:#666;font-size:13px;">
          <b>Email:</b> <?php echo vr_h((string)($sec['email'] ?? '')); ?>
          &nbsp;•&nbsp;
          <b>Stato:</b>
          <?php if ((int)($sec['is_active'] ?? 0) === 1): ?>
            ATTIVA
          <?php else: ?>
            <?php echo $secValidated ? 'SOSPESA' : 'DA VALIDARE'; ?>
          <?php endif; ?>
          <?php if ($secValidated): ?>
            <span style="color:#999;">(validata)</span>
          <?php endif; ?>
        </p>

        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
          <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
            <input type="hidden" name="action" value="secretary_update_max_clinics">
            <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
            <div>
              <label style="display:block;font-size:12px;color:#555;margin:0 0 6px 2px;">Max cliniche</label>
              <input name="max_clinics" type="number" min="1" step="1" value="<?php echo (int)($sec['max_clinics'] ?? 1); ?>" class="vr-input" style="width:120px;">
            </div>
            <div>
              <button class="vr-btn" type="submit">Salva</button>
            </div>
          </form>

          <div style="font-size:13px;color:#555;">
            <b>Cliniche attive:</b> <?php echo $activeMemberships; ?>
          </div>

          <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ((int)($sec['is_active'] ?? 0) === 1): ?>
              <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                <input type="hidden" name="action" value="secretary_suspend">
                <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                <button class="vr-btn" type="submit" onclick="return confirm('Sospendere questa segretaria?');">Sospendi</button>
              </form>
            <?php else: ?>
              <?php if (!$secValidated): ?>
                <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                  <input type="hidden" name="action" value="secretary_validate">
                  <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                  <input type="hidden" name="redirect" value="platform.php?page=secretary_view&id=<?php echo $sid; ?>">
                  <button class="vr-btn" type="submit">Valida</button>
                </form>
              <?php else: ?>
                <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                  <input type="hidden" name="action" value="secretary_reactivate">
                  <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                  <button class="vr-btn" type="submit">Riattiva</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
              <input type="hidden" name="action" value="secretary_close">
              <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
              <button class="vr-btn" type="submit" onclick="return confirm('Chiudere definitivamente l\'account?');">Chiudi account</button>
            </form>
          </div>
        </div>

        <hr style="margin:14px 0;border:none;border-top:1px solid #eee;">

        <h4 style="margin:0 0 10px 0;">Membership cliniche</h4>
        <div style="overflow:auto;">
          <table class="vr-table">
            <thead>
              <tr>
                <th>Clinica</th>
                <th>Stato clinica</th>
                <th>Membership</th>
                <th style="width:200px;">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$memberships): ?>
                <tr><td colspan="4" style="color:#666;">Nessuna membership trovata.</td></tr>
              <?php else: foreach ($memberships as $mr):
                $cid = (int)$mr['clinic_id'];
                $mActive = ((int)($mr['membership_active'] ?? 0) === 1);
                $cActive = ((int)($mr['clinic_is_active'] ?? 0) === 1);
                $cStatus = strtoupper((string)($mr['clinic_status'] ?? ''));
              ?>
                <tr>
                  <td><?php echo vr_h((string)($mr['clinic_name'] ?? ('Clinica #'.$cid))); ?></td>
                  <td><?php echo $cActive ? vr_h($cStatus) : 'INATTIVA'; ?></td>
                  <td><?php echo $mActive ? '<span class="vr-badge vr-badge-ok">ATTIVA</span>' : '<span class="vr-badge vr-badge-warn">DISATTIVA</span>'; ?></td>
                  <td>
                    <?php if ($mActive): ?>
                      <form method="post" action="platform.php?page=secretary_view&id=<?php echo $sid; ?>" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                        <input type="hidden" name="action" value="secretary_revoke_membership">
                        <input type="hidden" name="user_id" value="<?php echo $sid; ?>">
                        <input type="hidden" name="clinic_id" value="<?php echo $cid; ?>">
                        <button class="vr-btn" type="submit" onclick="return confirm('Revocare membership per questa clinica?');">Revoca</button>
                      </form>
                    <?php else: ?>
                      <span style="color:#999;">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($page === 'secretary_memberships'): ?>
    <?php
      $rows = $db->query("SELECT uc.user_id, uc.clinic_id, uc.is_active AS mem_active, uc.role, uc.updated_at,
                                 u.name AS sec_name, u.email AS sec_email, u.is_active AS sec_active,
                                 c.name AS clinic_name, c.status AS clinic_status, c.is_active AS clinic_active
                            FROM user_clinic uc
                            JOIN users u ON u.id = uc.user_id
                            JOIN clinics c ON c.id = uc.clinic_id
                           WHERE UPPER(uc.role) IN ('SECRETARY','STAFF')
                           ORDER BY c.name ASC, u.name ASC")->fetchAll(PDO::FETCH_ASSOC);

      $pending = $db->query("SELECT t.id, t.secretary_user_id, t.chief_email, t.expires_at, t.used_at, t.created_at,
                                    u.name AS sec_name, u.email AS sec_email
                               FROM secretary_membership_tokens t
                               JOIN users u ON u.id = t.secretary_user_id
                              WHERE t.used_at IS NULL
                              ORDER BY t.created_at DESC
                              LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
      $nowTs = time();
    ?>

    <div class="vr-card" style="padding:14px;">
      <h3>Membership segreteria</h3>
      <p style="margin:6px 0 0 0;color:#666;font-size:13px;">Vista globale: membership attive/sospese + token in attesa (QR/link non ancora usati).</p>

      <hr style="margin:14px 0;border:none;border-top:1px solid #eee;">

      <h4 style="margin:0 0 10px 0;">Token in attesa (pending)</h4>
      <div style="overflow:auto;">
        <table class="vr-table">
          <thead>
            <tr>
              <th>Segretaria</th>
              <th>Chief email</th>
              <th>Creato</th>
              <th>Scade</th>
              <th>Stato</th>
              <th style="width:160px;">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$pending): ?>
              <tr><td colspan="6" style="color:#666;">Nessun token in attesa.</td></tr>
            <?php else: foreach ($pending as $p):
              $expTs = strtotime((string)($p['expires_at'] ?? ''));
              $isExpired = ($expTs !== false && $expTs < $nowTs);
            ?>
              <tr>
                <td>
                  <a class="vr-link" href="platform.php?page=secretary_view&id=<?php echo (int)$p['secretary_user_id']; ?>"><?php echo vr_h((string)($p['sec_name'] ?? '')); ?></a>
                  <div style="font-size:12px;color:#666;"><?php echo vr_h((string)($p['sec_email'] ?? '')); ?></div>
                </td>
                <td><?php echo vr_h((string)($p['chief_email'] ?? '')); ?></td>
                <td style="white-space:nowrap"><?php echo vr_h((string)($p['created_at'] ?? '')); ?></td>
                <td style="white-space:nowrap"><?php echo vr_h((string)($p['expires_at'] ?? '')); ?></td>
                <td>
                  <?php if ($isExpired): ?>
                    <span class="vr-badge vr-badge-warn">SCADUTO</span>
                  <?php else: ?>
                    <span class="vr-badge vr-badge-warn">PENDING</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" action="platform.php?page=secretary_memberships" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                    <input type="hidden" name="action" value="secretary_token_delete">
                    <input type="hidden" name="token_id" value="<?php echo (int)$p['id']; ?>">
                    <button class="vr-btn" type="submit" onclick="return confirm('Rimuovere questo token?');">Rimuovi</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <hr style="margin:14px 0;border:none;border-top:1px solid #eee;">

      <h4 style="margin:0 0 10px 0;">Membership (active / suspended)</h4>
      <div style="overflow:auto;">
        <table class="vr-table">
          <thead>
            <tr>
              <th>Clinica</th>
              <th>Segretaria</th>
              <th>Stato clinica</th>
              <th>Stato membership</th>
              <th style="width:260px;">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="5" style="color:#666;">Nessuna membership trovata.</td></tr>
            <?php else: foreach ($rows as $r):
              $mActive = ((int)($r['mem_active'] ?? 0) === 1);
              $cActive = ((int)($r['clinic_active'] ?? 0) === 1);
              $cStatus = strtoupper((string)($r['clinic_status'] ?? ''));
              $secActive = ((int)($r['sec_active'] ?? 0) === 1);
              $statusLabel = $mActive ? 'ATTIVA' : 'SOSPESA';
              if (!$secActive) $statusLabel = 'SOSPESA (ACCOUNT)';
              elseif (!$cActive || $cStatus !== 'ACTIVE') $statusLabel = 'SOSPESA (CLINICA)';
            ?>
              <tr>
                <td><?php echo vr_h((string)($r['clinic_name'] ?? '')); ?></td>
                <td>
                  <a class="vr-link" href="platform.php?page=secretary_view&id=<?php echo (int)$r['user_id']; ?>"><?php echo vr_h((string)($r['sec_name'] ?? '')); ?></a>
                  <div style="font-size:12px;color:#666;"><?php echo vr_h((string)($r['sec_email'] ?? '')); ?></div>
                </td>
                <td><?php echo $cActive ? vr_h($cStatus) : 'INATTIVA'; ?></td>
                <td><?php echo $mActive ? '<span class="vr-badge vr-badge-ok">ATTIVA</span>' : '<span class="vr-badge vr-badge-warn">SOSPESA</span>'; ?></td>
                <td>
                  <form method="post" action="platform.php?page=secretary_memberships" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$r['user_id']; ?>">
                    <input type="hidden" name="clinic_id" value="<?php echo (int)$r['clinic_id']; ?>">
                    <?php if ($mActive): ?>
                      <button class="vr-btn" type="submit" name="action" value="secretary_membership_suspend" onclick="return confirm('Sospendere questa membership?');">Sospendi</button>
                    <?php else: ?>
                      <button class="vr-btn" type="submit" name="action" value="secretary_membership_reactivate" onclick="return confirm('Riattivare questa membership?');">Riattiva</button>
                    <?php endif; ?>
                    <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="secretary_membership_delete" onclick="return confirm('Rimuovere definitivamente la relazione segreteria↔clinica?');">Rimuovi</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php elseif ($page === 'profile_view'): ?>
    <?php
      $clinicId = (int)($_GET['clinic_id'] ?? 0);
      $tab = (string)($_GET['tab'] ?? 'docs');
      $stmtC = $db->prepare("SELECT * FROM clinics WHERE id=? LIMIT 1");
      $stmtC->execute([$clinicId]);
      $clinic = $stmtC->fetch(PDO::FETCH_ASSOC);
      if (!$clinic) {
        echo '<div class="vr-alert vr-alert-error">Clinica non trovata.</div>';
      } else {
        $adminEmail = $db->prepare("SELECT email FROM users WHERE clinic_id=? AND (role='CHIEF' OR role='ADMIN') ORDER BY id LIMIT 1");
        $adminEmail->execute([$clinicId]);
        $adminEmailVal = (string)($adminEmail->fetchColumn() ?: '');
      }
    ?>
    <?php if ($clinic): ?>
      <div class="vr-card vr-collapsible" style="padding:14px;">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
          <div>
            <h3 style="margin:0;">Profilo: <?php echo vr_h($clinic['name']); ?> (ID <?php echo (int)$clinicId; ?>)</h3>
            <div style="opacity:.85;margin-top:4px;">Chief email: <?php echo vr_h($adminEmailVal); ?> • Stato: <?php echo vr_h(($clinic['status'] ?? '') . ((int)$clinic['is_active']===1?' (attivo)':' (non attivo)')); ?></div>
          </div>
          <div>
            <a class="vr-btn vr-btn-secondary" href="platform.php?page=profiles">&larr; Torna alla lista</a>
          </div>
        </div>

        <div class="vr-card vr-collapsible" style="padding:12px;margin-top:12px;">
          <h4 style="margin:0 0 8px;">Limiti struttura (solo ADMIN)</h4>
          <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
            <input type="hidden" name="action" value="update_clinic_limits">
            <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
            <input type="hidden" name="tab" value="<?php echo vr_h($tab); ?>">
            <div style="min-width:220px;">
              <label>Max veterinari</label>
              <input class="vr-input" type="number" name="max_vets" min="1" max="50" value="<?php echo (int)($clinic['max_vets'] ?? 1); ?>" required>
            </div>
            <div style="min-width:220px;">
              <label>Max segretarie</label>
              <input class="vr-input" type="number" name="max_secretaries" min="0" max="200" value="<?php echo (int)($clinic['max_secretaries'] ?? 0); ?>" required>
            </div>
            <button class="vr-btn" type="submit">Salva limiti</button>
          </form>
          <div style="opacity:.8;font-size:12px;margin-top:8px;">Nota: i limiti possono essere modificati solo dalla piattaforma (ADMIN). Verranno usati per abilitare multi-vet e segreteria.</div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
          <?php
            $tabs = [
              'docs' => 'Schede visita / Documenti',
              'visits' => 'Visite',
              'templates' => 'Template',
              'appointments' => 'Appuntamenti'
            ];
          ?>
          <?php foreach ($tabs as $k=>$lbl): ?>
            <?php $cls = ($tab===$k) ? 'vr-btn' : 'vr-btn vr-btn-secondary'; ?>
            <a class="<?php echo $cls; ?>" href="platform.php?page=profile_view&clinic_id=<?php echo (int)$clinicId; ?>&tab=<?php echo urlencode($k); ?>"><?php echo vr_h($lbl); ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($tab === 'docs'): ?>
          <h4 style="margin-top:14px;">Documenti (tutti i file del veterinario)</h4>
          <?php
            $st = $db->prepare("SELECT d.*, p.name AS pet_name, o.surname AS owner_surname
                                FROM documents d
                                LEFT JOIN pets p ON d.pet_id=p.id
                                LEFT JOIN owners o ON p.owner_id=o.id
                                WHERE d.clinic_id=?
                                ORDER BY d.created_at DESC
                                LIMIT 500");
            $st->execute([$clinicId]);
            $docs = $st->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="overflow:auto">
            <table class="vr-table" style="width:100%">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Nome originale</th>
                  <th>Pet</th>
                  <th>Tipo</th>
                  <th>Data</th>
                  <th>Link</th>
                  <th>Azioni</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($docs as $d): ?>
                <tr>
                  <td><?php echo (int)$d['id']; ?></td>
                  <td><?php echo vr_h($d['original_name'] ?? ''); ?></td>
                  <td><?php echo vr_h(($d['pet_name'] ?? '') . ' ' . ($d['owner_surname'] ?? '')); ?></td>
                  <td><?php echo vr_h($d['mime_type'] ?? ''); ?></td>
                  <td><?php echo vr_h($d['created_at'] ?? ''); ?></td>
                  <td style="white-space:nowrap">
                    <a class="vr-btn vr-btn-secondary" href="platform_doc.php?doc_id=<?php echo (int)$d['id']; ?>&inline=1" target="_blank">Apri</a>
                    <a class="vr-btn vr-btn-secondary" href="platform_doc.php?doc_id=<?php echo (int)$d['id']; ?>">Scarica</a>
                  </td>
                  <td style="white-space:nowrap">
                    <form method="post" style="display:inline-block">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                      <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
                      <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                      <input type="text" name="new_name" value="<?php echo vr_h($d['original_name'] ?? ''); ?>" style="width:170px;">
                      <button class="vr-btn" type="submit" name="action" value="doc_rename">Rinomina</button>
                    </form>
                    <form method="post" style="display:inline-block">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                      <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
                      <input type="hidden" name="doc_id" value="<?php echo (int)$d['id']; ?>">
                      <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="doc_delete" onclick="return confirm('Eliminare questo file?');">Elimina</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($tab === 'visits'): ?>
          <h4 style="margin-top:14px;">Visite</h4>
          <?php
            $st = $db->prepare("SELECT v.id, v.visit_date, v.kind, v.created_at, p.name AS pet_name, o.surname AS owner_surname
                                FROM visits v
                                LEFT JOIN pets p ON v.pet_id=p.id
                                LEFT JOIN owners o ON p.owner_id=o.id
                                WHERE v.clinic_id=?
                                ORDER BY v.visit_date DESC, v.id DESC
                                LIMIT 500");
            $st->execute([$clinicId]);
            $vis = $st->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="overflow:auto">
            <table class="vr-table" style="width:100%">
              <thead>
                <tr><th>ID</th><th>Data</th><th>Tipo</th><th>Pet</th><th>Azioni</th></tr>
              </thead>
              <tbody>
              <?php foreach ($vis as $v): ?>
                <tr>
                  <td><?php echo (int)$v['id']; ?></td>
                  <td><?php echo vr_h($v['visit_date'] ?? ''); ?></td>
                  <td><?php echo vr_h($v['kind'] ?? ''); ?></td>
                  <td><?php echo vr_h(($v['pet_name'] ?? '') . ' ' . ($v['owner_surname'] ?? '')); ?></td>
                  <td><a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_visit_edit&clinic_id=<?php echo (int)$clinicId; ?>&visit_id=<?php echo (int)$v['id']; ?>">Modifica</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php elseif ($tab === 'templates'): ?>
          <h4 style="margin-top:14px;">Template schede visita</h4>
          <?php
            $st = $db->prepare("SELECT * FROM visit_templates WHERE clinic_id=? ORDER BY id DESC");
            $st->execute([$clinicId]);
            $tps = $st->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="overflow:auto">
            <table class="vr-table" style="width:100%">
              <thead>
                <tr><th>ID</th><th>Nome</th><th>Abilitato</th><th>Overlay header</th><th>Background</th><th>Azioni</th></tr>
              </thead>
              <tbody>
              <?php foreach ($tps as $t): ?>
                <tr>
                  <td><?php echo (int)$t['id']; ?></td>
                  <td><?php echo vr_h($t['name'] ?? ''); ?></td>
                  <td><?php echo (int)($t['enabled'] ?? 0) ? 'SI' : 'NO'; ?></td>
                  <td><?php echo vr_h($t['overlay_header'] ?? ''); ?></td>
                  <td><?php echo !empty($t['bg_path']) ? '<span style="opacity:.8">OK</span>' : '<span style="opacity:.5">-</span>'; ?></td>
                  <td style="white-space:nowrap">
                    <form method="post" style="display:inline-block">
                      <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                      <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
                      <input type="hidden" name="template_id" value="<?php echo (int)$t['id']; ?>">
                      <button class="vr-btn" type="submit" name="action" value="template_toggle"><?php echo (int)($t['enabled'] ?? 0) ? 'Disabilita' : 'Abilita'; ?></button>
                    </form>
                    <a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_template_edit&clinic_id=<?php echo (int)$clinicId; ?>&template_id=<?php echo (int)$t['id']; ?>">Modifica</a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p style="opacity:.8">Nota: la modifica avanzata (mappatura campi) è disponibile nella schermata Modifica.</p>

        <?php elseif ($tab === 'appointments'): ?>
          <h4 style="margin-top:14px;">Appuntamenti</h4>
          <?php
            $st = $db->prepare("SELECT a.id, a.appointment_at, a.status, a.kind, p.name AS pet_name, o.surname AS owner_surname
                                FROM appointments a
                                LEFT JOIN pets p ON a.pet_id=p.id
                                LEFT JOIN owners o ON p.owner_id=o.id
                                WHERE a.clinic_id=?
                                ORDER BY a.appointment_at DESC
                                LIMIT 500");
            $st->execute([$clinicId]);
            $apps = $st->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="overflow:auto">
            <table class="vr-table" style="width:100%">
              <thead>
                <tr><th>ID</th><th>Quando</th><th>Tipo</th><th>Stato</th><th>Pet</th><th>Azioni</th></tr>
              </thead>
              <tbody>
              <?php foreach ($apps as $a): ?>
                <tr>
                  <td><?php echo (int)$a['id']; ?></td>
                  <td><?php echo vr_h($a['appointment_at'] ?? ''); ?></td>
                  <td><?php echo vr_h($a['kind'] ?? ''); ?></td>
                  <td><?php echo vr_h($a['status'] ?? ''); ?></td>
                  <td><?php echo vr_h(($a['pet_name'] ?? '') . ' ' . ($a['owner_surname'] ?? '')); ?></td>
                  <td><a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_appointment_edit&clinic_id=<?php echo (int)$clinicId; ?>&appt_id=<?php echo (int)$a['id']; ?>">Modifica</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($page === 'profile_visit_edit'): ?>
    <?php
      $clinicId = (int)($_GET['clinic_id'] ?? 0);
      $visitId = (int)($_GET['visit_id'] ?? 0);
      $visit = null;
      if ($clinicId > 0 && $visitId > 0) {
        $st = $db->prepare("SELECT * FROM visits WHERE id=? AND clinic_id=? LIMIT 1");
        $st->execute([$visitId, $clinicId]);
        $visit = $st->fetch(PDO::FETCH_ASSOC);
      }
    ?>
    <?php if (!$visit): ?>
      <div class="vr-alert vr-alert-error">Visita non trovata.</div>
    <?php else: ?>
      <div class="vr-card vr-collapsible" style="padding:14px;">
        <h3>Modifica visita #<?php echo (int)$visitId; ?> (clinic <?php echo (int)$clinicId; ?>)</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
          <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
          <input type="hidden" name="visit_id" value="<?php echo (int)$visitId; ?>">
          <label>Diagnosi</label>
          <textarea class="vr-input" name="diagnosis" rows="3"><?php echo vr_h($visit['diagnosis'] ?? ''); ?></textarea>
          <label>Terapia</label>
          <textarea class="vr-input" name="therapy" rows="3"><?php echo vr_h($visit['therapy'] ?? ''); ?></textarea>
          <label>Note</label>
          <textarea class="vr-input" name="notes" rows="4"><?php echo vr_h($visit['notes'] ?? ''); ?></textarea>
          <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
            <button class="vr-btn" type="submit" name="action" value="visit_update">Salva</button>
            <a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_view&clinic_id=<?php echo (int)$clinicId; ?>&tab=visits">Annulla</a>
            <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="visit_delete" onclick="return confirm('Eliminare questa visita?');">Elimina</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

  <?php elseif ($page === 'profile_appointment_edit'): ?>
    <?php
      $clinicId = (int)($_GET['clinic_id'] ?? 0);
      $apptId = (int)($_GET['appt_id'] ?? 0);
      $appt = null;
      if ($clinicId > 0 && $apptId > 0) {
        $st = $db->prepare("SELECT * FROM appointments WHERE id=? AND clinic_id=? LIMIT 1");
        $st->execute([$apptId, $clinicId]);
        $appt = $st->fetch(PDO::FETCH_ASSOC);
      }
    ?>
    <?php if (!$appt): ?>
      <div class="vr-alert vr-alert-error">Appuntamento non trovato.</div>
    <?php else: ?>
      <div class="vr-card vr-collapsible" style="padding:14px;">
        <h3>Modifica appuntamento #<?php echo (int)$apptId; ?> (clinic <?php echo (int)$clinicId; ?>)</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
          <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
          <input type="hidden" name="appt_id" value="<?php echo (int)$apptId; ?>">
          <label>Data/ora (ISO o YYYY-MM-DD HH:MM)</label>
          <input class="vr-input" name="appointment_at" value="<?php echo vr_h($appt['appointment_at'] ?? ''); ?>">
          <label>Stato</label>
          <input class="vr-input" name="status" value="<?php echo vr_h($appt['status'] ?? ''); ?>">
          <label>Tipo</label>
          <input class="vr-input" name="kind" value="<?php echo vr_h($appt['kind'] ?? ''); ?>">
          <label>Note</label>
          <textarea class="vr-input" name="notes" rows="4"><?php echo vr_h($appt['notes'] ?? ''); ?></textarea>
          <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
            <button class="vr-btn" type="submit" name="action" value="appt_update">Salva</button>
            <a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_view&clinic_id=<?php echo (int)$clinicId; ?>&tab=appointments">Annulla</a>
            <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="appt_delete" onclick="return confirm('Eliminare questo appuntamento?');">Elimina</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

  <?php elseif ($page === 'profile_template_edit'): ?>
    <?php
      $clinicId = (int)($_GET['clinic_id'] ?? 0);
      $templateId = (int)($_GET['template_id'] ?? 0);
      $tpl = null;
      if ($clinicId > 0 && $templateId > 0) {
        $st = $db->prepare("SELECT * FROM visit_templates WHERE id=? AND clinic_id=? LIMIT 1");
        $st->execute([$templateId, $clinicId]);
        $tpl = $st->fetch(PDO::FETCH_ASSOC);
      }
    ?>
    <?php if (!$tpl): ?>
      <div class="vr-alert vr-alert-error">Template non trovato.</div>
    <?php else: ?>
      <div class="vr-card vr-collapsible" style="padding:14px;">
        <h3>Modifica template #<?php echo (int)$templateId; ?> (clinic <?php echo (int)$clinicId; ?>)</h3>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
          <input type="hidden" name="clinic_id" value="<?php echo (int)$clinicId; ?>">
          <input type="hidden" name="template_id" value="<?php echo (int)$templateId; ?>">
          <label>Nome</label>
          <input class="vr-input" name="name" value="<?php echo vr_h($tpl['name'] ?? ''); ?>">
          <label>Overlay header</label>
          <input class="vr-input" name="overlay_header" value="<?php echo vr_h($tpl['overlay_header'] ?? ''); ?>">
          <label>Mappa campi (JSON)</label>
          <textarea class="vr-input" name="field_map_json" data-vr-no-uppercase rows="8"><?php echo vr_h($tpl['field_map_json'] ?? ''); ?></textarea>
          <label>Background (PNG/JPG/PDF) - opzionale</label>
          <input class="vr-input" type="file" name="bg_file" accept="application/pdf,image/*">
          <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
            <button class="vr-btn" type="submit" name="action" value="template_update">Salva</button>
            <a class="vr-btn vr-btn-secondary" href="platform.php?page=profile_view&clinic_id=<?php echo (int)$clinicId; ?>&tab=templates">Annulla</a>
            <?php if (!empty($tpl['bg_path'])): ?>
              <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="template_bg_delete" onclick="return confirm('Eliminare il background del template?');">Elimina background</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    <?php endif; ?>

  <?php elseif ($page === 'admin_db'): ?>
    <?php
      $tab = (string)($_GET['tab'] ?? 'owners');
      $q = trim((string)($_GET['q'] ?? ''));
      $ownersCnt = (int)$db->query("SELECT COUNT(*) FROM admin_owners")->fetchColumn();
      $petsCnt = (int)$db->query("SELECT COUNT(*) FROM admin_pets")->fetchColumn();
    ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Database Admin (persistente)</h3>
      <p style="opacity:.85">Questo database contiene la somma (storica) di proprietari e pazienti. Quando una clinica viene eliminata, i suoi proprietari/pazienti restano qui.</p>
      <div style="margin:10px 0;opacity:.85">Proprietari: <?php echo (int)$ownersCnt; ?> • Pazienti: <?php echo (int)$petsCnt; ?></div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <a class="<?php echo $tab==='owners'?'vr-btn':'vr-btn vr-btn-secondary'; ?>" href="platform.php?page=admin_db&tab=owners">Proprietari</a>
        <a class="<?php echo $tab==='pets'?'vr-btn':'vr-btn vr-btn-secondary'; ?>" href="platform.php?page=admin_db&tab=pets">Pazienti</a>
      </div>
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
        <input type="hidden" name="page" value="admin_db">
        <input type="hidden" name="tab" value="<?php echo vr_h($tab); ?>">
        <input class="vr-input" name="q" value="<?php echo vr_h($q); ?>" placeholder="Cerca (nome, email, microchip...)" style="min-width:260px;">
        <button class="vr-btn" type="submit">Cerca</button>
      </form>

      <?php if ($tab === 'pets'): ?>
        <?php
          if ($q !== '') {
            $st = $db->prepare("SELECT * FROM admin_pets WHERE name LIKE ? OR microchip LIKE ? OR breed LIKE ? ORDER BY synced_at DESC LIMIT 300");
            $like = '%' . $q . '%';
            $st->execute([$like,$like,$like]);
          } else {
            $st = $db->query("SELECT * FROM admin_pets ORDER BY synced_at DESC LIMIT 300");
          }
          $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        ?>
        <div style="overflow:auto"><table class="vr-table" style="width:100%">
          <thead><tr><th>ID</th><th>Nome</th><th>Specie</th><th>Razza</th><th>Microchip</th><th>Clinic</th><th>Sync</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo vr_h($r['name'] ?? ''); ?></td>
              <td><?php echo vr_h($r['species'] ?? ''); ?></td>
              <td><?php echo vr_h($r['breed'] ?? ''); ?></td>
              <td><?php echo vr_h($r['microchip'] ?? ''); ?></td>
              <td><?php echo (int)($r['source_clinic_id'] ?? 0); ?></td>
              <td><?php echo vr_h($r['synced_at'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <?php
          if ($q !== '') {
            $st = $db->prepare("SELECT * FROM admin_owners WHERE name LIKE ? OR surname LIKE ? OR email LIKE ? ORDER BY synced_at DESC LIMIT 300");
            $like = '%' . $q . '%';
            $st->execute([$like,$like,$like]);
          } else {
            $st = $db->query("SELECT * FROM admin_owners ORDER BY synced_at DESC LIMIT 300");
          }
          $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        ?>
        <div style="overflow:auto"><table class="vr-table" style="width:100%">
          <thead><tr><th>ID</th><th>Nome</th><th>Cognome</th><th>Email</th><th>Telefono</th><th>Clinic</th><th>Sync</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo vr_h($r['name'] ?? ''); ?></td>
              <td><?php echo vr_h($r['surname'] ?? ''); ?></td>
              <td><?php echo vr_h($r['email'] ?? ''); ?></td>
              <td><?php echo vr_h($r['phone'] ?? ''); ?></td>
              <td><?php echo (int)($r['source_clinic_id'] ?? 0); ?></td>
              <td><?php echo vr_h($r['synced_at'] ?? ''); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <?php elseif ($page === 'cookies'): ?>
    <?php
      $selected = trim((string)($_GET['consent_id'] ?? ''));
      $consents = $db->query("SELECT * FROM cookie_consents ORDER BY last_seen DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Profilazione cookie (Landing)</h3>
      <p style="opacity:.85">Raccoglie i dati dei cookie di profilazione (solo con consenso) e gli eventi (consenso, pageview, click). Da qui puoi anche eliminare i dati.</p>

      <div style="overflow:auto">
        <table class="vr-table" style="width:100%">
          <thead>
            <tr><th>Consent ID</th><th>Profilazione</th><th>First</th><th>Last</th><th>Last path</th><th>IP</th><th>Azioni</th></tr>
          </thead>
          <tbody>
          <?php foreach ($consents as $c): ?>
            <tr>
              <td style="font-family:monospace;font-size:12px;white-space:nowrap"><?php echo vr_h($c['consent_id']); ?></td>
              <td><?php echo (int)($c['profiling'] ?? 0) ? 'SI' : 'NO'; ?></td>
              <td><?php echo vr_h($c['first_seen'] ?? ''); ?></td>
              <td><?php echo vr_h($c['last_seen'] ?? ''); ?></td>
              <td><?php echo vr_h($c['last_path'] ?? ''); ?></td>
              <td><?php echo vr_h($c['ip'] ?? ''); ?></td>
              <td style="white-space:nowrap">
                <a class="vr-btn vr-btn-secondary" href="platform.php?page=cookies&consent_id=<?php echo urlencode($c['consent_id']); ?>">Dettagli</a>
                <form method="post" style="display:inline-block">
                  <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
                  <input type="hidden" name="consent_id" value="<?php echo vr_h($c['consent_id']); ?>">
                  <button class="vr-btn vr-btn-secondary" type="submit" name="action" value="cookie_delete_consent" onclick="return confirm('Eliminare tutti i dati per questo consent?');">Elimina</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($selected !== ''): ?>
        <h4 style="margin-top:14px;">Eventi per: <span style="font-family:monospace"><?php echo vr_h($selected); ?></span></h4>
        <?php
          $st = $db->prepare("SELECT * FROM cookie_events WHERE consent_id=? ORDER BY created_at DESC LIMIT 500");
          $st->execute([$selected]);
          $events = $st->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div style="overflow:auto">
          <table class="vr-table" style="width:100%">
            <thead><tr><th>Quando</th><th>Tipo</th><th>Pagina</th><th>x</th><th>y</th><th>Viewport</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e): ?>
              <tr>
                <td><?php echo vr_h($e['created_at'] ?? ''); ?></td>
                <td><?php echo vr_h($e['event_type'] ?? ''); ?></td>
                <td><?php echo vr_h($e['page_path'] ?? ''); ?></td>
                <td><?php echo isset($e['x_percent']) ? vr_h((string)$e['x_percent']) : ''; ?></td>
                <td><?php echo isset($e['y_percent']) ? vr_h((string)$e['y_percent']) : ''; ?></td>
                <td><?php echo vr_h((string)($e['viewport_w'] ?? '') . 'x' . (string)($e['viewport_h'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($page === 'heatmap'): ?>
    <?php
      $paths = $db->query("SELECT page_path, COUNT(*) AS cnt FROM cookie_events WHERE event_type='click' AND page_path IS NOT NULL AND page_path<>'' GROUP BY page_path ORDER BY cnt DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
      $selPath = (string)($_GET['path'] ?? ($paths[0]['page_path'] ?? '/'));
      $selPath = $selPath === '' ? '/' : $selPath;
      $st = $db->prepare("SELECT x_percent, y_percent FROM cookie_events WHERE event_type='click' AND page_path=? AND x_percent IS NOT NULL AND y_percent IS NOT NULL LIMIT 5000");
      $st->execute([$selPath]);
      $pts = $st->fetchAll(PDO::FETCH_ASSOC);
      $ptsJson = json_encode($pts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($ptsJson)) $ptsJson = '[]';
    ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Heatmap (Landing)</h3>
      <p style="opacity:.85">Visualizzazione semplificata basata sui click (solo utenti che hanno accettato i cookie di profilazione).</p>
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
        <input type="hidden" name="page" value="heatmap">
        <label style="font-weight:800;">Pagina</label>
        <select class="vr-input" name="path">
          <?php foreach ($paths as $p): ?>
            <option value="<?php echo vr_h($p['page_path']); ?>" <?php echo ($selPath===$p['page_path'])?'selected':''; ?>><?php echo vr_h($p['page_path']); ?> (<?php echo (int)$p['cnt']; ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="vr-btn" type="submit">Aggiorna</button>
      </form>
      <div style="opacity:.85;margin-bottom:10px;">Click raccolti: <?php echo count($pts); ?></div>
      <div style="overflow:auto;border:1px solid rgba(0,0,0,0.08);border-radius:12px;">
        <canvas id="hm" width="900" height="2000" style="width:900px;height:2000px;"></canvas>
      </div>
      <script>
        (function(){
          const pts = <?php echo $ptsJson; ?> || [];
          const c = document.getElementById('hm');
          if (!c) return;
          const ctx = c.getContext('2d');
          // background
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0,0,c.width,c.height);
          // grid
          ctx.globalAlpha = 0.08;
          ctx.strokeStyle = '#000000';
          for (let y=0;y<=c.height;y+=200){ ctx.beginPath(); ctx.moveTo(0,y); ctx.lineTo(c.width,y); ctx.stroke(); }
          for (let x=0;x<=c.width;x+=150){ ctx.beginPath(); ctx.moveTo(x,0); ctx.lineTo(x,c.height); ctx.stroke(); }
          // points
          ctx.globalAlpha = 0.06;
          ctx.fillStyle = '#ff0000';
          pts.forEach(p => {
            const x = (typeof p.x_percent === 'number') ? (p.x_percent * c.width) : null;
            const y = (typeof p.y_percent === 'number') ? (p.y_percent * c.height) : null;
            if (x===null || y===null) return;
            ctx.beginPath();
            ctx.arc(x, y, 16, 0, Math.PI*2);
            ctx.fill();
          });
          ctx.globalAlpha = 1;
          ctx.fillStyle = '#000000';
          ctx.font = '16px system-ui, sans-serif';
          ctx.fillText('Heatmap click (<?php echo vr_h($selPath); ?>)', 12, 26);
        })();
      </script>
    </div>

  <?php elseif ($page === 'security'): ?>
    <?php
      $me = $db->prepare("SELECT * FROM platform_users WHERE id=? LIMIT 1");
      $me->execute([(int)$admin['id']]);
      $meRow = $me->fetch(PDO::FETCH_ASSOC) ?: [];
      $secretQ = (string)($meRow['secret_question'] ?? '');
      $codes = vr_platform_twofa_get_codes($db, (int)$admin['id']);
      $showCodes = $_SESSION['vetroom_platform_show_codes'] ?? null;
      if (!is_array($showCodes)) $showCodes = null;
    ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Sicurezza admin</h3>
      <p style="opacity:.85">2FA (tabella codici) + recupero password via domanda segreta. Consiglio: scarica e conserva la tabella codici in un luogo sicuro.</p>

      <div class="vr-card vr-collapsible" style="padding:12px;margin-top:12px;">
        <h4 style="margin:0 0 8px;">Domanda segreta (recupero password)</h4>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
          <label>Domanda segreta</label>
          <input class="vr-input" name="secret_question" value="<?php echo vr_h($secretQ); ?>" required>
          <label>Risposta segreta</label>
          <input class="vr-input" name="secret_answer" type="password" required>
          <button class="vr-btn" type="submit" name="action" value="update_platform_secret">Salva</button>
        </form>
      </div>

      <div class="vr-card vr-collapsible" style="padding:12px;margin-top:12px;">
        <h4 style="margin:0 0 8px;">2FA (tabella codici)</h4>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <a class="vr-btn vr-btn-secondary" href="platform_twofa_export.php?mode=current">Scarica tabella codici</a>
          <form method="post" style="display:inline-block">
            <input type="hidden" name="csrf_token" value="<?php echo vr_csrf_token(); ?>">
            <button class="vr-btn" type="submit" name="action" value="regenerate_twofa" onclick="return confirm('Rigenerare la tabella codici? Quella precedente non sarà più valida.');">Rigenera tabella codici</button>
          </form>
        </div>

        <?php if ($showCodes): ?>
          <div class="vr-alert vr-alert-success" style="margin-top:12px;">Nuova tabella codici generata. Scaricala ora.</div>
        <?php endif; ?>

        <details style="margin-top:12px;">
          <summary><b>Mostra tabella codici (sensibile)</b></summary>
          <div style="overflow:auto;margin-top:10px;">
            <table class="vr-table" style="width:360px;">
              <thead><tr><th>#</th><th>Codice</th></tr></thead>
              <tbody>
              <?php foreach ($codes as $i=>$code): ?>
                <tr><td><?php echo (int)$i; ?></td><td style="font-family:monospace;font-size:16px;"><?php echo vr_h($code); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </div>

  <?php elseif ($page === 'invites'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Genera invito</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
        <input type="hidden" name="action" value="create_invite">
        <label>Email del CHIEF (responsabile struttura)</label>
        <input class="vr-input" type="email" name="invite_email" required>
        <label>Scadenza (giorni)</label>
        <input class="vr-input" type="number" name="invite_days" min="1" max="14" value="2" required>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:10px;">
          <div style="flex:1;min-width:220px;">
            <label>Numero max veterinari</label>
            <input class="vr-input" type="number" name="invite_max_vets" min="1" max="50" value="1" required>
          </div>
          <div style="flex:1;min-width:220px;">
            <label>Numero max segretarie</label>
            <input class="vr-input" type="number" name="invite_max_secretaries" min="0" max="200" value="0" required>
          </div>
        </div>
        <button class="vr-btn" type="submit">Crea invito</button>
      </form>

      <?php if ($lastToken): ?>
        <div class="vr-alert vr-alert-info" style="margin-top:10px;">
          Link invito (copia e invia): <code><?php echo vr_h((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST'] ?? '').dirname($_SERVER['SCRIPT_NAME']).'/register.php?token='.$lastToken); ?></code>
        </div>
      <?php endif; ?>

      <h3 style="margin-top:18px;">Inviti recenti</h3>
      <?php
        $rows = $db->query("SELECT * FROM invitations ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "<p>Nessun invito.</p>"; }
        else {
            echo "<div style='overflow:auto;'><table class='vr-table' style='width:100%'><thead><tr><th>Email</th><th>Limiti</th><th>Scadenza</th><th>Usato</th><th>Token</th></tr></thead><tbody>";
            foreach ($rows as $r) {
                $lv = (int)($r['max_vets'] ?? 1);
                $ls = (int)($r['max_secretaries'] ?? 0);
                $lim = "VET={$lv}, SEG={$ls}";
                echo "<tr><td>".vr_h($r['email'])."</td><td>".vr_h($lim)."</td><td>".vr_h($r['expires_at'])."</td><td>".vr_h($r['used_at']?:'—')."</td><td><code>".vr_h(substr($r['token'],0,12))."…</code></td></tr>";
            }
            echo "</tbody></table></div>";
        }
      ?>
    </div>

  <?php elseif ($page === 'applications'): ?>
    <div class="vr-card vr-collapsible" style="padding:14px;">
      <h3>Richieste di onboarding</h3>
      <?php
        $rows = $db->query("SELECT * FROM clinic_applications ORDER BY created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "<p>Nessuna richiesta.</p>"; }
        else {
            echo "<div style='overflow:auto;'><table class='vr-table' style='width:100%'><thead><tr><th>ID</th><th>Email</th><th>Stato</th><th>Creato</th><th>Azioni</th></tr></thead><tbody>";
            foreach ($rows as $r) {
                echo "<tr>
                  <td>".(int)$r['id']."</td>
                  <td>".vr_h($r['email'])."</td>
                  <td>".vr_h($r['status'])."</td>
                  <td>".vr_h($r['created_at'])."</td>
                  <td><a class='vr-btn vr-btn-secondary' href='platform.php?page=application_view&id=".(int)$r['id']."'>Apri</a></td>
                </tr>";
            }
            echo "</tbody></table></div>";
        }
      ?>
    </div>

  <?php elseif ($page === 'application_view'): ?>
    <?php
      $id = (int)($_GET['id'] ?? 0);
      $st = $db->prepare("SELECT * FROM clinic_applications WHERE id=? LIMIT 1");
      $st->execute([$id]);
      $app = $st->fetch(PDO::FETCH_ASSOC);
      if (!$app) { echo "<div class='vr-alert vr-alert-error'>Richiesta non trovata.</div>"; }
      else {
        $data = json_decode($app['data_json'], true) ?: [];
        $docs = $data['docs'] ?? [];
        echo "<div class='vr-card' style='padding:14px;'>";
        echo "<h3>Richiesta #".(int)$app['id']."</h3>";
        echo "<p><b>Email:</b> ".vr_h($app['email'])."</p>";
        echo "<p><b>Stato:</b> ".vr_h($app['status'])."</p>";
        echo "<h4>Dati struttura</h4>";
        echo "<pre style='white-space:pre-wrap;background:#f7f7f7;padding:10px;border-radius:8px;'>".vr_h(json_encode($data['profile'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))."</pre>";
        echo "<h4>Documenti</h4>";
        if (!$docs) echo "<p>Nessun documento trovato.</p>";
        else {
            echo "<ul>";
            foreach ($docs as $k=>$path) {
                $safe = basename($path);
                echo "<li>".vr_h($k).": <a href='download_admin.php?app_id=".(int)$app['id']."&file=".urlencode($safe)."'>Scarica</a></li>";
            }
            echo "</ul>";
        }

        $stato = (string)($app['status'] ?? '');
        if ($stato === 'PENDING') {
            ?>
            <form method="post" style="margin-top:14px;">
              <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
              <input type="hidden" name="app_id" value="<?php echo (int)$app['id']; ?>">
              <label>Note (opzionale)</label>
              <input class="vr-input" name="notes" placeholder="Note approvazione/rifiuto">
              <div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">
                <button class="vr-btn" name="action" value="approve_application" type="submit">Approva e attiva</button>
                <button class="vr-btn vr-btn-secondary" name="action" value="reject_application" type="submit">Rifiuta</button>
              </div>
            </form>
            <?php
        } elseif (in_array($stato, ['APPROVED','SUSPENDED','REVOKED'], true)) {
            ?>
            <form method="post" style="margin-top:14px;">
              <input type="hidden" name="csrf_token" value="<?php echo vr_h(vr_csrf_token()); ?>">
              <input type="hidden" name="app_id" value="<?php echo (int)$app['id']; ?>">
              <label>Note (opzionale)</label>
              <input class="vr-input" name="notes" placeholder="Note (es. motivo sospensione/revoca)">
              <div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">
                <?php if ($stato === 'APPROVED'): ?>
                  <button class="vr-btn vr-btn-secondary" name="action" value="suspend_application" type="submit" onclick="return confirm('Sospendere questa struttura?');">Sospendi</button>
                <?php else: ?>
                  <button class="vr-btn" name="action" value="reactivate_application" type="submit" onclick="return confirm('Riattivare questa struttura?');">Riattiva</button>
                <?php endif; ?>
                <button class="vr-btn vr-btn-secondary" name="action" value="revoke_application" type="submit" onclick="return confirm('Revocare definitivamente questa struttura?');">Revoca</button>
              </div>
            </form>
            <?php
        }
        echo "</div>";
      }
    ?>

  <?php else: ?>
    <div class="vr-alert vr-alert-error">Pagina non trovata.</div>
  <?php endif; ?>

</main>
</div>
<div class="vr-admin-overlay" data-vr-admin-overlay aria-hidden="true"></div>
</body>
</html>
