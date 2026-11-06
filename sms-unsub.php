<?php
/*
|--------------------------------------------------------------------------
| sms-unsub.php — لغو عضویت دریافت پیامک (مقررات ملی پیامک / مصوبه ۲۷۰)
|--------------------------------------------------------------------------
| لینک یک‌کلیکی انتهای پیامک‌های اطلاع‌رسانی ملکینو:
|   sms-unsub.php?phone=09...&scope=alerts&token=XXXX
| توکن = substr(sha1('mk-sms-unsub|phone|cron_token(یا پیش‌فرض)'), 0, 12)
| بعد از لغو، شماره در sms_optouts ثبت می‌شود و هرگز پیامک اطلاع‌رسانی/
| تبلیغاتی دوباره نمی‌گیرد (OTP و تراکنشی مستثنی‌اند — تراکنشی تحت مقررات
| انبوه نیست).
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
require_once __DIR__ . '/config.php';
$pdo = melkinoInitDbGlobal();
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/db-settings.php';
require_once __DIR__ . '/sms-program.php';

function smsUnsubToken(string $phone, string $secret): string
{
    return substr(sha1('mk-sms-unsub|' . $phone . '|' . $secret), 0, 12);
}

$phone = smsProgramNormPhone((string)($_GET['phone'] ?? $_POST['phone'] ?? ''));
$scope = trim((string)($_GET['scope'] ?? $_POST['scope'] ?? 'alerts'));
if (!in_array($scope, ['alerts', 'promo'], true)) {
    $scope = 'alerts';
}
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');

$cfg = smsProgramSettings($pdo);
$secret = (string)($cfg['cron_token'] ?? '');
// No predictable fallback: mint + persist a random secret on first use
// (previously hardcoded 'melkino' let anyone forge unsub links for any phone).
if ($secret === '') {
    try {
        $secret = bin2hex(random_bytes(16));
        smsProgramSaveSettings($pdo, ['cron_token' => $secret]);
    } catch (Throwable $e) {
        $secret = '';
    }
}
$expected = $secret === '' ? "\0" : smsUnsubToken($phone, $secret);
$valid = $phone !== '' && preg_match('/^09\d{9}$/', $phone) && $token !== '' && hash_equals($expected, $token);
$done = false;

if ($valid) {
    try {
        smsProgramEnsureSchema($pdo);
        $st = $pdo->prepare('INSERT IGNORE INTO sms_optouts (phone, scope, source) VALUES (?,?,?)');
        $st->execute([$phone, $scope, 'link']);
        $done = true;
    } catch (Throwable $e) {
        $done = false;
    }
}

$scopeText = $scope === 'promo' ? 'تبلیغاتی' : 'اطلاع‌رسانی';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>لغو دریافت پیامک | ملکینو</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: Vazirmatn, Tahoma, sans-serif;
        min-height: 100dvh; display: flex; align-items: center; justify-content: center;
        background: linear-gradient(160deg, #073737, #052727 70%, #031C1C); padding: 16px;
    }
    .box {
        background: #fff; border-radius: 20px; padding: 36px 28px; max-width: 430px; width: 100%;
        text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.35);
    }
    .badge {
        width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 18px;
        display: flex; align-items: center; justify-content: center; font-size: 34px;
        background: <?= $done ? 'rgba(14,124,110,.12)' : 'rgba(220,38,38,.12)' ?>;
    }
    h1 { font-size: 19px; font-weight: 900; color: #111827; margin-bottom: 10px; }
    p { font-size: 13.5px; line-height: 2; color: #4b5563; }
    .phone { font-weight: 900; color: #0E7C6E; direction: ltr; display: inline-block; }
    a.btn {
        display: inline-block; margin-top: 20px; padding: 11px 26px; border-radius: 12px;
        background: #0E7C6E; color: #fff; font-size: 13.5px; font-weight: 800; text-decoration: none;
    }
    .count { color: #9ca3af; font-size: 11.5px; margin-top: 16px; }
</style>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body>
    <div class="box">
        <div class="badge"><?= $done ? '✅' : '⛔' ?></div>
        <?php if ($done): ?>
            <h1>لغو عضویت انجام شد</h1>
            <p>از این پس شمارهٔ <span class="phone"><?= htmlspecialchars($phone) ?></span> هیچ پیامک <?= $scopeText ?>ی از ملکینو دریافت نمی‌کند.</p>
        <?php else: ?>
            <h1>لینک معتبر نیست</h1>
            <p>این لینک لغو عضویت ناقص یا منقضی شده است. لطفاً با پشتیبانی ملکینو تماس بگیرید.</p>
        <?php endif; ?>
        <a class="btn" href="home.php">رفتن به ملکینو</a>
        <div class="count">هدایت به صفحهٔ اصلی تا <span id="c">۳</span> ثانیه دیگر…</div>
    </div>
    <script>
        var n = 3;
        setInterval(function () {
            n = n > 0 ? n - 1 : 0;
            var el = document.getElementById('c');
            if (el) el.textContent = ['۰', '۱', '۲', '۳'][n];
        }, 1000);
        setTimeout(function () { window.location.href = 'home.php'; }, 3000);
    </script>
</body>
</html>
