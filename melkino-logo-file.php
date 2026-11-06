<?php
/**
 * سرو لوگوی فعلی بدون کش استاتیک.
 * slot=site | site_dark | first
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/melkino-logo.php';

$slot = (string) ($_GET['slot'] ?? 'site');
if (!in_array($slot, ['site', 'site_dark', 'first'], true)) {
    $slot = 'site';
}

$rel = function_exists('melkinoLogoNewestRel') ? melkinoLogoNewestRel($slot) : '';
$full = ($rel !== '') ? (__DIR__ . '/' . $rel) : '';
if ($rel === '' || !is_file($full)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'logo not found';
    exit;
}

$ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
$mime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
][$ext] ?? '';
if ($mime === '') {
    $fi = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $fi ? (string) finfo_file($fi, $full) : 'application/octet-stream';
    if ($fi) {
        finfo_close($fi);
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($full)) . ' GMT');
readfile($full);
exit;
