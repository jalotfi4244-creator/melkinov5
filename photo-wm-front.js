/* واترمارک لوگو — ساخت و نصب روی عکس کارت/جزئیات */
(function (w) {
    'use strict';

    var POS = {
        'center':        { x: 50, y: 50 },
        'top-left':      { x: 18, y: 16 },
        'top-center':    { x: 50, y: 16 },
        'top-right':     { x: 82, y: 16 },
        'middle-left':   { x: 18, y: 50 },
        'middle-right':  { x: 82, y: 50 },
        'bottom-left':   { x: 18, y: 84 },
        'bottom-center': { x: 50, y: 84 },
        'bottom-right':  { x: 82, y: 84 }
    };

    function cfg() { return w.MELKINO_PHOTO_WM || {}; }

    function logo() {
        var c = cfg();
        return String(c.logo_url || c.logo || w.MELKINO_SITE_LOGO || '').trim();
    }

    function num(v, d) {
        var n = Number(v);
        return isFinite(n) ? n : d;
    }

    function isOn(v) {
        return !(v === false || v === 0 || v === '0' || v === 'false' || v === 'off');
    }

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    w.mkPhotoWmShouldShow = function (scope) {
        var c = cfg();
        if (!isOn(c.enabled)) return false;
        var cardsOn = isOn(c.on_cards);
        var detailsOn = isOn(c.on_details);
        if (!cardsOn && !detailsOn) {
            cardsOn = true;
            detailsOn = true;
        }
        if (scope === 'card') return cardsOn;
        if (scope === 'details') return detailsOn;
        return true;
    };

    w.mkPhotoWmLayout = function (scope) {
        var c = cfg();
        var pos = POS[c.position] || POS.center;
        var isTile = c.mode === 'tile';
        var size = scope === 'details' ? num(c.size_details, num(c.size, 30)) : num(c.size, 38);
        if (isTile) size = num(c.tile_scale, 22);
        size = Math.max(10, Math.min(85, size));
        var op = num(c.opacity, 0.4);
        if (op > 1) op = op / 100;
        op = Math.max(0.12, Math.min(0.92, op));
        var x = pos.x + num(c.offset_x, 0);
        var y = pos.y + num(c.offset_y, 0);
        x = Math.max(6, Math.min(94, x));
        y = Math.max(6, Math.min(94, y));
        return {
            tile: isTile,
            size: size,
            gap: Math.max(0, Math.min(80, num(c.tile_gap, 16))),
            opacity: op,
            x: x,
            y: y,
            rot: num(c.rotation, 0),
            logo: logo()
        };
    };

    function fillTile(el, layout) {
        el.innerHTML = '';
        el.style.backgroundImage = 'none';
        el.style.opacity = '1';
        el.style.transform = 'rotate(' + layout.rot + 'deg) scale(1.35)';
        el.style.transformOrigin = 'center center';
        var size = layout.size;
        var step = size + layout.gap;
        if (step < 12) step = 12;
        var start = -step;
        var end = 100 + step;
        for (var y = start; y <= end; y += step) {
            for (var x = start; x <= end; x += step) {
                var img = document.createElement('img');
                img.src = layout.logo;
                img.alt = '';
                img.draggable = false;
                img.style.cssText =
                    'position:absolute;left:' + x + '%;top:' + y + '%;' +
                    'width:' + size + '%;height:auto;max-width:' + size + '%;' +
                    'object-fit:contain;opacity:' + layout.opacity + ';' +
                    'pointer-events:none;-webkit-user-drag:none;';
                el.appendChild(img);
            }
        }
    }

    function applyNode(el, layout) {
        el.className = 'mk-photo-wm' + (layout.tile ? ' mk-photo-wm--tile' : '');
        el.setAttribute('aria-hidden', 'true');
        el.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:6;user-select:none;overflow:hidden;';
        if (layout.tile) {
            fillTile(el, layout);
            return el;
        }
        el.style.backgroundImage = '';
        el.style.opacity = '1';
        el.style.transform = 'none';
        var img = el.querySelector('img');
        if (!img || el.querySelectorAll('img').length !== 1) {
            el.innerHTML = '';
            img = document.createElement('img');
            img.alt = '';
            img.draggable = false;
            el.appendChild(img);
        }
        if (img.getAttribute('src') !== layout.logo) img.src = layout.logo;
        img.style.cssText =
            'position:absolute;left:' + layout.x + '%;top:' + layout.y + '%;' +
            'width:' + layout.size + '%;height:auto;max-height:80%;max-width:90%;' +
            'object-fit:contain;opacity:' + layout.opacity + ';' +
            'transform:translate(-50%,-50%) rotate(' + layout.rot + 'deg);' +
            'pointer-events:none;-webkit-user-drag:none;' +
            'filter:drop-shadow(0 2px 8px rgba(0,0,0,.25));';
        return el;
    }

    w.mkPhotoWmHtml = function (scope) {
        if (!w.mkPhotoWmShouldShow(scope)) return '';
        var L = w.mkPhotoWmLayout(scope);
        var src = logo();
        if (!src) {
            return '<div class="mk-photo-wm" aria-hidden="true" style="position:absolute;inset:0;pointer-events:none;z-index:8">' +
                '<span class="mk-photo-wm-txt">ملکینو</span></div>';
        }
        if (L.tile) {
            return '<div class="mk-photo-wm mk-photo-wm--tile" aria-hidden="true" style="position:absolute;inset:0;pointer-events:none;z-index:8;overflow:hidden"></div>';
        }
        return '<div class="mk-photo-wm" aria-hidden="true" style="position:absolute;inset:0;pointer-events:none;z-index:8">' +
            '<img src="' + esc(src) + '" alt="" draggable="false" style="' +
            'position:absolute;left:' + L.x + '%;top:' + L.y + '%;width:' + L.size +
            '%;height:auto;max-height:80%;object-fit:contain;opacity:' + L.opacity +
            ';transform:translate(-50%,-50%) rotate(' + L.rot + 'deg);pointer-events:none">' +
            '</div>';
    };

    w.mkPhotoWmMount = function (host, scope) {
        if (!host) return;
        var existing = null;
        if (host.children) {
            for (var i = 0; i < host.children.length; i++) {
                if (host.children[i].classList && host.children[i].classList.contains('mk-photo-wm')) {
                    existing = host.children[i];
                    break;
                }
            }
        }
        if (!w.mkPhotoWmShouldShow(scope)) {
            if (existing) existing.remove();
            return;
        }
        var el = existing || document.createElement('div');
        if (!logo()) {
            el.className = 'mk-photo-wm';
            el.setAttribute('aria-hidden', 'true');
            el.style.cssText = 'position:absolute;inset:0;pointer-events:none;z-index:8;overflow:hidden;';
            el.innerHTML = '<span class="mk-photo-wm-txt">ملکینو</span>';
        } else {
            applyNode(el, w.mkPhotoWmLayout(scope));
        }
        if (!existing) host.appendChild(el);
    };

    w.mkPhotoWmRefreshAll = function (root) {
        root = root || document;
        try {
            root.querySelectorAll('.property-image').forEach(function (box) {
                if (box.querySelector('img')) w.mkPhotoWmMount(box, 'card');
            });
            root.querySelectorAll('.gallery-slide > div, .hero-gallery .gallery-slide > div').forEach(function (box) {
                if (box.querySelector('img')) w.mkPhotoWmMount(box, 'details');
            });
        } catch (e) {}
    };
})(window);
