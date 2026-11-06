<?php require_once __DIR__ . '/config.php';
if (!function_exists('melkinoOnboardingFirstLogoUrl') || !function_exists('melkinoOnboardingFirstPage')) {
    require_once __DIR__ . '/melkino-logo.php';
}
$mkObFirstUrl = function_exists('melkinoOnboardingFirstLogoUrl') ? melkinoOnboardingFirstLogoUrl() : '';
$mkObFirstPage = function_exists('melkinoOnboardingFirstPage') ? melkinoOnboardingFirstPage() : ['title' => 'به ملکینو خوش آمدید', 'text' => 'سامانه جامع جستجو و ثبت ملک.'];
$mkObFirstTitle = (string) ($mkObFirstPage['title'] ?? '');
$mkObFirstText = (string) ($mkObFirstPage['text'] ?? '');
?>
<div class="admin-card" style="margin-top:20px;border:2px solid var(--primary);">
    <div class="card-header">
        <span class="card-title">صفحهٔ اول هدایت</span>
    </div>
    <p style="font-size:13px;line-height:1.9;color:var(--text-secondary);margin:0 0 14px;">
        این صفحه تمام‌صفحه است: لوگو، عنوان زرد، متن سفید. هر وقت خواستید عنوان و متن را عوض کنید و ذخیره کنید.
    </p>
    <div style="display:grid;grid-template-columns:minmax(240px,1fr) minmax(240px,1fr);gap:20px;align-items:start;">
        <div>
            <div class="admin-field">
                <label for="obFirstTitle">عنوان (زرد)</label>
                <input id="obFirstTitle" type="text" maxlength="80" value="<?= htmlspecialchars($mkObFirstTitle, ENT_QUOTES, 'UTF-8') ?>" placeholder="مثلاً به ملکینو خوش آمدید">
            </div>
            <div class="admin-field">
                <label for="obFirstText">متن (سفید)</label>
                <textarea id="obFirstText" rows="4" maxlength="500" style="width:100%;padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--surface);color:var(--text-primary);font-family:inherit;line-height:1.8;resize:vertical;"><?= htmlspecialchars($mkObFirstText, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="admin-field">
                <label for="obFirstLogoFile">لوگوی این صفحه</label>
                <input type="file" id="obFirstLogoFile" accept=".png,.jpg,.jpeg,.webp" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text-primary);">
                <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">PNG / JPG / WEBP — حداکثر ۵ مگابایت. لوگوی هدر سایت عوض نمی‌شود.</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px;">
                <button type="button" id="obFirstCopyBtn" class="btn-icon-sm primary" style="background:var(--primary);color:#fff;border:none;padding:10px 22px;border-radius:6px;font-weight:600;cursor:pointer;">ذخیره نوشته‌ها</button>
                <button type="button" id="obFirstLogoBtn" class="btn-icon-sm primary" style="background:var(--primary);color:#fff;border:none;padding:10px 22px;border-radius:6px;font-weight:600;cursor:pointer;">آپلود لوگو</button>
                <button type="button" id="obFirstLogoDel" class="btn-icon-sm" style="padding:10px 16px;border-radius:6px;cursor:pointer;">حذف لوگو</button>
            </div>
            <div id="obFirstLogoStatus" style="margin-top:8px;font-size:0.85rem;color:var(--text-secondary);"></div>
        </div>
        <div>
            <div style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">پیش‌نمایش زنده</div>
            <div id="obFirstPreview" style="background:#0c1412;border-radius:16px;padding:28px 20px 32px;min-height:280px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:1px solid #1c2a26;">
                <div id="obFirstLogoBox" style="min-height:90px;display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <?php if ($mkObFirstUrl !== ''): ?>
                        <img id="obFirstLogoPreview" src="<?= htmlspecialchars($mkObFirstUrl, ENT_QUOTES, 'UTF-8') ?>" alt="لوگو" style="max-width:140px;max-height:140px;object-fit:contain;">
                    <?php else: ?>
                        <img id="obFirstLogoPreview" alt="" style="display:none;max-width:140px;max-height:140px;object-fit:contain;">
                        <span id="obFirstLogoEmpty" style="font-size:13px;color:#8a9;">لوگو آپلود نشده</span>
                    <?php endif; ?>
                </div>
                <div id="obFirstPrevTitle" style="color:#f5c518;font-size:22px;font-weight:800;line-height:1.5;margin-bottom:10px;"><?= htmlspecialchars($mkObFirstTitle, ENT_QUOTES, 'UTF-8') ?></div>
                <div id="obFirstPrevText" style="color:#fff;font-size:15px;line-height:1.9;max-width:320px;"><?= htmlspecialchars($mkObFirstText, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    function $(id) { return document.getElementById(id); }
    function setStatus(msg, color) {
        var el = $('obFirstLogoStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.style.color = color || 'var(--text-secondary)';
    }
    function syncPreview() {
        var t = $('obFirstTitle');
        var x = $('obFirstText');
        var pt = $('obFirstPrevTitle');
        var px = $('obFirstPrevText');
        if (pt && t) pt.textContent = t.value || 'عنوان';
        if (px && x) px.textContent = x.value || 'متن';
    }
    function showPreview(url) {
        var img = $('obFirstLogoPreview');
        var empty = $('obFirstLogoEmpty');
        if (!img) return;
        if (url) {
            img.src = url;
            img.style.display = 'block';
            if (empty) empty.style.display = 'none';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
            if (empty) empty.style.display = '';
            else {
                empty = document.createElement('span');
                empty.id = 'obFirstLogoEmpty';
                empty.style.cssText = 'font-size:13px;color:#8a9;';
                empty.textContent = 'لوگو آپلود نشده';
                var box = $('obFirstLogoBox');
                if (box) box.appendChild(empty);
            }
        }
    }
    function postForm(fd, onOk) {
        if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
        fetch('upload_onboarding_first_logo.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (r) { return r.text().then(function (t) { return { r: r, t: t }; }); })
        .then(function (x) {
            var j = {};
            try { j = JSON.parse(x.t); } catch (e) { throw new Error('پاسخ نامعتبر از سرور'); }
            if (!x.r.ok || !j.success) throw new Error(j.message || 'ذخیره انجام نشد.');
            onOk(j);
        }).catch(function (e) {
            setStatus(e.message || 'خطا', 'red');
        });
    }
    var titleEl = $('obFirstTitle');
    var textEl = $('obFirstText');
    if (titleEl) titleEl.addEventListener('input', syncPreview);
    if (textEl) textEl.addEventListener('input', syncPreview);
    var copyBtn = $('obFirstCopyBtn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            copyBtn.disabled = true;
            setStatus('در حال ذخیره نوشته‌ها...');
            var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
            if (window.MELKINO_CSRF) headers['X-CSRF-Token'] = window.MELKINO_CSRF;
            fetch('save_onboarding_first_page.php', {
                method: 'POST',
                headers: headers,
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({
                    title: titleEl ? titleEl.value : '',
                    text: textEl ? textEl.value : '',
                    csrf_token: window.MELKINO_CSRF || ''
                })
            }).then(function (r) { return r.text().then(function (t) { return { r: r, t: t }; }); })
            .then(function (x) {
                var j = {};
                try { j = JSON.parse(x.t); } catch (e) { throw new Error('پاسخ نامعتبر از سرور'); }
                if (!x.r.ok || !j.success) throw new Error(j.message || 'ذخیره انجام نشد.');
                if (titleEl && j.title != null) titleEl.value = j.title;
                if (textEl && j.text != null) textEl.value = j.text;
                syncPreview();
                setStatus(j.message || 'ذخیره شد.', 'green');
            }).catch(function (e) {
                setStatus(e.message || 'خطا', 'red');
            }).finally(function () {
                copyBtn.disabled = false;
            });
        });
    }
    var fileInput = $('obFirstLogoFile');
    var upBtn = $('obFirstLogoBtn');
    var delBtn = $('obFirstLogoDel');
    if (upBtn) {
        upBtn.addEventListener('click', function () {
            var file = fileInput && fileInput.files && fileInput.files[0];
            if (!file) { setStatus('لطفاً یک فایل انتخاب کنید.'); return; }
            if (file.size > 5 * 1024 * 1024) { setStatus('حجم فایل بیشتر از ۵ مگابایت است.', 'red'); return; }
            var fd = new FormData();
            fd.append('onboarding_first_logo', file);
            setStatus('در حال آپلود لوگو...');
            postForm(fd, function (j) {
                var u = j.logo_url || '';
                if (u) u += (u.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
                showPreview(u);
                setStatus(j.message || 'لوگو ذخیره شد.', 'green');
                if (fileInput) fileInput.value = '';
            });
        });
    }
    if (delBtn) {
        delBtn.addEventListener('click', function () {
            if (!confirm('لوگوی صفحهٔ اول حذف شود؟')) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            postForm(fd, function (j) {
                showPreview('');
                setStatus(j.message || 'حذف شد.', 'green');
            });
        });
    }
})();
</script>
