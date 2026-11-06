<?php
/**
 * استودیوی استوری ملکینو — لایه داده (فقط پنل مدیریت)
 * --------------------------------------------------------------
 * از جدول‌های موجود استفاده می‌کند: ads, images, settings (group=ig_studio).
 * تنها جدول‌های جدید: ig_studio_projects و ig_studio_exports (ایجاد ایمن و idempotent).
 * هیچ داده‌ی نمایشی ساختگی تولید نمی‌شود؛ مقدار خالی = «نامشخص» و در UI نشان داده می‌شود.
 */
declare(strict_types=1);

require_once __DIR__ . '/ig-studio-fonts.php';

if (!defined('IGS_CANVAS_W')) {
    define('IGS_CANVAS_W', 1080);
    define('IGS_CANVAS_H', 1920);
    define('IGS_EXPORT_DIR', __DIR__ . '/storage/ig-studio');
    define('IGS_MAX_UPLOAD', 15 * 1024 * 1024);
    define('IGS_MAX_STATE_BYTES', 262144);
    define('IGS_PLACEHOLDER_PHONE', '021-000 00 00');
}
// اندازه‌های مجاز خروجی تصویری (همان قالب‌های FORMATS در کلاینت)
if (!defined('IGS_ALLOWED_SIZES')) {
    define('IGS_ALLOWED_SIZES', [[1080, 1920], [1080, 1080], [1080, 1528], [1200, 628], [1080, 1350]]);
}
/** برچسب قابل‌نمایش نوع خروجی بر اساس MIME ذخیره‌شده */
function igsMimeLabel(string $mime): string
{
    $map = ['image/png' => 'PNG', 'image/webp' => 'WebP', 'application/pdf' => 'PDF', 'application/zip' => 'ZIP'];
    return $map[$mime] ?? 'JPEG';
}
/** پسوند فایل دانلودی بر اساس MIME */
function igsMimeExt(string $mime): string
{
    $map = ['image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'application/zip' => 'zip'];
    return $map[$mime] ?? 'jpg';
}
// مدل داده‌ی نسخه ۲ (elements/groups/metadata). نسخه‌ی ۱ همچنان با igsSanitizeState قدیمی پردازش می‌شود.
if (!defined('IGS_SCHEMA_V2')) {
    define('IGS_SCHEMA_V2', 2);
    define('IGS_V2_MAX_ELEMENTS', 300);
    define('IGS_V2_MAX_GROUPS', 100);
    define('IGS_V2_TYPES', ['text', 'photo', 'rect', 'line', 'field', 'specs', 'chips', 'logo', 'qr', 'sticker']);
    define('IGS_V2_FONTS', ['', 'vazir', 'lalezar', 'amiri']);
}

if (!function_exists('igsEnsureSchema')) {

    function igsEnsureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL DEFAULT '',
            ad_id VARCHAR(64) NOT NULL DEFAULT '',
            template_id VARCHAR(64) NOT NULL DEFAULT '',
            state_json MEDIUMTEXT NOT NULL,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_igs_proj_updated (updated_at),
            INDEX idx_igs_proj_ad (ad_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_exports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NULL,
            project_title VARCHAR(200) NOT NULL DEFAULT '',
            ad_id VARCHAR(64) NOT NULL DEFAULT '',
            file_rel VARCHAR(255) NOT NULL,
            mime VARCHAR(40) NOT NULL,
            width INT UNSIGNED NOT NULL,
            height INT UNSIGNED NOT NULL,
            bytes INT UNSIGNED NOT NULL,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_igs_ex_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // قالب‌های ذخیره‌شده‌ی خود مدیر (طراحی کامل با نام دلخواه)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL DEFAULT '',
            template_id VARCHAR(64) NOT NULL DEFAULT '',
            state_json MEDIUMTEXT NOT NULL,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_igs_tpl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // پروفایل‌های برند شخصی (هر ادمین فقط ردیف‌های خودش را می‌بیند)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_brands (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner VARCHAR(120) NOT NULL DEFAULT '',
            name VARCHAR(120) NOT NULL DEFAULT '',
            data TEXT NOT NULL,
            logo_rel VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_igs_brand_owner (owner, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // نسخه‌ی اصلی وضعیت پروژه پیش از هر مهاجرت نسخه (بدون تغییر، فقط افزودن)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_project_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL DEFAULT '',
            state_json MEDIUMTEXT NOT NULL,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            INDEX idx_igs_ver_project (project_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ig_studio_project_backups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            schema_version INT NOT NULL DEFAULT 1,
            state_json MEDIUMTEXT NOT NULL,
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_igs_bk_proj (project_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // اتصال خروجی به محتوای ایجنت اینستاگرام (برای جدول‌های قبلی هم امن است)
        igsEnsureColumn($pdo, 'ig_studio_exports', 'agent_content_id',
            'agent_content_id BIGINT UNSIGNED NULL, ADD KEY idx_igs_ex_agent (agent_content_id)');
        $done = true;
    }

    /** افزودن ستون به جدول موجود اگر وجود نداشته باشد (ALTER فقط یک‌بار) */
    function igsEnsureColumn(PDO $pdo, string $table, string $column, string $ddl): void
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        if ((int)$st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $ddl);
        }
    }

    /** پیشوند آدرس نسبی پنل/API (برای تصاویر و لینک‌های same-origin) */
    function igsBaseUrl(): string
    {
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        return rtrim($dir, '/') . '/';
    }

    function igsFa(string $s): string
    {
        return strtr($s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    function igsClip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s;
    }

    /**
     * قیمت خام آگهی (ممکن است «ودیعه | اجاره» یا متن آزاد باشد) را با قالب سایت نمایش می‌دهد:
     * عدد → جداکننده‌ی هزارگان + «تومان»؛ متن آزاد همان‌طور بماند.
     */
    function igsPriceText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $parts = array_map('trim', explode('|', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $clean = strtr($p, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٬' => '', '،' => '', ',' => '', ' ' => '', 'تومان' => '']);
            if ($clean !== '' && is_numeric($clean) && (float)$clean > 0) {
                $n = (float)$clean;
                $out[] = igsFa(strtr(number_format($n, 0, '.', ','), [',' => '٬'])) . ' تومان';
            } else {
                $out[] = igsFa($p);
            }
        }
        return implode(' | ', $out);
    }

    /** فهرست آگهی‌ها برای انتخاب در استودیو */
    function igsAdsList(PDO $pdo, string $q, int $limit, int $offset): array
    {
        $where = '1=1';
        $args = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (a.title LIKE ? OR a.location LIKE ? OR a.id LIKE ?)';
            $args = [$like, $like, $like];
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM ads a WHERE $where");
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();

        $sql = "SELECT a.id, a.numeric_id, a.title, a.status, a.transaction_type, a.property_type,
                       a.location, a.published_at,
                       (SELECT COUNT(*) FROM images i WHERE i.ad_id = a.id AND i.is_selected = 1) AS photos
                  FROM ads a
                 WHERE $where
              ORDER BY a.published_at DESC, a.numeric_id DESC
                 LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
        foreach ($args as $i => $v) {
            $st->bindValue($i + 1, $v, PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = [
                'id' => (string)$r['id'],
                'title' => igsRedactPhones((string)($r['title'] ?? '')),
                'status' => (string)($r['status'] ?? ''),
                'transaction' => (string)($r['transaction_type'] ?? ''),
                'property_type' => (string)($r['property_type'] ?? ''),
                'location' => (string)($r['location'] ?? ''),
                'photos' => (int)$r['photos'],
            ];
        }
        return ['items' => $items, 'total' => $total];
    }

    /** جزئیات واقعی یک آگهی؛ هر مقدار خالی حذف می‌شود (بدون داده‌ی ساختگی) */
    /** اسلاگ عکس‌های نمونه‌ی هر نوع ملک (همان پوشه‌ی assets/defaults و پنل «عکس‌های پیش‌فرض انواع ملک») */
    function igsDefaultSlug(string $ptype): string
    {
        // تطبیق با زیررشته: نوع ملک در ثبت‌نام «ویلایی»، «تجاری / مغازه» و… ذخیره می‌شود
        $t = trim($ptype);
        if ($t === '') { return ''; }
        $en = ['apartment' => 'apartment', 'villa' => 'villa', 'land' => 'land', 'garden' => 'garden', 'office' => 'office', 'shop' => 'shop'];
        if (isset($en[strtolower($t)])) { return $en[strtolower($t)]; }
        if (mb_strpos($t, 'آپارتمان') !== false) { return 'apartment'; }
        if (mb_strpos($t, 'ویلا') !== false) { return 'villa'; }
        if (mb_strpos($t, 'تجاری') !== false || mb_strpos($t, 'مغازه') !== false) { return 'shop'; }
        if (mb_strpos($t, 'اداری') !== false) { return 'office'; }
        if (mb_strpos($t, 'باغ') !== false) { return 'garden'; }
        if (mb_strpos($t, 'زمین') !== false) { return 'land'; }
        return '';
    }

    /** همه‌ی فیلدهای آگهی (تعریف‌های انتشار + ستون‌های تکمیلی) با مقدار واقعی آگهی؛ مقدار خالی = ثبت نشده */
    /** فیلدهای خصوصی مالک (نام، شماره، آدرس دقیق): هرگز در استوری نمی‌آیند */
    /** شماره‌ی تلفن (موبایل یا ثابت، با ارقام لاتین یا فارسی) در متن آزاد پنهان می‌شود */
    function igsRedactPhones(string $s): string
    {
        return (string)preg_replace(
            '/(?<![0-9۰-۹])[0۰][0-9۰-۹](?:[\s\-]?[0-9۰-۹]){9}(?![0-9۰-۹])/u',
            '[شماره حذف شد]',
            $s
        );
    }

    function igsAdFields(array $ad, array $details, string $priceText, string $ptype): array
    {
        $defs = [];
        try {
            if (!function_exists('melkinoPublishFieldDefs') && is_file(__DIR__ . '/bot-settings.php')) {
                require_once __DIR__ . '/bot-settings.php';
            }
            if (function_exists('melkinoPublishFieldDefs')) {
                $defs = melkinoPublishFieldDefs(null);
            }
        } catch (Throwable $e) {
            $defs = [];
        }
        $c = function (string $k) use ($ad): string {
            return trim((string)($ad[$k] ?? ''));
        };
        $num = function (string $s): string {
            $t = str_replace(',', '', $s);
            return ($t !== '' && is_numeric($t)) ? igsFa(number_format((float)$t)) : igsFa($s);
        };
        $yes = function ($v): string {
            return (string)$v === '1' ? 'بله' : '';
        };
        $isLand = ($ptype === 'زمین');
        $loanParts = [];
        if ($c('has_loan') === '1') {
            $loanFields = [['loan_bank', 'بانک'], ['loan_amount', 'مبلغ'], ['loan_type', 'نوع'], ['loan_duration', 'مدت'], ['loan_installment', 'قسط'], ['loan_installments_paid', 'اقساط پرداخت‌شده']];
            foreach ($loanFields as $lf) {
                $v = in_array($lf[0], ['loan_amount', 'loan_installment'], true) ? igsPriceText($c($lf[0])) : $c($lf[0]);
                if ($v !== '') {
                    $loanParts[] = $lf[1] . ': ' . $v;
                }
            }
        }
        $deedParts = array_values(array_filter([$c('deed_type'), $c('deed_notes')], function ($x) { return $x !== ''; }));
        $detail = function (string $k) use ($details): string {
            $v = $details[$k] ?? '';
            if (is_array($v)) {
                $v = implode('، ', array_map('strval', $v));
            }
            $v = trim((string)$v);
            return ($v === '1' && strpos($k, 'has_') === 0) ? 'دارد' : $v;
        };
        $areaRaw = $isLand ? $c('land_area') : $c('area');
        $values = [
            'title' => $c('title'), 'transaction' => $c('transaction_type'), 'property_type' => $c('property_type'),
            'location' => $c('location'), 'address' => $c('address'),
            'area' => $areaRaw !== '' ? $num($areaRaw) . ' متر' : '',
            'rooms' => $c('rooms'), 'floor' => $c('floor'), 'year' => $c('year'), 'building_age' => $c('building_age'),
            'price' => $priceText !== '' ? $priceText : 'تماس بگیرید', 'price_per_meter' => '',
            'down_payment' => igsPriceText($c('down_payment')), 'deposit' => igsPriceText($c('deposit')),
            'rent_monthly' => igsPriceText($c('rent_monthly')), 'full_rent' => $yes($ad['full_rent'] ?? 0),
            'loan' => implode(' · ', $loanParts), 'deed' => implode(' - ', $deedParts), 'exchange' => $c('exchange_types'),
            'description' => $c('description'), 'contact' => trim($c('gender') . ' ' . $c('last_name')),
            'phone' => $c('phone'), 'consultant' => '', 'ad_id' => igsFa((string)$ad['id']), 'ad_link' => '',
        ];
        $out = [];
        foreach ($defs as $key => $def) {
            $label = (string)($def['label'] ?? $key);
            $key = (string)$key;
            if (in_array($key, ['contact', 'phone', 'address'], true)) {
                continue;
            }
            if (strpos($key, 'pd.') === 0) {
                $pv = $detail(substr($key, 3));
                $out[] = ['key' => $key, 'label' => $label, 'group' => 'مشخصات ملک', 'value' => igsRedactPhones(($pv === '0' || $pv === '۰') ? '' : $pv), 'private' => false];
                continue;
            }
            $group = in_array($key, ['contact', 'phone', 'consultant'], true) ? 'تماس' : 'اطلاعات آگهی';
            $val = (string)($values[$key] ?? '');
            $out[] = ['key' => $key, 'label' => $label, 'group' => $group, 'value' => igsRedactPhones(($val === '0' || $val === '۰') ? '' : $val), 'private' => false];
        }
        $extra = [
            'built_area' => ['زیربنا', 'area'], 'land_area' => ['متراژ زمین', 'area'], 'total_price' => ['قیمت کل', 'price'],
            'delivery_date' => ['تاریخ تحویل', ''], 'vacancy_date' => ['تاریخ تخلیه', ''], 'is_vacant' => ['خالی بودن', 'yes'],
            'is_renovated' => ['بازسازی‌شده', 'yes'], 'is_old' => ['قدیمی', 'yes'], 'water_share' => ['سهم آب', ''],
            'well_name' => ['نام چاه', ''], 'visit_hours' => ['ساعات بازدید', ''], 'tags' => ['برچسب‌ها', ''],
            'exchange_with' => ['معاوضه با', ''], 'price_condition' => ['شرایط قیمت', ''], 'payment_terms' => ['شرایط پرداخت', ''],
            'loan_notes' => ['یادداشت وام', ''], 'deed_notes' => ['یادداشت سند', ''],
        ];
        foreach ($extra as $k => $meta) {
            $raw = $c($k);
            if ($meta[1] === 'area') { $v = $raw !== '' ? $num($raw) . ' متر' : ''; }
            elseif ($meta[1] === 'price') { $v = igsPriceText($raw); }
            elseif ($meta[1] === 'yes') { $v = $yes($ad[$k] ?? ''); }
            else { $v = $raw; }
            $out[] = ['key' => 'col.' . $k, 'label' => $meta[0], 'group' => 'ستون‌های تکمیلی', 'value' => igsRedactPhones(($v === '0' || $v === '۰') ? '' : $v), 'private' => false];
        }
        return $out;
    }

    /** r68: تاریخ میلادی (timestamp) به شمسی yyyy/mm/dd — الگوریتم استاندارد، بدون وابستگی */
    if (!function_exists('igsJalaliDate')) {
        function igsJalaliDate(int $ts): string
        {
            $gy = (int)date('Y', $ts); $gm = (int)date('n', $ts); $gd = (int)date('j', $ts);
            $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
            $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
            $jy = -1595 + 33 * intdiv($days, 12053);
            $days %= 12053;
            $jy += 4 * intdiv($days, 1461);
            $days %= 1461;
            if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
            if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
            else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
            return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        }
    }

    function igsAdDetail(PDO $pdo, string $adId, string $base): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        $st->execute([$adId]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ad) {
            return null;
        }
        $ptype = trim((string)($ad['property_type'] ?? ''));
        $isLand = ($ptype === 'زمین');

        $facts = [];
        $add = static function (string $key, string $label, string $value) use (&$facts) {
            $value = trim($value);
            if ($value !== '' && $value !== '0' && $value !== '۰') {
                $facts[] = ['key' => $key, 'label' => $label, 'value' => $value];
            }
        };
        $num = static function ($v): string {
            $s = trim((string)$v);
            return ($s === '' || !is_numeric(str_replace(',', '', $s))) ? '' : number_format((float)str_replace(',', '', $s));
        };

        $areaVal = $isLand ? ($ad['land_area'] ?? '') : ($ad['area'] ?? '');
        if ($isLand && trim((string)$areaVal) === '') {
            $areaVal = $ad['area'] ?? '';
        }
        $add('area', $isLand ? 'متراژ زمین' : 'متراژ', $num($areaVal) !== '' ? igsFa($num($areaVal)) . ' متر' : '');
        if (!$isLand) {
            $add('built_area', 'زیربنا', $num($ad['built_area'] ?? '') !== '' ? igsFa($num($ad['built_area'])) . ' متر' : '');
        }
        $add('rooms', 'تعداد اتاق', igsFa((string)($ad['rooms'] ?? '')));
        $add('floor', 'طبقه', igsFa((string)($ad['floor'] ?? '')));
        $add('year', 'سال ساخت', igsFa((string)($ad['year'] ?? '')));

        $price = '';
        if (function_exists('melkinoPublicAdPrice')) {
            $price = (string)melkinoPublicAdPrice($ad);
        }
        $priceText = igsPriceText($price);
        // اگر ودیعه/اجاره جدا وجود دارد، «قیمت» تکراری نشان داده نمی‌شود
        if (strpos($price, '|') === false) {
            $add('price', 'قیمت', $priceText);
        }
        // r70: ودیعه و اجاره فقط برای آگهی‌های اجاره/رهن؛ در آگهی فروش نمایش داده نمی‌شود
        $isRentTx = (mb_strpos((string)($ad['transaction_type'] ?? ''), 'اجاره') !== false) || (mb_strpos((string)($ad['transaction_type'] ?? ''), 'رهن') !== false);
        if ($isRentTx && !empty($ad['deposit'])) {
            $add('deposit', 'ودیعه', igsPriceText((string)$ad['deposit']));
        }
        if ($isRentTx && !empty($ad['rent_monthly'])) {
            $add('rent', 'اجاره ماهانه', igsPriceText((string)$ad['rent_monthly']));
        }

        // r68: فیلدهای کارت صفحه‌ی اصلی برای استوری‌های «کارت کامل»
        $c68 = static function (string $k) use ($ad): string { return trim((string)($ad[$k] ?? '')); };
        if ($c68('building_age') !== '') { $add('building_age', 'سن بنا', igsFa($c68('building_age')) . ' سال'); }
        if ($isRentTx && !empty($ad['full_rent_enabled']) && $c68('full_rent') !== '') { $add('full_rent', 'رهن کامل', igsPriceText($c68('full_rent'))); }
        if (!$isRentTx && !empty($ad['has_loan']) && $c68('loan_amount') !== '') { $add('loan', 'وام', 'وام ' . igsPriceText($c68('loan_amount'))); }
        if ($c68('deed_type') !== '') { $add('deed', 'نوع سند', $c68('deed_type')); }
        if (!$isRentTx && $c68('exchange_types') !== '') { $add('exchange', 'معاوضه', $c68('exchange_types')); }
        if (!empty($ad['is_not_keyed'])) { $add('key', 'کلید', 'کلید نخورده'); }
        $add('property_type', 'نوع ملک', $ptype);
        $add('code', 'کد آگهی', igsFa((string)$ad['id']));
        if (!empty($ad['created_at']) && ($tsReg = strtotime((string)$ad['created_at'])) !== false) { $add('date', 'تاریخ ثبت', igsFa(igsJalaliDate($tsReg))); }
        $tagList = is_string($ad['tags'] ?? null) ? json_decode((string)$ad['tags'], true) : ($ad['tags'] ?? null);
        if (is_array($tagList) && $tagList) { $add('tags', 'برچسب', implode('، ', array_map('strval', array_slice($tagList, 0, 3)))); }

        // امکانات از property_details (کلیدهای has_* با مقدار ۱)
        $amen = [];
        $amenItems = [];
        $details = [];
        $raw = $ad['property_details'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $details = $dec;
            }
        }
        $defs = [];
        try {
            if (!function_exists('melkinoPublishFieldDefs') && is_file(__DIR__ . '/bot-settings.php')) {
                require_once __DIR__ . '/bot-settings.php';
            }
            if (function_exists('melkinoPublishFieldDefs')) {
                // برچسب‌ها از فهرست کلی تعریف‌های سایت (بدون فیلتر نوع ملک) خوانده می‌شوند
                $defs = melkinoPublishFieldDefs(null);
            }
        } catch (Throwable $e) {
            $defs = [];
        }
        foreach ($details as $k => $v) {
            if (strpos((string)$k, 'has_') !== 0 || trim((string)$v) !== '1') {
                continue;
            }
            // فقط امکاناتی که برچسب فارسی در تعریف‌های سایت دارند نمایش داده می‌شوند (کلید خام انگلیسی نمایش داده نمی‌شود)
            $label = trim((string)($defs['pd.' . $k]['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $amenItems[] = ['key' => (string)$k, 'label' => $label];
            if (count($amenItems) >= 300) {
                break;
            }
        }
        // امکانات ثبت‌شده در جدول amenities (همان منبعی که کارت و نقشه‌ی سایت می‌خوانند)
        try {
            $am = $pdo->prepare('SELECT a.id, a.name FROM ad_amenities aa JOIN amenities a ON a.id = aa.amenity_id WHERE aa.ad_id = ? AND a.is_active = 1 ORDER BY a.sort_order, a.name');
            $am->execute([$adId]);
            foreach ($am->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $label = trim((string)$row['name']);
                if ($label !== '' && count($amenItems) < 300) {
                    $amenItems[] = ['key' => 'am:' . (int)$row['id'], 'label' => $label];
                }
            }
        } catch (Throwable $e) {
            // نصب قدیمی بدون این جدول: فقط امکانات property_details نمایش داده می‌شوند
        }
        $amen = array_slice(array_column($amenItems, 'label'), 0, 12);

        // تصاویر: اول تصاویر انتخاب‌شده؛ اگر نبود، همه‌ی تصاویر
        $photos = [];
        $fetchPhotos = function (string $where) use ($pdo, $adId) {
            $s = $pdo->prepare("SELECT id, filename, is_primary FROM images WHERE ad_id = ? AND $where
                                ORDER BY is_primary DESC, sort_order ASC, id ASC LIMIT 10");
            $s->execute([$adId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $rows = $fetchPhotos('is_selected = 1');
        if (!$rows) {
            $rows = $fetchPhotos('1=1');
        }
        foreach ($rows as $r) {
            $rel = ltrim(str_replace('\\', '/', (string)$r['filename']), '/');
            if (strpos($rel, 'uploads/') !== 0) {
                $rel = 'uploads/' . basename($rel);
            }
            if (!is_file(__DIR__ . '/' . $rel)) {
                continue;
            }
            $photos[] = ['id' => (int)$r['id'], 'url' => $base . $rel, 'primary' => (bool)$r['is_primary']];
        }
        // عکس‌های نمونه‌ی همان نوع ملک؛ برای آگهی بدون عکس اختصاصی یا کنار عکس‌های آگهی (در خروجی برچسب «نمونه» ندارد، در استودیو مشخص است)
        // عکس‌های نمونه: اول نمونه‌های همان نوع ملک؛ اگر نوع ملک ناشناخته باشد یا نمونه‌ای برایش نباشد،
        // نمونه‌های آپارتمان (عمومی) استفاده می‌شوند تا کادر عکس قالب خالی نماند.
        $sampleSlug = igsDefaultSlug(trim((string)($ad['property_type'] ?? '')));
        $sampleFound = 0;
        $collectSamples = function (string $slug) use (&$photos, $base, &$sampleFound) {
            // فقط عکس‌های عمودی پوشه‌ی استودیو (assets/studio-samples)؛ عکس‌های افقی پیش‌فرض سایت استفاده نمی‌شوند
            $files = glob(__DIR__ . '/assets/studio-samples/' . $slug . '-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
            sort($files, SORT_NATURAL);
            foreach ($files as $file) {
                $dim = @getimagesize($file);
                if (!is_array($dim) || $dim[1] <= $dim[0]) { continue; }
                $photos[] = ['id' => 0, 'url' => $base . 'assets/studio-samples/' . basename($file), 'primary' => false, 'sample' => true];
                $sampleFound++;
            }
        };
        if ($sampleSlug !== '') { $collectSamples($sampleSlug); }
        if ($sampleFound === 0) { $collectSamples('apartment'); }

        // مشاور: فقط اگر تلفن واقعی داشته باشد
        $contact = null;
        if (function_exists('findConsultantForAd') && function_exists('getConsultantDisplayData')) {
            try {
                $c = getConsultantDisplayData(findConsultantForAd($ptype, (string)($ad['transaction_type'] ?? '')));
                if ($c && trim((string)$c['phone']) !== '') {
                    $contact = ['name' => (string)$c['name'], 'phone' => igsFa((string)$c['phone']), 'source' => 'consultant'];
                }
            } catch (Throwable $e) {
                $contact = null;
            }
        }

        // r68: امکانات کلیدی با آیکون — از ستون‌های جدول، اگر در امکانات نیامده باشد
        $amenLabels = array_column($amenItems, 'label');
        foreach (['parking' => 'پارکینگ', 'elevator' => 'آسانسور', 'warehouse' => 'انباری'] as $fk => $fl) {
            $flagOn = !empty($ad[$fk]) && (string)$ad[$fk] !== '0';
            if ($flagOn && !in_array($fl, $amenLabels, true)) {
                $amenItems[] = ['key' => $fk, 'label' => $fl];
                $amenLabels[] = $fl;
            }
        }
        $desc = igsClip((string)($ad['description'] ?? ''), 220);

        return [
            'id' => (string)$ad['id'],
            'title' => igsRedactPhones((string)($ad['title'] ?? '')),
            'status' => (string)($ad['status'] ?? ''),
            'transaction' => (string)($ad['transaction_type'] ?? ''),
            'property_type' => $ptype,
            'location' => (string)($ad['location'] ?? ''),
            'code' => igsFa((string)$ad['id']),
            'price_text' => $priceText,
            'facts' => $facts,
            'amenities' => $amen,
            'amenity_items' => $amenItems,
            'fields' => igsAdFields($ad, $details, $priceText, $ptype),
            'description' => igsRedactPhones($desc),
            'photos' => $photos,
            'default_photo' => igsDefaultPhotoGet($pdo, (string)$ad['id'], array_column($photos, 'url'), $base),
            'contact' => $contact,
        ];
    }

    /** برند: نام/لاتین/لوگو از تنظیمات موجود سایت؛ تلفن placeholder نمایش داده نمی‌شود */
    /** مسیر نسبی لوگوی اختصاصی استودیو (فقط الگوی امن؛ از تنظیمات خوانده می‌شود) */
    function igsLogoRel(PDO $pdo): string
    {
        if (!function_exists('dbSettingGet')) {
            return '';
        }
        $rel = (string)dbSettingGet($pdo, 'ig_studio', 'logo_rel', '');
        return preg_match('#^brand/logo-[a-f0-9]{16}\.(png|jpg|webp)$#', $rel) ? $rel : '';
    }

    /** ذخیره‌ی لوگوی اختصاصی استودیو؛ لوگوی سایت دست‌نخورده می‌ماند */
    /** انواع ملک برای عکس‌های نمونه‌ی استودیو (پوشه‌ی جدا از عکس‌های پیش‌فرض کارت‌ها) */
    function igsSampleTypes(): array
    {
        return ['apartment' => 'آپارتمان', 'villa' => 'ویلا', 'shop' => 'تجاری', 'office' => 'اداری', 'land' => 'زمین', 'garden' => 'باغ'];
    }

    function igsSampleMax(): int
    {
        return 20;
    }

    function igsSampleDir(): string
    {
        return __DIR__ . '/assets/studio-samples';
    }

    /** فهرست عکس‌های عمودی هر نوع؛ فقط فایل‌هایی که ارتفاع بیشتر از عرض دارند */
    function igsSampleState(string $base): array
    {
        $out = [];
        foreach (igsSampleTypes() as $slug => $label) {
            $files = glob(igsSampleDir() . '/' . $slug . '-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
            sort($files, SORT_NATURAL);
            $items = [];
            foreach ($files as $f) {
                $d = @getimagesize($f);
                if (!is_array($d) || $d[1] <= $d[0]) { continue; }
                $items[] = ['name' => basename($f), 'url' => $base . 'assets/studio-samples/' . basename($f)];
            }
            $out[] = ['slug' => $slug, 'label' => $label, 'count' => count($items), 'max' => igsSampleMax(), 'items' => $items];
        }
        return $out;
    }

    function igsSampleSave(array $file, string $slug, string $base): array
    {
        $types = igsSampleTypes();
        if (!isset($types[$slug])) {
            throw new RuntimeException('نوع ملک نامعتبر است.');
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('فایل عکس دریافت نشد.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
            throw new RuntimeException('حجم عکس باید کمتر از ۵ مگابایت باشد.');
        }
        $info = @getimagesize((string)$file['tmp_name']);
        $mimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($mimes[$info['mime'] ?? ''])) {
            throw new RuntimeException('فقط فایل PNG، JPG یا WEBP مجاز است.');
        }
        if ($info[1] <= $info[0]) {
            throw new RuntimeException('فقط عکس عمودی مجاز است (ارتفاع بیشتر از عرض).');
        }
        if ($info[0] < 600 || $info[1] < 900) {
            throw new RuntimeException('ابعاد عکس خیلی کوچک است (حداقل ۶۰۰×۹۰۰ پیکسل).');
        }
        $dir = igsSampleDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('پوشه‌ی عکس‌های استودیو ساخته نشد.');
        }
        $slot = 0;
        for ($n = 1; $n <= igsSampleMax(); $n++) {
            if (!glob($dir . '/' . $slug . '-' . $n . '.{jpg,jpeg,png,webp}', GLOB_BRACE)) { $slot = $n; break; }
        }
        if ($slot === 0) {
            throw new RuntimeException('ظرفیت این نوع پر است (حداکثر ' . igsSampleMax() . ' عکس).');
        }
        $name = $slug . '-' . $slot . '.' . $mimes[$info['mime']];
        if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('ذخیره‌ی عکس ناموفق بود.');
        }
        return ['name' => $name, 'url' => $base . 'assets/studio-samples/' . $name];
    }

    function igsSampleDelete(string $name): bool
    {
        if (!preg_match('/^(apartment|villa|shop|office|land|garden)-\d{1,2}\.(jpe?g|png|webp)$/i', $name)) {
            return false;
        }
        $path = igsSampleDir() . '/' . $name;
        return is_file($path) && @unlink($path);
    }

    /** عکس‌های پس‌زمینه‌ی عمومی (assets/studio-backgrounds/bg-NNN.ext) */
    function igsBackgroundList(string $base): array
    {
        $files = glob(__DIR__ . '/assets/studio-backgrounds/bg-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
        sort($files, SORT_NATURAL);
        $out = [];
        foreach ($files as $f) {
            $d = @getimagesize($f);
            if (!is_array($d) || $d[1] <= $d[0]) { continue; }
            $out[] = ['name' => basename($f), 'url' => $base . 'assets/studio-backgrounds/' . basename($f)];
        }
        return $out;
    }

    /** آیا این نشانی یکی از فایل‌های بانک پس‌زمینه است؟ (بدون مسیر دلخواه) */
    function igsBackgroundUrlOk(string $url, string $base): bool
    {
        $prefix = $base . 'assets/studio-backgrounds/';
        if (strpos($url, $prefix) !== 0) { return false; }
        $name = substr($url, strlen($prefix));
        return (bool)preg_match('/^bg-\d{3}\.(jpe?g|png|webp)$/i', $name)
            && is_file(__DIR__ . '/assets/studio-backgrounds/' . $name);
    }

    /** عکس پیش‌فرض ذخیره‌شده‌ی آگهی؛ فقط اگر هنوز معتبر باشد (عکس همان آگهی یا بانک پس‌زمینه) */
    function igsDefaultPhotoGet(PDO $pdo, string $adId, array $adPhotoUrls, string $base): string
    {
        $url = (string)dbSettingGet($pdo, 'ig_studio', 'default_photo_' . $adId, '');
        if ($url === '') { return ''; }
        return (in_array($url, $adPhotoUrls, true) || igsBackgroundUrlOk($url, $base)) ? $url : '';
    }

    function igsLogoSave(PDO $pdo, array $file, string $by): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('فایل لوگو دریافت نشد.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('حجم لوگو باید کمتر از ۲ مگابایت باشد.');
        }
        $info = @getimagesize((string)$file['tmp_name']);
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($types[$info['mime'] ?? ''])) {
            throw new RuntimeException('فقط فایل PNG، JPG یا WEBP مجاز است.');
        }
        if ($info[0] < 64 || $info[1] < 64) {
            throw new RuntimeException('ابعاد لوگو خیلی کوچک است (حداقل ۶۴ پیکسل).');
        }
        $dir = IGS_EXPORT_DIR . '/brand';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new RuntimeException('پوشه‌ی لوگو ساخته نشد.');
        }
        $rel = 'brand/logo-' . bin2hex(random_bytes(8)) . '.' . $types[$info['mime']];
        if (!@move_uploaded_file((string)$file['tmp_name'], IGS_EXPORT_DIR . '/' . $rel)) {
            throw new RuntimeException('ذخیره‌ی لوگو ناموفق بود.');
        }
        $old = igsLogoRel($pdo);
        dbSettingSet($pdo, 'ig_studio', 'logo_rel', $rel, 'string', null);
        if ($old !== '' && is_file(IGS_EXPORT_DIR . '/' . $old)) {
            @unlink(IGS_EXPORT_DIR . '/' . $old);
        }
        return ['source' => 'studio', 'width' => (int)$info[0], 'height' => (int)$info[1]];
    }

    function igsLogoDelete(PDO $pdo): void
    {
        $old = igsLogoRel($pdo);
        dbSettingSet($pdo, 'ig_studio', 'logo_rel', '', 'string', null);
        if ($old !== '' && is_file(IGS_EXPORT_DIR . '/' . $old)) {
            @unlink(IGS_EXPORT_DIR . '/' . $old);
        }
    }

    /* ---------- پروفایل‌های برند شخصی (owner-scoped) ---------- */

    /** اعتبارسنجی ورودی پروفایل برند؛ خطا = RuntimeException با پیام فارسی */
    function igsBrandNormalize(array $in): array
    {
        $name = trim(igsClip((string)($in['name'] ?? ''), 80));
        if ($name === '') {
            throw new RuntimeException('نام برند الزامی است.');
        }
        $phone = trim((string)($in['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9۰-۹+\-\s()]{4,20}$/u', $phone)) {
            throw new RuntimeException('شماره تلفن معتبر نیست.');
        }
        $web = trim((string)($in['website'] ?? ''));
        if ($web !== '' && !preg_match('#^(https?://)?[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$#i', $web)) {
            throw new RuntimeException('نشانی وب‌سایت معتبر نیست.');
        }
        $social = ltrim(trim((string)($in['social'] ?? '')), '@');
        if ($social !== '' && !preg_match('/^[A-Za-z0-9._]{1,30}$/', $social)) {
            throw new RuntimeException('شناسه‌ی اینستاگرام معتبر نیست (فقط حروف لاتین، عدد، نقطه و زیرخط).');
        }
        $colors = [];
        $c = is_array($in['colors'] ?? null) ? $in['colors'] : [];
        foreach (['accent', 'ink'] as $k) {
            $v = trim((string)($c[$k] ?? ''));
            if ($v !== '') {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    throw new RuntimeException('رنگ انتخاب‌شده معتبر نیست.');
                }
                $colors[$k] = strtolower($v);
            }
        }
        $font = (string)($in['font'] ?? '');
        if (!in_array($font, ['', 'vazir', 'lalezar', 'amiri'], true)) {
            $font = '';
        }
        return [
            'name' => $name,
            'latin' => igsClip((string)($in['latin'] ?? ''), 80),
            'phone' => $phone,
            'website' => $web,
            'social' => $social,
            'tagline' => igsClip((string)($in['tagline'] ?? ''), 120),
            'colors' => $colors,
            'font' => $font,
        ];
    }

    function igsBrandRow(PDO $pdo, string $by, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_brands WHERE id = ? AND owner = ? LIMIT 1');
        $st->execute([$id, $by]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    /** مسیر لوگوی پروفایل؛ null اگر ثبت نشده یا فایل از بین رفته باشد */
    function igsBrandLogoPath(array $r): ?string
    {
        $rel = (string)($r['logo_rel'] ?? '');
        if (!preg_match('#^brand/bl-[a-f0-9]{16}\.(png|jpg|webp)$#', $rel)) {
            return null;
        }
        $p = IGS_EXPORT_DIR . '/' . $rel;
        return is_file($p) ? $p : null;
    }

    function igsBrandPublic(array $r): array
    {
        $d = json_decode((string)$r['data'], true);
        $d = is_array($d) ? $d : [];
        return [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'latin' => (string)($d['latin'] ?? ''),
            'phone' => (string)($d['phone'] ?? ''),
            'website' => (string)($d['website'] ?? ''),
            'social' => (string)($d['social'] ?? ''),
            'tagline' => (string)($d['tagline'] ?? ''),
            'colors' => is_array($d['colors'] ?? null) ? $d['colors'] : [],
            'font' => (string)($d['font'] ?? ''),
            'has_logo' => igsBrandLogoPath($r) !== null,
            'updated_at' => (string)$r['updated_at'],
        ];
    }

    function igsBrandList(PDO $pdo, string $by): array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_brands WHERE owner = ? ORDER BY id ASC LIMIT 20');
        $st->execute([$by]);
        return array_map('igsBrandPublic', $st->fetchAll(PDO::FETCH_ASSOC));
    }

    function igsBrandSave(PDO $pdo, string $by, array $in): array
    {
        $clean = igsBrandNormalize($in);
        $json = json_encode(array_diff_key($clean, ['name' => 1]), JSON_UNESCAPED_UNICODE);
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            if (!igsBrandRow($pdo, $by, $id)) {
                throw new RuntimeException('پروفایل پیدا نشد.');
            }
            $st = $pdo->prepare('UPDATE ig_studio_brands SET name = ?, data = ? WHERE id = ? AND owner = ?');
            $st->execute([$clean['name'], $json, $id, $by]);
        } else {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM ig_studio_brands WHERE owner = ?');
            $cnt->execute([$by]);
            if ((int)$cnt->fetchColumn() >= 20) {
                throw new RuntimeException('حداکثر ۲۰ پروفایل برند می‌توانید بسازید.');
            }
            $now = date('Y-m-d H:i:s');
            $st = $pdo->prepare('INSERT INTO ig_studio_brands (owner, name, data, logo_rel, created_at, updated_at) VALUES (?,?,?,?,?,?)');
            $st->execute([$by, $clean['name'], $json, '', $now, $now]);
            $id = (int)$pdo->lastInsertId();
        }
        return igsBrandPublic(igsBrandRow($pdo, $by, $id));
    }

    function igsBrandDelete(PDO $pdo, string $by, int $id): void
    {
        $r = igsBrandRow($pdo, $by, $id);
        if (!$r) {
            throw new RuntimeException('پروفایل پیدا نشد.');
        }
        $st = $pdo->prepare('DELETE FROM ig_studio_brands WHERE id = ? AND owner = ?');
        $st->execute([$id, $by]);
        $p = igsBrandLogoPath($r);
        if ($p !== null) {
            @unlink($p);
        }
    }

    function igsBrandLogoSave(PDO $pdo, string $by, int $id, array $file): array
    {
        $r = igsBrandRow($pdo, $by, $id);
        if (!$r) {
            throw new RuntimeException('پروفایل پیدا نشد.');
        }
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('فایل لوگو دریافت نشد.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('حجم لوگو باید کمتر از ۲ مگابایت باشد.');
        }
        $info = @getimagesize((string)$file['tmp_name']);
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        if (!$info || !isset($types[$info['mime'] ?? ''])) {
            throw new RuntimeException('فقط فایل PNG، JPG یا WEBP مجاز است.');
        }
        if ($info[0] < 64 || $info[1] < 64) {
            throw new RuntimeException('ابعاد لوگو خیلی کوچک است (حداقل ۶۴ پیکسل).');
        }
        $dir = IGS_EXPORT_DIR . '/brand';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('پوشه‌ی لوگو ساخته نشد.');
        }
        $rel = 'brand/bl-' . bin2hex(random_bytes(8)) . '.' . $types[$info['mime']];
        if (!@move_uploaded_file((string)$file['tmp_name'], IGS_EXPORT_DIR . '/' . $rel)) {
            throw new RuntimeException('ذخیره‌ی لوگو ناموفق بود.');
        }
        $old = igsBrandLogoPath($r);
        $st = $pdo->prepare('UPDATE ig_studio_brands SET logo_rel = ? WHERE id = ? AND owner = ?');
        $st->execute([$rel, $id, $by]);
        if ($old !== null && $old !== IGS_EXPORT_DIR . '/' . $rel) {
            @unlink($old);
        }
        return igsBrandPublic(igsBrandRow($pdo, $by, $id));
    }

    /** مسیر فایل لوگوی یک پروفایل فقط برای مالک آن؛ null در غیر این صورت */
    function igsBrandLogoFile(PDO $pdo, string $by, int $id): ?string
    {
        $r = igsBrandRow($pdo, $by, $id);
        return $r ? igsBrandLogoPath($r) : null;
    }

    /* ---------- فونت‌های اختصاصی (فقط ادمین) ---------- */

    function igsFontRegistry(PDO $pdo): array
    {
        if (!function_exists('dbSettingGet')) { return []; }
        $raw = (string)dbSettingGet($pdo, 'ig_studio', 'fonts_json', '[]');
        $list = json_decode($raw, true);
        if (!is_array($list)) { return []; }
        $out = [];
        foreach ($list as $f) {
            if (!is_array($f) || !preg_match('/^font-[a-f0-9]{16}$/', (string)($f['id'] ?? ''))) { continue; }
            $out[] = [
                'id' => (string)$f['id'],
                'family' => (string)($f['family'] ?? ''),
                'format' => (string)($f['format'] ?? ''),
                'embedding' => (string)($f['embedding'] ?? ''),
                'licence_type' => (string)($f['licence_type'] ?? ''),
                'licence_note' => (string)($f['licence_note'] ?? ''),
                'size' => (int)($f['size'] ?? 0),
                'uploaded_by' => (string)($f['uploaded_by'] ?? ''),
                'uploaded_at' => (string)($f['uploaded_at'] ?? ''),
            ];
        }
        return $out;
    }

    function igsFontSaveRegistry(PDO $pdo, array $list): void
    {
        dbSettingSet($pdo, 'ig_studio', 'fonts_json', json_encode(array_values($list), JSON_UNESCAPED_UNICODE), 'string', null);
    }

    function igsFontSave(PDO $pdo, array $file, array $meta, string $by): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('فایل فونت دریافت نشد.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > IGS_FONT_MAX_BYTES) {
            throw new RuntimeException('حجم فونت باید کمتر از ۵ مگابایت باشد.');
        }
        $list = igsFontRegistry($pdo);
        if (count($list) >= IGS_FONT_MAX_COUNT) {
            throw new RuntimeException('حداکثر ' . IGS_FONT_MAX_COUNT . ' فونت اختصاصی می‌توان داشت. یکی را حذف کنید.');
        }
        $lic = igsFontLicenceErrors($meta);
        if ($lic) { throw new RuntimeException($lic[0]); }
        $bytes = (string)@file_get_contents((string)$file['tmp_name']);
        $info = igsFontInspect($bytes, (string)($file['name'] ?? ''));
        if (!$info['ok']) { throw new RuntimeException($info['errors'][0] ?? 'فونت پذیرفته نشد.'); }

        $dir = IGS_EXPORT_DIR . '/fonts';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { throw new RuntimeException('پوشه‌ی فونت ساخته نشد.'); }
        // جلوگیری از دسترسی مستقیم وب؛ فونت‌ها فقط از مسیر ادمین سرو می‌شوند
        if (!is_file($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }

        $id = 'font-' . bin2hex(random_bytes(8));
        $rel = 'fonts/' . $id . '.font';
        if (!@move_uploaded_file((string)$file['tmp_name'], IGS_EXPORT_DIR . '/' . $rel)) {
            throw new RuntimeException('ذخیره‌ی فونت ناموفق بود.');
        }
        $entry = [
            'id' => $id,
            'family' => $info['family'],
            'format' => $info['format'],
            'embedding' => $info['embedding'],
            'licence_type' => (string)$meta['licence_type'],
            'licence_note' => trim((string)($meta['licence_note'] ?? '')),
            'size' => strlen($bytes),
            'uploaded_by' => $by,
            'uploaded_at' => gmdate('c'),
            'file' => $rel,
        ];
        $list[] = $entry;
        igsFontSaveRegistry($pdo, $list);
        unset($entry['file']);
        $entry['warnings'] = $info['warnings'];
        return $entry;
    }

    function igsFontDelete(PDO $pdo, string $id): bool
    {
        if (!preg_match('/^font-[a-f0-9]{16}$/', $id)) { return false; }
        $list = igsFontRegistry($pdo);
        $keep = [];
        $found = false;
        foreach ($list as $f) {
            if ($f['id'] === $id) { $found = true; continue; }
            $keep[] = $f;
        }
        if (!$found) { return false; }
        igsFontSaveRegistry($pdo, $keep);
        $path = IGS_EXPORT_DIR . '/fonts/' . $id . '.font';
        if (is_file($path)) { @unlink($path); }
        return true;
    }

    /** مسیر فایل فونت برای سرو توسط ادمین؛ '' اگر ثبت نشده باشد. */
    function igsFontFilePath(PDO $pdo, string $id): string
    {
        if (!preg_match('/^font-[a-f0-9]{16}$/', $id)) { return ''; }
        foreach (igsFontRegistry($pdo) as $f) {
            if ($f['id'] === $id) {
                $path = IGS_EXPORT_DIR . '/fonts/' . $id . '.font';
                return is_file($path) ? $path : '';
            }
        }
        return '';
    }

    function igsBrand(PDO $pdo): array
    {
        $name = 'ملکینو';
        $latin = 'Melkino';
        $phone = '';
        $logo = '';
        try {
            if (!function_exists('mkBrandRead') && is_file(__DIR__ . '/brand-site-lib.php')) {
                require_once __DIR__ . '/brand-site-lib.php';
            }
            if (function_exists('mkBrandRead')) {
                $b = mkBrandRead();
                $s = is_array($b['settings'] ?? null) ? $b['settings'] : [];
                if (trim((string)($s['brand_name'] ?? '')) !== '') {
                    $name = trim((string)$s['brand_name']);
                }
                if (trim((string)($s['brand_latin'] ?? '')) !== '') {
                    $latin = trim((string)$s['brand_latin']);
                }
                $p = trim((string)($s['phone'] ?? ''));
                if ($p !== '' && $p !== IGS_PLACEHOLDER_PHONE) {
                    $phone = igsFa($p);
                }
            }
        } catch (Throwable $e) {
            // تنظیمات برند خوانده نشد؛ مقدار پیش‌فرض نام برند بماند
        }
        if (function_exists('melkinoLogoPublicUrl')) {
            $logo = (string)melkinoLogoPublicUrl('site');
        }
        // لوگوی اختصاصی استودیو (اگر ثبت شده) جای لوگوی سایت را فقط در استودیو می‌گیرد
        $source = $logo !== '' ? 'site' : '';
        $studioRel = igsLogoRel($pdo);
        $logoMissing = false;
        if ($studioRel !== '' && is_file(IGS_EXPORT_DIR . '/' . $studioRel)) {
            $logo = igsBaseUrl() . 'ig-studio-api.php?action=logo_file&v=' . substr(md5($studioRel), 0, 8);
            $source = 'studio';
        } elseif ($studioRel !== '') {
            // رکورد لوگو هست ولی فایل روی سرور نیست (مثلاً پوشه‌ی storage هنگام جایگزینی پاک شده):
            // آدرس خراب به مرورگر داده نمی‌شود تا خروجی با خطای تصویر متوقف نشود
            $logoMissing = true;
        }
        return ['name' => $name, 'latin' => $latin, 'phone' => $phone, 'logo_url' => $logo, 'logo_source' => $source, 'logo_missing' => $logoMissing];
    }

    function igsProfileDefaults(): array
    {
        return [
            'font' => '',
            'theme' => '',
            'logo' => true,
            'show_phone' => true,
            'cta' => 'برای مشاوره و بازدید پیام بدهید',
            'favorites' => [],
        ];
    }

    function igsProfileGet(PDO $pdo): array
    {
        $out = igsProfileDefaults();
        if (function_exists('dbSettingGet')) {
            // dbSettingGet برای نوع json خودش آرایه برمی‌گرداند
            $dec = dbSettingGet($pdo, 'ig_studio', 'profile', null);
            if (is_array($dec)) {
                $out['font'] = in_array(($dec['font'] ?? ''), ['', 'vazir', 'lalezar', 'amiri'], true) ? (string)$dec['font'] : '';
                $out['theme'] = preg_match('/^[a-z0-9-]{0,32}$/', (string)($dec['theme'] ?? '')) ? (string)$dec['theme'] : '';
                $out['logo'] = array_key_exists('logo', $dec) ? (bool)$dec['logo'] : true;
                $out['show_phone'] = array_key_exists('show_phone', $dec) ? (bool)$dec['show_phone'] : true;
                $out['cta'] = isset($dec['cta']) ? igsClip((string)$dec['cta'], 120) : $out['cta'];
                $fav = [];
                foreach ((array)($dec['favorites'] ?? []) as $f) {
                    if (is_string($f) && preg_match('/^[a-zA-Z0-9-]{1,64}$/', $f)) {
                        $fav[] = $f;
                    }
                }
                $out['favorites'] = array_values(array_unique(array_slice($fav, 0, 60)));
            }
        }
        return $out;
    }

    function igsProfileSave(PDO $pdo, array $in): array
    {
        $cur = igsProfileGet($pdo);
        $merged = array_merge($cur, array_intersect_key($in, $cur));
        $clean = [
            'font' => in_array($merged['font'], ['', 'vazir', 'lalezar', 'amiri'], true) ? (string)$merged['font'] : '',
            'theme' => preg_match('/^[a-z0-9-]{0,32}$/', (string)$merged['theme']) ? (string)$merged['theme'] : '',
            'logo' => (bool)$merged['logo'],
            'show_phone' => (bool)$merged['show_phone'],
            'cta' => igsClip((string)$merged['cta'], 120),
            'favorites' => array_values(array_unique(array_filter(array_map(
                static fn($f) => is_string($f) && preg_match('/^[a-zA-Z0-9-]{1,64}$/', $f) ? $f : null,
                (array)$merged['favorites']
            )))),
        ];
        $clean['favorites'] = array_slice($clean['favorites'], 0, 60);
        if (function_exists('dbSettingSet')) {
            dbSettingSet($pdo, 'ig_studio', 'profile', $clean, 'json', null);
        }
        return $clean;
    }

    /* ---------- وضعیت پروژه: فقط کلیدهای مجاز، با محدودیت نوع و اندازه ---------- */

    function igsColorOk($v): bool
    {
        return is_string($v) && strlen($v) <= 80 && (
            preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ||
            preg_match('/^rgba?\(\s*[\d.\s,%]{1,40}\)$/', $v) ||
            preg_match('/^\$[a-z0-9]{1,20}$/', $v)
        );
    }

    function igsNum($v, float $min, float $max): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float)$v;
        if (!is_finite($f)) {
            return null;
        }
        return max($min, min($max, $f));
    }

    /** ویژگی‌های مجاز یک المان (x,y,w,h, رنگ، فونت، ...) */
    function igsCleanProps($p): array
    {
        if (!is_array($p)) {
            return [];
        }
        $out = [];
        foreach (['x' => [-2000, 4000], 'y' => [-2000, 4000], 'w' => [0, 4000], 'h' => [0, 4000], 'rot' => [-180, 180],
                  'size' => [6, 400], 'lineH' => [0.8, 2.5], 'maxLines' => [1, 12], 'opacity' => [0, 1],
                  'radius' => [0, 540], 'strokeW' => [0, 40], 'zoom' => [1, 3], 'fx' => [0, 1], 'fy' => [0, 1]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $p)) {
                $n = igsNum($p[$k], (float)$lo, (float)$hi);
                if ($n !== null) {
                    $out[$k] = in_array($k, ['maxLines'], true) ? (int)round($n) : round($n, 3);
                }
            }
        }
        foreach (['pad' => [0, 200], 'minSize' => [6, 400], 'chipH' => [30, 200], 'max' => [1, 12], 'cols' => [0, 6]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $p)) {
                $n = igsNum($p[$k], (float)$lo, (float)$hi);
                if ($n !== null) {
                    $out[$k] = (int)round($n);
                }
            }
        }
        if (isset($p['valign']) && in_array($p['valign'], ['top', 'middle', 'bottom'], true)) {
            $out['valign'] = $p['valign'];
        }
        if (isset($p['layout']) && in_array($p['layout'], ['list', 'grid'], true)) {
            $out['layout'] = $p['layout'];
        }
        foreach (['hidden', 'locked', 'shadow'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = (bool)$p[$k];
            }
        }
        if (isset($p['weight']) && in_array((int)$p['weight'], [300, 400, 500, 700, 900], true)) {
            $out['weight'] = (int)$p['weight'];
        }
        if (isset($p['font']) && in_array($p['font'], ['vazir', 'lalezar', 'amiri'], true)) {
            $out['font'] = $p['font'];
        }
        if (isset($p['align']) && in_array($p['align'], ['right', 'center', 'left'], true)) {
            $out['align'] = $p['align'];
        }
        if (isset($p['shape']) && in_array($p['shape'], ['rect', 'round', 'arch', 'circle', 'diag'], true)) {
            $out['shape'] = $p['shape'];
        }
        // نوع المان: همه‌ی نوع‌هایی که ویرایشگر می‌سازد (قبلاً specs/chips/logo/field/photo حذف می‌شد)
        if (isset($p['type']) && in_array($p['type'], ['text', 'rect', 'line', 'specs', 'chips', 'logo', 'field', 'photo', 'qr', 'sticker'], true)) {
            $out['type'] = $p['type'];
        }
        // فیلدهای ساختاری: کلیدهای جدول مشخصات/امکانات، کلید فیلد آگهی و گزینه‌های نمایش
        if (isset($p['keys']) && is_array($p['keys'])) {
            $keys = [];
            foreach (array_slice($p['keys'], 0, 80) as $kk) {
                if (is_string($kk) && preg_match('/^[A-Za-z0-9_:.\-]{1,64}$/', $kk)) {
                    $keys[] = $kk;
                }
            }
            $out['keys'] = array_values(array_unique($keys));
        }
        if (isset($p['key']) && is_string($p['key']) && preg_match('/^[A-Za-z0-9_:.\-]{1,64}$/', $p['key'])) {
            $out['key'] = $p['key'];
        }
        foreach (['icons', 'showLabel'] as $k) {
            if (array_key_exists($k, $p)) {
                $out[$k] = (bool)$p[$k];
            }
        }
        if (isset($p['kind']) && in_array($p['kind'], ['star', 'burst', 'heart', 'badge', 'ribbon', 'arrow', 'bubble-l', 'bubble-r', 'bubble-box'], true)) {
            $out['kind'] = $p['kind'];
        }
        // عکس پس‌زمینه‌ی تمام‌قد: bg = true (نه رنگ)
        if (isset($p['bg']) && $p['bg'] === true) {
            $out['bg'] = true;
        }
        foreach (['color', 'bg', 'stroke', 'fill', 'lbl'] as $k) {
            if ($k === 'bg' && isset($out['bg']) && $out['bg'] === true) {
                continue;
            }
            if (isset($p[$k]) && ($p[$k] === 'none' || igsColorOk($p[$k]))) {
                $out[$k] = $p[$k];
            }
        }
        foreach (['text', 'label'] as $k) {
            if (isset($p[$k]) && is_string($p[$k])) {
                $out[$k] = igsClip($p[$k], 400);
            }
        }
        return $out;
    }

    /** متن آزاد نسخه ۲: بدون فشرده‌سازی فاصله‌ها (خط جدید حفظ می‌شود) و بدون تگ HTML */
    function igsV2Text($s): string
    {
        $s = str_replace("\r", '', strip_tags((string)$s));
        return mb_strlen($s, 'UTF-8') > 400 ? mb_substr($s, 0, 400, 'UTF-8') : $s;
    }

    /** مقدار آزاد (اسکالر یا آرایه‌ی تودرتو با عمق محدود) برای content/style/effects */
    function igsV2Value($v, int $depth)
    {
        if (is_array($v)) {
            if ($depth <= 0) {
                return null;
            }
            $out = [];
            $n = 0;
            foreach ($v as $k => $x) {
                if (++$n > 60) {
                    break;
                }
                if (is_string($k) && !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k)) {
                    continue;
                }
                $out[$k] = igsV2Value($x, $depth - 1);
            }
            return $out;
        }
        if (is_string($v)) {
            return igsV2Text($v);
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if (is_bool($v) || $v === null) {
            return $v;
        }
        return null;
    }

    /** یک المان نسخه ۲ را تمیز می‌کند؛ شناسه‌ی نامعتبر یا تکراری یا نوع ناشناخته حذف می‌شود */
    function igsSanitizeElementV2($e, array &$seen): ?array
    {
        if (!is_array($e)) {
            return null;
        }
        $id = (string)($e['id'] ?? '');
        $type = (string)($e['type'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) || isset($seen[$id]) || !in_array($type, IGS_V2_TYPES, true)) {
            return null;
        }
        $seen[$id] = true;

        $src = is_array($e['source'] ?? null) ? $e['source'] : [];
        $origin = ($src['origin'] ?? '') === 'template' ? 'template' : 'extra';
        $source = ['origin' => $origin];
        if ($origin === 'template') {
            $tid = (string)($src['templateElId'] ?? '');
            $source['templateElId'] = preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $tid) ? $tid : $id;
        } else {
            $source['extraIndex'] = max(0, min(999, (int)($src['extraIndex'] ?? 0)));
        }
        if (isset($src['legacyId']) && is_int($src['legacyId'])) {
            $source['legacyId'] = $src['legacyId'];
        } elseif (isset($src['legacyId']) && is_string($src['legacyId'])) {
            $source['legacyId'] = mb_substr(strip_tags($src['legacyId']), 0, 64, 'UTF-8');
        }

        $group = $e['groupId'] ?? null;
        $groupId = (is_string($group) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $group)) ? $group : null;

        $content = is_array($e['content'] ?? null) ? igsV2Value($e['content'], 6) : [];
        $style = is_array($e['style'] ?? null) ? igsV2Value($e['style'], 6) : [];
        $effects = is_array($e['effects'] ?? null) ? igsV2Value($e['effects'], 6) : [];

        $geo = function ($v, float $min, float $max, float $def): float {
            $n = igsNum($v, $min, $max);
            return $n === null ? $def : $n;
        };
        return [
            'id' => $id,
            'type' => $type,
            'name' => igsClip((string)($e['name'] ?? ''), 120),
            'x' => $geo($e['x'] ?? null, -3000, 6000, 0),
            'y' => $geo($e['y'] ?? null, -3000, 6000, 0),
            'w' => $geo($e['w'] ?? null, 0, 6000, 0),
            'h' => $geo($e['h'] ?? null, 0, 6000, 0),
            'rot' => $geo($e['rot'] ?? null, -360, 360, 0),
            'opacity' => igsNum($e['opacity'] ?? 1, 0, 1) ?? 1.0,
            'visible' => array_key_exists('visible', $e) ? (bool)$e['visible'] : true,
            'locked' => !empty($e['locked']),
            'z' => is_int($e['z'] ?? null) ? $e['z'] : null,
            'groupId' => $groupId,
            'aspectRatioLocked' => !empty($e['aspectRatioLocked']),
            'effects' => $effects,
            'source' => $source,
            'content' => $content,
            'style' => $style,
        ];
    }

    /** وضعیت نسخه ۲: elements/groups/metadata با اعتبارسنجی و سقف‌های امن */
    /** برچسب‌های آماده: فقط کلیدهای فهرست سفید (همان LABEL_DEFS در ig-studio-render.js)، یکتا، حداکثر ۴ مورد */
    function igsLabelKeys(): array
    {
        return ['key-ready', 'no-mansoubat', 'full-amenities', 'good-price', 'discount', 'special-file', 'vip', 'exclusive', 'urgent', 'new-build', 'pre-sale'];
    }
    function igsSanitizeLabels($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 20) as $k) {
            if (is_string($k) && in_array($k, igsLabelKeys(), true) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return array_slice($out, 0, 4);
    }

    function igsSanitizeStateV2(array $raw, bool $allowSlides = true): array
    {
        $out = [
            'v' => IGS_SCHEMA_V2,
            'canvas' => ['width' => IGS_CANVAS_W, 'height' => IGS_CANVAS_H],
            'elements' => [],
            'groups' => [],
            'metadata' => [],
        ];

        $seen = [];
        $rawEls = is_array($raw['elements'] ?? null) ? array_slice($raw['elements'], 0, IGS_V2_MAX_ELEMENTS) : [];
        $zs = [];
        foreach ($rawEls as $e) {
            $c = igsSanitizeElementV2($e, $seen);
            if ($c !== null) {
                $zs[] = $c['z'];
                unset($c['z']);
                $out['elements'][] = $c;
            }
        }
        // ترتیب لایه: z معتبر و یکتا نگه داشته می‌شود؛ مقدار نامعتبر یا تکراری به بعدترین عدد آزاد داده می‌شود
        $used = [];
        $pending = [];
        foreach ($zs as $i => $z) {
            if ($z !== null && $z >= 0 && $z <= 100000 && !isset($used[$z])) {
                $used[$z] = true;
                $out['elements'][$i]['z'] = $z;
            } else {
                $pending[] = $i;
            }
        }
        $next = 0;
        foreach ($pending as $i) {
            while (isset($used[$next])) {
                $next++;
            }
            $used[$next] = true;
            $out['elements'][$i]['z'] = $next;
        }

        $ids = [];
        foreach ($out['elements'] as $el) {
            $ids[$el['id']] = true;
        }
        foreach (array_slice(is_array($raw['groups'] ?? null) ? $raw['groups'] : [], 0, IGS_V2_MAX_GROUPS) as $g) {
            if (!is_array($g)) {
                continue;
            }
            $gid = (string)($g['id'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $gid)) {
                continue;
            }
            $children = [];
            foreach (is_array($g['childIds'] ?? null) ? array_slice($g['childIds'], 0, 300) : [] as $cid) {
                $cid = (string)$cid;
                if (isset($ids[$cid])) {
                    $children[] = $cid;
                }
            }
            $out['groups'][] = ['id' => $gid, 'name' => igsClip((string)($g['name'] ?? ''), 120), 'childIds' => $children];
        }

        $m = is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
        $design = is_array($m['design'] ?? null) ? $m['design'] : [];
        $d = [];
        if (array_key_exists('template', $design)) {
            $t = (string)$design['template'];
            $d['template'] = preg_match('/^[a-zA-Z0-9-]{1,64}$/', $t) ? $t : '';
        }
        if (array_key_exists('theme', $design)) {
            $t = (string)$design['theme'];
            $d['theme'] = preg_match('/^[a-z0-9-]{0,32}$/', $t) ? $t : '';
        }
        if (array_key_exists('font', $design)) {
            $d['font'] = in_array($design['font'], IGS_V2_FONTS, true) ? (string)$design['font'] : '';
        }
        if (array_key_exists('logo', $design)) {
            $d['logo'] = $design['logo'] === null ? null : (bool)$design['logo'];
        }
        if (array_key_exists('cta', $design)) {
            $d['cta'] = igsClip((string)$design['cta'], 120);
        }
        if (array_key_exists('hidePrice', $design)) {
            $d['hidePrice'] = !empty($design['hidePrice']);
        }
        if (array_key_exists('labels', $design)) {
            $d['labels'] = igsSanitizeLabels($design['labels']);
        }
        $meta = ['design' => $d, 'migratedFrom' => max(1, min(9, (int)($m['migratedFrom'] ?? 1)))];

        $orphans = [];
        $oe = is_array($m['orphans'] ?? null) ? $m['orphans'] : [];
        foreach (['els', 'photos'] as $kind) {
            foreach (array_slice(is_array($oe[$kind] ?? null) ? $oe[$kind] : [], 0, 200, true) as $k => $v) {
                $k = (string)$k;
                if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k) && is_array($v)) {
                    $orphans[$kind][$k] = igsV2Value($v, 6);
                }
            }
        }
        foreach (array_slice(is_array($oe['texts'] ?? null) ? $oe['texts'] : [], 0, 200, true) as $k => $v) {
            $k = (string)$k;
            if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k) && is_string($v)) {
                $orphans['texts'][$k] = igsV2Text($v);
            }
        }
        if ($orphans) {
            $meta['orphans'] = $orphans;
        }
        $unknown = [];
        foreach (array_slice($m, 0, 30, true) as $k => $v) {
            if (!in_array($k, ['design', 'migratedFrom', 'orphans', 'unknown', 'slides'], true) && is_string($k)
                && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k)) {
                $unknown[$k] = igsV2Value($v, 6);
            }
        }
        if (is_array($m['unknown'] ?? null)) {
            foreach (array_slice($m['unknown'], 0, 30, true) as $k => $v) {
                if (is_string($k) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k)) {
                    $unknown[$k] = igsV2Value($v, 6);
                }
            }
        }
        if ($unknown) {
            $meta['unknown'] = $unknown;
        }
        // چند اسلایدی: اسلایدهای ۲ به بعد (هر کدام یک وضعیت نسخه ۲ کامل). اسلاید داخل اسلاید مجاز نیست. حداکثر ۹ اسلاید اضافه.
        if ($allowSlides && is_array($m['slides'] ?? null)) {
            $slides = [];
            foreach (array_slice($m['slides'], 0, 9) as $s) {
                if (is_array($s) && (int)($s['v'] ?? 0) === IGS_SCHEMA_V2) {
                    $slides[] = igsSanitizeStateV2($s, false);
                }
            }
            if ($slides) {
                $meta['slides'] = $slides;
            }
        }
        $out['metadata'] = $meta;

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        // هر اسلاید سقف خودش را دارد؛ کل یک طرح چنداسلایدی تا ۴ برابر این سقف
        $limit = !empty($meta['slides']) ? IGS_MAX_STATE_BYTES * 4 : IGS_MAX_STATE_BYTES;
        if ($json === false || strlen($json) > $limit) {
            throw new RuntimeException('حجم وضعیت پروژه بیش از حد مجاز است.');
        }
        return $out;
    }

    function igsSanitizeState($raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }
        if ((int)($raw['v'] ?? 0) === IGS_SCHEMA_V2) {
            return igsSanitizeStateV2($raw);
        }
        $out = ['v' => 1];
        $out['template'] = preg_match('/^[a-zA-Z0-9-]{1,64}$/', (string)($raw['template'] ?? '')) ? (string)$raw['template'] : '';
        $out['theme'] = preg_match('/^[a-z0-9-]{0,32}$/', (string)($raw['theme'] ?? '')) ? (string)($raw['theme'] ?? '') : '';
        $out['font'] = in_array($raw['font'] ?? '', ['', 'vazir', 'lalezar', 'amiri'], true) ? (string)($raw['font'] ?? '') : '';
        // فرمت خروجی: story (پیش‌فرض) یا post (پست ۴:۵)
        $out['format'] = in_array($raw['format'] ?? '', ['story', 'post', 'square', 'poster', 'wide'], true) ? (string)$raw['format'] : 'story';
        // logo: null = «طبق تنظیمات برند»، bool = انتخاب صریح کاربر
        $out['logo'] = (array_key_exists('logo', $raw) && $raw['logo'] !== null) ? (bool)$raw['logo'] : null;
        $out['cta'] = igsClip((string)($raw['cta'] ?? ''), 120);
        $out['hidePrice'] = !empty($raw['hidePrice']);
        // برند شخصی این پروژه: شناسه‌ی پروفایل (0 = بدون برند)
        $out['brand'] = max(0, min(2147483647, (int)($raw['brand'] ?? 0)));

        $out['texts'] = [];
        if (is_array($raw['texts'] ?? null)) {
            foreach (array_slice($raw['texts'], 0, 40, true) as $k => $v) {
                if (is_string($k) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k) && is_string($v)) {
                    $out['texts'][$k] = igsClip($v, 400);
                }
            }
        }

        $out['els'] = [];
        if (is_array($raw['els'] ?? null)) {
            foreach (array_slice($raw['els'], 0, 80, true) as $k => $p) {
                if (is_string($k) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k)) {
                    $c = igsCleanProps($p);
                    if ($c) {
                        $out['els'][$k] = $c;
                    }
                }
            }
        }

        $out['photos'] = [];
        if (is_array($raw['photos'] ?? null)) {
            foreach (array_slice($raw['photos'], 0, 40, true) as $k => $p) {
                if (!is_string($k) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $k) || !is_array($p)) {
                    continue;
                }
                $out['photos'][$k] = [
                    'i' => (int)max(0, min(9, (int)($p['i'] ?? 0))),
                    'z' => (float)(igsNum($p['z'] ?? 1, 1, 3) ?? 1),
                    'fx' => (float)(igsNum($p['fx'] ?? 0.5, 0, 1) ?? 0.5),
                    'fy' => (float)(igsNum($p['fy'] ?? 0.5, 0, 1) ?? 0.5),
                ];
            }
        }

        $out['extra'] = [];
        if (is_array($raw['extra'] ?? null)) {
            foreach (array_slice($raw['extra'], 0, 30) as $e) {
                if (!is_array($e) || !preg_match('/^x[a-z0-9]{1,12}$/', (string)($e['id'] ?? ''))) {
                    continue;
                }
                $c = igsCleanProps($e);
                $c['id'] = (string)$e['id'];
                $c['type'] = $c['type'] ?? 'text';
                $out['extra'][] = $c;
            }
        }

        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > IGS_MAX_STATE_BYTES) {
            throw new RuntimeException('حجم وضعیت پروژه بیش از حد مجاز است.');
        }
        return $out;
    }

    function igsProjectRow(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_projects WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    function igsProjectPublic(array $r, bool $withState): array
    {
        $o = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'ad_id' => (string)$r['ad_id'],
            'template_id' => (string)$r['template_id'],
            'favorite' => (bool)$r['is_favorite'],
            'updated_at' => (string)$r['updated_at'],
            'created_at' => (string)$r['created_at'],
        ];
        if ($withState) {
            $dec = json_decode((string)$r['state_json'], true);
            $o['state'] = is_array($dec) ? $dec : igsSanitizeState([]);
        }
        return $o;
    }

    /* ---------- قالب‌های ذخیره‌شده‌ی مدیر ---------- */

    function igsMyTemplatePublic(array $r): array
    {
        $state = json_decode((string)($r['state_json'] ?? ''), true);
        return [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'template_id' => (string)$r['template_id'],
            'created_at' => (string)$r['created_at'],
            'state' => is_array($state) ? $state : igsSanitizeState([]),
        ];
    }

    function igsMyTemplatesList(PDO $pdo, string $by): array
    {
        $st = $pdo->prepare('SELECT id, name, template_id, state_json, created_at FROM ig_studio_templates WHERE created_by = ? ORDER BY created_at DESC, id DESC LIMIT 60');
        $st->execute([mb_substr($by, 0, 120, 'UTF-8')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return array_map('igsMyTemplatePublic', $rows);
    }

    function igsMyTemplateSave(PDO $pdo, array $in, string $by): array
    {
        $name = igsClip((string)($in['name'] ?? ''), 120);
        if ($name === '') {
            throw new InvalidArgumentException('نام قالب را وارد کنید.');
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM ig_studio_templates WHERE created_by = ?');
        $cnt->execute([mb_substr($by, 0, 120, 'UTF-8')]);
        if ((int)$cnt->fetchColumn() >= 60) {
            throw new InvalidArgumentException('تعداد قالب‌های ذخیره‌شده به حداکثر ۶۰ رسیده است. یکی را حذف کنید.');
        }
        $state = igsSanitizeState($in['state'] ?? []);
        if (empty($state['template'])) {
            throw new InvalidArgumentException('قالب پایه‌ی این طراحی نامعتبر است.');
        }
        // قالب عمومی است: عکس و متن‌های مخصوص آگهی حذف می‌شوند؛ متن المان‌های اضافه‌شده می‌ماند
        $state['photos'] = [];
        $extraIds = array_column($state['extra'] ?? [], 'id');
        $state['texts'] = array_intersect_key($state['texts'] ?? [], array_flip($extraIds));
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > IGS_MAX_STATE_BYTES) {
            throw new InvalidArgumentException('حجم این طراحی بیش از حد مجاز است.');
        }
        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare('INSERT INTO ig_studio_templates (name, template_id, state_json, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?)');
        $st->execute([$name, $state['template'], $json, mb_substr($by, 0, 120, 'UTF-8'), $now, $now]);
        return igsMyTemplatePublic([
            'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'template_id' => $state['template'],
            'state_json' => $json, 'created_at' => $now,
        ]);
    }

    function igsMyTemplateDelete(PDO $pdo, int $id, string $by): bool
    {
        $st = $pdo->prepare('DELETE FROM ig_studio_templates WHERE id = ? AND created_by = ?');
        $st->execute([$id, mb_substr($by, 0, 120, 'UTF-8')]);
        return $st->rowCount() > 0;
    }

    function igsProjectSave(PDO $pdo, array $in, ?int $id, string $by): array
    {
        $title = igsClip((string)($in['title'] ?? ''), 200);
        if ($title === '') {
            $title = 'بدون عنوان';
        }
        $adId = substr(trim((string)($in['ad_id'] ?? '')), 0, 64);
        $tpl = (string)($in['template_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{1,64}$/', $tpl)) {
            throw new InvalidArgumentException('قالب نامعتبر است.');
        }
        $state = igsSanitizeState($in['state'] ?? []);
        $isV2 = ($state['v'] ?? 1) === IGS_SCHEMA_V2;
        if ($isV2) {
            if (empty($state['metadata']['design']['template'])) {
                $state['metadata']['design']['template'] = $tpl;
            }
        } elseif ($state['template'] === '') {
            $state['template'] = $tpl;
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        if (strlen((string)$json) > IGS_MAX_STATE_BYTES) {
            throw new InvalidArgumentException('حجم این پروژه بیش از حد مجاز است.');
        }
        // زمان‌ها با منطقهٔ زمانی سایت (Asia/Tehran) ذخیره می‌شوند، نه با ساعت سرور دیتابیس
        $now = date('Y-m-d H:i:s');

        if ($id) {
            $cur = igsProjectRow($pdo, $id);
            if (!$cur) {
                throw new RuntimeException('پروژه پیدا نشد.');
            }
            $curDec = json_decode((string)$cur['state_json'], true);
            $curV = is_array($curDec) ? (int)($curDec['v'] ?? 1) : 1;
            if ($curV !== (int)($state['v'] ?? 1)) {
                // نسخه‌ی پروژه فقط رو به جلو می‌رود؛ ذخیره‌ی نسخه‌ی قدیمی از صفحه‌ی باز‌مانده نباید داده‌ی جدید را پاک کند
                if ($curV > (int)($state['v'] ?? 1)) {
                    throw new InvalidArgumentException('این پروژه در نسخه‌ی جدیدتری ذخیره شده است؛ صفحه را تازه‌سازی کنید.');
                }
                // نسخه‌ی اصلی پیش از مهاجرت، بدون تغییر، نگه داشته می‌شود
                $bk = $pdo->prepare('INSERT INTO ig_studio_project_backups (project_id, schema_version, state_json, created_by, created_at) VALUES (?,?,?,?,?)');
                $bk->execute([$id, $curV, (string)$cur['state_json'], mb_substr($by, 0, 120, 'UTF-8'), $now]);
            }
            if ((string)$cur['state_json'] !== (string)$json) {
                igsVersionSnapshot($pdo, (int)$id, (string)$cur['title'], (string)$cur['state_json'], $by, $now);
            }
            $st = $pdo->prepare('UPDATE ig_studio_projects SET title = ?, ad_id = ?, template_id = ?, state_json = ?, updated_at = ? WHERE id = ?');
            $st->execute([$title, $adId, $tpl, $json, $now, $id]);
        } else {
            $st = $pdo->prepare('INSERT INTO ig_studio_projects (title, ad_id, template_id, state_json, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$title, $adId, $tpl, $json, mb_substr($by, 0, 120, 'UTF-8'), $now, $now]);
            $id = (int)$pdo->lastInsertId();
        }
        return igsProjectPublic(igsProjectRow($pdo, $id), true);
    }

    function igsProjectsList(PDO $pdo, string $q, bool $favOnly, string $by): array
    {
        $where = 'created_by = ?';
        $args = [mb_substr($by, 0, 120, 'UTF-8')];
        if ($q !== '') {
            $where .= ' AND (title LIKE ? OR ad_id LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $args = [$args[0], $like, $like];
        }
        if ($favOnly) {
            $where .= ' AND is_favorite = 1';
        }
        $st = $pdo->prepare("SELECT * FROM ig_studio_projects WHERE $where ORDER BY updated_at DESC, id DESC LIMIT 200");
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = igsProjectPublic($r, false);
        }
        return $out;
    }

    /* ---------- مالکیت پروژه (هر ادمین فقط پروژه‌های خودش را می‌بیند) ---------- */

    function igsProjectOwned(PDO $pdo, int $id, string $by): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_projects WHERE id = ? AND created_by = ? LIMIT 1');
        $st->execute([$id, mb_substr($by, 0, 120, 'UTF-8')]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /* ---------- نسخه‌های پروژه: هر ۱۲۰ ثانیه حداکثر یک نسخه‌ی قبلی، و حداکثر ۳۰ نسخه برای هر پروژه ---------- */

    function igsVersionSnapshot(PDO $pdo, int $projectId, string $title, string $stateJson, string $by, string $now): void
    {
        $last = $pdo->prepare('SELECT created_at FROM ig_studio_project_versions WHERE project_id = ? ORDER BY id DESC LIMIT 1');
        $last->execute([$projectId]);
        $lastAt = $last->fetchColumn();
        if ($lastAt !== false && (strtotime($now) - strtotime((string)$lastAt)) < 120) {
            return;
        }
        $ins = $pdo->prepare('INSERT INTO ig_studio_project_versions (project_id, title, state_json, created_by, created_at) VALUES (?,?,?,?,?)');
        $ins->execute([$projectId, mb_substr($title, 0, 200, 'UTF-8'), $stateJson, mb_substr($by, 0, 120, 'UTF-8'), $now]);
        $prune = $pdo->prepare('DELETE FROM ig_studio_project_versions WHERE project_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM ig_studio_project_versions WHERE project_id = ? ORDER BY id DESC LIMIT 30) AS keep_rows)');
        $prune->execute([$projectId, $projectId]);
    }

    function igsVersionsList(PDO $pdo, int $projectId): array
    {
        $st = $pdo->prepare('SELECT id, title, created_by, created_at FROM ig_studio_project_versions WHERE project_id = ? ORDER BY id DESC LIMIT 30');
        $st->execute([$projectId]);
        return array_map(function ($r) {
            return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'created_at' => (string)$r['created_at']];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** بازیابی: وضعیت فعلی پیش از بازیابی خودش نسخه می‌شود (تا بازیابی هم برگشت‌پذیر باشد) */
    function igsProjectRestore(PDO $pdo, int $projectId, int $versionId, string $by): ?array
    {
        $cur = igsProjectOwned($pdo, $projectId, $by);
        if (!$cur) {
            return null;
        }
        $vs = $pdo->prepare('SELECT * FROM ig_studio_project_versions WHERE id = ? AND project_id = ? LIMIT 1');
        $vs->execute([$versionId, $projectId]);
        $v = $vs->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        // نسخه‌ی فعلی همیشه ذخیره می‌شود (بدون محدودیت ۱۲۰ ثانیه)
        $ins = $pdo->prepare('INSERT INTO ig_studio_project_versions (project_id, title, state_json, created_by, created_at) VALUES (?,?,?,?,?)');
        $ins->execute([$projectId, mb_substr((string)$cur['title'], 0, 200, 'UTF-8'), (string)$cur['state_json'], mb_substr($by, 0, 120, 'UTF-8'), $now]);
        $pdo->prepare('DELETE FROM ig_studio_project_versions WHERE project_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM ig_studio_project_versions WHERE project_id = ? ORDER BY id DESC LIMIT 30) AS keep_rows)')
            ->execute([$projectId, $projectId]);
        $up = $pdo->prepare('UPDATE ig_studio_projects SET state_json = ?, updated_at = ? WHERE id = ? AND created_by = ?');
        $up->execute([(string)$v['state_json'], $now, $projectId, mb_substr($by, 0, 120, 'UTF-8')]);
        return igsProjectPublic(igsProjectRow($pdo, $projectId), true);
    }

    /* ---------- خروجی‌ها ---------- */

    function igsExportSave(PDO $pdo, array $file, ?int $projectId, string $adId, string $by): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('فایل خروجی دریافت نشد.');
        }
        if ((int)$file['size'] <= 0 || (int)$file['size'] > IGS_MAX_UPLOAD) {
            throw new RuntimeException('حجم فایل خروجی نامعتبر است.');
        }
        $head = (string)@file_get_contents((string)$file['tmp_name'], false, null, 0, 8);
        if (strncmp($head, '%PDF-', 5) === 0) {
            // PDF: باید با %%EOF تمام شود و ساختار پایه داشته باشد
            $tail = (string)@file_get_contents((string)$file['tmp_name'], false, null, max(0, (int)$file['size'] - 64));
            if (strpos($tail, '%%EOF') === false || strpos((string)file_get_contents((string)$file['tmp_name'], false, null, 0, 4096), '/Type /Page') === false) {
                throw new RuntimeException('فایل PDF معتبر نیست.');
            }
            $ext = 'pdf';
            $mime = 'application/pdf';
            $w = 0;
            $h = 0;
        } elseif (strncmp($head, "PK\x03\x04", 4) === 0) {
            // ZIP: باید با ZipArchive باز شود (CRC/ساختار)
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('پشتیبانی ZIP روی سرور فعال نیست.');
            }
            $zip = new ZipArchive();
            if ($zip->open((string)$file['tmp_name'], ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('فایل ZIP معتبر نیست.');
            }
            $zip->close();
            $ext = 'zip';
            $mime = 'application/zip';
            $w = 0;
            $h = 0;
        } else {
            $info = @getimagesize((string)$file['tmp_name']);
            if (!$info || !in_array($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
                throw new RuntimeException('فقط PNG، JPEG، WebP، PDF یا ZIP پذیرفته می‌شود.');
            }
            // ابعاد باید یکی از اندازه‌های مجاز استودیو باشد (استوری، مربع، پوستر، عرضی، ۴:۵ پست)
            $okSize = false;
            foreach (IGS_ALLOWED_SIZES as $s) {
                if ((int)$info[0] === $s[0] && (int)$info[1] === $s[1]) { $okSize = true; break; }
            }
            if (!$okSize) {
                throw new RuntimeException('ابعاد خروجی مجاز نیست.');
            }
            $w = (int)$info[0];
            $h = (int)$info[1];
            if ($info[2] === IMAGETYPE_PNG) {
                $ext = 'png';
                $mime = 'image/png';
            } elseif ($info[2] === IMAGETYPE_JPEG) {
                $ext = 'jpg';
                $mime = 'image/jpeg';
            } else {
                $ext = 'webp';
                $mime = 'image/webp';
            }
        }

        $sub = date('Y') . '/' . date('m');
        $dir = IGS_EXPORT_DIR . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('پوشه خروجی ساخته نشد.');
        }
        if (!is_file(IGS_EXPORT_DIR . '/.htaccess')) {
            @file_put_contents(IGS_EXPORT_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $name = 'igs-' . ($projectId ?: 0) . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        $rel = $sub . '/' . $name;
        if (!@move_uploaded_file((string)$file['tmp_name'], IGS_EXPORT_DIR . '/' . $rel)) {
            throw new RuntimeException('ذخیره فایل خروجی ممکن نشد.');
        }
        $ptitle = '';
        if ($projectId) {
            $r = igsProjectRow($pdo, $projectId);
            $ptitle = $r ? (string)$r['title'] : '';
        }
        $st = $pdo->prepare('INSERT INTO ig_studio_exports (project_id, project_title, ad_id, file_rel, mime, width, height, bytes, created_at, created_by)
                             VALUES (?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$projectId ?: null, $ptitle, substr($adId, 0, 64), $rel, $mime, $w, $h, (int)filesize(IGS_EXPORT_DIR . '/' . $rel), date('Y-m-d H:i:s'), mb_substr($by, 0, 120, 'UTF-8')]);
        return igsExportPublic($pdo, (int)$pdo->lastInsertId());
    }

    function igsExportPublic(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_exports WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        return [
            'id' => (int)$r['id'],
            'project_id' => $r['project_id'] !== null ? (int)$r['project_id'] : null,
            'project_title' => (string)$r['project_title'],
            'ad_id' => (string)$r['ad_id'],
            'format' => igsMimeLabel((string)$r['mime']),
            'width' => (int)$r['width'],
            'height' => (int)$r['height'],
            'bytes' => (int)$r['bytes'],
            'created_at' => (string)$r['created_at'],
            'ad_id' => (string)$r['ad_id'],
            'agent_content_id' => $r['agent_content_id'] !== null ? (int)$r['agent_content_id'] : null,
            'agent_status' => igsAgentContentStatus($pdo, $r['agent_content_id'] !== null ? (int)$r['agent_content_id'] : 0),
        ];
    }

    /** وضعیت فعلی محتوای ایجنت (''، اگر وصل نباشد یا جدول ایجنت نصب نباشد) */
    function igsAgentContentStatus(PDO $pdo, int $contentId): string
    {
        if ($contentId <= 0) {
            return '';
        }
        try {
            $st = $pdo->prepare('SELECT status FROM instagram_agent_contents WHERE id = ?');
            $st->execute([$contentId]);
            $v = $st->fetchColumn();
            return $v === false ? '' : (string)$v;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * ارسال یک خروجی استودیو به ایجنت اینستاگرام به‌عنوان پیش‌نویس «استوری».
     *  - محتوا با همان سازندهٔ موجود ایجنت ساخته می‌شود (قالب‌محور؛ اگر ارائه‌دهندهٔ AI در تنظیمات ایجنت باشد همان).
     *  - رسانه فقط فایل خصوصی استودیو است (public_url ندارد) ⇒ انتشار API ندارد؛ با گردش کار دستی ایجنت منتشر می‌شود.
     *  - تکراری نمی‌سازد: اگر خروجی قبلاً وصل شده باشد، همان محتوا برمی‌گردد.
     */
    function igsSendExportToAgent(PDO $pdo, int $exportId, string $by): array
    {
        igsEnsureSchema($pdo);
        require_once __DIR__ . '/instagram-agent-lib.php';
        melkinoIgEnsureSchema($pdo);

        $st = $pdo->prepare('SELECT * FROM ig_studio_exports WHERE id = ? LIMIT 1');
        $st->execute([$exportId]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ex) {
            throw new RuntimeException('خروجی پیدا نشد.');
        }
        if (!empty($ex['agent_content_id'])) {
            $c = $pdo->prepare('SELECT id, status FROM instagram_agent_contents WHERE id = ?');
            $c->execute([(int)$ex['agent_content_id']]);
            $row = $c->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return ['content_id' => (int)$row['id'], 'status' => (string)$row['status'], 'created' => false, 'warnings' => []];
            }
        }

        $adId = (string)$ex['ad_id'];
        if ($adId === '') {
            throw new RuntimeException('این خروجی به هیچ آگهی‌ای وصل نیست؛ برای ارسال به ایجنت باید آگهی انتخاب شده باشد.');
        }
        $ad = melkinoIgGetAd($pdo, $adId);
        if ($ad === null) {
            throw new RuntimeException('آگهی این خروجی در سایت پیدا نشد.');
        }
        $elig = melkinoIgAdEligibility($pdo, $ad);
        if (!$elig['eligible']) {
            throw new RuntimeException('این آگهی برای ایجنت آماده نیست: ' . implode(' ', $elig['reasons']));
        }

        $images = melkinoIgAdImages($pdo, $adId);
        $secrets = melkinoIgSecretsRead();
        $ai = null;
        if (($secrets['ai_api_key'] ?? '') !== '') {
            $ai = new MelkinoIgHttpAiProvider(
                (string)($secrets['ai_base_url'] ?? 'https://api.openai.com/v1'),
                (string)$secrets['ai_api_key'],
                (string)($secrets['ai_model'] ?? 'gpt-4o-mini')
            );
        }
        $tone = melkinoIgSettingGet($pdo, 'tone', 'professional');
        $audience = melkinoIgSettingGet($pdo, 'audience', 'buyers');
        $pubUrl = melkinoIgPublicBaseUrl() . '/program/listing.php?id=' . rawurlencode($adId);
        $built = melkinoIgBuildContent($ad, 'story', ['tone' => $tone, 'audience' => $audience, 'public_url' => $pubUrl], $ai);

        $ext = (string)$ex['mime'] === 'image/png' ? 'png' : 'jpg';
        $media = [[
            'file' => 'ig-studio-export-' . $exportId . '.' . $ext,
            'public_url' => null,
            'source' => 'ig_studio',
            'studio_export_id' => $exportId,
            'width' => (int)$ex['width'],
            'height' => (int)$ex['height'],
        ]];

        $ins = $pdo->prepare(
            "INSERT INTO instagram_agent_contents
             (ad_id, content_type, title, hook, body_text, caption, cta, hashtags, on_image_text,
              video_scenario, alt_text, claims_check, tone, audience, media_json, source_snapshot,
              source_hash, generator, generator_model, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $adId, 'story', $built['title'], $built['hook'], $built['body_text'], $built['caption'],
            $built['cta'], $built['hashtags'], $built['on_image_text'], $built['video_scenario'],
            $built['alt_text'], $built['claims_check'], $built['tone'], $built['audience'],
            json_encode($media, JSON_UNESCAPED_UNICODE),
            json_encode($built['ad_facts'], JSON_UNESCAPED_UNICODE),
            melkinoIgSourceHash($ad, $images), $built['generator'], $ai ? $ai->name() : null, 'draft', mb_substr($by, 0, 120, 'UTF-8'),
        ]);
        $cid = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE ig_studio_exports SET agent_content_id = ? WHERE id = ?')->execute([$cid, $exportId]);
        melkinoIgAudit($pdo, 'content_generate', 'content', (string)$cid, 'source:ig_studio export:' . $exportId . ' kind:story', $by);

        return ['content_id' => $cid, 'status' => 'draft', 'created' => true, 'warnings' => $elig['warnings']];
    }

    function igsExportsList(PDO $pdo, int $limit = 100): array
    {
        $st = $pdo->prepare('SELECT id FROM ig_studio_exports ORDER BY created_at DESC, id DESC LIMIT ?');
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[] = igsExportPublic($pdo, (int)$id);
        }
        return $out;
    }

    /** مسیر فیزیکی امن یک خروجی؛ null اگر وجود ندارد یا خارج از پوشه است */
    function igsExportPath(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM ig_studio_exports WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $path = realpath(IGS_EXPORT_DIR . '/' . $r['file_rel']);
        $root = realpath(IGS_EXPORT_DIR);
        if ($path === false || $root === false || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return ['path' => $path, 'mime' => (string)$r['mime'], 'name' => basename($path), 'row' => $r];
    }

    function igsExportDelete(PDO $pdo, int $id): bool
    {
        $f = igsExportPath($pdo, $id);
        if (!$f) {
            return false;
        }
        // فایل خروجی هنوز توسط محتوای ایجنت استفاده می‌شود؛ تا لغو آن محتوا حذف نمی‌شود
        $chk = $pdo->prepare('SELECT agent_content_id FROM ig_studio_exports WHERE id = ?');
        $chk->execute([$id]);
        $linked = (int)$chk->fetchColumn();
        $linkStatus = $linked > 0 ? igsAgentContentStatus($pdo, $linked) : '';
        if ($linked > 0 && $linkStatus !== '' && $linkStatus !== 'cancelled') {
            throw new RuntimeException('این خروجی به یک محتوای ایجنت اینستاگرام وصل است؛ ابتدا آن محتوا را در ایجنت لغو کنید.');
        }
        @unlink($f['path']);
        $st = $pdo->prepare('DELETE FROM ig_studio_exports WHERE id = ?');
        $st->execute([$id]);
        return true;
    }
}
