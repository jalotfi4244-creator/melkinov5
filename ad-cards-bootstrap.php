<?php
/**
 * راند ۶۷: بوت‌استرپ مشترک کارت‌های آگهی (home / vip / properties)
 * متغیرهای window.MELKINO_* و فایل‌های ad-cards.css/js را تزریق می‌کند تا
 * کارت‌ها در همهٔ صفحه‌ها دقیقاً یکسان رندر شوند.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/card-display.php';
require_once __DIR__ . '/db_helpers.php';
if (is_file(__DIR__ . '/db-settings.php')) {
    require_once __DIR__ . '/db-settings.php';
}

if (!function_exists('melkinoAdCardsHead')) {
    function melkinoAdCardsHead(string $scope = 'list'): string
    {
        static $sent = false;
        if ($sent) {
            return '';
        }
        $sent = true;
        $payload = function_exists('melkinoCardDisplayFrontendPayload')
            ? melkinoCardDisplayFrontendPayload($scope)
            : ['settings' => (object)[], 'specs' => (object)[]];
        $gp = getGlobalSettings();
        $hide = (!$gp['show_prices'] || !empty($gp['hide_all_prices']));
        // راند ۷۲: نسخه‌دار پویا با filemtime — هر آپلود جدید فایل، کش مرورگر/تله‌گرام
        // را خودبه‌خود می‌شکند (مثل لوگو در راند ۶۸) تا همیشه آخرین رندرر اجرا شود.
        $cssV = (int)@filemtime(__DIR__ . '/ad-cards.css');
        $jsV  = (int)@filemtime(__DIR__ . '/ad-cards.js');
        $out  = "<link rel=\"stylesheet\" href=\"ad-cards.css?v=$cssV\">\n";
        if (is_file(__DIR__ . '/mk-card-layouts-100.css')) {
            $out .= "<link rel=\"stylesheet\" href=\"mk-card-layouts-100.css?v=" . (int)@filemtime(__DIR__ . '/mk-card-layouts-100.css') . "\">\n";
        }
        $ratingOn = function_exists('melkinoRatingFeatureEnabled') ? melkinoRatingFeatureEnabled() : false;
        $logo = '';
        if (function_exists('melkinoSiteLogoUrl')) {
            $logo = (string)melkinoSiteLogoUrl();
        } elseif (is_file(__DIR__ . '/melkino-logo.php')) {
            require_once __DIR__ . '/melkino-logo.php';
            if (function_exists('melkinoSiteLogoUrl')) {
                $logo = (string)melkinoSiteLogoUrl();
            }
        }
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
        $out .= "<script>window.MELKINO_CARD_DISPLAY = " . json_encode($payload, $jsonFlags) . ";";
        $out .= "window.MELKINO_PRICE_VISIBILITY = {hide: " . ($hide ? 'true' : 'false') . ", message: " . json_encode(melkinoPriceMessage(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . "};";
        $out .= "window.MELKINO_DEFAULT_IMAGES = " . json_encode(melkinoDefaultImagesMap(), $jsonFlags) . ";";
        $out .= "window.MELKINO_SITE_LOGO = " . json_encode($logo, $jsonFlags) . ";";
        if (is_file(__DIR__ . '/photo-watermark.php')) {
            require_once __DIR__ . '/photo-watermark.php';
        }
        $wm = function_exists('melkinoPhotoWatermark') ? melkinoPhotoWatermark() : ['enabled' => true, 'opacity' => 0.32, 'size' => 38];
        $out .= "window.MELKINO_PHOTO_WM = " . json_encode($wm, $jsonFlags) . ";";
        $out .= "window.MELKINO_RATING_ENABLED = " . ($ratingOn ? 'true' : 'false') . ";";
        if (is_file(__DIR__ . '/jalali-lib.php')) {
            require_once __DIR__ . '/jalali-lib.php';
        }
        $jyNow = function_exists('melkinoJalaliCurrentYear') ? (int) melkinoJalaliCurrentYear() : 1405;
        $cardLayout = 'photo-top';
        try {
            global $pdo;
            if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
                $cardLayout = (string) dbSettingGet($pdo, 'global', 'card_layout', 'photo-top');
            } elseif (function_exists('getGlobalSettings')) {
                $gs = getGlobalSettings();
                $cardLayout = (string) ($gs['card_layout'] ?? 'photo-top');
            }
        } catch (Throwable $e) {
            $cardLayout = 'photo-top';
        }
        $okLayouts = ['photo-top','photo-full','photo-left','photo-right','photo-float','photo-collage','photo-portrait','photo-editorial'];
        if (!in_array($cardLayout, $okLayouts, true)) {
            $cardLayout = 'photo-top';
        }
        $studio = null;
        try {
            global $pdo;
            if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
                if (is_file(__DIR__ . '/design-studio-lib.php')) {
                    require_once __DIR__ . '/design-studio-lib.php';
                }
                $rawPub = dbSettingGet($pdo, 'theme', 'studio_published', null);
                $rawStudio = $rawPub; // Only an explicitly applied/published theme is public.
                if (is_array($rawStudio) && function_exists('melkinoStudioSanitize')) {
                    $studio = melkinoStudioSanitize($rawStudio);
                    $cardLayout = (string) ($studio['layout'] ?? $cardLayout);
                }
            }
        } catch (Throwable $e) {
            $studio = null;
        }
        $out .= "window.MELKINO_JALALI_YEAR = " . $jyNow . ";";
        $out .= "window.MELKINO_CARD_LAYOUT = " . json_encode($cardLayout, $jsonFlags) . ";";
        $out .= "window.MELKINO_STUDIO = " . json_encode($studio ?: new stdClass(), $jsonFlags) . ";</script>\n";
        if (is_array($studio) && function_exists('melkinoStudioCss')) {
            $safeCss = melkinoStudioCss($studio);
            $safeCss = preg_replace('/<\/style/i', '<\\/style', $safeCss);
            $out .= "<style id=\"mk-studio-runtime\">" . $safeCss . "</style>\n";
        }
        $wmJsV = (int) @filemtime(__DIR__ . '/photo-wm-front.js');
        $out .= "<script src=\"photo-wm-front.js?v=$wmJsV\"></script>\n";
        $out .= "<script src=\"ad-cards.js?v=$jsV\"></script>\n";
        return $out;
    }
}
