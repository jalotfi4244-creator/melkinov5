<?php
/**
|--------------------------------------------------------------------------
| تبلیغاتِ بین کارت‌ها
|--------------------------------------------------------------------------
| ادمین می‌تواند بنرهایی تعریف کند که در فهرست آگهی‌ها (خانه،
| جستجو، املاک، ویژه) بعد از یک شماره‌ی کارت مشخص نمایش داده شوند.
|--------------------------------------------------------------------------
*/

if (!function_exists('melkinoEnsurePromotionsTable')) {
    function melkinoEnsurePromotionsTable(): void
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return;
        }

        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS promotions (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    title VARCHAR(255) NOT NULL DEFAULT '',
                    image_url VARCHAR(500) NOT NULL DEFAULT '',
                    link_url VARCHAR(500) NOT NULL DEFAULT '',
                    button_text VARCHAR(100) NOT NULL DEFAULT 'مشاهده',
                    description VARCHAR(1000) NOT NULL DEFAULT '',
                    placement VARCHAR(50) NOT NULL DEFAULT 'all',
                    position_after INT UNSIGNED NOT NULL DEFAULT 3,
                    repeat_every INT UNSIGNED NOT NULL DEFAULT 0,
                    start_date DATETIME NULL,
                    end_date DATETIME NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    views INT UNSIGNED NOT NULL DEFAULT 0,
                    clicks INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_promotions_active (is_active),
                    KEY idx_promotions_placement (placement)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            // پیام‌رسان‌های مجاز نمایش هر بنر (ستون افزوده‌شده؛ بنرهای قدیمی همه‌جا دیده می‌شوند)
            if (!$pdo->query("SHOW COLUMNS FROM promotions LIKE 'platforms'")->fetch()) {
                $pdo->exec("ALTER TABLE promotions ADD COLUMN platforms VARCHAR(100) NOT NULL DEFAULT 'telegram,bale,eitaa,web'");
            }
        } catch (Throwable $e) {
            // نبود دسترسی ایجاد جدول نباید صفحه را خراب کند
        }
    }
}

if (!function_exists('melkinoPromoPlatform')) {
    /** پیام‌رسان بازدیدکننده: telegram | bale | eitaa | web (هر چیز دیگری = وب) */
    function melkinoPromoPlatform(): string
    {
        // فید تبلیغات روی صفحه‌های مجاز (بدون دروازهٔ ورود) اجرا می‌شود و کتابخانهٔ پیام‌رسان
        // ممکن است هنوز لود نشده باشد؛ بدون آن، تشخیص تلگرام/بله/ایتا همیشه «وب» می‌شد.
        if (!function_exists('melkinoMessengerContext') || !function_exists('melkinoMessengerHint')) {
            $__libPath = __DIR__ . '/messenger-login-lib.php';
            if (is_readable($__libPath)) { require_once $__libPath; }
        }
        $p = function_exists('melkinoMessengerContext') ? (string)melkinoMessengerContext() : '';
        if ($p === '' && function_exists('melkinoMessengerHint')) {
            $p = (string)melkinoMessengerHint();
        }
        return in_array($p, ['telegram', 'bale', 'eitaa'], true) ? $p : 'web';
    }
}

if (!function_exists('melkinoActivePromotions')) {
    function melkinoActivePromotions(string $placement, ?string $platform = null): array
    {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return [];
        }

        melkinoEnsurePromotionsTable();

        try {
            $st = $pdo->prepare(
                "SELECT * FROM promotions
                  WHERE is_active = 1
                    AND (placement = 'all' OR placement = ?)
                    AND FIND_IN_SET(?, platforms) > 0
                    AND (start_date IS NULL OR start_date <= NOW())
                    AND (end_date IS NULL OR end_date >= NOW())
                  ORDER BY position_after ASC, id ASC"
            );
            $platform = $platform ?? melkinoPromoPlatform();
            $st->execute([$placement, $platform]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        // ثبت بازدید (یک‌بار در هر بار لود صفحه)
        if ($rows) {
            try {
                $ids = implode(',', array_map('intval', array_column($rows, 'id')));
                $pdo->exec("UPDATE promotions SET views = views + 1 WHERE id IN ($ids)");
            } catch (Throwable $e) {
            }
        }

        return $rows;
    }
}

if (!function_exists('melkinoPromotionAfter')) {
    /**
     * اگر تبلیغی باید بعد از کارتِ شماره‌ی $cardIndex (یک-پایه) نمایش
     * داده شود، HTML آن را برمی‌گرداند؛ در غیر این صورت رشته‌ی خالی.
     */
    function melkinoPromotionAfter(string $placement, int $cardIndex, ?array &$cache = null): string
    {
        static $loaded = [];

        if (!isset($loaded[$placement])) {
            $loaded[$placement] = melkinoActivePromotions($placement);
        }
        $promotions = $loaded[$placement];

        if (!$promotions) {
            return '';
        }

        foreach ($promotions as $promo) {
            $first = max(1, (int)($promo['position_after'] ?: 3));
            $repeat = (int)($promo['repeat_every'] ?: 0);

            $shouldShow = ($cardIndex === $first);
            if (!$shouldShow && $repeat > 0 && $cardIndex > $first) {
                $shouldShow = (($cardIndex - $first) % $repeat) === 0;
            }

            if ($shouldShow) {
                return melkinoRenderPromotion($promo);
            }
        }

        return '';
    }
}

if (!function_exists('melkinoRenderPromotion')) {
    function melkinoRenderPromotion(array $promo): string
    {
        $image = trim((string)($promo['image_url'] ?? ''));
        if ($image === '') {
            return '';
        }
        $link = trim((string)($promo['link_url'] ?? ''));
        $id = (int)($promo['id'] ?? 0);
        $img = '<img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="" loading="lazy">';

        $html = '<article class="property-card promo-card" data-promo-id="' . $id . '">';
        if ($link !== '' && $id > 0) {
            $html .= '<a class="promo-hit" href="promotion-click.php?id=' . $id . '"'
                . ' target="_blank" rel="noopener nofollow">' . $img . '</a>';
        } else {
            $html .= '<div class="promo-hit promo-hit--static">' . $img . '</div>';
        }
        $html .= '</article>';

        return $html;
    }
}

if (!function_exists('melkinoPromotionStyles')) {
    function melkinoPromotionStyles(): string
    {
        return <<<'CSS'
<style>
/* راند ۷۶: تبلیغ = فقط عکس، هم‌اندازه کارت آگهی؛ کلیک = لینک اگر باشد */
.promo-card.property-card{
    height:100%;
    min-height:320px;
    padding:0;
    display:flex;
    border:1px solid var(--border);
    border-radius:var(--radius-md, 16px);
    background:var(--surface);
    box-shadow:var(--shadow-card);
    overflow:hidden;
}
.promo-card .promo-hit{
    display:block;
    width:100%;
    height:100%;
    min-height:320px;
    text-decoration:none;
    color:inherit;
}
.promo-card .promo-hit--static{cursor:default;}
.promo-card .promo-hit img{
    display:block;
    width:100%;
    height:100%;
    object-fit:cover;
}
</style>
CSS;
    }
}
