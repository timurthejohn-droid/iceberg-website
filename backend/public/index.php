<?php
/**
 * Публичный фронт-контроллер сайта.
 *
 * Реальные файлы (картинки, шрифты, send.php, снимки WP, /admin) отдаёт Apache сам —
 * см. .htaccess: сюда попадает только то, для чего файла нет. А нет его как раз у
 * управляемых страниц: их эталоны лежат в BASELINE_DIR, вне веб-корня.
 * Здесь на эталон накладываются правки из админки, результат кэшируется и отдаётся.
 */
declare(strict_types=1);

// app/ — сосед веб-корня: и в репозитории (backend/app), и на сервере (../app рядом с public_html).
require __DIR__ . '/../app/bootstrap.php';

Security::enforceHttps();

// --- Разбор адреса ---
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rawurldecode($uri);

// Защита от обхода каталогов и мусора в адресе.
if (str_contains($path, '..') || !preg_match('#^/[a-z0-9\-/._]*$#i', $path)) {
    not_found();
}

$page = PageRegistry::findByUrl($path);

if ($page === null) {
    // Не управляемая страница. Файлы сюда доходить не должны (их отдал Apache),
    // но на всякий случай пробуем статический index.html, иначе 404.
    $static = rtrim(App::config('PUBLIC_DIR'), '/') . rtrim($path, '/') . '/index.html';
    if (is_file($static)) {
        Security::publicHeaders();
        header('Content-Type: text/html; charset=utf-8');
        readfile($static);
        exit;
    }
    not_found();
}

// Каталожный адрес без хвостового слэша — приводим к каноничному виду (301),
// чтобы не плодить дубли для поисковика.
if ($page['url'] !== $path && $page['url'] === $path . '/') {
    header('Location: ' . $page['url'], true, 301);
    exit;
}

/*
 * Страховка на случай отказа слоя админки (недоступна база, нет прав на кэш, ошибка наложения).
 * Сайт переезжает с сохранением поисковых сигналов, поэтому 500 на всех адресах — недопустимая
 * цена за сбой CMS. Отдаём эталон: это ровно тот HTML, что был бы без админки, только без правок.
 */
$html = null;
try {
    if (Content::pageRow($page['slug']) === null) {
        Content::syncPages();   // первый заход до сидинга — заводим строки страниц
    }
    $html = Cache::get($page['slug']);
    if ($html === null) {
        $html = Overlay::render($page);
        Cache::put($page['slug'], $html);
    }
} catch (Throwable $e) {
    error_log('Слой админки недоступен, отдаём эталон: ' . $e->getMessage());
    $html = null;
}
if ($html === null || $html === '') {
    $html = Overlay::baselineHtml($page);
}
if ($html === '') {
    // Нет даже эталона — это поломка установки. 503 (временно недоступно), а не 404/500:
    // поисковик подождёт и не выбросит адрес из индекса.
    http_response_code(503);
    header('Retry-After: 600');
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Сайт временно недоступен</title>'
       . '<div style="font:16px/1.5 system-ui;padding:12vh 8vw;color:#123E63">'
       . '<h1 style="font-weight:300">Сайт временно недоступен</h1>'
       . '<p>Мы уже чиним. Позвоните: <a href="tel:+78123256993">+7 (812) 325-69-93</a></p></div>');
}

Security::publicHeaders();
header('Content-Type: text/html; charset=utf-8');

// Условный кэш браузера.
$etag = '"' . md5($html) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=0, must-revalidate');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

echo $html;
exit;

function not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $file = rtrim(App::config('PUBLIC_DIR'), '/') . '/404.html';
    if (is_file($file)) { readfile($file); exit; }
    echo '<!doctype html><meta charset="utf-8"><title>404</title>'
       . '<div style="font:16px/1.5 system-ui;padding:12vh 8vw;color:#123E63">'
       . '<h1 style="font-weight:300">404 — страница не найдена</h1>'
       . '<p><a href="/" style="color:#2B6FA4">На главную</a></p></div>';
    exit;
}
