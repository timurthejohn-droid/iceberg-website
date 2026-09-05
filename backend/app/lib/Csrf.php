<?php
/**
 * Защита форм от CSRF. Токен живёт в сессии, проверяется на каждом POST.
 * Дополнительно cookie SameSite=Strict уже отсекает межсайтовые запросы.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = random_token(32);
        }
        return $_SESSION['csrf'];
    }

    /** Скрытое поле для вставки в форму. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . h(self::token()) . '">';
    }

    /** Проверка. При провале — 400 и стоп. */
    public static function check(): void
    {
        $sent = (string)($_POST['_csrf'] ?? '');
        if (empty($_SESSION['csrf']) || !safe_equals($_SESSION['csrf'], $sent)) {
            http_response_code(400);
            exit('Сессия устарела или неверный токен формы. Обновите страницу и повторите.');
        }
    }
}
