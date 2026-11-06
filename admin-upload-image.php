<?php
/*
|--------------------------------------------------------------------------
| آپلود تصویر برای یک آگهی موجود، از داخل پنل ادمین (بخش ویرایش آگهی)
|--------------------------------------------------------------------------
| قبلاً هیچ راهی برای اضافه‌کردن عکس جدید به یک آگهی از پنل ادمین
| وجود نداشت؛ فقط عکس‌هایی که خودِ کاربر موقع ثبت ملک فرستاده بود
| قابل انتخاب/عدم‌انتخاب بودند. این فایل به ادمین اجازه می‌دهد مستقیم
| از پنل، عکس جدید به هر آگهی اضافه کند.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-lib.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}
melkinoCsrfCheck();

$adId = trim((string)($_POST['ad_id'] ?? ''));

if ($adId === '') {
    echo json_encode(['success' => false, 'message' => 'شناسه‌ی آگهی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetDir = __DIR__ . '/uploads/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxFileSize = 5 * 1024 * 1024;

if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}
melkinoProtectUploadDir($targetDir);

if (!is_dir($targetDir) || !is_writable($targetDir)) {
    echo json_encode([
        'success' => false,
        'message' => 'پوشه‌ی uploads روی سرور وجود ندارد یا قابل نوشتن نیست (دسترسی ۷۵۵ لازم است).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploaded = [];
$errors = [];

if (isset($_FILES['images']) && is_array($_FILES['images']['name']) && count($_FILES['images']['name']) > 0) {
    for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
        if (empty($_FILES['images']['name'][$i])) continue;

        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = $_FILES['images']['name'][$i] . ': کد خطای آپلود ' . $_FILES['images']['error'][$i];
            continue;
        }

        if ($_FILES['images']['size'][$i] > $maxFileSize) {
            $errors[] = $_FILES['images']['name'][$i] . ': حجم فایل بیش از ۵ مگابایت است.';
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $_FILES['images']['tmp_name'][$i]);
        finfo_close($finfo);

        $fileExt = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMimes) || !in_array($fileExt, $allowedExtensions)) {
            $errors[] = $_FILES['images']['name'][$i] . ': فرمت تصویر مجاز نیست.';
            continue;
        }

        // اعتبارسنجی واقعی تصویر: فایلی که MIME جعلی دارد اینجا رد می‌شود.
        $imageInfo = @getimagesize($_FILES['images']['tmp_name'][$i]);
        if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
            $errors[] = $_FILES['images']['name'][$i] . ': فایل یک تصویر معتبر نیست.';
            continue;
        }
        $allowedImageTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
        if (!isset($imageInfo[2]) || !in_array((int) $imageInfo[2], $allowedImageTypes, true)) {
            $errors[] = $_FILES['images']['name'][$i] . ': نوع تصویر پشتیبانی نمی‌شود.';
            continue;
        }
        // نام فایل کاملاً تصادفی ساخته می‌شود و پسوند از نوع واقعی تصویر می‌آید.
        $extByType = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF  => 'gif',
        ];
        $fileExt = $extByType[(int) $imageInfo[2]];

        $uniqueId = bin2hex(random_bytes(10));
        $safeAdId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $adId);
        $fileName = $safeAdId . '_admin_' . date('Ymd_His') . '_' . $uniqueId . '.' . $fileExt;
        $targetFile = $targetDir . $fileName;

        // تصویر دوباره رمزگذاری می‌شود تا هر payload جاسازی‌شده در
        // متادیتا حذف شود. اگر GD در دسترس نبود، به انتقال عادی
        // برمی‌گردیم (فایل از قبل با getimagesize اعتبارسنجی شده).
        $stored = melkinoReencodeImage($_FILES['images']['tmp_name'][$i], $targetFile, (int) $imageInfo[2]);
        if (!$stored) {
            $stored = move_uploaded_file($_FILES['images']['tmp_name'][$i], $targetFile);
        }

        if ($stored) {
            @chmod($targetFile, 0644);
            $uploaded[] = 'uploads/' . $fileName;
        } else {
            $errors[] = $_FILES['images']['name'][$i] . ': ذخیره‌ی فایل روی سرور ناموفق بود.';
        }
    }
} else {
    $errors[] = 'هیچ فایلی دریافت نشد.';
}

// =====================================================================
// ثبت فوری تصاویر در جدول images
// =====================================================================
// قبلاً فقط فایل روی دیسک ذخیره می‌شد و تا ادمین «ذخیره تغییرات» را
// نمی‌زدند، ردیفی در جدول images ساخته نمی‌شد؛ در نتیجه اگر مودال
// بسته می‌شد (یا دکمه‌ی ذخیره در صفحه‌ی گوشی دیده نمی‌شد)، تصویر روی
// کارت‌های پنل و سایت نمایش داده نمی‌شد. حالا هر آپلود بلافاصله در
// دیتابیس هم ثبت می‌شود و bulk_sync بعدی فقط فلگ‌های انتخاب را به‌روز می‌کند.
$dbRowsAdded = 0;
if (count($uploaded) > 0 && ($pdo instanceof PDO)) {
    $safeAdId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $adId);
    try {
        $primaryCheck = $pdo->prepare("SELECT COUNT(*) FROM images WHERE ad_id = ? AND is_primary = 1");
        $primaryCheck->execute([$safeAdId]);
        $alreadyHasPrimary = (int) $primaryCheck->fetchColumn() > 0;

        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM images WHERE ad_id = ?");
        $ins = $pdo->prepare("INSERT INTO images (ad_id, filename, storage_path, sort_order, is_selected, is_primary, publish_publicly, created_at) VALUES (?, ?, ?, ?, 1, ?, 1, NOW())");

        foreach ($uploaded as $order => $file) {
            $maxOrder->execute([$safeAdId]);
            $sortOrder = (int) $maxOrder->fetchColumn() + 1;
            $isPrimary = (!$alreadyHasPrimary && $order === 0) ? 1 : 0;
            $ins->execute([$safeAdId, $file, $file, $sortOrder, $isPrimary]);
            if ($isPrimary === 1) {
                $alreadyHasPrimary = true;
            }
            $dbRowsAdded++;
        }
    } catch (Throwable $e) {
        $errors[] = melkinoSafeError($e, 'admin-upload-image', 'ثبت تصویر در دیتابیس انجام نشد.');
    }
}

if ($dbRowsAdded > 0) {
    melkinoAudit('ad.images_uploaded', 'ad', $adId, ['count' => $dbRowsAdded]);
}

echo json_encode([
    'success' => count($uploaded) > 0,
    'images' => $uploaded,
    'db_rows_added' => $dbRowsAdded,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
