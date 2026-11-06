<?php
// ==============================================
// محافظ امنیتی
// ==============================================
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

// =====================================================
// محافظ پاسخ‌های AJAX / JSON
// جلوگیری از ورود Warning / Notice / HTML به JSON
// =====================================================
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_helpers.php'; // برای melkinoJsJson (خروجی JSON ایمن)
require_once __DIR__ . '/security-lib.php';
melkinoEnsureRequestSchema(); // راند ۵۴: اسکیمای درخواست‌ها
require_once __DIR__ . '/visit-request-lib.php';
melkinoEnsureVisitRequestSchema();

// نشست امن ادمین: timeout قابل تنظیم
// قبلاً این مقدار از یک فایل JSON قدیمی (settings/security.json) خونده می‌شد
// که با ذخیره‌ی تنظیمات امنیتی از پنل (save_security_settings.php، که در
// دیتابیس ذخیره می‌کند) هرگز آپدیت نمی‌شد؛ یعنی این سوییچ عملاً بی‌اثر بود.
$securitySettings = [];
if ($pdo instanceof PDO) {
    foreach (['lockout', 'admin_login_log', 'session_timeout', 'force_https'] as $__sk) {
        $securitySettings[$__sk] = dbSettingGet($pdo, 'security', $__sk, true);
    }
}
if (!empty($_SESSION['is_admin']) && !empty($securitySettings['session_timeout'])) {
    $timeoutSeconds = 30 * 60;
    if (!empty($_SESSION['admin_last_activity']) && (time() - (int)$_SESSION['admin_last_activity']) > $timeoutSeconds) {
        session_unset();
        session_destroy();
        header('Location: admin-login.php?timeout=1');
        exit;
    }
}
$_SESSION['admin_last_activity'] = time();

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: admin-login.php');
    exit;
}

// SECFIX(P3-session-lock): UA متفاوت = خروج اجباری به صفحهٔ ورود.
if (function_exists('melkinoAdminFpCheck') && !melkinoAdminFpCheck()) {
    header('Location: admin-login.php?session=invalid');
    exit;
}

// SECFIX(P2-2FA): ادمینِ دارای TOTP ثبت‌شده باید هر نشست یک‌بار کد بزند؛
// ثبت‌نام‌نکرده‌ها بدون تغییر وارد پنل می‌شوند (بدون قفل‌شدن).
if (function_exists('melkinoLegacy2faNeedsCheck') && melkinoLegacy2faNeedsCheck()) {
    header('Location: admin-2fa.php?redirect=' . rawurlencode('admin-panel.php'));
    exit;
}

// فرگمنت آمار (تب «آمار») — فقط با درخواست تب، بعد از احراز هویت.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (string)($_GET['stats_fragment'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    (static function (): void {
        require_once __DIR__ . '/admin-stats-lib.php';
        require_once __DIR__ . '/jalali-lib.php';
        extract(melkinoStatsDashboard($GLOBALS['pdo'] ?? null), EXTR_SKIP);
        require __DIR__ . '/views/admin/tabs/stats.php';
    })();
    exit;
}

/*
 * راند ۲۷: همهٔ درخواست‌های تغییردهندهٔ پنل (POST/PUT/PATCH/DELETE) باید
 * توکن CSRF داشته باشند. تا قبل از این، هندلرهای داخل admin-panel.php
 * (user_revision_action / bulk_sync / request_action) فقط احراز هویت داشتند
 * و CSRF روی آن‌ها بررسی نمی‌شد. csrf-shim.php هدر X-CSRF-Token را به‌صورت
 * خودکار روی همهٔ fetchهای هم‌منبع می‌گذارد، پس این بررسی برای UI موجود
 * شفاف است.
 */
if (melkinoIsMutatingRequest()) {
    melkinoCsrfCheck();
}

// Site content uses the SAME admin session, timeout, fingerprint, 2FA and strict CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['brand_save']) || ($_GET['brand_action'] ?? '') === 'save')) {
    require_once __DIR__ . '/brand-site-lib.php';
    mkBrandAdminSave();
}

function adminPanelJsonResponse(array $payload, int $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (is_file(__DIR__ . '/form-options.php')) {
    require_once __DIR__ . '/form-options.php';
}
if (isset($_GET['forms_combos']) && function_exists('melkinoFormComboCatalog')) {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        adminPanelJsonResponse(['success' => true, 'combos' => melkinoFormComboCatalog()]);
    }
    $body = function_exists('melkinoReadRequestBody') ? melkinoReadRequestBody() : [];
    $combos = $body['combos'] ?? null;
    if (!is_array($combos) || $combos === []) {
        adminPanelJsonResponse(['success' => false, 'message' => 'داده نامعتبر است.'], 400);
    }
    $ok = function_exists('melkinoFormComboSave') ? melkinoFormComboSave($combos) : false;
    $err = function_exists('melkinoFormComboLastError') ? melkinoFormComboLastError() : '';
    adminPanelJsonResponse([
        'success' => $ok,
        'message' => $ok
            ? 'در دیتابیس ذخیره شد. فرم ثبت ملک را یک‌بار رفرش کنید.'
            : ($err !== '' ? $err : 'ذخیره در دیتابیس نشد.'),
        'combos' => $ok ? melkinoFormComboCatalog() : null,
    ], $ok ? 200 : 500);
}

/* ====== ویرایش‌های کاربران: فهرست/تأیید/رد ====== */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['user_revision_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $a = trim((string)$_POST['user_revision_action']);
    $rid = (int)($_POST['revision_id'] ?? 0);
    try {
        $rs = $pdo->prepare('SELECT * FROM ad_revisions WHERE id=? LIMIT 1'); $rs->execute([$rid]); $rev = $rs->fetch(PDO::FETCH_ASSOC);
        if (!$rev) throw new RuntimeException('ویرایش پیدا نشد.');
        $snap = json_decode((string)$rev['snapshot'], true);
        if (!is_array($snap) || ($snap['review_status'] ?? 'pending') !== 'pending') throw new RuntimeException('این ویرایش قبلاً بررسی شده است.');
        if ($a === 'approve') {
            $x=$snap['after']??[];
            $allowed = ['title','location','address','area','land_area','built_area','rooms','floor','building_age','price_sell','deposit','rent_monthly','full_rent','total_price','down_payment','description','phone','price_hidden'];
            $sets = [];
            $vals = [];
            foreach ($allowed as $col) {
                if (!array_key_exists($col, $x)) continue;
                $sets[] = "`$col`=?";
                $vals[] = $x[$col];
            }
            $sets[] = "status='published'";
            $sets[] = "updated_at=NOW()";
            $sets[] = "published_at=COALESCE(published_at,NOW())";
            $vals[] = $rev['ad_id'];
            if ($sets) {
                $pdo->prepare('UPDATE ads SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
            }
            $snap['review_status']='approved'; $snap['reviewed_at']=date('Y-m-d H:i:s');
            $u=$pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?'); $u->execute([json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);
            melkinoAudit('ad.revision_approve', 'ad', (string)$rev['ad_id'], ['revision_id' => $rid, 'fields' => array_keys($x)]);
            melkinoNotifyAdOwner((string)$rev['ad_id'], 'ad_revision_approved', 'ویرایش آگهی شما تأیید شد', 'ویرایش آگهی «' . ($x['title'] ?? '') . '» تأیید شد و آگهی دوباره منتشر شد.', 'my-properties.php');
            echo json_encode(['success'=>true,'message'=>'ویرایش تأیید و آگهی دوباره منتشر شد.'],JSON_UNESCAPED_UNICODE); exit;
        }
        if ($a === 'reject') {
            $snap['review_status']='rejected'; $snap['reviewed_at']=date('Y-m-d H:i:s');
            $u=$pdo->prepare('UPDATE ad_revisions SET snapshot=? WHERE id=?'); $u->execute([json_encode($snap,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$rid]);
            $prev=$snap['before']??[]; $pdo->prepare('UPDATE ads SET status=?,updated_at=NOW() WHERE id=?')->execute([$prev['status']??'published',$rev['ad_id']]);
            melkinoAudit('ad.revision_reject', 'ad', (string)$rev['ad_id'], ['revision_id' => $rid]);
            melkinoNotifyAdOwner((string)$rev['ad_id'], 'ad_revision_rejected', 'ویرایش آگهی شما رد شد', 'ویرایش پیشنهادی شما برای آگهی «' . ($prev['title'] ?? '') . '» تأیید نشد؛ اطلاعات قبلی آگهی حفظ شد.', 'my-properties.php');
            echo json_encode(['success'=>true,'message'=>'ویرایش رد شد و اطلاعات قبلی حفظ شد.'],JSON_UNESCAPED_UNICODE); exit;
        }
        throw new RuntimeException('عملیات نامعتبر است.');
    } catch(Throwable $e) {
        http_response_code(400);
        // پیام‌های RuntimeException خودمان قابل نمایش‌اند؛ بقیه (خطای
        // دیتابیس و…) فقط در لاگ سرور می‌مانند.
        $publicMsg = ($e instanceof RuntimeException)
            ? $e->getMessage()
            : melkinoSafeError($e, 'admin-panel.revision', 'بررسی ویرایش انجام نشد.');
        echo json_encode(['success'=>false,'message'=>$publicMsg],JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$isMockMode = defined('MOCK_MODE') && MOCK_MODE === true;

// =========================================================
// سقفِ بارِ اولیه‌ی پنل ادمین
// =========================================================
// پنل همه‌ی آگهی‌ها را یک‌جا در دل صفحه چاپ می‌کرد. با رشدِ تعداد
// آگهی‌ها، حجم صفحه از کنترل خارج می‌شد (۳۰۰ آگهی ≈ ۱.۱ مگابایت،
// ۱۰۰۰ آگهی ≈ ۳.۳ مگابایت، ۲۰۰۰ آگهی ≈ ۶.۵ مگابایت) و پنل یا بسیار
// کند می‌شد یا از سقفِ حافظه‌ی هاست رد می‌شد و اصلاً بالا نمی‌آمد.
// حالا فقط این تعداد از «جدیدترین» آگهی‌ها همراه صفحه می‌آید و بقیه
// فقط در صورت نیاز و به‌صورت مرحله‌ای (دکمه‌ی «بارگذاری بقیه») گرفته
// می‌شود. برای تغییر، این عدد را ویرایش کن.
if (!defined('MELKINO_ADMIN_ADS_LIMIT')) {
    define('MELKINO_ADMIN_ADS_LIMIT', 200);
}
if (!defined('MELKINO_ADMIN_REQUESTS_LIMIT')) {
    define('MELKINO_ADMIN_REQUESTS_LIMIT', 200);
}

// پیامِ خطای بارگذاری آگهی‌ها؛ قبلاً هر خطایی بی‌سر و صدا به داده‌ی
// نمونه (mock) برمی‌گشت و همین باعث می‌شد مشکلاتِ دیتابیس ماه‌ها
// پنهان بماند. حالا خطا نگه داشته و به ادمین نشان داده می‌شود.
$adsLoadError = '';


/* =========================================================
   ذخیره تغییرات آگهی‌ها در MySQL
   ========================================================= */
if (!function_exists('melkinoEnsureAdsHistory')) {
    /** جدول تاریخچهٔ کامل آگهی (ویرایش/تأیید/تعلیق/انتشار کانال...) */
    function melkinoEnsureAdsHistory(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        // اگر تراکنش فعالی است، CREATE (DDL) آن را implicit-commit می‌کند؛
        // در این حالت فقط ساخت جدول را به فرصت بی‌تراکنش موکول می‌کنیم.
        try {
            if ($pdo->inTransaction()) {
                return;
            }
        } catch (Throwable $eTx) {
        }
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ads_history (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    ad_id VARCHAR(64) NOT NULL,
                    action VARCHAR(60) NOT NULL,
                    detail VARCHAR(500) NULL,
                    actor VARCHAR(120) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_ah_ad (ad_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('melkinoLogAdHistory')) {
    function melkinoLogAdHistory(PDO $pdo, string $adId, string $action, string $detail = ''): void
    {
        try {
            melkinoEnsureAdsHistory($pdo);
            $actor = 'ادمین';
            if (!empty($_SESSION['admin_username'])) $actor = (string)$_SESSION['admin_username'];
            elseif (!empty($_SESSION['admin_id'])) $actor = 'ادمین #' . (int)$_SESSION['admin_id'];
            $st = $pdo->prepare('INSERT INTO ads_history (ad_id, action, detail, actor) VALUES (?,?,?,?)');
            $st->execute([$adId, $action, mb_substr($detail, 0, 480), $actor]);
        } catch (Throwable $e) {
        }
    }
}

function dbCleanNumber($value): ?float {
    if ($value === null || $value === '') return null;
    // اعداد فارسی/عربی → لاتین، سپس حذف جداکننده‌های سه‌رقمی و واحد پول؛
    // تا مقدارِ «سه‌رقم سه‌رقم» هم بدون خطا ذخیره شود (راند ۱۰).
    $v = strtr((string)$value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $v = str_replace([',', '٬', ' ', 'تومان', 'ریال'], '', $v);
    return is_numeric($v) ? (float)$v : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_GET['ad_db_action'] ?? $_POST['ad_db_action'] ?? '') === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        $ids = [];
        if (is_array($payload)) {
            $raw = $payload['ids'] ?? $payload;
            if (!is_array($raw)) {
                $raw = [$raw];
            }
            foreach ($raw as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            throw new RuntimeException('شناسه آگهی نامعتبر است.');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        // قوانین اتوماسیون مرکز انتشار: رویداد «حذف آگهی» (قبل از حذف؛ ایمن و بی‌صدا)
        try {
            require_once __DIR__ . '/publish-center-lib.php';
            foreach ($ids as $__delId) {
                melkinoPublishAdEventSafe($pdo, (string)$__delId, 'deleted', [], 'admin');
            }
        } catch (Throwable $ePubDel) {
        }
        $pdo->beginTransaction();
        foreach (['ad_amenities', 'images', 'favorites', 'request_matches', 'compare_items', 'location_change_log'] as $tbl) {
            try {
                $pdo->prepare("DELETE FROM `$tbl` WHERE ad_id IN ($in)")->execute($ids);
            } catch (Throwable $e) {
            }
        }
        $pdo->prepare("DELETE FROM ads WHERE id IN ($in)")->execute($ids);
        $pdo->commit();
        melkinoAudit('ad.delete', 'ad', implode(',', array_slice($ids, 0, 5)), ['count' => count($ids), 'ids' => array_slice($ids, 0, 20)]);
        echo json_encode(['ok' => true, 'deleted' => count($ids)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'حذف آگهی انجام نشد.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_GET['ad_db_action'] ?? $_POST['ad_db_action'] ?? '') === 'bulk_sync') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('داده‌های آگهی معتبر نیستند.');
        }
        // راند ۵۱: وضعیت‌های قبلی برای تشخیص تغییر و ارسال اعلان
        $syncOld = [];
        $syncIds = [];
        foreach ($payload as $pAd) {
            if (is_array($pAd) && !empty($pAd['id'])) $syncIds[] = (string)$pAd['id'];
        }
        if ($syncIds) {
            $in = implode(',', array_fill(0, count($syncIds), '?'));
            // ستون‌های قیمتی هم لازم‌اند تا لاگ حسابرسی «از → به» درست باشد
            $stOld = $pdo->prepare("SELECT id, status, title, phone, price_sell, deposit, rent_monthly, full_rent, total_price, is_vip FROM ads WHERE id IN ($in)");
            $stOld->execute($syncIds);
            foreach ($stOld->fetchAll(PDO::FETCH_ASSOC) as $sr) $syncOld[(string)$sr['id']] = $sr;
        }
        // مهم: هیچ DDL (CREATE/ALTER) داخل تراکنش اجرا نشود — DDL در MySQL
        // تراکنش را «implicit commit» می‌کند و commit پایانی با خطای
        // «There is no active transaction» می‌شکند. همهٔ تضمین‌های اسکیما
        // باید «قبل» از beginTransaction انجام شوند.
        melkinoEnsureAdsDefaultImageColumn($pdo);
        if (function_exists('melkinoEnsureRatingColumns')) {
            melkinoEnsureRatingColumns($pdo);
        }
        if (function_exists('melkinoEnsureAdsHistory')) {
            melkinoEnsureAdsHistory($pdo);
        }
        // شماره تماس دوم: ستون اختیاری و افزایشی (مثل ثبت آگهی). DDL قبل از تراکنش انجام می‌شود.
        if (!$pdo->query("SHOW COLUMNS FROM ads LIKE 'phone2'")->fetch()) {
            $pdo->exec("ALTER TABLE ads ADD COLUMN phone2 VARCHAR(20) NULL");
        }
        if (function_exists('melkinoEnsureAdsPriceNote')) { melkinoEnsureAdsPriceNote($pdo); }
        $pdo->beginTransaction();
        // [PRICE] راند ۲۱: display_price هم مثل ثبت اولیه همگام با ستون‌های قیمت
        // نگه داشته می‌شود (اولویت: price_sell → total_price → deposit →
        // rent_monthly). بدون این، پس از اصلاح قیمت در پنل، نمایش عمومی (هوم)
        // همچنان مقدار قدیمی/آلودهٔ display_price را نشان می‌داد.
        $update = $pdo->prepare("UPDATE ads SET title=?, transaction_type=?, property_type=?, status=?, location=?, address=?, gender=?, last_name=?, phone=?, phone2=?, price_sell=?, price_condition=?, deposit=?, rent_monthly=?, full_rent=?, full_rent_enabled=?, total_price=?, display_price=?, down_payment=?, payment_terms=?, price_hidden=?, price_note=?, description=?, publish_photos=?, is_vip=?, tags=?, property_details=?, custom_fields=?, is_not_keyed=?, delivery_date=?, vacancy_date=?, is_vacant=?, exchange_interested=?, exchange_with=?, deed_type=?, deed_notes=?, exchange_types=?, visit_hours=?, is_old=?, is_renovated=?, water_share=?, well_name=?, has_loan=?, loan_amount=?, loan_type=?, loan_duration=?, loan_bank=?, loan_installment=?, loan_installments_paid=?, loan_notes=?, default_image_no=?, melkino_visited=?, melkino_rating=?, melkino_review=?, updated_at=NOW(), published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END, sold_at=CASE WHEN ?='sold' THEN COALESCE(sold_at,NOW()) ELSE NULL END WHERE id=?");
        $delAmen = $pdo->prepare("DELETE FROM ad_amenities WHERE ad_id=?");
        $findAmen = $pdo->prepare("SELECT id FROM amenities WHERE name=? LIMIT 1");
        $insAmen = $pdo->prepare("INSERT INTO amenities (name, is_active) VALUES (?,1)");
        $linkAmen = $pdo->prepare("INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?,?)");
        $resetImages = $pdo->prepare("UPDATE images SET is_selected=0, publish_publicly=0, is_primary=0 WHERE ad_id=?");
        $updImage = $pdo->prepare("UPDATE images SET is_selected=?, publish_publicly=?, is_primary=?, sort_order=? WHERE ad_id=? AND filename=?");
        $insImage = $pdo->prepare("INSERT INTO images (ad_id, filename, storage_path, sort_order, is_selected, is_primary, publish_publicly, created_at) VALUES (?,?,?,?,?,?,?,NOW())");

        foreach ($payload as $ad) {
            if (!is_array($ad) || empty($ad['id'])) continue;
            $id = (string)$ad['id'];
            $status = (string)($ad['status'] ?? 'pending');
            // راند ۱۵: اگر property_details به‌صورت رشتهٔ JSON (یا چندلایه escape‌شده)
            // برسد، دیگر دور ریخته نمی‌شود — لایه‌ها باز و به آرایه تبدیل می‌شود.
            $details = melkinoNormalizeJsonColumn($ad['property_details'] ?? null);
            $amenities = is_array($ad['amenities'] ?? null) ? $ad['amenities'] : [];
            // [PRICE] اولویت مثل فرم ثبت (register-*): price_sell → total_price → deposit → rent_monthly
            $mkDisplayPrice = dbCleanNumber($ad['price_sell'] ?? null)
                ?: dbCleanNumber($ad['total_price'] ?? null)
                ?: dbCleanNumber($ad['deposit'] ?? null)
                ?: dbCleanNumber($ad['rent_monthly'] ?? null);
            $update->execute([
                function_exists('melkinoCleanAdTitle') ? melkinoCleanAdTitle((string)($ad['title'] ?? ''), '') : trim((string)($ad['title'] ?? '')),
                $ad['transaction_type'] ?? null,
                $ad['property_type'] ?? null,
                $status,
                $ad['location'] ?? null,
                $ad['address'] ?? null,
                $ad['gender'] ?? null,
                $ad['last_name'] ?? null,
                $ad['phone'] ?? null,
                ($ad['phone2'] ?? '') !== '' ? $ad['phone2'] : null,
                dbCleanNumber($ad['price_sell'] ?? null),
                $ad['price_condition'] ?? null,
                dbCleanNumber($ad['deposit'] ?? null),
                dbCleanNumber($ad['rent_monthly'] ?? null),
                dbCleanNumber($ad['full_rent'] ?? null),
                !empty($ad['full_rent_enabled']) ? 1 : 0,
                dbCleanNumber($ad['total_price'] ?? null),
                $mkDisplayPrice,
                dbCleanNumber($ad['down_payment'] ?? null),
                $ad['payment_terms'] ?? null,
                !empty($ad['price_hidden']) ? 1 : 0,
                (function ($v) { $v = trim((string)$v); return $v !== '' ? mb_substr($v, 0, 255) : null; })($ad['price_note'] ?? ''),
                $ad['description'] ?? null,
                (string)($ad['publish_photos'] ?? 'yes'),
                !empty($ad['is_vip']) ? 1 : 0,
                json_encode(melkinoNormalizeJsonColumn($ad['tags'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode(melkinoNormalizeJsonColumn($ad['custom_fields'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                !empty($ad['is_not_keyed']) ? 1 : 0,
                (!empty($ad['delivery_date']) ? (string)$ad['delivery_date'] : null),
                (!empty($ad['vacancy_date']) ? (string)$ad['vacancy_date'] : null),
                !empty($ad['is_vacant']) ? 1 : 0,
                !empty($ad['exchange_interested']) ? 1 : 0,
                (!empty($ad['exchange_with']) ? (string)$ad['exchange_with'] : null),
                // سند و معاوضه (راند ۱۹) — قبلاً ستون‌ها در bulk_sync ذخیره نمی‌شدند
                (function ($v) { $v = trim((string)$v); return $v !== '' ? mb_substr($v, 0, 40) : null; })($ad['deed_type'] ?? ''),
                (function ($v) { $v = trim((string)$v); return $v !== '' ? mb_substr($v, 0, 500) : null; })($ad['deed_notes'] ?? ''),
                (function ($v) { $v = trim((string)$v); return $v !== '' ? mb_substr($v, 0, 255) : null; })($ad['exchange_types'] ?? ''),
                (!empty($ad['visit_hours']) ? (string)$ad['visit_hours'] : null),
                !empty($ad['is_old']) ? 1 : 0,
                !empty($ad['is_renovated']) ? 1 : 0,
                (!empty($ad['water_share']) ? (string)$ad['water_share'] : null),
                (!empty($ad['well_name']) ? (string)$ad['well_name'] : null),
                // فیلدهای وام (فقط وقتی تیک «وام دارد» فعال باشد)
                !empty($ad['has_loan']) ? 1 : 0,
                !empty($ad['has_loan']) ? dbCleanNumber($ad['loan_amount'] ?? null) : null,
                !empty($ad['has_loan']) ? (trim((string)($ad['loan_type'] ?? '')) ?: null) : null,
                !empty($ad['has_loan']) ? (trim((string)($ad['loan_duration'] ?? '')) ?: null) : null,
                !empty($ad['has_loan']) ? (trim((string)($ad['loan_bank'] ?? '')) ?: null) : null,
                !empty($ad['has_loan']) ? dbCleanNumber($ad['loan_installment'] ?? null) : null,
                !empty($ad['has_loan']) ? (trim((string)($ad['loan_installments_paid'] ?? '')) ?: null) : null,
                !empty($ad['has_loan']) ? (trim((string)($ad['loan_notes'] ?? '')) ?: null) : null,
                (function ($v) { $v = (int)$v; return ($v >= 0 && $v <= 5) ? ($v ?: null) : null; })($ad['default_image_no'] ?? 0),
                !empty($ad['melkino_visited']) ? 1 : 0,
                (function ($v) { $v = is_numeric($v) ? (float)$v : 0; return ($v > 0 && $v <= 5) ? $v : null; })($ad['melkino_rating'] ?? 0),
                (function ($v) { $v = trim((string)$v); return $v !== '' ? $v : null; })($ad['melkino_review'] ?? ''),
                $status,
                $status,
                $id
            ]);

            // تاریخچهٔ آگهی: هر ذخیرهٔ ادمین + تغییر وضعیت (تأیید/تعلیق/رد/فروش/بایگانی)
            try {
                melkinoLogAdHistory($pdo, (string)$id, 'ویرایش توسط ادمین', 'ذخیرهٔ کامل از پنل آگهی‌ها');
                $__oldSt = isset($syncOld[(string)$id]['status']) ? (string)$syncOld[(string)$id]['status'] : '';
                if ($__oldSt !== '' && $__oldSt !== $status) {
                    $__stMap = [
                        'published' => 'تأیید و انتشار',
                        'pending'   => 'تعلیق (بازگشت به صف تأیید)',
                        'rejected'  => 'رد آگهی',
                        'sold'      => 'علامت‌گذاری فروش',
                        'archived'  => 'بایگانی آگهی',
                    ];
                    melkinoLogAdHistory($pdo, (string)$id, $__stMap[$status] ?? ('تغییر وضعیت به ' . $status), 'وضعیت قبلی: ' . $__oldSt);
                }
                if ((int)($syncOld[(string)$id]['is_vip'] ?? 0) !== (int)!empty($ad['is_vip'])) {
                    melkinoLogAdHistory($pdo, (string)$id, !empty($ad['is_vip']) ? 'VIP شد' : 'VIP برداشته شد', '');
                }
            } catch (Throwable $e) {
            }

            try {
                $lat = isset($ad['latitude']) ? $ad['latitude'] : null;
                $lng = isset($ad['longitude']) ? $ad['longitude'] : null;
                if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                    $pdo->prepare('UPDATE ads SET latitude=?, longitude=?, location_received=? WHERE id=?')
                        ->execute([$lat, $lng, '1', $id]);
                }
            } catch (Throwable $e) {
                try {
                    if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
                        $pdo->prepare('UPDATE ads SET latitude=?, longitude=? WHERE id=?')->execute([$lat, $lng, $id]);
                    }
                } catch (Throwable $e2) {
                }
            }

            $delAmen->execute([$id]);
            foreach ($amenities as $amenity) {
                $name = trim((string)$amenity);
                if ($name === '') continue;
                $findAmen->execute([$name]);
                $amenityId = $findAmen->fetchColumn();
                if (!$amenityId) {
                    $insAmen->execute([$name]);
                    $amenityId = $pdo->lastInsertId();
                }
                $linkAmen->execute([$id, (int)$amenityId]);
            }

            $resetImages->execute([$id]);
            $selected = array_map('strval', is_array($ad['selected_images'] ?? null) ? $ad['selected_images'] : []);
            $publishPhotos = (string)($ad['publish_photos'] ?? 'yes') === 'yes';
            $firstPrimary = true;
            $allImages = is_array($ad['images'] ?? null) ? $ad['images'] : [];
            $sortOrder = 0;
            foreach ($allImages as $file) {
                $file = trim((string)$file);
                if ($file === '') continue;
                $chosen = $publishPhotos && in_array($file, $selected, true);
                $isPrimary = ($chosen && $firstPrimary) ? 1 : 0;

                $updImage->execute([$chosen ? 1 : 0, $chosen ? 1 : 0, $isPrimary, $sortOrder, $id, $file]);

                if ($updImage->rowCount() === 0) {
                    // این عکس هنوز ردیفی در جدول images نداشت (مثلاً همین
                    // الان از پنل ادمین آپلود شده) — قبلاً این حالت به‌کلی
                    // نادیده گرفته می‌شد و عکس‌های جدید هیچ‌وقت ذخیره
                    // نمی‌شدند. حالا برایش یک ردیف تازه ساخته می‌شود.
                    $insImage->execute([$id, $file, $file, $sortOrder, $chosen ? 1 : 0, $isPrimary, $chosen ? 1 : 0]);
                }

                if ($chosen) $firstPrimary = false;
                $sortOrder++;
            }
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        } else {
            error_log('[melkino][bulk_sync] transaction was implicitly closed before commit (DDL inside transaction?)');
        }
        // راند ۵۱: اعلان تغییر وضعیت آگهی به مالک (منتشر/رد/فروخته/معلق) — بی‌صدا
        try {
            if (!function_exists('melkinoNotifyAdOwner')) {
                require_once __DIR__ . '/db_helpers.php';
            }
            $statusNotif = [
                'published' => ['ad_published', '✅ آگهی شما منتشر شد', 'آگهی «%s» تأیید و در سایت منتشر شد.'],
                'rejected'  => ['ad_rejected', '❌ آگهی شما رد شد', 'آگهی «%s» رد شد. برای اصلاح و ثبت مجدد با پشتیبانی در تماس باشید.'],
                'sold'      => ['ad_status', '🤝 آگهی شما بسته شد', 'وضعیت آگهی «%s» به «فروخته / اجاره شده» تغییر کرد.'],
                'suspended' => ['ad_status', '⏸ آگهی شما معلق شد', 'آگهی «%s» موقتاً معلق شد. برای اطلاعات بیشتر با پشتیبانی در تماس باشید.'],
            ];
            foreach ($payload as $pad) {
                if (!is_array($pad) || empty($pad['id'])) continue;
                $nid = (string)$pad['id'];
                $newStatus = (string)($pad['status'] ?? '');
                $oldStatus = (string)($syncOld[$nid]['status'] ?? '');
                if ($newStatus === '' || $newStatus === $oldStatus || !isset($statusNotif[$newStatus])) continue;
                [$nType, $nTitle, $nTpl] = $statusNotif[$newStatus];
                $nLabel = trim((string)($pad['title'] ?? $syncOld[$nid]['title'] ?? '')) ?: $nid;
                if (function_exists('melkinoNotifyAdOwner')) {
                    melkinoNotifyAdOwner($nid, $nType, $nTitle, sprintf($nTpl, $nLabel), 'my-properties.php');
                }
            }
        } catch (Throwable $e) {
            // اعلان هرگز مانع ذخیره نمی‌شود
        }
        // حسابرسی: چه آگهی‌هایی و کدام فیلدهای حساس (وضعیت/قیمت) تغییر کردند
        try {
            foreach ($payload as $pad) {
                if (!is_array($pad) || empty($pad['id'])) continue;
                $aid = (string) $pad['id'];
                $before = $syncOld[$aid] ?? [];
                $changed = [];
                foreach (['status', 'price_sell', 'deposit', 'rent_monthly', 'full_rent', 'total_price', 'is_vip'] as $wk) {
                    if (!array_key_exists($wk, $pad)) continue;
                    $newV = (string) $pad[$wk];
                    $oldV = (string) ($before[$wk] ?? '');
                    if ($newV !== $oldV) {
                        $changed[$wk] = ['from' => $oldV, 'to' => $newV];
                    }
                }
                if ($changed) {
                    melkinoAudit('ad.update', 'ad', $aid, $changed);
                }
            }
        } catch (Throwable $eAudit) {
        }
        // قوانین اتوماسیون مرکز انتشار: رویدادهای تغییر وضعیت/ویرایش آگهی (ایمن و بی‌صدا)
        try {
            require_once __DIR__ . '/publish-center-lib.php';
            foreach ($payload as $pad) {
                if (!is_array($pad) || empty($pad['id'])) continue;
                $nid = (string)$pad['id'];
                $newStatus = (string)($pad['status'] ?? '');
                $oldStatus = (string)($syncOld[$nid]['status'] ?? '');
                $txType = (string)($pad['transaction_type'] ?? $syncOld[$nid]['transaction_type'] ?? '');
                $__ev = 'changed';
                if ($newStatus !== '' && $newStatus !== $oldStatus) {
                    if ($newStatus === 'sold') {
                        $__ev = (mb_strpos($txType, 'اجاره') !== false) ? 'rented' : 'sold';
                    } elseif (in_array($newStatus, ['suspended', 'archived', 'rejected', 'pending'], true)) {
                        $__ev = 'inactive';
                    } else {
                        $__ev = 'changed';
                    }
                }
                melkinoPublishAdEventSafe($pdo, $nid, $__ev, ['old_status' => $oldStatus, 'new_status' => $newStatus], 'admin');
            }
        } catch (Throwable $ePubEv) {
        }
        adminPanelJsonResponse(['success'=>true], 200);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Melkino admin-panel bulk_sync error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        adminPanelJsonResponse([
            'success' => false,
            'message' => melkinoSafeError($e, 'admin-panel.bulk_sync', 'ذخیره تغییرات انجام نشد.'),
            // ادمین باید علت واقعی را ببیند (مثلاً «Unknown column») تا قابل‌رفع باشد
            'error' => $e->getMessage() . ' @ line ' . $e->getLine(),
        ], 500);
    }
    exit;
}

/* =========================================================
   مدیریت وضعیت و یادداشت پیگیری درخواست‌ها
   ========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['request_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $requestAction = (string)($_POST['request_action'] ?? '');

    if ($requestAction === 'delete_request') {
        $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));

        if ($trackingCode === '') {
            echo json_encode(['ok'=>false,'message'=>'کد پیگیری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $find = $pdo->prepare("SELECT id FROM property_requests WHERE tracking_code = ? LIMIT 1");
            $find->execute([$trackingCode]);
            $reqId = $find->fetchColumn();

            if (!$reqId) {
                echo json_encode(['ok'=>false,'message'=>'درخواست موردنظر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $pdo->beginTransaction();

            // قبلاً این پاک‌سازی با ON DELETE CASCADE در خودِ دیتابیس
            // انجام می‌شد؛ چون هاست فعلی دسترسی REFERENCES نمی‌دهد،
            // این کار باید دستی و از همینجا انجام شود.
            $pdo->prepare(
                "DELETE rmf FROM request_match_feedback rmf
                 INNER JOIN request_matches rm ON rm.id = rmf.request_match_id
                 WHERE rm.request_id = ?"
            )->execute([$reqId]);

            $pdo->prepare("DELETE FROM request_matches WHERE request_id = ?")->execute([$reqId]);
            $pdo->prepare("DELETE FROM request_amenities WHERE request_id = ?")->execute([$reqId]);
            $pdo->prepare("DELETE FROM property_requests WHERE id = ?")->execute([$reqId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok'=>false,'message'=>melkinoSafeError($e, 'admin-panel.request_delete', 'حذف درخواست انجام نشد.')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        melkinoAudit('request.delete', 'property_request', (string)$reqId, ['tracking_code' => $trackingCode]);
        echo json_encode(['ok'=>true,'message'=>'درخواست حذف شد.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ویرایش فیلدهای اصلی درخواست توسط ادمین — دقیقاً همان ستون‌های
    // ویرایش سمت کاربر (requests.php) با همان نرمال‌سازی اعداد.
    if ($requestAction === 'update_request_fields') {
        $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));
        if ($trackingCode === '') {
            echo json_encode(['ok'=>false,'message'=>'کد پیگیری معتبر نیست.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rqDigits = static fn($v) => strtr((string)$v, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $rqNum = static function (string $v) use ($rqDigits): ?string {
            $v = $rqDigits($v);
            $v = str_replace([',', '،', '٬', ' '], '', $v);
            $v = preg_replace('/[^0-9.\-]/u', '', $v);
            return ($v !== '' && is_numeric($v)) ? $v : null;
        };
        $rqText = static function (string $k): ?string {
            $v = trim(strip_tags((string)($_POST[$k] ?? '')));
            return $v !== '' ? $v : null;
        };

        $dateNeeded = $rqText('date_needed');
        $rahnKamal = !empty($_POST['rahn_kamal']) ? 1 : 0;
        $isNotKeyed = !empty($_POST['is_not_keyed']) ? 1 : 0;

        try {
            $find = $pdo->prepare("SELECT id FROM property_requests WHERE tracking_code = ? LIMIT 1");
            $find->execute([$trackingCode]);
            $reqId = $find->fetchColumn();
            if (!$reqId) {
                echo json_encode(['ok'=>false,'message'=>'درخواست موردنظر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $up = $pdo->prepare('UPDATE property_requests SET transaction_type=?, property_type=?, location=?, urgency=?, date_needed=?, rahn_kamal=?, min_area=?, max_area=?, min_price=?, max_price=?, min_deposit=?, max_deposit=?, min_rent=?, max_rent=?, is_not_keyed=?, updated_at=NOW() WHERE id=?');
            $up->execute([
                $rqText('transaction_type'), $rqText('property_type'), $rqText('location'), $rqText('urgency'), $dateNeeded,
                $rahnKamal, $rqNum((string)($_POST['min_area'] ?? '')), $rqNum((string)($_POST['max_area'] ?? '')),
                $rqNum((string)($_POST['min_price'] ?? '')), $rqNum((string)($_POST['max_price'] ?? '')),
                $rqNum((string)($_POST['min_deposit'] ?? '')), $rqNum((string)($_POST['max_deposit'] ?? '')),
                $rqNum((string)($_POST['min_rent'] ?? '')), $rqNum((string)($_POST['max_rent'] ?? '')),
                $isNotKeyed, $reqId,
            ]);

            // بازسازی تطبیق‌ها — مثل ویرایش سمت کاربر؛ شکستش ویرایش را ناموفق نمی‌کند
            $rematchOk = true;
            try {
                if (function_exists('m5EnsureRequestMatches')) {
                    m5EnsureRequestMatches((int)$reqId);
                }
            } catch (Throwable $matchError) {
                $rematchOk = false;
            }

            melkinoAudit('request.update_fields', 'property_request', (string)$reqId, ['tracking_code' => $trackingCode]);
            echo json_encode(['ok'=>true,'message'=>'درخواست به‌روزرسانی شد.','rematch'=>$rematchOk], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'message'=>melkinoSafeError($e, 'admin-panel.request_update_fields', 'به‌روزرسانی درخواست انجام نشد.')], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ثبت درخواست ملک توسط ادمین (مراجع حضوری) — مستقیم از پنل،
    // با همان ستون‌ها و کد رهگیریِ فرم کاربر (property-request.php)
    if ($requestAction === 'create_request') {
        $rqDigits2 = static fn($v) => strtr((string)$v, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $getT = static function (string $k): ?string {
            $v = trim(strip_tags((string)($_POST[$k] ?? '')));
            return $v !== '' ? $v : null;
        };
        $getNum = static function (string $k) use ($rqDigits2): ?string {
            $v = $rqDigits2((string)($_POST[$k] ?? ''));
            $v = str_replace([',', '،', '٬', ' '], '', $v);
            $v = preg_replace('/[^0-9.\-]/u', '', $v);
            return ($v !== '' && is_numeric($v)) ? $v : null;
        };

        $lastName = trim(strip_tags((string)($_POST['last_name'] ?? '')));
        $phoneRaw = $rqDigits2((string)($_POST['phone'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', (string)$phoneRaw);

        if ($lastName === '') {
            echo json_encode(['ok'=>false,'message'=>'نام مراجع را وارد کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!preg_match('/^09\d{9}$/', (string)$phone)) {
            echo json_encode(['ok'=>false,'message'=>'شمارهٔ موبایل معتبر نیست (مثل 09123456789).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $gender = trim((string)($_POST['gender'] ?? 'آقا'));
        if (!in_array($gender, ['آقا', 'خانم'], true)) {
            $gender = 'آقا';
        }

        // اتصال به حساب کاربریِ همان شماره (اگر بعداً مراجع از بله/تلگرام
        // وارد شد، درخواست را می‌بیند) — همان مسیر فرم کاربر
        $userId = null;
        if (function_exists('melkinoUpsertUser')) {
            try {
                $providedToken = $_COOKIE['melkino_access_token'] ?? '';
                $ident = melkinoUpsertUser('', $phone, $lastName, '', $providedToken);
                $userId = (int)($ident['id'] ?? 0) ?: null;
            } catch (Throwable $upE) {
                $userId = null;
            }
        }

        // کد رهگیری — همان الگوی REQ-Ymd-#### فرم کاربر
        $dateKey = date('Ymd');
        $trackingCode = '';
        try {
            $stLast = $pdo->prepare("SELECT tracking_code FROM property_requests WHERE tracking_code LIKE ? ORDER BY id DESC LIMIT 1");
            $stLast->execute(['REQ-' . $dateKey . '-%']);
            $nextNumber = 1;
            if (preg_match('/^REQ-' . preg_quote($dateKey, '/') . '-(\d{4})$/', (string)$stLast->fetchColumn(), $m)) {
                $nextNumber = ((int)$m[1]) + 1;
            }
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidate = 'REQ-' . $dateKey . '-' . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
                $chk = $pdo->prepare('SELECT 1 FROM property_requests WHERE tracking_code = ? LIMIT 1');
                $chk->execute([$candidate]);
                if (!$chk->fetchColumn()) {
                    $trackingCode = $candidate;
                    break;
                }
                $nextNumber++;
            }
            if ($trackingCode === '') {
                $trackingCode = 'REQ-' . $dateKey . '-' . substr((string)time(), -4);
            }
        } catch (Throwable $tcE) {
            $trackingCode = 'REQ-' . $dateKey . '-' . substr((string)time(), -4);
        }

        try {
            $ins = $pdo->prepare("INSERT INTO property_requests
                (tracking_code, user_id, telegram_id, gender, last_name, phone,
                 transaction_type, property_type, location, urgency, date_needed, rahn_kamal,
                 min_area, max_area, min_price, max_price, min_deposit, max_deposit, min_rent, max_rent,
                 min_age, max_age, is_not_keyed, status, additional, property_details, created_at)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, 'new', '[]', '{\"search_polygon\":null}', NOW())");
            $ins->execute([
                $trackingCode, $userId, $gender, $lastName, $phone,
                $getT('transaction_type'), $getT('property_type'), $getT('location'), $getT('urgency'), $getT('date_needed'),
                !empty($_POST['rahn_kamal']) ? 1 : 0,
                $getNum('min_area'), $getNum('max_area'), $getNum('min_price'), $getNum('max_price'),
                $getNum('min_deposit'), $getNum('max_deposit'), $getNum('min_rent'), $getNum('max_rent'),
                !empty($_POST['is_not_keyed']) ? 1 : 0,
            ]);
            $newId = (int)$pdo->lastInsertId();

            // بازسازی تطبیق‌ها — بی‌خطر برای خودِ ثبت
            try {
                if (function_exists('m5EnsureRequestMatches')) {
                    m5EnsureRequestMatches($newId);
                }
            } catch (Throwable $rmE) {
            }

            melkinoAudit('request.create_admin', 'property_request', (string)$newId, ['tracking_code' => $trackingCode]);

            $rowSt = $pdo->prepare('SELECT * FROM property_requests WHERE id = ? LIMIT 1');
            $rowSt->execute([$newId]);
            echo json_encode(['ok'=>true,'message'=>'درخواست ثبت شد.','request'=>$rowSt->fetch(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok'=>false,'message'=>melkinoSafeError($e, 'admin-panel.request_create', 'ثبت درخواست انجام نشد.')], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($requestAction !== 'update_request_meta') {
        echo json_encode(['ok'=>false,'message'=>'عملیات نامعتبر است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $trackingCode = trim((string)($_POST['tracking_code'] ?? ''));
    $status = trim((string)($_POST['status'] ?? 'new'));
    $followupNote = trim((string)($_POST['followup_note'] ?? ''));

    $allowedStatuses = [
        'new' => 'جدید',
        'tracking' => 'در حال پیگیری',
        'archived' => 'بایگانی',
        'closed' => 'بسته شده',
    ];

    if ($trackingCode === '' || !isset($allowedStatuses[$status])) {
        echo json_encode(['ok'=>false,'message'=>'اطلاعات درخواست معتبر نیست.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();
        // راند ۵۴: خواندن-اصلاح-نوشتن (JSON_SET روی آرایهٔ قدیمی «[]» بی‌اثر بود)
        $curSt = $pdo->prepare('SELECT status, property_details FROM property_requests WHERE tracking_code = ? LIMIT 1');
        $curSt->execute([$trackingCode]);
        $curRow = $curSt->fetch(PDO::FETCH_ASSOC);
        $oldStatus = (string)($curRow['status'] ?? '');
        $detArr = json_decode((string)($curRow['property_details'] ?? ''), true);
        if (!is_array($detArr) || array_is_list($detArr)) {
            $detArr = [];
        }
        $detArr['followup_note'] = $followupNote;
        $detJson = json_encode($detArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("UPDATE property_requests SET status = ?, property_details = ?, updated_at = NOW() WHERE tracking_code = ? LIMIT 1");
        $stmt->execute([$status, $detJson, $trackingCode]);
        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'message'=>'درخواست موردنظر پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->commit();
        // اعلان تغییر وضعیت درخواست برای متقاضی — بی‌صدا
        try {
            if ($status !== 'new' && $status !== $oldStatus) {
                if (!function_exists('sendNotification')) {
                    require_once __DIR__ . '/db_helpers.php';
                }
                if (function_exists('sendNotification')) {
                    $eventsOn = function_exists('melkinoEventsEnabled') ? melkinoEventsEnabled() : true;
                    if ($eventsOn) {
                        $owner = $pdo->prepare('SELECT id, user_id, telegram_id FROM property_requests WHERE tracking_code = ? LIMIT 1');
                        $owner->execute([$trackingCode]);
                        $orow = $owner->fetch(PDO::FETCH_ASSOC);
                        if ($orow && (!empty($orow['user_id']) || !empty($orow['telegram_id']))) {
                            sendNotification(
                                !empty($orow['user_id']) ? (int)$orow['user_id'] : null,
                                !empty($orow['telegram_id']) ? (string)$orow['telegram_id'] : null,
                                'request_status',
                                'وضعیت درخواست شما: ' . $allowedStatuses[$status],
                                'وضعیت درخواست با کد پیگیری ' . $trackingCode . ' به «' . $allowedStatuses[$status] . '» تغییر کرد.',
                                'requests.php',
                                null,
                                (int)$orow['id']
                            );
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'message'=>melkinoSafeError($e, 'admin-panel.save', 'ذخیره‌سازی انجام نشد.')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>true,'message'=>'ذخیره شد.','status'=>$status,'status_label'=>$allowedStatuses[$status],'followup_note'=>$followupNote], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==============================================
// داده‌های نمونه (فقط برای حالت MOCK)
// ==============================================
function getMockAds() {
    return [
        [
            'id' => 1,
            'title' => 'آپارتمان لوکس ۱۲۰ متری در نیاوران',
            'transaction_type' => 'فروش',
            'property_type' => 'آپارتمان',
            'price_sell' => '۳,۸۰۰,۰۰۰,۰۰۰',
            'price_condition' => 'negotiable',
            'deposit' => '',
            'rent_monthly' => '',
            'full_rent_enabled' => 0,
            'full_rent' => '',
            'total_price' => '',
            'down_payment' => '',
            'payment_terms' => '',
            'last_name' => 'رضایی',
            'phone' => '۰۹۱۲۳۴۵۶۷۸۹',
            'address' => 'خیابان نیاوران، پلاک ۱۲',
            'location' => 'نیاوران، تهران',
            'description' => 'دوبلکس با نمای شمالی، پارکینگ و انباری، نزدیک به مترو',
            'status' => 'published',
            'property_details' => '{"area":"۱۲۰","floor":"۵","unit":"۳","total_units":"۱۲","rooms":"۳","year":"۱۴۰۲","flooring":"پارکت","cabinet":"ام دی اف","cooling":"اسپیلت","heating":"شوفاژ"}',
            'images' => '["uploads/sample1.jpg"]',
            'selected_images' => '["uploads/sample1.jpg"]',
            'publish_photos' => 'yes',
            'amenities' => ['آسانسور', 'پارکینگ', 'انباری'],
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 2,
            'title' => 'ویلای ۴۰۰ متری در چالوس',
            'transaction_type' => 'فروش',
            'property_type' => 'ویلا',
            'price_sell' => '۱۲,۵۰۰,۰۰۰,۰۰۰',
            'price_condition' => 'fixed',
            'deposit' => '',
            'rent_monthly' => '',
            'full_rent_enabled' => 0,
            'full_rent' => '',
            'total_price' => '',
            'down_payment' => '',
            'payment_terms' => '',
            'last_name' => 'کریمی',
            'phone' => '۰۹۱۲۳۴۵۶۷۸۰',
            'address' => 'چالوس، خیابان دریا، کوچه ۵',
            'location' => 'چالوس، مازندران',
            'description' => 'استخر اختصاصی، باغچه و منظره دریا، سند تک‌برگ',
            'status' => 'published',
            'property_details' => '{"land_area":"۴۰۰","built_area":"۲۵۰","rooms":"۴","year":"۱۴۰۱","flooring":"سنگ","cabinet":"چوبی","cooling":"اسپیلت","heating":"پکیج"}',
            'images' => '["uploads/sample2.jpg"]',
            'selected_images' => '["uploads/sample2.jpg"]',
            'publish_photos' => 'yes',
            'amenities' => ['استخر', 'باغچه', 'سند تک‌برگ'],
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}

// ==============================================
// بارگذاری آگهی‌ها
// ==============================================
$adsData = [];

function normalizeAdsData($ads) {
    if (!is_array($ads)) return [];

    $propertyFieldMap = [
        'آپارتمان' => [
            'area',
            'floor',
            'unit',
            'total_units',
            'rooms',
            'year',
            'flooring',
            'cabinet',
            'cooling',
            'heating'
        ],
        'ویلا' => [
            'land_area',
            'area',
            'rooms',
            'year',
            'flooring',
            'cabinet',
            'cooling',
            'heating'
        ],
        'زمین' => [
            'land_area',
            'land_usage',
            'land_type',
            'land_width',
            'land_length',
            'land_front_width',
            'land_blocks',
            'land_direction',
            'land_shape',
            'land_deed_status',
            'land_deed_type',
            'land_division_status',
            'land_setback_status',
            'land_ownership'
        ],
        'باغ' => [
            'garden_area',
            'tree_count',
            'tree_types',
            'tree_age',
            'irrigation_type',
            'water_source',
            'water_share',
            'has_well',
            'has_pond',
            'has_building',
            'building_area',
            'document_type'
        ],
        'اداری' => [
            'office_area',
            'office_floor',
            'office_units_per_floor',
            'office_rooms',
            'office_year',
            'office_condition',
            'office_orientation',
            'office_usage'
        ],
        'تجاری' => [
            'area',
            'front',
            'flooring',
            'wall',
            'cabinet',
            'cooling',
            'heating',
            'balcony',
            'basement',
            'balcony_area',
            'basement_area',
            'location_type_1',
            'location_type_2',
            'jobs'
        ]
    ];

    $amenityKeys = [
        'آپارتمان' => ['amenities_apt', 'amenities'],
        'ویلا' => ['amenities_villa', 'amenities'],
        'زمین' => ['land_amenities', 'amenities'],
        'باغ' => ['garden_amenities', 'amenities'],
        'اداری' => ['office_amenities', 'amenities'],
        'تجاری' => ['amenities_comm', 'amenities'],
    ];

    foreach ($ads as &$ad) {

        $ad['id'] = $ad['id'] ?? $ad['ad_id'] ?? uniqid('AD-');
        $ad['ad_id'] = $ad['ad_id'] ?? $ad['id'];

        $ad['transaction_type'] =
            $ad['transaction_type'] ??
            $ad['transactionType'] ??
            '';

        $ad['property_type'] =
            $ad['property_type'] ??
            $ad['propertyType'] ??
            '';

        $ad['gender'] = $ad['gender'] ?? '';
        $ad['last_name'] = $ad['last_name'] ?? '';
        $ad['phone'] = $ad['phone'] ?? '';
        $ad['status'] = $ad['status'] ?? 'pending';

        $ad['price_sell'] = $ad['price_sell'] ?? '';
        $ad['price_condition'] = $ad['price_condition'] ?? '';
        $ad['deposit'] = $ad['deposit'] ?? '';
        $ad['rent_monthly'] = $ad['rent_monthly'] ?? '';
        $ad['full_rent_enabled'] = $ad['full_rent_enabled'] ?? 0;
        $ad['full_rent'] = $ad['full_rent'] ?? '';
        $ad['total_price'] = $ad['total_price'] ?? '';
        $ad['down_payment'] = $ad['down_payment'] ?? '';
        $ad['payment_terms'] = $ad['payment_terms'] ?? '';
        $ad['price_hidden'] = filter_var($ad['price_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
        // فیلدهای وام
        $ad['has_loan'] = $ad['has_loan'] ?? 0;
        $ad['loan_amount'] = $ad['loan_amount'] ?? '';
        $ad['loan_type'] = $ad['loan_type'] ?? '';
        $ad['loan_duration'] = $ad['loan_duration'] ?? '';
        $ad['loan_bank'] = $ad['loan_bank'] ?? '';
        $ad['loan_installment'] = $ad['loan_installment'] ?? '';
        $ad['loan_installments_paid'] = $ad['loan_installments_paid'] ?? '';
        $ad['loan_notes'] = $ad['loan_notes'] ?? '';
        $ad['melkino_visited'] = !empty($ad['melkino_visited']) ? 1 : 0;
        $ad['melkino_rating'] = is_numeric($ad['melkino_rating'] ?? null) ? (float)$ad['melkino_rating'] : 0;
        $ad['melkino_review'] = (string)($ad['melkino_review'] ?? '');

        $ad['location'] = $ad['location'] ?? '';
        $ad['address'] = $ad['address'] ?? '';
        $ad['location_received'] = $ad['location_received'] ?? '0';
        $ad['description'] = $ad['description'] ?? '';
        $ad['publish_photos'] = $ad['publish_photos'] ?? 'yes';
        $ad['created_at'] = $ad['created_at'] ?? date('Y-m-d H:i:s');

        $ad['images'] = normalizeAdminJsonArray($ad['images'] ?? []);

        $ad['selected_images'] =
            normalizeAdminJsonArray(
                $ad['selected_images'] ??
                ($ad['selectedImages'] ?? [])
            );

        $type = $ad['property_type'];

        $amenities = [];

        foreach (($amenityKeys[$type] ?? ['amenities']) as $key) {

            if (!array_key_exists($key, $ad)) {
                continue;
            }

            $candidate =
                normalizeAdminJsonArray($ad[$key]);

            if ($candidate) {
                $amenities = $candidate;
                break;
            }
        }

        if (
            !$amenities &&
            isset($ad['amenities']) &&
            is_string($ad['amenities'])
        ) {
            $decoded =
                json_decode(
                    $ad['amenities'],
                    true
                );

            if (is_array($decoded)) {
                $amenities = $decoded;
            }
        }

        $ad['amenities'] =
            array_values(
                array_unique(
                    array_filter(
                        $amenities,
                        static fn($v) => $v !== ''
                    )
                )
            );

        $details =
            normalizeAdminJsonObject(
                $ad['property_details'] ?? []
            );

        foreach (($propertyFieldMap[$type] ?? []) as $field) {

            if (!array_key_exists($field, $ad)) {
                continue;
            }

            $value = $ad[$field];

            if (
                $value !== '' &&
                $value !== null &&
                $value !== '0' &&
                $value !== 0
            ) {
                $details[$field] = $value;
            }
        }

        $aliases = [
            'آپارتمان' => [
                'area' => 'area_apt',
                'rooms' => 'rooms_apt',
                'year' => 'year_apt',
                'flooring' => 'flooring_apt',
                'cabinet' => 'cabinet_apt',
                'cooling' => 'cooling_apt',
                'heating' => 'heating_apt'
            ],
            'ویلا' => [
                'land_area' => 'land_villa',
                'area' => 'built_villa',
                'rooms' => 'rooms_villa',
                'year' => 'year_villa',
                'flooring' => 'flooring_villa',
                'cabinet' => 'cabinet_villa',
                'cooling' => 'cooling_villa',
                'heating' => 'heating_villa'
            ],
            'تجاری' => [
                'area' => 'area_comm',
                'front' => 'front_comm',
                'flooring' => 'floor_comm',
                'wall' => 'wall_comm',
                'cabinet' => 'cabinet_comm',
                'cooling' => 'cooling_comm',
                'heating' => 'heating_comm',
                'balcony_area' => 'balcony_comm',
                'basement_area' => 'basement_comm',
                'jobs' => 'jobs_comm'
            ],
        ];

        foreach (($aliases[$type] ?? []) as $standard => $source) {

            if (
                (!isset($details[$standard]) ||
                $details[$standard] === '') &&
                isset($ad[$standard]) &&
                $ad[$standard] !== ''
            ) {
                $details[$standard] =
                    $ad[$standard];
            }

            if (
                (!isset($details[$standard]) ||
                $details[$standard] === '') &&
                isset($ad[$source]) &&
                $ad[$source] !== ''
            ) {
                $details[$standard] =
                    $ad[$source];
            }
        }

        $ad['property_details'] = $details;
    }

    unset($ad);

    return $ads;
}

function normalizeAdminJsonArray($value) {

    if (is_array($value)) {
        return $value;
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        return [];
    }

    $decoded =
        json_decode($value, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

function normalizeAdminJsonObject($value) {

    if (
        is_array($value)
    ) {
        return $value;
    }

    if (
        !is_string($value) ||
        trim($value) === ''
    ) {
        return [];
    }

    $decoded =
        json_decode($value, true);

    return is_array($decoded)
        ? $decoded
        : [];
}

if ($isMockMode) {

    $jsonFile =
        __DIR__ . '/ads.json';

    if (file_exists($jsonFile)) {

        $content =
            file_get_contents(
                $jsonFile
            );

        $adsFromJson =
            json_decode(
                $content,
                true
            );

        if (
            is_array($adsFromJson) &&
            count($adsFromJson) > 0
        ) {
            $adsData =
                normalizeAdsData(
                    $adsFromJson
                );
        } else {
            $adsData =
                normalizeAdsData(
                    getMockAds()
                );
        }

    } else {

        $adsData =
            normalizeAdsData(
                getMockAds()
            );
    }

} else {

    try {

        // آمارِ کلی از خودِ دیتابیس گرفته می‌شود تا اعدادِ نوار آمار تب آگهی‌ها حتی
        // وقتی همه‌ی آگهی‌ها لود نشده‌اند، درست و کامل بمانند.
        $adsTotalCount = 0;
        $adsTotals = ['total' => 0, 'pending' => 0, 'published' => 0, 'vip' => 0, 'published_vip' => 0];
        try {
            $adsTotalCount = (int)$pdo->query("SELECT COUNT(*) FROM ads")->fetchColumn();
            $cntRow = $pdo->query(
                "SELECT
                    COALESCE(SUM(status = 'pending'), 0)                     AS c_pending,
                    COALESCE(SUM(status = 'published'), 0)                   AS c_published,
                    COALESCE(SUM(is_vip = 1), 0)                             AS c_vip,
                    COALESCE(SUM(is_vip = 1 AND status = 'published'), 0)    AS c_pub_vip
                   FROM ads"
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($cntRow)) {
                $adsTotals = [
                    'total'         => $adsTotalCount,
                    'pending'       => (int)($cntRow['c_pending'] ?? 0),
                    'published'     => (int)($cntRow['c_published'] ?? 0),
                    'vip'           => (int)($cntRow['c_vip'] ?? 0),
                    'published_vip' => (int)($cntRow['c_pub_vip'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            $adsTotals['total'] = $adsTotalCount;
        }

        if (function_exists('melkinoEnsureRatingColumns')) {
            melkinoEnsureRatingColumns($pdo);
        }
        $adsLimit = max(20, min(2000, (int)MELKINO_ADMIN_ADS_LIMIT));
        $stmt = $pdo->query("SELECT * FROM ads ORDER BY created_at DESC, `id` DESC LIMIT " . $adsLimit);
        $adsFromDB = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $adsLoadedCount = count($adsFromDB);
        $adsHasMore = $adsLoadedCount < $adsTotalCount;

        // تصاویر و امکانات فقط برای همین آگهی‌های لودشده خوانده می‌شوند،
        // نه برای کلِ جدول.
        $loadedAdIds = [];
        foreach ($adsFromDB as $__a) {
            $loadedAdIds[] = (string)$__a['id'];
        }
        $adIdFilter = '';
        $adIdFilterAa = '';
        if ($adsHasMore && !empty($loadedAdIds)) {
            $quotedIds = [];
            foreach ($loadedAdIds as $__id) {
                $quotedIds[] = $pdo->quote($__id);
            }
            $inList = implode(',', $quotedIds);
            $adIdFilter = ' WHERE ad_id IN (' . $inList . ')';
            $adIdFilterAa = ' WHERE aa.ad_id IN (' . $inList . ')';
        }
        // بارگذاری یک‌جای تصاویر و امکانات:
        // قبلاً برای هر آگهی دو کوئری جداگانه اجرا می‌شد (N+1) و با زیاد شدن
        // آگهی‌ها پنل به‌شدت کند می‌شد. حالا فقط دو کوئریِ کلی اجرا می‌شود.
        $imagesByAd = [];
        try {
            $allImages = $pdo->query(
                "SELECT ad_id, filename, sort_order, is_selected, is_primary, publish_publicly
                   FROM images" . $adIdFilter . " ORDER BY sort_order ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allImages as $img) {
                $imagesByAd[(string)$img['ad_id']][] = $img;
            }
        } catch (Throwable $e) {
            $imagesByAd = [];
        }

        $amenitiesByAd = [];
        try {
            $allAmenities = $pdo->query(
                "SELECT aa.ad_id AS ad_id, am.name AS name
                   FROM ad_amenities aa
                   INNER JOIN amenities am ON am.id = aa.amenity_id"
                . $adIdFilterAa .
                " ORDER BY am.sort_order ASC, am.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allAmenities as $am) {
                $amenitiesByAd[(string)$am['ad_id']][] = $am['name'];
            }
        } catch (Throwable $e) {
            $amenitiesByAd = [];
        }

        foreach ($adsFromDB as &$dbAd) {
            $adKey = (string)$dbAd['id'];
            $imgs = $imagesByAd[$adKey] ?? [];
            $dbAd['images'] = array_map(static fn($img) => $img['filename'], $imgs);
            $dbAd['selected_images'] = array_values(array_map(
                static fn($img) => $img['filename'],
                array_filter($imgs, static fn($img) => (int)$img['is_selected'] === 1 && (int)$img['publish_publicly'] === 1)
            ));
            $dbAd['amenities'] = array_values($amenitiesByAd[$adKey] ?? []);
        }
        unset($dbAd);
        $adsData = normalizeAdsData($adsFromDB);

    } catch (Throwable $e) {

        // خطا دیگر پنهان نمی‌شود: هم در لاگ ثبت می‌شود و هم به ادمین
        // نشان داده می‌شود تا بداند پنل به داده‌ی واقعی وصل نیست.
        // متن خطای خام (شامل SQL/مسیر) نمایش داده نمی‌شود؛ فقط لاگ می‌شود.
        error_log('[melkino] ads load failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $adsLoadError = 'اتصال به دادهٔ واقعی آگهی‌ها برقرار نشد؛ جزئیات در لاگ سرور ثبت شد.';
        $adsData =
            normalizeAdsData(
                getMockAds()
            );
    }
}
/* ==========================================================
   راند مشارکت: درخواست‌های «مشارکت در ساخت» هم در لیست آگهی‌های
   تب «آگهی‌ها» دیده شوند (همه آگهی‌ها + در انتظار بررسی).
   این ردیف‌ها فقط نمایشی‌اند (id با پیشوند MKP تا با آگهی‌های
   واقعی قاطی نشوند)؛ مدیریت کامل در تب «مشارکت در ساخت» است.
   ========================================================== */
if (is_array($adsData) && isset($pdo) && ($pdo instanceof PDO)) {
    try {
        $mkpStmt = $pdo->prepare("SELECT * FROM partnership_requests ORDER BY id DESC LIMIT 200");
        $mkpStmt->execute();
        foreach ($mkpStmt->fetchAll(PDO::FETCH_ASSOC) as $mkp) {
            $mkpTitle = trim((string)($mkp['title'] ?? ''));
            if ($mkpTitle === '') {
                $mkpTitle = 'درخواست مشارکت در ساخت #' . (int)$mkp['id'];
            }
            $mkpLocParts = [];
            foreach (['city', 'neighborhood'] as $mkpLocKey) {
                $mkpLocVal = trim((string)($mkp[$mkpLocKey] ?? ''));
                if ($mkpLocVal !== '') {
                    $mkpLocParts[] = $mkpLocVal;
                }
            }
            $adsData[] = [
                'id'               => 'MKP' . (int)$mkp['id'],
                'is_partnership'   => 1,
                'part_id'          => (int)$mkp['id'],
                'part_code'        => (string)($mkp['code'] ?? ''),
                'title'            => $mkpTitle,
                'location'         => implode('، ', $mkpLocParts),
                'property_type'    => (string)($mkp['property_type'] ?? ''),
                'transaction_type' => 'مشارکت در ساخت',
                'status'           => (string)($mkp['status'] ?? 'pending'),
                'last_name'        => (string)($mkp['owner_name'] ?? ''),
                'phone'            => (string)($mkp['phone'] ?? ''),
                'area'             => (string)($mkp['area'] ?? ''),
                'price_sell'       => '0',
                'total_price'      => '0',
                'deposit'          => '0',
                'rent_monthly'     => '0',
                'is_vip'           => 0,
                'selected_images'  => '[]',
                'images'           => '[]',
                'property_details' => [],
                'code'             => (string)($mkp['code'] ?? ''),
                'city'             => (string)($mkp['city'] ?? ''),
                'neighborhood'     => (string)($mkp['neighborhood'] ?? ''),
                'address'          => (string)($mkp['address'] ?? ''),
                'current_status'   => (string)($mkp['current_status'] ?? ''),
                'br_count'         => (string)($mkp['br_count'] ?? ''),
                'direction'        => (string)($mkp['direction'] ?? ''),
                'passage_width'    => (string)($mkp['passage_width'] ?? ''),
                'land_width'       => (string)($mkp['land_width'] ?? ''),
                'permit_status'    => (string)($mkp['permit_status'] ?? ''),
                'density'          => (string)($mkp['density'] ?? ''),
                'occupancy_rate'   => (string)($mkp['occupancy_rate'] ?? ''),
                'buildable_floors' => (string)($mkp['buildable_floors'] ?? ''),
                'buildable_area'   => (string)($mkp['buildable_area'] ?? ''),
                'deed_status'      => (string)($mkp['deed_status'] ?? ''),
                'deed_kind'        => (string)($mkp['deed_kind'] ?? ''),
                'owners_count'     => (string)($mkp['owners_count'] ?? ''),
                'occupancy'        => (string)($mkp['occupancy'] ?? ''),
                'legal_status'     => (string)($mkp['legal_status'] ?? ''),
                'notes'            => (string)($mkp['notes'] ?? ''),
                'latitude'         => (string)($mkp['latitude'] ?? ''),
                'longitude'        => (string)($mkp['longitude'] ?? ''),
                'location_source'  => (string)($mkp['location_source'] ?? ''),
                'doc_deed'         => (string)($mkp['doc_deed'] ?? ''),
                'doc_permit'       => (string)($mkp['doc_permit'] ?? ''),
                'doc_endjob'       => (string)($mkp['doc_endjob'] ?? ''),
                'doc_other'        => (string)($mkp['doc_other'] ?? ''),
                'completeness'     => (string)($mkp['completeness'] ?? ''),
                'photos'           => (string)($mkp['photos'] ?? ''),
                'created_at'       => (string)($mkp['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $mkpE) {
        /* جدول مشارکت هنوز ساخته نشده — بی‌صدا رد شو */
    }
}

/* مشارکت در ساخت هم نوعی آگهی است و در شمارنده‌های تب آگهی‌ها حساب
   می‌شود: «کل آگهی‌ها» و «در انتظار تایید» تا با لیست یکی باشند. */
if (isset($pdo) && ($pdo instanceof PDO) && isset($adsTotals) && is_array($adsTotals)) {
    try {
        $mkpRow = $pdo->query("SELECT COUNT(*) AS c_all, COALESCE(SUM(status = 'pending'), 0) AS c_pending FROM partnership_requests")->fetch(PDO::FETCH_ASSOC);
        if (is_array($mkpRow)) {
            $adsTotals['total']   += (int)($mkpRow['c_all'] ?? 0);
            $adsTotals['pending'] += (int)($mkpRow['c_pending'] ?? 0);
        }
    } catch (Throwable $mkpE) {
        /* جدول مشارکت هنوز ساخته نشده — بی‌صدا */
    }
}

if (!isset($adsLoadedCount)) { $adsLoadedCount = count($adsData); }
if (!isset($adsTotalCount))  { $adsTotalCount  = $adsLoadedCount; }
if (!isset($adsHasMore))     { $adsHasMore     = false; }
if (!isset($adsTotals))      { $adsTotals = ['total' => $adsTotalCount, 'pending' => 0, 'published' => 0, 'vip' => 0, 'published_vip' => 0]; }


// ==============================================
// توابع کمکی آگهی‌ها
// ==============================================

function getStatusLabel($status) {

    $labels = [
        'pending' => 'در انتظار',
        'published' => 'منتشر شده',
        'sold' => 'فروخته شده',
        'suspended' => 'معلق',
        'rejected' => 'رد شده'
    ];

    return $labels[$status] ?? $status;
}

function getStatusClass($status) {

    $classes = [
        'pending' => 'status-pending',
        'published' => 'status-published',
        'sold' => 'status-sold',
        'suspended' => 'status-suspended',
        'rejected' => 'status-rejected'
    ];

    return $classes[$status] ??
        'status-pending';
}

function formatAdminNumber($value): string {
    if ($value === null || $value === '') return '';
    $s = str_replace(['٬', '،', ',', ' '], '', (string)$value);
    if ($s === '' || !is_numeric($s)) return (string)$value;
    $n = (float)$s;
    if (abs($n - round($n)) < 0.000001) {
        return number_format((int)round($n), 0, '.', ',');
    }
    return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
}

function getDisplayPrice($ad) {
    $tx = trim((string)($ad['transaction_type'] ?? ''));

    // پسوند وام برای آگهی‌های وام‌دار (فروش/پیش‌فروش)
    $loanSuffix = '';
    if (!empty($ad['has_loan']) && function_exists('melkinoLoanInfo')) {
        $li = melkinoLoanInfo((array)$ad);
        if (!empty($li['has']) && !empty($li['net'])) {
            $loanSuffix = ' ' . melkinoSvgIcon('bank') . ' (نقد: ' . formatAdminNumber($li['net']) . ' + ' . formatAdminNumber($li['amount']) . ' وام)';
        } elseif (!empty($li['has'])) {
            $loanSuffix = ' ' . melkinoSvgIcon('bank') . ' وام: ' . formatAdminNumber($li['amount']) . ' تومان';
        }
    }

    if ($tx === 'فروش') {
        $value = $ad['price_sell'] ?? null;
        if ($value !== null && (float)$value > 0) {
            return melkinoSvgIcon('coins') . ' فروش: ' . formatAdminNumber($value) . ' تومان' . $loanSuffix;
        }
        return '';
    }

    if ($tx === 'رهن کامل') {
        $value = ($ad['full_rent'] ?? null) ?: ($ad['deposit'] ?? null);
        if ($value !== null && (float)$value > 0) {
            return melkinoSvgIcon('home') . ' رهن کامل: ' . formatAdminNumber($value) . ' تومان';
        }
        return '';
    }

    if ($tx === 'رهن و اجاره' || $tx === 'اجاره') {
        $parts = [];
        $deposit = $ad['deposit'] ?? null;
        $rent = $ad['rent_monthly'] ?? null;
        if ($deposit !== null && (float)$deposit > 0) $parts[] = 'ودیعه: ' . formatAdminNumber($deposit) . ' تومان';
        if ($rent !== null && (float)$rent > 0) $parts[] = 'اجاره: ' . formatAdminNumber($rent) . ' تومان';
        return $parts ? melkinoSvgIcon('home') . ' ' . implode(' | ', $parts) : '';
    }

    if ($tx === 'پیش فروش') {
        $value = $ad['total_price'] ?? null;
        if ($value !== null && (float)$value > 0) {
            return melkinoSvgIcon('list') . ' قیمت کل: ' . formatAdminNumber($value) . ' تومان' . $loanSuffix;
        }
    }

    $value = $ad['price_sell'] ?? $ad['total_price'] ?? null;
    return ($value !== null && (float)$value > 0) ? formatAdminNumber($value) . ' تومان' : '';
}

function getAmenitiesArray($ad) {

    if (
        is_array($ad['amenities'])
    ) {
        return $ad['amenities'];
    }

    if (
        is_string($ad['amenities'])
    ) {

        $decoded =
            json_decode(
                $ad['amenities'],
                true
            );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    return [];
}


// ==============================================
// درخواست‌های ملک
// ==============================================

$requestsData = [];
if (!$isMockMode) {
    try {
        $requestsTotalCount = 0;
        $requestsTotals = ['total' => 0, 'new_count' => 0, 'tracking_count' => 0, 'matched' => 0, 'matches' => 0];
        try {
            $requestsTotalCount = (int)$pdo->query("SELECT COUNT(*) FROM property_requests")->fetchColumn();
            $requestsTotals['total'] = $requestsTotalCount;
            try {
                $reqRow = $pdo->query(
                    "SELECT
                        COALESCE(SUM(status IS NULL OR status = '' OR status = 'new'), 0) AS c_new,
                        COALESCE(SUM(status = 'tracking'), 0) AS c_tracking
                     FROM property_requests"
                )->fetch(PDO::FETCH_ASSOC);
                if (is_array($reqRow)) {
                    $requestsTotals['new_count'] = (int)($reqRow['c_new'] ?? 0);
                    $requestsTotals['tracking_count'] = (int)($reqRow['c_tracking'] ?? 0);
                }
            } catch (Throwable $e2) { /* ستون وضعیت ممکن است وجود نداشته باشد */ }
            try {
                $requestsTotals['matched'] = (int)$pdo->query("SELECT COUNT(DISTINCT request_id) FROM request_matches")->fetchColumn();
                $requestsTotals['matches'] = (int)$pdo->query("SELECT COUNT(*) FROM request_matches")->fetchColumn();
            } catch (Throwable $e3) { /* جدول تطبیق ممکن است هنوز ساخته نشده باشد */ }
        } catch (Throwable $e) {
            $requestsTotalCount = 0;
        }
        $requestsLimit = max(20, min(2000, (int)MELKINO_ADMIN_REQUESTS_LIMIT));
        $stmt = $pdo->query("SELECT * FROM property_requests ORDER BY created_at DESC, id DESC LIMIT " . $requestsLimit);
        $requestsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $requestsLoadedIds = [];
        foreach ($requestsData as $__r) { $requestsLoadedIds[] = (string)$__r['id']; }
        $reqIdFilter = '';
        if (!empty($requestsLoadedIds) && count($requestsData) < $requestsTotalCount) {
            $qIds = [];
            foreach ($requestsLoadedIds as $__id) { $qIds[] = $pdo->quote($__id); }
            $reqIdFilter = ' WHERE request_id IN (' . implode(',', $qIds) . ')';
        }

        $matchStmt = $pdo->query("SELECT request_id, ad_id, match_percent, matched_transaction, matched_property_type, location_score, area_score, budget_score, amenities_score, is_notified FROM request_matches" . $reqIdFilter . " ORDER BY request_id ASC, match_percent DESC, id ASC");
        $matchesByRequest = [];
        foreach ($matchStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $rid = (string)$m['request_id'];
            $matchesByRequest[$rid][] = [
                'ad_id' => (string)$m['ad_id'],
                'match_percent' => (int)$m['match_percent'],
                'matched_transaction' => (int)$m['matched_transaction'],
                'matched_property_type' => (int)$m['matched_property_type'],
                'location_score' => (float)$m['location_score'],
                'area_score' => (float)$m['area_score'],
                'budget_score' => (float)$m['budget_score'],
                'amenities_score' => (float)$m['amenities_score'],
                'is_notified' => (int)$m['is_notified'],
            ];
        }

        $amenitiesByRequest = [];
        try {
            $reqAmenStmt = $pdo->query("SELECT ra.request_id, am.name FROM request_amenities ra LEFT JOIN amenities am ON am.id = ra.amenity_id" . str_replace('request_id', 'ra.request_id', $reqIdFilter) . " ORDER BY ra.request_id ASC, am.sort_order ASC, am.id ASC");
            foreach ($reqAmenStmt->fetchAll(PDO::FETCH_ASSOC) as $ra) {
                $rid = (string)$ra['request_id'];
                $nm = trim((string)($ra['name'] ?? ''));
                if ($nm !== '') {
                    $amenitiesByRequest[$rid][] = $nm;
                }
            }
        } catch (Throwable $eAmen) { }

        $userTg = [];
        $userIds = [];
        foreach ($requestsData as $__reqRow) {
            $uid = (int) ($__reqRow['user_id'] ?? 0);
            if ($uid > 0) {
                $userIds[$uid] = true;
            }
        }
        if ($userIds) {
            try {
                $inU = implode(',', array_map('intval', array_keys($userIds)));
                $uSql = "SELECT id, telegram_id, username FROM users WHERE id IN ($inU)";
                try {
                    $uSql = "SELECT id, telegram_id, username, telegram_username FROM users WHERE id IN ($inU)";
                    $uRows = $pdo->query($uSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                    $uRows = $pdo->query("SELECT id, telegram_id, username FROM users WHERE id IN ($inU)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
                foreach ($uRows as $uRow) {
                    $userTg[(int) $uRow['id']] = $uRow;
                }
            } catch (Throwable $e) {
            }
        }

        foreach ($requestsData as &$req) {
            $rid = (string)$req['id'];
            $uid = (int) ($req['user_id'] ?? 0);
            if ($uid && isset($userTg[$uid])) {
                if (trim((string) ($req['telegram_id'] ?? '')) === '') {
                    $req['telegram_id'] = $userTg[$uid]['telegram_id'] ?? '';
                }
                if (trim((string) ($req['username'] ?? '')) === '') {
                    $req['username'] = $userTg[$uid]['telegram_username'] ?? $userTg[$uid]['username'] ?? '';
                }
            }
            $details = [];
            if (!empty($req['property_details'])) {
                $decoded = json_decode((string)$req['property_details'], true);
                if (is_array($decoded)) $details = $decoded;
            }
            $req['followup_note'] = (string)($details['followup_note'] ?? '');
            $req['matches'] = $matchesByRequest[$rid] ?? [];
            $amenList = $amenitiesByRequest[$rid] ?? [];
            if (!$amenList && !empty($details['amenities']) && is_array($details['amenities'])) {
                $amenList = $details['amenities'];
            }
            $req['amenities'] = $amenList;

            // ترکیب‌های چندفایلی که به کاربر پیشنهاد شده (my-request-matches.php آن‌ها
            // را در ستون additional ذخیره می‌کند) — قبلاً در پنل ادمین نمایش داده نمی‌شدند.
            $additionalData = [];
            if (!empty($req['additional'])) {
                $decodedAdditional = json_decode((string)$req['additional'], true);
                if (is_array($decodedAdditional)) $additionalData = $decodedAdditional;
            }
            $req['combinations'] = is_array($additionalData['combinations'] ?? null) ? $additionalData['combinations'] : [];
            if (empty($req['amenities']) && !empty($additionalData['amenities'])) {
                $am = $additionalData['amenities'];
                $req['amenities'] = is_array($am) ? $am : preg_split('/[،,]+/u', (string) $am);
                $req['amenities'] = array_values(array_filter(array_map('trim', $req['amenities'])));
            }

            unset($req['additional'], $req['property_details']);
        }
        unset($req);
    } catch (Throwable $e) {
        $requestsData = [];
    }
}
?>

<?php
// =========================================================
// بارگذاریِ مرحله‌ایِ آگهی‌ها
// =========================================================
// وقتی تعداد آگهی‌ها از سقفِ لودِ اولیه بیشتر باشد، دکمه‌ی
// «بارگذاری بقیه» در پنل ظاهر می‌شود و ادامه‌ی آگهی‌ها را در بسته‌های
// ۲۰۰تایی از همین مسیر می‌گیرد. این کار باعث می‌شود پنل همیشه سبک و
// سریع بالا بیاید و در عین حال ادمین به همه‌ی آگهی‌ها دسترسی داشته باشد.
if (
    ($pdo instanceof PDO)
    && (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET')
    && (($_GET['action'] ?? '') === 'ads_chunk')
) {
    $__offset = max(0, (int)($_GET['offset'] ?? 0));
    $__limit  = max(1, min(200, (int)($_GET['limit'] ?? 200)));

    try {
        $__total = (int)$pdo->query("SELECT COUNT(*) FROM ads")->fetchColumn();
        $__rows  = $pdo->query(
            "SELECT * FROM ads ORDER BY created_at DESC, `id` DESC LIMIT "
            . $__limit . " OFFSET " . $__offset
        )->fetchAll(PDO::FETCH_ASSOC);

        $__ids = [];
        foreach ($__rows as $__r) {
            $__ids[] = (string)$__r['id'];
        }
        $__in = '';
        if (!empty($__ids)) {
            $__q = [];
            foreach ($__ids as $__id) {
                $__q[] = $pdo->quote($__id);
            }
            $__in = implode(',', $__q);
        }

        $__imagesByAd = [];
        if ($__in !== '') {
            foreach (
                $pdo->query(
                    "SELECT ad_id, filename, sort_order, is_selected, is_primary, publish_publicly
                       FROM images WHERE ad_id IN (" . $__in . ")
                      ORDER BY sort_order ASC, id ASC"
                )->fetchAll(PDO::FETCH_ASSOC) as $__img
            ) {
                $__imagesByAd[(string)$__img['ad_id']][] = $__img;
            }
        }

        $__amenitiesByAd = [];
        if ($__in !== '') {
            foreach (
                $pdo->query(
                    "SELECT aa.ad_id AS ad_id, am.name AS name
                       FROM ad_amenities aa
                       INNER JOIN amenities am ON am.id = aa.amenity_id
                      WHERE aa.ad_id IN (" . $__in . ")
                      ORDER BY am.sort_order ASC, am.id ASC"
                )->fetchAll(PDO::FETCH_ASSOC) as $__am
            ) {
                $__amenitiesByAd[(string)$__am['ad_id']][] = $__am['name'];
            }
        }

        foreach ($__rows as &$__r) {
            $__k    = (string)$__r['id'];
            $__imgs = $__imagesByAd[$__k] ?? [];
            $__r['images'] = array_map(static fn($i) => $i['filename'], $__imgs);
            $__r['selected_images'] = array_values(array_map(
                static fn($i) => $i['filename'],
                array_filter($__imgs, static fn($i) => (int)$i['is_selected'] === 1 && (int)$i['publish_publicly'] === 1)
            ));
            $__r['amenities'] = array_values($__amenitiesByAd[$__k] ?? []);
        }
        unset($__r);

        adminPanelJsonResponse([
            'success' => true,
            'ads'     => normalizeAdsData($__rows),
            'offset'  => $__offset,
            'count'   => count($__rows),
            'total'   => $__total,
            'hasMore' => ($__offset + count($__rows)) < $__total,
        ]);
    } catch (Throwable $__e) {
        adminPanelJsonResponse([
            'success' => false,
            'message' => melkinoSafeError($__e, 'admin-panel.ads_load', 'بارگذاری آگهی‌ها انجام نشد.'),
        ], 500);
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
<link rel="stylesheet" href="assets/css/admin-publishing.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/admin-publishing.css') ?>">
    <!-- راند ۴۰ (S10): بوت‌استرپ تم — پنل هم مثل صفحات عمومی تم ذخیره‌شده را اعمال می‌کند -->
    <script>
    (function () {
        try {
            var savedTheme = localStorage.getItem('melkino_theme');
            var melkinoDefaultTheme = 'light';
            <?php try { $mdt = dbSettingGet($pdo, 'global', 'default_theme', 'light'); } catch (Throwable $e) { $mdt = 'light'; } ?>
            melkinoDefaultTheme = <?= json_encode($mdt === 'dark' ? 'dark' : 'light') ?>;
            document.documentElement.setAttribute('data-theme', savedTheme === 'dark' ? 'dark' : (savedTheme === 'light' ? 'light' : melkinoDefaultTheme));
        } catch (e) {
            document.documentElement.setAttribute('data-theme', 'light');
        }
        window.melkinoTheme = window.melkinoTheme || {
            get: function () { return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'; },
            set: function (t) {
                t = t === 'dark' ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', t);
                try { localStorage.setItem('melkino_theme', t); } catch (e) {}
            }
        };
    })();
    </script>

    <style>
    .mk-theme-switch{display:inline-flex;gap:4px;padding:4px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);align-self:center}
    .mk-theme-btn{border:0;background:transparent;color:inherit;padding:7px 14px;border-radius:999px;font:inherit;font-size:12px;font-weight:800;cursor:pointer;opacity:.85}
    .mk-theme-btn:hover{opacity:1}
    .mk-theme-btn[aria-pressed="true"]{background:#f4d978;color:#0b2b23;opacity:1}
    .mk-theme-btn:focus-visible{outline:2px solid #f4d978;outline-offset:2px}
    .mk-theme-btn--icon{display:inline-flex;align-items:center;justify-content:center;padding:7px 9px;line-height:0}
    [data-theme="dark"] .admin-body input:not([type="checkbox"]):not([type="radio"]):not([type="range"]),
    [data-theme="dark"] .admin-body select,
    [data-theme="dark"] .admin-body textarea{background:#0f2021;color:#e8f1ef;border-color:#2b4a48}
    [data-theme="dark"] .admin-body table th{background:#10292a;color:#e8f1ef}
    [data-theme="dark"] .admin-body table td{border-color:#22403e;color:#dbe7e4}
    [data-theme="dark"] .admin-body select option{background:#0f2021;color:#e8f1ef}
    </style>
    <script>
    function mkAdminSyncThemeButtons() {
        var cur = (window.melkinoTheme && window.melkinoTheme.get) ? window.melkinoTheme.get() : 'light';
        var btns = document.querySelectorAll('[data-mk-theme-btn]');
        for (var i = 0; i < btns.length; i++) {
            var on = btns[i].getAttribute('data-mk-theme-btn') === cur;
            btns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
    }
    function mkAdminSetTheme(t) {
        if (window.melkinoTheme && window.melkinoTheme.set) window.melkinoTheme.set(t === 'dark' ? 'dark' : 'light');
        mkAdminSyncThemeButtons();
    }
    document.addEventListener('DOMContentLoaded', mkAdminSyncThemeButtons);
    </script>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover"
    >

    <!-- راند ۲۱: اعلام صریح پشتیبانی تم روشن/تیره — وقتی صفحه طرح خودش را اعلام
         نکند، مرورگر داخلی بله/تلگرام (اندروید) Force Dark را روشن می‌کند و روی
         تم تیرهٔ خود پنل دوگذاری می‌شود: کارت‌ها یک‌باره تیره، یک‌باره روشن می‌شوند -->
    <meta name="color-scheme" content="light dark">
    <style>:root{color-scheme:light}:root[data-theme="dark"]{color-scheme:dark}</style>

    <title>
        ملکینو - پنل مدیریت
    </title>

    <link
        href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"
        rel="stylesheet" media="print" onload="this.media='all'"
        type="text/css"
    />

    <link
        rel="stylesheet"
        href="style.css"
    >
    <link rel="stylesheet" href="admin/shell.css?v=<?php echo (int) @filemtime(__DIR__ . '/admin/shell.css'); ?>">

<style>

.admin-body {
    background: var(--bg);
}

.main-content {
    flex: 1;
    overflow-y: auto;
    background: var(--bg);
    padding: 0 var(--space-3) var(--space-3);
    display: flex;
    flex-direction: column;
}

.tabs-container {
    display: flex;
    border-bottom: 2px solid var(--border);
    margin-bottom: var(--space-3);
    background: var(--surface);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    padding: 0 var(--space-2);
    overflow-x: auto;
    flex-shrink: 0;
}

.tab-btn {
    flex: 0 0 auto;
    padding: var(--space-2);
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    font-weight: 700;
    color: var(--text-secondary);
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    font-size: 14px;
    transition: 0.2s;
    white-space: nowrap;
}

.tab-btn.active {
    color: var(--primary);
    border-bottom: 3px solid var(--primary);
    background: rgba(6, 78, 78, 0.05);
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease forwards;
}

.tab-content.active {
    display: flex;
    flex: 1;
    flex-direction: column;
}

.admin-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border);
    margin-bottom: var(--space-2);
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-2);
    flex-wrap: wrap;
    gap: var(--space-1);
}

.card-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: var(--space-1);
}

.option-tag {
    display: inline-flex;
    align-items: center;
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 4px 12px;
    border-radius: 20px;
    margin: var(--space-1) var(--space-1) 0 0;
    font-size: 13px;
    gap: 8px;
}

.option-tag button {
    background: none;
    border: none;
    color: var(--danger);
    cursor: pointer;
    font-size: 14px;
    padding: 0;
    display: flex;
    align-items: center;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 20000;
    backdrop-filter: blur(4px);
}

.modal-overlay.active {
    display: flex;
}

.modal-box {
    background: var(--surface);
    width: 95%;
    max-width: 700px;
    border-radius: var(--radius-md);
    padding: var(--space-3);
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    position: relative;
    max-height: 95vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-2);
    border-bottom: 1px solid var(--border);
    padding-bottom: var(--space-2);
}

.modal-header h3 {
    font-size: 20px;
    font-weight: 800;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-secondary);
}

.form-row-group {
    display: flex;
    flex-direction: column;
    gap: var(--space-1);
    margin-bottom: var(--space-2);
}

.form-row-group label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
}

.form-row-group input,
.form-row-group select,
.form-row-group textarea {
    width: 100%;
    padding: var(--space-1);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: 'Vazirmatn', sans-serif;
    font-size: 14px;
    background: var(--bg);
    color: var(--text-primary);
}

.form-row-group textarea {
    min-height: 60px;
    resize: vertical;
}

.btn-icon-sm {
    background: var(--bg);
    border: 1px solid var(--border);
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: 0.2s;
    font-family: 'Vazirmatn', sans-serif;
    font-weight: 600;
    font-size: 13px;
}

.btn-icon-sm.primary {
    background: var(--primary);
    color: #fff;
    border: none;
}

.btn-icon-sm.gold {
    background: var(--gold);
    color: #111827;
    border: none;
}

.btn-icon-sm.danger {
    color: var(--danger);
    border-color: var(--danger);
}

.btn-icon-sm.success {
    background: #059669;
    color: #fff;
    border: none;
}

.btn-icon-sm.teal {
    background: #0088cc;
    color: #fff;
    border: none;
}

.btn-save {
    background: var(--primary);
    color: #fff;
    width: 100%;
    height: 56px;
    border: none;
    border-radius: var(--radius-md);
    font-weight: 800;
    font-size: 18px;
    margin-top: var(--space-2);
    cursor: pointer;
}

.btn-save:active {
    transform: scale(0.98);
}

.ad-filter {
    display: flex;
    gap: var(--space-1);
    margin-bottom: var(--space-2);
    flex-wrap: wrap;
}

.ad-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    box-shadow: var(--shadow-card);
    border: 1px solid var(--border);
    margin-bottom: var(--space-2);
}

.ad-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--space-1);
}

.ad-title {
    font-weight: 700;
    font-size: 16px;
    color: var(--text-primary);
}

.ad-status {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-pending {
    background: #FEF3C7;
    color: #D97706;
}

.status-published {
    background: #D1FAE5;
    color: #059669;
}

.status-sold {
    background: #DBEAFE;
    color: #2563EB;
}

.status-suspended {
    background: #FEE2E2;
    color: #DC2626;
}

.status-rejected {
    background: #FEE2E2;
    color: #DC2626;
}

.ad-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
    font-size: 14px;
    margin: var(--space-1) 0;
}

.ad-info-grid .info-item {
    display: flex;
    justify-content: space-between;
    padding: 2px 0;
    border-bottom: 1px dashed var(--border);
}

.ad-info-grid .info-label {
    color: var(--text-secondary);
    font-weight: 500;
}

.ad-info-grid .info-value {
    color: var(--text-primary);
    font-weight: 600;
}

.ad-price {
    font-size: 16px;
    color: var(--gold);
    font-weight: 700;
    margin: var(--space-1) 0;
}

.ad-actions {
    display: flex;
    gap: var(--space-1);
    flex-wrap: wrap;
    margin-top: var(--space-1);
    border-top: 1px solid var(--border);
    padding-top: var(--space-1);
}

.btn-sm-ad {
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    border: none;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    transition: all 0.2s;
}

.btn-sm-ad:active {
    transform: scale(0.95);
}

.btn-sm-ad.approve {
    background: #059669;
    color: #fff;
}

.btn-sm-ad.edit {
    background: var(--primary);
    color: #fff;
}

.btn-sm-ad.delete {
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}

.btn-sm-ad.sold {
    background: #3B82F6;
    color: #fff;
}

.btn-sm-ad.suspend {
    background: #F59E0B;
    color: #fff;
}

.btn-sm-ad.republish {
    background: #8B5CF6;
    color: #fff;
}

.btn-sm-ad.telegram {
    background: #0088cc;
    color: #fff;
}

.btn-secondary {
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    padding: 6px 12px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    font-weight: 600;
    font-size: 13px;
}

.btn-primary-full {
    width: 100%;
    height: 48px;
    border-radius: var(--radius-md);
    border: none;
    background: var(--primary);
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    font-family: 'Vazirmatn', sans-serif;
    transition: all 0.2s;
}

.btn-primary-full:active {
    transform: scale(0.98);
}

.empty-state-ads {
    text-align: center;
    padding: var(--space-4);
    color: var(--text-secondary);
}

.empty-state-ads svg {
    margin-bottom: var(--space-2);
}

.empty-state-ads h3 {
    color: var(--text-primary);
    margin-top: var(--space-2);
}

.badge-mock {
    background: #F59E0B;
    color: #fff;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.price-section {
    display: none;
    padding: var(--space-1);
    background: var(--bg);
    border-radius: var(--radius-sm);
    margin-bottom: var(--space-2);
}

.price-section.active {
    display: block;
}

.checkbox-group {
    display: flex;
    gap: var(--space-2);
    flex-wrap: wrap;
    margin-top: var(--space-1);
}

.checkbox-group label {
    font-weight: 400;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.section-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--primary);
    margin: var(--space-2) 0 var(--space-1);
    border-bottom: 1px solid var(--border);
    padding-bottom: var(--space-1);
}

.row-half {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-2);
}

.img-check-grid {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-1);
}

.img-check-item {
    position: relative;
    width: 80px;
    height: 80px;
    border: 2px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    display: flex;
    justify-content: center;
    align-items: center;
    background: var(--bg);
}

.img-check-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.img-check-item input {
    position: absolute;
    top: 4px;
    left: 4px;
    accent-color: var(--primary);
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.img-check-item .img-label {
    position: absolute;
    bottom: 2px;
    right: 2px;
    font-size: 11px;
    color: #fff;
    background: rgba(0,0,0,0.6);
    padding: 1px 6px;
    border-radius: 4px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: var(--space-2);
    margin-bottom: var(--space-3);
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius-md);
    padding: var(--space-2);
    text-align: center;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-card);
}

.stat-card .number {
    font-size: 28px;
    font-weight: 800;
    color: var(--primary);
}

.stat-card .label {
    font-size: 14px;
    color: var(--text-secondary);
    margin-top: 4px;
}

.ads-toolbar {
    display:grid;
    grid-template-columns:minmax(220px,2fr) repeat(3,minmax(130px,1fr));
    gap:10px;
    margin-bottom:12px;
}

.ads-toolbar input,
.ads-toolbar select {
    width:100%;
    min-height:42px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--bg);
    color:var(--text-primary);
    padding:0 12px;
    font-family:'Vazirmatn',sans-serif;
}

.ads-toolbar-actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
    margin-bottom:12px;
}

.bulk-bar {
    display:none;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:10px;
    margin-bottom:12px;
}

.bulk-bar.active {
    display:flex;
}

.bulk-actions {
    display:flex;
    gap:7px;
    flex-wrap:wrap;
}

.btn-filter {
    background:var(--bg);
    color:var(--text-secondary);
    border:1px solid var(--border);
    padding:7px 12px;
    border-radius:8px;
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
    font-weight:600;
}

.btn-filter.active {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.ads-table-wrap {
    overflow-x:auto;
    border:1px solid var(--border);
    border-radius:12px;
    background:var(--surface);
}

.ads-table {
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}

.ads-table th,
.ads-table td {
    padding:10px 9px;
    border-bottom:1px solid var(--border);
    text-align:right;
    vertical-align:middle;
    font-size:13px;
}

.ads-table th {
    background:var(--bg);
    color:var(--text-secondary);
    font-weight:700;
    white-space:nowrap;
    position:sticky;
    top:0;
    z-index:1;
}

.ads-table tr:last-child td {
    border-bottom:none;
}

.ads-table tr:hover td {
    background:rgba(6,78,78,.03);
}

.ad-row-main {
    display:flex;
    align-items:center;
    gap:9px;
    min-width:270px;
}

.ad-row-thumb {
    width:54px;
    height:44px;
    border-radius:8px;
    object-fit:cover;
    border:1px solid var(--border);
    background:var(--bg);
    flex:0 0 auto;
}

.ad-row-title {
    font-weight:800;
    color:var(--text-primary);
    line-height:1.5;
}

.ad-row-sub {
    color:var(--text-secondary);
    font-size:11px;
    margin-top:2px;
}

.table-actions {
    display:flex;
    gap:5px;
    align-items:center;
    flex-wrap:wrap;
}

.table-action {
    position:relative;
    width:34px;
    height:34px;
    border-radius:8px;
    border:1px solid var(--border);
    background:var(--bg);
    color:var(--text-primary);
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
}

.table-action:hover {
    background:var(--surface);
}

.table-action.primary {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.table-action.danger {
    color:var(--danger);
}

.pagination-bar {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-top:12px;
    flex-wrap:wrap;
}

.pagination {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.page-btn {
    min-width:36px;
    height:36px;
    border-radius:8px;
    border:1px solid var(--border);
    background:var(--bg);
    cursor:pointer;
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
}

.page-btn.active {
    background:var(--primary);
    color:#fff;
    border-color:var(--primary);
}

.selection-check {
    width:17px;
    height:17px;
    accent-color:var(--primary);
    cursor:pointer;
}

.empty-table {
    text-align:center;
    padding:48px 20px;
    color:var(--text-secondary);
}

.ad-quick-status {
    font-size:11px;
    color:var(--text-secondary);
    margin-top:3px;
}

@media (max-width: 900px) {
    .ads-toolbar {
        grid-template-columns:1fr 1fr;
    }
}

@media (max-width: 600px) {
    .ads-toolbar {
        grid-template-columns:1fr;
    }

    .bulk-bar {
        flex-direction:column;
        align-items:stretch;
    }
}


/* =========================================================
   ویرایش حرفه‌ای آگهی
   ========================================================= */

#adEditModal .modal-box {
    max-width: 1050px;
    width: 96%;
    padding: 0;
    overflow: hidden;
    /* زنجیره‌ی flex ستونی: هدر ثابت، محتوا بقیه‌ی فضا را می‌گیرد
       و فقط .edit-pro-body اسکرول می‌شود تا فوتر (دکمه‌ی ذخیره)
       همیشه داخل قاب بماند. */
    display:flex;
    flex-direction:column;
}

#adEditModal .modal-header,
#adDetailModal .modal-header {
    flex-shrink:0;
}

.edit-shell {
    display:flex;
    flex-direction:column;
    max-height:90vh;
}

.edit-top {
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:center;
}

.edit-top-title {
    font-weight:800;
    font-size:18px;
    color:var(--text-primary);
}

.edit-top-meta {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.edit-tabs {
    display:flex;
    gap:4px;
    padding:8px 12px;
    overflow-x:auto;
    border-bottom:1px solid var(--border);
    background:var(--bg);
}

.edit-tab {
    border:1px solid transparent;
    background:transparent;
    color:var(--text-secondary);
    padding:9px 13px;
    border-radius:9px;
    cursor:pointer;
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    font-weight:700;
    white-space:nowrap;
}

.edit-tab.active {
    background:var(--surface);
    color:var(--primary);
    border-color:var(--border);
    box-shadow:0 2px 8px rgba(0,0,0,.04);
}

.edit-body {
    overflow:auto;
    padding:16px;
    background:var(--bg);
}

.edit-pane {
    display:none;
}

.edit-pane.active {
    display:block;
}

.edit-section {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:14px;
}

.edit-section-title {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-bottom:13px;
    font-size:14px;
    font-weight:800;
    color:var(--text-primary);
}

.edit-section-title span {
    color:var(--primary);
}

.edit-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:11px;
}

.edit-field {
    display:flex;
    flex-direction:column;
    gap:6px;
}

.edit-field.full {
    grid-column:1/-1;
}

.edit-field label {
    font-size:12px;
    color:var(--text-secondary);
    font-weight:700;
}

.edit-field input,
.edit-field select,
.edit-field textarea {
    width:100%;
    box-sizing:border-box;
    padding:9px 10px;
    border:1px solid var(--border);
    border-radius:8px;
    background:var(--bg);
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
}

.edit-field textarea {
    min-height:100px;
    resize:vertical;
}

.edit-field input:focus,
.edit-field select:focus,
.edit-field textarea:focus {
    border-color:var(--primary);
    box-shadow:0 0 0 2px rgba(6,78,78,.08);
}

/* خط «به حروف» زیر فیلدهای مبلغ (راند ۱۰) */
.edit-money-words {
    margin-top:4px;
    min-height:16px;
    font-size:11.5px;
    line-height:1.7;
    color:var(--text-secondary, #6b7280);
    letter-spacing:0;
}

.edit-checks {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.edit-check {
    display:flex;
    align-items:center;
    gap:7px;
    padding:8px 9px;
    border:1px solid var(--border);
    border-radius:8px;
    background:var(--bg);
    font-size:12px;
    cursor:pointer;
}

.edit-check input {
    width:16px;
    height:16px;
    accent-color:var(--primary);
}

.edit-image-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(100px,1fr));
    gap:10px;
}

.edit-image {
    position:relative;
    border:1px solid var(--border);
    border-radius:9px;
    overflow:hidden;
    background:var(--bg);
}

.edit-image img {
    width:100%;
    aspect-ratio:1/1;
    object-fit:cover;
    display:block;
}

.edit-image label {
    display:flex;
    align-items:center;
    gap:6px;
    padding:7px;
    font-size:11px;
}

.edit-image input {
    accent-color:var(--primary);
}

.edit-image.empty {
    min-height:110px;
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--text-secondary);
    font-size:12px;
}

.edit-footer {
    padding:12px 16px;
    border-top:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
}

.edit-footer-actions {
    display:flex;
    gap:8px;
}

.edit-footer .btn-secondary,
.edit-footer .btn-primary-full {
    width:auto;
    min-width:130px;
    padding:0 18px;
}

.edit-note {
    font-size:11px;
    color:var(--text-secondary);
}

.edit-inline {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.edit-badge {
    display:inline-flex;
    align-items:center;
    padding:4px 8px;
    border-radius:999px;
    background:var(--bg);
    border:1px solid var(--border);
    font-size:11px;
    color:var(--text-secondary);
}

@media (max-width:850px) {

    .edit-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-checks {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-image-grid {
        grid-template-columns:repeat(3,minmax(90px,1fr));
    }
}

@media (max-width:560px) {

    #adEditModal.modal-overlay {
        padding:0;
    }

    #adEditModal .modal-box {
        width:100%;
        height:100vh;
        max-height:100vh;
        height:100dvh;
        max-height:100dvh;
        border-radius:0;
        padding:0;
    }

    .edit-shell {
        max-height:100vh;
        max-height:100dvh;
    }

    .edit-grid {
        grid-template-columns:1fr;
    }

    .edit-checks {
        grid-template-columns:1fr 1fr;
    }

    .edit-image-grid {
        grid-template-columns:repeat(2,minmax(90px,1fr));
    }

    .edit-footer {
        flex-direction:column;
        align-items:stretch;
    }

    .edit-footer-actions {
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .edit-footer-actions button {
        width:100%!important;
    }
}

#adDetailModal .modal-box {
    max-width:1050px;
    width:96%;
    padding:0;
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.detail-shell {
    background:var(--bg);
    max-height:88vh;
    overflow:auto;
    padding:18px;
    /* اسکرول‌شونده‌ی اصلیِ مودال جزئیات؛ در قاب موبایل باقی‌ی
       ارتفاع را می‌گیرد به‌جای ارتفاع مطلق. */
    flex:1 1 auto;
    min-height:0;
}

.detail-hero {
    display:flex;
    justify-content:space-between;
    gap:12px;
    align-items:flex-start;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:12px;
}

.detail-title {
    font-size:20px;
    font-weight:900;
    color:var(--text-primary);
}

.detail-sub {
    font-size:12px;
    color:var(--text-secondary);
    margin-top:4px;
}

.detail-section {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:12px;
    padding:15px;
    margin-bottom:12px;
}

.detail-section-title {
    font-size:14px;
    font-weight:900;
    color:var(--primary);
    margin-bottom:12px;
}

.detail-kv-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}

.detail-kv {
    border:1px solid var(--border);
    background:var(--bg);
    border-radius:9px;
    padding:9px 10px;
    display:flex;
    flex-direction:column;
    gap:5px;
}

.detail-kv span {
    font-size:11px;
    color:var(--text-secondary);
}

.detail-kv strong {
    font-size:13px;
    color:var(--text-primary);
    word-break:break-word;
}

.detail-tags {
    display:flex;
    flex-wrap:wrap;
    gap:7px
}

.detail-tag {
    padding:6px 9px;
    border-radius:999px;
    background:var(--bg);
    border:1px solid var(--border);
    font-size:12px;
}

.detail-muted {
    color:var(--text-secondary);
    font-size:12px;
}

.detail-description {
    white-space:normal;
    line-height:2;
    font-size:13px;
    color:var(--text-primary)
}

.detail-policy,
.image-choice-banner {
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:9px;
    padding:10px;
    font-size:12px;
    line-height:1.9;
    margin-bottom:12px;
}

.detail-policy small,
.image-choice-banner small {
    display:block;
    color:var(--text-secondary);
}

.image-choice-banner.yes {
    border-color:#10b981;
}

.image-choice-banner.no {
    border-color:#ef4444;
}

.detail-gallery,
.manage-image-grid {
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:10px;
}

.detail-image-card,
.manage-image {
    background:var(--bg);
    border:2px solid var(--border);
    border-radius:10px;
    overflow:hidden;
}

.detail-image-card.is-selected,
.manage-image.selected {
    border-color:var(--primary);
}

.detail-image-card img,
.manage-image img {
    width:100%;
    aspect-ratio:1/1;
    object-fit:cover;
    display:block;
}

.detail-image-meta,
.manage-image-footer {
    padding:7px;
    font-size:11px;
    display:flex;
    justify-content:space-between;
    gap:6px;
    align-items:center;
}

.detail-image-meta b {
    color:var(--primary);
}

.detail-empty {
    padding:25px;
    text-align:center;
    color:var(--text-secondary);
    border:1px dashed var(--border);
    border-radius:10px;
}

.detail-actions {
    display:flex;
    justify-content:flex-start;
    gap:8px;
    flex-wrap:wrap;
}

.admin-image-controls {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:10px;
}

.edit-bool {
    display:flex;
    align-items:center;
    gap:8px;
    border:1px solid var(--border);
    background:var(--bg);
    padding:9px 10px;
    border-radius:8px;
    height:38px;
    box-sizing:border-box;
}

.edit-bool input {
    accent-color:var(--primary);
}

.edit-pro-shell {
    display:flex;
    flex-direction:column;
    max-height:90vh;
    /* در موبایل ارتفاع مطلق (100dvh) نمی‌گیرد؛ به‌جای آن بقیه‌ی
       ارتفاعِ قاب مودال را می‌گیرد تا هدر مودال + فوتر جا بشوند
       و دکمه‌ی «ذخیره تغییرات» بریده نشود. */
    flex:1 1 auto;
    min-height:0;
}

.edit-pro-head {
    padding:16px 18px;
    border-bottom:1px solid var(--border);
    background:var(--surface);
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
}

.edit-pro-head h2 {
    margin:0;
    font-size:18px;
    color:var(--text-primary);
}

.edit-pro-head p {
    margin:5px 0 0;
    color:var(--text-secondary);
    font-size:11px;
}

.edit-pro-body {
    overflow:auto;
    /* بدون flex:1 و min-height:0، محتوای بلند مودال را از ارتفاع
       بیشتر از shell بزرگ‌تر می‌کرد و فوتر (دکمه‌ی ذخیره) خارج از
       دید می‌رفت و با overflow:hidden روی modal-box بریده می‌شد. */
    flex:1 1 auto;
    min-height:0;
    background:var(--bg);
    padding:15px;
}

.edit-pro-footer {
    padding:12px 15px;
    background:var(--surface);
    border-top:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}

.edit-pro-footer>div {
    display:flex;
    gap:8px;
}

.edit-price-box {
    display:none;
}

.edit-price-box.active {
    display:block;
}

.edit-description {
    min-height:160px;
}

.manage-image-footer label {
    display:flex;
    align-items:center;
    gap:5px;
    font-size:11px;
    white-space:nowrap;
    cursor:pointer;
    color:var(--text-secondary);
}

.manage-image-footer input {
    accent-color:var(--primary);
}

/* ---- مدیریت دسته‌جمعی تصاویر در مودال ویرایش ---- */
.img-bulk-bar {
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:10px;
    padding:9px 11px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:10px;
}

.img-bulk-all {
    display:flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    font-weight:700;
    color:var(--text-primary);
    cursor:pointer;
}

.img-bulk-all input {
    accent-color:#dc2626;
    width:16px;
    height:16px;
}

.img-bulk-count {
    font-size:11px;
    color:var(--text-secondary);
}

.img-bulk-btn {
    margin-inline-start:auto;
}

.btn-danger {
    height:36px;
    padding:0 14px;
    border:none;
    border-radius:8px;
    background:#dc2626;
    color:#fff;
    font-family:'Vazirmatn',sans-serif;
    font-size:12px;
    font-weight:700;
    cursor:pointer;
}

.btn-danger:disabled {
    opacity:.45;
    cursor:not-allowed;
}

.btn-danger:not(:disabled):active {
    transform:scale(.98);
}

.manage-image-checks {
    display:flex;
    align-items:center;
    gap:10px;
}

.img-delete-label input {
    accent-color:#dc2626;
}

@media(max-width:850px) {

    .detail-kv-grid {
        grid-template-columns:1fr 1fr;
    }

    .detail-gallery,
    .manage-image-grid {
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
}

@media(max-width:560px) {

    #adDetailModal.modal-overlay,
    #adEditModal.modal-overlay {
        padding:0;
    }

    #adDetailModal .modal-box,
    #adEditModal .modal-box {
        width:100%;
        height:100vh;
        max-height:100vh;
        height:100dvh;
        max-height:100dvh;
        border-radius:0;
        padding:0;
    }

    /* بدون ارتفاع مطلق: هر دو shell باقی‌ی ارتفاعِ قاب (که 100dvh
       است) را می‌گیرند و فقط محتوای داخلی‌شان اسکرول می‌شود؛
       در نتیجه فوتر و دکمه‌ی ذخیره همیشه دیده می‌شوند. */
    .detail-shell,
    .edit-pro-shell {
        flex:1 1 auto;
        min-height:0;
        max-height:none;
    }

    .detail-kv-grid,
    .edit-grid {
        grid-template-columns:1fr;
    }

    .detail-gallery,
    .manage-image-grid {
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .edit-pro-footer {
        flex-direction:column;
        align-items:stretch;
    }

    .edit-pro-footer>div {
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .detail-actions {
        display:grid;
        grid-template-columns:1fr;
    }

    .detail-actions button {
        width:100%!important;
    }
}


/* =========================================================
   درخواست‌های ملک
   ========================================================= */

.requests-toolbar {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.requests-toolbar input,
.requests-toolbar select {
    height:42px;
    box-sizing:border-box;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--bg);
    color:var(--text-primary);
    padding:0 12px;
    font-family:'Vazirmatn',sans-serif
}

.requests-toolbar input {
    flex:1;
    min-width:220px
}

.requests-toolbar select {
    min-width:150px
}

.request-admin-card {
    border:1px solid var(--border);
    border-radius:14px;
    padding:15px;
    margin-bottom:12px;
    background:var(--surface)
}

.request-admin-head {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px
}

.request-code {
    font-size:16px;
    font-weight:900;
    color:var(--primary)
}

.request-date {
    font-size:11px;
    color:var(--text-secondary);
    margin-top:4px
}

.request-status {
    display:inline-flex;
    padding:5px 10px;
    border-radius:999px;
    background:#FEF3C7;
    color:#B45309;
    font-size:11px;
    font-weight:800
}

.request-admin-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
    margin-top:13px
}

.request-admin-item {
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:9px;
    padding:9px
}

.request-admin-item small {
    display:block;
    color:var(--text-secondary);
    font-size:11px;
    margin-bottom:4px
}

.request-admin-item strong {
    display:block;
    color:var(--text-primary);
    font-size:12px;
    word-break:break-word
}

.request-matches {
    margin-top:13px;
    padding-top:12px;
    border-top:1px solid var(--border)
}

.request-matches-toggle-row {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:13px;
    padding-top:12px;
    border-top:1px solid var(--border)
}

.request-collapse-toggle {
    display:flex;
    align-items:center;
    gap:6px;
    background:var(--bg-secondary);
    border:1px solid var(--border);
    color:var(--text-primary);
    border-radius:8px;
    padding:7px 12px;
    font-size:12px;
    font-family:inherit;
    cursor:pointer
}

.request-collapse-toggle:hover {
    border-color:var(--primary)
}

.request-collapse-arrow {
    display:inline-block;
    transition:transform .15s ease
}

.request-collapse-toggle.open .request-collapse-arrow {
    transform:rotate(-90deg)
}

.request-combo-row {
    background:var(--bg-secondary);
    border:1px solid var(--border);
    border-radius:10px;
    padding:10px;
    margin-bottom:10px
}

.request-combo-row:last-child {
    margin-bottom:0
}

.request-combo-head {
    display:flex;
    flex-wrap:wrap;
    gap:12px;
    align-items:center;
    font-size:12px;
    margin-bottom:8px;
    padding-bottom:8px;
    border-bottom:1px dashed var(--border)
}

.request-combo-items .request-match-row {
    padding:7px 0
}

.request-match-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    padding:9px 0;
    border-bottom:1px dashed var(--border)
}

.request-match-row:last-child {
    border-bottom:0
}

.request-match-title {
    font-size:13px;
    font-weight:800;
    color:var(--text-primary)
}

.request-match-meta {
    font-size:11px;
    color:var(--text-secondary);
    margin-top:3px
}

.request-match-score {
    font-size:15px;
    font-weight:900;
    color:var(--primary);
    white-space:nowrap
}


/* =========================================================
   REQUEST FOLLOW-UP / MATCH COMPACT UI
   ========================================================= */
.request-status-wrap{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.request-status-select{height:34px;min-width:150px;padding:0 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text-primary);font-family:'Vazirmatn',sans-serif;font-size:11px;font-weight:700;outline:none}
.request-status-select:focus,.request-followup-input:focus{border-color:var(--primary);box-shadow:0 0 0 2px rgba(6,78,78,.08)}
.request-followup-box{margin-top:10px;display:flex;gap:8px;align-items:flex-end}
.request-followup-input{flex:1;min-height:42px;max-height:110px;resize:vertical;padding:8px 10px;border:1px solid var(--border);border-radius:9px;background:var(--bg);color:var(--text-primary);font-family:'Vazirmatn',sans-serif;font-size:11px;outline:none;box-sizing:border-box}
.request-followup-save{height:42px;padding:0 14px;border:0;border-radius:9px;background:var(--primary);color:#fff;font-family:'Vazirmatn',sans-serif;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap}
.request-followup-state{font-size:11px;color:var(--text-secondary);min-width:90px}
.request-match-row{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px dashed var(--border)}
.request-match-main{flex:1;min-width:0}
.request-match-code{display:inline-flex;align-items:center;gap:4px;color:var(--primary);font-size:11px;font-weight:900;text-decoration:none;margin-bottom:2px}
.request-match-code:hover{text-decoration:underline}
.request-match-inline{display:flex;flex-wrap:wrap;gap:5px 12px;margin-top:5px}
.request-match-inline span{font-size:11px;color:var(--text-secondary)}
.request-match-inline strong{color:var(--text-primary);font-weight:800}
.request-match-score{font-size:15px;font-weight:900;color:var(--primary);white-space:nowrap;flex-shrink:0}
.request-match-detail{display:none}
@media(max-width:680px){.request-followup-box{flex-direction:column;align-items:stretch}.request-followup-save{width:100%}.request-status-select{min-width:130px}}

.request-empty {
    text-align:center;
    padding:42px 15px;
    color:var(--text-secondary)
}

@media(max-width:900px) {

    .request-admin-grid {
        grid-template-columns:repeat(2,minmax(0,1fr))
    }
}

@media(max-width:560px) {

    .request-admin-grid {
        grid-template-columns:1fr
    }

    .request-admin-head {
        flex-direction:column
    }
}


/* =========================================================
   تماس - بخش جدید مدیریت اطلاعات تماس
   ========================================================= */

.contact-admin-grid {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
}

.contact-admin-field {
    display:flex;
    flex-direction:column;
    gap:7px;
}

.contact-admin-field.full {
    grid-column:1 / -1;
}

.contact-admin-field label {
    font-size:13px;
    font-weight:700;
    color:var(--text-primary);
}

.contact-admin-field input,
.contact-admin-field textarea {
    width:100%;
    box-sizing:border-box;
    border:1px solid var(--border);
    background:var(--bg);
    color:var(--text-primary);
    border-radius:10px;
    padding:11px 12px;
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
    transition:.2s ease;
}

.contact-admin-field input {
    min-height:44px;
}

.contact-admin-field textarea {
    min-height:85px;
    resize:vertical;
    line-height:1.9;
}

.contact-admin-field input:focus,
.contact-admin-field textarea:focus {
    border-color:var(--primary);
    box-shadow:0 0 0 3px rgba(6,78,78,.08);
}

.contact-admin-help {
    font-size:11px;
    color:var(--text-secondary);
    line-height:1.8;
}

.contact-admin-preview {
    margin-top:18px;
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:12px;
    padding:14px;
}

.contact-admin-preview-title {
    font-size:13px;
    font-weight:800;
    color:var(--text-primary);
    margin-bottom:12px;
}

.contact-preview-grid {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:8px;
}

.contact-preview-grid > div {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:9px;
    padding:10px;
}

.contact-preview-grid span {
    display:block;
    color:var(--text-secondary);
    font-size:11px;
    margin-bottom:5px;
}

.contact-preview-grid strong {
    display:block;
    color:var(--text-primary);
    font-size:12px;
    word-break:break-word;
}

.contact-save-note {
    margin-top:10px;
    font-size:11px;
    color:var(--text-secondary);
    line-height:1.8;
}

@media(max-width:800px) {

    .contact-admin-grid {
        grid-template-columns:1fr;
    }

    .contact-admin-field.full {
        grid-column:auto;
    }

    .contact-preview-grid {
        grid-template-columns:1fr 1fr;
    }
}

@media(max-width:500px) {

    .contact-preview-grid {
        grid-template-columns:1fr;
    }
}

</style>


<style>
/* =========================================================
   MELKINO ADMIN CONTROL CENTER - NEW LAYER
========================================================= */
.admin-hero{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:20px 22px;margin-bottom:16px;border-radius:20px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 96%,#000 4%),color-mix(in srgb,var(--primary-dark) 92%,#000 8%));color:#fff;box-shadow:0 18px 50px rgba(6,78,78,.16);overflow:hidden;position:relative}
.admin-hero::after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;left:-95px;top:-115px;background:rgba(212,175,55,.10)}
.admin-hero-copy{position:relative;z-index:1}.admin-hero-kicker{font-size:11px;letter-spacing:1.2px;opacity:.66;font-weight:800}.admin-hero-title{font-size:24px;font-weight:950;margin-top:5px}.admin-hero-sub{font-size:11px;opacity:.68;margin-top:4px;line-height:1.8}.admin-hero-badge{position:relative;z-index:1;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.14);font-size:11px;font-weight:800}
.admin-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:11px}.admin-stat-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px;box-shadow:var(--shadow-card);position:relative;overflow:hidden}.admin-stat-icon{width:38px;height:38px;border-radius:12px;background:var(--gold-bg);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:18px}.admin-stat-number{font-size:24px;font-weight:950;color:var(--text-primary);margin-top:11px}.admin-stat-label{font-size:11px;color:var(--text-secondary);margin-top:2px}.admin-stat-note{font-size:11px;color:var(--text-muted);margin-top:8px}.admin-stat-card.accent{border-color:color-mix(in srgb,var(--primary) 20%,var(--border))}.admin-stat-card.warning{border-color:color-mix(in srgb,var(--warning) 20%,var(--border))}.admin-stat-card.danger{border-color:color-mix(in srgb,var(--danger) 20%,var(--border))}
.admin-panel-section{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:16px;margin-bottom:14px;box-shadow:var(--shadow-card)}.admin-section-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}.admin-section-title{font-size:15px;font-weight:900;color:var(--text-primary)}.admin-section-help{font-size:11px;color:var(--text-secondary);line-height:1.8}.admin-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.admin-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.admin-field{display:flex;flex-direction:column;gap:6px}.admin-field.full{grid-column:1/-1}.admin-field label{font-size:11px;font-weight:800;color:var(--text-secondary)}.admin-field input,.admin-field select,.admin-field textarea{width:100%;min-height:46px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text-primary);font-family:inherit;padding:9px 11px;outline:none}.admin-field textarea{min-height:100px;resize:vertical}.admin-field input:focus,.admin-field select:focus,.admin-field textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 10%,transparent)}
.consultants-toolbar{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}.consultant-manager-list{display:flex;flex-direction:column;gap:12px}.consultant-manager-card{border:1px solid var(--border);border-radius:16px;background:var(--bg);overflow:hidden}.consultant-manager-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;background:color-mix(in srgb,var(--surface) 88%,var(--primary) 12%);flex-wrap:wrap}.consultant-manager-title{display:flex;align-items:center;gap:8px;font-weight:900;color:var(--text-primary);font-size:13px}.consultant-default-pill{padding:5px 9px;border-radius:999px;background:var(--gold-bg);color:var(--gold-dark);font-size:11px;font-weight:900}.consultant-specialties{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 12px}.consultant-specialty{padding:6px 8px;border-radius:999px;background:var(--surface);border:1px solid var(--border);font-size:11px;color:var(--text-secondary);display:flex;align-items:center;gap:5px}.consultant-specialty button{border:0;background:transparent;color:var(--danger);cursor:pointer}.consultant-add-specialty{display:grid;grid-template-columns:1fr 1fr auto;gap:7px;padding:0 14px 14px}.consultant-manager-actions{display:flex;gap:7px;flex-wrap:wrap}.consultant-empty{padding:22px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--text-secondary);font-size:11px}
.color-theme-panel{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}.color-theme-card{min-width:0;border:1px solid var(--border);border-radius:18px;padding:16px;background:linear-gradient(180deg,var(--surface),var(--bg));box-shadow:var(--shadow-card)}.color-theme-title{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:14px;font-weight:900;color:var(--text-primary);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)}.color-theme-title::after{content:"ویرایش مستقیم";font-size:11px;font-weight:800;color:var(--text-secondary);padding:4px 8px;border-radius:999px;background:var(--bg-secondary);border:1px solid var(--border)}.color-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.color-field{min-width:0;display:flex;flex-direction:column;gap:7px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}.color-field label{display:block;font-size:11px;line-height:1.6;color:var(--text-primary);font-weight:800;min-width:0}.color-field label span{display:block;margin-top:2px;color:var(--text-muted)!important;font-size:11px!important;direction:ltr;text-align:left}.color-field > div{display:grid!important;grid-template-columns:minmax(0,1fr) 48px!important;gap:6px!important;align-items:center!important}.color-field .theme-text{width:100%!important;min-width:0!important;height:38px!important;padding:0 9px!important;box-sizing:border-box!important;border:1px solid var(--border)!important;border-radius:9px!important;background:var(--bg)!important;color:var(--text-primary)!important;font-family:inherit!important;font-size:11px!important;direction:ltr!important;text-align:left!important;outline:none!important}.color-field .theme-text:focus{border-color:var(--primary)!important;box-shadow:0 0 0 2px color-mix(in srgb,var(--primary) 12%,transparent)!important}.color-field input[type=color]{width:48px!important;height:38px!important;padding:3px!important;border:1px solid var(--border)!important;border-radius:9px!important;background:var(--surface)!important;cursor:pointer!important}.theme-preview{margin-top:10px;border:1px solid var(--border);border-radius:14px;overflow:hidden}.theme-preview-head{padding:9px 11px;font-size:11px;font-weight:900}.theme-preview-body{padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px}.theme-preview-card{padding:10px;border-radius:10px;font-size:11px}.theme-preview-button{padding:9px;border-radius:10px;text-align:center;font-size:11px;font-weight:900}
.password-security-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.security-switch{display:flex;justify-content:space-between;align-items:center;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg)}.security-switch span{font-size:11px;color:var(--text-primary);font-weight:800}.security-switch small{display:block;font-size:11px;color:var(--text-secondary);margin-top:2px}.security-switch input{width:18px;height:18px;accent-color:var(--primary)}
.users-table{width:100%;border-collapse:collapse}.users-table th,.users-table td{padding:var(--sp-12,12px);border-bottom:1px solid var(--border);font-size:var(--fs-sm,12.5px);text-align:right}.users-table th{background:var(--bg);color:var(--text-secondary);font-size:var(--fs-xs,11px)}.user-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--success);margin-left:4px}
.ad-vip-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:999px;background:var(--gold-bg);color:var(--gold-dark);font-size:11px;font-weight:900;margin-top:4px}.table-action.vip.active{background:var(--gold);color:#111827;border-color:var(--gold)}
/* نشانگرها و مودال لاگ انتشار کانال (راند ۱۸) */
.ch-badge{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:800;padding:2px 7px;border-radius:999px;margin-top:4px;margin-inline-start:5px}
.ch-badge.ch-tg{background:rgba(0,136,204,.13);color:#0088cc;border:1px solid rgba(0,136,204,.32)}
.ch-badge.ch-bale{background:rgba(138,92,246,.13);color:#8a5cf6;border:1px solid rgba(138,92,246,.32)}
.table-action.ch-pub{color:#16a34a;border-color:rgba(22,163,74,.45)}
.pub-tick{position:absolute;top:-6px;inset-inline-start:-6px;width:15px;height:15px;border-radius:50%;background:var(--surface);color:#16a34a;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 0 0 1px var(--border);pointer-events:none}
.pub-tick svg.mk-icon{width:10px;height:10px;stroke-width:3.4}
.table-action.ch-pub:hover{background:rgba(22,163,74,.12)}
.publog-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:9999}
.publog-overlay.open{display:flex}
.publog-modal{background:var(--surface);color:var(--text-primary);border:1px solid var(--border);border-radius:16px;width:min(760px,94vw);max-height:80vh;display:flex;flex-direction:column;box-shadow:var(--shadow-card)}
.publog-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;border-bottom:1px solid var(--border);font-size:13px}
.publog-close{background:none;border:none;font-size:15px;cursor:pointer;color:var(--text-secondary)}
.publog-body{padding:14px 16px;overflow:auto}
.publog-table{width:100%;border-collapse:collapse;font-size:12px}
.publog-table th,.publog-table td{padding:7px 8px;border-bottom:1px solid var(--border);text-align:right}
.publog-table th{color:var(--text-secondary);font-size:11px;font-weight:800}
.publog-ok{color:#16a34a;font-weight:800}
.publog-fail{color:#dc2626;font-weight:800}
.publog-loading,.publog-empty{text-align:center;padding:26px 8px;color:var(--text-secondary);line-height:2}
/* تب نمایش — مدیریت کارت‌ها (راند ۲۰) */
.cd-search{width:100%;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:var(--bg);color:var(--text-primary);font-family:inherit;font-size:12.5px;margin-bottom:16px;outline:none}
.cd-search:focus{border-color:var(--primary,#2563eb);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.cd-count{font-size:11px;font-weight:700;color:var(--text-secondary);background:var(--bg-secondary,rgba(128,128,128,.12));border-radius:999px;padding:2px 9px;margin-inline-start:6px}
.cd-group{margin-bottom:18px}
.cd-group-title{font-size:13px;font-weight:900;margin-bottom:4px;color:var(--text-primary)}
.cd-group-help{font-size:11px;color:var(--text-secondary);margin-bottom:9px}
.cd-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:var(--bg);margin-bottom:8px;flex-wrap:wrap}
.cd-item-info span{font-size:12px;font-weight:800;color:var(--text-primary)}
.cd-item-info small{display:block;font-size:11px;color:var(--text-secondary);margin-top:2px}
.cd-seg{display:inline-flex;border:1px solid var(--border);border-radius:10px;overflow:hidden;flex-shrink:0}
.cd-seg button{border:none;background:transparent;padding:6px 12px;font-size:11px;font-weight:700;cursor:pointer;color:var(--text-secondary);font-family:inherit}
.cd-seg button.on{background:var(--primary,#2563eb);color:#fff}
.cd-scope{font-size:11px;color:var(--text-secondary);border:1px solid var(--border);border-radius:999px;padding:1px 8px;margin-inline-start:6px;white-space:nowrap}
/* راند ۲۹ — سیستم مدیریت فیلدها */
.fd-subtabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.fd-subtab{border:1px solid var(--border);background:var(--bg);color:var(--text-secondary);padding:9px 16px;border-radius:12px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit}
.fd-subtab.active{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:#fff}
.fd-meta{font-size:11px;color:var(--text-secondary);margin-inline-start:auto}
.fd-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.fd-toolbar-label{font-size:11.5px;font-weight:700;color:var(--text-secondary);display:flex;align-items:center;gap:6px}
.fd-select{border:1px solid var(--border);background:var(--bg);color:var(--text-primary);border-radius:10px;padding:7px 10px;font-size:11.5px;font-family:inherit;max-width:280px}
.fd-devices{display:inline-flex;gap:4px;flex-wrap:wrap}
.fd-devices button{border:1px solid var(--border);background:var(--bg);color:var(--text-secondary);border-radius:8px;padding:5px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
.fd-devices button.on{background:var(--primary,#2563eb);border-color:var(--primary,#2563eb);color:#fff}
.fd-btn-sm{padding:6px 12px;font-size:11.5px}
.fd-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;align-items:center}
.fd-actions .btn-primary,.fd-actions .btn-secondary{padding:9px 18px;font-size:12.5px}
.fd-group{margin-bottom:16px}
.fd-group-title{font-size:13px;font-weight:900;margin-bottom:8px;color:var(--text-primary)}
.fd-row{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--border);border-radius:12px;background:var(--bg);margin-bottom:7px;flex-wrap:wrap}
.fd-row.fd-off{opacity:.55}
.fd-row.fd-dragging{opacity:.4}
.fd-row.fd-dragover{border-color:var(--primary,#2563eb);box-shadow:0 0 0 2px rgba(37,99,235,.2)}
.fd-handle{cursor:grab;font-size:14px;color:var(--text-secondary);user-select:none;padding:2px 4px}
.fd-switch{position:relative;width:36px;height:20px;flex-shrink:0}
.fd-switch input{opacity:0;width:0;height:0}
.fd-slider{position:absolute;inset:0;background:var(--border);border-radius:999px;cursor:pointer;transition:.15s}
.fd-slider::before{content:'';position:absolute;width:14px;height:14px;border-radius:50%;background:#fff;top:3px;right:3px;transition:.15s}
.fd-switch input:checked + .fd-slider{background:var(--success,#16a34a)}
.fd-switch input:checked + .fd-slider::before{transform:translateX(-16px)}
.fd-icn{width:42px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text-primary);text-align:center;font-size:14px;padding:4px 0;font-family:inherit}
.fd-label{flex:1;min-width:130px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text-primary);padding:6px 9px;font-size:11.5px;font-family:inherit}
.fd-key{font-size:11px;color:var(--text-secondary);direction:ltr;text-align:left;min-width:110px;word-break:break-all}
.fd-key b{display:block;font-size:11px;color:var(--text-primary)}
.fd-type{font-size:11px;border:1px solid var(--border);border-radius:999px;padding:2px 8px;color:var(--text-secondary);white-space:nowrap}
.fd-seg{display:inline-flex;border:1px solid var(--border);border-radius:9px;overflow:hidden;flex-shrink:0}
.fd-seg button{border:none;background:transparent;padding:5px 9px;font-size:11px;font-weight:700;cursor:pointer;color:var(--text-secondary);font-family:inherit}
.fd-seg button.on{background:var(--primary,#2563eb);color:#fff}
.fd-r{display:inline-flex;gap:4px;flex-shrink:0}
.fd-r label{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:var(--text-secondary);border:1px solid var(--border);border-radius:8px;padding:4px 7px;cursor:pointer}
.fd-r input{width:13px;height:13px;accent-color:var(--primary,#2563eb)}
.fd-format{display:flex;gap:6px;align-items:center;flex-shrink:0}
.fd-checkline{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:var(--text-secondary)}
.fd-checkline input{width:13px;height:13px;accent-color:var(--primary,#2563eb)}
.fd-device-frame{width:100%;overflow:auto;background:var(--bg-secondary,rgba(128,128,128,.08));border-radius:14px;padding:10px;display:flex;justify-content:center}
.fd-device-frame iframe{border:1px solid var(--border);border-radius:12px;background:#fff;height:640px;width:390px;max-width:100%;transition:width .2s}
.fd-sec-toggle{border:1px solid var(--border);background:var(--bg);color:var(--text-primary);border-radius:9px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit}
.fd-subfields{width:100%;margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);display:none}
.fd-subfields.open{display:block}
.fd-errors{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.35);color:var(--danger,#dc2626);border-radius:10px;padding:9px 12px;font-size:11.5px;margin-top:10px;display:none}
.fd-errors.show{display:block}
@media (max-width:767px){.fd-row{gap:6px}.fd-key{min-width:90px}}
.cd-preview-wrap{display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start}
.cd-preview-col{flex:1 1 300px;min-width:280px;max-width:430px}
.cd-preview-col h4{font-size:11px;color:var(--text-secondary);margin-bottom:8px;font-weight:800}
.cdp-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-card)}
.cdp-img{height:150px;background:linear-gradient(135deg,#334155,#0f172a);display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px;position:relative}
.cdp-badges{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px 0}
.cdp-badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px;background:rgba(212,175,55,.15);color:#b45309;border:1px solid rgba(212,175,55,.4)}
.cdp-badge.loan{background:rgba(22,163,74,.12);color:#16a34a;border-color:rgba(22,163,74,.35)}
.cdp-badge.exchange{background:rgba(0,136,204,.12);color:#0369a1;border-color:rgba(3,105,161,.35)}
.cdp-badge.key{background:rgba(138,92,246,.12);color:#7c3aed;border-color:rgba(124,58,237,.35)}
.cdp-body{padding:10px 12px}
.cdp-title{font-size:13.5px;font-weight:900;color:var(--text-primary);line-height:1.7}
.cdp-loc{font-size:11px;color:var(--text-secondary);margin-top:4px}
.cdp-details{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.cdp-chip{font-size:11px;color:var(--text-secondary);background:var(--bg-secondary,rgba(128,128,128,.12));border-radius:8px;padding:3px 8px}
.cdp-price{font-size:13px;font-weight:950;color:#b45309;margin-top:9px}
.cdp-loanline{font-size:11px;color:#16a34a;margin-top:3px;font-weight:700}
.cdp-footer{display:flex;justify-content:space-between;border-top:1px solid var(--border);padding:8px 12px;font-size:11px;color:var(--text-secondary)}
.cdp-h{display:flex}
.cdp-h .cdp-img{width:38%;min-width:110px;height:auto;min-height:140px}
.cdp-h .cdp-content{flex:1;padding:10px 12px;display:flex;flex-direction:column;gap:5px;min-width:0}
.cdp-code{font-size:11px;color:var(--text-muted,#888)}
.cdp-hrow{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.cdp-hbadges{position:absolute;top:8px;right:8px;display:flex;flex-direction:column;gap:4px;align-items:flex-end}
.cdp-hfooter{display:flex;justify-content:space-between;align-items:center;margin-top:auto;font-size:11px;gap:6px}
.cdp-detail-btn{color:#0369a1;font-weight:800;font-size:11px}
.cdp-likebtn{border:1px solid var(--border);background:transparent;border-radius:8px;padding:2px 8px;color:var(--text-secondary);font-size:11px}
.request-match-detail{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:8px}.request-match-detail div{padding:7px 8px;border-radius:9px;background:var(--surface);border:1px solid var(--border);font-size:11px;color:var(--text-secondary)}.request-match-detail strong{display:block;color:var(--text-primary);font-size:11px;margin-top:2px}
@media(max-width:980px){.admin-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.color-theme-panel,.password-security-grid{grid-template-columns:1fr}.admin-grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:680px){.admin-hero{align-items:flex-start;flex-direction:column}.admin-grid-2,.admin-grid-3,.color-grid{grid-template-columns:1fr}.admin-field.full{grid-column:auto}.admin-stat-grid{grid-template-columns:1fr 1fr}.consultant-add-specialty{grid-template-columns:1fr}.request-match-detail{grid-template-columns:1fr}.tabs-container{position:sticky;top:0;z-index:8000}}
@media(max-width:480px){.color-theme-card{padding:12px}.color-field{padding:9px}.color-field > div{grid-template-columns:minmax(0,1fr) 46px!important}.color-field input[type=color]{width:46px!important;height:36px!important}.color-field .theme-text{height:36px!important;font-size:11px!important}}


/* =========================================================
   MELKINO ADMIN — PREMIUM V2 VISUAL SHELL
   ========================================================= */
.admin-body{background:#071918!important}
.admin-body .app-container{background:var(--bg)!important}
.admin-body .topbar{background:linear-gradient(135deg,#052d2d,#0a4b49)!important;border-bottom:1px solid rgba(212,175,55,.18)!important;color:#fff!important}
.admin-body .topbar-title{color:#f0d36a!important;font-weight:900!important;letter-spacing:-.2px}
.admin-body .topbar-action{color:rgba(255,255,255,.78)!important}
.admin-body .topbar-action:hover{background:rgba(255,255,255,.08)!important;color:#f0d36a!important}
.admin-body .main-content{padding:0 22px 30px!important;background:radial-gradient(circle at 90% 0%,rgba(212,175,55,.08),transparent 24%),linear-gradient(180deg,#f6f8f5 0%,#eef2ef 100%)!important}
[data-theme="dark"] .admin-body .main-content{background:radial-gradient(circle at 90% 0%,rgba(229,184,66,.08),transparent 24%),linear-gradient(180deg,#0b1717,#0d1f1e)!important}

.admin-command-header{margin:20px 0 14px!important;padding:22px 24px!important;border-radius:26px!important;background:linear-gradient(135deg,#052d2d 0%,#064e4e 58%,#0b5d5b 100%)!important;box-shadow:0 18px 45px rgba(3,38,38,.18)!important;border:1px solid rgba(212,175,55,.22)!important;position:relative;overflow:hidden!important}
.admin-command-header:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;left:-100px;bottom:-160px;background:rgba(212,175,55,.08);pointer-events:none}
.admin-command-brand{position:relative;z-index:2;display:flex!important;align-items:center!important;gap:14px!important}
.admin-command-mark{width:56px!important;height:56px!important;border-radius:18px!important;display:grid!important;place-items:center!important;background:linear-gradient(135deg,#f4dc7a,#c79f27)!important;color:#163434!important;font-size:28px!important;font-weight:1000!important;box-shadow:0 10px 25px rgba(212,175,55,.22)!important}
.admin-command-kicker{font-size:11px!important;letter-spacing:2px!important;color:#f4dc7a!important;font-weight:900!important}
.admin-command-title{font-size:23px!important;color:#fff!important;font-weight:950!important;margin-top:3px!important}
.admin-command-meta{position:relative;z-index:2;color:rgba(255,255,255,.72)!important;font-size:11px!important;display:flex;align-items:center;gap:8px!important;flex-wrap:wrap}
.admin-live-dot{width:8px!important;height:8px!important;background:#51e39d!important;border-radius:50%!important;box-shadow:0 0 12px rgba(81,227,157,.8)!important}

.admin-body .tabs-container{display:flex!important;flex-direction:column!important;gap:8px!important;padding:10px!important;margin-bottom:18px!important;background:#ffffff!important;border:1px solid rgba(6,78,78,.08)!important;border-radius:20px!important;box-shadow:0 10px 26px rgba(0,0,0,.05)!important;overflow:visible!important;position:sticky;top:0;z-index:8000!important}
#adminAdsMap,#editAdMap,.admin-body .leaflet-container{position:relative;z-index:1!important;isolation:isolate}
.admin-body .leaflet-pane,.admin-body .leaflet-top,.admin-body .leaflet-bottom,.admin-body .leaflet-control{z-index:2!important}
.admin-nav-row{display:flex;flex-wrap:nowrap;gap:6px;overflow-x:auto;scrollbar-width:thin}
.admin-nav-group-btn{flex:1 0 auto;min-height:44px;padding:0 14px;border:1px solid transparent;border-radius:14px;background:transparent;color:var(--text-secondary);font-family:inherit;font-size:12px;font-weight:850;cursor:pointer;white-space:nowrap}
.admin-nav-group-btn:hover{background:rgba(6,78,78,.055);color:var(--primary)}
.admin-nav-group-btn.is-on{background:linear-gradient(135deg,var(--primary),#0b5d5b);color:#fff;box-shadow:0 8px 18px rgba(6,78,78,.18)}
.admin-nav-sub{display:none;flex-wrap:nowrap;gap:6px;overflow-x:auto;padding:2px 2px 4px}
.admin-nav-sub.is-open{display:flex}
.admin-nav-sub-panel{display:none;flex-wrap:nowrap;gap:6px;width:100%}
.admin-nav-sub-panel.is-on{display:flex}
.admin-nav-sub .tab-btn{width:auto!important;min-width:88px;flex:0 0 auto}
.admin-body .tab-btn{padding:11px 8px!important;min-height:48px!important;border:1px solid transparent!important;border-radius:14px!important;background:transparent!important;color:var(--text-secondary)!important;font-size:11px!important;font-weight:850!important;border-bottom:none!important;transition:.2s ease!important;white-space:nowrap}
.admin-body .tab-btn:hover{background:rgba(6,78,78,.055)!important;color:var(--primary)!important;transform:translateY(-1px)!important}
.admin-body .tab-btn.active{background:linear-gradient(135deg,var(--primary),#0b5d5b)!important;color:#fff!important;border-color:rgba(212,175,55,.28)!important;box-shadow:0 8px 18px rgba(6,78,78,.18)!important}
[data-theme="dark"] .admin-body .tabs-container{background:#152524!important;border-color:#294646!important}
[data-theme="dark"] .admin-body .tab-btn:hover{background:rgba(255,255,255,.05)!important}

.admin-body .tab-content.active{gap:16px!important}
.admin-body .admin-card{border-radius:22px!important;border:1px solid rgba(6,78,78,.08)!important;box-shadow:0 12px 34px rgba(0,0,0,.06)!important;background:rgba(255,255,255,.86)!important;padding:18px!important}
[data-theme="dark"] .admin-body .admin-card{background:#142524!important;border-color:#294646!important;box-shadow:0 12px 34px rgba(0,0,0,.25)!important}
.admin-body .card-title{font-size:17px!important;font-weight:950!important}

/* hide old compact dashboard strip — the new dashboard replaces it */
.admin-hero{margin:0!important;padding:24px!important;border-radius:24px!important;background:linear-gradient(135deg,rgba(6,78,78,.97),rgba(11,93,91,.90))!important;border:1px solid rgba(212,175,55,.20)!important;box-shadow:0 16px 38px rgba(6,78,78,.15)!important}
.admin-hero-title{font-size:27px!important;font-weight:950!important;color:#fff!important}
.admin-hero-kicker{color:#f4d978!important;font-weight:900!important;letter-spacing:1.4px!important;font-size:11px!important}
.admin-hero-sub{color:rgba(255,255,255,.68)!important;font-size:11px!important;line-height:1.9!important;max-width:620px!important}
.admin-hero-badge{background:rgba(255,255,255,.10)!important;border:1px solid rgba(255,255,255,.16)!important;color:#fff!important;padding:8px 12px!important;border-radius:999px!important;font-size:11px!important;font-weight:800!important}
.admin-stat-grid{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}
.admin-stat-card{min-height:104px!important;padding:12px 13px!important;border-radius:14px!important;background:rgba(255,255,255,.90)!important;border:1px solid rgba(6,78,78,.08)!important;box-shadow:0 12px 30px rgba(0,0,0,.055)!important;position:relative!important;overflow:hidden!important}
.admin-stat-card:after{content:"";position:absolute;width:78px;height:78px;border-radius:50%;left:-42px;bottom:-46px;background:rgba(212,175,55,.08)}
[data-theme="dark"] .admin-stat-card{background:#142524!important;border-color:#294646!important;box-shadow:0 12px 30px rgba(0,0,0,.24)!important}
.admin-stat-icon{width:31px!important;height:31px!important;border-radius:10px!important;display:grid!important;place-items:center!important;background:var(--gold-bg)!important;font-size:15px!important;margin-bottom:6px!important}
.admin-stat-number{font-size:21px!important;font-weight:1000!important;color:var(--text-primary)!important;line-height:1.15!important}
.admin-stat-label{font-size:11px!important;font-weight:850!important;color:var(--text-primary)!important;margin-top:1px!important;line-height:1.35!important}
.admin-stat-note{font-size:11px!important;color:var(--text-secondary)!important;margin-top:3px!important;line-height:1.4!important}

/* settings sections */
#tab-contact .admin-card,#tab-password .admin-card,#tab-global .admin-card,#tab-onboarding .admin-card{padding:22px!important}
#consultantsContainer,#passwordContainer,#globalContainer,#onboardingEditor{margin-top:6px!important}
.admin-field{background:var(--bg)!important;border-radius:14px!important;padding:12px!important;border:1px solid var(--border)!important}
.admin-field label{font-weight:850!important;font-size:11px!important;color:var(--text-primary)!important}
.admin-field input,.admin-field select,.admin-field textarea{border-radius:12px!important;min-height:46px!important;background:var(--surface)!important}

@media(max-width:1100px){.admin-body .tabs-container{grid-template-columns:repeat(5,minmax(0,1fr))!important}.admin-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:650px){.admin-body .main-content{padding:0 10px 24px!important}.admin-command-header{padding:18px!important;border-radius:20px!important}.admin-command-title{font-size:19px!important}.admin-command-meta{margin-top:12px!important}.admin-body .tabs-container{grid-template-columns:repeat(3,minmax(0,1fr))!important;position:sticky!important;top:0!important;z-index:8000!important}.admin-body .tab-btn{font-size:11px!important;padding:9px 5px!important}.admin-stat-grid{grid-template-columns:1fr 1fr!important;gap:9px!important}.admin-stat-card{min-height:92px!important;padding:10px 11px!important}.admin-stat-number{font-size:19px!important}.admin-hero{padding:18px!important}.admin-hero-title{font-size:22px!important}}
@media(max-width:400px){.admin-body .tabs-container{grid-template-columns:repeat(2,minmax(0,1fr))!important}.admin-stat-grid{grid-template-columns:1fr!important}}

</style>



    <link rel="stylesheet" href="design-pro.css">

    <?php require_once __DIR__ . '/csrf-shim.php'; ?>
<?php echo (is_file(__DIR__ . '/brand-site-icon.php') ? (include __DIR__ . '/brand-site-icon.php') : ''); ?>
    <link rel="stylesheet" href="assets/css/admin-light-mode.css?v=1">
</head>

<body class="admin-body">
<?php if (is_file(__DIR__ . '/admin/shell.php')) { require __DIR__ . '/admin/shell.php'; } ?>

<div
    class="app-container"
    style="padding-bottom:0;"
>

<header class="topbar">

    <a
        href="home.php"
        class="topbar-action"
    >
        <svg
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
        >
            <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
    </a>


    <span class="topbar-title">

        پنل مدیریت ملکینو

        <?php if ($isMockMode): ?>

            <span class="badge-mock">
                MOCK
            </span>

        <?php endif; ?>

    </span>


    <div class="mk-theme-switch mk-theme-switch--topbar" role="group" aria-label="حالت نمایش پنل" style="display:flex;gap:4px;align-items:center;">
        <button type="button" class="mk-theme-btn mk-theme-btn--icon" data-mk-theme-btn="light" aria-pressed="false" aria-label="لایت مود" onclick="mkAdminSetTheme('light')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg></button>
        <button type="button" class="mk-theme-btn mk-theme-btn--icon" data-mk-theme-btn="dark" aria-pressed="false" aria-label="دارک مود" onclick="mkAdminSetTheme('dark')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></button>
    </div>
    <div
        style="display:flex;gap:var(--space-2);"
    >

        <button
            class="topbar-action"
            onclick="saveAndExit()"
            title="ذخیره و خروج"
        >
            <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>


        <button
            class="topbar-action"
            onclick="adminLogout()"
            title="خروج امن"
        >
            <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </button>

    </div>

</header>


<div
    class="main-content"
    id="mainContent"
>

<div class="admin-command-header">
    <div class="admin-command-brand">
        <div class="admin-command-mark">M</div>
        <div>
            <div class="admin-command-kicker">MELKINO ADMIN</div>
            <div class="admin-command-title">مرکز فرمان مدیریت ملکینو</div>
        </div>
    </div>
    <div class="admin-command-meta">
        <span class="admin-live-dot"></span> سیستم آنلاین
        <span class="admin-command-date" id="adminCommandClock">در حال بررسی…</span>
    </div>
</div>

<nav class="tabs-container" id="adminTabsNav" aria-label="منوی مدیریت">
    <div class="admin-nav-row" id="adminNavGroups">
        <button type="button" class="admin-nav-group-btn is-on" data-nav-group="dash" onclick="openAdminNavGroup('dash')">داشبورد</button>
        <button type="button" class="admin-nav-group-btn" data-nav-group="ads" onclick="openAdminNavGroup('ads')">مدیریت آگهی</button>
        <button type="button" class="admin-nav-group-btn" data-nav-group="comm" onclick="openAdminNavGroup('comm')">ارتباطات</button>
        <button type="button" class="admin-nav-group-btn" data-nav-group="mkt" onclick="openAdminNavGroup('mkt')">بازاریابی</button>
        <button type="button" class="admin-nav-group-btn" data-nav-group="ui" onclick="openAdminNavGroup('ui')">ظاهر و محتوا</button>
        <button type="button" class="admin-nav-group-btn" data-nav-group="sys" onclick="openAdminNavGroup('sys')">سیستم</button>
    </div>
    <div class="admin-nav-sub" id="adminNavSub">
        <div class="admin-nav-sub-panel" data-nav-panel="ads">
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('ads')" data-tour="admin-ads">آگهی‌ها</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('requests')" data-tour="admin-requests">درخواست‌ها</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('visits')">درخواست بازدید</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('map')" data-tour="admin-map">نقشه</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('images')" data-tour="admin-images">تصاویر</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('advisor')">مشاور</button>
        </div>
        <div class="admin-nav-sub-panel" data-nav-panel="comm">
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('users')" data-tour="admin-users">کاربران</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('stats')">آمار</button>
            <button class="tab-btn" id="smsTabBtn" role="tab" aria-selected="false" onclick="switchTab('sms')" data-tour="admin-sms">پیامک</button>
            <button class="tab-btn" id="assistantTabBtn" role="tab" aria-selected="false" onclick="switchTab('assistant')">دستیار هوشمند</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('contact')">ارتباط با ما</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('support')" data-tour="admin-support">پشتیبانی</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('notifications')">اعلان‌ها</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('bots')" data-tour="admin-bots">ربات و کانال</button>
        </div>
        <div class="admin-nav-sub-panel" data-nav-panel="mkt">
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('promotions')">تبلیغات</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('onboarding')">صفحات هدایت</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('story')">استودیوی استوری</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('publish')">انتشار</button>
        </div>
        <div class="admin-nav-sub-panel" data-nav-panel="ui">
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('display')" data-tour="admin-display">نمایش</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('studio')" data-tour="admin-studio">تم و استودیو طراحی</button><!-- شامل «تم و رنگ» از طریق نوار سوییچ -->
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('forms')">فرم‌ها</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('brand')">برند و تنظیمات سایت</button>
        </div>
        <div class="admin-nav-sub-panel" data-nav-panel="sys">
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('global')" data-tour="admin-global">عمومی</button>
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('diagnostics')" data-tour="admin-diagnostics">عیب‌یابی و پشتیبان</button><!-- شامل «پشتیبان‌گیری» از طریق نوار سوییچ -->
            <button class="tab-btn" role="tab" aria-selected="false" onclick="switchTab('password')">تغییر رمز</button>
        </div>
    </div>
</nav>

<script>
window.MELKINO_ADMIN_NAV = {
    dash: 'dashboard',
    ads: ['ads', 'requests', 'visits', 'map', 'images', 'advisor'],
    customers: ['leads'],
    comm: ['users', 'stats', 'sms', 'assistant', 'contact', 'support', 'notifications', 'bots'],
    mkt: ['promotions', 'onboarding', 'story'],
    ui: ['display', 'theme', 'studio', 'forms', 'brand'],
    sys: ['global', 'backup', 'diagnostics', 'password']
};
function adminNavGroupOf(tabId) {
    var map = window.MELKINO_ADMIN_NAV || {};
    if (tabId === 'dashboard') return 'dash';
    for (var g in map) {
        if (Array.isArray(map[g]) && map[g].indexOf(tabId) !== -1) return g;
    }
    return 'dash';
}
function revealAdminNavGroup(groupId) {
    document.querySelectorAll('.admin-nav-group-btn').forEach(function (b) {
        b.classList.toggle('is-on', b.getAttribute('data-nav-group') === groupId);
    });
    var sub = document.getElementById('adminNavSub');
    document.querySelectorAll('.admin-nav-sub-panel').forEach(function (p) {
        p.classList.toggle('is-on', p.getAttribute('data-nav-panel') === groupId);
    });
    if (sub) sub.classList.toggle('is-open', groupId !== 'dash');
}
function openAdminNavGroup(groupId) {
    revealAdminNavGroup(groupId);
    if (groupId === 'dash') {
        switchTab('dashboard');
        return;
    }
}
/* =========================================================
   switchTab — عمداً در یک بلوک اسکریپتِ جدا و زودهِ exec تعریف
   می‌شود: اگر هر بلوکِ بزرگ‌ترِ پایین‌تر به هر دلیلی (خطای
   سینتکس، خروجی خراب PHP و…) از کار بیفتد، جا به جایی تب‌ها
   و بارگذاری اسکریپت‌های تب همچنان کار کند و پنل قابل استفاده
   بماند.
   ========================================================= */
// بارگذاری تنبل آمار: یک‌بار، هنگام اولین بازکردن تب «آمار»
function melkinoLoadStatsTab(el) {
    if (!el || el.dataset.lazyStats !== '1' || el.dataset.lazyLoading === '1') return;
    el.dataset.lazyLoading = '1';
    fetch('admin-panel.php?stats_fragment=1', {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' }
    })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function (html) {
            var tmp = document.createElement('div');
            tmp.innerHTML = html;
            var inner = tmp.querySelector('#tab-stats');
            el.innerHTML = inner ? inner.innerHTML : html;
            el.dataset.lazyStats = '0';
            el.dataset.lazyLoading = '0';
        })
        .catch(function () {
            el.innerHTML = '<div class="mk-empty" style="padding:24px;">بارگذاری آمار انجام نشد؛ دوباره تب را باز کنید.</div>';
            el.dataset.lazyLoading = '0';
        });
}

function switchTab(tabId) {

    document
        .querySelectorAll('.tab-content')
        .forEach(
            el =>
                el.classList.remove(
                    'active'
                )
        );


    document
        .querySelectorAll('.tab-btn')
        .forEach(
            el =>
                el.classList.remove(
                    'active'
                )
        );


    const contentEl =
        document.getElementById(
            'tab-' + tabId
        );


    if (contentEl) {
        contentEl.classList.add(
            'active'
        );
    }

    if (tabId === 'stats') {
        melkinoLoadStatsTab(contentEl);
    }


    const btnEl =
        document.querySelector(
            `.tab-btn[onclick*="'${tabId}'"]`
        );


    if (btnEl) {
        btnEl.classList.add(
            'active'
        );
    }

    // راند ۴۰ (S12): همگام‌سازی aria-selected برای صفحه‌خوان‌ها
    document
        .querySelectorAll('.tab-btn')
        .forEach(
            el =>
                el.setAttribute(
                    'aria-selected',
                    el.classList.contains('active') ? 'true' : 'false'
                )
        );


    /*
     * آماده‌سازیِ اسکریپتِ مورد نیازِ این تب
     *
     * اگر فایلِ مربوطه هنوز بارگیری نشده باشد، ابتدا بارگیری می‌شود و
     * سپس همین تابع دوباره فراخوانی می‌شود تا مقداردهیِ تب انجام شود.
     * کلاس‌های تب همین بالا تنظیم شده‌اند، بنابراین کاربر بلافاصله
     * تغییرِ تب را می‌بیند و فقط محتوا اندکی بعد می‌آید.
     */
    var __tabScripts = (window.MELKINO_TAB_SCRIPTS || {})[tabId] || [];
    var __loaded     = window.MELKINO_LOADED_SCRIPTS || {};
    var __missing    = [];

    for (var __i = 0; __i < __tabScripts.length; __i++) {
        if (!__loaded[__tabScripts[__i]]) {
            __missing.push(__tabScripts[__i]);
        }
    }

    if (__missing.length && typeof window.melkinoLoadAdminScripts === 'function') {
        window.melkinoLoadAdminScripts(__missing, function () {
            switchTab(tabId);
        });
        return;
    }

    if (tabId === 'ads') {
        if (typeof renderAds === 'function') { renderAds(); }
    }

    if (tabId === 'map' && typeof initMapTab === 'function') {
        initMapTab();
    }

    if (tabId === 'bots' && typeof loadBotSettings === 'function') {
        loadBotSettings();
    }

    if (tabId === 'images' && typeof loadAdminImages === 'function') {
        loadAdminImages();
    }

    if (tabId === 'promotions' && typeof loadPromotions === 'function') {
        loadPromotions();
    }

    if (tabId === 'theme' && typeof initThemeManager === 'function') {
        initThemeManager();
    }

    if (tabId === 'studio' && typeof initDesignStudio === 'function') {
        initDesignStudio();
    }

    if (tabId === 'backup' && typeof loadBackups === 'function') {
        loadBackups();
        if (typeof loadMelkinoExcelUi === 'function') loadMelkinoExcelUi();
    }


    if (tabId === 'requests') {
        if (typeof renderRequests === 'function') { renderRequests(); }
    }


    if (tabId === 'contact') {
        if (typeof loadContactSettings === 'function') { loadContactSettings(); }
        if (typeof bindContactLivePreview === 'function') { bindContactLivePreview(); }
        if (typeof loadConsultants === 'function') { loadConsultants(); }
    }

    if (tabId === 'users') {
        if (typeof loadAdminUsers === 'function') { loadAdminUsers(); }
            }

    if (tabId === 'visits') {
        if (typeof loadAdminVisits === 'function') { loadAdminVisits(); }
    }

    if (tabId === 'dashboard') {
        if (typeof loadAdminDashboard === 'function') { loadAdminDashboard(); }
    }

    if (tabId === 'global') {
        if (typeof loadGlobalSettings === 'function') { loadGlobalSettings(); }
    }

    if (tabId === 'colors') {
    }

    if (tabId === 'password') {
        renderPasswordSecurity();
    }

    if (tabId === 'support') {
        loadSupportTickets();
    }

    if (tabId === 'notifications' && typeof loadAdminNotifications === 'function') {
        loadAdminNotifications();
    }

    if (tabId === 'display' && typeof melkinoInitDisplayTab === 'function') {
        melkinoInitDisplayTab();
    }
    if (tabId === 'display' && typeof melkinoInitFieldDisplay === 'function') {
        melkinoInitFieldDisplay();
    }

    /* نوارهای آمار بالای تب‌ها همیشه به‌روز می‌مانند */
    try {
        if (typeof renderDashboard === 'function') renderDashboard();
    } catch (e) {}

    try { revealAdminNavGroup(adminNavGroupOf(tabId)); } catch (e) {}

}
</script>


<!-- =========================================================
     BOTS & CHANNEL
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-bots"
>
<?php require __DIR__ . '/admin-bots.php'; ?>
</div>


<!-- =========================================================
     IMAGES
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-images"
>
<?php require __DIR__ . '/admin-images.php'; ?>
</div>

<div
    role="tabpanel"
    class="tab-content"
    id="tab-advisor"
>
<?php require __DIR__ . '/views/admin/tabs/advisor.php'; ?>
</div>


<!-- =========================================================
     PROMOTIONS
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-promotions"
>
<?php require __DIR__ . '/admin-promotions.php'; ?>
</div>

<!-- =========================================================
     STUDIO STORY (استودیوی استوری ملکینو)
     ========================================================= -->
<div role="tabpanel" class="tab-content" id="tab-story">
<?php require __DIR__ . '/admin-ig-studio.php'; ?>
</div>

<!-- =========================================================
     PUBLISH CENTER (ماژول انتشار)
     ========================================================= -->
<div role="tabpanel" class="tab-content" id="tab-publish">
<?php require __DIR__ . '/admin-publish-center.php'; ?>
</div>


<!-- =========================================================
     DIAGNOSTICS
     ========================================================= -->

<!--
    محتوای تبِ عیب‌یاب (حدود ۲۴ کیلوبایت HTML و جاوااسکریپت) تنها زمانی
    از سرور گرفته می‌شود که ادمین واقعاً این تب را باز کند. پیش‌تر این
    حجم در هر بارگذاریِ پنل تولید و ارسال می‌شد، بی‌آن‌که بیشترِ اوقات
    به آن نیازی باشد.
-->
<div
    role="tabpanel"
    class="tab-content"
    id="tab-diagnostics"
>
    <div id="diagnosticsLazyHost"></div>
</div>

<script>
// escapeHtml: کارش این است که داده‌های کاربر قبل از وارد شدن به HTML
// escape شوند. قبلاً فقط در admin-ads.js (که به‌تأخیر و فقط برای تب
// آگهی‌ها لود می‌شد) تعریف شده بود؛ اگر تب دیگری مثل «کاربران» پیش
// از آن از آن استفاده می‌کرد، خطای "escapeHtml is not defined" می‌زد
// و جدول خالی می‌ماند. حالا از همان لحظه‌ی شروع صفحه در دسترس است.
if (typeof window.escapeHtml !== 'function') {
    window.escapeHtml = function (value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#39;',
                '"': '&quot;'
            }[ch];
        });
    };
}

// راند ۳۵: Helper مشترک «باز کردن تلگرام» سمت کلاینت برای رندر جدول‌ها.
// اعتبارسنجی سخت‌گیرانه: فقط username مطابق الگوی تلگرام یا آیدی کاملاً عددی؛
// هیچ مقدار خامی از دیتابیس مستقیم داخل href نمی‌رود.
function mkTgLink(tgId, username){
    const u = String(username||'').trim().replace(/^@/,'');
    if (/^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(u)) return 'https://t.me/' + encodeURIComponent(u);
    const id = String(tgId||'').trim();
    if (/^[1-9][0-9]{0,19}$/.test(id)) return 'tg://user?id=' + id;
    return null;
}
// راند ۶۴: عکس‌های پیش‌فرض انواع ملک برای انتخاب ادمین در مودال ویرایش
window.MELKINO_DEFAULT_IMAGES = <?= json_encode(melkinoDefaultImagesMap(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.MK_IC = Object.assign(window.MK_IC || {}, {
            megaphone: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10v4l11 5V5z"/><path d="M14 8a4 4 0 0 1 0 8"/><path d="M6 14v5"/></svg>',
            trash: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>',
            chat: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H9l-5 4z"/></svg>',
            plus: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
            search: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
            send: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 3 3 10.5l7 3 3 7z"/><path d="M21 3 10 13.5"/></svg>',
            star: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 2.7 5.8 6.3.8-4.6 4.3 1.2 6.1L12 17l-5.6 3 1.2-6.1L3 9.6l6.3-.8z"/></svg>',
            bank: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18M12 3 3 10h18z"/></svg>',
            coins: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg>',
            home: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7"/><path d="M6 9.5V21h12V9.5"/></svg>',
    save:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h11l3 3v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 3v5h7V3"/><path d="M8 21v-7h8v7"/></svg>',
    eye:      '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg>',
    x:        '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    upload:   '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14"/></svg>',
    download: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
    bulb:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.8.7 1 1.5 1 2.5h6c0-1 .2-1.8 1-2.5A6 6 0 0 0 12 3Z"/></svg>',
    lock:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
    gear:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M19.1 4.9 17 7M7 17l-2.1 2.1"/></svg>',
    user:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>',
    users:    '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.4 2.9-5.5 6.5-5.5s6.5 2.1 6.5 5.5"/><circle cx="17" cy="9" r="3"/><path d="M17.5 14.6c2.4.5 4 2.2 4 4.4"/></svg>',
    phone:    '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>',
    headset:  '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/></svg>',
    puzzle:   '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>',
    map:      '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 4-6 2v14l6-2 6 2 6-2V4l-6 2-6-2Z"/><path d="M9 4v14M15 6v14"/></svg>',
    image:    '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-4.5-4.5L7 21"/></svg>',
    list:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></svg>',
    edit:     '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>',
    globe:    '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18Z"/></svg>',
    check:    '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>'
});
function mkStatusHtml(ok, msg){
    const clean = String(msg || '').replace(/^[\u2705\u274C\u2714\u26D4]\s*/u, '');
    if (!clean) return '';
    const m = escapeHtml(clean);
    if (ok === true)  return '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;margin-inline-end:4px;color:var(--success);" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>' + m;
    if (ok === false) return '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" style="vertical-align:-3px;margin-inline-end:4px;color:var(--danger);" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/></svg>' + m;
    return m;
}
function mkTgSvg(){
    return '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4Z"/></svg>';
}
function mkTgIcon(tgId, username){
    const href = mkTgLink(tgId, username);
    if (!href) return '';
    const u = String(username||'').trim().replace(/^@/,'');
    const title = href.indexOf('https://t.me/') === 0 ? ('باز کردن تلگرام (@' + escapeHtml(u) + ')') : 'باز کردن تلگرام با آیدی عددی';
    return '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-icon" data-tg-open="1" href="' + escapeHtml(href) + '" target="_blank" rel="noopener noreferrer" title="' + title + '" aria-label="' + title + '">' + mkTgSvg() + '</a>';
}
function mkTgFull(tgId, username){
    const u = String(username||'').trim().replace(/^@/,'');
    const uOk = /^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(u);
    const id = String(tgId||'').trim();
    const idOk = /^[1-9][0-9]{0,19}$/.test(id);
    if (!uOk && !idOk) return '<span class="mk-btn mk-btn--sm mk-btn--outline mk-tg-off" aria-disabled="true">' + mkTgSvg() + ' تلگرام متصل نیست</span>';
    if (uOk) {
        let out = '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-btn" data-tg-open="1" href="https://t.me/' + encodeURIComponent(u) + '" target="_blank" rel="noopener noreferrer" title="باز کردن تلگرام (@' + escapeHtml(u) + ')">' + mkTgSvg() + ' باز کردن تلگرام (@' + escapeHtml(u) + ')</a>';
        if (idOk) out += ' <a class="mk-btn mk-btn--sm mk-btn--ghost mk-tg-icon" data-tg-open="1" href="tg://user?id=' + id + '" title="لینک عمیق با آیدی عددی" aria-label="لینک عمیق با آیدی عددی">' + mkTgSvg() + '</a>';
        return out;
    }
    return '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-btn" data-tg-open="1" href="tg://user?id=' + id + '" target="_blank" rel="noopener noreferrer" title="باز کردن تلگرام با آیدی عددی">' + mkTgSvg() + ' باز کردن تلگرام</a>';
}
function mkBaleSvg(){
    return '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H9l-5 4z"/></svg>';
}
function mkBaleLink(baleId, username){
    const u = String(username||'').trim().replace(/^@/,'');
    if (/^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(u)) return 'https://ble.ir/' + encodeURIComponent(u);
    const id = String(baleId||'').trim();
    if (/^[1-9][0-9]{0,19}$/.test(id)) return 'https://ble.ir/' + encodeURIComponent(id);
    return null;
}
function mkBaleFull(baleId, username){
    const u = String(username||'').trim().replace(/^@/,'');
    const uOk = /^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(u);
    const id = String(baleId||'').trim();
    const idOk = /^[1-9][0-9]{0,19}$/.test(id);
    if (!uOk && !idOk) return '<span class="mk-btn mk-btn--sm mk-btn--outline mk-tg-off" aria-disabled="true">' + mkBaleSvg() + ' بله متصل نیست</span>';
    if (uOk) {
        return '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-btn" href="https://ble.ir/' + encodeURIComponent(u) + '" target="_blank" rel="noopener noreferrer" title="باز کردن پروفایل در بله (@' + escapeHtml(u) + ')">' + mkBaleSvg() + ' باز کردن در بله (@' + escapeHtml(u) + ')</a>';
    }
    return '<a class="mk-btn mk-btn--sm mk-btn--outline mk-tg-btn" href="https://ble.ir/' + encodeURIComponent(id) + '" target="_blank" rel="noopener noreferrer" title="باز کردن پروفایل در بله">' + mkBaleSvg() + ' باز کردن در بله</a>';
}
// راند ۳۵: سازگاری Mini App — لینک t.me داخل وب‌ویو تلگرام با API رسمی
// باز می‌شود؛ لینک tg:// طبیعی به دست سیستم/کلاینت سپرده می‌شود
// (بدون پاپ‌آپ اجباری یا ریدایرکت عجیب).
document.addEventListener('click', function (e) {
    const t = e.target && e.target.closest ? e.target.closest('a[data-tg-open]') : null;
    if (!t) return;
    try {
        const href = t.getAttribute('href') || '';
        if (window.Telegram && Telegram.WebApp && typeof Telegram.WebApp.openTelegramLink === 'function' && /^https:\/\/t\.me\//i.test(href)) {
            e.preventDefault();
            Telegram.WebApp.openTelegramLink(href);
        }
    } catch (err) { /* رفتار پیش‌فرض مرورگر حفظ می‌شود */ }
}, true);
</script>

<script>
// اگر هر جای پنل خطای JS «بی‌صدا» رخ داد (مثلاً تابعی که از یک فایل
// لودنشده صدا زده شود)، قبلاً فقط دکمه بی‌پاسخ می‌ماند و ادمین فکر
// می‌کرد خراب است. حالا خطا یک‌بار به‌صورت پیام قابل‌فهم نشان داده
// می‌شود تا قابل گزارش و پیگیری باشد.
(function () {
    var shown = {};
    function report(msg) {
        msg = String(msg || '').slice(0, 400);
        if (!msg || shown[msg]) return;
        shown[msg] = true;
        try { console.error('[Melkino] ' + msg); } catch (e) {}
        try { alert('خطای غیرمنتظره در پنل:\n\n' + msg + '\n\nاگر این پیام تکرار شد، یک اسکرین‌شات از آن برای پشتیبانی بفرستید.'); } catch (e) {}
    }
    window.addEventListener('error', function (e) {
        if (e && e.message) report(e.message);
    });
    window.addEventListener('unhandledrejection', function (e) {
        var r = e && e.reason;
        report((r && (r.message || r.error)) || r);
    });
})();
</script>

<script>
(function () {
    var host = document.getElementById('diagnosticsLazyHost');
    if (!host) return;

    var loaded = false;

    function inject() {
        if (loaded) return;
        loaded = true;

        fetch('admin-diagnostics.php?action=view', { cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                // اجرایِ اسکریپت‌های همراهِ پاسخ
                var tmp = document.createElement('div');
                tmp.innerHTML = html;

                var scripts = tmp.querySelectorAll('script');
                var codes = [];
                for (var i = 0; i < scripts.length; i++) {
                    codes.push(scripts[i].textContent);
                    scripts[i].parentNode.removeChild(scripts[i]);
                }

                host.innerHTML = tmp.innerHTML;

                for (var j = 0; j < codes.length; j++) {
                    try {
                        var sc = document.createElement('script');
                        sc.textContent = codes[j];
                        document.body.appendChild(sc);
                    } catch (e) {}
                }
            })
            .catch(function () {
                host.innerHTML =
                    '<div class="admin-field-help" style="color:var(--danger)">' +
                    'خطا در بارگیریِ بخش عیب‌یاب.</div>';
            });
    }

    // هنگام باز شدنِ تب.
    // دکمهٔ مستقیم «عیب‌یابی سیستم» از منو حذف شده و حالا از طریق نوار
    // سوییچِ «عیب‌یابی و پشتیبان» باز می‌شود؛ پس به‌جای تکیه بر آن دکمه،
    // هم دکمه (اگر بود) و هم خودِ switchTab و هم چیپ سوییچ را پوشش می‌دهیم.
    var navBtn = document.querySelector(".tab-btn[onclick*=\"'diagnostics'\"]");
    if (navBtn) {
        navBtn.addEventListener('click', function () { setTimeout(inject, 0); });
    }
    document.addEventListener('click', function (e) {
        var chip = e.target && e.target.closest ? e.target.closest('[data-merge-go="diagnostics"]') : null;
        if (chip) setTimeout(inject, 0);
    });
    (function wrapForDiagnostics() {
        if (typeof window.switchTab !== 'function') { setTimeout(wrapForDiagnostics, 200); return; }
        if (window.switchTab.__mkDiagWrapped) return;
        var orig = window.switchTab;
        window.switchTab = function (tabId) {
            orig.apply(this, arguments);
            if (tabId === 'diagnostics') setTimeout(inject, 0);
        };
        window.switchTab.__mkDiagWrapped = true;
    })();

    // و اگر از طریقِ هشِ صفحه مستقیماً به این تب آمد
    window.addEventListener('hashchange', function () {
        if (window.location.hash === '#diagnostics') inject();
    });
})();
</script>


<!-- =========================================================
     THEME
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-theme"
>
<?php require __DIR__ . '/admin-theme-manager.php'; ?>
</div>

<div role="tabpanel" class="tab-content" id="tab-brand">
<?php (static function () { require __DIR__ . '/views/admin/tabs/brand.php'; })(); ?>
</div>
<div role="tabpanel" class="tab-content" id="tab-studio">
<?php require __DIR__ . '/design-studio.php'; ?>
</div>


<!-- =========================================================
     BACKUP
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-backup"
>
<?php require __DIR__ . '/admin-backup.php'; ?>
</div>



<?php require __DIR__ . '/admin-dashboard.php'; ?>
<?php require __DIR__ . '/admin-ads.php'; ?>
<?php require __DIR__ . '/admin-requests.php'; ?>
<?php require __DIR__ . '/admin-visits.php'; ?>
<div role="tabpanel" class="tab-content" id="tab-leads">
    <iframe id="mkLeadsFrame" data-src="admin-leads.php" loading="lazy" title="لیدها" style="width:100%;min-height:70vh;border:0;border-radius:16px;background:var(--surface);"></iframe>
</div>

<?php if (is_file(__DIR__ . '/admin-sms-tab.php')) { require __DIR__ . '/admin-sms-tab.php'; } ?>
<div role="tabpanel" class="tab-content" id="tab-assistant"></div>
<?php require __DIR__ . '/admin-map.php'; ?>
<!-- =========================================================
     USERS
     ========================================================= -->

<?php
/* تب «آمار»: دیگر در هر بارگذاری صفحه محاسبه نمی‌شود (حدود ۳۰ کوئری سنگین).
   محتوا با اولین بازکردن تب و از ?stats_fragment=1 بارگذاری می‌شود. */
?>
<div role="tabpanel" class="tab-content" id="tab-stats" aria-label="آمار" data-lazy-stats="1">
    <div class="mk-empty" style="padding:24px;">در حال بارگذاری آمار…</div>
</div>

<div
    role="tabpanel"
    class="tab-content"
    id="tab-users"
>

    <div class="admin-card">

        <div class="mk-page-head">
            <div>
                <h2 class="mk-page-title"><?= melkinoSvgIcon('users') ?> مدیریت کاربران</h2>
                <a class="btn-primary" href="admin-users-excel.php" download style="display:inline-block;margin:12px 0;padding:10px 14px;border-radius:10px;text-decoration:none">دریافت اکسل همهٔ کاربران</a>
                <p class="admin-field-help">همهٔ کاربران، مستقل از جست‌وجو و صفحه‌بندی؛ اطلاعات حساب، شماره و حساب‌های متصل، بدون رمز و توکن امنیتی. فایل شامل اطلاعات شخصی است؛ آن را محرمانه نگه دارید.</p>
            </div>
        </div>

        <div class="stats-grid" style="padding:0 16px;">

            <div class="stat-card">
                <div class="number" id="usersStatTotal">…</div>
                <div class="label">کاربران ثبت‌شده</div>
            </div>

            <div class="stat-card">
                <div class="number" id="usersStatVisits">…</div>
                <div class="label">ورود ۲۴ ساعت اخیر</div>
            </div>

        </div>

        <?php require __DIR__ . '/admin-site-visits-card.php'; ?>
        <div id="usersListContainer"></div>

    </div>

</div>


<!-- =========================================================
     ONBOARDING
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-onboarding"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                صفحات هدایت
            </span>

        </div>

        <div id="onboardingEditor"></div>

    </div>

    <?php
    if (is_file(__DIR__ . '/onboarding-tour.php')) {
        require_once __DIR__ . '/onboarding-tour.php';
        melkinoProductTourAdminCard();
    }
    ?>

    <!-- =========================================================
         لوگوی تیره ملکینو (برای تم تیره هدر سایت)
         ========================================================= -->
    <div class="admin-card" id="logoDarkCard" style="margin-top: 20px; border: 2px solid var(--primary);">
        <div class="card-header">
            <span class="card-title"><?= melkinoSvgIcon('image') ?> لوگوی تیره ملکینو</span>
        </div>
        <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center; padding: 15px 0;">
            <div style="flex: 0 0 200px; text-align: center;">
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">پیش‌نمایش فعلی (تم تیره)</div>
                <div style="background: #1f2430; border-radius: 12px; padding: 12px; border: 1px solid var(--border); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                    <img id="logoDarkPreview" src="<?php
                        require_once __DIR__ . '/melkino-logo.php';
                        echo htmlspecialchars(melkinoSiteDarkLogoUrl() ?: 'assets/images/melkino-login-logo.png', ENT_QUOTES, 'UTF-8');
                    ?>" alt="لوگوی تیره" style="max-width: 100%; max-height: 100px; object-fit: contain;">
                </div>
                <div id="logoDarkStatus" style="margin-top: 6px; font-size: 0.85rem; color: var(--text-secondary);"></div>
            </div>
            <div style="flex: 1; min-width: 250px;">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label for="logoDarkFileInput" style="display: block; margin-bottom: 4px; color: var(--text-primary); font-weight: 500;">انتخاب فایل لوگوی تیره</label>
                        <input type="file" id="logoDarkFileInput" accept=".png,.jpg,.jpeg,.webp" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary);">
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">برای پس‌زمینهٔ تیره؛ PNG با پس‌زمینهٔ شفاف و رنگ روشن</div>
                    </div>
                    <div>
                        <button type="button" id="uploadLogoDarkBtn" class="btn-icon-sm primary" style="background: var(--primary); color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer;">
                            <?= melkinoSvgIcon('upload') ?> آپلود و ذخیره لوگوی تیره
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         [NEW] کارت جدید: لوگوی اصلی ملکینو
         ========================================================= -->
    <div class="admin-card" style="margin-top: 20px; border: 2px solid var(--primary);">
        <div class="card-header">
            <span class="card-title"><?= melkinoSvgIcon('image') ?> لوگوی اصلی ملکینو</span>
        </div>
        <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 25px; align-items: center; padding: 15px 0;">
            <!-- ستون پیش‌نمایش -->
            <div style="flex: 0 0 200px; text-align: center;">
                <div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 8px;">پیش‌نمایش فعلی</div>
                <div id="logoPreviewContainer" style="background: #fff; border-radius: 12px; padding: 12px; border: 1px solid var(--border); min-height: 110px; display: flex; align-items: center; justify-content: center;">
                    <img id="logoPreview" src="<?php
                        require_once __DIR__ . '/melkino-logo.php';
                        echo melkinoSiteLogoUrl() ?: 'assets/images/melkino-login-logo.png';
                    ?>" alt="لوگوی ملکینو" style="max-width: 100%; max-height: 100px; object-fit: contain;">
                </div>
                <div id="logoStatus" style="margin-top: 6px; font-size: 0.85rem; color: var(--text-secondary);"></div>
            </div>

            <!-- ستون فرم آپلود -->
            <div style="flex: 1; min-width: 250px;">
                <form id="logoUploadForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label for="logoFileInput" style="display: block; margin-bottom: 4px; color: var(--text-primary); font-weight: 500;">انتخاب فایل لوگو</label>
                        <input type="file" id="logoFileInput" accept=".png,.jpg,.jpeg,.webp" style="width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary);">
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">فرمت‌های مجاز: PNG, JPG, JPEG, WEBP – حداکثر ۵ مگابایت</div>
                        <div style="font-size: 12px; color: var(--text-secondary);"><?= melkinoSvgIcon('bulb') ?> پیشنهاد: PNG با پس‌زمینه شفاف</div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <button type="button" id="uploadLogoBtn" class="btn-icon-sm primary" style="background: var(--primary); color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s;">
                            <?= melkinoSvgIcon('upload') ?> آپلود و ذخیره لوگو
                        </button>
                        <span id="fileNameDisplay" style="color: var(--text-secondary); font-size: 0.9rem;"></span>
                    </div>
                </form>
                <div id="uploadProgress" style="display: none; margin-top: 10px;">
                    <span style="color: var(--text-secondary);">در حال آپلود...</span>
                    <div style="width: 100%; height: 6px; background: var(--border); border-radius: 3px; margin-top: 4px; overflow: hidden;">
                        <div id="progressBar" style="width: 0%; height: 100%; background: var(--primary); transition: width 0.3s;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div style="padding: 10px 16px; background: var(--surface); border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--text-secondary); border-radius: 0 0 var(--radius-md) var(--radius-md);">
            <?= melkinoSvgIcon('bulb') ?> لوگوی اصلی سایت از این قسمت مدیریت می‌شود. پس از آپلود، هر صفحه‌ای که از لوگوی مرکزی استفاده کند، همین لوگو را نمایش خواهد داد.
        </div>
    </div>
    <!-- =========================================================
         پایان کارت جدید لوگو
         ========================================================= -->

</div>


<!-- =========================================================
     GLOBAL
     ========================================================= -->

<div
    class="tab-content"
    id="tab-global"
>

    <div class="admin-card" id="mkCalcAdminCard">
        <div class="card-header">
            <span class="card-title">ماشین‌حساب قیمت ملک</span>
        </div>
        <div style="padding:14px 16px 18px">
            <div class="security-switch" style="margin-bottom:16px">
                <div>
                    <span>فعال بودن ماشین‌حساب</span>
                    <small>اگر خاموش باشد آیکون هدر، میانبر خانه و صفحهٔ محاسبه برای عموم دیده نمی‌شود.</small>
                </div>
                <input id="g_enable_property_calculator" type="checkbox" checked>
            </div>
            <div class="admin-section-help" style="margin-bottom:10px">ضرایب فرمول — درصد را با اعشار دقیق وارد کنید.</div>
            <div class="admin-grid-2">
                <div class="admin-field"><label>استهلاک سالانه (٪ از قیمت هر متر)</label><input id="g_calc_estehklak" type="number" step="0.0001" min="0" max="100" value="1.5"></div>
                <div class="admin-field"><label>کسر وقفی (٪)</label><input id="g_calc_waqf" type="number" step="0.0001" min="0" max="100" value="20"></div>
                <div class="admin-field"><label>کسر بدون پارکینگ — حداقل (٪)</label><input id="g_calc_park_min" type="number" step="0.0001" min="0" max="100" value="8"></div>
                <div class="admin-field"><label>کسر بدون پارکینگ — پیش‌فرض (٪)</label><input id="g_calc_park_def" type="number" step="0.0001" min="0" max="100" value="9"></div>
                <div class="admin-field"><label>کسر بدون پارکینگ — حداکثر (٪)</label><input id="g_calc_park_max" type="number" step="0.0001" min="0" max="100" value="10"></div>
                <div class="admin-field"><label>کسر آسانسور به ازای هر طبقه بالای اول (٪)</label><input id="g_calc_elev" type="number" step="0.0001" min="0" max="100" value="2.5"></div>
                <div class="admin-field"><label>نسبت قیمت حیاط به قیمت هر متر (٪)</label><input id="g_calc_yard" type="number" step="0.0001" min="0" max="100" value="33.3333"></div>
                <div class="admin-field"><label>مقسوم‌علیه رهن کامل</label><input id="g_calc_rent_div" type="number" step="0.0001" min="0.0001" value="8"></div>
                <div class="admin-field"><label>مبنای رهن (تومان)</label><input id="g_calc_rent_base" type="number" step="1" min="1" value="100000000"></div>
                <div class="admin-field"><label>اجاره ماهانه به ازای هر واحد مبنا (تومان)</label><input id="g_calc_rent_per" type="number" step="1" min="0" value="3000000"></div>
            </div>
            <div style="margin-top:16px">
                <div class="admin-section-title" style="font-size:14px;margin-bottom:6px">آیتم‌های اضافی مثل انباری</div>
                <div class="admin-section-help" style="margin-bottom:8px">برای انباری معمولاً «متراژ × قیمت متر × نسبت» را بگذارید. می‌توانید آیتم جدید هم اضافه کنید.</div>
                <div id="mkCalcExtrasBox">
                    <div class="mk-calc-extra-row" data-id="storage" style="display:grid;grid-template-columns:1.3fr 1.1fr .7fr auto auto;gap:8px;align-items:end;margin-bottom:8px">
                        <div class="admin-field" style="margin:0"><label>نام آیتم</label><input class="mk-ex-label" value="انباری" placeholder="مثلاً انباری"></div>
                        <div class="admin-field" style="margin:0"><label>نوع محاسبه</label><select class="mk-ex-mode">
                            <option value="area_ratio" selected>متراژ × قیمت متر × نسبت</option>
                            <option value="percent_add">افزایش درصدی از قیمت</option>
                            <option value="percent_cut">کاهش درصدی از قیمت</option>
                        </select></div>
                        <div class="admin-field" style="margin:0"><label>مقدار (٪)</label><input class="mk-ex-pct" type="number" step="0.0001" min="0" max="200" value="50"></div>
                        <label class="admin-field" style="margin:0;display:flex;gap:6px;align-items:center;padding-bottom:10px"><input class="mk-ex-on" type="checkbox" checked> فعال</label>
                        <button type="button" class="btn-icon-sm mk-ex-del" style="margin-bottom:8px">حذف</button>
                    </div>
                </div>
                <button type="button" class="btn-icon-sm" id="mkCalcExtraAdd">افزودن آیتم</button>
            </div>
        </div>
    </div>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                گزینه‌های عمومی
            </span>

        </div>

        <div id="globalContainer"></div>

    </div>

</div>


<!-- =========================================================
     CONTACT
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-contact"
>

    <div class="admin-card">

        <div class="card-header">

            <div>

                <span class="card-title">
                    <?= melkinoSvgIcon('phone') ?> اطلاعات تماس ملکینو
                </span>

                <div
                    style="
                        font-size:12px;
                        color:var(--text-secondary);
                        margin-top:5px;
                    "
                >
                    اطلاعات این بخش مستقیماً در صفحه «ارتباط با ما» نمایش داده می‌شود.
                </div>

            </div>


            <span
                id="contactSaveState"
                style="
                    font-size:12px;
                    color:var(--text-secondary);
                "
            >
                آماده ویرایش
            </span>

        </div>


        <div id="contactContainer">
<style>
/* =========================================================
   تنظیمات مشاور
   ========================================================= */
.consultant-admin-card{
    margin-top:18px;
    padding:16px;
    border-radius:14px;
    border:1px solid rgba(212,175,55,.22);
    background:
        linear-gradient(145deg,rgba(212,175,55,.055),rgba(6,78,78,.025));
}
.consultant-admin-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
    flex-wrap:wrap;
}
.consultant-admin-title{
    font-size:15px;
    font-weight:900;
    color:var(--text-primary);
}
.consultant-admin-help{
    margin-top:4px;
    font-size:11px;
    line-height:1.8;
    color:var(--text-secondary);
}
.consultant-admin-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:11px;
}
.consultant-admin-field{
    display:flex;
    flex-direction:column;
    gap:6px;
}
.consultant-admin-field.full{
    grid-column:1/-1;
}
.consultant-admin-field label{
    font-size:12px;
    color:var(--text-secondary);
    font-weight:700;
}
.consultant-admin-field input{
    width:100%;
    box-sizing:border-box;
    padding:10px 11px;
    border:1px solid var(--border);
    border-radius:9px;
    background:var(--bg);
    color:var(--text-primary);
    font-family:'Vazirmatn',sans-serif;
    font-size:13px;
    outline:none;
}
.consultant-admin-field input:focus{
    border-color:var(--primary);
    box-shadow:0 0 0 2px rgba(6,78,78,.08);
}
.consultant-admin-preview{
    margin-top:14px;
    padding:12px;
    border-radius:11px;
    border:1px solid var(--border);
    background:var(--bg);
}
.consultant-admin-preview-title{
    font-size:12px;
    font-weight:800;
    color:var(--text-primary);
    margin-bottom:9px;
}
.consultant-preview-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:8px;
}
.consultant-preview-item{
    padding:9px 10px;
    border-radius:9px;
    background:var(--surface);
    border:1px solid var(--border);
}
.consultant-preview-item span{
    display:block;
    font-size:11px;
    color:var(--text-secondary);
    margin-bottom:3px;
}
.consultant-preview-item strong{
    display:block;
    font-size:12px;
    color:var(--text-primary);
    word-break:break-word;
}
#consultantSaveState{
    font-size:11px;
    color:var(--text-secondary);
}
@media(max-width:700px){
    .consultant-admin-grid,
    .consultant-preview-grid{
        grid-template-columns:1fr;
    }
}


/* =========================================================
   MELKINO COMMAND CENTER — VISIBLE REDESIGN
   ========================================================= */
.admin-command-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin:0 0 14px;
    padding:14px 18px;
    border-radius:20px;
    background:linear-gradient(135deg,#043b3b 0%,#064e4e 55%,#0a6661 100%);
    border:1px solid rgba(212,175,55,.22);
    box-shadow:0 16px 38px rgba(6,78,78,.18);
    color:#fff;
}
.admin-command-brand{display:flex;align-items:center;gap:11px;min-width:0}
.admin-command-mark{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(145deg,#f0d878,#c89d32);color:#173131;font-size:22px;font-weight:950;box-shadow:0 8px 18px rgba(212,175,55,.22)}
.admin-command-kicker{font-size:11px;letter-spacing:1.4px;opacity:.62;font-weight:900}
.admin-command-title{font-size:17px;font-weight:950;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-command-meta{display:flex;align-items:center;gap:7px;flex:0 0 auto;font-size:11px;font-weight:800;color:rgba(255,255,255,.74)}
.admin-command-date{padding:6px 9px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10)}
.admin-live-dot{width:7px;height:7px;border-radius:50%;background:#67e8a5;box-shadow:0 0 12px rgba(103,232,165,.7)}
.tabs-container{
    position:sticky !important;
    top:0 !important;
    z-index:8000 !important;
    gap:7px !important;
    padding:8px !important;
    border:1px solid var(--border) !important;
    border-radius:18px !important;
    background:var(--surface) !important;
    box-shadow:0 10px 25px rgba(0,0,0,.06) !important;
    backdrop-filter:blur(18px);
    -webkit-backdrop-filter:blur(18px);
}
.tab-btn{
    min-height:42px !important;
    padding:0 14px !important;
    border:1px solid transparent !important;
    border-bottom:none !important;
    border-radius:12px !important;
    background:transparent !important;
    color:var(--text-secondary) !important;
    font-size:12px !important;
}
.tab-btn:hover{background:var(--bg-secondary) !important;color:var(--primary) !important}
.tab-btn.active{
    background:linear-gradient(135deg,var(--primary),var(--primary-dark)) !important;
    color:#fff !important;
    border-color:transparent !important;
    box-shadow:0 7px 18px rgba(6,78,78,.18) !important;
}
.support-filter-btn.active{background:var(--primary) !important;color:#fff !important;border-color:var(--primary) !important}
.support-status-badge.status-open{background:#FEF3C7 !important;color:#92400E !important}
.support-status-badge.status-answered{background:#DCFCE7 !important;color:#166534 !important}
.support-status-badge.status-pending{background:#DBEAFE !important;color:#1E40AF !important}
.support-status-badge.status-closed{background:#F3F4F6 !important;color:#6B7280 !important}
.admin-static-security{padding:2px 0}
.admin-security-summary{min-height:50px;padding:12px;border:1px solid var(--border);border-radius:12px;background:var(--bg);display:flex;flex-direction:column;justify-content:center;gap:4px}
.admin-security-summary strong{color:var(--primary);font-size:13px}
.admin-security-summary span{color:var(--text-secondary);font-size:11px}
.admin-security-note{margin-top:12px;padding:11px 13px;border-radius:12px;background:var(--gold-bg);color:var(--text-secondary);font-size:11px;line-height:1.9}
@media(max-width:680px){
    .admin-command-header{align-items:flex-start;flex-direction:column}
    .admin-command-title{font-size:15px}
    .admin-command-meta{width:100%;justify-content:space-between}
    .tabs-container{overflow-x:auto;scrollbar-width:none}
    .tabs-container::-webkit-scrollbar{display:none}
    .tab-btn{flex:0 0 auto}
}
[data-theme="dark"] .admin-command-header{box-shadow:0 16px 38px rgba(0,0,0,.32)}
</style>


            <div class="contact-admin-grid">

                <!-- نام مجموعه -->

                <div class="contact-admin-field full">

                    <label for="adminContactAgencyName">
                        نام مجموعه
                    </label>

                    <input
                        type="text"
                        id="adminContactAgencyName"
                        placeholder="مثلاً املاک ملکینو شاهرود"
                    >

                </div>


                <!-- آدرس -->

                <div class="contact-admin-field full">

                    <label for="adminContactAddress">
                        آدرس دفتر
                    </label>

                    <textarea
                        id="adminContactAddress"
                        placeholder="آدرس کامل دفتر ملکینو"
                    ></textarea>

                </div>

                <!-- راند ۷۴: نقشهٔ زنده برای انتخاب موقعیت دفتر -->
                <div class="contact-admin-field full">
                    <label>موقعیت دفتر روی نقشه</label>
                    <div class="contact-admin-help" style="margin-bottom:8px;">
                        روی نقشه بزنید یا نشان را بکشید تا موقعیت دفتر انتخاب شود.
                        همین نقطه در صفحهٔ «ارتباط با ما» روی نقشهٔ زنده دیده می‌شود.
                    </div>
                    <div id="adminOfficeMap" style="height:280px;width:100%;border-radius:12px;border:1px solid var(--border);background:var(--bg-secondary);z-index:1;"></div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:flex-end;">
                        <div class="contact-admin-field" style="flex:1;min-width:140px;margin:0;">
                            <label for="adminOfficeLat">عرض جغرافیایی</label>
                            <input type="text" id="adminOfficeLat" dir="ltr" placeholder="36.4181" autocomplete="off">
                        </div>
                        <div class="contact-admin-field" style="flex:1;min-width:140px;margin:0;">
                            <label for="adminOfficeLng">طول جغرافیایی</label>
                            <input type="text" id="adminOfficeLng" dir="ltr" placeholder="54.9763" autocomplete="off">
                        </div>
                        <input type="hidden" id="adminOfficeZoom" value="15">
                        <button type="button" class="btn-secondary" onclick="mkUseMyLocation()">موقعیت فعلی من</button>
                    </div>
                </div>


                <!-- تلفن -->

                <div class="contact-admin-field">

                    <label for="adminContactPhone">
                        شماره تماس
                    </label>

                    <input
                        type="text"
                        id="adminContactPhone"
                        placeholder="مثلاً ۰۲۳-۳۲۲۲۲۲۲۲"
                        dir="ltr"
                    >

                </div>


                <!-- ایمیل -->

                <div class="contact-admin-field">

                    <label for="adminContactEmail">
                        ایمیل پشتیبانی
                    </label>

                    <input
                        type="email"
                        id="adminContactEmail"
                        placeholder="info@melkino.ir"
                        dir="ltr"
                    >

                </div>


                <!-- ساعت کاری -->

                <div class="contact-admin-field full">

                    <label for="adminContactWorkingHours">
                        ساعت کاری
                    </label>

                    <input
                        type="text"
                        id="adminContactWorkingHours"
                        placeholder="شنبه تا پنجشنبه، ۹ صبح تا ۸ شب"
                    >

                </div>


                <!-- واتساپ -->

                <div class="contact-admin-field">

                    <label for="adminContactWhatsapp">
                        واتساپ
                    </label>

                    <input
                        type="text"
                        id="adminContactWhatsapp"
                        placeholder="شماره یا لینک واتساپ"
                        dir="ltr"
                    >

                </div>


                <!-- تلگرام -->

                <div class="contact-admin-field">

                    <label for="adminContactTelegram">
                        لینک کانال تلگرام
                    </label>

                    <input
                        type="text" inputmode="url"
                        id="adminContactTelegram"
                        placeholder="https://t.me/..."
                        dir="ltr"
                    >

                    <div class="contact-admin-help">
                        مثال:
                        https://t.me/melkino
                    </div>

                    <label for="adminContactTelegramColor" style="margin-top:10px">رنگ کارت تلگرام</label>
                    <input type="color" id="adminContactTelegramColor" value="#174D46">

                    <label for="adminContactTelegramDesc" style="margin-top:10px">خط سوم کارت تلگرام</label>
                    <input type="text" id="adminContactTelegramDesc" placeholder="مثلاً مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو">

                </div>


                <!-- اینستاگرام -->

                <div class="contact-admin-field">

                    <label for="adminContactInstagram">
                        لینک اینستاگرام
                    </label>

                    <input
                        type="text" inputmode="url"
                        id="adminContactInstagram"
                        placeholder="https://instagram.com/..."
                        dir="ltr"
                    >

                    <div class="contact-admin-help">
                        مثال:
                        https://instagram.com/melkino
                    </div>

                    <label for="adminContactInstagramColor" style="margin-top:10px">رنگ کارت اینستاگرام</label>
                    <input type="color" id="adminContactInstagramColor" value="#4B3D32">

                    <label for="adminContactInstagramDesc" style="margin-top:10px">خط سوم کارت اینستاگرام</label>
                    <input type="text" id="adminContactInstagramDesc" placeholder="مثلاً تصاویر، فایل‌ها و محتوای اختصاصی ملکینو">

                </div>

                <!-- بله -->

                <div class="contact-admin-field">

                    <label for="adminContactBale">
                        لینک کانال بله
                    </label>

                    <input
                        type="text" inputmode="url"
                        id="adminContactBale"
                        placeholder="https://ble.ir/..."
                        dir="ltr"
                    >

                    <div class="contact-admin-help">
                        مثال:
                        https://ble.ir/melkino
                    </div>

                    <label for="adminContactBaleColor" style="margin-top:10px">رنگ کارت بله</label>
                    <input type="color" id="adminContactBaleColor" value="#4AB06A">

                    <label for="adminContactBaleDesc" style="margin-top:10px">خط سوم کارت بله</label>
                    <input type="text" id="adminContactBaleDesc" placeholder="مثلاً مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو">

                    <label style="margin-top:10px">لوگوی کانال بله</label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;">
                        <img id="adminBaleLogoPreview" alt="" style="display:none;width:48px;height:48px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;">
                        <input type="file" id="adminBaleLogoFile" accept="image/png,image/jpeg,image/webp,image/gif" style="font-size:12px;max-width:180px;">
                        <button type="button" class="btn-icon-sm primary" onclick="uploadBaleLogo()">آپلود لوگو</button>
                        <button type="button" class="btn-secondary" onclick="removeBaleLogo()">حذف</button>
                    </div>
                    <div class="contact-admin-help">همین لوگو روی کارت بله در صفحهٔ ارتباط با ما نمایش داده می‌شود.</div>

                </div>



            </div>


            <!-- =====================================================
                 کارت‌های سفارشیِ صفحه‌ی «ارتباط با ما»
                 ===================================================== -->

            <div class="consultant-admin-card" style="border-style:solid;">

                <div class="consultants-toolbar">

                    <div>

                        <div class="consultant-admin-title">
                            <?= melkinoSvgIcon('puzzle') ?> کارت‌های سفارشیِ «ارتباط با ما»
                        </div>

                        <div class="consultant-admin-help">
                            کارت خالی نشان داده نمی‌شود. با دکمهٔ + کارت جدید بسازید؛
                            برای هر کارت متن دکمه، نام پیام‌رسان، آدرس کانال و آیکون را تنظیم کنید.
                        </div>

                    </div>

                </div>

                <div
                    id="customCardsManager"
                    class="consultant-manager-list"
                ></div>

            </div>



            <!-- =====================================================
                 مدیریت حرفه‌ای مشاوران
                 ===================================================== -->
            <div class="consultant-admin-card" style="border-style:solid;">
                <div class="consultants-toolbar">
                    <div>
                        <div class="consultant-admin-title"><?= melkinoSvgIcon('users') ?> مدیریت مشاوران</div>
                        <div class="consultant-admin-help">حداکثر ۱۰ مشاور، برای هر مشاور حداکثر ۱۰ تخصص؛ هر تخصص با نوع ملک + نوع معامله تعریف می‌شود. اگر چند مشاور یک تخصص را داشته باشند، اولویت کمتر برنده است و در نبود مشاور تخصصی، مشاور پیش‌فرض استفاده می‌شود.</div>
                    </div>
                    <button type="button" class="btn-icon-sm gold" onclick="addConsultant()">＋ افزودن مشاور</button>
                </div>
                <div id="consultantsManager" class="consultant-manager-list"></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                    <button type="button" class="btn-icon-sm primary" onclick="saveConsultants()" style="min-width:210px;height:46px;font-size:14px;"><?= melkinoSvgIcon('save') ?>  ذخیره همه مشاوران</button>
                    <button type="button" class="btn-secondary" onclick="loadConsultants()" style="min-height:46px;">↻ بازیابی</button>
                    <span id="consultantsSaveState" style="align-self:center;font-size:11px;color:var(--text-secondary);"></span>
                </div>
            </div>

            <!-- Preview -->

            <div class="contact-admin-preview">

                <div class="contact-admin-preview-title">
                    پیش‌نمایش اطلاعات تماس
                </div>


                <div class="contact-preview-grid">

                    <div>

                        <span>
                            نام مجموعه
                        </span>

                        <strong id="previewAgencyName">
                            -
                        </strong>

                    </div>


                    <div>

                        <span>
                            تلفن
                        </span>

                        <strong id="previewPhone">
                            -
                        </strong>

                    </div>


                    <div>

                        <span>
                            تلگرام
                        </span>

                        <strong id="previewTelegram">
                            ثبت نشده
                        </strong>

                    </div>


                    <div>

                        <span>
                            اینستاگرام
                        </span>

                        <strong id="previewInstagram">
                            ثبت نشده
                        </strong>

                    </div>

                </div>

            </div>


            <div class="contact-save-note">

                تغییرات این بخش با دکمه زیر ذخیره می‌شوند و صفحه «ارتباط با ما»
                به صورت خودکار اطلاعات ذخیره‌شده را نمایش می‌دهد.

            </div>


            <div
                style="
                    display:flex;
                    gap:10px;
                    flex-wrap:wrap;
                    margin-top:18px;
                "
            >

                <button
                    type="button"
                    class="btn-icon-sm primary"
                    onclick="saveContactSettings()"
                    style="
                        min-width:190px;
                        height:46px;
                        font-size:14px;
                    "
                >
                    <?= melkinoSvgIcon('save') ?>  ذخیره اطلاعات تماس
                </button>


                <button
                    type="button"
                    class="btn-secondary"
                    onclick="loadContactSettings()"
                    style="min-height:46px;"
                >
                    ↻ بازیابی اطلاعات ذخیره‌شده
                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     SUPPORT
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-support"
>

    <div class="admin-card">

        <div class="card-header">
            <span class="card-title"><?= melkinoSvgIcon('headset') ?> تیکت‌های پشتیبانی</span>
            <span id="supportTicketCount" class="admin-section-help"></span>
        </div>

        <div class="stats-grid" style="padding:0 16px;">

            <div class="stat-card">
                <div class="number" id="supportStatOpen">…</div>
                <div class="label">تیکت باز</div>
            </div>

            <div class="stat-card">
                <div class="number" id="supportStatTotal">…</div>
                <div class="label">کل تیکت‌ها</div>
            </div>

        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; padding:0 16px 12px;">
            <button type="button" class="btn-secondary support-filter-btn active" data-status="all" onclick="filterSupportTickets('all', this)">همه</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="open" onclick="filterSupportTickets('open', this)">در انتظار بررسی</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="answered" onclick="filterSupportTickets('answered', this)">پاسخ داده‌شده</button>
            <button type="button" class="btn-secondary support-filter-btn" data-status="closed" onclick="filterSupportTickets('closed', this)">بسته‌شده</button>
            <button type="button" class="btn-secondary" onclick="loadSupportTickets()" style="margin-inline-start:auto;">↻ بروزرسانی</button>
        </div>

        <div id="supportTicketsList" style="padding:0 16px 16px;">در حال بارگذاری…</div>

    </div>

    <div class="admin-card" id="supportConversationCard" style="display:none;">

        <div class="card-header">
            <span class="card-title" id="supportConversationTitle">گفتگو</span>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-secondary" id="supportCloseBtn" onclick="setSupportTicketStatus('close')">بستن تیکت</button>
                <button type="button" class="btn-secondary" id="supportReopenBtn" onclick="setSupportTicketStatus('reopen')" style="display:none;">بازگشایی تیکت</button>
                <button type="button" class="btn-secondary" onclick="document.getElementById('supportConversationCard').style.display='none';">✕ بستن</button>
            </div>
        </div>

        <div id="supportMessages" style="padding:16px; display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto;"></div>

        <div style="display:flex; gap:8px; padding:16px; border-top:1px solid var(--border);">
            <textarea id="supportReplyText" rows="2" placeholder="پاسخ خود را بنویسید..." style="flex:1; resize:vertical; border:1px solid var(--border); border-radius:8px; padding:10px; font-family:inherit; background:var(--bg); color:var(--text-primary);"></textarea>
            <button type="button" class="btn-primary" onclick="sendSupportReply()">ارسال پاسخ</button>
        </div>

    </div>

</div>


<!-- =========================================================
     NOTIFICATIONS
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-notifications"
>
<?php require __DIR__ . '/admin-notifications.php'; ?>
</div>


<!-- =========================================================
     DISPLAY — مدیریت کارت‌های آگهی (راند ۲۰)
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-display"
>
    <!-- راند ۲۹: ساب‌تب‌های سیستم مدیریت فیلدها -->
    <div class="fd-subtabs">
        <button type="button" class="fd-subtab active" data-fdsub="home" onclick="melkinoFdSwitchSub('home', this)"><svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> کارت صفحهٔ اصلی</button>
        <button type="button" class="fd-subtab" data-fdsub="details" onclick="melkinoFdSwitchSub('details', this)"><svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 17h4"/></svg> صفحهٔ جزئیات ملک</button>
        <button type="button" class="fd-subtab" data-fdsub="cards" onclick="melkinoFdSwitchSub('cards', this)"><svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M9 9v11"/></svg> کارت فهرست/VIP و حباب‌ها</button>
    </div>

    <!-- ============ ساب‌تب: کارت صفحهٔ اصلی (راند ۲۹) ============ -->
    <div class="fd-subpane" id="fdSubHome">
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title"><?= melkinoSvgIcon('puzzle') ?> مدیریت فیلدهای کارت صفحهٔ اصلی</span>
                <span id="fdHomeMeta" class="fd-meta"></span>
            </div>
            <div style="padding:0 16px 16px;">
                <div class="admin-field-help" style="margin-bottom:12px;">
                    ترتیب فیلدها را با <strong>کشیدن و رها کردن</strong> عوض کنید؛ برای هر فیلد
                    <strong>نمایش/عدم نمایش</strong>، <strong>عنوان</strong>، <strong>آیکون</strong>،
                    <strong>حالت (حباب/چیپ)</strong>، <strong>فرمت ارقام</strong> و
                    <strong>نمایش در موبایل/تبلت/دسکتاپ</strong> را تنظیم کنید.
                    پیش‌نمایش پایین با <strong>آگهی واقعی</strong> و <strong>رندرر واقعی سایت</strong> زنده به‌روز می‌شود.
                    تا «ذخیره» را نزنید هیچ تغییری در دیتابیس ثبت نمی‌شود.
                    این تب <strong>فقط کارت‌های صفحهٔ اصلی (خانه)</strong> را تنظیم می‌کند؛
                    فهرست آگهی‌ها و VIP تب جدا دارند. پیش‌نمایش پایین همزمان با هر تغییر به‌روز می‌شود.
                </div>
                <div class="fd-toolbar">
                    <label class="fd-toolbar-label">آگهی نمونه:
                        <select id="fdHomeSample" class="fd-select"></select>
                    </label>
                    <span class="fd-devices" id="fdHomeDevices">
                        <button type="button" data-w="320">320</button>
                        <button type="button" data-w="375">375</button>
                        <button type="button" data-w="390" class="on">390</button>
                        <button type="button" data-w="430">430</button>
                        <button type="button" data-w="768">768</button>
                        <button type="button" data-w="820">820</button>
                        <button type="button" data-w="1024">1024</button>
                        <button type="button" data-w="1280">1280</button>
                        <button type="button" data-w="1440">1440</button>
                    </span>
                    <button type="button" class="btn-secondary fd-btn-sm" onclick="melkinoFdRefreshPreview('home')">↻ پیش‌نمایش</button>
                </div>
                <div id="fdHomeFields"><div class="admin-field-help">در حال بارگذاری…</div></div>
                <div class="fd-actions">
                    <button type="button" class="btn-primary" onclick="event.preventDefault();melkinoFdSave('home');return false;"><?= melkinoSvgIcon('save') ?>  ذخیرهٔ تغییرات</button>
                    <button type="button" class="btn-secondary" onclick="melkinoFdCancel('home')"><?= melkinoSvgIcon('x') ?> لغو</button>
                    <button type="button" class="btn-secondary" id="fdHomeRestore" onclick="melkinoFdRestore('home')">↩ بازگردانی آخرین تنظیمات</button>
                    <span id="fdHomeStatus" class="admin-status-msg"></span>
                </div>
            </div>
        </div>
        <div class="admin-card">
            <div class="card-header"><span class="card-title"><?= melkinoSvgIcon('eye') ?> پیش‌نمایش واقعی کارت (رندرر سایت + دادهٔ واقعی)</span></div>
            <div style="padding:14px 16px;">
                <div class="fd-device-frame"><iframe id="fdHomePreview" title="پیش‌نمایش کارت صفحهٔ اصلی"></iframe></div>
            </div>
        </div>
    </div>

    <!-- ============ ساب‌تب: صفحهٔ جزئیات (راند ۲۹) ============ -->
    <div class="fd-subpane" id="fdSubDetails" style="display:none">
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title"><?= melkinoSvgIcon('list') ?> مدیریت بخش‌ها و فیلدهای صفحهٔ جزئیات ملک</span>
                <span id="fdDetailsMeta" class="fd-meta"></span>
            </div>
            <div style="padding:0 16px 16px;">
                <div class="admin-field-help" style="margin-bottom:12px;">
                    بخش‌های صفحهٔ جزئیات (گالری، اطلاعات اصلی، قیمت، مشخصات، امکانات، توضیحات، نوار تماس) را
                    با <strong>کشیدن و رها کردن</strong> جابه‌جا، خاموش/روشن کنید و عنوان و آیکونشان را تغییر دهید.
                    با «▸ مدیریت فیلدها» فیلدهای داخل هر بخش (و مشخصات هر نوع ملک) را مدیریت کنید.
                </div>
                <div class="fd-toolbar">
                    <label class="fd-toolbar-label">آگهی نمونه:
                        <select id="fdDetailsSample" class="fd-select"></select>
                    </label>
                    <span class="fd-devices" id="fdDetailsDevices">
                        <button type="button" data-w="320">320</button>
                        <button type="button" data-w="375">375</button>
                        <button type="button" data-w="390" class="on">390</button>
                        <button type="button" data-w="430">430</button>
                        <button type="button" data-w="768">768</button>
                        <button type="button" data-w="820">820</button>
                        <button type="button" data-w="1024">1024</button>
                        <button type="button" data-w="1280">1280</button>
                        <button type="button" data-w="1440">1440</button>
                    </span>
                    <button type="button" class="btn-secondary fd-btn-sm" onclick="melkinoFdRefreshPreview('details')">↻ پیش‌نمایش</button>
                </div>
                <div id="fdDetailsSections"><div class="admin-field-help">در حال بارگذاری…</div></div>
                <div class="fd-actions">
                    <button type="button" class="btn-primary" onclick="event.preventDefault();melkinoFdSave('details');return false;"><?= melkinoSvgIcon('save') ?>  ذخیرهٔ تغییرات</button>
                    <button type="button" class="btn-secondary" onclick="melkinoFdCancel('details')"><?= melkinoSvgIcon('x') ?> لغو</button>
                    <button type="button" class="btn-secondary" id="fdDetailsRestore" onclick="melkinoFdRestore('details')">↩ بازگردانی آخرین تنظیمات</button>
                    <span id="fdDetailsStatus" class="admin-status-msg"></span>
                </div>
            </div>
        </div>
        <div class="admin-card">
            <div class="card-header"><span class="card-title"><?= melkinoSvgIcon('eye') ?> پیش‌نمایش واقعی صفحهٔ جزئیات</span></div>
            <div style="padding:14px 16px;">
                <div class="fd-device-frame"><iframe id="fdDetailsPreview" title="پیش‌نمایش صفحهٔ جزئیات"></iframe></div>
            </div>
        </div>
    </div>

    <!-- ============ ساب‌تب: تنظیمات فعلی کارت‌های فهرست/VIP ============ -->
    <div class="fd-subpane" id="fdSubCards" style="display:none">
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?= melkinoSvgIcon('image') ?> نمایش کارت‌های آگهی</span>
            <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="melkinoInitDisplayTab(true)">↻ به‌روزرسانی</button>
        </div>
        <div style="padding:0 16px 16px;">
            <div class="admin-field-help" style="margin-bottom:12px;">
                برای <strong>هر فیلد آگهی</strong> (مشخصات عمومی + تمام فیلدهای تخصصی هر نوع ملک) حالت نمایش را انتخاب کنید:
                <strong>متن</strong> (چیپ کوچک زیر عنوان)، <strong>حباب</strong> (بج رنگی) یا <strong>مخفی</strong>.
                فیلدهای تخصصی فقط وقتی روی کارت می‌آیند که در آن آگهی <strong>پر شده باشند</strong>.
                این تب <strong>فقط کارت‌های «همه آگهی‌ها» و VIP</strong> را تنظیم می‌کند (جدا از کارت خانه).
                پیش‌نمایش پایین همزمان با هر کلیک به‌روز می‌شود؛ برای اعمال در سایت «ذخیره» را بزنید.
            </div>
            <div id="cardDisplayControls"><div class="admin-field-help">در حال بارگذاری…</div></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;align-items:center;">
                <button type="button" class="btn-primary" style="padding:8px 18px;font-size:13px;" onclick="melkinoSaveCardDisplay()"><?= melkinoSvgIcon('save') ?>  ذخیرهٔ تنظیمات نمایش</button>
                <button type="button" class="btn-secondary" style="padding:8px 18px;font-size:13px;" onclick="melkinoResetCardDisplay()">↺ بازنشانی به پیش‌فرض</button>
                <span id="cardDisplayStatus" class="admin-status-msg"></span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?= melkinoSvgIcon('eye') ?> پیش‌نمایش زندهٔ کارت‌ها</span>
        </div>
        <div style="padding:14px 16px;">
            <div id="cardDisplayPreview" class="cd-preview-wrap"></div>
        </div>
    </div>
    </div><!-- /fdSubCards -->
</div>


<!-- =========================================================
     FORMS — گزینه‌های کمبوباکس
     ========================================================= -->

<div
    role="tabpanel"
    class="tab-content"
    id="tab-forms"
>
    <div class="admin-card">
        <div class="admin-section-head">
            <div>
                <div class="admin-section-title">گزینه‌های کمبوباکس فرم‌ها</div>
                <div class="admin-section-help">موارد هر فهرست در ثبت ملک و ثبت درخواست از دیتابیس خوانده می‌شود. گزینه را اضافه/حذف کنید و حتماً دکمه ذخیره را بزنید.</div>
            </div>
            <button type="button" class="btn-primary" id="formsCombosSaveBtn" onclick="melkinoFormsSaveCombos()" style="min-width:220px;height:48px;padding:0 22px;font-size:15px;font-weight:800;cursor:pointer;">ذخیره فرم‌ها در دیتابیس</button>
        </div>
        <div id="formsCombosBox" style="display:grid;gap:14px;margin-top:12px;">
<?php
if (function_exists('melkinoFormComboCatalog')) {
    foreach (melkinoFormComboCatalog() as $comboKey => $comboDef) {
        $comboLabel = (string) ($comboDef['label'] ?? $comboKey);
        $comboItems = is_array($comboDef['items'] ?? null) ? $comboDef['items'] : [];
        echo '<div class="admin-field" data-combo="' . htmlspecialchars($comboKey, ENT_QUOTES, 'UTF-8') . '" style="border:1px solid rgba(212,175,55,.22);border-radius:12px;padding:12px;">';
        echo '<label style="font-weight:800;margin-bottom:8px;display:block;">' . htmlspecialchars($comboLabel, ENT_QUOTES, 'UTF-8') . '</label>';
        echo '<div class="forms-combo-list">';
        foreach ($comboItems as $comboItem) {
            $comboItem = trim((string) $comboItem);
            if ($comboItem === '') {
                continue;
            }
            echo '<div style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">';
            echo '<input type="text" value="' . htmlspecialchars($comboItem, ENT_QUOTES, 'UTF-8') . '" style="flex:1;">';
            echo '<button type="button" class="btn-icon-sm" onclick="this.parentNode.remove()">حذف</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<button type="button" class="btn-icon-sm" onclick="melkinoFormsAddItem(this)">+ گزینه</button>';
        echo '</div>';
    }
}
?>
        </div>
        <p id="formsCombosMsg" class="admin-section-help" style="margin-top:10px;"></p>
        <button type="button" class="btn-primary" onclick="melkinoFormsSaveCombos()" style="min-width:220px;height:48px;padding:0 22px;font-size:15px;font-weight:800;margin-top:8px;cursor:pointer;">ذخیره فرم‌ها در دیتابیس</button>
    </div>
    <script>
    (function () {
        function esc(s) {
            return String(s || '').replace(/[&<>"]/g, function (c) {
                return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);
            });
        }
        window.melkinoFormsRenderCombos = function (data) {
            var box = document.getElementById('formsCombosBox');
            if (!box) return;
            var html = '';
            var keys = Object.keys(data || {});
            keys.forEach(function (k) {
                var d = data[k] || {};
                var items = d.items || [];
                html += '<div class="admin-field" data-combo="' + esc(k) + '" style="border:1px solid rgba(212,175,55,.22);border-radius:12px;padding:12px;">';
                html += '<label style="font-weight:800;margin-bottom:8px;display:block;">' + esc(d.label || k) + '</label>';
                html += '<div class="forms-combo-list">';
                items.forEach(function (it) {
                    html += '<div style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">';
                    html += '<input type="text" value="' + esc(it) + '" style="flex:1;">';
                    html += '<button type="button" class="btn-icon-sm" onclick="this.parentNode.remove()">حذف</button>';
                    html += '</div>';
                });
                html += '</div>';
                html += '<button type="button" class="btn-icon-sm" onclick="melkinoFormsAddItem(this)">+ گزینه</button>';
                html += '</div>';
            });
            box.innerHTML = html || '<div class="admin-section-help">فهرستی پیدا نشد.</div>';
        };
        window.melkinoFormsAddItem = function (btn) {
            var list = btn.parentNode.querySelector('.forms-combo-list');
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
            row.innerHTML = '<input type="text" value="" placeholder="گزینه جدید" style="flex:1;"><button type="button" class="btn-icon-sm" onclick="this.parentNode.remove()">حذف</button>';
            list.appendChild(row);
            var inp = row.querySelector('input');
            if (inp) inp.focus();
        };
        window.melkinoFormsSaveCombos = function () {
            var box = document.getElementById('formsCombosBox');
            var msg = document.getElementById('formsCombosMsg');
            var payload = {};
            box.querySelectorAll('[data-combo]').forEach(function (card) {
                var k = card.getAttribute('data-combo');
                var items = [];
                card.querySelectorAll('.forms-combo-list input').forEach(function (inp) {
                    var v = String(inp.value || '').trim();
                    if (v) items.push(v);
                });
                payload[k] = items;
            });
            msg.textContent = 'در حال ذخیره…';
            var body = JSON.stringify({ action: 'save', combos: payload });
            var opts = {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: body
            };
            function ok(j) {
                msg.textContent = (j && j.message) ? j.message : 'در دیتابیس ذخیره شد. فرم ثبت ملک را یک‌بار رفرش کنید.';
                if (j && j.combos) melkinoFormsRenderCombos(j.combos);
            }
            function fail(j) {
                msg.textContent = (j && j.message) ? j.message : 'ذخیره در دیتابیس نشد.';
            }
            fetch('save_form_options.php', opts).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (x) {
                if (x.j && x.j.success) { ok(x.j); return; }
                return fetch('admin-panel.php?forms_combos=1', opts).then(function (r2) { return r2.json(); }).then(function (j2) {
                    if (j2 && j2.success) ok(j2); else fail(j2 || x.j);
                });
            }).catch(function () {
                fetch('admin-panel.php?forms_combos=1', opts).then(function (r) { return r.json(); }).then(function (j) {
                    if (j && j.success) ok(j); else fail(j);
                }).catch(function () { msg.textContent = 'خطا در ذخیره. فایل save_form_options.php را هم آپلود کنید.'; });
            });
        };
        fetch('save_form_options.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.combos) melkinoFormsRenderCombos(j.combos);
        }).catch(function () {
            fetch('form-options.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.combos) melkinoFormsRenderCombos(j.combos);
            }).catch(function () {});
        });
    })();
    </script>
</div>

<div
    role="tabpanel"
    class="tab-content"
    id="tab-password"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                تغییر رمز
            </span>

        </div>

        <div id="passwordContainer">
    <div class="admin-static-security">
        <div class="admin-section-head">
            <div>
                <div class="admin-section-title"><?= melkinoSvgIcon('lock') ?> امنیت پنل مدیریت</div>
                <div class="admin-section-help">رمز عبور، نشست ادمین و محافظت از ورود از همین بخش مدیریت می‌شود.</div>
            </div>
        </div>
        <div class="admin-grid-2">
            <div class="admin-field"><label>رمز فعلی</label><input type="password" placeholder="رمز فعلی" autocomplete="current-password"></div>
            <div class="admin-field"><label>رمز جدید</label><input type="password" placeholder="حداقل ۸ کاراکتر" autocomplete="new-password"></div>
            <div class="admin-field"><label>تکرار رمز جدید</label><input type="password" placeholder="تکرار رمز جدید" autocomplete="new-password"></div>
            <div class="admin-security-summary"><strong>امنیت فعال</strong><span>قفل ورود • لاگ ورود • Timeout نشست</span></div>
        </div>
        <div class="admin-security-note">برای مدیریت کامل تنظیمات امنیتی، تب تغییر رمز پس از بارگذاری JavaScript تکمیل می‌شود.</div>
    </div>
</div>

    </div>

</div>


<button
    class="btn-save"
    onclick="saveAndExit()"
>
    <?= melkinoSvgIcon('save') ?>  ذخیره تغییرات و بازگشت به خانه
</button>

</div>
</div>


<?php require __DIR__ . '/admin-ads-modals.php'; // مودال‌های آگهی (ویرایش/جزئیات) — آهنگساز انتشار از داخل آن حذف شده (راند ۲۵) ?>

<script>

// ==============================================
// داده‌های اولیه
// ==============================================

let adsData = <?= melkinoJsJson($adsData) ?>;
window.MELKINO_RATING_ENABLED = <?= (function_exists('melkinoRatingFeatureEnabled') && melkinoRatingFeatureEnabled()) ? 'true' : 'false' ?>;

// =========================================================
// فراداده‌ی بارگذاریِ آگهی‌ها و درخواست‌ها
// =========================================================
// چون حالا فقط جدیدترین آگهی‌ها همراه صفحه می‌آیند، اعدادِ داشبورد
// نباید از روی همین زیرمجموعه حساب شوند؛ برای همین آمارِ واقعی از
// سمت سرور اینجا منتشر می‌شود.
window.MELKINO_ADS_META = {
    loaded:  <?= (int)$adsLoadedCount ?>,
    total:   <?= (int)$adsTotalCount ?>,
    hasMore: <?= $adsHasMore ? 'true' : 'false' ?>,
    error:   <?= melkinoJsJson((string)$adsLoadError) ?>
};
window.MELKINO_AD_TOTALS = <?= melkinoJsJson($adsTotals) ?>;
window.MELKINO_REQUESTS_META = {
    loaded: <?= count($requestsData) ?>,
    total:  <?= (int)($requestsTotalCount ?? count($requestsData)) ?>,
    new_count: <?= (int)($requestsTotals['new_count'] ?? 0) ?>,
    tracking_count: <?= (int)($requestsTotals['tracking_count'] ?? 0) ?>,
    matched: <?= (int)($requestsTotals['matched'] ?? 0) ?>,
    matches: <?= (int)($requestsTotals['matches'] ?? 0) ?>
};

// =========================================================
// اطلاع‌رسانی و بارگذاریِ مرحله‌ای در سمتِ مرورگر
// =========================================================
(function () {
    var M = window.MELKINO_ADS_META || {};

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function makeBox(bg, html) {
        var d = document.createElement('div');
        d.setAttribute(
            'style',
            'position:fixed;left:14px;bottom:14px;z-index:10050;max-width:min(430px,calc(100vw - 28px));' +
            'background:' + bg + ';color:#fff;border-radius:14px;padding:12px 14px;' +
            'font-family:inherit;font-size:13px;line-height:1.8;direction:rtl;' +
            'box-shadow:0 12px 34px rgba(0,0,0,.28)'
        );
        d.innerHTML = html;
        document.body.appendChild(d);
        return d;
    }

    function loadRest(box, btn) {
        btn.disabled = true;
        btn.textContent = 'در حال بارگذاری…';

        function step() {
            var offset = adsData.length;
            fetch('admin-panel.php?action=ads_chunk&offset=' + offset + '&limit=200', { cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.success) {
                        btn.disabled = false;
                        btn.textContent = 'خطا: ' + ((j && j.message) || 'پاسخ نامعتبر');
                        return;
                    }
                    var arr = j.ads || [];
                    for (var i = 0; i < arr.length; i++) { adsData.push(arr[i]); }
                    window.MELKINO_ADS_META.loaded = adsData.length;

                    try { if (typeof renderDashboard === 'function') renderDashboard(); } catch (e) {}
                    try { if (typeof renderAds === 'function') renderAds(); } catch (e) {}

                    if (j.hasMore) {
                        btn.textContent = 'در حال بارگذاری… (' + adsData.length + ')';
                        step();
                        return;
                    }

                    window.MELKINO_ADS_META.hasMore = false;
                    if (box.parentNode) { box.parentNode.removeChild(box); }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = 'خطا در ارتباط با سرور';
                });
        }

        step();
    }

    document.addEventListener('DOMContentLoaded', function () {

        // اگر بارگذاریِ آگهی‌ها با خطا مواجه شده باشد، دیگر پنهانش نمی‌کنیم؛
        // ادمین باید بداند پنل به داده‌ی واقعی وصل نیست.
        if (M.error) {
            makeBox(
                '#b00020',
                '<b>' + MK_IC.warn + ' آگهی‌ها از پایگاه داده خوانده نشدند</b><br>' +
                'پنل در حال نمایشِ داده‌ی نمونه است. علت فنی:' +
                '<div style="margin-top:6px;font-size:11px;opacity:.9;direction:ltr;text-align:left">' +
                esc(M.error) + '</div>'
            );
            return;
        }

        if (!M.hasMore) { return; }

        var box = makeBox(
            '#0b5d59',
            '<b>تنها ' + Number(M.loaded) + ' آگهی از ' + Number(M.total) + ' آگهی لود شده است.</b><br>' +
            '<span style="font-size:11px;opacity:.85">' +
            'برای اینکه پنل سریع بالا بیاید، فقط جدیدترین‌ها همراه صفحه آمده‌اند.' +
            '</span>'
        );

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = MK_IC.download + ' بارگذاری بقیه (' + Number(Number(M.total) - Number(M.loaded)) + ')';
        btn.setAttribute(
            'style',
            'margin-top:9px;border:0;background:#fff;color:#0b5d59;border-radius:9px;' +
            'padding:7px 13px;font-family:inherit;font-weight:800;cursor:pointer;font-size:12px'
        );
        box.appendChild(btn);
        btn.onclick = function () { loadRest(box, btn); };
    });
})();

let requestsData = <?= melkinoJsJson($requestsData) ?>;

const isMockMode =
    <?php echo $isMockMode ? 'true' : 'false'; ?>;

let currentAdFilter = 'all';

let appData = {};


// ==============================================
// اطلاعات تماس
// مستقل از سیستم آگهی‌ها و درخواست‌ها
// ==============================================

const MELKINO_CONTACT_STORAGE_KEY =
    'melkino_contact_info';


/* =========================================================
   هلپرهای فیلدهای اطلاعات تماس
   (قبلاً این توابع تعریف نشده بودند و تب «ارتباط با ما»
   با خطای ReferenceError می‌شکست؛ فرم کارت‌ها رندر نمی‌شد)
   ========================================================= */

function getContactFieldValue(id) {

    const el =
        document.getElementById(id);

    if (!el) {
        return '';
    }

    return String(
        el.value ?? ''
    ).trim();
}


function setContactFieldValue(id, value) {

    const el =
        document.getElementById(id);

    if (!el) {
        return;
    }

    el.value =
        value === null ||
        value === undefined
            ? ''
            : String(value);
}


function updateContactPreview() {

    const pairs = [
        ['previewAgencyName', 'adminContactAgencyName'],
        ['previewPhone', 'adminContactPhone'],
        ['previewTelegram', 'adminContactTelegram'],
        ['previewInstagram', 'adminContactInstagram']
    ];

    pairs.forEach(function (pair) {

        const previewEl =
            document.getElementById(pair[0]);

        if (!previewEl) {
            return;
        }

        const value =
            getContactFieldValue(pair[1]);

        previewEl.textContent =
            value !== ''
                ? value
                : '-';
    });
}


/* =========================================================
   تصویر نقشه دفتر (جایگزین نقشه زنده نشان)
   ادمین از نقشه اسکرین‌شات می‌گیرد و اینجا آپلود می‌کند؛
   همین عکس در صفحه «ارتباط با ما» نمایش داده می‌شود.
   ========================================================= */

let mapImagePath = '';
let baleLogoPath = '';

function setBaleLogoPreview(path) {
    baleLogoPath = String(path || '');
    const img = document.getElementById('adminBaleLogoPreview');
    if (!img) return;
    if (baleLogoPath) {
        img.src = baleLogoPath;
        img.style.display = 'block';
    } else {
        img.removeAttribute('src');
        img.style.display = 'none';
    }
}
async function uploadBaleLogo() {
    const input = document.getElementById('adminBaleLogoFile');
    const file = input && input.files && input.files[0];
    if (!file) { alert('اول یک تصویر انتخاب کن.'); return; }
    const fd = new FormData();
    fd.append('icon', file);
    if (window.MELKINO_CSRF) fd.append('csrf_token', window.MELKINO_CSRF);
    try {
        const r = await fetch('upload-card-icon.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j && j.success && j.path) {
            setBaleLogoPreview(j.path);
        } else {
            alert((j && j.message) || 'آپلود لوگو ناموفق بود.');
        }
    } catch (e) {
        alert('خطا در آپلود لوگو.');
    }
}
function removeBaleLogo() {
    setBaleLogoPreview('');
}


function setMapImagePreview(path, quiet) {

    mapImagePath =
        String(path || '');

    const img =
        document.getElementById(
            'adminMapImagePreview'
        );

    if (img) {

        if (mapImagePath !== '') {
            img.src = mapImagePath;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
    }

    if (!quiet) {

        const state =
            document.getElementById(
                'mapImageState'
            );

        if (state) {
            state.textContent =
                mapImagePath !== ''
                    ? 'تصویر نقشه انتخاب شده است. با دکمه «ذخیره» پایین صفحه ذخیره‌اش کن.'
                    : '';
        }
    }
}


async function uploadMapImage() {

    const input =
        document.getElementById(
            'adminMapImageFile'
        );

    const file =
        input &&
        input.files &&
        input.files[0];

    if (!file) {
        alert('اول یک عکس انتخاب کن.');
        return;
    }

    const state =
        document.getElementById(
            'mapImageState'
        );

    if (state) {
        state.textContent = 'در حال آپلود…';
    }

    const formData =
        new FormData();

    formData.append('map', file);

    try {

        const response =
            await fetch(
                'upload-contact-map.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }
            );

        const result =
            await response.json();

        if (result && result.success) {
            setMapImagePreview(result.path);
        } else if (state) {
            state.textContent =
                'آپلود ناموفق بود: ' +
                (
                    (result && result.message) ||
                    'خطای ناشناخته'
                );
        }

    } catch (error) {

        console.error(
            'upload map image error:',
            error
        );

        if (state) {
            state.textContent =
                'خطا در ارتباط با سرور.';
        }
    }
}


function removeMapImage() {

    const input =
        document.getElementById(
            'adminMapImageFile'
        );

    if (input) {
        input.value = '';
    }

    setMapImagePreview('');

    const state =
        document.getElementById(
            'mapImageState'
        );

    if (state) {
        state.textContent =
            'تصویر حذف شد. با دکمه «ذخیره» پایین صفحه ثبتش کن.';
    }
}

function getDefaultContactSettings() {

    return {

        agencyName:
            'املاک ملکینو شاهرود',

        address:
            '',

        phone:
            '',

        email:
            '',

        whatsapp:
            '',

        telegram:
            '',

        instagram:
            '',

        workingHours:
            '',

        mapImage:
            '',

        officeLat: '',
        officeLng: '',
        officeZoom: 15,

        bale:
            '',

        telegramColor: '#174D46',
        instagramColor: '#4B3D32',
        baleColor: '#4AB06A',
        telegramDescription: 'مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو',
        instagramDescription: 'تصاویر، فایل‌ها و محتوای اختصاصی ملکینو',
        baleDescription: 'مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو',

        customCards: []
    };
}


let customCardsData = [];


function customCardsDefaultArray() {
    return [];
}
function customCardBlank() {
    return { label: '', messenger: '', url: '', icon: '', description: '', color: '#2A4A62' };
}
function addCustomCard() {
    if (customCardsData.length >= 8) { alert('حداکثر ۸ کارت سفارشی.'); return; }
    customCardsData.push(customCardBlank());
    renderCustomCards();
}
function removeCustomCard(index) {
    customCardsData.splice(index, 1);
    renderCustomCards();
}


function customCardEsc(value) {

    return String(
        value ?? ''
    ).replace(/[&<>"']/g, function (ch) {

        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[ch];
    });
}


function updateCustomCard(index, key, value) {

    if (!customCardsData[index]) {

        customCardsData[index] = {
            label: '',
            messenger: '',
            url: '',
            icon: '',
            description: '',
            color: '#2A4A62'
        };
    }

    customCardsData[index][key] = value;
}


function renderCustomCards() {

    const host =
        document.getElementById(
            'customCardsManager'
        );

    if (!host) {
        return;
    }

    let html = '';
    if (!Array.isArray(customCardsData)) customCardsData = [];

    for (let i = 0; i < customCardsData.length; i++) {

        const card =
            customCardsData[i] ||
            { label: '', messenger: '', url: '', icon: '', description: '', color: '#2A4A62' };

        const previewIcon =
            card.icon
                ? '<img src="' + customCardEsc(card.icon) + '" alt="" style="width:34px;height:34px;object-fit:contain;border-radius:8px;background:var(--bg-secondary);flex-shrink:0">'
                : '<div style="width:34px;height:34px;border-radius:8px;background:var(--bg-secondary);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:15px">' + MK_IC.globe + '</div>';

        html +=
            '<div class="consultant-item" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;align-items:end;padding:14px;border:1px solid var(--border);border-radius:12px;margin-bottom:12px">' +

            '<div class="admin-field">' +
            '<label>کارت ' + (i + 1) + ' — متنِ نمایشی</label>' +
            '<input placeholder="مثال: کانال ایتا" value="' + customCardEsc(card.label) + '" oninput="updateCustomCard(' + i + ',\'label\',this.value)">' +
            '</div>' +

            '<div class="admin-field">' +
            '<label>نام پیام‌رسان</label>' +
            '<input placeholder="مثال: ایتا" value="' + customCardEsc(card.messenger) + '" oninput="updateCustomCard(' + i + ',\'messenger\',this.value)">' +
            '</div>' +

            '<div class="admin-field">' +
            '<label>آدرسِ کانال / لینک</label>' +
            '<input dir="ltr" placeholder="https://eitaa.com/..." value="' + customCardEsc(card.url) + '" oninput="updateCustomCard(' + i + ',\'url\',this.value)">' +
            '</div>' +

            '<div class="admin-field">' +
            '<label>آیکونِ پیام‌رسان</label>' +
            '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">' +
            previewIcon +
            '<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" onchange="uploadCustomCardIcon(' + i + ', this)" style="font-size:11px;max-width:170px">' +
            '</div>' +
            '</div>' +

            '<div class="admin-field" style="grid-column:1/-1">' +
            '<label>خط سوم کارت (توضیح زیر نام)</label>' +
            '<input placeholder="مثلاً مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو" value="' + customCardEsc(card.description) + '" oninput="updateCustomCard(' + i + ',\'description\',this.value)">' +
            '</div>' +
            '<div class="admin-field">' +
            '<label>رنگ کارت</label>' +
            '<input type="color" value="' + customCardEsc(card.color || '#2A4A62') + '" oninput="updateCustomCard(' + i + ',\'color\',this.value)" style="width:52px;height:36px;padding:2px;border:1px solid var(--border);border-radius:8px;background:transparent">' +
            '</div>' +
            '<div class="admin-field"><button type="button" class="btn-secondary" onclick="removeCustomCard(' + i + ')">حذف کارت</button></div>' +

            '</div>';
    }

    html += '<button type="button" class="btn-icon-sm gold" onclick="addCustomCard()" style="min-width:160px;height:44px;">＋ افزودن کارت سفارشی</button>';
    host.innerHTML = html;
}


async function uploadCustomCardIcon(index, input) {

    const file =
        input.files && input.files[0];

    if (!file) {
        return;
    }

    const formData =
        new FormData();

    formData.append(
        'icon',
        file
    );

    try {

        const response =
            await fetch(
                'upload-card-icon.php',
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }
            );

        const result =
            await response.json();

        if (result && result.success) {

            updateCustomCard(
                index,
                'icon',
                result.path
            );

            renderCustomCards();

        } else {

            alert(
                'آپلود آیکون ناموفق بود: ' +
                (result && result.message
                    ? result.message
                    : 'خطای ناشناخته')
            );
        }

    } catch (error) {

        console.error(
            'upload card icon error:',
            error
        );

        alert(
            'خطا در ارتباط با سرور هنگام آپلود آیکون.'
        );
    }
}


async function loadContactSettingsData() {

    const defaults =
        getDefaultContactSettings();


    let merged =
        Object.assign({}, defaults);


    /* ۱) ابتدا localStorage (سازگاری با نسخه‌های قبل) */

    try {

        const saved =
            localStorage.getItem(
                MELKINO_CONTACT_STORAGE_KEY
            );

        if (saved) {

            const parsed =
                JSON.parse(saved);

            if (parsed && typeof parsed === 'object') {

                merged =
                    Object.assign(
                        {},
                        merged,
                        parsed
                    );
            }
        }

    } catch (error) {

        console.error(
            'خطا در خواندن اطلاعات تماس:',
            error
        );
    }


    /* ۲) سپس سرور — اولویت با مقدارِ سرور است
          چون برای همه‌ی بازدیدکنندگان یکسان است */

    try {

        const response =
            await fetch(
                'save-contact-settings.php',
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

        if (response.ok) {

            const result =
                await response.json();

            if (
                result &&
                result.success &&
                result.data &&
                typeof result.data === 'object'
            ) {

                merged =
                    Object.assign(
                        {},
                        merged,
                        result.data
                    );
            }
        }

    } catch (error) {

        console.warn(
            'دریافت اطلاعات تماس از سرور ناموفق بود:',
            error
        );
    }


    if (!Array.isArray(merged.customCards)) {
        merged.customCards = [];
    }

    return merged;
}


async function loadContactSettings() {

    const data =
        await loadContactSettingsData();


    setContactFieldValue(
        'adminContactAgencyName',
        data.agencyName
    );

    setContactFieldValue(
        'adminContactAddress',
        data.address
    );

    setContactFieldValue(
        'adminContactPhone',
        data.phone
    );

    setContactFieldValue(
        'adminContactEmail',
        data.email
    );

    setContactFieldValue(
        'adminContactWorkingHours',
        data.workingHours
    );

    setContactFieldValue(
        'adminContactWhatsapp',
        data.whatsapp
    );

    setContactFieldValue(
        'adminContactTelegram',
        data.telegram
    );

    setContactFieldValue(
        'adminContactInstagram',
        data.instagram
    );

    setMapImagePreview(data.mapImage, true);
    setBaleLogoPreview(data.baleLogo || '');

    setContactFieldValue('adminOfficeLat', data.officeLat || '');
    setContactFieldValue('adminOfficeLng', data.officeLng || '');
    setContactFieldValue('adminOfficeZoom', data.officeZoom || 15);
    if (typeof window.mkRefreshOfficeMap === 'function') {
        setTimeout(window.mkRefreshOfficeMap, 120);
    }

    setContactFieldValue(
        'adminContactBale',
        data.bale
    );

    setContactFieldValue('adminContactTelegramColor', data.telegramColor || '#174D46');
    setContactFieldValue('adminContactInstagramColor', data.instagramColor || '#4B3D32');
    setContactFieldValue('adminContactBaleColor', data.baleColor || '#4AB06A');
    setContactFieldValue('adminContactTelegramDesc', data.telegramDescription || 'مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو');
    setContactFieldValue('adminContactInstagramDesc', data.instagramDescription || 'تصاویر، فایل‌ها و محتوای اختصاصی ملکینو');
    setContactFieldValue('adminContactBaleDesc', data.baleDescription || 'مشاهده فایل‌ها و جدیدترین آگهی‌های ملکینو');


    const incomingCards =
        Array.isArray(data.customCards)
            ? data.customCards
            : [];

    customCardsData = incomingCards
        .map(function (card) {
            card = card || {};
            return {
                label: String(card.label || ''),
                messenger: String(card.messenger || ''),
                url: String(card.url || ''),
                icon: String(card.icon || ''),
                description: String(card.description || ''),
                color: String(card.color || '#2A4A62')
            };
        })
        .filter(function (card) {
            return String(card.label || '').trim() !== '' || String(card.url || '').trim() !== '';
        });

    renderCustomCards();


    updateContactPreview();


    const state =
        document.getElementById(
            'contactSaveState'
        );


    if (state) {

        state.textContent =
            'اطلاعات ذخیره‌شده بارگذاری شد';

        state.style.color =
            'var(--text-secondary)';
    }
}


/* راند ۷۴: نقشهٔ انتخاب موقعیت دفتر (Leaflet + OSM، بدون کلید API) */
window.__mkOfficeMap = null;
window.__mkOfficeMarker = null;
var MK_DEFAULT_LAT = 36.4181;
var MK_DEFAULT_LNG = 54.9763;

function mkEnsureLeaflet(cb) {
    if (window.L && window.L.map) { cb(); return; }
    if (window.__mkLeafletLoading) { window.__mkLeafletLoading.push(cb); return; }
    window.__mkLeafletLoading = [cb];
    // اول نسخه‌ی همراه بسته (assets/vendor/leaflet) و بعد CDN؛ اگر هر دو شکست بخورند، پیام خطا می‌دهیم
    var css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'assets/vendor/leaflet/leaflet.css';
    document.head.appendChild(css);
    function mkLeafletDone() {
        var q = window.__mkLeafletLoading || [];
        window.__mkLeafletLoading = null;
        q.forEach(function (fn) { try { fn(); } catch (e) {} });
    }
    function mkLeafletFailed() {
        window.__mkLeafletLoading = null;
        ['adminOfficeMap', 'editAdMap', 'adminAdsMap'].forEach(function (id) {
            var box = document.getElementById(id);
            if (box && !box.childElementCount) {
                box.textContent = 'نقشه بارگذاری نشد. اتصال یا فایل‌های نقشه را بررسی کنید.';
                box.style.display = 'flex';
                box.style.alignItems = 'center';
                box.style.justifyContent = 'center';
                box.style.fontSize = '13px';
                box.style.color = '#8a5a00';
            }
        });
    }
    var s = document.createElement('script');
    s.src = 'assets/vendor/leaflet/leaflet.js';
    s.onload = mkLeafletDone;
    s.onerror = function () {
        var cdnCss = document.createElement('link');
        cdnCss.rel = 'stylesheet';
        cdnCss.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
        document.head.appendChild(cdnCss);
        var s2 = document.createElement('script');
        s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
        s2.onload = mkLeafletDone;
        s2.onerror = mkLeafletFailed;
        document.head.appendChild(s2);
    };
    document.head.appendChild(s);
}

function mkSetOfficeLatLng(lat, lng, zoom) {
    var latEl = document.getElementById('adminOfficeLat');
    var lngEl = document.getElementById('adminOfficeLng');
    var zEl = document.getElementById('adminOfficeZoom');
    if (latEl) latEl.value = Number(lat).toFixed(6);
    if (lngEl) lngEl.value = Number(lng).toFixed(6);
    if (zoom && zEl) zEl.value = String(zoom);
    if (window.__mkOfficeMarker) window.__mkOfficeMarker.setLatLng([lat, lng]);
}

function mkRefreshOfficeMap() {
    mkEnsureLeaflet(function () {
        var el = document.getElementById('adminOfficeMap');
        if (!el || !window.L) return;
        var lat = parseFloat((document.getElementById('adminOfficeLat') || {}).value) || MK_DEFAULT_LAT;
        var lng = parseFloat((document.getElementById('adminOfficeLng') || {}).value) || MK_DEFAULT_LNG;
        var zoom = parseInt((document.getElementById('adminOfficeZoom') || {}).value, 10) || 15;
        if (!window.__mkOfficeMap) {
            window.__mkOfficeMap = L.map(el).setView([lat, lng], zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(window.__mkOfficeMap);
            window.__mkOfficeMarker = L.marker([lat, lng], { draggable: true }).addTo(window.__mkOfficeMap);
            window.__mkOfficeMap.on('click', function (e) {
                mkSetOfficeLatLng(e.latlng.lat, e.latlng.lng, window.__mkOfficeMap.getZoom());
            });
            window.__mkOfficeMarker.on('dragend', function (e) {
                var p = e.target.getLatLng();
                mkSetOfficeLatLng(p.lat, p.lng, window.__mkOfficeMap.getZoom());
            });
            window.__mkOfficeMap.on('zoomend', function () {
                var zEl = document.getElementById('adminOfficeZoom');
                if (zEl) zEl.value = String(window.__mkOfficeMap.getZoom());
            });
        } else {
            window.__mkOfficeMap.setView([lat, lng], zoom);
            if (window.__mkOfficeMarker) window.__mkOfficeMarker.setLatLng([lat, lng]);
        }
        setTimeout(function () { try { window.__mkOfficeMap.invalidateSize(); } catch (e) {} }, 80);
    });
}
window.mkRefreshOfficeMap = mkRefreshOfficeMap;

function mkUseMyLocation() {
    if (!navigator.geolocation) { alert('موقعیت‌یاب در این مرورگر در دسترس نیست.'); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
        mkSetOfficeLatLng(pos.coords.latitude, pos.coords.longitude, 16);
        mkRefreshOfficeMap();
    }, function () { alert('دسترسی به موقعیت مکانی رد شد.'); });
}

async function saveContactSettings() {

    const telegram =
        getContactFieldValue(
            'adminContactTelegram'
        );

    const instagram =
        getContactFieldValue(
            'adminContactInstagram'
        );

    const bale =
        getContactFieldValue(
            'adminContactBale'
        );


    const contactData = {

        agencyName:
            getContactFieldValue(
                'adminContactAgencyName'
            ),

        address:
            getContactFieldValue(
                'adminContactAddress'
            ),

        phone:
            getContactFieldValue(
                'adminContactPhone'
            ),

        email:
            getContactFieldValue(
                'adminContactEmail'
            ),

        whatsapp:
            getContactFieldValue(
                'adminContactWhatsapp'
            ),

        telegram:
            telegram,

        instagram:
            instagram,

        bale:
            bale,

        telegramColor:
            getContactFieldValue('adminContactTelegramColor') || '#174D46',
        instagramColor:
            getContactFieldValue('adminContactInstagramColor') || '#4B3D32',
        baleColor:
            getContactFieldValue('adminContactBaleColor') || '#4AB06A',
        telegramDescription:
            getContactFieldValue('adminContactTelegramDesc'),
        instagramDescription:
            getContactFieldValue('adminContactInstagramDesc'),
        baleDescription:
            getContactFieldValue('adminContactBaleDesc'),

        mapImage:
            mapImagePath,
        baleLogo: baleLogoPath,

        officeLat:
            getContactFieldValue('adminOfficeLat'),

        officeLng:
            getContactFieldValue('adminOfficeLng'),

        officeZoom:
            getContactFieldValue('adminOfficeZoom'),

        workingHours:
            getContactFieldValue(
                'adminContactWorkingHours'
            ),

        customCards:
            (customCardsData || []).filter(function (c) {
                c = c || {};
                return String(c.label || '').trim() !== '' || String(c.url || '').trim() !== '';
            })
    };


    if (
        telegram &&
        !/^https?:\/\//i.test(telegram)
    ) {

        alert(
            'لینک تلگرام باید با http:// یا https:// شروع شود.'
        );

        return;
    }


    if (
        instagram &&
        !/^https?:\/\//i.test(instagram)
    ) {

        alert(
            'لینک اینستاگرام باید با http:// یا https:// شروع شود.'
        );

        return;
    }


    if (
        bale &&
        !/^https?:\/\//i.test(bale)
    ) {

        alert(
            'لینک بله باید با http:// یا https:// شروع شود.'
        );

        return;
    }


    /* بررسیِ آدرسِ کارت‌های سفارشی */

    for (let i = 0; i < customCardsData.length; i++) {

        const card =
            customCardsData[i] || {};

        const cardUrl =
            String(card.url || '').trim();

        const cardLabel =
            String(card.label || '').trim();

        if (cardUrl && !/^https?:\/\//i.test(cardUrl)) {

            alert(
                'آدرسِ کارت ' + (i + 1) +
                ' باید با http:// یا https:// شروع شود.'
            );

            return;
        }

        if (cardLabel && !cardUrl) {

            alert(
                'کارت ' + (i + 1) +
                ' متن دارد اما آدرس ندارد. لطفاً آدرس را هم وارد کنید.'
            );

            return;
        }
    }


    const state =
        document.getElementById(
            'contactSaveState'
        );


    /* ۱) ذخیره‌ی محلی (سازگاری با قبل) */

    try {

        localStorage.setItem(
            MELKINO_CONTACT_STORAGE_KEY,
            JSON.stringify(
                contactData
            )
        );

    } catch (error) {

        console.error(
            'خطا در ذخیره‌ی محلی اطلاعات تماس:',
            error
        );
    }


    /* ۲) ذخیره روی سرور — این همان چیزی است که
          بازدیدکنندگان واقعاً می‌بینند */

    try {

        if (state) {

            state.textContent =
                'در حال ذخیره روی سرور…';

            state.style.color =
                'var(--text-secondary)';
        }

        const response =
            await fetch(
                'save-contact-settings.php',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(
                        contactData
                    )
                }
            );

        const result =
            await response.json();

        updateContactPreview();


        if (result && result.success) {

            if (state) {

                state.textContent =
                    'اطلاعات با موفقیت روی سرور ذخیره شد';

                state.style.color =
                    '#059669';
            }

            alert(
                'اطلاعات تماس با موفقیت روی سرور ذخیره شد و در صفحه «ارتباط با ما» نمایش داده می‌شود.'
            );

            return;
        }


        if (state) {

            state.textContent =
                'ذخیره روی سرور ناموفق بود';

            state.style.color =
                '#dc2626';
        }

        alert(
            'ذخیره روی سرور انجام نشد:\n' +
            (result && result.message
                ? result.message
                : 'خطای ناشناخته')
        );

    } catch (error) {

        console.error(
            'خطا در ذخیره اطلاعات تماس:',
            error
        );


        if (state) {

            state.textContent =
                'خطا در ارتباط با سرور';

            state.style.color =
                '#dc2626';
        }

        alert(
            'ارتباط با سرور برقرار نشد؛ اطلاعات فقط در این مرورگر ذخیره شد.'
        );
    }
}


function bindContactLivePreview() {

    const fieldIds = [

        'adminContactAgencyName',
        'adminContactAddress',
        'adminContactPhone',
        'adminContactEmail',
        'adminContactWorkingHours',
        'adminContactWhatsapp',
        'adminContactTelegram',
        'adminContactInstagram',
        'adminContactBale'

    ];


    fieldIds.forEach(function (id) {

        const element =
            document.getElementById(id);


        if (!element) {
            return;
        }


        element.addEventListener(
            'input',
            updateContactPreview
        );
    });
}


// ==============================================
// مدیریت حرفه‌ای مشاوران
// ==============================================

const CONSULTANT_TYPES = ['آپارتمان','ویلا','زمین','باغ','تجاری','اداری','مغازه'];
const CONSULTANT_TRANSACTIONS = ['فروش','اجاره','رهن کامل','رهن و اجاره','پیش فروش'];
let consultantsData = [];

function consultantEsc(value){
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function consultantDefaultItem(index){
    return {id:'c'+(index+1),name:'',phone:'',telegram_username:'',telegram_link:'',active:true,is_default:false,priority:index+1,specialties:[]};
}

async function loadConsultants(){
    const state=document.getElementById('consultantsSaveState');
    if(state) state.textContent='در حال بارگذاری...';
    try{
        const response=await fetch('save_consultants.php?action=list&t='+Date.now(),{cache:'no-store'});
        const result=await response.json();
        if(!response.ok || !result.success) throw new Error(result.message||'خطا در بارگذاری مشاوران');
        consultantsData=Array.isArray(result.consultants)?result.consultants:[];
    }catch(error){
        consultantsData=[{id:'c1',name:'مشاور پیش‌فرض ملکینو',phone:'',telegram_username:'',telegram_link:'',active:true,is_default:true,priority:1,specialties:[]}];
        if(state) state.textContent='مشاور پیش‌فرض آماده ثبت است';
    }
    renderConsultants();
    if(state && !state.textContent) state.textContent='';
}

function addConsultant(){
    if(consultantsData.length>=10){alert('حداکثر ۱۰ مشاور مجاز است.');return;}
    consultantsData.push(consultantDefaultItem(consultantsData.length));
    if(!consultantsData.some(x=>x.is_default)) consultantsData[consultantsData.length-1].is_default=true;
    renderConsultants();
}

function removeConsultant(index){
    if(consultantsData.length<=1){alert('حداقل یک مشاور باید باقی بماند.');return;}
    const removed=consultantsData.splice(index,1)[0];
    if(removed?.is_default && consultantsData[0]) consultantsData[0].is_default=true;
    renderConsultants();
}

function updateConsultant(index,key,value){
    if(!consultantsData[index]) return;
    consultantsData[index][key]=value;
    if(key==='is_default' && value){consultantsData.forEach((c,i)=>{if(i!==index)c.is_default=false;});}
}

function addConsultantSpecialty(index){
    const c=consultantsData[index]; if(!c)return;
    c.specialties=Array.isArray(c.specialties)?c.specialties:[];
    if(c.specialties.length>=10){alert('برای هر مشاور حداکثر ۱۰ تخصص مجاز است.');return;}
    const pt=document.getElementById('spec-p-'+index)?.value||'';
    const tt=document.getElementById('spec-t-'+index)?.value||'';
    if(!pt||!tt){alert('نوع ملک و نوع معامله را انتخاب کنید.');return;}
    if(c.specialties.some(s=>s.property_type===pt&&s.transaction_type===tt)){alert('این ترکیب قبلاً برای مشاور ثبت شده است.');return;}
    c.specialties.push({property_type:pt,transaction_type:tt});
    renderConsultants();
}

function removeConsultantSpecialty(index,sIndex){
    if(!consultantsData[index])return;
    consultantsData[index].specialties.splice(sIndex,1);
    renderConsultants();
}

function renderConsultants(){
    const container=document.getElementById('consultantsManager'); if(!container)return;
    if(!consultantsData.length){container.innerHTML='<div class="consultant-empty">هنوز مشاوری ثبت نشده است.</div>';return;}
    container.innerHTML=consultantsData.map((c,i)=>{
        const specs=Array.isArray(c.specialties)?c.specialties:[];
        return `<div class="consultant-manager-card">
            <div class="consultant-manager-head">
                <div class="consultant-manager-title"><span>${MK_IC.user}</span> مشاور ${i+1} ${c.is_default?'<span class="consultant-default-pill">پیش‌فرض</span>':''}</div>
                <div class="consultant-manager-actions">
                    <button type="button" class="btn-icon-sm" onclick="removeConsultant(${i})">${MK_IC.trash} حذف</button>
                </div>
            </div>
            <div style="padding:14px;display:grid;gap:11px;">
                <div class="admin-grid-2">
                    <div class="admin-field"><label>نام مشاور</label><input value="${consultantEsc(c.name)}" oninput="updateConsultant(${i},'name',this.value)"></div>
                    <div class="admin-field"><label>شماره تماس</label><input dir="ltr" inputmode="tel" value="${consultantEsc(c.phone)}" oninput="updateConsultant(${i},'phone',this.value)"></div>
                    <div class="admin-field"><label>اکانت تلگرام</label><input dir="ltr" placeholder="@username" value="${consultantEsc(c.telegram_username)}" oninput="updateConsultant(${i},'telegram_username',this.value)"></div>
                    <div class="admin-field"><label>لینک مستقیم تلگرام</label><input dir="ltr" placeholder="https://t.me/..." value="${consultantEsc(c.telegram_link)}" oninput="updateConsultant(${i},'telegram_link',this.value)"></div>
                    <div class="admin-field"><label>اولویت</label><input type="number" min="1" max="100" value="${Number(c.priority||i+1)}" oninput="updateConsultant(${i},'priority',Math.max(1,Math.min(100,parseInt(this.value||1,10))))"></div>
                    <div class="security-switch"><div><span>فعال باشد</span><small>مشاور غیرفعال در انتخاب خودکار وارد نمی‌شود.</small></div><input type="checkbox" ${c.active!==false?'checked':''} onchange="updateConsultant(${i},'active',this.checked)"></div>
                    <div class="security-switch"><div><span>مشاور پیش‌فرض</span><small>Fallback برای ترکیب بدون مشاور تخصصی.</small></div><input type="checkbox" ${c.is_default?'checked':''} onchange="updateConsultant(${i},'is_default',this.checked);renderConsultants()"></div>
                </div>
                <div>
                    <div class="admin-section-help" style="margin-bottom:7px;">تخصص‌ها (${specs.length}/10)</div>
                    <div class="consultant-specialties">${specs.length?specs.map((sp,si)=>`<span class="consultant-specialty">${consultantEsc(sp.property_type)} + ${consultantEsc(sp.transaction_type)} <button type="button" onclick="removeConsultantSpecialty(${i},${si})">✕</button></span>`).join(''):'<span class="admin-section-help">تخصصی ثبت نشده است.</span>'}</div>
                    <div class="consultant-add-specialty">
                        <select id="spec-p-${i}" class="form-select">${CONSULTANT_TYPES.map(x=>`<option value="${consultantEsc(x)}">${consultantEsc(x)}</option>`).join('')}</select>
                        <select id="spec-t-${i}" class="form-select">${CONSULTANT_TRANSACTIONS.map(x=>`<option value="${consultantEsc(x)}">${consultantEsc(x)}</option>`).join('')}</select>
                        <button type="button" class="btn-icon-sm gold" onclick="addConsultantSpecialty(${i})">＋ افزودن تخصص</button>
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function saveConsultants(){
    const state=document.getElementById('consultantsSaveState');
    const cleaned=consultantsData.map((c,i)=>({...c,id:c.id||('c'+(i+1)),priority:Number(c.priority||i+1),specialties:(Array.isArray(c.specialties)?c.specialties:[]).slice(0,10)})).slice(0,10);
    if(!cleaned.some(c=>c.is_default) && cleaned[0]) cleaned[0].is_default=true;
    if(state){state.textContent='⏳ در حال ذخیره...';state.style.color='var(--text-secondary)';}
    try{
        const response=await fetch('save_consultants.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({consultants:cleaned})});
        const result=await response.json();
        if(!response.ok||!result.success)throw new Error(result.message||'ذخیره مشاوران انجام نشد.');
        consultantsData=result.consultants||cleaned; renderConsultants();
        if(state){state.textContent='ذخیره شد';state.style.color='var(--success)';}
    }catch(error){if(state){state.textContent=''+error.message;state.style.color='var(--danger)';}alert(''+error.message);}
}

// ==============================================
// توابع عمومی
// ==============================================

function closeModal(id) {

    const element =
        document.getElementById(id);

    if (element) {
        element.classList.remove(
            'active'
        );
    }
}


async function saveAdsToFile(onlyAds) {

    try {

        // اگر فقط همین یک آگهی مدنظر باشد (حالت ویرایش)، به‌جای ارسال
        // دوباره‌ی همه‌ی آگهی‌ها (چند مگابایت روی هاست اشتراکی) فقط
        // همین یک ردیف ارسال می‌شود؛ هم سریع‌تر، هم کم‌خطاتر.
        const payloadAll = Array.isArray(onlyAds) ? onlyAds : adsData;

        // [PARTNERSHIP] ردیف‌های «مشارکت در ساخت» به API خودشان می‌روند
        // (admin-partnership.php?action=update) و هرگز وارد جدول آگهی‌ها نمی‌شوند.
        const mkpRows = payloadAll.filter(a => a && a.is_partnership);
        for (const m of mkpRows) {
            const body = Object.assign({}, m, { id: m.part_id });
            delete body.is_partnership;
            delete body.part_id;
            delete body.part_code;
            body.csrf_token = window.MELKINO_CSRF || '';
            const r = await fetch('admin-partnership.php?action=update', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': (window.MELKINO_CSRF || '')
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify(body)
            });
            if (!r.ok) throw new Error('خطای سرور HTTP ' + r.status + ' در ذخیرهٔ درخواست مشارکت');
            const j = await r.json();
            if (!j || !j.success) throw new Error((j && j.message) || 'ذخیرهٔ درخواست مشارکت انجام نشد.');
        }
        const payload = payloadAll.filter(a => !(a && a.is_partnership));
        if (!payload.length) return true;

        const response =
            await fetch(
                window.location.pathname + '?ad_db_action=bulk_sync',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    cache: 'no-store',
                    body: JSON.stringify(payload)
                }
            );

        // پاسخ را ابتدا text می‌گیریم تا Warning/HTML باعث SyntaxError نشود.
        const raw = await response.text();
        const cleaned = String(raw || '').trim();


        let result = null;

        // حالت عادی: JSON خالص
        if (cleaned !== '') {
            try {
                result = JSON.parse(cleaned);
            } catch (jsonError) {
                // اگر PHP قبل از JSON Warning/Notice چاپ کرده باشد، بخش JSON را جدا کن.
                const firstBrace = cleaned.indexOf('{');
                const lastBrace = cleaned.lastIndexOf('}');

                if (firstBrace >= 0 && lastBrace > firstBrace) {
                    const possibleJson = cleaned.slice(firstBrace, lastBrace + 1);
                    try {
                        result = JSON.parse(possibleJson);
                    } catch (innerError) {
                        result = null;
                    }
                }
            }
        }

        // اگر PHP به‌خاطر عبور حجم درخواست از post_max_size خودِ php.ini
        // هشدار خام (HTML) چاپ کرده باشد، به‌جای نمایش آن متن گیج‌کننده،
        // یک پیام فارسی و قابل‌فهم نشان می‌دهیم.
        if (/Content-Length of \d+ bytes exceeds the limit/i.test(cleaned)) {
            throw new Error(
                'حجم اطلاعاتی که برای ذخیره ارسال شد بیشتر از سقف مجاز سرور (post_max_size در php.ini) است.\n' +
                'یا تعداد آگهی‌هایی که هم‌زمان ویرایش می‌کنید را کم کنید، یا از ادمین سرور بخواهید post_max_size و upload_max_filesize را در php.ini افزایش دهد و Apache را ری‌استارت کند.'
            );
        }

        // خطای HTTP
        if (!response.ok) {
            const serverMessage =
                result && (result.error || result.message || result.details)
                    ? (result.error || result.message || result.details)
                    : cleaned.substring(0, 1200);

            throw new Error(
                serverMessage ||
                ('خطای سرور HTTP ' + response.status)
            );
        }

        // پاسخ موفق HTTP ولی غیر JSON
        if (!result || typeof result !== 'object') {
            console.error('[Melkino] Invalid JSON response:', cleaned);

            throw new Error(
                'پاسخ سرور JSON معتبر نیست.\n\n' +
                cleaned.substring(0, 1200)
            );
        }

        // JSON معتبر ولی عملیات ناموفق
        if (result.success !== true) {
            throw new Error(
                result.error ||
                result.message ||
                result.details ||
                'ذخیره تغییرات انجام نشد.'
            );
        }

        return true;

    } catch (e) {

        console.error('[Melkino] saveAdsToFile error:', e);

        alert(
            'خطا در ذخیره تغییرات:\n\n' +
            (e && e.message ? e.message : String(e))
        );

        return false;
    }
}



async function saveAndExit() {

    try {

        const saved = await saveAdsToFile();
        if (!saved) return;
        alert('تغییرات ذخیره شد!');

        window.location.href =
            'home.php';

    } catch(e) {

        alert(
            'خطا'
        );
    }
}



// ==============================================
// کاربران
// ==============================================
function mkEitaaFull(eitaaId, username){
    const id = String(eitaaId || '').trim();
    const un = String(username || '').trim().replace(/^@/, '');
    if (!id) return '<span class="mk-btn mk-btn--sm mk-btn--outline" aria-disabled="true">ایتا متصل نیست</span>';
    let html = '<span class="mk-btn mk-btn--sm mk-btn--outline">آیدی ایتا: <bdi dir="ltr">' + escapeHtml(id) + '</bdi></span>';
    // No undocumented numeric profile URLs. Link a username only when actually available.
    if (/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(un)) html += ' <a class="mk-btn mk-btn--sm mk-btn--outline" href="https://eitaa.com/' + encodeURIComponent(un) + '" target="_blank" rel="noopener noreferrer" dir="ltr">@' + escapeHtml(un) + '</a>';
    return html;
}

async function loadAdminUsers(){
    const container=document.getElementById('usersListContainer'); if(!container)return;
    container.innerHTML='<div class="consultant-empty">در حال بارگذاری کاربران...</div>';
    try{
        const r=await fetch('identity-sync.php?action=list',{cache:'no-store',credentials:'same-origin'});
        const raw=await r.text();
        let data={};
        try{data=JSON.parse(raw);}catch(e){container.innerHTML='<div class="consultant-empty">خطا در بارگذاری کاربران.</div>';return;}
        if(data && data.success===false){container.innerHTML='<div class="consultant-empty">'+escapeHtml(data.message||'خطا در بارگذاری کاربران.')+'</div>';return;}
        const users=Array.isArray(data.users)?data.users:[];
        if(!users.length){container.innerHTML='<div class="consultant-empty">هنوز کاربری ثبت نشده است.</div>';return;}
        users.sort((a,b)=>String(b.last_login||'').localeCompare(String(a.last_login||'')));
        container.innerHTML=`<div class="table-wrap"><table class="users-table"><thead><tr><th>وضعیت</th><th>Telegram ID</th><th>Bale ID</th><th>آیدی ایتا</th><th>Username</th><th>نام</th><th>شماره تماس</th><th>وضعیت شماره</th><th>پلتفرم آخر</th><th>IP آخر</th><th>اولین ورود</th><th>آخرین ورود</th><th>تعداد ورود</th><th></th></tr></thead><tbody>${
            users.map(u=>{
                const active = Number(u.is_active ?? 1) !== 0;
                const platformLabel = (u.last_platform === 'telegram') ? 'تلگرام'
                                    : (u.last_platform === 'bale') ? 'بله'
                                    : (u.last_platform === 'eitaa') ? 'ایتا'
                                    : (u.last_platform || '—');
                // راند ۳۰: وضعیت شمارهٔ تماس + دکمهٔ حذف (فقط ادمین)
                const phoneLocked = Number(u.phone_locked ?? 0) === 1 || Number(u.phone_verified ?? 0) === 1;
                const phoneState = (u.phone || '') === '' ? '<span style="color:#7F8A87;font-size:11px;">— ثبت نشده</span>'
                    : (phoneLocked ? '<span style="color:#4ADE80;font-size:11px;">✓ تأیید و قفل‌شده</span>'
                                   : '<span style="color:#FFD47E;font-size:11px;">تأییدنشده</span>');
                const clearPhoneBtn = (u.phone || '') === '' ? ''
                    : `<button type="button" class="btn-secondary" style="padding:4px 10px;font-size:12px;color:#ff8a8a;border-color:rgba(255,120,120,.35);" onclick="clearUserPhone(${Number(u.id)}, this)">${MK_IC.trash} حذف شماره</button>`;
                return `<tr>
                    <td><span class="user-status-dot" style="background:${active?'#4ADE80':'#7F8A87'}"></span>${active?'فعال':'غیرفعال'}</td>
                    <td dir="ltr">${escapeHtml(u.telegram_id||'—')}</td>
                    <td dir="ltr">${escapeHtml(u.bale_id||'—')}</td>
                    <td dir="ltr" class="user-eitaa-id">${escapeHtml(u.eitaa_id||'—')}${u.eitaa_username ? '<br><small>'+escapeHtml('@'+u.eitaa_username)+'</small>' : ''}</td>
                    <td dir="ltr">${escapeHtml(u.username||'—')}</td>
                    <td>${escapeHtml(u.name||'—')}</td>
                    <td dir="ltr">${escapeHtml(u.phone||'—')}</td>
                    <td>${phoneState} ${clearPhoneBtn}</td>
                    <td>${escapeHtml(platformLabel)}</td>
                    <td dir="ltr">${escapeHtml(u.last_ip||'—')}</td>
                    <td>${escapeHtml(u.first_login_fa||u.first_login||u.created_at||'—')}</td>
                    <td>${escapeHtml(u.last_login_fa||u.last_login||'—')}</td>
                    <td>${Number(u.login_count||0)}</td>
                    <td style="white-space:nowrap;">${mkTgIcon(u.telegram_id, u.telegram_username || (u.last_platform==='telegram' ? u.username : ''))} <button type="button" class="mk-btn mk-btn--sm mk-btn--outline" data-tgid="${escapeHtml(u.telegram_id||'')}" data-tgun="${escapeHtml(u.telegram_username || (u.last_platform==='telegram' ? (u.username||'') : ''))}" data-baleid="${escapeHtml(u.bale_id||'')}" data-eitaaid="${escapeHtml(u.eitaa_id||'')}" data-eitaaun="${escapeHtml(u.eitaa_username||'')}" data-baleun="${escapeHtml((u.last_platform==='bale' ? (u.username||'') : ''))}" onclick="toggleUserHistory(${Number(u.id)}, this)"><svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h12a2 2 0 0 0 2-2v-2H10v2a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v3h4"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/></svg> تاریخچه</button></td>
                </tr>
                <tr id="userHistoryRow${Number(u.id)}" style="display:none;">
                    <td colspan="14"><div id="userHistoryBox${Number(u.id)}" style="padding:10px;font-size:12px;"></div></td>
                </tr>`;
            }).join('')
        }</tbody></table></div>`;
    }catch(e){container.innerHTML='<div class="consultant-empty">خطا در بارگذاری کاربران.</div>';}
}

// راند ۳۰: حذف شمارهٔ تماس کاربر (تنها مسیر مجازِ تغییر شمارهٔ قفل‌شده)
// لاگ کامل در جدول admin_phone_audit ثبت می‌شود.
async function clearUserPhone(userId, btn){
    if (!confirm('شمارهٔ تماس کاربر #'+userId+' حذف و قفل آن باز شود؟\nپس از حذف، کاربر می‌تواند شمارهٔ جدیدی ثبت کند.')) return;
    const token = (typeof TOKEN !== 'undefined' && TOKEN) ? TOKEN : (window.MELKINO_CSRF || '');
    if (btn) { btn.disabled = true; }
    try{
        const r = await fetch('identity-sync.php?action=clear_phone', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            cache: 'no-store',
            body: JSON.stringify({ action: 'clear_phone', user_id: userId, csrf_token: token })
        });
        const data = await r.json();
        if (!r.ok || data.success !== true) {
            alert('' + ((data && data.message) || 'حذف شماره انجام نشد.'));
            if (btn) { btn.disabled = false; }
            return;
        }
        alert('✓ ' + (data.message || 'شماره حذف شد.'));
        loadAdminUsers();
    }catch(e){
        alert('خطا در حذف شماره.');
        if (btn) { btn.disabled = false; }
    }
}

async function toggleUserHistory(userId, btn){
    const row = document.getElementById('userHistoryRow'+userId);
    if (!row) return;

    if (row.style.display !== 'none') {
        row.style.display = 'none';
        return;
    }

    row.style.display = '';
    const box = document.getElementById('userHistoryBox'+userId);
    box.innerHTML = 'در حال بارگذاری تاریخچه...';

    try {
        const r = await fetch('identity-sync.php?action=history&user_id='+userId, {cache:'no-store'});
        const data = await r.json();
        const events = Array.isArray(data.events) ? data.events : [];
        events.forEach(function (ev) {
            var plat = String(ev.platform || '').toLowerCase();
            if ((plat === 'bale' || plat === 'بله') && !String(ev.bale_id || '').trim() && ev.telegram_id) {
                ev.bale_id = ev.telegram_id;
                ev.telegram_id = '';
            }
        });
        // راند ۳۵: دکمهٔ «باز کردن تلگرام» و «باز کردن در بله» بالای تاریخچه
        let tgHead = '';
        let baleId = btn ? (btn.getAttribute('data-baleid') || '') : '';
        let baleUn = btn ? (btn.getAttribute('data-baleun') || '') : '';
        if (events.length) {
            for (let i = 0; i < events.length; i++) {
                if (!baleId && events[i].bale_id) baleId = String(events[i].bale_id);
                if (!baleUn && events[i].platform === 'bale' && events[i].username) baleUn = String(events[i].username);
            }
        }
        const openBtns = mkTgFull(btn ? (btn.getAttribute('data-tgid') || '') : '', btn ? (btn.getAttribute('data-tgun') || '') : '')
            + ' ' + mkBaleFull(baleId, baleUn)
            + ' ' + mkEitaaFull(btn ? btn.getAttribute('data-eitaaid') : '', btn ? btn.getAttribute('data-eitaaun') : '');
        tgHead = '<div style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">' + openBtns + '</div>';
        if (!events.length) { box.innerHTML = tgHead + '<div class="mk-empty">رخدادی برای این کاربر ثبت نشده است.</div>'; return; }
        const pf = v => v === 'telegram' ? 'تلگرام' : (v === 'bale' ? 'بله' : (v === 'eitaa' ? 'ایتا' : (v || '—')));
        // راند ۳۳: همهٔ شناسه‌های هر رخداد (Telegram ID / Bale ID / یوزرنیم /
        // نام ثبت‌شده در همان ورود) به‌صورت کامل نمایش داده می‌شود.
        box.innerHTML = tgHead + '<div style="overflow-x:auto;"><table class="users-table" style="width:100%;min-width:860px;"><thead><tr><th>تاریخ و ساعت ورود</th><th>پلتفرم</th><th>Telegram ID</th><th>Bale ID</th><th>آیدی ایتا</th><th>Username</th><th>نام</th><th>IP</th><th>مرورگر / دستگاه</th></tr></thead><tbody>' +
            events.map(e => `<tr><td style="white-space:nowrap;">${escapeHtml(e.created_at_fa||e.created_at||'—')}</td><td>${escapeHtml(pf(e.platform))}</td><td dir="ltr">${escapeHtml(e.telegram_id||'—')}</td><td dir="ltr">${escapeHtml(e.bale_id||'—')}</td><td dir="ltr">${escapeHtml(e.eitaa_id||'—')}</td><td dir="ltr">${escapeHtml(e.username||'—')}</td><td>${escapeHtml(e.name||'—')}</td><td dir="ltr">${escapeHtml(e.ip_address||e.ip||'—')}</td><td dir="ltr" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(e.user_agent||'—')}</td></tr>`).join('') +
            '</tbody></table></div>';
        const views = Array.isArray(data.ad_views) ? data.ad_views : [];
        if (views.length) {
            box.innerHTML += '<div style="margin-top:14px;font-weight:800;">آگهی‌های دیده‌شده</div>'
                + '<div style="overflow-x:auto;"><table class="users-table" style="width:100%;min-width:480px;"><thead><tr><th>تاریخ بازدید</th><th>کد آگهی</th><th>عنوان</th></tr></thead><tbody>'
                + views.map(function (v) {
                    const href = v.ad_id ? ('property-details.php?id=' + encodeURIComponent(v.ad_id)) : '#';
                    return '<tr><td style="white-space:nowrap;">' + escapeHtml(v.viewed_at_fa || v.viewed_at || '—')
                        + '</td><td dir="ltr"><a href="' + escapeHtml(href) + '" target="_blank" rel="noopener">' + escapeHtml(v.ad_id || '—') + '</a></td><td>'
                        + escapeHtml(v.ad_title || '—') + '</td></tr>';
                }).join('')
                + '</tbody></table></div>';
        }
    } catch (e) {
        box.innerHTML = '<div class="mk-error">خطا در بارگذاری تاریخچه.</div>';
    }
}

// ==============================================
// تنظیمات عمومی
// ==============================================
async function loadGlobalSettings(){
    const c=document.getElementById('globalContainer'); if(!c)return;
    try{const r=await fetch('save_global_settings.php?action=get',{cache:'no-store'});const j=await r.json();renderGlobalSettings(j.settings||{});}catch(e){renderGlobalSettings({});}
}
function renderGlobalSettings(d){
    const c=document.getElementById('globalContainer'); if(!c)return;
    const bool=(key,label,help)=>`<div class="security-switch"><div><span>${label}</span><small>${help}</small></div><input id="g_${key}" type="checkbox" ${(key==='maintenance_mode'?!!d[key]:d[key]!==false)?'checked':''}></div>`;
    c.innerHTML=`<div class="admin-section-head"><div><div class="admin-section-title">${MK_IC.gear} تنظیمات عمومی</div><div class="admin-section-help">این تنظیمات رفتار عمومی ملکینو را کنترل می‌کنند.</div></div><span id="globalSaveState" class="admin-section-help"></span></div><div class="admin-grid-2"><div class="admin-field"><label>نام سایت</label><input id="g_site_name" value="${escapeHtml(d.site_name||'ملکینو')}"></div><div class="admin-field"><label>شهر</label><input id="g_city" value="${escapeHtml(d.city||'شاهرود')}"></div><div class="admin-field full"><label>شعار</label><input id="g_slogan" value="${escapeHtml(d.slogan||'ملکینو؛ انتخابی فراتر از یک ملک')}"></div><div class="admin-field"><label>تعداد فایل در هر صفحه</label><input id="g_items_per_page" type="number" min="4" max="100" value="${Number(d.items_per_page||12)}"></div><div class="admin-field"><label>تم پیش‌فرض</label><select id="g_default_theme"><option value="light" ${(d.default_theme||'light')==='light'?'selected':''}>روشن</option><option value="dark" ${d.default_theme==='dark'?'selected':''}>تیره</option></select></div></div><div class="password-security-grid" style="margin-top:12px">${bool('show_prices','نمایش قیمت‌ها','قیمت در کارت‌ها و صفحات عمومی نمایش داده شود.')}${bool('enable_favorites','علاقه‌مندی‌ها','قابلیت ذخیره آگهی برای کاربر فعال باشد.')}${bool('enable_property_requests','ثبت درخواست ملک','فرم درخواست برای کاربران فعال باشد.')}${bool('enable_notifications','اعلان‌ها','اعلان‌های تطبیق و رویدادها فعال باشند.')}${bool('maintenance_mode','حالت تعمیرات','سایت در حالت محدود قرار بگیرد.')}</div><div style="display:flex;gap:8px;margin-top:14px"><button type="button" id="globalSaveButton" class="btn-icon-sm primary">ذخیره تنظیمات عمومی</button></div>`;

    bindCalcRatesAdmin(d);

}

function mkCalcPctVal(rate, fallback){
    const n = Number(rate);
    const v = isFinite(n) ? n : fallback;
    return String(+(v * 100).toFixed(6)).replace(/\.?0+$/, '');
}
function mkCalcNumVal(v, fallback){
    const n = Number(v);
    return String(isFinite(n) ? n : fallback);
}
function mkCalcExtraRowHtml(ex, i){
    const id = escapeHtml(ex.id || ('x'+i));
    const label = escapeHtml(ex.label || '');
    const mode = ex.mode || 'area_ratio';
    const pct = mkCalcPctVal(ex.ratio, 0.5);
    const on = ex.enabled === false ? '' : 'checked';
    return `<div class="mk-calc-extra-row" data-id="${id}" style="display:grid;grid-template-columns:1.3fr 1.1fr .7fr auto auto;gap:8px;align-items:end;margin-bottom:8px">
        <div class="admin-field" style="margin:0"><label>نام آیتم</label><input class="mk-ex-label" value="${label}" placeholder="مثلاً انباری"></div>
        <div class="admin-field" style="margin:0"><label>نوع محاسبه</label><select class="mk-ex-mode">
            <option value="area_ratio" ${mode==='area_ratio'?'selected':''}>متراژ × قیمت متر × نسبت</option>
            <option value="percent_add" ${mode==='percent_add'?'selected':''}>افزایش درصدی از قیمت</option>
            <option value="percent_cut" ${mode==='percent_cut'?'selected':''}>کاهش درصدی از قیمت</option>
        </select></div>
        <div class="admin-field" style="margin:0"><label>مقدار (٪)</label><input class="mk-ex-pct" type="number" step="0.0001" min="0" max="200" value="${pct}"></div>
        <label class="admin-field" style="margin:0;display:flex;gap:6px;align-items:center;padding-bottom:10px"><input class="mk-ex-on" type="checkbox" ${on}> فعال</label>
        <button type="button" class="btn-icon-sm mk-ex-del" style="margin-bottom:8px">حذف</button>
    </div>`;
}
function mkBindCalcExtraButtons(){
    const extrasBox = document.getElementById('mkCalcExtrasBox');
    if (!extrasBox) return;
    extrasBox.querySelectorAll('.mk-ex-del').forEach(function(btn){
        btn.onclick = function(){ const row = btn.closest('.mk-calc-extra-row'); if (row) row.remove(); };
    });
    const addBtn = document.getElementById('mkCalcExtraAdd');
    if (addBtn && !addBtn.getAttribute('data-bound')) {
        addBtn.setAttribute('data-bound', '1');
        addBtn.onclick = function(){
            extrasBox.insertAdjacentHTML('beforeend', mkCalcExtraRowHtml({id:'x'+Date.now(), label:'', enabled:true, mode:'area_ratio', ratio:0.5}, Date.now()));
            mkBindCalcExtraButtons();
        };
    }
}
function bindCalcRatesAdmin(d){
    d = d || {};
    const R = d.calc_rates || {};
    const set = function(id, val){ const el = document.getElementById(id); if (el) el.value = val; };
    set('g_calc_estehklak', mkCalcPctVal(R.ESTEHKLAK_RATE, 0.015));
    set('g_calc_waqf', mkCalcPctVal(R.VAGHFI_DISCOUNT, 0.20));
    set('g_calc_park_min', mkCalcPctVal(R.NO_PARKING_DISCOUNT_MIN, 0.08));
    set('g_calc_park_def', mkCalcPctVal(R.NO_PARKING_DISCOUNT_DEFAULT, 0.09));
    set('g_calc_park_max', mkCalcPctVal(R.NO_PARKING_DISCOUNT_MAX, 0.10));
    set('g_calc_elev', mkCalcPctVal(R.NO_ELEVATOR_RATE, 0.025));
    set('g_calc_yard', mkCalcPctVal(R.YARD_RATIO, 1/3));
    set('g_calc_rent_div', mkCalcNumVal(R.FULL_RENT_DIVISOR, 8));
    set('g_calc_rent_base', mkCalcNumVal(R.RENT_BASE, 100000000));
    set('g_calc_rent_per', mkCalcNumVal(R.RENT_PER_100M, 3000000));
    const en = document.getElementById('g_enable_property_calculator');
    if (en) en.checked = d.enable_property_calculator !== false;
    const extrasBox = document.getElementById('mkCalcExtrasBox');
    if (extrasBox) {
        let extras = Array.isArray(R.EXTRAS) ? R.EXTRAS : [{id:'storage',label:'انباری',enabled:true,mode:'area_ratio',ratio:0.5}];
        if (!extras.length) extras = [{id:'storage',label:'انباری',enabled:true,mode:'area_ratio',ratio:0.5}];
        extrasBox.innerHTML = extras.map(mkCalcExtraRowHtml).join('');
        const addBtn = document.getElementById('mkCalcExtraAdd');
        if (addBtn) addBtn.removeAttribute('data-bound');
        mkBindCalcExtraButtons();
    }
}
function collectCalcRates(){
    const n = function(id){
        const el = document.getElementById(id);
        if (!el) return NaN;
        return parseFloat(el.value);
    };
    const extras = [];
    document.querySelectorAll('.mk-calc-extra-row').forEach(function(row){
        const label = (row.querySelector('.mk-ex-label')||{}).value || '';
        if (!String(label).trim()) return;
        const pct = parseFloat((row.querySelector('.mk-ex-pct')||{}).value);
        extras.push({
            id: row.getAttribute('data-id') || '',
            label: String(label).trim(),
            enabled: !!(row.querySelector('.mk-ex-on')||{}).checked,
            mode: (row.querySelector('.mk-ex-mode')||{}).value || 'area_ratio',
            ratio: (isFinite(pct) ? pct : 0) / 100
        });
    });
    const pct = function(id, fb){ const v = n(id); return (isFinite(v) ? v : fb) / 100; };
    return {
        ESTEHKLAK_RATE: pct('g_calc_estehklak', 1.5),
        VAGHFI_DISCOUNT: pct('g_calc_waqf', 20),
        NO_PARKING_DISCOUNT_MIN: pct('g_calc_park_min', 8),
        NO_PARKING_DISCOUNT_DEFAULT: pct('g_calc_park_def', 9),
        NO_PARKING_DISCOUNT_MAX: pct('g_calc_park_max', 10),
        NO_ELEVATOR_RATE: pct('g_calc_elev', 2.5),
        YARD_RATIO: pct('g_calc_yard', 33.333333),
        FULL_RENT_DIVISOR: isFinite(n('g_calc_rent_div')) ? n('g_calc_rent_div') : 8,
        RENT_BASE: isFinite(n('g_calc_rent_base')) ? n('g_calc_rent_base') : 100000000,
        RENT_PER_100M: isFinite(n('g_calc_rent_per')) ? n('g_calc_rent_per') : 3000000,
        EXTRAS: extras
    };
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof mkBindCalcExtraButtons === 'function') mkBindCalcExtraButtons();
});
document.addEventListener('click', function (e) {
    const b = e.target && (e.target.id === 'globalSaveButton' ? e.target : (e.target.closest ? e.target.closest('#globalSaveButton') : null));
    if (!b) return;
    e.preventDefault();
    saveGlobalSettings();
});
async function saveGlobalSettings(){
    const state=document.getElementById('globalSaveState');
    const btn=document.getElementById('globalSaveButton');
    const el=function(id){ return document.getElementById('g_'+id); };
    const txt=function(id, fb){ const n=el(id); return n ? String(n.value||'').trim() : (fb||''); };
    const num=function(id, fb){ const n=el(id); const v=n ? parseInt(n.value||fb,10) : fb; return isFinite(v)?v:fb; };
    const chk=function(id, fb){ const n=el(id); return n ? !!n.checked : (fb!==false); };

    try {
        const payload={
            site_name:txt('site_name','ملکینو'),
            city:txt('city',''),
            slogan:txt('slogan',''),
            items_per_page:num('items_per_page',12),
            default_theme:txt('default_theme','light')==='dark'?'dark':'light',
            show_prices:chk('show_prices',true),
            enable_favorites:chk('enable_favorites',true),
            enable_property_requests:chk('enable_property_requests',true),
            enable_notifications:chk('enable_notifications',true),
            enable_property_calculator:chk('enable_property_calculator',true),
            maintenance_mode:chk('maintenance_mode',false)
        };
        if (window.MELKINO_CSRF) payload.csrf_token = window.MELKINO_CSRF;
        if (document.getElementById('g_calc_estehklak') && typeof collectCalcRates === 'function') {
            payload.calc_rates = collectCalcRates();
        }
        if (state) state.textContent='در حال ذخیره...';
        if (btn) btn.disabled = true;
        const headers={'Content-Type':'application/json','Accept':'application/json'};
        if (window.MELKINO_CSRF) headers['X-CSRF-Token']=window.MELKINO_CSRF;
        const r=await fetch('save_global_settings.php',{
            method:'POST',
            headers:headers,
            credentials:'same-origin',
            cache:'no-store',
            body:JSON.stringify(payload)
        });
        const text=await r.text();
        let j={};
        try { j=JSON.parse(text); } catch(e) { throw new Error('پاسخ نامعتبر از سرور دریافت شد.'); }
        if (!r.ok || !j.success) throw new Error(j.message||'ذخیره تنظیمات انجام نشد.');
        if (state) state.textContent='با موفقیت ذخیره شد';
        if (j.settings) renderGlobalSettings(j.settings);
        const st2=document.getElementById('globalSaveState');
        if (st2) st2.textContent='با موفقیت ذخیره شد';
    } catch(e) {
        console.error('saveGlobalSettings error:',e);
        if (state) state.textContent=''+e.message;
        else alert(''+e.message);
    } finally {
        const b=document.getElementById('globalSaveButton');
        if (b) b.disabled = false;
    }
}


// ==============================================
// امنیت و تغییر رمز
// ==============================================
function renderPasswordSecurity(){
    const c=document.getElementById('passwordContainer');if(!c)return;
    c.innerHTML=`<div class="admin-section-head"><div><div class="admin-section-title">${MK_IC.lock} امنیت پنل مدیریت</div><div class="admin-section-help">رمز جدید با password_hash ذخیره می‌شود و ورود ادمین از هش امن استفاده می‌کند.</div></div><span id="securitySaveState" class="admin-section-help"></span></div><div class="admin-grid-2"><div class="admin-field"><label>رمز فعلی</label><input id="security_current_password" type="password" autocomplete="current-password"></div><div class="admin-field"><label>رمز جدید</label><input id="security_new_password" type="password" autocomplete="new-password"></div><div class="admin-field"><label>تکرار رمز جدید</label><input id="security_repeat_password" type="password" autocomplete="new-password"></div><div class="admin-field"><label>قدرت رمز</label><div id="passwordStrength" class="admin-section-help" style="padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg)">حداقل ۸ کاراکتر</div></div></div><div style="display:flex;gap:8px;margin-top:12px"><button class="btn-icon-sm primary" onclick="saveAdminPassword()">${MK_IC.lock} تغییر رمز</button></div><div class="password-security-grid" style="margin-top:14px"><label class="security-switch"><span><b>قفل ورود پس از چند خطا</b><small>برای جلوگیری از brute force</small></span><input id="sec_lockout" type="checkbox" checked></label><label class="security-switch"><span><b>ثبت لاگ ورود ادمین</b><small>برای بررسی رخدادهای امنیتی</small></span><input id="sec_logins" type="checkbox" checked></label><label class="security-switch"><span><b>Timeout نشست</b><small>خروج خودکار پس از عدم فعالیت</small></span><input id="sec_timeout" type="checkbox" checked></label><label class="security-switch"><span><b>Force HTTPS</b><small>در محیط HTTPS فعال شود</small></span><input id="sec_https" type="checkbox"></label></div><div style="display:flex;gap:8px;margin-top:12px"><button class="btn-secondary" onclick="saveSecuritySettings()"><?= melkinoSvgIcon('save') ?>  ذخیره تنظیمات امنیتی</button></div>`;
    const n=document.getElementById('security_new_password');if(n)n.addEventListener('input',()=>{const v=n.value||'';const st=document.getElementById('passwordStrength');if(!st)return;let score=0;if(v.length>=8)score++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))score++;if(/\d/.test(v))score++;if(/[^A-Za-z0-9]/.test(v))score++;st.textContent=score>=4?'قوی':score>=2?'متوسط':'ضعیف';st.style.color=score>=4?'var(--success)':score>=2?'var(--warning)':'var(--danger)';});
    fetch('save_security_settings.php?action=get',{cache:'no-store'}).then(r=>r.json()).then(j=>{const d=j.settings||{};const set=(id,v)=>{const e=document.getElementById(id);if(e)e.checked=!!v;};set('sec_lockout',d.lockout);set('sec_logins',d.admin_login_log);set('sec_timeout',d.session_timeout);set('sec_https',d.force_https);}).catch(()=>{});
}
async function saveAdminPassword(){
    const current=document.getElementById('security_current_password').value;const nw=document.getElementById('security_new_password').value;const repeat=document.getElementById('security_repeat_password').value;if(nw!==repeat){alert('تکرار رمز جدید یکسان نیست.');return;}if(nw.length<8){alert('رمز جدید باید حداقل ۸ کاراکتر باشد.');return;}
    try{const r=await fetch('save_admin_password.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({current_password:current,new_password:nw})});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'خطا');alert('رمز با موفقیت تغییر کرد.');document.getElementById('security_current_password').value='';document.getElementById('security_new_password').value='';document.getElementById('security_repeat_password').value='';}catch(e){alert(''+e.message);}
}
async function saveSecuritySettings(){
    const payload={lockout:document.getElementById('sec_lockout').checked,admin_login_log:document.getElementById('sec_logins').checked,session_timeout:document.getElementById('sec_timeout').checked,force_https:document.getElementById('sec_https').checked};
    try{const r=await fetch('save_security_settings.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const j=await r.json();if(!r.ok||!j.success)throw new Error(j.message||'خطا');alert('تنظیمات امنیتی ذخیره شد.');}catch(e){alert(''+e.message);}
}

function adminLogout() {

    if (
        !confirm('خروج؟')
    ) {
        return;
    }

    // خروج واقعی: سشنِ سروری باید خراب شود (قبلاً فقط redirect بود و
    // $_SESSION['is_admin'] باقی می‌ماند و با کوکی دزدیده‌شده قابل
    // استفاده ماند).
    const redirect = () => { window.location.href = 'admin-login.php'; };
    fetch('admin-logout.php', { method: 'POST', credentials: 'same-origin' })
        .then(redirect)
        .catch(redirect);
}





/* =========================================================
   SUPPORT TAB
   ========================================================= */

let supportTicketsData = [];
let supportCurrentFilter = 'all';
let supportCurrentTicketId = null;

function supportEscapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function loadSupportTickets() {
    const listEl = document.getElementById('supportTicketsList');
    if (listEl) listEl.innerHTML = 'در حال بارگذاری…';

    fetch('support-api.php?action=admin_get_tickets', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                if (listEl) listEl.innerHTML = '<div style="padding:12px;color:var(--danger);">' + supportEscapeHtml(data && data.message || 'خطا در دریافت تیکت‌ها') + '</div>';
                return;
            }
            supportTicketsData = Array.isArray(data.tickets) ? data.tickets : [];
            const countEl = document.getElementById('supportTicketCount');
            if (countEl) countEl.innerText = supportTicketsData.length + ' تیکت';
            const openTickets = supportTicketsData.filter(t => t.status === 'open' || t.status === 'answered').length;
            const openEl = document.getElementById('supportStatOpen');
            const totalEl = document.getElementById('supportStatTotal');
            if (openEl) openEl.innerText = openTickets;
            if (totalEl) totalEl.innerText = supportTicketsData.length;
            renderSupportTickets();
        })
        .catch(() => {
            if (listEl) listEl.innerHTML = '<div style="padding:12px;color:var(--danger);">خطا در ارتباط با سرور</div>';
        });
}

function filterSupportTickets(status, btnEl) {
    supportCurrentFilter = status;
    document.querySelectorAll('.support-filter-btn').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');
    renderSupportTickets();
}

function renderSupportTickets() {
    const listEl = document.getElementById('supportTicketsList');
    if (!listEl) return;

    const rows = supportTicketsData.filter(t => supportCurrentFilter === 'all' || t.status === supportCurrentFilter);

    if (!rows.length) {
        listEl.innerHTML = '<div style="padding:12px;color:var(--text-secondary);">تیکتی برای نمایش وجود ندارد.</div>';
        return;
    }

    listEl.innerHTML = rows.map(t => `
        <div onclick="openSupportTicket(${t.id})" style="cursor:pointer;padding:12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;align-items:center;">
            <div>
                <div style="font-weight:700;">${supportEscapeHtml(t.subject)} ${t.unread_count > 0 ? '<span style="background:var(--danger);color:#fff;border-radius:20px;padding:1px 8px;font-size:11px;margin-inline-start:6px;">' + t.unread_count + ' جدید</span>' : ''}</div>
                <div style="font-size:12px;color:var(--text-secondary);margin-top:4px;">${supportEscapeHtml(t.user_name)} • ${supportEscapeHtml(t.last_message || '')}</div>
            </div>
            <span class="support-status-badge status-${supportEscapeHtml(t.status)}" style="white-space:nowrap;font-size:12px;padding:4px 10px;border-radius:20px;background:var(--bg-secondary);">${supportEscapeHtml(t.status_label)}</span>
        </div>
    `).join('');
}

function openSupportTicket(ticketId) {
    supportCurrentTicketId = ticketId;

    fetch('support-api.php?action=admin_get_ticket&ticket_id=' + encodeURIComponent(ticketId), { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در دریافت گفتگو');
                return;
            }

            const card = document.getElementById('supportConversationCard');
            if (card) card.style.display = '';

            const titleEl = document.getElementById('supportConversationTitle');
            if (titleEl) titleEl.innerText = data.ticket.subject + ' — ' + data.ticket.user_name;

            const closeBtn = document.getElementById('supportCloseBtn');
            const reopenBtn = document.getElementById('supportReopenBtn');
            const isClosed = data.ticket.status === 'closed';
            if (closeBtn) closeBtn.style.display = isClosed ? 'none' : '';
            if (reopenBtn) reopenBtn.style.display = isClosed ? '' : 'none';

            const msgEl = document.getElementById('supportMessages');
            if (msgEl) {
                msgEl.innerHTML = (data.messages || []).map(m => `
                    <div style="align-self:${m.sender_type === 'admin' ? 'flex-start' : 'flex-end'};max-width:80%;background:${m.sender_type === 'admin' ? 'var(--primary)' : 'var(--bg-secondary)'};color:${m.sender_type === 'admin' ? '#fff' : 'var(--text-primary)'};padding:10px 14px;border-radius:12px;">
                        <div style="font-size:11px;opacity:.75;margin-bottom:4px;">${supportEscapeHtml(m.sender_name)}</div>
                        <div style="white-space:pre-wrap;">${supportEscapeHtml(m.message)}</div>
                    </div>
                `).join('');
                msgEl.scrollTop = msgEl.scrollHeight;
            }

            card.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // شمارش تیکت‌های خوانده‌نشده در لیست به‌روزرسانی شود
            loadSupportTickets();
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}

function sendSupportReply() {
    const textEl = document.getElementById('supportReplyText');
    const message = (textEl?.value || '').trim();

    if (!supportCurrentTicketId) return;
    if (!message) { alert('لطفاً متن پاسخ را وارد کنید.'); return; }

    const body = new URLSearchParams({ action: 'admin_reply', ticket_id: supportCurrentTicketId, message });
    // توکن CSRF صریح فرستاده می‌شود؛ قبلاً فقط به هم‌مبدأ بودن تکیه می‌شد
    // و اگر مرورگر Referer را حذف می‌کرد پاسخ ۴۱۹ می‌گرفت.
    if (window.MELKINO_CSRF) body.append('csrf_token', window.MELKINO_CSRF);

    fetch('support-api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در ارسال پاسخ');
                return;
            }
            if (textEl) textEl.value = '';
            openSupportTicket(supportCurrentTicketId);
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}

function setSupportTicketStatus(action) {
    if (!supportCurrentTicketId) return;

    const body = new URLSearchParams({
        action: action === 'close' ? 'admin_close_ticket' : 'admin_reopen_ticket',
        ticket_id: supportCurrentTicketId
    });
    if (window.MELKINO_CSRF) body.append('csrf_token', window.MELKINO_CSRF);

    fetch('support-api.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                alert(data && data.message || 'خطا در به‌روزرسانی وضعیت');
                return;
            }
            openSupportTicket(supportCurrentTicketId);
        })
        .catch(() => alert('خطا در ارتباط با سرور'));
}


/* =========================================================
   آمار بالای هر تب
   =========================================================
   تب «داشبورد» حذف شده و آمار هر بخش بالای همان تب نمایش داده
   می‌شود. این تابع (که از جاهای مختلف مثل بارگذاری مرحله‌ای آگهی‌ها
   و تغییر وضعیت آگهی صدا زده می‌شود) همه‌ی نوارهای آمار را به‌روز
   می‌کند؛ هر کدام که در صفحه نباشد نادیده گرفته می‌شود.
   ========================================================= */
function renderDashboard() {
    const set=(id,val)=>{const e=document.getElementById(id);if(e)e.innerText=val;};

    /* ---------- آمار تب آگهی‌ها ---------- */
    const T=window.MELKINO_AD_TOTALS||null;
    const ads=(typeof adsData!=='undefined'&&Array.isArray(adsData))?adsData:[];
    set('adsStatTotal',T?T.total:ads.length);
    set('adsStatPending',T?T.pending:ads.filter(a=>a.status==='pending').length);
    set('adsStatPublished',T?T.published:ads.filter(a=>a.status==='published').length);
    set('adsStatVip',T?T.vip:ads.filter(a=>a.is_vip===true||a.is_vip==='1').length);
    set('adsStatPublishedVip',T?T.published_vip:ads.filter(a=>(a.is_vip===true||a.is_vip==='1')&&a.status==='published').length);

    /* ---------- آمار تب درخواست‌ها ---------- */
    const RM=window.MELKINO_REQUESTS_META||null;
    const reqs=(typeof requestsData!=='undefined'&&Array.isArray(requestsData))?requestsData:[];
    const reqStatus=r=>String(r.status||'new');
    set('reqStatTotal',RM&&RM.total!=null?RM.total:reqs.length);
    set('reqStatNew',RM&&RM.new_count!=null?RM.new_count:reqs.filter(r=>reqStatus(r)==='new').length);
    set('reqStatTracking',RM&&RM.tracking_count!=null?RM.tracking_count:reqs.filter(r=>reqStatus(r)==='tracking').length);
    set('reqStatMatched',RM&&RM.matched!=null?RM.matched:reqs.filter(r=>Array.isArray(r.matches)&&r.matches.length>0).length);
    set('reqStatMatches',RM&&RM.matches!=null?RM.matches:reqs.reduce((sum,r)=>sum+(Array.isArray(r.matches)?r.matches.length:0),0));

    /* ---------- آمار تب کاربران (با کش ۶۰ ثانیه‌ای) ---------- */
    const usersEl=document.getElementById('usersStatTotal');
    const visitsEl=document.getElementById('usersStatVisits');
    const now=Date.now();
    if((usersEl||visitsEl)&&(!window.__melkinoUsersStatsAt||now-window.__melkinoUsersStatsAt>60000)){
        window.__melkinoUsersStatsAt=now;
        if(usersEl)usersEl.innerText='…'; if(visitsEl)visitsEl.innerText='…';
        Promise.all([
            fetch('identity-sync.php?action=list',{cache:'no-store'}).then(r=>r.ok?r.json():null).catch(()=>null),
            fetch('page-visits.php?action=stats',{cache:'no-store'}).then(r=>r.ok?r.json():null).catch(()=>null)
        ]).then(([u,v])=>{
            if(usersEl)usersEl.innerText=Array.isArray(u?.users)?u.users.length:(Array.isArray(u)?u.length:0);
            if(visitsEl)visitsEl.innerText=Number(v?.last_24h||0);
        });
    }

    /* ---------- آمار تب پشتیبانی (اگر تیکت‌ها قبلاً لود شده‌اند) ---------- */
    if(typeof supportTicketsData!=='undefined'&&Array.isArray(supportTicketsData)&&supportTicketsData.length){
        const open=supportTicketsData.filter(t=>t.status==='open'||t.status==='answered').length;
        set('supportStatOpen',open);
        set('supportStatTotal',supportTicketsData.length);
    }
}

// ==============================================
function uploadOnboardingLogo() {
    const file = logoFileInput.files[0];
    if (!file) {
        logoStatus.textContent = 'لطفاً یک فایل انتخاب کنید.';
        logoStatus.style.color = 'var(--text-secondary)';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        logoStatus.textContent = 'حجم فایل بیشتر از ۵ مگابایت است.';
        logoStatus.style.color = 'red';
        return;
    }

    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        logoStatus.textContent = 'فرمت فایل مجاز نیست (فقط PNG, JPG, JPEG, WEBP).';
        logoStatus.style.color = 'red';
        return;
    }

    uploadLogoBtn.disabled = true;
    uploadLogoBtn.style.opacity = '0.6';
    uploadProgress.style.display = 'block';
    progressBar.style.width = '0%';
    logoStatus.textContent = '⏳ در حال آپلود...';
    logoStatus.style.color = 'var(--text-secondary)';

    const formData = new FormData();
    formData.append('onboarding_logo', file);

    // مسیر آپلود - اگر پروژه در پوشه melkino است
    const uploadUrl = 'upload_onboarding_logo.php';
    // اگر پروژه در ریشه است، خط بالا رو کامنت کنید و این خط رو فعال کنید:
    // const uploadUrl = window.location.origin + '/upload_onboarding_logo.php';

    fetch(uploadUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(text || 'خطا در پاسخ سرور');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const logoUrl = data.logo_url || data.url || '';
            if (logoUrl) {
                logoPreview.src = logoUrl + '?t=' + new Date().getTime();
                logoStatus.textContent = '' + data.message;
                logoStatus.style.color = 'green';
            } else {
                logoStatus.textContent = 'لوگو آپلود شد، اما نشانی دریافت نشد. صفحه را رفرش کنید.';
                logoStatus.style.color = 'orange';
            }
            logoFileInput.value = '';
            fileNameDisplay.textContent = '';
            alert('لوگو با موفقیت آپلود و جایگزین شد.');
        } else {
            logoStatus.textContent = '' + data.message;
            logoStatus.style.color = 'red';
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        logoStatus.textContent = 'خطا: ' + error.message;
        logoStatus.style.color = 'red';
    })
    .finally(() => {
        uploadProgress.style.display = 'none';
        uploadLogoBtn.disabled = false;
        uploadLogoBtn.style.opacity = '1';
    });
}

uploadLogoBtn.addEventListener('click', uploadOnboardingLogo);

logoFileInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        uploadLogoBtn.click();
    }
});


// ==============================================
// لوگوی تیره: آپلود جداگانه (variant=dark)
// ==============================================
(function () {
    var inp = document.getElementById('logoDarkFileInput');
    var btn = document.getElementById('uploadLogoDarkBtn');
    var st = document.getElementById('logoDarkStatus');
    var pv = document.getElementById('logoDarkPreview');
    if (!inp || !btn) { return; }
    function setSt(msg, color) { st.textContent = msg; st.style.color = color; }
    btn.addEventListener('click', function () {
        var file = inp.files && inp.files[0];
        if (!file) { setSt('لطفاً یک فایل انتخاب کنید.', 'var(--text-secondary)'); return; }
        if (file.size > 5 * 1024 * 1024) { setSt('حجم فایل بیشتر از ۵ مگابایت است.', 'red'); return; }
        if (['image/png', 'image/jpeg', 'image/webp'].indexOf(file.type) === -1) {
            setSt('فرمت فایل مجاز نیست (فقط PNG, JPG, JPEG, WEBP).', 'red'); return;
        }
        btn.disabled = true; btn.style.opacity = '0.6';
        setSt('⏳ در حال آپلود...', 'var(--text-secondary)');
        var fd = new FormData();
        fd.append('onboarding_logo', file);
        fd.append('variant', 'dark');
        var headers = {};
        if (window.MELKINO_CSRF) { fd.append('csrf_token', window.MELKINO_CSRF); headers['X-CSRF-Token'] = window.MELKINO_CSRF; }
        fetch('upload_onboarding_logo.php', { method: 'POST', body: fd, headers: headers, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (x.ok && x.j.success) {
                    if (x.j.logo_url) { pv.src = x.j.logo_url + (x.j.logo_url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now(); }
                    setSt(x.j.message, 'green');
                    inp.value = '';
                } else {
                    setSt(x.j.message || 'خطا در آپلود', 'red');
                }
            })
            .catch(function (e) { setSt('خطا: ' + e.message, 'red'); })
            .finally(function () { btn.disabled = false; btn.style.opacity = '1'; });
    });
})();

// ==============================================
// بارگذاری اولیه
// ==============================================

document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#support') {
        switchTab('support');
    }
});

document.addEventListener(
    'DOMContentLoaded',
    function() {

        const search =
            document.getElementById(
                'adsSearch'
            );


        const property =
            document.getElementById(
                'adsPropertyFilter'
            );


        const transaction =
            document.getElementById(
                'adsTransactionFilter'
            );


        const sort =
            document.getElementById(
                'adsSort'
            );


        if (search) {

            search.addEventListener(
                'input',
                function() {

                    adViewState.search =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (property) {

            property.addEventListener(
                'change',
                function() {

                    adViewState.propertyType =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (transaction) {

            transaction.addEventListener(
                'change',
                function() {

                    adViewState.transactionType =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        if (sort) {

            sort.addEventListener(
                'change',
                function() {

                    adViewState.sort =
                        this.value;

                    adViewState.page =
                        1;

                    renderAds();
                }
            );
        }


        /*
         * پیش‌تر در اینجا داده‌های «همه‌ی تب‌ها» با هم بارگیری می‌شد:
         * داشبورد، آگهی‌ها، درخواست‌ها، تنظیماتِ تماس و کاربران.
         * یعنی در هر بارگذاریِ پنل، چندین درخواستِ هم‌زمان به سرور فرستاده
         * می‌شد — از جمله دریافتِ فهرستِ کاملِ آگهی‌ها که ممکن است حجیم
         * باشد — در حالی که ادمین در آن لحظه فقط یک تب را می‌بیند.
         *
         * حالا فقط تبِ فعلی مقداردهی می‌شود و بقیه هنگامی که ادمین آن‌ها را
         * باز کند آماده می‌شوند (switchTab خودش این کار را می‌کند).
         * شنونده‌های فیلتر و جست‌وجو در بالا همچنان به عناصر متصل‌اند،
         * بنابراین پس از باز شدنِ تب همه چیز درست کار می‌کند.
         */
        var __activeTab = document.querySelector('.tab-content.active');
        var __activeId  = __activeTab
            ? String(__activeTab.id || '').replace(/^tab-/, '')
            : 'global';

        if (!__activeId) {
            __activeId = 'dashboard';
        }

        // Fixed allowlisted deep link, also used by settings-save PRG.
        if (new URLSearchParams(window.location.search).get('tab') === 'brand') __activeId = 'brand';
        switchTab(__activeId);

    }
);


// ==============================================
// بستن مودال با کلیک روی پس‌زمینه
// ==============================================

document
    .querySelectorAll(
        '.modal-overlay'
    )
    .forEach(
        modal => {

            modal.addEventListener(
                'click',
                function(e) {

                    if (
                        e.target ===
                        this
                    ) {

                        this.classList.remove(
                            'active'
                        );
                    }
                }
            );
        }
    );

</script>

<!-- رله‌ی ارتباط با تلگرام/بله (فایل کوچک، همراهِ صفحه می‌آید) -->
<script src="telegram-relay.js?v=<?php echo (int)@filemtime(__DIR__ . '/telegram-relay.js'); ?>" defer></script>

<!--
    =========================================================
    بارگیریِ هوشمندِ اسکریپت‌های سنگینِ پنل ادمین
    =========================================================
    چرا این کار لازم است؟
        سه فایل admin-ads.js (۹۳ کیلوبایت)، admin-new-tabs.js (۴۵ کیلوبایت)
        و admin-requests.js (۲۳ کیلوبایت) روی هم بیش از ۱۶۰ کیلوبایت
        جاوااسکریپت هستند که پیش‌تر «همیشه و هم‌زمان» با صفحه بارگیری
        می‌شدند؛ در حالی که بیشترِ آن‌ها فقط برای یک یا دو تب به کار
        می‌روند و ادمین معمولاً ابتدا داشبورد را می‌بیند.

        حالا این فایل‌ها فقط در دو حالت بارگیری می‌شوند:
          ۱) هنگامی که تبِ مربوط به آن‌ها باز شود؛
          ۲) در پس‌زمینه و پس از آماده شدنِ کاملِ صفحه، تا هنگامی که
             ادمین روی تب‌ها کلیک کند، از پیش آماده باشند.

        نتیجه: صفحه بسیار زودتر نمایش داده می‌شود و در عین حال هیچ
        دکمه‌ای از کار نمی‌افتد.
-->
<script>
(function () {
    window.MELKINO_LOADED_SCRIPTS = window.MELKINO_LOADED_SCRIPTS || {};
    // صف بارگیری‌های در حال انجام (ضد race): بدون این، اگر باز کردن تب و
    // پیش‌بارگیریِ پس‌زمینه هم‌زمان یک فایل را بخواهند، دو تگ <script>
    // ساخته می‌شود و فایل دو بار اجرا می‌شود — با let سطح بالا یعنی
    // «Identifier has already been declared» و مرگ کل جاوااسکریپت پنل.
    window.MELKINO_LOADING_SCRIPTS = window.MELKINO_LOADING_SCRIPTS || {};
    // telegram-relay.js با تگ ثابت (defer) همراه خود صفحه می‌آید؛ لازم نیست
    // لودر تب‌ها دوباره آن را بگیرد (قبلاً برای تب ads دو بار اجرا می‌شد).
    window.MELKINO_LOADED_SCRIPTS['telegram-relay.js'] = true;

    // نسخه‌ی فایلها از زمانِ اصلاحِ آخرین فایلِ اسکریپت گرفته می‌شود تا
    // پس از هر آپدیت، مرورگرِ ادمین (و کشِ هاست) دیگر نسخه‌ی کهنه را
    // اجرا نکند؛ قبلاً بدون این پارامتر، گوشی‌ها ساعت‌ها روی JS قدیم
    // می‌ماندند و رفتار پنل با کدِ روی سرور فرق می‌کرد.
    window.MELKINO_ASSET_VERSION = <?php
        $melkinoAssetFiles = ['assets/js/admin-bots-controls.js', 'admin-ads.js', 'admin-new-tabs.js', 'admin-requests.js', 'telegram-relay.js', 'admin-field-display.js', 'admin/shell.css', 'admin/navigation.js', 'admin-publish-center.js'];
        $melkinoAssetVersion = 0;
        foreach ($melkinoAssetFiles as $melkinoAssetFile) {
            $melkinoAssetMtime = @filemtime(__DIR__ . '/' . $melkinoAssetFile);
            if ($melkinoAssetMtime && $melkinoAssetMtime > $melkinoAssetVersion) {
                $melkinoAssetVersion = $melkinoAssetMtime;
            }
        }
        echo json_encode($melkinoAssetVersion ?: (int)date('Ymd'));
    ?>;

    // هر تب به کدام اسکریپت نیاز دارد
    window.MELKINO_TAB_SCRIPTS = {
        ads:         ['telegram-relay.js', 'admin-ads.js', 'admin-ads-map.js'],
        map:         ['admin-map.js'],
        requests:    ['admin-requests.js'],
        sms:         ['admin-comm.js', 'admin-sms.js', 'assets/js/admin-comm-sms-tab.js'],
        assistant:   ['admin-assistant.js'],
        bots:        ['assets/js/admin-bots-controls.js', 'admin-new-tabs.js'],
        display:     ['admin-new-tabs.js', 'admin-field-display.js'],
        images:      ['admin-new-tabs.js'],
        promotions:  ['admin-new-tabs.js'],
        theme:       ['admin-new-tabs.js'],
        studio:      ['design-studio.js'],
        story:       ['assets/js/ig-studio-model.js', 'assets/js/ig-studio-templates.js', 'assets/js/ig-studio-ref-templates.js', 'assets/js/ig-studio-post-templates.js', 'assets/vendor/qrcode/qrcode.js', 'assets/js/ig-studio-render.js', 'assets/js/ig-studio-general.js', 'assets/js/ig-studio-pack.js', 'assets/js/ig-studio-export.js', 'assets/js/ig-studio-ops.js', 'assets/js/ig-studio-state.js', 'assets/js/ig-studio.js'],
        backup:      ['admin-new-tabs.js'],
        diagnostics: ['admin-new-tabs.js'],
        advisor:     ['assets/vendor/leaflet/leaflet.js', 'assets/js/admin-tab-advisor.js'],
        publish:     ['admin-publish-center.js']
    };

    var ALL = ['assets/js/admin-bots-controls.js', 'admin-ads.js', 'admin-new-tabs.js', 'admin-requests.js', 'admin-publish-center.js'];

    function loadOne(src, cb) {
        if (window.MELKINO_LOADED_SCRIPTS[src]) { cb(); return; }
        var queue = window.MELKINO_LOADING_SCRIPTS[src];
        if (queue) { queue.push(cb); return; }
        window.MELKINO_LOADING_SCRIPTS[src] = [cb];
        var s = document.createElement('script');
        s.src = src + '?v=' + window.MELKINO_ASSET_VERSION;
        s.async = false;
        var done = function () {
            window.MELKINO_LOADED_SCRIPTS[src] = true;
            var q = window.MELKINO_LOADING_SCRIPTS[src] || [];
            window.MELKINO_LOADING_SCRIPTS[src] = null;
            for (var i = 0; i < q.length; i++) { try { q[i](); } catch (e) {} }
        };
        s.onload  = done;
        s.onerror = done;
        document.head.appendChild(s);
    }

    window.melkinoLoadAdminScripts = function (list, cb) {
        var i = 0;
        (function next() {
            if (i >= list.length) { cb(); return; }
            loadOne(list[i++], function () { next(); });
        })();
    };

    // پیش‌بارگیریِ پس‌زمینه؛ با تأخیرِ کوتاه تا بارِ اولیه سنگین نشود
    function prefetch() {
        var i = 0;
        (function next() {
            if (i >= ALL.length) return;
            var src = ALL[i++];
            loadOne(src, function () { setTimeout(next, 60); });
        })();
    }

    if (document.readyState === 'complete') {
        setTimeout(prefetch, 120);
    } else {
        window.addEventListener('load', function () { setTimeout(prefetch, 120); });
    }
})();
</script>

<script>
(function(){
  function tick(){var e=document.getElementById('adminCommandClock');if(!e)return;var d=new Date();e.textContent=d.toLocaleDateString('fa-IR')+' • '+d.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit'});}
  tick();setInterval(tick,30000);
})();
</script>
<div id="userRevisionFab" style="position:fixed;left:18px;bottom:18px;z-index:10000;display:none"><button type="button" id="openUserRevisions" style="border:0;background:#0b5d59;color:#fff;border-radius:999px;padding:12px 16px;font-family:inherit;font-weight:800;box-shadow:0 10px 30px rgba(0,0,0,.18);cursor:pointer"><?= melkinoSvgIcon('edit') ?> ویرایش‌های در انتظار تأیید <span id="userRevisionCount">0</span></button></div>
<div id="userRevisionModal" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;display:none;align-items:center;justify-content:center;padding:16px"><div style="width:min(760px,100%);max-height:85vh;overflow:auto;background:var(--surface-elevated,#fff);color:var(--text-primary,#111);border-radius:18px;padding:18px;direction:rtl"><div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">ویرایش‌های در انتظار تأیید</h3><button id="closeUserRevisions" type="button" aria-label="بستن">✕</button></div><div id="userRevisionList" style="margin-top:14px"></div></div></div>
<script>
(function(){
 const fab=document.getElementById('userRevisionFab'), modal=document.getElementById('userRevisionModal'), list=document.getElementById('userRevisionList'), cnt=document.getElementById('userRevisionCount');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 async function load(){try{const d=await fetch('admin-property-revisions.php?action=list',{cache:'no-store'}).then(r=>r.json());const rows=d.revisions||[];cnt.textContent=rows.length;fab.style.display=rows.length?'block':'none';list.innerHTML=rows.length?rows.map(r=>{const s=r.snapshot||{},x=s.after||{},b=s.before||{};
const F=[['title','عنوان'],['area','متراژ'],['price_sell','قیمت فروش'],['deposit','رهن'],['rent_monthly','اجاره ماهانه'],['description','توضیحات']];
const ch=F.filter(([k])=>String(b[k]??'').trim()!==String(x[k]??'').trim()).map(([k,l])=> k==='description'
  ? `<details style="margin-top:6px;font-size:12px;color:#666"><summary style="cursor:pointer">توضیحات (قبل و بعد)</summary><div style="white-space:pre-wrap;background:rgba(127,127,127,.08);border-radius:8px;padding:8px;margin-top:6px">قبل: ${esc(b.description||'—')}\nبعد: ${esc(x.description||'—')}</div></details>`
  : `<div style="font-size:12px;color:#666;margin-top:4px"><b style="color:inherit">${l}:</b> ${esc(String(b[k]??'—'))} <span style="color:var(--success,#0a7a52)">←</span> ${esc(String(x[k]??'—'))}</div>`).join('')
  || '<div style="font-size:12px;color:#666;margin-top:6px">تغییری در فیلدهای اصلی ثبت نشده است.</div>';
return `<div style="border:1px solid #e2e2e2;border-radius:14px;padding:13px;margin:10px 0"><b>${esc(r.ad_id)} — ${esc(r.title||'')}</b><div style="font-size:11px;color:#999;margin-top:2px">تاریخ: ${esc(String(r.created_at||''))}</div><div style="margin-top:8px;padding:8px 10px;border:1px dashed #d0d0d0;border-radius:10px">${ch}</div><div style="display:flex;gap:8px;margin-top:10px"><button type="button" data-rev="${r.id}" data-act="approve">${MK_IC.check} تأیید و انتشار</button><button type="button" data-rev="${r.id}" data-act="reject">${MK_IC.x} رد</button></div></div>`}).join(''):'<div style="text-align:center;color:#666;padding:30px">موردی برای بررسی نیست.</div>';}catch(e){list.innerHTML='<div style="color:#b00020">خطا در بارگذاری ویرایش‌ها.</div>';}}
 document.getElementById('openUserRevisions').onclick=()=>{modal.style.display='flex';load();};document.getElementById('closeUserRevisions').onclick=()=>modal.style.display='none';
 list.onclick=async e=>{const b=e.target.closest('[data-rev]');if(!b)return;if(!confirm(b.dataset.act==='approve'?'این ویرایش تأیید و دوباره منتشر شود؟':'این ویرایش رد شود؟'))return;const d=await fetch('admin-panel.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({user_revision_action:b.dataset.act,revision_id:b.dataset.rev})}).then(r=>r.json());alert(d.message||'');if(d.success)load();};
 /* اجرایِ فوری حذف شد تا با بارگذاریِ اولیه رقابت نکند */
 setTimeout(load, 4000);setInterval(load,60000);
})();
</script>

<?php
if (is_file(__DIR__ . '/onboarding-tour.php')) {
    require_once __DIR__ . '/onboarding-tour.php';
    melkinoProductTourBoot('admin');
}
?>
<script src="admin/navigation.js?v=<?php echo (int) @filemtime(__DIR__ . '/admin/navigation.js'); ?>"></script>
</body>
</html>
