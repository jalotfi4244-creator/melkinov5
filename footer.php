<?php
/* footer.php */
require_once __DIR__ . '/shared-property.php';
$mkSharedGuest = melkinoIsSharedPropertyGuest();
if (!function_exists('melkinoPromotionStyles')) {
    require_once __DIR__ . '/promotions.php';
}
if (is_file(__DIR__ . '/ad-cards-bootstrap.php')) {
    require_once __DIR__ . '/ad-cards-bootstrap.php';
    if (function_exists('melkinoAdCardsHead')) {
        $mkCardsHead = melkinoAdCardsHead('list');
        // Its shared CSS also styles the original detail page. Keep that exact cascade,
        // including admin studio styles, but no card runtime or serialized config for guests.
        if ($mkSharedGuest) $mkCardsHead = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $mkCardsHead);
        echo $mkCardsHead;
    }
}
?>

<?php if (!$mkSharedGuest): ?>
        <nav class="bottom-nav">
            <a href="home.php" class="nav-item" data-tour="nav-home">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <span>خانه</span>
            </a>

            <a href="map.php" class="nav-item" data-tour="nav-map">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"></path>
                    <circle cx="12" cy="10" r="2.5"></circle>
                </svg>
                <span>نقشه</span>
            </a>

            <a href="register-choose.php" class="nav-item" data-tour="nav-register">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
                <span>ثبت</span>
            </a>

            <a href="search.php" class="nav-item" data-tour="nav-search">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <span>جستجو</span>
            </a>

            <a href="profile.php" class="nav-item" data-tour="nav-profile">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-secondary)" stroke-width="2">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                </svg>
                <span>پروفایل</span>
            </a>
        </nav>
<?php endif; ?>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof updateBadge === 'function') {
            updateBadge();
        }
        if (typeof updateThemeButton === 'function') {
            updateThemeButton();
        }
    });
    </script>

    <?php if (function_exists('melkinoPromotionStyles')) { echo melkinoPromotionStyles(); } ?>
    <!-- =========================================================
         راند ۷۵: تزریق تبلیغ بین کارت‌ها — خانه + همه آگهی‌ها + VIP
         بدون چشمک: فقط وقتی خودِ کارت‌ها عوض شوند دوباره تزریق می‌شود
         ========================================================= -->
    <script>
    (function () {
        var page = (location.pathname.split('/').pop() || '').toLowerCase();
        var placementMap = {
            'properties.php': 'properties',
            'search-results.php': 'search',
            'vip.php': 'vip',
            'home.php': 'home',
            'index.php': 'home'
        };
        var placement = placementMap[page];
        if (!placement) return;

        var selectors = [
            '#mkHomeAdsList',
            '#propertiesList',
            '#mkVipAdsList',
            '#adsListContainer',
            '#vipList',
            '#adsList',
            '[data-ads-container]'
        ];

        function findContainer() {
            for (var i = 0; i < selectors.length; i++) {
                var el = document.querySelector(selectors[i]);
                if (el) return el;
            }
            return null;
        }

        function cardSignature(container) {
            var parts = [];
            var kids = container.children;
            for (var i = 0; i < kids.length; i++) {
                var el = kids[i];
                if (el.getAttribute && el.getAttribute('data-promo-slot') !== null) continue;
                if (el.classList && el.classList.contains('promo-card')) continue;
                parts.push(el.getAttribute('data-id') || el.id || el.className || String(i));
            }
            return parts.join('|') + '#' + parts.length;
        }

        function buildSlot(promo) {
            var wrap = document.createElement('div');
            wrap.innerHTML = String(promo.html || '').trim();
            var el = wrap.firstElementChild || wrap;
            el.setAttribute('data-promo-slot', '');
            el.setAttribute('data-promo-id', String(promo.id));
            var link = el.querySelector('a.promo-hit');
            if (link) {
                var destination = link.getAttribute('href') || '';
                if (destination && destination.indexOf('promotion-click.php') !== 0) {
                    link.setAttribute('href', 'promotion-click.php?id=' + encodeURIComponent(promo.id));
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener nofollow');
                }
            }
            return el;
        }

        var lastSig = '';
        var injecting = false;

        function inject(container, promos) {
            if (!container || !promos || !promos.length || injecting) return;
            var sig = cardSignature(container);
            if (sig === lastSig && container.querySelector('[data-promo-slot], .promo-card')) return;

            injecting = true;
            lastSig = sig;

            var old = container.querySelectorAll('[data-promo-slot], article.promo-card');
            for (var i = 0; i < old.length; i++) old[i].remove();

            var children = Array.prototype.slice.call(container.children);
            var cardIndex = 0;
            for (var c = 0; c < children.length; c++) {
                var card = children[c];
                if (card.getAttribute && card.getAttribute('data-promo-slot') !== null) continue;
                if (card.classList && card.classList.contains('promo-card')) continue;
                cardIndex++;
                for (var p = 0; p < promos.length; p++) {
                    var promo = promos[p];
                    var first = Math.max(1, promo.position_after || 3);
                    var repeat = promo.repeat_every || 0;
                    var show = (cardIndex === first);
                    if (!show && repeat > 0 && cardIndex > first) {
                        show = ((cardIndex - first) % repeat) === 0;
                    }
                    if (show && card.parentNode) {
                        card.parentNode.insertBefore(buildSlot(promo), card.nextSibling);
                    }
                }
            }
            injecting = false;
        }

        // پلتفرم: از صفحه اگر مشخص باشد؛ وگرنه سرور خودش تشخیص می‌دهد (نشست/UA/ارجاع)
        var mkPromoPlat = document.documentElement.getAttribute('data-melkino-messenger') || '';
        var mkPromoQs = 'promotions-feed.php?placement=' + encodeURIComponent(placement) + (mkPromoPlat ? '&platform=' + encodeURIComponent(mkPromoPlat) : '');
        fetch(mkPromoQs, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.success || !data.promotions || !data.promotions.length) return;
                var promos = data.promotions;
                var container = findContainer();
                if (container) inject(container, promos);

                var timer = null;
                var observer = new MutationObserver(function () {
                    if (injecting) return;
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        var current = findContainer();
                        if (current) inject(current, promos);
                    }, 250);
                });
                if (container) {
                    observer.observe(container, { childList: true, subtree: false });
                } else {
                    var wait = 0;
                    var ready = setInterval(function () {
                        wait++;
                        var el = findContainer();
                        if (el) {
                            clearInterval(ready);
                            inject(el, promos);
                            observer.observe(el, { childList: true, subtree: false });
                        } else if (wait > 40) {
                            clearInterval(ready);
                        }
                    }, 200);
                }
            })
            .catch(function () {});
    })();
    </script>

    <!-- نمایش مبلغ به حروف زیر فیلدهای قیمت -->
    <script src="price-words.js"></script>

    <!-- ویجت مقایسه ملک‌ها -->
    <?php if (!$mkSharedGuest): ?>
    <script src="compare-widget.js?v=<?php echo (int)@filemtime(__DIR__ . '/compare-widget.js'); ?>"></script>
    <script src="favorite-widget.js?v=<?php echo (int)@filemtime(__DIR__ . '/favorite-widget.js'); ?>"></script>
    <?php endif; ?>
    <script src="assets/js/melkino-share.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/melkino-share.js'); ?>"></script>
    <?php
    if (!$mkSharedGuest && !(($_GET['fd_preview'] ?? '') === '1' && !empty($_SESSION['is_admin'])) && is_file(__DIR__ . '/onboarding-tour.php')) {
        require_once __DIR__ . '/onboarding-tour.php';
        $__tourPage = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $__tourSkip = ['onboarding.php', 'login.php', 'admin-login.php', 'logout.php', 'index.php'];
        if (!in_array($__tourPage, $__tourSkip, true)) {
            melkinoProductTourBoot('user');
        }
    }
    ?>

    <?php
    // قلاب ترافیکی «برنامهٔ پیامک»: حداکثر هر ۵ دقیقه، در پایان پاسخ اجرا
    // می‌شود (register_shutdown_function) و هرگز رندر صفحه را به تأخیر
    // نمی‌اندازد. داخل تابع، گیت mtime هم هست؛ اینجا فقط فراخوانی سبک است.
    if (!$mkSharedGuest && is_file(__DIR__ . '/sms-program.php')) {
        require_once __DIR__ . '/sms-program.php';
        try {
            smsProgramTrafficTick();
        } catch (Throwable $e) {
            // هرگز صفحه را نمی‌شکند
        }
    }
    ?>

</body>
</html>