<?php
require_once __DIR__ . '/program-url.php';
// ========== کنترل‌کننده‌ی مرکزی خطا ==========
// قبلاً وقتی یک خطای واقعی PHP رخ می‌داد، چون display_errors خاموش
// بود، کاربر فقط یک صفحه‌ی خالی یا «HTTP ERROR 500» بی‌اطلاعات از
// خود هاست می‌دید و هیچ‌کس نمی‌فهمید دقیقاً کدوم خط از کدوم فایل
// خطا داده. حالا خطا همیشه در لاگ سرور ثبت می‌شود، و اگر ادمین
// وارد شده باشد متن دقیق خطا روی صفحه هم نشان داده می‌شود.
// پارامتر عمومی ?melkino_debug=1 دیگر خطا را برای مهمان نشان نمی‌دهد.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Tehran');
}

if (!function_exists('melkinoShouldShowErrorDetails')) {
    function melkinoShouldShowErrorDetails(): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent() && PHP_SAPI !== 'cli') {
            @session_start();
        }
        return !empty($_SESSION['is_admin']);
    }
}

if (!function_exists('melkinoRenderError')) {
function melkinoRenderError(string $message, string $file, int $line): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }

    if (!melkinoShouldShowErrorDetails()) {
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
            . '<title>خطا</title>' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head><body style="font-family:Tahoma,sans-serif;'
            . 'text-align:center;padding:60px 20px;">'
            . '<h2>مشکلی پیش آمد</h2>'
            . '<p>لطفاً کمی بعد دوباره تلاش کنید.</p></body></html>';
        return;
    }

    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>خطای PHP</title>' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head><body style="font-family:Tahoma,sans-serif;'
        . 'direction:rtl;padding:30px;background:#1a1a1a;color:#f5f5f5;">'
        . '<h2 style="color:#ff6b6b;">یک خطا رخ داد</h2>'
        . '<p style="font-size:16px;background:#2a2a2a;padding:15px;border-radius:8px;'
        . 'white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#aaa;">فایل: ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '<br>خط: ' . $line . '</p>'
        . '</body></html>';
}
}

set_exception_handler(function (Throwable $e) {
    error_log('[melkino] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    melkinoRenderError($e->getMessage(), $e->getFile(), $e->getLine());
});

/* =========================================================
   سخت‌سازی امنیتی: کوکی نشست + هدرهای امنیتی
   =========================================================
   - HttpOnly: اسکریپت‌های جاوااسکریپت به کوکی نشست دسترسی ندارند
   - SameSite=Lax: ارسال خودکار کوکی در درخواست‌های cross-origin (فرم‌های
     CSRF) متوقف می‌شود
   - Secure: فقط در HTTPS (تا HTTPS کامل نشود، در HTTP هم کار می‌کند)
   - X-Frame-Options / frame-ancestors: جلوگیری از کلیک‌جکینگ
   - X-Content-Type-Options: جلوگیری از sniff نوع محتوا
   - HSTS: فقط وقتی سایت از طریق HTTPS سرو می‌شود
   ========================================================= */
if (php_sapi_name() !== 'cli') {
    $melkinoIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // فقط وقتی هنوز نشست start نشده باشد (در غیر این صورت ini_set خطا می‌دهد).
    // برای صفحه‌هایی که پیش از config.php، session_start می‌زنند، کوکیِ
    // اولین درخواست همان‌جا ارسال شده و این مقادیر از درخواست بعدی
    // (و همه‌ی نشست‌های جدید) اعمال می‌شوند.
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $melkinoIsHttps ? '1' : '0');
    }

    if (!headers_sent()) {
        // مینی‌اپ بله (نسخهٔ وب) صفحه را داخل iframe باز می‌کند.
        // مستندات رسمی docs.bale.ai/miniapp: هدر X-Frame-Options نباید
        // وجود داشته باشد، وگرنه مینی‌اپ اصلاً باز نمی‌شود.
        // frame-ancestors فقط مبدأ خودمان + پیام‌رسان‌ها را مجاز می‌کند
        // (کلیک‌جکینگ سایت‌های متفرقه همچنان بسته است).
        header('X-Content-Type-Options: nosniff');
        // هدرهای تکمیلی — عمداً CSP کاملِ script-src اضافه نشده تا
        // اسکریپت‌های inline موجود پنل/سایت نشکنند.
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=(), payment=(), usb=()');
        header('X-Permitted-Cross-Domain-Policies: none');
        header("Content-Security-Policy: frame-ancestors 'self' https://*.bale.ai https://bale.ai https://web.bale.ai https://ble.ir https://*.telegram.org https://web.telegram.org https://*.eitaa.com https://web.eitaa.com https://eitaa.com");
        if ($melkinoIsHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[melkino] Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            melkinoRenderError($err['message'], $err['file'], $err['line']);
        }
    }
});

require_once __DIR__ . '/db-settings.php';

// =========================================================
// بارگذاری مقادیر محرمانه از خارج از کدِ تحتِ کنترل نسخه
// =========================================================
// هرگز رمزها/توکن‌ها را داخل فایل‌هایی که در گیت commit می‌شوند نگذار.
// اولویت خواندن:
//   1) فایل config.secrets.php در همین پوشه (در .gitignore است)
//   2) متغیرهای محیطی سرور (ENV)
//   3) مقدار پیش‌فرضِ خالی/عمومی (بدون هیچ رمز واقعی)

/*
 * بارگذاری فایل .env (اختیاری).
 * روی هاست‌هایی که امکان تعریف متغیر محیطی واقعی نیست، مقادیر محرمانه را
 * می‌توان در فایل .env کنار پروژه گذاشت. این فایل در .gitignore است.
 * نکته: مقادیر فقط وقتی ست می‌شوند که از قبل در محیط تعریف نشده باشند،
 * پس متغیر واقعی سرور همیشه اولویت دارد.
 */
if (!function_exists('melkinoLoadDotEnv')) {
    function melkinoLoadDotEnv(string $path): void
    {
        static $loaded = [];
        if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
            return;
        }
        $loaded[$path] = true;
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
                continue;
            }
            if (strlen($v) > 1 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
                $v = substr($v, 1, -1);
            }
            if (getenv($k) !== false && getenv($k) !== '') {
                continue; // متغیر واقعی سرور اولویت دارد
            }
            $_ENV[$k] = $v;
            @putenv($k . '=' . $v);
        }
    }
}
melkinoLoadDotEnv(__DIR__ . '/.env');

if (is_file(__DIR__ . '/config.secrets.php')) {
    require_once __DIR__ . '/config.secrets.php';
}

if (!function_exists('melkinoEnv')) {
    function melkinoEnv(string $key, string $default = ''): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

// ========== تنظیمات ربات تلگرام ==========
if (!defined('BOT_TOKEN')) {
    define('BOT_TOKEN', melkinoEnv('MELKINO_BOT_TOKEN', ''));
}
if (!defined('CHANNEL_ID')) {
    define('CHANNEL_ID', melkinoEnv('MELKINO_CHANNEL_ID', '@melkino_shahrood'));
}

// ========== تنظیمات ربات بله ==========
// از @botfather_bale (یا پنل توسعه‌دهندگان بله) توکن بگیر و در
// config.secrets.php یا متغیر محیطی MELKINO_BALE_BOT_TOKEN قرار بده.
if (!defined('BALE_BOT_TOKEN')) {
    define('BALE_BOT_TOKEN', melkinoEnv('MELKINO_BALE_BOT_TOKEN', ''));
}

if (!defined('EITAA_BOT_TOKEN')) {
    define('EITAA_BOT_TOKEN', melkinoEnv('MELKINO_EITAA_BOT_TOKEN', ''));
}

// ========== تنظیمات پیامک (اختیاری) ==========
// اگر سرویس پیامکی داری، این مقادیر رو با اطلاعات API واقعی جایگزین کن.
// اگر خالی بمونه، سیستم به‌جای ارسال پیامک، کد رو مستقیم روی صفحه نشون می‌ده.
if (!defined('SMS_API_KEY')) {
    define('SMS_API_KEY', melkinoEnv('MELKINO_SMS_API_KEY', ''));
}
if (!defined('SMS_API_URL')) {
    define('SMS_API_URL', melkinoEnv('MELKINO_SMS_API_URL', ''));
}
if (!defined('SMS_SENDER_LINE')) {
    define('SMS_SENDER_LINE', melkinoEnv('MELKINO_SMS_SENDER_LINE', ''));
}

// ========== تنظیمات دیتابیس ==========
if (!defined('MOCK_MODE')) define('MOCK_MODE', false);

// ========== ثابت‌های دیتابیس ==========
// نکته: هر ثابت جداگانه بررسی می‌شود تا اگر فقط یکی از آن‌ها در
// config.secrets.php تعریف شده بود، بقیه بی‌تعریف نمانند.
// امنیت: هیچ مقدار واقعیِ اتصال (هاست/نام دیتابیس/کاربر) داخل سورس نیست.
// مقادیر باید از config.secrets.php یا متغیر محیطی/.env بیایند.
if (!defined('DB_HOST')) {
    define('DB_HOST', melkinoEnv('DB_HOST', ''));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', melkinoEnv('DB_NAME', ''));
}
if (!defined('DB_USER')) {
    define('DB_USER', melkinoEnv('DB_USER', ''));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', melkinoEnv('DB_PASS', ''));
}

// ========== مسیر تنظیمات ==========
if (!defined('SETTINGS_DIR')) {
    define('SETTINGS_DIR', __DIR__ . '/settings');
}

// ========== SETTINGS_DIR رو نگه می‌داریم چون هنوز برای تم و لوگو استفاده می‌شه ==========

if (!is_dir(SETTINGS_DIR)) {
    @mkdir(SETTINGS_DIR, 0755, true);
}

// ========== محافظت از پوشه‌ی uploads ==========
// حتی اگر روزی فایلی غیرمجاز وارد uploads شد، اجرای اسکریپت (PHP و...)
// روی سرورهای Apache بلاک می‌شود. فایل .htaccess فقط یک‌بار ساخته می‌شود.
if (!function_exists('melkinoProtectUploadsDir')) {
    function melkinoProtectUploadsDir(): void
    {
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ht = $dir . '/.htaccess';
        // راند ۶۷: خودترمیمی — نسخهٔ قبلی «Options -ExecCGI» داشت که InfinityFree
        // آن را خطای .htaccess می‌داد و کل پوشهٔ uploads را ۵۰۰ می‌کرد (لوگو و
        // همهٔ تصویرهای آپلودی broken می‌شدند). فقط Options -Indexes مجاز است.
        $melkinoSafeHt = "Options -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    <FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar)$\">\n"
            . "        Order allow,deny\n"
            . "        Deny from all\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n";
        if (is_file($ht)) {
            $cur = (string)@file_get_contents($ht);
            if (strpos($cur, '-ExecCGI') !== false || strpos($cur, 'Require all denied') === false) {
                @file_put_contents($ht, $melkinoSafeHt);
            }
            return;
        }
        if (true) {
            @file_put_contents($ht,
                "Options -Indexes -ExecCGI\n"
                . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar)$\">\n"
                . "    Require all denied\n"
                . "</FilesMatch>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    <FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar)$\">\n"
                . "        Order allow,deny\n"
                . "        Deny from all\n"
                . "    </FilesMatch>\n"
                . "</IfModule>\n"
            );
        }
    }
}
melkinoProtectUploadsDir();

// ========== تنظیمات مشاور از MySQL ==========
if (!function_exists('getConsultantSettings')) {
    function getConsultantSettings(): array {
        global $pdo;
        $defaults = ['name'=>'مشاور ملکینو','phone'=>'','telegram_username'=>'','telegram_link'=>''];
        if (!$pdo) return $defaults;
        foreach ($defaults as $k => $v) {
            $x = dbSettingGet($pdo, 'consultant_default', $k, $v);
            $defaults[$k] = is_string($x) ? $x : $v;
        }
        return array_merge($defaults, ['updated_at'=>date('Y-m-d H:i:s')]);
    }
}
if (!function_exists('saveConsultantSettings')) {
    function saveConsultantSettings(array $settings): bool {
        global $pdo; if (!$pdo) return false;
        $vals = [
          'name'=>trim((string)($settings['name']??'')) ?: 'مشاور ملکینو',
          'phone'=>trim((string)($settings['phone']??'')),
          'telegram_username'=>ltrim(trim((string)($settings['telegram_username']??'')),'@'),
          'telegram_link'=>trim((string)($settings['telegram_link']??''))
        ];
        if ($vals['telegram_link']!=='' && !preg_match('#^https?://t\.me/#i',$vals['telegram_link'])) return false;
        foreach($vals as $k=>$v) if(!dbSettingSet($pdo,'consultant_default',$k,$v,'string')) return false;
        return true;
    }
}

// ========== شماره تماس مشاور ==========
if (!function_exists('getConsultantPhone')) {
    function getConsultantPhone(): string {
        $settings = getConsultantSettings();
        return trim((string)($settings['phone'] ?? ''));
    }
}

// ========== نام مشاور ==========
if (!function_exists('getConsultantName')) {
    function getConsultantName(): string {
        $settings = getConsultantSettings();
        return trim((string)($settings['name'] ?? 'مشاور ملکینو')) ?: 'مشاور ملکینو';
    }
}

// ========== لینک تلگرام مشاور ==========
if (!function_exists('getConsultantTelegramLink')) {
    function getConsultantTelegramLink(): string {
        $settings = getConsultantSettings();

        $link = trim((string)($settings['telegram_link'] ?? ''));
        if ($link !== '' && preg_match('#^https?://#i', $link)) {
            return $link;
        }

        $username = ltrim(trim((string)($settings['telegram_username'] ?? '')), '@');
        return $username !== '' ? 'https://t.me/' . $username : '';
    }
}

// ========== تعریف کلاس PDO ساختگی (فقط در حالت MOCK) ==========
if (MOCK_MODE && !class_exists('PDO')) {
    class PDO {
        public function __construct($dsn, $user, $pass) {}
        public function setAttribute($attr, $value) {}
        public function query($sql) { return new MockStatement(); }
        public function prepare($sql) { return new MockStatement(); }
    }

    class MockStatement {
        public function execute($params = []) { return true; }
        public function fetchAll($mode = null) { return []; }
        public function fetch($mode = null) { return null; }
    }
}


// ========== تنظیمات عمومی قیمت ==========
if (!function_exists('getGlobalSettings')) {
    function getGlobalSettings(): array {
        global $pdo;
        $defaults=['site_name'=>'ملکینو','city'=>'شاهرود','slogan'=>'ملکینو؛ انتخابی فراتر از یک ملک','show_prices'=>true,'hide_all_prices'=>false,'price_message_enabled'=>false,'price_message_text'=>'','enable_favorites'=>true,'enable_property_requests'=>true,'enable_notifications'=>true,'enable_property_calculator'=>true,'items_per_page'=>4,'default_theme'=>'dark','maintenance_mode'=>false,'card_layout'=>'photo-top'];
        if (!$pdo) return $defaults;
        foreach ($defaults as $k=>$v) {
            $defaults[$k]=dbSettingGet($pdo,'global',$k,$v);
        }
        // پیام به‌جای قیمت فعال باشد، همهٔ قیمت‌های عمومی پنهان می‌شوند (همان مسیر «پنهان‌کردن همه قیمت‌ها»).
        // مقدار خام hide_all_prices دست‌نخورده می‌ماند و در melkinoGlobalSettingsPayload به پنل برمی‌گردد.
        if (melkinoPriceMessage() !== '') { $defaults['hide_all_prices'] = true; }
        return $defaults;
    }
}

if (!function_exists('melkinoPriceMessage')) {
    /** متن پیام به‌جای قیمت؛ فقط وقتی فعال و غیرخالی باشد. */
    function melkinoPriceMessage(): string {
        global $pdo;
        if (!$pdo instanceof PDO || !function_exists('dbSettingGet')) { return ''; }
        try {
            if (!(bool)dbSettingGet($pdo,'global','price_message_enabled',false)) { return ''; }
            $t = trim(strip_tags((string)dbSettingGet($pdo,'global','price_message_text','')));
            return $t === '' ? '' : mb_substr($t, 0, 300, 'UTF-8');
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('melkinoEnsureAdsPriceNote')) {
    /** ستون متن جایگزین قیمت (price_note) — یک بار در هر درخواست، بدون خطا. */
    function melkinoEnsureAdsPriceNote($pdo): void {
        static $done = false;
        if ($done || !($pdo instanceof PDO)) { return; }
        $done = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM ads LIKE 'price_note'")->fetch()) {
                $pdo->exec("ALTER TABLE ads ADD COLUMN price_note VARCHAR(255) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoPublicAdPrice')) {
    /** قیمت عمومی آگهی: اگر پیام قیمت فعال است همان پیام، وگرنه قیمت معمول. */
    function melkinoPublicAdPrice(array $ad): string {
        $note = trim((string)($ad['price_note'] ?? ''));
        if ($note !== '') { return $note; }
        $m = melkinoPriceMessage();
        if ($m !== '') { return $m; }
        return function_exists('melkinoAdDisplayPrice') ? (string)melkinoAdDisplayPrice($ad) : '';
    }
}

if (!function_exists('melkinoPublicPriceRow')) {
    /** ردیف عمومی آگهی (JSON صفحه): وقتی پیام فعال است هیچ مبلغی در خروجی نمی‌ماند. */
    function melkinoPublicPriceRow(array $row): array {
        $note = trim((string)($row['price_note'] ?? ''));
        $m = $note !== '' ? $note : melkinoPriceMessage();
        if ($m === '') { return $row; }
        foreach (['price','price_sell','total_price','deposit','rent_monthly','full_rent','down_payment','loan_amount','loan_installment','card_price'] as $k) {
            if (array_key_exists($k, $row)) { $row[$k] = ''; }
        }
        $row['display_price'] = $m;
        $row['card_price'] = $m;
        $row['has_loan'] = 0;
        foreach (['details', 'property_details'] as $dk) {
            if (isset($row[$dk]) && is_array($row[$dk])) {
                $row[$dk] = array_filter($row[$dk], static fn($k) => !preg_match('/قیمت|ودیعه|اجاره|رهن|وام|پیش/u', (string)$k), ARRAY_FILTER_USE_KEY);
            }
        }
        return $row;
    }
}

if (!function_exists('melkinoHiddenPriceLabel')) {
    /** برچسب قیمت پنهان: پیام ادمین یا متن پیش‌فرض. */
    function melkinoHiddenPriceLabel(): string {
        $m = melkinoPriceMessage();
        return $m !== '' ? $m : 'برای استعلام قیمت تماس بگیرید';
    }
}

if (!function_exists('shouldHidePublicPrice')) {
    function shouldHidePublicPrice(array $ad): bool {
        $settings = getGlobalSettings();
        return !empty($settings['hide_all_prices']) ||
            empty($settings['show_prices']) ||
            !empty($ad['price_hidden']);
    }
}

// ========== تابع کمکی برای دریافت اولین تصویر ==========
if (!function_exists('getFirstImage')) {
    function getFirstImage($selectedImages = null, $pdo = null, $adId = null) {
        if (is_array($selectedImages) && count($selectedImages) > 0) {
            return $selectedImages[0];
        }

        if (is_string($selectedImages) && !empty($selectedImages)) {
            $decoded = json_decode($selectedImages, true);
            if (is_array($decoded) && count($decoded) > 0) {
                return $decoded[0];
            }
        }

        if (MOCK_MODE) {
            return 'default-house.jpg';
        }

        if ($pdo && $adId) {
            try {
                $stmt = $pdo->prepare("SELECT filename FROM images WHERE ad_id = ? ORDER BY id ASC LIMIT 1");
                $stmt->execute([$adId]);
                $img = $stmt->fetch(PDO::FETCH_ASSOC);
                return $img ? $img['filename'] : null;
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }
}

// ========== اتصال به دیتابیس (حالت واقعی) ==========
require_once __DIR__ . '/bot-settings.php';

/**
 * راند ۷۲: اتصال به دیتابیس حتماً داخل تابع انجام می‌شود تا $pdo در SCOPE GLOBAL
 * تنظیم شود — حتی اگر config.php از داخل یک تابع include شده باشد
 * (مثلاً melkino-logo.php از getSiteLogoUrl()). قبلاً در آن حالت $pdo فقط
 * متغیر محلی آن تابع می‌شد و صفحه‌هایی که «global $pdo» می‌خواندند
 * (مثل properties.php و تنظیمات نمایش کارت) بدون دیتابیس کار می‌کردند.
 */
if (!function_exists('melkinoInitDbGlobal')) {
    function melkinoInitDbGlobal(): ?PDO
    {
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if (MOCK_MODE) {
            return null;
        }
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            if (function_exists('melkinoEnsureAdsPriceNote')) { melkinoEnsureAdsPriceNote($pdo); }
            return $pdo;
        } catch (Exception $e) {
            $pdo = null;
            // قبلاً شکست اتصال بی‌صدا بود و سایت با خطای مرگبار «صفحه‌ی سفید»
            // می‌افتاد بدون اینکه هیچ لاگی باشد. حالا حداقل در لاگ سرور ثبت می‌شود.
            error_log('[melkino] اتصال دیتابیس ناموفق: ' . $e->getMessage());
            return null;
        }
    }
}

$pdo = melkinoInitDbGlobal();

/**
 * برای صفحاتی که بدون دیتابیس اصلاً قابل ارائه نیستند:
 * به‌جای fatal error / صفحه‌ی سفید، یک پیام ۵۰۳ قابل فهم نمایش می‌دهد.
 */
if (!function_exists('melkinoRequireDb')) {
    function melkinoRequireDb(): void
    {
        global $pdo;
        if ($pdo instanceof PDO) {
            return;
        }
        http_response_code(503);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>سایت موقتاً در دسترس نیست</title>'
            . '<style>body{font-family:Tahoma,sans-serif;background:#0D1413;color:#F3F4F6;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center}'
            . '.box{max-width:420px;padding:20px}h1{font-size:20px;margin-bottom:10px}'
            . 'p{color:#A8B1AE;line-height:1.9}</style>' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head>'
            . '<body><div class="box"><div style="font-size:48px;margin-bottom:12px">🛠️</div>'
            . '<h1>ملکینو موقتاً در دسترس نیست</h1>'
            . '<p>اتصال به پایگاه داده برقرار نشد. لطفاً کمی بعد دوباره تلاش کنید.</p></div></body></html>';
        exit;
    }
}

// ========== اجرای واقعی سوییچ «اجبار HTTPS» ==========
// این بخش مقدار security.force_https را از دیتابیس می‌خواند و در صورت
// فعال بودن، کاربر را به نسخه‌ی HTTPS همان آدرس ریدایرکت می‌کند.
// قبلاً این تنظیم فقط ذخیره می‌شد ولی هیچ‌جا اعمال نمی‌شد.
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoIsHttps =
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    if (!$melkinoIsHttps) {
        try {
            $melkinoForceHttps = dbSettingGet($pdo, 'security', 'force_https', false);
        } catch (Throwable $e) {
            $melkinoForceHttps = false;
        }

        if ($melkinoForceHttps) {
            $melkinoHost = $_SERVER['HTTP_HOST'] ?? '';
            $melkinoUri = $_SERVER['REQUEST_URI'] ?? '/';

            if ($melkinoHost !== '') {
                header('Location: https://' . $melkinoHost . $melkinoUri, true, 301);
                exit;
            }
        }
    }
}
// ========== اجرای واقعی «حالت تعمیرات» ==========
// این سوییچ قبلاً فقط ذخیره می‌شد و هیچ صفحه‌ای چک‌اش نمی‌کرد.
// حالا اگر فعال باشد، هر صفحه‌ی عمومی سایت یک پیام «در حال تعمیر» نشان
// می‌دهد؛ پنل ادمین و فایل‌های مربوط به ورود/API آن همیشه در دسترس
// می‌مانند تا خود ادمین بتواند دوباره این حالت را خاموش کند.
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoMaintenanceAllowlist = [
        'admin-login.php',
        'admin-panel.php',
        'admin-ads.php',
        'admin-ads-modals.php',
        'admin-requests.php',
        'admin-property-revisions.php',
        'admin-support.php',
        'save_admin_password.php',
        'save_config.php',
        'save_consultant_settings.php',
        'save_consultants.php',
        'save_global_settings.php',
        'save_security_settings.php',
        'save_theme.php',
        'support-api.php',
        'upload_onboarding_logo.php',
        'upload_onboarding_first_logo.php',
        'save_onboarding_first_page.php',
        'melkino-logo-file.php',
        'identity-sync.php',
        'page-visits.php',
        'db-settings.php',
        'publish-to-telegram.php',
        'admin-upload-image.php',
    ];

    $melkinoCurrentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    }

    try {
        $melkinoMaintenanceOn = dbSettingGet($pdo, 'global', 'maintenance_mode', false);
        $melkinoMaintenanceSince = (int) dbSettingGet($pdo, 'global', 'maintenance_started_at', 0);
    } catch (Throwable $e) {
        $melkinoMaintenanceOn = false;
        $melkinoMaintenanceSince = 0;
    }

    // =========================================================
    // وقتی حالت تعمیرات فعال می‌شود، همه‌ی نشست‌های قبلی باطل می‌شوند
    // (هم کاربران عادی و هم ادمین‌ها باید دوباره وارد شوند)
    // =========================================================
    if ($melkinoMaintenanceOn && $melkinoMaintenanceSince > 0) {
        $melkinoSessionStarted = (int)($_SESSION['melkino_session_started_at'] ?? 0);
        if ($melkinoSessionStarted <= 0 || $melkinoSessionStarted < $melkinoMaintenanceSince) {
            unset(
                $_SESSION['is_admin'],
                $_SESSION['user_role'],
                $_SESSION['admin_id'],
                $_SESSION['admin_username'],
                $_SESSION['admin_display_name'],
                $_SESSION['admin_login_at'],
                $_SESSION['reg_telegram_id'],
                $_SESSION['reg_bale_id'],
                $_SESSION['user_phone'],
                $_SESSION['user_name']
            );
            // توکن دسترسی ذخیره‌شده در مرورگر هم باطل می‌شود
            if (isset($_COOKIE['melkino_access_token'])) {
                setcookie('melkino_access_token', '', time() - 3600, '/');
                unset($_COOKIE['melkino_access_token']);
            }
        }
    }

    $melkinoIsAdminSession = !empty($_SESSION['is_admin'] ?? null);

    if (!in_array($melkinoCurrentScript, $melkinoMaintenanceAllowlist, true) && !$melkinoIsAdminSession) {
        if ($melkinoMaintenanceOn) {
            http_response_code(503);
            header('Retry-After: 3600');
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>در حال تعمیر</title>'
                . '<style>body{font-family:Tahoma,sans-serif;background:#0D1413;color:#F3F4F6;'
                . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:20px}'
                . '.box{max-width:420px}h1{font-size:22px;margin-bottom:10px}p{color:#A8B1AE;line-height:1.9}</style>' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head>'
                . '<body><div class="box"><div style="font-size:48px;margin-bottom:12px;">🛠️</div>'
                . '<h1>ملکینو موقتاً در دسترس نیست</h1>'
                . '<p>سایت در حال تعمیر است. لطفاً بعداً مراجعه نمایید.</p></div></body></html>';
            exit;
        }
    }
}

/* =========================================================
   نمایش خطاهای پنهان برای ادمین (فقط با ?debug=1)
   =========================================================
   چون نمایش خطاها در سایت خاموش است، هر خطای مرگبارِ PHP به‌جای
   پیام، یک «صفحه‌ی سفید» نشان می‌دهد. با افزودنِ ?debug=1 به آدرس
   (و فقط در صورتی که با حساب ادمین وارد شده باشی) خطاها نمایش
   داده می‌شوند تا علتِ صفحه‌ی سفید مشخص شود.
   ========================================================= */
/*
 * امنیت: نمایش خطا حالا دو قفل دارد —
 *   ۱) نشست ادمین   ۲) کلید سمت سرور MELKINO_ALLOW_DEBUG=1 (env یا ثابت)
 * یعنی صرفِ داشتن نشست ادمین + ?debug=1 در production کافی نیست و
 * جزئیات فنی روی صفحه نمی‌آید. برای عیب‌یابی، متغیر محیطی را موقتاً
 * روشن کنید و بعد خاموش کنید.
 */
if (!function_exists('melkinoDebugAllowed')) {
    function melkinoDebugAllowed(): bool
    {
        if (defined('MELKINO_ALLOW_DEBUG')) {
            return (string) MELKINO_ALLOW_DEBUG === '1';
        }
        return melkinoEnv('MELKINO_ALLOW_DEBUG', '0') === '1';
    }
}
if (php_sapi_name() !== 'cli'
    && (($_GET['debug'] ?? '') === '1')
    && !empty($_SESSION['is_admin'])
    && melkinoDebugAllowed()) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @ini_set('error_reporting', (string)E_ALL);
    @ini_set('log_errors', '1');
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array((int)$e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo '<div dir="rtl" style="direction:rtl;text-align:right;font-family:Tahoma;background:#2A0F0F;'
                . 'color:#FFD7D7;border:1px solid #7F1D1D;border-radius:10px;padding:14px;margin:14px;line-height:1.9">'
                . '<b>خطای مرگبار (علت صفحه‌ی سفید):</b><br>'
                . htmlspecialchars((string)$e['message']) . '<br><br>'
                . '<b>فایل:</b> ' . htmlspecialchars((string)$e['file'])
                . ' &nbsp; <b>خط:</b> ' . (int)$e['line']
                . '</div>';
        }
    });
}

/* =========================================================
   توکن ورودِ پشتیبان (برای مرورگرهای داخلی تلگرام/بله)
   =========================================================
   در بعضی دستگاه‌ها (مخصوصاً آیفون) مرورگرِ داخلی تلگرام کوکیِ
   نشست را نگه نمی‌دارد؛ در نتیجه کاربر با وجود ورودِ موفق،
   در هر بار باز کردنِ مینی‌اپ دوباره به صفحه‌ی ورود برمی‌گردد.
   برای حل این مشکل، هنگام ورودِ موفق یک توکنِ یک‌بارمصرفِ
   طولانی‌مدت صادر می‌شود که می‌تواند نشست را دوباره برقرار کند.
   ========================================================= */

if (!function_exists('melkinoEnsureLoginTokenTable')) {
    function melkinoEnsureLoginTokenTable(): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS login_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    token CHAR(64) NOT NULL,
                    user_id BIGINT UNSIGNED NULL,
                    telegram_id VARCHAR(191) NULL,
                    bale_id VARCHAR(191) NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    first_used_at DATETIME NULL,
                    expires_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_login_tokens_token (token),
                    KEY idx_login_tokens_user (user_id),
                    KEY idx_login_tokens_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* =========================================================
   محافظت CSRF (Cross-Site Request Forgery)
   =========================================================
   یک توکن تصادفی در هر نشست تولید می‌شود:
   - در سمت مرورگر: csrf-shim.php آن را به همه‌ی درخواست‌های
     fetch/XHR داخلی (غیر-GET) به‌صورت هدر X-CSRF-Token اضافه می‌کند.
   - در فرم‌های کلاسیک HTML: باید فیلد مخفی csrf_token هم‌راستا باشد.
   - در سمت سرور: melkinoCsrfCheck() در endpointهای تغییردهنده صدا
     می‌شود و درخواست را در برابر فرم‌های cross-origin متخلف رد می‌کند.
   ========================================================= */
if (!function_exists('melkinoCsrfToken')) {
    function melkinoCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                @session_start();
            }
        }
        if (empty($_SESSION['melkino_csrf']) || !is_string($_SESSION['melkino_csrf'])) {
            $_SESSION['melkino_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['melkino_csrf'];
    }
}

if (!function_exists('melkinoReadRequestBody')) {
    /** بدنهٔ درخواست را یک‌بار می‌خواند (php://input فقط یک‌بار قابل خواندن است) */
    function melkinoReadRequestBody(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }
        if (!empty($_POST) && is_array($_POST)) {
            $cached = $_POST;
            return $cached;
        }
        $raw = (string) @file_get_contents('php://input');
        if ($raw === '') {
            $cached = [];
            return $cached;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $cached = $json;
            return $cached;
        }
        $parsed = [];
        parse_str($raw, $parsed);
        $cached = is_array($parsed) ? $parsed : [];
        return $cached;
    }
}

if (!function_exists('melkinoRequestIsSameOrigin')) {
    function melkinoRequestIsSameOrigin(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', $host)[0];
        if ($host === '') {
            return false;
        }
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
            $v = (string) ($_SERVER[$h] ?? '');
            if ($v === '') {
                continue;
            }
            $oh = strtolower((string) (parse_url($v, PHP_URL_HOST) ?? ''));
            if ($oh !== '' && $oh === $host) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('melkinoCsrfCheck')) {
    /**
     * راستی‌آزمایی درخواست تغییردهنده. اگر توکن موجود نباشد یا نامعتبر
     * باشد، با پاسخ 419 (JSON) پایان می‌یابد.
     * راند ۸۰: توکن از هدر/POST/GET/بدنهٔ urlencoded هم خوانده می‌شود.
     * برای پنل ادمین روی آیفون، درخواست هم‌مبدا + نشست ادمین پذیرفته می‌شود.
     */
    function melkinoCsrfCheck(): void
    {
        $body = function_exists('melkinoReadRequestBody') ? melkinoReadRequestBody() : (array) $_POST;
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '') {
            $sent = (string) ($body['csrf_token'] ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
        }
        $expected = melkinoCsrfToken();
        $ok = ($sent !== '' && hash_equals($expected, $sent));
        if (!$ok && !empty($_SESSION['is_admin'])) {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $adminEp = (strpos($script, 'admin-') === 0) || in_array($script, ['admin-panel.php', 'admin-field-display.php', 'admin-card-display.php'], true);
            if ($adminEp) {
                $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
                $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
                // مرورگرهای امروزی همیشه Sec-Fetch-Site می‌فرستند؛ از آن برای
                // تشخیص قطعی درخواست بین‌سایتی استفاده می‌کنیم.
                $secFetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));

                if ($secFetchSite === 'cross-site' || $secFetchSite === 'same-site') {
                    // درخواست از دامنهٔ دیگر: هیچ استثنایی پذیرفته نیست
                    $ok = false;
                } elseif ($secFetchSite === 'same-origin') {
                    $ok = true;
                } elseif (function_exists('melkinoRequestIsSameOrigin') && melkinoRequestIsSameOrigin()) {
                    $ok = true;
                } elseif ($secFetchSite === '' && $origin === '' && $referer === '') {
                    /*
                     * مسیر سازگاری قدیمی: وب‌ویوهای قدیمی (مینی‌اپ تلگرام روی
                     * iOS) نه Sec-Fetch-* می‌فرستند و نه Origin/Referer.
                     * کوکی نشست SameSite=Lax است، پس یک فرم بین‌سایتی اصلاً
                     * کوکی ادمین را همراه نمی‌کند و به اینجا نمی‌رسد.
                     * برای قابل‌ردیابی بودن، هر استفاده لاگ می‌شود.
                     */
                    $ok = true;
                    error_log('[melkino][csrf] legacy no-header exemption used for '
                        . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
                }
            }
        }
        if (!$ok) {
            // 419 is not a standard code: this SAPI turns it into 500. Admin-session
            // requests get a proper 403; the mini-app flows keep 419 (their JS checks it).
            $csrfStatus = !empty($_SESSION['is_admin']) ? 403 : 419;
            if (!headers_sent()) {
                http_response_code($csrfStatus);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'csrf_error' => true,
                'message' => 'درخواست از نظر امنیتی نامعتبر بود (CSRF). صفحه را دوباره بارگذاری کنید.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!function_exists('melkinoIsMutatingRequest')) {
    function melkinoIsMutatingRequest(): bool
    {
        return in_array((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}

if (!function_exists('melkinoMintLoginToken')) {
    /** یک توکن ورودِ جدید می‌سازد و خودِ توکن را برمی‌گرداند (در خطا: رشته‌ی خالی) */
    // اعتبار پیش‌فرض ۶۰ روز بسیار طولانی بود: چون توکن در query-string
    // (?t=) رد و بدل می‌شود، در تاریخچه‌ی مرورگر/لاگ‌ها/Referer می‌ماند.
    // حالا پیش‌فرض ۱ روز است و مصرف آن single-use شده (پایین‌تر ببین).
    function melkinoMintLoginToken($userId, ?string $telegramId = null, ?string $baleId = null, int $days = 1): string
    {
        global $pdo;
        if (!melkinoEnsureLoginTokenTable()) {
            return '';
        }
        try {
            $token = function_exists('random_bytes') ? bin2hex(random_bytes(32)) : md5(uniqid('', true) . microtime(true) . mt_rand());

            $st = $pdo->prepare(
                "INSERT INTO login_tokens (token, user_id, telegram_id, bale_id, user_agent, expires_at)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
            );
            $st->execute([
                $token,
                $userId ? (int)$userId : null,
                $telegramId !== null && $telegramId !== '' ? (string)$telegramId : null,
                $baleId !== null && $baleId !== '' ? (string)$baleId : null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                max(1, min(365, $days)),
            ]);

            // پاک‌سازی توکن‌های منقضی‌شده
            $pdo->exec("DELETE FROM login_tokens WHERE expires_at < NOW()");
            return $token;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('melkinoConsumeLoginToken')) {
    /**
     * با استفاده از توکن، نشست کاربر را برقرار می‌کند.
     * خروجی: ['user_id','telegram_id','bale_id'] یا آرایه‌ی خالی در صورت نامعتبر بودن
     */
    function melkinoConsumeLoginToken(string $token): array
    {
        global $pdo;
        $token = trim((string)$token);
        if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return [];
        }
        if (!melkinoEnsureLoginTokenTable()) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                "SELECT id, user_id, telegram_id, bale_id FROM login_tokens
                  WHERE token = ? AND expires_at > NOW() LIMIT 1"
            );
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return [];
            }
            // single-use: با اولین مصرفِ معتبر، توکن از جدول حذف می‌شود؛
            // یعنی کپی‌ی این URL (تاریخچه/لاگ/referrer) بعد از یک بار دیگر
            // قابل استفاده نیست.
            try {
                $del = $pdo->prepare("DELETE FROM login_tokens WHERE id = ?");
                $del->execute([$row['id']]);
            } catch (Throwable $e) {
                // حذف ثانویه‌ست؛ اگر نشود، توکن با انقضا هم باطل می‌شود
            }
            return [
                'user_id'     => $row['user_id'] !== null ? (int)$row['user_id'] : null,
                'telegram_id' => $row['telegram_id'] !== null ? (string)$row['telegram_id'] : '',
                'bale_id'     => $row['bale_id'] !== null ? (string)$row['bale_id'] : '',
            ];
        } catch (Throwable $e) {
            return [];
        }
    }
}

/* =========================================================
   برقراری خودکارِ نشست از روی توکنِ ورود (?t=...)
   =========================================================
   اگر کاربر از قبل وارد شده باشد کاری انجام نمی‌شود. این بخش
   فقط زمانی فعال است که پارامتر t در آدرس وجود داشته باشد.
   ========================================================= */
if ($pdo instanceof PDO && php_sapi_name() !== 'cli') {
    $melkinoLoginToken = trim((string)($_GET['t'] ?? ''));

    if ($melkinoLoginToken !== '') {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        if (session_status() === PHP_SESSION_ACTIVE
            && empty($_SESSION['reg_telegram_id'])
            && empty($_SESSION['reg_bale_id'])
            && empty($_SESSION['reg_eitaa_id'])) {

            $melkinoTokenData = melkinoConsumeLoginToken($melkinoLoginToken);

            if (!empty($melkinoTokenData['telegram_id'])) {
                $_SESSION['reg_telegram_id'] = (string)$melkinoTokenData['telegram_id'];
            }
            if (!empty($melkinoTokenData['bale_id'])) {
                $_SESSION['reg_bale_id'] = (string)$melkinoTokenData['bale_id'];
            }
            if (!empty($melkinoTokenData['user_id']) && ($pdo instanceof PDO)) {
                try {
                    $st = $pdo->prepare("SELECT phone, eitaa_id, telegram_id, bale_id FROM users WHERE id = ? LIMIT 1");
                    $st->execute([(int)$melkinoTokenData['user_id']]);
                    $urow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                    $phone = trim((string)($urow['phone'] ?? ''));
                    if ($phone !== '') {
                        $_SESSION['user_phone'] = $phone;
                    }
                    if (empty($_SESSION['reg_eitaa_id']) && !empty($urow['eitaa_id'])) {
                        $_SESSION['reg_eitaa_id'] = (string)$urow['eitaa_id'];
                    }
                    if (empty($_SESSION['reg_telegram_id']) && !empty($urow['telegram_id'])) {
                        $_SESSION['reg_telegram_id'] = (string)$urow['telegram_id'];
                    }
                    if (empty($_SESSION['reg_bale_id']) && !empty($urow['bale_id'])) {
                        $_SESSION['reg_bale_id'] = (string)$urow['bale_id'];
                    }
                } catch (Throwable $e) {
                    try {
                        $st = $pdo->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
                        $st->execute([(int)$melkinoTokenData['user_id']]);
                        $phone = trim((string)$st->fetchColumn());
                        if ($phone !== '') {
                            $_SESSION['user_phone'] = $phone;
                        }
                    } catch (Throwable $e2) {
                    }
                }
            }
        }
    }
}

// اجرای خودکار صف انتشار هنگام بازدید (حداکثر هر ۶۰ ثانیه) — قبل از گیت ورود تا مقصدهای سررسیده بدون دکمهٔ ادمین ارسال شوند
if (php_sapi_name() !== 'cli' && !defined('MK_SKIP_LOGIN_GATE') && isset($pdo) && $pdo instanceof PDO && is_readable(__DIR__ . '/melkino-publish-autotick.php')) {
    try { require_once __DIR__ . '/melkino-publish-autotick.php'; melkinoPublishAutoTick($pdo); } catch (Throwable $e) { /* صف انتشار نباید صفحه را خراب کند */ }
}
if (php_sapi_name() !== 'cli' && !defined('MK_SKIP_LOGIN_GATE')) {
    if (!is_readable(__DIR__ . '/melkino-require-login.php')) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit('بخش ورود برنامه کامل بارگذاری نشده است؛ فایل‌های بسته را دوباره بارگذاری کنید.');
    }
    require_once __DIR__ . '/melkino-require-login.php';
}

?>
