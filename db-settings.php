<?php
declare(strict_types=1);

/**
 * جدول settings در هیچ جای پروژه ساخته نمی‌شد؛ به همین دلیل هر بار که ادمین
 * توکن ربات را ذخیره می‌کرد، درج (INSERT) با خطا مواجه می‌شد و مقدار هیچ‌وقت
 * ذخیره نمی‌شد — در نتیجه بعد از خروج از پنل، توکن «پاک شده» به‌نظر می‌رسید
 * و تستِ اتصال همیشه ناموفق بود.
 *
 * این تابع جدول را در صورت نبودن می‌سازد. کلید یکتا روی (group, key) ضروری
 * است تا عبارت ON DUPLICATE KEY UPDATE درست کار کند.
 */
if (!function_exists('melkinoEnsureSettingsTable')) {
    function melkinoEnsureSettingsTable(?PDO $pdo = null): bool
    {
        static $done = null;
        if ($done !== null) {
            return $done;
        }

        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO)) {
            return $done = false;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS settings (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    setting_group VARCHAR(64) NOT NULL DEFAULT 'global',
                    setting_key VARCHAR(191) NOT NULL,
                    setting_value LONGTEXT NULL,
                    value_type VARCHAR(32) NOT NULL DEFAULT 'string',
                    updated_by_admin_id BIGINT UNSIGNED NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_settings_group_key (setting_group, setting_key),
                    KEY idx_settings_group (setting_group)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            // Older installations may predate the settings columns used by save
            // (updated_by_admin_id / updated_at). Add any missing columns so the
            // INSERT path never fails silently on legacy tables.
            try {
                $cols = $pdo->query('SHOW COLUMNS FROM settings')->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('updated_by_admin_id', $cols, true)) {
                    $pdo->exec('ALTER TABLE settings ADD COLUMN updated_by_admin_id BIGINT UNSIGNED NULL');
                }
                if (!in_array('updated_at', $cols, true)) {
                    $pdo->exec('ALTER TABLE settings ADD COLUMN updated_at DATETIME NULL');
                }
            } catch (Throwable $eCols) {
            }
            return $done = true;
        } catch (Throwable $e) {
            return $done = false;
        }
    }
}

if (!function_exists('melkinoSettingsRequestCache')) {
    /**
     * کش داخل-درخواستی جدول settings.
     * هر درخواست PHP (وب) حداکثر یک‌بار هر کلید را از دیتابیس می‌خواند؛ چون
     * تنظیمات در طول یک درخواست تغییر نمی‌کنند، این کش همیشه صحیح است و
     * هزاران کوئری تکراری (مثلاً در صفحهٔ خانه) را حذف می‌کند.
     * نکتهٔ مهم: static در ورکر FPM بین درخواست‌ها زنده می‌ماند، پس هر
     * ورودی شیء PDO خودش را هم نگه می‌دارد و فقط در صورت تطابق هویتی
     * (همان اتصالِ همان درخواست) استفاده می‌شود؛ در غیر این صورت دوباره
     * از دیتابیس خوانده می‌شود. به این ترتیب تغییر تنظیمات توسط ادمین
     * بلافاصله در همهٔ ورکرها اثر می‌کند (هیچ مقدار کهنه‌ای بین
     * درخواست‌ها نشت نمی‌کند).
     * @return array<string,array{0:PDO,1:array|false}>
     */
    function &melkinoSettingsRequestCache(): array
    {
        static $cache = [];
        return $cache;
    }
}

if (!function_exists('dbSettingGet')) {
    function dbSettingGet(PDO $pdo, string $group, string $key, $default = null) {
        $cache = &melkinoSettingsRequestCache();
        $ck = spl_object_id($pdo) . "\0" . $group . "\0" . $key;
        $hit = array_key_exists($ck, $cache) ? $cache[$ck] : null;
        if (!is_array($hit) || ($hit[0] ?? null) !== $pdo) {
            if (function_exists('melkinoEnsureSettingsTable')) {
                melkinoEnsureSettingsTable($pdo);
            }
            $st = $pdo->prepare("SELECT setting_value, value_type FROM settings WHERE setting_group = ? AND setting_key = ? LIMIT 1");
            $st->execute([$group, $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $cache[$ck] = [$pdo, $row ?: false];
        } else {
            $row = $hit[1];
        }
        if ($row === false) return $default;
        $v = $row['setting_value'];
        switch ($row['value_type']) {
            case 'boolean': return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'integer': return (int)$v;
            case 'decimal': return (float)$v;
            case 'json':
                $d = json_decode((string)$v, true);
                return is_array($d) ? $d : $default;
            default: return (string)$v;
        }
    }
}
if (!function_exists('dbSettingSet')) {
    function dbSettingSet(PDO $pdo, string $group, string $key, $value, string $type = 'string', ?int $adminId = null): bool {
        if (function_exists('melkinoEnsureSettingsTable')) {
            melkinoEnsureSettingsTable($pdo);
        }
        if ($type === 'json') $value = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        elseif ($type === 'boolean') $value = $value ? 'true' : 'false';
        else $value = (string)$value;
        try {
            $st = $pdo->prepare("INSERT INTO settings (setting_group,setting_key,setting_value,value_type,updated_by_admin_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),updated_by_admin_id=VALUES(updated_by_admin_id)");
            if ($st->execute([$group,$key,$value,$type,$adminId])) {
                // ابطال کش داخل-درخواستی تا خواندن بعدی مقدار تازه را بگیرد
                $cache = &melkinoSettingsRequestCache();
                unset($cache[spl_object_id($pdo) . "\0" . $group . "\0" . $key]);
                return true;
            }
        } catch (Throwable $eIns) {
        }
        try {
            $up = $pdo->prepare("UPDATE settings SET setting_value=?, value_type=?, updated_by_admin_id=? WHERE setting_group=? AND setting_key=?");
            $up->execute([$value, $type, $adminId, $group, $key]);
            $cache = &melkinoSettingsRequestCache();
            unset($cache[spl_object_id($pdo) . "\0" . $group . "\0" . $key]);
            if ($up->rowCount() > 0) {
                return true;
            }
            $ins = $pdo->prepare("INSERT INTO settings (setting_group,setting_key,setting_value,value_type,updated_by_admin_id) VALUES (?,?,?,?,?)");
            return $ins->execute([$group, $key, $value, $type, $adminId]);
        } catch (Throwable $eUp) {
            return false;
        }
    }
}

if (!function_exists('melkinoEnsureRatingColumns')) {
    function melkinoEnsureRatingColumns(?PDO $pdo = null): void
    {
        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO)) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        $cols = [
            'melkino_visited' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'melkino_rating' => 'DECIMAL(3,1) NULL',
            'melkino_review' => 'TEXT NULL',
        ];
        foreach ($cols as $name => $def) {
            try {
                $st = $pdo->query("SHOW COLUMNS FROM ads LIKE " . $pdo->quote($name));
                if ($st && !$st->fetch()) {
                    $pdo->exec('ALTER TABLE ads ADD COLUMN `' . $name . '` ' . $def);
                }
            } catch (Throwable $e) {
            }
        }
        $done = true;
    }
}

if (!function_exists('melkinoRatingFeatureEnabled')) {
    function melkinoRatingFeatureEnabled(?PDO $pdo = null): bool
    {
        if (!($pdo instanceof PDO)) {
            global $pdo;
        }
        if (!($pdo instanceof PDO) || !function_exists('dbSettingGet')) {
            return false;
        }
        try {
            return (bool) dbSettingGet($pdo, 'global', 'melkino_rating_enabled', false);
        } catch (Throwable $e) {
            return false;
        }
    }
}
