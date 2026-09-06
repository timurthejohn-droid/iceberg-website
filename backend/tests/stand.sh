#!/bin/bash
# Поднять чистый стенд админки на текущей сборке сайта и запустить локальный сервер.
#
#   ./tests/stand.sh            # стенд в /tmp/iceberg-stand, сервер на 127.0.0.1:8090
#   ICEBERG_PHP=/путь/к/php ./tests/stand.sh
#
# Нужен PHP 8.1+ с pdo_sqlite и gd. Если в системе PHP нет, подойдёт переносимая сборка:
#   curl -sL -o php.tar.gz https://dl.static-php.dev/static-php-cli/common/php-8.3.9-cli-macos-aarch64.tar.gz
#   tar xzf php.tar.gz            # рядом появится бинарник php
# (для Linux — файл с суффиксом linux-x86_64; список сборок на dl.static-php.dev)
set -e

STAND="${ICEBERG_STAND:-/tmp/iceberg-stand}"
PHP="${ICEBERG_PHP:-php}"
PORT="${ICEBERG_PORT:-8090}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"          # 04-site-iceberg/backend
DIST="$(cd "$HERE/../dist" && pwd)"

pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
pkill -f "\-S 127.0.0.1:$PORT" 2>/dev/null || true

# Копируем именно СОДЕРЖИМОЕ папок: «cp -R src dst» при уже существующем dst кладёт src ВНУТРЬ него,
# и повторный запуск оставил бы стенд на старом коде.
mkdir -p "$STAND/app" "$STAND/bin" "$STAND/public"
cp -R "$HERE/app/." "$STAND/app/"
cp -R "$HERE/bin/." "$STAND/bin/"
cp -R "$HERE/public/." "$STAND/public/"
cp -R "$DIST/." "$STAND/public/"                  # сборка сайта в веб-корень
cp "$HERE/public/.htaccess" "$STAND/public/.htaccess"   # объединённый — поверх того, что из dist
mkdir -p "$STAND/data" "$STAND/app/baseline"
rm -f "$STAND/data/content.db" "$STAND/data/content.db-wal" "$STAND/data/content.db-shm" \
      "$STAND/public/leads.log" "$STAND/leads.log" "$STAND"/data/cache/page_*.html

# Конфиг стенда
"$PHP" -r '
$src = file_get_contents($argv[1]);
$src = str_replace("ЗАМЕНИТЬ_НА_openssl_rand_hex_32", bin2hex(random_bytes(32)), $src);
$src = str_replace("https://iceberg.spb.ru", "http://127.0.0.1:" . $argv[3], $src);
file_put_contents($argv[2], $src);
' "$STAND/app/config.example.php" "$STAND/app/config.php" "$PORT"

# Роутер для встроенного сервера PHP: повторяет логику .htaccess (есть файл — отдаём файл).
cat > "$STAND/public/_router.php" <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) return false;
if (str_starts_with($path, '/admin')) { require __DIR__ . '/admin/index.php'; return true; }
require __DIR__ . '/index.php';
PHP

cd "$STAND"
"$PHP" bin/install_layout.php > /dev/null
"$PHP" bin/create_admin.php marketolog "PareVaLnyj2026pass" > /dev/null
"$PHP" bin/seed.php > /dev/null

cd "$STAND/public"
nohup "$PHP" -S "127.0.0.1:$PORT" _router.php > "$STAND/server.log" 2>&1 &
sleep 2
echo "Стенд поднят: http://127.0.0.1:$PORT  (админка: /admin/, вход marketolog)"
echo "Проверки:     ICEBERG_STAND=$STAND ICEBERG_PHP=$PHP python3 $HERE/tests/smoke.py"
