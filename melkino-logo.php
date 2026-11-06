<?php
require_once __DIR__ . '/config.php';

if (!function_exists('melkinoLogoStripQuery')) {
    function melkinoLogoStripQuery(string $url): string
    {
        $url = str_replace('\\', '/', ltrim($url, '/\\'));
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;
        if ($url === '' || strpos($url, '..') !== false) {
            return '';
        }
        return $url;
    }
}

if (!function_exists('melkinoLogoCandidates')) {
    function melkinoLogoCandidates(string $slot): array
    {
        global $pdo;
        $out = [];
        $data = [];
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            try {
                $data = dbSettingGet($pdo, 'branding', 'logos', []);
            } catch (Throwable $e) {
                $data = [];
            }
            if (!is_array($data)) {
                $data = [];
            }
        }
        if ($slot === 'site_dark') {
            // لوگوی تیره: فقط فایل‌های dark؛ هرگز با لوگوی روشن مخلوط نمی‌شود
            $u = melkinoLogoStripQuery((string) ($data['primary_logo_dark']['url'] ?? ''));
            if ($u !== '') {
                $out[] = $u;
            }
            foreach (glob(__DIR__ . '/uploads/onboarding-logo-dark.*') ?: [] as $f) {
                $out[] = 'uploads/' . basename($f);
            }
            foreach (glob(__DIR__ . '/uploads/branding/site-logo-dark-*.*') ?: [] as $f) {
                $out[] = 'uploads/branding/' . basename($f);
            }
        } elseif ($slot === 'first') {
            $u = melkinoLogoStripQuery((string) ($data['onboarding_first']['url'] ?? ''));
            if ($u !== '') {
                $out[] = $u;
            }
            foreach (glob(__DIR__ . '/uploads/onboarding-first-logo.*') ?: [] as $f) {
                $out[] = 'uploads/' . basename($f);
            }
            foreach (glob(__DIR__ . '/uploads/branding/onboarding-first-*.*') ?: [] as $f) {
                $out[] = 'uploads/branding/' . basename($f);
            }
        } else {
            $u = melkinoLogoStripQuery((string) ($data['primary_logo']['url'] ?? ''));
            if ($u !== '' && strpos(basename($u), 'site-logo-dark-') !== 0) {
                $out[] = $u;
            }
            foreach (glob(__DIR__ . '/uploads/onboarding-logo.*') ?: [] as $f) {
                $out[] = 'uploads/' . basename($f);
            }
            foreach (glob(__DIR__ . '/uploads/branding/site-logo-*.*') ?: [] as $f) {
                if (strpos(basename($f), 'site-logo-dark-') === 0) {
                    continue;
                }
                $out[] = 'uploads/branding/' . basename($f);
            }
            foreach (glob(__DIR__ . '/uploads/branding/onboarding-logo.*') ?: [] as $f) {
                $out[] = 'uploads/branding/' . basename($f);
            }
            $out[] = 'assets/images/melkino-login-logo.png';
        }
        return $out;
    }
}

if (!function_exists('melkinoLogoNewestRel')) {
    /** مسیر نسبیِ تازه‌ترین فایل لوگو روی دیسک (بدون query). */
    function melkinoLogoNewestRel(string $slot = 'site'): string
    {
        $best = '';
        $bestMt = -1;
        $seen = [];
        foreach (melkinoLogoCandidates($slot) as $rel) {
            $rel = melkinoLogoStripQuery((string) $rel);
            if ($rel === '' || isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            $full = __DIR__ . '/' . $rel;
            if (!is_file($full)) {
                continue;
            }
            $mt = (int) @filemtime($full);
            if ($mt >= $bestMt) {
                $bestMt = $mt;
                $best = $rel;
            }
        }
        return $best;
    }
}

if (!function_exists('melkinoLogoPublicUrl')) {
    /**
     * نشانی عمومی لوگو. از PHP سرو می‌شود تا کشِ فایل استاتیک InfinityFree
     * تصویر کهنه را نگه ندارد. v=mtime فقط برای دور زدن کش مرورگر است.
     */
    function melkinoLogoPublicUrl(string $slot = 'site'): string
    {
        $rel = melkinoLogoNewestRel($slot);
        if ($rel === '') {
            return '';
        }
        $mt = (int) @filemtime(__DIR__ . '/' . $rel);
        return 'melkino-logo-file.php?slot=' . rawurlencode($slot) . '&v=' . ($mt ?: time());
    }
}

if (!function_exists('melkinoLogoUrl')) {
    function melkinoLogoUrl(): string
    {
        $url = function_exists('melkinoSiteLogoUrl') ? melkinoSiteLogoUrl() : '';
        return $url !== '' ? $url : 'assets/images/melkino-login-logo.png';
    }
}

if (!function_exists('melkinoSiteLogoUrl')) {
    function melkinoSiteLogoUrl(): string
    {
        return melkinoLogoPublicUrl('site');
    }
}

if (!function_exists('melkinoSiteDarkLogoUrl')) {
    /** لوگوی تیره (برای تم تیره). اگر آپلود نشده باشد، رشتهٔ خالی برمی‌گرداند. */
    function melkinoSiteDarkLogoUrl(): string
    {
        return melkinoLogoPublicUrl('site_dark');
    }
}

if (!function_exists('melkinoOnboardingFirstLogoUrl')) {
    function melkinoOnboardingFirstLogoUrl(): string
    {
        $u = melkinoLogoPublicUrl('first');
        if ($u !== '') {
            return $u;
        }
        return melkinoLogoPublicUrl('site');
    }
}

if (!function_exists('melkinoOnboardingFirstPage')) {
    function melkinoOnboardingFirstPage(): array
    {
        global $pdo;
        $out = [
            'title' => 'به ملکینو خوش آمدید',
            'text' => 'سامانه جامع جستجو و ثبت ملک.',
        ];
        if ($pdo instanceof PDO && function_exists('dbSettingGet')) {
            try {
                $s = dbSettingGet($pdo, 'onboarding', 'first_page', []);
            } catch (Throwable $e) {
                $s = [];
            }
            if (is_array($s)) {
                $t = trim((string) ($s['title'] ?? ''));
                $x = trim((string) ($s['text'] ?? ''));
                if ($t !== '') {
                    $out['title'] = $t;
                }
                if ($x !== '') {
                    $out['text'] = $x;
                }
            }
        }
        return $out;
    }
}
