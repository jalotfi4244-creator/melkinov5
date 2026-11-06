<?php
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-lib.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=utf-8');
// نکته: قبلاً این فایل db_helpers.php را require نمی‌کرد؛ یعنی
// melkinoUpsertUser() اصلاً تعریف نشده بود و هر فراخوانی این
// endpoint با خطای «تابع تعریف‌نشده» رد می‌خورد.

/* ------------------------------------------------------------------
   راند ۳۰ — حذف/بازکردن شمارهٔ تماس کاربر توسط ادمین
   ------------------------------------------------------------------
   تنها مسیر مجاز برای تغییر شمارهٔ ثبت‌شدهٔ کاربر.
   - فقط ادمین (سشن سروری) + CSRF
   - phone = NULL و phone_verified = 0 و phone_locked = 0
   - یک ردیف لاگ در admin_phone_audit ثبت می‌شود:
     admin_id, admin_username, user_id, action, old_phone, new_phone,
     ip_address, created_at
------------------------------------------------------------------ */
// بدنهٔ درخواست یک‌بار خوانده می‌شود (php://input در برخی SAPIها دوبار خواندنی نیست)
$rawBody30 = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? (string)file_get_contents('php://input') : '';
$body30 = json_decode($rawBody30, true);

if (($_GET['action'] ?? '') === 'clear_phone' || (is_array($body30) && ($body30['action'] ?? '') === 'clear_phone')) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $body = is_array($body30 ?? null) ? $body30 : $_POST;
    melkinoCsrfCheck();

    $targetUserId = (int)($body['user_id'] ?? 0);
    if ($targetUserId <= 0) {
        echo json_encode(['success' => false, 'message' => 'user_id نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('melkinoEnsureUserProfileColumns')) {
        melkinoEnsureUserProfileColumns();
    }

    try {
        // ساخت جدول لاگ در صورت نبود (idempotent)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_phone_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NULL,
                admin_username VARCHAR(100) NULL,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                old_phone VARCHAR(30) NULL,
                new_phone VARCHAR(30) NULL,
                ip_address VARCHAR(45) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_apa_user (user_id),
                INDEX idx_apa_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $st = $pdo->prepare('SELECT id, phone, name, telegram_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$targetUserId]);
        $target = $st->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'کاربر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $oldPhone = trim((string)($target['phone'] ?? ''));

        $pdo->prepare('UPDATE users SET phone = NULL, phone_verified = 0, phone_locked = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$targetUserId]);

        $pdo->prepare(
            'INSERT INTO admin_phone_audit (admin_id, admin_username, user_id, action, old_phone, new_phone, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())'
        )->execute([
            (int)($_SESSION['admin_id'] ?? 0) ?: null,
            (string)($_SESSION['admin_username'] ?? ''),
            $targetUserId,
            'clear_phone',
            $oldPhone !== '' ? $oldPhone : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);

        echo json_encode([
            'success'   => true,
            'message'   => 'شمارهٔ تماس کاربر حذف و قفل آن باز شد.',
            'user_id'   => $targetUserId,
            'old_phone' => $oldPhone,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => melkinoSafeError($e, 'identity-sync.delete_phone', 'حذف شماره انجام نشد.')], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['action'] ?? '') === 'list') {
    if (empty($_SESSION['is_admin']) && empty($_SESSION['admin_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // ستون‌های پروفایل را در صورت نیاز اضافه می‌کنیم (فقط اگر نباشند)
    if (function_exists('melkinoEnsureUserProfileColumns')) {
        try { melkinoEnsureUserProfileColumns(); } catch (Throwable $e) {}
    }
    if (function_exists('melkinoEnsureLoginEventsSchema')) {
        try { melkinoEnsureLoginEventsSchema($pdo); } catch (Throwable $e) {}
    }
    try {
        $pdo->exec(
            "UPDATE login_events SET bale_id = telegram_id, telegram_id = NULL
              WHERE (platform = 'bale' OR platform = 'بله')
                AND (bale_id IS NULL OR bale_id = '')
                AND telegram_id IS NOT NULL AND telegram_id <> ''"
        );
        $pdo->exec(
            "UPDATE users SET bale_id = telegram_id, telegram_id = NULL
              WHERE (last_platform = 'bale' OR last_platform = 'بله')
                AND (bale_id IS NULL OR bale_id = '')
                AND telegram_id IS NOT NULL AND telegram_id <> ''"
        );
    } catch (Throwable $eFixBaleList) {}

    $rows = [];
    if ($pdo instanceof PDO) {
        $queries = [
            "SELECT id, telegram_id, bale_id, eitaa_id, eitaa_username, telegram_username, bale_username, username, name, phone, is_active,
                    first_login, last_login, login_count, created_at,
                    last_ip, last_platform, user_agent, photo_url, language_code,
                    phone_verified, phone_locked
               FROM users
              ORDER BY last_login DESC, id DESC",
            "SELECT id, telegram_id, username, name, phone, is_active,
                    first_login, last_login, login_count, created_at
               FROM users
              ORDER BY last_login DESC, id DESC",
            "SELECT * FROM users ORDER BY id DESC",
        ];
        foreach ($queries as $q30) {
            try {
                $got = $pdo->query($q30)->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($got) && $got) {
                    $rows = $got;
                    break;
                }
                if (is_array($got)) {
                    $rows = $got;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        if (!$rows) {
            try {
                if (function_exists('melkinoEnsureLoginEventsSchema')) {
                    melkinoEnsureLoginEventsSchema($pdo);
                }
                $rows = $pdo->query(
                    "SELECT
                        COALESCE(MAX(user_id), 0) AS id,
                        telegram_id,
                        bale_id,
                        eitaa_id,
                        MAX(username) AS username,
                        MAX(name) AS name,
                        '' AS phone,
                        1 AS is_active,
                        MIN(created_at) AS first_login,
                        MAX(created_at) AS last_login,
                        COUNT(*) AS login_count,
                        MIN(created_at) AS created_at,
                        MAX(COALESCE(ip_address, ip)) AS last_ip,
                        MAX(platform) AS last_platform
                       FROM login_events
                      GROUP BY telegram_id, bale_id, eitaa_id
                      ORDER BY MAX(created_at) DESC
                      LIMIT 500"
                )->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $rows = $pdo->query(
                        "SELECT id, telegram_id, username, name, created_at AS first_login,
                                created_at AS last_login, 1 AS login_count
                           FROM login_events
                          ORDER BY created_at DESC
                          LIMIT 200"
                    )->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) {
                    $rows = [];
                }
            }
        }
    }
    if (!is_array($rows)) {
        $rows = [];
    }

    $utf8clean = static function ($v) use (&$utf8clean) {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = $utf8clean($x);
            }
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return $v;
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($v, 'UTF-8')) {
            return $v;
        }
        $c = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $v) : false;
        if (is_string($c) && $c !== '') {
            return $c;
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    };
    $rows = $utf8clean($rows);
    if (is_array($rows)) {
        foreach ($rows as &$uFix) {
            if (!is_array($uFix)) {
                continue;
            }
            $pf = strtolower(trim((string) ($uFix['last_platform'] ?? '')));
            $bid = trim((string) ($uFix['bale_id'] ?? ''));
            $tid = trim((string) ($uFix['telegram_id'] ?? ''));
            if (($pf === 'bale' || $pf === 'بله') && $bid === '' && $tid !== '') {
                $uFix['bale_id'] = $tid;
                $uFix['telegram_id'] = '';
            }
        }
        unset($uFix);
    }
    if ($pdo instanceof PDO && is_array($rows) && $rows) {
        try {
            if (function_exists('melkinoEnsureLoginEventsSchema')) {
                melkinoEnsureLoginEventsSchema($pdo);
            }
            $ids = [];
            foreach ($rows as $rr) {
                $iid = (int) ($rr['id'] ?? 0);
                if ($iid > 0) {
                    $ids[$iid] = true;
                }
            }
            $idList = array_keys($ids);
            $counts = [];
            if ($idList) {
                $in = implode(',', array_map('intval', $idList));
                $stc = $pdo->query("SELECT user_id, COUNT(*) AS c FROM login_events WHERE user_id IN ($in) GROUP BY user_id");
                if ($stc) {
                    foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $counts[(int) $cr['user_id']] = (int) $cr['c'];
                    }
                }
            }
            foreach ($rows as &$rrc) {
                $iid = (int) ($rrc['id'] ?? 0);
                if ($iid && isset($counts[$iid])) {
                    $rrc['login_count'] = $counts[$iid];
                } else {
                    $rrc['login_count'] = (int) ($rrc['login_count'] ?? 0);
                    if ($rrc['login_count'] > 1 && empty($counts[$iid])) {
                        $rrc['login_count'] = 1;
                    }
                }
            }
            unset($rrc);
        } catch (Throwable $eCnt) {
        }
    }

    if (is_file(__DIR__ . '/jalali-lib.php')) {
        require_once __DIR__ . '/jalali-lib.php';
    }
    if (function_exists('melkinoFormatTehranFa')) {
        foreach ($rows as &$u30) {
            try {
                $u30['first_login_fa'] = melkinoFormatTehranFa($u30['first_login'] ?? ($u30['created_at'] ?? null));
                $u30['last_login_fa'] = melkinoFormatTehranFa($u30['last_login'] ?? null);
            } catch (Throwable $e) {
                $u30['first_login_fa'] = (string) ($u30['first_login'] ?? $u30['created_at'] ?? '—');
                $u30['last_login_fa'] = (string) ($u30['last_login'] ?? '—');
            }
        }
        unset($u30);
    }

    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode(['success' => true, 'users' => $rows], $flags);
    if ($json === false) {
        $json = json_encode(['success' => true, 'users' => $rows, 'encode_error' => true]);
    }
    if ($json === false) {
        $json = '{"success":false,"message":"خطا در تبدیل فهرست کاربران","users":[]}';
    }
    echo $json;
    exit;
}

if (($_GET['action'] ?? '') === 'history') {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // راند ۳۳: SELECT پویا از ستون‌های موجود — روی جدول قدیمی (بدون
    // bale_id/username/name/ip_address) هم تاریخچه بدون خطا برمی‌گردد و
    // روی جدول جدید همهٔ شناسه‌ها کامل ارسال می‌شوند.
    if (function_exists('melkinoEnsureLoginEventsSchema')) {
        melkinoEnsureLoginEventsSchema($pdo);
    }
    try {
        $pdo->exec(
            "UPDATE login_events\n                SET bale_id = telegram_id, telegram_id = NULL\n              WHERE (platform = 'bale' OR platform = 'بله')\n                AND (bale_id IS NULL OR bale_id = '')\n                AND telegram_id IS NOT NULL AND telegram_id <> ''"
        );
    } catch (Throwable $eFixBaleEv) {
    }
    try {
        $leCols33 = [];
        foreach ($pdo->query('SHOW COLUMNS FROM login_events')->fetchAll(PDO::FETCH_ASSOC) as $c33) {
            $leCols33[strtolower((string)$c33['Field'])] = true;
        }
        $want33 = ['id', 'telegram_id', 'bale_id', 'eitaa_id', 'username', 'name', 'ip_address', 'ip', 'user_agent', 'platform', 'language_code', 'created_at'];
        $sel33 = array_values(array_filter($want33, function ($c) use ($leCols33) {
            return isset($leCols33[$c]);
        }));
        if (!$sel33) {
            $sel33 = ['id'];
        }
        $st = $pdo->prepare('SELECT `' . implode('`,`', $sel33) . '` FROM login_events WHERE user_id=? ORDER BY created_at DESC LIMIT 200');
        $st->execute([$uid]);
        $events33 = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $events33 = [];
    }
    if (!is_array($events33)) {
        $events33 = [];
    }
    if (is_file(__DIR__ . '/jalali-lib.php')) {
        require_once __DIR__ . '/jalali-lib.php';
    }
    if (function_exists('melkinoFormatTehranFa')) {
        foreach ($events33 as &$e30) {
            try {
                $e30['created_at_fa'] = melkinoFormatTehranFa($e30['created_at'] ?? null);
            } catch (Throwable $e) {
                $e30['created_at_fa'] = (string) ($e30['created_at'] ?? '—');
            }
        }
        unset($e30);
    }
    foreach ($events33 as &$eFix) {
        $pf = strtolower(trim((string) ($eFix['platform'] ?? '')));
        $bid = trim((string) ($eFix['bale_id'] ?? ''));
        $tid = trim((string) ($eFix['telegram_id'] ?? ''));
        if (($pf === 'bale' || $pf === 'بله') && $bid === '' && $tid !== '') {
            $eFix['bale_id'] = $tid;
            $eFix['telegram_id'] = '';
        }
    }
    unset($eFix);
    $views33 = [];
    try {
        if (function_exists('melkinoEnsureAdViewsSchema')) {
            melkinoEnsureAdViewsSchema($pdo);
        }
        $stv = $pdo->prepare('SELECT ad_id, ad_title, viewed_at FROM ad_views WHERE user_id = ? ORDER BY viewed_at DESC LIMIT 80');
        $stv->execute([$uid]);
        $views33 = $stv->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (function_exists('melkinoFormatTehranFa')) {
            foreach ($views33 as &$vv) {
                try {
                    $vv['viewed_at_fa'] = melkinoFormatTehranFa($vv['viewed_at'] ?? null);
                } catch (Throwable $eVf) {
                    $vv['viewed_at_fa'] = (string) ($vv['viewed_at'] ?? '—');
                }
            }
            unset($vv);
        }
    } catch (Throwable $eViews) {
        $views33 = [];
    }
    $json = json_encode(['success' => true, 'events' => $events33, 'ad_views' => $views33], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"success":true,"events":[]}';
    }
    echo $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Profile synchronisation is not an alternate login or account-binding endpoint.
require_once __DIR__.'/messenger-login-lib.php';
$me=melkinoMessengerCurrentUser($pdo);
if(!$me){http_response_code(401);echo json_encode(['success'=>false,'need_login'=>true,'message'=>'ابتدا شماره را در صفحهٔ ورود تأیید کنید.'],JSON_UNESCAPED_UNICODE);exit;}
melkinoCsrfCheck();
echo json_encode(['success'=>true,'status'=>'session','user_id'=>(int)$me['id'],'name'=>$me['name'],'phone'=>$me['phone'],'platform'=>melkinoMessengerContext()],JSON_UNESCAPED_UNICODE);
