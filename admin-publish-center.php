<?php require_once __DIR__ . '/config.php';
/**
 * ملکینو — تب «انتشار» — مرکز انتشار (نمای کلی، تقویم شمسی، صف، انتشار گروهی،
 * محتوای عمومی، کانال‌ها و اتصالات، قوانین، عملکرد و آمار بازدید، تاریخچه)
 * این فایل فقط پوسته است؛ منطق در admin-publish-center.js از طریق publish-api.php.
 */
// فقط از داخل admin-panel.php include می‌شود؛ دسترسی مستقیم مجاز نیست
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'admin-publish-center.php') {
    http_response_code(403);
    exit('forbidden');
}
?>
<div class="publish-wrap" id="publishWrap" dir="rtl">
  <style>
    /* ═══ مرکز انتشار — هماهنگ با توکن‌های طراحی ملکینو (mx) ═══ */
    .publish-wrap{--pu-bg:var(--bg,#0D1413);--pu-card:var(--surface,#16201E);--pu-s2:var(--surface-soft,#192521);--pu-line:var(--border,#293633);--pu-text:var(--text-primary,#F3F4F6);--pu-mut:var(--text-secondary,#A8B1AE);--pu-accent:var(--primary,#159B8F);--pu-accent-2:var(--primary-light,#34B7A9);--pu-gold:var(--gold,#E5B842);--pu-ok:var(--success,#4ADE80);--pu-warn:var(--warning,#FBBF24);--pu-bad:var(--danger,#F87171);
      background:var(--pu-bg);color:var(--pu-text);font-family:var(--mx-font,Tahoma,Arial,sans-serif);border-radius:var(--mx-radius-lg,18px);padding:16px}
    .publish-wrap code{font-family:ui-monospace,Menlo,Consolas,monospace}
    .pu-head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;background:var(--pu-card);border:1px solid var(--pu-line);border-radius:var(--mx-radius-lg,18px);padding:14px 16px;margin-bottom:12px}
    .pu-head h2{margin:0;font-size:1.15rem}
    .pu-head .pu-sub{color:var(--pu-mut);font-size:.82rem;margin-top:4px}
    .pu-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
    .pu-tab{padding:8px 14px;border-radius:10px;border:1px solid var(--pu-line);background:var(--pu-s2);color:var(--pu-mut);cursor:pointer;font-size:.85rem}
    .pu-tab:hover{color:var(--pu-text);border-color:var(--pu-accent)}
    .pu-tab.on{background:var(--pu-accent);border-color:var(--pu-accent);color:#fff;font-weight:700}
    .pu-pane{display:none}
    .pu-pane.on{display:block}
    .pu-card{background:var(--pu-card);border:1px solid var(--pu-line);border-radius:var(--mx-radius-md,14px);padding:14px}
    .pu-grid{display:grid;gap:12px}
    .pu-stats{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
    .pu-stat{background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:var(--mx-radius-md,14px);padding:12px}
    .pu-stat .n{font-size:1.5rem;font-weight:800}
    .pu-stat .l{color:var(--pu-mut);font-size:.78rem}
    .pu-badges{display:flex;flex-wrap:wrap;gap:6px}
    .pu-badge{padding:3px 10px;border-radius:999px;font-size:.72rem;border:1px solid var(--pu-line);background:var(--pu-s2)}
    .pu-badge.ok{color:#fff;background:var(--pu-ok);border-color:var(--pu-ok)}
    .pu-badge.warn{color:#fff;background:var(--pu-warn);border-color:var(--pu-warn)}
    .pu-badge.bad{color:#fff;background:var(--pu-bad);border-color:var(--pu-bad)}
    .pu-badge.mut{color:var(--pu-mut)}
    .pu-table{width:100%;border-collapse:collapse;font-size:.82rem}
    .pu-table th{color:var(--pu-mut);text-align:right;font-weight:600;padding:8px 8px;border-bottom:1px solid var(--pu-line);white-space:nowrap}
    .pu-table td{padding:8px;border-bottom:1px solid var(--pu-line);vertical-align:top}
    .pu-table tr:hover td{background:var(--pu-s2)}
    .pu-scroll{overflow-x:auto}
    .pu-btn{padding:7px 13px;border-radius:10px;border:1px solid var(--pu-line);background:var(--pu-s2);color:var(--pu-text);cursor:pointer;font-size:.8rem;font-family:inherit}
    .pu-btn:hover{border-color:var(--pu-accent)}
    .pu-btn.pri{background:var(--pu-accent);color:#fff;font-weight:700;border-color:var(--pu-accent)}
    .pu-btn.pri:hover{background:var(--pu-accent-2)}
    .pu-btn.dang{background:transparent;border-color:var(--pu-bad);color:var(--pu-bad)}
    .pu-btn.dang:hover{background:var(--pu-bad);color:#fff}
    .pu-btn.sm{padding:4px 9px;font-size:.72rem}
    .pu-btn:disabled{opacity:.55;cursor:not-allowed}
    .pu-field{display:flex;flex-direction:column;gap:5px;margin:8px 0}
    .pu-field label{font-size:.78rem;color:var(--pu-mut)}
    .pu-field input,.pu-field select,.pu-field textarea{background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:10px;color:var(--pu-text);padding:9px 10px;font-size:.85rem;font-family:inherit}
    .pu-field input:focus,.pu-field select:focus,.pu-field textarea:focus{outline:none;border-color:var(--pu-accent)}
    .pu-field textarea{min-height:110px;resize:vertical;line-height:1.9}
    .pu-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .pu-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
    .pu-cal .hd{color:var(--pu-mut);font-size:.72rem;text-align:center;padding:4px 0}
    .pu-cal .day{background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:10px;min-height:78px;padding:5px;font-size:.72rem;cursor:pointer}
    .pu-cal .day:hover{border-color:var(--pu-accent)}
    .pu-cal .day.out{opacity:.35}
    .pu-cal .day .dn{font-weight:700;font-size:.78rem}
    .pu-cal .ev{display:block;background:var(--pu-card);border-radius:6px;padding:2px 5px;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pu-cal .ev.ok{border-right:3px solid var(--pu-ok)}
    .pu-cal .ev.bad{border-right:3px solid var(--pu-bad)}
    .pu-cal .ev.pend{border-right:3px solid var(--pu-warn)}
    .pu-note{background:var(--pu-s2);border-right:3px solid var(--pu-accent);padding:10px 12px;border-radius:10px;font-size:.8rem;line-height:1.9;margin:8px 0}
    .pu-note.warn{border-right-color:var(--pu-warn)}
    .pu-empty{color:var(--pu-mut);text-align:center;padding:26px 10px;font-size:.85rem}
    .pu-media-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}
    .pu-media-grid img{width:100%;height:86px;object-fit:cover;border-radius:8px;border:2px solid transparent;cursor:pointer}
    .pu-media-grid img.sel{border-color:var(--pu-gold)}
    .pu-chip{display:inline-flex;gap:6px;align-items:center;background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:999px;padding:5px 11px;font-size:.76rem;margin:3px}
    .pu-plat-card{border:1px solid var(--pu-line);border-radius:var(--mx-radius-md,14px);padding:12px;margin:10px 0;background:var(--pu-s2)}
    .pu-plat-card h4{margin:0 0 8px;display:flex;align-items:center;gap:8px}
    .pu-mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.74rem;color:var(--pu-mut);direction:ltr;text-align:left}
    .hidden{display:none !important}

    /* ── مودال‌ها: همیشه داخل viewport، هدر/پابر ثابت، بدنه اسکرول‌شونده ── */
    .pu-modal{display:none;position:fixed;inset:0;background:rgba(4,10,9,.72);z-index:9999;align-items:center;justify-content:center;padding:16px}
    .pu-modal.on{display:flex}
    .pu-modal .box{background:var(--pu-card);border:1px solid var(--pu-line);border-radius:var(--mx-radius-lg,18px);width:min(920px,100%);max-height:calc(100dvh - 32px);display:flex;flex-direction:column;overflow:hidden}
    .pu-modal .box > .pm-head{position:sticky;top:0;z-index:2;background:var(--pu-card);border-bottom:1px solid var(--pu-line);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
    .pu-modal .box > .pm-body{flex:1 1 auto;overflow-y:auto;padding:16px;min-height:0}
    .pu-modal .box > .pm-foot{position:sticky;bottom:0;z-index:2;background:var(--pu-card);border-top:1px solid var(--pu-line);padding:12px 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between}

    /* ── فرم «ثبت برنامهٔ انتشار» (بخش‌های ۱ تا ۱۰) ── */
    .pm-sec{border:1px solid var(--pu-line);border-radius:var(--mx-radius-md,14px);background:var(--pu-card);padding:14px 16px;margin:0 0 12px}
    .pm-sec h3{margin:0 0 2px;font-size:.98rem;display:flex;align-items:center;gap:8px}
    .pm-sec .pm-sub{color:var(--pu-mut);font-size:.78rem;margin:0 0 10px;line-height:1.9}
    .pm-choices{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
    .pm-choice{display:flex;gap:10px;align-items:flex-start;border:1.5px solid var(--pu-line);background:var(--pu-s2);border-radius:var(--mx-radius-md,14px);padding:12px;cursor:pointer}
    .pm-choice.on{border-color:var(--pu-gold);background:rgba(229,184,66,.12)}
    .pm-choice input{margin-top:4px;accent-color:var(--pu-gold)}
    .pm-choice .t{font-weight:700;font-size:.9rem}
    .pm-choice .d{color:var(--pu-mut);font-size:.76rem;line-height:1.8;margin-top:2px}
    .pm-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:999px;font-size:.7rem;border:1px solid var(--pu-line);background:var(--pu-s2);color:var(--pu-mut)}
    .pm-badge.ok{background:var(--pu-ok);border-color:var(--pu-ok);color:#fff}
    .pm-badge.warn{background:var(--pu-warn);border-color:var(--pu-warn);color:#fff}
    .pm-badge.bad{background:var(--pu-bad);border-color:var(--pu-bad);color:#fff}
    .pm-badge.gold{background:rgba(229,184,66,.16);border-color:var(--pu-gold);color:var(--pu-gold)}
    .pm-results{display:grid;gap:8px;margin:8px 0;max-height:260px;overflow-y:auto}
    .pm-result{display:flex;gap:10px;align-items:center;border:1px solid var(--pu-line);background:var(--pu-s2);border-radius:12px;padding:8px;cursor:pointer}
    .pm-result:hover{border-color:var(--pu-accent)}
    .pm-result img{width:58px;height:48px;object-fit:cover;border-radius:8px}
    .pm-result .meta{min-width:0;font-size:.78rem;line-height:1.9}
    .pm-result .meta .ti{font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .pm-chosen{display:flex;gap:12px;align-items:center;border:1.5px solid var(--pu-gold);background:rgba(229,184,66,.10);border-radius:var(--mx-radius-md,14px);padding:12px}
    .pm-chosen img{width:74px;height:60px;object-fit:cover;border-radius:10px}
    .pm-chosen .meta{flex:1;min-width:0;font-size:.82rem;line-height:2}
    .pm-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(108px,1fr));gap:8px;margin-top:8px}
    .pm-gal{position:relative;border:2px solid var(--pu-line);border-radius:10px;overflow:hidden;cursor:pointer;background:var(--pu-s2)}
    .pm-gal.sel{border-color:var(--pu-gold)}
    .pm-gal img{width:100%;height:82px;object-fit:cover;display:block}
    .pm-gal .ord{position:absolute;top:4px;right:4px;background:var(--pu-gold);color:#241B03;font-size:.72rem;font-weight:800;border-radius:8px;padding:0 7px}
    .pm-gal.add{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;border-style:dashed;border-color:var(--pu-gold);color:var(--pu-gold);min-height:82px}
    .ev-x{margin-inline-start:4px;border:0;background:transparent;color:var(--pu-bad,#e5484d);cursor:pointer;font-size:.8rem;padding:0 3px;border-radius:6px}
    .ev.cx{opacity:.55;text-decoration:line-through}
    .ev-x:hover{background:rgba(229,72,77,.15)}
    .pm-gal .chk{position:absolute;bottom:4px;right:4px;width:18px;height:18px;border-radius:6px;border:1.5px solid var(--pu-line);background:rgba(0,0,0,.35)}
    .pm-gal.sel .chk{background:var(--pu-gold);border-color:var(--pu-gold)}
    .pm-chan-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
    .pm-chan{border:1.5px solid var(--pu-line);background:var(--pu-s2);border-radius:var(--mx-radius-md,14px);padding:12px}
    .pm-chan.on{border-color:var(--pu-accent)}
    .pm-chan .hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .pm-chan .hd .nm{font-weight:700}
    .pm-toggle{display:flex;align-items:center;gap:8px;font-size:.82rem;cursor:pointer;user-select:none}
    .pm-toggle input{accent-color:var(--pu-gold);width:16px;height:16px}
    .pm-preview{background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:14px;padding:14px;max-height:320px;overflow-y:auto}
    .pm-bubble{background:var(--pu-card);border:1px solid var(--pu-line);border-radius:12px;padding:12px;white-space:pre-wrap;line-height:2;font-size:.85rem}
    .pm-bubble .lnk{color:var(--pu-gold);word-break:break-all}
    .pm-thumbs{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}
    .pm-thumbs img{width:76px;height:58px;object-fit:cover;border-radius:8px;border:1px solid var(--pu-line)}
    .pm-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
    .pm-tab{padding:5px 12px;border-radius:999px;border:1px solid var(--pu-line);background:var(--pu-s2);color:var(--pu-mut);cursor:pointer;font-size:.76rem}
    .pm-tab.on{background:var(--pu-gold);border-color:var(--pu-gold);color:#241B03;font-weight:700}
    .pm-sum{display:grid;gap:6px;font-size:.82rem}
    .pm-sum .r{display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--pu-line);padding:5px 0}
    .pm-sum .r span:first-child{color:var(--pu-mut)}
    .pm-err{color:var(--pu-bad);font-size:.76rem;margin-top:4px}
    .pm-adv summary{cursor:pointer;font-size:.85rem;color:var(--pu-mut);padding:6px 0;list-style:none}
    .pm-adv summary::-webkit-details-marker{display:none}
    .pm-adv summary:before{content:"▾";display:inline-block;margin-left:6px;transition:transform .15s}
    .pm-adv[open] summary:before{transform:rotate(180deg)}
    .pm-crumb{color:var(--pu-mut);font-size:.74rem;margin:0 0 2px}
    .pm-foot .pm-main{padding:9px 22px;border-radius:12px;background:var(--pu-accent);color:#fff;font-weight:800;border:1px solid var(--pu-accent);cursor:pointer;font-family:inherit}
    .pm-foot .pm-main:hover{background:var(--pu-accent-2)}
    .pm-foot .pm-main:disabled{opacity:.55;cursor:not-allowed}
    /* توقف/ادامهٔ سراسری */
    .pu-pause{display:inline-flex;align-items:center;gap:6px;font-weight:700}
    .pu-pause.is-running{background:transparent;border-color:var(--pu-ok);color:var(--pu-ok)}
    .pu-pause.is-running:hover{background:rgba(74,222,128,.12)}
    .pu-pause.is-paused{background:var(--pu-bad);border-color:var(--pu-bad);color:#fff}
    .pu-pause.is-paused:hover{filter:brightness(1.1)}
    /* تقویم شمسی بازشونده */
    .mk-jalali-pop{position:absolute;z-index:10050;background:var(--pu-card);border:1px solid var(--pu-line);border-radius:14px;padding:10px;width:280px;box-shadow:0 18px 40px rgba(0,0,0,.45);display:none}
    .mk-jalali-pop.on{display:block}
    .mk-jalali-pop .jp-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px;font-weight:700;font-size:.85rem}
    .mk-jalali-pop .jp-nav{background:var(--pu-s2);border:1px solid var(--pu-line);color:var(--pu-text);border-radius:8px;width:30px;height:30px;cursor:pointer;font-family:inherit}
    .mk-jalali-pop .jp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
    .mk-jalali-pop .jp-dow{text-align:center;font-size:.68rem;color:var(--pu-mut);padding:3px 0}
    .mk-jalali-pop .jp-day{border:1px solid transparent;background:var(--pu-s2);color:var(--pu-text);border-radius:8px;min-height:32px;cursor:pointer;font-size:.76rem;font-family:inherit}
    .mk-jalali-pop .jp-day:hover{border-color:var(--pu-gold)}
    .mk-jalali-pop .jp-day.out{opacity:.3}
    .mk-jalali-pop .jp-day.today{border-color:var(--pu-accent)}
    .mk-jalali-pop .jp-day.sel{background:var(--pu-gold);color:#241B03;font-weight:800}
    .mk-jalali-pop .jp-foot{margin-top:8px;display:flex;justify-content:space-between}
    .mk-jalali-pop .jp-foot button{background:var(--pu-s2);border:1px solid var(--pu-line);color:var(--pu-text);border-radius:8px;padding:4px 10px;font-size:.74rem;cursor:pointer;font-family:inherit}
    /* زمان جداگانهٔ هر کانال */
    .pm-when-row{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;border:1px solid var(--pu-line);border-radius:12px;background:var(--pu-s2);padding:10px;margin:8px 0}
    .pm-when-row .lbl{font-size:.78rem;color:var(--pu-mut);min-width:110px;padding-bottom:8px}
    .pm-when-row input,.pm-when-row select{background:var(--pu-card);border:1px solid var(--pu-line);border-radius:10px;color:var(--pu-text);padding:8px 10px;font-family:inherit}
    @media (max-width:760px){
      .pu-head{flex-direction:column;align-items:stretch}
      .pu-cal .day{min-height:58px}
      .pu-stat .n{font-size:1.25rem}
      .pu-modal{padding:8px}
      .pu-modal .box{max-height:calc(100dvh - 16px)}
      .pm-foot{flex-direction:column;align-items:stretch}
      .pm-foot .pm-main{width:100%;padding:12px}
    }
  </style>

  <div class="pu-head">
    <div>
      <h2>📤 مرکز انتشار</h2>
      <div class="pu-sub">زمان‌بندی و انتشار آگهی‌ها در ایتا / بله / تلگرام — پردازش کاملاً سمت سرور (بدون نیاز به باز بودن مرورگر)</div>
    </div>
    <div class="pu-row">
      <span class="pu-badge mut" id="puWorkerBadge" title="وضعیت Worker سرور">Worker: در حال بررسی…</span>
      <button class="pu-btn" id="puRunQueueBtn" type="button">▶ اجرای صف انتشار</button>
      <button class="pu-btn pri" id="puNewBtn" type="button">＋ برنامهٔ انتشار جدید</button>
      <button class="pu-btn pu-pause" id="puPauseBtn" type="button" title="توقف/ادامهٔ سراسری انتشار">▶ ادامه طبق برنامه</button>
    </div>
  </div>

  <div class="pu-note warn hidden" id="puPausedBanner" style="border-right-color:var(--pu-bad)">
    <strong>انتشار سراسری متوقف است.</strong> هیچ برنامهٔ انتشاری — حتی زمان‌بندی‌شده — تا زمانی که «▶ ادامه طبق برنامه» را بزنید ارسال نمی‌شود.
  </div>

  <div class="pu-tabs" id="puTabs">
    <button class="pu-tab on" data-pane="overview" type="button">نمای کلی</button>
    <button class="pu-tab" data-pane="calendar" type="button">تقویم انتشار</button>
    <button class="pu-tab" data-pane="queue" type="button">صف انتشار</button>
    <button class="pu-tab" data-pane="bulk" type="button">انتشار گروهی</button>
    <button class="pu-tab" data-pane="content" type="button">مدیریت محتوای عمومی</button>
    <button class="pu-tab" data-pane="channels" type="button">کانال‌ها و اتصالات</button>
    <button class="pu-tab" data-pane="rules" type="button">قوانین اتوماسیون</button>
    <button class="pu-tab" data-pane="stats" type="button">عملکرد و آمار بازدید</button>
    <button class="pu-tab" data-pane="history" type="button">تاریخچهٔ انتشار</button>
    <button class="pu-tab" data-pane="backup" type="button">پشتیبانی و اکسل</button>
    <button class="pu-tab" data-pane="dist" type="button">قوانین توزیع</button>
  </div>

  <!-- نمای کلی -->
  <section class="pu-pane on" id="puPane-overview">
    <div class="pu-grid pu-stats" id="puStats"></div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 8px;font-size:.95rem">فیلتر نما</h3>
      <div class="pu-row">
        <select id="puOvPlat"><option value="">همهٔ پیام‌رسان‌ها</option><option value="telegram">تلگرام</option><option value="bale">بله</option><option value="eitaa">ایتا</option></select>
        <input id="puOvFrom" placeholder="از تاریخ (۱۴۰۵/۰۷/۰۱)" style="max-width:150px">
        <input id="puOvTo" placeholder="تا تاریخ (۱۴۰۵/۰۷/۳۰)" style="max-width:150px">
        <button class="pu-btn" id="puOvFilterBtn" type="button">اعمال فیلتر</button>
        <span class="pu-note" style="border:0;background:none">کلیک روی هر کارت آمار = نمایش فهرست همان وضعیت.</span>
      </div>
    </div>
    <div class="pu-card" style="margin-top:12px" id="puOvListCard">
      <h3 style="margin:0 0 8px;font-size:.95rem">فهرست فیلترشده</h3>
      <div class="pu-scroll"><table class="pu-table" id="puOvList"><thead><tr><th>زمان</th><th>عنوان</th><th>پیام‌رسان</th><th>وضعیت</th><th>تلاش</th><th>خطا</th></tr></thead><tbody>
        <tr><td colspan="6" class="pu-empty">روی کارت آمار یا «اعمال فیلتر» بزنید.</td></tr>
      </tbody></table></div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 10px;font-size:.95rem">وضعیت هر پیام‌رسان</h3>
      <div class="pu-badges" id="puPlatSummary"></div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 10px;font-size:.95rem">آخرین رویدادهای انتشار</h3>
      <div class="pu-scroll"><table class="pu-table" id="puRecentTable"><thead><tr><th>زمان</th><th>آگهی</th><th>پیام‌رسان</th><th>عملیات</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- تقویم -->
  <section class="pu-pane" id="puPane-calendar">
    <div class="pu-card">
      <div class="pu-row" style="justify-content:space-between">
        <div class="pu-row">
          <button class="pu-btn sm" id="puCalPrev" type="button">› ماه قبل</button>
          <strong id="puCalTitle" style="min-width:150px;text-align:center"></strong>
          <button class="pu-btn sm" id="puCalNext" type="button">ماه بعد ‹</button>
          <button class="pu-btn sm" id="puCalToday" type="button">امروز</button>
        </div>
        <span class="pu-badge mut" id="puCalTz"></span>
      </div>
      <div class="pu-cal" id="puCalGrid" style="margin-top:12px"></div>
      <div class="pu-note">برنامه‌های هر روز با رنگ وضعیت نمایش داده می‌شوند (سبز=منتشرشده، زرد=در صف، قرمز=ناموفق). با کلیک روی هر روز می‌توانید برنامهٔ جدید ثبت کنید.</div>
    </div>
  </section>

  <!-- صف انتشار -->
  <section class="pu-pane" id="puPane-queue">
    <div class="pu-card">
      <div class="pu-row" style="justify-content:space-between">
        <h3 style="margin:0;font-size:.95rem">مقاصد در انتظار / ناموفق</h3>
        <div class="pu-row" style="gap:8px">
          <span id="puQueueSelCount" class="pu-mono" style="font-size:.75rem">۰ مورد انتخاب شده</span>
          <button class="pu-btn sm dang" id="puQueueBulkDelete" type="button" disabled>حذف انتخاب‌شده‌ها از صف</button>
          <button class="pu-btn sm" id="puRefreshQueue" type="button">↻ بروزرسانی</button>
        </div>
      </div>
      <div class="pu-scroll"><table class="pu-table" id="puQueueTable"><thead><tr><th style="width:34px"><input type="checkbox" id="puQueueSelAll" aria-label="انتخاب همه" title="انتخاب همه"></th><th>زمان ارسال</th><th>عنوان</th><th>پیام‌رسان</th><th>وضعیت</th><th>تلاش</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- انتشار گروهی -->
  <section class="pu-pane" id="puPane-bulk">
    <div class="pu-card">
      <h3 style="margin:0 0 8px;font-size:.95rem">انتشار گروهی آگهی‌ها</h3>
      <div class="pu-note">چند آگهی را انتخاب کنید، پیام‌رسان‌ها و فاصلهٔ زمانی میان ارسال‌ها را مشخص کنید. هر آگهی با «Snapshot» مستقل خودش ارسال می‌شود و نتیجه به‌صورت جزئی (موفق/ناموفق هر مورد) گزارش می‌شود.</div>
      <div class="pu-row"><input class="pu-field" style="flex:1;min-width:220px" id="puBulkSearch" placeholder="جست‌وجوی آگهی (عنوان/شناسه/آدرس)…"><button class="pu-btn" id="puBulkSearchBtn" type="button">جست‌وجو</button></div>
      <div class="pu-scroll"><table class="pu-table" id="puBulkTable"><thead><tr><th><input type="checkbox" id="puBulkAll"></th><th>شناسه</th><th>عنوان</th><th>نوع</th><th>وضعیت</th></tr></thead><tbody></tbody></table></div>
      <div class="pu-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-top:10px">
        <div class="pu-field"><label>پیام‌رسان‌های مقصد</label><div id="puBulkPlats" class="pu-row"></div></div>
        <div class="pu-field"><label>زمان شروع (شمسی)</label><input id="puBulkWhen" placeholder="۱۴۰۵/۰۷/۲۰ ۱۸:۰۰"></div>
        <div class="pu-field"><label>فاصلهٔ بین ارسال‌ها (ثانیه)</label><input id="puBulkInterval" type="number" min="30" value="120"></div>
      </div>
      <button class="pu-btn pri" id="puBulkGo" type="button">شروع انتشار گروهی</button>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 10px;font-size:.95rem">کمپین‌های گروهی</h3>
      <div class="pu-scroll"><table class="pu-table" id="puCampaignsTable"><thead><tr><th>#</th><th>عنوان</th><th>شروع</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- محتوای عمومی -->
  <section class="pu-pane" id="puPane-content">
    <div class="pu-card">
      <h3 style="margin:0 0 8px;font-size:.95rem">محتوای عمومی (بدون آگهی)</h3>
      <div class="pu-note">برای اطلاع‌رسانی‌ها و محتوای عمومی کانال‌ها؛ می‌توانید متن دلخواه و تصاویر آپلودشده را انتخاب و در کانال‌های دلخواه منتشر یا زمان‌بندی کنید.</div>
      <div class="pu-field"><label>عنوان</label><input id="puContTitle" placeholder="مثلاً: اطلاعیهٔ تعطیلات"></div>
      <div class="pu-field"><label>متن پیام</label><textarea id="puContBody" placeholder="متن پیام کانال…"></textarea></div>
      <div class="pu-field"><label>قالب محتوا</label>
        <div class="pu-row"><select id="puContTemplate"><option value="">بدون قالب (متن بالا ارسال می‌شود)</option></select>
        <button class="pu-btn sm" id="puContTplFill" type="button">درج متن قالب</button></div>
      </div>
      <div class="pu-field"><label>تصاویر و ویدیو (اختیاری — از کتابخانهٔ رسانهٔ سایت)</label><input id="puContMedia" placeholder="نام فایل در uploads/… (جدا شده با ویرگول)"></div>
      <div class="pu-field"><label>پیش‌نمایش</label>
        <div id="puContPrevTabs" class="pm-tabs" style="display:flex;gap:6px;margin-bottom:8px"></div>
        <div id="puContPrevBody"></div>
      </div>
      <div class="pu-field"><label>پیام‌رسان‌ها</label><div id="puContPlats" class="pu-row"></div></div>
      <div class="pu-row">
        <div class="pu-field" style="flex:1"><label>زمان ارسال (شمسی — خالی = فوری)</label><input id="puContWhen" placeholder="۱۴۰۵/۰۷/۲۰ ۱۸:۰۰"></div>
        <button class="pu-btn pri" id="puContGo" type="button" style="align-self:flex-end">ثبت و انتشار</button>
        <div class="pu-row" style="align-self:flex-end">
          <button class="pu-btn pri" id="puContUploadBtn" type="button">⬆ آپلود عکس/ویدئو</button>
          <input type="file" id="puContUploadFile" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4" multiple class="hidden">
          <button class="pu-btn" id="puContDraft" type="button">ذخیرهٔ پیش‌نویس</button>
        </div>
        <div id="puContMediaChips" class="pu-badges"></div>
      </div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 10px;font-size:.95rem">محتوای ثبت‌شده</h3>
      <div class="pu-scroll"><table class="pu-table" id="puContentTable"><thead><tr><th>#</th><th>عنوان</th><th>وضعیت</th><th>زمان</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 8px;font-size:.95rem">پیام‌های یک آگهی در کانال‌ها + حذف گروهی</h3>
      <div class="pu-note">برای هر آگهی، همهٔ پیام‌های منتشرشدهٔ آن در تلگرام/بله/ایتا را نشان می‌دهد و در صورت تأیید، از کانال‌ها حذف می‌کند. عملیات حذف در تاریخچه ثبت می‌شود.</div>
      <div class="pu-row" style="margin-top:8px">
        <input id="puAdPostsId" placeholder="شناسهٔ آگهی (مثلاً 101)" style="max-width:180px">
        <div id="puAdPostsDist" style="flex:1;min-width:220px"></div>
        <button class="pu-btn" id="puAdPostsBtn" type="button">نمایش پیام‌ها</button>
        <button class="pu-btn" id="puAdPostsDel" type="button">حذف همه از کانال‌ها…</button>
      </div>
      <div class="pu-scroll" style="margin-top:8px"><table class="pu-table" id="puAdPostsTable"><thead><tr><th>پیام‌رسان</th><th>پیام‌ها</th><th>زمان انتشار</th><th>شناسهٔ پیام</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- کانال‌ها و اتصالات -->
  <section class="pu-pane" id="puPane-channels">
    <div class="pu-card" style="margin-bottom:12px">
      <h3 style="margin:0 0 8px;font-size:.95rem">تست اتصال واقعی</h3>
      <div class="pu-note">هر تست فقط اتصال API/کانال را می‌آزماید و نتیجهٔ واقعی در تاریخچه ثبت می‌شود؛ انتشار واقعی مرحلهٔ جدا دارد.</div>
      <div class="pu-row" style="margin-top:8px">
        <button class="pu-btn" data-test-conn="telegram|bot" type="button">تست API تلگرام</button>
        <button class="pu-btn" data-test-conn="telegram|channel" type="button">تست کانال تلگرام</button>
        <button class="pu-btn" data-test-conn="bale|bot" type="button">تست API بله</button>
        <button class="pu-btn" data-test-conn="bale|channel" type="button">تست کانال بله</button>
        <button class="pu-btn" data-test-conn="eitaa|channel" type="button">تست کانال ایتا</button>
      </div>
    </div>
    <div class="pu-card" style="margin-bottom:12px">
      <h3 style="margin:0 0 8px;font-size:.95rem">قالب‌های محتوایی</h3>
      <div class="pu-note">جای‌نگهدارها: <code>{title}</code> <code>{body}</code> <code>{price}</code> <code>{area}</code> <code>{location}</code> <code>{rooms}</code> <code>{phone}</code> <code>{link}</code> <code>{footer}</code></div>
      <div class="pu-grid" style="grid-template-columns:1fr 1fr;margin-top:8px">
        <div class="pu-field"><label>نام قالب</label><input id="puTplName" maxlength="190"></div>
        <div class="pu-field"><label>پیام‌رسان</label><select id="puTplPlat"><option value="all">همه</option><option value="telegram">تلگرام</option><option value="bale">بله</option><option value="eitaa">ایتا</option></select></div>
        <div class="pu-field"><label>نوع</label><select id="puTplKind"><option value="ad">آگهی</option><option value="content">محتوای آزاد</option></select></div>
        <div class="pu-field"><label>پاورقی</label><input id="puTplFooter" maxlength="300"></div>
      </div>
      <div class="pu-field"><label>متن قالب</label><textarea id="puTplBody" rows="3" placeholder="🏠 {title}&#10;قیمت: {price}&#10;{body}"></textarea></div>
      <div class="pu-row"><button class="pu-btn pri" id="puTplSave" type="button">ذخیرهٔ قالب</button></div>
      <div class="pu-scroll" style="margin-top:8px"><table class="pu-table" id="puTplTable"><thead><tr><th>نام</th><th>پیام‌رسان</th><th>نوع</th><th>پیش‌فرض</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
    </div>
    <div class="pu-grid" id="puChannelsGrid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))"></div>
    <div class="pu-note warn">توکن‌های ربات فقط سمت سرور در «تنظیمات پیام‌رسان‌ها» نگهداری می‌شوند و هرگز در این صفحه یا لاگ‌ها نمایش داده نمی‌شوند. برای تنظیم/تغییر توکن به تب «تنظیمات پیام‌رسان‌ها» بروید.</div>
    <div class="pu-note">قابلیت «تعداد بازدید» در Bot API تلگرام و بله وجود ندارد و ایتا نیز مستندسازی عمومی ندارد؛ بنابراین آمار بازدید «پشتیبانی نمی‌شود» و عدد ساختگی نمایش داده نمی‌شود. آمار کلیک لینک‌های اختصاصی در بخش «عملکرد و آمار بازدید» ثبت می‌شود.</div>
  </section>

  <!-- قوانین -->
  <section class="pu-pane" id="puPane-rules">
    <div class="pu-card">
      <h3 style="margin:0 0 8px;font-size:.95rem">قوانین اتوماسیون</h3>
      <div class="pu-note">با تغییر وضعیت آگهی (فروخته/اجاره‌رفته/غیرفعال/ویرایش‌شده/منقضی/حذف‌شده)، عملیات انتخابی روی «پست‌های قبلاً منتشرشده» اجرا می‌شود. هر قانون حداکثر یک بار برای هر آگهی اجرا می‌شود و در تاریخچه ثبت می‌گردد. ویرایش/حذف پست فقط برای پیام‌رسان‌هایی انجام می‌شود که از پشتیبانی قطعی برخوردارند.</div>
      <div class="pu-scroll"><table class="pu-table" id="puRulesTable"><thead><tr><th>رویداد</th><th>عملیات</th><th>فعال</th><th>متن افزودنی (برای ویرایش پست)</th><th></th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- عملکرد و آمار -->
  <section class="pu-pane" id="puPane-stats">
    <div class="pu-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
      <div class="pu-card">
        <h3 style="margin:0 0 10px;font-size:.95rem">بازدید پست‌ها</h3>
        <div id="puViewsNote"></div>
      </div>
      <div class="pu-card">
        <h3 style="margin:0 0 10px;font-size:.95rem">کلیک لینک‌های اختصاصی</h3>
        <div class="pu-scroll"><table class="pu-table" id="puClicksTable"><thead><tr><th>پیام‌رسان</th><th>کلیک</th><th>لینک فعال</th><th>آخرین کلیک</th></tr></thead><tbody></tbody></table></div>
      </div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 10px;font-size:.95rem">پست‌های منتشرشده</h3>
      <div class="pu-scroll"><table class="pu-table" id="puPublishedTable"><thead><tr><th>زمان</th><th>عنوان</th><th>پیام‌رسان</th><th>شناسهٔ پیام</th><th>لینک</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- تاریخچه -->
  <section class="pu-pane" id="puPane-history">
    <div class="pu-card">
      <div class="pu-row">
        <input id="puHistAd" placeholder="شناسهٔ آگهی" style="max-width:130px">
        <select id="puHistPlat"><option value="">همهٔ پیام‌رسان‌ها</option><option value="telegram">تلگرام</option><option value="bale">بله</option><option value="eitaa">ایتا</option></select>
        <input id="puHistFrom" placeholder="از تاریخ (۱۴۰۵/۰۷/۰۱)" style="max-width:150px">
        <input id="puHistTo" placeholder="تا تاریخ (۱۴۰۵/۰۷/۳۰)" style="max-width:150px">
        <button class="pu-btn" id="puHistFilter" type="button">اعمال فیلتر</button>
        <button class="pu-btn sm" id="puHistRefresh" type="button">↻</button>
      </div>
      <div class="pu-scroll" style="margin-top:10px"><table class="pu-table" id="puHistTable"><thead><tr><th>زمان</th><th>آگهی</th><th>پیام‌رسان</th><th>عملیات</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody></tbody></table></div>
    </div>
  </section>

  <!-- پشتیبانی / بازیابی / اکسل -->
  <div class="pu-pane" id="puPane-backup">
    <div class="pu-card">
      <h3 style="margin:0 0 6px;font-size:.98rem">پشتیبان‌گیری، بازیابی و اکسل برنامهٔ انتشار</h3>
      <p class="pm-sub" style="color:var(--pu-mut);font-size:.8rem;line-height:1.9">
        پشتیبان، تمام برنامه‌ها/مقاصد/تاریخچه/قالب‌ها/قوانین را در یک فایل JSON نگه می‌دارد.
        بازیابی، داده‌های فعلی ماژول انتشار را <strong>جایگزین</strong> می‌کند. خروجی اکسل با Excel باز می‌شود و ورودی همان ساختار را می‌پذیرد (xlsx یا csv).
      </p>
      <div class="pu-row" style="margin:10px 0">
        <button class="pu-btn pri" id="puBackupBtn" type="button">⬇ پشتیبان‌گیری (JSON)</button>
        <button class="pu-btn" id="puRestoreBtn" type="button">⬆ بازیابی از پشتیبان</button>
        <input type="file" id="puRestoreFile" accept=".json,application/json" class="hidden">
      </div>
      <div class="pu-row" style="margin:10px 0">
        <button class="pu-btn pri" id="puXlsxExportBtn" type="button">⬇ خروجی اکسل برنامهٔ انتشار</button>
        <button class="pu-btn" id="puXlsxImportBtn" type="button">⬆ ورودی اکسل/CSV</button>
        <input type="file" id="puXlsxImportFile" accept=".xlsx,.csv" class="hidden">
      </div>
      <div id="puBackupInfo" class="pu-note">هنوز عملیاتی انجام نشده است.</div>
    </div>
  </div>

  <!-- قوانین هوشمند توزیع آگهی -->
  <div class="pu-pane" id="puPane-dist">
    <div class="pu-card">
      <div class="pu-row" style="justify-content:space-between">
        <div>
          <h3 style="margin:0 0 4px;font-size:.98rem">قوانین توزیع آگهی بین کانال‌ها</h3>
          <div class="pu-sub" style="color:var(--pu-mut);font-size:.78rem;line-height:1.9">
            برای هر کانال (حتی چند کانال از یک پیام‌رسان) قانون بگذارید تا هر آگهی تأییدشده بر اساس
            نوع معامله/نوع ملک/شرایط واقعی خود به مقصد درست برود. منطق شرط‌ها: <strong>همهٔ شرط‌ها (AND)</strong>؛
            نتیجهٔ چند قانون: <strong>اجتماع مقاصد</strong> (هر قانون مستقل). انتشار خودکار فقط برای آگهی‌های «منتشرشده».
          </div>
        </div>
        <div class="pu-row">
          <button class="pu-btn" id="puDistEvalAll" type="button">ارزیابی مجدد همه</button>
          <button class="pu-btn pri" id="puDistNewBtn" type="button">＋ قانون جدید</button>
        </div>
      </div>
      <div class="pu-scroll" style="margin-top:10px">
        <table class="pu-table" id="puDistTable">
          <thead><tr>
            <th>نام قانون</th><th>کانال مقصد</th><th>دسته‌بندی‌ها</th><th>تعداد منطبق</th><th>رفتار</th><th>وضعیت</th><th>آخرین فعالیت</th><th>عملیات</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="pu-empty hidden" id="puDistEmpty">هنوز قانونی تعریف نشده است — «＋ قانون جدید» را بزنید.</div>
    </div>
    <div class="pu-card" style="margin-top:12px">
      <h3 style="margin:0 0 8px;font-size:.95rem">آگهی‌های بدون قانون</h3>
      <div class="pu-sub" style="color:var(--pu-mut);font-size:.78rem">آگهی‌های تأییدشده‌ای که با هیچ قانون فعالی منطبق نیستند و به‌صورت خودکار جایی نمی‌روند.</div>
      <div id="puUnmatchedList" class="pu-badges" style="margin-top:8px"></div>
    </div>
  </div>

  <!-- مودال قانون توزیع -->
  <div class="pu-modal" id="puDistModal">
    <div class="box" style="max-width:760px">
      <div class="pm-head">
        <h3 style="margin:0;font-size:1.02rem" id="puDistModalTitle">قانون توزیع جدید</h3>
        <button class="pu-btn sm" id="puDistClose" type="button">✕</button>
      </div>
      <div class="pm-body">
        <div class="pu-field"><label>نام قانون</label><input id="puDistName" maxlength="120" placeholder="مثلاً: کانال اجاره"></div>
        <div class="pu-row">
          <div class="pu-field" style="flex:1;min-width:150px"><label>پیام‌رسان مقصد</label>
            <select id="puDistPlatform"><option value="telegram">تلگرام</option><option value="bale">بله</option><option value="eitaa">ایتا</option></select>
          </div>
          <div class="pu-field" style="flex:2;min-width:180px"><label>شناسهٔ کانال مقصد (برای چند کانال از یک پیام‌رسان، هر قانون یک کانال)</label>
            <input id="puDistChannel" list="puChRegistryList" placeholder="@channel یا chat_id — خالی = کانال پیش‌فرض تنظیمات">
            <datalist id="puChRegistryList"></datalist>
          </div>
        </div>
        <div class="pu-field"><label>نوع معامله (خالی = همه)</label>
          <div class="pu-row" id="puDistTxTypes"></div>
        </div>
        <div class="pu-field"><label>رفتار هنگام انطباق</label>
          <div class="pm-choices" style="grid-template-columns:1fr 1fr">
            <label class="pm-choice on" data-onm="suggest"><input type="radio" name="puDistOnM" value="suggest" checked>
              <div><div class="t">فقط تعیین مقصد</div><div class="d">پیشنهاد کانال‌ها نمایش داده می‌شود؛ هیچ اقدام خودکاری نیست.</div></div></label>
            <label class="pm-choice" data-onm="enqueue"><input type="radio" name="puDistOnM" value="enqueue">
              <div><div class="t">ورود به صف انتشار</div><div class="d">برنامه فوری ساخته و توسط Worker ارسال می‌شود (با جلوگیری از تکرار).</div></div></label>
            <label class="pm-choice" data-onm="schedule"><input type="radio" name="puDistOnM" value="schedule">
              <div><div class="t">زمان‌بندی با زمان پیش‌فرض</div><div class="d">برنامه با زمان شمسی مشخص‌شده در ادامه ساخته می‌شود.</div></div></label>
            <label class="pm-choice" data-onm="approval"><input type="radio" name="puDistOnM" value="approval">
              <div><div class="t">نیاز به تأیید ادمین</div><div class="d">به‌صورت پیش‌نویس ساخته می‌شود و تا تأیید شما ارسال نمی‌شود.</div></div></label>
          </div>
          <div class="pu-field hidden" id="puDistScheduleWrap" style="margin-top:8px">
            <label>زمان پیش‌فرض انتشار (شمسی)</label>
            <input id="puDistSchedule" placeholder="۱۴۰۵/۰۷/۲۵ ۱۰:۰۰" data-mk-jalali style="max-width:220px">
          </div>
        </div>
        <details class="pm-adv" style="margin:10px 0">
          <summary>شرایط تکمیلی (اختیاری — همه با هم AND)</summary>
          <div style="margin-top:10px">
            <div class="pu-field"><label>نوع ملک (خالی = همه)</label><div class="pu-row" id="puDistPropTypes"></div></div>
            <div class="pu-row">
              <div class="pu-field" style="flex:1;min-width:130px"><label>فقط آگهی ویژه</label>
                <select id="puDistVip"><option value="">همه</option><option value="1">فقط «ویژه» (is_vip)</option><option value="0">فقط غیرویژه</option></select>
              </div>
              <div class="pu-field" style="flex:1;min-width:130px"><label>شهر/موقعیت شامل</label><input id="puDistCity" placeholder="مثلاً: شاهرود"></div>
              <div class="pu-field" style="flex:1;min-width:130px"><label>محله شامل</label><input id="puDistHood"></div>
            </div>
            <div class="pu-row">
              <div class="pu-field" style="width:120px"><label>حداقل قیمت</label><input id="puDistPMin" type="number" min="0"></div>
              <div class="pu-field" style="width:120px"><label>حداکثر قیمت</label><input id="puDistPMax" type="number" min="0"></div>
              <div class="pu-field" style="width:110px"><label>حداقل متراژ</label><input id="puDistAMin" type="number" min="0"></div>
              <div class="pu-field" style="width:110px"><label>حداکثر متراژ</label><input id="puDistAMax" type="number" min="0"></div>
            </div>
            <div class="pu-note">قیمت: «قیمت فروش» یا «قیمت کل» آگهی. شرط‌های پرنشده نادیده گرفته می‌شوند.</div>
          </div>
        </details>
        <label class="pm-toggle"><input type="checkbox" id="puDistEnabled" checked> قانون فعال باشد</label>
        <div class="pu-row" style="margin:12px 0">
          <button class="pu-btn" id="puDistPreviewBtn" type="button">پیش‌نمایش نتیجه (بدون انتشار)</button>
        </div>
        <div id="puDistPreviewBox" class="pu-note hidden"></div>
      </div>
      <div class="pm-foot">
        <div class="pu-row">
          <button class="pu-btn dang" id="puDistDelete" type="button">حذف قانون</button>
          <button class="pu-btn" id="puDistCancel" type="button">انصراف</button>
        </div>
        <button class="pm-main" id="puDistSave" type="button">ذخیرهٔ قانون</button>
      </div>
    </div>
  </div>

  <!-- مودال «ثبت برنامهٔ انتشار» — طراحی UI/UX یکپارچه -->
  <div class="pu-modal" id="puComposerModal">
    <div class="box">
      <div class="pm-head">
        <div>
          <div class="pm-crumb">بازاریابی ← انتشار ← ثبت برنامهٔ انتشار</div>
          <h3 style="margin:0;font-size:1.05rem">ثبت برنامهٔ انتشار</h3>
        </div>
        <button class="pu-btn sm" id="puComposerClose" type="button" aria-label="بستن">✕</button>
      </div>

      <div class="pm-body" id="puCompBody">

        <!-- ۱. انتخاب نوع محتوا -->
        <section class="pm-sec" id="pmSecType">
          <h3>۱. چه چیزی می‌خواهید منتشر کنید؟</h3>
          <div class="pm-choices">
            <label class="pm-choice on" data-type="ad">
              <input type="radio" name="puCompType" value="ad" checked>
              <div><div class="t">🏠 آگهی ملک</div><div class="d">انتشار یکی از آگهی‌های موجود در ملکینو</div></div>
            </label>
            <label class="pm-choice" data-type="content">
              <input type="radio" name="puCompType" value="content">
              <div><div class="t">📝 مطلب عمومی</div><div class="d">انتشار نوشته، عکس یا اطلاعیه بدون وابستگی به آگهی ملک</div></div>
            </label>
          </div>
        </section>

        <!-- ۲. انتخاب آگهی -->
        <section class="pm-sec" id="pmSecAd">
          <h3>۲. انتخاب آگهی</h3>
          <p class="pm-sub">جست‌وجو با عنوان، شناسه، محله، متراژ یا قیمت — مشخصات آگهی به‌صورت خودکار خوانده می‌شود.</p>
          <div class="pu-row">
            <input id="puCompAdSearch" placeholder="مثلاً: باغ ویلا، 101، شاهرود، 750، 6000000000…" style="flex:1;min-width:220px;background:var(--pu-s2);border:1px solid var(--pu-line);border-radius:10px;color:var(--pu-text);padding:9px 10px">
            <button class="pu-btn" id="puCompAdSearchBtn" type="button">جست‌وجو</button>
          </div>
          <div id="puCompAdResults" class="pm-results"></div>
          <div id="puCompAdChosen" style="display:none"></div>
          <div id="puCompAdEmpty" class="pu-empty">هنوز آگهی‌ای انتخاب نشده است — از جست‌وجوی بالا یک آگهی انتخاب کنید.</div>
          <div id="puCompAdWarn"></div>
        </section>

        <!-- ۳. محتوای عمومی -->
        <section class="pm-sec hidden" id="pmSecContent">
          <h3>۳. محتوای عمومی</h3>
          <p class="pm-sub">عنوان داخلی فقط برای مدیریت و جست‌وجوست و لزوماً در پیام نهایی نمی‌آید.</p>
          <div class="pu-field"><label>عنوان داخلی محتوا</label><input id="puContInTitle" maxlength="190" placeholder="مثلاً: اطلاعیهٔ تعطیلات"></div>
          <div class="pu-field"><label>متن قابل انتشار</label><textarea id="puContInBody" placeholder="متن پیام کانال…"></textarea></div>
          <div class="pu-field"><label>رسانه‌ها (با دکمهٔ آپلود اضافه کنید)</label>
            <div class="pu-row">
              <button class="pu-btn pri" id="puCompUploadBtn" type="button">⬆ آپلود عکس/ویدئو</button>
              <input type="file" id="puCompUploadFile" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4" multiple class="hidden">
            </div>
            <div id="puCompMediaChips" class="pu-badges"></div>
            <input id="puContInMedia" placeholder="نام فایل‌های آپلودشده (جداشده با ویرگول)">
          </div>
        </section>

        <!-- ۴. تصاویر قابل انتشار -->
        <section class="pm-sec" id="pmSecMedia">
          <h3>۴. تصاویر قابل انتشار</h3>
          <p class="pm-sub">مشخص کنید کدام تصاویر از این آگهی در پیام‌رسان‌ها منتشر شوند. این تنظیمات فقط برای انتشار است و تصویر اصلی آگهی را تغییر نمی‌دهد.</p>
          <div class="pm-choices">
            <label class="pm-choice on" data-mm="all"><input type="radio" name="puCompMM" value="all" checked>
              <div><div class="t">انتشار همهٔ تصاویر</div><div class="d">تمام تصاویر آگهی منتشر می‌شوند؛ محدودیت‌های کانال مقصد بررسی خواهد شد.</div></div></label>
            <label class="pm-choice" data-mm="selected"><input type="radio" name="puCompMM" value="selected">
              <div><div class="t">انتخاب دستی تصاویر</div><div class="d">روی هر تصویر کلیک کنید تا انتخاب/حذف شود؛ ترتیب با فلش‌ها.</div></div></label>
            <label class="pm-choice" data-mm="none"><input type="radio" name="puCompMM" value="none">
              <div><div class="t">انتشار بدون تصویر</div><div class="d">فقط متن و لینک آگهی منتشر می‌شود.</div></div></label>
          </div>
          <div id="puCompMediaWrap">
            <div class="pu-row" style="margin-top:10px">
              <span class="pm-badge gold" id="puCompMediaCount">۰ تصویر انتخاب شده است.</span>
            </div>
            <div class="pm-gallery" id="puCompGallery"></div>
            <input type="file" id="puCompUpload" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
            <div id="puCompGalleryEmpty" class="pu-empty">این آگهی تصویری ندارد — با «＋ افزودن تصویر» عکس دلخواه اضافه کنید یا «انتشار بدون تصویر» را انتخاب کنید.</div>
          </div>
        </section>

        <!-- ۵. کانال‌های انتشار -->
        <section class="pm-sec" id="pmSecChannels">
          <h3>۵. در کدام پیام‌رسان‌ها منتشر شود؟</h3>
          <div class="pm-chan-grid" id="puCompChans"></div>
          <div id="puCompChanErr" class="pm-err"></div>
          <label class="pm-toggle" style="margin-top:10px">
            <input type="checkbox" id="puCompPerChannel"> تنظیمات اختصاصی هر کانال (متن/تصاویر/زمان مستقل)
          </label>
        </section>

        <!-- ۶. تنظیمات اختصاصی هر کانال -->
        <section class="pm-sec hidden" id="pmSecPer">
          <h3>۶. تنظیمات اختصاصی هر کانال</h3>
          <p class="pm-sub">هر تغییر فقط روی همان کانال اثر دارد. اگر چیزی را تغییر ندهید، از مقادیر کلی استفاده می‌شود.</p>
          <div id="puCompPerTabs" class="pm-tabs"></div>
          <div id="puCompPerBody"></div>
          <div class="pu-row" style="margin-top:8px">
            <button class="pu-btn sm" id="puCompCopyFrom" type="button">کپی تنظیمات از…</button>
            <select id="puCompCopySrc" style="background:var(--pu-s2);color:var(--pu-text);border:1px solid var(--pu-line);border-radius:10px;padding:5px 8px">
              <option value="telegram">تلگرام</option><option value="bale">بله</option><option value="eitaa">ایتا</option>
            </select>
          </div>
        </section>

        <!-- ۷. زمان انتشار -->
        <section class="pm-sec" id="pmSecWhen">
          <h3>۷. چه زمانی منتشر شود؟</h3>
          <div class="pm-choices">
            <label class="pm-choice on" data-wm="now"><input type="radio" name="puCompWM" value="now" checked>
              <div><div class="t">انتشار فوری</div><div class="d">محتوا پس از تأیید نهایی برای انتشار ارسال شود.</div></div></label>
            <label class="pm-choice" data-wm="schedule"><input type="radio" name="puCompWM" value="schedule">
              <div><div class="t">زمان‌بندی برای آینده</div><div class="d">تاریخ و ساعت انتشار را مشخص کنید.</div></div></label>
          </div>
          <div id="puCompWhenWrap" class="hidden" style="margin-top:10px">
            <div class="pu-row">
              <div class="pu-field" style="flex:1;min-width:150px"><label>تاریخ شمسی</label>
                <input id="puCompWhen" placeholder="۱۴۰۵/۰۷/۲۰" data-mk-jalali></div>
              <div class="pu-field" style="width:90px"><label>ساعت</label>
                <select id="puCompHour"></select></div>
              <div class="pu-field" style="width:90px"><label>دقیقه</label>
                <select id="puCompMinute"></select></div>
            </div>
            <div class="pm-sub" id="puCompTzNote">ساعت به وقت ایران (‎+۰۳:۳۰‎) ثبت و اجرا می‌شود.</div>
            <div id="puCompWhenErr" class="pm-err"></div>
            <label class="pm-toggle"><input type="checkbox" id="puCompPerTime"> تعیین زمان جداگانه برای هر کانال</label>
            <div id="puCompPerTimes"></div>
          </div>
        </section>

        <!-- ۸. پیش‌نمایش انتشار -->
        <section class="pm-sec" id="pmSecPreview">
          <h3>۸. پیش‌نمایش انتشار <span class="pm-badge">پیش‌نمایش تقریبی</span></h3>
          <p class="pm-sub">متن نهایی، تصاویر منتخب و لینک آگهی برای هر پیام‌رسان — با تغییر فرم به‌روز می‌شود.</p>
          <div class="pm-tabs" id="puCompPrevTabs"></div>
          <div class="pm-preview" id="puCompPrevBody"><div class="pu-empty">ابتدا محتوا و کانال‌ها را انتخاب کنید.</div></div>
        </section>

        <!-- ۹. تنظیمات پیشرفته -->
        <details class="pm-sec pm-adv" id="pmSecAdv">
          <summary>۹. تنظیمات پیشرفته</summary>
          <div style="margin-top:10px">
            <div class="pu-field"><label>قالب پیام</label>
              <div class="pu-row">
                <select id="puCompTemplate" style="flex:1;background:var(--pu-s2);color:var(--pu-text);border:1px solid var(--pu-line);border-radius:10px;padding:8px"><option value="">بدون قالب</option></select>
                <button class="pu-btn" id="puCompTplFill" type="button">درج متن قالب</button>
              </div>
            </div>
            <div class="pu-field"><label>لینک اختصاصی ردیابی کلیک</label>
              <select id="puCompTrack"><option value="1">فعال</option><option value="0">غیرفعال</option></select>
            </div>
            <div class="pu-note">فاصلهٔ بین انتشارها فقط در «انتشار گروهی» کاربرد دارد و در این فرم نیست.</div>
          </div>
        </details>

        <!-- ۱۰. خلاصهٔ نهایی -->
        <section class="pm-sec" id="pmSecSummary">
          <h3>۱۰. خلاصهٔ نهایی</h3>
          <div class="pm-sum" id="puCompSummary"></div>
        </section>
      </div>

      <div class="pm-foot">
        <div class="pu-row">
          <button class="pu-btn" id="puCompDraft" type="button">ذخیرهٔ پیش‌نویس</button>
          <button class="pu-btn" id="puCompCancel" type="button">انصراف</button>
        </div>
        <button class="pm-main" id="puCompSave" type="button">تأیید و انتشار</button>
      </div>
    </div>
  </div>

  <!-- مودال اکشن مقصد -->
  <div class="pu-modal" id="puTargetModal">
    <div class="box" style="max-width:520px">
      <div class="pu-row" style="justify-content:space-between"><h3 style="margin:0" id="puTargetTitle">عملیات مقصد</h3><button class="pu-btn sm" id="puTargetClose" type="button">✕</button></div>
      <div id="puTargetInfo" class="pu-note"></div>
      <div class="pu-field"><label>زمان جدید (شمسی)</label><input id="puTargetWhen" placeholder="۱۴۰۵/۰۷/۲۰ ۱۸:۰۰"></div>
      <div class="pu-field"><label>متن جدید (برای ویرایش پست)</label><textarea id="puTargetText"></textarea></div>
      <div class="pu-row" style="flex-wrap:wrap">
        <button class="pu-btn" id="puTargetReschedule" type="button">🗓 زمان‌بندی مجدد</button>
        <button class="pu-btn" id="puTargetRetry" type="button">↻ تلاش مجدد</button>
        <button class="pu-btn" id="puTargetEdit" type="button">✏ ویرایش پست</button>
        <button class="pu-btn dang" id="puTargetDelete" type="button">🗑 حذف پست</button>
        <button class="pu-btn dang" id="puTargetCancel" type="button">✕ لغو</button>
      </div>
    </div>
  </div>

  <div id="puToast" style="display:none;position:fixed;bottom:18px;right:18px;background:#16233f;border:1px solid #38bdf8;border-radius:10px;padding:11px 16px;font-size:.84rem;z-index:10000;max-width:340px"></div>

  <script>
  // راه‌اندازی پس از بارگذاری اسکریپت تب (asset loader تب‌ها)
  window.MELKINO_PUBLISH_BOOT = function () {
    if (window.melkinoPublishCenterBooted) return;
    window.melkinoPublishCenterBooted = true;
    if (typeof window.melkinoPublishCenterInit === 'function') {
      window.melkinoPublishCenterInit();
    }
  };
  if (typeof window.melkinoPublishCenterInit === 'function') window.MELKINO_PUBLISH_BOOT();
  </script>
</div>
