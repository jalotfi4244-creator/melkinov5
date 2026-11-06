/* ==============================================================
   راند ۲۹ — سیستم مدیریت فیلدهای نمایشی
   ساب‌تب «کارت صفحهٔ اصلی» و «صفحهٔ جزئیات ملک» در تب نمایش:
   drag & drop ترتیب، فعال/غیرفعال، عنوان، آیکون، حالت حباب/چیپ،
   فرمت ارقام، ریسپانسیو موبایل/تبلت/دسکتاپ، پیش‌نمایش زندهٔ واقعی
   (رندرر سایت + آگهی واقعی) و ذخیره/لغو/بازگردانی.
   ============================================================== */
(function () {
    'use strict';

    const fdEsc = (x) => String(x == null ? '' : x)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const FD = {
        home:    { defs: null, draft: null, saved: null, meta: null, samples: [], hasHistory: false, dirty: false, loaded: false, ad: '', device: 390 },
        details: { defs: null, draft: null, saved: null, meta: null, samples: [], hasHistory: false, dirty: false, loaded: false, ad: '', device: 390 }
    };
    let FD_PREVIEW_TIMER = null;
    let FD_OPEN_SUB = 'home';
    const FD_DETAILS_TYPE = { current: 'آپارتمان' };
    const FD_OPEN_FIELDS = {}; // sectionKey → bool

    const deepCopy = (o) => JSON.parse(JSON.stringify(o));

    /* ---------- ساب‌تب‌ها ---------- */
    window.melkinoFdSwitchSub = function (sub, btn) {
        const st = FD[FD_OPEN_SUB];
        if (sub !== FD_OPEN_SUB && st && st.dirty) {
            if (!window.confirm('تغییرات ذخیره نشده‌اند. جابه‌جایی بین بخش‌ها پیش‌نویس را نگه می‌دارد ولی ذخیره نمی‌کند. ادامه؟')) {
                return;
            }
        }
        FD_OPEN_SUB = sub;
        document.querySelectorAll('.fd-subtab').forEach((b) => b.classList.toggle('active', b === btn));
        document.getElementById('fdSubHome').style.display = sub === 'home' ? '' : 'none';
        document.getElementById('fdSubDetails').style.display = sub === 'details' ? '' : 'none';
        document.getElementById('fdSubCards').style.display = sub === 'cards' ? '' : 'none';
        if (sub === 'details') {
            const frame = document.getElementById('fdDetailsPreview');
            if (frame && !frame.src) { window.melkinoFdRefreshPreview('details'); }
        }
        if (sub === 'home') {
            const frame = document.getElementById('fdHomePreview');
            if (frame && !frame.src) { window.melkinoFdRefreshPreview('home'); }
        }
    };

    /* ---------- وضعیت/پیام ---------- */
    function fdStatus(target, msg, ok) {
        const el = document.getElementById(target === 'home' ? 'fdHomeStatus' : 'fdDetailsStatus');
        if (!el) return;
        const clean = String(msg || '').replace(/^[\u2705\u274C\u2714\u26D4]\s*/, '');
        el.innerHTML = (typeof mkStatusHtml === 'function') ? mkStatusHtml(ok, clean) : clean;
        el.style.color = ok === true ? 'var(--success,#16a34a)' : (ok === false ? 'var(--danger,#dc2626)' : 'var(--text-secondary)');
        el.dataset.mkMsg = clean;
        if (clean && ok !== undefined) {
            setTimeout(() => { if (el.dataset.mkMsg === clean) el.innerHTML = ''; }, 5000);
        }
    }

    function fdMarkDirty(target, dirty) {
        FD[target].dirty = dirty;
        const st = document.getElementById(target === 'home' ? 'fdHomeStatus' : 'fdDetailsStatus');
        if (st && dirty) {
            st.textContent = '● تغییرات ذخیره نشده';
            st.style.color = 'var(--warning,#d97706)';
        }
    }

    /* ---------- بارگذاری ---------- */
    async function fdLoad(target) {
        const res = await fetch('admin-field-display.php?action=get&target=' + target, { cache: 'no-store' });
        const data = await res.json();
        if (!data || !data.success) throw new Error((data && data.message) || 'پاسخ نامعتبر');
        const st = FD[target];
        st.defs = data.defs;
        st.meta = data.meta || null;
        st.samples = data.samples || [];
        st.hasHistory = !!data.has_history;
        if (target === 'home') {
            st.saved = data.fields;
        } else {
            st.saved = data.config;
        }
        st.draft = deepCopy(st.saved);
        if (!st.ad && st.samples.length) st.ad = st.samples[0].id;
        st.loaded = true;
        fdRenderMeta(target);
        fdFillSamples(target);
        const rb = document.getElementById(target === 'home' ? 'fdHomeRestore' : 'fdDetailsRestore');
        if (rb) rb.style.display = st.hasHistory ? '' : 'none';
    }

    function fdRenderMeta(target) {
        const el = document.getElementById(target === 'home' ? 'fdHomeMeta' : 'fdDetailsMeta');
        if (!el) return;
        const m = FD[target].meta;
        el.textContent = m && m.updated_at
            ? 'آخرین تغییر: ' + m.updated_at + ' — توسط ' + (m.updated_by || 'admin')
            : 'هنوز ذخیره‌ای انجام نشده (پیش‌فرض = وضعیت فعلی سایت)';
    }

    function fdFillSamples(target) {
        const sel = document.getElementById(target === 'home' ? 'fdHomeSample' : 'fdDetailsSample');
        if (!sel) return;
        const st = FD[target];
        sel.innerHTML = st.samples.map((s) =>
            '<option value="' + fdEsc(s.id) + '"' + (s.id === st.ad ? ' selected' : '') + '>' +
            fdEsc(s.title.slice(0, 40)) + ' — ' + fdEsc(s.type) + '/' + fdEsc(s.tr) + '</option>').join('');
        sel.onchange = () => { st.ad = sel.value; window.melkinoFdRefreshPreview(target); };
    }

    /* ---------- پیش‌نمایش زنده ---------- */
    function fdPreviewUrl(target) {
        const st = FD[target];
        const cb = Date.now();
        if (target === 'home') {
            return 'home.php?fd_preview=1&fd_ad=' + encodeURIComponent(st.ad || '') + '&_cb=' + cb;
        }
        return 'property-details.php?id=' + encodeURIComponent(st.ad || '') + '&fd_preview=1&_cb=' + cb;
    }

    function fdHomeDraftToPayload(draft) {
        const defs = FD.home.defs || {};
        const settings = {};
        const specs = {};
        Object.keys(draft || {}).forEach(function (k) {
            const f = draft[k] || {};
            const d = defs[k] || {};
            const visible = f.visible !== false;
            if (d.kind === 'spec') {
                settings[k] = visible ? (f.mode === 'pill' ? 'pill' : 'text') : 'off';
                specs[k] = {
                    emoji: f.icon || d.icon || '',
                    label: f.label || d.label || k,
                    show_icon: !!(f.show_icon || f.icon),
                    show_label: !!f.show_label,
                    order: f.order || 0,
                    scope: 'both'
                };
            } else {
                settings[k] = visible;
            }
        });
        return { settings: settings, specs: specs };
    }

    function fdPostLiveToHomeFrame() {
        const frame = document.getElementById('fdHomePreview');
        if (!frame || !FD.home.draft) return;
        const payload = fdHomeDraftToPayload(FD.home.draft);
        const msg = { type: 'melkino-card-display', payload: payload, adId: FD.home.ad };
        try {
            if (frame.contentWindow) frame.contentWindow.postMessage(msg, '*');
        } catch (e) {}
    }

    async function fdPushDraft(target) {
        const st = FD[target];
        if (!st || !st.draft) return;
        try {
            await fdPostForm('admin-field-display.php?action=set_preview&target=' + encodeURIComponent(target), {
                draft: st.draft,
                ad_id: st.ad
            });
        } catch (e) { /* پیش‌نمایش بدون نشست هم با postMessage کار می‌کند */ }
    }

    window.melkinoFdRefreshPreview = async function (target) {
        const frame = document.getElementById(target === 'home' ? 'fdHomePreview' : 'fdDetailsPreview');
        if (!frame) return;
        if (target === 'home') {
            if (!frame.getAttribute('src')) {
                frame.onload = function () { fdPostLiveToHomeFrame(); };
                frame.src = fdPreviewUrl('home');
            } else {
                fdPostLiveToHomeFrame();
            }
            return;
        }
        await fdPushDraft(target);
        frame.src = fdPreviewUrl(target);
    };

    function fdSchedulePreview(target) {
        clearTimeout(FD_PREVIEW_TIMER);
        if (target === 'home') {
            FD_PREVIEW_TIMER = setTimeout(function () { fdPostLiveToHomeFrame(); }, 60);
            const frame = document.getElementById('fdHomePreview');
            if (frame && !frame.getAttribute('src')) {
                window.melkinoFdRefreshPreview('home');
            }
            return;
        }
        FD_PREVIEW_TIMER = setTimeout(function () { window.melkinoFdRefreshPreview(target); }, 400);
    }

    function fdBindDevices(target) {
        const box = document.getElementById(target === 'home' ? 'fdHomeDevices' : 'fdDetailsDevices');
        if (!box || box.dataset.bound) return;
        box.dataset.bound = '1';
        box.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
            box.querySelectorAll('button').forEach((x) => x.classList.remove('on'));
            b.classList.add('on');
            const w = parseInt(b.getAttribute('data-w'), 10);
            FD[target].device = w;
            const frame = document.getElementById(target === 'home' ? 'fdHomePreview' : 'fdDetailsPreview');
            if (frame) frame.style.width = w + 'px';
        }));
    }

    /* ---------- ردیف فیلد (مشترک home/details) ---------- */
    function fdSwitchHtml(checked, attrs) {
        return '<label class="fd-switch"><input type="checkbox" ' + (checked ? 'checked' : '') + ' ' + attrs + '><span class="fd-slider"></span></label>';
    }

    function fdResponsiveHtml(f, attrs) {
        const mk = (k, lbl) => '<label title="نمایش در ' + lbl + '"><input type="checkbox" data-r="' + k + '" ' +
            (f[k] === false ? '' : 'checked') + ' ' + attrs + '>' + lbl.charAt(0) + '</label>';
        return '<span class="fd-r">' + mk('mobile', 'موبایل') + mk('tablet', 'تبلت') + mk('desktop', 'دسکتاپ') + '</span>';
    }

    /* ================= HOME: فهرست فیلدها ================= */
    function fdRenderHome() {
        const box = document.getElementById('fdHomeFields');
        const st = FD.home;
        if (!box || !st.defs) return;

        const entries = Object.keys(st.draft).map((k) => [k, st.draft[k], st.defs[k] || {}]);
        const cardItems = entries.filter((e) => (e[2].kind === 'block' || e[2].kind === 'spec'))
            .sort((a, b) => (a[1].order || 0) - (b[1].order || 0));
        const footerItems = entries.filter((e) => e[2].kind === 'footer')
            .sort((a, b) => (a[1].order || 0) - (b[1].order || 0));

        function row(key, f, d) {
            const isSpec = d.kind === 'spec';
            const zoneLbl = !isSpec ? (d.kind === 'footer' ? 'فوتر' : 'بدنه') :
                ((f.mode === 'pill') ? 'حباب روی تصویر' : 'چیپ در بدنه');
            const modeSeg = isSpec
                ? '<span class="fd-seg">' +
                  '<button type="button" class="' + (f.mode === 'pill' ? 'on' : '') + '" data-act="mode" data-v="pill">حباب</button>' +
                  '<button type="button" class="' + (f.mode !== 'pill' ? 'on' : '') + '" data-act="mode" data-v="text">چیپ</button>' +
                  '</span>'
                : '';
            const fmt = isSpec
                ? '<span class="fd-format">' +
                  '<select data-act="digits" class="fd-select" style="padding:4px 6px;font-size:10.5px">' +
                    '<option value="auto"' + (f.digits === 'auto' ? ' selected' : '') + '>ارقام: خودکار</option>' +
                    '<option value="fa"' + (f.digits === 'fa' ? ' selected' : '') + '>ارقام: فارسی</option>' +
                    '<option value="en"' + (f.digits === 'en' ? ' selected' : '') + '>ارقام: انگلیسی</option>' +
                  '</select>' +
                  '<label class="fd-checkline"><input type="checkbox" data-act="show_label" ' + (f.show_label ? 'checked' : '') + '>لیبل</label>' +
                  '<label class="fd-checkline"><input type="checkbox" data-act="show_icon" ' + (f.show_icon ? 'checked' : '') + '>آیکون</label>' +
                  '</span>'
                : ((d.type === 'currency' || d.type === 'number' || d.type === 'count' || d.type === 'date')
                    ? '<select data-act="digits" class="fd-select" style="padding:4px 6px;font-size:10.5px">' +
                        '<option value="auto"' + (f.digits === 'auto' ? ' selected' : '') + '>ارقام: خودکار</option>' +
                        '<option value="fa"' + (f.digits === 'fa' ? ' selected' : '') + '>ارقام: فارسی</option>' +
                        '<option value="en"' + (f.digits === 'en' ? ' selected' : '') + '>ارقام: انگلیسی</option>' +
                      '</select>'
                    : '');
            return '<div class="fd-row' + (f.visible ? '' : ' fd-off') + '" draggable="false" data-key="' + fdEsc(key) + '">' +
                '<span class="fd-handle" title="بکشید تا ترتیب عوض شود">⠿</span>' +
                fdSwitchHtml(!!f.visible, 'data-act="visible"') +
                '<input class="fd-icn" data-act="icon" value="' + fdEsc(f.icon || '') + '" title="آیکون (ایموجی)" maxlength="64" autocomplete="off" spellcheck="false">' +
                '<input class="fd-label" data-act="label" value="' + fdEsc(f.label || '') + '" title="عنوان نمایشی" maxlength="80">' +
                '<span class="fd-key"><b>' + fdEsc(key) + '</b>' + fdEsc(d.source || '') + ' · ' + fdEsc(zoneLbl) + '</span>' +
                '<span class="fd-type">' + fdEsc(d.type || 'text') + '</span>' +
                modeSeg + fmt + fdResponsiveHtml(f, 'data-act="r"') +
                '</div>';
        }

        let html = '<div class="fd-group"><div class="fd-group-title">فیلدهای کارت (بدنه + حباب‌ها) — به ترتیب نمایش</div>' +
            cardItems.map((e) => row(e[0], e[1], e[2])).join('') + '</div>';
        html += '<div class="fd-group"><div class="fd-group-title">فوتر کارت</div>' +
            footerItems.map((e) => row(e[0], e[1], e[2])).join('') + '</div>';
        box.innerHTML = html;

        fdBindRows(box, 'home');
        fdBindDnd(box, 'home', cardItems.map((e) => e[0]), footerItems.map((e) => e[0]));
    }

    /* ================= DETAILS: بخش‌ها + فیلدها ================= */
    function fdRenderDetails() {
        const box = document.getElementById('fdDetailsSections');
        const st = FD.details;
        if (!box || !st.defs || !st.draft) return;
        const cfg = st.draft;
        const secDefs = st.defs.sections;

        const secKeys = Object.keys(cfg.sections).sort((a, b) =>
            (cfg.sections[a].order || 0) - (cfg.sections[b].order || 0));

        function fieldRow(sec, key, f, d) {
            return '<div class="fd-row' + (f.visible ? '' : ' fd-off') + '" draggable="true" data-sec="' + fdEsc(sec) + '" data-key="' + fdEsc(key) + '">' +
                '<span class="fd-handle">⠿</span>' +
                fdSwitchHtml(!!f.visible, 'data-act="visible"') +
                '<input class="fd-icn" data-act="icon" value="' + fdEsc(f.icon || '') + '" maxlength="64" autocomplete="off" spellcheck="false" title="آیکون (ایموجی)">' +
                '<input class="fd-label" data-act="label" value="' + fdEsc(f.label || f.title || '') + '" maxlength="80" title="عنوان نمایشی">' +
                '<span class="fd-key"><b>' + fdEsc(key) + '</b>' + fdEsc((d && d.source) || '') + '</span>' +
                (d && d.type ? '<span class="fd-type">' + fdEsc(d.type) + '</span>' : '') +
                fdResponsiveHtml(f, 'data-act="r"') +
                '</div>';
        }

        let html = '<div class="fd-group"><div class="fd-group-title">بخش‌های صفحه — به ترتیب نمایش</div>';
        secKeys.forEach((sk) => {
            const sc = cfg.sections[sk];
            const d = secDefs[sk] || {};
            const hasFields = (st.defs.fields && st.defs.fields[sk]) || sk === 'specs';
            const open = !!FD_OPEN_FIELDS[sk];
            html += '<div class="fd-row' + (sc.visible ? '' : ' fd-off') + '" draggable="false" data-seckeys="' + fdEsc(sk) + '" data-key="' + fdEsc(sk) + '">' +
                '<span class="fd-handle">⠿</span>' +
                fdSwitchHtml(!!sc.visible, 'data-act="svisible"') +
                '<input class="fd-icn" data-act="sicon" value="' + fdEsc(sc.icon || '') + '" maxlength="64" autocomplete="off" spellcheck="false">' +
                '<input class="fd-label" data-act="stitle" value="' + fdEsc(sc.title || '') + '" maxlength="80">' +
                '<span class="fd-key"><b>' + fdEsc(sk) + '</b>' + fdEsc(d.label || '') + (sk === 'contact' ? ' · ثابت پایین صفحه (ترتیب تأثیر ندارد)' : '') + '</span>' +
                (d.has_title ? '<label class="fd-checkline"><input type="checkbox" data-act="sshow_title" ' + (sc.show_title ? 'checked' : '') + '>عنوان بخش</label>' : '') +
                fdResponsiveHtml(sc, 'data-act="sr"') +
                (hasFields ? '<button type="button" class="fd-sec-toggle" data-act="togglefields">' + (open ? '▾ بستن فیلدها' : '▸ مدیریت فیلدها') + '</button>' : '') +
                '<div class="fd-subfields' + (open ? ' open' : '') + '" id="fdSubFields_' + fdEsc(sk) + '">' +
                    fdSubFieldsHtml(sk) +
                '</div>' +
                '</div>';
        });
        html += '</div>';
        box.innerHTML = html;

        fdBindDetailsRows(box);
        fdBindDnd(box, 'details', secKeys, []);
    }

    function fdSubFieldsHtml(sk) {
        const st = FD.details;
        const cfg = st.draft;
        if (sk === 'specs') {
            const type = FD_DETAILS_TYPE.current;
            const types = st.defs.types || [];
            let h = '<div class="fd-toolbar" style="margin:4px 0 8px"><label class="fd-toolbar-label">نوع ملک: <select class="fd-select" data-act="spectype">' +
                types.map((t) => '<option value="' + fdEsc(t) + '"' + (t === type ? ' selected' : '') + '>' + fdEsc(t) + '</option>').join('') +
                '</select></label><span class="fd-key">فیلدهای فهرست‌شده برای همهٔ نوع‌ها قابل فعال‌سازی‌اند؛ فقط موارد پرشده در آگهی نمایش داده می‌شوند.</span></div>';
            const list = Object.keys((cfg.specs || {})[type] || {}).sort((a, b) =>
                (cfg.specs[type][a].order || 0) - (cfg.specs[type][b].order || 0));
            list.forEach((label) => {
                const f = cfg.specs[type][label];
                const src = (st.defs.specSources && st.defs.specSources[label] || []).join(' | ');
                h += '<div class="fd-row' + (f.visible ? '' : ' fd-off') + '" draggable="false" data-spec="' + fdEsc(type) + '" data-key="' + fdEsc(label) + '">' +
                    '<span class="fd-handle">⠿</span>' +
                    fdSwitchHtml(!!f.visible, 'data-act="visible"') +
                    '<input class="fd-icn" data-act="icon" value="' + fdEsc(f.icon || '') + '" maxlength="64" autocomplete="off" spellcheck="false">' +
                    '<input class="fd-label" data-act="label" value="' + fdEsc(f.label || '') + '" maxlength="80">' +
                    '<span class="fd-key"><b>' + fdEsc(label) + '</b>property_details: ' + fdEsc(src) + '</span>' +
                    fdResponsiveHtml(f, 'data-act="r"') +
                    '</div>';
            });
            return h;
        }
        const fields = (st.defs.fields || {})[sk];
        if (!fields) return '<div class="admin-field-help">این بخش فیلد قابل تنظیمی ندارد (فقط نمایش/ترتیب/عنوان بخش).</div>';
        const list = Object.keys(cfg.fields[sk] || {}).sort((a, b) =>
            (cfg.fields[sk][a].order || 0) - (cfg.fields[sk][b].order || 0));
        return list.map((key) =>
            '<div class="fd-row' + (cfg.fields[sk][key].visible ? '' : ' fd-off') + '" draggable="false" data-fieldsec="' + fdEsc(sk) + '" data-key="' + fdEsc(key) + '">' +
            '<span class="fd-handle">⠿</span>' +
            fdSwitchHtml(!!cfg.fields[sk][key].visible, 'data-act="visible"') +
            '<input class="fd-icn" data-act="icon" value="' + fdEsc(cfg.fields[sk][key].icon || '') + '" maxlength="64" autocomplete="off" spellcheck="false">' +
            '<input class="fd-label" data-act="label" value="' + fdEsc(cfg.fields[sk][key].label || '') + '" maxlength="80">' +
            '<span class="fd-key"><b>' + fdEsc(key) + '</b>' + fdEsc((fields[key] || {}).source || '') + '</span>' +
            '<span class="fd-type">' + fdEsc((fields[key] || {}).type || 'text') + '</span>' +
            fdResponsiveHtml(cfg.fields[sk][key], 'data-act="r"') +
            '</div>').join('');
    }

    /* ---------- رویدادهای ردیف‌ها ---------- */
    function fdOnChange(target) {
        fdMarkDirty(target, true);
        fdSchedulePreview(target);
    }

    function fdBindRows(box, target) {
        box.querySelectorAll('.fd-row').forEach((rowEl) => {
            const key = rowEl.getAttribute('data-key');
            rowEl.querySelectorAll('[data-act]').forEach((el) => {
                const act = el.getAttribute('data-act');
                if (act === 'r') {
                    el.addEventListener('change', () => {
                        FD[target].draft[key][el.getAttribute('data-r')] = el.checked;
                        fdOnChange(target);
                    });
                } else if (act === 'visible') {
                    el.addEventListener('change', () => {
                        FD[target].draft[key].visible = el.checked;
                        rowEl.classList.toggle('fd-off', !el.checked);
                        fdOnChange(target);
                    });
                } else if (act === 'icon' || act === 'label') {
                    el.addEventListener('input', () => {
                        FD[target].draft[key][act] = el.value;
                        if (act === 'icon' && String(el.value || '').trim()) {
                            FD[target].draft[key].show_icon = true;
                            const cb = rowEl.querySelector('[data-act="show_icon"]');
                            if (cb) cb.checked = true;
                        }
                        fdOnChange(target);
                    });
                } else if (act === 'digits') {
                    el.addEventListener('change', () => {
                        FD[target].draft[key].digits = el.value;
                        fdOnChange(target);
                    });
                } else if (act === 'show_label' || act === 'show_icon') {
                    el.addEventListener('change', () => {
                        FD[target].draft[key][act] = el.checked;
                        fdOnChange(target);
                    });
                } else if (act === 'mode') {
                    el.addEventListener('click', () => {
                        FD[target].draft[key].mode = el.getAttribute('data-v');
                        fdRenderHome();
                        fdOnChange(target);
                    });
                }
            });
        });
    }

    function fdBindDetailsRows(box) {
        const st = FD.details;
        // ردیف بخش‌ها
        box.querySelectorAll('.fd-row[data-seckeys]').forEach((rowEl) => {
            const sk = rowEl.getAttribute('data-seckeys');
            const sc = () => st.draft.sections[sk];
            rowEl.querySelectorAll(':scope > [data-act], :scope > label > [data-act]').forEach((el) => {
                const act = el.getAttribute('data-act');
                if (act === 'sr') {
                    el.addEventListener('change', () => { sc()[el.getAttribute('data-r')] = el.checked; fdOnChange('details'); });
                } else if (act === 'svisible') {
                    el.addEventListener('change', () => { sc().visible = el.checked; rowEl.classList.toggle('fd-off', !el.checked); fdOnChange('details'); });
                } else if (act === 'sicon') {
                    el.addEventListener('input', () => { sc().icon = el.value; fdOnChange('details'); });
                } else if (act === 'stitle') {
                    el.addEventListener('input', () => { sc().title = el.value; fdOnChange('details'); });
                } else if (act === 'sshow_title') {
                    el.addEventListener('change', () => { sc().show_title = el.checked; fdOnChange('details'); });
                } else if (act === 'togglefields') {
                    el.addEventListener('click', () => {
                        FD_OPEN_FIELDS[sk] = !FD_OPEN_FIELDS[sk];
                        fdRenderDetails();
                    });
                }
            });
            // انتخاب نوع ملک در زیربخش specs
            const typeSel = rowEl.querySelector('[data-act="spectype"]');
            if (typeSel) {
                typeSel.addEventListener('change', () => {
                    FD_DETAILS_TYPE.current = typeSel.value;
                    fdRenderDetails();
                });
            }
            // فیلدهای header/price داخل ردیف بخش
            rowEl.querySelectorAll('.fd-row[data-fieldsec]').forEach((fRow) => fdBindInnerFieldRow(fRow, 'fields', fRow.getAttribute('data-fieldsec')));
            // فیلدهای specs
            rowEl.querySelectorAll('.fd-row[data-spec]').forEach((fRow) => fdBindInnerFieldRow(fRow, 'specs', fRow.getAttribute('data-spec')));
        });

        function fdBindInnerFieldRow(fRow, group, secOrType) {
            const key = fRow.getAttribute('data-key');
            const get = () => group === 'fields'
                ? st.draft.fields[secOrType][key]
                : st.draft.specs[secOrType][key];
            fRow.querySelectorAll('[data-act]').forEach((el) => {
                const act = el.getAttribute('data-act');
                if (act === 'r') {
                    el.addEventListener('change', () => { get()[el.getAttribute('data-r')] = el.checked; fdOnChange('details'); });
                } else if (act === 'visible') {
                    el.addEventListener('change', () => { get().visible = el.checked; fRow.classList.toggle('fd-off', !el.checked); fdOnChange('details'); });
                } else if (act === 'icon' || act === 'label') {
                    el.addEventListener('input', () => {
                        get()[act] = el.value;
                        if (act === 'icon' && String(el.value || '').trim()) {
                            get().show_icon = true;
                            const cb = fRow.querySelector('[data-act="show_icon"]');
                            if (cb) cb.checked = true;
                        }
                        fdOnChange('details');
                    });
                }
            });
            fRow.__fdDndGroup = group + ':' + secOrType;
        }
    }

    /* ---------- Drag & Drop ---------- */
    function fdBindDnd(box, target, mainKeys, footerKeys) {
        let dragEl = null;

        // فقط با گرفتن دستگیره، ردیف قابل کشیدن شود (تا انتخاب متن/ورودی مختل نشود)
        box.addEventListener('mousedown', (e) => {
            const row = e.target.closest('.fd-row');
            if (!row) return;
            const onHandle = e.target.closest('.fd-handle');
            row.setAttribute('draggable', onHandle ? 'true' : 'false');
        });
        box.addEventListener('mouseup', () => {
            box.querySelectorAll('.fd-row[draggable="true"]').forEach((r) => r.setAttribute('draggable', 'false'));
        });

        box.addEventListener('dragstart', (e) => {
            const row = e.target.closest('.fd-row');
            if (!row) return;
            // فقط ردیف‌های سطح قابل drag (نه ردیف‌های تودرتو وقتی داخل another row هستند)
            dragEl = row;
            row.classList.add('fd-dragging');
            try { e.dataTransfer.setData('text/plain', row.getAttribute('data-key') || ''); } catch (err) {}
            e.dataTransfer.effectAllowed = 'move';
        });

        box.addEventListener('dragover', (e) => {
            if (!dragEl) return;
            const row = e.target.closest('.fd-row');
            if (!row || row === dragEl) return;
            if (!fdSameGroup(dragEl, row)) return;
            e.preventDefault();
            row.classList.add('fd-dragover');
        });

        box.addEventListener('dragleave', (e) => {
            const row = e.target.closest && e.target.closest('.fd-row');
            if (row) row.classList.remove('fd-dragover');
        });

        box.addEventListener('drop', (e) => {
            const row = e.target.closest('.fd-row');
            if (!row || !dragEl || row === dragEl) return;
            if (!fdSameGroup(dragEl, row)) return;
            e.preventDefault();
            row.classList.remove('fd-dragover');
            fdMoveBefore(target, dragEl, row);
            dragEl.classList.remove('fd-dragging');
            dragEl = null;
        });

        box.addEventListener('dragend', () => {
            if (dragEl) dragEl.classList.remove('fd-dragging');
            box.querySelectorAll('.fd-dragover').forEach((r) => r.classList.remove('fd-dragover'));
            dragEl = null;
        });

        function fdSameGroup(a, b) {
            const g = (el) => {
                if (el.__fdDndGroup) return el.__fdDndGroup;
                if (el.hasAttribute('data-seckeys')) return 'sections';
                if (el.hasAttribute('data-spec')) return 'specs:' + el.getAttribute('data-spec');
                if (el.hasAttribute('data-fieldsec')) return 'fields:' + el.getAttribute('data-fieldsec');
                const p = el.parentElement && el.parentElement.closest('.fd-group');
                if (p) return 'home:' + (Array.from(p.parentElement.children).indexOf(p));
                return 'home:0';
            };
            return g(a) === g(b) && a.parentElement === b.parentElement;
        }

        function fdMoveBefore(tgt, fromEl, toEl) {
            const fromKey = fromEl.getAttribute('data-key');
            const toKey = toEl.getAttribute('data-key');
            let list = null;

            if (tgt === 'home') {
                const inFooter = (k) => (FD.home.defs[k] || {}).kind === 'footer';
                list = Object.keys(FD.home.draft).filter((k) => inFooter(k) === inFooter(fromKey))
                    .sort((a, b) => (FD.home.draft[a].order || 0) - (FD.home.draft[b].order || 0));
                const arr = list;
                const fi = arr.indexOf(fromKey), ti = arr.indexOf(toKey);
                if (fi < 0 || ti < 0) return;
                arr.splice(fi, 1);
                arr.splice(arr.indexOf(toKey) + (fi < ti ? 1 : 0), 0, fromKey);
                arr.forEach((k, i) => { FD.home.draft[k].order = (i + 1) * 10; });
                fdRenderHome();
                fdOnChange('home');
                return;
            }

            // details
            if (toEl.hasAttribute('data-seckeys') || fromEl.hasAttribute('data-seckeys')) {
                const arr = Object.keys(FD.details.draft.sections)
                    .sort((a, b) => (FD.details.draft.sections[a].order || 0) - (FD.details.draft.sections[b].order || 0));
                const fi = arr.indexOf(fromKey), ti = arr.indexOf(toKey);
                if (fi < 0 || ti < 0) return;
                arr.splice(fi, 1);
                arr.splice(arr.indexOf(toKey) + (fi < ti ? 1 : 0), 0, fromKey);
                arr.forEach((k, i) => { FD.details.draft.sections[k].order = (i + 1) * 10; });
            } else if (fromEl.hasAttribute('data-spec')) {
                const type = fromEl.getAttribute('data-spec');
                const arr = Object.keys(FD.details.draft.specs[type])
                    .sort((a, b) => (FD.details.draft.specs[type][a].order || 0) - (FD.details.draft.specs[type][b].order || 0));
                const fi = arr.indexOf(fromKey), ti = arr.indexOf(toKey);
                if (fi < 0 || ti < 0) return;
                arr.splice(fi, 1);
                arr.splice(arr.indexOf(toKey) + (fi < ti ? 1 : 0), 0, fromKey);
                arr.forEach((k, i) => { FD.details.draft.specs[type][k].order = (i + 1) * 10; });
            } else if (fromEl.hasAttribute('data-fieldsec')) {
                const sec = fromEl.getAttribute('data-fieldsec');
                const arr = Object.keys(FD.details.draft.fields[sec])
                    .sort((a, b) => (FD.details.draft.fields[sec][a].order || 0) - (FD.details.draft.fields[sec][b].order || 0));
                const fi = arr.indexOf(fromKey), ti = arr.indexOf(toKey);
                if (fi < 0 || ti < 0) return;
                arr.splice(fi, 1);
                arr.splice(arr.indexOf(toKey) + (fi < ti ? 1 : 0), 0, fromKey);
                arr.forEach((k, i) => { FD.details.draft.fields[sec][k].order = (i + 1) * 10; });
            } else {
                return;
            }
            fdRenderDetails();
            fdOnChange('details');
        }
    }

    /* ---------- ذخیره بدون fetch/JSON (آیفون + InfinityFree) ---------- */
    function fdSafeStr(s, max) {
        s = String(s == null ? '' : s);
        var out = '', i, c, n;
        for (i = 0; i < s.length; i++) {
            c = s.charCodeAt(i);
            if (c >= 0xD800 && c <= 0xDBFF) {
                n = s.charCodeAt(i + 1);
                if (n >= 0xDC00 && n <= 0xDFFF) { out += s.charAt(i) + s.charAt(i + 1); i++; }
                continue;
            }
            if (c >= 0xDC00 && c <= 0xDFFF) continue;
            out += s.charAt(i);
        }
        if (max && out.length > max) out = out.slice(0, max);
        return out;
    }
    function fdPlain(val) {
        if (val == null) return val;
        if (typeof val !== 'object') return val;
        if (Array.isArray(val)) return val.map(fdPlain);
        var o = {}, k;
        for (k in val) {
            if (!Object.prototype.hasOwnProperty.call(val, k)) continue;
            o[k] = fdPlain(val[k]);
        }
        return o;
    }
    function fdPostForm(url, payloadObj) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            var token = String(window.MELKINO_CSRF || '');
            if (!token) {
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) token = String(meta.getAttribute('content') || '');
            }
            if (token && url.indexOf('csrf_token=') === -1) {
                url += (url.indexOf('?') >= 0 ? '&' : '?') + 'csrf_token=' + encodeURIComponent(token);
            }
            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                var raw = String(xhr.responseText || '');
                var data = null;
                try { data = JSON.parse(raw); } catch (pe) {
                    var hint = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                    reject(new Error('پاسخ سرور خوانده نشد (' + xhr.status + ')' + (hint ? ': ' + hint : '')));
                    return;
                }
                resolve(data);
            };
            xhr.onerror = function () { reject(new Error('ارتباط با سرور برقرار نشد')); };
            var fd = new FormData();
            if (token) fd.append('csrf_token', token);
            fd.append('body_json', JSON.stringify(payloadObj || {}));
            xhr.send(fd);
        });
    }

    window.melkinoFdSave = async function (target) {
        if (window.event) {
            try { window.event.preventDefault(); window.event.stopPropagation(); } catch (e0) {}
        }
        const st = FD[target];
        if (!st || !st.draft) return;
        fdStatus(target, 'در حال ذخیره…');
        try {
            const payload = target === 'home'
                ? { fields: fdPlain(st.draft) }
                : { config: fdPlain(st.draft) };
            const data = await fdPostForm('admin-field-display.php?action=save&target=' + encodeURIComponent(target), payload);
            if (data && data.success) {
                st.saved = deepCopy(st.draft);
                st.dirty = false;
                st.meta = data.meta || st.meta;
                st.hasHistory = true;
                fdRenderMeta(target);
                const rb = document.getElementById(target === 'home' ? 'fdHomeRestore' : 'fdDetailsRestore');
                if (rb) rb.style.display = '';
                fdStatus(target, (data.message || 'ذخیره شد و روی خانه، همه آگهی‌ها و VIP اعمال می‌شود.'), true);
                window.melkinoFdRefreshPreview(target);
            } else {
                const errs = (data && data.errors && data.errors.length)
                    ? ' — ' + data.errors.join('، ')
                    : '';
                fdStatus(target, ((data && data.message) || 'ذخیره ناموفق بود.') + errs, false);
            }
        } catch (e) {
            fdStatus(target, 'خطا در ذخیره: ' + String((e && e.message) || e || 'نامشخص'), false);
        }
    };

    window.melkinoFdCancel = function (target) {
        const st = FD[target];
        if (!st.saved) return;
        if (st.dirty && !window.confirm('تغییرات ذخیره‌نشدهٔ پیش‌نویس حذف شوند؟')) return;
        st.draft = deepCopy(st.saved);
        st.dirty = false;
        if (target === 'home') fdRenderHome(); else fdRenderDetails();
        fdStatus(target, '↺ به آخرین تنظیمات ذخیره‌شده برگشت.');
        window.melkinoFdRefreshPreview(target);
    };

    window.melkinoFdRestore = async function (target) {
        if (!window.confirm('آخرین تنظیمات ذخیره‌شدهٔ قبلی از تاریخچه بازگردانی شود؟')) return;
        fdStatus(target, 'در حال بازگردانی…');
        try {
            const res = await fetch('admin-field-display.php?action=restore&target=' + target, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });
            const data = await res.json();
            if (data && data.success) {
                await fdLoad(target);
                if (target === 'home') fdRenderHome(); else fdRenderDetails();
                fdStatus(target, '↩ ' + (data.message || 'بازگردانی شد.'), true);
                window.melkinoFdRefreshPreview(target);
            } else {
                fdStatus(target, '❌ ' + ((data && data.message) || 'بازگردانی ناموفق بود.'), false);
            }
        } catch (e) {
            fdStatus(target, '❌ خطا: ' + (e.message || e), false);
        }
    };

    /* ---------- هشدار خروج بدون ذخیره ---------- */
    window.addEventListener('beforeunload', function (e) {
        if (FD.home.dirty || FD.details.dirty) {
            e.preventDefault();
            e.returnValue = 'تغییرات ذخیره نشده‌اند.';
            return e.returnValue;
        }
    });

    /* ---------- init ---------- */
    window.melkinoInitFieldDisplay = async function () {
        fdBindDevices('home');
        fdBindDevices('details');
        try {
            if (!FD.home.loaded) {
                await fdLoad('home');
                fdRenderHome();
                window.melkinoFdRefreshPreview('home');
            }
            if (!FD.details.loaded) {
                await fdLoad('details');
                fdRenderDetails();
                // پیش‌نمایش details فقط وقتی ساب‌تب باز شد لازم است؛ برای سرعت، اینجا هم آماده می‌شود
            }
        } catch (e) {
            const box = document.getElementById('fdHomeFields');
            if (box) box.innerHTML = '<div class="admin-field-help">بارگذاری سیستم مدیریت فیلدها ناموفق بود: ' + fdEsc(e.message || e) +
                '<br>فایل‌های field-display.php و admin-field-display.php را آپلود کرده‌اید؟</div>';
        }
    };

})();
