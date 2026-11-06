(function () {
    'use strict';

    var state = { insights: [], filter: 'all', kpis: {}, run: null };

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function injectCss() {
        if (document.getElementById('ast-css')) return;
        var st = document.createElement('style');
        st.id = 'ast-css';
        st.textContent =
            '#tab-assistant{gap:14px}' +
            '.ast-hero{padding:20px 22px;border-radius:22px;background:linear-gradient(135deg,#052d2d 0%,#0b5d5b 70%);border:1px solid rgba(212,175,55,.22);color:#fff;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}' +
            '.ast-kicker{font-size:10px;letter-spacing:1.6px;color:#f4dc7a;font-weight:900}' +
            '.ast-hero h2{margin:4px 0 0;font-size:22px;font-weight:950;color:#fff}' +
            '.ast-hero p{margin:6px 0 0;font-size:12px;line-height:1.8;color:#e8f4f1;max-width:640px}' +
            '.ast-hero-actions{display:flex;gap:8px;flex-wrap:wrap}' +
            '.ast-btn{border:0;border-radius:12px;padding:10px 16px;font:inherit;font-size:14px;font-weight:800;cursor:pointer}' +
            '.ast-btn.gold{background:#f4dc7a;color:#102f2c!important}' +
            '.ast-btn.ghost{background:#f4dc7a;color:#102f2c!important;border:0;text-decoration:none;display:inline-flex;align-items:center}' +
            '.ast-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}' +
            '.ast-kpi{background:#e7f3f0;border:1px solid #9ec4be;border-radius:16px;padding:12px 14px;cursor:pointer;color:#102f2c}' +
            '.ast-kpi.on{background:#0E7C6E;border-color:#0E7C6E;color:#fff}' +
            '.ast-kpi b,.ast-kpi span{color:inherit}' +
            '.ast-kpi b{display:block;font-size:22px;font-weight:1000}' +
            '.ast-kpi span{font-size:12px;font-weight:750}' +
            '.ast-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.9fr);gap:14px;align-items:start}' +
            '.ast-card{background:#142524;border:1px solid #2f5552;border-radius:18px;overflow:hidden;color:#eef6f4}' +
            '.ast-card h3{margin:0;padding:14px 16px;font-size:14px;border-bottom:1px solid #2f5552;color:#f4dc7a}' +
            '.ast-list{padding:12px;display:flex;flex-direction:column;gap:10px;max-height:70vh;overflow:auto}' +
            '.ast-item{border:1px solid #3a5f5b;border-radius:16px;padding:12px 14px 12px 14px;background:#1a3331;color:#eef6f4;position:relative}' +
            '.ast-item:before{content:"";position:absolute;top:10px;bottom:10px;right:0;width:4px;border-radius:4px;background:#94a3b8}' +
            '.ast-item.p-CRITICAL:before{background:#ef4444}' +
            '.ast-item.p-HIGH:before{background:#f4dc7a}' +
            '.ast-item.p-MEDIUM:before{background:#34d399}' +
            '.ast-tag{font-size:12px;font-weight:850;padding:4px 9px;border-radius:999px;background:#f4dc7a;color:#102f2c;border:0}' +
            '.ast-item h4{margin:8px 0 4px;font-size:15px;color:#fff}' +
            '.ast-meta{font-size:12px;color:#c5ddd8;line-height:1.7}' +
            '.ast-facts{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}' +
            '.ast-fact{font-size:12px;padding:5px 8px;border-radius:9px;background:#234845;color:#e8f4f1;border:1px solid #3d6d68}' +
            '.ast-why{margin-top:8px;font-size:13px;line-height:1.8;color:#eef6f4}' +
            '.ast-act{margin-top:8px;font-size:13px;font-weight:800;color:#f4dc7a}' +
            '.ast-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}' +
            '.ast-links a{font-size:13px;font-weight:800;text-decoration:none;padding:9px 14px;border-radius:10px;background:#f4dc7a;color:#102f2c!important;display:inline-flex;align-items:center}' +
            '.ast-empty{padding:28px 16px;text-align:center;color:#c5ddd8;font-size:13px}' +
            '.ast-log{height:340px;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px}' +
            '.ast-msg{max-width:95%;padding:9px 11px;border-radius:12px;font-size:13px;line-height:1.8}' +
            '.ast-msg.bot{align-self:flex-start;background:#1a3331;border:1px solid #2f5552;color:#eef6f4}' +
            '.ast-msg.me{align-self:flex-end;background:#0E7C6E;color:#fff}' +
            '.ast-form{display:flex;gap:8px;padding:12px;border-top:1px solid #2f5552}' +
            '.ast-form input{flex:1;min-height:44px;border-radius:12px;border:1px solid #3a5f5b;padding:0 12px;font:inherit;background:#102422;color:#eef6f4}' +
            '.ast-form button{background:#f4dc7a;color:#102f2c!important;border:0;border-radius:12px;padding:0 18px;font:inherit;font-size:14px;font-weight:800;cursor:pointer}' +
            '.ast-note{font-size:12px;color:#c5ddd8;padding:0 16px 12px;line-height:1.7}' +
            '@media(max-width:980px){.ast-layout{grid-template-columns:1fr}.ast-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}';
        document.head.appendChild(st);
    }

    function ensureTab() {
        injectCss();
        var panel = document.getElementById('tab-assistant');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'tab-content';
            panel.id = 'tab-assistant';
            var main = document.getElementById('mainContent') || document.body;
            main.appendChild(panel);
        }
        if (!panel.querySelector('.ast-hero')) {
            panel.innerHTML = tabHtml();
        }
        bindUi();
        if (panel.classList.contains('active') && !state.loaded) {
            state.loaded = true;
            if (typeof loadBoard === 'function') loadBoard();
        }
    }

    function tabHtml() {
        return (
            '<div class="ast-hero"><div><div class="ast-kicker">MELKINO OPS</div><h2>دستیار هوشمند</h2>' +
            '<p>صف پیگیری از خود دیتابیس ملکینو: آگهی‌ها، بازدیدها، درخواست‌ها، تطبیق‌ها، علاقه‌مندی‌ها و درخواست بازدید. نتیجه در جدول‌های دستیار ذخیره می‌شود.</p></div>' +
            '<div class="ast-hero-actions"><button type="button" class="ast-btn gold" id="astRefresh">تحلیل دوباره</button>' +
            '<a class="ast-btn ghost" href="admin-leads.php">پنل پیامک لید</a></div></div>' +
            '<div class="ast-kpis" id="astKpis"></div>' +
            '<div class="ast-layout"><div class="ast-card"><h3 id="astListTitle">موارد نیازمند توجه</h3><div class="ast-list" id="astList"></div><div class="ast-note" id="astRunMeta"></div></div>' +
            '<div class="ast-card"><h3>پرس‌وجو</h3><div class="ast-log" id="astLog"></div>' +
            '<form class="ast-form" id="astForm"><input id="astInput" maxlength="240" placeholder="کد ملک، کد رهگیری، نام…"><button type="submit">بپرس</button></form></div></div>'
        );
    }

    function kpiHtml(k, on) {
        var items = [
            ['all', 'همه', k.all || 0],
            ['hot', 'لید / پیگیری', k.hot || 0],
            ['match', 'تطبیق', k.match || 0],
            ['request', 'درخواست', k.request || 0],
            ['visit', 'بازدید حضوری', k.visit || 0]
        ];
        return items.map(function (it) {
            return '<div class="ast-kpi' + (on === it[0] ? ' on' : '') + '" data-filter="' + it[0] + '"><b>' + esc(it[2]) + '</b><span>' + it[1] + '</span></div>';
        }).join('');
    }

    function filtered() {
        var f = state.filter;
        return (state.insights || []).filter(function (i) {
            if (f === 'all') return true;
            if (f === 'hot') return ['hot_lead', 'interest_no_action', 'warming', 'repeat_view'].indexOf(i.type) >= 0;
            if (f === 'match') return ['high_match', 'multi_match', 'behavioral_match'].indexOf(i.type) >= 0;
            if (f === 'request') return ['old_request', 'no_match'].indexOf(i.type) >= 0;
            if (f === 'visit') return i.type === 'open_visit';
            if (f === 'ad') return ['trending_ad', 'multi_user_ad', 'view_no_visit'].indexOf(i.type) >= 0;
            return true;
        });
    }

    function renderList(list) {
        var el = document.getElementById('astList');
        if (!el) return;
        if (!list || !list.length) {
            el.innerHTML = '<div class="ast-empty">در این فیلتر موردی با شواهد در دیتابیس نبود.</div>';
            return;
        }
        el.innerHTML = list.map(function (i) {
            var facts = (i.facts || []).map(function (f) { return '<span class="ast-fact">' + esc(f) + '</span>'; }).join('');
            var who = [i.person_name, i.phone, i.tracking_code ? 'کد ' + i.tracking_code : '']
                .filter(Boolean).join(' · ');
            var links = '';
            if (i.ad_id) links += '<a href="property-details.php?id=' + encodeURIComponent(i.ad_id) + '" target="_blank" rel="noopener">آگهی ' + esc(i.ad_id) + '</a>';
            if (i.phone) links += '<a href="admin-leads.php">پیامک / لید</a>';
            return '<article class="ast-item p-' + esc(i.priority) + '">' +
                '<div class="ast-item-top"><span class="ast-tag">' + esc(i.type_label || i.type) + '</span>' +
                '<span class="ast-tag">' + esc(i.priority) + ' · اطمینان ' + esc(i.confidence) + '</span></div>' +
                '<h4>' + esc(i.title) + '</h4>' +
                '<div class="ast-meta">' + esc(who) + (i.ad_title ? '<br>' + esc(i.ad_title) : '') + '</div>' +
                '<div class="ast-why">' + esc(i.what_happened) + '</div>' +
                (i.why_it_matters ? '<div class="ast-why">' + esc(i.why_it_matters) + '</div>' : '') +
                (i.interpretation ? '<div class="ast-meta">' + esc(i.interpretation) + '</div>' : '') +
                '<div class="ast-facts">' + facts + '</div>' +
                (i.action ? '<div class="ast-act">' + esc(i.action) + '</div>' : '') +
                (links ? '<div class="ast-links">' + links + '</div>' : '') +
                '</article>';
        }).join('');
    }

    function renderBoard() {
        var kpis = document.getElementById('astKpis');
        if (kpis) kpis.innerHTML = kpiHtml(state.kpis || {}, state.filter);
        var title = document.getElementById('astListTitle');
        var list = filtered();
        if (title) title.textContent = 'موارد نیازمند توجه (' + list.length + ')';
        renderList(list);
        var meta = document.getElementById('astRunMeta');
        if (meta) {
            var r = state.run;
            meta.textContent = r
                ? ('آخرین اجرا ' + (r.finished_at || '') + ' — ' + (r.views_n || 0) + ' بازدید، ' + (r.requests_n || 0) + ' درخواست، ' + (r.matches_n || 0) + ' تطبیق، ' + (r.ads_n || 0) + ' آگهی در نمونه.')
                : 'هنوز اجرایی ذخیره نشده.';
            if (r && r.notes && r.notes.length) meta.textContent += ' ' + r.notes.join(' ');
        }
    }

    function addMsg(role, text) {
        var log = document.getElementById('astLog');
        if (!log) return;
        var el = document.createElement('div');
        el.className = 'ast-msg ' + (role === 'me' ? 'me' : 'bot');
        el.textContent = text;
        log.appendChild(el);
        log.scrollTop = log.scrollHeight;
    }

    async function api(action, body) {
        var opt = { credentials: 'same-origin', cache: 'no-store' };
        if (body) {
            opt.method = 'POST';
            opt.headers = { 'Content-Type': 'application/json; charset=UTF-8' };
            opt.body = JSON.stringify(Object.assign({ action: action }, body));
        }
        var res = await fetch('admin-assistant-api.php?action=' + encodeURIComponent(action), opt);
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.success === false) throw new Error(data.message || 'خطا');
        return data;
    }

    function applyPayload(data) {
        state.insights = data.insights || [];
        state.kpis = data.kpis || {};
        state.run = data.run || null;
        renderBoard();
    }

    async function loadBoard() {
        var el = document.getElementById('astList');
        if (el) el.innerHTML = '<div class="ast-empty">در حال خواندن دیتابیس ملکینو…</div>';
        try {
            applyPayload(await api('board'));
        } catch (e) {
            if (el) el.innerHTML = '<div class="ast-empty">' + esc(e.message) + '</div>';
        }
    }

    async function refresh() {
        var btn = document.getElementById('astRefresh');
        if (btn) { btn.disabled = true; btn.textContent = 'در حال تحلیل…'; }
        try {
            var data = await api('refresh', {});
            applyPayload(data);
            addMsg('bot', data.message || 'تحلیل تازه شد.');
        } catch (e) {
            addMsg('bot', e.message || 'تحلیل انجام نشد.');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'تحلیل دوباره'; }
        }
    }

    async function ask(q) {
        addMsg('me', q);
        addMsg('bot', '…');
        var log = document.getElementById('astLog');
        var pending = log ? log.lastElementChild : null;
        try {
            var data = await api('chat', { message: q });
            if (pending) pending.textContent = data.reply || '—';
            if (data.insights) {
                state.filter = data.filter === 'follow' ? 'hot' : (data.filter && data.filter !== 'search' && data.filter !== 'all' ? data.filter : state.filter);
                if (data.filter === 'search' || data.filter === 'follow') {
                    renderList(data.insights);
                    var title = document.getElementById('astListTitle');
                    if (title) title.textContent = 'نتیجه پرس‌وجو (' + data.insights.length + ')';
                } else {
                    renderBoard();
                }
            }
        } catch (e) {
            if (pending) pending.textContent = e.message || 'خطا';
        }
    }

    function bindUi() {
        var kpis = document.getElementById('astKpis');
        if (kpis && !kpis.dataset.bound) {
            kpis.dataset.bound = '1';
            kpis.addEventListener('click', function (e) {
                var b = e.target.closest('[data-filter]');
                if (!b) return;
                state.filter = b.getAttribute('data-filter') || 'all';
                renderBoard();
            });
        }
        var form = document.getElementById('astForm');
        if (form && !form.dataset.bound) {
            form.dataset.bound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var input = document.getElementById('astInput');
                var v = input ? input.value.trim() : '';
                if (!v) return;
                input.value = '';
                ask(v);
            });
        }
        var ref = document.getElementById('astRefresh');
        if (ref && !ref.dataset.bound) {
            ref.dataset.bound = '1';
            ref.addEventListener('click', refresh);
        }
        var orig = window.switchTab;
        if (typeof orig === 'function' && !orig.__astWrapped) {
            var wrapped = function (tabId) {
                orig(tabId);
                if (tabId === 'assistant' && !state.loaded) {
                    state.loaded = true;
                    loadBoard();
                }
            };
            wrapped.__astWrapped = true;
            window.switchTab = wrapped;
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ensureTab);
    else ensureTab();
})();
