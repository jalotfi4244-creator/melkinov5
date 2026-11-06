<?php
// هدر مشترک ملکینو
require_once __DIR__ . '/shared-property.php';
$mkSharedGuest = melkinoIsSharedPropertyGuest();
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: frame-ancestors 'self' https://*.bale.ai https://bale.ai https://web.bale.ai https://ble.ir https://*.telegram.org https://web.telegram.org https://*.eitaa.com https://web.eitaa.com https://eitaa.com");
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

// برای پر کردنِ خودکارِ فرم‌ها از اطلاعاتِ کاربرِ واردشده
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/melkino-calc-rates.php';
if ($mkSharedGuest) melkinoPrepareSharedProperty();

$melkinoProfilePrefill = !$mkSharedGuest && function_exists('melkinoProfilePrefill')
    ? melkinoProfilePrefill()
    : ['logged_in' => false, 'name' => '', 'phone' => ''];

/*
|--------------------------------------------------------------------------
| تشخیصِ پلتفرمِ مینی‌اپ
|--------------------------------------------------------------------------
| مستندات رسمی بله می‌گوید اسکریپتِminiapp.js باید «پیش از هر اسکریپت
| دیگری در ابتدای <head>» قرار گیرد. دلیلش این است که تا زمانی که
| Bale.WebApp.ready() صدا زده نشود، کلاینتِ بله یک صفحه‌ی بارگذاریِ سفید
| نشان می‌دهد. بنابراین وقتی مطمئن هستیم کاربر داخلِ بله است، اسکریپت را
| به‌صورت مستقیم و در اولین خطِ head چاپ می‌کنیم.
|
| برای تلگرام این کار را نمی‌کنیم: در ایران دسترسی به telegram.org معمولاً
| مسدود است و یک تگِ مسدودکننده باعث می‌شود مرورگر تا پایانِ مهلتِ درخواست
| صبر کند و کاربر صفحه‌ی سفید ببیند. به همین دلیل تلگرام همچنان به‌صورت
| غیرِمسدودکننده (async) لود می‌شود.
|--------------------------------------------------------------------------
*/
$melkinoUa = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$melkinoIsBale = (strpos($melkinoUa, 'bale') !== false)
    || (strpos($melkinoUa, 'ble.ir') !== false);
$melkinoIsTelegram = (strpos($melkinoUa, 'telegram') !== false);
require_once __DIR__ . '/messenger-login-lib.php';
$melkinoMessenger = $mkSharedGuest ? '' : melkinoMessengerContext();
if ($mkSharedGuest) { $melkinoIsBale = $melkinoIsTelegram = false; }
$melkinoIsEitaa = $melkinoMessenger === 'eitaa';
if ($melkinoMessenger !== '') $melkinoIsBale = false;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" id="melkinoRoot"<?= $melkinoMessenger !== '' ? ' data-melkino-messenger="' . htmlspecialchars($melkinoMessenger, ENT_QUOTES, 'UTF-8') . '"' : '' ?><?= $melkinoIsEitaa ? ' data-melkino-eitaa="1"' : '' ?>>
<head>
<?php melkinoSharedPropertyHead(); ?>
<?php if (!$mkSharedGuest): ?>
<?php if ($melkinoMessenger !== ''): ?>
<script src="<?= htmlspecialchars(melkinoMessengerSdk($melkinoMessenger), ENT_QUOTES, 'UTF-8') ?>" data-mk-sdk="<?= htmlspecialchars($melkinoMessenger, ENT_QUOTES, 'UTF-8') ?>" async></script>
<script src="assets/js/messenger-bridge.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/messenger-bridge.js') ?>" defer></script>
<script src="assets/js/messenger-runtime.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/messenger-runtime.js') ?>" defer></script>
<?php endif; ?>
<?php
// اسکریپت بله فقط با UA بله؛ تگ ثابت در سورس نباشد تا عیب‌یاب مسدودکننده نگیرد.
if (!empty($melkinoIsBale)) {
    $melkinoBaleSdk = 'https://tapi.bale.ai/' . 'miniapp.js?3';
    echo '<script src="' . htmlspecialchars($melkinoBaleSdk, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}
?>
<script>
(function () {
        if (document.documentElement.hasAttribute('data-melkino-messenger')) return;
    function go() {
        try {
            var w = window.Bale && window.Bale.WebApp;
            if (w) {
                try { if (typeof w.ready === 'function') w.ready(); } catch (e) {}
                try { if (typeof w.expand === 'function') w.expand(); } catch (e) {}
            }
        } catch (e) {}
        try {
            if (window.parent && window.parent !== window) {
                function send(t) {
                    window.parent.postMessage(JSON.stringify({ eventType: t, eventData: null }), '*');
                }
                send('iframe_ready');
                send('web_app_ready');
                send('web_app_expand');
            }
        } catch (e) {}
    }
    go();
    setTimeout(go, 0);
    setTimeout(go, 100);
    setTimeout(go, 400);
    setTimeout(go, 1200);
})();
</script>
<script>
/*
 * آمادگیِ مینی‌اپ (تلگرام و بله)
 * ========================================================
 * کلاینتِ تلگرام/بله تا وقتی پیامِ «آمادگی» را دریافت نکند، یک لایه‌ی
 * سفید روی صفحه نگه می‌دارد.
 *
 * سه اشکالِ پشتِ‌هم باعث می‌شد این پیام فرستاده نشود:
 *   ۱) تشخیص با User-Agent در سمتِ سرور؛
 *   ۲) وابستگی به ورودِ کاربر؛
 *   ۳) تشخیص در مرورگر بر اساسِ «بودن در قاب» یا User-Agent — که روی
 *      کلاینتِ بله در iOS هر دو شکست می‌خورند.
 *
 * عیب‌یابی روی دستگاهِ واقعی نشان داد User-Agentِ بله در iOS چیزی جز یک
 * Safari معمولی نیست و صفحه هم درونِ قاب نیست. بنابراین دیگر هیچ تشخیصی
 * انجام نمی‌شود: این بلوک همیشه اجرا می‌شود و اسکریپتِ بله همیشه
 * به‌صورت غیرِ مسدودکننده بارگیری می‌گردد.
 */
(function () {
        if (document.documentElement.hasAttribute('data-melkino-messenger')) return;

    var sdkDone = { bale: false, telegram: false, eitaa: false };

    // ثبتِ رویدادها — فقط در صفحه‌ی عیب‌یاب استفاده می‌شود و در بقیه‌ی
    // صفحات بی‌اثر است.
    function note(text) {
        try {
            if (typeof window.__MK_MARK === 'function') { window.__MK_MARK(text); }
        } catch (e) {}
    }

    note('بلوکِ آمادگی اجرا شد (بدونِ نیاز به تشخیصِ پلتفرم)');

    // حالتِ عادی: ready() از طریقِ اسکریپتِ رسمی
    function announceSDK() {
        try {
            if (window.Bale && window.Bale.WebApp) {
                if (!sdkDone.bale) {
                    sdkDone.bale = true;
                    if (typeof window.Bale.WebApp.ready === 'function') window.Bale.WebApp.ready();
                    try { if (typeof window.Bale.WebApp.expand === 'function') window.Bale.WebApp.expand(); } catch (e) {}
                    note('Bale.WebApp پیدا شد و ready() فراخوانی شد ✅');
                }
                return true;
            }
        } catch (e) {}
        try {
            if (window.Telegram && window.Telegram.WebApp) {
                if (!sdkDone.telegram) {
                    sdkDone.telegram = true;
                    if (typeof window.Telegram.WebApp.ready === 'function') window.Telegram.WebApp.ready();
                    try { if (typeof window.Telegram.WebApp.expand === 'function') window.Telegram.WebApp.expand(); } catch (e) {}
                    note('Telegram.WebApp پیدا شد و ready() فراخوانی شد ✅');
                }
                return true;
            }
        } catch (e) {}
        try {
            if (window.Eitaa && window.Eitaa.WebApp) {
                if (!sdkDone.eitaa) {
                    sdkDone.eitaa = true;
                    if (typeof window.Eitaa.WebApp.ready === 'function') window.Eitaa.WebApp.ready();
                    try { if (typeof window.Eitaa.WebApp.expand === 'function') window.Eitaa.WebApp.expand(); } catch (e) {}
                    note('Eitaa.WebApp پیدا شد و ready() فراخوانی شد ✅');
                }
                return true;
            }
        } catch (e) {}
        return false;
    }

    function inFrame() {
        try { return (window.self !== window.top); } catch (e) { return true; }
    }

    // مسیرِ جایگزین: پروتکلِ خامِ مینی‌اپ (فقط وقتی درونِ قاب باشیم)
    function postReady() {
        if (!inFrame()) { return; }
        try {
            function send(eventType, eventData) {
                window.parent.postMessage(
                    JSON.stringify({ eventType: eventType, eventData: eventData }),
                    '*'
                );
            }
            send('iframe_ready', { reload_supported: true });
            send('web_app_ready', null);
            send('web_app_expand', null);
            note('مسیرِ جایگزین: پیام‌های آمادگی با postMessage فرستاده شد ✅');
        } catch (e) {}
    }

    // ۱) اگر شیء از پیش وجود داشت، همان لحظه اعلام کن
    if (announceSDK()) { note('SDK از پیش موجود بود'); }

    // ۲) بارگیریِ اسکریپتِ بله: همیشه و غیرِ مسدودکننده
    var ua = '';
    try { ua = navigator.userAgent || ''; } catch (e) {}

    var sources = ['https://tapi.bale.ai/miniapp.js?3'];
    // داخل بله هرگز telegram.org / ایتا لود نشود (در ایران hang و صفحه سفید)
    var inBale = false;
    try { inBale = !!(window.Bale && window.Bale.WebApp) || /bale|ble\.ir/i.test(ua); } catch (e) {}
    if (!inBale) {
        sources.unshift('telegram-web-app.js');
        if (inFrame() || /telegram/i.test(ua)) {
            sources.push('https://telegram.org/js/telegram-web-app.js');
        }
        if (/eitaa/i.test(ua)) {
            sources.push('https://developer.eitaa.com/eitaa-web-app.js');
        }
    }

    for (var i = 0; i < sources.length; i++) {
        (function (src) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;                 // هرگز مسدودکننده نباشد
            s.onload = function () { note('اسکریپت لود شد: ' + src); announceSDK(); };
            s.onerror = function () { note('خطا در بارگیری: ' + src); };
            document.head.appendChild(s);
            note('درخواستِ بارگیری فرستاده شد: ' + src);
        })(sources[i]);
    }

    // ۳) پایشِ مداوم
    var tries = 0;
    var timer = setInterval(function () {
        tries++;
        announceSDK();
        if ((sdkDone.bale && sdkDone.telegram) || tries > 120) { clearInterval(timer); }
    }, 150);

    // ۴) مسیرِ جایگزین برای کلاینت‌هایی که صفحه را درونِ قاب نشان می‌دهند
    setTimeout(function () { postReady(); }, 400);
    setTimeout(function () { postReady(); }, 1500);
    setTimeout(function () { postReady(); }, 3000);
})();
</script>

    <?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <?php require_once __DIR__ . '/seo-lib.php'; echo melkinoSeoHeadHtml(); ?>

    <!-- اطلاعات کاربرِ واردشده؛ برای پر کردن خودکار فرم‌های ثبت آگهی و درخواست -->
    <script>
        window.MELKINO_PROFILE = <?= melkinoJsJson($melkinoProfilePrefill) ?>;
    </script>
    <?php if (!$mkSharedGuest): ?>
    <script>
    (function () {
        var NAME_KEYS  = ['regLastName', 'lastName', 'last_name', 'hidden_last_name', 'ownerName', 'owner_name', 'reqName'];
        var PHONE_KEYS = ['regPhone', 'phone', 'hidden_phone', 'ownerPhone', 'owner_phone', 'reqPhone', 'phoneNumber', 'phone_number'];

        function findEl(key) {
            var el = null;
            try { el = document.getElementById(key); } catch (e) {}
            if (!el) { try { el = document.querySelector('[name="' + key + '"]'); } catch (e) {} }
            return el;
        }

        function fill(keys, value) {
            if (!value) return;
            for (var i = 0; i < keys.length; i++) {
                var el = findEl(keys[i]);
                if (!el || el.value) continue;
                el.value = value;
                el.setAttribute('data-melkino-autofill', '1');
                try {
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) {}
            }
        }

        // هرگاه کاربر نام یا شماره‌اش را خودش تغییر دهد، در حسابش ذخیره
        // می‌شود تا از آن پس به‌طور خودکار پر شود. این کار فقط برای کاربرِ
        // واردشده انجام می‌شود و در صورت نبودِ شماره، چیزی ذخیره نمی‌شود.
        var watching = false;
        var lastSaved = '';

        function watchContact() {
            if (watching) return;

            var nameEl = null;
            var phoneEl = null;

            for (var i = 0; i < NAME_KEYS.length && !nameEl; i++) nameEl = findEl(NAME_KEYS[i]);
            for (var j = 0; j < PHONE_KEYS.length && !phoneEl; j++) phoneEl = findEl(PHONE_KEYS[j]);

            if (!nameEl && !phoneEl) return;
            watching = true;

            function save() {
                var n = nameEl ? String(nameEl.value || '').trim() : '';
                var ph = phoneEl && window.melkinoNormalizePhone
                    ? window.melkinoNormalizePhone(phoneEl.value)
                    : (phoneEl ? String(phoneEl.value || '').trim() : '');

                if (!ph) return;                       // بدون شماره چیزی ذخیره نمی‌شود
                if (!window.melkinoIsValidPhone || !window.melkinoIsValidPhone(ph)) return;
                if (n + '|' + ph === lastSaved) return; // فقط هنگام تغییر
                lastSaved = n + '|' + ph;

                if (typeof window.melkinoSaveContact === 'function') {
                    window.melkinoSaveContact(n, ph);
                }
            }

            if (nameEl) {
                nameEl.addEventListener('blur', save);
                nameEl.addEventListener('change', save);
            }
            if (phoneEl) {
                phoneEl.addEventListener('blur', save);
                phoneEl.addEventListener('change', save);
            }
        }

        function run() {
            var p = window.MELKINO_PROFILE;
            if (!p || !p.logged_in) return;

            fill(NAME_KEYS, p.name);
            fill(PHONE_KEYS, p.phone);

            watchContact();

            // ذخیره برای مراحل بعدی ثبت آگهی
            try {
                if (p.name && !sessionStorage.getItem('reg_last_name')) sessionStorage.setItem('reg_last_name', p.name);
                if (p.phone && !sessionStorage.getItem('reg_phone')) sessionStorage.setItem('reg_phone', p.phone);
            } catch (e) {}
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
        setTimeout(run, 600);
    })();
    </script>

    <!-- همگام‌سازی پروفایل: به‌محض ورود کاربر به مینی‌اپ، اطلاعات او
         در پایگاه داده ساخته/به‌روزرسانی می‌شود (حتی بدون ثبت آگهی) -->
    <script>
    (function () {
        if (document.documentElement.hasAttribute('data-melkino-messenger')) return;
        if (window.MELKINO_PROFILE && window.MELKINO_PROFILE.logged_in) return;
        try { if (sessionStorage.getItem('melkino_profile_synced') === 'ok') return; } catch (e) {}

        function sdkData() {
            var tg = '', bl = '';
            try { if (window.Telegram && window.Telegram.WebApp) tg = window.Telegram.WebApp.initData || ''; } catch (e) {}
            try { if (window.Bale && window.Bale.WebApp) bl = window.Bale.WebApp.initData || ''; } catch (e) {}
            return { telegram: tg, bale: bl };
        }

        function hashData() {
            var h = (location.hash || '').replace(/^#/, '');
            if (!h) return '';
            try { var p = new URLSearchParams(h); return p.get('tgWebAppData') || ''; } catch (e) { return ''; }
        }

        function trySync() {
            var d = sdkData();
            var data = d.telegram || d.bale;
            var platform = d.bale ? 'bale' : 'telegram';
            if (!data) data = hashData();
            if (!data) return false;

            fetch('profile-sync.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ init_data: data, platform: platform })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    try { sessionStorage.setItem('melkino_profile_synced', 'ok'); } catch (e) {}
                    try { document.documentElement.classList.remove('mk-await-id'); } catch (e) {}
                    if (window.MELKINO_PROFILE) {
                        window.MELKINO_PROFILE.logged_in = true;
                        if (res.name) window.MELKINO_PROFILE.name = res.name;
                        if (res.phone) window.MELKINO_PROFILE.phone = res.phone;
                    }
                    if (res.user_id) {
                        try { sessionStorage.setItem('melkino_user_id', String(res.user_id)); } catch (e) {}
                    }
                }
            })
            .catch(function () {});
            return true;
        }

        if (!trySync()) {
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                if (trySync() || tries > 40) clearInterval(timer);
            }, 150);
        }
    })();
    </script>

    <!--
        کارتِ «اطلاعات تماسِ قابل ویرایش»

        چرا لازم است؟
            در فرم‌های ثبت ملک، نام و شماره تماس «فیلدهای پنهان» هستند و
            فقط از پروفایلِ کاربر خوانده می‌شوند. بنابراین اگر کاربر شماره
            نداشت، اصلاً نمی‌توانست آن را وارد کند یا اسمش را اصلاح کند و
            مدام به مرحله‌ی اول برگردانده می‌شد.

            این کارت، اطلاعات تماس را در خودِ صفحه‌ی ثبت «نمایش می‌دهد و
            قابل ویرایش می‌کند» و هر تغییری را در پروفایل هم ذخیره می‌کند تا
            از آن پس به‌طور خودکار پر شود.
    -->
    <script>
    (function () {
        'use strict';

        /** تبدیل اعداد فارسی/عربی و پیش‌شماره‌ها به فرمت ۰۹xxxxxxxxx */
        window.melkinoNormalizePhone = function (value) {
            if (value === null || value === undefined) return '';
            var v = String(value)
                .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); })
                .replace(/[^0-9+]/g, '');
            if (v.indexOf('+98') === 0) v = '0' + v.slice(3);
            else if (v.indexOf('0098') === 0) v = '0' + v.slice(4);
            else if (v.indexOf('98') === 0 && v.length > 10) v = '0' + v.slice(2);
            return v;
        };

        window.melkinoIsValidPhone = function (value) {
            return /^09\d{9}$/.test(window.melkinoNormalizePhone(value));
        };

        /**
         * ذخیره‌ی نام و شماره در پروفایل کاربر
         * (فقط وقتی کاربر وارد شده باشد؛ در غیر این صورت بی‌صدا رد می‌شود)
         */
        window.melkinoSaveContact = function (name, phone, callback) {
            try {
                return fetch('profile-sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_contact',
                        name: String(name || ''),
                        phone: window.melkinoNormalizePhone(phone || '')
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (typeof callback === 'function') callback(res);
                    return res;
                })
                .catch(function () {
                    return { success: false, message: 'خطا در ارتباط' };
                });
            } catch (e) {
                return Promise.resolve({ success: false });
            }
        };

        /**
         * خروج از حساب کاربری (دکمه‌ی «خروج» پروفایل و هر صفحه‌ی دیگر)
         *
         * قبلاً onclick="logoutUser()" فقط در پروفایل صدا زده می‌شد ولی
         * تابع هیچ‌جا تعریف نشده بود و دکمه کار نمی‌کرد. حالا اینجا
         * سراسری تعریف می‌شود: پس از تأیید کاربر به logout.php می‌رود
         * که سشن سرور و داده‌های مرورگر را پاک می‌کند.
         */
        window.logoutUser = function () {
            if (!confirm('از حساب کاربری خارج می‌شوید؟')) {
                return;
            }
            try {
                localStorage.removeItem('melkino_login_token');
            } catch (e) {}
            window.location.href = 'logout.php';
        };

        /**
         * ساخت کارتِ اطلاعات تماس در یک فرم
         *
         * options:
         *   formId        شناسه‌ی فرم (اجباری)
         *   nameId        شناسه‌ی فیلد پنهانِ نام
         *   phoneId       شناسه‌ی فیلد پنهانِ شماره
         *   name          مقدار اولیه‌ی نام
         *   phone         مقدار اولیه‌ی شماره
         *   title         عنوان کارت
         */
        window.melkinoContactCard = function (options) {
            options = options || {};

            var form = document.getElementById(options.formId);
            if (!form) return null;

            // اگر قبلاً ساخته شده، دوباره ساخته نمی‌شود
            if (document.getElementById('melkinoContactCard')) {
                return document.getElementById('melkinoContactCard');
            }

            var nameHidden = options.nameId ? document.getElementById(options.nameId) : null;
            var phoneHidden = options.phoneId ? document.getElementById(options.phoneId) : null;

            var card = document.createElement('div');
            card.id = 'melkinoContactCard';
            card.setAttribute('dir', 'rtl');
            card.style.cssText =
                'background:#0b5d59;color:#fff;border-radius:12px;' +
                'padding:11px 14px;margin:0 0 12px;font-family:inherit;' +
                'box-shadow:0 6px 18px rgba(0,0,0,.16);border:1px solid rgba(212,175,55,.45);';

            var hasName  = String(options.name || '').trim() !== '';
            var hasPhone = String(options.phone || '').trim() !== '';
            var missing  = !hasName || !hasPhone;

            var title = options.title || '📇 تکمیل اطلاعات تماس';

            var noteText;
            if (!hasName && !hasPhone) {
                noteText = 'نام و شماره تماس شما در سیستم ثبت نشده است. لطفاً آن‌ها را وارد کنید.';
            } else if (!hasPhone) {
                noteText = 'شماره تماس شما در سیستم ثبت نشده است. لطفاً آن را وارد کنید.';
            } else if (!hasName) {
                noteText = 'نام شما در سیستم ثبت نشده است. لطفاً آن را وارد کنید.';
            } else {
                noteText = 'در صورت نیاز می‌توانید اطلاعات زیر را ویرایش کنید.';
            }

            card.innerHTML =
                '<div style="font-weight:700;font-size:14px;margin-bottom:3px;color:#D4AF37">' + title + '</div>' +
                '<div id="melkinoContactNote" style="font-size:12px;opacity:.9;line-height:1.7;margin-bottom:9px">' +
                    noteText +
                '</div>' +
                '<div style="display:flex;gap:10px;flex-wrap:wrap">' +
                    '<div style="flex:1;min-width:150px">' +
                        '<label style="display:block;font-size:12px;margin-bottom:4px;opacity:.9">نام و نام خانوادگی</label>' +
                        '<input id="melkinoContactName" type="text" inputmode="text" autocomplete="name"' +
                        ' placeholder="مثلاً احمد احمدی"' +
                        ' style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;' +
                        'border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);' +
                        'color:#fff;font-family:inherit;font-size:14px">' +
                    '</div>' +
                    '<div style="flex:1;min-width:150px">' +
                        '<label style="display:block;font-size:12px;margin-bottom:4px;opacity:.9">شماره تماس</label>' +
                        '<input id="melkinoContactPhone" type="tel" inputmode="numeric" autocomplete="tel"' +
                        ' placeholder="۰۹۱۲۳۴۵۶۷۸۹" maxlength="13"' +
                        ' style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:10px;' +
                        'border:1px solid ' + (missing ? 'rgba(212,175,55,.95)' : 'rgba(255,255,255,.35)') + ';' +
                        'background:rgba(255,255,255,.12);color:#fff;font-family:inherit;font-size:14px"' +
                        (missing ? ' required' : '') + '>' +
                    '</div>' +
                '</div>' +
                '<div id="melkinoContactStatus" style="font-size:11px;margin-top:8px;min-height:14px;opacity:.85"></div>';

            // درج در ابتدای فرم
            if (form.firstChild) {
                form.insertBefore(card, form.firstChild);
            } else {
                form.appendChild(card);
            }

            var nameInput = document.getElementById('melkinoContactName');
            var phoneInput = document.getElementById('melkinoContactPhone');
            var statusEl = document.getElementById('melkinoContactStatus');

            nameInput.value = String(options.name || '');
            phoneInput.value = String(options.phone || '');

            var saveTimer = null;

            function status(text, color) {
                if (!statusEl) return;
                statusEl.textContent = text || '';
                statusEl.style.color = color || '';
            }

            function sync() {
                var n = nameInput.value.trim();
                var p = window.melkinoNormalizePhone(phoneInput.value);

                if (nameHidden) nameHidden.value = n;
                if (phoneHidden) phoneHidden.value = p;

                // ذخیره در sessionStorage برای مراحل بعدی
                try {
                    if (n) sessionStorage.setItem('reg_last_name', n);
                    if (p) sessionStorage.setItem('reg_phone', p);
                } catch (e) {}

                if (p && !window.melkinoIsValidPhone(p)) {
                    status('شماره واردشده معتبر نیست (باید با ۰۹ شروع شود و ۱۱ رقم باشد).', '#FBBF24');
                    return;
                }

                if (!p && !n) return;

                clearTimeout(saveTimer);
                saveTimer = setTimeout(function () {
                    status('در حال ذخیره…');
                    window.melkinoSaveContact(n, p, function (res) {
                        if (res && res.success) {
                            status('✓ در حساب شما ذخیره شد و از این پس خودکار پر می‌شود.', '#7CFFB2');
                        } else if (p === '') {
                            status('');
                        } else {
                            status('ذخیره در حساب انجام نشد، اما برای این آگهی استفاده می‌شود.');
                        }
                    });
                }, 700);
            }

            nameInput.addEventListener('input', sync);
            phoneInput.addEventListener('input', sync);
            nameInput.addEventListener('blur', sync);
            phoneInput.addEventListener('blur', function () {
                var p = window.melkinoNormalizePhone(phoneInput.value);
                if (p) phoneInput.value = p;
                sync();
            });

            // جلوگیری از ارسال فرم در صورت خالی‌بودنِ شماره
            form.addEventListener('submit', function (e) {
                var p = window.melkinoNormalizePhone(phoneInput.value);
                var n = nameInput.value.trim();

                if (nameHidden) nameHidden.value = n;
                if (phoneHidden) phoneHidden.value = p;

                if (!p) {
                    e.preventDefault();
                    alert('لطفاً شماره تماس خود را در کادر بالا وارد کنید.');
                    phoneInput.focus();
                    return false;
                }

                if (!window.melkinoIsValidPhone(p)) {
                    e.preventDefault();
                    alert('شماره تماس معتبر نیست. مثال: ۰۹۱۲۳۴۵۶۷۸۹');
                    phoneInput.focus();
                    return false;
                }

                // آخرین تلاش برای ذخیره در پروفایل (بدون معطل کردنِ ارسال)
                try {
                    if (navigator.sendBeacon) {
                        var blob = new Blob([JSON.stringify({
                            action: 'update_contact', name: n, phone: p
                        })], { type: 'application/json' });
                        navigator.sendBeacon('profile-sync.php', blob);
                    }
                } catch (err) {}

                return true;
            });

            window.melkinoContactCardNode = card;
            return card;
        };
    })();
    </script>

    <!--
        اسکریپت‌های رسمی تلگرام و بله باید «بدون مسدود کردنِ نمایش صفحه»
        لود شوند. اگر این فایل‌ها به‌صورت معمولی (مسدودکننده) در هدر باشند
        و سرورِ telegram.org از شبکه‌ی کاربر در دسترس نباشد، مرورگر تا
        پایانِ مهلتِ درخواست صبر می‌کند و کاربر فقط یک صفحه‌ی سفید می‌بیند.

        نکته‌ی مهم‌تر: برای بله، فراخوانیِ Bale.WebApp.ready() الزامی است؛
        تا وقتی این تابع صدا زده نشود، مینی‌اپ روی صفحه‌ی بارگذاریِ سفید
        باقی می‌ماند. بنابراین در اینجا بعد از لود شدنِ هر اسکریپت،
        ready() هم فراخوانی می‌شود.
    -->
    <script>
    (function () {
        if (document.documentElement.hasAttribute('data-melkino-messenger')) return;
        var ua = navigator.userAgent || '';
        function has(p) { try { return new RegExp(p, 'i').test(ua); } catch (e) { return false; } }

        var isBale     = has('bale') || has('ble\\.ir');
        var isTelegram = has('telegram');

        /**
         * فراخوانیِ ready() و expand()
         * تا وقتی ready() صدا زده نشود، کلاینتِ بله/تلگرام صفحه‌ی
         * بارگذاریِ سفید را نشان می‌دهد؛ برای همین این تابع چندین بار
         * (در لحظه‌های مختلف) فراخوانی می‌شود تا در هر حالتی اجرا شود.
         */
        function signal(obj) {
            try { if (obj && typeof obj.ready === 'function') obj.ready(); } catch (e) {}
            try { if (obj && typeof obj.expand === 'function') obj.expand(); } catch (e) {}
        }

        function signalAll() {
            try { if (window.Bale && window.Bale.WebApp) signal(window.Bale.WebApp); } catch (e) {}
            try { if (window.Telegram && window.Telegram.WebApp) signal(window.Telegram.WebApp); } catch (e) {}
        }

        var readyTg = false, readyBale = false, tries = 0;

        function tick() {
            tries++;

            try {
                if (!readyBale && window.Bale && window.Bale.WebApp) {
                    readyBale = true;
                    signal(window.Bale.WebApp);
                }
            } catch (e) {}

            try {
                if (!readyTg && window.Telegram && window.Telegram.WebApp) {
                    readyTg = true;
                    signal(window.Telegram.WebApp);
                }
            } catch (e) {}

            // فقط وقتی پلتفرمِ مربوطه آماده شد متوقف می‌شویم
            if (isBale && !isTelegram && readyBale) { clearInterval(timer); return; }
            if (isTelegram && !isBale && readyTg)   { clearInterval(timer); return; }
            if (readyTg && readyBale)               { clearInterval(timer); return; }

            // مهلتِ بسیار طولانی‌تر (۳۰ ثانیه) برای شبکه‌های کند
            if (tries > 300) clearInterval(timer);
        }

        var timer = setInterval(tick, 100);
        tick();

        // تلاشِ دوباره پس از آماده شدنِ صفحه و پس از بارگیریِ کامل
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { setTimeout(signalAll, 0); });
        } else {
            setTimeout(signalAll, 0);
        }
        window.addEventListener('load', function () {
            setTimeout(signalAll, 0);
            setTimeout(signalAll, 500);
            setTimeout(signalAll, 1500);
        });

        /*
         * لودِ غیرِ مسدودکننده فقط در صورتی که اسکریپتِ مورد نیاز هنوز
         * در دسترس نباشد (مثلاً وقتی User-Agent پلتفرم را نشان نمی‌دهد).
         * برای بله، اسکریپت در ابتدای head به‌صورت مستقیم درج شده است.
         */
        var sources = [];

        if (window.Bale && window.Bale.WebApp) {
            // مینی‌اپ بله آماده است؛ اسکریپت اضافه لازم نیست
        } else if (isBale && !isTelegram) {
            if (!window.Bale) sources.push('https://tapi.bale.ai/miniapp.js?3');
        } else if (isTelegram && !isBale) {
            sources.push('telegram-web-app.js');
        } else if (/eitaa/i.test(ua)) {
            sources.push('https://developer.eitaa.com/eitaa-web-app.js');
        } else {
            if (!window.Bale) sources.push('https://tapi.bale.ai/miniapp.js?3');
            sources.push('telegram-web-app.js');
        }

        for (var i = 0; i < sources.length; i++) {
            (function (src) {
                var s = document.createElement('script');
                s.src = src;
                s.async = true;
                s.onerror = function () {
                    // اگر اسکریپت در دسترس نبود، صفحه به کارش ادامه می‌دهد
                    if (window.console && console.warn) console.warn('عدم دسترسی به ' + src);
                };
                document.head.appendChild(s);
            })(sources[i]);
        }
    })();
    </script>

    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" media="print" onload="this.media='all'" />
    <link rel="stylesheet" href="style.css">
    <!-- لایه طراحی لوکس -->
    <link rel="stylesheet" href="design-pro.css?v=<?= (int)@filemtime(__DIR__ . '/design-pro.css') ?>">

    <!-- Theme -->
    <?php
    // راند ۶۳: تم پیش‌فرض کاربران از تنظیمات ادمین (تب تم و رنگ / تنظیمات عمومی)
    $melkinoPublicDefaultTheme = 'light';
    try {
        if (function_exists('dbSettingGet')) {
            $melkinoPublicDefaultTheme = dbSettingGet($pdo, 'global', 'default_theme', 'light') === 'dark' ? 'dark' : 'light';
        }
    } catch (Throwable $e) {}
    ?>
    <script>
    (function () {
        try {
            var savedTheme = localStorage.getItem('melkino_theme');
            var theme = savedTheme === 'dark' ? 'dark' : (savedTheme === 'light' ? 'light' : <?= json_encode($melkinoPublicDefaultTheme) ?>);
            document.documentElement.setAttribute('data-theme', theme);
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }

        window.melkinoTheme = {
            get: function () {
                return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            },
            set: function (theme) {
                theme = theme === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                try {
                    localStorage.setItem('melkino_theme', theme);
                } catch (e) {}
                window.dispatchEvent(new CustomEvent('melkino:themechange', { detail: { theme: theme } }));
            },
            toggle: function () {
                this.set(this.get() === 'dark' ? 'light' : 'dark');
            }
        };

        window.toggleTheme = function () {
            window.melkinoTheme.toggle();
        };
    })();
    </script>

    <?php require_once __DIR__ . '/csrf-shim.php'; ?>
    <?php
    if (is_file(__DIR__ . '/field-icons.php')) {
        require_once __DIR__ . '/field-icons.php';
        if (function_exists('melkinoFieldIconsFrontScript')) {
            echo melkinoFieldIconsFrontScript();
        }
    }
    ?>
    <?php
    if (!function_exists('melkinoSiteLogoUrl') && is_file(__DIR__ . '/melkino-logo.php')) {
        require_once __DIR__ . '/melkino-logo.php';
    }
    if (!function_exists('melkinoPhotoWatermark') && is_file(__DIR__ . '/photo-watermark.php')) {
        require_once __DIR__ . '/photo-watermark.php';
    }
    $mkWmLogo = function_exists('melkinoSiteLogoUrl') ? (string) melkinoSiteLogoUrl() : '';
    $mkWmCfg = function_exists('melkinoPhotoWatermark') ? melkinoPhotoWatermark() : ['enabled' => true, 'opacity' => 0.32, 'size' => 38];
    $mkWmJsV = (int) @filemtime(__DIR__ . '/photo-wm-front.js');
    ?>
    <script>
    window.MELKINO_SITE_LOGO = <?= json_encode($mkWmLogo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    window.MELKINO_PHOTO_WM = <?= json_encode($mkWmCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="photo-wm-front.js?v=<?= $mkWmJsV ?>"></script>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body>
    <div class="app-container">
        <header class="topbar">
            <div style="display:flex; align-items:center; gap:var(--space-2); min-width:0; flex:1;">
                <!-- منو همبرگری -->
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>

                <?php if (!empty($_SESSION['is_admin'])): ?>
                <!-- آیکون مدیریت (فقط برای ادمینی که واقعاً وارد شده) -->
                <a href="admin-panel.php" style="display:flex; align-items:center; color:var(--primary); text-decoration:none; transition:0.2s; flex-shrink:0;" title="ورود به پنل مدیریت">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"></path><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.09A1.65 1.65 0 0 0 20.91 10H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09A1.65 1.65 0 0 0 19.4 15z"></path></svg>
                </a>
                <?php endif; ?>

                <?php if (function_exists('melkinoCalcEnabled') && melkinoCalcEnabled() && !$mkSharedGuest): ?>
                <!-- ماشین‌حساب قیمت ملک — دکمه طلایی کنار لوگو -->
                <a href="property-calculator.php" class="mk-calc-btn" title="ماشین‌حساب قیمت ملک" aria-label="ماشین‌حساب قیمت ملک">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="5" y="3" width="14" height="18" rx="2"></rect>
                        <rect x="8" y="6" width="8" height="3" rx="0.5"></rect>
                        <circle cx="9" cy="13" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="12" cy="13" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="15" cy="13" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="9" cy="16.5" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="12" cy="16.5" r="0.9" fill="currentColor" stroke="none"></circle>
                        <circle cx="15" cy="16.5" r="0.9" fill="currentColor" stroke="none"></circle>
                    </svg>
                </a>
                <?php endif; ?>

                <!-- ===== لوگو (مسیر مستقیم) ===== -->
                <a href="<?= $mkSharedGuest ? htmlspecialchars(melkinoSharedPropertyLoginUrl(), ENT_QUOTES, "UTF-8") : 'home.php' ?>" style="display:flex; align-items:center; text-decoration:none; max-width:180px; flex-shrink:1; min-width:0;">
                    <?php require_once __DIR__ . '/melkino-logo.php'; $melkinoHeaderLogo = melkinoSiteLogoUrl(); $melkinoHeaderLogoDark = melkinoSiteDarkLogoUrl(); ?>
                    <?php if ($melkinoHeaderLogo !== '' || $melkinoHeaderLogoDark !== ''): ?>
                    <?php $mkLightSrc = $melkinoHeaderLogo !== '' ? $melkinoHeaderLogo : $melkinoHeaderLogoDark; ?>
                    <style>img.mk-logo-dark{display:none}html[data-theme="dark"] img.mk-logo-light:not(.mk-logo-solo){display:none}html[data-theme="dark"] img.mk-logo-dark{display:inline-block}</style>
                    <img class="mk-logo-light<?= $melkinoHeaderLogoDark === '' ? ' mk-logo-solo' : '' ?>" src="<?= htmlspecialchars($mkLightSrc) ?>" alt="ملکینو" style="height:44px; width:auto; max-width:100%; max-height:52px; object-fit:contain;">
                    <?php if ($melkinoHeaderLogoDark !== ''): ?>
                    <img class="mk-logo-dark" src="<?= htmlspecialchars($melkinoHeaderLogoDark) ?>" alt="ملکینو" style="height:44px; width:auto; max-width:100%; max-height:52px; object-fit:contain;">
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="font-weight:900; font-size:1.3rem; color:var(--primary, #0b5d5b); white-space:nowrap;">ملکینو</span>
                    <?php endif; ?>
                </a>
            </div>

            <div style="display:flex; gap:var(--space-2); align-items:center; flex-shrink:0;">
                <!-- تغییر تم -->
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeBtn" type="button" title="تغییر تم" aria-label="تغییر تم" data-tour="theme">
                    <svg id="themeIcon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></svg>
                </button>

<?php if (!$mkSharedGuest): ?>
                <!-- نوتیفیکیشن -->
                <a href="notifications.php" class="notif-wrapper" data-tour="notifications" style="text-decoration:none; color:inherit; position:relative;">
                    <div style="position:relative; cursor:pointer;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        <span class="notif-badge" id="notifBadge" style="display:none; position:absolute; top:-6px; right:-8px; background:var(--danger, #dc3545); color:#fff; border-radius:50%; font-size:11px; padding:1px 6px; min-width:18px; text-align:center; line-height:1.5;">0</span>
                    </div>
                </a>

                <!-- پروفایل -->
                <a href="profile.php" data-tour="header-profile" style="display:flex; align-items:center; color:var(--text-secondary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                </a>
<?php endif; ?>
            </div>
        </header>

        <script>
        (function () {
            window.updateThemeButton = function () {
                var icon = document.getElementById('themeIcon');
                if (!icon) return;
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                icon.innerHTML = isDark
                    ? '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>'
                    : '<circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="19.78" y1="4.22" x2="18.36" y2="5.64"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="19.78" y1="19.78" x2="18.36" y2="18.36"></line>';
                document.getElementById('themeBtn')?.setAttribute('aria-pressed', isDark ? 'true' : 'false');
                document.getElementById('themeBtn')?.setAttribute('title', isDark ? 'فعال‌سازی حالت روشن' : 'فعال‌سازی حالت تاریک');
            };

            window.addEventListener('melkino:themechange', window.updateThemeButton);
            document.addEventListener('DOMContentLoaded', window.updateThemeButton);
        })();

        // ===== به‌روزرسانی خودکار تعداد نوتیفیکیشن‌ها =====
        (function() {
            if (window.MELKINO_SHARED_GUEST) return;
            function updateNotificationBadge() {
                var tid = localStorage.getItem('melkino_telegram_id') || sessionStorage.getItem('reg_telegram_id') || '';
                var badge = document.getElementById('notifBadge');
                if (!badge) return;
                
                fetch('notifications.php?action=count&telegram_id=' + encodeURIComponent(tid))
                    .then(res => res.json())
                    .then(data => {
                        var count = data.unread || 0;
                        if (count > 0) {
                            badge.textContent = count > 99 ? '99+' : count;
                            badge.style.display = 'inline-block';
                        } else {
                            badge.style.display = 'none';
                        }
                    })
                    .catch(function() {
                        badge.style.display = 'none';
                    });
            }

            // بارگذاری اولیه
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', updateNotificationBadge);
            } else {
                updateNotificationBadge();
            }

            // هر ۳۰ ثانیه به‌روزرسانی
            setInterval(updateNotificationBadge, 30000);

            // گوش دادن به رویداد برای به‌روزرسانی فوری
            window.addEventListener('melkino:notificationUpdate', updateNotificationBadge);
        })();
        </script>

        <script>
        /*
         * جدول visits و endpoint مربوطش از قبل توی پروژه بودن ولی هیچ
         * صفحه‌ای صداشون نمی‌زد؛ یعنی حتی یک بازدید هم ثبت نمی‌شد.
         * این اسکریپت روی همه‌ی صفحات، هر بازدید رو ثبت می‌کنه —
         * حتی بازدیدکننده‌ی کاملاً ناشناسی که نه شماره داده نه از
         * تلگرام اومده؛ IP و User-Agent و صفحه‌ی بازدیدشده ثبت می‌شه.
         */
        (function() {
            if (window.MELKINO_SHARED_GUEST) return;
            try {
                var telegramId = localStorage.getItem('melkino_telegram_id') || sessionStorage.getItem('reg_telegram_id') || '';
                var urlParams = new URLSearchParams(window.location.search);
                fetch('page-visits.php?action=hit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        path: window.location.pathname,
                        ad_id: urlParams.get('id') || '',
                        telegram_id: telegramId
                    })
                }).catch(function() {});
            } catch (e) {}
        })();
        </script>

        <script>
        window.NOTIF_STORAGE_KEY = window.NOTIF_STORAGE_KEY || 'melkino_notifications';
        </script>

        <script>
        /*
         * راند ۷۴: به‌محض باز شدن مینی‌اپ، initData امضاشده به سرور می‌رود
         * و ردیف کاربر (آی‌دی تلگرام + امضا) در تب کاربران ساخته می‌شود.
         * اسکریپت تا آماده شدن SDK چند بار تلاش می‌کند — قبلاً یک‌بار در لحظهٔ
         * پارس اجرا می‌شد و چون SDK هنوز لود نشده بود، هویت هرگز ذخیره نمی‌شد.
         */
        (function() {
        if (window.MELKINO_SHARED_GUEST) return;
        if (document.documentElement.hasAttribute('data-melkino-messenger')) return;
            if (window.MELKINO_PROFILE && window.MELKINO_PROFILE.logged_in) return;
            var tries = 0;
            var inflight = false;
            function sdkData() {
                var tg = '', bl = '';
                try { if (window.Telegram && window.Telegram.WebApp) tg = window.Telegram.WebApp.initData || ''; } catch (e) {}
                try { if (window.Bale && window.Bale.WebApp) bl = window.Bale.WebApp.initData || ''; } catch (e) {}
                return { telegram: tg, bale: bl };
            }
            function hashData() {
                var h = (location.hash || '').replace(/^#/, '');
                if (!h) return '';
                try { var p = new URLSearchParams(h); return p.get('tgWebAppData') || ''; } catch (e) { return ''; }
            }
            function identify() {
                if (inflight) return;
                try { if (sessionStorage.getItem('melkino_identified_ok') === '1') return; } catch (e) {}
                var d = sdkData();
                var data = d.telegram || d.bale || hashData();
                if (!data) return;
                try { document.documentElement.classList.add('mk-await-id'); } catch (e) {}
                inflight = true;
                var platform = d.bale && !d.telegram ? 'bale' : 'telegram';
                fetch('profile-sync.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ init_data: data, platform: platform })
                }).then(function(r) { return r.json(); })
                  .then(function(res) {
                        inflight = false;
                        if (res && res.success) {
                            try { sessionStorage.setItem('melkino_identified_ok', '1'); } catch (e) {}
                            try { sessionStorage.setItem('melkino_profile_synced', 'ok'); } catch (e) {}
                            try { document.documentElement.classList.remove('mk-await-id'); } catch (e) {}
                            var tg = window.Telegram && window.Telegram.WebApp;
                            var u = tg && tg.initDataUnsafe && tg.initDataUnsafe.user;
                            if (u && u.id) {
                                try { localStorage.setItem('melkino_telegram_id', String(u.id)); } catch (e) {}
                                try { sessionStorage.setItem('reg_telegram_id', String(u.id)); } catch (e) {}
                            }
                        }
                  })
                  .catch(function() { inflight = false; });
            }
            identify();
            var timer = setInterval(function () {
                tries++;
                identify();
                if (tries > 40) clearInterval(timer);
            }, 200);
            setTimeout(function () {
                try { document.documentElement.classList.remove('mk-await-id'); } catch (e) {}
            }, 4000);
        })();
        </script>
        <style>
        html.mk-await-id #mkHomeAdsList,
        html.mk-await-id .properties-list { opacity: .35; pointer-events: none; }
        .mk-calc-btn {
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: linear-gradient(145deg, #F0D36A, #D4AF37);
            color: #173131;
            text-decoration: none;
            box-shadow: 0 6px 14px rgba(212,175,55,.32);
            z-index: 2;
        }
        .mk-calc-btn:active { transform: scale(.96); }
        .mk-photo-wm {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 6;
            user-select: none;
        }
        </style>