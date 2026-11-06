/* ملکینو — موتور Guided / Product Tour (بدون وابستگی خارجی) */
(function (w, d) {
    'use strict';
    if (w.MelkinoTour) return;

    var CFG = w.MELKINO_TOUR || {};
    var STORE = 'melkino_tour_' + (CFG.tour || 'user') + '_v2';
    var RESUME = 'melkino_tour_resume';
    var root, spot, card, confirmEl, liveEl;
    var steps = [];
    var index = 0;
    var running = false;
    var lastFocus = null;
    var topic = '';
    var restorePreview = null;
    var revision = 0;

    function pageName() {
        var p = (location.pathname.split('/').pop() || 'home.php').toLowerCase();
        return p || 'home.php';
    }
    function enabledSteps() {
        return ((topic && CFG.guides && CFG.guides[topic]) ? CFG.guides[topic].steps : (CFG.steps || [])).filter(function (s) {
            return s && s.enabled !== false;
        });
    }
    function qs(sel, ctx) {
        if (!sel) return null;
        try { return (ctx || d).querySelector(sel); } catch (e) { return null; }
    }
    function pageOk(step) {
        if (!step || !step.page || step.page === '*') return true;
        var pages = Array.isArray(step.page) ? step.page : [step.page];
        return pages.some(function (page) {
            var wanted = new URL(page, location.href);
            var current = new URL(location.href);
            if (wanted.pathname !== current.pathname) return false;
            var ok = true;
            wanted.searchParams.forEach(function (value, key) {
                if (current.searchParams.get(key) !== value) ok = false;
            });
            // v2 overrides legacy on the server: don't accept a mixed-mode URL.
            if (wanted.searchParams.get('legacy') === '1' && current.searchParams.get('v2') === '1') ok = false;
            return ok;
        });
    }
    function findTarget(step) {
        if (!step || !step.target) return null;
        var sels = String(step.target).split(',');

        for (var i = 0; i < sels.length; i++) {
            var el = qs(sels[i].trim());
            if (!el) continue;
            if (el.getClientRects().length > 0) return el;
        }
        return null;
    }

    function build() {
        if (root) return;
        root = d.createElement('div');
        root.className = 'mk-tour-root';
        root.setAttribute('dir', 'rtl');
        root.innerHTML =
            '<div class="mk-tour-spot" aria-hidden="true"></div>' +
            '<div class="mk-tour-card" role="dialog" aria-modal="true" aria-labelledby="mkTourTitle" aria-describedby="mkTourDesc" tabindex="-1">' +
                '<div class="mk-tour-kicker" id="mkTourKicker">راهنمای ملکینو</div>' +
                '<h2 class="mk-tour-title" id="mkTourTitle"></h2>' +
                '<p class="mk-tour-desc" id="mkTourDesc"></p>' +
                '<div class="mk-tour-progress">' +
                    '<span class="mk-tour-count" id="mkTourCount"></span>' +
                    '<div class="mk-tour-dots" id="mkTourDots"></div>' +
                '</div>' +
                '<div class="mk-tour-actions" id="mkTourActions"></div>' +
                '<div class="mk-tour-skiprow"><button type="button" class="mk-tour-skip" id="mkTourSkip">رد کردن راهنما</button></div>' +
            '</div>' +
            '<div class="mk-tour-confirm" id="mkTourConfirm" role="alertdialog" aria-labelledby="mkTourConfirmT">' +
                '<div class="mk-tour-confirm-box">' +
                    '<p id="mkTourConfirmT">راهنما متوقف شود؟</p>' +
                    '<div class="mk-tour-actions">' +
                        '<button type="button" class="mk-tour-btn mk-tour-btn-primary" id="mkTourKeep">ادامه راهنما</button>' +
                        '<button type="button" class="mk-tour-btn mk-tour-btn-soft" id="mkTourExit">خروج از راهنما</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="mk-tour-live" id="mkTourLive" aria-live="polite"></div>';
        d.body.appendChild(root);
        spot = root.querySelector('.mk-tour-spot');
        card = root.querySelector('.mk-tour-card');
        confirmEl = root.querySelector('#mkTourConfirm');
        liveEl = root.querySelector('#mkTourLive');
        root.querySelector('#mkTourSkip').addEventListener('click', askSkip);
        root.querySelector('#mkTourKeep').addEventListener('click', function () { confirmEl.classList.remove('is-on'); });
        root.querySelector('#mkTourExit').addEventListener('click', function () { finish(true); });
        d.addEventListener('keydown', onKey);
        w.addEventListener('resize', onWin, { passive: true });
        w.addEventListener('scroll', onWin, { passive: true });
    }

    function onKey(e) {
        if (!running) return;
        if (e.key === 'Tab') {
            var dialog = confirmEl.classList.contains('is-on') ? confirmEl : card;
            var focusable = Array.from(dialog.querySelectorAll('button:not([disabled])')).filter(function (el) { return el.getClientRects().length; });
            if (focusable.length) {
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (!dialog.contains(d.activeElement) || (e.shiftKey && d.activeElement === first) || (!e.shiftKey && d.activeElement === last)) {
                    e.preventDefault(); (e.shiftKey ? last : first).focus();
                }
            }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            if (confirmEl.classList.contains('is-on')) confirmEl.classList.remove('is-on');
            else askSkip();
            return;
        }
        if (confirmEl.classList.contains('is-on')) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); next(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); prev(); }
        if (e.key === 'Enter' && e.target && e.target.tagName !== 'BUTTON') { e.preventDefault(); next(); }
    }
    function onWin() {
        if (!running) return;
        layout(steps[index]);
    }

    function askSkip() {
        confirmEl.classList.add('is-on');
        var b = root.querySelector('#mkTourKeep');
        if (b) b.focus();
    }

    function markDone() {
        try { localStorage.setItem(topic ? STORE + '_' + topic : STORE, 'done'); } catch (e) {}
        try { sessionStorage.removeItem(RESUME); } catch (e) {}
    }
    function isDone() {
        try { return localStorage.getItem(STORE) === 'done'; } catch (e) { return false; }
    }

    function saveResume(i, destination) {
        try {
            sessionStorage.setItem(RESUME, JSON.stringify({ tour: CFG.tour || 'user', topic: topic, i: i, step: steps[i] && steps[i].id, page: (new URL(destination || location.href, location.href)).pathname, time: Date.now() }));
        } catch (e) {}
    }
    function readResume() {
        try {
            var raw = sessionStorage.getItem(RESUME);
            if (!raw) return null;
            var o = JSON.parse(raw);
            if (!o || o.tour !== (CFG.tour || 'user') || o.page !== location.pathname || Date.now() - o.time > 1800000) return null;
            return o;
        } catch (e) { return null; }
    }

    function goPage(step, i) {
        var dest = Array.isArray(step.page) ? step.page[0] : step.page;
        if (!dest || dest === '*') return false;
        var url = new URL(dest, location.href);
        url.searchParams.set('mk_guide', topic || 'overview');
        url.searchParams.set('mk_step', steps[i].id);
        saveResume(i, url.href);
        location.href = url.href;
        return true;
    }

    function clearPreview() {
        if (restorePreview) { restorePreview(); restorePreview = null; }
    }
    function runAction(step) {
        if (!step || !step.action) return;
        var a = String(step.action);
        if (a.indexOf('preview:') === 0) {
            var selected = qs(a.slice(8));
            if (!selected) return;
            var panels = Array.from(selected.parentElement.querySelectorAll('.step-content'));
            var old = panels.map(function (el) { return [el, el.className, el.getAttribute('style')]; });
            panels.forEach(function (el) { el.classList.toggle('active', el === selected); el.style.display = el === selected ? 'flex' : 'none'; });
            restorePreview = function () { old.forEach(function (x) { x[0].className = x[1]; if (x[2] === null) x[0].removeAttribute('style'); else x[0].setAttribute('style', x[2]); }); };
            return;
        }
        if (a.indexOf('expand:') === 0) {
            var parts = a.slice(7).split('|');
            var box = qs(parts[0]);
            if (!box) return;
            var parent = box.closest('.adv-search');
            var oldClass = parent && parent.className;
            var oldStyle = box.getAttribute('style');
            var panes = Array.from(box.querySelectorAll('.adv-pane'));
            var paneClasses = panes.map(function (el) { return el.className; });
            if (parts[1]) panes.forEach(function (el) { el.classList.toggle('is-on', el.getAttribute('data-type') === parts[1]); });
            if (parent) parent.classList.add('is-open');
            box.style.display = 'block';
            restorePreview = function () { panes.forEach(function (el, i) { el.className = paneClasses[i]; }); if (parent) parent.className = oldClass; if (oldStyle === null) box.removeAttribute('style'); else box.setAttribute('style', oldStyle); };
            return;
        }
        if (a.indexOf('switchTab:') === 0 && typeof w.switchTab === 'function') {
            try { w.switchTab(a.slice(10)); } catch (e) {}
        }
    }

    function waitTarget(step, tries, cb) {
        var el = findTarget(step);
        if (el || tries <= 0 || !step.target) { cb(el); return; }
        setTimeout(function () { waitTarget(step, tries - 1, cb); }, 180);
    }

    function showStep(i) {
        var ticket = ++revision;
        clearPreview();
        steps = enabledSteps();
        if (!steps.length) { finish(true); return; }
        if (i < 0) i = 0;
        if (i >= steps.length) { finish(true); return; }
        var step = steps[i];
        index = i;
        if (!pageOk(step) && step.page && step.page !== '*') {
            if (goPage(step, i)) return;
        }
        runAction(step);
        waitTarget(step, step.target ? 10 : 0, function (el) {
            if (ticket !== revision) return;
            if (step.target && !el && !step.showIfMissing) {
                showStep(i + 1);
                return;
            }
            index = i;
            saveResume(i);
            render(step, el);
        });
    }

    function render(step, el) {
        running = true;
        root.classList.add('is-on');
        d.getElementById('mkTourTitle').textContent = step.title || '';
        d.getElementById('mkTourDesc').textContent = step.description || '';
        d.getElementById('mkTourKicker').textContent = step.kicker || 'راهنمای ملکینو';
        liveEl.textContent = (step.title || '') + ' — ' + (step.description || '');
        var n = steps.length;
        d.getElementById('mkTourCount').textContent = 'مرحله ' + toFa(index + 1) + ' از ' + toFa(n);
        var dots = d.getElementById('mkTourDots');
        dots.innerHTML = '';
        for (var i = 0; i < n; i++) {
            var b = d.createElement('button');
            b.type = 'button';
            b.className = 'mk-tour-dot' + (i === index ? ' is-on' : (i < index ? ' is-done' : ''));
            b.setAttribute('aria-label', 'مرحله ' + (i + 1));
            b.setAttribute('data-i', String(i));
            b.addEventListener('click', function () { showStep(parseInt(this.getAttribute('data-i'), 10)); });
            dots.appendChild(b);
        }
        var actions = d.getElementById('mkTourActions');
        actions.innerHTML = '';
        var last = index === n - 1;
        var first = index === 0;
        if (!first && !step.hidePrev) {
            actions.appendChild(btn('قبلی', 'mk-tour-btn mk-tour-btn-soft', prev));
        }
        if (first && !last && !step.target) {
            actions.appendChild(btn(step.cta || 'شروع کنیم', 'mk-tour-btn mk-tour-btn-gold', next));
            actions.appendChild(btn('رد کردن', 'mk-tour-btn mk-tour-btn-ghost', askSkip));
            d.getElementById('mkTourSkip').style.display = 'none';
        } else if (last) {
            actions.appendChild(btn(step.cta || 'شروع استفاده از ملکینو', 'mk-tour-btn mk-tour-btn-gold', function () { finish(true); }));
            d.getElementById('mkTourSkip').style.display = 'none';
        } else {
            actions.appendChild(btn('مرحله بعد', 'mk-tour-btn mk-tour-btn-primary', next));
            d.getElementById('mkTourSkip').style.display = '';
        }

        if (el) {
            try { el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' }); } catch (e) {}
            setTimeout(function () { layout(step, el); }, 280);
        } else {
            layout(step, null);
        }
        setTimeout(function () {
            var f = card.querySelector('.mk-tour-btn-gold, .mk-tour-btn-primary');
            if (f) f.focus();
        }, 40);
    }

    function btn(label, cls, fn) {
        var b = d.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = label;
        b.addEventListener('click', fn);
        return b;
    }

    function toFa(n) {
        return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }

    function layout(step, el) {
        el = el || findTarget(step);
        var pad = 8;
        if (el) {
            var r = el.getBoundingClientRect();
            spot.style.top = Math.max(0, r.top - pad) + 'px';
            spot.style.left = Math.max(0, r.left - pad) + 'px';
            spot.style.width = Math.min(w.innerWidth, r.width + pad * 2) + 'px';
            spot.style.height = Math.min(w.innerHeight, r.height + pad * 2) + 'px';
            spot.classList.add('is-on');
        } else {
            spot.classList.remove('is-on');
            spot.style.width = '0';
            spot.style.height = '0';
        }
        card.classList.add('is-on');
        placeCard(el, step && step.position);
    }

    function placeCard(el, preferred) {
        var cw = card.offsetWidth || 360;
        var ch = card.offsetHeight || 220;
        var vw = w.innerWidth, vh = w.innerHeight;
        var gap = 12, edge = 10;
        var x, y, pos = preferred || 'auto';
        if (!el) {
            x = Math.max(edge, (vw - cw) / 2);
            y = Math.max(edge, (vh - ch) / 2);
        } else {
            var r = el.getBoundingClientRect();
            var below = vh - r.bottom;
            var above = r.top;
            if (pos === 'auto') {
                if (below >= ch + 16) pos = 'bottom';
                else if (above >= ch + 16) pos = 'top';
                else if (r.left > vw / 2) pos = 'left';
                else pos = 'right';
            }
            if (pos === 'bottom') {
                y = r.bottom + gap;
                x = r.left + r.width / 2 - cw / 2;
            } else if (pos === 'top') {
                y = r.top - ch - gap;
                x = r.left + r.width / 2 - cw / 2;
            } else if (pos === 'left') {
                x = r.left - cw - gap;
                y = r.top + r.height / 2 - ch / 2;
            } else {
                x = r.right + gap;
                y = r.top + r.height / 2 - ch / 2;
            }
        }
        x = Math.min(Math.max(edge, x), vw - cw - edge);
        y = Math.min(Math.max(edge, y), vh - ch - edge);
        if (x + cw > vw - edge) x = vw - cw - edge;
        if (y + ch > vh - edge) y = vh - ch - edge;
        card.style.left = x + 'px';
        card.style.top = y + 'px';
    }

    function next() { showStep(index + 1); }
    function prev() { showStep(index - 1); }

    function finish(save) {
        running = false;
        revision++;
        clearPreview();
        try {
            var url = new URL(location.href);
            ['mk_guide', 'mk_step', 'mk_tour'].forEach(function (key) { url.searchParams.delete(key); });
            history.replaceState(null, '', url.href);
        } catch (e) {}
        if (save) markDone();
        try { sessionStorage.removeItem(RESUME); } catch (e) {}
        if (root) {
            root.classList.remove('is-on');
            card.classList.remove('is-on');
            spot.classList.remove('is-on');
            confirmEl.classList.remove('is-on');
        }
        if (lastFocus && lastFocus.focus) {
            try { lastFocus.focus(); } catch (e) {}
        }
    }

    function start(from, selectedTopic) {
        if (typeof selectedTopic === 'string') topic = CFG.guides && CFG.guides[selectedTopic] ? selectedTopic : '';
        build();
        lastFocus = d.activeElement;
        steps = enabledSteps();
        var i = typeof from === 'number' ? from : 0;
        running = true;
        showStep(i);
    }

    function reset() {
        try { localStorage.removeItem(topic ? STORE + '_' + topic : STORE); } catch (e) {}
        try { sessionStorage.removeItem(RESUME); } catch (e) {}
    }

    w.MelkinoTour = {
        start: start,
        next: next,
        prev: prev,
        stop: function () { finish(true); },
        reset: reset,
        replay: function () { topic = ''; reset(); start(0); },
        startTopic: function (name) { start(0, name); },
        isDone: isDone
    };

    function bindReplay() {
        var el = d.getElementById('mkTourReplayBtn');
        var help = d.getElementById('mkTourHelp');
        if (el) el.addEventListener('click', function (e) {
            e.preventDefault();
            if (help) {
                help.hidden = !help.hidden;
                el.setAttribute('aria-expanded', String(!help.hidden));
                if (!help.hidden) help.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else { start(0, ''); }
        });
        d.addEventListener('click', function (e) {
            var button = e.target.closest('[data-tour-topic]');
            if (!button) return;
            e.preventDefault();
            start(0, button.getAttribute('data-tour-topic'));
        });
        // A guided preview must never submit a real form or activate an underlying control.
        d.addEventListener('submit', function (e) { if (running) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);
        d.addEventListener('click', function (e) {
            if (running && root && !root.contains(e.target)) { e.preventDefault(); e.stopImmediatePropagation(); }
        }, true);
    }

    function readInit() {
        var tg = '', bl = '', ei = '';
        try { if (w.Eitaa && w.Eitaa.WebApp) ei = w.Eitaa.WebApp.initData || ''; } catch (e) {}
        try { if (w.Telegram && w.Telegram.WebApp) tg = w.Telegram.WebApp.initData || ''; } catch (e) {}
        try { if (w.Bale && w.Bale.WebApp) bl = w.Bale.WebApp.initData || ''; } catch (e) {}
        if (ei) return { platform: 'eitaa', data: ei };
        if (tg) return { platform: 'telegram', data: tg };
        if (bl) return { platform: 'bale', data: bl };
        try {
            var h = (w.location.hash || '').replace(/^#/, '');
            if (h) {
                var p = new URLSearchParams(h);
                var raw = p.get('tgWebAppData') || '';
                if (raw) {
                    var ua = navigator.userAgent || '';
                    var plat = /eitaa/i.test(ua) ? 'eitaa' : (/bale/i.test(ua) ? 'bale' : 'telegram');
                    return { platform: plat, data: raw };
                }
            }
        } catch (e) {}
        return { platform: '', data: '' };
    }
    function isMiniApp() {
        if (readInit().data) return true;
        var ua = '';
        try { ua = String(navigator.userAgent || ''); } catch (e) {}
        return /Telegram|Bale|Eitaa|WebApp/i.test(ua);
    }
    function isLoggedIn() {
        if (CFG.logged_in) return true;
        try { if (w.MELKINO_PROFILE && w.MELKINO_PROFILE.logged_in) return true; } catch (e) {}
        return false;
    }
    function showLoginWait() {
        if (d.getElementById('mkTourLoginWait')) return;
        var el = d.createElement('div');
        el.id = 'mkTourLoginWait';
        el.setAttribute('dir', 'rtl');
        el.innerHTML = '<div style="text-align:center;max-width:300px"><div style="color:#f5c518;font-weight:800;font-size:20px;margin-bottom:8px">در حال ورود</div><div style="color:#fff;font-size:13px;line-height:1.8;margin-bottom:18px">صبر کنید تا حساب پیام‌رسان ثبت شود؛ بعد پروفایل را می‌بینید.</div><div style="height:4px;border-radius:99px;background:#1c2a26;overflow:hidden"><i id="mkTourLoginBar" style="display:block;height:100%;width:18%;background:#f5c518"></i></div></div>';
        el.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:#0c1412;display:flex;align-items:center;justify-content:center;font-family:Tahoma,sans-serif';
        d.body.appendChild(el);
        var n = 18;
        el._t = setInterval(function () {
            n = Math.min(90, n + 4);
            var b = d.getElementById('mkTourLoginBar');
            if (b) b.style.width = n + '%';
        }, 250);
    }
    function hideLoginWait() {
        var el = d.getElementById('mkTourLoginWait');
        if (!el) return;
        try { clearInterval(el._t); } catch (e) {}
        el.remove();
    }
    function loginThen(ok, fail) {
        var got = readInit();
        if (!got.data) return false;
        var ep = got.platform === 'eitaa' ? 'auth-eitaa.php' : (got.platform === 'bale' ? 'auth-bale.php' : 'auth-telegram.php');
        fetch(ep, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ init_data: got.data })
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.success) {
                CFG.logged_in = true;
                if (w.MELKINO_PROFILE) w.MELKINO_PROFILE.logged_in = true;
                if (j.login_token) {
                    try {
                        var u = new URL(location.href);
                        u.searchParams.set('t', j.login_token);
                        hideLoginWait();
                        location.replace(u.toString());
                        return;
                    } catch (e) {}
                }
                ok();
                return;
            }
            fail();
        }).catch(function () { fail(); });
        return true;
    }
    function waitLogin(fn) {
        if (CFG.tour === 'admin') { fn(); return; }
        if (isLoggedIn()) { fn(); return; }
        if (!isMiniApp() && !readInit().data) { fn(); return; }
        showLoginWait();
        var tries = 0;
        var busy = false;
        var timer = setInterval(function () {
            tries++;
            if (isLoggedIn()) {
                clearInterval(timer);
                hideLoginWait();
                fn();
                return;
            }
            if (!busy) {
                busy = true;
                var started = loginThen(function () {
                    clearInterval(timer);
                    hideLoginWait();
                    fn();
                }, function () { busy = false; });
                if (!started) busy = false;
            }
            if (tries > 45) {
                clearInterval(timer);
                hideLoginWait();
            }
        }, 250);
    }

    function boot() {
        CFG = w.MELKINO_TOUR || CFG;
        bindReplay();
        if (CFG.enabled === false) return;
        var params = {};
        try { params = Object.fromEntries(new URLSearchParams(location.search)); } catch (e) {}
        if (params.mk_tour === 'reset') { reset(); }
        var force = params.mk_tour === '1' || params.mk_tour === 'start' || params.mk_tour === (CFG.tour || 'user');
        var chosen = params.mk_guide;
        if (chosen === 'overview' || (CFG.guides && Object.prototype.hasOwnProperty.call(CFG.guides, chosen))) {
            topic = chosen === 'overview' ? '' : chosen;
            var requested = enabledSteps().findIndex(function (s) { return s.id === params.mk_step; });
            waitLogin(function () { setTimeout(function () { start(Math.max(0, requested)); }, 400); });
            return;
        }
        var resume = readResume();
        if (resume && typeof resume.i === 'number') {
            topic = CFG.guides && CFG.guides[resume.topic] ? resume.topic : '';
            var resumed = enabledSteps().findIndex(function (s) { return s.id === resume.step; });
            waitLogin(function () { setTimeout(function () { start(Math.max(0, resumed)); }, 400); });
            return;
        }
        if (force) {
            reset();
            waitLogin(function () { setTimeout(function () { start(0); }, 400); });
            return;
        }
        if (CFG.auto && !isDone()) {
            var pg = pageName();
            var ok = (CFG.tour === 'admin')
                ? (pg === 'admin-panel.php')
                : (pg === 'home.php' || pg === 'profile.php');
            if (ok) waitLogin(function () { setTimeout(function () { start(0); }, 500); });
        }
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot);
    else boot();
})(window, document);
