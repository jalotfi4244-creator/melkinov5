<?php
/** One preload/login document for Telegram, Bale, Eitaa, ordinary web and V2. */
declare(strict_types=1);
require_once __DIR__.'/messenger-login-lib.php';
melkinoMessengerSessionStart();
require_once __DIR__.'/config.php';
require_once __DIR__.'/bot-settings.php';
global $pdo;
// Use the saved public brand logo, then the existing app logo; never a placeholder initial.
$loginLogo='assets/images/melkino-login-logo.png';$logoOrigin='';
try{
    require_once __DIR__.'/melkino-logo.php';
    $currentLogo=melkinoSiteLogoUrl();if($currentLogo!=='')$loginLogo=$currentLogo;
    require_once __DIR__.'/brand-site-lib.php';$brand=mkBrandRead();
    foreach(['logo_path','favicon_path'] as $key){
        $candidate=$brand['settings'][$key]??'';
        if(!is_string($candidate)||$candidate==='')continue;
        try{$candidate=mkBrandUrl($candidate,true);}catch(Throwable $e){continue;}
        if(str_starts_with($candidate,'/')){
            $loginLogo=$candidate.(str_contains($candidate,'?')?'&':'?').'mklogo='.substr(hash('sha256',(string)$brand['revision']),0,12);break;
        }
        $parts=parse_url($candidate);
        if(($parts['scheme']??'')==='https'&&!empty($parts['host'])){
            $loginLogo=$candidate;$logoOrigin=' https://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');break;
        }
    }
}catch(Throwable $e){ /* Brand settings must never block authentication. */ }

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header_remove('X-Frame-Options');
$nonce=base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://tapi.bale.ai https://developer.eitaa.com; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:{$logoOrigin}; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self' https://web.telegram.org https://*.telegram.org https://bale.ai https://*.bale.ai https://ble.ir https://eitaa.com https://*.eitaa.com");
melkinoMessengerSessionCookie();
if(empty($_SESSION['mk_login_probe']))$_SESSION['mk_login_probe']=bin2hex(random_bytes(16));
$hint=isset($melkinoForcedMessenger)&&isset(melkinoMessengerNames()[$melkinoForcedMessenger])?$melkinoForcedMessenger:melkinoMessengerHint();
$gateNotice=(isset($_GET['notice'])&&$_GET['notice']==='gate');
$next=melkinoMessengerTarget($melkinoLoginNext??$_GET['redirect']??'home.php');
$providers=[];
foreach(melkinoMessengerNames()as$p=>$label){
    $links=melkinoMessengerLinks($p);
    $providers[$p]=['label'=>$label,'enabled'=>melkinoLoginMethodEnabled($p),'sdk'=>melkinoMessengerSdk($p),
        'launch'=>$links['launch'],'bot'=>$links['bot'],'entry'=>$p.'-app.php'];
}
$cfg=['hint'=>$hint,'next'=>$next,'providers'=>$providers,'csrf'=>melkinoCsrfToken(),
    'sessionProbe'=>$_SESSION['mk_login_probe'],'endpoint'=>'messenger-auth.php','sms'=>melkinoLoginMethodEnabled('sms'),
    'version'=>'phone-login-v5-once'];
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-login-version="4">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php if(($melkinoForcedMessenger ?? '')==='eitaa'): ?>
<script src="https://developer.eitaa.com/eitaa-web-app.js"></script>
<?php endif; ?>
<meta name="csrf-token" content="<?= $esc($cfg['csrf']) ?>">
<meta name="theme-color" content="#0b5d59">
<title>ورود به ملکینو</title>
<script nonce="<?= $esc($nonce) ?>">
// Capture before any vendor SDK can consume/change the fragment. Memory only.
window.__mkLaunch = { hash: location.hash || '', search: location.search || '' };
try { var t=localStorage.getItem('melkino_theme'); if(t==='dark')document.documentElement.dataset.theme='dark'; } catch(e){}
</script>
<link rel="stylesheet" href="assets/css/messenger-login.css?v=<?= (int)filemtime(__DIR__.'/assets/css/messenger-login.css') ?>">
<style>
.mk-login-gate{margin:10px auto 4px;max-width:420px;display:flex;flex-direction:column;gap:4px;align-items:center;justify-content:center;background:rgba(221,195,140,.12);border:1px solid rgba(221,195,140,.45);color:#f4e8cf;border-radius:14px;padding:12px 16px;font-size:15px;font-weight:800;line-height:1.9;text-align:center}
.mk-login-gate i{color:#e8c37a;margin-inline-end:6px}
.mk-login-gate small{display:block;font-size:12px;font-weight:500;opacity:.8}
</style>
<script id="mkLoginConfig" type="application/json"><?= json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="assets/js/messenger-bridge.js?v=<?= (int)filemtime(__DIR__.'/assets/js/messenger-bridge.js') ?>" defer></script>
<script src="assets/js/messenger-login.js?v=<?= (int)filemtime(__DIR__.'/assets/js/messenger-login.js') ?>" defer></script>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
</head>
<body>
<div class="mk-lux-bg" aria-hidden="true"></div>
<div class="mk-lux-tl" aria-hidden="true"><strong>دنیای املاک</strong><span>در دستان شما</span></div>
<aside class="mk-lux-side" aria-hidden="true">
    <div class="mk-lux-feat"><i><svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></i><b>املاک مسکونی</b><small>آپارتمان، خانه، ویلا</small></div>
    <div class="mk-lux-feat"><i><svg viewBox="0 0 24 24"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10z"/></svg></i><b>املاک تجاری</b><small>دفتر کار، فروشگاه، مغازه</small></div>
    <div class="mk-lux-feat"><i><svg viewBox="0 0 24 24"><path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7zm0 9.5c-1.4 0-2.5-1.1-2.5-2.5S10.6 6.5 12 6.5s2.5 1.1 2.5 2.5S13.4 11.5 12 11.5z"/></svg></i><b>املاک کلنگی و زمین</b><small>سرمایه‌گذاری مطمئن</small></div>
</aside>
<div class="mk-lux-script" aria-hidden="true">با ملکینو<br>آسان‌تر خرید کنید...</div>
<main class="mk-login-shell">
    <section class="mk-login-card" aria-labelledby="mkLoginTitle">
        <header class="mk-login-brand"><img id="mkLoginLogo" class="mk-login-logo" src="<?= htmlspecialchars($loginLogo,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" alt="لوگوی ملکینو" width="72" height="72" referrerpolicy="no-referrer"><div><strong>ملکینو</strong><small>انتخابی فراتر از یک ملک</small></div></header>
        <h1 id="mkLoginTitle">به <span class="mk-gold">ملکینو</span> خوش آمدید</h1>
        <p class="mk-login-sub">با ورود به حساب کاربری خود، به دنیای فرصت‌های بی‌نظیر خرید، فروش و اجاره ملک دسترسی پیدا کنید.</p>
        <?php if($gateNotice): ?><p class="mk-login-gate" role="status">🔒 برای دیدن وارد شوید<small>جزئیات فایل‌ها فقط پس از ورود نمایش داده می‌شود</small></p><?php endif; ?>
        <?php
        $lxIcons = [
            'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 15.2l-.4 4.3c.6 0 .8-.3 1.1-.6l2.6-2.5 5.4 3.9c1 .5 1.7.3 1.9-.9l3.5-16.2c.3-1.4-.5-2-1.5-1.6L2.7 10.5c-1.4.5-1.4 1.4-.2 1.8l5.3 1.7L20 6.3c.6-.4 1.2-.2.7.2L9.8 15.2z"/></svg>',
            'eitaa' => '<img src="assets/images/eitaa-app.png" alt="" width="52" height="52" class="mk-app-img">',
            'bale' => '<img src="assets/images/bale-app.png" alt="" width="52" height="52" class="mk-app-img">',
            'phone' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z"/></svg>',
        ];
        ?>
        <div class="mk-tiles" role="group" aria-label="روش ورود">
            <?php foreach(['telegram','eitaa','bale'] as $tp): if(empty($providers[$tp])||!$providers[$tp]['enabled'])continue; ?>
            <button type="button" class="mk-tile mk-tile-<?= $tp ?>" data-provider="<?= $tp ?>" aria-pressed="false"><span class="mk-tile-ico"><?= $lxIcons[$tp] ?></span><span class="mk-tile-txt">ورود با <?= $esc($providers[$tp]['label']) ?></span></button>
            <?php endforeach; ?>
            <?php if($cfg['sms']): ?>
            <button type="button" class="mk-tile mk-tile-phone" data-open-sms="1"><span class="mk-tile-ico"><?= $lxIcons['phone'] ?></span><span class="mk-tile-txt">ورود با شماره موبایل</span></button>
            <?php endif; ?>
        </div>
        <div class="mk-divider"><span>یا</span></div>
        <p class="mk-login-help">برای ورود از طریق پیام‌رسان‌ها، روی گزینهٔ مورد نظر خود کلیک کنید.</p>
        <p class="mk-login-intro">تأیید شماره فقط برای اولین اتصال هر حساب پیام‌رسان</p>
        <ol class="mk-login-steps" aria-label="مراحل ورود">
            <li data-stage="detect">۱. پیام‌رسان</li><li data-stage="verify">۲. شمارهٔ پیام‌رسان</li><li data-stage="session">۳. ورود</li>
        </ol>
        <div class="mk-login-progress">
            <span class="mk-login-spinner" id="mkLoginSpinner" aria-hidden="true"></span>
            <div><span id="mkProviderLabel" class="mk-login-badge">در حال بررسی</span><p id="mkLoginStatus" role="status" aria-live="polite">در حال آماده‌سازی ورود…</p></div>
        </div>
        <section id="mkProviderChoice" class="mk-login-choice" aria-labelledby="mkChooseTitle" hidden>
            <h2 id="mkChooseTitle">از کدام برنامه وارد شده‌اید؟</h2>
            <div class="mk-provider-grid">
                <?php foreach($providers as$p=>$provider): if(!$provider['enabled'])continue; ?>
                <button type="button" data-provider="<?= $esc($p) ?>" aria-pressed="false"><?= $esc($provider['label']) ?><span>انتخاب</span></button>
                <?php endforeach; ?>
            </div>
            <p id="mkReopenHelp" class="mk-login-help">اگر هویت دریافت نشد، <strong>برنامک را کاملاً ببندید و از دکمهٔ اجرای آن در ربات دوباره باز کنید.</strong> باز کردن لینک معمولی سایت، الزاماً ورود مینی‌اپ نیست.</p>
        </section>
        <button type="button" class="mk-primary" id="mkPhoneContact" hidden>اشتراک شماره و ورود</button>
        <form id="mkPhoneNameForm" class="mk-phone-name" hidden>
            <p>شمارهٔ تأییدشده: <b id="mkVerifiedPhone" dir="ltr"></b></p>
            <label for="mkFirstName">نام (اختیاری)</label><input id="mkFirstName" autocomplete="given-name" maxlength="80">
            <label for="mkLastName">نام خانوادگی (الزامی)</label><input id="mkLastName" autocomplete="family-name" maxlength="80" required>
            <button type="submit" class="mk-primary" id="mkPhoneRegister">ثبت و ورود</button>
        </form>
        <div class="mk-login-actions">
            <button type="button" id="mkLoginRetry" class="mk-primary" hidden>تلاش دوباره در همین صفحه</button>
            <a id="mkOpenApp" class="mk-primary" href="#" rel="noopener noreferrer" hidden>باز کردن برنامک</a>
            <a id="mkOpenBot" class="mk-secondary" href="#" rel="noopener noreferrer" hidden>باز کردن صفحهٔ ربات</a>
            <button type="button" id="mkCloseApp" class="mk-secondary" hidden>بستن برنامک</button>
            <button type="button" id="mkContinueSession" class="mk-secondary" hidden>ادامه با نشست فعلی سایت</button>
        </div>
        <?php if($cfg['sms']): ?>
        <details class="mk-login-sms" id="mkSmsPanel">
            <summary>ورود با شماره موبایل</summary>
            <p class="mk-login-help">شمارهٔ تأییدشده، همان حساب مشترک تلگرام، بله و ایتای شما را باز می‌کند.</p>
            <label for="smsPhone">شماره موبایل</label>
            <div class="mk-sms-row"><input id="smsPhone" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="09123456789" maxlength="16"><button id="smsSendBtn" type="button" class="mk-secondary">دریافت کد</button></div>
            <div id="mkSmsCodeRow" hidden><label for="smsCode">کد ورود</label><div class="mk-sms-row"><input id="smsCode" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><button id="smsVerifyBtn" type="button" class="mk-primary">تأیید و ورود</button></div></div>
            <p id="smsMsg" role="status" aria-live="polite"></p>
        </details>
        <?php endif; ?>
        <details class="mk-login-diag"><summary>راهنمای رفع مشکل</summary><p>فقط کد وضعیت زیر را برای پشتیبانی بفرستید؛ نیازی به توکن یا اطلاعات حساب نیست.</p><output id="mkLoginDiagnostic" dir="ltr">START</output></details>
        <noscript><p class="mk-login-help">برای ورود، JavaScript باید فعال باشد.</p></noscript>
        <div class="mk-trust" aria-label="ویژگی‌های ورود">
            <div><svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></svg><span>امن و مطمئن</span></div>
            <div><svg viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/></svg><span>دسترسی سریع</span></div>
            <div><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg><span>بدون نیاز به رمز عبور</span></div>
        </div>
        <a class="mk-back" href="../"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>بازگشت به صفحه اصلی</a>
    </section>
    <p class="mk-login-foot">حساب متصل، با تأیید امن هویت پیام‌رسان و بدون درخواست دوبارهٔ شماره باز می‌شود.</p>
</main>
</body>
</html>
