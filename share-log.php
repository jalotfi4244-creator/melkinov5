<?php
/**
 * Melkino — ثبت اشتراک‌گذاری آگهی (ADDITIVE).
 * فراخواننده: assets/js/melkino-share.js (فقط POST، fire-and-forget).
 * فقط کاربر لاگین‌کرده + CSRF معتبر (هدر خودکار csrf-shim) ثبت می‌شود؛
 * مهمان توسط گیت عمومی ورود رد می‌شود (302 یا 401 برای JSON)؛
 * ورودی نامعتبر بدون ثبت رویداد با ok:false پاسخ می‌گیرد.
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-stats-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

if (function_exists('melkinoCsrfCheck')) {
    melkinoCsrfCheck(); // نامعتبر = خروج 419
}

$uid = (int)($_SESSION['melkino_user_id'] ?? $_SESSION['user_id'] ?? 0);
if ($uid <= 0 && isset($pdo) && $pdo instanceof PDO) {
    // تنها هویت تأییدشدهٔ سشن؛ هرگز user_id/phone ارسالی مرورگر پذیرفته نمی‌شود.
    // پوشش ورود OTP و ورود مستقل مینی‌اپ تلگرام/بله/ایتا، حتی بدون شماره.
    foreach (['telegram_id' => 'reg_telegram_id', 'bale_id' => 'reg_bale_id',
              'eitaa_id' => 'reg_eitaa_id', 'phone' => 'user_phone'] as $column => $key) {
        $value = trim((string)($_SESSION[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        try {
            $st = $pdo->prepare("SELECT id FROM users WHERE `$column` = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$value]);
            $uid = (int)$st->fetchColumn();
            if ($uid > 0) {
                break;
            }
        } catch (Throwable $e) {
            $uid = 0;
        }
    }
}
if ($uid <= 0) {
    echo json_encode(['ok' => false, 'guest' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = $_POST;
if (!$data && ($raw = (string)file_get_contents('php://input')) !== '') {
    $j = json_decode($raw, true);
    $data = is_array($j) ? $j : [];
}
$adId = is_scalar($data['ad_id'] ?? null) ? trim((string)$data['ad_id']) : '';
$title = is_scalar($data['title'] ?? null) ? trim((string)$data['title']) : '';

$ok = ($adId !== '') && melkinoRecordAdShare($uid, $adId, $title);
echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
