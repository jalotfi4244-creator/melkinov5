<?php require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/photo-watermark.php';
require_once __DIR__ . '/melkino-logo.php';

melkinoRequireAdminJson();

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function melkinoWmPublicLogo(array $wm): string
{
    $custom = function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($wm) : '';
    if ($custom !== '') {
        return $custom;
    }
    return function_exists('melkinoSiteLogoUrl') ? (string) melkinoSiteLogoUrl() : '';
}

if ($method === 'GET') {
    $wm = melkinoPhotoWatermark();
    melkinoAdminJson([
        'success' => true,
        'settings' => $wm,
        'logo' => melkinoWmPublicLogo($wm),
        'custom_logo' => (function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($wm) : '') !== '',
    ]);
}

if ($method === 'POST' && ($action === 'upload_logo' || isset($_FILES['logo']))) {
    $f = $_FILES['logo'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        melkinoAdminJson(['success' => false, 'message' => 'فایلی انتخاب نشده.'], 400);
    }
    if (($f['size'] ?? 0) > 3 * 1024 * 1024) {
        melkinoAdminJson(['success' => false, 'message' => 'حجم فایل بیشتر از ۳ مگابایت است.'], 400);
    }
    $fi = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $fi ? (string) finfo_file($fi, $f['tmp_name']) : '';
    if ($fi) {
        finfo_close($fi);
    }
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || @getimagesize($f['tmp_name']) === false) {
        melkinoAdminJson(['success' => false, 'message' => 'فقط PNG، JPG یا WebP.'], 400);
    }
    $dir = __DIR__ . '/uploads/branding';
    if (!is_dir(__DIR__ . '/uploads')) {
        @mkdir(__DIR__ . '/uploads', 0755, true);
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        melkinoAdminJson(['success' => false, 'message' => 'پوشهٔ uploads/branding قابل نوشتن نیست.'], 500);
    }
    foreach (glob($dir . '/watermark-logo*.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $name = 'watermark-logo-' . date('YmdHis') . '.' . $ext;
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $path)) {
        melkinoAdminJson(['success' => false, 'message' => 'ذخیرهٔ فایل ناموفق بود.'], 500);
    }
    @touch($path);
    $saved = melkinoSavePhotoWatermark(['logo_rel' => 'uploads/branding/' . $name]);
    $url = function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($saved) : ('uploads/branding/' . $name);
    melkinoAdminJson([
        'success' => true,
        'message' => 'لوگوی واترمارک آپلود شد.',
        'settings' => $saved,
        'logo' => $url,
        'custom_logo' => true,
    ]);
}

if ($method === 'POST' && $action === 'clear_logo') {
    $dir = __DIR__ . '/uploads/branding';
    foreach (glob($dir . '/watermark-logo*.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $saved = melkinoSavePhotoWatermark(['logo_rel' => '']);
    melkinoAdminJson([
        'success' => true,
        'message' => 'لوگوی واترمارک حذف شد؛ از لوگوی سایت استفاده می‌شود.',
        'settings' => $saved,
        'logo' => function_exists('melkinoSiteLogoUrl') ? (string) melkinoSiteLogoUrl() : '',
        'custom_logo' => false,
    ]);
}

if ($method === 'POST') {
    $data = function_exists('melkinoAdminJsonBody') ? melkinoAdminJsonBody() : [];
    if (!is_array($data)) {
        $data = [];
    }
    unset($data['logo_url'], $data['logo']);
    $saved = melkinoSavePhotoWatermark($data);
    melkinoAdminJson([
        'success' => true,
        'settings' => $saved,
        'logo' => melkinoWmPublicLogo($saved),
        'custom_logo' => (function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($saved) : '') !== '',
        'message' => 'واترمارک ذخیره شد. صفحهٔ آگهی‌ها را یک‌بار کامل ببندید و باز کنید.',
    ]);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
