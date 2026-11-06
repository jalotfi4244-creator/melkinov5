<?php
declare(strict_types=1);
require_once __DIR__ . '/program/brand-site-lib.php';
const PROGRAM_ENTRY = '/program/home.php';
const BASE_URL = '';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
function mkBrandPublic(): array {
    static $data;
    if ($data === null) {
        try { $data = mkBrandRead(); }
        catch (Throwable $e) { error_log('[brand] Cannot read content; showing defaults'); $data = mkBrandDefaults(); }
    }
    return $data;
}
function all_settings(): array { return mkBrandPublic()['settings']; }
function setting(string $key, string $fallback = ''): string { return all_settings()[$key] ?? $fallback; }
function features(): array { return array_values(array_filter(mkBrandPublic()['features'], static fn($r) => !empty($r['is_active']))); }
function social_kind_label(string $kind): string
{
    return [
        'bot'     => 'ربات',
        'channel' => 'کانال',
        'page'    => 'صفحه',
        'support' => 'پشتیبانی',
    ][$kind] ?? 'صفحه';
}

/** عنوان گروه‌بندی بخش شبکه‌های اجتماعی */
function social_groups(): array
{
    return [
        'bot'     => ['title' => 'ربات‌های رسمی', 'note' => 'خدمات و اطلاعات ملکینو در پیام‌رسان‌ها'],
        'channel' => ['title' => 'کانال‌های رسمی', 'note' => 'اطلاعیه‌ها، محتوا و اخبار برند'],
        'page'    => ['title' => 'صفحه‌های اجتماعی', 'note' => 'حضور ملکینو در شبکه‌های اجتماعی'],
    ];
}

/** شناسایی پلتفرم برای نمایش آیکون اختصاصی (بله و ایتا آیکون آماده در Font Awesome ندارند) */
function platform_of(array $s): string
{
    $hay = mb_strtolower(($s['network'] ?? '') . ' ' . ($s['icon'] ?? '') . ' ' . ($s['url'] ?? ''));
    if (preg_match('/bale|ball|بله|ble\.ir/u', $hay)) {
        return 'bale';
    }
    if (preg_match('/eitaa|eita|ایتا|eitaa\.com/u', $hay)) {
        return 'eitaa';
    }
    return '';
}

/** آیکون SVG تک‌رنگ برای بله و ایتا (با currentColor رنگ می‌گیرد) */
function platform_svg(string $platform): string
{
    if ($platform === 'bale') {
        return '<svg class="picon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.4a8.5 8.5 0 0 1-12.6 7.5L4 20.4l1.5-4A8.5 8.5 0 1 1 20.5 11.4z"/><path d="M8.7 11.7l2.3 2.3 4.5-4.6"/></svg>';
    }
    if ($platform === 'eitaa') {
        return '<svg class="picon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.6"/><path d="M8.3 12.1h7.4a3.7 3.7 0 1 0-1.2 2.8"/></svg>';
    }
    return '';
}

/**
 * خروجی آیکون یک شبکه (SVG اختصاصی یا کلاس Font Awesome)
 */
function social_icon_html(array $s, string $fallback = 'fa-solid fa-link'): string
{
    $p = platform_of($s);
    if ($p !== '') {
        return platform_svg($p);
    }
    return '<i class="' . e(icon_class((string)($s['icon'] ?? ''), $fallback)) . '"></i>';
}

/** مقادیر پیش‌فرض شبکه‌ها، ربات‌ها و کانال‌ها */
function social_links(string $filter = 'all'): array
{
    $cache = array_values(array_filter(mkBrandPublic()['links'], static fn($r) => !empty($r['is_active']) && ($r['url'] ?? '') !== ''));
    $by = static function (string $key) use ($cache): array {
        return array_values(array_filter($cache, static fn($s) => ($s[$key] ?? '') == 1));
    };

    switch ($filter) {
        case 'header':
            return $by('is_header');
        case 'quick':
            return $by('in_quick');
        case 'bot':
        case 'channel':
        case 'page':
        case 'support':
            return array_values(array_filter($cache, static fn($s) => ($s['kind'] ?? 'page') === $filter));
        case 'others':
            return array_values(array_filter($cache, static fn($s) => !in_array($s['kind'] ?? 'page', ['bot', 'channel'], true) && ($s['network'] ?? '') !== 'اینستاگرام'));
    }

    return $cache;
}

function e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function u(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '#';
    }
    if (preg_match('#^(https?|tel|mailto|wa\.me):#i', $path) || $path[0] === '#' || $path[0] === '/') {
        return $path;
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

/** لینک تنها در صورتی معتبر است که پروتکل مجاز داشته باشد. */
function link_is_safe(string $url): bool
{
    return clean_url($url) !== '#' || trim($url) === '' || trim($url) === '#';
}
function clean_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return '#';
    if ($url[0] === '#') return $url;
    if ($url[0] === '/') {
        // A root-relative application link is not an external hostname.
        if (str_starts_with($url, '//') || preg_match('/%(?:2f|5c|0[ad])/i', $url) || str_contains($url, '..')) return '#';
        return $url;
    }
    if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) $url = 'https://' . $url;
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['https','http','tel','mailto'], true) || isset($p['user']) || isset($p['pass'])) return '#';
    if (in_array(strtolower($p['scheme']), ['https','http'], true) && empty($p['host'])) return '#';
    return $url;
}

/** تبدیل ارقام لاتین به فارسی برای نمایش */
function fa_num(string $s): string
{
    return strtr($s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

/** پاراگراف‌بندی متن سادهٔ ورودی پنل */
function paragraphs(string $text): string
{
    $parts = preg_split('/\R{2,}/u', trim($text));
    $out = '';
    foreach ((array)$parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out .= '<p>' . nl2br(e($p)) . '</p>';
        }
    }
    return $out;
}

/** آخرین واژۀ عنوان را با تأکید طلایی برمی‌گرداند (خروجی امن و آمادهٔ چاپ) */
function title_em(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') {
        return '';
    }
    $parts = explode(' ', $title);
    if (count($parts) < 2) {
        return '<em>' . e($title) . '</em>';
    }
    $last = array_pop($parts);
    return e(implode(' ', $parts)) . ' <em>' . e($last) . '</em>';
}

function lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', trim($text)) as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = $l;
        }
    }
    return $out;
}

function icon_class(string $icon, string $default = 'fa-solid fa-gem'): string
{
    $icon = trim($icon);
    if ($icon === '') {
        return $default;
    }
    return preg_match('/^fa-(solid|brands|regular|light|thin)\s/', $icon) ? $icon : 'fa-solid ' . $icon;
}

/* ---------- ۷. CSRF ---------- */


/* ---------- ۸. SEO: canonical + دادهٔ ساختاریافته ---------- */

/** نشانی قانونی (canonical) صفحهٔ جاری بدون کوئری‌استرینگ — برای تگ canonical و og:url. */
function mkCanonicalUrl(string $path = '/'): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        $host = 'melkino.infinityfree.me';
    }
    $base = ($https ? 'https' : 'http') . '://' . $host . '/';
    return $base . ltrim($path, '/');
}

/**
 * ساعات کاری ساخت‌یافته (schema.org) از متن تنظیمات «hours».
 * فقط الگوی رایج «شنبه تا چهارشنبه H:MM تا H:MM · پنجشنبه ...» خوانده می‌شود؛
 * در صورت ناخوانا بودن متن، خروجی خالی است تا دادهٔ نادرست تولید نشود.
 */
function mkOpeningHoursSpec(string $hours): array
{
    $h = strtr($hours, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9', '٫'=>'.',
    ]);
    $spec = [];
    $mk = static function (array $days, string $from, string $to): array {
        return [[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => array_map(static fn($d) => 'https://schema.org/' . $d, $days),
            'opens' => $from,
            'closes' => $to,
        ]];
    };
    if (preg_match('/شنبه\s*تا\s*چهارشنبه\s*(\d{1,2}):(\d{2})\s*تا\s*(\d{1,2}):(\d{2})/u', $h, $m)) {
        $spec = array_merge($spec, $mk(
            ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday'],
            sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
            sprintf('%02d:%02d', (int)$m[3], (int)$m[4])
        ));
    }
    if (preg_match('/پنجشنبه\s*(\d{1,2}):(\d{2})\s*تا\s*(\d{1,2}):(\d{2})/u', $h, $m)) {
        $spec = array_merge($spec, $mk(['Thursday'],
            sprintf('%02d:%02d', (int)$m[1], (int)$m[2]),
            sprintf('%02d:%02d', (int)$m[3], (int)$m[4])
        ));
    }
    return $spec;
}

/**
 * دادهٔ ساختاریافتهٔ RealEstateAgent برای موتورهای جست‌وجو.
 * همهٔ مقادیر از تنظیمات سایت خوانده می‌شوند؛ داده‌ای ساخته نمی‌شود.
 */
function mkJsonLd(array $S, ?array $officePoint, array $socials): string
{
    $digits = static fn(string $v): string => strtr($v, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
    $tel = trim($digits((string)($S['mobile'] ?? '')));
    if ($tel === '') {
        $tel = trim($digits((string)($S['phone'] ?? '')));
    }
    $tel = preg_replace('/[\s\x{200c}\x{2013}\x{2014}-]+/u', '', $tel);
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'RealEstateAgent',
        'name' => (string)($S['brand_name'] ?? 'ملکینو'),
        'url' => mkCanonicalUrl(),
    ];
    $img = (string)($S['logo_path'] ?? '') !== '' ? (string)$S['logo_path'] : (string)($S['hero_image'] ?? '');
    if ($img !== '') {
        $data['image'] = u($img);
    }
    if ($tel !== '') {
        $data['telephone'] = $tel;
    }
    if (trim((string)($S['email'] ?? '')) !== '') {
        $data['email'] = trim((string)$S['email']);
    }
    if (trim((string)($S['address'] ?? '')) !== '') {
        $data['address'] = ['@type' => 'PostalAddress', 'streetAddress' => trim((string)$S['address'])];
    }
    if ($officePoint !== null) {
        $data['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => (float)$officePoint['lat'],
            'longitude' => (float)$officePoint['lng'],
        ];
    }
    $hours = mkOpeningHoursSpec((string)($S['hours'] ?? ''));
    if ($hours !== []) {
        $data['openingHoursSpecification'] = $hours;
    }
    $sameAs = [];
    foreach ($socials as $s) {
        $uu = clean_url((string)($s['url'] ?? ''));
        if ($uu !== '#' && preg_match('#^https?://#i', $uu)) {
            $sameAs[] = $uu;
        }
    }
    foreach (['instagram_url', 'telegram_url', 'whatsapp_url', 'youtube_url', 'tg_bot_url', 'bale_bot_url', 'eitaa_bot_url', 'tg_channel_url', 'bale_channel_url', 'eitaa_channel_url'] as $key) {
        $uu = clean_url((string)($S[$key] ?? ''));
        if ($uu !== '#' && preg_match('#^https?://#i', $uu)) {
            $sameAs[] = $uu;
        }
    }
    if ($sameAs !== []) {
        $data['sameAs'] = array_values(array_unique($sameAs));
    }
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
