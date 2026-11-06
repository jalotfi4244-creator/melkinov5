<?php
/*
|--------------------------------------------------------------------------
| انتشار واقعی آگهی در گروه/کانال تلگرام
|--------------------------------------------------------------------------
| قبلاً دکمه‌ی «انتشار در تلگرام» در پنل ادمین فقط یک alert نمایشی
| بود و هیچ پیامی واقعاً ارسال نمی‌شد. این فایل با استفاده از Bot API
| تلگرام، متن کامل آگهی را به گروه/کانال تنظیم‌شده ارسال می‌کند.
|
| پیش‌نیاز: باید ثابت‌های BOT_TOKEN و CHANNEL_ID در config.php با
| مقادیر واقعی جایگزین شوند (نه مقدار پیش‌فرض «توکن_ربات_تلگرام»):
|   ۱. با @BotFather یک بات بساز و توکنش را در BOT_TOKEN بگذار.
|   ۲. همان بات را به گروه/کانال موردنظر، به‌عنوان ادمین اضافه کن.
|   ۳. آیدی عددی گروه/کانال (یا @username در صورت عمومی بودن) را
|      در CHANNEL_ID بگذار.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/telegram.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}
melkinoCsrfCheck();

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = [];
$adId = trim((string)($payload['id'] ?? $_POST['id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// گارد ضدتکرار ارسال (راند ۱۰): هر request_id فقط یک بار ارسال می‌کند.
$sendRequestId = trim((string)($payload['request_id'] ?? $_POST['request_id'] ?? ''));
if ($sendRequestId !== '' && function_exists('melkinoSendGuardTake')) {
    $guard = melkinoSendGuardTake($pdo, $sendRequestId);
    if ($guard['seen'] && is_array($guard['response'])) {
        echo json_encode($guard['response'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// توکن و کانال از پنل ادمین (دیتابیس) خوانده می‌شوند و در صورت نبودن،
// از ثابت‌های config.php — مثل بقیه‌ی مسیرهای انتشار.
$tgToken = function_exists('melkinoTelegramToken')
    ? (string)melkinoTelegramToken()
    : (defined('BOT_TOKEN') ? (string)BOT_TOKEN : '');
$tgChannel = function_exists('melkinoChannelId')
    ? (string)melkinoChannelId()
    : (defined('CHANNEL_ID') ? (string)CHANNEL_ID : '');

if ($tgToken === '' || $tgToken === 'توکن_ربات_تلگرام') {
    echo json_encode([
        'success' => false,
        'message' => 'توکن ربات تلگرام تنظیم نشده. از تب «ربات و کانال» پنل ادمین آن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tgChannel === '' || $tgChannel === '@آیدی_کانال') {
    echo json_encode([
        'success' => false,
        'message' => 'آیدی گروه/کانال تلگرام تنظیم نشده. از تب «ربات و کانال» پنل ادمین آن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ? LIMIT 1");
    $stmt->execute([$adId]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);

// راند ۲۵: پنجرهٔ ضدتکرار ۱۲۰ ثانیه‌ای — اگر همین الان (کمتر از ۲ دقیقه)
// این آگهی روی همین پلتفرم منتشر شده، دوباره ارسال نمی‌کنیم (جلوگیری از
// انتشار دوبل وقتی مسیر مرورگر و سرور هر دو اجرا شوند). با force=1 همیشه ارسال می‌شود.
$__force = !empty($payload['force']) || !empty($_POST['force']) || !empty($_GET['force']);
if (!$__force && !empty($ad['telegram_published_at'])) {
    // مقایسه در خودِ دیتابیس (ساعت MySQL) — راند ۱۰
    $__freshSt = $pdo->prepare("SELECT (telegram_published_at > NOW() - INTERVAL 120 SECOND) FROM ads WHERE id = ? LIMIT 1");
    $__freshSt->execute([$adId]);
    if ((bool)$__freshSt->fetchColumn()) {
        echo json_encode([
            'success' => true,
            'duplicate_prevented' => true,
            'message' => 'این آگهی کمتر از ۲ دقیقه پیش در همین پلتفرم منتشر شده است؛ از انتشار تکراری جلوگیری شد.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'خطا در خواندن اطلاعات آگهی.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$ad) {
    echo json_encode(['success' => false, 'message' => 'آگهی پیدا نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   ساخت متن پیام از روی اطلاعات آگهی
   =========================================================
   از سازنده‌ی مشترک استفاده می‌شود تا انتخاب فیلدها و متن ثابت
   بالا/پایین آگهی (تنظیم‌شده در تب «ربات و کانال») اعمال شود و خروجی
   همه‌ی مسیرهای انتشار (سرور/مرورگر، تلگرام/بله) یکسان باشد.
   ========================================================= */

$customText = trim((string) ($payload['text'] ?? $_POST['text'] ?? ''));
if ($customText !== '') {
    $messageText = $customText;
} else {
    $fieldsIn = $payload['fields'] ?? null;
    $fieldsOverride = is_array($fieldsIn) ? array_values(array_map('strval', $fieldsIn)) : null;
    $messageText = melkinoAdMessageText($ad, true, 'telegram', $fieldsOverride);
}

/* =========================================================
   ارسال به تلگرام
   ========================================================= */

$apiUrl = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
$postFields = http_build_query([
    'chat_id' => $tgChannel,
    'text' => $messageText,
    'parse_mode' => 'HTML',
]);

$response = function_exists('melkinoHttpPost')
    ? melkinoHttpPost($apiUrl, $postFields)
    : false;

if ($response === false || $response === null || $response === '') {
    $proxy = function_exists('melkinoProxy') ? trim((string) melkinoProxy()) : '';
    echo json_encode([
        'success' => false,
        'ambiguous' => true,
        'message' => ($proxy === ''
            ? 'اتصال سرور به تلگرام برقرار نشد (هاست api.telegram.org را مسدود کرده).'
            : 'اتصال از طریق پروکسی به تلگرام برقرار نشد.')
            . ' ممکن است پیام ارسال شده باشد؛ پیش از تلاش دوباره کانال را بررسی کنید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = json_decode($response, true);

if (!is_array($result) || !array_key_exists('ok', $result)) {
    echo json_encode([
        'success' => false,
        'ambiguous' => true,
        'message' => 'پاسخ قطعی از تلگرام دریافت نشد؛ ممکن است پیام ارسال شده باشد. کانال را بررسی کنید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($result['ok'])) {
    $tgError = $result['description'] ?? 'خطای نامشخص از سمت تلگرام';
    melkinoLogChannelPublish($pdo, $adId, 'telegram', false, null, $tgError);
    $failPayload = [
        'success' => false,
        'message' => 'تلگرام درخواست را رد کرد: ' . $tgError,
    ];
    if ($sendRequestId !== '' && function_exists('melkinoSendGuardStore')) {
        melkinoSendGuardStore($pdo, $sendRequestId, $failPayload);
    }
    echo json_encode($failPayload, JSON_UNESCAPED_UNICODE);
    exit;
}

$messageId = $result['result']['message_id'] ?? null;

try {
    $update = $pdo->prepare(
        "UPDATE ads SET telegram_message_id = ?, telegram_channel_id = ?, telegram_published_at = NOW() WHERE id = ?"
    );
    $update->execute([$messageId, $tgChannel, $adId]);
} catch (Throwable $e) {
    // پیام با موفقیت ارسال شده؛ فقط ثبتش در دیتابیس ناموفق بود
}

// لاگ انتشار (راند ۱۸)
melkinoLogChannelPublish($pdo, $adId, 'telegram', true, $messageId, 'انتشار از سمت سرور');

$successPayload = [
    'success' => true,
    'message' => 'آگهی با موفقیت در تلگرام منتشر شد.',
    'telegram_message_id' => $messageId,
];
if ($sendRequestId !== '' && function_exists('melkinoSendGuardStore')) {
    melkinoSendGuardStore($pdo, $sendRequestId, $successPayload);
}
echo json_encode($successPayload, JSON_UNESCAPED_UNICODE);
