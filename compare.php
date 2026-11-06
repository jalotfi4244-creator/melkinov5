<?php
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    @session_start();
}
$_mkPage = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
$_mkAllow = ['login.php','logout.php','auth.php','auth-telegram.php','auth-bale.php','auth-eitaa.php','request-otp.php','verify-otp.php','admin-login.php','admin-logout.php','telegram.php','bale.php','eitaa.php','telegram-relay.php','identity-sync.php','bale-ok.php','r.php'];
if (
    $_mkPage !== ''
    && !in_array($_mkPage, $_mkAllow, true)
    && strncmp($_mkPage, 'admin-', 6) !== 0
    && empty($_SESSION['user_id'])
    && empty($_SESSION['reg_telegram_id'])
    && empty($_SESSION['reg_bale_id'])
    && empty($_SESSION['reg_eitaa_id'])
    && empty($_SESSION['user_phone'])
    && empty($_SESSION['is_admin'])
) {
    $here = (string) ($_SERVER['REQUEST_URI'] ?? $_mkPage);
    $here = preg_replace('#^/+#', '', $here) ?? $_mkPage;
    if ($here === '' || strpos($here, 'login.php') === 0) {
        $here = 'home.php';
    }
    if (!headers_sent()) {
        header('Location: login.php?redirect=' . rawurlencode($here), true, 302);
    }
    exit;
}
unset($_mkPage, $_mkAllow);
/*
|--------------------------------------------------------------------------
| مقایسه ملک‌ها (API)
|--------------------------------------------------------------------------
| کاربر از روی کارت‌ها یا صفحه جزئیات، ملک را به مقایسه اضافه می‌کند؛
| در پروفایل ۳ دسته (قابل تغییرنام) وجود دارد که هر دسته حداکثر ۵ ملک
| می‌گیرد و کاربر مشخص می‌کند کدام ملک‌ها با هم مقایسه شوند.
|
| actions:
|   add            افزودن ملک (group=1..3 یا auto)
|   remove         حذف ملک از مقایسه
|   move           جابه‌جایی ملک بین دسته‌ها
|   list           فهرست دسته‌ها + ملک‌ها
|   rename_group   تغییر نام دسته
|   clear_group    خالی کردن یک دسته
|   score          امتیازدهی و مقایسه کنارهم یک دسته (از ۱۰۰)
|   count          تعداد کل ملک‌های مقایسه
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/db_helpers.php';

// تمام منطق مقایسه (جدول‌ها، مالکیت، مهمان، امتیازدهی) در compare-lib.php
// متمرکز است تا این API و compare-page.php دقیقاً یک رفتار داشته باشند.
// قبلاً این فایل یک کپی قدیمیِ بدون پشتیبانیِ «مهمان» از این تابع‌ها داشت؛
// نتیجه‌اش این بود که دکمه‌ی «افزودن به مقایسه» برای کاربرِ وارد‌نشده
// به‌جای اضافه‌کردن، خطا (۴۰۱) می‌داد.
require_once __DIR__ . '/compare-lib.php';

global $pdo;
/* =====================================================
   ACTIONS
   ===================================================== */

if (!isset($_GET['action'])) {
    melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
}

if (!$pdo instanceof PDO) {
    melkinoJsonResponse(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
}

try {
    melkinoEnsureCompareTables($pdo);
} catch (Throwable $e) {
    melkinoJsonResponse(['success' => false, 'message' => 'خطا در آماده‌سازی جدول‌ها.'], 500);
}

$identity = melkinoCurrentIdentity();

// راند ۲۴: مقایسه فقط برای کاربرِ وارد‌شده است. مهمان (کوکی مهمان) دیگر
// نه می‌تواند ملک اضافه کند و نه صفحه/دادهٔ مقایسه می‌بیند.
$loggedIn = !empty($identity['user_id']);

// اگر مهمانی قبلاً ملک‌هایی اضافه کرده و حالا وارد حساب شده، ردیف‌هایش
// یک‌بار به حساب منتقل می‌شوند تا مقایسه‌هایش گم نشود.
if ($loggedIn) {
    try {
        melkinoCompareMergeGuest($pdo, $identity);
    } catch (Throwable $e) {
        // خطای ادغام نباید خودِ درخواست را بشکند
    }
}

[$ownerWhere, $ownerParams, $ownerUserId, $ownerTelegramId, $ownerGuestToken] = melkinoCompareOwner($identity, 'ci');
[$ownerWherePlain, $ownerParamsPlain] = melkinoCompareOwner($identity);
$action = trim((string)$_GET['action']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { melkinoCsrfCheck(); }

if ($ownerWhere === '') {
    melkinoJsonResponse(['success' => false, 'message' => 'برای مقایسه، اول وارد حساب شو.'], 401);
}


/* ---------------------------------------------------------------
   راند ۲۴: قانون «یک نوع ملک در هر دسته» + helperهای مشترک
   --------------------------------------------------------------- */
$melkinoCmpTypeOf = function (string $adId) use ($pdo): string {
    $st = $pdo->prepare('SELECT property_type FROM ads WHERE id = ? LIMIT 1');
    $st->execute([$adId]);
    return trim((string)$st->fetchColumn());
};
$melkinoCmpGroupTypes = function (int $g) use ($pdo, $ownerWhere, $ownerParams): array {
    $st = $pdo->prepare(
        "SELECT DISTINCT a.property_type FROM compare_items ci
         INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
         WHERE $ownerWhere AND ci.group_no = ?"
    );
    $pp = $ownerParams;
    $pp[] = $g;
    $st->execute($pp);
    return array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN)), static fn($t) => $t !== ''));
};
$melkinoCmpAssertSameType = function (int $g, string $newType) use ($melkinoCmpGroupTypes, $pdo, $ownerWherePlain, $ownerParamsPlain): void {
    $types = $melkinoCmpGroupTypes($g);
    if ($types && $newType !== '' && !in_array($newType, $types, true)) {
        melkinoJsonResponse([
            'success' => false,
            'message' => 'هر دستهٔ مقایسه فقط یک نوع ملک می‌پذیرد؛ این دسته شامل «' . $types[0] . '» است و ملک شما «' . $newType . '» است. یک دستهٔ دیگر بسازید یا این دسته را خالی کنید.',
        ], 422);
    }
};

if ($action === 'count') {
    if (!$loggedIn) {
        melkinoJsonResponse(['success' => true, 'count' => 0, 'logged_in' => false]);
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM compare_items ci
         INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
         WHERE $ownerWhere"
    );
    $stmt->execute($ownerParams);
    melkinoJsonResponse(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
}
// راند ۲۴: همهٔ عملیات مقایسه (جز count) نیاز به ورود دارند
if (!$loggedIn) {
    melkinoJsonResponse(['success' => false, 'logged_in' => false, 'message' => 'برای استفاده از مقایسهٔ ملک‌ها، اول وارد حساب کاربری شوید.'], 401);
}


if ($action === 'list') {
    $groups = [];
    $total = 0;
    for ($g = 1; $g <= 3; $g++) {
        $items = melkinoCompareItems($pdo, $ownerWhere, $ownerParams, $g);
        $total += count($items);
        $groups[] = [
            'no' => $g,
            'name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $g),
            'count' => count($items),
            'items' => $items,
        ];
    }
    melkinoJsonResponse(['success' => true, 'groups' => $groups, 'total' => $total]);
}

if ($action === 'ids') {
    $stmt = $pdo->prepare(
        "SELECT ci.ad_id FROM compare_items ci
         INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
         WHERE $ownerWhere"
    );
    $stmt->execute($ownerParams);
    melkinoJsonResponse(['success' => true, 'ids' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
}

if ($action === 'toggle') {
    // راند ۲۴: کلیک دوباره روی دکمه = حذف از مقایسه
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }
    $stmt = $pdo->prepare("SELECT id FROM compare_items ci WHERE $ownerWhere AND ci.ad_id = ? LIMIT 1");
    $p = $ownerParams;
    $p[] = $adId;
    $stmt->execute($p);
    if ($stmt->fetchColumn() !== false) {
        $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND ad_id = ?");
        $p2 = $ownerParamsPlain;
        $p2[] = $adId;
        $stmt->execute($p2);
        melkinoJsonResponse(['success' => true, 'removed' => true, 'message' => 'از مقایسه حذف شد.']);
    }
    $_POST['ad_id'] = $adId;
    $_POST['group'] = 'auto';
    $action = 'add'; // ادامهٔ مسیر افزودن با همهٔ بررسی‌ها
}

if ($action === 'add') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    $groupRaw = trim((string)($_POST['group'] ?? $_GET['group'] ?? 'auto'));
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }
    $stmt = $pdo->prepare("SELECT id, title FROM ads WHERE id = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$adId]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ad) {
        melkinoJsonResponse(['success' => false, 'message' => 'آگهی پیدا نشد یا منتشر نیست.'], 404);
    }

    // تکراری؟
    $stmt = $pdo->prepare("SELECT group_no FROM compare_items ci WHERE $ownerWhere AND ci.ad_id = ? LIMIT 1");
    $p = $ownerParams;
    $p[] = $adId;
    $stmt->execute($p);
    $existingGroup = $stmt->fetchColumn();
    if ($existingGroup !== false) {
        melkinoJsonResponse([
            'success' => true,
            'already' => true,
            'group' => (int)$existingGroup,
            'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, (int)$existingGroup),
            'message' => 'این ملک قبلاً به مقایسه اضافه شده.',
        ]);
    }

    // انتخاب دسته: auto یعنی اولین دسته‌ای که جا دارد
    $countIn = function (int $g) use ($pdo, $ownerWhere, $ownerParams): int {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $pp = $ownerParams;
        $pp[] = $g;
        $s->execute($pp);
        return (int)$s->fetchColumn();
    };
    $newType = $melkinoCmpTypeOf($adId);
    if ($groupRaw === 'auto' || $groupRaw === '' || $groupRaw === '0') {
        $groupNo = 0;
        for ($g = 1; $g <= 3; $g++) {
            if ($countIn($g) < 5) {
                $gt = $melkinoCmpGroupTypes($g);
                if ($gt === [] || ($newType !== '' && in_array($newType, $gt, true))) {
                    $groupNo = $g;
                    break;
                }
            }
        }
        if ($groupNo === 0) {
            melkinoJsonResponse(['success' => false, 'message' => 'دستهٔ هم‌نوعِ «' . ($newType ?: 'این ملک') . '» جا ندارد؛ یک دستهٔ دیگر را خالی کنید (حداکثر ۵ ملک هم‌نوع در هر دسته).'], 422);
        }
    } else {
        $groupNo = (int)$groupRaw;
        if ($groupNo < 1 || $groupNo > 3) {
            melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
        }
        if ($countIn($groupNo) >= 5) {
            melkinoJsonResponse(['success' => false, 'message' => 'این دسته پر است (حداکثر ۵ ملک در هر دسته).'], 422);
        }
        $melkinoCmpAssertSameType($groupNo, $newType);
    }

    $stmt = $pdo->prepare('INSERT INTO compare_items (user_id, telegram_id, guest_token, ad_id, group_no) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $ownerUserId,
        $ownerTelegramId !== '' ? $ownerTelegramId : null,
        $ownerGuestToken !== '' ? $ownerGuestToken : null,
        $adId,
        $groupNo,
    ]);
    melkinoJsonResponse([
        'success' => true,
        'group' => $groupNo,
        'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'message' => 'به مقایسه اضافه شد.',
    ]);
}

if ($action === 'remove') {
    $adId = trim((string)($_POST['ad_id'] ?? $_GET['ad_id'] ?? ''));
    if ($adId === '') {
        melkinoJsonResponse(['success' => false, 'message' => 'شناسه آگهی الزامی است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND ad_id = ?");
    $p = $ownerParamsPlain;
    $p[] = $adId;
    $stmt->execute($p);
    melkinoJsonResponse(['success' => true, 'removed' => $stmt->rowCount() > 0]);
}

if ($action === 'move') {
    $adId = trim((string)($_POST['ad_id'] ?? ''));
    $groupNo = (int)($_POST['group'] ?? 0);
    if ($adId === '' || $groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'اطلاعات نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare("SELECT id, group_no FROM compare_items WHERE $ownerWherePlain AND ad_id = ? LIMIT 1");
    $p = $ownerParamsPlain;
    $p[] = $adId;
    $stmt->execute($p);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        melkinoJsonResponse(['success' => false, 'message' => 'این ملک در مقایسه نیست.'], 404);
    }
    if ((int)$row['group_no'] !== $groupNo) {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM compare_items ci
             INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
             WHERE $ownerWhere AND ci.group_no = ?"
        );
        $pp = $ownerParams;
        $pp[] = $groupNo;
        $s->execute($pp);
        if ((int)$s->fetchColumn() >= 5) {
            melkinoJsonResponse(['success' => false, 'message' => 'دسته مقصد پر است (حداکثر ۵ ملک).'], 422);
        }
        $melkinoCmpAssertSameType($groupNo, $melkinoCmpTypeOf($adId));
        $pdo->prepare('UPDATE compare_items SET group_no = ? WHERE id = ?')->execute([$groupNo, (int)$row['id']]);
    }
    melkinoJsonResponse(['success' => true, 'group' => $groupNo]);
}

if ($action === 'rename_group') {
    $groupNo = (int)($_POST['group'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 40, 'UTF-8');
    } else {
        $name = substr($name, 0, 40);
    }
    $stmt = $pdo->prepare("SELECT id FROM compare_groups WHERE $ownerWherePlain AND group_no = ? LIMIT 1");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        $pdo->prepare('UPDATE compare_groups SET name = ? WHERE id = ?')->execute([$name, (int)$existing]);
    } else {
        $pdo->prepare('INSERT INTO compare_groups (user_id, telegram_id, guest_token, group_no, name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ownerUserId, $ownerTelegramId !== '' ? $ownerTelegramId : null, $ownerGuestToken !== '' ? $ownerGuestToken : null, $groupNo, $name]);
    }
    melkinoJsonResponse(['success' => true, 'name' => $name]);
}

if ($action === 'clear_group') {
    $groupNo = (int)($_POST['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare("DELETE FROM compare_items WHERE $ownerWherePlain AND group_no = ?");
    $p = $ownerParamsPlain;
    $p[] = $groupNo;
    $stmt->execute($p);
    melkinoJsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
}

if ($action === 'score') {
    $groupNo = (int)($_POST['group'] ?? $_GET['group'] ?? 0);
    if ($groupNo < 1 || $groupNo > 3) {
        melkinoJsonResponse(['success' => false, 'message' => 'دسته نامعتبر است.'], 422);
    }
    $stmt = $pdo->prepare(
        "SELECT a.*,
                i.filename AS image,
                (SELECT COUNT(*) FROM images i2 WHERE i2.ad_id = a.id AND i2.is_selected = 1 AND i2.publish_publicly = 1) AS img_count,
                (SELECT COUNT(*) FROM ad_amenities aa WHERE aa.ad_id = a.id) AS amenity_count,
                (SELECT GROUP_CONCAT(am.name SEPARATOR '، ') FROM ad_amenities aa JOIN amenities am ON am.id = aa.amenity_id WHERE aa.ad_id = a.id) AS amenity_names
         FROM compare_items ci
         INNER JOIN ads a ON a.id COLLATE utf8mb4_general_ci = ci.ad_id COLLATE utf8mb4_general_ci AND a.status = 'published'
         LEFT JOIN images i ON i.id = (
             SELECT i3.id FROM images i3
             WHERE i3.ad_id = a.id AND i3.is_selected = 1 AND i3.publish_publicly = 1
             ORDER BY i3.is_primary DESC, i3.sort_order ASC, i3.id ASC LIMIT 1
         )
         WHERE $ownerWhere AND ci.group_no = ?
         ORDER BY ci.created_at ASC"
    );
    $p = $ownerParams;
    $p[] = $groupNo;
    $stmt->execute($p);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($ads) < 2) {
        melkinoJsonResponse(['success' => false, 'message' => 'برای مقایسه حداقل ۲ ملک در این دسته لازم است.'], 422);
    }
    if ($result === null) {
        $result = melkinoScoreCompareGroup($ads);
    }
    melkinoJsonResponse([
        'success' => true,
        'group' => $groupNo,
        'group_name' => melkinoCompareGroupName($pdo, $ownerWherePlain, $ownerParamsPlain, $groupNo),
        'ads' => $ads,
        'scores' => $result['scores'],
        'winner' => $result['winner'],
        'highlights' => $result['highlights'],
        'mixed_types' => $result['mixed_types'],
        'engine' => $result['engine'] ?? null,
    ]);
}

melkinoJsonResponse(['success' => false, 'message' => 'عملیات نامعتبر است.'], 400);
