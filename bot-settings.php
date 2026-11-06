<?php
/**
|--------------------------------------------------------------------------
| تنظیمات ربات‌ها و کانال
|--------------------------------------------------------------------------
| ادمین می‌تواند توکن ربات، شناسه کانال و نام کاربری ربات را از پنل
| تغییر بدهد. مقادیر در جدول settings ذخیره می‌شوند.
|
| اولویت خواندن توکن:
|   1) مقدار ذخیره‌شده در دیتابیس (تنظیم‌شده توسط ادمین)
|   2) مقدار فایل config.secrets.php یا متغیر محیطی
|--------------------------------------------------------------------------
*/

// اطمینان از اینکه توابع dbSettingGet / dbSettingSet همیشه در دسترس باشند
// (این فایل ممکن است جایی لود شود که config.php هنوز اجرا نشده باشد)
require_once __DIR__ . '/db-settings.php';

if (!function_exists('melkinoBotSetting')) {
    function melkinoBotSetting(string $key, string $default = ''): string
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return $default;
        }
        try {
            $value = dbSettingGet($pdo, 'bots', $key, null);
        } catch (Throwable $e) {
            return $default;
        }
        return ($value === null || $value === '') ? $default : (string)$value;
    }
}

if (!function_exists('melkinoBotSettings')) {
    function melkinoBotSettings(): array
    {
        $sms = melkinoSmsSettings();
        return [
            'telegram_token'        => melkinoBotSetting('telegram_token'),
            'telegram_channel'      => melkinoBotSetting('telegram_channel', defined('CHANNEL_ID') ? CHANNEL_ID : ''),
            'telegram_bot_username' => ltrim(melkinoBotSetting('telegram_bot_username'), '@'),
            'telegram_miniapp_url' => melkinoBotSetting('telegram_miniapp_url'),
            'bale_token'            => melkinoBotSetting('bale_token'),
            'bale_channel'          => melkinoBotSetting('bale_channel'),
            'bale_bot_username'     => ltrim(melkinoBotSetting('bale_bot_username'), '@'),
            'bale_miniapp_url' => melkinoBotSetting('bale_miniapp_url'),
            'eitaa_token'           => melkinoBotSetting('eitaa_token'),
            'eitaa_bot_username'    => ltrim(melkinoBotSetting('eitaa_bot_username'), '@'),
            'eitaa_miniapp_url'     => melkinoBotSetting('eitaa_miniapp_url'),
            'eitaa_channel_token'   => melkinoBotSetting('eitaa_channel_token'),
            'eitaa_channel'         => melkinoBotSetting('eitaa_channel'),
            'http_proxy'            => melkinoBotSetting('http_proxy'),
            'sms_enabled'           => $sms['enabled'] ? '1' : '0',
            'sms_api_key'           => $sms['api_key'],
            'sms_api_url'           => $sms['api_url'],
            'sms_sender_line'       => $sms['sender_line'],
            // سرویس‌دهنده و فیلدهای ملی‌پیامک (رمز عمداً خروجی داده نمی‌شود؛ ماسک می‌شود)
            'sms_provider'          => (string)($sms['provider'] ?? 'melipayamak'),
            'sms_otp_line'          => (string)($sms['otp_line'] ?? ''),
            'sms_promo_line'        => (string)($sms['promo_line'] ?? ''),
            'sms_otp_body_id'       => (string)($sms['otp_body_id'] ?? ''),
            'sms_otp_template_masked' => (string)melkinoBotSetting('sms_otp_template', ''),
            // روش‌های ورود مجاز (تب «ربات و کانال» → کارت «روش‌های ورود»)
            'login_telegram_enabled' => melkinoBotSetting('login_telegram_enabled', '1'),
            'login_bale_enabled'     => melkinoBotSetting('login_bale_enabled', '1'),
            'login_eitaa_enabled'    => melkinoBotSetting('login_eitaa_enabled', '1'),
            'login_sms_enabled'      => melkinoBotSetting('login_sms_enabled', '0'),
            'updated_at'            => melkinoBotSetting('updated_at'),
        ];
    }
}

/**
 * --------------------------------------------------------------------------
 * آیا یک روشِ ورود (telegram/bale/sms) توسط ادمین فعال است؟
 * --------------------------------------------------------------------------
 * پیش‌فرض: تلگرام و بله فعال (سازگار با رفتار قبلی)، پیامک غیرفعال
 * (قبلاً با یک بلوک ۴۰۳ سخت‌کد غیرفعال بود؛ حالا ادمین از پنل
 * تصمیم می‌گیرد). هم صفحه‌ی ورود و هم اندپوینت‌های احراز هویت
 * این تابع را صدا می‌زنند تا غیرفعال‌کردن، فقط ظاهری نباشد.
 */
if (!function_exists('melkinoLoginMethodEnabled')) {
    function melkinoLoginMethodEnabled(string $method): bool
    {
        $map = [
            'telegram' => ['login_telegram_enabled', '1'],
            'bale'     => ['login_bale_enabled', '1'],
            'eitaa'    => ['login_eitaa_enabled', '1'],
            'sms'      => ['login_sms_enabled', '0'],
        ];
        $method = strtolower(trim($method));
        if (!isset($map[$method])) {
            return false;
        }
        [$key, $default] = $map[$method];
        return melkinoBotSetting($key, $default) === '1';
    }
}

/**
 * --------------------------------------------------------------------------
 * تنظیمات پنل پیامک
 * --------------------------------------------------------------------------
 * اولویت خواندن: مقدار ذخیره‌شده در دیتابیس (پنل ادمین) و در صورت نبودن،
 * ثابت‌های config.php. اگر ادمین هرگز چیزی در پنل ذخیره نکرده باشد،
 * فعال‌بودن بر اساس پر بودن ثابت‌هاست (سازگاری با رفتار قبلی).
 */
if (!function_exists('melkinoSmsSettings')) {
    function melkinoSmsSettings(): array
    {
        $enabledDb = melkinoBotSetting('sms_enabled', '');
        if ($enabledDb === '') {
            $enabled = defined('SMS_API_KEY') && (string)SMS_API_KEY !== ''
                && defined('SMS_API_URL') && (string)SMS_API_URL !== '';
        } else {
            $enabled = $enabledDb === '1';
        }

        return [
            'enabled'     => $enabled,
            'api_key'     => melkinoBotSetting('sms_api_key', defined('SMS_API_KEY') ? (string)SMS_API_KEY : ''),
            'api_url'     => melkinoBotSetting('sms_api_url', defined('SMS_API_URL') ? (string)SMS_API_URL : ''),
            'sender_line' => melkinoBotSetting('sms_sender_line', defined('SMS_SENDER_LINE') ? (string)SMS_SENDER_LINE : ''),
            // سرویس‌دهنده و فیلدهای ملی‌پیامک (برنامهٔ پیامک)
            'provider'    => melkinoBotSetting('sms_provider', 'melipayamak'),
            'password'    => melkinoBotSetting('sms_password', defined('SMS_API_PASSWORD') ? (string)SMS_API_PASSWORD : ''),
            'otp_line'    => melkinoBotSetting('sms_otp_line', ''),
            'promo_line'  => melkinoBotSetting('sms_promo_line', ''),
            'otp_body_id' => melkinoBotSetting('sms_otp_body_id', ''),
            'otp_template' => melkinoBotSetting('sms_otp_template', ''),
        ];
    }
}

/**
 * --------------------------------------------------------------------------
 * فیلدهای قابل انتشار آگهی در کانال
 * --------------------------------------------------------------------------
 * ادمین برای هر پلتفرم (تلگرام/بله) جداگانه انتخاب می‌کند کدام فیلدها در
 * متن پیام منتشرشده بیایند و چه متن ثابتی بالا/پایین همه‌ی آگهی‌ها باشد.
 */
if (!function_exists('melkinoPublishFieldDefs')) {
    // publish_defs_v5 (راند ۲۲): قیمت هر متر + پیش‌پرداخت پیش‌فروش + نرمال‌سازی مبالغ
    // publish_defs_v4 (راند ۱۸): فیلدهای رهن/اجاره + فیلتر نوع معامله
    // publish_defs_v3 (راند ۱۷):
    // ۱) کلیدهای «نوع سند» و «معاوضه» (راند ۱۶).
    // ۲) فیلدهای اختصاصی فرم ثبت هر نوع ملک (pd.*) — یعنی دقیقاً همان
    //    فیلدهایی که در property_details ذخیره می‌شوند (کاربری زمین،
    //    عرض/طول، پوشش کف، کابینت، سرمایش/گرمایش، نوع درختان، …).
    //    با انتخاب هر نوع ملک، فهرست به فیلدهای همان نوع محدود می‌شود.
    function melkinoPublishFieldDefs(?string $propertyType = null, ?string $transactionType = null): array
    {
        $all = [
            'title'         => ['emoji' => '🏠', 'label' => 'عنوان آگهی'],
            'transaction'   => ['emoji' => '📌', 'label' => 'نوع معامله'],
            'property_type' => ['emoji' => '🏷️', 'label' => 'نوع ملک'],
            'location'      => ['emoji' => '📍', 'label' => 'موقعیت'],
            'address'       => ['emoji' => '🗺️', 'label' => 'آدرس'],
            'area'          => ['emoji' => '📐', 'label' => 'متراژ'],
            'rooms'         => ['emoji' => '🛏️', 'label' => 'تعداد اتاق'],
            'floor'         => ['emoji' => '🏢', 'label' => 'طبقه'],
            'year'          => ['emoji' => '📅', 'label' => 'سال ساخت'],
            'building_age'  => ['emoji' => '⏳', 'label' => 'سن بنا'],
            'price'         => ['emoji' => '💰', 'label' => 'قیمت (فروش/پیش‌فروش/توافقی)'],
            'price_per_meter' => ['emoji' => '💹', 'label' => 'قیمت هر متر مربع (فروش/پیش‌فروش)'],
            'down_payment'  => ['emoji' => '💳', 'label' => 'پیش‌پرداخت و شرایط پرداخت (پیش‌فروش)'],
            'deposit'       => ['emoji' => '💵', 'label' => 'مبلغ رهن (ودیعه)'],
            'rent_monthly'  => ['emoji' => '🗓️', 'label' => 'اجارهٔ ماهانه'],
            'full_rent'     => ['emoji' => '🔑', 'label' => 'رهن کامل (اجارهٔ کامل)'],
            'loan'          => ['emoji' => '🏦', 'label' => 'مشخصات وام'],
            'deed'          => ['emoji' => '📜', 'label' => 'نوع سند و توضیحات سند'],
            'exchange'      => ['emoji' => '🔄', 'label' => 'تمایل به معاوضه و گزینه‌ها'],
            'description'   => ['emoji' => '📝', 'label' => 'توضیحات'],
            'contact'       => ['emoji' => '👤', 'label' => 'نام تماس‌گیرنده'],
            'phone'         => ['emoji' => '📞', 'label' => 'شماره تماس آگهی'],
            'consultant'    => ['emoji' => '☎️', 'label' => 'شماره مشاور ملکینو'],
            'ad_id'         => ['emoji' => '🔗', 'label' => 'کد آگهی'],
            'ad_link'       => ['emoji' => '🌐', 'label' => 'لینک آگهی در سایت'],
        ];

        // کاتالوگ فیلدهای اختصاصی هر نوع (کلید = pd.<کلیدِ property_details>)
        $pdCatalog = [
            // آپارتمان و ویلا و تجاری (مشترک)
            'pd.flooring'              => ['emoji' => '🧱', 'label' => 'پوشش کف'],
            'pd.cabinet'               => ['emoji' => '🚪', 'label' => 'نوع کابینت'],
            'pd.cooling'               => ['emoji' => '❄️', 'label' => 'سیستم سرمایش'],
            'pd.heating'               => ['emoji' => '🔥', 'label' => 'سیستم گرمایش'],
            // آپارتمان
            'pd.total_units'           => ['emoji' => '🏬', 'label' => 'تعداد کل واحدها'],
            'pd.units_per_floor'       => ['emoji' => '🚪', 'label' => 'تعداد واحد در طبقه'],
            'pd.apartment_type'        => ['emoji' => '🏢', 'label' => 'نوع آپارتمان'],
            'pd.villa_type'            => ['emoji' => '🏡', 'label' => 'نوع ویلایی'],
            // ویلا
            'pd.land_area'             => ['emoji' => '📏', 'label' => 'متراژ زمین', 'suffix' => ' متر مربع'],
            // زمین
            'pd.land_type'             => ['emoji' => '🧭', 'label' => 'کاربری زمین'],
            'pd.land_width'            => ['emoji' => '↔️', 'label' => 'عرض زمین', 'suffix' => ' متر'],
            'pd.land_length'           => ['emoji' => '↕️', 'label' => 'طول زمین', 'suffix' => ' متر'],
            'pd.land_front_width'      => ['emoji' => '📏', 'label' => 'عرض بر', 'suffix' => ' متر'],
            'pd.land_blocks'           => ['emoji' => '🔢', 'label' => 'تعداد بر'],
            'pd.land_direction'        => ['emoji' => '🧭', 'label' => 'جهت ملک'],
            'pd.land_shape'            => ['emoji' => '🔷', 'label' => 'شکل زمین'],
            'pd.land_deed_status'      => ['emoji' => '📃', 'label' => 'وضعیت سند زمین'],
            'pd.land_deed_type'        => ['emoji' => '📜', 'label' => 'نوع سند زمین'],
            'pd.land_setback_status'   => ['emoji' => '🚧', 'label' => 'وضعیت عقب‌نشینی'],
            'pd.land_ownership'        => ['emoji' => '🤝', 'label' => 'وضعیت مالکیت'],
            // باغ
            'pd.garden_area'           => ['emoji' => '🌳', 'label' => 'مساحت باغ', 'suffix' => ' متر مربع'],
            'pd.tree_types'            => ['emoji' => '🌲', 'label' => 'نوع درختان'],
            'pd.tree_age'              => ['emoji' => '🕰️', 'label' => 'سن درختان'],
            'pd.irrigation_type'       => ['emoji' => '💧', 'label' => 'نوع آبیاری'],
            'pd.has_well'              => ['emoji' => '🕳️', 'label' => 'آب ملکی (چاه)'],
            'pd.has_pond'              => ['emoji' => '🏊', 'label' => 'استخر ذخیره آب'],
            'pd.has_building'          => ['emoji' => '🏚️', 'label' => 'بنا / خانه باغ'],
            'pd.building_area'         => ['emoji' => '📐', 'label' => 'متراژ بنا', 'suffix' => ' متر مربع'],
            'pd.document_type'         => ['emoji' => '📜', 'label' => 'نوع سند باغ'],
            // اداری
            'pd.office_area'           => ['emoji' => '📐', 'label' => 'متراژ واحد', 'suffix' => ' متر مربع'],
            'pd.office_floor'          => ['emoji' => '🏢', 'label' => 'طبقه'],
            'pd.office_units_per_floor'=> ['emoji' => '🚪', 'label' => 'تعداد واحد در طبقه'],
            'pd.office_rooms'          => ['emoji' => '🛏️', 'label' => 'تعداد اتاق'],
            'pd.office_year'           => ['emoji' => '📅', 'label' => 'سال ساخت'],
            'pd.office_condition'      => ['emoji' => '🛠️', 'label' => 'وضعیت واحد'],
            'pd.office_orientation'    => ['emoji' => '🧭', 'label' => 'موقعیت واحد'],
            'pd.office_usage'          => ['emoji' => '💼', 'label' => 'کاربری'],
            // تجاری
            'pd.front'                 => ['emoji' => '📏', 'label' => 'بر مغازه', 'suffix' => ' متر'],
            'pd.wall'                  => ['emoji' => '🧱', 'label' => 'پوشش دیوارها'],
            'pd.location_type'         => ['emoji' => '📍', 'label' => 'موقعیت (دونبش/دوبر/یک‌بر)'],
            'pd.location_features'     => ['emoji' => '🌆', 'label' => 'ویژگی موقعیت (بر اصلی/فرعی/پاساژ/گاراژ)'],
            'pd.jobs'                  => ['emoji' => '💼', 'label' => 'مناسب برای مشاغل'],
        ];

        // فیلدهای عمومی (برای همهٔ انواع معنا دارند)
        $common = [
            'title', 'transaction', 'property_type', 'location', 'address',
            'price', 'price_per_meter', 'down_payment',
            'deposit', 'rent_monthly', 'full_rent', 'loan',
            'deed', 'exchange', 'description',
            'contact', 'phone', 'consultant', 'ad_id', 'ad_link',
        ];
        // فیلدهای ستونیِ مرتبط با هر نوع (area/rooms/floor/year فقط اگر
        // فرمِ آن نوع واقعاً اینها را پر می‌کند)
        $typeGeneric = [
            'آپارتمان' => ['area', 'rooms', 'floor', 'year', 'building_age'],
            'ویلا'     => ['area', 'rooms', 'year', 'building_age'],
            'زمین'     => [],
            'باغ'      => [],
            'اداری'    => [],
            'تجاری'    => ['area'],
            'مغازه'    => ['area'],
        ];
        // فیلدهای اختصاصی (pd.*) هر نوع — دقیقاً همان‌هایی که فرم ثبت
        // آن نوع می‌گیرد و در property_details ذخیره می‌شود
        $typePd = [
            'آپارتمان' => ['pd.apartment_type', 'pd.total_units', 'pd.units_per_floor', 'pd.flooring', 'pd.cabinet', 'pd.cooling', 'pd.heating'],
            'ویلا'     => ['pd.villa_type', 'pd.land_area', 'pd.flooring', 'pd.cabinet', 'pd.cooling', 'pd.heating'],
            'زمین'     => ['pd.land_area', 'pd.land_type', 'pd.land_width', 'pd.land_length', 'pd.land_front_width', 'pd.land_blocks', 'pd.land_direction', 'pd.land_shape', 'pd.land_deed_status', 'pd.land_deed_type', 'pd.land_setback_status', 'pd.land_ownership'],
            'باغ'      => ['pd.garden_area', 'pd.tree_types', 'pd.tree_age', 'pd.irrigation_type', 'pd.has_well', 'pd.has_pond', 'pd.has_building', 'pd.building_area', 'pd.document_type'],
            'اداری'    => ['pd.office_area', 'pd.office_floor', 'pd.office_units_per_floor', 'pd.office_rooms', 'pd.office_year', 'pd.office_condition', 'pd.office_orientation', 'pd.office_usage'],
            'تجاری'    => ['pd.front', 'pd.flooring', 'pd.wall', 'pd.cabinet', 'pd.cooling', 'pd.heating', 'pd.location_type', 'pd.location_features', 'pd.jobs'],
            'مغازه'    => ['pd.front', 'pd.flooring', 'pd.wall', 'pd.cabinet', 'pd.cooling', 'pd.heating', 'pd.location_type', 'pd.location_features', 'pd.jobs'],
        ];

        // فیلتر بر اساس نوع معامله (راند ۱۸):
        // فروش/پیش‌فروش → قیمت و وام؛ اجاره/رهن → ودیعه، اجارهٔ ماهانه، رهن کامل
        $tt = trim((string)$transactionType);
        $rentFamily = ['اجاره', 'رهن کامل', 'رهن و اجاره'];
        $sellFamily = ['فروش', 'پیش فروش'];
        $dropByTrans = [];
        if (in_array($tt, $rentFamily, true)) {
            // راند ۲۲: قیمت هر متر و پیش‌پرداخت مخصوص فروش/پیش‌فروش هستند
            $dropByTrans = ['loan', 'price_per_meter', 'down_payment'];
        } elseif (in_array($tt, $sellFamily, true)) {
            $dropByTrans = ['deposit', 'rent_monthly', 'full_rent'];
        }

        $pt = trim((string)$propertyType);
        if ($pt === '' || !isset($typePd[$pt])) {
            // «همهٔ انواع ملک» یا نوع سفارشی/ناشناخته → فهرست کامل
            $full = array_merge($all, $pdCatalog);
            foreach ($dropByTrans as $k) {
                unset($full[$k]);
            }
            return $full;
        }

        // ترتیب نمایش: عمومی ← ستونی‌های همان نوع ← اختصاصی‌های همان نوع
        $orderedCommon = array_merge($common, $typeGeneric[$pt]);
        $out = [];
        foreach ($all as $k => $v) {
            if (in_array($k, $orderedCommon, true) && !in_array($k, $dropByTrans, true)) {
                $out[$k] = $v;
            }
        }
        foreach ($typePd[$pt] as $k) {
            if (isset($pdCatalog[$k])) {
                $out[$k] = $pdCatalog[$k];
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoPublishPlatform')) {
    function melkinoPublishPlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        return in_array($platform, ['telegram','bale','eitaa'], true) ? $platform : 'telegram';
    }
}

/**
 * فهرست گزینه‌های «نوع ملک» و «نوع معامله» برای تنظیمِ محتوای انتشار به تفکیک
 * نوع. فهرست ثابتِ پیش‌فرض + مقادیری که واقعاً در آگهی‌های دیتابیس هستند
 * (تا نوع‌های سفارشیِ ادمین هم قابل انتخاب باشند).
 */
if (!function_exists('melkinoPublishCombos')) {
    function melkinoPublishCombos(): array
    {
        global $pdo;
        $types = ['آپارتمان', 'ویلا', 'زمین', 'باغ', 'اداری', 'تجاری'];
        $transactions = ['فروش', 'پیش فروش', 'اجاره', 'رهن کامل'];

        if ($pdo instanceof PDO) {
            try {
                $rows = $pdo->query("SELECT DISTINCT property_type FROM ads WHERE property_type IS NOT NULL AND property_type <> ''")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $t) {
                    $t = trim((string)$t);
                    if ($t !== '' && !in_array($t, $types, true)) {
                        $types[] = $t;
                    }
                }
                $rows = $pdo->query("SELECT DISTINCT transaction_type FROM ads WHERE transaction_type IS NOT NULL AND transaction_type <> ''")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $t) {
                    $t = trim((string)$t);
                    if ($t !== '' && !in_array($t, $transactions, true)) {
                        $transactions[] = $t;
                    }
                }
            } catch (Throwable $e) {
                // فهرست ثابت کافی است
            }
        }

        return ['property_types' => $types, 'transactions' => $transactions];
    }
}

/**
 * ماتریس تنظیمات انتشار به تفکیک «نوع ملک × نوع معامله».
 * قالب ذخیره‌سازی (یک ردیف settings برای هر پلتفرم):
 *   ['v' => 1, 'overrides' => ['آپارتمان|فروش' => ['title', ...], 'زمین|' => [...]]]
 * کلیدِ «همه» رشتهٔ خالی است؛ یعنی 'آپارتمان|' = همهٔ معامله‌های آپارتمان.
 */
if (!function_exists('melkinoLegacyPublishMatrix')) {
    function melkinoLegacyPublishMatrix(string $platform): array
    {
        global $pdo;
        $platform = melkinoPublishPlatform($platform);
        if (!($pdo instanceof PDO)) {
            return [];
        }
        try {
            $stored = dbSettingGet($pdo, 'publish', 'fields_matrix_' . $platform, null);
            if (is_array($stored) && isset($stored['overrides']) && is_array($stored['overrides'])) {
                $overrides = $stored['overrides'];
                // راند ۲۲: تنظیم‌های اختصاصیِ ذخیره‌شدهٔ قدیمی (v1) کلیدهای جدید
                // «قیمت هر متر» و «پیش‌پرداخت» را ندارند → به‌صورت پیش‌فرض اضافه
                // می‌شوند (ادمین هر وقت خواست خاموششان می‌کند و v2 ذخیره می‌شود).
                if ((int)($stored['v'] ?? 1) < 2) {
                    $allDefs = array_keys(melkinoPublishFieldDefs());
                    foreach ($overrides as $__ck => $__list) {
                        if (!is_array($__list)) {
                            continue;
                        }
                        foreach (['price_per_meter', 'down_payment'] as $__nk) {
                            if (in_array($__nk, $allDefs, true) && !in_array($__nk, $__list, true)) {
                                $__list[] = $__nk;
                            }
                        }
                        $overrides[$__ck] = array_values($__list);
                    }
                }
                return $overrides;
            }
        } catch (Throwable $e) {
        }
        return [];
    }
}

if (!function_exists('melkinoLegacyPublishSettings')) {
    /**
     * @param string $propertyType    نوع ملکِ آگهی (خالی = تنظیمات عمومی)
     * @param string $transactionType نوع معاملهٔ آگهی (خالی = تنظیمات عمومی)
     *
     * ترتیب اولویت: ترکیب دقیق «نوع|معامله» ← فقط نوع ← فقط معامله ← عمومی.
     */
    function melkinoLegacyPublishSettings(string $platform, string $propertyType = '', string $transactionType = ''): array
    {
        global $pdo;
        $platform = melkinoPublishPlatform($platform);
        $defaults = array_keys(melkinoPublishFieldDefs());
        if (in_array($platform, ['bale','eitaa'], true)) {
            // پیش‌فرض بله/ایتا: نام و شماره‌ی ثبت‌کننده منتشر نمی‌شود (همان رفتار
            // قبلی مسیر مستقیم بله)؛ ادمین می‌تواند آن‌ها را فعال کند.
            $defaults = array_values(array_diff($defaults, ['contact', 'phone']));
        }

        $fields = $defaults;
        $header = '';
        $footer = '';
        $isOverride = false;

        $propertyType = trim($propertyType);
        $transactionType = trim($transactionType);

        if ($pdo instanceof PDO) {
            try {
                $stored = dbSettingGet($pdo, 'publish', 'fields_' . $platform, null);
                if (is_array($stored)) {
                    // قالب جدید ذخیره‌سازی: ['v'=>2,'fields'=>[...]] ؛ قالب قدیمی
                    // یک فهرست سادهٔ کلیدها بود.
                    $hasVersion = isset($stored['fields']) && is_array($stored['fields']);
                    $list = $hasVersion ? $stored['fields'] : $stored;
                    // فقط کلیدهای معتبر نگه داشته می‌شوند؛ ترتیب همان ترتیب پیش‌فرض است
                    $fields = array_values(array_intersect($defaults, array_map('strval', $list)));
                    // تنظیماتِ ذخیره‌شدهٔ قدیمی (قبل از افزودن فیلد «لینک آگهی»)
                    // این کلید را ندارند؛ برای آن‌ها فیلد جدید پیش‌فرض روشن است
                    // تا لینک آگهی منتشر شود؛ ادمین هر وقت خواست خاموشش می‌کند
                    // (بعد از اولین ذخیرهٔ جدید، خاموش‌بودن صریح ذخیره می‌شود).
                    if (!$hasVersion
                        && !in_array('ad_link', $fields, true)
                        && in_array('ad_link', $defaults, true)) {
                        $fields[] = 'ad_link';
                    }
                    // راند ۲۲: تنظیماتِ ذخیره‌شدهٔ قدیمی (v<3) کلیدهای «قیمت هر
                    // متر» و «پیش‌پرداخت» را ندارند → پیش‌فرض روشن اضافه می‌شوند.
                    $__ver = $hasVersion ? (int)($stored['v'] ?? 2) : 1;
                    if ($__ver < 3) {
                        foreach (['price_per_meter', 'down_payment'] as $__nk) {
                            if (in_array($__nk, $defaults, true) && !in_array($__nk, $fields, true)) {
                                $fields[] = $__nk;
                            }
                        }
                    }
                }
                $header = (string)dbSettingGet($pdo, 'publish', 'header_' . $platform, '');
                $footer = (string)dbSettingGet($pdo, 'publish', 'footer_' . $platform, '');

                // تنظیمِ اختصاصیِ این ترکیب (اگر ادمین ذخیره کرده باشد)
                if ($propertyType !== '' || $transactionType !== '') {
                    $matrix = melkinoLegacyPublishMatrix($platform);
                    foreach ([$propertyType . '|' . $transactionType, $propertyType . '|', '|' . $transactionType] as $key) {
                        if (isset($matrix[$key]) && is_array($matrix[$key])) {
                            $fields = array_values(array_intersect($defaults, array_map('strval', $matrix[$key])));
                            $isOverride = true;
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                // در صورت خطا، پیش‌فرض‌ها برگردانده می‌شوند
            }
        }

        return [
            'fields'      => $fields,
            'header'      => $header,
            'footer'      => $footer,
            'is_override' => $isOverride,
        ];
    }
}

require_once __DIR__ . '/publish-content.php';

if (!function_exists('melkinoSaveBotSettings')) {
    /** Legacy callers keep working, but only provided keys are patched atomically. */
    function melkinoSaveBotSettings(array $data): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO)) return false;
        require_once __DIR__ . '/bot-settings-write.php';
        $scope = is_string($data['scope'] ?? null) ? $data['scope'] : 'all';
        try { return melkinoSaveBotSection($pdo, $data, $scope)['success']; }
        catch (InvalidArgumentException $e) { throw $e; }
        catch (Throwable $e) { return false; }
    }
}

if (!function_exists('melkinoTelegramToken')) {
    function melkinoTelegramToken(): string
    {
        $fromDb = trim(melkinoBotSetting('telegram_token'));
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('BOT_TOKEN') ? trim((string)BOT_TOKEN) : '';
    }
}

if (!function_exists('melkinoBaleToken')) {
    function melkinoBaleToken(): string
    {
        $fromDb = trim(melkinoBotSetting('bale_token'));
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('BALE_BOT_TOKEN') ? trim((string)BALE_BOT_TOKEN) : '';
    }
}

if (!function_exists('melkinoEitaaValidLaunchUrl')) {
    function melkinoEitaaValidLaunchUrl(string $url): bool
    {
        return strlen($url) <= 768 && preg_match('#\Ahttps://eitaa\.com/[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+)?/?(?:\?startapp=[A-Za-z0-9_-]{0,512})?\z#i', $url) === 1;
    }
}

if (!function_exists('melkinoEitaaMiniappUrl')) {
    function melkinoEitaaMiniappUrl(): string
    {
        $url = trim(melkinoBotSetting('eitaa_miniapp_url'));
        if ($url !== '' && melkinoEitaaValidLaunchUrl($url)) return $url;
        $username = ltrim(trim(melkinoBotSetting('eitaa_bot_username')), '@');
        return preg_match('/^[A-Za-z0-9_]{3,64}$/D', $username)
            ? 'https://eitaa.com/' . rawurlencode($username) : '';
    }
}

if (!function_exists('melkinoEitaaToken')) {
    function melkinoEitaaToken(): string
    {
        $fromDb = trim(melkinoBotSetting('eitaa_token'));
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('EITAA_BOT_TOKEN') ? trim((string)EITAA_BOT_TOKEN) : '';
    }
}

/**
 * پروکسیِ اختیاری برای ارتباط با سرورهای تلگرام/بله.
 * روی هاست‌هایی که دسترسی مستقیم به api.telegram.org ندارند، ادمین می‌تواند
 * نشانیِ یک پروکسی (مثلاً http://user:pass@1.2.3.4:8080 یا socks5://...) را
 * ثبت کند تا همه‌ی درخواست‌ها از آن عبور کنند.
 */
if (!function_exists('melkinoProxy')) {
    function melkinoProxy(): string
    {
        return melkinoBotSetting('http_proxy');
    }
}

if (!function_exists('melkinoChannelId')) {
    function melkinoChannelId(): string
    {
        $fromDb = melkinoBotSetting('telegram_channel');
        if ($fromDb !== '') {
            return $fromDb;
        }
        return defined('CHANNEL_ID') ? (string)CHANNEL_ID : '';
    }
}

/**
 * تست اتصال به ربات تلگرام (متد getMe)
 * خروجی: ['success'=>bool, 'message'=>string, 'username'=>?string]
 */
if (!function_exists('melkinoTestTelegramConnection')) {
    function melkinoTestTelegramConnection(?string $token = null): array
    {
        $token = $token !== null ? trim($token) : melkinoTelegramToken();
        if ($token === '') {
            return ['success' => false, 'message' => 'توکن تلگرام تنظیم نشده است.', 'username' => null];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/getMe';
        $response = function_exists('melkinoHttpPost')
            ? melkinoHttpPost($url, http_build_query([]))
            : @file_get_contents($url);

        if ($response === null || $response === '' || $response === false) {
            return [
                'success'  => false,
                'message'  => 'ارتباط با سرور تلگرام برقرار نشد (بازیابی خودکار DNS هم امتحان شد). اگر هاست شما به api.telegram.org '
                            . 'دسترسی ندارد (مثلاً داخل ایران)، یک پروکسی در همین بخش ثبت کنید.',
                'username' => null,
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? ($data['description'] ?? 'پاسخ نامعتبر') : 'پاسخ نامعتبر از سرور تلگرام';
            return ['success' => false, 'message' => 'تلگرام: ' . $desc, 'username' => null];
        }

        return [
            'success' => true,
            'message' => 'اتصال به تلگرام برقرار است.',
            'username' => $data['result']['username'] ?? null,
        ];
    }
}

/**
 * تست اتصال به ربات بله (متد getMe)
 */
if (!function_exists('melkinoTestBaleConnection')) {
    function melkinoTestBaleConnection(?string $token = null): array
    {
        $token = $token !== null ? trim($token) : melkinoBaleToken();
        if ($token === '') {
            return ['success' => false, 'message' => 'توکن بله تنظیم نشده است.', 'username' => null];
        }

        $url = 'https://tapi.bale.ai/bot' . $token . '/getMe';
        $response = function_exists('melkinoHttpPost')
            ? melkinoHttpPost($url, http_build_query([]))
            : @file_get_contents($url);

        if ($response === null || $response === '' || $response === false) {
            return [
                'success'  => false,
                'message'  => 'ارتباط با سرور بله برقرار نشد (بازیابی خودکار DNS هم امتحان شد). اتصال اینترنتِ هاست یا تنظیمات پروکسی را بررسی کنید.',
                'username' => null,
            ];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? ($data['description'] ?? 'پاسخ نامعتبر') : 'پاسخ نامعتبر از سرور بله';
            return ['success' => false, 'message' => 'بله: ' . $desc, 'username' => null];
        }

        return [
            'success' => true,
            'message' => 'اتصال به بله برقرار است.',
            'username' => $data['result']['username'] ?? null,
        ];
    }
}

if (!function_exists('melkinoTestEitaaConnection')) {
    function melkinoTestEitaaConnection(?string $token = null): array
    {
        require_once __DIR__ . '/bot-settings-write.php';
        $token = $token !== null && melkinoBotTrim($token) !== '' ? melkinoBotTrim($token) : melkinoEitaaToken();
        if ($token === '' || $token === '-') return ['success'=>false,'message'=>'توکن برنامه ایتا تنظیم نشده است.'];
        try { melkinoBotValidateValue('eitaa_token',$token); }
        catch (MelkinoBotSettingsError $e) { return ['success'=>false,'message'=>$e->getMessage()]; }
        return ['success'=>true,'checked'=>'configuration_only','username'=>null,
            'message'=>'قالب کلید پذیرفته شد؛ اعتبار واقعی توکن هنوز تأیید نشده. ورود را از برنامک همان برنامه بررسی کنید. توکن API کانال فیلد جدا دارد.'];
    }
}
