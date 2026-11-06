(function () {
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function api(action, opts) {
        const method = (opts && opts.method) || 'GET';
        const extra = (opts && opts.query) ? ('&' + opts.query) : '';
        const url = 'admin-leads-api.php?action=' + encodeURIComponent(action) + extra;
        const init = { method: method, credentials: 'same-origin', cache: 'no-store', headers: {} };
        if (method === 'POST') {
            init.headers['Content-Type'] = 'application/json; charset=UTF-8';
            init.body = JSON.stringify(Object.assign({ action: action }, (opts && opts.body) || {}));
        }
        const res = await fetch(url, init);
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.success === false) {
            throw new Error(data.message || 'خطا');
        }
        return data;
    }

    async function sendSms(action, body, btn) {
        const prev = btn ? btn.textContent : '';
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'در حال ارسال…';
        }
        try {
            const data = await api(action, { method: 'POST', body: body });
            if (btn) btn.textContent = 'ارسال شد';
            alert(data.message || 'پیامک ارسال شد.');
            loadLog();
        } catch (e) {
            alert(e.message || 'ارسال نشد.');
            if (btn) btn.textContent = prev;
        } finally {
            if (btn) {
                setTimeout(function () {
                    btn.disabled = false;
                    btn.textContent = prev;
                }, 1600);
            }
        }
    }

    async function loadMatches() {
        const el = document.getElementById('matchList');
        try {
            const data = await api('matches');
            const rows = data.rows || [];
            const countEl = document.getElementById('matchCount');
            if (countEl) countEl.textContent = rows.length + ' درخواست';
            if (!rows.length) {
                el.innerHTML = '<div class="muted">هنوز درخواستی با فایل منطبق ثبت نشده.</div>';
                return;
            }
            el.innerHTML = rows.map(function (r) {
                const id = Number(r.request_id || 0);
                return '<div class="leads-row">' +
                    '<div><strong>' + esc(r.last_name || 'بدون نام') + '</strong>' +
                    '<small>کد ' + esc(r.tracking_code || '—') + ' · ' + esc(r.transaction_type || '') + ' ' + esc(r.property_type || '') + ' · ' + esc(r.location || '') + '</small></div>' +
                    '<div dir="ltr">' + esc(r.phone || '—') + '</div>' +
                    '<div>' + esc(r.match_count) + ' فایل</div>' +
                    '<div></div>' +
                    '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
                    '<button type="button" class="btn-sms" data-sms-matches="' + id + '">پیامک فایل‌ها</button>' +
                    '<button type="button" class="btn-sms" style="background:#1f6feb;" data-sms-count="' + id + '">پیامک تعداد</button>' +
                    '</div></div>';
            }).join('');
            el.querySelectorAll('[data-sms-matches]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    sendSms('sms_matches', { request_id: Number(btn.getAttribute('data-sms-matches')) }, btn);
                });
            });
            el.querySelectorAll('[data-sms-count]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    sendSms('sms_count', { request_id: Number(btn.getAttribute('data-sms-count')) }, btn);
                });
            });
        } catch (e) {
            el.innerHTML = '<div class="err">' + esc(e.message) + '</div>';
        }
    }

    async function loadViewers() {
        const el = document.getElementById('viewerList');
        const min = document.getElementById('minViews');
        const n = min ? min.value : '3';
        el.innerHTML = 'در حال بارگذاری…';
        try {
            const data = await api('viewers', { query: 'min=' + encodeURIComponent(n) });
            const rows = data.rows || [];
            if (!rows.length) {
                el.innerHTML = '<div class="muted">کسی این تعداد بازدید روی یک آگهی ندارد.</div>';
                return;
            }
            el.innerHTML = rows.map(function (r) {
                const phone = String(r.phone || '').trim();
                const can = /^09\d{9}$/.test(phone.replace(/\D/g, '').replace(/^98/, '0'));
                const payload = encodeURIComponent(JSON.stringify({
                    phone: phone,
                    ad_id: r.ad_id,
                    ad_title: r.ad_title,
                    user_id: r.user_id || 0,
                    views: r.views
                }));
                return '<div class="leads-row">' +
                    '<div><strong>' + esc(r.ad_title || ('آگهی ' + r.ad_id)) + '</strong>' +
                    '<small>کد ملک ' + esc(r.ad_id) + ' · آخرین بازدید ' + esc(r.last_view || '') + '</small></div>' +
                    '<div>' + esc(r.name || 'بدون نام') + '<small dir="ltr">' + esc(phone || 'بدون موبایل') + '</small></div>' +
                    '<div><strong>' + esc(r.views) + '</strong> بار</div>' +
                    '<div></div>' +
                    '<div>' + (can
                        ? '<button type="button" class="btn-sms" data-sms-viewer="' + payload + '">پیامک پیگیری</button>'
                        : '<span class="muted">موبایل ندارد</span>') +
                    '</div></div>';
            }).join('');
            el.querySelectorAll('[data-sms-viewer]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    let body = {};
                    try { body = JSON.parse(decodeURIComponent(btn.getAttribute('data-sms-viewer') || '{}')); } catch (e) {}
                    sendSms('sms_viewer', body, btn);
                });
            });
        } catch (e) {
            el.innerHTML = '<div class="err">' + esc(e.message) + '</div>';
        }
    }

    async function loadLog() {
        const el = document.getElementById('logList');
        try {
            const data = await api('log');
            const rows = data.rows || [];
            if (!rows.length) {
                el.innerHTML = '<div class="muted">هنوز پیامکی از این صفحه ارسال نشده.</div>';
                return;
            }
            const kinds = { sms_matches: 'فایل‌های منطبق', sms_count: 'تعداد تطبیق', view_followup: 'پیگیری بازدید' };
            el.innerHTML = rows.map(function (r) {
                return '<div class="leads-row">' +
                    '<div><strong>' + esc(kinds[r.kind] || r.kind) + '</strong><small>' + esc(r.created_at || '') + '</small></div>' +
                    '<div dir="ltr">' + esc(r.phone) + '</div>' +
                    '<div class="' + (Number(r.success) ? 'ok' : 'err') + '">' + (Number(r.success) ? 'ارسال شد' : 'ناموفق') + '</div>' +
                    '<div class="muted">' + esc(r.result_message || '') + '</div>' +
                    '<div></div></div>';
            }).join('');
        } catch (e) {
            el.innerHTML = '<div class="err">' + esc(e.message) + '</div>';
        }
    }

    const reload = document.getElementById('reloadViewers');
    if (reload) reload.addEventListener('click', loadViewers);
    const min = document.getElementById('minViews');
    if (min) min.addEventListener('change', loadViewers);

    loadMatches();
    loadViewers();
    loadLog();
})();
