<?php
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/config.php';
    if (is_file(__DIR__ . '/db_helpers.php')) {
        require_once __DIR__ . '/db_helpers.php';
    }
}
if (is_file(__DIR__ . '/map-lib.php')) {
    require_once __DIR__ . '/map-lib.php';
}
$mkMapCfg = ['lat' => 36.4182, 'lng' => 54.9763, 'gps' => true, 'require' => true];
if (isset($pdo) && $pdo instanceof PDO && function_exists('melkinoMapPublicSettings')) {
    $ps = melkinoMapPublicSettings($pdo);
    $mkMapCfg['lat'] = $ps['center_lat'];
    $mkMapCfg['lng'] = $ps['center_lng'];
    $mkMapCfg['gps'] = !empty($ps['gps_enabled']);
    $mkMapCfg['require'] = !empty($ps['require_location']);
}
?>
<div class="form-group" id="mkLocBox" style="margin-top:12px;">
    <label>موقعیت ملک <?= !empty($mkMapCfg['require']) ? '<span style="color:#c0392b">*</span>' : '' ?></label>
    <p style="font-size:12px;line-height:1.9;color:var(--text-secondary);margin:6px 0 10px;">موقعیت دقیق ملک فقط برای شما و مدیر سیستم قابل مشاهده است. سایر کاربران فقط محدوده تقریبی ملک را روی نقشه خواهند دید.</p>
    <input type="hidden" name="map_lat" id="map_lat" value="">
    <input type="hidden" name="map_lng" id="map_lng" value="">
    <input type="hidden" name="map_source" id="map_source" value="map">
    <input type="hidden" name="map_accuracy" id="map_accuracy" value="">
    <div id="mkLocMap" style="height:220px;border-radius:14px;overflow:hidden;border:1px solid var(--border);margin-bottom:8px;"></div>
    <button type="button" class="btn-secondary" id="mkLocGps" style="width:100%;height:42px;">استفاده از موقعیت فعلی من</button>
</div>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script>window.MELKINO_MAP_PICKER = <?= json_encode($mkMapCfg, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="map-picker.js?v=<?= (int) @filemtime(__DIR__ . '/map-picker.js') ?>"></script>
