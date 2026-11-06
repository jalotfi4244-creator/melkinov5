<?php
/**
 * گزینه‌های کمبوباکس فرم ثبت ملک و درخواست — ذخیره در جدول settings.
 */

if (!function_exists('melkinoFormComboDefaults')) {
    function melkinoFormComboDefaults(): array
    {
        return [
            'rooms' => ['label' => 'تعداد اتاق', 'items' => ['۱', '۲', '۳', '۴', '۵', '۶']],
            'flooring' => ['label' => 'پوشش کف', 'items' => ['سرامیک', 'پارکت', 'موکت', 'سنگ', 'موزاییک', 'سیمان', 'کفپوش']],
            'cabinet' => ['label' => 'نوع کابینت', 'items' => ['ام دی اف', 'هایگلاس', 'چوبی', 'فلزی', 'ندارد']],
            'cooling' => ['label' => 'سیستم سرمایش', 'items' => ['کولر آبی', 'اسپیلت', 'داکت اسپلیت', 'چیلر', 'پنکه سقفی', 'ندارد']],
            'heating' => ['label' => 'سیستم گرمایش', 'items' => ['بخاری', 'شوفاژ', 'پکیج رادیاتور', 'ندارد']],
            'apartment_type' => ['label' => 'نوع آپارتمان', 'items' => ['فلت', 'دوبلکس']],
            'villa_type' => ['label' => 'نوع ویلایی', 'items' => ['فلت', 'دوبلکس', 'تریبلکس']],
            'units_per_floor' => ['label' => 'تعداد واحد در طبقه', 'items' => ['تک واحد', 'دو واحدی', 'سه واحدی', 'چهار واحدی', 'بیشتر']],
            'wall' => ['label' => 'پوشش دیوارها', 'items' => ['کاغذ دیواری', 'رنگ', 'پنل', 'گچ', 'سرامیک', 'سنگ']],
            'land_direction' => ['label' => 'جهت ملک', 'items' => ['شمالی', 'جنوبی', 'شرقی', 'غربی', 'شمال شرقی', 'شمال غربی', 'جنوب شرقی', 'جنوب غربی']],
            'land_shape' => ['label' => 'شکل زمین', 'items' => ['مستطیل', 'مربع', 'مثلث', 'ذوزنقه', 'نامنظم']],
            'land_deed_status' => ['label' => 'وضعیت سند زمین', 'items' => ['سند رسمی', 'قولنامه', 'در دست اقدام']],
            'land_deed_type' => ['label' => 'نوع سند زمین', 'items' => ['تک‌برگ', 'دفترچه‌ای', 'مشاعی']],
            'land_setback' => ['label' => 'وضعیت عقب‌نشینی', 'items' => ['دارد', 'ندارد', 'مشخص نیست']],
            'irrigation' => ['label' => 'نوع آبیاری', 'items' => ['قطره‌ای', 'بارانی', 'جوی و پشته', 'آبیاری تحت فشار', 'سطحی', 'ترکیبی', 'غرقابی']],
            'deed_type' => ['label' => 'نوع سند ملک', 'items' => ['طلق', 'وقفی', 'مشاعی', 'عرصه', 'اعیان', 'رهنی', 'قولنامه عادی', 'قولنامه شورایی', 'برگه واگذاری']],
            'property_type' => ['label' => 'نوع ملک', 'items' => ['آپارتمان', 'ویلا', 'زمین', 'باغ', 'اداری', 'تجاری']],
        ];
    }
}

if (!function_exists('melkinoFormComboNormalizeMap')) {
    /** @param mixed $raw @return array<string,string[]> */
    function melkinoFormComboNormalizeMap($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $dec = json_decode($raw, true);
            $raw = is_array($dec) ? $dec : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $items) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (is_array($items) && array_key_exists('items', $items) && is_array($items['items'])) {
                $items = $items['items'];
            }
            if (!is_array($items)) {
                continue;
            }
            $list = [];
            foreach ($items as $it) {
                $it = trim((string) $it);
                if ($it !== '' && !in_array($it, $list, true)) {
                    $list[] = $it;
                }
            }
            $out[$k] = $list;
        }
        return $out;
    }
}

if (!function_exists('melkinoFormComboLastError')) {
    function melkinoFormComboLastError(?string $set = null): string
    {
        static $err = '';
        if ($set !== null) {
            $err = $set;
        }
        return $err;
    }
}

if (!function_exists('melkinoFormComboSaved')) {
    /** @return array<string,string[]> */
    function melkinoFormComboSaved(bool $reset = false): array
    {
        static $cache = null;
        if ($reset) {
            $cache = null;
            return [];
        }
        if (is_array($cache)) {
            return $cache;
        }
        $cache = [];
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return $cache;
        }
        if (function_exists('melkinoEnsureSettingsTable')) {
            try {
                melkinoEnsureSettingsTable($pdo);
            } catch (Throwable $e) {
            }
        }
        $pairs = [
            ['global', 'form_combos'],
            ['forms', 'combos'],
        ];
        foreach ($pairs as $pair) {
            [$group, $key] = $pair;
            try {
                if (function_exists('dbSettingGet')) {
                    $map = melkinoFormComboNormalizeMap(dbSettingGet($pdo, $group, $key, null));
                    if ($map) {
                        $cache = $map;
                        return $cache;
                    }
                }
                $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_group = ? AND setting_key = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$group, $key]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $map = melkinoFormComboNormalizeMap($row['setting_value'] ?? '');
                    if ($map) {
                        $cache = $map;
                        return $cache;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return $cache;
    }
}

if (!function_exists('melkinoFormComboItems')) {
    /** @return string[] */
    function melkinoFormComboItems(string $key): array
    {
        $saved = melkinoFormComboSaved();
        if (isset($saved[$key]) && $saved[$key]) {
            return $saved[$key];
        }
        $defs = melkinoFormComboDefaults();
        return isset($defs[$key]['items']) && is_array($defs[$key]['items'])
            ? $defs[$key]['items']
            : [];
    }
}

if (!function_exists('melkinoFormComboMap')) {
    /** @return array<string,string[]> */
    function melkinoFormComboMap(): array
    {
        $out = [];
        foreach (melkinoFormComboDefaults() as $k => $def) {
            $out[$k] = melkinoFormComboItems($k);
        }
        return $out;
    }
}

if (!function_exists('melkinoFormComboCatalog')) {
    /** @return array<string,array{label:string,items:string[]}> */
    function melkinoFormComboCatalog(): array
    {
        $items = [];
        foreach (melkinoFormComboDefaults() as $k => $def) {
            $items[$k] = [
                'label' => (string) ($def['label'] ?? $k),
                'items' => melkinoFormComboItems($k),
            ];
        }
        return $items;
    }
}

if (!function_exists('melkinoFormSelectOptions')) {
    function melkinoFormSelectOptions(string $key, string $selected = '', bool $withEmpty = true): string
    {
        $html = '';
        if ($withEmpty) {
            $html .= '<option value="">انتخاب کنید</option>';
        }
        foreach (melkinoFormComboItems($key) as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            $esc = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
            $sel = ($selected !== '' && $selected === $item) ? ' selected' : '';
            $html .= '<option value="' . $esc . '"' . $sel . '>' . $esc . '</option>';
        }
        return $html;
    }
}

if (!function_exists('melkinoFormComboSave')) {
    /** @param array<string,mixed> $data */
    function melkinoFormComboSave(array $data): bool
    {
        melkinoFormComboLastError('');
        global $pdo;
        $defs = melkinoFormComboDefaults();
        $clean = [];
        foreach ($defs as $k => $def) {
            $items = $data[$k] ?? null;
            if (is_array($items) && array_key_exists('items', $items) && is_array($items['items'])) {
                $items = $items['items'];
            }
            if (!is_array($items)) {
                continue;
            }
            $out = [];
            foreach ($items as $it) {
                $it = trim((string) $it);
                if ($it !== '' && !in_array($it, $out, true)) {
                    $out[] = $it;
                }
            }
            $clean[$k] = $out;
        }
        if ($clean === []) {
            melkinoFormComboLastError('هیچ گزینه‌ای برای ذخیره نبود.');
            return false;
        }
        if (!($pdo instanceof PDO)) {
            melkinoFormComboLastError('اتصال دیتابیس برقرار نیست.');
            return false;
        }
        if (function_exists('melkinoEnsureSettingsTable')) {
            try {
                melkinoEnsureSettingsTable($pdo);
            } catch (Throwable $e) {
            }
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            melkinoFormComboLastError('تبدیل JSON ناموفق بود.');
            return false;
        }
        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        if ($adminId === 0) {
            $adminId = null;
        }
        $group = 'global';
        $key = 'form_combos';
        try {
            if (function_exists('dbSettingSet')) {
                dbSettingSet($pdo, $group, $key, $clean, 'json', $adminId);
            }
        } catch (Throwable $e) {
        }
        try {
            $sel = $pdo->prepare('SELECT id FROM settings WHERE setting_group = ? AND setting_key = ? ORDER BY id DESC LIMIT 1');
            $sel->execute([$group, $key]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['id'])) {
                $up = $pdo->prepare('UPDATE settings SET setting_value = ?, value_type = ?, updated_by_admin_id = ? WHERE id = ?');
                $ok = $up->execute([$json, 'json', $adminId, (int) $row['id']]);
            } else {
                $ins = $pdo->prepare('INSERT INTO settings (setting_group, setting_key, setting_value, value_type, updated_by_admin_id) VALUES (?,?,?,?,?)');
                $ok = $ins->execute([$group, $key, $json, 'json', $adminId]);
            }
            if (!$ok) {
                melkinoFormComboLastError('نوشتن در جدول settings ناموفق بود.');
                return false;
            }
        } catch (Throwable $e) {
            melkinoFormComboLastError('خطای دیتابیس: ' . $e->getMessage());
            return false;
        }
        melkinoFormComboSaved(true);
        $check = [];
        try {
            if (function_exists('dbSettingGet')) {
                $check = melkinoFormComboNormalizeMap(dbSettingGet($pdo, $group, $key, []));
            }
            if (!$check) {
                $sel = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_group = ? AND setting_key = ? ORDER BY id DESC LIMIT 1');
                $sel->execute([$group, $key]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                $check = melkinoFormComboNormalizeMap($row['setting_value'] ?? '');
            }
        } catch (Throwable $e) {
            $check = [];
        }
        if (!$check) {
            melkinoFormComboLastError('ذخیره شد ولی از دیتابیس خوانده نشد.');
            return false;
        }
        return true;
    }
}

if (
    isset($_SERVER['SCRIPT_FILENAME'])
    && basename((string) $_SERVER['SCRIPT_FILENAME']) === 'form-options.php'
) {
    $cfg = __DIR__ . '/config.php';
    if (is_file($cfg)) {
        require_once $cfg;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['success' => true, 'combos' => melkinoFormComboCatalog()], JSON_UNESCAPED_UNICODE);
    exit;
}
