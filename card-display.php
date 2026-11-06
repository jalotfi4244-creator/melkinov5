<?php
/*
|--------------------------------------------------------------------------
| تنظیمات نمایش کارت‌های آگهی (راند ۲۰/۲۱)
|--------------------------------------------------------------------------
| دو گروه تنظیم:
|  1) main  — عناصر اصلی کارت (عنوان/کد/قیمت/موقعیت/تاریخ/خط وام):
|             فقط نمایش یا مخفی (bool).
|  2) specs — همهٔ فیلدهای مشخصاتی که یک آگهی می‌تواند داشته باشد
|             (عمومی + ۳۹ فیلد اختصاصی انواع ملک با پیشوند pd.):
|             سه حالت 'text' (چیپ متنی) | 'pill' (حباب) | 'off' (مخفی).
|
| ذخیره در db_settings: گروه global، کلید card_display (JSON).
| scope: home = فقط کارت صفحهٔ اصلی، list = فقط فهرست، both = هر دو.
| راند ۲۶: همهٔ فیلدهای مشخصات (specs) حالا scope=both دارند تا حباب‌ها
| (کلید نخورده و …) در صفحهٔ اصلی، فهرست همهٔ آگهی‌ها و صفحهٔ VIP یکسان
| طبق تنظیمات تب «نمایش» رندر شوند.
|--------------------------------------------------------------------------
*/

// کاتالوگ pd.* از تنظیمات انتشار خوانده می‌شود
require_once __DIR__ . '/bot-settings.php';

if (!function_exists('melkinoCardDisplayDefs')) {
    function melkinoCardDisplayDefs(): array
    {
        $specs = [
            'transaction'    => ['emoji' => '🏷️', 'label' => 'نوع معامله', 'default' => 'pill', 'scope' => 'both'],
            'property_type'  => ['emoji' => '🏠', 'label' => 'نوع ملک', 'default' => 'text', 'scope' => 'both'],
            'area'           => ['emoji' => '📐', 'label' => 'متراژ', 'default' => 'text', 'scope' => 'both'],
            'rooms'          => ['emoji' => '🛏️', 'label' => 'تعداد اتاق', 'default' => 'text', 'scope' => 'both'],
            'floor'          => ['emoji' => '🏢', 'label' => 'طبقه', 'default' => 'text', 'scope' => 'both'],
            'year'           => ['emoji' => '📅', 'label' => 'سال ساخت', 'default' => 'off', 'scope' => 'both'],
            'building_age'   => ['emoji' => '⏳', 'label' => 'سن بنا', 'default' => 'text', 'scope' => 'both'],
            'parking'        => ['emoji' => '🅿️', 'label' => 'پارکینگ', 'default' => 'text', 'scope' => 'both', 'help' => 'از امکانات آگهی (جدول amenities) خوانده می‌شود — آیکون تب نمایش روی چیپ اعمال می‌شود.'],
            'elevator'       => ['emoji' => '🛗', 'label' => 'آسانسور', 'default' => 'text', 'scope' => 'both', 'help' => 'از امکانات آگهی (جدول amenities) خوانده می‌شود — آیکون تب نمایش روی چیپ اعمال می‌شود.'],
            'key_not_turned' => ['emoji' => '🔑', 'label' => 'کلید نخورده', 'default' => 'pill', 'scope' => 'both'],
            'loan'           => ['emoji' => '🏦', 'label' => 'وام‌دار', 'default' => 'pill', 'scope' => 'both'],
            'exchange'       => ['emoji' => '🔄', 'label' => 'مایل به معاوضه', 'default' => 'pill', 'scope' => 'both'],
            'tags'           => ['emoji' => '🏅', 'label' => 'برچسب‌های آگهی', 'default' => 'pill', 'scope' => 'both'],
            'deed'           => ['emoji' => '📜', 'label' => 'نوع سند', 'default' => 'off', 'scope' => 'both'],
            'deposit'        => ['emoji' => '💵', 'label' => 'مبلغ رهن (ودیعه)', 'default' => 'off', 'scope' => 'both'],
            'rent_monthly'   => ['emoji' => '🗓️', 'label' => 'اجارهٔ ماهانه', 'default' => 'off', 'scope' => 'both'],
            'full_rent'      => ['emoji' => '🔐', 'label' => 'رهن کامل', 'default' => 'off', 'scope' => 'both'],
        ];

        // همهٔ فیلدهای اختصاصی انواع ملک (pd.*) از کاتالوگ انتشار —
        // با همان ایموجی/لیبل/پسوند واحد، پیش‌فرض مخفی.
        if (function_exists('melkinoPublishFieldDefs')) {
            foreach (melkinoPublishFieldDefs() as $key => $def) {
                if (strpos($key, 'pd.') === 0) {
                    $commOn = in_array($key, [
                        'pd.front', 'pd.wall', 'pd.flooring', 'pd.cabinet',
                        'pd.cooling', 'pd.heating', 'pd.location_type',
                        'pd.location_features', 'pd.jobs',
                    ], true);
                    $specs[$key] = [
                        'emoji'   => $def['emoji'] ?? '🔹',
                        'label'   => $def['label'] ?? $key,
                        'suffix'  => $def['suffix'] ?? '',
                        'default' => $commOn ? 'text' : 'off',
                        'scope'   => 'both',
                    ];
                }
            }
        }

        return [
            'main' => [
                'label' => '🧱 عناصر اصلی کارت',
                'help'  => 'این عناصر ساختار کارت‌اند؛ فقط نمایش داده می‌شوند یا مخفی.',
                'items' => [
                    'title'           => ['emoji' => '📰', 'label' => 'عنوان آگهی', 'default' => true, 'scope' => 'both'],
                    'code'            => ['emoji' => '🔢', 'label' => 'کد آگهی', 'default' => true, 'scope' => 'list'],
                    'price'           => ['emoji' => '💰', 'label' => 'قیمت', 'default' => true, 'scope' => 'both'],
                    'loan_price_line' => ['emoji' => '🏦', 'label' => 'خط قیمت نقد + وام', 'default' => true, 'scope' => 'home'],
                    'location'        => ['emoji' => '📍', 'label' => 'موقعیت', 'default' => true, 'scope' => 'both'],
                    'date'            => ['emoji' => '🗓️', 'label' => 'تاریخ ثبت', 'default' => true, 'scope' => 'home'],
                    'details_link'    => ['emoji' => '🔍', 'label' => 'لینک مشاهدهٔ جزئیات', 'default' => true, 'scope' => 'both'],
                ],
            ],
            'specs' => [
                'label' => '🎛 فیلدهای مشخصات — متن / حباب / مخفی',
                'help'  => 'هر فیلدی که آگهی مقدارش را داشته باشد، طبق حالت انتخابی روی کارت می‌آید: «متن» = چیپ ساده، «حباب» = بج رنگی. فیلدهای pd.* مخصوص نوع ملک هستند (زمین/باغ/تجاری/…) و فقط وقتی آن آگهی مقدارشان را داشته باشد نمایش داده می‌شوند.',
                'items' => $specs,
            ],
        ];
    }
}

if (!function_exists('melkinoCardDisplaySettings')) {
    /** نقشهٔ تخت key => ('pill'|'text'|'off'|true|false) ادغام‌شده با پیش‌فرض‌ها */
    function melkinoCardDisplaySettings(): array
    {
        global $pdo;
        $defs = melkinoCardDisplayDefs();
        $settings = [];
        foreach ($defs as $groupName => $group) {
            foreach ($group['items'] as $key => $item) {
                $settings[$key] = $item['default'];
            }
        }
        try {
            if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
                $raw = dbSettingGet($pdo, 'global', 'card_display', '');
                // راند ۵۸: مقدار با type=json ذخیره می‌شود پس dbSettingGet آرایه برمی‌گرداند؛
                // هر دو شکل (آرایهٔ آماده / رشتهٔ JSON) پشتیبانی می‌شود.
                if (is_array($raw)) {
                    $decoded = $raw;
                } elseif (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                } else {
                    $decoded = null;
                }
                if (is_array($decoded)) {
                        // تبدیل تنظیمات نسخهٔ قبل (badge_*) به کلیدهای جدید
                        $legacyMap = [
                            'badge_transaction' => 'transaction', 'badge_property_type' => 'property_type',
                            'badge_key_not_turned' => 'key_not_turned', 'badge_loan' => 'loan',
                            'badge_exchange' => 'exchange', 'badge_tags' => 'tags',
                        ];
                        foreach ($legacyMap as $oldKey => $newKey) {
                            if (array_key_exists($oldKey, $decoded) && !array_key_exists($newKey, $decoded)) {
                                $decoded[$newKey] = $decoded[$oldKey];
                            }
                        }
                        foreach ($decoded as $key => $val) {
                            if (!array_key_exists($key, $settings)) {
                                continue;
                            }
                            // فیلدهای specs سه‌حالته‌اند؛ bool قدیمی → text/off
                            if (isset($defs['specs']['items'][$key]) && is_bool($val)) {
                                $val = $val ? 'text' : 'off';
                            }
                            $settings[$key] = $val;
                        }
                }
            }
        } catch (Throwable $e) {
            // در خطا، پیش‌فرض‌ها برمی‌گردند
        }
        return $settings;
    }
}

if (!function_exists('melkinoCardMode')) {
    /** حالت یک فیلد مشخصات: 'pill' | 'text' | 'off' */
    function melkinoCardMode(array $settings, string $key): string
    {
        $val = $settings[$key] ?? null;
        if ($val === true) return 'pill';
        if ($val === false || $val === null) return 'off';
        $val = (string)$val;
        return in_array($val, ['pill', 'text', 'off'], true) ? $val : 'off';
    }
}

if (!function_exists('melkinoCardShow')) {
    /** آیا یک عنصر اصلی کارت نمایش داده شود؟ */
    function melkinoCardShow(array $settings, string $key): bool
    {
        $val = $settings[$key] ?? true;
        if (is_string($val)) {
            return !in_array($val, ['off', '', '0'], true);
        }
        return (bool)$val;
    }
}

if (!function_exists('melkinoCardDecodeDetails')) {
    function melkinoCardDecodeDetails(array $ad): array
    {
        $raw = $ad['property_details'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }
}

if (!function_exists('melkinoCardMoney')) {
    function melkinoCardMoney($n): string
    {
        $n = (float)$n;
        if ($n <= 0) return '';
        return number_format($n) . ' تومان';
    }
}

if (!function_exists('melkinoCardFieldValue')) {
    /**
     * مقدار نمایشی یک فیلد مشخصات برای کارت (بدون ایموجی/لیبل) یا null.
     */
    function melkinoCardFieldValue(array $ad, string $key): ?string
    {
        $details = melkinoCardDecodeDetails($ad);
        $hd = is_array($ad['details'] ?? null) ? $ad['details'] : [];

        $pick = function (array $sources) use ($details, $hd, $ad) {
            foreach ($sources as $src) {
                $val = null;
                if (is_array($src)) {
                    foreach ($src as $v) {
                        if (is_array($v)) {
                            $v = implode('، ', array_filter(array_map('strval', $v), static fn($x) => trim($x) !== ''));
                        }
                        $v = trim((string)$v);
                        if ($v !== '' && $v !== '0' && $v !== '۰') {
                            $val = $v;
                            break;
                        }
                    }
                } else {
                    $v = trim((string)$src);
                    if ($v !== '' && $v !== '0' && $v !== '۰') {
                        $val = $v;
                    }
                }
                if ($val !== null) {
                    return $val;
                }
            }
            return '';
        };

        switch ($key) {
            case 'transaction':
                $t = trim((string)($ad['transaction_type'] ?? ''));
                if ($t === '') return null;
                // راند ۲۷: روی کارت فقط خودِ نوع معامله نمایش داده می‌شود («فروش»، نه «خرید و فروش»)
                $labels = ['پیش فروش' => 'پیش‌فروش'];
                return $labels[$t] ?? $t;
            case 'property_type':
                $v = trim((string)($ad['property_type'] ?? ''));
                return $v === '' ? null : $v;
            case 'area':
                $v = $pick([
                    [$hd['area'] ?? '', $details['area'] ?? '', $details['area_apt'] ?? '', $details['area_comm'] ?? '',
                     $details['office_area'] ?? '', $details['land_area'] ?? '', $details['garden_area'] ?? '',
                     $details['built_area'] ?? '', $ad['area'] ?? ''],
                ]);
                return $v === '' ? null : $v . ' متر';
            case 'rooms':
                $v = $pick([[$hd['rooms'] ?? '', $details['rooms'] ?? '', $details['rooms_apt'] ?? '',
                             $details['rooms_villa'] ?? '', $details['office_rooms'] ?? '']]);
                return $v === '' ? null : $v . ' اتاق';
            case 'floor':
                $v = $pick([[$details['floor'] ?? '', $details['floor_apt'] ?? '', $details['office_floor'] ?? '', $hd['floor'] ?? '']]);
                return $v === '' ? null : 'طبقه ' . $v;
            case 'year':
                $v = $pick([[$details['year'] ?? '', $details['year_apt'] ?? '', $details['year_villa'] ?? '', $details['office_year'] ?? '', $hd['year'] ?? '']]);
                return $v === '' ? null : 'ساخت ' . $v;
            case 'building_age':
                $yr = $pick([[$details['year'] ?? '', $details['year_apt'] ?? '', $details['year_villa'] ?? '', $details['office_year'] ?? '', $hd['year'] ?? '', $ad['year'] ?? '']]);
                if ($yr === '' && function_exists('melkinoBuildingAgeDisplay')) {
                    $yr = (string)($ad['year'] ?? '');
                }
                if (function_exists('melkinoBuildingAgeDisplay')) {
                    $age = melkinoBuildingAgeDisplay($yr !== '' ? $yr : ($ad['building_age'] ?? ''));
                    if ($age === '' && isset($ad['building_age']) && $ad['building_age'] !== '' && $ad['building_age'] !== null) {
                        $age = function_exists('melkinoFaDigits') ? melkinoFaDigits((string)$ad['building_age']) : (string)$ad['building_age'];
                    }
                    return $age === '' ? null : $age . ' سال';
                }
                return null;
            case 'parking':
                return melkinoCardHasAmenity($ad, ['پارکینگ', 'parking']) ? 'پارکینگ' : null;
            case 'elevator':
                return melkinoCardHasAmenity($ad, ['آسانسور', 'elevator']) ? 'آسانسور' : null;
            case 'key_not_turned':
                return !empty($ad['is_not_keyed']) ? 'کلید نخورده' : null;
            case 'loan':
                $loan = function_exists('melkinoLoanInfo') ? melkinoLoanInfo($ad) : ['has' => false];
                return !empty($loan['has']) ? 'وام' : null;
            case 'exchange':
                return !empty($ad['exchange_interested']) ? 'مایل به معاوضه' : null;
            case 'tags':
                return null; // در رندرر جداگانه مدیریت می‌شود (چند حباب)
            case 'deed':
                $v = $pick([[$ad['deed_type'] ?? '', $details['document_type'] ?? '', $details['land_deed_type'] ?? '']]);
                return $v === '' ? null : $v;
            case 'deposit':
                $v = melkinoCardMoney($ad['deposit'] ?? 0);
                return $v === '' ? null : 'رهن ' . $v;
            case 'rent_monthly':
                $v = melkinoCardMoney($ad['rent_monthly'] ?? 0);
                return $v === '' ? null : 'اجاره ' . $v;
            case 'full_rent':
                if (empty($ad['full_rent_enabled'])) return null;
                $v = melkinoCardMoney($ad['full_rent'] ?? 0);
                return $v === '' ? null : 'رهن کامل ' . $v;
        }

        // فیلدهای اختصاصی pd.*
        if (strpos($key, 'pd.') === 0) {
            $dk = substr($key, 3);
            $alias = [
                'flooring' => ['flooring', 'floor_comm', 'floor_covering'],
                'wall' => ['wall', 'wall_comm', 'wall_covering'],
                'cabinet' => ['cabinet', 'cabinet_comm'],
                'cooling' => ['cooling', 'cooling_comm', 'cooling_system'],
                'heating' => ['heating', 'heating_comm', 'heating_system'],
                'front' => ['front', 'front_comm', 'front_width'],
                'location_type' => ['location_type', 'orientation_comm'],
                'location_features' => ['location_features'],
                'jobs' => ['jobs', 'jobs_comm', 'usage_comm'],
            ];
            $val = '';
            foreach ($alias[$dk] ?? [$dk] as $ak) {
                if (isset($details[$ak]) && $details[$ak] !== '' && $details[$ak] !== null) {
                    $val = $details[$ak];
                    break;
                }
            }
            if ($val === '') {
                $val = $details[$dk] ?? '';
            }
            if (is_array($val)) {
                $val = implode('، ', array_filter(array_map('strval', $val), static fn($x) => trim($x) !== ''));
            }
            $val = trim((string)$val);
            if (strpos($dk, 'has_') === 0) {
                return $val === '1' ? 'دارد' : null;
            }
            if ($val === '' || $val === '0' || $val === '۰') {
                return null;
            }
            $defs = melkinoCardDisplayDefs();
            $suffix = (string)($defs['specs']['items'][$key]['suffix'] ?? '');
            return $val . $suffix;
        }

        return null;
    }
}

if (!function_exists('melkinoCardHasAmenity')) {
    function melkinoCardHasAmenity(array $ad, array $needles): bool
    {
        $amenities = $ad['amenities'] ?? [];
        if (is_string($amenities)) {
            $decoded = json_decode($amenities, true);
            $amenities = is_array($decoded) ? $decoded : [$amenities];
        }
        if (!is_array($amenities)) {
            return false;
        }
        foreach ($amenities as $a) {
            if (is_array($a)) {
                $a = $a['name'] ?? ($a['title'] ?? '');
            }
            $a = trim((string)$a);
            foreach ($needles as $n) {
                if ($a !== '' && $n !== '' && mb_strpos($a, $n) !== false) {
                    return true;
                }
            }
        }
        // ستون/جزئیات بولین (بعضی آگهی‌ها امکانات را این‌طور ذخیره می‌کنند)
        foreach (['parking' => ['پارکینگ', 'parking'], 'elevator' => ['آسانسور', 'elevator']] as $boolKey => $names) {
            $hit = false;
            foreach ($needles as $n) {
                if (in_array($n, $names, true)) { $hit = true; break; }
            }
            if (!$hit) continue;
            $v = $ad[$boolKey] ?? ($ad['has_' . $boolKey] ?? null);
            if ($v === true || $v === 1 || $v === '1') return true;
            $details = $ad['property_details'] ?? $ad['details'] ?? [];
            if (is_string($details)) {
                $decoded = json_decode($details, true);
                $details = is_array($decoded) ? $decoded : [];
            }
            if (is_array($details)) {
                $v2 = $details[$boolKey] ?? ($details['has_' . $boolKey] ?? null);
                if ($v2 === true || $v2 === 1 || $v2 === '1') return true;
            }
        }
        return false;
    }
}

if (!function_exists('melkinoCardSpecItems')) {
    /**
     * فهرست آیتم‌های مشخصات آمادهٔ رندر برای یک آگهی.
     * خروجی: [['key','mode','value','class'], ...] به ترتیب کاتالوگ.
     */
    function melkinoCardSpecItems(array $ad, array $settings, string $scope): array
    {
        $defs = melkinoCardDisplayDefs()['specs']['items'];
        $specialClass = [
            'transaction' => 'transaction', 'property_type' => 'transaction',
            'key_not_turned' => 'key-not-turned', 'loan' => 'loan', 'exchange' => 'exchange',
        ];
        $items = [];
        foreach ($defs as $key => $def) {
            $itemScope = (string)($def['scope'] ?? 'both');
            if ($itemScope !== 'both' && $itemScope !== $scope) {
                continue;
            }
            $mode = melkinoCardMode($settings, $key);
            if ($mode === 'off') {
                continue;
            }
            if ($key === 'tags') {
                $tags = $ad['tags'] ?? [];
                if (is_string($tags)) {
                    $decoded = json_decode($tags, true);
                    $tags = is_array($decoded) ? $decoded : [];
                }
                foreach ((array)$tags as $tag) {
                    $tag = trim((string)$tag);
                    if ($tag !== '') {
                        $items[] = ['key' => 'tags', 'mode' => $mode, 'label' => 'برچسب', 'value' => $tag, 'class' => ''];
                    }
                }
                continue;
            }
            $value = melkinoCardFieldValue($ad, $key);
            if ($value === null || $value === '') {
                continue;
            }
            $items[] = [
                'key'   => $key,
                'mode'  => $mode,
                'label' => (string)($def['label'] ?? $key),
                'emoji' => (string)($def['emoji'] ?? ''),
                'value' => $value,
                'class' => $specialClass[$key] ?? 'spec',
            ];
        }
        return $items;
    }
}

if (!function_exists('melkinoSaveCardDisplay')) {
    function melkinoSaveCardDisplay(array $map): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
            return false;
        }
        $clean = [];
        foreach (melkinoCardDisplayDefs() as $groupName => $group) {
            foreach ($group['items'] as $key => $item) {
                if (!array_key_exists($key, $map)) {
                    $clean[$key] = $item['default'];
                    continue;
                }
                if ($groupName === 'specs') {
                    $val = (string)$map[$key];
                    $clean[$key] = in_array($val, ['pill', 'text', 'off'], true) ? $val : $item['default'];
                } else {
                    $val = $map[$key];
                    if (is_string($val)) {
                        $val = !in_array($val, ['off', '', '0'], true);
                    }
                    $clean[$key] = (bool)$val;
                }
            }
        }
        $ok = (bool)dbSettingSet(
            $pdo,
            'global',
            'card_display',
            json_encode($clean, JSON_UNESCAPED_UNICODE)
        );
        return $ok;
    }
}



if (!function_exists('melkinoFdPushHomeToCardDisplay')) {
    /**
     * راند ۷۸: ذخیرهٔ تب «کارت صفحهٔ اصلی» باید همان card_display را هم به‌روز کند
     * تا رندرر واقعی (ad-cards.js) تغییرات را نشان دهد.
     */
    function melkinoFdPushHomeToCardDisplay(array $fields): void
    {
        $map = function_exists('melkinoCardDisplaySettings') ? melkinoCardDisplaySettings() : [];
        $defs = melkinoCardDisplayDefs();
        foreach ($fields as $key => $f) {
            if (!is_array($f)) {
                continue;
            }
            $visible = !empty($f['visible']);
            if (isset($defs['specs']['items'][$key])) {
                if (!$visible) {
                    $map[$key] = 'off';
                } else {
                    $mode = (string)($f['mode'] ?? 'text');
                    $map[$key] = in_array($mode, ['pill', 'text'], true) ? $mode : 'text';
                }
            } elseif (isset($defs['main']['items'][$key])) {
                $map[$key] = $visible;
            }
        }
        if (function_exists('melkinoSaveCardDisplay')) {
            melkinoSaveCardDisplay($map);
        }
    }
}

if (!function_exists('melkinoCardDisplayPushToFdHome')) {
    /** ذخیرهٔ ساب‌تب «کارت فهرست/VIP» → هم‌تراز کردن visible/mode در home_card_fields */
    function melkinoCardDisplayPushToFdHome(array $map): void
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
            return;
        }
        if (!function_exists('melkinoFdStored')) {
            $fdFile = __DIR__ . '/field-display.php';
            if (is_file($fdFile)) {
                require_once $fdFile;
            }
        }
        if (!function_exists('melkinoFdStored') || !function_exists('melkinoFdMergeFields')) {
            return;
        }
        $stored = melkinoFdStored('home');
        $fields = melkinoFdMergeFields(melkinoFdHomeDefaults(), $stored['fields'] ?? []);
        $defs = melkinoCardDisplayDefs();
        foreach ($map as $key => $val) {
            if (!isset($fields[$key])) {
                continue;
            }
            if (isset($defs['specs']['items'][$key])) {
                $mode = is_string($val) ? $val : ($val ? 'text' : 'off');
                if (!in_array($mode, ['pill', 'text', 'off'], true)) {
                    $mode = 'text';
                }
                $fields[$key]['mode'] = $mode === 'off' ? 'text' : $mode;
                $fields[$key]['visible'] = $mode !== 'off';
            } elseif (isset($defs['main']['items'][$key])) {
                $on = $val;
                if (is_string($on)) {
                    $on = !in_array($on, ['off', '', '0'], true);
                }
                $fields[$key]['visible'] = (bool)$on;
            }
        }
        $stored['fields'] = $fields;
        dbSettingSet($pdo, 'global', 'home_card_fields', $stored, 'json');
    }
}


if (!function_exists('melkinoCardDisplayFrontendPayload')) {
    /**
     * @param string $scope  home = کارت صفحهٔ اصلی | list = فهرست آگهی‌ها و VIP
     * تنظیمات این دو صفحه جدا هستند و روی هم نوشته نمی‌شوند.
     */
    function melkinoCardDisplayFrontendPayload(string $scope = 'list'): array
    {
        $cd = melkinoCardDisplaySettings();
        $defs = melkinoCardDisplayDefs();
        $specs = $defs['specs']['items'] ?? [];
        $order = 0;
        foreach ($specs as $k => &$it) {
            $it['show_icon'] = true;
            $it['show_label'] = strpos((string)$k, 'pd.') === 0;
            $it['order'] = $order++;
        }
        unset($it);

        if ($scope !== 'home') {
            return ['settings' => (object)$cd, 'specs' => (object)$specs];
        }

        if (!function_exists('melkinoFdHomeSettings')) {
            $fdFile = __DIR__ . '/field-display.php';
            if (is_file($fdFile)) {
                require_once $fdFile;
            }
        }
        $fields = [];
        $previewDraft = null;
        if (isset($_GET['fd_preview']) && $_GET['fd_preview'] === '1'
            && !empty($_SESSION['is_admin']) && !empty($_SESSION['fd_preview']['home'])
            && is_array($_SESSION['fd_preview']['home'])) {
            $previewDraft = $_SESSION['fd_preview']['home'];
        }
        if (is_array($previewDraft)) {
            $fields = $previewDraft;
        } elseif (function_exists('melkinoFdHomeSettings')) {
            $fields = melkinoFdHomeSettings()['fields'] ?? [];
        }
        foreach ($fields as $k => $f) {
            if (!is_array($f)) {
                continue;
            }
            $visible = array_key_exists('visible', $f) ? !empty($f['visible']) : true;
            if (isset($specs[$k])) {
                if (!$visible) {
                    $cd[$k] = 'off';
                } else {
                    $mode = (string)($f['mode'] ?? 'text');
                    $cd[$k] = in_array($mode, ['pill', 'text'], true) ? $mode : 'text';
                }
                $icon = trim((string)($f['icon'] ?? ''));
                if ($icon !== '') {
                    $specs[$k]['emoji'] = $icon;
                    $specs[$k]['show_icon'] = true;
                } elseif (array_key_exists('show_icon', $f)) {
                    $specs[$k]['show_icon'] = !empty($f['show_icon']);
                }
                if (!empty($f['label'])) {
                    $specs[$k]['label'] = (string)$f['label'];
                }
                if (array_key_exists('show_label', $f)) {
                    $specs[$k]['show_label'] = !empty($f['show_label']);
                }
                if (isset($f['order'])) {
                    $specs[$k]['order'] = (int)$f['order'];
                }
            } elseif (isset($defs['main']['items'][$k]) || in_array($k, ['details_link', 'date', 'title', 'code', 'price', 'location', 'loan_price_line'], true)) {
                $cd[$k] = $visible;
            }
        }
        return ['settings' => (object)$cd, 'specs' => (object)$specs];
    }
}
