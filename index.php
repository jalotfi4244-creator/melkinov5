<?php
/**
 * ملکینو — صفحهٔ اصلی (لندینگ لوکس)
 * محتوا از تنظیمات سایت اصلی در پنل مدیریت برنامه خوانده می‌شود.
 * تصویر هیرو ثابت است و هیچ تغییر خودکار روز/شب، فصل یا آب‌وهوا ندارد.
 */
declare(strict_types=1);
// Old bot launch URLs may still point to the root. Preserve launch query data; never authenticate here.
foreach (array_keys($_GET) as $key) {
    if (stripos((string)$key, 'tgWebApp') === 0 || in_array($key, ['baleWebAppData','eitaaWebAppData','t'], true)) {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: /program/index.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 302);
        exit;
    }
}
require_once __DIR__ . '/config.php';
// قفل سایت و شمارش بازدیدکننده (صفحهٔ عمومی؛ دروازهٔ ورود برنامه برای لندینگ اجرا نشود)
if (!defined('MK_SKIP_LOGIN_GATE')) { define('MK_SKIP_LOGIN_GATE', true); }
require_once __DIR__ . '/program/config.php';
require_once __DIR__ . '/program/site-runtime-lib.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
siteRuntimeGate($GLOBALS['pdo'] ?? null);
siteRuntimeRecordVisit($GLOBALS['pdo'] ?? null);

$S        = all_settings();
$LANDING_HERO      = '/brand-media/landing/melkino-hero.jpg';
$LANDING_HERO_1200 = '/brand-media/landing/melkino-hero-1200.jpg';
$officePoint = mkBrandOfficePoint($S);
$officeMapUrl = $officePoint ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($officePoint['lat'].','.$officePoint['lng']) : $S['map_url'];
// نقشهٔ بخش موقعیت: فقط از مختصات دفتر (تنظیمات برند) ساخته می‌شود؛ بدون کلید API و بدون اسکریپت شخص ثالث.
$locEmbed = null; $locOpen = null;
if ($officePoint) {
    $la = sprintf('%.6F', (float)$officePoint['lat']);
    $lo = sprintf('%.6F', (float)$officePoint['lng']);
    $d  = 0.006;
    $bbox = sprintf('%.6F,%.6F,%.6F,%.6F', (float)$lo - $d, (float)$la - $d, (float)$lo + $d, (float)$la + $d);
    $locEmbed = 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $la . ',' . $lo;
    $locOpen  = 'https://www.openstreetmap.org/?mlat=' . $la . '&mlon=' . $lo . '#map=16/' . $la . '/' . $lo;
}
$locAddress = trim((string)$S['address']);
// اگر مختصات نباشد، فقط جستجو با همان نشانیِ نمایش‌داده‌شده (نه لینک پیش‌فرض قدیمی)
$locSearch = $locAddress !== '' ? 'https://www.openstreetmap.org/search?query=' . rawurlencode($locAddress) : null;
$features = features();
$quickSoc = social_links('quick');
$aboutPoints = array_slice(lines($S['about_points'] ?? ''), 0, 3);
$brandName = trim((string)$S['brand_name']) !== '' ? (string)$S['brand_name'] : 'ملکینو';
$appLabel  = trim((string)$S['app_button_text']) !== '' ? (string)$S['app_button_text'] : 'ورود به برنامه ملکینو';
$year = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<script>
// Compatibility only for OLD root launch URLs carrying identity data in the fragment.
// The program still verifies signatures; this site neither reads nor stores user identity.
(function(){var h=new URLSearchParams(location.hash.slice(1));if(['tgWebAppData','baleWebAppData','eitaaWebAppData'].some(function(k){return h.has(k);})){location.replace('/program/index.php'+location.search+location.hash);}})();
</script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($S['site_title'] ?: ($brandName . ' | ' . $S['hero_title'])) ?></title>
<meta name="description" content="<?= e($S['meta_description']) ?>">
<meta name="keywords" content="<?= e($S['meta_keywords']) ?>">
<meta name="theme-color" content="#061A15">
<?php echo (is_file(__DIR__ . '/program/brand-site-icon.php') ? (include __DIR__ . '/program/brand-site-icon.php') : ''); ?>
<meta property="og:title" content="<?= e($S['site_title']) ?>">
<meta property="og:description" content="<?= e($S['meta_description']) ?>">
<meta property="og:type" content="website">
<meta property="og:image" content="<?= e($LANDING_HERO_1200) ?>">
<meta property="og:url" content="<?= e(mkCanonicalUrl()) ?>">
<meta property="og:site_name" content="<?= e($brandName) ?>">
<link rel="canonical" href="<?= e(mkCanonicalUrl()) ?>">
<script type="application/ld+json"><?= mkJsonLd($S, $officePoint, social_links('all')) ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,500;1,300&family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preload" as="image" href="<?= e($LANDING_HERO) ?>" imagesrcset="<?= e($LANDING_HERO_1200) ?> 1200w, <?= e($LANDING_HERO) ?> 1671w" imagesizes="100vw">
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/landing-lux.css?v=r1">
</head>
<body class="lx">

<!-- ============ هیرو ============ -->
<section class="lx-hero" id="top" aria-label="<?= e($brandName) ?>">
  <img class="lx-hero__img" src="<?= e($LANDING_HERO_1200) ?>"
       srcset="<?= e($LANDING_HERO_1200) ?> 1200w, <?= e($LANDING_HERO) ?> 1671w"
       sizes="100vw" width="1671" height="941" alt="ویلای مدرن با استخر بی‌کران در غروب، منظرهٔ کوهستان و شهر" fetchpriority="high">
  <div class="lx-hero__shade" aria-hidden="true"></div>

  <header class="lx-top">
    <a class="lx-brand" href="#top" aria-label="<?= e($brandName) ?> — خانه">
      <span class="lx-brand__name"><?= e($brandName) ?></span>
      <span class="lx-brand__latin"><?= e($S['brand_latin']) ?></span>
    </a>
    <nav class="lx-nav" aria-label="منوی اصلی">
      <a href="#about"><?= e($S['nav_about_label']) ?></a>
      <a href="#features">چرا <?= e($brandName) ?></a>
      <a href="#contact"><?= e($S['nav_contact_label']) ?></a>
    </nav>
    <a class="lx-btn lx-btn--gold lx-btn--sm" data-program-entry href="<?= e(PROGRAM_ENTRY) ?>"><?= e($appLabel) ?> <span aria-hidden="true">←</span></a>
  </header>

  <div class="lx-hero__body">
    <p class="lx-eyebrow"><?= e($S['hero_eyebrow']) ?></p>
    <h1 class="lx-hero__title"><img class="lx-hero__logo" src="<?= e(BASE_URL) ?>/brand-media/landing/melkino-logo.png" width="600" height="583" alt="<?= e($brandName) ?>" decoding="async"></h1>
    <p class="lx-hero__lead"><?= e($S['hero_title']) ?></p>
    <p class="lx-hero__sub"><?= e($S['hero_sub']) ?></p>
    <div class="lx-cta">
      <a class="lx-btn lx-btn--gold" data-program-entry href="<?= e(PROGRAM_ENTRY) ?>"><?= e($appLabel) ?> <span aria-hidden="true">←</span></a>
      <a class="lx-btn lx-btn--ghost" href="#contact"><?= e($S['nav_contact_label']) ?></a>
    </div>
  </div>

  <ul class="lx-hero__foot">
    <li><?= e($S['header_note']) ?></li>
    <li><?= e($S['hero_meta_1']) ?></li>
    <li>پاسخگویی: <?= e(fa_num((string)$S['hours'])) ?></li>
  </ul>
</section>

<!-- ============ معرفی ============ -->
<section class="lx-about" id="about">
  <div class="lx-wrap lx-about__grid">
    <div class="lx-about__text">
      <p class="lx-kicker"><?= e($S['about_eyebrow']) ?></p>
      <h2 class="lx-title"><?= title_em($S['about_title']) ?></h2>
      <div class="lx-body"><?= paragraphs($S['about_text']) ?></div>
      <?php if ($S['about_quote'] !== ''): ?>
        <blockquote class="lx-quote"><?= e($S['about_quote']) ?></blockquote>
      <?php endif; ?>
    </div>
    <?php if ($aboutPoints): ?>
      <ol class="lx-points">
        <?php foreach ($aboutPoints as $i => $p): ?>
          <li><span class="lx-points__n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><span><?= e($p) ?></span></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</section>

<!-- ============ ویژگی‌ها ============ -->
<section class="lx-pillars" id="features">
  <div class="lx-wrap">
    <header class="lx-head">
      <p class="lx-kicker lx-kicker--gold">Why <?= e($S['brand_latin']) ?></p>
      <h2 class="lx-title lx-title--light">چرا <em><?= e($brandName) ?>؟</em></h2>
    </header>
    <div class="lx-pillars__grid">
      <?php foreach ($features as $i => $f): ?>
        <article class="lx-pillar">
          <span class="lx-pillar__n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3><?= e($f['title']) ?></h3>
          <p><?= e($f['description']) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ دعوت به برنامه ============ -->
<section class="lx-invite" aria-label="ورود به برنامه">
  <div class="lx-wrap lx-invite__inner">
    <span class="lx-invite__mark" aria-hidden="true"><?= e(mb_substr($brandName, 0, 1)) ?></span>
    <a class="lx-btn lx-btn--gold lx-btn--lg" data-program-entry href="<?= e(PROGRAM_ENTRY) ?>"><?= e($appLabel) ?> <span aria-hidden="true">←</span></a>
  </div>
</section>

<!-- ============ تماس ============ -->
<section class="lx-contact" id="contact">
  <div class="lx-wrap lx-contact__grid">
    <div>
      <p class="lx-kicker"><?= e($S['access_eyebrow']) ?></p>
      <h2 class="lx-title"><?= e($S['contact_title']) ?></h2>
      <p class="lx-body"><?= e($S['contact_lead']) ?></p>
    </div>
    <dl class="lx-details">
      <?php if ($S['phone'] !== ''): ?><div><dt>تلفن</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$S['phone'])) ?>" dir="ltr"><?= e(fa_num((string)$S['phone'])) ?></a></dd></div><?php endif; ?>
      <?php if ($S['mobile'] !== ''): ?><div><dt>موبایل</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$S['mobile'])) ?>" dir="ltr"><?= e(fa_num((string)$S['mobile'])) ?></a></dd></div><?php endif; ?>
      <?php if ($S['email'] !== ''): ?><div><dt>ایمیل</dt><dd><a href="mailto:<?= e((string)$S['email']) ?>"><?= e((string)$S['email']) ?></a></dd></div><?php endif; ?>
      <?php if ($S['address'] !== ''): ?><div><dt>نشانی</dt><dd><?= e((string)$S['address']) ?></dd></div><?php endif; ?>
      <?php if ($S['hours'] !== ''): ?><div><dt>ساعت کاری</dt><dd><?= e(fa_num((string)$S['hours'])) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($quickSoc): ?>
      <div class="lx-social" aria-label="شبکه‌های اجتماعی">
        <?php foreach ($quickSoc as $s): ?>
          <a href="<?= e(clean_url((string)$s['url'])) ?>" target="_blank" rel="noopener" title="<?= e($s['network']) ?>"><?= social_icon_html($s) ?><span class="sr"><?= e($s['network']) ?></span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($officeMapUrl !== ''): ?>
      <a class="lx-btn lx-btn--ghost-dark" href="<?= e(clean_url($officeMapUrl)) ?>" target="_blank" rel="noopener">مسیریابی روی نقشه</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($locEmbed !== null || $locAddress !== ''): ?>
<!-- ============ موقعیت ============ -->
<section class="lx-location" id="location" aria-labelledby="locTitle">
  <div class="lx-wrap">
    <div class="lx-location__head">
      <p class="lx-kicker">موقعیت</p>
      <h2 class="lx-title" id="locTitle">دفتر ما روی نقشه</h2>
      <?php if ($locAddress !== ''): ?><p class="lx-body"><?= e($locAddress) ?></p><?php endif; ?>
    </div>
    <?php if ($locEmbed !== null): ?>
      <div class="lx-map">
        <iframe title="نقشهٔ موقعیت دفتر <?= e($brandName) ?>" src="<?= e($locEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
      <div class="lx-location__actions">
        <a class="lx-btn lx-btn--ghost-dark" href="<?= e(clean_url($locOpen)) ?>" target="_blank" rel="noopener">بزرگ‌نمایی روی نقشه</a>
        <?php if ($officeMapUrl !== ''): ?><a class="lx-btn lx-btn--ghost-dark" href="<?= e(clean_url($officeMapUrl)) ?>" target="_blank" rel="noopener">مسیریابی</a><?php endif; ?>
      </div>
    <?php elseif ($locSearch !== null): ?>
      <div class="lx-location__actions">
        <a class="lx-btn lx-btn--ghost-dark" href="<?= e(clean_url($locSearch)) ?>" target="_blank" rel="noopener">مشاهده در نقشه</a>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<footer class="lx-footer">
  <div class="lx-wrap lx-footer__inner">
    <span class="lx-brand__name"><?= e($brandName) ?></span>
    <span class="lx-footer__copy">© <?= $year ?> <?= e($brandName) ?> — <?= e($S['brand_latin']) ?></span>
    <nav class="lx-footer__nav" aria-label="پیوندهای پایین صفحه">
      <a href="listings.php">فایل‌های املاک</a>
      <a data-program-entry href="<?= e(PROGRAM_ENTRY) ?>"><?= e($appLabel) ?></a>
    </nav>
  </div>
</footer>

</body>
</html>
