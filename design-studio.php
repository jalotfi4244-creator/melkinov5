<?php require_once __DIR__ . '/config.php';
require_once __DIR__ . '/design-studio-lib.php';
require_once __DIR__ . '/map-markers.php';
$dsLayouts = melkinoStudioLayouts();
$dsCssV = (int) @filemtime(__DIR__ . '/design-studio.css');
$dsLayV = (int) @filemtime(__DIR__ . '/mk-card-layouts.css');
$dsJsV = (int) @filemtime(__DIR__ . '/design-studio.js');
$adCssV = (int) @filemtime(__DIR__ . '/ad-cards.css');
?>
<link rel="stylesheet" href="ad-cards.css?v=<?= $adCssV ?>">
<link rel="stylesheet" href="mk-card-layouts-100.css?v=<?= (int) @filemtime(__DIR__ . '/mk-card-layouts-100.css') ?>">
<link rel="stylesheet" href="map.css?v=<?= (int) @filemtime(__DIR__ . '/map.css') ?>">
<link rel="stylesheet" href="design-studio.css?v=<?= $dsCssV ?>">
<div class="ds-app" id="dsApp" dir="rtl">
    <div class="ds-bar">
        <div class="ds-bar-row">
            <div class="ds-bar-title">استودیو طراحی ملکینو <small>ظاهر سایت و کارت‌ها، بدون کدنویسی</small></div>
            <span class="ds-status" id="dsStatus">در حال بارگذاری…</span>
        </div>
        <div class="ds-bar-row ds-bar-actions">
            <select class="ds-select" id="dsDeviceSel" aria-label="دستگاه">
                <option value="mobile">موبایل</option>
                <option value="tablet">تبلت</option>
                <option value="desktop">دسکتاپ</option>
            </select>
            <select class="ds-select" id="dsModeSel" aria-label="حالت رنگ">
                <option value="light">روشن</option>
                <option value="dark">تیره</option>
                <option value="system">سیستم</option>
            </select>
            <button type="button" class="ds-btn ghost" id="dsUndoBtn">↶ بازگشت</button>
            <button type="button" class="ds-btn ghost" id="dsRedoBtn">↷ جلو</button>
            <button type="button" class="ds-btn" id="dsWizardBtn">طراحی سریع</button>
            <button type="button" class="ds-btn" id="dsSaveBtn">ذخیره و اعمال روی سایت</button>
            <button type="button" class="ds-btn gold" id="dsPublishBtn">انتشار</button>
        </div>
    </div>
    <div class="ds-scrim" id="dsScrim"></div>
    <div class="ds-body">
        <aside class="ds-nav" id="dsNav">
            <div class="ds-drawer-head">
                <strong>منوها</strong>
                <button type="button" class="ds-drawer-close" data-close-drawer="nav" aria-label="بستن منو">بستن ✕</button>
            </div>
            <button type="button" class="is-on" data-panel="cards">🃏 کارت‌ها</button>
            <button type="button" data-panel="theme">🎨 تم</button>
            <button type="button" data-panel="images">🖼 تصاویر</button>
            <button type="button" data-panel="text">🔤 متن</button>
            <button type="button" data-panel="effects">✨ افکت‌ها</button>
            <button type="button" data-panel="layout">📐 چیدمان</button>
            <button type="button" data-panel="map">🗺 نقشه و مارکر</button>
            <button type="button" data-panel="mode">🌙 حالت نمایش</button>
            <button type="button" data-panel="advanced">⚙ پیشرفته</button>
        </aside>
        <main class="ds-stage">
            <div class="ds-stage-tools">
                <button type="button" class="ds-drawer-btn ds-chip" id="dsOpenNav">منوها</button>
                <button type="button" class="ds-chip is-on" data-page="home">خانه</button>
                <button type="button" class="ds-chip" data-page="listing">لیست</button>
                <button type="button" class="ds-chip" data-page="details">جزئیات</button>
                <button type="button" class="ds-chip" data-page="dashboard">داشبورد</button>
                <button type="button" class="ds-chip" data-page="map">نقشه</button>
                <button type="button" class="ds-chip" data-size="s">کوچک</button>
                <button type="button" class="ds-chip is-on" data-size="m">متوسط</button>
                <button type="button" class="ds-chip" data-size="l">بزرگ</button>
                <button type="button" class="ds-chip" id="dsCompareBtn">مقایسه مدل‌ها</button>
                <button type="button" class="ds-chip" id="dsBaBtn">قبل / بعد</button>
                <button type="button" class="ds-drawer-btn ds-chip" id="dsOpenSide">تنظیمات</button>
            </div>
            <div class="ds-browser" id="dsBrowser" data-device="mobile">
                <div class="ds-chrome"><span class="ds-chrome-dot"></span> 🔒 melkino.ir · پیش‌نمایش زنده</div>
                <div class="ds-page" id="dsPage" data-theme="light"></div>
            </div>
        </main>
        <aside class="ds-side" id="dsSide">
            <div class="ds-drawer-head">
                <strong>تنظیمات</strong>
                <button type="button" class="ds-drawer-close" data-close-drawer="side" aria-label="بستن تنظیمات">بستن ✕</button>
            </div>
            <div id="dsSideBody"></div>
        </aside>
    </div>
</div>
<div class="ds-modal" id="dsModal"></div>
<script>
window.MELKINO_STUDIO_CATALOG = <?= json_encode($dsLayouts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.MELKINO_MARKER_SHAPES = <?= json_encode(melkinoMarkerShapes(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.MELKINO_MAPCARD_SKINS = <?= json_encode(melkinoMapCardSkins(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="map-markers.js?v=<?= (int) @filemtime(__DIR__ . '/map-markers.js') ?>"></script>
