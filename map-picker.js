(function () {
    function boot() {
        var box = document.getElementById('mkLocMap');
        if (!box || !window.L) return;
        var latEl = document.getElementById('map_lat');
        var lngEl = document.getElementById('map_lng');
        var srcEl = document.getElementById('map_source');
        var accEl = document.getElementById('map_accuracy');
        var cfg = window.MELKINO_MAP_PICKER || { lat: 36.4182, lng: 54.9763, gps: true };
        var start = [parseFloat(latEl && latEl.value) || cfg.lat, parseFloat(lngEl && lngEl.value) || cfg.lng];
        var map = L.map(box).setView(start, latEl && latEl.value ? 16 : 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(map);
        var marker = L.marker(start, { draggable: true }).addTo(map);
        function set(lat, lng, src, acc) {
            marker.setLatLng([lat, lng]);
            if (latEl) latEl.value = String(lat);
            if (lngEl) lngEl.value = String(lng);
            if (srcEl) srcEl.value = src || 'map';
            if (accEl) accEl.value = acc || '';
        }
        map.on('click', function (e) { set(e.latlng.lat, e.latlng.lng, 'map'); });
        marker.on('dragend', function () {
            var p = marker.getLatLng();
            set(p.lat, p.lng, 'map');
        });
        var gpsBtn = document.getElementById('mkLocGps');
        if (gpsBtn) {
            if (!cfg.gps) gpsBtn.style.display = 'none';
            gpsBtn.onclick = function () {
                if (!navigator.geolocation) {
                    alert('موقعیت‌یاب روی این دستگاه در دسترس نیست. روی نقشه لمس کنید.');
                    return;
                }
                navigator.geolocation.getCurrentPosition(function (pos) {
                    set(pos.coords.latitude, pos.coords.longitude, 'gps', String(pos.coords.accuracy || ''));
                    map.setView([pos.coords.latitude, pos.coords.longitude], 17);
                }, function () {
                    alert('اجازه موقعیت داده نشد. می‌توانید Marker را روی نقشه جابه‌جا کنید.');
                }, { enableHighAccuracy: true, timeout: 12000 });
            };
        }
        setTimeout(function () { map.invalidateSize(); }, 400);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
