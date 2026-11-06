<?php
/**
 * ------------------------------------------------------------------------
 * ملکینو — ماژول «انتشار» — API (JSON)
 * ------------------------------------------------------------------------
 * GET  action=overview|queue|history|campaigns|rules|channels|channels_registry|stats|calendar|ad_search|ad_media|ad_preview|links
 * POST action=create|publish_now|reschedule|cancel|retry|bulk|content_save|rule_save|edit_post|delete_post|run_due|campaign_cancel|channel_save|channel_delete|ad_photo_add
 * همهٔ نوشتن‌ها: POST + CSRF + ادمین. نتایج از بک‌اند واقعی؛ بدون دادهٔ ساختگی.
 * ------------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';
require_once __DIR__ . '/jalali-lib.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/publish-center-lib.php';

melkinoRequireAdminJson();
global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], 500);
}
melkinoPublishEnsureSchema($pdo);

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
if ($isPost) {
    try {
        melkinoCsrfCheck();
    } catch (Throwable $e) {
        melkinoAdminJson(['success' => false, 'message' => 'توکن امنیتی (CSRF) معتبر نیست؛ صفحه را تازه‌سازی کنید.'], 403);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }
} else {
    $body = [];
}

$actor = (string)($_SESSION['admin_user'] ?? $_SESSION['admin_name'] ?? ($_SESSION['is_admin'] ?? false ? 'admin' : 'admin'));

function melkinoPublishAdRow(PDO $pdo, string $adId): ?array
{
    try {
        $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        $st->execute([$adId]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);
        return $ad ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** نتایج مبتنی بر «ok» را با کلید «success» هم سازگار می‌کند (انتظار UI). */
function melkinoPublishApiResult($res): array
{
    if (!is_array($res)) {
        return ['success' => false, 'message' => 'پاسخ نامعتبر.'];
    }
    if (!array_key_exists('success', $res)) {
        $res['success'] = (bool)($res['ok'] ?? false);
    }
    return $res;
}

/* ======================= سازندهٔ محتوا (مشترک بین action ها) ======================= */

switch ($action) {

    /* ======================= نمای کلی ======================= */
    case 'overview':
        try {
            $stats = $pdo->query(
                "SELECT
                    SUM(status='published') AS published,
                    SUM(status='partial') AS partial,
                    SUM(status='failed') AS failed,
                    SUM(status='needs_review') AS needs_review,
                    SUM(status IN ('pending','queued','sending')) AS active,
                    COUNT(*) AS total
                 FROM publish_items"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $today = $pdo->query(
                "SELECT COUNT(*) AS n FROM publish_targets
                  WHERE status IN ('pending','queued')
                    AND COALESCE(scheduled_at, (SELECT scheduled_at FROM publish_items WHERE id = item_id)) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
            )->fetchColumn();
            $byPlatform = $pdo->query(
                "SELECT platform, COUNT(*) AS total,
                        SUM(status='published') AS published,
                        SUM(status IN ('failed','needs_review')) AS bad
                   FROM publish_targets GROUP BY platform"
            )->fetchAll(PDO::FETCH_ASSOC);
            $clicks = $pdo->query("SELECT COUNT(*) AS total, COUNT(DISTINCT token) AS links FROM publish_clicks")->fetch(PDO::FETCH_ASSOC) ?: [];
            $recent = melkinoPublishHistoryRows($pdo, []);
            melkinoAdminJson([
                'success' => true,
                'paused' => melkinoPublishPaused($pdo),
                'stats' => $stats + ['scheduled_today' => (int)$today],
                'by_platform' => $byPlatform,
                'clicks' => $clicks,
                'recent' => array_slice($recent, 0, 12),
                'schema' => MELKINO_PUBLISH_SCHEMA,
            ]);
        } catch (Throwable $e) {
            melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish.overview', 'خواندن آمار انجام نشد.')], 500);
        }
        break;

    /* ======================= تقویم ======================= */
    case 'calendar':
        // شبکهٔ تقویم کاملاً سمت سرور ساخته می‌شود (تنها منبع حقیقت تبدیل تاریخ: jalali-lib)
        $y = (int)($_GET['y'] ?? 0);
        $m = (int)($_GET['m'] ?? 0);
        if ($y < 1300) {
            [$gy, $gm] = [intval(date('Y')), intval(date('n'))];
            [$y, $m] = melkinoGregorianToJalali($gy, $gm, 1);
        }
        $m = max(1, min(12, $m));
        [$gy1, $gm1, $gd1] = melkinoJalaliToGregorian($y, $m, 1);
        [$gy2, $gm2, $gd2] = melkinoJalaliToGregorian($m === 12 ? $y + 1 : $y, $m === 12 ? 1 : $m + 1, 1);
        $from = sprintf('%04d-%02d-%02d 00:00:00', $gy1, $gm1, $gd1);
        $to = sprintf('%04d-%02d-%02d 00:00:00', $gy2, $gm2, $gd2);
        $st = $pdo->prepare(
            "SELECT t.id AS target_id, t.platform, t.status AS target_status,
                    COALESCE(t.scheduled_at, i.scheduled_at) AS sched_at,
                    i.id AS item_id, i.kind, i.ad_id, i.title
               FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
              WHERE COALESCE(t.scheduled_at, i.scheduled_at) >= ? AND COALESCE(t.scheduled_at, i.scheduled_at) < ?
              ORDER BY COALESCE(t.scheduled_at, i.scheduled_at) ASC"
        );
        $st->execute([$from, $to]);
        $eventsByDay = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $day = substr((string)$ev['sched_at'], 0, 10);
            $eventsByDay[$day][] = $ev;
        }
        // ساخت شبکه: از شنبهٔ هفتهٔ حاوی اول ماه تا پایان هفته
        [$fwGy, $fwGm, $fwGd] = melkinoJalaliToGregorian($y, $m, 1);
        $firstTs = mktime(12, 0, 0, $fwGm, $fwGd, $fwGy);
        $dow = (int)date('w', $firstTs); // 0=یکشنبه … 6=شنبه
        $offsetToSat = ($dow + 1) % 7;   // شنبه = روز اول هفتهٔ ایران
        $startTs = strtotime('-' . $offsetToSat . ' days', $firstTs);
        [$ny, $nm, $nd] = melkinoJalaliToGregorian($m === 12 ? $y + 1 : $y, $m === 12 ? 1 : $m + 1, 1);
        $endTs = mktime(12, 0, 0, $nm, $nd, $ny);
        $endTs = strtotime('-1 day', $endTs);
        $endDow = (int)date('w', $endTs);
        $endTs = strtotime('+' . ((6 - $endDow + 1) % 7) . ' days', $endTs);
        $cells = [];
        for ($ts = $startTs; $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
            $iso = date('Y-m-d', $ts);
            [$cjy, $cjm, $cjd] = melkinoGregorianToJalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
            $cells[] = [
                'iso' => $iso,
                'jy' => $cjy, 'jm' => $cjm, 'jd' => $cjd,
                'out' => ((int)$cjm !== $m || (int)$cjy !== $y),
                'today' => ($iso === date('Y-m-d')),
                'events' => $eventsByDay[$iso] ?? [],
            ];
        }
        melkinoAdminJson(['success' => true, 'jy' => $y, 'jm' => $m, 'cells' => $cells, 'tz' => melkinoTzLabel()]);
        break;

    /* ======================= صف ======================= */
    case 'queue':
        // متن خودکار مقصدهای در صف را در محیط مدیر (با رندرر کامل) همگام کن؛ اگر قبلاً ناقص شده باشد، اصلاح می‌شود
        try { melkinoPublishRefreshAutoTexts($pdo); } catch (Throwable $e) { }
        $rows = $pdo->query(
            "SELECT t.*, i.title, i.kind, i.ad_id, i.campaign_id
               FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
              WHERE t.status IN ('pending','queued','sending','needs_review','failed')
              ORDER BY COALESCE(t.scheduled_at, i.scheduled_at) ASC, t.id ASC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        melkinoAdminJson(['success' => true, 'queue' => $rows]);
        break;

    /* ======================= تاریخچه ======================= */
    case 'history':
        // فیلتر تاریخ به شمسی (۱۴۰۵/۰۷/۰۱) وارد می‌شود؛ اینجا به میلادی تبدیل می‌شود
        $fromIn = trim((string)($_GET['from'] ?? ''));
        $toIn = trim((string)($_GET['to'] ?? ''));
        $fromSql = $fromIn !== '' ? (melkinoParseJalaliDateTime($fromIn) ?: ($fromIn . ' 00:00:00')) : null;
        $toSql = $toIn !== '' ? (melkinoParseJalaliDateTime($toIn) ?: ($toIn . ' 00:00:00')) : null;
        if ($toSql !== null) {
            $toSql = date('Y-m-d 23:59:59', strtotime($toSql));
        }
        melkinoAdminJson([
            'success' => true,
            'history' => melkinoPublishHistoryRows($pdo, [
                'ad_id' => trim((string)($_GET['ad_id'] ?? '')) ?: null,
                'platform' => trim((string)($_GET['platform'] ?? '')) ?: null,
                'action' => trim((string)($_GET['action_filter'] ?? '')) ?: null,
                'status' => trim((string)($_GET['status'] ?? '')) ?: null,
                'from' => $fromSql,
                'to' => $toSql,
            ]),
        ]);
        break;

    /* ======================= کانال‌ها و اتصالات ======================= */
    case 'channels':
        $caps = melkinoPublishCapabilities();
        $out = [];
        foreach (['telegram', 'bale', 'eitaa'] as $p) {
            $token = melkinoPublishToken($p);
            $chat = melkinoPublishChannelId($p);
            $last = null;
            try {
                $st = $pdo->prepare("SELECT action, status, detail, created_at FROM publish_history WHERE platform = ? AND action IN ('test_connection','publish') ORDER BY id DESC LIMIT 1");
                $st->execute([$p]);
                $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
            }
            $out[] = [
                'platform' => $p,
                'label' => melkinoPublishPlatformLabel($p),
                'configured' => ($token !== '' && $chat !== ''),
                'channel_id' => $chat,
                'capabilities' => $caps[$p],
                'last_activity' => $last,
            ];
        }
        melkinoAdminJson(['success' => true, 'channels' => $out]);
        break;

    /* ======================= عملکرد و آمار بازدید ======================= */
    case 'stats':
        try {
            $clicks = $pdo->query(
                "SELECT platform, COUNT(*) AS clicks, COUNT(DISTINCT token) AS links, MAX(clicked_at) AS last_click
                   FROM publish_clicks GROUP BY platform"
            )->fetchAll(PDO::FETCH_ASSOC);
            $targets = $pdo->query(
                "SELECT t.id, t.platform, t.message_ids, t.published_at, i.title, i.ad_id
                   FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
                  WHERE t.status = 'published' ORDER BY t.published_at DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
            melkinoAdminJson([
                'success' => true,
                'views' => [
                    'telegram' => ['supported' => false, 'note' => melkinoPublishCapabilities()['telegram']['views_note']],
                    'bale' => ['supported' => false, 'note' => melkinoPublishCapabilities()['bale']['views_note']],
                    'eitaa' => ['supported' => false, 'note' => melkinoPublishCapabilities()['eitaa']['views_note']],
                ],
                'clicks' => $clicks,
                'published' => $targets,
            ]);
        } catch (Throwable $e) {
            melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish.stats', 'خواندن آمار انجام نشد.')], 500);
        }
        break;

    /* ======================= کانال‌های ثبت‌شده (چند کانال در هر پیام‌رسان) ======================= */
    case 'channels_registry':
        $reg = ['telegram' => [], 'bale' => [], 'eitaa' => []];
        foreach (melkinoPublishChannelsList($pdo) as $r) {
            $p = (string)$r['platform'];
            if (isset($reg[$p])) {
                $reg[$p][] = [
                    'id' => (int)$r['id'],
                    'channel_id' => (string)$r['channel_id'],
                    'title' => (string)$r['title'],
                    'is_default' => (int)$r['is_default'] === 1,
                ];
            }
        }
        melkinoAdminJson(['success' => true, 'registry' => $reg]);
        break;

    case 'channel_save':
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        melkinoAdminJson(melkinoPublishChannelSave($pdo, is_array($body) ? $body : [], $actor));
        break;

    case 'channel_delete':
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        melkinoAdminJson(melkinoPublishChannelDelete($pdo, (int)($body['id'] ?? 0), $actor));
        break;

    /* ======================= آپلود تصویر دلخواه برای انتشار آگهی ======================= */
    case 'ad_photo_add':
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $adId = trim((string)($_POST['ad_id'] ?? ($body['ad_id'] ?? '')));
        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی نیست.'], 422);
        }
        if (!melkinoPublishAdRow($pdo, $adId)) {
            melkinoAdminJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
        }
        $f = $_FILES['file'] ?? null;
        if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            melkinoAdminJson(['success' => false, 'message' => 'فایل تصویر دریافت نشد.'], 422);
        }
        if ((int)$f['size'] > 8 * 1024 * 1024) {
            melkinoAdminJson(['success' => false, 'message' => 'حجم تصویر حداکثر ۸ مگابایت است.'], 422);
        }
        $ext = strtolower((string)pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            melkinoAdminJson(['success' => false, 'message' => 'فرمت تصویر پشتیبانی نمی‌شود (jpg/png/webp/gif).'], 422);
        }
        $bytes = (string)file_get_contents((string)$f['tmp_name']);
        melkinoAdminJson(melkinoPublishAdPhotoStore($pdo, $adId, $bytes, $ext, (string)($f['type'] ?? '')));
        break;

    /* ======================= جست‌وجوی آگهی ======================= */
    case 'ad_search':
        $q = trim((string)($_GET['q'] ?? ''));
        // scope=published: the bulk-schedule picker lists only published ads (other callers unchanged).
        $pubSql = (($_GET['scope'] ?? '') === 'published') ? " AND status IN ('published','active')" : '';
        try {
            if ($q !== '') {
                $like = '%' . $q . '%';
                $st = $pdo->prepare(
                    "SELECT id, title, status, transaction_type, property_type, location, area, price_sell, deposit, rent_monthly
                       FROM ads
                      WHERE (id LIKE ? OR title LIKE ? OR location LIKE ? OR address LIKE ?)" . $pubSql . "
                      ORDER BY COALESCE(updated_at, published_at, created_at) DESC, created_at DESC LIMIT 200"
                );
                $st->execute([$like, $like, $like, $like]);
            } else {
                $st = $pdo->query(
                    "SELECT id, title, status, transaction_type, property_type, location, area, price_sell, deposit, rent_monthly
                       FROM ads WHERE 1=1" . $pubSql . "
                      ORDER BY COALESCE(updated_at, published_at, created_at) DESC, created_at DESC LIMIT 200"
                );
            }
            $adsRows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($adsRows as &$ar) {
                $cSt = $pdo->prepare("SELECT COUNT(*) FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE i.ad_id = ? AND t.status = 'published'");
                $cSt->execute([(string)$ar['id']]);
                $ar['published_count'] = (int)$cSt->fetchColumn();
                $ar['pub_state'] = $ar['published_count'] > 0 ? 'published' : 'none';
                // نخستین تصویر آگهی برای نمایش کارت/نتایج جست‌وجو (مسیر قابل نمایش در پنل)
                $imgs = melkinoPublishAdImages($pdo, (string)$ar['id'], true);
                $ar['image'] = $imgs ? (string)$imgs[0]['url'] : '';
            }
            unset($ar);
            melkinoAdminJson(['success' => true, 'ads' => $adsRows]);
        } catch (Throwable $e) {
            melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'publish.search', 'جست‌وجو انجام نشد.')], 500);
        }
        break;

    /* ======================= رسانهٔ آگهی ======================= */
    case 'ad_media':
        $adId = trim((string)($_GET['ad_id'] ?? ''));
        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی نیست.'], 422);
        }
        melkinoAdminJson(['success' => true, 'media' => melkinoPublishAdImages($pdo, $adId, true)]);
        break;

    /* ======================= پیش‌نمایش (متن + برنامهٔ پیام‌ها) ======================= */
    case 'ad_preview':
        $adId = trim((string)($_GET['ad_id'] ?? ''));
        $platform = trim((string)($_GET['platform'] ?? 'bale'));
        $ad = melkinoPublishAdRow($pdo, $adId);
        if (!$ad) {
            melkinoAdminJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
        }
        $media = melkinoPublishAdImages($pdo, $adId, true);
        $text = melkinoPublishAdText($ad, $platform, null, true);
        $tplId = (int)($_GET['template_id'] ?? 0);
        if ($tplId > 0) {
            $stT = $pdo->prepare('SELECT * FROM publish_templates WHERE id = ?');
            $stT->execute([$tplId]);
            $tpl = $stT->fetch(PDO::FETCH_ASSOC);
            if ($tpl) {
                $rendered = melkinoPublishTemplateRender((string)$tpl['body'], $ad, ['footer' => (string)($tpl['footer'] ?? '')]);
                if ($rendered !== '') {
                    $text = $rendered . ((string)($tpl['footer'] ?? '') !== '' ? "\n\n" . (string)$tpl['footer'] : '');
                }
            }
        }
        [$plan, $notes] = melkinoPublishPlan($platform, melkinoPublishChannelId($platform), $text, $media);
        melkinoAdminJson([
            'success' => true,
            'text' => $text,
            'media' => $media,
            'message_count' => count($plan),
            'notes' => $notes,
            'capabilities' => melkinoPublishCapabilities()[$platform] ?? [],
        ]);
        break;

    /* ======================= ایجاد برنامه/انتشار ======================= */
    case 'pause_state': {
        melkinoAdminJson(['success' => true, 'paused' => melkinoPublishPaused($pdo)]);
    }
    case 'publish_pause': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        melkinoAdminJson(melkinoPublishSetPaused($pdo, true, $actor) + ['message' => 'انتشار سراسری متوقف شد.']);
    }
    case 'publish_resume': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        melkinoAdminJson(melkinoPublishSetPaused($pdo, false, $actor) + ['message' => 'انتشار از سر گرفته شد؛ برنامه‌ها طبق زمان‌بندی اجرا می‌شوند.']);
    }
    case 'backup': {
        $data = melkinoPublishBackup($pdo);
        melkinoAdminJson(['success' => true, 'backup' => $data, 'counts' => $data['counts']]);
    }
    case 'restore': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        if (empty($body['confirmed'])) {
            melkinoAdminJson(['success' => false, 'message' => 'بازیابی، داده‌های فعلی برنامهٔ انتشار را جایگزین می‌کند؛ تأیید لازم است.'], 422);
        }
        $file = is_array($body['backup'] ?? null) ? $body['backup'] : (is_array($body['data'] ?? null) ? $body['data'] : null);
        if (!$file && is_string($body['json'] ?? null)) {
            $file = json_decode((string)$body['json'], true);
        }
        if (!is_array($file)) {
            melkinoAdminJson(['success' => false, 'message' => 'فایل پشتیبان ارسال نشده است.'], 422);
        }
        melkinoAdminJson(melkinoPublishRestore($pdo, $file, $actor));
    }
    case 'xlsx_export': {
        $st = $pdo->query("SELECT t.platform, t.status, t.scheduled_at, t.attempts, t.last_error, i.created_at, i.id AS item_id, i.kind, i.ad_id, i.title, i.body FROM publish_targets t JOIN publish_items i ON i.id = t.item_id ORDER BY i.id DESC, t.id DESC");
        $rows = [];
        $statusFa = ['queued' => 'در صف', 'scheduled' => 'زمان‌بندی‌شده', 'draft' => 'پیش‌نویس', 'sending' => 'در حال ارسال',
            'published' => 'منتشرشده', 'failed' => 'ناموفق', 'needs_review' => 'نیاز به بررسی', 'partial' => 'ناقص',
            'cancelled' => 'لغوشده', 'deleted' => 'حذف‌شده', 'banned' => 'مسدود', 'rights' => 'خطای دسترسی', 'timeout' => 'مهلت'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                $r['item_id'], $r['kind'] === 'ad' ? 'آگهی' : 'محتوا', (string)$r['title'], (string)$r['ad_id'],
                melkinoPublishPlatformLabel((string)$r['platform']), $statusFa[(string)$r['status']] ?? (string)$r['status'],
                melkinoPublishJalaliStr($r['scheduled_at'] ?: $r['created_at']), (string)($r['scheduled_at'] ?? ''),
                (string)($r['created_at'] ?? ''), (string)($r['attempts'] ?? '0'),
                mb_substr((string)($r['last_error'] ?? ''), 0, 200), mb_substr((string)($r['body'] ?? ''), 0, 300),
            ];
        }
        $headers = ['کد برنامه', 'نوع', 'عنوان', 'شناسه آگهی', 'پیام‌رسان', 'وضعیت', 'زمان ارسال (شمسی)', 'زمان ارسال (میلادی)', 'زمان ثبت', 'تلاش‌ها', 'آخرین خطا', 'متن (خلاصه)'];
        $bytes = melkinoPublishXlsxBuild($headers, $rows);
        melkinoAdminJson(['success' => true, 'filename' => 'melkino-publish-' . date('Ymd-His') . '.xlsx',
            'xlsx_base64' => base64_encode($bytes), 'rows' => count($rows)]);
    }
    case 'xlsx_import': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $rows = null;
        $upload = $_FILES['file'] ?? null;
        if (is_array($upload) && ($upload['error'] ?? 1) === UPLOAD_ERR_OK) {
            if ((int)$upload['size'] > 4 * 1024 * 1024) {
                melkinoAdminJson(['success' => false, 'message' => 'حجم فایل بیش از ۴ مگابایت است.'], 422);
            }
            $bytes = (string)file_get_contents((string)$upload['tmp_name']);
            $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                $rows = melkinoPublishCsvParse($bytes);
            } elseif ($ext === 'xlsx' || substr($bytes, 0, 2) === 'PK') {
                $rows = melkinoPublishXlsxParse($bytes);
            } else {
                melkinoAdminJson(['success' => false, 'message' => 'فقط فایل xlsx یا csv پذیرفته می‌شود.'], 422);
            }
        } elseif (is_string($body['xlsx_base64'] ?? null)) {
            $rows = melkinoPublishXlsxParse(base64_decode((string)$body['xlsx_base64']));
        } elseif (is_string($body['csv'] ?? null)) {
            $rows = melkinoPublishCsvParse((string)$body['csv']);
        } elseif (is_array($body['rows'] ?? null)) {
            $rows = array_map('array_values', $body['rows']);
        }
        if (!is_array($rows) || !$rows) {
            melkinoAdminJson(['success' => false, 'message' => 'فایل/داده‌ای برای ورود دریافت نشد یا خالی بود.'], 422);
        }
        $header = array_map(static fn($h) => mb_strtolower(trim((string)$h)), array_shift($rows));
        $idxOf = static function (array $names) use ($header): int {
            foreach ($header as $i => $h) {
                foreach ($names as $n) {
                    if (mb_strpos($h, $n) !== false) {
                        return $i;
                    }
                }
            }
            return -1;
        };
        $iTitle = $idxOf(['عنوان', 'title']);
        $iText = $idxOf(['متن', 'body', 'text']);
        $iPlat = $idxOf(['پیام‌رسان', 'پیامرسان', 'platform', 'کانال']);
        $iWhen = $idxOf(['زمان ارسال', 'زمان', 'when', 'scheduled']);
        $iAd = $idxOf(['شناسه آگهی', 'آگهی', 'ad_id', 'ad id']);
        $platMap = ['تلگرام' => 'telegram', 'telegram' => 'telegram', 'بله' => 'bale', 'bale' => 'bale', 'ایتا' => 'eitaa', 'eitaa' => 'eitaa'];
        $created = 0;
        $errors = [];
        foreach ($rows as $ri => $row) {
            if (!is_array($row) || !array_filter($row, static fn($v) => trim((string)$v) !== '')) {
                continue;
            }
            $line = $ri + 2;
            $title = $iTitle >= 0 ? trim((string)($row[$iTitle] ?? '')) : '';
            $text = $iText >= 0 ? trim((string)($row[$iText] ?? '')) : '';
            $whenRaw = $iWhen >= 0 ? trim((string)($row[$iWhen] ?? '')) : '';
            $adId = $iAd >= 0 ? trim((string)($row[$iAd] ?? '')) : '';
            $platRaw = $iPlat >= 0 ? trim((string)($row[$iPlat] ?? '')) : 'تلگرام';
            $plats = [];
            foreach (preg_split('/[،,\/|]+/u', $platRaw) ?: [] as $one) {
                $one = mb_strtolower(trim($one));
                if (isset($platMap[$one])) {
                    $plats[$platMap[$one]] = true;
                }
            }
            if (!$plats) {
                $errors[] = 'سطر ' . $line . ': پیام‌رسان ناشناخته است (' . $platRaw . ').';
                continue;
            }
            if ($title === '' && $text === '') {
                $errors[] = 'سطر ' . $line . ': عنوان یا متن لازم است.';
                continue;
            }
            $when = $whenRaw !== '' ? (melkinoParseJalaliDateTime($whenRaw) ?? melkinoParseJalaliDateTime(str_replace('T', ' ', $whenRaw))) : null;
            if ($whenRaw !== '' && !$when) {
                $errors[] = 'سطر ' . $line . ': زمان نامعتبر است (' . $whenRaw . ').';
                continue;
            }
            $ad = $adId !== '' ? melkinoPublishAdRow($pdo, $adId) : null;
            if ($adId !== '' && !$ad) {
                $errors[] = 'سطر ' . $line . ': آگهی ' . $adId . ' پیدا نشد.';
                continue;
            }
            $targetsIn = [];
            foreach (array_keys($plats) as $pl) {
                $targetsIn[] = ['platform' => $pl, 'text' => $text, 'media_mode' => 'none', 'media' => []];
            }
            melkinoPublishCreateItem($pdo, $ad, $targetsIn, $when ? $when->format('Y-m-d H:i:s') : null, 'admin:import', null, 0, ['title' => $title ?: mb_substr($text, 0, 60), 'body' => $text]);
            $created++;
        }
        melkinoPublishHistory($pdo, null, null, null, 'import', 'success', 'ورود اکسل/CSV: ' . $created . ' برنامه ساخته شد' . ($errors ? '؛ ' . count($errors) . ' سطر رد شد' : '') . '.', null, $actor);
        melkinoAdminJson(['success' => true, 'created' => $created, 'errors' => $errors,
            'message' => $created . ' برنامهٔ انتشار وارد شد.' . ($errors ? ' (' . count($errors) . ' سطر با خطا رد شد)' : '')]);
    }

    case 'dist_rules': {
        $rules = melkinoDistRules($pdo);
        foreach ($rules as &$r) {
            $m = $r['match'];
            $r['match_summary'] = trim(
                ($m['transaction_types'] ? implode('، ', $m['transaction_types']) : 'همهٔ معاملات') . ' · ' .
                ($m['property_types'] ? implode('، ', $m['property_types']) : 'همهٔ املاک') .
                (!empty($m['is_vip']) ? ' · فقط ویژه' : (isset($m['is_vip']) && $m['is_vip'] === false ? ' · فقط غیرویژه' : '')) .
                (!empty($m['city']) ? ' · شهر: ' . $m['city'] : '') .
                (!empty($m['neighborhood']) ? ' · محله: ' . $m['neighborhood'] : '') .
                ((!empty($m['price_min']) || !empty($m['price_max'])) ? ' · قیمت: ' . ($m['price_min'] ?: '…') . '–' . ($m['price_max'] ?: '…') : '') .
                ((!empty($m['area_min']) || !empty($m['area_max'])) ? ' · متراژ: ' . ($m['area_min'] ?: '…') . '–' . ($m['area_max'] ?: '…') : '')
            );
            unset($r['match_json']);
            $r['match_count'] = 0;
            $adsNow = $pdo->query("SELECT * FROM ads WHERE status = 'published' LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($adsNow as $adRow) {
                [$okM] = melkinoDistMatchRule($adRow, $r['match']);
                if ($okM) {
                    $r['match_count']++;
                }
            }
        }
        melkinoAdminJson(['success' => true, 'rules' => $rules]);
    }
    case 'dist_rule_save': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        melkinoAdminJson(melkinoDistRuleSave($pdo, is_array($body['rule'] ?? null) ? $body['rule'] : $body, $actor));
    }
    case 'dist_rule_delete': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        if (empty($body['confirmed'])) {
            melkinoAdminJson(['success' => false, 'message' => 'حذف قانون نیاز به تأیید دارد (برنامه‌های موجود حذف نمی‌شوند).'], 422);
        }
        $rid = (int)($body['id'] ?? 0);
        $rule = melkinoDistRules($pdo, $rid);
        if (!$rule) {
            melkinoAdminJson(['success' => false, 'message' => 'قانون پیدا نشد.'], 404);
        }
        $pdo->prepare('DELETE FROM publish_dist_rules WHERE id = ?')->execute([$rid]);
        melkinoPublishHistory($pdo, null, null, $rule[0]['platform'], 'dist_rule_delete', 'success',
            "حذف قانون توزیع #{$rid} «{$rule[0]['name']}» — برنامه‌ها و تاریخچه‌های موجود دست‌نخورده ماندند.", null, $actor);
        melkinoAdminJson(['success' => true, 'message' => 'قانون حذف شد؛ برنامه‌های موجود حذف نشدند.']);
    }
    case 'dist_rule_toggle': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $rid = (int)($body['id'] ?? 0);
        $rule = melkinoDistRules($pdo, $rid);
        if (!$rule) {
            melkinoAdminJson(['success' => false, 'message' => 'قانون پیدا نشد.'], 404);
        }
        $new = $rule[0]['enabled'] ? 0 : 1;
        $pdo->prepare('UPDATE publish_dist_rules SET enabled = ? WHERE id = ?')->execute([$new, $rid]);
        melkinoPublishHistory($pdo, null, null, $rule[0]['platform'], 'dist_rule_toggle', 'success',
            ($new ? 'فعال‌سازی' : 'غیرفعال‌سازی') . " قانون توزیع #{$rid} «{$rule[0]['name']}» — برنامه‌های موجود تغییر نکردند.", null, $actor);
        melkinoAdminJson(['success' => true, 'enabled' => $new, 'message' => $new ? 'قانون فعال شد.' : 'قانون غیرفعال شد؛ برنامه‌های موجود حذف نشدند.']);
    }
    case 'dist_preview': {
        $draft = is_array($body['rule'] ?? null) ? $body['rule'] : [];
        if (!$draft && !empty($body['id'])) {
            $rr = melkinoDistRules($pdo, (int)$body['id']);
            $draft = $rr[0] ?? [];
        }
        if (!$draft) {
            melkinoAdminJson(['success' => false, 'message' => 'قانونی برای پیش‌نمایش ارسال نشده است.'], 422);
        }
        $draft['match'] = $draft['match'] ?? json_decode((string)($draft['match_json'] ?? '{}'), true) ?: [];
        $draft['name'] = (string)($draft['name'] ?? 'قانون جدید');
        $prev = melkinoDistPreviewRule($pdo, $draft);
        melkinoAdminJson(['success' => true] + $prev);
    }
    case 'dist_evaluate': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $adId = trim((string)($body['ad_id'] ?? ''));
        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی لازم است.'], 422);
        }
        melkinoAdminJson(melkinoDistEvaluate($pdo, $adId, $actor, isset($body['rule_id']) ? (int)$body['rule_id'] : null, !empty($body['preview']) ? false : true));
    }
    case 'dist_evaluate_all': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $ids = $pdo->query("SELECT id FROM ads WHERE status = 'published' ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $adId) {
            melkinoDistEvaluate($pdo, (string)$adId, $actor);
            $n++;
        }
        melkinoAdminJson(['success' => true, 'evaluated' => $n, 'message' => $n . ' آگهی تأییدشده ارزیابی شد.']);
    }
    case 'dist_status': {
        $adId = trim((string)($_GET['ad_id'] ?? $body['ad_id'] ?? ''));
        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی لازم است.'], 422);
        }
        melkinoAdminJson(['success' => true] + melkinoDistStatus($pdo, $adId));
    }
    case 'dist_unmatched': {
        melkinoAdminJson(['success' => true, 'ads' => melkinoDistUnmatched($pdo)]);
    }
    case 'upload_media': {
        if (!$isPost) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط با POST.'], 405);
        }
        $up = $_FILES['file'] ?? null;
        if (!is_array($up) || ($up['error'] ?? 1) !== UPLOAD_ERR_OK) {
            melkinoAdminJson(['success' => false, 'message' => 'فایلی دریافت نشد.'], 422);
        }
        if ((int)$up['size'] > 8 * 1024 * 1024) {
            melkinoAdminJson(['success' => false, 'message' => 'حجم فایل بیش از ۸ مگابایت است.'], 422);
        }
        $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4'], true)) {
            melkinoAdminJson(['success' => false, 'message' => 'فقط jpg/png/webp/gif/mp4 پذیرفته می‌شود.'], 422);
        }
        $mime = (string)($up['type'] ?? '');
        $allow = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4'];
        if (!in_array($mime, $allow, true)) {
            // برخی وب‌ویوها mime خالی/نامعتبر می‌فرستند؛ بر اساس پسوند قضاوت کن
            if ($ext === 'mp4') {
                $mime = 'video/mp4';
            } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $mime = $ext === 'jpg' ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/gif'));
            }
        }
        if (!in_array($mime, $allow, true)) {
            melkinoAdminJson(['success' => false, 'message' => 'نوع فایل معتبر نیست.'], 422);
        }
        $dir = dirname(__DIR__) . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = 'content_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file((string)$up['tmp_name'], $dir . '/' . $name)) {
            melkinoAdminJson(['success' => false, 'message' => 'ذخیرهٔ فایل ناموفق بود.'], 500);
        }
        $isVideo = $mime === 'video/mp4';
        melkinoAdminJson(['success' => true, 'filename' => $name, 'url' => '../uploads/' . $name,
            'storage_path' => 'uploads/' . $name, 'kind' => $isVideo ? 'video' : 'image',
            'message' => ($isVideo ? 'ویدیو' : 'تصویر') . ' «' . $name . '» با موفقیت آپلود شد.']);
    }

    case 'create':
    case 'bulk':
    case 'content_save': {
        $kind = $action === 'content_save' ? 'content' : 'ad';
        $adId = trim((string)($body['ad_id'] ?? ''));
        $title = trim((string)($body['title'] ?? ''));
        $bulkIds = is_array($body['ad_ids'] ?? null) ? array_map('strval', $body['ad_ids']) : [];
        if ($kind === 'ad' && $action !== 'bulk' && $adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'آگهی انتخاب نشده است.'], 422);
        }
        if ($action === 'bulk' && !$bulkIds) {
            melkinoAdminJson(['success' => false, 'message' => 'هیچ آگهی‌ای انتخاب نشده است.'], 422);
        }
        $targetsIn = is_array($body['targets'] ?? null) ? $body['targets'] : [];
        if (!$targetsIn) {
            melkinoAdminJson(['success' => false, 'message' => 'حداقل یک پیام‌رسان انتخاب کنید.'], 422);
        }
        $isDraft = !empty($body['draft']);
        $whenDefault = $isDraft ? null : (melkinoParseJalaliDateTime((string)($body['scheduled_at'] ?? '')) ?? null);
        // زمان کلی (خالی = فوری): نامعتبر یا گذشته یعنی ارسال فوری بی‌صدا؛ پیش از هر نوشتنی رد می‌شود
        $topAt = trim((string)($body['scheduled_at'] ?? ''));
        if (!$isDraft && $topAt !== '') {
            $topParsed = melkinoParseJalaliDateTime($topAt);
            if ($topParsed === null) {
                melkinoAdminJson(['success' => false, 'message' => 'تاریخ/ساعت شمسی معتبر نیست (مثال: ۱۴۰۵/۰۷/۲۰ ۱۸:۰۰).'], 422);
            }
            if (strtotime($topParsed) < time() - 60) {
                melkinoAdminJson(['success' => false, 'message' => 'زمان انتخابی در گذشته است (به وقت ایران).'], 422);
            }
        }
        // زمان جداگانهٔ هر کانال: نامعتبر یا گذشته یعنی ارسال فوری بی‌صدا؛ پیش از هر نوشتنی رد می‌شود
        if (!$isDraft) {
            foreach ($targetsIn as $tIn) {
                $tAt = trim((string)($tIn['scheduled_at'] ?? ''));
                if ($tAt === '') {
                    continue;
                }
                $tParsed = melkinoParseJalaliDateTime($tAt);
                if ($tParsed === null) {
                    melkinoAdminJson(['success' => false, 'message' => 'زمان ' . (string)($tIn['platform'] ?? '') . ' معتبر نیست.'], 422);
                }
                if (strtotime($tParsed) < time() - 60) {
                    melkinoAdminJson(['success' => false, 'message' => 'زمان ' . (string)($tIn['platform'] ?? '') . ' در گذشته است (به وقت ایران).'], 422);
                }
            }
        }
        $interval = max(0, (int)($body['interval_seconds'] ?? 0));
        $campaignId = null;
        if ($action === 'bulk') {
            $pdo->prepare('INSERT INTO publish_campaigns (title, interval_seconds, starts_at, created_by) VALUES (?,?,?,?)')
                ->execute([trim((string)($body['campaign_title'] ?? ('انتشار گروهی ' . date('Y/m/d')))) ?: 'انتشار گروهی', $interval > 0 ? $interval : 120, $whenDefault, $actor]);
            $campaignId = (int)$pdo->lastInsertId();
            melkinoPublishHistory($pdo, null, null, null, 'create_campaign', 'info', 'ایجاد انتشار گروهی با ' . count($bulkIds) . ' آگهی × ' . count($targetsIn) . ' کانال', null, $actor, $campaignId);
            $created = 0;
            $offset = 0;
            foreach ($bulkIds as $bid) {
                $ad = melkinoPublishAdRow($pdo, $bid);
                if (!$ad) {
                    melkinoPublishHistory($pdo, null, $bid, null, 'create', 'failed', 'آگهی پیدا نشد؛ از گروه حذف شد.', null, $actor, $campaignId);
                    continue;
                }
                $itemId = melkinoPublishCreateItem($pdo, $ad, $targetsIn, $whenDefault, $actor, $campaignId, $offset, $body);
                $created += $itemId ? 1 : 0;
                $offset += $interval > 0 ? $interval : 120;
            }
            melkinoAdminJson(['success' => true, 'campaign_id' => $campaignId, 'created' => $created, 'message' => $created . ' آگهی در انتشار گروهی زمان‌بندی شد.']);
            break;
        }

        $ad = $kind === 'ad' ? melkinoPublishAdRow($pdo, $adId) : null;
        if ($kind === 'ad' && !$ad) {
            melkinoAdminJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
        }
        $itemId = melkinoPublishCreateItem($pdo, $ad, $targetsIn, $whenDefault, $actor, null, 0, $body);
        if (!$itemId) {
            melkinoAdminJson(['success' => false, 'message' => 'ثبت انجام نشد.'], 500);
        }
        melkinoAdminJson(['success' => true, 'item_id' => $itemId, 'message' => 'برنامهٔ انتشار ثبت شد.']);
        break;
    }

    /* ======================= انتشار فوری ======================= */
    case 'publish_now': {
        $itemId = (int)($body['item_id'] ?? 0);
        $targetIds = is_array($body['target_ids'] ?? null) ? array_map('intval', $body['target_ids']) : [];
        if (!$itemId && !$targetIds) {
            melkinoAdminJson(['success' => false, 'message' => 'موردی برای انتشار نیست.'], 422);
        }
        if (!$targetIds) {
            $st = $pdo->prepare("SELECT id FROM publish_targets WHERE item_id = ? AND status IN ('pending','queued','failed','needs_review')");
            $st->execute([$itemId]);
            $targetIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $results = [];
        foreach ($targetIds as $tid) {
            $results[] = ['target_id' => $tid] + melkinoPublishSendTarget($pdo, $tid, null, $actor);
        }
        melkinoAdminJson(['success' => true, 'results' => $results]);
        break;
    }

    /* ======================= لغو / زمان‌بندی مجدد / تلاش مجدد ======================= */
    case 'cancel':
        melkinoAdminJson(melkinoPublishApiResult(melkinoPublishCancelTarget($pdo, (int)($body['target_id'] ?? 0), $actor)));
        break;

    case 'reschedule': {
        $when = melkinoParseJalaliDateTime((string)($body['scheduled_at'] ?? ''));
        if (!$when) {
            melkinoAdminJson(['success' => false, 'message' => 'تاریخ/ساعت شمسی معتبر نیست (مثال: ۱۴۰۵/۰۷/۲۰ ۱۸:۰۰).'], 422);
        }
        // زمان گذشته برای مقصد یعنی ارسال فوری توسط cron؛ رد می‌شود
        if (strtotime($when) < time() - 60) {
            melkinoAdminJson(['success' => false, 'message' => 'زمان انتخابی در گذشته است (به وقت ایران).'], 422);
        }
        melkinoAdminJson(melkinoPublishApiResult(melkinoPublishReschedule($pdo, (int)($body['target_id'] ?? 0), $when, $actor)));
        break;
    }

    case 'retry': {
        $tid = (int)($body['target_id'] ?? 0);
        $r = melkinoPublishSendTarget($pdo, $tid, null, $actor, true);
        melkinoAdminJson(melkinoPublishApiResult($r));
        break;
    }

    /* ======================= حذف گروهی از صف ======================= */
    case 'cancel_bulk': {
        $ids = is_array($body['target_ids'] ?? null) ? array_values(array_unique(array_map('intval', $body['target_ids']))) : [];
        $ids = array_slice(array_values(array_filter($ids, static fn($v) => $v > 0)), 0, 500);
        if (!$ids) {
            melkinoAdminJson(['success' => false, 'message' => 'موردی برای حذف انتخاب نشده است.'], 422);
        }
        $cancelled = 0;
        $skipped = [];
        foreach ($ids as $tid) {
            $stS = $pdo->prepare('SELECT status FROM publish_targets WHERE id = ?');
            $stS->execute([$tid]);
            $status = (string)$stS->fetchColumn();
            // «sending» را هرگز لغو نمی‌کنیم: ارسال در حال انجام است و وضعیتش بعداً مشخص می‌شود
            if ($status === 'sending' || $status === '') {
                $skipped[] = $tid;
                continue;
            }
            $r = melkinoPublishCancelTarget($pdo, $tid, $actor);
            if (!empty($r['ok'])) {
                $cancelled++;
            } else {
                $skipped[] = $tid;
            }
        }
        $msg = $cancelled . ' مورد از صف حذف شد.';
        if ($skipped) {
            $msg .= ' ' . count($skipped) . ' مورد حذف نشد (در حال ارسال، منتشرشده یا نامعتبر).';
        }
        melkinoAdminJson(['success' => true, 'cancelled' => $cancelled, 'skipped' => count($skipped), 'message' => $msg]);
        break;
    }

    /* ======================= ویرایش/حذف پست ======================= */
    case 'edit_post':
        melkinoAdminJson(melkinoPublishApiResult(melkinoPublishEditOrDelete($pdo, (int)($body['target_id'] ?? 0), 'edit', (string)($body['text'] ?? ''), $actor)));
        break;

    case 'delete_post':
        melkinoAdminJson(melkinoPublishApiResult(melkinoPublishEditOrDelete($pdo, (int)($body['target_id'] ?? 0), 'delete', '', $actor)));
        break;

    /* ======================= قوانین اتوماسیون ======================= */
    case 'rules':
        melkinoAdminJson(['success' => true, 'rules' => $pdo->query('SELECT * FROM publish_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'rule_save': {
        $event = trim((string)($body['event_key'] ?? ''));
        $act = trim((string)($body['action_key'] ?? 'none'));
        if (!in_array($event, ['sold', 'rented', 'inactive', 'changed', 'expired', 'deleted'], true) || !in_array($act, ['edit_post', 'delete_post', 'flag', 'none'], true)) {
            melkinoAdminJson(['success' => false, 'message' => 'مقادیر قانون معتبر نیست.'], 422);
        }
        $pdo->prepare('UPDATE publish_rules SET action_key = ?, enabled = ?, config = ? WHERE event_key = ?')
            ->execute([$act, !empty($body['enabled']) ? 1 : 0, json_encode(['append_text' => trim((string)($body['append_text'] ?? ''))], JSON_UNESCAPED_UNICODE), $event]);
        melkinoPublishHistory($pdo, null, null, null, 'rule_save', 'info', 'قانون «' . $event . '» → «' . $act . '»' . (!empty($body['enabled']) ? ' (فعال)' : ' (غیرفعال)'), null, $actor);
        melkinoAdminJson(['success' => true, 'message' => 'قانون ذخیره شد.']);
        break;
    }

    /* ======================= اجرای صف (Worker دستی) ======================= */
    case 'run_due': {
        $max = max(1, min(20, (int)($body['max'] ?? 5)));
        $r = melkinoPublishRunDue($pdo, $max, null, $actor . ':manual');
        melkinoAdminJson($r);
        break;
    }

    /* ======================= لغو کمپین ======================= */
    case 'campaign_cancel': {
        $cid = (int)($body['campaign_id'] ?? 0);
        $pdo->prepare("UPDATE publish_items SET status='cancelled' WHERE campaign_id = ? AND status IN ('draft','scheduled','queued')")->execute([$cid]);
        $pdo->prepare("UPDATE publish_targets SET status='cancelled' WHERE item_id IN (SELECT id FROM publish_items WHERE campaign_id = ?) AND status IN ('pending','queued')")->execute([$cid]);
        $pdo->prepare("UPDATE publish_campaigns SET status='cancelled' WHERE id = ?")->execute([$cid]);
        melkinoPublishHistory($pdo, null, null, null, 'cancel_campaign', 'info', 'لغو کمپین #' . $cid, null, $actor, $cid);
        melkinoAdminJson(['success' => true, 'message' => 'کمپین لغو شد.']);
        break;
    }

    /* ======================= قالب‌های محتوایی ======================= */
    case 'templates':
        melkinoAdminJson(['success' => true, 'templates' => melkinoPublishTemplates($pdo, trim((string)($_GET['platform'] ?? '')), trim((string)($_GET['kind'] ?? '')))]);
        break;

    case 'template_save':
        melkinoAdminJson(melkinoPublishTemplateSave($pdo, $body, $actor));
        break;

    case 'template_delete':
        melkinoAdminJson(melkinoPublishTemplateDelete($pdo, (int)($body['id'] ?? 0), $actor));
        break;

    /* ======================= تست اتصال واقعی ======================= */
    case 'test_connection': {
        $platform = trim((string)($body['platform'] ?? ''));
        $kind = in_array(($body['kind'] ?? 'bot'), ['bot', 'channel', 'message'], true) ? $body['kind'] : 'bot';
        if (!in_array($platform, ['telegram', 'bale', 'eitaa'], true)) {
            melkinoAdminJson(['success' => false, 'message' => 'پیام‌رسان نامعتبر است.'], 422);
        }
        $scope = $platform === 'eitaa' ? 'eitaa_channel' : $platform;
        try {
            $r = melkinoTestBotSection($pdo, $scope, (string)$kind, [], null);
            melkinoPublishHistory($pdo, null, null, $platform, 'test_connection', !empty($r['success']) ? 'success' : 'failed',
                'تست ' . ($kind === 'bot' ? 'اتصال API' : 'کانال') . ': ' . (string)($r['message'] ?? ''), null, $actor);
            melkinoAdminJson(['success' => true, 'result' => $r]);
        } catch (Throwable $e) {
            $msg = $e instanceof MelkinoBotSettingsError ? implode('؛ ', array_values($e->getFields() ?: [$e->getMessage()])) : melkinoSafeError($e, 'publish.test_connection', 'تست اتصال انجام نشد.');
            melkinoPublishHistory($pdo, null, null, $platform, 'test_connection', 'failed', 'تست اتصال: ' . $msg, null, $actor);
            melkinoAdminJson(['success' => false, 'message' => $msg]);
        }
        break;
    }

    /* ======================= پیام‌های آگهی + حذف گروهی ======================= */
    case 'ad_posts': {
        $apId = trim((string)($_GET['ad_id'] ?? ''));
        melkinoAdminJson(['success' => true, 'posts' => melkinoPublishAdPosts($pdo, $apId),
            'dist' => $apId !== '' ? melkinoDistStatus($pdo, $apId) : null]);
        break;
    }
        break;

    case 'delete_ad_posts': {
        $adId = trim((string)($body['ad_id'] ?? ''));
        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسهٔ آگهی نیست.'], 422);
        }
        if (empty($body['confirmed'])) {
            $posts = melkinoPublishAdPosts($pdo, $adId);
            $msgCount = 0;
            foreach ($posts as $pp) {
                $msgCount += (int)$pp['message_count'];
            }
            melkinoAdminJson(['success' => true, 'needs_confirmation' => true, 'posts' => $posts, 'message' => count($posts) . ' پست (' . $msgCount . ' پیام) حذف خواهد شد. تأیید کنید.']);
        }
        $ids = is_array($body['target_ids'] ?? null) ? array_map('intval', $body['target_ids']) : [];
        melkinoAdminJson(melkinoPublishDeleteAdPosts($pdo, $adId, $ids, null, $actor));
        break;
    }

    /* ======================= ویرایش محتوا (با تاریخچه) ======================= */
    case 'content_edit': {
        $itemId = (int)($body['item_id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM publish_items WHERE id = ?');
        $st->execute([$itemId]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) {
            melkinoAdminJson(['success' => false, 'message' => 'محتوا پیدا نشد.'], 404);
        }
        $title = trim((string)($body['title'] ?? '')) ?: (string)$it['title'];
        $bodyText = (string)($body['body'] ?? $it['body']);
        $pdo->prepare('UPDATE publish_items SET title = ?, body = ? WHERE id = ?')
            ->execute([mb_substr($title, 0, 255), $bodyText, $itemId]);
        melkinoPublishHistory($pdo, $itemId, $it['ad_id'], null, 'content_edit', 'info', 'ویرایش محتوا (متن پایه تغییر کرد؛ اسنپ‌شات مقاصد منتشرشده دست‌نخورده ماند).', null, $actor);
        melkinoAdminJson(['success' => true, 'message' => 'محتوا ویرایش شد.']);
        break;
    }

    /* ======================= زمان‌بندی پیش‌نویس ======================= */
    case 'schedule_draft': {
        $itemId = (int)($body['item_id'] ?? 0);
        $when = melkinoParseJalaliDateTime((string)($body['scheduled_at'] ?? ''));
        if (!$when) {
            melkinoAdminJson(['success' => false, 'message' => 'تاریخ/ساعت شمسی معتبر نیست.'], 422);
        }
        $pdo->prepare("UPDATE publish_items SET status = 'scheduled', scheduled_at = ? WHERE id = ? AND status = 'draft'")->execute([$when, $itemId]);
        $pdo->prepare("UPDATE publish_targets SET status = 'pending', scheduled_at = ? WHERE item_id = ? AND scheduled_at IS NULL")->execute([$when, $itemId]);
        melkinoPublishHistory($pdo, $itemId, null, null, 'schedule', 'info', 'پیش‌نویس برای ' . $when . ' زمان‌بندی شد.', null, $actor);
        melkinoAdminJson(['success' => true, 'message' => 'پیش‌نویس زمان‌بندی شد.']);
        break;
    }

    /* ======================= فهرست فیلترشده برای کارت‌های آمار ======================= */
    case 'overview_list': {
        $st = $pdo->prepare(
            "SELECT t.id AS target_id, t.platform, t.status AS target_status, t.scheduled_at, t.published_at, t.attempts, t.last_error,
                    i.id AS item_id, i.title, i.kind, i.ad_id, i.status AS item_status
               FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
              WHERE (? = '' OR t.status = ?)
                AND (? = '' OR t.platform = ?)
                AND (? = '' OR COALESCE(t.scheduled_at, i.scheduled_at) >= ?)
                AND (? = '' OR COALESCE(t.scheduled_at, i.scheduled_at) <= ?)
              ORDER BY COALESCE(t.scheduled_at, i.scheduled_at) DESC LIMIT 200"
        );
        $fStatus = trim((string)($_GET['status'] ?? ''));
        $fPlat = trim((string)($_GET['platform'] ?? ''));
        $fromSql = trim((string)($_GET['from'] ?? '')) !== '' ? (melkinoParseJalaliDateTime(trim((string)$_GET['from'])) ?: (string)$_GET['from']) : '';
        $toSql = trim((string)($_GET['to'] ?? '')) !== '' ? date('Y-m-d 23:59:59', strtotime(melkinoParseJalaliDateTime(trim((string)$_GET['to'])) ?: (string)$_GET['to'])) : '';
        $st->execute([$fStatus, $fStatus, $fPlat, $fPlat, $fromSql, $fromSql, $toSql, $toSql]);
        melkinoAdminJson(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        break;
    }

    default:
        melkinoAdminJson(['success' => false, 'message' => 'action نامعتبر است.'], 422);
}
