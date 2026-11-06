<?php
/**
 * قفل سایت و شمارش بازدیدکننده (migration 006)
 * - قفل فقط صفحهٔ عمومی (index.php) را می‌بندد؛ ادمین و کاربر واردشده عبور می‌کنند.
 * - شمارش یکتا: HMAC(IP + کوکی ناشناس mk_vid) با نمک محلی؛ IP خام ذخیره نمی‌شود.
 * - بازدیدهای مسدودشده و بازدیدهای ادمین شمارش نمی‌شوند.
 */

if (!function_exists('siteRuntimeSalt')) {
    function siteRuntimeSalt(): string
    {
        $dir = __DIR__ . '/storage/cache';
        if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
        $file = $dir . '/visit-salt.key';
        if (!is_file($file)) {
            @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($file, 0600);
        }
        return trim((string)@file_get_contents($file));
    }

    function siteRuntimeIsPrivileged(): bool
    {
        return (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
            || !empty($_SESSION['user_id']);
    }

    function siteRuntimeLockRow($pdo): array
    {
        try {
            $st = $pdo->query("SELECT locked, message, updated_at, updated_by FROM site_lock WHERE id = 1 LIMIT 1");
            $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$r) { return ['locked' => 0, 'message' => '', 'updated_at' => null, 'updated_by' => '']; }
            return [
                'locked' => (int)$r['locked'],
                'message' => (string)$r['message'],
                'updated_at' => $r['updated_at'],
                'updated_by' => (string)$r['updated_by'],
            ];
        } catch (Throwable $e) {
            error_log('site lock read: ' . $e->getMessage());
            return ['locked' => 0, 'message' => '', 'updated_at' => null, 'updated_by' => ''];
        }
    }

    /**
     * قفل سایت: وقتی قفل است، مهمان (بدون ورود) به صفحهٔ اصلی دسترسی ندارد و به ورود هدایت می‌شود.
     * ادمین و کاربر واردشده عبور می‌کنند. صفحهٔ جزئیات ملک از طریق لینک اشتراک (share=1) جداگانه
     * و بدون قیمت در program/ مدیریت می‌شود و این دروازه آن را تغییر نمی‌دهد.
     */
    function siteRuntimeGate($pdo): void
    {
        if (PHP_SAPI === 'cli' || !$pdo instanceof PDO) { return; }
        $lock = siteRuntimeLockRow($pdo);
        if (!$lock['locked'] || siteRuntimeIsPrivileged()) { return; }

        header('Cache-Control: no-store, private');
        header('Location: program/login.php', true, 302);
        exit;
    }

    function siteRuntimeVisitorHash(): string
    {
        $cookie = (string)($_COOKIE['mk_vid'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $cookie = bin2hex(random_bytes(16));
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie('mk_vid', $cookie, [
                'expires' => time() + 31536000,
                'path' => '/',
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['mk_vid'] = $cookie;
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return hash_hmac('sha256', $ip . '|' . $cookie, siteRuntimeSalt());
    }

    /** شمارش یک بازدید GET صفحهٔ HTML (ادمین و درخواست‌های غیر HTML شمارش نمی‌شوند). */
    function siteRuntimeRecordVisit($pdo): void
    {
        if (PHP_SAPI === 'cli' || !$pdo instanceof PDO) { return; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
        if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') === false) { return; }
        if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) { return; }
        try {
            $day = date('Y-m-d');
            $now = date('Y-m-d H:i:s');
            $hash = siteRuntimeVisitorHash();
            $path = substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 190) ?: '/';
            $pdo->prepare(
                "INSERT INTO site_visits_daily (day, visitor_hash, first_seen, last_seen, hits) VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)"
            )->execute([$day, $hash, $now, $now]);
            $pdo->prepare(
                "INSERT INTO site_page_hits_daily (day, path, hits) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1"
            )->execute([$day, $path]);
        } catch (Throwable $e) {
            error_log('site visit record: ' . $e->getMessage());
        }
    }
}
