<?php
/**
 * Public browsing switch — follows the site-lock switch (table site_lock, row id=1).
 *
 *  - Lock ON (locked=1), or no row, or DB error: every program page keeps the login wall.
 *  - Lock OFF (row exists with locked=0): the read-only public pages below are open to guests.
 *    Guests on those pages get the public view: no phone, contact, or exact address;
 *    prices are shown (see MELKINO_PUBLIC_GUEST in shared-property.php).
 *
 * Fails closed: any doubt keeps the login wall.
 */
if (!function_exists('melkinoPublicBrowsePages')) {
    function melkinoPublicBrowsePages(): array
    {
        return ['properties.php', 'properties-data.php', 'vip.php', 'search.php', 'search-results.php', 'property-details.php'];
    }

    function melkinoPublicBrowseAllowed(): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        // Fail closed, and never cache a failure: the gate can run before $pdo exists.
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!($pdo instanceof PDO)) return false;
        try {
            $st = $pdo->query('SELECT locked FROM site_lock WHERE id = 1 LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $cache = is_array($row) && isset($row['locked']) && (int)$row['locked'] === 0;
            return $cache;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** True when the current request is a GET/HEAD of one of the public pages. */
    function melkinoPublicBrowseRequest(): bool
    {
        if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) return false;
        $page = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
        return in_array($page, melkinoPublicBrowsePages(), true);
    }

    /** Guest may browse this request without login (public switch ON and public page). */
    function melkinoPublicBrowseGuest(): bool
    {
        return melkinoPublicBrowseRequest() && melkinoPublicBrowseAllowed();
    }

    /** Session check only (no DB): is the viewer signed in? */
    function melkinoViewerLoggedIn(): bool
    {
        foreach (['user_id', 'melkino_user_id', 'reg_telegram_id', 'reg_bale_id', 'reg_eitaa_id', 'user_phone', 'is_admin'] as $key) {
            if (!empty($_SESSION[$key])) return true;
        }
        return false;
    }

    /** Remove phone-like digit runs (7+ digits, Latin or Persian) and @handles from free text. */
    function melkinoPublicScrubContact(string $s): string
    {
        $s = preg_replace('/[0-9۰-۹٠-٩]{7,}/u', '', $s) ?? $s;
        $s = preg_replace('/@[A-Za-z0-9_]{3,}/u', '', $s) ?? $s;
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Public view of one ad row for guests: private contact/location columns are blanked
     * (keys kept so the page shape does not change), and free text is scrubbed of phone
     * numbers and @handles. Prices are NOT changed.
     */
    function melkinoPublicAdPublicView(array $ad): array
    {
        foreach (['phone', 'phone2', 'last_name', 'address', 'telegram_id', 'telegram_link', 'telegram_channel_id',
                  'created_by_telegram_id', 'user_id', 'owner_user_id', 'latitude', 'longitude'] as $key) {
            if (array_key_exists($key, $ad)) $ad[$key] = '';
        }
        $textKeys = ['title', 'description', 'custom_fields', 'property_details', 'tags', 'loan_notes', 'deed_notes',
                     'payment_terms', 'exchange_with', 'visit_hours', 'water_share', 'well_name'];
        $scrub = static function ($v) use (&$scrub) {
            if (is_string($v)) return melkinoPublicScrubContact($v);
            if (is_array($v)) {
                foreach ($v as $k => $x) $v[$k] = $scrub($x);
            }
            return $v;
        };
        foreach ($textKeys as $key) {
            if (array_key_exists($key, $ad)) $ad[$key] = $scrub($ad[$key]);
        }
        return $ad;
    }
}
