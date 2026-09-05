<?php
/**
 * Хранилище контента: строки страниц, значения полей (переопределения),
 * история правок и глобальные настройки. Все записи — только подготовленными запросами.
 */
final class Content
{
    /** Завести в БД строку под каждую управляемую страницу (идемпотентно). */
    public static function syncPages(): void
    {
        $pdo = Database::pdo();
        $sort = 0;
        foreach (PageRegistry::pages() as $p) {
            $st = $pdo->prepare(
                'INSERT INTO pages (slug, label, baseline_path, sort)
                 VALUES (:slug, :label, :bp, :sort)
                 ON CONFLICT(slug) DO UPDATE SET label = :label, baseline_path = :bp, sort = :sort'
            );
            $st->execute([
                ':slug' => $p['slug'], ':label' => $p['label'],
                ':bp' => $p['file'], ':sort' => $sort++,
            ]);
        }
    }

    public static function pageId(string $slug): ?int
    {
        $st = Database::pdo()->prepare('SELECT id FROM pages WHERE slug = ?');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public static function pageRow(string $slug): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM pages WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    /** Все переопределения полей страницы: [field_key => value]. */
    public static function fields(string $slug): array
    {
        $id = self::pageId($slug);
        if ($id === null) return [];
        $st = Database::pdo()->prepare('SELECT field_key, value FROM page_fields WHERE page_id = ?');
        $st->execute([$id]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['field_key']] = $r['value'];
        }
        return $out;
    }

    public static function field(string $slug, string $key): ?string
    {
        $id = self::pageId($slug);
        if ($id === null) return null;
        $st = Database::pdo()->prepare('SELECT value FROM page_fields WHERE page_id = ? AND field_key = ?');
        $st->execute([$id, $key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Записать значение поля. Пишет историю, обновляет метку страницы, сбрасывает кэш. */
    public static function setField(string $slug, string $key, string $value, ?string $username): void
    {
        $pdo = Database::pdo();
        $id = self::pageId($slug);
        if ($id === null) return;

        $old = self::field($slug, $key);
        if ($old !== null && $old === $value) return;   // без изменений — не пишем

        // История прежнего значения (для отката).
        $h = $pdo->prepare(
            'INSERT INTO field_history (page_id, field_key, old_value, ts, username) VALUES (?,?,?,?,?)'
        );
        $h->execute([$id, $key, $old, time(), $username]);

        $st = $pdo->prepare(
            'INSERT INTO page_fields (page_id, field_key, value, updated_at, updated_by)
             VALUES (:pid, :k, :v, :ts, :by)
             ON CONFLICT(page_id, field_key) DO UPDATE SET value = :v, updated_at = :ts, updated_by = :by'
        );
        $st->execute([':pid' => $id, ':k' => $key, ':v' => $value, ':ts' => time(), ':by' => $username]);

        $pdo->prepare('UPDATE pages SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
        Cache::invalidate($slug);
    }

    /** Удалить переопределение поля — страница вернётся к эталонному значению. */
    public static function deleteField(string $slug, string $key, ?string $username): void
    {
        $id = self::pageId($slug);
        if ($id === null) return;
        $old = self::field($slug, $key);
        if ($old === null) return;

        $h = Database::pdo()->prepare(
            'INSERT INTO field_history (page_id, field_key, old_value, ts, username) VALUES (?,?,?,?,?)'
        );
        $h->execute([$id, $key, $old, time(), $username]);

        Database::pdo()->prepare('DELETE FROM page_fields WHERE page_id = ? AND field_key = ?')
            ->execute([$id, $key]);
        Database::pdo()->prepare('UPDATE pages SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
        Cache::invalidate($slug);
    }

    public static function history(string $slug, string $key, int $limit = 20): array
    {
        $id = self::pageId($slug);
        if ($id === null) return [];
        $st = Database::pdo()->prepare(
            'SELECT * FROM field_history WHERE page_id = ? AND field_key = ? ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$id, $key, $limit]);
        return $st->fetchAll();
    }

    // ---- Глобальные настройки ----

    public static function setting(string $key, string $default = ''): string
    {
        $st = Database::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public static function allSettings(): array
    {
        $out = [];
        foreach (Database::pdo()->query('SELECT key, value FROM settings')->fetchAll() as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    public static function setSetting(string $key, string $value): void
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value = :v'
        );
        $st->execute([':k' => $key, ':v' => $value]);
        Cache::flushAll();   // глобалки влияют на все страницы
    }
}
