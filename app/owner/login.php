<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';

// If already logged in, go to dashboard.
if (vetroom_owner_current_user()) {
    header('Location: index.php');
    exit;
}

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vr_owner_csrf_is_valid()) {
        $err = 'Richiesta non valida. Ricarica la pagina e riprova.';
    } else {
        $res = vetroom_login_owner((string)($_POST['fiscal_code'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res === true) {
            header('Location: index.php');
            exit;
        }
        $err = (string)$res;
    }
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title><?php echo vr_h(VETROOM_APP_NAME); ?> - Area Proprietario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../public/styles.css">
</head>
<body>
<div class="vr-login-wrap">
  <div class="vr-login-card">
    <div class="vr-login-title">Area Proprietario</div>
    <div class="vr-login-sub">Accedi con Codice Fiscale e password.</div>

    <?php if ($err): ?>
      <div class="vr-alert vr-alert-error" style="margin-top:10px;"><?php echo vr_h($err); ?></div>
    <?php endif; ?>

    <form method="post" style="margin-top:12px;">
      <?php echo vr_owner_csrf_field(); ?>

      <label>Codice Fiscale</label>
      <input class="vr-input" name="fiscal_code" maxlength="16" required autocomplete="username" value="<?php echo vr_h((string)($_POST['fiscal_code'] ?? '')); ?>">

      <label>Password</label>
      <input class="vr-input" type="password" name="password" required autocomplete="current-password">

      <button class="vr-btn" type="submit" style="margin-top:10px;">Accedi</button>
    </form>

    <p style="margin-top:12px; font-size:13px;">
      Non hai un account? <a href="register.php">Registrati</a>
    </p>

    <p style="margin-top:6px; font-size:13px;">
      <a href="../index.php">Vai al gestionale staff</a>
    </p>
  </div>
</div>
</body>
</html>
