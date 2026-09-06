<?php
/**
 * Проверка окружения хостинга под бэкенд. Не требует config.php и базы.
 * Запуск: `php bin/check_env.php` (CLI) или временно положить рядом с сайтом и открыть в браузере.
 * ⚠️ После проверки УДАЛИТЬ файл с сервера.
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
$checks = [];

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$checks[] = ['PHP ≥ 8.1', $phpOk, PHP_VERSION];

foreach (['pdo_sqlite' => true, 'sqlite3' => true, 'mbstring' => true, 'openssl' => true, 'fileinfo' => true] as $ext => $crit) {
    $checks[] = ["Расширение $ext" . ($crit ? ' (критично)' : ''), extension_loaded($ext), extension_loaded($ext) ? 'есть' : 'НЕТ'];
}
$gd = extension_loaded('gd') || extension_loaded('imagick');
$checks[] = ['GD или Imagick (пересжатие фото)', $gd, $gd ? 'есть' : 'нет — желательно'];

$checks[] = ['password_hash argon2id', defined('PASSWORD_ARGON2ID'), defined('PASSWORD_ARGON2ID') ? 'да' : 'нет (будет bcrypt)'];
$checks[] = ['ZipArchive (бэкапы)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'есть' : 'нет — бэкап копией'];

$writable = is_writable(__DIR__);
$checks[] = ['Запись рядом со скриптом', $writable, $writable ? 'ок' : 'нет прав на запись'];

$rewrite = !function_exists('apache_get_modules') || in_array('mod_rewrite', apache_get_modules(), true);
$checks[] = ['mod_rewrite', $rewrite, function_exists('apache_get_modules') ? ($rewrite ? 'есть' : 'НЕТ') : 'не проверить (не Apache-модуль PHP)'];

// Журнал заявок и база лежат ВЫШЕ веб-корня (в них персональные данные). Если хостинг заперт
// в public_html через open_basedir, туда не записать — это надо знать ДО заливки.
$basedir = (string)ini_get('open_basedir');
$checks[] = ['open_basedir не мешает (критично)', $basedir === '',
             $basedir === '' ? 'не задан — запись выше веб-корня разрешена'
                             : "задан: $basedir — журнал и данные положить внутрь разрешённых путей"];
$up = dirname(__DIR__, 2) . '/.icb-write-test';
$canWriteUp = @file_put_contents($up, 'x') !== false;
if ($canWriteUp) @unlink($up);
$checks[] = ['Запись на уровень выше проекта', $canWriteUp,
             $canWriteUp ? 'ок — журнал заявок можно держать вне веб-корня' : 'нет прав — см. DEPLOY.md, шаг 2'];

// Показ ошибок в браузере раскрывает пути и стектрейсы. Бэкенд его выключает сам, но если
// хостинг включил его жёстко (ini_set запрещён) — это надо увидеть заранее.
@ini_set('display_errors', '0');
$errShown = (string)ini_get('display_errors') !== '' && (string)ini_get('display_errors') !== '0';
$checks[] = ['Показ ошибок PHP выключается', !$errShown,
             $errShown ? 'ini_set не действует — просить хостинг выключить display_errors' : 'ок'];

$allOk = array_reduce($checks, fn($c, $r) => $c && ($r[1] || !str_contains($r[0], 'критично')), true);

if ($cli) {
    foreach ($checks as [$name, $ok, $note]) {
        fwrite(STDOUT, sprintf("[%s] %-40s %s\n", $ok ? 'OK' : '!!', $name, $note));
    }
    fwrite(STDOUT, "\n" . ($allOk ? "Окружение подходит.\n" : "Есть проблемы — см. строки с !!.\n"));
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo "<!doctype html><meta charset=utf-8><title>Проверка окружения</title>";
echo "<div style='font:15px/1.6 system-ui;max-width:640px;margin:6vh auto;padding:0 16px;color:#0A0C0E'>";
echo "<h1 style='font-weight:600;color:#123E63'>Проверка окружения</h1>";
echo "<table style='border-collapse:collapse;width:100%'>";
foreach ($checks as [$name, $ok, $note]) {
    $c = $ok ? '#1F5C42' : '#8F2433';
    $ic = $ok ? '✔' : '✗';
    echo "<tr style='border-bottom:1px solid #E3E7EA'>"
       . "<td style='padding:8px 6px;color:$c;font-weight:700'>$ic</td>"
       . "<td style='padding:8px 6px'>" . htmlspecialchars($name) . "</td>"
       . "<td style='padding:8px 6px;color:#6b7681'>" . htmlspecialchars($note) . "</td></tr>";
}
echo "</table>";
echo "<p style='margin-top:18px;padding:12px;border-radius:8px;background:#fbeef0;color:#8F2433'>"
   . "⚠️ Удалите этот файл (check_env.php) с сервера после проверки.</p></div>";
