<?php
/**
 * بدون ورود، هیچ صفحهٔ عمومی ملکینو دیده نمی‌شود.
 * وب‌هوک ربات، ورود، و پنل ادمین مستثنا هستند.
 */
/**
 * راند ۶۴ — حفظ دادهٔ هویت مینی‌اپ هنگام پرتاب به صفحهٔ ورود.
 *
 * مشکل: تلگرام/بله دادهٔ هویت را در آدرس صفحه می‌گذارند (کوئری tgWebAppData
 * یا هش #tgWebAppData). وقتی مینی‌اپ یک صفحهٔ محافظت‌شده (مثل home.php) را
 * باز می‌کند، سرور کاربرِ مهمان را با 302 به login.php می‌فرستد و پارامترها
 * وسط راه گم می‌شوند → ورود ناممکن می‌شود. (هش را جاوااسکریپتِ اولِ head
 * ذخیره می‌کند؛ این توابع مسیر کوئری را پوشش می‌دهند.)
 */
if (!function_exists('melkinoMiniAppForwardQuery')) {
    /**
     * پارامترهای tgWebApp* کوئری جاری را به‌صورت «&k=v&k=v...» برمی‌گرداند
     * تا به انتهای URL ورود الصاق شود. خروجی خالی = چیزی برای فوروارد نیست.
     */
    function melkinoMiniAppForwardQuery(): string
    {
        if (empty($_GET) || !is_array($_GET)) {
            return '';
        }
        $out = '';
        foreach ($_GET as $k => $v) {
            if (!is_string($k) || stripos($k, 'tgwebapp') !== 0) {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $vs = (string)$v;
            if ($vs === '') {
                continue;
            }
            $out .= '&' . rawurlencode($k) . '=' . rawurlencode($vs);
            // سقف طول: آدرس‌های بیش‌ازحد بلند را ناقص فوروارد نمی‌کنیم.
            if (strlen($out) > 3500) {
                return '';
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoMiniAppCleanHere')) {
    /**
     * کلیدهای tgWebApp* را از کوئریِ $here حذف می‌کند تا در پارامتر redirect
     * تودرتو نشوند (کوتاه نگه‌داشتن URL + جلوگیری از نشت داده به لاگ‌ها).
     * بقیهٔ پارامترها (مثل ?tab=ads) دست‌نخورده می‌مانند.
     */
    function melkinoMiniAppCleanHere(string $here): string
    {
        $qpos = strpos($here, '?');
        if ($qpos === false) {
            return $here;
        }
        $path = substr($here, 0, $qpos);
        $query = substr($here, $qpos + 1);
        if ($query === '' || stripos($query, 'tgwebapp') === false) {
            return $here;
        }
        $kept = [];
        parse_str($query, $parsed);
        if (!is_array($parsed)) {
            return (string)$path;
        }
        foreach ($parsed as $k => $v) {
            if (stripos((string)$k, 'tgwebapp') === 0 || is_array($v)) {
                continue;
            }
            $kept[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }
        return $kept ? ($path . '?' . implode('&', $kept)) : (string)$path;
    }
}

if (!function_exists('melkinoEnforceSiteLogin')) {
    function melkinoEnforceSiteLogin(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (defined('PHP_SAPI') && PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        }

        $page = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        if ($page === '') {
            $page = strtolower(basename((string) ($_SERVER['PHP_SELF'] ?? 'home.php')));
        }

        $allow = [
            'login.php',
            'logout.php',
            'auth.php',
            'auth-telegram.php',
            'auth-bale.php',
            'auth-eitaa.php',
            'eitaa-app.php', // Public preload only; identity is verified by auth-eitaa.php.
            'telegram-app.php',
            'bale-app.php',
            'messenger-auth.php',
            'bale-contact-hook.php',
            'telegram-webhook.php', // Telegram webhook: anonymous by design; X-Telegram-Bot-Api-Secret-Token is checked inside.
            'request-otp.php',
            'verify-otp.php',
            'admin-login.php',
            'admin-logout.php',
            'admin-panel.php', // Handles its own is_admin check and sends guests to admin-login.php (not the messenger login).
            'telegram.php',
            'bale.php',
            'eitaa.php',
            'telegram-relay.php',
            'identity-sync.php',
            'bale-ok.php',
            'r.php',
            'sitemap.php', // Lists nothing while the site lock is ON (see seo-lib.php).
        ];
        if (in_array($page, $allow, true)) {
            return;
        }
        // Public browsing: only while the site lock is OFF (site_lock.locked=0).
        // The lock ON keeps the login wall everywhere, exactly as before.
        require_once __DIR__ . '/public-browse-lib.php';
        if (melkinoPublicBrowseGuest()) {
            return;
        }
        // Admin-only files: admin-* (except the entry/login/logout pages, which
        // handle their own auth) and design-studio.php. Previously every admin-*
        // file skipped this gate and several fragments had no guard of their own
        // (anonymous and plain-user requests received HTML/data). Deny everyone
        // who is not an admin session here.
        $adminOnlyPage = (substr($page, 0, 6) === 'admin-' || $page === 'design-studio.php')
            && !in_array($page, ['admin-panel.php', 'admin-login.php', 'admin-logout.php'], true);
        if ($adminOnlyPage) {
            if (!empty($_SESSION['is_admin'])) {
                return;
            }
            $adminAccept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
            $adminXhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
            $adminWantsJson = (substr($page, -8) === '-api.php')
                || isset($_GET['action']) || isset($_POST['action'])
                || strpos($adminAccept, 'application/json') !== false
                || $adminXhr === 'xmlhttprequest';
            $adminLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['user_phone'])
                || !empty($_SESSION['reg_telegram_id']) || !empty($_SESSION['reg_bale_id'])
                || !empty($_SESSION['reg_eitaa_id']);
            if ($adminWantsJson) {
                if (!headers_sent()) {
                    http_response_code(403); // same contract as melkinoRequireAdminJson()
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store, private');
                }
                echo json_encode(['success' => false, 'ok' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!$adminLoggedIn && !headers_sent()) {
                header('Cache-Control: no-store, private');
                header('Location: admin-login.php', true, 302);
                exit;
            }
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store, private');
            }
            echo 'دسترسی غیرمجاز';
            exit;
        }

        if (!headers_sent()) header('Cache-Control: no-store, private');
        $loginEntry = function_exists('melkinoAppBasePath') ? rtrim(melkinoAppBasePath(), '/') . '/login.php' : 'login.php';

        $logged = !empty($_SESSION['user_id'])
            || !empty($_SESSION['reg_telegram_id'])
            || !empty($_SESSION['reg_bale_id'])
            || !empty($_SESSION['reg_eitaa_id'])
            || !empty($_SESSION['user_phone'])
            || !empty($_SESSION['is_admin']);

        if (!$logged && function_exists('melkinoCurrentIdentity')) {
            $idn = melkinoCurrentIdentity();
            $logged = !empty($idn['user_id']);
        }
        if ($logged) {
            return;
        }

        // Only this GET/HEAD is public; it renders a separate, public-only projection.
        require_once __DIR__ . '/shared-property.php';
        if (melkinoIsSharedPropertyRequest()) {
            return;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $isApi = (substr($page, -8) === '-api.php')
            || isset($_GET['action'])
            || isset($_POST['action'])
            || strpos($accept, 'application/json') !== false
            || $xhr === 'xmlhttprequest';

        if ($isApi) {
            if (!headers_sent()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'ok' => false,
                'message' => 'برای دیدن این بخش باید وارد ملکینو شوی.',
                'login' => $loginEntry,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // A known mini-app HTML entry must reach the preload BEFORE any PHP 302.
        // UA/query/referrer only select presentation; identity still requires HMAC.
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
            require_once __DIR__ . '/messenger-login-lib.php';
            $hasLaunchQuery = false;
            foreach (array_keys($_GET) as $key) {
                if (stripos((string)$key, 'tgWebApp') === 0) { $hasLaunchQuery = true; break; }
            }
            if ($hasLaunchQuery || melkinoMessengerHint() !== '') {
                $query = $_GET;
                foreach (array_keys($query) as $key) {
                    if (stripos((string)$key,'tgWebApp') === 0 || in_array($key,['messenger','t','redirect'],true)) unset($query[$key]);
                }
                $melkinoLoginNext = $page . ($query ? '?' . http_build_query($query) : '');
                require __DIR__ . '/messenger-login-page.php';
                exit;
            }
        }

        $here = (string) ($_SERVER['REQUEST_URI'] ?? $page);
        $here = preg_replace('#^/+#', '', $here) ?? $page;
        if ($here === '' || strpos($here, 'login.php') === 0) {
            $here = 'home.php';
        }
        // راند ۶۴: فوروارد پارامترهای tgWebApp* تا دادهٔ هویت در پرتاب گم نشود.
        $fwd = function_exists('melkinoMiniAppForwardQuery') ? melkinoMiniAppForwardQuery() : '';
        if ($fwd !== '' && function_exists('melkinoMiniAppCleanHere')) {
            $here = melkinoMiniAppCleanHere($here);
        }
        if (function_exists('melkinoAppRelativeTarget')) $here = melkinoAppRelativeTarget($here);
        $to = $loginEntry . '?redirect=' . rawurlencode($here) . $fwd;
        if (!headers_sent()) {
            header('Location: ' . $to, true, 302);
        } else {
            echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
                . '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '">'
                . '<script>location.replace(' . json_encode($to, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');</script>'
                . '' . (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : '') . '</head><body>برای دیدن ملکینو باید وارد شوید. <a href="'
                . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '">ورود</a></body></html>';
        }
        exit;
    }
}

if (!function_exists('melkinoRequireLogin')) {
    function melkinoRequireLogin(?string $returnTo = null)
    {
        melkinoEnforceSiteLogin();
        return function_exists('melkinoCurrentIdentity') ? melkinoCurrentIdentity() : [];
    }
}

melkinoEnforceSiteLogin();
