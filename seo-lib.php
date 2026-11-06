<?php
/**
 * SEO helpers for public pages.
 *
 * Indexing rule: a page is indexable ONLY while public browsing is ON (site lock OFF),
 * it is one of the INDEXABLE pages below, and it is not a shared (?share=1) link.
 * Everything else gets "noindex, nofollow". No content differs between crawlers and
 * people (no cloaking): crawlers see the same public view a guest sees.
 *
 * Privacy: metadata and sitemap use only public fields (type, deal, location, title,
 * description, price). Phone, contact, last name, and exact address are never emitted.
 */

require_once __DIR__ . '/public-browse-lib.php';

if (!function_exists('melkinoSeoIndexablePages')) {
    function melkinoSeoIndexablePages(): array
    {
        return ['properties.php', 'vip.php', 'property-details.php'];
    }

    function melkinoSeoEsc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Plain text, collapsed whitespace, capped length (multibyte safe). */
    function melkinoSeoPlain($value, int $max = 160): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$value)));
        if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
            $s = rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…';
        }
        return $s;
    }

    function melkinoSeoScrubContact(string $s): string
    {
        return melkinoPublicScrubContact($s);
    }

    function melkinoSeoSet(array $seo): void
    {
        $GLOBALS['mkSeo'] = $seo;
    }

    function melkinoSeoIsIndexable(): bool
    {
        if (function_exists('melkinoIsSharedPropertyRequest') && melkinoIsSharedPropertyRequest()) return false;
        if (!function_exists('melkinoPublicBrowseAllowed') || !melkinoPublicBrowseAllowed()) return false;
        $page = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
        return in_array($page, melkinoSeoIndexablePages(), true);
    }

    /** Absolute site URL for a path (same host rules as the root mkCanonicalUrl). */
    function melkinoSeoAbsolute(string $path = ''): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') $host = 'melkino.infinityfree.me';
        return ($https ? 'https' : 'http') . '://' . $host . '/' . ltrim($path, '/');
    }

    /** Absolute URL of a program page as served (the root rewrites /x.php to /program/x.php). */
    function melkinoSeoUrl(string $file, array $query = []): string
    {
        $path = 'program/' . ltrim($file, '/');
        if ($query) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return melkinoSeoAbsolute($path);
    }

    /** Metadata for the listing/VIP pages (base URL only; filters never become canonical). */
    function melkinoSeoListMeta(string $which): array
    {
        if ($which === 'vip') {
            return [
                'title' => 'فایل‌های VIP | ملکینو',
                'description' => 'فایل‌ها و آگهی‌های ویژه ملکینو برای خرید و فروش و اجارهٔ ملک.',
                'canonical' => melkinoSeoUrl('vip.php'),
            ];
        }
        return [
            'title' => 'آگهی‌های خرید، فروش و اجارهٔ ملک | ملکینو',
            'description' => 'مشاهدهٔ آگهی‌های آپارتمان، خانه، زمین و مغازه برای خرید، فروش و اجاره در ملکینو.',
            'canonical' => melkinoSeoUrl('properties.php'),
        ];
    }

    /**
     * Metadata for one ad. $row is the raw ad row; $priceText is the already-public price
     * text (empty when hidden). Never reads phone, last_name, address, or contact columns.
     */
    function melkinoSeoAdMeta(array $row, string $priceText = ''): array
    {
        $type = melkinoSeoPlain($row['property_type'] ?? '', 40);
        $deal = melkinoSeoPlain($row['transaction_type'] ?? '', 40);
        $loc = melkinoSeoPlain($row['location'] ?? '', 60);
        $name = melkinoSeoPlain($row['title'] ?? '', 90);
        $lead = trim($deal . ' ' . $type);
        $headline = $name !== '' ? $name : ($lead !== '' ? $lead : 'آگهی ملک');

        $desc = $lead;
        if ($loc !== '') $desc .= ($desc !== '' ? ' ' : '') . 'در ' . $loc;
        if ($priceText !== '') $desc .= ($desc !== '' ? '. ' : '') . 'قیمت: ' . melkinoSeoPlain($priceText, 60);
        $desc .= ($desc !== '' ? '. ' : '') . melkinoSeoPlain($row['description'] ?? '', 160);
        $desc = melkinoSeoPlain(melkinoSeoScrubContact($desc), 160);

        $id = (string)($row['id'] ?? '');
        $canonical = $id !== '' ? melkinoSeoUrl('property-details.php', ['id' => $id]) : '';
        $meta = [
            'title' => $headline . ' | ملکینو',
            'description' => $desc,
            'canonical' => $canonical,
        ];
        $published = (string)($row['published_at'] ?? $row['created_at'] ?? '');
        $ts = $published !== '' ? strtotime($published) : false;
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'RealEstateListing',
            'name' => $headline,
            'description' => $desc,
            'url' => $canonical,
        ];
        if ($ts !== false) $ld['datePosted'] = date('Y-m-d', $ts);
        $meta['jsonld'] = $ld;
        return $meta;
    }

    /** Head tags for the page (called from header.php). */
    function melkinoSeoHeadHtml(): string
    {
        $seo = is_array($GLOBALS['mkSeo'] ?? null) ? $GLOBALS['mkSeo'] : [];
        $title = (string)($seo['title'] ?? 'ملکینو');
        $desc = (string)($seo['description'] ?? '');
        $canonical = (string)($seo['canonical'] ?? '');
        $robots = melkinoSeoIsIndexable() ? 'index, follow' : 'noindex, nofollow';

        $out = '<title>' . melkinoSeoEsc($title) . "</title>\n";
        $out .= '<meta name="robots" content="' . $robots . '">' . "\n";
        if ($desc !== '') {
            $out .= '<meta name="description" content="' . melkinoSeoEsc($desc) . '">' . "\n";
        }
        if ($canonical !== '' && $robots === 'index, follow') {
            $out .= '<link rel="canonical" href="' . melkinoSeoEsc($canonical) . '">' . "\n";
        }
        if ($robots === 'index, follow') {
            $out .= '<meta property="og:site_name" content="ملکینو">' . "\n";
            $out .= '<meta property="og:locale" content="fa_IR">' . "\n";
            $out .= '<meta property="og:title" content="' . melkinoSeoEsc($title) . '">' . "\n";
            if ($desc !== '') $out .= '<meta property="og:description" content="' . melkinoSeoEsc($desc) . '">' . "\n";
            if ($canonical !== '') $out .= '<meta property="og:url" content="' . melkinoSeoEsc($canonical) . '">' . "\n";
            $out .= '<meta property="og:type" content="website">' . "\n";
        }
        if ($robots === 'index, follow' && is_array($seo['jsonld'] ?? null)) {
            $json = json_encode($seo['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($json)) {
                $out .= '<script type="application/ld+json">' . $json . "</script>\n";
            }
        }
        return $out;
    }

    /** sitemap.xml body. Empty (no URLs) while the site lock is ON. */
    function melkinoSeoSitemapXml(?PDO $pdo): string
    {
        $urls = [];
        if (function_exists('melkinoPublicBrowseAllowed') && melkinoPublicBrowseAllowed() && $pdo instanceof PDO) {
            $urls[] = ['loc' => melkinoSeoAbsolute(''), 'lastmod' => null];
            $urls[] = ['loc' => melkinoSeoUrl('properties.php'), 'lastmod' => null];
            $urls[] = ['loc' => melkinoSeoUrl('vip.php'), 'lastmod' => null];
            try {
                $st = $pdo->query("SELECT id, updated_at, published_at, created_at FROM ads WHERE status='published' ORDER BY updated_at DESC LIMIT 5000");
                foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $id = (string)($r['id'] ?? '');
                    if ($id === '' || !preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $id)) continue;
                    $when = (string)($r['updated_at'] ?? $r['published_at'] ?? $r['created_at'] ?? '');
                    $ts = $when !== '' ? strtotime($when) : false;
                    $urls[] = [
                        'loc' => melkinoSeoUrl('property-details.php', ['id' => $id]),
                        'lastmod' => $ts !== false ? date('c', $ts) : null,
                    ];
                }
            } catch (Throwable $e) {
                // Keep the static part only.
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . melkinoSeoEsc($u['loc']) . '</loc>';
            if (!empty($u['lastmod'])) $xml .= '<lastmod>' . melkinoSeoEsc($u['lastmod']) . '</lastmod>';
            $xml .= "</url>\n";
        }
        return $xml . "</urlset>\n";
    }
}
