<?php
if (!empty($melkinoJalaliPickerLoaded)) return;
$melkinoJalaliPickerLoaded = true;
?>
<!-- =========================================================
     راند ۲۷: تقویم شمسی «خودکفا» (بدون jQuery و CDN خارجی).
     قبلاً persian-datepicker از jsdelivr بارگذاری می‌شد؛ وقتی CDN
     مسدود/کند بود تقویم اصلاً نمایش داده نمی‌شد. حالا کل تقویم
     داخل خود سایت است: تبدیل جلالی↔میلادی (الگوریتم jalaali)،
     انتخابگر RTL با رقم فارسی، محدودیت از امروز تا ۶ ماه بعد.
========================================================= -->
<style>
.jp-wrap{position:relative}
.jp-panel{position:absolute;top:calc(100% + 6px);right:0;z-index:1100;width:min(320px, calc(100vw - 24px));background:var(--surface,#fff);border:1px solid var(--border,#e3e3e3);border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.18);padding:10px;display:none;direction:rtl;box-sizing:border-box}
.jp-panel.jp-open{display:block}
.jp-panel.jp-up{top:auto;bottom:calc(100% + 6px)}
.jp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px}
.jp-title{font-size:14px;font-weight:800;color:var(--text-primary,#172121);flex:1;text-align:center}
.jp-nav{width:36px;height:36px;min-width:36px;border-radius:10px;border:1px solid var(--border,#e3e3e3);background:var(--bg,#f7f7f7);color:var(--text-primary,#172121);font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.jp-weekdays{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-bottom:4px}
.jp-weekdays span{text-align:center;font-size:11px;color:var(--text-secondary,#666);font-weight:700;padding:4px 0}
.jp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.jp-day{height:38px;border-radius:10px;border:none;background:transparent;color:var(--text-primary,#172121);font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;font-family:inherit;padding:0}
.jp-day:hover:not(:disabled){background:var(--bg,#f0f0f0)}
.jp-day:disabled{opacity:.28;cursor:default}
.jp-day.jp-today{border:1px solid var(--primary,#064e4e)}
.jp-day.jp-selected{background:var(--primary,#064e4e);color:#fff}
.jp-foot{display:flex;justify-content:flex-start;margin-top:8px}
.jp-today-btn{border:none;background:transparent;color:var(--primary,#064e4e);font-weight:700;font-size:12px;cursor:pointer;font-family:inherit;padding:6px 4px}
</style>
<script>
/* ==============================================================
   راند ۲۷: تقویم شمسی خودکفا
   - تبدیل جلالی/میلادی: الگوریتم استاندارد jalaali (MIT)
   - بدون هیچ وابستگی خارجی (jQuery/CDN حذف شد)
============================================================== */
(function () {
    'use strict';

    function div(a, b) { return ~~(a / b); }
    function mod(a, b) { return a - ~~(a / b) * b; }

    function jalCal(jy) {
        var breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
                      1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
        var bl = breaks.length,
            gy = jy + 621,
            leapJ = -14,
            jp = breaks[0],
            jm, jump, leap, leapG, march, n, i;
        for (i = 1; i < bl; i += 1) {
            jm = breaks[i];
            jump = jm - jp;
            if (jy < jm) break;
            leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
            jp = jm;
        }
        n = jy - jp;
        leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
        if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
        leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
        march = 20 + leapJ - leapG;
        if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
        leap = mod(mod(n + 1, 33) - 1, 4);
        if (leap === -1) leap = 4;
        return { leap: leap, gy: gy, march: march };
    }

    function g2d(gy, gm, gd) {
        var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4)
              + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
        d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
        return d;
    }

    function d2g(jdn) {
        var j = 4 * jdn + 139361631;
        j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        var i = div(mod(j, 1461), 4) * 5 + 308;
        var gd = div(mod(i, 153), 5) + 1;
        var gm = mod(div(i, 153), 12) + 1;
        var gy = div(j, 1461) - 100100 + div(8 - gm, 6);
        return { gy: gy, gm: gm, gd: gd };
    }

    function j2d(jy, jm, jd) {
        var r = jalCal(jy);
        return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    }

    function d2j(jdn) {
        var g = d2g(jdn);
        var jy = g.gy - 621;
        var r = jalCal(jy);
        var jdn1f = g2d(g.gy, 3, r.march);
        var k = jdn - jdn1f, jd, jm;
        if (k >= 0) {
            if (k <= 185) {
                jm = 1 + div(k, 31);
                jd = mod(k, 31) + 1;
                return { jy: jy, jm: jm, jd: jd };
            } else {
                k -= 186;
            }
        } else {
            jy -= 1;
            k += 179;
            if (r.leap === 1) k += 1;
        }
        jm = 7 + div(k, 30);
        jd = mod(k, 30) + 1;
        return { jy: jy, jm: jm, jd: jd };
    }

    window.melkinoToJalali = function (gy, gm, gd) { return d2j(g2d(gy, gm, gd)); };
    window.melkinoToGregorian = function (jy, jm, jd) { return d2g(j2d(jy, jm, jd)); };
    window.melkinoIsLeapJalali = function (jy) { return jalCal(jy).leap === 0; };
    window.melkinoJMonthLength = function (jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return window.melkinoIsLeapJalali(jy) ? 30 : 29;
    };
    window.melkinoJ2d = j2d;

    var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
    function toFa(v) { return String(v).replace(/[0-9]/g, function (d) { return FA_DIGITS[+d]; }); }
    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                  'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    function jalaliToday() {
        var n = new Date();
        return window.melkinoToJalali(n.getFullYear(), n.getMonth() + 1, n.getDate());
    }

    function addMonthsJ(j, months) {
        var total = (j.jy * 12 + (j.jm - 1)) + months;
        var jy = Math.floor(total / 12);
        var jm = (total % 12) + 1;
        var jd = Math.min(j.jd, window.melkinoJMonthLength(jy, jm));
        return { jy: jy, jm: jm, jd: jd };
    }

    window.melkinoInitJalaliPicker = function (input, opts) {
        if (!input) return null;
        if (input.getAttribute('data-jp') === '1') return null;
        input.setAttribute('data-jp', '1');
        opts = opts || {};
        var minJ = jalaliToday();
        var maxMonths = parseInt(opts.maxMonths, 10);
        if (!maxMonths || maxMonths < 1) maxMonths = 6;
        var maxJ = addMonthsJ(minJ, maxMonths);
        var hidden = opts.hiddenGregorianId ? document.getElementById(opts.hiddenGregorianId) : null;
        var view = null;          // {jy, jm} در حال نمایش
        var selected = null;      // {jy, jm, jd}

        var wrap = document.createElement('div');
        wrap.className = 'jp-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        input.setAttribute('readonly', 'readonly');
        input.style.cursor = 'pointer';

        var panel = document.createElement('div');
        panel.className = 'jp-panel';
        wrap.appendChild(panel);

        function parseCurrentValue() {
            var v = (input.value || '').trim();
            if (!v) return null;
            v = v.replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                 .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
            var p = v.split(/[\-\/.]/);
            if (p.length !== 3) return null;
            var jy = parseInt(p[0], 10), jm = parseInt(p[1], 10), jd = parseInt(p[2], 10);
            if (!jy || !jm || !jd || jm < 1 || jm > 12) return null;
            if (jd > window.melkinoJMonthLength(jy, jm)) return null;
            return { jy: jy, jm: jm, jd: jd };
        }

        function setSelection(j, fromInput) {
            selected = j;
            input.value = toFa(j.jy + '-' + pad2(j.jm) + '-' + pad2(j.jd));
            if (hidden) {
                var g = window.melkinoToGregorian(j.jy, j.jm, j.jd);
                hidden.value = g.gy + '-' + pad2(g.gm) + '-' + pad2(g.gd);
            }
            if (!fromInput && typeof window.convertSelectedJalaliToGregorian === 'function') {
                // همگام‌سازی نهایی با منطق موجودِ فرم (submit)
                window.convertSelectedJalaliToGregorian();
            }
            if (typeof opts.onSelect === 'function') opts.onSelect(j);
        }

        function dayInRange(j) {
            var d = window.melkinoJ2d(j.jy, j.jm, j.jd);
            return d >= window.melkinoJ2d(minJ.jy, minJ.jm, minJ.jd)
                && d <= window.melkinoJ2d(maxJ.jy, maxJ.jm, maxJ.jd);
        }

        function weekdayOfJ(j) {
            var g = window.melkinoToGregorian(j.jy, j.jm, j.jd);
            var jsDay = new Date(g.gy, g.gm - 1, g.gd).getDay(); // 0=یکشنبه
            return (jsDay + 1) % 7; // 0=شنبه
        }

        function render() {
            if (!view) {
                var cur = parseCurrentValue();
                view = cur ? { jy: cur.jy, jm: cur.jm } : { jy: minJ.jy, jm: minJ.jm };
            }
            var len = window.melkinoJMonthLength(view.jy, view.jm);
            var firstWd = weekdayOfJ({ jy: view.jy, jm: view.jm, jd: 1 });
            var html = '';
            html += '<div class="jp-head">'
                 +  '<button type="button" class="jp-nav" data-nav="-1" aria-label="ماه قبل">›</button>'
                 +  '<div class="jp-title">' + MONTHS[view.jm - 1] + ' ' + toFa(view.jy) + '</div>'
                 +  '<button type="button" class="jp-nav" data-nav="1" aria-label="ماه بعد">‹</button>'
                 +  '</div>';
            html += '<div class="jp-weekdays">';
            for (var w = 0; w < 7; w++) html += '<span>' + WEEKDAYS[w] + '</span>';
            html += '</div>';
            html += '<div class="jp-grid">';
            for (var e = 0; e < firstWd; e++) html += '<span></span>';
            for (var d = 1; d <= len; d++) {
                var j = { jy: view.jy, jm: view.jm, jd: d };
                var cls = 'jp-day';
                if (j.jy === minJ.jy && j.jm === minJ.jm && j.jd === minJ.jd) cls += ' jp-today';
                if (selected && j.jy === selected.jy && j.jm === selected.jm && j.jd === selected.jd) cls += ' jp-selected';
                html += '<button type="button" class="' + cls + '" data-day="' + d + '"'
                     +  (dayInRange(j) ? '' : ' disabled') + '>' + toFa(d) + '</button>';
            }
            html += '</div>';
            html += '<div class="jp-foot"><button type="button" class="jp-today-btn">امروز</button></div>';
            panel.innerHTML = html;
        }

        function open() {
            render();
            panel.classList.add('jp-open');
            var rect = input.getBoundingClientRect();
            var spaceBelow = window.innerHeight - rect.bottom;
            panel.classList.toggle('jp-up', spaceBelow < 330 && rect.top > 330);
        }
        function close() { panel.classList.remove('jp-open'); }
        function isOpen() { return panel.classList.contains('jp-open'); }

        input.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isOpen()) close(); else open();
        });
        input.addEventListener('focus', function () { if (!isOpen()) open(); });

        panel.addEventListener('click', function (e) {
            e.stopPropagation();
            var nav = e.target.closest ? e.target.closest('[data-nav]') : null;
            if (nav) {
                var step = parseInt(nav.getAttribute('data-nav'), 10);
                var total = view.jy * 12 + (view.jm - 1) + step;
                view = { jy: Math.floor(total / 12), jm: (total % 12) + 1 };
                render();
                return;
            }
            var day = e.target.closest ? e.target.closest('[data-day]') : null;
            if (day && !day.disabled) {
                setSelection({ jy: view.jy, jm: view.jm, jd: parseInt(day.getAttribute('data-day'), 10) });
                close();
                return;
            }
            if (e.target.classList && e.target.classList.contains('jp-today-btn')) {
                view = { jy: minJ.jy, jm: minJ.jm };
                setSelection({ jy: minJ.jy, jm: minJ.jm, jd: minJ.jd });
                close();
            }
        });

        document.addEventListener('click', function () { if (isOpen()) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen()) close(); });

        var initial = parseCurrentValue();
        if (initial && dayInRange(initial)) {
            selected = initial;
            setSelection(initial, true);
        }
        return { open: open, close: close };
    };
})();
</script>
