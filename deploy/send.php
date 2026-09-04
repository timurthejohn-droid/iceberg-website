<?php
/**
 * /send.php — приём заявок с форм сайта iceberg.spb.ru и отправка их почтой.
 *
 * Стек: PHP 8.1+ и PHPMailer 7.x, положенный рядом (deploy/lib/PHPMailer),
 * без Composer — как и вся остальная серверная часть проекта.
 *
 * Принимает POST: JSON (Content-Type: application/json) или обычную форму.
 * Поля: name, phone | contact, message, consent, form, page + метки utm_*.
 * Отдаёт JSON: {"ok":true} либо {"ok":false,"error":"…"}.
 *
 * Настройки почты — в mail-config.php (там же заглушки SMTP).
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Ответ и выход. */
function reply(bool $ok, string $error = '', int $code = 200): never
{
    http_response_code($code);
    echo json_encode(
        $ok ? ['ok' => true] : ['ok' => false, 'error' => $error],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(false, 'Метод не поддерживается.', 405);
}

define('ICEBERG_MAIL', true);
$cfgFile = __DIR__ . '/mail-config.php';
if (!is_file($cfgFile)) {
    error_log('send.php: нет mail-config.php');
    reply(false, 'Форма временно недоступна. Позвоните: +7 (812) 325-69-93', 500);
}
$cfg = require $cfgFile;

// ── разбор тела запроса ─────────────────────────────────────────────────────
$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '' && str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $in = $decoded;
    }
}
if (!$in) {
    $in = $_POST;
}
if (!$in) {
    reply(false, 'Пустой запрос.', 400);
}

/** Чистая однострочная строка ограниченной длины. */
function clean(mixed $v, int $max = 300): string
{
    if (!is_scalar($v)) {
        return '';
    }
    $s = trim((string)$v);
    $s = str_replace(["\r", "\n", "\0"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return mb_substr($s, 0, $max);
}

// ── ловушка для ботов: поле скрыто в вёрстке, человек его не заполнит ───────
if (clean($in['website'] ?? '') !== '' || clean($in['company_url'] ?? '') !== '') {
    reply(true);   // боту отвечаем «успех», письмо не шлём
}

// ── обязательное согласие на обработку персональных данных (152-ФЗ) ─────────
$consent = $in['consent'] ?? '';
if (!in_array((string)$consent, ['1', 'on', 'true', 'yes'], true)) {
    reply(false, 'Нужно согласие на обработку персональных данных.', 400);
}

// ── поля ───────────────────────────────────────────────────────────────────
$name    = clean($in['name'] ?? '', 120);
$contact = clean($in['phone'] ?? ($in['contact'] ?? ''), 120);
$message = clean($in['message'] ?? '', 4000);
$formId  = clean($in['form'] ?? 'lead', 60);
$page    = clean($in['page'] ?? '', 200);

if (mb_strlen($name) < 2) {
    reply(false, 'Укажите имя.', 400);
}
$digits = preg_replace('/\D+/', '', $contact) ?? '';
$looksLikeMail = (bool)filter_var($contact, FILTER_VALIDATE_EMAIL);
if (strlen($digits) < 10 && !$looksLikeMail) {
    reply(false, 'Укажите телефон или почту.', 400);
}

// ── антиспам: не чаще одной заявки с IP раз в N секунд ─────────────────────
$ip = clean($_SERVER['REMOTE_ADDR'] ?? '', 45);
$throttle = (int)($cfg['throttle_seconds'] ?? 0);
if ($throttle > 0 && $ip !== '') {
    $stamp = sys_get_temp_dir() . '/ib_lead_' . md5($ip);
    if (is_file($stamp) && (time() - (int)filemtime($stamp)) < $throttle) {
        reply(false, 'Заявка уже отправлена. Подождите немного.', 429);
    }
    @touch($stamp);
}

// ── метки рекламы ──────────────────────────────────────────────────────────
$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
          'yclid', 'gclid', 'variant'] as $k) {
    $v = clean($in[$k] ?? '', 200);
    if ($v !== '') {
        $utm[$k] = $v;
    }
}

// ── письмо ─────────────────────────────────────────────────────────────────
$rows = [
    'Имя'      => $name,
    'Контакт'  => $contact,
    'Сообщение'=> $message !== '' ? $message : '—',
    'Форма'    => $formId,
    'Страница' => $page !== '' ? 'https://iceberg.spb.ru' . $page : '—',
    'Время'    => date('d.m.Y H:i:s'),
    'IP'       => $ip !== '' ? $ip : '—',
];
foreach ($utm as $k => $v) {
    $rows[$k] = $v;
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$html = '<h2 style="font:600 18px/1.3 Arial,sans-serif;margin:0 0 14px">Новая заявка с сайта</h2>'
      . '<table cellpadding="7" cellspacing="0" border="0" '
      . 'style="font:14px/1.5 Arial,sans-serif;border-collapse:collapse">';
$plain = "Новая заявка с сайта iceberg.spb.ru\n\n";
foreach ($rows as $k => $v) {
    $html .= '<tr><td style="color:#6b7681;border-bottom:1px solid #E3E7EA;white-space:nowrap">'
           . $esc((string)$k) . '</td><td style="border-bottom:1px solid #E3E7EA">'
           . nl2br($esc((string)$v)) . '</td></tr>';
    $plain .= $k . ': ' . $v . "\n";
}
$html .= '</table>';

// ── страховка: пишем заявку в файл до отправки ─────────────────────────────
$logFile = (string)($cfg['log_file'] ?? '');
if ($logFile !== '') {
    @file_put_contents(
        $logFile,
        json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

require __DIR__ . '/lib/PHPMailer/src/Exception.php';
require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/src/SMTP.php';

$mail = new PHPMailer(true);
try {
    PHPMailer::setLanguage('ru', __DIR__ . '/lib/PHPMailer/language/');
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;

    $smtp = $cfg['smtp'] ?? [];
    if (!empty($smtp['enabled'])) {
        $mail->isSMTP();
        $mail->Host       = (string)($smtp['host'] ?? '');
        $mail->Port       = (int)($smtp['port'] ?? 465);
        $mail->SMTPAuth   = (bool)($smtp['auth'] ?? true);
        $mail->Username   = (string)($smtp['username'] ?? '');
        $mail->Password   = (string)($smtp['password'] ?? '');
        $mail->Timeout    = (int)($smtp['timeout'] ?? 15);
        $mail->SMTPDebug  = (int)($smtp['debug'] ?? 0);
        $mail->Debugoutput = 'error_log';
        if (!empty($smtp['secure'])) {
            $mail->SMTPSecure = (string)$smtp['secure'];
        }
    }

    $mail->setFrom((string)$cfg['from']['email'], (string)$cfg['from']['name']);
    foreach (($cfg['to'] ?? []) as $rcpt) {
        $mail->addAddress((string)$rcpt['email'], (string)($rcpt['name'] ?? ''));
    }
    foreach (($cfg['cc'] ?? []) as $rcpt) {
        $mail->addCC((string)$rcpt['email'], (string)($rcpt['name'] ?? ''));
    }
    foreach (($cfg['bcc'] ?? []) as $rcpt) {
        $mail->addBCC((string)$rcpt['email'], (string)($rcpt['name'] ?? ''));
    }
    // Ответить клиенту прямо из письма, если он оставил почту
    if ($looksLikeMail) {
        $mail->addReplyTo($contact, $name);
    }

    $mail->Subject = trim((string)($cfg['subject_prefix'] ?? 'Заявка')) . ' · ' . $formId . ' · ' . $name;
    $mail->isHTML(true);
    $mail->Body    = $html;
    $mail->AltBody = $plain;
    $mail->send();
} catch (Throwable $e) {
    error_log('send.php: письмо не ушло — ' . $e->getMessage());
    // Заявка уже записана в leads.log, поэтому не теряется.
    reply(false, 'Не удалось отправить. Позвоните: +7 (812) 325-69-93', 500);
}

reply(true);
