<?php
declare(strict_types=1);

/**
 * Minimal crypto helper.
 *
 * Used for:
 * - Platform 2FA code card encryption at rest
 *
 * Notes:
 * - Stores a per-install secret key in /app/data/app_secret.key (best effort protected by .htaccess).
 * - If OpenSSL is not available, it falls back to reversible base64 "encryption" (still functional, less secure).
 */

function vr_crypto_key_path(): string {
    return __DIR__ . '/../data/app_secret.key';
}

function vr_crypto_get_key(): string {
    $path = vr_crypto_key_path();
    if (is_file($path)) {
        $raw = (string)@file_get_contents($path);
        $raw = trim($raw);
        $bin = base64_decode($raw, true);
        if ($bin !== false && strlen($bin) >= 32) {
            return substr($bin, 0, 32);
        }
    }
    $key = random_bytes(32);
    // Store as base64 to keep it filesystem-friendly.
    @file_put_contents($path, base64_encode($key));
    @chmod($path, 0600);
    return $key;
}

/**
 * Encrypt a string.
 * Returns an array with keys: enc, nonce, tag.
 */
function vr_crypto_encrypt(string $plain): array {
    $plain = (string)$plain;
    if (!function_exists('openssl_encrypt')) {
        return [
            'enc' => base64_encode($plain),
            'nonce' => null,
            'tag' => null,
        ];
    }
    $key = vr_crypto_get_key();
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return [
            'enc' => base64_encode($plain),
            'nonce' => null,
            'tag' => null,
        ];
    }
    return [
        'enc' => base64_encode($cipher),
        'nonce' => base64_encode($nonce),
        'tag' => base64_encode($tag),
    ];
}

function vr_crypto_decrypt(?string $enc, ?string $nonce, ?string $tag): string {
    if ($enc === null) return '';

    // Fallback (no openssl or no nonce/tag)
    if (!function_exists('openssl_decrypt') || !$nonce || !$tag) {
        $plain = base64_decode($enc, true);
        return ($plain === false) ? '' : (string)$plain;
    }

    $key = vr_crypto_get_key();
    $cipher = base64_decode($enc, true);
    $nb = base64_decode($nonce, true);
    $tb = base64_decode($tag, true);
    if ($cipher === false || $nb === false || $tb === false) return '';
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nb, $tb);
    return ($plain === false) ? '' : (string)$plain;
}
