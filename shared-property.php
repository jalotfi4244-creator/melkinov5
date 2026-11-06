<?php
declare(strict_types=1);

/** A narrow read-only exception, never a login/session credential. */
function melkinoIsSharedPropertyRequest(): bool
{
    $page = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $id = $_GET['id'] ?? null;
    return $page === 'property-details.php'
        && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
        && ($_GET['share'] ?? null) === '1'
        && is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $id) === 1
        && !isset($_GET['action']) && empty($_POST);
}

function melkinoSharedPropertyLoggedIn(): bool
{
    foreach (['user_id', 'melkino_user_id', 'reg_telegram_id', 'reg_bale_id', 'reg_eitaa_id', 'user_phone', 'is_admin'] as $key) {
        if (!empty($_SESSION[$key])) return true;
    }
    if (function_exists('melkinoCurrentIdentity')) {
        return !empty(melkinoCurrentIdentity()['user_id']);
    }
    return false;
}

/** Public-only lookup: numeric primary keys AND legacy AD-* codes are supported. */
function melkinoFindSharedProperty(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM ads WHERE id = ? AND status = 'published' LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $st = $pdo->prepare("SELECT * FROM ads WHERE ad_code = ? AND status = 'published' LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Guest media must be selected, public and a same-origin upload (never a URL or traversal). */
function melkinoSharedImagePath(string $path): string
{
    $path = ltrim(trim($path), '/');
    if ($path === '' || str_contains($path, '..') || str_contains($path, ':') || str_contains($path, '\\') || strpbrk($path, '%?#') !== false || preg_match('/[\x00-\x1f\x7f]/', $path)) return '';
    return str_starts_with($path, 'uploads/') ? $path : 'uploads/' . $path;
}

function melkinoSharedPropertyGallery(PDO $pdo, string $id, string $title): array
{
    $st = $pdo->prepare('SELECT filename, storage_path FROM images WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1 ORDER BY is_primary DESC, sort_order ASC, id ASC');
    $st->execute([$id]);
    $gallery = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $image) {
        $path = melkinoSharedImagePath((string)($image['storage_path'] ?: $image['filename']));
        if ($path !== '') $gallery[] = ['src' => $path, 'alt' => $title];
    }
    return $gallery;
}

function melkinoSharedPropertyAmenities(PDO $pdo, string $id): array
{
    $st = $pdo->prepare('SELECT am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = ? ORDER BY am.sort_order ASC, am.name ASC');
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Request-local context only. It never authenticates a session or permits other routes. */
function melkinoIsSharedPropertyGuest(): bool
{
    return defined('MELKINO_SHARED_GUEST') && MELKINO_SHARED_GUEST === true;
}

function melkinoPrepareSharedProperty(): void
{
    // Public browsing (site lock OFF): a guest sees the same private-data-free view as a
    // shared link, but keeps prices. Not indexed-by-noindex: this is a normal public page.
    if (!melkinoIsSharedPropertyRequest() && !melkinoSharedPropertyLoggedIn()
        && function_exists('melkinoPublicBrowseGuest') && melkinoPublicBrowseGuest()
        && ($_GET['id'] ?? null) !== null && is_string($_GET['id'])
        && preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $_GET['id']) === 1
        && !isset($_GET['action']) && empty($_POST)) {
        if (!defined('MELKINO_SHARED_GUEST')) define('MELKINO_SHARED_GUEST', true);
        if (!defined('MELKINO_PUBLIC_GUEST')) define('MELKINO_PUBLIC_GUEST', true);
        header('Cache-Control: private, no-store');
        return;
    }
    if (!melkinoIsSharedPropertyRequest() || melkinoSharedPropertyLoggedIn()) return;
    if (!defined('MELKINO_SHARED_GUEST')) define('MELKINO_SHARED_GUEST', true);
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex');
}

function melkinoSharedPropertyLoginUrl(?string $id = null): string
{
    $id = $id ?? (defined('MELKINO_SHARED_PROPERTY_ID') ? MELKINO_SHARED_PROPERTY_ID : (is_string($_GET['id'] ?? null) ? $_GET['id'] : ''));
    $target = 'property-details.php?id=' . rawurlencode($id);
    // Preserve the selected real detail implementation after an explicit login.
    if ($id !== '' && !ctype_digit($id)) $target .= '&legacy=1';
    elseif (($_GET['v2'] ?? '') === '1') $target .= '&v2=1';
    elseif (($_GET['legacy'] ?? '') === '1') $target .= '&legacy=1';
    return 'login.php?redirect=' . rawurlencode($target);
}

/** Compatibility entry: render the ACTUAL detail template, never a simplified copy. */
function melkinoRenderSharedProperty(): void
{
    global $pdo;
    melkinoPrepareSharedProperty();
    if (function_exists('melkinoPublicV2') && melkinoPublicV2()) {
        echo (new \Melkino\Http\Controllers\PropertyDetailController())->render();
    } else {
        require __DIR__ . '/views/legacy/property-details-legacy.php';
    }
}

/** Load only the access prompt; all property styling is owned by the canonical template. */
function melkinoSharedPropertyHead(): void
{
    if (!melkinoIsSharedPropertyGuest()) return;
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $nonce = function_exists('csp_nonce_attr') ? csp_nonce_attr() : '';
    echo '<script' . $nonce . '>window.MELKINO_SHARED_GUEST=true;window.MELKINO_SHARED_LOGIN='
        . json_encode(melkinoSharedPropertyLoginUrl(), $flags) . ';</script>';
    echo '<link rel="stylesheet" href="assets/css/shared-property.css?v=' . (int)filemtime(__DIR__ . '/assets/css/shared-property.css') . '">';
    echo '<script src="assets/js/shared-property.js?v=' . (int)filemtime(__DIR__ . '/assets/js/shared-property.js') . '"></script>';
}
