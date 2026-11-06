/**
 * هِلپر مشترک مارکر نقشه — همان شکل‌هایی که map-markers.php تعریف می‌کند.
 * شکل‌ها از سرور در window.MELKINO_MARKER_SHAPES تزریق می‌شوند تا
 * تعریف در دو جا تکرار نشود.
 */
(function () {
    'use strict';

    var SHAPES = window.MELKINO_MARKER_SHAPES || {};

    function esc(v, fallback) {
        return /^#[0-9A-Fa-f]{3,8}$/.test(String(v || '')) ? String(v) : fallback;
    }

    var API = {
        keys: function () { return Object.keys(SHAPES); },
        label: function (k) { return (SHAPES[k] && SHAPES[k].fa) || k; },

        /** فقط رشتهٔ SVG */
        svg: function (shape, color, stroke) {
            var row = SHAPES[shape] || SHAPES.pin;
            if (!row) return '';
            return String(row.svg)
                .split('%COLOR%').join(esc(color, '#0E7C6E'))
                .split('%STROKE%').join(esc(stroke, '#FFFFFF'));
        },

        /** HTML کامل مارکر با اندازه، سایه، ضربان و برچسب اختیاری */
        html: function (opt) {
            opt = opt || {};
            var size = Math.max(18, Math.min(64, parseInt(opt.size, 10) || 34));
            var cls = 'mk-mk' +
                (opt.shadow === false ? '' : ' has-shadow') +
                (opt.pulse ? ' is-pulse' : '');
            var label = opt.label ? '<b class="mk-mk-label">' + String(opt.label)
                .replace(/[&<>"]/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                }) + '</b>' : '';
            return '<span class="' + cls + '" style="width:' + size + 'px;height:' + Math.round(size * 42 / 32) + 'px">' +
                API.svg(opt.shape, opt.color, opt.stroke) + label + '</span>';
        },

        /** ابعاد و نقطهٔ اتصال برای L.divIcon */
        metrics: function (size) {
            var w = Math.max(18, Math.min(64, parseInt(size, 10) || 34));
            var h = Math.round(w * 42 / 32);
            return { size: [w, h], anchor: [Math.round(w / 2), h - 2], popup: [0, -h + 6] };
        }
    };

    window.MelkinoMarker = API;
})();
