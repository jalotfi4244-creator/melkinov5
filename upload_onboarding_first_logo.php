<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/melkino-logo.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

function melkinoOnboardingFirstLogoMeta(): array
{
    $url = function_exists('melkinoOnboardingFirstLogoUrl') ? melkinoOnboardingFirstLogoUrl() : '';
    return [
        'success' => true,
        'logo_url' => $url,
        'has_logo' => $url !== '',
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(melkinoOnboardingFirstLogoMeta(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (function_exists('melkinoCsrfCheck')) {
    melkinoCsrfCheck();
}

$adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
if (!$adminId) {
    $adminId = null;
}

$upDir = __DIR__ . '/uploads';
$brandDir = $upDir . '/branding';
if (!is_dir($upDir)) {
    @mkdir($upDir, 0755, true);
}
if (!is_dir($brandDir)) {
    @mkdir($brandDir, 0755, true);
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'delete') {
    foreach (glob($upDir . '/onboarding-first-logo.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    foreach (glob($brandDir . '/onboarding-first-*.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $old = dbSettingGet($pdo, 'branding', 'logos', []);
    if (!is_array($old)) {
        $old = [];
    }
    unset($old['onboarding_first']);
    dbSettingSet($pdo, 'branding', 'logos', $old, 'json', $adminId);
    echo json_encode([
        'success' => true,
        'message' => 'لوگوی اسلاید اول حذف شد؛ اسلاید اول دوباره آیکون نشان می‌دهد.',
        'logo_url' => '',
        'has_logo' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_FILES['onboarding_first_logo']) || ($_FILES['onboarding_first_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشده یا خطا در آپلود'], JSON_UNESCAPED_UNICODE);
    exit;
}

$f = $_FILES['onboarding_first_logo'];
if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'حجم فایل بیشتر از ۵ مگابایت است'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = $fi ? finfo_file($fi, $f['tmp_name']) : '';
if ($fi) {
    finfo_close($fi);
}
$ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
if (!$ext || @getimagesize($f['tmp_name']) === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'فرمت فایل مجاز نیست (PNG, JPG, WEBP)'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_dir($brandDir) || !is_writable($brandDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'پوشهٔ uploads/branding قابل نوشتن نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = 'onboarding-first-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
$path = $brandDir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی فایل'], JSON_UNESCAPED_UNICODE);
    exit;
}
@touch($path);

foreach (glob($upDir . '/onboarding-first-logo.*') ?: [] as $old) {
    if (is_file($old)) {
        @unlink($old);
    }
}
@copy($path, $upDir . '/onboarding-first-logo.' . $ext);

foreach (glob($brandDir . '/onboarding-first-*.*') ?: [] as $old) {
    if (is_file($old) && basename($old) !== $name) {
        @unlink($old);
    }
}

$old = dbSettingGet($pdo, 'branding', 'logos', []);
if (!is_array($old)) {
    $old = [];
}
$old['onboarding_first'] = [
    'url' => 'uploads/branding/' . $name,
    'filename' => $name,
    'mime' => $mime,
    'updated_at' => date('Y-m-d H:i:s'),
];
if (!dbSettingSet($pdo, 'branding', 'logos', $old, 'json', $adminId)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'فایل ذخیره شد ولی ثبت تنظیمات انجام نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = melkinoOnboardingFirstLogoMeta();
$out['message'] = 'لوگوی اسلاید اول با موفقیت آپلود شد.';
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
