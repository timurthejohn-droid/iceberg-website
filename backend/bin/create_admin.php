<?php
/**
 * Создание первого/нового администратора. ТОЛЬКО из командной строки (не через веб).
 *   php bin/create_admin.php                      # интерактивно
 *   php bin/create_admin.php <логин> <пароль>     # для автоматизации
 * После создания: при ПЕРВОМ входе пользователь привяжет 2FA (покажется ключ).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Этот скрипт запускается только из командной строки.\n");
}

require __DIR__ . '/../app/bootstrap.php';
Database::pdo();   // создаст базу и схему при первом запуске

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';

if ($username === '') {
    fwrite(STDOUT, "Логин администратора: ");
    $username = trim((string)fgets(STDIN));
}
if ($password === '') {
    fwrite(STDOUT, "Пароль (от 12 символов, буквы и цифры): ");
    // Прячем ввод, если позволяет терминал.
    @shell_exec('stty -echo 2>/dev/null');
    $password = trim((string)fgets(STDIN));
    @shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, "\n");
}

if (!preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $username)) {
    exit("Ошибка: логин 3–32 символа, латиница/цифры/._-\n");
}
if ($prob = Auth::passwordProblem($password)) {
    exit("Ошибка: $prob\n");
}
if (Auth::findByUsername($username)) {
    exit("Ошибка: пользователь «$username» уже существует.\n");
}

$id = Auth::createUser($username, $password);
fwrite(STDOUT, "✔ Администратор «$username» создан (id $id).\n");
fwrite(STDOUT, "  Откройте /admin/ и войдите — при первом входе привяжете приложение 2FA.\n");
