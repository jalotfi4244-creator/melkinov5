<?php require_once __DIR__ . '/config.php'; ?>
<!-- =========================================================
     VISIT REQUESTS
     ========================================================= -->
<style>
.vr-cal { user-select:none; }
.vr-cal-nav { display:flex; align-items:center; justify-content:space-between; gap:8px; margin:8px 0 10px; }
.vr-cal-nav button { min-width:36px; }
.vr-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.vr-cal-wd { text-align:center; font-size:11px; font-weight:800; color:var(--text-secondary); padding:4px 0; }
.vr-cal-day {
    height:38px; border-radius:10px; border:1px solid var(--border);
    background:var(--bg); color:var(--text-primary); cursor:pointer;
    font-family:inherit; font-size:12px; font-weight:700;
}
.vr-cal-day.is-empty { visibility:hidden; pointer-events:none; }
.vr-cal-day.is-fri { color:#c0392b; }
.vr-cal-day.is-on { background:#C0392B; color:#fff; border-color:#C0392B; }
.vr-st-btn { font-size:11px !important; padding:7px 12px !important; border-width:1px; border-radius:999px !important; }
.vr-st-btn.is-on { color:#fff !important; font-weight:800 !important; }
.vr-card {
    margin:0 0 8px; padding:8px 10px; overflow:hidden; border:1px solid var(--border);
    border-radius:12px; background:var(--surface);
    box-shadow:0 2px 8px rgba(0,0,0,.04);
}
.vr-card-top { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; padding:0 0 6px; border-bottom:1px solid var(--border); }
.vr-card-title { font-size:13px; font-weight:900; }
.vr-chip { font-size:11px; font-weight:800; padding:2px 8px; border-radius:999px; background:var(--bg); border:1px solid var(--border); direction:ltr; }
.vr-who { font-size:12px; color:var(--text-secondary); margin:4px 0 6px; }
.vr-block { font-size:12px; line-height:1.7; padding:8px 10px; margin:6px 0; border:1px solid var(--border); border-radius:10px; background:var(--bg); }
.vr-block h4 { margin:0 0 6px; font-size:11px; font-weight:900; color:var(--text-secondary); }
.vr-kv { display:grid; grid-template-columns:88px 1fr; gap:2px 8px; }
.vr-kv span { color:var(--text-secondary); }
.vr-actions { display:flex; gap:4px; flex-wrap:wrap; padding:4px 0 0; }
.vr-st-btn { font-size:11px !important; padding:5px 8px !important; }
.vr-more { margin-top:4px; font-size:12px; }
.vr-more summary { cursor:pointer; font-weight:800; color:var(--primary); }
.vr-urg-later { border-inline-start:4px solid #16a34a; }
.vr-urg-soon  { border-inline-start:4px solid #ea580c; background:rgba(234,88,12,.04); }
.vr-urg-past  { border-inline-start:4px solid #dc2626; background:rgba(220,38,38,.04); }
@media (max-width:640px){ .vr-kv { grid-template-columns:1fr; } }
</style>
<div role="tabpanel" class="tab-content" id="tab-visits">
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">تنظیمات روزهای تعطیل</span>
        </div>
        <div style="padding:0 16px 16px;font-size:13px;line-height:1.9;">
            <p style="color:var(--text-secondary);margin:0 0 10px;">روزهای هفته و تاریخ‌های تقویم که علامت بزنید، در فرم درخواست بازدید برای کاربر قرمز و غیرقابل انتخاب می‌شود. پیش‌فرض جمعه است.</p>
            <div id="vrHolidayWeekdays" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;"></div>
            <div class="vr-cal">
                <div class="vr-cal-nav">
                    <button type="button" class="btn-secondary" onclick="vrCalMove(-1)">ماه قبل</button>
                    <strong id="vrCalTitle">—</strong>
                    <button type="button" class="btn-secondary" onclick="vrCalMove(1)">ماه بعد</button>
                </div>
                <div class="vr-cal-grid" id="vrCalHead"></div>
                <div class="vr-cal-grid" id="vrCalBody" style="margin-top:4px;"></div>
            </div>
            <p style="font-size:12px;color:var(--text-secondary);margin:10px 0 0;">تاریخ‌های انتخاب‌شده: <span id="vrHolidayCount">۰</span> روز</p>
            <button type="button" class="btn-primary" style="margin-top:10px;" onclick="saveVisitHolidays()">ذخیره روزهای تعطیل</button>
            <span id="vrHolidayMsg" style="margin-right:10px;font-size:12px;"></span>
        </div>
    </div>
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">ظرفیت بازدید روزانه</span>
        </div>
        <div style="padding:0 16px 16px;">
            <p style="font-size:12.5px;color:var(--text-secondary);line-height:1.9;margin:0 0 10px;">
                حداکثر بازدیدِ قابل ثبت برای هر روز در هر بازهٔ زمانی. وقتی ظرفیت یک روز پر شود، آن روز در تقویم کاربر غیرفعال می‌شود و پیام
                «به علت کامل بودن برنامه بازدیدها…» نمایش داده می‌شود. صفر = بدون محدودیت.
            </p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <label style="font-size:13px;">صبح — حداکثر:
                    <input type="number" id="vrCapMorning" min="0" max="200" class="admin-input" style="width:80px;padding:6px 8px;">
                </label>
                <label style="font-size:13px;">عصر — حداکثر:
                    <input type="number" id="vrCapEvening" min="0" max="200" class="admin-input" style="width:80px;padding:6px 8px;">
                </label>
                <button type="button" class="btn-primary" style="padding:8px 16px;font-size:12.5px;" onclick="saveVisitCapacity()">ذخیره ظرفیت</button>
                <span id="vrCapMsg" style="font-size:12px;"></span>
            </div>
        </div>
    </div>
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">درخواست بازدید</span>
            <span id="visitsResultCount" class="admin-section-help"></span>
        </div>
        <div style="padding:12px 16px 0;display:flex;gap:8px;">
            <button type="button" id="vrSegCurrent" class="btn-primary" style="flex:1;padding:10px;font-size:13px;" onclick="vrSwitchSection('current')">📌 بازدیدهای جاری</button>
            <button type="button" id="vrSegArchived" class="btn-secondary" style="flex:1;padding:10px;font-size:13px;" onclick="vrSwitchSection('archived')">📦 بایگانی</button>
        </div>
        <div class="stats-grid" style="padding:0 16px;">
            <div class="stat-card"><div class="number" id="vrStatAll">…</div><div class="label">کل</div></div>
            <div class="stat-card"><div class="number" id="vrStatNew">…</div><div class="label">جدید</div></div>
            <div class="stat-card"><div class="number" id="vrStatScheduled">…</div><div class="label">هماهنگ با مالک</div></div>
            <div class="stat-card"><div class="number" id="vrStatRejected">…</div><div class="label">رد تاریخ</div></div>
        </div>
        <div id="vrFilterBar" style="display:flex; gap:8px; flex-wrap:wrap; padding:0 16px 12px;"></div>
        <div id="visitsListContainer" style="padding:0 16px 16px;">در حال بارگذاری…</div>
    </div>
</div>
<script>
(function () {
    var vrFilter = 'all';
    var vrSlots = { morning: 'صبح', evening: 'عصر' };
    var vrStatus = {
        new: 'جدید',
        scheduled_owner: 'هماهنگ شده با مالک',
        owner_rejected_date: 'رد تاریخ توسط مالک',
        user_notified: 'اطلاع داده شده به کاربر',
        cancelled_by_user: 'لغو بازدید توسط کاربر',
        time_changed_by_user: 'تغییر زمان توسط کاربر',
        user_no_response: 'عدم پاسخگویی کاربر',
        visited: 'بازدید شده'
    };
    var vrStatusColor = {
        new: '#C9A227',
        scheduled_owner: '#0E7C6E',
        owner_rejected_date: '#C0392B',
        user_notified: '#1D6FB8',
        cancelled_by_user: '#6B7280',
        time_changed_by_user: '#D97706',
        user_no_response: '#7C3AED',
        visited: '#15803D'
    };

    function vrEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function vrLine(label, value) {
        var v = (value == null || String(value).trim() === '') ? '—' : String(value);
        return '<div class="vr-kv"><span>' + vrEsc(label) + '</span><strong>' + vrEsc(v) + '</strong></div>';
    }
    function vrMoney(value) {
        if (value == null) return '';
        var raw = String(value).trim();
        if (!raw) return '';
        var s = raw.replace(/[۰۱۲۳۴۵۶۷۸۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); })
                   .replace(/[٠١٢٣٤٥٦٧٨٩]/g, function (d) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)); });
        s = s.replace(/[^\d.]/g, '');
        if (!s) return '';
        var n = parseFloat(s);
        if (!isFinite(n) || n <= 0) return '';
        n = Math.round(n);
        var t = String(n);
        return t.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    function vrFa(n) {
        return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); });
    }

    function renderFilters() {
        var bar = document.getElementById('vrFilterBar');
        if (!bar) return;
        var html = '<button type="button" class="btn-secondary vr-filter-btn' + (vrFilter === 'all' ? ' active' : '') + '" onclick="filterAdminVisits(\'all\', this)">همه</button>';
        Object.keys(vrStatus).forEach(function (k) {
            html += '<button type="button" class="btn-secondary vr-filter-btn' + (vrFilter === k ? ' active' : '') + '" onclick="filterAdminVisits(\'' + k + '\', this)">' + vrEsc(vrStatus[k]) + '</button>';
        });
        html += '<button type="button" class="btn-secondary vr-filter-btn' + (vrFilter === 'archived' ? ' active' : '') + '" onclick="filterAdminVisits(\'archived\', this)">بایگانی</button>';
        html += '<button type="button" class="btn-secondary" onclick="loadAdminVisits()" style="margin-inline-start:auto;">↻ بروزرسانی</button>';
        bar.innerHTML = html;
    }

    var vrWeekdayOpts = [
        { w: 6, name: 'شنبه' }, { w: 0, name: 'یکشنبه' }, { w: 1, name: 'دوشنبه' },
        { w: 2, name: 'سه‌شنبه' }, { w: 3, name: 'چهارشنبه' }, { w: 4, name: 'پنجشنبه' }, { w: 5, name: 'جمعه' }
    ];
    var vrClosedDates = {};
    var vrCalJy = 1404, vrCalJm = 1;
    var vrJMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

    function g2j(gy, gm, gd) {
        var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        var gy2 = (gm > 2) ? (gy + 1) : gy;
        var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
            + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
        var jy = -1595 + (33 * Math.floor(days / 12053));
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var jm, jd;
        if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
        else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
        return [jy, jm, jd];
    }
    function j2g(jy, jm, jd) {
        jy += 1595;
        var days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd
            + ((jm < 7) ? ((jm - 1) * 31) : (((jm - 7) * 30) + 186));
        var gy = 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * Math.floor(--days / 36524);
            days %= 36524;
            if (days >= 365) days++;
        }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
        var gd = days + 1;
        var sal = [0, 31, (((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        var gm = 1;
        for (var i = 1; i <= 12; i++) {
            if (gd <= sal[i]) { gm = i; break; }
            gd -= sal[i];
        }
        return [gy, gm, gd];
    }
    function jMonthDays(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        var leaps = [1, 5, 9, 13, 17, 22, 26, 30];
        return leaps.indexOf(jy % 33) >= 0 ? 30 : 29;
    }
    function isoOf(jy, jm, jd) {
        var g = j2g(jy, jm, jd);
        var m = g[1] < 10 ? '0' + g[1] : '' + g[1];
        var d = g[2] < 10 ? '0' + g[2] : '' + g[2];
        return g[0] + '-' + m + '-' + d;
    }
    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    function renderCalendar() {
        var title = document.getElementById('vrCalTitle');
        if (title) title.textContent = vrJMonths[vrCalJm - 1] + ' ' + vrFa(vrCalJy);
        var head = document.getElementById('vrCalHead');
        if (head && !head.childElementCount) {
            ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach(function (w) {
                var d = document.createElement('div');
                d.className = 'vr-cal-wd';
                d.textContent = w;
                head.appendChild(d);
            });
        }
        var body = document.getElementById('vrCalBody');
        if (!body) return;
        body.innerHTML = '';
        var g1 = j2g(vrCalJy, vrCalJm, 1);
        var dt = new Date(g1[0], g1[1] - 1, g1[2]);
        var phpW = dt.getDay();
        var start = (phpW + 1) % 7;
        var dim = jMonthDays(vrCalJy, vrCalJm);
        var i;
        for (i = 0; i < start; i++) {
            var e = document.createElement('button');
            e.type = 'button';
            e.className = 'vr-cal-day is-empty';
            body.appendChild(e);
        }
        for (var day = 1; day <= dim; day++) {
            var iso = isoOf(vrCalJy, vrCalJm, day);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'vr-cal-day';
            var gg = j2g(vrCalJy, vrCalJm, day);
            var wd = new Date(gg[0], gg[1] - 1, gg[2]).getDay();
            if (wd === 5) btn.classList.add('is-fri');
            if (vrClosedDates[iso]) btn.classList.add('is-on');
            btn.textContent = vrFa(day);
            btn.setAttribute('data-iso', iso);
            btn.onclick = function () {
                var k = this.getAttribute('data-iso');
                if (vrClosedDates[k]) delete vrClosedDates[k];
                else vrClosedDates[k] = true;
                this.classList.toggle('is-on');
                updateHolidayCount();
            };
            body.appendChild(btn);
        }
        updateHolidayCount();
    }
    function updateHolidayCount() {
        var el = document.getElementById('vrHolidayCount');
        if (el) el.textContent = vrFa(Object.keys(vrClosedDates).length);
    }
    window.vrCalMove = function (delta) {
        vrCalJm += delta;
        if (vrCalJm < 1) { vrCalJm = 12; vrCalJy--; }
        if (vrCalJm > 12) { vrCalJm = 1; vrCalJy++; }
        renderCalendar();
    };

    window.loadVisitHolidays = async function () {
        var box = document.getElementById('vrHolidayWeekdays');
        if (!box) return;
        try {
            var r = await fetch('visit-request-api.php?action=admin_holidays', { cache: 'no-store', credentials: 'same-origin' });
            var data = await r.json();
            if (!data || data.success === false) return;
            if (Array.isArray(data.weekday_options) && data.weekday_options.length) vrWeekdayOpts = data.weekday_options;
            var selected = {};
            (data.weekdays || []).forEach(function (w) { selected[Number(w)] = true; });
            box.innerHTML = vrWeekdayOpts.map(function (opt) {
                var w = Number(opt.w);
                var on = !!selected[w];
                return '<label style="display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:10px;padding:6px 10px;cursor:pointer;">'
                    + '<input type="checkbox" class="vr-holi-w" value="' + w + '"' + (on ? ' checked' : '') + '> '
                    + vrEsc(opt.name) + '</label>';
            }).join('');
            vrClosedDates = {};
            (data.dates || []).forEach(function (iso) { if (iso) vrClosedDates[iso] = true; });
            var now = new Date();
            var j = g2j(now.getFullYear(), now.getMonth() + 1, now.getDate());
            vrCalJy = j[0];
            vrCalJm = j[1];
            renderCalendar();
        } catch (e) {}
    };

    window.saveVisitHolidays = async function () {
        var msg = document.getElementById('vrHolidayMsg');
        if (msg) msg.textContent = 'در حال ذخیره…';
        var weekdays = [];
        document.querySelectorAll('.vr-holi-w:checked').forEach(function (el) { weekdays.push(el.value); });
        var dates = Object.keys(vrClosedDates).sort();
        var fd = new FormData();
        fd.append('weekdays', JSON.stringify(weekdays));
        fd.append('dates', dates.join('\n'));
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_save_holidays', {
                method: 'POST', body: fd, credentials: 'same-origin'
            });
            var data = await r.json();
            if (msg) msg.textContent = (data && data.message) ? data.message : (data && data.success ? 'ذخیره شد.' : 'ذخیره نشد.');
            if (data && data.success) loadVisitHolidays();
        } catch (e) {
            if (msg) msg.textContent = 'ذخیره نشد.';
        }
    };

    window.vrSwitchSection = function (sec) {
        vrFilter = sec === 'archived' ? 'archived' : 'all';
        var cur = document.getElementById('vrSegCurrent');
        var arc = document.getElementById('vrSegArchived');
        if (cur) { cur.className = vrFilter === 'all' ? 'btn-primary' : 'btn-secondary'; }
        if (arc) { arc.className = vrFilter === 'archived' ? 'btn-primary' : 'btn-secondary'; }
        loadAdminVisits();
    };

    window.loadVisitCapacity = async function () {
        try {
            var r = await fetch('visit-request-api.php?action=admin_holidays', { cache: 'no-store', credentials: 'same-origin' });
            var d = await r.json();
        } catch (e) { return; }
        try {
            var r2 = await fetch('visit-request-api.php?action=days', { cache: 'no-store' });
            var d2 = await r2.json();
            if (d2 && d2.days && d2.days[0] && d2.days[0].capacity) {
                var m = document.getElementById('vrCapMorning');
                var e = document.getElementById('vrCapEvening');
                if (m) m.value = Number(d2.days[0].capacity.morning || 0);
                if (e) e.value = Number(d2.days[0].capacity.evening || 0);
            }
        } catch (e2) {}
    };

    window.saveVisitCapacity = async function () {
        var msg = document.getElementById('vrCapMsg');
        if (msg) msg.textContent = 'در حال ذخیره…';
        var fd = new FormData();
        fd.append('morning', document.getElementById('vrCapMorning').value || '0');
        fd.append('evening', document.getElementById('vrCapEvening').value || '0');
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_save_capacity', { method: 'POST', body: fd, credentials: 'same-origin' });
            var d = await r.json();
            if (msg) msg.textContent = (d && d.message) || (d && d.success ? 'ذخیره شد.' : 'ذخیره نشد.');
            setTimeout(function () { if (msg) msg.textContent = ''; }, 4000);
        } catch (e) {
            if (msg) msg.textContent = 'ذخیره نشد.';
        }
    };

    window.filterAdminVisits = function (status, btn) {
        vrFilter = status || 'all';
        document.querySelectorAll('.vr-filter-btn').forEach(function (el) {
            el.classList.toggle('active', el === btn);
        });
        loadAdminVisits();
    };

    window.loadAdminVisits = async function () {
        var box = document.getElementById('visitsListContainer');
        if (!box) return;
        box.textContent = 'در حال بارگذاری…';
        renderFilters();
        loadVisitHolidays();
        loadVisitCapacity();
        try {
            var r = await fetch('visit-request-api.php?action=admin_list&status=' + encodeURIComponent(vrFilter), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            var raw = await r.text();
            var data = null;
            try { data = JSON.parse(raw); } catch (pe) { data = null; }
            if (!data) {
                box.textContent = 'خطا در بارگذاری.';
                return;
            }
            if (data.success === false) {
                box.textContent = data.message || 'خطا در بارگذاری.';
                return;
            }
            if (data.slots) vrSlots = data.slots;
            if (data.statuses) {
                vrStatus = data.statuses;
            }
            var c = data.counts || {};
            var setN = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = String(v == null ? 0 : v); };
            setN('vrStatAll', c.all);
            setN('vrStatNew', c.new);
            setN('vrStatScheduled', c.scheduled_owner);
            setN('vrStatRejected', c.owner_rejected_date);
            var items = Array.isArray(data.items) ? data.items : [];
            var cnt = document.getElementById('visitsResultCount');
            if (cnt) cnt.textContent = items.length + ' مورد';
            if (!items.length) {
                box.innerHTML = '<div class="consultant-empty">درخواستی ثبت نشده است.</div>';
                return;
            }
            box.innerHTML = items.map(function (it) {
                var id = Number(it.id || 0);
                var st = String(it.status || 'new');
                if (!st) st = 'new';
                // رنگ فوریت: گذشته=قرمز، امروز/فردا=نارنجی، ۲ روز به بعد=سبز
                var urgCls = '', urgLabel = '';
                var pd = String(it.preferred_date || '');
                if (/^\d{4}-\d{2}-\d{2}$/.test(pd)) {
                    var pdDate = new Date(pd + 'T00:00:00');
                    var now = new Date();
                    var today0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                    var diff = Math.round((pdDate - today0) / 86400000);
                    if (diff < 0) { urgCls = 'vr-urg-past'; urgLabel = 'گذشته'; }
                    else if (diff <= 1) { urgCls = 'vr-urg-soon'; urgLabel = diff === 0 ? 'امروز' : 'فردا'; }
                    else { urgCls = 'vr-urg-later'; urgLabel = vrFa(diff) + ' روز مانده'; }
                }
                var info = it.ad_info || {};
                var title = info.title || it.ad_title || ('آگهی ' + (it.ad_id || ''));
                var track = it.tracking_code || ('VR-' + String(id).padStart(5, '0'));
                var wp = it.when_parts || {};
                var when = it.when_label || [wp.weekday, wp.date, wp.slot].filter(Boolean).join(' · ');
                var statusKeys = Object.keys(vrStatus);
                var curIdx = statusKeys.indexOf(st);
                if (curIdx < 0) curIdx = 0;
                var statusBtns = statusKeys.map(function (k, i) {
                    var bg, fg, bd;
                    if (i < curIdx) { bg = '#C0392B'; fg = '#fff'; bd = '#C0392B'; }
                    else if (i === curIdx) { bg = '#15803D'; fg = '#fff'; bd = '#15803D'; }
                    else { bg = 'transparent'; fg = '#6B7280'; bd = '#9CA3AF'; }
                    var style = 'background:' + bg + ';border-color:' + bd + ';color:' + fg + ';';
                    return '<button type="button" class="btn-secondary vr-st-btn' + (i === curIdx ? ' is-on' : '') + '" style="' + style + '" onclick="saveAdminVisitStatus(' + id + ',\'' + k + '\')">' + vrEsc(vrStatus[k]) + '</button>';
                }).join('');
                var adBits = '';
                adBits += vrLine('عنوان آگهی', title);
                adBits += vrLine('کد آگهی', info.ad_code);
                adBits += vrLine('نوع ملک', info.property_type);
                adBits += vrLine('نوع معامله', info.transaction_type);
                adBits += vrLine('موقعیت', info.location);
                adBits += vrLine('آدرس', info.address);
                adBits += vrLine('متراژ', info.area);
                adBits += vrLine('خواب', info.rooms);
                adBits += vrLine('طبقه', info.floor);
                adBits += vrLine('قیمت', vrMoney(info.price_sell || info.display_price) || info.price_sell || info.display_price);
                adBits += vrLine('رهن', vrMoney(info.deposit) || info.deposit);
                adBits += vrLine('اجاره', vrMoney(info.rent_monthly) || info.rent_monthly);
                var adLink = it.ad_id
                    ? '<a href="property-details.php?id=' + encodeURIComponent(it.ad_id) + '" target="_blank" rel="noopener">مشاهده آگهی</a>'
                    : '';
                var note = it.admin_note || '';
                var editBox = '' +
                    '<div style="border:1px dashed var(--border);border-radius:10px;padding:10px;margin-top:8px;">'
                    + '<div style="font-weight:900;font-size:11.5px;margin-bottom:6px;">✏️ ویرایش بازدید</div>'
                    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">'
                    + '<input id="vrEn' + id + '" placeholder="نام" value="' + vrEsc(it.requester_name || it.name || '') + '" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12px;">'
                    + '<input id="vrEp' + id + '" placeholder="شماره" dir="ltr" value="' + vrEsc(it.requester_phone || it.phone || '') + '" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12px;">'
                    + '<input id="vrEd' + id + '" placeholder="تاریخ (مثلاً 1404/07/15 یا 2026-10-06)" dir="ltr" value="' + vrEsc(it.preferred_date || '') + '" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12px;">'
                    + '<select id="vrEs' + id + '" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12px;">'
                    + Object.keys(vrSlots).map(function (k) { return '<option value="' + k + '"' + (it.time_slot === k ? ' selected' : '') + '>' + vrEsc(vrSlots[k]) + '</option>'; }).join('')
                    + '</select>'
                    + '</div>'
                    + '<button type="button" class="btn-primary vr-st-btn" style="margin-top:6px;" onclick="adminEditVisit(' + id + ')">ذخیرهٔ ویرایش</button>'
                    + '</div>';
                return '<div class="vr-card ' + urgCls + '">'
                    + '<div class="vr-card-top">'
                    + '<div class="vr-card-title">' + vrEsc(title) + '</div>'
                    + '<span class="vr-chip">' + vrEsc(track) + ' · ' + vrEsc(it.status_label || vrStatus[st] || st) + (urgLabel ? ' · ' + urgLabel : '') + '</span>'
                    + '</div>'
                    + '<div class="vr-who">' + vrEsc(it.requester_name || it.name || '—') + ' · <span dir="ltr">' + vrEsc(it.requester_phone || it.phone || '') + '</span> · ' + vrEsc(when) + '</div>'
                    + '<div class="vr-actions">' + statusBtns
                    + '<button type="button" class="btn-secondary vr-st-btn" style="color:#C0392B;border-color:#C0392B;" onclick="adminDeleteVisit(' + id + ')">حذف</button>'
                    + (vrFilter === 'archived' ? '' : '<button type="button" class="btn-secondary vr-st-btn" onclick="adminArchiveVisit(' + id + ')">بایگانی</button>')
                    + '</div>'
                    + '<details class="vr-more"><summary>جزئیات و یادداشت</summary>'
                    + '<div class="vr-block">'
                    + vrLine('روز', wp.weekday || it.weekday)
                    + vrLine('تاریخ', wp.date || it.preferred_date_fa || it.preferred_date)
                    + vrLine('زمان', wp.slot || vrSlots[it.time_slot] || it.time_slot)
                    + vrLine('زمان جایگزین', it.alternative_datetime)
                    + vrLine('آگهی‌دهنده', it.advertiser_last_name)
                    + vrLine('تلفن آگهی‌دهنده', it.advertiser_phone)
                    + adBits
                    + (adLink ? '<div style="margin-top:4px;">' + adLink + '</div>' : '')
                    + '</div>'
                    + editBox
                    + '<textarea id="vrNote' + id + '" rows="2" style="width:100%;box-sizing:border-box;padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12px;margin-top:8px;">' + vrEsc(note) + '</textarea>'
                    + '<button type="button" class="btn-primary vr-st-btn" style="margin-top:6px;" onclick="saveAdminVisitNote(' + id + ')">ذخیره یادداشت</button>'
                    + '</details></div>';
            }).join('');
        } catch (e) {
            box.textContent = 'خطا در بارگذاری.';
        }
    };

    window.saveAdminVisitStatus = async function (id, status) {
        var fd = new FormData();
        fd.append('id', String(id));
        fd.append('status', status);
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_set_status', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var data = await r.json();
            if (!data || data.success === false) {
                alert((data && data.message) ? data.message : 'ذخیره نشد.');
                return;
            }
            loadAdminVisits();
        } catch (e) {
            alert('ذخیره نشد.');
        }
    };

    window.saveAdminVisitNote = async function (id) {
        var ta = document.getElementById('vrNote' + id);
        var fd = new FormData();
        fd.append('id', String(id));
        fd.append('admin_note', ta ? ta.value : '');
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_save_note', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });
            var data = await r.json();
            alert((data && data.message) ? data.message : (data && data.success ? 'ذخیره شد.' : 'ذخیره نشد.'));
        } catch (e) {
            alert('ذخیره نشد.');
        }
    };

    window.adminEditVisit = async function (id) {
        var fd = new FormData();
        fd.append('id', String(id));
        fd.append('requester_name', (document.getElementById('vrEn' + id) || {}).value || '');
        fd.append('requester_phone', (document.getElementById('vrEp' + id) || {}).value || '');
        fd.append('preferred_date', (document.getElementById('vrEd' + id) || {}).value || '');
        fd.append('time_slot', (document.getElementById('vrEs' + id) || {}).value || '');
        fd.append('alternative_datetime', '');
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_edit', { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await r.json();
            alert((data && data.message) || (data && data.success ? 'ذخیره شد.' : 'ذخیره نشد.'));
            if (data && data.success) loadAdminVisits();
        } catch (e) {
            alert('ذخیره نشد.');
        }
    };

    window.adminDeleteVisit = async function (id) {
        if (!confirm('آیا از حذف اطمینان دارید؟')) return;
        var fd = new FormData();
        fd.append('id', String(id));
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_delete', {
                method: 'POST', body: fd, credentials: 'same-origin'
            });
            var data = await r.json();
            if (!data || data.success === false) {
                alert((data && data.message) ? data.message : 'حذف نشد.');
                return;
            }
            loadAdminVisits();
        } catch (e) {
            alert('حذف نشد.');
        }
    };

    window.adminArchiveVisit = async function (id) {
        var fd = new FormData();
        fd.append('id', String(id));
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        try {
            var r = await fetch('visit-request-api.php?action=admin_archive', {
                method: 'POST', body: fd, credentials: 'same-origin'
            });
            var data = await r.json();
            if (!data || data.success === false) {
                alert((data && data.message) ? data.message : 'بایگانی نشد.');
                return;
            }
            loadAdminVisits();
        } catch (e) {
            alert('بایگانی نشد.');
        }
    };
})();
</script>
