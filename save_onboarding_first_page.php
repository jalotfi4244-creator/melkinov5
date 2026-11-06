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

function melkinoOnboardingFirstPagePayload(): array
{
    $p = function_exists('melkinoOnboardingFirstPage') ? melkinoOnboardingFirstPage() : ['title' => '', 'text' => ''];
    $logo = function_exists('melkinoOnboardingFirstLogoUrl') ? melkinoOnboardingFirstLogoUrl() : '';
    return [
        'success' => true,
        'title' => (string) ($p['title'] ?? ''),
        'text' => (string) ($p['text'] ?? ''),
        'logo_url' => $logo,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    echo json_encode(melkinoOnboardingFirstPagePayload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

if (function_exists('melkinoReadRequestBody')) {
    $p = melkinoReadRequestBody();
} else {
    $p = json_decode((string) file_get_contents('php://input'), true);
}
if (!is_array($p)) {
    $p = $_POST;
}

$title = trim((string) ($p['title'] ?? ''));
$text = trim((string) ($p['text'] ?? ''));
if (function_exists('mb_substr')) {
    $title = mb_substr($title, 0, 80);
    $text = mb_substr($text, 0, 500);
} else {
    $title = substr($title, 0, 80);
    $text = substr($text, 0, 500);
}

$adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
if (!$adminId) {
    $adminId = null;
}

$ok = dbSettingSet($pdo, 'onboarding', 'first_page', [
    'title' => $title,
    'text' => $text,
], 'json', $adminId);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ذخیره نوشته‌ها انجام نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = melkinoOnboardingFirstPagePayload();
$out['message'] = 'نوشته‌های اسلاید اول ذخیره شد.';
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
