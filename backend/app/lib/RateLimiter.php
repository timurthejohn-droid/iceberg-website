<?php
/**
 * Защита входа от подбора пароля. Считает неудачные попытки по IP и по логину
 * в скользящем окне; при превышении лимита — блокировка с НАРАСТАЮЩЕЙ задержкой.
 */
final class RateLimiter
{
    /** Записать факт попытки входа. */
    public static function record(string $ip, string $username, bool $ok): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO login_attempts (ip, username, ok, ts) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$ip, $username, $ok ? 1 : 0, time()]);

        // При успехе — чистим неудачи этого логина (снимаем блок).
        if ($ok) {
            $d = Database::pdo()->prepare(
                'DELETE FROM login_attempts WHERE username = ? AND ok = 0'
            );
            $d->execute([$username]);
        }
        self::gc();
    }

    /**
     * Сколько секунд ещё блокировка (0 = не заблокировано).
     * Блокируем если за окно набралось >= LOGIN_MAX_ATTEMPTS неудач по IP ИЛИ по логину.
     */
    public static function retryAfter(string $ip, string $username): int
    {
        $window = (int)App::config('LOGIN_LOCK_WINDOW', 900);
        $max    = (int)App::config('LOGIN_MAX_ATTEMPTS', 5);
        $base   = (int)App::config('LOGIN_LOCK_BASE', 30);
        $since  = time() - $window;

        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) c, MAX(ts) last FROM login_attempts
             WHERE ok = 0 AND ts >= ? AND (ip = ? OR username = ?)'
        );
        $st->execute([$since, $ip, $username]);
        $row = $st->fetch();
        $fails = (int)($row['c'] ?? 0);
        $last  = (int)($row['last'] ?? 0);

        if ($fails < $max) return 0;

        // Нарастающая задержка: base * 2^(fails-max), потолок 1 час.
        $delay = min($base * (2 ** ($fails - $max)), 3600);
        $elapsed = time() - $last;
        return max(0, $delay - $elapsed);
    }

    /** Удаляем записи старше суток, чтобы таблица не пухла. */
    private static function gc(): void
    {
        if (random_int(1, 20) !== 1) return;         // не на каждом запросе
        $d = Database::pdo()->prepare('DELETE FROM login_attempts WHERE ts < ?');
        $d->execute([time() - 86400]);
    }
}
