<?php
/**
 *--------------------------------------------------------------------------
 * ورودی هوشمند مینی‌اپ بله + صفحهٔ تشخیص
 *--------------------------------------------------------------------------
 * چرا این صفحه وجود دارد؟
 *   اگر آدرس مینی‌اپِ ثبت‌شده در ربات به این صفحه اشاره کند (یادگارِ دوران
 *   عیب‌یابی) کاربر به‌جای اپ، یک بن‌بست می‌دید. حالا این صفحه:
 *     ۱) پارامترهای ورود (#tgWebAppData) را «قبل از پاک‌شدن توسط SDK»
 *        ذخیره می‌کند؛
 *     ۲) اگر پارامتر هست، بعد از ۲ ثانیه خودکار به اپ واقعی (index.php)
 *        منتقل می‌شود و هش را کامل فوروارد می‌کند تا ورود انجام شود؛
 *     ۳) اگر پارامتر نیست، به زبان ساده می‌گوید مشکل از کجاست (لینکِ
 *        معمولی به‌جای دکمهٔ منو، یا صفحهٔ امنیتی هاست که پارامتر را خورده).
 *
 * بدون نیاز به ورود (در فهرست مجاز auth است) و بدون چاپ هیچ راز/توکن.
 */
if (!headers_sent()) {
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود به ملکینو</title>
<script>
/* اول از همه: ذخیرهٔ هش پیام‌رسان، چون SDK بله بعد از خواندن، آن را پاک می‌کند. */
try {
    window.__probeEarlyHash = location.hash || '';
    if (location.hash) {
        try { sessionStorage.setItem('melkino_probe_hash', location.hash); } catch (e0) {}
    } else {
        try {
            var ph = sessionStorage.getItem('melkino_probe_hash') || '';
            if (ph) window.__probeEarlyHash = ph;
        } catch (e1) {}
    }
} catch (e) {}
</script>
<script src="https://tapi.bale.ai/miniapp.js?3"></script>
<script>
(function () {
    function go() {
        try {
            var w = window.Bale && window.Bale.WebApp;
            if (w) {
                try { w.ready(); } catch (e) {}
                try { w.expand(); } catch (e) {}
            }
        } catch (e) {}
    }
    go();
    setTimeout(go, 50);
    setTimeout(go, 300);
})();
</script>
<style>
body{font-family:Tahoma,sans-serif;background:#122320;color:#fff;margin:0;padding:24px;line-height:2}
h1{font-size:20px;margin:0 0 12px}
.box{padding:14px 16px;border-radius:12px;margin:12px 0}
.ok{background:#1F8A70}
.warn{background:#8A6D1F}
.bad{background:#8A1F1F}
.muted{color:#BFD8D2;font-size:13px}
a.btn{display:inline-block;background:#fff;color:#122320;font-weight:bold;text-decoration:none;
    padding:10px 22px;border-radius:10px;margin:8px 0 0}
a.btn.ghost{background:transparent;color:#fff;border:1px solid #4a6b65;font-weight:normal}
ol{margin:8px 0;padding-right:20px}
code{direction:ltr;display:inline-block;background:#0D1413;padding:1px 8px;border-radius:6px;font-size:12px}
details{margin-top:14px;font-size:12px;color:#BFD8D2}
details table{width:100%;border-collapse:collapse;font-size:12px}
details td{padding:4px 6px;border-bottom:1px solid #244a46;vertical-align:top}
details td:first-child{width:44%}
#count{font-weight:bold}
</style>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body>
<h1>ملکینو — ورودی مینی‌اپ</h1>
<div id="verdict" class="box warn">در حال بررسی نحوهٔ باز شدن…</div>
<div id="actions"></div>
<p class="muted">اگر این متن را می‌بینی، خودِ صفحه سالم لود شده و مشکل از «نحوهٔ باز شدن» است نه از هاست.</p>
<details>
<summary>جزئیات فنی (برای پشتیبانی)</summary>
<table id="tech"></table>
</details>
<script>
(function () {
    var verdict = document.getElementById('verdict');
    var actions = document.getElementById('actions');
    var tech = document.getElementById('tech');

    function row(k, v) {
        var tr = document.createElement('tr');
        var a = document.createElement('td');
        var b = document.createElement('td');
        a.textContent = k;
        b.textContent = v;
        tr.appendChild(a);
        tr.appendChild(b);
        tech.appendChild(tr);
    }

    /* ---------- جمع‌آوری نشانه‌ها ---------- */
    var earlyHash = '';
    try { earlyHash = window.__probeEarlyHash || location.hash || ''; } catch (e) {}
    var hashHasData = /tgWebAppData=./.test(earlyHash);

    var w = null;
    try { w = window.Bale && window.Bale.WebApp; } catch (e2) {}
    var sdkData = '';
    var platform = '';
    var version = '';
    try {
        if (w) {
            sdkData = w.initData || '';
            platform = w.platform || '';
            version = w.version || '';
        }
    } catch (e3) {}

    var query = '';
    try { query = location.search || ''; } catch (e4) {}
    /* InfinityFree روی اولین بازدید، یک صفحهٔ امنیتی (?i=1) نشان می‌دهد و
       ریدایرکت جاوااسکریپتی‌اش تکهٔ #… (پارامتر ورود) را می‌اندازد. */
    var challengeSeen = /[?&](i|ckattempt)=1\b/.test(query);
    var testCookie = false;
    try { testCookie = /(?:^|;\s*)__test=/.test(document.cookie || ''); } catch (e5) {}

    var ref = '';
    try { ref = document.referrer || ''; } catch (e6) {}
    var ua = '';
    try { ua = navigator.userAgent || ''; } catch (e7) {}

    row('هش اولیه (#…)', earlyHash ? (earlyHash.length + ' کاراکتر') : 'خالی');
    row('tgWebAppData در هش', hashHasData ? 'دارد ✅' : 'ندارد ❌');
    row('SDK بله (Bale.WebApp)', w ? 'وصل است ✅' : 'نیست ❌');
    row('initData در SDK', sdkData ? (sdkData.length + ' کاراکتر ✅') : 'خالی ❌');
    row('platform/version', (platform || '—') + ' / ' + (version || '—'));
    row('صفحهٔ امنیتی هاست (?i=1)', challengeSeen ? 'دیده شد ⚠️' : 'دیده نشد');
    row('کوکی __test هاست', testCookie ? 'موجود (عبور قبلی موفق)' : 'نیست');
    row('ارجاع‌دهنده (referrer)', ref || '(خالی)');
    row('مرورگر (UA)', ua || '(خالی)');

    function btn(text, href, ghost, id) {
        var a = document.createElement('a');
        a.textContent = text;
        a.className = 'btn' + (ghost ? ' ghost' : '');
        if (href) a.href = href;
        if (id) a.id = id;
        actions.appendChild(a);
        actions.appendChild(document.createTextNode(' '));
        return a;
    }

    /* اگر هش پاک شده ولی SDK داده را دارد (حافظهٔ مرورگر بسته است)،
       داده را در کوئری می‌گذاریم — صفحهٔ ورود (?tgWebAppData) را هم می‌خواند. */
    var target = 'index.php';
    if (earlyHash) {
        target += (earlyHash.charAt(0) === '#' ? earlyHash : '#' + earlyHash);
    } else if (sdkData) {
        try { target += '?tgWebAppData=' + encodeURIComponent(sdkData); } catch (e9) {}
    }

    /* ---------- رأی نهایی ---------- */
    if (hashHasData || sdkData) {
        verdict.className = 'box ok';
        verdict.innerHTML = '✅ مینی‌اپ <b>درست</b> باز شده و پارامتر ورود رسیده است.'
            + '<br>در حال انتقال خودکار به ملکینو… (<span id="count">۲</span>)';
        var go = btn('ورود به ملکینو ←', target, false, null);
        var stop = btn('توقف انتقال (ماندن برای بررسی)', '', true, 'stopBtn');
        var left = 2;
        var timer = setInterval(function () {
            left--;
            var c = document.getElementById('count');
            if (c) c.textContent = left > 0 ? String(left).replace(/0/g, '۰').replace(/1/g, '۱').replace(/2/g, '۲') : '۰';
            if (left <= 0) {
                clearInterval(timer);
                try { location.replace(target); } catch (e8) { location.href = target; }
            }
        }, 1000);
        stop.addEventListener('click', function (ev) {
            ev.preventDefault();
            clearInterval(timer);
            verdict.innerHTML = '⏸ انتقال متوقف شد. هر وقت خواستی با دکمهٔ «ورود به ملکینو» وارد شو.';
        });
    } else if (challengeSeen || (!testCookie && /bale|ble\.ir/i.test(ua))) {
        verdict.className = 'box warn';
        verdict.innerHTML = '⚠️ پارامتر ورود به صفحه نرسیده، چون <b>صفحهٔ امنیتی هاست</b>'
            + ' (InfinityFree) وسط راه آن را انداخته است. این خطای ملکینو نیست و با یک‌بار بازوبسته‌کردن درست می‌شود:'
            + '<ol><li>مینی‌اپ را <b>کامل ببند</b> (دکمهٔ ✕)؛</li>'
            + '<li>دوباره از <b>دکمهٔ منوی ربات</b> (نه لینک داخل چت) بازش کن؛</li>'
            + '<li>اگر باز هم همین صفحه را دیدی، آدرس مینی‌اپِ ثبت‌شده در ربات باید آدرس اصلی سایت باشد، نه این صفحهٔ تست.</li></ol>';
        btn('تلاش دوباره (باز کردن اپ)', 'index.php', false, null);
    } else {
        verdict.className = 'box bad';
        verdict.innerHTML = '❌ این صفحه با <b>لینک معمولی</b> باز شده، نه به‌عنوان مینی‌اپ؛'
            + ' به همین دلیل بله هیچ پارامتر ورودی نفرستاده (<code>initData خالی</code>).'
            + '<ol><li>مینی‌اپ را فقط از <b>دکمهٔ منوی ربات</b> باز کن، نه با زدن روی لینک داخل چت؛</li>'
            + '<li>آدرس مینی‌اپ در تنظیمات ربات باید <b>آدرس اصلی سایت</b> باشد (مثلاً <code>index.php</code>) نه این صفحهٔ تست.</li></ol>';
        btn('باز کردن صفحهٔ اصلی سایت', 'index.php', false, null);
    }
})();
</script>
</body>
</html>
