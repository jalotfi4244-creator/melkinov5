<?php
/**
 * Melkino Instagram Agent — Job Runner (قابل اجرا با Cron هاست)
 * نمونه Cron (هر ۱۵ دقیقه):
 *   php /path/to/program/instagram-agent-cron.php
 * قواعد: قفل فایلی (بدون اجرای موازی)، محدودیت تعداد در هر اجرا، ثبت نتیجه در جدول jobs.
 * بدون Cron هم ماژول با انتشار دستی/دکمهٔ «اجرای دستی صف» کامل کار می‌کند.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    // اجرای وبی مستقیم مجاز نیست؛ فقط مسیر پنل (POST+CSRF) می‌تواند صف را اجرا کند.
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/instagram-agent-lib.php';

if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "DB not available\n");
    exit(1);
}

melkinoIgEnsureSchema($pdo);

$lockFile = __DIR__ . '/storage/instagram-agent/cron.lock';
if (!is_dir(dirname($lockFile))) {
    @mkdir(dirname($lockFile), 0700, true);
}
$fp = @fopen($lockFile, 'c');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "another run in progress; exiting\n";
    exit(0);
}

try {
    $max = (int)melkinoIgSettingGet($pdo, 'max_per_run', '5');
    $jobSt = $pdo->prepare("INSERT INTO instagram_agent_jobs (kind, status, started_at) VALUES ('publish_due', 'running', NOW())");
    $jobSt->execute();
    $jobId = (int)$pdo->lastInsertId();

    $result = melkinoIgRunDuePublications($pdo, null, max(1, min(20, $max)));

    $pdo->prepare("UPDATE instagram_agent_jobs SET status='done', finished_at=NOW(), result_summary=? WHERE id=?")
        ->execute([json_encode($result, JSON_UNESCAPED_UNICODE) ?: '', $jobId]);

    echo 'done ' . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    // پیام خطا بدون جزئیات حساس در جدول؛ جزئیات فقط به STDERR سرور
    try {
        $pdo->prepare("UPDATE instagram_agent_jobs SET status='failed', finished_at=NOW(), last_error=? WHERE kind='publish_due' AND status='running'")
            ->execute([mb_substr($e->getMessage(), 0, 500)]);
    } catch (Throwable $e2) {
    }
    fwrite(STDERR, "job failed: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
