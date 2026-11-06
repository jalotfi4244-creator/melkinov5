<?php
declare(strict_types=1);
/** URL placement only. Does not alter identity, sessions or access gates. */
function melkinoConfiguredAppUrl(): string
{
    $url = defined('MELKINO_PUBLIC_APP_URL') ? (string)MELKINO_PUBLIC_APP_URL : (string)(getenv('MELKINO_PUBLIC_APP_URL') ?: '');
    $url = rtrim($url, '/');
    if ($url === '') return '';
    $p = parse_url($url);
    if (!is_array($p) || !in_array($p['scheme'] ?? '', ['https','http'], true) || empty($p['host'])
        || isset($p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])
        || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_contains($p['path'] ?? '', '..')) return '';
    return $url;
}
function melkinoAppBasePath(): string
{
    if (defined('MELKINO_APP_BASE_PATH')) return rtrim('/' . trim((string)MELKINO_APP_BASE_PATH, '/'), '/');
    $configured = melkinoConfiguredAppUrl();
    if ($configured !== '') return rtrim((string)(parse_url($configured, PHP_URL_PATH) ?? ''), '/');
    $root = str_replace('\\', '/', __DIR__) . '/';
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_starts_with($file, $root)) {
        $relative = substr($file, strlen($root));
        if ($relative !== '' && str_ends_with($script, '/' . $relative)) {
            return rtrim(substr($script, 0, -strlen($relative)), '/');
        }
    }
    // CLI jobs have no HTTP route; final deployment places the app in /program.
    return basename(__DIR__) === 'program' ? '/program' : '';
}
function melkinoAppUrl(string $path = ''): string
{
    $base = melkinoConfiguredAppUrl();
    if ($base === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/\A(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?\z/i', $host)) return '';
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443 || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https://' : 'http://') . $host . melkinoAppBasePath();
    }
    return rtrim($base, '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
}
/** Strip ONLY this application's deployment prefix. Callers must still validate destinations. */
function melkinoAppRelativeTarget(string $target): string
{
    $base = trim(melkinoAppBasePath(), '/');
    if ($base === '' || str_starts_with($target, '//')) return $target;
    foreach (['/' . $base . '/', $base . '/'] as $prefix) {
        if (str_starts_with($target, $prefix)) return substr($target, strlen($prefix));
    }
    return $target;
}
