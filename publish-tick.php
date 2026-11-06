<?php
/**
 * ملکینو — نقطهٔ اجرای زمان‌بندی‌شدهٔ صف انتشار از بیرون (مثلاً cron-job.org هر دقیقه).
 * نشانی: /program/publish-tick.php?token=TOKEN
 * TOKEN در فایل storage/logs/publish-tick.token است (بعد از اولین بازدید ساخته می‌شود).
 */
declare(strict_types=1);

define('MK_SKIP_LOGIN_GATE', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/melkino-publish-autotick.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$given = (string)($_GET['token'] ?? '');
$expected = melkinoPublishTickToken();
if ($given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $pdo;
if (!($pdo instanceof PDO)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'db'], JSON_UNESCAPED_UNICODE);
    exit;
}
$result = melkinoPublishAutoTick($pdo, true);
echo json_encode(['ok' => true, 'time' => date('Y-m-d H:i:s'), 'result' => $result], JSON_UNESCAPED_UNICODE);
