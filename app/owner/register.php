<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../consent.php';

// If already logged in, go to dashboard.
if (vetroom_owner_current_user()) {
    header('Location: index.php');
    exit;
}

$db = vetroom_db();

function vr_owner_pick_default_clinic(PDO $db): ?array {
    try {
        $rows = $db->query("SELECT id, name, is_active, status FROM clinics ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        // Prefer active clinics.
        foreach ($rows as $r) {
            if ((int)($r['is_active'] ?? 0) === 1) return $r;
        }
        return $rows[0];
    } catch (Throwable $t) {
        return null;
    }
}

$clinic = vr_owner_pick_default_clinic($db);
$clinicId = $clinic ? (int)($clinic['id'] ?? 0) : 0;
$clinicName = $clinic ? (string)($clinic['name'] ?? '') : '';

$errors = [];

// Defaults
$fields = [
    'name' => '',
    'surname' => '',
    'birth_date' => '',
    'fiscal_code' => '',
    'email' => '',
    'phone' => '',
    'address_street' => '',
    'address_number' => '',
    'address_city' => '',
    'address_province' => '',
    'address_state' => '',
    'address_zip' => '',
    'billing_is_different' => 0,
    'billing_street' => '',
    'billing_number' => '',
    'billing_city' => '',
    'billing_province' => '',
    'billing_state' => '',
    'billing_zip' => '',
];

foreach ($fields as $k => $_) {
    if ($k === 'billing_is_different') {
        $fields[$k] = !empty($_POST[$k]) ? 1 : 0;
    } else {
        $fields[$k] = (string)($_POST[$k] ?? $fields[$k]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!vr_owner_csrf_is_valid()) {
        $errors[] = 'Richiesta non valida. Ricarica la pagina e riprova.';
    }

    $name = trim($fields['name']);
    $surname = trim($fields['surname']);
    $birth_date = trim($fields['birth_date']);
    $fiscal_code = strtoupper(trim($fields['fiscal_code']));
    $fiscal_code = preg_replace('/\s+/', '', $fiscal_code);
    $email = trim($fields['email']);
    $phone = trim($fields['phone']);

    $address_street = trim($fields['address_street']);
    $address_number = trim($fields['address_number']);
    $address_city = trim($fields['address_city']);
    $address_province = trim($fields['address_province']);
    $address_state = trim($fields['address_state']);
    $address_zip = trim($fields['address_zip']);

    $billing_is_different = (int)$fields['billing_is_different'];
    $billing_street = trim($fields['billing_street']);
    $billing_number = trim($fields['billing_number']);
    $billing_city = trim($fields['billing_city']);
    $billing_province = trim($fields['billing_province']);
    $billing_state = trim($fields['billing_state']);
    $billing_zip = trim($fields['billing_zip']);

    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($clinicId <= 0) {
        $errors[] = 'Nessuna struttura configurata. Contatta l\'assistenza.';
    }

    // Same fields as "Nuovo proprietario" lato vet (+ password)
    if ($name === '' || $surname === '') {
        $errors[] = 'Nome e cognome sono obbligatori.';
    }
    if ($fiscal_code === '') {
        $errors[] = 'Il codice fiscale è obbligatorio.';
    } elseif (!vr_cf_is_valid_strict($fiscal_code)) {
        $errors[] = 'Codice fiscale non valido.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci una email valida.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'La password deve avere almeno 8 caratteri.';
    }
    if ($password !== $password2) {
        $errors[] = 'Le password non coincidono.';
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $now = vr_now_iso();

            // 1) Create owner account (unique by fiscal_code)
            $stmtAcc = $db->prepare("INSERT INTO owner_accounts (fiscal_code, password_hash, email, created_at, updated_at) VALUES (?,?,?,?,?)");
            $stmtAcc->execute([
                $fiscal_code,
                password_hash($password, PASSWORD_DEFAULT),
                $email !== '' ? $email : null,
                $now,
                $now
            ]);
            $accountId = (int)$db->lastInsertId();

            // 2) Link (or create) the owner profile (anagrafica) in the clinic
            $stmtFind = $db->prepare("SELECT id FROM owners WHERE clinic_id=? AND upper(fiscal_code)=? ORDER BY id ASC LIMIT 1");
            $stmtFind->execute([$clinicId, $fiscal_code]);
            $existingOwnerId = (int)($stmtFind->fetchColumn() ?: 0);
            $profileOwnerId = 0;

            if ($existingOwnerId > 0) {
                $stmtUp = $db->prepare("
                    UPDATE owners SET
                        owner_account_id = ?,
                        name = ?, surname = ?, birth_date = ?, fiscal_code = ?,
                        email = ?, phone = ?,
                        address_street = ?, address_number = ?, address_city = ?, address_province = ?, address_state = ?, address_zip = ?,
                        billing_is_different = ?, billing_street = ?, billing_number = ?, billing_city = ?, billing_province = ?, billing_state = ?, billing_zip = ?,
                        updated_at = ?
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmtUp->execute([
                    $accountId,
                    $name, $surname, $birth_date, $fiscal_code,
                    $email, $phone,
                    $address_street, $address_number, $address_city, $address_province, $address_state, $address_zip,
                    $billing_is_different, $billing_street, $billing_number, $billing_city, $billing_province, $billing_state, $billing_zip,
                    $now,
                    $existingOwnerId, $clinicId
                ]);
                $profileOwnerId = $existingOwnerId;
            } else {
                $stmtIns = $db->prepare("
                    INSERT INTO owners (
                        clinic_id, owner_account_id,
                        name, surname, birth_date, fiscal_code,
                        email, phone,
                        address_street, address_number, address_city, address_province, address_state, address_zip,
                        billing_is_different, billing_street, billing_number, billing_city, billing_province, billing_state, billing_zip,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?
                    )
                ");
                $stmtIns->execute([
                    $clinicId, $accountId,
                    $name, $surname, $birth_date, $fiscal_code,
                    $email, $phone,
                    $address_street, $address_number, $address_city, $address_province, $address_state, $address_zip,
                    $billing_is_different, $billing_street, $billing_number, $billing_city, $billing_province, $billing_state, $billing_zip,
                    $now, $now
                ]);
                $profileOwnerId = (int)$db->lastInsertId();
            }

            // 2b) Ensure an ACTIVE consent relation for this clinic/profile (registration = explicit consent)
            if ($profileOwnerId > 0) {
                vr_consent_upsert_relation($db, $clinicId, $profileOwnerId, $accountId, 'active');
            }

            $db->commit();

            // 3) Auto-login (no admin validation for owners)
            $res = vetroom_login_owner($fiscal_code, $password);
            if ($res === true) {
                header('Location: index.php');
                exit;
            }

            // Fallback: go to login
            header('Location: login.php');
            exit;

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $msg = (string)$e->getMessage();
            if (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'owner_accounts') !== false) {
                $errors[] = 'Esiste già un account per questo Codice Fiscale. Vai al login.';
            } else {
                $errors[] = 'Errore durante la registrazione: ' . $msg;
            }
        }
    }
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title><?php echo vr_h(VETROOM_APP_NAME); ?> - Registrazione Proprietario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../public/styles.css">
</head>
<body>
<div class="vr-login-wrap">
  <div class="vr-login-card" style="max-width:640px;">
    <div class="vr-login-title">Registrazione Proprietario</div>
    <div class="vr-login-sub">
      Crea il tuo accesso al portale. <?php if ($clinicName !== ''): ?>Struttura: <b><?php echo vr_h($clinicName); ?></b><?php endif; ?>
    </div>

    <?php if ($errors): ?>
      <div class="vr-alert vr-alert-error" style="margin-top:10px;">
        <ul style="margin:0;padding-left:18px;">
          <?php foreach ($errors as $e): ?>
            <li><?php echo vr_h($e); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" style="margin-top:12px;">
      <?php echo vr_owner_csrf_field(); ?>

      <div class="vr-form-row">
        <label class="vr-label" for="name_owner">Nome *</label>
        <input class="vr-input" type="text" id="name_owner" name="name" required value="<?php echo vr_h($fields['name']); ?>">
      </div>
      <div class="vr-form-row">
        <label class="vr-label" for="surname_owner">Cognome *</label>
        <input class="vr-input" type="text" id="surname_owner" name="surname" required value="<?php echo vr_h($fields['surname']); ?>">
      </div>
      <div class="vr-form-row">
        <label class="vr-label" for="birth_date_owner">Data di nascita</label>
        <input class="vr-input" type="date" id="birth_date_owner" name="birth_date" value="<?php echo vr_h($fields['birth_date']); ?>">
      </div>
      <div class="vr-form-row">
        <label class="vr-label" for="fiscal_code">Codice fiscale *</label>
        <input class="vr-input" type="text" id="fiscal_code" name="fiscal_code" maxlength="16" required value="<?php echo vr_h($fields['fiscal_code']); ?>">
      </div>
      <div class="vr-form-row">
        <label class="vr-label" for="email_owner">Email</label>
        <input class="vr-input" type="email" id="email_owner" name="email" value="<?php echo vr_h($fields['email']); ?>">
      </div>
      <div class="vr-form-row">
        <label class="vr-label" for="phone_owner">Telefono</label>
        <input class="vr-input" type="text" id="phone_owner" name="phone" value="<?php echo vr_h($fields['phone']); ?>">
      </div>

      <div class="vr-form-row">
        <label class="vr-label">Indirizzo di residenza</label>
      </div>
      <div class="vr-form-row">
        <input class="vr-input" type="text" name="address_street" placeholder="Via / Piazza" value="<?php echo vr_h($fields['address_street']); ?>">
      </div>
      <div class="vr-form-row" style="display:flex;gap:6px;">
        <input class="vr-input" type="text" name="address_number" placeholder="Civico" value="<?php echo vr_h($fields['address_number']); ?>">
        <input class="vr-input" type="text" name="address_zip" placeholder="CAP" value="<?php echo vr_h($fields['address_zip']); ?>">
      </div>
      <div class="vr-form-row" style="display:flex;gap:6px;">
        <input class="vr-input" type="text" name="address_city" placeholder="Città" value="<?php echo vr_h($fields['address_city']); ?>">
        <input class="vr-input" type="text" name="address_province" placeholder="Provincia" value="<?php echo vr_h($fields['address_province']); ?>">
        <input class="vr-input" type="text" name="address_state" placeholder="Stato" value="<?php echo vr_h($fields['address_state']); ?>">
      </div>

      <div class="vr-form-row vr-checkbox-row">
        <input type="checkbox" id="billing_is_different" name="billing_is_different" value="1" <?php echo !empty($fields['billing_is_different']) ? 'checked' : ''; ?>>
        <label for="billing_is_different">Indirizzo di fatturazione diverso da residenza</label>
      </div>

      <div class="vr-form-row">
        <label class="vr-label">Indirizzo di fatturazione (se diverso)</label>
      </div>
      <div class="vr-form-row">
        <input class="vr-input" type="text" name="billing_street" placeholder="Via / Piazza" value="<?php echo vr_h($fields['billing_street']); ?>">
      </div>
      <div class="vr-form-row" style="display:flex;gap:6px;">
        <input class="vr-input" type="text" name="billing_number" placeholder="Civico" value="<?php echo vr_h($fields['billing_number']); ?>">
        <input class="vr-input" type="text" name="billing_zip" placeholder="CAP" value="<?php echo vr_h($fields['billing_zip']); ?>">
      </div>
      <div class="vr-form-row" style="display:flex;gap:6px;">
        <input class="vr-input" type="text" name="billing_city" placeholder="Città" value="<?php echo vr_h($fields['billing_city']); ?>">
        <input class="vr-input" type="text" name="billing_province" placeholder="Provincia" value="<?php echo vr_h($fields['billing_province']); ?>">
        <input class="vr-input" type="text" name="billing_state" placeholder="Stato" value="<?php echo vr_h($fields['billing_state']); ?>">
      </div>

      <hr style="margin:14px 0;">

      <label>Password *</label>
      <input class="vr-input" type="password" name="password" required autocomplete="new-password">

      <label>Ripeti password *</label>
      <input class="vr-input" type="password" name="password2" required autocomplete="new-password">

      <button class="vr-btn" type="submit" style="margin-top:10px;">Crea account</button>
    </form>

    <p style="margin-top:12px; font-size:13px;">
      Hai già un account? <a href="login.php">Accedi</a>
    </p>
  </div>
</div>
</body>
</html>
