<?php
/**
|--------------------------------------------------------------------------
| ملکینو — کتابخانهٔ «مشارکت در ساخت»
|--------------------------------------------------------------------------
| جدول partnership_requests + گزینه‌های مشترک فرم + پاک‌سازی/محاسبهٔ امتیاز
| الگو گرفته شده از visit-request-lib.php؛ جدول با CREATE TABLE IF NOT EXISTS
| خودکار ساخته می‌شود و نیازی به اجرای دستی SQL نیست.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoPartNormalizeDigits')) {
    /** ارقام فارسی/عربی → لاتین (برای فیلدهای عددی) */
    function melkinoPartNormalizeDigits(string $value): string
    {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        return str_replace($ar, $en, str_replace($fa, $en, trim($value)));
    }
}

if (!function_exists('melkinoPartOptions')) {
    /** همهٔ گزینه‌های فرم در یک نقطه — فرم، JS و پنل ادمین از همین لیست استفاده می‌کنند */
    function melkinoPartOptions(): array
    {
        return [
            'property_types'   => ['زمین', 'خانه کلنگی', 'آپارتمان کلنگی', 'ملک تجاری', 'باغ / زمین با بنا', 'سایر'],
            'current_statuses' => ['زمین خالی', 'خانه کلنگی', 'ساختمان قابل سکونت', 'ساختمان تجاری', 'سایر'],
            'br_counts'        => ['یک بر', 'دو بر', 'سه بر یا بیشتر', 'نمی‌دانم'],
            'permit_statuses'  => ['پروانه ندارم', 'در حال اخذ پروانه', 'پروانه صادر شده'],
            'deed_kinds'       => ['طلق', 'وقفی', 'مشاعی', 'سایر'],
            'directions'       => ['شمالی', 'جنوبی'],
            'yes_no_neg'       => ['بله', 'خیر', 'قابل مذاکره'],
            'division_methods' => ['درصدی', 'واحدی', 'متری', 'توافقی'],
            'durations'        => ['کمتر از ۱۸ ماه', '۱۸ تا ۲۴ ماه', '۲۴ تا ۳۶ ماه', 'بیشتر از ۳۶ ماه', 'قابل مذاکره'],
            'fundings'         => ['سازنده', 'مالک و سازنده', 'قابل مذاکره'],
            'deed_statuses'    => ['سند تک‌برگ', 'سند دفترچه‌ای', 'قولنامه‌ای', 'وکالتی', 'سایر', 'نمی‌دانم'],
            'occupancies'      => ['خالی', 'مالک ساکن است', 'مستأجر دارد', 'در حال تخلیه'],
            'legal_flags'      => ['در رهن است', 'در بازداشت است', 'پرونده حقوقی دارد', 'ورثه‌ای است', 'هیچ‌کدام', 'نمی‌دانم'],
            'statuses'         => [
                'pending'   => 'در انتظار بررسی',
                'approved'  => 'تأیید شد',
                'reviewing' => 'در حال بررسی',
                'contacted' => 'تماس گرفته شد',
                'offer'     => 'پیشنهاد داده شد',
                'done'      => 'توافق شد',
                'rejected'  => 'رد شد',
            ],
        ];
    }
}

if (!function_exists('melkinoPartStatuses')) {
    function melkinoPartStatuses(): array
    {
        return melkinoPartOptions()['statuses'];
    }
}

if (!function_exists('melkinoPartAddColumn')) {
    function melkinoPartAddColumn(PDO $pdo, string $column, string $definition): void
    {
        // نکتهٔ هاست‌های اشتراکی: information_schema گاهی در دسترس نیست؛
        // بنابراین مستقیم ALTER می‌زنیم و خطای «ستون تکراری» را نادیده می‌گیریم.
        try {
            $pdo->exec("ALTER TABLE partnership_requests ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            /* ستون از قبل وجود دارد (SQLSTATE 42S21) یا افزودن ممکن نیست — بی‌صدا */
        }
    }
}

if (!function_exists('melkinoEnsurePartnershipSchema')) {
    function melkinoEnsurePartnershipSchema(?PDO $pdo = null): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }
        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO)) {
            return $done = false;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS partnership_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(24) NULL,
                user_id INT NULL,
                owner_name VARCHAR(120) NOT NULL DEFAULT '',
                phone VARCHAR(30) NOT NULL DEFAULT '',
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                -- مرحله ۱ — معرفی ملک
                property_type VARCHAR(60) NOT NULL DEFAULT '',
                title VARCHAR(500) NOT NULL DEFAULT '',
                area VARCHAR(30) NOT NULL DEFAULT '',
                current_status VARCHAR(60) NOT NULL DEFAULT '',
                photos LONGTEXT NULL,
                -- مرحله ۲ — موقعیت و مشخصات
                city VARCHAR(120) NOT NULL DEFAULT '',
                neighborhood VARCHAR(120) NOT NULL DEFAULT '',
                address VARCHAR(1000) NOT NULL DEFAULT '',
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                location_source VARCHAR(20) NULL,
                passage_width VARCHAR(20) NOT NULL DEFAULT '',
                land_width VARCHAR(20) NOT NULL DEFAULT '',
                br_count VARCHAR(30) NOT NULL DEFAULT '',
                direction VARCHAR(10) NOT NULL DEFAULT '',
                -- مرحله ۳ — وضعیت ساخت
                building_age VARCHAR(10) NOT NULL DEFAULT '',
                current_floors VARCHAR(10) NOT NULL DEFAULT '',
                current_units VARCHAR(10) NOT NULL DEFAULT '',
                current_parkings VARCHAR(10) NOT NULL DEFAULT '',
                capacity_known TINYINT(1) NOT NULL DEFAULT 1,
                density VARCHAR(20) NOT NULL DEFAULT '',
                occupancy_rate VARCHAR(20) NOT NULL DEFAULT '',
                buildable_floors VARCHAR(10) NOT NULL DEFAULT '',
                buildable_area VARCHAR(20) NOT NULL DEFAULT '',
                buildable_units VARCHAR(10) NOT NULL DEFAULT '',
                permit_status VARCHAR(40) NOT NULL DEFAULT '',
                permit_number VARCHAR(60) NOT NULL DEFAULT '',
                permit_date VARCHAR(30) NOT NULL DEFAULT '',
                permit_floors VARCHAR(10) NOT NULL DEFAULT '',
                permit_area VARCHAR(20) NOT NULL DEFAULT '',
                -- مرحله ۴ — شرایط مشارکت
                owner_share VARCHAR(10) NOT NULL DEFAULT '',
                builder_share VARCHAR(10) NOT NULL DEFAULT '',
                balaghz VARCHAR(30) NOT NULL DEFAULT '',
                balaghz_amount VARCHAR(40) NOT NULL DEFAULT '',
                division_method VARCHAR(40) NOT NULL DEFAULT '',
                unit_shares LONGTEXT NULL,
                partner_parkings VARCHAR(10) NOT NULL DEFAULT '',
                partner_storage VARCHAR(10) NOT NULL DEFAULT '',
                duration VARCHAR(60) NOT NULL DEFAULT '',
                funding VARCHAR(60) NOT NULL DEFAULT '',
                value_from VARCHAR(40) NOT NULL DEFAULT '',
                value_to VARCHAR(40) NOT NULL DEFAULT '',
                notes TEXT NULL,
                -- مرحله ۵ — مالکیت و مدارک
                deed_status VARCHAR(40) NOT NULL DEFAULT '',
                deed_kind VARCHAR(40) NOT NULL DEFAULT '',
                owners_count VARCHAR(10) NOT NULL DEFAULT '',
                occupancy VARCHAR(40) NOT NULL DEFAULT '',
                legal_status LONGTEXT NULL,
                doc_deed VARCHAR(500) NOT NULL DEFAULT '',
                doc_permit VARCHAR(500) NOT NULL DEFAULT '',
                doc_endjob VARCHAR(500) NOT NULL DEFAULT '',
                doc_other LONGTEXT NULL,
                completeness TINYINT UNSIGNED NOT NULL DEFAULT 0,
                admin_note VARCHAR(1000) NOT NULL DEFAULT '',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_partnership_code (code),
                KEY idx_part_status (status),
                KEY idx_part_created (created_at),
                KEY idx_part_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ستون‌های جدید در آینده بدون دست‌زدن به CREATE TABLE اضافه می‌شوند
            melkinoPartAddColumn($pdo, 'value_from', "VARCHAR(40) NOT NULL DEFAULT ''");
            melkinoPartAddColumn($pdo, 'value_to', "VARCHAR(40) NOT NULL DEFAULT ''");
            melkinoPartAddColumn($pdo, 'deed_kind', "VARCHAR(40) NOT NULL DEFAULT ''");
            melkinoPartAddColumn($pdo, 'direction', "VARCHAR(10) NOT NULL DEFAULT ''");

            return $done = true;
        } catch (Throwable $e) {
            return $done = false;
        }
    }
}

if (!function_exists('melkinoPartFaDigits')) {
    function melkinoPartFaDigits(string $v): string
    {
        return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $v);
    }
}

if (!function_exists('melkinoPartCode')) {
    /** کد پیگیری مثل MKP-7F3K9Q2M */
    function melkinoPartCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'MKP-' . $out;
    }
}

if (!function_exists('melkinoPartCleanPath')) {
    /** فقط مسیرهای آپلود معتبرِ همین ماژول قبول می‌شوند (ضد path traversal) */
    function melkinoPartCleanPath(string $p): string
    {
        $p = ltrim(trim($p), '/');
        return preg_match('#^uploads/partnership/[A-Za-z0-9._-]+$#', $p) ? $p : '';
    }
}

if (!function_exists('melkinoPartCleanList')) {
    /** آرایهٔ ورودی → JSON تمیز؛ عناصر باید جزو لیست مجاز باشند */
    function melkinoPartCleanList($value, array $allowed = [], bool $asJson = true)
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            // ورودی ممکن است JSON آرایه باشد یا یک مسیر ساده (مثل doc_deed)
            $value = is_array($decoded) ? $decoded : (trim($value) !== '' ? [$value] : []);
        }
        if (!is_array($value)) {
            $value = [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = reset($item);
            }
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if ($allowed && !in_array($item, $allowed, true)) {
                continue;
            }
            $out[] = $item;
        }
        $out = array_values(array_unique($out));
        return $asJson ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $out;
    }
}

if (!function_exists('melkinoPartSanitize')) {
    /**
     * پاک‌سازی کامل ورودی فرم → آرایهٔ آمادهٔ INSERT
     * هر فیلد با whitelist؛ هیچ داده‌ای بدون پاک‌سازی وارد دیتابیس نمی‌شود.
     */
    function melkinoPartSanitize(array $in): array
    {
        $opt = melkinoPartOptions();
        $clean = static function ($v, int $max = 190): string {
            $v = trim(strip_tags((string)$v));
            $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
            return mb_substr($v, 0, $max);
        };
        $num = static function ($v, int $max = 20) use ($clean): string {
            $v = melkinoPartNormalizeDigits($clean($v, $max + 10));
            return preg_match('#^[\d.,/]+$#', $v) ? mb_substr($v, 0, $max) : '';
        };
        $enum = static function ($v, array $list) use ($clean): string {
            $v = $clean($v, 60);
            return in_array($v, $list, true) ? $v : '';
        };
        $isLand = ($enum($in['current_status'] ?? '', $opt['current_statuses']) === 'زمین خالی');

        $photos = [];
        foreach (melkinoPartCleanList($in['photos'] ?? [], [], false) as $p) {
            $ok = melkinoPartCleanPath((string)$p);
            if ($ok !== '') {
                $photos[] = $ok;
            }
        }
        $docs = static function ($v): array {
            $out = [];
            foreach (melkinoPartCleanList($v, [], false) as $p) {
                $ok = melkinoPartCleanPath((string)$p);
                if ($ok !== '') {
                    $out[] = $ok;
                }
            }
            return $out;
        };
        $docSingle = static function ($v) use ($docs): string {
            $d = $docs($v);
            return $d[0] ?? '';
        };

        $lat = isset($in['latitude']) ? (float)melkinoPartNormalizeDigits((string)$in['latitude']) : 0.0;
        $lng = isset($in['longitude']) ? (float)melkinoPartNormalizeDigits((string)$in['longitude']) : 0.0;
        $latOk = ($lat >= 24 && $lat <= 42) ? $lat : null;   // محدودهٔ جغرافیایی ایران
        $lngOk = ($lng >= 43 && $lng <= 64) ? $lng : null;

        $autoTitle = $clean($in['title'] ?? '', 300);
        if ($autoTitle === '') {
            // عنوان حذف شده از فرم؛ خودکار ساخته می‌شود
            $tParts = [];
            $tType = $enum($in['property_type'] ?? '', $opt['property_types']);
            $tArea = $num($in['area'] ?? '', 12);
            $tHood = $clean($in['neighborhood'] ?? '', 100);
            $tCity = $clean($in['city'] ?? '', 100);
            if ($tType !== '') $tParts[] = $tType;
            if ($tArea !== '') $tParts[] = melkinoPartFaDigits($tArea) . ' متری';
            $tLoc = trim($tHood . ($tHood && $tCity ? ' ' : '') . $tCity);
            if ($tLoc !== '') $tParts[] = $tLoc;
            $autoTitle = trim('مشارکت در ساخت ' . implode(' ', $tParts));
            if ($autoTitle === '') $autoTitle = 'درخواست مشارکت در ساخت';
        }

        $data = [
            'property_type'    => $enum($in['property_type'] ?? '', $opt['property_types']),
            'title'            => $autoTitle,
            'area'             => $num($in['area'] ?? '', 12),
            'current_status'   => $enum($in['current_status'] ?? '', $opt['current_statuses']),
            'photos'           => json_encode(array_slice($photos, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'city'             => '', // فیلد شهر از فرم حذف شده است
            'neighborhood'     => $clean($in['neighborhood'] ?? '', 100),
            'address'          => $clean($in['address'] ?? '', 500),
            'latitude'         => $latOk,
            'longitude'        => $lngOk,
            'location_source'  => in_array(($in['location_source'] ?? ''), ['map', 'gps', 'manual'], true) ? (string)$in['location_source'] : 'map',
            'passage_width'    => $num($in['passage_width'] ?? '', 6),
            'land_width'       => $num($in['land_width'] ?? '', 6),
            'br_count'         => $enum($in['br_count'] ?? '', $opt['br_counts']),
            'direction'        => $enum($in['direction'] ?? '', $opt['directions']),
            // اطلاعات بنا در نسخهٔ جدید فرم حذف شده است
            'building_age'     => '',
            'current_floors'   => '',
            'current_units'    => '',
            'current_parkings' => '',
            // اگر پروانه ندارد، ظرفیت ساخت نامشخص است (بررسی کارشناسی لازم)
            'capacity_known'   => ($enum($in['permit_status'] ?? '', $opt['permit_statuses']) === 'پروانه ندارم') ? 0 : 1,
            'density'          => $num($in['density'] ?? '', 4),
            'occupancy_rate'   => $num($in['occupancy_rate'] ?? '', 4),
            'buildable_floors' => $num($in['buildable_floors'] ?? '', 3),
            'buildable_area'   => $num($in['buildable_area'] ?? '', 10),
            'buildable_units'  => '', // از فرم حذف شد
            'permit_status'    => $enum($in['permit_status'] ?? '', $opt['permit_statuses']),
            'permit_number'    => '', // جزئیات پروانه از فرم حذف شد
            'permit_date'      => '',
            'permit_floors'    => '',
            'permit_area'      => '',
            // مرحلهٔ «شرایط مشارکت» از فرم حذف شده؛ ستون‌ها برای فاز بعدی می‌مانند
            'owner_share'      => '',
            'builder_share'    => '',
            'balaghz'          => 'خیر',
            'balaghz_amount'   => '',
            'division_method'  => '',
            'unit_shares'      => '[]',
            'partner_parkings' => '',
            'partner_storage'  => '',
            'duration'         => '',
            'funding'          => '',
            'value_from'       => '',
            'value_to'         => '',
            'notes'            => '',
            'deed_status'      => $enum($in['deed_status'] ?? '', $opt['deed_statuses']),
            'deed_kind'        => $enum($in['deed_kind'] ?? '', $opt['deed_kinds']),
            'owners_count'     => $num($in['owners_count'] ?? '', 3),
            'occupancy'        => $isLand ? 'خالی' : $enum($in['occupancy'] ?? '', $opt['occupancies']),
            'legal_status'     => melkinoPartCleanList($in['legal_status'] ?? [], $opt['legal_flags']),
            'doc_deed'         => $docSingle($in['doc_deed'] ?? ''),
            'doc_permit'       => $docSingle($in['doc_permit'] ?? ''),
            'doc_endjob'       => $isLand ? '' : $docSingle($in['doc_endjob'] ?? ''),
            'doc_other'        => json_encode(array_slice($docs($in['doc_other'] ?? ''), 0, 5), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $data['completeness'] = melkinoPartCompleteness($data);
        return $data;
    }
}

if (!function_exists('melkinoPartCompleteness')) {
    /** امتیاز کامل بودن (۰ تا ۱۰۰) — دقیقاً همان فرمولِ register-partnership.js */
    function melkinoPartCompleteness(array $d): int
    {
        $score = 0;
        $j = static function ($v) {
            $a = json_decode((string)$v, true);
            return is_array($a) ? $a : [];
        };
        if ((float)($d['area'] ?? 0) > 0) $score += 5;
        if (($d['property_type'] ?? '') !== '') $score += 5;
        if (($d['current_status'] ?? '') !== '') $score += 4;
        if (($d['neighborhood'] ?? '') !== '') $score += 6;
        if (mb_strlen(trim($d['address'] ?? '')) >= 4) $score += 5;
        if (($d['latitude'] ?? null) !== null && ($d['longitude'] ?? null) !== null) $score += 7;
        if (($d['br_count'] ?? '') !== '') $score += 4;
        if (($d['permit_status'] ?? '') !== '') $score += 7;
        if (($d['permit_status'] ?? '') === 'پروانه ندارم') {
            $score += 4; // ظرفیت ساخت فعلاً نامشخص است
        } elseif (($d['density'] ?? '') !== '' || ($d['occupancy_rate'] ?? '') !== ''
            || ($d['buildable_floors'] ?? '') !== '' || ($d['buildable_area'] ?? '') !== '') {
            $score += 8;
        }
        if (($d['deed_status'] ?? '') !== '') $score += 10;
        if (($d['deed_kind'] ?? '') !== '') $score += 4;
        if (($d['occupancy'] ?? '') !== '') $score += 4;
        if (($d['legal_status'] ?? '') !== '[]' && ($d['legal_status'] ?? '') !== '') $score += 6;
        if (($d['doc_deed'] ?? '') !== '') $score += 9;
        if (($d['doc_permit'] ?? '') !== '') $score += 6;
        // زمین خالی اصلاً پایان‌کار ندارد؛ امتیازش خودکار داده می‌شود
        if (($d['current_status'] ?? '') === 'زمین خالی' || ($d['doc_endjob'] ?? '') !== '') $score += 4;
        if (($d['doc_other'] ?? '') !== '[]' && ($d['doc_other'] ?? '') !== '') $score += 6;
        return max(0, min(100, $score));
    }
}

if (!function_exists('melkinoPartValidate')) {
    /** اعتبارسنجی الزامی‌ها؛ خروجی: آرایهٔ خطاها (خالی = سالم) */
    function melkinoPartValidate(array $d, bool $mapRequired = true): array
    {
        $errors = [];
        if (($d['property_type'] ?? '') === '') $errors[] = 'نوع ملک را انتخاب کنید.';
        if (($d['area'] ?? '') === '' || (float)$d['area'] <= 0) $errors[] = 'مساحت ملک را وارد کنید.';
        if (($d['current_status'] ?? '') === '') $errors[] = 'وضعیت فعلی ملک را انتخاب کنید.';
        if (($d['neighborhood'] ?? '') === '') $errors[] = 'محله را مشخص کنید.';
        if (mb_strlen($d['address'] ?? '') < 8) $errors[] = 'آدرس ملک را کامل‌تر بنویسید.';
        if ($mapRequired && ($d['latitude'] === null || $d['longitude'] === null)) $errors[] = 'موقعیت ملک را روی نقشه مشخص کنید.';
        if (($d['deed_status'] ?? '') === '') $errors[] = 'وضعیت سند را انتخاب کنید.';
        return $errors;
    }
}
