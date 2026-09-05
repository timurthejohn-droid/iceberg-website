<?php
/**
 * Мелкие утилиты общего назначения. Без состояния, без зависимостей.
 */

/** Экранирование для вывода в HTML (защита от XSS). Используем ВЕЗДЕ при выводе. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Безопасный редирект внутри сайта. */
function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/** JSON-ответ и выход. */
function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Текущее значение из $_POST/$_GET как обрезанная строка. */
function req_str(array $src, string $key, string $default = ''): string
{
    $v = $src[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Реальный IP клиента с учётом доверенного прокси хостинга. */
function client_ip(): string
{
    // На shared-хостинге доверяем только REMOTE_ADDR; заголовки X-Forwarded-* подделываются.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** Проверка попадания IP в список (поддержка одиночных адресов и CIDR IPv4). */
function ip_in_list(string $ip, array $list): bool
{
    foreach ($list as $rule) {
        $rule = trim($rule);
        if ($rule === '') continue;
        if (!str_contains($rule, '/')) {
            if ($ip === $rule) return true;
            continue;
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $ipL = ip2long($ip);
        $subL = ip2long($subnet);
        if ($ipL === false || $subL === false) continue;
        $mask = -1 << (32 - (int)$bits);
        if (($ipL & $mask) === ($subL & $mask)) return true;
    }
    return false;
}

/** Криптостойкая случайная строка (hex). */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Сравнение секретов без утечки по времени. */
function safe_equals(string $a, string $b): bool
{
    return hash_equals($a, $b);
}

/** Гарантирует существование папки с безопасными правами. */
function ensure_dir(string $path, int $mode = 0750): void
{
    if (!is_dir($path)) {
        @mkdir($path, $mode, true);
    }
}

/** Человекочитаемый размер. */
function human_size(int $bytes): string
{
    $u = ['Б', 'КБ', 'МБ', 'ГБ'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return ($i === 0 ? (int)$n : number_format($n, 1, ',', ' ')) . ' ' . $u[$i];
}
