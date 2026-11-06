<?php
/*
|--------------------------------------------------------------------------
| sms-cron.php — اجرای زمان‌بندی‌شدهٔ برنامهٔ پیامک (ملی‌پیامک)
|--------------------------------------------------------------------------
| هر ۵ دقیقه یک‌بار از cron هاست صدا زده می‌شود:
|   php /home/USER/public_html/sms-cron.php
|   یا: curl -s "https://SITE/sms-cron.php?token=XXXX"
| توکن از تب «برنامهٔ پیامک» پنل ادمین تنظیم می‌شود. اگر توکن تنظیم نشده
| باشد اجرا رد می‌شود (گارد امنیتی).
| ترتیب کارها: هشدار ادمین → جستجوی ذخیره‌شده → انطباق درخواست‌ها →
| کمپین‌های زمان‌دار → ارسال صف (با سقف روزانه و ساعات مجاز مقررات ملی).
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {
    // حالت وب: فقط با توکن
    $mkToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    require_once __DIR__ . '/config.php';
    $pdo = melkinoInitDbGlobal();
    require_once __DIR__ . '/db_helpers.php';
    require_once __DIR__ . '/db-settings.php';
    require_once __DIR__ . '/sms-program.php';
    require_once __DIR__ . '/sms.php';
    $cfg = smsProgramSettings($pdo);
    $expected = (string)($cfg['cron_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $mkToken)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'توکن اجرای برنامهٔ پیامک معتبر نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    // حالت CLI (cron هاست)
    require_once __DIR__ . '/config.php';
    $pdo = melkinoInitDbGlobal();
    require_once __DIR__ . '/db_helpers.php';
    require_once __DIR__ . '/db-settings.php';
    require_once __DIR__ . '/sms-program.php';
    require_once __DIR__ . '/sms.php';
}

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// اگر دامنهٔ سایت در تنظیمات ثبت شده، لینک‌های پیامک از همان ساخته شوند
try {
    $__cfgHost = smsProgramSettings($pdo);
    $__siteUrl = trim((string)($__cfgHost['site_url'] ?? ''));
    if ($__siteUrl !== '' && PHP_SAPI === 'cli') {
        $__host = parse_url($__siteUrl, PHP_URL_HOST);
        if ($__host) {
            $_SERVER['HTTP_HOST'] = $__host;
            if (strpos(strtolower($__siteUrl), 'https://') === 0) {
                $_SERVER['HTTPS'] = 'on';
            }
        }
    }
} catch (Throwable $e) {
}

try {
    $res = smsProgramTick($pdo, false);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'خطای اجرای برنامهٔ پیامک.'], JSON_UNESCAPED_UNICODE);
}
