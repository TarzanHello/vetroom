<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

$db = vetroom_db();
$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(400); echo "Token mancante."; exit; }

$stmt = $db->prepare("SELECT * FROM invitations WHERE token=? LIMIT 1");
$stmt->execute([$token]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); echo "Invito non valido."; exit; }
if (!empty($inv['used_at'])) { echo "Invito già utilizzato."; exit; }
$now = new DateTimeImmutable();
$exp = new DateTimeImmutable($inv['expires_at']);
if ($now > $exp) { echo "Invito scaduto."; exit; }

$errors = [];
$done = false;

function vr_require_upload(string $field): array {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return [false, 'File mancante: '.$field];
    }
    $f = $_FILES[$field];
    if ($f['size'] > 8*1024*1024) return [false, 'File troppo grande (max 8MB): '.$field];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf','jpg','jpeg','png'])) return [false, 'Formato non valido (pdf/jpg/png): '.$field];
    return [true, $ext];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $clinicName = trim($_POST['clinic_name'] ?? '');
    $vetName = trim($_POST['vet_name'] ?? '');
    $piva = trim($_POST['piva'] ?? '');
    $albo = trim($_POST['albo'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');
    $secret_q = trim((string)($_POST['secret_question'] ?? ''));
    $secret_a = trim((string)($_POST['secret_answer'] ?? ''));

    if ($clinicName==='') $errors[]='Inserisci il nome struttura.';
    if ($vetName==='') $errors[]='Inserisci il nome del professionista.';
    if ($piva==='') $errors[]='Inserisci la Partita IVA.';
    if (strlen($pass) < 8) $errors[]='Password minimo 8 caratteri.';
    if ($pass !== $pass2) $errors[]='Le password non coincidono.';
    if ($secret_q === '') $errors[]='Inserisci una domanda segreta (per recupero password).';
    if ($secret_a === '') $errors[]='Inserisci una risposta segreta.';
    if ($secret_q === '') $errors[]='Inserisci una domanda segreta.';
    if ($secret_a === '') $errors[]='Inserisci una risposta segreta.';

    // required docs
    $reqFields = ['doc_id_1','doc_id_2','visura','cert_piva'];
    $exts = [];
    foreach ($reqFields as $f) {
        [$ok,$info] = vr_require_upload($f);
        if (!$ok) $errors[]=$info;
        else $exts[$f]=$info;
    }

    if (!$errors) {
        try {
            $db->beginTransaction();
            $ts = vr_now_iso();

            // Invitation-scoped limits (set by PLATFORM/ADMIN before generating the invite link)
            $maxVets = max(1, (int)($inv['max_vets'] ?? 1));
            $maxSecretaries = max(0, (int)($inv['max_secretaries'] ?? 0));

            // create clinic inactive
            $stmtC = $db->prepare("INSERT INTO clinics (name,is_active,status,max_vets,max_secretaries,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
            $stmtC->execute([$clinicName,0,'PENDING',$maxVets,$maxSecretaries,$ts,$ts]);
            $clinicId = (int)$db->lastInsertId();

            // create vet_settings baseline (can be edited later)
            $stmtS = $db->prepare("INSERT INTO vet_settings (clinic_id,header_name,header_title,header_piva,header_albo,header_phone,header_email,header_address,pdf_settings,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmtS->execute([$clinicId,$vetName,'',$piva,$albo,$phone,$inv['email'],$address,'{}',$ts,$ts]);

            // create user inactive
            $stmtU = $db->prepare("INSERT INTO users (clinic_id,name,email,password_hash,secret_question,secret_answer_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmtU->execute([
                $clinicId,
                $vetName,
                $inv['email'],
                password_hash($pass, PASSWORD_DEFAULT),
                $secret_q,
                password_hash(vr_norm_secret_answer($secret_a), PASSWORD_DEFAULT),
                'CHIEF',
                0,
                $ts,
                $ts
            ]);
            $userId = (int)$db->lastInsertId();

            // ensure pivot for future multi-staff
            $db->prepare("INSERT OR IGNORE INTO user_clinic (user_id,clinic_id,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?)")
               ->execute([$userId,$clinicId,'CHIEF',0,$ts,$ts]);

            // create application
            $stmtA = $db->prepare("INSERT INTO clinic_applications (invitation_id,email,clinic_id,user_id,status,data_json,created_at) VALUES (?,?,?,?,?,?,?)");
            $profile = [
                'clinic_name'=>$clinicName,
                'vet_name'=>$vetName,
                'piva'=>$piva,
                'albo'=>$albo,
                'phone'=>$phone,
                'address'=>$address,
                'email'=>$inv['email'],
                'secret_question'=>$secret_q,
            ];
            $data = ['profile'=>$profile, 'docs'=>[]];
            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE);

            $stmtA->execute([(int)$inv['id'],$inv['email'],$clinicId,$userId,'PENDING',$dataJson,$ts]);
            $appId = (int)$db->lastInsertId();

            // store files
            // Store onboarding identity docs in a private folder (not directly web-accessible).
            $baseRel = 'data/private_uploads/onboarding/app_'.$appId;
            $baseAbs = __DIR__ . '/' . $baseRel;
            if (!is_dir($baseAbs)) { @mkdir($baseAbs, 0775, true); }

            $docs = [];
            foreach ($reqFields as $f) {
                $ext = $exts[$f];
                $destName = $f . '.' . $ext;
                $destAbs = $baseAbs . '/' . $destName;
                if (!move_uploaded_file($_FILES[$f]['tmp_name'], $destAbs)) {
                    throw new Exception('Upload fallito: '.$f);
                }
                $docs[$f] = $baseRel . '/' . $destName;
            }
            $data['docs'] = $docs;
            $db->prepare("UPDATE clinic_applications SET data_json=? WHERE id=?")->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $appId]);

            // mark invitation used
            $db->prepare("UPDATE invitations SET used_at=? WHERE id=?")->execute([$ts,(int)$inv['id']]);

            $db->commit();
            $done = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Errore registrazione: '.$e->getMessage();
        }
    }
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>VetRoom - Onboarding</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="public/styles.css">
</head>
<body>
<div class="vr-login-wrap">
  <div class="vr-login-card" style="max-width:640px;">
    <div class="vr-login-title">Registrazione Veterinario / Struttura</div>

    <?php if ($done): ?>
      <div class="vr-alert vr-alert-success">Richiesta inviata. Attendi approvazione dal Superuser. Poi potrai accedere al gestionale.</div>
      <p><a class="vr-btn" href="index.php">Vai al login Vetroom</a></p>
    <?php else: ?>
      <div class="vr-alert vr-alert-info">Email invito: <b><?php echo vr_h($inv['email']); ?></b></div>
      <?php if ($errors): ?>
        <div class="vr-alert vr-alert-error"><ul><?php foreach($errors as $e) echo '<li>'.vr_h($e).'</li>'; ?></ul></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <label>Nome struttura / studio</label>
        <input class="vr-input" name="clinic_name" required value="<?php echo vr_h($_POST['clinic_name'] ?? ''); ?>">

        <label>Nome e cognome professionista</label>
        <input class="vr-input" name="vet_name" required value="<?php echo vr_h($_POST['vet_name'] ?? ''); ?>">

        <label>Partita IVA</label>
        <input class="vr-input" name="piva" required value="<?php echo vr_h($_POST['piva'] ?? ''); ?>">

        <label>Albo / iscrizione (opzionale)</label>
        <input class="vr-input" name="albo" value="<?php echo vr_h($_POST['albo'] ?? ''); ?>">

        <label>Telefono (opzionale)</label>
        <input class="vr-input" name="phone" value="<?php echo vr_h($_POST['phone'] ?? ''); ?>">

        <label>Indirizzo (opzionale)</label>
        <input class="vr-input" name="address" value="<?php echo vr_h($_POST['address'] ?? ''); ?>">

        <hr style="margin:14px 0;">

        <label>Password (per accesso gestionale)</label>
        <input class="vr-input" type="password" name="password" required>

        <label>Ripeti password</label>
        <input class="vr-input" type="password" name="password2" required>

        <label>Domanda segreta (recupero password)</label>
        <input class="vr-input" name="secret_question" required placeholder="Es. Nome del tuo primo animale" value="<?php echo vr_h($_POST['secret_question'] ?? ''); ?>">

        <label>Risposta segreta</label>
        <input class="vr-input" type="password" name="secret_answer" required>

        <hr style="margin:14px 0;">

        <div class="vr-alert vr-alert-info">
          Documenti obbligatori (max 8MB ciascuno, pdf/jpg/png):
          <ul>
            <li>2 documenti di identità</li>
            <li>Visura camerale</li>
            <li>Certificato apertura P.IVA</li>
          </ul>
        </div>

        <label>Documento identità 1</label>
        <input class="vr-input" type="file" name="doc_id_1" required>

        <label>Documento identità 2</label>
        <input class="vr-input" type="file" name="doc_id_2" required>

        <label>Visura camerale</label>
        <input class="vr-input" type="file" name="visura" required>

        <label>Certificato apertura P.IVA</label>
        <input class="vr-input" type="file" name="cert_piva" required>

        <button class="vr-btn" type="submit">Invia richiesta</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
