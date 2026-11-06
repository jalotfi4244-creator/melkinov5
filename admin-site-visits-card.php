<?php
/** آمار بازدید سایت (بازدیدکننده یکتا، بدون IP خام) در تب کاربران ادمین. فقط ادمین. */
?>
<section class="admin-card" id="siteVisitsCard" style="margin: 0 16px 16px; padding: 16px; border: 1px solid var(--border); border-radius: 12px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
        <span style="font-size: 15px; font-weight: 800;">بازدید سایت</span>
        <span style="font-size: 12px; color: var(--text-secondary);">بازدیدکننده یکتا از ترکیب IP و کوکی ناشناس؛ IP خام ذخیره نمی‌شود. بازدید ادمین شمارش نمی‌شود.</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px; text-align: center;">
            <div style="font-size: 12px; color: var(--text-secondary);">بازدیدکننده امروز</div>
            <div id="svcToday" style="font-size: 22px; font-weight: 800;">—</div>
        </div>
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px; text-align: center;">
            <div style="font-size: 12px; color: var(--text-secondary);">بازدید امروز</div>
            <div id="svcHitsToday" style="font-size: 22px; font-weight: 800;">—</div>
        </div>
        <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 12px; text-align: center;">
            <div style="font-size: 12px; color: var(--text-secondary);">کل بازدیدکنندگان یکتا</div>
            <div id="svcAll" style="font-size: 22px; font-weight: 800;">—</div>
        </div>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; margin-top: 14px;">
        <div>
            <div style="font-weight: 700; margin-bottom: 6px;">هفت روز اخیر</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead><tr><th style="text-align: right; padding: 4px;">روز</th><th style="text-align: right; padding: 4px;">بازدیدکننده</th><th style="text-align: right; padding: 4px;">بازدید</th></tr></thead>
                <tbody id="svcSeries"><tr><td colspan="3" style="padding: 4px; color: var(--text-secondary);">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
        <div>
            <div style="font-weight: 700; margin-bottom: 6px;">پربازدیدترین صفحه‌ها (هفت روز اخیر)</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <thead><tr><th style="text-align: right; padding: 4px;">مسیر</th><th style="text-align: right; padding: 4px;">بازدید</th></tr></thead>
                <tbody id="svcPages"><tr><td colspan="2" style="padding: 4px; color: var(--text-secondary);">در حال بارگذاری…</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="svcErr" style="color: #b42318; font-size: 13px; margin-top: 8px; min-height: 0;"></div>
</section>
<script>
(function () {
    var API = 'admin-site-runtime-api.php';
    function $(id) { return document.getElementById(id); }
    function num(n) { return (Number(n) || 0).toLocaleString('fa-IR'); }
    function cell(text, align) {
        var td = document.createElement('td');
        td.style.padding = '4px';
        td.style.textAlign = align || 'right';
        td.textContent = text;
        return td;
    }
    function rowOf(cells) {
        var tr = document.createElement('tr');
        cells.forEach(function (c) { tr.appendChild(c); });
        return tr;
    }
    function emptyRow(tbody, cols, text) {
        tbody.innerHTML = '';
        var td = document.createElement('td');
        td.colSpan = cols;
        td.style.padding = '4px';
        td.style.color = 'var(--text-secondary)';
        td.textContent = text;
        var tr = document.createElement('tr');
        tr.appendChild(td);
        tbody.appendChild(tr);
    }
    function render(d) {
        $('svcToday').textContent = num(d.visitors.today_unique);
        $('svcHitsToday').textContent = num(d.visitors.today_hits);
        $('svcAll').textContent = num(d.visitors.all_unique);
        var sb = $('svcSeries');
        sb.innerHTML = '';
        (d.series7 || []).forEach(function (r) {
            sb.appendChild(rowOf([cell(String(r.day).slice(5)), cell(num(r.visitors)), cell(num(r.hits))]));
        });
        if (!(d.series7 || []).length) { emptyRow(sb, 3, 'بازدیدی ثبت نشده است.'); }
        var pb = $('svcPages');
        pb.innerHTML = '';
        (d.pages7 || []).forEach(function (r) {
            pb.appendChild(rowOf([cell(String(r.path), 'left'), cell(num(r.hits))]));
        });
        if (!(d.pages7 || []).length) { emptyRow(pb, 2, 'بازدیدی ثبت نشده است.'); }
    }
    function load() {
        fetch(API + '?action=stats', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j.success) { throw new Error(x.j.message || 'خطا در دریافت آمار'); }
                $('svcErr').textContent = '';
                render(x.j);
            })
            .catch(function (e) { $('svcErr').textContent = 'خطا: ' + e.message; });
    }
    load();
})();
</script>
