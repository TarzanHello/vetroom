<?php
ini_set('display_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Requisiti minimi: PDO SQLite e cartella dati scrivibile
$__fatal = null;
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $__fatal = "Estensione PDO SQLite non disponibile: abilita 'pdo_sqlite' nel PHP del server.";
}
$__dataDir = dirname(VETROOM_DB_PATH);
if (!is_dir($__dataDir)) { @mkdir($__dataDir, 0775, true); }
if (!is_writable($__dataDir)) {
    @chmod($__dataDir, 0775);
    if (!is_writable($__dataDir)) @chmod($__dataDir, 0777);
}
if (!$__fatal && !is_writable($__dataDir)) {
    $__fatal = "La cartella dati non è scrivibile: " . $__dataDir;
}

$db = null;
try { $db = vetroom_db(); } catch (Throwable $e) { /* ignore */ }

// already installed if there is at least one platform user
$alreadyInstalled = false;
if ($db) {
    try {
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='platform_users'");
        if ($res && $res->fetchColumn()) {
            $c = (int)$db->query("SELECT COUNT(*) FROM platform_users")->fetchColumn();
            if ($c > 0) $alreadyInstalled = true;
        }
    } catch (Throwable $e) {}
}

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyInstalled && !$__fatal) {
    $name = trim($_POST['admin_name'] ?? '');
    $email = trim(strtolower($_POST['admin_email'] ?? ''));
    $pass = (string)($_POST['admin_pass'] ?? '');
    $pass2 = (string)($_POST['admin_pass2'] ?? '');
    $secret_q = trim((string)($_POST['secret_question'] ?? ''));
    $secret_a = trim((string)($_POST['secret_answer'] ?? ''));

    if ($name === '') $errors[] = 'Inserisci il nome del Superuser.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Inserisci una email valida.';
    if (strlen($pass) < 8) $errors[] = 'La password deve avere almeno 8 caratteri.';
    if ($pass !== $pass2) $errors[] = 'Le password non coincidono.';
    if ($secret_q === '') $errors[] = 'Inserisci una domanda segreta.';
    if ($secret_a === '') $errors[] = 'Inserisci una risposta segreta.';

    if (!$errors) {
        try {
            vetroom_create_schema();
            $db = vetroom_db();
            $now = vr_now_iso();

            // Create platform superuser
            $stmt = $db->prepare("INSERT INTO platform_users (name,email,password_hash,role,secret_question,secret_answer_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $name,
                $email,
                password_hash($pass, PASSWORD_DEFAULT),
                'SUPERADMIN',
                $secret_q,
                password_hash(vr_norm_secret_answer($secret_a), PASSWORD_DEFAULT),
                $now,
                $now
            ]);

            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Errore in installazione: ' . $e->getMessage();
        }
    }
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title><?php echo vr_h(VETROOM_APP_NAME); ?> - Installazione</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="public/styles.css">
</head>
<body>
<div class="vr-login-wrap">
  <div class="vr-login-card">
    <div class="vr-login-title"><?php echo vr_h(VETROOM_APP_NAME); ?> - Installazione Piattaforma</div>

    <?php if ($__fatal): ?>
      <div class="vr-alert vr-alert-error"><?php echo vr_h($__fatal); ?></div>
    <?php elseif ($alreadyInstalled): ?>
      <div class="vr-alert vr-alert-info">Installazione già completata.</div>
      <p><a class="vr-btn" href="platform.php">Vai alla Piattaforma</a></p>
    <?php elseif ($done): ?>
      <div class="vr-alert vr-alert-success">Installazione completata. Accedi come Superuser.</div>
      <p><a class="vr-btn" href="platform.php?login=1">Vai al login Superuser</a></p>
    <?php else: ?>
      <div class="vr-alert vr-alert-info">Configura il Superuser (Admin Piattaforma). Le strutture verranno create tramite invito e onboarding.</div>

      <?php if ($errors): ?>
        <div class="vr-alert vr-alert-error">
          <ul><?php foreach($errors as $e) echo '<li>'.vr_h($e).'</li>'; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post">
        <label>Nome Superuser</label>
        <input class="vr-input" name="admin_name" required>

        <label>Email Superuser</label>
        <input class="vr-input" type="email" name="admin_email" required>

        <label>Password</label>
        <input class="vr-input" type="password" name="admin_pass" required>

        <label>Ripeti password</label>
        <input class="vr-input" type="password" name="admin_pass2" required>

        <label>Domanda segreta (per recupero password)</label>
        <input class="vr-input" name="secret_question" required placeholder="Es. Nome del tuo primo animale">

        <label>Risposta segreta</label>
        <input class="vr-input" type="password" name="secret_answer" required>

        <button class="vr-btn" type="submit">Installa</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
