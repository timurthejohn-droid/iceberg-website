<?php
/**
 * Симметричное шифрование секретов в базе (секрет 2FA хранится зашифрованным).
 * Ключ выводится из APP_SECRET. Используем libsodium (если есть) или AES-256-GCM (openssl).
 */
final class Crypto
{
    private static function key(): string
    {
        // 32 байта ключа из APP_SECRET.
        return hash('sha256', (string)App::config('APP_SECRET'), true);
    }

    public static function encrypt(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct = sodium_crypto_secretbox($plain, $nonce, self::key());
            return 'v1s:' . base64_encode($nonce . $ct);
        }
        // Фолбэк: AES-256-GCM.
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1o:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): ?string
    {
        [$tagId, $b64] = array_pad(explode(':', $stored, 2), 2, '');
        $raw = base64_decode($b64, true);
        if ($raw === false) return null;

        if ($tagId === 'v1s' && function_exists('sodium_crypto_secretbox_open')) {
            $nl = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $nonce = substr($raw, 0, $nl);
            $ct = substr($raw, $nl);
            $out = sodium_crypto_secretbox_open($ct, $nonce, self::key());
            return $out === false ? null : $out;
        }
        if ($tagId === 'v1o') {
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct = substr($raw, 28);
            $out = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
            return $out === false ? null : $out;
        }
        return null;
    }
}
