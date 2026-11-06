<?php require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/sms.php';
if (is_file(__DIR__ . '/db_helpers.php')) {
    require_once __DIR__ . '/db_helpers.php';
}

melkinoRequireAdminJson();

function leadsEnsureLog(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS lead_sms_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40) NOT NULL,
            request_id INT NULL,
            ad_id VARCHAR(64) NULL,
            user_id INT NULL,
            phone VARCHAR(30) NOT NULL,
            message TEXT NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            result_message VARCHAR(255) NULL,
            admin_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_kind (kind, created_at),
            KEY idx_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function leadsNormPhone(string $phone): string
{
    $map = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'];
    $d = strtr($phone, $map);
    $d = preg_replace('/\D+/', '', $d) ?? '';
    if (substr($d, 0, 2) === '98' && strlen($d) >= 12) {
        $d = '0' . substr($d, 2);
    }
    if (strlen($d) === 10 && substr($d, 0, 1) === '9') {
        $d = '0' . $d;
    }
    return $d;
}

function leadsSite(): string
{
        require_once __DIR__ . '/program-url.php';
        return melkinoAppUrl();
    }

function leadsLog(PDO $pdo, array $row): void
{
    leadsEnsureLog($pdo);
    $pdo->prepare(
        'INSERT INTO lead_sms_log(kind,request_id,ad_id,user_id,phone,message,success,result_message,admin_id) VALUES(?,?,?,?,?,?,?,?,?)'
    )->execute([
        $row['kind'],
        $row['request_id'] ?? null,
        $row['ad_id'] ?? null,
        $row['user_id'] ?? null,
        $row['phone'],
        $row['message'],
        !empty($row['success']) ? 1 : 0,
        $row['result_message'] ?? '',
        isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
    ]);
}

global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'دیتابیس در دسترس نیست.'], 500);
}

leadsEnsureLog($pdo);
if (function_exists('melkinoEnsureAdViewsSchema')) {
    melkinoEnsureAdViewsSchema($pdo);
}

$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = function_exists('melkinoAdminJsonBody') ? melkinoAdminJsonBody() : [];
if (!is_array($body)) {
    $body = [];
}
if ($action === '' && isset($body['action'])) {
    $action = (string)$body['action'];
}

if ($method === 'GET' && $action === 'viewers') {
    $min = max(2, min(20, (int)($_GET['min'] ?? 3)));
    $rows = [];
    try {
        $raw = $pdo->query(
            'SELECT ad_id, ad_title, user_id, telegram_id, bale_id, viewed_at
               FROM ad_views
           ORDER BY id DESC
              LIMIT 8000'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $agg = [];
        foreach ($raw as $v) {
            $adId = trim((string)($v['ad_id'] ?? ''));
            if ($adId === '') {
                continue;
            }
            $uid = (int)($v['user_id'] ?? 0);
            $tg = trim((string)($v['telegram_id'] ?? ''));
            $bale = trim((string)($v['bale_id'] ?? ''));
            $who = $uid > 0 ? ('u:' . $uid) : ($tg !== '' ? ('t:' . $tg) : ($bale !== '' ? ('b:' . $bale) : ''));
            if ($who === '') {
                continue;
            }
            $key = $adId . '|' . $who;
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'ad_id' => $adId,
                    'ad_title' => (string)($v['ad_title'] ?? ''),
                    'user_id' => $uid > 0 ? $uid : null,
                    'telegram_id' => $tg,
                    'bale_id' => $bale,
                    'phone' => '',
                    'name' => '',
                    'views' => 0,
                    'last_view' => (string)($v['viewed_at'] ?? ''),
                ];
            }
            $agg[$key]['views']++;
            if ((string)($v['viewed_at'] ?? '') > $agg[$key]['last_view']) {
                $agg[$key]['last_view'] = (string)$v['viewed_at'];
            }
            if ($agg[$key]['ad_title'] === '' && (string)($v['ad_title'] ?? '') !== '') {
                $agg[$key]['ad_title'] = (string)$v['ad_title'];
            }
        }
        $userCache = [];
        foreach ($agg as &$item) {
            $u = null;
            if (!empty($item['user_id'])) {
                $k = 'id:' . $item['user_id'];
                if (!array_key_exists($k, $userCache)) {
                    $st = $pdo->prepare('SELECT id, phone, name FROM users WHERE id=? LIMIT 1');
                    $st->execute([(int)$item['user_id']]);
                    $userCache[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $u = $userCache[$k];
            }
            if (!$u && $item['telegram_id'] !== '') {
                $k = 'tg:' . $item['telegram_id'];
                if (!array_key_exists($k, $userCache)) {
                    $st = $pdo->prepare('SELECT id, phone, name FROM users WHERE telegram_id=? LIMIT 1');
                    $st->execute([$item['telegram_id']]);
                    $userCache[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $u = $userCache[$k];
            }
            if (!$u && $item['bale_id'] !== '') {
                $k = 'bl:' . $item['bale_id'];
                if (!array_key_exists($k, $userCache)) {
                    $st = $pdo->prepare('SELECT id, phone, name FROM users WHERE bale_id=? LIMIT 1');
                    $st->execute([$item['bale_id']]);
                    $userCache[$k] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
                $u = $userCache[$k];
            }
            if ($u) {
                $item['user_id'] = (int)$u['id'];
                $item['phone'] = (string)($u['phone'] ?? '');
                $item['name'] = (string)($u['name'] ?? '');
            }
        }
        unset($item);
        $rows = array_values(array_filter($agg, static function ($r) use ($min) {
            return (int)$r['views'] >= $min;
        }));
        usort($rows, static function ($a, $b) {
            $d = ((int)$b['views']) - ((int)$a['views']);
            return $d !== 0 ? $d : strcmp((string)$b['last_view'], (string)$a['last_view']);
        });
        $rows = array_slice($rows, 0, 300);
    } catch (Throwable $e) {
        $rows = [];
    }
    melkinoAdminJson(['success' => true, 'min' => $min, 'rows' => $rows]);
}

if ($method === 'GET' && $action === 'matches') {
    $out = [];
    try {
        $sql = "SELECT pr.id AS request_id, pr.tracking_code, pr.phone, pr.last_name, pr.property_type, pr.transaction_type, pr.location,
                       COUNT(rm.id) AS match_count
                  FROM property_requests pr
             INNER JOIN request_matches rm ON rm.request_id = pr.id
              GROUP BY pr.id, pr.tracking_code, pr.phone, pr.last_name, pr.property_type, pr.transaction_type, pr.location
              ORDER BY match_count DESC, pr.id DESC
                 LIMIT 400";
        $out = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $out = [];
    }
    melkinoAdminJson(['success' => true, 'rows' => $out]);
}

if ($method === 'GET' && $action === 'log') {
    $rows = [];
    try {
        $rows = $pdo->query('SELECT * FROM lead_sms_log ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }
    melkinoAdminJson(['success' => true, 'rows' => $rows]);
}

if ($method === 'POST' && ($action === 'sms_matches' || $action === 'sms_count')) {
    $reqId = (int)($body['request_id'] ?? 0);
    if ($reqId <= 0) {
        melkinoAdminJson(['success' => false, 'message' => 'شناسه درخواست نامعتبر است.'], 400);
    }
    $st = $pdo->prepare('SELECT * FROM property_requests WHERE id=? LIMIT 1');
    $st->execute([$reqId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        melkinoAdminJson(['success' => false, 'message' => 'درخواست پیدا نشد.'], 404);
    }
    $phone = leadsNormPhone((string)($req['phone'] ?? ''));
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'شماره موبایل درخواست معتبر نیست.'], 400);
    }
    $matches = [];
    try {
        $mst = $pdo->prepare(
            'SELECT rm.ad_id, rm.match_percent, a.title
               FROM request_matches rm
          LEFT JOIN ads a ON a.id = rm.ad_id
              WHERE rm.request_id = ?
           ORDER BY rm.match_percent DESC
              LIMIT 8'
        );
        $mst->execute([$reqId]);
        $matches = $mst->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $mst = $pdo->prepare(
            'SELECT ad_id, match_percent FROM request_matches WHERE request_id = ? ORDER BY match_percent DESC LIMIT 8'
        );
        $mst->execute([$reqId]);
        $matches = $mst->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $nSt = $pdo->prepare('SELECT COUNT(*) FROM request_matches WHERE request_id = ?');
    $nSt->execute([$reqId]);
    $n = (int)$nSt->fetchColumn();
    if ($n === 0) {
        melkinoAdminJson(['success' => false, 'message' => 'فایل منطبقی برای این درخواست نیست.'], 400);
    }
    $home = leadsSite();
    $link = $home . '/my-request-matches.php';
    if ($action === 'sms_count') {
        $text = 'ملکینو: ' . $n . ' فایل مطابق با درخواست شما در سایت قرار گرفت. مشاهده: ' . $link;
    } else {
        $lines = ['ملکینو: فایل‌های منطبق با درخواست شما:'];
        foreach ($matches as $i => $m) {
            $title = trim((string)($m['title'] ?? $m['ad_id']));
            if (function_exists('mb_substr')) {
                $title = mb_substr($title, 0, 40);
            } else {
                $title = substr($title, 0, 40);
            }
            $lines[] = ($i + 1) . ') ' . $title;
        }
        $lines[] = $link;
        $text = implode("\n", $lines);
    }
    $send = smsSendText($phone, $text);
    leadsLog($pdo, [
        'kind' => $action,
        'request_id' => $reqId,
        'phone' => $phone,
        'message' => $text,
        'success' => !empty($send['success']),
        'result_message' => $send['message'] ?? '',
    ]);
    if (!empty($send['success'])) {
        try {
            $pdo->prepare('UPDATE request_matches SET is_notified=1 WHERE request_id=?')->execute([$reqId]);
        } catch (Throwable $e) {
        }
    }
    melkinoAdminJson([
        'success' => !empty($send['success']),
        'message' => $send['message'] ?? '',
        'count' => $n,
        'phone' => $phone,
    ], !empty($send['success']) ? 200 : 400);
}

if ($method === 'POST' && $action === 'sms_viewer') {
    $phone = leadsNormPhone((string)($body['phone'] ?? ''));
    $adId = trim((string)($body['ad_id'] ?? ''));
    $title = trim((string)($body['ad_title'] ?? $adId));
    $views = (int)($body['views'] ?? 0);
    if (!preg_match('/^09\d{9}$/', $phone)) {
        melkinoAdminJson(['success' => false, 'message' => 'شماره موبایل معتبر نیست.'], 400);
    }
    if ($adId === '') {
        melkinoAdminJson(['success' => false, 'message' => 'آگهی نامعتبر است.'], 400);
    }
    $url = leadsSite() . '/property-details.php?id=' . rawurlencode($adId);
    $text = 'ملکینو: آگهی «' . (function_exists('mb_substr') ? mb_substr($title, 0, 50) : substr($title, 0, 50)) . '» را چند بار دیده‌اید. مشاهده دوباره: ' . $url;
    $send = smsSendText($phone, $text);
    leadsLog($pdo, [
        'kind' => 'view_followup',
        'ad_id' => $adId,
        'user_id' => (int)($body['user_id'] ?? 0) ?: null,
        'phone' => $phone,
        'message' => $text,
        'success' => !empty($send['success']),
        'result_message' => $send['message'] ?? '',
    ]);
    melkinoAdminJson([
        'success' => !empty($send['success']),
        'message' => $send['message'] ?? '',
        'phone' => $phone,
        'views' => $views,
    ], !empty($send['success']) ? 200 : 400);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
