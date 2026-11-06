<?php
/**
 * ملکینو — مدل مرجع قیمت (Single Source of Truth)
 * ---------------------------------------------------------------
 * audit 2026-10-03: منطق نرمال‌سازی/اولویت قیمت قبلاً در ۶ جا تکرار
 * شده بود (admin-panel، module-ads، home، search-results، ad-cards.js،
 * admin-ads.js) و یک‌بار drift واقعی کرده بود (باگ ×۱۰۰). این فایل
 * پیاده‌سازی مرجع PHP است؛ JSON خروجی → JS فقط نمایش/فرمت می‌کند.
 * Business rule مصوب:
 *   اولویت قیمت فروش: price_sell → total_price → deposit → rent_monthly
 *   رشتهٔ ترکیبی («0.00 | 0.00»)، صفر و متن بی‌عدد معتبر نیستند.
 * بازتولید رفتار قبلی با تست رگرسیون تضمین شده است.
 */
if (!function_exists('melkinoPriceClean')) {
    function melkinoPriceClean($value): ?float
    {
        if ($value === null) return null;
        $s = trim((string)$value);
        if ($s === '' || strpos($s, '|') !== false) return null; // رشتهٔ ترکیبی نامعتبر
        $s = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،',',',' ','تومان','ریال'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','','','','','',''],
            $s
        );
        if ($s === '' || !is_numeric($s)) return null;
        $f = (float)$s;
        return $f > 0 ? $f : null; // صفر/منفی = قیمت معتبر نیست
    }
}

if (!function_exists('melkinoPriceDisplayValue')) {
    /** اولویت مصوب نمایش قیمت — همان که ثبت اولیه و bulk_sync استفاده می‌کنند */
    function melkinoPriceDisplayValue(array $ad): ?float
    {
        return melkinoPriceClean($ad['price_sell'] ?? null)
            ?: melkinoPriceClean($ad['total_price'] ?? null)
            ?: melkinoPriceClean($ad['deposit'] ?? null)
            ?: melkinoPriceClean($ad['rent_monthly'] ?? null);
    }
}

if (!function_exists('melkinoPriceFormat')) {
    /** فرمت نمایش: جداکنندهٔ سه‌رقمی (فقط formatting — هیچ تبدیل واحدی انجام نمی‌شود) */
    function melkinoPriceFormat($value): string
    {
        $n = melkinoPriceClean($value);
        return $n === null ? '' : number_format((int)round($n));
    }
}
