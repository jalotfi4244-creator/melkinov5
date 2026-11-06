<?php
/**
 * Shared database writer for Melkino property registration forms.
 * Source of truth: MySQL. JSON files are no longer used for saving new ads.
 */

if (!function_exists('melkino_normalize_digits')) {
    function melkino_normalize_digits($value): string {
        $value = (string)$value;
        $map = [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ];
        return strtr($value, $map);
    }
}

if (!function_exists('melkino_numeric')) {
    function melkino_numeric($value, ?float $default = null): ?float {
        if ($value === null) return $default;
        if (is_array($value) || is_object($value)) return $default;
        $value = trim(melkino_normalize_digits($value));
        if ($value === '') return $default;
        // راند ۲۷: یکسان با melkinoPriceToNum — ممیز عربی ٫ → نقطه،
        // کامای اعشاری (بدون نقطه، با ۱-۲ رقم اعشار) → نقطه، بقیهٔ
        // کاماها/٬ جداکنندهٔ هزارگان‌اند و حذف می‌شوند.
        $value = str_replace(['٬', ' '], '', $value);
        $value = str_replace('٫', '.', $value);
        if (strpos($value, '.') === false && preg_match('/^([+-]?\d+),(\d{1,2})$/', $value)) {
            $value = str_replace(',', '.', $value);
        }
        $value = str_replace(',', '', $value);
        if (!is_numeric($value)) return $default;
        return (float)$value;
    }
}

if (!function_exists('melkino_integer')) {
    function melkino_integer($value, ?int $default = null): ?int {
        $n = melkino_numeric($value, null);
        return $n === null ? $default : (int)$n;
    }
}

if (!function_exists('melkino_ad_id')) {
    function melkino_ad_id(PDO $pdo): string {
        do {
            $id = 'AD-' . date('Ymd') . '-' . random_int(1000, 9999);
            $stmt = $pdo->prepare('SELECT 1 FROM ads WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
        } while ($stmt->fetchColumn());
        return $id;
    }
}

if (!function_exists('melkino_amenity_id')) {
    function melkino_amenity_id(PDO $pdo, string $name, string $category): ?int {
        $name = trim($name);
        if ($name === '') return null;

        $stmt = $pdo->prepare('SELECT id FROM amenities WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) return (int)$existing;

        try {
            $insert = $pdo->prepare(
                'INSERT INTO amenities (name, is_active, sort_order) VALUES (?, 1, 0)'
            );
            $insert->execute([$name]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            $stmt = $pdo->prepare('SELECT id FROM amenities WHERE name = ? LIMIT 1');
            $stmt->execute([$name]);
            $existing = $stmt->fetchColumn();
            return $existing !== false ? (int)$existing : null;
        }
    }
}

if (!function_exists('melkino_extract_normalized_fields')) {
    function melkino_extract_normalized_fields(array $newAd): array {
        $propertyType = trim((string)($newAd['propertyType'] ?? $newAd['property_type'] ?? ''));
        $transactionType = trim((string)($newAd['transactionType'] ?? $newAd['transaction_type'] ?? ''));

        $area = melkino_numeric($newAd['area'] ?? null);
        $landArea = melkino_numeric($newAd['land_area'] ?? null);
        $builtArea = melkino_numeric($newAd['built_area'] ?? null);

        if ($propertyType === 'آپارتمان') {
            $area = $area ?? melkino_numeric($newAd['area_apt'] ?? null);
            $builtArea = $builtArea ?? $area;
        } elseif ($propertyType === 'ویلایی' || $propertyType === 'ویلا' || mb_strpos($propertyType, 'ویلا') !== false) {
            $landArea = $landArea ?? melkino_numeric($newAd['land_villa'] ?? $newAd['land_area'] ?? null);
            $builtArea = $builtArea ?? melkino_numeric($newAd['built_villa'] ?? $newAd['built_area'] ?? null);
            // متراژ زمین ≠ زیربنا: area برای کارت = زمین، built_area = زیربنا
            if ($builtArea === null && $area !== null && $landArea !== null && (string)$area !== (string)$landArea) {
                $builtArea = $area;
            }
            $area = $landArea ?? $area;
        } elseif ($propertyType === 'زمین') {
            $landArea = $landArea ?? melkino_numeric($newAd['land_area'] ?? null);
            $area = $landArea;
        } elseif ($propertyType === 'باغ') {
            $landArea = $landArea ?? melkino_numeric($newAd['garden_area'] ?? null);
            $area = $landArea;
        } elseif ($propertyType === 'اداری') {
            $area = $area ?? melkino_numeric($newAd['office_area'] ?? null);
            $builtArea = $builtArea ?? $area;
        } elseif ($propertyType === 'تجاری') {
            $area = $area ?? melkino_numeric($newAd['area_comm'] ?? null);
            $builtArea = $builtArea ?? $area;
        }

        $rooms = melkino_integer($newAd['rooms'] ?? null);
        if ($rooms === null) {
            $rooms = melkino_integer($newAd['rooms_apt'] ?? $newAd['rooms_villa'] ?? $newAd['office_rooms'] ?? null);
        }

        $floor = $newAd['floor'] ?? $newAd['office_floor'] ?? null;
        if (is_array($floor)) $floor = null;
        $floor = trim((string)($floor ?? ''));

        $year = melkino_integer($newAd['year'] ?? $newAd['year_apt'] ?? $newAd['year_villa'] ?? $newAd['office_year'] ?? null);

        return [
            'transaction_type' => $transactionType !== '' ? $transactionType : null,
            'property_type' => $propertyType !== '' ? $propertyType : null,
            'area' => $area,
            'land_area' => $landArea,
            'built_area' => $builtArea,
            'rooms' => $rooms,
            'floor' => $floor !== '' ? $floor : null,
            'year' => $year,
        ];
    }
}

if (!function_exists('savePropertyToDatabase')) {
    /**
     * @return array{success:bool,id?:string,error?:string}
     */
    function savePropertyToDatabase(PDO $pdo, array $newAd): array {
        $id = trim((string)($newAd['id'] ?? ''));
        if ($id === '') $id = melkino_ad_id($pdo);

        $norm = melkino_extract_normalized_fields($newAd);
        $transactionType = $norm['transaction_type'] ?? null;

        // راند ۳۰: تضمین ستون created_by_telegram_id — بیرون از تراکنش،
        // چون DDL در MySQL تراکنش را implicitly commit می‌کند.
        static $r30HasTgCol = null;
        if ($r30HasTgCol === null) {
            $r30HasTgCol = false;
            try {
                $col = $pdo->query("SHOW COLUMNS FROM ads LIKE 'created_by_telegram_id'")->fetch();
                if (!$col) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN created_by_telegram_id VARCHAR(30) NULL");
                }
                $r30HasTgCol = true;
            } catch (Throwable $e) {
                $r30HasTgCol = false;
            }
        }

        // شماره تماس دوم: ستون اختیاری؛ اگر نبود یا ساخته نشد، ذخیره‌ی آن رد می‌شود و ثبت آگهی شکست نمی‌خورد
        static $phone2HasCol = null;
        if ($phone2HasCol === null) {
            $phone2HasCol = false;
            try {
                $c2 = $pdo->query("SHOW COLUMNS FROM ads LIKE 'phone2'")->fetch();
                if (!$c2) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN phone2 VARCHAR(20) NULL");
                }
                $phone2HasCol = true;
            } catch (Throwable $e) {
                $phone2HasCol = false;
            }
        }
        $phone2Val = trim((string)($newAd['phone2'] ?? ''));
        $phone2Val = ($phone2Val !== '') ? $phone2Val : null;

        $priceSell = melkino_numeric($newAd['price_sell'] ?? null, 0);
        $deposit = melkino_numeric($newAd['deposit'] ?? null, 0);
        $rentMonthly = melkino_numeric($newAd['rent_monthly'] ?? null, 0);
        $fullRent = melkino_numeric($newAd['full_rent'] ?? null, 0);
        $fullRentEnabled = !empty($newAd['full_rent_enabled']) ? 1 : 0;
        $totalPrice = melkino_numeric($newAd['total_price'] ?? null, 0);
        $downPayment = melkino_numeric($newAd['down_payment'] ?? null, 0);
        $displayPrice = melkino_numeric($newAd['display_price'] ?? null, 0);

        // وام: فقط وقتی تیک خورده باشد ذخیره می‌شود
        $hasLoan = !empty($newAd['has_loan']) ? 1 : 0;
        $loanAmount = melkino_numeric($newAd['loan_amount'] ?? null, 0) ?: 0;
        $loanInstallment = melkino_numeric($newAd['loan_installment'] ?? null, 0) ?: 0;
        if ($hasLoan && $loanAmount <= 0) {
            $hasLoan = 0; // تیک خورده ولی مبلغی وارد نشده — چیزی ذخیره نکن
        }

        if (is_file(__DIR__ . '/jalali-lib.php')) {
            require_once __DIR__ . '/jalali-lib.php';
        }
        if (function_exists('melkinoEnsureAdsBuildingAgeColumn')) {
            melkinoEnsureAdsBuildingAgeColumn($pdo);
        }
        $ageVal = null;
        if (function_exists('melkinoBuildingAge')) {
            $ageVal = melkinoBuildingAge($norm['year'] ?? $newAd['year'] ?? $newAd['office_year'] ?? '');
            if ($ageVal !== null) {
                $newAd['building_age'] = $ageVal;
            }
        }

        $details = $newAd;
        foreach ([
            'id','title','location','address','location_received','status','tags','gender','last_name','phone',
            'propertyType','property_type','transactionType','transaction_type','description','images','selectedImages',
            'publish_photos','created_at','price_sell','price_condition','deposit','rent_monthly','full_rent_enabled',
            'full_rent','total_price','down_payment','payment_terms','display_price','amenities',
            'is_not_keyed','delivery_date','vacancy_date','is_vacant','exchange_interested','exchange_with','visit_hours',
            'is_old','is_renovated','water_share','well_name',
            'has_loan','loan_amount','loan_type','loan_duration','loan_bank','loan_installment',
            'loan_installments_paid','loan_notes','deed_type','deed_notes','exchange_types',
            'telegram_id','owner_user_id','phone2',
            'latitude','longitude','location_source','location_accuracy','map_lat','map_lng','map_source','map_accuracy'
        ] as $remove) {
            unset($details[$remove]);
        }

        $tags = $newAd['tags'] ?? [];
        if (!is_array($tags)) $tags = [$tags];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ads (
                    id, title, status, transaction_type, property_type, gender, last_name, phone,
                    location, address, location_received, area, land_area, built_area, rooms, floor, year,
                    price_sell, price_condition, deposit, rent_monthly, full_rent_enabled, full_rent,
                    total_price, down_payment, payment_terms, display_price, description, publish_photos,
                    price_hidden, tags, property_details, custom_fields, created_at,
                    is_not_keyed, delivery_date, vacancy_date, is_vacant, exchange_interested, exchange_with, visit_hours,
                    is_old, is_renovated, water_share, well_name,
                    has_loan, loan_amount, loan_type, loan_duration, loan_bank,
                    loan_installment, loan_installments_paid, loan_notes,
                    deed_type, deed_notes, exchange_types
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?
                )'
            );

            $stmt->execute([
                $id,
                function_exists('melkinoCleanAdTitle') ? melkinoCleanAdTitle((string)($newAd['title'] ?? ''), '') : trim((string)($newAd['title'] ?? '')),
                trim((string)($newAd['status'] ?? 'pending')) ?: 'pending',
                $norm['transaction_type'],
                $norm['property_type'],
                trim((string)($newAd['gender'] ?? '')) ?: null,
                trim((string)($newAd['last_name'] ?? '')) ?: null,
                trim((string)($newAd['phone'] ?? '')) ?: null,
                trim((string)($newAd['location'] ?? '')) ?: null,
                trim((string)($newAd['address'] ?? '')) ?: null,
                trim((string)($newAd['location_received'] ?? '0')),
                $norm['area'],
                $norm['land_area'],
                $norm['built_area'],
                $norm['rooms'],
                $norm['floor'],
                $norm['year'],
                $priceSell,
                trim((string)($newAd['price_condition'] ?? '')) ?: null,
                $deposit,
                $rentMonthly,
                $fullRentEnabled,
                $fullRent,
                $totalPrice,
                $downPayment,
                trim((string)($newAd['payment_terms'] ?? '')) ?: null,
                $displayPrice,
                trim((string)($newAd['description'] ?? '')) ?: null,
                trim((string)($newAd['publish_photos'] ?? 'yes')),
                !empty($newAd['price_hidden']) ? 1 : 0,
                json_encode(array_values($tags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode([], JSON_UNESCAPED_UNICODE),
                !empty($newAd['created_at']) ? $newAd['created_at'] : date('Y-m-d H:i:s'),
                // فیلدهای جدید:
                !empty($newAd['is_not_keyed']) ? 1 : 0,
                !empty($newAd['delivery_date']) ? $newAd['delivery_date'] : null,
                !empty($newAd['vacancy_date']) ? $newAd['vacancy_date'] : null,
                !empty($newAd['is_vacant']) ? 1 : 0,
                !empty($newAd['exchange_interested']) ? 1 : 0,
                !empty($newAd['exchange_with']) ? (string)$newAd['exchange_with'] : null,
                !empty($newAd['visit_hours']) ? (string)$newAd['visit_hours'] : null,
                !empty($newAd['is_old']) ? 1 : 0,
                !empty($newAd['is_renovated']) ? 1 : 0,
                !empty($newAd['water_share']) ? (string)$newAd['water_share'] : null,
                !empty($newAd['well_name']) ? (string)$newAd['well_name'] : null,
                // فیلدهای وام (فقط فروش/پیش‌فروش)
                $hasLoan,
                $hasLoan ? (string)$loanAmount : null,
                $hasLoan ? (trim((string)($newAd['loan_type'] ?? '')) ?: null) : null,
                $hasLoan ? (trim((string)($newAd['loan_duration'] ?? '')) ?: null) : null,
                $hasLoan ? (trim((string)($newAd['loan_bank'] ?? '')) ?: null) : null,
                $hasLoan ? (string)$loanInstallment : null,
                $hasLoan ? (trim((string)($newAd['loan_installments_paid'] ?? '')) ?: null) : null,
                $hasLoan ? (trim((string)($newAd['loan_notes'] ?? '')) ?: null) : null,
                // سند و معاوضه
                trim((string)($newAd['deed_type'] ?? '')) ?: null,
                trim((string)($newAd['deed_notes'] ?? '')) ?: null,
                trim((string)($newAd['exchange_types'] ?? '')) ?: null,
            ]);

            $images = $newAd['selectedImages'] ?? $newAd['images'] ?? [];
            if (is_string($images)) {
                $images = array_values(array_filter(array_map('trim', explode(',', $images))));
            }
            if (!is_array($images)) $images = [];

            $insertImage = $pdo->prepare(
                'INSERT INTO images (ad_id, filename, storage_path, sort_order, is_selected, is_primary, publish_publicly)
                 VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            $publicImages = strtolower(trim((string)($newAd['publish_photos'] ?? 'yes'))) === 'yes' ? 1 : 0;
            foreach (array_values($images) as $index => $filename) {
                $filename = trim((string)$filename);
                if ($filename === '') continue;
                $insertImage->execute([$id, $filename, $filename, $index, $index === 0 ? 1 : 0, $publicImages]);
            }

            $amenities = $newAd['amenities'] ?? [];
            if (!is_array($amenities)) $amenities = [$amenities];
            $propertyCategory = $norm['property_type'] ?? 'ملک';
            $linkAmenity = $pdo->prepare('INSERT IGNORE INTO ad_amenities (ad_id, amenity_id) VALUES (?, ?)');
            foreach ($amenities as $amenity) {
                if (is_array($amenity)) continue;
                $amenityId = melkino_amenity_id($pdo, (string)$amenity, $propertyCategory);
                if ($amenityId !== null) $linkAmenity->execute([$id, $amenityId]);
            }

            // راند ۳۰: اتصال قطعی آگهی به ثبت‌کننده.
            // owner_user_id از سشن تأییدشدهٔ سرور می‌آید (فرم ثبت آن را
            // پر می‌کند)؛ اگر نبود، از telegram_id در جدول users پیدا
            // می‌شود. created_by_telegram_id هویت تلگرام ثبت‌کننده را
            // روی خود رکورد ملک نگه می‌دارد.
            $telegramId = trim((string)($newAd['telegram_id'] ?? ''));
            $ownerUserId = (int)($newAd['owner_user_id'] ?? 0);
            if ($ownerUserId <= 0 && $telegramId !== '') {
                $userStmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ? LIMIT 1');
                $userStmt->execute([$telegramId]);
                $foundUserId = $userStmt->fetchColumn();
                if ($foundUserId !== false) {
                    $ownerUserId = (int)$foundUserId;
                }
            }
            try {
                $tgVal = $telegramId !== '' ? $telegramId : null;
                if ($ownerUserId > 0 && $r30HasTgCol) {
                    $pdo->prepare('UPDATE ads SET owner_user_id = ?, created_by_telegram_id = ?, telegram_id = ? WHERE id = ?')
                        ->execute([$ownerUserId, $tgVal, $tgVal, $id]);
                } elseif ($ownerUserId > 0) {
                    $pdo->prepare('UPDATE ads SET owner_user_id = ?, telegram_id = ? WHERE id = ?')->execute([$ownerUserId, $tgVal, $id]);
                } elseif ($telegramId !== '' && $r30HasTgCol) {
                    $pdo->prepare('UPDATE ads SET created_by_telegram_id = ?, telegram_id = ? WHERE id = ?')->execute([$tgVal, $tgVal, $id]);
                } elseif ($telegramId !== '') {
                    $pdo->prepare('UPDATE ads SET telegram_id = ? WHERE id = ?')->execute([$tgVal, $id]);
                }
            } catch (Throwable $e) {
                // شکستِ اتصال نباید ثبت آگهی را عقب بزند
            }
            if ($ageVal !== null) {
                try {
                    $pdo->prepare('UPDATE ads SET building_age = ? WHERE id = ?')->execute([$ageVal, $id]);
                } catch (Throwable $e) {
                }
            }

            $pdo->commit();
            if ($phone2HasCol && $phone2Val !== null) {
                try {
                    $pdo->prepare('UPDATE ads SET phone2 = ? WHERE id = ?')->execute([$phone2Val, $id]);
                } catch (Throwable $e) { /* ignore: phone2 is optional */ }
            }

            try {
                if (is_file(__DIR__ . '/map-lib.php')) {
                    require_once __DIR__ . '/map-lib.php';
                }
                if (function_exists('melkinoMapSaveCoords')) {
                    $lat = $newAd['latitude'] ?? null;
                    $lng = $newAd['longitude'] ?? null;
                    if ($lat && $lng) {
                        melkinoMapSaveCoords($pdo, $id, [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'location_source' => $newAd['location_source'] ?? 'map',
                            'location_accuracy' => $newAd['location_accuracy'] ?? '',
                            'location_received' => '1',
                        ], ['user_id' => (int)($newAd['owner_user_id'] ?? 0)]);
                    }
                }
            } catch (Throwable $e) {
            }

            // اعلان «آگهی شما ثبت شد» برای ثبت‌کننده.
            // بی‌صدا انجام می‌شود تا شکستش روند ثبت را خراب نکند.
            try {
                if (!function_exists('melkinoNotifyByPhone')) {
                    require_once __DIR__ . '/db_helpers.php';
                }
                if (function_exists('melkinoNotifyByPhone')) {
                    $ownerPhone = trim((string)($newAd['phone'] ?? ''));
                    $adTitle = trim((string)($newAd['title'] ?? ''));
                    if ($ownerPhone !== '') {
                        melkinoNotifyByPhone(
                            $ownerPhone,
                            'ad_submitted',
                            '📝 آگهی شما ثبت شد',
                            'آگهی «' . ($adTitle !== '' ? $adTitle : $id) . '» با موفقیت ثبت شد و پس از بررسی کارشناسان منتشر می‌شود. کد پیگیری: ' . $id,
                            'my-properties.php',
                            $id
                        );
                    }
                }
            } catch (Throwable $e) {
                // ignore — notification must never break registration
            }

            return ['success' => true, 'id' => $id];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}