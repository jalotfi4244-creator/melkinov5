function initMapTab() {
    var csrf = (window.MELKINO_CSRF || (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    function j(url, opt) {
        opt = opt || {};
        opt.credentials = 'same-origin';
        opt.headers = Object.assign({ 'X-CSRF-Token': csrf }, opt.headers || {});
        return fetch(url, opt).then(function (r) { return r.json(); });
    }
    j('map-api.php?action=admin_stats').then(function (d) {
        if (!d || !d.success) return;
        document.getElementById('mpStatWith').textContent = d.with_location;
        document.getElementById('mpStatWithout').textContent = d.without_location;
        document.getElementById('mpStatOn').textContent = d.on_map;
        document.getElementById('mpStatSell').textContent = d.sell;
        document.getElementById('mpStatRent').textContent = d.rent;
    });
    j('map-api.php?action=admin_settings').then(function (d) {
        if (!d || !d.settings) return;
        var s = d.settings;
        document.getElementById('mpEnabled').checked = !!s.enabled;
        document.getElementById('mpRequire').checked = !!s.require_location;
        document.getElementById('mpGps').checked = !!s.gps_enabled;
        document.getElementById('mpCircle').checked = !!s.show_privacy_circle;
        document.getElementById('mpMarkers').checked = !!s.show_markers;
        document.getElementById('mpCluster').checked = !!s.clustering;
        document.getElementById('mpRadius').value = s.privacy_radius;
        document.getElementById('mpMax').value = s.max_results;
        document.getElementById('mpZoom').value = s.min_zoom;
        document.getElementById('mpProvider').value = s.provider || 'osm';
        var box = document.getElementById('mpColors');
        box.innerHTML = '<strong>رنگ Marker</strong>';
        Object.keys(s.marker_colors || {}).forEach(function (k) {
            var row = document.createElement('label');
            row.style.display = 'block';
            row.innerHTML = k + ' <input type="color" data-pt="' + k + '" value="' + (s.marker_colors[k] || '#0e7c6e') + '">';
            box.appendChild(row);
        });
    });
    document.querySelectorAll('[data-r]').forEach(function (b) {
        b.onclick = function () { document.getElementById('mpRadius').value = b.getAttribute('data-r'); };
    });
    document.getElementById('mpSave').onclick = function () {
        var colors = {};
        document.querySelectorAll('#mpColors input[type=color]').forEach(function (i) {
            colors[i.getAttribute('data-pt')] = i.value;
        });
        var fd = new FormData();
        fd.append('action', 'admin_settings');
        fd.append('csrf_token', csrf);
        fd.append('enabled', document.getElementById('mpEnabled').checked ? '1' : '0');
        fd.append('require_location', document.getElementById('mpRequire').checked ? '1' : '0');
        fd.append('gps_enabled', document.getElementById('mpGps').checked ? '1' : '0');
        fd.append('show_privacy_circle', document.getElementById('mpCircle').checked ? '1' : '0');
        fd.append('show_markers', document.getElementById('mpMarkers').checked ? '1' : '0');
        fd.append('clustering', document.getElementById('mpCluster').checked ? '1' : '0');
        fd.append('privacy_radius', document.getElementById('mpRadius').value);
        fd.append('max_results', document.getElementById('mpMax').value);
        fd.append('min_zoom', document.getElementById('mpZoom').value);
        fd.append('provider', document.getElementById('mpProvider').value);
        fd.append('marker_colors', JSON.stringify(colors));
        j('map-api.php?action=admin_settings', { method: 'POST', body: fd }).then(function (d) {
            document.getElementById('mpMsg').textContent = d.success ? 'ذخیره شد.' : (d.message || 'خطا');
        });
    };
    j('map-api.php?action=admin_list&missing=1').then(function (d) {
        var el = document.getElementById('mpMissing');
        var rows = (d && d.items) || [];
        el.innerHTML = rows.length ? rows.map(function (r) {
            return '<div style="padding:8px 0;border-bottom:1px solid var(--border)">' + (r.id || '') + ' — ' + (r.title || '') + ' (' + (r.status || '') + ')</div>';
        }).join('') : '<p>همه فایل‌ها موقعیت دارند یا فایلی نیست.</p>';
    });
    j('map-api.php?action=admin_logs').then(function (d) {
        var el = document.getElementById('mpLogs');
        var rows = (d && d.items) || [];
        el.innerHTML = rows.length ? rows.map(function (r) {
            return '<div>' + (r.created_at || '') + ' · ' + (r.ad_id || '') + ' · ' + (r.change_source || '') + '</div>';
        }).join('') : 'سابقه‌ای نیست.';
    });
}
if (document.getElementById('tab-map')) {
    document.querySelectorAll('.tab-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            if ((b.getAttribute('onclick') || '').indexOf('map') !== -1) setTimeout(initMapTab, 50);
        });
    });
}
