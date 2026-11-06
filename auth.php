<?php
/**
|--------------------------------------------------------------------------
| احراز هویت یکپارچهٔ ملکینو
|--------------------------------------------------------------------------
| بعد از این‌که ورود فقط از طریق تلگرام و بله انجام می‌شود، هر صفحه‌ای
| که نیاز به هویت کاربر دارد (ثبت ملک، ثبت درخواست و...) باید از این
| فایل استفاده کند.
|
| توابع:
|   melkinoIsLoggedIn()        آیا کاربر وارد شده است؟
|   melkinoLoggedInIdentity()  هویت کامل کاربر (user_id، تلگرام، بله، تلفن)
|   melkinoRequireLogin()      اگر وارد نشده باشد: ریدایرکت به صفحه ورود
|                              (یا پاسخ ۴۰۱ برای درخواست‌های AJAX)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';

if (!function_exists('melkinoIsLoggedIn')) {
    function melkinoIsLoggedIn(): bool
    {
        $identity = melkinoCurrentIdentity();
        return !empty($identity['user_id']);
    }
}

if (!function_exists('melkinoLoggedInIdentity')) {
    function melkinoLoggedInIdentity(): array
    {
        return melkinoCurrentIdentity();
    }
}

if (!function_exists('melkinoIsApiRequest')) {
    function melkinoIsApiRequest(): bool
    {
        if (($_POST['action'] ?? '') !== '' || ($_GET['action'] ?? '') !== '') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('melkinoLoginUrl')) {
    function melkinoLoginUrl(?string $returnTo = null): string
    {
        $returnTo = $returnTo ?? (string)($_SERVER['REQUEST_URI'] ?? '');
        // فقط مسیرهای داخلی مجاز هستند (جلوگیری از Open Redirect)
        if ($returnTo !== '' && preg_match('#^/[^/\\\\]|^[a-zA-Z0-9_.-]+\.php#', $returnTo) !== 1) {
            $returnTo = '';
        }
        // راند ۶۴: فوروارد پارامترهای tgWebApp* تا دادهٔ هویت مینی‌اپ در پرتاب گم نشود.
        $fwd = function_exists('melkinoMiniAppForwardQuery') ? melkinoMiniAppForwardQuery() : '';
        if ($fwd !== '' && function_exists('melkinoMiniAppCleanHere') && $returnTo !== '') {
            $returnTo = melkinoMiniAppCleanHere($returnTo);
        }
        if ($returnTo === '') {
            return $fwd !== '' ? 'login.php?' . substr($fwd, 1) : 'login.php';
        }
        return 'login.php?redirect=' . urlencode($returnTo) . $fwd;
    }
}

if (!function_exists('melkinoRequireLogin')) {
    function melkinoRequireLogin(?string $returnTo = null): array
    {
        $identity = melkinoCurrentIdentity();

        if (!empty($identity['user_id'])) {
            return $identity;
        }

        if (melkinoIsApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'need_login' => true,
                'login_url' => melkinoLoginUrl($returnTo),
                'message' => 'برای انجام این کار باید با تلگرام یا بله وارد شوید.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $target = melkinoLoginUrl($returnTo);

        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        } else {
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">';
        }
        exit;
    }
}

/**
 * ستون‌های بیشتری از پروفایل کاربر را در صورت نبودن به جدول users اضافه
 * می‌کند تا پنل ادمین بتواند اطلاعات کامل ورود را نمایش بدهد
 * (پلتفرم، مرورگر، زبان، عکس پروفایل و...).
 *
 * این تابع فقط ستون‌هایی را اضافه می‌کند که وجود ندارند، پس برای
 * دیتابیسِ فعلی بی‌خطر است.
 */
if (!function_exists('melkinoEnsureUserProfileColumns')) {
    function melkinoEnsureUserProfileColumns(): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $wanted = [
            'last_platform' => "VARCHAR(20) NULL",
            'user_agent'    => "VARCHAR(1000) NULL",
            'photo_url'     => "VARCHAR(500) NULL",
            'language_code' => "VARCHAR(10) NULL",
            'bale_username' => "VARCHAR(191) NULL",
            'updated_at'    => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            // راند ۳۰: فیلدهای هویت تلگرام + قفل شمارهٔ تماس.
            // اطلاعات تلگرام در هر ورودِ معتبر همگام می‌شود و کاربر
            // نمی‌تواند آن‌ها را ویرایش کند؛ شمارهٔ تماس یک‌بار توسط
            // خود کاربر ثبت/تأیید و سپس قفل می‌شود (فقط ادمین می‌تواند
            // آن را حذف/باز کند).
            'telegram_first_name'     => "VARCHAR(100) NULL",
            'telegram_last_name'      => "VARCHAR(100) NULL",
            'telegram_username'       => "VARCHAR(100) NULL",
            'telegram_language_code'  => "VARCHAR(10) NULL",
            'telegram_is_premium'     => "TINYINT(1) NOT NULL DEFAULT 0",
            'telegram_photo_url'      => "VARCHAR(500) NULL",
            'telegram_auth_date'      => "DATETIME NULL",
            'last_init_data'          => "TEXT NULL",
            'phone_verified'          => "TINYINT(1) NOT NULL DEFAULT 0",
            'phone_locked'            => "TINYINT(1) NOT NULL DEFAULT 0",
            // راند ۳۱: نام/نام‌خانوادگی که خود کاربر یک‌بار (فارسی) ثبت
            // می‌کند و پس از تأیید قفل می‌شود (name_locked=1). بعد از آن
            // حتی همگام‌سازی تلگرام هم name را بازنویسی نمی‌کند.
            'first_name'              => "VARCHAR(100) NULL",
            'last_name'               => "VARCHAR(100) NULL",
            'name_locked'             => "TINYINT(1) NOT NULL DEFAULT 0",
            'eitaa_id'                => "VARCHAR(64) NULL",
            'eitaa_username'          => "VARCHAR(191) NULL",
        ];

        try {
            $existing = [];
            foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existing[strtolower((string)$col['Field'])] = true;
            }

            foreach ($wanted as $column => $definition) {
                if (!isset($existing[strtolower($column)])) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN `$column` $definition");
                }
            }
            try {
                $pdo->exec('CREATE UNIQUE INDEX uniq_users_eitaa_id ON users (eitaa_id)');
            } catch (Throwable $idxErr) {
            }
        } catch (Throwable $e) {
            // نبودِ دسترسی ALTER یا جدول نباید مانع ورود کاربر شود
        }
    }
}

/**
 * راند ۳۰ — همگام‌سازی فیلدهای هویتی تلگرام/بله در هر ورودِ معتبر.
 *
 * قوانین (طبق اسپک «مدیریت شماره تماس قفل‌شونده»):
 *   - فقط ستون‌های telegram_* و photo_url/language_code/last_init_data نوشته می‌شوند؛
 *   - ستون‌های phone / phone_verified / phone_locked هرگز در این مسیر
 *     دست نمی‌خورند (شماره فقط از طریق ثبت+تأیید در پروفایل یا
 *     حذف توسط ادمین تغییر می‌کند).
 *   - داده‌ها فقط از payload تأیید‌شده‌ی سمت سرور (HMAC) می‌آیند.
 *
 * @param int|null $userId
 * @param array    $verified      خروجی melkinoVerifyMiniAppInitData
 * @param string   $platform      'telegram' | 'bale' | 'eitaa'
 * @param string   $rawInitData   رشتهٔ خام initData (برای لاگِ آخرین ورود)
 */
if (!function_exists('melkinoSyncTelegramIdentity')) {
    function melkinoSyncTelegramIdentity($userId, array $verified, string $platform = 'telegram', string $rawInitData = ''): void
    {
        global $pdo;
        $userId = (int)$userId;
        if ($userId <= 0 || !($pdo instanceof PDO)) {
            return;
        }
        melkinoEnsureUserProfileColumns();
        if (function_exists('melkinoEnsureLoginEventsSchema')) {
            melkinoEnsureLoginEventsSchema($pdo);
        }

        try {
            $sets   = [];
            $params = [];

            $photo = substr(trim((string)($verified['photo_url'] ?? '')), 0, 500);
            $lang  = substr(trim((string)($verified['language_code'] ?? '')), 0, 10);

            if ($platform === 'telegram') {
                $sets[] = 'telegram_first_name = ?, telegram_last_name = ?, telegram_username = ?, telegram_is_premium = ?';
                $params[] = substr(trim((string)($verified['first_name'] ?? '')), 0, 100) ?: null;
                $params[] = substr(trim((string)($verified['last_name'] ?? '')), 0, 100) ?: null;
                $params[] = substr(trim((string)($verified['username'] ?? '')), 0, 100) ?: null;
                $params[] = !empty($verified['is_premium']) ? 1 : 0;
                if ($photo !== '') {
                    $sets[] = 'telegram_photo_url = ?, photo_url = ?';
                    $params[] = $photo;
                    $params[] = $photo;
                }
                if ($lang !== '') {
                    $sets[] = 'telegram_language_code = ?, language_code = ?';
                    $params[] = $lang;
                    $params[] = $lang;
                }
                $authDate = (int)($verified['auth_date'] ?? 0);
                if ($authDate > 0) {
                    $sets[] = 'telegram_auth_date = FROM_UNIXTIME(?)';
                    $params[] = $authDate;
                }
            } else {
                // بله / ایتا: username و نام قبلاً در melkinoUpsertUser ذخیره شده؛
                // اینجا فقط عکس/زبان/زمان احراز هویت در صورت موجود بودن.
                if ($platform === 'eitaa') {
                    $eu = substr(trim((string)($verified['username'] ?? '')), 0, 191);
                    if ($eu !== '') {
                        $sets[] = 'eitaa_username = ?';
                        $params[] = $eu;
                    }
                }
                if ($photo !== '') {
                    $sets[] = 'photo_url = ?';
                    $params[] = $photo;
                }
                if ($lang !== '') {
                    $sets[] = 'language_code = ?';
                    $params[] = $lang;
                }
                $authDate = (int)($verified['auth_date'] ?? 0);
                if ($authDate > 0) {
                    $sets[] = 'telegram_auth_date = FROM_UNIXTIME(?)';
                    $params[] = $authDate;
                }
            }

            if ($rawInitData !== '') {
                $sets[] = 'last_init_data = ?';
                $params[] = substr($rawInitData, 0, 4000);
            }

            if ($sets) {
                $params[] = $userId;
                $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            }
        } catch (Throwable $e) {
            // همگام‌سازی بی‌صدا انجام می‌شود تا هرگز مانع ورود نشود.
        }
    }
}

/**
 * راند ۳۰ — نرمال‌سازی و اعتبارسنجی شمارهٔ موبایل ایران.
 * ارقام فارسی/عربی → انگلیسی؛ حذف فاصله/خط‌تیره؛ +98/0098/98 → 09.
 * خروجی: 09xxxxxxxxx (۱۱ رقم) یا رشتهٔ خالی اگر معتبر نبود.
 */
if (!function_exists('melkinoNormalizeIranPhone')) {
    function melkinoNormalizeIranPhone(string $input): string
    {
        $s = trim($input);
        if ($s === '') {
            return '';
        }
        // نرمال‌سازی ارقام فارسی/عربی
        $s = strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        // حذف هر کاراکتر غیر رقم (فاصله، -، ()، + و …)
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        if ($digits === '') {
            return '';
        }
        // 0098 → 0 ، 98 (۱۲ رقم) → 0 ، 9xxxxxxxxx (۱۰ رقم) → 09…
        if (str_starts_with($digits, '0098')) {
            $digits = '0' . substr($digits, 4);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '98')) {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0' . $digits;
        }
        if (preg_match('/^09\d{9}$/', $digits)) {
            return $digits;
        }
        return '';
    }
}

/**
 * ثبت کاملِ اطلاعات هر بار ورود کاربر:
 *   - به‌روزرسانی ستون‌های پروفایل در جدول users
 *   - درج یک ردیف در login_events (اگر جدول وجود داشته باشد)
 */
if (!function_exists('melkinoRecordLoginInfo')) {
    function melkinoRecordLoginInfo(?int $userId, string $platform, array $profile = []): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        melkinoEnsureUserProfileColumns();

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

        // زمان شروع این نشست (برای ابطال خودکار هنگام فعال شدن حالت تعمیرات)
        if (PHP_SESSION_ACTIVE === session_status()) {
            $_SESSION['melkino_session_started_at'] = time();
            if ($platform !== 'eitaa') unset($_SESSION['melkino_eitaa_context']);
            if (!defined('MELKINO_UNIFIED_LOGIN')) unset($_SESSION['melkino_messenger_context'], $_SESSION['mk_login_completed']);
        }

        if ($userId !== null && $userId > 0) {
            try {
                $st = $pdo->prepare(
                    "UPDATE users
                        SET last_platform = ?,
                            user_agent = ?,
                            last_ip = ?,
                            last_login = NOW()
                      WHERE id = ?"
                );
                $st->execute([$platform !== '' ? $platform : null, $ua !== '' ? $ua : null, $ip, $userId]);
            } catch (Throwable $e) {
                // ستون‌ها ممکن است هنوز اضافه نشده باشند؛ ادامه می‌دهیم
            }

            try {
                if (function_exists('melkinoEnsureLoginEventsSchema')) {
                    melkinoEnsureLoginEventsSchema($pdo);
                }
                $have = [];
                try {
                    foreach ($pdo->query('SHOW COLUMNS FROM login_events')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                        $have[strtolower((string) $c['Field'])] = true;
                    }
                } catch (Throwable $eCols) {
                    $have = [];
                }
                $tgId = !empty($profile['telegram_id']) ? (string) $profile['telegram_id'] : '';
                $baleId = !empty($profile['bale_id']) ? (string) $profile['bale_id'] : '';
                $eitaaId = !empty($profile['eitaa_id']) ? (string) $profile['eitaa_id'] : '';
                if ($platform === 'bale' && $baleId === '' && $tgId !== '') {
                    $baleId = $tgId;
                    $tgId = '';
                }
                if ($platform === 'eitaa' && $eitaaId === '' && $tgId !== '') {
                    $eitaaId = $tgId;
                    $tgId = '';
                }
                $map = [
                    'user_id' => $userId,
                    'telegram_id' => $tgId !== '' ? $tgId : null,
                    'bale_id' => $baleId !== '' ? $baleId : null,
                    'eitaa_id' => $eitaaId !== '' ? $eitaaId : null,
                    'username' => (string) ($profile['username'] ?? ''),
                    'name' => (string) ($profile['name'] ?? ''),
                    'ip_address' => $ip,
                    'ip' => $ip,
                    'user_agent' => $ua,
                    'platform' => $platform !== '' ? $platform : null,
                ];
                $cols = [];
                $vals = [];
                foreach ($map as $c => $v) {
                    if (isset($have[$c])) {
                        $cols[] = '`' . $c . '`';
                        $vals[] = $v;
                    }
                }
                if ($cols) {
                    $sql = 'INSERT INTO login_events (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
                    $pdo->prepare($sql)->execute($vals);
                }
            } catch (Throwable $e) {
                // ثبت رویداد اختیاری است
            }
        }
    }
}
