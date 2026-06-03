<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/mailer.php';

$db = vetroom_db();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo "Token mancante.";
    exit;
}

$tokenHash = hash('sha256', $token);

$stmt = $db->prepare("SELECT t.*, 
                             u.name AS sec_name,
                             u.email AS sec_email,
                             u.is_active AS sec_active,
                             COALESCE(u.max_clinics,1) AS sec_max_clinics
                      FROM secretary_membership_tokens t
                      JOIN users u ON u.id = t.secretary_user_id
                      WHERE t.token_hash = ?
                      LIMIT 1");
$stmt->execute([$tokenHash]);
$tok = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tok) {
    http_response_code(404);
    echo "Link non valido.";
    exit;
}

$now = new DateTimeImmutable();
$expired = false;
try {
    $exp = new DateTimeImmutable((string)$tok['expires_at']);
    $expired = ($now > $exp);
} catch (Throwable $t) {
    $expired = true;
}

// Require login
$user = vetroom_current_user();
if (!$user) {
    // after-login redirect
    $_SESSION['vetroom_after_login'] = 'membership.php?token=' . urlencode($token);
    header('Location: index.php?login=1');
    exit;
}

$roleUp = strtoupper((string)($user['role'] ?? ''));
if ($roleUp !== 'CHIEF') {
    http_response_code(403);
    echo "Solo il CHIEF può attivare una membership.";
    exit;
}

// If the token was generated for a specific CHIEF email, enforce it.
$tokChiefEmail = strtolower(trim((string)($tok['chief_email'] ?? '')));
if ($tokChiefEmail !== '') {
    $uEmail = strtolower(trim((string)($user['email'] ?? '')));
    if ($uEmail === '' || $uEmail !== $tokChiefEmail) {
        http_response_code(403);
        echo "Questo link è riservato al CHIEF: " . vr_h($tokChiefEmail) . ".";
        exit;
    }
}

$clinicId = (int)($user['clinic_id'] ?? 0);
$clinicRow = null;
if ($clinicId > 0) {
    $stC = $db->prepare("SELECT id,name,max_secretaries,is_active,status FROM clinics WHERE id=? LIMIT 1");
    $stC->execute([$clinicId]);
    $clinicRow = $stC->fetch(PDO::FETCH_ASSOC) ?: null;
}

$errors = [];
$success = '';
$otpSent = false;

// Helper: send OTP to the CHIEF email.
$sendOtp = function() use (&$tok, $db, $now, $user, $clinicRow, $tokenHash, $stmt, &$otpSent, &$success, &$errors) {
    // rate limit: 30s between sends
    $lastSent = (string)($tok['otp_sent_at'] ?? '');
    if ($lastSent !== '') {
        try {
            $ls = new DateTimeImmutable($lastSent);
            if ($now->getTimestamp() - $ls->getTimestamp() < 30) {
                return;
            }
        } catch (Throwable $t) {
            // ignore
        }
    }

    $code = (string)random_int(100000, 999999);
    $otpHash = password_hash($code, PASSWORD_DEFAULT);
    $otpExp = $now->modify('+10 minutes')->format(DateTimeInterface::ATOM);
    $ts = vr_now_iso();

    $db->prepare("UPDATE secretary_membership_tokens
                  SET otp_code_hash=?, otp_expires_at=?, otp_sent_at=?
                  WHERE id=?")
       ->execute([$otpHash, $otpExp, $ts, (int)$tok['id']]);

    $subj = 'VetRoom - Codice di attivazione segretaria';
    $body = "Ciao " . (string)($user['name'] ?? '') . ",\n\n".
            "Stai attivando la segretaria " . (string)($tok['sec_name'] ?? '') . " per la clinica \"" . (string)($clinicRow['name'] ?? '') . "\".\n\n".
            "Codice OTP: " . $code . "\n".
            "Scade tra 10 minuti.\n\n".
            "Se non riconosci questa richiesta, ignora la mail.";

    vr_mail_send((string)($user['email'] ?? ''), $subj, $body);

    // reload token state
    $stmt->execute([$tokenHash]);
    $tok = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tok;
    $otpSent = true;
    $success = 'Abbiamo inviato un codice OTP alla tua email.';
};

// AUTO: when the CHIEF opens the link, we send the OTP automatically (best-effort).
if (!$errors && empty($tok['used_at'])) {
    $otpExpRaw = (string)($tok['otp_expires_at'] ?? '');
    $hasValidOtp = false;
    if ($otpExpRaw !== '' && (string)($tok['otp_code_hash'] ?? '') !== '') {
        try {
            $otpExp = new DateTimeImmutable($otpExpRaw);
            if ($now <= $otpExp) $hasValidOtp = true;
        } catch (Throwable $t) {
            $hasValidOtp = false;
        }
    }
    if (!$hasValidOtp) {
        try { $sendOtp(); } catch (Throwable $t) { /* ignore */ }
    }
}

// Validate token usable
if (!empty($tok['used_at'])) {
    $errors[] = 'Questo link è già stato utilizzato.';
}
if ($expired) {
    $errors[] = 'Questo link è scaduto. Chiedi alla segretaria di generare un nuovo QR/link.';
}
if ((int)($tok['sec_active'] ?? 0) !== 1) {
    $errors[] = 'Account segretaria non attivo (sospeso o chiuso da ADMIN).';
}
if (!$clinicRow || (int)($clinicRow['is_active'] ?? 0) !== 1 || strtoupper((string)($clinicRow['status'] ?? '')) !== 'ACTIVE') {
    $errors[] = 'La clinica corrente non è attiva o non è approvata.';
}

// Ensure current user is CHIEF for this clinic
if (!$errors) {
    $stR = $db->prepare("SELECT role FROM user_clinic WHERE user_id=? AND clinic_id=? AND is_active=1 LIMIT 1");
    $stR->execute([(int)$user['id'], $clinicId]);
    $m = $stR->fetch(PDO::FETCH_ASSOC);
    if (!$m || strtoupper((string)($m['role'] ?? '')) !== 'CHIEF') {
        $errors[] = 'Sessione non valida per questa clinica. Torna in VetRoom e seleziona la clinica corretta.';
    }
}

function vr_count_active_secretary_memberships(PDO $db, int $secUserId): int {
    $q = $db->prepare("SELECT COUNT(1)
                      FROM user_clinic uc
                      JOIN users u ON u.id = uc.user_id
                      JOIN clinics c ON c.id = uc.clinic_id
                      WHERE uc.user_id=?
                        AND uc.is_active=1
                        AND u.is_active=1
                        AND c.is_active=1
                        AND UPPER(c.status)='ACTIVE'
                        AND UPPER(uc.role) IN ('SECRETARY','STAFF')");
    $q->execute([$secUserId]);
    return (int)($q->fetchColumn() ?: 0);
}

function vr_count_active_secretaries_in_clinic(PDO $db, int $clinicId): int {
    $q = $db->prepare("SELECT COUNT(1)
                      FROM user_clinic uc
                      JOIN users u ON u.id = uc.user_id
                      WHERE uc.clinic_id=?
                        AND uc.is_active=1
                        AND u.is_active=1
                        AND UPPER(uc.role) IN ('SECRETARY','STAFF')");
    $q->execute([$clinicId]);
    return (int)($q->fetchColumn() ?: 0);
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $fa = (string)($_POST['form_action'] ?? '');
    if (!vr_staff_csrf_is_valid()) {
        $errors[] = 'CSRF token non valido.';
    } else {
        if ($fa === 'send_otp') {
            // Manual resend
            if (!$errors) {
                $sendOtp();
            }
        }

        if ($fa === 'verify_otp') {
            $inCode = trim((string)($_POST['otp_code'] ?? ''));
            if ($inCode === '' || strlen($inCode) < 4) {
                $errors[] = 'Inserisci il codice ricevuto via email.';
            } else {
                $otpHash = (string)($tok['otp_code_hash'] ?? '');
                $otpExpRaw = (string)($tok['otp_expires_at'] ?? '');
                $otpOk = false;

                if ($otpHash === '' || $otpExpRaw === '') {
                    $errors[] = 'Prima invia il codice OTP.';
                } else {
                    try {
                        $otpExp = new DateTimeImmutable($otpExpRaw);
                        if ($now > $otpExp) {
                            $errors[] = 'Codice scaduto. Invia un nuovo codice.';
                        } else {
                            if (password_verify($inCode, $otpHash)) {
                                $otpOk = true;
                            } else {
                                $errors[] = 'Codice non valido.';
                            }
                        }
                    } catch (Throwable $t) {
                        $errors[] = 'Codice non valido.';
                    }
                }

                if ($otpOk) {
                    try {
                        $db->beginTransaction();

                        // Re-check quotas at activation time
                        $secUserId = (int)($tok['secretary_user_id'] ?? 0);
                        $ts = vr_now_iso();

                        // Idempotent: if already active for this clinic, just consume the token.
                        $chk = $db->prepare("SELECT id,is_active FROM user_clinic WHERE user_id=? AND clinic_id=? LIMIT 1");
                        $chk->execute([$secUserId, $clinicId]);
                        $ex = $chk->fetch(PDO::FETCH_ASSOC);
                        if ($ex && (int)($ex['is_active'] ?? 0) === 1) {
                            $db->prepare("UPDATE secretary_membership_tokens
                                          SET used_at=?, used_by_user_id=?, used_clinic_id=?
                                          WHERE id=?")
                               ->execute([$ts, (int)$user['id'], $clinicId, (int)$tok['id']]);

                            $db->commit();
                            $success = 'Membership già attiva: token registrato con successo.';

                            // Refresh token
                            $stmt->execute([$tokenHash]);
                            $tok = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tok;
                        } else {
                            $secMax = max(1, (int)($tok['sec_max_clinics'] ?? 1));
                            $activeM = vr_count_active_secretary_memberships($db, $secUserId);
                            if ($activeM >= $secMax) {
                                throw new Exception('La segretaria ha raggiunto il limite di cliniche (max_clinics). Contatta ADMIN.');
                            }

                            $maxSecs = max(0, (int)($clinicRow['max_secretaries'] ?? 0));
                            $usedSecs = vr_count_active_secretaries_in_clinic($db, $clinicId);
                            if ($usedSecs >= $maxSecs) {
                                throw new Exception('Quota segreteria della clinica raggiunta. Chiedi ad ADMIN di aumentare la quota.');
                            }

                            // Activate membership for this clinic
                            if ($ex) {
                                $db->prepare("UPDATE user_clinic SET role='SECRETARY', is_active=1, updated_at=? WHERE id=?")
                                   ->execute([$ts, (int)$ex['id']]);
                            } else {
                                $db->prepare("INSERT INTO user_clinic (user_id,clinic_id,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?)")
                                   ->execute([$secUserId, $clinicId, 'SECRETARY', 1, $ts, $ts]);
                            }
                        }

                        if ($success === '') {
                            // Mark token used (activation path)
                            $db->prepare("UPDATE secretary_membership_tokens
                                          SET used_at=?, used_by_user_id=?, used_clinic_id=?
                                          WHERE id=?")
                               ->execute([$ts, (int)$user['id'], $clinicId, (int)$tok['id']]);

                            $db->commit();
                            $success = 'Membership attivata. La segretaria ora è abilitata per questa clinica.';

                            // Refresh token
                            $stmt->execute([$tokenHash]);
                            $tok = $stmt->fetch(PDO::FETCH_ASSOC) ?: $tok;
                        }
                    } catch (Throwable $t) {
                        if ($db->inTransaction()) $db->rollBack();
                        $errors[] = $t->getMessage();
                    }
                }
            }
        }
    }
}

?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Attiva membership - <?php echo vr_h(VETROOM_APP_NAME); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="public/styles.css">
    <script src="public/ux.js" defer></script>
</head>
<body>
<div class="vr-login-wrap"><div class="vr-login-card" style="max-width:820px;">

    <div class="vr-login-title">Attiva membership segretaria</div>
    <div class="vr-login-sub" style="line-height:1.35;">
        Stai attivando <b><?php echo vr_h((string)($tok['sec_name'] ?? '')); ?></b> (<span style="color:#444"><?php echo vr_h((string)($tok['sec_email'] ?? '')); ?></span>)
        per la clinica <b><?php echo vr_h((string)($clinicRow['name'] ?? '')); ?></b>.
    </div>

    <?php if ($success !== ''): ?>
        <div class="vr-alert vr-alert-success" style="margin-top:12px;"><?php echo vr_h($success); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="vr-alert vr-alert-error" style="margin-top:12px;">
            <?php foreach ($errors as $e): ?><div><?php echo vr_h((string)$e); ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$errors && empty($tok['used_at'])): ?>
        <div class="vr-panel" style="margin-top:14px;">
            <div style="font-weight:800;margin-bottom:6px;">Codice OTP</div>
            <div style="color:#444;line-height:1.4;">
                Il codice OTP viene inviato <b>automaticamente</b> alla tua email (<b><?php echo vr_h((string)($user['email'] ?? '')); ?></b>) quando apri questo link.
                Se non lo ricevi, puoi reinviarlo.
            </div>
            <form method="post" style="margin-top:10px;">
                <?php echo vr_staff_csrf_field(); ?>
                <input type="hidden" name="form_action" value="send_otp">
                <button class="vr-btn" type="submit">Reinvia codice</button>
            </form>
        </div>

        <div class="vr-panel" style="margin-top:14px;">
            <div style="font-weight:800;margin-bottom:6px;">Step 2 - Inserisci OTP</div>
            <form method="post" style="margin-top:8px;">
                <?php echo vr_staff_csrf_field(); ?>
                <input type="hidden" name="form_action" value="verify_otp">
                <label>Codice OTP (6 cifre)</label>
                <input class="vr-input" name="otp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" style="max-width:220px;" required>
                <button class="vr-btn" type="submit" style="margin-top:10px;">Attiva membership</button>
            </form>
            <?php
                $otpExp = (string)($tok['otp_expires_at'] ?? '');
                if ($otpExp !== '') {
                    echo '<div style="margin-top:8px;color:#666;font-size:13px;">OTP valido fino a: ' . vr_h(vr_fmt_date($otpExp)) . '</div>';
                }
            ?>
        </div>

        <div style="margin-top:14px;">
            <a class="vr-link" href="index.php?page=team">Torna a Team</a>
        </div>
    <?php endif; ?>

</div></div>
</body>
</html>
