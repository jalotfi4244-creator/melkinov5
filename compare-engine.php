<?php
if (is_file(__DIR__ . '/jalali-lib.php')) {
    require_once __DIR__ . '/jalali-lib.php';
}
/*
|--------------------------------------------------------------------------
| موتور مقایسهٔ ملک‌ها — Property Comparison Engine (راند ۲۴)
|--------------------------------------------------------------------------
| اصول پیاده‌سازی (مطابق سند موتور مقایسهٔ ملکینو):
|   ۱. دیتابیس تنها منبع حقیقت است: فقط فیلدهایی که در دادهٔ ورودی وجود
|      دارند وارد امتیازدهی می‌شوند؛ هیچ معیار فرضی اختراع نمی‌شود.
|   ۲. موقعیت مکانی (شهر/منطقه/محله/خیابان/لوکیشن) هرگز امتیازدهی نمی‌شود؛
|      فقط برای نمایش در جدول اطلاعات نگه داشته می‌شود.
|   ۳. فیلدهای سیستمی/شناسه‌ای (id، کد، تاریخ ایجاد، بازدید، وضعیت، عکس…)
|      معیار امتیاز نیستند.
|   ۴. هر فیلد type و direction دارد (numeric/boolean + higher/lower)؛
|      فیلدهای categorical و context-dependent فقط نمایش داده می‌شوند
|      (مگر در قوانین سیستم جهت داشته باشند، مثل قیمت که lower است).
|   ۵. نبود داده = امتیاز صفر/منفی نیست؛ آن فیلد برای آن ملک «نامعلوم»
|      می‌ماند و از میانگین او خارج می‌شود (Unknown ≠ No).
|   ۶. امتیاز هر فیلد نسبی است (min-max بین همان ملک‌های انتخاب‌شده،
|      skala 0..10)؛ محدودهٔ ثابت فرضی استفاده نمی‌شود.
|   ۷. وزن‌ها از «قوانین سیستم» (melkinoCompareFieldRules) می‌آیند؛ فیلد
|      جدید دیتابیس فقط وقتی وارد امتیاز می‌شود که در قوانین مجاز باشد
|      (Dynamic Scoring).
|   ۸. خروجی: Overall / Value / Risk / Confidence + Breakdown شفاف +
|      تفاوت‌های اصلی + نقاط قوت/ضعف + معیارهای استفاده‌شده/بدون داده +
|      نتیجه‌گیری قابل ردیابی.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoCompareFieldRules')) {
    /**
     * قوانین سیستم (Weight/Rule Configuration): فقط همین فیلدها امتیاز دارند.
     * dir: higher = مقدار بیشتر بهتر | lower = مقدار کمتر بهتر
     * group: specs | price | legal
     */
    function melkinoCompareFieldRules(): array
    {
        return [
            // ----- مشخصات عمومی (ستونی) -----
            'area'         => ['label' => 'متراژ', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'rooms'        => ['label' => 'تعداد اتاق/خواب', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'plain' => true],
            'year'         => ['label' => 'سال ساخت (نوسازی)', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'plain' => true],
            'building_age' => ['label' => 'سن بنا', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'specs', 'plain' => true, 'unit' => ' سال'],
            'parking'      => ['label' => 'پارکینگ', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'elevator'     => ['label' => 'آسانسور', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'is_not_keyed' => ['label' => 'کلید نخورده', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            // ----- قیمت (فقط اگر در داده موجود باشد) -----
            'price_unit'   => ['label' => 'قیمت هر متر', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'price', 'money' => true],
            'price_total'  => ['label' => 'قیمت کل', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'price', 'money' => true],
            'full_rent'    => ['label' => 'رهن کامل', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'price', 'money' => true],
            'deposit'      => ['label' => 'ودیعه (رهن)', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'price', 'money' => true],
            'rent_monthly' => ['label' => 'اجارهٔ ماهانه', 'type' => 'numeric', 'dir' => 'lower', 'group' => 'price', 'money' => true],
            // ----- فیلدهای تخصصی مجاز (pd.*) -----
            'pd.total_units'        => ['label' => 'تعداد کل واحدهای ساختمان', 'type' => 'numeric', 'dir' => 'context', 'group' => 'specs', 'plain' => true],
            'pd.land_area'          => ['label' => 'متراژ زمین', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر مربع'],
            'pd.garden_area'        => ['label' => 'مساحت باغ', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر مربع'],
            'pd.building_area'      => ['label' => 'سطح زیربنای بنا', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر مربع'],
            'pd.office_area'        => ['label' => 'متراژ واحد اداری', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'pd.office_rooms'       => ['label' => 'تعداد اتاق اداری', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'plain' => true],
            'pd.office_units_per_floor' => ['label' => 'تعداد واحد در طبقه', 'type' => 'numeric', 'dir' => 'context', 'group' => 'specs', 'plain' => true],
            'pd.office_year'        => ['label' => 'سال ساخت اداری', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'plain' => true],
            'pd.land_width'         => ['label' => 'عرض زمین', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'pd.land_length'        => ['label' => 'طول زمین', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'pd.land_front_width'   => ['label' => 'عرض بر', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'pd.land_blocks'        => ['label' => 'تعداد بر', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'plain' => true],
            'pd.front'              => ['label' => 'بر مغازه', 'type' => 'numeric', 'dir' => 'higher', 'group' => 'specs', 'unit' => ' متر'],
            'pd.has_well'           => ['label' => 'آب ملکی (چاه)', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'pd.has_pond'           => ['label' => 'استخر/حوض', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'pd.has_building'       => ['label' => 'بنای روی ملک', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            // راند ۲۵: «نصب‌شده بودن» کابینت/سرمایش/گرمایش. دادهٔ این فیلدها
            // متنی است (مثلاً «ام دی اف»/«اسپیلیت») و مقدار «ندارد» = نصب‌نشده.
            'pd.cabinet'            => ['label' => 'کابینت نصب‌شده', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'pd.cooling'            => ['label' => 'سیستم سرمایش نصب‌شده', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
            'pd.heating'            => ['label' => 'سیستم گرمایش نصب‌شده', 'type' => 'boolean', 'dir' => 'higher', 'group' => 'specs'],
        ];
    }
}

if (!function_exists('melkinoCompareNum')) {
    /** تبدیل امن مبلغ/عدد (رقم فارسی و جداکننده‌ها هم پشتیبانی می‌شود) */
    function melkinoCompareNum($value): float
    {
        if (function_exists('melkinoPriceToNum')) {
            return melkinoPriceToNum($value);
        }
        $s = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،',',',' ','تومان'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','','','',''],
            trim((string)$value)
        );
        return is_numeric($s) ? (float)$s : 0.0;
    }
}

if (!function_exists('melkinoCompareBool')) {
    /**
     * مقدار بولین سه‌حالته: 1 / 0 / null(نامعلوم).
     * Unknown هرگز به معنی «ندارد» نیست.
     */
    function melkinoCompareBool($value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return 0;
        }
        return null;
    }
}

if (!function_exists('melkinoCompareInstalledBool')) {
    /**
     * راند ۲۵: تبدیل مقدار متنی «نوع کابینت/سرمایش/گرمایش» به بولینِ نصب‌شده:
     * «ندارد» (و مشابه‌ها) = 0 ؛ هر مقدار واقعی دیگر = 1 ؛ ناموجود = null.
     */
    function melkinoCompareInstalledBool($value): ?int
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        $no = ['ندارد', 'ندارند', 'نصب نشده', 'نصب‌نشده', 'none', 'no', '-', '—', '0', 'false'];
        return in_array(mb_strtolower($s), $no, true) ? 0 : 1;
    }
}

if (!function_exists('melkinoCompareExtractFields')) {
    /**
     * استخراج فیلدهای قابل مقایسه + فیلدهای نمایشی از یک ردیف آگهی.
     * فقط آنچه واقعاً در داده هست؛ موقعیت و فیلدهای سیستمی جدا نگه داشته می‌شوند.
     */
    function melkinoCompareExtractFields(array $ad): array
    {
        $num = static fn($k) => melkinoCompareNum($ad[$k] ?? '');
        $bool = static fn($k) => melkinoCompareBool($ad[$k] ?? null);

        $pd = $ad['property_details'] ?? null;
        if (is_string($pd) && trim($pd) !== '') {
            $dec = json_decode($pd, true);
            $pd = is_array($dec) ? $dec : [];
        }
        if (!is_array($pd)) {
            $pd = [];
        }

        $v = [];
        // مشخصات ستونی
        $v['area']         = $num('area');
        $v['rooms']        = $num('rooms');
        $v['year']         = $num('year');
        if ($v['year'] <= 0 && isset($pd['year'])) {
            $v['year'] = melkinoCompareNum($pd['year'] ?? $pd['year_apt'] ?? $pd['office_year'] ?? '');
        }
        $v['building_age'] = 0.0;
        if (function_exists('melkinoBuildingAge') && $v['year'] > 0) {
            $ba = melkinoBuildingAge((int) $v['year']);
            $v['building_age'] = $ba !== null ? (float) $ba : 0.0;
        } elseif (!empty($ad['building_age'])) {
            $v['building_age'] = $num('building_age');
        }
        $v['parking']      = $bool('parking');
        $v['elevator']     = $bool('elevator');
        $v['is_not_keyed'] = $bool('is_not_keyed');
        // قیمت‌ها
        $sell  = $num('price_sell');
        $total = $num('total_price');
        $base  = $sell > 0 ? $sell : $total;
        $v['price_total'] = $base;
        $v['price_unit']  = ($base > 0 && $v['area'] > 0) ? round($base / $v['area'], 2) : 0.0;
        $v['full_rent']    = ($bool('full_rent_enabled') === 1) ? $num('full_rent') : $num('full_rent');
        $v['deposit']      = $num('deposit');
        $v['rent_monthly'] = $num('rent_monthly');
        // فیلدهای تخصصی pd.*
        foreach (melkinoCompareFieldRules() as $key => $rule) {
            if (strpos($key, 'pd.') !== 0) {
                continue;
            }
            $dk = substr($key, 3);
            if (!array_key_exists($dk, $pd)) {
                $v[$key] = ($rule['type'] === 'boolean') ? null : 0.0;
                continue;
            }
            if ($rule['type'] === 'boolean') {
                if (in_array($dk, ['cabinet', 'cooling', 'heating'], true)) {
                    // راند ۲۵: داده متنی است؛ «ندارد» = نصب‌نشده (0)، هر نوع واقعی = 1
                    $v[$key] = melkinoCompareInstalledBool($pd[$dk]);
                } else {
                    $v[$key] = melkinoCompareBool($pd[$dk]);
                }
            } else {
                $val = $pd[$dk];
                if (is_array($val)) {
                    $v[$key] = 0.0; // فهرست متنی قابل مقایسهٔ عددی نیست
                } else {
                    $v[$key] = melkinoCompareNum($val);
                }
            }
        }

        // راند ۲۵: ملک «پیش فروش» طبق قاعدهٔ صریح کاربر همیشه کلید نخورده است
        // و کابینت/سرمایش/گرمایش آن هنوز نصب نشده‌اند؛ حتی اگر در دادهٔ آگهی
        // چیز دیگری ثبت شده باشد، در مقایسه این قاعده حاکم است.
        $__trans = trim((string)($ad['transaction_type'] ?? ''));
        if ($__trans !== '' && (mb_strpos($__trans, 'پیش') !== false || stripos($__trans, 'pre') !== false)) {
            $v['is_not_keyed'] = 1;
            $v['pd.cabinet']   = 0;
            $v['pd.cooling']   = 0;
            $v['pd.heating']   = 0;
        }

        // فیلدهای نمایشی (امتیازدهی نمی‌شوند)
        $display = [
            'نوع معامله' => (string)($ad['transaction_type'] ?? ''),
            'نوع ملک'    => (string)($ad['property_type'] ?? ''),
            'موقعیت (بدون امتیاز)' => (string)($ad['location'] ?? ''),
            'طبقه'       => (string)($ad['floor'] ?? ''),
            'نوع سند'    => (string)($ad['deed_type'] ?? ''),
        ];
        foreach ($pd as $dk => $pv) {
            $rk = 'pd.' . $dk;
            $rules = melkinoCompareFieldRules();
            if (isset($rules[$rk]) && $rules[$rk]['dir'] !== 'context') {
                continue; //Already scored/displayed via breakdown
            }
            if (is_array($pv)) {
                $pv = implode('، ', array_map('strval', $pv));
            }
            $pv = trim((string)$pv);
            if ($pv !== '' && $pv !== '0') {
                $display['pd.' . $dk] = $pv;
            }
        }

        return ['values' => $v, 'display' => $display, 'pd' => $pd];
    }
}

if (!function_exists('melkinoCompareDeedRiskScore')) {
    /**
     * امتیاز کم‌ریسکی سند (۰ تا ۱۰؛ بالاتر = کم‌ریسک‌تر).
     * این جدول بخشی از «قوانین سیستم» است، نه حدس کارشناسیِ لحظه‌ای.
     */
    function melkinoCompareDeedRiskScore(string $deed): ?float
    {
        $map = [
            'طلق' => 10.0, 'تک‌برگ' => 10.0, 'برگه واگذاری' => 7.0,
            'اعیان' => 6.0, 'مشاعی' => 5.0, 'رهنی' => 5.0,
            'وقفی' => 4.0, 'قولنامه عادی' => 3.0, 'قولنامه شورایی' => 3.0,
        ];
        $deed = trim($deed);
        return $map[$deed] ?? null;
    }
}

if (!function_exists('melkinoCompareEngine')) {
    /**
     * اجرای موتور مقایسه روی ۲ تا ۵ ملک هم‌نوع.
     * @param array $ads ردیف‌های کامل آگهی (SELECT a.* …)
     */
    function melkinoCompareEngine(array $ads): array
    {
        $rules = melkinoCompareFieldRules();
        $disclaimer = 'توجه: موقعیت مکانی در این مقایسه امتیازدهی نشده است؛ زیرا مناسب بودن موقعیت تا حد زیادی به سلیقه، نیاز و هدف خریدار بستگی دارد. امتیازها فقط بر اساس فیلدهای ثبت‌شده برای ملک در سامانه محاسبه شده‌اند.';

        $types = [];
        foreach ($ads as $a) {
            $types[] = trim((string)($a['property_type'] ?? ''));
        }
        $types = array_values(array_filter(array_unique($types), static fn($t) => $t !== ''));
        $mixedTypes = count($types) > 1;
        $typeLabel = $mixedTypes ? implode(' / ', $types) : (string)($types[0] ?? '');

        // ۱) استخراج دادهٔ هر ملک
        $extracted = [];
        foreach ($ads as $a) {
            $extracted[(string)$a['id']] = melkinoCompareExtractFields($a);
        }

        // ۲) فیلدهای قابل امتیازدهی: فقط فیلدهایی که جهت مشخص دارند و
        //    حداقل در یک ملک دادهٔ معتبر دارند (Dynamic: از خود داده می‌آید)
        $isValid = function (string $key, $val) use ($rules): bool {
            $rule = $rules[$key] ?? null;
            if (!$rule || $rule['dir'] === 'context') {
                return false;
            }
            if ($rule['type'] === 'boolean') {
                return $val === 0 || $val === 1;
            }
            return is_numeric($val) && (float)$val > 0;
        };

        $scoredKeys = [];
        foreach ($rules as $key => $rule) {
            foreach ($extracted as $ex) {
                if ($isValid($key, $ex['values'][$key] ?? null)) {
                    $scoredKeys[] = $key;
                    break;
                }
            }
        }

        // ۳) امتیاز نسبی هر فیلد (min-max بین همین ملک‌ها، ۰ تا ۱۰)
        $fieldScores = []; // key => [adId => score|null]
        foreach ($scoredKeys as $key) {
            $rule = $rules[$key];
            $vals = [];
            foreach ($extracted as $id => $ex) {
                $v = $ex['values'][$key] ?? null;
                $vals[$id] = $isValid($key, $v) ? (float)$v : null;
            }
            $known = array_filter($vals, static fn($x) => $x !== null);
            $min = $known ? min($known) : 0.0;
            $max = $known ? max($known) : 0.0;
            $scores = [];
            foreach ($vals as $id => $v) {
                if ($v === null) {
                    $scores[$id] = null; // نامعلوم → خارج از میانگین، نه صفر
                    continue;
                }
                if ($rule['type'] === 'boolean') {
                    $scores[$id] = $v === 1.0 ? 10.0 : 0.0;
                    continue;
                }
                if (abs($max - $min) < 0.000001) {
                    $scores[$id] = 5.0; // تفاوتی وجود ندارد → خنثی
                    continue;
                }
                $n = 10.0 * ($v - $min) / ($max - $min);
                $scores[$id] = ($rule['dir'] === 'lower') ? (10.0 - $n) : $n;
            }
            $fieldScores[$key] = $scores;
        }

        // ۴) امتیازهای هر ملک
        $out = [];
        $relevantCount = max(1, count($scoredKeys));
        foreach ($ads as $a) {
            $id = (string)$a['id'];
            $ex = $extracted[$id];

            // Overall: میانگین وزنی (وزن برابر) فیلدهای معلومِ همان ملک
            $sum = 0.0; $cnt = 0;
            $breakdown = [];
            foreach ($scoredKeys as $key) {
                $sc = $fieldScores[$key][$id] ?? null;
                $raw = $ex['values'][$key] ?? null;
                $rule = $rules[$key];
                $valText = '';
                if ($rule['type'] === 'boolean') {
                    $valText = $raw === 1 ? 'دارد' : ($raw === 0 ? 'ندارد' : 'نامعلوم');
                } elseif ($raw !== null && $raw > 0) {
                    $numText = !empty($rule['plain'])
                        ? (string)(int)round((float)$raw)
                        : number_format((float)$raw, 0, '.', ',');
                    $valText = $numText . ($rule['unit'] ?? '') . (!empty($rule['money']) ? ' تومان' : '');
                } else {
                    $valText = 'ثبت نشده';
                }
                $breakdown[] = [
                    'key' => $key,
                    'label' => $rule['label'],
                    'score' => $sc === null ? null : round($sc, 1),
                    'value' => $valText,
                    'dir' => $rule['dir'],
                ];
                if ($sc !== null) {
                    $sum += $sc; $cnt++;
                }
            }
            $overall = $cnt > 0 ? round($sum / $cnt, 1) : null;

            // Value: جذابیت قیمت (فقط اگر دادهٔ قیمت موجود باشد)
            $priceKeys = array_values(array_intersect($scoredKeys, ['price_unit', 'price_total', 'full_rent', 'deposit', 'rent_monthly']));
            $value = null;
            if ($priceKeys) {
                $vs = 0.0; $vc = 0;
                foreach ($priceKeys as $pk) {
                    $s = $fieldScores[$pk][$id] ?? null;
                    if ($s !== null) { $vs += $s; $vc++; }
                }
                $value = $vc > 0 ? round($vs / $vc, 1) : null;
            }

            // Confidence: چند درصد از معیارهای قابل امتیازِ این مقایسه برای این ملک دادهٔ معتبر دارد
            $have = 0;
            foreach ($scoredKeys as $key) {
                if (($fieldScores[$key][$id] ?? null) !== null) {
                    $have++;
                }
            }
            $confidence = (int)round(100 * $have / $relevantCount);

            // Risk: سند + وضعیت حقوقی (وام) + کامل‌بودن داده؛ بالاتر = کم‌ریسک‌تر
            $deedScore = melkinoCompareDeedRiskScore((string)($a['deed_type'] ?? ''));
            $conf10 = $confidence / 10.0;
            if ($deedScore !== null) {
                $risk = 0.6 * $deedScore + 0.4 * $conf10;
            } else {
                $risk = $conf10;
            }
            if (melkinoCompareBool($a['has_loan'] ?? null) === 1) {
                $risk -= 1.0; // درگیر بودن وام = یک درجه ریسک حقوقی بیشتر (مستند در قوانین)
            }
            $risk = max(0.0, min(10.0, $risk));

            $out[$id] = [
                'id' => $id,
                'title' => (string)($a['title'] ?? ''),
                'type' => (string)($a['property_type'] ?? ''),
                'transaction' => (string)($a['transaction_type'] ?? ''),
                'scores' => [
                    'overall' => $overall,
                    'value' => $value,
                    'risk' => round($risk, 1),
                    'confidence' => $confidence,
                ],
                'breakdown' => $breakdown,
                'display' => $ex['display'],
            ];
        }

        // ۵) تفاوت‌های اصلی (بیشترین شکاف امتیاز، حداکثر ۵)
        $diffs = [];
        foreach ($scoredKeys as $key) {
            $known = array_filter($fieldScores[$key], static fn($x) => $x !== null);
            if (count($known) < 2) {
                continue;
            }
            $spread = max($known) - min($known);
            if ($spread < 0.5) {
                continue;
            }
            $perAd = [];
            foreach ($out as $id => $o) {
                $raw = $extracted[$id]['values'][$key] ?? null;
                $rule = $rules[$key];
                $txt = $rule['type'] === 'boolean'
                    ? ($raw === 1 ? 'دارد' : ($raw === 0 ? 'ندارد' : '—'))
                    : (($raw !== null && $raw > 0) ? ((!empty($rule['plain']) ? (string)(int)round((float)$raw) : number_format((float)$raw, 0, '.', ',')) . ($rule['unit'] ?? '')) : '—');
                $perAd[$id] = ['value' => $txt, 'score' => $fieldScores[$key][$id]];
            }
            $diffs[] = ['key' => $key, 'label' => $rules[$key]['label'], 'spread' => round($spread, 1), 'ads' => $perAd];
        }
        usort($diffs, static fn($x, $y) => $y['spread'] <=> $x['spread']);
        $diffs = array_slice($diffs, 0, 5);

        // ۶) نقاط قوت/ضعف هر ملک (از همان امتیازهای قابل ردیابی)
        $strengths = []; $weaknesses = [];
        foreach ($out as $id => $o) {
            $st = []; $wk = [];
            foreach ($o['breakdown'] as $b) {
                $sc = $b['score'];
                if ($sc === null) {
                    continue;
                }
                $colScores = array_filter(array_column($fieldScores[$b['key']], null), static fn($x) => $x !== null);
                $isMax = $colScores ? ($sc >= max($colScores) - 0.001) : false;
                $isMin = $colScores ? ($sc <= min($colScores) + 0.001) : false;
                if ($sc >= 8 && $isMax && count($st) < 3) {
                    $st[] = $b['label'] . ' (' . $b['value'] . ')';
                } elseif ($sc <= 3.5 && $isMin && count($wk) < 3) {
                    $wk[] = $b['label'] . ' (' . $b['value'] . ')';
                }
            }
            // موارد نامعلوم که بقیه دارند = نقطهٔ ضعف اطلاعاتی
            foreach ($o['breakdown'] as $b) {
                if ($b['score'] === null && count($wk) < 3) {
                    $colKnown = array_filter($fieldScores[$b['key']], static fn($x) => $x !== null);
                    if (count($colKnown) >= 2) {
                        $wk[] = $b['label'] . ': ثبت نشده (نامعلوم ≠ ندارد)';
                    }
                }
            }
            $strengths[$id] = $st;
            $weaknesses[$id] = $wk;
        }

        // ۷) معیارهای استفاده‌شده / بدون داده
        $used = [];
        foreach ($scoredKeys as $key) {
            $used[] = $rules[$key]['label'];
        }
        $noData = [];
        foreach ($rules as $key => $rule) {
            if (!in_array($key, $scoredKeys, true) && $rule['dir'] !== 'context') {
                $noData[] = $rule['label'];
            }
        }

        // ۸) نتیجه‌گیری قابل ردیابی
        $conclusion = '';
        $ranked = $out;
        usort($ranked, static fn($x, $y) => ($y['scores']['overall'] ?? -1) <=> ($x['scores']['overall'] ?? -1));
        if ($ranked && ($ranked[0]['scores']['overall'] ?? null) !== null) {
            $top = $ranked[0];
            $second = $ranked[1] ?? null;
            $conclusion = 'ملک «' . $top['title'] . '» بر اساس فیلدهای ثبت‌شده در این مقایسه، امتیاز کلی بالاتری دارد (' . $top['scores']['overall'] . ' از ۱۰).';
            if ($second && ($second['scores']['overall'] ?? null) !== null && $diffs) {
                $factors = [];
                foreach ($diffs as $d) {
                    $my = $d['ads'][$top['id']]['score'] ?? null;
                    $other = $d['ads'][$second['id']]['score'] ?? null;
                    if ($my !== null && $other !== null && $my > $other) {
                        $factors[] = mb_strtolower($d['label']);
                    }
                    if (count($factors) >= 3) break;
                }
                if ($factors) {
                    $conclusion .= ' مهم‌ترین عوامل مؤثر در اختلاف امتیاز شامل ' . implode('، ', $factors) . ' بوده است.';
                }
            }
            $conclusion .= ' این نتیجه فقط بر اساس داده‌های موجود در سامانه است و به‌معنی «بهترین بودن مطلق» ملک نیست.';
        }

        return [
            'disclaimer' => $disclaimer,
            'type' => $typeLabel,
            'mixed_types' => $mixedTypes,
            'ads' => array_values($out),
            'differences' => $diffs,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
            'criteria_used' => $used,
            'criteria_no_data' => $noData,
            'conclusion' => $conclusion,
            'no_data_note' => $noData ? 'برای برخی معیارها اطلاعات کافی در دیتابیس موجود نبود و این معیارها در امتیازدهی لحاظ نشدند.' : '',
        ];
    }
}
