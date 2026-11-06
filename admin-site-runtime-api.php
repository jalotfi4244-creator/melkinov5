<?php
/** API قفل سایت و آمار بازدید (فقط ادمین). */
require_once __DIR__ . '/admin-guard.php';
melkinoRequireAdminJson();
require_once __DIR__ . '/site-runtime-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function srJsonOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    srJsonOut(['success' => false, 'message' => 'اتصال پایگاه داده برقرار نیست.'], 500);
}

$action = (string)($_REQUEST['action'] ?? 'stats');

try {
    if ($action === 'set_lock' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $locked = !empty($_POST['locked']) && $_POST['locked'] !== '0' ? 1 : 0;
        $message = trim((string)($_POST['message'] ?? ''));
        if (function_exists('mb_substr')) { $message = mb_substr($message, 0, 255, 'UTF-8'); }
        $by = (string)($_SESSION['admin_username'] ?? $_SESSION['admin_name'] ?? 'admin');
        $by = substr($by, 0, 120);
        $pdo->prepare(
            "UPDATE site_lock SET locked = ?, message = ?, updated_at = NOW(), updated_by = ? WHERE id = 1"
        )->execute([$locked, $message, $by]);
        srJsonOut(['success' => true, 'lock' => siteRuntimeLockRow($pdo)]);
    }

    if ($action !== 'stats') {
        srJsonOut(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
    }

    $today = date('Y-m-d');
    $todayUnique = (int)$pdo->query("SELECT COUNT(*) FROM site_visits_daily WHERE day = CURDATE()")->fetchColumn();
    $todayHits = (int)$pdo->query("SELECT COALESCE(SUM(hits),0) FROM site_visits_daily WHERE day = CURDATE()")->fetchColumn();
    $allUnique = (int)$pdo->query("SELECT COUNT(DISTINCT visitor_hash) FROM site_visits_daily")->fetchColumn();
    $series = [];
    $st = $pdo->query(
        "SELECT day, COUNT(*) AS visitors, SUM(hits) AS hits FROM site_visits_daily
         WHERE day >= CURDATE() - INTERVAL 6 DAY GROUP BY day ORDER BY day ASC"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $series[] = ['day' => $row['day'], 'visitors' => (int)$row['visitors'], 'hits' => (int)$row['hits']];
    }
    $pages = [];
    $st = $pdo->query(
        "SELECT path, SUM(hits) AS hits FROM site_page_hits_daily
         WHERE day >= CURDATE() - INTERVAL 6 DAY GROUP BY path ORDER BY hits DESC LIMIT 10"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pages[] = ['path' => $row['path'], 'hits' => (int)$row['hits']];
    }
    srJsonOut([
        'success' => true,
        'today' => $today,
        'lock' => siteRuntimeLockRow($pdo),
        'visitors' => ['today_unique' => $todayUnique, 'today_hits' => $todayHits, 'all_unique' => $allUnique],
        'series7' => $series,
        'pages7' => $pages,
    ]);
} catch (Throwable $e) {
    error_log('admin-site-runtime-api: ' . $e->getMessage());
    srJsonOut(['success' => false, 'message' => 'خطا در پردازش.'], 500);
}
