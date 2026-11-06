<?php
/**
 * وبهوک تلگرام: پاسخ به /start در چت خصوصی ربات با دکمهٔ «ورود به ملکینو» (web_app).
 *
 * - پاسخ از همین درخواستِ وبهوک برگردانده می‌شود (روش method در بدنهٔ پاسخ)؛
 *   بنابراین هاست برای جواب دادن نیازی به دسترسی خروجی به api.telegram.org ندارد.
 * - احراز: هدر X-Telegram-Bot-Api-Secret-Token باید با telegram_webhook_secret برابر باشد.
 * - هیچ داده‌ای ذخیره یا لاگ نمی‌شود؛ شمارهٔ کاربر خوانده یا نگه‌داری نمی‌شود.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot-settings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$reply = static function (int $code, array $body = ['ok' => true]): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $reply(405, ['ok' => false]);
}

try {
    $secret = trim(melkinoBotSetting('telegram_webhook_secret'));
    $sent = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret) || !hash_equals($secret, $sent)) {
        $reply(403, ['ok' => false]);
    }

    $raw = (string)file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        $reply(413, ['ok' => false]);
    }
    $update = json_decode($raw, true, 32);
    if (!is_array($update)) {
        $reply(400, ['ok' => false]);
    }

    $message = $update['message'] ?? null;
    if (!is_array($message)) {
        $reply(200);
    }
    $chat = $message['chat'] ?? null;
    // فقط چت خصوصی؛ گروه و کانال پاسخ نمی‌گیرند.
    if (!is_array($chat) || ($chat['type'] ?? '') !== 'private' || !is_int($chat['id'] ?? null)) {
        $reply(200);
    }
    $text = trim((string)($message['text'] ?? ''));
    if (!preg_match('~^/start(?:@[A-Za-z0-9_]{1,64})?(?:\s|$)~', $text)) {
        $reply(200);
    }

    $url = trim(melkinoBotSetting('telegram_webapp_url'));
    $parts = $url !== '' ? parse_url($url) : false;
    $urlOk = is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && !empty($parts['host'])
        && !isset($parts['user'], $parts['pass'], $parts['fragment'], $parts['port'])
        && strlen($url) <= 512;

    $out = [
        'method' => 'sendMessage',
        'chat_id' => $chat['id'],
    ];
    if ($urlOk) {
        $out['text'] = 'برای ورود به ملکینو روی دکمهٔ زیر بزنید.';
        $out['reply_markup'] = ['inline_keyboard' => [[['text' => 'ورود به ملکینو', 'web_app' => ['url' => $url]]]]];
    } else {
        $out['text'] = 'برای ورود به ملکینو، برنامک را از منوی ربات باز کنید.';
    }
    http_response_code(200);
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log('[telegram-webhook] unavailable');
    $reply(503, ['ok' => false]);
}
