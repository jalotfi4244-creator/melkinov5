<?php
/**
 * لایهٔ ورود مینی‌اپ: تا هویت تلگرام/بله روی سرور ثبت نشود
 * صفحهٔ خانه و تور راهنما نشان داده نمی‌شوند.
 */
if (!function_exists('melkinoAuthGateBoot')) {
    function melkinoAuthGateBoot()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $logged = !empty($_SESSION['reg_telegram_id'])
            || !empty($_SESSION['reg_bale_id'])
            || !empty($_SESSION['user_id'])
            || !empty($_SESSION['is_admin']);
        $page = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $jsV = (int) @filemtime(__DIR__ . '/melkino-auth-gate.js');
        ?>
<div id="mkAuthGate" class="mk-auth-gate is-off" hidden aria-live="polite">
    <div class="mk-auth-box">
        <div class="mk-auth-kicker">ملکینو</div>
        <div class="mk-auth-title" id="mkAuthTitle">در حال ورود</div>
        <div class="mk-auth-sub" id="mkAuthSub">صبر کنید تا حساب شما ساخته شود</div>
        <div class="mk-auth-track" aria-hidden="true"><i id="mkAuthBar"></i></div>
    </div>
</div>
<style>
.mk-auth-gate{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:#0c1412;color:#fff;font-family:Vazirmatn,Tahoma,sans-serif;transition:opacity .28s ease}
.mk-auth-gate.is-off{opacity:0;pointer-events:none}
.mk-auth-box{width:min(320px,86vw);text-align:center}
.mk-auth-kicker{font-size:11px;letter-spacing:.18em;color:#f5c518;margin-bottom:10px;font-weight:800}
.mk-auth-title{font-size:22px;font-weight:800;color:#f5c518;margin-bottom:8px}
.mk-auth-sub{font-size:13px;line-height:1.8;color:rgba(255,255,255,.78);margin-bottom:22px}
.mk-auth-track{height:4px;border-radius:99px;background:#1c2a26;overflow:hidden}
.mk-auth-track>i{display:block;height:100%;width:12%;background:#f5c518;border-radius:99px;transition:width .35s ease}
</style>
<script>
window.MELKINO_AUTH_GATE = true;
window.MELKINO_AUTH = <?= json_encode([
    'logged_in' => (bool) $logged,
    'page' => $page,
    'go_home' => ($page === 'index.php'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="melkino-auth-gate.js?v=<?= $jsV ?>"></script>
        <?php
    }
}
