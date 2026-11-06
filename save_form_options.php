<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/form-options.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (melkinoIsMutatingRequest()) {
    melkinoCsrfCheck();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => true, 'combos' => melkinoFormComboCatalog()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (function_exists('melkinoReadRequestBody')) {
    $p = melkinoReadRequestBody();
} else {
    $p = json_decode((string) file_get_contents('php://input'), true);
}
if (!is_array($p)) {
    $p = [];
}
$combos = $p['combos'] ?? null;
if (!is_array($combos) || $combos === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'دادهٔ فرم به سرور نرسید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = melkinoFormComboSave($combos);
if (!$ok) {
    http_response_code(500);
    $msg = function_exists('melkinoFormComboLastError') ? melkinoFormComboLastError() : '';
    echo json_encode([
        'success' => false,
        'message' => $msg !== '' ? $msg : 'ذخیره در دیتابیس انجام نشد.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'در دیتابیس ذخیره شد. فرم ثبت ملک را یک‌بار رفرش کنید.',
    'combos' => melkinoFormComboCatalog(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
