<?php require_once __DIR__ . '/config.php';
/**
|--------------------------------------------------------------------------
| مدیریت تصاویر آگهی‌ها (پنل ادمین)
|--------------------------------------------------------------------------
| actions:
|   list            فهرست تصاویر (با جستجو و فیلتر آگهی)
|   delete          حذف یک تصویر (فایل + ردیف دیتابیس)
|   delete_orphans  پاکسازی فایل‌های بدون استفاده در پوشه uploads
|   orphans         فقط شمارش/فهرست فایل‌های بدون استفاده
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';

/* =========================================================
   راند ۶۵: مدیریت عکس‌های پیش‌فرض انواع ملک (assets/defaults)
   آپلود/جایگزینی/حذف مستقیم از پنل ادمین — بدون فایل‌منیجر
   ========================================================= */
if (isset($_GET['mk_defaults'])) {
    header('Content-Type: application/json; charset=utf-8');
    $melkinoTypes = [
        'apartment' => 'آپارتمان', 'villa' => 'ویلا', 'shop' => 'تجاری / مغازه',
        'office' => 'اداری', 'land' => 'زمین', 'garden' => 'باغ',
    ];
    $melkinoExts = ['jpg', 'jpeg', 'png', 'webp'];
    $melkinoSlotFile = function (string $slug, int $slot) use ($melkinoExts): string {
        foreach ($melkinoExts as $e) {
            $rel = 'assets/defaults/' . $slug . '-' . $slot . '.' . $e;
            if (is_file(__DIR__ . '/' . $rel)) { return $rel; }
        }
        return '';
    };
    $melkinoState = function () use ($melkinoTypes, $melkinoSlotFile): array {
        $rows = [];
        foreach ($melkinoTypes as $slug => $label) {
            $slots = [];
            for ($i = 1; $i <= 5; $i++) { $slots[$i] = $melkinoSlotFile($slug, $i); }
            $rows[] = ['slug' => $slug, 'label' => $label, 'slots' => $slots];
        }
        return $rows;
    };
    $melkinoAction = (string)$_GET['mk_defaults'];

    if ($melkinoAction === 'state') {
        echo json_encode(['success' => true, 'types' => $melkinoState()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($melkinoAction === 'upload' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $slug = (string)($_POST['slug'] ?? '');
        $slot = (int)($_POST['slot'] ?? 0);
        if (!isset($melkinoTypes[$slug]) || $slot < 1 || $slot > 5) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => 'پارامتر نامعتبر'], JSON_UNESCAPED_UNICODE); exit;
        }
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشد'], JSON_UNESCAPED_UNICODE); exit;
        }
        $f = $_FILES['image'];
        if ($f['size'] > 5 * 1024 * 1024) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => 'حجم فایل بیشتر از ۵ مگابایت است'], JSON_UNESCAPED_UNICODE); exit;
        }
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string)finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => 'فرمت مجاز نیست (png/jpg/webp)'], JSON_UNESCAPED_UNICODE); exit;
        }
        $dir = __DIR__ . '/assets/defaults';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            http_response_code(500); echo json_encode(['success' => false, 'message' => 'ساخت پوشه assets/defaults ناموفق بود'], JSON_UNESCAPED_UNICODE); exit;
        }
        foreach ($melkinoExts as $e) {
            $old = $dir . '/' . $slug . '-' . $slot . '.' . $e;
            if (is_file($old)) { @unlink($old); }
        }
        $dest = $dir . '/' . $slug . '-' . $slot . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            @copy($f['tmp_name'], $dest);
        }
        if (!is_file($dest)) {
            http_response_code(500); echo json_encode(['success' => false, 'message' => 'ذخیرهٔ فایل ناموفق بود'], JSON_UNESCAPED_UNICODE); exit;
        }
        echo json_encode(['success' => true, 'message' => 'عکس ذخیره شد', 'types' => $melkinoState()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($melkinoAction === 'delete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $slug = (string)($_POST['slug'] ?? '');
        $slot = (int)($_POST['slot'] ?? 0);
        if (!isset($melkinoTypes[$slug]) || $slot < 1 || $slot > 5) {
            http_response_code(400); echo json_encode(['success' => false, 'message' => 'پارامتر نامعتبر'], JSON_UNESCAPED_UNICODE); exit;
        }
        foreach ($melkinoExts as $e) {
            $old = __DIR__ . '/assets/defaults/' . $slug . '-' . $slot . '.' . $e;
            if (is_file($old)) { @unlink($old); }
        }
        echo json_encode(['success' => true, 'message' => 'حذف شد', 'types' => $melkinoState()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400); echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** مسیر فیزیکی یک تصویر روی سرور (با پشتیبانی از فرمت‌های مختلف ذخیره‌سازی) */
function melkinoImageAbsolutePath(array $row): string
{
    $raw = trim((string)($row['storage_path'] ?? ''));
    if ($raw === '') {
        $raw = trim((string)($row['filename'] ?? ''));
    }
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('\\', '/', $raw);

    // اگر مسیر کامل روی سرور است
    if (strpos($raw, '/') === 0 && is_file($raw)) {
        return $raw;
    }

    // اگر آدرس اینترنتی است
    if (preg_match('#^https?://#i', $raw)) {
        return '';
    }

    $candidates = [
        __DIR__ . '/' . ltrim($raw, '/'),
        __DIR__ . '/uploads/' . ltrim($raw, '/'),
        __DIR__ . '/uploads/' . basename($raw),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

/** آدرس وبِ نمایش تصویر */
function melkinoImageWebPath(array $row): string
{
    $raw = trim((string)($row['filename'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $raw)) {
        return $raw;
    }
    $raw = str_replace('\\', '/', $raw);
    if (strpos($raw, 'uploads/') === 0) {
        return $raw;
    }
    return 'uploads/' . ltrim($raw, '/');
}

$melkinoImageAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoImageAction !== '') {
    melkinoRequireAdminJson();
    melkinoRequirePostFor(['delete', 'delete_bulk', 'delete_orphans'], $melkinoImageAction);

    global $pdo;

    switch ($melkinoImageAction) {

        case 'list':
            $q = trim((string)($_GET['q'] ?? ''));
            $adId = trim((string)($_GET['ad_id'] ?? ''));
            $onlyMissing = ($_GET['missing'] ?? '') === '1';

            $where = [];
            $params = [];

            if ($q !== '') {
                $where[] = '(i.filename LIKE ? OR a.title LIKE ? OR i.ad_id LIKE ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($adId !== '') {
                $where[] = 'i.ad_id = ?';
                $params[] = $adId;
            }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            try {
                $stmt = $pdo->prepare(
                    "SELECT i.id, i.ad_id, i.filename, i.storage_path, i.is_primary,
                            i.is_selected, i.publish_publicly, i.created_at,
                            a.title AS ad_title
                       FROM images i
                       LEFT JOIN ads a ON a.id = i.ad_id
                       $whereSql
                      ORDER BY i.id DESC
                      LIMIT 300"
                );
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try {
                    $rows = $pdo->query(
                        "SELECT id, ad_id, filename, storage_path, is_primary, is_selected, publish_publicly
                           FROM images ORDER BY id DESC LIMIT 300"
                    )->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e2) {
                    melkinoAdminJson(['success' => false, 'message' => 'خواندن جدول تصاویر ممکن نشد.'], 500);
                }
            }

            $items = [];
            foreach ($rows as $row) {
                $abs = melkinoImageAbsolutePath($row);
                $exists = $abs !== '' && is_file($abs);

                if ($onlyMissing && $exists) {
                    continue;
                }

                $items[] = [
                    'id' => (int)$row['id'],
                    'ad_id' => (string)($row['ad_id'] ?? ''),
                    'ad_title' => (string)($row['ad_title'] ?? ''),
                    'filename' => basename((string)($row['filename'] ?? '')),
                    'url' => melkinoImageWebPath($row),
                    'exists' => $exists,
                    'size' => $exists ? filesize($abs) : 0,
                    'size_human' => $exists ? round(filesize($abs) / 1024, 1) . ' کیلوبایت' : '—',
                    'is_primary' => (int)($row['is_primary'] ?? 0),
                    'is_selected' => (int)($row['is_selected'] ?? 0),
                    'publish_publicly' => (int)($row['publish_publicly'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? ''),
                ];
            }

            melkinoAdminJson(['success' => true, 'images' => $items, 'count' => count($items)]);

        case 'delete':
            $data = melkinoAdminJsonBody();
            $id = (int)($data['id'] ?? 0);
            $removeFile = !isset($data['remove_file']) || !empty($data['remove_file']);

            if ($id <= 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه نامعتبر است.'], 422);
            }

            $stmt = $pdo->prepare("SELECT * FROM images WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                melkinoAdminJson(['success' => false, 'message' => 'تصویری با این شناسه پیدا نشد.'], 404);
            }

            $fileDeleted = false;
            $fileError = '';

            if ($removeFile) {
                $abs = melkinoImageAbsolutePath($row);
                if ($abs !== '' && is_file($abs)) {
                    if (@unlink($abs)) {
                        $fileDeleted = true;
                    } else {
                        $fileError = 'فایل روی سرور حذف نشد (دسترسی پوشه را بررسی کن).';
                    }
                } else {
                    $fileDeleted = true; // فایل از قبل وجود نداشته
                }
            }

            $pdo->prepare("DELETE FROM images WHERE id = ?")->execute([$id]);

            // اگر تصویر حذف‌شده اصلی بود، تصویر بعدی همان آگهی را اصلی کن
            if (!empty($row['is_primary'])) {
                $next = $pdo->prepare("SELECT id FROM images WHERE ad_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                $next->execute([(string)$row['ad_id']]);
                $nextId = $next->fetchColumn();
                if ($nextId) {
                    $pdo->prepare("UPDATE images SET is_primary = 1 WHERE id = ?")->execute([$nextId]);
                }
            }

            melkinoAdminJson([
                'success' => true,
                'file_deleted' => $fileDeleted,
                'message' => $fileDeleted
                    ? 'تصویر از دیتابیس و سرور حذف شد.'
                    : ('ردیف دیتابیس حذف شد، اما ' . ($fileError ?: 'فایل روی سرور پیدا نشد.')),
            ]);

        case 'delete_bulk':
            // حذف دسته‌جمعی تصاویر یک آگهی (فایل‌ها + ردیف‌های دیتابیس)
            $data = melkinoAdminJsonBody();
            $adId = (string)($data['ad_id'] ?? '');
            $rawFiles = $data['filenames'] ?? [];
            if (!is_array($rawFiles)) {
                $rawFiles = [];
            }
            $files = array_values(array_unique(array_filter(
                array_map(static function ($v) {
                    return trim((string) $v);
                }, $rawFiles),
                static function ($v) {
                    return $v !== '';
                }
            )));

            if ($adId === '' || count($files) === 0) {
                melkinoAdminJson(['success' => false, 'message' => 'شناسه‌ی آگهی یا فهرست تصاویر نامعتبر است.'], 422);
            }

            try {
                $pdo->beginTransaction();
            } catch (Throwable $e) {
                // در صورتی که transaction در دسترس نبود، ادامه می‌دهیم
            }

            $sel = $pdo->prepare("SELECT id, storage_path, filename FROM images WHERE ad_id = ? AND filename = ? LIMIT 1");
            $del = $pdo->prepare("DELETE FROM images WHERE id = ?");
            $deletedRows = 0;
            $deletedFiles = 0;
            $fileErrors = 0;

            foreach ($files as $file) {
                try {
                    $sel->execute([$adId, $file]);
                    $row = $sel->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        continue;
                    }
                    $abs = melkinoImageAbsolutePath($row);
                    if ($abs !== '' && is_file($abs)) {
                        if (@unlink($abs)) {
                            $deletedFiles++;
                        } else {
                            $fileErrors++;
                        }
                    }
                    $del->execute([$row['id']]);
                    $deletedRows++;
                } catch (Throwable $e) {
                    $fileErrors++;
                }
            }

            // اگر تصویر اصلی حذف شده بود، تصویر بعدی را اصلی می‌کنیم
            try {
                $primCount = $pdo->prepare("SELECT COUNT(*) FROM images WHERE ad_id = ? AND is_primary = 1");
                $primCount->execute([$adId]);
                if ((int) $primCount->fetchColumn() === 0) {
                    $next = $pdo->prepare("SELECT id FROM images WHERE ad_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                    $next->execute([$adId]);
                    $nextId = $next->fetchColumn();
                    if ($nextId) {
                        $pdo->prepare("UPDATE images SET is_primary = 1 WHERE id = ?")->execute([$nextId]);
                    }
                }
            } catch (Throwable $e) {
                // غیرمرکزی
            }

            try {
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
            }

            $msg = $deletedRows . ' تصویر از دیتابیس حذف شد';
            if ($fileErrors > 0) {
                $msg .= ' (' . $fileErrors . ' فایل روی سرور با خطا حذف نشد)';
            }
            melkinoAdminJson([
                'success' => true,
                'deleted_rows' => $deletedRows,
                'deleted_files' => $deletedFiles,
                'message' => $msg,
            ]);

        case 'orphans':
            // فایل‌هایی که در پوشه uploads هستند ولی هیچ ردیفی در دیتابیس ندارند
            $used = [];
            try {
                foreach ($pdo->query("SELECT filename, storage_path FROM images")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    foreach (['filename', 'storage_path'] as $key) {
                        $v = trim((string)($r[$key] ?? ''));
                        if ($v !== '') {
                            $used[basename(str_replace('\\', '/', $v))] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                $used = [];
            }

            $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $orphans = [];
            $totalSize = 0;

            foreach (glob(__DIR__ . '/uploads/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $name = basename($file);
                // فایل‌های سیستمی را حذف نکن
                if (strpos($name, 'onboarding-logo') === 0 || strpos($name, 'onboarding-first') === 0 || strpos($name, 'site-logo-') === 0) {
                    continue;
                }
                if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                    continue;
                }
                if (isset($used[$name])) {
                    continue;
                }
                $orphans[] = ['name' => $name, 'size' => filesize($file)];
                $totalSize += filesize($file);
            }

            melkinoAdminJson([
                'success' => true,
                'orphans' => $orphans,
                'count' => count($orphans),
                'total_size' => $totalSize,
                'total_size_human' => round($totalSize / 1024 / 1024, 2) . ' مگابایت',
            ]);

        case 'delete_orphans':
            $data = melkinoAdminJsonBody();
            $names = $data['files'] ?? null;

            if (!is_array($names) || !$names) {
                melkinoAdminJson(['success' => false, 'message' => 'فایلی انتخاب نشده است.'], 422);
            }

            $deleted = 0;
            $failed = 0;
            $freed = 0;

            foreach ($names as $name) {
                $safe = basename((string)$name);
                // فقط فایل‌های داخل پوشه uploads و فقط تصاویر
                if ($safe === '' || $safe !== (string)$name) {
                    $failed++;
                    continue;
                }
                if (strpos($safe, 'onboarding-logo') === 0) {
                    $failed++;
                    continue;
                }
                if (!in_array(strtolower(pathinfo($safe, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $failed++;
                    continue;
                }

                $path = __DIR__ . '/uploads/' . $safe;
                if (!is_file($path)) {
                    $failed++;
                    continue;
                }

                $size = filesize($path);
                if (@unlink($path)) {
                    $deleted++;
                    $freed += $size;
                } else {
                    $failed++;
                }
            }

            melkinoAdminJson([
                'success' => true,
                'deleted' => $deleted,
                'failed' => $failed,
                'freed_human' => round($freed / 1024 / 1024, 2) . ' مگابایت',
                'message' => $deleted . ' فایل حذف شد (' . round($freed / 1024 / 1024, 2) . ' مگابایت آزاد شد).',
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}
?>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('image') ?> مدیریت تصاویر آگهی‌ها</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="loadAdminImages()">
            ↻ بروزرسانی
        </button>
    </div>

    <div style="padding:0 16px 12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:200px;">
            <label class="admin-field-label">جستجو (نام فایل، عنوان آگهی یا شناسه آگهی)</label>
            <input type="text" id="imagesSearch" class="admin-input" placeholder="مثال: AD-2024 یا آپارتمان" oninput="loadAdminImages()">
        </div>
        <div>
            <label class="admin-field-label">فقط تصاویرِ فایل‌ندار</label>
            <select id="imagesMissingOnly" class="admin-input" onchange="loadAdminImages()">
                <option value="0">همه</option>
                <option value="1">فقط ردیف‌های بدون فایل</option>
            </select>
        </div>
    </div>

    <div style="padding:0 16px 8px;color:var(--text-secondary);font-size:12px;line-height:1.9;">
        حذف هر تصویر، هم ردیف آن را از دیتابیس پاک می‌کند و هم فایل اصلی را از پوشه‌ی <code dir="ltr">uploads</code> روی سرور.
    </div>

    <div id="adminImagesContainer" style="padding:0 16px 16px;"></div>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('trash') ?> پاکسازی فایل‌های بدون استفاده</span>
        <button type="button" class="btn-secondary" style="padding:6px 14px;font-size:12px;" onclick="scanOrphanImages()">
            <?= melkinoSvgIcon('search') ?> اسکن پوشه uploads
        </button>
    </div>

    <div style="padding:0 16px 8px;color:var(--text-secondary);font-size:12px;line-height:1.9;">
        فایل‌هایی که در پوشه‌ی آپلود مانده‌اند اما به هیچ آگهی‌ای وصل نیستند (مثلاً به‌خاطر حذف آگهی) را پیدا و حذف می‌کند.
        فایل لوگو و تصاویر تبلیغات هرگز حذف نمی‌شوند.
    </div>

    <div id="orphanImagesContainer" style="padding:0 16px 16px;"></div>
</div>


<div class="admin-card" style="margin-top:18px;">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('image') ?> عکس‌های پیش‌فرض انواع ملک</span>
    </div>
    <div style="padding:0 16px 16px;">
        <p style="font-size:13px; color:var(--text-secondary); margin:0 0 10px;">
            اگر آگهی عکس آپلودشده نداشته باشد، یکی از این عکس‌های تزیینی روی کارت و صفحهٔ جزئیات نمایش داده می‌شود.
            برای هر نوع ملک ۵ جایگاه وجود دارد؛ از همین‌جا می‌توانید عکس بگذارید، جایگزین یا حذف کنید (png / jpg / webp تا ۵ مگابایت).
        </p>
        <details id="mkDefaultsDetails">
            <summary style="cursor:pointer; font-weight:700; padding:8px 0;">باز کردن مدیریت عکس‌های پیش‌فرض</summary>
            <div style="margin:12px 0; padding:12px; border:1px dashed var(--border,#bbb); border-radius:12px; background:rgba(128,128,128,.06);">
                <div style="font-weight:800; margin-bottom:6px;">آپلود گروهی یک‌مرحله‌ای</div>
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:8px;">
                    همهٔ فایل‌های پوشهٔ assets/defaults زیپ (۳۰ عکس) را یک‌جا انتخاب کنید؛ جایگاه هر عکس از روی نام فایل تشخیص داده می‌شود
                    (مانند apartment-1.jpg ، villa-3.jpg ، shop-2.webp ، office-5.png ، land-4.jpg ، garden-1.jpg).
                </div>
                <input type="file" id="mkDefaultsBulk" multiple accept=".png,.jpg,.jpeg,.webp" style="font-size:12px;">
                <div id="mkDefaultsBulkStatus" style="margin-top:8px; font-size:12px; color:var(--text-secondary);"></div>
            </div>
            <div id="mkDefaultsHost" style="margin-top:12px;">در حال بارگذاری…</div>
        </details>
    </div>
</div>

<script>
(function () {
    var mkLoaded = false;
    var details = document.getElementById('mkDefaultsDetails');
    if (!details) return;
    details.addEventListener('toggle', function () {
        if (details.open && !mkLoaded) { mkLoaded = true; mkLoadDefaults(); }
    });

    var mkBulk = document.getElementById('mkDefaultsBulk');
    if (mkBulk) {
        mkBulk.addEventListener('change', function () {
            var files = Array.prototype.slice.call(mkBulk.files || []);
            if (!files.length) return;
            var status = document.getElementById('mkDefaultsBulkStatus');
            var jobs = [];
            var skipped = [];
            files.forEach(function (f) {
                var m = /^(apartment|villa|shop|office|land|garden)[-_](\d{1})\.(png|jpe?g|webp)$/i.exec(f.name);
                if (!m) { skipped.push(f.name); return; }
                var slot = parseInt(m[2], 10);
                if (slot < 1 || slot > 5) { skipped.push(f.name); return; }
                jobs.push({ file: f, slug: m[1].toLowerCase(), slot: slot });
            });
            var done = 0;
            status.textContent = 'در حال آپلود ' + jobs.length + ' فایل…';
            function next(lastTypes) {
                if (!jobs.length) {
                    status.textContent = 'آپلود گروهی تمام شد' + (skipped.length ? ' · نادیده گرفته شد (نام نامعتبر): ' + skipped.join('، ') : '') + '.';
                    mkBulk.value = '';
                    if (lastTypes) { mkRenderDefaults(lastTypes); } else { mkLoadDefaults(); }
                    return;
                }
                var j = jobs.shift();
                var fd = new FormData();
                fd.append('slug', j.slug);
                fd.append('slot', String(j.slot));
                fd.append('image', j.file);
                fetch('admin-images.php?mk_defaults=upload', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        done++;
                        status.textContent = 'آپلود ' + done + ' از ' + (done + jobs.length) + (d.success ? '' : ' · خطا: ' + (d.message || ''));
                        next(d.success ? d.types : null);
                    })
                    .catch(function () { done++; next(null); });
            }
            next(null);
        });
    }

    window.mkLoadDefaults = function () {
        fetch('admin-images.php?mk_defaults=state', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) throw new Error(d.message || 'خطا');
                mkRenderDefaults(d.types);
            })
            .catch(function (e) {
                document.getElementById('mkDefaultsHost').textContent = 'خطا در بارگذاری: ' + e.message;
            });
    };

    function mkRenderDefaults(types) {
        var host = document.getElementById('mkDefaultsHost');
        host.innerHTML = '';
        types.forEach(function (t) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; padding:10px 0; border-bottom:1px solid var(--border,#eee);';
            var label = document.createElement('div');
            label.style.cssText = 'width:110px; font-weight:800; padding-top:14px;';
            label.textContent = t.label;
            row.appendChild(label);
            for (var s = 1; s <= 5; s++) {
                (function (slot) {
                    var url = t.slots[String(slot)] || '';
                    var cell = document.createElement('div');
                    cell.style.cssText = 'text-align:center;';
                    var box = document.createElement('div');
                    box.style.cssText = 'width:104px; height:68px; border-radius:10px; overflow:hidden; border:2px dashed var(--border,#ccc); display:flex; align-items:center; justify-content:center; background:var(--surface,#fff); margin-bottom:6px;';
                    if (url) {
                        var img = document.createElement('img');
                        img.src = url + '?t=' + Date.now();
                        img.alt = t.label + ' ' + slot;
                        img.style.cssText = 'width:100%; height:100%; object-fit:cover; display:block;';
                        box.appendChild(img);
                        box.style.borderStyle = 'solid';
                    } else {
                        var plus = document.createElement('span');
                        plus.textContent = 'خالی';
                        plus.style.cssText = 'font-size:12px; opacity:.6;';
                        box.appendChild(plus);
                    }
                    cell.appendChild(box);
                    var up = document.createElement('input');
                    up.type = 'file';
                    up.accept = '.png,.jpg,.jpeg,.webp';
                    up.style.cssText = 'width:104px; font-size:11px;';
                    up.addEventListener('change', function () {
                        if (!up.files || !up.files[0]) return;
                        var fd = new FormData();
                        fd.append('slug', t.slug);
                        fd.append('slot', String(slot));
                        fd.append('image', up.files[0]);
                        fetch('admin-images.php?mk_defaults=upload', { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                if (!d.success) { alert(d.message || 'خطا در آپلود'); return; }
                                mkRenderDefaults(d.types);
                            })
                            .catch(function () { alert('خطا در آپلود'); });
                    });
                    cell.appendChild(up);
                    if (url) {
                        var del = document.createElement('button');
                        del.type = 'button';
                        del.textContent = 'حذف';
                        del.className = 'btn-secondary';
                        del.style.cssText = 'margin-top:4px; font-size:11px; padding:2px 10px;';
                        del.addEventListener('click', function () {
                            if (!confirm('عکس جایگاه ' + slot + ' از «' + t.label + '» حذف شود؟')) return;
                            var fd = new FormData();
                            fd.append('slug', t.slug);
                            fd.append('slot', String(slot));
                            fetch('admin-images.php?mk_defaults=delete', { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (d) { if (d.success) { mkRenderDefaults(d.types); } else { alert(d.message || 'خطا'); } })
                                .catch(function () { alert('خطا در حذف'); });
                        });
                        cell.appendChild(del);
                    }
                    row.appendChild(cell);
                })(s);
            }
            host.appendChild(row);
        });
    }
})();
</script>
