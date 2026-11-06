<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/visit-request-lib.php';

global $pdo;

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
if ($action === '') {
    melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    melkinoCsrfCheck();
}

if (!$pdo instanceof PDO) {
    melkinoJsonResponse(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}

melkinoEnsureVisitRequestSchema();

$identity = function_exists('melkinoCurrentIdentity')
    ? melkinoCurrentIdentity()
    : ['user_id' => null, 'telegram_id' => '', 'phone' => '', 'user' => []];

$isAdmin = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

if ($action === 'days') {
    $closed = melkinoVisitClosedSettings();
    $days = melkinoVisitNextDays(7);
    // ظرفیت روزانه: هر روز/بازه که پر شد، غیرقابل انتخاب می‌شود
    $cap = melkinoVisitCapacity();
    $counts = melkinoVisitDayCounts(array_map(static fn($d) => (string) $d['date'], $days));
    foreach ($days as $i => $d) {
        $iso = (string) $d['date'];
        $days[$i]['capacity'] = $cap;
        $days[$i]['slots_state'] = [];
        $anyFull = false;
        foreach (melkinoVisitSlots() as $slotKey => $slotLabel) {
            $limit = (int) ($cap[$slotKey] ?? 0);
            $used = (int) ($counts[$iso][$slotKey] ?? 0);
            $full = ($limit > 0 && $used >= $limit);
            $days[$i]['slots_state'][$slotKey] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit > 0 ? max(0, $limit - $used) : null,
                'full' => $full,
            ];
            if ($full) {
                $anyFull = true;
            }
        }
        $days[$i]['full'] = $anyFull && !array_filter($days[$i]['slots_state'], static fn($st) => empty($st['full']));
        // روز «کاملاً پر» یعنی همهٔ بازه‌های محدوددار پر باشند
        $limited = array_filter($cap, static fn($v) => (int) $v > 0);
        $days[$i]['full'] = !empty($limited) && $anyFull
            && !array_filter($days[$i]['slots_state'], static fn($st) => ((int) $st['limit'] > 0 && !$st['full']));
    }
    melkinoJsonResponse([
        'success' => true,
        'days' => $days,
        'slots' => melkinoVisitSlots(),
        'closed_weekdays' => $closed['weekdays'],
        'closed_dates' => $closed['dates'],
    ]);
}

if ($action === 'admin_holidays') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    $closed = melkinoVisitClosedSettings();
    melkinoJsonResponse([
        'success' => true,
        'weekdays' => $closed['weekdays'],
        'dates' => $closed['dates'],
        'weekday_options' => melkinoVisitWeekdayAdminOrder(),
    ]);
}

if ($action === 'admin_save_holidays') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $weekdays = $_POST['weekdays'] ?? [];
    if (is_string($weekdays)) {
        $decoded = json_decode($weekdays, true);
        $weekdays = is_array($decoded) ? $decoded : explode(',', $weekdays);
    }
    if (!is_array($weekdays)) {
        $weekdays = [];
    }
    $datesRaw = trim((string) ($_POST['dates'] ?? ''));
    $dateLines = preg_split('/\r\n|\r|\n/', $datesRaw) ?: [];
    if (!melkinoVisitSaveClosedSettings($weekdays, $dateLines)) {
        melkinoJsonResponse(['success' => false, 'message' => 'ذخیره تنظیمات تعطیلی انجام نشد.'], 500);
    }
    $closed = melkinoVisitClosedSettings();
    melkinoJsonResponse([
        'success' => true,
        'message' => 'روزهای تعطیل ذخیره شد.',
        'weekdays' => $closed['weekdays'],
        'dates' => $closed['dates'],
    ]);
}

if ($action === 'create') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $gate = function_exists('melkinoVisitRequesterGate')
        ? melkinoVisitRequesterGate($identity)
        : ['ok' => false, 'login' => true, 'need_phone' => false, 'message' => 'برای درخواست بازدید ابتدا وارد حساب شوید.', 'phone' => ''];
    if (empty($gate['ok'])) {
        $code = !empty($gate['login']) ? 401 : 403;
        melkinoJsonResponse([
            'success' => false,
            'message' => (string) ($gate['message'] ?? 'امکان ثبت درخواست بازدید نیست.'),
            'login' => !empty($gate['login']),
            'need_phone' => !empty($gate['need_phone']),
        ], $code);
    }

    $adId = trim((string) ($_POST['ad_id'] ?? ''));
    $date = melkinoVisitNormalizeDigits(trim((string) ($_POST['preferred_date'] ?? '')));
    if (function_exists('melkinoVisitToGregorianDate')) {
        $asG = melkinoVisitToGregorianDate($date);
        if ($asG !== '') {
            $date = $asG;
        }
    }
    $slot = trim((string) ($_POST['time_slot'] ?? ''));
    $alt = trim((string) ($_POST['alternative_datetime'] ?? ''));
    if (function_exists('mb_substr')) {
        $alt = mb_substr($alt, 0, 500);
    } else {
        $alt = substr($alt, 0, 500);
    }
    $slots = melkinoVisitSlots();
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی نامعتبر است.'], 422);
    }
    if (!isset($slots[$slot])) {
        melkinoJsonResponse(['success' => false, 'message' => 'بازه زمانی را انتخاب کنید (صبح یا عصر).'], 422);
    }

    $allowed = [];
    foreach (melkinoVisitNextDays(7) as $dayRow) {
        $allowed[(string) $dayRow['date']] = $dayRow;
    }
    if (!isset($allowed[$date])) {
        melkinoJsonResponse(['success' => false, 'message' => 'روز انتخاب‌شده معتبر نیست. از فردا تا یک هفته بعد را انتخاب کنید.'], 422);
    }
    $day = $allowed[$date];
    if (!empty($day['closed']) || (isset($day['selectable']) && !$day['selectable'])) {
        melkinoJsonResponse(['success' => false, 'message' => 'این روز تعطیل است و برای بازدید قابل انتخاب نیست.'], 422);
    }
    // ظرفیت روزانه (تنظیم ادمین): اگر بازهٔ انتخابی پر بود، ثبت نمی‌شود
    $cap = melkinoVisitCapacity();
    $slotLimit = (int) ($cap[$slot] ?? 0);
    if ($slotLimit > 0) {
        $__counts = melkinoVisitDayCounts([$date]);
        $__used = (int) ($__counts[$date][$slot] ?? 0);
        if ($__used >= $slotLimit) {
            melkinoJsonResponse([
                'success' => false,
                'message' => 'به علت کامل بودن برنامه بازدیدها برای «' . $day['label'] . '» امکان ثبت بازدید نیست؛ لطفاً روز دیگری را انتخاب کنید.',
            ], 422);
        }
    }

    $adTitle = '';
    $advLast = '';
    $advPhone = '';
    $snapshot = [];
    try {
        $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        $st->execute([$adId]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ad) {
            melkinoJsonResponse(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
        }
        $adTitle = trim((string) ($ad['title'] ?? ''));
        $advLast = trim((string) ($ad['last_name'] ?? ''));
        $advPhone = melkinoVisitNormalizePhone((string) ($ad['phone'] ?? ''));
        $snapshot = melkinoVisitAdSnapshot($ad);
    } catch (Throwable $e) {
        $adTitle = trim((string) ($_POST['ad_title'] ?? ''));
    }

    $name = melkinoVisitRequesterName($identity);
    $phone = melkinoVisitNormalizePhone((string) ($identity['phone'] ?? ''));
    $user = is_array($identity['user'] ?? null) ? $identity['user'] : [];
    if ($phone === '' && !empty($user['phone'])) {
        $phone = melkinoVisitNormalizePhone((string) $user['phone']);
    }

    try {
        $ins = $pdo->prepare('INSERT INTO visit_requests
            (ad_id, ad_title, user_id, telegram_id, phone, name, preferred_date, preferred_date_fa, weekday, time_slot, alternative_datetime, advertiser_last_name, advertiser_phone, ad_snapshot, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'new\', NOW())');
        $ins->execute([
            $adId,
            $adTitle !== '' ? $adTitle : null,
            !empty($identity['user_id']) ? (int) $identity['user_id'] : null,
            trim((string) ($identity['telegram_id'] ?? '')) !== '' ? trim((string) $identity['telegram_id']) : null,
            $phone !== '' ? $phone : null,
            $name !== '' ? $name : null,
            $date,
            $day['date_label'] ?? $day['label'] ?? $day['jalali'],
            $day['weekday'],
            $slot,
            $alt !== '' ? $alt : null,
            $advLast !== '' ? $advLast : null,
            $advPhone !== '' ? $advPhone : null,
            $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $newId = (int) $pdo->lastInsertId();
        $track = melkinoVisitTrackingCode($newId);
        try {
            $pdo->prepare('UPDATE visit_requests SET tracking_code = ?, status = \'new\', archived = 0 WHERE id = ?')->execute([$track, $newId]);
        } catch (Throwable $e2) {
            $track = $track;
        }
        $row = [
            'id' => $newId,
            'ad_id' => $adId,
            'ad_title' => $adTitle,
            'user_id' => $identity['user_id'] ?? null,
            'telegram_id' => $identity['telegram_id'] ?? null,
            'tracking_code' => $track,
            'status' => 'new',
        ];
        $msg = melkinoVisitSuccessMessage($adTitle);
        melkinoVisitNotify($row, 'visit_submitted', 'درخواست بازدید ثبت شد', $msg);
        melkinoJsonResponse(['success' => true, 'message' => $msg, 'id' => $newId, 'tracking_code' => $track]);
    } catch (Throwable $e) {
        melkinoJsonResponse(['success' => false, 'message' => 'ثبت درخواست انجام نشد. دوباره تلاش کنید.'], 500);
    }
}

if ($action === 'list') {
    [$where, $params] = melkinoVisitOwnerWhere($identity);
    if ($where === '') {
        melkinoJsonResponse(['success' => true, 'items' => []]);
    }
    $st = $pdo->prepare('SELECT * FROM visit_requests WHERE ' . $where . ' ORDER BY created_at DESC, id DESC');
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($items as &$it) {
        $it['status'] = melkinoVisitNormalizeStatus((string) ($it['status'] ?? 'new'));
        $it['when_label'] = melkinoVisitWhenLabel($it);
        $it['status_label'] = melkinoVisitStatuses()[$it['status']] ?? $it['status'];
    }
    unset($it);
    melkinoJsonResponse(['success' => true, 'items' => $items, 'statuses' => melkinoVisitStatuses(), 'slots' => melkinoVisitSlots()]);
}

if ($action === 'delete') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT * FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !melkinoVisitOwn($row, $identity)) {
        melkinoJsonResponse(['success' => false, 'message' => 'این درخواست متعلق به شما نیست.'], 403);
    }
    $stNow = melkinoVisitNormalizeStatus((string) ($row['status'] ?? 'new'));
    if ($stNow !== 'new') {
        melkinoJsonResponse(['success' => false, 'message' => 'به علت انجام پیگیری امکان حذف سیستمی نمی باشد جهت حذف درخواست بازدید با ملکینو تماس بگیرید.'], 403);
    }
    $pdo->prepare('DELETE FROM visit_requests WHERE id = ?')->execute([$id]);
    melkinoJsonResponse(['success' => true, 'message' => 'درخواست بازدید حذف شد.']);
}

if ($action === 'admin_list') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    $status = trim((string) ($_GET['status'] ?? 'all'));
    if ($status !== 'archived' && $status !== 'all') {
        $status = melkinoVisitNormalizeStatus($status);
    }
    $params = [];
    $wheres = [];
    if ($status === 'archived') {
        $wheres[] = 'COALESCE(archived,0) = 1';
    } else {
        $wheres[] = 'COALESCE(archived,0) = 0';
        if ($status !== '' && $status !== 'all' && isset(melkinoVisitStatuses()[$status])) {
            $wheres[] = 'status = ?';
            $params[] = $status;
        }
    }
    $sql = 'SELECT * FROM visit_requests';
    if ($wheres) {
        $sql .= ' WHERE ' . implode(' AND ', $wheres);
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 500';
    $items = [];
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $st = $pdo->query('SELECT * FROM visit_requests ORDER BY created_at DESC, id DESC LIMIT 500');
            $raw = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $items = [];
            foreach ($raw as $r) {
                $arch = (int) ($r['archived'] ?? 0);
                if ($status === 'archived') {
                    if ($arch === 1) {
                        $items[] = $r;
                    }
                    continue;
                }
                if ($arch === 1) {
                    continue;
                }
                if ($status !== '' && $status !== 'all'
                    && melkinoVisitNormalizeStatus((string) ($r['status'] ?? '')) !== $status) {
                    continue;
                }
                $items[] = $r;
            }
        } catch (Throwable $e2) {
            $items = [];
        }
    }
    $adIds = [];
    foreach ($items as $row) {
        $adIds[] = (string) ($row['ad_id'] ?? '');
    }
    $adsMap = function_exists('melkinoVisitLoadAdsMap') ? melkinoVisitLoadAdsMap($pdo, $adIds) : [];
    $outItems = [];
    foreach ($items as $it) {
        try {
            if ((string) ($it['status'] ?? '') === '') {
                $it['status'] = 'new';
            }
            $it['status'] = melkinoVisitNormalizeStatus((string) ($it['status'] ?? 'new'));
            $aid = (string) ($it['ad_id'] ?? '');
            if ($aid !== '' && isset($adsMap[$aid])) {
                $it['_ad'] = $adsMap[$aid];
                if (trim((string) ($it['advertiser_last_name'] ?? '')) === '') {
                    $it['advertiser_last_name'] = (string) ($adsMap[$aid]['last_name'] ?? '');
                }
                if (trim((string) ($it['advertiser_phone'] ?? '')) === '') {
                    $it['advertiser_phone'] = (string) ($adsMap[$aid]['phone'] ?? '');
                }
            }
            melkinoVisitEnsureTracking($pdo, $it);
            $info = melkinoVisitResolveAdInfo($it);
            unset($info['_ad']);
            $outItems[] = [
                'id' => (int) ($it['id'] ?? 0),
                'ad_id' => $aid,
                'tracking_code' => (string) ($it['tracking_code'] ?? ''),
                'status' => $it['status'],
                'status_label' => melkinoVisitStatuses()[$it['status']] ?? $it['status'],
                'when_label' => melkinoVisitWhenLabel($it),
                'when_parts' => function_exists('melkinoVisitWhenParts') ? melkinoVisitWhenParts($it) : [],
                'weekday' => (string) ($it['weekday'] ?? ''),
                'preferred_date' => (string) ($it['preferred_date'] ?? ''),
                'preferred_date_fa' => (string) ($it['preferred_date_fa'] ?? ''),
                'time_slot' => (string) ($it['time_slot'] ?? ''),
                'alternative_datetime' => (string) ($it['alternative_datetime'] ?? ''),
                'requester_name' => trim((string) ($it['name'] ?? '')) ?: '—',
                'requester_phone' => trim((string) ($it['phone'] ?? '')) ?: '—',
                'name' => (string) ($it['name'] ?? ''),
                'phone' => (string) ($it['phone'] ?? ''),
                'advertiser_last_name' => (string) ($it['advertiser_last_name'] ?? ''),
                'advertiser_phone' => (string) ($it['advertiser_phone'] ?? ''),
                'admin_note' => (string) ($it['admin_note'] ?? ''),
                'ad_title' => (string) ($it['ad_title'] ?? ''),
                'ad_info' => $info,
            ];
        } catch (Throwable $eRow) {
            continue;
        }
    }
    $items = $outItems;

    $counts = ['all' => 0, 'archived' => 0];
    foreach (array_keys(melkinoVisitStatuses()) as $k) {
        $counts[$k] = 0;
    }
    try {
        $c = $pdo->query('SELECT status, COALESCE(archived,0) AS archived, COUNT(*) AS c FROM visit_requests GROUP BY status, COALESCE(archived,0)');
        foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $n = (int) ($row['c'] ?? 0);
            if ((int) ($row['archived'] ?? 0) === 1) {
                $counts['archived'] += $n;
                continue;
            }
            $k = melkinoVisitNormalizeStatus((string) ($row['status'] ?? ''));
            if (isset($counts[$k])) {
                $counts[$k] += $n;
            }
            $counts['all'] += $n;
        }
    } catch (Throwable $e) {
        try {
            $c = $pdo->query('SELECT status, COUNT(*) AS c FROM visit_requests GROUP BY status');
            foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = melkinoVisitNormalizeStatus((string) ($row['status'] ?? ''));
                $n = (int) ($row['c'] ?? 0);
                if (isset($counts[$k])) {
                    $counts[$k] += $n;
                }
                $counts['all'] += $n;
            }
        } catch (Throwable $e2) {
        }
    }
    melkinoJsonResponse([
        'success' => true,
        'items' => $items,
        'counts' => $counts,
        'statuses' => melkinoVisitStatuses(),
        'slots' => melkinoVisitSlots(),
    ]);
}

if ($action === 'admin_set_status') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = melkinoVisitNormalizeStatus(trim((string) ($_POST['status'] ?? '')));
    $statuses = melkinoVisitStatuses();
    if ($id <= 0 || !isset($statuses[$status])) {
        melkinoJsonResponse(['success' => false, 'message' => 'وضعیت یا شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT * FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        melkinoJsonResponse(['success' => false, 'message' => 'درخواست پیدا نشد.'], 404);
    }
    $upd = $pdo->prepare('UPDATE visit_requests SET status = ?, updated_at = NOW() WHERE id = ?');
    $upd->execute([$status, $id]);
    $row['status'] = $status;
    melkinoVisitNotifyForStatus($row, $status);
    melkinoJsonResponse(['success' => true, 'message' => 'وضعیت به‌روز شد.', 'status' => $status, 'status_label' => $statuses[$status]]);
}

if ($action === 'admin_save_note') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 2000);
    } else {
        $note = substr($note, 0, 2000);
    }
    if ($id <= 0) {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT id FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        melkinoJsonResponse(['success' => false, 'message' => 'درخواست پیدا نشد.'], 404);
    }
    $pdo->prepare('UPDATE visit_requests SET admin_note = ?, updated_at = NOW() WHERE id = ?')->execute([$note !== '' ? $note : null, $id]);
    melkinoJsonResponse(['success' => true, 'message' => 'یادداشت ذخیره شد.']);
}

if ($action === 'admin_edit') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT * FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        melkinoJsonResponse(['success' => false, 'message' => 'درخواست یافت نشد.'], 404);
    }
    $name = trim((string) ($_POST['requester_name'] ?? ''));
    $phone = melkinoVisitNormalizePhone(trim((string) ($_POST['requester_phone'] ?? '')));
    $date = melkinoVisitNormalizeDigits(trim((string) ($_POST['preferred_date'] ?? '')));
    if (function_exists('melkinoVisitToGregorianDate')) {
        $asG = melkinoVisitToGregorianDate($date);
        if ($asG !== '') {
            $date = $asG;
        }
    }
    $slot = trim((string) ($_POST['time_slot'] ?? ''));
    $alt = trim((string) ($_POST['alternative_datetime'] ?? ''));
    if (function_exists('mb_substr')) {
        $alt = mb_substr($alt, 0, 500);
    } else {
        $alt = substr($alt, 0, 500);
    }
    $slots = melkinoVisitSlots();
    if ($name === '' || $phone === '' || $date === '' || !isset($slots[$slot])) {
        melkinoJsonResponse(['success' => false, 'message' => 'نام، شمارهٔ معتبر، تاریخ و بازهٔ زمانی الزامی است.'], 422);
    }
    if (function_exists('melkinoVisitAddColumn')) {
        // ستون‌های ویرایش ادمین ممکن است روی نصب‌های قدیمی وجود نداشته باشند
        melkinoVisitAddColumn($pdo, 'requester_name', 'VARCHAR(120) NULL');
        melkinoVisitAddColumn($pdo, 'requester_phone', 'VARCHAR(30) NULL');
    }
    $pdo->prepare('UPDATE visit_requests SET requester_name = ?, requester_phone = ?, preferred_date = ?, time_slot = ?, alternative_datetime = ? WHERE id = ?')
        ->execute([$name, $phone, $date, $slot, $alt, $id]);
    melkinoJsonResponse(['success' => true, 'message' => 'تغییرات بازدید ذخیره شد.']);
}

if ($action === 'admin_save_capacity') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $cap = [
        'morning' => max(0, min(200, (int) ($_POST['morning'] ?? 0))),
        'evening' => max(0, min(200, (int) ($_POST['evening'] ?? 0))),
    ];
    if (!function_exists('dbSettingSet')) {
        melkinoJsonResponse(['success' => false, 'message' => 'ذخیرهٔ تنظیمات در دسترس نیست.'], 500);
    }
    dbSettingSet($pdo, 'visit', 'capacity', $cap, 'json', (int) ($_SESSION['admin_id'] ?? 0));
    melkinoJsonResponse(['success' => true, 'message' => 'ظرفیت بازدید روزانه ذخیره شد.']);
}

if ($action === 'admin_delete') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT id FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        melkinoJsonResponse(['success' => false, 'message' => 'درخواست پیدا نشد.'], 404);
    }
    $pdo->prepare('DELETE FROM visit_requests WHERE id = ?')->execute([$id]);
    melkinoJsonResponse(['success' => true, 'message' => 'درخواست بازدید حذف شد.']);
}

if ($action === 'admin_archive') {
    if (!$isAdmin) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسترسی مجاز نیست.'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        melkinoJsonResponse(['success' => false, 'message' => 'روش درخواست نامعتبر است.'], 405);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare('SELECT id FROM visit_requests WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        melkinoJsonResponse(['success' => false, 'message' => 'درخواست پیدا نشد.'], 404);
    }
    try {
        $pdo->prepare('UPDATE visit_requests SET archived = 1, updated_at = NOW() WHERE id = ?')->execute([$id]);
    } catch (Throwable $e) {
        melkinoJsonResponse(['success' => false, 'message' => 'بایگانی انجام نشد. ستون بایگانی را اضافه کنید.'], 500);
    }
    melkinoJsonResponse(['success' => true, 'message' => 'درخواست بایگانی شد.']);
}

melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
