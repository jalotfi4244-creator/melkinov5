<?php
/**
 * API استودیو طراحی — draft / publish / history / import
 * بدون جدول جدید؛ فقط settings.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db-settings.php';
require_once __DIR__ . '/design-studio-lib.php';

melkinoRequireAdminJson();

global $pdo;
if (!($pdo instanceof PDO)) {
    melkinoAdminJson(['success' => false, 'message' => 'اتصال پایگاه‌داده برقرار نیست.'], 500);
}

$adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string) ($_GET['action'] ?? '');
$body = $method === 'GET' ? [] : melkinoAdminJsonBody();
if ($action === '' && isset($body['action'])) {
    $action = (string) $body['action'];
}
if ($action === '') {
    $action = $method === 'GET' ? 'get' : 'save_draft';
}

function melkinoStudioLoad(PDO $pdo, string $key, $fallback)
{
    try {
        $v = dbSettingGet($pdo, 'theme', $key, $fallback);
        return $v;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function melkinoStudioPut(PDO $pdo, string $key, $value, ?int $adminId): bool
{
    try {
        return (bool) dbSettingSet($pdo, 'theme', $key, $value, 'json', $adminId);
    } catch (Throwable $e) {
        return false;
    }
}

function melkinoStudioApplyPublic(PDO $pdo, array $theme, ?int $adminId): bool
{
    $theme = melkinoStudioSanitize($theme);
    melkinoEnsureSettingsTable($pdo);
    $pdo->beginTransaction();
    try {
    if (!melkinoStudioPut($pdo, 'studio_draft', $theme, $adminId)) {
        throw new RuntimeException('draft write');
    }
    if (!melkinoStudioPut($pdo, 'studio_published', $theme, $adminId)) {
        throw new RuntimeException('publish write');
    }
    $bases = [];
    foreach (melkinoStudioLayouts() as $k => $meta) {
        $bases[$k] = $meta['base'];
    }
    $layout = $theme['layout'];
    $legacy = $bases[$layout] ?? 'photo-top';
    try {
        dbSettingSet($pdo, 'global', 'card_layout', $legacy, 'string', $adminId);
        dbSettingSet($pdo, 'global', 'studio_layout', $layout, 'string', $adminId);
    } catch (Throwable $e) {
    }
    $pdo->commit();return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return false; }
}

// All writes require POST, including legacy action-in-query callers.
melkinoRequirePostFor(['save_draft','publish','restore','reset_theme','import','preview'], $action);
header('Cache-Control: private, no-store');
if ($action === 'preview') {
    $theme=melkinoStudioSanitize($body['theme']??[]);
    $result=['success'=>true,'theme'=>$theme,'css'=>melkinoStudioCss($theme)];
    if (!empty($body['gallery'])) {
        $result['gallery']=[];
        foreach (melkinoStudioLayouts() as $id=>$meta) {
            $candidate=$theme;$candidate['layout']=$id;
            $result['gallery'][$id]=['theme'=>$candidate,'css'=>melkinoStudioCss($candidate)];
        }
    }
    melkinoAdminJson($result);
}

$published = melkinoStudioLoad($pdo, 'studio_published', null);
$published = is_array($published) ? melkinoStudioSanitize($published) : null;
$rawDraft = melkinoStudioLoad($pdo, 'studio_draft', null);
$draft = melkinoStudioSanitize(is_array($rawDraft) ? $rawDraft : ($published ?? ['layout'=>dbSettingGet($pdo,'global','card_layout','photo-top')]));
$history = melkinoStudioLoad($pdo, 'studio_history', []);
if (!is_array($history)) {
    $history = [];
}

if ($action === 'get') {
    melkinoAdminJson([
        'success' => true,
        'draft' => $draft,
        'published' => $published,
        'history' => array_slice($history, 0, 12),
        'layouts' => melkinoStudioLayouts(),
        'contrast_warn' => melkinoStudioContrastWarn($draft),
        'css' => melkinoStudioCss($draft),
    ]);
}

if ($action === 'save_draft') {
    $theme = melkinoStudioSanitize($body['theme'] ?? $body);
    if (!melkinoStudioApplyPublic($pdo, $theme, $adminId)) {
        melkinoAdminJson(['success' => false, 'message' => 'ذخیره انجام نشد.'], 500);
    }
    melkinoAdminJson([
        'success' => true,
        'message' => 'ذخیره شد و روی کارت‌های سایت اعمال شد. خانه را یک‌بار تازه کنید.',
        'draft' => $theme,
        'published' => $theme,
        'contrast_warn' => melkinoStudioContrastWarn($theme),
        'css' => melkinoStudioCss($theme),
    ]);
}

if ($action === 'publish') {
    $theme = melkinoStudioSanitize($body['theme'] ?? $draft);
    $theme['name'] = trim((string) ($body['name'] ?? $theme['name']));
    if ($theme['name'] === '') {
        $theme['name'] = 'Melkino Theme';
    }
    if (!melkinoStudioApplyPublic($pdo, $theme, $adminId)) {
        melkinoAdminJson(['success' => false, 'message' => 'انتشار انجام نشد.'], 500);
    }
    $snap = [
        'id' => 'th-' . date('YmdHis'),
        'name' => $theme['name'],
        'at' => date('c'),
        'layout' => $theme['layout'],
        'theme' => $theme,
    ];
    array_unshift($history, $snap);
    $history = array_slice($history, 0, 12);
    melkinoStudioPut($pdo, 'studio_history', $history, $adminId);

    melkinoAdminJson([
        'success' => true,
        'message' => 'تم روی کارت‌های سایت اعمال شد.',
        'published' => $theme,
        'history' => $history,
        'css' => melkinoStudioCss($theme),
    ]);
}

if ($action === 'restore') {
    $id = (string) ($body['id'] ?? '');
    $found = null;
    foreach ($history as $h) {
        if (is_array($h) && (string) ($h['id'] ?? '') === $id) {
            $found = $h;
            break;
        }
    }
    if (!$found || !isset($found['theme'])) {
        melkinoAdminJson(['success' => false, 'message' => 'نسخه پیدا نشد.'], 404);
    }
    $theme = melkinoStudioSanitize($found['theme']);
    melkinoStudioPut($pdo, 'studio_draft', $theme, $adminId);
    melkinoAdminJson(['success' => true, 'message' => 'نسخه بازیابی شد (پیش‌نویس). برای اعمال روی سایت منتشر کنید.', 'draft' => $theme]);
}

if ($action === 'reset_theme') {
    $theme = melkinoStudioDefaultTheme();
    melkinoStudioPut($pdo, 'studio_draft', $theme, $adminId);
    melkinoAdminJson(['success' => true, 'draft' => $theme, 'message' => 'تم به پیش‌فرض برگشت (پیش‌نویس).']);
}

if ($action === 'import') {
    $incoming = $body['theme'] ?? $body['json'] ?? null;
    if (is_string($incoming)) {
        $incoming = json_decode($incoming, true);
    }
    if (!is_array($incoming)) {
        melkinoAdminJson(['success' => false, 'message' => 'فایل تم نامعتبر است.'], 400);
    }
    if (isset($incoming['theme']) && is_array($incoming['theme'])) {
        $incoming = $incoming['theme'];
    }
    $theme = melkinoStudioSanitize($incoming);
    melkinoStudioPut($pdo, 'studio_draft', $theme, $adminId);
    melkinoAdminJson(['success' => true, 'draft' => $theme, 'message' => 'تم وارد شد. پیش‌نویس است تا منتشر شود.']);
}

if ($action === 'export') {
    $src = (string) ($body['which'] ?? $_GET['which'] ?? 'draft');
    $theme = $src === 'published' && $published ? $published : $draft;
    melkinoAdminJson([
        'success' => true,
        'filename' => 'melkino-theme-' . preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($theme['name'])) . '.json',
        'theme' => $theme,
    ]);
}

melkinoAdminJson(['success' => false, 'message' => 'عملیات ناشناخته.'], 400);
