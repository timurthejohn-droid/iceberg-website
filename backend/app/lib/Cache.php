<?php
/**
 * Полностраничный кэш управляемых страниц. После наложения правок готовый HTML
 * кладётся в файл; следующие запросы отдаются с диска (скорость статики).
 * Сбрасывается при любом сохранении контента страницы/настроек.
 */
final class Cache
{
    private static function dir(): string
    {
        $d = rtrim(App::config('DATA_DIR'), '/') . '/cache';
        ensure_dir($d, 0750);
        return $d;
    }

    private static function path(string $slug): string
    {
        return self::dir() . '/page_' . md5($slug) . '.html';
    }

    public static function get(string $slug): ?string
    {
        // На превью кэш выключен — удобнее видеть правки мгновенно.
        if (App::config('PREVIEW')) return null;
        $f = self::path($slug);
        if (!is_file($f)) return null;
        $html = file_get_contents($f);
        return $html === false ? null : $html;
    }

    public static function put(string $slug, string $html): void
    {
        if (App::config('PREVIEW')) return;
        $f = self::path($slug);
        // Атомарная запись.
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $html, LOCK_EX) !== false) {
            @rename($tmp, $f);
            @chmod($f, 0640);
        }
    }

    public static function invalidate(string $slug): void
    {
        @unlink(self::path($slug));
    }

    public static function flushAll(): void
    {
        foreach (glob(self::dir() . '/page_*.html') ?: [] as $f) {
            @unlink($f);
        }
    }
}
