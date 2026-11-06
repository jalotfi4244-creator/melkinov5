<?php
/**
 * استودیوی استوری ملکینو — JSON API (فقط پنل مدیریت)
 * خواندن: GET | تغییر: فقط POST + CSRF + نشست ادمین موجود (بدون احراز هویت موازی)
 * خروجی فایل: export_file با نشست ادمین (پوشه‌ی storage مستقیماً قابل دسترسی نیست)
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/ig-studio-lib.php';

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

// exportFile یک فایل باینری برمی‌گرداند؛ بقیه‌ی پاسخ‌ها JSON هستند
if ($action !== 'export_file') {
    header('Content-Type: application/json; charset=utf-8');
}
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (!($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$MUTATING = ['save_project', 'project_restore', 'delete_project', 'duplicate_project', 'toggle_favorite', 'save_profile', 'save_export', 'delete_export', 'send_to_agent', 'save_logo', 'delete_logo', 'sample_upload', 'sample_delete', 'save_default_photo', 'clear_default_photo', 'template_save', 'template_delete', 'font_save', 'font_delete', 'brand_save', 'brand_delete', 'brand_logo_save'];

if (in_array($action, $MUTATING, true)) {
    melkinoRequirePostFor($MUTATING, $action);
    melkinoRequireAdminJson(); // نشست ادمین + CSRF (هدر X-CSRF-Token)
} else {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

igsEnsureSchema($pdo);
$base = igsBaseUrl();
$by = (string)($_SESSION['admin_username'] ?? $_SESSION['username'] ?? 'admin');

/** بدنه‌ی JSON برای درخواست‌های POST */
$body = [];
if (in_array($action, $MUTATING, true) && !in_array($action, ['save_export', 'save_logo', 'sample_upload', 'font_save', 'brand_logo_save'], true)) {
    // ارسال urlencoded با فیلد payload (JSON) — همان روش ایجنت موجود برای سازگاری با هاست/WAF
    $payloadRaw = $_POST['payload'] ?? null;
    if (is_string($payloadRaw)) {
        $decoded = json_decode($payloadRaw, true);
        $body = is_array($decoded) ? $decoded : [];
    } else {
        $body = melkinoAdminJsonBody();
    }
}

function igsOk(array $payload = [], int $status = 200): void
{
    melkinoAdminJson(['success' => true] + $payload, $status);
}

function igsFail(string $msg, int $status = 400): void
{
    melkinoAdminJson(['success' => false, 'message' => $msg], $status);
}

try {
    switch ($action) {

        case 'bootstrap':
            igsOk([
                'brand' => igsBrand($pdo),
                'profile' => igsProfileGet($pdo),
                'canvas' => ['w' => IGS_CANVAS_W, 'h' => IGS_CANVAS_H],
                'base' => $base,
            ]);
            break;

        case 'ads':
            $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100, 'UTF-8');
            $limit = max(1, min(60, (int)($_GET['limit'] ?? 30)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            igsOk(igsAdsList($pdo, $q, $limit, $offset));
            break;

        case 'ad':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '' || strlen($id) > 64) {
                igsFail('شناسه آگهی نامعتبر است.');
            }
            $ad = igsAdDetail($pdo, $id, $base);
            if (!$ad) {
                igsFail('آگهی پیدا نشد.', 404);
            }
            igsOk(['ad' => $ad]);
            break;

        case 'projects':
            $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100, 'UTF-8');
            igsOk(['items' => igsProjectsList($pdo, $q, !empty($_GET['fav']), $by)]);
            break;

        case 'project':
            $id = (int)($_GET['id'] ?? 0);
            $r = $id > 0 ? igsProjectOwned($pdo, $id, $by) : null;
            if (!$r) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            igsOk(['project' => igsProjectPublic($r, true)]);
            break;

        case 'exports':
            igsOk(['items' => igsExportsList($pdo, 100)]);
            break;

        case 'export_file':
            $id = (int)($_GET['id'] ?? 0);
            $f = $id > 0 ? igsExportPath($pdo, $id) : null;
            if (!$f) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'فایل خروجی پیدا نشد.';
                exit;
            }
            header('Content-Type: ' . $f['mime']);
            header('Content-Length: ' . (string)filesize($f['path']));
            header('Cache-Control: private, no-store');
            $dl = !empty($_GET['download']);
            $fname = 'melkino-story-' . $id . '.' . igsMimeExt((string)$f['mime']);
            header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . $fname . '"');
            readfile($f['path']);
            exit;

        case 'get_profile':
            igsOk(['profile' => igsProfileGet($pdo)]);
            break;

        /* ---------- تغییرات (POST + CSRF) ---------- */

        case 'save_project':
            $id = (int)($body['id'] ?? 0);
            if ($id > 0 && !igsProjectOwned($pdo, $id, $by)) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            $row = igsProjectSave($pdo, $body, $id ?: null, $by);
            igsOk(['project' => $row]);
            break;

        case 'delete_project':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0 || !igsProjectOwned($pdo, $id, $by)) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            // خروجی‌های ذخیره‌شده (آرشیو) حفظ می‌شوند
            $pdo->prepare('DELETE FROM ig_studio_projects WHERE id = ? AND created_by = ?')->execute([$id, mb_substr($by, 0, 120, 'UTF-8')]);
            $pdo->prepare('DELETE FROM ig_studio_project_versions WHERE project_id = ?')->execute([$id]);
            igsOk(['deleted' => $id]);
            break;

        case 'duplicate_project':
            $id = (int)($body['id'] ?? 0);
            $r = $id > 0 ? igsProjectOwned($pdo, $id, $by) : null;
            if (!$r) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            $title = mb_substr((string)$r['title'] . ' (کپی)', 0, 200, 'UTF-8');
            $st = $pdo->prepare('INSERT INTO ig_studio_projects (title, ad_id, template_id, state_json, created_by) VALUES (?,?,?,?,?)');
            $st->execute([$title, $r['ad_id'], $r['template_id'], $r['state_json'], mb_substr($by, 0, 120, 'UTF-8')]);
            igsOk(['project' => igsProjectPublic(igsProjectRow($pdo, (int)$pdo->lastInsertId()), true)]);
            break;

        case 'toggle_favorite':
            $id = (int)($body['id'] ?? 0);
            $r = $id > 0 ? igsProjectOwned($pdo, $id, $by) : null;
            if (!$r) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            $pdo->prepare('UPDATE ig_studio_projects SET is_favorite = IF(is_favorite = 1, 0, 1) WHERE id = ? AND created_by = ?')->execute([$id, mb_substr($by, 0, 120, 'UTF-8')]);
            igsOk(['project' => igsProjectPublic(igsProjectRow($pdo, $id), false)]);
            break;

        case 'project_versions':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0 || !igsProjectOwned($pdo, $id, $by)) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            igsOk(['items' => igsVersionsList($pdo, $id)]);
            break;

        case 'project_restore':
            $id = (int)($body['id'] ?? 0);
            $vid = (int)($body['version_id'] ?? 0);
            $restored = $id > 0 && $vid > 0 ? igsProjectRestore($pdo, $id, $vid, $by) : null;
            if (!$restored) {
                igsFail('نسخه‌ی مورد نظر پیدا نشد.', 404);
            }
            igsOk(['project' => $restored]);
            break;

        case 'save_profile':
            igsOk(['profile' => igsProfileSave($pdo, is_array($body['profile'] ?? null) ? $body['profile'] : $body)]);
            break;

        case 'templates_list':
            igsOk(['items' => igsMyTemplatesList($pdo, $by)]);
            break;

        case 'template_save':
            igsOk(['template' => igsMyTemplateSave($pdo, $body, $by)]);
            break;

        case 'template_delete':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0 || !igsMyTemplateDelete($pdo, $id, $by)) {
                igsFail('قالب پیدا نشد.', 404);
            }
            igsOk(['deleted' => $id]);
            break;

        case 'save_export':
            $projectId = (int)($_POST['project_id'] ?? 0);
            $adId = substr(trim((string)($_POST['ad_id'] ?? '')), 0, 64);
            if ($projectId > 0 && !igsProjectRow($pdo, $projectId)) {
                igsFail('پروژه پیدا نشد.', 404);
            }
            if (empty($_FILES['file'])) {
                igsFail('فایل خروجی دریافت نشد.');
            }
            igsOk(['export' => igsExportSave($pdo, $_FILES['file'], $projectId ?: null, $adId, $by)]);
            break;

        case 'backgrounds':
            igsOk(['items' => igsBackgroundList($base)]);
            break;

        case 'save_default_photo':
            $adId = substr(trim((string)($body['ad_id'] ?? '')), 0, 64);
            $url = trim((string)($body['url'] ?? ''));
            $ad = $adId !== '' ? igsAdDetail($pdo, $adId, $base) : null;
            if (!$ad) {
                igsFail('آگهی پیدا نشد.', 404);
            }
            $ok = in_array($url, array_column($ad['photos'], 'url'), true) || igsBackgroundUrlOk($url, $base);
            if (!$ok) {
                igsFail('این عکس برای ذخیره‌ی پیش‌فرض مجاز نیست.', 422);
            }
            dbSettingSet($pdo, 'ig_studio', 'default_photo_' . $adId, $url, 'string', null);
            igsOk(['ad_id' => $adId, 'default_photo' => $url]);
            break;

        case 'clear_default_photo':
            $adId = substr(trim((string)($body['ad_id'] ?? '')), 0, 64);
            if ($adId === '') {
                igsFail('شناسه‌ی آگهی لازم است.');
            }
            dbSettingSet($pdo, 'ig_studio', 'default_photo_' . $adId, '', 'string', null);
            igsOk(['ad_id' => $adId, 'default_photo' => '']);
            break;

        case 'samples_state':
            igsOk(['types' => igsSampleState($base)]);
            break;

        case 'sample_upload':
            if (empty($_FILES['file'])) {
                igsFail('فایل عکس دریافت نشد.');
            }
            try {
                $res = igsSampleSave($_FILES['file'], trim((string)($_POST['slug'] ?? '')), $base);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['sample' => $res]);
            break;

        case 'sample_delete':
            $name = (string)($body['name'] ?? '');
            if (!igsSampleDelete($name)) {
                igsFail('عکس پیدا نشد یا حذف نشد.', 404);
            }
            igsOk(['deleted' => $name]);
            break;

        case 'save_logo':
            if (empty($_FILES['file'])) {
                igsFail('فایل لوگو دریافت نشد.');
            }
            try {
                $res = igsLogoSave($pdo, $_FILES['file'], $by);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['logo' => $res]);
            break;

        case 'delete_logo':
            igsLogoDelete($pdo);
            igsOk(['logo' => null]);
            break;

        case 'logo_file':
            // لوگوی اختصاصی استودیو فقط برای ادمین نشست‌دار؛ مثل export_file خروجی باینری است
            $rel = igsLogoRel($pdo);
            $path = $rel !== '' ? IGS_EXPORT_DIR . '/' . $rel : '';
            if ($rel === '' || !is_file($path)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'لوگو پیدا نشد.';
                exit;
            }
            $info = @getimagesize($path);
            header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($path));
            header('Cache-Control: private, no-store');
            readfile($path);
            exit;

        case 'brands':
            igsOk(['items' => igsBrandList($pdo, $by)]);
            break;

        case 'brand_save':
            try {
                $res = igsBrandSave($pdo, $by, is_array($body['brand'] ?? null) ? $body['brand'] : []);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['brand' => $res]);
            break;

        case 'brand_delete':
            $delId = (int)($body['id'] ?? 0);
            try {
                igsBrandDelete($pdo, $by, $delId);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['deleted' => $delId]);
            break;

        case 'brand_logo_save':
            try {
                $res = igsBrandLogoSave($pdo, $by, (int)($_POST['id'] ?? 0), $_FILES['file'] ?? []);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['brand' => $res]);
            break;

        case 'brand_logo_file':
            // لوگوی پروفایل فقط برای مالک آن (و فقط نشست ادمین)
            $bpath = igsBrandLogoFile($pdo, $by, (int)($_GET['id'] ?? 0));
            if ($bpath === null) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'لوگو پیدا نشد.';
                exit;
            }
            $binfo = @getimagesize($bpath);
            header('Content-Type: ' . ($binfo['mime'] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($bpath));
            header('Cache-Control: private, no-store');
            readfile($bpath);
            exit;

        case 'fonts':
            igsOk(['fonts' => igsFontRegistry($pdo)]);
            break;

        case 'font_save':
            // فونت اختصاصی فقط برای ادمین نشست‌دار (با CSRF) و همراه با تأیید مجوز
            if (empty($_FILES['file'])) {
                igsFail('فایل فونت دریافت نشد.');
            }
            try {
                $font = igsFontSave($pdo, $_FILES['file'], [
                    'licence_confirm' => $_POST['licence_confirm'] ?? '',
                    'licence_type' => $_POST['licence_type'] ?? '',
                    'licence_note' => $_POST['licence_note'] ?? '',
                ], $by);
            } catch (RuntimeException $e) {
                igsFail($e->getMessage());
            }
            igsOk(['font' => $font]);
            break;

        case 'font_delete':
            $fid = (string)($body['id'] ?? '');
            if (!igsFontDelete($pdo, $fid)) {
                igsFail('فونت پیدا نشد.', 404);
            }
            igsOk(['deleted' => $fid]);
            break;

        case 'font_file':
            // فایل فونت فقط برای ادمین نشست‌دار؛ مثل logo_file باینری است و کش خصوصی دارد
            $path = igsFontFilePath($pdo, (string)($_GET['id'] ?? ''));
            if ($path === '') {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'فونت پیدا نشد.';
                exit;
            }
            $bytes = (string)file_get_contents($path);
            $fmt = igsFontFormatOf($bytes);
            $mime = ['ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff'][$fmt ?? ''] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string)strlen($bytes));
            header('Cache-Control: private, no-store');
            echo $bytes;
            exit;

        case 'send_to_agent':
            $id = (int)($body['export_id'] ?? 0);
            if ($id <= 0) {
                igsFail('خروجی نامعتبر است.', 422);
            }
            igsOk(['agent' => igsSendExportToAgent($pdo, $id, $by)]);
            break;

        case 'delete_export':
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0 || !igsExportDelete($pdo, $id)) {
                igsFail('خروجی پیدا نشد یا قابل حذف نیست.', 404);
            }
            igsOk(['deleted' => $id]);
            break;

        default:
            igsFail('عمل نامعتبر است.', 400);
    }
} catch (InvalidArgumentException $e) {
    igsFail($e->getMessage(), 422);
} catch (RuntimeException $e) {
    igsFail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[ig-studio] ' . $e->getMessage());
    igsFail('خطای داخلی سرور. جزئیات در لاگ ثبت شد.', 500);
}
