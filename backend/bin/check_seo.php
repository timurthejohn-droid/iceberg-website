<?php
/**
 * Сверка поисковых сигналов: title / description / canonical / robots / H1.
 *
 * Два режима:
 *   php bin/check_seo.php
 *       Сравнивает ЭТАЛОН и то, что реально отдаётся сейчас (с правками из админки).
 *       Отвечает на вопрос «не сломал ли кто-нибудь SEO через админку».
 *
 *   php bin/check_seo.php --live https://iceberg.spb.ru
 *       Сравнивает то, что отдадим мы, с ЖИВЫМ сайтом по тем же адресам.
 *       Это и есть автосверка склейки: прогнать ДО переключения и сразу ПОСЛЕ.
 *
 * Расхождение в title/canonical — повод остановиться: это несущие сигналы переезда.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Только из командной строки.\n"); }

require __DIR__ . '/../app/bootstrap.php';
Database::pdo();

$live = null;
foreach ($argv as $i => $a) {
    if ($a === '--live') $live = rtrim((string)($argv[$i + 1] ?? 'https://iceberg.spb.ru'), '/');
}

/** Достать сигналы из HTML. */
function signals(string $html): array
{
    $get = static function (string $re) use ($html): string {
        return preg_match($re, $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
    };
    $h1 = $get('/<h1\b[^>]*>(.*?)<\/h1>/is');
    return [
        'title'       => $get('/<title\b[^>]*>(.*?)<\/title>/is'),
        'description' => $get('/<meta\s+[^>]*name="description"[^>]*content="([^"]*)"/i'),
        'canonical'   => $get('/<link\s+[^>]*rel="canonical"[^>]*href="([^"]*)"/i'),
        'robots'      => $get('/<meta\s+[^>]*name="robots"[^>]*content="([^"]*)"/i'),
        'h1'          => trim(strip_tags($h1)),
    ];
}

/** Загрузить живую страницу (curl есть на любом хостинге; без него — file_get_contents). */
function fetch(string $url): ?string
{
    $ch = function_exists('curl_init') ? curl_init($url) : null;
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'iceberg-seo-check/1.0',
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return is_string($r) ? $r : null;
    }
    $r = @file_get_contents($url);
    return is_string($r) ? $r : null;
}

// printf выравнивает по байтам, кириллица занимает по два — считаем символы.
$pad = static fn(string $v, int $w): string => $v . str_repeat(' ', max(1, $w - mb_strlen($v)));

$labels = ['title' => 'Title', 'description' => 'Description', 'canonical' => 'Canonical',
           'robots' => 'Robots', 'h1' => 'H1'];
$diffs = 0;
$pages = 0;

foreach (PageRegistry::pages() as $pg) {
    $baseHtml = Overlay::baselineHtml($pg);
    if ($baseHtml === '') { echo "✗ {$pg['label']}: нет эталона\n"; $diffs++; continue; }

    $ours = signals(Overlay::apply($baseHtml, $pg, Content::fields($pg['slug']), Content::allSettings()));

    if ($live !== null) {
        $url = $live . $pg['url'];
        $html = fetch($url);
        if ($html === null) { echo "✗ {$pg['label']}: не открылась $url\n"; $diffs++; continue; }
        $other = signals($html);
        $what = 'живой сайт';
    } else {
        $other = signals($baseHtml);
        $what = 'эталон';
    }

    $pages++;
    $bad = [];
    foreach ($labels as $k => $name) {
        if ($k === 'robots' && $live !== null) continue;          // на живом WP свои настройки индексации
        if (trim($ours[$k]) !== trim($other[$k])) $bad[] = $k;
    }
    if (!$bad) {
        echo '✔  ' . $pad($pg['label'], 44) . "совпадает с «$what»\n";
        continue;
    }
    $diffs++;
    echo '!  ' . $pad($pg['label'], 44) . 'расхождения: ' . implode(', ', array_map(fn($k) => $labels[$k], $bad)) . "\n";
    $show = static fn(string $v): string => $v === '' ? '(тега нет)' : mb_strimwidth($v, 0, 90, '…');
    foreach ($bad as $k) {
        echo '      ' . $pad($labels[$k], 13) . $pad($what, 12) . ': ' . $show($other[$k]) . "\n";
        echo '      ' . $pad('', 13)          . $pad('у нас', 12) . ': ' . $show($ours[$k]) . "\n";
    }
    // Тега не было, а мы его добавили — это не потеря сигнала, а улучшение.
    $added = array_filter($bad, static fn($k) => trim($other[$k]) === '' && trim($ours[$k]) !== '');
    if ($added && count($added) === count($bad)) {
        echo "      (на живом сайте этих тегов нет — мы их добавляем, сигналы не теряются)\n";
    }
}

echo "\nСтраниц проверено: $pages · с расхождениями: $diffs\n";
if ($live !== null) {
    echo $diffs === 0
        ? "Склейка чистая: адреса и сигналы совпадают с живым сайтом.\n"
        : "⚠️ Есть расхождения — разобрать ДО переключения. Title и Canonical критичны.\n";
} else {
    echo $diffs === 0
        ? "Правки из админки поисковые сигналы не меняли.\n"
        : "⚠️ Сигналы отличаются от собранных. Если это не осознанная правка — вернуть значения.\n";
}
exit($diffs > 0 ? 1 : 0);
