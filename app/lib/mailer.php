<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

/**
 * Minimal, dependency-free mail helper.
 *
 * Notes:
 * - Uses PHP mail() for maximum portability.
 * - Logs all attempts to app/data/mail_log.jsonl so you can debug delivery problems.
 */

function vr_mail_log(array $entry): void {
    try {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/mail_log.jsonl';
        $entry['ts'] = $entry['ts'] ?? date('c');
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Send a plain text email.
 *
 * @param string      $to
 * @param string      $subject
 * @param string      $body
 * @param string|null $replyTo
 * @param string|null $from
 * @param string|null $fromName
 */
function vr_mail_send(string $to, string $subject, string $body, ?string $replyTo = null, ?string $from = null, ?string $fromName = null): bool {
    $to = trim($to);
    $subject = trim($subject);
    if ($to === '' || $subject === '') {
        vr_mail_log(['ok' => false, 'reason' => 'missing_to_or_subject', 'to' => $to, 'subject' => $subject]);
        return false;
    }

    // Guard against header injection.
    $to = preg_replace('/[\r\n]+/', '', $to);
    $subject = preg_replace('/[\r\n]+/', '', $subject);
    $replyTo = $replyTo !== null ? preg_replace('/[\r\n]+/', '', $replyTo) : null;

    $from = trim((string)($from ?? (defined('VETROOM_MAIL_FROM') ? VETROOM_MAIL_FROM : 'no-reply@vetroom.it')));
    if ($from === '') $from = 'no-reply@vetroom.it';
    $from = preg_replace('/[\r\n]+/', '', $from);
    $fromName = trim((string)($fromName ?? (defined('VETROOM_APP_NAME') ? VETROOM_APP_NAME : 'VetRoom')));
    if ($fromName === '') $fromName = 'VetRoom';

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Content-Type-Options: nosniff';
    $headers[] = 'X-Mailer: PHP/' . phpversion();
    $headers[] = 'From: ' . $fromName . ' <' . $from . '>';

    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $headersStr = implode("\r\n", $headers);

    $ok = false;
    $err = null;
    try {
        // Try setting envelope sender (improves deliverability on many hosts).
        // Some hosts forbid it; we fallback to plain mail().
        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $ok = @mail($to, $subject, $body, $headersStr, '-f' . $from);
        } else {
            $ok = @mail($to, $subject, $body, $headersStr);
        }
        if (!$ok) {
            // fallback without -f
            $ok = @mail($to, $subject, $body, $headersStr);
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    vr_mail_log([
        'ok' => (bool)$ok,
        'to' => $to,
        'subject' => $subject,
        'from' => $from,
        'reply_to' => $replyTo,
        'error' => $ok ? null : ($err ?? 'mail_failed'),
    ]);

    return (bool)$ok;
}
