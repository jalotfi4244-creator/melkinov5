<?php
/*
|--------------------------------------------------------------------------
| انتشار آگهی در کانال/گروه بله
|--------------------------------------------------------------------------
| همسان با publish-to-telegram.php، اما از طریق Bot API بله
| (https://tapi.bale.ai) و با استفاده از توکن و کانالی که ادمین در تب
| «ربات و کانال» تنظیم کرده است.
|
| ترتیب ارسال:
|   ۱. اگر تصویری برای آگهی ثبت شده باشد → sendPhoto همراه با توضیحات
|   ۲. در صورت نبود تصویر یا خطا → sendMessage (متن کامل آگهی)
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';

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
if (!is_array($payload)) {
    $payload = [];
}
$adId = trim((string)($payload['id'] ?? $_POST['id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   گارد ضدتکرار ارسال (رفع مشکل انتشار سه‌باره)
   اگر همین request_id قبلاً نتیجهٔ قطعی ثبت شده باشد،
   همان پاسخ برمی‌گردد و هرگز دوباره پست نمی‌شود.
   ========================================================= */
$sendRequestId = trim((string)($payload['request_id'] ?? $_POST['request_id'] ?? ''));
if ($sendRequestId !== '' && function_exists('melkinoSendGuardTake')) {
    $guard = melkinoSendGuardTake($pdo, $sendRequestId);
    if ($guard['seen'] && is_array($guard['response'])) {
        echo json_encode($guard['response'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* =========================================================
   بررسی تنظیمات ربات بله
   ========================================================= */

$token = function_exists('melkinoBaleToken') ? melkinoBaleToken() : (defined('BALE_BOT_TOKEN') ? (string)BALE_BOT_TOKEN : '');
$channel = function_exists('melkinoBotSetting') ? melkinoBotSetting('bale_channel') : '';
$channel = trim($channel);

if ($token === '' || $token === 'توکن_ربات_بله') {
    echo json_encode([
        'success' => false,
        'message' => 'توکن ربات بله تنظیم نشده است. از تب «ربات و کانال» در پنل ادمین توکن را وارد و ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($channel === '') {
    echo json_encode([
        'success' => false,
        'message' => 'شناسه کانال بله تنظیم نشده است. از تب «ربات و کانال» در پنل ادمین شناسه کانال (مثل @melkino) را ذخیره کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   خواندن اطلاعات آگهی
   ========================================================= */

try {
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ? LIMIT 1");
    $stmt->execute([$adId]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);

// راند ۲۵: پنجرهٔ ضدتکرار ۱۲۰ ثانیه‌ای — اگر همین الان (کمتر از ۲ دقیقه)
// این آگهی روی همین پلتفرم منتشر شده، دوباره ارسال نمی‌کنیم (جلوگیری از
// انتشار دوبل وقتی مسیر مرورگر و سرور هر دو اجرا شوند). با force=1 همیشه ارسال می‌شود.
$__force = !empty($payload['force']) || !empty($_POST['force']) || !empty($_GET['force']);
if (!$__force && !empty($ad['bale_published_at'])) {
    // مقایسه در خودِ دیتابیس (ساعت MySQL) تا اختلاف منطقهٔ زمانی PHP/MySQL
    // باعث از‌کارافتادن این گارد نشود (راند ۱۰).
    $__freshSt = $pdo->prepare("SELECT (bale_published_at > NOW() - INTERVAL 120 SECOND) FROM ads WHERE id = ? LIMIT 1");
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
   ساخت متن پیام (برای بله از متن ساده استفاده می‌شود
   تا در همه‌ی کلاینت‌ها درست نمایش داده شود)
   ========================================================= */

function baleMoney($value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || !is_numeric(str_replace(',', '', $raw))) {
        return '';
    }
    return number_format((float)str_replace(',', '', $raw), 0, '.', ',') . ' تومان';
}

$lines = [];
$lines[] = '🏠 ' . melkinoCleanAdTitle($ad['title'] ?? '');
$lines[] = '';
$lines[] = '📌 نوع معامله: ' . (string)($ad['transaction_type'] ?: '-');
$lines[] = '🏷️ نوع ملک: ' . (string)($ad['property_type'] ?: '-');

if (!empty($ad['location'])) {
    $lines[] = '📍 موقعیت: ' . (string)$ad['location'];
}
if (!empty($ad['address'])) {
    $lines[] = '🗺️ آدرس: ' . (string)$ad['address'];
}
if (!empty($ad['area'])) {
    $lines[] = '📐 متراژ: ' . (string)$ad['area'] . ' متر';
}
if (!empty($ad['rooms'])) {
    $lines[] = '🛏️ تعداد اتاق: ' . (string)$ad['rooms'];
}
if (!empty($ad['floor'])) {
    $lines[] = '🏢 طبقه: ' . (string)$ad['floor'];
}
if (!empty($ad['year'])) {
    $lines[] = '📅 سال ساخت: ' . (string)$ad['year'];
}

if (empty($ad['price_hidden'])) {
    $priceLine = '';
    if (!empty($ad['price_sell'])) {
        $priceLine = '💰 قیمت فروش: ' . baleMoney($ad['price_sell']);
    } elseif (!empty($ad['full_rent_enabled']) && !empty($ad['full_rent'])) {
        $priceLine = '💰 اجاره کامل: ' . baleMoney($ad['full_rent']);
    } elseif (!empty($ad['deposit']) || !empty($ad['rent_monthly'])) {
        $priceLine = '💰 ودیعه: ' . baleMoney($ad['deposit']) . ' | اجاره: ' . baleMoney($ad['rent_monthly']);
    } elseif (!empty($ad['total_price'])) {
        $priceLine = '💰 قیمت کل: ' . baleMoney($ad['total_price']);
    }
    if ($priceLine !== '') {
        $lines[] = $priceLine;
    }
} else {
    $lines[] = '💰 قیمت: توافقی (تماس بگیرید)';
}

if (!empty($ad['description'])) {
    $lines[] = '';
    $lines[] = '📝 ' . (string)$ad['description'];
}

/**
 * نکته‌ی امنیتی و حریم خصوصی:
 *   نام و شماره تماسِ ثبت‌کننده‌ی آگهی هرگز منتشر نمی‌شود؛
 *   به‌جای آن شماره‌ی مشاوری که در پنل ادمین ثبت شده درج می‌شود.
 *   تابع melkinoAdMessageText تابعِ مشترک با سایر مسیرهاست تا خروجی
 *   همه‌ی مسیرهای انتشار دقیقاً یکسان باشد.
 *   (برای بله از parse_mode استفاده نمی‌کنیم، پس html=false است)
 */
$customText = trim((string) ($payload['text'] ?? $_POST['text'] ?? ''));
if ($customText !== '') {
    $messageText = $customText;
} elseif (function_exists('melkinoAdMessageText')) {
    $fieldsIn = $payload['fields'] ?? null;
    $fieldsOverride = is_array($fieldsIn) ? array_values(array_map('strval', $fieldsIn)) : null;
    $messageText = melkinoAdMessageText($ad, false, 'bale', $fieldsOverride);
} else {
    $messageText = implode("\n", $lines);
}

if (!function_exists('melkinoAdMessageText')) {
    $lines[] = '';
    $lines[] = '📞 جهت اطلاعات بیشتر با ما تماس بگیرید:';
    if (function_exists('getConsultantPhone')) {
        $cPhone = trim((string)getConsultantPhone());
        if ($cPhone !== '') {
            $lines[] = '☎️ ' . $cPhone;
        }
    }
    $lines[] = '';
    $lines[] = '🔗 کد آگهی: ' . (string)$ad['id'];
    $messageText = implode("\n", $lines);
}

/* =========================================================
   یافتن تصویر آگهی (در صورت وجود)
   ========================================================= */

$imagePath = '';
try {
    $imgStmt = $pdo->prepare(
        "SELECT filename FROM images
          WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1
          ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1"
    );
    $imgStmt->execute([$adId]);
    $imageName = trim((string)$imgStmt->fetchColumn());

    if ($imageName !== '') {
        $candidates = [
            __DIR__ . '/' . ltrim(str_replace('\\', '/', $imageName), '/'),
            __DIR__ . '/uploads/' . ltrim(str_replace('\\', '/', $imageName), '/'),
            __DIR__ . '/uploads/' . basename($imageName),
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $imagePath = $candidate;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $imagePath = '';
}

/* =========================================================
   ارسال به بله
   ========================================================= */

$apiBase = 'https://tapi.bale.ai/bot' . $token;
$result = null;
$sentWithPhoto = false;

// تلاش اول: ارسال تصویر همراه با توضیحات
$result = null;
$sentWithPhoto = false;
$photoAttempted = false;
$photoDefiniteFail = false;

if ($imagePath !== '' && function_exists('curl_init')) {
    $photoAttempted = true;
    $postFields = [
        'chat_id' => $channel,
        'caption' => $messageText,
        'photo' => new CURLFile($imagePath),
    ];

    $ch = curl_init($apiBase . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $postFields,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    if (is_array($decoded) && !empty($decoded['ok'])) {
        $result = $decoded;
        $sentWithPhoto = true;
    } elseif (is_array($decoded) && array_key_exists('ok', $decoded)) {
        // بله رسماً پاسخ داد و رد کرد → امن است که متن جدا بفرستیم
        $photoDefiniteFail = true;
    }
    // پاسخ خالی/نامفهوم یعنی وضعیت نامشخص: ممکن است عکس رفته باشد
}

// اگر ارسال تصویر انجام شده ولی پاسخ قطعی نگرفته‌ایم، هرگز متن جدا نمی‌فرستیم
// (همین تلاش دوم، عامل انتشار چندباره بود).
if ($result === null && $photoAttempted && !$photoDefiniteFail) {
    echo json_encode([
        'success' => false,
        'ambiguous' => true,
        'message' => 'پاسخ قطعی از بله دریافت نشد و ممکن است پست همین حالا ارسال شده باشد. لطفاً کانال را بررسی کنید؛ اگر پست آمده دیگر انتشار ندهید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// تلاش دوم: ارسال متن ساده — فقط وقتی تصویری در کار نبوده یا بله رسماً عکس را رد کرده
if ($result === null) {
    $postFields = http_build_query([
        'chat_id' => $channel,
        'text' => $messageText,
    ]);

    $response = function_exists('melkinoHttpPost')
        ? melkinoHttpPost($apiBase . '/sendMessage', $postFields)
        : false;

    if ($response === false || $response === null) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postFields,
                'timeout' => 25,
            ],
        ]);
        $response = @file_get_contents($apiBase . '/sendMessage', false, $context);
    }

    if ($response === false || $response === null || $response === '') {
        echo json_encode([
            'success' => false,
            'ambiguous' => true,
            'message' => 'پاسخ قطعی از بله دریافت نشد؛ ممکن است پیام ارسال شده باشد. پیش از تلاش دوباره کانال را بررسی کنید.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = json_decode((string)$response, true);
}

if (!is_array($result) || empty($result['ok'])) {
    $baleError = is_array($result)
        ? ($result['description'] ?? 'خطای نامشخص از سمت بله')
        : 'پاسخ نامعتبر از سرور بله';

    melkinoLogChannelPublish($pdo, $adId, 'bale', false, null, $baleError);
    $failPayload = [
        'success' => false,
        'message' => 'بله درخواست را رد کرد: ' . $baleError,
    ];
    // نتیجهٔ قطعی (ردِ رسمی) را ثبت کن تا تلاش تکراری با همین شناسه، دوباره نفرستد
    if ($sendRequestId !== '' && is_array($result) && function_exists('melkinoSendGuardStore')) {
        melkinoSendGuardStore($pdo, $sendRequestId, $failPayload);
    }
    echo json_encode($failPayload, JSON_UNESCAPED_UNICODE);
    exit;
}

$messageId = $result['result']['message_id'] ?? null;

/* =========================================================
   ثبت در دیتابیس (در صورت نبود ستون، ستون‌ها ساخته می‌شوند)
   ========================================================= */

try {
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM ads')->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $columns[strtolower((string)$col['Field'])] = true;
    }

    if (!isset($columns['bale_message_id'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_message_id VARCHAR(100) NULL");
    }
    if (!isset($columns['bale_channel_id'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_channel_id VARCHAR(191) NULL");
    }
    if (!isset($columns['bale_published_at'])) {
        $pdo->exec("ALTER TABLE ads ADD COLUMN bale_published_at DATETIME NULL");
    }

    $update = $pdo->prepare(
        "UPDATE ads SET bale_message_id = ?, bale_channel_id = ?, bale_published_at = NOW() WHERE id = ?"
    );
    $update->execute([$messageId, $channel, $adId]);
} catch (Throwable $e) {
    // پیام با موفقیت ارسال شده؛ فقط ثبت آن در دیتابیس ناموفق بود
}

// لاگ انتشار (راند ۱۸)
melkinoLogChannelPublish($pdo, $adId, 'bale', true, $messageId, 'انتشار از سمت سرور');

$successPayload = [
    'success' => true,
    'message' => $sentWithPhoto
        ? 'آگهی با تصویر در کانال بله منتشر شد.'
        : 'آگهی در کانال بله منتشر شد.',
    'bale_message_id' => $messageId,
    'with_photo' => $sentWithPhoto,
];
// نتیجهٔ موفق را برای request_id ثبت کن تا درخواست تکراری، پست دوباره نفرستد
if ($sendRequestId !== '' && function_exists('melkinoSendGuardStore')) {
    melkinoSendGuardStore($pdo, $sendRequestId, $successPayload);
}
echo json_encode($successPayload, JSON_UNESCAPED_UNICODE);
