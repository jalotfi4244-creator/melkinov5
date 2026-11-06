<?php
/**
 * ایجنت هوشمند اینستاگرام ملکینو — رابط پنل (بخش «بازاریابی»)
 * داخل admin-panel.php include می‌شود؛ از نشست/سطح دسترسی موجود استفاده می‌کند.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?>
<div class="admin-hero" style="margin-bottom:14px">
    <h2 class="admin-hero-title">📸 ایجنت هوشمند اینستاگرام ملکینو</h2>
    <p class="admin-hero-sub" style="margin-top:6px">تولید محتوای فارسی از آگهی‌های واقعی سایت، صف تأیید، انتشار رسمی یا دستی — پیج هدف: <bdi>@melkino_shahrood</bdi></p>
</div>

<style>
/* رنگ‌بندی خودکار با تم روشن/تیرهٔ پنل — همهٔ رنگ‌ها صریح تا متن هرگز نامرئی نشود */
.iga-wrap{display:flex;flex-direction:column;gap:14px;color:var(--text-primary,#1d2b28)}
.iga-tabs{display:flex;flex-wrap:wrap;gap:8px}
.iga-tab{padding:10px 14px;border-radius:12px;border:1px solid var(--border,rgba(212,175,55,.25));background:var(--surface,#fff);color:var(--text-primary,#3d4a47);font-weight:800;font-size:12px;cursor:pointer}
.iga-tab.is-on{background:linear-gradient(135deg,var(--primary,#0E7C6E),#0b5d5b);color:#fff;border-color:transparent}
.iga-card{background:var(--surface,#fff);border:1px solid var(--border,rgba(0,0,0,.07));border-radius:18px;padding:16px;color:var(--text-primary,#1d2b28)}
.iga-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
.iga-stat{background:var(--surface-soft,#eef5f3);border:1px solid var(--border,rgba(0,0,0,.05));border-radius:14px;padding:12px;color:var(--text-primary,#1d2b28)}
.iga-stat b{font-size:20px;display:block;color:var(--primary,#0E7C6E)}
.iga-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.iga-btn{padding:9px 14px;border-radius:11px;border:none;background:linear-gradient(135deg,var(--primary,#0E7C6E),#0b5d5b);color:#fff;font-weight:800;font-size:12px;cursor:pointer}
.iga-btn.ghost{background:var(--surface-soft,#f4f5f5);border:1px solid var(--border,rgba(0,0,0,.15));color:var(--text-primary,#33403d)}
.iga-btn.gold{background:linear-gradient(135deg,#d4af37,#b8912b);color:#241f07}
.iga-btn:disabled{opacity:.5;cursor:not-allowed}
.iga-input,textarea.iga-input,select.iga-input{padding:10px;border-radius:11px;border:1px solid var(--border,rgba(0,0,0,.15));font-family:inherit;font-size:12px;width:100%;box-sizing:border-box;background:var(--surface,#fff);color:var(--text-primary,#1d2b28)}
textarea.iga-input{line-height:1.9}
.iga-input:focus,textarea.iga-input:focus,select.iga-input:focus{outline:2px solid var(--primary,#0E7C6E);outline-offset:1px}
.iga-badge{display:inline-block;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800}
.iga-badge.ok{background:#e6f6ee;color:#0b7a48}
.iga-badge.warn{background:#fdf3d7;color:#8a6116}
.iga-badge.bad{background:#fdeaea;color:#a12828}
.iga-table{width:100%;border-collapse:collapse;font-size:12px;color:var(--text-primary,#1d2b28)}
.iga-table th,.iga-table td{padding:9px 8px;border-bottom:1px solid var(--border,rgba(0,0,0,.07));text-align:right;vertical-align:top}
.iga-table th{color:var(--text-secondary,#5b6b68);font-weight:800}
.iga-muted{color:var(--text-secondary,#6b7280);font-size:11px}
.iga-preview{background:var(--surface-soft,rgba(0,0,0,.04));border:1px solid var(--border,rgba(0,0,0,.06));border-radius:12px;padding:12px;white-space:pre-wrap;font-size:12.5px;line-height:2;max-height:340px;overflow:auto;color:var(--text-primary,#1d2b28)}
.iga-ad{border:1px solid var(--border,rgba(0,0,0,.08));border-radius:14px;padding:11px;cursor:pointer;color:var(--text-primary,#1d2b28)}
.iga-ad:hover{border-color:var(--primary,#0E7C6E)}
.iga-sec-title{font-size:14px;font-weight:900;margin:2px 0 10px;color:var(--text-primary,#1d2b28)}
.iga-reason{color:var(--danger,#c0392b)}
</style>

<div class="iga-wrap" id="igaRoot" dir="rtl">
    <div class="iga-tabs" id="igaTabs">
        <button class="iga-tab is-on" data-s="dash">داشبورد</button>
        <button class="iga-tab" data-s="ads">انتخاب آگهی</button>
        <button class="iga-tab" data-s="studio">استودیوی محتوا</button>
        <button class="iga-tab" data-s="queue">تقویم و صف</button>
        <button class="iga-tab" data-s="review">صف تأیید</button>
        <button class="iga-tab" data-s="history">تاریخچه انتشار</button>
        <button class="iga-tab" data-s="insights">تحلیل عملکرد</button>
        <button class="iga-tab" data-s="errors">گزارش خطاها</button>
        <button class="iga-tab" data-s="conn">اتصال اینستاگرام</button>
        <button class="iga-tab" data-s="settings">تنظیمات ایجنت</button>
    </div>
    <div class="iga-card" id="igaBody"><div class="iga-muted">در حال بارگذاری…</div></div>
</div>

<script>
(function () {
    var API = 'instagram-agent-api.php';
    var state = { s: 'dash', ad: null, content: null, genWarnings: null };
    var body = document.getElementById('igaBody');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function parseResp(r) {
        return r.text().then(function (t) {
            var j = null;
            try { j = JSON.parse(t); } catch (e) { j = null; }
            if (!j || typeof j !== 'object') {
                return { success: false, message: 'پاسخ نامعتبر از سرور (HTTP ' + r.status + ')' + (t ? ' — ' + String(t).replace(/\s+/g, ' ').slice(0, 120) : '') };
            }
            if (!j.success && !j.message) {
                j.message = 'خطای ناشناختهٔ سرور (HTTP ' + r.status + ')';
            }
            return j;
        });
    }
    function api(action, opts) {
        opts = opts || {};
        var method = opts.method || 'GET';
        var url = API + '?action=' + encodeURIComponent(action);
        if (opts.qs) {
            Object.keys(opts.qs).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.qs[k] == null ? '' : opts.qs[k]);
            });
        }
        var init = { method: method, credentials: 'same-origin', headers: {} };
        if (method === 'POST') {
            // ارسال urlencoded به‌جای multipart — سازگاری بیشتر با هاست/WAF و پاسخ JSON تمیز
            var body = new URLSearchParams();
            body.append('action', action);
            Object.keys(opts.data || {}).forEach(function (k) { body.append(k, opts.data[k] == null ? '' : opts.data[k]); });
            if (window.MELKINO_CSRF) body.append('csrf_token', window.MELKINO_CSRF);
            init.body = body;
        }
        return fetch(url, init).then(parseResp, function () {
            if (method !== 'GET') {
                return { success: false, message: 'خطای ارتباط با سرور (پاسخی دریافت نشد). اتصال اینترنت/هاست را بررسی و دوباره تلاش کنید.' };
            }
            // یک تلاش مجدد فقط برای خواندن‌ها
            return fetch(url, init).then(parseResp, function () {
                return { success: false, message: 'خطای ارتباط با سرور (پاسخی دریافت نشد). اتصال اینترنت/هاست را بررسی و دوباره تلاش کنید.' };
            });
        });
    }
    function badge(st) {
        var map = {
            draft: 'warn', pending_review: 'warn', approved: 'ok', scheduled: 'ok',
            publishing: 'warn', published: 'ok', published_manual: 'ok',
            failed: 'bad', cancelled: 'bad', needs_attention: 'bad'
        };
        var fa = {
            draft: 'پیش‌نویس', pending_review: 'منتظر تأیید', approved: 'تأییدشده',
            scheduled: 'زمان‌بندی‌شده', publishing: 'در حال انتشار', published: 'منتشرشده (API)',
            published_manual: 'منتشرشده (دستی)', failed: 'ناموفق', cancelled: 'لغوشده', needs_attention: 'نیازمند بررسی'
        };
        return '<span class="iga-badge ' + (map[st] || 'warn') + '">' + esc(fa[st] || st) + '</span>';
    }
    function alertMsg(msg, ok) {
        var cls = ok === true ? 'ok' : (ok === 'warn' ? 'warn' : 'bad');
        return '<div class="iga-badge ' + cls + '" style="padding:9px 12px;display:block;line-height:1.9">' + esc(msg) + '</div>';
    }

    /* ---------------- pages ---------------- */
    var pages = {
        dash: function () {
            api('overview').then(function (j) {
                if (!j.success) { body.innerHTML = alertMsg(j.message); return; }
                var s = j.stats, c = j.connection;
                body.innerHTML =
                    '<div class="iga-sec-title">داشبورد اینستاگرام</div>' +
                    '<div class="iga-grid">' +
                    '<div class="iga-stat"><b>' + s.publishable_ads + '</b>آگهی قابل انتشار</div>' +
                    '<div class="iga-stat"><b>' + s.contents_total + '</b>محتوای تولیدشده</div>' +
                    '<div class="iga-stat"><b>' + s.drafts + '</b>پیش‌نویس</div>' +
                    '<div class="iga-stat"><b>' + s.pending_review + '</b>منتظر تأیید</div>' +
                    '<div class="iga-stat"><b>' + s.scheduled + '</b>زمان‌بندی‌شده</div>' +
                    '<div class="iga-stat"><b>' + s.published_ok + '</b>انتشار موفق</div>' +
                    '<div class="iga-stat"><b>' + s.failed + '</b>انتشار ناموفق</div>' +
                    '<div class="iga-stat"><b>' + s.needs_attention + '</b>نیازمند بررسی</div>' +
                    '</div>' +
                    '<div style="margin-top:12px" class="iga-row">' +
                    '<span class="iga-badge ' + (c.connected ? 'ok' : 'warn') + '">' + (c.connected ? 'اتصال API برقرار' : 'حالت انتشار دستی (API متصل نیست)') + '</span>' +
                    '<span class="iga-badge ' + (c.auto_publish === 'on' ? 'ok' : 'warn') + '">انتشار خودکار: ' + (c.auto_publish === 'on' ? 'روشن' : 'خاموش (پیش‌فرض)') + '</span>' +
                    '<span class="iga-badge warn">تولید محتوا: ' + (c.ai_provider === 'ai' ? 'هوش مصنوعی + قالب' : 'قالب‌محور') + '</span>' +
                    (c.last_publication_at ? '<span class="iga-muted">آخرین انتشار: ' + esc(c.last_publication_at) + '</span>' : '') +
                    '</div>' +
                    (j.recent_errors.length ? '<div style="margin-top:12px"><div class="iga-sec-title">آخرین خطاها</div><table class="iga-table"><tr><th>آگهی</th><th>خطا</th><th>زمان</th></tr>' +
                        j.recent_errors.map(function (e) {
                            return '<tr><td>' + esc(e.ad_id) + '</td><td>' + esc(e.last_error_friendly) + '</td><td class="iga-muted">' + esc(e.created_at) + '</td></tr>';
                        }).join('') + '</table></div>' : '');
            });
        },
        ads: function () {
            body.innerHTML =
                '<div class="iga-sec-title">انتخاب و مدیریت آگهی‌ها</div>' +
                '<div class="iga-row" style="margin-bottom:10px">' +
                '<input class="iga-input" id="igaQ" placeholder="جستجو (عنوان/کد/محله)" style="flex:1;min-width:180px">' +
                '<select class="iga-input" id="igaTx" style="max-width:150px"><option value="">همهٔ معامله‌ها</option><option>فروش</option><option>رهن و اجاره</option></select>' +
                '<button class="iga-btn" id="igaSearch">جستجو</button></div>' +
                '<div class="iga-grid" id="igaAds"><div class="iga-muted">در حال بارگذاری…</div></div>';
            function load() {
                api('ads', { qs: {
                    q: document.getElementById('igaQ').value,
                    transaction_type: document.getElementById('igaTx').value
                } }).then(function (j) {
                        var host = document.getElementById('igaAds');
                        if (!j.success) { host.innerHTML = alertMsg(j.message); return; }
                        host.innerHTML = j.ads.map(function (a) {
                            var e = a.eligibility;
                            return '<div class="iga-ad" data-id="' + esc(a.id) + '">' +
                                '<b>' + esc(a.title || a.id) + '</b>' +
                                '<div class="iga-muted">' + esc(a.transaction_type) + ' · ' + esc(a.property_type) + ' · ' + esc(a.location) + '</div>' +
                                '<div style="margin-top:6px">' +
                                (e.eligible
                                    ? '<span class="iga-badge ' + (e.text_only ? 'warn' : 'ok') + '">' + (e.text_only ? 'قابل نوشتن — فقط متن (' + e.score + ')' : 'مناسب انتشار (' + e.score + ')') + '</span>'
                                    : '<span class="iga-badge bad">نامناسب</span>') +
                                (a.images.length ? '<span class="iga-badge ok">' + a.images.length + ' تصویر</span>' : '<span class="iga-badge warn">بدون تصویر</span>') +
                                '</div>' +
                                (e.reasons && e.reasons.length ? '<div class="iga-muted iga-reason" style="margin-top:5px">' + esc(e.reasons.join(' ')) + '</div>' : '') +
                                (e.warnings && e.warnings.length ? '<div class="iga-muted" style="margin-top:5px">' + esc(e.warnings.join(' ')) + '</div>' : '') +
                                '<div class="iga-row" style="margin-top:8px">' +
                                '<button class="iga-btn gold iga-gen" data-id="' + esc(a.id) + '" data-kind="post">ساخت پست</button>' +
                                '<button class="iga-btn ghost iga-gen" data-id="' + esc(a.id) + '" data-kind="carousel">کاروسل</button>' +
                                '<button class="iga-btn ghost iga-gen" data-id="' + esc(a.id) + '" data-kind="reels">ریلز</button>' +
                                '<button class="iga-btn ghost iga-gen" data-id="' + esc(a.id) + '" data-kind="story">استوری</button>' +
                                '</div></div>';
                        }).join('') || '<div class="iga-muted">آگهی‌ای یافت نشد.</div>';
                    });
            }
            document.getElementById('igaSearch').onclick = load;
            load();
            body.onclick = function (ev) {
                var g = ev.target.closest ? ev.target.closest('.iga-gen') : null;
                if (!g) return;
                g.disabled = true;
                api('generate', { method: 'POST', data: { ad_id: g.dataset.id, content_type: g.dataset.kind } }).then(function (j) {
                    g.disabled = false;
                    if (!j.success) { body.insertAdjacentHTML('afterbegin', alertMsg(j.message)); return; }
                    state.content = j.content_id;
                    state.genWarnings = j.warnings || [];
                    nav('studio');
                });
            };
        },
        studio: function () {
            api('contents').then(function (j) {
                if (!j.success) { body.innerHTML = alertMsg(j.message); return; }
                var genWarn = (state.genWarnings && state.genWarnings.length)
                    ? alertMsg(state.genWarnings.join(' '), 'warn') : '';
                state.genWarnings = null;
                body.innerHTML = genWarn +
                    '<div class="iga-sec-title">استودیوی تولید محتوا</div>' +
                    (j.contents.length ? '<select class="iga-input" id="igaPick" style="margin-bottom:10px">' +
                        j.contents.map(function (c) {
                            return '<option value="' + c.id + '"' + (String(state.content) === String(c.id) ? ' selected' : '') + '>#' + c.id + ' — ' + esc(c.title) + ' (' + esc(c.content_type) + ')</option>';
                        }).join('') + '</select>' : '<div class="iga-muted">هنوز محتوایی ساخته نشده — از «انتخاب آگهی» شروع کنید.</div>') +
                    '<div id="igaStudioBox"></div>';
                function loadC() {
                    var id = document.getElementById('igaPick') ? document.getElementById('igaPick').value : state.content;
                    if (!id) return;
                    api('content', { qs: { id: id } }).then(function (rj) {
                        if (!rj.success) return;
                        var c = rj.content;
                        state.content = c.id;
                        document.getElementById('igaStudioBox').innerHTML =
                            '<div class="iga-row" style="margin-bottom:8px">' + badge(c.status) +
                            '<span class="iga-muted">آگهی ' + esc(c.ad_id) + (c.ad_exists ? '' : ' — ⚠ حذف‌شده از سایت') + '</span>' +
                            '<span class="iga-muted">ژنراتور: ' + esc(c.generator) + '</span></div>' +
                            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">' +
                            '<div><label class="iga-muted">عنوان</label><input class="iga-input" id="f_title" value="' + esc(c.title) + '"></div>' +
                            '<div><label class="iga-muted">هوک</label><input class="iga-input" id="f_hook" value="' + esc(c.hook) + '"></div>' +
                            '</div>' +
                            '<label class="iga-muted">کپشن نهایی</label><textarea class="iga-input" id="f_caption" rows="8">' + esc(c.caption) + '</textarea>' +
                            '<label class="iga-muted">دعوت به اقدام (CTA)</label><input class="iga-input" id="f_cta" value="' + esc(c.cta) + '">' +
                            '<label class="iga-muted">هشتگ‌ها</label><input class="iga-input" id="f_hashtags" value="' + esc(c.hashtags) + '">' +
                            (c.video_scenario ? '<label class="iga-muted">سناریوی ویدئو</label><div class="iga-preview">' + esc(c.video_scenario) + '</div>' : '') +
                            '<div class="iga-row" style="margin-top:10px">' +
                            '<button class="iga-btn" id="igaSave">ذخیرهٔ ویرایش</button>' +
                            '<button class="iga-btn gold" id="igaApprove">تأیید نهایی</button>' +
                            '<button class="iga-btn ghost" id="igaBack">بازگشت به پیش‌نویس</button>' +
                            '<button class="iga-btn ghost" id="igaAttention">نیازمند بررسی</button>' +
                            '<button class="iga-btn ghost" id="igaManual">ثبت انتشار دستی</button>' +
                            '<button class="iga-btn ghost" id="igaCopy">کپی کپشن</button>' +
                            '<button class="iga-btn gold" id="igaCopyPkg">کپی بستهٔ انتشار دستی</button>' +
                            '<button class="iga-btn ghost" id="igaToStudio" title="باز کردن همین آگهی در استودیوی استوری">ویرایش تصویر در استودیو</button>' +
                            '</div>' +
                            '<div class="iga-sec-title" style="margin-top:14px">پیش‌نمایش کپشن</div><div class="iga-preview">' + esc(c.caption) + '</div>' +
                            '<div class="iga-sec-title" style="margin-top:14px">رسانه‌ها</div>' +
                            (c.media.length ? '<div class="iga-grid">' + c.media.map(function (m) {
                                if (m.studio_export_id) {
                                    // خروجی استودیو: فایل خصوصی است و فقط با نشست ادمین قابل دانلود است
                                    return '<div class="iga-ad"><div>خروجی استودیو #' + esc(m.studio_export_id) + '</div><div class="iga-muted"><a href="ig-studio-api.php?action=export_file&id=' + encodeURIComponent(m.studio_export_id) + '&download=1">دانلود تصویر ۱۰۸۰×۱۹۲۰</a> · انتشار دستی (استوری)</div></div>';
                                }
                                return '<div class="iga-ad"><div>' + esc(m.file) + '</div><div class="iga-muted">' + (m.public_url ? '✅ ' + esc(m.public_url) : '⚠ بدون آدرس عمومی — انتشار دستی') + '</div></div>';
                            }).join('') + '</div>' : '<div class="iga-muted">این محتوا فقط-متن است: تصویر مجاز برای انتشار عمومی ندارد. می‌توانید کپشن را با «کپی بستهٔ انتشار دستی» کپی و به‌صورت دستی منتشر کنید، یا در مدیریت تصاویر آگهی گزینهٔ «انتشار عمومی» را فعال کنید.</div>');
                        document.getElementById('igaSave').onclick = function () {
                            api('save_content', { method: 'POST', data: {
                                content_id: c.id, title: f('f_title'), hook: f('f_hook'), caption: f('f_caption'), cta: f('f_cta'), hashtags: f('f_hashtags')
                            } }).then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'ذخیره شد' : z.message, z.success)); loadC(); });
                        };
                        document.getElementById('igaApprove').onclick = function () {
                            // مسیر استاندارد: پیش‌نویس/نیازمند بررسی → منتظر تأیید → تأییدشده
                            var first = (c.status === 'draft' || c.status === 'needs_attention')
                                ? api('set_status', { method: 'POST', data: { content_id: c.id, to: 'pending_review' } })
                                : Promise.resolve({ success: true });
                            first.then(function () {
                                return api('set_status', { method: 'POST', data: { content_id: c.id, to: 'approved' } });
                            }).then(function (z) {
                                body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'تأیید شد' : z.message, z.success));
                                loadC();
                            });
                        };
                        document.getElementById('igaBack').onclick = function () {
                            api('set_status', { method: 'POST', data: { content_id: c.id, to: 'draft' } })
                                .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'بازگشت به پیش‌نویس' : z.message, z.success)); loadC(); });
                        };
                        document.getElementById('igaAttention').onclick = function () {
                            api('set_status', { method: 'POST', data: { content_id: c.id, to: 'needs_attention', review_note: 'بررسی توسط مدیر' } })
                                .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'ثبت شد' : z.message, z.success)); loadC(); });
                        };
                        document.getElementById('igaManual').onclick = function () {
                            var link = prompt('لینک پست منتشرشده (اختیاری — https):', '');
                            if (link === null) return;
                            api('publish_manual', { method: 'POST', data: { content_id: c.id, manual_link: link } })
                                .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'انتشار دستی ثبت شد' : z.message, z.success)); loadC(); });
                        };
                        document.getElementById('igaToStudio').onclick = function () {
                            try { sessionStorage.setItem('igsOpenAd', c.ad_id); } catch (err) { }
                            window.dispatchEvent(new CustomEvent('igs:open-ad', { detail: { id: c.ad_id } }));
                            if (typeof window.switchTab === 'function') { window.switchTab('story'); }
                        };
                        document.getElementById('igaCopy').onclick = function () {
                            (navigator.clipboard ? navigator.clipboard.writeText(c.caption) : Promise.resolve()).then(function () {
                                body.insertAdjacentHTML('afterbegin', alertMsg('کپشن کپی شد', true));
                            });
                        };
                        document.getElementById('igaCopyPkg').onclick = function () {
                            var pkg = c.caption || '';
                            if (c.video_scenario) pkg += '\n\n—— سناریوی ویدئو ——\n' + c.video_scenario;
                            if (c.on_image_text) pkg += '\n\n—— متن روی تصویر ——\n' + c.on_image_text;
                            if (c.hashtags && (c.caption || '').indexOf(c.hashtags) === -1) pkg += '\n\n' + c.hashtags;
                            if (c.media && c.media.length) {
                                pkg += '\n\n—— تصاویر (در پست اینستاگرام اضافه کنید) ——\n' + c.media.map(function (m) {
                                    if (m.studio_export_id) {
                                        return new URL('ig-studio-api.php?action=export_file&id=' + m.studio_export_id + '&download=1', location.href).href + ' (نیاز به ورود به پنل)';
                                    }
                                    return (m.public_url || m.file);
                                }).join('\n');
                            } else {
                                pkg += '\n\n(این محتوا فقط-متن است — بدون تصویر مجاز انتشار عمومی)';
                            }
                            (navigator.clipboard ? navigator.clipboard.writeText(pkg) : Promise.resolve()).then(function () {
                                body.insertAdjacentHTML('afterbegin', alertMsg('بستهٔ انتشار دستی کپی شد — در اینستاگرام پست کنید و سپس «ثبت انتشار دستی» را بزنید', true));
                            });
                        };
                    });
                }
                function f(id) { return document.getElementById(id).value; }
                if (document.getElementById('igaPick')) {
                    document.getElementById('igaPick').onchange = loadC;
                    loadC();
                }
            });
        },
        queue: function () {
            api('queue').then(function (j) {
                if (!j.success) { body.innerHTML = alertMsg(j.message); return; }
                body.innerHTML = '<div class="iga-sec-title">تقویم و صف انتشار</div>' +
                    '<table class="iga-table"><tr><th>#</th><th>عنوان</th><th>وضعیت</th><th>زمان انتشار</th><th>عملیات</th></tr>' +
                    j.queue.map(function (c) {
                        return '<tr><td>' + c.id + '</td><td>' + esc(c.title) + '</td><td>' + badge(c.status) + '</td>' +
                            '<td class="iga-muted">' + esc(c.scheduled_at || '—') + '</td>' +
                            '<td><div class="iga-row">' +
                            '<button class="iga-btn ghost iga-sch" data-id="' + c.id + '">زمان‌بندی</button>' +
                            '<button class="iga-btn ghost iga-enq" data-id="' + c.id + '">افزودن به صف</button>' +
                            '<button class="iga-btn ghost iga-pub" data-id="' + c.id + '">انتشار اکنون</button>' +
                            '<button class="iga-btn ghost iga-can" data-id="' + c.id + '">لغو</button>' +
                            '</div></td></tr>';
                    }).join('') + '</table>' +
                    '<div class="iga-muted" style="margin-top:8px">انتشار خودکار فقط با تنظیم «روشن» در تنظیمات ایجنت و اجرای Cron انجام می‌شود؛ در حالت پیش‌فرض خاموش است.</div>';
                body.onclick = function (ev) {
                    var t = ev.target.closest ? ev.target.closest('button') : null;
                    if (!t) return;
                    var id = t.dataset.id;
                    if (t.classList.contains('iga-sch')) {
                        var when = prompt('زمان انتشار (مثال 2026-10-14 18:30):', '');
                        if (!when) return;
                        api('schedule', { method: 'POST', data: { content_id: id, scheduled_at: when } })
                            .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'زمان‌بندی شد' : z.message, z.success)); pages.queue(); });
                    } else if (t.classList.contains('iga-enq')) {
                        api('enqueue', { method: 'POST', data: { content_id: id } })
                            .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'به صف اضافه شد' : z.message, z.success)); pages.queue(); });
                    } else if (t.classList.contains('iga-pub')) {
                        if (!confirm('الان منتشر شود؟ (نیازمند اتصال API و تأیید محتوا)')) return;
                        api('publish_now', { method: 'POST', data: { content_id: id } })
                            .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'انجام شد' : z.message, z.success)); pages.queue(); });
                    } else if (t.classList.contains('iga-can')) {
                        api('set_status', { method: 'POST', data: { content_id: id, to: 'cancelled' } })
                            .then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'لغو شد' : z.message, z.success)); pages.queue(); });
                    }
                };
            });
        },
        review: function () {
            api('contents', { qs: { status: 'pending_review' } }).then(function (j) {
                body.innerHTML = '<div class="iga-sec-title">صف تأیید محتوا</div>' +
                    (j.contents.length ? '<table class="iga-table"><tr><th>#</th><th>عنوان</th><th>نوع</th><th>آگهی</th><th>عملیات</th></tr>' +
                        j.contents.map(function (c) {
                            return '<tr><td>' + c.id + '</td><td>' + esc(c.title) + '</td><td>' + esc(c.content_type) + '</td><td>' + esc(c.ad_id) + '</td><td><div class="iga-row">' +
                                '<button class="iga-btn gold iga-ok" data-id="' + c.id + '">تأیید</button>' +
                                '<button class="iga-btn ghost iga-no" data-id="' + c.id + '">بازگشت به پیش‌نویس</button>' +
                                '</div></td></tr>';
                        }).join('') + '</table>' : '<div class="iga-muted">موردی در صف تأیید نیست.</div>') +
                    '<div class="iga-row" style="margin-top:10px"><button class="iga-btn ghost" id="igaSubmitAll">ارسال همهٔ پیش‌نویس‌ها به صف تأیید</button></div>';
                body.onclick = function (ev) {
                    var t = ev.target.closest ? ev.target.closest('button') : null;
                    if (!t || !t.dataset.id) return;
                    api('set_status', { method: 'POST', data: { content_id: t.dataset.id, to: t.classList.contains('iga-ok') ? 'approved' : 'draft' } })
                        .then(function () { pages.review(); });
                };
                var all = document.getElementById('igaSubmitAll');
                if (all) all.onclick = function () {
                    api('contents', { qs: { status: 'draft' } }).then(function (dj) {
                        var seq = Promise.resolve();
                        dj.contents.forEach(function (c) {
                            seq = seq.then(function () {
                                return api('set_status', { method: 'POST', data: { content_id: c.id, to: 'pending_review' } });
                            });
                        });
                        seq.then(function () { pages.review(); });
                    });
                };
            });
        },
        history: function () {
            api('publications').then(function (j) {
                body.innerHTML = '<div class="iga-sec-title">تاریخچه انتشار</div>' +
                    (j.publications.length ? '<table class="iga-table"><tr><th>#</th><th>محتوا</th><th>آگهی</th><th>روش</th><th>وضعیت</th><th>شناسهٔ رسانه</th><th>زمان</th></tr>' +
                        j.publications.map(function (p) {
                            return '<tr><td>' + p.id + '</td><td>' + esc(p.content_title || p.content_id) + '</td><td>' + esc(p.ad_id) + '</td>' +
                                '<td>' + esc(p.mode === 'manual' ? 'دستی' : 'API') + '</td>' +
                                '<td>' + badge(p.status === 'published' ? 'published' : p.status) + '</td>' +
                                '<td class="iga-muted">' + esc(p.ig_media_id || p.ig_permalink || '—') + '</td>' +
                                '<td class="iga-muted">' + esc(p.finished_at || p.created_at) + '</td></tr>';
                        }).join('') + '</table>' : '<div class="iga-muted">هنوز انتشاری ثبت نشده است.</div>');
            });
        },
        insights: function () {
            body.innerHTML = '<div class="iga-sec-title">تحلیل عملکرد</div>' +
                '<div class="iga-muted" style="line-height:2">آمار فقط از Instagram Graph API (Insights) دریافت می‌شود. تا زمانی که حساب متصل نشده یا داده‌ای از متا برنگردد، هیچ عددی نمایش داده نمی‌شود (بدون آمار ساختگی).<br>' +
                'پس از انتشار با API، در تاریخچهٔ انتشار، شناسهٔ رسانه ثبت می‌شود و آمار همان رسانه اینجا نمایش داده خواهد شد.</div>' +
                '<div class="iga-row" style="margin-top:12px"><button class="iga-btn" id="igaIns">دریافت آمار آخرین پست‌های API</button></div><div id="igaInsBox" style="margin-top:10px"></div>';
            document.getElementById('igaIns').onclick = function () {
                api('publications').then(function (j) {
                    var withId = j.publications.filter(function (p) { return p.ig_media_id; }).slice(0, 6);
                    document.getElementById('igaInsBox').innerHTML = withId.length ?
                        '<div class="iga-muted">برای ' + withId.length + ' رسانهٔ دارای شناسه، Insights از API دریافت می‌شود (در اجرای Cron بعدی).</div>' :
                        '<div class="iga-badge warn">هنوز رسانه‌ای با شناسهٔ API منتشر نشده است.</div>';
                });
            };
        },
        errors: function () {
            api('errors').then(function (j) {
                body.innerHTML = '<div class="iga-sec-title">گزارش خطاها</div>' +
                    '<div class="iga-sec-title" style="font-size:12px">تلاش‌های ناموفق انتشار</div>' +
                    (j.failed.length ? '<table class="iga-table"><tr><th>#</th><th>آگهی</th><th>کد خطا</th><th>پیام</th><th>تلاش</th></tr>' +
                        j.failed.map(function (p) {
                            return '<tr><td>' + p.id + '</td><td>' + esc(p.ad_id) + '</td><td class="iga-muted">' + esc(p.last_error_code || '—') + '</td>' +
                                '<td>' + esc(p.last_error_friendly) + '</td><td>' + p.attempt_count + '/' + p.max_attempts + '</td></tr>';
                        }).join('') + '</table>' : '<div class="iga-muted">خطایی ثبت نشده است.</div>') +
                    '<div class="iga-sec-title" style="font-size:12px;margin-top:14px">محتواهای نیازمند بررسی</div>' +
                    (j.needs_attention.length ? '<table class="iga-table"><tr><th>#</th><th>آگهی</th><th>دلیل</th></tr>' +
                        j.needs_attention.map(function (c) {
                            return '<tr><td>' + c.id + '</td><td>' + esc(c.ad_id) + '</td><td>' + esc(c.review_note) + '</td></tr>';
                        }).join('') + '</table>' : '<div class="iga-muted">موردی نیست.</div>');
            });
        },
        conn: function () {
            api('settings').then(function (j) {
                var s = j.secrets_state;
                body.innerHTML = '<div class="iga-sec-title">تنظیمات اتصال اینستاگرام (Meta Graph API)</div>' +
                    '<div class="iga-muted" style="line-height:2">از روش رسمی «Instagram Graph API» با حساب حرفه‌ای استفاده می‌شود. هرگز رمز عبور اینستاگرام خواسته نمی‌شود. مقادیر محرمانه فقط در سرور ذخیره می‌شوند و اینجا فقط وضعیت‌شان نمایش داده می‌شود.</div>' +
                    '<div class="iga-grid" style="margin:10px 0">' +
                    '<div class="iga-stat"><b>' + (s.ig_app_id || '—') + '</b>شناسهٔ اپ (App ID)</div>' +
                    '<div class="iga-stat"><b>' + (s.ig_app_secret_set ? 'ثبت‌شده ✓' : '—') + '</b>App Secret</div>' +
                    '<div class="iga-stat"><b>' + (s.ig_access_token || '—') + '</b>دسترسی (Access Token)</div>' +
                    '<div class="iga-stat"><b>' + (s.ig_user_id || '—') + '</b>شناسهٔ حساب (IG User ID)</div>' +
                    '</div>' +
                    '<div class="iga-row">' +
                    '<input class="iga-input" id="k_app_id" placeholder="App ID" style="max-width:180px">' +
                    '<input class="iga-input" id="k_app_secret" placeholder="App Secret (تغییر دهید تا جایگزین شود)" style="max-width:220px">' +
                    '<input class="iga-input" id="k_token" placeholder="Access Token بلندمدت" style="max-width:260px">' +
                    '<input class="iga-input" id="k_uid" placeholder="IG User ID" style="max-width:180px">' +
                    '<button class="iga-btn" id="igaSaveConn">ذخیره</button>' +
                    '<button class="iga-btn ghost" id="igaDisc">قطع اتصال</button>' +
                    '</div>' +
                    '<div class="iga-muted" style="margin-top:10px;line-height:2">مجوزهای لازم: <bdi>instagram_basic</bdi>، <bdi>instagram_content_publish</bdi>، <bdi>pages_show_list</bdi> — از طریق اپ متا (developers.facebook.com) و اتصال حساب Business/Creator به فیس‌بوک پیج. توکن بلندمدت را با تمدید رسمی نگه دارید؛ از روش‌های غیررسمی استفاده نمی‌شود.</div>';
                document.getElementById('igaSaveConn').onclick = function () {
                    var d = {};
                    ['k_app_id:ig_app_id', 'k_app_secret:ig_app_secret', 'k_token:ig_access_token', 'k_uid:ig_user_id'].forEach(function (pair) {
                        var p = pair.split(':');
                        var v = document.getElementById(p[0]).value.trim();
                        if (v) d[p[1]] = v;
                    });
                    api('save_secrets', { method: 'POST', data: d }).then(function (z) {
                        body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'ذخیره شد (مقدار نمایش داده نمی‌شود)' : z.message, z.success));
                        if (z.success) setTimeout(pages.conn, 600);
                    });
                };
                document.getElementById('igaDisc').onclick = function () {
                    if (!confirm('اتصال قطع شود؟')) return;
                    api('disconnect', { method: 'POST', data: {} }).then(function () { pages.conn(); });
                };
            });
        },
        settings: function () {
            api('settings').then(function (j) {
                var st = j.settings || {};
                body.innerHTML = '<div class="iga-sec-title">تنظیمات ایجنت</div>' +
                    '<label class="iga-muted">انتشار خودکار (پیش‌فرض: خاموش — فقط با Cron و اتصال API)</label>' +
                    '<select class="iga-input" id="s_auto" style="max-width:200px"><option value="off"' + (st.auto_publish !== 'on' ? ' selected' : '') + '>خاموش</option><option value="on"' + (st.auto_publish === 'on' ? ' selected' : '') + '>روشن</option></select>' +
                    '<label class="iga-muted">لحن پیش‌فرض</label>' +
                    '<select class="iga-input" id="s_tone" style="max-width:200px"><option value="professional">حرفه‌ای</option><option value="friendly">صمیمی</option><option value="luxury">لوکس</option></select>' +
                    '<label class="iga-muted">آدرس عمومی پایه برای رسانه (خالی = دامنهٔ خود سایت)</label>' +
                    '<input class="iga-input" id="s_base" placeholder="https://melkino.infinityfree.me" value="' + esc(st.public_base_url || '') + '">' +
                    '<label class="iga-muted">حداکثر عملیات انتشار در هر اجرا</label>' +
                    '<input class="iga-input" id="s_max" style="max-width:140px" value="' + esc(st.max_per_run || '5') + '">' +
                    '<div class="iga-row" style="margin-top:12px"><button class="iga-btn" id="s_save">ذخیرهٔ تنظیمات</button>' +
                    '<button class="iga-btn ghost" id="s_run">اجرای دستی صف انتشار</button></div>' +
                    '<div class="iga-muted" style="margin-top:12px;line-height:2">Cron پیشنهادی هاست: <bdi>php instagram-agent-cron.php</bdi> (هر ۱۵ دقیقه)<br>اگر هاست Cron ندارد، انتشار دستی و «اجرای دستی صف» کامل کار می‌کنند؛ زمان‌بندی دقیق بدون Cron تضمین نمی‌شود.</div>';
                document.getElementById('s_save').onclick = function () {
                    api('save_settings', { method: 'POST', data: {
                        auto_publish: document.getElementById('s_auto').value,
                        tone: document.getElementById('s_tone').value,
                        public_base_url: document.getElementById('s_base').value.trim(),
                        max_per_run: document.getElementById('s_max').value.trim()
                    } }).then(function (z) { body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'ذخیره شد' : z.message, z.success)); });
                };
                document.getElementById('s_run').onclick = function () {
                    api('run_jobs', { method: 'POST', data: {} }).then(function (z) {
                        body.insertAdjacentHTML('afterbegin', alertMsg(z.success ? 'اجرا شد: ' + JSON.stringify(z.result) : z.message, z.success));
                    });
                };
            });
        }
    };

    function nav(s) {
        state.s = s;
        document.querySelectorAll('.iga-tab').forEach(function (b) {
            b.classList.toggle('is-on', b.dataset.s === s);
        });
        body.innerHTML = '<div class="iga-muted">در حال بارگذاری…</div>';
        pages[s]();
    }
    document.getElementById('igaTabs').addEventListener('click', function (ev) {
        var b = ev.target.closest ? ev.target.closest('.iga-tab') : null;
        if (b) nav(b.dataset.s);
    });
    nav('dash');
})();
</script>
