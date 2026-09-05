<?php
/**
 * Раскладка после копирования собранного сайта (dist/) в веб-корень.
 * Переносит index.html УПРАВЛЯЕМЫХ страниц из public_html в BASELINE_DIR,
 * чтобы запросы к ним шли через фронт-контроллер (PHP + наложение правок).
 * Снимки WP и статику не трогает — их отдаёт Apache напрямую.
 *   php bin/install_layout.php          # выполнить
 *   php bin/install_layout.php --dry    # только показать, что будет сделано
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Только из командной строки.\n"); }

require __DIR__ . '/../app/bootstrap.php';

$dry = in_array('--dry', $argv, true);
$pub  = rtrim(App::config('PUBLIC_DIR'), '/');
$base = rtrim(App::config('BASELINE_DIR'), '/');
$moved = 0; $missing = 0;

foreach (PageRegistry::pages() as $page) {
    $rel = $page['file'];
    $src = "$pub/$rel";
    $dst = "$base/$rel";

    if (!is_file($src)) {
        // Возможно, уже перенесён.
        $state = is_file($dst) ? 'уже в baseline' : 'НЕ НАЙДЕН в public_html';
        if (!is_file($dst)) $missing++;
        fwrite(STDOUT, sprintf("·  %-42s %s\n", $page['label'], $state));
        continue;
    }

    fwrite(STDOUT, sprintf("→  %-42s %s  →  baseline\n", $page['label'], $rel));
    if (!$dry) {
        ensure_dir(dirname($dst), 0750);
        if (!copy($src, $dst)) { fwrite(STDERR, "  ! не удалось скопировать $src\n"); continue; }
        @unlink($src);
        // Пустую папку из-под перенесённой внутренней страницы можно оставить — Apache отдаст её PHP.
        $moved++;
    }
}

fwrite(STDOUT, "\n" . ($dry ? "Пробный прогон. " : "Готово. Перенесено: $moved. "));
if ($missing) fwrite(STDOUT, "Не найдено эталонов: $missing (сначала скопируйте dist/ в public_html).");
fwrite(STDOUT, "\nПроверьте: `php bin/seed.php`, затем откройте сайт и /admin/.\n");
