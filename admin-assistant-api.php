<?php require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-guard.php';
if (is_file(__DIR__ . '/db_helpers.php')) {
    require_once __DIR__ . '/db_helpers.php';
}
if (is_file(__DIR__ . '/visit-request-lib.php')) {
    require_once __DIR__ . '/visit-request-lib.php';
}
require_once __DIR__ . '/admin-assistant-engine.php';

melkinoRequireAdminJson();

global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'دیتابیس در دسترس نیست.'], 500);
}

$action = (string)($_GET['action'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = function_exists('melkinoAdminJsonBody') ? melkinoAdminJsonBody() : [];
if (!is_array($body)) {
    $body = [];
}
if ($action === '' && isset($body['action'])) {
    $action = (string)$body['action'];
}

function astBoardPayload(array $board): array
{
    $run = $board['run'];
    return [
        'success' => true,
        'run' => $run ? [
            'id' => (int)$run['id'],
            'finished_at' => $run['finished_at'],
            'views_n' => (int)$run['views_n'],
            'requests_n' => (int)$run['requests_n'],
            'matches_n' => (int)$run['matches_n'],
            'ads_n' => (int)$run['ads_n'],
            'favorites_n' => (int)$run['favorites_n'],
            'visits_n' => (int)$run['visits_n'],
            'insights_n' => (int)$run['insights_n'],
            'notes' => array_values(array_filter(preg_split("/\n+/", (string)($run['notes'] ?? '')) ?: [])),
        ] : null,
        'kpis' => $board['kpis'],
        'insights' => $board['insights'],
        'thresholds' => astThresholds(),
    ];
}

if ($action === 'board') {
    astEnsureSchema($pdo);
    $board = astLoadBoard($pdo);
    if (!$board['run']) {
        try {
            $board = astRefresh($pdo);
        } catch (Throwable $e) {
            melkinoAdminJson(['success' => false, 'message' => 'ساخت تحلیل انجام نشد.'], 500);
        }
    }
    melkinoAdminJson(astBoardPayload($board));
}

if ($method === 'POST' && $action === 'refresh') {
    try {
        $board = astRefresh($pdo);
        melkinoAdminJson(astBoardPayload($board) + ['message' => 'تحلیل از دیتابیس ملکینو تازه شد.']);
    } catch (Throwable $e) {
        melkinoAdminJson(['success' => false, 'message' => 'تحلیل ذخیره نشد.'], 500);
    }
}

if ($action === 'history') {
    melkinoAdminJson(['success' => true, 'rows' => astChatHistory($pdo, 50)]);
}

if ($method === 'POST' && ($action === 'chat' || $action === 'ask')) {
    $message = trim((string)($body['message'] ?? $body['q'] ?? ''));
    $board = astLoadBoard($pdo);
    if (!$board['run']) {
        $board = astRefresh($pdo);
    }
    if ($message !== '') {
        astSaveChat($pdo, 'admin', $message);
    }
    $ans = astChatAnswer($board, $message);
    astSaveChat($pdo, 'assistant', (string)$ans['reply']);
    melkinoAdminJson([
        'success' => true,
        'reply' => $ans['reply'],
        'insights' => $ans['insights'],
        'filter' => $ans['filter'],
        'kpis' => $board['kpis'],
        'run' => $board['run'] ? $board['run']['finished_at'] : null,
    ]);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
