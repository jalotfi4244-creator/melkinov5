<?php
/*
|--------------------------------------------------------------------------
| کتابخانهٔ مشترک «مقایسهٔ ملک‌ها»
|--------------------------------------------------------------------------
| این فایل قلبِ قابلیت مقایسه است و هم توسط API (compare.php) و هم توسط
| صفحهٔ مستقل مقایسه (compare-page.php) استفاده می‌شود تا منطق در یک جا
| باشد و رفتار هر دو مسیر یکسان بماند.
|
| چرا مهمان (Guest) هم می‌تواند مقایسه کند؟
|   نسخهٔ قبلی فقط با «هویت سشن» کار می‌کرد؛ یعنی اگر کاربر وارد نشده بود،
|   همهٔ درخواست‌ها ۴۰۱ می‌شد و دکمهٔ «افزودن به مقایسه» هیچ کاری نمی‌کرد
|   (همان «مقایسه کار نمی‌کند»). حالا هر بازدیدکننده یک شناسهٔ تصادفیِ
|   امن در کوکی می‌گیرد و مقایسه‌اش همان‌جا ذخیره می‌شود؛ به‌محض ورود،
|   ردیف‌های مهمان به حساب کاربری‌اش منتقل می‌شود.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/compare-engine.php';


if (!function_exists('melkinoCompareGuestCookieName')) {
    function melkinoCompareGuestCookieName(): string
    {
        return 'melkino_cmp_guest';
    }
}

/**
 * شناسهٔ مهمان از کوکی خوانده می‌شود و اگر نبود، ساخته و ست می‌شود.
 * فقط مقدارهای هگزِ ۳۲ کاراکتری پذیرفته می‌شوند (جلوگیری از تزریق).
 */
if (!function_exists('melkinoCompareGuestToken')) {
    function melkinoCompareGuestToken(bool $create = true): string
    {
        $name = melkinoCompareGuestCookieName();
        $value = trim((string)($_COOKIE[$name] ?? ''));

        if ($value !== '' && preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            $value = '';
        }

        if ($value !== '') {
            return $value;
        }

        if (!$create) {
            return '';
        }

        try {
            $value = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            $value = md5(uniqid('cmp', true));
        }

        $_COOKIE[$name] = $value;

        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

            setcookie($name, $value, [
                'expires'  => time() + 60 * 60 * 24 * 365,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $value;
    }
}

/** در صورت نیاز، ستون guest_token را به جدول‌های مقایسه اضافه می‌کند. */
if (!function_exists('melkinoCompareEnsureColumn')) {
    function melkinoCompareEnsureColumn(PDO $pdo, string $table): void
    {
        try {
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[strtolower((string)($col['Field'] ?? ''))] = true;
            }
            if (!isset($columns['guest_token'])) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN guest_token VARCHAR(64) NULL");
            }
            if (!isset($columns['updated_at'])) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN updated_at DATETIME NULL");
            }
        } catch (Throwable $e) {
            // اگر کاربرِ دیتابیس اجازهٔ ALTER نداشت، مقایسه برای کاربرانِ
            // واردشده همچنان کار می‌کند و خطای مرگبار نمی‌دهیم.
        }
    }
}

/** ساخت جدول‌های مقایسه در اولین استفاده. */
if (!function_exists('melkinoEnsureCompareTables')) {
    function melkinoEnsureCompareTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                guest_token VARCHAR(64) NULL,
                ad_id VARCHAR(64) NOT NULL,
                group_no TINYINT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_owner (user_id, telegram_id),
                KEY idx_guest (guest_token),
                KEY idx_ad (ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compare_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                telegram_id VARCHAR(64) NULL,
                guest_token VARCHAR(64) NULL,
                group_no TINYINT NOT NULL,
                name VARCHAR(60) NOT NULL DEFAULT '',
                KEY idx_owner (user_id, telegram_id),
                KEY idx_guest (guest_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        melkinoCompareEnsureColumn($pdo, 'compare_items');
        melkinoCompareEnsureColumn($pdo, 'compare_groups');
    }
}

if (!function_exists('melkinoCompareMaxPerGroup')) {
    function melkinoCompareMaxPerGroup(): int
    {
        return 5;
    }
}

if (!function_exists('melkinoCompareGroupDefaults')) {
    function melkinoCompareGroupDefaults(): array
    {
        return [1 => 'گروه ۱', 2 => 'گروه ۲', 3 => 'گروه ۳'];
    }
}

/**
 * شرط مالکیت ردیف‌های مقایسه بر اساس هویتِ سشن + شناسهٔ مهمان.
 *
 * @return array{0:string,1:array,2:?int,3:string,4:string} [where, params, userId, telegramId, guestToken]
 */
if (!function_exists('melkinoCompareOwner')) {
    function melkinoCompareOwner(array $identity, string $alias = '', bool $createGuest = true): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $conds = [];
        $params = [];

        $userId = !empty($identity['user_id']) ? (int)$identity['user_id'] : null;
        $telegramId = trim((string)($identity['telegram_id'] ?? ''));
        $guestToken = melkinoCompareGuestToken($createGuest);

        if ($userId) {
            $conds[] = $prefix . 'user_id = ?';
            $params[] = $userId;
        }
        if ($telegramId !== '') {
            $conds[] = $prefix . 'telegram_id = ?';
            $params[] = $telegramId;
        }
        if ($guestToken !== '') {
            $conds[] = $prefix . 'guest_token = ?';
            $params[] = $guestToken;
        }

        if (!$conds) {
            return ['', [], null, '', ''];
        }

        return ['(' . implode(' OR ', $conds) . ')', $params, $userId, $telegramId, $guestToken];
    }
}

/**
 * انتقالِ مقایسهٔ مهمان به حساب کاربر (اولین باری که کاربر وارد شده دیده شود).
 * این کار یک‌بار انجام می‌شود؛ بعد از آن کوکیِ مهمان پاک می‌شود.
 */
if (!function_exists('melkinoCompareMergeGuest')) {
    function melkinoCompareMergeGuest(PDO $pdo, array $identity): void
    {
        $userId = !empty($identity['user_id']) ? (int)$identity['user_id'] : null;
        $telegramId = trim((string)($identity['telegram_id'] ?? ''));

        if (!$userId && $telegramId === '') {
            return;
        }

        $guestToken = trim((string)($_COOKIE[melkinoCompareGuestCookieName()] ?? ''));
        if ($guestToken === '' || preg_match('/^[a-f0-9]{32}$/', $guestToken) !== 1) {
            return;
        }

        try {
            // فقط ردیف‌هایی منتقل می‌شوند که کاربر همان ملک را از قبل در
            // مقایسه نداشته باشد؛ وگرنه یک ملک دو بار در لیست دیده می‌شد.
            $pdo->prepare(
                'UPDATE compare_items g
                 LEFT JOIN compare_items u
                        ON u.ad_id = g.ad_id
                       AND u.id <> g.id
                       AND (u.user_id = ? OR (? <> \'\' AND u.telegram_id = ?))
                 SET g.user_id = ?, g.telegram_id = ?
                 WHERE g.guest_token = ? AND g.user_id IS NULL AND u.id IS NULL'
            )->execute([
                $userId,
                $telegramId,
                $telegramId,
                $userId,
                $telegramId !== '' ? $telegramId : null,
                $guestToken,
            ]);

            // ردیف‌های تکراریِ باقی‌مانده (ملکی که کاربر از قبل داشت) حذف می‌شوند
            $pdo->prepare(
                'DELETE FROM compare_items WHERE guest_token = ? AND user_id IS NULL'
            )->execute([$guestToken]);

            // دسته‌ها: اول تکراری‌ها حذف، بعد بقیه منتقل
            $pdo->prepare(
                'DELETE g FROM compare_groups g
                 INNER JOIN compare_groups u
                         ON u.group_no = g.group_no
                        AND (u.user_id = ? OR (? <> \'\' AND u.telegram_id = ?))
                 WHERE g.guest_token = ? AND g.user_id IS NULL'
            )->execute([$userId, $telegramId, $telegramId, $guestToken]);

            $pdo->prepare(
                'UPDATE compare_groups SET user_id = ?, telegram_id = ? WHERE guest_token = ? AND user_id IS NULL'
            )->execute([$userId, $telegramId !== '' ? $telegramId : null, $guestToken]);
        } catch (Throwable $e) {
            return;
        }

        unset($_COOKIE[melkinoCompareGuestCookieName()]);
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            setcookie(melkinoCompareGuestCookieName(), '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

if (!function_exists('melkinoCompareGroupName')) {
    function melkinoCompareGroupName(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): string
    {
        $defaults = melkinoCompareGroupDefaults();
        $stmt = $pdo->prepare("SELECT name FROM compare_groups WHERE $ownerWhere AND group_no = ? ORDER BY id DESC LIMIT 1");
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        $name = trim((string)$stmt->fetchColumn());
        return $name !== '' ? $name : ($defaults[$groupNo] ?? ('گروه ' . $groupNo));
    }
}

/** ملک‌های منتشرشدهٔ یک دسته (به‌همراه عکس اصلی). */
if (!function_exists('melkinoCompareItems')) {
    function melkinoCompareItems(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.transaction_type, a.property_type, a.location,
                    a.area, a.rooms, a.year, a.price_sell, a.total_price,
                    a.deposit, a.rent_monthly, a.price_hidden, ci.group_no, ci.created_at,
                    i.filename AS image
             FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             LEFT JOIN images i ON i.id = (
                 SELECT i2.id FROM images i2
                 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1
                 ORDER BY i2.is_primary DESC, i2.sort_order ASC, i2.id ASC LIMIT 1
             )
             WHERE $ownerWhere AND ci.group_no = ?
             ORDER BY ci.created_at ASC, ci.id ASC"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** تعداد کل ملک‌های مقایسه. */
if (!function_exists('melkinoCompareCount')) {
    function melkinoCompareCount(PDO $pdo, string $ownerWhere, array $ownerParams): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             WHERE $ownerWhere"
        );
        $stmt->execute($ownerParams);
        return (int)$stmt->fetchColumn();
    }
}

/** تعداد ملک‌های یک دسته. */
if (!function_exists('melkinoCompareCountInGroup')) {
    function melkinoCompareCountInGroup(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

/** فهرست کامل سه دسته با نام، تعداد و ملک‌ها. */
if (!function_exists('melkinoCompareGroups')) {
    function melkinoCompareGroups(PDO $pdo, string $ownerWhere, array $ownerParams, string $ownerWherePlain, array $ownerParamsPlain): array
    {
        $groups = [];
        for ($g = 1; $g <= 3; $g++) {
            $items = melkinoCompareItems($pdo, $ownerWhere, $ownerParams, $g);
            $groups[] = [
                'no'    => $g,
                'name'  => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $g),
                'count' => count($items),
                'items' => $items,
            ];
        }
        return $groups;
    }
}

/** مبلغ مرجع قابل‌مقایسه: فروش ← قیمت فروش؛ اجاره ← ودیعه + ۱۲ ماه اجاره. */
if (!function_exists('melkinoCompareRefAmount')) {
    function melkinoCompareRefAmount(array $ad): ?float
    {
        $num = function ($v): ?float {
            if ($v === null || $v === '') {
                return null;
            }
            $n = (float)str_replace(',', '', (string)$v);
            return $n > 0 ? $n : null;
        };
        $tx = trim((string)($ad['transaction_type'] ?? ''));
        $isRent = mb_strpos($tx, 'اجاره') !== false || mb_strpos($tx, 'رهن') !== false;
        if ($isRent) {
            $dep = $num($ad['deposit'] ?? null);
            $rent = $num($ad['rent_monthly'] ?? null);
            if ($dep === null && $rent === null) {
                return null;
            }
            return ($dep ?? 0) + ($rent ?? 0) * 12;
        }
        return $num($ad['price_sell'] ?? null)
            ?? $num($ad['total_price'] ?? null)
            ?? $num($ad['deposit'] ?? null);
    }
}

/**
 * موتور امتیازدهی مقایسه — شفاف و نسبی درون گروه، از ۱۰۰ نمره:
 *   قیمت (هر متر ارزان‌تر بهتر) ۳۵ + متراژ ۱۵ + خواب ۱۰ +
 *   سال ساخت (نوساز بهتر) ۱۰ + امکانات ۱۵ + کیفیت آگهی ۱۵
 */
if (!function_exists('melkinoScoreCompareGroup')) {
    /**
     * راند ۲۴: امتیازدهی کاملاً به موتور جدید مقایسه (compare-engine.php)
     * واگذار شده است. این تابع فقط برای سازگاری مسیرهای قدیمی نگه داشته شده.
     */
    function melkinoScoreCompareGroup(array $ads): array
    {
        $engine = melkinoCompareEngine($ads);
        $scores = [];
        foreach ($engine['ads'] as $a) {
            $scores[$a['id']] = $a['scores'];
        }
        $winner = '';
        $best = -1.0;
        foreach ($engine['ads'] as $a) {
            $o = $a['scores']['overall'];
            if ($o !== null && $o > $best) {
                $best = $o;
                $winner = $a['id'];
            }
        }
        $highlights = [];
        foreach ($engine['differences'] as $d) {
            $parts = [];
            foreach ($d['ads'] as $id => $info) {
                $parts[] = $info['value'];
            }
            $highlights[] = $d['label'] . ': ' . implode(' در برابر ', $parts);
        }
        return [
            'scores' => $scores,
            'winner' => $winner,
            'highlights' => $highlights,
            'mixed_types' => $engine['mixed_types'],
            'engine' => $engine,
        ];
    }
}

if (!function_exists('melkinoCompareScoreData')) {
    function melkinoCompareScoreData(PDO $pdo, string $ownerWhere, array $ownerParams, int $groupNo): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.*,
                    i.filename AS image,
                    (SELECT COUNT(*) FROM images i2 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1) AS img_count,
                    (SELECT COUNT(*) FROM ad_amenities aa WHERE aa.ad_id = a.id) AS amenity_count,
                    (SELECT GROUP_CONCAT(am.name SEPARATOR '، ') FROM ad_amenities aa JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = a.id) AS amenity_names
             FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             LEFT JOIN images i ON i.id = (
                 SELECT i3.id FROM images i3
                 WHERE i3.ad_id = a.id AND i3.is_selected = 1 AND i3.publish_publicly = 1
                 ORDER BY i3.is_primary DESC, i3.sort_order ASC, i3.id ASC LIMIT 1
             )
             WHERE $ownerWhere AND ci.group_no = ?
             ORDER BY ci.created_at ASC, ci.id ASC"
        );
        $params = $ownerParams;
        $params[] = $groupNo;
        $stmt->execute($params);
        $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = melkinoScoreCompareGroup($ads);

        return [
            'ads'         => $ads,
            'engine'      => $result['engine'] ?? melkinoCompareEngine($ads),
            'scores'      => $result['scores'],
            'winner'      => $result['winner'],
            'highlights'  => $result['highlights'],
            'mixed_types' => $result['mixed_types'],
        ];
    }
}

/** بهترین‌های یک گروه برای نمایش در کارتِ پروفایل. */
if (!function_exists('melkinoCompareBestGroup')) {
    function melkinoCompareBestGroup(array $groups): ?array
    {
        $best = null;
        foreach ($groups as $g) {
            if (!is_array($g) || ($g['count'] ?? 0) < 2) {
                continue;
            }
            if ($best === null || $g['count'] > $best['count']) {
                $best = $g;
            }
        }
        if ($best === null) {
            foreach ($groups as $g) {
                if (is_array($g) && ($g['count'] ?? 0) > 0) {
                    return $g;
                }
            }
        }
        return $best;
    }
}
