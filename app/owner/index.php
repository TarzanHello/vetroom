<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../consent.php';

vetroom_require_owner_login('login.php');

$db = vetroom_db();
$sess = vetroom_owner_current_user();
$accountId = (int)($sess['id'] ?? 0);

$notice_success = '';
$notice_error = '';

// Handle accept/reject/revoke relationship requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fa = strtolower(trim((string)($_POST['form_action'] ?? '')));
    if (in_array($fa, ['accept_vet_link','reject_vet_link','revoke_vet_link'], true)) {
        if (!vr_owner_csrf_is_valid()) {
            $_SESSION['vetroom_owner_notice_error'] = 'Sessione scaduta o richiesta non valida. Ricarica la pagina e riprova.';
            header('Location: index.php');
            exit;
        }

        $rid = (int)($_POST['relation_id'] ?? 0);
        if ($rid > 0 && $accountId > 0) {
            try {
                $stmtR = $db->prepare(
                    "SELECT r.id, r.status, r.clinic_id, r.owner_id
                     FROM vet_owner_relations r
                     WHERE r.id=? AND r.owner_account_id=?
                     LIMIT 1"
                );
                $stmtR->execute([$rid, $accountId]);
                $rel = $stmtR->fetch(PDO::FETCH_ASSOC);
                if (!$rel) {
                    $_SESSION['vetroom_owner_notice_error'] = 'Richiesta non trovata.';
                } else {
                    $now = vr_now_iso();
                    $cur = vr_consent_normalize_status((string)($rel['status'] ?? ''));
                    if ($fa === 'accept_vet_link') {
                        // Only pending/rejected can be accepted (revoked/suspended stay blocked)
                        if (in_array($cur, ['pending','rejected'], true)) {
                            $db->prepare("UPDATE vet_owner_relations SET status='active', updated_at=? WHERE id=?")
                               ->execute([$now, $rid]);
                            // Promote this clinic/profile as the latest one for the dashboard (best effort)
                            $db->prepare("UPDATE owners SET updated_at=? WHERE id=? AND clinic_id=?")
                               ->execute([$now, (int)$rel['owner_id'], (int)$rel['clinic_id']]);
                            $_SESSION['vetroom_owner_notice_success'] = 'Richiesta accettata. La struttura ora è collegata al tuo account.';
                        }
                    } elseif ($fa === 'reject_vet_link') {
                        if ($cur === 'pending') {
                            $db->prepare("UPDATE vet_owner_relations SET status='rejected', updated_at=? WHERE id=?")
                               ->execute([$now, $rid]);
                            $_SESSION['vetroom_owner_notice_success'] = 'Richiesta rifiutata.';
                        }
                    } elseif ($fa === 'revoke_vet_link') {
                        if ($cur === 'active') {
                            $db->prepare("UPDATE vet_owner_relations SET status='revoked', updated_at=? WHERE id=?")
                               ->execute([$now, $rid]);
                            $_SESSION['vetroom_owner_notice_success'] = 'Collegamento revocato.';
                        }
                    }
                }
            } catch (Throwable $t) {
                $_SESSION['vetroom_owner_notice_error'] = 'Errore: ' . (string)$t->getMessage();
            }
        }

        header('Location: index.php');
        exit;
    }
}

if (!empty($_SESSION['vetroom_owner_notice_success'])) {
    $notice_success = (string)$_SESSION['vetroom_owner_notice_success'];
    unset($_SESSION['vetroom_owner_notice_success']);
}
if (!empty($_SESSION['vetroom_owner_notice_error'])) {
    $notice_error = (string)$_SESSION['vetroom_owner_notice_error'];
    unset($_SESSION['vetroom_owner_notice_error']);
}

// Owner inbox (pending requests)
$pendingLinks = [];
$activeLinks = [];
if ($accountId > 0) {
    try {
        $stP = $db->prepare(
            "SELECT r.id, r.status, r.created_at, r.updated_at, r.clinic_id, r.owner_id, c.name AS clinic_name
             FROM vet_owner_relations r
             JOIN clinics c ON c.id = r.clinic_id
             WHERE r.owner_account_id=? AND r.status='pending'
             ORDER BY r.created_at DESC, r.id DESC"
        );
        $stP->execute([$accountId]);
        $pendingLinks = $stP->fetchAll(PDO::FETCH_ASSOC);

        $stA = $db->prepare(
            "SELECT r.id, r.status, r.created_at, r.updated_at, r.clinic_id, r.owner_id, c.name AS clinic_name
             FROM vet_owner_relations r
             JOIN clinics c ON c.id = r.clinic_id
             WHERE r.owner_account_id=? AND r.status='active'
             ORDER BY r.updated_at DESC, r.id DESC"
        );
        $stA->execute([$accountId]);
        $activeLinks = $stA->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $t) {
        $pendingLinks = [];
        $activeLinks = [];
    }
}

// Load linked owner profile (anagrafica)
$owner = null;
$ownerId = 0;
$clinicId = 0;
$clinicName = '';

if ($accountId > 0) {
    $stmtO = $db->prepare(
        "SELECT o.*, c.name AS clinic_name
         FROM owners o
         JOIN clinics c ON c.id=o.clinic_id
         JOIN vet_owner_relations r ON r.owner_id=o.id AND r.clinic_id=o.clinic_id
         WHERE o.owner_account_id=? AND r.status='active'
         ORDER BY o.updated_at DESC, o.id DESC
         LIMIT 1"
    );
    $stmtO->execute([$accountId]);
    $owner = $stmtO->fetch(PDO::FETCH_ASSOC);
    if ($owner) {
        $ownerId = (int)($owner['id'] ?? 0);
        $clinicId = (int)($owner['clinic_id'] ?? 0);
        $clinicName = (string)($owner['clinic_name'] ?? '');

        // Keep session snapshot in sync (best effort)
        $_SESSION['vetroom_owner_user']['owner_id'] = $ownerId;
        $_SESSION['vetroom_owner_user']['clinic_id'] = $clinicId;
        $_SESSION['vetroom_owner_user']['clinic_name'] = $clinicName;
    }
}

$pets = [];
$appts = [];
$visits = [];

if ($ownerId > 0 && $clinicId > 0) {
    $stmtPets = $db->prepare("SELECT * FROM pets WHERE owner_id=? AND clinic_id=? ORDER BY name");
    $stmtPets->execute([$ownerId, $clinicId]);
    $pets = $stmtPets->fetchAll(PDO::FETCH_ASSOC);

    $today = vr_today_date();
    $stmtA = $db->prepare("
        SELECT a.*, p.name AS pet_name
        FROM appointments a
        JOIN pets p ON p.id = a.pet_id
        WHERE a.owner_id=? AND a.clinic_id=? AND a.date >= ?
        ORDER BY a.date ASC, a.time ASC
        LIMIT 30
    ");
    $stmtA->execute([$ownerId, $clinicId, $today]);
    $appts = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    $stmtV = $db->prepare("
        SELECT v.id, v.visit_date, v.visit_kind, v.title, p.name AS pet_name,
               (SELECT d.id
                  FROM documents d
                 WHERE d.clinic_id=v.clinic_id
                   AND d.visit_id=v.id
                   AND d.doc_role='VISIT_PDF_FINAL'
                 ORDER BY d.created_at DESC, d.id DESC
                 LIMIT 1) AS final_pdf_id
        FROM visits v
        JOIN pets p ON p.id = v.pet_id
        WHERE v.owner_id=? AND v.clinic_id=?
        ORDER BY v.visit_date DESC, v.id DESC
        LIMIT 10
    ");
    $stmtV->execute([$ownerId, $clinicId]);
    $visits = $stmtV->fetchAll(PDO::FETCH_ASSOC);
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

<div class="vr-header">
  <div class="vr-header-left">
    <div>
      <div class="vr-logo-title"><?php echo vr_h(VETROOM_APP_NAME); ?></div>
      <div class="vr-logo-sub">Area Proprietario</div>
    </div>
  </div>
  <div class="vr-header-right">
    <?php if ($clinicName !== ''): ?>
      <span style="opacity:.9;">Struttura:</span>
      <strong><?php echo vr_h($clinicName); ?></strong>
    <?php endif; ?>
    <a class="vr-button" href="logout.php" style="font-size:12px; padding:4px 10px;">Esci</a>
  </div>
</div>

<div class="vr-main" style="max-width: 980px; margin: 0 auto;">
  <h1 class="vr-page-title">Benvenuto</h1>
  <p class="vr-page-subtitle">Qui trovi i tuoi animali, appuntamenti e ultime visite.</p>

  <?php if ($notice_success): ?>
    <div class="vr-alert vr-alert-success" style="margin:0 0 12px 0;"><?php echo vr_h($notice_success); ?></div>
  <?php endif; ?>
  <?php if ($notice_error): ?>
    <div class="vr-alert vr-alert-error" style="margin:0 0 12px 0;"><?php echo vr_h($notice_error); ?></div>
  <?php endif; ?>

  <div class="vr-card">
    <div class="vr-card-header">Richieste di collegamento</div>
    <?php if (empty($pendingLinks)): ?>
      <p style="font-size:13px;margin:0;">Nessuna richiesta in attesa.</p>
    <?php else: ?>
      <table class="vr-table">
        <thead>
          <tr>
            <th>Struttura</th>
            <th>Data richiesta</th>
            <th style="width:210px;">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingLinks as $r): ?>
            <tr>
              <td><?php echo vr_h((string)($r['clinic_name'] ?? '')); ?></td>
              <td><?php echo vr_h((string)($r['created_at'] ?? '')); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="form_action" value="accept_vet_link">
                  <?php echo vr_owner_csrf_field(); ?>
                  <input type="hidden" name="relation_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                  <button type="submit" class="vr-button" style="font-size:12px;padding:4px 10px;">Accetta</button>
                </form>
                <form method="post" style="display:inline;margin-left:6px;" onsubmit="return confirm('Rifiutare questa richiesta?');">
                  <input type="hidden" name="form_action" value="reject_vet_link">
                  <?php echo vr_owner_csrf_field(); ?>
                  <input type="hidden" name="relation_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                  <button type="submit" class="vr-button vr-button-secondary" style="font-size:12px;padding:4px 10px;">Rifiuta</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($activeLinks)): ?>
    <div class="vr-card">
      <div class="vr-card-header">Strutture collegate</div>
      <table class="vr-table">
        <thead>
          <tr>
            <th>Struttura</th>
            <th>Ultimo aggiornamento</th>
            <th style="width:140px;">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($activeLinks as $r): ?>
            <tr>
              <td><?php echo vr_h((string)($r['clinic_name'] ?? '')); ?></td>
              <td><?php echo vr_h((string)($r['updated_at'] ?? '')); ?></td>
              <td>
                <form method="post" style="display:inline;" onsubmit="return confirm('Revocare questo collegamento?');">
                  <input type="hidden" name="form_action" value="revoke_vet_link">
                  <?php echo vr_owner_csrf_field(); ?>
                  <input type="hidden" name="relation_id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                  <button type="submit" class="vr-button vr-button-danger" style="font-size:12px;padding:4px 10px;">Revoca</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!$owner): ?>
    <div class="vr-card">
      <div class="vr-card-header">Profilo non collegato</div>
      <p style="font-size:13px;margin:0;">Il tuo account è attivo ma non risulta ancora collegato a una anagrafica proprietario attiva nella struttura. Se hai richieste in attesa, puoi accettarle sopra; altrimenti contatta la segreteria.</p>
    </div>
  <?php else: ?>
    <div class="vr-card">
      <div class="vr-card-header">Dati proprietario</div>
      <table class="vr-table">
        <tbody>
          <tr><th style="width:180px;">Nome</th><td><?php echo vr_h(($owner['surname'] ?? '') . ' ' . ($owner['name'] ?? '')); ?></td></tr>
          <tr><th>Codice Fiscale</th><td><?php echo vr_h((string)($owner['fiscal_code'] ?? '')); ?></td></tr>
          <tr><th>Email</th><td><?php echo vr_h((string)($owner['email'] ?? '')); ?></td></tr>
          <tr><th>Telefono</th><td><?php echo vr_h((string)($owner['phone'] ?? '')); ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="vr-card">
      <div class="vr-card-header">Animali</div>
      <?php if (!$pets): ?>
        <p style="font-size:13px;margin:0;">Nessun animale associato.</p>
      <?php else: ?>
        <table class="vr-table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Specie</th>
              <th>Razza</th>
              <th>Data nascita</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pets as $p): ?>
              <tr>
                <td><?php echo vr_h((string)($p['name'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($p['species'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($p['breed'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($p['birth_date'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="vr-card">
      <div class="vr-card-header">Prossimi appuntamenti</div>
      <?php if (!$appts): ?>
        <p style="font-size:13px;margin:0;">Nessun appuntamento futuro.</p>
      <?php else: ?>
        <table class="vr-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Ora</th>
              <th>Animale</th>
              <th>Tipo</th>
              <th>Stato</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($appts as $a): ?>
              <tr>
                <td><?php echo vr_h((string)($a['date'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($a['time'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($a['pet_name'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($a['type'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($a['status'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="vr-card">
      <div class="vr-card-header">Ultime visite</div>
      <?php if (!$visits): ?>
        <p style="font-size:13px;margin:0;">Nessuna visita presente.</p>
      <?php else: ?>
        <table class="vr-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Animale</th>
              <th>Tipo</th>
              <th>Titolo</th>
              <th>PDF</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visits as $v): ?>
              <tr>
                <td><?php echo vr_h((string)($v['visit_date'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($v['pet_name'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($v['visit_kind'] ?? '')); ?></td>
                <td><?php echo vr_h((string)($v['title'] ?? '')); ?></td>
                <td>
                  <?php $pdfId = (int)($v['final_pdf_id'] ?? 0); ?>
                  <?php if ($pdfId > 0): ?>
                    <a href="download.php?doc_id=<?php echo $pdfId; ?>&inline=1" target="_blank" rel="noopener">Apri</a>
                  <?php else: ?>
                    <span style="opacity:.6;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
