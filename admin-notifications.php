<?php
/**
|--------------------------------------------------------------------------
| اعلان‌ها (پنل ادمین): ارسال اعلان عمومی + مدیریت اعلان‌ها
|--------------------------------------------------------------------------
| هم UI تب را می‌سازد و هم endpointهای AJAX را سرو می‌دهد:
|   ?action=stats            آمار کلی (کاربران، اعلان‌ها، پیام‌های عمومی)
|   ?action=broadcast_send   ارسال اعلان عمومی به همه کاربران
|   ?action=broadcast_list   فهرست اعلان‌های عمومی ارسال‌شده
|   ?action=broadcast_delete حذف یک اعلان عمومی (و همه نسخه‌هایش)
|   ?action=recent_list      آخرین اعلان‌های کاربران
|   ?action=notif_delete     حذف یک اعلان
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

if (!function_exists('melkinoEnsureBroadcastTables')) {
    /**
     * جدول پیام‌های عمومی + ستون broadcast_id روی notifications.
     * روی هاست بدون هیچ مایگریشن دستی، با اولین بازدید تب ساخته می‌شود.
     */
    function melkinoEnsureBroadcastTables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS notification_broadcasts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                url VARCHAR(500) NULL,
                sent_count INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $columns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $columns[strtolower((string)$col['Field'])] = true;
        }
        if (!isset($columns['broadcast_id'])) {
            $pdo->exec('ALTER TABLE notifications ADD COLUMN broadcast_id INT UNSIGNED NULL, ADD KEY idx_broadcast (broadcast_id)');
        }
    }
}

$melkinoNotifAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoNotifAction !== '') {
    melkinoRequireAdminJson();
    melkinoRequirePostFor(['broadcast_send', 'broadcast_delete', 'events_save', 'notif_delete', 'notif_delete_bulk', 'notif_delete_filtered'], $melkinoNotifAction);
    require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-lib.php';

    global $pdo;
    if (!($pdo instanceof PDO)) {
        melkinoAdminJson(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
    }

    try {
        melkinoEnsureBroadcastTables($pdo);
    } catch (Throwable $e) {
        melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'admin-notifications.schema', 'آماده‌سازی جدول‌ها انجام نشد.')], 500);
    }

    switch ($melkinoNotifAction) {
        case 'stats':
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $total = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
            $unread = (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE is_read = 0')->fetchColumn();
            $broadcasts = (int)$pdo->query('SELECT COUNT(*) FROM notification_broadcasts')->fetchColumn();
            melkinoAdminJson([
                'success' => true,
                'stats' => [
                    'users' => $users,
                    'total' => $total,
                    'unread' => $unread,
                    'broadcasts' => $broadcasts,
                ],
            ]);

        case 'broadcast_send':
            $data = melkinoAdminJsonBody();
            $title = trim((string)($data['title'] ?? ''));
            $message = trim((string)($data['message'] ?? ''));
            $url = trim((string)($data['url'] ?? ''));
            if ($title === '' || $message === '') {
                melkinoAdminJson(['success' => false, 'message' => 'عنوان و متن اعلان الزامی است.'], 422);
            }
            if ($url !== '' && !preg_match('#^(https?://|home\.php|properties\.php|property-details\.php|requests\.php|my-properties\.php|my-request-matches\.php|favorites\.php|notifications\.php|contact\.php|profile\.php)#', $url)) {
                melkinoAdminJson(['success' => false, 'message' => 'لینک معتبر نیست (آدرس داخلی سایت یا https).'], 422);
            }

            $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
            $ins = $pdo->prepare('INSERT INTO notification_broadcasts (title, message, url, created_by) VALUES (?, ?, ?, ?)');
            $ins->execute([$title, $message, $url !== '' ? $url : null, $adminId]);
            $broadcastId = (int)$pdo->lastInsertId();

            // پخش به همه کاربران (هر کاربر یک ردیف جدا تا خواندن/حذف مستقل باشد)
            $fanout = $pdo->prepare(
                "INSERT INTO notifications (user_id, telegram_id, type, title, message, url, broadcast_id, is_read, created_at)
                 SELECT id, NULLIF(telegram_id, ''), 'broadcast', ?, ?, ?, ?, 0, NOW() FROM users"
            );
            $fanout->execute([$title, $message, $url !== '' ? $url : null, $broadcastId]);
            $sent = (int)$fanout->rowCount();

            $pdo->prepare('UPDATE notification_broadcasts SET sent_count = ? WHERE id = ?')->execute([$sent, $broadcastId]);

            melkinoAdminJson([
                'success' => true,
                'message' => 'اعلان عمومی برای ' . $sent . ' کاربر ارسال شد.',
                'sent' => $sent,
            ]);

        case 'broadcast_list':
            $rows = $pdo->query(
                'SELECT id, title, message, url, sent_count, created_at,
                        (SELECT COUNT(*) FROM notifications n WHERE n.broadcast_id = notification_broadcasts.id AND n.is_read = 0) AS unread_count
                 FROM notification_broadcasts ORDER BY id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
            melkinoAdminJson(['success' => true, 'broadcasts' => $rows]);

        case 'broadcast_delete':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
            }
            $pdo->prepare('DELETE FROM notifications WHERE broadcast_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM notification_broadcasts WHERE id = ?')->execute([$id]);
            melkinoAdminJson(['success' => true, 'message' => 'اعلان عمومی و همه نسخه‌هایش حذف شد.']);

        case 'recent_list':
            $rows = $pdo->query(
                'SELECT n.id, n.type, n.title, n.message, n.url, n.is_read, n.created_at,
                        n.user_id, n.telegram_id, u.name AS user_name, u.phone AS user_phone
                 FROM notifications n
                 LEFT JOIN users u ON u.id = n.user_id
                 ORDER BY n.id DESC LIMIT 50'
            )->fetchAll(PDO::FETCH_ASSOC);
            melkinoAdminJson(['success' => true, 'notifications' => $rows]);

        case 'notif_delete':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
            }
            $pdo->prepare('DELETE FROM notifications WHERE id = ?')->execute([$id]);
            melkinoAdminJson(['success' => true, 'message' => 'اعلان حذف شد.']);

        /* --------------------------------------------------------------
           حذف گروهی اعلان‌های انتخاب‌شده (چک‌باکس‌های فهرست)
           -------------------------------------------------------------- */
        case 'notif_delete_bulk':
            $data = melkinoAdminJsonBody();
            $rawIds = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];
            $ids = [];
            foreach ($rawIds as $rawId) {
                $intId = (int)$rawId;
                if ($intId > 0) {
                    $ids[$intId] = true;
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                melkinoAdminJson(['success' => false, 'message' => 'موردی انتخاب نشده است.'], 422);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare('DELETE FROM notifications WHERE id IN (' . $placeholders . ')')
                ->execute($ids);
            melkinoAdminJson([
                'success' => true,
                'message' => count($ids) . ' اعلان حذف شد.',
                'deleted' => count($ids),
            ]);

        /* --------------------------------------------------------------
           حذف بر اساس نوع (یا همه) — نوع‌ها همان کلیدهای type هستند
           -------------------------------------------------------------- */
        case 'notif_delete_filtered':
            $data = melkinoAdminJsonBody();
            $type = trim((string)($data['type'] ?? ''));
            if ($type === '' || $type === 'all') {
                $pdo->exec('DELETE FROM notifications');
                melkinoAdminJson(['success' => true, 'message' => 'همهٔ اعلان‌های کاربران حذف شد.']);
            }
            $allowed = ['welcome', 'match', 'property_match', 'broadcast', 'ad_submitted',
                'ad_published', 'ad_rejected', 'ad_revision_approved', 'ad_revision_rejected', 'ad_status', 'request_submitted',
                'request_status', 'system', 'price_condition'];
            if (!in_array($type, $allowed, true)) {
                melkinoAdminJson(['success' => false, 'message' => 'نوع اعلان معتبر نیست.'], 422);
            }
            $pdo->prepare('DELETE FROM notifications WHERE type = ?')->execute([$type]);
            melkinoAdminJson(['success' => true, 'message' => 'اعلان‌های نوع انتخاب‌شده حذف شد.']);

        case 'events_get':
            require_once __DIR__ . '/db_helpers.php';
            require_once __DIR__ . '/notification-events.php';
            $defs = melkinoNotificationEventDefs();
            $state = melkinoNotificationEventsState();
            $master = function_exists('melkinoEventsEnabled') ? melkinoEventsEnabled() : true;
            $events = [];
            foreach ($defs as $id => $def) {
                $events[] = [
                    'id'         => $id,
                    'emoji'      => $def['emoji'],
                    'title'      => $def['title'],
                    'desc'       => $def['desc'],
                    'where'      => $def['where'],
                    'channel'    => $def['channel'],
                    'toggleable' => !empty($def['toggleable']),
                    'enabled'    => !empty($state[$id]),
                ];
            }
            melkinoAdminJson(['success' => true, 'master_enabled' => $master, 'events' => $events]);

        case 'events_save':
            require_once __DIR__ . '/db_helpers.php';
            require_once __DIR__ . '/notification-events.php';
            $data = melkinoAdminJsonBody();
            $map = is_array($data['events'] ?? null) ? $data['events'] : [];
            $ok = melkinoSaveNotificationEvents($map);
            melkinoAdminJson([
                'success' => $ok,
                'message' => $ok ? 'تنظیمات رویدادهای اعلان ذخیره شد.' : 'ذخیره ناموفق بود.',
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('bell') ?> اعلان‌ها</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="loadAdminNotifications()"><?= melkinoSvgIcon('restore') ?> به‌روزرسانی</button>
    </div>
    <div style="padding:0 16px 16px;">
        <div id="notifStatsRow" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;"></div>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('gear') ?> رویدادهای اعلان سیستمی</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="loadAdminNotifEvents()"><?= melkinoSvgIcon('restore') ?> به‌روزرسانی</button>
    </div>
    <div style="padding:0 16px 16px;">
        <div class="admin-field-help" style="margin-bottom:10px;">
            فهرست کامل ارتباطات خودکار سیستم با کاربران. هر رویداد را می‌توانید جداگانه فعال یا غیرفعال کنید.
            <br>سوئیچ اصلی «اعلان‌ها» در تب <strong>تنظیمات عمومی</strong> بالادست همهٔ رویدادهاست؛ اگر خاموش باشد هیچ اعلان خودکاری ارسال نمی‌شود.
            <span id="notifEventsMasterNote" style="font-weight:700;"></span>
        </div>
        <div id="notifEventsContainer"><div class="admin-field-help">در حال بارگذاری…</div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center;">
            <button type="button" class="btn-primary" style="padding:8px 18px;font-size:13px;" onclick="saveAdminNotifEvents()"><?= melkinoSvgIcon('save') ?> ذخیرهٔ تنظیمات اعلان‌ها</button>
            <span id="notifEventsStatus" class="admin-status-msg"></span>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('megaphone') ?> ارسال اعلان عمومی</span>
    </div>
    <div style="padding:0 16px 16px;">
        <div class="admin-field-help" style="margin-bottom:10px;">
            این اعلان برای <strong>همه کاربران</strong> ارسال می‌شود و در صفحه «اعلان‌ها»ی هر کس نمایش داده می‌شود.
            هر کاربر می‌تواند نسخه خودش را بخواند یا حذف کند.
        </div>
        <label class="admin-field-label">عنوان</label>
        <input type="text" id="broadcastTitle" class="admin-input" style="width:100%;box-sizing:border-box;" placeholder="مثلاً: 🎉 جشنواره فروش ویژه ملکینو" maxlength="200">
        <label class="admin-field-label" style="margin-top:10px;display:block;">متن اعلان</label>
        <textarea id="broadcastMessage" class="admin-input" rows="3" style="width:100%;box-sizing:border-box;padding:10px;font-family:inherit;font-size:13px;resize:vertical;" placeholder="متن کامل اعلان..."></textarea>
        <label class="admin-field-label" style="margin-top:10px;display:block;">لینک (اختیاری)</label>
        <input type="text" id="broadcastUrl" class="admin-input" style="width:100%;box-sizing:border-box;" dir="ltr" placeholder="properties.php یا https://...">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center;">
            <button type="button" class="btn-primary" style="padding:8px 18px;font-size:13px;" onclick="sendBroadcast()"><?= melkinoSvgIcon('send') ?> ارسال برای همه</button>
            <span id="broadcastStatus" class="admin-status-msg"></span>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('list') ?> اعلان‌های عمومی ارسال‌شده</span>
    </div>
    <div id="broadcastsListContainer" style="padding:0 16px 16px;"></div>
</div>


