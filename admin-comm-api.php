<?php require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-guard.php';
if (is_file(__DIR__ . '/db_helpers.php')) {
    require_once __DIR__ . '/db_helpers.php';
}
if (is_file(__DIR__ . '/sms.php')) {
    require_once __DIR__ . '/sms.php';
}
require_once __DIR__ . '/admin-comm-lib.php';
if (is_file(__DIR__ . '/melkino-xlsx.php')) {
    require_once __DIR__ . '/melkino-xlsx.php';
}

melkinoRequireAdminJson();
global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'دیتابیس در دسترس نیست.'], 500);
}
commEnsureSchema($pdo);

$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = function_exists('melkinoAdminJsonBody') ? melkinoAdminJsonBody() : [];
if (!is_array($body)) {
    $body = [];
}
if ($action === '' && isset($body['action'])) {
    $action = (string)$body['action'];
}

if ($action === 'dashboard') {
    $need = (int)$pdo->query('SELECT COUNT(*) FROM comm_contacts')->fetchColumn();
    if ($need === 0) {
        commSyncContacts($pdo);
    }
    $kpis = commKpis($pdo);
    $today = commToday($pdo);
    $recent = commTry($pdo, 'SELECT id, phone, success, LEFT(body,80) preview, created_at FROM comm_messages ORDER BY id DESC LIMIT 12');
    $fu = commTry($pdo, 'SELECT f.*, c.name, c.phone FROM comm_followups f LEFT JOIN comm_contacts c ON c.id=f.contact_id WHERE f.status=\'open\' ORDER BY f.due_date IS NULL, f.due_date ASC LIMIT 12');
    $hot = commTry($pdo, 'SELECT id, phone, name, last_name, roles_suggested, views_n, requests_n, last_activity FROM comm_contacts WHERE views_n>=5 ORDER BY views_n DESC LIMIT 8');
    $attention = [];
    foreach (array_slice($hot, 0, 3) as $h) {
        $attention[] = [
            'kind' => 'hot',
            'id' => (int)$h['id'],
            'title' => trim((string)($h['name'] ?: $h['last_name'] ?: $h['phone'])),
            'text' => ((int)$h['views_n']) . ' بار آگهی دیده — نقش پیشنهادی: ' . (string)($h['roles_suggested'] ?: 'نامشخص'),
        ];
    }
    if ($today['requests'] > 0) {
        $attention[] = ['kind' => 'requests', 'title' => $today['requests'] . ' درخواست جدید امروز', 'text' => 'از جدول درخواست ملک'];
    }
    if ($today['visits_tomorrow'] > 0) {
        $attention[] = ['kind' => 'visits', 'title' => $today['visits_tomorrow'] . ' بازدید فردا', 'text' => 'یادآوری در اتوماسیون قابل تنظیم است'];
    }
    if ($today['deferred'] > 0) {
        $attention[] = ['kind' => 'deferred', 'title' => $today['deferred'] . ' پیام منتظر زمان مناسب', 'text' => 'ساعات سکوت یا ارسال زمان‌بندی‌شده'];
    }
    $sms = function_exists('smsEffectiveSettings') ? smsEffectiveSettings() : [];
    melkinoAdminJson([
        'success' => true,
        'kpis' => $kpis,
        'today' => $today,
        'recent' => $recent,
        'followups' => $fu,
        'hot' => $hot,
        'attention' => array_slice($attention, 0, 5),
        'sms_on' => !empty($sms['enabled']),
    ]);
}

if ($action === 'sync' && $method === 'POST') {
    $n = commSyncContacts($pdo);
    melkinoAdminJson(['success' => true, 'synced' => $n, 'kpis' => commKpis($pdo)]);
}

if ($action === 'contacts') {
    [$where, $params] = commWhere($_GET);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 40;
    $st = $pdo->prepare('SELECT COUNT(*) FROM comm_contacts WHERE ' . $where);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $sql = 'SELECT * FROM comm_contacts WHERE ' . $where . ' ORDER BY last_activity DESC, id DESC LIMIT ' . (int)$per . ' OFFSET ' . (($page - 1) * $per);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = commEnrichMessenger($pdo, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    melkinoAdminJson(['success' => true, 'total' => $total, 'page' => $page, 'rows' => $rows]);
}

if ($action === 'contact') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM comm_contacts WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        melkinoAdminJson(['success' => false, 'message' => 'مخاطب پیدا نشد.'], 404);
    }
    $phone = (string)$c['phone'];
    $ads = commTry($pdo, 'SELECT id, title, status, transaction_type, location, created_at FROM ads WHERE phone=? ORDER BY id DESC LIMIT 30', [$phone]);
    $reqs = commTry($pdo, 'SELECT id, tracking_code, status, transaction_type, property_type, location, created_at FROM property_requests WHERE phone=? ORDER BY id DESC LIMIT 30', [$phone]);
    $visits = commTry($pdo, 'SELECT id, ad_id, ad_title, status, preferred_date_fa, created_at FROM visit_requests WHERE phone=? ORDER BY id DESC LIMIT 20', [$phone]);
    $sms = commTry($pdo, 'SELECT id, success, body, created_at FROM comm_messages WHERE phone=? ORDER BY id DESC LIMIT 20', [$phone]);
    $notes = commTry($pdo, 'SELECT * FROM comm_notes WHERE contact_id=? ORDER BY id DESC LIMIT 20', [$id]);
    $fu = commTry($pdo, 'SELECT * FROM comm_followups WHERE contact_id=? ORDER BY id DESC LIMIT 20', [$id]);
    $c = commEnrichMessenger($pdo, [$c])[0];
    melkinoAdminJson(['success' => true, 'contact' => $c, 'ads' => $ads, 'requests' => $reqs, 'visits' => $visits, 'sms' => $sms, 'notes' => $notes, 'followups' => $fu]);
}

if ($action === 'save_contact' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $phone = commNormPhone((string)($body['phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'موبایل نامعتبر است.'], 400);
    }
    $name = trim((string)($body['name'] ?? ''));
    $roles = trim((string)($body['roles_verified'] ?? ''));
    $allowedStatus = ['active', 'inactive', 'blocked', 'archived'];
    $statusRaw = $body['status'] ?? 'active';
    if (!is_string($statusRaw) || $statusRaw === '') {
        $statusRaw = 'active';
    }
    $status = in_array($statusRaw, $allowedStatus, true) ? $statusRaw : 'active';
    $lastName = trim((string)($body['last_name'] ?? '')) ?: null;
    try {
        if ($id > 0) {
            $pdo->prepare('UPDATE comm_contacts SET phone=?, name=?, last_name=?, roles_verified=?, status=?, updated_at=NOW() WHERE id=?')
                ->execute([$phone, $name !== '' ? $name : null, $lastName, $roles !== '' ? $roles : null, $status, $id]);
        } else {
            $pdo->prepare('INSERT INTO comm_contacts(phone,name,last_name,roles_verified,roles_suggested,status,source,updated_at) VALUES (?,?,?,?,?,?,\'manual\',NOW())')
                ->execute([$phone, $name !== '' ? $name : null, $lastName, $roles !== '' ? $roles : null, $roles !== '' ? $roles : null, $status]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        $sqlState = (string) $e->getCode();
        $msg = $e->getMessage();
        if ($sqlState === '23000' || stripos($msg, 'Duplicate') !== false || stripos($msg, 'uq_phone') !== false) {
            melkinoAdminJson(['success' => false, 'message' => 'این شماره قبلاً در دفترچه تلفن ثبت شده است.'], 409);
        }
        error_log('[melkino] save_contact failed: ' . $msg);
        melkinoAdminJson(['success' => false, 'message' => 'ذخیره مخاطب انجام نشد.'], 500);
    }
    melkinoAdminJson(['success' => true, 'id' => $id]);
}

if ($action === 'note' && $method === 'POST') {
    $cid = (int)($body['contact_id'] ?? 0);
    $txt = trim((string)($body['body'] ?? ''));
    if ($cid <= 0 || $txt === '') {
        melkinoAdminJson(['success' => false, 'message' => 'یادداشت خالی است.'], 400);
    }
    $pdo->prepare('INSERT INTO comm_notes(contact_id, body) VALUES (?,?)')->execute([$cid, $txt]);
    melkinoAdminJson(['success' => true]);
}

if ($action === 'followup' && $method === 'POST') {
    $cid = (int)($body['contact_id'] ?? 0);
    $title = trim((string)($body['title'] ?? ''));
    if ($cid <= 0 || $title === '') {
        melkinoAdminJson(['success' => false, 'message' => 'عنوان پیگیری لازم است.'], 400);
    }
    $pdo->prepare('INSERT INTO comm_followups(contact_id,title,due_date,priority,notes,related_ad,related_request) VALUES (?,?,?,?,?,?,?)')
        ->execute([
            $cid, $title, trim((string)($body['due_date'] ?? '')) ?: null,
            in_array($body['priority'] ?? '', ['HIGH', 'MEDIUM', 'LOW'], true) ? $body['priority'] : 'MEDIUM',
            trim((string)($body['notes'] ?? '')) ?: null,
            trim((string)($body['related_ad'] ?? '')) ?: null,
            (int)($body['related_request'] ?? 0) ?: null,
        ]);
    melkinoAdminJson(['success' => true]);
}

if ($action === 'templates') {
    melkinoAdminJson(['success' => true, 'rows' => commTry($pdo, 'SELECT * FROM comm_templates ORDER BY id DESC')]);
}
if ($action === 'save_template' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $name = trim((string)($body['name'] ?? ''));
    $txt = trim((string)($body['body'] ?? ''));
    if ($name === '' || $txt === '') {
        melkinoAdminJson(['success' => false, 'message' => 'نام و متن قالب لازم است.'], 400);
    }
    if ($id > 0) {
        $pdo->prepare('UPDATE comm_templates SET name=?, body=? WHERE id=?')->execute([$name, $txt, $id]);
    } else {
        $pdo->prepare('INSERT INTO comm_templates(name, body) VALUES (?,?)')->execute([$name, $txt]);
        $id = (int)$pdo->lastInsertId();
    }
    melkinoAdminJson(['success' => true, 'id' => $id]);
}
if ($action === 'delete_template' && $method === 'POST') {
    $pdo->prepare('DELETE FROM comm_templates WHERE id=?')->execute([(int)($body['id'] ?? 0)]);
    melkinoAdminJson(['success' => true]);
}

if ($action === 'send' && $method === 'POST') {
    $text = trim((string)($body['body'] ?? ''));
    $ids = $body['contact_ids'] ?? [];
    $phones = $body['phones'] ?? [];
    if ($text === '') {
        melkinoAdminJson(['success' => false, 'message' => 'متن پیام خالی است.'], 400);
    }
    $targets = [];
    $crit = $body['criteria'] ?? null;
    if (is_array($crit) && $crit) {
        [$where, $params] = commWhere($crit);
        $st = $pdo->prepare('SELECT * FROM comm_contacts WHERE status=\'active\' AND ' . $where . ' LIMIT 200');
        $st->execute($params);
        $targets = array_merge($targets, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    if (is_array($ids)) {
        foreach ($ids as $id) {
            $st = $pdo->prepare('SELECT * FROM comm_contacts WHERE id=? LIMIT 1');
            $st->execute([(int)$id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $targets[] = $c;
            }
        }
    }
    if (is_array($phones)) {
        foreach ($phones as $ph) {
            $ph = commNormPhone((string)$ph);
            $st = $pdo->prepare('SELECT * FROM comm_contacts WHERE phone=? LIMIT 1');
            $st->execute([$ph]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            $targets[] = $c ?: ['phone' => $ph, 'id' => null, 'name' => '', 'first_name' => '', 'last_name' => ''];
        }
    }
    if (!$targets) {
        melkinoAdminJson(['success' => false, 'message' => 'مخاطبی انتخاب نشده.'], 400);
    }
    if (count($targets) > 200) {
        melkinoAdminJson(['success' => false, 'message' => 'سقف ارسال گروهی ۲۰۰ نفر است.'], 400);
    }
    $ok = 0;
    $fail = 0;
    $def = 0;
    $results = [];
    $tplId = (int)($body['template_id'] ?? 0) ?: null;
    $when = (string)($body['when'] ?? 'now');
    $sendAt = trim((string)($body['send_at'] ?? ''));
    foreach ($targets as $c) {
        $msg = commFillVars($text, $c);
        if ($when === 'later' && $sendAt !== '') {
            $pdo->prepare('INSERT INTO comm_deferred(phone,body,contact_id,status,send_after) VALUES (?,?,?,?,?)')
                ->execute([(string)$c['phone'], $msg, isset($c['id']) ? (int)$c['id'] : null, 'WAITING', $sendAt]);
            $def++;
            continue;
        }
        $r = commSendOne($pdo, (string)$c['phone'], $msg, isset($c['id']) ? (int)$c['id'] : null, null, $tplId);
        $results[] = $r;
        if (!empty($r['deferred'])) {
            $def++;
        } elseif (!empty($r['success'])) {
            $ok++;
        } else {
            $fail++;
        }
    }
    melkinoAdminJson(['success' => ($ok + $def) > 0, 'ok' => $ok, 'fail' => $fail, 'deferred' => $def, 'results' => $results, 'meta' => commSmsParts($text)]);
}

if ($action === 'logs') {
    $rows = commTry($pdo, 'SELECT * FROM comm_messages ORDER BY id DESC LIMIT 200');
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

if ($action === 'export') {
    [$where, $params] = commWhere($_GET);
    $st = $pdo->prepare('SELECT phone, name, last_name, user_id, roles_suggested, roles_verified, status, district, properties_n, requests_n, visits_n, views_n, last_activity, last_sms_at, source, telegram_id, bale_id, telegram_username, bale_username FROM comm_contacts WHERE ' . $where . ' ORDER BY id DESC LIMIT 5000');
    $st->execute($params);
    $rows = commEnrichMessenger($pdo, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $headers = ['موبایل', 'نام', 'نام خانوادگی', 'آیدی تلگرام', 'لینک تلگرام', 'آیدی بله', 'لینک بله', 'نقش پیشنهادی', 'نقش تأییدشده', 'وضعیت', 'منطقه', 'املاک', 'درخواست', 'بازدید', 'مشاهده آگهی', 'آخرین فعالیت', 'آخرین پیامک', 'منبع'];
    $out = [];
    foreach ($rows as $r) {
        $tgId = trim((string)($r['telegram_id'] ?? ''));
        $baleId = trim((string)($r['bale_id'] ?? ''));
        $out[] = [
            "'" . $r['phone'], $r['name'], $r['last_name'],
            $tgId !== '' ? "'" . $tgId : '',
            (string)($r['telegram_link'] ?? ''),
            $baleId !== '' ? "'" . $baleId : '',
            (string)($r['bale_link'] ?? ''),
            $r['roles_suggested'], $r['roles_verified'],
            $r['status'], $r['district'], $r['properties_n'], $r['requests_n'], $r['visits_n'], $r['views_n'],
            $r['last_activity'], $r['last_sms_at'], $r['source'],
        ];
    }
    $dir = sys_get_temp_dir();
    $file = $dir . '/melkino_contacts_' . date('Y-m-d_His') . '.xlsx';
    if (!function_exists('melkinoXlsxWrite') || !melkinoXlsxWrite($file, $headers, $out, 'مخاطبین')) {
        melkinoAdminJson(['success' => false, 'message' => 'ساخت اکسل ممکن نشد.'], 500);
    }
    $pdo->prepare('INSERT INTO comm_export_log(scope, rows_n, format) VALUES (?,?,?)')->execute(['filter', count($out), 'xlsx']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="melkino_contacts_' . date('Y-m-d') . '.xlsx"');
    readfile($file);
    @unlink($file);
    exit;
}

if ($action === 'settings') {
    if ($method === 'POST') {
        commSet($pdo, 'quiet_enabled', !empty($body['quiet_enabled']) ? '1' : '0');
        commSet($pdo, 'quiet_start', preg_match('/^\d{2}:\d{2}$/', (string)($body['quiet_start'] ?? '')) ? $body['quiet_start'] : '22:00');
        commSet($pdo, 'quiet_end', preg_match('/^\d{2}:\d{2}$/', (string)($body['quiet_end'] ?? '')) ? $body['quiet_end'] : '08:00');
        commSet($pdo, 'max_sms_day', (string)max(1, min(30, (int)($body['max_sms_day'] ?? 8))));
        if (isset($body['admin_phone'])) {
            commSet($pdo, 'admin_phone', commNormPhone((string)$body['admin_phone']));
        }
        if (isset($body['urgent_bypass'])) {
            commSet($pdo, 'urgent_bypass', !empty($body['urgent_bypass']) ? '1' : '0');
        }
        commAudit($pdo, 'settings', 'quiet/max updated');
    }
    $sms = function_exists('smsEffectiveSettings') ? smsEffectiveSettings() : [];
    melkinoAdminJson([
        'success' => true,
        'quiet_enabled' => commSetting($pdo, 'quiet_enabled', '0'),
        'quiet_start' => commSetting($pdo, 'quiet_start', '22:00'),
        'quiet_end' => commSetting($pdo, 'quiet_end', '08:00'),
        'max_sms_day' => commSetting($pdo, 'max_sms_day', '8'),
        'admin_phone' => commSetting($pdo, 'admin_phone', ''),
        'urgent_bypass' => commSetting($pdo, 'urgent_bypass', '0'),
        'quiet_now' => commQuietNow($pdo),
        'provider' => [
            'enabled' => !empty($sms['enabled']),
            'url' => (string)($sms['api_url'] ?? ''),
            'sender' => (string)($sms['sender_line'] ?? ''),
        ],
    ]);
}

if ($action === 'segments') {
    if ($method === 'POST') {
        $name = trim((string)($body['name'] ?? ''));
        $crit = json_encode($body['criteria'] ?? [], JSON_UNESCAPED_UNICODE);
        if ($name === '') {
            melkinoAdminJson(['success' => false, 'message' => 'نام سگمنت لازم است.'], 400);
        }
        $pdo->prepare('INSERT INTO comm_segments(name, criteria) VALUES (?,?)')->execute([$name, $crit]);
        commAudit($pdo, 'segment_create', $name);
        melkinoAdminJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
    $rows = commTry($pdo, 'SELECT * FROM comm_segments ORDER BY id DESC');
    foreach ($rows as &$r) {
        $crit = json_decode((string)($r['criteria'] ?? '{}'), true) ?: [];
        [$where, $params] = commWhere($crit);
        $st = $pdo->prepare('SELECT COUNT(*) FROM comm_contacts WHERE ' . $where);
        $st->execute($params);
        $r['count'] = (int)$st->fetchColumn();
        $r['criteria'] = $crit;
    }
    unset($r);
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

if ($action === 'campaigns') {
    if ($method === 'POST') {
        $name = trim((string)($body['name'] ?? ''));
        $text = trim((string)($body['body'] ?? ''));
        if ($name === '' || $text === '') {
            melkinoAdminJson(['success' => false, 'message' => 'نام و متن کمپین لازم است.'], 400);
        }
        $seg = (int)($body['segment_id'] ?? 0) ?: null;
        // زمان‌بندی ارسال (اختیاری) — اجرا توسط برنامهٔ پیامک (sms-program.php)
        $sched = trim((string)($body['scheduled_at'] ?? ''));
        $sched = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $sched)
            ? str_replace('T', ' ', substr($sched, 0, 16))
            : null;
        if ($sched !== null) {
            $pdo->prepare('INSERT INTO comm_campaigns(name,body,segment_id,status,scheduled_at) VALUES (?,?,?,\'scheduled\',?)')->execute([$name, $text, $seg, $sched]);
        } else {
            $pdo->prepare('INSERT INTO comm_campaigns(name,body,segment_id,status) VALUES (?,?,?,\'draft\')')->execute([$name, $text, $seg]);
        }
        commAudit($pdo, 'campaign_create', $name . ($sched !== null ? ' @' . $sched : ''));
        melkinoAdminJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
    melkinoAdminJson(['success' => true, 'rows' => commTry($pdo, 'SELECT * FROM comm_campaigns ORDER BY id DESC LIMIT 80')]);
}

if ($action === 'run_campaign' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM comm_campaigns WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        melkinoAdminJson(['success' => false, 'message' => 'کمپین پیدا نشد.'], 404);
    }
    $crit = [];
    if (!empty($c['segment_id'])) {
        $s = commTry($pdo, 'SELECT criteria FROM comm_segments WHERE id=?', [(int)$c['segment_id']]);
        $crit = json_decode((string)($s[0]['criteria'] ?? '{}'), true) ?: [];
    }
    [$where, $params] = commWhere($crit);
    $st = $pdo->prepare('SELECT * FROM comm_contacts WHERE status=\'active\' AND ' . $where . ' LIMIT 200');
    $st->execute($params);
    $people = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ok = 0;
    $fail = 0;
    $def = 0;
    foreach ($people as $p) {
        $msg = commFillVars((string)$c['body'], $p);
        $r = commSendOne($pdo, (string)$p['phone'], $msg, (int)$p['id'], $id, null);
        if (!empty($r['deferred'])) {
            $def++;
        } elseif (!empty($r['success'])) {
            $ok++;
        } else {
            $fail++;
        }
    }
    $pdo->prepare('UPDATE comm_campaigns SET status=?, recipients_n=?, sent_n=?, fail_n=?, sent_at=NOW() WHERE id=?')
        ->execute([$ok || $def ? 'completed' : 'failed', count($people), $ok, $fail, $id]);
    commAudit($pdo, 'campaign_run', 'id=' . $id . ' ok=' . $ok);
    melkinoAdminJson(['success' => true, 'ok' => $ok, 'fail' => $fail, 'deferred' => $def, 'recipients' => count($people)]);
}

if ($action === 'flush_deferred' && $method === 'POST') {
    if (commQuietNow($pdo)) {
        melkinoAdminJson(['success' => false, 'message' => 'هنوز ساعات سکوت است.'], 400);
    }
    $rows = commTry($pdo, "SELECT * FROM comm_deferred WHERE status='WAITING' AND (send_after IS NULL OR send_after<=NOW()) ORDER BY id ASC LIMIT 100");
    $ok = 0;
    $fail = 0;
    foreach ($rows as $d) {
        $GLOBALS['COMM_URGENT'] = true;
        $r = commSendOne($pdo, (string)$d['phone'], (string)$d['body'], $d['contact_id'] ? (int)$d['contact_id'] : null, $d['campaign_id'] ? (int)$d['campaign_id'] : null, null);
        unset($GLOBALS['COMM_URGENT']);
        $pdo->prepare('UPDATE comm_deferred SET status=? WHERE id=?')->execute([!empty($r['success']) ? 'SENT' : 'FAILED', (int)$d['id']]);
        if (!empty($r['success'])) {
            $ok++;
        } else {
            $fail++;
        }
    }
    melkinoAdminJson(['success' => true, 'ok' => $ok, 'fail' => $fail, 'n' => count($rows)]);
}

if ($action === 'tags') {
    if ($method === 'POST') {
        $name = trim((string)($body['name'] ?? ''));
        $cid = (int)($body['contact_id'] ?? 0);
        if ($name === '' || $cid <= 0) {
            melkinoAdminJson(['success' => false, 'message' => 'تگ و مخاطب لازم است.'], 400);
        }
        $pdo->prepare('INSERT IGNORE INTO comm_tags(name) VALUES (?)')->execute([$name]);
        $tid = (int)$pdo->query('SELECT id FROM comm_tags WHERE name=' . $pdo->quote($name) . ' LIMIT 1')->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO comm_contact_tags(contact_id, tag_id) VALUES (?,?)')->execute([$cid, $tid]);
        melkinoAdminJson(['success' => true]);
    }
    melkinoAdminJson(['success' => true, 'rows' => commTry($pdo, 'SELECT t.name, COUNT(ct.contact_id) n FROM comm_tags t LEFT JOIN comm_contact_tags ct ON ct.tag_id=t.id GROUP BY t.id, t.name ORDER BY n DESC')]);
}

if ($action === 'clicks') {
    $rows = commTry($pdo, 'SELECT c.clicked_at, t.short_code, t.destination_url, t.contact_id FROM comm_clicks c INNER JOIN comm_tracking_links t ON t.id=c.tracking_id ORDER BY c.id DESC LIMIT 100');
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

if ($action === 'audit') {
    melkinoAdminJson(['success' => true, 'rows' => commTry($pdo, 'SELECT * FROM comm_audit ORDER BY id DESC LIMIT 100')]);
}

if ($action === 'import' && $method === 'POST') {
    $raw = (string)($body['csv'] ?? '');
    $mode = (string)($body['mode'] ?? 'UPDATE_OR_CREATE');
    $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
    if (count($lines) < 2) {
        melkinoAdminJson(['success' => false, 'message' => 'فایل خالی است. ستون‌ها: phone,name,last_name,role'], 400);
    }
    $head = str_getcsv(array_shift($lines));
    $map = [];
    foreach ($head as $i => $h) {
        $map[strtolower(trim((string)$h))] = $i;
    }
    $created = 0;
    $updated = 0;
    $invalid = 0;
    $skip = 0;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cols = str_getcsv($line);
        $phone = commNormPhone((string)($cols[$map['phone'] ?? $map['mobile'] ?? 0] ?? ''));
        if (!preg_match('/^09\d{9}$/', $phone)) {
            $invalid++;
            continue;
        }
        $name = trim((string)($cols[$map['name'] ?? 1] ?? ''));
        $last = trim((string)($cols[$map['last_name'] ?? 2] ?? ''));
        $role = trim((string)($cols[$map['role'] ?? 3] ?? ''));
        $ex = commTry($pdo, 'SELECT id FROM comm_contacts WHERE phone=? LIMIT 1', [$phone]);
        if ($ex && $mode === 'CREATE_ONLY') {
            $skip++;
            continue;
        }
        if ($ex) {
            $pdo->prepare('UPDATE comm_contacts SET name=COALESCE(NULLIF(?,\'\'),name), last_name=COALESCE(NULLIF(?,\'\'),last_name), roles_verified=COALESCE(NULLIF(?,\'\'),roles_verified), source=\'import\', updated_at=NOW() WHERE id=?')
                ->execute([$name, $last, $role, (int)$ex[0]['id']]);
            $updated++;
        } else {
            $pdo->prepare('INSERT INTO comm_contacts(phone,name,last_name,roles_verified,roles_suggested,status,source,updated_at) VALUES (?,?,?,?,?,\'active\',\'import\',NOW())')
                ->execute([$phone, $name ?: null, $last ?: null, $role ?: null, $role ?: null]);
            $created++;
        }
    }
    commAudit($pdo, 'import', 'c=' . $created . ' u=' . $updated);
    melkinoAdminJson(['success' => true, 'created' => $created, 'updated' => $updated, 'invalid' => $invalid, 'skipped' => $skip]);
}

if ($action === 'audience') {
    $crit = $_GET;
    if ($method === 'POST' && is_array($body['criteria'] ?? null)) {
        $crit = $body['criteria'];
    }
    [$where, $params] = commWhere($crit);
    $st = $pdo->prepare('SELECT COUNT(*) FROM comm_contacts WHERE status=\'active\' AND ' . $where);
    $st->execute($params);
    $n = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT id, phone, name, last_name, roles_suggested FROM comm_contacts WHERE status=\'active\' AND ' . $where . ' ORDER BY last_activity DESC LIMIT 8');
    $st->execute($params);
    melkinoAdminJson(['success' => true, 'count' => $n, 'sample' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($action === 'suggest_text' && $method === 'POST') {
    $q = trim((string)($body['prompt'] ?? ''));
    $text = 'ملکینو: سلام {{first_name}}، برای پیگیری پرونده‌تان این پیام را می‌فرستیم. {{link}}';
    if (mb_strpos($q, 'بازدید') !== false) {
        $text = 'ملکینو: یادآوری بازدید «{{property_title}}» در {{visit_date}}. لطفاً اگر برنامه عوض شد هماهنگ کنید.';
    } elseif (mb_strpos($q, 'درخواست') !== false) {
        $text = 'ملکینو: درخواست شما ثبت شد. به‌محض فایل مناسب خبر می‌دهیم.';
    } elseif (mb_strpos($q, 'آگهی') !== false || mb_strpos($q, 'ملک') !== false) {
        $text = 'ملکینو: آگهی «{{property_title}}» را دیده‌اید. برای هماهنگی بازدید پیام دهید.';
    } elseif (mb_strpos($q, 'خرید') !== false) {
        $text = 'ملکینو: سلام {{first_name}}، چند فایل مناسب درخواست خرید شما آماده است. مشاهده: {{link}}';
    }
    melkinoAdminJson(['success' => true, 'text' => $text, 'note' => 'پیشنهاد متن است؛ بدون تأیید شما ارسال نمی‌شود.']);
}

if ($action === 'automations') {
    if ($method === 'POST') {
        $title = trim((string)($body['title'] ?? ''));
        $event = trim((string)($body['event_key'] ?? ''));
        $msg = trim((string)($body['body'] ?? ''));
        if ($title === '' || $event === '' || $msg === '') {
            melkinoAdminJson(['success' => false, 'message' => 'عنوان، رویداد و متن لازم است.'], 400);
        }
        $pdo->prepare('INSERT INTO comm_automations(title,event_key,recipient_type,recipient_phone,body,timing,interval_min,min_count,enabled) VALUES (?,?,?,?,?,?,?,?,1)')
            ->execute([
                $title,
                $event,
                (string)($body['recipient_type'] ?? 'admin'),
                commNormPhone((string)($body['recipient_phone'] ?? commSetting($pdo, 'admin_phone', ''))),
                $msg,
                (string)($body['timing'] ?? 'digest'),
                max(1, (int)($body['interval_min'] ?? 30)),
                max(1, (int)($body['min_count'] ?? 1)),
            ]);
        commAudit($pdo, 'automation_create', $title);
        melkinoAdminJson(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
    melkinoAdminJson(['success' => true, 'rows' => commTry($pdo, 'SELECT * FROM comm_automations ORDER BY id DESC')]);
}

if ($action === 'toggle_auto' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $on = !empty($body['enabled']) ? 1 : 0;
    $pdo->prepare('UPDATE comm_automations SET enabled=? WHERE id=?')->execute([$on, $id]);
    melkinoAdminJson(['success' => true]);
}

if ($action === 'run_auto' && $method === 'POST') {
    $id = (int)($body['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM comm_automations WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        melkinoAdminJson(['success' => false, 'message' => 'اعلان پیدا نشد.'], 404);
    }
    $phone = commNormPhone((string)($a['recipient_phone'] ?: commSetting($pdo, 'admin_phone', '')));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'شماره مدیر در تنظیمات ثبت نشده.'], 400);
    }
    $today = commToday($pdo);
    $map = [
        'new_ad' => (int)$today['ads'],
        'new_request' => (int)$today['requests'],
        'visit' => (int)$today['visits'],
        'hot_lead' => (int)$today['hot'],
        'visit_remind' => (int)$today['visits_tomorrow'],
    ];
    $n = $map[(string)$a['event_key']] ?? 0;
    if ($n < (int)$a['min_count']) {
        melkinoAdminJson(['success' => true, 'skipped' => true, 'count' => $n, 'message' => 'تعداد امروز به حد نصاب نرسید. حدس زده نشد.']);
    }
    $msg = str_replace(['{{count}}', '{{event}}'], [(string)$n, (string)$a['title']], (string)$a['body']);
    $GLOBALS['COMM_URGENT'] = ((string)$a['timing'] === 'now');
    $r = commSendOne($pdo, $phone, $msg, null, null, null);
    unset($GLOBALS['COMM_URGENT']);
    $pdo->prepare('UPDATE comm_automations SET last_run_at=NOW() WHERE id=?')->execute([$id]);
    melkinoAdminJson(['success' => !empty($r['success']), 'result' => $r, 'count' => $n]);
}

if ($action === 'reports') {
    $smsFail = commCnt($pdo, "SELECT COUNT(*) FROM comm_messages WHERE created_at>=CURDATE() AND success=0");
    melkinoAdminJson([
        'success' => true,
        'sms' => [
            'today' => commCnt($pdo, "SELECT COUNT(*) FROM comm_messages WHERE created_at>=CURDATE()"),
            'ok' => commCnt($pdo, "SELECT COUNT(*) FROM comm_messages WHERE created_at>=CURDATE() AND success=1"),
            'fail' => $smsFail,
            'waiting' => commCnt($pdo, "SELECT COUNT(*) FROM comm_deferred WHERE status='WAITING'"),
        ],
        'rel' => [
            'clicks' => commCnt($pdo, "SELECT COUNT(*) FROM comm_clicks WHERE clicked_at>=CURDATE()"),
            'visits' => commCnt($pdo, "SELECT COUNT(*) FROM visit_requests WHERE created_at>=CURDATE()"),
            'requests' => commCnt($pdo, "SELECT COUNT(*) FROM property_requests WHERE created_at>=CURDATE()"),
        ],
        'work' => [
            'campaigns' => commCnt($pdo, 'SELECT COUNT(*) FROM comm_campaigns'),
            'autos' => commCnt($pdo, 'SELECT COUNT(*) FROM comm_automations'),
            'templates' => commCnt($pdo, 'SELECT COUNT(*) FROM comm_templates'),
        ],
        'clicks' => commTry($pdo, 'SELECT c.clicked_at, t.short_code, t.destination_url FROM comm_clicks c INNER JOIN comm_tracking_links t ON t.id=c.tracking_id ORDER BY c.id DESC LIMIT 40'),
    ]);
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') {
        melkinoAdminJson(['success' => true, 'contacts' => [], 'followups' => []]);
    }
    $like = '%' . $q . '%';
    $st = $pdo->prepare('SELECT id, phone, name, last_name, roles_suggested FROM comm_contacts WHERE phone LIKE ? OR name LIKE ? OR last_name LIKE ? ORDER BY last_activity DESC LIMIT 8');
    $st->execute([$like, $like, $like]);
    melkinoAdminJson(['success' => true, 'contacts' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
