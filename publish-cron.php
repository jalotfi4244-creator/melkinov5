<?php
/**
 * ملکینو — Worker انتشار (CLI)
 * برنامه‌ریزی سرور:
 *   * * * * * php /path/to/program/publish-cron.php >> /path/to/program/storage/logs/publish-cron.log 2>&1
 * فقط CLI؛ با flock مانع اجرای همزمان می‌شود. مقاصد سررسیدشده را می‌فرستد؛
 * پس از خطای قطعی تا ۳ بار با backoff تلاش مجدد می‌کند؛ پس از نتیجهٔ مبهم هرگز تلاش مجدد خودکار نمی‌کند.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/publish-center-lib.php';

global $pdo;
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "[publish-cron] no database connection\n");
    exit(1);
}
melkinoPublishEnsureSchema($pdo);

$lockPath = __DIR__ . '/storage/logs';
if (!is_dir($lockPath)) {
    @mkdir($lockPath, 0755, true);
}
$lockFile = $lockPath . '/publish-cron.lock';
$fp = fopen($lockFile, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo '[publish-cron] already running, skip.', PHP_EOL;
    exit(0);
}

$max = 10;
$result = melkinoPublishRunDue($pdo, $max, null, 'cron');
echo '[publish-cron] ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;

flock($fp, LOCK_UN);
fclose($fp);
