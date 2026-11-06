<?php
/**
 * Melkino Instagram Agent — JSON API (فقط پنل مدیریت)
 * خواندن: GET | تغییر: فقط POST + CSRF + نشست ادمین موجود (بدون احراز هویت موازی)
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/instagram-agent-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// هرگز اجازه نده هشدار/خطای PHP پاسخ JSON را خراب کند (پاسخ باید همیشه JSON معتبر بماند)
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$MUTATING = [
    'generate', 'save_content', 'set_status', 'schedule', 'publish_now', 'publish_manual',
    'save_settings', 'save_secrets', 'disconnect', 'run_jobs', 'enqueue',
];

if (in_array($action, $MUTATING, true)) {
    // POST + CSRF + نشست ادمین — همه با هم
    melkinoRequirePostFor($MUTATING, $action);
    // کنترل CSRF با پاسخ تمیز 403 (رفع ناسازگاری وضعیت 419 روی برخی Apacheها)
    $body = function_exists('melkinoReadRequestBody') ? melkinoReadRequestBody() : (array) $_POST;
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '') {
        $sent = (string) ($body['csrf_token'] ?? $_POST['csrf_token'] ?? '');
    }
    if ($sent === '' || !hash_equals(melkinoCsrfToken(), $sent)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'درخواست CSRF نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    melkinoRequireAdminJson();
} else {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    melkinoIgEnsureSchema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ساختار ماژول قابل ایجاد نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$actor = (string)($_SESSION['admin_name'] ?? ($_SESSION['admin_username'] ?? 'admin'));
$ok = static fn (array $data) => print(json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE) ?: '{}');
$err = static function (string $msg, int $code = 400) {
    http_response_code($code);
    print(json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE) ?: '{}');
    exit;
};

function igApiContentRow(PDO $pdo, array $row): array
{
    $row['media'] = json_decode((string)($row['media_json'] ?? '[]'), true) ?: [];
    unset($row['media_json']);
    $ad = melkinoIgGetAd($pdo, (string)$row['ad_id']);
    $row['ad_exists'] = $ad !== null;
    $row['ad_status'] = (string)($ad['status'] ?? '');
    $row['ad_title'] = (string)($ad['title'] ?? '');
    return $row;
}

try {
switch ($action) {

    /* ------------------------- خواندن ------------------------- */
    case 'overview':
        $c = static fn (string $sql, array $p = []) => (int)$pdo->prepare($sql)->execute($p) ?: 0;
        $count = static function (string $sql) use ($pdo): int {
            $st = $pdo->query($sql);
            return (int)$st->fetchColumn();
        };
        $pubCount = $count("SELECT COUNT(*) FROM ads WHERE status='published'");
        $stats = [
            'publishable_ads' => $pubCount,
            'contents_total' => $count('SELECT COUNT(*) FROM instagram_agent_contents'),
            'drafts' => $count("SELECT COUNT(*) FROM instagram_agent_contents WHERE status='draft'"),
            'pending_review' => $count("SELECT COUNT(*) FROM instagram_agent_contents WHERE status='pending_review'"),
            'scheduled' => $count("SELECT COUNT(*) FROM instagram_agent_contents WHERE status='scheduled'"),
            'published_ok' => $count("SELECT COUNT(*) FROM instagram_agent_publications WHERE status='published'"),
            'failed' => $count("SELECT COUNT(*) FROM instagram_agent_publications WHERE status='failed'"),
            'needs_attention' => $count("SELECT COUNT(*) FROM instagram_agent_contents WHERE status='needs_attention'"),
        ];
        $lastPub = $pdo->query('SELECT MAX(finished_at) FROM instagram_agent_publications')->fetchColumn();
        $conn = [];
        $secrets = melkinoIgSecretsRead();
        $conn = [
            'connected' => ($secrets['ig_access_token'] ?? '') !== '' && ($secrets['ig_user_id'] ?? '') !== '',
            'app_id_set' => ($secrets['ig_app_id'] ?? '') !== '',
            'auto_publish' => melkinoIgSettingGet($pdo, 'auto_publish', 'off'),
            'ai_provider' => ($secrets['ai_api_key'] ?? '') !== '' ? 'ai' : 'template',
            'last_publication_at' => $lastPub ? (string)$lastPub : null,
        ];
        $errors = $pdo->query(
            "SELECT p.id, p.ad_id, p.last_error_friendly, p.created_at, p.status
             FROM instagram_agent_publications p
             WHERE p.status='failed' ORDER BY p.id DESC LIMIT 8"
        )->fetchAll();
        $ok(['stats' => $stats, 'connection' => $conn, 'recent_errors' => $errors]);
        break;

    case 'ads':
        $ads = melkinoIgListPublishable($pdo, [
            'q' => trim((string)($_GET['q'] ?? '')),
            'property_type' => trim((string)($_GET['property_type'] ?? '')),
            'transaction_type' => trim((string)($_GET['transaction_type'] ?? '')),
            'location' => trim((string)($_GET['location'] ?? '')),
        ], (int)($_GET['limit'] ?? 20), (int)($_GET['offset'] ?? 0));
        $slim = array_map(static function (array $a): array {
            return [
                'id' => (string)$a['id'], 'title' => (string)$a['title'],
                'transaction_type' => (string)$a['transaction_type'], 'property_type' => (string)$a['property_type'],
                'location' => (string)$a['location'], 'price_hidden' => !empty($a['price_hidden']),
                'images' => $a['images'], 'eligibility' => $a['eligibility'], 'created_at' => (string)($a['created_at'] ?? ''),
            ];
        }, $ads);
        $ok(['ads' => $slim]);
        break;

    case 'ad':
        $adId = trim((string)($_GET['id'] ?? ''));
        $ad = melkinoIgGetAd($pdo, $adId);
        if ($ad === null) {
            $err('آگهی یافت نشد.', 404);
        }
        $images = melkinoIgAdImages($pdo, $adId);
        $pubUrls = [];
        foreach ($images as $img) {
            $u = melkinoIgMediaPublicUrl($img);
            $pubUrls[] = ['file' => $img, 'public_url' => $u, 'note' => $u ? 'آدرس عمومی قابل استفاده برای متا' : 'آدرس عمومی قابل‌اتکا نیست — انتشار دستی'];
        }
        $ok([
            'ad' => $ad,
            'eligibility' => melkinoIgAdEligibility($pdo, $ad),
            'images' => $pubUrls,
            'facts' => melkinoIgAdFacts($ad, melkinoIgPublicBaseUrl() . '/program/listing.php?id=' . rawurlencode($adId)),
        ]);
        break;

    case 'contents':
        $where = '1=1';
        $params = [];
        $st = trim((string)($_GET['status'] ?? ''));
        if ($st !== '') {
            $where .= ' AND status = :st';
            $params[':st'] = $st;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (title LIKE :q1 OR ad_id LIKE :q2)';
            $params[':q1'] = '%' . $q . '%';
            $params[':q2'] = '%' . $q . '%';
        }
        $stmt = $pdo->prepare("SELECT * FROM instagram_agent_contents WHERE {$where} ORDER BY id DESC LIMIT 60");
        $stmt->execute($params);
        $rows = array_map(static fn ($r) => igApiContentRow($pdo, $r), $stmt->fetchAll());
        $ok(['contents' => $rows]);
        break;

    case 'content':
        $stmt = $pdo->prepare('SELECT * FROM instagram_agent_contents WHERE id = ?');
        $stmt->execute([(int)($_GET['id'] ?? 0)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            $err('محتوا یافت نشد.', 404);
        }
        $ok(['content' => igApiContentRow($pdo, $row)]);
        break;

    case 'queue':
        $rows = $pdo->query(
            "SELECT c.*, p.id AS pub_id, p.status AS pub_status, p.ig_media_id
             FROM instagram_agent_contents c
             LEFT JOIN instagram_agent_publications p ON p.content_id = c.id
             WHERE c.status IN ('approved','scheduled','publishing','pending_review')
             ORDER BY c.scheduled_at IS NULL, c.scheduled_at ASC, c.id DESC LIMIT 60"
        )->fetchAll();
        $ok(['queue' => array_map(static fn ($r) => igApiContentRow($pdo, $r), $rows)]);
        break;

    case 'publications':
        $rows = $pdo->query(
            'SELECT p.*, c.title AS content_title, c.content_type
             FROM instagram_agent_publications p
             LEFT JOIN instagram_agent_contents c ON c.id = p.content_id
             ORDER BY p.id DESC LIMIT 60'
        )->fetchAll();
        $ok(['publications' => $rows]);
        break;

    case 'errors':
        $failed = $pdo->query(
            "SELECT * FROM instagram_agent_publications WHERE status='failed' ORDER BY id DESC LIMIT 40"
        )->fetchAll();
        $attention = $pdo->query(
            "SELECT id, ad_id, title, review_note, updated_at FROM instagram_agent_contents WHERE status='needs_attention' ORDER BY id DESC LIMIT 40"
        )->fetchAll();
        $ok(['failed' => $failed, 'needs_attention' => $attention]);
        break;

    case 'settings':
        $secrets = melkinoIgSecretsRead();
        $rows = $pdo->query('SELECT setting_key, setting_value, updated_by, updated_at FROM instagram_agent_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $r) {
            $settings[(string)$r['setting_key']] = (string)$r['setting_value'];
        }
        $ok([
            'settings' => $settings,
            'secrets_state' => [
                'ig_app_id' => melkinoIgMaskSecret((string)($secrets['ig_app_id'] ?? '')),
                'ig_app_secret_set' => ($secrets['ig_app_secret'] ?? '') !== '',
                'ig_access_token' => melkinoIgMaskSecret((string)($secrets['ig_access_token'] ?? '')),
                'ig_user_id' => melkinoIgMaskSecret((string)($secrets['ig_user_id'] ?? '')),
                'ai_api_key_set' => ($secrets['ai_api_key'] ?? '') !== '',
                'ai_model' => (string)($secrets['ai_model'] ?? ''),
            ],
        ]);
        break;

    case 'connection':
        $secrets = melkinoIgSecretsRead();
        $configured = ($secrets['ig_access_token'] ?? '') !== '' && ($secrets['ig_user_id'] ?? '') !== '';
        $ok([
            'connected' => $configured,
            'mode' => $configured ? 'meta-graph' : 'manual-only',
            'auto_publish' => melkinoIgSettingGet($pdo, 'auto_publish', 'off'),
            'scopes_note' => 'برای انتشار رسمی به Instagram Graph API با حساب حرفه‌ای (Business/Creator) متصل به فیس‌بوک پیج و مجوزهای instagram_basic, instagram_content_publish و pages_show_list نیاز است.',
        ]);
        break;

    /* ------------------------- تغییر ------------------------- */
    case 'generate':
        $adId = trim((string)($_POST['ad_id'] ?? ''));
        $kind = (string)($_POST['content_type'] ?? 'post');
        if (!in_array($kind, ['post', 'carousel', 'reels', 'story'], true)) {
            $err('نوع محتوا نامعتبر است.');
        }
        $ad = melkinoIgGetAd($pdo, $adId);
        if ($ad === null) {
            $err('آگهی یافت نشد.', 404);
        }
        $elig = melkinoIgAdEligibility($pdo, $ad);
        if (!$elig['eligible'] && ($_POST['force'] ?? '') !== '1') {
            $err('این آگهی شرایط انتشار ندارد: ' . implode(' ', $elig['reasons']));
        }
        $images = melkinoIgAdImages($pdo, $adId);
        $secrets = melkinoIgSecretsRead();
        $ai = null;
        if (($secrets['ai_api_key'] ?? '') !== '') {
            $ai = new MelkinoIgHttpAiProvider(
                (string)($secrets['ai_base_url'] ?? 'https://api.openai.com/v1'),
                (string)$secrets['ai_api_key'],
                (string)($secrets['ai_model'] ?? 'gpt-4o-mini')
            );
        }
        $pubUrl = melkinoIgPublicBaseUrl() . '/program/listing.php?id=' . rawurlencode($adId);
        $built = melkinoIgBuildContent($ad, $kind, [
            'tone' => (string)($_POST['tone'] ?? 'professional'),
            'audience' => (string)($_POST['audience'] ?? 'buyers'),
            'public_url' => $pubUrl,
        ], $ai);

        $media = [];
        foreach ($images as $img) {
            $media[] = ['file' => $img, 'public_url' => melkinoIgMediaPublicUrl($img)];
        }
        $hash = melkinoIgSourceHash($ad, $images);

        $st = $pdo->prepare(
            "INSERT INTO instagram_agent_contents
             (ad_id, content_type, title, hook, body_text, caption, cta, hashtags, on_image_text,
              video_scenario, alt_text, claims_check, tone, audience, media_json, source_snapshot,
              source_hash, generator, generator_model, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $adId, $kind, $built['title'], $built['hook'], $built['body_text'], $built['caption'],
            $built['cta'], $built['hashtags'], $built['on_image_text'], $built['video_scenario'],
            $built['alt_text'], $built['claims_check'], $built['tone'], $built['audience'],
            json_encode($media, JSON_UNESCAPED_UNICODE),
            json_encode($built['ad_facts'], JSON_UNESCAPED_UNICODE),
            $hash, $built['generator'], $ai ? $ai->name() : null, 'draft', $actor,
        ]);
        $cid = (int)$pdo->lastInsertId();
        melkinoIgAudit($pdo, 'content_generate', 'content', (string)$cid, 'ad:' . $adId . ' kind:' . $kind, $actor);
        $ok([
            'content_id' => $cid,
            'warnings' => $elig['warnings'],
            'text_only' => !empty($elig['text_only']),
            'media_count' => count($media),
        ]);
        break;

    case 'save_content':
        $cid = (int)($_POST['content_id'] ?? 0);
        $fields = [];
        foreach (['title', 'hook', 'body_text', 'caption', 'cta', 'hashtags', 'on_image_text', 'video_scenario', 'review_note'] as $k) {
            if (isset($_POST[$k])) {
                $fields[$k] = mb_substr(trim((string)$_POST[$k]), 0, 4000);
            }
        }
        if ($fields === []) {
            $err('چیزی برای ذخیره ارسال نشده است.');
        }
        $st = $pdo->prepare('SELECT status FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$cid]);
        $cur = (string)$st->fetchColumn();
        if ($cur === '') {
            $err('محتوا یافت نشد.', 404);
        }
        $sets = [];
        $params = [':id' => $cid];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[':' . $k] = $v;
        }
        $pdo->prepare('UPDATE instagram_agent_contents SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')->execute($params);
        melkinoIgAudit($pdo, 'content_save', 'content', (string)$cid, '', $actor);
        $ok(['saved' => true]);
        break;

    case 'set_status':
        $cid = (int)($_POST['content_id'] ?? 0);
        $to = (string)($_POST['to'] ?? '');
        if (!in_array($to, IG_STATUSES, true)) {
            $err('وضعیت مقصد نامعتبر است.');
        }
        $fields = [];
        if (isset($_POST['review_note'])) {
            $fields['review_note'] = mb_substr(trim((string)$_POST['review_note']), 0, 1000);
        }
        if ($to === 'approved' || $to === 'pending_review') {
            $fields['review_at'] = date('Y-m-d H:i:s');
            $fields['review_admin_id'] = (int)($_SESSION['admin_id'] ?? 0) ?: null;
        }
        if ($to === 'published_manual') {
            $fields['manual_link'] = mb_substr(trim((string)($_POST['manual_link'] ?? '')), 0, 600);
            $fields['published_at'] = date('Y-m-d H:i:s');
        }
        $done = melkinoIgSetContentStatus($pdo, $cid, $to, $fields, $actor);
        if (!$done) {
            $err('تغییر وضعیت از این حالت مجاز نیست یا محتوا یافت نشد.');
        }
        $ok(['status' => $to]);
        break;

    case 'schedule':
        $cid = (int)($_POST['content_id'] ?? 0);
        $when = trim((string)($_POST['scheduled_at'] ?? ''));
        if ($when === '' || strtotime($when) === false) {
            $err('زمان انتشار نامعتبر است.');
        }
        if (strtotime($when) < time() - 60) {
            $err('زمان انتشار در گذشته است.');
        }
        $done = melkinoIgSetContentStatus($pdo, $cid, 'scheduled', ['scheduled_at' => date('Y-m-d H:i:s', strtotime($when))], $actor);
        if (!$done) {
            $err('این محتوا در وضعیت فعلی قابل زمان‌بندی نیست (ابتدا باید تأیید شود).');
        }
        $srow = $pdo->query('SELECT ad_id, content_type, media_json FROM instagram_agent_contents WHERE id=' . $cid)->fetch();
        $sMode = melkinoIgContentApiCapable((string)($srow['content_type'] ?? 'post'), (string)($srow['media_json'] ?? '')) ? 'api' : 'manual';
        melkinoIgQueueEnqueue($pdo, $cid, (string)($srow['ad_id'] ?? ''), $sMode);
        $ok(['scheduled_at' => $when, 'queue_mode' => $sMode] + ($sMode === 'manual'
            ? ['note' => 'این محتوا فقط به‌صورت دستی قابل انتشار است؛ زمان‌بندی به‌عنوان یادآور ثبت شد.']
            : []));
        break;

    case 'enqueue':
        $cid = (int)($_POST['content_id'] ?? 0);
        $st = $pdo->prepare('SELECT ad_id, status, content_type, media_json FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch();
        if (!is_array($row) || !in_array((string)$row['status'], ['approved', 'scheduled'], true)) {
            $err('فقط محتوای تأییدشده قابل افزودن به صف انتشار است.');
        }
        $mode = (string)($_POST['mode'] ?? 'api');
        if (!in_array($mode, ['api', 'manual'], true)) {
            $err('حالت صف نامعتبر است.');
        }
        $capable = melkinoIgContentApiCapable((string)($row['content_type'] ?? 'post'), (string)($row['media_json'] ?? ''));
        if ($mode === 'api' && !$capable) {
            $mode = 'manual'; // انتشار API فقط پست تک‌تصویری — بقیه یادآور دستی می‌شوند
        }
        $r = melkinoIgQueueEnqueue($pdo, $cid, (string)$row['ad_id'], $mode);
        melkinoIgAudit($pdo, 'content_enqueue', 'content', (string)$cid, 'created:' . ($r['created'] ? '1' : '0') . ' mode:' . $mode, $actor);
        $ok($r + ['mode' => $mode] + ($mode === 'manual' && !$capable
            ? ['note' => 'انتشار API در نسخهٔ فعلی فقط برای پست تک‌تصویری با تصویر مجاز است؛ این محتوا به‌صورت دستی منتشر می‌شود.']
            : []));
        break;

    case 'publish_now':
        $cid = (int)($_POST['content_id'] ?? 0);
        $check = melkinoIgRevalidateContent($pdo, $cid);
        if (!$check['ok']) {
            $err('انتشار متوقف شد: ' . $check['note']);
        }
        $st = $pdo->prepare('SELECT ad_id, status, content_type, media_json FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$cid]);
        $row = $st->fetch();
        if (!is_array($row) || !in_array((string)$row['status'], ['approved', 'scheduled'], true)) {
            $err('فقط محتوای تأییدشده قابل انتشار است.');
        }
        if (!melkinoIgContentApiCapable((string)($row['content_type'] ?? 'post'), (string)($row['media_json'] ?? ''))) {
            $err('انتشار API در نسخهٔ فعلی فقط برای پست تک‌تصویری با تصویر مجاز برای انتشار عمومی است. '
                . 'کاروسل/ریلز/استوری و محتوای فقط-متن را با «ثبت انتشار دستی» منتشر کنید.');
        }
        $enq = melkinoIgQueueEnqueue($pdo, $cid, (string)$row['ad_id'], 'api');
        if (!$enq['created']) {
            $exists = $pdo->prepare("SELECT status FROM instagram_agent_publications WHERE id = ?");
            $exists->execute([$enq['publication_id']]);
            if ((string)$exists->fetchColumn() === 'published') {
                $err('این محتوا قبلاً منتشر شده است؛ از انتشار تکراری جلوگیری شد.');
            }
        }
        $secrets = melkinoIgSecretsRead();
        $publisher = new MelkinoIgMetaPublisher($secrets);
        if (!$publisher->isConfigured()) {
            $err('اتصال اینستاگرام برقرار نیست؛ از «انتشار دستی» استفاده کنید.');
        }
        $res = melkinoIgRunDuePublications($pdo, $publisher, 1);
        $ok(['result' => $res]);
        break;

    case 'publish_manual':
        $cid = (int)($_POST['content_id'] ?? 0);
        $link = mb_substr(trim((string)($_POST['manual_link'] ?? '')), 0, 600);
        if ($link !== '' && !preg_match('#^https://#i', $link)) {
            $err('لینک پست باید با https شروع شود.');
        }
        $done = melkinoIgRegisterManualPublish($pdo, $cid, $link, $actor);
        if (!$done) {
            $err('این محتوا در وضعیت فعلی قابل ثبت انتشار دستی نیست.');
        }
        $ok(['status' => 'published_manual']);
        break;

    case 'save_settings':
        $allowed = ['auto_publish', 'tone', 'audience', 'public_base_url', 'max_per_run', 'error_report_email'];
        $n = 0;
        foreach ($allowed as $k) {
            if (isset($_POST[$k])) {
                $v = trim((string)$_POST[$k]);
                if ($k === 'auto_publish' && !in_array($v, ['on', 'off'], true)) {
                    $err('مقدار انتشار خودکار فقط on/off است.');
                }
                if ($k === 'public_base_url' && $v !== '' && !preg_match('#^https://#i', $v)) {
                    $err('آدرس پایه باید https باشد.');
                }
                melkinoIgSettingSet($pdo, $k, mb_substr($v, 0, 500), $actor);
                $n++;
            }
        }
        melkinoIgAudit($pdo, 'settings_save', 'settings', '', 'keys:' . $n, $actor);
        $ok(['saved' => $n]);
        break;

    case 'save_secrets':
        $patch = [];
        foreach (['ig_app_id', 'ig_app_secret', 'ig_access_token', 'ig_user_id', 'ai_api_key', 'ai_base_url', 'ai_model'] as $k) {
            if (isset($_POST[$k])) {
                $v = trim((string)$_POST[$k]);
                if ($v !== '' && (str_contains($v, '<') || str_contains($v, "\0"))) {
                    $err('مقدار نامعتبر است.');
                }
                $patch[$k] = $v;
            }
        }
        if ($patch === []) {
            $err('چیزی برای ذخیره ارسال نشده است.');
        }
        melkinoIgSecretsWrite($patch);
        melkinoIgAudit($pdo, 'secrets_save', 'secrets', '', 'keys:' . implode(',', array_keys($patch)), $actor);
        $ok(['saved' => true]); // هرگز خود مقدار برنمی‌گردد
        break;

    case 'disconnect':
        melkinoIgSecretsWrite(['ig_access_token' => '', 'ig_user_id' => '']);
        melkinoIgAudit($pdo, 'disconnect', 'connection', '', '', $actor);
        $ok(['disconnected' => true]);
        break;

    case 'run_jobs':
        $res = melkinoIgRunDuePublications($pdo, null, (int)($_POST['max'] ?? 5));
        melkinoIgAudit($pdo, 'run_jobs', 'jobs', '', json_encode($res, JSON_UNESCAPED_UNICODE) ?: '', $actor);
        $ok(['result' => $res]);
        break;

    default:
        $err('عملیات نامعتبر است.', 404);
}
} catch (Throwable $e) {
    // هیچ خطایی نباید HTML خام شود — همیشه JSON تمیز با پیام قابل اقدام
    error_log('instagram-agent-api: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'خطای غیرمنتظرهٔ سرور رخ داد (' . mb_substr($e->getMessage(), 0, 120) . '). لطفاً دوباره تلاش کنید؛ در صورت تکرار این پیام را گزارش دهید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
