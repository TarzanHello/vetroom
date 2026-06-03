<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

if (!vetroom_is_installed()) {
    header('Location: install.php');
    exit;
}

$db = vetroom_db();

// Simple CSRF token (separate from platform)
if (empty($_SESSION['vr_forgot_csrf'])) {
    $_SESSION['vr_forgot_csrf'] = bin2hex(random_bytes(16));
}
function vr_forgot_csrf_check(): void {
    $t = (string)($_POST['csrf_token'] ?? '');
    if ($t === '' || empty($_SESSION['vr_forgot_csrf']) || !hash_equals((string)$_SESSION['vr_forgot_csrf'], $t)) {
        http_response_code(400);
        echo "CSRF token non valido.";
        exit;
    }
}

$err = null;
$ok = null;
$step = (string)($_POST['step'] ?? '1');
$email = trim(strtolower((string)($_POST['email'] ?? ($_GET['email'] ?? ''))));
$question = '';

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $st = $db->prepare("SELECT id, secret_question FROM users WHERE lower(email)=? LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $question = (string)($row['secret_question'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vr_forgot_csrf_check();
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Inserisci una email valida.';
        $step = '1';
    } else {
        $ip = vr_auth_client_ip();
        if ($ip !== '' && vr_auth_rate_limited($db, $email, $ip, 10, 900)) {
            $err = 'Troppi tentativi. Riprova tra qualche minuto.';
            $step = '1';
        } else {
            $st = $db->prepare("SELECT * FROM users WHERE lower(email)=? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                vr_auth_record_login_attempt($db, $email, $ip, 0);
                $err = 'Account non trovato.';
                $step = '1';
            } else {
                $question = (string)($u['secret_question'] ?? '');
                if ($question === '' || empty($u['secret_answer_hash'])) {
                    $err = 'Recupero non disponibile: contatta l\'amministrazione dello studio per impostare domanda/risposta segreta.';
                    $step = '1';
                } else {
                    if ($step === '2') {
                        $answer = vr_norm_secret_answer((string)($_POST['secret_answer'] ?? ''));
                        $newPass = (string)($_POST['new_password'] ?? '');
                        $newPass2 = (string)($_POST['new_password2'] ?? '');
                        if ($answer === '' || !password_verify($answer, (string)$u['secret_answer_hash'])) {
                            vr_auth_record_login_attempt($db, $email, $ip, 0);
                            $err = 'Risposta segreta non valida.';
                        } elseif (strlen($newPass) < 8) {
                            $err = 'La nuova password deve avere almeno 8 caratteri.';
                        } elseif ($newPass !== $newPass2) {
                            $err = 'Le password non coincidono.';
                        } else {
                            try {
                                $now = vr_now_iso();
                                $db->beginTransaction();
                                $db->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE id=?")
                                   ->execute([password_hash($newPass, PASSWORD_DEFAULT), $now, (int)$u['id']]);
                                // Invalidate active sessions for this user
                                $db->prepare("DELETE FROM active_sessions WHERE type='STAFF' AND user_id=?")
                                   ->execute([(int)$u['id']]);
                                $db->commit();
                                vr_auth_record_login_attempt($db, $email, $ip, 1);
                                $_SESSION['vetroom_notice'] = 'Password aggiornata. Ora puoi accedere.';
                                header('Location: index.php');
                                exit;
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
}

?><!doctype html>
<html lang="it"><head>
  <meta charset="utf-8">
  <title><?php echo vr_h(VETROOM_APP_NAME); ?> - Recupero password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="public/styles.css">
</head><body>
<div class="vr-login-wrap"><div class="vr-login-card" style="max-width:720px;">
  <img src="public/vetroom_logo.png" alt="Logo" class="vr-logo-img">
  <div class="vr-login-title">Recupero password (Studio)</div>
  <div class="vr-login-sub">Identificazione tramite email + domanda segreta.</div>

  <?php if ($err): ?><div class="vr-alert vr-alert-error" style="margin-top:12px;"><?php echo vr_h($err); ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="vr-alert vr-alert-success" style="margin-top:12px;"><?php echo vr_h($ok); ?></div><?php endif; ?>

  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="csrf_token" value="<?php echo vr_h((string)$_SESSION['vr_forgot_csrf']); ?>">
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

  <p style="margin-top:12px;"><a href="index.php">Torna al login</a></p>
</div></div>
</body></html>
