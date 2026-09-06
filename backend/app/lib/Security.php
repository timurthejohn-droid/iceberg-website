<?php
/**
 * Централизованная безопасность: security-заголовки, укрепление сессии,
 * принудительный HTTPS, IP-заслон для админки.
 */
final class Security
{
    /** Работаем ли по HTTPS (учитываем прокси хостинга через стандартный заголовок). */
    public static function isHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') return true;
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;   // sprinthost проксирует TLS
        return false;
    }

    /** Каноничный хост сайта — берём из конфига, а не из запроса. */
    public static function canonicalHost(): string
    {
        return (string)parse_url(App::baseUrl(), PHP_URL_HOST);
    }

    /**
     * Редирект на HTTPS. Пропускаем localhost — для локальной проверки без сертификата.
     *
     * Адрес назначения берём из конфига, а НЕ из заголовка Host: заголовок задаёт клиент,
     * и подстановка чужого домена превращала бы сайт в открытый редирект
     * (`Host: evil.example` → 301 на https://evil.example/...), удобный для фишинга.
     */
    public static function enforceHttps(): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocal = str_contains($host, 'localhost') || str_starts_with($host, '127.') || $host === '';
        if (!self::isHttps() && !$isLocal) {
            $target = self::canonicalHost() ?: preg_replace('/[^A-Za-z0-9.\-:]/', '', $host);
            header('Location: https://' . $target . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }

    /** Запуск сессии с безопасными cookie-параметрами + контроль тайм-аутов. */
    public static function sessionStart(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        session_name('icb_admin');
        session_set_cookie_params([
            'lifetime' => 0,                       // сессионная cookie
            'path'     => '/admin',                // cookie не уходит на публичные страницы
            'secure'   => self::isHttps(),
            'httponly' => true,                    // недоступна из JS
            'samesite' => 'Strict',                // защита от CSRF на уровне браузера
        ]);
        ini_set('session.use_strict_mode', '1');   // не принимать чужой session id
        ini_set('session.use_only_cookies', '1');
        session_start();

        $now = time();
        $idle = (int)App::config('SESSION_IDLE_TIMEOUT', 1800);
        $abs  = (int)App::config('SESSION_ABSOLUTE_TIMEOUT', 43200);

        // Абсолютный и idle-таймауты.
        if (isset($_SESSION['auth_at'])) {
            if ($now - $_SESSION['auth_at'] > $abs) { self::destroySession(); return; }
        }
        if (isset($_SESSION['last_seen'])) {
            if ($now - $_SESSION['last_seen'] > $idle) { self::destroySession(); return; }
        }
        $_SESSION['last_seen'] = $now;

        // Периодическая ротация id (раз в 15 мин) — против фиксации/кражи.
        if (!isset($_SESSION['rotated_at'])) {
            $_SESSION['rotated_at'] = $now;
        } elseif ($now - $_SESSION['rotated_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['rotated_at'] = $now;
        }
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    /**
     * Заголовки для ПУБЛИЧНЫХ страниц.
     *
     * CSP здесь мягче админской и обязана быть такой: вёрстка сборки держится на inline-стилях
     * и inline-скриптах (шейдер первого экрана, секвенция яхты, меню, формы), а из внешнего
     * подключается только счётчик Метрики. Поэтому 'unsafe-inline' оставлен осознанно —
     * политика не ловит инъекцию в саму страницу, но закрывает главное: подгрузку чужого
     * скрипта, отправку формы на чужой домен, подмену <base>, встраивание сайта в чужой фрейм.
     * Перечень источников можно переопределить ключом PUBLIC_CSP в config.php (пустая строка —
     * выключить заголовок совсем, если что-то сломается на боевом).
     */
    public static function publicHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
        header_remove('X-Powered-By');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $ya = 'https://mc.yandex.ru https://mc.yandex.com https://yastatic.net';
        $csp = App::config('PUBLIC_CSP', null);
        if ($csp === null) {
            $csp = "default-src 'self'; "
                 . "base-uri 'self'; "
                 . "object-src 'none'; "
                 . "frame-ancestors 'self'; "
                 . "form-action 'self'; "
                 . "script-src 'self' 'unsafe-inline' $ya; "
                 . "style-src 'self' 'unsafe-inline'; "
                 . "img-src 'self' data: blob: $ya https://*.yandex.net; "
                 . "font-src 'self' data:; "
                 . "media-src 'self' data: blob:; "
                 . "connect-src 'self' $ya; "
                 . "frame-src 'self' $ya https://www.youtube.com https://rutube.ru https://vk.com; "
                 . "worker-src 'self' blob:";
        }
        if ((string)$csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
    }

    /** Строгие заголовки для АДМИНКИ. Скрипты/стили — только по nonce, никаких сторонних источников. */
    public static function adminHeaders(string $nonce): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header_remove('X-Powered-By');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        $csp = "default-src 'none'; "
             . "base-uri 'none'; "
             . "form-action 'self'; "
             . "frame-ancestors 'none'; "
             . "img-src 'self' data:; "
             . "font-src 'self' data:; "
             . "style-src 'self' 'nonce-$nonce'; "
             . "script-src 'self' 'nonce-$nonce'; "
             . "connect-src 'self'";
        header("Content-Security-Policy: $csp");
    }

    /** Заслон по IP для /admin (если список задан в config). */
    public static function adminIpGate(): void
    {
        $allow = (array)App::config('ADMIN_IP_ALLOWLIST', []);
        if (!$allow) return;                         // список пуст = ограничение выключено
        if (!ip_in_list(client_ip(), $allow)) {
            http_response_code(403);
            exit('403 — доступ к админке разрешён только с доверенных IP.');
        }
    }

    /** Одноразовый nonce для CSP текущего запроса. */
    public static function nonce(): string
    {
        static $n = null;
        return $n ??= base64_encode(random_bytes(16));
    }
}
