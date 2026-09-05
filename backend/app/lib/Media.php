<?php
/**
 * Медиатека: безопасная загрузка фото и их учёт.
 * Файлы лежат в public/uploads (веб-доступны), но там .htaccess запрещает исполнение —
 * даже если кто-то зальёт .php, он не выполнится. Плюс: проверка реального MIME,
 * пересжатие через GD (срезает EXIF и любые встроенные полезные нагрузки), случайные имена.
 */
final class Media
{
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public static function dir(): string
    {
        $d = rtrim(App::config('PUBLIC_DIR'), '/') . '/uploads';
        ensure_dir($d, 0755);
        return $d;
    }

    public static function url(string $filename): string
    {
        return '/uploads/' . rawurlencode($filename);
    }

    /**
     * Обработать загруженный файл ($_FILES['...']). Возврат: ['ok'=>bool,'error'=>?string,'media'=>?array].
     */
    public static function handleUpload(array $file, ?string $username): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'Файл не загрузился (код ' . ($file['error'] ?? '?') . ').'];
        }
        $max = (int)App::config('UPLOAD_MAX_BYTES', 8 * 1024 * 1024);
        if (($file['size'] ?? 0) > $max) {
            return ['ok' => false, 'error' => 'Файл больше ' . human_size($max) . '.'];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'Некорректная загрузка.'];
        }

        // Реальный MIME по содержимому, а не по расширению/заголовку.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED[$mime])) {
            return ['ok' => false, 'error' => 'Разрешены только фото: JPG, PNG, WEBP, GIF.'];
        }
        $ext = self::ALLOWED[$mime];

        // Проверка, что это действительно изображение, и размеров.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Файл не распознан как изображение.'];
        }
        [$w, $h] = $info;
        $maxW = (int)App::config('UPLOAD_MAX_W', 3000);
        $maxH = (int)App::config('UPLOAD_MAX_H', 3000);

        $filename = date('Ymd') . '-' . random_token(8) . '.' . $ext;
        $dest = self::dir() . '/' . $filename;

        // Пересжатие через GD (санитайзинг). Если GD нет — сохраняем как есть (MIME уже проверен).
        $saved = self::reencode($file['tmp_name'], $dest, $mime, $w, $h, $maxW, $maxH);
        if (!$saved) {
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                return ['ok' => false, 'error' => 'Не удалось сохранить файл.'];
            }
        }
        @chmod($dest, 0644);

        $info2 = @getimagesize($dest) ?: [$w, $h];
        $st = Database::pdo()->prepare(
            'INSERT INTO media (filename, orig_name, mime, size, width, height, created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $filename, mb_substr((string)($file['name'] ?? $filename), 0, 200),
            $mime, filesize($dest) ?: 0, $info2[0] ?? null, $info2[1] ?? null, time(), $username,
        ]);
        Audit::log('media_upload', $filename);

        return ['ok' => true, 'error' => null, 'media' => self::byId((int)Database::pdo()->lastInsertId())];
    }

    /** Пересжатие/уменьшение через GD. Возвращает true при успехе. */
    private static function reencode(string $src, string $dest, string $mime, int $w, int $h, int $maxW, int $maxH): bool
    {
        if (!function_exists('imagecreatetruecolor')) return false;
        if ($mime === 'image/gif') return false;   // не трогаем GIF (могут быть анимированные)

        $img = @imagecreatefromstring((string)file_get_contents($src));
        if ($img === false) return false;

        // Масштабирование, если больше лимита.
        $scale = min(1, $maxW / max(1, $w), $maxH / max(1, $h));
        if ($scale < 1) {
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        } else {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($img, $dest, 85),
            'image/png'  => imagepng($img, $dest, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($img, $dest, 85) : false,
            default      => false,
        };
        imagedestroy($img);
        return (bool)$ok;
    }

    public static function all(int $limit = 500): array
    {
        $limit = max(1, min($limit, 2000));
        return Database::pdo()->query("SELECT * FROM media ORDER BY id DESC LIMIT $limit")->fetchAll();
    }

    public static function byId(int $id): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM media WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function delete(int $id): void
    {
        $m = self::byId($id);
        if (!$m) return;
        @unlink(self::dir() . '/' . $m['filename']);
        Database::pdo()->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
        Audit::log('media_delete', $m['filename']);
    }
}
