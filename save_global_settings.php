<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/melkino-calc-rates.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (melkinoIsMutatingRequest()) {
    // نشست ادمین قبلاً چک شده؛ CSRF سخت‌گیرانه این دکمه را روی بعضی مرورگرها می‌بست
    if (empty($_SESSION['is_admin'])) {
        melkinoCsrfCheck();
    }
}

function melkinoGlobalSettingsPayload(): array
{
    $s = getGlobalSettings();
    // مقدار خام hide_all_prices (بدون اثر پیام به‌جای قیمت) تا ذخیرهٔ تب تنظیمات عمومی آن را پایدار نکند
    try {
        global $pdo;
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            $s['hide_all_prices'] = (bool) dbSettingGet($pdo, 'global', 'hide_all_prices', false);
        }
    } catch (Throwable $e) {
    }
    $s['calc_rates'] = melkinoCalcRates();
    try {
        global $pdo;
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            $s['card_layout'] = (string) dbSettingGet($pdo, 'global', 'card_layout', $s['card_layout'] ?? 'photo-top');
        }
    } catch (Throwable $e) {
        $s['card_layout'] = $s['card_layout'] ?? 'photo-top';
    }
    return $s;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(['success' => true, 'settings' => melkinoGlobalSettingsPayload()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// php://input بعد از melkinoCsrfCheck خالی است — بدنه را از کش CSRF بخوان
if (function_exists('melkinoReadRequestBody')) {
    $p = melkinoReadRequestBody();
} else {
    $p = json_decode((string) file_get_contents('php://input'), true);
}
if (!is_array($p)) {
    $p = [];
}
unset($p['csrf_token']);

if (!array_key_exists('site_name', $p) && !array_key_exists('calc_rates', $p) && !array_key_exists('default_theme', $p) && !array_key_exists('card_layout', $p) && !array_key_exists('price_message_enabled', $p) && !array_key_exists('price_message_text', $p)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'دادهٔ تنظیمات به سرور نرسید. صفحه را یک‌بار رفرش کنید و دوباره ذخیره کنید.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
if (!$adminId) {
    $adminId = null;
}

$melkinoWasMaintenance = false;
try {
    $melkinoWasMaintenance = (bool) dbSettingGet($pdo, 'global', 'maintenance_mode', false);
} catch (Throwable $e) {
}

$keys = [
    'site_name',
    'city',
    'slogan',
    'show_prices',
    'hide_all_prices',
    'price_message_enabled',
    'price_message_text',
    'enable_favorites',
    'enable_property_requests',
    'enable_notifications',
    'enable_property_calculator',
    'items_per_page',
    'default_theme',
    'maintenance_mode',
    'card_layout',
];

$saved = 0;
foreach ($keys as $k) {
    if (!array_key_exists($k, $p)) {
        continue;
    }
    $v = $p[$k] ?? null;
    if ($k === 'items_per_page') {
        $v = max(4, min(100, (int) $v));
    } elseif ($k === 'price_message_text') {
        $v = mb_substr(trim(strip_tags((string) $v)), 0, 300, 'UTF-8');
    } elseif ($k === 'default_theme') {
        $v = $v === 'dark' ? 'dark' : 'light';
    } elseif ($k === 'card_layout') {
        $okLayouts = ['photo-top','photo-full','photo-left','photo-right','photo-float','photo-collage','photo-portrait','photo-editorial'];
        $v = (string) $v;
        if (!in_array($v, $okLayouts, true)) {
            $v = 'photo-top';
        }
    } elseif (in_array($k, [
        'show_prices',
        'hide_all_prices',
        'price_message_enabled',
        'enable_favorites',
        'enable_property_requests',
        'enable_notifications',
        'enable_property_calculator',
        'maintenance_mode',
    ], true)) {
        $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
    $type = is_bool($v) ? 'boolean' : (is_int($v) ? 'integer' : 'string');
    if (!dbSettingSet($pdo, 'global', $k, $v, $type, $adminId)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ذخیره تنظیمات عمومی انجام نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $saved++;
}

if (array_key_exists('calc_rates', $p)) {
    $clean = melkinoSanitizeCalcRates($p['calc_rates']);
    if (!dbSettingSet($pdo, 'global', 'melkino_calc_rates', $clean, 'json', $adminId)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ذخیره ضرایب ماشین‌حساب انجام نشد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $saved++;
}

if ($saved < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'هیچ فیلدی برای ذخیره ارسال نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $nowMaint = (bool) dbSettingGet($pdo, 'global', 'maintenance_mode', false);
} catch (Throwable $e) {
    $nowMaint = !empty($p['maintenance_mode']);
}
if ($nowMaint && !$melkinoWasMaintenance) {
    dbSettingSet($pdo, 'global', 'maintenance_started_at', time(), 'integer', $adminId);
    $_SESSION['melkino_session_started_at'] = time();
}
if (!$nowMaint) {
    dbSettingSet($pdo, 'global', 'maintenance_started_at', 0, 'integer', $adminId);
}

echo json_encode(['success' => true, 'settings' => melkinoGlobalSettingsPayload()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
