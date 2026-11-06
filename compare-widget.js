/* =========================================================
   ویجت مقایسهٔ ملک‌ها (مشترک همه صفحات) — راند ۲۴
   - هر دکمه‌ای با [data-compare-add="AD-ID"] وضعیت مقایسه را TOGGLE می‌کند:
     کلیک اول = افزودن، کلیک دوباره = حذف از مقایسه
   - فقط کاربر وارد‌شده مجاز است؛ مهمان با پیام راهنما مواجه می‌شود
   - حباب شناور «⚖️ مقایسه (N)» فقط برای کاربر وارد‌شده نمایش داده می‌شود
   ========================================================= */
(function () {
    'use strict';

    if (window.__melkinoCompareWidgetLoaded) {
        return;
    }
    window.__melkinoCompareWidgetLoaded = true;

    /* ---------- Toast ---------- */

    let toastEl = null;
    let toastTimer = null;

    function ensureToast() {
        if (toastEl) {
            return toastEl;
        }
        toastEl = document.createElement('div');
        toastEl.id = 'melkinoCompareToast';
        toastEl.setAttribute('style', [
            'position:fixed',
            'bottom:26px',
            'right:50%',
            'transform:translateX(50%) translateY(20px)',
            'background:var(--surface,#fff)',
            'border:1px solid var(--border,#e5e7eb)',
            'border-radius:14px',
            'padding:12px 18px',
            'font-size:13px',
            'color:var(--text-primary,#111)',
            'box-shadow:0 12px 30px rgba(0,0,0,.18)',
            'opacity:0',
            'pointer-events:none',
            'transition:opacity .3s ease,transform .3s ease',
            'z-index:9999',
            'max-width:calc(100vw - 40px)',
            'text-align:center'
        ].join(';'));
        document.body.appendChild(toastEl);
        return toastEl;
    }

    function compareToast(message, ms) {
        const t = ensureToast();
        t.textContent = message;
        t.style.opacity = '1';
        t.style.transform = 'translateX(50%) translateY(0)';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            t.style.opacity = '0';
            t.style.transform = 'translateX(50%) translateY(20px)';
        }, ms || 3500);
    }

    /* ---------- Floating pill ---------- */

    let pillEl = null;

    function ensurePill() {
        if (pillEl) {
            return pillEl;
        }
        pillEl = document.createElement('a');
        pillEl.id = 'melkinoComparePill';
        pillEl.href = 'compare-page.php';
        pillEl.setAttribute('style', [
            'position:fixed',
            // بالای نوار پایینِ اپ (خانه/جستجو/ثبت/علاقه‌ها/پروفایل)؛
            // قبلاً bottom:22px بود و دقیقاً روی تب «پروفایل» می‌افتاد.
            'bottom:calc(var(--bottom-nav-height, 70px) + 14px + env(safe-area-inset-bottom, 0px))',
            'left:16px',
            'z-index:9000',
            'display:none',
            'align-items:center',
            'gap:8px',
            'background:linear-gradient(135deg,var(--primary,#0b5d5b),#0b5d5b)',
            'color:#fff',
            'border-radius:999px',
            'padding:11px 18px',
            'font-size:13px',
            'font-weight:800',
            'text-decoration:none',
            'box-shadow:0 10px 26px rgba(6,78,78,.35)',
            // راند ۴۳: حرکت ظریف حباب
            'transition:transform .15s ease, box-shadow .2s ease'
        ].join(';'));
        if (!document.getElementById('mkPillHoverCss')) {
            var st43 = document.createElement('style');
            st43.id = 'mkPillHoverCss';
            st43.textContent = '#melkinoComparePill:hover{transform:translateY(-2px)} @media (prefers-reduced-motion: reduce){#melkinoComparePill{transition:none!important}}';
            document.head.appendChild(st43);
        }
        document.body.appendChild(pillEl);
        return pillEl;
    }

    function updatePill(count) {
        // در خود صفحه پروفایل حباب لازم نیست
        if (window.location.pathname.indexOf('profile.php') !== -1) {
            return;
        }
        const pill = ensurePill();
        count = Number(count) || 0;
        if (count > 0) {
            pill.style.display = 'inline-flex';
            pill.innerHTML = '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-3px;"><path d="M12 3v18"/><path d="M6 7h12"/><path d="m6 7-3 6a3 3 0 0 0 6 0z"/><path d="m18 7-3 6a3 3 0 0 0 6 0z"/><path d="M8 21h8"/></svg> مقایسه (' + count + ')';
        } else {
            pill.style.display = 'none';
        }
    }

    let compareLoggedIn = null;

    async function refreshCount() {
        try {
            const res = await fetch('compare.php?action=count', { cache: 'no-store' });
            const data = await res.json();
            if (data && data.success) {
                compareLoggedIn = data.logged_in !== false;
                updatePill(data.count);
            }
        } catch (e) {
            /* ignore */
        }
    }

    /* ---------- حالت دکمه‌ها (در مقایسه هست یا نه) ---------- */

    function setBtnState(btn, inCompare) {
        if (!btn.dataset.compareOrig) {
            btn.dataset.compareOrig = btn.innerHTML;
        }
        if (inCompare) {
            /* راند ۷۱: دکمه فقط پر می‌شود (روشن) و متن نمی‌گیرد — آیکون همان آیکون است */
            btn.classList.add('in-compare');
            btn.setAttribute('aria-pressed', 'true');
            btn.title = 'حذف از مقایسه';
        } else {
            btn.classList.remove('in-compare');
            btn.innerHTML = btn.dataset.compareOrig;
            btn.setAttribute('aria-pressed', 'false');
            btn.title = 'افزودن به مقایسه';
        }
    }

    async function syncButtons() {
        const btns = document.querySelectorAll('[data-compare-add]');
        if (!btns.length) {
            return;
        }
        let ids = [];
        try {
            const res = await fetch('compare.php?action=ids', { cache: 'no-store' });
            if (res.status === 401) {
                ids = [];
            } else {
                const data = await res.json();
                ids = (data && data.success && Array.isArray(data.ids)) ? data.ids : [];
            }
        } catch (e) {
            ids = [];
        }
        const set = {};
        ids.forEach(function (id) { set[id] = true; });
        btns.forEach(function (btn) {
            setBtnState(btn, !!set[btn.getAttribute('data-compare-add')]);
        });
    }

    /* ---------- Toggle: افزودن / حذف با کلیک دوباره ---------- */

    async function toggleCompare(adId, btn) {
        if (!adId) {
            return;
        }
        if (btn) {
            btn.disabled = true;
        }
        try {
            const body = new URLSearchParams();
            body.append('ad_id', adId);
            const res = await fetch('compare.php?action=toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const data = await res.json().catch(function () { return null; });
            if (res.status === 401) {
                // راند ۲۴: مقایسه فقط برای کاربر وارد‌شده
                compareToast('🔑 برای مقایسهٔ ملک‌ها، اول وارد حساب کاربری شوید.');
                return;
            }
            if (data && data.success) {
                if (data.removed) {
                    compareToast('🗑️ از مقایسه حذف شد.');
                    if (btn) setBtnState(btn, false);
                } else {
                    compareToast('به «' + (data.group_name || 'مقایسه') + '» اضافه شد. (کلیک دوباره = حذف)');
                    if (btn) setBtnState(btn, true);
                }
                window.dispatchEvent(new CustomEvent('melkino:compare-changed'));
                refreshCount();
                syncButtons();
            } else {
                compareToast('❌ ' + ((data && data.message) || 'خطا در تغییر وضعیت مقایسه.'));
            }
        } catch (e) {
            compareToast('❌ خطا در ارتباط با سرور.');
        } finally {
            if (btn) {
                btn.disabled = false;
            }
        }
    }

    document.addEventListener('click', function (e) {
        const btn = e.target && e.target.closest
            ? e.target.closest('[data-compare-add]')
            : null;
        if (!btn) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        toggleCompare(btn.getAttribute('data-compare-add'), btn);
    });

    window.addEventListener('melkino:compare-changed', function () {
        refreshCount();
    });

    /* ---------- Public API ---------- */

    window.melkinoCompareAdd = toggleCompare;      // سازگاری نام قدیمی
    window.melkinoCompareToggle = toggleCompare;
    window.melkinoCompareRefreshCount = refreshCount;
    window.melkinoCompareSyncButtons = syncButtons;

    /* کارت‌ها در برخی صفحات (فهرست/جستجو/علاقه‌ها) با JS و بعد از boot
       رندر می‌شوند؛ با MutationObserver به‌محض افزودن دکمه‌ها، وضعیتشان
       از سرور همگام می‌شود. */
    let syncTimer = null;
    function queueSync() {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(syncButtons, 400);
    }
    if (typeof MutationObserver !== 'undefined') {
        const mo = new MutationObserver(function (muts) {
            for (let i = 0; i < muts.length; i++) {
                const added = muts[i].addedNodes || [];
                for (let j = 0; j < added.length; j++) {
                    const n = added[j];
                    if (n.nodeType === 1 && n.matches &&
                        (n.matches('[data-compare-add]') || n.querySelector('[data-compare-add]'))) {
                        queueSync();
                        return;
                    }
                }
            }
        });
        mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    function boot() {
        refreshCount();
        syncButtons();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
