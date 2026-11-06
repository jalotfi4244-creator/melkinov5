<?php
/**
 * نقشه زنده املاک ملکینو — هستهٔ تنظیمات، حریم موقعیت و اسکیما.
 */
if (!function_exists('melkinoMapDefaults')) {
    function melkinoMapDefaults(): array
    {
        return [
            'enabled' => true,
            'require_location' => true,
            'gps_enabled' => true,
            'privacy_radius' => 50,
            'show_privacy_circle' => false,
            'show_markers' => true,
            'clustering' => false,
            'min_zoom' => 11,
            'max_results' => 100,
            'provider' => 'osm',
            'center_lat' => 36.4182,
            'center_lng' => 54.9763,
            'marker_colors' => [
                'آپارتمان' => '#3b82f6',
                'ویلایی' => '#22c55e',
                'باغ' => '#166534',
                'زمین' => '#92400e',
                'تجاری' => '#f97316',
                'اداری' => '#7c3aed',
            ],
        ];
    }
}

if (!function_exists('melkinoMapEnsureSchema')) {
    /** بدون ALTER/CREATE روی Production — فقط no-op ایمن. */
    function melkinoMapEnsureSchema(PDO $pdo): void
    {
    }
}

if (!function_exists('melkinoMapSettings')) {
    function melkinoMapSettings(PDO $pdo): array
    {
        $d = melkinoMapDefaults();
        if (!function_exists('dbSettingGet')) {
            return $d;
        }
        try {
        foreach ($d as $k => $v) {
            $got = dbSettingGet($pdo, 'map', $k, $v);
            if ($got === null || $got === '') {
                $d[$k] = $v;
            } else {
                $d[$k] = $got;
            }
        }
        } catch (Throwable $e) {
            return melkinoMapDefaults();
        }
        $pr = (int) $d['privacy_radius'];
        if ($pr <= 0 || $pr >= 120) {
            $pr = 50;
        }
        $d['privacy_radius'] = max(40, min(70, $pr));
        $d['max_results'] = max(10, min(300, (int) $d['max_results']));
        $d['min_zoom'] = max(1, min(18, (int) $d['min_zoom']));
        if (!is_array($d['marker_colors'])) {
            $d['marker_colors'] = melkinoMapDefaults()['marker_colors'];
        }
        return $d;
    }
}

if (!function_exists('melkinoMapPublicSettings')) {
    function melkinoMapPublicSettings(PDO $pdo): array
    {
        $s = melkinoMapSettings($pdo);
        return [
            'enabled' => !empty($s['enabled']),
            'require_location' => !empty($s['require_location']),
            'gps_enabled' => !empty($s['gps_enabled']),
            'privacy_radius' => (int) $s['privacy_radius'],
            'show_privacy_circle' => !empty($s['show_privacy_circle']),
            'show_markers' => !empty($s['show_markers']),
            'clustering' => !empty($s['clustering']),
            'min_zoom' => (int) $s['min_zoom'],
            'max_results' => (int) $s['max_results'],
            'provider' => (string) $s['provider'],
            'center_lat' => (float) $s['center_lat'],
            'center_lng' => (float) $s['center_lng'],
            'marker_colors' => $s['marker_colors'],
            'tile_url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            'tile_attr' => '&copy; OpenStreetMap',
        ];
    }
}

if (!function_exists('melkinoMapMaskSecret')) {
    function melkinoMapMaskSecret(PDO $pdo): string
    {
        $s = function_exists('dbSettingGet') ? (string) dbSettingGet($pdo, 'map', 'mask_secret', '') : '';
        if ($s === '') {
            $s = bin2hex(random_bytes(16));
            if (function_exists('dbSettingSet')) {
                dbSettingSet($pdo, 'map', 'mask_secret', $s, 'string');
            }
        }
        return $s;
    }
}

if (!function_exists('melkinoMapMaskPoint')) {
    function melkinoMapMaskPoint(float $lat, float $lng, string $adId, int $radiusM, string $secret): array
    {
        $h = hash_hmac('sha256', $adId, $secret);
        $angle = (hexdec(substr($h, 0, 8)) / 4294967295) * 2 * M_PI;
        $frac = hexdec(substr($h, 8, 8)) / 4294967295;
        $base = ($radiusM >= 40 && $radiusM <= 70) ? $radiusM : 50;
        $dist = $base - 8 + 16 * $frac;
        $dLat = ($dist * cos($angle)) / 111320;
        $cosLat = cos(deg2rad($lat));
        $dLng = ($dist * sin($angle)) / (111320 * max(0.2, $cosLat));
        return [
            'latitude' => round($lat + $dLat, 5),
            'longitude' => round($lng + $dLng, 5),
            'radius' => $radiusM,
        ];
    }
}

if (!function_exists('melkinoMapParseCoord')) {
    function melkinoMapParseCoord($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (function_exists('melkino_numeric')) {
            $n = melkino_numeric($v, null);
        } else {
            $n = is_numeric($v) ? (float) $v : null;
        }
        if ($n === null) {
            return null;
        }
        return (float) $n;
    }
}

if (!function_exists('melkinoMapIsOwner')) {
    function melkinoMapIsOwner(array $ad, array $identity): bool
    {
        if (!empty($identity['is_admin']) || !empty($_SESSION['is_admin'])) {
            return true;
        }
        if (!empty($identity['user_id']) && (int) ($ad['owner_user_id'] ?? 0) === (int) $identity['user_id']) {
            return true;
        }
        $p1 = preg_replace('/\D+/', '', (string) ($ad['phone'] ?? ''));
        $p2 = preg_replace('/\D+/', '', (string) ($identity['phone'] ?? ''));
        if ($p1 !== '' && $p2 !== '' && $p1 === $p2) {
            return true;
        }
        return false;
    }
}

if (!function_exists('melkinoMapPostedLocation')) {
    function melkinoMapPostedLocation(array &$errors, array $settings): array
    {
        $lat = melkinoMapParseCoord($_POST['map_lat'] ?? $_POST['latitude'] ?? null);
        $lng = melkinoMapParseCoord($_POST['map_lng'] ?? $_POST['longitude'] ?? null);
        $src = trim((string) ($_POST['map_source'] ?? $_POST['location_source'] ?? 'map'));
        if (!in_array($src, ['map', 'gps', 'manual'], true)) {
            $src = 'map';
        }
        $acc = trim((string) ($_POST['map_accuracy'] ?? $_POST['location_accuracy'] ?? ''));
        $ok = $lat !== null && $lng !== null && $lat >= 25 && $lat <= 42 && $lng >= 44 && $lng <= 64;
        if (!empty($settings['require_location']) && !$ok) {
            $errors[] = 'لطفاً موقعیت ملک را روی نقشه مشخص کنید.';
        }
        return [
            'latitude' => $ok ? $lat : null,
            'longitude' => $ok ? $lng : null,
            'location_source' => $ok ? $src : null,
            'location_accuracy' => $ok ? $acc : null,
            'location_received' => $ok ? '1' : '0',
        ];
    }
}

if (!function_exists('melkinoMapSaveCoords')) {
    function melkinoMapSaveCoords(PDO $pdo, string $adId, array $loc, array $identity = [], ?array $old = null): void
    {
        if (empty($loc['latitude']) || empty($loc['longitude'])) {
            return;
        }
        try {
            $pdo->prepare('UPDATE ads SET latitude=?, longitude=?, location_received=? WHERE id=?')
                ->execute([$loc['latitude'], $loc['longitude'], '1', $adId]);
        } catch (Throwable $e) {
            try {
                $pdo->prepare('UPDATE ads SET latitude=?, longitude=? WHERE id=?')
                    ->execute([$loc['latitude'], $loc['longitude'], $adId]);
            } catch (Throwable $e2) {
            }
        }
        try {
            $pdo->prepare(
                'INSERT INTO location_change_log (ad_id,user_id,old_latitude,old_longitude,new_latitude,new_longitude,change_source,changed_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $adId,
                ((int) ($identity['user_id'] ?? 0)) ?: null,
                $old['latitude'] ?? null,
                $old['longitude'] ?? null,
                $loc['latitude'],
                $loc['longitude'],
                $loc['location_source'] ?? 'map',
                (string) ($identity['name'] ?? $identity['admin_username'] ?? ''),
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoFormatMoneyDisplay')) {
    /** عدد قیمت: بدون .00، جداکنندهٔ سه‌رقمی، دو صفر انتهایی حذف. */
    function melkinoFormatMoneyDisplay($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،',',',' '],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','',''], (string) $v);
        $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';
        if ($s === '' || !is_numeric($s)) {
            return '';
        }
        $n = (float) $s;
        if ($n <= 0) {
            return '';
        }
        $i = (int) round($n);
        // دو صفر اعشار (.00) حذف می‌شود؛ جداکننده سه‌رقمی می‌ماند
        return number_format($i, 0, '.', ',');
    }
}

if (!function_exists('melkinoMapPublicCard')) {
    function melkinoMapPublicCard(PDO $pdo, array $ad, array $settings, string $secret, ?string $thumb = null): array
    {
        $lat = melkinoMapParseCoord($ad['latitude'] ?? null);
        $lng = melkinoMapParseCoord($ad['longitude'] ?? null);
        $radius = (int) $settings['privacy_radius'];
        $pub = ($lat !== null && $lng !== null)
            ? melkinoMapMaskPoint($lat, $lng, (string) $ad['id'], $radius, $secret)
            : null;
        $tx = (string) ($ad['transaction_type'] ?? '');
        $txN = str_replace(["\u{200c}", '‌', ' ', '-'], '', $tx);
        $price = '';
        if (strpos($txN, 'پیشفروش') !== false) {
            $price = melkinoFormatMoneyDisplay($ad['total_price'] ?? $ad['display_price'] ?? $ad['price_sell'] ?? '');
        } elseif (strpos($txN, 'فروش') !== false) {
            $price = melkinoFormatMoneyDisplay($ad['price_sell'] ?? $ad['display_price'] ?? $ad['total_price'] ?? '');
        } elseif (strpos($txN, 'رهنکامل') !== false) {
            $price = melkinoFormatMoneyDisplay($ad['full_rent'] ?? $ad['deposit'] ?? '');
        } else {
            $d = melkinoFormatMoneyDisplay($ad['deposit'] ?? '');
            $r = melkinoFormatMoneyDisplay($ad['rent_monthly'] ?? '');
            $price = trim(($d ? 'ودیعه: '.$d : '') . ($d && $r ? ' | ' : '') . ($r ? 'اجاره: '.$r : ''));
        }
        $priceHidden = function_exists('shouldHidePublicPrice') && shouldHidePublicPrice($ad);
        if ($priceHidden) $price = '';
        if ($thumb === null) {
            $thumb = '';
            try {
                $st = $pdo->prepare("SELECT COALESCE(NULLIF(storage_path,''),filename) FROM images WHERE ad_id=? AND is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC, sort_order ASC LIMIT 1");
                $st->execute([(string) $ad['id']]);
                $thumb = (string) ($st->fetchColumn() ?: '');
            } catch (Throwable $e) {
            }
        }
        if ($thumb === '' && function_exists('melkinoDefaultImageForAd')) {
            $thumb = melkinoDefaultImageForAd($ad);
        }
        // Only local public image paths; never echo an external URL or traversal.
        $thumb = trim((string)$thumb);
        if ($thumb !== '' && (str_contains($thumb,'..') || str_contains($thumb,':') || str_contains($thumb,'\\') || strpbrk($thumb, '%?#') !== false || preg_match('/[\x00-\x1f\x7f]/',$thumb))) $thumb = '';
        $thumb = ltrim($thumb,'/');
        if ($thumb !== '' && !str_starts_with($thumb,'uploads/') && !str_starts_with($thumb,'assets/')) $thumb = 'uploads/'.$thumb;
        $pt = (string) ($ad['property_type'] ?? '');
        $colors = $settings['marker_colors'] ?? [];
        $color = (string) ($colors[$pt] ?? '');
        if ($color === '' && mb_strpos($pt, 'ویلا') !== false) {
            $color = (string) ($colors['ویلایی'] ?? $colors['ویلا'] ?? '#22c55e');
        }
        $amen = $ad['_amenities'] ?? [];
        if (!is_array($amen)) {
            $amen = [];
        }
        $blob = implode(' ', $amen);
        $has = static function (array $keys) use ($blob): bool {
            foreach ($keys as $k) {
                if ($k !== '' && mb_stripos($blob, $k) !== false) {
                    return true;
                }
            }
            return false;
        };
        return [
            'id' => (string) $ad['id'],
            'title' => (string) ($ad['title'] ?? 'ملک'),
            'code' => (string) (($ad['ad_code'] ?? '') ?: $ad['id']),
            'location' => (string) ($ad['location'] ?? ''),
            'floor' => (string) ($ad['floor'] ?? ''),
            'year' => (string) ($ad['year'] ?? ''),
            'price_hidden' => $priceHidden,
            'type' => $pt,
            'transaction_type' => $tx,
            // برای مارکر ویژه (رنگ طلایی) در نقشه
            'is_vip' => (int) ($ad['is_vip'] ?? 0) === 1,
            'area' => $ad['area'] ?? $ad['land_area'] ?? $ad['built_area'] ?? '',
            'price' => $price,
            'rooms' => $ad['rooms'] ?? '',
            'building_age' => $ad['building_age'] ?? '',
            'thumbnail' => $thumb,
            'color' => $color !== '' ? $color : '#0e7c6e',
            'public_location' => $pub,
            'amenities' => $amen,
            'has_parking' => $has(['پارکینگ', 'parking']),
            'has_elevator' => $has(['آسانسور', 'elevator']),
            'has_storage' => $has(['انباری', 'storage']),
        ];
    }
}

if (!function_exists('melkinoMapParsePolygon')) {
    function melkinoMapParsePolygon($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $pts = [];
        foreach ($raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $lat = melkinoMapParseCoord($p['lat'] ?? $p['latitude'] ?? $p[0] ?? null);
            $lng = melkinoMapParseCoord($p['lng'] ?? $p['longitude'] ?? $p[1] ?? null);
            if ($lat !== null && $lng !== null) {
                $pts[] = ['lat' => $lat, 'lng' => $lng];
            }
        }
        return count($pts) >= 3 ? array_slice($pts, 0, 8) : [];
    }
}

if (!function_exists('melkinoMapPointInPolygon')) {
    function melkinoMapPointInPolygon(float $lat, float $lng, array $poly): bool
    {
        $n = count($poly);
        if ($n < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = (float) $poly[$i]['lat'];
            $xi = (float) $poly[$i]['lng'];
            $yj = (float) $poly[$j]['lat'];
            $xj = (float) $poly[$j]['lng'];
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }
}

if (!function_exists('melkinoMapRequestPolygon')) {
    function melkinoMapRequestPolygon(array $request): array
    {
        $details = $request['property_details'] ?? $request['_details'] ?? [];
        if (is_string($details)) {
            $details = json_decode($details, true) ?: [];
        }
        if (!is_array($details)) {
            $details = [];
        }
        $raw = $request['search_polygon'] ?? $details['search_polygon'] ?? $request['_polygon'] ?? null;
        return melkinoMapParsePolygon($raw);
    }
}

if (!function_exists('melkinoMapAdInPolygon')) {
    function melkinoMapAdInPolygon(array $ad, array $poly): bool
    {
        if (!$poly) {
            return true;
        }
        $lat = melkinoMapParseCoord($ad['latitude'] ?? null);
        $lng = melkinoMapParseCoord($ad['longitude'] ?? null);
        if ($lat === null || $lng === null) {
            return false;
        }
        return melkinoMapPointInPolygon($lat, $lng, $poly);
    }
}
