<?php
/**
 * Заявки с форм сайта.
 *
 * Формы отправляются в /send.php (обработчик подрядчика, мы его НЕ трогаем): он шлёт письмо
 * и дублирует каждую заявку строкой JSON в leads.log. Админка читает этот файл, переносит
 * новые строки в базу и добавляет то, чего в файле нет: статус «в работе / обработана»,
 * заметку менеджера, поиск и выгрузку в CSV.
 *
 * Почему не переписали send.php под базу: он уже работает и проверен, а письмо — единственный
 * канал, который не зависит от нашей базы. Файл остаётся страховкой.
 */
final class Leads
{
    private const MAX_READ = 5 * 1024 * 1024;   // читаем не больше 5 МБ хвоста журнала

    public static function migrate(): void
    {
        Database::pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS leads (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                hash      TEXT    NOT NULL UNIQUE,   -- защита от повторного импорта строки
                ts        INTEGER NOT NULL,          -- время заявки
                name      TEXT    NOT NULL DEFAULT '',
                contact   TEXT    NOT NULL DEFAULT '',
                message   TEXT    NOT NULL DEFAULT '',
                form      TEXT    NOT NULL DEFAULT '',
                page      TEXT    NOT NULL DEFAULT '',
                ip        TEXT    NOT NULL DEFAULT '',
                utm       TEXT    NOT NULL DEFAULT '',
                status    TEXT    NOT NULL DEFAULT 'new',
                note      TEXT    NOT NULL DEFAULT '',
                done_at   INTEGER,
                done_by   TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_leads_ts     ON leads(ts DESC);
            CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status, ts DESC);
        SQL);
    }

    /**
     * Основной путь журнала. Он лежит ВНЕ веб-корня (см. DEPLOY.md): в журнале персональные
     * данные — имя, телефон, IP, — и держать их в public_html под защитой одного лишь
     * .htaccess нельзя: любая смена веб-сервера или потеря файла правил открывает их всем.
     */
    public static function logPath(): string
    {
        $p = (string)App::config('LEADS_LOG', '');
        return $p !== '' ? $p : rtrim((string)App::config('PUBLIC_DIR'), '/') . '/../leads.log';
    }

    /** Где ещё искать журнал: старое место в веб-корне (до переноса) — чтобы заявки не потерялись. */
    public static function logPaths(): array
    {
        $paths = [self::logPath(), rtrim((string)App::config('PUBLIC_DIR'), '/') . '/leads.log'];
        $out = [];
        foreach ($paths as $p) {
            $real = realpath($p) ?: $p;
            if (!isset($out[$real]) && is_file($p) && is_readable($p)) $out[$real] = $p;
        }
        return array_values($out);
    }

    /** Перенести новые строки журнала в базу. Возвращает, сколько добавлено. */
    public static function ingest(): int
    {
        self::migrate();
        $added = 0;
        foreach (self::logPaths() as $file) {
            $added += self::ingestFile($file);
        }
        return $added;
    }

    private static function ingestFile(string $file): int
    {
        $size = (int)filesize($file);
        $fh = @fopen($file, 'rb');
        if (!$fh) return 0;
        if ($size > self::MAX_READ) fseek($fh, $size - self::MAX_READ);

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT OR IGNORE INTO leads (hash, ts, name, contact, message, form, page, ip, utm)
             VALUES (:h,:ts,:n,:c,:m,:f,:p,:ip,:u)'
        );
        $added = 0;
        $lineNo = 0;
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;

            $known = ['Имя', 'Контакт', 'Сообщение', 'Форма', 'Страница', 'Время', 'IP'];
            $utm = array_diff_key($row, array_flip($known));

            $st->execute([
                ':h'  => sha1($lineNo . '|' . $line),
                ':ts' => self::parseTime((string)($row['Время'] ?? '')),
                ':n'  => mb_substr((string)($row['Имя'] ?? ''), 0, 200),
                ':c'  => mb_substr((string)($row['Контакт'] ?? ''), 0, 200),
                ':m'  => mb_substr((string)($row['Сообщение'] ?? ''), 0, 4000),
                ':f'  => mb_substr((string)($row['Форма'] ?? ''), 0, 100),
                ':p'  => mb_substr((string)($row['Страница'] ?? ''), 0, 300),
                ':ip' => mb_substr((string)($row['IP'] ?? ''), 0, 60),
                ':u'  => $utm ? (string)json_encode($utm, JSON_UNESCAPED_UNICODE) : '',
            ]);
            $added += $st->rowCount();
        }
        fclose($fh);
        return $added;
    }

    /** «05.09.2026 14:03:11» → метка времени. Не разобрали — берём текущее. */
    private static function parseTime(string $v): int
    {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\D+(\d{2}):(\d{2})(?::(\d{2}))?/', $v, $m)) {
            return (int)mktime((int)$m[4], (int)$m[5], (int)($m[6] ?? 0), (int)$m[2], (int)$m[1], (int)$m[3]);
        }
        $t = strtotime($v);
        return $t === false ? time() : $t;
    }

    /** Список заявок с фильтром: status = new|done|'' (все), q — поиск по имени/контакту/тексту. */
    public static function all(string $status = '', string $q = '', int $limit = 300): array
    {
        self::migrate();
        $sql = 'SELECT * FROM leads';
        $w = [];
        $args = [];
        if ($status === 'new' || $status === 'done') { $w[] = 'status = ?'; $args[] = $status; }
        if ($q !== '') {
            $w[] = '(name LIKE ? OR contact LIKE ? OR message LIKE ? OR utm LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $sql .= ' ORDER BY ts DESC, id DESC LIMIT ' . max(1, min(2000, $limit));
        $st = Database::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function counts(): array
    {
        self::migrate();
        $r = ['new' => 0, 'done' => 0, 'total' => 0];
        foreach (Database::pdo()->query('SELECT status, COUNT(*) c FROM leads GROUP BY status')->fetchAll() as $row) {
            $r[$row['status']] = (int)$row['c'];
            $r['total'] += (int)$row['c'];
        }
        return $r;
    }

    public static function setStatus(int $id, string $status, ?string $user): void
    {
        if (!in_array($status, ['new', 'done'], true)) return;
        Database::pdo()->prepare('UPDATE leads SET status = ?, done_at = ?, done_by = ? WHERE id = ?')
            ->execute([$status, $status === 'done' ? time() : null, $status === 'done' ? $user : null, $id]);
    }

    public static function setNote(int $id, string $note): void
    {
        Database::pdo()->prepare('UPDATE leads SET note = ? WHERE id = ?')
            ->execute([mb_substr($note, 0, 2000), $id]);
    }

    /** Выгрузка в CSV для Excel (разделитель «;», BOM — иначе Excel ломает кириллицу). */
    public static function csv(array $rows): string
    {
        $out = "\xEF\xBB\xBF";
        $head = ['Дата', 'Имя', 'Контакт', 'Сообщение', 'Форма', 'Страница', 'Метки', 'Статус', 'Заметка'];
        // Заявки приходят от анонимных посетителей. Ячейка, начинающаяся с = + - @, для Excel
        // и Google Таблиц — ФОРМУЛА: открыв выгрузку, менеджер выполнил бы чужой код
        // (=HYPERLINK, =WEBSERVICE и т.п.). Обезвреживаем апострофом — он в ячейке не виден.
        $esc = static function (string $v): string {
            if ($v !== '' && str_contains("=+-@\t\r|", $v[0])) {
                $v = "'" . $v;
            }
            $v = str_replace('"', '""', $v);
            return '"' . $v . '"';
        };
        $out .= implode(';', array_map($esc, $head)) . "\r\n";
        foreach ($rows as $r) {
            $line = [
                date('d.m.Y H:i', (int)$r['ts']),
                (string)$r['name'], (string)$r['contact'], (string)$r['message'],
                (string)$r['form'], (string)$r['page'], (string)$r['utm'],
                $r['status'] === 'done' ? 'обработана' : 'новая',
                (string)$r['note'],
            ];
            $out .= implode(';', array_map($esc, $line)) . "\r\n";
        }
        return $out;
    }
}
