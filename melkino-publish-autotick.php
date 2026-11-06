<?php
/**
 * ملکینو — اجرای خودکار صف انتشار هنگام بازدید (بدون نیاز به دکمهٔ ادمین و بدون cron سرور)
 * هر ۶۰ ثانیه حداکثر یک بار، مقصدهای سررسیده را می‌فرستد. قفل داخلی RunDue از اجرای همزمان جلوگیری می‌کند.
 */
declare(strict_types=1);

function melkinoPublishTokenPath(): string
{
    return __DIR__ . '/storage/logs/publish-tick.token';
}

function melkinoPublishTickToken(): string
{
    $path = melkinoPublishTokenPath();
    if (is_file($path)) {
        $t = trim((string)@file_get_contents($path));
        if (preg_match('~^[a-f0-9]{32}$~', $t)) {
            return $t;
        }
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $t = bin2hex(random_bytes(16));
    @file_put_contents($path, $t, LOCK_EX);
    return $t;
}

/** بار اول فقط یک فایل مهر زمانی می‌سازد؛ پس از آن هر ۶۰ ثانیه یک بار صف را اجرا می‌کند. */
function melkinoPublishAutoTick(PDO $pdo, bool $force = false): array
{
    $dir = __DIR__ . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $stamp = $dir . '/publish-tick.stamp';
    clearstatcache(true, $stamp);
    if (!$force && is_file($stamp) && (time() - (int)filemtime($stamp)) < 60) {
        return ['ok' => true, 'skipped' => 'throttle'];
    }
    @touch($stamp);
    melkinoPublishTickToken();
    require_once __DIR__ . '/bot-settings.php';
    require_once __DIR__ . '/publish-center-lib.php';
    if (!function_exists('melkinoPublishRunDue')) {
        return ['ok' => false, 'skipped' => 'lib'];
    }
    melkinoPublishEnsureSchema($pdo);
    return melkinoPublishRunDue($pdo, 5, null, 'auto');
}
