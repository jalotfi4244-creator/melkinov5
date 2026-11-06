<?php
/*
|--------------------------------------------------------------------------
| برنامهٔ پیامک ملکینو — سرویس‌دهنده: ملی‌پیامک (melipayamak.com)
|--------------------------------------------------------------------------
| مرکز برنامه‌ریزی و تنظیمات پیامک برای ۵ هدف:
|   1) احراز هویت ورود با پیامک (OTP)          → از قبل فعال است (request-otp.php)
|                                                اینجا فقط خط/پترن ملی‌پیامکش تنظیم می‌شود.
|   2) اطلاع‌رسانی «جستجوی ذخیره‌شده» کاربر     → آگهی جدیدِ منطبق با فیلتر کاربر
|   3) اطلاع‌رسانی انطباق درخواست مشتری         → فایل‌های جدید منطبق با property_requests
|   4) هشدار ادمین (درخواست/آگهی در انتظار > N)  → پیامک به شمارهٔ مدیر
|   5) کمپین تبلیغاتی گروهی                    → کمپین‌های زمان‌دارِ تب «پنل پیامک» (comm)
|
| ---------- انطباق با مقررات ملی پیامک (مصوبه ۲۷۰ کمیسیون تنظیم مقررات) ----------
|   • ارسال انبوه تبلیغاتی/اطلاع‌رسانی فقط با رضایت قبلی مشترک (opt-in) —
|     جستجوی ذخیره‌شده و درخواستِ ثبت‌شده خودشان رضایت‌اند + جدول lغو عضویت.
|   • «لغو11» به انتهای پیامک‌های اطلاع‌رسانی/تبلیغاتی انبوه از خط خدماتی
|     اضافه می‌شود تا مشترک بتواند دریافت را قطع کند (OTP و پیامک ادمین مستثنی‌اند).
|   • ساعات مجاز ارسال انبوه: ۸ صبح تا ۲۲ شب (مقررات ملی‌پیامک؛ ارسالِ
|     بیرون از بازه به‌جای ارسال، در صف می‌ماند). OTP و هشدار ادمین مستثنی.
|   • شماره‌های لغو‌عضویت‌شده (sms_optouts) هرگز پیامک انبوه نمی‌گیرند.
|
| ---------- قرارداد وب‌سرویس ملی‌پیامک ----------
|   POST https://rest.payamak-panel.com/api/SendSMS/SendSMS
|        username, password, to, from, text, isFlash
|   پاسخ JSON: {Value: recId, RetStatus: 1, StrRetStatus: "Ok"}
|   موفقیت فقط یعنی RetStatus === 1 (HTTP 200 به‌تنهایی معیار نیست).
|   کد خطاها در melipayamakErrorText() فارسی شده‌اند.
|
| تنظیمات در db_settings (گروه sms_program، کلید info) به‌صورت JSON.
| اجرای خودکار: sms-cron.php?token=... یا تیکِ همراه با ترافیک سایت (footer).
|--------------------------------------------------------------------------
*/

if (!function_exists('smsProgramDefaultSettings')) {

    /* =====================================================================
     * تنظیمات
     * ===================================================================== */

    function smsProgramDefaultSettings(): array
    {
        return [
            'enabled'         => 0,            // کلید اصلی کل برنامه
            'quiet_start'     => 22,           // ساعات سکوت انبوه (مقررات: ۲۲ تا ۸)
            'quiet_end'       => 8,
            'lagoo11'         => 1,            // درج خودکار «لغو11» در پیامک‌های انبوه
            'max_per_tick'    => 15,           // سقف ارسال در هر اجرای برنامه
            'cron_token'      => '',           // توکن sms-cron.php
            'site_url'        => '',           // دامنهٔ سایت برای لینک داخل پیامک (اجرای CLI)
            'last_tick'       => '',           // آخرین اجرا (Y-m-d H:i:s)
            'last_saved_run'  => '',           // آخرین اسکن آگهی‌های منتشرشده برای جستجوها
            // هدف ۲: جستجوی ذخیره‌شده — متن‌ها و آستانه‌ها همه از پنل قابل تغییرند
            'saved_search'    => [
                'on' => 1, 'cap_day' => 2,
                'min_new' => 1, // ۱ = با اولین ملک جدید پیامک برو؛ بیشتر = تجمیعی
                'template' => "🏠 ملک جدید مطابق جستجوی شما در ملکینو\n{property}\n{location}\nقیمت: {price}\nمشاهده: {link}",
                'digest_template' => "🏠 {count} ملک جدید مطابق جستجوی شما در ملکینو:\n{list}\nمشاهده: {link}",
            ],
            // هدف ۳: انطباق درخواست مشتری
            'request_match'   => [
                'on' => 1, 'cap_day' => 1, 'score_min' => 0,
                'min_new' => 1, // حداقل فایل جدیدِ منطبق برای ارسال (مثلاً ۵)
                'template' => "ملکینو: {count} فایل جدیدِ منطبق با درخواست شما در سایت قرار گرفت.\nمشاهده: {link}",
            ],
            // هدف ۴: هشدار ادمین — over = بیشتر از سقف / every = هر N موردِ جدید
            'admin_alert'     => [
                'on' => 1, 'ads_thr' => 10, 'req_thr' => 10, 'debounce_h' => 6, 'phone' => '',
                'mode' => 'over', 'every_n' => 20, 'last_ads' => 0, 'last_reqs' => 0,
                'template' => "⚠️ ملکینو — هشدار پنل مدیریت\n{text}\nلطفاً پنل ادمین را بررسی کنید.",
            ],
            // هدف ۵: کمپین تبلیغاتی زمان‌دار (متن/مخاطب از تب «پنل پیامک» — خود کمپین)
            'marketing'       => ['on' => 1],
            // خطوط ارسال (چند شماره): [{id,label,number,active,daily_cap}]
            'lines'           => [],
            // مسیریابی: هدف (purpose) => id خط. خالی = خط پیش‌فرض قدیمی
            'routes'          => [],
        ];
    }

    /* =====================================================================
     * خطوط ارسال و مسیریابی
     * ===================================================================== */

    /** اهداف مسیریابی (کلیدِ ثابت => برچسب فارسی) */
    function smsProgramPurposes(): array
    {
        return [
            'otp'           => 'کد ورود (OTP)',
            'saved_search'  => 'جستجوی ذخیره‌شده',
            'request_match' => 'انطباق درخواست',
            'admin_alert'   => 'هشدار ادمین',
            'campaign'      => 'کمپین تبلیغاتی',
            'manual'        => 'تست و ارسال دستی',
        ];
    }

    /** نگاشت goal پیامک در صف به هدف مسیریابی */
    function smsProgramPurposeOfGoal(string $goal): string
    {
        return in_array($goal, ['saved_search', 'request_match', 'admin_alert', 'campaign'], true) ? $goal : 'manual';
    }

    /**
     * پاک‌سازی فهرست خطوط: فقط شماره‌های معتبر، شناسهٔ یکتا، سقف روزانهٔ صفر = نامحدود.
     * @return array<int, array{id:string,label:string,number:string,active:int,daily_cap:int}>
     */
    function smsProgramNormLines($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $l) {
            if (!is_array($l)) {
                continue;
            }
            $number = trim(strtr((string)($l['number'] ?? ''), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
            if (!preg_match('/^\d{3,15}$/', $number)) {
                continue;
            }
            $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($l['id'] ?? '')) ?? '';
            if ($id === '') {
                $id = 'L' . substr(md5($number), 0, 6);
            }
            $id = substr($id, 0, 20);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $label = trim((string)($l['label'] ?? ''));
            $out[] = [
                'id'        => $id,
                'label'     => mb_substr($label !== '' ? $label : $number, 0, 60),
                'number'    => $number,
                'active'    => !empty($l['active']) ? 1 : 0,
                'daily_cap' => max(0, min(100000, (int)($l['daily_cap'] ?? 0))),
            ];
        }
        return $out;
    }

    /**
     * تعیین خط ارسال برای یک هدف.
     * ترتیب: ۱) مسیریابی ادمین (اگر خط فعال باشد) ۲) خط قدیمی هدف ۳) خط پیش‌فرض.
     * اگر مسیریابی به خطِ غیرفعال اشاره کند، ارسال بلاک می‌شود (به‌جای خط دیگر).
     * @return array{number:string,line_id:string,label:string,daily_cap:int,source:string,reason:string}
     */
    function smsProgramResolveLine(array $cfg, array $sms, string $purpose): array
    {
        $out = ['number' => '', 'line_id' => '', 'label' => '', 'daily_cap' => 0, 'source' => 'none', 'reason' => ''];
        $byId = [];
        foreach (smsProgramNormLines($cfg['lines'] ?? []) as $l) {
            $byId[$l['id']] = $l;
        }
        $routes = is_array($cfg['routes'] ?? null) ? $cfg['routes'] : [];
        $rid = trim((string)($routes[$purpose] ?? ''));
        if ($rid !== '' && isset($byId[$rid])) {
            $l = $byId[$rid];
            if (empty($l['active'])) {
                $out['line_id'] = $rid;
                $out['label'] = $l['label'];
                $out['source'] = 'route';
                $out['reason'] = 'خط «' . $l['label'] . '» غیرفعال است؛ این پیامک ارسال نمی‌شود تا خط فعال شود.';
                return $out;
            }
            return ['number' => $l['number'], 'line_id' => $rid, 'label' => $l['label'], 'daily_cap' => (int)$l['daily_cap'], 'source' => 'route', 'reason' => ''];
        }
        if ($purpose === 'otp') {
            $cands = [$sms['otp_line'] ?? '', $sms['sender_line'] ?? ''];
        } elseif ($purpose === 'campaign') {
            $cands = [$sms['promo_line'] ?? '', $sms['sender_line'] ?? ''];
        } else {
            $cands = [$sms['sender_line'] ?? ''];
        }
        foreach ($cands as $c) {
            $c = trim((string)$c);
            if ($c !== '') {
                $out['number'] = $c;
                $out['label'] = 'خط پیش‌فرض';
                $out['source'] = 'legacy';
                return $out;
            }
        }
        $out['reason'] = 'برای این هدف هیچ خطی تعریف نشده است.';
        return $out;
    }

    /** شمارهٔ خط مسیریابی‌شده برای یک هدف (برای OTP؛ فقط وقتی مسیریابی تعیین شده باشد) */
    function smsProgramRouteNumber(string $purpose, array $sms): string
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return '';
        }
        $cfg = smsProgramSettings($pdo);
        $routes = is_array($cfg['routes'] ?? null) ? $cfg['routes'] : [];
        if (trim((string)($routes[$purpose] ?? '')) === '') {
            return '';
        }
        $pick = smsProgramResolveLine($cfg, $sms, $purpose);
        return $pick['source'] === 'route' ? (string)$pick['number'] : '';
    }

    /** تعداد پیامک‌های موفقِ امروز از یک خط (فقط پیامک‌هایی که در صف ثبت شده‌اند) */
    function smsProgramLineSentToday(PDO $pdo, string $number): int
    {
        if ($number === '') {
            return 0;
        }
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE line = ? AND status = 'sent' AND sent_at >= CURDATE()");
            $st->execute([$number]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** وضعیت مسیریابی برای پنل ادمین */
    function smsProgramRoutingState(PDO $pdo, array $cfg, array $sms): array
    {
        $lines = smsProgramNormLines($cfg['lines'] ?? []);
        foreach ($lines as $i => $l) {
            $lines[$i]['sent_today'] = smsProgramLineSentToday($pdo, $l['number']);
        }
        $routes = is_array($cfg['routes'] ?? null) ? $cfg['routes'] : [];
        $effective = [];
        foreach (smsProgramPurposes() as $p => $label) {
            $pick = smsProgramResolveLine($cfg, $sms, $p);
            $effective[$p] = [
                'label'  => $label,
                'route'  => (string)($routes[$p] ?? ''),
                'number' => $pick['number'],
                'source' => $pick['source'],
                'reason' => $pick['reason'],
            ];
        }
        return [
            'purposes'  => smsProgramPurposes(),
            'lines'     => $lines,
            'routes'    => $routes,
            'effective' => $effective,
            'legacy'    => [
                'service' => (string)($sms['sender_line'] ?? ''),
                'otp'     => (string)($sms['otp_line'] ?? ''),
                'promo'   => (string)($sms['promo_line'] ?? ''),
            ],
        ];
    }

    /** ستون‌های وضعیت تحویل روی sms_outbox (یک‌بار؛ برای نصب‌های قدیمی) */
    function smsProgramEnsureDeliveryColumns(PDO $pdo): void
    {
        try {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_outbox' AND COLUMN_NAME = 'delivery_status'")->fetchColumn();
            if ($n === 0) {
                $pdo->exec('ALTER TABLE sms_outbox ADD COLUMN delivery_status SMALLINT NULL, ADD COLUMN delivery_checked_at DATETIME NULL');
            }
        } catch (Throwable $e) {
        }
    }

    function smsProgramSettings(PDO $pdo): array
    {
        $def = smsProgramDefaultSettings();
        try {
            $raw = dbSettingGet($pdo, 'sms_program', 'info', null);
        } catch (Throwable $e) {
            $raw = null;
        }
        $saved = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($saved)) {
            $saved = [];
        }
        foreach ($def as $k => $dv) {
            if (!array_key_exists($k, $saved)) {
                $saved[$k] = $dv;
            }
        }
        foreach (['saved_search', 'request_match', 'admin_alert', 'marketing'] as $g) {
            if (!is_array($saved[$g])) {
                $saved[$g] = $def[$g];
            } else {
                // تنظیمات ذخیره‌شدهٔ قدیمی‌تر ممکن است کلیدهای تازه را نداشته باشند
                $saved[$g] = array_merge($def[$g], $saved[$g]);
            }
        }
        return $saved;
    }

    function smsProgramSaveSettings(PDO $pdo, array $next): void
    {
        $cur = smsProgramSettings($pdo);
        $merged = array_merge($cur, $next);
        dbSettingSet($pdo, 'sms_program', 'info', json_encode($merged, JSON_UNESCAPED_UNICODE));
    }

    /* =====================================================================
     * جداول
     * ===================================================================== */

    function smsProgramEnsureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_outbox (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            goal VARCHAR(30) NOT NULL DEFAULT 'manual',
            phone VARCHAR(20) NOT NULL,
            body TEXT NOT NULL,
            meta_json TEXT NULL,
            line VARCHAR(30) NULL,
            rec_id VARCHAR(64) NULL,
            status VARCHAR(15) NOT NULL DEFAULT 'queued',
            fail_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            KEY idx_goal (goal, status),
            KEY idx_phone (phone, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        smsProgramEnsureDeliveryColumns($pdo);
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_optouts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            scope VARCHAR(15) NOT NULL DEFAULT 'all',
            source VARCHAR(60) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_phone_scope (phone, scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS saved_searches (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            phone VARCHAR(20) NULL,
            title VARCHAR(160) NULL,
            tx VARCHAR(60) NULL,
            property_type VARCHAR(60) NULL,
            district VARCHAR(120) NULL,
            min_price VARCHAR(40) NULL,
            max_price VARCHAR(40) NULL,
            min_area VARCHAR(30) NULL,
            max_area VARCHAR(30) NULL,
            rooms VARCHAR(10) NULL,
            notify TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_notified_at DATETIME NULL,
            KEY idx_user (user_id),
            KEY idx_notify (notify)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // ستون زمان‌بندی کمپین‌ها (اگر هنوز وجود ندارد — اجرای یک‌بارهٔ سبک)
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM comm_campaigns')->fetchAll(PDO::FETCH_COLUMN);
            if ($cols && !in_array('scheduled_at', $cols, true)) {
                $pdo->exec("ALTER TABLE comm_campaigns ADD COLUMN scheduled_at DATETIME NULL DEFAULT NULL");
            }
        } catch (Throwable $e) {
        }
        $done = true;
    }

    /* =====================================================================
     * کمک‌کارها
     * ===================================================================== */

    function smsProgramDigits(string $s): string
    {
        $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => '', ',' => ''];
        return preg_replace('/\D+/', '', strtr($s, $map)) ?? '';
    }

    function smsProgramNormPhone(string $phone): string
    {
        $d = smsProgramDigits($phone);
        if (substr($d, 0, 2) === '98' && strlen($d) >= 12) {
            $d = '0' . substr($d, 2);
        }
        if (strlen($d) === 10 && substr($d, 0, 1) === '9') {
            $d = '0' . $d;
        }
        return $d;
    }

    /** نمایش قیمت فارسی: گروه‌بندی سه‌رقمی بدون تغییر مقدار (ملاک: هوم). */
    function smsProgramPriceFa(string $raw): string
    {
        $d = smsProgramDigits($raw);
        if ($d === '') {
            return trim($raw);
        }
        $en = number_format((int)$d);
        $fa = strtr($en, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬']);
        return $fa . ' تومان';
    }

    function smsProgramFaNum($n): string
    {
        return strtr((string)$n, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** آیا الان داخل ساعات سکوت انبوه هستیم؟ (مقررات: ارسال انبوه فقط ۸–۲۲) */
    function smsProgramInQuiet(int $h, int $quietStart, int $quietEnd): bool
    {
        if ($quietStart === $quietEnd) {
            return false;
        }
        if ($quietStart < $quietEnd) {
            return $h >= $quietStart && $h < $quietEnd;
        }
        return $h >= $quietStart || $h < $quietEnd; // بازهٔ شبان‌روزی (22→8)
    }

    function smsProgramLagoo(array $cfg): string
    {
        return !empty($cfg['lagoo11']) ? "\n" . 'لغو11' : '';
    }

    function smsProgramOptedOut(PDO $pdo, string $phone, string $scope): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM sms_optouts WHERE phone = ? AND scope IN (?, 'all')");
            $st->execute([$phone, $scope]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** جایگذاری متغیرهای {نام} در قالب پیامک — قالب‌ها از پنل ادمین قابل تغییرند */
    function smsProgramFill(string $tpl, array $vars): string
    {
        return trim(strtr($tpl, $vars));
    }

    /** خلاصهٔ یک‌خطی آگهی: «آپارتمان ۱۱۰ متری، ۲ خوابه» */
    function smsProgramAdSummary(array $ad): array
    {
        $type = trim((string)($ad['property_type'] ?? ''));
        $area = (int)smsProgramDigits((string)($ad['area'] ?? ''));
        $prop = $type;
        if ($area > 0) {
            $prop .= ' ' . smsProgramFaNum($area) . ' متری';
        }
        $rooms = trim((string)($ad['rooms'] ?? ''));
        if ($rooms !== '' && $rooms !== '0') {
            $prop .= '، ' . smsProgramFaNum($rooms) . ' خوابه';
        }
        $price = smsProgramAdPrice($ad);
        return [
            'property' => $prop,
            'location' => trim((string)($ad['location'] ?? '')),
            'price'    => $price > 0 ? smsProgramPriceFa((string)$price) : '',
            'title'    => trim((string)($ad['title'] ?? '')),
            'ad_id'    => (string)($ad['id'] ?? ''),
        ];
    }

    function smsProgramSiteUrl(): string
    {
        require_once __DIR__ . '/program-url.php';
        return melkinoAppUrl();
    }

    /** ثبت یک پیام در صف خروجی. برمی‌گرداند: id ردیف صف */
    function smsProgramQueue(PDO $pdo, string $goal, string $phone, string $body, array $meta = [], string $status = 'queued'): int
    {
        $st = $pdo->prepare('INSERT INTO sms_outbox(goal, phone, body, meta_json, status) VALUES (?,?,?,?,?)');
        $st->execute([$goal, $phone, $body, json_encode($meta, JSON_UNESCAPED_UNICODE), $status === 'held' ? 'held' : 'queued']);
        return (int)$pdo->lastInsertId();
    }

    /* =====================================================================
     * تطبیق آگهی با جستجوی ذخیره‌شده (همان منطق چیپ‌های «همه آگهی‌ها»)
     * ===================================================================== */

    function smsProgramTxMatch(string $searchTx, string $adTx): bool
    {
        $s = trim($searchTx);
        $a = trim($adTx);
        if ($s === '' || $s === 'all') {
            return true;
        }
        if ($s === 'اجاره') {
            // اجاره و رهن: اجاره / رهن و اجاره / رهن کامل — مثل فیلتر صفحهٔ آگهی‌ها
            return mb_strpos($a, 'اجاره') !== false || mb_strpos($a, 'رهن') !== false;
        }
        return $a === $s;
    }

    /** قیمت عددی آگهی بر اساس نوع معامله (اولین فیلد قابل‌پارس) */
    function smsProgramAdPrice(array $ad): int
    {
        $tx = (string)($ad['transaction_type'] ?? '');
        $candidates = (mb_strpos($tx, 'فروش') !== false && mb_strpos($tx, 'پیش') === false)
            ? [$ad['price_sell'] ?? '', $ad['total_price'] ?? '']
            : (($tx === 'پیش فروش') ? [$ad['price_condition'] ?? '', $ad['total_price'] ?? ''] : [$ad['deposit'] ?? '', $ad['total_price'] ?? '']);
        foreach ($candidates as $c) {
            $d = smsProgramDigits((string)$c);
            if ($d !== '') {
                return (int)$d;
            }
        }
        return 0;
    }

    function smsProgramAdMatches(array $ad, array $s): bool
    {
        // نوع معامله
        if (!smsProgramTxMatch((string)($s['tx'] ?? ''), (string)($ad['transaction_type'] ?? ''))) {
            return false;
        }
        // نوع ملک (تطابق دقیق، مثل فیلتر آگهی‌ها)
        $pt = trim((string)($s['property_type'] ?? ''));
        if ($pt !== '' && trim((string)($ad['property_type'] ?? '')) !== $pt) {
            return false;
        }
        // محله/منطقه: جستجو در محله و نشانی
        $ds = trim((string)($s['district'] ?? ''));
        if ($ds !== '') {
            $hay = trim((string)($ad['location'] ?? '')) . ' ' . trim((string)($ad['address'] ?? ''));
            if ($hay === '' || mb_strpos($hay, $ds) === false) {
                return false;
            }
        }
        // متراژ
        $area = (int)smsProgramDigits((string)($ad['area'] ?? ''));
        $minA = (int)smsProgramDigits((string)($s['min_area'] ?? ''));
        $maxA = (int)smsProgramDigits((string)($s['max_area'] ?? ''));
        if ($minA > 0 && $area > 0 && $area < $minA) {
            return false;
        }
        if ($maxA > 0 && $area > 0 && $area > $maxA) {
            return false;
        }
        // خواب
        $rooms = trim((string)($s['rooms'] ?? ''));
        if ($rooms !== '' && $rooms !== '0' && trim((string)($ad['rooms'] ?? '')) !== $rooms) {
            return false;
        }
        // بودجه
        $price = smsProgramAdPrice($ad);
        $minP = (int)smsProgramDigits((string)($s['min_price'] ?? ''));
        $maxP = (int)smsProgramDigits((string)($s['max_price'] ?? ''));
        if ($minP > 0 && $price > 0 && $price < $minP) {
            return false;
        }
        if ($maxP > 0 && $price > 0 && $price > $maxP) {
            return false;
        }
        return true;
    }

    /* =====================================================================
     * هدف ۲ — جستجوی ذخیره‌شده: آگهی‌های تازه منتشرشدهٔ منطبق
     * ===================================================================== */

    function smsProgramJobSavedSearches(PDO $pdo, array $cfg, array &$log): void
    {
        $g = $cfg['saved_search'];
        if (empty($g['on']) || empty($cfg['enabled'])) {
            return;
        }
        $minNew = max(1, (int)($g['min_new'] ?? 1));
        $tpl = (string)($g['template'] ?? '');
        $digestTpl = (string)($g['digest_template'] ?? '');
        $since = trim((string)($cfg['last_saved_run'] ?? ''));
        if ($since === '') {
            $since = date('Y-m-d H:i:s', time() - 3600); // اولین اجرا: یک ساعت اخیر
        }
        try {
            $ads = $pdo->prepare("SELECT id, title, transaction_type, property_type, area, rooms, location, address, price_sell, total_price, price_condition, deposit FROM ads WHERE status = 'published' AND published_at IS NOT NULL AND published_at > ?");
            $ads->execute([$since]);
            $newAds = $ads->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $log[] = 'saved_search: ads query failed';
            return;
        }
        if (!$newAds) {
            smsProgramSaveSettings($pdo, ['last_saved_run' => date('Y-m-d H:i:s')]);
            return;
        }
        $searches = $pdo->query('SELECT * FROM saved_searches WHERE notify = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $linkBase = smsProgramSiteUrl() . '/property-details.php?id=';
        foreach ($searches as $s) {
            $phone = smsProgramNormPhone((string)($s['phone'] ?? ''));
            if (!preg_match('/^09\d{9}$/', $phone) || smsProgramOptedOut($pdo, $phone, 'alerts')) {
                continue;
            }
            $matched = [];
            foreach ($newAds as $ad) {
                if (!smsProgramAdMatches($ad, $s)) {
                    continue;
                }
                // «کدوم ملک برای کدوم مشتری»: هر (جستجو، آگهی) فقط یک‌بار — در هر وضعیتی
                $adKey = '"ad_id":' . json_encode((string)$ad['id']); // json_encode خودش کوتیشن می‌گذارد
                $st = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE goal = 'saved_search' AND meta_json LIKE ? AND meta_json LIKE ?");
                $st->execute(['%"search_id":' . (int)$s['id'] . '%', '%' . $adKey . '%']);
                if ((int)$st->fetchColumn() > 0) {
                    continue;
                }
                $matched[] = $ad;
            }
            if (!$matched) {
                continue;
            }
            $link = $linkBase . rawurlencode((string)$matched[0]['id']);
            if ($minNew <= 1) {
                // تک‌ملکی: برای هر ملک جدید یک پیامک با قالب ادمین
                foreach ($matched as $ad) {
                    $sum = smsProgramAdSummary($ad);
                    $body = smsProgramFill($tpl, [
                        '{property}' => $sum['property'],
                        '{location}' => $sum['location'],
                        '{price}'    => $sum['price'],
                        '{title}'    => $sum['title'],
                        '{ad_id}'    => $sum['ad_id'],
                        '{link}'     => $linkBase . rawurlencode($sum['ad_id']),
                    ]) . smsProgramLagoo($cfg);
                    smsProgramQueue($pdo, 'saved_search', $phone, $body, ['search_id' => (int)$s['id'], 'ad_id' => $sum['ad_id'], 'ad_title' => $sum['title']]);
                    $log[] = 'saved_search: queued ad ' . $sum['ad_id'] . ' → ' . $phone;
                }
                continue;
            }
            // تجمیعی: تا حد نصاب پر نشده، در صف «held» نگه داشته می‌شود
            $gst = $pdo->prepare("SELECT id, meta_json FROM sms_outbox WHERE goal = 'saved_search' AND status = 'held' AND meta_json LIKE ?");
            $gst->execute(['%"search_id":' . (int)$s['id'] . '%']);
            $held = $gst->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $heldIds = [];
            $lines = [];
            foreach ($held as $h) {
                $hm = json_decode((string)$h['meta_json'], true) ?: [];
                $heldIds[] = (int)$h['id'];
                $lines[] = (string)($hm['line'] ?? '');
            }
            $newHeldIds = [];
            foreach ($matched as $ad) {
                $sum = smsProgramAdSummary($ad);
                $line = '• ' . $sum['property'] . ($sum['location'] !== '' ? ' — ' . $sum['location'] : '') . ($sum['price'] !== '' ? ' — ' . $sum['price'] : '');
                $lines[] = $line;
                $newHeldIds[] = smsProgramQueue($pdo, 'saved_search', $phone, '[held] ' . $sum['property'], [
                    'search_id' => (int)$s['id'], 'ad_id' => $sum['ad_id'], 'ad_title' => $sum['title'], 'line' => $line,
                ], 'held');
            }
            $total = count($lines);
            if ($total < $minNew) {
                $log[] = 'saved_search: ' . $total . ' ملکِ نگه‌داری‌شده (حد نصاب ' . $minNew . ') — هنوز پیامک نمی‌رود';
                continue;
            }
            // حد نصاب پر شد: ردیف‌های held ادغام و پیامک خلاصه ساخته می‌شود
            $num = 0;
            $numLines = array_map(static function ($l) use (&$num) {
                $num++;
                return smsProgramFaNum($num) . ') ' . $l;
            }, $lines);
            $body = smsProgramFill($digestTpl, [
                '{count}' => smsProgramFaNum($total),
                '{list}'  => implode("\n", $numLines),
                '{link}'  => $link,
            ]) . smsProgramLagoo($cfg);
            smsProgramQueue($pdo, 'saved_search', $phone, $body, ['search_id' => (int)$s['id'], 'digest' => $total]);
            foreach (array_merge($heldIds, $newHeldIds) as $hid) {
                $pdo->prepare("UPDATE sms_outbox SET status = 'merged' WHERE id = ?")->execute([$hid]);
            }
            $log[] = 'saved_search: digest ' . $total . ' ملک → ' . $phone;
        }
        smsProgramSaveSettings($pdo, ['last_saved_run' => date('Y-m-d H:i:s')]);
    }

    /* =====================================================================
     * هدف ۳ — انطباق درخواست مشتری: فایل‌های منطبقِ اطلاع‌داده‌نشده
     * ===================================================================== */

    function smsProgramJobRequestMatches(PDO $pdo, array $cfg, array &$log): void
    {
        $g = $cfg['request_match'];
        if (empty($g['on']) || empty($cfg['enabled'])) {
            return;
        }
        $minNew = max(1, (int)($g['min_new'] ?? 1));
        $tpl = (string)($g['template'] ?? '');
        $scoreMin = (float)($g['score_min'] ?? 0);
        $rows = $pdo->prepare(
            "SELECT m.id AS match_id, m.request_id, m.ad_id, m.match_percent, m.is_notified,
                    r.phone, r.id AS req_id
             FROM request_matches m
             JOIN property_requests r ON r.id = m.request_id
             WHERE m.is_notified = 0 AND m.match_percent >= :smin
               AND COALESCE(r.phone, '') <> ''
               AND COALESCE(r.status, 'new') NOT IN ('rejected', 'closed')
             ORDER BY m.request_id ASC, m.match_percent DESC"
        );
        $rows->execute(['smin' => $scoreMin]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return;
        }
        $byReq = [];
        foreach ($rows as $r) {
            $byReq[(int)$r['request_id']][] = $r;
        }
        foreach ($byReq as $reqId => $matches) {
            $phone = smsProgramNormPhone((string)$matches[0]['phone']);
            if (!preg_match('/^09\d{9}$/', $phone)) {
                continue;
            }
            if (smsProgramOptedOut($pdo, $phone, 'alerts')) {
                continue;
            }
            $fresh = [];
            foreach ($matches as $m) {
                $adKey = '"ad_id":' . json_encode((string)$m['ad_id']);
                $st = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE goal = 'request_match' AND meta_json LIKE ? AND meta_json LIKE ?");
                $st->execute(['%"request_id":' . $reqId . '%', '%' . $adKey . '%']);
                if ((int)$st->fetchColumn() === 0) {
                    $fresh[] = $m;
                }
            }
            if (!$fresh) {
                continue;
            }
            // حد نصاب: اگر فایل‌های جدیدِ انباشته به minNew نرسد، پیامکی نمی‌رود
            // (is_notified صبحت نمی‌ماند و موارد تیک بعدی جمع می‌شوند)
            if (count($fresh) < $minNew) {
                $log[] = 'request_match: request ' . $reqId . ' → ' . count($fresh) . ' فایل جدید (حد نصاب ' . $minNew . ') — صبر';
                continue;
            }
            $link = smsProgramSiteUrl() . '/my-request-matches.php';
            $n = count($fresh);
            $body = smsProgramFill($tpl, [
                '{count}' => smsProgramFaNum($n),
                '{link}'  => $link,
            ]) . smsProgramLagoo($cfg);
            smsProgramQueue($pdo, 'request_match', $phone, $body, [
                'request_id' => $reqId,
                'ad_ids' => array_map(static fn($m) => (string)$m['ad_id'], $fresh),
                'match_ids' => array_map(static fn($m) => (int)$m['match_id'], $fresh),
            ]);
            $log[] = 'request_match: queued ' . $n . ' match(es) for request ' . $reqId . ' → ' . $phone;
        }
    }

    /* =====================================================================
     * هدف ۴ — هشدار ادمین: صف در انتظار بیش از حد مجاز
     * ===================================================================== */

    function smsProgramJobAdminAlerts(PDO $pdo, array $cfg, array &$log): void
    {
        $g = $cfg['admin_alert'];
        if (empty($g['on']) || empty($cfg['enabled'])) {
            return;
        }
        $phone = smsProgramNormPhone((string)($g['phone'] ?? ''));
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return; // شماره مدیر تنظیم نشده
        }
        $tpl = (string)($g['template'] ?? '');
        $mode = (($g['mode'] ?? 'over') === 'every') ? 'every' : 'over';

        $pendingAds = 0;
        try {
            $pendingAds = (int)$pdo->query("SELECT COUNT(*) FROM ads WHERE status IN ('pending','new','waiting')")->fetchColumn();
        } catch (Throwable $e) {
        }
        $pendingReqs = 0;
        try {
            $pendingReqs = (int)$pdo->query("SELECT COUNT(*) FROM property_requests WHERE status = 'new'")->fetchColumn();
        } catch (Throwable $e) {
        }

        // [goal key => [متن هشدار, شمارندهٔ فعلی]]
        $fire = [];
        $newBaselines = null;

        if ($mode === 'over') {
            // حالت «بیشتر از سقف» + ضدتکرار ساعتی
            $adsThr = max(1, (int)($g['ads_thr'] ?? 10));
            $reqThr = max(1, (int)($g['req_thr'] ?? 10));
            $debounce = max(1, (int)($g['debounce_h'] ?? 6));
            $st = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE goal = 'admin_alert' AND meta_json LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)");
            if ($pendingAds > $adsThr) {
                $st->execute(['%"alert":"pending_ads"%', $debounce]);
                if ((int)$st->fetchColumn() === 0) {
                    $fire['pending_ads'] = ['آگهی در انتظار تأیید: ' . smsProgramFaNum($pendingAds) . ' عدد (بیش از سقف ' . smsProgramFaNum($adsThr) . ')', $pendingAds, $adsThr];
                }
            }
            if ($pendingReqs > $reqThr) {
                $st->execute(['%"alert":"pending_requests"%', $debounce]);
                if ((int)$st->fetchColumn() === 0) {
                    $fire['pending_requests'] = ['درخواست مشتری در انتظار بررسی: ' . smsProgramFaNum($pendingReqs) . ' عدد (بیش از سقف ' . smsProgramFaNum($reqThr) . ')', $pendingReqs, $reqThr];
                }
            }
        } else {
            // حالت «هر N موردِ جدید»: از آخرین پیامک، هر N مورد جدید یک پیامک
            $everyAds = max(1, (int)($g['every_n'] ?? 20));
            $everyReqs = max(1, (int)($g['every_req_n'] ?? $g['every_n'] ?? 20));
            $lastAds = (int)($g['last_ads'] ?? 0);
            $lastReqs = (int)($g['last_reqs'] ?? 0);
            $newBaselines = ['last_ads' => $lastAds, 'last_reqs' => $lastReqs];
            $dAds = $pendingAds - $lastAds;
            if ($dAds < 0) {
                $newBaselines['last_ads'] = $pendingAds; // صف کم شده (تأیید/رد) → مبنای جدید
            } elseif ($dAds >= $everyAds) {
                $fire['pending_ads'] = ['از آخرین پیامک، ' . smsProgramFaNum($dAds) . ' آگهی جدید در صف تأیید ثبت شد (اکنون ' . smsProgramFaNum($pendingAds) . ' در انتظار)', $dAds, $everyAds];
                $newBaselines['last_ads'] = $pendingAds;
            }
            $dReqs = $pendingReqs - $lastReqs;
            if ($dReqs < 0) {
                $newBaselines['last_reqs'] = $pendingReqs;
            } elseif ($dReqs >= $everyReqs) {
                $fire['pending_requests'] = ['از آخرین پیامک، ' . smsProgramFaNum($dReqs) . ' درخواست جدید مشتری ثبت شد (اکنون ' . smsProgramFaNum($pendingReqs) . ' در انتظار بررسی)', $dReqs, $everyReqs];
                $newBaselines['last_reqs'] = $pendingReqs;
            }
        }

        foreach ($fire as $key => $f) {
            $body = smsProgramFill($tpl, [
                '{text}'      => $f[0],
                '{count}'     => smsProgramFaNum($f[1]),
                '{threshold}' => smsProgramFaNum($f[2]),
            ]);
            smsProgramQueue($pdo, 'admin_alert', $phone, $body, ['alert' => $key, 'count' => $f[1]]);
            $log[] = 'admin_alert: queued ' . $key . ' → ' . $phone;
        }
        if ($newBaselines !== null) {
            smsProgramSaveSettings($pdo, ['admin_alert' => array_merge($g, $newBaselines)]);
        }
    }

    /* =====================================================================
     * هدف ۵ — کمپین تبلیغاتی زمان‌دار (کمپین‌های status='scheduled')
     * ===================================================================== */

    function smsProgramJobScheduledCampaigns(PDO $pdo, array $cfg, array &$log): void
    {
        $g = $cfg['marketing'];
        if (empty($g['on']) || empty($cfg['enabled'])) {
            return;
        }
        $camps = $pdo->query("SELECT * FROM comm_campaigns WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW() ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($camps as $c) {
            // ارسال کمپین فقط از خط تبلیغاتی (مقررات ملی پیامک)
            $send = smsProgramSendCampaign($pdo, $cfg, (int)$c['id'], $log);
            if ($send === null) {
                // خط تبلیغاتی تنظیم نیست → به‌جای تلاش بی‌پایان، کمپین برمی‌گردد به draft
                $pdo->prepare("UPDATE comm_campaigns SET status = 'draft' WHERE id = ?")->execute([(int)$c['id']]);
                $log[] = 'campaign ' . $c['id'] . ': خط تبلیغاتی تنظیم نشده → بازگشت به پیش‌نویس';
            }
        }
    }

    /** اجرای یک کمپین روی مخاطبان سگمنت — با قواعد ملی پیامک. null = خط تبلیغاتی نیست */
    function smsProgramSendCampaign(PDO $pdo, array $cfg, int $campaignId, array &$log): ?int
    {
        $sms = function_exists('smsEffectiveSettings') ? smsEffectiveSettings() : ['enabled' => false];
        $promoLine = trim((string)($sms['promo_line'] ?? ''));
        if (empty($sms['enabled']) || $promoLine === '') {
            return null;
        }
        $st = $pdo->prepare('SELECT * FROM comm_campaigns WHERE id = ? LIMIT 1');
        $st->execute([$campaignId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return 0;
        }
        $crit = [];
        if (!empty($c['segment_id'])) {
            $s = $pdo->prepare('SELECT criteria FROM comm_segments WHERE id = ?');
            $s->execute([(int)$c['segment_id']]);
            $crit = json_decode((string)($s->fetchColumn() ?: '{}'), true) ?: [];
        }
        // مخاطبان کمپین: مخاطبین تب «پنل پیامک» (بر پایهٔ سگمنت) + رعایت لغو عضویت
        $where = "status = 'active'";
        $params = [];
        if (!empty($crit['role'])) {
            $where .= ' AND roles_suggested LIKE ?';
            $params[] = '%' . $crit['role'] . '%';
        }
        if (!empty($crit['search'])) {
            $where .= ' AND (phone LIKE ? OR name LIKE ?)';
            $params[] = '%' . $crit['search'] . '%';
            $params[] = '%' . $crit['search'] . '%';
        }
        $st = $pdo->prepare("SELECT id, phone FROM comm_contacts WHERE $where LIMIT 500");
        $st->execute($params);
        $people = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ok = 0;
        $fail = 0;
        $skip = 0;
        foreach ($people as $p) {
            $phone = smsProgramNormPhone((string)$p['phone']);
            if (!preg_match('/^09\d{9}$/', $phone) || smsProgramOptedOut($pdo, $phone, 'promo')) {
                $skip++;
                continue;
            }
            $body = trim((string)$c['body']) . "\n" . 'لغو11';
            $id = smsProgramQueue($pdo, 'campaign', $phone, $body, ['campaign_id' => $campaignId]);
            $ok++;
        }
        $pdo->prepare("UPDATE comm_campaigns SET status = 'completed', recipients_n = ?, sent_n = 0, fail_n = ?, sent_at = NOW() WHERE id = ?")
            ->execute([count($people), $skip, $campaignId]);
        $log[] = 'campaign ' . $campaignId . ': ' . $ok . ' پیام در صف (خط تبلیغاتی ' . $promoLine . ')';
        return $ok;
    }

    /* =====================================================================
     * ارسال صف (dispatcher) — با سقف/ساعات/لغو عضویت
     * ===================================================================== */

    function smsProgramDispatch(PDO $pdo, array $cfg, array &$log): array
    {
        $max = max(1, (int)($cfg['max_per_tick'] ?? 15));
        $sent = 0;
        $failed = 0;
        $legacySms = function_exists('smsEffectiveSettings') ? smsEffectiveSettings() : [];
        $rows = $pdo->query("SELECT * FROM sms_outbox WHERE status = 'queued' ORDER BY id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if ($sent + $failed >= $max) {
                break;
            }
            $goal = (string)$row['goal'];
            $phone = (string)$row['phone'];
            // سقف روزانهٔ هر شماره برای پیامک‌های اطلاع‌رسانی
            if (in_array($goal, ['saved_search', 'request_match'], true)) {
                $cap = $goal === 'saved_search'
                    ? max(1, (int)($cfg['saved_search']['cap_day'] ?? 2))
                    : max(1, (int)($cfg['request_match']['cap_day'] ?? 1));
                $st = $pdo->prepare("SELECT COUNT(*) FROM sms_outbox WHERE goal = ? AND phone = ? AND status = 'sent' AND created_at >= CURDATE()");
                $st->execute([$goal, $phone]);
                if ((int)$st->fetchColumn() >= $cap) {
                    continue; // امروز سهمیه‌اش پر است — برای فردا می‌ماند
                }
            }
            // ساعات سکوت: فقط پیامک‌های انبوهِ اطلاع‌رسانی/تبلیغاتی (OTP و ادمین مستثنی)
            if (in_array($goal, ['saved_search', 'request_match', 'campaign'], true)) {
                $h = (int)date('G');
                if (smsProgramInQuiet($h, (int)$cfg['quiet_start'], (int)$cfg['quiet_end'])) {
                    continue;
                }
            }
            // مسیریابی: خط هدف (یا خط قدیمی/پیش‌فرض) — خط غیرفعال = در صف می‌ماند
            $pick = smsProgramResolveLine($cfg, $legacySms, smsProgramPurposeOfGoal($goal));
            if ($pick['number'] === '') {
                $log[] = 'dispatch: wait id=' . $row['id'] . ' → ' . $pick['reason'];
                continue;
            }
            if ((int)$pick['daily_cap'] > 0 && smsProgramLineSentToday($pdo, $pick['number']) >= (int)$pick['daily_cap']) {
                continue; // سقف روزانهٔ این خط پر است — برای فردا می‌ماند
            }
            $res = smsSendText($phone, (string)$row['body'], $pick['number']);
            if (!empty($res['success'])) {
                $pdo->prepare("UPDATE sms_outbox SET status = 'sent', rec_id = ?, line = ?, sent_at = NOW(), fail_reason = NULL WHERE id = ?")
                    ->execute([(string)($res['rec_id'] ?? ''), $pick['number'], (int)$row['id']]);
                $sent++;
                // انطباق: علامت‌گذاری ردیف‌های تطبیقِ اطلاع‌داده‌شده
                if ($goal === 'request_match') {
                    $meta = json_decode((string)$row['meta_json'], true) ?: [];
                    foreach (($meta['match_ids'] ?? []) as $mid) {
                        $pdo->prepare('UPDATE request_matches SET is_notified = 1 WHERE id = ?')->execute([(int)$mid]);
                    }
                }
                if ($goal === 'saved_search') {
                    $meta = json_decode((string)$row['meta_json'], true) ?: [];
                    $pdo->prepare('UPDATE saved_searches SET last_notified_at = NOW() WHERE id = ?')
                        ->execute([(int)($meta['search_id'] ?? 0)]);
                }
            } else {
                $pdo->prepare("UPDATE sms_outbox SET status = 'failed', fail_reason = ? WHERE id = ?")
                    ->execute([mb_substr((string)($res['message'] ?? 'خطای نامشخص'), 0, 250), (int)$row['id']]);
                $failed++;
                $log[] = 'dispatch: failed id=' . $row['id'] . ' → ' . ($res['message'] ?? '');
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /* =====================================================================
     * تیک اصلی برنامه
     * ===================================================================== */

    function smsProgramTick(PDO $pdo, bool $force = false): array
    {
        smsProgramEnsureSchema($pdo);
        $cfg = smsProgramSettings($pdo);
        $log = [];
        $out = ['ok' => true, 'skipped' => '', 'queued' => 0, 'sent' => 0, 'failed' => 0, 'log' => &$log];

        if (empty($cfg['enabled']) && !$force) {
            $out['skipped'] = 'برنامهٔ پیامک غیرفعال است.';
            return $out;
        }
        // ضد اجرای پشت‌سرهم (تیک ترافیکی) — اجرای دستی همیشه انجام می‌شود
        if (!$force) {
            $minInt = 4;
            if (!empty($cfg['last_tick'])) {
                $last = strtotime((string)$cfg['last_tick']);
                if ($last !== false && (time() - $last) < $minInt * 60) {
                    $out['skipped'] = 'اجرای قبلی خیلی نزدیک بود.';
                    return $out;
                }
            }
        }
        smsProgramSaveSettings($pdo, ['last_tick' => date('Y-m-d H:i:s')]);

        smsProgramJobAdminAlerts($pdo, $cfg, $log);
        smsProgramJobSavedSearches($pdo, $cfg, $log);
        smsProgramJobRequestMatches($pdo, $cfg, $log);
        smsProgramJobScheduledCampaigns($pdo, $cfg, $log);
        $d = smsProgramDispatch($pdo, $cfg, $log);
        $out['sent'] = $d['sent'];
        $out['failed'] = $d['failed'];
        $c = $pdo->query("SELECT COUNT(*) FROM sms_outbox WHERE status = 'queued'")->fetchColumn();
        $out['queued'] = (int)$c;
        return $out;
    }

    /** قلاب ترافیکی: با بازدید سایت، حداکثر هر ۵ دقیقه یک‌بار در پایان پاسخ اجرا می‌شود. */
    function smsProgramTrafficTick(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        $marker = __DIR__ . '/uploads/cache/sms-program.tick';
        if (@filemtime($marker) !== false && (time() - (int)@filemtime($marker)) < 300) {
            return; // بهینه: قبل از هر کوئری، فقط با mtime
        }
        @mkdir(dirname($marker), 0755, true);
        @touch($marker);
        if (!class_exists('PDO')) {
            return;
        }
        register_shutdown_function(static function () use ($marker) {
            try {
                if (!function_exists('melkinoInitDbGlobal')) {
                    return;
                }
                $pdo = melkinoInitDbGlobal();
                if (!$pdo instanceof PDO) {
                    return;
                }
                @touch($marker);
                smsProgramTick($pdo, false);
            } catch (Throwable $e) {
                // تیک ترافیکی هرگز صفحه را نمی‌شکند
            }
        });
    }
}
