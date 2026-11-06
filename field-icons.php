<?php
/**
 * آیکون SVG اختصاصی فیلدهای مشخصات ملک (کارت + جزئیات).
 * تشخیص از روی کلید فیلد، لیبل فارسی، یا ایموجی قدیمی.
 */
if (!function_exists('melkinoFieldIconPaths')) {
    function melkinoFieldIconPaths(): array
    {
        return [
            'cabinet'     => '<rect x="3.5" y="7" width="17" height="14" rx="1"/><path d="M3.5 7V5.5h17V7"/><path d="M12 7v14"/><circle cx="9.2" cy="14.2" r=".7" fill="currentColor" stroke="none"/><circle cx="14.8" cy="14.2" r=".7" fill="currentColor" stroke="none"/>',
            'flooring'    => '<path d="M3 8h18M3 12h18M3 16h18M3 20h18"/><path d="M8 8v12M14 8v12"/>',
            'cooling'     => '<path d="M12 3v18M5.6 6.5l12.8 11M5.6 17.5l12.8-11"/><circle cx="12" cy="12" r="2.2"/>',
            'heating'     => '<path d="M12 21c4 0 6-3 6-6 0-4-6-8-6-12 0 4-6 8-6 12 0 3 2 6 6 6z"/>',
            'area'        => '<path d="M4 7V4h3M20 7V4h-3M4 17v3h3M20 17v3h-3"/><rect x="7" y="8" width="10" height="8" rx="1"/>',
            'land'        => '<path d="M3 18h18"/><path d="M5 18 9 8l4 6 3-4 4 8"/>',
            'built'       => '<path d="M4 20V10l8-5 8 5v10"/><path d="M10 20v-6h4v6"/>',
            'floor'       => '<rect x="6" y="3" width="12" height="18" rx="1"/><path d="M6 9h12M6 15h12M10 6h.01M14 6h.01M10 12h.01M14 12h.01M10 18h.01M14 18h.01"/>',
            'rooms'       => '<path d="M3 18v-8h13a5 5 0 0 1 5 5v3"/><path d="M3 14h18"/><path d="M6 10V7h6v3"/>',
            'year'        => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
            'building_age'=> '<path d="M7 4h10v5l-5 3 5 3v5H7v-5l5-3-5-3z"/><path d="M7 4h10M7 20h10"/>',
            'units'       => '<rect x="4" y="4" width="7" height="16" rx="1"/><rect x="13" y="8" width="7" height="12" rx="1"/><path d="M6.5 8h2M6.5 12h2M15.5 12h2M15.5 16h2"/>',
            'units_floor' => '<path d="M4 20V8h16v12"/><path d="M4 12h16"/><path d="M8 12v8M12 12v8M16 12v8"/>',
            'apartment'   => '<rect x="6" y="3" width="12" height="18"/><path d="M10 7h1M13 7h1M10 11h1M13 11h1M10 15h1M13 15h1M10 21v-3h4v3"/>',
            'villa'       => '<path d="M3 12l9-8 9 8"/><path d="M5 10.5V21h14V10.5"/><path d="M10 21v-6h4v6"/>',
            'condition'   => '<path d="M14.7 6.3a4 4 0 0 1 0 5.7L9 17.7 4.3 13l5.7-5.7a4 4 0 0 1 5.7 0z"/><path d="m4.3 13 4.7 4.7"/><path d="M16 20h4"/>',
            'compass'     => '<circle cx="12" cy="12" r="9"/><path d="m16 8-2.2 6.2L8 16l2.2-6.2z"/>',
            'usage'       => '<rect x="6" y="7" width="12" height="14" rx="1"/><path d="M9 7V5h6v2"/><path d="M9 12h6"/>',
            'width'       => '<path d="M4 12h16M7 9 4 12l3 3M17 9l3 3-3 3"/>',
            'length'      => '<path d="M12 4v16M9 7l3-3 3 3M9 17l3 3 3-3"/>',
            'shape'       => '<path d="M12 3 20 8.5 17 20H7L4 8.5z"/>',
            'deed'        => '<path d="M7 3h8l5 5v13H7z"/><path d="M15 3v5h5"/><path d="M10 13h6M10 17h4"/>',
            'deed_note'   => '<path d="M7 3h8l5 5v13H7z"/><path d="M15 3v5h5"/><path d="M10 13h6M10 17h6"/>',
            'ownership'   => '<path d="M8 11V8a4 4 0 0 1 8 0v3"/><rect x="5" y="11" width="14" height="10" rx="2"/>',
            'front'       => '<path d="M4 20V10l8-6 8 6v10"/><path d="M4 20h16"/><path d="M10 20v-6h4v6"/>',
            'alley'       => '<path d="M4 20 8 4h8l4 16"/><path d="M9 12h6"/>',
            'blocks'      => '<rect x="3" y="8" width="5" height="12"/><rect x="10" y="4" width="4" height="16"/><rect x="16" y="10" width="5" height="10"/>',
            'setback'     => '<path d="M4 20h16"/><path d="M7 20V8h10v12"/><path d="M4 12h3M17 12h3"/>',
            'trees'       => '<path d="M12 21V11"/><path d="M12 11c-4 0-6-3-6-6 3 0 6 2 6 2s3-2 6-2c0 3-2 6-6 6z"/>',
            'tree_age'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'well'        => '<path d="M12 3c4 6 7 9 7 13a7 7 0 1 1-14 0c0-4 3-7 7-13z"/>',
            'irrigation'  => '<path d="M12 3v6"/><path d="M8 9h8"/><path d="M7 21c0-5 2.5-8 5-8s5 3 5 8"/><path d="M5 14h2M17 14h2"/>',
            'cottage'     => '<path d="M3 12 12 4l9 8"/><path d="M5 10v11h14V10"/><rect x="10" y="14" width="4" height="7"/>',
            'pool'        => '<path d="M4 16c1.5-1 3-.5 4.5.5S12 17 13.5 16s3-1.5 4.5-.5 3 .5 4.5-.5"/><path d="M4 20c1.5-1 3-.5 4.5.5S12 21 13.5 20s3-1.5 4.5-.5 3 .5 4.5-.5"/><path d="M6 8h12v6H6z"/>',
            'wall'        => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 12h18M9 5v14M15 12v7"/>',
            'location'    => '<path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.4"/>',
            'city'        => '<path d="M4 20V10h6v10M10 20V6h6v14M16 20v-7h4v7"/>',
            'jobs'        => '<rect x="3" y="8" width="18" height="12" rx="2"/><path d="M8 8V6h8v2"/><path d="M3 13h18"/>',
            'exchange'    => '<path d="M7 7h11l-2.5-2.5M18 17H7l2.5 2.5"/>',
            'key'         => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l3 3"/>',
            'parking'     => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3"/>',
            'elevator'    => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9 10 1.5-2L12 10M15 14l-1.5 2L12 14"/>',
            'storage'     => '<path d="M4 8h16v12H4z"/><path d="M4 8 12 4l8 4"/><path d="M12 8v12"/>',
            'loan'        => '<path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18M12 3 3 10h18z"/>',
            'tag'         => '<path d="M3 3h8l10 10-8 8L3 11z"/><path d="M7.5 7.5h.01"/>',
            'home'        => '<path d="M4 11l8-7 8 7"/><path d="M6 9.5V21h12V9.5"/>',
            'deposit'     => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
            'rent'        => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
            'lock'        => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            'water'       => '<path d="M12 3c4 6 7 9 7 13a7 7 0 1 1-14 0c0-4 3-7 7-13z"/>',
        ];
    }
}

if (!function_exists('melkinoFieldIconAlias')) {
    function melkinoFieldIconAlias(): array
    {
        return [
            'pd.cabinet' => 'cabinet', 'cabinet' => 'cabinet', 'cabinet_type' => 'cabinet',
            'cabinet_apt' => 'cabinet', 'cabinet_villa' => 'cabinet', 'cabinet_comm' => 'cabinet', 'office_cabinet' => 'cabinet',
            'نوع کابینت' => 'cabinet', 'پوشش کف' => 'flooring',
            'pd.flooring' => 'flooring', 'flooring' => 'flooring', 'floor_covering' => 'flooring',
            'نوع پوشش کف' => 'flooring',
            'pd.cooling' => 'cooling', 'cooling' => 'cooling', 'cooling_system' => 'cooling',
            'سیستم سرمایش' => 'cooling', 'سرمایش' => 'cooling',
            'pd.heating' => 'heating', 'heating' => 'heating', 'heating_system' => 'heating',
            'سیستم گرمایش' => 'heating', 'گرمایش' => 'heating',
            'area' => 'area', 'متراژ' => 'area', 'متراژ (متر مربع)' => 'area', 'pd.office_area' => 'area',
            'pd.land_area' => 'land', 'land_area' => 'land', 'متراژ زمین' => 'land',
            'متراژ زمین (متر مربع)' => 'land', 'مساحت زمین (متر مربع)' => 'land',
            'مساحت باغ (متر مربع)' => 'land', 'pd.garden_area' => 'land',
            'built_area' => 'built', 'زیربنا (متر مربع)' => 'built', 'pd.building_area' => 'built',
            'متراژ خانه باغ (متر مربع)' => 'built', 'متراژ بنا' => 'built', 'متراژ واحد' => 'area',
            'floor' => 'floor', 'طبقه' => 'floor', 'pd.office_floor' => 'floor',
            'rooms' => 'rooms', 'تعداد اتاق' => 'rooms', 'pd.office_rooms' => 'rooms',
            'year' => 'year', 'سال ساخت' => 'year', 'pd.office_year' => 'year',
            'building_age' => 'building_age', 'سن بنا' => 'building_age', 'pd.building_age' => 'building_age',
            'pd.total_units' => 'units', 'total_units' => 'units', 'تعداد کل واحدها' => 'units',
            'pd.units_per_floor' => 'units_floor', 'units_per_floor' => 'units_floor',
            'pd.office_units_per_floor' => 'units_floor', 'تعداد واحد در طبقه' => 'units_floor',
            'pd.apartment_type' => 'apartment', 'نوع آپارتمان' => 'apartment',
            'pd.villa_type' => 'villa', 'نوع ویلایی' => 'villa',
            'وضعیت ملک' => 'condition', 'وضعیت واحد' => 'condition', 'pd.office_condition' => 'condition',
            'جهت ملک' => 'compass', 'pd.land_direction' => 'compass', 'pd.office_orientation' => 'compass',
            'کاربری' => 'usage', 'کاربری زمین' => 'usage', 'pd.land_type' => 'usage', 'pd.office_usage' => 'usage',
            'pd.land_width' => 'width', 'عرض زمین (متر)' => 'width', 'عرض زمین' => 'width',
            'pd.land_length' => 'length', 'کوچه یا معبر (متر)' => 'length', 'طول زمین' => 'length',
            'pd.land_shape' => 'shape', 'شکل زمین' => 'shape',
            'deed' => 'deed', 'نوع سند' => 'deed', 'نوع سند زمین' => 'deed', 'نوع سند باغ' => 'deed', 'وضعیت سند زمین' => 'deed',
            'pd.document_type' => 'deed', 'pd.land_deed_type' => 'deed', 'pd.land_deed_status' => 'deed',
            'توضیحات سند' => 'deed_note', 'deed_notes' => 'deed_note',
            'وضعیت مالکیت' => 'ownership', 'pd.land_ownership' => 'ownership',
            'pd.front' => 'front', 'بر مغازه (متر)' => 'front', 'بر مغازه' => 'front', 'بر زمین (متر)' => 'front', 'عرض بر' => 'front',
            'pd.land_front_width' => 'front',
            'تعداد بر' => 'blocks', 'pd.land_blocks' => 'blocks',
            'وضعیت عقب‌نشینی' => 'setback', 'pd.land_setback_status' => 'setback',
            'نوع درختان' => 'trees', 'pd.tree_types' => 'trees',
            'سن درختان' => 'tree_age', 'pd.tree_age' => 'tree_age',
            'آب ملکی' => 'well', 'آب ملکی (چاه)' => 'well', 'pd.has_well' => 'well',
            'نوع آبیاری' => 'irrigation', 'pd.irrigation_type' => 'irrigation',
            'بنا / خانه باغ' => 'cottage', 'pd.has_building' => 'cottage',
            'استخر' => 'pool', 'استخر ذخیره آب' => 'pool', 'pd.has_pond' => 'pool',
            'پوشش دیوارها' => 'wall', 'pd.wall' => 'wall',
            'موقعیت' => 'location', 'location' => 'location', 'pd.location_type' => 'location',
            'موقعیت واحد' => 'compass',
            'ویژگی موقعیت' => 'city', 'pd.location_features' => 'city',
            'مناسب برای' => 'jobs', 'مناسب برای مشاغل' => 'jobs', 'pd.jobs' => 'jobs',
            'معاوضه' => 'exchange', 'exchange' => 'exchange', 'مایل به معاوضه' => 'exchange',
            'کلید نخورده' => 'key', 'key_not_turned' => 'key',
            'parking' => 'parking', 'پارکینگ' => 'parking',
            'elevator' => 'elevator', 'آسانسور' => 'elevator',
            'انباری' => 'storage',
            'loan' => 'loan', 'وام' => 'loan', 'وام‌دار' => 'loan',
            'transaction' => 'tag', 'نوع معامله' => 'tag',
            'property_type' => 'home', 'نوع ملک' => 'home',
            'deposit' => 'deposit', 'مبلغ رهن (ودیعه)' => 'deposit',
            'rent_monthly' => 'rent', 'اجارهٔ ماهانه' => 'rent',
            'full_rent' => 'lock', 'رهن کامل' => 'lock',
            'tags' => 'tag',
            '🚪' => 'cabinet',
            '❄️' => 'cooling',
            '🔥' => 'heating',
            '🧱' => 'flooring',
            '💧' => 'water',
            '🌳' => 'trees',
            '🌲' => 'trees',
            '🏊' => 'pool',
            '🏡' => 'villa',
            '📜' => 'deed',
            '📃' => 'deed',
            '🧭' => 'compass',
            '💼' => 'jobs',
            '🕳️' => 'well',
            '🏚️' => 'cottage',
            '🚧' => 'setback',
            '🔷' => 'shape',
            '🤝' => 'ownership',
            '🌆' => 'city',
            '🏬' => 'units',
            '🛠️' => 'condition',
            '🕰️' => 'tree_age',
            '🏦' => 'loan',
            '💰' => 'deposit',
            '🏠' => 'home',
            '📍' => 'location',
            '🏷️' => 'tag',
            '📐' => 'area',
            '🛏️' => 'rooms',
            '🏢' => 'floor',
            '📅' => 'year',
            '🅿️' => 'parking',
            '🛗' => 'elevator',
            '🔑' => 'key',
            '🔄' => 'exchange',
            '📏' => 'area',
            '↔️' => 'width',
            '↕️' => 'length',
            '🔹' => 'tag',
            '💵' => 'deposit',
            '🗓️' => 'year',
            '🔐' => 'lock',
            '🏅' => 'tag',
            '🔢' => 'blocks',
        ];
    }
}

if (!function_exists('melkinoFieldIconName')) {
    function melkinoFieldIconName(string $hint): string
    {
        $hint = trim($hint);
        if ($hint === '') {
            return '';
        }
        $alias = melkinoFieldIconAlias();
        if (isset($alias[$hint])) {
            return $alias[$hint];
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($hint) : strtolower($hint);
        foreach ($alias as $k => $name) {
            if ($k === '' || preg_match('/[A-Za-z]/', (string) $k)) {
                continue;
            }
            if (function_exists('mb_strlen') ? mb_strlen((string) $k) < 3 : strlen((string) $k) < 3) {
                continue;
            }
            if (strpos($hint, (string) $k) !== false || strpos($low, (string) $k) !== false) {
                return $name;
            }
        }
        $paths = melkinoFieldIconPaths();
        return isset($paths[$hint]) ? $hint : '';
    }
}

if (!function_exists('melkinoFieldIconSvg')) {
    function melkinoFieldIconSvg(string $name, string $cls = 'mk-icon mk-icon--sm'): string
    {
        $paths = melkinoFieldIconPaths();
        if (!isset($paths[$name])) {
            return function_exists('melkinoSvgIcon') ? melkinoSvgIcon($name, $cls) : '';
        }
        $wh = (strpos($cls, 'mk-icon--sm') !== false) ? ' width="16" height="16"' : ' width="18" height="18"';
        return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $wh
            . ' viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . $paths[$name] . '</svg>';
    }
}

if (!function_exists('melkinoFieldIcon')) {
    function melkinoFieldIcon(string $hint, string $cls = 'mk-icon mk-icon--sm'): string
    {
        $name = melkinoFieldIconName($hint);
        if ($name === '') {
            if ($hint !== '' && function_exists('melkinoSvgIcon')) {
                $legacy = melkinoSvgIcon($hint, $cls);
                if ($legacy !== '') {
                    return $legacy;
                }
            }
            if (function_exists('melkinoIconOrText') && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $hint)) {
                $mapped = melkinoIconOrText($hint);
                if ($mapped !== '' && $mapped !== htmlspecialchars($hint, ENT_QUOTES, 'UTF-8')) {
                    return $mapped;
                }
            }
            return '';
        }
        return melkinoFieldIconSvg($name, $cls);
    }
}

if (!function_exists('melkinoFieldIconsFrontScript')) {
    function melkinoFieldIconsFrontScript(): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        return '<script>window.MELKINO_FIELD_ICON_PATHS=' . json_encode(melkinoFieldIconPaths(), $flags)
            . ';window.MELKINO_FIELD_ICON_ALIAS=' . json_encode(melkinoFieldIconAlias(), $flags)
            . ';(function(w){if(w.mkFieldIcon)return;function nm(h){h=String(h||"").trim();if(!h)return"";'
            . 'var a=w.MELKINO_FIELD_ICON_ALIAS||{},p=w.MELKINO_FIELD_ICON_PATHS||{};if(a[h])return a[h];'
            . 'for(var k in a){if(!k||/[A-Za-z]/.test(k)||k.length<3)continue;if(h.indexOf(k)!==-1)return a[k];}return p[h]?h:""}'
            . 'w.mkFieldIcon=function(key,label,emoji){var n=nm(key)||nm(label)||nm(emoji);'
            . 'if(!n||!w.MELKINO_FIELD_ICON_PATHS[n])return"";'
            . 'return \'<svg class="mk-icon mk-icon--sm mk-field-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">\'+w.MELKINO_FIELD_ICON_PATHS[n]+"</svg>"};})(window);</script>' . "\n";
    }
}
