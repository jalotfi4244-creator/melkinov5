<?php
/**
 * ضرایب ماشین‌حساب قیمت‌گذاری ملکینو.
 * منبع واحد برای موتور JS، صفحهٔ محاسبه و تب عمومی پنل ادمین.
 */
if (!function_exists('melkinoCalcDefaultRates')) {
    function melkinoCalcDefaultRates(): array
    {
        return [
            'ESTEHKLAK_RATE' => 0.015,
            'VAGHFI_DISCOUNT' => 0.20,
            'NO_PARKING_DISCOUNT_DEFAULT' => 0.09,
            'NO_PARKING_DISCOUNT_MIN' => 0.08,
            'NO_PARKING_DISCOUNT_MAX' => 0.10,
            'NO_ELEVATOR_RATE' => 0.025,
            'YARD_RATIO' => 1 / 3,
            'FULL_RENT_DIVISOR' => 8,
            'RENT_PER_100M' => 3000000,
            'RENT_BASE' => 100000000,
            'EXTRAS' => [
                [
                    'id' => 'storage',
                    'label' => 'انباری',
                    'enabled' => true,
                    'mode' => 'area_ratio',
                    'ratio' => 0.5,
                ],
            ],
        ];
    }
}

if (!function_exists('melkinoSanitizeCalcExtra')) {
    function melkinoSanitizeCalcExtra($row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $label = trim((string)($row['label'] ?? ''));
        if ($label === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            $label = mb_substr($label, 0, 40);
        } else {
            $label = substr($label, 0, 40);
        }
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $id)) {
            $id = 'x' . substr(sha1($label), 0, 10);
        }
        $mode = (string)($row['mode'] ?? 'area_ratio');
        if (!in_array($mode, ['area_ratio', 'percent_add', 'percent_cut'], true)) {
            $mode = 'area_ratio';
        }
        $ratio = isset($row['ratio']) && is_numeric($row['ratio']) ? (float)$row['ratio'] : 0.0;
        if ($ratio < 0) {
            $ratio = 0.0;
        }
        if ($ratio > 2) {
            $ratio = 2.0;
        }
        $enabled = $row['enabled'] ?? true;
        if (is_string($enabled)) {
            $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
        }
        return [
            'id' => $id,
            'label' => $label,
            'enabled' => (bool)$enabled,
            'mode' => $mode,
            'ratio' => $ratio,
        ];
    }
}

if (!function_exists('melkinoSanitizeCalcRates')) {
    function melkinoSanitizeCalcRates($over): array
    {
        $defaults = melkinoCalcDefaultRates();
        if (!is_array($over)) {
            return $defaults;
        }
        foreach ($defaults as $k => $v) {
            if ($k === 'EXTRAS') {
                continue;
            }
            if (!array_key_exists($k, $over) || !is_numeric($over[$k])) {
                continue;
            }
            $n = (float)$over[$k];
            if (is_nan($n) || is_infinite($n)) {
                continue;
            }
            if ($k === 'FULL_RENT_DIVISOR') {
                if ($n < 0.000001) {
                    continue;
                }
                $defaults[$k] = $n;
                continue;
            }
            if ($k === 'RENT_PER_100M' || $k === 'RENT_BASE') {
                if ($n < 0) {
                    continue;
                }
                $defaults[$k] = $n;
                continue;
            }
            if ($n < 0) {
                $n = 0.0;
            }
            if ($n > 1 && in_array($k, [
                'ESTEHKLAK_RATE',
                'VAGHFI_DISCOUNT',
                'NO_PARKING_DISCOUNT_DEFAULT',
                'NO_PARKING_DISCOUNT_MIN',
                'NO_PARKING_DISCOUNT_MAX',
                'NO_ELEVATOR_RATE',
                'YARD_RATIO',
            ], true)) {
                $n = 1.0;
            }
            $defaults[$k] = $n;
        }
        if ($defaults['NO_PARKING_DISCOUNT_MIN'] > $defaults['NO_PARKING_DISCOUNT_MAX']) {
            $tmp = $defaults['NO_PARKING_DISCOUNT_MIN'];
            $defaults['NO_PARKING_DISCOUNT_MIN'] = $defaults['NO_PARKING_DISCOUNT_MAX'];
            $defaults['NO_PARKING_DISCOUNT_MAX'] = $tmp;
        }
        if ($defaults['NO_PARKING_DISCOUNT_DEFAULT'] < $defaults['NO_PARKING_DISCOUNT_MIN']) {
            $defaults['NO_PARKING_DISCOUNT_DEFAULT'] = $defaults['NO_PARKING_DISCOUNT_MIN'];
        }
        if ($defaults['NO_PARKING_DISCOUNT_DEFAULT'] > $defaults['NO_PARKING_DISCOUNT_MAX']) {
            $defaults['NO_PARKING_DISCOUNT_DEFAULT'] = $defaults['NO_PARKING_DISCOUNT_MAX'];
        }
        if (array_key_exists('EXTRAS', $over) && is_array($over['EXTRAS'])) {
            $extras = [];
            $seen = [];
            foreach (array_slice($over['EXTRAS'], 0, 20) as $row) {
                $ex = melkinoSanitizeCalcExtra($row);
                if ($ex === null) {
                    continue;
                }
                if (isset($seen[$ex['id']])) {
                    $ex['id'] = $ex['id'] . substr(sha1($ex['label'] . count($extras)), 0, 4);
                }
                $seen[$ex['id']] = true;
                $extras[] = $ex;
            }
            $defaults['EXTRAS'] = $extras;
        }
        return $defaults;
    }
}

if (!function_exists('melkinoCalcRates')) {
    function melkinoCalcRates(): array
    {
        $defaults = melkinoCalcDefaultRates();
        if (function_exists('dbSettingGet') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                $raw = dbSettingGet($GLOBALS['pdo'], 'global', 'melkino_calc_rates', null);
                $over = null;
                if (is_array($raw)) {
                    $over = $raw;
                } elseif (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $over = $decoded;
                    }
                }
                if (is_array($over)) {
                    return melkinoSanitizeCalcRates($over);
                }
            } catch (Throwable $e) {
            }
        }
        return $defaults;
    }
}

if (!function_exists('melkinoCalcEnabled')) {
    function melkinoCalcEnabled(): bool
    {
        if (function_exists('dbSettingGet') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            try {
                return (bool) dbSettingGet($GLOBALS['pdo'], 'global', 'enable_property_calculator', true);
            } catch (Throwable $e) {
            }
        }
        return true;
    }
}
