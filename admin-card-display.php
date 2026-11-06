<?php require_once __DIR__ . '/config.php';
/**
|--------------------------------------------------------------------------
| endpoint تنظیمات نمایش کارت‌ها (راند ۲۰)
|--------------------------------------------------------------------------
| GET  ?action=get  → تعاریف + تنظیمات فعلی
| POST ?action=save → ذخیرهٔ تنظیمات {settings:{key:mode|bool}}
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/card-display.php';

melkinoRequireAdminJson();

$action = strtolower(trim((string)($_GET['action'] ?? '')));

if ($action === 'get') {
    melkinoAdminJson([
        'success'  => true,
        'defs'     => melkinoCardDisplayDefs(),
        'settings' => melkinoCardDisplaySettings(),
    ]);
}

if ($action === 'save') {
    $data = melkinoAdminJsonBody();
    if (isset($data['settings']) && is_string($data['settings'])) {
        $decodedSettings = json_decode($data['settings'], true);
        if (is_array($decodedSettings)) {
            $data['settings'] = $decodedSettings;
        }
    }
    $map = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $ok = melkinoSaveCardDisplay($map);
    melkinoAdminJson([
        'success' => $ok,
        'message' => $ok ? 'تنظیمات نمایش کارت‌ها ذخیره شد.' : 'ذخیره ناموفق بود.',
    ]);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
