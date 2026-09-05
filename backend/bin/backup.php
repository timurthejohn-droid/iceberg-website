<?php
/**
 * Резервная копия: база content.db + папка uploads → data/backups/ZIP.
 * Удобно повесить в cron хостинга (раз в сутки). Хранит последние 30 копий.
 *   php bin/backup.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Только из командной строки.\n"); }

require __DIR__ . '/../app/bootstrap.php';
Database::pdo();   // гарантирует WAL-checkpoint через закрытие ниже

$dataDir = rtrim(App::config('DATA_DIR'), '/');
$backDir = "$dataDir/backups";
ensure_dir($backDir, 0750);

$db = "$dataDir/content.db";
$stamp = date('Ymd-His');
$zipPath = "$backDir/backup-$stamp.zip";

if (!class_exists('ZipArchive')) {
    // Фолбэк: просто копия базы.
    $dst = "$backDir/content-$stamp.db";
    copy($db, $dst);
    @chmod($dst, 0600);
    exit("ZipArchive недоступен — сохранена копия базы: $dst\n");
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    exit("Не удалось создать архив $zipPath\n");
}
if (is_file($db)) $zip->addFile($db, 'content.db');
// WAL-файлы, если есть (полнота снимка).
foreach (['-wal', '-shm'] as $suf) {
    if (is_file($db . $suf)) $zip->addFile($db . $suf, 'content.db' . $suf);
}

$uploads = rtrim(App::config('PUBLIC_DIR'), '/') . '/uploads';
if (is_dir($uploads)) {
    foreach (glob("$uploads/*") ?: [] as $f) {
        if (is_file($f) && basename($f) !== '.htaccess') {
            $zip->addFile($f, 'uploads/' . basename($f));
        }
    }
}
$zip->close();
@chmod($zipPath, 0600);
fwrite(STDOUT, "✔ Бэкап создан: $zipPath (" . human_size((int)filesize($zipPath)) . ")\n");

// Чистим старые, оставляем 30.
$all = glob("$backDir/backup-*.zip") ?: [];
rsort($all);
foreach (array_slice($all, 30) as $old) { @unlink($old); }
