<?php
/** کارت «قفل سایت و بازدید» در داشبورد ادمین. */
?>
<section class="admin-card" id="siteRuntimeCard" style="margin: 0 0 20px; border: 2px solid var(--primary);">
    <div class="card-header">
        <span class="card-title">قفل سایت</span>
        <span id="srLockBadge" style="font-size: 13px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: #eef6f3; color: #0b5d5b;">در حال بارگذاری…</span>
    </div>
    <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 20px; padding: 12px 0;">
        <div style="flex: 1 1 280px; min-width: 240px;">
            <label for="srLockMsg" style="display: block; margin-bottom: 6px; font-weight: 600;">پیام صفحهٔ قفل (برای بازدیدکنندگان ناشناس)</label>
            <textarea id="srLockMsg" rows="2" maxlength="255" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary);" placeholder="سایت موقتاً در دسترس نیست."></textarea>
            <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="srLockToggle" style="background: var(--primary); color: #fff; border: none; padding: 10px 22px; border-radius: 6px; font-weight: 600; cursor: pointer;">قفل سایت</button>
                <span id="srUpdated" style="font-size: 12px; color: var(--text-secondary);"></span>
            </div>
            <div style="margin-top: 8px; font-size: 12px; color: var(--text-secondary);">
                وقتی سایت قفل است، فقط ادمین و کاربران واردشده صفحهٔ اصلی را می‌بینند.
            </div>
        </div>
    </div>
    <div id="srErr" style="color: #b42318; font-size: 13px; min-height: 0;"></div>
</section>
<script>
(function () {
    var API = 'admin-site-runtime-api.php';
    var lock = { locked: 0, message: '' };
    function $(id) { return document.getElementById(id); }
    function setErr(msg) { $('srErr').textContent = msg || ''; }
    function render(d) {
        lock = d.lock || lock;
        var badge = $('srLockBadge');
        if (lock.locked) {
            badge.textContent = 'قفل فعال است';
            badge.style.background = '#fdecea';
            badge.style.color = '#b42318';
            $('srLockToggle').textContent = 'باز کردن قفل';
        } else {
            badge.textContent = 'سایت باز است';
            badge.style.background = '#eef6f3';
            badge.style.color = '#0b5d5b';
            $('srLockToggle').textContent = 'قفل سایت';
        }
        if (document.activeElement !== $('srLockMsg')) { $('srLockMsg').value = lock.message || ''; }
        $('srUpdated').textContent = lock.updated_at ? ('آخرین تغییر: ' + lock.updated_at + ' ' + (lock.updated_by || '')) : '';
    }
    function load() {
        fetch(API + '?action=stats', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j.success) { throw new Error(x.j.message || 'خطا در دریافت آمار'); }
                setErr('');
                render(x.j);
            })
            .catch(function (e) { setErr('خطا: ' + e.message); });
    }
    $('srLockToggle').addEventListener('click', function () {
        var want = lock.locked ? 0 : 1;
        var fd = new FormData();
        fd.append('locked', String(want));
        fd.append('message', $('srLockMsg').value || '');
        if (window.MELKINO_CSRF) { fd.append('csrf_token', window.MELKINO_CSRF); }
        var headers = {};
        if (window.MELKINO_CSRF) { headers['X-CSRF-Token'] = window.MELKINO_CSRF; }
        $('srLockToggle').disabled = true;
        fetch(API + '?action=set_lock', { method: 'POST', body: fd, headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j.success) { throw new Error(x.j.message || 'ذخیره نشد'); }
                setErr('');
                lock = x.j.lock;
                load();
            })
            .catch(function (e) { setErr('خطا: ' + e.message); })
            .finally(function () { $('srLockToggle').disabled = false; });
    });
    load();
})();
</script>
