<?php require_once __DIR__ . '/config.php'; ?>
<style>
.mk-dash{padding:4px 2px 28px;display:flex;flex-direction:column;gap:16px}
.mk-dash-hero{
  display:grid;grid-template-columns:1.4fr .9fr;gap:14px;
  background:linear-gradient(135deg,#0b1f1c 0%,#12352f 42%,#1a4a3c 100%);
  border:1px solid rgba(201,162,39,.28);border-radius:22px;padding:18px 20px;color:#F4F1E8;
  box-shadow:0 18px 40px rgba(8,20,18,.35);
}
.mk-dash-kicker{font-size:11px;letter-spacing:.18em;color:#C9A227;font-weight:800}
.mk-dash-clock{font-size:42px;font-weight:900;letter-spacing:.04em;font-variant-numeric:tabular-nums;margin:6px 0 2px}
.mk-dash-date{font-size:15px;opacity:.92}
.mk-dash-cal{background:rgba(255,255,255,.06);border-radius:16px;padding:12px}
.mk-dash-cal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-weight:800;font-size:13px}
.mk-dash-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}
.mk-dash-cal-wd{font-size:11px;opacity:.7;padding:2px 0}
.mk-dash-cal-day{height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.mk-dash-cal-day.is-today{background:#C9A227;color:#12231f}
.mk-dash-cal-day.is-empty{opacity:0}
.mk-dash-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.mk-dash-stat{
  border:1px solid var(--border);border-radius:18px;padding:16px 16px 14px;cursor:pointer;
  background:var(--surface);transition:transform .15s ease, box-shadow .15s ease;
}
.mk-dash-stat:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(0,0,0,.12)}
.mk-dash-stat b{display:block;font-size:32px;line-height:1;margin:8px 0 4px}
.mk-dash-stat span{font-size:12px;color:var(--text-secondary);font-weight:800}
.mk-dash-stat.gold{background:linear-gradient(180deg,rgba(201,162,39,.16),var(--surface))}
.mk-dash-stat.teal{background:linear-gradient(180deg,rgba(14,124,110,.16),var(--surface))}
.mk-dash-stat.blue{background:linear-gradient(180deg,rgba(29,111,184,.14),var(--surface))}
.mk-dash-stat.rose{background:linear-gradient(180deg,rgba(192,57,43,.14),var(--surface))}
.mk-dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.mk-dash-card{border:1px solid var(--border);border-radius:18px;background:var(--surface);overflow:hidden}
.mk-dash-card h3{margin:0;padding:12px 16px;border-bottom:1px solid var(--border);font-size:14px}
.mk-dash-list{padding:8px 10px 12px;display:flex;flex-direction:column;gap:8px;max-height:280px;overflow:auto}
.mk-dash-row{display:flex;justify-content:space-between;gap:8px;padding:10px 10px;border-radius:12px;background:var(--bg);font-size:12px}
.mk-dash-row strong{display:block}
.mk-dash-empty{padding:18px;text-align:center;color:var(--text-secondary);font-size:12px}
.mk-dash-visit{padding:12px 14px 14px}
.mk-dash-visit-day{font-weight:900;margin:4px 0 8px;font-size:13px}
.mk-vitem{display:grid;grid-template-columns:88px 1fr auto;gap:8px;align-items:center;padding:10px;border-radius:12px;border:1px solid var(--border);margin-bottom:8px;background:var(--bg)}
.mk-vtime{font-weight:900;color:#0E7C6E;font-size:13px}
@media(max-width:900px){
  .mk-dash-hero,.mk-dash-stats,.mk-dash-grid,.mk-vitem{grid-template-columns:1fr}
  .mk-dash-clock{font-size:32px}
}
</style>
<div role="tabpanel" class="tab-content active" id="tab-dashboard">
  <div class="mk-dash">
    <div class="mk-dash-hero">
      <div>
        <div class="mk-dash-kicker">COMMAND CENTER</div>
        <div class="mk-dash-clock" id="mkDashClock">—:—</div>
        <div class="mk-dash-date" id="mkDashDate">در حال بارگذاری تقویم…</div>
        <div style="margin-top:12px;font-size:13px;opacity:.85">کارهای معوق، بازدیدهای امروز و فردا، و تیکت‌های باز در یک نگاه.</div>
      </div>
      <div class="mk-dash-cal">
        <div class="mk-dash-cal-head">
          <span id="mkDashCalTitle">—</span>
          <span style="font-size:11px;opacity:.75">تقویم شمسی</span>
        </div>
        <div class="mk-dash-cal-grid" id="mkDashCalHead"></div>
        <div class="mk-dash-cal-grid" id="mkDashCalBody"></div>
      </div>
    </div>
    <?php require __DIR__ . '/admin-site-runtime-card.php'; ?>
    <div class="mk-dash-stats">
      <div class="mk-dash-stat gold" onclick="switchTab('ads')">
        <span>آگهی منتظر تأیید</span>
        <b id="mkDashPendingAds">…</b>
      </div>
      <div class="mk-dash-stat teal" onclick="switchTab('requests')">
        <span>درخواست ملک جدید</span>
        <b id="mkDashNewReqs">…</b>
      </div>
      <div class="mk-dash-stat blue" onclick="switchTab('visits')">
        <span>بازدید جدید</span>
        <b id="mkDashNewVisits">…</b>
      </div>
      <div class="mk-dash-stat rose" onclick="switchTab('support')">
        <span>تیکت باز</span>
        <b id="mkDashNewTickets">…</b>
      </div>
    </div>
    <div class="mk-dash-grid">
      <div class="mk-dash-card">
        <h3>بازدیدهای امروز</h3>
        <div class="mk-dash-visit" id="mkDashVisitsToday"><div class="mk-dash-empty">در حال بارگذاری…</div></div>
      </div>
      <div class="mk-dash-card">
        <h3>بازدیدهای فردا</h3>
        <div class="mk-dash-visit" id="mkDashVisitsTomorrow"><div class="mk-dash-empty">در حال بارگذاری…</div></div>
      </div>
      <div class="mk-dash-card">
        <h3>آگهی‌های در صف تأیید</h3>
        <div class="mk-dash-list" id="mkDashAdsList"></div>
      </div>
      <div class="mk-dash-card">
        <h3>درخواست‌ها و تیکت‌ها</h3>
        <div class="mk-dash-list" id="mkDashSideList"></div>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  function faDigits(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); });
  }
  function g2j(gy, gm, gd) {
    var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = (gm > 2) ? (gy + 1) : gy;
    var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
    var jy = -1595 + (33 * Math.floor(days / 12053));
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var jm, jd;
    if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
    else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
    return [jy, jm, jd];
  }
  function j2g(jy, jm, jd) {
    jy += 1595;
    var days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd
      + ((jm < 7) ? ((jm - 1) * 31) : (((jm - 7) * 30) + 186));
    var gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) {
      gy += 100 * Math.floor(--days / 36524);
      days %= 36524;
      if (days >= 365) days++;
    }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var sal = [0, 31, (((gy % 4 === 0) && (gy % 100 !== 0)) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 1;
    for (var i = 1; i <= 12; i++) {
      if (gd <= sal[i]) { gm = i; break; }
      gd -= sal[i];
    }
    return [gy, gm, gd];
  }
  function jMonthDays(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return [1, 5, 9, 13, 17, 22, 26, 30].indexOf(jy % 33) >= 0 ? 30 : 29;
  }
  var months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  function renderCal() {
    var now = new Date();
    var j = g2j(now.getFullYear(), now.getMonth() + 1, now.getDate());
    var title = document.getElementById('mkDashCalTitle');
    if (title) title.textContent = months[j[1] - 1] + ' ' + faDigits(j[0]);
    var head = document.getElementById('mkDashCalHead');
    if (head && !head.childElementCount) {
      ['ش','ی','د','س','چ','پ','ج'].forEach(function (w) {
        var d = document.createElement('div'); d.className = 'mk-dash-cal-wd'; d.textContent = w; head.appendChild(d);
      });
    }
    var body = document.getElementById('mkDashCalBody');
    if (!body) return;
    body.innerHTML = '';
    var g1 = j2g(j[0], j[1], 1);
    var phpW = new Date(g1[0], g1[1] - 1, g1[2]).getDay();
    var start = (phpW + 1) % 7;
    var dim = jMonthDays(j[0], j[1]);
    var i;
    for (i = 0; i < start; i++) {
      var e = document.createElement('div'); e.className = 'mk-dash-cal-day is-empty'; body.appendChild(e);
    }
    for (var day = 1; day <= dim; day++) {
      var cell = document.createElement('div');
      cell.className = 'mk-dash-cal-day' + (day === j[2] ? ' is-today' : '');
      cell.textContent = faDigits(day);
      body.appendChild(cell);
    }
  }
  function tickClock() {
    var d = new Date();
    var c = document.getElementById('mkDashClock');
    var dt = document.getElementById('mkDashDate');
    if (c) c.textContent = d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    if (dt) dt.textContent = d.toLocaleDateString('fa-IR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);
    });
  }
  function visitHtml(list) {
    if (!list || !list.length) return '<div class="mk-dash-empty">بازدیدی ثبت نشده است.</div>';
    return list.map(function (v) {
      return '<div class="mk-vitem">'
        + '<div class="mk-vtime">' + esc(v.slot || v.when || '—') + '</div>'
        + '<div><strong>' + esc(v.ad_title) + '</strong><div style="opacity:.75">' + esc(v.user) + ' · ' + esc(v.phone) + '</div></div>'
        + '<button type="button" class="btn-secondary" style="font-size:11px" onclick="switchTab(\'visits\')">پیگیری</button>'
        + '</div>';
    }).join('');
  }
  window.loadAdminDashboard = async function () {
    tickClock();
    renderCal();
    try {
      var r = await fetch('admin-dashboard-api.php', { cache: 'no-store', credentials: 'same-origin' });
      var data = await r.json();
      if (!data || !data.success) return;
      var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = faDigits(v == null ? 0 : v); };
      set('mkDashPendingAds', data.pending_ads_count);
      set('mkDashNewReqs', data.new_requests_count);
      set('mkDashNewVisits', data.new_visits_count);
      set('mkDashNewTickets', data.new_tickets_count);
      var t = document.getElementById('mkDashVisitsToday');
      var tm = document.getElementById('mkDashVisitsTomorrow');
      if (t) t.innerHTML = visitHtml(data.visits_today);
      if (tm) tm.innerHTML = visitHtml(data.visits_tomorrow);
      var ads = document.getElementById('mkDashAdsList');
      if (ads) {
        var adRows = (data.pending_ads || []).map(function (a) {
          return '<div class="mk-dash-row"><div><strong>' + esc(a.title || a.id) + '</strong><span>' + esc(a.property_type || '') + ' · ' + esc(a.transaction_type || '') + '</span></div><button type="button" class="btn-secondary" style="font-size:11px" onclick="switchTab(\'ads\')">بررسی</button></div>';
        });
        var mkpRows = (data.pending_partnership || []).map(function (a) {
          return '<div class="mk-dash-row"><div><strong>🤝 ' + esc(a.title || a.code || a.id) + '</strong><span>مشارکت در ساخت · ' + esc(a.property_type || '') + '</span></div><button type="button" class="btn-secondary" style="font-size:11px" onclick="switchTab(\'ads\')">بررسی</button></div>';
        });
        ads.innerHTML = (adRows.length + mkpRows.length) ? adRows.concat(mkpRows).join('') : '<div class="mk-dash-empty">موردی منتظر تأیید نیست.</div>';
      }
      var side = document.getElementById('mkDashSideList');
      if (side) {
        var html = '';
        (data.new_requests || []).slice(0, 6).forEach(function (q) {
          html += '<div class="mk-dash-row"><div><strong>درخواست ' + esc(q.tracking_code || q.id) + '</strong><span>' + esc(q.name || q.phone || '') + '</span></div><button type="button" class="btn-secondary" style="font-size:11px" onclick="switchTab(\'requests\')">باز</button></div>';
        });
        (data.new_tickets || []).slice(0, 6).forEach(function (q) {
          html += '<div class="mk-dash-row"><div><strong>تیکت #' + esc(q.id) + '</strong><span>' + esc(q.subject || q.status || '') + '</span></div><button type="button" class="btn-secondary" style="font-size:11px" onclick="switchTab(\'support\')">پاسخ</button></div>';
        });
        side.innerHTML = html || '<div class="mk-dash-empty">مورد جدیدی نیست.</div>';
      }
    } catch (e) {}
  };
  setInterval(tickClock, 1000);
  tickClock();
  renderCal();
})();
</script>
