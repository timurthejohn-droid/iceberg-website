<?php
/**
 * Интерфейс админки. Максимально простой и понятный: крупные подписи, подсказки,
 * группировка «SEO» и «Контент», предпросмотр, счётчики символов, выбор фото из медиатеки.
 * Стили и скрипты — инлайн под nonce (строгая CSP, без сторонних источников).
 */
declare(strict_types=1);

final class View
{
    // ---------- Каркас ----------

    private static function head(string $title): string
    {
        $nonce = Security::nonce();
        Security::adminHeaders($nonce);
        header('Content-Type: text/html; charset=utf-8');
        $css = self::css();
        return "<!doctype html><html lang=ru><head><meta charset=utf-8>"
            . "<meta name=viewport content='width=device-width,initial-scale=1'>"
            . "<meta name=robots content='noindex,nofollow'>"
            . "<title>" . h($title) . " · ICEBERG</title>"
            . "<style nonce=\"$nonce\">$css</style></head><body>";
    }

    private static function flash(): string
    {
        $f = take_flash();
        if (!$f) return '';
        $cls = $f['type'] === 'error' ? 'msg msg--err' : 'msg msg--ok';
        return "<div class='$cls'>" . h($f['msg']) . "</div>";
    }

    /** Экран входа/2FA — центрированная карточка без навигации. */
    private static function authShell(string $title, string $body): void
    {
        echo self::head($title);
        echo "<div class='auth'><div class='auth__card'>"
           . "<div class='brand'>ICEBERG<span>админка сайта</span></div>"
           . self::flash()
           . $body
           . "</div><p class='auth__foot'>Защищённая зона. Все входы фиксируются в журнале.</p></div>";
        echo self::js() . "</body></html>";
    }

    /** Рабочий экран с навигацией. */
    private static function shell(string $active, string $title, string $body): void
    {
        $me = Auth::user();
        $newLeads = Leads::counts()['new'] ?? 0;
        $nav = [
            'leads'     => 'Заявки',
            'dashboard' => 'Страницы',
            'media'     => 'Медиатека',
            'settings'  => 'Настройки',
            'users'     => 'Пользователи',
            'audit'     => 'Журнал',
        ];
        $links = '';
        foreach ($nav as $k => $label) {
            $cur = $active === $k ? ' is-active' : '';
            $badge = ($k === 'leads' && $newLeads > 0) ? "<b class='nav__n'>$newLeads</b>" : '';
            $links .= "<a class='nav__i$cur' href='/admin/?p=$k'>" . h($label) . "$badge</a>";
        }
        echo self::head($title);
        echo "<header class='top'>"
           . "<div class='top__brand'>ICEBERG <span>админка</span></div>"
           . "<nav class='nav'>$links</nav>"
           . "<div class='top__me'><a href='/admin/?p=account'>" . h($me['username'] ?? '') . "</a>"
           . "<a class='btn btn--ghost' href='/admin/?p=logout'>Выход</a></div>"
           . "</header>"
           . "<main class='wrap'>" . self::flash() . $body . "</main>";
        echo self::pickerMarkup() . self::js() . "</body></html>";
    }

    // ---------- Вход ----------

    public static function login(): void
    {
        $b = "<form method=post action='/admin/?p=login' class='form'>"
           . Csrf::field()
           . "<h1>Вход</h1>"
           . self::input('username', 'Логин', '', ['autofocus' => true, 'autocomplete' => 'username'])
           . self::input('password', 'Пароль', '', ['type' => 'password', 'autocomplete' => 'current-password'])
           . "<button class='btn btn--primary btn--wide'>Войти</button>"
           . "</form>";
        self::authShell('Вход', $b);
    }

    public static function twofa(): void
    {
        $b = "<form method=post action='/admin/?p=twofa' class='form'>"
           . Csrf::field()
           . "<h1>Код подтверждения</h1>"
           . "<p class='muted'>Откройте приложение-аутентификатор и введите текущий 6-значный код.</p>"
           . self::input('code', 'Код из приложения', '', ['inputmode' => 'numeric', 'autofocus' => true, 'autocomplete' => 'one-time-code', 'maxlength' => 6, 'class' => 'code-in'])
           . "<button class='btn btn--primary btn--wide'>Подтвердить</button>"
           . "<a class='link' href='/admin/?p=logout'>Отмена</a>"
           . "</form>";
        self::authShell('Подтверждение', $b);
    }

    public static function twofaSetup(string $secret, string $uri): void
    {
        $grp = trim(chunk_split($secret, 4, ' '));
        $b = "<form method=post action='/admin/?p=twofa_setup' class='form'>"
           . Csrf::field()
           . "<h1>Настройка 2FA</h1>"
           . "<p class='muted'>Один раз привяжите вход к приложению-аутентификатору "
           . "(Google Authenticator, Яндекс.Ключ, Authy, 1Password).</p>"
           . "<ol class='steps'>"
           . "<li>Откройте приложение → «Добавить» → «Ввести ключ вручную».</li>"
           . "<li>Название: <b>ICEBERG</b>. Ключ (введите как есть):</li>"
           . "</ol>"
           . "<div class='secret'>" . h($grp) . "</div>"
           . "<p class='muted small'>Или откройте ссылку на телефоне: <a class='link' href='" . h($uri) . "'>привязать автоматически</a></p>"
           . self::input('code', 'Введите 6-значный код из приложения', '', ['inputmode' => 'numeric', 'autofocus' => true, 'maxlength' => 6, 'class' => 'code-in'])
           . "<button class='btn btn--primary btn--wide'>Включить 2FA и войти</button>"
           . "<a class='link' href='/admin/?p=logout'>Отмена</a>"
           . "</form>";
        self::authShell('Настройка 2FA', $b);
    }

    // ---------- Дашборд ----------

    public static function dashboard(): void
    {
        Content::syncPages();
        $groups = [];
        foreach (PageRegistry::pages() as $pg) {
            $groups[$pg['group']][] = $pg;
        }
        $body = "<div class='pagehead'><h1>Страницы сайта</h1>"
              . "<p class='muted'>Выберите страницу, чтобы изменить её SEO, заголовки, тексты и фото. "
              . "Изменения появляются на сайте сразу после сохранения.</p></div>";

        foreach ($groups as $gname => $pages) {
            $body .= "<h2 class='grp'>" . h($gname) . "</h2><div class='cards'>";
            foreach ($pages as $pg) {
                $row = Content::pageRow($pg['slug']);
                $editedN = count(Content::fields($pg['slug']));
                $url = $pg['url'];
                $badge = $editedN
                    ? "<span class='tag tag--edited'>изменена: $editedN</span>"
                    : "<span class='tag'>как в оригинале</span>";
                $body .= "<a class='card' href='/admin/?p=page&slug=" . rawurlencode($pg['slug']) . "'>"
                       . "<div class='card__t'>" . h($pg['label']) . "</div>"
                       . "<div class='card__u'>" . h($url) . "</div>"
                       . "<div class='card__b'>$badge</div></a>";
            }
            $body .= "</div>";
        }
        self::shell('dashboard', 'Страницы', $body);
    }

    // ---------- Редактор страницы ----------

    public static function pageEdit(array $page, array $defaults, array $overrides): void
    {
        $slug = $page['slug'];
        $url = $page['url'];
        $groups = [];
        foreach ($page['fields'] as $def) {
            $groups[$def['group'] ?? 'Прочее'][] = $def;
        }

        $body = "<div class='pagehead'>"
              . "<a class='link back' href='/admin/?p=dashboard'>← ко всем страницам</a>"
              . "<h1>" . h($page['label']) . "</h1>"
              . "<p class='muted'>" . h($url) . " · "
              . "<a class='link' href='" . h($url) . "' target='_blank' rel='noopener'>открыть на сайте ↗</a></p>"
              . "</div>";

        $body .= "<form method=post action='/admin/?p=page' class='form form--wide'>"
               . Csrf::field()
               . "<input type=hidden name=slug value='" . h($slug) . "'>";

        foreach ($groups as $gname => $defs) {
            $adv = $gname === 'SEO';
            $body .= "<section class='panel'><h2 class='panel__t'>" . h($gname) . "</h2>";
            $normal = array_filter($defs, fn($d) => empty($d['advanced']));
            $advanced = array_filter($defs, fn($d) => !empty($d['advanced']));
            foreach ($normal as $def) {
                $body .= self::fieldRow($def, $overrides[$def['key']] ?? null, $defaults[$def['key']] ?? null);
            }
            if ($advanced) {
                $body .= "<details class='adv'><summary>Дополнительно (менять осторожно)</summary>";
                foreach ($advanced as $def) {
                    $body .= self::fieldRow($def, $overrides[$def['key']] ?? null, $defaults[$def['key']] ?? null);
                }
                $body .= "</details>";
            }
            $body .= "</section>";
        }

        $body .= "<div class='savebar'><button class='btn btn--primary'>Сохранить</button>"
               . "<a class='link' href='" . h($url) . "' target='_blank' rel='noopener'>Предпросмотр ↗</a></div>"
               . "</form>";

        self::shell('dashboard', $page['label'], $body);
    }

    /** Строка одного поля с подписью, подсказкой, текущим значением и оригиналом. */
    private static function fieldRow(array $def, ?string $current, ?string $default): string
    {
        $key  = $def['key'];
        $type = $def['type'];
        $val  = $current ?? $default ?? '';
        $name = "f[$key]";
        $help = $def['help'] ?? '';

        $isImg   = in_array($type, ['og_image', 'css_image', 'image'], true);
        $isBlock = $type === 'marker_block';
        $isArea  = $isBlock || in_array($type, ['description', 'og_description', 'marker_text', 'marker_inline'], true);

        $out = "<div class='field'><label class='field__l'>" . h($def['label']) . "</label>";
        if ($help) $out .= "<div class='field__h'>" . h($help) . "</div>";

        if ($isImg) {
            $prev = $val ? "<img class='thumb' src='" . h($val) . "' alt=''>" : "<div class='thumb thumb--empty'>нет фото</div>";
            $out .= "<div class='imgrow'>$prev<div class='imgrow__c'>"
                  . "<input type=text name='" . h($name) . "' value='" . h($val) . "' class='in in--url' data-img readonly>"
                  . "<div class='imgrow__btns'>"
                  . "<button type=button class='btn btn--sm pick-btn' data-target='" . h($name) . "'>Выбрать фото</button>"
                  . "<button type=button class='btn btn--sm btn--ghost clr-btn' data-target='" . h($name) . "'>Убрать</button>"
                  . "</div></div></div>";
        } elseif ($isArea) {
            $rows = (int)($def['rows'] ?? ($isBlock ? 18 : 3));
            $cnt = in_array($type, ['description', 'og_description'], true) ? " data-count='160'" : '';
            $cls = $isBlock ? 'in in--area in--code' : 'in in--area';
            $out .= "<textarea name='" . h($name) . "' class='$cls' rows=$rows$cnt>" . h($val) . "</textarea>";
            if ($cnt) $out .= "<div class='cnt'></div>";
            if ($isBlock) {
                $out .= "<div class='field__h field__h--warn'>Это разметка страницы. Меняйте текст между тегами, "
                      . "теги и классы (class=\"…\") оставляйте как есть — на них держится оформление. "
                      . "Скрипты и стили вырезаются при сохранении.</div>";
            }
        } else {
            $cnt = ($type === 'title' || $type === 'og_title') ? " data-count='60'" : '';
            $out .= "<input type=text name='" . h($name) . "' value='" . h($val) . "' class='in'$cnt>";
            if ($cnt) $out .= "<div class='cnt'></div>";
        }

        if ($current !== null && $default !== null && $default !== '' && $current !== $default) {
            $out .= "<details class='field__def'><summary>Было в оригинале</summary>"
                  . "<div class='orig'>" . h($default) . "</div></details>";
        }
        // Подсказка ровно там, где пустое поле не оставляет пустоту, а возвращает исходный текст.
        if ($current !== null && $default !== null && $default !== ''
            && in_array($type, ['title', 'description', 'canonical', 'robots',
                                'og_title', 'og_description', 'h1'], true)) {
            $out .= "<div class='field__h'>Очистите поле и сохраните — вернётся текст из вёрстки.</div>";
        }
        $out .= "</div>";
        return $out;
    }

    // ---------- Заявки ----------

    public static function leads(): void
    {
        $imported = Leads::ingest();          // подхватываем всё новое из журнала форм
        $status = req_str($_GET, 'status');
        $q      = req_str($_GET, 'q');
        $rows   = Leads::all($status, $q);
        $c      = Leads::counts();

        $tab = static function (string $key, string $label, int $n) use ($status, $q): string {
            $u = '/admin/?p=leads' . ($key !== '' ? '&status=' . $key : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
            $cur = $status === $key ? ' is-on' : '';
            return "<a class='tab$cur' href='" . h($u) . "'>" . h($label) . " <b>$n</b></a>";
        };

        $body = "<div class='pagehead'><h1>Заявки с сайта</h1>"
              . "<p class='muted'>Всё, что люди отправили через формы. Заявки приходят и на почту — "
              . "здесь они хранятся, ищутся и выгружаются в таблицу."
              . ($imported ? " <b>Новых с прошлого захода: $imported.</b>" : "")
              . "</p></div>";

        $body .= "<div class='leadbar'>"
               . "<div class='tabs'>" . $tab('', 'Все', $c['total']) . $tab('new', 'Новые', $c['new']) . $tab('done', 'Обработанные', $c['done']) . "</div>"
               . "<form class='srch' method=get action='/admin/'>"
               . "<input type=hidden name=p value=leads>"
               . ($status !== '' ? "<input type=hidden name=status value='" . h($status) . "'>" : '')
               . "<input type=text name=q value='" . h($q) . "' class='in' placeholder='Поиск: имя, телефон, текст, метка'>"
               . "<button class='btn btn--sm'>Найти</button></form>"
               . "<form method=post action='/admin/?p=leads'>" . Csrf::field()
               . "<input type=hidden name=action value=export>"
               . "<input type=hidden name=status value='" . h($status) . "'>"
               . "<input type=hidden name=q value='" . h($q) . "'>"
               . "<button class='btn btn--sm btn--ghost'>Выгрузить в CSV</button></form>"
               . "</div>";

        if (!$rows) {
            $file = Leads::logPath();
            $body .= "<p class='muted empty'>Заявок пока нет."
                   . (is_file($file) ? "" : " Журнал форм ещё не создан — он появится с первой заявкой (" . h($file) . ").")
                   . "</p>";
        }

        foreach ($rows as $r) {
            $done = $r['status'] === 'done';
            $utm = '';
            if ($r['utm'] !== '') {
                $u = json_decode((string)$r['utm'], true);
                if (is_array($u)) {
                    foreach ($u as $k => $v) $utm .= "<span class='chip'>" . h((string)$k) . ": " . h((string)$v) . "</span>";
                }
            }
            $page = (string)$r['page'];
            $body .= "<article class='lead" . ($done ? ' lead--done' : '') . "'>"
                   . "<div class='lead__h'>"
                   . "<div><b class='lead__n'>" . h((string)$r['name']) . "</b>"
                   . "<span class='lead__c'>" . h((string)$r['contact']) . "</span></div>"
                   . "<div class='muted small'>" . date('d.m.Y H:i', (int)$r['ts'])
                   . " · форма «" . h((string)$r['form']) . "»"
                   . ($page !== '' && $page !== '—' ? " · <a class='link' href='" . h($page) . "' target='_blank' rel='noopener'>страница ↗</a>" : '')
                   . "</div></div>";
            if (trim((string)$r['message']) !== '' && $r['message'] !== '—') {
                $body .= "<p class='lead__m'>" . nl2br(h((string)$r['message'])) . "</p>";
            }
            if ($utm) $body .= "<div class='chips'>$utm</div>";
            $body .= "<div class='lead__a'>"
                   . "<form method=post action='/admin/?p=leads'>" . Csrf::field()
                   . "<input type=hidden name=action value='" . ($done ? 'new' : 'done') . "'>"
                   . "<input type=hidden name=id value='" . (int)$r['id'] . "'>"
                   . "<input type=hidden name=status value='" . h($status) . "'>"
                   . "<input type=hidden name=q value='" . h($q) . "'>"
                   . "<button class='btn btn--sm " . ($done ? 'btn--ghost' : 'btn--primary') . "'>"
                   . ($done ? 'Вернуть в работу' : 'Обработана') . "</button></form>"
                   . "<form method=post action='/admin/?p=leads' class='noteform'>" . Csrf::field()
                   . "<input type=hidden name=action value=note>"
                   . "<input type=hidden name=id value='" . (int)$r['id'] . "'>"
                   . "<input type=hidden name=status value='" . h($status) . "'>"
                   . "<input type=hidden name=q value='" . h($q) . "'>"
                   . "<input type=text name=note class='in in--note' value='" . h((string)$r['note']) . "' placeholder='Заметка: что решили, кому передали'>"
                   . "<button class='btn btn--sm btn--ghost'>Сохранить</button></form>"
                   . "</div>";
            if ($done && $r['done_by']) {
                $body .= "<div class='muted small lead__done'>обработал(а) " . h((string)$r['done_by'])
                       . ($r['done_at'] ? ', ' . date('d.m.Y H:i', (int)$r['done_at']) : '') . "</div>";
            }
            $body .= "</article>";
        }

        self::shell('leads', 'Заявки', $body);
    }

    // ---------- Медиатека ----------

    public static function media(): void
    {
        $items = Media::all();
        $body = "<div class='pagehead'><h1>Медиатека</h1>"
              . "<p class='muted'>Загрузите фото (JPG, PNG, WEBP, GIF, до 8 МБ). "
              . "Потом выбирайте их в полях «фото» на страницах.</p></div>";

        $body .= "<form method=post action='/admin/?p=media' enctype='multipart/form-data' class='uploader'>"
               . Csrf::field()
               . "<input type=hidden name=action value=upload>"
               . "<input type=file name=file accept='image/*' required class='file'>"
               . "<button class='btn btn--primary'>Загрузить</button></form>";

        $body .= "<div class='mediagrid'>";
        foreach ($items as $m) {
            $u = Media::url($m['filename']);
            $body .= "<div class='mediacell'>"
                   . "<img src='" . h($u) . "' alt='' loading=lazy>"
                   . "<div class='mediacell__m'>" . (int)$m['width'] . "×" . (int)$m['height']
                   . " · " . h(human_size((int)$m['size'])) . "</div>"
                   . "<div class='mediacell__a'>"
                   . "<button type=button class='btn btn--sm copy-btn' data-url='" . h($u) . "'>Копировать URL</button>"
                   . "<form method=post action='/admin/?p=media' data-confirm='Удалить это фото?'>"
                   . Csrf::field()
                   . "<input type=hidden name=action value=delete><input type=hidden name=id value='" . (int)$m['id'] . "'>"
                   . "<button class='btn btn--sm btn--danger'>Удалить</button></form>"
                   . "</div></div>";
        }
        if (!$items) $body .= "<p class='muted'>Пока пусто. Загрузите первое фото.</p>";
        $body .= "</div>";
        self::shell('media', 'Медиатека', $body);
    }

    // ---------- Настройки ----------

    public static function settings(): void
    {
        $s = Content::allSettings();
        $body = "<div class='pagehead'><h1>Глобальные настройки</h1>"
              . "<p class='muted'>Телефон, почта, реквизиты и обложка. Применяются сразу ко всем страницам.</p></div>";
        $body .= "<form method=post action='/admin/?p=settings' class='form form--wide'><section class='panel'>"
               . Csrf::field();
        foreach (PageRegistry::globalFields() as $def) {
            $cur = $s[$def['key']] ?? '';
            $d = $def; $d['group'] = null;
            $body .= self::fieldRow($d, $cur === '' ? null : $cur, '');
        }
        $body .= "</section><div class='savebar'><button class='btn btn--primary'>Сохранить</button></div></form>";
        self::shell('settings', 'Настройки', $body);
    }

    // ---------- Аккаунт ----------

    public static function account(): void
    {
        $me = Auth::user();
        $body = "<div class='pagehead'><h1>Мой аккаунт</h1><p class='muted'>Логин: <b>" . h($me['username']) . "</b></p></div>";

        $body .= "<section class='panel'><h2 class='panel__t'>Смена пароля</h2>"
               . "<form method=post action='/admin/?p=account' class='form'>"
               . Csrf::field() . "<input type=hidden name=action value=password>"
               . self::input('current', 'Текущий пароль', '', ['type' => 'password'])
               . self::input('new', 'Новый пароль (от 12 символов, буквы и цифры)', '', ['type' => 'password'])
               . self::input('new2', 'Повторите новый пароль', '', ['type' => 'password'])
               . "<button class='btn btn--primary'>Изменить пароль</button></form></section>";

        $body .= "<section class='panel'><h2 class='panel__t'>Двухфакторная защита</h2>"
               . "<p class='muted'>Статус: " . ((int)$me['totp_enabled'] === 1 ? "<b class='ok-t'>включена</b>" : "выключена")
               . ". Сброс потребует заново привязать приложение при следующем входе.</p>"
               . "<form method=post action='/admin/?p=account' class='form' data-confirm='Сбросить 2FA?'>"
               . Csrf::field() . "<input type=hidden name=action value=reset2fa>"
               . self::input('current', 'Текущий пароль — подтвердите, что это вы', '', ['type' => 'password'])
               . "<button class='btn btn--ghost'>Сбросить 2FA</button></form></section>";
        self::shell('account', 'Аккаунт', $body);
    }

    // ---------- Пользователи ----------

    public static function users(): void
    {
        $rows = Database::pdo()->query('SELECT * FROM users ORDER BY id')->fetchAll();
        $me = Auth::user();
        $body = "<div class='pagehead'><h1>Пользователи</h1><p class='muted'>Кто имеет доступ в админку.</p></div>";
        $body .= "<div class='table'>";
        foreach ($rows as $u) {
            $when = $u['last_login_at'] ? date('d.m.Y H:i', (int)$u['last_login_at']) : '—';
            $twofa = (int)$u['totp_enabled'] === 1 ? '2FA ✓' : '2FA —';
            $del = ((int)$u['id'] !== (int)$me['id'])
                ? "<form method=post action='/admin/?p=users' data-confirm='Удалить пользователя " . h($u['username']) . "?'>"
                    . Csrf::field() . "<input type=hidden name=action value=delete><input type=hidden name=id value='" . (int)$u['id'] . "'>"
                    . "<button class='btn btn--sm btn--danger'>Удалить</button></form>"
                : "<span class='muted small'>это вы</span>";
            $body .= "<div class='trow'><div><b>" . h($u['username']) . "</b> <span class='muted small'>" . h($u['role']) . "</span></div>"
                   . "<div class='muted small'>$twofa · вход: $when</div><div>$del</div></div>";
        }
        $body .= "</div>";
        $body .= "<section class='panel'><h2 class='panel__t'>Добавить пользователя</h2>"
               . "<form method=post action='/admin/?p=users' class='form'>"
               . Csrf::field() . "<input type=hidden name=action value=add>"
               . self::input('username', 'Логин (латиница, 3–32)', '', ['autocomplete' => 'off'])
               . self::input('password', 'Пароль (от 12 символов)', '', ['type' => 'password', 'autocomplete' => 'new-password'])
               . "<button class='btn btn--primary'>Создать</button></form></section>";
        self::shell('users', 'Пользователи', $body);
    }

    // ---------- Журнал ----------

    public static function audit(): void
    {
        $rows = Audit::recent(300);
        $body = "<div class='pagehead'><h1>Журнал действий</h1><p class='muted'>Входы, правки, загрузки — последние 300 событий.</p></div>";
        $body .= "<div class='table table--log'>";
        foreach ($rows as $r) {
            $body .= "<div class='trow'>"
                   . "<div class='muted small'>" . date('d.m.Y H:i', (int)$r['ts']) . "</div>"
                   . "<div><b>" . h((string)$r['username']) . "</b> <span class='muted small'>" . h((string)$r['ip']) . "</span></div>"
                   . "<div>" . h((string)$r['action']) . " <span class='muted'>" . h((string)$r['target']) . "</span> "
                   . "<span class='muted small'>" . h((string)$r['details']) . "</span></div></div>";
        }
        $body .= "</div>";
        self::shell('audit', 'Журнал', $body);
    }

    // ---------- Вспомогательное ----------

    private static function input(string $name, string $label, string $val, array $o = []): string
    {
        $type = $o['type'] ?? 'text';
        $attrs = '';
        foreach (['autocomplete', 'inputmode', 'maxlength', 'class'] as $a) {
            if (isset($o[$a])) $attrs .= " $a='" . h((string)$o[$a]) . "'";
        }
        if (!empty($o['autofocus'])) $attrs .= ' autofocus';
        return "<div class='field'><label class='field__l'>" . h($label) . "</label>"
             . "<input type='$type' name='" . h($name) . "' value='" . h($val) . "' class='in " . h($o['class'] ?? '') . "'$attrs></div>";
    }

    /** Разметка модалки выбора фото (наполняется из медиатеки). */
    private static function pickerMarkup(): string
    {
        $cells = '';
        foreach (Media::all() as $m) {
            $u = Media::url($m['filename']);
            $cells .= "<button type=button class='pcell' data-file='" . h($u) . "'><img src='" . h($u) . "' alt=''></button>";
        }
        if ($cells === '') $cells = "<p class='muted'>Медиатека пуста. Сначала загрузите фото в разделе «Медиатека».</p>";
        return "<div id='picker' class='modal' hidden><div class='modal__box'>"
             . "<div class='modal__h'>Выберите фото <button type=button id='pclose' class='btn btn--sm btn--ghost'>Закрыть</button></div>"
             . "<div class='pgrid'>$cells</div></div></div>";
    }

    private static function css(): string
    {
        return <<<CSS
:root{--blue:#123E63;--blue2:#2B6FA4;--ink:#0A0C0E;--mut:#6b7681;--line:#E3E7EA;--wash:#EAF3F9;--bg:#f6f8fa;--ok:#1F5C42;--err:#8F2433}
*{box-sizing:border-box}
body{margin:0;font:15px/1.5 'Segoe UI',system-ui,-apple-system,Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg)}
a{color:var(--blue2);text-decoration:none}a:hover{text-decoration:underline}
h1{font-size:26px;font-weight:600;margin:0 0 6px}h2{font-size:18px;font-weight:600}
.muted{color:var(--mut)}.small{font-size:13px}
.top{display:flex;align-items:center;gap:18px;background:var(--blue);color:#fff;padding:0 22px;height:60px;position:sticky;top:0;z-index:5}
.top__brand{font-weight:700;letter-spacing:.04em}.top__brand span{opacity:.6;font-weight:400;margin-left:6px;font-size:13px}
.nav{display:flex;gap:4px;margin-left:8px;flex:1}
.nav__i{color:#cfe0ee;padding:8px 12px;border-radius:8px;font-size:14px}
.nav__i:hover{background:rgba(255,255,255,.1);text-decoration:none}
.nav__i.is-active{background:#fff;color:var(--blue)}
.top__me{display:flex;align-items:center;gap:12px}.top__me a{color:#fff}
.wrap{max-width:860px;margin:26px auto;padding:0 18px}
.pagehead{margin-bottom:18px}.back{display:inline-block;margin-bottom:8px}
.grp{margin:22px 0 10px;color:var(--mut);font-size:14px;text-transform:uppercase;letter-spacing:.06em}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.card{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;transition:.15s}
.card:hover{border-color:var(--blue2);box-shadow:0 6px 20px rgba(18,62,99,.08);text-decoration:none;transform:translateY(-1px)}
.card__t{font-weight:600;color:var(--ink);margin-bottom:2px}.card__u{color:var(--mut);font-size:13px;font-family:ui-monospace,monospace}
.card__b{margin-top:10px}
.tag{display:inline-block;font-size:12px;padding:3px 9px;border-radius:20px;background:var(--line);color:var(--mut)}
.tag--edited{background:var(--wash);color:var(--blue)}
.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-bottom:16px}
.panel__t{margin:0 0 12px;padding-bottom:10px;border-bottom:1px solid var(--line)}
.field{margin-bottom:16px}
.field__l{display:block;font-weight:600;margin-bottom:4px}
.field__h{color:var(--mut);font-size:13px;margin-bottom:6px}
.field__def{font-size:12px;color:var(--mut);margin-top:4px}.field__def span{font-family:ui-monospace,monospace}
.in{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:#fff}
.in:focus{outline:none;border-color:var(--blue2);box-shadow:0 0 0 3px var(--wash)}
.in--area{resize:vertical;min-height:74px}.in--url{font-family:ui-monospace,monospace;font-size:13px;background:#fafbfc}
.code-in{font-size:26px;letter-spacing:.4em;text-align:center;font-family:ui-monospace,monospace}
.cnt{font-size:12px;color:var(--mut);text-align:right;margin-top:3px}
.cnt.over{color:var(--err)}
.adv{margin-top:6px;border-top:1px dashed var(--line);padding-top:8px}
.adv summary{cursor:pointer;color:var(--mut);font-size:14px;padding:6px 0}
.savebar{display:flex;align-items:center;gap:16px;position:sticky;bottom:0;background:linear-gradient(180deg,transparent,var(--bg) 40%);padding:14px 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--line);background:#fff;color:var(--ink);padding:10px 18px;border-radius:10px;font:inherit;font-weight:600;cursor:pointer;transition:.15s}
.btn:hover{border-color:var(--blue2)}
.btn--primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn--primary:hover{background:#0d3252}
.btn--ghost{background:transparent}
.btn--danger{color:var(--err);border-color:#e7c9cf}.btn--danger:hover{background:#fbeef0;border-color:var(--err)}
.btn--sm{padding:6px 12px;font-size:13px;font-weight:500}
.btn--wide{width:100%;margin-top:6px}
.imgrow{display:flex;gap:14px;align-items:flex-start}
.thumb{width:120px;height:80px;object-fit:cover;border-radius:10px;border:1px solid var(--line)}
.thumb--empty{display:flex;align-items:center;justify-content:center;color:var(--mut);font-size:12px;background:#fafbfc}
.imgrow__c{flex:1}.imgrow__btns{display:flex;gap:8px;margin-top:8px}
.uploader{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin-bottom:18px}
.file{flex:1}
.mediagrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
.mediacell{background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
.mediacell img{width:100%;height:130px;object-fit:cover;display:block}
.mediacell__m{font-size:12px;color:var(--mut);padding:6px 10px}
.mediacell__a{display:flex;gap:6px;padding:0 10px 10px;flex-wrap:wrap}
.table{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:18px}
.trow{display:grid;grid-template-columns:150px 1fr auto;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);align-items:center}
.table--log .trow{grid-template-columns:130px 200px 1fr}
.trow:last-child{border-bottom:none}
.form{max-width:520px}.form--wide{max-width:100%}
.msg{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:500}
.msg--ok{background:#e9f3ee;color:var(--ok);border:1px solid #bfe0cf}
.msg--err{background:#fbeef0;color:var(--err);border:1px solid #e7c9cf}
.auth{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.auth__card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:30px 30px 26px;width:100%;max-width:400px;box-shadow:0 10px 40px rgba(18,62,99,.1)}
.auth__foot{color:var(--mut);font-size:13px;margin-top:18px}
.brand{font-weight:700;font-size:22px;letter-spacing:.06em;color:var(--blue);margin-bottom:18px}.brand span{display:block;font-size:13px;font-weight:400;letter-spacing:0;color:var(--mut);margin-top:2px}
.steps{padding-left:18px;color:#333}.steps li{margin:6px 0}
.secret{font-family:ui-monospace,monospace;font-size:20px;letter-spacing:.12em;background:var(--wash);color:var(--blue);padding:14px;border-radius:10px;text-align:center;word-break:break-all;margin:6px 0 10px}
.link{color:var(--blue2)}.ok-t{color:var(--ok)}
.modal{position:fixed;inset:0;background:rgba(10,20,31,.55);display:flex;align-items:center;justify-content:center;z-index:20;padding:20px}
.modal[hidden]{display:none}
.modal__box{background:#fff;border-radius:16px;max-width:760px;width:100%;max-height:80vh;overflow:auto;padding:18px}
.modal__h{display:flex;justify-content:space-between;align-items:center;font-weight:600;margin-bottom:12px}
.pgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
.pcell{padding:0;border:2px solid transparent;border-radius:10px;overflow:hidden;cursor:pointer;background:none}
.pcell:hover{border-color:var(--blue2)}.pcell img{width:100%;height:90px;object-fit:cover;display:block}
/* --- заявки --- */
.nav__n{display:inline-block;min-width:18px;padding:0 5px;margin-left:6px;border-radius:20px;background:#F2C230;color:#3a2f00;font-size:12px;font-weight:700;text-align:center;vertical-align:1px}
.leadbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px}
.tabs{display:flex;gap:6px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:4px}
.tab{padding:7px 14px;border-radius:9px;color:var(--mut);font-weight:600;font-size:14px}
.tab:hover{text-decoration:none;background:var(--bg)}
.tab.is-on{background:var(--blue);color:#fff}
.tab b{font-weight:700;opacity:.75;margin-left:2px}
.srch{display:flex;gap:8px;flex:1;min-width:260px}.srch .in{flex:1}
.empty{background:#fff;border:1px dashed var(--line);border-radius:14px;padding:26px;text-align:center}
.lead{background:#fff;border:1px solid var(--line);border-left:3px solid var(--blue2);border-radius:12px;padding:14px 16px;margin-bottom:10px}
.lead--done{border-left-color:var(--line);opacity:.72}
.lead__h{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:baseline}
.lead__n{font-size:16px}
.lead__c{margin-left:10px;font-family:ui-monospace,monospace;font-size:14px;color:var(--blue2)}
.lead__m{margin:8px 0 0;white-space:pre-wrap;color:#333}
.chips{margin-top:8px;display:flex;flex-wrap:wrap;gap:6px}
.chip{font-size:12px;background:var(--wash);color:var(--blue);padding:3px 8px;border-radius:20px;font-family:ui-monospace,monospace}
.lead__a{display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center}
.noteform{display:flex;gap:8px;flex:1;min-width:280px}
.in--note{padding:6px 10px;font-size:13px}
.lead__done{margin-top:8px}
/* --- редактор контента --- */
.in--code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.55;background:#fcfcfd}
.field__h--warn{margin:6px 0 0;color:#7a5b00;background:#fff8e6;border:1px solid #f0e0b0;border-radius:8px;padding:8px 10px}
.field__def{margin-top:6px}
.field__def summary{cursor:pointer;color:var(--mut);font-size:13px}
.orig{margin-top:6px;padding:8px 10px;background:#fafbfc;border:1px solid var(--line);border-radius:8px;font-family:ui-monospace,monospace;font-size:12px;color:#444;white-space:pre-wrap;max-height:220px;overflow:auto}
@media(max-width:640px){.nav{display:none}.trow{grid-template-columns:1fr}.imgrow{flex-direction:column}}
CSS;
    }

    private static function js(): string
    {
        $nonce = Security::nonce();
        return <<<JS
<script nonce="$nonce">
(function(){
  // Счётчики символов
  document.querySelectorAll('[data-count]').forEach(function(el){
    var lim=+el.getAttribute('data-count');
    var box=el.parentNode.querySelector('.cnt'); if(!box)return;
    function upd(){var n=el.value.length;box.textContent=n+' / '+lim+' символов';box.classList.toggle('over',n>lim);}
    el.addEventListener('input',upd);upd();
  });
  // Подтверждение удаления
  document.querySelectorAll('form[data-confirm]').forEach(function(f){
    f.addEventListener('submit',function(e){ if(!confirm(f.getAttribute('data-confirm')))e.preventDefault(); });
  });
  // Копировать URL
  document.querySelectorAll('.copy-btn').forEach(function(b){
    b.addEventListener('click',function(){
      var u=location.origin+b.getAttribute('data-url');
      navigator.clipboard&&navigator.clipboard.writeText(u);
      var t=b.textContent;b.textContent='Скопировано';setTimeout(function(){b.textContent=t;},1200);
    });
  });
  // Выбор фото из медиатеки
  var picker=document.getElementById('picker'), target=null;
  document.querySelectorAll('.pick-btn').forEach(function(b){
    b.addEventListener('click',function(){ target=b.getAttribute('data-target'); if(picker)picker.hidden=false; });
  });
  document.querySelectorAll('.clr-btn').forEach(function(b){
    b.addEventListener('click',function(){
      var inp=document.querySelector('[name="'+b.getAttribute('data-target')+'"]'); if(!inp)return;
      inp.value=''; var img=inp.closest('.imgrow').querySelector('.thumb');
      if(img&&img.tagName==='IMG'){var d=document.createElement('div');d.className='thumb thumb--empty';d.textContent='нет фото';img.replaceWith(d);}
    });
  });
  if(picker){
    var close=document.getElementById('pclose');
    close&&close.addEventListener('click',function(){picker.hidden=true;});
    picker.addEventListener('click',function(e){if(e.target===picker)picker.hidden=true;});
    picker.querySelectorAll('.pcell').forEach(function(c){
      c.addEventListener('click',function(){
        if(!target)return; var file=c.getAttribute('data-file');
        var inp=document.querySelector('[name="'+target+'"]'); if(inp)inp.value=file;
        var row=inp.closest('.imgrow'), ph=row.querySelector('.thumb');
        var img=document.createElement('img');img.className='thumb';img.src=file;ph.replaceWith(img);
        picker.hidden=true;
      });
    });
  }
})();
</script>
JS;
    }
}
