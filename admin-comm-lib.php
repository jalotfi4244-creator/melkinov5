<?php
/** مرکز ارتباطات ملکینو — اسکیما، دفترچه تلفن، ارسال */

function commNormPhone(string $phone): string
{
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $d = preg_replace('/\D+/', '', strtr($phone, $map)) ?? '';
    if (substr($d, 0, 2) === '98' && strlen($d) >= 12) {
        $d = '0' . substr($d, 2);
    }
    if (strlen($d) === 10 && substr($d, 0, 1) === '9') {
        $d = '0' . $d;
    }
    return $d;
}

function commTry(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function commEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_contacts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        user_id INT NULL,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(120) NULL,
        name VARCHAR(160) NULL,
        roles_suggested VARCHAR(255) NULL,
        roles_verified VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        source VARCHAR(40) NULL,
        properties_n INT NOT NULL DEFAULT 0,
        requests_n INT NOT NULL DEFAULT 0,
        visits_n INT NOT NULL DEFAULT 0,
        views_n INT NOT NULL DEFAULT 0,
        last_activity DATETIME NULL,
        last_sms_at DATETIME NULL,
        city VARCHAR(80) NULL,
        district VARCHAR(120) NULL,
        telegram_id VARCHAR(64) NULL,
        bale_id VARCHAR(64) NULL,
        telegram_username VARCHAR(100) NULL,
        bale_username VARCHAR(191) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        UNIQUE KEY uq_phone (phone),
        KEY idx_status (status),
        KEY idx_act (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_tags (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        UNIQUE KEY uq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_contact_tags (
        contact_id INT UNSIGNED NOT NULL,
        tag_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (contact_id, tag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_notes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_c (contact_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_segments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        criteria TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_campaigns (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        body TEXT NOT NULL,
        segment_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        recipients_n INT NOT NULL DEFAULT 0,
        sent_n INT NOT NULL DEFAULT 0,
        fail_n INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        contact_id INT NULL,
        body TEXT NOT NULL,
        campaign_id INT NULL,
        template_id INT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        result_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_phone (phone),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_followups (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        due_date DATE NULL,
        priority VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
        notes TEXT NULL,
        related_ad VARCHAR(64) NULL,
        related_request INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_c (contact_id),
        KEY idx_due (due_date, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_export_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(40) NULL,
        rows_n INT NOT NULL DEFAULT 0,
        format VARCHAR(10) NOT NULL DEFAULT 'xlsx',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_settings (
        k VARCHAR(80) NOT NULL PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_audit (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(80) NOT NULL,
        detail TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_tracking_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        short_code VARCHAR(16) NOT NULL,
        destination_url VARCHAR(500) NOT NULL,
        contact_id INT NULL,
        campaign_id INT NULL,
        message_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (short_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_clicks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tracking_id INT UNSIGNED NOT NULL,
        clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_t (tracking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_deferred (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20) NOT NULL,
        body TEXT NOT NULL,
        contact_id INT NULL,
        campaign_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'WAITING',
        send_after DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_st (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS comm_automations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL,
        event_key VARCHAR(40) NOT NULL,
        recipient_type VARCHAR(40) NOT NULL DEFAULT 'admin',
        recipient_phone VARCHAR(20) NULL,
        body TEXT NOT NULL,
        timing VARCHAR(20) NOT NULL DEFAULT 'digest',
        interval_min INT NOT NULL DEFAULT 30,
        min_count INT NOT NULL DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        last_run_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        $pdo->exec('ALTER TABLE comm_deferred ADD COLUMN send_after DATETIME NULL');
    } catch (Throwable $e) {
    }
    foreach ([
        'telegram_id VARCHAR(64) NULL',
        'bale_id VARCHAR(64) NULL',
        'telegram_username VARCHAR(100) NULL',
        'bale_username VARCHAR(191) NULL',
    ] as $col) {
        try {
            $pdo->exec('ALTER TABLE comm_contacts ADD COLUMN ' . $col);
        } catch (Throwable $e) {
        }
    }
    $n = (int)$pdo->query('SELECT COUNT(*) FROM comm_templates')->fetchColumn();
    if ($n === 0) {
        $ins = $pdo->prepare('INSERT INTO comm_templates(name, body) VALUES (?,?)');
        $ins->execute(['تطبیق جدید', 'ملکینو: {{count}} فایل مطابق درخواست شما در سایت قرار گرفت. {{link}}']);
        $ins->execute(['یادآوری بازدید', 'ملکینو: یادآوری بازدید «{{property_title}}» در {{visit_date}}. {{link}}']);
        $ins->execute(['پیگیری علاقه', 'ملکینو: آگهی «{{property_title}}» را چند بار دیده‌اید. مشاهده: {{link}}']);
    }
    $done = true;
}

function commRoleFromTx(string $tx, string $side): array
{
    $tx = trim($tx);
    $out = [];
    if ($side === 'owner') {
        $out[] = 'مالک';
        if (strpos($tx, 'اجاره') !== false || strpos($tx, 'رهن') !== false) {
            $out[] = 'موجر';
        }
        if (strpos($tx, 'فروش') !== false) {
            $out[] = 'فروشنده';
        }
    } else {
        $out[] = 'متقاضی';
        if (strpos($tx, 'اجاره') !== false) {
            $out[] = 'مستأجر';
        }
        if (strpos($tx, 'رهن') !== false && strpos($tx, 'اجاره') === false) {
            $out[] = 'مستأجر';
        }
        if (strpos($tx, 'خرید') !== false || strpos($tx, 'فروش') !== false) {
            $out[] = 'خریدار';
        }
    }
    return $out;
}

function commMergeRoles(string $cur, array $add): string
{
    $have = array_filter(array_map('trim', explode(',', $cur)));
    foreach ($add as $r) {
        if ($r !== '' && !in_array($r, $have, true)) {
            $have[] = $r;
        }
    }
    return implode(',', $have);
}

function commTouch(array &$bag, string $phone, array $patch): void
{
    $phone = commNormPhone($phone);
    if (!preg_match('/^09\d{9}$/', $phone)) {
        return;
    }
    if (!isset($bag[$phone])) {
        $bag[$phone] = [
            'phone' => $phone,
            'user_id' => null,
            'first_name' => '',
            'last_name' => '',
            'name' => '',
            'roles_suggested' => '',
            'source' => $patch['source'] ?? 'system',
            'properties_n' => 0,
            'requests_n' => 0,
            'visits_n' => 0,
            'views_n' => 0,
            'last_activity' => null,
            'city' => '',
            'district' => '',
            'telegram_id' => '',
            'bale_id' => '',
            'telegram_username' => '',
            'bale_username' => '',
        ];
    }
    $row = &$bag[$phone];
    foreach (['user_id', 'first_name', 'last_name', 'name', 'source', 'city', 'district', 'telegram_id', 'bale_id', 'telegram_username', 'bale_username'] as $k) {
        if (!empty($patch[$k]) && (empty($row[$k]) || $row[$k] === '')) {
            $row[$k] = $patch[$k];
        }
    }
    if (!empty($patch['roles'])) {
        $row['roles_suggested'] = commMergeRoles((string)$row['roles_suggested'], (array)$patch['roles']);
    }
    foreach (['properties_n', 'requests_n', 'visits_n', 'views_n'] as $k) {
        if (!empty($patch[$k])) {
            $row[$k] += (int)$patch[$k];
        }
    }
    if (!empty($patch['last_activity'])) {
        if ($row['last_activity'] === null || $patch['last_activity'] > $row['last_activity']) {
            $row['last_activity'] = $patch['last_activity'];
        }
    }
}

function commSyncContacts(PDO $pdo): int
{
    commEnsureSchema($pdo);
    $bag = [];
    $usersSql = 'SELECT id, name, first_name, last_name, phone, telegram_id, bale_id, telegram_username, bale_username FROM users ORDER BY id DESC LIMIT 4000';
    $users = commTry($pdo, $usersSql);
    if (!$users) {
        $users = commTry($pdo, 'SELECT id, name, first_name, last_name, phone, telegram_id, bale_id, username, last_platform FROM users ORDER BY id DESC LIMIT 4000');
    }
    foreach ($users as $u) {
        $tgUn = trim((string)($u['telegram_username'] ?? ''));
        $baleUn = trim((string)($u['bale_username'] ?? ''));
        if ($tgUn === '' && ($u['last_platform'] ?? '') !== 'bale') {
            $tgUn = trim((string)($u['username'] ?? ''));
        }
        if ($baleUn === '' && ($u['last_platform'] ?? '') === 'bale') {
            $baleUn = trim((string)($u['username'] ?? ''));
        }
        commTouch($bag, (string)($u['phone'] ?? ''), [
            'user_id' => (int)$u['id'],
            'name' => trim((string)($u['name'] ?? '')),
            'first_name' => trim((string)($u['first_name'] ?? '')),
            'last_name' => trim((string)($u['last_name'] ?? '')),
            'source' => 'registration',
            'roles' => ['کاربر'],
            'telegram_id' => trim((string)($u['telegram_id'] ?? '')),
            'bale_id' => trim((string)($u['bale_id'] ?? '')),
            'telegram_username' => $tgUn,
            'bale_username' => $baleUn,
        ]);
    }
    foreach (commTry($pdo, 'SELECT phone, last_name, transaction_type, location, status, created_at, updated_at FROM ads ORDER BY id DESC LIMIT 2500') as $a) {
        $roles = commRoleFromTx((string)($a['transaction_type'] ?? ''), 'owner');
        commTouch($bag, (string)($a['phone'] ?? ''), [
            'last_name' => trim((string)($a['last_name'] ?? '')),
            'source' => 'property',
            'roles' => $roles,
            'properties_n' => 1,
            'district' => trim((string)($a['location'] ?? '')),
            'last_activity' => $a['updated_at'] ?? $a['created_at'] ?? null,
        ]);
    }
    foreach (commTry($pdo, 'SELECT phone, last_name, transaction_type, location, created_at, user_id FROM property_requests ORDER BY id DESC LIMIT 2500') as $r) {
        commTouch($bag, (string)($r['phone'] ?? ''), [
            'user_id' => !empty($r['user_id']) ? (int)$r['user_id'] : null,
            'last_name' => trim((string)($r['last_name'] ?? '')),
            'source' => 'request',
            'roles' => commRoleFromTx((string)($r['transaction_type'] ?? ''), 'seeker'),
            'requests_n' => 1,
            'district' => trim((string)($r['location'] ?? '')),
            'last_activity' => $r['created_at'] ?? null,
        ]);
    }
    foreach (commTry($pdo, 'SELECT phone, name, created_at, user_id FROM visit_requests ORDER BY id DESC LIMIT 1500') as $v) {
        commTouch($bag, (string)($v['phone'] ?? ''), [
            'user_id' => !empty($v['user_id']) ? (int)$v['user_id'] : null,
            'name' => trim((string)($v['name'] ?? '')),
            'source' => 'visit',
            'roles' => ['متقاضی'],
            'visits_n' => 1,
            'last_activity' => $v['created_at'] ?? null,
        ]);
    }
    foreach (commTry($pdo, 'SELECT user_id, COUNT(*) c, MAX(viewed_at) lastv FROM ad_views WHERE user_id IS NOT NULL AND user_id>0 GROUP BY user_id') as $vw) {
        $u = commTry($pdo, 'SELECT phone FROM users WHERE id=? LIMIT 1', [(int)$vw['user_id']]);
        if ($u) {
            commTouch($bag, (string)$u[0]['phone'], [
                'user_id' => (int)$vw['user_id'],
                'views_n' => (int)$vw['c'],
                'last_activity' => $vw['lastv'],
            ]);
        }
    }
    $up = $pdo->prepare(
        'INSERT INTO comm_contacts(phone,user_id,first_name,last_name,name,roles_suggested,status,source,properties_n,requests_n,visits_n,views_n,last_activity,city,district,telegram_id,bale_id,telegram_username,bale_username,updated_at)
         VALUES (?,?,?,?,?,?,\'active\',?,?,?,?,?,?,?,?,?,?,?,?,NOW())
         ON DUPLICATE KEY UPDATE
            user_id=COALESCE(VALUES(user_id), user_id),
            first_name=IF(VALUES(first_name)<>\'\', VALUES(first_name), first_name),
            last_name=IF(VALUES(last_name)<>\'\', VALUES(last_name), last_name),
            name=IF(VALUES(name)<>\'\', VALUES(name), name),
            roles_suggested=VALUES(roles_suggested),
            source=VALUES(source),
            properties_n=VALUES(properties_n),
            requests_n=VALUES(requests_n),
            visits_n=VALUES(visits_n),
            views_n=VALUES(views_n),
            last_activity=VALUES(last_activity),
            district=IF(VALUES(district)<>\'\', VALUES(district), district),
            telegram_id=IF(VALUES(telegram_id)<>\'\', VALUES(telegram_id), telegram_id),
            bale_id=IF(VALUES(bale_id)<>\'\', VALUES(bale_id), bale_id),
            telegram_username=IF(VALUES(telegram_username)<>\'\', VALUES(telegram_username), telegram_username),
            bale_username=IF(VALUES(bale_username)<>\'\', VALUES(bale_username), bale_username),
            updated_at=NOW()'
    );
    $n = 0;
    foreach ($bag as $row) {
        $name = trim((string)$row['name']);
        if ($name === '') {
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
        }
        $up->execute([
            $row['phone'],
            $row['user_id'],
            $row['first_name'] !== '' ? $row['first_name'] : null,
            $row['last_name'] !== '' ? $row['last_name'] : null,
            $name !== '' ? $name : null,
            $row['roles_suggested'] !== '' ? $row['roles_suggested'] : null,
            $row['source'],
            (int)$row['properties_n'],
            (int)$row['requests_n'],
            (int)$row['visits_n'],
            (int)$row['views_n'],
            $row['last_activity'],
            $row['city'] !== '' ? $row['city'] : null,
            $row['district'] !== '' ? $row['district'] : null,
            ($row['telegram_id'] ?? '') !== '' ? $row['telegram_id'] : null,
            ($row['bale_id'] ?? '') !== '' ? $row['bale_id'] : null,
            ($row['telegram_username'] ?? '') !== '' ? $row['telegram_username'] : null,
            ($row['bale_username'] ?? '') !== '' ? $row['bale_username'] : null,
        ]);
        $n++;
    }
    return $n;
}

function commFillVars(string $body, array $c): string
{
    $name = trim((string)($c['name'] ?? ''));
    $first = trim((string)($c['first_name'] ?? ''));
    if ($first === '' && $name !== '') {
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = $parts[0] ?? '';
    }
    $map = [
        '{{first_name}}' => $first !== '' ? $first : 'کاربر',
        '{{last_name}}' => (string)($c['last_name'] ?? ''),
        '{{full_name}}' => $name !== '' ? $name : (string)($c['phone'] ?? ''),
        '{{phone}}' => (string)($c['phone'] ?? ''),
        '{{company_name}}' => 'ملکینو',
    ];
    return strtr($body, $map);
}

function commSmsParts(string $text): array
{
    $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    $per = 70;
    $parts = max(1, (int)ceil($len / $per));
    return ['chars' => $len, 'parts' => $parts];
}

function commSendOne(PDO $pdo, string $phone, string $text, ?int $contactId, ?int $campaignId, ?int $templateId): array
{
    if (!function_exists('smsSendText')) {
        require_once __DIR__ . '/sms.php';
    }
    $phone = commNormPhone($phone);
    if (!preg_match('/^09\d{9}$/', $phone)) {
        return ['success' => false, 'message' => 'شماره نامعتبر است.', 'phone' => $phone];
    }
    $today = commTry($pdo, 'SELECT COUNT(*) c FROM comm_messages WHERE phone=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) AND success=1', [$phone]);
    $maxDay = max(1, (int)commSetting($pdo, 'max_sms_day', '8'));
    if ((int)($today[0]['c'] ?? 0) >= $maxDay) {
        return ['success' => false, 'message' => 'سقف پیامک روزانه این شماره پر است.', 'phone' => $phone];
    }
    $dup = commTry($pdo, 'SELECT id FROM comm_messages WHERE phone=? AND body=? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) LIMIT 1', [$phone, $text]);
    if ($dup) {
        return ['success' => false, 'message' => 'پیام مشابه در ۶ ساعت اخیر ارسال شده.', 'phone' => $phone];
    }
    $urgent = !empty($GLOBALS['COMM_URGENT']);
    if (!$urgent && commQuietNow($pdo)) {
        $pdo->prepare('INSERT INTO comm_deferred(phone,body,contact_id,campaign_id,status) VALUES (?,?,?,?,\'WAITING\')')
            ->execute([$phone, $text, $contactId, $campaignId]);
        return ['success' => true, 'deferred' => true, 'message' => 'در ساعات سکوت ذخیره شد و بعداً ارسال می‌شود.', 'phone' => $phone];
    }
    $text = commRewriteLinks($pdo, $text, $contactId, $campaignId);
    $send = smsSendText($phone, $text);
    $ok = !empty($send['success']);
    $pdo->prepare('INSERT INTO comm_messages(phone,contact_id,body,campaign_id,template_id,success,result_message) VALUES (?,?,?,?,?,?,?)')
        ->execute([$phone, $contactId, $text, $campaignId, $templateId, $ok ? 1 : 0, $send['message'] ?? '']);
    if ($ok && $contactId) {
        $pdo->prepare('UPDATE comm_contacts SET last_sms_at=NOW() WHERE id=?')->execute([$contactId]);
    }
    return ['success' => $ok, 'message' => $send['message'] ?? '', 'phone' => $phone];
}

function commWhere(array $q): array
{
    $w = ['1=1'];
    $p = [];
    if (!empty($q['status'])) {
        $w[] = 'status=?';
        $p[] = $q['status'];
    }
    if (!empty($q['role'])) {
        $w[] = 'roles_suggested LIKE ?';
        $p[] = '%' . $q['role'] . '%';
    }
    if (!empty($q['search'])) {
        $s = '%' . $q['search'] . '%';
        $w[] = '(phone LIKE ? OR name LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR district LIKE ? OR telegram_id LIKE ? OR bale_id LIKE ? OR telegram_username LIKE ? OR bale_username LIKE ?)';
        array_push($p, $s, $s, $s, $s, $s, $s, $s, $s, $s);
    }
    if (!empty($q['hot'])) {
        $w[] = 'views_n >= 5';
    }
    if (!empty($q['has_request'])) {
        $w[] = 'requests_n > 0';
    }
    if (!empty($q['has_property'])) {
        $w[] = 'properties_n > 0';
    }
    if (!empty($q['min_views'])) {
        $w[] = 'views_n >= ?';
        $p[] = (int)$q['min_views'];
    }
    if (!empty($q['days'])) {
        $w[] = 'last_activity >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $p[] = (int)$q['days'];
    }
    if (!empty($q['no_visit'])) {
        $w[] = 'visits_n = 0';
    }
    return [implode(' AND ', $w), $p];
}

function commKpis(PDO $pdo): array
{
    $total = (int)$pdo->query('SELECT COUNT(*) FROM comm_contacts')->fetchColumn();
    $role = static function (PDO $pdo, string $r): int {
        $st = $pdo->prepare('SELECT COUNT(*) FROM comm_contacts WHERE roles_suggested LIKE ?');
        $st->execute(['%' . $r . '%']);
        return (int)$st->fetchColumn();
    };
    $smsToday = (int)$pdo->query("SELECT COUNT(*) FROM comm_messages WHERE created_at >= CURDATE()")->fetchColumn();
    $smsOk = (int)$pdo->query("SELECT COUNT(*) FROM comm_messages WHERE created_at >= CURDATE() AND success=1")->fetchColumn();
    $openFu = (int)$pdo->query("SELECT COUNT(*) FROM comm_followups WHERE status='open'")->fetchColumn();
    $smsSet = function_exists('smsEffectiveSettings') ? smsEffectiveSettings() : ['enabled' => false];
    return [
        'contacts' => $total,
        'owners' => $role($pdo, 'مالک'),
        'landlords' => $role($pdo, 'موجر'),
        'tenants' => $role($pdo, 'مستأجر'),
        'buyers' => $role($pdo, 'خریدار'),
        'sellers' => $role($pdo, 'فروشنده'),
        'leads' => $role($pdo, 'متقاضی'),
        'hot' => (int)$pdo->query('SELECT COUNT(*) FROM comm_contacts WHERE views_n>=5')->fetchColumn(),
        'sms_today' => $smsToday,
        'sms_ok' => $smsOk,
        'followups' => $openFu,
        'sms_enabled' => !empty($smsSet['enabled']),
        'clicks_today' => (int)$pdo->query("SELECT COUNT(*) FROM comm_clicks WHERE clicked_at >= CURDATE()")->fetchColumn(),
        'deferred' => (int)$pdo->query("SELECT COUNT(*) FROM comm_deferred WHERE status='WAITING'")->fetchColumn(),
        'campaigns' => (int)$pdo->query('SELECT COUNT(*) FROM comm_campaigns')->fetchColumn(),
        'new_week' => (int)$pdo->query("SELECT COUNT(*) FROM comm_contacts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
    ];
}

function commSetting(PDO $pdo, string $k, string $default = ''): string
{
    $st = $pdo->prepare('SELECT v FROM comm_settings WHERE k=? LIMIT 1');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function commSet(PDO $pdo, string $k, string $v): void
{
    $pdo->prepare('INSERT INTO comm_settings(k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}

function commAudit(PDO $pdo, string $action, string $detail = ''): void
{
    try {
        $pdo->prepare('INSERT INTO comm_audit(action, detail) VALUES (?,?)')->execute([$action, $detail]);
    } catch (Throwable $e) {
    }
}

function commQuietNow(PDO $pdo): bool
{
    if (commSetting($pdo, 'quiet_enabled', '0') !== '1') {
        return false;
    }
    $start = commSetting($pdo, 'quiet_start', '22:00');
    $end = commSetting($pdo, 'quiet_end', '08:00');
    $now = date('H:i');
    if ($start <= $end) {
        return $now >= $start && $now < $end;
    }
    return $now >= $start || $now < $end;
}

function commSite(): string
{
        require_once __DIR__ . '/program-url.php';
        return melkinoAppUrl();
    }

function commTrackUrl(PDO $pdo, string $dest, ?int $contactId, ?int $campaignId): string
{
    $dest = trim($dest);
    if ($dest === '' || !preg_match('#^https?://#i', $dest)) {
        return $dest;
    }
    $code = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 8);
    $pdo->prepare('INSERT INTO comm_tracking_links(short_code,destination_url,contact_id,campaign_id) VALUES (?,?,?,?)')
        ->execute([$code, $dest, $contactId, $campaignId]);
    $site = commSite();
    return $site === '' ? $dest : ($site . '/r.php?c=' . rawurlencode($code));
}

function commRewriteLinks(PDO $pdo, string $text, ?int $contactId, ?int $campaignId): string
{
    return preg_replace_callback('#https?://[^\s<>"]+#u', static function ($m) use ($pdo, $contactId, $campaignId) {
        if (strpos($m[0], 'r.php?c=') !== false) {
            return $m[0];
        }
        return commTrackUrl($pdo, $m[0], $contactId, $campaignId);
    }, $text) ?? $text;
}

function commCnt(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function commToday(PDO $pdo): array
{
    return [
        'ads' => commCnt($pdo, "SELECT COUNT(*) FROM ads WHERE created_at >= CURDATE()"),
        'requests' => commCnt($pdo, "SELECT COUNT(*) FROM property_requests WHERE created_at >= CURDATE()"),
        'visits' => commCnt($pdo, "SELECT COUNT(*) FROM visit_requests WHERE created_at >= CURDATE()"),
        'visits_tomorrow' => commCnt($pdo, "SELECT COUNT(*) FROM visit_requests WHERE DATE(preferred_date)=DATE_ADD(CURDATE(), INTERVAL 1 DAY)"),
        'hot' => commCnt($pdo, 'SELECT COUNT(*) FROM comm_contacts WHERE views_n>=5'),
        'reminders' => commCnt($pdo, "SELECT COUNT(*) FROM comm_followups WHERE status='open' AND (due_date IS NULL OR due_date<=DATE_ADD(CURDATE(), INTERVAL 1 DAY))"),
        'deferred' => commCnt($pdo, "SELECT COUNT(*) FROM comm_deferred WHERE status='WAITING'"),
    ];
}

function commLeadBand(array $c): string
{
    $v = (int)($c['views_n'] ?? 0);
    $r = (int)($c['requests_n'] ?? 0);
    $vis = (int)($c['visits_n'] ?? 0);
    if ($v >= 8 || $vis > 0 && $v >= 3) {
        return 'HOT';
    }
    if ($v >= 3 || $r > 0) {
        return 'WARM';
    }
    return 'COLD';
}

function commCleanUsername(?string $u): string
{
    return ltrim(trim((string)$u), '@');
}

function commTgLink(?string $id, ?string $username): string
{
    $u = commCleanUsername($username);
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $u)) {
        return 'https://t.me/' . $u;
    }
    $id = trim((string)$id);
    if (preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
        return 'tg://user?id=' . $id;
    }
    return '';
}

function commBaleLink(?string $id, ?string $username): string
{
    $u = commCleanUsername($username);
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $u)) {
        return 'https://ble.ir/' . $u;
    }
    $id = trim((string)$id);
    if (preg_match('/^[1-9][0-9]{0,19}$/', $id)) {
        return 'https://ble.ir/' . $id;
    }
    return '';
}

function commAttachLinks(array $row): array
{
    $row['telegram_id'] = trim((string)($row['telegram_id'] ?? ''));
    $row['bale_id'] = trim((string)($row['bale_id'] ?? ''));
    $row['telegram_username'] = commCleanUsername($row['telegram_username'] ?? '');
    $row['bale_username'] = commCleanUsername($row['bale_username'] ?? '');
    $row['telegram_link'] = commTgLink($row['telegram_id'], $row['telegram_username']);
    $row['bale_link'] = commBaleLink($row['bale_id'], $row['bale_username']);
    return $row;
}

function commEnrichMessenger(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return $rows;
    }
    $phones = [];
    $uids = [];
    foreach ($rows as $r) {
        $ph = commNormPhone((string)($r['phone'] ?? ''));
        if ($ph !== '') {
            $phones[$ph] = true;
        }
        if (!empty($r['user_id'])) {
            $uids[(int)$r['user_id']] = true;
        }
    }
    $users = [];
    if ($phones || $uids) {
        $sql = 'SELECT id, phone, telegram_id, bale_id, telegram_username, bale_username FROM users WHERE 0';
        $params = [];
        if ($phones) {
            $in = implode(',', array_fill(0, count($phones), '?'));
            $sql .= ' OR phone IN (' . $in . ')';
            $params = array_merge($params, array_keys($phones));
        }
        if ($uids) {
            $in = implode(',', array_fill(0, count($uids), '?'));
            $sql .= ' OR id IN (' . $in . ')';
            $params = array_merge($params, array_map('intval', array_keys($uids)));
        }
        $users = commTry($pdo, $sql, $params);
        if (!$users) {
            $sql = 'SELECT id, phone, telegram_id, bale_id, username, last_platform FROM users WHERE 0';
            $params = [];
            if ($phones) {
                $in = implode(',', array_fill(0, count($phones), '?'));
                $sql .= ' OR phone IN (' . $in . ')';
                $params = array_merge($params, array_keys($phones));
            }
            if ($uids) {
                $in = implode(',', array_fill(0, count($uids), '?'));
                $sql .= ' OR id IN (' . $in . ')';
                $params = array_merge($params, array_map('intval', array_keys($uids)));
            }
            $users = commTry($pdo, $sql, $params);
        }
    }
    $byPhone = [];
    $byId = [];
    foreach ($users as $u) {
        $tgUn = commCleanUsername($u['telegram_username'] ?? '');
        $baleUn = commCleanUsername($u['bale_username'] ?? '');
        if ($tgUn === '' && ($u['last_platform'] ?? '') !== 'bale') {
            $tgUn = commCleanUsername($u['username'] ?? '');
        }
        if ($baleUn === '' && ($u['last_platform'] ?? '') === 'bale') {
            $baleUn = commCleanUsername($u['username'] ?? '');
        }
        $pack = [
            'telegram_id' => trim((string)($u['telegram_id'] ?? '')),
            'bale_id' => trim((string)($u['bale_id'] ?? '')),
            'telegram_username' => $tgUn,
            'bale_username' => $baleUn,
        ];
        $byId[(int)$u['id']] = $pack;
        $ph = commNormPhone((string)($u['phone'] ?? ''));
        if ($ph !== '') {
            $byPhone[$ph] = $pack;
        }
    }
    foreach ($rows as &$r) {
        $pack = [];
        if (!empty($r['user_id']) && isset($byId[(int)$r['user_id']])) {
            $pack = $byId[(int)$r['user_id']];
        } else {
            $ph = commNormPhone((string)($r['phone'] ?? ''));
            if ($ph !== '' && isset($byPhone[$ph])) {
                $pack = $byPhone[$ph];
            }
        }
        foreach (['telegram_id', 'bale_id', 'telegram_username', 'bale_username'] as $k) {
            if (empty($r[$k]) && !empty($pack[$k])) {
                $r[$k] = $pack[$k];
            }
        }
        $r = commAttachLinks($r);
    }
    unset($r);
    return $rows;
}
