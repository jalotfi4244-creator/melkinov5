<?php
declare(strict_types=1);
/** Shared browser icon renderer. No session, DB, auth or output-buffer side effects. */
if (!function_exists('mkBrandIconTags')) {
    function mkBrandIconTags(): string {
        static $html = null;
        if ($html !== null) return $html;
        $html = '';
        try {
            if (!is_file(__DIR__ . '/brand-site-lib.php')) return '';
            require_once __DIR__ . '/brand-site-lib.php';
            $brand = mkBrandRead();
            $settings = $brand['settings'];
            // The explicit browser icon wins; the uploaded logo is a useful fallback.
            $value = $settings['favicon_path'] ?? '';
            if (!is_string($value)) return '';
            $url = trim($value);
            if ($url === '') {
                $value = $settings['logo_path'] ?? '';
                if (!is_string($value)) return '';
                $url = trim($value);
            }
            if ($url === '') return '';
            $url = mkBrandUrl($url, true);
            // Version only same-origin paths. Do not change signed/external image URLs.
            if (str_starts_with($url, '/')) {
                $parts = explode('#', $url, 2);
                $url = $parts[0] . (str_contains($parts[0], '?') ? '&' : '?') . 'mkicon=' . substr(hash('sha256', $url . '|' . ($brand['revision'] ?? 'initial')), 0, 12);
                if (isset($parts[1])) $url .= '#' . $parts[1];
            }
            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<link rel="icon" href="' . $escaped . '">' . "\n"
                . '<link rel="apple-touch-icon" href="' . $escaped . '">' . "\n";
        } catch (Throwable $e) {
            // An unavailable/corrupt brand setting must not interrupt program pages.
            $html = '';
        }
        return $html;
    }
}
return mkBrandIconTags();
