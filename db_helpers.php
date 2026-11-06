<?php
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
require_once __DIR__ . '/config.php';
if (php_sapi_name() !== 'cli') {
    if (!is_readable(__DIR__ . '/melkino-require-login.php')) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit('بخش ورود برنامه کامل بارگذاری نشده است؛ فایل‌های بسته را دوباره بارگذاری کنید.');
    }
    require_once __DIR__ . '/melkino-require-login.php';
}
require_once __DIR__ . '/notification-events.php'; // رجیستری رویدادهای اعلان (راند ۲۰)

/**
 * تابع مشترک تأیید initData برای Mini App — طبق مستندات رسمی، هم
 * تلگرام و هم بله دقیقاً از همین الگوریتم (HMAC-SHA256 با کلید
 * مشتق‌شده از توکن بات + رشته‌ی ثابت «WebAppData») استفاده می‌کنند؛
 * فقط توکن بات‌شون فرق داره.
 *
 * قبلاً سایت فقط به مقداری که خودِ جاوااسکریپت کلاینت می‌فرستاد
 * اعتماد می‌کرد؛ یعنی هرکسی می‌تونست مستقیم به endpoint سایت درخواست
 * بفرسته و ادعا کنه فلان آی‌دی رو داره. این تابع initData خام (که
 * فقط کلاینت واقعیِ پیام‌رسان می‌تونه درست امضاش کنه) رو با امضای
 * رمزنگاری‌شده بررسی می‌کنه؛ اگر امضا نامعتبر باشه یا قدیمی باشه
 * (بیش از ۲۴ ساعت)، هیچ چیزی پذیرفته نمی‌شه.
 *
 * خروجیِ melkinoVerifyMiniAppInitData: آرایه‌ی کاربر تأییدشده
 * (id, username, first_name, last_name, ...) یا null در صورت نامعتبر بودن.
 * نسخهٔ Ex هم همین منطق را دارد ولی علت رد را هم برمی‌گرداند.
 */
if (!function_exists('melkinoVerifyMiniAppInitDataEx')) {
    /**
     * نسخهٔ تشخیصیِ تأیید initData: دقیقاً همان الگوریتمِ
     * melkinoVerifyMiniAppInitData، ولی علاوه‌بر کاربر، «علت رد» را هم
     * برمی‌گرداند تا صفحهٔ ورود و عیب‌یاب بتوانند پیام دقیق بدهند:
     *   - منقضی (expired) → مینی‌اپ را ببند و دوباره باز کن
     *   - امضای نامعتبر (bad_hash) → توکنِ ذخیره‌شده متعلق به همین ربات نیست
     *
     * خروجی: ['user' => ?array, 'error' => ?string]
     * error یکی از: null | 'empty' | 'no_user' | 'no_hash' | 'bad_hash'
     *               | 'expired' | 'future' | 'no_user_id'
     */
    function melkinoVerifyMiniAppInitDataEx(string $initData, string $botToken): array
    {
        $botToken = trim($botToken);
        if ($initData === '' || $botToken === '') {
            return ['user' => null, 'error' => 'empty'];
        }

        /* امضای تلگرام/بله روی «مقادیر خام» (هنوز URL-encoded) محاسبه
           می‌شود. parse_str مقادیر را decode می‌کند و برای نام‌های فارسی/
           ایموجی/فاصله‌دار data-check-string را از آنچه تلگرام امضا کرده
           جدا می‌کند → خطای «امضا معتبر نیست». حالا اول نسخهٔ RAW را
           می‌سازیم و نسخهٔ decode شده را فقط به‌عنوان چارهٔ آخر امتحان
           می‌کنیم تا نصب‌هایی که با روش قبلی کار می‌کردند نشکنند. */
        $rawParsed = [];
        foreach (explode('&', $initData) as $__seg) {
            if ($__seg === '') continue;
            $eq = strpos($__seg, '=');
            if ($eq === false) { $rawParsed[$__seg] = ''; continue; }
            $rawParsed[substr($__seg, 0, $eq)] = substr($__seg, $eq + 1);
        }
        if (!$rawParsed || empty($rawParsed['user'])) {
            return ['user' => null, 'error' => 'no_user'];
        }

        // تلگرام امضا را در پارامتر hash می‌فرستد؛ بعضی نسخه‌های بله به‌جای آن
        // از signature استفاده می‌کنند. هر دو پشتیبانی می‌شوند.
        // راند ۶۸: کلاینت‌های جدید تلگرام علاوه‌بر hash یک فیلد signature هم
        // می‌فرستند؛ مقدارش را نگه می‌داریم تا هر دو پوشش (با/بدون signature)
        // امتحان شود (بخش ۱b/۲b پایین).
        $receivedHash = '';
        $sigVal = '';
        if (!empty($rawParsed['hash'])) {
            $receivedHash = (string)$rawParsed['hash'];
            unset($rawParsed['hash']);
            if (isset($rawParsed['signature'])) {
                $sigVal = (string)$rawParsed['signature'];
                unset($rawParsed['signature']);
            }
        } elseif (!empty($rawParsed['signature'])) {
            $receivedHash = (string)$rawParsed['signature'];
            unset($rawParsed['signature']);
        }

        if ($receivedHash === '') {
            return ['user' => null, 'error' => 'no_hash'];
        }

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        $hashOk = false;
        $variant = '';
        $parsed = [];

        /* روش ۱ (درست و استاندارد): مقادیر خام */
        $pairs = [];
        foreach ($rawParsed as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        sort($pairs, SORT_STRING);
        $dataCheckString = implode("\n", $pairs);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);
        if (hash_equals($computedHash, $receivedHash)) {
            $hashOk = true;
            $variant = 'raw-ex';
            parse_str($initData, $parsed); // برای خواندن مقادیر، decode لازم است
        } else {
            /* روش ۲a (چارهٔ آخر — سازگاری با رفتار قبلی): مقادیر decode شده */
            parse_str($initData, $parsed);
            if (is_array($parsed) && !empty($parsed['user'])) {
                $__p2 = $parsed;
                unset($__p2['hash'], $__p2['signature']);
                $__pairs = [];
                foreach ($__p2 as $key => $value) {
                    $__pairs[] = $key . '=' . $value;
                }
                sort($__pairs, SORT_STRING);
                $__computed = hash_hmac('sha256', implode("\n", $__pairs), $secretKey);
                if (hash_equals($__computed, $receivedHash)) {
                    $hashOk = true;
                    $variant = 'dec-ex';
                }
            }
        }

        /* روش ۱b (راند ۶۸ — کلاینت‌های جدید تلگرام): مقادیر خام، فقط بدون
           hash؛ یعنی signature جزء پوشش رشتهٔ بررسی است. اگر تلگرام hash را
           روی «همهٔ فیلدها به‌جز hash» حساب کرده باشد (و signature را حذف
           نکرده باشد)، فقط همین حالت جور درمی‌آید. */
        if (!$hashOk && $sigVal !== '') {
            $__pairsIn = [];
            foreach ($rawParsed as $key => $value) {
                $__pairsIn[] = $key . '=' . $value;
            }
            $__pairsIn[] = 'signature=' . $sigVal;
            sort($__pairsIn, SORT_STRING);
            $__computedIn = hash_hmac('sha256', implode("\n", $__pairsIn), $secretKey);
            if (hash_equals($__computedIn, $receivedHash)) {
                $hashOk = true;
                $variant = 'raw-in';
                parse_str($initData, $parsed);
            }
        }

        /* روش ۲b (راند ۶۸): مقادیر decode شده، فقط بدون hash. */
        if (!$hashOk && $sigVal !== '') {
            if (!is_array($parsed) || empty($parsed['user'])) {
                parse_str($initData, $parsed);
            }
            if (is_array($parsed) && !empty($parsed['user'])) {
                $__p2b = $parsed;
                unset($__p2b['hash']);
                $__pairsB = [];
                foreach ($__p2b as $key => $value) {
                    $__pairsB[] = $key . '=' . $value;
                }
                sort($__pairsB, SORT_STRING);
                $__computedB = hash_hmac('sha256', implode("\n", $__pairsB), $secretKey);
                if (hash_equals($__computedB, $receivedHash)) {
                    $hashOk = true;
                    $variant = 'dec-in';
                }
            }
        }

        if (!$hashOk) {
            return ['user' => null, 'error' => 'bad_hash'];
        }

        $authDate = (int)($parsed['auth_date'] ?? 0);
        if ($authDate <= 0) {
            return ['user' => null, 'error' => 'expired'];
        }
        if ((time() - $authDate) > 86400) {
            // امضا معتبر است ولی خیلی قدیمی‌ست (بیش از ۲۴ ساعت)
            return ['user' => null, 'error' => 'expired', 'auth_date' => $authDate];
        }
        if (($authDate - time()) > 3600) {
            // auth_date در آینده است: ساعت سرور عقب است (replay/اختلاف ساعت)
            return ['user' => null, 'error' => 'future', 'auth_date' => $authDate];
        }

        $user = json_decode((string)$parsed['user'], true);
        if (!is_array($user) || empty($user['id'])) {
            return ['user' => null, 'error' => 'no_user_id'];
        }

        // راند ۳۰: تمام فیلدهای هویتی تلگرام (نه فقط ۴ فیلد) برگردانده
        // می‌شود تا در هر ورود با users.telegram_* همگام شوند. این داده‌ها
        // فقط از payload امضاشده (HMAC) می‌آیند، پس قابل اعتمادند.
        return ['user' => [
            'id' => (string)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'first_name' => (string)($user['first_name'] ?? ''),
            'last_name' => (string)($user['last_name'] ?? ''),
            'language_code' => (string)($user['language_code'] ?? ''),
            'is_premium' => !empty($user['is_premium']),
            'photo_url' => (string)($user['photo_url'] ?? ''),
            'allows_write_to_pm' => !empty($user['allows_write_to_pm']),
            'auth_date' => $authDate,
            'query_id' => (string)($parsed['query_id'] ?? ''),
            'chat_type' => (string)($parsed['chat_type'] ?? ''),
            'chat_instance' => (string)($parsed['chat_instance'] ?? ''),
            'start_param' => (string)($parsed['start_param'] ?? ''),
        ], 'error' => null, 'variant' => $variant];
    }
}

if (!function_exists('melkinoVerifyMiniAppInitData')) {
    function melkinoVerifyMiniAppInitData(string $initData, string $botToken): ?array
    {
        $r = melkinoVerifyMiniAppInitDataEx($initData, $botToken);
        return $r['user'];
    }
}

if (!function_exists('melkinoVerifyTelegramInitData')) {
    function melkinoVerifyTelegramInitData(string $initData): ?array
    {
        $token = function_exists('melkinoTelegramToken') ? melkinoTelegramToken() : (defined('BOT_TOKEN') ? (string)BOT_TOKEN : '');
        if ($token === '' || $token === 'توکن_ربات_تلگرام') {
            return null;
        }
        return melkinoVerifyMiniAppInitData($initData, $token);
    }
}

if (!function_exists('melkinoVerifyBaleInitData')) {
    function melkinoVerifyBaleInitData(string $initData): ?array
    {
        $token = function_exists('melkinoBaleToken') ? melkinoBaleToken() : (defined('BALE_BOT_TOKEN') ? (string)BALE_BOT_TOKEN : '');
        if ($token === '' || $token === 'توکن_ربات_بله') {
            return null;
        }
        return melkinoVerifyMiniAppInitData($initData, $token);
    }
}

if (!function_exists('melkinoVerifyEitaaInitData')) {
    function melkinoVerifyEitaaInitData(string $initData): ?array
    {
        $token = function_exists('melkinoEitaaToken') ? melkinoEitaaToken() : (defined('EITAA_BOT_TOKEN') ? (string)EITAA_BOT_TOKEN : '');
        if ($token === '' || $token === 'توکن_برنامه_ایتا') return null;
        require_once __DIR__ . '/eitaa-auth-lib.php';
        return melkinoEitaaVerifyInitDataEx($initData, $token)['user'];
    }
}

/**
 * مطمئن می‌شود جدول‌های سیستم پشتیبانی (تیکت‌ها و پیام‌ها) وجود دارند.
 * قبلاً این کار فقط داخل support.php انجام می‌شد، به همین دلیل اگر
 * کاربری مستقیم به support-api.php (مثلاً از پنل ادمین) درخواست می‌زد
 * بدون اینکه support.php قبلاً یک‌بار اجرا شده باشد، خطای «جدول وجود
 * ندارد» می‌گرفت. حالا این تابع در همین فایل مشترک (db_helpers.php)
 * قرار دارد و همیشه، صرف‌نظر از نقطه‌ی ورود، اجرا می‌شود.
 */
if (!function_exists('melkinoEnsureSupportTables')) {
    function melkinoEnsureSupportTables(): bool
    {
        global $pdo;

        static $ensured = false;
        if ($ensured) {
            return true;
        }

        if (!($pdo instanceof PDO)) {
            return false;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_tickets (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NULL,
                    telegram_id VARCHAR(64) NULL,
                    phone VARCHAR(30) NULL,
                    name VARCHAR(120) NULL,
                    subject VARCHAR(255) NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'open',
                    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    PRIMARY KEY (id),
                    KEY idx_support_status (status),
                    KEY idx_support_updated_at (updated_at)
                ) ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS support_messages (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id INT NOT NULL,
                    sender_type VARCHAR(20) NOT NULL DEFAULT 'user',
                    sender_id VARCHAR(64) NULL,
                    sender_name VARCHAR(120) NULL,
                    message TEXT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_support_messages_ticket (ticket_id),
                    KEY idx_sender_read (sender_type, is_read)
                ) ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
            ");

            // راند ۵۰: مهاجرت دیتابیس‌های قدیمی به اسکیمای کاننonical
            $colsT = $pdo->query('SHOW COLUMNS FROM support_tickets')->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('user_name', $colsT, true) && !in_array('name', $colsT, true)) {
                $pdo->exec('ALTER TABLE support_tickets CHANGE COLUMN user_name name VARCHAR(120) NULL');
            } elseif (in_array('user_name', $colsT, true) && in_array('name', $colsT, true)) {
                $pdo->exec('ALTER TABLE support_tickets DROP COLUMN user_name');
            }
            $colsM = $pdo->query('SHOW COLUMNS FROM support_messages')->fetchAll(PDO::FETCH_COLUMN);
            $need = [
                'sender_type' => "ADD COLUMN sender_type VARCHAR(20) NOT NULL DEFAULT 'user'",
                'sender_id'   => 'ADD COLUMN sender_id VARCHAR(64) NULL',
                'sender_name' => 'ADD COLUMN sender_name VARCHAR(120) NULL',
                'message'     => 'ADD COLUMN message TEXT NULL',
                'is_read'     => 'ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0',
            ];
            $legacy = in_array('sender', $colsM, true) || in_array('body', $colsM, true);
            foreach ($need as $col => $ddl) {
                if (!in_array($col, $colsM, true)) {
                    $pdo->exec('ALTER TABLE support_messages ' . $ddl);
                }
            }
            if ($legacy) {
                if (in_array('sender', $colsM, true)) {
                    $pdo->exec("UPDATE support_messages SET sender_type = COALESCE(NULLIF(sender, ''), 'user') WHERE sender IS NOT NULL");
                    $pdo->exec('ALTER TABLE support_messages DROP COLUMN sender');
                }
                if (in_array('body', $colsM, true)) {
                    $pdo->exec('UPDATE support_messages SET message = COALESCE(message, body)');
                    $pdo->exec('ALTER TABLE support_messages DROP COLUMN body');
                }
            }

            $ensured = true;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

melkinoEnsureSupportTables();

/**
 * کاربر را در جدول users ثبت/به‌روزرسانی می‌کند — چه با آی‌دی تلگرام
 * شناسایی شده باشد، چه فقط با شماره تماس (بازدیدکننده‌ی مرورگر عادی).
 *
 * نکته‌ی امنیتی مهم: فقط تایپ‌کردن یک شماره تلفن، اثبات مالکیت آن
 * شماره نیست. قبلاً هرکسی با وارد کردن شماره‌ی شخص دیگری (مثلاً در
 * فرم ثبت ملک یا صفحه‌ی ورود)، سشن خودش را به همان شماره متصل
 * می‌کرد و می‌توانست «ملک‌های من» و «درخواست‌های من» فرد دیگری را
 * ببیند. حالا شماره‌ی تلفن به‌تنهایی چیزی را باز نمی‌کند: هر کاربرِ
 * شماره‌محور یک «access_token» تصادفی و مخفی می‌گیرد که فقط در
 * کوکی همان مرورگری که اول بار ثبت‌نام کرده ذخیره می‌شود. برای ادعای
 * یک شماره‌ی از قبل ثبت‌شده، باید همان توکن معتبر ارائه شود؛ در غیر
 * این صورت شماره «متعلق به دیگری» اعلام می‌شود و هویتی داده نمی‌شود.
 *
 * آی‌دی تلگرام همچنان مثل قبل معتبر و قابل‌اعتماد است، چون فقط
 * کلاینت واقعی تلگرامِ همان شخص می‌تواند آن را در اختیار سایت بگذارد.
 *
 * خروجی: آرایه‌ای شامل:
 *   - id: شناسه‌ی کاربر (یا null)
 *   - token: توکن معتبر برای ذخیره در کوکی مرورگر (یا null)
 *   - trusted: آیا این هویت برای اتصال سشن قابل‌اعتماد است؟
 *   - status: 'telegram' | 'new_phone' | 'verified_phone' | 'phone_taken' | 'none'
 */
if (!function_exists('melkinoUpsertUser')) {
    function melkinoUpsertUser(?string $telegramId, ?string $phone, ?string $name = null, ?string $username = null, ?string $providedToken = null, ?string $baleId = null, ?string $eitaaId = null): array
    {
        global $pdo;

        $telegramId = trim((string)$telegramId);
        $baleId = trim((string)$baleId);
        $eitaaId = trim((string)$eitaaId);
        $phone = trim((string)$phone);
        $name = trim((string)$name);
        $username = trim((string)$username);
        $providedToken = trim((string)$providedToken);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $result = ['id' => null, 'token' => null, 'trusted' => false, 'status' => 'none'];
        // Legacy listing/profile forms may read their own identity, never manufacture a login.
        // Administrator-created records remain permitted under the approved legacy-phone policy.
        if(empty($_SESSION['is_admin'])){
            $uid=(int)($_SESSION['melkino_user_id']??$_SESSION['user_id']??0);
            if($uid<=0||!($pdo instanceof PDO))return $result;
            $q=$pdo->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$uid]);
            return $q->fetchColumn()?['id'=>$uid,'token'=>null,'trusted'=>true,'status'=>'session']:$result;
        }


        if ($telegramId === '' && $baleId === '' && $eitaaId === '' && $phone === '') {
            return $result;
        }
        if (!($pdo instanceof PDO)) {
            return $result;
        }

        // راند ۳۱: اگر کاربر نام خودش را ثبت و قفل کرده باشد (name_locked=1)،
        // همگام‌سازی ورود نباید نام او را با نام تلگرام بازنویسی کند.
        if (function_exists('melkinoEnsureUserProfileColumns')) {
            melkinoEnsureUserProfileColumns();
        }
        static $r31HasNameLocked = null;
        if ($r31HasNameLocked === null) {
            $r31HasNameLocked = false;
            try {
                $r31HasNameLocked = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'name_locked'")->fetch();
            } catch (Throwable $e) {
                $r31HasNameLocked = false;
            }
        }
        $r31NameGuard = $r31HasNameLocked ? ' AND name_locked = 0' : '';

        try {
            if ($telegramId !== '') {
                // مسیر تلگرام: مثل قبل، همیشه معتبر است.
                $stmt = $pdo->prepare(
                    "INSERT INTO users (telegram_id, username, name, phone, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)
                     ON DUPLICATE KEY UPDATE
                         username = IF(VALUES(username) <> '', VALUES(username), username),
                         name = IF(VALUES(name) <> ''$r31NameGuard, VALUES(name), name),
                         last_ip = VALUES(last_ip),
                         last_login = NOW(),
                         login_count = login_count + 1"
                );
                $stmt->execute([$telegramId, $username !== '' ? $username : null, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $ip]);
                $isNewUser = $stmt->rowCount() === 1;

                $find = $pdo->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
                $find->execute([$telegramId]);
                $id = $find->fetchColumn();
                $id = $id !== false ? (int)$id : null;

                if ($id) {
                    $id = melkinoUnifyAccountByPhone((int) $id, ['telegram_id' => $telegramId]);
                }
                $result = ['id' => $id, 'token' => null, 'trusted' => true, 'status' => 'telegram'];
                melkinoMaybeSendWelcome($pdo, $isNewUser, $id, $telegramId);
                return $result;
            }

            if ($baleId !== '') {
                // مسیر بله: دقیقاً مثل تلگرام، همیشه معتبر است.
                $stmt = $pdo->prepare(
                    "INSERT INTO users (bale_id, username, name, phone, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)
                     ON DUPLICATE KEY UPDATE
                         username = IF(VALUES(username) <> '', VALUES(username), username),
                         name = IF(VALUES(name) <> ''$r31NameGuard, VALUES(name), name),
                         last_ip = VALUES(last_ip),
                         last_login = NOW(),
                         login_count = login_count + 1"
                );
                $stmt->execute([$baleId, $username !== '' ? $username : null, $name !== '' ? $name : null, $phone !== '' ? $phone : null, $ip]);
                $isNewUser = $stmt->rowCount() === 1;

                $find = $pdo->prepare("SELECT id FROM users WHERE bale_id = ? LIMIT 1");
                $find->execute([$baleId]);
                $id = $find->fetchColumn();
                $id = $id !== false ? (int)$id : null;

                if ($id) {
                    $id = melkinoUnifyAccountByPhone((int) $id, ['bale_id' => $baleId]);
                }
                $result = ['id' => $id, 'token' => null, 'trusted' => true, 'status' => 'bale'];
                melkinoMaybeSendWelcome($pdo, $isNewUser, $id, '');
                return $result;
            }

            if ($eitaaId !== '') {
                if (function_exists('melkinoEnsureUserProfileColumns')) {
                    melkinoEnsureUserProfileColumns();
                }
                $existingId = null;
                try {
                    $find = $pdo->prepare('SELECT id FROM users WHERE eitaa_id = ? LIMIT 1');
                    $find->execute([$eitaaId]);
                    $existingId = $find->fetchColumn();
                    $existingId = $existingId !== false ? (int)$existingId : null;
                } catch (Throwable $e) {
                    $existingId = null;
                }

                $isNewUser = false;
                if ($existingId) {
                    $stmt = $pdo->prepare(
                        "UPDATE users
                            SET username = IF(? <> '', ?, username),
                                name = IF(? <> ''$r31NameGuard, ?, name),
                                last_ip = ?,
                                last_login = NOW(),
                                login_count = login_count + 1
                          WHERE id = ?"
                    );
                    $stmt->execute([
                        $username, $username !== '' ? $username : null,
                        $name, $name !== '' ? $name : null,
                        $ip,
                        $existingId,
                    ]);
                    $id = $existingId;
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (eitaa_id, username, name, phone, last_ip, first_login, last_login, login_count, is_active)
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)"
                    );
                    $stmt->execute([
                        $eitaaId,
                        $username !== '' ? $username : null,
                        $name !== '' ? $name : null,
                        $phone !== '' ? $phone : null,
                        $ip,
                    ]);
                    $id = (int)$pdo->lastInsertId();
                    $isNewUser = true;
                }

                $result = ['id' => $id, 'token' => null, 'trusted' => true, 'status' => 'eitaa'];
                melkinoMaybeSendWelcome($pdo, $isNewUser, $id, '');
                return $result;
            }

            // مسیر فقط-شماره: اول ببینیم این شماره قبلاً ثبت شده یا نه.
            $existing = $pdo->prepare("SELECT id, access_token FROM users WHERE phone = ? LIMIT 1");
            $existing->execute([$phone]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // شماره‌ی تازه: یک کاربر و یک توکن جدید ساخته می‌شود.
                // HARDEN-01: هش ذخیره، خام برای کوکی برگردانده می‌شود.
                $newToken = bin2hex(random_bytes(24));
                $stmt = $pdo->prepare(
                    "INSERT INTO users (phone, username, name, access_token, last_ip, first_login, last_login, login_count, is_active)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1, 1)"
                );
                $stmt->execute([$phone, $username !== '' ? $username : null, $name !== '' ? $name : null, hash('sha256', $newToken), $ip]);
                $id = (int)$pdo->lastInsertId();

                $result = ['id' => $id, 'token' => $newToken, 'trusted' => true, 'status' => 'new_phone'];
                melkinoMaybeSendWelcome($pdo, true, $id, '');
                return $result;
            }

            // شماره از قبل وجود دارد؛ فقط با توکن معتبر همان مرورگر قابل تأیید است.
            // HARDEN-01: مقایسه با هش (خام در DB نیست). ردیف‌های مهاجرت‌نکرده
            // خودبه‌خود با ورود OTP بعدی (که همیشه می‌چرخاند) ترمیم می‌شوند.
            if ($row['access_token'] !== null && $providedToken !== '' && hash_equals((string)$row['access_token'], hash('sha256', $providedToken))) {
                $update = $pdo->prepare(
                    "UPDATE users SET
                        name = IF(? <> ''$r31NameGuard, ?, name),
                        username = IF(? <> '', ?, username),
                        last_ip = ?,
                        last_login = NOW(),
                        login_count = login_count + 1
                     WHERE id = ?"
                );
                $update->execute([$name, $name, $username, $username, $ip, $row['id']]);

                // HARDEN-01: کوکی باید همان توکن خامی باشد که همین‌جا تأیید شد
                // (مقدار ذخیره‌شده در DB اکنون هش است و به درد کوکی نمی‌خورد).
                $result = ['id' => (int)$row['id'], 'token' => $providedToken, 'trusted' => true, 'status' => 'verified_phone'];
                return $result;
            }

            // توکن معتبر نیست یا اصلاً ارسال نشده: این شماره متعلق به شخص دیگری‌ست.
            $result = ['id' => null, 'token' => null, 'trusted' => false, 'status' => 'phone_taken'];
            return $result;
        } catch (Throwable $e) {
            return $result;
        }
    }
}


// Legacy callers must not merge identities on the strength of a typed/cached phone.
if (!function_exists('melkinoMergeUserAccounts')) {
    function melkinoMergeUserAccounts(PDO $pdo, int $keepId, int $absorbId): bool { return false; }
}
if (!function_exists('melkinoClaimPhoneOnUser')) {
    function melkinoClaimPhoneOnUser(int $userId, string $phone): array {
        global $pdo;
        $phone=melkinoNormalizeIranPhone($phone);
        $fail=['ok'=>false,'user_id'=>$userId,'merged'=>false,'message'=>'ثبت شماره انجام نشد.'];
        if($userId<=0 || $phone==='' || !($pdo instanceof PDO))return $fail;
        $lock='mk-phone-'.substr(hash('sha256',$phone),0,40);$locked=false;
        try {
            $st=$pdo->prepare('SELECT GET_LOCK(?,5)');$st->execute([$lock]);$locked=(int)$st->fetchColumn()===1;
            if(!$locked)throw new RuntimeException('Phone busy');
            $st=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$st->execute([$userId]);$me=$st->fetch(PDO::FETCH_ASSOC);
            if(!$me || empty($me['is_active']))return $fail;
            $current=trim((string)($me['phone']??''));
            if($current!=='' && $current!==$phone){$fail['message']='شمارهٔ تماس قفل است؛ تغییر آن فقط توسط مدیر امکان‌پذیر است.';return $fail;}
            $st=$pdo->prepare('SELECT id FROM users WHERE phone=? AND id<>? LIMIT 1');$st->execute([$phone,$userId]);
            if($st->fetchColumn()){
                $fail['code']='account_link_required';$fail['link_url']='account-link.php';
                $fail['message']='این شماره قبلاً ثبت شده است. از ورود یکپارچه، شمارهٔ خود پیام‌رسان را به اشتراک بگذارید تا همان حساب باز شود.';
                return $fail;
            }
            if($current===''){
                $fail['code']='native_phone_required';$fail['link_url']='account-link.php';$fail['message']='شماره باید از خود پیام‌رسان دریافت یا با پیامک تأیید شود؛ از صفحهٔ ورود یکپارچه ادامه دهید.';return $fail;

            }
            melkinoSyncSessionFromUser($userId,$phone);
            return ['ok'=>true,'user_id'=>$userId,'merged'=>false,'message'=>''];
        }catch(Throwable $e){error_log('[account-link] Phone registration failed');return $fail;}
        finally {if($locked){try{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}catch(Throwable $e){}}}
    }
}

if (!function_exists('melkinoSyncSessionFromUser')) {
    function melkinoSyncSessionFromUser(int $userId, string $phone = ''): void
    {
        global $pdo;
        if (session_status() !== PHP_SESSION_ACTIVE || $userId <= 0 || !($pdo instanceof PDO)) {
            return;
        }
        try {
            $st = $pdo->prepare('SELECT telegram_id, bale_id, eitaa_id, name, phone FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $u = [];
        }
        if (!$u) return;
        // The verified DB account is authoritative. Do not keep IDs/phone cached
        // from another provider/user, especially when switching to/from Eitaa.
        unset($_SESSION['reg_telegram_id'], $_SESSION['reg_bale_id'], $_SESSION['reg_eitaa_id'],
            $_SESSION['user_phone'], $_SESSION['user_name']);
        $_SESSION['user_id'] = $_SESSION['melkino_user_id'] = $userId;
        if ($phone !== '') {
            $_SESSION['user_phone'] = $phone;
        } elseif (!empty($u['phone'])) {
            $_SESSION['user_phone'] = (string) $u['phone'];
        }
        if (!empty($u['telegram_id'])) {
            $_SESSION['reg_telegram_id'] = (string) $u['telegram_id'];
        }
        if (!empty($u['bale_id'])) {
            $_SESSION['reg_bale_id'] = (string) $u['bale_id'];
        }
        if (!empty($u['eitaa_id'])) {
            $_SESSION['reg_eitaa_id'] = (string) $u['eitaa_id'];
        }
        if (!empty($u['name'])) {
            $_SESSION['user_name'] = (string) $u['name'];
        }
    }
}

if (!function_exists('melkinoUnifyAccountByPhone')) {
    // Cached session phone is NOT consent to link a new messenger identity.
    function melkinoUnifyAccountByPhone(?int $userId, array $ids=[]): int { return (int)$userId; }
}

if (!function_exists('melkinoMaybeSendWelcome')) {
    function melkinoMaybeSendWelcome(PDO $pdo, bool $isNewUser, ?int $userId, string $telegramId): void
    {
        if (!$isNewUser || $userId === null) return;
        try {
            $welcome = $pdo->prepare(
                "INSERT INTO notifications (user_id, telegram_id, type, title, message, url, is_read, created_at)
                 VALUES (?, ?, 'welcome', ?, ?, 'home.php', 0, NOW())"
            );
            $welcome->execute([
                $userId,
                $telegramId !== '' ? $telegramId : null,
                '🎉 به ملکینو خوش اومدی',
                'خوشحالیم که به جمع ملکینو پیوستی! از اینجا می‌تونی ملک‌های پیشنهادی، درخواست‌ها و آگهی‌های ثبت‌شده‌ات رو دنبال کنی.',
            ]);
        } catch (Throwable $e) {
            // اگر ثبت اعلان ناموفق بود، مانع از ادامه‌ی کار نمی‌شود
        }
    }
}

/**
 * اطلاعاتِ پایه‌ی کاربرِ واردشده برای «پر کردن خودکارِ فرم‌ها».
 * خروجی: ['logged_in'=>bool, 'name'=>string, 'phone'=>string]
 */
if (!function_exists('melkinoProfilePrefill')) {
    function melkinoProfilePrefill(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }

        $identity = function_exists('melkinoCurrentIdentity') ? melkinoCurrentIdentity() : [];
        $userId   = $identity['user_id'] ?? null;

        $name  = trim((string)($_SESSION['user_name'] ?? ''));
        $phone = trim((string)($_SESSION['user_phone'] ?? ''));

        if ($name === '' && !empty($identity['user']['name'])) {
            $name = trim((string)$identity['user']['name']);
        }
        if ($phone === '' && !empty($identity['phone'])) {
            $phone = trim((string)$identity['phone']);
        }

        // راند ۳۰: وضعیت شمارهٔ قفل‌شده + فیلدهای تلگرامی برای فرم‌ها و پروفایل.
        // این داده‌ها سمت سرور از دیتابیس خوانده می‌شوند (نه localStorage).
        $user        = $identity['user'] ?? null;
        $dbPhone     = trim((string)($user['phone'] ?? ''));
        if ($dbPhone !== '') {
            $phone = $dbPhone;
        }
        $phoneVerified = !empty($user['phone_verified']) || !empty($user['phone_locked']);
        $phoneLocked   = !empty($user['phone_locked']);
        if ($phone === '' || !$phoneVerified) {
            $phoneVerified = $phoneVerified && $phone !== '';
        }
        $tgUser = '';
        foreach (['telegram_username', 'username'] as $k) {
            if (!empty($user[$k])) { $tgUser = (string)$user[$k]; break; }
        }

        return [
            'logged_in'      => !empty($userId),
            'name'           => $name,
            'first_name'     => (string)($user['first_name'] ?? ''),
            'last_name'      => (string)($user['last_name'] ?? ''),
            'name_locked'    => !empty($user['name_locked']),
            'phone'          => $phone,
            'phone_verified' => $phoneVerified,
            'phone_locked'   => $phoneLocked,
            'telegram_id'    => (string)($identity['telegram_id'] ?? ''),
            'telegram_username' => $tgUser,
            'photo_url'      => (string)($user['photo_url'] ?? ''),
        ];
    }
}

/**
 * متن کاملِ یک آگهی برای ارسال به تلگرام/بله (با فرمت HTML برای تلگرام).
 * این تابع مشترک است تا متنی که از «سرور» فرستاده می‌شود با متنی که از
 * «مرورگر» فرستاده می‌شود دقیقاً یکسان باشد.
 *
 * کدام فیلدها بیایند و چه متن ثابتی بالا/پایین همه‌ی آگهی‌ها باشد، از
 * تنظیمات انتشار هر پلتفرم (تب «ربات و کانال») خوانده می‌شود. اگر چیزی
 * تنظیم نشده باشد، همه‌ی فیلدها می‌آیند (همان رفتار قبلی).
 */
if (!function_exists('melkinoPriceToNum')) {
    /**
     * تبدیل مبلغ/عدد به رقم لاتین استاندارد (راند ۲۲).
     * رقم‌های فارسی/عربی، جداکننده‌های هزارگان (، / ٬ / ,)، فاصله و واژهٔ
     * «تومان» را حذف می‌کند تا قیمت‌هایی که به شکل قالب‌بندی‌شده ذخیره شده‌اند
     * درست خوانده شوند (جلوگیری از انتشار «۰ تومان» یا قیمت خالی).
     */
    function melkinoPriceToNum($value): float
    {
        $s = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            trim((string)$value)
        );
        // راند ۲۷: جداکننده‌های فارسی/عربی — ٬ و ، و «تومان» و فاصله حذف می‌شوند
        // و ممیز عربی ٫ (U+066B) به نقطه تبدیل می‌شود.
        $s = str_replace(['٬', '،', ' ', 'تومان'], '', $s);
        $s = str_replace('٫', '.', $s);
        // راند ۲۷: کامای اعشاری (مثل 1234,50) فقط وقتی نقطه وجود نداشته باشد
        // و دقیقاً یک کاما با ۱-۲ رقم بعدش باشد؛ وگرنه کاما جداکنندهٔ هزارگان است.
        if (strpos($s, '.') === false && preg_match('/^([+-]?\d+),(\d{1,2})$/', $s)) {
            $s = str_replace(',', '.', $s);
        }
        $s = str_replace(',', '', $s);
        return is_numeric($s) ? (float)$s : 0.0;
    }
}

if (!function_exists('melkinoPublishMoneyNumber')) {
    /** Full value, grouped; only an ALL-ZERO fractional part is removed. */
    function melkinoPublishMoneyNumber($value): string
    {
        if (is_float($value)) {
            if (!is_finite($value)) return '';
            $value = number_format($value, 2, '.', '');
        }
        $s = strtr(trim((string)$value), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٬'=>'','،'=>'',','=>'',' '=>'','تومان'=>'','٫'=>'.',
        ]);
        if (!preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $s, $m)) return '';
        $whole = ltrim($m[2], '0'); if ($whole==='') $whole='0';
        $fraction = $m[3] ?? '';
        $whole = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', $whole);
        return ($m[1]==='-'?'-':'') . $whole . ($fraction!=='' && trim($fraction,'0')!=='' ? '.'.$fraction : '');
    }
}

if (!function_exists('melkinoCleanAdTitle')) {
    /**
     * پاک‌سازی عنوان آگهی از تگ‌ها/نوشته‌های الکی HTML (درخواست کارفرما).
     * <b>، </b>، پرانتزهای باقی‌مانده و فاصله‌های اضافه حذف می‌شوند.
     */
    function melkinoCleanAdTitle($title, string $fallback = 'آگهی ملک'): string
    {
        $t = (string)$title;
        $t = strip_tags($t);
        $t = str_replace(['<', '>'], '', $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? '';
        $t = trim($t);
        return $t !== '' ? $t : $fallback;
    }
}

if (!function_exists('melkinoEnsureSendGuard')) {
    /** جدول کوچک گارد ضدتکرار ارسال پیام (زیرساختی؛ ربطی به داده‌های آگهی ندارد). */
    function melkinoEnsureSendGuard(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS melkino_send_guard (
            request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            response MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('melkinoSendGuardTake')) {
    /**
     * اگر همین request_id قبلاً پردازش شده باشد، پاسخ قبلی را برمی‌گرداند (true)
     * تا هرگز دوباره ارسال نشود. خروجی: ['seen'=>bool,'response'=>?array]
     */
    function melkinoSendGuardTake(PDO $pdo, string $requestId): array
    {
        if ($requestId === '' || strlen($requestId) > 80) {
            return ['seen' => false, 'response' => null];
        }
        try {
            melkinoEnsureSendGuard($pdo);
            $st = $pdo->prepare('SELECT response FROM melkino_send_guard WHERE request_id = ? LIMIT 1');
            $st->execute([$requestId]);
            $row = $st->fetchColumn();
            if ($row === false) {
                return ['seen' => false, 'response' => null];
            }
            $decoded = json_decode((string)$row, true);
            return ['seen' => true, 'response' => is_array($decoded) ? $decoded : null];
        } catch (Throwable $e) {
            return ['seen' => false, 'response' => null];
        }
    }
}

if (!function_exists('melkinoSendGuardStore')) {
    /** ذخیرهٔ نتیجهٔ قطعی (موفق یا خطای API) برای request_id — جلوگیری از ارسال دوباره. */
    function melkinoSendGuardStore(PDO $pdo, string $requestId, array $response): void
    {
        if ($requestId === '' || strlen($requestId) > 80) {
            return;
        }
        try {
            melkinoEnsureSendGuard($pdo);
            $st = $pdo->prepare('INSERT IGNORE INTO melkino_send_guard (request_id, response) VALUES (?, ?)');
            $st->execute([$requestId, json_encode($response, JSON_UNESCAPED_UNICODE) ?: '']);
            // پاک‌سازی رکوردهای قدیمی‌تر از ۲۴ ساعت
            $pdo->exec("DELETE FROM melkino_send_guard WHERE created_at < NOW() - INTERVAL 1 DAY");
        } catch (Throwable $e) {
            /* گارد هرگز مسیر اصلی را نمی‌شکند */
        }
    }
}

if (!function_exists('melkinoAdMessageText')) {
    function melkinoAdMessageText(array $ad, bool $html = true, string $platform = 'telegram', ?array $fieldsOverride = null, ?array $textOverride = null): string
    {
        $esc = function ($text) use ($html) {
            $text = (string)$text;
            return $html ? str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text) : $text;
        };

        $money = function ($value) {
            $n = melkinoPriceToNum($value);
            return $n > 0 ? melkinoPublishMoneyNumber($value) . ' تومان' : '';
        };

        // تنظیمات انتشار این پلتفرم (با fallback به رفتار قبلی)
        // اگر برای «نوع ملک × نوع معاملهٔ» این آگهی تنظیم اختصاصی ذخیره شده
        // باشد، همان اعمال می‌شود؛ وگرنه تنظیمات عمومی.
        $enabledFields = null; // null یعنی همه‌ی فیلدها روشن
        $pubHeader = '';
        $pubFooter = '';
        if (function_exists('melkinoPublishSettings')) {
            try {
                $pub = melkinoPublishSettings(
                    $platform,
                    (string)($ad['property_type'] ?? ''),
                    (string)($ad['transaction_type'] ?? '')
                );
                $enabledFields = is_array($pub['fields'] ?? null) ? array_map('strval', $pub['fields']) : null;
                $pubHeader = trim((string)($pub['header'] ?? ''));
                $pubFooter = trim((string)($pub['footer'] ?? ''));
            } catch (Throwable $e) {
                $enabledFields = null;
            }
        }
        if (is_array($textOverride)) {
            $pubHeader = trim((string)($textOverride['header'] ?? $pubHeader));
            $pubFooter = trim((string)($textOverride['footer'] ?? $pubFooter));
        }
        if (is_array($fieldsOverride)) {
            $enabledFields = array_values(array_map('strval', $fieldsOverride));
        }
        $on = function (string $key) use ($enabledFields) {
            return $enabledFields === null || in_array($key, $enabledFields, true);
        };

        $lines = [];

        if ($pubHeader !== '') {
            $lines[] = $esc($pubHeader);
            $lines[] = '';
        }

        if ($on('title')) {
            $lines[] = '🏠 ' . $esc(melkinoCleanAdTitle($ad['title'] ?? ''));
            $lines[] = '';
        }
        if ($on('transaction')) {
            $lines[] = '📌 نوع معامله: ' . $esc($ad['transaction_type'] ?: '-');
        }
        if ($on('property_type')) {
            $lines[] = '🏷️ نوع ملک: ' . $esc($ad['property_type'] ?: '-');
        }
        if ($on('location') && !empty($ad['location'])) {
            $lines[] = '📍 موقعیت: ' . $esc($ad['location']);
        }
        if ($on('address') && !empty($ad['address'])) {
            $lines[] = '🗺️ آدرس: ' . $esc($ad['address']);
        }
        if ($on('area') && !empty($ad['area'])) {
            $lines[] = '📐 متراژ: ' . $esc($ad['area']) . ' متر';
        }
        if ($on('rooms') && !empty($ad['rooms'])) {
            $lines[] = '🛏️ تعداد اتاق: ' . $esc($ad['rooms']);
        }
        if ($on('floor') && !empty($ad['floor'])) {
            $lines[] = '🏢 طبقه: ' . $esc($ad['floor']);
        }
        if ($on('year') && !empty($ad['year'])) {
            $lines[] = '📅 سال ساخت: ' . $esc($ad['year']);
            if (function_exists('melkinoBuildingAgeDisplay')) {
                $__age = melkinoBuildingAgeDisplay($ad['year']);
                if ($__age !== '') {
                    $lines[] = '⏳ سن بنا: ' . $__age;
                }
            }
        }

        // فیلدهای اختصاصیِ نوع ملک از property_details (راند ۱۷)
        // کلیدها با پیشوند pd. در melkinoPublishFieldDefs تعریف شده‌اند.
        $__pdDefs = [];
        if (function_exists('melkinoPublishFieldDefs')) {
            foreach (melkinoPublishFieldDefs((string)($ad['property_type'] ?? '')) as $__k => $__v) {
                if (strpos($__k, 'pd.') === 0) {
                    $__pdDefs[$__k] = $__v;
                }
            }
        }
        if ($__pdDefs) {
            $__details = [];
            $__rawDetails = $ad['property_details'] ?? null;
            if (is_string($__rawDetails) && trim($__rawDetails) !== '') {
                $__decoded = json_decode($__rawDetails, true);
                if (is_array($__decoded)) {
                    $__details = $__decoded;
                }
            } elseif (is_array($__rawDetails)) {
                $__details = $__rawDetails;
            }
            foreach ($__pdDefs as $__k => $__def) {
                if (!$on($__k)) {
                    continue;
                }
                $__dk = substr($__k, 3);
                $__val = $__details[$__dk] ?? '';
                if (is_array($__val)) {
                    $__val = implode('، ', array_filter(array_map('strval', $__val), static fn($x) => trim($x) !== ''));
                }
                $__val = trim((string)$__val);
                if (strpos($__dk, 'has_') === 0) {
                    if ($__val !== '1') {
                        continue;
                    }
                    $__val = 'دارد';
                }
                if ($__val === '' || $__val === '0' || $__val === '۰') {
                    continue;
                }
                $lines[] = ($__def['emoji'] ?? '🔹') . ' ' . ($__def['label'] ?? $__dk) . ': ' . $esc($__val) . (string)($__def['suffix'] ?? '');
            }
        }

        // اطلاعات وام (فقط فروش/پیش‌فروش)
        $loan = function_exists('melkinoLoanInfo') ? melkinoLoanInfo($ad) : ['has' => false];

        $__pm = function_exists('melkinoPriceMessage') ? melkinoPriceMessage() : '';
        if ($on('price') && $__pm !== '') {
            $lines[] = '💰 ' . $esc($__pm);
        } elseif ($on('price')) {
            if (empty($ad['price_hidden'])) {
                // راند ۲۲: همهٔ مبالغ با melkinoPriceToNum نرمال می‌شوند تا
                // مقدارهای قالب‌بندی‌شده (رقم فارسی/جداکننده) یا صفرگونه
                // («0»، «0,000»، «۰») باعث انتشار «۰ تومان» یا قیمت خالی نشوند.
                $sellNum     = melkinoPriceToNum($ad['price_sell'] ?? '');
                $totalNum    = melkinoPriceToNum($ad['total_price'] ?? '');
                $displayNum  = melkinoPriceToNum($ad['display_price'] ?? '');
                $depositNum  = melkinoPriceToNum($ad['deposit'] ?? '');
                $rentNum     = melkinoPriceToNum($ad['rent_monthly'] ?? '');
                $fullRentNum = !empty($ad['full_rent_enabled']) ? melkinoPriceToNum($ad['full_rent'] ?? '') : 0.0;
                $isPreSell   = mb_strpos(trim((string)($ad['transaction_type'] ?? '')), 'پیش') === 0;
                $priceLine   = '';
                $basePrice   = 0.0; // مبنای «قیمت هر متر» (فروش/پیش‌فروش)
                if ($sellNum > 0) {
                    $priceLine = '💰 قیمت فروش: ' . $money($ad['price_sell'] ?? '');
                    $basePrice = $sellNum;
                } elseif ($totalNum > 0) {
                    $priceLine = ($isPreSell ? '💰 قیمت کل (پیش‌فروش): ' : '💰 قیمت کل: ') . $money($ad['total_price'] ?? '');
                    $basePrice = $totalNum;
                } elseif ($fullRentNum > 0) {
                    // راند ۱۸: اگر کلید اختصاصی «رهن کامل» روشن است، خطِ جداگانه
                    // پایین چاپ می‌شود و اینجا تکرار نمی‌کنیم
                    if (!$on('full_rent')) {
                        $priceLine = '💰 اجاره کامل: ' . $money($ad['full_rent'] ?? '');
                    }
                } elseif ($depositNum > 0 || $rentNum > 0) {
                    if (!$on('deposit') && !$on('rent_monthly')) {
                        $priceLine = '💰 ودیعه: ' . $money($ad['deposit'] ?? '') . ' | اجاره: ' . $money($ad['rent_monthly'] ?? '');
                    }
                } elseif ($displayNum > 0) {
                    // راند ۲۲: چارهٔ آخر — اگر قیمت فقط در display_price ذخیره شده
                    $priceLine = '💰 قیمت: ' . $money($ad['display_price'] ?? '');
                    $basePrice = $displayNum;
                }
                if ($priceLine !== '') {
                    $lines[] = $priceLine;
                }
                // قیمت هر متر مربع (راند ۲۲ — فقط فروش/پیش‌فروش با متراژ معتبر)
                $areaNum = melkinoPriceToNum($ad['area'] ?? '');
                if ($on('price_per_meter') && $basePrice > 0 && $areaNum > 0) {
                    $lines[] = '💹 قیمت هر متر: ' . $money($basePrice / $areaNum);
                }
                // پیش‌پرداخت و شرایط پرداختِ پیش‌فروش (راند ۲۲)
                $downNum = melkinoPriceToNum($ad['down_payment'] ?? '');
                if ($on('down_payment') && $downNum > 0) {
                    $lines[] = '💳 پیش‌پرداخت: ' . $money($ad['down_payment'] ?? '');
                }
                if ($on('down_payment') && !empty($ad['payment_terms'])) {
                    $lines[] = '📋 شرایط پرداخت: ' . $esc($ad['payment_terms']);
                }
                // ملک وام‌دار: قیمت دوم = (قیمت منهای مبلغ وام) + وام
                if (!empty($loan['has']) && $priceLine !== '' && !empty($loan['net'])) {
                    $lines[] = '💵 نقد + وام: ' . $money($loan['net']) . ' + ' . $money($loan['amount']) . ' وام';
                } elseif (!empty($loan['has']) && $priceLine !== '') {
                    $lines[] = '🏦 این ملک ' . $money($loan['amount']) . ' وام دارد (از قیمت کسر می‌شود)';
                }
            } else {
                $lines[] = '💰 قیمت: توافقی (تماس بگیرید)';
            }
        }

        // خطوط جداگانهٔ رهن و اجاره (راند ۱۸)
        if ($__pm === '' && $on('full_rent') && !empty($ad['full_rent_enabled']) && !empty($ad['full_rent']) && (float)$ad['full_rent'] > 0) {
            $lines[] = '🔑 رهن کامل: ' . $money($ad['full_rent']);
        }
        if ($__pm === '' && $on('deposit') && !empty($ad['deposit']) && (float)$ad['deposit'] > 0) {
            $lines[] = '💵 رهن (ودیعه): ' . $money($ad['deposit']);
        }
        if ($__pm === '' && $on('rent_monthly') && !empty($ad['rent_monthly']) && (float)$ad['rent_monthly'] > 0) {
            $lines[] = '🗓️ اجارهٔ ماهانه: ' . $money($ad['rent_monthly']);
        }

        // مشخصات کامل وام (نوع/بانک/مدت/قسط/اقساط پرداخت‌شده/توضیحات)
        if ($__pm === '' && $on('loan') && !empty($loan['has'])) {
            $loanParts = [];
            if (!empty($loan['type']))     { $loanParts[] = 'نوع: ' . $esc($loan['type']); }
            if (!empty($loan['bank']))     { $loanParts[] = 'بانک: ' . $esc($loan['bank']); }
            if (!empty($loan['duration'])) { $loanParts[] = 'مدت: ' . $esc($loan['duration']); }
            $installmentMoney = $money($loan['installment']);
            if ($installmentMoney !== '')  { $loanParts[] = 'قسط: ' . $installmentMoney; }
            if (!empty($loan['paid']))     { $loanParts[] = 'اقساط پرداخت‌شده: ' . $esc($loan['paid']); }
            $lines[] = '🏦 وام: ' . $money($loan['amount']) . ($loanParts ? ' | ' . implode(' | ', $loanParts) : '');
            if (!empty($loan['notes'])) {
                $lines[] = '📄 توضیحات وام: ' . $esc($loan['notes']);
            }
        }

        // نوع سند و توضیحات سند (راند ۱۴)
        if ($on('deed') && !empty($ad['deed_type'])) {
            $lines[] = '📜 سند: ' . $esc($ad['deed_type']);
            if (!empty($ad['deed_notes'])) {
                $lines[] = '📄 توضیحات سند: ' . $esc($ad['deed_notes']);
            }
        }

        // تمایل به معاوضه + گزینه‌های انتخابی (راند ۱۴)
        if ($on('exchange') && !empty($ad['exchange_interested'])) {
            $exParts = [];
            if (!empty($ad['exchange_types'])) {
                $exArr = array_values(array_filter(array_map('trim', explode(',', (string)$ad['exchange_types'])), static fn($x) => $x !== ''));
                if ($exArr) {
                    $exParts[] = $esc(implode('، ', $exArr));
                }
            }
            if (!empty($ad['exchange_with'])) {
                $exParts[] = $esc($ad['exchange_with']);
            }
            $lines[] = '🔄 مایل به معاوضه' . ($exParts ? ': ' . implode(' — ', $exParts) : '');
        }

        if ($on('description') && !empty($ad['description'])) {
            $lines[] = '';
            $lines[] = '📝 ' . $esc($ad['description']);
        }

        if ($on('contact') || ($on('phone') && !empty($ad['phone']))) {
            $lines[] = '';
        }
        if ($on('contact')) {
            $lines[] = '👤 تماس: ' . $esc($ad['last_name'] ?: '-');
        }
        if ($on('phone') && !empty($ad['phone'])) {
            $lines[] = '📞 شماره تماس: ' . $esc($ad['phone']);
        }
        if ($on('consultant')) {
            $cName = '';
            $cPhone = '';
            if (!function_exists('findConsultantForAd') && is_file(__DIR__ . '/consultant_helper.php')) {
                require_once __DIR__ . '/consultant_helper.php';
            }
            if (function_exists('findConsultantForAd')) {
                $cRow = findConsultantForAd((string) ($ad['property_type'] ?? ''), (string) ($ad['transaction_type'] ?? ''));
                $cDisp = function_exists('getConsultantDisplayData') ? getConsultantDisplayData($cRow) : null;
                if (is_array($cDisp)) {
                    $cName = trim((string) ($cDisp['name'] ?? ''));
                    $cPhone = trim((string) ($cDisp['phone'] ?? ''));
                }
            }
            if ($cPhone === '' && function_exists('getConsultantPhone')) {
                $cPhone = trim((string) getConsultantPhone());
            }
            if ($cName === '' && function_exists('getConsultantName')) {
                $cName = trim((string) getConsultantName());
            }
            if ($cName !== '' || $cPhone !== '') {
                $consultLine = $cName;
                if ($cName !== '' && $cPhone !== '') {
                    $consultLine .= ' - ';
                }
                $consultLine .= $cPhone;
                $lines[] = '☎️ مشاور ملکینو: ' . $esc($consultLine);
            }
        }

        if ($on('ad_id')) {
            $lines[] = '';
            $lines[] = '🔗 کد آگهی: ' . $esc($ad['id']);
        }

        // لینک مستقیم همان آگهی روی سایت (ادمین از تب «ربات و کانال»
        // روشن/خاموشش می‌کند). اگر HOST در دسترس نباشد (مثلاً اجرای CLI)
        // خط لینک ساده حذف می‌شود تا پیام خراب نشود.
        if ($on('ad_link')) {
            $adUrl = function_exists('melkinoAdPublicUrl') ? melkinoAdPublicUrl($ad) : '';
            if ($adUrl !== '') {
                $lines[] = '🌐 لینک آگهی: ' . $adUrl;
            }
        }

        if ($pubFooter !== '') {
            $lines[] = '';
            $lines[] = $esc($pubFooter);
        }

        // خط‌های خالیِ اضافه (ناشی از خاموش‌بودن فیلدها) جمع می‌شوند
        $text = implode("\n", $lines);
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}

/**
 * نشانیِ کامل و عمومیِ صفحه‌ی خودِ آگهی روی سایت.
 * برای افزودن «لینک آگهی» به متن انتشار در کانال استفاده می‌شود.
 * اگر HOST در دسترس نباشد (اجرای CLI/کرون بدون HTTP) رشته‌ی خالی برمی‌گرداند.
 */
if (!function_exists('melkinoAdPublicUrl')) {
    function melkinoAdPublicUrl(array $ad): string
    {
        if (empty($ad['id'])) {
            return '';
        }

        return melkinoAppUrl('property-details.php?id=' . rawurlencode((string)$ad['id']) . '&share=1');
    }
}

/**
 * نشانیِ کامل و عمومیِ تصویر اصلی آگهی (برای ارسال به تلگرام).
 * اگر تصویری نباشد یا فایل وجود نداشته باشد، رشته‌ی خالی برمی‌گرداند.
 */
if (!function_exists('melkinoAdImageUrl')) {
    function melkinoAdImageUrl(array $ad): string
    {
        global $pdo;
        if (!($pdo instanceof PDO) || empty($ad['id'])) {
            return '';
        }

        $filename = '';
        try {
            $st = $pdo->prepare(
                "SELECT filename FROM images
                  WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1
                  ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 1"
            );
            $st->execute([(string)$ad['id']]);
            $filename = trim((string)$st->fetchColumn());
        } catch (Throwable $e) {
            return '';
        }

        if ($filename === '') {
            return '';
        }

        $relative = ltrim(str_replace('\\', '/', $filename), '/');
        if (strpos($relative, 'uploads/') !== 0) {
            $relative = 'uploads/' . basename($relative);
        }

        if (!is_file(__DIR__ . '/' . $relative)) {
            return '';
        }

        return melkinoAppUrl($relative);
    }
}

/**
 * خروجی JSONِ ایمن برای نشستن داخل <script>
 *
 * چرا لازم است؟ اگر حتی یک ردیف از دیتابیس بایتِ نامعتبر UTF-8 داشته
 * باشد (متن کپی‌شده از Word، ایموجیِ نصفه، ستون با encoding قدیمی)،
 * json_encode مقدار false برمی‌گرداند و echo آن «هیچ» چاپ می‌کند؛
 * نتیجه می‌شود `let data = ;` یعنی خطای سینتکس که کل بلوکِ اسکریپت
 * (و همه‌ی توابع داخلش مثل switchTab پنل ادمین) را از کار می‌اندازد.
 * این تابع هرگز خروجی خالی نمی‌دهد: اول با پرچمِ جایگزینیِ کاراکتر
 * نامعتبر سعی می‌کند، نشد رشته‌ها را پاک‌سازی می‌کند، و در بدترین
 * حالت یک مقدار معتبر JS ([] یا null) برمی‌گرداند.
 */
if (!function_exists('melkinoJsJson')) {
    function melkinoJsJson($value, int $extraFlags = 0): string
    {
        // SECFIX(C1): این خروجی همیشه داخل <script> تزریق می‌شود؛ بدون HEX_TAG
        // رشته‌ای مثل </script> بلاک اسکریپت را می‌بست (Stored XSS روی
        // property-details و پنل ادمین). هر چهار HEX همیشه روشن‌اند تا در
        // همهٔ بافت‌ها (script/attribute) امن باشد؛ خروجی برای JSON/JS معتبر می‌ماند.
        $flags = JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | $extraFlags;

        // پرچمِ جایگزینیِ بایت نامعتبر از PHP 7.2 به بعد وجود دارد
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($value, $flags);
        if (is_string($json)) {
            return $json;
        }

        // پاک‌سازی بازگشتی: حذف بایت‌های نامعتبر UTF-8 از رشته‌ها
        $clean = function ($v) use (&$clean) {
            if (is_array($v)) {
                return array_map($clean, $v);
            }
            if (is_string($v)) {
                return mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
            return $v;
        };
        $json = json_encode($clean($value), $flags);
        if (is_string($json)) {
            return $json;
        }

        return is_array($value) ? '[]' : 'null';
    }
}

/**
 * نرمال‌سازی شماره موبایل به فرمت استانداردِ ۰۹xxxxxxxxx
 *
 * اعداد فارسی/عربی را به انگلیسی تبدیل می‌کند، فاصله و خط تیره را حذف
 * می‌کند و پیش‌شماره‌های +98 / 0098 / 98 را به ۰۹ تبدیل می‌کند.
 * اگر شماره معتبر نباشد، همان رشته‌ی ورودی (پاک‌شده) برگردانده می‌شود.
 */
if (!function_exists('melkinoNormalizePhone')) {
    function melkinoNormalizePhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // اعداد فارسی و عربی ← انگلیسی
        $value = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $value
        );

        // حذف هرچه رقم نیست
        $value = preg_replace('/[^0-9+]/', '', $value) ?? '';

        // +98 / 0098 / 98  →  09
        if (strpos($value, '+98') === 0) {
            $value = '0' . substr($value, 3);
        } elseif (strpos($value, '0098') === 0) {
            $value = '0' . substr($value, 4);
        } elseif (strpos($value, '98') === 0 && strlen($value) > 10) {
            $value = '0' . substr($value, 2);
        }

        return $value;
    }
}

function melkinoCurrentIdentity(?string $telegramId = null): array
{
    global $pdo;
    // نکته‌ی امنیتی: قبلاً پارامتر $telegramId مستقیماً از $_GET/$_POST
    // در صفحاتی مثل my-properties.php، favorites.php و notifications.php
    // خونده و اینجا بدون هیچ اعتبارسنجی‌ای پذیرفته می‌شد؛ یعنی هرکسی با
    // تغییر آدرس (?telegram_id=...) می‌تونست ملک‌ها/علاقه‌مندی‌های
    // شخص دیگه‌ای رو ببینه. حالا این پارامتر نادیده گرفته می‌شه و
    // هویت فقط از سشن سروری (که خودِ سرور قبلاً تأییدش کرده) خونده می‌شه.
    $telegramId = trim((string)($_SESSION['reg_telegram_id'] ?? ''));
    $baleId = trim((string)($_SESSION['reg_bale_id'] ?? ''));
    $eitaaId = trim((string)($_SESSION['reg_eitaa_id'] ?? ''));
    $phone = trim((string)($_SESSION['user_phone'] ?? ''));
    $userId = null;

    // راند ۳۰: ستون‌های وضعیت شماره (phone_verified / phone_locked) به
    // ردیف کاربر اضافه می‌شوند. اگر ستون‌ها هنوز ساخته نشده باشند،
    // کوئری گسترده خطا می‌دهد و به کوئری ساده برمی‌گردیم.
    if (function_exists('melkinoEnsureUserProfileColumns')) {
        melkinoEnsureUserProfileColumns();
    }
    $cols = 'id, telegram_id, phone, name, username, phone_verified, phone_locked, photo_url, telegram_username, first_name, last_name, name_locked';
    $colsBasic = 'id, telegram_id, phone, name, username';

    if ($pdo instanceof PDO) {
        $sessionUid=(int)($_SESSION['user_id']??$_SESSION['melkino_user_id']??0);
        if($sessionUid>0){
            $st=$pdo->prepare("SELECT $cols, bale_id, eitaa_id, is_active FROM users WHERE id=? LIMIT 1");$st->execute([$sessionUid]);$sessionUser=$st->fetch(PDO::FETCH_ASSOC);
            if($sessionUser){
                if(empty($sessionUser['is_active']))return ['user_id'=>null,'telegram_id'=>'','phone'=>'','user'=>null];
                return ['user_id'=>$sessionUid,'telegram_id'=>(string)($sessionUser['telegram_id']??''),'bale_id'=>(string)($sessionUser['bale_id']??''),'eitaa_id'=>(string)($sessionUser['eitaa_id']??''),'phone'=>(string)($sessionUser['phone']??''),'user'=>$sessionUser];
            }
        }
        if ($telegramId !== '') {
            $user = null;
            try {
                $stmt = $pdo->prepare("SELECT $cols FROM users WHERE telegram_id = ? LIMIT 1");
                $stmt->execute([$telegramId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("SELECT $colsBasic FROM users WHERE telegram_id = ? LIMIT 1");
                $stmt->execute([$telegramId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($user) {
                $userId = (int)$user['id'];
                // راند ۳۰: شمارهٔ ذخیره‌شده در دیتابیس بر سشن اولویت دارد؛
                // اگر ادمین شماره را حذف کرده باشد، سشنِ بازِ کاربر نباید
                // شمارهٔ پاک‌شده را زنده نگه دارد.
                $dbPhone = trim((string)($user['phone'] ?? ''));
                $phone = $dbPhone !== '' ? $dbPhone : '';
                return ['user_id' => $userId, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => $user];
            }
        }

        if ($baleId !== '') {
            $user = null;
            try {
                $stmt = $pdo->prepare("SELECT id, bale_id, phone, name, username, phone_verified, phone_locked, photo_url FROM users WHERE bale_id = ? LIMIT 1");
                $stmt->execute([$baleId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("SELECT id, bale_id, phone, name, username FROM users WHERE bale_id = ? LIMIT 1");
                $stmt->execute([$baleId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($user) {
                $userId = (int)$user['id'];
                $dbPhone = trim((string)($user['phone'] ?? ''));
                $phone = $dbPhone !== '' ? $dbPhone : '';
                return ['user_id' => $userId, 'telegram_id' => '', 'bale_id' => $baleId, 'phone' => $phone, 'user' => $user];
            }
        }

        if ($eitaaId !== '') {
            $user = null;
            try {
                $stmt = $pdo->prepare("SELECT id, eitaa_id, phone, name, username, phone_verified, phone_locked, photo_url FROM users WHERE eitaa_id = ? LIMIT 1");
                $stmt->execute([$eitaaId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $stmt = $pdo->prepare("SELECT id, eitaa_id, phone, name, username FROM users WHERE eitaa_id = ? LIMIT 1");
                    $stmt->execute([$eitaaId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) {
                    $user = null;
                }
            }
            if ($user) {
                $userId = (int)$user['id'];
                $dbPhone = trim((string)($user['phone'] ?? ''));
                $phone = $dbPhone !== '' ? $dbPhone : '';
                return ['user_id' => $userId, 'telegram_id' => '', 'eitaa_id' => $eitaaId, 'phone' => $phone, 'user' => $user];
            }
        }

        if ($phone !== '') {
            $user = null;
            try {
                $stmt = $pdo->prepare("SELECT $cols FROM users WHERE phone = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$phone]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $stmt = $pdo->prepare("SELECT $colsBasic FROM users WHERE phone = ? ORDER BY id DESC LIMIT 1");
                $stmt->execute([$phone]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($user) {
                $userId = (int)$user['id'];
                $telegramId = $telegramId !== '' ? $telegramId : trim((string)($user['telegram_id'] ?? ''));
                return ['user_id' => $userId, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => $user];
            }
        }
    }

    return ['user_id' => null, 'telegram_id' => $telegramId, 'phone' => $phone, 'user' => null];
}

if (!function_exists('melkinoIssueTokenForVerifiedPhone')) {
    function melkinoIssueTokenForVerifiedPhone(string $phone, ?string $name = null): array
    {
        global $pdo;

        $phone = trim($phone);
        $name = trim((string)$name);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        if ($phone === '' || !($pdo instanceof PDO)) {
            return ['id' => null, 'token' => null];
        }

        $existing = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $existing->execute([$phone]);
        $id = $existing->fetchColumn();

        // یک کد یک‌بارمصرفِ درست، خودش قوی‌ترین اثباتِ مالکیت شماره‌ست؛
        // بنابراین همیشه یک توکن دسترسی تازه صادر می‌شود (even اگر قبلاً
        // توکنی برای مرورگر دیگری صادر شده بود) — این دقیقاً معادل
        // «ورود مجدد با احراز هویت واقعی» است.
        $newToken = bin2hex(random_bytes(24));

        // راند ۳۰: کد یک‌بارمصرفِ درست = اثبات مالکیت شماره؛ پس شماره
        // تأییدشده و قفل‌شده علامت می‌خورد (فقط ادمین می‌تواند بازش کند).
        if ($id === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO users (phone, name, access_token, last_ip, first_login, last_login, login_count, is_active, phone_verified, phone_locked)
                 VALUES (?, ?, ?, ?, NOW(), NOW(), 1, 1, 1, 1)"
            );
            // HARDEN-01: هش ذخیره می‌شود؛ خام پایین‌تر برای کوکی برمی‌گردد.
            $stmt->execute([$phone, $name !== '' ? $name : null, hash('sha256', $newToken), $ip]);
            $id = (int)$pdo->lastInsertId();
            melkinoMaybeSendWelcome($pdo, true, $id, '');
        } else {
            $id = (int)$id;
            try {
                $stmt = $pdo->prepare(
                    "UPDATE users SET access_token = ?, name = IF(? <> '', ?, name), last_ip = ?, last_login = NOW(), login_count = login_count + 1, phone_verified = 1, phone_locked = 1 WHERE id = ?"
                );
                $stmt->execute([hash('sha256', $newToken), $name, $name, $ip, $id]);
            } catch (Throwable $e) {
                // ستون‌های راند ۳۰ هنوز ساخته نشده‌اند؛ مسیر قدیمی
                $stmt = $pdo->prepare(
                    "UPDATE users SET access_token = ?, name = IF(? <> '', ?, name), last_ip = ?, last_login = NOW(), login_count = login_count + 1 WHERE id = ?"
                );
                $stmt->execute([hash('sha256', $newToken), $name, $name, $ip, $id]);
            }
        }

        $sessionUid = 0;
        if (function_exists('melkinoCurrentIdentity')) {
            try {
                $curId = (int) (melkinoCurrentIdentity()['user_id'] ?? 0);
                if ($curId > 0 && $curId !== (int) $id) {
                    $sessionUid = $curId;
                }
            } catch (Throwable $eSid) {
            }
        }
        if ($sessionUid > 0 && function_exists('melkinoClaimPhoneOnUser')) {
            $linked = melkinoClaimPhoneOnUser($sessionUid, $phone);
            if (!empty($linked['ok']) && !empty($linked['user_id'])) {
                $id = (int) $linked['user_id'];
            }
        }

        return ['id' => $id, 'token' => $newToken];
    }
}

function melkinoJsonResponse(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = '{"success":false,"message":"خروجی نامعتبر است."}';
        http_response_code(500);
    }
    echo $json;
    exit;
}

/**
 * --------------------------------------------------------------------------
 * اطلاعات وامِ آگهی (فروش / پیش‌فروش)
 * --------------------------------------------------------------------------
 * یک تابع مشترک برای همه‌ی صفحه‌ها (کارت‌ها، جزئیات، پیام انتشار) تا منطقِ
 * «قیمت کامل» و «قیمت منهای وام + وام» در کل سایت یکسان بماند.
 *
 * خروجی:
 *  has        bool   — آیا این آگهی وامِ قابل‌نمایش دارد؟
 *  amount     float  — مبلغ وام (تومان)
 *  price      float  — قیمتِ مرجع (price_sell برای فروش، total_price برای پیش‌فروش)
 *  net        float  — قیمت منهای مبلغ وام (فقط وقتی has=true و نتیجه > 0)
 *  type/duration/bank/installment/paid/notes — رشته‌های خامِ فیلدهای وام
 */
if (!function_exists('melkinoLoanInfo')) {
    function melkinoLoanInfo(array $ad): array
    {
        $none = [
            'has' => false, 'amount' => 0.0, 'price' => 0.0, 'net' => 0.0,
            'type' => '', 'duration' => '', 'bank' => '', 'installment' => '',
            'paid' => '', 'notes' => '',
        ];

        // تبدیل رقم‌های فارسی/عربی و حذف جداکننده‌ها
        $toNum = static function ($value): float {
            $s = str_replace(
                ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،',',',' ','تومان'],
                ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','','','','',''],
                trim((string)$value)
            );
            return is_numeric($s) ? (float)$s : 0.0;
        };

        if (empty($ad['has_loan'])) {
            return $none;
        }

        $amount = $toNum($ad['loan_amount'] ?? '');
        if ($amount <= 0) {
            return $none;
        }

        $tx = trim((string)($ad['transaction_type'] ?? ''));
        $price = 0.0;
        if ($tx === 'پیش فروش') {
            $price = $toNum($ad['total_price'] ?? '');
        } else {
            // وام فقط برای فروش/پیش‌فروش معنا دارد؛ بقیه معامله‌ها نادیده گرفته می‌شوند
            if ($tx !== '' && $tx !== 'فروش') {
                return $none;
            }
            $price = $toNum($ad['price_sell'] ?? '');
        }

        $net = $price - $amount;
        if ($price <= 0 || $net <= 0) {
            // قیمت معتبر نیست یا وام از قیمت بیشتر است؛ فقط مبلغ وام را نشان بده
            $net = 0.0;
        }

        return [
            'has'         => true,
            'amount'      => $amount,
            'price'       => $price,
            'net'         => $net,
            'type'        => trim((string)($ad['loan_type'] ?? '')),
            'duration'    => trim((string)($ad['loan_duration'] ?? '')),
            'bank'        => trim((string)($ad['loan_bank'] ?? '')),
            'installment' => trim((string)($ad['loan_installment'] ?? '')),
            'paid'        => trim((string)($ad['loan_installments_paid'] ?? '')),
            'notes'       => trim((string)($ad['loan_notes'] ?? '')),
        ];
    }
}

function melkinoMoney($value): string
{
    $raw = str_replace(',', '', trim((string)$value));
    if ($raw === '' || !is_numeric($raw) || (float)$raw == 0.0) {
        return '';
    }
    return number_format((float)$raw, 0, '.', ',');
}

function melkinoNumber($value): string
{
    $raw = str_replace(',', '', trim((string)$value));
    if ($raw === '' || !is_numeric($raw)) return '';
    $num = (float)$raw;
    if ((int)$num == $num) return number_format($num, 0, '.', ',');
    return rtrim(rtrim(number_format($num, 2, '.', ','), '0'), '.');
}

/**
 * ارسال اعلان برای کاربر
 * 
 * @param int|null $userId
 * @param string|null $telegramId
 * @param string $type
 * @param string $title
 * @param string $message
 * @param string|null $url
 * @param string|null $adId
 * @param int|null $requestId
 * @param int|null $matchPercent
 * @return bool
 */
function sendNotification($userId, $telegramId, $type, $title, $message, $url = null, $adId = null, $requestId = null, $matchPercent = null) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        return false;
    }
    if (empty($userId) && empty($telegramId)) {
        return false;
    }
    // راند ۲۰: گیت رویداد — اگر ادمین این نوع اعلان را خاموش کرده باشد، بی‌صدا رد می‌شود
    if (function_exists('melkinoNotificationEventEnabled') && !melkinoNotificationEventEnabled((string)$type)) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, telegram_id, type, title, message, url, ad_id, request_id, match_percent, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([
        $userId ?: null,
        $telegramId ?: null,
        $type,
        $title,
        $message,
        $url,
        $adId ?: null,
        $requestId ?: null,
        $matchPercent !== null ? (int)$matchPercent : null
    ]);
}

/**
 * آیا اعلان‌های رویدادی فعال‌اند؟ (تنظیم عمومی enable_notifications)
 */
function melkinoEventsEnabled(): bool
{
    try {
        global $pdo;
        if (!function_exists('dbSettingGet') || !($pdo instanceof PDO)) {
            return true;
        }
        return (bool)dbSettingGet($pdo, 'global', 'enable_notifications', true);
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * ارسال اعلان به صاحب یک شماره موبایل.
 *
 * آگهی‌ها ستون user_id ندارند و مالک با شماره تماس پیدا می‌شود؛ این تابع
 * شماره را نرمال می‌کند، کاربر متناظر را از جدول users پیدا می‌کند و اعلان
 * را برایش ثبت می‌کند. اگر کاربری پیدا نشود یا اعلان‌ها خاموش باشند،
 * بی‌صدا false برمی‌گردد (روند اصلی نباید به‌خاطر اعلان بشکند).
 */
function melkinoNotifyByPhone(string $phone, string $type, string $title, string $message, ?string $url = null, $adId = null, $requestId = null): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    if (!melkinoEventsEnabled()) {
        return false;
    }

    $normalized = function_exists('melkinoNormalizePhone')
        ? melkinoNormalizePhone($phone)
        : trim($phone);
    if ($normalized === '') {
        return false;
    }

    // شماره‌ها ممکن است با فرمت‌های مختلف ذخیره شده باشند
    $candidates = [$normalized];
    if (strpos($normalized, '0') === 0 && strlen($normalized) > 1) {
        $candidates[] = substr($normalized, 1);
        $candidates[] = '98' . substr($normalized, 1);
    } elseif (strpos($normalized, '98') === 0) {
        $candidates[] = '0' . substr($normalized, 2);
    } else {
        $candidates[] = '0' . $normalized;
    }
    $candidates = array_values(array_unique($candidates));

    try {
        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, telegram_id FROM users WHERE phone IN ($placeholders) ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute($candidates);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return sendNotification(
            (int)$row['id'],
            !empty($row['telegram_id']) ? (string)$row['telegram_id'] : null,
            $type,
            $title,
            $message,
            $url,
            $adId,
            $requestId
        );
    } catch (Throwable $e) {
        return false;
    }
}


/* =========================================================
   melkinoPeelJsonValue — باز کردن «پیازِ escape»
   ---------------------------------------------------------
   باگ راند ۱۵: هندلر ذخیرهٔ پنل ادمین (bulk_sync) رشتهٔ
   tags/custom_fields را که خودش JSON بود دوباره json_encode
   می‌کرد؛ با هر بار ذخیره یک لایه escape اضافه می‌شد و طول
   مقدار به‌صورت نمایی رشد می‌کرد (چند مگابایت برای یک آگهی!).
   نتیجه: خروجی properties-data.php از حد حافظه/خروجی هاست رد
   می‌شد، JSON بریده می‌شد و صفحهٔ «همهٔ آگهی‌ها» خطای
   «اتصال دیتابیس» نشان می‌داد در حالی که دیتابیس سالم بود.
   این تابع هر مقدار را لایه‌لایه decode می‌کند تا به هستهٔ
   اصلی (آرایه/عدد/…) برسد.
   ========================================================= */
if (!function_exists('melkinoPeelJsonValue')) {
    function melkinoPeelJsonValue($value, int $maxDepth = 80)
    {
        $v = $value;
        for ($i = 0; $i < $maxDepth; $i++) {
            if (!is_string($v)) {
                break;
            }
            $t = trim($v);
            if ($t === '') {
                return [];
            }
            $d = json_decode($t, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // JSON نیست — متن ساده است؛ همان را برمی‌گردانیم
                return $v;
            }
            $v = $d;
        }
        return $v;
    }
}

if (!function_exists('melkinoNormalizeJsonColumn')) {
    /**
     * مقدار یک ستون JSON را به «آرایهٔ تمیز» تبدیل می‌کند.
     * خروجی همیشه آرایه است (برای ستون‌هایی مثل tags که
     * خواننده‌ها آرایه انتظار دارند).
     */
    function melkinoNormalizeJsonColumn($value): array
    {
        $v = melkinoPeelJsonValue($value);
        if (is_array($v)) {
            return $v;
        }
        // اسکالر/رشتهٔ باقی‌مانده (مثلاً JSON بریده‌شدهٔ بی‌اعتبار یا متن
        // ساده) برای این ستون‌ها بی‌معنی است — همهٔ خواننده‌ها آرایه می‌خواهند.
        return [];
    }
}

/* =========================================================
   melkinoLogChannelPublish — لاگ انتشار در کانال (راند ۱۸)
   هر بار انتشار (موفق یا ناموفق) در تلگرام/بله ثبت می‌شود تا
   ادمین بداند هر آگهی چند بار و کِی منتشر شده است.
   جدول در صورت نبود، به‌صورت خودکار ساخته می‌شود.
   ========================================================= */
if (!function_exists('melkinoLogChannelPublish')) {
    function melkinoLogChannelPublish($pdo, string $adId, string $platform, bool $success, $messageId = null, string $note = ''): void
    {
        if (!($pdo instanceof PDO) || $adId === '') {
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS channel_publish_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ad_id VARCHAR(40) NOT NULL,
                    platform VARCHAR(10) NOT NULL,
                    success TINYINT(1) NOT NULL DEFAULT 0,
                    message_id VARCHAR(60) NULL,
                    note VARCHAR(255) NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cpl_ad (ad_id),
                    INDEX idx_cpl_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $platform = strtolower(trim($platform)) === 'bale' ? 'bale' : 'telegram';
            $st = $pdo->prepare(
                'INSERT INTO channel_publish_logs (ad_id, platform, success, message_id, note) VALUES (?,?,?,?,?)'
            );
            $st->execute([
                $adId,
                $platform,
                $success ? 1 : 0,
                $messageId !== null && $messageId !== '' ? (string)$messageId : null,
                $note !== '' ? mb_substr($note, 0, 250) : null,
            ]);
        } catch (Throwable $e) {
            // لاگ اختیاری است؛ هیچ‌وقت جریان انتشار را نمی‌شکند
        }
    }
}

/* =========================================================
   راند ۳۵ — لینک و دکمهٔ مشترک «باز کردن تلگرام کاربر»
   یک‌جا این‌جا تعریف می‌شود و همهٔ صفحه‌ها از همین استفاده می‌کنند
   (بدون کد تکراری در صفحه‌ها). بدون تغییر schema/API/منطق ورود.
   ========================================================= */

/**
 * لینک عمیق تلگرام بر پایهٔ آیدی عددی.
 * فقط مقدار کاملاً عددی و مثبت پذیرفته می‌شود؛ در غیر این صورت null.
 */
if (!function_exists('melkinoTelegramProfileLink')) {
    function melkinoTelegramProfileLink($telegramId): ?string
    {
        $id = trim((string)$telegramId);
        if ($id === '' || !ctype_digit($id) || strlen($id) > 20) {
            return null;
        }
        if ((int)$id <= 0) {
            return null;
        }
        return 'tg://user?id=' . $id;
    }
}

/** نام مستعار مطابق مشخصات راند ۳۵ */
if (!function_exists('getTelegramProfileLink')) {
    function getTelegramProfileLink($telegramId): ?string
    {
        return melkinoTelegramProfileLink($telegramId);
    }
}

/**
 * لینک عمومی بر پایهٔ username (بدون @).
 * اعتبارسنجی سخت‌گیرانه: حرف لاتین اول، سپس ۴ تا ۳۱ حرف/عدد/زیرخط.
 */
if (!function_exists('melkinoTelegramUsernameLink')) {
    function melkinoTelegramUsernameLink($username): ?string
    {
        $u = ltrim(trim((string)$username), '@');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $u)) {
            return null;
        }
        return 'https://t.me/' . rawurlencode($u);
    }
}

/** لینک نهایی برای باز کردن: اولویت با username معتبر، وگرنه آیدی عددی */
if (!function_exists('melkinoTelegramOpenLink')) {
    function melkinoTelegramOpenLink($telegramId, $username = ''): ?string
    {
        $byUser = melkinoTelegramUsernameLink($username);
        if ($byUser !== null) {
            return $byUser;
        }
        return melkinoTelegramProfileLink($telegramId);
    }
}

/**
 * کامپوننت مشترک دکمهٔ «باز کردن تلگرام» (HTML ایمن).
 * - icon_only: فقط آیکون کوچک برای جدول‌ها (اگر لینکی نباشد: هیچ)
 * - حالت کامل: دکمهٔ متنی؛ اگر هیچ لینکی نباشد دکمهٔ غیرفعال
 *   «تلگرام متصل نیست» (مناسب پنل ادمین)
 * - اگر username و آیدی هر دو موجود باشند، دکمهٔ کوچک «لینک عمیق»
 *   هم کنارش می‌آید تا قابلیت ID حفظ شود.
 * خروجی با htmlspecialchars ایمن شده؛ href فقط از مقدار اعتبارسنجی‌شده ساخته می‌شود.
 */
if (!function_exists('melkinoTelegramButtonHtml')) {
    function melkinoTelegramButtonHtml($telegramId, $username = '', array $opts = []): string
    {
        $iconOnly = !empty($opts['icon_only']);
        $label = (string)($opts['label'] ?? 'باز کردن تلگرام');
        $tgLink = melkinoTelegramProfileLink($telegramId);
        $uClean = ltrim(trim((string)$username), '@');
        $uLink = melkinoTelegramUsernameLink($uClean);
        $href = $uLink ?? $tgLink;
        $icon = '<svg class="mk-icon mk-icon--sm" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>';

        if ($href === null) {
            if ($iconOnly) {
                return '';
            }
            return '<span class="mk-btn mk-btn--sm mk-btn--outline mk-tg-off" aria-disabled="true">' . $icon . ' تلگرام متصل نیست</span>';
        }

        $eU = htmlspecialchars($uClean, ENT_QUOTES, 'UTF-8');
        $title = $uLink !== null ? ('باز کردن تلگرام (@' . $eU . ')') : 'باز کردن تلگرام با آیدی عددی';
        $out = '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-btn' . ($iconOnly ? ' mk-tg-icon' : '') . '" data-tg-open="1" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" title="' . $title . '" aria-label="' . $title . '">' . $icon;
        if (!$iconOnly) {
            $out .= ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        }
        $out .= '</a>';

        if (!$iconOnly && $uLink !== null && $tgLink !== null) {
            $out .= ' <a class="mk-btn mk-btn--sm mk-btn--ghost mk-tg-icon" data-tg-open="1" href="' . htmlspecialchars($tgLink, ENT_QUOTES, 'UTF-8') . '" title="لینک عمیق با آیدی عددی" aria-label="لینک عمیق با آیدی عددی">' . $icon . '</a>';
        }
        return $out;
    }
}

/**
 * راند ۳۷: آیکون SVG مشترک سمت سرور برای برچسب‌ها/دکمه‌های PHP.
 * یک نقشهٔ مرکزی؛ همهٔ صفحه‌های PHP از همین تابع استفاده می‌کنند.
 */
if (!function_exists('melkinoSvgIcon')) {
    function melkinoSvgIcon(string $name, string $cls = 'mk-icon mk-icon--sm'): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'save'     => '<path d="M5 3h11l3 3v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 3v5h7V3"/><path d="M8 21v-7h8v7"/>',
                'trash'    => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/>',
                'eye'      => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/>',
                'x'        => '<path d="M6 6l12 12M18 6 6 18"/>',
                'upload'   => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14"/>',
                'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
                'bulb'     => '<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.8.7 1 1.5 1 2.5h6c0-1 .2-1.8 1-2.5A6 6 0 0 0 12 3Z"/>',
                'lock'     => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
                'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/>',
                'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
                'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.4 2.9-5.5 6.5-5.5s6.5 2.1 6.5 5.5"/><circle cx="17" cy="9" r="3"/><path d="M17.5 14.6c2.4.5 4 2.2 4 4.4"/>',
                'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
                'headset'  => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/>',
                'puzzle'   => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
                'map'      => '<path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2-6-2Z"/><path d="M9 4v14M15 6v14"/>',
                'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-4.5-4.5L7 21"/>',
                'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
                'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
                'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18Z"/>',
                'warn'     => '<path d="M10.3 3.8 2.3 17.8A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3.2l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v5"/><path d="M12 17.5h.01"/>',
                'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
                'play'      => '<path d="M8 5.5v13l10.5-6.5z"/>',
                'restore'   => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
                'shield'    => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/>',
                'inbox'     => '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M4 13V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6"/><path d="M4 13v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
                'megaphone'  => '<path d="M4 10v4h3l8 4V6l-8 4z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
                'plus'      => '<path d="M12 5v14M5 12h14"/>',
                'bell'      => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2.5 2.5 0 0 0 4 0"/>',
                'send'      => '<path d="M21 3 3 10.5l7 3 3 7z"/><path d="M21 3 10 13.5"/>',
                'share'     => '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="m8.2 10.8 7.6-3.6m-7.6 6 7.6 3.6"/>',
                'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                'star'      => '<path d="m12 3 2.7 5.8 6.3.8-4.6 4.3 1.2 6.1L12 17l-5.6 3 1.2-6.1L3 9.6l6.3-.8z"/>',
                'tag'       => '<path d="M3 3h8l10 10-8 8L3 11z"/><path d="M7.5 7.5h.01"/>',
                'pin'       => '<path d="M9 4h6l-1 7 3 3v2H7v-2l3-3z"/><path d="M12 16v5"/>',
                'compass'   => '<circle cx="12" cy="12" r="9"/><path d="m15 9-2 5-4 1 2-5z"/>',
                'plug'      => '<path d="M9 3v6M15 3v6"/><path d="M6 9h12v3a6 6 0 0 1-12 0z"/><path d="M12 18v3"/>',
                'chat'      => '<path d="M4 5h16v11H9l-5 4z"/>',
                'receipt'   => '<path d="M6 3h12v18l-2-1.5L14 21l-2-1.5L10 21l-2-1.5L6 21z"/><path d="M9 8h6M9 12h6"/>',
                'bot'       => '<rect x="5" y="8" width="14" height="11" rx="2"/><path d="M12 8V4M9 4h6"/><path d="M9.5 13h.01M14.5 13h.01"/>',
                'bank'      => '<path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18M12 3 3 10h18z"/>',
                'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>',
                'moon'      => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
                'contrast'  => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z"/>',
                'palette'   => '<path d="M12 3a9 9 0 1 0 .5 18c1.5 0 2-1 1.5-2s0-2 1.5-2H18a3 3 0 0 0 3-3c0-6-4-11-9-11z"/><path d="M7.5 10h.01M11 7h.01M15 8h.01"/>',
                'pause'     => '<path d="M9 5v14M15 5v14"/>',
                'check'     => '<path d="m5 12.5 4.5 4.5L19 7"/>',
                'home'     => '<path d="M4 11l8-7 8 7"/><path d="M6 9.5V21h12V9.5"/>',
                'ruler'     => '<path d="M3 17 17 3l4 4L7 21z"/><path d="M8 16l1.5 1.5M11 13l1.5 1.5M14 10l1.5 1.5"/>',
                'bed'       => '<path d="M3 18v-8h13a5 5 0 0 1 5 5v3"/><path d="M3 14h18"/><path d="M6 10V7h6v3"/>',
                'building'  => '<rect x="6" y="3" width="12" height="18"/><path d="M10 7h1M13 7h1M10 11h1M13 11h1M10 15h1M13 15h1"/>',
                'parking'   => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3"/>',
                'elevator'  => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9 10 1.5-2L12 10M15 14l-1.5 2L12 14"/>',
                'scale'    => '<path d="M12 3v18"/><path d="M6 7h12"/><path d="m6 7-3 6a3 3 0 0 0 6 0z"/><path d="m18 7-3 6a3 3 0 0 0 6 0z"/><path d="M8 21h8"/>',
                'sparkles'  => '<path d="M12 3l1.8 4.2L18 9l-4.2 1.8L12 15l-1.8-4.2L6 9l4.2-1.8z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/>',
                'chart'     => '<path d="M5 20v-6M11 20V8M17 20v-10"/><path d="M3 20h18"/>',
                'shuffle'   => '<path d="M3 7h4l10 10h4"/><path d="M3 17h4l10-10h4"/><path d="m18 4 3 3-3 3M18 14l3 3-3 3"/>',
                'calc'      => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 12h.01M12 12h.01M15 12h.01M9 16h.01M12 16h.01M15 16h.01"/>',
                'calendar'  => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
                'key'       => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l3 3"/>',
                'coins'    => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
            ];
        }
        if (!isset($map[$name])) {
            return '';
        }
        $wh = strpos($cls, 'mk-icon--sm') !== false ? ' width="16" height="16"' : (strpos($cls, 'mk-icon--lg') !== false ? ' width="26" height="26"' : ' width="20" height="20"');
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $wh . ' viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-3px;" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $map[$name] . '</svg>';
    }
}


if (!function_exists('melkinoIconOrText')) {
    /**
     * راند ۴۱ (S13): مقدار آیکونِ تنظیمات (که تاریخی به‌صورت ایموجی ذخیره شده)
     * را به SVG خطی تبدیل می‌کند؛ مقدار ناشناخته/سفارشی همان‌گونه (escape‌شده) برمی‌گردد.
     * بدون مهاجرت داده: کلیدهای ذخیره‌شده در تنظیمات دست‌نخورده می‌مانند.
     */
    function melkinoIconOrText(string $icon): string
    {
        $icon = trim($icon);
        if ($icon === '') return '';
        $map = [
            '🏦' => 'bank',
            '💰' => 'coins',
            '🏠' => 'home',
            '📋' => 'list',
            '📍' => 'pin',
            '⭐' => 'star',
            '📞' => 'phone',
            '⚖️' => 'scale',
            '🏷️' => 'tag',
            '📐' => 'ruler',
            '🛏️' => 'bed',
            '🏢' => 'building',
            '📅' => 'calendar',
            '🅿️' => 'parking',
            '🛗' => 'elevator',
            '🔑' => 'key',
            '🔄' => 'restore',
            '🏅' => 'star',
            '↔️' => 'ruler',
            '↕️' => 'ruler',
            '' => 'ruler',
        ];
        if (isset($map[$icon])) return melkinoSvgIcon($map[$icon]);
        return htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * راند ۴۹: ارسال اعلان به مالک آگهی (بر اساس user_id / telegram_id / شماره).
 * برای رویدادهای مرتبط با آگهی که مالک مستقیم در جدول users نیست.
 */
if (!function_exists('melkinoNotifyAdOwner')) {
    function melkinoNotifyAdOwner(string $adId, string $type, string $title, string $message, ?string $url = 'my-properties.php'): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || $adId === '') {
            return false;
        }
        try {
            $st = $pdo->prepare('SELECT owner_user_id, user_id, telegram_id, phone FROM ads WHERE id=? LIMIT 1');
            $st->execute([$adId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            $userId = (int)($row['owner_user_id'] ?: $row['user_id'] ?: 0);
            $tg = trim((string)($row['telegram_id'] ?? ''));
            if ($userId > 0 || $tg !== '') {
                return sendNotification($userId > 0 ? $userId : null, $tg !== '' ? $tg : null, $type, $title, $message, $url, $adId);
            }
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone !== '' && function_exists('melkinoNotifyByPhone')) {
                return melkinoNotifyByPhone($phone, $type, $title, $message, $url, $adId);
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * راند ۵۰: ارسال اعلان به صاحب تیکت پشتیبانی (user_id / telegram_id / شماره).
 * متن پیام شامل موضوع تیکت است تا اعلان برای کاربر گویا باشد.
 */
if (!function_exists('melkinoNotifySupportOwner')) {
    function melkinoNotifySupportOwner(int $ticketId, string $type, string $title, string $messageTemplate): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || $ticketId <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare('SELECT user_id, telegram_id, phone, subject FROM support_tickets WHERE id=? LIMIT 1');
            $st->execute([$ticketId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            $message = str_replace('%s', (string)($row['subject'] ?? ''), $messageTemplate);
            $userId = (int)($row['user_id'] ?? 0);
            $tg = trim((string)($row['telegram_id'] ?? ''));
            if ($userId > 0 || $tg !== '') {
                return sendNotification($userId > 0 ? $userId : null, $tg !== '' ? $tg : null, $type, $title, $message, 'support.php');
            }
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone !== '' && function_exists('melkinoNotifyByPhone')) {
                return melkinoNotifyByPhone($phone, $type, $title, $message, 'support.php');
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * راند ۵۴: اسکیمای کاننیکال property_requests + مهاجرت خودکار دیتابیس‌های قدیمی.
 */
if (!function_exists('melkinoDefaultImagesForType')) {
    /**
     * راند ۶۴: عکس‌های پیش‌فرض تزیینی هر نوع ملک (تا ۵ عکس در
     * assets/defaults/) — برای آگهی‌هایی که عکس آپلود نشده است.
     * فقط فایل‌های موجود برگردانده می‌شوند تا آپلود ناقص هم امن باشد.
     */
    function melkinoDefaultImagesForType(string $propertyType): array
    {
        $type = trim($propertyType);
        $slug = '';
        if (mb_strpos($type, 'آپارتمان') !== false) { $slug = 'apartment'; }
        elseif (mb_strpos($type, 'ویلا') !== false) { $slug = 'villa'; }
        elseif (mb_strpos($type, 'تجاری') !== false || mb_strpos($type, 'مغازه') !== false) { $slug = 'shop'; }
        elseif (mb_strpos($type, 'اداری') !== false) { $slug = 'office'; }
        elseif (mb_strpos($type, 'باغ') !== false) { $slug = 'garden'; }
        elseif (mb_strpos($type, 'زمین') !== false) { $slug = 'land'; }
        if ($slug === '') { return []; }
        $out = [];
        for ($i = 1; $i <= 5; $i++) {
            // راند ۶۵: آپلود ادمین ممکن است png/webp باشد — هر پسوند معتبر قبول است
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $melkinoExt) {
                $rel = 'assets/defaults/' . $slug . '-' . $i . '.' . $melkinoExt;
                if (is_file(__DIR__ . '/' . $rel)) { $out[] = $rel; break; }
            }
        }
        return $out;
    }
}

/**
 * راند ۷۳: حل قیمت نمایشی آگهی از همهٔ ستون‌های قیمتی — سازگار با هر دو اسکیمای
 * دیتابیس (جدید: price_sell/total_price/display_price + قدیمی/لگاسی: ستون مستقل price).
 * مقادیر عددی صفر (مثل '0.00' از ستون‌های DECIMAL) نادیده گرفته می‌شوند.
 * برگشتی: رشتهٔ قیمت (بدون فرمت) یا '' اگر قیمت موجود نیست.
 */
if (!function_exists('melkinoAdCardPrice')) {

    /**
     * قیمت نمایشی کارت — همان چیزی که صفحهٔ جزئیات در بخش طلایی قیمت نشان می‌دهد.
     * خروجی خالی یعنی «تماس بگیرید».
     */
    function melkinoAdCardPrice(array $ad): string
    {
        // متن جایگزین قیمت همین آگهی، قبل از هر قالب‌بندی مبلغ
        $__note = trim((string)($ad['price_note'] ?? ''));
        if ($__note !== '') { return $__note; }
        $__pmMsg = function_exists('melkinoPriceMessage') ? melkinoPriceMessage() : '';
        if ($__pmMsg !== '') { return $__pmMsg; }
        if (!empty($ad['price_hidden'])) {
            return '';
        }

        $num = static function ($v): bool {
            $s = str_replace(',', '', trim((string) $v));
            return $s !== '' && is_numeric($s) && (float) $s > 0;
        };
        $fa = static function (string $t): string {
            return strtr($t, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬']);
        };
        $fmt = static function ($v) use ($fa): string {
            $s = str_replace(',', '', trim((string) $v));
            if ($s !== '' && is_numeric($s)) {
                return $fa(number_format((float) $s, 0, '.', ','));
            }
            return trim((string) $v);
        };

        $tx = (string) ($ad['transaction_type'] ?? '');
        $parts = [];
        if ($tx === 'فروش') {
            if ($num($ad['price_sell'] ?? '')) {
                $parts[] = $fmt($ad['price_sell']) . ' تومان';
            }
        } else {
            if (!empty($ad['full_rent_enabled']) && $num($ad['full_rent'] ?? '')) {
                $parts[] = 'رهن کامل: ' . $fmt($ad['full_rent']) . ' تومان';
            }
            if ($num($ad['deposit'] ?? '')) {
                $parts[] = 'ودیعه: ' . $fmt($ad['deposit']) . ' تومان';
            }
            if ($num($ad['rent_monthly'] ?? '')) {
                $parts[] = 'اجاره: ' . $fmt($ad['rent_monthly']) . ' تومان';
            }
        }
        $tp = trim((string) ($ad['total_price'] ?? ''));
        if ($tp !== '' && $tp !== '0' && $tp !== '۰') {
            $parts[] = $fmt($tp) . ' تومان';
        }
        if ($parts) {
            return implode(' | ', $parts);
        }

        /* متن‌های قیمتی صریح */
        $junk = static function (string $t): bool {
            return $t === '' || $t === '0' || $t === '۰'
                || (bool) preg_match('/^[0.,٬،\s]+$/', $t)
                || mb_strpos($t, '|') !== false;
        };
        foreach (['display_price', 'price_sell', 'total_price', 'down_payment', 'payment_terms'] as $k) {
            $t = trim((string) ($ad[$k] ?? ''));
            if ($junk($t)) {
                continue;
            }
            $tn = str_replace(',', '', $t);
            if (is_numeric($tn) && (float) $tn > 0) {
                return $fa(number_format((float) $tn, 0, '.', ',')) . ' تومان';
            }
            return $t;
        }

        /* آخرین پناهگاه: کلیدهای «قیمت» داخل property_details / custom_fields */
        foreach (['property_details', 'details', 'custom_fields'] as $dk) {
            $pd = $ad[$dk] ?? null;
            if (is_string($pd)) {
                $dec = json_decode($pd, true);
                if (is_array($dec)) {
                    $pd = $dec;
                }
            }
            if (!is_array($pd)) {
                continue;
            }
            foreach ($pd as $pk => $pv) {
                $pk = (string) $pk;
                if (mb_strpos($pk, 'قیمت') === false && mb_strpos($pk, 'ودیعه') === false && mb_strpos($pk, 'اجاره') === false) {
                    continue;
                }
                if (is_array($pv)) {
                    $pv = implode('، ', array_map('strval', $pv));
                }
                $t = trim((string) $pv);
                if (!$junk($t)) {
                    return $pk . ': ' . $t;
                }
            }
        }

        return '';
    }
}

if (!function_exists('melkinoAdDisplayPrice')) {
    function melkinoAdDisplayPrice(array $ad): string
    {
        $numeric = static function ($v): float {
            $s = strtr((string)$v, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            $s = str_replace([',', '٬', '،', ' ', 'تومان'], '', $s);
            return is_numeric($s) ? (float)$s : 0.0;
        };
        foreach (['display_price', 'price_sell', 'total_price', 'price'] as $k) {
            $v = trim((string)($ad[$k] ?? ''));
            if ($v !== '' && $numeric($v) > 0) {
                return $v;
            }
        }
        // اجاره/رهن: ودیعه + اجارهٔ ماهانه (فقط اگر واقعاً صفر نباشند)
        $dep = trim((string)($ad['deposit'] ?? ''));
        $rent = trim((string)($ad['rent_monthly'] ?? ''));
        $full = trim((string)($ad['full_rent'] ?? ''));
        $dn = $numeric($dep);
        $rn = $numeric($rent);
        $fn = $numeric($full);
        if ($dn > 0 && $rn > 0) {
            return $dep . ' | ' . $rent;
        }
        if ($dn > 0) {
            return $dep;
        }
        if ($rn > 0) {
            return $rent;
        }
        if ($fn > 0) {
            return $full;
        }
        // قیمت متنی ثبت‌شده (مثل «۱۸ میلیارد» یا «متری ۸۰») — عدد خالص نیست ولی نمایش‌پذیر است
        foreach (['display_price', 'price', 'price_sell', 'total_price'] as $k) {
            $v = trim((string)($ad[$k] ?? ''));
            if ($v !== '' && $v !== '0' && !str_contains($v, '|') && preg_match('/\D/u', strtr($v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']))) {
                return $v;
            }
        }
        return '';
    }
}

if (!function_exists('melkinoDefaultImageForAd')) {
    /** یکی از عکس‌های نوع ملک؛ انتخاب ادمین اول، وگرنه ثابت بر اساس هش شناسه. */
    function melkinoDefaultImageForAd(array $ad): string
    {
        $list = melkinoDefaultImagesForType((string)($ad['property_type'] ?? ''));
        if (!$list) { return ''; }
        // راند ۶۴: انتخاب صریح ادمین اولویت دارد
        $no = (int)($ad['default_image_no'] ?? 0);
        if ($no >= 1 && $no <= count($list)) { return $list[$no - 1]; }
        $id = (string)($ad['id'] ?? '');
        $idx = $id === '' ? 0 : ((int)(crc32($id) % count($list)));
        return $list[$idx];
    }
}

if (!function_exists('melkinoEnsureAdsDefaultImageColumn')) {
    /** ستون انتخاب ادمین برای عکس پیش‌فرض (یک‌بار ALTER در صورت نبود). */
    function melkinoEnsureAdsDefaultImageColumn(PDO $pdo): bool
    {
        static $done = false;
        if ($done) { return true; }
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM ads')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (strtolower((string)$c['Field']) === 'default_image_no') { return $done = true; }
            }
            $pdo->exec('ALTER TABLE ads ADD COLUMN default_image_no TINYINT NULL DEFAULT NULL');
            return $done = true;
        } catch (Throwable $e) {
            return $done = true;
        }
    }
}

if (!function_exists('melkinoDefaultImagesMap')) {
    /** نقشهٔ نوع → فهرست مسیرها برای سمت کلاینت. */
    function melkinoDefaultImagesMap(): array
    {
        global $pdo;
        if ($pdo instanceof PDO) { melkinoEnsureAdsDefaultImageColumn($pdo); }
        $map = [];
        foreach (['آپارتمان', 'ویلا', 'ویلایی', 'تجاری', 'مغازه', 'اداری', 'زمین', 'باغ'] as $melkinoType) {
            $map[$melkinoType] = melkinoDefaultImagesForType($melkinoType);
        }
        return $map;
    }
}

if (!function_exists('melkinoEnsureLoginEventsSchema')) {
    /**
     * راند ۶۱: اسکیمای کاننیکال login_events + مهاجرت زمان‌اجرا.
     * گزارش کاربر: «تاریخچهٔ کاربران خالی» — جدول تولید از ایمپورت اولیه
     * ستون user_id (و بعدها bale_id/username/name/ip_address) را نداشت →
     * هر سه مسیر INSERT auth شکست می‌خوردند و هیچ رخدادی ثبت نمی‌شد.
     */
    function melkinoEnsureLoginEventsSchema(PDO $pdo): bool
    {
        static $done = false;
        if ($done) return true;
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS login_events (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NULL,
                    telegram_id VARCHAR(64) NULL,
                    bale_id VARCHAR(64) NULL,
                    username VARCHAR(191) NULL,
                    name VARCHAR(191) NULL,
                    ip_address VARCHAR(45) NULL,
                    ip VARCHAR(45) NULL,
                    user_agent VARCHAR(1000) NULL,
                    platform VARCHAR(20) NULL,
                    language_code VARCHAR(10) NULL,
                    created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_login_events_user (user_id),
                    KEY idx_login_events_tg (telegram_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $have = [];
            foreach ($pdo->query('SHOW COLUMNS FROM login_events')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[strtolower((string)$c['Field'])] = true;
            }
            $want = [
                'user_id'         => 'INT NULL',
                'telegram_id'     => 'VARCHAR(64) NULL',
                'bale_id'         => 'VARCHAR(64) NULL',
                'eitaa_id'        => 'VARCHAR(64) NULL',
                'username'        => 'VARCHAR(191) NULL',
                'name'            => 'VARCHAR(191) NULL',
                'ip_address'      => 'VARCHAR(45) NULL',
                'ip'              => 'VARCHAR(45) NULL',
                'user_agent'      => 'VARCHAR(1000) NULL',
                'platform'        => 'VARCHAR(20) NULL',
                'language_code'   => 'VARCHAR(10) NULL',
                'created_at'      => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
            ];
            foreach ($want as $col => $ddl) {
                if (!isset($have[$col])) {
                    $pdo->exec('ALTER TABLE login_events ADD COLUMN `' . $col . '` ' . $ddl);
                }
            }
            try {
                $pdo->exec(
                    "UPDATE login_events
                        SET bale_id = telegram_id, telegram_id = NULL
                      WHERE platform = 'bale'
                        AND (bale_id IS NULL OR bale_id = '')
                        AND telegram_id IS NOT NULL AND telegram_id <> ''"
                );
            } catch (Throwable $eBf) {
            }
            try {
                $pdo->exec(
                    "UPDATE users
                        SET bale_id = telegram_id, telegram_id = NULL
                      WHERE last_platform = 'bale'
                        AND (bale_id IS NULL OR bale_id = '')
                        AND telegram_id IS NOT NULL AND telegram_id <> ''"
                );
            } catch (Throwable $eBf2) {
            }
            // بک‌فیل: رخداد های قدیمی بدون user_id را از روی telegram_id به کاربر وصل کن
            $pdo->exec(
                "UPDATE login_events le
                    JOIN users u ON u.telegram_id = le.telegram_id
                    SET le.user_id = u.id
                  WHERE le.user_id IS NULL AND le.telegram_id IS NOT NULL AND le.telegram_id <> ''"
            );
            try {
                $pdo->exec(
                    "UPDATE login_events le
                        JOIN users u ON u.bale_id = le.bale_id
                        SET le.user_id = u.id
                      WHERE le.user_id IS NULL AND le.bale_id IS NOT NULL AND le.bale_id <> ''"
                );
            } catch (Throwable $eBf3) {
            }
            return $done = true;
        } catch (Throwable $e) {
            return $done = true; // دوباره تلاش نمی‌کنیم؛ مسیرهای fallback در auth همچنان کار می‌کنند
        }
    }
}

if (!function_exists('melkinoEnsureRequestSchema')) {
    function melkinoEnsureRequestSchema(): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }
        static $done = false;
        if ($done) {
            return true;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS property_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tracking_code VARCHAR(40) NULL,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                phone VARCHAR(30) NULL,
                gender VARCHAR(10) NULL,
                last_name VARCHAR(120) NULL,
                transaction_type VARCHAR(60) NULL,
                property_type VARCHAR(60) NULL,
                location VARCHAR(255) NULL,
                urgency VARCHAR(40) NULL,
                date_needed VARCHAR(40) NULL,
                rahn_kamal VARCHAR(10) NULL,
                min_area VARCHAR(30) NULL,
                max_area VARCHAR(30) NULL,
                min_price VARCHAR(40) NULL,
                max_price VARCHAR(40) NULL,
                min_deposit VARCHAR(40) NULL,
                max_deposit VARCHAR(40) NULL,
                min_rent VARCHAR(40) NULL,
                max_rent VARCHAR(40) NULL,
                min_age VARCHAR(10) NULL,
                max_age VARCHAR(10) NULL,
                is_not_keyed TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                additional TEXT NULL,
                property_details LONGTEXT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_owner (user_id, telegram_id),
                KEY idx_track (tracking_code),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $cols = $pdo->query('SHOW COLUMNS FROM property_requests')->fetchAll(PDO::FETCH_COLUMN);
            $canonical = [
                'tracking_code'   => 'VARCHAR(40) NULL',
                'gender'          => 'VARCHAR(10) NULL',
                'last_name'       => 'VARCHAR(120) NULL',
                'transaction_type'=> 'VARCHAR(60) NULL',
                'urgency'         => 'VARCHAR(40) NULL',
                'date_needed'     => 'VARCHAR(40) NULL',
                'rahn_kamal'      => 'VARCHAR(10) NULL',
                'min_area'        => 'VARCHAR(30) NULL',
                'max_area'        => 'VARCHAR(30) NULL',
                'min_price'       => 'VARCHAR(40) NULL',
                'max_price'       => 'VARCHAR(40) NULL',
                'min_deposit'     => 'VARCHAR(40) NULL',
                'max_deposit'     => 'VARCHAR(40) NULL',
                'min_rent'        => 'VARCHAR(40) NULL',
                'max_rent'        => 'VARCHAR(40) NULL',
                'min_age'         => 'VARCHAR(10) NULL',
                'max_age'         => 'VARCHAR(10) NULL',
                'is_not_keyed'    => 'TINYINT(1) NOT NULL DEFAULT 0',
                'additional'      => 'TEXT NULL',
                'property_details'=> 'LONGTEXT NULL',
            ];
            $adds = [];
            foreach ($canonical as $col => $ddl) {
                if (!in_array($col, $cols, true)) {
                    $adds[] = "ADD COLUMN `$col` $ddl";
                }
            }
            if ($adds) {
                $pdo->exec('ALTER TABLE property_requests ' . implode(', ', $adds));
                $cols = $pdo->query('SHOW COLUMNS FROM property_requests')->fetchAll(PDO::FETCH_COLUMN);
            }
            $legacyMap = [
                'request_type' => 'transaction_type',
                'budget_min'   => 'min_price',
                'budget_max'   => 'max_price',
                'area_min'     => 'min_area',
                'area_max'     => 'max_area',
                'description'  => 'additional',
                'details'      => 'property_details',
            ];
            foreach ($legacyMap as $old => $new) {
                if (in_array($old, $cols, true) && in_array($new, $cols, true)) {
                    $pdo->exec("UPDATE property_requests SET `$new` = COALESCE(NULLIF(`$new`, ''), `$old`) WHERE `$old` IS NOT NULL AND `$old` <> ''");
                }
            }
            // راند ۵۴: request_matches هم ستون‌های امتیاز تفکیکی می‌خواهد
            $pdo->exec("CREATE TABLE IF NOT EXISTS request_matches (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT NOT NULL,
                ad_id VARCHAR(64) NOT NULL,
                match_percent DECIMAL(5,2) NULL DEFAULT 0,
                matched_transaction VARCHAR(60) NULL,
                matched_property_type VARCHAR(60) NULL,
                location_score INT NULL,
                area_score INT NULL,
                budget_score INT NULL,
                amenities_score INT NULL,
                is_notified TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_req (request_id),
                KEY idx_ad (ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $mcols = $pdo->query('SHOW COLUMNS FROM request_matches')->fetchAll(PDO::FETCH_COLUMN);
            $mcanon = [
                'match_percent'         => 'DECIMAL(5,2) NULL DEFAULT 0',
                'matched_transaction'   => 'VARCHAR(60) NULL',
                'matched_property_type' => 'VARCHAR(60) NULL',
                'location_score'        => 'INT NULL',
                'area_score'            => 'INT NULL',
                'budget_score'          => 'INT NULL',
                'amenities_score'       => 'INT NULL',
                'is_notified'           => 'TINYINT(1) NOT NULL DEFAULT 0',
            ];
            $madds = [];
            foreach ($mcanon as $col => $ddl) {
                if (!in_array($col, $mcols, true)) {
                    $madds[] = "ADD COLUMN `$col` $ddl";
                }
            }
            if ($madds) {
                $pdo->exec('ALTER TABLE request_matches ' . implode(', ', $madds));
                $mcols = $pdo->query('SHOW COLUMNS FROM request_matches')->fetchAll(PDO::FETCH_COLUMN);
            }
            if (in_array('score', $mcols, true) && in_array('match_percent', $mcols, true)) {
                $pdo->exec('UPDATE request_matches SET match_percent = COALESCE(match_percent, score)');
            }

            $done = true;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}


if (!function_exists('melkinoEnsureAdViewsSchema')) {
    function melkinoEnsureAdViewsSchema(?PDO $pdo = null): void
    {
        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO)) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ad_views (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT NULL,
                    telegram_id VARCHAR(64) NULL,
                    bale_id VARCHAR(64) NULL,
                    ad_id VARCHAR(64) NOT NULL,
                    ad_title VARCHAR(255) NULL,
                    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user (user_id),
                    KEY idx_ad (ad_id),
                    KEY idx_viewed (viewed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoRecordAdView')) {
    function melkinoRecordAdView($userId, $adId, string $title = '', string $telegramId = '', string $baleId = ''): void
    {
        global $pdo;
        $adId = trim((string) $adId);
        if ($adId === '' || !($pdo instanceof PDO)) {
            return;
        }
        $userId = (int) $userId;
        if ($userId <= 0 && $telegramId === '' && $baleId === '') {
            return;
        }
        melkinoEnsureAdViewsSchema($pdo);
        try {
            $pdo->prepare(
                'INSERT INTO ad_views (user_id, telegram_id, bale_id, ad_id, ad_title, viewed_at) VALUES (?,?,?,?,?,NOW())'
            )->execute([
                $userId > 0 ? $userId : null,
                $telegramId !== '' ? $telegramId : null,
                $baleId !== '' ? $baleId : null,
                $adId,
                $title !== '' ? mb_substr($title, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoRunMaintenanceGate')) {
    function melkinoRunMaintenanceGate(): void
    {
        global $pdo;
        static $done = false;
        if ($done || php_sapi_name() === 'cli' || !($pdo instanceof PDO)) {
            return;
        }
        $done = true;
        $allow = [
            'admin-login.php','admin-panel.php','admin-ads.php','admin-visits.php','admin-bots.php',
            'admin-notifications.php','admin-diagnostics.php','save_global_settings.php',
            'save_contact_settings.php','save_consultants.php','save_security_settings.php',
            'identity-sync.php','auth.php','auth-telegram.php','auth-bale.php','login.php',
            'admin-guard.php','db-settings.php','visit-request-api.php','profile-sync.php',
            'verify-otp.php','request-otp.php','melkino-logo-file.php',
        ];
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (in_array($script, $allow, true) || strpos($script, 'admin-') === 0 || strpos($script, 'save_') === 0) {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_start();
        }
        if (!empty($_SESSION['is_admin'])) {
            return;
        }
        try {
            $on = dbSettingGet($pdo, 'global', 'maintenance_mode', false);
        } catch (Throwable $e) {
            $on = false;
        }
        if (!$on) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 3600');
        }
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>در حال تعمیر</title>'
            . '<style>body{font-family:Tahoma,sans-serif;background:#0D1413;color:#F3F4F6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center}'
            . '.box{max-width:420px;padding:24px}h1{font-size:22px}</style>' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head><body><div class="box"><h1>ملکینو موقتاً در حال تعمیر است</h1>'
            . '<p style="color:#A8B1AE;line-height:1.9">لطفاً کمی بعد دوباره سر بزنید.</p></div></body></html>';
        exit;
    }
}
if (php_sapi_name() !== 'cli') {
    melkinoRunMaintenanceGate();
    if (is_file(__DIR__ . '/melkino-require-login.php')) {
        require_once __DIR__ . '/melkino-require-login.php';
    }
}
