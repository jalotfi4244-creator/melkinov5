<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/map-lib.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

global $pdo;
if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'دیتابیس در دسترس نیست.'], JSON_UNESCAPED_UNICODE);
    exit;
}
melkinoMapEnsureSchema($pdo);

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$identity = function_exists('melkinoCurrentIdentity') ? melkinoCurrentIdentity() : [];
$isAdmin = !empty($_SESSION['is_admin']);

function mapJson(array $p, int $c = 200): void
{
    http_response_code($c);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'settings') {
    mapJson(['success' => true, 'settings' => melkinoMapPublicSettings($pdo)]);
}

$settings = melkinoMapSettings($pdo);
if (empty($settings['enabled']) && !$isAdmin && $action !== 'admin_settings') {
    mapJson(['success' => false, 'message' => 'نقشه املاک فعلاً غیرفعال است.', 'disabled' => true], 403);
}

$secret = melkinoMapMaskSecret($pdo);

if ($action === 'properties') {
    $n = melkinoMapParseCoord($_GET['north'] ?? null);
    $s = melkinoMapParseCoord($_GET['south'] ?? null);
    $e = melkinoMapParseCoord($_GET['east'] ?? null);
    $w = melkinoMapParseCoord($_GET['west'] ?? null);
    if ($n === null || $s === null || $e === null || $w === null) {
        mapJson(['success' => false, 'message' => 'محدوده نقشه نامعتبر است.'], 422);
    }
    $pad = ((int) $settings['privacy_radius'] * 2) / 111320;
    $n += $pad;
    $s -= $pad;
    $e += $pad;
    $w -= $pad;
    $limit = (int) $settings['max_results'];
    $sql = "SELECT id,ad_code,title,status,transaction_type,property_type,area,land_area,built_area,rooms,building_age,location,floor,year,is_vip,price_hidden,
                   price_sell,display_price,total_price,deposit,rent_monthly,full_rent,latitude,longitude
            FROM ads
            WHERE status='published' AND latitude IS NOT NULL AND longitude IS NOT NULL
              AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?";
    $params = [min($s, $n), max($s, $n), min($w, $e), max($w, $e)];
    $pt = trim((string) ($_GET['property_type'] ?? ''));
    $tx = trim((string) ($_GET['transaction_type'] ?? ''));
    if ($pt !== '' && $pt !== 'همه') {
        if (mb_strpos($pt, 'ویلا') !== false) {
            $sql .= " AND property_type LIKE '%ویلا%'";
        } else {
            $sql .= ' AND property_type = ?';
            $params[] = $pt;
        }
    }
    if ($tx !== '' && $tx !== 'همه') {
        $txN = str_replace(["\u{200c}", '‌', ' ', '-'], '', $tx);
        if ($txN === 'اجاره') {
            $sql .= " AND (transaction_type LIKE '%اجاره%' OR transaction_type LIKE '%رهن%')";
        } elseif (strpos($txN, 'پیشفروش') !== false) {
            $sql .= " AND REPLACE(REPLACE(transaction_type,'‌',''),' ','') LIKE '%پیشفروش%'";
        } else {
            $sql .= ' AND transaction_type = ?';
            $params[] = $tx;
        }
    }
    $minP = melkinoMapParseCoord($_GET['min_price'] ?? null);
    $maxP = melkinoMapParseCoord($_GET['max_price'] ?? null);
    if ($minP !== null) {
        $sql .= ' AND CAST(REPLACE(IFNULL(price_sell, IFNULL(display_price,0)), ",", "") AS DECIMAL(18,0)) >= ?';
        $params[] = $minP;
    }
    if ($maxP !== null) {
        $sql .= ' AND CAST(REPLACE(IFNULL(price_sell, IFNULL(display_price,0)), ",", "") AS DECIMAL(18,0)) <= ?';
        $params[] = $maxP;
    }
    $minA = melkinoMapParseCoord($_GET['min_area'] ?? null);
    $maxA = melkinoMapParseCoord($_GET['max_area'] ?? null);
    if ($minA !== null) {
        $sql .= ' AND CAST(IFNULL(area, IFNULL(land_area,0)) AS DECIMAL(12,2)) >= ?';
        $params[] = $minA;
    }
    if ($maxA !== null) {
        $sql .= ' AND CAST(IFNULL(area, IFNULL(land_area,0)) AS DECIMAL(12,2)) <= ?';
        $params[] = $maxA;
    }
    $rooms = trim((string) ($_GET['rooms'] ?? ''));
    if ($rooms !== '') {
        $sql .= ' AND rooms = ?';
        $params[] = $rooms;
    }
    $sql .= ' LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $thumbs = [];
    $ids = array_values(array_filter(array_map(static fn($r) => (string) ($r['id'] ?? ''), $rows)));
    if ($ids) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $ts = $pdo->prepare("SELECT ad_id, filename, storage_path FROM images WHERE ad_id IN ($in) AND is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC, sort_order ASC");
            $ts->execute($ids);
            foreach ($ts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $im) {
                $aid = (string) $im['ad_id'];
                if (!isset($thumbs[$aid])) {
                    $thumbs[$aid] = (string) ($im['storage_path'] ?: $im['filename']);
                }
            }
        } catch (Throwable $e) {
        }
    }
    $amenByAd = [];
    if ($ids) {
        try {
            $as = $pdo->prepare("SELECT aa.ad_id, am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id IN ($in)");
            $as->execute($ids);
            foreach ($as->fetchAll(PDO::FETCH_ASSOC) ?: [] as $ar) {
                $amenByAd[(string) $ar['ad_id']][] = (string) $ar['name'];
            }
        } catch (Throwable $e) {
        }
    }
    $out = [];
    foreach ($rows as $ad) {
        $ad['_amenities'] = $amenByAd[(string) $ad['id']] ?? [];
        $out[] = melkinoMapPublicCard($pdo, $ad, $settings, $secret, $thumbs[(string) $ad['id']] ?? '');
    }
    mapJson(['success' => true, 'count' => count($out), 'items' => $out, 'radius' => (int) $settings['privacy_radius']]);
}

if ($action === 'property') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        mapJson(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
    }
    $st = $pdo->prepare("SELECT * FROM ads WHERE id=? AND status='published' LIMIT 1");
    $st->execute([$id]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ad) {
        mapJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
    }
    $card = melkinoMapPublicCard($pdo, $ad, $settings, $secret);
    mapJson(['success' => true, 'item' => $card]);
}

if ($action === 'owner_location') {
    $id = trim((string) ($_GET['id'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM ads WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ad) {
        mapJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
    }
    if (!melkinoMapIsOwner($ad, $identity)) {
        mapJson(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
    }
    mapJson([
        'success' => true,
        'id' => $ad['id'],
        'exact_latitude' => $ad['latitude'],
        'exact_longitude' => $ad['longitude'],
        'location_source' => $ad['location_source'] ?? null,
        'public_location' => (melkinoMapParseCoord($ad['latitude']) !== null)
            ? melkinoMapMaskPoint((float) $ad['latitude'], (float) $ad['longitude'], (string) $ad['id'], (int) $settings['privacy_radius'], $secret)
            : null,
    ]);
}

if ($action === 'save_owner_location') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        mapJson(['success' => false, 'message' => 'روش نامعتبر.'], 405);
    }
    if (function_exists('melkinoCsrfCheck')) {
        melkinoCsrfCheck();
    }
    $id = trim((string) ($_POST['id'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM ads WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ad || !melkinoMapIsOwner($ad, $identity)) {
        mapJson(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
    }
    $errors = [];
    $req = $settings;
    $req['require_location'] = true;
    $loc = melkinoMapPostedLocation($errors, $req);
    if ($errors) {
        mapJson(['success' => false, 'message' => $errors[0]], 422);
    }
    melkinoMapSaveCoords($pdo, $id, $loc, $identity, $ad);
    mapJson(['success' => true, 'message' => 'موقعیت ذخیره شد.']);
}

if ($action === 'similar') {
    $id = trim((string) ($_GET['id'] ?? ''));
    $st = $pdo->prepare("SELECT * FROM ads WHERE id=? AND status='published' LIMIT 1");
    $st->execute([$id]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ad || $ad['latitude'] === null) {
        mapJson(['success' => true, 'items' => []]);
    }
    $km = max(0.3, ((int) $settings['privacy_radius']) / 1000 * 3);
    $dLat = $km / 111.32;
    $st = $pdo->prepare(
        "SELECT id,ad_code,title,status,transaction_type,property_type,area,land_area,built_area,rooms,building_age,location,floor,year,is_vip,price_hidden,
                price_sell,display_price,total_price,deposit,rent_monthly,full_rent,latitude,longitude
         FROM ads WHERE status='published' AND id<>? AND property_type=?
           AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?
         LIMIT 12"
    );
    $lat = (float) $ad['latitude'];
    $lng = (float) $ad['longitude'];
    $st->execute([$id, $ad['property_type'], $lat - $dLat, $lat + $dLat, $lng - $dLat, $lng + $dLat]);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $items[] = melkinoMapPublicCard($pdo, $row, $settings, $secret);
    }
    mapJson(['success' => true, 'items' => $items]);
}

if ($action === 'admin_stats' || $action === 'admin_settings' || $action === 'admin_list' || $action === 'admin_save_location' || $action === 'admin_clear_location' || $action === 'admin_logs') {
    if (!$isAdmin) {
        mapJson(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
    }
}

if ($action === 'admin_stats') {
    $total = (int) $pdo->query("SELECT COUNT(*) FROM ads")->fetchColumn();
    $with = (int) $pdo->query("SELECT COUNT(*) FROM ads WHERE latitude IS NOT NULL AND longitude IS NOT NULL")->fetchColumn();
    $pub = (int) $pdo->query("SELECT COUNT(*) FROM ads WHERE status='published' AND latitude IS NOT NULL")->fetchColumn();
    $sell = (int) $pdo->query("SELECT COUNT(*) FROM ads WHERE status='published' AND latitude IS NOT NULL AND transaction_type IN ('فروش','پیش فروش')")->fetchColumn();
    $rent = (int) $pdo->query("SELECT COUNT(*) FROM ads WHERE status='published' AND latitude IS NOT NULL AND transaction_type LIKE '%اجاره%'")->fetchColumn();
    mapJson(['success' => true, 'total' => $total, 'with_location' => $with, 'without_location' => max(0, $total - $with), 'on_map' => $pub, 'sell' => $sell, 'rent' => $rent]);
}

if ($action === 'admin_settings' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    mapJson(['success' => true, 'settings' => $settings]);
}

if ($action === 'admin_settings' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('melkinoCsrfCheck')) {
        melkinoCsrfCheck();
    }
    $body = $_POST;
    if (empty($body) && function_exists('melkinoReadRequestBody')) {
        $b = melkinoReadRequestBody();
        if (is_array($b)) {
            $body = $b;
        }
    }
    $bools = ['enabled', 'require_location', 'gps_enabled', 'show_privacy_circle', 'show_markers', 'clustering'];
    foreach ($bools as $k) {
        if (array_key_exists($k, $body)) {
            $v = $body[$k];
            dbSettingSet($pdo, 'map', $k, filter_var($v, FILTER_VALIDATE_BOOLEAN), 'boolean', (int) ($_SESSION['admin_id'] ?? 0));
        }
    }
    if (isset($body['privacy_radius'])) {
        dbSettingSet($pdo, 'map', 'privacy_radius', max(40, min(70, (int) $body['privacy_radius'])), 'integer', (int) ($_SESSION['admin_id'] ?? 0));
    }
    if (isset($body['max_results'])) {
        dbSettingSet($pdo, 'map', 'max_results', max(10, min(300, (int) $body['max_results'])), 'integer', (int) ($_SESSION['admin_id'] ?? 0));
    }
    if (isset($body['min_zoom'])) {
        dbSettingSet($pdo, 'map', 'min_zoom', max(1, min(18, (int) $body['min_zoom'])), 'integer', (int) ($_SESSION['admin_id'] ?? 0));
    }
    if (isset($body['provider'])) {
        dbSettingSet($pdo, 'map', 'provider', preg_replace('/[^a-z0-9_-]/i', '', (string) $body['provider']) ?: 'osm', 'string', (int) ($_SESSION['admin_id'] ?? 0));
    }
    if (isset($body['marker_colors'])) {
        $c = $body['marker_colors'];
        if (is_string($c)) {
            $c = json_decode($c, true);
        }
        if (is_array($c)) {
            dbSettingSet($pdo, 'map', 'marker_colors', $c, 'json', (int) ($_SESSION['admin_id'] ?? 0));
        }
    }
    mapJson(['success' => true, 'settings' => melkinoMapSettings($pdo)]);
}

if ($action === 'admin_list') {
    $miss = isset($_GET['missing']) && $_GET['missing'] === '1';
    $sql = $miss
        ? "SELECT id,title,property_type,status,latitude,longitude,location FROM ads WHERE latitude IS NULL OR longitude IS NULL ORDER BY created_at DESC LIMIT 200"
        : "SELECT id,title,property_type,status,latitude,longitude,location FROM ads WHERE latitude IS NOT NULL ORDER BY location_updated_at DESC, created_at DESC LIMIT 200";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    mapJson(['success' => true, 'items' => $rows]);
}

if ($action === 'admin_save_location' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('melkinoCsrfCheck')) {
        melkinoCsrfCheck();
    }
    $id = trim((string) ($_POST['id'] ?? ''));
    $st = $pdo->prepare('SELECT * FROM ads WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $ad = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ad) {
        mapJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
    }
    $errors = [];
    $req = $settings;
    $req['require_location'] = true;
    $loc = melkinoMapPostedLocation($errors, $req);
    if ($errors) {
        mapJson(['success' => false, 'message' => $errors[0]], 422);
    }
    $identity['is_admin'] = true;
    $identity['admin_username'] = (string) ($_SESSION['admin_username'] ?? 'admin');
    melkinoMapSaveCoords($pdo, $id, $loc, $identity, $ad);
    mapJson(['success' => true]);
}

if ($action === 'admin_clear_location' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('melkinoCsrfCheck')) {
        melkinoCsrfCheck();
    }
    $id = trim((string) ($_POST['id'] ?? ''));
    $pdo->prepare('UPDATE ads SET latitude=NULL, longitude=NULL, location_received=0 WHERE id=?')->execute([$id]);
    mapJson(['success' => true]);
}

if ($action === 'admin_logs') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $st = $pdo->prepare('SELECT * FROM location_change_log WHERE ad_id=? ORDER BY id DESC LIMIT 50');
        $st->execute([$id]);
    } else {
        $st = $pdo->query('SELECT * FROM location_change_log ORDER BY id DESC LIMIT 80');
    }
    mapJson(['success' => true, 'items' => $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : []]);
}

mapJson(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
