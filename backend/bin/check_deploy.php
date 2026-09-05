<?php
/**
 * Предполётная проверка перед запуском: разложено ли всё по местам и что осталось доделать.
 * В отличие от check_env.php (тот про сам хостинг) — эта проверяет НАШУ установку.
 *
 *   php bin/check_deploy.php
 * либо временно положить рядом с сайтом и открыть в браузере (потом УДАЛИТЬ).
 */
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$rows = [];      // [уровень ok|warn|err, что проверяли, подробность]
$add = static function (string $level, string $what, string $note) use (&$rows): void {
    $rows[] = [$level, $what, $note];
};

$pub  = rtrim((string)App::config('PUBLIC_DIR'), '/');
$base = rtrim((string)App::config('BASELINE_DIR'), '/');
$data = rtrim((string)App::config('DATA_DIR'), '/');

// ---------- 1. Конфигурация ----------
$secret = (string)App::config('APP_SECRET');
$add(strlen($secret) >= 32 ? 'ok' : 'err', 'APP_SECRET задан', strlen($secret) >= 32 ? 'длина ' . strlen($secret) : 'слишком короткий или не заменён');
$baseUrl = App::baseUrl();
$add(str_starts_with($baseUrl, 'https://') ? 'ok' : 'warn', 'BASE_URL', $baseUrl ?: 'не задан');
$add(App::config('PREVIEW') ? 'warn' : 'ok', 'Режим', App::config('PREVIEW') ? 'PREVIEW (превью)' : 'боевой');

// ---------- 2. Раскладка ----------
$inside = static fn(string $p, string $root): bool => str_starts_with(realpath($p) ?: $p, (realpath($root) ?: $root) . '/');
$add($inside($data, $pub) ? 'err' : 'ok', 'data/ вне веб-корня', $inside($data, $pub) ? 'ЛЕЖИТ ВНУТРИ public_html — вынести!' : $data);
$add($inside($base, $pub) ? 'err' : 'ok', 'baseline/ вне веб-корня', $inside($base, $pub) ? 'ЛЕЖИТ ВНУТРИ public_html — вынести!' : $base);
$add(is_writable($data) ? 'ok' : 'err', 'Запись в data/', is_writable($data) ? 'есть' : 'НЕТ ПРАВ');
$up = $pub . '/uploads';
$add(is_dir($up) ? (is_writable($up) ? 'ok' : 'err') : 'warn', 'Папка uploads/',
     is_dir($up) ? (is_writable($up) ? 'есть, доступна на запись' : 'НЕТ ПРАВ НА ЗАПИСЬ') : 'появится при первой загрузке фото');
$add(is_file($up . '/.htaccess') ? 'ok' : 'warn', 'Запрет исполнения в uploads/', is_file($up . '/.htaccess') ? 'на месте' : 'нет .htaccess — скопировать из backend/public/uploads/');

// ---------- 3. Единый .htaccess ----------
$ht = $pub . '/.htaccess';
$htTxt = is_file($ht) ? (string)file_get_contents($ht) : '';
$merged = str_contains($htTxt, 'Фронт-контроллер') && str_contains($htTxt, 'trikotazhnie-');
$add($merged ? 'ok' : 'err', '.htaccess объединённый',
     $merged ? 'редиректы + роутинг в одном файле'
             : ($htTxt === '' ? 'НЕТ ФАЙЛА' : 'это не объединённая версия — взять backend/public/.htaccess (иначе или SEO-редиректы, или админка не работают)'));

// ---------- 4. Страницы: эталоны, маркеры, отсутствие дублей в веб-корне ----------
$pagesOk = $pagesBad = 0;
foreach (PageRegistry::pages() as $pg) {
    $file = $base . '/' . $pg['file'];
    $stale = $pub . '/' . $pg['file'];
    $label = $pg['label'];

    if (!is_file($file)) { $add('err', "Эталон: $label", 'НЕТ ФАЙЛА ' . $pg['file'] . ' — запустить bin/install_layout.php'); $pagesBad++; continue; }
    if (is_file($stale)) { $add('err', "Дубль в веб-корне: $label", 'Apache отдаст старую копию мимо админки — удалить ' . $pg['file']); $pagesBad++; continue; }

    $html = (string)file_get_contents($file);
    $miss = [];
    foreach ($pg['fields'] as $def) {
        if (!str_starts_with($def['type'], 'marker_')) continue;
        if (!str_contains($html, '<!--cms:' . $def['key'] . '-->')) $miss[] = $def['key'];
    }
    if ($miss) { $add('warn', "Метки правки: $label", 'нет меток: ' . implode(', ', $miss) . ' — прогнать tools/cms_markers.py'); $pagesBad++; }
    else { $pagesOk++; }
}
$add($pagesBad ? 'warn' : 'ok', 'Страницы под управлением', "готовы: $pagesOk из " . count(PageRegistry::pages()));

// ---------- 5. Формы и заявки ----------
$send = $pub . '/send.php';
$add(is_file($send) ? 'ok' : 'err', 'Обработчик форм send.php', is_file($send) ? 'на месте' : 'НЕТ — заявки уходить не будут');
$mailCfg = $pub . '/mail-config.php';
if (is_file($mailCfg)) {
    $txt = (string)file_get_contents($mailCfg);
    $stub = str_contains($txt, 'smtp.example.com') || str_contains($txt, 'CHANGE_ME_SMTP_PASSWORD');
    $add($stub ? 'err' : 'ok', 'Настройки почты', $stub ? 'ЗАГЛУШКИ SMTP не заменены — письма с заявками не уйдут' : 'заполнены');
} else {
    $add('err', 'Настройки почты', 'нет mail-config.php');
}
$phone = Content::setting('phone');
if ($phone !== '' && is_file($send)) {
    $txt = (string)file_get_contents($send);
    $has = str_contains($txt, $phone);
    $add($has ? 'ok' : 'warn', 'Телефон в тексте ошибки send.php',
         $has ? 'совпадает с настройками' : "в send.php остался прежний номер — поправить вручную (в админке он не правится)");
}
$log = Leads::logPath();
$add(is_file($log) ? 'ok' : 'warn', 'Журнал заявок', is_file($log) ? $log . ' (' . human_size((int)filesize($log)) . ')' : 'ещё не создан — появится с первой заявкой');

// ---------- 6. Юридические страницы ----------
foreach ([['policy.html', 'Политика конфиденциальности'], ['cookie_agreement.html', 'Соглашение о cookie']] as [$f, $name]) {
    $p = $base . '/' . $f;
    $txt = is_file($p) ? (string)file_get_contents($p) : '';
    $stub = str_contains($txt, 'legal-stub') || str_contains($txt, 'Текст готовится');
    $edited = Content::field(trim($f, '/'), 'page_body');
    $add($stub && $edited === null ? 'err' : 'ok', $name,
         $stub && $edited === null ? 'ЗАГЛУШКА «Текст готовится» — ссылки на неё стоят под каждой формой' : 'текст заполнен');
}

// ---------- 7. Доступ в админку ----------
$users = (int)Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$with2fa = (int)Database::pdo()->query('SELECT COUNT(*) FROM users WHERE totp_enabled = 1')->fetchColumn();
$add($users > 0 ? 'ok' : 'err', 'Пользователи админки', $users > 0 ? "$users, из них с 2FA: $with2fa" : 'нет ни одного — bin/create_admin.php');
$adminHt = $pub . '/admin/.htaccess';
$basic = is_file($adminHt) && preg_match('/^\s*AuthType\s+Basic/mi', (string)file_get_contents($adminHt));
$add($basic ? 'ok' : 'warn', 'Второй заслон на /admin (HTTP Basic)', $basic ? 'включён' : 'выключен (по умолчанию) — включается в admin/.htaccess');

// ---------- вывод ----------
$levels = ['err' => 0, 'warn' => 0, 'ok' => 0];
foreach ($rows as [$l]) $levels[$l]++;

if (PHP_SAPI === 'cli') {
    $mark = ['ok' => ' OK ', 'warn' => ' !  ', 'err' => '!!!!'];
    // sprintf выравнивает по байтам, кириллица в UTF-8 занимает по два — считаем символы.
    $pad = static fn(string $v, int $w): string => $v . str_repeat(' ', max(1, $w - mb_strlen($v)));
    foreach ($rows as [$l, $what, $note]) {
        fwrite(STDOUT, '[' . $mark[$l] . '] ' . $pad($what, 40) . $note . "\n");
    }
    fwrite(STDOUT, "\nГотово: {$levels['ok']} · предупреждений: {$levels['warn']} · блокирует запуск: {$levels['err']}\n");
    exit($levels['err'] > 0 ? 1 : 0);
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
$color = ['ok' => '#1F5C42', 'warn' => '#7a5b00', 'err' => '#8F2433'];
$icon  = ['ok' => '✔', 'warn' => '!', 'err' => '✗'];
echo "<!doctype html><meta charset=utf-8><title>Проверка установки</title>";
echo "<div style='font:15px/1.6 system-ui;max-width:760px;margin:5vh auto;padding:0 16px;color:#0A0C0E'>";
echo "<h1 style='font-weight:600;color:#123E63'>Проверка установки</h1>";
echo "<table style='border-collapse:collapse;width:100%'>";
foreach ($rows as [$l, $what, $note]) {
    echo "<tr style='border-bottom:1px solid #E3E7EA'>"
       . "<td style='padding:8px 6px;color:{$color[$l]};font-weight:700'>{$icon[$l]}</td>"
       . "<td style='padding:8px 6px'>" . htmlspecialchars($what) . "</td>"
       . "<td style='padding:8px 6px;color:#6b7681'>" . htmlspecialchars($note) . "</td></tr>";
}
echo "</table>";
echo "<p style='margin-top:18px'>Готово: {$levels['ok']} · предупреждений: {$levels['warn']} · <b>блокирует запуск: {$levels['err']}</b></p>";
echo "<p style='padding:12px;border-radius:8px;background:#fbeef0;color:#8F2433'>⚠️ Удалите этот файл с сервера после проверки.</p></div>";
