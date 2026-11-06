<?php
/**
 * درخواست بازدید ملک — جدول، روزهای هفته، وضعیت‌ها.
 */
if (!function_exists('melkinoVisitNormalizeDigits')) {
    function melkinoVisitNormalizeDigits(string $value): string
    {
        return strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}

if (!function_exists('melkinoVisitNormalizePhone')) {
    function melkinoVisitNormalizePhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', melkinoVisitNormalizeDigits($value));
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '0098') === 0) {
            $digits = substr($digits, 4);
        } elseif (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '0' . $digits;
        }
        return $digits;
    }
}

if (!function_exists('melkinoVisitIsMobile')) {
    function melkinoVisitIsMobile(string $phone): bool
    {
        return (bool) preg_match('/^09\d{9}$/', melkinoVisitNormalizePhone($phone));
    }
}

if (!function_exists('melkinoVisitRequesterGate')) {
    /**
     * درخواست بازدید فقط وقتی مجاز است که کاربر وارد شده باشد و شماره موبایل ثبت شده باشد.
     * @return array{ok:bool,login:bool,need_phone:bool,message:string,phone:string}
     */
    function melkinoVisitRequesterGate(array $identity): array
    {
        $user = is_array($identity['user'] ?? null) ? $identity['user'] : [];
        $logged = !empty($identity['user_id'])
            || trim((string) ($identity['telegram_id'] ?? '')) !== ''
            || trim((string) ($identity['bale_id'] ?? '')) !== ''
            || trim((string) ($identity['eitaa_id'] ?? '')) !== '';
        $phone = melkinoVisitNormalizePhone((string) ($identity['phone'] ?? ''));
        if ($phone === '' && !empty($user['phone'])) {
            $phone = melkinoVisitNormalizePhone((string) $user['phone']);
        }
        if (!$logged) {
            return [
                'ok' => false,
                'login' => true,
                'need_phone' => false,
                'message' => 'برای درخواست بازدید ابتدا وارد حساب شوید.',
                'phone' => '',
            ];
        }
        if (!melkinoVisitIsMobile($phone)) {
            return [
                'ok' => false,
                'login' => false,
                'need_phone' => true,
                'message' => 'برای درخواست بازدید ابتدا شماره موبایل خود را در پروفایل ثبت کنید.',
                'phone' => $phone,
            ];
        }
        return [
            'ok' => true,
            'login' => false,
            'need_phone' => false,
            'message' => '',
            'phone' => $phone,
        ];
    }
}

if (!function_exists('melkinoVisitSlots')) {
    function melkinoVisitSlots(): array
    {
        return [
            'morning' => 'صبح',
            'evening' => 'عصر',
        ];
    }
}

if (!function_exists('melkinoVisitStatuses')) {
    function melkinoVisitStatuses(): array
    {
        return [
            'new' => 'جدید',
            'scheduled_owner' => 'هماهنگ شده با مالک',
            'owner_rejected_date' => 'رد تاریخ توسط مالک',
            'user_notified' => 'اطلاع داده شده به کاربر',
            'cancelled_by_user' => 'لغو بازدید توسط کاربر',
            'time_changed_by_user' => 'تغییر زمان توسط کاربر',
            'user_no_response' => 'عدم پاسخگویی کاربر',
            'visited' => 'بازدید شده',
        ];
    }
}

if (!function_exists('melkinoVisitNormalizeStatus')) {
    function melkinoVisitNormalizeStatus(string $status): string
    {
        $map = [
            'scheduled' => 'scheduled_owner',
            'confirmed' => 'scheduled_owner',
            'done' => 'user_notified',
            'cancelled' => 'cancelled_by_user',
        ];
        $status = trim($status);
        if (isset($map[$status])) {
            return $map[$status];
        }
        return $status !== '' ? $status : 'new';
    }
}

if (!function_exists('melkinoVisitWeekdayNames')) {
    function melkinoVisitWeekdayNames(): array
    {
        return ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
    }
}

if (!function_exists('melkinoVisitJalaliMonths')) {
    function melkinoVisitJalaliMonths(): array
    {
        return ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    }
}

if (!function_exists('melkinoVisitAddColumn')) {
    function melkinoVisitAddColumn(PDO $pdo, string $column, string $definition): void
    {
        try {
            $pdo->exec('ALTER TABLE visit_requests ADD COLUMN `' . str_replace('`', '', $column) . '` ' . $definition);
        } catch (Throwable $e) {
            // ستون از قبل هست
        }
    }
}

if (!function_exists('melkinoEnsureVisitRequestSchema')) {
    function melkinoEnsureVisitRequestSchema(): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return false;
        }
        static $done = false;
        if ($done) {
            return true;
        }
        // پروب سبک: اگر آخرین ستون مهاجرت وجود دارد، کل مهاجرت قبلاً اجرا شده و نیازی به ۱۶ دستور DDL در هر درخواست نیست.
        try {
            $probe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_requests' AND COLUMN_NAME = 'requester_phone'");
            if ($probe && (int) $probe->fetchColumn() > 0) {
                $done = true;
                return true;
            }
        } catch (Throwable $e) {
            // پروب ناموفق: مهاجرت کامل اجرا می‌شود (رفتار قبلی).
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS visit_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ad_id VARCHAR(64) NOT NULL,
                ad_title VARCHAR(500) NULL,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                phone VARCHAR(30) NULL,
                name VARCHAR(200) NULL,
                preferred_date DATE NOT NULL,
                preferred_date_fa VARCHAR(40) NULL,
                weekday VARCHAR(40) NULL,
                time_slot VARCHAR(20) NOT NULL DEFAULT 'morning',
                alternative_datetime TEXT NULL,
                advertiser_last_name VARCHAR(120) NULL,
                advertiser_phone VARCHAR(30) NULL,
                ad_snapshot LONGTEXT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'new',
                admin_note TEXT NULL,
                created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_ad (ad_id),
                KEY idx_owner (user_id, telegram_id),
                KEY idx_phone (phone),
                KEY idx_status (status),
                KEY idx_date (preferred_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            melkinoVisitAddColumn($pdo, 'alternative_datetime', 'TEXT NULL');
            melkinoVisitAddColumn($pdo, 'advertiser_last_name', 'VARCHAR(120) NULL');
            melkinoVisitAddColumn($pdo, 'advertiser_phone', 'VARCHAR(30) NULL');
            melkinoVisitAddColumn($pdo, 'ad_snapshot', 'LONGTEXT NULL');
            melkinoVisitAddColumn($pdo, 'tracking_code', 'VARCHAR(30) NULL');
            melkinoVisitAddColumn($pdo, 'archived', 'TINYINT(1) NOT NULL DEFAULT 0');
            melkinoVisitAddColumn($pdo, 'requester_name', 'VARCHAR(120) NULL');
            melkinoVisitAddColumn($pdo, 'requester_phone', 'VARCHAR(30) NULL');
            try {
                $pdo->exec("ALTER TABLE visit_requests MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'new'");
            } catch (Throwable $e) {
            }
            try {
                $pdo->exec('CREATE INDEX idx_vr_track ON visit_requests (tracking_code)');
            } catch (Throwable $e) {
            }
            if (function_exists('melkinoVisitMigrateGregorianDates')) {
                melkinoVisitMigrateGregorianDates($pdo);
            }
            $done = true;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('melkinoVisitWeekdayAdminOrder')) {
    /** ترتیب ایرانی شنبه تا جمعه: [php w, نام] */
    function melkinoVisitWeekdayAdminOrder(): array
    {
        $names = melkinoVisitWeekdayNames();
        $order = [6, 0, 1, 2, 3, 4, 5];
        $out = [];
        foreach ($order as $w) {
            $out[] = ['w' => $w, 'name' => $names[$w]];
        }
        return $out;
    }
}

if (!function_exists('melkinoJalaliToGregorian')) {
    function melkinoJalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + ((int) ($jy / 33) * 8) + (int) ((($jy % 33) + 3) / 4) + $jd
            + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));
        $gy = 400 * (int) ($days / 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * (int) (--$days / 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * (int) ($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, ((($gy % 4 === 0) && ($gy % 100 !== 0)) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for ($i = 1; $i <= 12; $i++) {
            $v = $sal_a[$i];
            if ($gd <= $v) {
                $gm = $i;
                break;
            }
            $gd -= $v;
        }
        return [$gy, $gm, $gd];
    }
}

if (!function_exists('melkinoVisitClosedSettings')) {
    /** @return array{weekdays:int[],dates:string[]} */
    function melkinoVisitClosedSettings(): array
    {
        $weekdays = [5]; // پیش‌فرض: جمعه
        $dates = [];
        global $pdo;
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            try {
                $raw = dbSettingGet($pdo, 'visit', 'closed_days', null);
                if (is_array($raw)) {
                    if (isset($raw['weekdays']) && is_array($raw['weekdays'])) {
                        $weekdays = [];
                        foreach ($raw['weekdays'] as $w) {
                            $w = (int) $w;
                            if ($w >= 0 && $w <= 6) {
                                $weekdays[] = $w;
                            }
                        }
                        $weekdays = array_values(array_unique($weekdays));
                    }
                    if (isset($raw['dates']) && is_array($raw['dates'])) {
                        foreach ($raw['dates'] as $d) {
                            $d = trim((string) $d);
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                                $dates[] = $d;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return ['weekdays' => $weekdays, 'dates' => $dates];
    }
}

if (!function_exists('melkinoVisitCapacity')) {
    /** ظرفیت بازدید روزانه برای هر بازهٔ زمانی — از پنل ادمین قابل تغییر */
    function melkinoVisitCapacity(): array
    {
        $cap = ['morning' => 0, 'evening' => 0]; // ۰ = بدون محدودیت
        global $pdo;
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            try {
                $raw = dbSettingGet($pdo, 'visit', 'capacity', null);
                if (is_array($raw)) {
                    foreach (['morning', 'evening'] as $k) {
                        $v = (int) ($raw[$k] ?? 0);
                        $cap[$k] = max(0, min(200, $v));
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return $cap;
    }
}

if (!function_exists('melkinoVisitDayCounts')) {
    /** تعداد بازدیدِ ثبت‌شدهٔ هر روز/بازه (بایگانی‌شده‌ها و حذف‌شده‌ها حساب نمی‌شوند) */
    function melkinoVisitDayCounts(array $dates): array
    {
        global $pdo;
        $out = [];
        if (!($pdo instanceof PDO) || !$dates) {
            return $out;
        }
        try {
            $in = implode(',', array_fill(0, count($dates), '?'));
            $st = $pdo->prepare("SELECT preferred_date, time_slot, COUNT(*) c FROM visit_requests
                                 WHERE archived = 0 AND status <> 'cancelled_by_user'
                                   AND preferred_date IN ($in)
                                 GROUP BY preferred_date, time_slot");
            $st->execute($dates);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['preferred_date']][(string) $r['time_slot']] = (int) $r['c'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}

if (!function_exists('melkinoVisitParseDateLine')) {
    function melkinoVisitParseDateLine(string $line): ?string
    {
        $line = melkinoVisitNormalizeDigits(trim($line));
        if ($line === '') {
            return null;
        }
        $line = str_replace(['.', ' ', '،'], ['/', '', ''], $line);
        $line = str_replace('-', '/', $line);
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $line, $m)) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($y > 1700) {
            if (!checkdate($mo, $d, $y)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }
        [$gy, $gm, $gd] = melkinoJalaliToGregorian($y, $mo, $d);
        if (!checkdate((int) $gm, (int) $gd, (int) $gy)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}

if (!function_exists('melkinoVisitSaveClosedSettings')) {
    function melkinoVisitSaveClosedSettings(array $weekdays, array $dates): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
            return false;
        }
        $wOut = [];
        foreach ($weekdays as $w) {
            $w = (int) $w;
            if ($w >= 0 && $w <= 6) {
                $wOut[] = $w;
            }
        }
        $wOut = array_values(array_unique($wOut));
        $dOut = [];
        foreach ($dates as $d) {
            $iso = is_string($d) ? melkinoVisitParseDateLine($d) : null;
            if ($iso) {
                $dOut[] = $iso;
            }
        }
        $dOut = array_values(array_unique($dOut));
        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        return dbSettingSet($pdo, 'visit', 'closed_days', ['weekdays' => $wOut, 'dates' => $dOut], 'json', $adminId);
    }
}

if (!function_exists('melkinoVisitIsClosedDay')) {
    function melkinoVisitIsClosedDay(string $isoDate, int $phpW, array $settings): bool
    {
        if (in_array($phpW, $settings['weekdays'] ?? [], true)) {
            return true;
        }
        return in_array($isoDate, $settings['dates'] ?? [], true);
    }
}

if (!function_exists('melkinoVisitNextDays')) {
    function melkinoVisitNextDays(int $n = 7): array
    {
        require_once __DIR__ . '/jalali-lib.php';
        $tz = new DateTimeZone('Asia/Tehran');
        $weekdays = melkinoVisitWeekdayNames();
        $months = melkinoVisitJalaliMonths();
        $closed = melkinoVisitClosedSettings();
        $out = [];
        $today = new DateTimeImmutable('now', $tz);
        $start = $today->modify('+1 day');
        $count = max(1, min(14, $n));
        for ($i = 0; $i < $count; $i++) {
            $d = $start->modify('+' . $i . ' day');
            $gy = (int) $d->format('Y');
            $gm = (int) $d->format('n');
            $gd = (int) $d->format('j');
            [$jy, $jm, $jd] = melkinoGregorianToJalali($gy, $gm, $gd);
            $wIndex = (int) $d->format('w');
            $w = $weekdays[$wIndex];
            $iso = $d->format('Y-m-d');
            $isClosed = melkinoVisitIsClosedDay($iso, $wIndex, $closed);
            $monthName = $months[$jm - 1] ?? '';
            $dayFa = function_exists('melkinoFaDigits') ? melkinoFaDigits((string) $jd) : (string) $jd;
            $yearFa = function_exists('melkinoFaDigits') ? melkinoFaDigits((string) $jy) : (string) $jy;
            $dateLabel = trim($dayFa . ' ' . $monthName . ' ' . $yearFa);
            $label = $w . ' ' . $dateLabel;
            $out[] = [
                'date' => $iso,
                'jalali' => $jy . '/' . str_pad((string) $jm, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string) $jd, 2, '0', STR_PAD_LEFT),
                'date_label' => $dateLabel,
                'weekday' => $w,
                'label' => $label,
                'w' => $wIndex,
                'friday' => $wIndex === 5,
                'closed' => $isClosed,
                'selectable' => !$isClosed,
            ];
        }
        return $out;
    }
}

if (!function_exists('melkinoVisitOwn')) {
    function melkinoVisitOwn(array $row, array $identity): bool
    {
        if (!empty($identity['user_id']) && (int) ($row['user_id'] ?? 0) === (int) $identity['user_id']) {
            return true;
        }
        $tg = trim((string) ($identity['telegram_id'] ?? ''));
        if ($tg !== '' && (string) ($row['telegram_id'] ?? '') === $tg) {
            return true;
        }
        $p1 = melkinoVisitNormalizePhone((string) ($row['phone'] ?? ''));
        $p2 = melkinoVisitNormalizePhone((string) ($identity['phone'] ?? ''));
        return $p1 !== '' && $p2 !== '' && $p1 === $p2;
    }
}

if (!function_exists('melkinoVisitOwnerWhere')) {
    /** @return array{0:string,1:array} */
    function melkinoVisitOwnerWhere(array $identity, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $parts = [];
        $params = [];
        if (!empty($identity['user_id'])) {
            $parts[] = $p . 'user_id = ?';
            $params[] = (int) $identity['user_id'];
        }
        $tg = trim((string) ($identity['telegram_id'] ?? ''));
        if ($tg !== '') {
            $parts[] = $p . 'telegram_id = ?';
            $params[] = $tg;
        }
        $phone = melkinoVisitNormalizePhone((string) ($identity['phone'] ?? ''));
        if ($phone !== '') {
            $parts[] = $p . 'phone = ?';
            $params[] = $phone;
        }
        if (!$parts) {
            return ['', []];
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}

if (!function_exists('melkinoVisitRequesterName')) {
    function melkinoVisitRequesterName(array $identity): string
    {
        $u = is_array($identity['user'] ?? null) ? $identity['user'] : [];
        $first = trim((string) ($u['first_name'] ?? ''));
        $last = trim((string) ($u['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }
        if (!empty($u['name'])) {
            return trim((string) $u['name']);
        }
        if (!empty($_SESSION['user_name'])) {
            return trim((string) $_SESSION['user_name']);
        }
        return '';
    }
}

if (!function_exists('melkinoVisitScalar')) {
    function melkinoVisitScalar($v): string
    {
        if (is_array($v) || is_object($v)) {
            return '';
        }
        $v = trim((string) $v);
        if ($v === '' || $v === '0' || $v === '0.0' || $v === '0.00' || $v === '0.000') {
            return '';
        }
        return $v;
    }
}

if (!function_exists('melkinoVisitToGregorianDate')) {
    /** ورودی هر قالب → Y-m-d میلادی یا '' */
    function melkinoVisitToGregorianDate(string $raw): string
    {
        $raw = melkinoVisitNormalizeDigits(trim($raw));
        if ($raw === '' || strpos($raw, '0000') === 0) {
            return '';
        }
        if ($raw instanceof DateTimeInterface) {
            $raw = $raw->format('Y-m-d');
        }
        if (!preg_match('/(\d{4})[\/\.\-](\d{1,2})[\/\.\-](\d{1,2})/', $raw, $m)) {
            return '';
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return '';
        }
        if ($y >= 1300 && $y <= 1599 && function_exists('melkinoJalaliToGregorian')) {
            [$gy, $gm, $gd] = melkinoJalaliToGregorian($y, $mo, $d);
            if ($gy < 1700) {
                return '';
            }
            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }
        if ($y >= 1700 && $y <= 2500) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return '';
    }
}

if (!function_exists('melkinoVisitMigrateGregorianDates')) {
    function melkinoVisitMigrateGregorianDates(PDO $pdo): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;
        try {
            $st = $pdo->query('SELECT id, preferred_date FROM visit_requests');
            $upd = $pdo->prepare('UPDATE visit_requests SET preferred_date = ? WHERE id = ?');
            foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
                $cur = trim((string) ($row['preferred_date'] ?? ''));
                $iso = melkinoVisitToGregorianDate($cur);
                if ($iso !== '' && $iso !== substr($cur, 0, 10)) {
                    $upd->execute([$iso, (int) $row['id']]);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoVisitMergeAdBag')) {
    /** ادغام ستون ads + property_details (با حذف پسوند _apt/_villa/…) */
    function melkinoVisitMergeAdBag(array $row, array $adInfo = []): array
    {
        $live = [];
        if (!empty($row['_ad']) && is_array($row['_ad'])) {
            $live = $row['_ad'];
        } elseif (!empty($adInfo['_ad']) && is_array($adInfo['_ad'])) {
            $live = $adInfo['_ad'];
        }
        $details = melkinoVisitDetailsArray(
            $live['property_details'] ?? ($adInfo['property_details'] ?? ($row['ad_snapshot'] ?? null))
        );
        if (isset($live['details'])) {
            $more = melkinoVisitDetailsArray($live['details']);
            if ($more) {
                $details = $details + $more;
            }
        }
        $snap = [];
        if (!empty($row['ad_snapshot'])) {
            $d = json_decode((string) $row['ad_snapshot'], true);
            if (is_array($d)) {
                $snap = $d;
            }
        }
        $bag = [];
        foreach ([$details, $snap, $live, $adInfo] as $src) {
            if (!is_array($src)) {
                continue;
            }
            foreach ($src as $k => $v) {
                if (!is_string($k) || $k === '' || $k[0] === '_') {
                    continue;
                }
                $sv = function_exists('melkinoVisitLooseScalar')
                    ? melkinoVisitLooseScalar($v)
                    : (is_array($v) || is_object($v) ? '' : melkinoVisitScalar($v));
                if ($sv === '' || $sv === '0' || $sv === '0.0' || $sv === '۰') {
                    continue;
                }
                $k2 = (string) preg_replace('/^pd\./', '', $k);
                if (!isset($bag[$k])) {
                    $bag[$k] = $sv;
                }
                if ($k2 !== $k && !isset($bag[$k2])) {
                    $bag[$k2] = $sv;
                }
                $canon = (string) preg_replace('/_(apt|villa|comm|office)$/', '', $k2);
                if ($canon !== $k2 && !isset($bag[$canon])) {
                    $bag[$canon] = $sv;
                }
            }
        }
        return $bag;
    }
}

if (!function_exists('melkinoVisitPickField')) {
    function melkinoVisitPickField(array $sources, array $keys): string
    {
        foreach ($keys as $k) {
            foreach ($sources as $src) {
                if (!is_array($src) || !array_key_exists($k, $src)) {
                    continue;
                }
                $v = melkinoVisitScalar($src[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }
}

if (!function_exists('melkinoVisitLooseScalar')) {
    /** مثل Scalar ولی صفرِ معنادار (طبقه همکف) را نگه می‌دارد؛ از آرایه value/val/text هم می‌خواند. */
    function melkinoVisitLooseScalar($v): string
    {
        if (is_object($v)) {
            $v = (array) $v;
        }
        if (is_array($v)) {
            if (isset($v['value']) || isset($v['val']) || isset($v['text']) || isset($v['v']) || isset($v['amount'])) {
                $v = $v['value'] ?? ($v['val'] ?? ($v['text'] ?? ($v['v'] ?? $v['amount'])));
            } elseif ($v && array_keys($v) === range(0, count($v) - 1) && !is_array($v[0] ?? null) && !is_object($v[0] ?? null)) {
                $v = implode('، ', array_map('strval', $v));
            } else {
                return '';
            }
        }
        if (is_bool($v) || is_array($v) || is_object($v)) {
            return '';
        }
        return trim((string) $v);
    }
}

if (!function_exists('melkinoVisitDetailsArray')) {
    function melkinoVisitDetailsArray($raw): array
    {
        $n = 0;
        while (is_string($raw) && $raw !== '' && $n < 3) {
            $n++;
            $d = json_decode($raw, true);
            if (!is_array($d) && !is_string($d)) {
                return [];
            }
            $raw = $d;
        }
        if (!is_array($raw)) {
            return [];
        }
        $flat = [];
        $walk = static function ($arr) use (&$walk, &$flat): void {
            if (!is_array($arr)) {
                return;
            }
            foreach (['fields', 'details', 'property', 'data', 'values', 'specs', 'items', 'pd'] as $nest) {
                if (isset($arr[$nest]) && is_array($arr[$nest])) {
                    $walk($arr[$nest]);
                }
            }
            foreach ($arr as $k => $v) {
                if (!is_string($k) || $k === '' || $k[0] === '_') {
                    continue;
                }
                if (in_array($k, ['fields', 'details', 'property', 'data', 'values', 'specs', 'items', 'pd'], true)) {
                    continue;
                }
                $k2 = (string) preg_replace('/^pd\./', '', $k);
                if (is_array($v) || is_object($v)) {
                    $sv = function_exists('melkinoVisitLooseScalar') ? melkinoVisitLooseScalar($v) : '';
                    if ($sv === '') {
                        $walk((array) $v);
                        continue;
                    }
                } else {
                    $sv = function_exists('melkinoVisitLooseScalar') ? melkinoVisitLooseScalar($v) : trim((string) $v);
                }
                if ($sv === '' || $sv === '0' || $sv === '0.0' || $sv === '۰') {
                    continue;
                }
                if (!isset($flat[$k])) {
                    $flat[$k] = $sv;
                }
                if (!isset($flat[$k2])) {
                    $flat[$k2] = $sv;
                }
                $canon = (string) preg_replace('/_(apt|villa|comm|office)$/', '', $k2);
                if ($canon !== $k2 && !isset($flat[$canon])) {
                    $flat[$canon] = $sv;
                }
            }
        };
        $walk($raw);
        return $flat ?: $raw;
    }
}

if (!function_exists('melkinoVisitLoadAdsMap')) {
    /** @return array<string,array> */
    function melkinoVisitLoadAdsMap(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static function ($v) {
            return $v !== '';
        })));
        if (!$ids) {
            return [];
        }
        $map = [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare('SELECT * FROM ads WHERE id IN (' . $in . ')');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $rid = (string) ($row['id'] ?? '');
                if ($rid === '') {
                    continue;
                }
                $map[$rid] = $row;
                if (ctype_digit($rid)) {
                    $map[(string) (int) $rid] = $row;
                }
            }
        } catch (Throwable $e) {
            foreach ($ids as $id) {
                try {
                    $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
                    $st->execute([$id]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $map[(string) $id] = $row;
                    }
                } catch (Throwable $e2) {
                }
            }
        }
        return $map;
    }
}

if (!function_exists('melkinoVisitFlattenAd')) {
    function melkinoVisitFlattenAd(array $ad): array
    {
        $details = melkinoVisitDetailsArray($ad['property_details'] ?? null);
        $srcs = [$ad, $details];
        $area = melkinoVisitPickField($srcs, ['area', 'built_area', 'land_area', 'office_area', 'garden_area', 'area_apt', 'area_comm', 'land_villa', 'built_villa']);
        $rooms = melkinoVisitPickField($srcs, ['rooms', 'rooms_apt', 'rooms_villa', 'office_rooms']);
        $floor = melkinoVisitPickField($srcs, ['floor', 'floor_apt', 'office_floor']);
        $price = '';
        if (function_exists('melkinoAdDisplayPrice')) {
            try {
                $price = melkinoVisitScalar(melkinoAdDisplayPrice($ad));
            } catch (Throwable $e) {
                $price = '';
            }
        }
        if ($price === '') {
            $price = melkinoVisitPickField($srcs, ['price_sell', 'display_price', 'total_price', 'price']);
        }
        $code = melkinoVisitPickField($srcs, ['ad_code']);
        if ($code === '') {
            $code = melkinoVisitScalar($ad['numeric_id'] ?? '');
        }
        if ($code === '') {
            $code = melkinoVisitScalar($ad['id'] ?? '');
        }
        return [
            'id' => melkinoVisitScalar($ad['id'] ?? ''),
            'ad_code' => $code,
            'title' => melkinoVisitPickField($srcs, ['title']),
            'transaction_type' => melkinoVisitPickField($srcs, ['transaction_type', 'transactionType']),
            'property_type' => melkinoVisitPickField($srcs, ['property_type', 'propertyType']),
            'last_name' => melkinoVisitPickField($srcs, ['last_name']),
            'phone' => melkinoVisitPickField($srcs, ['phone', 'mobile']),
            'location' => melkinoVisitPickField($srcs, ['location', 'neighborhood']),
            'address' => melkinoVisitPickField($srcs, ['address']),
            'area' => $area,
            'rooms' => $rooms,
            'floor' => $floor,
            'year' => melkinoVisitPickField($srcs, ['year', 'build_year', 'age']),
            'price_sell' => $price,
            'deposit' => melkinoVisitPickField($srcs, ['deposit']),
            'rent_monthly' => melkinoVisitPickField($srcs, ['rent_monthly', 'rent']),
            'total_price' => melkinoVisitPickField($srcs, ['total_price']),
            'display_price' => $price,
            'deed_type' => melkinoVisitPickField($srcs, ['deed_type']),
        ];
    }
}

if (!function_exists('melkinoVisitAdSnapshot')) {
    function melkinoVisitAdSnapshot(array $ad): array
    {
        return melkinoVisitFlattenAd($ad);
    }
}

if (!function_exists('melkinoVisitTrackingCode')) {
    function melkinoVisitTrackingCode(int $id): string
    {
        return 'VR-' . str_pad((string) max(0, $id), 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('melkinoVisitEnsureTracking')) {
    function melkinoVisitEnsureTracking(PDO $pdo, array &$row): void
    {
        if (!empty($row['tracking_code']) || empty($row['id'])) {
            return;
        }
        $code = melkinoVisitTrackingCode((int) $row['id']);
        try {
            $pdo->prepare('UPDATE visit_requests SET tracking_code = ? WHERE id = ? AND (tracking_code IS NULL OR tracking_code = \'\')')->execute([$code, (int) $row['id']]);
            $row['tracking_code'] = $code;
        } catch (Throwable $e) {
            $row['tracking_code'] = $code;
        }
    }
}

if (!function_exists('melkinoVisitNotify')) {
    function melkinoVisitNotify(array $row, string $event, string $title, string $message): void
    {
        if (!function_exists('sendNotification')) {
            return;
        }
        $userId = !empty($row['user_id']) ? (int) $row['user_id'] : null;
        $tg = trim((string) ($row['telegram_id'] ?? ''));
        $tg = $tg !== '' ? $tg : null;
        $adId = trim((string) ($row['ad_id'] ?? ''));
        $reqId = !empty($row['id']) ? (int) $row['id'] : null;
        try {
            sendNotification($userId, $tg, $event, $title, $message, 'visits.php', $adId !== '' ? $adId : null, $reqId, null);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoVisitNotifyForStatus')) {
    function melkinoVisitNotifyForStatus(array $row, string $status): void
    {
        $titleAd = trim((string) ($row['ad_title'] ?? '')) ?: 'این ملک';
        $slotLabel = melkinoVisitSlots()[$row['time_slot'] ?? ''] ?? '';
        $when = trim((string) ($row['weekday'] ?? '') . ' ' . (string) ($row['preferred_date_fa'] ?? '') . ' ' . $slotLabel);
        $whenBit = $when !== '' ? ' (' . $when . ')' : '';
        switch ($status) {
            case 'scheduled_owner':
                melkinoVisitNotify($row, 'visit_scheduled', 'بازدید هماهنگ شد', 'بازدید آگهی ' . $titleAd . ' با مالک هماهنگ شد' . $whenBit . '.');
                break;
            case 'owner_rejected_date':
                melkinoVisitNotify($row, 'visit_owner_rejected', 'رد تاریخ بازدید', 'مالک تاریخ پیشنهادی بازدید آگهی ' . $titleAd . ' را نپذیرفت.');
                break;
            case 'user_notified':
                melkinoVisitNotify($row, 'visit_user_notified', 'اطلاع بازدید', 'نتیجه هماهنگی بازدید آگهی ' . $titleAd . ' به شما اطلاع داده شد.');
                break;
            case 'cancelled_by_user':
                melkinoVisitNotify($row, 'visit_cancelled', 'لغو بازدید', 'درخواست بازدید آگهی ' . $titleAd . ' لغو شد.');
                break;
            case 'time_changed_by_user':
                melkinoVisitNotify($row, 'visit_time_changed', 'تغییر زمان بازدید', 'زمان بازدید آگهی ' . $titleAd . ' تغییر کرد.');
                break;
            case 'user_no_response':
                melkinoVisitNotify($row, 'visit_no_response', 'عدم پاسخگویی', 'تلاش برای هماهنگی بازدید آگهی ' . $titleAd . ' بدون پاسخ ماند.');
                break;
            case 'visited':
                melkinoVisitNotify($row, 'visit_visited', 'بازدید انجام شد', 'بازدید آگهی ' . $titleAd . ' انجام شد.');
                break;
        }
    }
}

if (!function_exists('melkinoVisitSuccessMessage')) {
    function melkinoVisitSuccessMessage(string $adTitle): string
    {
        $title = trim($adTitle) !== '' ? trim($adTitle) : 'این ملک';
        return 'درخواست بازدید شما برای آگهی ' . $title . ' ثبت شد همکاران ما جهت هماهنگی بازدید با شما تماس خواهند گرفت';
    }
}

if (!function_exists('melkinoVisitJalaliLabel')) {
    function melkinoVisitJalaliLabel(int $jy, int $jm, int $jd): string
    {
        if ($jd < 1 || $jm < 1 || $jm > 12) {
            return '';
        }
        $months = melkinoVisitJalaliMonths();
        $dayFa = function_exists('melkinoFaDigits') ? melkinoFaDigits((string) $jd) : (string) $jd;
        $yearFa = $jy > 0 ? (function_exists('melkinoFaDigits') ? melkinoFaDigits((string) $jy) : (string) $jy) : '';
        return trim($dayFa . ' ' . ($months[$jm - 1] ?? '') . ' ' . $yearFa);
    }
}

if (!function_exists('melkinoVisitWhenParts')) {
    /** @return array{weekday:string,date:string,slot:string} */
    function melkinoVisitWhenParts(array $row): array
    {
        require_once __DIR__ . '/jalali-lib.php';
        $slots = melkinoVisitSlots();
        $slotKey = trim((string) ($row['time_slot'] ?? ''));
        $slot = $slots[$slotKey] ?? ($slotKey !== '' ? $slotKey : 'صبح');
        $weekday = trim((string) ($row['weekday'] ?? ''));
        $rawFa = $row['preferred_date_fa'] ?? '';
        $rawG = $row['preferred_date'] ?? '';
        if ($rawG instanceof DateTimeInterface) {
            $rawG = $rawG->format('Y-m-d');
        }
        if ($rawFa instanceof DateTimeInterface) {
            $rawFa = $rawFa->format('Y-m-d');
        }
        $fa = melkinoVisitNormalizeDigits(trim((string) $rawFa));
        $g = melkinoVisitNormalizeDigits(trim((string) $rawG));
        $jy = 0;
        $jm = 0;
        $jd = 0;
        $setG = static function (int $y, int $mo, int $d) use (&$jy, &$jm, &$jd): void {
            if ($jy > 0 || $y < 1700 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
                return;
            }
            if (function_exists('melkinoGregorianToJalali')) {
                [$jy, $jm, $jd] = melkinoGregorianToJalali($y, $mo, $d);
            }
        };
        $setJ = static function (int $y, int $mo, int $d) use (&$jy, &$jm, &$jd): void {
            if ($jy > 0 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
                return;
            }
            if ($y >= 1300 && $y <= 1599) {
                $jy = $y;
                $jm = $mo;
                $jd = $d;
            }
        };
        $isoG = function_exists('melkinoVisitToGregorianDate')
            ? melkinoVisitToGregorianDate($g !== '' && strpos($g, '0000') !== 0 ? $g : $fa)
            : '';
        if ($isoG !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoG, $ig)) {
            $setG((int) $ig[1], (int) $ig[2], (int) $ig[3]);
        }
        if ($jy <= 0) {
            foreach ([$g, $fa] as $cand) {
                if ($cand === '' || strpos($cand, '0000') === 0) {
                    continue;
                }
                try {
                    $dt = new DateTimeImmutable($cand);
                    $yy = (int) $dt->format('Y');
                    if ($yy >= 1700) {
                        $setG($yy, (int) $dt->format('n'), (int) $dt->format('j'));
                    } else {
                        $setJ($yy, (int) $dt->format('n'), (int) $dt->format('j'));
                    }
                } catch (Throwable $e) {
                }
                if ($jy > 0) {
                    break;
                }
            }
        }
        $months = melkinoVisitJalaliMonths();
        if ($jy <= 0 && $fa !== '') {
            foreach ($months as $i => $name) {
                if (preg_match('/(\d{1,2})\s*' . preg_quote($name, '/') . '(?:\s+(\d{4}))?/u', $fa, $mm)) {
                    $yy = isset($mm[2]) && $mm[2] !== '' ? (int) $mm[2] : 0;
                    if ($yy < 1300 && function_exists('melkinoJalaliCurrentYear')) {
                        $yy = (int) melkinoJalaliCurrentYear();
                    }
                    $setJ($yy, $i + 1, (int) $mm[1]);
                    break;
                }
            }
        }
        if ($jy <= 0 && $weekday !== '') {
            $tz = new DateTimeZone('Asia/Tehran');
            $base = new DateTimeImmutable('now', $tz);
            $baseRaw = trim((string) ($row['created_at'] ?? ''));
            if ($baseRaw !== '' && strpos($baseRaw, '0000') !== 0) {
                try {
                    $base = new DateTimeImmutable($baseRaw, $tz);
                } catch (Throwable $e) {
                }
            }
            $names = melkinoVisitWeekdayNames();
            $want = str_replace([' ', '‌', 'ي', 'ك'], ['', '', 'ی', 'ک'], $weekday);
            for ($i = 0; $i <= 14; $i++) {
                $cand = $base->modify('+' . $i . ' day');
                $wname = $names[(int) $cand->format('w')] ?? '';
                $have = str_replace([' ', '‌', 'ي', 'ك'], ['', '', 'ی', 'ک'], $wname);
                if ($have === $want) {
                    $setG((int) $cand->format('Y'), (int) $cand->format('n'), (int) $cand->format('j'));
                    break;
                }
            }
        }
        $dateLabel = function_exists('melkinoVisitJalaliLabel')
            ? melkinoVisitJalaliLabel($jy, $jm, $jd)
            : '';
        if ($dateLabel === '' && $fa !== '') {
            $dateLabel = function_exists('melkinoFaDigits') ? melkinoFaDigits($fa) : $fa;
            $dateLabel = trim((string) preg_replace('/^(شنبه|یکشنبه|دوشنبه|سه‌شنبه|سه شنبه|چهارشنبه|پنجشنبه|پنج‌شنبه|جمعه)\s+/u', '', $dateLabel));
        }
        if ($dateLabel === '' && $isoG !== '') {
            $dateLabel = function_exists('melkinoFaDigits') ? melkinoFaDigits($isoG) : $isoG;
        }
        if ($weekday === '' && $jy > 0 && function_exists('melkinoJalaliToGregorian')) {
            try {
                [$gy, $gm, $gd] = melkinoJalaliToGregorian($jy, $jm, $jd);
                $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $gy, $gm, $gd), new DateTimeZone('Asia/Tehran'));
                $weekday = melkinoVisitWeekdayNames()[(int) $dt->format('w')] ?? '';
            } catch (Throwable $e) {
            }
        }
        return [
            'weekday' => $weekday !== '' ? $weekday : '—',
            'date' => $dateLabel !== '' ? $dateLabel : '—',
            'slot' => $slot !== '' ? $slot : 'صبح',
        ];
    }
}

if (!function_exists('melkinoVisitWhenLabel')) {
    function melkinoVisitWhenLabel(array $row): string
    {
        $p = function_exists('melkinoVisitWhenParts') ? melkinoVisitWhenParts($row) : [];
        $parts = array_filter([
            $p['weekday'] ?? '',
            $p['date'] ?? '',
            $p['slot'] ?? '',
        ], static function ($v) {
            return $v !== '' && $v !== '—';
        });
        return implode(' · ', $parts);
    }
}

if (!function_exists('melkinoVisitResolveAdInfo')) {
    function melkinoVisitResolveAdInfo(array $row): array
    {
        if (!empty($row['_ad']) && is_array($row['_ad'])) {
            $live = $row['_ad'];
        } else {
            $live = [
                'id' => $row['ad_id'] ?? ($row['id'] ?? ''),
                'ad_code' => $row['ad_code'] ?? '',
                'numeric_id' => $row['ad_numeric_id'] ?? '',
                'title' => $row['ad_title_live'] ?? ($row['ad_title'] ?? ''),
                'property_type' => $row['ad_property_type'] ?? '',
                'transaction_type' => $row['ad_transaction_type'] ?? '',
                'location' => $row['ad_location'] ?? '',
                'address' => $row['ad_address'] ?? '',
                'area' => $row['ad_area'] ?? '',
                'built_area' => $row['ad_built_area'] ?? '',
                'land_area' => $row['ad_land_area'] ?? '',
                'rooms' => $row['ad_rooms'] ?? '',
                'floor' => $row['ad_floor'] ?? '',
                'year' => $row['ad_year'] ?? '',
                'price_sell' => $row['ad_price_sell'] ?? '',
                'deposit' => $row['ad_deposit'] ?? '',
                'rent_monthly' => $row['ad_rent_monthly'] ?? '',
                'display_price' => $row['ad_display_price'] ?? '',
                'total_price' => $row['ad_total_price'] ?? '',
                'deed_type' => $row['ad_deed_type'] ?? '',
                'amenities' => $row['ad_amenities'] ?? null,
                'tags' => $row['ad_tags'] ?? null,
                'is_not_keyed' => $row['ad_is_not_keyed'] ?? 0,
                'has_loan' => $row['ad_has_loan'] ?? 0,
                'loan_amount' => $row['ad_loan_amount'] ?? '',
                'exchange_interested' => $row['ad_exchange_interested'] ?? 0,
                'property_details' => $row['ad_property_details'] ?? ($row['property_details'] ?? null),
            ];
        }
        $flat = melkinoVisitFlattenAd($live);
        $snap = [];
        if (!empty($row['ad_snapshot'])) {
            $decoded = json_decode((string) $row['ad_snapshot'], true);
            if (is_array($decoded)) {
                $snap = $decoded;
            }
        }
        foreach ($flat as $k => $v) {
            if ($v === '' && isset($snap[$k]) && melkinoVisitScalar($snap[$k]) !== '') {
                $flat[$k] = melkinoVisitScalar($snap[$k]);
            }
        }
        if ($flat['title'] === '') {
            $flat['title'] = trim((string) ($row['ad_title'] ?? ($live['title'] ?? '')));
        }
        $flat['id'] = trim((string) ($row['ad_id'] ?? $flat['id'] ?? ($live['id'] ?? '')));
        $flat['_ad'] = $live;
        return $flat;
    }
}

if (!function_exists('melkinoVisitMoneyFa')) {
    function melkinoVisitMoneyFa($value): string
    {
        $s = melkinoVisitNormalizeDigits(trim((string) $value));
        $s = str_replace([',', '٬', '،', ' ', 'تومان', 'ریال'], '', $s);
        if ($s === '' || !is_numeric($s)) {
            return '';
        }
        $n = (float) $s;
        if ($n <= 0) {
            return '';
        }
        $out = number_format($n, 0, '.', ',');
        if (function_exists('melkinoFaDigits')) {
            $out = melkinoFaDigits($out);
        }
        return $out . ' تومان';
    }
}

if (!function_exists('melkinoVisitIsRentDeal')) {
    function melkinoVisitIsRentDeal(string $tx): bool
    {
        $t = str_replace(['‌', ' ', 'ي', 'ك'], ['', '', 'ی', 'ک'], $tx);
        if ($t === '') {
            return false;
        }
        if (mb_strpos($t, 'فروش') !== false) {
            return false;
        }
        return mb_strpos($t, 'رهن') !== false || mb_strpos($t, 'اجاره') !== false;
    }
}

if (!function_exists('melkinoVisitPickMoney')) {
    function melkinoVisitPickMoney(array $sources, array $keys): string
    {
        foreach ($keys as $k) {
            foreach ($sources as $src) {
                if (!is_array($src) || !isset($src[$k])) {
                    continue;
                }
                $m = melkinoVisitMoneyFa($src[$k]);
                if ($m !== '') {
                    return $m;
                }
            }
        }
        return '';
    }
}

if (!function_exists('melkinoVisitBagFind')) {
    function melkinoVisitBagFind(array $bag, array $needles): string
    {
        foreach ($needles as $n) {
            if (isset($bag[$n])) {
                $v = function_exists('melkinoVisitLooseScalar')
                    ? melkinoVisitLooseScalar($bag[$n])
                    : melkinoVisitScalar($bag[$n]);
                if ($v !== '' && $v !== '0' && $v !== '۰') {
                    return $v;
                }
            }
        }
        $want = [];
        foreach ($needles as $n) {
            $want[strtolower((string) $n)] = true;
        }
        foreach ($bag as $k => $v) {
            $kk = strtolower((string) preg_replace('/^pd\./', '', (string) $k));
            $canon = (string) preg_replace('/_(apt|villa|comm|office)$/', '', $kk);
            if (!isset($want[$kk]) && !isset($want[$canon])) {
                continue;
            }
            $sv = function_exists('melkinoVisitLooseScalar')
                ? melkinoVisitLooseScalar($v)
                : melkinoVisitScalar($v);
            if ($sv !== '' && $sv !== '0' && $sv !== '۰') {
                return $sv;
            }
        }
        return '';
    }
}

if (!function_exists('melkinoVisitHomeFacts')) {
    /** مشخصات خلاصه روی کارت — بدون آدرس. رهن‌واجاره بدون فیلد قیمت. */
    function melkinoVisitHomeFacts(array $row, array $adInfo): array
    {
        $live = [];
        if (!empty($row['_ad']) && is_array($row['_ad'])) {
            $live = $row['_ad'];
        } elseif (!empty($adInfo['_ad']) && is_array($adInfo['_ad'])) {
            $live = $adInfo['_ad'];
        }
        $bag = function_exists('melkinoVisitMergeAdBag') ? melkinoVisitMergeAdBag($row, $adInfo) : [];
        $details = melkinoVisitDetailsArray($live['property_details'] ?? ($adInfo['property_details'] ?? null));
        $srcs = [$bag, $details, $live, $adInfo];
        $merged = $bag + $details;
        $facts = [];
        $push = static function (string $label, $value) use (&$facts) {
            $value = trim((string) $value);
            $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]+/u', '', $value) ?? $value;
            if ($value === '' || $value === '—' || $value === '-' || preg_match('/^(متر|تومان|اتاق|سال)$/u', $value)) {
                return;
            }
            $facts[] = [$label, $value];
        };
        $num = static function ($v): string {
            $v = trim((string) $v);
            if ($v === '') {
                return '';
            }
            return function_exists('melkinoFaDigits') ? melkinoFaDigits($v) : $v;
        };
        $cardVal = static function (string $key) use ($live, $adInfo): string {
            $ad = $live ?: $adInfo;
            if (!$ad) {
                return '';
            }
            if (!function_exists('melkinoCardFieldValue')) {
                $p = __DIR__ . '/card-display.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('melkinoCardFieldValue')) {
                return '';
            }
            try {
                $v = melkinoCardFieldValue($ad, $key);
                return $v !== null ? trim((string) $v) : '';
            } catch (Throwable $e) {
                return '';
            }
        };
        $find = static function (array $keys) use ($srcs, $merged): string {
            $v = function_exists('melkinoVisitPickField') ? melkinoVisitPickField($srcs, $keys) : '';
            if ($v !== '') {
                return $v;
            }
            return function_exists('melkinoVisitBagFind') ? melkinoVisitBagFind($merged, $keys) : '';
        };

        $tx = $find(['transaction_type', 'transactionType']);
        $push('نوع ملک', $find(['property_type', 'propertyType']));
        $push('معامله', $tx);
        $push('موقعیت', $find(['location', 'neighborhood']));

        $ptype = $find(['property_type', 'propertyType']);

        $area = $find(['area', 'built_area', 'land_area', 'office_area', 'garden_area', 'area_apt', 'area_comm', 'land_villa', 'built_villa', 'building_area']);
        $areaCard = $cardVal('area');
        if ($area === '' && $areaCard !== '') {
            $push('متراژ', function_exists('melkinoFaDigits') ? melkinoFaDigits($areaCard) : $areaCard);
        } elseif ($area !== '') {
            $push('متراژ', preg_match('/متر/u', $area) ? $num($area) : ($num($area) . ' متر'));
        }

        $rooms = $find(['rooms', 'rooms_apt', 'rooms_villa', 'office_rooms']);
        if ($rooms === '') {
            $rc = $cardVal('rooms');
            if ($rc !== '') {
                $rooms = $rc;
            }
        }
        if ($rooms !== '') {
            $push('خواب', $num($rooms));
        }

        if ($ptype === 'تجاری' || $ptype === 'مغازه') {
            $front = $find(['front', 'front_comm', 'front_width']);
            if ($front !== '') {
                $push('بر مغازه', preg_match('/متر/u', $front) ? $num($front) : ($num($front) . ' متر'));
            }
            $push('پوشش کف', $find(['flooring', 'floor_comm', 'floor_covering']));
            $push('پوشش دیوار', $find(['wall', 'wall_comm', 'wall_covering']));
            $push('کابینت', $find(['cabinet', 'cabinet_comm']));
            $push('سرمایش', $find(['cooling', 'cooling_comm', 'cooling_system']));
            $push('گرمایش', $find(['heating', 'heating_comm', 'heating_system']));
            $lt = $find(['location_type', 'orientation_comm']);
            if ($lt !== '') {
                $push('موقعیت ملک', $lt);
            }
            $push('ویژگی موقعیت', $find(['location_features']));
            $push('مناسب برای', $find(['jobs', 'jobs_comm', 'usage_comm']));
        }

        $floor = $find(['floor', 'floor_apt', 'office_floor']);
        if ($floor === '' || $floor === '0' || $floor === '۰') {
            $fc = $cardVal('floor');
            if ($fc !== '') {
                $floor = $fc;
            } elseif ($floor === '0' || $floor === '۰') {
                $floor = 'همکف';
            }
        }
        if ($floor !== '' && $floor !== '0') {
            $push('طبقه', $num($floor));
        }

        $year = $find(['year', 'build_year', 'year_apt', 'year_villa', 'office_year']);
        if ($year === '') {
            $yc = $cardVal('year');
            if ($yc !== '') {
                $year = preg_replace('/^ساخت\s+/u', '', $yc) ?? $yc;
            }
        }
        if ($year !== '') {
            $push('سال ساخت', $num($year));
            if (function_exists('melkinoBuildingAgeDisplay')) {
                $age = melkinoBuildingAgeDisplay($year);
                if ($age === '') {
                    $ageCard = $cardVal('building_age');
                    $age = $ageCard !== '' ? preg_replace('/\s*سال$/u', '', $ageCard) : '';
                }
                if ($age !== '') {
                    $push('سن بنا', $num($age));
                }
            }
        }

        $title = $find(['title']);
        if ($title === '') {
            $title = trim((string) ($row['ad_title'] ?? ($adInfo['title'] ?? '')));
        }
        $titleN = function_exists('melkinoVisitNormalizeDigits') ? melkinoVisitNormalizeDigits($title) : $title;
        if ($area === '' && $areaCard === '' && preg_match('/(\d+(?:\.\d+)?)\s*متر/u', $titleN, $tm)) {
            $push('متراژ', $num($tm[1]) . ' متر');
        }
        if ($rooms === '' && preg_match('/(\d+)\s*(?:خواب|اتاق)/u', $titleN, $tr)) {
            $push('خواب', $num($tr[1]));
        }

        if (melkinoVisitIsRentDeal($tx)) {
            $dep = melkinoVisitPickMoney($srcs, ['deposit', 'rahn', 'vadie', 'ودیعه']);
            if ($dep === '') {
                $dc = $cardVal('deposit');
                $dep = $dc !== '' ? $dc : '';
            }
            $rent = melkinoVisitPickMoney($srcs, ['rent_monthly', 'rent', 'اجاره']);
            if ($rent === '') {
                $rcm = $cardVal('rent_monthly');
                $rent = $rcm !== '' ? $rcm : '';
            }
            $full = melkinoVisitPickMoney($srcs, ['full_rent']);
            if ($full === '') {
                $full = $cardVal('full_rent');
            }
            if ($dep !== '') {
                $push('رهن', $dep);
            }
            if ($rent !== '') {
                $push('اجاره', $rent);
            }
            if ($full !== '' && $dep === '') {
                $push('رهن کامل', $full);
            }
        } else {
            $price = melkinoVisitPickMoney($srcs, ['price_sell', 'total_price', 'display_price', 'price']);
            if ($price === '' && function_exists('melkinoAdDisplayPrice')) {
                try {
                    $price = melkinoVisitMoneyFa(melkinoAdDisplayPrice($live ?: $adInfo));
                } catch (Throwable $e) {
                    $price = '';
                }
            }
            $push('قیمت', $price);
        }

        $code = $find(['ad_code', 'numeric_id', 'code']);
        if ($code === '') {
            $code = trim((string) ($live['id'] ?? $adInfo['id'] ?? $row['ad_id'] ?? ''));
        }
        if ($code !== '' && $code !== '0') {
            $push('کد آگهی', $num($code));
        }
        return $facts;
    }
}

if (!function_exists('melkinoVisitLoadAmenitiesMap')) {
    /** @return array<string,string[]> */
    function melkinoVisitLoadAmenitiesMap(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $map = [];
        if (!$ids) {
            return $map;
        }
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                'SELECT aa.ad_id, am.name FROM ad_amenities aa
                 INNER JOIN amenities am ON am.id = aa.amenity_id
                 WHERE aa.ad_id IN (' . $in . ')
                 ORDER BY am.sort_order, am.id'
            );
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $aid = (string) ($r['ad_id'] ?? '');
                $name = trim((string) ($r['name'] ?? ''));
                if ($aid === '' || $name === '') {
                    continue;
                }
                $map[$aid][] = $name;
            }
        } catch (Throwable $e) {
        }
        return $map;
    }
}

if (!function_exists('melkinoVisitAmenityList')) {
    /** @return string[] */
    function melkinoVisitAmenityList(array $row, array $fromTable = []): array
    {
        $live = is_array($row['_ad'] ?? null) ? $row['_ad'] : [];
        $bag = [];
        $add = static function ($v) use (&$bag) {
            if (is_array($v)) {
                foreach ($v as $x) {
                    if (is_array($x)) {
                        $x = $x['name'] ?? ($x['title'] ?? '');
                    }
                    $x = trim((string) $x);
                    if ($x !== '') {
                        $bag[] = $x;
                    }
                }
                return;
            }
            if (is_string($v) && $v !== '') {
                $d = json_decode($v, true);
                if (is_array($d)) {
                    foreach ($d as $x) {
                        $x = trim(is_array($x) ? (string) ($x['name'] ?? '') : (string) $x);
                        if ($x !== '') {
                            $bag[] = $x;
                        }
                    }
                    return;
                }
                foreach (preg_split('/[,،]+/u', $v) ?: [] as $x) {
                    $x = trim((string) $x);
                    if ($x !== '') {
                        $bag[] = $x;
                    }
                }
            }
        };
        $add($fromTable);
        $add($live['amenities'] ?? null);
        $details = melkinoVisitDetailsArray($live['property_details'] ?? null);
        $add($details['amenities'] ?? null);
        $out = [];
        $seen = [];
        foreach ($bag as $name) {
            $k = str_replace('‌', '', $name);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $name;
        }
        return $out;
    }
}

if (!function_exists('melkinoVisitExtraDetails')) {
    /** فیلدهای امکانات/مشخصات برای کرکره جزئیات — فقط لیبل فارسی، بدون کلید انگلیسی. */
    function melkinoVisitExtraDetails(array $row): array
    {
        $live = is_array($row['_ad'] ?? null) ? $row['_ad'] : [];
        $details = melkinoVisitDetailsArray($live['property_details'] ?? null);
        $skip = [
            'area', 'built_area', 'land_area', 'office_area', 'garden_area', 'area_apt', 'area_comm', 'land_villa', 'built_villa',
            'rooms', 'rooms_apt', 'rooms_villa', 'office_rooms',
            'floor', 'floor_apt', 'office_floor',
            'year', 'build_year', 'year_apt', 'year_villa', 'office_year', 'building_age', 'age',
            'address', 'location', 'title', 'price', 'price_sell', 'total_price', 'display_price',
            'deposit', 'rent_monthly', 'rent', 'full_rent', 'amenities', 'phone', 'last_name',
            'transaction_type', 'property_type', 'id', 'ad_id', 'ad_code', 'numeric_id',
        ];
        $labels = [
            'cabinet' => 'نوع کابینت', 'cabinet_apt' => 'نوع کابینت', 'cabinet_villa' => 'نوع کابینت', 'cabinet_comm' => 'نوع کابینت', 'cabinet_type' => 'نوع کابینت', 'office_cabinet' => 'نوع کابینت',
            'cooling' => 'سیستم سرمایش', 'cooling_apt' => 'سیستم سرمایش', 'cooling_villa' => 'سیستم سرمایش', 'cooling_comm' => 'سیستم سرمایش', 'cooling_system' => 'سیستم سرمایش', 'office_cooling' => 'سیستم سرمایش',
            'heating' => 'سیستم گرمایش', 'heating_apt' => 'سیستم گرمایش', 'heating_villa' => 'سیستم گرمایش', 'heating_comm' => 'سیستم گرمایش', 'heating_system' => 'سیستم گرمایش', 'office_heating' => 'سیستم گرمایش',
            'flooring' => 'پوشش کف', 'flooring_apt' => 'پوشش کف', 'flooring_villa' => 'پوشش کف', 'floor_comm' => 'پوشش کف', 'floor_covering' => 'پوشش کف', 'floor_type' => 'پوشش کف', 'office_flooring' => 'پوشش کف',
            'apartment_type' => 'نوع آپارتمان', 'villa_type' => 'نوع ویلا',
            'total_units' => 'تعداد کل واحدها', 'units_total' => 'تعداد کل واحدها', 'number_of_units' => 'تعداد کل واحدها',
            'units_per_floor' => 'تعداد واحد در طبقه', 'units_per_floor_apt' => 'تعداد واحد در طبقه', 'office_units_per_floor' => 'تعداد واحد در طبقه',
            'deed_type' => 'نوع سند', 'document_type' => 'نوع سند', 'land_deed_type' => 'نوع سند',
            'land_type' => 'کاربری زمین', 'land_width' => 'عرض زمین', 'land_length' => 'طول زمین',
            'land_front_width' => 'عرض بر', 'land_direction' => 'جهت ملک', 'land_shape' => 'شکل زمین',
            'wall' => 'پوشش دیوارها', 'wall_comm' => 'پوشش دیوارها', 'wall_covering' => 'پوشش دیوارها',
            'front' => 'بر مغازه', 'front_comm' => 'بر مغازه', 'front_width' => 'بر مغازه',
            'office_condition' => 'وضعیت واحد', 'office_usage' => 'کاربری', 'office_orientation' => 'موقعیت واحد',
            'irrigation_type' => 'نوع آبیاری', 'tree_types' => 'نوع درختان', 'tree_age' => 'سن درختان',
            'has_well' => 'آب ملکی (چاه)', 'has_pond' => 'استخر', 'has_building' => 'بنا / خانه باغ',
            'condition' => 'وضعیت ملک', 'orientation' => 'جهت ملک', 'usage' => 'کاربری',
            'jobs' => 'مناسب برای مشاغل', 'location_type' => 'موقعیت', 'location_features' => 'ویژگی موقعیت',
        ];
        if (is_file(__DIR__ . '/field-display.php')) {
            require_once __DIR__ . '/field-display.php';
        }
        if (function_exists('melkinoPdSpecDefinitions')) {
            foreach (melkinoPdSpecDefinitions() as $faLabel => $keys) {
                foreach ($keys as $key) {
                    if (!isset($labels[$key])) {
                        $labels[$key] = $faLabel;
                    }
                }
            }
        }
        if (function_exists('melkinoPublishFieldDefs')) {
            foreach (melkinoPublishFieldDefs() as $key => $def) {
                if (strpos((string) $key, 'pd.') === 0) {
                    $dk = substr((string) $key, 3);
                    if (!isset($labels[$dk])) {
                        $labels[$dk] = (string) ($def['label'] ?? '');
                    }
                }
            }
        }
        $out = [];
        $seen = [];
        foreach ($details as $k => $v) {
            if (!is_string($k) || in_array($k, $skip, true) || stripos($k, 'price') !== false) {
                continue;
            }
            if (is_array($v)) {
                $v = implode('، ', array_filter(array_map('strval', $v)));
            }
            $v = trim((string) $v);
            if ($v === '' || $v === '0' || $v === '۰') {
                continue;
            }
            if (strpos($k, 'has_') === 0) {
                $v = ($v === '1' || $v === 'true' || $v === 'دارد') ? 'دارد' : $v;
            }
            $canon = (string) preg_replace('/_(apt|villa|comm|office)$/', '', $k);
            $label = $labels[$k] ?? ($labels[$canon] ?? '');
            if ($label === '' || is_numeric($k) || preg_match('/^[a-zA-Z0-9_]+$/', $label)) {
                continue;
            }
            $sig = $label . '|' . $v;
            if (isset($seen[$sig]) || isset($seen[$label])) {
                continue;
            }
            $seen[$sig] = true;
            $seen[$label] = true;
            $out[] = [$label, function_exists('melkinoFaDigits') ? melkinoFaDigits($v) : $v];
        }
        $deed = trim((string) ($live['deed_type'] ?? ''));
        if ($deed !== '' && empty($seen['نوع سند'])) {
            $out[] = ['نوع سند', $deed];
        }
        return $out;
    }
}
