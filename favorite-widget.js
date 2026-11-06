/* =========================================================
   favorite-widget.js — راند ۷۱: دکمهٔ علاقه‌مندی کارت‌ها
   دکمه‌های قلب [data-fav-toggle]:
   - یک کلیک  → روشن (قرمز) + ثبت در علاقه‌مندی‌ها
   - کلیک دوم → خاموش + حذف
   وضعیت سروری (favorites.php) همیشه مرجع نهایی است.
   ========================================================= */
(function () {
    'use strict';

    function setFavState(btn, on) {
        if (!btn) {
            return;
        }
        btn.classList.toggle('in-fav', !!on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.title = on ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها';
        btn.setAttribute('aria-label', btn.title);
    }

    async function syncFavButtons() {
        var btns = Array.prototype.slice.call(document.querySelectorAll('[data-fav-toggle]'));
        if (!btns.length) {
            return;
        }
        var favs = [];
        try {
            var res = await fetch('favorites.php?action=list', { cache: 'no-store' });
            if (res.status !== 401) {
                var data = await res.json();
                favs = (data && data.success && Array.isArray(data.favorites)) ? data.favorites : [];
            }
        } catch (e) {
            favs = [];
        }
        var set = {};
        favs.forEach(function (f) {
            if (f && f.id) {
                set[f.id] = true;
            }
        });
        btns.forEach(function (btn) {
            setFavState(btn, !!set[btn.getAttribute('data-fav-toggle')]);
        });
    }

    async function toggleFav(adId, btn) {
        if (!adId || !btn) {
            return;
        }
        if (btn.disabled) {
            return;
        }
        btn.disabled = true;
        try {
            var res = await fetch('favorites.php?action=toggle', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ad_id=' + encodeURIComponent(adId)
            });
            if (!res.ok) {
                var msg = 'خطایی رخ داد.';
                try {
                    var j = await res.json();
                    if (j && j.message) {
                        msg = j.message;
                    }
                } catch (e2) {
                    /* ignore */
                }
                window.alert(msg);
                return;
            }
            await syncFavButtons();
        } catch (e) {
            window.alert('خطا در ارتباط با سرور.');
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) {
            return;
        }
        var btn = e.target.closest('[data-fav-toggle]');
        if (!btn) {
            return;
        }
        e.preventDefault();
        toggleFav(btn.getAttribute('data-fav-toggle'), btn);
    });

    /* صفحهٔ جزئیات رویداد favorites-changed پخش می‌کند؛ کارت‌ها را تازه کنیم */
    document.addEventListener('melkino:favorites-changed', function () {
        syncFavButtons();
    });

    /* کارت‌ها ممکن است بعد از DOMContentLoaded با جاوااسکریپت رندر شوند؛
       هر وقت دکمهٔ جدید ظاهر شد، وضعیت را از سرور تازه می‌کنیم. */
    let syncTimer = null;
    function queueSync() {
        if (syncTimer) {
            return;
        }
        syncTimer = setTimeout(function () {
            syncTimer = null;
            syncFavButtons();
        }, 250);
    }

    if (typeof MutationObserver !== 'undefined') {
        const mo = new MutationObserver(function (muts) {
            for (let i = 0; i < muts.length; i++) {
                const added = muts[i].addedNodes;
                if (!added) {
                    continue;
                }
                for (let j = 0; j < added.length; j++) {
                    const n = added[j];
                    if (n.nodeType === 1 && (n.matches('[data-fav-toggle]') || n.querySelector('[data-fav-toggle]'))) {
                        queueSync();
                        return;
                    }
                }
            }
        });
        mo.observe(document.documentElement, { childList: true, subtree: true });
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncFavButtons();
    });
    if (document.readyState !== 'loading') {
        syncFavButtons();
    }
})();
