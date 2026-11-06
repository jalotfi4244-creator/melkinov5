<?php
/**
 * Melkino — admin stats library (ADDITIVE, read-only rankings + tiny recorders).
 *
 * Tab «آمار»: همهٔ رنکینگ‌ها تا ۵ نفر با نام، شناسه‌های تلگرام/بله/ایتا و شماره تماس.
 * جدول‌های موجود (بدون هیچ ALTER): users / ads / ad_views / login_events /
 * saved_searches / property_requests / request_matches / favorites.
 * جدول تازه (خودکار، CREATE IF NOT EXISTS): ad_shares.
 *
 * همهٔ توابع در برابر خطا fail-soft هستند: تب آمار هرگز ۵۰۰ نمی‌دهد.
 */
declare(strict_types=1);

if (!function_exists('melkinoStatsEnsureTables')) {
    function melkinoStatsEnsureTables(?PDO $pdo = null): void
    {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        if (!($pdo instanceof PDO)) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS ad_shares ('
                . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'user_id INT NULL,'
                . 'ad_id VARCHAR(64) NOT NULL,'
                . 'ad_title VARCHAR(255) NULL,'
                . 'shared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (id),'
                . 'KEY idx_user (user_id),'
                . 'KEY idx_ad (ad_id),'
                . 'KEY idx_shared (shared_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $done = true;
        } catch (Throwable $e) {
            // آمار هرگز صفحه را نمی‌شکند.
        }
    }
}

if (!function_exists('melkinoRecordAdShare')) {
    /**
     * ثبت اشتراک‌گذاری آگهی (از share-log.php). فقط کاربر لاگین‌کرده.
     */
    function melkinoRecordAdShare($userId, string $adId, string $title = '', ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        $adId = trim($adId);
        $userId = (int)$userId;
        if ($adId === '' || strlen($adId) > 64 || $userId <= 0 || !($pdo instanceof PDO)) {
            return false;
        }
        melkinoStatsEnsureTables($pdo);
        try {
            $pdo->prepare(
                'INSERT INTO ad_shares (user_id, ad_id, ad_title, shared_at) VALUES (?,?,?,NOW())'
            )->execute([$userId, $adId, $title !== '' ? mb_substr($title, 0, 255) : null]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('melkinoStatsColumns')) {
    /** Read-only schema discovery: old event tables do not all have eitaa_id. */
    function melkinoStatsColumns(PDO $pdo, string $table): array
    {
        if (!preg_match('/\A[a-z_]+\z/D', $table)) return [];
        try {
            $st = $pdo->query("SELECT * FROM `{$table}` WHERE 1 = 0");
            if (!$st) return [];
            $columns = [];
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $meta = $st->getColumnMeta($i);
                if (is_array($meta) && isset($meta['name'])) $columns[(string)$meta['name']] = true;
            }
            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('melkinoStatsPlatformCounts')) {
    /**
     * Counts ACCOUNTS, not logins or last_platform. Messenger groups can overlap.
     * Web = no linked messenger ID. All accounts (active/inactive) are included.
     * Aggregate once so all four cards use the same database snapshot.
     */
    function melkinoStatsPlatformCounts(?PDO $pdo, ?array $columns = null): array
    {
        $result = ['counts' => ['users_eitaa' => 0, 'users_telegram' => 0, 'users_bale' => 0, 'users_web' => 0], 'error' => true];
        if (!$pdo) return $result;
        $columns = $columns ?? melkinoStatsColumns($pdo, 'users');
        if (!isset($columns['id'])) return $result;
        $linked = [];
        foreach (['eitaa', 'telegram', 'bale'] as $provider) {
            $column = $provider . '_id';
            $linked[$provider] = isset($columns[$column]) ? "NULLIF(TRIM(`{$column}`), '') IS NOT NULL" : '1 = 0';
        }
        $select = [];
        foreach ($linked as $provider => $condition) {
            $select[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS users_{$provider}";
        }
        $web = 'NOT (' . implode(' OR ', $linked) . ')';
        $select[] = "COALESCE(SUM(CASE WHEN {$web} THEN 1 ELSE 0 END), 0) AS users_web";
        try {
            $st = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM users');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$row) return $result;
            foreach ($result['counts'] as $key => $unused) $result['counts'][$key] = (int)$row[$key];
            $result['error'] = false;
        } catch (Throwable $e) {
            error_log('[melkino][stats] Unable to count linked user platforms; SQL state: ' . $e->getCode());
        }
        return $result;
    }
}

if (!function_exists('melkinoStatsResolveSql')) {
    /**
     * Event identity priority: user_id, Telegram, Bale, Eitaa.
     * Unique-ID subqueries prevent a duplicate legacy messenger ID from multiplying events.
     * Ambiguous IDs without a valid user_id are not attributed to an arbitrary account.
     * @return array{0:string,1:string} [joinSql, uidExpr]
     */
    function melkinoStatsResolveSql(string $alias, bool $hasTg = true, bool $hasBale = true, bool $hasEitaa = false, bool $hasUserId = true): array
    {
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/D', $alias)) throw new InvalidArgumentException('Invalid stats alias');
        $joins = $hasUserId ? "LEFT JOIN users u1 ON u1.id = {$alias}.user_id AND {$alias}.user_id > 0" : '';
        $expr = $hasUserId ? ['u1.id'] : [];
        foreach ([['telegram_id', 'u2', $hasTg], ['bale_id', 'u3', $hasBale], ['eitaa_id', 'u4', $hasEitaa]] as [$column, $userAlias, $enabled]) {
            if (!$enabled) continue;
            $joins .= " LEFT JOIN (SELECT `{$column}` AS identity_key, MIN(id) AS id FROM users"
                . " WHERE NULLIF(TRIM(`{$column}`), '') IS NOT NULL GROUP BY `{$column}` HAVING COUNT(*) = 1) {$userAlias}"
                . " ON {$userAlias}.identity_key = {$alias}.`{$column}`";
            $expr[] = $userAlias . '.id';
        }
        return [$joins, count($expr) > 1 ? 'COALESCE(' . implode(',', $expr) . ')' : ($expr[0] ?? 'NULL')];
    }
}

if (!function_exists('melkinoStatsDisplay')) {
    /**
     * نمایش هویت کاربر برای جدول رنکینگ: نام، شناسه‌های تلگرام/بله/ایتا، شماره تماس.
     * @return array<string,string> name, handle, phone, and each provider ID/username
     */
    function melkinoStatsDisplay(array $u): array
    {
        $first = trim((string)($u['first_name'] ?? ''));
        $last = trim((string)($u['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = trim((string)($u['name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string)($u['username'] ?? ''));
        }
        if ($name === '') {
            $name = '—';
        }

        $identities = [];
        $handles = [];
        foreach (['telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا'] as $provider => $label) {
            $id = trim((string)($u[$provider . '_id'] ?? ''));
            $username = ltrim(trim((string)($u[$provider . '_username'] ?? '')), '@');
            $identities[$provider . '_id'] = $id;
            $identities[$provider . '_username'] = $username;
            if ($id !== '' || $username !== '') {
                $handles[] = $label . ': ' . ($id !== '' ? $id : '—') . ($username !== '' ? ' (@' . $username . ')' : '');
            }
        }
        $handle = $handles ? implode(' | ', $handles) : '—';

        $phone = trim((string)($u['phone'] ?? ''));
        if ($phone === '') {
            $phone = '—';
        }
        return array_merge(['name' => $name, 'handle' => $handle, 'phone' => $phone], $identities);
    }
}

if (!function_exists('melkinoStatsTopUsers')) {
    /**
     * اجرای یک کوئری رنکینگ و برگرداندن ردیف‌های نمایشی (حداکثر ۵).
     * @return array<int, array<string,int|string>>
     */
    function melkinoStatsTopUsers(PDO $pdo, string $sql, int $limit = 5): array
    {
        $out = [];
        try {
            $st = $pdo->query($sql);
            if (!$st) {
                return [];
            }
            $rank = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($rank >= $limit) {
                    break;
                }
                $rank++;
                $d = melkinoStatsDisplay($row);
                $out[] = array_merge($d, [
                    'uid' => (int)($row['uid'] ?? $row['id'] ?? 0),
                    'name' => $d['name'],
                    'handle' => $d['handle'],
                    'phone' => $d['phone'],
                    'c' => (int)($row['c'] ?? 0),
                    'extra' => (string)($row['extra'] ?? ''),
                ]);
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

if (!function_exists('melkinoStatsCount')) {
    function melkinoStatsCount(?PDO $pdo, string $sql): int
    {
        if (!($pdo instanceof PDO)) {
            return 0;
        }
        try {
            $v = $pdo->query($sql);
            return $v ? (int)$v->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('melkinoStatsAdBreakdown')) {
    /**
     * تمام ردیف‌های ads، مستقل از وضعیت؛ داده‌های خالی در گروه «نامشخص».
     * یک GROUP BY مشترک، تا جمع سطری/ستونی و کل از یک snapshot باشند.
     * هیچ محدودیت ۵تایی برای دسته‌ها و هیچ تغییر روی دادهٔ آگهی اعمال نمی‌شود.
     */
    function melkinoStatsAdBreakdown(PDO $pdo): array
    {
        $result = [
            'ads_by_tx' => [], 'ads_by_ptype' => [], 'ads_by_type' => [],
            'ads_total' => 0, 'ads_breakdown_error' => false,
        ];
        try {
            $st = $pdo->query(
                "SELECT COALESCE(NULLIF(TRIM(transaction_type), ''), 'نامشخص') AS tx, "
                . "COALESCE(NULLIF(TRIM(property_type), ''), 'نامشخص') AS pt, COUNT(*) AS c "
                . 'FROM ads GROUP BY tx, pt ORDER BY tx ASC, pt ASC'
            );
            if (!$st) {
                throw new RuntimeException('Ads breakdown query failed');
            }
            $byTx = [];
            $byProperty = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tx = (string)$row['tx'];
                $pt = (string)$row['pt'];
                $count = (int)$row['c'];
                $byTx[$tx] = ($byTx[$tx] ?? 0) + $count;
                $byProperty[$pt] = ($byProperty[$pt] ?? 0) + $count;
                $result['ads_by_type'][] = ['tx' => $tx, 'pt' => $pt, 'c' => $count];
                $result['ads_total'] += $count;
            }
            foreach ($byTx as $label => $count) {
                $result['ads_by_tx'][] = ['t' => (string)$label, 'c' => $count];
            }
            foreach ($byProperty as $label => $count) {
                $result['ads_by_ptype'][] = ['t' => (string)$label, 'c' => $count];
            }
            $sort = static function (array $a, array $b): int {
                return ($b['c'] <=> $a['c']) ?: strcmp($a['t'], $b['t']);
            };
            usort($result['ads_by_tx'], $sort);
            usort($result['ads_by_ptype'], $sort);
        } catch (Throwable $e) {
            $result['ads_breakdown_error'] = true;
            error_log('[melkino][stats] Unable to read ads breakdown; SQL state: ' . $e->getCode());
        }
        return $result;
    }
}

if (!function_exists('melkinoStatsDashboard')) {
    /**
     * همهٔ دادهٔ تب «آمار». ورودی null/خطا = داشبورد خالی (نه خطا).
     * @return array{counts:array,rankings:array,top_ads:array,ads_by_tx:array,ads_by_ptype:array,ads_by_type:array,ads_breakdown_error:bool,users_platform_error:bool}
     */
    function melkinoStatsDashboard(?PDO $pdo = null): array
    {
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        $empty = [
            'counts' => [
                'users_total' => 0, 'users_active' => 0, 'users_inactive' => 0,
                'users_eitaa' => 0, 'users_telegram' => 0, 'users_bale' => 0, 'users_web' => 0,
                'active_24h' => 0, 'active_30d' => 0, 'new_7d' => 0,
                'ads_total' => 0, 'requests_total' => 0, 'views_total' => 0,
                'shares_total' => 0, 'matches_total' => 0, 'logins_total' => 0,
                'searches_total' => 0, 'favs_total' => 0,
            ],
            'users_platform_error' => true,
            'rankings' => [],
            'top_ads' => [],
            'ads_by_tx' => [],
            'ads_by_ptype' => [],
            'ads_by_type' => [],
            'ads_breakdown_error' => true,
        ];
        if (!($pdo instanceof PDO)) {
            return $empty;
        }
        melkinoStatsEnsureTables($pdo);

        $userColumns = melkinoStatsColumns($pdo, 'users');
        $identityColumns = ['first_name', 'last_name', 'name', 'username', 'telegram_username', 'telegram_id',
            'bale_username', 'bale_id', 'eitaa_username', 'eitaa_id', 'phone'];
        $idCols = implode(',', array_map(static function (string $column) use ($userColumns): string {
            return isset($userColumns[$column]) ? 'u.' . $column : "NULL AS {$column}";
        }, $identityColumns));
        $platformCounts = melkinoStatsPlatformCounts($pdo, $userColumns);

        // ---- شمارش‌ها ----
        $counts = [
            'users_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users'),
            'users_active' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users WHERE is_active = 1'),
            'users_inactive' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users WHERE is_active <> 1'),
            'active_24h' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 1 DAY)'),
            'active_30d' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)'),
            'new_7d' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM users WHERE COALESCE(created_at, first_login) > DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'ads_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM ads'),
            'requests_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM property_requests'),
            'views_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM ad_views'),
            'shares_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM ad_shares'),
            'matches_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM request_matches'),
            'logins_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM login_events'),
            'searches_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM saved_searches'),
            'favs_total' => melkinoStatsCount($pdo, 'SELECT COUNT(*) FROM favorites'),
        ];

        $counts = array_merge($counts, $platformCounts['counts']);

        // Read only columns that actually exist on each event table; no schema migration required.
        $resolve = static function (string $table, string $alias) use ($pdo, $userColumns): array {
            $columns = melkinoStatsColumns($pdo, $table);
            return melkinoStatsResolveSql($alias,
                isset($columns['telegram_id'], $userColumns['telegram_id']),
                isset($columns['bale_id'], $userColumns['bale_id']),
                isset($columns['eitaa_id'], $userColumns['eitaa_id']),
                isset($columns['user_id'])
            );
        };
        [$vj, $vu] = $resolve('ad_views', 'v');
        [$lj, $lu] = $resolve('login_events', 'l');
        [$fj, $fu] = $resolve('favorites', 'f');

        $rankings = [];

        // ۱) بیشترین بازدید آگهی
        $rankings['views'] = [
            'title' => 'پربازدیدترین کاربران (بازدید آگهی)',
            'unit' => 'بازدید',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM ad_views v {$vj} "
                . "JOIN users u ON u.id = {$vu} GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5"),
        ];

        // ۲) بیشترین آگهی ثبت‌شده
        $rankings['ads'] = [
            'title' => 'بیشترین آگهی ثبت‌شده',
            'unit' => 'آگهی',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM ads a "
                . 'JOIN users u ON u.id = a.owner_user_id '
                . 'WHERE a.owner_user_id IS NOT NULL GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۳) بیشترین مرور تکراری (بازدید دوبارهٔ همان آگهی)
        $rankings['repeats'] = [
            'title' => 'بیشترین مرور تکراری آگهی',
            'unit' => 'بازدید تکراری',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},SUM(agg.rep) AS c FROM ("
                . "SELECT r.uid AS uid, COUNT(*) - 1 AS rep FROM ("
                . "SELECT {$vu} AS uid, v.ad_id FROM ad_views v {$vj}"
                . ') r WHERE r.uid IS NOT NULL GROUP BY r.uid, r.ad_id HAVING COUNT(*) > 1'
                . ') agg JOIN users u ON u.id = agg.uid GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۴) بیشترین جستجوی ذخیره‌شده
        $rankings['searches'] = [
            'title' => 'بیشترین جستجو (ذخیره‌شده)',
            'unit' => 'جستجو',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM saved_searches s "
                . 'JOIN users u ON u.id = s.user_id '
                . 'WHERE s.user_id IS NOT NULL GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۵) بیشترین درخواست ملک
        $rankings['requests'] = [
            'title' => 'بیشترین درخواست',
            'unit' => 'درخواست',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM property_requests r "
                . 'JOIN users u ON u.id = r.user_id '
                . 'WHERE r.user_id IS NOT NULL GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۶) بیشترین تطبیق (درخواست↔آگهی)
        $rankings['matches'] = [
            'title' => 'بیشترین تطبیق',
            'unit' => 'تطبیق',
            'rows' => melkinoStatsTopUsers($pdo,
                'SELECT u.id AS uid,' . $idCols . ',COUNT(*) AS c FROM request_matches m '
                . 'JOIN property_requests r ON r.id = m.request_id '
                . 'JOIN users u ON u.id = r.user_id '
                . 'WHERE r.user_id IS NOT NULL GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۷) کمترین تطبیق (کاربران دارای درخواست، به ترتیب صعودی)
        $rankings['least_matches'] = [
            'title' => 'کمترین تطبیق (دارای درخواست)',
            'unit' => 'تطبیق',
            'rows' => melkinoStatsTopUsers($pdo,
                'SELECT u.id AS uid,' . $idCols . ',COUNT(m.id) AS c FROM users u '
                . 'JOIN property_requests r ON r.user_id = u.id '
                . 'LEFT JOIN request_matches m ON m.request_id = r.id '
                . 'GROUP BY u.id ORDER BY c ASC, u.id ASC LIMIT 5'),
        ];

        // ۸) بیشترین اشتراک‌گذاری آگهی
        $rankings['shares'] = [
            'title' => 'بیشترین اشتراک‌گذاری آگهی',
            'unit' => 'اشتراک‌گذاری',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM ad_shares s "
                . 'JOIN users u ON u.id = s.user_id '
                . 'WHERE s.user_id IS NOT NULL GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5'),
        ];

        // ۹) بیشترین ورود در یک روز (همراه تاریخ همان روز)
        $dayBase = "SELECT {$lu} AS uid, DATE(l.created_at) AS d, COUNT(*) AS c "
            . "FROM login_events l {$lj} GROUP BY {$lu}, DATE(l.created_at)";
        $rankings['loginday'] = [
            'title' => 'بیشترین ورود در یک روز',
            'unit' => 'ورود در یک روز',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},mx.mc AS c, mind.d AS extra FROM ("
                . "SELECT b.uid AS uid, MAX(b.c) AS mc FROM ({$dayBase}) b GROUP BY b.uid"
                . ') mx JOIN ('
                . "SELECT b.uid AS uid, MIN(b.d) AS d FROM ({$dayBase}) b JOIN ("
                . "SELECT b2.uid AS uid, MAX(b2.c) AS mc FROM ({$dayBase}) b2 GROUP BY b2.uid"
                . ') m ON m.uid = b.uid AND b.c = m.mc GROUP BY b.uid'
                . ') mind ON mind.uid = mx.uid '
                . 'JOIN users u ON u.id = mx.uid ORDER BY mx.mc DESC, mx.uid ASC LIMIT 5'),
        ];

        // ۱۰) بیشترین علاقه‌مندی (پیشنهاد)
        $rankings['favs'] = [
            'title' => 'بیشترین علاقه‌مندی',
            'unit' => 'علاقه‌مندی',
            'rows' => melkinoStatsTopUsers($pdo,
                "SELECT u.id AS uid,{$idCols},COUNT(*) AS c FROM favorites f {$fj} "
                . "JOIN users u ON u.id = {$fu} GROUP BY u.id ORDER BY c DESC, u.id ASC LIMIT 5"),
        ];

        // ---- پربازدیدترین آگهی‌ها (سطح آگهی، پیشنهاد) ----
        $topAds = [];
        try {
            $st = $pdo->query(
                'SELECT ad_id, MAX(ad_title) AS ad_title, COUNT(*) AS c FROM ad_views '
                . 'GROUP BY ad_id ORDER BY c DESC LIMIT 5'
            );
            if ($st) {
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $topAds[] = [
                        'ad_id' => (string)($r['ad_id'] ?? ''),
                        'ad_title' => trim((string)($r['ad_title'] ?? '')) !== ''
                            ? trim((string)$r['ad_title']) : '—',
                        'c' => (int)($r['c'] ?? 0),
                    ];
                }
            }
        } catch (Throwable $e) {
            $topAds = [];
        }

        // ---- آگهی‌ها به تفکیک نوع معامله و نوع ملک ----
        $breakdown = melkinoStatsAdBreakdown($pdo);
        if (!$breakdown['ads_breakdown_error']) {
            // کارت کل و جمع جدول‌ها دقیقاً از همان مجموعهٔ ردیف‌ها هستند.
            $counts['ads_total'] = $breakdown['ads_total'];
        }
        unset($breakdown['ads_total']);

        return array_merge([
            'counts' => $counts,
            'users_platform_error' => $platformCounts['error'],
            'rankings' => $rankings,
            'top_ads' => $topAds,
        ], $breakdown);
    }
}
