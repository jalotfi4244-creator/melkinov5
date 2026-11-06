<?php require_once __DIR__ . '/config.php';
/**
|--------------------------------------------------------------------------
| endpoint سیستم مدیریت فیلدهای نمایشی (راند ۲۹)
|--------------------------------------------------------------------------
| GET  ?action=get&target=home|details   → registry + تنظیمات + meta + نمونه‌ها
| POST ?action=save&target=…             → اعتبارسنجی + ذخیره (+تاریخچه)
| POST ?action=restore&target=…          → بازگردانی آخرین تنظیمات
| POST ?action=set_preview               → draft پیش‌نمایش در session ادمین
| POST ?action=clear_preview             → پاک کردن draft پیش‌نمایش
|
| امنیت: admin-guard (session ادمین) + CSRF روی همهٔ عمل‌های تغییردهنده.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/field-display.php';

melkinoRequireAdminJson();

$melkinoFdUnwrap = static function (array $data): array {
    if (isset($data['payload']) && is_string($data['payload'])) {
        $decoded = json_decode($data['payload'], true);
        if (is_array($decoded)) {
            unset($data['payload']);
            $data = array_merge($data, $decoded);
        }
    }
    return $data;
};

$action = strtolower(trim((string)($_GET['action'] ?? '')));
$target = (string)($_GET['target'] ?? $_POST['target'] ?? 'home');
if (!in_array($target, ['home', 'details'], true)) {
    $target = 'home';
}

if ($action === 'get') {
    global $pdo;

    $samples = [];
    try {
        if ($pdo instanceof PDO) {
            $st = $pdo->query(
                "SELECT id, title, property_type, transaction_type
                 FROM ads
                 WHERE status = 'published'
                 ORDER BY created_at DESC
                 LIMIT 15"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $samples[] = [
                    'id'      => (string)$row['id'],
                    'title'   => (string)$row['title'],
                    'type'    => (string)($row['property_type'] ?? ''),
                    'tr'      => (string)($row['transaction_type'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        // بدون نمونه هم پنل کار می‌کند
    }

    if ($target === 'details') {
        $settings = melkinoFdDetailsSettings();
        melkinoAdminJson([
            'success'  => true,
            'target'   => 'details',
            'defs'     => $settings['defs'],
            'config'   => $settings['config'],
            'meta'     => $settings['meta'],
            'samples'  => $samples,
            'has_history' => !empty(melkinoFdStored('details')['history']),
        ]);
    }

    $settings = melkinoFdHomeSettings();
    melkinoAdminJson([
        'success'  => true,
        'target'   => 'home',
        'defs'     => $settings['defs'],
        'fields'   => $settings['fields'],
        'meta'     => $settings['meta'],
        'samples'  => $samples,
        'has_history' => !empty(melkinoFdStored('home')['history']),
    ]);
}

if ($action === 'save') {
    try {
        $data = $melkinoFdUnwrap(melkinoAdminJsonBody());
        if (isset($_POST['body_json']) && is_string($_POST['body_json'])) {
            $fromForm = json_decode($_POST['body_json'], true);
            if (is_array($fromForm)) {
                $data = array_merge($data, $fromForm);
            }
        }
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : $data;
        if ($target === 'home' && !isset($payload['fields']) && is_array($payload)) {
            // بدنه مستقیماً map فیلدهاست
            $looksLikeFields = isset($payload['title']) || isset($payload['parking']) || isset($payload['details_link']);
            if ($looksLikeFields) {
                $payload = ['fields' => $payload];
            }
        }
        $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $adminName = (string)($_SESSION['admin_username'] ?? '');
        $res = melkinoFdSave($target, $payload, $adminId, $adminName);
        melkinoAdminJson([
            'success' => (bool)$res['success'],
            'message' => $res['success']
                ? 'تنظیمات این صفحه ذخیره شد.'
                : 'ذخیره انجام نشد.',
            'errors'  => $res['errors'] ?? [],
            'meta'    => $res['meta'] ?? null,
            'has_history' => true,
        ], 200);
    } catch (Throwable $e) {
        melkinoAdminJson([
            'success' => false,
            'message' => melkinoSafeError($e, 'admin-field-display', 'ذخیرهٔ تنظیمات انجام نشد.'),
        ], 200);
    }
}

if ($action === 'restore') {
    $adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $res = melkinoFdRestore($target, $adminId);
    melkinoAdminJson([
        'success' => (bool)$res['success'],
        'message' => (string)($res['message'] ?? ''),
    ]);
}

if ($action === 'set_preview') {
    $data = $melkinoFdUnwrap(melkinoAdminJsonBody());
    $draft = is_array($data['draft'] ?? null) ? $data['draft'] : null;
    $adId = trim((string)($data['ad_id'] ?? ''));
    if ($draft === null) {
        melkinoAdminJson(['success' => false, 'message' => 'draft نامعتبر'], 400);
    }
    if (!isset($_SESSION['fd_preview']) || !is_array($_SESSION['fd_preview'])) {
        $_SESSION['fd_preview'] = [];
    }
    $_SESSION['fd_preview'][$target] = $draft;
    $_SESSION['fd_preview'][$target . '_ad'] = $adId;
    melkinoAdminJson(['success' => true]);
}

if ($action === 'clear_preview') {
    unset($_SESSION['fd_preview'][$target], $_SESSION['fd_preview'][$target . '_ad']);
    melkinoAdminJson(['success' => true]);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
