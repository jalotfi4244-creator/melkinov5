(function () {
    var cfg = window.MELKINO_MAP || {};
    if (!cfg.enabled) {
        var el = document.getElementById('mkMapCanvas');
        if (el) el.innerHTML = '<p style="padding:40px;text-align:center">نقشه املاک فعلاً غیرفعال است.</p>';
        return;
    }
    if (!window.L) return;
    var map = L.map('mkMapCanvas').setView([cfg.center_lat || 36.4182, cfg.center_lng || 54.9763], 13);
    L.tileLayer(cfg.tile_url || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: cfg.tile_attr || '© OSM',
        maxZoom: 19
    }).addTo(map);
    var layer = L.layerGroup();
    map.addLayer(layer);
    var itemsById = Object.create(null);
    var markersById = Object.create(null);

    // ---- مارکر طراحی‌پذیر (شکل/رنگ/اندازه از استودیو طراحی) ----
    var THEME = window.MELKINO_MAP_THEME || {};
    var MK = THEME.marker || {};
    var MCARD = THEME.mapCard || {};
    var THEME_COLORS = THEME.colors || {};

    function markerLabel(it) {
        if (MK.label === 'price') return it.price || '';
        if (MK.label === 'type') return it.type || '';
        return '';
    }

    var TYPE_COLORS = (cfg.marker_colors || {});
    var VIP_COLOR = MK.vipColor || '#D4AF37';

    function pinColor(it) {
        if (it.is_vip) return VIP_COLOR;
        var c = TYPE_COLORS[String(it.type || '').trim()];
        if (!c) {
            // تطابق نرم: «ویلایی/ویلا» و کلیدهای مشابه
            var keys = Object.keys(TYPE_COLORS);
            for (var i = 0; i < keys.length; i++) {
                if (keys[i] && String(it.type || '').indexOf(keys[i]) !== -1) { c = TYPE_COLORS[keys[i]]; break; }
            }
        }
        return c || MK.color || it.color || THEME_COLORS.primary || '#0e7c6e';
    }

    function pinIcon(it) {
        var color = pinColor(it);

        // اگر هِلپر مشترک لود نشده بود، همان پین قدیمی نمایش داده می‌شود
        if (!window.MelkinoMarker) {
            return L.divIcon({
                className: 'mk-pin-wrap',
                html: '<span class="mk-pin" style="background:' + color + '"></span>',
                iconSize: [28, 36], iconAnchor: [14, 34], popupAnchor: [0, -28]
            });
        }
        var m = window.MelkinoMarker.metrics(MK.size);
        return L.divIcon({
            className: 'mk-pin-wrap',
            html: window.MelkinoMarker.html({
                shape: MK.shape || 'pin',
                color: color,
                stroke: MK.stroke || '#FFFFFF',
                size: MK.size || 34,
                shadow: MK.shadow !== false,
                pulse: !!MK.pulse,
                label: markerLabel(it)
            }),
            iconSize: m.size,
            iconAnchor: m.anchor,
            popupAnchor: m.popup
        });
    }

    var txFilter = '';
    var ptFilter = '';

    function qs() {
        var b = map.getBounds();
        var p = new URLSearchParams();
        p.set('action', 'properties');
        p.set('north', b.getNorth());
        p.set('south', b.getSouth());
        p.set('east', b.getEast());
        p.set('west', b.getWest());
        if (txFilter) p.set('transaction_type', txFilter);
        if (ptFilter) p.set('property_type', ptFilter);
        return p.toString();
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function icon(name) {
        var paths = {
            heart: '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
            compare: '<path d="M12 3v18M6 7h12M8 21h8M6 7l-3 6a3 3 0 0 0 6 0L6 7Zm12 0-3 6a3 3 0 0 0 6 0l-3-6Z"/>',
            share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/>',
            image: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="2"/><path d="m3 17 6-6 5 5 3-3 4 4"/>',
            close: '<path d="m6 6 12 12M6 18 18 6"/>'
        };
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths[name] + '</svg>';
    }
    function feature(value, suffix) {
        return value !== null && value !== undefined && String(value).trim() !== '' ? '<span>' + esc(value) + ' ' + suffix + '</span>' : '';
    }
    function sheetHtml(it) {
        var detail = 'property-details.php?id=' + encodeURIComponent(it.id);
        var area = Number(String(it.area || '').replace(/,/g, ''));
        var features = (isFinite(area) && area > 0 ? feature(new Intl.NumberFormat('fa-IR', {maximumFractionDigits: 1}).format(area), 'متر') : '') +
            feature(it.rooms, 'اتاق') + (it.floor !== '' && it.floor != null ? feature('طبقه ' + it.floor, '') : '') +
            (it.year ? feature('ساخت ' + it.year, '') : feature(it.building_age, 'سال بنا'));
        var amenities = (Array.isArray(it.amenities) ? it.amenities : []).map(function (a) { return '<span>' + esc(a) + '</span>'; }).join('');
        var showImage = MCARD.showImage !== false && MCARD.imageHeight !== 0;
        return '<article class="mk-map-compact-card" data-ad-id="' + esc(it.id) + '">' +
            '<header class="mk-map-card-head"><div class="mk-map-card-badges"><span class="mk-sheet-tx">' + esc(it.transaction_type || 'ملک') + '</span>' +
            (it.is_vip ? '<span class="mk-map-vip">ویژه</span>' : '') + '<span class="mk-map-code">کد: ' + esc(it.code || it.id) + '</span></div>' +
            '<button type="button" class="mk-map-sheet-close" id="mkMapSheetClose" aria-label="بستن کارت ملک" title="بستن کارت">' + icon('close') + '</button></header>' +
            '<div class="mk-map-sheet-body">' + (showImage ? '<a class="mk-map-media" href="' + detail + '" aria-label="مشاهده تصاویر ملک">' +
            (it.thumbnail ? '<img class="mk-map-thumb" src="' + esc(it.thumbnail) + '" alt="' + esc(it.title || 'ملک') + '" width="72" height="72" loading="lazy">' : '<span class="mk-map-no-image">' + icon('image') + '<small>بدون تصویر</small></span>') + '</a>' : '') +
            '<div class="mk-map-sheet-copy"><a class="mk-sheet-title" href="' + detail + '" title="' + esc(it.title) + '">' + esc(it.title || 'ملک') + '</a>' +
            (MCARD.showKind !== false ? '<p class="mk-map-kind">' + esc(it.type) + (it.location ? ' · ' + esc(it.location) : '') + '</p>' : '') +
            '<div class="mk-sheet-meta">' + features + '</div></div></div>' +
            (MCARD.showAmen !== false && amenities ? '<div class="mk-map-amenities" aria-label="امکانات ملک" tabindex="0">' + amenities + '</div>' : '') +
            (MCARD.showPrice !== false ? '<p class="mk-map-price">' + (it.price_hidden ? 'برای استعلام قیمت تماس بگیرید' : it.price ? esc(it.price) + (String(it.price).indexOf('تومان') !== -1 ? '' : ' <small>تومان</small>') : 'قیمت توافقی / تماس بگیرید') + '</p>' : '') +
            '<div class="mk-map-card-actions">' +
            (MCARD.showButton !== false ? '<a class="mk-map-card-detail" href="' + detail + '">' + esc(MCARD.buttonText || 'مشاهده جزئیات') + '</a>' : '') +
            '<button type="button" class="mk-map-action" data-map-action="favorite" data-map-id="' + esc(it.id) + '" aria-label="افزودن به علاقه‌مندی‌ها" title="افزودن به علاقه‌مندی‌ها" aria-pressed="false" disabled>' + icon('heart') + '</button>' +
            '<button type="button" class="mk-map-action" data-map-action="compare" data-map-id="' + esc(it.id) + '" aria-label="افزودن به مقایسه" title="افزودن به مقایسه" aria-pressed="false" disabled>' + icon('compare') + '</button>' +
            '<button type="button" class="mk-map-action" data-share-ad="' + esc(it.id) + '" data-share-title="' + esc(it.title) + '" title="اشتراک‌گذاری آگهی" aria-label="اشتراک‌گذاری آگهی">' + icon('share') + '</button></div>' +
            '<p class="mk-map-card-status" id="mkMapCardStatus" role="status" aria-live="polite" hidden></p></article>';
    }
    // Same server-owned favorites/compare stores as the all-listings cards. Namespaced
    // controls prevent duplicate legacy/V2 click handlers (and support string ad IDs).
    var actionCache = {favorite: Object.create(null), compare: Object.create(null)}, actionBusy = Object.create(null), actionEpoch = {favorite: 0, compare: 0};
    function feedback(id, text) {
        if (String(openItemId) !== String(id)) return;
        var el = document.getElementById('mkMapCardStatus');
        if (el) { el.textContent = text; el.hidden = !text; }
    }
    function paintAction(kind, id) {
        if (String(openItemId) !== String(id)) return;
        var btn = document.querySelector('#mkMapSheet [data-map-action="' + kind + '"]');
        if (!btn) return;
        var known = typeof actionCache[kind][id] === 'boolean', on = actionCache[kind][id] === true;
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.classList.toggle('is-active', on);
        btn.title = (on ? 'حذف از ' : 'افزودن به ') + (kind === 'favorite' ? 'علاقه‌مندی‌ها' : 'مقایسه');
        btn.setAttribute('aria-label', btn.title);
        btn.disabled = !!actionBusy[kind + ':' + id] || !known;
    }
    function actionRequest(url, options) {
        return fetch(url, Object.assign({credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}}, options || {}))
            .then(function (res) { return res.json().then(function (d) { if (!res.ok || !d.success) throw Object.assign(new Error(d.message || 'عملیات انجام نشد؛ دوباره تلاش کنید.'), {userMessage: d.message || 'عملیات انجام نشد؛ دوباره تلاش کنید.'}); return d; }); });
    }
    function syncActions(id) {
        ['favorite', 'compare'].forEach(function (kind) {
            paintAction(kind, id);
            var epoch = actionEpoch[kind];
            var url = kind === 'favorite' ? 'favorites.php?legacy=1&action=list' : 'compare.php?action=ids';
            actionRequest(url).then(function (d) {
                if (epoch !== actionEpoch[kind] || actionBusy[kind + ':' + id]) return;
                var ids = kind === 'favorite' ? (d.favorites || []).map(function (f) { return String(f.id); }) : (d.ids || []).map(String);
                actionCache[kind][id] = ids.indexOf(String(id)) !== -1;
                paintAction(kind, id);
            }).catch(function () { feedback(id, 'وضعیت ذخیره‌ها دریافت نشد؛ اتصال را بررسی کنید و کارت را دوباره باز کنید.'); });
        });
    }
    document.getElementById('mkMapSheet').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-map-action]');
        if (!btn || btn.disabled) return;
        e.preventDefault();
        var kind = btn.getAttribute('data-map-action'), id = btn.getAttribute('data-map-id'), key = kind + ':' + id;
        if (actionBusy[key]) return;
        actionBusy[key] = true; actionEpoch[kind]++; paintAction(kind, id); feedback(id, '');
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = window.MELKINO_CSRF || (meta && meta.content) || '';
        var url = kind === 'favorite' ? 'favorites.php?legacy=1&action=toggle' : 'compare.php?action=toggle';
        actionRequest(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-Token': token}, body: 'ad_id=' + encodeURIComponent(id)})
            .then(function (d) {
                var on = kind === 'favorite' ? d.favorited === true : !d.removed;
                actionCache[kind][id] = on;
                feedback(id, (on ? 'به ' : 'از ') + (kind === 'favorite' ? 'علاقه‌مندی‌ها' : 'مقایسه') + (on ? ' اضافه شد.' : ' حذف شد.'));
                if (kind === 'favorite') document.dispatchEvent(new CustomEvent('melkino:favorites-changed'));
                else window.dispatchEvent(new CustomEvent('melkino:compare-changed'));
            }).catch(function (err) {
                delete actionCache[kind][id];
                feedback(id, err.userMessage || 'پاسخ سرور دریافت نشد؛ وضعیت را دوباره بررسی می‌کنیم.');
            }).finally(function () {
                actionBusy[key] = false; paintAction(kind, id);
                if (typeof actionCache[kind][id] !== 'boolean') syncActions(id);
            });
    });

    var openItemId = null;
    function closeSheet() {
        var sh = document.getElementById('mkMapSheet');
        if (sh) sh.hidden = true;
        openItemId = null;
    }
    function openItem(id) {
        var it = itemsById[id];
        if (!it) return;
        var sh = document.getElementById('mkMapSheet');
        // کلیک دوباره روی همان مارکر → بستن کارت
        if (openItemId === id && sh && !sh.hidden) {
            closeSheet();
            return;
        }
        openItemId = id;
        sh.setAttribute('data-pos', MCARD.pos || 'bottom');
        sh.setAttribute('data-skin', MCARD.skin || 'classic');
        sh.hidden = false;
        sh.innerHTML = sheetHtml(it);
        var img = sh.querySelector('.mk-map-thumb');
        if (img) img.addEventListener('error', function () {
            img.parentNode.innerHTML = '<span class="mk-map-no-image">' + icon('image') + '<small>بدون تصویر</small></span>';
        }, {once: true});
        syncActions(id);
        var closer = document.getElementById('mkMapSheetClose');
        if (closer) closer.onclick = closeSheet;
        var m = markersById[id];
        if (m && m.getLatLng) map.panTo(m.getLatLng());
    }

    function render(items) {
        layer.clearLayers();
        itemsById = Object.create(null);
        markersById = Object.create(null);
        items.forEach(function (it) {
            itemsById[it.id] = it;
            var loc = it.public_location;
            if (!loc || !cfg.show_markers) return;
            var mk = L.marker([loc.latitude, loc.longitude], { icon: pinIcon(it), title: String(it.title || 'ملک'), alt: String(it.title || 'ملک') });
            mk.on('add', function () {
                var el = mk.getElement();
                if (el) { el.setAttribute('data-map-ad', String(it.id)); el.setAttribute('aria-label', 'نمایش کارت ' + (it.title || 'ملک')); }
            });
            mk.on('click', function () { openItem(it.id); });
            layer.addLayer(mk);
            markersById[it.id] = mk;
        });
    }

    var loadVersion = 0;
    function load() {
        var version = ++loadVersion;
        fetch('map-api.php?' + qs(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (version !== loadVersion) return;
                if (d && d.disabled) {
                    document.getElementById('mkMapCanvas').innerHTML = '<p style="padding:40px;text-align:center">نقشه غیرفعال است.</p>';
                    return;
                }
                render((d && d.items) || []);
                if (openItemId !== null && !itemsById[openItemId]) closeSheet();
            })
            .catch(function () {});
    }

    map.on('click', function () { closeSheet(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

    var t = null;
    map.on('moveend', function () {
        clearTimeout(t);
        t = setTimeout(load, 250);
    });

    function bindChips(root, attr, apply) {
        if (!root) return;
        root.addEventListener('click', function (e) {
            var b = e.target.closest('[' + attr + ']');
            if (!b) return;
            root.querySelectorAll('.mk-chip').forEach(function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
            closeSheet();
            apply(b.getAttribute(attr) || '');
            load();
        });
    }
    bindChips(document.getElementById('mkMapTx'), 'data-tx', function (v) { txFilter = v; });
    bindChips(document.getElementById('mkMapTypes'), 'data-pt', function (v) {
        ptFilter = v;
        var sm = document.getElementById('mkMapTypesSummary');
        if (sm) sm.textContent = 'نوع ملک: ' + (v || 'همه');
        var box = document.getElementById('mkMapTypes');
        if (box) box.open = false;
    });

    load();
})();
