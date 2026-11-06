<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'فقط مدیر می‌تواند تنظیمات راهنما را ذخیره کند.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// اصلاح امنیتی: این endpoint قبلاً CSRF نداشت.
melkinoCsrfCheck();

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'داده نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clean = [
    'enabled' => !empty($data['enabled']),
    'user_enabled' => !empty($data['user_enabled']),
    'admin_enabled' => !empty($data['admin_enabled']),
    'steps_user' => is_array($data['steps_user'] ?? null) ? $data['steps_user'] : [],
    'steps_admin' => is_array($data['steps_admin'] ?? null) ? $data['steps_admin'] : [],
];

$ok = false;
try {
    if (isset($pdo) && $pdo instanceof PDO && function_exists('dbSettingSet')) {
        $ok = (bool) dbSettingSet($pdo, 'global', 'product_tour', $clean, 'json', (int) ($_SESSION['admin_id'] ?? 0));
    }
} catch (Throwable $e) {
    // جزئیات واقعی فقط در لاگ سرور؛ پیام عمومی بدون SQL/path.
    echo json_encode([
        'success' => false,
        'message' => melkinoSafeError($e, 'onboarding-tour-save', 'ذخیره در دیتابیس ممکن نشد.'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ok) {
    melkinoAudit('settings.update', 'settings', 'global.product_tour', [
        'enabled' => $clean['enabled'],
        'user_enabled' => $clean['user_enabled'],
        'admin_enabled' => $clean['admin_enabled'],
    ]);
}

echo json_encode(['success' => $ok, 'message' => $ok ? 'ذخیره شد.' : 'ذخیره در دیتابیس ممکن نشد.'], JSON_UNESCAPED_UNICODE);
