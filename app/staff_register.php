<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

$db = vetroom_db();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') { http_response_code(400); echo "Token mancante."; exit; }

$tokenHash = hash('sha256', $token);
$stmt = $db->prepare("SELECT si.*, c.name AS clinic_name, c.status AS clinic_status, c.is_active AS clinic_is_active, c.max_vets, c.max_secretaries
                      FROM staff_invitations si
                      JOIN clinics c ON c.id = si.clinic_id
                      WHERE si.token_hash = ? LIMIT 1");
$stmt->execute([$tokenHash]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); echo "Invito non valido."; exit; }
if (!empty($inv['used_at'])) { echo "Invito già utilizzato."; exit; }
if (!empty($inv['revoked_at'])) { echo "Invito revocato."; exit; }

try {
    $now = new DateTimeImmutable();
    $exp = new DateTimeImmutable((string)$inv['expires_at']);
    if ($now > $exp) { echo "Invito scaduto."; exit; }
} catch (Throwable $t) {
    echo "Invito non valido."; exit;
}

// PLATFORM invite without clinic association (placeholder SYSTEM clinic in DB)
$roleUp = strtoupper((string)($inv['role'] ?? ''));
$isPlatformInvite = !empty($inv['created_by_platform_user_id']);
$clinicStatusUp = strtoupper((string)($inv['clinic_status'] ?? ''));
$unassigned = ($isPlatformInvite && in_array($roleUp, ['SECRETARY','STAFF'], true) && $clinicStatusUp === 'SYSTEM');

$errors = [];
$done = false;
$createdNeedsValidation = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vr_staff_csrf_is_valid()) {
        $errors[] = 'CSRF token non valido.';
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $secret_q = trim((string)($_POST['secret_question'] ?? ''));
    $secret_a = trim((string)($_POST['secret_answer'] ?? ''));

    if ($name === '') $errors[] = 'Inserisci il nome.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';
    if (strlen($pass) < 8) $errors[] = 'Password minimo 8 caratteri.';
    if ($pass !== $pass2) $errors[] = 'Le password non coincidono.';
    if ($secret_q === '') $errors[] = 'Inserisci una domanda segreta.';
    if ($secret_a === '') $errors[] = 'Inserisci la risposta segreta.';

    // email must match the invitation
    if (strcasecmp($email, (string)$inv['email']) !== 0) {
        $errors[] = 'Questa email non corrisponde all\'invito.';
    }

    $role = $roleUp;
    if (!in_array($role, ['VET','SECRETARY','STAFF','READONLY'], true)) {
        $role = 'STAFF';
        $roleUp = 'STAFF';
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $ts = vr_now_iso();
            $clinicId = (int)$inv['clinic_id'];

            // Enforce quotas (active users only). Use clinic current limits (source of truth).
            // NOTE: PLATFORM-created secretaries start UNASSIGNED (no clinic membership yet), so quotas are not enforced here.
            if (!$unassigned) {
            $maxVets = 1;
            $maxSecs = 0;
            $row = $db->prepare("SELECT max_vets, max_secretaries FROM clinics WHERE id=? LIMIT 1");
            $row->execute([$clinicId]);
            $cl = $row->fetch(PDO::FETCH_ASSOC);
            if ($cl) {
                $maxVets = max(1, (int)$cl['max_vets']);
                $maxSecs = max(0, (int)$cl['max_secretaries']);
            }

            if ($role === 'VET') {
                // Count CHIEF as a veterinarian slot as well.
                $cnt = (int)($db->query("SELECT COUNT(1)
                                         FROM user_clinic uc
                                         JOIN users u ON u.id = uc.user_id
                                         WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('CHIEF','VET')")->fetchColumn() ?: 0);
                if ($cnt >= $maxVets) {
                    throw new Exception('Quota veterinari raggiunta. Chiedi ad ADMIN di aumentare la quota.');
                }
            }
            if ($role === 'SECRETARY') {
                $cnt = (int)($db->query("SELECT COUNT(1)
                                         FROM user_clinic uc
                                         JOIN users u ON u.id = uc.user_id
                                         WHERE uc.clinic_id=".$clinicId." AND uc.is_active=1 AND u.is_active=1 AND UPPER(uc.role) IN ('SECRETARY','STAFF')")->fetchColumn() ?: 0);
                if ($cnt >= $maxSecs) {
                    throw new Exception('Quota segreteria raggiunta. Chiedi ad ADMIN di aumentare la quota.');
                }
            }

            }

            // Secretary multi-clinic max (set by ADMIN at invite time). Default 1.
            $maxClinics = 1;
            if (in_array($role, ['SECRETARY','STAFF'], true)) {
                $maxClinics = max(1, (int)($inv['max_clinics'] ?? 1));
            }

            // Create user
            // NOTE: SECRETARY/STAFF accounts require PLATFORM validation before login.
            $userIsActive = 1;
            if (in_array($role, ['SECRETARY','STAFF'], true)) {
                $userIsActive = 0;
                $createdNeedsValidation = true;
            }

            $stmtU = $db->prepare("INSERT INTO users (clinic_id,name,email,password_hash,secret_question,secret_answer_hash,role,max_clinics,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmtU->execute([
                $clinicId,
                $name,
                strtolower($email),
                password_hash($pass, PASSWORD_DEFAULT),
                $secret_q,
                password_hash(vr_norm_secret_answer($secret_a), PASSWORD_DEFAULT),
                $role,
                $maxClinics,
                $userIsActive,
                $ts,
                $ts
            ]);
            $userId = (int)$db->lastInsertId();

            // Pivot (membership)
            if (!$unassigned) {
                $db->prepare("INSERT OR IGNORE INTO user_clinic (user_id,clinic_id,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$userId,$clinicId,$role,1,$ts,$ts]);
            }

            // Mark invitation used (+ link to created user for PLATFORM tracking/validation)
            $db->prepare("UPDATE staff_invitations SET used_at=?, used_by_user_id=? WHERE id=?")
               ->execute([$ts, $userId, (int)$inv['id']]);

            $db->commit();
            $done = true;
        } catch (Throwable $t) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = $t->getMessage();
        }
    }
}

?><!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Invito Staff - <?php echo vr_h(VETROOM_APP_NAME); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="public/styles.css">
    <script src="public/ux.js" defer></script>
</head>
<body>
<div class="vr-login-wrap"><div class="vr-login-card" style="max-width:720px;">

    <div class="vr-login-title">Benvenuto nello staff</div>
    <div class="vr-login-sub">
        <?php if ($unassigned): ?>
        Stai creando il tuo account come <b><?php echo vr_h(strtoupper((string)$inv['role'])); ?></b>.
        <br><span style="color:#666;">Nessuna clinica associata al momento: diventerai operativa dopo la prima membership (QR/link + OTP dal CHIEF).</span>
      <?php else: ?>
        Stai entrando nella struttura <b><?php echo vr_h((string)$inv['clinic_name']); ?></b> come
        <b><?php echo vr_h(strtoupper((string)$inv['role'])); ?></b>.
      <?php endif; ?>
    </div>

    <?php if ($done): ?>
        <div class="vr-alert vr-alert-success" style="margin-top:12px;">
            Account creato.
            <?php if ($createdNeedsValidation): ?>
                <br><span style="color:#666;">Il profilo deve essere validato da <b>ADMIN</b> (piattaforma) prima di poter effettuare il login.</span>
            <?php else: ?>
                Ora puoi accedere dal login VetRoom.
            <?php endif; ?>
            <?php if ($unassigned && !$createdNeedsValidation): ?>
                <br><span style="color:#666;">Dopo il login vai su <b>Cliniche</b> per generare QR/link di membership e farti attivare dal CHIEF.</span>
            <?php endif; ?>
        </div>
        <p style="margin-top:12px;"><a class="vr-btn" href="index.php?login=1">Vai al login</a></p>
    <?php else: ?>
        <?php if ($errors): ?>
            <div class="vr-alert vr-alert-error" style="margin-top:12px;">
                <?php foreach ($errors as $e): ?><div><?php echo vr_h($e); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" style="margin-top:12px;">
            <?php echo vr_staff_csrf_field(); ?>
            <label>Nome e cognome</label>
            <input class="vr-input" name="name" value="<?php echo vr_h((string)($_POST['name'] ?? '')); ?>" required>
            <label>Email</label>
            <input class="vr-input" name="email" type="email" value="<?php echo vr_h((string)($_POST['email'] ?? (string)$inv['email'])); ?>" required>
            <label>Password</label>
            <input class="vr-input" name="password" type="password" required>
            <label>Ripeti password</label>
            <input class="vr-input" name="password2" type="password" required>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:240px;">
                    <label>Domanda segreta</label>
                    <input class="vr-input" name="secret_question" required placeholder="Es. Nome del tuo primo animale" value="<?php echo vr_h((string)($_POST['secret_question'] ?? '')); ?>">
                </div>
                <div style="flex:1;min-width:240px;">
                    <label>Risposta segreta</label>
                    <input class="vr-input" name="secret_answer" required placeholder="Risposta" value="<?php echo vr_h((string)($_POST['secret_answer'] ?? '')); ?>">
                </div>
            </div>
            <button class="vr-btn" type="submit" style="margin-top:10px;">Crea account</button>
        </form>
    <?php endif; ?>

</div></div>
</body>
</html>
