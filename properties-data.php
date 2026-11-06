<?php
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
require_once __DIR__ . '/public-browse-lib.php';
if (!isset($GLOBALS['pdo']) && melkinoPublicBrowseRequest()) {
    // Public listing feed: load config (sets $pdo; the main gate allows public pages only while the lock is OFF).
    require_once __DIR__ . '/config.php';
}
$_mkPage = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
$_mkAllow = ['login.php','logout.php','auth.php','auth-telegram.php','auth-bale.php','auth-eitaa.php','request-otp.php','verify-otp.php','admin-login.php','admin-logout.php','telegram.php','bale.php','eitaa.php','telegram-relay.php','identity-sync.php','bale-ok.php','r.php'];
if (
    $_mkPage !== ''
    && !in_array($_mkPage, $_mkAllow, true)
    && strncmp($_mkPage, 'admin-', 6) !== 0
    && empty($_SESSION['user_id'])
    && empty($_SESSION['reg_telegram_id'])
    && empty($_SESSION['reg_bale_id'])
    && empty($_SESSION['reg_eitaa_id'])
    && empty($_SESSION['user_phone'])
    && empty($_SESSION['is_admin'])
    && !melkinoPublicBrowseGuest() // Site lock OFF: public read-only pages (see public-browse-lib.php).
) {
    $here = (string) ($_SERVER['REQUEST_URI'] ?? $_mkPage);
    $here = preg_replace('#^/+#', '', $here) ?? $_mkPage;
    if ($here === '' || strpos($here, 'login.php') === 0) {
        $here = 'home.php';
    }
    if (!headers_sent()) {
        header('Location: login.php?redirect=' . rawurlencode($here), true, 302);
    }
    exit;
}
unset($_mkPage, $_mkAllow);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php';

if (!function_exists('melkinoPublishedAdsForCards')) {
    function melkinoPublishedAdsForCards(?PDO $pdo): array
    {
        if (!($pdo instanceof PDO)) {
            return [];
        }
        $stmt = $pdo->query("SELECT a.* FROM ads a WHERE a.status='published' ORDER BY a.created_at DESC");
        $ads = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $imagesByAd = [];
        try {
            $allImages = $pdo->query("SELECT ad_id,filename,is_selected,publish_publicly FROM images WHERE is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC,sort_order ASC,id ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allImages as $im) {
                $imagesByAd[(string)$im['ad_id']][] = $im['filename'];
            }
        } catch (Throwable $e) {
            $imagesByAd = [];
        }
        $amenitiesByAd = [];
        try {
            $allAmen = $pdo->query("SELECT aa.ad_id AS ad_id, am.name AS name FROM ad_amenities aa INNER JOIN amenities am ON am.id=aa.amenity_id ORDER BY am.sort_order,am.id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allAmen as $am) {
                $amenitiesByAd[(string)$am['ad_id']][] = $am['name'];
            }
        } catch (Throwable $e) {
            $amenitiesByAd = [];
        }
        foreach ($ads as &$ad) {
            // آگهی با متن جایگزین قیمت: مبلغ خام به مرورگر نرسد
            if (function_exists('melkinoPublicPriceRow') && trim((string)($ad['price_note'] ?? '')) !== '') {
                $ad = melkinoPublicPriceRow($ad);
            }
            $ad['selected_images'] = $imagesByAd[(string)$ad['id']] ?? [];
            $ad['amenities'] = $amenitiesByAd[(string)$ad['id']] ?? [];
            if (function_exists('melkinoNormalizeJsonColumn')) {
                $ad['property_details'] = melkinoNormalizeJsonColumn($ad['property_details'] ?? null);
                $ad['tags'] = melkinoNormalizeJsonColumn($ad['tags'] ?? null);
                $ad['custom_fields'] = melkinoNormalizeJsonColumn($ad['custom_fields'] ?? null);
            }
        }
        unset($ad);
        // Signed-out viewers (public browsing, site lock OFF) get the public view only.
        if (!melkinoViewerLoggedIn()) {
            $ads = array_map('melkinoPublicAdPublicView', $ads);
        }
        return $ads;
    }
}

if (!defined('MELKINO_PROPERTIES_DATA_NO_OUTPUT')) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$pdo instanceof PDO) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        echo json_encode(melkinoPublishedAdsForCards($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'خطا در دریافت آگهی‌ها.'], JSON_UNESCAPED_UNICODE);
    }
}
