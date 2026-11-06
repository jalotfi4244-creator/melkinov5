<?php
/*
|--------------------------------------------------------------------------
| تأیید کد یک‌بارمصرف (OTP) و ورود
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot-settings.php';

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

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

melkinoCsrfCheck();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

$phone = strtr((string)($body['phone'] ?? ''), [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
]);
$phone = preg_replace('/\D/', '', $phone);
$code = trim((string)($body['code'] ?? ''));
$code = strtr($code, [
    '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
]);

if (!preg_match('/^09\d{9}$/', $phone) || !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'شماره یا کد نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// اصلاح امنیتی: سقف تلاش قبلاً فقط روی «همان ردیف کد» بود؛ مهاجم
// می‌توانست با درخواست کد جدید، شمارندهٔ تلاش را ریست کند. حالا یک سقف
// مستقل روی (IP + شماره) هم هست تا brute-force شش‌رقمی عملی نباشد.
if (!melkinoRateLimitHit('otpverify:' . melkinoClientIp() . ':' . $phone, 10, 900, true)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'تعداد تلاش‌های ناموفق زیاد بوده؛ چند دقیقه دیگر دوباره تلاش کن.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM otp_codes
         WHERE phone = ? AND is_used = 0 AND channel = 'sms'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'کدی برای این شماره درخواست نشده یا قبلاً استفاده شده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        echo json_encode(['success' => false, 'message' => 'کد منقضی شده؛ دوباره درخواست کد بده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // محدودیت تلاش: حداکثر ۳ بار می‌توان کد اشتباه وارد کرد
    if ((int)$row['attempts'] >= 3) {
        echo json_encode(['success' => false, 'message' => 'تعداد تلاش‌های مجاز تمام شده؛ دوباره درخواست کد بده.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تطبیق دوحالته: اگر ستون code_hash وجود داشت با هش مقایسه می‌شود،
    // وگرنه با کد خام (سازگاری کامل عقب‌رو — بدون نیاز به ALTER).
    // HARDEN-02: بافت شماره از خودِ ردیف (تضمین تطابق با لحظهٔ صدور).
    if (!melkinoOtpCodeMatches($row, $code, (string)($row['phone'] ?? ''))) {
        $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        $remaining = 3 - ((int)$row['attempts'] + 1);
        echo json_encode([
            'success' => false,
            'message' => $remaining > 0 ? "کد اشتباه است. $remaining تلاش دیگر باقی مانده." : 'کد اشتباه است و تلاش‌هایت تمام شد؛ دوباره درخواست کد بده.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
     * مصرف کد به‌صورت atomic:
     * شرط `is_used = 0` داخل خود UPDATE است، پس اگر دو درخواست هم‌زمان
     * با کد درست برسند فقط یکی rowCount = 1 می‌گیرد و بقیه رد می‌شوند.
     * (قبلاً SELECT و UPDATE جدا بودند و پنجرهٔ مسابقه وجود داشت.)
     */
    $claim = $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE id = ? AND is_used = 0');
    $claim->execute([$row['id']]);
    if ($claim->rowCount() !== 1) {
        echo json_encode([
            'success' => false,
            'message' => 'این کد همین الان استفاده شد؛ دوباره درخواست کد بده.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // هر کد بازماندهٔ دیگری برای همین شماره هم باطل می‌شود.
    $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE phone = ? AND is_used = 0')
        ->execute([$phone]);

    require_once __DIR__.'/messenger-login-lib.php';
    require_once __DIR__.'/unified-phone-lib.php';
    require_once __DIR__.'/auth.php';
    melkinoEnsureUserProfileColumns();
    mkUnifiedSchema($pdo);
    $pending=mkUnifiedBegin('sms',[],hash('sha256','otp|'.$row['id']),bin2hex(random_bytes(16)),time()+600);
    $pending['phone']=$phone;$pending['proof_hash']=hash('sha256','otp|'.$row['id']);
    $_SESSION['mk_phone_login']=$pending;
    echo json_encode(mkUnifiedComplete($pdo,$pending),JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(409);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => melkinoSafeError($e, 'verify-otp', 'خطا در تأیید کد.'),
    ], JSON_UNESCAPED_UNICODE);
}
