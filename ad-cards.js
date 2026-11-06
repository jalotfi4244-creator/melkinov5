/* =========================================================
   ad-cards.js — رندر مشترک کارت آگهی (راند ۶۷+)
   استخراج‌شده خودکار از properties.php — کارت‌های خانه/VIP یکسان.
   ========================================================= */
(function () {

    function mkIconOrText41(s) {
        var sv = function (inner) { return '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-3px;">' + inner + '</svg>'; };
        var map = {
            '🏦': sv('<path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18M12 3 3 10h18z"/>'),
            '💰': sv('<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>'),
            '🏠': sv('<path d="M4 11l8-7 8 7"/><path d="M6 9.5V21h12V9.5"/>'),
            '📋': sv('<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4v3h6V4"/><path d="M9 12h6M9 16h6"/>'),
            '📍': sv('<path d="M12 21s-7-6-7-11a7 7 0 0 1 14 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>'),
            '⭐': sv('<path d="m12 3 2.7 5.8 6.3.8-4.6 4.3 1.2 6.1L12 17l-5.6 3 1.2-6.1L3 9.6l6.3-.8z"/>'),
            '🏷️': sv('<path d="M3 3h8l10 10-8 8L3 11z"/><path d="M7.5 7.5h.01"/>'),
            '📐': sv('<path d="M3 17 17 3l4 4L7 21z"/><path d="M8 16l1.5 1.5M11 13l1.5 1.5M14 10l1.5 1.5"/>'),
            '🛏️': sv('<path d="M3 18v-8h13a5 5 0 0 1 5 5v3"/><path d="M3 14h18"/><path d="M6 10V7h6v3"/>'),
            '🏢': sv('<rect x="6" y="3" width="12" height="18"/><path d="M10 7h1M13 7h1M10 11h1M13 11h1M10 15h1M13 15h1"/>'),
            '📅': sv('<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>'),
            '🅿️': sv('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3"/>'),
            '🛗': sv('<rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9 10 1.5-2L12 10M15 14l-1.5 2L12 14"/>'),
            '🔑': sv('<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l3 3"/>'),
            '🔄': sv('<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>'),
            '🏅': sv('<path d="m12 3 2.7 5.8 6.3.8-4.6 4.3 1.2 6.1L12 17l-5.6 3 1.2-6.1L3 9.6l6.3-.8z"/>'),
            '↔️': sv('<path d="M3 17 17 3l4 4L7 21z"/><path d="M8 16l1.5 1.5M11 13l1.5 1.5M14 10l1.5 1.5"/>'),
            '↕️': sv('<path d="M3 17 17 3l4 4L7 21z"/><path d="M8 16l1.5 1.5M11 13l1.5 1.5M14 10l1.5 1.5"/>'),
            '📏': sv('<path d="M3 17 17 3l4 4L7 21z"/><path d="M8 16l1.5 1.5M11 13l1.5 1.5M14 10l1.5 1.5"/>'),
            '⚖️': sv('<path d="M12 3v18"/><path d="M6 7h12"/><path d="m6 7-3 6a3 3 0 0 0 6 0z"/><path d="m18 7-3 6a3 3 0 0 0 6 0z"/><path d="M8 21h8"/>'),
            '🚪': sv('<rect x="3.5" y="7" width="17" height="14" rx="1"/><path d="M3.5 7V5.5h17V7"/><path d="M12 7v14"/><circle cx="9.2" cy="14.2" r=".7" fill="currentColor" stroke="none"/><circle cx="14.8" cy="14.2" r=".7" fill="currentColor" stroke="none"/>'),
            '❄️': sv('<path d="M12 3v18M5.6 6.5l12.8 11M5.6 17.5l12.8-11"/><circle cx="12" cy="12" r="2.2"/>'),
            '🔥': sv('<path d="M12 21c4 0 6-3 6-6 0-4-6-8-6-12 0 4-6 8-6 12 0 3 2 6 6 6z"/>'),
            '🧱': sv('<path d="M3 8h18M3 12h18M3 16h18M3 20h18"/><path d="M8 8v12M14 8v12"/>'),
            '📜': sv('<path d="M7 3h8l5 5v13H7z"/><path d="M15 3v5h5"/><path d="M10 13h6M10 17h4"/>'),
            '🧭': sv('<circle cx="12" cy="12" r="9"/><path d="m16 8-2.2 6.2L8 16l2.2-6.2z"/>'),
            '🌳': sv('<path d="M12 21V11"/><path d="M12 11c-4 0-6-3-6-6 3 0 6 2 6 2s3-2 6-2c0 3-2 6-6 6z"/>'),
            '🏡': sv('<path d="M3 12l9-8 9 8"/><path d="M5 10.5V21h14V10.5"/><path d="M10 21v-6h4v6"/>'),
            '🏊': sv('<path d="M4 16c1.5-1 3-.5 4.5.5S12 17 13.5 16s3-1.5 4.5-.5 3 .5 4.5-.5"/><path d="M4 20c1.5-1 3-.5 4.5.5S12 21 13.5 20s3-1.5 4.5-.5 3 .5 4.5-.5"/><path d="M6 8h12v6H6z"/>')
        };
        var esc = function (v) {
            return String(v == null ? '' : v).replace(/[&<>'"]/g, function (ch) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[ch];
            });
        };
        return (s in map) ? map[s] : esc(s);
    }

    function mkAmenityList(ad, details) {
        var bag = [];
        function push(v) {
            if (v == null || v === '') return;
            if (Array.isArray(v)) { v.forEach(push); return; }
            if (typeof v === 'object') { push(v.name || v.title || v.label || ''); return; }
            var s = String(v).replace(/[\u200c\s]+/g, '');
            if (s) bag.push(s);
        }
        push(ad && ad.amenities);
        push(ad && ad.amenity);
        push(details && details.amenities);
        return bag;
    }
    function mkHasAmenity(ad, details, needles) {
        var bag = mkAmenityList(ad, details);
        for (var i = 0; i < needles.length; i++) {
            var n = String(needles[i] || '').replace(/[\u200c\s]+/g, '');
            if (!n) continue;
            for (var j = 0; j < bag.length; j++) {
                if (bag[j].indexOf(n) !== -1) return true;
            }
        }
        return false;
    }


    'use strict';


    /* =========================================================
       DOM
    ========================================================== */

    var propertiesList =
        document.getElementById(
            'propertiesList'
        );

    var propertiesCount =
        document.getElementById(
            'propertiesCount'
        );

    var sortSelect =
        document.getElementById(
            'sortSelect'
        );


    /* =========================================================
       DATA
    ========================================================== */

    var allProperties = [];
    var siteLogoUrl = window.MELKINO_SITE_LOGO || '';

    function mkCardWatermarkHtml(skip) {
        if (skip) return '';
        if (typeof window.mkPhotoWmHtml === 'function') {
            return window.mkPhotoWmHtml('card');
        }
        var cfg = window.MELKINO_PHOTO_WM || {};
        var logo = String(cfg.logo_url || cfg.logo || window.MELKINO_SITE_LOGO || '').trim();
        if (cfg.enabled === false || cfg.on_cards === false) return '';
        if (!logo) {
            return '<div class="mk-photo-wm" aria-hidden="true"><span class="mk-photo-wm-txt">ملکینو</span></div>';
        }
        var op = Number(cfg.opacity);
        if (!isFinite(op)) op = 0.32;
        if (op > 1) op = op / 100;
        op = Math.max(0.04, Math.min(0.92, op));
        var sz = Number(cfg.size);
        if (!isFinite(sz)) sz = 38;
        sz = Math.max(8, Math.min(85, sz));
        return '<div class="mk-photo-wm" aria-hidden="true" style="--mk-wm-opacity:' + op + ';--mk-wm-size:' + sz + '%;--mk-wm-top:50%;--mk-wm-left:50%;--mk-wm-tx:-50%;--mk-wm-ty:-50%">' +
            '<img src="' + escapeHtml(logo) + '" alt="" draggable="false">' +
            '</div>';
    }


    /* =========================================================
       TYPE MAPS
    ========================================================== */

    var propertyTypeMap = {

        apartment: 'آپارتمان',
        villa: 'ویلا',
        commercial: 'تجاری',
        land: 'زمین',
        garden: 'باغ',
        office: 'اداری'

    };


    var transactionTypeMap = {

        sell: 'فروش',
        pre_sell: 'پیش فروش',
        rent: 'اجاره',
        mortgage: 'رهن کامل'

    };


    /* =========================================================
       DIGITS
    ========================================================== */

    function normalizeDigits(value) {

        return String(value || '')
            .replace(/[۰-۹]/g, function (digit) {

                return '۰۱۲۳۴۵۶۷۸۹'
                    .indexOf(digit);

            })
            .replace(/[٠-٩]/g, function (digit) {

                return '٠١٢٣٤٥٦٧٨٩'
                    .indexOf(digit);

            });

    }


    /* =========================================================
       NUMBER
    ========================================================== */

    function parseNumber(value) {

        var normalized =
            normalizeDigits(value)
                .replace(
                    /[,\s٬،٫]/g,
                    ''
                );

        if (!normalized) {
            return 0;
        }

        return (
            parseInt(
                normalized,
                10
            ) || 0
        );

    }


    /* =========================================================
       HTML ESCAPE
    ========================================================== */

    function mkMelkinoStars(ad) {
        if (!window.MELKINO_RATING_ENABLED) return '';
        if (!ad || !ad.melkino_visited) return '';
        var n = Math.round(Number(ad.melkino_rating) || 0);
        if (n < 1) return '';
        if (n > 5) n = 5;
        var star = '<svg class="mk-icon" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m12 3 2.7 5.8 6.3.8-4.6 4.3 1.2 6.1L12 17l-5.6 3 1.2-6.1L3 9.6l6.3-.8z"/></svg>';
        var html = '<div class="mk-melkino-rating" title="امتیاز بازدید ملکینو">';
        for (var i = 1; i <= 5; i++) {
            html += '<span class="' + (i <= n ? 'on' : 'off') + '">' + star + '</span>';
        }
        html += '</div>';
        return html;
    }

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );

    }


    /* =========================================================
       IMAGE NORMALIZER
       ========================================================== */

    function normalizeImages(value) {

        if (Array.isArray(value)) {

            return value.filter(
                function (item) {

                    return (
                        typeof item === 'string' &&
                        item.trim() !== ''
                    );

                }
            );

        }

        if (
            typeof value === 'string' &&
            value.trim() !== ''
        ) {

            try {

                var decoded =
                    JSON.parse(value);

                if (
                    Array.isArray(decoded)
                ) {

                    return decoded.filter(
                        function (item) {

                            return (
                                typeof item === 'string' &&
                                item.trim() !== ''
                            );

                        }
                    );

                }

            } catch (error) {

                if (
                    value.indexOf('/') !== -1 ||
                    value.indexOf('.') !== -1
                ) {

                    return [
                        value.trim()
                    ];

                }

            }

        }

        return [];

    }


    /* =========================================================
       IMAGE URL
    ========================================================== */

    function normalizeImageUrl(url) {

        if (!url) return '';

        var imageUrl = String(url).trim();
        if (!imageUrl) return '';

        imageUrl = imageUrl.replace(/\\/g, '/');

        if (/^(https?:)?\/\//i.test(imageUrl)) {
            return imageUrl;
        }

        imageUrl = imageUrl.replace(/^\.\//, '');
        imageUrl = imageUrl.replace(/^\/+/, '');
        imageUrl = imageUrl.replace(/^melkino\//i, '');

        return imageUrl;
    }

    function collectImageCandidates(value, out) {

        out = out || [];

        if (value === null || value === undefined) {
            return out;
        }

        if (Array.isArray(value)) {
            value.forEach(function (item) {
                collectImageCandidates(item, out);
            });
            return out;
        }

        if (typeof value === 'object') {
            ['url', 'path', 'src', 'file', 'image', 'image_url', 'file_url'].forEach(function (key) {
                if (value[key]) {
                    collectImageCandidates(value[key], out);
                }
            });
            return out;
        }

        if (typeof value === 'string') {
            var raw = value.trim();
            if (!raw) return out;

            if (raw.charAt(0) === '[' || raw.charAt(0) === '{') {
                try {
                    var decoded = JSON.parse(raw);
                    if (decoded && decoded !== value) {
                        collectImageCandidates(decoded, out);
                        return out;
                    }
                } catch (e) {}
            }

            out.push(raw);
        }

        return out;
    }

    function getImageUrlCandidates(value) {

        var rawCandidates = collectImageCandidates(value, []);
        var result = [];

        rawCandidates.forEach(function (raw) {

            var normalized = normalizeImageUrl(raw);
            if (!normalized) return;

            var variants = [normalized];

            if (!/^(https?:)?\/\//i.test(normalized)) {
                variants.push('/' + normalized);
                variants.push('/melkino/' + normalized);

                if (normalized.indexOf('uploads/') !== 0) {
                    variants.push('/melkino/uploads/' + normalized);
                }
            }

            variants.forEach(function (variant) {
                if (result.indexOf(variant) === -1) {
                    result.push(variant);
                }
            });
        });

        return result;
    }

    function getFirstValidImage(ad) {

        var sources = [
            ad.selected_images,
            ad.selectedImages,
            ad.images,
            ad.photos,
            ad.gallery,
            ad.gallery_images,
            ad.image,
            ad.photo,
            ad.image_url,
            ad.photo_url
        ];

        for (var i = 0; i < sources.length; i++) {
            var candidates = getImageUrlCandidates(sources[i]);
            if (candidates.length) {
                return candidates[0];
            }
        }

        return '';
    }


    /* =========================================================
       NORMALIZE AD
    ========================================================== */

    /* پیام قیمت فعال: هیچ مبلغی به کارت نمی‌رسد (ادمین همچنان قیمت را می‌بیند) */
    function mkMessageOnlyPrices(ad) {
        var vis = window.MELKINO_PRICE_VISIBILITY;
        if (!ad || !vis || !vis.hide) return ad;
        var msg = String(vis.message || 'برای استعلام قیمت تماس بگیرید');
        var out = {};
        Object.keys(ad).forEach(function (k) { out[k] = ad[k]; });
        ['price', 'price_sell', 'total_price', 'deposit', 'rent_monthly', 'full_rent', 'down_payment',
         'loan_amount', 'loan_installment', 'priceNumeric'].forEach(function (k) { out[k] = ''; });
        out.display_price = msg;
        out.card_price = msg;
        out.has_loan = 0;
        var strip = function (obj) {
            var o = {};
            Object.keys(obj).forEach(function (k) { if (!/قیمت|ودیعه|اجاره|رهن|وام|پیش/.test(k)) o[k] = obj[k]; });
            return o;
        };
        ['property_details', 'details'].forEach(function (key) {
            var d = ad[key];
            if (typeof d === 'string') { try { d = JSON.parse(d); } catch (e) { d = null; } }
            if (d && typeof d === 'object' && !Array.isArray(d)) { out[key] = strip(d); }
        });
        return out;
    }

    function normalizeAd(ad) {
        ad = mkMessageOnlyPrices(ad);

        var transactionType =
            ad.transactionType ||
            ad.transaction_type ||
            ad.type ||
            '';

        var propertyType =
            ad.propertyType ||
            ad.property_type ||
            '';

        if (
            transactionTypeMap[
                transactionType
            ]
        ) {

            transactionType =
                transactionTypeMap[
                    transactionType
                ];

        }

        if (
            propertyTypeMap[
                propertyType
            ]
        ) {

            propertyType =
                propertyTypeMap[
                    propertyType
                ];

        }

        // ===== تغییر: گرفتن فیلدهای قیمتی =====
        var deposit = ad.deposit || '';
        var rentMonthly = ad.rent_monthly || '';
        var priceSell = ad.price_sell || '';
        var totalPrice = ad.total_price || '';

        /* اولین مقدار واقعی — رشته‌های آشغال «0.00» و «0.00 | 0.00» رد می‌شوند */
        function mkFirstReal() {
            for (var i = 0; i < arguments.length; i++) {
                var v = arguments[i];
                if (v == null) continue;
                var t = String(v).trim();
                if (!t || t === '0' || t === '۰') continue;
                if (/^[0.,٬،\s]+$/.test(t)) continue;
                if (t.indexOf('|') !== -1) {
                    var allZero = true;
                    var parts = t.split('|');
                    for (var j = 0; j < parts.length; j++) {
                        var pt = String(parts[j]).trim();
                        if (pt && !/^[0.,٬،\s]+$/.test(pt) && pt !== '0' && pt !== '۰') { allZero = false; break; }
                    }
                    if (allZero) continue;
                }
                return v;
            }
            return '';
        }

        var displayPrice =
            mkFirstReal(
                ad.display_price,
                ad.price_sell,
                ad.total_price,
                ad.price,
                ad.deposit,
                ad.rent_monthly
            ) || '۰';

        var priceNumeric =
            parseNumber(
                ad.priceNumeric ||
                ad.price_sell ||
                ad.total_price ||
                ad.price ||
                0
            );

        var area =
            parseNumber(
                ad.area ||
                ad.land_area ||
                ad.office_area ||
                ad.building_area ||
                ad.property_area ||
                0
            );

        var location =
            ad.location ||
            ad.neighborhood ||
            'نامشخص';

        var neighborhood =
            ad.neighborhood ||
            (
                location
                    ? String(location)
                        .split('،')[0]
                        .trim()
                    : ''
            );

        var selectedImages = [];
        [
            ad.selected_images,
            ad.selectedImages,
            ad.images,
            ad.photos,
            ad.gallery,
            ad.gallery_images,
            ad.image,
            ad.photo
        ].forEach(function (source) {
            collectImageCandidates(source, selectedImages);
        });

        var timestamp =
            ad.created_at
                ? new Date(
                    ad.created_at
                ).getTime()
                : (
                    ad.timestamp ||
                    Date.now()
                );

        var details =
            ad.property_details ||
            ad.propertyDetails ||
            ad.details ||
            {};

        if (
            typeof details === 'string'
        ) {

            try {

                details =
                    JSON.parse(
                        details
                    );

            } catch (error) {

                details = {};

            }

        }

        if (
            !details ||
            typeof details !== 'object'
        ) {

            details = {};

        }

        var amenities =
            ad.amenities ||
            [];

        if (
            typeof amenities === 'string'
        ) {

            try {

                amenities =
                    JSON.parse(
                        amenities
                    );

            } catch (error) {

                amenities = [];

            }

        }

        if (
            !Array.isArray(amenities)
        ) {

            amenities = [];

        }

        if (
            !ad.display_price &&
            ad.deposit &&
            ad.rent_monthly &&
            String(ad.deposit) !== '0' &&
            String(ad.rent_monthly) !== '0'
        ) {

            displayPrice =
                String(
                    ad.deposit
                ) +
                ' | ' +
                String(
                    ad.rent_monthly
                );

        }

        return {

            id:
                ad.id ||
                'N/A',

            title:
                ad.title ||
                'بدون عنوان',

            price:
                String(
                    displayPrice
                ),

            priceNumeric:
                priceNumeric,

            location:
                location,

            neighborhood:
                neighborhood,

            propertyType:
                propertyType ||
                'نامشخص',

            transactionType:
                transactionType ||
                'نامشخص',

            type:
                transactionType ||
                'نامشخص',

            area:
                area,

            bedrooms:
                ad.rooms ||
                ad.bedrooms ||
                details.rooms ||
                details.bedrooms ||
                '-',

            tags:
                Array.isArray(ad.tags)
                    ? ad.tags
                    : [],

            parking:
                ad.parking === true ||
                ad.parking === 1 ||
                ad.parking === '1' ||
                details.parking === true ||
                details.parking === 1 ||
                details.parking === '1' ||
                mkHasAmenity(ad, details, ['پارکینگ', 'parking']),

            elevator:
                ad.elevator === true ||
                ad.elevator === 1 ||
                ad.elevator === '1' ||
                details.elevator === true ||
                details.elevator === 1 ||
                details.elevator === '1' ||
                mkHasAmenity(ad, details, ['آسانسور', 'elevator']),

            amenities:
                amenities,

            details:
                details,

            selectedImages:
                selectedImages,

            status:
                ad.status ||
                'pending',

            timestamp:
                isNaN(timestamp)
                    ? Date.now()
                    : timestamp,

            // ===== اضافه کردن فیلدهای قیمتی =====
            deposit: deposit,
            rent_monthly: rentMonthly,
            price_sell: priceSell,
            total_price: totalPrice,
            display_price: ad.display_price || '',
            card_price: ad.card_price || '',
            down_payment: ad.down_payment || '',
            payment_terms: ad.payment_terms || '',
            price_hidden: !!(ad.price_hidden || ad.priceHidden),

            // ===== فیلدهای وام =====
            has_loan: ad.has_loan === true || ad.has_loan === 1 || ad.has_loan === '1',
            loan_amount: ad.loan_amount || '',

            // ===== مایل به معاوضه (راند ۱۴) =====
            exchange_interested: ad.exchange_interested == 1 || ad.exchange_interested === '1' || ad.exchange_interested === true,

            // ===== فیلدهای رندر پویای کارت (راند ۲۱) =====
            is_not_keyed: ad.is_not_keyed == 1 || ad.is_not_keyed === '1' || ad.is_not_keyed === true,
            deed_type: ad.deed_type || details.document_type || details.land_deed_type || '',
            full_rent: ad.full_rent || details.full_rent || '',
            full_rent_enabled: ad.full_rent_enabled == 1 || ad.full_rent_enabled === '1' || ad.full_rent_enabled === true,

            melkino_visited: ad.melkino_visited == 1 || ad.melkino_visited === '1' || ad.melkino_visited === true,
            melkino_rating: Number(ad.melkino_rating || 0),
            created_at: ad.created_at || ad.date || '',

        };

    }


    window.handleCardImageError = function (img) {
        try {
            var candidates = JSON.parse(img.getAttribute('data-image-candidates') || '[]');
            var current = img.getAttribute('src') || '';
            var index = candidates.indexOf(current);
            var next = index >= 0 ? candidates[index + 1] : candidates[0];

            if (next && next !== current) {
                img.setAttribute('src', next);
                return;
            }
        } catch (e) {}

        img.style.display = 'none';
        var _parent = img.parentNode;
        var _bd = _parent ? _parent.querySelector('.mk-decor-badge') : null;
        if (_bd) { _bd.style.display = 'none'; }
        var _wm = _parent ? _parent.querySelector('.mk-photo-wm') : null;
        if (_wm) { _wm.style.display = 'none'; }
        var _ph = _parent ? _parent.querySelector('.property-image-placeholder') : null;
        if (_ph) {
            _ph.style.display = 'flex';
        }
    };

    /* =========================================================
       LOAD ADS
    ========================================================== */

    function mkDefaultImageFor(ad) {
        var map = window.MELKINO_DEFAULT_IMAGES || {};
        var t = String(ad.propertyType || ad.property_type || '');
        var list = map[t] || [];
        if (!list || !list.length) {
            if (t.indexOf('ویلا') !== -1) list = map['ویلایی'] || map['ویلا'] || [];
            else if (t.indexOf('آپارتمان') !== -1) list = map['آپارتمان'] || [];
            else if (t.indexOf('باغ') !== -1) list = map['باغ'] || [];
            else if (t.indexOf('زمین') !== -1) list = map['زمین'] || [];
            else if (t.indexOf('اداری') !== -1) list = map['اداری'] || [];
            else if (t.indexOf('تجاری') !== -1 || t.indexOf('مغازه') !== -1) list = map['تجاری'] || map['مغازه'] || [];
        }
        if (!list || !list.length) return '';
        var no = parseInt(ad.default_image_no || 0, 10);
        if (no >= 1 && list[no - 1]) return list[no - 1];
        var h = 0, id = String(ad.id || '');
        for (var i = 0; i < id.length; i++) { h = (h * 31 + id.charCodeAt(i)) >>> 0; }
        return list[h % list.length];
    }

    function displayPrice(ad) {
        if (window.MELKINO_PRICE_VISIBILITY && (window.MELKINO_PRICE_VISIBILITY.hide || ad.price_hidden)) { return (window.MELKINO_PRICE_VISIBILITY.message || 'برای استعلام قیمت تماس بگیرید'); }



        function normalizeMoney(value) {
            if (value === null || value === undefined) return 0;

            var s = String(value)
                .replace(/[۰-۹]/g, function (digit) {
                    return '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit);
                })
                .replace(/[٠-٩]/g, function (digit) {
                    return '٠١٢٣٤٥٦٧٨٩'.indexOf(digit);
                })
                .replace(/[٬،,\s]/g, '')
                .replace(/تومان/g, '');

            var n = Number(s);
            return isFinite(n) ? n : 0;
        }

        function moneyFa(value) {
            var n = normalizeMoney(value);
            return n > 0 ? n.toLocaleString('fa-IR') : '';
        }

        var depositText = moneyFa(ad.deposit || '');
        var rentText = moneyFa(ad.rent_monthly || '');
        var fullRentText = moneyFa(ad.full_rent || '');

        /* راند ۷۳: قیمت فروش از ستون‌های قیمتی — رشتهٔ «0.00 | 0.00» نادیده گرفته می‌شود */
        var sellCandidates = [ad.display_price, ad.price_sell, ad.total_price, ad.price];
        var sellRaw = '';
        for (var si = 0; si < sellCandidates.length; si++) {
            var sv = sellCandidates[si];
            if (sv == null || sv === '') continue;
            var ss = String(sv);
            if (ss.indexOf('|') !== -1) continue;
            if (normalizeMoney(ss) > 0) { sellRaw = ss; break; }
        }

        var tx = String(ad.transaction_type || ad.transactionType || '');
        var isRentTx = tx.indexOf('اجاره') !== -1 || tx.indexOf('رهن') !== -1 || tx === 'rent' || tx === 'mortgage';

        /* اجاره/رهن: مبلغ واقعی ودیعه و اجاره مقدم است؛ فروش: قیمت فروش مقدم است */
        if (isRentTx || !sellRaw) {
            if (depositText && rentText) {
                return 'ودیعه: ' + depositText + ' تومان | اجاره: ' + rentText + ' تومان';
            }
            if (depositText) {
                return 'ودیعه: ' + depositText + ' تومان';
            }
            if (rentText) {
                return 'اجاره: ' + rentText + ' تومان';
            }
            if (isRentTx && fullRentText) {
                return 'رهن کامل: ' + fullRentText + ' تومان';
            }
        }

        var price = sellRaw;
        var priceText = moneyFa(price);

        /* وام‌دار: دو قیمت — کامل و «منهای وام + وام» */
        var MK_LOAN_BANK_SVG = '<svg class="mk-icon mk-icon--sm" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 21h18M12 3 3 10h18z"/></svg>';
        var loanNum = normalizeMoney(ad.loan_amount || '');
        var priceNum = normalizeMoney(price);
        var showLoanLine = true;
        try {
            var __cd = (window.MELKINO_CARD_DISPLAY || {}).settings || {};
            var __lv = __cd.loan_price_line;
            showLoanLine = !(__lv === false || __lv === 'off' || __lv === 0 || __lv === '0');
        } catch (e0) {}
        if (showLoanLine && ad.has_loan && loanNum > 0 && priceNum > loanNum) {
            var netNum = priceNum - loanNum;
            var full = priceText ? priceText + ' تومان' : '';
            return full
                + '<div class="price-loan-sub">' + MK_LOAN_BANK_SVG + ' '
                + netNum.toLocaleString('fa-IR') + ' تومان + '
                + loanNum.toLocaleString('fa-IR') + ' تومان وام</div>';
        }

        if (priceText) {
            return priceText + ' تومان';
        }

        /* قیمت متنی (مثل «۱۸ میلیارد» یا «متری ۸۰»): عدد خالص نیست ولی متن واقعیِ ثبت‌شدهٔ مالک است */
        var textCandidates = [ad.display_price, ad.price, ad.price_sell, ad.total_price];
        for (var ti = 0; ti < textCandidates.length; ti++) {
            var tv = textCandidates[ti];
            if (tv == null) continue;
            var tt = String(tv).trim();
            if (!tt || tt === '0' || tt === '۰' || tt === '0.0' || tt === '0.00') continue;
            if (tt.indexOf('|') !== -1) continue;
            if (/^[0.,٬،\s]+$/.test(tt)) continue;
            return escapeHtml(tt);
        }

        /* آخرین پناهگاه: قیمت از پیش محاسبه‌شدهٔ سرور (همان منطق صفحهٔ جزئیات) */
        if (ad.card_price && String(ad.card_price).trim()) {
            return escapeHtml(String(ad.card_price).trim());
        }

        var restCandidates = [ad.down_payment, ad.payment_terms];
        for (var ri = 0; ri < restCandidates.length; ri++) {
            var rv = restCandidates[ri];
            if (rv == null) continue;
            var rt = String(rv).trim();
            if (!rt || rt === '0' || rt === '۰') continue;
            if (/^[0.,٬،\s]+$/.test(rt)) continue;
            if (rt.indexOf('|') !== -1) continue;
            return escapeHtml(rt);
        }
        var pdPrice = ad.property_details || ad.details || {};
        if (typeof pdPrice === 'string') {
            try { pdPrice = JSON.parse(pdPrice); } catch (e) { pdPrice = {}; }
        }
        if (pdPrice && typeof pdPrice === 'object') {
            var pdKeys = Object.keys(pdPrice);
            for (var pi = 0; pi < pdKeys.length; pi++) {
                var pk = pdKeys[pi];
                if (pk.indexOf('قیمت') === -1 && pk.indexOf('ودیعه') === -1 && pk.indexOf('اجاره') === -1) continue;
                var pv = pdPrice[pk];
                if (Array.isArray(pv)) { pv = pv.join('، '); }
                if (pv == null) continue;
                var pt2 = String(pv).trim();
                if (!pt2 || pt2 === '0' || pt2 === '۰') continue;
                if (/^[0.,٬،\s]+$/.test(pt2)) continue;
                return escapeHtml(pk + ': ' + pt2);
            }
        }

        return 'تماس بگیرید';
    }


    /* =========================================================
       RENDER
    ========================================================== */

    function createPropertyCard(ad) {

        var card =
            document.createElement(
                'article'
            );

        var studio = (window.MELKINO_STUDIO && window.MELKINO_STUDIO.layout) ? window.MELKINO_STUDIO : null;
        var layout = String((studio && studio.layout) || window.MELKINO_CARD_LAYOUT || 'photo-top');
        var alias = { 'photo-top': 'nx-01', 'photo-full': 'nx-31', 'photo-left': 'nx-26', 'photo-right': 'nx-21', 'photo-float': 'nx-46', 'photo-collage': 'nx-96', 'photo-portrait': 'nx-76', 'photo-editorial': 'nx-41' };
        if (alias[layout]) layout = alias[layout];
        var bases = {
            'nx-01': 'photo-top', 'nx-02': 'photo-top', 'nx-03': 'photo-top', 'nx-04': 'photo-top', 'nx-05': 'photo-top', 'nx-06': 'photo-top', 'nx-07': 'photo-top', 'nx-08': 'photo-top', 'nx-09': 'photo-top', 'nx-10': 'photo-top', 'nx-11': 'photo-top', 'nx-12': 'photo-top', 'nx-13': 'photo-top', 'nx-14': 'photo-top', 'nx-15': 'photo-top', 'nx-16': 'photo-left', 'nx-17': 'photo-left', 'nx-18': 'photo-left', 'nx-19': 'photo-left', 'nx-20': 'photo-left', 'nx-21': 'photo-top', 'nx-22': 'photo-top', 'nx-23': 'photo-top', 'nx-24': 'photo-top', 'nx-25': 'photo-top', 'nx-26': 'photo-full', 'nx-27': 'photo-full', 'nx-28': 'photo-full', 'nx-29': 'photo-full', 'nx-30': 'photo-full', 'nx-31': 'photo-top', 'nx-32': 'photo-top', 'nx-33': 'photo-top', 'nx-34': 'photo-top', 'nx-35': 'photo-top', 'nx-36': 'photo-portrait', 'nx-37': 'photo-portrait', 'nx-38': 'photo-portrait', 'nx-39': 'photo-portrait', 'nx-40': 'photo-portrait', 'nx-41': 'photo-full', 'nx-42': 'photo-full', 'nx-43': 'photo-full', 'nx-44': 'photo-full', 'nx-45': 'photo-full', 'nx-46': 'photo-top', 'nx-47': 'photo-top', 'nx-48': 'photo-top', 'nx-49': 'photo-top', 'nx-50': 'photo-top', 'nx-51': 'photo-top', 'nx-52': 'photo-top', 'nx-53': 'photo-top', 'nx-54': 'photo-top', 'nx-55': 'photo-top', 'nx-56': 'photo-full', 'nx-57': 'photo-full', 'nx-58': 'photo-full', 'nx-59': 'photo-full', 'nx-60': 'photo-full', 'nx-61': 'photo-left', 'nx-62': 'photo-left', 'nx-63': 'photo-left', 'nx-64': 'photo-left', 'nx-65': 'photo-left', 'nx-66': 'photo-top', 'nx-67': 'photo-top', 'nx-68': 'photo-top', 'nx-69': 'photo-top', 'nx-70': 'photo-top', 'nx-71': 'photo-top', 'nx-72': 'photo-top', 'nx-73': 'photo-top', 'nx-74': 'photo-top', 'nx-75': 'photo-top', 'nx-76': 'photo-top', 'nx-77': 'photo-top', 'nx-78': 'photo-top', 'nx-79': 'photo-top', 'nx-80': 'photo-top', 'nx-81': 'photo-top', 'nx-82': 'photo-top', 'nx-83': 'photo-top', 'nx-84': 'photo-top', 'nx-85': 'photo-top', 'nx-86': 'photo-top', 'nx-87': 'photo-top', 'nx-88': 'photo-top', 'nx-89': 'photo-top', 'nx-90': 'photo-top', 'nx-91': 'photo-left', 'nx-92': 'photo-left', 'nx-93': 'photo-left', 'nx-94': 'photo-left', 'nx-95': 'photo-left', 'nx-96': 'photo-top', 'nx-97': 'photo-top', 'nx-98': 'photo-top', 'nx-99': 'photo-top', 'nx-100': 'photo-top'
        };
        var legacyOk = /^(photo-top|photo-full|photo-left|photo-right|photo-float|photo-collage|photo-portrait|photo-editorial)$/.test(layout);
        if (!bases[layout] && !legacyOk) {
            layout = 'nx-01';
        }
        var base = bases[layout] || '';
        card.className = 'property-card mk-l-' + layout + (base && base !== layout ? ' mk-l-' + base : '');

        var detailsUrl =
            'property-details.php?id=' +
            encodeURIComponent(
                ad.id
            );

        /* تنظیمات نمایش کارت (راند ۲۱) */
        var CDRAW = window.MELKINO_CARD_DISPLAY || {};
        var CD = CDRAW.settings || {};
        var CDSPECS = CDRAW.specs || {};
        var cdMode = function (k) {
            var v = CD[k];
            if (v === true) return 'pill';
            if (v === false || v === undefined || v === null) return 'off';
            return String(v);
        };
        var cdShow = function (k) {
            var v = CD[k];
            return !(v === false || v === 'off' || v === undefined || v === null);
        };

        /* =====================================================
           BADGES + FEATURES — رندر پویا از تنظیمات تب «نمایش» (راند ۲۱)
        ====================================================== */
        var CD_SPECIAL = {
            transaction: ' gold',
            property_type: ' gold',
            loan: ' loan',
            exchange: ' exchange',
            key_not_turned: ' key'
        };

        function cdValue(a, k) {
            var pd = a.property_details || a.details || {};
            if (typeof pd === 'string') {
                try { pd = JSON.parse(pd); } catch (e) { pd = {}; }
            }
            var num = function (v) {
                v = Number(v);
                return v > 0 ? v.toLocaleString('en-US') : '';
            };
            switch (k) {
                case 'transaction': {
                    // راند ۲۷: فقط «پیش فروش» به «پیش‌فروش» زیبانویسی می‌شود؛ بقیه عیناً نمایش داده می‌شوند
                    if (!a.transactionType || a.transactionType === 'نامشخص') return '';
                    var __trLabels = { 'پیش فروش': 'پیش‌فروش' };
                    return __trLabels[a.transactionType] || a.transactionType;
                }
                case 'property_type':
                    return (a.propertyType && a.propertyType !== 'نامشخص') ? a.propertyType : '';
                case 'area':
                    return a.area > 0 ? a.area + ' متر' : '';
                case 'rooms':
                    return (a.bedrooms !== '-' && a.bedrooms !== '' && a.bedrooms != null)
                        ? a.bedrooms + ' خواب' : '';
                case 'floor': {
                    var fv = pd.floor || pd.floor_apt || pd.office_floor || '';
                    return fv ? 'طبقه ' + fv : '';
                }
                case 'year': {
                    var yv = pd.year || pd.year_apt || pd.year_villa || pd.office_year || '';
                    return yv ? 'ساخت ' + yv : '';
                }
                case 'building_age': {
                    var yraw = pd.year || pd.year_apt || pd.year_villa || pd.office_year || ad.year || '';
                    var ys = String(yraw).replace(/[۰-۹]/g, function (ch) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(ch)); }).replace(/[٠-٩]/g, function (ch) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(ch)); });
                    var ym = ys.match(/(13|14)\d{2}/);
                    var ynum = ym ? parseInt(ym[0], 10) : parseInt(ys, 10);
                    var jy = window.MELKINO_JALALI_YEAR || 1405;
                    if (!ynum || ynum < 1200) return '';
                    var age = jy - ynum;
                    if (age < 0) age = 0;
                    return String(age).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }) + ' سال';
                }
                case 'parking': return a.parking ? 'پارکینگ' : '';
                case 'elevator': return a.elevator ? 'آسانسور' : '';
                case 'key_not_turned': return a.is_not_keyed ? 'کلید نخورده' : '';
                case 'loan':
                    return (a.has_loan && a.loan_amount && String(a.transactionType || '').indexOf('فروش') !== -1)
                        ? 'وام' : '';
                case 'exchange': return a.exchange_interested ? 'مایل به معاوضه' : '';
                case 'deed': return a.deed_type || pd.document_type || pd.land_deed_type || '';
                case 'deposit': { var dv = num(a.deposit); return dv ? 'رهن ' + dv + ' تومان' : ''; }
                case 'rent_monthly': { var rv = num(a.rent_monthly); return rv ? 'اجاره ' + rv + ' تومان' : ''; }
                case 'full_rent': {
                    var fvv = a.full_rent_enabled ? num(a.full_rent) : '';
                    return fvv ? 'رهن کامل ' + fvv + ' تومان' : '';
                }
                case 'tags': return '';
            }
            if (k.indexOf('pd.') === 0) {
                var dk = k.slice(3);
                var val = pd[dk];
                if (Array.isArray(val)) {
                    val = val.filter(function (x) { return String(x).trim() !== ''; }).join('، ');
                }
                val = (val == null ? '' : String(val).trim());
                if (dk.indexOf('has_') === 0) return val === '1' ? 'دارد' : '';
                if (val === '' || val === '0') return '';
                var d = CDSPECS[k] || {};
                return val + (d.suffix || '');
            }
            return '';
        }

        var cdItems = [];
        Object.keys(CDSPECS).forEach(function (k) {
            var def = CDSPECS[k] || {};
            if ((def.scope || 'both') === 'home') return;
            var mode = cdMode(k);
            if (mode === 'off') return;
            if (k === 'tags') {
                var tg = a_tags(ad);
                tg.forEach(function (t) {
                    t = String(t).trim();
                    if (t) cdItems.push({ key: k, mode: mode, emoji: '', show_icon: false, show_label: false, order: def.order || 0, label: 'برچسب', value: t, cls: '' });
                });
                return;
            }
            var v = cdValue(ad, k);
            if (!v) return;
            cdItems.push({ key: k, mode: mode, emoji: def.emoji || '', show_icon: def.show_icon !== false, show_label: !!def.show_label, order: def.order || 0, label: def.label || k, value: v, cls: CD_SPECIAL[k] || '' });
        });
        cdItems.sort(function (a, b) { return (a.order || 0) - (b.order || 0); });

        function a_tags(a) {
            var t = a.tags;
            if (typeof t === 'string') {
                try { t = JSON.parse(t); } catch (e) { t = t.split(','); }
            }
            return Array.isArray(t) ? t : [];
        }

        var tagsHtml = '';
        var featsHtml = '';
        cdItems.forEach(function (it) {
            var iconBit = '';
            if (it.show_icon !== false) {
                if (typeof window.mkFieldIcon === 'function') {
                    iconBit = window.mkFieldIcon(it.key, it.label, it.emoji) || '';
                }
                if (!iconBit && it.emoji) {
                    iconBit = mkIconOrText41(it.emoji);
                }
                if (iconBit) iconBit += ' ';
            }
            if (it.mode === 'pill') {
                tagsHtml += `
                    <span class="property-badge${it.cls}">
                        ${iconBit + escapeHtml(it.value)}
                    </span>
                `;
            } else {
                var txt = (it.show_label ? it.label + ': ' : '') + it.value;
                featsHtml += `
                    <span class="property-feature${it.key === 'property_type' ? ' primary' : ''}">
                        ${iconBit}${escapeHtml(txt)}
                    </span>
                `;
            }
        });

        /* =====================================================
           IMAGE — با پشتیبانی از لوگو
        ====================================================== */

        var firstImage = getFirstValidImage(ad);

        // راند ۶۴: بدون عکس آپلودشده → عکس تزیینی نوع ملک
        if (!firstImage) { firstImage = mkDefaultImageFor(ad); if (firstImage) { ad.__mkDecor = true; } }
        var imageCandidates = getImageUrlCandidates(
            [
                ad.selected_images,
                ad.selectedImages,
                ad.images,
                ad.photos,
                ad.gallery,
                ad.gallery_images,
                ad.image,
                ad.photo,
                ad.image_url,
                ad.photo_url
            ]
        );

        var imageHtml = '';

        var extraThumbs = [];
        if (layout === 'photo-collage' && firstImage) {
            imageCandidates.forEach(function (u) {
                if (u && extraThumbs.indexOf(u) === -1 && u !== firstImage) extraThumbs.push(u);
            });
            extraThumbs = extraThumbs.slice(0, 2);
            while (extraThumbs.length < 2) extraThumbs.push(firstImage);
        }
        var collageHtml = extraThumbs.map(function (u) {
            return '<img class="mk-g-extra" src="' + escapeHtml(u) + '" alt="" loading="lazy">';
        }).join('');

        if (firstImage) {
            imageHtml = `
                <img
                    src="${escapeHtml(firstImage)}"
                    alt="${escapeHtml(ad.title)}"
                    loading="lazy"
                    data-image-candidates="${escapeHtml(JSON.stringify(imageCandidates))}"
                    onerror="handleCardImageError(this);"
                >
                ${collageHtml}

                ${ad.__mkDecor ? '<span class="mk-decor-badge">عکس تزیینی — مربوط به این ملک نیست</span>' : ''}

                <div
                    class="property-image-placeholder"
                    style="display:none;"
                >

                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="16"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <circle
                            cx="8"
                            cy="9"
                            r="1.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <path
                            d="M3 17L8 12L12 16L15 13L21 19"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />

                    </svg>

                    <span>
                        تصویر در دسترس نیست
                    </span>

                </div>

            `;

        } else {

            imageHtml = `

                <div class="property-image-placeholder">

                    <svg
                        width="36"
                        height="36"
                        viewBox="0 0 24 24"
                        fill="none"
                    >

                        <rect
                            x="3"
                            y="4"
                            width="18"
                            height="16"
                            rx="2"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <circle
                            cx="8"
                            cy="9"
                            r="1.5"
                            stroke="currentColor"
                            stroke-width="1.4"
                        />

                        <path
                            d="M3 17L8 12L12 16L15 13L21 19"
                            stroke="currentColor"
                            stroke-width="1.4"
                            stroke-linejoin="round"
                        />

                    </svg>

                    <span>
                        تصویر ملک
                    </span>

                </div>

            `;

        }


        var pdAmen = ad.property_details || ad.details || {};
        if (typeof pdAmen === 'string') {
            try { pdAmen = JSON.parse(pdAmen); } catch (e) { pdAmen = {}; }
        }
        function amenChip(on, label, svg) {
            return '<span class="mk-amen-chip' + (on ? ' is-on' : '') + '">' + svg + '<i>' + label + '</i></span>';
        }
        var amenRow = '<div class="property-amen-row">' +
            amenChip(mkHasAmenity(ad, pdAmen, ['آسانسور', 'elevator']), 'آسانسور', '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="m9 10 1.5-2L12 10M15 14l-1.5 2L12 14"/></svg>') +
            amenChip(mkHasAmenity(ad, pdAmen, ['پارکینگ', 'parking']), 'پارکینگ', '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3"/></svg>') +
            amenChip(mkHasAmenity(ad, pdAmen, ['انباری', 'انبار', 'storage']), 'انباری', '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16v13H4z"/><path d="M4 7 12 3l8 4"/><path d="M12 7v13"/></svg>') +
            '</div>';

        /* =====================================================
           CARD
        ====================================================== */

        card.innerHTML = `

            <div class="property-image">

                ${imageHtml}

                ${mkCardWatermarkHtml(!firstImage)}

                ${
                    tagsHtml
                        ? `
                            <div class="property-badges">
                                ${tagsHtml}
                            </div>
                        `
                        : ''
                }

            </div>


            <div class="property-content">

                ${cdShow('code') ? `<div class="property-code">

                    کد ملک:
                    ${escapeHtml(ad.id)}

                </div>` : ''}


                <div class="property-title-row">

                    ${cdShow('title') ? `<div class="property-title">

                        ${escapeHtml(
                            ad.title
                        )}

                    </div>` : ''}


                    ${cdShow('price') ? `<div class="property-price">

                        ${displayPrice(ad)}

                    </div>` : ''}

                </div>
                ${mkMelkinoStars(ad)}


                ${cdShow('location') ? `<div class="property-location">

                    ${mkIconOrText41('📍')}
                    ${escapeHtml(
                        ad.location
                    )}

                </div>` : ''}


                <div class="property-features">
                    ${featsHtml}
                </div>
                ${amenRow}

                <div class="property-footer">

                    ${cdShow('date') && (ad.created_at || ad.date) ? `<span class="property-date">${escapeHtml(String(ad.created_at || ad.date).replace('T', ' ').slice(0, 16))}</span>` : ''}

                    ${cdShow('details_link') ? `<a
                        href="${detailsUrl}"
                        class="property-detail"
                    >
                        مشاهده جزئیات
                    </a>` : '<span></span>'}


                    <button
                        type="button"
                        class="property-like"
                        data-fav-toggle="${escapeHtml(ad.id)}"
                        title="افزودن به علاقه‌مندی‌ها"
                        aria-label="افزودن به علاقه‌مندی‌ها"
                        aria-pressed="false"
                    >

                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                        >

                            <path
                                d="
                                    M20.84 4.61
                                    a5.5 5.5 0 0 0-7.78 0
                                    L12 5.67
                                    l-1.06-1.06
                                    a5.5 5.5 0 0 0-7.78 7.78
                                    l1.06 1.06
                                    L12 21.23
                                    l7.78-7.78
                                    1.06-1.06
                                    a5.5 5.5 0 0 0 0-7.78z
                                "
                            ></path>

                        </svg>

                    </button>


                    <button
                        type="button"
                        class="property-like property-compare"
                        data-compare-add="${escapeHtml(ad.id)}"
                        aria-label="افزودن به مقایسه"
                        title="افزودن به مقایسه"
                    >
                        ${mkIconOrText41('⚖️')}
                    </button>


                    <button
                        type="button"
                        class="property-like property-share"
                        data-share-ad="${escapeHtml(ad.id)}"
                        data-share-title="${escapeHtml(ad.title)}"
                        aria-label="اشتراک‌گذاری آگهی"
                        title="اشتراک‌گذاری آگهی"
                    >

                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.7"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >

                            <circle cx="18" cy="5" r="3"></circle>
                            <circle cx="6" cy="12" r="3"></circle>
                            <circle cx="18" cy="19" r="3"></circle>
                            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>

                        </svg>

                    </button>

                </div>

            </div>

        `;

        return card;

    }

    window.mkNormalizeAd = normalizeAd;
    window.mkCreatePropertyCard = createPropertyCard;
    window.mkHandleCardImageError = window.handleCardImageError;

    window.mkRenderAdCards = function (container, ads, opts) {
        if (!container) return;
        var listLayout = String((window.MELKINO_STUDIO && window.MELKINO_STUDIO.layout) || window.MELKINO_CARD_LAYOUT || 'photo-top');
        var listAlias = { 'photo-top': 'nx-01', 'photo-full': 'nx-31', 'photo-left': 'nx-26', 'photo-right': 'nx-21', 'photo-float': 'nx-46', 'photo-collage': 'nx-96', 'photo-portrait': 'nx-76', 'photo-editorial': 'nx-41' };
        if (listAlias[listLayout]) listLayout = listAlias[listLayout];
        container.setAttribute('data-card-layout', listLayout);
        container.innerHTML = '';
        var frag = document.createDocumentFragment();
        (ads || []).forEach(function (raw) {
            try {
                var card = createPropertyCard(normalizeAd(raw));
                if (card) frag.appendChild(card);
            } catch (err) {}
        });
        container.appendChild(frag);
        if (typeof window.mkPhotoWmRefreshAll === 'function') {
            window.mkPhotoWmRefreshAll(container);
        } else if (typeof window.mkPhotoWmMount === 'function') {
            container.querySelectorAll('.property-image').forEach(function (box) {
                if (box.querySelector('img')) window.mkPhotoWmMount(box, 'card');
            });
        }
    };

    function mkRerenderHost(ads) {
        var host = document.getElementById('mkHomeAdsList')
            || document.getElementById('mkVipAdsList')
            || document.querySelector('.properties-list');
        if (!host || !window.mkRenderAdCards) return;
        window.mkRenderAdCards(host, ads || window.MELKINO_HOME_ADS || window.MELKINO_VIP_ADS || []);
    }

    window.addEventListener('message', function (e) {
        if (!e || e.origin !== window.origin || e.source !== window.parent || window.parent === window) return;
        var d = e.data;
        if (!d) return;
        if (d.type === 'melkino-studio-preview' && d.theme) {
            var previewMode=d.theme.mode==='system'?(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):d.theme.mode;
            if(previewMode==='dark'||previewMode==='light')document.documentElement.setAttribute('data-theme',previewMode);
            window.MELKINO_STUDIO = d.theme;
            window.MELKINO_CARD_LAYOUT = d.theme.layout || window.MELKINO_CARD_LAYOUT;
            var st = document.getElementById('mk-studio-runtime');
            if (!st) {
                st = document.createElement('style');
                st.id = 'mk-studio-runtime';
                document.head.appendChild(st);
            }
            if (d.css) st.textContent = d.css;
            mkRerenderHost(window.MELKINO_HOME_ADS || []);
            return;
        }
        if (d.type !== 'melkino-card-display' || !d.payload) return;
        window.MELKINO_CARD_DISPLAY = d.payload;
        var ads = window.MELKINO_HOME_ADS || window.MELKINO_VIP_ADS || [];
        if (d.adId) {
            ads = ads.filter(function (a) { return String(a.id) === String(d.adId); });
        }
        mkRerenderHost(ads);
    });

})();
