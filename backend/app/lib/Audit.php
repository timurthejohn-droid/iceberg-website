<?php
/**
 * Журнал действий: входы, выходы, правки контента, загрузка/удаление медиа,
 * управление пользователями. Нужен и для безопасности, и чтобы видеть «кто что менял».
 */
final class Audit
{
    public static function log(string $action, ?string $target = null, ?string $details = null): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO audit_log (ts, username, ip, action, target, details) VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            time(),
            $_SESSION['username'] ?? null,
            client_ip(),
            $action,
            $target,
            $details,
        ]);
    }

    /** Последние записи для страницы журнала. */
    public static function recent(int $limit = 200): array
    {
        $limit = max(1, min($limit, 1000));
        $st = Database::pdo()->query(
            "SELECT * FROM audit_log ORDER BY id DESC LIMIT $limit"
        );
        return $st->fetchAll();
    }
}
