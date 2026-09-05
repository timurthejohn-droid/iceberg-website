<?php
/**
 * Точка инициализации приложения. Подключается из public/index.php и public/admin/index.php.
 * Загружает конфиг, настраивает ошибки/автозагрузку, даёт доступ к настройкам через App::config().
 */
declare(strict_types=1);

final class App
{
    private static array $config = [];
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;

        // --- Конфиг ---
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Нет config.php — скопируйте config.example.php в config.php и заполните (см. DEPLOY.md).');
        }
        self::$config = require $file;

        if (!self::$config['APP_SECRET'] || str_starts_with((string)self::$config['APP_SECRET'], 'ЗАМЕНИТЬ')) {
            http_response_code(500);
            exit('APP_SECRET не задан в config.php.');
        }

        // --- Ошибки: логируем, не показываем пользователю ---
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ensure_dir(self::config('DATA_DIR'), 0750);
        ini_set('error_log', rtrim(self::config('DATA_DIR'), '/') . '/php-error.log');

        // --- Автозагрузка классов из app/lib ---
        spl_autoload_register(static function (string $class): void {
            $f = __DIR__ . '/lib/' . $class . '.php';
            if (is_file($f)) require $f;
        });

        date_default_timezone_set('Europe/Moscow');
        self::$booted = true;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    public static function baseUrl(): string
    {
        return rtrim((string)self::config('BASE_URL'), '/');
    }
}

// helpers.php нужен ещё до автозагрузки (ensure_dir и пр. используются в boot()).
require __DIR__ . '/lib/helpers.php';
App::boot();
