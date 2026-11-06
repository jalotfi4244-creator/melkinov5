<?php
/*
|--------------------------------------------------------------------------
| رجیستری رویدادهای اعلان سیستمی (راند ۲۰)
|--------------------------------------------------------------------------
| فهرست مرکزی همهٔ ارتباطاتی که سیستم با کاربر برقرار می‌کند + کلید
| فعال/غیرفعال هر رویداد. هر اعلان داخلی قبل از ثبت، از
| melkinoNotificationEventEnabled() عبور می‌کند؛ اگر ادمین آن رویداد را
| خاموش کرده باشد، اعلان بی‌صدا رد می‌شود (بدون خطا).
|
| تنظیمات در db_settings (گروه global، کلید notification_events) به‌صورت
| JSON ذخیره می‌شود: {"welcome":true,"property_match":false,...}
| سوئیچ اصلی enable_notifications (تب تنظیمات عمومی) همچنان بالادست همه
| رویدادهاست: اگر خاموش باشد هیچ اعلان خودکاری ارسال نمی‌شود.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoNotificationEventDefs')) {
    /**
     * تعریف همهٔ رویدادهای اعلان.
     * toggleable=false → رویداد فقط برای شناسایی فهرست شده (کنترلش جای دیگری است).
     */
    function melkinoNotificationEventDefs(): array
    {
        return [
            'welcome' => [
                'emoji' => '👋', 'title' => 'خوش‌آمدگویی کاربر جدید',
                'desc' => 'اولین اعلانی که کاربر تازه‌وارد (ورود با تلگرام، بله یا پیامک) دریافت می‌کند.',
                'where' => 'هنگام ساخت حساب کاربری جدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_submitted' => [
                'emoji' => '📝', 'title' => 'ثبت آگهی',
                'desc' => '«آگهی شما ثبت شد و پس از بررسی منتشر می‌شود» برای ثبت‌کنندهٔ آگهی.',
                'where' => 'بلافاصله بعد از ثبت آگهی در فرم‌های ثبت ملک', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_published' => [
                'emoji' => '✅', 'title' => 'تأیید و انتشار آگهی',
                'desc' => '«آگهی شما منتشر شد» وقتی ادمین آگهی را تأیید می‌کند.',
                'where' => 'ذخیرهٔ وضعیت در پنل ادمین (تأیید/انتشار)', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_rejected' => [
                'emoji' => '❌', 'title' => 'رد شدن آگهی',
                'desc' => '«آگهی شما رد شد» با راهنمای تماس با پشتیبانی.',
                'where' => 'ذخیرهٔ وضعیت در پنل ادمین (رد کردن)', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_revision_approved' => [
                'emoji' => '📝', 'title' => 'تأیید ویرایش مالک',
                'desc' => '«ویرایش آگهی شما تأیید شد و آگهی دوباره منتشر شد» وقتی ادمین ویرایش در انتظار را تأیید می‌کند.',
                'where' => 'پنل ادمین ← ویرایش‌های در انتظار تأیید ← تأیید و انتشار', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_revision_rejected' => [
                'emoji' => '❌', 'title' => 'رد ویرایش مالک',
                'desc' => '«ویرایش پیشنهادی شما رد شد؛ اطلاعات قبلی آگهی حفظ شد» وقتی ادمین ویرایش در انتظار را رد می‌کند.',
                'where' => 'پنل ادمین ← ویرایش‌های در انتظار تأیید ← رد', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'support_reply' => [
                'emoji' => '🎧', 'title' => 'پاسخ پشتیبانی به تیکت',
                'desc' => 'وقتی ادمین به تیکت کاربر پاسخ می‌دهد، به کاربر اعلان داده می‌شود.',
                'where' => 'پنل ادمین ← پشتیبانی ← ارسال پاسخ', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'support_closed' => [
                'emoji' => '🔒', 'title' => 'بسته شدن تیکت پشتیبانی',
                'desc' => 'وقتی ادمین تیکت را می‌بندد، به کاربر اعلان داده می‌شود.',
                'where' => 'پنل ادمین ← پشتیبانی ← بستن تیکت', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'ad_status' => [
                'emoji' => '🔄', 'title' => 'تغییر وضعیت آگهی (فروخته/معلق)',
                'desc' => 'وقتی وضعیت آگهی به «فروخته/اجاره‌شده» یا «معلق» تغییر کند به مالک خبر داده می‌شود.',
                'where' => 'ذخیرهٔ وضعیت در پنل ادمین', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'request_submitted' => [
                'emoji' => '📋', 'title' => 'ثبت درخواست خرید',
                'desc' => '«درخواست شما با کد پیگیری ثبت شد» برای متقاضی.',
                'where' => 'بعد از ثبت فرم درخواست ملک', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'request_status' => [
                'emoji' => '🔔', 'title' => 'تغییر وضعیت درخواست خرید',
                'desc' => 'وقتی ادمین وضعیت درخواست (در حال بررسی/تکمیل/رد) را عوض کند به متقاضی خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست‌ها', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_submitted' => [
                'emoji' => '🏠', 'title' => 'ثبت درخواست بازدید',
                'desc' => '«درخواست بازدید شما برای آگهی … ثبت شد همکاران ما جهت هماهنگی بازدید با شما تماس خواهند گرفت».',
                'where' => 'صفحه جزئیات ملک ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_scheduled' => [
                'emoji' => '📅', 'title' => 'هماهنگ شده با مالک',
                'desc' => 'وقتی ادمین وضعیت را «هماهنگ شده با مالک» کند به کاربر خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_owner_rejected' => [
                'emoji' => '❌', 'title' => 'رد تاریخ توسط مالک',
                'desc' => 'وقتی مالک تاریخ پیشنهادی را رد کند به درخواست‌کننده خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_user_notified' => [
                'emoji' => '📞', 'title' => 'اطلاع داده شده به کاربر',
                'desc' => 'وقتی ادمین نتیجه هماهنگی را به کاربر اطلاع داده علامت بزند.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_cancelled' => [
                'emoji' => '❌', 'title' => 'لغو بازدید توسط کاربر',
                'desc' => 'وقتی درخواست بازدید لغو شود به کاربر خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست بازدید / پروفایل کاربر', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_time_changed' => [
                'emoji' => '🕒', 'title' => 'تغییر زمان توسط کاربر',
                'desc' => 'وقتی زمان بازدید تغییر کند به کاربر خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_no_response' => [
                'emoji' => '🔕', 'title' => 'عدم پاسخگویی کاربر',
                'desc' => 'وقتی ادمین وضعیت را «عدم پاسخگویی کاربر» کند.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'visit_visited' => [
                'emoji' => '🏡', 'title' => 'بازدید شده',
                'desc' => 'وقتی ادمین وضعیت را «بازدید شده» کند به کاربر خبر داده می‌شود.',
                'where' => 'پنل ادمین ← درخواست بازدید', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'property_match' => [
                'emoji' => '🎯', 'title' => 'فایل مناسب پیدا شد (تطابق ≥۷۰٪)',
                'desc' => 'وقتی آگهی یا درخواستی با درصد تطابق ۷۰ به بالا پیدا شود، برای متقاضی اعلان «ملک مناسب برای شما پیدا شد» ثبت می‌شود.',
                'where' => 'هنگام ثبت درخواست جدید (مچ با آگهی‌های موجود)', 'channel' => 'اعلان داخلی', 'toggleable' => true,
            ],
            'broadcast' => [
                'emoji' => '📢', 'title' => 'اعلان عمومی دستی',
                'desc' => 'اعلانی که خود ادمین از همین تب برای همهٔ کاربران می‌فرستد. چون ارسالش دستی است، کلید فعال/غیرفعال ندارد.',
                'where' => 'پنل ادمین ← اعلان‌ها ← ارسال اعلان عمومی', 'channel' => 'اعلان داخلی', 'toggleable' => false,
            ],
            'sms_otp' => [
                'emoji' => '💬', 'title' => 'پیامک کد ورود',
                'desc' => 'کد یکبارمصرف ورود با شماره موبایل از طریق پنل پیامک ارسال می‌شود. مدیریتش در «ربات و کانال ← کارت پیامک» و «روش‌های ورود» است (خاموش کردنش = غیرفعال کردن ورود پیامکی).',
                'where' => 'صفحهٔ ورود ← درخواست کد', 'channel' => 'پیامک', 'toggleable' => false,
            ],
        ];
    }
}

if (!function_exists('melkinoNotificationEventsState')) {
    /** نقشهٔ id => bool (ذخیره‌شده در DB، ادغام با پیش‌فرض‌ها) */
    function melkinoNotificationEventsState(): array
    {
        global $pdo;
        $defs = melkinoNotificationEventDefs();
        $state = [];
        foreach ($defs as $id => $def) {
            $state[$id] = true; // پیش‌فرض: فعال
        }
        try {
            if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
                $raw = dbSettingGet($pdo, 'global', 'notification_events', '');
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $id => $on) {
                            if (isset($state[$id])) {
                                $state[$id] = (bool)$on;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // در خطا، پیش‌فرض فعال می‌ماند
        }
        return $state;
    }
}

if (!function_exists('melkinoNotificationEventEnabled')) {
    /**
     * آیا اعلان این رویداد فعال است؟
     * سوئیچ اصلی enable_notifications بالادست همهٔ رویدادهاست.
     * رویدادهای ناشناخته (typeهای دلخواه/قدیمی) به‌صورت پیش‌فرض فعال‌اند.
     */
    function melkinoNotificationEventEnabled(string $eventId): bool
    {
        if (function_exists('melkinoEventsEnabled') && !melkinoEventsEnabled()) {
            return false;
        }
        $defs = melkinoNotificationEventDefs();
        if (!isset($defs[$eventId])) {
            return true;
        }
        $state = melkinoNotificationEventsState();
        return !empty($state[$eventId]);
    }
}

if (!function_exists('melkinoSaveNotificationEvents')) {
    /** ذخیرهٔ نقشهٔ فعال/غیرفعال رویدادها (فقط رویدادهای toggleable) */
    function melkinoSaveNotificationEvents(array $map): bool
    {
        global $pdo;
        if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
            return false;
        }
        $defs = melkinoNotificationEventDefs();
        $clean = [];
        foreach ($defs as $id => $def) {
            if (empty($def['toggleable'])) {
                continue;
            }
            $clean[$id] = array_key_exists($id, $map) ? (bool)$map[$id] : true;
        }
        return (bool)dbSettingSet(
            $pdo,
            'global',
            'notification_events',
            json_encode($clean, JSON_UNESCAPED_UNICODE)
        );
    }
}
