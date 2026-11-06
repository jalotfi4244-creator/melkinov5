<?php
/*
|--------------------------------------------------------------------------
| saved-search-save.php — ذخیرهٔ جستجوی کاربر در «همه آگهی‌ها»
|--------------------------------------------------------------------------
| POST ساده از دکمهٔ «ذخیرهٔ این جستجو» (properties.php):
|   tx, property_type, district, min_price, max_price, min_area, max_area, rooms
| فقط کاربر واردشده (گیت ورود مثل my-request-matches.php).
| شمارهٔ موبایل از حساب کاربر (users.phone) گرفته می‌شود — رضایتِ دریافت
| پیامکِ «ملک جدید مطابق جستجو» با همین ذخیره ثبت می‌شود (opt-in مقررات ۲۷۰)،
| و لغو آن همیشه از صفحهٔ «جستجوهای ذخیره‌شده» یا لینک لغوِ انتهای پیامک.
| خروجی: صفحهٔ موفقیت ساده + ریدایرکت ۳ ثانیه‌ای به properties.php
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
$_mkPage = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
$_mkAllow = ['login.php', 'logout.php', 'auth.php', 'auth-telegram.php', 'auth-bale.php', 'auth-eitaa.php', 'request-otp.php', 'verify-otp.php', 'admin-login.php', 'admin-logout.php', 'telegram.php', 'bale.php', 'eitaa.php', 'telegram-relay.php', 'identity-sync.php', 'bale-ok.php', 'r.php'];
if (
    $_mkPage !== ''
    && !in_array($_mkPage, $_mkAllow, true)
    && strncmp($_mkPage, 'admin-', 6) !== 0
    && empty($_SESSION['user_id'])
    && empty($_SESSION['reg_telegram_id'])
    && empty($_SESSION['reg_bale_id'])
    && empty($_SESSION['reg_eitaa_id'])
    && empty($_SESSION['user_phone'])
    && empty($_SESSION['is_admin'])
) {
    $here = (string) ($_SERVER['REQUEST_URI'] ?? $_mkPage);
    $here = preg_replace('#^/+#', '', $here) ?? $_mkPage;
    if ($here === '' || strpos($here, 'login.php') === 0) {
        $here = 'home.php';
    }
    if (!headers_sent()) {
        header('Location: login.php?redirect=' . rawurlencode($here), true, 302);
    }
    exit;
}
unset($_mkPage, $_mkAllow);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
$pdo = melkinoInitDbGlobal();
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/db-settings.php';
require_once __DIR__ . '/sms-program.php';

smsProgramEnsureSchema($pdo);

function ssvClean(string $v): string
{
    return trim(mb_substr($v, 0, 120));
}

function ssvSuccess(string $title, string $msg, string $backUrl): void
{
    $back = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | ملکینو</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Vazirmatn, Tahoma, sans-serif; min-height: 100dvh; display: flex; align-items: center; justify-content: center; background: linear-gradient(160deg, #073737, #052727 70%, #031C1C); padding: 16px; }
    .box { background: #fff; border-radius: 20px; padding: 36px 28px; max-width: 430px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.35); }
    .badge { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; font-size: 34px; background: rgba(14,124,110,.12); }
    h1 { font-size: 19px; font-weight: 900; color: #111827; margin-bottom: 10px; }
    p { font-size: 13.5px; line-height: 2; color: #4b5563; }
    a.btn { display: inline-block; margin-top: 20px; padding: 11px 26px; border-radius: 12px; background: #0E7C6E; color: #fff; font-size: 13.5px; font-weight: 800; text-decoration: none; }
    .count { color: #9ca3af; font-size: 11.5px; margin-top: 16px; }
</style>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body>
<div class="box">
    <div class="badge">✅</div>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= $msg ?></p>
    <a class="btn" href="<?= $back ?>">ادامه</a>
    <div class="count">هدایت خودکار تا <span id="c">۳</span> ثانیه دیگر…</div>
</div>
<script>
    var n = 3;
    setInterval(function () { n = n > 0 ? n - 1 : 0; var el = document.getElementById('c'); if (el) el.textContent = ['۰','۱','۲','۳'][n]; }, 1000);
    setTimeout(function () { window.location.href = <?= json_encode((string)$backUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>; }, 3000);
</script>
</body>
</html><?php
    exit;
}

// V2 (search.js) posts JSON {query:"tx=...&min_price=..."} with X-CSRF-Token;
// legacy callers post form fields. Accept both, always verify CSRF on POST.
$ssvIsJson = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false;
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && function_exists('melkinoCsrfCheck')) {
    melkinoCsrfCheck();
}
function ssvJson(bool $ok, string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
$ssvIn = $_POST;
if ($ssvIsJson) {
    $ssvBody = json_decode((string)@file_get_contents('php://input'), true);
    if (is_array($ssvBody) && isset($ssvBody['query'])) {
        $ssvQ = [];
        parse_str((string)$ssvBody['query'], $ssvQ);
        if (is_array($ssvQ)) {
            foreach (['tx', 'title', 'property_type', 'district', 'min_price', 'max_price', 'min_area', 'max_area', 'rooms'] as $ssvK) {
                if (isset($ssvQ[$ssvK]) && !isset($ssvIn[$ssvK])) {
                    $ssvIn[$ssvK] = $ssvQ[$ssvK];
                }
            }
        }
    }
}

$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// شمارهٔ موبایل کاربر — شرط ارسال پیامک اطلاع‌رسانی
$phone = '';
if ($userId > 0) {
    try {
        $st = $pdo->prepare('SELECT phone FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $phone = smsProgramNormPhone((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $phone = '';
    }
}
if ($phone === '' && !empty($_SESSION['user_phone'])) {
    $phone = smsProgramNormPhone((string)$_SESSION['user_phone']);
}
if ($userId === 0 && $phone !== '') {
    try {
        $st = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $st->execute([$phone]);
        $userId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
    }
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    if ($ssvIsJson) {
        ssvJson(false, 'حساب شما شمارهٔ موبایل معتبر ندارد.');
    }
    ssvSuccess(
        'شمارهٔ موبایل در حساب شما نیست',
        'برای اطلاع‌رسانی پیامکیِ ملک جدید، حساب شما باید شمارهٔ موبایل معتبر داشته باشد.<br>با شماره وارد شوید یا شمارهٔ خود را در پروفایل تکمیل کنید.',
        'properties.php'
    );
}

$tx = ssvClean((string)($ssvIn['tx'] ?? ''));
if ($tx !== '' && !in_array($tx, ['فروش', 'پیش فروش', 'اجاره'], true)) {
    $tx = '';
}
$row = [
    'user_id'       => $userId > 0 ? $userId : null,
    'phone'         => $phone,
    'title'         => ssvClean((string)($ssvIn['title'] ?? '')) ?: 'جستجوی ذخیره‌شده',
    'tx'            => $tx,
    'property_type' => ssvClean((string)($ssvIn['property_type'] ?? '')),
    'district'      => ssvClean((string)($ssvIn['district'] ?? '')),
    'min_price'     => ssvClean((string)($ssvIn['min_price'] ?? '')),
    'max_price'     => ssvClean((string)($ssvIn['max_price'] ?? '')),
    'min_area'      => ssvClean((string)($ssvIn['min_area'] ?? '')),
    'max_area'      => ssvClean((string)($ssvIn['max_area'] ?? '')),
    'rooms'         => ssvClean((string)($ssvIn['rooms'] ?? '')),
];

// جلوگیری از ثبت تکراری دقیقاً مشابه
$st = $pdo->prepare('SELECT COUNT(*) FROM saved_searches WHERE phone = ? AND tx <=> ? AND property_type <=> ? AND district <=> ? AND min_price <=> ? AND max_price <=> ? AND min_area <=> ? AND max_area <=> ? AND rooms <=> ?');
$st->execute([$phone, $row['tx'], $row['property_type'], $row['district'], $row['min_price'], $row['max_price'], $row['min_area'], $row['max_area'], $row['rooms']]);
if ((int)$st->fetchColumn() > 0) {
    if ($ssvIsJson) {
        ssvJson(true, 'این جستجو از قبل ذخیره شده است.');
    }
    ssvSuccess(
        'این جستجو از قبل ذخیره شده است',
        'همین جستجو قبلاً برای شما ثبت شده و با انتشار ملک جدیدِ منطبق، پیامک می‌گیرید.',
        'saved-searches.php'
    );
}

$st = $pdo->prepare('INSERT INTO saved_searches (user_id, phone, title, tx, property_type, district, min_price, max_price, min_area, max_area, rooms, notify) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)');
$st->execute(array_values($row));

if ($ssvIsJson) {
    ssvJson(true, 'این جستجو ذخیره شد.');
}
ssvSuccess(
    'جستجوی شما ذخیره شد 🎉',
    'با ثبت ملک جدیدِ منطبق با این جستجو، پیامک اطلاع‌رسانی برایتان ارسال می‌شود.<br>مدیریت جستجوها و لغو دریافت: صفحهٔ «جستجوهای ذخیره‌شده».',
    'saved-searches.php'
);
