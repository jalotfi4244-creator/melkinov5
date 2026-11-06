<?php
/**
 * ------------------------------------------------------------------------
 * ملکینو — ماژول «انتشار» (Publication Center) — کتابخانهٔ هسته
 * ------------------------------------------------------------------------
 * • ساختار داده (همانِ publish-module.sql؛ خودکار و امن)
 * • موتور صف و اجرای زمان‌بندی‌شده (Worker) — مستقل از مرورگر
 * • ارسال واقعی از طریق bot-api-client.php (تلگرام/بله/ایتا)
 * • تاریخچهٔ یکپارچهٔ عملیات (publish_history + سازگاری با لاگ‌های قدیمی)
 * • قوانین اتوماسیون وضعیت آگهی
 * • لینک رهگیری اختصاصی و ثبت کلیک
 * • هیچ آمار یا نتیجهٔ ساختگی تولید نمی‌شود؛ وضعیت‌های نامشخص صریحاً «نامشخص»‌اند.
 * ------------------------------------------------------------------------
 */
declare(strict_types=1);

require_once __DIR__ . '/bot-api-client.php'; // ارسال واقعی/شبیه‌سازی‌شدهٔ پیام‌رسان‌ها

if (!defined('MELKINO_PUBLISH_SCHEMA')) {
    define('MELKINO_PUBLISH_SCHEMA', 'publish_schema_v5');
}

/* ======================= برچسب‌ها و قابلیت‌ها ======================= */

if (!function_exists('melkinoPublishPlatformLabel')) {
    function melkinoPublishPlatformLabel(string $platform): string
    {
        return ['telegram' => 'تلگرام', 'bale' => 'بله', 'eitaa' => 'ایتا'][$platform] ?? $platform;
    }
}

if (!function_exists('melkinoTzLabel')) {
    /** برچسب صریح منطقهٔ زمانی مورد استفادهٔ برنامه (بدون ابهام PHP/MySQL). */
    function melkinoTzLabel(): string
    {
        return (string)date_default_timezone_get() ?: ((string)ini_get('date.timezone') ?: 'Asia/Tehran');
    }
}

if (!function_exists('melkinoPublishCapabilities')) {
    /**
     * ماتریس صادقانهٔ قابلیت‌های هر اتصال (بر اساس API واقعیِ همین زیرساخت).
     * «unsupported» یعنی با اتصال فعلی تأیید نشده — هرگز موفقیت جعلی نشان داده نمی‌شود.
     */
    function melkinoPublishCapabilities(): array
    {
        return [
            'telegram' => [
                'label' => 'تلگرام', 'send_text' => 'yes', 'send_photo' => 'yes',
                'send_album' => 'yes', 'send_video' => 'yes',
                'edit_post' => 'yes', 'delete_post' => 'yes',
                'views' => 'no', // شمارندهٔ بازدید در Bot API تلگرام وجود ندارد
                'views_note' => 'شمارندهٔ بازدید در Bot API تلگرام ارائه نمی‌شود؛ فقط کلیک لینک ملکینو قابل اندازه‌گیری است.',
                'max_photos' => 10, 'max_photo_bytes' => 10 * 1024 * 1024, 'max_video_bytes' => 50 * 1024 * 1024,
                'caption_limit' => 1024, 'text_limit' => 4096,
            ],
            'bale' => [
                'label' => 'بله', 'send_text' => 'yes', 'send_photo' => 'yes',
                'send_album' => 'yes', 'send_video' => 'yes',
                'edit_post' => 'yes', 'delete_post' => 'yes',
                'views' => 'no',
                'views_note' => 'API ربات بله شمارندهٔ بازدید برنمی‌گرداند؛ آمار بازدید در دسترس نیست.',
                'max_photos' => 10, 'max_photo_bytes' => 10 * 1024 * 1024, 'max_video_bytes' => 50 * 1024 * 1024,
                'caption_limit' => 1024, 'text_limit' => 4096,
            ],
            'eitaa' => [
                'label' => 'ایتا', 'send_text' => 'yes', 'send_photo' => 'yes',
                'send_album' => 'no', // آلبوم در اتصال فعلی (eitaayar.ir) تأیید نشده
                'send_video' => 'yes', // ویدیو با همان sendFile ارسال می‌شود
                'edit_post' => 'no', 'delete_post' => 'no', // در اتصال فعلی تأیید نشده
                'views' => 'no',
                'views_note' => 'اتصال فعلی ایتا شمارندهٔ بازدید ندارد؛ آمار بازدید در دسترس نیست.',
                'max_photos' => 10, 'max_photo_bytes' => 20 * 1024 * 1024, 'max_video_bytes' => 20 * 1024 * 1024,
                'caption_limit' => 1024, 'text_limit' => 4096,
            ],
        ];
    }
}

/* ======================= ساختار داده ======================= */

if (!function_exists('melkinoPublishEnsureSchema')) {
    function melkinoPublishEnsureSchema(PDO $pdo): void
    {
        // منطقهٔ زمانی صریح و یکسان برای همهٔ مقایسه‌های NOW()/DATE_ADD با ساعت PHP (Asia/Tehran)
        // تا هیچ اختلاف PHP/MySQL در زمان‌بندی ایجاد نشود.
        static $tzSet = false;
        if (!$tzSet) {
            try {
                $pdo->exec("SET time_zone = '+03:30'");
                $tzSet = true;
            } catch (Throwable $e) {
            }
        }
        // D4 (ممیزی r32): در هر درخواست برای هر اتصال PDO فقط یک بار اجرا شود.
        // قبلاً هر tick ده‌ها بار این DDL را اجرا می‌کرد (حدود ۴.۵ ثانیه). اگر خطا بدهد، استثنا بالا می‌رود و دفعهٔ بعد دوباره تلاش می‌شود.
        static $ensuredPdo = null;
        if ($ensuredPdo === $pdo) {
            return;
        }
        $ddl = [
            "CREATE TABLE IF NOT EXISTS publish_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(16) NOT NULL DEFAULT 'ad',
                ad_id VARCHAR(40) NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                body LONGTEXT NULL,
                media JSON NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                campaign_id BIGINT UNSIGNED NULL,
                scheduled_at DATETIME NULL,
                track_link TINYINT(1) NOT NULL DEFAULT 1,
                created_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pi_status (status),
                KEY idx_pi_sched (scheduled_at),
                KEY idx_pi_ad (ad_id),
                KEY idx_pi_camp (campaign_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_targets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                item_id BIGINT UNSIGNED NOT NULL,
                platform VARCHAR(12) NOT NULL,
                channel_id VARCHAR(191) NULL,
                text LONGTEXT NULL,
                media JSON NULL,
                media_mode VARCHAR(12) NOT NULL DEFAULT 'all',
                scheduled_at DATETIME NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                message_ids JSON NULL,
                published_at DATETIME NULL,
                request_id VARCHAR(64) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pt_item_platform (item_id, platform),
                UNIQUE KEY uq_pt_request (request_id),
                KEY idx_pt_status_sched (status, scheduled_at),
                KEY idx_pt_platform (platform, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                target_id BIGINT UNSIGNED NOT NULL,
                attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at DATETIME NULL,
                result VARCHAR(12) NOT NULL DEFAULT 'running',
                code VARCHAR(40) NULL,
                message TEXT NULL,
                request_id VARCHAR(64) NULL,
                PRIMARY KEY (id),
                KEY idx_pa_target (target_id),
                KEY idx_pa_started (started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_campaigns (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                interval_seconds INT UNSIGNED NOT NULL DEFAULT 120,
                starts_at DATETIME NULL,
                note VARCHAR(500) NULL,
                created_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pc_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                item_id BIGINT UNSIGNED NULL,
                ad_id VARCHAR(40) NULL,
                campaign_id BIGINT UNSIGNED NULL,
                platform VARCHAR(12) NULL,
                action VARCHAR(40) NOT NULL,
                status VARCHAR(12) NULL,
                detail VARCHAR(1000) NULL,
                message_ids VARCHAR(500) NULL,
                actor VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ph_ad (ad_id, created_at),
                KEY idx_ph_item (item_id),
                KEY idx_ph_created (created_at),
                KEY idx_ph_platform (platform, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_key VARCHAR(32) NOT NULL,
                action_key VARCHAR(32) NOT NULL DEFAULT 'none',
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                config JSON NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pr_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                platform VARCHAR(12) NOT NULL DEFAULT 'all',
                kind VARCHAR(16) NOT NULL DEFAULT 'ad',
                body LONGTEXT NULL,
                footer VARCHAR(500) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_by VARCHAR(120) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pt_plat (platform, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_rule_runs (
                event_key VARCHAR(32) NOT NULL,
                ad_id VARCHAR(40) NOT NULL,
                ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_key, ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_clicks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token VARCHAR(32) NOT NULL,
                ad_id VARCHAR(40) NOT NULL,
                platform VARCHAR(12) NOT NULL,
                target_id BIGINT UNSIGNED NULL,
                clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_hash CHAR(64) NULL,
                ua_hash CHAR(64) NULL,
                PRIMARY KEY (id),
                KEY idx_pck_token (token),
                KEY idx_pck_ad (ad_id, clicked_at),
                KEY idx_pck_platform (platform, clicked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_view_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                target_id BIGINT UNSIGNED NOT NULL,
                views INT UNSIGNED NULL,
                source VARCHAR(24) NOT NULL DEFAULT 'unsupported',
                note VARCHAR(255) NULL,
                fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_pvs_target (target_id, fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_locks (
                lock_key VARCHAR(64) NOT NULL,
                owner VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (lock_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token VARCHAR(32) NOT NULL,
                ad_id VARCHAR(40) NOT NULL,
                platform VARCHAR(12) NOT NULL,
                target_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pl_token (token),
                KEY idx_pl_ad (ad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_settings (
                k VARCHAR(64) NOT NULL,
                v TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (k)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_dist_rules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                platform VARCHAR(12) NOT NULL,
                channel_id VARCHAR(120) NOT NULL DEFAULT '',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                on_match VARCHAR(16) NOT NULL DEFAULT 'suggest',
                schedule_jalali VARCHAR(32) NULL,
                match_json TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_run_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_dr_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS publish_channels (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                platform VARCHAR(12) NOT NULL,
                channel_id VARCHAR(120) NOT NULL,
                title VARCHAR(120) NOT NULL DEFAULT '',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_pc (platform, channel_id),
                KEY idx_pc_platform (platform, enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($ddl as $sql) {
            $pdo->exec($sql);
        }
        // متن خودکار آگهی: اگر 1 باشد، متن هنگام ارسال از تنظیمات فعلی و داده‌های به‌روز آگهی ساخته می‌شود
        $hasTextAuto = $pdo->query("SHOW COLUMNS FROM publish_targets LIKE 'text_auto'")->fetch();
        if (!$hasTextAuto) {
            $pdo->exec('ALTER TABLE publish_targets ADD COLUMN text_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER text');
        }
        foreach ([['sold', 'flag'], ['rented', 'flag'], ['inactive', 'flag'], ['changed', 'none'], ['expired', 'flag'], ['deleted', 'flag']] as $r) {
            $pdo->prepare('INSERT IGNORE INTO publish_rules (event_key, action_key, enabled) VALUES (?,?,0)')
                ->execute([$r[0], $r[1]]);
        }
        $ensuredPdo = $pdo;
    }
}

/* ======================= تاریخچه ======================= */

if (!function_exists('melkinoPublishHistory')) {
    /**
     * ثبت یک رویداد در تاریخچهٔ یکپارچه. همراه با سازگاری با
     * channel_publish_logs (برای بج‌ها/صفحات قدیمی) در رویدادهای انتشار.
     */
    function melkinoPublishHistory(PDO $pdo, ?int $itemId, ?string $adId, ?string $platform, string $action, ?string $status, string $detail = '', ?string $messageIds = null, string $actor = '', ?int $campaignId = null): void
    {
        try {
            melkinoPublishEnsureSchema($pdo);
            $pdo->prepare(
                'INSERT INTO publish_history (item_id, ad_id, campaign_id, platform, action, status, detail, message_ids, actor)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $itemId, $adId, $campaignId, $platform,
                mb_substr($action, 0, 40), $status,
                mb_substr($detail, 0, 1000), $messageIds ? mb_substr($messageIds, 0, 500) : null,
                mb_substr($actor, 0, 120),
            ]);
        } catch (Throwable $e) {
        }
        // سازگاری: نسخه‌ای فشرده در channel_publish_logs تا بج‌های قدیمی هم به‌روز بمانند
        if ($adId !== null && $platform !== null && $action === 'publish' && $status !== null) {
            try {
                $ok = $status === 'success' ? 1 : 0;
                $note = $status === 'ambiguous' ? 'نامشخص؛ بررسی کانال' : ($detail !== '' ? $detail : ($status === 'success' ? 'انتشار از ماژول انتشار' : 'تلاش ناموفق از ماژول انتشار'));
                $note = '[mk-pub] ' . $note; // نشانهٔ آینه — از ادغام تاریخچه حذف می‌شود
                if (function_exists('melkinoLogChannelPublish')) {
                    melkinoLogChannelPublish($pdo, $adId, $platform, (bool)$ok, $messageIds, mb_substr($note, 0, 250));
                } else {
                    $pdo->exec('CREATE TABLE IF NOT EXISTS channel_publish_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY, ad_id VARCHAR(40) NOT NULL, platform VARCHAR(10) NOT NULL,
                        success TINYINT(1) NOT NULL DEFAULT 0, message_id VARCHAR(60) NULL, note VARCHAR(255) NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_cpl_ad (ad_id), INDEX idx_cpl_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
                    $firstMid = $messageIds ? explode(',', $messageIds)[0] : null;
                    $pdo->prepare('INSERT INTO channel_publish_logs (ad_id, platform, success, message_id, note) VALUES (?,?,?,?,?)')
                        ->execute([$adId, $platform, (int)$ok, $firstMid !== null ? mb_substr($firstMid, 0, 60) : null, mb_substr($note, 0, 250)]);
                }
            } catch (Throwable $e) {
            }
        }
    }
}

/* ======================= قفل Worker ======================= */

if (!function_exists('melkinoPublishLock')) {
    function melkinoPublishLock(PDO $pdo, string $key, string $owner, int $ttl = 120): bool
    {
        try {
            melkinoPublishEnsureSchema($pdo);
            $pdo->exec('DELETE FROM publish_locks WHERE expires_at < NOW()');
            $st = $pdo->prepare('INSERT IGNORE INTO publish_locks (lock_key, owner, expires_at) VALUES (?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))');
            $st->execute([$key, $owner, $ttl]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('melkinoPublishUnlock')) {
    function melkinoPublishUnlock(PDO $pdo, string $key, string $owner): void
    {
        try {
            $pdo->prepare('DELETE FROM publish_locks WHERE lock_key = ? AND owner = ?')->execute([$key, $owner]);
        } catch (Throwable $e) {
        }
    }
}

/* ======================= لینک رهگیری و کلیک ======================= */

if (!function_exists('melkinoPublishPaused')) {
    /** آیا انتشار سراسری متوقف است؟ (توقف کلی — هیچ ارسالی حتی زمان‌بندی‌شده انجام نمی‌شود) */
    function melkinoPublishPaused(PDO $pdo): bool
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->query("SELECT v FROM publish_settings WHERE k = 'paused' LIMIT 1");
        $v = $st ? (string)$st->fetchColumn() : '';
        return $v === '1';
    }
}

if (!function_exists('melkinoPublishSetPaused')) {
    /** توقف/ادامهٔ سراسری انتشار. وضعیت ماندگار است و در تاریخچه ثبت می‌شود. */
    function melkinoPublishSetPaused(PDO $pdo, bool $paused, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $pdo->prepare("INSERT INTO publish_settings (k, v) VALUES ('paused', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([$paused ? '1' : '0']);
        melkinoPublishHistory($pdo, null, null, null, $paused ? 'pause' : 'resume', 'success',
            $paused ? 'انتشار سراسری متوقف شد؛ هیچ برنامه‌ای تا «ادامه» ارسال نمی‌شود.' : 'انتشار سراسری از سر گرفته شد؛ برنامه‌ها طبق زمان‌بندی اجرا می‌شوند.',
            null, $actor);
        return ['success' => true, 'paused' => $paused];
    }
}

if (!function_exists('melkinoPublishEnsureLink')) {
    function melkinoPublishEnsureLink(PDO $pdo, string $adId, string $platform, ?int $targetId = null): string
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT token FROM publish_links WHERE ad_id = ? AND platform = ? LIMIT 1');
        $st->execute([$adId, $platform]);
        $token = (string)$st->fetchColumn();
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(16));
        try {
            $pdo->prepare('INSERT INTO publish_links (token, ad_id, platform, target_id) VALUES (?,?,?,?)')
                ->execute([$token, $adId, $platform, $targetId]);
        } catch (Throwable $e) {
        }
        return $token;
    }
}

if (!function_exists('melkinoPublishTrackUrl')) {
    function melkinoPublishTrackUrl(string $token): string
    {
        $base = (defined('MELKINO_SITE_BASE') ? (string)MELKINO_SITE_BASE : '');
        if ($base === '' && function_exists('melkinoPublicBaseUrl')) {
            try {
                $base = (string)melkinoPublicBaseUrl();
            } catch (Throwable $e) {
                $base = '';
            }
        }
        if ($base === '') {
            $base = '/';
        }
        return rtrim($base, '/') . '/track.php?k=' . rawurlencode($token);
    }
}

if (!function_exists('melkinoPublishRecordClick')) {
    /** ثبت کلیک + برگرداندن اطلاعات مقصد برای هدایت؛ فقط هش IP/UA ذخیره می‌شود. */
    function melkinoPublishRecordClick(PDO $pdo, string $token): ?array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT id, ad_id, platform, target_id FROM publish_links WHERE token = ? LIMIT 1');
        $st->execute([$token]);
        $link = $st->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return null;
        }
        try {
            $pdo->prepare('INSERT INTO publish_clicks (token, ad_id, platform, target_id, ip_hash, ua_hash) VALUES (?,?,?,?,?,?)')
                ->execute([
                    $token, $link['ad_id'], $link['platform'], $link['target_id'],
                    hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|melkino'),
                    hash('sha256', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200) . '|melkino'),
                ]);
            melkinoPublishHistory(
                $pdo, null, (string)$link['ad_id'], (string)$link['platform'],
                'click', 'info', 'کلیک روی لینک اختصاصی', null, 'visitor'
            );
        } catch (Throwable $e) {
        }
        return $link;
    }
}

/* ======================= متن و رسانه ======================= */

if (!function_exists('melkinoPublishAdImages')) {
    /** تصاویرِ قابل انتشار یک آگهی (فقط انتشار-عمومی مجازها). */
    /**
     * مسیر مطلق فایل رسانه: ابتدا program/uploads، سپس uploads ریشهٔ سایت.
     */
    function melkinoPublishAbsPath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
        if ($rel === '') {
            return '';
        }
        $prg = __DIR__ . '/' . $rel;
        if (is_file($prg)) {
            return $prg;
        }
        $web = dirname(__DIR__) . '/' . $rel;
        if (is_file($web)) {
            return $web;
        }
        return $prg;
    }

    /**
     * تصاویر آگهی برای انتشار. با $all=true همهٔ تصاویر آگهی (از جمله
     * تصاویر آپلودی ادمین با publish_publicly=0) برمی‌گردد؛ پیش‌فرض فقط
     * تصاویر عمومی. هر ردیف «url» قابل‌نمایش در پنل (/program/) دارد.
     */
    function melkinoPublishAdImages(PDO $pdo, string $adId, bool $all = false): array
    {
        try {
            $sql = "SELECT id, filename, is_primary, sort_order, is_selected, publish_publicly
                      FROM images
                     WHERE ad_id = ?" . ($all ? '' : ' AND publish_publicly = 1') . "
                   ORDER BY is_primary DESC, sort_order ASC, id ASC";
            $st = $pdo->prepare($sql);
            $st->execute([$adId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $rel = ltrim(str_replace('\\', '/', (string)$r['filename']), '/');
            if (strpos($rel, 'uploads/') !== 0) {
                $rel = 'uploads/' . basename($rel);
            }
            $abs = melkinoPublishAbsPath($rel);
            // URL قابل نمایش از پنل (/program/…): فایل‌های program/uploads با
            // «uploads/x» و فایل‌های uploads ریشه با «../uploads/x» در دسترس‌اند.
            $url = (is_file($abs) && strpos($abs, __DIR__ . DIRECTORY_SEPARATOR) !== 0)
                ? ('../' . $rel) : $rel;
            $out[] = [
                'image_id' => (int)$r['id'],
                'filename' => (string)$r['filename'],
                'storage_path' => is_file($abs) ? $abs : '',
                'url' => $url,
                'public_url' => function_exists('melkinoAdImageUrl') ? (string)melkinoAdImageUrl(['id' => $adId]) : '',
                'sort_order' => (int)$r['sort_order'],
                'is_primary' => (int)$r['is_primary'] === 1,
            ];
            // public_url برای همهٔ عکس‌ها یکسان است فقط وقتی تک‌عکس باشد؛
            // برای چندعکسی، URL عمومی را از مسیر نسبی می‌سازیم:
            if (count($rows) > 1) {
                $out[count($out) - 1]['public_url'] = '';
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoPublishAdText')) {
    /** متن انتشار آگهی (از سازندهٔ مشترک ملکینو) + لینک رهگیری اختصاصی. */
    /* ======================= کانال‌های چندگانه (ثبت‌شده توسط ادمین) ======================= */

    if (!function_exists('melkinoPublishChannelsList')) {
        /** فهرست کانال‌های ثبت‌شده؛ platform خالی = همه. */
        function melkinoPublishChannelsList(PDO $pdo, string $platform = ''): array
        {
            try {
                $sql = "SELECT id, platform, channel_id, title, is_default, enabled
                          FROM publish_channels"
                    . ($platform !== '' ? ' WHERE platform = ?' : '')
                    . " ORDER BY platform ASC, is_default DESC, id ASC";
                $st = $pdo->prepare($sql);
                $st->execute($platform !== '' ? [$platform] : []);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return [];
            }
        }
    }

    if (!function_exists('melkinoPublishChannelSave')) {
        /** ثبت/ویرایش یک کانال. خروجی: success|error. */
        function melkinoPublishChannelSave(PDO $pdo, array $in, string $actor = 'admin'): array
        {
            $platform = (string)($in['platform'] ?? '');
            $channelId = trim((string)($in['channel_id'] ?? ''));
            $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 120);
            $id = (int)($in['id'] ?? 0);
            if (!in_array($platform, ['telegram', 'bale', 'eitaa'], true)) {
                return ['success' => false, 'error' => 'پیام‌رسان نامعتبر است.'];
            }
            if ($channelId === '' || !preg_match('/^(@?[A-Za-z0-9_]{3,100}|-?\d{4,20})$/', $channelId)) {
                return ['success' => false, 'error' => 'شناسهٔ کانال نامعتبر است (مانند @channel_name یا شمارهٔ عددی).'];
            }
            try {
                if ($id > 0) {
                    $pdo->prepare('UPDATE publish_channels SET platform = ?, channel_id = ?, title = ? WHERE id = ?')
                        ->execute([$platform, $channelId, $title, $id]);
                    melkinoPublishHistory($pdo, null, null, $platform, 'channel_save', 'info', 'ویرایش کانال «' . ($title ?: $channelId) . '» (' . $channelId . ')', null, $actor);
                    return ['success' => true, 'id' => $id];
                }
                $pdo->prepare('INSERT INTO publish_channels (platform, channel_id, title) VALUES (?,?,?)')
                    ->execute([$platform, $channelId, $title]);
                $newId = (int)$pdo->lastInsertId();
                melkinoPublishHistory($pdo, null, null, $platform, 'channel_save', 'info', 'افزودن کانال «' . ($title ?: $channelId) . '» (' . $channelId . ')', null, $actor);
                return ['success' => true, 'id' => $newId];
            } catch (Throwable $e) {
                $dup = stripos($e->getMessage(), '23000') !== false || stripos($e->getMessage(), 'Duplicate') !== false;
                return ['success' => false, 'error' => $dup ? 'این کانال قبلاً ثبت شده است.' : 'ثبت کانال ناموفق بود.'];
            }
        }
    }

    if (!function_exists('melkinoPublishChannelDelete')) {
        function melkinoPublishChannelDelete(PDO $pdo, int $id, string $actor = 'admin'): array
        {
            if ($id <= 0) {
                return ['success' => false, 'error' => 'شناسه نامعتبر است.'];
            }
            try {
                $st = $pdo->prepare('SELECT platform, channel_id, title FROM publish_channels WHERE id = ?');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return ['success' => false, 'error' => 'کانال یافت نشد.'];
                }
                $pdo->prepare('DELETE FROM publish_channels WHERE id = ?')->execute([$id]);
                melkinoPublishHistory($pdo, null, null, (string)$row['platform'], 'channel_delete', 'info', 'حذف کانال «' . ((string)$row['title'] ?: (string)$row['channel_id']) . '»', null, $actor);
                return ['success' => true];
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'حذف ناموفق بود.'];
            }
        }
    }

    /* ======================= آپلود تصویر دلخواه برای انتشار آگهی ======================= */

    if (!function_exists('melkinoPublishAdPhotoStore')) {
        /**
         * ذخیرهٔ تصویر آپلودی در program/uploads + ثبت ردیف images (غیرعمومی).
         * $bytes محتوای فایل؛ $ext پسوند نرمال‌شده (jpg|png|webp|gif).
         * هرگز فایل خارج از program/uploads نمی‌سازد؛ نام تصادفی + کنترل MIME.
         */
        function melkinoPublishAdPhotoStore(PDO $pdo, string $adId, string $bytes, string $ext, string $mime): array
        {
            $ext = strtolower($ext);
            $allow = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
            if (!isset($allow[$ext])) {
                return ['success' => false, 'error' => 'فرمت تصویر پشتیبانی نمی‌شود (jpg/png/webp/gif).'];
            }
            if (strlen($bytes) < 64 || strlen($bytes) > 8 * 1024 * 1024) {
                return ['success' => false, 'error' => 'حجم تصویر باید بین ۶۴ بایت و ۸ مگابایت باشد.'];
            }
            $det = function_exists('mime_content_type') ? @mime_content_type('data://application/octet-stream;base64,' . base64_encode($bytes)) : false;
            $okMime = in_array((string)$det, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) || (string)$det === $allow[$ext];
            if ($det !== false && !$okMime) {
                return ['success' => false, 'error' => 'نوع فایل با تصویر سازگار نیست.'];
            }
            if (function_exists('getimagesizefromstring')) {
                $info = @getimagesizefromstring($bytes);
                if ($info === false) {
                    return ['success' => false, 'error' => 'فایل تصویر معتبر نیست.'];
                }
            }
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $name = 'ad_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $abs = $dir . '/' . $name;
            if (@file_put_contents($abs, $bytes) === false) {
                return ['success' => false, 'error' => 'ذخیرهٔ فایل ناموفق بود.'];
            }
            try {
                $ord = 1000;
                try {
                    $stO = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM images WHERE ad_id = ?');
                    $stO->execute([$adId]);
                    $ord = (int)$stO->fetchColumn();
                } catch (Throwable $e) {
                }
                $pdo->prepare('INSERT INTO images (ad_id, filename, storage_path, sort_order, is_primary, is_selected, publish_publicly) VALUES (?,?,?,?,0,0,0)')
                    ->execute([$adId, 'uploads/' . $name, $abs, $ord]);
                $iid = (int)$pdo->lastInsertId();
                melkinoPublishHistory($pdo, null, (string)$adId, null, 'ad_photo_add', 'info', 'افزودن تصویر به آگهی (' . $name . ')', null, 'admin');
                return [
                    'success' => true, 'image_id' => $iid,
                    'filename' => 'uploads/' . $name,
                    'url' => 'uploads/' . $name,
                    'storage_path' => $abs,
                ];
            } catch (Throwable $e) {
                @unlink($abs);
                return ['success' => false, 'error' => 'ثبت تصویر در پایگاه‌داده ناموفق بود.'];
            }
        }
    }

    function melkinoPublishAdText(array $ad, string $platform, ?int $targetId, bool $withTrack): string
    {
        $html = $platform === 'telegram';
        // بدون رندرر کامل آگهی، هرگز متن ناقص (فقط عنوان) ساخته نمی‌شود:
        // ارسال خودکار هنگام بازدید صفحه‌ها این حالت را دارد و قبلاً فقط عنوان + لینک منتشر می‌کرد.
        if (!function_exists('melkinoAdMessageText')) {
            return '';
        }
        $text = (string)melkinoAdMessageText($ad, $html, $platform);
        if ($withTrack && !empty($ad['id'])) {
            global $pdo;
            $token = melkinoPublishEnsureLink($pdo, (string)$ad['id'], $platform, $targetId);
            $text .= "\n\n🔗 مشاهدهٔ آگهی در ملکینو: " . melkinoPublishTrackUrl($token);
        }
        return $text;
    }
}

/* ======================= ارسال (Adapter) ======================= */

if (!function_exists('melkinoPublishToken')) {
    function melkinoPublishToken(string $platform): string
    {
        if ($platform === 'eitaa') {
            $t = function_exists('melkinoBotSetting') ? trim((string)melkinoBotSetting('eitaa_channel_token')) : '';
        } elseif ($platform === 'bale') {
            $t = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
        } else {
            $t = function_exists('melkinoTelegramToken') ? (string)melkinoTelegramToken() : '';
        }
        // نرمال‌سازی: فاصله/خط جدید + پیشوند bot (خطای رایج کپی از مستندات)
        $t = preg_replace('/\s+/', '', (string)$t) ?? '';
        if ($platform !== 'eitaa' && preg_match('/^bot(\d+:[A-Za-z0-9_-]+)$/', $t, $mm)) {
            $t = $mm[1];
        }
        return $t;
    }
}

if (!function_exists('melkinoPublishChannelId')) {
    function melkinoPublishChannelId(string $platform): string
    {
        $key = $platform === 'eitaa' ? 'eitaa_channel' : ($platform . '_channel');
        $v = function_exists('melkinoBotSetting') ? trim((string)melkinoBotSetting($key)) : '';
        return $v;
    }
}

if (!function_exists('melkinoPublishProvider')) {
    function melkinoPublishProvider(string $platform): string
    {
        return $platform === 'eitaa' ? 'eitaa_channel' : $platform;
    }
}

if (!function_exists('melkinoPublishPlan')) {
    /**
     * برنامهٔ پیام‌ها: [ [method, params, media_ref], ... ]
     * تقسیم چندپیامی همیشه از قبل در پیش‌نمایش اعلام می‌شود.
     */
    /**
     * نوع رسانه برای انتخاب روش ارسال: photo | video | document (بر اساس پسوند فایل)
     */
    function melkinoPublishMediaKind(string $path): string
    {
        $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'mov', 'm4v', 'mkv', 'avi'], true)) {
            return 'video';
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true)) {
            return 'photo';
        }
        return 'document';
    }

    /**
     * متد و نام فیلد فایل برای هر نوع رسانه در هر پیام‌رسان.
     * eitaa همه‌چیز را با sendFile/file می‌فرستد؛ تلگرام/بله sendVideo/sendPhoto/sendDocument.
     */
    function melkinoPublishMediaMethod(string $platform, string $kind): array
    {
        if (melkinoPublishProvider($platform) === 'eitaa_channel') {
            return ['sendFile', 'file'];
        }
        if ($kind === 'video') {
            return ['sendVideo', 'video'];
        }
        if ($kind === 'photo') {
            return ['sendPhoto', 'photo'];
        }
        return ['sendDocument', 'document'];
    }

    function melkinoPublishPlan(string $platform, string $chatId, string $text, array $media): array
    {
        $caps = melkinoPublishCapabilities()[$platform] ?? [];
        $maxPhotos = (int)($caps['max_photos'] ?? 10);
        $maxBytes = (int)($caps['max_photo_bytes'] ?? 10485760);
        $maxVideoBytes = (int)($caps['max_video_bytes'] ?? 52428800);
        $provider = melkinoPublishProvider($platform);
        $media = array_values(array_filter($media, static function ($m) {
            return !empty($m['storage_path']) && is_file($m['storage_path']);
        }));
        foreach ($media as $i => $m) {
            $kindI = melkinoPublishMediaKind((string)($m['storage_path'] ?? ''));
            $media[$i]['_kind'] = $kindI;
            $limitI = $kindI === 'video' ? $maxVideoBytes : $maxBytes;
            if (filesize($m['storage_path']) > $limitI) {
                $media[$i]['_oversize'] = true;
            }
        }
        $oversize = array_filter($media, static fn($m) => !empty($m['_oversize']));
        $media = array_values(array_filter($media, static fn($m) => empty($m['_oversize'])));
        $plan = [];
        $notes = [];
        if ($oversize) {
            $notes[] = count($oversize) . ' تصویر به‌دلیل حجم بیش از حد مجاز مقصد ارسال نمی‌شود.';
        }
        if (count($media) === 0) {
            // متن‌های بلند: تقسیم به چند پیام (حد تلگرام ۴۰۹۶ نویسه)
            $limit = $platform === 'telegram' ? 4000 : 4000;
            $chunks = [];
            if (mb_strlen($text) > $limit) {
                $chunks = mb_str_split($text, $limit);
                $notes[] = 'متن بلندتر از حد یک پیام بود؛ در ' . count($chunks) . ' پیام پشت‌سرهم ارسال شد.';
            } else {
                $chunks[] = $text;
            }
            foreach ($chunks as $ci => $chunk) {
                $plan[] = ['method' => 'sendMessage', 'params' => ['chat_id' => $chatId, 'text' => $chunk] + ($platform === 'telegram' ? ['parse_mode' => 'HTML'] : []), 'media_ref' => $ci === 0 ? null : 'text-part:' . $ci];
            }
            return [$plan, $notes];
        }
        // آلبوم فقط برای مجموعه‌ای تماماً عکس؛ ویدیو/فایل همیشه تک‌تک با متد درست می‌رود
        $allPhotos = true;
        foreach ($media as $m) {
            if (($m['_kind'] ?? 'photo') !== 'photo') {
                $allPhotos = false;
                break;
            }
        }
        $useAlbum = ($caps['send_album'] ?? 'no') === 'yes' && count($media) > 1 && $allPhotos;
        if ($useAlbum && count($media) <= $maxPhotos) {
            // آلبوم چندعکسی: multipart با attach:// (فایل‌ها هرگز داخل JSON نمی‌روند)
            $group = [];
            $files = [];
            foreach (array_slice($media, 0, $maxPhotos) as $i => $m) {
                $key = 'f' . $i;
                $item = ['type' => 'photo', 'media' => 'attach://' . $key];
                if ($i === 0) {
                    $capLimit = (int)($caps['caption_limit'] ?? 1024);
                    $item['caption'] = mb_substr($text, 0, $capLimit);
                    if (mb_strlen($text) > $capLimit) {
                        $notes[] = 'متن طولانی‌تر از حد کپشن است؛ ادامهٔ متن به‌صورت پیام جدا ارسال می‌شود.';
                        $plan[] = [
                            'method' => 'sendMessage',
                            'params' => ['chat_id' => $chatId, 'text' => $text] + ($platform === 'telegram' ? ['parse_mode' => 'HTML'] : []),
                            'media_ref' => null,
                        ];
                    }
                }
                $group[] = $item;
                $files[$key] = new CURLFile($m['storage_path']);
            }
            if (count($media) > $maxPhotos) {
                $notes[] = 'تعداد تصاویر بیش از حد مجاز آلبوم است؛ ' . (count($media) - $maxPhotos) . ' تصویر باقی‌مانده جدا ارسال می‌شود.';
            }
            array_unshift($plan, ['method' => 'sendMediaGroup', 'params' => ['chat_id' => $chatId, 'media' => json_encode($group, JSON_UNESCAPED_UNICODE)] + $files, 'media_ref' => 'album:' . count($group), 'album_files' => array_column(array_slice($media, 0, $maxPhotos), 'storage_path')]);
        } else {
            if (count($media) > 1) {
                $notes[] = 'این انتشار در ' . count($media) . ' پیام جدا (به‌همراه متن) ارسال می‌شود.';
            }
            foreach ($media as $i => $m) {
                // باگ r19: ویدیو قبلاً با sendPhoto می‌رفت (تلگرام رد می‌کرد، بله عکس
                // بی‌متن می‌ساخت، فقط ایتا با sendFile درست می‌رفت) — حالا متد بر اساس نوع فایل
                [$method, $field] = melkinoPublishMediaMethod($platform, (string)($m['_kind'] ?? 'photo'));
                $params = ['chat_id' => $chatId, $field => new CURLFile($m['storage_path'])];
                if ($i === 0) {
                    $capLimit = (int)($caps['caption_limit'] ?? 1024);
                    $params['caption'] = mb_substr($text, 0, $capLimit);
                    if (mb_strlen($text) > $capLimit) {
                        $plan[] = ['method' => 'sendMessage', 'params' => ['chat_id' => $chatId, 'text' => $text] + ($platform === 'telegram' ? ['parse_mode' => 'HTML'] : []), 'media_ref' => null];
                    }
                }
                $plan[] = ['method' => $method, 'params' => $params, 'media_ref' => $m['filename'] ?? null];
            }
        }
        return [$plan, $notes];
    }
}

if (!function_exists('melkinoPublishMapState')) {
    function melkinoPublishMapState(array $resp): string
    {
        $state = (string)($resp['state'] ?? '');
        if (!empty($resp['ok'])) {
            return 'success';
        }
        return $state === 'failed' ? 'failed' : 'ambiguous';
    }
}

if (!function_exists('melkinoPublishSendTarget')) {
    /**
     * ارسالِ یک مقصد (target) با Snapshot خودش. نتیجهٔ واقعی هر پیام ثبت می‌شود.
     * Idempotency: اگر همین target قبلاً موفق منتشر شده یا request_id تکراری است،
     * هیچ ارسال جدیدی انجام نمی‌شود.
     */
    function melkinoPublishSendTarget(PDO $pdo, int $targetId, ?callable $transport = null, string $actor = 'worker', bool $isRetry = false): array
    {
        melkinoPublishEnsureSchema($pdo);
        if (melkinoPublishPaused($pdo)) {
            return ['ok' => false, 'state' => 'skipped', 'message' => 'انتشار سراسری متوقف است؛ تا زمان «ادامه» هیچ ارسالی انجام نمی‌شود.'];
        }
        $st = $pdo->prepare('SELECT t.*, i.kind, i.ad_id, i.title, i.track_link FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE t.id = ? LIMIT 1');
        $st->execute([$targetId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return ['ok' => false, 'state' => 'failed', 'message' => 'مقصد پیدا نشد.'];
        }
        if (in_array($t['status'], ['published', 'cancelled'], true)) {
            return ['ok' => true, 'state' => 'skipped', 'message' => 'این مقصد قبلاً ' . ($t['status'] === 'published' ? 'منتشر شده' : 'لغو شده') . ' است.'];
        }
        // بازبینی تأیید در لحظهٔ اجرا: آگهی تأییدنشده هرگز ارسال نمی‌شود
        if ((string)$t['kind'] === 'ad' && $t['ad_id'] !== null && $t['ad_id'] !== '') {
            $stAd = $pdo->prepare('SELECT status FROM ads WHERE id = ? LIMIT 1');
            $stAd->execute([(string)$t['ad_id']]);
            $adStatus = (string)$stAd->fetchColumn();
            if (in_array($adStatus, ['pending', 'rejected', 'draft'], true)) {
                $msg = 'آگهی تأیید نیست (وضعیت: ' . $adStatus . ')؛ ارسال انجام نشد.';
                $pdo->prepare('UPDATE publish_targets SET status = ?, last_error = ? WHERE id = ?')->execute(['cancelled', mb_substr($msg, 0, 2000), $targetId]);
                melkinoPublishHistory($pdo, (int)$t['item_id'], (string)$t['ad_id'], (string)$t['platform'], 'publish', 'failed', $msg, null, $actor, $t['campaign_id'] ?? null);
                return ['ok' => false, 'state' => 'skipped', 'message' => $msg];
            }
        }
        // متن خودکار آگهی: هنگام ارسال با تنظیمات فعلی (مثلاً قیمت خاموش‌شده) و داده‌های به‌روز آگهی بازسازی می‌شود
        if ((int)($t['text_auto'] ?? 0) === 1 && (string)$t['kind'] === 'ad' && (string)($t['ad_id'] ?? '') !== '') {
            $stAdRow = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
            $stAdRow->execute([(string)$t['ad_id']]);
            $adRow = $stAdRow->fetch(PDO::FETCH_ASSOC);
            if ($adRow) {
                $fresh = melkinoPublishAdText($adRow, (string)$t['platform'], null, (bool)$t['track_link']);
                if ($fresh !== '' && $fresh !== (string)($t['text'] ?? '')) {
                    $pdo->prepare('UPDATE publish_targets SET text = ? WHERE id = ?')->execute([$fresh, $targetId]);
                    $t['text'] = $fresh;
                }
            }
        }
        $platform = (string)$t['platform'];
        $provider = melkinoPublishProvider($platform);
        $token = melkinoPublishToken($platform);
        $chatId = (string)($t['channel_id'] !== null && $t['channel_id'] !== '' ? $t['channel_id'] : melkinoPublishChannelId($platform));
        if ($transport === null && ($token === '' || $chatId === '')) {
            $msg = 'توکن یا شناسهٔ کانال ' . melkinoPublishPlatformLabel($platform) . ' تنظیم نشده است.';
            melkinoPublishHistory($pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, $platform, 'publish', 'failed', $msg, null, $actor, $t['campaign_id'] ?? null);
            return ['ok' => false, 'state' => 'failed', 'message' => $msg];
        }
        if ($chatId === '') {
            $chatId = 'mock-chat'; // transport سفارشی/تستی
        }
        $media = json_decode((string)($t['media'] ?? '[]'), true) ?: [];
        [$plan, $notes] = melkinoPublishPlan($platform, $chatId, (string)($t['text'] ?? ''), is_array($media) ? $media : []);

        $pdo->prepare('UPDATE publish_targets SET status = ?, attempts = attempts + 1 WHERE id = ?')->execute(['sending', $targetId]);
        $attemptNo = (int)$t['attempts'] + 1;
        $attSt = $pdo->prepare("INSERT INTO publish_attempts (target_id, attempt_no, request_id, result) VALUES (?,?,?,'running')");
        $reqId = (string)($t['request_id'] ?: ('pt-' . $targetId . '-' . bin2hex(random_bytes(6))));
        $attSt->execute([$targetId, $attemptNo, $reqId]);
        $attemptId = (int)$pdo->lastInsertId();

        $sentIds = [];
        $resultState = 'success';
        $failMsg = '';
        foreach ($plan as $step) {
            $params = $step['params'];
            try {
                $resp = melkinoBotApiRequest($provider, $step['method'], $params, $token, $transport);
            } catch (Throwable $e) {
                // استثنا پس از تلاش ارسال = نتیجهٔ مبهم (ممکن است پیام رسیده باشد)
                $resp = ['ok' => false, 'state' => 'unknown', 'code' => 'exception', 'message' => 'خطای ارتباطی هنگام ارسال؛ وضعیت تحویل نامشخص است: ' . $e->getMessage(), 'result' => null];
            }
            // خطای قطعی پارس HTML تلگرام (متن‌های دستی شامل < یا &) → تکرار بدون parse_mode (پیام قطعاً ثبت نشده)
            if ($platform === 'telegram'
                && !empty($resp['ok'] === false)
                && (string)($resp['state'] ?? '') === 'failed'
                && isset($step['params']['parse_mode'])
                && preg_match('/parse entit|can.t parse|unsupported start tag|unexpected end tag|unbalanced tag/i', (string)($resp['message'] ?? ''))) {
                $pNo = $step['params'];
                unset($pNo['parse_mode']);
                try {
                    $resp2 = melkinoBotApiRequest($provider, $step['method'], $pNo, $token, $transport);
                    if (!empty($resp2['ok'])) {
                        $resp = $resp2;
                        $notes[] = 'متن شامل نویسه‌های ویژه بود؛ بدون قالب HTML ارسال شد.';
                    }
                } catch (Throwable $e2) {
                    // نتیجه قبلی معتبر می‌ماند
                }
            }
            $mapped = melkinoPublishMapState($resp);
            if ($mapped === 'success') {
                $res = $resp['result'] ?? null;
                $mid = null;
                if (is_array($res)) {
                    $mid = $res['message_id'] ?? ($res[0]['message_id'] ?? null);
                }
                $sentIds[] = ['message_id' => $mid !== null ? (string)$mid : null, 'media_ref' => $step['media_ref'], 'method' => $step['method']];
                if ($step['method'] === 'sendMediaGroup' && is_array($res)) {
                    foreach ($res as $rr) {
                        if (is_array($rr) && isset($rr['message_id'])) {
                            $sentIds[] = ['message_id' => (string)$rr['message_id'], 'media_ref' => 'album-part', 'method' => 'sendMediaGroup'];
                        }
                    }
                }
            } elseif ($mapped === 'failed' && $step['method'] === 'sendMediaGroup') {
                // آلبوم قطعاً رد شد → تلاش مجدد به‌صورت تک‌تک عکس‌ها (بدون ارسال دوبارهٔ متن اضافه)
                // باگ r19: کپشن داخل media JSON بود ولی هرگز برنمی‌گشت (فقط comment بود) →
                // همهٔ فایل‌ها بی‌متن می‌رفتند («فقط خالی عکس/ویدیو»). متن روی اولین فایل +
                // متد/فیلد درست بر اساس نوع فایل (ویدیو: sendVideo نه sendPhoto).
                $grpJson = json_decode((string)($step['params']['media'] ?? '[]'), true);
                $fbCaption = is_array($grpJson) && isset($grpJson[0]['caption']) ? (string)$grpJson[0]['caption'] : '';
                foreach ($step['album_files'] ?? [] as $fi => $fpath) {
                    [$fMethod, $fField] = melkinoPublishMediaMethod($platform, melkinoPublishMediaKind((string)$fpath));
                    $p2 = ['chat_id' => $chatId, $fField => new CURLFile($fpath)];
                    if ($fi === 0 && $fbCaption !== '') {
                        $p2['caption'] = $fbCaption;
                    }
                    try {
                        $r2 = melkinoBotApiRequest($provider, $fMethod, $p2, $token, $transport);
                    } catch (Throwable $e) {
                        $r2 = ['ok' => false, 'state' => 'unknown', 'message' => 'خطای ارتباطی؛ وضعیت تحویل نامشخص است: ' . $e->getMessage()];
                    }
                    $m2 = melkinoPublishMapState($r2);
                    if ($m2 === 'ambiguous') {
                        $resultState = 'unknown';
                        $failMsg = (string)($r2['message'] ?? 'وضعیت تحویل نامشخص است.');
                        break;
                    }
                    if ($m2 === 'success') {
                        $mid = is_array($r2['result'] ?? null) ? ($r2['result']['message_id'] ?? null) : null;
                        $sentIds[] = ['message_id' => $mid !== null ? (string)$mid : null, 'media_ref' => 'album-fallback:' . $fi, 'method' => $fMethod];
                    } else {
                        $resultState = $m2 === 'ambiguous' ? 'ambiguous' : ($resultState === 'success' ? 'partial' : $resultState);
                        $failMsg = (string)($r2['message'] ?? 'ارسال تصویر ناموفق بود.');
                    }
                }
                if ($resultState === 'success') {
                    $notes[] = 'آلبوم توسط مقصد پشتیبانی نشد؛ تصاویر جدا ارسال شدند.';
                }
            } else {
                $resultState = $mapped === 'ambiguous' ? ($resultState === 'success' ? 'unknown' : $resultState) : ($mapped === 'failed' ? ($sentIds ? 'partial' : 'failed') : $resultState);
                $failMsg = (string)($resp['message'] ?? 'ارسال ناموفق بود.');
                if ($mapped === 'ambiguous') {
                    break; // وضعیت نامشخص → هیچ تلاش بعدی در همین اجرا (عدم ارسال کورکورانه)
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE publish_attempts SET finished_at = ?, result = ?, code = ?, message = ? WHERE id = ?')
            ->execute([$now, $resultState === 'unknown' ? 'ambiguous' : ($resultState === 'success' ? 'success' : 'failed'), mb_substr($resultState . ':' . ($failMsg !== '' ? 'err' : 'ok'), 0, 40), mb_substr(trim($failMsg . ' ' . implode(' | ', $notes) . ($resultState === 'partial' ? ' (ارسال ناقص)' : '')), 0, 2000), $attemptId]);

        $statusMap = [
            'success' => 'published', 'partial' => 'partial',
            'failed' => 'failed', 'unknown' => 'needs_review',
        ];
        $newStatus = $statusMap[$resultState] ?? 'failed';
        $pdo->prepare('UPDATE publish_targets SET status = ?, message_ids = ?, published_at = ?, last_error = ?, request_id = ? WHERE id = ?')
            ->execute([
                $newStatus,
                json_encode($sentIds, JSON_UNESCAPED_UNICODE) ?: '[]',
                $newStatus === 'published' ? $now : null,
                $failMsg !== '' ? mb_substr($failMsg . ($notes ? ' | ' . implode(' | ', $notes) : ''), 0, 2000) : ($notes ? mb_substr(implode(' | ', $notes), 0, 2000) : null),
                $reqId,
                $targetId,
            ]);

        // وضعیت کلی محتوا
        $cnt = $pdo->prepare("SELECT SUM(status='published') AS pub, SUM(status IN ('failed','needs_review')) AS bad, COUNT(*) AS total FROM publish_targets WHERE item_id = ?");
        $cnt->execute([(int)$t['item_id']]);
        $c = $cnt->fetch(PDO::FETCH_ASSOC) ?: [];
        $itemStatus = ((int)($c['pub'] ?? 0) >= (int)($c['total'] ?? 1)) ? 'published' : (((int)($c['bad'] ?? 0) > 0 && (int)($c['pub'] ?? 0) > 0) ? 'partial' : (((int)($c['bad'] ?? 0) > 0) ? 'failed' : 'queued'));
        $pdo->prepare('UPDATE publish_items SET status = ? WHERE id = ?')->execute([$itemStatus, (int)$t['item_id']]);

        $histStatus = $newStatus === 'published' ? 'success' : ($newStatus === 'needs_review' ? 'ambiguous' : 'failed');
        melkinoPublishHistory(
            $pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, $platform,
            $isRetry ? 'retry' : 'publish', $histStatus,
            trim(($failMsg !== '' ? $failMsg . ' ' : '') . implode(' | ', $notes) . ($newStatus === 'published' ? ' ارسال شد.' : '')),
            $sentIds ? implode(',', array_filter(array_column($sentIds, 'message_id'))) : null,
            $actor, $t['campaign_id'] ?? null
        );

        return [
            'ok' => in_array($newStatus, ['published', 'partial'], true),
            'state' => $newStatus,
            'message_ids' => $sentIds,
            'notes' => $notes,
            'error' => $failMsg,
        ];
    }
}

if (!function_exists('melkinoPublishRunDue')) {
    /**
     * Worker: اجرای وظایف سررسید. قفل فایلی + قفل دیتابیسی + Idempotency.
     * وظایف عقب‌افتاده (هنگام قطعی سرور) با تأخیر اجرا و در تاریخچه ثبت می‌شوند.
     */
    function melkinoPublishRunDue(PDO $pdo, int $max = 5, ?callable $transport = null, string $actor = 'worker'): array
    {
        if (melkinoPublishPaused($pdo)) {
            return ['ok' => true, 'state' => 'paused', 'sent' => [], 'note' => 'انتشار سراسری متوقف است؛ صف اجرا نشد.'];
        }
        melkinoPublishEnsureSchema($pdo);
        $owner = gethostname() . ':' . getmypid() . ':' . substr(bin2hex(random_bytes(4)), 0, 8);
        if (!melkinoPublishLock($pdo, 'publish_worker', $owner, 120)) {
            return ['ok' => false, 'message' => 'اجرای دیگری در جریان است (قفل).'];
        }
        $summary = ['ok' => true, 'ran' => 0, 'published' => 0, 'failed' => 0, 'needs_review' => 0, 'items' => []];
        try {
            $st = $pdo->prepare(
                "SELECT t.id, t.item_id, t.scheduled_at AS t_sched, i.scheduled_at AS i_sched, i.campaign_id
                   FROM publish_targets t
                   JOIN publish_items i ON i.id = t.item_id
                  WHERE t.status IN ('pending','queued')
                    AND i.status NOT IN ('cancelled')
                    AND (t.scheduled_at IS NOT NULL OR i.scheduled_at IS NOT NULL)
                    AND COALESCE(t.scheduled_at, i.scheduled_at) <= NOW()
                  ORDER BY COALESCE(t.scheduled_at, i.scheduled_at) ASC, t.id ASC
                  LIMIT ?"
            );
            $st->bindValue(1, max(1, min(50, $max)), PDO::PARAM_INT);
            $st->execute();
            $due = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($due as $row) {
                $sched = (string)($row['t_sched'] ?? $row['i_sched'] ?? '');
                $overdue = $sched !== '' && (time() - strtotime($sched)) > 90;
                if ($overdue) {
                    melkinoPublishHistory($pdo, (int)$row['item_id'], null, null, 'run_overdue', 'info', 'اجرا با تأخیر نسبت به زمان برنامه‌ریزی‌شده (' . $sched . ') — مقصد #' . (int)$row['id'], null, $actor, isset($row['campaign_id']) ? (int)$row['campaign_id'] : null);
                }
                $r = melkinoPublishSendTarget($pdo, (int)$row['id'], $transport, $actor);
                $summary['ran']++;
                $st2 = (string)($r['state'] ?? 'failed');
                if ($st2 === 'published') {
                    $summary['published']++;
                } elseif ($st2 === 'needs_review') {
                    $summary['needs_review']++;
                } elseif ($st2 === 'failed') {
                    $summary['failed']++;
                    // تلاش مجدد با فاصلهٔ افزایشی: حداکثر ۳ تلاش
                    try {
                        $pdo->prepare("UPDATE publish_targets SET status='queued', scheduled_at = DATE_ADD(NOW(), INTERVAL (2 + attempts * 3) MINUTE) WHERE id = ? AND status = 'failed' AND attempts < 3")
                            ->execute([(int)$row['id']]);
                    } catch (Throwable $e) {
                    }
                }
                $summary['items'][] = ['target_id' => (int)$row['id'], 'state' => $st2];
            }
        } finally {
            melkinoPublishUnlock($pdo, 'publish_worker', $owner);
        }
        return $summary;
    }
}

/* ======================= عملیات روی مقصد/محتوا ======================= */

if (!function_exists('melkinoPublishCancelTarget')) {
    function melkinoPublishCancelTarget(PDO $pdo, int $targetId, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT t.*, i.ad_id FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE t.id = ?');
        $st->execute([$targetId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return ['ok' => false, 'message' => 'مقصد پیدا نشد.'];
        }
        if ($t['status'] === 'published') {
            return ['ok' => false, 'message' => 'این مقصد قبلاً منتشر شده است؛ برای حذف پست از «مدیریت پست» استفاده کنید.'];
        }
        $pdo->prepare("UPDATE publish_targets SET status='cancelled' WHERE id = ?")->execute([$targetId]);
        melkinoPublishHistory($pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, (string)$t['platform'], 'cancel', 'info', 'لغو انتشار زمان‌بندی‌شده', null, $actor);
        return ['ok' => true, 'message' => 'انتشار لغو شد.'];
    }
}

if (!function_exists('melkinoPublishReschedule')) {
    function melkinoPublishReschedule(PDO $pdo, int $targetId, string $whenSql, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT t.*, i.ad_id FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE t.id = ?');
        $st->execute([$targetId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return ['ok' => false, 'message' => 'مقصد پیدا نشد.'];
        }
        if ($t['status'] === 'published') {
            return ['ok' => false, 'message' => 'مقصد منتشرشده قابل زمان‌بندی مجدد نیست.'];
        }
        $pdo->prepare("UPDATE publish_targets SET scheduled_at = ?, status = CASE WHEN status='cancelled' THEN 'queued' ELSE status END WHERE id = ?")
            ->execute([$whenSql, $targetId]);
        melkinoPublishHistory($pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, (string)$t['platform'], 'reschedule', 'info', 'تغییر زمان انتشار به ' . $whenSql, null, $actor);
        return ['ok' => true, 'message' => 'زمان انتشار تغییر کرد.'];
    }
}

if (!function_exists('melkinoPublishEditOrDelete')) {
    /**
     * ویرایش/حذف پست منتشرشده — فقط با پشتیبانی واقعیِ اتصال.
     * عدم پشتیبانی هرگز موفقیت ثبت نمی‌شود.
     */
    function melkinoPublishEditOrDelete(PDO $pdo, int $targetId, string $mode, string $newText = '', string $actor = 'admin', ?callable $transport = null): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT t.*, i.ad_id FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE t.id = ?');
        $st->execute([$targetId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            return ['ok' => false, 'state' => 'failed', 'message' => 'مقصد پیدا نشد.'];
        }
        $platform = (string)$t['platform'];
        $caps = melkinoPublishCapabilities()[$platform] ?? [];
        $support = $mode === 'edit' ? ($caps['edit_post'] ?? 'no') : ($caps['delete_post'] ?? 'no');
        if ($support !== 'yes') {
            melkinoPublishHistory($pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, $platform, $mode === 'edit' ? 'edit_post' : 'delete_post', 'failed', 'این اتصال از ' . ($mode === 'edit' ? 'ویرایش' : 'حذف') . ' پست پشتیبانی نمی‌کند.', null, $actor);
            return ['ok' => false, 'state' => 'unsupported', 'message' => 'اتصال ' . melkinoPublishPlatformLabel($platform) . ' از این عملیات پشتیبانی نمی‌کند (تأیید نشده).'];
        }
        $ids = json_decode((string)($t['message_ids'] ?? '[]'), true) ?: [];
        $token = melkinoPublishToken($platform);
        $provider = melkinoPublishProvider($platform);
        $done = 0;
        $errs = [];
        foreach ($ids as $m) {
            $mid = (string)($m['message_id'] ?? '');
            if ($mid === '') {
                continue;
            }
            $method = $mode === 'edit' ? 'editMessageText' : 'deleteMessage';
            $params = ['chat_id' => (string)($t['channel_id'] ?: melkinoPublishChannelId($platform)), 'message_id' => $mid];
            if ($mode === 'edit') {
                $params['text'] = $newText !== '' ? $newText : (string)$t['text'];
                if ($platform === 'telegram') {
                    $params['parse_mode'] = 'HTML';
                }
            }
            try {
                $resp = melkinoBotApiRequest($provider, $method, $params, $token, $transport);
                if (!empty($resp['ok'])) {
                    $done++;
                } else {
                    $errs[] = (string)($resp['message'] ?? 'ناموفق');
                }
            } catch (Throwable $e) {
                $errs[] = 'خطای داخلی: ' . $e->getMessage();
            }
        }
        $ok = $done > 0 && !$errs;
        melkinoPublishHistory(
            $pdo, (int)$t['item_id'], $t['ad_id'] !== null ? (string)$t['ad_id'] : null, $platform,
            $mode === 'edit' ? 'edit_post' : 'delete_post',
            $ok ? 'success' : ($done > 0 ? 'ambiguous' : 'failed'),
            ($mode === 'edit' ? 'ویرایش پست' : 'حذف پست') . ' — ' . $done . ' پیام' . ($errs ? ' | خطا: ' . implode('؛ ', array_slice($errs, 0, 3)) : ''),
            $done ? implode(',', array_filter(array_column($ids, 'message_id'))) : null,
            $actor
        );
        if ($ok && $mode === 'edit') {
            // متن ویرایش‌شده دستی است؛ دیگر با تغییر تنظیمات بازسازی نمی‌شود
            $pdo->prepare('UPDATE publish_targets SET text = ?, text_auto = 0 WHERE id = ?')
                ->execute([$newText !== '' ? $newText : (string)$t['text'], $targetId]);
        }
        if ($ok && $mode === 'delete') {
            // حذف کامل و تأییدشده → مقصد دیگر «منتشرشده» نیست (پیام‌ها از کانال رفته‌اند)
            $pdo->prepare("UPDATE publish_targets SET status = 'deleted' WHERE id = ?")->execute([$targetId]);
        }
        return ['ok' => $ok, 'state' => $ok ? 'success' : ($done > 0 ? 'partial' : 'failed'), 'done' => $done, 'errors' => $errs];
    }
}

/* ======================= قوانین اتوماسیون ======================= */

if (!function_exists('melkinoPublishOnAdEvent')) {
    /**
     * رویداد وضعیت آگهی → قانونِ فعال → عملیات روی پست‌های همان آگهی.
     * هر رویداد یک‌بار پردازش و در تاریخچه ثبت می‌شود.
     */
    function melkinoPublishOnAdEvent(PDO $pdo, string $adId, string $eventKey, array $ctx = [], string $actor = 'system', ?callable $transport = null): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM publish_rules WHERE event_key = ? LIMIT 1');
        $st->execute([$eventKey]);
        $rule = $st->fetch(PDO::FETCH_ASSOC);
        $out = ['event' => $eventKey, 'rule' => $rule ? (string)$rule['action_key'] : 'none', 'results' => []];
        if (!$rule || !(int)$rule['enabled'] || $rule['action_key'] === 'none') {
            melkinoPublishHistory($pdo, null, $adId, null, 'rule_' . $eventKey, 'info', 'رویداد ثبت شد؛ قانون غیرفعال است (' . ($rule['action_key'] ?? 'تعریف‌نشده') . ').', null, $actor);
            return $out;
        }
        // فقط پیام‌های مرتبط با همین آگهی
        $q = $pdo->prepare("SELECT t.* FROM publish_targets t JOIN publish_items i ON i.id = t.item_id WHERE i.ad_id = ? AND t.status IN ('published','partial')");
        $q->execute([$adId]);
        $targets = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$targets) {
            melkinoPublishHistory($pdo, null, $adId, null, 'rule_' . $eventKey, 'info', 'رویداد ثبت شد؛ پستِ مرتبطی در کانال‌ها وجود ندارد.', null, $actor);
            return $out;
        }
        // run-once: هر قانون حداکثر یک بار برای هر آگهی اجرا می‌شود
        try {
            $ins = $pdo->prepare('INSERT IGNORE INTO publish_rule_runs (event_key, ad_id) VALUES (?,?)');
            $ins->execute([$eventKey, $adId]);
            if ($ins->rowCount() === 0) {
                melkinoPublishHistory($pdo, null, $adId, null, 'rule_' . $eventKey, 'info', 'این قانون قبلاً برای این آگهی اجرا شده است (run-once)؛ اجرای تکراری انجام نشد.', null, $actor);
                $out['run_once'] = 'skipped';
                return $out;
            }
        } catch (Throwable $e) {
        }
        $cfg = json_decode((string)($rule['config'] ?? '{}'), true) ?: [];
        foreach ($targets as $t) {
            $r = null;
            if ($rule['action_key'] === 'delete_post') {
                $r = melkinoPublishEditOrDelete($pdo, (int)$t['id'], 'delete', '', $actor . ':rule:' . $eventKey, $transport);
            } elseif ($rule['action_key'] === 'edit_post') {
                $suffix = (string)($cfg['append_text'] ?? ($eventKey === 'sold' ? '✅ فروخته شد' : ($eventKey === 'rented' ? '✅ اجاره رفت' : '⛔ این آگهی در حال حاضر غیرفعال است')));
                $newText = trim((string)$t['text'] . "\n\n" . $suffix);
                $r = melkinoPublishEditOrDelete($pdo, (int)$t['id'], 'edit', $newText, $actor . ':rule:' . $eventKey, $transport);
            } elseif ($rule['action_key'] === 'flag') {
                $pdo->prepare("UPDATE publish_targets SET status = 'needs_review' WHERE id = ?")->execute([(int)$t['id']]);
                $r = ['ok' => true, 'state' => 'flagged'];
            }
            $out['results'][] = ['target_id' => (int)$t['id'], 'platform' => (string)$t['platform'], 'result' => $r];
        }
        melkinoPublishHistory($pdo, null, $adId, null, 'rule_' . $eventKey, 'info', 'قانون «' . $rule['action_key'] . '» روی ' . count($targets) . ' پست اعمال شد.', null, $actor);
        return $out;
    }
}

/* ======================= تاریخچهٔ خواندنی (ادغام منابع قدیمی) ======================= */

if (!function_exists('melkinoPublishHistoryRows')) {
    function melkinoPublishHistoryRows(PDO $pdo, array $f = []): array
    {
        melkinoPublishEnsureSchema($pdo);
        $rows = [];
        try {
            $where = [];
            $args = [];
            if (!empty($f['ad_id'])) {
                $where[] = 'ad_id = ?';
                $args[] = (string)$f['ad_id'];
            }
            if (!empty($f['platform'])) {
                $where[] = 'platform = ?';
                $args[] = (string)$f['platform'];
            }
            if (!empty($f['action'])) {
                $where[] = 'action = ?';
                $args[] = (string)$f['action'];
            }
            if (!empty($f['status'])) {
                $where[] = 'status = ?';
                $args[] = (string)$f['status'];
            }
            if (!empty($f['from'])) {
                $where[] = 'created_at >= ?';
                $args[] = (string)$f['from'];
            }
            if (!empty($f['to'])) {
                $where[] = 'created_at <= ?';
                $args[] = (string)$f['to'];
            }
            $sql = 'SELECT id, item_id, ad_id, platform, action, status, detail, message_ids, actor, created_at, UNIX_TIMESTAMP(created_at) AS created_epoch
                      FROM publish_history'
                . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                . ' ORDER BY id DESC LIMIT 200';
            $st = $pdo->prepare($sql);
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['source'] = 'publish_history';
                $rows[] = $r;
            }
        } catch (Throwable $e) {
        }
        // ادغام منابع قدیمی (تا تاریخچهٔ گذشته هم دیده شود)
        if (empty($f['action']) && empty($f['status'])) {
            try {
                $args = [];
                $w = [];
                if (!empty($f['ad_id'])) {
                    $w[] = 'ad_id = ?';
                    $args[] = (string)$f['ad_id'];
                }
                $sql = "SELECT id, ad_id, platform, (success=1) AS ok, message_id, note, created_at, UNIX_TIMESTAMP(created_at) AS created_epoch FROM channel_publish_logs WHERE note NOT LIKE '[mk-pub]%'"
                    . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                    . ' ORDER BY id DESC LIMIT 100';
                $st = $pdo->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [
                        'id' => 'cpl' . $r['id'], 'item_id' => null, 'ad_id' => $r['ad_id'], 'platform' => $r['platform'],
                        'action' => 'publish', 'status' => ((string)$r['ok'] === '1' || $r['ok'] === 1) ? 'success' : 'failed',
                        'detail' => (string)($r['note'] ?? ''), 'message_ids' => $r['message_id'] ?? null,
                        'actor' => null, 'created_at' => $r['created_at'], 'created_epoch' => $r['created_epoch'],
                        'source' => 'channel_publish_logs',
                    ];
                }
            } catch (Throwable $e) {
            }
            try {
                $args = [];
                $w = [];
                if (!empty($f['ad_id'])) {
                    $w[] = 'ad_id = ?';
                    $args[] = (string)$f['ad_id'];
                }
                $sql = "SELECT id, ad_id, 'eitaa' AS platform, status, message_id, note, created_at, started_epoch AS created_epoch FROM melkino_eitaa_publish_attempts"
                    . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                    . ' ORDER BY id DESC LIMIT 100';
                $st = $pdo->prepare($sql);
                $st->execute($args);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = [
                        'id' => 'eit' . $r['id'], 'item_id' => null, 'ad_id' => $r['ad_id'], 'platform' => 'eitaa',
                        'action' => 'publish', 'status' => $r['status'] === 'published' ? 'success' : ($r['status'] === 'sending' ? 'ambiguous' : 'failed'),
                        'detail' => (string)($r['note'] ?? ''), 'message_ids' => $r['message_id'] ?? null,
                        'actor' => null, 'created_at' => $r['created_at'], 'created_epoch' => $r['created_epoch'],
                        'source' => 'eitaa_attempts',
                    ];
                }
            } catch (Throwable $e) {
            }
        }
        usort($rows, static fn($a, $b) => ((int)($b['created_epoch'] ?? 0) <=> (int)($a['created_epoch'] ?? 0)) ?: strcmp((string)$b['id'], (string)$a['id']));
        return array_slice($rows, 0, 200);
    }
}

if (!function_exists('melkinoPublishCreateItem')) {
    /**
     * ایجاد محتوا + مقاصد با Snapshot کامل (متن/رسانه/زمان هر مقصد مستقل).
     * $offsetSeconds برای انتشار گروهی: جابه‌جایی زمانی میان آگهی‌ها.
     */
    function melkinoPublishCreateItem(PDO $pdo, ?array $ad, array $targetsIn, ?string $whenDefault, string $actor, ?int $campaignId, int $offsetSeconds, array $body): int
    {
        $kind = $ad ? 'ad' : 'content';
        $adId = $ad ? (string)$ad['id'] : null;
        $title = trim((string)($body['title'] ?? '')) ?: ($ad ? (string)($ad['title']) : 'محتوای عمومی');
        $bodyText = (string)($body['body'] ?? '');
        $mediaGlobal = is_array($body['media'] ?? null) ? $body['media'] : [];
        $track = !isset($body['track_link']) || !empty($body['track_link']);
        $isDraft = !empty($body['draft']);
        $pdo->prepare(
            "INSERT INTO publish_items (kind, ad_id, title, body, media, status, campaign_id, scheduled_at, track_link, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $kind, $adId, mb_substr($title, 0, 255), $bodyText,
            $mediaGlobal ? json_encode($mediaGlobal, JSON_UNESCAPED_UNICODE) : null,
            $isDraft ? 'draft' : ($whenDefault ? 'scheduled' : 'queued'), $campaignId,
            $isDraft ? null : ($whenDefault ? date('Y-m-d H:i:s', strtotime($whenDefault) + $offsetSeconds) : ($offsetSeconds ? date('Y-m-d H:i:s', time() + $offsetSeconds) : date('Y-m-d H:i:s'))),
            $track ? 1 : 0, $actor,
        ]);
        $itemId = (int)$pdo->lastInsertId();
        melkinoPublishHistory($pdo, $itemId, $adId, null, 'create', 'info', 'ایجاد «' . mb_substr($title, 0, 80) . '» برای ' . count($targetsIn) . ' کانال' . ($whenDefault ? ' — زمان‌بندی‌شده' : ' — فوری'), null, $actor, $campaignId);

        foreach ($targetsIn as $t) {
            $platform = (string)($t['platform'] ?? '');
            if (!in_array($platform, ['telegram', 'bale', 'eitaa'], true)) {
                continue;
            }
            $mode = in_array(($t['media_mode'] ?? 'all'), ['all', 'selected', 'none'], true) ? $t['media_mode'] : 'all';
            // Snapshot متن: متن ویرایش‌شدهٔ کاربر یا متن خودکار آگهی
            $text = (string)($t['text'] ?? '');
            if (!empty($t['template_id'])) {
                $stT = $pdo->prepare('SELECT * FROM publish_templates WHERE id = ?');
                $stT->execute([(int)$t['template_id']]);
                $tpl = $stT->fetch(PDO::FETCH_ASSOC);
                if ($tpl) {
                    $rendered = melkinoPublishTemplateRender((string)$tpl['body'], $ad, ['footer' => (string)($tpl['footer'] ?? ''), 'body' => (string)($body['body'] ?? ''), 'title' => $title]);
                    if ($rendered !== '') {
                        $text = $rendered . ((string)($tpl['footer'] ?? '') !== '' ? "\n\n" . (string)$tpl['footer'] : '');
                    }
                }
            }
            if ($text === '' && $ad) {
                $text = melkinoPublishAdText($ad, $platform, null, $track);
                if ($text === '') {
                    melkinoPublishHistory($pdo, $itemId, $adId, $platform, 'create', 'failed', 'متن کامل آگهی در این مرحله ساخته نشد؛ این مقصد ثبت نشد (به‌جای ارسال متن ناقص).', null, $actor, $campaignId);
                    continue;
                }
            }
            // متن خودکار: کاربر متن این کانال را دستی نکرده و قالب هم درج نشده است
            $textAuto = ($ad && empty($t['template_id']) && (trim((string)($t['text'] ?? '')) === '' || !empty($t['text_auto']))) ? 1 : 0;
            if ($text === '' && !$ad) {
                $text = $bodyText;
            }
            if ($track && $adId !== null && strpos($text, 'track.php?k=') === false) {
                $token = melkinoPublishEnsureLink($pdo, $adId, $platform, null);
                $text .= "\n\n🔗 مشاهدهٔ آگهی در ملکینو: " . melkinoPublishTrackUrl($token);
            }
            // Snapshot رسانه (همهٔ تصاویر آگهی از جمله تصاویر آپلودی سفارشی)
            $customMedia = [];
            if (is_array($body['custom_media'] ?? null)) {
                foreach ($body['custom_media'] as $cm) {
                    if (!is_array($cm)) {
                        continue;
                    }
                    $fn = (string)($cm['filename'] ?? '');
                    $sp = (string)($cm['storage_path'] ?? '');
                    if ($fn === '' && $sp === '') {
                        continue;
                    }
                    $customMedia[] = [
                        'image_id' => (string)($cm['image_id'] ?? ('custom-' . count($customMedia))),
                        'filename' => $fn !== '' ? $fn : basename($sp),
                        'storage_path' => melkinoPublishAbsPath($sp !== '' ? $sp : $fn),
                    ];
                }
            }
            if ($mode === 'none') {
                $media = [];
            } elseif ($mode === 'selected' && is_array($t['media'] ?? null) && $t['media']) {
                $all = $ad ? melkinoPublishAdImages($pdo, $adId, true) : (is_array($mediaGlobal) ? $mediaGlobal : []);
                $wanted = array_map('strval', $t['media']);
                $media = array_values(array_filter($all, static fn($m) => in_array((string)($m['image_id'] ?? $m['filename'] ?? ''), $wanted, true)));
            } else {
                $media = $ad ? melkinoPublishAdImages($pdo, $adId, true) : (is_array($mediaGlobal) ? $mediaGlobal : []);
            }
            // مسیر مطلق فایل‌ها + افزودن تصاویر سفارشی آپلودشده
            foreach ($media as $mi => $mm) {
                if (!empty($mm['storage_path']) && (string)$mm['storage_path'][0] !== '/') {
                    $media[$mi]['storage_path'] = melkinoPublishAbsPath((string)$mm['storage_path']);
                }
            }
            if ($mode !== 'none') {
                foreach ($customMedia as $cm) {
                    $dup = false;
                    foreach ($media as $mm) {
                        if ((string)($mm['image_id'] ?? '') === $cm['image_id'] || ((string)($mm['filename'] ?? '') !== '' && (string)$mm['filename'] === $cm['filename'])) {
                            $dup = true;
                            break;
                        }
                    }
                    if (!$dup) {
                        $media[] = $cm;
                    }
                }
            }
            // ترتیب دلخواه
            if (is_array($t['order'] ?? null) && $t['order']) {
                $order = array_map('strval', $t['order']);
                usort($media, static fn($a, $b) => array_search((string)($a['image_id'] ?? $a['filename'] ?? ''), $order, true) <=> array_search((string)($b['image_id'] ?? $b['filename'] ?? ''), $order, true));
            }
            $when = null;
            if (!empty($t['scheduled_at'])) {
                $when = melkinoParseJalaliDateTime((string)$t['scheduled_at']);
            }
            if ($isDraft) {
                $when = null; // پیش‌نویس: بدون زمان — هرگز خودکار ارسال نمی‌شود
            } elseif ($when === null) {
                $when = $whenDefault ? date('Y-m-d H:i:s', strtotime($whenDefault) + $offsetSeconds) : ($offsetSeconds ? date('Y-m-d H:i:s', time() + $offsetSeconds) : date('Y-m-d H:i:s'));
            }
            $status = $isDraft ? 'pending' : (($when && strtotime($when) > time() + 30) ? 'pending' : 'queued');
            $chat = trim((string)($t['channel_id'] ?? '')) ?: melkinoPublishChannelId($platform);
            try {
                $pdo->prepare(
                    "INSERT INTO publish_targets (item_id, platform, channel_id, text, text_auto, media, media_mode, scheduled_at, status)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $itemId, $platform, $chat,
                    $text, $textAuto,
                    json_encode(array_values($media), JSON_UNESCAPED_UNICODE) ?: '[]',
                    $mode, $when, $status,
                ]);
            } catch (Throwable $e) {
                melkinoPublishHistory($pdo, $itemId, $adId, $platform, 'create', 'failed', 'ثبت مقصد ناموفق: ' . $e->getMessage(), null, $actor, $campaignId);
            }
        }
        return $itemId;
    }
}

/* ═══ فراخوانی ایمن رویداد آگهی از مسیرهای تغییر وضعیت/ویرایش/حذف ═══
   هرگز مسیر اصلی ذخیره/حذف آگهی را نمی‌شکند؛ فقط رویداد را به موتور قوانین می‌رساند. */
if (!function_exists('melkinoPublishAdEventSafe')) {
    function melkinoPublishAdEventSafe(?PDO $pdo, string $adId, string $eventKey, array $ctx = [], string $actor = 'system'): void
    {
        // ارزیابی قوانین توزیع هنگام ایجاد/تأیید/تغییر مشخصات مؤثر
        if ($pdo instanceof PDO && in_array($eventKey, ['changed', 'created', 'approved'], true)) {
            melkinoDistEvaluateSafe($pdo, $adId, $actor);
        }
        try {
            if ($pdo instanceof PDO && $adId !== '' && function_exists('melkinoPublishOnAdEvent')) {
                melkinoPublishOnAdEvent($pdo, $adId, $eventKey, $ctx, $actor);
            }
        } catch (Throwable $e) {
            // سکوت: قوانین اتوماسیون هرگز ذخیرهٔ آگهی را با خطا مواجه نمی‌کنند
        }
    }
}

/* ======================= قالب‌های محتوایی (بخش ۱۲) ======================= */

if (!function_exists('melkinoPublishTemplates')) {
    /** فهرست قالب‌ها؛ $platform,$kind اختیاری فیلتر می‌کنند. */
    function melkinoPublishTemplates(PDO $pdo, string $platform = '', string $kind = ''): array
    {
        melkinoPublishEnsureSchema($pdo);
        $where = [];
        $args = [];
        if ($platform !== '') {
            $where[] = '(platform = ? OR platform = \'all\')';
            $args[] = $platform;
        }
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $args[] = $kind;
        }
        $st = $pdo->prepare('SELECT * FROM publish_templates' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY is_default DESC, name ASC');
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('melkinoPublishTemplateSave')) {
    /**
     * ذخیره/ویرایش قالب. خروجی: [ok, id, message]. هیچ موفقیتی بدون نوشتن واقعی ادعا نمی‌شود.
     */
    function melkinoPublishTemplateSave(PDO $pdo, array $in, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $name = trim((string)($in['name'] ?? ''));
        $platform = (string)($in['platform'] ?? 'all');
        $kind = (string)($in['kind'] ?? 'ad');
        $body = (string)($in['body'] ?? '');
        $footer = trim((string)($in['footer'] ?? ''));
        $isDefault = !empty($in['is_default']) ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 190) {
            return ['ok' => false, 'message' => 'نام قالب الزامی است (حداکثر ۱۹۰ نویسه).'];
        }
        if (!in_array($platform, ['all', 'telegram', 'bale', 'eitaa'], true) || !in_array($kind, ['ad', 'content'], true)) {
            return ['ok' => false, 'message' => 'مقادیر قالب معتبر نیست.'];
        }
        try {
            $id = (int)($in['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('UPDATE publish_templates SET name = ?, platform = ?, kind = ?, body = ?, footer = ?, is_default = ? WHERE id = ?')
                    ->execute([$name, $platform, $kind, $body, $footer !== '' ? $footer : null, $isDefault, $id]);
            } else {
                $pdo->prepare('INSERT INTO publish_templates (name, platform, kind, body, footer, is_default, created_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $platform, $kind, $body, $footer !== '' ? $footer : null, $isDefault, mb_substr($actor, 0, 120)]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($isDefault) {
                $pdo->prepare('UPDATE publish_templates SET is_default = 0 WHERE id <> ? AND platform = ? AND kind = ?')->execute([$id, $platform, $kind]);
            }
            melkinoPublishHistory($pdo, null, null, $platform === 'all' ? null : $platform, 'template_save', 'info', 'ذخیرهٔ قالب «' . mb_substr($name, 0, 80) . '»', null, $actor);
            return ['ok' => true, 'id' => $id, 'message' => 'قالب ذخیره شد.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => melkinoSafeError($e, 'publish.template_save', 'ذخیرهٔ قالب انجام نشد.')];
        }
    }
}

if (!function_exists('melkinoPublishTemplateDelete')) {
    function melkinoPublishTemplateDelete(PDO $pdo, int $id, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT name FROM publish_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'قالب پیدا نشد.'];
        }
        $pdo->prepare('DELETE FROM publish_templates WHERE id = ?')->execute([$id]);
        melkinoPublishHistory($pdo, null, null, null, 'template_delete', 'info', 'حذف قالب «' . mb_substr((string)$row['name'], 0, 80) . '»', null, $actor);
        return ['ok' => true, 'message' => 'قالب حذف شد.'];
    }
}

if (!function_exists('melkinoPublishTemplateRender')) {
    /**
     * جای‌گذاری جای‌نگهدارهای قالب با دادهٔ واقعی آگهی/محتوا.
     * جای‌نگهدارها: {title} {body} {price} {area} {location} {rooms} {phone} {link} {footer}
     */
    function melkinoPublishTemplateRender(string $body, ?array $ad, array $extra = []): string
    {
        $ad = $ad ?: [];
        $price = function_exists('melkinoPriceMessage') ? melkinoPriceMessage() : '';
        foreach (['price_sell', 'deposit', 'rent_monthly', 'full_rent', 'total_price'] as $k) {
            if ($price !== '') { break; }
            if (!empty($ad[$k]) && (string)$ad[$k] !== '0') {
                $price = trim((string)$ad[$k]);
                break;
            }
        }
        $map = [
            '{title}' => (string)($ad['title'] ?? ($extra['title'] ?? '')),
            '{body}' => (string)($ad['description'] ?? ($extra['body'] ?? '')),
            '{price}' => $price,
            '{area}' => (string)($ad['area'] ?? ($extra['area'] ?? '')),
            '{location}' => (string)($ad['location'] ?? ($extra['location'] ?? '')),
            '{rooms}' => (string)($ad['rooms'] ?? ($extra['rooms'] ?? '')),
            '{phone}' => (string)($extra['phone'] ?? ''),
            '{link}' => (string)($extra['link'] ?? ''),
            '{footer}' => (string)($extra['footer'] ?? ''),
        ];
        return trim(strtr($body, $map));
    }
}

/* ======================= پیام‌های یک آگهی + حذف گروهی (بخش ۱۷) ======================= */

if (!function_exists('melkinoPublishAdPosts')) {
    /** همهٔ مقاصد/پیام‌های منتشرشدهٔ یک آگهی برای نمایش/عملیات گروهی. */
    function melkinoPublishAdPosts(PDO $pdo, string $adId): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare(
            "SELECT t.id AS target_id, t.platform, t.channel_id, t.status, t.message_ids, t.published_at, t.text, t.media,
                    i.id AS item_id, i.title
               FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
              WHERE i.ad_id = ? AND t.status IN ('published','partial','needs_review')
              ORDER BY t.id DESC"
        );
        $st->execute([$adId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids = json_decode((string)($r['message_ids'] ?? '[]'), true) ?: [];
            $r['message_count'] = count(array_filter($ids, static fn($m) => !empty($m['message_id'])));
            $r['message_list'] = array_values(array_filter($ids, static fn($m) => !empty($m['message_id'])));
            unset($r['message_ids']);
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('melkinoPublishDeleteAdPosts')) {
    /**
     * حذف پست‌های یک آگهی از کانال‌ها (با transport اختیاری). نتیجه هر مقصد مستقل ثبت می‌شود.
     * $targetIds خالی = همهٔ مقاصد منتشرشدهٔ آگهی.
     */
    function melkinoPublishDeleteAdPosts(PDO $pdo, string $adId, array $targetIds = [], ?callable $transport = null, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $posts = melkinoPublishAdPosts($pdo, $adId);
        if ($targetIds) {
            $want = array_map('intval', $targetIds);
            $posts = array_values(array_filter($posts, static fn($p) => in_array((int)$p['target_id'], $want, true)));
        }
        if (!$posts) {
            return ['ok' => false, 'message' => 'پست منتشرشده‌ای برای این آگهی یافت نشد.'];
        }
        $results = [];
        $okAll = 0;
        foreach ($posts as $p) {
            $r = melkinoPublishEditOrDelete($pdo, (int)$p['target_id'], 'delete', '', $actor . ':bulk-delete', $transport);
            $results[] = ['target_id' => (int)$p['target_id'], 'platform' => $p['platform'], 'result' => $r];
            if (!empty($r['ok'])) {
                $okAll++;
            }
        }
        melkinoPublishHistory(
            $pdo, null, $adId, null, 'bulk_delete', $okAll === count($posts) ? 'success' : ($okAll > 0 ? 'ambiguous' : 'failed'),
            'حذف گروهی پست‌ها: ' . $okAll . ' از ' . count($posts) . ' مقصد موفق', null, $actor
        );
        return ['ok' => true, 'total' => count($posts), 'succeeded' => $okAll, 'results' => $results, 'message' => $okAll . ' از ' . count($posts) . ' حذف شد.'];
    }
}

/* ======================= وضعیت آمار بازدید (بخش ۱۵) ======================= */

if (!function_exists('melkinoPublishViewsStatus')) {
    /**
     * وضعیت صادقانهٔ دریافت شمارندهٔ بازدید برای هر پیام‌رسان.
     * فقط اگر اتصالی واقعاً شمارنده برگرداند supported=true می‌شود؛ در غیر این صورت
     * هیچ عددی تولید/نمایش داده نمی‌شود («در دسترس نیست» ≠ صفر).
     */
    function melkinoPublishViewsStatus(string $platform): array
    {
        $caps = melkinoPublishCapabilities()[$platform] ?? [];
        $supported = (($caps['views'] ?? 'no') === 'yes');
        return [
            'platform' => $platform,
            'supported' => $supported,
            'note' => (string)($caps['views_note'] ?? 'این اتصال از آمار بازدید پشتیبانی نمی‌کند.'),
            'last_synced_at' => null,
            'source' => $supported ? 'api' : null,
        ];
    }
}

/* ======================= پشتیبان‌گیری / بازیابی ======================= */

if (!function_exists('melkinoPublishBackupTables')) {
    function melkinoPublishBackupTables(): array
    {
        return ['publish_items', 'publish_targets', 'publish_attempts', 'publish_campaigns', 'publish_history',
            'publish_rules', 'publish_rule_runs', 'publish_templates', 'publish_clicks', 'publish_links',
            'publish_view_snapshots', 'publish_settings'];
    }
}

if (!function_exists('melkinoPublishBackup')) {
    /** خروجی پشتیبان برنامهٔ انتشار (JSON کامل — قفل‌ها ایفمرال و خارج از بکاپ‌اند). */
    function melkinoPublishBackup(PDO $pdo): array
    {
        melkinoPublishEnsureSchema($pdo);
        $out = ['format' => 'melkino-publish-backup', 'version' => 3, 'schema' => MELKINO_PUBLISH_SCHEMA,
            'exported_at' => date('Y-m-d H:i:s'), 'tz' => melkinoTzLabel(), 'tables' => []];
        foreach (melkinoPublishBackupTables() as $tbl) {
            $rows = $pdo->query("SELECT * FROM {$tbl}")->fetchAll(PDO::FETCH_ASSOC);
            $out['tables'][$tbl] = $rows;
        }
        $out['counts'] = array_map('count', $out['tables']);
        return $out;
    }
}

if (!function_exists('melkinoPublishRestore')) {
    /**
     * بازیابی پشتیبان. جایگزینی کامل داده‌های ماژول انتشار (destructive) —
     * فقط ستون‌های شناخته‌شدهٔ هر جدول درج می‌شوند؛ اجرا در تراکنش.
     */
    function melkinoPublishRestore(PDO $pdo, array $data, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        if (($data['format'] ?? '') !== 'melkino-publish-backup' || (int)($data['version'] ?? 0) < 2) {
            return ['success' => false, 'message' => 'فایل پشتیبان معتبر نیست (format/version ناشناخته).'];
        }
        $tables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
        if (!$tables) {
            return ['success' => false, 'message' => 'فایل پشتیبان هیچ جدولی ندارد.'];
        }
        $restored = [];
        $pdo->beginTransaction();
        try {
            foreach (melkinoPublishBackupTables() as $tbl) {
                $cols = [];
                foreach ($pdo->query("SHOW COLUMNS FROM {$tbl}")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $cols[] = $c['Field'];
                }
                $pdo->exec("DELETE FROM {$tbl}");
                $rows = is_array($tables[$tbl] ?? null) ? $tables[$tbl] : [];
                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ins = array_intersect_key($row, array_flip($cols));
                    if (!$ins) {
                        continue;
                    }
                    $names = array_keys($ins);
                    $sql = "INSERT INTO {$tbl} (`" . implode('`,`', $names) . "`) VALUES (" . rtrim(str_repeat('?,', count($names)), ',') . ")";
                    $pdo->prepare($sql)->execute(array_values($ins));
                    $n++;
                }
                $restored[$tbl] = $n;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'بازیابی ناتمام ماند و لغو شد: ' . $e->getMessage()];
        }
        melkinoPublishHistory($pdo, null, null, null, 'restore', 'success',
            'بازیابی پشتیبان انجام شد (' . array_sum($restored) . ' رکورد جایگزین شد).', null, $actor);
        return ['success' => true, 'restored' => $restored];
    }
}

/* ======================= اکسل (XLSX خوانا/نوشتنی بدون وابستگی) ======================= */

if (!function_exists('melkinoPublishG2J')) {
    /** تبدیل میلادی → شمسی (الگوریتم استاندارد) */
    function melkinoPublishG2J(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
            + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053));
        $days %= 12053;
        $jy += 4 * (int)($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $jm = $days < 186 ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
        return [$jy, $jm, $jd];
    }
}

if (!function_exists('melkinoPublishJalaliStr')) {
    function melkinoPublishJalaliStr(?string $datetime): string
    {
        if (!$datetime) {
            return '';
        }
        $ts = strtotime($datetime);
        if (!$ts) {
            return $datetime;
        }
        [$jy, $jm, $jd] = melkinoPublishG2J((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i', $ts));
    }
}

if (!function_exists('melkinoZipCreate')) {
    /**
     * ساخت فایل ZIP (فشرده‌سازی STORED) فقط با zlib/core — بدون ext-zip.
     * برای XLSX کافی است (متن‌های XML کوچک‌اند).
     */
    function melkinoZipCreate(array $files): string
    {
        $out = '';
        $central = '';
        $offset = 0;
        $count = 0;
        foreach ($files as $name => $content) {
            $name = (string)$name;
            $content = (string)$content;
            $crc = crc32($content);
            $len = strlen($content);
            $nameLen = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0) . $name . $content;
            $out .= $local;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len, $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
            $offset = strlen($out);
            $count++;
        }
        $out .= $central;
        $out .= pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out) - strlen($central), 0);
        return $out;
    }
}

if (!function_exists('melkinoZipRead')) {
    /** خواندن ZIP (STORED یا DEFLATE) — خروجی: [name => content] */
    function melkinoZipRead(string $bytes): array
    {
        $entries = [];
        $pos = strrpos($bytes, "PK\x05\x06");
        if ($pos === false) {
            return [];
        }
        $eocd = unpack('Vsig/vdisk/vcdDisk/vcdCount/vtotal/VcdSize/VcdOff/vcomment', substr($bytes, $pos, 22));
        $cdOff = (int)$eocd['cdOff'];
        $total = (int)$eocd['total'];
        $p = $cdOff;
        for ($i = 0; $i < $total; $i++) {
            if (substr($bytes, $p, 4) !== "PK\x01\x02") {
                break;
            }
            $h = unpack('vverMade/vverNeed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdisk/viattr/Vattr/Voffset', substr($bytes, $p + 4, 42));
            $name = substr($bytes, $p + 46, (int)$h['nameLen']);
            $localOff = (int)$h['offset'];
            $lnameLen = unpack('v', substr($bytes, $localOff + 26, 2))[1];
            $lextraLen = unpack('v', substr($bytes, $localOff + 28, 2))[1];
            $dataStart = $localOff + 30 + $lnameLen + $lextraLen;
            $data = substr($bytes, $dataStart, (int)$h['csize']);
            if ((int)$h['method'] === 8) {
                $data = (string)gzinflate($data);
            } elseif ((int)$h['method'] !== 0) {
                $data = '';
            }
            $entries[$name] = $data;
            $p += 46 + (int)$h['nameLen'] + (int)$h['extraLen'] + (int)$h['commentLen'];
        }
        return $entries;
    }
}

if (!function_exists('melkinoPublishXlsxBuild')) {
    /** ساخت XLSX مینیمال (SpreadsheetML) بدون هیچ کتابخانه‌ای. */
    function melkinoPublishXlsxBuild(array $headers, array $rows): string
    {
        $esc = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $colLetter = static function (int $n): string {
            $s = '';
            while ($n >= 0) {
                $s = chr(65 + ($n % 26)) . $s;
                $n = (int)($n / 26) - 1;
            }
            return $s;
        };
        $cellsXml = '';
        $all = array_merge([$headers], $rows);
        foreach ($all as $ri => $row) {
            $r = $ri + 1;
            $cellsXml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $val) {
                $ref = $colLetter($ci) . $r;
                if (is_numeric($val) && !preg_match('/^0\d/', (string)$val)) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . $esc($val) . '</v></c>';
                } else {
                    $cellsXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($val) . '</t></is></c>';
                }
            }
            $cellsXml .= '</row>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $cellsXml . '</sheetData></worksheet>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="برنامه انتشار" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        return melkinoZipCreate($files);
    }
}

if (!function_exists('melkinoPublishXlsxParse')) {
    /** خواندن سطرهای XLSX (شیت اول) — ورودی بایت، خروجی آرایهٔ سطرها. */
    function melkinoPublishXlsxParse(string $bytes): array
    {
        $entries = melkinoZipRead($bytes);
        $shared = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $sx = @simplexml_load_string($entries['xl/sharedStrings.xml']);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    $shared[] = (string)($si->t ?? $si->r->t);
                }
            }
        }
        $sheetName = 'xl/worksheets/sheet1.xml';
        if (!isset($entries[$sheetName])) {
            $sheetName = null;
            foreach (array_keys($entries) as $n) {
                if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') {
                    $sheetName = $n;
                    break;
                }
            }
        }
        $rows = [];
        $sheet = $sheetName ? ($entries[$sheetName] ?? '') : '';
        if ($sheet !== '') {
            $sx = @simplexml_load_string($sheet);
            if ($sx !== false) {
                foreach ($sx->sheetData->row as $row) {
                    $cells = [];
                    foreach ($row->c as $c) {
                        $t = (string)($c['t'] ?? '');
                        if ($t === 's') {
                            $val = $shared[(int)$c->v] ?? '';
                        } elseif ($t === 'inlineStr') {
                            $val = (string)$c->is->t;
                        } else {
                            $val = (string)$c->v;
                        }
                        $cells[] = $val;
                    }
                    $rows[] = $cells;
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('melkinoPublishCsvParse')) {
    function melkinoPublishCsvParse(string $text): array
    {
        $text = preg_replace('/^\\xEF\\xBB\\xBF/', '', $text) ?? $text;
        $rows = [];
        foreach (preg_split('/\\r\\n|\\n|\\r/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, ',', '"', '\\');
        }
        return $rows;
    }
}

/* ======================= قوانین هوشمند توزیع آگهی (دور ۱۶) ======================= */

if (!function_exists('melkinoDistAdApproved')) {
    /** تأیید معتبر: نه «در انتظار»، نه «ردشده»، نه «پیش‌نویس». */
    function melkinoDistAdApproved(array $ad): bool
    {
        return !in_array((string)($ad['status'] ?? ''), ['pending', 'rejected', 'draft'], true);
    }
}

if (!function_exists('melkinoDistAdLive')) {
    /** آگهی منتشرشدهٔ زنده — فقط این‌ها وارد صف خودکار می‌شوند. */
    function melkinoDistAdLive(array $ad): bool
    {
        return (string)($ad['status'] ?? '') === 'published';
    }
}

if (!function_exists('melkinoDistMatchRule')) {
    /**
     * انطباق آگهی با شرط‌های یک قانون. منطق ترکیب: همهٔ شرط‌های پرشده باید برقرار باشند (AND).
     * خروجی: [matched(bool), reasons(array of strings)]
     */
    function melkinoDistMatchRule(array $ad, array $m): array
    {
        $reasons = [];
        $tt = array_values(array_filter((array)($m['transaction_types'] ?? []), 'strlen'));
        if ($tt && !in_array((string)($ad['transaction_type'] ?? ''), $tt, true)) {
            return [false, ['نوع معامله مطابقت ندارد (' . ($ad['transaction_type'] ?? '—') . ')']];
        }
        $pt = array_values(array_filter((array)($m['property_types'] ?? []), 'strlen'));
        if ($pt && !in_array((string)($ad['property_type'] ?? ''), $pt, true)) {
            return [false, ['نوع ملک مطابقت ندارد (' . ($ad['property_type'] ?? '—') . ')']];
        }
        if (isset($m['is_vip']) && $m['is_vip'] !== null && $m['is_vip'] !== '') {
            $want = (bool)$m['is_vip'];
            $have = !empty($ad['is_vip']);
            if ($want !== $have) {
                return [false, [$want ? 'آگهی «ویژه» نیست' : 'آگهی «ویژه» است']];
            }
        }
        $city = trim((string)($m['city'] ?? ''));
        if ($city !== '' && mb_strpos((string)($ad['location'] ?? '') . ' ' . (string)($ad['address'] ?? ''), $city) === false) {
            return [false, ['شهر/موقعیت شامل «' . $city . '» نیست']];
        }
        $hood = trim((string)($m['neighborhood'] ?? ''));
        if ($hood !== '' && mb_strpos((string)($ad['location'] ?? '') . ' ' . (string)($ad['address'] ?? ''), $hood) === false) {
            return [false, ['محله شامل «' . $hood . '» نیست']];
        }
        $pmin = (int)($m['price_min'] ?? 0);
        $pmax = (int)($m['price_max'] ?? 0);
        if ($pmin > 0 || $pmax > 0) {
            $price = (int)(($ad['price_sell'] ?? '') !== '' && $ad['price_sell'] !== null ? $ad['price_sell'] : ($ad['total_price'] ?? 0));
            if ($price <= 0) {
                return [false, ['قیمت آگهی ثبت نشده و بازهٔ قیمت قابل ارزیابی نیست']];
            }
            if ($pmin > 0 && $price < $pmin) {
                return [false, ['قیمت کمتر از حداقل است']];
            }
            if ($pmax > 0 && $price > $pmax) {
                return [false, ['قیمت بیشتر از حداکثر است']];
            }
        }
        $amin = (int)($m['area_min'] ?? 0);
        $amax = (int)($m['area_max'] ?? 0);
        if ($amin > 0 || $amax > 0) {
            $area = (int)($ad['area'] ?? 0);
            if ($area <= 0) {
                return [false, ['متراژ ثبت نشده و بازهٔ متراژ قابل ارزیابی نیست']];
            }
            if ($amin > 0 && $area < $amin) {
                return [false, ['متراژ کمتر از حداقل است']];
            }
            if ($amax > 0 && $area > $amax) {
                return [false, ['متراژ بیشتر از حداکثر است']];
            }
        }
        $reasons[] = 'همهٔ شرط‌ها برقرار است (AND)';
        return [true, $reasons];
    }
}

if (!function_exists('melkinoDistRules')) {
    function melkinoDistRules(PDO $pdo, ?int $id = null, ?bool $enabledOnly = null): array
    {
        melkinoPublishEnsureSchema($pdo);
        $sql = 'SELECT * FROM publish_dist_rules';
        $args = [];
        $where = [];
        if ($id !== null) {
            $where[] = 'id = ?';
            $args[] = $id;
        }
        if ($enabledOnly !== null) {
            $where[] = 'enabled = ?';
            $args[] = $enabledOnly ? 1 : 0;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $rules = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rules as &$r) {
            $r['match'] = json_decode((string)($r['match_json'] ?? '{}'), true) ?: [];
        }
        return $rules;
    }
}

if (!function_exists('melkinoDistRuleSave')) {
    function melkinoDistRuleSave(PDO $pdo, array $in, string $actor = 'admin'): array
    {
        melkinoPublishEnsureSchema($pdo);
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $platform = (string)($in['platform'] ?? '');
        $channel = trim((string)($in['channel_id'] ?? ''));
        $onMatch = (string)($in['on_match'] ?? 'suggest');
        if ($name === '') {
            return ['success' => false, 'message' => 'نام قانون لازم است.'];
        }
        if (!in_array($platform, ['telegram', 'bale', 'eitaa'], true)) {
            return ['success' => false, 'message' => 'پیام‌رسان مقصد نامعتبر است.'];
        }
        if ($channel === '') {
            $channel = melkinoPublishChannelId($platform);
        }
        if ($channel === '') {
            return ['success' => false, 'message' => 'شناسهٔ کانال مقصد را وارد کنید (یا ابتدا کانال پیش‌فرض را در «کانال‌ها و اتصالات» تنظیم کنید).'];
        }
        if (!in_array($onMatch, ['suggest', 'enqueue', 'schedule', 'approval'], true)) {
            return ['success' => false, 'message' => 'رفتار هنگام انطباق نامعتبر است.'];
        }
        $scheduleJ = trim((string)($in['schedule_jalali'] ?? ''));
        if ($onMatch === 'schedule') {
            $when = melkinoParseJalaliDateTime($scheduleJ);
            if (!$when) {
                return ['success' => false, 'message' => 'برای رفتار «زمان‌بندی»، زمان پیش‌فرض معتبر شمسی لازم است.'];
            }
        }
        $match = [
            'transaction_types' => array_values(array_filter((array)($in['transaction_types'] ?? []), 'strlen')),
            'property_types' => array_values(array_filter((array)($in['property_types'] ?? []), 'strlen')),
            'is_vip' => isset($in['is_vip']) && $in['is_vip'] !== '' && $in['is_vip'] !== null ? ((string)$in['is_vip'] === '1' ? true : ((string)$in['is_vip'] === '0' ? false : null)) : null,
            'city' => trim((string)($in['city'] ?? '')),
            'neighborhood' => trim((string)($in['neighborhood'] ?? '')),
            'price_min' => (int)($in['price_min'] ?? 0),
            'price_max' => (int)($in['price_max'] ?? 0),
            'area_min' => (int)($in['area_min'] ?? 0),
            'area_max' => (int)($in['area_max'] ?? 0),
            'logic' => 'and',
        ];
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $matchJson = json_encode($match, JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE publish_dist_rules SET name = ?, platform = ?, channel_id = ?, enabled = ?, on_match = ?, schedule_jalali = ?, match_json = ? WHERE id = ?');
            $st->execute([$name, $platform, $channel, $enabled, $onMatch, $scheduleJ ?: null, $matchJson, $id]);
            melkinoPublishHistory($pdo, null, null, $platform, 'dist_rule_save', 'success', "ویرایش قانون توزیع #$id «{$name}» (" . melkinoPublishPlatformLabel($platform) . ' / ' . $channel . '؛ رفتار: ' . $onMatch . ')', null, $actor);
        } else {
            $st = $pdo->prepare('INSERT INTO publish_dist_rules (name, platform, channel_id, enabled, on_match, schedule_jalali, match_json) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$name, $platform, $channel, $enabled, $onMatch, $scheduleJ ?: null, $matchJson]);
            $id = (int)$pdo->lastInsertId();
            melkinoPublishHistory($pdo, null, null, $platform, 'dist_rule_save', 'success', "ایجاد قانون توزیع #$id «{$name}» (" . melkinoPublishPlatformLabel($platform) . ' / ' . $channel . '؛ رفتار: ' . $onMatch . ')', null, $actor);
        }
        return ['success' => true, 'id' => $id, 'message' => 'قانون توزیع ذخیره شد.'];
    }
}

if (!function_exists('melkinoDistExisting')) {
    /**
     * سابقهٔ واقعی همان آگهی در همان کانال (پایهٔ جلوگیری از تکرار).
     * فقط رکوردهای ملکینو — ادعایی دربارهٔ انتشار دستی خارج از سیستم نمی‌کند.
     */
    function melkinoDistExisting(PDO $pdo, string $adId, string $platform, string $channel): ?array
    {
        $st = $pdo->prepare(
            "SELECT t.id, t.status, t.scheduled_at, t.published_at, t.message_ids
             FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
             WHERE i.ad_id = ? AND t.platform = ? AND t.channel_id = ? AND t.status IN ('queued','pending','sending','published','partial','needs_review','draft')
             ORDER BY t.id DESC LIMIT 1"
        );
        $st->execute([$adId, $platform, $channel]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('melkinoDistEvaluate')) {
    /**
     * ارزیابی قوانین توزیع برای یک آگهی. apply=false فقط گزارش می‌دهد (پیش‌نمایش/وضعیت).
     * هر تصمیم در تاریخچه با شناسهٔ قانون ثبت می‌شود.
     */
    function melkinoDistEvaluate(PDO $pdo, string $adId, string $actor = 'system', ?int $ruleOnly = null, bool $apply = true): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        $st->execute([$adId]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ad) {
            return ['success' => false, 'message' => 'آگهی پیدا نشد.', 'ad_id' => $adId];
        }
        $rules = melkinoDistRules($pdo, $ruleOnly, true);
        $matched = [];
        $notMatched = [];
        foreach ($rules as $rule) {
            [$ok, $why] = melkinoDistMatchRule($ad, $rule['match']);
            if ($ok) {
                $matched[] = $rule;
            } else {
                $notMatched[] = ['rule_id' => (int)$rule['id'], 'name' => $rule['name'], 'reasons' => $why];
            }
        }
        $result = [
            'success' => true, 'ad_id' => $adId, 'approved' => melkinoDistAdApproved($ad),
            'live' => melkinoDistAdLive($ad), 'matched' => [], 'skipped' => [], 'created' => [],
        ];
        if (!melkinoDistAdApproved($ad)) {
            $result['skipped'][] = ['reason' => 'آگهی تأیید نیست (وضعیت: ' . ($ad['status'] ?? '—') . ') — ورود خودکار ممنوع'];
            if ($apply) {
                melkinoPublishHistory($pdo, null, $adId, null, 'dist_skip_unapproved', 'info', 'رد از انتشار خودکار: آگهی تأیید نیست (وضعیت: ' . ($ad['status'] ?? '—') . ')', null, $actor);
            }
            $result['not_matched'] = $notMatched;
            return $result;
        }
        foreach ($matched as $rule) {
            $rid = (int)$rule['id'];
            $platform = (string)$rule['platform'];
            $channel = (string)$rule['channel_id'];
            $route = ['rule_id' => $rid, 'rule_name' => $rule['name'], 'platform' => $platform, 'channel_id' => $channel, 'on_match' => $rule['on_match']];
            $existing = melkinoDistExisting($pdo, $adId, $platform, $channel);
            if ($existing) {
                $route['existing'] = $existing;
                $route['duplicate'] = true;
                $result['skipped'][] = $route + ['reason' => 'مقصد تکراری — سابقهٔ قبلی: ' . $existing['status'] . ($existing['published_at'] ? ' در ' . $existing['published_at'] : ($existing['scheduled_at'] ? ' زمان‌بندی ' . $existing['scheduled_at'] : ''))];
                if ($apply) {
                    melkinoPublishHistory($pdo, null, $adId, $platform, 'dist_skip_dup', 'info',
                        "قانون#{$rid} «{$rule['name']}»: برنامهٔ تکراری ساخته نشد — سابقهٔ قبلی در " . $channel . ' با وضعیت ' . $existing['status'], null, $actor);
                }
                $result['matched'][] = $route;
                continue;
            }
            $route['duplicate'] = false;
            $result['matched'][] = $route;
            $behavior = (string)$rule['on_match'];
            if (!$apply || $behavior === 'suggest') {
                if ($apply) {
                    melkinoPublishHistory($pdo, null, $adId, $platform, 'dist_eval', 'info',
                        "قانون#{$rid} «{$rule['name']}»: مقصد " . $channel . ' تعیین شد (فقط پیشنهاد — اقدام خودکار ندارد)', null, $actor);
                }
                continue;
            }
            $whenDefault = null;
            if ($behavior === 'schedule') {
                $whenDefault = melkinoParseJalaliDateTime((string)($rule['schedule_jalali'] ?? ''));
                if (!$whenDefault) {
                    $result['skipped'][] = $route + ['reason' => 'زمان پیش‌فرض قانون معتبر نیست'];
                    melkinoPublishHistory($pdo, null, $adId, $platform, 'dist_error', 'failed', "قانون#{$rid} «{$rule['name']}»: زمان پیش‌فرض معتبر نیست", null, $actor);
                    continue;
                }
            }
            $body = ['title' => (string)($ad['title'] ?? ''), 'body' => ''];
            if ($behavior === 'approval') {
                $body['draft'] = 1;
            }
            $text = melkinoPublishAdText($ad, $platform, null, true);
            $itemId = melkinoPublishCreateItem($pdo, $ad, [[
                'platform' => $platform, 'channel_id' => $channel, 'text' => $text,
                'media_mode' => 'all', 'media' => [],
            ]], $whenDefault, $actor . ':rule#' . $rid, null, 0, $body);
            $result['created'][] = $route + ['item_id' => $itemId];
            melkinoPublishHistory($pdo, $itemId, $adId, $platform, 'dist_plan', 'info',
                "قانون#{$rid} «{$rule['name']}»: برنامهٔ انتشار ساخته شد (" .
                ($behavior === 'schedule' ? 'زمان‌بندی‌شده ' . $rule['schedule_jalali'] : ($behavior === 'approval' ? 'در انتظار تأیید ادمین' : 'در صف انتشار')) . ')',
                null, $actor);
        }
        if ($apply && $matched) {
            $ids = implode(',', array_map('intval', array_map(static fn($r) => $r['id'], $matched)));
            $pdo->exec('UPDATE publish_dist_rules SET last_run_at = NOW() WHERE id IN (' . $ids . ')');
        }
        if ($apply) {
            if (!$matched) {
                melkinoPublishHistory($pdo, null, $adId, null, 'dist_no_match', 'info', 'برای این آگهی قانون توزیع منطبقی وجود ندارد.', null, $actor);
            } else {
                melkinoPublishHistory($pdo, null, $adId, null, 'dist_eval', 'info',
                    'ارزیابی قوانین توزیع: ' . count($matched) . ' قانون منطبق' . ($result['created'] ? '؛ ' . count($result['created']) . ' برنامه ساخته شد' : '') .
                    ($result['skipped'] ? '؛ ' . count($result['skipped']) . ' مورد رد/تکراری' : ''), null, $actor);
            }
        }
        $result['not_matched'] = $notMatched;
        return $result;
    }
}

if (!function_exists('melkinoDistEvaluateSafe')) {
    function melkinoDistEvaluateSafe(?PDO $pdo, string $adId, string $actor = 'system'): void
    {
        if (!$pdo || $adId === '') {
            return;
        }
        try {
            melkinoDistEvaluate($pdo, $adId, $actor);
        } catch (Throwable $e) {
            // بی‌صدا خطا نده؛ در تاریخچه ثبت شود
            try {
                melkinoPublishHistory($pdo, null, $adId, null, 'dist_error', 'failed', 'خطای ارزیابی قوانین توزیع: ' . $e->getMessage(), null, $actor);
            } catch (Throwable $e2) {
            }
        }
    }
}

if (!function_exists('melkinoDistPreviewRule')) {
    /** پیش‌نمایش خشک (بدون انتشار): چه آگهی‌هایی با قانون منطبق‌اند و به کجا می‌روند. */
    function melkinoDistPreviewRule(PDO $pdo, array $rule, int $limit = 20): array
    {
        melkinoPublishEnsureSchema($pdo);
        $rows = $pdo->query("SELECT * FROM ads WHERE status = 'published' ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $enabled = melkinoDistRules($pdo, null, true);
        $adsOut = [];
        $countMatched = 0;
        $countDup = 0;
        $countSkipped = 0;
        foreach ($rows as $ad) {
            [$ok] = melkinoDistMatchRule($ad, $rule['match']);
            if (!$ok) {
                continue;
            }
            $countMatched++;
            if (count($adsOut) >= $limit) {
                continue;
            }
            $routes = [];
            $dups = [];
            foreach (array_merge($enabled, [$rule + ['id' => (int)($rule['id'] ?? 0), 'name' => $rule['name'] . ' (این قانون)']]) as $r2) {
                if (($r2['id'] ?? 0) === 0 && ($rule['id'] ?? 0)) {
                    continue;
                }
                [$ok2] = melkinoDistMatchRule($ad, $r2['match']);
                if (!$ok2) {
                    continue;
                }
                $ch = (string)($r2['channel_id'] ?? '');
                $ex = melkinoDistExisting($pdo, (string)$ad['id'], (string)$r2['platform'], $ch);
                $routes[] = ['rule' => $r2['name'], 'platform' => $r2['platform'], 'channel_id' => $ch, 'duplicate' => (bool)$ex];
                if ($ex) {
                    $dups[] = $r2['name'] . ' ← ' . $ch;
                    $countDup++;
                }
            }
            $adsOut[] = ['id' => $ad['id'], 'title' => $ad['title'] ?? '', 'transaction_type' => $ad['transaction_type'] ?? '', 'property_type' => $ad['property_type'] ?? '', 'routes' => $routes, 'duplicates' => $dups];
        }
        $unapproved = (int)$pdo->query("SELECT COUNT(*) FROM ads WHERE status IN ('pending','rejected','draft')")->fetchColumn();
        $countSkipped = $unapproved;
        return ['matched_count' => $countMatched, 'duplicate_count' => $countDup, 'unapproved_count' => $countSkipped, 'ads' => $adsOut];
    }
}

if (!function_exists('melkinoDistStatus')) {
    /** وضعیت توزیع یک آگهی برای جدول «وضعیت انتشار». */
    function melkinoDistStatus(PDO $pdo, string $adId): array
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        $st->execute([$adId]);
        $ad = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ad) {
            return ['found' => false];
        }
        $rules = melkinoDistRules($pdo, null, true);
        $matched = [];
        $channels = [];
        foreach ($rules as $rule) {
            [$ok] = melkinoDistMatchRule($ad, $rule['match']);
            if ($ok) {
                $matched[] = ['id' => (int)$rule['id'], 'name' => $rule['name'], 'platform' => $rule['platform'], 'channel_id' => $rule['channel_id'], 'on_match' => $rule['on_match']];
                $channels[$rule['platform'] . '@' . $rule['channel_id']] = ['platform' => $rule['platform'], 'channel_id' => $rule['channel_id']];
            }
        }
        $published = [];
        $planned = [];
        foreach ($channels as $key => $ch) {
            $ex = melkinoDistExisting($pdo, $adId, $ch['platform'], $ch['channel_id']);
            if ($ex && $ex['status'] === 'published') {
                $published[] = $ch + ['published_at' => $ex['published_at']];
            } elseif ($ex && in_array($ex['status'], ['queued', 'pending', 'sending', 'draft'], true)) {
                $planned[] = $ch + ['status' => $ex['status'], 'scheduled_at' => $ex['scheduled_at']];
            }
        }
        $missing = [];
        foreach ($channels as $key => $ch) {
            $inP = array_filter($published, static fn($x) => $x['platform'] === $ch['platform'] && $x['channel_id'] === $ch['channel_id']);
            $inL = array_filter($planned, static fn($x) => $x['platform'] === $ch['platform'] && $x['channel_id'] === $ch['channel_id']);
            if (!$inP && !$inL) {
                $missing[] = $ch;
            }
        }
        return [
            'found' => true, 'approved' => melkinoDistAdApproved($ad), 'status' => $ad['status'] ?? '',
            'matched_rules' => $matched, 'suggested_channels' => array_values($channels),
            'published' => $published, 'planned' => $planned, 'missing' => $missing,
            'no_rule' => !$matched,
        ];
    }
}

if (!function_exists('melkinoDistUnmatched')) {
    /** آگهی‌های تأییدشدهٔ فاقد قانون منطبق (برای فهرست «آگهی‌های بدون قانون»). */
    function melkinoDistUnmatched(PDO $pdo, int $limit = 50): array
    {
        melkinoPublishEnsureSchema($pdo);
        $rules = melkinoDistRules($pdo, null, true);
        $rows = $pdo->query("SELECT * FROM ads WHERE status = 'published' ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $ad) {
            $any = false;
            foreach ($rules as $rule) {
                [$ok] = melkinoDistMatchRule($ad, $rule['match']);
                if ($ok) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                $out[] = ['id' => $ad['id'], 'title' => $ad['title'] ?? '', 'transaction_type' => $ad['transaction_type'] ?? '', 'property_type' => $ad['property_type'] ?? ''];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoPublishRefreshAutoTexts')) {
    /**
     * بازسازی متن خودکار مقصدهای در صف (هنوز ارسال‌نشده) پس از تغییر تنظیمات انتشار،
     * تا صف و پیش‌نمایش با تنظیمات فعلی هم‌خوان باشند. مقصدهای دستی تغییر نمی‌کنند.
     */
    function melkinoPublishRefreshAutoTexts(PDO $pdo): int
    {
        melkinoPublishEnsureSchema($pdo);
        $st = $pdo->query(
            "SELECT t.id, t.platform, t.text, i.track_link, i.ad_id
               FROM publish_targets t JOIN publish_items i ON i.id = t.item_id
              WHERE t.text_auto = 1 AND i.kind = 'ad' AND i.ad_id IS NOT NULL
                AND t.status IN ('pending','queued','failed','needs_review')
              LIMIT 500"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $changed = 0;
        $stAd = $pdo->prepare('SELECT * FROM ads WHERE id = ? LIMIT 1');
        foreach ($rows as $r) {
            $stAd->execute([(string)$r['ad_id']]);
            $ad = $stAd->fetch(PDO::FETCH_ASSOC);
            if (!$ad) {
                continue;
            }
            $fresh = melkinoPublishAdText($ad, (string)$r['platform'], null, (bool)$r['track_link']);
            if ($fresh !== '' && $fresh !== (string)$r['text']) {
                $pdo->prepare('UPDATE publish_targets SET text = ? WHERE id = ?')->execute([$fresh, (int)$r['id']]);
                $changed++;
            }
        }
        return $changed;
    }
}
