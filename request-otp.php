<?php
/** SMS ownership proof only. Never route the code to an old messenger or display it in HTTP. */

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/security-lib.php';

// =========================================================
// ورود با شماره موبایل / کد یک‌بارمصرف
// قبلاً اینجا یک بلوک ۴۰۳ سخت‌کد بود؛ حالا ادمین از پنل
// (تب «ربات و کانال» → «روش‌های ورود») فعال/غیرفعالش می‌کند.
// =========================================================
if (!melkinoLoginMethodEnabled('sms')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ورود با شماره موبایل توسط مدیر سایت غیرفعال شده است.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/bale.php';
require_once __DIR__ . '/sms.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

melkinoCsrfCheck();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$rawPhone = trim((string)($body['phone'] ?? ''));

// نرمال‌سازی: تبدیل ارقام فارسی/عربی به انگلیسی
$phone = strtr($rawPhone, [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
    '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
]);
$phone = preg_replace('/\D/', '', $phone);
if (strpos($phone, '98') === 0 && strlen($phone) === 12) {
    $phone = '0' . substr($phone, 2);
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'شماره موبایل معتبر نیست (فرمت درست: 09123456789).'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // محدودیت نرخ: حداکثر ۳ درخواست کد برای یک شماره در ۱۰ دقیقه‌ی اخیر،
    // تا از سوءاستفاده (اسپم کردن پیامک/پیام دیگران) جلوگیری شود.
    $rateCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)"
    );
    $rateCheck->execute([$phone]);
    if ((int)$rateCheck->fetchColumn() >= 3) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'تعداد درخواست کد برای این شماره زیاد بوده؛ چند دقیقه‌ی دیگر دوباره امتحان کن.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اصلاح امنیتی: محدودیت قبلی فقط «به ازای شماره» بود، بنابراین یک
    // مهاجم می‌توانست با چرخاندن شماره‌ها، هزاران پیامک/پیام بفرستد و
    // شماره‌ها را enumerate کند. حالا یک سقف مستقل روی IP هم هست.
    if (!melkinoRateLimitHit('otp:ip:' . melkinoClientIp(), 15, 3600, true)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'درخواست‌های زیادی از این دستگاه ثبت شده؛ کمی بعد دوباره تلاش کن.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // اصلاح امنیتی: کدهای قبلیِ همین شماره باطل می‌شوند تا هم‌زمان چند
    // کد معتبر وجود نداشته باشد (کاهش سطح حملهٔ حدس زدن کد).
    $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE phone = ? AND is_used = 0')
        ->execute([$phone]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 120); // اعتبار ۲ دقیقه

    // A bot message proves access to that bot account, not ownership of this phone.
    $smsResult=smsSendCode($phone,$code);
    if(empty($smsResult['success'])){
        http_response_code(503);
        echo json_encode(['success'=>false,'message'=>'ارسال پیامک انجام نشد؛ فعال‌بودن سرویس، تنظیمات و اعتبار پنل پیامک را بررسی کنید.'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $channel='sms';$telegramChatId=null;$baleChatId=null;

    $insert = $pdo->prepare(
        melkinoOtpHasHashColumn()
            ? "INSERT INTO otp_codes (phone, code, code_hash, channel, telegram_chat_id, bale_chat_id, attempts, is_used, expires_at)
               VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?)"
            : "INSERT INTO otp_codes (phone, code, channel, telegram_chat_id, bale_chat_id, attempts, is_used, expires_at)
               VALUES (?, ?, ?, ?, ?, 0, 0, ?)"
    );
    // اگر ستون code_hash روی دیتابیس وجود داشته باشد، هش هم ذخیره می‌شود.
    // تا زمانی که ستون ساخته نشده، دقیقاً مثل قبل رفتار می‌کند.
    if (melkinoOtpHasHashColumn()) {
        $insert->execute([$phone, '', melkinoOtpHash($code), $channel, $telegramChatId, $baleChatId, $expiresAt]);
    } else {
        $insert->execute([$phone, $code, $channel, $telegramChatId, $baleChatId, $expiresAt]);
    }

    echo json_encode(['success'=>true,'channel'=>'sms','message'=>'کد با پیامک ارسال شد.'],JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => melkinoSafeError($e, 'request-otp', 'خطا در ارسال کد.'),
    ], JSON_UNESCAPED_UNICODE);
}
