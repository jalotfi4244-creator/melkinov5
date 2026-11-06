<?php
/**
 * استودیوی استوری ملکینو — بخش پنل مدیریت (فقط با نشست ادمین بارگذاری می‌شود)
 * منطق در assets/js/ig-studio*.js و API در ig-studio-api.php است.
 */
if (!defined('MELKINO_ADMIN_PANEL_CONTEXT') && empty($_SESSION['is_admin'])) {
    return;
}
?>
<style>
#igsRoot{direction:rtl;text-align:right;color:#1d2430;background:#f4f5f7;border-radius:14px;padding:14px;font-family:Tahoma,"Segoe UI",sans-serif;font-size:13px;line-height:1.6;box-sizing:border-box}
#igsRoot *,#igsRoot *::before,#igsRoot *::after{box-sizing:border-box}
#igsRoot h3{margin:0 0 8px;font-size:14px;color:#1d2430}
#igsRoot .igs-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-bottom:12px}
#igsRoot .igs-head h2{margin:0;font-size:17px}
#igsRoot .igs-sub{color:#5b6573;font-size:12px}
#igsRoot .igs-status{min-height:22px;padding:4px 10px;border-radius:8px;background:#e9edf3;color:#334;font-size:12.5px;max-width:100%}
#igsRoot .igs-status.ok{background:#e4f6ea;color:#0d5a2a}
#igsRoot .igs-status.warn{background:#fff4d6;color:#7a5200}
#igsRoot .igs-status.err{background:#fde3e3;color:#8a1111}
#igsRoot .igs-grid{display:grid;grid-template-columns:300px minmax(380px,1fr) 340px;gap:12px;align-items:start}
@media (max-width:1240px){#igsRoot .igs-grid{grid-template-columns:280px minmax(0,1fr)}#igsRoot .igs-right{grid-column:1 / -1}}
@media (max-width:820px){#igsRoot .igs-grid{grid-template-columns:1fr}}
#igsRoot .igs-col{background:#fff;border:1px solid #dde2ea;border-radius:12px;padding:12px;min-width:0}
#igsRoot .igs-start{background:#fff;border:1px solid #dde2ea;border-radius:14px;padding:16px;margin-bottom:12px}
#igsRoot .igs-start-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
#igsRoot .igs-start-cards{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
#igsRoot .igs-card-big{all:unset;cursor:pointer;display:flex;flex-direction:column;gap:6px;padding:18px;border:2px solid #dde2ea;border-radius:14px;background:#fff;text-align:right;min-height:118px;box-sizing:border-box}
#igsRoot .igs-card-big:hover,#igsRoot .igs-card-big:focus-visible{border-color:#2b6cb0;box-shadow:0 0 0 3px rgba(43,108,176,.15)}
#igsRoot .igs-card-big b{font-size:16px;color:#1d2430}
#igsRoot .igs-card-big span{font-size:12.5px;color:#5b6676}
#igsRoot .igs-card-big .igs-card-ico{font-size:22px;color:#2b6cb0}
#igsRoot .igs-sizes{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:6px 0 12px}
#igsRoot .igs-sizes button{border:1px solid #cfd6e0;border-radius:10px;background:#fff;padding:10px;cursor:pointer;font:inherit;text-align:right}
#igsRoot .igs-sizes button:hover{border-color:#2b6cb0}
#igsRoot .igs-sizes small{display:block;color:#5b6676;font-size:11.5px}
#igsRoot .igs-start-recent{max-height:220px;overflow:auto}
#igsRoot .igs-fav{border:0;background:none;font-size:16px;cursor:pointer;color:#b7791f;padding:0 4px}
#igsRoot.igs-mode-free .igs-ad-only{display:none!important}
#igsRoot:not(.igs-mode-free) .igs-free-only{display:none!important}
#igsRoot.igs-mode-free [data-ad-only]{display:none!important}
@media (max-width:700px){#igsRoot .igs-start-cards{grid-template-columns:1fr}}
#igsRoot input[type=text],#igsRoot input[type=search],#igsRoot input[type=number],#igsRoot select,#igsRoot textarea{width:100%;background:#fff;color:#1d2430;border:1px solid #c9d0db;border-radius:8px;padding:6px 8px;font:inherit;font-size:13px}
#igsRoot textarea{min-height:64px;resize:vertical}
#igsRoot input[type=color]{width:44px;height:30px;padding:0;border:1px solid #c9d0db;border-radius:6px;background:#fff;cursor:pointer}
#igsRoot input[type=range]{width:100%}
#igsRoot button{font:inherit;font-size:12.5px;border:1px solid #b9c2cf;background:#fff;color:#1d2430;border-radius:8px;padding:5px 10px;cursor:pointer}
#igsRoot button:hover{background:#eef2f7}
#igsRoot button:disabled{opacity:.45;cursor:not-allowed}
#igsRoot button.primary{background:#1f5fae;border-color:#1f5fae;color:#fff}
#igsRoot button.primary:hover{background:#184d91}
#igsRoot button.danger{color:#a11;border-color:#e0a3a3}
#igsRoot button.on{background:#1f5fae;color:#fff;border-color:#1f5fae}
#igsRoot .igs-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0}
#igsRoot .igs-field{margin:8px 0}
#igsRoot .igs-swatches{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
#igsRoot .igs-swatch{width:46px;height:46px;border-radius:12px;border:2px solid #d5dbe4;padding:0;cursor:pointer;position:relative;overflow:hidden;background:#fff}
#igsRoot .igs-swatch i{position:absolute;inset:0;display:block}
#igsRoot .igs-swatch i.b{clip-path:polygon(0 0,100% 0,0 100%)}
#igsRoot .igs-swatch i.a{clip-path:polygon(100% 0,100% 100%,0 100%)}
#igsRoot .igs-swatch.on{outline:3px solid #1f5fae;outline-offset:2px}
#igsRoot .igs-swatch:focus-visible{outline:3px solid #1f5fae;outline-offset:2px}
#igsRoot .igs-field>label,#igsRoot .igs-lbl{display:block;font-size:12px;color:#4a5563;margin-bottom:3px}
#igsRoot .igs-check{display:flex;gap:6px;align-items:center;font-size:12.5px}
#igsRoot .igs-muted{color:#6b7584;font-size:12px}
#igsRoot .igs-empty{padding:14px;border:1px dashed #b9c2cf;border-radius:10px;color:#5b6573;text-align:center;font-size:12.5px}
#igsRoot .igs-list{max-height:300px;overflow:auto;border:1px solid #e3e8ef;border-radius:10px}
#igsRoot .igs-item{display:block;width:100%;text-align:right;border:0;border-bottom:1px solid #eef1f5;border-radius:0;padding:8px 10px;background:#fff}
#igsRoot .igs-item:hover{background:#f2f6fb}
#igsRoot .igs-item.sel{background:#e3eefc}
#igsRoot .igs-item b{display:block;font-size:12.5px;color:#1d2430;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#igsRoot .igs-badge{display:inline-block;font-size:11px;padding:0 6px;border-radius:6px;background:#e9edf3;color:#334;margin-left:4px}
#igsRoot .igs-badge.pub{background:#e4f6ea;color:#0d5a2a}
#igsRoot .igs-badge.warn{background:#fff4d6;color:#7a5200}
#igsRoot .igs-gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:620px;overflow:auto;padding:2px}
#igsRoot .igs-card{position:relative;border:2px solid #dde2ea;border-radius:10px;background:#fff;padding:5px;cursor:pointer;text-align:center}
#igsRoot .igs-card.sel{border-color:#1f5fae;box-shadow:0 0 0 2px #cfe2fb}
#igsRoot .igs-card canvas{width:100%;height:auto;display:block;border-radius:6px;background:#ddd}
#igsRoot .igs-card .nm{font-size:11.5px;margin-top:4px;color:#2b3442;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#igsRoot .igs-star{position:absolute;top:6px;left:6px;border:0;background:rgba(255,255,255,.9);border-radius:50%;width:26px;height:26px;padding:0;line-height:24px;font-size:14px;color:#b7860b}
#igsRoot .igs-toolbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px}
#igsRoot .igs-stagewrap{background:#dfe4ec;border-radius:12px;padding:14px;display:flex;justify-content:center;overflow:auto;min-height:420px}
#igsRoot .igs-stage{position:relative;width:420px;max-width:100%;user-select:none;touch-action:none;background:#fff;box-shadow:0 6px 24px rgba(20,30,50,.25)}
#igsRoot .igs-stage canvas{display:block;width:100%;height:auto}
#igsRoot .igs-ov{position:absolute;left:0;top:0;width:100%;height:100%;pointer-events:none;overflow:visible}
#igsRoot .igs-ov [data-h]{pointer-events:auto}
#igsRoot .igs-guide{position:absolute;background:#ff3b7f;pointer-events:none;display:none}
#igsRoot .igs-safe{position:absolute;left:0;right:0;pointer-events:none;background:rgba(255,40,40,.13);display:none}
#igsRoot .igs-warn{margin-top:8px;font-size:12.5px}
#igsRoot .igs-warn li{margin:2px 0}
#igsRoot .igs-tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px;border-bottom:1px solid #e3e8ef;padding-bottom:6px}
#igsRoot .igs-tabs button{border-radius:8px 8px 0 0;border-bottom:0}
#igsRoot .igs-tabs button.on{background:#1f5fae;color:#fff}
#igsRoot .igs-pane{display:none}
#igsRoot .igs-pane.on{display:block}
#igsRoot .igs-thumbs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}
#igsRoot .igs-thumbs button{padding:0;border:2px solid transparent;border-radius:8px;overflow:hidden;background:#f4f6fa;cursor:pointer;line-height:0}
#igsRoot .igs-thumbs button.on{border-color:#1f5fae;box-shadow:0 0 0 2px #cfe0f7}
#igsRoot .igs-thumbs img{width:100%;aspect-ratio:9/16;object-fit:cover;display:block}
#igsRoot .igs-group{display:none;border-top:1px solid #eef1f5;margin-top:8px;padding-top:6px}
#igsRoot .igs-group.on{display:block}
#igsRoot .igs-two{display:grid;grid-template-columns:1fr 1fr;gap:6px}
#igsRoot .igs-seg{display:flex;gap:4px}
#igsRoot .igs-seg button{flex:1}
#igsRoot .igs-tiny{font-size:11.5px;padding:2px 6px}
#igsRoot .igs-pick{display:flex;gap:6px;align-items:center}
#igsRoot .igs-pick input[type=range]{flex:1}
#igsRoot .igs-pick output{min-width:44px;text-align:center;font-size:12px;color:#334}
#igsRoot .igs-layer{display:flex;gap:4px;align-items:center;padding:5px 6px;border-bottom:1px solid #eef1f5;background:#fff}
#igsRoot .igs-layer.sel{background:#e3eefc}
#igsRoot .igs-grip{cursor:grab;color:#8a94a3;padding:0 2px;user-select:none}
#igsRoot .igs-layer .nm{flex:1;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px}
#igsRoot .igs-layer .nm.off{color:#9aa3b0;text-decoration:line-through}
#igsRoot .igs-layer button{padding:1px 6px;font-size:11.5px}
#igsRoot .igs-proj{display:flex;gap:4px;align-items:center;padding:6px;border-bottom:1px solid #eef1f5}
#igsRoot .igs-proj .nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px}
#igsRoot .igs-proj button{padding:2px 6px;font-size:11.5px}
#igsRoot .igs-kv{font-size:12.5px;margin:3px 0}
#igsRoot .igs-kv b{color:#2b3442}
</style>

<div id="igsRoot" class="igs-root">
  <div class="igs-head">
    <div>
      <h2>استودیوی طراحی ملکینو</h2>
      <div class="igs-sub">ساخت استوری ۱۰۸۰×۱۹۲۰ از آگهی‌های واقعی، با قالب‌های فارسی و خروجی PNG/JPEG. انتشار مستقیم در اینستاگرام انجام نمی‌شود؛ فایل دانلود می‌شود.</div>
    </div>
    <div id="igsStatus" class="igs-status" role="status">در حال بارگذاری استودیو…</div>
  </div>

  <div id="igsStart" class="igs-start" style="display:none" role="dialog" aria-label="صفحه‌ی شروع استودیو">
    <div class="igs-start-head">
      <h3 style="margin:0">چه چیزی می‌سازید؟</h3>
      <button type="button" id="igsStartClose" class="igs-tiny">ادامه‌ی کار فعلی</button>
    </div>
    <div class="igs-start-cards">
      <button type="button" class="igs-card-big" id="igsStartAd">
        <span class="igs-card-ico">▣</span>
        <b>ساخت محتوای تبلیغاتی از آگهی</b>
        <span>از عکس‌ها و مشخصات یک آگهی واقعی، با قالب‌های آماده</span>
      </button>
      <button type="button" class="igs-card-big" id="igsStartFree">
        <span class="igs-card-ico">+</span>
        <b>ساخت استوری و پست جدید</b>
        <span>از صفحه‌ی خالی؛ برای تبریک، اطلاعیه، تخفیف، نقل‌قول و هر طرح دلخواه. بدون نیاز به آگهی</span>
      </button>
    </div>
    <div id="igsStartFreeBox" style="display:none">
      <h3 style="margin:0 0 8px">اندازه‌ی طرح</h3>
      <div class="igs-sizes">
        <button type="button" data-fmt="story">استوری ۹:۱۶<small>۱۰۸۰×۱۹۲۰</small></button>
        <button type="button" data-fmt="post">پست ۴:۵<small>۱۰۸۰×۱۳۵۰</small></button>
        <button type="button" data-fmt="square">مربع<small>۱۰۸۰×۱۰۸۰</small></button>
        <button type="button" data-fmt="poster">پوستر<small>۱۰۸۰×۱۵۲۸</small></button>
        <button type="button" data-fmt="wide">بنر افقی<small>۱۲۰۰×۶۲۸</small></button>
      </div>
    </div>
    <h3 style="margin:8px 0">پروژه‌های اخیر</h3>
    <div class="igs-list igs-start-recent" id="igsStartRecent"><div class="igs-empty">در حال بارگذاری…</div></div>
  </div>

  <div class="igs-grid">
    <!-- ستون چپ: آگهی و قالب (فقط حالت آگهی) -->
    <div class="igs-col igs-ad-only">
      <h3>۱. آگهی واقعی</h3>
      <input type="search" id="igsAdQ" placeholder="جستجوی عنوان، موقعیت یا کد آگهی">
      <div class="igs-muted" id="igsAdCount" style="margin:6px 0"></div>
      <div class="igs-list" id="igsAdList"><div class="igs-empty">در حال دریافت آگهی‌ها…</div></div>
      <div class="igs-row"><button type="button" id="igsAdMore" class="igs-tiny" style="display:none">نمایش بیشتر</button></div>

      <h3 style="margin-top:14px">۲. قالب</h3>
      <select id="igsCat" aria-label="دسته‌بندی قالب"></select>
      <div class="igs-muted" id="igsTplCount" style="margin:6px 0"></div>
      <div class="igs-row" style="margin:6px 0"><input type="text" id="igsMyTplName" maxlength="120" placeholder="نام قالب، مثلاً: قالب ویلای من" style="flex:1"><button type="button" class="primary igs-tiny" id="igsMyTplSave">ذخیره به‌عنوان قالب</button></div>
      <div class="igs-muted" id="igsMyTplMsg" style="margin-bottom:6px"></div>
      <div class="igs-gallery" id="igsGallery"></div>
    </div>

    <!-- ستون قالب‌های عمومی (فقط حالت طراحی آزاد) -->
    <div class="igs-col igs-free-only">
      <h3>۱. قالب‌های عمومی</h3>
      <p class="igs-muted" style="margin:0 0 6px">یک قالب را انتخاب کنید؛ همه‌ی متن‌ها و عناصر بعداً قابل ویرایش‌اند.</p>
      <div class="igs-row" style="margin:0 0 6px"><input type="search" id="igsFreeQ" placeholder="جستجوی قالب یا دسته…" style="flex:1" aria-label="جستجوی قالب"><select id="igsFreeCat" aria-label="دسته‌بندی قالب‌های عمومی"></select></div>
      <div class="igs-muted" id="igsFreeCount" style="margin:6px 0"></div>
      <div class="igs-gallery" id="igsFreeGallery"></div>
      <h3 style="margin-top:12px">ذخیره به‌عنوان قالب شخصی</h3>
      <div class="igs-row" style="margin:6px 0"><input type="text" id="igsFreeTplName" maxlength="120" placeholder="نام قالب، مثلاً: تبریک عید" style="flex:1"><button type="button" class="primary igs-tiny" id="igsFreeTplSave">ذخیره</button></div>
      <div class="igs-muted" id="igsFreeTplMsg"></div>
    </div>

    <!-- ستون وسط: پیش‌نمایش و خروجی -->
    <div class="igs-col" style="padding:10px">
      <div class="igs-toolbar">
        <button type="button" id="igsUndo" title="بازگشت (Ctrl+Z)">↶ بازگشت</button>
        <button type="button" id="igsRedo" title="انجام دوباره (Ctrl+Y)">↷ انجام دوباره</button>
        <button type="button" id="igsGoStart" title="برگشت به صفحه‌ی شروع">⌂ شروع</button>
        <label class="igs-check">فرمت <select id="igsFormat" aria-label="فرمت خروجی"><option value="story">استوری ۹:۱۶ (۱۰۸۰×۱۹۲۰)</option><option value="post">پست کاروسلی ۴:۵ (۱۰۸۰×۱۳۵۰، ۴ اسلاید)</option><option value="square">مربع (۱۰۸۰×۱۰۸۰) — طراحی آزاد</option><option value="poster">پوستر (۱۰۸۰×۱۵۲۸) — طراحی آزاد</option><option value="wide">بنر افقی (۱۲۰۰×۶۲۸) — طراحی آزاد</option></select></label>
        <label class="igs-check"><input type="checkbox" id="igsSafeOn" checked> ناحیه امن استوری</label>
        <label class="igs-check"><input type="checkbox" id="igsGuideOn" checked> راهنماهای چیدمان</label>
        <span class="igs-pick" style="margin-inline-start:auto;min-width:200px">
          <span class="igs-muted">بزرگنمایی</span>
          <input type="range" id="igsZoom" min="0.6" max="1.4" step="0.05" value="1">
          <output id="igsZoomOut">100%</output>
        </span>
      </div>
      <div class="igs-stagewrap">
        <div class="igs-stage" id="igsStage">
          <canvas id="igsCanvas" width="1080" height="1920" aria-label="پیش‌نمایش طرح"></canvas>
          <div class="igs-safe" id="igsSafeTop" style="top:0;height:14%"></div>
          <div class="igs-safe" id="igsSafeBottom" style="bottom:0;height:17%"></div>
          <div class="igs-guide" id="igsGuideV" style="top:0;bottom:0;width:2px"></div>
          <div class="igs-guide" id="igsGuideH" style="left:0;right:0;height:2px"></div>
          <svg id="igsOv" class="igs-ov" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"></svg>
        </div>
      </div>
      <ul class="igs-warn" id="igsWarn"></ul>

      <div class="igs-row" style="margin-top:10px">
        <label class="igs-muted">قالب
          <select id="igsExFormat">
            <option value="png">PNG (بدون افت کیفیت)</option>
            <option value="jpeg">JPEG (سازگار با همه)</option>
            <option value="webp">WebP (حجم کمتر)</option>
            <option value="pdf">PDF (هر طرح یک صفحه)</option>
            <option value="zip">ZIP (همه‌ی طرح‌ها، PNG)</option>
          </select>
        </label>
        <label class="igs-muted">کیفیت
          <select id="igsExQuality">
            <option value="high">بالا</option>
            <option value="balanced">متوسط</option>
            <option value="small">حجم کم</option>
          </select>
        </label>
        <button type="button" class="primary" id="igsExDownload">دانلود ۱۰۸۰×۱۹۲۰</button>
        <span class="igs-muted" id="igsExHint"></span>
      </div>
    </div>

    <!-- ستون راست: ویرایش، لایه‌ها، پروژه، برند، آرشیو -->
    <div class="igs-col igs-right">
      <div class="igs-tabs" role="tablist">
        <button type="button" data-tab="edit" class="on">ویرایش</button>
        <button type="button" data-tab="photo">عکس</button>
        <button type="button" data-tab="add">افزودن</button>
        <button type="button" data-tab="layers">لایه‌ها</button>
        <button type="button" data-tab="project">پروژه</button>
        <button type="button" data-tab="brand">برند</button>
        <button type="button" data-tab="fonts">فونت‌ها</button>
        <button type="button" data-tab="archive">آرشیو</button>
      </div>

      <!-- ویرایش -->
      <div class="igs-pane on" data-pane="edit">
        <div class="igs-field">
          <label>پالت رنگی <span id="igsThemeName" style="color:#1f5fae;font-weight:700"></span></label>
          <div class="igs-swatches" id="igsSwatches" role="radiogroup" aria-label="پالت رنگی"></div>
          <select id="igsTheme" hidden aria-hidden="true" tabindex="-1"></select>
        </div>
        <div class="igs-field">
          <label for="igsGlobalFont">فونت متن‌ها</label>
          <div class="igs-row" style="margin:0">
            <select id="igsGlobalFont" style="flex:1"></select>
            <button type="button" id="igsApplyFont" class="igs-tiny" title="این فونت را به همه متن‌ها می‌دهد">اعمال به همه</button>
          </div>
        </div>
        <div class="igs-muted" style="margin-top:4px">برای انتخاب المان، روی پیش‌نمایش کلیک کنید یا از تب «لایه‌ها» استفاده کنید. Shift+کلیک برای چند انتخاب؛ گروه‌ها با یک کلیک کل گروه را انتخاب می‌کنند. تغییر قالب، جای‌گذاری دستی را ریست می‌کند؛ متن‌ها و تنظیمات حفظ می‌شوند.</div>

        <div id="igsSelBox" style="display:none;border-top:2px solid #e3e8ef;margin-top:12px;padding-top:8px">
          <div class="igs-row" style="justify-content:space-between">
            <b id="igsSelName"></b>
            <span class="igs-badge" id="igsSelType"></span>
          </div>
          <div class="igs-two">
            <div class="igs-field"><label class="igs-lbl" for="igsX">X</label><input type="number" id="igsX" step="1"></div>
            <div class="igs-field"><label class="igs-lbl" for="igsY">Y</label><input type="number" id="igsY" step="1"></div>
            <div class="igs-field"><label class="igs-lbl" for="igsW">عرض</label><input type="number" id="igsW" min="10" step="1"></div>
            <div class="igs-field"><label class="igs-lbl" for="igsH">ارتفاع</label><input type="number" id="igsH" min="10" step="1"></div>
          </div>
          <div class="igs-two">
            <div class="igs-field"><label class="igs-lbl" for="igsRot">چرخش (درجه)</label><input type="number" id="igsRot" min="-180" max="180" step="1"></div>
            <div class="igs-field"><label class="igs-lbl" for="igsOpacity">شفافیت</label><input type="range" id="igsOpacity" min="0" max="1" step="0.05"></div>
          </div>
          <div class="igs-row">
            <label class="igs-check"><input type="checkbox" id="igsHide"> پنهان</label>
            <label class="igs-check"><input type="checkbox" id="igsLock"> قفل</label>
          </div>

          <!-- متن -->
          <div class="igs-group" data-g="text">
            <div class="igs-field">
              <label class="igs-lbl" for="igsTxt">متن (ویرایش دستی)</label>
              <textarea id="igsTxt" rows="2"></textarea>
              <div class="igs-row" style="margin:4px 0 0"><button type="button" class="igs-tiny" id="igsTxtReset">بازگشت به داده‌ی آگهی</button><span class="igs-muted" id="igsTxtHint"></span></div>
            </div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsFont">فونت</label><select id="igsFont"></select></div>
              <div class="igs-field"><label class="igs-lbl" for="igsWeight">ضخامت</label><select id="igsWeight"></select></div>
            </div>
            <div class="igs-field"><label class="igs-lbl">اندازه</label>
              <div class="igs-pick"><input type="range" id="igsSize" min="16" max="200" step="1"><output id="igsSizeOut"></output></div>
            </div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsMaxLines">حداکثر خط</label><input type="number" id="igsMaxLines" min="1" max="12" step="1"></div>
              <div class="igs-field"><label class="igs-lbl">تراز</label>
                <div class="igs-seg" id="igsAlign"><button type="button" data-align="right">راست</button><button type="button" data-align="center">وسط</button><button type="button" data-align="left">چپ</button></div>
              </div>
            </div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsColor">رنگ</label><input type="color" id="igsColor"></div>
              <div class="igs-field"><label class="igs-lbl">عمودی</label>
                <div class="igs-seg" id="igsValign"><button type="button" data-valign="top">بالا</button><button type="button" data-valign="middle">وسط</button><button type="button" data-valign="bottom">پایین</button></div>
              </div>
            </div>
            <div class="igs-row">
              <label class="igs-check"><input type="checkbox" id="igsBgOn"> زمینه‌ی برچسب</label>
              <input type="color" id="igsBgColor" title="رنگ زمینه">
              <label class="igs-check"><input type="checkbox" id="igsShadow"> سایه</label>
            </div>
          </div>

          <!-- عکس -->
          <div class="igs-group" data-g="photo">
            <div class="igs-field"><label class="igs-lbl" for="igsPhIdx">عکس آگهی</label><select id="igsPhIdx"></select></div>
            <div class="igs-field"><label class="igs-lbl">بزرگنمایی</label><div class="igs-pick"><input type="range" id="igsZoom2" min="1" max="3" step="0.01"><output id="igsZoom2Out"></output></div></div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsFilter">فیلتر عکس</label><select id="igsFilter"><option value="">بدون فیلتر</option><option value="bright">روشن‌تر</option><option value="contrast">کنتراست بیشتر</option><option value="warm">گرم</option><option value="cool">سرد</option><option value="sepia">قدیمی</option><option value="grayscale">سیاه‌وسفید</option></select></div>
              <div class="igs-field"><label class="igs-lbl">کادربندی افقی</label><input type="range" id="igsFx" min="0" max="1" step="0.01"></div>
              <div class="igs-field"><label class="igs-lbl">کادربندی عمودی</label><input type="range" id="igsFy" min="0" max="1" step="0.01"></div>
              <div class="igs-field"><button type="button" id="igsCropReset" class="igs-tiny">بازنشانی برش</button></div>
            </div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsShape">شکل</label>
                <select id="igsShape"><option value="rect">مستطیل</option><option value="round">گوشه‌گرد</option><option value="arch">کمان</option><option value="circle">دایره/بیضی</option></select></div>
              <div class="igs-field"><label class="igs-lbl" for="igsRadius">گردی گوشه</label><input type="number" id="igsRadius" min="0" max="540" step="1"></div>
            </div>
          </div>

          <!-- کادر/خط -->
          <div class="igs-group" data-g="rect">
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsFill">رنگ پرکردن</label><input type="color" id="igsFill"></div>
              <div class="igs-field"><label class="igs-check"><input type="checkbox" id="igsFillOn"> پرشده</label></div>
            </div>
          </div>

          <!-- مرز (عکس/کادر) -->
          <div class="igs-group" data-g="stroke">
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsStroke">رنگ مرز</label><input type="color" id="igsStroke"></div>
              <div class="igs-field"><label class="igs-lbl" for="igsStrokeW">ضخامت مرز</label><input type="number" id="igsStrokeW" min="0" max="40" step="1"></div>
            </div>
            <label class="igs-check"><input type="checkbox" id="igsStrokeOn"> مرز فعال</label>
          </div>

          <!-- جدول مشخصات و فهرست امکانات -->
          <div class="igs-group" data-g="qr">
            <div class="igs-field"><label class="igs-lbl" for="igsQrValue">متن یا لینک کد QR</label><input type="text" id="igsQrValue" maxlength="400" dir="ltr"></div>
            <div class="igs-field"><label class="igs-lbl" for="igsQrFg">رنگ کد</label><input type="color" id="igsQrFg"></div>
          </div>
          <div class="igs-group" data-g="sticker">
            <div class="igs-field"><label class="igs-lbl" for="igsStickerKind">شکل استیکر</label><select id="igsStickerKind"><option value="star">ستاره</option><option value="burst">انفجار</option><option value="heart">قلب</option><option value="badge">نشان دایره‌ای</option><option value="ribbon">روبان</option><option value="arrow">فلش</option><option value="bubble-l">حباب چپ</option><option value="bubble-r">حباب راست</option><option value="bubble-box">کادر گرد</option></select></div>
          </div>
          <div class="igs-group" data-g="list">
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsLayout">چیدمان مشخصات</label>
                <select id="igsLayout"><option value="list">فهرست</option><option value="grid">شبکه‌ای</option></select></div>
              <div class="igs-field"><label class="igs-lbl" for="igsCols">ستون‌ها (شبکه)</label><input type="number" id="igsCols" min="1" max="6" step="1"></div>
            </div>
            <div class="igs-two">
              <div class="igs-field"><label class="igs-lbl" for="igsListSize">اندازه</label><input type="number" id="igsListSize" min="10" max="200" step="1"></div>
              <div class="igs-field"><label class="igs-lbl" for="igsBgChip">رنگ تراشه/زمینه</label><input type="color" id="igsBgChip"></div>
            </div>
          </div>

          <div class="igs-field" id="igsFieldWrap" style="display:none"><label class="igs-lbl">موارد نمایش (فقط انتخاب‌شده‌ها در استوری می‌آیند)</label>
            <div id="igsFieldPick" style="display:grid;grid-template-columns:1fr 1fr;gap:4px 10px"></div>
            <div class="igs-row" style="margin-top:6px"><button type="button" class="igs-tiny" id="igsFieldAll">همه را روشن کن</button><button type="button" class="igs-tiny" id="igsFieldNone">همه را خاموش کن</button></div>
            <label class="igs-check" id="igsIconsWrap" style="margin-top:6px;display:none"><input type="checkbox" id="igsIcons"> نمایش با آیکون</label>
          </div>
          <label class="igs-check" id="igsFieldLabelWrap" style="display:none;margin-top:6px"><input type="checkbox" id="igsFieldLabel"> نمایش عنوان فیلد (مثلاً «موقعیت: …»)</label>
          <div class="igs-field" id="igsFieldRenameWrap" style="display:none;margin-top:6px"><label class="igs-lbl" for="igsFieldRename">عنوان این فیلد در این استوری</label><input type="text" id="igsFieldRename" maxlength="60" placeholder="پیش‌فرض: عنوان خود آگهی"></div>
          <div class="igs-row" style="margin-top:10px">
            <button type="button" id="igsDel" class="danger igs-tiny" style="display:none">حذف این کادر/متن</button>
            <button type="button" id="igsReset" class="igs-tiny">بازنشانی این المان</button>
          </div>
        </div>
      </div>

      <!-- عکس: انتخاب دستی عکس و عکس پیش‌فرض آگهی -->
      <div class="igs-pane" data-pane="photo">
        <div class="igs-field">
          <b>عکس پیش‌فرض این آگهی</b>
          <div class="igs-muted" id="igsDefPhotoState"></div>
          <div class="igs-row" style="margin-top:6px">
            <button type="button" id="igsDefSave" class="primary igs-tiny">ذخیره‌ی عکس انتخاب‌شده به‌عنوان پیش‌فرض</button>
            <button type="button" id="igsDefClear" class="danger igs-tiny">حذف پیش‌فرض</button>
          </div>
          <div class="igs-muted" style="margin-top:6px">روی هر عکس کلیک کنید تا عکس عکس‌دار انتخاب‌شده در پیش‌نمایش عوض شود. «ذخیره» همان عکس را برای این آگهی نگه می‌دارد و دفعه‌ی بعد خودکار انتخاب می‌شود.</div>
        </div>
        <h3 style="margin-top:12px">عکس‌های این آگهی</h3>
        <div class="igs-thumbs" id="igsThumbsAd"></div>
        <h3 style="margin-top:12px">پس‌زمینه‌ی عمومی <span class="igs-muted" id="igsBgCount"></span></h3>
        <div class="igs-thumbs" id="igsThumbsBg"></div>
        <div class="igs-row" style="margin-top:6px"><button type="button" id="igsBgMore" class="igs-tiny" style="display:none">نمایش بیشتر</button></div>
      </div>

      <!-- افزودن: المان‌ها و فیلدهای آگهی -->
      <div class="igs-pane" data-pane="add">
        <div class="igs-row">
          <button type="button" id="igsAddText">+ متن آزاد</button>
          <button type="button" id="igsAddRect">+ کادر رنگی</button>
          <button type="button" id="igsAddLine">+ خط</button>
          <button type="button" id="igsAddWatermark">+ واترمارک</button>
          <button type="button" id="igsAddPhotoBg">+ عکس پس‌زمینه</button>
          <button type="button" id="igsAddPhoto">+ عکس</button>
          <button type="button" id="igsAddSpecs" data-ad-only>+ جدول مشخصات</button>
          <button type="button" id="igsAddChips" data-ad-only>+ فهرست امکانات</button>
          <button type="button" id="igsAddLogo">+ لوگو</button>
          <button type="button" id="igsAddQr">+ کد QR</button>
          <button type="button" id="igsAddBrandInfo">+ اطلاعات برند</button>
          <select id="igsFieldSel" style="max-width:260px" title="همه‌ی فیلدهای آگهی"></select>
          <button type="button" id="igsAddField" data-ad-only>+ فیلد با عنوان</button>
          <button type="button" id="igsAddFieldPlain" data-ad-only>+ فیلد بدون عنوان</button>
          <button type="button" id="igsResetAll" class="danger igs-tiny">بازنشانی چیدمان</button>
        </div>
        <div class="igs-row" style="margin-top:6px"><span class="igs-muted">استیکر:</span>
          <button type="button" class="igs-tiny" data-stk="star">★ ستاره</button>
          <button type="button" class="igs-tiny" data-stk="burst">✸ انفجار</button>
          <button type="button" class="igs-tiny" data-stk="heart">♥ قلب</button>
          <button type="button" class="igs-tiny" data-stk="badge">● نشان</button>
          <button type="button" class="igs-tiny" data-stk="ribbon">روبان</button>
          <button type="button" class="igs-tiny" data-stk="arrow">فلش</button>
        </div>
        <div class="igs-row" style="margin-top:6px"><span class="igs-muted">حباب (با یک کلیک، متن قابل ویرایش):</span>
          <button type="button" class="igs-tiny" data-bub="bubble-l">حباب چپ</button>
          <button type="button" class="igs-tiny" data-bub="bubble-r">حباب راست</button>
          <button type="button" class="igs-tiny" data-bub="bubble-box">کادر گرد</button>
        </div>
        <div id="igsFieldChecks" class="igs-field-checks" style="margin-top:8px"></div>
      </div>

      <!-- فونت‌های اختصاصی (فقط ادمین) -->
      <div class="igs-pane" data-pane="fonts">
        <div class="igs-muted" style="margin-bottom:8px">فونت اختصاصی فقط برای ادمین قابل آپلود است. فایل‌ها TTF، OTF یا WOFF هستند (حداکثر ۵ مگابایت و ۸ فونت). WOFF2 فعلاً پذیرفته نمی‌شود، چون رمزگشای Brotli روی سرور نیست. فونت‌های پیش‌فرض استودیو همیشه در دسترس‌اند.</div>
        <div class="igs-list" id="igsFontList" style="margin-bottom:10px"></div>
        <h3>افزودن فونت</h3>
        <div class="igs-field"><label class="igs-lbl" for="igsFontFile">فایل فونت</label><input type="file" id="igsFontFile" accept=".ttf,.otf,.woff"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsFontType">نوع مجوز</label>
          <select id="igsFontType"><option value="">— انتخاب کنید —</option><option value="own">مالکیت خودم</option><option value="purchased">خریداری‌شده (مجوز تجاری)</option><option value="free_commercial">رایگان با مجوز تجاری</option><option value="ofl">SIL OFL (آزاد)</option></select></div>
        <div class="igs-field"><label class="igs-lbl" for="igsFontNote">یادداشت مجوز (اختیاری، حداکثر ۲۰۰ نویسه)</label><input type="text" id="igsFontNote" maxlength="200"></div>
        <label class="igs-check" style="margin-top:6px"><input type="checkbox" id="igsFontConfirm"> تأیید می‌کنم که مجوز استفاده‌ی تجاری از این فونت را دارم.</label>
        <div class="igs-row" style="margin-top:8px"><button type="button" class="primary igs-tiny" id="igsFontUpload" disabled>بارگذاری فونت</button><span class="igs-muted" id="igsFontStatus"></span></div>
      </div>

      <!-- لایه‌ها -->
      <div class="igs-pane" data-pane="layers">
        <div class="igs-muted" style="margin-bottom:6px">بالاترین ردیف، روی‌ترین المان است. ردیف‌ها را بکشید یا با دکمه‌ها جابه‌جا کنید. Shift+کلیک برای چند انتخاب؛ Ctrl+G گروه‌بندی، Ctrl+D تکرار، Ctrl+C/V کپی و چسباندن، Delete حذف (فقط کادر و متن آزاد).</div>
        <div class="igs-row" style="gap:4px;flex-wrap:wrap;margin-bottom:8px">
          <button type="button" class="igs-tiny" id="igsLayFront" title="به بالاترین لایه">به جلو</button>
          <button type="button" class="igs-tiny" id="igsLayFwd" title="یک لایه جلوتر">یک لایه جلو</button>
          <button type="button" class="igs-tiny" id="igsLayBwd" title="یک لایه عقب‌تر">یک لایه عقب</button>
          <button type="button" class="igs-tiny" id="igsLayBack" title="به پایین‌ترین لایه">به عقب</button>
          <button type="button" class="igs-tiny" id="igsGroup" title="گروه‌بندی انتخاب‌شده‌ها (Ctrl+G)">گروه‌بندی</button>
          <button type="button" class="igs-tiny" id="igsUngroup" title="جدا کردن گروه (Ctrl+Shift+G)">جدا کردن گروه</button>
          <button type="button" class="igs-tiny" id="igsCopy" title="کپی (Ctrl+C)">کپی</button>
          <button type="button" class="igs-tiny" id="igsPaste" title="چسباندن (Ctrl+V)">چسباندن</button>
          <button type="button" class="igs-tiny" id="igsDup" title="تکرار (Ctrl+D)">تکرار</button>
        </div>
        <div class="igs-list" id="igsLayers" style="max-height:520px"></div>
      </div>

      <!-- پروژه -->
      <div class="igs-pane" data-pane="project">
        <div class="igs-field"><label class="igs-lbl" for="igsTitle">عنوان پروژه</label><input type="text" id="igsTitle" maxlength="200"></div>
        <div class="igs-row">
          <button type="button" class="primary" id="igsSave">ذخیره پروژه</button>
          <button type="button" id="igsSaveNew">ذخیره به‌عنوان جدید</button>
          <button type="button" id="igsNew">پروژه جدید</button>
        </div>
        <div class="igs-muted" id="igsPStatus"></div>
        <h3 style="margin-top:12px">پروژه‌های ذخیره‌شده</h3>
        <div class="igs-row"><input type="search" id="igsPQ" placeholder="جستجوی پروژه" style="flex:1"><label class="igs-check"><input type="checkbox" id="igsPFav"> فقط علاقه‌مندی</label></div>
        <div class="igs-list" id="igsPList"></div>
        <h3 style="margin-top:12px">نسخه‌های این پروژه</h3>
        <div class="igs-muted">هر ۲ دقیقه حداکثر یک نسخه‌ی قبلی ذخیره می‌شود (حداکثر ۳۰ نسخه).</div>
        <div class="igs-list" id="igsVersions"></div>
        <div style="margin-top:12px;border-top:2px solid #e3e8ef;padding-top:8px">
          <h3>خروجی گروهی (صف)</h3>
          <div class="igs-muted">پروژه‌ها را در فهرست بالا تیک بزنید و به صف اضافه کنید. خروجی‌ها یکی‌یکی ساخته و در آرشیو ثبت می‌شوند؛ دانلود جداگانه‌ی هر فایل را از آرشیو بگیرید.</div>
          <div class="igs-row" style="margin-top:6px;flex-wrap:wrap;gap:4px">
            <select id="igsBatchFormat" style="width:auto"><option value="png">PNG</option><option value="jpeg">JPEG</option><option value="webp">WebP</option></select>
            <button type="button" class="igs-tiny" id="igsBatchAdd">افزودن تیک‌خورده‌ها به صف</button>
            <button type="button" class="primary igs-tiny" id="igsBatchRun">شروع صف</button>
            <button type="button" class="igs-tiny" id="igsBatchStop">توقف پس از مورد جاری</button>
            <button type="button" class="igs-tiny" id="igsBatchClear">پاک کردن صف</button>
          </div>
          <div class="igs-list" id="igsQueue" style="margin-top:6px"></div>
        </div>
      </div>

      <!-- برند -->
      <div class="igs-pane" data-pane="brand">
        <div class="igs-field">
          <label class="igs-check"><input type="checkbox" id="igsLogoOn"> نمایش لوگوی برند (در صورت تنظیم)</label>
          <div class="igs-muted" id="igsLogoNote"></div>
          <div class="igs-row" style="margin-top:6px">
            <input type="file" id="igsLogoFile" accept="image/png,image/jpeg,image/webp" class="igs-tiny" title="لوگوی اختصاصی استودیو (فقط همین ابزار)">
            <button type="button" id="igsLogoDel" class="danger igs-tiny" style="display:none">حذف لوگوی استودیو</button>
          </div>
          <div class="igs-muted" id="igsLogoState"></div>
        </div>
        <div class="igs-field" id="igsSamplesBox">
          <label>عکس‌های نمونه‌ی استودیو (عمودی، جدا از عکس‌های پیش‌فرض کارت‌ها)</label>
          <div class="igs-muted">برای آگهی بدون عکس در استودیو. هر نوع ملک تا ۲۰ عکس عمودی. این عکس‌ها در کارت‌ها و صفحه‌ی عمومی سایت نمایش داده نمی‌شوند.</div>
          <div class="igs-row" style="margin-top:6px">
            <select id="igsSampleType" class="igs-tiny"></select>
            <input type="file" id="igsSampleFile" accept="image/png,image/jpeg,image/webp" class="igs-tiny">
            <button type="button" id="igsSampleAdd" class="igs-tiny">افزودن عکس</button>
          </div>
          <div class="igs-muted" id="igsSampleState"></div>
          <div id="igsSampleList"></div>
        </div>
        <div class="igs-field">
          <label for="igsCta">متن دعوت به اقدام</label>
          <input type="text" id="igsCta" maxlength="120" placeholder="خالی = متن پیش‌فرض برند">
        </div>
        <div class="igs-field">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="igsHidePrice"> عدم نمایش قیمت</label>
          <div class="igs-muted">اگر فعال باشد، به‌جای قیمت در استوری این متن نوشته می‌شود: «برای استعلام قیمت تماس بگیرید»</div>
        </div>

        <div class="igs-field">
          <b>برچسب‌های آماده</b>
          <div class="igs-muted">چند برچسب انتخاب کنید (حداکثر ۴). روی همهٔ اسلایدها و نسخهٔ خروجی نشان داده می‌شوند؛ بدون انتخاب چیزی اضافه نمی‌شود.</div>
          <div class="igs-row" id="igsLabels" style="flex-wrap:wrap;gap:6px;margin-top:6px"></div>
        </div>

        <div id="igsBrandInfo" class="igs-kv"></div>
        <div class="igs-muted" style="margin:6px 0 8px">نام برند، لوگو و تلفن از «تنظیمات سایت» خوانده می‌شوند. اگر چیزی تنظیم نشده باشد، در استودیو نشان داده می‌شود و چیزی ساخته نمی‌شود.</div>
        <div class="igs-row"><button type="button" class="igs-tiny" id="igsGoBrand">رفتن به تنظیمات برند</button></div>
        <h3 style="margin-top:12px">برند این پروژه</h3>
        <div class="igs-field"><label class="igs-lbl" for="igsDocBrand">برند برای این پروژه</label><select id="igsDocBrand"><option value="0">بدون برند</option></select>
          <div class="igs-muted">وقتی برند روشن است، نام، تلفن، لوگو و رنگ‌های پروفایل انتخابی در طرح به‌کار می‌رود و دکمه‌ی «+ اطلاعات برند» در دسترس قرار می‌گیرد.</div></div>
        <h3 style="margin-top:12px">پروفایل‌های برند من</h3>
        <div class="igs-field"><label class="igs-lbl" for="igsBrandSel">پروفایل</label><select id="igsBrandSel"><option value="0">— پروفایل جدید —</option></select></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpName">نام برند</label><input type="text" id="igsBpName" maxlength="80"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpLatin">نام لاتین</label><input type="text" id="igsBpLatin" maxlength="80" dir="ltr"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpPhone">تلفن</label><input type="text" id="igsBpPhone" maxlength="20" dir="ltr"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpWeb">وب‌سایت</label><input type="text" id="igsBpWeb" maxlength="120" dir="ltr"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpSocial">شناسه‌ی اینستاگرام</label><input type="text" id="igsBpSocial" maxlength="31" dir="ltr" placeholder="بدون @"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpTagline">شعار</label><input type="text" id="igsBpTagline" maxlength="120"></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpFont">فونت برند</label><select id="igsBpFont"></select></div>
        <div class="igs-row"><label class="igs-lbl">رنگ اصلی <input type="color" id="igsBpAccent" value="#c9a25f"></label><label class="igs-lbl">رنگ متن <input type="color" id="igsBpInk" value="#1f1f1f"></label></div>
        <div class="igs-field"><label class="igs-lbl" for="igsBpLogo">لوگوی این پروفایل</label><input type="file" id="igsBpLogo" accept="image/png,image/jpeg,image/webp"><div class="igs-muted" id="igsBpLogoState"></div></div>
        <div class="igs-row"><button type="button" class="primary" id="igsBpSave">ذخیره پروفایل</button><button type="button" class="danger" id="igsBpDel">حذف پروفایل</button></div>
        <div class="igs-muted" id="igsBpMsg" style="margin-top:6px"></div>
        <h3 style="margin-top:12px">پیش‌فرض‌های استودیو</h3>
        <div class="igs-field"><label class="igs-lbl" for="igsPFont">فونت پیش‌فرض</label><select id="igsPFont"></select></div>
        <div class="igs-field"><label class="igs-lbl" for="igsPTheme">تم پیش‌فرض</label><select id="igsPTheme"></select></div>
        <div class="igs-field"><label class="igs-check"><input type="checkbox" id="igsPLogo"> نمایش لوگو به‌طور پیش‌فرض</label></div>
        <div class="igs-field"><label class="igs-lbl" for="igsPCta">متن دعوت به اقدام پیش‌فرض</label><input type="text" id="igsPCta" maxlength="120"></div>
        <button type="button" class="primary" id="igsPSave">ذخیره پیش‌فرض‌ها</button>
        <div class="igs-muted" id="igsPMsg" style="margin-top:6px"></div>
      </div>

      <!-- آرشیو خروجی -->
      <div class="igs-pane" data-pane="archive">
        <div class="igs-row"><button type="button" class="igs-tiny" id="igsExRefresh">به‌روزرسانی فهرست</button><span class="igs-muted">خروجی‌های ذخیره‌شده روی سرور</span></div>
        <div class="igs-list" id="igsExList" style="margin-top:6px"></div>
      </div>
    </div>
  </div>
</div>
