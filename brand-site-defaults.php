<?php
declare(strict_types=1);
function mkBrandDefaultSettings(): array
{
    // پیش‌فرض Hero: تصویر ویلای غروب (فایل در brand-media/landing/melkino-hero-1200.jpg)
    $heroImg = '/brand-media/landing/melkino-hero-1200.jpg';
    $aboutImg = 'https://images.pexels.com/photos/40134484/pexels-photo-40134484.jpeg?auto=compress&cs=tinysrgb&w=1100';
    $aboutImg2 = 'https://images.pexels.com/photos/31817157/pexels-photo-31817157.jpeg?auto=compress&cs=tinysrgb&w=800';

    return [
        // برند
        'brand_name'       => 'ملکینو',
        'brand_latin'       => 'Melkino',
        'logo_path'         => '',
        'favicon_path'      => '',
        'site_title'        => 'ملکینو شاهرود | خرید، فروش، رهن و اجاره ملک',
        'meta_description'   => 'املاک ملکینو شاهرود؛ مشاوره تخصصی خرید، فروش، رهن و اجاره خانه، آپارتمان، ویلا و زمین در شاهرود. فایل‌های به‌روز، بازدید حرفه‌ای و ثبت درخواست ملک.',
        'meta_keywords'     => 'ملکینو شاهرود, املاک شاهرود, خرید آپارتمان شاهرود, رهن و اجاره شاهرود, فروش ملک شاهرود',

        // هدر / ناوبری
        'app_button_text'   => 'ورود به برنامه ملکینو',
        'nav_about_label'   => 'درباره ملکینو',
        'nav_contact_label' => 'ارتباط با ما',
        'header_note'       => 'برند رسمی ملکینو',

        // هیرو
        'hero_eyebrow'      => 'Luxury Real Estate Brand',
        'hero_title'         => 'املاک ملکینو شاهرود — خرید، فروش، رهن و اجاره ملک',
        'hero_accent'       => 'خرید، فروش، رهن و اجاره ملک',
        'hero_sub'          => 'فایل‌های به‌روز شاهرود، مشاورهٔ حرفه‌ای و ابزارهای هوشمند انتخاب ملک؛ همه در یک برنامه.',
        'hero_image'        => $heroImg,
        'hero_btn1_text'    => 'ورود به برنامه ملکینو',
        'hero_btn1_url'     => '/program/home.php',
        'hero_btn2_text'    => 'شبکه‌های اجتماعی',
        'hero_btn2_url'     => '#social',
        'hero_meta_1'       => 'خرید · فروش · رهن · اجاره · ثبت درخواست ملک در شاهرود',
        'hero_meta_2'       => 'پاسخگویی: شنبه تا پنجشنبه · ۹ تا ۱۸',

        // درباره
        'about_eyebrow'     => 'ملکینو',
        'about_title'       => 'ملکینو چیست؟',
        'about_text'        => "ملکینو با هدف ایجاد تجربه‌ای متفاوت در حوزه املاک و ارائه خدمات و محتوای تخصصی شکل گرفته است. ما تلاش می‌کنیم ارتباطی ساده، حرفه‌ای و متفاوت میان مخاطبان و دنیای ملک ایجاد کنیم.\n\nدر ملکینو، تمرکز ما بر کیفیت انتخاب‌ها، شفافیت در ارتباط و احترام به مخاطب است؛ همان چیزی که یک برند لوکس را از یک سرویس معمولی جدا می‌کند.",
        'about_image'       => $aboutImg,
        'about_image_2'     => $aboutImg2,
        'about_points'      => "محتوای تخصصی و ویرایش‌شده در حوزه ملک و معماری\nارتباط مستقیم، محترمانه و بدون پیچیدگی با مخاطبان\nنگاه برندمحور به انتخاب‌های خاص و متفاوت",
        'about_quote'       => 'ما در ملکینو باور داریم «انتخاب» ارزشمندترین بخش یک زندگی است.',
        'about_badge_num'   => '05',
        'about_badge_text'  => 'بستر رسمی ارتباطی برند',

        // شبکه‌های اجتماعی
        'social_eyebrow'    => 'Follow Melkino',
        'social_title'      => 'ملکینو را دنبال کنید',
        'social_lead'       => 'هر روزِ ملکینو در شبکه‌های اجتماعی می‌گذرد. انتخاب کنید از کدام مسیر در ارتباط باشید.',
        'access_eyebrow'    => 'Quick Access',
        'access_title'      => 'دسترسی سریع به ربات‌ها و شبکه‌های ملکینو',
        'access_lead'       => 'یک لمس فاصله است؛ ربات رسمی یا کانال ملکینو را در پیام‌رسان دلخواه باز کنید.',
        'dock_label'        => 'دسترسی سریع',
        'instagram_url'     => 'https://instagram.com/malakinoo',
        'telegram_url'      => 'https://t.me/malakinoo',
        'whatsapp_url'      => 'https://wa.me/989120000000',
        'youtube_url'       => 'https://youtube.com/@malakinoo',
        'tg_bot_url'        => 'https://t.me/MalakinooBot',
        'bale_bot_url'      => 'https://ble.ir/MalakinooBot',
        'eitaa_bot_url'     => 'https://eitaa.com/MalakinooBot',
        'tg_channel_url'    => 'https://t.me/malakinoo',
        'bale_channel_url'  => 'https://ble.ir/malakinoo',
        'eitaa_channel_url' => 'https://eitaa.com/malakinoo',

        // ربات
        'bot_eyebrow'       => 'Melkino Bot',
        'bot_title'         => 'ربات ملکینو',
        'bot_text'          => 'برای دسترسی سریع‌تر به خدمات و اطلاعات ملکینو، از ربات اختصاصی ما استفاده کنید.',
        'bot_btn_text'      => 'ورود به ربات ملکینو',
        'bot_url'           => 'https://t.me/MalakinooBot',
        'bot_hint'          => 'بدون ثبت‌نام · مستقیم در تلگرام',

        // ارتباط
        'contact_title'     => 'ارتباط با ملکینو',
        'contact_lead'      => 'یک مسیر را انتخاب کنید؛ ملکینو در تمام کانال‌های رسمی پاسخگوی شماست.',
        'phone'             => '021-000 00 00',
        'mobile'            => '0912 000 00 00',
        'email'             => 'info@malakinoo.com',
        'address'           => 'تهران، خیابان ولنجک، نبش کوچهٔ مهر، پلاک ۱۲، طبقهٔ سوم',
        'hours'             => 'شنبه تا چهارشنبه ۹:۰۰ تا ۱۸:۰۰ · پنجشنبه ۹:۰۰ تا ۱۳:۰۰',
        'map_url'           => 'https://maps.google.com/?q=Tehran,+Velenjak',
        'map_embed'         => 'https://www.google.com/maps?q=Tehran,+Velenjak&z=15&output=embed',

        // فوتر
        'footer_about'      => 'ملکینو؛ برند رسمی املاک با رویکردی متفاوت به محتوا، انتخاب و ارتباط.',
        'copyright'         => '© ۲۰۲۶ ملکینو · تمامی حقوق محفوظ است.',
        'fab_text'          => 'گفت‌وگو با ملکینو',
        'fab_url'           => 'https://wa.me/989120000000',
    ];
}
function mkBrandDefaultLinks(): array
{
    return [
        ['id' => 1, 'network' => 'ربات تلگرام', 'kind' => 'bot', 'description' => 'سریع‌ترین راه دسترسی به خدمات و اطلاعات ملکینو؛ پاسخ‌گویی خودکار و مسیرهای آماده.', 'button_text' => 'ورود به ربات', 'note' => 'Bot', 'icon' => 'fa-brands fa-telegram', 'accent' => '#2AABEE', 'url' => 'https://t.me/MalakinooBot', 'size' => 'normal', 'is_header' => 1, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 1],
        ['id' => 2, 'network' => 'ربات بله', 'kind' => 'bot', 'description' => 'ربات رسمی ملکینو در پیام‌رسان بله؛ همان خدمات، روی بستری ایرانی.', 'button_text' => 'ورود به ربات بله', 'note' => 'Bot', 'icon' => '', 'accent' => '#2F80C3', 'url' => 'https://ble.ir/MalakinooBot', 'size' => 'normal', 'is_header' => 1, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 2],
        ['id' => 3, 'network' => 'ربات ایتا', 'kind' => 'bot', 'description' => 'ربات رسمی ملکینو در ایتا؛ دسترسی آسان برای کاربران ایتا.', 'button_text' => 'ورود به ربات ایتا', 'note' => 'Bot', 'icon' => '', 'accent' => '#7A4BD0', 'url' => 'https://eitaa.com/MalakinooBot', 'size' => 'normal', 'is_header' => 1, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 3],
        ['id' => 4, 'network' => 'کانال تلگرام', 'kind' => 'channel', 'description' => 'کانال رسمی ملکینو؛ اطلاعیه‌ها و محتوای تکمیلی برند.', 'button_text' => 'ورود به کانال', 'note' => 'Channel', 'icon' => 'fa-brands fa-telegram', 'accent' => '#1F8FC4', 'url' => 'https://t.me/malakinoo', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 4],
        ['id' => 5, 'network' => 'کانال بله', 'kind' => 'channel', 'description' => 'کانال رسمی ملکینو در بله؛ روایت تصویری و اطلاع‌رسانی روزانه.', 'button_text' => 'ورود به کانال بله', 'note' => 'Channel', 'icon' => '', 'accent' => '#26689C', 'url' => 'https://ble.ir/malakinoo', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 5],
        ['id' => 6, 'network' => 'کانال ایتا', 'kind' => 'channel', 'description' => 'کانال رسمی ملکینو در ایتا؛ تازه‌های برند در دسترس شما.', 'button_text' => 'ورود به کانال ایتا', 'note' => 'Channel', 'icon' => '', 'accent' => '#5E3BAE', 'url' => 'https://eitaa.com/malakinoo', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 6],
        ['id' => 7, 'network' => 'اینستاگرام', 'kind' => 'page', 'description' => 'صفحه رسمی اینستاگرام ملکینو؛ روایت تصویری برند، انتخاب‌های خاص و پشت‌صحنهٔ مجموعه.', 'button_text' => 'مشاهده اینستاگرام', 'note' => 'Main Page', 'icon' => 'fa-brands fa-instagram', 'accent' => '#C1397E', 'url' => 'https://instagram.com/malakinoo', 'size' => 'tall', 'is_header' => 1, 'in_quick' => 1, 'is_active' => 1, 'sort_order' => 7],
        ['id' => 8, 'network' => 'واتساپ', 'kind' => 'support', 'description' => 'ارتباط مستقیم با ملکینو؛ پرسش‌ها و درخواست‌های شما.', 'button_text' => 'ارتباط در واتساپ', 'note' => 'Direct', 'icon' => 'fa-brands fa-whatsapp', 'accent' => '#1FA855', 'url' => 'https://wa.me/989120000000', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 0, 'is_active' => 1, 'sort_order' => 8],
        ['id' => 9, 'network' => 'یوتیوب', 'kind' => 'page', 'description' => 'ویدیوهای ملکینو؛ تور مجازی، معرفی و روایت‌های تصویری.', 'button_text' => 'مشاهده یوتیوب', 'note' => 'Video', 'icon' => 'fa-brands fa-youtube', 'accent' => '#C4302B', 'url' => 'https://youtube.com/@malakinoo', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 0, 'is_active' => 1, 'sort_order' => 9],
        ['id' => 10, 'network' => 'لینکدین', 'kind' => 'page', 'description' => 'صفحهٔ سازمانی ملکینو و اخبار برند.', 'button_text' => 'مشاهده لینکدین', 'note' => 'Network', 'icon' => 'fa-brands fa-linkedin-in', 'accent' => '#0A66C2', 'url' => 'https://linkedin.com/company/malakinoo', 'size' => 'normal', 'is_header' => 0, 'in_quick' => 0, 'is_active' => 1, 'sort_order' => 10],
    ];
}

function mkBrandDefaultFeatures(): array { return [
            ['title' => 'حرفه‌ای', 'icon' => 'fa-solid fa-award', 'description' => 'زبان برند، محتوا و نحوهٔ ارتباط ما با معیارهای حرفه‌ای سنجیده می‌شود؛ چیزی که باید در همان نگاه اول حس شود.'],
            ['title' => 'قابل اعتماد', 'icon' => 'fa-solid fa-shield-halved', 'description' => 'شفافیت و پایداری در اطلاع‌رسانی، سرمایهٔ اصلی ملکینو است. مخاطب می‌داند از ما چه انتظار داشته باشد.'],
            ['title' => 'متفاوت', 'icon' => 'fa-solid fa-gem', 'description' => 'ملکینو مسیر خودش را می‌رود؛ انتخاب‌های خاص، روایت بصری متمایز و تجربه‌ای که تکراری نیست.'],
            ['title' => 'مدرن', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'description' => 'از ربات اختصاصی تا شبکه‌های اجتماعی؛ همه‌چیز برای دسترسی سریع و بی‌اصطکاک مخاطب طراحی شده است.'],
        ];
}
function mkBrandFields(): array { return [
    'brand' => [
        ['app_button_text', 'متن دکمهٔ ورود به برنامه', 'fa-solid fa-arrow-left', 'text', 'مسیر برنامه ثابت و مستقل است: /program/home.php'],
        ['brand_name', 'نام برند', 'fa-solid fa-crown', 'text', ''],
        ['brand_latin', 'نام لاتین برند (Wordmark)', 'fa-solid fa-font', 'text', ''],
        ['site_title', 'عنوان سایت (Tab / SEO)', 'fa-solid fa-heading', 'text', ''],
        ['meta_description', 'توضیحات متا', 'fa-solid fa-align-right', 'textarea', ''],
        ['meta_keywords', 'کلمات کلیدی (با کاما)', 'fa-solid fa-tags', 'text', ''],
        ['nav_about_label', 'برچسب منو: درباره', 'fa-solid fa-list', 'text', ''],
        ['nav_contact_label', 'برچسب منو: ارتباط', 'fa-solid fa-list', 'text', ''],
        ['header_note', 'یادداشت کوتاه هدر/هیرو', 'fa-solid fa-note-sticky', 'text', ''],
        ['footer_about', 'متن کوتاه فوتر', 'fa-solid fa-paragraph', 'textarea', ''],
        ['copyright', 'متن کپی‌رایت', 'fa-solid fa-copyright', 'text', ''],
        ['dock_label', 'برچسب نوار شناور پایین سایت', 'fa-solid fa-grip-lines', 'text', ''],
    ],
    'hero' => [
        ['hero_eyebrow', 'برچسب بالای عنوان (لاتین)', 'fa-solid fa-signal', 'text', ''],
        ['hero_title', 'عنوان اصلی هیرو', 'fa-solid fa-star', 'text', ''],
        ['hero_accent', 'بخش طلایی عنوان (سطر دوم)', 'fa-solid fa-highlighter', 'text', ''],
        ['hero_sub', 'زیرعنوان', 'fa-solid fa-align-right', 'textarea', ''],
        ['hero_image', 'آدرس/مسیر تصویر هیرو', 'fa-solid fa-image', 'text', 'می‌توانید URL بگذارید یا تصویر آپلود کنید.'],
        ['hero_btn2_text', 'متن دکمهٔ ۲', 'fa-solid fa-i-cursor', 'text', ''],
        ['hero_btn2_url', 'لینک دکمهٔ ۲', 'fa-solid fa-link', 'url', ''],
        ['hero_meta_1', 'نوار پایین هیرو — آیتم ۱', 'fa-solid fa-grip-lines', 'text', ''],
        ['hero_meta_2', 'نوار پایین هیرو — آیتم ۲', 'fa-solid fa-grip-lines', 'text', ''],
    ],
    'about' => [
        ['about_eyebrow', 'برچسب لاتین بخش', 'fa-solid fa-signal', 'text', ''],
        ['about_title', 'عنوان بخش', 'fa-solid fa-heading', 'text', 'ملکینو چیست؟'],
        ['about_text', 'متن معرفی (هر خط خالی = پاراگراف تازه)', 'fa-solid fa-paragraph', 'textarea', ''],
        ['about_points', 'نکته‌های کلیدی (هر خط یک مورد)', 'fa-solid fa-list-check', 'textarea', ''],
        ['about_quote', 'نقل‌قول کوتاه', 'fa-solid fa-quote-right', 'textarea', ''],
        ['about_image', 'آدرس/مسیر تصویر اصلی', 'fa-solid fa-image', 'text', 'پیشنهاد: تصویر عمودی (۴:۵) از فضای داخلی یا نمای لوکس.'],
        ['about_image_2', 'آدرس/مسیر تصویر کوچک (روی تصویر اصلی)', 'fa-solid fa-images', 'text', 'خالی بگذارید تا نمایش داده نشود.'],
        ['about_badge_num', 'عدد بج روی تصویر', 'fa-solid fa-circle-half-stroke', 'text', ''],
        ['about_badge_text', 'متن بج روی تصویر', 'fa-solid fa-i-cursor', 'text', ''],
    ],
    'bot' => [
        ['bot_eyebrow', 'برچسب لاتین', 'fa-solid fa-signal', 'text', ''],
        ['bot_title', 'عنوان ربات', 'fa-solid fa-robot', 'text', ''],
        ['bot_text', 'توضیح ربات', 'fa-solid fa-paragraph', 'textarea', ''],
        ['bot_btn_text', 'متن دکمه', 'fa-solid fa-i-cursor', 'text', ''],
        ['bot_url', 'لینک ربات', 'fa-solid fa-link', 'url', 'مهم: لینک ربات از همین‌جا تغییر می‌کند.'],
        ['bot_hint', 'متن کمکی کنار دکمه', 'fa-solid fa-circle-info', 'text', ''],
    ],
    'social' => [
        ['social_eyebrow', 'برچسب لاتین بخش', 'fa-solid fa-signal', 'text', ''],
        ['social_title', 'عنوان بخش', 'fa-solid fa-heading', 'text', ''],
        ['social_lead', 'متن معرفی بخش', 'fa-solid fa-paragraph', 'textarea', ''],
        ['tg_bot_url', 'ربات تلگرام', 'fa-brands fa-telegram', 'url', ''],
        ['bale_bot_url', 'ربات بله', 'fa-solid fa-robot', 'url', 'مثال: https://ble.ir/MalakinooBot'],
        ['eitaa_bot_url', 'ربات ایتا', 'fa-solid fa-robot', 'url', 'مثال: https://eitaa.com/MalakinooBot'],
        ['tg_channel_url', 'کانال تلگرام', 'fa-brands fa-telegram', 'url', ''],
        ['bale_channel_url', 'کانال بله', 'fa-solid fa-tower-broadcast', 'url', ''],
        ['eitaa_channel_url', 'کانال ایتا', 'fa-solid fa-tower-broadcast', 'url', ''],
        ['instagram_url', 'اینستاگرام URL', 'fa-brands fa-instagram', 'url', ''],
        ['whatsapp_url', 'WhatsApp URL', 'fa-brands fa-whatsapp', 'url', ''],
        ['youtube_url', 'YouTube URL', 'fa-brands fa-youtube', 'url', ''],
        ['access_eyebrow', 'برچسب لاتین بخش دسترسی سریع', 'fa-solid fa-signal', 'text', ''],
        ['access_title', 'عنوان بخش دسترسی سریع', 'fa-solid fa-heading', 'text', ''],
        ['access_lead', 'متن بخش دسترسی سریع', 'fa-solid fa-paragraph', 'textarea', ''],
        ['dock_label', 'برچسب نوار شناور پایین سایت', 'fa-solid fa-grip-lines', 'text', ''],
    ],
    'contact' => [
        ['contact_title', 'عنوان بخش ارتباط', 'fa-solid fa-heading', 'text', ''],
        ['contact_lead', 'متن زیر عنوان', 'fa-solid fa-paragraph', 'textarea', ''],
        ['phone', 'شماره تماس', 'fa-solid fa-phone', 'text', ''],
        ['mobile', 'شماره موبایل', 'fa-solid fa-mobile-screen', 'text', ''],
        ['email', 'ایمیل', 'fa-solid fa-envelope', 'text', ''],
        ['address', 'آدرس دفتر', 'fa-solid fa-location-dot', 'textarea', ''],
        ['hours', 'ساعات کاری', 'fa-solid fa-clock', 'textarea', ''],
        ['map_url', 'لینک گوگل‌مپ', 'fa-solid fa-map', 'url', ''],
        ['map_embed', 'آدرس Embed نقشه', 'fa-solid fa-map-location-dot', 'url', ''],
    ],
];
}
