<?php
/**
 * Подключение к SQLite (PDO) + создание схемы. Единственный источник структуры БД.
 * Схема применяется идемпотентно при каждом запуске (CREATE TABLE IF NOT EXISTS),
 * поэтому «миграция» = вызвать migrate(). Отдельного шага деплоя не нужно.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $file = rtrim(App::config('DATA_DIR'), '/') . '/content.db';
        ensure_dir(dirname($file), 0750);

        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Надёжность и параллельный доступ на shared-хостинге.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        // Права на сам файл базы — только владельцу.
        @chmod($file, 0600);

        self::$pdo = $pdo;
        self::migrate();
        return self::$pdo;
    }

    /** Создание/обновление схемы. Безопасно вызывать многократно. */
    public static function migrate(): void
    {
        $pdo = self::$pdo;
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE,
                pass_hash     TEXT    NOT NULL,
                totp_secret   TEXT,                 -- зашифрован APP_SECRET
                totp_enabled  INTEGER NOT NULL DEFAULT 0,
                role          TEXT    NOT NULL DEFAULT 'admin',
                created_at    INTEGER NOT NULL,
                last_login_at INTEGER,
                must_change   INTEGER NOT NULL DEFAULT 0,
                totp_last_counter INTEGER NOT NULL DEFAULT 0  -- последнее использованное окно 2FA
            );

            CREATE TABLE IF NOT EXISTS login_attempts (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ip       TEXT    NOT NULL,
                username TEXT,
                ok       INTEGER NOT NULL,
                ts       INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_attempts_ip  ON login_attempts(ip, ts);
            CREATE INDEX IF NOT EXISTS idx_attempts_usr ON login_attempts(username, ts);

            CREATE TABLE IF NOT EXISTS pages (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                slug          TEXT    NOT NULL UNIQUE,   -- '' = главная
                label         TEXT    NOT NULL,          -- человекочитаемое имя в админке
                baseline_path TEXT    NOT NULL,          -- путь эталонного HTML внутри BASELINE_DIR
                sort          INTEGER NOT NULL DEFAULT 0,
                updated_at    INTEGER
            );

            CREATE TABLE IF NOT EXISTS page_fields (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id    INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                field_key  TEXT    NOT NULL,   -- seo_title, seo_description, og_image, h1, ...
                value      TEXT    NOT NULL DEFAULT '',
                updated_at INTEGER,
                updated_by TEXT,
                UNIQUE(page_id, field_key)
            );

            CREATE TABLE IF NOT EXISTS field_history (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id    INTEGER NOT NULL,
                field_key  TEXT    NOT NULL,
                old_value  TEXT,
                ts         INTEGER NOT NULL,
                username   TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_hist ON field_history(page_id, field_key, ts);

            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS media (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                filename   TEXT    NOT NULL UNIQUE,   -- имя внутри uploads/
                orig_name  TEXT    NOT NULL,
                mime       TEXT    NOT NULL,
                size       INTEGER NOT NULL,
                width      INTEGER,
                height     INTEGER,
                created_at INTEGER NOT NULL,
                created_by TEXT
            );

            CREATE TABLE IF NOT EXISTS audit_log (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                ts       INTEGER NOT NULL,
                username TEXT,
                ip       TEXT,
                action   TEXT    NOT NULL,
                target   TEXT,
                details  TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts);
        SQL);

        // Колонки, добавленные после первой установки. CREATE TABLE IF NOT EXISTS их не заведёт,
        // поэтому дополняем отдельно — тоже идемпотентно.
        self::addColumn('users', 'totp_last_counter', 'INTEGER NOT NULL DEFAULT 0');
    }

    /** Добавить колонку, если её ещё нет (SQLite не умеет ADD COLUMN IF NOT EXISTS). */
    private static function addColumn(string $table, string $column, string $definition): void
    {
        $st = self::$pdo->prepare('SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?');
        $st->execute([$table, $column]);
        if ((int)$st->fetchColumn() === 0) {
            self::$pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }
}
