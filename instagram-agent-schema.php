<?php
/**
 * Melkino Instagram Agent — Schema (Migration)
 * ------------------------------------------------
 * جدول‌های اختصاصی ایجنت؛ هیچ تغییری در جدول‌های اصلی ads/images ایجاد نمی‌شود.
 * الگو دقیقاً مثل melkinoEnsureRequestSchema / melkinoEitaaPublishEnsure:
 *   - melkinoIgEnsureSchema(): ایمن و idempotent — درخواست‌های بعدی بی‌اثر
 *   - melkinoIgDropSchema(): فقط برای تست/rollback — داده‌های سایت را لمس نمی‌کند
 */
declare(strict_types=1);

if (!function_exists('melkinoIgEnsureSchema')) {
    function melkinoIgEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_agent_contents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad_id VARCHAR(64) NOT NULL,
            content_type VARCHAR(24) NOT NULL DEFAULT 'post',
            title VARCHAR(300) NOT NULL DEFAULT '',
            hook VARCHAR(500) NOT NULL DEFAULT '',
            body_text TEXT NULL,
            caption TEXT NOT NULL,
            cta VARCHAR(500) NOT NULL DEFAULT '',
            hashtags VARCHAR(1000) NOT NULL DEFAULT '',
            on_image_text VARCHAR(500) NOT NULL DEFAULT '',
            video_scenario TEXT NULL,
            alt_text VARCHAR(500) NOT NULL DEFAULT '',
            claims_check TEXT NULL,
            tone VARCHAR(40) NOT NULL DEFAULT 'professional',
            audience VARCHAR(60) NOT NULL DEFAULT 'buyers',
            media_json TEXT NULL,
            source_snapshot TEXT NULL,
            source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            generator VARCHAR(24) NOT NULL DEFAULT 'template',
            generator_model VARCHAR(120) NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            review_admin_id INT NULL,
            review_note VARCHAR(1000) NOT NULL DEFAULT '',
            review_at DATETIME NULL,
            scheduled_at DATETIME NULL,
            published_at DATETIME NULL,
            manual_link VARCHAR(600) NOT NULL DEFAULT '',
            created_by VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            KEY idx_igc_ad (ad_id, id),
            KEY idx_igc_status (status, scheduled_at),
            KEY idx_igc_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_agent_publications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            ad_id VARCHAR(64) NOT NULL,
            mode VARCHAR(12) NOT NULL DEFAULT 'api',
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            ig_media_id VARCHAR(120) NULL,
            ig_permalink VARCHAR(600) NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
            last_error_code VARCHAR(60) NULL,
            last_error_friendly VARCHAR(500) NOT NULL DEFAULT '',
            last_http_status INT NULL,
            payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_igpub_request (request_id),
            KEY idx_igpub_content (content_id, id),
            KEY idx_igpub_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_agent_settings (
            setting_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by VARCHAR(120) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_agent_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued',
            due_at DATETIME NULL,
            locked_until DATETIME NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NOT NULL DEFAULT '',
            result_summary VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            KEY idx_igjob_due (status, due_at),
            KEY idx_igjob_kind (kind, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_agent_audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(60) NOT NULL,
            entity_type VARCHAR(30) NOT NULL DEFAULT '',
            entity_id VARCHAR(64) NOT NULL DEFAULT '',
            detail VARCHAR(1200) NOT NULL DEFAULT '',
            actor VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_iglog_action (action, id),
            KEY idx_iglog_entity (entity_type, entity_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('melkinoIgDropSchema')) {
    /** فقط برای تست/rollback کامل ماژول؛ هرگز در مسیر عادی صدا زده نمی‌شود. */
    function melkinoIgDropSchema(PDO $pdo): void
    {
        foreach ([
            'instagram_agent_audit_logs',
            'instagram_agent_jobs',
            'instagram_agent_settings',
            'instagram_agent_publications',
            'instagram_agent_contents',
        ] as $t) {
            $pdo->exec("DROP TABLE IF EXISTS {$t}");
        }
    }
}
