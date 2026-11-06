<?php require_once __DIR__ . '/config.php';
/*
|--------------------------------------------------------------------------
| admin-sms-api.php — API تب «برنامهٔ پیامک» پنل ادمین
|--------------------------------------------------------------------------
| اکشن‌ها (JSON، همه با گارد ادمین):
|   status   → تنظیمات + وضعیت سرویس‌دهنده + شمارنده‌ها
|   save     → ذخیرهٔ تنظیمات برنامهٔ پیامک
|   tick     → اجرای دستی همان لحظه (مثل کرون ولی force)
|   balance  → استعلام اعتبار پنل ملی‌پیامک
|   test     → ارسال پیامک تست به یک شماره
|   outbox   → گزارش پیامک‌ها (کدوم ملک برای کدوم مشتری / ارسال‌شده و نشده)
|   optout_add / optout_del → مدیریت لغو عضویت
|   searches → جستجوهای ذخیره‌شدهٔ کاربران
|   search_del → حذف جستجوی ذخیره‌شده توسط ادمین
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/db-settings.php';
if (is_file(__DIR__ . '/sms.php')) {
    require_once __DIR__ . '/sms.php';
}
require_once __DIR__ . '/sms-program.php';

melkinoRequireAdminJson();

$body = method_exists('melkinoAdminJsonBody', 'x') || function_exists('melkinoAdminJsonBody') ? [] : [];
if (function_exists('melkinoAdminJsonBody')) {
    $json = melkinoAdminJsonBody();
    if (is_array($json)) {
        $body = $json;
    }
}
if (!$body) {
    $body = $_POST;
}
$action = (string)($body['action'] ?? $_GET['action'] ?? '');

global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}
smsProgramEnsureSchema($pdo);

/* ---------------- status ---------------- */
if ($action === '' || $action === 'status') {
    $cfg = smsProgramSettings($pdo);
    $sms = smsEffectiveSettings();

    $pendingAds = 0;
    try {
        $pendingAds = (int)$pdo->query("SELECT COUNT(*) FROM ads WHERE status IN ('pending','new','waiting')")->fetchColumn();
    } catch (Throwable $e) {
    }
    $pendingReqs = 0;
    try {
        $pendingReqs = (int)$pdo->query("SELECT COUNT(*) FROM property_requests WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0];
    try {
        foreach ($pdo->query("SELECT status, COUNT(*) c FROM sms_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (array_key_exists($r['status'], $counts)) {
                $counts[$r['status']] = (int)$r['c'];
            }
        }
    } catch (Throwable $e) {
    }
    $searchesN = 0;
    $optoutsN = 0;
    try {
        $searchesN = (int)$pdo->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $optoutsN = (int)$pdo->query('SELECT COUNT(*) FROM sms_optouts')->fetchColumn();
    } catch (Throwable $e) {
    }

    melkinoAdminJson([
        'success' => true,
        'settings' => [
            'enabled' => (int)$cfg['enabled'],
            'quiet_start' => (int)$cfg['quiet_start'],
            'quiet_end' => (int)$cfg['quiet_end'],
            'lagoo11' => (int)$cfg['lagoo11'],
            'max_per_tick' => (int)$cfg['max_per_tick'],
            'site_url' => (string)$cfg['site_url'],
            'cron_token_set' => trim((string)$cfg['cron_token']) !== '',
            'last_tick' => (string)$cfg['last_tick'],
            'saved_search' => $cfg['saved_search'],
            'request_match' => $cfg['request_match'],
            'admin_alert' => $cfg['admin_alert'],
            'marketing' => $cfg['marketing'],
            'otp_template' => (string)(function_exists('melkinoBotSetting') ? melkinoBotSetting('sms_otp_template', '') : ''),
        ],
        'provider' => [
            'name' => (string)($sms['provider'] ?? 'melipayamak'),
            'enabled' => !empty($sms['enabled']),
            'username_set' => trim((string)($sms['api_key'] ?? '')) !== '',
            'password_set' => trim((string)($sms['password'] ?? '')) !== '',
            'service_line' => (string)($sms['sender_line'] ?? ''),
            'otp_line' => (string)($sms['otp_line'] ?? ''),
            'promo_line' => (string)($sms['promo_line'] ?? ''),
            'otp_body_id' => (string)($sms['otp_body_id'] ?? ''),
        ],
        'routing' => smsProgramRoutingState($pdo, $cfg, $sms),
        'counts' => [
            'pending_ads' => $pendingAds,
            'pending_requests' => $pendingReqs,
            'queued' => $counts['queued'],
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
            'saved_searches' => $searchesN,
            'optouts' => $optoutsN,
        ],
    ]);
}

/* ---------------- save ---------------- */
if ($action === 'save') {
    $cfg = smsProgramSettings($pdo);
    $keep = static function (string $key, $input, $cur) {
        if ($input === null || $input === '') {
            return $cur;
        }
        return $input;
    };
    $next = [];
    $next['enabled'] = !empty($body['enabled']) ? 1 : 0;
    $next['quiet_start'] = max(0, min(23, (int)($body['quiet_start'] ?? $cfg['quiet_start'])));
    $next['quiet_end'] = max(0, min(23, (int)($body['quiet_end'] ?? $cfg['quiet_end'])));
    $next['lagoo11'] = !empty($body['lagoo11']) ? 1 : 0;
    $next['max_per_tick'] = max(1, min(100, (int)($body['max_per_tick'] ?? $cfg['max_per_tick'])));
    $next['site_url'] = trim((string)($body['site_url'] ?? $cfg['site_url']));
    $next['cron_token'] = trim((string)($body['cron_token'] ?? ''));
    if ($next['cron_token'] === '') {
        $next['cron_token'] = (string)$cfg['cron_token'];
    }
    // اهداف
    $cleanTpl = static function ($v, $fallback) {
        $v = trim((string)$v);
        // پاک‌سازی کنترل‌کاراکترها؛ خط جدید مجاز است
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '';
        return $v === '' ? $fallback : mb_substr($v, 0, 600);
    };
    $ss = is_array($body['saved_search'] ?? null) ? $body['saved_search'] : [];
    $next['saved_search'] = [
        'on' => !empty($ss['on']) ? 1 : 0,
        'cap_day' => max(1, min(10, (int)($ss['cap_day'] ?? $cfg['saved_search']['cap_day']))),
        'min_new' => max(1, min(20, (int)($ss['min_new'] ?? $cfg['saved_search']['min_new']))),
        'template' => $cleanTpl($ss['template'] ?? '', $cfg['saved_search']['template']),
        'digest_template' => $cleanTpl($ss['digest_template'] ?? '', $cfg['saved_search']['digest_template']),
    ];
    $rm = is_array($body['request_match'] ?? null) ? $body['request_match'] : [];
    $next['request_match'] = [
        'on' => !empty($rm['on']) ? 1 : 0,
        'cap_day' => max(1, min(10, (int)($rm['cap_day'] ?? $cfg['request_match']['cap_day']))),
        'score_min' => max(0, min(100, (float)($rm['score_min'] ?? $cfg['request_match']['score_min']))),
        'min_new' => max(1, min(20, (int)($rm['min_new'] ?? $cfg['request_match']['min_new']))),
        'template' => $cleanTpl($rm['template'] ?? '', $cfg['request_match']['template']),
    ];
    $aa = is_array($body['admin_alert'] ?? null) ? $body['admin_alert'] : [];
    $aaMode = (($aa['mode'] ?? $cfg['admin_alert']['mode']) === 'every') ? 'every' : 'over';
    $next['admin_alert'] = [
        'on' => !empty($aa['on']) ? 1 : 0,
        'ads_thr' => max(1, (int)($aa['ads_thr'] ?? $cfg['admin_alert']['ads_thr'])),
        'req_thr' => max(1, (int)($aa['req_thr'] ?? $cfg['admin_alert']['req_thr'])),
        'debounce_h' => max(1, (int)($aa['debounce_h'] ?? $cfg['admin_alert']['debounce_h'])),
        'phone' => smsProgramNormPhone((string)($aa['phone'] ?? $cfg['admin_alert']['phone'])),
        'mode' => $aaMode,
        'every_n' => max(1, min(500, (int)($aa['every_n'] ?? $cfg['admin_alert']['every_n'] ?? 20))),
        'every_req_n' => max(1, min(500, (int)($aa['every_req_n'] ?? $cfg['admin_alert']['every_req_n'] ?? 0)) ?: (int)($cfg['admin_alert']['every_n'] ?? 20)),
        // شمارنده‌های حالت every دست‌نخورده — فقط خود موتور جلو می‌برد
        'last_ads' => (int)($cfg['admin_alert']['last_ads'] ?? 0),
        'last_reqs' => (int)($cfg['admin_alert']['last_reqs'] ?? 0),
        'template' => $cleanTpl($aa['template'] ?? '', $cfg['admin_alert']['template']),
    ];
    $mk = is_array($body['marketing'] ?? null) ? $body['marketing'] : [];
    $next['marketing'] = ['on' => !empty($mk['on']) ? 1 : 0];

    smsProgramSaveSettings($pdo, $next);
    melkinoAdminJson(['success' => true, 'message' => 'تنظیمات برنامهٔ پیامک ذخیره شد.']);
}

/* ---------------- tick (اجرای الان) ---------------- */
if ($action === 'tick') {
    try {
        $res = smsProgramTick($pdo, true);
        melkinoAdminJson(['success' => true, 'result' => $res]);
    } catch (Throwable $e) {
        melkinoAdminJson(['success' => false, 'message' => 'اجرای برنامه ناموفق بود.'], 500);
    }
}

/* ---------------- balance (اعتبار ملی‌پیامک) ---------------- */
if ($action === 'balance') {
    $sms = smsEffectiveSettings();
    if ((string)($sms['provider'] ?? '') !== 'melipayamak' || trim((string)($sms['api_key'] ?? '')) === '' || trim((string)($sms['password'] ?? '')) === '') {
        melkinoAdminJson(['success' => false, 'message' => 'نام کاربری/رمز پنل پیامک کامل تنظیم نشده است (تب ربات و کانال).'], 400);
    }
    $resp = melkinoHttpPost('https://rest.payamak-panel.com/api/SendSMS/GetBalance', http_build_query([
        'username' => (string)$sms['api_key'],
        'password' => (string)$sms['password'],
    ]));
    $j = is_string($resp) ? json_decode($resp, true) : null;
    if (is_array($j) && isset($j['RetStatus']) && (string)$j['RetStatus'] === '1') {
        melkinoAdminJson(['success' => true, 'credit' => (string)($j['Value'] ?? '')]);
    }
    $ret = is_array($j) ? (string)($j['RetStatus'] ?? '') : '';
    melkinoAdminJson(['success' => false, 'message' => $ret !== '' ? melipayamakErrorText($ret) : 'اتصال به سامانه برقرار نشد.'], 400);
}

/* ---------------- test ---------------- */
if ($action === 'test') {
    $phone = smsProgramNormPhone((string)($body['phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'شماره موبایل معتبر نیست.'], 422);
    }
    $cfg = smsProgramSettings($pdo);
    $lineId = trim((string)($body['line_id'] ?? ''));
    $from = '';
    if ($lineId !== '') {
        foreach (smsProgramNormLines($cfg['lines'] ?? []) as $l) {
            if ($l['id'] === $lineId) {
                $from = $l['number'];
            }
        }
        if ($from === '') {
            melkinoAdminJson(['success' => false, 'message' => 'خط انتخاب‌شده در فهرست خطوط نیست.'], 422);
        }
    } else {
        $pick = smsProgramResolveLine($cfg, smsEffectiveSettings(), 'manual');
        if ($pick['number'] === '') {
            melkinoAdminJson(['success' => false, 'message' => 'خط تست تعریف نشده: ' . $pick['reason']], 422);
        }
        $from = $pick['number'];
    }
    $res = smsSendText($phone, 'تست برنامهٔ پیامک ملکینو ✅ (خط ' . $from . ')', $from);
    melkinoAdminJson(['success' => !empty($res['success']), 'message' => (string)($res['message'] ?? ''), 'rec_id' => (string)($res['rec_id'] ?? '')], !empty($res['success']) ? 200 : 400);
}

/* ---------------- خطوط ارسال و مسیریابی ---------------- */
if ($action === 'lines_save') {
    $cfg = smsProgramSettings($pdo);
    $lines = smsProgramNormLines($body['lines'] ?? []);
    $ids = array_column($lines, 'id');
    $curRoutes = is_array($cfg['routes'] ?? null) ? $cfg['routes'] : [];
    $routes = [];
    foreach (array_keys(smsProgramPurposes()) as $p) {
        $rid = trim((string)($curRoutes[$p] ?? ''));
        $routes[$p] = in_array($rid, $ids, true) ? $rid : ''; // مسیریابی به خط حذف‌شده پاک می‌شود
    }
    smsProgramSaveSettings($pdo, ['lines' => $lines, 'routes' => $routes]);
    melkinoAdminJson(['success' => true, 'message' => 'خطوط ارسال ذخیره شد (' . count($lines) . ' خط).', 'lines' => $lines, 'routes' => $routes]);
}

if ($action === 'routes_save') {
    $cfg = smsProgramSettings($pdo);
    $ids = array_column(smsProgramNormLines($cfg['lines'] ?? []), 'id');
    $in = is_array($body['routes'] ?? null) ? $body['routes'] : [];
    $routes = [];
    foreach (array_keys(smsProgramPurposes()) as $p) {
        $rid = trim((string)($in[$p] ?? ''));
        if ($rid !== '' && !in_array($rid, $ids, true)) {
            melkinoAdminJson(['success' => false, 'message' => 'خط انتخاب‌شده برای «' . smsProgramPurposes()[$p] . '» در فهرست خطوط نیست.'], 422);
        }
        $routes[$p] = $rid;
    }
    smsProgramSaveSettings($pdo, ['routes' => $routes]);
    melkinoAdminJson(['success' => true, 'message' => 'مسیریابی پیامک‌ها ذخیره شد.', 'routes' => $routes]);
}

/* گزارش خود ملی‌پیامک (GetMessages) */
if ($action === 'provider_log') {
    $loc = (int)($body['location'] ?? -1);
    if (!in_array($loc, [1, 2, -1], true)) {
        $loc = -1;
    }
    $d = smsProviderMessages($loc, (string)($body['from'] ?? ''), 0, (int)($body['count'] ?? 30));
    melkinoAdminJson(['success' => $d['ok'], 'message' => $d['message'], 'rows' => $d['rows']], $d['ok'] ? 200 : 400);
}

/* وضعیت تحویل (دلیوری) — حداکثر ۲۰ پیامکِ ارسال‌شدهٔ ۳ روز اخیر در هر بار */
if ($action === 'delivery_refresh') {
    $st = $pdo->query("SELECT id, rec_id FROM sms_outbox WHERE status = 'sent' AND rec_id IS NOT NULL AND rec_id <> '' AND (delivery_status IS NULL OR delivery_status IN (0, 8)) AND sent_at >= (NOW() - INTERVAL 3 DAY) ORDER BY id DESC LIMIT 20");
    $checked = 0;
    $updated = 0;
    $errors = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $d = smsDeliveryStatus((string)$r['rec_id']);
        $checked++;
        if (empty($d['ok'])) {
            $errors[] = (string)$d['message'];
            if (count($errors) >= 3) {
                break; // احتمالاً گزارش تحویل غیرفعال است یا رمز اشتباه — ادامه نده
            }
            continue;
        }
        $pdo->prepare('UPDATE sms_outbox SET delivery_status = ?, delivery_checked_at = NOW() WHERE id = ?')
            ->execute([(int)$d['status'], (int)$r['id']]);
        $updated++;
    }
    $msg = 'بررسی شد: ' . $checked . ' — به‌روز شد: ' . $updated . '.';
    if ($errors) {
        $msg .= ' خطا: ' . $errors[0];
    }
    melkinoAdminJson(['success' => $updated > 0 || !$errors, 'message' => $msg, 'checked' => $checked, 'updated' => $updated]);
}

/* ---------------- outbox (گزارش) ---------------- */
if ($action === 'outbox') {
    $goal = trim((string)($body['goal'] ?? ''));
    $status = trim((string)($body['status'] ?? ''));
    $q = smsProgramNormPhone((string)($body['phone'] ?? ''));
    $where = ['1=1'];
    $params = [];
    if ($goal !== '' && $goal !== 'all') {
        $where[] = 'goal = ?';
        $params[] = $goal;
    }
    if ($status !== '' && $status !== 'all') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($q !== '') {
        $where[] = 'phone LIKE ?';
        $params[] = $q . '%';
    }
    $st = $pdo->prepare('SELECT * FROM sms_outbox WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 150');
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $meta = json_decode((string)$r['meta_json'], true) ?: [];
        $rows[] = [
            'id' => (int)$r['id'],
            'goal' => (string)$r['goal'],
            'phone' => (string)$r['phone'],
            'status' => (string)$r['status'],
            'fail_reason' => (string)($r['fail_reason'] ?? ''),
            'rec_id' => (string)($r['rec_id'] ?? ''),
            'line' => (string)($r['line'] ?? ''),
            'delivery' => isset($r['delivery_status']) && $r['delivery_status'] !== null ? (int)$r['delivery_status'] : null,
            'ad_id' => (string)($meta['ad_id'] ?? ''),
            'request_id' => (int)($meta['request_id'] ?? 0),
            'search_id' => (int)($meta['search_id'] ?? 0),
            'campaign_id' => (int)($meta['campaign_id'] ?? 0),
            'alert' => (string)($meta['alert'] ?? ''),
            'body' => mb_substr(trim(str_replace("\n", ' | ', (string)$r['body'])), 0, 90),
            'created_at' => (string)$r['created_at'],
            'sent_at' => (string)($r['sent_at'] ?? ''),
        ];
    }
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

/* ---------------- optouts ---------------- */
if ($action === 'optout_add') {
    $phone = smsProgramNormPhone((string)($body['phone'] ?? ''));
    $scope = (string)($body['scope'] ?? 'all');
    if (!in_array($scope, ['alerts', 'promo', 'all'], true)) {
        $scope = 'all';
    }
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'شماره موبایل معتبر نیست.'], 422);
    }
    $st = $pdo->prepare('INSERT IGNORE INTO sms_optouts (phone, scope, source) VALUES (?,?,?)');
    $st->execute([$phone, $scope, 'admin']);
    melkinoAdminJson(['success' => true, 'message' => 'شماره به فهرست لغو عضویت اضافه شد.']);
}
if ($action === 'optout_del') {
    $id = (int)($body['id'] ?? 0);
    $pdo->prepare('DELETE FROM sms_optouts WHERE id = ?')->execute([$id]);
    melkinoAdminJson(['success' => true]);
}
if ($action === 'optouts') {
    $rows = $pdo->query('SELECT * FROM sms_optouts ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

/* ---------------- saved searches (مدیریت) ---------------- */
if ($action === 'searches') {
    $rows = $pdo->query('SELECT s.*, u.name AS user_name FROM saved_searches s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}
if ($action === 'search_del') {
    $id = (int)($body['id'] ?? 0);
    $pdo->prepare('DELETE FROM saved_searches WHERE id = ?')->execute([$id]);
    melkinoAdminJson(['success' => true]);
}

melkinoAdminJson(['success' => false, 'message' => 'اکشن نامعتبر است.'], 404);
