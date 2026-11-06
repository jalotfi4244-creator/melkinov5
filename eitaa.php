<?php
/*
|--------------------------------------------------------------------------
| توابع کمکی ایتا: ارسال پیام و تأیید initData برنامک
|--------------------------------------------------------------------------
| کیت رسمی: https://developer.eitaa.com/eitaa-web-app.js → window.Eitaa.WebApp
| امضای initData همان HMAC-SHA256 تلگرام است (کلید WebAppData + توکن برنامه).
| ارسال پیام طبق مستندات ایتا:
|   POST https://eitaayar.ir/api/app/sendMessage
|   JSON: { token, chat_id, text }
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/telegram.php'; // melkinoHttpPost

function eitaaVerifyInitData(string $initData): ?array
{
    return function_exists('melkinoVerifyEitaaInitData')
        ? melkinoVerifyEitaaInitData($initData)
        : null;
}

function eitaaToken(): string
{
    if (function_exists('melkinoEitaaToken')) {
        return melkinoEitaaToken();
    }
    return defined('EITAA_BOT_TOKEN') ? (string)EITAA_BOT_TOKEN : '';
}

/**
 * یک پیام متنی از طریق برنامهٔ ایتا ارسال می‌کند.
 *
 * @return array{success:bool,message:string,message_id:?int}
 */
function eitaaSendMessage(string $chatId, string $text): array
{
    $token = eitaaToken();
    if ($token === '' || $token === 'توکن_برنامه_ایتا') {
        return ['success' => false, 'message' => 'توکن ایتا تنظیم نشده است.', 'message_id' => null];
    }
    if ($chatId === '') {
        return ['success' => false, 'message' => 'chat_id نامعتبر است.', 'message_id' => null];
    }

    $payload = json_encode([
        'token'   => $token,
        'chat_id' => is_numeric($chatId) ? (int)$chatId : $chatId,
        'text'    => $text,
    ], JSON_UNESCAPED_UNICODE);

    $url = 'https://eitaayar.ir/api/app/sendMessage';
    $response = melkinoHttpPost($url, $payload, ['Content-Type: application/json']);

    if ($response === null || $response === '' || $response === false) {
        // مسیر جایگزینِ بات‌استایل ایتایار
        $alt = 'https://eitaayar.ir/bot' . $token . '/sendMessage';
        $response = melkinoHttpPost($alt, http_build_query([
            'chat_id' => $chatId,
            'text'    => $text,
        ]));
    }

    if ($response === null || $response === '' || $response === false) {
        return ['success' => false, 'message' => 'اتصال به سرور ایتا برقرار نشد.', 'message_id' => null];
    }

    $result = json_decode((string)$response, true);
    if (!is_array($result)) {
        return ['success' => false, 'message' => 'پاسخ نامعتبر از ایتا.', 'message_id' => null];
    }
    if (empty($result['ok']) && (($result['result'] ?? '') !== 'success')) {
        return [
            'success'    => false,
            'message'    => (string)($result['description'] ?? $result['message'] ?? 'خطای نامشخص از ایتا'),
            'message_id' => null,
        ];
    }

    $mid = null;
    if (isset($result['result']['message_id'])) {
        $mid = (int)$result['result']['message_id'];
    }

    return ['success' => true, 'message' => 'ارسال شد', 'message_id' => $mid];
}
