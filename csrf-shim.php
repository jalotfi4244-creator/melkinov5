<?php
/*
|--------------------------------------------------------------------------
| csrf-shim.php — تزریق توکن CSRF به مرورگر
|--------------------------------------------------------------------------
| این فایل را در صفحاتی که درخواست fetch/XHR داخلی انجام می‌دهند include
| کنید (header.php و admin-panel.php). دو کار انجام می‌دهد:
|   1) توکن سشنی را در window.MELKINO_CSRF قرار می‌دهد.
|   2) fetch و XMLHttpRequest را wrap می‌کند تا برای همه‌ی درخواست‌های
|      تغییردهنده (POST/PUT/PATCH/DELETE) هم‌مبدا (same-origin)، هدر
|      X-CSRF-Token را به‌صورت خودکار اضافه کند — بدون نیاز به تغییر
|      هر call-site.
|
| سمت سرور، melkinoCsrfCheck() (تعریف‌شده در config.php) هدر/فیلد
| csrf_token را با توکن سشن تطبیق می‌دهد.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        @session_start();
    }
}
$melkinoShimToken = function_exists('melkinoCsrfToken') ? melkinoCsrfToken() : '';
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars((string)$melkinoShimToken, ENT_QUOTES, 'UTF-8'); ?>">
<script<?php echo function_exists('csp_nonce_attr') ? csp_nonce_attr() : ''; ?>>
(function () {
    'use strict';
    var TOKEN = <?php echo json_encode((string) $melkinoShimToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (TOKEN) { window.MELKINO_CSRF = TOKEN; }
    if (!TOKEN) { return; }

    function sameOrigin(u) {
        if (!u) { return false; }
        try {
            return new URL(u, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    // --- fetch ---
    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (input, init) {
            init = init || {};
            var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
            if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') {
                return originalFetch.apply(this, arguments);
            }
            var url = (typeof input === 'string') ? input : ((input && input.url) || '');
            if (sameOrigin(url)) {
                var headers = new Headers(init.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', TOKEN);
                }
                init.headers = headers;
            }
            return originalFetch.call(this, input, init);
        };
    }

    // --- XMLHttpRequest (برای آپلودها و کدهای قدیمی‌تر) ---
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__melkinoCsrfMethod = String(method || 'GET').toUpperCase();
        this.__melkinoCsrfUrl = url;
        return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        try {
            if (
                this.__melkinoCsrfMethod &&
                this.__melkinoCsrfMethod !== 'GET' &&
                this.__melkinoCsrfMethod !== 'HEAD' &&
                sameOrigin(this.__melkinoCsrfUrl)
            ) {
                this.setRequestHeader('X-CSRF-Token', TOKEN);
            }
        } catch (e) { /* نادیده گرفته می‌شود */ }
        return origSend.apply(this, arguments);
    };
})();
</script>
<?php if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin-panel.php'): ?>
<?php if (is_file(__DIR__ . '/admin-contact-social.js')): ?>
<script src="admin-contact-social.js?v=<?= (int) @filemtime(__DIR__ . '/admin-contact-social.js') ?>" defer></script>
<?php endif; ?>
<script src="admin-photo-watermark.js?v=<?= (int) @filemtime(__DIR__ . '/admin-photo-watermark.js') ?>" defer></script>
<?php endif; ?>
