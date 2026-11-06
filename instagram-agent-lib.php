<?php
/**
 * Melkino Instagram Agent — Service Layer
 * ------------------------------------------------
 * منطق کسب‌وکار از UI/API جداست. قواعد:
 *  - داده فقط از جدول ads/images سایت (منبع واقعی) — بدون کپی/جدول موازی آگهی
 *  - تولید محتوا: قالب‌محور (رایگان) + ارائه‌دهندهٔ AI اختیاری پشت Interface
 *  - انتشار: Interface + پیاده‌سازی Meta Graph API + Mock برای تست (بدون تماس زنده)
 *  - هیچ Secret در HTML/JSON/لاگ؛ توکن‌ها فقط در storage محرمانه
 */
declare(strict_types=1);

require_once __DIR__ . '/instagram-agent-schema.php';

/* ============================================================
   سازگاری PHP (هاست‌هایی که هنوز PHP 7.x دارند — توابع PHP 8)
============================================================ */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/* ============================================================
   وضعیت‌های محتوا (ماشین حالت — §۱۰ و §۲۱)
============================================================ */
const IG_STATUSES = [
    'draft', 'pending_review', 'approved', 'scheduled', 'publishing',
    'published', 'failed', 'cancelled', 'needs_attention', 'published_manual',
];
const IG_TRANSITIONS = [
    'draft' => ['pending_review', 'cancelled', 'needs_attention'],
    'pending_review' => ['approved', 'draft', 'cancelled', 'needs_attention'],
    'approved' => ['scheduled', 'publishing', 'cancelled', 'needs_attention', 'draft', 'published_manual'],
    'scheduled' => ['publishing', 'cancelled', 'needs_attention', 'approved', 'published_manual'],
    'publishing' => ['published', 'failed', 'needs_attention'],
    'failed' => ['publishing', 'approved', 'cancelled', 'needs_attention', 'draft'],
    'needs_attention' => ['draft', 'pending_review', 'approved', 'cancelled'],
    'published' => ['needs_attention'],
    'published_manual' => ['needs_attention'],
    'cancelled' => ['draft'],
];

if (!function_exists('melkinoIgCanTransition')) {
    function melkinoIgCanTransition(string $from, string $to): bool
    {
        return in_array($to, IG_TRANSITIONS[$from] ?? [], true);
    }
}

/* ============================================================
   Secret Store — program/storage/instagram-agent/secrets.json
   (storage/ در بسته نمی‌رود و در هاست حفظ می‌شود؛ خروجی HTML ندارد)
============================================================ */
if (!function_exists('melkinoIgSecretsPath')) {
    function melkinoIgSecretsPath(): string
    {
        $dir = __DIR__ . '/storage/instagram-agent';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        return $dir . '/secrets.json';
    }
}

if (!function_exists('melkinoIgSecretsRead')) {
    /** @return array<string,string> */
    function melkinoIgSecretsRead(): array
    {
        $path = melkinoIgSecretsPath();
        $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        // ثابت‌های config.secrets.php اولویت دارند (تغییر کد بدون نوشتن فایل)
        foreach ([
            'ig_app_id' => 'INSTAGRAM_APP_ID',
            'ig_app_secret' => 'INSTAGRAM_APP_SECRET',
            'ig_access_token' => 'INSTAGRAM_ACCESS_TOKEN',
            'ig_user_id' => 'INSTAGRAM_IG_USER_ID',
            'ai_api_key' => 'AI_API_KEY',
            'ai_base_url' => 'AI_BASE_URL',
            'ai_model' => 'AI_MODEL',
        ] as $k => $c) {
            if (defined($c) && (string)constant($c) !== '') {
                $data[$k] = (string)constant($c);
            }
        }
        return $data;
    }
}

if (!function_exists('melkinoIgSecretsWrite')) {
    function melkinoIgSecretsWrite(array $patch): void
    {
        $path = melkinoIgSecretsPath();
        $cur = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
        if (!is_array($cur)) {
            $cur = [];
        }
        foreach ($patch as $k => $v) {
            $k = (string)$k;
            if (!preg_match('/^[a-z0-9_]+$/', $k)) {
                continue;
            }
            $v = trim((string)$v);
            if ($v === '') {
                unset($cur[$k]); // خالی = حذف
            } else {
                $cur[$k] = $v;
            }
        }
        @file_put_contents($path, json_encode($cur, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($path, 0600);
    }
}

if (!function_exists('melkinoIgMaskSecret')) {
    function melkinoIgMaskSecret(string $v): string
    {
        if ($v === '') {
            return '';
        }
        if (mb_strlen($v) <= 6) {
            return '••••••';
        }
        return '••••' . mb_substr($v, -4);
    }
}

/* ============================================================
   Settings — جدول غیرمحرمانه (کلید/مقدار)
============================================================ */
if (!function_exists('melkinoIgSettingGet')) {
    function melkinoIgSettingGet(PDO $pdo, string $key, string $fallback = ''): string
    {
        if (!isset($GLOBALS['__melkinoIgSettingCache'])) {
            melkinoIgEnsureSchema($pdo);
            $GLOBALS['__melkinoIgSettingCache'] = [];
            foreach ($pdo->query('SELECT setting_key, setting_value FROM instagram_agent_settings') as $r) {
                $GLOBALS['__melkinoIgSettingCache'][(string)$r['setting_key']] = (string)$r['setting_value'];
            }
        }
        return $GLOBALS['__melkinoIgSettingCache'][$key] ?? $fallback;
    }
}

if (!function_exists('melkinoIgSettingSet')) {
    function melkinoIgSettingSet(PDO $pdo, string $key, string $value, string $actor = ''): void
    {
        melkinoIgEnsureSchema($pdo);
        $st = $pdo->prepare(
            'INSERT INTO instagram_agent_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $st->execute([$key, $value, $actor]);
        if (isset($GLOBALS['__melkinoIgSettingCache'])) {
            $GLOBALS['__melkinoIgSettingCache'][$key] = $value; // باطل‌سازی کش
        }
    }
}

/* ============================================================
   Audit
============================================================ */
if (!function_exists('melkinoIgAudit')) {
    function melkinoIgAudit(PDO $pdo, string $action, string $entityType = '', string $entityId = '', string $detail = '', string $actor = ''): void
    {
        try {
            melkinoIgEnsureSchema($pdo);
            $st = $pdo->prepare(
                'INSERT INTO instagram_agent_audit_logs (action, entity_type, entity_id, detail, actor)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$action, $entityType, mb_substr($entityId, 0, 64), mb_substr($detail, 0, 1200), mb_substr($actor, 0, 120)]);
        } catch (Throwable $e) {
            /* گزارش‌گیری هرگز مسیر اصلی را نمی‌شکند */
        }
    }
}

/* ============================================================
   PropertySourceService — خواندن آگهی‌های واقعی سایت
============================================================ */
if (!function_exists('melkinoIgAdColumns')) {
    function melkinoIgAdColumns(): string
    {
        return 'a.id, a.title, a.status, a.transaction_type, a.property_type, a.location, a.address,
                a.area, a.built_area, a.land_area, a.rooms, a.floor, a.year,
                a.price_sell, a.price_condition, a.deposit, a.rent_monthly, a.full_rent_enabled, a.full_rent,
                a.total_price, a.display_price, a.price_hidden, a.description, a.publish_photos,
                a.is_vip, a.tags, a.property_details, a.created_at';
    }
}

if (!function_exists('melkinoIgGetAd')) {
    /** یک آگهی از منبع واقعی؛ null یعنی وجود ندارد. */
    function melkinoIgGetAd(PDO $pdo, string $adId): ?array
    {
        $st = $pdo->prepare('SELECT ' . melkinoIgAdColumns() . ' FROM ads a WHERE a.id = ? LIMIT 1');
        $st->execute([$adId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('melkinoIgAdImages')) {
    /** تصاویر مجاز انتشار عمومی آگهی (همان قواعد صفحات عمومی). */
    function melkinoIgAdImages(PDO $pdo, string $adId): array
    {
        $st = $pdo->prepare(
            "SELECT filename FROM images
             WHERE ad_id = ? AND is_selected = 1 AND publish_publicly = 1
             ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $st->execute([$adId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $name = str_replace('\\', '/', (string)$name);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }
}

if (!function_exists('melkinoIgAdEligibility')) {
    /**
     * ارزیابی شفاف شایستگی انتشار — طبق قواعد §۴ (فقط وضعیت‌های واقعی، نه حدس متنی).
     * قاعدهٔ «نوشتنی»: نبودِ تصویر مجاز یا غیرفعال بودن انتشار تصاویر، آگهی را رد نمی‌کند؛
     * محتوا فقط-متن (بدون تصویر) تولید می‌شود و text_only=true برمی‌گردد.
     * @return array{eligible:bool,score:int,reasons:list<string>,warnings:list<string>,text_only:bool}
     */
    function melkinoIgAdEligibility(PDO $pdo, array $ad): array
    {
        $reasons = [];
        $warnings = [];
        $score = 0;

        $status = (string)($ad['status'] ?? '');
        if ($status !== 'published') {
            $reasons[] = 'آگهی در وضعیت منتشرشدهٔ سایت نیست (' . $status . ').';
        } else {
            $score += 30;
        }

        $hasImg = count(melkinoIgAdImages($pdo, (string)$ad['id'])) > 0;
        $textOnly = false;
        if (($ad['publish_photos'] ?? 'yes') !== 'yes') {
            $textOnly = true;
            $warnings[] = 'انتشار تصاویر این آگهی غیرفعال است؛ محتوا فقط-متن (بدون تصویر) تولید می‌شود.';
        } elseif (!$hasImg) {
            $textOnly = true;
            $warnings[] = 'آگهی تصویر مجاز برای انتشار عمومی ندارد؛ محتوا فقط-متن تولید می‌شود. '
                . 'برای انتشار همراه تصویر، در مدیریت تصاویر آگهی گزینهٔ «انتشار عمومی» را فعال کنید.';
        } else {
            $score += 25;
        }

        $title = trim((string)($ad['title'] ?? ''));
        if ($title === '') {
            $reasons[] = 'عنوان آگهی خالی است.';
        } else {
            $score += 10;
        }

        $priceLine = function_exists('melkinoPublicAdPrice') ? (string)melkinoPublicAdPrice($ad) : trim((string)($ad['display_price'] ?? ''));
        if ($priceLine !== '' && !empty($ad['price_hidden'])) {
            $warnings[] = 'قیمت این آگهی مخفی است؛ در محتوا قیمت درج نمی‌شود.';
        } elseif ($priceLine !== '') {
            $score += 15;
        } else {
            $warnings[] = 'قیمت ثبت‌نشده؛ محتوا بدون قیمت تولید می‌شود.';
        }

        if (trim((string)($ad['location'] ?? '')) !== '') {
            $score += 10;
        }
        if (trim((string)($ad['description'] ?? '')) !== '') {
            $score += 10;
        }

        return [
            'eligible' => count($reasons) === 0,
            'score' => $score,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'text_only' => $textOnly,
        ];
    }
}

if (!function_exists('melkinoIgSourceHash')) {
    /** اثرانگشت داده‌های آگهی برای تشخیص تغییر بعد از تولید محتوا (§۱۳). */
    function melkinoIgSourceHash(array $ad, array $images): string
    {
        $keys = ['id', 'title', 'status', 'transaction_type', 'property_type', 'location', 'area', 'rooms',
            'price_sell', 'deposit', 'rent_monthly', 'full_rent', 'total_price', 'display_price', 'price_hidden', 'description'];
        $snap = [];
        foreach ($keys as $k) {
            $snap[$k] = (string)($ad[$k] ?? '');
        }
        $snap['__images'] = $images;
        return hash('sha256', json_encode($snap, JSON_UNESCAPED_UNICODE) ?: '');
    }
}

if (!function_exists('melkinoIgListPublishable')) {
    /** آگهی‌های منتشرشدهٔ سایت برای انتخاب — با امتیاز شفاف. */
    function melkinoIgListPublishable(PDO $pdo, array $filters = [], int $limit = 40, int $offset = 0): array
    {
        $where = "a.status = 'published'";
        $params = [];
        if (!empty($filters['q'])) {
            $where .= ' AND (a.title LIKE :q1 OR a.id LIKE :q2 OR a.location LIKE :q3)';
            $params[':q1'] = '%' . $filters['q'] . '%';
            $params[':q2'] = '%' . $filters['q'] . '%';
            $params[':q3'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['property_type'])) {
            $where .= ' AND a.property_type = :pt';
            $params[':pt'] = (string)$filters['property_type'];
        }
        if (!empty($filters['transaction_type'])) {
            $where .= ' AND a.transaction_type = :tt';
            $params[':tt'] = (string)$filters['transaction_type'];
        }
        if (!empty($filters['location'])) {
            $where .= ' AND a.location LIKE :loc';
            $params[':loc'] = '%' . $filters['location'] . '%';
        }
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $st = $pdo->prepare(
            'SELECT ' . melkinoIgAdColumns() . " FROM ads a WHERE {$where}
             ORDER BY COALESCE(a.created_at, '1970-01-01') DESC, a.id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $elig = melkinoIgAdEligibility($pdo, $r);
            $r['eligibility'] = $elig;
            $r['images'] = melkinoIgAdImages($pdo, (string)$r['id']);
            $out[] = $r;
        }
        return $out;
    }
}

/* ============================================================
   MediaAccessService — بررسی URL عمومی رسانه برای متا (§۱۲)
============================================================ */
if (!function_exists('melkinoIgPublicBaseUrl')) {
    function melkinoIgPublicBaseUrl(): string
    {
        $fromSetting = '';
        try {
            $fromSetting = melkinoIgSettingGet($GLOBALS['pdo'] ?? null, 'public_base_url', '');
        } catch (Throwable $e) {
        }
        if ($fromSetting !== '') {
            return rtrim($fromSetting, '/');
        }
        return rtrim(mkCanonicalUrlPublic(), '/');
    }
}

if (!function_exists('mkCanonicalUrlPublic')) {
    function mkCanonicalUrlPublic(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = 'melkino.infinityfree.me';
        }
        return ($https ? 'https' : 'http') . '://' . $host . '/';
    }
}

if (!function_exists('melkinoIgMediaPublicUrl')) {
    /**
     * آدرس عمومی فایل تصویر آگهی برای متا.
     * null = در دسترس عمومی قابل‌اتکا نیست → انتشار API ممکن نیست و باید دستی انجام شود.
     */
    function melkinoIgMediaPublicUrl(string $filename): ?string
    {
        $filename = str_replace('\\', '/', $filename);
        $clean = ltrim($filename, '/');
        if ($clean === '' || str_contains($clean, '..') || str_contains($clean, "\0")) {
            return null;
        }
        $root = realpath(__DIR__ . '/uploads');
        $real = realpath(__DIR__ . '/' . $clean);
        if (!$root || !$real || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }
        return melkinoIgPublicBaseUrl() . '/program/' . $clean;
    }
}

if (!function_exists('melkinoIgMediaFirstUrl')) {
    /**
     * نخستین آدرس عمومی رسانه از media_json (شکل فهرستی [{file,public_url}] تولید فعلی،
     * یا شکل قدیمی شیء {public_url}) — سازگار با هر دو.
     */
    function melkinoIgMediaFirstUrl(string $mediaJson): string
    {
        $media = json_decode($mediaJson !== '' ? $mediaJson : '[]', true);
        if (!is_array($media)) {
            return '';
        }
        // شکل قدیمی: شیء با کلید public_url
        if (isset($media['public_url']) && is_string($media['public_url'])) {
            return $media['public_url'];
        }
        // شکل فعلی: فهرستی از اقلام رسانه
        foreach ($media as $item) {
            if (is_array($item)) {
                $u = (string)($item['public_url'] ?? '');
                if ($u !== '') {
                    return $u;
                }
            } elseif (is_string($item) && $item !== '') {
                return $item;
            }
        }
        return '';
    }
}

if (!function_exists('melkinoIgContentApiCapable')) {
    /**
     * آیا این محتوا با API رسمی (پست تک‌تصویری) قابل انتشار است؟
     * کاروسل/ریلز/استوری و محتوای فقط-متن در نسخهٔ فعلی فقط انتشار دستی دارند.
     */
    function melkinoIgContentApiCapable(string $contentType, string $mediaJson): bool
    {
        return $contentType === 'post' && melkinoIgMediaFirstUrl($mediaJson) !== '';
    }
}

if (!function_exists('melkinoIgMediaProbe')) {
    /**
     * بررسی دسترس‌پذیری از بیرون (اختیاری؛ آهسته). فقط وقتی صدا زده شود که لازم است.
     * @return array{ok:bool,status:int,note:string}
     */
    function melkinoIgMediaProbe(string $url, int $timeout = 8): array
    {
        if (!preg_match('#^https://#i', $url)) {
            return ['ok' => false, 'status' => 0, 'note' => 'آدرس رسانه باید https عمومی باشد.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'facebookexternalhit/1.1',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 400) {
            return ['ok' => true, 'status' => $code, 'note' => 'در دسترس'];
        }
        return ['ok' => false, 'status' => $code, 'note' => 'رسانه از بیرون در دسترس نیست (کد ' . $code . ').'];
    }
}

/* ============================================================
   CaptionGeneratorService — قالب‌محور + Interface برای AI
============================================================ */
interface MelkinoIgAiProvider
{
    public function name(): string;
    /** @return string|null متن تولیدشده یا null (شکست → بازگشت به قالب) */
    public function generate(array $ad, string $kind, array $parts, array $opts): ?string;
}

class MelkinoIgTemplateProvider implements MelkinoIgAiProvider
{
    public function name(): string
    {
        return 'template';
    }

    public function generate(array $ad, string $kind, array $parts, array $opts): ?string
    {
        return null; // قالب‌محور در melkinoIgBuildContent انجام می‌شود
    }
}

class MelkinoIgHttpAiProvider implements MelkinoIgAiProvider
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(string $baseUrl, string $apiKey, string $model)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function name(): string
    {
        return 'ai:' . $this->model;
    }

    public function generate(array $ad, string $kind, array $parts, array $opts): ?string
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            return null;
        }
        $prompt = "یک کپشن فارسی طبیعی و حرفه‌ای برای آگهی املاک در شاهرود بنویس. فقط از اطلاعات داده‌شده استفاده کن؛ هیچ قیمت یا ویژگی نساز.\n"
            . 'داده: ' . json_encode($parts, JSON_UNESCAPED_UNICODE) . "\n"
            . 'نوع محتوا: ' . $kind . '؛ لحن: ' . ($opts['tone'] ?? 'professional') . "\n"
            . 'خروجی: فقط متن کپشن. حداکثر ۹۰۰ کاراکتر.';
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 600,
            'temperature' => 0.6,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($res)) {
            return null;
        }
        $j = json_decode($res, true);
        $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
        return $text !== '' ? mb_substr($text, 0, 4000) : null;
    }
}

if (!function_exists('melkinoIgFormatPrice')) {
    /**
     * قیمت خام آگهی را با قاعدهٔ انتشار سایت نمایش می‌دهد: عدد → جداکننده + «تومان».
     * «ودیعه | اجاره» جدا قالب‌بندی می‌شود و متن آزاد (مثلاً «۱۸ میلیارد») دست‌نخورده می‌ماند.
     */
    function melkinoIgFormatPrice(string $raw): string
    {
        $out = [];
        foreach (array_map('trim', explode('|', $raw)) as $p) {
            if ($p === '') {
                continue;
            }
            $n = strtr($p, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٬' => '', '،' => '', ',' => '', ' ' => '', 'تومان' => '',
            ]);
            if ($n !== '' && is_numeric($n) && (float)$n > 0) {
                $out[] = number_format((float)$n, 0, '.', ',') . ' تومان';
            } else {
                $out[] = $p;
            }
        }
        return implode(' | ', $out);
    }
}

if (!function_exists('melkinoIgAdFacts')) {
    /**
     * «حقایق» مجاز آگهی برای تولید محتوا — هر مقدار مستقیماً از دادهٔ سایت.
     * فیلد خالی = حذف سطر؛ هرگز جایگزین ساختگی.
     */
    function melkinoIgAdFacts(array $ad, string $publicUrl = ''): array
    {
        global $pdo;
        $facts = [];
        $facts['کد ملک'] = trim((string)($ad['id'] ?? ''));
        $facts['عنوان'] = trim((string)($ad['title'] ?? ''));
        $facts['نوع معامله'] = trim((string)($ad['transaction_type'] ?? ''));
        $facts['نوع ملک'] = trim((string)($ad['property_type'] ?? ''));
        $facts['موقعیت'] = trim((string)($ad['location'] ?? ''));
        $area = trim((string)($ad['area'] ?? $ad['built_area'] ?? ''));
        if ($area !== '' && $area !== '0') {
            $facts['متراژ'] = $area . ' متر';
        }
        $rooms = trim((string)($ad['rooms'] ?? ''));
        if ($rooms !== '' && $rooms !== '-' && $rooms !== '0') {
            $facts['اتاق'] = $rooms . ' خواب';
        }
        $price = '';
        try {
            $price = function_exists('melkinoPublicAdPrice') ? trim((string)melkinoPublicAdPrice($ad)) : trim((string)($ad['display_price'] ?? ''));
        } catch (Throwable $e) {
            $price = trim((string)($ad['display_price'] ?? ''));
        }
        if ($price !== '' && empty($ad['price_hidden'])) {
            $facts['قیمت'] = melkinoIgFormatPrice($price);
        }
        if (trim((string)($ad['description'] ?? '')) !== '') {
            $facts['توضیحات'] = mb_substr(trim((string)$ad['description']), 0, 600);
        }
        if ($publicUrl !== '') {
            $facts['لینک آگهی'] = $publicUrl;
        }
        return array_filter($facts, static fn ($v) => (string)$v !== '');
    }
}

if (!function_exists('melkinoIgBuildHashtags')) {
    function melkinoIgBuildHashtags(array $ad): string
    {
        $tags = ['#ملکینو', '#ملکینو_شاهرود', '#املاک_شاهرود'];
        $tx = (string)($ad['transaction_type'] ?? '');
        if (str_contains($tx, 'فروش')) {
            $tags[] = '#فروش_ملک';
        }
        if (str_contains($tx, 'اجاره') || str_contains($tx, 'رهن')) {
            $tags[] = '#رهن_اجاره';
        }
        $pt = trim((string)($ad['property_type'] ?? ''));
        if ($pt !== '') {
            $tags[] = '#' . str_replace(' ', '_', $pt);
        }
        $loc = trim((string)($ad['location'] ?? ''));
        if ($loc !== '') {
            $tags[] = '#' . str_replace(' ', '_', $loc);
        }
        return implode(' ', array_slice(array_unique($tags), 0, 8));
    }
}

if (!function_exists('melkinoIgBuildContent')) {
    /**
     * ساخت اجزای محتوا (§۷/§۸) — قالب‌محور + AI اختیاری.
     * @return array آرایهٔ اجزای محتوا؛ 'ad_facts' حقایق استفاده‌شده را نگه می‌دارد
     */
    function melkinoIgBuildContent(array $ad, string $kind = 'post', array $opts = [], ?MelkinoIgAiProvider $ai = null): array
    {
        $facts = melkinoIgAdFacts($ad, (string)($opts['public_url'] ?? ''));
        $tone = (string)($opts['tone'] ?? 'professional');
        $toneLine = [
            'professional' => 'به‌صورت حرفه‌ای و مطمئن',
            'friendly' => 'صمیمی و صمیمانه',
            'luxury' => 'شیک و لوکس',
        ][$tone] ?? 'حرفه‌ای';

        $title = ($facts['عنوان'] ?? 'ملک در شاهرود');
        $tx = $facts['نوع معامله'] ?? '';
        $hook = $title . ' ✨';
        if (($facts['موقعیت'] ?? '') !== '') {
            $hook = $title . ' در ' . $facts['موقعیت'] . ' ✨';
        }

        $lines = [];
        foreach (['نوع معامله', 'نوع ملک', 'موقعیت', 'متراژ', 'اتاق', 'قیمت'] as $k) {
            if (($facts[$k] ?? '') !== '') {
                $lines[] = '• ' . $k . ': ' . $facts[$k];
            }
        }
        $body = $hook . "\n\n" . implode("\n", $lines);
        if (($facts['توضیحات'] ?? '') !== '') {
            $body .= "\n\n" . mb_substr($facts['توضیحات'], 0, 400);
        }

        $cta = 'برای جزئیات بیشتر و هماهنگی بازدید، با ملکینو شاهرود تماس بگیرید.';
        if (($facts['لینک آگهی'] ?? '') !== '') {
            $cta = 'آگهی کامل را در سایت ملکینو ببینید: ' . $facts['لینک آگهی'];
        }

        $hashtags = melkinoIgBuildHashtags($ad);
        $caption = $body . "\n\n" . $cta . "\n\n" . $hashtags;

        /* AI اختیاری — فقط کپشن را بازنویسی می‌کند؛ حقایق دست‌نخورده */
        $aiUsed = 'template';
        if ($ai instanceof MelkinoIgAiProvider && !($ai instanceof MelkinoIgTemplateProvider)) {
            $gen = $ai->generate($ad, $kind, $facts, ['tone' => $tone]);
            if (is_string($gen) && trim($gen) !== '') {
                $caption = trim($gen);
                $aiUsed = $ai->name();
            }
        }

        $scenario = '';
        $onImage = '';
        $alt = $title . ' — تصویر آگهی ' . ($facts['کد ملک'] ?? '');
        switch ($kind) {
            case 'reels':
                $scenario = "صحنه ۱: نمای بیرونی ملک (۳ ثانیه)\nصحنه ۲: فضای اصلی (۴ ثانیه)\nصحنه ۳: ویژگی‌های شاخص (۴ ثانیه)\nصحنه ۴: متن تماس و لوگوی ملکینو (۳ ثانیه)";
                $onImage = $title;
                break;
            case 'carousel':
                $scenario = "اسلاید ۱: تصویر شاخص + عنوان\nاسلاید ۲: مشخصات کلیدی\nاسلاید ۳: امکانات و موقعیت\nاسلاید ۴: قیمت و تماس";
                $onImage = $title;
                break;
            case 'story':
                $caption = $hook . "\n" . ($facts['قیمت'] ?? '') . "\n" . $cta;
                $onImage = mb_substr($title, 0, 60);
                break;
        }

        return [
            'title' => $title,
            'hook' => $hook,
            'body_text' => $body,
            'caption' => $caption,
            'cta' => $cta,
            'hashtags' => $hashtags,
            'on_image_text' => $onImage,
            'video_scenario' => $scenario,
            'alt_text' => $alt,
            'claims_check' => json_encode(array_values($facts), JSON_UNESCAPED_UNICODE),
            'tone' => $tone,
            'audience' => (string)($opts['audience'] ?? 'buyers'),
            'generator' => $aiUsed,
            'ad_facts' => $facts,
        ];
    }
}

/* ============================================================
   InstagramPublisherService — Interface + Meta + Mock
============================================================ */
interface MelkinoIgPublisher
{
    public function label(): string;
    public function isConfigured(): bool;
    /** @return array{ok:bool,media_id?:string,permalink?:string,error_code?:string,error?:string} */
    public function publishPhoto(string $imageUrl, string $caption): array;
}

class MelkinoIgMockPublisher implements MelkinoIgPublisher
{
    public array $calls = [];
    public bool $failNext = false;

    public function label(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function publishPhoto(string $imageUrl, string $caption): array
    {
        $this->calls[] = ['url' => $imageUrl, 'caption' => $caption];
        if ($this->failNext) {
            $this->failNext = false;
            return ['ok' => false, 'error_code' => 'mock_fail', 'error' => 'خطای شبیه‌سازی‌شده'];
        }
        return ['ok' => true, 'media_id' => 'mock_' . (count($this->calls)), 'permalink' => 'https://instagram.com/p/mock' . count($this->calls)];
    }
}

class MelkinoIgMetaPublisher implements MelkinoIgPublisher
{
    private array $secrets;
    private string $graphVersion;

    public function __construct(array $secrets, string $graphVersion = 'v21.0')
    {
        $this->secrets = $secrets;
        $this->graphVersion = $graphVersion;
    }

    public function label(): string
    {
        return 'meta-graph';
    }

    public function isConfigured(): bool
    {
        return ($this->secrets['ig_access_token'] ?? '') !== '' && ($this->secrets['ig_user_id'] ?? '') !== '';
    }

    private function graph(string $path, array $params, string $method = 'POST'): array
    {
        $url = 'https://graph.facebook.com/' . $this->graphVersion . '/' . ltrim($path, '/');
        $params['access_token'] = (string)($this->secrets['ig_access_token'] ?? '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'MelkinoIgAgent/1.0',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        }
        $res = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($res, true);
        return ['http' => $http, 'json' => is_array($j) ? $j : [], 'raw' => $res];
    }

    public function publishPhoto(string $imageUrl, string $caption): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error_code' => 'not_configured', 'error' => 'اتصال اینستاگرام تنظیم نشده است.'];
        }
        $uid = (string)$this->secrets['ig_user_id'];
        $step1 = $this->graph($uid . '/media', [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ]);
        $creationId = (string)($step1['json']['id'] ?? '');
        if ($step1['http'] < 200 || $step1['http'] >= 300 || $creationId === '') {
            return [
                'ok' => false,
                'error_code' => (string)($step1['json']['error']['code'] ?? 'http_' . $step1['http']),
                'error' => 'ساخت رسانه در اینستاگرام ناموفق بود.',
                'http_status' => $step1['http'],
            ];
        }
        $step2 = $this->graph($uid . '/media_publish', ['creation_id' => $creationId]);
        $mediaId = (string)($step2['json']['id'] ?? '');
        if ($step2['http'] < 200 || $step2['http'] >= 300 || $mediaId === '') {
            return [
                'ok' => false,
                'error_code' => (string)($step2['json']['error']['code'] ?? 'http_' . $step2['http']),
                'error' => 'انتشار رسانه در اینستاگرام ناموفق بود.',
                'http_status' => $step2['http'],
            ];
        }
        return ['ok' => true, 'media_id' => $mediaId, 'permalink' => ''];
    }
}

/* ============================================================
   PublicationQueueService — صف، جلوگیری از تکرار، Retry محدود
============================================================ */
if (!function_exists('melkinoIgQueueEnqueue')) {
    /**
     * ثبت تلاش انتشار با request_id یکتا (جلوگیری از انتشار تکراری — §۵/§۱۰).
     * @return array{created:bool,publication_id:int}
     */
    function melkinoIgQueueEnqueue(PDO $pdo, int $contentId, string $adId, string $mode, string $requestId = ''): array
    {
        melkinoIgEnsureSchema($pdo);
        if ($requestId === '') {
            $requestId = 'ig_' . $contentId . '_' . substr(hash('sha256', $adId . '|' . $mode . '|' . microtime(true) . '|' . random_bytes(8)), 0, 24);
        }
        try {
            $st = $pdo->prepare(
                'INSERT INTO instagram_agent_publications (request_id, content_id, ad_id, mode, status)
                 VALUES (?, ?, ?, ?, \'pending\')'
            );
            $st->execute([$requestId, $contentId, $adId, $mode]);
            return ['created' => true, 'publication_id' => (int)$pdo->lastInsertId(), 'request_id' => $requestId];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $st = $pdo->prepare('SELECT id FROM instagram_agent_publications WHERE request_id = ?');
                $st->execute([$requestId]);
                return ['created' => false, 'publication_id' => (int)$st->fetchColumn(), 'request_id' => $requestId];
            }
            throw $e;
        }
    }
}

if (!function_exists('melkinoIgSetContentStatus')) {
    /** تغییر وضعیت با اعتبارسنجی ماشین حالت. */
    function melkinoIgSetContentStatus(PDO $pdo, int $contentId, string $to, array $fields = [], string $actor = ''): bool
    {
        melkinoIgEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT id, status, ad_id FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$contentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $from = (string)$row['status'];
        if ($from !== $to && !melkinoIgCanTransition($from, $to)) {
            return false;
        }
        $sets = ['status = :st', 'updated_at = NOW()'];
        $params = [':st' => $to, ':id' => $contentId];
        foreach (['caption', 'title', 'hook', 'body_text', 'cta', 'hashtags', 'review_note', 'manual_link', 'scheduled_at', 'published_at'] as $k) {
            if (array_key_exists($k, $fields)) {
                $sets[] = "{$k} = :{$k}";
                $params[':' . $k] = $fields[$k];
            }
        }
        // تأیید = پذیرش وضعیت فعلی آگهی → اثرانگشت منبع تازه می‌شود
        if ($to === 'approved') {
            $adNow = melkinoIgGetAd($pdo, (string)$row['ad_id']);
            if ($adNow !== null) {
                $params[':source_hash'] = melkinoIgSourceHash($adNow, melkinoIgAdImages($pdo, (string)$row['ad_id']));
                $sets[] = 'source_hash = :source_hash';
            }
        }
        $pdo->prepare('UPDATE instagram_agent_contents SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        melkinoIgAudit($pdo, 'content_status_' . $to, 'content', (string)$contentId, 'from:' . $from, $actor);
        return true;
    }
}

if (!function_exists('melkinoIgRegisterManualPublish')) {
    /** §۲۱ — ثبت انتشار دستی با لینک پست؛ تفکیک کامل از انتشار API. */
    function melkinoIgRegisterManualPublish(PDO $pdo, int $contentId, string $link, string $actor = ''): bool
    {
        melkinoIgEnsureSchema($pdo);
        if (!melkinoIgSetContentStatus($pdo, $contentId, 'published_manual', ['manual_link' => $link], $actor)) {
            return false;
        }
        $st = $pdo->prepare('SELECT ad_id FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$contentId]);
        $adId = (string)$st->fetchColumn();
        $pdo->prepare(
            "INSERT INTO instagram_agent_publications (request_id, content_id, ad_id, mode, status, ig_permalink, finished_at)
             VALUES (?, ?, ?, 'manual', 'published', ?, NOW())"
        )->execute(['manual_' . $contentId . '_' . substr(hash('sha256', $link . microtime(true) . random_bytes(4)), 0, 16), $contentId, $adId, $link]);
        melkinoIgAudit($pdo, 'publish_manual', 'content', (string)$contentId, 'link:' . $link, $actor);
        return true;
    }
}

if (!function_exists('melkinoIgRevalidateContent')) {
    /**
     * §۱۳ — بازبینی آگهی قبل از انتشار؛ در صورت تغییر/حذف → needs_attention.
     * @return array{ok:bool,note:string}
     */
    function melkinoIgRevalidateContent(PDO $pdo, int $contentId): array
    {
        melkinoIgEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT * FROM instagram_agent_contents WHERE id = ?');
        $st->execute([$contentId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($c)) {
            return ['ok' => false, 'note' => 'محتوا یافت نشد.'];
        }
        $ad = melkinoIgGetAd($pdo, (string)$c['ad_id']);
        if ($ad === null) {
            melkinoIgSetContentStatus($pdo, $contentId, 'needs_attention', ['review_note' => 'آگهی اصلی از سایت حذف شده است.'], 'system');
            return ['ok' => false, 'note' => 'آگهی اصلی از سایت حذف شده است.'];
        }
        if ((string)$ad['status'] !== 'published') {
            melkinoIgSetContentStatus($pdo, $contentId, 'needs_attention', ['review_note' => 'آگهی دیگر در سایت منتشر نیست.'], 'system');
            return ['ok' => false, 'note' => 'آگهی دیگر در وضعیت منتشرشده نیست.'];
        }
        $images = melkinoIgAdImages($pdo, (string)$ad['id']);
        // فقط محتوایی که هنگام تولید رسانه داشته، وابسته به تصویر است؛
        // محتوای فقط-متن از ابتدا بدون تصویر ساخته شده و نبود تصویر برایش خطا نیست.
        $cMedia = json_decode((string)($c['media_json'] ?? '[]'), true);
        // خروجی‌های استودیو (studio_export_id) به تصویر آگهی وابسته نیستند و در این بررسی نادیده گرفته می‌شوند
        $hadMedia = false;
        if (is_array($cMedia)) {
            foreach ($cMedia as $mi) {
                if (!(is_array($mi) && isset($mi['studio_export_id']))) {
                    $hadMedia = true;
                }
            }
        }
        if ($hadMedia && $images === []) {
            melkinoIgSetContentStatus($pdo, $contentId, 'needs_attention', ['review_note' => 'تصویر معتبر آگهی دیگر موجود نیست.'], 'system');
            return ['ok' => false, 'note' => 'تصویر معتبری برای انتشار باقی نمانده است.'];
        }
        $hash = melkinoIgSourceHash($ad, $images);
        if ((string)($c['source_hash'] ?? '') !== '' && $hash !== (string)$c['source_hash']) {
            melkinoIgSetContentStatus($pdo, $contentId, 'needs_attention', ['review_note' => 'اطلاعات آگهی پس از تولید محتوا تغییر کرده است؛ بازبینی مدیر لازم است.'], 'system');
            return ['ok' => false, 'note' => 'داده‌های آگهی تغییر کرده و نیاز به بازبینی دارد.'];
        }
        return ['ok' => true, 'note' => 'تأیید شد'];
    }
}

if (!function_exists('melkinoIgRunDuePublications')) {
    /**
     * پردازش صف انتشار (فقط موارد approved با scheduled_at رسیده یا فوری).
     * @return array{processed:int,published:int,failed:int,skipped:int}
     */
    function melkinoIgRunDuePublications(PDO $pdo, ?MelkinoIgPublisher $publisher = null, int $max = 5): array
    {
        melkinoIgEnsureSchema($pdo);
        $auto = melkinoIgSettingGet($pdo, 'auto_publish', 'off');
        $out = ['processed' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0];
        if ($auto !== 'on') {
            return $out; // انتشار خودکار پیش‌فرض خاموش (§۱۰)
        }
        if ($publisher === null) {
            $secrets = melkinoIgSecretsRead();
            $publisher = new MelkinoIgMetaPublisher($secrets);
        }
        if (!$publisher->isConfigured()) {
            return $out;
        }

        $st = $pdo->prepare(
            "SELECT id, content_id, ad_id, attempt_count, max_attempts, payload_sha256
             FROM instagram_agent_publications
             WHERE status = 'pending' AND mode = 'api' AND attempt_count < max_attempts
             ORDER BY id ASC LIMIT " . max(1, min(20, $max))
        );
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pub) {
            $out['processed']++;
            $cid = (int)$pub['content_id'];
            $check = melkinoIgRevalidateContent($pdo, $cid);
            if (!$check['ok']) {
                $out['skipped']++;
                $pdo->prepare("UPDATE instagram_agent_publications SET status='failed', last_error_friendly=?, attempt_count=attempt_count+1, finished_at=NOW() WHERE id=?")
                    ->execute([$check['note'], (int)$pub['id']]);
                continue;
            }
            $cs = $pdo->prepare('SELECT caption, media_json, status, content_type FROM instagram_agent_contents WHERE id=?');
            $cs->execute([$cid]);
            $content = $cs->fetch(PDO::FETCH_ASSOC);
            if (!is_array($content) || !in_array((string)$content['status'], ['approved', 'scheduled', 'publishing'], true)) {
                $out['skipped']++;
                continue; // هرگز بدون تأیید منتشر نمی‌شود
            }
            $imageUrl = melkinoIgMediaFirstUrl((string)($content['media_json'] ?? ''));
            if ((string)($content['content_type'] ?? 'post') !== 'post') {
                $out['failed']++;
                $pdo->prepare("UPDATE instagram_agent_publications SET status='failed', last_error_code='manual_only', last_error_friendly=?, attempt_count=attempt_count+1, finished_at=NOW() WHERE id=?")
                    ->execute(['انتشار API در نسخهٔ فعلی فقط برای پست تک‌تصویری است؛ کاروسل/ریلز/استوری را دستی منتشر کنید.', (int)$pub['id']]);
                continue;
            }
            if ($imageUrl === '') {
                $out['failed']++;
                $pdo->prepare("UPDATE instagram_agent_publications SET status='failed', last_error_code='no_media', last_error_friendly=?, attempt_count=attempt_count+1, finished_at=NOW() WHERE id=?")
                    ->execute(['رسانهٔ عمومی معتبر برای انتشار تعیین نشده است.', (int)$pub['id']]);
                continue;
            }

            melkinoIgSetContentStatus($pdo, $cid, 'publishing', [], 'system');
            $pdo->prepare("UPDATE instagram_agent_publications SET status='publishing', started_at=NOW(), attempt_count=attempt_count+1 WHERE id=?")
                ->execute([(int)$pub['id']]);

            $res = $publisher->publishPhoto($imageUrl, (string)$content['caption']);
            if (!empty($res['ok'])) {
                $pdo->prepare("UPDATE instagram_agent_publications SET status='published', ig_media_id=?, ig_permalink=?, finished_at=NOW() WHERE id=?")
                    ->execute([(string)($res['media_id'] ?? ''), (string)($res['permalink'] ?? ''), (int)$pub['id']]);
                melkinoIgSetContentStatus($pdo, $cid, 'published', ['published_at' => date('Y-m-d H:i:s')], 'system');
                $out['published']++;
            } else {
                $pdo->prepare("UPDATE instagram_agent_publications SET status='failed', last_error_code=?, last_error_friendly=?, last_http_status=?, finished_at=NOW() WHERE id=?")
                    ->execute([
                        (string)($res['error_code'] ?? 'unknown'),
                        mb_substr((string)($res['error'] ?? 'انتشار ناموفق بود.'), 0, 500),
                        (int)($res['http_status'] ?? 0),
                        (int)$pub['id'],
                    ]);
                melkinoIgSetContentStatus($pdo, $cid, 'failed', [], 'system');
                $out['failed']++;
            }
        }
        return $out;
    }
}

/* ============================================================
   InstagramInsightsService — فقط دادهٔ واقعی؛ بدون آمار ساختگی
============================================================ */
if (!function_exists('melkinoIgInsightsFor')) {
    /**
     * @return array{available:bool,source:string,fetched_at:?string,metrics:array}
     */
    function melkinoIgInsightsFor(PDO $pdo, string $mediaId): array
    {
        $secrets = melkinoIgSecretsRead();
        if ($mediaId === '' || ($secrets['ig_access_token'] ?? '') === '') {
            return ['available' => false, 'source' => 'none', 'fetched_at' => null, 'metrics' => []];
        }
        $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($mediaId) . '/metrics?metric=impressions,reach,engagement&access_token=' . rawurlencode((string)$secrets['ig_access_token']));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $res = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = json_decode($res, true);
        if ($code < 200 || $code >= 300 || !is_array($j)) {
            return ['available' => false, 'source' => 'meta-graph', 'fetched_at' => date('c'), 'metrics' => []];
        }
        $metrics = [];
        foreach (($j['data'] ?? []) as $m) {
            $metrics[(string)($m['name'] ?? '')] = (string)($m['values'][0]['value'] ?? '');
        }
        return ['available' => $metrics !== [], 'source' => 'meta-graph', 'fetched_at' => date('c'), 'metrics' => $metrics];
    }
}
