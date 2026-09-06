<?php
/**
 * Маршрутизация и действия админки. Вьюхи — в views.php.
 * Все POST проходят проверку CSRF. Доступ ко всему, кроме входа/2FA, требует аутентификации.
 */
declare(strict_types=1);

require __DIR__ . '/views.php';

final class AdminController
{
    /**
     * Поля, где пустое значение означает «вернуть как в вёрстке», а не «оставить пусто».
     *
     * Пустой <title> или <h1> на боевой странице — прямая потеря позиций, а промахнуться легко:
     * достаточно стереть текст и нажать «Сохранить». Текстовые блоки сюда НЕ входят — там пусто
     * значит пусто (так, например, убирается черновая пометка под блоком «Группа в цифрах»).
     */
    private const REVERT_ON_EMPTY = ['title', 'description', 'canonical', 'robots',
                                     'og_title', 'og_description', 'og_image', 'h1',
                                     'image', 'css_image'];

    public static function handle(): void
    {
        $p = (string)($_GET['p'] ?? 'dashboard');
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($post) Csrf::check();

        // --- Маршруты без аутентификации ---
        if (!Auth::check()) {
            switch ($p) {
                case 'login':       $post ? self::doLogin()       : View::login();       return;
                case 'twofa':       $post ? self::doTwofa()       : self::showTwofa();    return;
                case 'twofa_setup': $post ? self::doTwofaSetup()  : self::showTwofaSetup();return;
                case 'logout':      Auth::logout();               redirect('/admin/?p=login');
                default:            redirect('/admin/?p=login');
            }
            return;
        }

        // --- Требует входа ---
        switch ($p) {
            case 'logout':   Auth::logout(); redirect('/admin/?p=login');
            case 'page':     $post ? self::savePage()     : self::editPage();   break;
            case 'media':    $post ? self::mediaAction()  : View::media();      break;
            case 'settings': $post ? self::saveSettings() : View::settings();   break;
            case 'account':  $post ? self::saveAccount()  : View::account();    break;
            case 'users':    $post ? self::usersAction()  : View::users();      break;
            case 'leads':    $post ? self::leadsAction()   : View::leads();      break;
            case 'audit':    View::audit();                                     break;
            default:         View::dashboard();
        }
    }

    // ---------------- Вход и 2FA ----------------

    private static function doLogin(): void
    {
        $u  = req_str($_POST, 'username');
        $pw = (string)($_POST['password'] ?? '');
        $r  = Auth::attemptPassword($u, $pw);
        if (!$r['ok']) { flash('error', $r['error']); redirect('/admin/?p=login'); }
        redirect($r['next'] === 'totp' ? '/admin/?p=twofa' : '/admin/?p=twofa_setup');
    }

    private static function showTwofa(): void
    {
        if (!Auth::pendingUser()) redirect('/admin/?p=login');
        View::twofa();
    }

    private static function doTwofa(): void
    {
        if (!Auth::pendingUser()) redirect('/admin/?p=login');
        if (Auth::verifyTotpAndLogin(req_str($_POST, 'code'))) redirect('/admin/?p=dashboard');

        flash('error', match (Auth::$lastTotpError) {
            'replay'  => 'Этот код уже использован. Дождитесь в приложении следующего — он меняется раз в 30 секунд.',
            'blocked' => 'Слишком много попыток. Подождите и повторите вход с самого начала.',
            default   => 'Неверный код. Проверьте приложение-аутентификатор.',
        });
        // Пять промахов подряд сбрасывают ожидание кода — тогда пароль спрашиваем заново.
        redirect(Auth::pendingUser() ? '/admin/?p=twofa' : '/admin/?p=login');
    }

    private static function showTwofaSetup(): void
    {
        $pending = Auth::pendingUser();
        if (!$pending) redirect('/admin/?p=login');
        $secret = Auth::beginTotpSetup();
        $uri = Totp::provisioningUri($secret, $pending['username'], (string)App::config('TOTP_ISSUER', 'ICEBERG'));
        View::twofaSetup($secret, $uri);
    }

    private static function doTwofaSetup(): void
    {
        if (!Auth::pendingUser()) redirect('/admin/?p=login');
        if (Auth::confirmTotpSetup(req_str($_POST, 'code'))) redirect('/admin/?p=dashboard');
        flash('error', 'Код не совпал. Отсканируйте QR заново и введите текущий 6-значный код.');
        redirect('/admin/?p=twofa_setup');
    }

    // ---------------- Страницы / контент ----------------

    /** Значения полей из эталонного HTML (дефолты и подсказки). */
    public static function baselineDefaults(array $page): array
    {
        $file = rtrim(App::config('BASELINE_DIR'), '/') . '/' . $page['file'];
        $html = is_file($file) ? (string)file_get_contents($file) : '';
        $out = [];
        foreach ($page['fields'] as $def) {
            $out[$def['key']] = $html === '' ? null : Overlay::baselineValue($html, $def);
        }
        return $out;
    }

    private static function editPage(): void
    {
        $slug = req_str($_GET, 'slug');
        $page = PageRegistry::find($slug);
        if (!$page) { flash('error', 'Страница не найдена.'); redirect('/admin/?p=dashboard'); }
        View::pageEdit($page, self::baselineDefaults($page), Content::fields($slug));
    }

    private static function savePage(): void
    {
        $slug = req_str($_POST, 'slug');
        $page = PageRegistry::find($slug);
        if (!$page) { flash('error', 'Страница не найдена.'); redirect('/admin/?p=dashboard'); }

        $defaults = self::baselineDefaults($page);
        $me = $_SESSION['username'] ?? null;
        $changed = 0;

        $posted = (array)($_POST['f'] ?? []);

        foreach ($page['fields'] as $def) {
            $key = $def['key'];

            // Поле не пришло в запросе — значит форма отправлена не целиком.
            // Молча стирать содержимое в таком случае нельзя.
            if (!array_key_exists($key, $posted)) continue;

            $val = self::cleanValue($def['type'], (string)$posted[$key]);
            // Эталон прогоняем через ту же очистку, иначе «сохранить, ничего не меняя»
            // каждый раз создавало бы переопределение из-за нормализации разметки.
            $default = $defaults[$key] === null ? null : self::cleanValue($def['type'], (string)$defaults[$key]);

            // Пусто там, где пустым быть нельзя, — возвращаем текст из вёрстки.
            if ($val === '' && $default !== null && in_array($def['type'], self::REVERT_ON_EMPTY, true)) {
                if (Content::field($slug, $key) !== null) { Content::deleteField($slug, $key, $me); $changed++; }
                continue;
            }

            // Пусто и в вёрстке ничего не было (фото-слот, отсутствующий мета-тег) — хранить нечего.
            if ($default === null && $val === '') {
                if (Content::field($slug, $key) !== null) { Content::deleteField($slug, $key, $me); $changed++; }
                continue;
            }

            // Совпадает с эталоном → убираем переопределение (страница = как собрана).
            if ($default !== null && trim($val) === trim((string)$default)) {
                if (Content::field($slug, $key) !== null) { Content::deleteField($slug, $key, $me); $changed++; }
                continue;
            }
            if (Content::field($slug, $key) !== $val) {
                Content::setField($slug, $key, $val, $me);
                $changed++;
            }
        }
        Audit::log('page_save', $slug, "полей изменено: $changed");

        // Контрольная сборка страницы: если какое-то поле не нашло своего места
        // в вёрстке, честно говорим об этом, а не рапортуем «сохранено».
        Overlay::apply(Overlay::baselineHtml($page), $page, Content::fields($slug), Content::allSettings());
        if (Overlay::$lastMissing) {
            $names = [];
            foreach ($page['fields'] as $def) {
                if (in_array($def['key'], Overlay::$lastMissing, true)) $names[] = $def['label'];
            }
            flash('error', 'Сохранено, но на страницу не легло: ' . implode(', ', $names)
                . '. Разметка страницы изменилась — нужен разработчик (см. tools/cms_markers.py).');
        } else {
            flash('ok', $changed ? "Сохранено. Изменений: $changed. Обновите страницу сайта — правки уже видны." : 'Изменений не было.');
        }
        redirect('/admin/?p=page&slug=' . rawurlencode($slug));
    }

    /** Очистка/санитайзинг значения по типу поля. */
    private static function cleanValue(string $type, string $raw): string
    {
        $raw = trim($raw);
        return match ($type) {
            'h1', 'marker_inline', 'js_offer' => mb_substr(Overlay::sanitizeInline($raw), 0, 600),
            'marker_block'                    => mb_substr(Overlay::sanitizeBlock($raw), 0, 60000),
            'og_image', 'css_image', 'image'  => self::cleanUrl($raw),
            'title', 'og_title'               => mb_substr(strip_tags($raw), 0, 300),
            'description', 'og_description'   => mb_substr(strip_tags($raw), 0, 500),
            'canonical'                       => mb_substr(strip_tags($raw), 0, 500),
            default                           => mb_substr(strip_tags($raw), 0, 1000),
        };
    }

    private static function cleanUrl(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (str_starts_with($v, '/uploads/') || preg_match('#^https?://#i', $v)) return $v;
        return '';   // прочее не принимаем
    }

    // ---------------- Медиа ----------------

    private static function mediaAction(): void
    {
        $action = req_str($_POST, 'action');
        if ($action === 'upload' && isset($_FILES['file'])) {
            $r = Media::handleUpload($_FILES['file'], $_SESSION['username'] ?? null);
            flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Фото загружено.' : $r['error']);
        } elseif ($action === 'delete') {
            Media::delete((int)($_POST['id'] ?? 0));
            flash('ok', 'Фото удалено.');
        }
        redirect('/admin/?p=media');
    }

    // ---------------- Заявки ----------------

    private static function leadsAction(): void
    {
        $action = req_str($_POST, 'action');
        $id = (int)($_POST['id'] ?? 0);
        $me = $_SESSION['username'] ?? null;

        if ($action === 'done' || $action === 'new') {
            Leads::setStatus($id, $action, $me);
            Audit::log('lead_status', (string)$id, $action);
        } elseif ($action === 'note') {
            Leads::setNote($id, (string)($_POST['note'] ?? ''));
            Audit::log('lead_note', (string)$id);
        } elseif ($action === 'export') {
            $rows = Leads::all(req_str($_POST, 'status'), req_str($_POST, 'q'), 2000);
            $csv = Leads::csv($rows);
            Audit::log('lead_export', null, 'строк: ' . count($rows));
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="zayavki-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        }
        redirect('/admin/?p=leads' . self::leadsQuery());
    }

    /** Сохранить фильтр списка заявок при возврате. */
    private static function leadsQuery(): string
    {
        $q = [];
        if (($s = req_str($_POST, 'status')) !== '') $q['status'] = $s;
        if (($f = req_str($_POST, 'q')) !== '')      $q['q'] = $f;
        return $q ? '&' . http_build_query($q) : '';
    }

    // ---------------- Настройки ----------------

    private static function saveSettings(): void
    {
        foreach (PageRegistry::globalFields() as $def) {
            $val = self::cleanValue($def['type'], (string)($_POST['s'][$def['key']] ?? ''));
            Content::setSetting($def['key'], $val);
        }
        Audit::log('settings_save');
        flash('ok', 'Настройки сохранены и применены ко всем страницам.');
        redirect('/admin/?p=settings');
    }

    // ---------------- Аккаунт ----------------

    private static function saveAccount(): void
    {
        $action = req_str($_POST, 'action');
        $me = Auth::user();
        if (!$me) redirect('/admin/?p=login');

        if ($action === 'password') {
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $new2 = (string)($_POST['new2'] ?? '');
            if (!password_verify($cur, $me['pass_hash'])) {
                flash('error', 'Текущий пароль неверный.');
            } elseif ($new !== $new2) {
                flash('error', 'Новый пароль и подтверждение не совпадают.');
            } elseif ($prob = Auth::passwordProblem($new)) {
                flash('error', $prob);
            } else {
                Auth::changePassword((int)$me['id'], $new);
                flash('ok', 'Пароль изменён.');
            }
        } elseif ($action === 'reset2fa') {
            // Пароль спрашиваем ещё раз: сброс 2FA снимает второй фактор, и без этой проверки
            // им воспользовался бы любой, кто дорвался до открытой вкладки с админкой.
            if (!password_verify((string)($_POST['current'] ?? ''), $me['pass_hash'])) {
                Audit::log('2fa_reset_denied', $me['username']);
                flash('error', 'Для сброса 2FA введите текущий пароль.');
            } else {
                Database::pdo()->prepare(
                    'UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_counter = 0 WHERE id = ?'
                )->execute([$me['id']]);
                Audit::log('2fa_reset', $me['username']);
                flash('ok', 'Сброшено. При следующем входе заново привяжете приложение-аутентификатор.');
            }
        }
        redirect('/admin/?p=account');
    }

    // ---------------- Пользователи ----------------

    private static function usersAction(): void
    {
        $action = req_str($_POST, 'action');
        if ($action === 'add') {
            $u = req_str($_POST, 'username');
            $pw = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $u)) {
                flash('error', 'Логин: 3–32 символа, латиница/цифры/._-');
            } elseif (Auth::findByUsername($u)) {
                flash('error', 'Такой логин уже есть.');
            } elseif ($prob = Auth::passwordProblem($pw)) {
                flash('error', $prob);
            } else {
                Auth::createUser($u, $pw);
                Audit::log('user_add', $u);
                flash('ok', "Пользователь $u создан. При первом входе он привяжет свою 2FA.");
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $me = Auth::user();
            $cnt = (int)Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($id === (int)$me['id']) {
                flash('error', 'Нельзя удалить себя.');
            } elseif ($cnt <= 1) {
                flash('error', 'Нельзя удалить единственного пользователя.');
            } else {
                $t = Auth::findById($id);
                Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                Audit::log('user_delete', $t['username'] ?? (string)$id);
                flash('ok', 'Пользователь удалён.');
            }
        }
        redirect('/admin/?p=users');
    }
}

// ---- Флеш-сообщения ----
function flash(string $type, ?string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => (string)$msg];
}
function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
