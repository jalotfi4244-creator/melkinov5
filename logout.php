<?php
/*
|--------------------------------------------------------------------------
| خروج از حساب کاربری
|--------------------------------------------------------------------------
| قبلاً این فایل اصلاً وجود نداشت و دکمه‌ی «خروج از حساب کاربری» در
| پروفایل با خطای 404 مواجه می‌شد. این فایل هویت کاربر را از سشن سرور
| پاک می‌کند و سپس با یک اسکریپت کوتاه، اطلاعات ذخیره‌شده در مرورگر
| (localStorage/sessionStorage) را هم پاک می‌کند تا واقعاً خارج شود.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-lib.php';

// هویت، پیش از پاک‌سازی خوانده می‌شود تا چرخش توکن ممکن باشد.
$__tok = (string)($_COOKIE['melkino_access_token'] ?? '');
$__uid = (int)($_SESSION['melkino_user_id'] ?? 0);
$__ph = (string)($_SESSION['user_phone'] ?? '');

// SECFIX(L3): قبلاً فقط ۴ کلید پاک می‌شد و melkino_user_id / reg_eitaa_id /
// پرچم‌های ورود در همان نشست زنده می‌ماندند. حالا همهٔ کلیدهای هویتی کاربر
// پاک می‌شود (کلیدهای ادمین عمداً دست نمی‌خورند تا خروج کاربر، پنل را نبندد).
foreach ([
    'user_id', 'user_phone', 'user_name', 'melkino_user_id', 'melkino_login_recorded',
    'reg_telegram_id', 'reg_bale_id', 'reg_eitaa_id',
    'melkino_eitaa_context', 'melkino_eitaa_binding',
    'melkino_messenger_context', 'mk_login_completed', 'mk_login_pending', 'mk_login_probe',
    'reg_submit_tokens', 'reg_last_ad_id', 'pfx_last', 'phone_login_verified', 'mk_phone_login',
] as $__k) {
    unset($_SESSION[$__k]);
}

// SECFIX(L3): ابطال سمت-سرور توکن‌ها؛ قبلاً فقط کوکی مرورگر پاک می‌شد:
// - ردیف login_tokens (توکن یک‌بارمصرف ?t=) تا انقضا قابل استفاده می‌ماند.
// - مهم‌تر: users.access_token یک bearer بلندمدت است که به‌تنهایی اعتماد
//   می‌سازد (db_helpers: melkinoUpsertUser → trusted) و هیچ انقضای سمت-سرور
//   ندارد؛ بدون چرخش، کوکیِ دزدیده‌شده تا ۳۰ روز بعد از «خروج» معتبر می‌ماند.
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        if ($__tok !== '') {
            // HARDEN-01: ابطال با هش (مقدار خام در DB نیست).
            $st = $pdo->prepare('DELETE FROM login_tokens WHERE token = ? LIMIT 1');
            $st->execute([hash('sha256', $__tok)]);
        }
        if ($__uid > 0 || $__ph !== '') {
            // HARDEN-01: چرخش، هش را ذخیره می‌کند (کوکی پاک شده؛ خام لازم نیست).
            $newTok = bin2hex(random_bytes(32));
            $newHash = hash('sha256', $newTok);
            if ($__uid > 0) {
                $pdo->prepare('UPDATE users SET access_token = ? WHERE id = ?')->execute([$newHash, $__uid]);
            } else {
                $pdo->prepare('UPDATE users SET access_token = ? WHERE phone = ? LIMIT 1')->execute([$newHash, $__ph]);
            }
        }
    } catch (Throwable $e) {
        // خروج هرگز نباید به‌خاطر خطای دیتابیس ناقص بماند.
    }
}
unset($__tok, $__k, $__uid, $__ph);

// شناسه‌ی نشست از نو ساخته می‌شود تا کوکی‌ی قدیمی (که مثلاً در
// تاریخچه/لاگ کپی شده) دیگر هویتِ قبل از خروج را حمل نکند.
session_regenerate_id(true);

// کوکیِ دسترسیِ طولانی‌مدتِ قبلی هم (که با secure=false set می‌شد)
// با پیکربندی ایمن‌تر حذف می‌شود
if (function_exists('melkinoClearAccessTokenCookie')) { melkinoClearAccessTokenCookie(); }
// SECFIX(L2): فرم قدیمی setcookie حداکثر ۷ آرگومان می‌گیرد و این خط با ۸ آرگومان
// خطای ۵۰۰ می‌داد؛ فرم آرایه‌ای (PHP 7.3+) جایگزین شد.
else { setcookie('melkino_access_token', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']); }

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>خروج از حساب...</title>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body style="font-family:Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0D1413;color:#F3F4F6;">
<p>در حال خروج...</p>
<script>
    try {
        localStorage.removeItem('melkino_user_phone');
        localStorage.removeItem('melkino_telegram_id');
        localStorage.removeItem('melkino_bale_id');
        sessionStorage.removeItem('reg_telegram_id');
        sessionStorage.removeItem('reg_phone');
        sessionStorage.removeItem('melkino_identified');
        localStorage.removeItem('melkino_login_token');
        localStorage.removeItem('melkino_user_id');
        sessionStorage.removeItem('reg_bale_id');
        sessionStorage.removeItem('reg_eitaa_id');
        sessionStorage.removeItem('__eitaa__initParams');
        sessionStorage.removeItem('__bale__initParams');
        sessionStorage.removeItem('__telegram__initParams');
        sessionStorage.removeItem('melkino_tg_hash');
        sessionStorage.removeItem('melkino_profile_synced');
        sessionStorage.removeItem('melkino_identified_ok');
    } catch (e) {}
    window.location.href = 'profile.php';
</script>
</body>
</html>
