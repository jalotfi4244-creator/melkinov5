<?php
/**
 * دستیار عملیاتی ملکینو — فقط روی جدول‌های واقعی سایت.
 * جداول خود دستیار: assistant_runs / assistant_insights / assistant_evidence / assistant_chat
 */

function astThresholds(): array
{
    return [
        'repeated_view' => 5,
        'high_view' => 10,
        'old_request_days' => 14,
        'hot_lead_score' => 70,
        'high_match_score' => 85,
        'ok_match_score' => 70,
        'multi_day' => 3,
        'multi_user' => 3,
    ];
}

function astTry(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function astCols(PDO $pdo, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

function astSelect(PDO $pdo, string $table, array $want, string $orderSql, int $limit): array
{
    $have = astCols($pdo, $table);
    if (!$have) {
        return [];
    }
    $use = [];
    foreach ($want as $col) {
        if (in_array($col, $have, true)) {
            $use[] = $col;
        }
    }
    if (!$use) {
        return [];
    }
    $sql = 'SELECT `' . implode('`,`', $use) . '` FROM `' . str_replace('`', '', $table) . '` ' . $orderSql . ' LIMIT ' . (int)$limit;
    return astTry($pdo, $sql);
}

function astEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assistant_runs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NOT NULL,
            views_n INT NOT NULL DEFAULT 0,
            requests_n INT NOT NULL DEFAULT 0,
            matches_n INT NOT NULL DEFAULT 0,
            ads_n INT NOT NULL DEFAULT 0,
            favorites_n INT NOT NULL DEFAULT 0,
            visits_n INT NOT NULL DEFAULT 0,
            users_n INT NOT NULL DEFAULT 0,
            insights_n INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            KEY idx_finished (finished_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assistant_insights (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            run_id INT UNSIGNED NOT NULL,
            fingerprint VARCHAR(80) NOT NULL,
            type VARCHAR(40) NOT NULL,
            type_label VARCHAR(80) NOT NULL,
            priority VARCHAR(20) NOT NULL,
            confidence DECIMAL(4,2) NOT NULL DEFAULT 0,
            user_id INT NULL,
            ad_id VARCHAR(64) NULL,
            request_id INT NULL,
            visit_id INT NULL,
            phone VARCHAR(30) NULL,
            person_name VARCHAR(160) NULL,
            tracking_code VARCHAR(40) NULL,
            ad_title VARCHAR(255) NULL,
            title VARCHAR(190) NOT NULL,
            what_happened TEXT NULL,
            why_it_matters TEXT NULL,
            interpretation TEXT NULL,
            action TEXT NULL,
            signals VARCHAR(500) NULL,
            score INT NULL,
            last_activity DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_run (run_id),
            KEY idx_fp (fingerprint),
            KEY idx_prio (priority),
            KEY idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assistant_evidence (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            insight_id INT UNSIGNED NOT NULL,
            fact_text VARCHAR(400) NOT NULL,
            source_table VARCHAR(64) NULL,
            source_id VARCHAR(64) NULL,
            KEY idx_insight (insight_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assistant_chat (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role VARCHAR(20) NOT NULL,
            message MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function astWho(array $row): string
{
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid > 0) {
        return 'u:' . $uid;
    }
    $tg = trim((string)($row['telegram_id'] ?? ''));
    if ($tg !== '') {
        return 't:' . $tg;
    }
    $bale = trim((string)($row['bale_id'] ?? ''));
    if ($bale !== '') {
        return 'b:' . $bale;
    }
    $phone = trim((string)($row['phone'] ?? ''));
    if ($phone !== '') {
        return 'p:' . $phone;
    }
    return '';
}

function astPerson(array $users, string $who, array $fallback = []): array
{
    $u = $users[$who] ?? null;
    $name = '';
    $phone = '';
    $uid = null;
    if (is_array($u)) {
        $name = trim((string)($u['name'] ?? ''));
        if ($name === '') {
            $name = trim(trim((string)($u['first_name'] ?? '')) . ' ' . trim((string)($u['last_name'] ?? '')));
        }
        $phone = trim((string)($u['phone'] ?? ''));
        $uid = isset($u['id']) ? (int)$u['id'] : null;
    }
    if ($name === '') {
        $name = trim((string)($fallback['last_name'] ?? $fallback['name'] ?? ''));
    }
    if ($phone === '') {
        $phone = trim((string)($fallback['phone'] ?? ''));
    }
    if ($uid === null && strpos($who, 'u:') === 0) {
        $uid = (int)substr($who, 2);
    }
    $label = $name !== '' ? $name : ($phone !== '' ? $phone : 'کاربر بدون نام');
    return ['user_id' => $uid, 'name' => $name, 'phone' => $phone, 'label' => $label];
}

function astReqStatus(string $st): string
{
    $map = ['new' => 'جدید', 'tracking' => 'در حال پیگیری', 'archived' => 'بایگانی', 'closed' => 'بسته شده'];
    return $map[$st] ?? ($st !== '' ? $st : 'جدید');
}

function astVisitStatus(string $st): string
{
    $map = [
        'new' => 'جدید',
        'scheduled_owner' => 'هماهنگ با مالک',
        'owner_rejected_date' => 'رد تاریخ توسط مالک',
        'user_notified' => 'اطلاع به کاربر',
        'done' => 'انجام شده',
        'archived' => 'بایگانی',
    ];
    return $map[$st] ?? ($st !== '' ? $st : 'جدید');
}

function astAdStatus(string $st): string
{
    $map = ['published' => 'منتشر شده', 'draft' => 'پیش‌نویس', 'sold' => 'فروخته/اجاره رفته', 'archived' => 'بایگانی', 'rejected' => 'رد شده'];
    return $map[$st] ?? ($st !== '' ? $st : 'نامشخص');
}

function astLoadSnapshot(PDO $pdo): array
{
    $notes = [];
    if (function_exists('melkinoEnsureAdViewsSchema')) {
        melkinoEnsureAdViewsSchema($pdo);
    }
    if (function_exists('melkinoEnsureRequestSchema')) {
        melkinoEnsureRequestSchema();
    }
    if (function_exists('melkinoEnsureVisitRequestSchema')) {
        melkinoEnsureVisitRequestSchema();
    }

    $views = astSelect($pdo, 'ad_views', ['id', 'user_id', 'telegram_id', 'bale_id', 'ad_id', 'ad_title', 'viewed_at'], 'ORDER BY id DESC', 8000);
    $requests = astSelect($pdo, 'property_requests', [
        'id', 'tracking_code', 'user_id', 'telegram_id', 'phone', 'last_name', 'status',
        'location', 'property_type', 'transaction_type', 'min_area', 'max_area',
        'min_price', 'max_price', 'min_deposit', 'max_deposit', 'min_rent', 'max_rent', 'created_at',
    ], 'ORDER BY id DESC', 600);
    $matches = astSelect($pdo, 'request_matches', [
        'id', 'request_id', 'ad_id', 'match_percent', 'location_score', 'area_score', 'budget_score',
        'amenities_score', 'is_notified', 'created_at',
    ], 'ORDER BY id DESC', 2500);
    $ads = astSelect($pdo, 'ads', [
        'id', 'title', 'status', 'location', 'property_type', 'transaction_type',
        'price_sell', 'deposit', 'rent_monthly', 'last_name', 'phone', 'created_at', 'published_at', 'views',
    ], 'ORDER BY id DESC', 900);
    $favs = astSelect($pdo, 'favorites', ['id', 'user_id', 'telegram_id', 'ad_id'], '', 4000);
    $visits = astSelect($pdo, 'visit_requests', [
        'id', 'ad_id', 'ad_title', 'user_id', 'telegram_id', 'phone', 'name', 'status',
        'preferred_date', 'preferred_date_fa', 'tracking_code', 'created_at', 'archived',
    ], 'ORDER BY id DESC', 800);
    $sms = astSelect($pdo, 'lead_sms_log', ['id', 'kind', 'request_id', 'ad_id', 'user_id', 'phone', 'success', 'created_at'], 'ORDER BY id DESC', 400);
    $userRows = astSelect($pdo, 'users', [
        'id', 'name', 'first_name', 'last_name', 'phone', 'telegram_id', 'bale_id',
    ], 'ORDER BY id DESC', 2500);

    $users = [];
    foreach ($userRows as $u) {
        $users['u:' . (int)$u['id']] = $u;
        if (trim((string)($u['telegram_id'] ?? '')) !== '') {
            $users['t:' . trim((string)$u['telegram_id'])] = $u;
        }
        if (trim((string)($u['bale_id'] ?? '')) !== '') {
            $users['b:' . trim((string)$u['bale_id'])] = $u;
        }
        if (trim((string)($u['phone'] ?? '')) !== '') {
            $users['p:' . trim((string)$u['phone'])] = $u;
        }
    }

    if (!$views) {
        $notes[] = 'در ad_views رکوردی نیست؛ تحلیل علاقه فقط از درخواست، تطبیق و بازدید حضوری است.';
    }
    if (!$favs) {
        $notes[] = 'جدول favorites خالی یا در دسترس نبود.';
    }

    return compact('views', 'requests', 'matches', 'ads', 'favs', 'visits', 'sms', 'users', 'notes') + ['now' => time()];
}

function astInsightRow(array $row): array
{
    $base = [
        'type' => '',
        'type_label' => '',
        'priority' => 'MEDIUM',
        'confidence' => 0.5,
        'user_id' => null,
        'ad_id' => null,
        'request_id' => null,
        'visit_id' => null,
        'phone' => '',
        'person_name' => '',
        'tracking_code' => '',
        'ad_title' => '',
        'title' => '',
        'what_happened' => '',
        'why_it_matters' => '',
        'interpretation' => '',
        'action' => null,
        'signals' => [],
        'facts' => [],
        'sources' => [],
        'score' => null,
        'last_activity' => null,
    ];
    return array_merge($base, $row);
}

function astBuildInsights(array $snap, array $th): array
{
    $now = (int)$snap['now'];
    $ads = [];
    foreach ($snap['ads'] as $a) {
        $ads[(string)$a['id']] = $a;
    }
    $users = $snap['users'];
    $insights = [];

    $favPair = [];
    foreach ($snap['favs'] as $f) {
        $who = astWho($f);
        $adId = trim((string)($f['ad_id'] ?? ''));
        if ($who !== '' && $adId !== '') {
            $favPair[$who . '|' . $adId] = true;
        }
    }

    $visitOpen = [];
    $visitPair = [];
    $visitByAd = [];
    foreach ($snap['visits'] as $v) {
        if (!empty($v['archived'])) {
            continue;
        }
        $adId = trim((string)($v['ad_id'] ?? ''));
        $who = astWho($v);
        $st = (string)($v['status'] ?? 'new');
        if ($adId !== '') {
            $visitByAd[$adId] = ($visitByAd[$adId] ?? 0) + 1;
        }
        if ($who !== '' && $adId !== '') {
            $visitPair[$who . '|' . $adId] = $v;
        }
        if (in_array($st, ['new', 'scheduled_owner', 'owner_rejected_date', 'user_notified', ''], true)) {
            $visitOpen[] = $v;
        }
    }

    $reqByWho = [];
    $reqById = [];
    foreach ($snap['requests'] as $r) {
        $reqById[(int)$r['id']] = $r;
        $who = astWho($r);
        if ($who !== '') {
            $reqByWho[$who][] = $r;
        }
        $ph = trim((string)($r['phone'] ?? ''));
        if ($ph !== '') {
            $reqByWho['p:' . $ph][] = $r;
        }
    }

    $matchByReq = [];
    $matchByAd = [];
    foreach ($snap['matches'] as $m) {
        $rid = (int)$m['request_id'];
        $adId = trim((string)$m['ad_id']);
        $matchByReq[$rid][] = $m;
        if ($adId !== '') {
            $matchByAd[$adId][] = $m;
        }
    }

    $smsByReq = [];
    $smsByAdPhone = [];
    foreach ($snap['sms'] as $s) {
        if (empty($s['success'])) {
            continue;
        }
        if (!empty($s['request_id'])) {
            $smsByReq[(int)$s['request_id']] = $s;
        }
        $k = trim((string)($s['ad_id'] ?? '')) . '|' . trim((string)($s['phone'] ?? ''));
        if ($k !== '|') {
            $smsByAdPhone[$k] = $s;
        }
    }

    $ua = [];
    $byAd = [];
    $byWho = [];
    foreach ($snap['views'] as $v) {
        $adId = trim((string)($v['ad_id'] ?? ''));
        if ($adId === '') {
            continue;
        }
        $who = astWho($v);
        $ts = strtotime((string)($v['viewed_at'] ?? '')) ?: 0;
        $day = $ts ? date('Y-m-d', $ts) : '';
        $byAd[$adId]['n'] = ($byAd[$adId]['n'] ?? 0) + 1;
        $byAd[$adId]['title'] = (string)($v['ad_title'] ?? ($byAd[$adId]['title'] ?? ''));
        $byAd[$adId]['last'] = max($byAd[$adId]['last'] ?? 0, $ts);
        if ($ts >= $now - 3 * 86400) {
            $byAd[$adId]['d3'] = ($byAd[$adId]['d3'] ?? 0) + 1;
        } elseif ($ts >= $now - 6 * 86400) {
            $byAd[$adId]['p3'] = ($byAd[$adId]['p3'] ?? 0) + 1;
        }
        if ($who === '') {
            continue;
        }
        $byAd[$adId]['who'][$who] = ($byAd[$adId]['who'][$who] ?? 0) + 1;
        $byWho[$who]['n'] = ($byWho[$who]['n'] ?? 0) + 1;
        if ($ts >= $now - 7 * 86400) {
            $byWho[$who]['d7'] = ($byWho[$who]['d7'] ?? 0) + 1;
        } elseif ($ts >= $now - 14 * 86400) {
            $byWho[$who]['p7'] = ($byWho[$who]['p7'] ?? 0) + 1;
        }
        $key = $who . '|' . $adId;
        if (!isset($ua[$key])) {
            $ua[$key] = ['who' => $who, 'ad_id' => $adId, 'title' => (string)($v['ad_title'] ?? ''), 'n' => 0, 'days' => [], 'times' => [], 'last' => 0];
        }
        $ua[$key]['n']++;
        if ($day) {
            $ua[$key]['days'][$day] = true;
        }
        if ($ts) {
            $ua[$key]['times'][] = $ts;
            $ua[$key]['last'] = max($ua[$key]['last'], $ts);
        }
        if ($ua[$key]['title'] === '' && (string)($v['ad_title'] ?? '') !== '') {
            $ua[$key]['title'] = (string)$v['ad_title'];
        }
    }

    $avgPair = 0.0;
    if ($ua) {
        $sum = 0;
        foreach ($ua as $row) {
            $sum += $row['n'];
        }
        $avgPair = $sum / count($ua);
    }

    foreach ($ua as $row) {
        $n = (int)$row['n'];
        $daysN = count($row['days']);
        if ($n < $th['repeated_view'] && $daysN < $th['multi_day']) {
            continue;
        }
        $who = $row['who'];
        $adId = $row['ad_id'];
        $ad = $ads[$adId] ?? [];
        $title = trim((string)($ad['title'] ?? $row['title']));
        if ($title === '') {
            $title = 'ملک کد ' . $adId;
        }
        $person = astPerson($users, $who);
        $fav = !empty($favPair[$who . '|' . $adId]);
        $vis = $visitPair[$who . '|' . $adId] ?? null;
        $reqs = $reqByWho[$who] ?? [];
        if (!$reqs && $person['phone'] !== '') {
            $reqs = $reqByWho['p:' . $person['phone']] ?? [];
        }
        $reqId = null;
        $track = '';
        $matchPct = null;
        foreach ($reqs as $rq) {
            $rid = (int)$rq['id'];
            $reqId = $reqId ?: $rid;
            $track = (string)($rq['tracking_code'] ?? $track);
            foreach ($matchByReq[$rid] ?? [] as $m) {
                if ((string)$m['ad_id'] === $adId) {
                    $reqId = $rid;
                    $track = (string)($rq['tracking_code'] ?? $track);
                    $matchPct = (float)$m['match_percent'];
                    break 2;
                }
            }
        }
        $signals = [];
        if ($n >= $th['repeated_view']) {
            $signals[] = 'بازدید تکراری';
        }
        if ($n >= $th['high_view']) {
            $signals[] = 'بازدید پرتکرار ۷روزه';
        }
        if ($daysN >= $th['multi_day']) {
            $signals[] = 'چند روز متوالی';
        }
        if ($fav) {
            $signals[] = 'علاقه‌مندی';
        }
        if ($vis) {
            $signals[] = 'درخواست بازدید';
        }
        if ($matchPct !== null) {
            $signals[] = 'در فایل‌های منطبق درخواست';
        }
        if ($n >= $th['repeated_view'] && !$fav && !$vis && !$reqs) {
            $signals[] = 'بدون اقدام ثبت‌شده';
        }
        $quick = 0;
        sort($row['times']);
        for ($i = 1; $i < count($row['times']); $i++) {
            $gap = $row['times'][$i] - $row['times'][$i - 1];
            if ($gap > 0 && $gap <= 7200) {
                $quick++;
            }
        }
        if ($quick) {
            $signals[] = 'بازگشت سریع';
        }

        $score = $n + max(0, $n - 1) * 2 + max(0, $daysN - 1) * 3;
        if ($fav) {
            $score += 5;
        }
        if ($matchPct !== null) {
            $score += 4;
        }
        if ($reqs) {
            $score += 8;
        }
        if ($vis) {
            $score += 10;
        }
        if ($quick) {
            $score += 3;
        }

        $facts = [
            $n . ' بار مشاهده در ad_views',
            $daysN . ' روز جداگانه',
        ];
        $sources = [['ad_views', $adId]];
        if ($row['last']) {
            $facts[] = 'آخرین مشاهده ' . date('Y-m-d H:i', $row['last']);
        }
        if ($avgPair > 0) {
            $facts[] = 'میانگین مشاهده هر جفت کاربر-آگهی ' . round($avgPair, 1) . ' بار';
        }
        if ($fav) {
            $facts[] = 'در جدول favorites ثبت شده';
            $sources[] = ['favorites', $adId];
        }
        if ($matchPct !== null) {
            $facts[] = 'امتیاز تطبیق request_matches: ' . rtrim(rtrim(number_format($matchPct, 1, '.', ''), '0'), '.') . '٪';
            $sources[] = ['request_matches', (string)$reqId];
        }
        if ($vis) {
            $facts[] = 'درخواست بازدید #' . $vis['id'] . ' وضعیت «' . astVisitStatus((string)($vis['status'] ?? '')) . '»';
            $sources[] = ['visit_requests', (string)$vis['id']];
        }
        if (!empty($ad['location'])) {
            $facts[] = 'محدوده آگهی: ' . $ad['location'];
        }
        $facts = array_slice($facts, 0, 6);

        $recent = $row['last'] >= $now - 7 * 86400;
        $conf = 0.48 + 0.07 * min(5, count($signals));
        if ($recent) {
            $conf += 0.08;
        }
        $conf = min(0.96, $conf);

        $isHot = $score >= $th['hot_lead_score'] || ($fav && $n >= $th['repeated_view'] && $matchPct !== null);
        $noAction = in_array('بدون اقدام ثبت‌شده', $signals, true);
        $type = $isHot ? 'hot_lead' : ($noAction ? 'interest_no_action' : 'repeat_view');
        $prio = $isHot ? ($recent ? 'CRITICAL' : 'HIGH') : ($n >= $th['high_view'] && $recent ? 'HIGH' : 'MEDIUM');
        $action = null;
        if ($isHot || $n >= $th['high_view']) {
            $action = 'پیگیری تلفنی/پیامک این کاربر درباره همین آگهی قابل بررسی است.';
        } elseif ($noAction) {
            $action = 'فرصت پیگیری؛ هنوز درخواست یا بازدید حضوری ثبت نشده.';
        }
        if ($reqId && isset($smsByReq[$reqId])) {
            $facts[] = 'قبلاً از پنل لید پیامک ارسال شده';
        }

        $insights[] = astInsightRow([
            'type' => $type,
            'type_label' => $isHot ? 'لید داغ' : ($noAction ? 'علاقه بدون اقدام' : 'بازدید تکراری'),
            'priority' => $prio,
            'confidence' => round($conf, 2),
            'user_id' => $person['user_id'],
            'ad_id' => $adId,
            'request_id' => $reqId,
            'visit_id' => $vis ? (int)$vis['id'] : null,
            'phone' => $person['phone'],
            'person_name' => $person['label'],
            'tracking_code' => $track,
            'ad_title' => $title,
            'title' => $isHot ? 'تعامل بالا با یک آگهی' : 'بازدید تکراری یک آگهی',
            'what_happened' => $person['label'] . ' آگهی «' . $title . '» (کد ' . $adId . ') را ' . $n . ' بار در ' . $daysN . ' روز دیده است.',
            'why_it_matters' => $avgPair > 0 && $n >= max($th['repeated_view'], $avgPair * 3)
                ? 'این تکرار بالاتر از الگوی معمول مشاهده در سامانه است.'
                : 'تکرار مشاهده روی یک فایل، نسبت به یک بازدید تکی، ارزش پیگیری بیشتری دارد.',
            'interpretation' => 'شواهد برای احتمال علاقه بالاتر هستند؛ نیت قطعی خرید/اجاره از این داده ثابت نمی‌شود.',
            'action' => $action,
            'signals' => $signals,
            'facts' => $facts,
            'sources' => $sources,
            'score' => $score,
            'last_activity' => $row['last'] ? date('Y-m-d H:i:s', $row['last']) : null,
        ]);
    }

    foreach ($byWho as $who => $u) {
        $d7 = (int)($u['d7'] ?? 0);
        $p7 = (int)($u['p7'] ?? 0);
        $person = astPerson($users, $who);
        if ($d7 >= 8 && $p7 <= 3) {
            $insights[] = astInsightRow([
                'type' => 'warming',
                'type_label' => 'افزایش فعالیت',
                'priority' => 'HIGH',
                'confidence' => 0.74,
                'user_id' => $person['user_id'],
                'phone' => $person['phone'],
                'person_name' => $person['label'],
                'title' => 'فعالیت ۷ روز اخیر بیشتر شده',
                'what_happened' => $person['label'] . ' در ۷ روز اخیر ' . $d7 . ' بازدید داشته؛ ۷ روز قبل از آن ' . $p7 . ' بازدید.',
                'why_it_matters' => 'افزایش حجم مشاهده نسبت به هفته قبل، برای صف پیگیری روزانه مهم است.',
                'interpretation' => 'علت افزایش از داده قابل اثبات نیست.',
                'action' => 'بررسی پیگیری کاربر پیشنهاد می‌شود.',
                'signals' => ['افزایش فعالیت'],
                'facts' => ['ad_views هفت روز اخیر: ' . $d7, 'ad_views هفت روز قبل: ' . $p7],
                'sources' => [['ad_views', $who]],
            ]);
        } elseif ($p7 >= 10 && $d7 <= 3) {
            $insights[] = astInsightRow([
                'type' => 'cooling',
                'type_label' => 'کاهش فعالیت',
                'priority' => 'MEDIUM',
                'confidence' => 0.7,
                'user_id' => $person['user_id'],
                'phone' => $person['phone'],
                'person_name' => $person['label'],
                'title' => 'فعالیت ۷ روز اخیر کم شده',
                'what_happened' => $person['label'] . ' از ' . $p7 . ' بازدید در هفته قبل به ' . $d7 . ' بازدید در این هفته رسیده.',
                'why_it_matters' => 'اگر پیگیری باز داشته، ممکن است نیاز به جمع‌بندی داشته باشد.',
                'interpretation' => 'کاهش تعامل دیده شد؛ علت نسبت داده نمی‌شود.',
                'action' => null,
                'signals' => ['کاهش فعالیت'],
                'facts' => ['ad_views هفت روز اخیر: ' . $d7, 'ad_views هفت روز قبل: ' . $p7],
                'sources' => [['ad_views', $who]],
            ]);
        }
    }

    foreach ($snap['requests'] as $r) {
        $rid = (int)$r['id'];
        $st = (string)($r['status'] ?? 'new');
        $active = in_array($st, ['new', 'tracking', ''], true);
        $created = strtotime((string)($r['created_at'] ?? '')) ?: 0;
        $age = $created ? (int)floor(($now - $created) / 86400) : 0;
        $ms = $matchByReq[$rid] ?? [];
        $who = astWho($r);
        $person = astPerson($users, $who !== '' ? $who : ('p:' . trim((string)($r['phone'] ?? ''))), $r);
        $code = (string)($r['tracking_code'] ?? $rid);
        $meta = trim((string)($r['transaction_type'] ?? '') . ' ' . (string)($r['property_type'] ?? ''));
        $loc = trim((string)($r['location'] ?? ''));
        $strong = [];
        foreach ($ms as $m) {
            if ((float)$m['match_percent'] >= $th['high_match_score']) {
                $strong[] = $m;
            }
        }

        if ($active && $age >= $th['old_request_days']) {
            $insights[] = astInsightRow([
                'type' => 'old_request',
                'type_label' => 'درخواست قدیمی',
                'priority' => $ms ? 'MEDIUM' : 'HIGH',
                'confidence' => 0.9,
                'user_id' => $person['user_id'],
                'request_id' => $rid,
                'phone' => $person['phone'] ?: (string)($r['phone'] ?? ''),
                'person_name' => $person['label'],
                'tracking_code' => $code,
                'title' => 'درخواست هنوز باز است',
                'what_happened' => 'درخواست ' . $code . ' از ' . $age . ' روز پیش با وضعیت «' . astReqStatus($st) . '» باز مانده.',
                'why_it_matters' => 'درخواست فعال قدیمی در صف عملیات جا می‌ماند.',
                'interpretation' => 'نیازمند بررسی وضعیت است؛ علت باز ماندن از این داده معلوم نیست.',
                'action' => 'وضعیت این درخواست در تب درخواست‌ها بررسی شود.',
                'signals' => ['درخواست قدیمی فعال'],
                'facts' => [
                    'جدول property_requests، کد ' . $code,
                    'وضعیت: ' . astReqStatus($st),
                    $age . ' روز از created_at',
                    count($ms) . ' ردیف در request_matches',
                    $meta !== '' ? $meta : 'نوع معامله/ملک خالی',
                    $loc !== '' ? 'محدوده: ' . $loc : 'محدوده ثبت نشده',
                ],
                'sources' => [['property_requests', (string)$rid]],
            ]);
        }

        if ($active && !$ms) {
            $insights[] = astInsightRow([
                'type' => 'no_match',
                'type_label' => 'بدون فایل منطبق',
                'priority' => 'HIGH',
                'confidence' => 0.88,
                'user_id' => $person['user_id'],
                'request_id' => $rid,
                'phone' => $person['phone'] ?: (string)($r['phone'] ?? ''),
                'person_name' => $person['label'],
                'tracking_code' => $code,
                'title' => 'برای این درخواست فایل منطبقی نیست',
                'what_happened' => 'درخواست ' . $code . ' فعال است و در request_matches ردیفی ندارد.',
                'why_it_matters' => 'مشتری منتظر فایل است و موتور تطبیق فعلاً چیزی نداده.',
                'interpretation' => 'یا موجودی آگهی کم است یا شرایط درخواست محدودکننده است.',
                'action' => 'شرایط درخواست و موجودی آگهی‌های منتشرشده بررسی شود.',
                'signals' => ['بدون تطبیق'],
                'facts' => [
                    'کد رهگیری ' . $code,
                    $meta !== '' ? $meta : 'نوع خالی',
                    $loc !== '' ? 'محدوده: ' . $loc : 'محدوده خالی',
                    'min/max متراژ: ' . (($r['min_area'] ?? '—') . ' تا ' . ($r['max_area'] ?? '—')),
                    'تعداد تطبیق: ۰',
                ],
                'sources' => [['property_requests', (string)$rid]],
            ]);
        }

        if ($strong) {
            $top = $strong[0];
            $adId = (string)$top['ad_id'];
            $adTitle = (string)(($ads[$adId]['title'] ?? '') ?: ('ملک ' . $adId));
            $pct = rtrim(rtrim(number_format((float)$top['match_percent'], 1, '.', ''), '0'), '.');
            $facts = [
                'کد رهگیری ' . $code,
                count($strong) . ' ردیف با match_percent ≥ ' . $th['high_match_score'],
                'بالاترین: ' . $pct . '٪ روی آگهی ' . $adId,
            ];
            if (isset($top['location_score'])) {
                $facts[] = 'امتیاز محدوده ' . $top['location_score'] . '، متراژ ' . ($top['area_score'] ?? '—') . '، بودجه ' . ($top['budget_score'] ?? '—');
            }
            if (!empty($top['is_notified'])) {
                $facts[] = 'is_notified=1 (قبلاً اطلاع داده شده)';
            }
            if (isset($smsByReq[$rid])) {
                $facts[] = 'پیامک لید برای این درخواست ارسال شده';
            }
            $insights[] = astInsightRow([
                'type' => count($strong) >= 3 ? 'multi_match' : 'high_match',
                'type_label' => count($strong) >= 3 ? 'چند فایل مناسب' : 'تطبیق قوی',
                'priority' => 'HIGH',
                'confidence' => 0.92,
                'user_id' => $person['user_id'],
                'request_id' => $rid,
                'ad_id' => $adId,
                'phone' => $person['phone'] ?: (string)($r['phone'] ?? ''),
                'person_name' => $person['label'],
                'tracking_code' => $code,
                'ad_title' => $adTitle,
                'title' => count($strong) >= 3 ? 'چند آگهی با تطبیق بالا' : 'تطبیق بالا برای درخواست',
                'what_happened' => 'برای درخواست ' . $code . '، ' . count($strong) . ' آگهی با امتیاز حداقل ' . $th['high_match_score'] . '٪ در request_matches هست. نمونه: «' . $adTitle . '».',
                'why_it_matters' => 'ارسال فایل منطبق از پنل پیامک برای این درخواست ارزش بررسی دارد.',
                'interpretation' => 'امتیاز از موتور تطبیق ملکینو است، نه حدس.',
                'action' => empty($smsByReq[$rid]) ? 'ارسال پیامک فایل‌های منطبق از تب درخواست / صفحه لید قابل بررسی است.' : null,
                'signals' => count($strong) >= 3 ? ['چند تطبیق قوی'] : ['تطبیق قوی'],
                'facts' => array_slice($facts, 0, 6),
                'sources' => [['request_matches', (string)$rid], ['ads', $adId]],
            ]);
        }

        // behavioral: medium match + high views
        foreach ($ms as $m) {
            $pct = (float)$m['match_percent'];
            if ($pct >= $th['high_match_score'] || $pct < 50) {
                continue;
            }
            $adId = (string)$m['ad_id'];
            $whoKey = $who !== '' ? $who : ('p:' . trim((string)($r['phone'] ?? '')));
            $pair = $ua[$whoKey . '|' . $adId] ?? null;
            if (!$pair || $pair['n'] < $th['repeated_view']) {
                continue;
            }
            $adTitle = (string)(($ads[$adId]['title'] ?? '') ?: ('ملک ' . $adId));
            $insights[] = astInsightRow([
                'type' => 'behavioral_match',
                'type_label' => 'تطبیق متوسط، بازدید زیاد',
                'priority' => 'HIGH',
                'confidence' => 0.78,
                'user_id' => $person['user_id'],
                'request_id' => $rid,
                'ad_id' => $adId,
                'phone' => $person['phone'] ?: (string)($r['phone'] ?? ''),
                'person_name' => $person['label'],
                'tracking_code' => $code,
                'ad_title' => $adTitle,
                'title' => 'بازدید زیاد روی فایل با تطبیق متوسط',
                'what_happened' => $person['label'] . ' آگهی «' . $adTitle . '» را ' . $pair['n'] . ' بار دیده؛ match_percent=' . rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '٪.',
                'why_it_matters' => 'امتیاز رسمی متوسط است ولی رفتار روی همین فایل بالاست.',
                'interpretation' => 'احتمال توجه واقعی بیشتر از عدد تطبیق است؛ نیت قطعی ثابت نیست.',
                'action' => 'بررسی این آگهی برای ارسال به متقاضی.',
                'signals' => ['تطبیق رفتاری'],
                'facts' => [
                    $pair['n'] . ' مشاهده در ad_views',
                    'match_percent از request_matches: ' . $pct,
                    'کد درخواست ' . $code,
                ],
                'sources' => [['ad_views', $adId], ['request_matches', (string)$rid]],
                'last_activity' => $pair['last'] ? date('Y-m-d H:i:s', $pair['last']) : null,
            ]);
        }
    }

    foreach ($byAd as $adId => $info) {
        $ad = $ads[$adId] ?? [];
        $title = trim((string)($ad['title'] ?? $info['title'] ?? ''));
        if ($title === '') {
            $title = 'ملک کد ' . $adId;
        }
        $d3 = (int)($info['d3'] ?? 0);
        $p3 = (int)($info['p3'] ?? 0);
        $usersN = isset($info['who']) ? count($info['who']) : 0;
        $repeat = 0;
        foreach ($info['who'] ?? [] as $c) {
            if ($c >= 3) {
                $repeat++;
            }
        }
        if ($p3 > 0 && $d3 >= $p3 * 2 && $d3 >= 6) {
            $pct = round((($d3 - $p3) / $p3) * 100);
            $insights[] = astInsightRow([
                'type' => 'trending_ad',
                'type_label' => 'رشد بازدید آگهی',
                'priority' => 'HIGH',
                'confidence' => 0.8,
                'ad_id' => $adId,
                'ad_title' => $title,
                'person_name' => $title,
                'title' => 'بازدید ۳ روز اخیر بالا رفته',
                'what_happened' => 'آگهی «' . $title . '» (کد ' . $adId . ') در ۳ روز اخیر ' . $d3 . ' بازدید داشته؛ ۳ روز قبل ' . $p3 . ' بازدید.',
                'why_it_matters' => 'رشد حدود ' . $pct . '٪ نسبت به بازه قبل.',
                'interpretation' => 'توجه به این فایل بیشتر شده؛ علت (قیمت/کانال/جدید بودن) از این جدول ثابت نمی‌شود.',
                'action' => $repeat >= 2 ? 'وضعیت ملک با مالک قابل پیگیری است.' : null,
                'signals' => ['رشد بازدید'],
                'facts' => [
                    'ad_views سه روز اخیر: ' . $d3,
                    'ad_views سه روز قبل: ' . $p3,
                    $usersN . ' کاربر یکتا',
                    'وضعیت آگهی: ' . astAdStatus((string)($ad['status'] ?? '')),
                ],
                'sources' => [['ad_views', $adId], ['ads', $adId]],
            ]);
        }
        if ($repeat >= $th['multi_user']) {
            $insights[] = astInsightRow([
                'type' => 'multi_user_ad',
                'type_label' => 'چند متقاضی روی یک فایل',
                'priority' => 'HIGH',
                'confidence' => 0.84,
                'ad_id' => $adId,
                'ad_title' => $title,
                'person_name' => $title,
                'title' => 'چند نفر همین آگهی را تکرار دیده‌اند',
                'what_happened' => 'آگهی «' . $title . '» را ' . $usersN . ' نفر دیده‌اند؛ ' . $repeat . ' نفر حداقل ۳ بار.',
                'why_it_matters' => 'فایل هم‌زمان برای چند متقاضی مهم شده.',
                'interpretation' => 'تقاضای متمرکز روی یک کد ملک.',
                'action' => 'پیگیری وضعیت ملک با مالک (' . trim((string)($ad['last_name'] ?? '')) . ') پیشنهاد می‌شود.',
                'signals' => ['تمرکز چند کاربر'],
                'facts' => [
                    'کل مشاهده در نمونه: ' . (int)$info['n'],
                    $repeat . ' کاربر با ≥۳ بازدید',
                    'تعداد ردیف request_matches روی این آگهی: ' . count($matchByAd[$adId] ?? []),
                    'درخواست بازدید ثبت‌شده: ' . (int)($visitByAd[$adId] ?? 0),
                    !empty($ad['location']) ? 'محدوده: ' . $ad['location'] : 'محدوده خالی',
                ],
                'sources' => [['ad_views', $adId], ['ads', $adId]],
            ]);
        }
        $vr = (int)($visitByAd[$adId] ?? 0);
        if ((int)$info['n'] >= 20 && $vr === 0) {
            $insights[] = astInsightRow([
                'type' => 'view_no_visit',
                'type_label' => 'بازدید زیاد بدون درخواست بازدید',
                'priority' => 'MEDIUM',
                'confidence' => 0.66,
                'ad_id' => $adId,
                'ad_title' => $title,
                'person_name' => $title,
                'title' => 'بازدید زیاد، درخواست بازدید صفر',
                'what_happened' => 'آگهی «' . $title . '» در نمونه ad_views، ' . (int)$info['n'] . ' مشاهده دارد و در visit_requests ردیفی ندارد.',
                'why_it_matters' => 'تبدیل مشاهده به درخواست بازدید پایین است.',
                'interpretation' => 'نمی‌توان گفت علت قیمت است؛ فقط ریزش در همین دو جدول دیده می‌شود.',
                'action' => 'کیفیت اطلاعات آگهی در پنل بررسی شود.',
                'signals' => ['بازدید بدون تبدیل'],
                'facts' => [
                    'ad_views: ' . (int)$info['n'],
                    'visit_requests: ۰',
                    'وضعیت: ' . astAdStatus((string)($ad['status'] ?? '')),
                ],
                'sources' => [['ad_views', $adId], ['visit_requests', $adId]],
            ]);
        }
    }

    foreach ($visitOpen as $v) {
        $st = (string)($v['status'] ?? 'new');
        $who = astWho($v);
        $person = astPerson($users, $who, $v);
        $adId = trim((string)($v['ad_id'] ?? ''));
        $title = trim((string)($v['ad_title'] ?? (($ads[$adId]['title'] ?? '') ?: ('ملک ' . $adId))));
        $created = (string)($v['created_at'] ?? '');
        $prio = $st === 'new' ? 'HIGH' : 'MEDIUM';
        $insights[] = astInsightRow([
            'type' => 'open_visit',
            'type_label' => 'درخواست بازدید باز',
            'priority' => $prio,
            'confidence' => 0.93,
            'user_id' => $person['user_id'],
            'ad_id' => $adId !== '' ? $adId : null,
            'visit_id' => (int)$v['id'],
            'phone' => $person['phone'] ?: (string)($v['phone'] ?? ''),
            'person_name' => $person['label'] !== 'کاربر بدون نام' ? $person['label'] : trim((string)($v['name'] ?? 'بدون نام')),
            'tracking_code' => (string)($v['tracking_code'] ?? ''),
            'ad_title' => $title,
            'title' => 'درخواست بازدید در صف',
            'what_happened' => 'درخواست بازدید #' . $v['id'] . ' برای «' . $title . '» با وضعیت «' . astVisitStatus($st) . '» باز است.',
            'why_it_matters' => 'این یک اقدام واقعی مشتری است، نه فقط مشاهده.',
            'interpretation' => 'نیاز به هماهنگی بازدید.',
            'action' => 'از تب درخواست بازدید پیگیری شود.',
            'signals' => ['درخواست بازدید باز'],
            'facts' => [
                'visit_requests #' . $v['id'],
                'وضعیت: ' . astVisitStatus($st),
                !empty($v['preferred_date_fa']) ? 'تاریخ پیشنهادی: ' . $v['preferred_date_fa'] : (!empty($v['preferred_date']) ? 'تاریخ: ' . $v['preferred_date'] : 'تاریخ خالی'),
                $created !== '' ? 'ثبت: ' . $created : 'زمان ثبت نامشخص',
            ],
            'sources' => [['visit_requests', (string)$v['id']]],
            'last_activity' => $created !== '' ? $created : null,
        ]);
    }

    $seen = [];
    $out = [];
    foreach ($insights as $ins) {
        $fp = $ins['type'] . '|' . ($ins['user_id'] ?? '') . '|' . ($ins['ad_id'] ?? '') . '|' . ($ins['request_id'] ?? '') . '|' . ($ins['visit_id'] ?? '');
        if (isset($seen[$fp])) {
            continue;
        }
        $seen[$fp] = true;
        $ins['fingerprint'] = substr(sha1($fp), 0, 24);
        $out[] = $ins;
    }
    $rank = ['CRITICAL' => 4, 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
    usort($out, static function ($a, $b) use ($rank) {
        $d = ($rank[$b['priority']] ?? 0) - ($rank[$a['priority']] ?? 0);
        if ($d !== 0) {
            return $d;
        }
        return ($b['confidence'] <=> $a['confidence']);
    });
    return array_slice($out, 0, 60);
}

function astSaveRun(PDO $pdo, array $snap, array $insights, array $th): int
{
    astEnsureSchema($pdo);
    $pdo->beginTransaction();
    try {
        $notes = implode("\n", $snap['notes'] ?? []);
        $pdo->prepare(
            'INSERT INTO assistant_runs(started_at, finished_at, views_n, requests_n, matches_n, ads_n, favorites_n, visits_n, users_n, insights_n, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            date('Y-m-d H:i:s', (int)$snap['now']),
            date('Y-m-d H:i:s'),
            count($snap['views']),
            count($snap['requests']),
            count($snap['matches']),
            count($snap['ads']),
            count($snap['favs']),
            count($snap['visits']),
            count($snap['users']),
            count($insights),
            $notes,
        ]);
        $runId = (int)$pdo->lastInsertId();
        $insSt = $pdo->prepare(
            'INSERT INTO assistant_insights(run_id,fingerprint,type,type_label,priority,confidence,user_id,ad_id,request_id,visit_id,phone,person_name,tracking_code,ad_title,title,what_happened,why_it_matters,interpretation,action,signals,score,last_activity)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $evSt = $pdo->prepare('INSERT INTO assistant_evidence(insight_id,fact_text,source_table,source_id) VALUES (?,?,?,?)');
        foreach ($insights as $ins) {
            $insSt->execute([
                $runId,
                $ins['fingerprint'],
                $ins['type'],
                $ins['type_label'],
                $ins['priority'],
                $ins['confidence'],
                $ins['user_id'],
                $ins['ad_id'],
                $ins['request_id'],
                $ins['visit_id'],
                $ins['phone'] !== '' ? $ins['phone'] : null,
                $ins['person_name'] !== '' ? $ins['person_name'] : null,
                $ins['tracking_code'] !== '' ? $ins['tracking_code'] : null,
                $ins['ad_title'] !== '' ? $ins['ad_title'] : null,
                $ins['title'],
                $ins['what_happened'],
                $ins['why_it_matters'],
                $ins['interpretation'],
                $ins['action'],
                implode('|', (array)$ins['signals']),
                $ins['score'],
                $ins['last_activity'],
            ]);
            $iid = (int)$pdo->lastInsertId();
            foreach ((array)$ins['facts'] as $i => $fact) {
                $src = $ins['sources'][$i] ?? ($ins['sources'][0] ?? [null, null]);
                $evSt->execute([$iid, $fact, $src[0] ?? null, $src[1] ?? null]);
            }
        }
        $allRuns = astTry($pdo, 'SELECT id FROM assistant_runs ORDER BY id ASC');
        if (count($allRuns) > 5) {
            $drop = array_slice($allRuns, 0, count($allRuns) - 5);
            $in = implode(',', array_map(static function ($r) {
                return (int)$r['id'];
            }, $drop));
            if ($in !== '') {
                $pdo->exec('DELETE e FROM assistant_evidence e INNER JOIN assistant_insights i ON i.id = e.insight_id WHERE i.run_id IN (' . $in . ')');
                $pdo->exec('DELETE FROM assistant_insights WHERE run_id IN (' . $in . ')');
                $pdo->exec('DELETE FROM assistant_runs WHERE id IN (' . $in . ')');
            }
        }
        $pdo->commit();
        return $runId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function astPublicInsight(array $row, array $facts = []): array
{
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : null,
        'type' => $row['type'],
        'type_label' => $row['type_label'],
        'priority' => $row['priority'],
        'confidence' => (float)$row['confidence'],
        'user_id' => $row['user_id'] !== null && $row['user_id'] !== '' ? (int)$row['user_id'] : null,
        'ad_id' => $row['ad_id'] !== null && $row['ad_id'] !== '' ? (string)$row['ad_id'] : null,
        'request_id' => $row['request_id'] !== null && $row['request_id'] !== '' ? (int)$row['request_id'] : null,
        'visit_id' => $row['visit_id'] !== null && $row['visit_id'] !== '' ? (int)$row['visit_id'] : null,
        'phone' => (string)($row['phone'] ?? ''),
        'person_name' => (string)($row['person_name'] ?? ''),
        'tracking_code' => (string)($row['tracking_code'] ?? ''),
        'ad_title' => (string)($row['ad_title'] ?? ''),
        'title' => (string)$row['title'],
        'what_happened' => (string)$row['what_happened'],
        'why_it_matters' => (string)$row['why_it_matters'],
        'interpretation' => (string)$row['interpretation'],
        'action' => $row['action'] !== null && $row['action'] !== '' ? (string)$row['action'] : null,
        'signals' => array_values(array_filter(explode('|', (string)($row['signals'] ?? '')))),
        'facts' => $facts,
        'score' => $row['score'] !== null && $row['score'] !== '' ? (int)$row['score'] : null,
        'last_activity' => $row['last_activity'] ?? null,
    ];
}

function astLoadBoard(PDO $pdo): array
{
    astEnsureSchema($pdo);
    $run = astTry($pdo, 'SELECT * FROM assistant_runs ORDER BY id DESC LIMIT 1');
    $run = $run[0] ?? null;
    if (!$run) {
        return ['run' => null, 'insights' => [], 'kpis' => astEmptyKpis()];
    }
    $rows = astTry($pdo, 'SELECT * FROM assistant_insights WHERE run_id=? ORDER BY FIELD(priority,"CRITICAL","HIGH","MEDIUM","LOW"), confidence DESC', [(int)$run['id']]);
    $factsBy = [];
    if ($rows) {
        $ids = array_map(static function ($r) {
            return (int)$r['id'];
        }, $rows);
        $ev = astTry($pdo, 'SELECT insight_id, fact_text FROM assistant_evidence WHERE insight_id IN (' . implode(',', $ids) . ') ORDER BY id ASC');
        foreach ($ev as $e) {
            $factsBy[(int)$e['insight_id']][] = $e['fact_text'];
        }
    }
    $insights = [];
    foreach ($rows as $r) {
        $insights[] = astPublicInsight($r, $factsBy[(int)$r['id']] ?? []);
    }
    return ['run' => $run, 'insights' => $insights, 'kpis' => astKpis($insights, $run)];
}

function astEmptyKpis(): array
{
    return [
        'hot' => 0, 'match' => 0, 'request' => 0, 'visit' => 0, 'ad' => 0, 'all' => 0,
    ];
}

function astKpis(array $insights, array $run): array
{
    $k = astEmptyKpis();
    $k['all'] = count($insights);
    foreach ($insights as $i) {
        $t = $i['type'];
        if (in_array($t, ['hot_lead', 'interest_no_action', 'warming'], true)) {
            $k['hot']++;
        }
        if (in_array($t, ['high_match', 'multi_match', 'behavioral_match'], true)) {
            $k['match']++;
        }
        if (in_array($t, ['old_request', 'no_match'], true)) {
            $k['request']++;
        }
        if ($t === 'open_visit') {
            $k['visit']++;
        }
        if (in_array($t, ['trending_ad', 'multi_user_ad', 'view_no_visit'], true)) {
            $k['ad']++;
        }
    }
    $k['views_n'] = (int)($run['views_n'] ?? 0);
    $k['requests_n'] = (int)($run['requests_n'] ?? 0);
    $k['matches_n'] = (int)($run['matches_n'] ?? 0);
    $k['ads_n'] = (int)($run['ads_n'] ?? 0);
    return $k;
}

function astRefresh(PDO $pdo): array
{
    astEnsureSchema($pdo);
    $th = astThresholds();
    $snap = astLoadSnapshot($pdo);
    $insights = astBuildInsights($snap, $th);
    astSaveRun($pdo, $snap, $insights, $th);
    return astLoadBoard($pdo);
}

function astSaveChat(PDO $pdo, string $role, string $message): void
{
    astEnsureSchema($pdo);
    try {
        $pdo->prepare('INSERT INTO assistant_chat(role, message) VALUES (?,?)')->execute([$role, $message]);
    } catch (Throwable $e) {
    }
}

function astChatHistory(PDO $pdo, int $limit = 40): array
{
    astEnsureSchema($pdo);
    $rows = astTry($pdo, 'SELECT role, message, created_at FROM assistant_chat ORDER BY id DESC LIMIT ' . (int)$limit);
    return array_reverse($rows);
}

function astFilterBoard(array $insights, string $q): array
{
    $q = trim($q);
    if ($q === '') {
        return $insights;
    }
    $low = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $out = [];
    foreach ($insights as $ins) {
        $hay = function_exists('mb_strtolower')
            ? mb_strtolower(json_encode($ins, JSON_UNESCAPED_UNICODE) ?: '', 'UTF-8')
            : strtolower(json_encode($ins, JSON_UNESCAPED_UNICODE) ?: '');
        if (strpos($hay, $low) !== false) {
            $out[] = $ins;
        }
    }
    return $out;
}

function astChatAnswer(array $board, string $message): array
{
    $insights = $board['insights'];
    $q = trim($message);
    $low = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);

    $pickTypes = static function (array $types) use ($insights) {
        return array_values(array_filter($insights, static function ($i) use ($types) {
            return in_array($i['type'], $types, true);
        }));
    };

    if ($q === '' || preg_match('/گزارش|خلاصه|امروز|این هفته/u', $low)) {
        if (preg_match('/پیگیری|چه کسانی|کی(?:ا)?/u', $low)) {
            $rows = array_values(array_filter($insights, static function ($i) {
                return in_array($i['priority'], ['CRITICAL', 'HIGH'], true)
                    && $i['action'];
            }));
            return [
                'reply' => $rows ? ('موارد با اقدام مشخص: ' . count($rows) . ' مورد. کارت‌ها از جدول‌های ملکینو آمده‌اند.') : 'الان موردی با شواهد کافی برای پیگیری فوری در آخرین تحلیل نبود.',
                'insights' => array_slice($rows, 0, 12),
                'filter' => 'follow',
            ];
        }
        return [
            'reply' => 'تحلیل از ads، ad_views، property_requests، request_matches، favorites، visit_requests و users است. ' . count($insights) . ' مورد در آخرین اجرا.',
            'insights' => $insights,
            'filter' => 'all',
        ];
    }
    if (preg_match('/داغ|لید/u', $low)) {
        $rows = $pickTypes(['hot_lead', 'interest_no_action', 'warming']);
        return ['reply' => $rows ? count($rows) . ' لید با شواهد بازدید/علاقه/تطبیق.' : 'لید داغی در آخرین اجرا نبود.', 'insights' => $rows, 'filter' => 'hot'];
    }
    if (preg_match('/بدون نتیجه|بدون تطبیق|قدیمی/u', $low)) {
        $rows = $pickTypes(['no_match', 'old_request']);
        return ['reply' => $rows ? count($rows) . ' درخواست نیازمند توجه.' : 'درخواست بازِ بدون تطبیق یا خیلی قدیمی نبود.', 'insights' => $rows, 'filter' => 'request'];
    }
    if (preg_match('/تطبیق|منطبق|match/u', $low)) {
        $rows = $pickTypes(['high_match', 'multi_match', 'behavioral_match']);
        return ['reply' => $rows ? count($rows) . ' مورد از جدول request_matches.' : 'تطبیق بالای آستانه نبود.', 'insights' => $rows, 'filter' => 'match'];
    }
    if (preg_match('/بازدید زیاد|تقاضا|آگهی/u', $low)) {
        $rows = $pickTypes(['trending_ad', 'multi_user_ad', 'view_no_visit', 'repeat_view']);
        return ['reply' => $rows ? count($rows) . ' مورد از ad_views و ads.' : 'موردی برای آگهی‌ها نبود.', 'insights' => $rows, 'filter' => 'ad'];
    }
    if (preg_match('/بازدید حضوری|نوبت بازدید/u', $low)) {
        $rows = $pickTypes(['open_visit']);
        return ['reply' => $rows ? count($rows) . ' درخواست بازدید باز از visit_requests.' : 'درخواست بازدید بازی نبود.', 'insights' => $rows, 'filter' => 'visit'];
    }

    $found = astFilterBoard($insights, $q);
    if ($found) {
        return ['reply' => count($found) . ' مورد در آخرین تحلیل با این عبارت جور شد.', 'insights' => array_slice($found, 0, 16), 'filter' => 'search'];
    }
    return ['reply' => 'در جدول تحلیل دستیار موردی با این عبارت نبود. کد ملک، کد رهگیری یا نام را دقیق‌تر بفرست.', 'insights' => [], 'filter' => 'search'];
}
