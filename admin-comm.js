(function () {
    'use strict';
    var view = 'dash';
    var msgSub = 'send';
    var reportTab = 'sms';
    var selected = {};
    var page = 1;
    var sendStep = 1;
    var sendState = { who: 'person', role: '', criteria: {}, phones: '', ids: [], body: '', tpl: 0, when: 'now', send_at: '' };

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function faStatus(s) {
        var m = { draft: 'پیش‌نویس', completed: 'ارسال شده', failed: 'ناموفق', open: 'باز', WAITING: 'منتظر زمان مناسب', SENT: 'ارسال شد', FAILED: 'ناموفق', active: 'فعال', queued: 'در صف ارسال', DELIVERED: 'تحویل شده', deferred: 'منتظر زمان مناسب' };
        return m[s] || s || '—';
    }
    function person(r) {
        return (r && (r.name || r.last_name)) || 'بدون نام';
    }
    function idCell(v) {
        v = String(v || '').trim();
        return v ? '<span dir="ltr">' + esc(v) + '</span>' : '—';
    }
    function linkCell(url, label) {
        url = String(url || '').trim();
        if (!url) return '—';
        return '<a class="cm-btn dim" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    }

    function injectCss() {
        if (document.getElementById('comm-css')) return;
        var st = document.createElement('style');
        st.id = 'comm-css';
        st.textContent =
            '#tab-comm{gap:0;padding:0!important;background:transparent}' +
            '.cm-app{display:grid;grid-template-columns:210px 1fr;min-height:70vh;background:#FAFAF7;border:1px solid #c9d9d6;border-radius:18px;overflow:hidden;color:#102f2c}' +
            '.cm-rail{background:#064E4E;color:#fff;padding:16px 12px;display:flex;flex-direction:column;gap:6px}' +
            '.cm-rail h2{margin:0 8px 10px;font-size:15px;color:#fff;line-height:1.6}' +
            '.cm-rail button{display:block;width:100%;text-align:right;border:0;background:transparent;color:#e8f4f1;border-radius:12px;padding:10px 12px;font:inherit;font-size:13px;font-weight:800;cursor:pointer}' +
            '.cm-rail button.on{background:#0F766E;color:#fff}' +
            '.cm-stage{padding:16px 18px;background:#FAFAF7;min-width:0}' +
            '.cm-top{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px}' +
            '.cm-top h3{margin:0;font-size:18px;color:#064E4E}' +
            '.cm-search{min-height:40px;border-radius:12px;border:1px solid #9ec4be;padding:0 12px;min-width:180px;font:inherit;background:#fff;color:#102f2c}' +
            '.cm-btn{border:0;border-radius:12px;padding:9px 14px;font:inherit;font-size:13px;font-weight:800;cursor:pointer;background:#D4AF37;color:#102f2c}' +
            '.cm-btn.dim{background:#fff;color:#064E4E;border:1px solid #9ec4be}' +
            '.cm-btn.ghost{background:#0F766E;color:#fff}' +
            '.cm-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}' +
            '.cm-kpi{background:#fff;border:1px solid #c9d9d6;border-radius:16px;padding:12px 14px}' +
            '.cm-kpi b{display:block;font-size:22px;color:#064E4E}' +
            '.cm-kpi span{font-size:12px;font-weight:750;color:#3d5f5b}' +
            '.cm-card{background:#fff;border:1px solid #c9d9d6;border-radius:16px;overflow:hidden;margin-bottom:12px}' +
            '.cm-card h4{margin:0;padding:12px 14px;border-bottom:1px solid #e4eeec;color:#064E4E;font-size:14px}' +
            '.cm-pad{padding:12px 14px}' +
            '.cm-grid2{display:grid;grid-template-columns:1.2fr .8fr;gap:12px}' +
            '.cm-table{width:100%;border-collapse:collapse;font-size:13px}' +
            '.cm-table th,.cm-table td{padding:9px 6px;border-bottom:1px solid #e4eeec;text-align:right;color:#102f2c}' +
            '.cm-table th{color:#0F766E;font-weight:800;font-size:12px}' +
            '.cm-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}' +
            '.cm-chips button,.cm-pill{border:1px solid #9ec4be;background:#fff;color:#064E4E;border-radius:999px;padding:7px 12px;font:inherit;font-size:12px;font-weight:800;cursor:pointer}' +
            '.cm-chips button.on,.cm-pill.on{background:#064E4E;color:#fff;border-color:#064E4E}' +
            '.cm-field,select.cm-field,textarea.cm-field,.cm-tools input,.cm-tools select{min-height:42px;border-radius:12px;border:1px solid #9ec4be;background:#fff;color:#102f2c;padding:0 10px;font:inherit;width:100%;box-sizing:border-box}' +
            'textarea.cm-field{min-height:110px;padding:10px}' +
            '.cm-tools{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}' +
            '.cm-meta{font-size:12px;color:#3d5f5b;line-height:1.8}' +
            '.cm-att{padding:10px 0;border-bottom:1px solid #e4eeec}' +
            '.cm-att b{display:block;color:#064E4E}' +
            '.cm-steps{display:flex;gap:8px;margin-bottom:12px}' +
            '.cm-steps span{flex:1;text-align:center;padding:8px;border-radius:10px;background:#e7f3f0;color:#3d5f5b;font-size:12px;font-weight:800}' +
            '.cm-steps span.on{background:#064E4E;color:#fff}' +
            '.cm-who{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}' +
            '.cm-who button{min-height:72px;border:1px solid #9ec4be;background:#fff;border-radius:14px;font:inherit;font-weight:800;color:#064E4E;cursor:pointer}' +
            '.cm-who button.on{border-color:#D4AF37;background:#fff8e6}' +
            '.cm-when{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' +
            '.cm-when button{min-height:64px;border:1px solid #9ec4be;background:#fff;border-radius:14px;font:inherit;font-weight:800;color:#064E4E;cursor:pointer}' +
            '.cm-when button.on{background:#064E4E;color:#fff}' +
            '.cm-adv{margin-top:10px;border:1px dashed #9ec4be;border-radius:12px;padding:8px 10px}' +
            '.cm-drawer{position:fixed;inset:0;background:rgba(6,78,78,.45);z-index:9000;display:none;justify-content:flex-start}' +
            '.cm-drawer.on{display:flex}' +
            '.cm-side{width:min(440px,96vw);background:#FAFAF7;color:#102f2c;overflow:auto;padding:16px}' +
            '.cm-empty{padding:28px 12px;text-align:center;color:#3d5f5b}' +
            '.cm-cmd{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9100;display:none;align-items:flex-start;justify-content:center;padding:12vh 16px}' +
            '.cm-cmd.on{display:flex}' +
            '.cm-cmd box,.cm-cmd .box{width:min(520px,100%);background:#fff;border-radius:16px;padding:12px;box-shadow:0 12px 40px rgba(0,0,0,.2)}' +
            '.cm-mob{display:none}' +
            '@media(max-width:900px){.cm-app{grid-template-columns:1fr}.cm-rail{display:none}.cm-kpis,.cm-grid2,.cm-who,.cm-when{grid-template-columns:1fr}.cm-mob{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}.cm-mob button{flex:1;min-width:30%;border:1px solid #9ec4be;background:#fff;color:#064E4E;border-radius:10px;padding:8px;font:inherit;font-size:12px;font-weight:800}.cm-mob button.on{background:#064E4E;color:#fff}.cm-side{width:100%}}';
        document.head.appendChild(st);
    }

    function navItems() {
        return [
            ['dash', 'داشبورد'],
            ['book', 'دفترچه تلفن'],
            ['msg', 'پیام‌ها'],
            ['auto', 'اتوماسیون'],
            ['reports', 'گزارش‌ها'],
            ['set', 'تنظیمات']
        ];
    }

    function ensureTab() {
        injectCss();
        var panel = document.getElementById('tab-comm');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'tab-content';
            panel.id = 'tab-comm';
            var main = document.getElementById('mainContent') || document.body;
            main.appendChild(panel);
        }
        if (!document.getElementById('cmMain')) {
            panel.innerHTML =
                '<div class="cm-app">' +
                '<aside class="cm-rail"><h2>مرکز ارتباطات ملکینو</h2><div id="cmRail"></div></aside>' +
                '<section class="cm-stage">' +
                '<div class="cm-mob" id="cmMob"></div>' +
                '<div class="cm-top"><h3 id="cmTitle">داشبورد</h3><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">' +
                '<input class="cm-search" id="cmQ" placeholder="جستجوی مخاطب…">' +
                '<button type="button" class="cm-btn" id="cmGoSend">+ ارسال پیام</button></div></div>' +
                '<div id="cmMain"></div></section></div>' +
                '<div class="cm-drawer" id="cmDrawer"><div class="cm-side" id="cmSide"></div></div>' +
                '<div class="cm-cmd" id="cmCmd"><div class="box"><input class="cm-field" id="cmCmdIn" placeholder="ارسال پیام، دفترچه، گزارش امروز…"><div id="cmCmdOut" class="cm-pad"></div></div></div>';
        }
        bind();
        if (panel && panel.classList.contains('active')) {
            render();
        }
    }

    async function api(action, opt) {
        opt = opt || {};
        var url = 'admin-comm-api.php?action=' + encodeURIComponent(action) + (opt.query ? '&' + opt.query : '');
        var init = { credentials: 'same-origin', cache: 'no-store' };
        if (opt.body) {
            init.method = 'POST';
            init.headers = { 'Content-Type': 'application/json; charset=UTF-8' };
            init.body = JSON.stringify(Object.assign({ action: action }, opt.body));
        }
        var res = await fetch(url, init);
        if (opt.blob) return res;
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.success === false) throw new Error(data.message || 'خطا');
        return data;
    }

    function titles() {
        return { dash: 'داشبورد', book: 'دفترچه تلفن', msg: 'پیام‌ها', auto: 'اتوماسیون', reports: 'گزارش‌ها', set: 'تنظیمات' };
    }

    function paintNav() {
        var html = navItems().map(function (it) {
            return '<button type="button" data-view="' + it[0] + '" class="' + (view === it[0] ? 'on' : '') + '">' + it[1] + '</button>';
        }).join('');
        var r = document.getElementById('cmRail');
        var m = document.getElementById('cmMob');
        if (r) r.innerHTML = html;
        if (m) m.innerHTML = html;
        var t = document.getElementById('cmTitle');
        if (t) t.textContent = titles()[view] || 'مرکز ارتباطات';
    }

    async function showDash() {
        var data = await api('dashboard');
        var k = data.kpis || {};
        var t = data.today || {};
        var need = (k.followups || 0) + (t.deferred || 0) + (t.visits_tomorrow || 0);
        var att = (data.attention || []).map(function (a) {
            var btn = a.id ? '<button class="cm-btn dim" data-hot="' + a.id + '">مشاهده</button>' : '';
            return '<div class="cm-att"><b>' + esc(a.title) + '</b><div class="cm-meta">' + esc(a.text) + '</div>' + btn + '</div>';
        }).join('') || '<div class="cm-empty">مورد فوری با شواهد کافی نبود.</div>';
        document.getElementById('cmMain').innerHTML =
            '<div class="cm-kpis">' +
            '<div class="cm-kpi"><b>' + esc(k.sms_today || 0) + '</b><span>ارسال امروز</span></div>' +
            '<div class="cm-kpi"><b>' + esc(k.sms_ok || 0) + '</b><span>ارسال موفق</span></div>' +
            '<div class="cm-kpi"><b>' + esc(need) + '</b><span>نیازمند توجه</span></div>' +
            '<div class="cm-kpi"><b>' + (data.sms_on ? 'فعال' : 'خاموش') + '</b><span>سرویس پیامک (تب ربات)</span></div></div>' +
            '<div class="cm-chips">' +
            '<button type="button" class="cm-btn" id="qaSend">+ ارسال پیام</button>' +
            '<button type="button" class="cm-btn dim" data-go="book">دفترچه تلفن</button>' +
            '<button type="button" class="cm-btn dim" data-go="auto">ساخت اعلان</button>' +
            '<button type="button" class="cm-btn dim" data-go="reports">گزارش</button></div>' +
            '<div class="cm-grid2"><div class="cm-card"><h4>امروز</h4><div class="cm-pad cm-meta">' +
            esc(t.ads || 0) + ' آگهی جدید<br>' +
            esc(t.requests || 0) + ' درخواست جدید<br>' +
            esc(t.visits || 0) + ' بازدید ثبت‌شده<br>' +
            esc(t.hot || 0) + ' لید با مشاهده تکراری<br>' +
            esc(t.reminders || 0) + ' پیگیری باز' +
            '<p>رقم ساختگی نیست؛ از جداول ملکینو است. صفر یعنی موردی نبود.</p></div></div>' +
            '<div class="cm-card"><h4>نیازمند توجه</h4><div class="cm-pad">' + att +
            '<button class="cm-btn dim" data-go="book" style="margin-top:8px">مشاهده همه مخاطبین</button></div></div></div>';
        document.getElementById('qaSend').onclick = function () { openSend(); };
        document.getElementById('cmMain').querySelectorAll('[data-go]').forEach(function (b) {
            b.onclick = function () { view = b.getAttribute('data-go'); render(); };
        });
        document.getElementById('cmMain').querySelectorAll('[data-hot]').forEach(function (b) {
            b.onclick = function () { openContact(Number(b.getAttribute('data-hot'))); };
        });
    }

    async function showBook() {
        var qEl = document.getElementById('bkQ');
        var roleEl = document.getElementById('bkRole');
        var query = 'page=' + page;
        if (qEl && qEl.value) query += '&search=' + encodeURIComponent(qEl.value);
        if (roleEl && roleEl.value) query += '&role=' + encodeURIComponent(roleEl.value);
        var data = await api('contacts', { query: query });
        var rows = (data.rows || []).map(function (r) {
            var st = r.status === 'active' ? 'فعال' : faStatus(r.status);
            return '<tr><td>' + esc(person(r)) + '</td><td dir="ltr">' + esc(r.phone) + '</td><td>' + idCell(r.telegram_id) + '</td><td>' + linkCell(r.telegram_link, 'تلگرام') + '</td><td>' + idCell(r.bale_id) + '</td><td>' + linkCell(r.bale_link, 'بله') + '</td><td>' + esc(r.roles_suggested || '—') + '</td><td>' + esc(r.last_activity || '—') + '</td><td>' + esc(st) + '</td><td><button class="cm-btn dim" data-open="' + r.id + '">مشاهده</button></td></tr>';
        }).join('');
        document.getElementById('cmMain').innerHTML =
            '<div class="cm-card"><h4>مخاطبین (' + esc(data.total || 0) + ')</h4><div class="cm-pad">' +
            '<div class="cm-tools"><input id="bkQ" class="cm-search" placeholder="جستجو نام یا موبایل" value="' + esc(qEl ? qEl.value : '') + '">' +
            '<button class="cm-btn" id="bkFind">جستجو</button>' +
            '<button class="cm-btn dim" id="bkAdd">+ مخاطب</button>' +
            '<button class="cm-btn dim" id="bkEx">خروجی Excel</button>' +
            '<button class="cm-btn dim" id="bkSync">همگام از ملکینو</button></div>' +
            '<div class="cm-chips" id="bkRoles">' +
            [['', 'همه'], ['مالک', 'مالک'], ['موجر', 'موجر'], ['مستأجر', 'مستأجر'], ['خریدار', 'خریدار'], ['فروشنده', 'فروشنده'], ['متقاضی', 'متقاضی']].map(function (x) {
                return '<button type="button" data-role="' + x[0] + '" class="' + ((roleEl && roleEl.value === x[0]) || (!roleEl && x[0] === '') ? 'on' : '') + '">' + x[1] + '</button>';
            }).join('') + '<input type="hidden" id="bkRole" value="' + esc(roleEl ? roleEl.value : '') + '"></div>' +
            (rows ? '<table class="cm-table"><thead><tr><th>نام</th><th>موبایل</th><th>آیدی تلگرام</th><th>لینک تلگرام</th><th>آیدی بله</th><th>لینک بله</th><th>نقش پیشنهادی</th><th>آخرین فعالیت</th><th>وضعیت</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' :
                '<div class="cm-empty">هنوز مخاطبی اضافه نشده.<br><button class="cm-btn" id="bkEmptyAdd" style="margin-top:8px">+ افزودن مخاطب</button></div>') +
            '<div class="cm-tools" style="margin-top:10px"><button class="cm-btn dim" id="cmPrev">قبل</button><button class="cm-btn dim" id="cmNext">بعد</button>' +
            '<details class="cm-adv"><summary>فیلتر بیشتر / ورود CSV</summary><p class="cm-meta">نقش روی کارت پیشنهادی است نه قطعی. ورود CSV در تنظیمات → پیشرفته.</p></details></div></div></div>';
        document.getElementById('bkFind').onclick = function () { page = 1; showBook(); };
        document.getElementById('bkAdd').onclick = addContact;
        var empty = document.getElementById('bkEmptyAdd');
        if (empty) empty.onclick = addContact;
        document.getElementById('bkEx').onclick = exportXls;
        document.getElementById('bkSync').onclick = async function () {
            var d = await api('sync', { body: {} });
            alert(d.synced + ' مخاطب از دیتابیس ملکینو همگام شد.');
            showBook();
        };
        document.getElementById('bkRoles').querySelectorAll('[data-role]').forEach(function (b) {
            b.onclick = function () { document.getElementById('bkRole').value = b.getAttribute('data-role'); page = 1; showBook(); };
        });
        document.getElementById('cmPrev').onclick = function () { page = Math.max(1, page - 1); showBook(); };
        document.getElementById('cmNext').onclick = function () { page += 1; showBook(); };
        document.getElementById('cmMain').querySelectorAll('[data-open]').forEach(function (b) {
            b.onclick = function () { openContact(Number(b.getAttribute('data-open'))); };
        });
    }

    async function exportXls() {
        var role = document.getElementById('bkRole');
        var q = document.getElementById('bkQ');
        var query = '';
        if (q && q.value) query += 'search=' + encodeURIComponent(q.value);
        if (role && role.value) query += (query ? '&' : '') + 'role=' + encodeURIComponent(role.value);
        var res = await api('export', { query: query, blob: true });
        var blob = await res.blob();
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'melkino_contacts.xlsx';
        a.click();
    }

    function addContact() {
        var name = prompt('نام مخاطب');
        if (name === null) return;
        var phone = prompt('موبایل ۰۹');
        if (!phone) return;
        api('save_contact', { body: { name: name, phone: phone } }).then(function () { showBook(); }).catch(function (e) { alert(e.message); });
    }

    async function openContact(id) {
        var data = await api('contact', { query: 'id=' + id });
        var c = data.contact || {};
        var ads = (data.ads || []).map(function (a) { return '<div class="cm-meta">#' + esc(a.id) + ' ' + esc(a.title || '') + ' · ' + esc(a.status || '') + '</div>'; }).join('') || '<div class="cm-meta">ملکی با این موبایل نبود.</div>';
        var reqs = (data.requests || []).map(function (a) { return '<div class="cm-meta">' + esc(a.tracking_code || a.id) + ' · ' + esc(a.status || '') + '</div>'; }).join('') || '<div class="cm-meta">درخواستی نبود.</div>';
        var vis = (data.visits || []).map(function (a) { return '<div class="cm-meta">' + esc(a.ad_title || a.ad_id) + ' · ' + esc(a.status || '') + '</div>'; }).join('') || '<div class="cm-meta">بازدید نبود.</div>';
        var sms = (data.sms || []).map(function (a) { return '<div class="cm-meta">' + (Number(a.success) ? 'ارسال شد' : 'ناموفق') + ' · ' + esc(a.created_at) + '<br>' + esc(a.body) + '</div>'; }).join('') || '<div class="cm-meta">پیامی نبود.</div>';
        document.getElementById('cmSide').innerHTML =
            '<button class="cm-btn dim" id="cmClose">بستن</button>' +
            '<h3 style="color:#064E4E">' + esc(person(c)) + '</h3>' +
            '<div class="cm-meta" dir="ltr">' + esc(c.phone) + '</div>' +
            '<div class="cm-meta">آیدی تلگرام: <span dir="ltr">' + esc(c.telegram_id || '—') + '</span> · ' + linkCell(c.telegram_link, 'باز کردن تلگرام') + '</div>' +
            '<div class="cm-meta">آیدی بله: <span dir="ltr">' + esc(c.bale_id || '—') + '</span> · ' + linkCell(c.bale_link, 'باز کردن بله') + '</div>' +
            '<div class="cm-meta">نقش پیشنهادی: ' + esc(c.roles_suggested || '—') + ' — تأیید قطعی نیست</div>' +
            '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">' +
            '<button class="cm-btn" id="cmSmsOne">پیامک</button>' +
            '<a class="cm-btn dim" href="tel:' + esc(c.phone) + '">تماس</a></div>' +
            '<h4>درخواست‌ها</h4>' + reqs + '<h4>املاک مرتبط</h4>' + ads + '<h4>بازدیدها</h4>' + vis +
            '<h4>آخرین پیام‌ها</h4>' + sms +
            '<details class="cm-adv"><summary>مشاهده کامل / پیگیری</summary>' +
            '<textarea class="cm-field" id="cmNote" placeholder="یادداشت"></textarea>' +
            '<button class="cm-btn" id="cmNoteBtn" style="margin:8px 0">ثبت یادداشت</button>' +
            '<input class="cm-field" id="cmFuTitle" placeholder="عنوان پیگیری" style="margin-bottom:6px">' +
            '<input class="cm-field" id="cmFuDate" type="date" style="margin-bottom:6px">' +
            '<button class="cm-btn dim" id="cmFuBtn">ثبت پیگیری</button></details>';
        document.getElementById('cmDrawer').classList.add('on');
        document.getElementById('cmClose').onclick = function () { document.getElementById('cmDrawer').classList.remove('on'); };
        document.getElementById('cmSmsOne').onclick = function () {
            document.getElementById('cmDrawer').classList.remove('on');
            sendState = { who: 'person', role: '', criteria: {}, phones: c.phone, ids: [c.id], body: '', tpl: 0, when: 'now', send_at: '' };
            sendStep = 2;
            openSend();
        };
        document.getElementById('cmNoteBtn').onclick = async function () {
            var t = document.getElementById('cmNote').value.trim();
            if (!t) return;
            await api('note', { body: { contact_id: id, body: t } });
            openContact(id);
        };
        document.getElementById('cmFuBtn').onclick = async function () {
            var t = document.getElementById('cmFuTitle').value.trim();
            if (!t) return;
            await api('followup', { body: { contact_id: id, title: t, due_date: document.getElementById('cmFuDate').value } });
            openContact(id);
        };
    }

    function openSend() {
        view = 'msg';
        msgSub = 'send';
        sendStep = sendStep || 1;
        render();
    }

    async function showMsg() {
        var chips = [['send', 'ارسال'], ['box', 'صندوق'], ['tpl', 'قالب‌ها'], ['camp', 'کمپین']].map(function (x) {
            return '<button type="button" data-sub="' + x[0] + '" class="' + (msgSub === x[0] ? 'on' : '') + '">' + x[1] + '</button>';
        }).join('');
        var inner = document.createElement('div');
        document.getElementById('cmMain').innerHTML = '<div class="cm-chips" id="msgChips">' + chips + '</div><div id="msgBody"></div>';
        document.getElementById('msgChips').onclick = function (e) {
            var b = e.target.closest('[data-sub]');
            if (!b) return;
            msgSub = b.getAttribute('data-sub');
            showMsg();
        };
        if (msgSub === 'send') await showSend();
        else if (msgSub === 'box') await showLogs();
        else if (msgSub === 'tpl') await showTpl();
        else await showCamp();
        inner = null;
    }

    async function audienceCount() {
        var crit = {};
        if (sendState.who === 'group' && sendState.role) crit.role = sendState.role;
        if (sendState.who === 'filter') crit = sendState.criteria || {};
        if (!crit.role && !crit.hot && !crit.has_request && !crit.no_visit && !crit.days) {
            if (sendState.who === 'person' || sendState.who === 'manual') return { count: (sendState.phones ? sendState.phones.split(/[,\s]+/).filter(Boolean).length : 0) + (sendState.ids || []).length, sample: [] };
        }
        return api('audience', { body: { criteria: crit } });
    }

    async function showSend() {
        var tpls = [];
        try { tpls = (await api('templates')).rows || []; } catch (e) {}
        var host = document.getElementById('msgBody') || document.getElementById('cmMain');
        var steps = '<div class="cm-steps"><span class="' + (sendStep === 1 ? 'on' : '') + '">۱ گیرنده</span><span class="' + (sendStep === 2 ? 'on' : '') + '">۲ پیام</span><span class="' + (sendStep === 3 ? 'on' : '') + '">۳ زمان</span></div>';
        if (sendStep === 1) {
            host.innerHTML = steps + '<div class="cm-card"><h4>چه کسی پیام را بگیرد؟</h4><div class="cm-pad">' +
                '<div class="cm-who">' +
                '<button type="button" data-who="person" class="' + (sendState.who === 'person' ? 'on' : '') + '">فرد</button>' +
                '<button type="button" data-who="group" class="' + (sendState.who === 'group' ? 'on' : '') + '">گروه نقش</button>' +
                '<button type="button" data-who="filter" class="' + (sendState.who === 'filter' ? 'on' : '') + '">فیلتر هوشمند</button>' +
                '<button type="button" data-who="manual" class="' + (sendState.who === 'manual' ? 'on' : '') + '">انتخاب دستی</button></div>' +
                '<div id="whoMore" style="margin-top:12px"></div>' +
                '<div class="cm-meta" id="audBox" style="margin:10px 0"></div>' +
                '<button class="cm-btn" id="to2">ادامه</button></div></div>';
            function more() {
                var el = document.getElementById('whoMore');
                if (sendState.who === 'person' || sendState.who === 'manual') {
                    el.innerHTML = '<input class="cm-field" id="cmPhones" placeholder="موبایل ۰۹ — چندتایی با ویرگول" value="' + esc(sendState.phones) + '">';
                } else if (sendState.who === 'group') {
                    el.innerHTML = '<select class="cm-field" id="cmRoleG"><option value="">انتخاب نقش پیشنهادی</option><option>مالک</option><option>موجر</option><option>مستأجر</option><option>خریدار</option><option>فروشنده</option><option>متقاضی</option></select><p class="cm-meta">نقش پیشنهادی است نه قطعی.</p>';
                    document.getElementById('cmRoleG').value = sendState.role;
                } else {
                    el.innerHTML = '<details open class="cm-adv"><summary>تنظیمات پیشرفته مخاطبان</summary>' +
                        '<select class="cm-field" id="fRole"><option value="">نقش</option><option>مالک</option><option>موجر</option><option>مستأجر</option><option>خریدار</option><option>فروشنده</option><option>متقاضی</option></select>' +
                        '<label class="cm-meta"><input type="checkbox" id="fHot"> لید vis زیاد</label> ' +
                        '<label class="cm-meta"><input type="checkbox" id="fReq"> دارای درخواست</label> ' +
                        '<label class="cm-meta"><input type="checkbox" id="fNoV"> بدون بازدید</label></details>';
                }
            }
            more();
            host.querySelectorAll('[data-who]').forEach(function (b) {
                b.onclick = function () { sendState.who = b.getAttribute('data-who'); showSend(); };
            });
            async function refreshAud() {
                grabWho();
                try {
                    var a = await audienceCount();
                    document.getElementById('audBox').innerHTML = 'مخاطبان: <b>' + esc(a.count || 0) + ' نفر</b>';
                } catch (e) { document.getElementById('audBox').textContent = ''; }
            }
            function grabWho() {
                var p = document.getElementById('cmPhones');
                if (p) sendState.phones = p.value;
                var g = document.getElementById('cmRoleG');
                if (g) sendState.role = g.value;
                sendState.criteria = {};
                if (document.getElementById('fRole') && document.getElementById('fRole').value) sendState.criteria.role = document.getElementById('fRole').value;
                if (document.getElementById('fHot') && document.getElementById('fHot').checked) sendState.criteria.hot = 1;
                if (document.getElementById('fReq') && document.getElementById('fReq').checked) sendState.criteria.has_request = 1;
                if (document.getElementById('fNoV') && document.getElementById('fNoV').checked) sendState.criteria.no_visit = 1;
            }
            host.addEventListener('change', refreshAud);
            document.getElementById('to2').onclick = function () { grabWho(); sendStep = 2; showSend(); };
            refreshAud();
            return;
        }
        if (sendStep === 2) {
            var opts = tpls.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('');
            host.innerHTML = steps + '<div class="cm-card"><h4>پیام</h4><div class="cm-pad">' +
                '<select class="cm-field" id="cmTpl"><option value="">نوشتن پیام</option>' + opts + '</select>' +
                '<textarea class="cm-field" id="cmBody" placeholder="متن پیام" style="margin-top:8px">' + esc(sendState.body) + '</textarea>' +
                '<div class="cm-tools" style="margin-top:8px"><button class="cm-btn dim" id="aiHelp">کمک برای متن</button>' +
                '<button class="cm-btn dim" id="vars">+ متغیرهای شخصی‌سازی</button></div>' +
                '<div class="cm-meta" id="varsBox" style="display:none">{{first_name}} {{full_name}} {{phone}} {{property_title}} {{visit_date}} {{link}}</div>' +
                '<div class="cm-meta" id="cmCount"></div>' +
                '<div style="display:flex;gap:8px"><button class="cm-btn dim" id="back1">بازگشت</button><button class="cm-btn" id="to3">ادامه</button></div></div></div>';
            var bodyEl = document.getElementById('cmBody');
            var map = {};
            tpls.forEach(function (t) { map[t.id] = t.body; });
            document.getElementById('cmTpl').onchange = function () {
                if (this.value && map[this.value]) bodyEl.value = map[this.value];
                sendState.tpl = Number(this.value || 0);
                count();
            };
            bodyEl.oninput = count;
            function count() {
                var t = bodyEl.value || '';
                document.getElementById('cmCount').textContent = t.length + ' کاراکتر · حدود ' + Math.max(1, Math.ceil(t.length / 70)) + ' پیامک برای فارسی';
            }
            count();
            document.getElementById('vars').onclick = function () {
                var b = document.getElementById('varsBox');
                b.style.display = b.style.display === 'none' ? 'block' : 'none';
            };
            document.getElementById('aiHelp').onclick = async function () {
                var want = prompt('چه پیامی می‌خواهید؟ مثلاً یادآوری بازدید دوستانه');
                if (!want) return;
                var r = await api('suggest_text', { body: { prompt: want } });
                if (confirm((r.note || '') + '\n\n' + r.text + '\n\nاستفاده از این متن؟')) bodyEl.value = r.text;
                count();
            };
            document.getElementById('back1').onclick = function () { sendState.body = bodyEl.value; sendStep = 1; showSend(); };
            document.getElementById('to3').onclick = function () { sendState.body = bodyEl.value; sendStep = 3; showSend(); };
            return;
        }
        host.innerHTML = steps + '<div class="cm-card"><h4>زمان ارسال</h4><div class="cm-pad">' +
            '<div class="cm-when">' +
            '<button type="button" data-when="now" class="' + (sendState.when === 'now' ? 'on' : '') + '">همین الان</button>' +
            '<button type="button" data-when="later" class="' + (sendState.when === 'later' ? 'on' : '') + '">بعداً</button>' +
            '<button type="button" data-when="plan" class="' + (sendState.when === 'plan' ? 'on' : '') + '">طبق برنامه</button></div>' +
            '<div id="whenMore" style="margin:12px 0"></div>' +
            '<details class="cm-adv"><summary>تنظیمات پیشرفته</summary><p class="cm-meta">سرویس پیامک از تب ربات است. ردیابی لینک http خودکار است. ساعات سکوت در تنظیمات. تلاش مجدد جدا ساخته نشده.</p></details>' +
            '<div class="cm-card" style="border:0"><div class="cm-pad" id="preview"></div></div>' +
            '<div style="display:flex;gap:8px"><button class="cm-btn dim" id="back2">ویرایش</button><button class="cm-btn" id="doSend">تأیید و ارسال</button></div></div></div>';
        function whenMore() {
            document.getElementById('whenMore').innerHTML = sendState.when === 'later' || sendState.when === 'plan'
                ? '<input class="cm-field" id="sendAt" type="datetime-local">' : '<p class="cm-meta">بلافاصله پس از تأیید ارسال می‌شود. اگر ساعات سکوت باشد، می‌رود منتظر زمان مناسب.</p>';
        }
        whenMore();
        host.querySelectorAll('[data-when]').forEach(function (b) {
            b.onclick = function () { sendState.when = b.getAttribute('data-when'); showSend(); };
        });
        (async function () {
            var a = { count: 0 };
            try { a = await audienceCount(); } catch (e) {}
            document.getElementById('preview').innerHTML =
                '<div class="cm-meta">گیرندگان: ' + esc(a.count || 0) + ' نفر<br>پیام:<br>' + esc((sendState.body || '').slice(0, 280)) +
                '<br>ارسال: ' + (sendState.when === 'now' ? 'همین الان' : 'زمان‌بندی') + '</div>';
        })();
        document.getElementById('back2').onclick = function () { sendStep = 2; showSend(); };
        document.getElementById('doSend').onclick = doSend;
    }

    async function doSend() {
        var at = document.getElementById('sendAt');
        if (at) sendState.send_at = at.value ? at.value.replace('T', ' ') + ':00' : '';
        var payload = { body: sendState.body, template_id: sendState.tpl, when: sendState.when === 'now' ? 'now' : 'later', send_at: sendState.send_at, phones: [], contact_ids: sendState.ids || [], criteria: {} };
        if (sendState.who === 'person' || sendState.who === 'manual') payload.phones = (sendState.phones || '').split(/[,\s]+/).filter(Boolean);
        if (sendState.who === 'group' && sendState.role) payload.criteria = { role: sendState.role };
        if (sendState.who === 'filter') payload.criteria = sendState.criteria || {};
        if (!payload.body) { alert('متن خالی است.'); return; }
        if (!confirm('بدون تأیید این مرحله چیزی ارسال نمی‌شود. ادامه؟')) return;
        try {
            var data = await api('send', { body: payload });
            alert('موفق ' + data.ok + ' · ناموفق ' + data.fail + ' · منتظر زمان مناسب ' + (data.deferred || 0));
            msgSub = 'box';
            sendStep = 1;
            render();
        } catch (e) { alert(e.message); }
    }

    async function showLogs() {
        var host = document.getElementById('msgBody') || document.getElementById('cmMain');
        var data = await api('logs');
        var rows = (data.rows || []).map(function (r) {
            return '<tr><td dir="ltr">' + esc(r.phone) + '</td><td>' + (Number(r.success) ? 'ارسال شد' : 'ناموفق') + '</td><td>' + esc(r.created_at) + '</td><td>' + esc(r.body) + '</td></tr>';
        }).join('');
        host.innerHTML = '<div class="cm-card"><h4>صندوق پیام‌ها</h4><div class="cm-pad">' +
            (rows ? '<table class="cm-table"><thead><tr><th>موبایل</th><th>وضعیت</th><th>زمان</th><th>متن</th></tr></thead><tbody>' + rows + '</tbody></table>' : '<div class="cm-empty">هنوز پیامی از این مرکز نرفته.</div>') + '</div></div>';
    }

    async function showTpl() {
        var host = document.getElementById('msgBody') || document.getElementById('cmMain');
        var data = await api('templates');
        var cards = (data.rows || []).map(function (t) {
            return '<div class="cm-card"><h4>' + esc(t.name) + '</h4><div class="cm-pad cm-meta">' + esc(t.body) + '<br><button class="cm-btn dim" data-del="' + t.id + '">حذف</button></div></div>';
        }).join('') || '<div class="cm-empty">قالبی نیست.</div>';
        host.innerHTML = '<button class="cm-btn" id="tplNew" style="margin-bottom:10px">قالب جدید</button>' + cards;
        document.getElementById('tplNew').onclick = async function () {
            var n = prompt('نام قالب');
            if (!n) return;
            var b = prompt('متن');
            if (!b) return;
            await api('save_template', { body: { name: n, body: b } });
            showTpl();
        };
        host.querySelectorAll('[data-del]').forEach(function (b) {
            b.onclick = async function () {
                if (!confirm('حذف شود؟')) return;
                await api('delete_template', { body: { id: Number(b.getAttribute('data-del')) } });
                showTpl();
            };
        });
    }

    async function showCamp() {
        var host = document.getElementById('msgBody') || document.getElementById('cmMain');
        var segs = [];
        try { segs = (await api('segments')).rows || []; } catch (e) {}
        var camps = (await api('campaigns')).rows || [];
        var opt = segs.map(function (s) { return '<option value="' + s.id + '">' + esc(s.name) + ' (' + s.count + ')</option>'; }).join('');
        var rows = camps.map(function (c) {
            return '<tr><td>' + esc(c.name) + '</td><td>' + esc(faStatus(c.status)) + '</td><td>' + esc(c.sent_n) + ' موفق / ' + esc(c.fail_n) + ' ناموفق</td><td><button class="cm-btn" data-run="' + c.id + '">تأیید و ارسال</button></td></tr>';
        }).join('');
        host.innerHTML = '<div class="cm-card"><h4>کمپین — بدون تأیید ارسال نمی‌شود</h4><div class="cm-pad">' +
            '<input class="cm-field" id="cpName" placeholder="نام" style="margin-bottom:6px">' +
            '<select class="cm-field" id="cpSeg" style="margin-bottom:6px"><option value="">همه فعال‌ها</option>' + opt + '</select>' +
            '<textarea class="cm-field" id="cpBody" placeholder="متن"></textarea>' +
            '<input class="cm-field" id="cpSchedule" type="datetime-local" style="margin:6px 0" title="زمان ارسال — خالی = پیش‌نویس دستی">' +
            '<details class="cm-adv"><summary>ساخت با توضیح (پیشنهاد متن)</summary><input class="cm-field" id="aiCamp" placeholder="برای خریدارهای فعال یک پیام دوستانه"><button class="cm-btn dim" id="aiCampGo" style="margin-top:6px">پیشنهاد متن</button></details>' +
            '<button class="cm-btn" id="cpSave" style="margin:8px 0">ذخیره پیش‌نویس</button>' +
            (rows ? '<table class="cm-table"><thead><tr><th>نام</th><th>وضعیت</th><th>نتیجه</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>' : '<div class="cm-empty">کمپینی نیست.</div>') +
            '<details class="cm-adv"><summary>سگمنت ذخیره‌شده</summary><div id="segBox"></div></details></div></div>';
        document.getElementById('cpSave').onclick = async function () {
            await api('campaigns', { body: { name: document.getElementById('cpName').value, body: document.getElementById('cpBody').value, segment_id: Number(document.getElementById('cpSeg').value || 0), scheduled_at: document.getElementById('cpSchedule') ? document.getElementById('cpSchedule').value : '' } });
            showCamp();
        };
        document.getElementById('aiCampGo').onclick = async function () {
            var r = await api('suggest_text', { body: { prompt: document.getElementById('aiCamp').value } });
            document.getElementById('cpBody').value = r.text;
            alert(r.note);
        };
        host.querySelectorAll('[data-run]').forEach(function (b) {
            b.onclick = async function () {
                if (!confirm('تأیید و ارسال این کمپین؟')) return;
                var r = await api('run_campaign', { body: { id: Number(b.getAttribute('data-run')) } });
                alert('مخاطب ' + r.recipients + ' · موفق ' + r.ok + ' · معلق ' + r.deferred);
                showCamp();
            };
        });
        try {
            var sg = await api('segments');
            document.getElementById('segBox').innerHTML = (sg.rows || []).map(function (s) {
                return '<div class="cm-meta">' + esc(s.name) + ' — ' + esc(s.count) + ' نفر</div>';
            }).join('') + '<input class="cm-field" id="segName" placeholder="نام سگمنت" style="margin:6px 0">' +
                '<select class="cm-field" id="segRole"><option value="">نقش</option><option>مالک</option><option>موجر</option><option>مستأجر</option><option>خریدار</option><option>فروشنده</option><option>متقاضی</option></select>' +
                '<button class="cm-btn dim" id="segSave" style="margin-top:6px">ذخیره سگمنت</button>';
            document.getElementById('segSave').onclick = async function () {
                var crit = {};
                if (document.getElementById('segRole').value) crit.role = document.getElementById('segRole').value;
                await api('segments', { body: { name: document.getElementById('segName').value, criteria: crit } });
                showCamp();
            };
        } catch (e) {}
    }

    async function showAuto() {
        var data = await api('automations');
        var cards = (data.rows || []).map(function (a) {
            var when = a.timing === 'now' ? 'فوری' : ('هر ' + a.interval_min + ' دقیقه');
            return '<div class="cm-card"><h4>' + esc(a.title) + '</h4><div class="cm-pad cm-meta">وقتی: ' + esc(eventFa(a.event_key)) +
                '<br>ارسال برای: ' + esc(a.recipient_type === 'admin' ? 'مدیر' : a.recipient_type) +
                '<br>زمان: ' + esc(when) +
                '<br>وضعیت: ' + (Number(a.enabled) ? 'فعال' : 'خاموش') +
                '<div style="margin-top:8px;display:flex;gap:6px">' +
                '<button class="cm-btn dim" data-tog="' + a.id + '" data-on="' + (Number(a.enabled) ? 0 : 1) + '">' + (Number(a.enabled) ? 'خاموش' : 'روشن') + '</button>' +
                '<button class="cm-btn" data-run="' + a.id + '">اجرا الان</button></div></div></div>';
        }).join('') || '<div class="cm-empty">اعلانی ساخته نشده.</div>';
        document.getElementById('cmMain').innerHTML =
            '<p class="cm-meta">به‌زبان آدمیزاد: «هر وقت درخواست جدید آمد، هر نیم‌ساعت به مدیر خبر بده.» روی هاست بدون کرون، با دکمه اجرا یا باز شدن پنل.</p>' +
            '<button class="cm-btn" id="autoNew" style="margin-bottom:10px">ساخت اعلان</button>' +
            '<div id="autoWiz"></div>' + cards;
        document.getElementById('autoNew').onclick = function () { autoWizard(); };
        document.getElementById('cmMain').querySelectorAll('[data-tog]').forEach(function (b) {
            b.onclick = async function () {
                await api('toggle_auto', { body: { id: Number(b.getAttribute('data-tog')), enabled: Number(b.getAttribute('data-on')) } });
                showAuto();
            };
        });
        document.getElementById('cmMain').querySelectorAll('[data-run]').forEach(function (b) {
            b.onclick = async function () {
                var r = await api('run_auto', { body: { id: Number(b.getAttribute('data-run')) } });
                alert(r.skipped ? r.message : ('ارسال با شمارش واقعی ' + r.count));
                showAuto();
            };
        });
    }

    function eventFa(k) {
        return { new_ad: 'آگهی جدید', new_request: 'درخواست جدید', visit: 'بازدید', visit_remind: 'یادآوری بازدید', hot_lead: 'لید vis زیاد', match: 'تطبیق جدید' }[k] || k;
    }

    function autoWizard() {
        var el = document.getElementById('autoWiz');
        el.innerHTML = '<div class="cm-card"><h4>اعلان جدید</h4><div class="cm-pad">' +
            '<div class="cm-meta">۱) چه اتفاقی؟</div><select class="cm-field" id="ev"><option value="new_request">درخواست جدید</option><option value="new_ad">آگهی جدید</option><option value="visit">بازدید</option><option value="visit_remind">یادآوری بازدید</option><option value="hot_lead">لید vis زیاد</option></select>' +
            '<div class="cm-meta">۲) برای چه کسی؟</div><select class="cm-field" id="whoA"><option value="admin">مدیر</option></select>' +
            '<div class="cm-meta">۳) چه پیامی؟</div><textarea class="cm-field" id="ab">ملکینو: {{count}} مورد جدید «{{event}}».</textarea>' +
            '<div class="cm-meta">۴) چه زمانی؟</div><select class="cm-field" id="tm"><option value="digest">گزارش تجمیعی هر ۳۰ دقیقه</option><option value="now">فوری</option><option value="daily">روزانه</option></select>' +
            '<details class="cm-adv"><summary>تنظیمات پیشرفته</summary><label class="cm-meta">حداقل تعداد <input class="cm-field" id="mn" type="number" value="1"></label><label class="cm-meta">بازه دقیقه <input class="cm-field" id="iv" type="number" value="30"></label></details>' +
            '<button class="cm-btn" id="autoSave" style="margin-top:8px">ذخیره</button></div></div>';
        document.getElementById('autoSave').onclick = async function () {
            var ev = document.getElementById('ev').value;
            await api('automations', { body: {
                title: eventFa(ev),
                event_key: ev,
                recipient_type: 'admin',
                body: document.getElementById('ab').value,
                timing: document.getElementById('tm').value,
                min_count: document.getElementById('mn').value,
                interval_min: document.getElementById('iv').value
            } });
            showAuto();
        };
    }

    async function showReports() {
        var d = await api('reports');
        var tabs = [['sms', 'پیامک'], ['rel', 'ارتباط'], ['work', 'عملکرد']].map(function (x) {
            return '<button type="button" data-rt="' + x[0] + '" class="' + (reportTab === x[0] ? 'on' : '') + '">' + x[1] + '</button>';
        }).join('');
        var body = '';
        if (reportTab === 'sms') {
            body = '<div class="cm-kpis"><div class="cm-kpi"><b>' + esc(d.sms.today) + '</b><span>ارسال</span></div><div class="cm-kpi"><b>' + esc(d.sms.ok) + '</b><span>موفق</span></div><div class="cm-kpi"><b>' + esc(d.sms.fail) + '</b><span>ناموفق</span></div><div class="cm-kpi"><b>' + esc(d.sms.waiting) + '</b><span>منتظر زمان مناسب</span></div></div><p class="cm-meta">تحویل اپراتور جداگانه نداریم؛ موفق یعنی پنل پیامک قبول کرده. هزینه از پنل پیامک است نه حدس.</p>';
        } else if (reportTab === 'rel') {
            var clk = (d.clicks || []).map(function (r) { return '<tr><td>' + esc(r.clicked_at) + '</td><td>' + esc(r.short_code) + '</td><td>' + esc(r.destination_url) + '</td></tr>'; }).join('');
            body = '<div class="cm-kpis"><div class="cm-kpi"><b>' + esc(d.rel.clicks) + '</b><span>کلیک امروز</span></div><div class="cm-kpi"><b>' + esc(d.rel.visits) + '</b><span>بازدید امروز</span></div><div class="cm-kpi"><b>' + esc(d.rel.requests) + '</b><span>درخواست امروز</span></div></div>' +
                (clk ? '<table class="cm-table"><thead><tr><th>زمان</th><th>کد</th><th>مقصد</th></tr></thead><tbody>' + clk + '</tbody></table>' : '<div class="cm-empty">کلیکی ثبت نشده.</div>');
        } else {
            body = '<div class="cm-kpis"><div class="cm-kpi"><b>' + esc(d.work.campaigns) + '</b><span>کمپین</span></div><div class="cm-kpi"><b>' + esc(d.work.autos) + '</b><span>اعلان</span></div><div class="cm-kpi"><b>' + esc(d.work.templates) + '</b><span>قالب</span></div></div>';
        }
        document.getElementById('cmMain').innerHTML = '<div class="cm-chips" id="rt">' + tabs + '</div><div class="cm-card"><div class="cm-pad">' + body + '</div></div>';
        document.getElementById('rt').onclick = function (e) {
            var b = e.target.closest('[data-rt]');
            if (!b) return;
            reportTab = b.getAttribute('data-rt');
            showReports();
        };
    }

    async function showSet() {
        var s = await api('settings');
        document.getElementById('cmMain').innerHTML =
            '<div class="cm-card"><h4>ساعات عدم ارسال</h4><div class="cm-pad">' +
            '<label class="cm-meta"><input type="checkbox" id="qEn"' + (s.quiet_enabled === '1' ? ' checked' : '') + '> فعال</label>' +
            '<div class="cm-tools"><input id="qS" class="cm-search" value="' + esc(s.quiet_start) + '"><input id="qE" class="cm-search" value="' + esc(s.quiet_end) + '"></div>' +
            '<label class="cm-meta"><input type="checkbox" id="qUrg"' + (s.urgent_bypass === '1' ? ' checked' : '') + '> پیام‌های فوری اعلان حتی در این ساعات بروند</label>' +
            '<p class="cm-meta">' + (s.quiet_now ? 'الان داخل ساعات سکوت است.' : 'الان خارج از ساعات سکوت است.') + '</p></div></div>' +
            '<div class="cm-card"><h4>سرویس پیامک</h4><div class="cm-pad cm-meta">سرویس اصلی: ' + (s.provider && s.provider.enabled ? 'فعال' : 'خاموش') +
            '<br>خط: ' + esc((s.provider && s.provider.sender) || '—') +
            '<br>آدرس از تب ربات خوانده می‌شود؛ اینجا حدس زده نمی‌شود.' +
            '<br>سقف روزانه هر شماره: <input id="qMax" class="cm-search" type="number" min="1" max="30" value="' + esc(s.max_sms_day) + '">' +
            '<br>موبایل مدیر برای اعلان‌ها: <input id="adPh" class="cm-search" dir="ltr" value="' + esc(s.admin_phone || '') + '">' +
            '<br><button class="cm-btn" id="qSave">ذخیره</button> <button class="cm-btn dim" id="qFlush">ارسال پیام‌های معلق</button></div></div>' +
            '<details class="cm-adv"><summary>تنظیمات پیشرفته — ورود CSV</summary>' +
            '<p class="cm-meta">ستون‌ها: phone,name,last_name,role</p>' +
            '<textarea class="cm-field" id="impCsv"></textarea>' +
            '<select class="cm-field" id="impMode" style="margin:8px 0"><option value="UPDATE_OR_CREATE">اگر بود به‌روز شود</option><option value="CREATE_ONLY">فقط جدید</option></select>' +
            '<button class="cm-btn dim" id="impGo">ورود</button></details>';
        document.getElementById('qSave').onclick = async function () {
            await api('settings', { body: { quiet_enabled: document.getElementById('qEn').checked ? 1 : 0, quiet_start: document.getElementById('qS').value, quiet_end: document.getElementById('qE').value, max_sms_day: document.getElementById('qMax').value, admin_phone: document.getElementById('adPh').value, urgent_bypass: document.getElementById('qUrg').checked ? 1 : 0 } });
            alert('ذخیره شد.');
        };
        document.getElementById('qFlush').onclick = async function () {
            var r = await api('flush_deferred', { body: {} });
            alert('ارسال شد ' + r.ok + ' / ناموفق ' + r.fail);
        };
        document.getElementById('impGo').onclick = async function () {
            var r = await api('import', { body: { csv: document.getElementById('impCsv').value, mode: document.getElementById('impMode').value } });
            alert('ایجاد ' + r.created + ' · به‌روز ' + r.updated + ' · نامعتبر ' + r.invalid);
        };
    }

    async function render() {
        paintNav();
        try {
            if (view === 'dash') await showDash();
            else if (view === 'book') await showBook();
            else if (view === 'msg') await showMsg();
            else if (view === 'auto') await showAuto();
            else if (view === 'reports') await showReports();
            else if (view === 'set') await showSet();
        } catch (e) {
            document.getElementById('cmMain').innerHTML = '<div class="cm-card"><div class="cm-pad">' + esc(e.message) + '</div></div>';
        }
    }

    function bind() {
        function onNav(e) {
            var b = e.target.closest('[data-view]');
            if (!b) return;
            view = b.getAttribute('data-view');
            if (view === 'msg') msgSub = 'send';
            render();
        }
        ['cmRail', 'cmMob'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el && !el.dataset.bound) { el.dataset.bound = '1'; el.addEventListener('click', onNav); }
        });
        var go = document.getElementById('cmGoSend');
        if (go && !go.dataset.bound) {
            go.dataset.bound = '1';
            go.onclick = function () { sendStep = 1; openSend(); };
        }
        var q = document.getElementById('cmQ');
        if (q && !q.dataset.bound) {
            q.dataset.bound = '1';
            q.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                view = 'book';
                render().then(function () {
                    var b = document.getElementById('bkQ');
                    if (b) { b.value = q.value; showBook(); }
                });
            });
        }
        var orig = window.switchTab;
        if (typeof orig === 'function' && !orig.__cmWrapped) {
            var wrapped = function (tabId) {
                orig(tabId);
                if (tabId === 'comm') render();
            };
            wrapped.__cmWrapped = true;
            window.switchTab = wrapped;
        }
        document.getElementById('cmDrawer').addEventListener('click', function (e) {
            if (e.target.id === 'cmDrawer') e.target.classList.remove('on');
        });
        if (!window.__cmCmd) {
            window.__cmCmd = true;
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    document.getElementById('cmCmd').classList.add('on');
                    document.getElementById('cmCmdIn').focus();
                }
            });
            document.getElementById('cmCmd').addEventListener('click', function (e) {
                if (e.target.id === 'cmCmd') e.target.classList.remove('on');
            });
            document.getElementById('cmCmdIn').addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                var t = this.value;
                document.getElementById('cmCmd').classList.remove('on');
                if (t.indexOf('ارسال') >= 0) { sendStep = 1; openSend(); }
                else if (t.indexOf('دفتر') >= 0) { view = 'book'; render(); }
                else if (t.indexOf('گزارش') >= 0) { view = 'reports'; render(); }
                else if (t.indexOf('اعلان') >= 0 || t.indexOf('اتوماسیون') >= 0) { view = 'auto'; render(); }
                else { view = 'book'; render(); }
            });
        }
    }

    window.mkCommEnsure = ensureTab;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureTab);
    else ensureTab();
})();
