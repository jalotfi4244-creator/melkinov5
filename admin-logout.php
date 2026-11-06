<?php
/*
|--------------------------------------------------------------------------
| خروج امن ادمین
|--------------------------------------------------------------------------
| قبلاً «خروج» پنل فقط یک redirect سمت کلاینت بود و $_SESSION['is_admin']
| و کوکی نشست هرگز از سمت سرور خراب نمی‌شدند؛ یعنی هر کسی که کوکی
| نشست را داشته باشد می‌توانست بعد از «خروج» هم وارد پنل بماند.
|
| این endpoint سشن را کامل می‌سازد و کوکی نشست را حذف می‌کند و سپس
| به صفحه‌ی ورود ادمین برمی‌گردد. (فقط POST — برای جلوگیری از خروج
| سرنتمی با یک لینک/تصویر از سایت دیگر.)
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'روش مجاز نیست'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

session_unset();
session_destroy();

// کوکی نشست را در مرورگر هم خراب کن
$cookieParams = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 3600,
    'path'     => $cookieParams['path'] !== '' ? $cookieParams['path'] : '/',
    'domain'   => $cookieParams['domain'],
    'secure'   => $cookieParams['secure'],
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: admin-login.php', true, 302);
exit;
