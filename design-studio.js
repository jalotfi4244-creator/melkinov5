/* استودیو طراحی ملکینو — ادیتور زنده */
(function () {
    'use strict';

    var CATALOG = window.MELKINO_STUDIO_CATALOG || {};
    var state = {
        theme: null,
        published: null,
        history: [],
        dirty: false,
        panel: 'cards',
        page: 'home',
        size: 'm',
        device: 'mobile',
        selectedEl: 'title',
        undo: [],
        redo: [],
        compare: false,
        ba: false,
        previewAdId: ''
    };

    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function $(id) { return document.getElementById(id); }
    function qs(s, r) { return (r || document).querySelector(s); }
    function qsa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function pushUndo() {
        if (!state.theme) return;
        state.undo.push(clone(state.theme));
        if (state.undo.length > 40) state.undo.shift();
        state.redo = [];
    }

    function setDirty(v) {
        state.dirty = !!v;
        var el = $('dsStatus');
        if (!el) return;
        el.className = 'ds-status' + (v ? ' dirty' : ' ok');
        el.textContent = v ? '● تغییرات ذخیره نشده' : (state.published ? '✓ تم منتشر شده' : '✓ ذخیره شد');
    }

    function contrastWarn(t) {
        function rel(hex) {
            hex = String(hex || '').replace('#', '');
            if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
            if (hex.length < 6) return 1;
            var r = parseInt(hex.slice(0,2),16)/255, g = parseInt(hex.slice(2,4),16)/255, b = parseInt(hex.slice(4,6),16)/255;
            var L = function (c) { return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
            return 0.2126*L(r)+0.7152*L(g)+0.0722*L(b);
        }
        var bg = (t.card && t.card.bg) || (t.colors && t.colors.surface) || '#fff';
        var fg = (t.elements && t.elements.title && t.elements.title.color) || '#111827';
        var a = rel(bg)+0.05, b = rel(fg)+0.05;
        return (a > b ? a/b : b/a) < 3;
    }

    function deviceWidth() {
        if (state.device === 'mobile') return 390;
        if (state.device === 'tablet') return 768;
        return 1280;
    }
    var frameResizeObserver=null;
    function fitRealFrame() {
        var frame=$('dsFrame');if(!frame)return;
        var box=frame.parentElement,w=deviceWidth();
        var zoom=state.size==='s'?.92:state.size==='l'?1.04:1;
        var scale=Math.min(1,Math.max(100,box.clientWidth-20)/w)*zoom;
        frame.style.width=w+'px';frame.style.transform='scale('+scale+')';
        box.style.height=Math.ceil(720*scale+20)+'px';
    }
    window.addEventListener('resize',fitRealFrame);
    function applyLiveCss(t) {
        postStudioToHome();
    }
    var previewTicket=0, previewTimer=null, canonicalCache={};
    function canonicalPreview(theme, gallery) {
        var key=JSON.stringify(theme)+'|'+!!gallery;
        if (!canonicalCache[key]) {
            // Keep the cache bounded while sliders are dragged.
            if(Object.keys(canonicalCache).length>25)canonicalCache={};
            canonicalCache[key]=api('preview',{theme:theme,gallery:!!gallery}).then(function(j){
                if(!j.success)throw Error(j.message||'پیش‌نمایش آماده نشد.');return j;
            }).catch(function(e){delete canonicalCache[key];throw e;});
        }
        return canonicalCache[key];
    }
    function postStudioToHome() {
        var ticket=++previewTicket;clearTimeout(previewTimer);
        previewTimer=setTimeout(function(){
            if(!state.theme)return;
            var theme=clone(state.theme), mode=$('dsModeSel');
            if(mode && mode.value!=='system')theme.mode=mode.value;
            canonicalPreview(theme,false).then(function(j){
                if(ticket!==previewTicket)return;
                var frame=$('dsFrame');if(!frame || !frame.contentWindow)return;
                frame.contentWindow.postMessage({type:'melkino-studio-preview',theme:j.theme,css:j.css},location.origin);
                if(state.page==='home' || state.page==='listing')requestAnimationFrame(function(){try{var el=frame.contentDocument.getElementById('mkHomeAdsList');if(el)frame.contentWindow.scrollTo(0,el.getBoundingClientRect().top+frame.contentWindow.scrollY-72);}catch(e){}});
            }).catch(function(){var label=qs('.ds-now-layout');if(label)label.textContent='پیش‌نمایش به‌روز نشد؛ اتصال را بررسی کنید.';});
        },100);
    }
    var cardDocumentPromise=null, galleryTicket=0;
    function loadRealGallery(host) {
        var ticket=++galleryTicket;
        if(!cardDocumentPromise)cardDocumentPromise=fetch('admin-card-preview.php',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(!r.ok)throw Error('preview');return r.text();}).catch(function(e){cardDocumentPromise=null;throw e;});
        Promise.all([cardDocumentPromise,canonicalPreview(clone(state.theme),true)]).then(function(values){
            if(ticket!==galleryTicket || !host.isConnected)return;
            qsa('iframe[data-card-preview]',host).forEach(function(frame){
                var item=values[1].gallery[frame.getAttribute('data-card-preview')];
                frame.style.width=deviceWidth()+'px';
                frame.onload=function(){frame.contentWindow.postMessage({type:'melkino-studio-preview',theme:item.theme,css:item.css},location.origin);};
                frame.srcdoc=values[0];
            });
        }).catch(function(){qsa('.ds-thumb',host).forEach(function(t){t.textContent='پیش‌نمایش بارگذاری نشد؛ تب کارت‌ها را دوباره باز کنید.';});});
    }
    function scaleCardThumbnail(frame) {
        var box=frame.parentElement,scale=box.clientWidth/(Number(frame.dataset.cardWidth)||deviceWidth());
        if(!scale)return;
        var h=Number(frame.dataset.cardHeight)||600;
        frame.style.height=h+'px';frame.style.transform='scale('+scale+')';box.style.height=(h*scale)+'px';
    }
    window.addEventListener('message',function(e){
        if(e.origin!==location.origin || !e.data || e.data.type!=='melkino-card-height')return;
        qsa('iframe[data-card-preview]').forEach(function(f){if(f.contentWindow!==e.source)return;f.dataset.cardWidth=Math.min(2000,Math.max(100,Number(e.data.width)||390));f.dataset.cardHeight=Math.min(1800,Math.max(80,Number(e.data.height)||600));scaleCardThumbnail(f);});
    });
    window.addEventListener('resize',function(){qsa('iframe[data-card-preview]').forEach(scaleCardThumbnail);});
    function previewLabel() {
        var layout = (state.theme && state.theme.layout) || 'nx-01';
        var meta = CATALOG[layout] || { fa: layout };
        if (state.page === 'map') {
            var mk0 = (state.theme && state.theme.marker) || {};
            var shapeFa = (window.MelkinoMarker && window.MelkinoMarker.label(mk0.shape || 'pin')) || (mk0.shape || 'pin');
            return '<div class="ds-now-layout">پیش‌نمایش زندهٔ نقشه — همان کارت و مارکر واقعی صفحهٔ نقشه · مارکر: <strong>' +
                shapeFa + '</strong> <a class="ds-chip" href="map.php" target="_blank" rel="noopener">باز کردن نقشهٔ واقعی</a></div>';
        }
        return '<div class="ds-now-layout">همان پیش‌نمایش واقعی تب نمایش (رندرر سایت + آگهی واقعی) — مدل: <strong>'
            + (meta.fa || layout) + '</strong></div>';
    }
    function mountHomeFrame(adId) {
        var page = $('dsPage');
        if (!page) return;
        state.previewAdId = adId || '';
        var w = deviceWidth();
        var url = 'home.php?fd_preview=1&_cb=' + Date.now();
        if (state.page === 'details' && adId) {
            url = 'property-details.php?id=' + encodeURIComponent(adId) + '&fd_preview=1&_cb=' + Date.now();
        } else if (state.page === 'listing') {
            url = 'home.php?fd_preview=1&_cb=' + Date.now();
        } else if (adId) {
            url += '&fd_ad=' + encodeURIComponent(adId);
        }
        page.innerHTML = previewLabel() + '<div class="ds-real-frame"><iframe class="ds-frame" id="dsFrame" title="پیش‌نمایش واقعی کارت" style="width:' + w + 'px"></iframe></div>';
        var frame = $('dsFrame');
        frame.onload = function () {
            frame.setAttribute('data-ready', '1');
            postStudioToHome();
            try { bindImagePan(frame.contentDocument && frame.contentDocument.body); } catch (e) {}
        };
        frame.src = url;
        if(frameResizeObserver)frameResizeObserver.disconnect();
        if(window.ResizeObserver){frameResizeObserver=new ResizeObserver(fitRealFrame);frameResizeObserver.observe(frame.parentElement);}
        requestAnimationFrame(fitRealFrame);
    }
    function resolvePreviewAd(done) {
        var sel = document.getElementById('fdHomeSample');
        if (sel && sel.value) { done(sel.value); return; }
        if (state.previewAdId) { done(state.previewAdId); return; }
        fetch('admin-field-display.php?action=get&target=home', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var id = (j && j.samples && j.samples[0] && j.samples[0].id) || '';
                done(id);
            })
            .catch(function () { done(''); });
    }
    /* ---------- پیش‌نمایش زندهٔ نقشه ----------
     * از همان مارکاپ و همان CSS صفحهٔ map.php استفاده می‌کند
     * (map.css در استودیو لود شده) تا چیزی که می‌بینید واقعی باشد.
     */
    function mapSheetHtml(t) {
        var mc = t.mapCard || {};
        var amen = '';
        if (mc.showAmen !== false) {
            amen = '<div class="mk-amen-row">' +
                '<span class="mk-amen is-on"><i>پارکینگ</i></span>' +
                '<span class="mk-amen is-on"><i>آسانسور</i></span>' +
                '<span class="mk-amen"><i>انباری</i></span></div>';
        }
        var img = (mc.showImage !== false && (mc.imageHeight == null || mc.imageHeight > 0))
            ? '<img class="mk-map-thumb" src="assets/defaults/apartment-1.jpg" alt="">' : '';
        return '<button type="button" class="mk-map-sheet-close">بستن</button>' +
            '<div class="mk-map-sheet-body">' + img +
            '<div class="mk-map-sheet-copy"><strong>آپارتمان ۸۵ متری کارکنان دولت</strong>' +
            (mc.showKind !== false ? '<p class="mk-map-kind">فروش · آپارتمان · ۲ خواب</p>' : '') +
            amen +
            (mc.showPrice !== false ? '<p class="mk-map-price">۴٫۸ میلیارد تومان</p>' : '') +
            (mc.showButton !== false ? '<a class="mk-map-btn gold" href="#" onclick="return false">' +
                esc(mc.buttonText || 'مشاهده فایل') + '</a>' : '') +
            '</div></div>';
    }

    function mapStageCss(t) {
        var mc = t.mapCard || {};
        var shadows = {
            none: 'none', soft: '0 8px 22px rgba(6,78,78,.08)', medium: '0 10px 28px rgba(6,78,78,.12)',
            strong: '0 18px 40px rgba(6,78,78,.18)', floating: '0 22px 50px rgba(6,78,78,.16)',
            luxury: '0 16px 36px rgba(212,175,55,.18)'
        };
        var css = '#dsMapSheet{max-width:' + (mc.width || 360) + 'px;border-radius:' + (mc.radius == null ? 18 : mc.radius) + 'px;';
        css += 'box-shadow:' + (shadows[mc.shadow] || shadows.strong) + ';';
        if (mc.bg) css += 'background:' + mc.bg + ' !important;';
        css += '}';
        if (mc.imageHeight != null) {
            css += '#dsMapSheet .mk-map-thumb{height:' + mc.imageHeight + 'px;object-fit:cover}';
        }
        return css;
    }

    function renderMapStage() {
        var t = state.theme;
        var page = $('dsPage');
        if (!page || !t) return;
        var mk = t.marker || {};
        var color = mk.color || (t.colors && t.colors.primary) || '#064E4E';
        var markerRow = '';
        if (window.MelkinoMarker) {
            markerRow = ['normal', 'vip'].map(function (kind) {
                return '<span class="ds-map-marker">' + window.MelkinoMarker.html({
                    shape: mk.shape || 'pin',
                    color: kind === 'vip' ? (mk.vipColor || '#D4AF37') : color,
                    stroke: mk.stroke || '#FFFFFF',
                    size: mk.size || 34,
                    shadow: mk.shadow !== false,
                    pulse: !!mk.pulse,
                    label: mk.label === 'price' ? '۴٫۸ میلیارد' : (mk.label === 'type' ? 'آپارتمان' : '')
                }) + '<em>' + (kind === 'vip' ? 'فایل ویژه' : 'فایل عادی') + '</em></span>';
            }).join('');
        }
        page.innerHTML = previewLabel() +
            '<style id="dsMapCss">' + mapStageCss(t) + '</style>' +
            '<div class="ds-map-stage" style="width:' + deviceWidth() + 'px">' +
            '<div class="ds-map-canvas"><div class="ds-map-markers">' + markerRow + '</div></div>' +
            '<div class="mk-map-sheet ds-map-sheet" id="dsMapSheet" data-skin="' +
            esc((t.mapCard || {}).skin || 'classic') + '">' + mapSheetHtml(t) + '</div>' +
            '</div>';
    }

    function renderPreview() {
        var t = state.theme; if (!t) return;
        if (state.page === 'map') {
            var br = $('dsBrowser');
            if (br) br.setAttribute('data-device', state.device);
            var pg = $('dsPage');
            if (pg) pg.setAttribute('data-theme', (t.mode === 'dark' ? 'dark' : 'light'));
            renderMapStage();
            return;
        }
        var page = $('dsPage');
        var browser = $('dsBrowser');
        if (!page || !browser) return;
        browser.setAttribute('data-device', state.device);
        var mode = t.mode === 'system' ? 'light' : t.mode;
        if ($('dsModeSel') && $('dsModeSel').value === 'dark') mode = 'dark';
        if ($('dsModeSel') && $('dsModeSel').value === 'light') mode = 'light';
        page.setAttribute('data-theme', mode);
        var frame = $('dsFrame');
        var w = deviceWidth();
        if (frame && frame.getAttribute('data-ready') === '1' && frame.getAttribute('data-page') === state.page) {
            frame.style.width = w + 'px';
            requestAnimationFrame(fitRealFrame);
            var lab = page.querySelector('.ds-now-layout');
            if (lab) lab.outerHTML = previewLabel();
            postStudioToHome();
            return;
        }
        resolvePreviewAd(function (id) {
            mountHomeFrame(id);
            var f = $('dsFrame');
            if (f) f.setAttribute('data-page', state.page);
        });
    }

    function bindImagePan(root) {
        root = root || $('dsPage');
        if (!root || !state.theme || !state.theme.elements || !state.theme.elements.image) return;
        qsa('.property-image', root).forEach(function (box) {
            box.style.cursor = 'grab';
            box.style.touchAction = 'none';
            var start = null;
            box.addEventListener('pointerdown', function (e) {
                if (e.button && e.button !== 0) return;
                var im = state.theme.elements.image;
                start = {
                    x: e.clientX,
                    y: e.clientY,
                    px: im.posX == null ? 50 : +im.posX,
                    py: im.posY == null ? 50 : +im.posY,
                    w: box.clientWidth || 1,
                    h: box.clientHeight || 1
                };
                try { box.setPointerCapture(e.pointerId); } catch (err) {}
                box.style.cursor = 'grabbing';
                e.preventDefault();
            });
            box.addEventListener('pointermove', function (e) {
                if (!start) return;
                var nx = Math.max(0, Math.min(100, start.px - ((e.clientX - start.x) / start.w) * 100));
                var ny = Math.max(0, Math.min(100, start.py - ((e.clientY - start.y) / start.h) * 100));
                state.theme.elements.image.posX = Math.round(nx);
                state.theme.elements.image.posY = Math.round(ny);
                applyLiveCss(state.theme);
            });
            function endPan() {
                if (!start) return;
                start = null;
                box.style.cursor = 'grab';
                setDirty(true);
            }
            box.addEventListener('pointerup', endPan);
            box.addEventListener('pointercancel', endPan);
        });
    }

    function renderGallery(host) {
        var fav = state.theme.favorites || [];
        var html = '<div class="ds-help" style="margin-bottom:8px">مدل‌های محبوب من</div><div class="ds-gallery">';
        fav.forEach(function (id) {
            if (!CATALOG[id]) return;
            html += gCard(id, true);
        });
        html += '</div><div class="ds-row" style="margin:10px 0"><button type="button" class="ds-chip" id="dsNewCard">+ ساخت کارت جدید</button><button type="button" class="ds-chip" id="dsDupCard">Duplicate</button></div>';
        html += '<div class="ds-help">۱۰۰ مدل — کارت واقعی با اطلاعات نمونه و تنظیمات فعلی شما؛ پس از انتخاب، ذخیره و اعمال کنید.</div><div class="ds-gallery">';
        Object.keys(CATALOG).forEach(function (id) { html += gCard(id, false); });
        html += '</div>';
        host.innerHTML = html;
        loadRealGallery(host);
        qsa('.ds-g-card', host).forEach(function (b) {
            b.addEventListener('click', function () {
                pushUndo();
                state.theme.layout = b.getAttribute('data-id');
                state.compare = false;
                state.ba = false;
                var cBtn = $('dsCompareBtn'); if (cBtn) cBtn.classList.remove('is-on');
                var bBtn = $('dsBaBtn'); if (bBtn) bBtn.classList.remove('is-on');
                setDirty(true);
                renderGallery(host);
                renderPreview();
            });
        });
        qsa('[data-fav]', host).forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = b.getAttribute('data-fav');
                var i = fav.indexOf(id);
                pushUndo();
                if (i >= 0) fav.splice(i, 1); else fav.push(id);
                state.theme.favorites = fav;
                setDirty(true);
                renderGallery(host);
            });
        });
        var dup = qs('#dsDupCard', host);
        if (dup) dup.addEventListener('click', function () {
            pushUndo();
            var id = 'c' + Date.now().toString(36);
            state.theme.custom = state.theme.custom || [];
            state.theme.custom.push({ id: id, name: (CATALOG[state.theme.layout] || {}).fa + ' اختصاصی', basedOn: state.theme.layout });
            setDirty(true);
            alert('نسخه اختصاصی ساخته شد. می‌توانید تنظیمات را تغییر دهید و پیش‌نویس را ذخیره کنید.');
        });
        var neu = qs('#dsNewCard', host);
        if (neu) neu.addEventListener('click', function () { openWizard(true); });
    }

    function gCard(id, favOnly) {
        var m = CATALOG[id] || { fa: id, desc: '' };
        var on = state.theme.layout === id ? ' is-on' : '';
        var star = (state.theme.favorites || []).indexOf(id) >= 0 ? '★' : '☆';
        return '<button type="button" class="ds-g-card' + on + '" data-id="' + id + '">'
            + '<span class="ds-thumb ds-thumb-real"><iframe data-card-preview="'+id+'" loading="lazy" tabindex="-1" aria-hidden="true" title="پیش‌نمایش '+m.fa+'"></iframe></span>'
            + '<strong>' + m.fa + '</strong><small>' + m.desc + '</small>'
            + '<span data-fav="' + id + '" style="float:left;cursor:pointer">' + star + '</span></button>';
    }

    function field(label, inner, key) {
        return '<div class="ds-field" data-set-key="' + (key || label) + '"><label>' + label + '</label>' + inner + '</div>';
    }
    function tog(label, path, on) {
        return '<label class="ds-toggle" data-set-key="' + label + '"><span>' + label + '</span><input type="checkbox" data-path="' + path + '"' + (on ? ' checked' : '') + '></label>';
    }

    function inspector() {
        var host = $('dsSideBody') || $('dsSide'); if (!host || !state.theme) return;
        var t = state.theme;
        var p = state.panel;
        var html = '<input class="ds-search" id="dsSearch" placeholder="جستجو در تنظیمات…">';
        html += '<div class="ds-presets">';
        [['luxury','✨ Luxury'],['teal','🌿 Teal'],['dark','🌙 Dark'],['minimal','🤍 Minimal'],['modern','🪄 Modern']].forEach(function (x) {
            html += '<button type="button" class="ds-chip" data-preset="' + x[0] + '">' + x[1] + '</button>';
        });
        html += '</div>';
        if (contrastWarn(t)) html += '<div class="ds-warn">کنتراست برای خوانایی مناسب نیست. تصمیم نهایی با شماست.</div>';

        if (p === 'cards') {
            renderGallery(host);
            var g = document.createElement('div');
            g.innerHTML = html;
            host.insertBefore(g, host.firstChild);
            bindInspector(host);
            return;
        }
        if (p === 'theme') {
            html += field('نام تم', '<input type="text" data-path="name" value="' + esc(t.name) + '">', 'نام');
            html += field('رنگ اصلی', colorInput('colors.primary', t.colors.primary), 'primary');
            html += field('رنگ دوم', colorInput('colors.secondary', t.colors.secondary), 'secondary');
            html += field('طلایی', colorInput('colors.gold', t.colors.gold), 'gold');
            html += field('پس‌زمینه', colorInput('colors.background', t.colors.background), 'background');
            html += field('سطح کارت', colorInput('colors.surface', t.colors.surface), 'surface');
        }
        if (p === 'images') {
            var im = t.elements.image;
            html += tog('نمایش تصویر', 'elements.image.show', im.show);
            html += field('نسبت', sel('elements.image.ratio', im.ratio, [['16/10','۱۶٫۱۰'],['16/9','۱۶٫۹'],['4/3','۴٫۳'],['3/2','۳٫۲'],['1/1','۱٫۱'],['4/5','پرتره']]), 'ratio');
            html += field('ارتفاع ' + im.height, '<input type="range" min="80" max="420" data-path="elements.image.height" value="' + im.height + '">', 'height');
            html += field('گردی عکس', '<input type="range" min="0" max="40" data-path="elements.image.radius" value="' + im.radius + '">', 'radius');
            html += field('Object Fit', sel('elements.image.fit', im.fit, [['cover','پوشش'],['contain','جا شدن'],['fill','کشیده']]), 'object fit');
            html += '<div class="ds-help">قسمت دیده شده عکس — روی پیش‌نمایش بکشید یا از دکمه‌ها انتخاب کنید.</div>';
            html += '<div class="ds-presets">';
            [['50,20','بالا'],['50,50','وسط'],['50,80','پایین'],['20,50','چپ'],['80,50','راست']].forEach(function (x) {
                html += '<button type="button" class="ds-chip" data-focal="' + x[0] + '">' + x[1] + '</button>';
            });
            html += '</div>';
            html += field('افقی ' + (im.posX == null ? 50 : im.posX), '<input type="range" min="0" max="100" data-path="elements.image.posX" value="' + (im.posX == null ? 50 : im.posX) + '">', 'position x');
            html += field('عمودی ' + (im.posY == null ? 50 : im.posY), '<input type="range" min="0" max="100" data-path="elements.image.posY" value="' + (im.posY == null ? 50 : im.posY) + '">', 'position y');
            html += field('Overlay', sel('elements.image.overlay', im.overlay, [['none','هیچ'],['dark','تیره'],['gradient','گرادیان'],['soft','نرم']]), 'overlay');
            html += '<button type="button" class="ds-chip" data-adv="img">تنظیمات پیشرفته</button>';
            html += '<div class="ds-adv" id="adv-img">' + field('شفافیت', '<input type="range" min="0.4" max="1" step="0.05" data-path="elements.image.opacity" value="' + im.opacity + '">', 'opacity')
                + field('روشنایی', '<input type="range" min="0.5" max="1.6" step="0.05" data-path="elements.image.brightness" value="' + im.brightness + '">', 'brightness')
                + field('کنتراست', '<input type="range" min="0.5" max="1.6" step="0.05" data-path="elements.image.contrast" value="' + im.contrast + '">', 'contrast')
                + '<p class="ds-help">مشخص می‌کند تصویر چگونه داخل محدوده خود قرار بگیرد.</p></div>';
        }
        if (p === 'text') {
            var ttl = t.elements.title, prc = t.elements.price;
            html += tog('عنوان', 'elements.title.show', ttl.show);
            html += field('اندازه عنوان ' + ttl.size, '<input type="range" min="11" max="26" data-path="elements.title.size" value="' + ttl.size + '">', 'size');
            html += field('ضخامت', sel('elements.title.weight', String(ttl.weight), [['600','متوسط'],['800','Bold'],['900','Black']]), 'weight');
            html += field('رنگ عنوان', colorInput('elements.title.color', ttl.color || t.colors.title || '#111827'), 'رنگ');
            html += field('تراز', sel('elements.title.align', ttl.align, [['right','راست'],['center','وسط'],['left','چپ']]), 'align');
            html += tog('قیمت', 'elements.price.show', prc.show);
            html += field('اندازه قیمت ' + prc.size, '<input type="range" min="11" max="32" data-path="elements.price.size" value="' + prc.size + '">', 'price');
            html += field('رنگ قیمت', colorInput('elements.price.color', prc.color || t.colors.gold), 'price color');
            html += field('پسوند', '<input type="text" data-path="elements.price.suffix" value="' + esc(prc.suffix) + '">', 'suffix');
            html += tog('موقعیت', 'elements.location.show', t.elements.location.show);
            html += tog('مشخصات', 'elements.features.show', t.elements.features.show);
            html += tog('متراژ', 'elements.features.items.area', t.elements.features.items.area);
            html += tog('اتاق', 'elements.features.items.rooms', t.elements.features.items.rooms);
            html += tog('طبقه', 'elements.features.items.floor', t.elements.features.items.floor);
            html += tog('سال ساخت', 'elements.features.items.year', t.elements.features.items.year);
        }
        if (p === 'effects') {
            html += '<div class="ds-help">Style سریع</div><div class="ds-presets">';
            [['minimal','Minimal'],['soft','Soft'],['luxury','Luxury'],['modern','Modern'],['glass','Glass']].forEach(function (x) {
                html += '<button type="button" class="ds-chip" data-style="' + x[0] + '">' + x[1] + '</button>';
            });
            html += '</div>';
            html += field('گردی کارت', sel('card.radiusPreset', '', [['0','Sharp'],['8','Small'],['16','Medium'],['24','Large'],['32','Extra Large'],['40','Pill']]) + '<input type="range" min="0" max="40" data-path="card.radius" value="' + t.card.radius + '">', 'radius');
            html += field('سایه', sel('card.shadow', t.card.shadow, [['none','هیچ'],['soft','نرم'],['medium','متوسط'],['strong','قوی'],['floating','شناور'],['luxury','لوکس']]), 'shadow');
            html += field('حاشیه', sel('card.border', t.card.border, [['none','هیچ'],['subtle','ظریف'],['solid','توپر'],['gold','طلایی'],['gradient','گرادیان']]), 'border');
            html += field('هاور', sel('card.hover', t.card.hover, [['none','هیچ'],['lift','بالا'],['zoom','زوم'],['shadow','سایه'],['glow','درخشش'],['scale','مقیاس']]), 'hover');
            html += field('مدت هاور', '<input type="range" min="80" max="600" data-path="card.hoverMs" value="' + t.card.hoverMs + '">', 'duration');
        }
        if (p === 'layout') {
            html += '<div class="ds-help">ترتیب عناصر را بکشید</div><div id="dsLayers"></div>';
            html += field('ستون دسکتاپ', sel('grid.desktop', String(t.grid.desktop), [['1','۱'],['2','۲'],['3','۳'],['4','۴']]), 'desktop');
            html += field('ستون تبلت', sel('grid.tablet', String(t.grid.tablet), [['1','۱'],['2','۲'],['3','۳']]), 'tablet');
            html += field('ستون موبایل', sel('grid.mobile', String(t.grid.mobile), [['1','۱'],['2','۲']]), 'mobile');
        }
        if (p === 'map') {
            var mkS = t.marker || {};
            var mcS = t.mapCard || {};
            var SKINS = window.MELKINO_MAPCARD_SKINS || {};
            html += '<div class="ds-help">مدل آمادهٔ کارت نقشه — یکی را انتخاب کنید</div><div class="ds-skin-grid">';
            Object.keys(SKINS).forEach(function (k) {
                var s = SKINS[k] || {};
                var pv = s.preview || {};
                var on = (mcS.skin || 'classic') === k ? ' is-on' : '';
                html += '<button type="button" class="ds-skin-opt' + on + '" data-skin="' + k + '" data-set-key="' + (s.fa || k) + '">' +
                    '<span class="ds-skin-pv" style="background:' + (pv.bg || '#10201f') + ';border-color:' + (pv.border || '#d4af37') + ';color:' + (pv.text || '#fff') + '">' +
                    '<i class="ds-skin-img"></i>' +
                    '<i class="ds-skin-line"></i><i class="ds-skin-line short"></i>' +
                    '<i class="ds-skin-price" style="color:' + (pv.accent || '#d4af37') + '">۴٫۸ م</i>' +
                    '</span><strong>' + (s.fa || k) + '</strong><small>' + (s.desc || '') + '</small></button>';
            });
            html += '</div>';
            html += '<div class="ds-help">شکل مارکر روی نقشه</div><div class="ds-marker-grid" id="dsMarkerGrid">';
            var keys = (window.MelkinoMarker ? window.MelkinoMarker.keys() : ['pin']);
            keys.forEach(function (k) {
                var on = (mkS.shape || 'pin') === k ? ' is-on' : '';
                var svg = window.MelkinoMarker
                    ? window.MelkinoMarker.svg(k, mkS.color || t.colors.primary, mkS.stroke || '#FFFFFF')
                    : '';
                html += '<button type="button" class="ds-marker-opt' + on + '" data-marker="' + k + '" data-set-key="marker ' + k + '">' +
                    '<span class="ds-marker-ico">' + svg + '</span><small>' +
                    (window.MelkinoMarker ? window.MelkinoMarker.label(k) : k) + '</small></button>';
            });
            html += '</div>';
            html += field('اندازه مارکر ' + (mkS.size || 34), '<input type="range" min="18" max="64" data-path="marker.size" value="' + (mkS.size || 34) + '">', 'marker size');
            html += field('رنگ مارکر', colorInput('marker.color', mkS.color || t.colors.primary), 'marker color');
            html += field('رنگ مارکر ویژه (VIP)', colorInput('marker.vipColor', mkS.vipColor || t.colors.gold), 'vip');
            html += field('رنگ دورخط', colorInput('marker.stroke', mkS.stroke || '#FFFFFF'), 'stroke');
            html += tog('سایه مارکر', 'marker.shadow', mkS.shadow !== false);
            html += tog('ضربان (جلب توجه)', 'marker.pulse', !!mkS.pulse);
            html += field('برچسب کنار مارکر', sel('marker.label', mkS.label || 'none', [['none', 'بدون برچسب'], ['price', 'قیمت'], ['type', 'نوع ملک']]), 'label');

            html += '<div class="ds-help" style="margin-top:12px">کارت بازشونده روی نقشه</div>';
            html += field('عرض کارت ' + (mcS.width || 360), '<input type="range" min="240" max="520" data-path="mapCard.width" value="' + (mcS.width || 360) + '">', 'map card width');
            html += field('گردی کارت', '<input type="range" min="0" max="40" data-path="mapCard.radius" value="' + (mcS.radius || 18) + '">', 'map card radius');
            html += field('ارتفاع عکس ' + (mcS.imageHeight == null ? 110 : mcS.imageHeight), '<input type="range" min="0" max="240" data-path="mapCard.imageHeight" value="' + (mcS.imageHeight == null ? 110 : mcS.imageHeight) + '">', 'map card image');
            html += field('سایه کارت', sel('mapCard.shadow', mcS.shadow || 'strong', [['none', 'هیچ'], ['soft', 'نرم'], ['medium', 'متوسط'], ['strong', 'قوی'], ['floating', 'شناور'], ['luxury', 'لوکس']]), 'map card shadow');
            html += field('جای کارت', sel('mapCard.pos', mcS.pos || 'bottom', [['bottom', 'پایین صفحه'], ['top', 'بالای صفحه'], ['center', 'وسط']]), 'map card pos');
            html += field('رنگ زمینه کارت', colorInput('mapCard.bg', mcS.bg || t.colors.surface), 'map card bg');
            html += tog('نمایش عکس', 'mapCard.showImage', mcS.showImage !== false);
            html += tog('نمایش نوع معامله/ملک', 'mapCard.showKind', mcS.showKind !== false);
            html += tog('نمایش امکانات', 'mapCard.showAmen', mcS.showAmen !== false);
            html += tog('نمایش قیمت', 'mapCard.showPrice', mcS.showPrice !== false);
            html += tog('نمایش دکمه', 'mapCard.showButton', mcS.showButton !== false);
            html += field('متن دکمه', '<input type="text" data-path="mapCard.buttonText" value="' + esc(mcS.buttonText || 'مشاهده فایل') + '">', 'button text');
            html += '<p class="ds-help">این تنظیمات روی صفحهٔ نقشهٔ سایت اعمال می‌شود. بعد از «ذخیره و اعمال» تغییر را روی نقشه می‌بینید.</p>';
        }
        if (p === 'mode') {
            html += field('حالت پیش‌فرض', sel('mode', t.mode, [['light','روشن'],['dark','تیره'],['system','سیستم']]), 'mode');
            html += field('رنگ تیره · اصلی', colorInput('darkColors.primary', t.darkColors.primary), 'dark');
            html += field('پس‌زمینه تیره', colorInput('darkColors.background', t.darkColors.background), 'dark bg');
            html += field('سطح تیره', colorInput('darkColors.surface', t.darkColors.surface), 'dark surface');
        }
        if (p === 'advanced') {
            html += '<p class="ds-help">متغیرها و تاریخچه. این بخش برای کاربر حرفه‌ای است.</p>';
            html += field('ارتفاع عکس موبایل', '<input type="range" min="100" max="280" data-path="deviceOverrides.mobile.imageHeight" value="' + t.deviceOverrides.mobile.imageHeight + '">', 'mobile');
            html += '<div class="ds-row"><button type="button" class="ds-chip" id="dsResetSec">بازنشانی این بخش</button><button type="button" class="ds-chip" id="dsResetAll">بازنشانی کل تم</button></div>';
            html += '<div class="ds-row" style="margin-top:8px"><button type="button" class="ds-chip" id="dsExport">خروجی JSON</button><button type="button" class="ds-chip" id="dsImport">ورود JSON</button></div>';
            html += '<div class="ds-help" style="margin-top:10px">تاریخچه انتشار</div><div id="dsHist"></div>';
        }
        host.innerHTML = html;
        if (p === 'layout') renderLayers();
        if (p === 'advanced') renderHist();
        bindInspector(host);
    }

    function colorInput(path, val) {
        val = val || '#064E4E';
        return '<div class="ds-row"><input type="color" data-path="' + path + '" value="' + val + '"><input type="text" data-path="' + path + '" value="' + val + '"></div>';
    }
    function sel(path, val, opts) {
        return '<select data-path="' + path + '">' + opts.map(function (o) {
            return '<option value="' + o[0] + '"' + (String(val) === String(o[0]) ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('') + '</select>';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function setPath(obj, path, value) {
        var p = path.split('.');
        var cur = obj;
        for (var i = 0; i < p.length - 1; i++) {
            if (!cur[p[i]] || typeof cur[p[i]] !== 'object') cur[p[i]] = {};
            cur = cur[p[i]];
        }
        var last = p[p.length - 1];
        if (value === 'true' || value === true) value = true;
        else if (value === 'false' || value === false) value = false;
        else if (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value) && last !== 'name' && last !== 'suffix' && last !== 'prefix') {
            value = value.indexOf('.') >= 0 ? parseFloat(value) : parseInt(value, 10);
        }
        cur[last] = value;
    }

    function bindInspector(host) {
        qsa('[data-preset]', host).forEach(function (b) {
            b.addEventListener('click', function () { applyPreset(b.getAttribute('data-preset')); });
        });
        qsa('[data-focal]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                var parts = String(b.getAttribute('data-focal') || '50,50').split(',');
                pushUndo();
                state.theme.elements.image.posX = parseInt(parts[0], 10) || 50;
                state.theme.elements.image.posY = parseInt(parts[1], 10) || 50;
                setDirty(true);
                inspector();
                renderPreview();
            });
        });
        qsa('[data-skin]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                pushUndo();
                state.theme.mapCard = state.theme.mapCard || {};
                state.theme.mapCard.skin = b.getAttribute('data-skin');
                // چند مدل ابعاد پیشنهادی خودشان را دارند تا همان لحظه لوکس دیده شوند
                var tune = {
                    strip: { imageHeight: 60, radius: 14, width: 340 },
                    poster: { imageHeight: 150, radius: 20, width: 380 },
                    gold: { imageHeight: 110, radius: 20, width: 370 },
                    noir: { imageHeight: 110, radius: 16, width: 360 },
                    glass: { imageHeight: 100, radius: 22, width: 360 },
                    white: { imageHeight: 110, radius: 16, width: 360 },
                    emerald: { imageHeight: 110, radius: 20, width: 370 },
                    classic: { imageHeight: 110, radius: 18, width: 360 }
                }[state.theme.mapCard.skin];
                if (tune) {
                    state.theme.mapCard.imageHeight = tune.imageHeight;
                    state.theme.mapCard.radius = tune.radius;
                    state.theme.mapCard.width = tune.width;
                }
                setDirty(true);
                if (state.page !== 'map') { state.page = 'map'; qsa('[data-page]').forEach(function (x) { x.classList.toggle('is-on', x.getAttribute('data-page') === 'map'); }); }
                inspector();
                renderPreview();
            });
        });
        qsa('[data-marker]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                pushUndo();
                state.theme.marker = state.theme.marker || {};
                state.theme.marker.shape = b.getAttribute('data-marker');
                setDirty(true);
                inspector();
                renderPreview();
            });
        });
        qsa('[data-style]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                pushUndo();
                state.theme.card.style = b.getAttribute('data-style');
                if (state.theme.card.style === 'luxury') { state.theme.card.border = 'gold'; state.theme.card.shadow = 'luxury'; state.theme.card.radius = 18; }
                if (state.theme.card.style === 'minimal') { state.theme.card.border = 'none'; state.theme.card.shadow = 'none'; state.theme.card.radius = 8; }
                if (state.theme.card.style === 'glass') { state.theme.layout = 'glass'; }
                if (state.theme.card.style === 'modern') { state.theme.card.radius = 10; state.theme.card.shadow = 'soft'; }
                setDirty(true); renderPreview(); inspector();
            });
        });
        qsa('[data-adv]', host).forEach(function (b) {
            b.addEventListener('click', function () {
                var box = $('adv-' + b.getAttribute('data-adv'));
                if (box) box.classList.toggle('open');
            });
        });
        qsa('[data-path]', host).forEach(function (inp) {
            var ev = inp.type === 'range' || inp.type === 'color' || inp.type === 'checkbox' ? 'input' : 'change';
            inp.addEventListener(ev, function () {
                pushUndo();
                var v = inp.type === 'checkbox' ? inp.checked : inp.value;
                if (inp.getAttribute('data-path') === 'card.radiusPreset' && v !== '') {
                    state.theme.card.radius = parseInt(v, 10);
                } else {
                    setPath(state.theme, inp.getAttribute('data-path'), v);
                }
                setDirty(true);
                renderPreview();
            });
        });
        var search = qs('#dsSearch', host);
        if (search) search.addEventListener('input', function () {
            var q = search.value.trim();
            qsa('[data-set-key]', host).forEach(function (n) {
                n.style.display = !q || n.getAttribute('data-set-key').indexOf(q) !== -1 || (n.textContent || '').indexOf(q) !== -1 ? '' : 'none';
            });
        });
        var rs = qs('#dsResetSec', host);
        if (rs) rs.addEventListener('click', function () {
            pushUndo();
            if (state.panel === 'images') state.theme.elements.image = clone(defaultTheme().elements.image);
            if (state.panel === 'text') { state.theme.elements.title = clone(defaultTheme().elements.title); state.theme.elements.price = clone(defaultTheme().elements.price); }
            if (state.panel === 'effects') state.theme.card = clone(defaultTheme().card);
            if (state.panel === 'map') {
                state.theme.marker = clone(defaultTheme().marker);
                state.theme.mapCard = clone(defaultTheme().mapCard);
            }
            setDirty(true); inspector(); renderPreview();
        });
        var ra = qs('#dsResetAll', host);
        if (ra) ra.addEventListener('click', function () {
            if (!confirm('کل تم به پیش‌فرض برگردد؟')) return;
            api('reset_theme', {}).then(function (j) { if (j.draft) { state.theme = j.draft; setDirty(true); inspector(); renderPreview(); } });
        });
        var ex = qs('#dsExport', host);
        if (ex) ex.addEventListener('click', exportJson);
        var im = qs('#dsImport', host);
        if (im) im.addEventListener('click', importJson);
    }

    function renderLayers() {
        var box = $('dsLayers'); if (!box) return;
        var labels = { image: 'تصویر', badge: 'نشان', title: 'عنوان', location: 'موقعیت', features: 'مشخصات', price: 'قیمت', amen: 'امکانات', button: 'دکمه' };
        box.innerHTML = (state.theme.order || []).map(function (k) {
            return '<div class="ds-layer" draggable="true" data-el="' + k + '">☰ ' + (labels[k] || k) + '</div>';
        }).join('');
        var drag = null;
        qsa('.ds-layer', box).forEach(function (n) {
            n.addEventListener('dragstart', function () { drag = n.getAttribute('data-el'); });
            n.addEventListener('dragover', function (e) { e.preventDefault(); });
            n.addEventListener('drop', function (e) {
                e.preventDefault();
                var to = n.getAttribute('data-el');
                if (!drag || drag === to) return;
                pushUndo();
                var arr = state.theme.order.slice();
                var i = arr.indexOf(drag), j = arr.indexOf(to);
                arr.splice(i, 1);
                arr.splice(j, 0, drag);
                state.theme.order = arr;
                setDirty(true);
                renderLayers();
                renderPreview();
            });
            n.addEventListener('click', function () { state.selectedEl = n.getAttribute('data-el'); qsa('.ds-layer', box).forEach(function (x) { x.classList.toggle('is-on', x === n); }); });
        });
    }

    function renderHist() {
        var box = $('dsHist'); if (!box) return;
        if (!state.history.length) { box.innerHTML = '<div class="ds-help">هنوز نسخه‌ای منتشر نشده.</div>'; return; }
        box.innerHTML = state.history.map(function (h) {
            return '<div class="ds-layer">' + esc(h.name || 'تم') + ' · ' + esc((h.at || '').slice(0, 16)) + ' <button type="button" class="ds-chip" data-restore="' + esc(h.id) + '">بازیابی</button></div>';
        }).join('');
        qsa('[data-restore]', box).forEach(function (b) {
            b.addEventListener('click', function () {
                api('restore', { id: b.getAttribute('data-restore') }).then(function (j) {
                    if (j.draft) { state.theme = j.draft; setDirty(true); inspector(); renderPreview(); }
                });
            });
        });
    }

    function applyPreset(key) {
        pushUndo();
        var map = {
            luxury: { primary: '#064E4E', gold: '#D4AF37', background: '#FAFAF7', surface: '#FFFFFF' },
            teal: { primary: '#0F766E', gold: '#D4AF37', background: '#F4FAF8', surface: '#FFFFFF' },
            dark: { primary: '#72D2CC', gold: '#D4AF37', background: '#0B1717', surface: '#142525' },
            minimal: { primary: '#334155', gold: '#BFA46F', background: '#FFFFFF', surface: '#FFFFFF' },
            modern: { primary: '#0F2E5C', gold: '#C9A227', background: '#F8FAFC', surface: '#FFFFFF' }
        };
        var c = map[key]; if (!c) return;
        Object.keys(c).forEach(function (k) { state.theme.colors[k] = c[k]; });
        state.theme.preset = key;
        if (key === 'dark') state.theme.layout = 'dark';
        if (key === 'luxury') { state.theme.card.border = 'gold'; state.theme.card.shadow = 'luxury'; }
        if (key === 'minimal') { state.theme.layout = 'minimal'; state.theme.card.shadow = 'none'; }
        if (key === 'modern') state.theme.layout = 'modern';
        setDirty(true);
        inspector();
        renderPreview();
    }

    function defaultTheme() {
        return {
            version: 1, name: 'Melkino Luxury', layout: 'nx-01', favorites: ['nx-01', 'nx-06', 'nx-11'], custom: [],
            order: ['image', 'badge', 'title', 'location', 'features', 'price', 'amen', 'button'],
            elements: {
                image: { show: true, ratio: '16/10', height: 210, radius: 0, fit: 'cover', overlay: 'none', opacity: 1, brightness: 1, contrast: 1, posX: 50, posY: 50 },
                badge: { show: true, pos: 'tr', radius: 999, opacity: 1 },
                title: { show: true, size: 14, weight: 800, align: 'right', mb: 5, color: '' },
                price: { show: true, size: 14, weight: 900, align: 'left', color: '', prefix: '', suffix: ' تومان' },
                features: { show: true, items: { area: true, rooms: true, floor: true, year: true } },
                button: { show: true }, favorite: { show: true }, location: { show: true }, amen: { show: true }
            },
            card: { radius: 16, shadow: 'soft', border: 'subtle', hover: 'lift', hoverMs: 200, bg: '', style: 'soft' },
            grid: { desktop: 2, tablet: 2, mobile: 1 },
            mapCard: { skin: 'classic', width: 360, radius: 18, imageHeight: 110, shadow: 'strong', pos: 'bottom', bg: '', showImage: true, showKind: true, showAmen: true, showPrice: true, showButton: true, buttonText: 'مشاهده فایل' },
            marker: { shape: 'pin', size: 34, color: '', vipColor: '#D4AF37', stroke: '#FFFFFF', shadow: true, pulse: false, label: 'none' },
            colors: { primary: '#064E4E', secondary: '#0F766E', gold: '#D4AF37', background: '#FAFAF7', surface: '#FFFFFF', title: '', price: '', border: '' },
            darkColors: { primary: '#72D2CC', secondary: '#0F766E', gold: '#D4AF37', background: '#0B1717', surface: '#142525', title: '', price: '', border: '' },
            mode: 'system', preset: 'teal',
            deviceOverrides: { mobile: { imageHeight: 180, titleSize: 13, padding: 10 }, tablet: { imageHeight: 200, titleSize: 14, padding: 12 }, desktop: { imageHeight: 210, titleSize: 14, padding: 12 } }
        };
    }

    function api(action, payload) {
        var body = payload || {};
        body.action = action;
        if (window.MELKINO_CSRF) body.csrf_token = window.MELKINO_CSRF;
        var opt = { credentials: 'same-origin', cache: 'no-store' };
        if (action === 'get' || action === 'export') {
            return fetch('design-studio-api.php?action=' + encodeURIComponent(action), opt).then(function (r) { return r.json(); });
        }
        opt.method = 'POST';
        opt.headers = { 'Content-Type': 'application/json' };
        opt.body = JSON.stringify(body);
        return fetch('design-studio-api.php', opt).then(function (r) { return r.json(); });
    }

    function saveDraft() {
        var st = $('dsStatus'); if (st) st.textContent = 'در حال ذخیره…';
        api('save_draft', { theme: state.theme }).then(function (j) {
            if (j.success) { setDirty(false); if (st) { st.className = 'ds-status ok'; st.textContent = '✓ روی سایت اعمال شد'; } }
            else { if (st) st.textContent = j.message || 'خطا'; }
        }).catch(function () { if (st) st.textContent = 'خطا در ارتباط'; });
    }

    function publish() {
        if (!confirm('تم روی سایت عمومی اعمال شود؟')) return;
        var st = $('dsStatus'); if (st) st.textContent = 'در حال انتشار…';
        api('publish', { theme: state.theme, name: state.theme.name }).then(function (j) {
            if (j.success) {
                state.published = j.published;
                state.history = j.history || state.history;
                setDirty(false);
                if (st) { st.className = 'ds-status ok'; st.textContent = '✓ Theme منتشر شده'; }
            } else if (st) st.textContent = j.message || 'خطا';
        }).catch(function () { if (st) st.textContent = 'خطا در ارتباط'; });
    }

    function exportJson() {
        api('export', {}).then(function (j) {
            var blob = new Blob([JSON.stringify({ theme: j.theme }, null, 2)], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = j.filename || 'melkino-theme.json';
            a.click();
        });
    }
    function importJson() {
        var inp = document.createElement('input');
        inp.type = 'file'; inp.accept = 'application/json';
        inp.onchange = function () {
            var f = inp.files && inp.files[0]; if (!f) return;
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var data = JSON.parse(String(reader.result || ''));
                    api('import', { theme: data.theme || data }).then(function (j) {
                        if (j.draft) { state.theme = j.draft; setDirty(true); inspector(); renderPreview(); }
                        else alert(j.message || 'ورود ناموفق');
                    });
                } catch (e) { alert('JSON نامعتبر است.'); }
            };
            reader.readAsText(f);
        };
        inp.click();
    }

    function openWizard() {
        var modal = $('dsModal');
        var step = 0;
        var w = { style: 'luxury', color: 'teal', layout: 'nx-01', radius: 16, shadow: 'soft' };
        function paint() {
            var steps = [
                { t: 'سبک', k: 'style', o: [['luxury','لوکس'],['modern','مدرن'],['minimal','مینیمال']] },
                { t: 'رنگ', k: 'color', o: [['teal','سبزآبی'],['dark','تیره'],['light','روشن']] },
                { t: 'مدل کارت', k: 'layout', o: Object.keys(CATALOG).slice(0, 8).map(function (id) { return [id, CATALOG[id].fa]; }) },
                { t: 'گردی', k: 'radius', o: [['0','تیز'],['16','نرم'],['28','گرد']] },
                { t: 'سایه', k: 'shadow', o: [['none','هیچ'],['soft','نرم'],['luxury','لوکس']] }
            ];
            if (step >= steps.length) {
                modal.className = 'ds-modal open';
                modal.innerHTML = '<div class="ds-modal-card"><h3>پیش‌نمایش سریع</h3><p class="ds-help">با اعمال، روی پیش‌نویس سوار می‌شود. برای سایت عمومی باید منتشر کنید.</p><div class="ds-row"><button type="button" class="ds-btn gold" id="dsWApply" style="color:#163434;background:#d4af37">اعمال</button><button type="button" class="ds-chip" id="dsWClose">انصراف</button></div></div>';
                $('dsWApply').onclick = function () {
                    pushUndo();
                    applyPreset(w.color === 'light' ? 'minimal' : w.color);
                    state.theme.layout = w.layout;
                    state.theme.card.radius = parseInt(w.radius, 10);
                    state.theme.card.shadow = w.shadow;
                    if (w.style === 'minimal') state.theme.layout = 'minimal';
                    if (w.style === 'modern') state.theme.layout = 'modern';
                    setDirty(true); modal.className = 'ds-modal'; inspector(); renderPreview();
                };
                $('dsWClose').onclick = function () { modal.className = 'ds-modal'; };
                return;
            }
            var s = steps[step];
            modal.className = 'ds-modal open';
            modal.innerHTML = '<div class="ds-modal-card"><div class="ds-help">مرحله ' + (step + 1) + ' از ' + steps.length + '</div><h3>' + s.t + '</h3><div class="ds-presets">' + s.o.map(function (o) {
                return '<button type="button" class="ds-chip' + (String(w[s.k]) === String(o[0]) ? ' is-on' : '') + '" data-v="' + o[0] + '">' + o[1] + '</button>';
            }).join('') + '</div><div class="ds-row"><button type="button" class="ds-btn" id="dsWNext">ادامه</button>'
                + (step > 0 ? '<button type="button" class="ds-chip" id="dsWBack">قبلی</button>' : '')
                + '<button type="button" class="ds-chip" id="dsWCancel">انصراف</button></div></div>';
            qsa('[data-v]', modal).forEach(function (b) {
                b.onclick = function () { w[s.k] = b.getAttribute('data-v'); paint(); };
            });
            $('dsWNext').onclick = function () { step++; paint(); };
            if ($('dsWBack')) $('dsWBack').onclick = function () { step--; paint(); };
            $('dsWCancel').onclick = function () { modal.className = 'ds-modal'; modal.innerHTML = ''; };
        }
        paint();
    }

    function drawersOpen() {
        return ($('dsNav') && $('dsNav').classList.contains('open')) ||
            ($('dsSide') && $('dsSide').classList.contains('open'));
    }
    function syncScrim() {
        // پوشش و قفل اسکرول فقط وقتی لازم است که منوها به‌صورت کشوی روی صفحه باشند (≤ ۱۱۰۰px).
        // روی دسکتاپ ستون‌ها ثابتند و باز بودن کلاس open نباید صفحه را بپوشاند.
        var drawerLayout = false;
        try { drawerLayout = window.matchMedia('(max-width: 1100px)').matches; } catch (e) {}
        var on = drawerLayout && drawersOpen();
        var s = $('dsScrim');
        if (s) s.classList.toggle('is-on', on);
        try { document.body.style.overflow = on ? 'hidden' : ''; } catch (e) {}
    }
    function closeDrawers() {
        if ($('dsNav')) $('dsNav').classList.remove('open');
        if ($('dsSide')) $('dsSide').classList.remove('open');
        syncScrim();
    }
    function openDrawer(which) {
        var nav = $('dsNav');
        var side = $('dsSide');
        var already = which === 'nav' ? (nav && nav.classList.contains('open')) : (side && side.classList.contains('open'));
        closeDrawers();
        if (already) return;
        if (which === 'nav' && nav) nav.classList.add('open');
        if (which === 'side' && side) side.classList.add('open');
        syncScrim();
    }

    function bindChrome() {
        qsa('#dsNav [data-panel]').forEach(function (b) {
            b.addEventListener('click', function () {
                state.panel = b.getAttribute('data-panel');
                if (state.panel === 'map' && state.page !== 'map') {
                    state.page = 'map';
                    qsa('[data-page]').forEach(function (x) { x.classList.toggle('is-on', x.getAttribute('data-page') === 'map'); });
                    renderPreview();
                }
                qsa('#dsNav [data-panel]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                inspector();
                if ($('dsNav')) $('dsNav').classList.remove('open');
                if ($('dsSide')) $('dsSide').classList.add('open');
                syncScrim();
            });
        });
        qsa('[data-page]').forEach(function (b) {
            b.addEventListener('click', function () {
                state.page = b.getAttribute('data-page');
                qsa('[data-page]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                renderPreview();
            });
        });
        qsa('[data-size]').forEach(function (b) {
            b.addEventListener('click', function () {
                state.size = b.getAttribute('data-size');
                qsa('[data-size]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
                renderPreview();
            });
        });
        $('dsDeviceSel').addEventListener('change', function () { state.device = this.value; inspector(); renderPreview(); });
        $('dsModeSel').addEventListener('change', function () { state.theme.mode = this.value; setDirty(true); inspector(); renderPreview(); });
        $('dsSaveBtn').addEventListener('click', saveDraft);
        $('dsPublishBtn').addEventListener('click', publish);
        $('dsWizardBtn').addEventListener('click', openWizard);
        $('dsUndoBtn').addEventListener('click', function () {
            if (!state.undo.length) return;
            state.redo.push(clone(state.theme));
            state.theme = state.undo.pop();
            setDirty(true); inspector(); renderPreview();
        });
        $('dsRedoBtn').addEventListener('click', function () {
            if (!state.redo.length) return;
            state.undo.push(clone(state.theme));
            state.theme = state.redo.pop();
            setDirty(true); inspector(); renderPreview();
        });
        $('dsCompareBtn').addEventListener('click', function () { state.compare = !state.compare; state.ba = false; this.classList.toggle('is-on', state.compare); renderPreview(); });
        $('dsBaBtn').addEventListener('click', function () {
            state.ba = !state.ba;
            this.classList.toggle('is-on', state.ba);
            renderPreview();
        });
        if ($('dsOpenNav')) $('dsOpenNav').addEventListener('click', function () { openDrawer('nav'); });
        if ($('dsOpenSide')) $('dsOpenSide').addEventListener('click', function () { openDrawer('side'); });
        if ($('dsScrim')) $('dsScrim').addEventListener('click', closeDrawers);
        qsa('[data-close-drawer]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeDrawers();
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDrawers();
        });
    }

    function boot(data) {
        state.theme = data.draft || defaultTheme();
        state.published = data.published || null;
        state.history = data.history || [];
        if($('dsModeSel'))$('dsModeSel').value=state.theme.mode||'system';
        setDirty(false);
        bindChrome();
        inspector();
        renderPreview();
    }

    window.initDesignStudio = function () {
        if (window.__dsReady) { renderPreview(); return; }
        window.__dsReady = true;
        api('get').then(boot).catch(function () { boot({ draft: defaultTheme() }); });
    };

    if (document.getElementById('dsApp')) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', window.initDesignStudio);
        else window.initDesignStudio();
    }
})();
