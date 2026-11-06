<?php
/*
|--------------------------------------------------------------------------
| security-lib.php — لایهٔ مشترک امنیت، حسابرسی و اعتبارسنجی ملکینو
|--------------------------------------------------------------------------
| این فایل هیچ رفتار تجاری‌ای را تغییر نمی‌دهد. فقط ابزارهای مشترکی را
| در اختیار endpointها می‌گذارد تا منطق تکراری (مالکیت، محدودیت نرخ،
| پیام خطای امن، حسابرسی، پاک‌سازی تصویر) یک‌جا و یکسان باشد.
|
| هیچ ALTER روی جدول‌های موجود اجرا نمی‌شود؛ فقط دو جدول کمکیِ جدید
| در صورت نبودن ساخته می‌شوند (همان الگویی که پروژه از قبل برای
| comm_audit و admin_phone_audit استفاده می‌کند).
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoClientIp')) {
    function melkinoClientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return substr($ip, 0, 45);
    }
}

if (!function_exists('melkinoLogInternal')) {
    /**
     * خطای داخلی را فقط در error_log سرور ثبت می‌کند؛ هرگز به کاربر
     * برنمی‌گرداند. جای پیام‌هایی مثل «دیتابیس: ...getMessage()» را می‌گیرد.
     */
    function melkinoLogInternal(string $context, Throwable $e): void
    {
        @error_log(sprintf(
            '[melkino][%s] %s: %s @ %s:%d',
            $context,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}

if (!function_exists('melkinoSafeError')) {
    /**
     * پیام امن برای کاربر + ثبت جزئیات واقعی در لاگ سرور.
     * هیچ SQL/path/stack trace به بیرون نمی‌رود.
     */
    function melkinoSafeError(Throwable $e, string $context, string $publicMessage): string
    {
        melkinoLogInternal($context, $e);
        return $publicMessage;
    }
}

/* =====================================================================
   حسابرسی (Audit trail)
   ===================================================================== */

if (!function_exists('melkinoEnsureAuditTable')) {
    function melkinoEnsureAuditTable(): bool
    {
        global $pdo;
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        if (!($pdo instanceof PDO)) {
            return $ok = false;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `melkino_audit_log` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `actor_type` VARCHAR(20) NOT NULL DEFAULT 'admin',
                    `actor_id` VARCHAR(64) NULL,
                    `actor_name` VARCHAR(120) NULL,
                    `action` VARCHAR(80) NOT NULL,
                    `entity` VARCHAR(60) NULL,
                    `entity_id` VARCHAR(64) NULL,
                    `details` TEXT NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_action` (`action`),
                    KEY `idx_entity` (`entity`, `entity_id`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return $ok = true;
        } catch (Throwable $e) {
            melkinoLogInternal('audit.ensure', $e);
            return $ok = false;
        }
    }
}

if (!function_exists('melkinoAudit')) {
    /**
     * ثبت یک رخداد مهم. هرگز جریان اصلی را متوقف نمی‌کند و هرگز
     * استثنا پرتاب نمی‌کند؛ اگر لاگ ممکن نبود، بی‌صدا رد می‌شود.
     *
     * نکته: مقادیر حساس (رمز، توکن) نباید در $details فرستاده شوند.
     */
    function melkinoAudit(string $action, string $entity = '', $entityId = null, array $details = []): void
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !melkinoEnsureAuditTable()) {
            return;
        }

        // کلیدهایی که هرگز نباید خام در لاگ بنشینند
        // زیررشته‌هایی که همیشه حساس‌اند
        $blocked = ['password', 'pass', 'token', 'secret', 'api_key', 'bot_token', 'csrf_token', 'otp', 'hash', 'salt'];
        // کلیدهای دقیقاً «کد» (کد ورود). کدِ پیگیری (tracking_code) حساس
        // نیست و برای ردیابی لازم است، پس عمداً اینجا نیست.
        $blockedExact = ['code', 'sms_code', 'login_code', 'verify_code', 'verification_code'];
        // شماره تماس کاملاً حذف نمی‌شود (برای پیگیری لازم است) ولی فقط
        // چهار رقم آخرش نگه داشته می‌شود.
        $partial = ['phone', 'mobile', 'tel'];
        foreach ($details as $k => $v) {
            $key = (string) $k;
            $done = false;
            if (in_array(strtolower($key), $blockedExact, true)) {
                $details[$k] = '***';
                continue;
            }
            foreach ($blocked as $b) {
                if (stripos($key, $b) !== false) {
                    $details[$k] = '***';
                    $done = true;
                    break;
                }
            }
            if ($done) {
                continue;
            }
            foreach ($partial as $b) {
                if (stripos($key, $b) !== false) {
                    $digits = preg_replace('/\D/', '', is_scalar($v) ? (string) $v : '');
                    $details[$k] = $digits === '' ? '***' : ('***' . substr($digits, -4));
                    break;
                }
            }
        }

        if (!empty($_SESSION['is_admin'])) {
            $actorType = 'admin';
            $actorId   = (string) ($_SESSION['admin_id'] ?? '');
            $actorName = (string) ($_SESSION['admin_display_name'] ?? $_SESSION['admin_username'] ?? 'admin');
        } else {
            $actorType = 'user';
            $actorId   = (string) ($_SESSION['user_id'] ?? $_SESSION['reg_telegram_id'] ?? '');
            $actorName = (string) ($_SESSION['user_name'] ?? '');
        }

        try {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                $json = '{}';
            }
            if (strlen($json) > 4000) {
                $json = substr($json, 0, 4000);
            }
            $st = $pdo->prepare(
                'INSERT INTO melkino_audit_log
                    (actor_type, actor_id, actor_name, action, entity, entity_id, details, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $st->execute([
                $actorType,
                $actorId !== '' ? substr($actorId, 0, 64) : null,
                $actorName !== '' ? substr($actorName, 0, 120) : null,
                substr($action, 0, 80),
                $entity !== '' ? substr($entity, 0, 60) : null,
                $entityId !== null ? substr((string) $entityId, 0, 64) : null,
                $json,
                melkinoClientIp(),
            ]);
        } catch (Throwable $e) {
            melkinoLogInternal('audit.write', $e);
        }
    }
}

/* =====================================================================
   محدودیت نرخ (Rate limiting) — برای OTP و عملیات حساس
   ===================================================================== */

if (!function_exists('melkinoEnsureRateLimitTable')) {
    function melkinoEnsureRateLimitTable(): bool
    {
        global $pdo;
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        if (!($pdo instanceof PDO)) {
            return $ok = false;
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `melkino_rate_limits` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `bucket` VARCHAR(190) NOT NULL,
                    `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_bucket_time` (`bucket`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return $ok = true;
        } catch (Throwable $e) {
            melkinoLogInternal('ratelimit.ensure', $e);
            return $ok = false;
        }
    }
}

if (!function_exists('melkinoRateLimitHit')) {
    /**
     * یک تلاش را ثبت می‌کند و می‌گوید آیا از سقف عبور کرده یا نه.
     * در صورت نبود دیتابیس، محدودیت اعمال نمی‌شود (fail-open) تا سایت
     * از کار نیفتد؛ ولی رخداد در لاگ سرور ثبت می‌شود.
     *
     * @return bool true = مجاز، false = از سقف گذشته
     */
    function melkinoRateLimitHit(string $bucket, int $max, int $windowSeconds, bool $failClosed = false): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !melkinoEnsureRateLimitTable()) {
            // برای مسیرهای حساس (OTP) نبودِ سازوکار محدودیت یعنی
            // brute-force بی‌حد ممکن است؛ در آن حالت fail-closed می‌کنیم.
            error_log('[melkino][CRITICAL] rate-limit unavailable for bucket=' . substr($bucket, 0, 60)
                . ' failClosed=' . ($failClosed ? '1' : '0'));
            return !$failClosed;
        }
        $bucket = substr($bucket, 0, 190);
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM melkino_rate_limits
                 WHERE bucket = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
            );
            $st->bindValue(1, $bucket, PDO::PARAM_STR);
            $st->bindValue(2, $windowSeconds, PDO::PARAM_INT);
            $st->execute();
            $count = (int) $st->fetchColumn();

            $pdo->prepare('INSERT INTO melkino_rate_limits (bucket, created_at) VALUES (?, NOW())')
                ->execute([$bucket]);

            // نظافت سبک: ردیف‌های قدیمی‌تر از یک روز پاک می‌شوند
            if (random_int(1, 50) === 1) {
                $pdo->exec('DELETE FROM melkino_rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
            }

            return $count < $max;
        } catch (Throwable $e) {
            melkinoLogInternal('ratelimit.hit', $e);
            error_log('[melkino][CRITICAL] rate-limit query failed for bucket=' . substr($bucket, 0, 60)
                . ' failClosed=' . ($failClosed ? '1' : '0'));
            return !$failClosed;
        }
    }
}

/* =====================================================================
   مالکیت مرکزی (Authorization helpers)
   ===================================================================== */

if (!function_exists('melkinoNormalizePhoneKey')) {
    function melkinoNormalizePhoneKey($phone): string
    {
        $p = strtr((string) $phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $p = preg_replace('/\D+/', '', $p) ?? '';
        if (strpos($p, '98') === 0 && strlen($p) === 12) {
            $p = '0' . substr($p, 2);
        }
        return $p;
    }
}

if (!function_exists('melkinoIsAdminSession')) {
    function melkinoIsAdminSession(): bool
    {
        return !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }
}

if (!function_exists('melkinoOwnsRow')) {
    /**
     * بررسی مالکیت یک ردیف بر اساس هویت جاری.
     * ادمین همیشه مجاز است. مقایسه شماره با نرمال‌سازی ارقام فارسی.
     *
     * @param array $row      ردیف دیتابیس (ads / property_requests / visit_requests / ...)
     * @param array $identity خروجی melkinoCurrentIdentity()
     * @param array $fields   نگاشت ستون‌ها
     */
    function melkinoOwnsRow(array $row, array $identity, array $fields = []): bool
    {
        if (melkinoIsAdminSession()) {
            return true;
        }

        $userCol     = $fields['user_id']     ?? 'user_id';
        $telegramCol = $fields['telegram_id'] ?? 'telegram_id';
        $phoneCol    = $fields['phone']       ?? 'phone';

        if (!empty($identity['user_id']) && !empty($row[$userCol])
            && (int) $row[$userCol] === (int) $identity['user_id']) {
            return true;
        }

        $idTg  = trim((string) ($identity['telegram_id'] ?? ''));
        $rowTg = trim((string) ($row[$telegramCol] ?? ''));
        if ($idTg !== '' && $rowTg !== '' && hash_equals($rowTg, $idTg)) {
            return true;
        }

        $idPhone  = melkinoNormalizePhoneKey($identity['phone'] ?? '');
        $rowPhone = melkinoNormalizePhoneKey($row[$phoneCol] ?? '');
        if ($idPhone !== '' && $rowPhone !== '' && hash_equals($rowPhone, $idPhone)) {
            return true;
        }

        return false;
    }
}

/* =====================================================================
   پاک‌سازی تصویر آپلودی
   ===================================================================== */




if (!function_exists('melkinoAccessTokenTtl')) {
    /** عمر کوکی توکن دسترسی (ثانیه). پیش‌فرض ۳۰ روز، قابل تنظیم با env. */
    function melkinoAccessTokenTtl(): int
    {
        $v = function_exists('melkinoEnv') ? (int) melkinoEnv('MELKINO_TOKEN_TTL_DAYS', '30') : 30;
        if ($v < 1 || $v > 180) {
            $v = 30;
        }
        return $v * 86400;
    }
}

if (!function_exists('melkinoSetAccessTokenCookie')) {
    /**
     * تنظیم یکنواخت کوکی «توکن دسترسی».
     *
     * قبلاً هر فایل خودش setcookie می‌زد با عمر یک‌ساله، بدون Secure و
     * بدون SameSite. اینجا همه‌جا یکی می‌شود:
     *   HttpOnly + SameSite=Lax + Secure (فقط روی HTTPS) + عمر محدود.
     */
    function melkinoSetAccessTokenCookie(string $token, ?int $ttl = null): void
    {
        if ($token === '' || headers_sent()) {
            return;
        }
        $ttl = $ttl ?? melkinoAccessTokenTtl();
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        setcookie('melkino_access_token', $token, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('melkinoClearAccessTokenCookie')) {
    function melkinoClearAccessTokenCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        setcookie('melkino_access_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['melkino_access_token']);
    }
}

if (!function_exists('melkinoOtpHasHashColumn')) {
    /**
     * آیا جدول otp_codes ستون code_hash دارد؟ (یک‌بار در هر درخواست کش می‌شود)
     *
     * این تابع امکان مهاجرت تدریجی به کد هش‌شده را بدون هیچ ALTER اجباری
     * فراهم می‌کند: تا وقتی ستون نیست، همان مسیر قدیمی کار می‌کند.
     */
    function melkinoOtpHasHashColumn(): bool
    {
        global $pdo;
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        if (!($pdo instanceof PDO)) {
            return $has = false;
        }
        try {
            $st = $pdo->query("SHOW COLUMNS FROM otp_codes LIKE 'code_hash'");
            return $has = ($st && $st->fetch(PDO::FETCH_ASSOC) !== false);
        } catch (Throwable $e) {
            melkinoLogInternal('otp.hashcol', $e);
            return $has = false;
        }
    }
}

if (!function_exists('melkinoOtpHash')) {
    function melkinoOtpHash(string $code): string
    {
        // نمک ثابتِ برنامه‌ای لازم نیست: فضای کد ۶ رقمی کوچک است ولی
        // ردیف‌ها دو دقیقه عمر دارند و با شماره جفت‌اند؛ هدف اصلی این است
        // که دیدنِ دیتابیس به‌تنهایی کد فعال را لو ندهد.
        return hash('sha256', $code);
    }
}

if (!function_exists('melkinoOtpCodeMatches')) {
    /**
     * تطبیق کد واردشده با ردیف otp_codes — سازگار با هر دو حالت
     * (ستون code_hash موجود یا فقط code خام).
     */
    function melkinoOtpCodeMatches(array $row, string $input): bool
    {
        $stored = (string) ($row['code_hash'] ?? '');
        if ($stored !== '') {
            return hash_equals($stored, melkinoOtpHash($input));
        }
        return hash_equals((string) ($row['code'] ?? ''), $input);
    }
}

if (!function_exists('melkinoImageTypeOf')) {
    /**
     * تشخیص نوع واقعی تصویر.
     *
     * روی بعضی هاست‌ها (یا با disable_functions) تابع getimagesize در
     * دسترس نیست؛ در آن حالت به‌جای خطای کشنده، از امضای بایت‌های ابتدایی
     * فایل (magic bytes) استفاده می‌کنیم. خروجی یکی از ثابت‌های IMAGETYPE_*
     * است و اگر تصویر شناخته نشد صفر برمی‌گردد.
     *
     * @return array{type:int,width:int,height:int}
     */
    function melkinoImageTypeOf(string $path): array
    {
        $out = ['type' => 0, 'width' => 0, 'height' => 0];

        if (function_exists('getimagesize')) {
            $info = @getimagesize($path);
            if (is_array($info) && !empty($info[2])) {
                $out['type']   = (int) $info[2];
                $out['width']  = (int) ($info[0] ?? 0);
                $out['height'] = (int) ($info[1] ?? 0);
            }
            return $out;
        }

        // ----- مسیر جایگزین: بررسی امضای فایل -----
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return $out;
        }
        $head = (string) fread($fh, 32);
        fclose($fh);
        if (strlen($head) < 12) {
            return $out;
        }

        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            $out['type'] = IMAGETYPE_JPEG;
        } elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            $out['type'] = IMAGETYPE_PNG;
        } elseif (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            $out['type'] = IMAGETYPE_GIF;
        } elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            $out['type'] = defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18;
        } elseif (strncmp($head, 'BM', 2) === 0) {
            $out['type'] = IMAGETYPE_BMP;
        }

        return $out;
    }
}

if (!function_exists('melkinoValidateUploadedImage')) {
    /**
     * اعتبارسنجی سخت‌گیرانهٔ یک فایل آپلودشده به‌عنوان تصویر.
     *
     * @return array{ok:bool,message:string,ext:string,type:int,width:int,height:int}
     */
    function melkinoValidateUploadedImage(string $tmpPath, int $maxBytes = 5242880): array
    {
        $fail = static function (string $m): array {
            return ['ok' => false, 'message' => $m, 'ext' => '', 'type' => 0, 'width' => 0, 'height' => 0];
        };

        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            return $fail('فایل آپلودشده معتبر نیست.');
        }

        $size = (int) @filesize($tmpPath);
        if ($size <= 0) {
            return $fail('فایل خالی است.');
        }
        if ($size > $maxBytes) {
            return $fail('حجم فایل بیش از حد مجاز است.');
        }

        $probe = melkinoImageTypeOf($tmpPath);
        if (empty($probe['type'])) {
            return $fail('فایل انتخابی یک تصویر معتبر نیست.');
        }
        // وقتی getimagesize در دسترس نیست ابعاد صفر است؛ در آن حالت فقط
        // نوع بررسی می‌شود و کنترل ابعاد رد می‌شود (نه اینکه خطا بدهد).
        $info = [$probe['width'], $probe['height'], $probe['type']];

        $allowed = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
        ];
        $type = (int) $info[2];
        if (!isset($allowed[$type])) {
            return $fail('نوع تصویر پشتیبانی نمی‌شود (فقط jpg، png، webp و gif).');
        }

        // ابعاد نامعقول = تلاش برای decompression bomb
        if ((int) $info[0] > 12000 || (int) $info[1] > 12000) {
            return $fail('ابعاد تصویر بیش از حد بزرگ است.');
        }

        // محتوای مشکوک: برچسب PHP یا script داخل بایت‌های ابتدایی فایل
        $head = (string) @file_get_contents($tmpPath, false, null, 0, 4096);
        if ($head !== '' && preg_match('/<\?php|<\?=|<script\b/i', $head)) {
            return $fail('محتوای فایل مجاز نیست.');
        }

        return [
            'ok' => true,
            'message' => '',
            'ext' => $allowed[$type],
            'type' => $type,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }
}

if (!function_exists('melkinoReencodeImage')) {
    /**
     * تصویر را با GD رمزگشایی و دوباره رمزگذاری می‌کند تا هر محتوای
     * جاسازی‌شده (متادیتا، payload) حذف شود.
     *
     * اگر GD در دسترس نباشد یا تبدیل شکست بخورد، false برمی‌گرداند و
     * فراخوان باید به move_uploaded_file معمولی برگردد (fail-safe، نه
     * fail-closed، تا آپلود روی هاست‌های ضعیف از کار نیفتد).
     */
    function melkinoReencodeImage(string $srcPath, string $destPath, int $type): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        try {
            $data = @file_get_contents($srcPath);
            if ($data === false || $data === '') {
                return false;
            }
            $img = @imagecreatefromstring($data);
            unset($data);
            if (!$img) {
                return false;
            }

            $ok = false;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $ok = @imagejpeg($img, $destPath, 88);
                    break;
                case IMAGETYPE_PNG:
                    @imagealphablending($img, false);
                    @imagesavealpha($img, true);
                    $ok = @imagepng($img, $destPath, 6);
                    break;
                case IMAGETYPE_WEBP:
                    $ok = function_exists('imagewebp') ? @imagewebp($img, $destPath, 88) : false;
                    break;
                case IMAGETYPE_GIF:
                    $ok = @imagegif($img, $destPath);
                    break;
            }
            @imagedestroy($img);
            return (bool) $ok && is_file($destPath) && filesize($destPath) > 0;
        } catch (Throwable $e) {
            melkinoLogInternal('image.reencode', $e);
            return false;
        }
    }
}

if (!function_exists('melkinoRandomFileName')) {
    function melkinoRandomFileName(string $prefix, string $ext): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '', $prefix) ?? '';
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?? 'jpg';
        return ($prefix !== '' ? $prefix . '_' : '') . bin2hex(random_bytes(10)) . '.' . $ext;
    }
}

if (!function_exists('melkinoProtectUploadDir')) {
    /**
     * اطمینان از وجود .htaccess ضد اجرای اسکریپت در یک پوشهٔ آپلود.
     */
    function melkinoProtectUploadDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $file = rtrim($dir, '/\\') . '/.htaccess';
        if (is_file($file)) {
            return;
        }
        $rules = "Options -Indexes\n"
            . "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl|py|sh|htaccess)$\">\n"
            . "    Require all denied\n"
            . "</FilesMatch>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    <FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl|py|sh|htaccess)$\">\n"
            . "        Order allow,deny\n"
            . "        Deny from all\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n";
        @file_put_contents($file, $rules);
    }
}
