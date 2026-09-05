<?php
/**
 * Реестр управляемых страниц и их редактируемых полей.
 * Единственное место, где перечислено, что можно править в админке.
 *
 * Соответствует сборке 04-site-iceberg/dist (версия подрядчика, принята 4 сент 2026).
 * Маркеры в HTML расставляет tools/cms_markers.py — ключи здесь и там ОДНИ И ТЕ ЖЕ.
 *
 * Типы полей (как их накладывает Overlay):
 *   title, description, og_title, og_description, og_image, canonical, robots
 *                 — теги в <head>; если тега нет, он ДОБАВЛЯЕТСЯ
 *   h1            — первый <h1> в теле
 *   marker_text   — текст между <!--cms:KEY-->…<!--/cms:KEY--> (без HTML)
 *   marker_inline — то же, разрешены em/strong/br/span/a
 *   marker_block  — то же, разрешены абзацы, списки, заголовки, таблицы (содержимое страницы)
 *   css_image     — фото-заглушка: подставляет background-image для CSS-селектора
 *   js_offer      — поле оффера посадочной (объект OFFERS в её скрипте)
 */
final class PageRegistry
{
    /** Общий SEO-набор, одинаковый для всех страниц. */
    private static function seoFields(): array
    {
        return [
            ['key' => 'title',           'label' => 'Заголовок вкладки (Title)',     'type' => 'title',          'group' => 'SEO', 'help' => 'Показывается во вкладке браузера и ссылкой в результатах поиска. 50–60 символов.'],
            ['key' => 'description',     'label' => 'Описание (Description)',        'type' => 'description',    'group' => 'SEO', 'help' => 'Короткий текст под ссылкой в поиске. 120–160 символов.'],
            ['key' => 'og_title',        'label' => 'Заголовок при отправке ссылки', 'type' => 'og_title',       'group' => 'SEO', 'help' => 'Как называется страница, когда ссылку кидают в мессенджер или соцсеть.'],
            ['key' => 'og_description',  'label' => 'Описание при отправке ссылки',  'type' => 'og_description', 'group' => 'SEO', 'help' => 'Текст в превью ссылки при отправке.'],
            ['key' => 'og_image',        'label' => 'Картинка при отправке ссылки',  'type' => 'og_image',       'group' => 'SEO', 'help' => 'Изображение в превью ссылки, 1200×630. Выберите из медиатеки.'],
            ['key' => 'canonical',       'label' => 'Canonical URL',                 'type' => 'canonical',      'group' => 'SEO', 'help' => 'Каноничный адрес страницы. МЕНЯТЬ НЕ РЕКОМЕНДУЕТСЯ — это несущая часть SEO-склейки.', 'advanced' => true],
            ['key' => 'robots',          'label' => 'Индексация (robots)',           'type' => 'robots',         'group' => 'SEO', 'help' => 'Управляет индексацией страницы. Трогать только осознанно.', 'advanced' => true],
        ];
    }

    private static function h1(string $help = 'Главный видимый заголовок на самой странице.'): array
    {
        return ['key' => 'h1', 'label' => 'Заголовок H1 на странице', 'type' => 'h1', 'group' => 'Контент', 'help' => $help];
    }

    /** Содержимое внутренней страницы целиком (между шапкой и подвалом). */
    private static function pageBody(string $help): array
    {
        return ['key' => 'page_body', 'label' => 'Содержимое страницы', 'type' => 'marker_block',
                'group' => 'Контент', 'rows' => 22, 'help' => $help];
    }

    /** Сквозное поле: строка копирайта в подвале. Есть на каждой странице. */
    private static function copyright(): array
    {
        return ['key' => 'g_copy', 'label' => 'Строка копирайта в подвале', 'type' => 'marker_text',
                'group' => 'Подвал', 'help' => 'Нижняя строка на всех страницах. Год основания сверьте с руководством.'];
    }

    /** Поля главной страницы. */
    private static function homeFields(): array
    {
        $f = [];

        $f[] = ['key' => 'hero_eyebrow', 'label' => 'Надпись над заголовком', 'type' => 'marker_text', 'group' => 'Первый экран',
                'help' => 'Мелкая строка над большим заголовком.'];
        $f[] = self::h1('Большой заголовок первого экрана. Курсивом выделяется то, что в теге <em>.');
        $f[] = ['key' => 'hero_sub', 'label' => 'Подзаголовок первого экрана', 'type' => 'marker_inline', 'group' => 'Первый экран',
                'help' => 'Строка под заголовком. Можно выделять слова тегом <em>.'];

        $co = [
            1 => ['Iceberg Tech (технические ткани)', '.ph-tech'],
            2 => ['Iceberg Biflex (эластичные ткани)', '.ph-biflex'],
            3 => ['Iceberg Factory (производство)', '.ph-factory'],
            4 => ['EMDI (собственный бренд)', '.ph-emdi'],
        ];
        foreach ($co as $i => [$name, $sel]) {
            $f[] = ['key' => "co{$i}_kick", 'label' => "$name — надпись сверху", 'type' => 'marker_text', 'group' => 'Наши компании'];
            $f[] = ['key' => "co{$i}_desc", 'label' => "$name — описание",       'type' => 'marker_inline', 'group' => 'Наши компании'];
            $f[] = ['key' => "co{$i}_photo", 'label' => "$name — фото карточки", 'type' => 'css_image', 'selector' => $sel, 'group' => 'Фото',
                    'help' => 'Заменяет фото-заглушку в карточке. Горизонтальное фото, от 1200 px по ширине.'];
        }

        for ($i = 1; $i <= 4; $i++) {
            $f[] = ['key' => "aud{$i}_h", 'label' => "Аудитория $i — заголовок", 'type' => 'marker_text',   'group' => 'Для кого'];
            $f[] = ['key' => "aud{$i}_p", 'label' => "Аудитория $i — описание",  'type' => 'marker_inline', 'group' => 'Для кого'];
            $f[] = ['key' => "aud{$i}_photo", 'label' => "Аудитория $i — фото",  'type' => 'css_image', 'selector' => '.ph-aud' . ($i - 1), 'group' => 'Фото',
                    'help' => 'Вертикальное фото — панель показывается в полный рост.'];
        }

        $f[] = ['key' => 'yacht_h', 'label' => 'Блок Tech — заголовок', 'type' => 'marker_inline', 'group' => 'Блок с яхтой',
                'help' => 'Заголовок над кадрами яхты. Курсив — тег <em>.'];
        $f[] = ['key' => 'yacht_p', 'label' => 'Блок Tech — описание',  'type' => 'marker_text',   'group' => 'Блок с яхтой'];

        $f[] = ['key' => 'about_h', 'label' => 'О группе — заголовок', 'type' => 'marker_inline', 'group' => 'О группе'];
        $f[] = ['key' => 'about_p', 'label' => 'О группе — текст',     'type' => 'marker_text',   'group' => 'О группе'];
        $f[] = ['key' => 'about_facts', 'label' => 'О группе — три факта', 'type' => 'marker_block', 'group' => 'О группе', 'rows' => 6,
                'help' => 'Три плитки: в <b> — крупное значение, в <span> — подпись. ⚠️ Сейчас здесь «1991 год основания», а в подвале «© 2003» — сверьте с руководством.'];

        $f[] = ['key' => 'num_lede', 'label' => 'Цифры — вводная строка', 'type' => 'marker_text', 'group' => 'Группа в цифрах'];
        $f[] = ['key' => 'num_grid', 'label' => 'Цифры — три показателя', 'type' => 'marker_block', 'group' => 'Группа в цифрах', 'rows' => 12,
                'help' => 'В <div class="num"> — само число, в <h3> — подпись, в <p> — пояснение.'];
        $f[] = ['key' => 'num_note', 'label' => 'Цифры — примечание под блоком', 'type' => 'marker_text', 'group' => 'Группа в цифрах',
                'help' => '⚠️ Сейчас там черновая пометка «цифры уточняются» — она видна посетителям. Очистите поле, чтобы убрать строку.'];

        $f[] = ['key' => 'con_dl', 'label' => 'Контакты — адрес, проезд, телефон, почта', 'type' => 'marker_block', 'group' => 'Контакты', 'rows' => 10,
                'help' => 'Блок контактов внизу главной. Телефон и почта подставляются из «Настроек» автоматически.'];

        $f[] = self::copyright();
        return $f;
    }

    /** Поля посадочной производства (первый экран у неё задаётся скриптом). */
    private static function landingFields(): array
    {
        // Ключ поля в админке — в нижнем регистре; offer_key — как свойство названо в скрипте.
        $o = static fn(string $k, string $label, string $type, string $help = ''): array =>
            ['key' => 'offer_' . strtolower(preg_replace('/([A-Z])/', '_$1', $k)),
             'label' => $label, 'type' => 'js_offer', 'offer_key' => $k,
             'group' => 'Первый экран (оффер)', 'help' => $help];

        return [
            self::h1('Заголовок в самом HTML. Его видят поисковики; посетителю скрипт показывает заголовок оффера (ниже). Менять только вместе с SEO.'),
            $o('eyebrow',   'Надпись над заголовком', 'js_offer'),
            $o('h1',        'Заголовок оффера', 'js_offer', 'То, что видит посетитель. Курсив — тег <em>. Формулировка согласована с клиентом.'),
            $o('sub',       'Подзаголовок оффера', 'js_offer'),
            $o('cta',       'Надпись на кнопке', 'js_offer'),
            $o('formTitle', 'Заголовок формы', 'js_offer'),
            $o('formNote',  'Текст над полями формы', 'js_offer'),
            ['key' => 'lp_final_h', 'label' => 'Заголовок финального блока с формой', 'type' => 'marker_inline', 'group' => 'Контент'],
            self::copyright(),
        ];
    }

    /** Определения всех управляемых страниц. */
    public static function pages(): array
    {
        $seo = self::seoFields();

        $inner = static function (string $url, string $file, string $label, string $group, string $bodyHelp) use ($seo): array {
            return [
                'slug'  => trim($url, '/'),
                'url'   => $url,
                'file'  => $file,
                'label' => $label,
                'group' => $group,
                'fields' => array_merge($seo, [
                    self::h1(),
                    self::pageBody($bodyHelp),
                    self::copyright(),
                ]),
            ];
        };

        $pages = [];

        $pages[] = [
            'slug' => '', 'url' => '/', 'file' => 'index.html',
            'label' => 'Главная', 'group' => 'Основные',
            'fields' => array_merge($seo, self::homeFields()),
        ];

        $pages[] = $inner('/tkani-optom/', 'tkani-optom/index.html', 'Ткани оптом', 'Основные',
            'Весь текст страницы. Заголовки — <h2>, абзацы — <p>, списки — <ul><li>. Классы у блоков лучше не убирать: на них держится оформление.');
        $pages[] = $inner('/contacts/', 'contacts/index.html', 'Контакты', 'Основные',
            'Весь текст страницы вместе с картой. Блок с картой (<iframe>) не удаляйте, если карта нужна.');
        $pages[] = $inner('/vacancy/', 'vacancy/index.html', 'Вакансии', 'Основные',
            'Текст вакансии. Чтобы снять вакансию — замените текст на сообщение о том, что набор закрыт.');

        $pages[] = [
            'slug' => 'shveinoe-proizvodstvo', 'url' => '/shveinoe-proizvodstvo/',
            'file' => 'shveinoe-proizvodstvo/index.html',
            'label' => 'Производство — посадочная (флагман)', 'group' => 'Производство',
            'fields' => array_merge($seo, self::landingFields()),
        ];

        $pages[] = $inner('/izgotovlenie-kupalnikov-i-sportivnoi-odezhdi/', 'izgotovlenie-kupalnikov-i-sportivnoi-odezhdi/index.html',
            'Купальники и спортивная одежда', 'Производство', 'Текст страницы.');
        $pages[] = $inner('/izgotovlenie-startovykh-maek/', 'izgotovlenie-startovykh-maek/index.html',
            'Стартовые майки', 'Производство', 'Текст страницы.');
        $pages[] = $inner('/mashinnaya-vishivka/', 'mashinnaya-vishivka/index.html',
            'Машинная вышивка', 'Производство', 'Текст страницы.');
        $pages[] = $inner('/tkani-print/', 'tkani-print/index.html',
            'Печать на ткани', 'Производство', 'Текст страницы.');

        $pages[] = $inner('/policy.html', 'policy.html', 'Политика конфиденциальности', 'Юридические',
            'Сюда вставляется текст политики. Сейчас на странице заглушка «Текст готовится» — до замены на настоящий документ сайт запускать нельзя: ссылки на него стоят под каждой формой.');
        $pages[] = $inner('/cookie_agreement.html', 'cookie_agreement.html', 'Соглашение о cookie', 'Юридические',
            'Сюда вставляется текст соглашения. Сейчас заглушка — ссылка на него стоит в баннере про cookie.');

        return $pages;
    }

    /** Соответствие URL → страница. Ключ маршрутизации публичной части. */
    public static function findByUrl(string $url): ?array
    {
        $url = '/' . ltrim($url, '/');
        foreach (self::pages() as $p) {
            if ($p['url'] === $url) return $p;
        }
        // Каталожные адреса допускаем и без хвостового слэша: /contacts → /contacts/
        if (!str_ends_with($url, '/') && !str_contains(basename($url), '.')) {
            foreach (self::pages() as $p) {
                if ($p['url'] === $url . '/') return $p;
            }
        }
        return null;
    }

    public static function isManagedUrl(string $url): bool
    {
        return self::findByUrl($url) !== null;
    }

    public static function find(string $slug): ?array
    {
        foreach (self::pages() as $p) {
            if ($p['slug'] === $slug) return $p;
        }
        return null;
    }

    /** Глобальные настройки (сквозные для всех страниц). */
    public static function globalFields(): array
    {
        return [
            ['key' => 'phone',      'label' => 'Телефон',            'type' => 'text',  'help' => 'Как он показывается на сайте: +7 (812) 325-69-93. Меняется сразу на всех страницах.'],
            ['key' => 'phone_href', 'label' => 'Телефон для ссылки', 'type' => 'text',  'help' => 'Тот же номер без пробелов и скобок: +78123256993. По нему идёт звонок с телефона.'],
            ['key' => 'email',      'label' => 'E-mail',             'type' => 'text',  'help' => 'Почта для связи. Меняется во всех ссылках и в разметке для поисковиков.'],
            ['key' => 'og_cover',   'label' => 'Картинка-превью по умолчанию', 'type' => 'image', 'help' => 'Используется страницами, у которых не задана своя (1200×630).'],
        ];
    }
}
