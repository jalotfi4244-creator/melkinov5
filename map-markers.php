<?php
/**
 * کتابخانهٔ شکل مارکر نقشه — منبع واحد (PHP + خروجی برای JS)
 *
 * هر شکل یک SVG ساده است که با دو placeholder ساخته می‌شود:
 *   %COLOR%  رنگ اصلی مارکر
 *   %STROKE% رنگ دورخط
 * هیچ اسکریپت/مسیر خارجی داخل SVG نیست.
 */
declare(strict_types=1);

if (!function_exists('melkinoMarkerShapes')) {
    function melkinoMarkerShapes(): array
    {
        return [
            'pin' => [
                'fa' => 'پین کلاسیک',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="M16 1c-7.2 0-13 5.8-13 13 0 9.6 13 27 13 27s13-17.4 13-27c0-7.2-5.8-13-13-13z" fill="%COLOR%" stroke="%STROKE%" stroke-width="2"/><circle cx="16" cy="14" r="5" fill="%STROKE%"/></svg>',
            ],
            'flag' => [
                'fa' => 'پرچم',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="M8 41V3" stroke="%STROKE%" stroke-width="3.5" stroke-linecap="round"/><path d="M8 4h18l-4 6 4 6H8z" fill="%COLOR%" stroke="%STROKE%" stroke-width="2" stroke-linejoin="round"/></svg>',
            ],
            'home' => [
                'fa' => 'خانه',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="M16 1 2 13v14a3 3 0 0 0 3 3h22a3 3 0 0 0 3-3V13z" fill="%COLOR%" stroke="%STROKE%" stroke-width="2" stroke-linejoin="round"/><path d="M16 30v-8h0" stroke="%STROKE%" stroke-width="2"/><path d="M13 41l3-8 3 8z" fill="%COLOR%"/><rect x="12" y="18" width="8" height="10" rx="1.5" fill="%STROKE%"/></svg>',
            ],
            'building' => [
                'fa' => 'برج / آپارتمان',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><rect x="7" y="4" width="18" height="28" rx="2.5" fill="%COLOR%" stroke="%STROKE%" stroke-width="2"/><g fill="%STROKE%"><rect x="11" y="9" width="4" height="4" rx="1"/><rect x="17" y="9" width="4" height="4" rx="1"/><rect x="11" y="16" width="4" height="4" rx="1"/><rect x="17" y="16" width="4" height="4" rx="1"/><rect x="13.5" y="23" width="5" height="9" rx="1"/></g><path d="M13 41l3-8 3 8z" fill="%COLOR%"/></svg>',
            ],
            'circle' => [
                'fa' => 'دایره ساده',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="16" r="13" fill="%COLOR%" stroke="%STROKE%" stroke-width="3"/><path d="M12 30h8l-4 10z" fill="%COLOR%"/></svg>',
            ],
            'tag' => [
                'fa' => 'برچسب قیمت',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="M3 6a3 3 0 0 1 3-3h13l10 11-13 13L3 17z" fill="%COLOR%" stroke="%STROKE%" stroke-width="2" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.6" fill="%STROKE%"/><path d="M13 29h6l-3 12z" fill="%COLOR%"/></svg>',
            ],
            'star' => [
                'fa' => 'ستاره (ویژه)',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="m16 2 4.2 8.6 9.5 1.4-6.9 6.7 1.7 9.4L16 23.7 7.5 28.1l1.7-9.4L2.3 12l9.5-1.4z" fill="%COLOR%" stroke="%STROKE%" stroke-width="1.8" stroke-linejoin="round"/><path d="M13 30h6l-3 11z" fill="%COLOR%"/></svg>',
            ],
            'drop' => [
                'fa' => 'قطره',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><path d="M16 2c6 8 10 12.6 10 18a10 10 0 0 1-20 0c0-5.4 4-10 10-18z" fill="%COLOR%" stroke="%STROKE%" stroke-width="2"/><circle cx="16" cy="20" r="4" fill="%STROKE%"/></svg>',
            ],
            'key' => [
                'fa' => 'کلید',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="11" r="7.5" fill="none" stroke="%COLOR%" stroke-width="4"/><path d="M16 16.5 27 28M22 23l3.5 3.5M19 26l3 3" stroke="%COLOR%" stroke-width="4" stroke-linecap="round"/><circle cx="12" cy="11" r="2.6" fill="%STROKE%"/></svg>',
            ],
            'dot' => [
                'fa' => 'نقطه مینیمال',
                'svg' => '<svg viewBox="0 0 32 42" xmlns="http://www.w3.org/2000/svg"><circle cx="16" cy="21" r="8" fill="%COLOR%" stroke="%STROKE%" stroke-width="3"/></svg>',
            ],
        ];
    }
}

if (!function_exists('melkinoMarkerShapeKeys')) {
    function melkinoMarkerShapeKeys(): array
    {
        return array_keys(melkinoMarkerShapes());
    }
}

if (!function_exists('melkinoMarkerSvg')) {
    /** SVG آمادهٔ یک شکل با رنگ مشخص (برای پیش‌نمایش سمت سرور) */
    function melkinoMarkerSvg(string $shape, string $color = '#0E7C6E', string $stroke = '#FFFFFF'): string
    {
        $shapes = melkinoMarkerShapes();
        $row = $shapes[$shape] ?? $shapes['pin'];
        $color = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color) ? $color : '#0E7C6E';
        $stroke = preg_match('/^#[0-9A-Fa-f]{3,8}$/', $stroke) ? $stroke : '#FFFFFF';
        return str_replace(['%COLOR%', '%STROKE%'], [$color, $stroke], $row['svg']);
    }
}
