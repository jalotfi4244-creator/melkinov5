(function () {
    // نسخه‌ی Leaflet 1.9.4 همراه بسته است (assets/vendor/leaflet). اول همان را لود می‌کنیم
    // و اگر نشد از CDN استفاده می‌کنیم. قبلاً فقط CDN بود؛ اگر CDN بالا نمی‌آمد نقشه بی‌صدا
    // نشان داده نمی‌شد.
    function showMapLoadError() {
        ['editAdMap', 'adminAdsMap'].forEach(function (id) {
            var box = document.getElementById(id);
            if (box && !box.querySelector('.leaflet-container') && !box.childElementCount) {
                box.textContent = 'نقشه بارگذاری نشد. اتصال یا فایل‌های نقشه را بررسی کنید.';
                box.style.display = 'flex';
                box.style.alignItems = 'center';
                box.style.justifyContent = 'center';
                box.style.fontSize = '13px';
                box.style.color = '#8a5a00';
            }
        });
    }
    function loadLeaflet(cb) {
        if (window.L) { cb(); return; }
        if (!document.getElementById('mkLeafletCss')) {
            var css = document.createElement('link');
            css.id = 'mkLeafletCss';
            css.rel = 'stylesheet';
            css.href = 'assets/vendor/leaflet/leaflet.css';
            document.head.appendChild(css);
        }
        var s = document.createElement('script');
        s.src = 'assets/vendor/leaflet/leaflet.js';
        s.onload = cb;
        s.onerror = function () {
            var c2 = document.createElement('link');
            c2.rel = 'stylesheet';
            c2.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            document.head.appendChild(c2);
            var s2 = document.createElement('script');
            s2.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            s2.onload = cb;
            s2.onerror = showMapLoadError;
            document.head.appendChild(s2);
        };
        document.head.appendChild(s);
    }
    var overview;
    function drawOverview() {
        var el = document.getElementById('adminAdsMap');
        if (!el || !window.L) return;
        if (overview) { overview.remove(); overview = null; }
        overview = L.map(el).setView([36.4182, 54.9763], 12);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(overview);
        var list = window.adsData || (typeof adsData !== 'undefined' ? adsData : []);
        list.forEach(function (ad) {
            var lat = parseFloat(ad.latitude);
            var lng = parseFloat(ad.longitude);
            if (!isFinite(lat) || !isFinite(lng)) return;
            var m = L.marker([lat, lng]).addTo(overview);
            m.bindTooltip((ad.title || ad.id || '') + '');
            m.on('click', function () {
                if (typeof openAdEditModal === 'function') openAdEditModal(ad.id);
            });
        });
        setTimeout(function () { overview.invalidateSize(); }, 300);
    }
    window.mkInitAdminAdsMap = function () { loadLeaflet(drawOverview); };
    window.mkInitAdminEditMap = function (ad) {
        loadLeaflet(function () {
            var box = document.getElementById('editAdMap');
            var latEl = document.getElementById('editAdLat');
            var lngEl = document.getElementById('editAdLng');
            if (!box || !window.L) return;
            var lat = parseFloat((ad && ad.latitude) || (latEl && latEl.value) || 36.4182);
            var lng = parseFloat((ad && ad.longitude) || (lngEl && lngEl.value) || 54.9763);
            var has = ad && isFinite(parseFloat(ad.latitude)) && isFinite(parseFloat(ad.longitude));
            var map = L.map(box).setView([lat, lng], has ? 16 : 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
            var marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            function set(a, b) {
                marker.setLatLng([a, b]);
                if (latEl) latEl.value = String(a);
                if (lngEl) lngEl.value = String(b);
            }
            if (has) set(lat, lng);
            map.on('click', function (e) { set(e.latlng.lat, e.latlng.lng); });
            marker.on('dragend', function () {
                var p = marker.getLatLng();
                set(p.lat, p.lng);
            });
            setTimeout(function () { map.invalidateSize(); }, 250);
        });
    };
    if (document.getElementById('adminAdsMap')) {
        loadLeaflet(drawOverview);
    }
})();
