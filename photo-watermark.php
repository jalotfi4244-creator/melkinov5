<?php
/**
 * واترمارک لوگو روی عکس آگهی‌ها (کارت‌ها + جزئیات ملک).
 * ذخیره: settings.global.photo_watermark (json)
 */
if (!function_exists('melkinoPhotoWatermarkDefaults')) {
    function melkinoPhotoWatermarkDefaults(): array
    {
        return [
            'enabled'      => true,
            'on_cards'     => true,
            'on_details'   => true,
            'opacity'      => 0.40,
            'size'         => 38,
            'size_details' => 30,
            'position'     => 'center',
            'offset_x'     => 0,
            'offset_y'     => 0,
            'rotation'     => 0,
            'mode'         => 'single',
            'tile_scale'   => 22,
            'tile_gap'     => 16,
            'logo_rel'     => '',
        ];
    }
}

if (!function_exists('melkinoPhotoWmBool')) {
    function melkinoPhotoWmBool($v, bool $default): bool
    {
        if ($v === null) {
            return $default;
        }
        if (is_string($v)) {
            return (bool) filter_var($v, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $v;
    }
}

if (!function_exists('melkinoSanitizePhotoWatermark')) {
    function melkinoSanitizePhotoWatermark(array $in, ?array $base = null): array
    {
        $d = $base ?: melkinoPhotoWatermarkDefaults();
        $posOk = [
            'center', 'top-left', 'top-center', 'top-right',
            'middle-left', 'middle-right',
            'bottom-left', 'bottom-center', 'bottom-right',
        ];

        if (array_key_exists('enabled', $in)) {
            $d['enabled'] = melkinoPhotoWmBool($in['enabled'], true);
        }
        if (array_key_exists('on_cards', $in)) {
            $d['on_cards'] = melkinoPhotoWmBool($in['on_cards'], true);
        }
        if (array_key_exists('on_details', $in)) {
            $d['on_details'] = melkinoPhotoWmBool($in['on_details'], true);
        }
        if (isset($in['opacity']) && is_numeric($in['opacity'])) {
            $op = (float) $in['opacity'];
            if ($op > 1) {
                $op = $op / 100;
            }
            $d['opacity'] = max(0.12, min(0.92, $op));
        }
        if (isset($in['size']) && is_numeric($in['size'])) {
            $d['size'] = max(8, min(85, (float) $in['size']));
        }
        if (isset($in['size_details']) && is_numeric($in['size_details'])) {
            $d['size_details'] = max(8, min(85, (float) $in['size_details']));
        } elseif (!isset($d['size_details'])) {
            $d['size_details'] = $d['size'];
        }
        if (isset($in['position']) && is_string($in['position']) && in_array($in['position'], $posOk, true)) {
            $d['position'] = $in['position'];
        }
        if (isset($in['offset_x']) && is_numeric($in['offset_x'])) {
            $d['offset_x'] = max(-45, min(45, (float) $in['offset_x']));
        }
        if (isset($in['offset_y']) && is_numeric($in['offset_y'])) {
            $d['offset_y'] = max(-45, min(45, (float) $in['offset_y']));
        }
        if (isset($in['rotation']) && is_numeric($in['rotation'])) {
            $d['rotation'] = max(-60, min(60, (float) $in['rotation']));
        }
        if (isset($in['mode'])) {
            $d['mode'] = ($in['mode'] === 'tile') ? 'tile' : 'single';
        }
        if (isset($in['tile_scale']) && is_numeric($in['tile_scale'])) {
            $d['tile_scale'] = max(8, min(50, (float) $in['tile_scale']));
        }
        if (isset($in['tile_gap']) && is_numeric($in['tile_gap'])) {
            $d['tile_gap'] = max(0, min(80, (float) $in['tile_gap']));
        }
        if (array_key_exists('logo_rel', $in)) {
            $rel = str_replace('\\', '/', trim((string) $in['logo_rel']));
            $rel = preg_replace('#^/+#', '', $rel) ?? '';
            if ($rel === '' || preg_match('#^uploads/branding/watermark-logo[-a-zA-Z0-9_.]+$#', $rel)) {
                $d['logo_rel'] = $rel;
            }
        }
        return $d;
    }
}

if (!function_exists('melkinoPhotoWatermarkLogoUrl')) {
    function melkinoPhotoWatermarkLogoUrl(?array $wm = null): string
    {
        $rel = str_replace('\\', '/', trim((string) (($wm['logo_rel'] ?? '') )));
        $rel = preg_replace('#^/+#', '', $rel) ?? '';
        if ($rel === '' || strpos($rel, '..') !== false) {
            return '';
        }
        if (!preg_match('#^uploads/branding/watermark-logo[-a-zA-Z0-9_.]+$#', $rel)) {
            return '';
        }
        $full = __DIR__ . '/' . $rel;
        if (!is_file($full)) {
            return '';
        }
        return $rel . '?v=' . (int) @filemtime($full);
    }
}

if (!function_exists('melkinoPhotoWatermarkAttachLogo')) {
    function melkinoPhotoWatermarkAttachLogo(array $d): array
    {
        if (!function_exists('melkinoSiteLogoUrl') && is_file(__DIR__ . '/melkino-logo.php')) {
            require_once __DIR__ . '/melkino-logo.php';
        }
        $url = function_exists('melkinoPhotoWatermarkLogoUrl') ? (string) melkinoPhotoWatermarkLogoUrl($d) : '';
        if ($url === '' && function_exists('melkinoSiteLogoUrl')) {
            $url = (string) melkinoSiteLogoUrl();
        }
        $d['logo_url'] = $url;
        $d['enabled'] = array_key_exists('enabled', $d) ? (bool) $d['enabled'] : true;
        $d['on_cards'] = array_key_exists('on_cards', $d) ? (bool) $d['on_cards'] : true;
        return $d;
    }
}

if (!function_exists('melkinoPhotoWatermark')) {
    function melkinoPhotoWatermark(): array
    {
        $d = melkinoPhotoWatermarkDefaults();
        if (!function_exists('dbSettingGet') || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            return melkinoPhotoWatermarkAttachLogo($d);
        }
        try {
            $raw = dbSettingGet($GLOBALS['pdo'], 'global', 'photo_watermark', null);
            $over = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : null);
            if (!is_array($over)) {
                return melkinoPhotoWatermarkAttachLogo($d);
            }
            $clean = melkinoSanitizePhotoWatermark($over, $d);
            if (!empty($clean['enabled']) && empty($clean['on_cards']) && empty($clean['on_details'])) {
                $clean['on_cards'] = true;
                $clean['on_details'] = true;
            }
            return melkinoPhotoWatermarkAttachLogo($clean);
        } catch (Throwable $e) {
        }
        return melkinoPhotoWatermarkAttachLogo($d);
    }
}

if (!function_exists('melkinoSavePhotoWatermark')) {
    function melkinoSavePhotoWatermark(array $in): array
    {
        if (!$in) {
            return melkinoPhotoWatermark();
        }
        $clean = melkinoSanitizePhotoWatermark($in, melkinoPhotoWatermark());
        if (!empty($clean['enabled']) && empty($clean['on_cards']) && empty($clean['on_details'])) {
            $clean['on_cards'] = true;
            $clean['on_details'] = true;
        }
        unset($clean['logo_url']);
        if (!function_exists('dbSettingSet') || !isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            $clean['logo_url'] = function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($clean) : '';
            return $clean;
        }
        dbSettingSet($GLOBALS['pdo'], 'global', 'photo_watermark', $clean, 'json', isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null);
        $clean['logo_url'] = function_exists('melkinoPhotoWatermarkLogoUrl') ? melkinoPhotoWatermarkLogoUrl($clean) : '';
        return $clean;
    }
}
