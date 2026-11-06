/* واترمارک لوگو — کنترل دقیق در تب نمایش */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }

    var POSITIONS = [
        { id: 'top-right', label: 'بالا راست' },
        { id: 'top-center', label: 'بالا وسط' },
        { id: 'top-left', label: 'بالا چپ' },
        { id: 'middle-right', label: 'وسط راست' },
        { id: 'center', label: 'وسط' },
        { id: 'middle-left', label: 'وسط چپ' },
        { id: 'bottom-right', label: 'پایین راست' },
        { id: 'bottom-center', label: 'پایین وسط' },
        { id: 'bottom-left', label: 'پایین چپ' }
    ];

    var logoUrl = '';
    var currentPos = 'center';

    function faNum(n) {
        return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function ensureCss() {
        if ($('mkWmAdminCss')) return;
        var s = document.createElement('style');
        s.id = 'mkWmAdminCss';
        s.textContent =
            '#mkWmAdminCard .mk-wm-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;max-width:280px}' +
            '#mkWmAdminCard .mk-wm-pos{border:1px solid var(--border,#d5ddd9);background:var(--surface,#fff);border-radius:8px;padding:8px 4px;font-size:11px;cursor:pointer}' +
            '#mkWmAdminCard .mk-wm-pos.is-on{background:#0b5d59;color:#fff;border-color:#0b5d59}' +
            '#mkWmAdminCard .mk-wm-previews{display:grid;grid-template-columns:1fr 1.2fr;gap:12px}' +
            '#mkWmAdminCard .mk-wm-frame{position:relative;overflow:hidden;border-radius:12px;background:#1a2e2e}' +
            '#mkWmAdminCard .mk-wm-frame.card{height:150px}' +
            '#mkWmAdminCard .mk-wm-frame.details{height:170px}' +
            '#mkWmAdminCard .mk-wm-frame .bg{position:absolute;inset:0;background:linear-gradient(135deg,#f6f8f7,#dfe7e5 55%,#eef3f2)}' +
            '#mkWmAdminCard .mk-wm-frame.is-dark .bg{background:linear-gradient(135deg,#4a6a6a,#1a2e2e 55%,#2c4444)}' +
            '#mkWmAdminCard .mk-wm-cap{font-size:11px;color:var(--text-secondary,#6b7774);margin:6px 0 4px}' +
            '#mkWmAdminCard .mk-photo-wm{position:absolute;inset:0;pointer-events:none;z-index:5;overflow:hidden}' +
            '#mkWmAdminCard .mk-wm-frame img{position:absolute;height:auto!important;max-width:none!important;object-fit:contain}' +
            '@media (max-width:720px){#mkWmAdminCard .mk-wm-previews{grid-template-columns:1fr}}';
        document.head.appendChild(s);
    }

    function ensureCard() {
        var existing = $('mkWmAdminCard');
        if (existing && (!$('mkWmGap') || !$('mkWmLogoFile'))) {
            existing.parentNode.removeChild(existing);
        }
        if ($('mkWmAdminCard')) return true;
        var tab = $('tab-display');
        if (!tab) return false;
        ensureCss();
        var posBtns = POSITIONS.map(function (p) {
            return '<button type="button" class="mk-wm-pos' + (p.id === 'center' ? ' is-on' : '') + '" data-pos="' + p.id + '">' + p.label + '</button>';
        }).join('');
        var box = document.createElement('div');
        box.className = 'admin-card';
        box.id = 'mkWmAdminCard';
        box.style.marginBottom = '14px';
        box.innerHTML =
            '<div class="card-header"><span class="card-title">واترمارک لوگو روی عکس‌ها</span></div>' +
            '<div style="padding:14px 16px 16px">' +
            '<div class="admin-section-help" style="margin-bottom:12px">لوگو روی عکس کارت‌ها (خانه، همه آگهی‌ها، VIP) و گالری جزئیات می‌نشیند. اندازهٔ کارت و جزئیات جداست چون عرض عکس‌ها فرق دارد.</div>' +
            '<div class="admin-field" style="margin-bottom:14px;padding:12px;border:1px dashed var(--border,#d5ddd9);border-radius:10px">' +
            '<label>لوگوی واترمارک (جدا از لوگوی سایت)</label>' +
            '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px">' +
            '<img id="mkWmLogoPrev" alt="" style="height:52px;max-width:160px;object-fit:contain;background:#123;border-radius:8px;padding:6px">' +
            '<div><input id="mkWmLogoFile" type="file" accept="image/png,image/jpeg,image/webp">' +
            '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">' +
            '<button type="button" class="btn-icon-sm primary" id="mkWmLogoUpload">آپلود لوگو</button>' +
            '<button type="button" class="btn-icon-sm" id="mkWmLogoClear">حذف لوگوی واترمارک</button>' +
            '</div><small class="admin-section-help">PNG شفاف بهتر است. اگر خالی باشد همان لوگوی سایت روی عکس می‌آید.</small></div></div></div>' +
            '<label class="security-switch" style="margin-bottom:10px"><div><span>نمایش واترمارک</span><small>کلید اصلی؛ اگر خاموش باشد هیچ‌جا نمی‌آید.</small></div>' +
            '<input id="mkWmEnabled" type="checkbox" checked></label>' +
            '<label class="security-switch" style="margin-bottom:10px"><div><span>روی کارت‌ها</span><small>خانه، همه آگهی‌ها و VIP</small></div>' +
            '<input id="mkWmOnCards" type="checkbox" checked></label>' +
            '<label class="security-switch" style="margin-bottom:14px"><div><span>روی صفحهٔ جزئیات</span><small>گالری عکس ملک</small></div>' +
            '<input id="mkWmOnDetails" type="checkbox" checked></label>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>حالت</label>' +
            '<select id="mkWmMode" style="max-width:260px">' +
            '<option value="single">یک لوگو</option>' +
            '<option value="tile">تکرار مورب روی کل عکس</option>' +
            '</select></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>موقعیت روی عکس</label>' +
            '<div class="mk-wm-grid" id="mkWmPosGrid">' + posBtns + '</div></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>میزان دیده شدن لوگو: <b id="mkWmOpacityLabel">۴۰٪</b></label>' +
            '<input id="mkWmOpacity" type="range" min="12" max="90" step="1" value="40"></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>اندازه روی کارت: <b id="mkWmSizeLabel">۳۸٪</b></label>' +
            '<input id="mkWmSize" type="range" min="10" max="80" step="1" value="38"></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>اندازه روی جزئیات: <b id="mkWmSizeDetailsLabel">۳۰٪</b></label>' +
            '<input id="mkWmSizeDetails" type="range" min="10" max="80" step="1" value="30"></div>' +
            '<div id="mkWmTileFields" style="display:none">' +
            '<div class="admin-field" style="margin-bottom:12px" id="mkWmTileWrap"><label>اندازهٔ هر لوگو در تکرار: <b id="mkWmTileLabel">۲۲٪</b></label>' +
            '<input id="mkWmTile" type="range" min="8" max="45" step="1" value="22"></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>فاصلهٔ لوگوها از هم: <b id="mkWmGapLabel">۱۶٪</b></label>' +
            '<input id="mkWmGap" type="range" min="0" max="70" step="1" value="16"></div>' +
            '</div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>جابجایی افقی: <b id="mkWmOxLabel">۰٪</b></label>' +
            '<input id="mkWmOx" type="range" min="-40" max="40" step="1" value="0"></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>جابجایی عمودی: <b id="mkWmOyLabel">۰٪</b></label>' +
            '<input id="mkWmOy" type="range" min="-40" max="40" step="1" value="0"></div>' +
            '<div class="admin-field" style="margin-bottom:12px"><label>چرخش: <b id="mkWmRotLabel">۰°</b></label>' +
            '<input id="mkWmRot" type="range" min="-45" max="45" step="1" value="0"></div>' +
            '<div style="margin-bottom:8px"><button type="button" class="btn-icon-sm" id="mkWmBgToggle" aria-pressed="false">پس‌زمینهٔ تیره</button> <span class="admin-section-help">برای دیدن لوگو روی عکس‌های تیره</span></div>' +
            '<div class="mk-wm-previews">' +
            '<div><div class="mk-wm-cap">پیش‌نمایش کارت</div><div class="mk-wm-frame card" id="mkWmPrevCard"><div class="bg"></div></div></div>' +
            '<div><div class="mk-wm-cap">پیش‌نمایش جزئیات</div><div class="mk-wm-frame details" id="mkWmPrevDetails"><div class="bg"></div></div></div>' +
            '</div>' +
            '<div style="margin-top:14px">' +
            '<button type="button" class="btn-icon-sm primary" id="mkWmSave">ذخیره واترمارک</button>' +
            '<span id="mkWmState" class="admin-section-help" style="margin-right:10px"></span>' +
            '</div></div>';
        var sub = tab.querySelector('.fd-subtabs');
        if (sub && sub.parentNode) sub.parentNode.insertBefore(box, sub);
        else tab.insertBefore(box, tab.firstChild);
        return true;
    }

    function readState() {
        var enEl = $('mkWmEnabled');
        var cEl = $('mkWmOnCards');
        var dEl = $('mkWmOnDetails');
        return {
            enabled: enEl ? !!enEl.checked : true,
            on_cards: cEl ? !!cEl.checked : true,
            on_details: dEl ? !!dEl.checked : true,
            opacity: Number(($('mkWmOpacity') || {}).value || 32) / 100,
            size: Number(($('mkWmSize') || {}).value || 38),
            size_details: Number(($('mkWmSizeDetails') || {}).value || 30),
            position: currentPos || 'center',
            offset_x: Number(($('mkWmOx') || {}).value || 0),
            offset_y: Number(($('mkWmOy') || {}).value || 0),
            rotation: Number(($('mkWmRot') || {}).value || 0),
            mode: (($('mkWmMode') || {}).value === 'tile') ? 'tile' : 'single',
            tile_scale: Number(($('mkWmTile') || {}).value || 22),
            tile_gap: Number(($('mkWmGap') || {}).value || 16)
        };
    }

    function styleFor(scope, s) {
        var POS = {
            'center':        { x: 50, y: 50 },
            'top-left':      { x: 18, y: 16 },
            'top-center':    { x: 50, y: 16 },
            'top-right':     { x: 82, y: 16 },
            'middle-left':   { x: 18, y: 50 },
            'middle-right':  { x: 82, y: 50 },
            'bottom-left':   { x: 18, y: 84 },
            'bottom-center': { x: 50, y: 84 },
            'bottom-right':  { x: 82, y: 84 }
        };
        var pos = POS[s.position] || POS.center;
        var isTile = s.mode === 'tile';
        var size = isTile ? s.tile_scale : (scope === 'details' ? s.size_details : s.size);
        var x = Math.max(6, Math.min(94, pos.x + Number(s.offset_x || 0)));
        var y = Math.max(6, Math.min(94, pos.y + Number(s.offset_y || 0)));
        return { tile: isTile, size: size, gap: Number(s.tile_gap || 0), opacity: s.opacity, x: x, y: y, rot: s.rotation };
    }

    function paintFrame(id, scope, s, show) {
        var frame = $(id);
        if (!frame) return;
        var old = frame.querySelector('.mk-photo-wm');
        if (old) old.remove();
        var hint = frame.querySelector('.mk-wm-empty');
        if (hint) hint.remove();
        if (!show || !s.enabled) return;
        if (!logoUrl) {
            var miss = document.createElement('div');
            miss.className = 'mk-wm-empty';
            miss.style.cssText = 'position:relative;z-index:2;color:#fff;font-size:12px;text-align:center;padding:20px 10px;line-height:1.8';
            miss.textContent = 'لوگوی سایت برای پیش‌نمایش لود نشد. اگر لوگو آپلود شده، صفحه را یک‌بار تازه کنید.';
            frame.appendChild(miss);
            return;
        }
        var L = styleFor(scope, s);
        var el = document.createElement('div');
        el.className = 'mk-photo-wm';
        el.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:5;overflow:hidden';
        if (L.tile) {
            el.style.transform = 'rotate(' + L.rot + 'deg) scale(1.35)';
            el.style.transformOrigin = 'center center';
            var step = L.size + L.gap;
            if (step < 12) step = 12;
            var start = -step;
            var end = 100 + step;
            for (var y = start; y <= end; y += step) {
                for (var x = start; x <= end; x += step) {
                    var t = document.createElement('img');
                    t.src = logoUrl;
                    t.alt = '';
                    t.draggable = false;
                    t.style.cssText = 'position:absolute;left:' + x + '%;top:' + y + '%;width:' + L.size +
                        '%;height:auto;object-fit:contain;opacity:' + L.opacity + ';pointer-events:none';
                    el.appendChild(t);
                }
            }
        } else {
            var img = document.createElement('img');
            img.src = logoUrl;
            img.alt = '';
            img.draggable = false;
            img.style.cssText = 'position:absolute;left:' + L.x + '%;top:' + L.y + '%;width:' + L.size +
                '%;height:auto;max-height:80%;object-fit:contain;opacity:' + L.opacity +
                ';transform:translate(-50%,-50%) rotate(' + L.rot + 'deg);pointer-events:none';
            el.appendChild(img);
        }
        frame.appendChild(el);
    }

    function syncPreview() {
        var s = readState();
        if ($('mkWmOpacityLabel')) $('mkWmOpacityLabel').textContent = faNum(Math.round(s.opacity * 100)) + '٪';
        if ($('mkWmSizeLabel')) $('mkWmSizeLabel').textContent = faNum(Math.round(s.size)) + '٪';
        if ($('mkWmSizeDetailsLabel')) $('mkWmSizeDetailsLabel').textContent = faNum(Math.round(s.size_details)) + '٪';
        if ($('mkWmTileLabel')) $('mkWmTileLabel').textContent = faNum(Math.round(s.tile_scale)) + '٪';
        if ($('mkWmGapLabel')) $('mkWmGapLabel').textContent = faNum(Math.round(s.tile_gap)) + '٪';
        if ($('mkWmOxLabel')) $('mkWmOxLabel').textContent = faNum(s.offset_x) + '٪';
        if ($('mkWmOyLabel')) $('mkWmOyLabel').textContent = faNum(s.offset_y) + '٪';
        if ($('mkWmRotLabel')) $('mkWmRotLabel').textContent = faNum(s.rotation) + '°';
        var tileFields = $('mkWmTileFields');
        if (tileFields) tileFields.style.display = s.mode === 'tile' ? '' : 'none';
        paintFrame('mkWmPrevCard', 'card', s, s.on_cards);
        paintFrame('mkWmPrevDetails', 'details', s, s.on_details);
    }

    function setPos(id) {
        currentPos = id || 'center';
        var grid = $('mkWmPosGrid');
        if (grid) {
            [].forEach.call(grid.querySelectorAll('.mk-wm-pos'), function (b) {
                b.classList.toggle('is-on', b.getAttribute('data-pos') === currentPos);
            });
        }
        syncPreview();
    }

    function fill(data) {
        data = data || {};
        var s = data.settings || data;
        if (data.logo) {
            logoUrl = data.logo;
            var prev = $('mkWmLogoPrev');
            if (prev) prev.src = data.logo;
        }
        if ($('mkWmEnabled')) $('mkWmEnabled').checked = s.enabled !== false;
        if ($('mkWmOnCards')) $('mkWmOnCards').checked = s.on_cards !== false;
        if ($('mkWmOnDetails')) $('mkWmOnDetails').checked = s.on_details !== false;
        if ($('mkWmMode')) $('mkWmMode').value = s.mode === 'tile' ? 'tile' : 'single';
        var op = Number(s.opacity);
        if (op <= 1) op = op * 100;
        if (!isFinite(op)) op = 32;
        if ($('mkWmOpacity')) $('mkWmOpacity').value = String(Math.round(op));
        if ($('mkWmSize')) $('mkWmSize').value = String(Math.round(Number(s.size) || 38));
        if ($('mkWmSizeDetails')) $('mkWmSizeDetails').value = String(Math.round(Number(s.size_details != null ? s.size_details : s.size) || 30));
        if ($('mkWmTile')) $('mkWmTile').value = String(Math.round(Number(s.tile_scale) || 22));
        if ($('mkWmGap')) $('mkWmGap').value = String(Math.round(Number(s.tile_gap != null ? s.tile_gap : 16)));
        if ($('mkWmOx')) $('mkWmOx').value = String(Math.round(Number(s.offset_x) || 0));
        if ($('mkWmOy')) $('mkWmOy').value = String(Math.round(Number(s.offset_y) || 0));
        if ($('mkWmRot')) $('mkWmRot').value = String(Math.round(Number(s.rotation) || 0));
        setPos(s.position || 'center');
    }

    function bind() {
        ['mkWmOpacity', 'mkWmSize', 'mkWmSizeDetails', 'mkWmTile', 'mkWmGap', 'mkWmOx', 'mkWmOy', 'mkWmRot', 'mkWmEnabled', 'mkWmOnCards', 'mkWmOnDetails', 'mkWmMode'].forEach(function (id) {
            var el = $(id);
            if (el && !el.__mkWm) {
                el.__mkWm = true;
                el.addEventListener('input', syncPreview);
                el.addEventListener('change', syncPreview);
            }
        });
        var grid = $('mkWmPosGrid');
        if (grid && !grid.__mkWm) {
            grid.__mkWm = true;
            grid.addEventListener('click', function (e) {
                var b = e.target && e.target.closest ? e.target.closest('[data-pos]') : null;
                if (b) setPos(b.getAttribute('data-pos'));
            });
        }
        var btn = $('mkWmSave');
        if (btn && !btn.__mkWm) {
            btn.__mkWm = true;
            btn.addEventListener('click', save);
        }
        var bgt = $('mkWmBgToggle');
        if (bgt && !bgt.__mkWm) {
            bgt.__mkWm = true;
            bgt.addEventListener('click', function () {
                var dark = bgt.getAttribute('aria-pressed') !== 'true';
                bgt.setAttribute('aria-pressed', String(dark));
                bgt.textContent = dark ? 'پس‌زمینهٔ روشن' : 'پس‌زمینهٔ تیره';
                ['mkWmPrevCard', 'mkWmPrevDetails'].forEach(function (id) {
                    var f = $(id);
                    if (f) f.classList.toggle('is-dark', dark);
                });
            });
        }
        var up = $('mkWmLogoUpload');
        if (up && !up.__mkWm) {
            up.__mkWm = true;
            up.addEventListener('click', uploadLogo);
        }
        var clr = $('mkWmLogoClear');
        if (clr && !clr.__mkWm) {
            clr.__mkWm = true;
            clr.addEventListener('click', clearLogo);
        }
    }

    function uploadLogo() {
        var inp = $('mkWmLogoFile');
        var st = $('mkWmState');
        if (!inp || !inp.files || !inp.files[0]) {
            if (st) st.textContent = 'اول فایل لوگو را انتخاب کنید.';
            return;
        }
        if (st) st.textContent = 'در حال آپلود لوگو…';
        var fd = new FormData();
        fd.append('logo', inp.files[0]);
        fetch('admin-photo-watermark.php?action=upload_logo', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (st) st.textContent = (j && j.message) || (j && j.success ? 'آپلود شد' : 'آپلود نشد');
            if (j && j.success && j.logo) {
                logoUrl = j.logo;
                if (window.MELKINO_PHOTO_WM) window.MELKINO_PHOTO_WM.logo_url = j.logo;
                var prev = $('mkWmLogoPrev');
                if (prev) prev.src = j.logo;
                syncPreview();
            }
        }).catch(function () {
            if (st) st.textContent = 'خطا در آپلود لوگو';
        });
    }

    function clearLogo() {
        var st = $('mkWmState');
        if (st) st.textContent = 'در حال حذف…';
        fetch('admin-photo-watermark.php?action=clear_logo', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: '{}'
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (st) st.textContent = (j && j.message) || 'حذف شد';
            if (j && j.success) {
                logoUrl = j.logo || '';
                if (window.MELKINO_PHOTO_WM) window.MELKINO_PHOTO_WM.logo_url = '';
                var prev = $('mkWmLogoPrev');
                if (prev) prev.src = logoUrl;
                var inp = $('mkWmLogoFile');
                if (inp) inp.value = '';
                syncPreview();
            }
        }).catch(function () {
            if (st) st.textContent = 'خطا در حذف لوگو';
        });
    }

    function save() {
        var st = $('mkWmState');
        if (st) st.textContent = 'در حال ذخیره…';
        fetch('admin-photo-watermark.php?action=save', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(readState())
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (st) st.textContent = (j && j.message) || (j && j.success ? 'ذخیره شد' : 'ذخیره نشد');
            if (j && j.success && j.settings) fill({ settings: j.settings, logo: logoUrl });
        }).catch(function () {
            if (st) st.textContent = 'خطا در ذخیره';
        });
    }

    function load() {
        fetch('admin-photo-watermark.php?action=get', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.success) fill(j); })
            .catch(function () {});
    }

    function boot() {
        if (!ensureCard()) return;
        bind();
        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
    setTimeout(boot, 500);
    setTimeout(boot, 1500);
})();
