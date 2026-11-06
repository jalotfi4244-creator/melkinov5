/* ملکینو — ورود مینی‌اپ قبل از خانه و تور */
(function (w, d) {
    'use strict';
    var cfg = w.MELKINO_AUTH || {};
    var gate = d.getElementById('mkAuthGate');
    var bar = d.getElementById('mkAuthBar');
    var titleEl = d.getElementById('mkAuthTitle');
    var subEl = d.getElementById('mkAuthSub');
    var inflight = false;
    var finished = false;
    var pct = 8;
    var tick = null;

    function setText(t, s) {
        if (titleEl && t) titleEl.textContent = t;
        if (subEl && s) subEl.textContent = s;
    }
    function setPct(n) {
        pct = Math.max(pct, Math.min(100, n));
        if (bar) bar.style.width = pct + '%';
    }
    function fireReady() {
        if (finished) return;
        finished = true;
        if (tick) { try { clearInterval(tick); } catch (e) {} }
        setPct(100);
        w.MELKINO_AUTH_READY = true;
        try { d.documentElement.classList.remove('mk-auth-pending'); } catch (e) {}
        if (gate) {
            gate.classList.add('is-off');
            setTimeout(function () { try { gate.setAttribute('hidden', 'hidden'); } catch (x) {} }, 320);
        }
        try { w.dispatchEvent(new Event('melkino:auth-ready')); } catch (e) {}
    }
    function likelyMiniApp() {
        try { if (w.Telegram && w.Telegram.WebApp && w.Telegram.WebApp.initData) return true; } catch (e) {}
        try { if (w.Bale && w.Bale.WebApp && w.Bale.WebApp.initData) return true; } catch (e) {}
        try {
            var h = (w.location.hash || '').replace(/^#/, '');
            if (h && /tgWebAppData=/.test(h)) return true;
        } catch (e) {}
        var ua = '';
        try { ua = String(navigator.userAgent || ''); } catch (e) {}
        if (/Telegram|Bale|Eitaa|WebApp/i.test(ua)) return true;
        return false;
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
    function goHome(token) {
        var url = 'home.php';
        if (token) {
            try {
                var u = new URL('home.php', w.location.href);
                u.searchParams.set('t', token);
                url = u.pathname + u.search;
            } catch (e) {
                url = 'home.php?t=' + encodeURIComponent(token);
            }
        }
        w.location.replace(url);
    }
    function login() {
        if (inflight || finished) return false;
        var got = readInit();
        if (!got.data) return false;
        inflight = true;
        setText('در حال ورود', 'هویت پیام‌رسان در حال ثبت است');
        setPct(55);
        var ep = got.platform === 'eitaa' ? 'auth-eitaa.php' : (got.platform === 'bale' ? 'auth-bale.php' : 'auth-telegram.php');
        fetch(ep, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ init_data: got.data })
        }).then(function (r) { return r.text().then(function (t) { return { r: r, t: t }; }); })
        .then(function (x) {
            var j = {};
            var looksHtml = /^\s*<|^\s*\{|your connection|suspended|challenge/i.test(x.t || '');
            if (!looksHtml) { try { j = JSON.parse(x.t); } catch (e) { j = {}; } }
            if (!j || !j.success) {
                inflight = false;
                var srv = (j && j.message) ? String(j.message) : '';
                /* پاسخ HTML/چالش هاست یا خطای شبکه‌ای → یک‌بار رفرش کامل:
                   چالش امنیتی هاست‌ها فقط با ناوبری کامل تکمیل می‌شود
                   (همان کاری که «بستن و باز کردن مینی‌اپ» می‌کرد). */
                var suspect = looksHtml || !srv || x.r.status === 403 || x.r.status === 503 || x.r.status === 502;
                var n = 0;
                try { n = parseInt(sessionStorage.getItem('melkino_auth_reload') || '0', 10) || 0; } catch (eN) {}
                if (suspect && n < 1) {
                    try { sessionStorage.setItem('melkino_auth_reload', String(n + 1)); } catch (eS) {}
                    setText('یک لحظه…', 'در حال تازه‌سازی امن اتصال');
                    setTimeout(function () { w.location.reload(); }, 600);
                    return;
                }
                setText('ورود انجام نشد', srv || ('پاسخ سرور: ' + x.r.status));
                return;
            }
            try { sessionStorage.removeItem('melkino_auth_reload'); } catch (eC) {}
            try { sessionStorage.setItem('melkino_profile_synced', 'ok'); } catch (e) {}
            try { sessionStorage.setItem('melkino_identified_ok', '1'); } catch (e) {}
            if (w.MELKINO_PROFILE) {
                w.MELKINO_PROFILE.logged_in = true;
                if (j.name) w.MELKINO_PROFILE.name = j.name;
                if (j.phone) w.MELKINO_PROFILE.phone = j.phone;
            }
            setPct(92);
            setText('ورود انجام شد', 'در حال باز کردن خانه');
            if (cfg.go_home) {
                goHome(j.login_token || '');
                return;
            }
            fireReady();
        }).catch(function () {
            inflight = false;
        });
        return true;
    }

    if (cfg.logged_in) {
        fireReady();
        return;
    }

    if (likelyMiniApp()) {
        try { d.documentElement.classList.add('mk-auth-pending'); } catch (e) {} /* فقط برای تور */
        if (gate) {
            gate.removeAttribute('hidden');
            gate.classList.remove('is-off');
        }
        tick = setInterval(function () { setPct(pct + 3); }, 280);
        var tries = 0;
        var timer = setInterval(function () {
            tries++;
            login();
            if (finished || tries > 40) clearInterval(timer);
        }, 200);
        login();
        setTimeout(function () {
            if (!finished) {
                setText('ادامه می‌دهیم', 'اگر ورود کامل نشد، از خانه دوباره باز کنید');
                fireReady();
            }
        }, 9000);
        return;
    }

    var waitSdk = 0;
    var sdkTimer = setInterval(function () {
        waitSdk++;
        if (likelyMiniApp() || readInit().data) {
            clearInterval(sdkTimer);
            try { d.documentElement.classList.add('mk-auth-pending'); } catch (e) {}
            if (gate) {
                gate.removeAttribute('hidden');
                gate.classList.remove('is-off');
            }
            login();
            var tries = 0;
            var timer = setInterval(function () {
                tries++;
                login();
                if (finished || tries > 30) clearInterval(timer);
            }, 200);
            setTimeout(function () { if (!finished) fireReady(); }, 8000);
            return;
        }
        if (waitSdk > 3) {
            clearInterval(sdkTimer);
            fireReady();
        }
    }, 150);
})(window, document);
