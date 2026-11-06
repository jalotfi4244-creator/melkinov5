<?php
require_once __DIR__ . '/db_helpers.php'; // راند ۴۱: آیکون‌های SVG
if (is_file(__DIR__ . '/field-icons.php')) {
    require_once __DIR__ . '/field-icons.php';
}
/*
|--------------------------------------------------------------------------
| field-display.php — سیستم مدیریت فیلدهای نمایشی (راند ۲۹)
|--------------------------------------------------------------------------
| مرجع مرکزی (Registry) فیلدهای:
|   1) کارت‌های صفحهٔ اصلی (home.php)
|   2) بخش‌ها و فیلدهای صفحهٔ جزئیات ملک (property-details.php)
| به‌همراه: تنظیمات ذخیره‌شده در جدول settings (گروه global)،
| تاریخچهٔ تغییرات (updated_at/updated_by + ۵ نسخهٔ اخیر)،
| نرمال‌سازی ارقام فارسی/عربی، فرمت نمایش، قواعد ریسپانسیو
| (موبایل/تبلت/دسکتاپ) و رندرر مشترک سایت + پیش‌نمایش پنل.
|
| معماری: Field Configuration → Data Resolver → Formatter →
|         Visibility/Responsive Rules → Renderer → Card/Details
|
| ذخیره: settings.global.home_card_fields و settings.global.details_display
| (بدون تغییر ساختار دیتابیس — همان جدول settings راندهای قبل)
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/card-display.php';

/* =====================================================
   ابزارها — ارقام و کلاس‌های ریسپانسیو
===================================================== */

if (!function_exists('melkinoFdEnDigits')) {
    /** نرمال‌سازی ارقام فارسی/عربی به انگلیسی (برای ورودی‌های پنل و order) */
    function melkinoFdEnDigits($v): string
    {
        return strtr(
            (string)$v,
            [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]
        );
    }
}

if (!function_exists('melkinoFdFaDigits')) {
    function melkinoFdFaDigits($v): string
    {
        return strtr(
            (string)$v,
            ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
             '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']
        );
    }
}

if (!function_exists('melkinoFdApplyDigits')) {
    /**
     * حالت نمایش ارقام یک فیلد:
     *  fa = فارسی، en = انگلیسی، auto = همان مقدار فعلی (رفتار پیش‌فرض سایت)
     */
    function melkinoFdApplyDigits(string $text, string $mode): string
    {
        if ($mode === 'fa') {
            return melkinoFdFaDigits($text);
        }
        if ($mode === 'en') {
            return melkinoFdEnDigits($text);
        }
        return $text;
    }
}

if (!function_exists('melkinoFdRclass')) {
    /** کلاس‌های مخفی‌سازی ریسپانسیو — با CSS رسانه‌ای صفحه اعمال می‌شود */
    function melkinoFdRclass(array $f): string
    {
        $c = [];
        if (empty($f['mobile']))  { $c[] = 'fd-hide-m'; }
        if (empty($f['tablet']))  { $c[] = 'fd-hide-t'; }
        if (empty($f['desktop'])) { $c[] = 'fd-hide-d'; }
        return $c ? ' ' . implode(' ', $c) : '';
    }
}

if (!function_exists('melkinoFdEsc')) {
    function melkinoFdEsc($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

/* =====================================================
   کاتالوگ مشترک مشخصات صفحهٔ جزئیات
   (از property-details.php منتقل شد — منبع واحد لیبل‌ها)
===================================================== */

if (!function_exists('melkinoPdSpecDefinitions')) {
    /** لیبل نمایشی → کلیدهای کاندیدا در property_details/ads */
    function melkinoPdSpecDefinitions(): array
    {
        return [
        'کلید نخورده' => ['key_not_turned', 'is_not_keyed', 'is_new', 'new_building', 'never_lived'],

        'متراژ (متر مربع)' => ['area', 'area_apt', 'area_comm', 'office_area', 'built_area'],
        'متراژ زمین (متر مربع)' => ['land_area', 'land_villa', 'garden_area'],
        'زیربنا (متر مربع)' => ['built_area', 'built_villa'],
        'مساحت زمین (متر مربع)' => ['land_area', 'land_villa', 'garden_area'],
        'مساحت باغ (متر مربع)' => ['garden_area', 'land_area'],
        'متراژ خانه باغ (متر مربع)' => ['building_area'],

        'طبقه' => ['floor', 'floor_apt', 'office_floor'],
        'تعداد اتاق' => ['rooms', 'rooms_apt', 'rooms_villa', 'office_rooms'],
        'سال ساخت' => ['year', 'year_apt', 'year_villa', 'office_year'],
        'سن بنا' => ['building_age', 'age', 'buildingAge'],
        'تعداد کل واحدها' => ['units_per_floor_apt', 'total_units', 'units_total', 'units_per_floor', 'number_of_units'],
        'تعداد واحد در طبقه' => ['units_per_floor', 'office_units_per_floor'],
        'نوع پوشش کف' => ['floor_covering', 'flooring_apt', 'flooring_villa', 'floor_comm', 'office_flooring', 'flooring', 'floor_type', 'floor_cover'],
        'نوع کابینت' => ['cabinet_type', 'cabinet_apt', 'cabinet_villa', 'cabinet_comm', 'office_cabinet', 'cabinet'],
        'سیستم سرمایش' => ['cooling_system', 'cooling_apt', 'cooling_villa', 'cooling_comm', 'office_cooling', 'cooling'],
        'سیستم گرمایش' => ['heating_system', 'heating_apt', 'heating_villa', 'heating_comm', 'office_heating', 'heating'],
        'نوع آپارتمان' => ['apartment_type'],
        'نوع ویلایی' => ['villa_type'],
        'وضعیت ملک' => ['condition', 'condition_apt', 'condition_villa', 'office_condition'],
        'جهت ملک' => ['orientation', 'orientation_apt', 'land_direction', 'office_orientation'],
        'کاربری' => ['usage', 'land_usage', 'land_type', 'office_usage', 'usage_comm'],
        'عرض زمین (متر)' => ['land_width'],
        'شکل زمین' => ['land_shape'],
        'وضعیت سند زمین' => ['land_deed_status'],
        'وضعیت مالکیت' => ['land_ownership'],
        'بر مغازه (متر)' => ['front_width', 'front_comm', 'front'],
        'بر زمین (متر)' => ['front_width', 'land_front_width'],
        'کوچه یا معبر (متر)' => ['length', 'land_length'],
        'تعداد بر' => ['blocks', 'land_blocks'],
        'وضعیت عقب‌نشینی' => ['setback_status', 'land_setback_status'],
        'نوع درختان' => ['tree_types'],
        'آب ملکی' => ['irrigation_source', 'has_well'],
        'سن درختان' => ['tree_age'],
        'بنا / خانه باغ' => ['has_building'],
        'نوع آبیاری' => ['irrigation_type'],
        'استخر' => ['has_pond'],
        'نوع سند' => ['document_type', 'deed_type', 'land_deed_type'],
        'توضیحات سند' => ['deed_notes'],
        'پوشش دیوارها' => ['wall_covering', 'wall_comm', 'wall'],
        'سرمایش' => ['cooling_system', 'cooling_comm'],
        'گرمایش' => ['heating_system', 'heating_comm'],
        'موقعیت' => ['orientation', 'orientation_comm', 'location_type', 'location_type_1'],
        'ویژگی موقعیت' => ['location_features', 'location_type_2'],
        'مناسب برای' => ['usage', 'usage_comm', 'jobs', 'jobs_comm'],
        'وضعیت واحد' => ['condition', 'office_condition'],
    ];
    }
}

if (!function_exists('melkinoPdTypeSpecOrder')) {
    /** ترتیب پیش‌فرض لیبل‌های مشخصات برای هر نوع ملک (وضعیت فعلی سایت) */
    function melkinoPdTypeSpecOrder(string $propertyType): array
    {
        $specDefinitions = melkinoPdSpecDefinitions();
        $desiredLabels = [];
        $pt = trim($propertyType);
        if (mb_strpos($pt, 'ویلا') !== false) {
            $pt = 'ویلا';
        } elseif ($pt === 'مغازه') {
            $pt = 'تجاری';
        }
    switch ($pt) {
        case 'آپارتمان':
            $desiredLabels = [
                'کلید نخورده',
                'متراژ (متر مربع)',
                'طبقه',
                'تعداد اتاق',
                'سال ساخت',
                'سن بنا',
                'تعداد کل واحدها',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        case 'ویلا':
        case 'ویلایی':
            $desiredLabels = [
                'متراژ زمین (متر مربع)',
                'زیربنا (متر مربع)',
                'نوع ویلایی',
                'تعداد اتاق',
                'سال ساخت',
                'سن بنا',
                'وضعیت ملک',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        case 'زمین':
            $desiredLabels = [
                'مساحت زمین (متر مربع)',
                'کاربری',
                'عرض زمین (متر)',
                'شکل زمین',
                'جهت ملک',
                'بر زمین (متر)',
                'کوچه یا معبر (متر)',
                'تعداد بر',
                'وضعیت عقب‌نشینی',
                'وضعیت سند زمین',
                'وضعیت مالکیت',
            ];
            break;
        case 'باغ':
            $desiredLabels = [
                'مساحت باغ (متر مربع)',
                'نوع درختان',
                'سن درختان',
                'آب ملکی',
                'نوع آبیاری',
                'بنا / خانه باغ',
                'متراژ خانه باغ (متر مربع)',
                'استخر',
                'نوع سند',
            ];
            break;
        case 'تجاری':
            $desiredLabels = [
                'متراژ (متر مربع)',
                'بر مغازه (متر)',
                'نوع پوشش کف',
                'پوشش دیوارها',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
                'موقعیت',
                'ویژگی موقعیت',
                'مناسب برای',
            ];
            break;
        case 'اداری':
            $desiredLabels = [
                'کلید نخورده',
                'متراژ (متر مربع)',
                'طبقه',
                'تعداد واحد در طبقه',
                'تعداد اتاق',
                'سال ساخت',
                'سن بنا',
                'وضعیت واحد',
                'نوع پوشش کف',
                'نوع کابینت',
                'سیستم سرمایش',
                'سیستم گرمایش',
            ];
            break;
        default:
            $desiredLabels = array_keys($specDefinitions);
            break;
    }

        // نوع سند و توضیحات آن برای همهٔ نوع‌های ملک مشترک است (راند ۱۴)
        foreach (['نوع سند', 'توضیحات سند'] as $__deedLabel) {
            if (!in_array($__deedLabel, $desiredLabels, true)) {
                $desiredLabels[] = $__deedLabel;
            }
        }

        return $desiredLabels;
    }
}

/* =====================================================
   Registry کارت صفحهٔ اصلی (home.php)
===================================================== */

if (!function_exists('melkinoFdHomeDefs')) {
    /**
     * تعریف مرجع همهٔ فیلدهای قابل مدیریت کارت.
     *  kind: block = عنصر ساختاری بدنه | spec = فیلد مشخصات (حباب/چیپ) | footer
     *  type: text|currency|location|date|badge|count|number|boolean|composite|tags
     * ترتیب پیش‌فرض = ترتیب فعلی DOM در home.php (وضعیت ظاهری حفظ می‌شود).
     */
    function melkinoFdHomeDefs(): array
    {
        $defs = [];

        // --- فیلدهای ساختاری بدنهٔ کارت ---
        $defs['code'] = [
            'label' => 'کد آگهی', 'icon' => '🔢', 'type' => 'text',
            'source' => 'ads.id', 'kind' => 'block', 'visible' => false, 'order' => 5,
        ];
        $defs['title'] = [
            'label' => 'عنوان آگهی', 'icon' => '📰', 'type' => 'text',
            'source' => 'ads.title', 'kind' => 'block', 'visible' => true, 'order' => 10,
        ];
        $defs['location'] = [
            'label' => 'موقعیت', 'icon' => '📍', 'type' => 'location',
            'source' => 'ads.location / neighborhood', 'kind' => 'block', 'visible' => true, 'order' => 20,
        ];

        // --- فیلدهای مشخصات (حباب روی تصویر یا چیپ در بدنه) ---
        // ترتیب = همان ترتیب کاتالوگ card-display (رندر فعلی سایت)
        $order = 30;
        $typeMap = [
            'transaction' => 'badge', 'property_type' => 'badge', 'area' => 'number',
            'rooms' => 'count', 'floor' => 'number', 'year' => 'number',
            'parking' => 'boolean', 'elevator' => 'boolean', 'key_not_turned' => 'boolean',
            'loan' => 'boolean', 'exchange' => 'boolean', 'tags' => 'tags',
            'deed' => 'text', 'deposit' => 'currency', 'rent_monthly' => 'currency',
            'full_rent' => 'currency',
        ];
        $sourceMap = [
            'transaction' => 'ads.transaction_type', 'property_type' => 'ads.property_type',
            'area' => 'property_details.area* / ads.area', 'rooms' => 'property_details.rooms*',
            'floor' => 'property_details.floor*', 'year' => 'property_details.year*',
            'parking' => 'ad_amenities+amenities', 'elevator' => 'ad_amenities+amenities',
            'key_not_turned' => 'ads.is_not_keyed', 'loan' => 'ads.has_loan/loan_amount',
            'exchange' => 'ads.exchange_interested', 'tags' => 'ads.tags (JSON)',
            'deed' => 'ads.deed_type / property_details', 'deposit' => 'ads.deposit',
            'rent_monthly' => 'ads.rent_monthly', 'full_rent' => 'ads.full_rent(_enabled)',
        ];
        $specItems = melkinoCardDisplayDefs()['specs']['items'] ?? [];
        foreach ($specItems as $key => $item) {
            $defs[$key] = [
                'label'  => (string)($item['label'] ?? $key),
                'icon'   => (string)($item['emoji'] ?? '🔹'),
                'type'   => $typeMap[$key] ?? (strpos($key, 'pd.') === 0 ? 'text' : 'text'),
                'source' => $sourceMap[$key] ?? (strpos($key, 'pd.') === 0 ? 'property_details.' . substr($key, 3) : 'ads.*'),
                'kind'   => 'spec',
                'mode'   => in_array($item['default'] ?? 'off', ['pill', 'text'], true) ? $item['default'] : 'text',
                'visible' => ($item['default'] ?? 'off') !== 'off',
                'suffix' => (string)($item['suffix'] ?? ''),
                'order'  => $order,
            ];
            $order += 1;
        }

        // --- ادامهٔ ساختاری بدنه ---
        $defs['price'] = [
            'label' => 'قیمت', 'icon' => '💰', 'type' => 'currency',
            'source' => 'price_sell/total_price/deposit+rent_monthly', 'kind' => 'block', 'visible' => true, 'order' => $order + 1,
        ];
        $defs['loan_price_line'] = [
            'label' => 'خط قیمت نقد + وام', 'icon' => '🏦', 'type' => 'composite',
            'source' => 'ads.loan_* (melkinoLoanInfo)', 'kind' => 'block', 'visible' => true, 'order' => $order + 2,
        ];

        // --- فوتر کارت ---
        $defs['date'] = [
            'label' => 'تاریخ ثبت', 'icon' => '🗓️', 'type' => 'date',
            'source' => 'ads.created_at', 'kind' => 'footer', 'visible' => true, 'order' => 500,
        ];
        $defs['details_link'] = [
            'label' => 'لینک مشاهدهٔ جزئیات', 'icon' => '🔍', 'type' => 'link',
            'source' => 'property-details.php?id=', 'kind' => 'footer', 'visible' => true, 'order' => 510,
        ];

        return $defs;
    }
}

if (!function_exists('melkinoFdHomeDefaults')) {
    /**
     * تنظیمات پیش‌فرض = وضعیت فعلی سایت (مهاجرت از تنظیمات card_display
     * راند ۲۱ تا ظاهر صفحهٔ اصلی بعد از نصب این سیستم تغییر نکند).
     */
    function melkinoFdHomeDefaults(): array
    {
        $defs = melkinoFdHomeDefs();
        $cd = function_exists('melkinoCardDisplaySettings') ? melkinoCardDisplaySettings() : [];
        $out = [];
        foreach ($defs as $key => $d) {
            $cfg = [
                'label'      => $d['label'],
                'icon'       => $d['icon'],
                'visible'    => (bool)$d['visible'],
                'order'      => (int)$d['order'],
                'mobile'     => true,
                'tablet'     => true,
                'desktop'    => true,
                'digits'     => 'auto',
                'show_label' => strpos($key, 'pd.') === 0, // چیپ‌های pd.* امروز لیبل دارند
                'show_icon'  => in_array($key, ['parking', 'elevator'], true),
            ];
            if ($d['kind'] === 'spec') {
                $mode = melkinoCardMode($cd, $key);
                $cfg['mode'] = in_array($mode, ['pill', 'text'], true) ? $mode : ($d['mode'] ?? 'text');
                $cfg['visible'] = $mode !== 'off';
            } elseif (array_key_exists($key, $cd) && $key !== 'code') {
                // title/location/price/date/loan_price_line از تنظیمات فعلی
                $cfg['visible'] = melkinoCardShow($cd, $key);
            }
            $out[$key] = $cfg;
        }
        return $out;
    }
}

if (!function_exists('melkinoFdStored')) {
    /** خواندن تنظیمات ذخیره‌شدهٔ یک هدف از جدول settings */
    function melkinoFdStored(string $target): array
    {
        global $pdo;
        $key = $target === 'details' ? 'details_display' : 'home_card_fields';
        try {
            if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
                $raw = dbSettingGet($pdo, 'global', $key, '');
                // dbSettingGet برای value_type=json آرایهٔ decode‌شده برمی‌گرداند
                if (is_array($raw)) {
                    return $raw;
                }
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        } catch (Throwable $e) {
            // بدون تنظیمات ذخیره‌شده → پیش‌فرض‌ها
        }
        return [];
    }
}

if (!function_exists('melkinoFdMergeFields')) {
    /** ادغام تنظیمات ذخیره‌شده با پیش‌فرض‌ها (فقط کلیدهای معتبر registry) */
    function melkinoFdMergeFields(array $defaults, $saved): array
    {
        $out = $defaults;
        if (!is_array($saved)) {
            return $out;
        }
        foreach ($out as $key => $cfg) {
            if (!isset($saved[$key]) || !is_array($saved[$key])) {
                continue;
            }
            $s = $saved[$key];
            if (isset($s['label']) && is_string($s['label']) && trim($s['label']) !== '') {
                $out[$key]['label'] = mb_substr(trim($s['label']), 0, 80);
            }
            if (isset($s['icon']) && is_string($s['icon'])) {
                $out[$key]['icon'] = mb_substr($s['icon'], 0, 64);
            }
            foreach (['visible', 'mobile', 'tablet', 'desktop', 'show_label', 'show_icon'] as $bk) {
                if (array_key_exists($bk, $s)) {
                    $out[$key][$bk] = filter_var($s[$bk], FILTER_VALIDATE_BOOLEAN);
                }
            }
            if (isset($s['order'])) {
                $out[$key]['order'] = (int)melkinoFdEnDigits($s['order']);
            }
            if (isset($s['digits']) && in_array((string)$s['digits'], ['fa', 'en', 'auto'], true)) {
                $out[$key]['digits'] = (string)$s['digits'];
            }
            if (isset($s['mode']) && in_array((string)$s['mode'], ['pill', 'text'], true)) {
                $out[$key]['mode'] = (string)$s['mode'];
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoFdHomeSettings')) {
    /** تنظیمات نهایی کارت اصلی: fields + meta + defs */
    function melkinoFdHomeSettings(): array
    {
        $stored = melkinoFdStored('home');
        return [
            'fields' => melkinoFdMergeFields(melkinoFdHomeDefaults(), $stored['fields'] ?? []),
            'meta'   => $stored['meta'] ?? null,
            'defs'   => melkinoFdHomeDefs(),
        ];
    }
}

/* =====================================================
   رندرر کارت صفحهٔ اصلی
   ورودی: آگهی + تنظیمات + helperهای صفحه (price/loan/date)
   خروجی: ['pills'=>html, 'body'=>html, 'footer'=>html]
   markup دقیقاً همان کلاس‌های فعلی home.php است.
===================================================== */

if (!function_exists('melkinoFdLocationSvg')) {
    function melkinoFdLocationSvg(): string
    {
        return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
            . '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>'
            . '<circle cx="12" cy="10" r="3"></circle></svg>';
    }
}

if (!function_exists('melkinoFdHomeRender')) {
    function melkinoFdHomeRender(array $ad, array $settings, array $helpers = []): array
    {
        $defs = $settings['defs'] ?? melkinoFdHomeDefs();
        $cfg  = $settings['fields'] ?? [];

        $specialClass = [
            'transaction' => 'transaction', 'property_type' => 'transaction',
            'key_not_turned' => 'key-not-turned', 'loan' => 'loan', 'exchange' => 'exchange',
        ];

        // مرتب‌سازی فیلدهای فعال بر اساس order
        $items = [];
        foreach ($cfg as $key => $f) {
            if (empty($f['visible']) || !isset($defs[$key])) {
                continue;
            }
            $items[] = ['key' => $key, 'f' => $f, 'def' => $defs[$key]];
        }
        usort($items, static function ($a, $b) {
            return ((int)$a['f']['order']) <=> ((int)$b['f']['order']);
        });

        $pills = [];
        $body  = [];   // ['kind'=>'block'|'chip', 'html'=>..., 'rc'=>...]
        $footer = [];

        foreach ($items as $it) {
            $key = $it['key'];
            $f   = $it['f'];
            $d   = $it['def'];
            $rc  = melkinoFdRclass($f);
            $kind = (string)($d['kind'] ?? 'block');

            if ($kind === 'spec') {
                $mode = ($f['mode'] ?? 'text') === 'pill' ? 'pill' : 'text';
                if ($key === 'tags') {
                    $tags = $ad['tags'] ?? [];
                    if (is_string($tags)) {
                        $decoded = json_decode($tags, true);
                        $tags = is_array($decoded) ? $decoded : [];
                    }
                    foreach ((array)$tags as $tag) {
                        $tag = trim((string)$tag);
                        if ($tag === '') {
                            continue;
                        }
                        $tHtml = '<span class="ad-card-badge' . $rc . '">' . melkinoFdEsc(melkinoFdApplyDigits($tag, $f['digits'] ?? 'auto')) . '</span>';
                        if ($mode === 'pill') { $pills[] = $tHtml; } else { $body[] = ['kind' => 'chip', 'html' => $tHtml, 'rc' => $rc]; }
                    }
                    continue;
                }
                $value = melkinoCardFieldValue($ad, $key);
                if ($value === null || $value === '') {
                    continue; // مقدار خالی → فیلد نمایش داده نمی‌شود (بدون فضای خالی)
                }
                $value = melkinoFdApplyDigits($value, (string)($f['digits'] ?? 'auto'));
                $icon  = (string)($f['icon'] ?? '');
                $iconOut = function_exists('melkinoFieldIcon')
                    ? (melkinoFieldIcon((string) $key) ?: melkinoFieldIcon($icon))
                    : melkinoIconOrText($icon);
                if ($mode === 'pill') {
                    $cls = $specialClass[$key] ?? 'spec';
                    $pills[] = '<span class="ad-card-badge ' . melkinoFdEsc($cls) . $rc . '">' . ($iconOut !== '' ? $iconOut . ' ' : '') . melkinoFdEsc($value) . '</span>';
                } else {
                    $label = (string)($f['label'] ?? $d['label'] ?? $key);
                    $prefix = !empty($f['show_label']) ? $label . ': ' : '';
                    $iconPre = !empty($f['show_icon']) && $iconOut !== '' ? $iconOut . ' ' : '';
                    $rcAttr = $rc !== '' ? ' class="' . trim($rc) . '"' : '';
                    $body[] = ['kind' => 'chip', 'html' => '<span' . $rcAttr . '>' . melkinoFdEsc($iconPre . $prefix . $value) . '</span>', 'rc' => $rc];
                }
                continue;
            }

            if ($kind === 'footer') {
                if ($key === 'date') {
                    $dateText = '';
                    if (is_callable($helpers['date'] ?? null)) {
                        $dateText = (string)call_user_func($helpers['date'], $ad);
                    }
                    if ($dateText === '') {
                        continue;
                    }
                    $footer[] = '<span' . $rc . '>' . melkinoFdEsc(melkinoFdApplyDigits($dateText, $f['digits'] ?? 'auto')) . '</span>';
                } elseif ($key === 'details_link') {
                    $footer[] = '<span' . $rc . '>🔍 مشاهده جزئیات</span>';
                }
                continue;
            }

            // بلوک‌های ساختاری بدنه
            switch ($key) {
                case 'title':
                    $t = trim((string)($ad['title'] ?? ''));
                    if ($t === '') { continue 2; }
                    $body[] = ['kind' => 'block', 'rc' => $rc, 'html' => '<div class="ad-card-title' . $rc . '">' . melkinoFdEsc(melkinoFdApplyDigits($t, $f['digits'] ?? 'auto')) . '</div>'];
                    break;
                case 'location':
                    $loc = (string)($ad['location'] ?? '');
                    $body[] = ['kind' => 'block', 'rc' => $rc, 'html' => '<div class="ad-card-location' . $rc . '">' . melkinoFdLocationSvg() . melkinoFdEsc($loc !== '' ? $loc : 'موقعیت نامشخص') . '</div>'];
                    break;
                case 'code':
                    $code = (string)($ad['ad_id'] ?? $ad['id'] ?? '');
                    if ($code === '') { continue 2; }
                    $body[] = ['kind' => 'block', 'rc' => $rc, 'html' => '<div class="ad-card-code' . $rc . '">' . melkinoFdEsc($code) . '</div>'];
                    break;
                case 'price':
                    $price = is_callable($helpers['price'] ?? null) ? (string)call_user_func($helpers['price'], $ad) : '';
                    if ($price === '') { continue 2; }
                    $body[] = ['kind' => 'block', 'rc' => $rc, 'html' => '<div class="ad-card-price' . $rc . '">' . melkinoFdEsc(melkinoFdApplyDigits($price, $f['digits'] ?? 'auto')) . '</div>'];
                    break;
                case 'loan_price_line':
                    $loan = is_callable($helpers['loan_line'] ?? null) ? (string)call_user_func($helpers['loan_line'], $ad) : '';
                    if ($loan === '') { continue 2; }
                    $wrapped = $rc !== '' ? '<div class="' . trim($rc) . '">' . $loan . '</div>' : $loan;
                    $body[] = ['kind' => 'block', 'rc' => $rc, 'html' => $wrapped];
                    break;
            }
        }

        // گروه‌بندی چیپ‌های متوالی در یک ظرف .ad-card-details (مثل امروز)
        $bodyHtml = '';
        $run = [];
        $flushRun = function () use (&$run, &$bodyHtml) {
            if (!$run) {
                return;
            }
            // اگر همهٔ چیپ‌ها در یک breakpoint مخفی‌اند، ظرف هم مخفی شود (فضای خالی نماند)
            $common = ['fd-hide-m', 'fd-hide-t', 'fd-hide-d'];
            foreach ($run as $r) {
                foreach ($common as $ci => $cl) {
                    if (strpos($r['rc'], $cl) === false) {
                        unset($common[$ci]);
                    }
                }
            }
            $runRc = $common ? ' ' . implode(' ', array_values($common)) : '';
            $bodyHtml .= '<div class="ad-card-details' . $runRc . '">';
            foreach ($run as $r) {
                $bodyHtml .= $r['html'];
            }
            $bodyHtml .= '</div>';
            $run = [];
        };
        foreach ($body as $b) {
            if ($b['kind'] === 'chip') {
                $run[] = $b;
            } else {
                $flushRun();
                $bodyHtml .= $b['html'];
            }
        }
        $flushRun();

        return [
            'pills'  => $pills ? '<div class="ad-card-badges">' . implode('', $pills) . '</div>' : '',
            'body'   => $bodyHtml,
            'footer' => implode('', $footer),
        ];
    }
}

if (!function_exists('melkinoFdHomeCss')) {
    /** CSS ریسپانسیو مشترک (یک‌بار در هر صفحه چاپ شود) */
    function melkinoFdHomeCss(): string
    {
        return <<<CSS
/* راند ۲۹ — قوانین نمایش فیلدها در موبایل/تبلت/دسکتاپ */
@media (max-width:767px){ .fd-hide-m{ display:none !important; } }
@media (min-width:768px) and (max-width:1023px){ .fd-hide-t{ display:none !important; } }
@media (min-width:1024px){ .fd-hide-d{ display:none !important; } }
.ad-card-code{ font-size:11px; color:var(--text-secondary); margin-bottom:2px; }
CSS;
    }
}

/* =====================================================
   Registry صفحهٔ جزئیات (property-details.php)
===================================================== */

if (!function_exists('melkinoFdDetailsSectionDefs')) {
    /** بخش‌های واقعی صفحهٔ جزئیات به ترتیب فعلی DOM */
    function melkinoFdDetailsSectionDefs(): array
    {
        return [
            'gallery'     => ['label' => 'گالری تصاویر',   'icon' => '🖼️', 'order' => 10, 'has_title' => false],
            'header'      => ['label' => 'اطلاعات اصلی',   'icon' => '🧾', 'order' => 20, 'has_title' => false],
            'price'       => ['label' => 'قیمت و شرایط',   'icon' => '💰', 'order' => 30, 'has_title' => false],
            'specs'       => ['label' => 'مشخصات ملک',     'icon' => '',   'order' => 40, 'has_title' => true],
            'amenities'   => ['label' => 'امکانات ملک',    'icon' => '',   'order' => 50, 'has_title' => true],
            'description' => ['label' => 'توضیحات',        'icon' => '',   'order' => 60, 'has_title' => true],
            'contact'     => ['label' => 'نوار تماس مشاور', 'icon' => '📞', 'order' => 70, 'has_title' => false],
        ];
    }
}

if (!function_exists('melkinoFdDetailsFieldDefs')) {
    /** فیلدهای داخلی بخش‌های header و price */
    function melkinoFdDetailsFieldDefs(): array
    {
        return [
            'header' => [
                'property_code'   => ['label' => 'خط کد/نوع ملک', 'icon' => '🏠', 'type' => 'text', 'source' => 'ads.property_type', 'order' => 5],
                'title'           => ['label' => 'عنوان ملک',     'icon' => '📰', 'type' => 'text', 'source' => 'ads.title', 'order' => 10],
                'chip_transaction' => ['label' => 'چیپ نوع معامله', 'icon' => '🏷️', 'type' => 'badge', 'source' => 'ads.transaction_type', 'order' => 20],
                'chip_location'   => ['label' => 'چیپ موقعیت',    'icon' => '📍', 'type' => 'location', 'source' => 'ads.neighborhood/location', 'order' => 30],
                'chip_key'        => ['label' => 'کلید نخورده', 'icon' => '🔑', 'type' => 'boolean', 'source' => 'ads.is_not_keyed', 'order' => 40],
                'compare_btn'     => ['label' => 'دکمهٔ مقایسه',  'icon' => '⚖️', 'type' => 'text', 'source' => 'compare_items', 'order' => 50],
            ],
            'price' => [
                'price_main'     => ['label' => 'قیمت اصلی',        'icon' => '💰', 'type' => 'currency', 'source' => 'price_sell/total_price/deposit', 'order' => 10],
                'price_per_meter' => ['label' => 'قیمت هر متر مربع', 'icon' => '💹', 'type' => 'currency', 'source' => 'محاسبه از قیمت و متراژ', 'order' => 20],
                'loan_box'       => ['label' => 'مشخصات وام',       'icon' => '🏦', 'type' => 'composite', 'source' => 'ads.loan_*', 'order' => 30],
            ],
        ];
    }
}

if (!function_exists('melkinoFdDetailsDefaults')) {
    function melkinoFdDetailsDefaults(): array
    {
        $sections = [];
        foreach (melkinoFdDetailsSectionDefs() as $key => $d) {
            $sections[$key] = [
                'visible' => true, 'title' => $d['label'], 'icon' => $d['icon'],
                'show_title' => (bool)$d['has_title'], 'order' => (int)$d['order'],
                'mobile' => true, 'tablet' => true, 'desktop' => true,
            ];
        }

        $fields = [];
        foreach (melkinoFdDetailsFieldDefs() as $sec => $list) {
            foreach ($list as $key => $d) {
                $fields[$sec][$key] = [
                    'visible' => true, 'label' => $d['label'], 'icon' => $d['icon'],
                    'order' => (int)$d['order'], 'mobile' => true, 'tablet' => true, 'desktop' => true,
                    'digits' => 'auto',
                ];
            }
        }

        // فیلدهای بخش مشخصات برای هر نوع ملک — ترتیب پیش‌فرض = وضعیت فعلی
        $specs = [];
        $allLabels = array_keys(melkinoPdSpecDefinitions());
        $types = ['آپارتمان', 'ویلا', 'ویلایی', 'زمین', 'باغ', 'تجاری', 'اداری'];
        foreach ($types as $type) {
            $desired = melkinoPdTypeSpecOrder($type);
            $specs[$type] = [];
            $i = 10;
            foreach ($desired as $label) {
                $specs[$type][$label] = [
                    'visible' => true, 'label' => $label, 'icon' => '', 'order' => $i,
                    'mobile' => true, 'tablet' => true, 'desktop' => true,
                ];
                $i += 10;
            }
            foreach ($allLabels as $label) {
                if (!isset($specs[$type][$label])) {
                    $specs[$type][$label] = [
                        'visible' => false, 'label' => $label, 'icon' => '', 'order' => $i,
                        'mobile' => true, 'tablet' => true, 'desktop' => true,
                    ];
                    $i += 10;
                }
            }
        }

        return ['sections' => $sections, 'fields' => $fields, 'specs' => $specs];
    }
}

if (!function_exists('melkinoFdDetailsSettings')) {
    function melkinoFdDetailsSettings(): array
    {
        $defaults = melkinoFdDetailsDefaults();
        $stored = melkinoFdStored('details');
        $saved = is_array($stored['config'] ?? null) ? $stored['config'] : $stored;

        if (is_array($saved['sections'] ?? null)) {
            foreach ($defaults['sections'] as $key => $cfg) {
                if (isset($saved['sections'][$key]) && is_array($saved['sections'][$key])) {
                    $defaults['sections'][$key] = melkinoFdMergeSection($cfg, $saved['sections'][$key]);
                }
            }
        }
        if (is_array($saved['fields'] ?? null)) {
            foreach ($defaults['fields'] as $sec => $list) {
                foreach ($list as $key => $cfg) {
                    if (isset($saved['fields'][$sec][$key]) && is_array($saved['fields'][$sec][$key])) {
                        $defaults['fields'][$sec][$key] = melkinoFdMergeSection($cfg, $saved['fields'][$sec][$key]);
                    }
                }
            }
        }
        if (is_array($saved['specs'] ?? null)) {
            foreach ($defaults['specs'] as $type => $list) {
                foreach ($list as $label => $cfg) {
                    if (isset($saved['specs'][$type][$label]) && is_array($saved['specs'][$type][$label])) {
                        $defaults['specs'][$type][$label] = melkinoFdMergeSection($cfg, $saved['specs'][$type][$label]);
                    }
                }
            }
        }

        return [
            'config' => $defaults,
            'meta'   => $stored['meta'] ?? null,
            'defs'   => [
                'sections' => melkinoFdDetailsSectionDefs(),
                'fields'   => melkinoFdDetailsFieldDefs(),
                'specLabels' => array_keys(melkinoPdSpecDefinitions()),
                'specSources' => melkinoPdSpecDefinitions(),
                'types'    => ['آپارتمان', 'ویلا', 'زمین', 'باغ', 'تجاری', 'اداری'],
            ],
        ];
    }
}

if (!function_exists('melkinoFdMergeSection')) {
    function melkinoFdMergeSection(array $cfg, array $s): array
    {
        if (isset($s['label']) && is_string($s['label']) && trim($s['label']) !== '') {
            $cfg['label'] = mb_substr(trim($s['label']), 0, 80);
        }
        if (isset($s['title']) && is_string($s['title']) && trim($s['title']) !== '') {
            $cfg['title'] = mb_substr(trim($s['title']), 0, 80);
        }
        if (isset($s['icon']) && is_string($s['icon'])) {
            $cfg['icon'] = mb_substr($s['icon'], 0, 64);
        }
        foreach (['visible', 'mobile', 'tablet', 'desktop', 'show_title'] as $bk) {
            if (array_key_exists($bk, $s)) {
                $cfg[$bk] = filter_var($s[$bk], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (isset($s['order'])) {
            $cfg['order'] = (int)melkinoFdEnDigits($s['order']);
        }
        if (isset($s['digits']) && in_array((string)$s['digits'], ['fa', 'en', 'auto'], true)) {
            $cfg['digits'] = (string)$s['digits'];
        }
        return $cfg;
    }
}

if (!function_exists('melkinoFdOrderedSections')) {
    /** بخش‌های فعال صفحهٔ جزئیات به ترتیب تنظیم‌شده */
    function melkinoFdOrderedSections(array $config): array
    {
        $out = [];
        foreach ($config['sections'] as $key => $s) {
            if (!empty($s['visible'])) {
                $out[$key] = $s;
            }
        }
        uasort($out, static fn($a, $b) => ((int)$a['order']) <=> ((int)$b['order']));
        return $out;
    }
}

if (!function_exists('melkinoFdSpecLabelOrder')) {
    /**
     * لیبل‌های فعال بخش مشخصات برای یک نوع ملک، به ترتیب تنظیم‌شده.
     * خروجی: [ [origLabel, cfg], ... ]
     */
    function melkinoFdSpecLabelOrder(array $config, string $propertyType): array
    {
        if ($propertyType === 'مغازه') {
            $propertyType = 'تجاری';
        }
        if (mb_strpos($propertyType, 'ویلا') !== false) {
            $propertyType = 'ویلا';
        }
        $specs = $config['specs'][$propertyType]
            ?? $config['specs']['ویلایی']
            ?? [];
        if ($specs === [] && mb_strpos($propertyType, 'ویلا') !== false) {
            $specs = $config['specs']['ویلا'] ?? [];
        }
        $out = [];
        if ($specs === []) {
            $labels = function_exists('melkinoPdTypeSpecOrder')
                ? melkinoPdTypeSpecOrder($propertyType)
                : [];
            $i = 10;
            foreach ($labels as $lab) {
                $out[] = [$lab, ['visible' => true, 'order' => $i, 'label' => $lab]];
                $i += 10;
            }
            return $out;
        }
        foreach ($specs as $origLabel => $s) {
            if (!empty($s['visible'])) {
                $out[] = [$origLabel, $s];
            }
        }
        usort($out, static fn($a, $b) => ((int)$a[1]['order']) <=> ((int)$b[1]['order']));
        return $out;
    }
}

/* =====================================================
   اعتبارسنجی، ذخیره، تاریخچه و بازگردانی
===================================================== */

if (!function_exists('melkinoFdValidateHome')) {
    /** اعتبارسنجی payload کارت اصلی — خروجی: فهرست خطاهای دقیق */
    function melkinoFdValidateHome($payload): array
    {
        $errors = [];
        if (!is_array($payload) || !is_array($payload['fields'] ?? null)) {
            return ['ساختار تنظیمات نامعتبر است (fields).'];
        }
        $defs = melkinoFdHomeDefs();
        $orders = [];
        foreach ($payload['fields'] as $key => $f) {
            if (!isset($defs[$key])) {
                // کلید قدیمی/حذف‌شده را رد نکن — نادیده گرفته می‌شود تا ذخیره نشکند
                continue;
            }
            if (!is_array($f)) {
                $errors[] = 'مقدار فیلد نامعتبر: ' . $key;
                continue;
            }
            if (isset($f['label']) && (!is_string($f['label']) || mb_strlen($f['label']) > 80)) {
                $errors[] = 'عنوان «' . $key . '» باید رشتهٔ حداکثر ۸۰ نویسه باشد.';
            }
            if (isset($f['icon']) && !is_string($f['icon'])) {
                $errors[] = 'آیکون «' . $key . '» نامعتبر است.';
            }
            if (isset($f['mode']) && !in_array((string)$f['mode'], ['pill', 'text'], true)) {
                $errors[] = 'حالت نمایش «' . $key . '» باید pill یا text باشد.';
            }
            if (isset($f['digits']) && !in_array((string)$f['digits'], ['fa', 'en', 'auto'], true)) {
                $errors[] = 'فرمت ارقام «' . $key . '» نامعتبر است.';
            }
            if (isset($f['order'])) {
                $o = melkinoFdEnDigits($f['order']);
                if (!ctype_digit((string)$o)) {
                    $errors[] = 'ترتیب «' . $key . '» باید عدد باشد.';
                } else {
                    $orders[$key] = (int)$o;
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('melkinoFdValidateDetails')) {
    function melkinoFdValidateDetails($payload): array
    {
        $errors = [];
        if (!is_array($payload) || !is_array($payload['config'] ?? null)) {
            return ['ساختار تنظیمات نامعتبر است (config).'];
        }
        $cfg = $payload['config'];
        $secDefs = melkinoFdDetailsSectionDefs();
        $fieldDefs = melkinoFdDetailsFieldDefs();
        $specLabels = array_keys(melkinoPdSpecDefinitions());

        if (is_array($cfg['sections'] ?? null)) {
            foreach ($cfg['sections'] as $key => $s) {
                if (!isset($secDefs[$key])) { $errors[] = 'بخش نامعتبر: ' . $key; continue; }
                if (!is_array($s)) { $errors[] = 'تنظیم بخش نامعتبر: ' . $key; continue; }
                if (isset($s['title']) && mb_strlen((string)$s['title']) > 80) { $errors[] = 'عنوان بخش «' . $key . '» خیلی طولانی است.'; }
            }
        } else {
            $errors[] = 'بخش‌ها (sections) ارسال نشده‌اند.';
        }
        if (is_array($cfg['fields'] ?? null)) {
            foreach ($cfg['fields'] as $sec => $list) {
                if (!isset($fieldDefs[$sec])) { $errors[] = 'گروه فیلد نامعتبر: ' . $sec; continue; }
                foreach ((array)$list as $key => $f) {
                    if (!isset($fieldDefs[$sec][$key])) { $errors[] = 'فیلد نامعتبر: ' . $sec . '.' . $key; }
                }
            }
        }
        if (is_array($cfg['specs'] ?? null)) {
            foreach ($cfg['specs'] as $type => $list) {
                if (!is_array($list)) { $errors[] = 'مشخصات نوع «' . $type . '» نامعتبر است.'; continue; }
                foreach (array_keys($list) as $label) {
                    if (!in_array((string)$label, $specLabels, true)) {
                        $errors[] = 'لیبل مشخصات نامعتبر: ' . $label;
                    }
                }
            }
        }
        // ترتیب تکراری داخل هر گروه
        foreach ([ 'sections' ] as $grp) {
            $orders = [];
            foreach ((array)($cfg[$grp] ?? []) as $key => $s) {
                if (isset($s['order'])) {
                    $o = (int)melkinoFdEnDigits($s['order']);
                    if (in_array($o, $orders, true)) { $errors[] = 'ترتیب تکراری در بخش‌ها: ' . $key; }
                    $orders[] = $o;
                }
            }
        }
        return $errors;
    }
}

if (!function_exists('melkinoFdSave')) {
    /**
     * ذخیرهٔ تنظیمات با تاریخچه (۵ نسخهٔ اخیر) و متادیتا.
     * بازگشت: ['success'=>bool,'errors'=>[],'meta'=>?]
     */
    function melkinoFdSave(string $target, array $payload, ?int $adminId = null, string $adminName = ''): array
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
            return ['success' => false, 'errors' => ['اتصال دیتابیس برای ذخیرهٔ تنظیمات در دسترس نیست.']];
        }
        $errors = $target === 'details' ? melkinoFdValidateDetails($payload) : melkinoFdValidateHome($payload);
        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        $key = $target === 'details' ? 'details_display' : 'home_card_fields';

        // نرمال‌سازی نهایی از مسیر merge (فقط کلیدهای معتبر ذخیره می‌شوند)
        if ($target === 'details') {
            $clean = ['config' => melkinoFdDetailsSettingsMergeForSave($payload['config'])];
        } else {
            $clean = ['fields' => melkinoFdMergeFields(melkinoFdHomeDefaults(), $payload['fields'])];
        }

        $prev = melkinoFdStored($target);
        $history = is_array($prev['history'] ?? null) ? $prev['history'] : [];
        if (!empty($prev) && isset($prev['meta'])) {
            $snap = $prev;
            unset($snap['history']);
            array_unshift($history, ['meta' => $prev['meta'], 'snapshot' => $snap]);
            $history = array_slice($history, 0, 3);
        }

        $clean['meta'] = [
            'updated_at'    => date('Y-m-d H:i:s'),
            'updated_at_fa' => '',
            'updated_by'    => $adminName !== '' ? $adminName : ($adminId ? 'admin#' . $adminId : 'admin'),
            'admin_id'      => $adminId,
        ];
        $clean['history'] = $history;

        try {
            $ok = (bool)dbSettingSet($pdo, 'global', $key, $clean, 'json', $adminId);
        } catch (Throwable $e) {
            return ['success' => false, 'errors' => ['دیتابیس: ' . $e->getMessage()], 'meta' => $clean['meta']];
        }
        return ['success' => $ok, 'errors' => $ok ? [] : ['ذخیره در دیتابیس ناموفق بود.'], 'meta' => $clean['meta']];
    }
}

if (!function_exists('melkinoFdDetailsSettingsMergeForSave')) {
    /** payload ارسالی پنل را با پیش‌فرض‌ها ادغام می‌کند تا فقط ساختار معتبر ذخیره شود */
    function melkinoFdDetailsSettingsMergeForSave(array $in): array
    {
        $defaults = melkinoFdDetailsDefaults();
        foreach ($defaults['sections'] as $key => $cfg) {
            if (isset($in['sections'][$key]) && is_array($in['sections'][$key])) {
                $defaults['sections'][$key] = melkinoFdMergeSection($cfg, $in['sections'][$key]);
            }
        }
        foreach ($defaults['fields'] as $sec => $list) {
            foreach ($list as $key => $cfg) {
                if (isset($in['fields'][$sec][$key]) && is_array($in['fields'][$sec][$key])) {
                    $defaults['fields'][$sec][$key] = melkinoFdMergeSection($cfg, $in['fields'][$sec][$key]);
                }
            }
        }
        foreach ($defaults['specs'] as $type => $list) {
            foreach ($list as $label => $cfg) {
                if (isset($in['specs'][$type][$label]) && is_array($in['specs'][$type][$label])) {
                    $defaults['specs'][$type][$label] = melkinoFdMergeSection($cfg, $in['specs'][$type][$label]);
                }
            }
        }
        return $defaults;
    }
}

if (!function_exists('melkinoFdRestore')) {
    /** بازگردانی آخرین تنظیمات ذخیره‌شده (از تاریخچه) */
    function melkinoFdRestore(string $target, ?int $adminId = null): array
    {
        global $pdo;
        $prev = melkinoFdStored($target);
        $history = is_array($prev['history'] ?? null) ? $prev['history'] : [];
        if (!$history) {
            return ['success' => false, 'message' => 'نسخهٔ قبلی برای بازگردانی وجود ندارد.'];
        }
        $snapshot = $history[0]['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return ['success' => false, 'message' => 'نسخهٔ قبلی معتبر نیست.'];
        }
        array_shift($history);
        $snapshot['history'] = $history;
        $snapshot['meta'] = [
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => 'بازگردانی توسط ' . ($adminId ? 'admin#' . $adminId : 'admin'),
            'admin_id'   => $adminId,
            'restored'   => true,
        ];
        $key = $target === 'details' ? 'details_display' : 'home_card_fields';
        $ok = (bool)dbSettingSet($pdo, 'global', $key, $snapshot, 'json', $adminId);
        return ['success' => $ok, 'message' => $ok ? 'آخرین تنظیمات بازگردانی شد.' : 'بازگردانی ناموفق بود.'];
    }
}

if (!function_exists('melkinoFdPreviewSettings')) {
    /**
     * تنظیمات فعال یک هدف با پشتیبانی از draft پیش‌نمایش پنل:
     * فقط برای ادمین لاگین‌شده و فقط وقتی draft در session باشد.
     */
    function melkinoFdPreviewSettings(string $target): array
    {
        $settings = $target === 'details' ? melkinoFdDetailsSettings() : melkinoFdHomeSettings();
        $isPreview = isset($_GET['fd_preview']) && $_GET['fd_preview'] === '1';
        if ($isPreview && !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
            $draft = $_SESSION['fd_preview'][$target] ?? null;
            if (is_array($draft)) {
                if ($target === 'details') {
                    $settings['config'] = melkinoFdDetailsSettingsMergeForSave($draft);
                } else {
                    $settings['fields'] = melkinoFdMergeFields(melkinoFdHomeDefaults(), $draft);
                }
                $settings['preview'] = true;
            }
        }
        return $settings;
    }
}

if (!function_exists('melkinoFdApplySpecConfig')) {
    /**
     * اعمال تنظیمات بخش مشخصات روی خروجی buildPublicSpecs:
     * ترتیب، لیبل سفارشی، آیکون (پیشوند مقدار) و کلاس‌های ریسپانسیو.
     * لیبل‌های خارج از کاتالوگ (مثل «معاوضه») بدون تغییر در انتها می‌مانند.
     *
     * @param array $specs  لیبل => مقدار (خروجی buildPublicSpecs)
     * @param array $ordered [['origLabel', cfg], ...] از melkinoFdSpecLabelOrder
     * @return array [specs مرتب‌شده, meta[لیبل]=>کلاس ریسپانسیو]
     */
    function melkinoFdApplySpecConfig(array $specs, array $ordered): array
    {
        $out = [];
        $meta = [];
        $seen = [];
        foreach ($ordered as $pair) {
            [$orig, $cfg] = $pair;
            $seen[$orig] = true;
            if (!array_key_exists($orig, $specs)) {
                continue; // مقدار خالی → نمایش داده نمی‌شود (بدون فضای خالی)
            }
            $newLabel = trim((string)($cfg['label'] ?? ''));
            if ($newLabel === '') {
                $newLabel = (string)$orig;
            }
            $final = $newLabel;
            $n = 2;
            while (array_key_exists($final, $out)) {
                $final = $newLabel . ' (' . $n++ . ')';
            }
            $val = (string)$specs[$orig];
            $out[$final] = $val;
            $rc = melkinoFdRclass(is_array($cfg) ? $cfg : []);
            if ($rc !== '') {
                $meta[$final] = $rc;
            }
        }
        // لیبل‌های پویا (معاوضه و…) که در کاتالوگ نیستند — حفظ رفتار فعلی
        foreach ($specs as $label => $val) {
            if (isset($seen[$label]) || array_key_exists($label, $out)) {
                continue;
            }
            $out[$label] = $val;
        }
        return [$out, $meta];
    }
}
