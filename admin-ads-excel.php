<?php require_once __DIR__ . '/config.php';
/**
 * خروجی / ورودی اکسل آگهی‌ها + تنظیم امتیاز ملکینو
 */
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/melkino-xlsx.php';
require_once __DIR__ . '/property-db-helper.php';
require_once __DIR__ . '/db-settings.php';

function melkinoExcelColumns(): array
{
    return [
        'کد آگهی' => 'id',
        'عنوان' => 'title',
        'وضعیت' => 'status',
        'نوع معامله' => 'transaction_type',
        'نوع ملک' => 'property_type',
        'موقعیت' => 'location',
        'آدرس' => 'address',
        'متراژ' => 'area',
        'متراژ زمین' => 'land_area',
        'زیربنا' => 'built_area',
        'اتاق' => 'rooms',
        'طبقه' => 'floor',
        'سال ساخت' => 'year',
        'قیمت فروش' => 'price_sell',
        'ودیعه' => 'deposit',
        'اجاره ماهانه' => 'rent_monthly',
        'رهن کامل' => 'full_rent',
        'وام دارد' => 'has_loan',
        'مبلغ وام' => 'loan_amount',
        'نوع سند' => 'deed_type',
        'کلید نخورده' => 'is_not_keyed',
        'مایل به معاوضه' => 'exchange_interested',
        'امکانات' => 'amenities',
        'توضیحات' => 'description',
        'نام خانوادگی' => 'last_name',
        'تلفن' => 'phone',
        'بازدید ملکینو' => 'melkino_visited',
        'امتیاز ملکینو' => 'melkino_rating',
        'نظر ملکینو' => 'melkino_review',
    ];
}

// melkinoEnsureRatingColumns نسخهٔ کانونی در db-settings.php است (همان فایل
// بالا require شده)؛ نسخهٔ تکراری این‌جا حذف شد چون fatal می‌داد.
function melkinoExcelYesNo($v): string
{
    $v = trim((string)$v);
    if ($v === '1' || strcasecmp($v, 'true') === 0 || $v === 'بله' || $v === 'آری') {
        return 'بله';
    }
    return ($v === '' || $v === '0') ? '' : ((int)$v ? 'بله' : 'خیر');
}

function melkinoExcelParseBool($v): int
{
    $v = trim(strtr((string)$v, ['۰'=>'0','۱'=>'1']));
    $low = mb_strtolower($v);
    if ($v === '1' || $low === 'true' || $v === 'بله' || $v === 'آری' || $low === 'yes') {
        return 1;
    }
    return 0;
}

function melkinoRatingEnabled(?PDO $pdo): bool
{
    if (!($pdo instanceof PDO) || !function_exists('dbSettingGet')) {
        return false;
    }
    return (bool)dbSettingGet($pdo, 'global', 'melkino_rating_enabled', false);
}

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
if ($action === '') {
    http_response_code(400);
    echo 'عمل نامعتبر';
    exit;
}

melkinoRequireAdminJson();
melkinoRequirePostFor(['import', 'rating_toggle', 'rating_save'], $action);
global $pdo;
melkinoEnsureRatingColumns($pdo instanceof PDO ? $pdo : null);

$cols = melkinoExcelColumns();
$headers = array_keys($cols);

if ($action === 'template' || $action === 'export') {
    $rows = [];
    if ($action === 'template') {
        $sample = array_fill(0, count($headers), '');
        $sample[array_search('عنوان', $headers, true)] = 'آپارتمان نمونه ۹۰ متری';
        $sample[array_search('وضعیت', $headers, true)] = 'published';
        $sample[array_search('نوع معامله', $headers, true)] = 'فروش';
        $sample[array_search('نوع ملک', $headers, true)] = 'آپارتمان';
        $sample[array_search('موقعیت', $headers, true)] = 'شاهرود';
        $sample[array_search('متراژ', $headers, true)] = '90';
        $sample[array_search('اتاق', $headers, true)] = '2';
        $sample[array_search('قیمت فروش', $headers, true)] = '3500000000';
        $sample[array_search('امکانات', $headers, true)] = 'پارکینگ، آسانسور';
        $rows[] = $sample;
    } elseif ($pdo instanceof PDO) {
        $ads = $pdo->query('SELECT * FROM ads ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $amenBy = [];
        try {
            $am = $pdo->query('SELECT aa.ad_id, am.name FROM ad_amenities aa INNER JOIN amenities am ON am.id=aa.amenity_id')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($am as $row) {
                $amenBy[(string)$row['ad_id']][] = $row['name'];
            }
        } catch (Throwable $e) {
            $amenBy = [];
        }
        foreach ($ads as $ad) {
            $line = [];
            foreach ($cols as $label => $key) {
                if ($key === 'amenities') {
                    $line[] = implode('، ', $amenBy[(string)$ad['id']] ?? []);
                } elseif (in_array($key, ['has_loan', 'is_not_keyed', 'exchange_interested', 'melkino_visited'], true)) {
                    $line[] = !empty($ad[$key]) ? 'بله' : '';
                } else {
                    $line[] = (string)($ad[$key] ?? '');
                }
            }
            $rows[] = $line;
        }
    }
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $dir . '/melkino-ads-' . bin2hex(random_bytes(4)) . '.xlsx';
    $ok = melkinoXlsxWrite($tmp, $headers, $rows, $action === 'template' ? 'نمونه' : 'آگهی‌ها');
    if (!$ok || !is_file($tmp) || filesize($tmp) < 100) {
        @unlink($tmp);
        $tmp = sys_get_temp_dir() . '/melkino-ads-' . bin2hex(random_bytes(4)) . '.xlsx';
        $ok = melkinoXlsxWrite($tmp, $headers, $rows, $action === 'template' ? 'نمونه' : 'آگهی‌ها');
    }
    if (!$ok || !is_file($tmp) || filesize($tmp) < 100) {
        melkinoAdminJson(['success' => false, 'message' => 'ساخت فایل اکسل ممکن نشد. پوشه backups باید قابل نوشتن باشد.'], 500);
    }
    $name = $action === 'template' ? 'melkino-ads-template.xlsx' : ('melkino-ads-' . date('Ymd-His') . '.xlsx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

if ($action === 'import') {
    if (!($pdo instanceof PDO)) {
        melkinoAdminJson(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
    }
    $file = $_FILES['excel_file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        melkinoAdminJson(['success' => false, 'message' => 'فایل اکسل انتخاب نشده یا آپلود ناموفق بود.'], 422);
    }
    $tmp = (string)$file['tmp_name'];
    $parsed = melkinoXlsxRead($tmp);
    if (!$parsed['rows']) {
        melkinoAdminJson(['success' => false, 'message' => 'ردیف معتبری در اکسل پیدا نشد. از فایل نمونه استفاده کنید.'], 422);
    }
    $created = 0;
    $updated = 0;
    $errors = [];
    $ids = [];
    $labelToKey = $cols;
    foreach ($parsed['rows'] as $i => $row) {
        $mapped = [];
        foreach ($row as $label => $val) {
            $key = $labelToKey[$label] ?? null;
            if ($key) {
                $mapped[$key] = $val;
            }
        }
        $title = trim((string)($mapped['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'ردیف ' . ($i + 2) . ': عنوان خالی است.';
            continue;
        }
        $status = trim((string)($mapped['status'] ?? 'published'));
        if ($status === 'منتشر شده' || $status === '') {
            $status = 'published';
        } elseif ($status === 'در انتظار') {
            $status = 'pending';
        }
        $amenRaw = (string)($mapped['amenities'] ?? '');
        $amenities = array_values(array_filter(array_map('trim', preg_split('/[,،;]+/u', $amenRaw) ?: [])));
        $newAd = [
            'title' => $title,
            'status' => $status,
            'transaction_type' => $mapped['transaction_type'] ?? '',
            'property_type' => $mapped['property_type'] ?? '',
            'propertyType' => $mapped['property_type'] ?? '',
            'transactionType' => $mapped['transaction_type'] ?? '',
            'location' => $mapped['location'] ?? '',
            'address' => $mapped['address'] ?? '',
            'area' => $mapped['area'] ?? '',
            'land_area' => $mapped['land_area'] ?? '',
            'built_area' => $mapped['built_area'] ?? '',
            'rooms' => $mapped['rooms'] ?? '',
            'floor' => $mapped['floor'] ?? '',
            'year' => $mapped['year'] ?? '',
            'price_sell' => $mapped['price_sell'] ?? '',
            'deposit' => $mapped['deposit'] ?? '',
            'rent_monthly' => $mapped['rent_monthly'] ?? '',
            'full_rent' => $mapped['full_rent'] ?? '',
            'full_rent_enabled' => trim((string)($mapped['full_rent'] ?? '')) !== '' ? 1 : 0,
            'has_loan' => melkinoExcelParseBool($mapped['has_loan'] ?? ''),
            'loan_amount' => $mapped['loan_amount'] ?? '',
            'deed_type' => $mapped['deed_type'] ?? '',
            'is_not_keyed' => melkinoExcelParseBool($mapped['is_not_keyed'] ?? ''),
            'exchange_interested' => melkinoExcelParseBool($mapped['exchange_interested'] ?? ''),
            'description' => $mapped['description'] ?? '',
            'last_name' => $mapped['last_name'] ?? '',
            'phone' => $mapped['phone'] ?? '',
            'amenities' => $amenities,
        ];
        $givenId = trim((string)($mapped['id'] ?? ''));
        $exists = false;
        if ($givenId !== '') {
            $chk = $pdo->prepare('SELECT id FROM ads WHERE id = ? LIMIT 1');
            $chk->execute([$givenId]);
            $exists = (bool)$chk->fetchColumn();
        }
        try {
            if ($exists) {
                $pdo->prepare(
                    'UPDATE ads SET title=?, status=?, transaction_type=?, property_type=?, location=?, address=?,
                     area=?, land_area=?, built_area=?, rooms=?, floor=?, year=?,
                     price_sell=?, deposit=?, rent_monthly=?, full_rent=?, full_rent_enabled=?,
                     has_loan=?, loan_amount=?, deed_type=?, is_not_keyed=?, exchange_interested=?,
                     description=?, last_name=?, phone=?,
                     melkino_visited=?, melkino_rating=?, melkino_review=?
                     WHERE id=?'
                )->execute([
                    $newAd['title'], $status, $newAd['transaction_type'] ?: null, $newAd['property_type'] ?: null,
                    $newAd['location'] ?: null, $newAd['address'] ?: null,
                    $newAd['area'] ?: null, $newAd['land_area'] ?: null, $newAd['built_area'] ?: null,
                    $newAd['rooms'] ?: null, $newAd['floor'] ?: null, $newAd['year'] ?: null,
                    $newAd['price_sell'] ?: null, $newAd['deposit'] ?: null, $newAd['rent_monthly'] ?: null,
                    $newAd['full_rent'] ?: null, $newAd['full_rent_enabled'],
                    $newAd['has_loan'], $newAd['loan_amount'] ?: null, $newAd['deed_type'] ?: null,
                    $newAd['is_not_keyed'], $newAd['exchange_interested'],
                    $newAd['description'] ?: null, $newAd['last_name'] ?: null, $newAd['phone'] ?: null,
                    melkinoExcelParseBool($mapped['melkino_visited'] ?? ''),
                    ($mapped['melkino_rating'] ?? '') !== '' ? (float)strtr($mapped['melkino_rating'], ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5']) : null,
                    trim((string)($mapped['melkino_review'] ?? '')) ?: null,
                    $givenId,
                ]);
                if ($amenities) {
                    $pdo->prepare('DELETE FROM ad_amenities WHERE ad_id=?')->execute([$givenId]);
                    foreach ($amenities as $name) {
                        $aid = melkino_amenity_id($pdo, $name, (string)$newAd['property_type']);
                        if ($aid) {
                            $pdo->prepare('INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?,?)')->execute([$givenId, $aid]);
                        }
                    }
                }
                $updated++;
                $ids[] = $givenId;
            } else {
                $newAd['id'] = $givenId !== '' ? $givenId : '';
                $res = savePropertyToDatabase($pdo, $newAd);
                if (empty($res['success'])) {
                    $errors[] = 'ردیف ' . ($i + 2) . ': ' . (string)($res['error'] ?? 'ثبت نشد');
                    continue;
                }
                $id = (string)$res['id'];
                $rating = trim((string)($mapped['melkino_rating'] ?? ''));
                $pdo->prepare('UPDATE ads SET status=?, melkino_visited=?, melkino_rating=?, melkino_review=? WHERE id=?')->execute([
                    $status,
                    melkinoExcelParseBool($mapped['melkino_visited'] ?? ''),
                    $rating !== '' ? (float)strtr($rating, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5']) : null,
                    trim((string)($mapped['melkino_review'] ?? '')) ?: null,
                    $id,
                ]);
                $created++;
                $ids[] = $id;
            }
        } catch (Throwable $e) {
            $errors[] = 'ردیف ' . ($i + 2) . ': ' . $e->getMessage();
        }
    }
    melkinoAdminJson([
        'success' => ($created + $updated) > 0,
        'message' => ($created + $updated) . ' آگهی از اکسل ثبت شد (' . $created . ' جدید، ' . $updated . ' به‌روز).'
            . ($ids ? ' کدها: ' . implode('، ', array_slice($ids, 0, 8)) . (count($ids) > 8 ? '…' : '') : ''),
        'created' => $created,
        'updated' => $updated,
        'ids' => $ids,
        'errors' => array_slice($errors, 0, 8),
    ]);
}

if ($action === 'rating_state') {
    $enabled = melkinoRatingEnabled($pdo instanceof PDO ? $pdo : null);
    $ads = [];
    if ($pdo instanceof PDO) {
        try {
            $ads = $pdo->query(
                "SELECT id, title, status, location, melkino_visited, melkino_rating, melkino_review
                 FROM ads ORDER BY created_at DESC LIMIT 80"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $ads = [];
        }
    }
    melkinoAdminJson(['success' => true, 'enabled' => $enabled, 'ads' => $ads]);
}

if ($action === 'rating_toggle') {
    $data = melkinoAdminJsonBody();
    $on = !empty($data['enabled']);
    if (!($pdo instanceof PDO) || !function_exists('dbSettingSet')) {
        melkinoAdminJson(['success' => false, 'message' => 'ذخیره تنظیمات ممکن نیست.'], 500);
    }
    dbSettingSet($pdo, 'global', 'melkino_rating_enabled', $on ? 'true' : 'false', 'boolean');
    melkinoAdminJson([
        'success' => true,
        'enabled' => $on,
        'message' => $on ? 'امتیاز ملکینو روی کارت و صفحهٔ جزئیات فعال شد.' : 'امتیاز ملکینو فعلاً خاموش است.',
    ]);
}

if ($action === 'rating_save') {
    if (!($pdo instanceof PDO)) {
        melkinoAdminJson(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست.'], 500);
    }
    $data = melkinoAdminJsonBody();
    $id = trim((string)($data['id'] ?? ''));
    if ($id === '') {
        melkinoAdminJson(['success' => false, 'message' => 'کد آگهی خالی است.'], 422);
    }
    $visited = !empty($data['visited']) ? 1 : 0;
    $rating = isset($data['rating']) ? (float)$data['rating'] : 0;
    if ($rating < 0) {
        $rating = 0;
    }
    if ($rating > 5) {
        $rating = 5;
    }
    $review = trim((string)($data['review'] ?? ''));
    $pdo->prepare('UPDATE ads SET melkino_visited=?, melkino_rating=?, melkino_review=? WHERE id=?')->execute([
        $visited,
        $rating > 0 ? $rating : null,
        $review !== '' ? $review : null,
        $id,
    ]);
    melkinoAdminJson(['success' => true, 'message' => 'امتیاز و نظر ذخیره شد.']);
}

melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
