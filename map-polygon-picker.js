(function () {
    function boot() {
        var box = document.getElementById('mkPolyMap');
        var input = document.getElementById('map_poly');
        if (!box || !window.L) return;
        var pts = [];
        try { pts = JSON.parse(input && input.value ? input.value : '[]') || []; } catch (e) { pts = []; }
        var map = L.map(box).setView([36.4182, 54.9763], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OSM' }).addTo(map);
        var layer = L.layerGroup().addTo(map);
        var poly = null;
        function sync() {
            layer.clearLayers();
            if (poly) { map.removeLayer(poly); poly = null; }
            pts.forEach(function (p, i) {
                var m = L.marker([p.lat, p.lng]).addTo(layer);
                m.bindTooltip(String(i + 1), { permanent: true, direction: 'top' });
                // کلیک روی مارکر → حذف همان نقطه (خواستهٔ کاربر)
                m.on('click', function (ev) {
                    if (ev && ev.originalEvent) { L.DomEvent.stopPropagation(ev.originalEvent); }
                    pts.splice(i, 1);
                    sync();
                });
                m.on('mouseover', function () { m.unbindTooltip(); m.bindTooltip('✕ حذف', { permanent: true, direction: 'top' }); });
                m.on('mouseout', function () { m.unbindTooltip(); m.bindTooltip(String(i + 1), { permanent: true, direction: 'top' }); });
            });
            if (pts.length >= 3) {
                poly = L.polygon(pts.map(function (p) { return [p.lat, p.lng]; }), {
                    color: '#d4af37', weight: 2, fillOpacity: 0.18
                }).addTo(map);
            }
            if (input) input.value = JSON.stringify(pts);
            var st = document.getElementById('mkPolyStatus');
            var stTxt = pts.length >= 4 ? 'محدوده کامل شد. می‌توانید ثبت کنید.' : ('نقطه ' + (pts.length + 1) + ' از ۴ را روی نقشه بزنید.');
            if (pts.length > 0) stTxt += ' برای حذف هر نقطه، روی همان نقطه کلیک کنید.';
            if (st) st.textContent = stTxt;
        }
        map.on('click', function (e) {
            if (pts.length >= 4) return;
            pts.push({ lat: e.latlng.lat, lng: e.latlng.lng });
            sync();
        });
        var reset = document.getElementById('mkPolyReset');
        if (reset) reset.onclick = function () { pts = []; sync(); };
        sync();
        setTimeout(function () { map.invalidateSize(); }, 400);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
