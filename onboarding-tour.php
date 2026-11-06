<?php
/**
 * لایهٔ مستقل Interactive Product Tour ملکینو.
 * منطق کسب‌وکار را تغییر نمی‌دهد؛ فقط روی رابط موجود سوار می‌شود.
 */
require_once __DIR__ . '/onboarding-guides.php';

if (!function_exists('melkinoProductTourDefaults')) {
    function melkinoProductTourDefaults(string $tour = 'user'): array
    {
        if ($tour === 'admin') {
            return [
                ['id' => 'welcome', 'target' => '', 'title' => 'داشبورد مدیریت', 'description' => 'از این نوار به تمام بخش‌های پنل ملکینو دسترسی دارید. هر تب یک حوزهٔ مدیریت جداست.', 'position' => 'bottom', 'order' => 10, 'page' => 'admin-panel.php', 'action' => '', 'enabled' => true],
                ['id' => 'tabs', 'target' => '#adminNavGroups', 'title' => 'منوی تب‌ها', 'description' => 'ردیف بالا گروه‌هاست. روی هر گروه بزنید تا زیرمجموعه‌هایش در ردیف پایین بیاید.', 'position' => 'bottom', 'order' => 15, 'page' => 'admin-panel.php', 'enabled' => true],
                ['id' => 'ads', 'target' => '[data-tour="admin-ads"]', 'title' => 'مدیریت فایل‌ها', 'description' => 'آگهی‌های کاربران را بررسی، تأیید، رد یا ویرایش کنید. روی نقشهٔ همین تب می‌توانید موقعیت فایل را دقیق بگذارید.', 'position' => 'bottom', 'order' => 20, 'page' => 'admin-panel.php', 'action' => 'switchTab:ads', 'enabled' => true],
                ['id' => 'map-admin', 'target' => '[data-tour="admin-map"]', 'title' => 'نقشه و حریم', 'description' => 'از تب نقشه شعاع جابه‌جایی پین عمومی (حدود ۵۰ متر)، نمایش نقشه و موقعیت هر فایل را تنظیم کنید.', 'position' => 'bottom', 'order' => 25, 'page' => 'admin-panel.php', 'action' => 'switchTab:map', 'enabled' => true],
                ['id' => 'map-marker-design', 'target' => '[data-panel="map"], [data-tour="admin-studio"]', 'title' => 'شکل مارکر و کارت نقشه', 'description' => 'در «تم و استودیو طراحی» بخش «نقشه و مارکر» را باز کنید: شکل مارکر (پین، پرچم، خانه، برج، ستاره و…)، رنگ، اندازه، برچسب قیمت و طراحی کارتی که روی نقشه باز می‌شود، همه از همان‌جا تنظیم می‌شود.', 'position' => 'bottom', 'order' => 26, 'page' => 'admin-panel.php', 'action' => 'switchTab:studio', 'enabled' => true],
                ['id' => 'requests', 'target' => '[data-tour="admin-requests"]', 'title' => 'درخواست‌های کاربران', 'description' => 'درخواست‌های ملکی ثبت‌شده را مشاهده کنید و فایل‌های متناسب را پیگیری نمایید.', 'position' => 'bottom', 'order' => 30, 'page' => 'admin-panel.php', 'action' => 'switchTab:requests', 'enabled' => true],
                ['id' => 'users', 'target' => '[data-tour="admin-users"]', 'title' => 'کاربران', 'description' => 'حساب‌ها، شماره تماس و تاریخچهٔ فعالیت کاربران را از این تب مدیریت کنید.', 'position' => 'bottom', 'order' => 40, 'page' => 'admin-panel.php', 'action' => 'switchTab:users', 'enabled' => true],
                ['id' => 'support', 'target' => '[data-tour="admin-support"]', 'title' => 'پشتیبانی', 'description' => 'تیکت‌های کاربران را بخوانید و پاسخ دهید.', 'position' => 'bottom', 'order' => 50, 'page' => 'admin-panel.php', 'action' => 'switchTab:support', 'enabled' => true],
                ['id' => 'settings', 'target' => '[data-tour="admin-global"]', 'title' => 'تنظیمات', 'description' => 'تنظیمات عمومی سامانه، نمایش قیمت و رفتارهای سراسری از تب «عمومی» کنترل می‌شود.', 'position' => 'bottom', 'order' => 60, 'page' => 'admin-panel.php', 'action' => 'switchTab:global', 'enabled' => true],
                ['id' => 'images', 'target' => '[data-tour="admin-images"]', 'title' => 'مدیریت تصاویر', 'description' => 'تصاویر پیش‌فرض انواع ملک و لوگوی سایت را از این بخش مدیریت کنید.', 'position' => 'bottom', 'order' => 70, 'page' => 'admin-panel.php', 'action' => 'switchTab:images', 'enabled' => true],
                ['id' => 'publish', 'target' => '[data-tour="admin-bots"]', 'title' => 'انتشار فایل', 'description' => 'اتصال ربات و کانال تلگرام/بله و انتشار آگهی‌ها از تب «ربات و کانال» انجام می‌شود.', 'position' => 'bottom', 'order' => 80, 'page' => 'admin-panel.php', 'action' => 'switchTab:bots', 'enabled' => true],
                ['id' => 'fields', 'target' => '[data-tour="admin-display"]', 'title' => 'مدیریت فیلدها', 'description' => 'نمایش فیلدها روی کارت، فهرست و صفحهٔ جزئیات را از تب «نمایش» تنظیم کنید.', 'position' => 'bottom', 'order' => 90, 'page' => 'admin-panel.php', 'action' => 'switchTab:display', 'enabled' => true],
                ['id' => 'reports', 'target' => '[data-tour="admin-diagnostics"]', 'title' => 'گزارش‌ها', 'description' => 'عیب‌یاب و پشتیبان‌گیری در یک تب ادغام شده‌اند: «عیب‌یابی و پشتیبان». با نوار بالای صفحه بین «عیب‌یابی سیستم» و «پشتیبان‌گیری» جابه‌جا شوید. عیب‌یاب بررسی‌های تازه‌ای دارد: لایهٔ امنیت، محافظت پوشهٔ آپلود، جدول‌های حسابرسی و سلامت تم نقشه.', 'position' => 'bottom', 'order' => 100, 'page' => 'admin-panel.php', 'action' => 'switchTab:diagnostics', 'enabled' => true],
                ['id' => 'done', 'target' => '', 'title' => 'پنل آماده است', 'description' => 'حالا می‌توانید فایل‌ها، درخواست‌ها و تنظیمات ملکینو را از همین پنل مدیریت کنید.', 'position' => 'center', 'order' => 110, 'page' => 'admin-panel.php', 'cta' => 'شروع کار با پنل', 'enabled' => true],
            ];
        }

        return [
            ['id' => 'welcome', 'target' => '', 'title' => 'به ملکینو خوش آمدید', 'description' => 'اول وارد حساب می‌شوید، بعد پروفایل و امکانات ملکینو را با هم می‌بینیم.', 'position' => 'center', 'order' => 10, 'page' => 'home.php', 'cta' => 'برو به پروفایل', 'kicker' => 'Onboarding Tour', 'enabled' => true],
            ['id' => 'profile', 'target' => '[data-tour="profile-page"], .profile-hero', 'title' => 'پروفایل شما', 'description' => 'این صفحه حساب شماست: نام و شماره، آگهی‌ها، درخواست‌ها، علاقه‌مندی‌ها و اعلان‌ها. بعد از ورود پیامرسان اینجا هویت شما دیده می‌شود.', 'position' => 'bottom', 'order' => 15, 'page' => 'profile.php', 'enabled' => true],
            ['id' => 'home', 'target' => '[data-tour="nav-home"], .bottom-nav a[href="home.php"]', 'title' => 'خانه ملکینو', 'description' => 'از اینجا می‌توانید به فایل‌های ملکی، جستجو، درخواست‌ها و امکانات اصلی دسترسی داشته باشید.', 'position' => 'top', 'order' => 20, 'page' => 'home.php', 'enabled' => true],
            ['id' => 'theme', 'target' => '[data-tour="theme"], #themeBtn, .theme-toggle-btn', 'title' => 'حالت روشن و تاریک', 'description' => 'با این دکمه ظاهر ملکینو را بین حالت روشن و تاریک عوض کنید. انتخاب شما ذخیره می‌شود.', 'position' => 'bottom', 'order' => 25, 'page' => '*', 'enabled' => true],
            ['id' => 'search', 'target' => '[data-tour="home-search"], a.home-shortcut[href="search.php"], [data-tour="nav-search"]', 'title' => 'جستجوی سریع ملک', 'description' => 'نوع ملک، نوع معامله، محدوده قیمت و سایر مشخصات موردنظر خود را انتخاب کنید تا فایل‌های مناسب را پیدا کنید.', 'position' => 'bottom', 'order' => 30, 'page' => ['home.php', 'index.php', 'search.php'], 'enabled' => true],
            ['id' => 'map', 'target' => '[data-tour="nav-map"], [data-tour="home-map"], a[href="map.php"]', 'title' => 'نقشه املاک', 'description' => 'همهٔ فایل‌های منتشرشده روی نقشه شاهرود با مارکر دیده می‌شوند. برای باز کردن نقشه این دکمه را بزنید — هم در نوار پایین و هم در شورتکات‌های خانه هست.', 'position' => 'top', 'order' => 31, 'page' => ['home.php', 'index.php', 'map.php'], 'enabled' => true],
            ['id' => 'map-filters', 'target' => '#mkMapTx, .mk-map-tx', 'title' => 'فیلتر فروش / اجاره / پیش‌فروش', 'description' => 'با این دکمه‌ها نوع معامله را محدود کنید. نقشه بلافاصله فقط فایل‌های همان نوع را نشان می‌دهد.', 'position' => 'bottom', 'order' => 31.1, 'page' => 'map.php', 'enabled' => true],
            ['id' => 'map-types', 'target' => '#mkMapTypes, .mk-map-types', 'title' => 'انتخاب نوع ملک', 'description' => 'از این کرکره آپارتمان، ویلایی، باغ، زمین، تجاری یا اداری را انتخاب کنید تا نقشه شلوغ نشود.', 'position' => 'bottom', 'order' => 31.2, 'page' => 'map.php', 'enabled' => true],
            ['id' => 'map-markers', 'target' => '#mkMapCanvas .mk-pin-wrap, #mkMapCanvas', 'title' => 'مارکرها روی نقشه', 'description' => 'هر مارکر یک فایل است. جای مارکر عمداً حدود ۵۰ متر جابه‌جا شده تا حریم و آدرس دقیق ملک محفوظ بماند — پس نقطه تقریبی است، نه آدرس واقعی. مارکرهای طلایی فایل‌های ویژه (VIP) هستند.', 'position' => 'top', 'order' => 31.3, 'page' => 'map.php', 'enabled' => true],
            ['id' => 'map-card', 'target' => '#mkMapSheet, #mkMapCanvas', 'title' => 'کارت فایل روی نقشه', 'description' => 'با زدن روی هر مارکر، کارت فایل پایین صفحه باز می‌شود: عکس، نوع معامله، امکانات و قیمت. با دکمهٔ «مشاهده فایل» وارد صفحهٔ جزئیات می‌شوید و با «بستن» به نقشه برمی‌گردید.', 'position' => 'top', 'order' => 31.4, 'page' => 'map.php', 'enabled' => true],
            ['id' => 'map-move', 'target' => '#mkMapCanvas', 'title' => 'جابه‌جایی و بزرگ‌نمایی', 'description' => 'نقشه را با انگشت بکشید و با دو انگشت زوم کنید. هر بار که نقشه می‌ایستد، فایل‌های همان محدوده دوباره بارگذاری می‌شوند.', 'position' => 'center', 'order' => 31.5, 'page' => 'map.php', 'enabled' => true],
            ['id' => 'vip', 'target' => '[data-tour="home-vip"], a.home-shortcut[href="vip.php"]', 'title' => 'فایل‌های VIP', 'description' => 'فایل‌های ویژه و منتخب ملکینو را در بخش VIP ببینید؛ این آگهی‌ها برجسته‌تر نمایش داده می‌شوند.', 'position' => 'bottom', 'order' => 32, 'page' => ['home.php', 'index.php', 'vip.php'], 'enabled' => true],
            ['id' => 'register', 'target' => '[data-tour="home-register"], a.home-shortcut[href="register-step1.php"], [data-tour="nav-register"]', 'title' => 'ثبت ملک', 'description' => 'اگر فایل برای فروش، رهن یا اجاره دارید، از اینجا آگهی خود را مرحله‌به‌مرحله ثبت کنید.', 'position' => 'bottom', 'order' => 34, 'page' => ['home.php', 'index.php', 'register-step1.php', 'register-choose.php'], 'enabled' => true],
            ['id' => 'cards', 'target' => '[data-tour="property-cards"] .property-card, #mkHomeAdsList .property-card, .property-card', 'title' => 'فایل‌های ملکی', 'description' => 'هر فایل اطلاعات مهم ملک، تصاویر، قیمت و مشخصات اصلی را در اختیار شما قرار می‌دهد.', 'position' => 'top', 'order' => 40, 'page' => ['home.php', 'index.php', 'properties.php', 'vip.php'], 'enabled' => true],
            ['id' => 'details', 'target' => '.property-detail, a.property-detail, [data-tour="property-details"]', 'title' => 'جزئیات کامل ملک', 'description' => 'برای مشاهده تصاویر، مشخصات، امکانات و اطلاعات بیشتر، وارد صفحه جزئیات ملک شوید.', 'position' => 'top', 'order' => 50, 'page' => ['home.php', 'index.php', 'properties.php', 'property-details.php'], 'enabled' => true],
            ['id' => 'visit', 'target' => melkinoTourLatestPropertyUrl() !== '' ? '[data-tour="visit-request"], #visitRequestOpenBtn, [data-modal-open="mkVisit"]' : '', 'title' => 'ثبت درخواست بازدید ملک', 'description' => (melkinoTourLatestPropertyUrl() === '' ? 'فعلاً آگهی منتشرشده‌ای برای نمایش نمونه در دسترس نیست. ' : 'این صفحه، جزئیات آخرین آگهی منتشرشده است. ') . 'با دکمهٔ «درخواست بازدید» روز و بازهٔ صبح یا عصر را انتخاب کنید و درخواست را بفرستید. پیگیری هماهنگی از پروفایل، بخش بازدیدهاست. این آموزش هیچ درخواستی ثبت نمی‌کند.', 'position' => 'top', 'order' => 52, 'page' => melkinoTourLatestPropertyUrl() ?: '*', 'showIfMissing' => true, 'enabled' => true],
            ['id' => 'share', 'target' => melkinoTourLatestPropertyUrl() !== '' ? '#shareBtn, [data-share-ad]' : '', 'title' => 'اشتراک‌گذاری آگهی', 'description' => 'این دکمه لینک همین آگهی را برای ارسال در پیام‌رسان‌ها می‌سازد؛ اگر اشتراک‌گذاری دستگاه پشتیبانی نشود، لینک کپی می‌شود. گیرندهٔ مهمان فقط همین آگهی را می‌بیند و برای صفحات دیگر، بازدید، تماس یا پیام به مشاور باید وارد شود.', 'position' => 'top', 'order' => 53, 'page' => melkinoTourLatestPropertyUrl() ?: '*', 'showIfMissing' => true, 'enabled' => true],
            ['id' => 'compare', 'target' => '[data-tour="compare"], .property-compare, [data-compare-add], a.profile-stat[href="compare-page.php"]', 'title' => 'مقایسه ملک‌ها', 'description' => 'چند فایل هم‌نوع را کنار هم بگذارید و مشخصات، قیمت و امتیازشان را مقایسه کنید.', 'position' => 'top', 'order' => 55, 'page' => ['home.php', 'index.php', 'properties.php', 'profile.php', 'compare-page.php'], 'enabled' => true],
            ['id' => 'favorite', 'target' => '[data-fav-toggle], .property-like, a[href="favorites.php"]', 'title' => 'ملک‌های موردعلاقه', 'description' => 'با زدن قلب روی کارت، فایل ذخیره می‌شود. فهرست ذخیره‌شده‌ها از پروفایل، بخش علاقه‌مندی‌ها باز می‌شود.', 'position' => 'top', 'order' => 60, 'page' => ['home.php', 'index.php', 'properties.php', 'favorites.php', 'profile.php'], 'enabled' => true],
            ['id' => 'request', 'target' => '[data-tour="home-request"], a.home-shortcut[href="property-request.php"]', 'title' => 'ملک موردنظر خود را درخواست کنید', 'description' => 'به‌جای نوشتن محله، چهار گوشهٔ محدوده را روی نقشه بزنید. سیستم فقط فایل‌های داخل همان محدوده را تطبیق می‌دهد.', 'position' => 'bottom', 'order' => 70, 'page' => ['home.php', 'index.php', 'property-request.php', 'register-choose.php'], 'enabled' => true],
            ['id' => 'map-register', 'target' => '[data-tour="map-picker"], #mkMapPicker, .mk-map-picker, #map', 'title' => 'انتخاب موقعیت هنگام ثبت ملک', 'description' => 'موقع ثبت آگهی، موقعیت دقیق ملک را روی نقشه مشخص کنید. این نقطه فقط برای ملکینو است؛ روی نقشهٔ عمومی با جابه‌جایی تقریبی نمایش داده می‌شود.', 'position' => 'top', 'order' => 71, 'page' => ['register-apartment.php', 'register-villa.php', 'register-land.php', 'register-garden.php', 'register-office.php', 'register-commercial.php', 'property-request.php'], 'enabled' => true],
            ['id' => 'matches', 'target' => '[data-tour="matches"], a.profile-stat[href="my-request-matches.php"]', 'title' => 'فایل‌های مناسب من', 'description' => 'ملکینو بر اساس نیاز ثبت‌شده شما، فایل‌های مرتبط را پیدا می‌کند و در این قسمت نمایش می‌دهد.', 'position' => 'bottom', 'order' => 80, 'page' => 'profile.php', 'enabled' => true],
            ['id' => 'my-visits', 'target' => '[data-tour="my-visits"], a[href="visits.php"]', 'title' => 'درخواست‌های بازدید من', 'description' => 'همین‌جا می‌توانید درخواست‌های بازدید ثبت‌شده، وضعیت هماهنگی و شماره پیگیری را ببینید.', 'position' => 'bottom', 'order' => 82, 'page' => 'profile.php', 'enabled' => true],
            ['id' => 'profile-nav', 'target' => '[data-tour="nav-profile"], a[href="profile.php"]', 'title' => 'ورود به پروفایل از منو', 'description' => 'از این دکمه همیشه می‌توانید به صفحهٔ پروفایل برگردید.', 'position' => 'top', 'order' => 90, 'page' => 'profile.php', 'enabled' => false],
            ['id' => 'notifications', 'target' => '[data-tour="notifications"], a.notif-wrapper, a.profile-stat[href="notifications.php"]', 'title' => 'همیشه در جریان باشید', 'description' => 'اعلان‌های مربوط به درخواست‌ها، فایل‌های جدید و اتفاقات مهم را از این بخش مشاهده کنید.', 'position' => 'bottom', 'order' => 100, 'page' => ['home.php', 'index.php', 'profile.php', 'notifications.php'], 'enabled' => true],
            ['id' => 'contact', 'target' => '[data-tour="home-contact"], [data-tour="contact"], a.home-shortcut[href="contact.php"], a.profile-menu-item[href="contact.php"]', 'title' => 'ارتباط با ما', 'description' => 'شماره تماس، شبکه‌های اجتماعی و اطلاعات دفتر ملکینو را از بخش ارتباط با ما ببینید.', 'position' => 'top', 'order' => 105, 'page' => ['home.php', 'index.php', 'profile.php', 'contact.php'], 'enabled' => true],
            ['id' => 'support', 'target' => '[data-tour="support"], a.profile-menu-item[href="support.php"]', 'title' => 'پشتیبانی ملکینو', 'description' => 'اگر سؤال یا مشکلی داشتید، می‌توانید از طریق بخش پشتیبانی با ما در ارتباط باشید.', 'position' => 'top', 'order' => 110, 'page' => ['profile.php', 'support.php'], 'enabled' => true],
            ['id' => 'done', 'target' => '', 'title' => 'همه‌چیز آماده است!', 'description' => 'حالا آماده‌اید از ملکینو برای پیدا کردن و مدیریت فایل‌های ملکی استفاده کنید.', 'position' => 'center', 'order' => 120, 'page' => '*', 'cta' => 'شروع استفاده از ملکینو', 'enabled' => true],
        ];
    }
}

if (!function_exists('melkinoProductTourConfig')) {
    function melkinoProductTourConfig(string $tour = 'user'): array
    {
        $defaults = melkinoProductTourDefaults($tour);
        $saved = [];
        try {
            global $pdo;
            if (isset($pdo) && $pdo instanceof PDO && function_exists('dbSettingGet')) {
                $raw = dbSettingGet($pdo, 'global', 'product_tour', []);
                if (is_string($raw) && $raw !== '') {
                    $raw = json_decode($raw, true);
                }
                if (is_array($raw)) {
                    $saved = $raw;
                }
            }
        } catch (Throwable $e) {
            $saved = [];
        }
        $enabledGlobal = !isset($saved['enabled']) || !empty($saved['enabled']);
        $key = $tour === 'admin' ? 'admin_enabled' : 'user_enabled';
        $tourOn = $enabledGlobal && (!isset($saved[$key]) || !empty($saved[$key]));
        $flags = is_array($saved['steps_' . $tour] ?? null) ? $saved['steps_' . $tour] : [];
        foreach ($defaults as &$step) {
            $id = (string)($step['id'] ?? '');
            if ($id !== '' && array_key_exists($id, $flags)) {
                $step['enabled'] = !empty($flags[$id]);
            }
        }
        unset($step);
        // مقایسه اعشاری تا گام‌های میانی (مثلاً ۳۱٫۱ برای آموزش نقشه)
        // بدون شماره‌گذاری دوباره‌ی همه‌ی گام‌ها سر جای خودشان بنشینند.
        usort($defaults, static function ($a, $b) {
            return ((float)($a['order'] ?? 0)) <=> ((float)($b['order'] ?? 0));
        });
        return [
            'enabled' => $tourOn,
            'tour' => $tour,
            'auto' => true,
            'steps' => $defaults,
            'saved' => $saved,
        ];
    }
}

if (!function_exists('melkinoProductTourBoot')) {
    function melkinoProductTourBoot(string $tour = 'user'): void
    {
        static $booted = [];
        if (!empty($booted[$tour])) {
            return;
        }
        $booted[$tour] = true;
        $cfg = melkinoProductTourConfig($tour);
        $cssV = (int) @filemtime(__DIR__ . '/onboarding-tour.css');
        $jsV = (int) @filemtime(__DIR__ . '/onboarding-tour.js');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        echo '<link rel="stylesheet" href="onboarding-tour.css?v=' . $cssV . '">' . "\n";
        echo '<script>window.MELKINO_TOUR=' . json_encode([
            'enabled' => !empty($cfg['enabled']),
            'tour' => $tour,
            'auto' => !empty($cfg['auto']),
            'logged_in' => !empty($_SESSION['reg_telegram_id']) || !empty($_SESSION['reg_bale_id']) || !empty($_SESSION['reg_eitaa_id']) || !empty($_SESSION['user_id']) || !empty($_SESSION['user_phone']),
            'steps' => $cfg['steps'],
            'guides' => $tour === 'user' ? melkinoTopicGuides() : [],
        ], $flags) . ';</script>' . "\n";
        echo '<script src="onboarding-tour.js?v=' . $jsV . '"></script>' . "\n";
    }
}

if (!function_exists('melkinoProductTourAdminCard')) {
    function melkinoProductTourAdminCard(): void
    {
        // کارت «صفحهٔ اول هدایت» حذف شد (درخواست ادمین)
        $user = melkinoProductTourConfig('user');
        $admin = melkinoProductTourConfig('admin');
        $saved = $user['saved'] ?? [];
        ?>
        <div class="admin-card" style="margin-top:20px;">
            <div class="card-header">
                <span class="card-title">راهنمای تعاملی ملکینو</span>
            </div>
            <p style="font-size:13px;line-height:1.9;color:var(--text-secondary);margin:0 0 14px;">
                این راهنما روی صفحات واقعی سایت اجرا می‌شود و بخش‌ها را مرحله‌به‌مرحله معرفی می‌کند.
                با صفحات هدایت تمام‌صفحهٔ بالا فرق دارد.
            </p>
            <label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:13.5px;">
                <input type="checkbox" id="mkTourOn" <?= empty($saved) || !isset($saved['enabled']) || !empty($saved['enabled']) ? 'checked' : '' ?>>
                فعال بودن کلی راهنما
            </label>
            <label style="display:flex;gap:8px;align-items:center;margin:8px 0;font-size:13.5px;">
                <input type="checkbox" id="mkTourUserOn" <?= empty($saved) || !isset($saved['user_enabled']) || !empty($saved['user_enabled']) ? 'checked' : '' ?>>
                راهنمای کاربران
            </label>
            <label style="display:flex;gap:8px;align-items:center;margin:8px 0 16px;font-size:13.5px;">
                <input type="checkbox" id="mkTourAdminOn" <?= empty($saved) || !isset($saved['admin_enabled']) || !empty($saved['admin_enabled']) ? 'checked' : '' ?>>
                راهنمای پنل مدیریت
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div>
                    <div style="font-weight:800;margin-bottom:8px;">مراحل کاربر</div>
                    <?php foreach ($user['steps'] as $s): ?>
                        <label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13px;">
                            <input type="checkbox" class="mk-tour-flag-user" data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>" <?= !empty($s['enabled']) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div>
                    <div style="font-weight:800;margin-bottom:8px;">مراحل مدیر</div>
                    <?php foreach ($admin['steps'] as $s): ?>
                        <label style="display:flex;gap:8px;align-items:flex-start;margin:6px 0;font-size:13px;">
                            <input type="checkbox" class="mk-tour-flag-admin" data-id="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>" <?= !empty($s['enabled']) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;">
                <button type="button" class="tab-btn" id="mkTourSaveBtn" style="padding:8px 14px;">ذخیره تنظیمات راهنما</button>
                <button type="button" class="tab-btn" id="mkTourRunAdmin" style="padding:8px 14px;">اجرای راهنمای پنل</button>
                <button type="button" class="tab-btn" id="mkTourResetAdmin" style="padding:8px 14px;">بازنشانی راهنمای پنل</button>
            </div>
            <div id="mkTourSaveMsg" style="margin-top:8px;font-size:12.5px;color:var(--text-secondary);"></div>
            <script>
            (function () {
                var msg = document.getElementById('mkTourSaveMsg');
                function flags(cls) {
                    var o = {};
                    document.querySelectorAll(cls).forEach(function (el) { o[el.getAttribute('data-id')] = !!el.checked; });
                    return o;
                }
                var saveBtn = document.getElementById('mkTourSaveBtn');
                if (saveBtn) saveBtn.addEventListener('click', function () {
                    msg.textContent = 'در حال ذخیره…';
                    fetch('onboarding-tour-save.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            enabled: document.getElementById('mkTourOn').checked,
                            user_enabled: document.getElementById('mkTourUserOn').checked,
                            admin_enabled: document.getElementById('mkTourAdminOn').checked,
                            steps_user: flags('.mk-tour-flag-user'),
                            steps_admin: flags('.mk-tour-flag-admin')
                        })
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        msg.textContent = (d && d.success) ? 'ذخیره شد.' : ((d && d.message) || 'ذخیره نشد.');
                    }).catch(function () { msg.textContent = 'خطا در ارتباط.'; });
                });
                var run = document.getElementById('mkTourRunAdmin');
                if (run) run.addEventListener('click', function () {
                    if (window.MelkinoTour) window.MelkinoTour.replay();
                    else location.href = 'admin-panel.php?mk_tour=1';
                });
                var rst = document.getElementById('mkTourResetAdmin');
                if (rst) rst.addEventListener('click', function () {
                    if (window.MelkinoTour) window.MelkinoTour.reset();
                    msg.textContent = 'راهنمای پنل بازنشانی شد. با ورود بعدی یا دکمهٔ اجرا دوباره دیده می‌شود.';
                });
            })();
            </script>
        </div>
        <?php
    }
}
