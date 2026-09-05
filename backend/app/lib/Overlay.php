<?php
/**
 * Наложение правок из БД на эталонный HTML.
 *
 * Принцип: эталон (baseline) неизменен байт-в-байт; заменяются ТОЛЬКО те фрагменты,
 * для которых в админке задано значение. Ничего не задано — страница отдаётся как собрана,
 * до последнего байта. Это защищает SEO-склейку: не тронутое в админке поисковик видит прежним.
 *
 * Почему не DOM: страницы весят до 2,3 МБ (шрифты и фото вшиты base64). Разбор в DOMDocument
 * их перелопачивает и меняет разметку. Поэтому — точечные подстановки строками и короткими
 * регулярками, привязанными к литеральным якорям.
 */
final class Overlay
{
    /** Поля, которые не удалось наложить в последнем вызове apply() (для диагностики в админке). */
    public static array $lastMissing = [];

    /** Собрать итоговый HTML управляемой страницы. */
    public static function render(array $page): string
    {
        $html = self::baselineHtml($page);
        if ($html === '') {
            // Решение о коде ответа принимает фронт-контроллер (там 503 + Retry-After),
            // чтобы поисковик подождал, а не выбросил адрес из индекса.
            error_log('Нет эталонного HTML: ' . $page['file']);
            return '';
        }
        return self::apply($html, $page, Content::fields($page['slug']), Content::allSettings());
    }

    public static function baselineHtml(array $page): string
    {
        $file = rtrim(App::config('BASELINE_DIR'), '/') . '/' . $page['file'];
        return is_file($file) ? (string)file_get_contents($file) : '';
    }

    /** Применить переопределения полей и глобальные настройки. */
    public static function apply(string $html, array $page, array $fields, array $settings): string
    {
        self::$lastMissing = [];
        $cssImages = [];

        foreach ($page['fields'] as $def) {
            $key = $def['key'];
            if (!array_key_exists($key, $fields)) continue;   // нет переопределения — пропускаем
            $val = (string)$fields[$key];

            if ($def['type'] === 'css_image') {              // копим, вставим одним блоком
                if ($val !== '') $cssImages[$def['selector']] = $val;
                continue;
            }
            $before = $html;
            $html = self::applyField($html, $def, $val);
            if ($html === $before && $val !== '') {
                self::$lastMissing[] = $key;                  // якорь не найден — правка не применилась
            }
        }

        if ($cssImages) $html = self::injectCss($html, $cssImages);
        return self::applyGlobals($html, $settings);
    }

    private static function applyField(string $html, array $def, string $val): string
    {
        switch ($def['type']) {
            case 'title':
                return self::elementText($html, 'title', h($val));
            case 'description':
                return self::headMeta($html, 'name', 'description', $val);
            case 'robots':
                return self::headMeta($html, 'name', 'robots', $val);
            case 'og_title':
                return self::headMeta($html, 'property', 'og:title', $val);
            case 'og_description':
                return self::headMeta($html, 'property', 'og:description', $val);
            case 'og_image':
                $url = self::absUrl($val);
                $html = self::headMeta($html, 'property', 'og:image', $url);
                return self::headMeta($html, 'name', 'twitter:image', $url);
            case 'canonical':
                return self::headLink($html, 'canonical', self::absUrl($val));
            case 'h1':
                return self::elementText($html, 'h1', self::sanitizeInline($val));
            case 'marker_text':
                return self::marker($html, $def['key'], h($val));
            case 'marker_inline':
                return self::marker($html, $def['key'], self::sanitizeInline($val));
            case 'marker_block':
                return self::marker($html, $def['key'], self::sanitizeBlock($val));
            case 'js_offer':
                return self::offerValue($html, (string)$def['offer_key'], self::sanitizeInline($val));
            default:
                return $html;
        }
    }

    // ---------- <head>: замена или ДОБАВЛЕНИЕ тега ----------

    /** Границы <head>. Работаем только внутри — в теле те же строки встречаются в тексте. */
    private static function headBounds(string $html): array
    {
        $end = stripos($html, '</head>');
        if ($end === false) return [0, 0, false];
        $start = stripos($html, '<head');
        $start = $start === false ? 0 : $start;
        return [$start, $end, true];
    }

    /** Заменить content= у мета-тега; если тега нет — добавить перед </head>. */
    private static function headMeta(string $html, string $attr, string $name, string $value): string
    {
        [$s, $e, $ok] = self::headBounds($html);
        if (!$ok) return $html;
        $head = substr($html, $s, $e - $s);

        $pattern = '/(<meta\s+[^>]*' . preg_quote($attr, '/') . '\s*=\s*"' . preg_quote($name, '/') . '"[^>]*?content\s*=\s*")[^"]*(")/i';
        $new = preg_replace_callback($pattern, static fn($m) => $m[1] . h($value) . $m[2], $head, 1, $cnt);

        if ($cnt > 0) {
            return substr($html, 0, $s) . $new . substr($html, $e);
        }
        // Обратный порядок атрибутов: content= идёт раньше name=/property=.
        $pattern2 = '/(<meta\s+[^>]*content\s*=\s*")([^"]*)("[^>]*' . preg_quote($attr, '/') . '\s*=\s*"' . preg_quote($name, '/') . '")/i';
        $new = preg_replace_callback($pattern2, static fn($m) => $m[1] . h($value) . $m[3], $head, 1, $cnt);
        if ($cnt > 0) {
            return substr($html, 0, $s) . $new . substr($html, $e);
        }
        if ($value === '') return $html;                       // нечего добавлять

        $tag = '<meta ' . $attr . '="' . h($name) . '" content="' . h($value) . '">';
        return substr($html, 0, $e) . "\n" . $tag . "\n" . substr($html, $e);
    }

    /** Заменить href у <link rel="…">; если его нет — добавить. */
    private static function headLink(string $html, string $rel, string $value): string
    {
        [$s, $e, $ok] = self::headBounds($html);
        if (!$ok) return $html;
        $head = substr($html, $s, $e - $s);

        $pattern = '/(<link\s+[^>]*rel\s*=\s*"' . preg_quote($rel, '/') . '"[^>]*?href\s*=\s*")[^"]*(")/i';
        $new = preg_replace_callback($pattern, static fn($m) => $m[1] . h($value) . $m[2], $head, 1, $cnt);
        if ($cnt > 0) return substr($html, 0, $s) . $new . substr($html, $e);

        $pattern2 = '/(<link\s+[^>]*href\s*=\s*")([^"]*)("[^>]*rel\s*=\s*"' . preg_quote($rel, '/') . '")/i';
        $new = preg_replace_callback($pattern2, static fn($m) => $m[1] . h($value) . $m[3], $head, 1, $cnt);
        if ($cnt > 0) return substr($html, 0, $s) . $new . substr($html, $e);

        if ($value === '') return $html;
        $tag = '<link rel="' . h($rel) . '" href="' . h($value) . '">';
        return substr($html, 0, $e) . "\n" . $tag . "\n" . substr($html, $e);
    }

    /** Свои фото поверх заглушек: один <style> перед </head>, ничего в вёрстке не трогаем. */
    private static function injectCss(string $html, array $map): string
    {
        $rules = '';
        foreach ($map as $selector => $url) {
            // Селекторы приходят из реестра (не от пользователя), URL — только /uploads/… или https://…
            $rules .= $selector . '{background-image:url("' . h(self::absUrl($url)) . '")!important}';
        }
        if ($rules === '') return $html;
        $block = '<style id="cms-media">' . $rules . '</style>';
        $e = stripos($html, '</head>');
        if ($e === false) return $html . $block;
        return substr($html, 0, $e) . "\n" . $block . "\n" . substr($html, $e);
    }

    // ---------- тело страницы ----------

    /** Заменить текст внутри ПЕРВОГО элемента $tag (title, h1). Только строковые операции. */
    private static function elementText(string $html, string $tag, string $value): string
    {
        $open = self::findTag($html, $tag);
        if ($open === null) return $html;
        [, $contentStart] = $open;
        $close = stripos($html, '</' . $tag, $contentStart);
        if ($close === false) return $html;
        return substr($html, 0, $contentStart) . $value . substr($html, $close);
    }

    /** Позиции открывающего тега: [начало тега, начало содержимого]. */
    private static function findTag(string $html, string $tag): ?array
    {
        $from = 0;
        $needle = '<' . $tag;
        while (($p = stripos($html, $needle, $from)) !== false) {
            $next = $html[$p + strlen($needle)] ?? '';
            if ($next === '>' || $next === ' ' || $next === "\n" || $next === "\t" || $next === "\r") {
                $gt = strpos($html, '>', $p);
                if ($gt === false) return null;
                return [$p, $gt + 1];
            }
            $from = $p + 1;   // это был другой тег (например, <header> при поиске <head>)
        }
        return null;
    }

    /** Текст между маркерами <!--cms:KEY-->…<!--/cms:KEY-->. Заменяет ВСЕ вхождения. */
    private static function marker(string $html, string $key, string $value): string
    {
        $open  = '<!--cms:' . $key . '-->';
        $close = '<!--/cms:' . $key . '-->';
        $out = '';
        $pos = 0;
        while (($s = strpos($html, $open, $pos)) !== false) {
            $e = strpos($html, $close, $s);
            if ($e === false) break;
            $out .= substr($html, $pos, $s - $pos) . $open . $value;
            $pos = $e;
        }
        return $out === '' ? $html : $out . substr($html, $pos);
    }

    /**
     * Значение в объекте OFFERS посадочной: `ключ: 'текст'`.
     * Определён один оффер (a), остальные закомментированы, поэтому берём первое вхождение.
     */
    private static function offerValue(string $html, string $key, string $value): string
    {
        $anchor = strpos($html, 'var OFFERS');
        if ($anchor === false) return $html;
        $region = substr($html, $anchor, 4000);
        $pattern = '/(\b' . preg_quote($key, '/') . '\s*:\s*\')((?:\\\\.|[^\'\\\\])*)(\')/';
        $new = preg_replace_callback(
            $pattern,
            static fn($m) => $m[1] . self::jsString($value) . $m[3],
            $region, 1, $cnt
        );
        if ($cnt === 0) return $html;
        return substr($html, 0, $anchor) . $new . substr($html, $anchor + strlen($region));
    }

    /** Экранирование для JS-строки в одинарных кавычках внутри <script>. */
    private static function jsString(string $v): string
    {
        $v = str_replace(['\\', "'", "\r", "\n"], ['\\\\', "\\'", '', ' '], $v);
        return str_replace('</', '<\/', $v);   // чтобы текст не закрыл <script>
    }

    /**
     * Глобальные настройки: телефон и почта во всех ссылках, видимых подписях и в разметке
     * для поисковиков.
     *
     * Важно: заменяем не «все телефоны на странице», а только ОСНОВНОЙ контакт группы.
     * Эталон берём из подвала — он одинаков на всех страницах. Иначе смена номера в админке
     * затёрла бы, например, телефон отдела кадров на странице вакансий.
     */
    private static function applyGlobals(string $html, array $s): string
    {
        [$oldTel, $oldTelText, $oldMail] = self::mainContacts($html);

        $telHref = preg_replace('/[^\d+]/', '', trim((string)($s['phone_href'] ?? '')));
        $telText = trim((string)($s['phone'] ?? ''));
        $mail    = trim((string)($s['email'] ?? ''));

        if ($oldTel !== null && ($telHref !== '' || $telText !== '')) {
            $newHref = $telHref !== '' ? $telHref : $oldTel;
            $newText = $telText !== '' ? h($telText) : null;
            $q = preg_quote($oldTel, '/');

            // Ссылки именно на основной номер. Внутри бывает и просто текст, и иконка рядом
            // с надписью (шапка), поэтому подпись меняем поиском старого номера внутри ссылки.
            $html = preg_replace_callback(
                '/(<a\b[^>]*href=")tel:' . $q . '("[^>]*>)(.*?)(<\/a>)/is',
                static function ($m) use ($newHref, $newText, $oldTelText) {
                    $inner = $m[3];
                    if ($newText !== null && $oldTelText !== null && $oldTelText !== '') {
                        $inner = str_replace($oldTelText, $newText, $inner);
                    }
                    return $m[1] . 'tel:' . $newHref . $m[2] . $inner . $m[4];
                },
                $html
            ) ?? $html;

            // Ссылки на тот же номер, не подошедшие под шаблон выше (например, кнопка без текста).
            $html = preg_replace_callback(
                '/href="tel:' . $q . '"/i',
                static fn() => 'href="tel:' . $newHref . '"',
                $html
            ) ?? $html;

            // Остатки в тексте и в сообщениях скриптов («Не удалось отправить. Позвоните: …»).
            // Номер записан одной узнаваемой строкой, поэтому замена по ней безопасна:
            // телефоны других отделов записаны иначе и под неё не попадают.
            if ($newText !== null && $oldTelText !== null && $oldTelText !== '') {
                $html = str_replace($oldTelText, $newText, $html);
            }

            // Разметка для поисковиков: сверяем по цифрам, формат записи там свой.
            $digits = preg_replace('/\D/', '', $oldTel);
            $html = preg_replace_callback('/("telephone":\s*")([^"]*)(")/', static function ($m) use ($digits, $newHref) {
                return preg_replace('/\D/', '', $m[2]) === $digits ? $m[1] . $newHref . $m[3] : $m[0];
            }, $html) ?? $html;
        }

        if ($oldMail !== null && $mail !== '') {
            $mailH = h($mail);
            $mailJson = str_replace(['\\', '"'], ['\\\\', '\\"'], $mail);
            $q = preg_quote($oldMail, '/');

            $html = preg_replace_callback(
                '/(<a\b[^>]*href=")mailto:' . $q . '("[^>]*>)(.*?)(<\/a>)/is',
                static fn($m) => $m[1] . 'mailto:' . $mailH . $m[2]
                    . str_replace($oldMail, $mailH, $m[3]) . $m[4],
                $html
            ) ?? $html;
            $html = preg_replace_callback('/href="mailto:' . $q . '"/i',
                static fn() => 'href="mailto:' . $mailH . '"', $html) ?? $html;
            $html = str_replace($oldMail, $mailH, $html);   // остатки в тексте и в скриптах
            $html = preg_replace_callback('/("email":\s*")' . $q . '(")/',
                static fn($m) => $m[1] . $mailJson . $m[2], $html) ?? $html;
        }

        return $html;
    }

    /**
     * Основные контакты сайта — как они записаны в подвале.
     * Подвал одинаков на всех страницах, поэтому это надёжная точка отсчёта.
     * Возврат: [телефон для ссылки, телефон как подпись, почта].
     */
    private static function mainContacts(string $html): array
    {
        $from = strripos($html, '<footer');
        $region = $from === false ? $html : substr($html, $from);

        $tel = $telText = $mail = null;
        if (preg_match('/<a\b[^>]*href="tel:([^"]+)"[^>]*>([^<]*)<\/a>/i', $region, $m)) {
            $tel = $m[1];
            $telText = trim($m[2]) !== '' ? trim($m[2]) : null;
        } elseif (preg_match('/href="tel:([^"]+)"/i', $region, $m)) {
            $tel = $m[1];
        }
        if (preg_match('/href="mailto:([^"]+)"/i', $region, $m)) {
            $mail = $m[1];
        }
        return [$tel, $telText, $mail];
    }

    // ---------- очистка пользовательского HTML ----------

    /** Строчный текст: только выделения, без атрибутов (кроме href у ссылки). */
    public static function sanitizeInline(string $v): string
    {
        $v = strip_tags($v, '<em><strong><b><i><br><span><a>');
        $v = self::cleanAttributes($v, ['a' => ['href', 'target', 'rel']]);
        return self::dropBadLinks($v);
    }

    /**
     * Содержимое страницы: разрешены блоки и классы — на классах держится оформление.
     * Вырезается всё исполняемое: script/style, обработчики on*, javascript:-ссылки.
     * <iframe> сохраняется только для карт и видео с доверенных доменов.
     */
    public static function sanitizeBlock(string $v): string
    {
        // Убираем скрипты и стили вместе с содержимым.
        $v = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $v) ?? $v;
        $v = preg_replace('#</?(script|style)\b[^>]*>#i', '', $v) ?? $v;

        $allowed = '<p><br><b><strong><i><em><u><span><div><section><h2><h3><h4><h5>'
                 . '<ul><ol><li><dl><dt><dd><a><img><figure><figcaption><blockquote>'
                 . '<table><thead><tbody><tr><th><td><hr><small><sup><sub><iframe><nav><picture><source>';
        $v = strip_tags($v, $allowed);

        // Атрибуты оформления и разметки сохраняем: на классах, style и data-* держится
        // и вид страницы, и её интерактив. Вырезается исполняемое: on*-обработчики
        // (они не в списке) и опасные схемы ссылок — ниже.
        $v = self::cleanAttributes($v, [
            '*'      => ['class', 'id', 'style', 'title', 'role', 'lang', 'dir', 'hidden'],
            'a'      => ['href', 'target', 'rel', 'download'],
            'img'    => ['src', 'srcset', 'sizes', 'alt', 'width', 'height', 'loading', 'decoding'],
            'source' => ['src', 'srcset', 'sizes', 'type', 'media'],
            'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'loading'],
            'td'     => ['colspan', 'rowspan'],
            'th'     => ['colspan', 'rowspan', 'scope'],
        ]);
        $v = self::dropBadLinks($v);
        return self::dropForeignFrames($v);
    }

    /** Оставить у каждого тега только разрешённые атрибуты. */
    private static function cleanAttributes(string $html, array $allow): array|string
    {
        return preg_replace_callback('#<([a-z][a-z0-9]*)\b([^>]*)>#i', static function ($m) use ($allow) {
            $tag = strtolower($m[1]);
            $ok = array_merge($allow['*'] ?? [], $allow[$tag] ?? []);
            if (!$ok) return '<' . $tag . '>';
            $keep = '';
            if (preg_match_all('#([a-z\-]+)\s*=\s*("[^"]*"|\'[^\']*\')#i', $m[2], $at, PREG_SET_ORDER)) {
                foreach ($at as $a) {
                    $name = strtolower($a[1]);
                    // aria-* и data-* — разметка доступности и зацепки для скриптов, оставляем.
                    $allowedName = in_array($name, $ok, true)
                        || str_starts_with($name, 'aria-')
                        || str_starts_with($name, 'data-');
                    if (!$allowedName) continue;
                    $val = trim($a[2], '"\'');
                    if (str_contains(strtolower($val), 'javascript:')) continue;
                    $keep .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
                }
            }
            return '<' . $tag . $keep . '>';
        }, $html) ?? $html;
    }

    /** Вырезать javascript:/data:-ссылки (кроме картинок-data). */
    private static function dropBadLinks(string $html): string
    {
        return preg_replace_callback('#\s(href|src)\s*=\s*"([^"]*)"#i', static function ($m) {
            $v = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
            if ($scheme === 'javascript' || $scheme === 'vbscript') return ' ' . $m[1] . '="#"';
            if (str_starts_with(strtolower(ltrim($v)), 'data:') && !str_starts_with(strtolower(ltrim($v)), 'data:image/')) {
                return ' ' . $m[1] . '="#"';
            }
            return $m[0];
        }, $html) ?? $html;
    }

    /** <iframe> — только карты и видео с известных доменов, остальные удаляем целиком. */
    private static function dropForeignFrames(string $html): string
    {
        $hosts = ['yandex.ru', 'yandex.com', 'www.youtube.com', 'youtube.com', 'rutube.ru', 'vk.com', 'vkvideo.ru'];
        return preg_replace_callback('#<iframe\b([^>]*)>(.*?)</iframe>#is', static function ($m) use ($hosts) {
            if (!preg_match('#src\s*=\s*"([^"]*)"#i', $m[1], $s)) return '';
            $host = strtolower((string)parse_url(html_entity_decode($s[1], ENT_QUOTES, 'UTF-8'), PHP_URL_HOST));
            foreach ($hosts as $ok) {
                if ($host === $ok || str_ends_with($host, '.' . $ok)) return $m[0];
            }
            return '';
        }, $html) ?? $html;
    }

    private static function absUrl(string $v): string
    {
        if ($v === '' || preg_match('#^https?://#i', $v)) return $v;
        return App::baseUrl() . '/' . ltrim($v, '/');
    }

    // ---------- чтение текущих значений из эталона (дефолты и подсказки в админке) ----------

    public static function baselineValue(string $html, array $def): ?string
    {
        switch ($def['type']) {
            case 'title':
                return self::grabElement($html, 'title');
            case 'h1':
                return self::grabElement($html, 'h1');
            case 'description':
                return self::grabMeta($html, 'name', 'description');
            case 'robots':
                return self::grabMeta($html, 'name', 'robots');
            case 'og_title':
                return self::grabMeta($html, 'property', 'og:title');
            case 'og_description':
                return self::grabMeta($html, 'property', 'og:description');
            case 'og_image':
                return self::grabMeta($html, 'property', 'og:image');
            case 'canonical':
                return self::grabAttr($html, '<link\s+[^>]*rel\s*=\s*"canonical"', 'href');
            case 'marker_text':
            case 'marker_inline':
            case 'marker_block':
                return self::grabMarker($html, $def['key']);
            case 'js_offer':
                return self::grabOffer($html, (string)$def['offer_key']);
            default:
                return null;   // css_image: значения в эталоне нет (там заглушка)
        }
    }

    private static function grabElement(string $html, string $tag): ?string
    {
        $open = self::findTag($html, $tag);
        if ($open === null) return null;
        $close = stripos($html, '</' . $tag, $open[1]);
        if ($close === false) return null;
        $v = substr($html, $open[1], $close - $open[1]);
        return $tag === 'title' ? html_entity_decode(trim($v), ENT_QUOTES, 'UTF-8') : trim($v);
    }

    private static function grabMeta(string $html, string $attr, string $name): ?string
    {
        [$s, $e, $ok] = self::headBounds($html);
        $head = $ok ? substr($html, $s, $e - $s) : $html;
        $v = self::grabAttr($head, '<meta\s+[^>]*' . preg_quote($attr, '/') . '\s*=\s*"' . preg_quote($name, '/') . '"', 'content');
        if ($v !== null) return $v;
        $p = '/<meta\s+[^>]*content\s*=\s*"([^"]*)"[^>]*' . preg_quote($attr, '/') . '\s*=\s*"' . preg_quote($name, '/') . '"/i';
        return preg_match($p, $head, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }

    private static function grabMarker(string $html, string $key): ?string
    {
        $open  = '<!--cms:' . $key . '-->';
        $close = '<!--/cms:' . $key . '-->';
        $s = strpos($html, $open);
        if ($s === false) return null;
        $e = strpos($html, $close, $s);
        if ($e === false) return null;
        $s += strlen($open);
        return trim(substr($html, $s, $e - $s));
    }

    private static function grabOffer(string $html, string $key): ?string
    {
        $anchor = strpos($html, 'var OFFERS');
        if ($anchor === false) return null;
        $region = substr($html, $anchor, 4000);
        $p = '/\b' . preg_quote($key, '/') . '\s*:\s*\'((?:\\\\.|[^\'\\\\])*)\'/';
        if (!preg_match($p, $region, $m)) return null;
        return str_replace(["\\'", '<\\/'], ["'", '</'], $m[1]);
    }

    private static function grabAttr(string $html, string $tagPrefix, string $attr): ?string
    {
        $pattern = '/' . $tagPrefix . '[^>]*?\s' . $attr . '\s*=\s*"([^"]*)"/i';
        return preg_match($pattern, $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }
}
