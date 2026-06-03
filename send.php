<?php
declare(strict_types=1);

/**
 * VetRoom landing – request handler
 *
 * Goals:
 * - Always store the request (so nothing gets lost if email delivery fails)
 * - Best-effort email notification
 * - Basic anti-spam + input validation
 */

header('X-Content-Type-Options: nosniff');

function wants_json(): bool {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'application/json') !== false;
}

function respond(bool $ok, string $message, int $code = 200): void {
  http_response_code($code);

  if (wants_json()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => $ok,
      'message' => $message,
      'error' => $ok ? null : $message,
    ], JSON_UNESCAPED_UNICODE);
    return;
  }

  header('Content-Type: text/html; charset=utf-8');
  $title = $ok ? 'Richiesta inviata' : 'Errore invio richiesta';
  $safeMsg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  echo "<!doctype html><html lang=\"it\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{$title} – VetRoom</title></head>";
  echo "<body style=\"margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#071716;color:#fff;\">";
  echo "<main style=\"max-width:720px;margin:10vh auto;padding:22px;\">";
  echo "<div style=\"padding:18px 18px;border-radius:18px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.14);\">";
  echo "<h1 style=\"margin:0 0 10px;font-size:22px;\">{$title}</h1>";
  echo "<p style=\"margin:0 0 14px;line-height:1.55;\">{$safeMsg}</p>";
  echo "<a href=\"/\" style=\"display:inline-block;padding:12px 14px;border-radius:14px;background:linear-gradient(135deg,#129A95,#2FAAA3);color:#06201f;font-weight:800;text-decoration:none;\">Torna alla homepage</a>";
  echo "</div></main></body></html>";
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(false, 'Metodo non consentito.', 405);
  exit;
}

// Honeypot anti-spam (bots fill hidden fields)
$honeypot = trim((string)($_POST['company'] ?? ''));
if ($honeypot !== '') {
  // Pretend success to bots
  respond(true, 'Richiesta inviata! Ti risponderemo via email.');
  exit;
}

// Basic rate limit (per-IP, file based) to reduce spam abuse.
// Allows up to 8 requests per hour per IP.
function rate_limit_ok(string $ip): bool {
  $ip = trim($ip);
  if ($ip === '') return true;
  $dir = __DIR__ . '/app/data/ratelimit';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  $key = hash('sha256', $ip);
  $file = $dir . '/landing_' . $key . '.json';
  $now = time();
  $window = 3600;
  $max = 8;
  $data = ['hits' => []];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) $data = $decoded;
  }
  $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];
  // keep only last hour
  $hits = array_values(array_filter($hits, fn($t) => is_int($t) && ($t >= ($now - $window))));
  if (count($hits) >= $max) {
    // Do not reveal rate limiting details
    return false;
  }
  $hits[] = $now;
  $data['hits'] = $hits;
  @file_put_contents($file, json_encode($data));
  return true;
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!rate_limit_ok($ip)) {
  respond(false, 'Troppe richieste in poco tempo. Riprova più tardi.', 429);
  exit;
}

$full_name = trim((string)($_POST['full_name'] ?? ''));
$email_raw = trim((string)($_POST['email'] ?? ''));
$email = preg_replace('/[\r\n]+/', '', $email_raw); // header injection guard
$phone = trim((string)($_POST['phone'] ?? ''));
$clinic = trim((string)($_POST['clinic'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));
$privacy = (string)($_POST['privacy'] ?? '');

$errors = [];
if ($full_name === '') $errors[] = 'Inserisci Nome e Cognome.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Inserisci un indirizzo email valido.';
if ($clinic === '') $errors[] = 'Inserisci il nome dello studio.';
if ($city === '') $errors[] = 'Inserisci la città.';
if ($message === '') $errors[] = 'Scrivi un messaggio.';
if ($privacy !== '1') $errors[] = 'Conferma il consenso privacy.';

if (!empty($errors)) {
  respond(false, implode(' ', $errors), 400);
  exit;
}

// Persist the request in the VetRoom DB (best effort)
$storedOk = false;
try {
  require_once __DIR__ . '/app/config.php';
  require_once __DIR__ . '/app/db.php';
  require_once __DIR__ . '/app/lib/mailer.php';

  $db = vetroom_db();
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $nowIso = date('c');

  $stmt = $db->prepare("INSERT INTO leads (full_name,email,phone,clinic,city,message,ip,user_agent,created_at,status)
                        VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    $full_name,
    $email,
    $phone,
    $clinic,
    $city,
    $message,
    $ip,
    $ua,
    $nowIso,
    'NEW',
  ]);
  $storedOk = true;

  // Best-effort email notification
  $to = defined('VETROOM_CONTACT_TO') ? (string)VETROOM_CONTACT_TO : 'info@vetroom.it';
  $subject = 'Richiesta accesso VetRoom – ' . $clinic;

  $body = "Nuova richiesta di accesso a VetRoom\n\n";
  $body .= "Nome: {$full_name}\n";
  $body .= "Email: {$email}\n";
  $body .= "Telefono: " . ($phone !== '' ? $phone : '-') . "\n";
  $body .= "Studio: {$clinic}\n";
  $body .= "Città: {$city}\n\n";
  $body .= "Messaggio:\n{$message}\n\n";
  $body .= "---\n";
  $body .= "IP: {$ip}\n";
  $body .= "User-Agent: {$ua}\n";
  $body .= "Data: {$nowIso}\n";

  // Do not fail the request if email cannot be sent.
  @vr_mail_send($to, $subject, $body, $email);
} catch (Throwable $e) {
  // If DB is not available, we still try to send the email with mail()
  // to keep legacy behavior.
  $to = 'info@vetroom.it';
  $subject = 'Richiesta accesso VetRoom – ' . $clinic;
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $nowIso = date('c');
  $body = "Nuova richiesta di accesso a VetRoom\n\n";
  $body .= "Nome: {$full_name}\n";
  $body .= "Email: {$email}\n";
  $body .= "Telefono: " . ($phone !== '' ? $phone : '-') . "\n";
  $body .= "Studio: {$clinic}\n";
  $body .= "Città: {$city}\n\n";
  $body .= "Messaggio:\n{$message}\n\n";
  $body .= "---\n";
  $body .= "IP: {$ip}\n";
  $body .= "User-Agent: {$ua}\n";
  $body .= "Data: {$nowIso}\n";

  $headers = [];
  $headers[] = 'From: VetRoom <no-reply@vetroom.it>';
  $headers[] = 'Reply-To: ' . $email;
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  @mail($to, $subject, $body, implode("\r\n", $headers));
}

// If stored in DB we consider it a success even if email delivery is unreliable.
respond(true, 'Richiesta ricevuta! Ti contatteremo via email il prima possibile.');
