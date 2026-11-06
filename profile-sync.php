<?php
/*
|--------------------------------------------------------------------------
| همگام‌سازی پروفایل کاربر (profile-sync)
|--------------------------------------------------------------------------
| هدف: به‌محض اینکه کاربر وارد مینی‌اپ می‌شود، اطلاعات و پروفایل او در
| جدول users ساخته یا به‌روزرسانی شود — حتی اگر هرگز آگهی یا درخواستی
| ثبت نکند و فقط سایت را ببیند.
|
| مسیرهای ورود اطلاعات:
|   1) auth-telegram.php / auth-bale.php  → هنگام ورود (احراز هویت)
|   2) این فایل                          → در هر بازدید، برای به‌روزرسانی
|                                           نام، نام کاربری، عکس، زبان،
|                                           پلتفرم و زمان آخرین بازدید
|
| امنیت: هویت فقط از initDataـی که با امضای رمزنگاری‌شده‌ی خودِ تلگرام/بله
| تأیید شده باشد پذیرفته می‌شود؛ ارسالِ دستیِ telegram_id پذیرفته نیست.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

/* ------------------------------------------------------------------
   راند ۳۱ — تکمیل اطلاعات حساب: نام + نام خانوادگی (فارسی) + شماره
   ------------------------------------------------------------------
   قوانین:
   - فقط کاربرِ واردشده (سشن سروری) + CSRF + پرچم confirm.
   - نام و نام خانوادگی باید فارسی باشد (بدون رقم/لاتین).
   - شماره نرمال‌سازی و اعتبارسنجی می‌شود و باید «سراسری یکتا» باشد:
     اگر همان شماره روی حساب (اکانت تلگرام) دیگری ثبت شده باشد، رد
     می‌شود — نمی‌شود موبایل یک نفر را روی دو اکانت ذخیره کرد.
   - پس از ذخیره: name_locked=1 و phone_locked=1؛ شمارهٔ دستی phone_verified=0 دارد؛
     هیچ مسیر کاربری دیگری نمی‌تواند آن‌ها را تغییر دهد (فقط ادمین).
------------------------------------------------------------------ */
if (($body['action'] ?? '') === 'set_profile') {

    $identity = melkinoCurrentIdentity();
    $userId   = (int)($identity['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    melkinoCsrfCheck();

    if (empty($body['confirm'])) {
        echo json_encode(['success' => false, 'message' => 'اطلاعات باید در مرحلهٔ تأیید، نهایی شود.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    melkinoEnsureUserProfileColumns();

    $cur = [];
    try {
        $st = $pdo->prepare('SELECT name, phone, phone_locked, name_locked FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $cur = [];
    }
    $curPhone     = trim((string)($cur['phone'] ?? ''));
    $phoneLocked  = !empty($cur['phone_locked']);
    $nameLocked   = !empty($cur['name_locked']);

    $firstName = trim((string)($body['first_name'] ?? ''));
    $lastName  = trim((string)($body['last_name'] ?? ''));
    $inPhone   = melkinoNormalizeIranPhone(trim((string)($body['phone'] ?? '')));

    $sets   = [];
    $params = [];

    /* ---- نام ---- */
    if (!$nameLocked) {
        if ($firstName === '' || $lastName === '') {
            echo json_encode(['success' => false, 'message' => 'نام و نام خانوادگی را کامل وارد کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // فقط حروف فارسی/عربی، نیم‌فاصله و فاصله — بدون رقم و لاتین
        $faName = '/^[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\x{200C}\x{200D} ]{2,60}$/u';
        if (!preg_match($faName, $firstName) || !preg_match($faName, $lastName)
            || trim(str_replace(["\u{200C}", "\u{200D}", ' '], '', $firstName)) === ''
            || trim(str_replace(["\u{200C}", "\u{200D}", ' '], '', $lastName)) === '') {
            echo json_encode(['success' => false, 'message' => 'نام و نام خانوادگی باید به فارسی باشد (بدون عدد و حروف انگلیسی).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sets[] = 'first_name = ?, last_name = ?, name = ?, name_locked = 1';
        $params[] = mb_substr($firstName, 0, 100);
        $params[] = mb_substr($lastName, 0, 100);
        $params[] = mb_substr($firstName . ' ' . $lastName, 0, 200);
    }

    /* ---- شماره ---- */
    if ($curPhone !== '' && $phoneLocked) {
        if ($inPhone !== '' && $inPhone !== $curPhone) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'شمارهٔ تماس شما ثبت و قفل شده است. تغییر یا حذف آن فقط توسط ادمین امکان‌پذیر است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        if ($inPhone === '') {
            echo json_encode(['success' => false, 'message' => 'شمارهٔ موبایل معتبر نیست. فرمت صحیح: ۰۹۱۲۳۴۵۶۷۸۹'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $claimed = function_exists('melkinoClaimPhoneOnUser')
            ? melkinoClaimPhoneOnUser($userId, $inPhone)
            : ['ok' => false, 'user_id' => $userId, 'merged' => false, 'message' => 'امکان اتصال شماره نیست.'];
        if (empty($claimed['ok'])) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => (string) ($claimed['message'] ?? 'این شماره قبلاً روی حساب دیگری ثبت شده است.'),
                'code' => (string)($claimed['code']??''),
                'link_url' => (string)($claimed['link_url']??''),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $userId = (int) ($claimed['user_id'] ?? $userId);
    }

    if (!$sets) {
        if ($inPhone !== '') {
            $_SESSION['user_phone'] = $inPhone;
            echo json_encode([
                'success'     => true,
                'message'     => 'اطلاعات حساب ثبت شد.',
                'name'        => (string) ($cur['name'] ?? ''),
                'phone'       => $inPhone,
                'name_locked' => true,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'اطلاعات حساب شما قبلاً تکمیل و قفل شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $params[] = $userId;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?')->execute($params);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$nameLocked && $firstName !== '') {
        $_SESSION['user_name'] = $firstName . ' ' . $lastName;
    }
    if ($inPhone !== '' && !($curPhone !== '' && $phoneLocked)) {
        $_SESSION['user_phone'] = $inPhone;
    }

    echo json_encode([
        'success'     => true,
        'message'     => 'اطلاعات حساب ثبت شد.',
        'name'        => (!$nameLocked && $firstName !== '') ? ($firstName . ' ' . $lastName) : (string)($cur['name'] ?? ''),
        'phone'       => ($curPhone !== '' && $phoneLocked) ? $curPhone : $inPhone,
        'name_locked' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------
   راند ۳۰ — ثبت اولیهٔ شمارهٔ تماس (با تأیید نهایی کاربر)
   ------------------------------------------------------------------
   قوانین:
   - فقط کاربرِ واردشده (سشن سروری)؛ هیچ شناسه‌ای از بدنهٔ درخواست
     برای هویت پذیرفته نمی‌شود.
   - شماره ابتدا نرمال‌سازی می‌شود (۰۹xxxxxxxxx) و فرمت ایران را
     باید داشته باشد.
   - اگر کاربر از قبل شمارهٔ ثبت‌شده دارد → تغییر فقط توسط ادمین.
   - با ثبت دستی: phone_verified=0 و phone_locked=1 (قفل دائم تا حذف ادمین).
   - پرچم confirm باید true باشد (مرحلهٔ «تأیید و ثبت» در فرانت‌اند).
------------------------------------------------------------------ */
if (($body['action'] ?? '') === 'set_phone') {

    $identity = melkinoCurrentIdentity();
    $userId   = (int)($identity['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    melkinoCsrfCheck();

    if (empty($body['confirm'])) {
        echo json_encode([
            'success' => false,
            'message' => 'شماره باید در مرحلهٔ تأیید، نهایی شود.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $phone = melkinoNormalizeIranPhone(trim((string)($body['phone'] ?? '')));
    if ($phone === '') {
        echo json_encode([
            'success' => false,
            'message' => 'شمارهٔ موبایل معتبر نیست. فرمت صحیح: ۰۹۱۲۳۴۵۶۷۸۹',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    melkinoEnsureUserProfileColumns();

    try {
        $st = $pdo->prepare('SELECT phone, phone_locked FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    $curPhone = trim((string)($row['phone'] ?? ''));
    if ($curPhone !== '') {
        if ($curPhone === $phone && !empty($row['phone_locked'])) {
            echo json_encode(['success' => true, 'message' => 'شمارهٔ شما قبلاً ثبت و تأیید شده است.', 'phone' => $phone], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'شمارهٔ تماس شما ثبت و قفل شده است. تغییر یا حذف آن فقط توسط ادمین امکان‌پذیر است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $claimed = function_exists('melkinoClaimPhoneOnUser')
        ? melkinoClaimPhoneOnUser($userId, $phone)
        : ['ok' => false, 'user_id' => $userId, 'message' => 'امکان اتصال شماره نیست.'];
    if (empty($claimed['ok'])) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => (string) ($claimed['message'] ?? 'این شماره قبلاً روی حساب دیگری ثبت شده است.'),
                'code' => (string)($claimed['code']??''),
                'link_url' => (string)($claimed['link_url']??''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userId = (int) ($claimed['user_id'] ?? $userId);

    $_SESSION['user_phone'] = $phone;

    echo json_encode([
        'success'        => true,
        'message'        => 'شمارهٔ تماس ثبت شد.',
        'phone'          => $phone,
        'phone_verified' => false,
        'phone_locked'   => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------
   به‌روزرسانی اطلاعات تماس (نام و شماره) توسط خودِ کاربر
   ------------------------------------------------------------------
   وقتی کاربر در فرم ثبت ملک یا درخواست، نام یا شماره‌اش را وارد یا
   اصلاح می‌کند، اینجا در پروفایلش ذخیره می‌شود تا از این پس به‌طور
   خودکار پر شود. این بخش از مسیرِ ورود (که نیازمند initData است)
   جداست و فقط برای کاربرِ واردشده کار می‌کند.

   امنیت: هویت فقط از سشنِ سروری خوانده می‌شود و هیچ شناسه‌ای از سمت
   مرورگر پذیرفته نمی‌شود؛ بنابراین امکان دستکشیِ پروفایلِ دیگران نیست.
------------------------------------------------------------------ */
if (($body['action'] ?? '') === 'update_contact') {

    if (!function_exists('melkinoCurrentIdentity')) {
        echo json_encode(['success' => false, 'message' => 'امکان به‌روزرسانی فراهم نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = melkinoCurrentIdentity();
    $userId   = (int)($identity['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // این اکشن هویت را از سشن می‌خواند (نه initData)، پس نیاز به CSRF دارد
    melkinoCsrfCheck();

    $newName  = trim((string)($body['name'] ?? ''));
    $newPhone = trim((string)($body['phone'] ?? ''));

    if ($newPhone !== '' && function_exists('melkinoNormalizePhone')) {
        $newPhone = melkinoNormalizePhone($newPhone);
    }

    if ($newPhone !== '' && preg_match('/^09\d{9}$/', $newPhone) !== 1) {
        echo json_encode([
            'success' => false,
            'message' => 'شماره موبایل معتبر نیست (مثال: ۰۹۱۲۳۴۵۶۷۸۹).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // راند ۳۰: سیاست قفل شماره و نامِ پیام‌رسانی — enforcement کامل سمت سرور.
    // حتی درخواست دستی HTTP هم نمی‌تواند شمارهٔ ثبت‌شده را تغییر دهد.
    $meRow = [];
    try {
        $me = $pdo->prepare('SELECT name, phone, phone_verified, phone_locked, telegram_id, bale_id, name_locked FROM users WHERE id = ? LIMIT 1');
        $me->execute([$userId]);
        $meRow = $me->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $meRow = [];
    }
    $curPhone = trim((string)($meRow['phone'] ?? ''));
    $isTgUser = trim((string)($meRow['telegram_id'] ?? '')) !== ''
        || trim((string)($meRow['bale_id'] ?? '')) !== '';

    if ($newPhone !== '' && $curPhone !== '') {
        if ($newPhone === $curPhone) {
            $newPhone = ''; // همان شمارهٔ فعلی؛ کاری لازم نیست
        } else {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'شمارهٔ تماس شما ثبت و قفل شده است. تغییر یا حذف آن فقط توسط ادمین امکان‌پذیر است.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // راند ۳۱: نام تأییدشدهٔ کاربر (name_locked) دیگر قابل ویرایش نیست —
    // حتی با درخواست دستی HTTP.
    if ($newName !== '' && !empty($meRow['name_locked']) && $newName !== trim((string)($meRow['name'] ?? ''))) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'نام و نام خانوادگی شما تأیید و قفل شده است. تغییر آن فقط توسط ادمین امکان‌پذیر است.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // نامِ کاربرِ تلگرام/بله از پیام‌رسان می‌آید و قابل ویرایش نیست
    if ($newName !== '' && $isTgUser && $newName !== trim((string)($meRow['name'] ?? ''))) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'نام شما از حساب پیام‌رسان خوانده می‌شود و قابل ویرایش نیست.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $sets = [];
        $args = [];

        if ($newName !== '') {
            $sets[] = 'name = ?';
            $args[] = $newName;
            $_SESSION['user_name'] = $newName;
        }

        if ($newPhone !== '') {
            $claimed = function_exists('melkinoClaimPhoneOnUser')
                ? melkinoClaimPhoneOnUser($userId, $newPhone)
                : ['ok' => false, 'user_id' => $userId, 'message' => 'امکان اتصال شماره نیست.'];
            if (empty($claimed['ok'])) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => (string) ($claimed['message'] ?? 'این شماره قبلاً روی حساب دیگری ثبت شده است.'),
                'code' => (string)($claimed['code']??''),
                'link_url' => (string)($claimed['link_url']??''),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $userId = (int) ($claimed['user_id'] ?? $userId);
            $_SESSION['user_phone'] = $newPhone;
        }

        if ($sets) {
            $sets[] = 'updated_at = NOW()';
            $args[] = $userId;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        }

        echo json_encode([
            'success' => true,
            'message' => 'اطلاعات تماس ذخیره شد.',
            'name'    => $newName,
            'phone'   => $newPhone,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی.'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// Profile synchronisation is not an alternate login or account-binding endpoint.
require_once __DIR__.'/messenger-login-lib.php';
$me=melkinoMessengerCurrentUser($pdo);
if(!$me){http_response_code(401);echo json_encode(['success'=>false,'need_login'=>true,'message'=>'ابتدا شماره را در صفحهٔ ورود تأیید کنید.'],JSON_UNESCAPED_UNICODE);exit;}
melkinoCsrfCheck();
echo json_encode(['success'=>true,'status'=>'session','user_id'=>(int)$me['id'],'name'=>$me['name'],'phone'=>$me['phone'],'platform'=>melkinoMessengerContext()],JSON_UNESCAPED_UNICODE);
