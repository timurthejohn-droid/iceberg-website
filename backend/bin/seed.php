<?php
/**
 * Инициализация страниц в базе + проверка, что эталонный HTML читается и SEO-поля
 * распознаются. Переопределения НЕ создаёт (сайт и так отдаётся из эталона),
 * только заводит строки страниц и показывает текущие значения для сверки.
 *   php bin/seed.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Только из командной строки.\n"); }

require __DIR__ . '/../app/bootstrap.php';
Database::pdo();
Content::syncPages();

fwrite(STDOUT, "Страницы заведены. Проверка эталонных значений:\n\n");
$baseDir = rtrim(App::config('BASELINE_DIR'), '/');
$missing = 0;

foreach (PageRegistry::pages() as $page) {
    $file = $baseDir . '/' . $page['file'];
    $ok = is_file($file);
    if (!$ok) $missing++;
    $mark = $ok ? '✔' : '✗ НЕТ ФАЙЛА';
    fwrite(STDOUT, sprintf("%s  %-42s %s\n", $mark, $page['label'], $page['file']));
    if ($ok) {
        $html = (string)file_get_contents($file);
        $title = Overlay::baselineValue($html, ['type' => 'title', 'key' => 'title']);
        fwrite(STDOUT, "      title: " . mb_strimwidth((string)$title, 0, 70, '…') . "\n");
    }
}

fwrite(STDOUT, "\n");
if ($missing) {
    fwrite(STDOUT, "⚠ Не найдено эталонов: $missing. Разложите собранный сайт в BASELINE_DIR (см. bin/install_layout.php).\n");
} else {
    fwrite(STDOUT, "Готово. Откройте сайт (страницы как раньше) и /admin/ для правок.\n");
}
