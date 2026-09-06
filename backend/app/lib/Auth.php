<?php
/**
 * Аутентификация: пароли (argon2id), двухшаговый вход с 2FA, состояние сессии.
 *
 * Поток входа:
 *   1) логин+пароль  -> Auth::attemptPassword()  -> pending (ждём 2FA)
 *   2а) 2FA включена -> ввод кода                 -> Auth::verifyTotpAndLogin()
 *   2б) 2FA не настроена -> показать QR, привязать -> Auth::enableTotp()
 */
final class Auth
{
    private const PENDING_TTL = 300;   // 5 мин на прохождение 2FA после пароля

    /** Что именно не так с последним кодом 2FA: 'bad' | 'replay' | 'blocked'. Для текста ошибки. */
    public static string $lastTotpError = 'bad';

    /** Алгоритм хеша: argon2id если доступен, иначе — системный дефолт (bcrypt). */
    private static function hashAlgo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::hashAlgo());
    }

    /** Требования к паролю. Возвращает текст ошибки или null. */
    public static function passwordProblem(string $p): ?string
    {
        if (mb_strlen($p) < 12) return 'Пароль должен быть не короче 12 символов.';
        if (!preg_match('/[A-Za-zА-Яа-я]/u', $p) || !preg_match('/\d/', $p)) {
            return 'Пароль должен содержать буквы и цифры.';
        }
        return null;
    }

    public static function findByUsername(string $username): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function createUser(string $username, string $password, string $role = 'admin'): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO users (username, pass_hash, role, created_at) VALUES (?,?,?,?)'
        );
        $st->execute([$username, self::hashPassword($password), $role, time()]);
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * Шаг 1: проверка логина/пароля с учётом анти-перебора.
     * Возврат: ['ok'=>bool, 'error'=>?string, 'retry_after'=>int, 'next'=>'totp'|'setup'|null]
     */
    public static function attemptPassword(string $username, string $password): array
    {
        $ip = client_ip();
        $wait = RateLimiter::retryAfter($ip, $username);
        if ($wait > 0) {
            return ['ok' => false, 'retry_after' => $wait,
                    'error' => "Слишком много попыток. Повторите через $wait сек."];
        }

        $user = self::findByUsername($username);
        // Всегда выполняем verify (даже при отсутствии юзера) — против тайминг-атаки на существование логина.
        // Заглушка-хеш считается «живым» алгоритмом сервера, чтобы password_verify гарантированно работал.
        static $dummy = null;
        $dummy ??= password_hash('dummy-nonexistent-user', self::hashAlgo());
        $hash = $user['pass_hash'] ?? $dummy;
        $valid = $user !== null && password_verify($password, $hash);

        RateLimiter::record($ip, $username, $valid);
        Audit::log($valid ? 'login_password_ok' : 'login_password_fail', $username);

        if (!$valid) {
            return ['ok' => false, 'retry_after' => 0, 'error' => 'Неверный логин или пароль.'];
        }

        // Пароль верный. Обновляем хеш, если изменились параметры.
        if (password_needs_rehash($hash, self::hashAlgo())) {
            $u = Database::pdo()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
            $u->execute([self::hashPassword($password), $user['id']]);
        }

        // Переводим в состояние ожидания 2FA.
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int)$user['id'];
        $_SESSION['pending_at']  = time();

        $next = (int)$user['totp_enabled'] === 1 ? 'totp' : 'setup';
        return ['ok' => true, 'retry_after' => 0, 'error' => null, 'next' => $next];
    }

    public static function pendingUser(): ?array
    {
        $uid = $_SESSION['pending_uid'] ?? null;
        $at  = $_SESSION['pending_at'] ?? 0;
        if (!$uid || time() - $at > self::PENDING_TTL) {
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            return null;
        }
        return self::findById((int)$uid);
    }

    /**
     * Шаг 2а: проверка кода 2FA для уже привязанного секрета.
     *
     * Второй шаг защищён так же, как первый: пароль мог утечь, и тогда единственное,
     * что стоит между злоумышленником и админкой, — шестизначный код. Без счётчика
     * попыток его подбирают перебором, поэтому здесь тот же RateLimiter, что и на пароле,
     * плюс счётчик в сессии: после нескольких промахов пароль спрашивается заново.
     */
    public static function verifyTotpAndLogin(string $code): bool
    {
        $user = self::pendingUser();
        if (!$user || (int)$user['totp_enabled'] !== 1) return false;

        self::$lastTotpError = 'bad';
        $ip = client_ip();
        if (RateLimiter::retryAfter($ip, $user['username']) > 0) {
            Audit::log('login_2fa_blocked', $user['username']);
            self::$lastTotpError = 'blocked';
            return false;
        }

        $secret = Crypto::decrypt((string)$user['totp_secret']);
        $counter = $secret ? Totp::verifyCounter($secret, $code) : null;

        // Код верен, но уже использовался (перехват из чужих рук / повтор запроса) — не пускаем.
        if ($counter !== null && $counter <= (int)($user['totp_last_counter'] ?? 0)) {
            Audit::log('login_2fa_replay', $user['username']);
            self::$lastTotpError = 'replay';
            $counter = null;
        }

        if ($counter === null) {
            RateLimiter::record($ip, (string)$user['username'], false);
            Audit::log('login_2fa_fail', $user['username']);
            self::countTwofaFail();
            return false;
        }

        $st = Database::pdo()->prepare('UPDATE users SET totp_last_counter = ? WHERE id = ?');
        $st->execute([$counter, $user['id']]);
        RateLimiter::record($ip, (string)$user['username'], true);

        $user['totp_last_counter'] = $counter;
        self::finalizeLogin($user);
        return true;
    }

    /** Несколько промахов подряд — сбрасываем ожидание 2FA, пароль спрашиваем заново. */
    private static function countTwofaFail(): void
    {
        $_SESSION['twofa_fails'] = (int)($_SESSION['twofa_fails'] ?? 0) + 1;
        if ($_SESSION['twofa_fails'] >= 5) {
            unset($_SESSION['pending_uid'], $_SESSION['pending_at'],
                  $_SESSION['setup_secret'], $_SESSION['twofa_fails']);
        }
    }

    /** Шаг 2б: привязка и включение 2FA при первом входе. Секрет держим в сессии до подтверждения. */
    public static function beginTotpSetup(): string
    {
        if (empty($_SESSION['setup_secret'])) {
            $_SESSION['setup_secret'] = Totp::generateSecret();
        }
        return $_SESSION['setup_secret'];
    }

    public static function confirmTotpSetup(string $code): bool
    {
        $user = self::pendingUser();
        $secret = $_SESSION['setup_secret'] ?? '';
        $counter = ($user && $secret) ? Totp::verifyCounter($secret, $code) : null;
        if ($counter === null) {
            Audit::log('2fa_setup_fail', $user['username'] ?? null);
            self::countTwofaFail();
            return false;
        }
        // Код привязки сразу помечаем использованным: иначе им же можно войти второй раз.
        $st = Database::pdo()->prepare(
            'UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_counter = ? WHERE id = ?'
        );
        $st->execute([Crypto::encrypt($secret), $counter, $user['id']]);
        unset($_SESSION['setup_secret']);
        Audit::log('2fa_enabled', $user['username']);

        $user = self::findById((int)$user['id']);
        self::finalizeLogin($user);
        return true;
    }

    private static function finalizeLogin(array $user): void
    {
        session_regenerate_id(true);
        unset($_SESSION['pending_uid'], $_SESSION['pending_at'],
              $_SESSION['setup_secret'], $_SESSION['twofa_fails']);
        $_SESSION['auth_uid']   = (int)$user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['auth_at']    = time();
        $_SESSION['last_seen']  = time();
        $_SESSION['rotated_at'] = time();

        $u = Database::pdo()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $u->execute([time(), $user['id']]);
        Audit::log('login_ok', $user['username']);
    }

    public static function user(): ?array
    {
        $uid = $_SESSION['auth_uid'] ?? null;
        return $uid ? self::findById((int)$uid) : null;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['auth_uid']);
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            redirect('/admin/?p=login');
        }
    }

    public static function logout(): void
    {
        $name = $_SESSION['username'] ?? null;
        Audit::log('logout', $name);
        Security::destroySession();
    }

    public static function changePassword(int $uid, string $new): void
    {
        $st = Database::pdo()->prepare('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?');
        $st->execute([self::hashPassword($new), $uid]);
        Audit::log('password_changed', $_SESSION['username'] ?? null);
    }
}
