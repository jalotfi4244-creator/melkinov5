<?php
/**
|--------------------------------------------------------------------------
| محافظ مشترکِ endpointهای پنل ادمین
|--------------------------------------------------------------------------
| هر فایل API جدیدِ پنل، ابتدا این فایل را require می‌کند.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-lib.php';

if (!function_exists('melkinoRequireAdminJson')) {
    function melkinoRequireAdminJson(): void
    {
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // محافظت CSRF: همه‌ی درخواست‌های تغییردهنده (POST/PUT/PATCH/DELETE)
        // باید هدر X-CSRF-Token (یا csrf_token در بدنه) را با توکن سشن
        // تطبیق بدهند تا فرم‌های cross-origin از سایت‌های متخلف رد شوند.
        if (melkinoIsMutatingRequest()) {
            melkinoCsrfCheck();
        }
    }
}

/**
 * POST-only enforcement for mutating admin actions.
 * Rationale: melkinoRequireAdminJson() runs the CSRF check only for mutating
 * HTTP methods, and SameSite=Lax cookies ARE sent on top-level GET navigation —
 * so any state-changing ?action= reachable via GET is CSRF-able (confirmed live
 * case: admin-backup.php?action=create). Forcing POST routes the request back
 * under the existing CSRF/Sec-Fetch check. Reads stay on GET.
 */
if (!function_exists('melkinoRequirePostFor')) {
    function melkinoRequirePostFor(array $mutatingActions, string $action): void
    {
        if ($action === '' || !in_array($action, $mutatingActions, true)) {
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'این عمل فقط با POST مجاز است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!function_exists('melkinoAdminJson')) {
    function melkinoAdminJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (!function_exists('melkinoAdminJsonBody')) {
    function melkinoAdminJsonBody(): array
    {
        if (function_exists('melkinoReadRequestBody')) {
            $data = melkinoReadRequestBody();
            if (is_array($data) && $data) {
                return $data;
            }
        }
        $raw = (string) @file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        return is_array($_POST) ? $_POST : [];
    }
}
