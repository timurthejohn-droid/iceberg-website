<?php
/**
 * Двухфакторная аутентификация TOTP (RFC 6238) — чистый PHP, без библиотек.
 * Совместимо с Google Authenticator, Яндекс.Ключ, Authy, 1Password и т.п.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // base32

    /** Новый секрет (base32, 160 бит). */
    public static function generateSecret(): string
    {
        return self::base32encode(random_bytes(20));
    }

    /** otpauth://-ссылка для QR-кода в приложении-аутентификаторе. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $q = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
        return "otpauth://totp/$label?$q";
    }

    /** Проверка кода. window=1 => допускаем ±30 сек рассинхрон часов. */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        return self::verifyCounter($secret, $code, $window) !== null;
    }

    /**
     * То же, но возвращает НОМЕР временного окна, на котором код совпал (или null).
     * Номер нужен, чтобы запомнить использованный код: без этого перехваченный код
     * работает ещё полторы минуты и его можно применить повторно.
     */
    public static function verifyCounter(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return null;
        $counter = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $counter + $i), $code)) {
                return $counter + $i;
            }
        }
        return null;
    }

    /** Код для конкретного счётчика времени. */
    private static function code(string $secret, int $counter): string
    {
        $key = self::base32decode($secret);
        $bin = pack('N*', 0) . pack('N*', $counter);   // 64-битный счётчик, big-endian
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $part = (
            ((ord($hash[$offset])   & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) << 8)  |
             (ord($hash[$offset+3]) & 0xff)
        );
        return str_pad((string)($part % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32encode(string $data): string
    {
        $out = '';
        $bits = 0;
        $val = 0;
        foreach (str_split($data) as $ch) {
            $val = ($val << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($val >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($val << (5 - $bits)) & 0x1f];
        }
        return $out;
    }

    private static function base32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $out = '';
        $bits = 0;
        $val = 0;
        foreach (str_split($b32) as $ch) {
            $val = ($val << 5) | strpos(self::ALPHABET, $ch);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($val >> $bits) & 0xff);
            }
        }
        return $out;
    }
}
