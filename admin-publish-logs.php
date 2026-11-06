<?php require_once __DIR__ . '/config.php';
/**
|--------------------------------------------------------------------------
| لاگ انتشار آگهی در کانال‌ها (راند ۱۸)
|--------------------------------------------------------------------------
| GET ?action=list&ad_id=...  → فهرست تلاش‌های انتشار یک آگهی (جدیدترین اول)
| GET ?action=counts          → تعداد انتشار موفق هر آگهی (برای بج‌ها)
| جدول channel_publish_logs در صورت نبود، خودکار ساخته می‌شود.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';

melkinoRequireAdminJson();

global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], 500);
}

// اطمینان از وجود جدول لاگ
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS channel_publish_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ad_id VARCHAR(40) NOT NULL,
            platform VARCHAR(10) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            message_id VARCHAR(60) NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cpl_ad (ad_id),
            INDEX idx_cpl_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (Throwable $e) {
    melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish-logs.schema', 'جدول لاگ در دسترس نیست.')], 500);
}

$action = strtolower(trim((string)($_GET['action'] ?? 'list')));

if ($action === 'counts') {
    try {
        $rows = $pdo->query(
            'SELECT ad_id, platform, SUM(success = 1) AS total, COUNT(*) AS attempts,
                    MAX(CASE WHEN success = 1 THEN created_at END) AS last_success
             FROM channel_publish_logs
             GROUP BY ad_id, platform'
        )->fetchAll(PDO::FETCH_ASSOC);
        try {
            $extra=$pdo->query("SELECT ad_id, 'eitaa' AS platform, SUM(status='published') AS total, COUNT(*) AS attempts, MAX(CASE WHEN status='published' THEN created_at END) AS last_success FROM melkino_eitaa_publish_attempts GROUP BY ad_id")->fetchAll(PDO::FETCH_ASSOC);
            $rows=array_merge($rows,$extra);
        } catch (Throwable $missingEitaaTable) {}
        melkinoAdminJson(['success' => true, 'counts' => $rows]);
    } catch (Throwable $e) {
        melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish-logs.list', 'خواندن لاگ انجام نشد.')], 500);
    }
}

$adId = trim((string)($_GET['ad_id'] ?? ''));
if ($adId === '' || mb_strlen($adId) > 64) {
    melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی معتبر نیست.'], 422);
}

// جدول تاریخچهٔ مدیریتی (ویرایش/تأیید/تعلیق/VIP/...) — در صورت نبود خودکار ساخته می‌شود
try {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS ads_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad_id VARCHAR(64) NOT NULL,
            action VARCHAR(60) NOT NULL,
            detail VARCHAR(500) NULL,
            actor VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ah_ad (ad_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (Throwable $e) {
}

try {
    $st = $pdo->prepare(
        'SELECT id, platform, success, message_id, note, created_at, UNIX_TIMESTAMP(created_at) AS created_epoch
         FROM channel_publish_logs
         WHERE ad_id = ?
         ORDER BY id DESC
         LIMIT 100'
    );
    $st->execute([$adId]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
    try {
        $eitaa=$pdo->prepare("SELECT id, 'eitaa' AS platform, (status='published') AS success, status, message_id, note, channel_id, actor, created_at, started_epoch AS created_epoch FROM melkino_eitaa_publish_attempts WHERE ad_id=? ORDER BY id DESC LIMIT 100");
        $eitaa->execute([$adId]);
        foreach ($eitaa->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status']==='sending' && time()-(int)$row['created_epoch']>120) {
                $row['status']='unknown';
                $row['note']='تأیید نهایی این تلاش ثبت نشده است؛ قبل از تکرار، کانال را بررسی کنید.';
            }
            $logs[]=$row;
        }
        usort($logs,static fn($a,$b)=>((int)$b['created_epoch']<=>(int)$a['created_epoch']) ?: ((int)$b['id']<=>(int)$a['id']));
        $logs=array_slice($logs,0,100);
    } catch (Throwable $missingEitaaTable) {}

    $history = [];
    try {
        $h = $pdo->prepare(
            'SELECT id, action, detail, actor, created_at
             FROM ads_history
             WHERE ad_id = ?
             ORDER BY id DESC
             LIMIT 150'
        );
        $h->execute([$adId]);
        $history = $h->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
    }
    melkinoAdminJson(['success' => true, 'logs' => $logs, 'history' => $history]);
} catch (Throwable $e) {
    melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish-logs.op', 'عملیات انجام نشد.')], 500);
}
