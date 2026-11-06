/* ملکینو — مرکز انتشار — admin-publish-center.js (منطق UI؛ داده‌ها از publish-api.php) */
(function () {
  'use strict';

  var API = 'publish-api.php';
  var state = { jy: 0, jm: 0, targetId: 0, compAd: null, compPlats: {} };

  function $(id) { return document.getElementById(id); }

  function toast(msg, ok) {
    var t = $('puToast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    t.style.borderColor = ok ? '#34d399' : '#f87171';
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.style.display = 'none'; }, 4200);
  }

  function csrf() {
    var m = document.cookie.match(/(?:^|;\s*)melkino_csrf=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : (window.MELKINO_CSRF || '');
  }

  function api(action, body, qs) {
    var opts = { credentials: 'same-origin', headers: {} };
    if (body) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = csrf();
      opts.body = JSON.stringify(body);
    }
    var url = API + '?action=' + encodeURIComponent(action);
    if (qs) {
      for (var k in qs) {
        if (qs[k] !== '' && qs[k] != null) url += '&' + k + '=' + encodeURIComponent(qs[k]);
      }
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { success: false, 'message': 'پاسخ نامعتبر سرور' }; }).then(function (j) {
        if (j && j.success === false && j.message) toast(j.message, false);
        return j || { success: false };
      });
    }).catch(function () {
      toast('ارتباط با سرور برقرار نشد.', false);
      return { success: false };
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  var J_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

  function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

  function jdt(iso) {
    // نمایش فشردهٔ شمسی از datetime میلادی (بدون تبدیل — از سرور در تقویم؛ اینجا فقط ساعت)
    if (!iso) return '—';
    return esc(String(iso).slice(0, 16));
  }

  function statusBadge(st) {
    var map = {
      published: ['ok', 'منتشرشده'], partial: ['warn', 'ناقص'], queued: ['pend', 'در صف'], pending: ['pend', 'زمان‌بندی‌شده'],
      sending: ['pend', 'در حال ارسال'], failed: ['bad', 'ناموفق'], needs_review: ['bad', 'نیازمند بررسی'],
      cancelled: ['mut', 'لغوشده'], draft: ['mut', 'پیش‌نویس'], info: ['mut', 'اطلاعات'],
      success: ['ok', 'موفق'], ambiguous: ['warn', 'نامشخص'], skipped: ['mut', 'ردشده'],
      create: ['mut', 'ایجاد'], publish: ['ok', 'انتشار'], run_overdue: ['warn', 'تاخیر در اجرا'],
      rule_action: ['pend', 'قانون اتوماسیون'], edit_post: ['mut', 'ویرایش پست'], delete_post: ['bad', 'حذف پست'],
    };
    var v = map[st] || ['mut', st || '—'];
    return '<span class="pu-badge ' + v[0] + '">' + esc(v[1]) + '</span>';
  }

  function platName(p) {
    return { telegram: 'تلگرام', bale: 'بله', eitaa: 'ایتا' }[p] || esc(p || '—');
  }

  /* ================= تب‌ها ================= */
  function bindTabs() {
    var tabs = document.querySelectorAll('#puTabs .pu-tab');
    Array.prototype.forEach.call(tabs, function (b) {
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(tabs, function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        var panes = document.querySelectorAll('.pu-pane');
        Array.prototype.forEach.call(panes, function (p) { p.classList.remove('on'); });
        var pane = $('puPane-' + b.dataset.pane);
        if (pane) pane.classList.add('on');
        var m = {
          overview: loadOverview, calendar: loadCalendar, queue: loadQueue, bulk: loadBulk,
          content: loadContent, channels: loadChannels, rules: loadRules, stats: loadStats, history: loadHistory,
        }[b.dataset.pane];
        if (m) m();
      });
    });
  }

  /* ================= نمای کلی ================= */
  function loadOverview() {
    api('overview').then(function (j) {
      if (j && j.success) renderPause(!!j.paused);
      if (!j.success) return;
      var s = j.stats || {};
      $('puStats').innerHTML = [
        ['منتشرشده', s.published || 0, 'ok'], ['در صف/زمان‌بندی', s.active || 0, 'pend'],
        ['ناموفق', s.failed || 0, 'bad'], ['نیازمند بررسی', s.needs_review || 0, 'bad'],
        ['ارسال ناقص', s.partial || 0, 'warn'], ['امروز (در برنامه)', s.scheduled_today || 0, 'mut'],
      ].map(function (x) {
        return '<div class="pu-stat"><div class="n">' + fa(x[1]) + '</div><div class="l">' + x[0] + '</div></div>';
      }).join('');
      $('puPlatSummary').innerHTML = (j.by_platform || []).map(function (p) {
        return '<span class="pu-badge">' + platName(p.platform) + ': ' + fa(p.total) + ' مورد — ' + fa(p.published || 0) + ' موفق، ' + fa(p.bad || 0) + ' مشکل‌دار</span>';
      }).join('') || '<span class="pu-empty">هنوز موردی ثبت نشده است.</span>';
      var tb = $('puRecentTable').querySelector('tbody');
      tb.innerHTML = (j.recent || []).map(function (h) {
        return '<tr><td>' + jdt(h.created_at) + '</td><td>' + esc(h.title || h.ad_id || '—') + '</td><td>' + platName(h.platform) + '</td><td>' + statusBadge(h.action) + '</td><td>' + statusBadge(h.status) + '</td><td>' + esc(h.detail || '') + '</td></tr>';
      }).join('') || '<tr><td colspan="6" class="pu-empty">تاریخچه‌ای ثبت نشده است.</td></tr>';
    });
  }

  /* ================= تقویم ================= */
  function loadCalendar() {
    api('calendar', null, state.jy ? { y: state.jy, m: state.jm } : null).then(function (j) {
      if (!j.success) return;
      state.jy = j.jy; state.jm = j.jm;
      $('puCalTitle').textContent = J_MONTHS[(j.jm || 1) - 1] + ' ' + fa(j.jy);
      $('puCalTz').textContent = 'منطقهٔ زمانی: ' + (j.tz || 'Asia/Tehran');
      var heads = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
      var html = heads.map(function (h) { return '<div class="hd">' + h + '</div>'; }).join('');
      html += (j.cells || []).map(function (c) {
        var evs = (c.events || []).map(function (e) {
          var cx = e.target_status === 'cancelled';
          var cls = cx ? 'cx' : (e.target_status === 'published' ? 'ok' : (e.target_status === 'failed' || e.target_status === 'needs_review' ? 'bad' : 'pend'));
          var canCancel = !cx && e.target_status !== 'published' && e.target_id;
          return '<span class="ev ' + cls + '" title="' + esc((cx ? 'لغوشده — ' : '') + (e.title || '')) + '">' + platName(e.platform) + ' · ' + esc((e.title || '').slice(0, 18)) +
            (canCancel ? '<button type="button" class="ev-x" data-act="cal-cancel" data-tid="' + e.target_id + '" title="لغو انتشار این مورد در این روز">✕</button>' : '') +
            '</span>';
        }).join('');
        return '<div class="day' + (c.out ? ' out' : '') + '" data-iso="' + c.iso + '"' + (c.today ? ' style="border-color:#38bdf8"' : '') + '>' +
          '<div class="dn">' + fa(c.jd) + '</div>' + evs + '</div>';
      }).join('');
      $('puCalGrid').innerHTML = html;
      Array.prototype.forEach.call($('puCalGrid').querySelectorAll('.day'), function (d) {
        d.addEventListener('click', function (e) {
          if (e.target && e.target.closest && e.target.closest('.ev-x')) return;
          openComposer(null, d.dataset.iso);
        });
      });
    });
  }

  function calShift(delta) {
    var m = state.jm + delta, y = state.jy;
    while (m < 1) { m += 12; y -= 1; }
    while (m > 12) { m -= 12; y += 1; }
    state.jy = y; state.jm = m;
    loadCalendar();
  }

  /* ================= صف ================= */
  var queueSel = {};
  var queueIds = [];
  function queueSelected() {
    return Object.keys(queueSel).filter(function (k) { return queueSel[k]; }).map(function (k) { return parseInt(k, 10); });
  }
  function queueSync() {
    var n = queueSelected().length;
    if ($('puQueueSelCount')) $('puQueueSelCount').textContent = fa(n) + ' مورد انتخاب شده';
    if ($('puQueueBulkDelete')) $('puQueueBulkDelete').disabled = n === 0;
    var all = $('puQueueSelAll');
    if (all) {
      var visible = queueIds.filter(function (id) { return queueSel[id]; }).length;
      all.checked = queueIds.length > 0 && visible === queueIds.length;
      all.indeterminate = visible > 0 && visible < queueIds.length;
    }
  }
  function loadQueue() {
    api('queue').then(function (j) {
      if (!j.success) return;
      var tb = $('puQueueTable').querySelector('tbody');
      queueIds = (j.queue || []).map(function (q) { return String(q.id); });
      // فقط مواردِ هنوز موجود در صف انتخاب‌شده می‌مانند
      Object.keys(queueSel).forEach(function (k) { if (queueIds.indexOf(k) < 0) delete queueSel[k]; });
      tb.innerHTML = (j.queue || []).map(function (q) {
        return '<tr><td><input type="checkbox" class="puQPick" value="' + q.id + '"' + (queueSel[String(q.id)] ? ' checked' : '') + ' aria-label="انتخاب این مورد"></td>' +
          '<td>' + jdt(q.scheduled_at) + '</td><td>' + esc(q.title || '—') + (q.ad_id ? ' <span class="pu-mono">(' + esc(q.ad_id) + ')</span>' : '') + '</td><td>' + platName(q.platform) + '</td><td>' + statusBadge(q.status) + '</td><td>' + fa(q.attempts || 0) + '</td><td>' +
          '<button class="pu-btn sm" data-tid="' + q.id + '" data-act="open" data-info="' + esc(JSON.stringify({ title: q.title, platform: q.platform, text: q.text })) + '">عملیات</button>' +
          (q.status === 'failed' || q.status === 'needs_review' ? ' <button class="pu-btn sm" data-tid="' + q.id + '" data-act="retry">تلاش مجدد</button>' : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="7" class="pu-empty">صف خالی است.</td></tr>';
      Array.prototype.forEach.call(tb.querySelectorAll('button'), function (b) {
        b.addEventListener('click', function () {
          var tid = parseInt(b.dataset.tid, 10);
          if (b.dataset.act === 'retry') {
            api('retry', { target_id: tid }).then(function (r) {
              toast(r.message || (r.success ? 'ارسال انجام شد.' : 'ارسال ناموفق بود.'), !!r.success);
              loadQueue(); loadOverview();
            });
          } else {
            openTargetModal(tid, b.dataset.info);
          }
        });
      });
      Array.prototype.forEach.call(tb.querySelectorAll('.puQPick'), function (cb) {
        cb.addEventListener('change', function () { queueSel[cb.value] = cb.checked; queueSync(); });
      });
      queueSync();
    });
  }

  function bulkDeleteQueue() {
    var ids = queueSelected();
    if (!ids.length) return;
    if (!window.confirm(fa(ids.length) + ' مقصد از صف انتشار حذف (لغو) شود؟\nموارد در حال ارسال و پست‌های منتشرشده حذف نمی‌شوند. تاریخچه حفظ می‌شود.')) return;
    $('puQueueBulkDelete').disabled = true;
    api('cancel_bulk', { target_ids: ids }).then(function (r) {
      toast(r.message || (r.success ? 'انجام شد.' : 'حذف انجام نشد.'), !!r.success);
      queueSel = {};
      loadQueue(); loadOverview();
    }).catch(function () {
      toast('حذف انجام نشد؛ دوباره تلاش کنید.', false);
      queueSync();
    });
  }

  /* ================= انتشار گروهی ================= */
  function platChecks(containerId) {
    var c = $(containerId);
    if (!c) return [];
    return Array.prototype.map.call(c.querySelectorAll('input[type=checkbox]:checked'), function (i) { return i.value; });
  }

  function renderPlatChecks(containerId) {
    var c = $(containerId);
    if (!c) return;
    c.innerHTML = ['telegram', 'bale', 'eitaa'].map(function (p) {
      return '<label class="pu-chip"><input type="checkbox" value="' + p + '" checked> ' + platName(p) + '</label>';
    }).join('');
  }

  function loadBulk() {
    renderPlatChecks('puBulkPlats');
    renderPlatChecks('puContPlats');
    searchBulk('');
    api('overview').then(function () {});
    api('queue').then(function () {});
    // کمپین‌ها از مسیر history خوانده می‌شوند
    api('history', null).then(function (j) {
      // noop — کمپین‌ها جداگانه
    });
    loadCampaigns();
  }

  function loadCampaigns() {
    api('queue').then(function () {});
    fetch(API + '?action=history', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var tb = $('puCampaignsTable').querySelector('tbody');
      tb.innerHTML = '<tr><td colspan="5" class="pu-empty">کمپین‌های فعال در «صف انتشار» قابل مشاهده و لغو هستند.</td></tr>';
    }).catch(function () {});
  }

  function searchBulk(q) {
    api('ad_search', null, q ? { q: q, scope: 'published' } : { scope: 'published' }).then(function (j) {
      if (!j.success) return;
      var tb = $('puBulkTable').querySelector('tbody');
      tb.innerHTML = (j.ads || []).map(function (a) {
        // ردیف‌هایی که هنوز منتشر نشده‌اند (بدون هیچ مقصد منتشرشده) برجسته می‌شوند؛ در تم روشن و تیره یکسان دیده می‌شوند.
        var fresh = a.pub_state !== 'published';
        var rowStyle = fresh ? ' style="background:rgba(245,158,11,.16);box-shadow:inset 4px 0 0 #f59e0b"' : '';
        return '<tr' + rowStyle + '><td><input type="checkbox" class="puBulkPick" value="' + esc(a.id) + '"></td><td class="pu-mono">' + esc(a.id) + '</td><td>' + esc(a.title || '—') + '</td><td>' + esc(a.transaction_type || '') + ' ' + esc(a.property_type || '') + '</td><td>' + statusBadge(a.status === 'active' ? 'published' : a.status) + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="pu-empty">آگهی‌ای پیدا نشد.</td></tr>';
    });
  }

  function runBulk() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.puBulkPick:checked'), function (i) { return i.value; });
    if (!ids.length) { toast('هیچ آگهی‌ای انتخاب نشده است.', false); return; }
    var plats = platChecks('puBulkPlats');
    if (!plats.length) { toast('حداقل یک پیام‌رسان انتخاب کنید.', false); return; }
    api('bulk', {
      ad_ids: ids,
      targets: plats.map(function (p) { return { platform: p, media_mode: 'all' }; }),
      scheduled_at: $('puBulkWhen') ? $('puBulkWhen').value : '',
      interval_seconds: $('puBulkInterval') ? parseInt($('puBulkInterval').value, 10) || 120 : 120,
    }).then(function (r) {
      if (r.success) toast(r.message || 'انتشار گروهی زمان‌بندی شد.', true);
      loadQueue();
    });
  }

  /* ================= محتوای عمومی ================= */
  function loadContent() {
    renderPlatChecks('puContPlats');
  }

  function runContent() {
    var plats = platChecks('puContPlats');
    if (!plats.length) { toast('حداقل یک پیام‌رسان انتخاب کنید.', false); return; }
    var media = ($('puContMedia') ? $('puContMedia').value : '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    api('content_save', {
      title: $('puContTitle') ? $('puContTitle').value : '',
      body: $('puContBody') ? $('puContBody').value : '',
      media: media.map(function (f) { return { filename: f, storage_path: 'uploads/' + f.replace(/^uploads\//, '') }; }),
      targets: plats.map(function (p) { return { platform: p, media_mode: media.length ? 'selected' : 'none', media: media, text: $('puContBody') ? $('puContBody').value : '' }; }),
      scheduled_at: $('puContWhen') ? $('puContWhen').value : '',
      track_link: false,
    }).then(function (r) {
      if (r.success) {
        toast(r.message || 'ثبت شد.', true);
        if ($('puContTitle')) { $('puContTitle').value = ''; $('puContBody').value = ''; $('puContMedia').value = ''; }
      }
    });
  }

  /* ================= کانال‌ها ================= */
  function loadChannels() {
    api('channels').then(function (j) {
      if (!j.success) return;
      $('puChannelsGrid').innerHTML = (j.channels || []).map(function (c) {
        var caps = c.capabilities || {};
        var capRows = [
          ['ارسال متن', caps.send_text], ['ارسال عکس', caps.send_photo], ['آلبوم چندعکسی', caps.send_album],
          ['ویرایش پست', caps.edit], ['حذف پست', caps.delete], ['تعداد بازدید', caps.views],
        ].map(function (x) {
          var lbl = x[1] === 'yes' ? '<span class="pu-badge ok">پشتیبانی</span>' : '<span class="pu-badge bad">پشتیبانی نمی‌شود</span>';
          return '<div class="pu-row" style="justify-content:space-between"><span>' + x[0] + '</span>' + lbl + '</div>';
        }).join('');
        return '<div class="pu-card"><h3 style="margin:0 0 8px">' + platName(c.platform) + '</h3>' +
          '<div class="pu-badges"><span class="pu-badge ' + (c.configured ? 'ok' : 'bad') + '">' + (c.configured ? 'متصل' : 'پیکربندی نشده') + '</span>' +
          (c.channel_id ? '<span class="pu-badge mut">کانال: ' + esc(c.channel_id) + '</span>' : '') + '</div>' +
          '<div style="margin-top:10px;line-height:2.1">' + capRows + '</div>' +
          (c.last_activity ? '<div class="pu-mono" style="margin-top:8px">آخرین فعالیت: ' + esc(c.last_activity.created_at || '') + ' — ' + esc(c.last_activity.status || '') + '</div>' : '') +
          '</div>';
      }).join('');
    });
  }

  /* ================= قوانین ================= */
  var RULE_LABELS = {
    sold: 'تغییر وضعیت به «فروخته»', rented: 'تغییر وضعیت به «اجاره رفته»', inactive: 'غیرفعال شدن آگهی',
    changed: 'ویرایش آگهی', expired: 'انقضای آگهی', deleted: 'حذف آگهی',
  };
  var ACT_LABELS = { edit_post: 'ویرایش پست', delete_post: 'حذف پست', flag: 'فقط علامت‌گذاری', none: 'هیچ' };

  function loadRules() {
    api('rules').then(function (j) {
      if (!j.success) return;
      var tb = $('puRulesTable').querySelector('tbody');
      tb.innerHTML = (j.rules || []).map(function (r) {
        var cfg = {};
        try { cfg = JSON.parse(r.config || '{}'); } catch (e) {}
        return '<tr data-ev="' + esc(r.event_key) + '"><td>' + esc(RULE_LABELS[r.event_key] || r.event_key) + '</td>' +
          '<td><select class="puRuleAct">' + Object.keys(ACT_LABELS).map(function (k) {
            return '<option value="' + k + '"' + (r.action_key === k ? ' selected' : '') + '>' + ACT_LABELS[k] + '</option>';
          }).join('') + '</select></td>' +
          '<td><input type="checkbox" class="puRuleOn"' + (parseInt(r.enabled, 10) ? ' checked' : '') + '></td>' +
          '<td><input class="puRuleAppend" style="width:100%" value="' + esc(cfg.append_text || '') + '" placeholder="متنی که هنگام ویرایش پست افزوده می‌شود"></td>' +
          '<td><button class="pu-btn sm puRuleSave">ذخیره</button></td></tr>';
      }).join('');
      Array.prototype.forEach.call(tb.querySelectorAll('.puRuleSave'), function (b) {
        b.addEventListener('click', function () {
          var tr = b.closest('tr');
          api('rule_save', {
            event_key: tr.dataset.ev,
            action_key: tr.querySelector('.puRuleAct').value,
            enabled: tr.querySelector('.puRuleOn').checked,
            append_text: tr.querySelector('.puRuleAppend').value,
          }).then(function (r) { if (r.success) toast(r.message || 'ذخیره شد.', true); });
        });
      });
    });
  }

  /* ================= آمار ================= */
  function loadStats() {
    api('stats').then(function (j) {
      if (!j.success) return;
      $('puViewsNote').innerHTML = Object.keys(j.views || {}).map(function (p) {
        var v = j.views[p];
        return '<div class="pu-note warn"><strong>' + platName(p) + ':</strong> ' + esc(v.note || 'پشتیبانی نمی‌شود.') + '</div>';
      }).join('');
      var tb = $('puClicksTable').querySelector('tbody');
      tb.innerHTML = (j.clicks || []).map(function (c) {
        return '<tr><td>' + platName(c.platform) + '</td><td>' + fa(c.clicks || 0) + '</td><td>' + fa(c.links || 0) + '</td><td>' + jdt(c.last_click) + '</td></tr>';
      }).join('') || '<tr><td colspan="4" class="pu-empty">هنوز کلیکی ثبت نشده است.</td></tr>';
      var pb = $('puPublishedTable').querySelector('tbody');
      pb.innerHTML = (j.published || []).map(function (p) {
        var mids = '';
        try { mids = (JSON.parse(p.message_ids || '[]') || []).map(function (m) { return m.message_id; }).join(', '); } catch (e) {}
        return '<tr><td>' + jdt(p.published_at) + '</td><td>' + esc(p.title || '—') + '</td><td>' + platName(p.platform) + '</td><td class="pu-mono">' + esc(mids) + '</td><td class="pu-mono">' + esc(p.ad_id || '') + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="pu-empty">هنوز پستی منتشر نشده است.</td></tr>';
    });
  }

  /* ================= تاریخچه ================= */
  function loadHistory() {
    var qs = {};
    if ($('puHistAd') && $('puHistAd').value) qs.ad_id = $('puHistAd').value;
    if ($('puHistPlat') && $('puHistPlat').value) qs.platform = $('puHistPlat').value;
    if ($('puHistFrom') && $('puHistFrom').value) qs.from = $('puHistFrom').value;
    if ($('puHistTo') && $('puHistTo').value) qs.to = $('puHistTo').value;
    api('history', null, qs).then(function (j) {
      if (!j.success) return;
      var tb = $('puHistTable').querySelector('tbody');
      tb.innerHTML = (j.history || []).map(function (h) {
        return '<tr><td>' + jdt(h.created_at) + '</td><td>' + esc(h.title || h.ad_id || '—') + '</td><td>' + platName(h.platform) + '</td><td>' + statusBadge(h.action) + '</td><td>' + statusBadge(h.status) + '</td><td>' + esc(h.detail || '') + '</td></tr>';
      }).join('') || '<tr><td colspan="6" class="pu-empty">رکوردی یافت نشد.</td></tr>';
    });
  }

  /* ================= مودال مقصد ================= */
  function openTargetModal(tid, infoJson) {
    state.targetId = tid;
    var info = {};
    try { info = JSON.parse(infoJson || '{}'); } catch (e) {}
    $('puTargetTitle').textContent = 'عملیات مقصد — ' + (info.title || ('#' + tid)) + ' (' + platName(info.platform) + ')';
    $('puTargetInfo').textContent = (info.text || '').slice(0, 400);
    $('puTargetModal').classList.add('on');
  }

  /* ================= مودال برنامهٔ جدید ================= */
  /* ═══════ «ثبت برنامهٔ انتشار» — فرم یکپارچه (طراحی UI/UX دور ۱۴) ═══════ */
  var comp = null;
  var COMP_HOURS = (function () { var a = []; for (var h = 0; h < 24; h++) a.push((h < 10 ? '0' : '') + h); return a; })();
  var COMP_MINS = (function () { var a = []; for (var m = 0; m < 60; m++) a.push((m < 10 ? '0' : '') + m); return a; })();

  function jalaliToGregorian(jy, jm, jd) {
    // الگوریتم استاندارد jalaali-js (جایگزین تبدیل نادرست قبلی که ماه‌های
    // دوم نیمهٔ سال شمسی را ~۸۳ روز جابه‌جا می‌کرد و باعث خطای «زمان در گذشته» می‌شد)
    var jy2 = jy + 1595;
    var days = -355668 + (365 * jy2) + (Math.floor(jy2 / 33) * 8) + Math.floor(((jy2 % 33) + 3) / 4) + jd
      + ((jm < 7) ? (jm - 1) * 31 : (((jm - 7) * 30) + 186));
    var gy = 400 * Math.floor(days / 146097);
    days %= 146097;
    if (days > 36524) { days--; gy += 100 * Math.floor(days / 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var months = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 1;
    for (; gm <= 12 && gd > months[gm]; gm++) gd -= months[gm];
    return new Date(gy, gm - 1, gd);
  }

  function parseJalali(str) {
    // راند ۲۶: pickر تاریخ «۱۴۰۵-۰۷-۲۸»، تقویم «2026-10-20» و «20-10-2026» هم معتبرند؛
    // قبلاً فقط «1405/07/28» (شمسی + اسلش) قبول بود و دکمهٔ ثبت بی‌صدا کار نمی‌کرد.
    var norm = String(str || '').trim()
      .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
    var timeM = norm.match(/[T\s](\d{1,2}):(\d{2})\s*$/);
    var hh = timeM ? +timeM[1] : 0, mi = timeM ? +timeM[2] : 0;
    var datePart = (timeM ? norm.slice(0, timeM.index) : norm).trim();
    var p = datePart.split(/[-\/.]/);
    if (p.length !== 3) return null;
    if (hh > 23 || mi > 59) return null;
    var a = +p[0], b = +p[1], c = +p[2], d;
    if (p[0].length === 4 && a >= 1200 && a <= 1500) {
      d = jalaliToGregorian(a, b, c);
    } else if (p[0].length === 4 && a >= 1900) {
      d = new Date(a, b - 1, c);
    } else if (p[2].length === 4 && c >= 1900) {
      d = new Date(c, b - 1, a);
    } else if (p[2].length === 4 && c >= 1200 && c <= 1500) {
      d = jalaliToGregorian(c, b, a);
    } else {
      return null;
    }
    if (!d || isNaN(d.getTime())) return null;
    if (d.getFullYear() < 2000) return null;
    d.setHours(hh, mi, 0, 0);
    return d;
  }

  function compMediaIds(p) {
    var ch = comp.chans[p];
    if (!ch.on) return [];
    var mode = (comp.per && ch.mediaMode) ? ch.mediaMode : comp.mediaMode;
    if (mode === 'none') return [];
    if (mode === 'all') return comp.media.map(function (x) { return x.id; });
    var ids = (comp.per && ch.media && ch.media.length) ? ch.media : comp.media.filter(function (x) { return x.sel; });
    return ids.map(function (x) { return typeof x === 'object' ? x.id : x; });
  }

  function compText(p) {
    var ch = comp.chans[p];
    if (comp.per && ch.text !== '') return ch.text;
    return ch.defaultText || (comp.type === 'content' ? (comp.content.body || '') : '');
  }

  function compWhenFor(p) {
    if (comp.wm === 'now') return '';
    if (comp.perTime && comp.chans[p]) {
      var ch = comp.chans[p];
      if (ch.whenDate || ch.whenHour || ch.whenMin) {
        var d = ch.whenDate || comp.when;
        if (d) return d + ' ' + (ch.whenHour || comp.hour) + ':' + (ch.whenMin || comp.minute);
      }
      if (ch.when) return ch.when;
    }
    return (comp.when ? comp.when + ' ' + comp.hour + ':' + comp.minute : '');
  }

  function renderComposer() {
    if (!comp) return;
    // نوع محتوا
    $('pmSecAd').classList.toggle('hidden', comp.type !== 'ad');
    $('pmSecMedia').classList.toggle('hidden', comp.type !== 'ad');
    $('pmSecContent').classList.toggle('hidden', comp.type !== 'content');
    Array.prototype.forEach.call(document.querySelectorAll('#puCompBody .pm-choice[data-type]'), function (el) {
      el.classList.toggle('on', el.dataset.type === comp.type);
      el.querySelector('input').checked = el.dataset.type === comp.type;
    });

    // کارت آگهی انتخاب‌شده
    var chosen = $('puCompAdChosen');
    if (comp.ad) {
      $('puCompAdEmpty').style.display = 'none';
      chosen.style.display = 'block';
      var st = comp.ad.status === 'active' || comp.ad.status === 'published' ? '<span class="pm-badge ok">منتشرشده</span>'
        : comp.ad.status === 'pending' ? '<span class="pm-badge warn">در انتظار تأیید</span>'
        : '<span class="pm-badge bad">' + esc(comp.ad.status || '—') + '</span>';
      chosen.innerHTML = '<div class="pm-chosen">' +
        (comp.ad.image ? '<img src="' + esc(comp.ad.image) + '" alt="">' : '<div style="width:74px;height:60px;border-radius:10px;background:var(--pu-s2)"></div>') +
        '<div class="meta"><strong>' + esc(comp.ad.title || '—') + '</strong> <span class="pu-mono">(' + esc(comp.ad.id) + ')</span><br>' +
        esc([comp.ad.location, comp.ad.area ? comp.ad.area + ' متر' : '', comp.ad.price ? 'قیمت: ' + comp.ad.price : ''].filter(Boolean).join(' — ')) +
        ' ' + st + '</div>' +
        '<div class="pu-row"><button class="pu-btn sm" data-act="comp-change-ad" type="button">تغییر آگهی</button>' +
        '<button class="pu-btn sm dang" data-act="comp-clear-ad" type="button">حذف انتخاب</button></div></div>';
      $('puCompAdWarn').innerHTML = (comp.ad.status && comp.ad.status !== 'active' && comp.ad.status !== 'published')
        ? '<div class="pu-note warn">این آگهی در وضعیت «' + esc(comp.ad.status) + '» است؛ طبق قواعد سامانه انتشار آن بلامانع است ولی بهتر است ابتدا وضعیت را بررسی کنید.</div>'
        : '';
    } else {
      $('puCompAdEmpty').style.display = 'block';
      chosen.style.display = 'none';
      $('puCompAdWarn').innerHTML = '';
    }

    // گالری تصاویر (+ افزودن تصویر دلخواه برای انتشار)
    var gal = $('puCompGallery');
    $('puCompMediaWrap').classList.toggle('hidden', comp.type !== 'ad' || comp.mediaMode === 'none');
    var upTile = (comp.type === 'ad' && comp.ad)
      ? '<div class="pm-gal add" data-act="comp-gal-add" title="افزودن تصویر دلخواه (از گوشی/سیستم) برای انتشار همراه این آگهی">' +
        '<span style="font-size:22px;line-height:1.4">＋</span><span class="pm-sub" style="font-size:11px">افزودن تصویر</span></div>'
      : '';
    if (!comp.media.length) {
      gal.innerHTML = upTile;
      $('puCompGalleryEmpty').style.display = comp.type === 'ad' && comp.ad ? 'none' : 'block';
    } else {
      $('puCompGalleryEmpty').style.display = 'none';
      var ord = 0;
      gal.innerHTML = comp.media.map(function (x, i) {
        if (x.sel) ord++;
        return '<div class="pm-gal' + (x.sel ? ' sel' : '') + '" data-act="comp-gal" data-i="' + i + '" title="برای انتخاب/حذف کلیک کنید">' +
          '<img src="' + esc(x.src) + '" alt="">' + (x.sel ? '<span class="ord">' + ord + '</span>' : '') +
          '<span class="chk"></span></div>';
      }).join('') + upTile;
    }
    var selCount = comp.media.filter(function (x) { return x.sel; }).length;
    $('puCompMediaCount').textContent = comp.mediaMode === 'all'
      ? ('همهٔ ' + comp.media.length + ' تصویر منتشر می‌شود.')
      : comp.mediaMode === 'none' ? 'بدون تصویر منتشر می‌شود.'
      : (selCount + ' تصویر از ' + comp.media.length + ' تصویر انتخاب شده است.');
    Array.prototype.forEach.call(document.querySelectorAll('#pmSecMedia .pm-choice[data-mm]'), function (el) {
      el.classList.toggle('on', el.dataset.mm === comp.mediaMode);
      el.querySelector('input').checked = el.dataset.mm === comp.mediaMode;
    });

    // کارت‌های کانال (+ انتخاب کانال مقصد از فهرست کانال‌های ثبت‌شده)
    $('puCompChans').innerHTML = ['telegram', 'bale', 'eitaa'].map(function (p) {
      var cs = comp.chanStatus[p] || {};
      var badge = cs.configured
        ? '<span class="pm-badge ok">متصل</span>'
        : '<span class="pm-badge warn">نیاز به بررسی اتصال</span>';
      var reg = (comp.registry && comp.registry[p]) || [];
      var destSel = '<div class="pu-field" style="margin-top:6px"><label>کانال مقصد ' + platName(p) + '</label>' +
        '<select data-act="comp-chan-dest" data-p="' + p + '">' +
        '<option value="">(کانال پیش‌فرض تنظیمات)</option>' +
        reg.map(function (c) {
          return '<option value="' + esc(c.channel_id) + '"' + (comp.chans[p].channel === c.channel_id ? ' selected' : '') + '>' +
            esc(c.title || c.channel_id) + '</option>';
        }).join('') + '</select>' +
        (reg.length ? '' : '<div class="pm-sub">کانالی ثبت نشده — از تب «ربات و کانال» کانال اضافه کنید.</div>') +
        '</div>';
      return '<div class="pm-chan' + (comp.chans[p].on ? ' on' : '') + '" data-p="' + p + '">' +
        '<div class="hd"><label class="pm-toggle"><input type="checkbox" data-act="comp-chan" data-p="' + p + '"' + (comp.chans[p].on ? ' checked' : '') + '>' +
        '<span class="nm">' + platName(p) + '</span></label>' + badge + '</div>' +
        (cs.channel_id ? '<div class="pu-mono" style="margin-top:4px">کانال: ' + esc(cs.channel_id) + '</div>' : '') +
        destSel +
        '</div>';
    }).join('');

    // پنل اختصاصی کانال‌ها
    $('pmSecPer').classList.toggle('hidden', !(comp.per && comp.type));
    $('puCompPerTabs').style.display = comp.per ? 'flex' : 'none';
    if (comp.per) {
      var onPlats = ['telegram', 'bale', 'eitaa'].filter(function (p) { return comp.chans[p].on; });
      if (onPlats.indexOf(comp.perTab) === -1) comp.perTab = onPlats[0] || 'telegram';
      $('puCompPerTabs').innerHTML = onPlats.map(function (p) {
        return '<button class="pm-tab' + (p === comp.perTab ? ' on' : '') + '" data-act="comp-per-tab" data-p="' + p + '" type="button">' + platName(p) + '</button>';
      }).join('');
      var ch = comp.chans[comp.perTab] || {};
      $('puCompPerBody').innerHTML =
        '<div class="pu-field"><label>متن مخصوص ' + platName(comp.perTab) + ' (خالی = متن پیش‌فرض)</label>' +
        '<textarea data-act="comp-per-text" placeholder="برای استفاده از متن پیش‌فرض، خالی بگذارید.">' + esc(ch.text || '') + '</textarea></div>' +
        '<div class="pu-row">' +
        '<div class="pu-field" style="flex:1;min-width:140px"><label>تصاویر این کانال</label><select data-act="comp-per-mm">' +
        ['<option value="">پیروی از تنظیم کلی</option>'].concat(['all', 'selected', 'none'].map(function (v) {
          var lb = v === 'all' ? 'همه' : v === 'selected' ? 'انتخابی' : 'بدون تصویر';
          return '<option value="' + v + '"' + (ch.mediaMode === v ? ' selected' : '') + '>' + lb + '</option>';
        })).join('') + '</select></div>' +
        '<div class="pu-field" style="flex:1;min-width:140px"><label>زمان این کانال (شمسی — خالی = زمان کلی)</label>' +
        '<input data-act="comp-per-when" value="' + esc(ch.when || '') + '" placeholder="۱۴۰۵/۰۷/۲۰ ۱۸:۰۰" data-mk-jalali></div>' +
        '</div>' +
        '<div class="pm-sub">محدودیت‌ها: ' + esc((comp.chanStatus[comp.perTab] && comp.chanStatus[comp.perTab].caps) || 'متن/عکس/آلبوم — بسته به پیام‌رسان') + '</div>';
    }

    // زمان
    $('puCompWhenWrap').classList.toggle('hidden', comp.wm !== 'schedule');
    Array.prototype.forEach.call(document.querySelectorAll('#pmSecWhen .pm-choice[data-wm]'), function (el) {
      el.classList.toggle('on', el.dataset.wm === comp.wm);
      el.querySelector('input').checked = el.dataset.wm === comp.wm;
    });
    // زمان جداگانهٔ هر کانال: سه تاریخ/ساعت قابل تنظیم
    var perTimeBox = $('puCompPerTimes');
    if (perTimeBox) {
      perTimeBox.classList.toggle('hidden', !(comp.perTime && comp.wm === 'schedule'));
      if (comp.perTime && comp.wm === 'schedule') {
        perTimeBox.innerHTML = ['telegram', 'bale', 'eitaa'].filter(function (p2) { return comp.chans[p2].on; }).map(function (p2) {
          var ch = comp.chans[p2];
          // باگ r19: ساعت/دقیقه باید per-channel بمانند؛ قبلاً گزینه‌ها با مقدار کلی
          // ساخته می‌شدند و بعد از هر re-render انتخاب کاربر به حالت کلی برمی‌گشت
          var hh = (ch.whenHour !== null && ch.whenHour !== undefined && ch.whenHour !== '') ? ch.whenHour : comp.hour;
          var mm = (ch.whenMin !== null && ch.whenMin !== undefined && ch.whenMin !== '') ? ch.whenMin : comp.minute;
          var hourOpts = COMP_HOURS.map(function (h) { return '<option' + (h === hh ? ' selected' : '') + '>' + h + '</option>'; }).join('');
          var minOpts = COMP_MINS.map(function (m2) { return '<option' + (m2 === mm ? ' selected' : '') + '>' + m2 + '</option>'; }).join('');
          return '<div class="pm-when-row" data-p="' + p2 + '">' +
            '<div class="lbl">زمان انتشار ' + platName(p2) + '</div>' +
            '<input data-act="comp-when-date" data-p="' + p2 + '" value="' + esc(ch.whenDate || '') + '" placeholder="۱۴۰۵/۰۷/۲۰" data-mk-jalali style="width:130px">' +
            '<select data-act="comp-when-hour" data-p="' + p2 + '" style="width:74px">' + hourOpts + '</select>' +
            '<span>:</span>' +
            '<select data-act="comp-when-min" data-p="' + p2 + '" style="width:74px">' + minOpts + '</select>' +
            (ch.whenDate ? '' : '<span class="pm-badge">از زمان کلی پیروی می‌کند</span>') +
            '</div>';
        }).join('');
        Array.prototype.forEach.call(perTimeBox.querySelectorAll('[data-mk-jalali]'), mkAttachJalaliPicker);
      }
    }

    // پیش‌نمایش
    var prevOn = ['telegram', 'bale', 'eitaa'].filter(function (p) { return comp.chans[p].on; });
    if (!prevOn.length) {
      $('puCompPrevTabs').innerHTML = '';
      $('puCompPrevBody').innerHTML = '<div class="pu-empty">ابتدا حداقل یک پیام‌رسان را انتخاب کنید.</div>';
    } else {
      if (prevOn.indexOf(comp.prevTab) === -1) comp.prevTab = prevOn[0];
      $('puCompPrevTabs').innerHTML = prevOn.map(function (p) {
        return '<button class="pm-tab' + (p === comp.prevTab ? ' on' : '') + '" data-act="comp-prev-tab" data-p="' + p + '" type="button">' + platName(p) + '</button>';
      }).join('');
      var ids = compMediaIds(comp.prevTab);
      var idset = {}; ids.forEach(function (id) { idset[String(id)] = 1; });
      var imgs = comp.media.filter(function (x) { return idset[String(x.id)]; });
      // باگ r19: محتوای عمومی اصلاً رسانه در پیش‌نمایش نشان نمی‌داد (comp.media خالی بود)
      var contFiles = comp.type === 'content'
        ? (comp.content.media || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean)
        : [];
      var contThumbs = contFiles.filter(function (n) { return !/\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(n); });
      var contVids = contFiles.filter(function (n) { return /\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(n); });
      $('puCompPrevBody').innerHTML =
        '<div class="pu-mono" style="margin-bottom:6px">به ' + platName(comp.prevTab) + ' — ' + esc(compWhenFor(comp.prevTab) || 'فوری') + '</div>' +
        (imgs.length ? '<div class="pm-thumbs">' + imgs.map(function (x) { return '<img src="' + esc(x.src) + '" alt="">'; }).join('') + '</div>' : '') +
        (contThumbs.length ? '<div class="pm-thumbs">' + contThumbs.map(function (n) { return '<img src="../uploads/' + encodeURIComponent(n.replace(/^uploads\//, '')) + '" alt="">'; }).join('') + '</div>' : '') +
        (contVids.length ? '<div class="pm-chips" style="margin:6px 0">' + contVids.map(function (n) { return '<span class="pm-chip">\uD83C\uDFAC ' + esc(n) + '</span>'; }).join('') + '</div>' : '') +
        '<div class="pm-bubble">' + esc(compText(comp.prevTab) || '(بدون متن)') +
        (comp.ad && comp.track !== false ? '<div class="lnk">مشاهدهٔ آگهی در ملکینو</div>' : '') + '</div>' +
        (comp.type === 'ad' && !comp.ad ? '<div class="pm-err">آگهی‌ای انتخاب نشده است.</div>' : '');
    }

    // خلاصه
    var sumRows = [];
    sumRows.push(['محتوا', comp.type === 'ad' ? ((comp.ad ? (comp.ad.title || comp.ad.id) : '— آگهی انتخاب نشده —')) : ((comp.content.title || comp.content.body || 'مطلب عمومی').slice(0, 40))]);
    sumRows.push(['رسانه', comp.type === 'content'
      ? (((comp.content.media || '').split(',').filter(function (s) { return s.trim(); }).length) + ' فایل — طبق محتوای عمومی')
      : ($('puCompMediaCount').textContent || '')]);
    sumRows.push(['نوع انتشار', comp.wm === 'now' ? 'فوری' : ('زمان‌بندی‌شده — ' + (compWhenFor('telegram') || '—'))]);
    sumRows.push(['کانال‌ها', prevOn.map(function (p) {
      var w = compWhenFor(p);
      return platName(p) + (comp.perTime && w ? ' (' + w + ')' : '');
    }).join('، ') || '—']);
    if (comp.per) sumRows.push(['تنظیمات اختصاصی', 'فعال — تفاوت‌ها در بخش ۶']);
    $('puCompSummary').innerHTML = sumRows.map(function (r) {
      return '<div class="r"><span>' + esc(r[0]) + '</span><span>' + esc(r[1]) + '</span></div>';
    }).join('');

    // دکمهٔ اصلی
    $('puCompSave').textContent = comp.wm === 'now' ? 'تأیید و انتشار' : 'ثبت برنامهٔ انتشار';
  }

  function openComposer(ad, isoDay) {
    comp = {
      type: 'ad', ad: null, mediaMode: 'all', media: [], registry: {},
      chans: {
        telegram: { on: true, defaultText: '', text: '', mediaMode: '', when: '', whenDate: '', whenHour: '', whenMin: '', channel: '' },
        bale: { on: true, defaultText: '', text: '', mediaMode: '', when: '', whenDate: '', whenHour: '', whenMin: '', channel: '' },
        eitaa: { on: true, defaultText: '', text: '', mediaMode: '', when: '', whenDate: '', whenHour: '', whenMin: '', channel: '' },
      },
      per: false, perTab: 'telegram', perTime: false,
      wm: isoDay ? 'schedule' : 'now', when: isoDay || '', hour: '18', minute: '00',
      templateId: '', track: true, prevTab: 'telegram',
      content: { title: '', body: '', typed: '', media: '' }, chanStatus: {},
    };
    var hSel = $('puCompHour');
    if (hSel && !hSel.options.length) COMP_HOURS.forEach(function (h) { hSel.add(new Option(h, h)); });
    hSel.value = comp.hour;
    var mSel = $('puCompMinute');
    if (mSel && !mSel.options.length) COMP_MINS.forEach(function (m0) { mSel.add(new Option(m0, m0)); });
    $('puCompMinute').value = comp.minute;
    $('puCompWhen').value = comp.when;
    $('puCompAdSearch').value = '';
    $('puCompAdResults').innerHTML = '';
    $('puCompPerChannel').checked = false;
    $('puCompPerTime').checked = false;
    $('puCompChanErr').textContent = '';
    $('puCompWhenErr').textContent = '';
    $('puComposerModal').classList.add('on');
    api('channels').then(function (j) {
      if (j && j.success) {
        (j.channels || []).forEach(function (c) {
          comp.chanStatus[c.platform] = {
            configured: !!c.configured, channel_id: c.channel_id || '',
            caps: ['متن', c.capabilities && c.capabilities.send_text === 'yes' ? '✓' : '✕',
                   'عکس', c.capabilities && c.capabilities.send_photo === 'yes' ? '✓' : '✕',
                   'آلبوم', c.capabilities && c.capabilities.send_album === 'yes' ? '✓' : '✕'].join(' '),
          };
        });
      }
      renderComposer();
    }).catch(function () { renderComposer(); });
    api('channels_registry').then(function (j) {
      if (j && j.success) {
        comp.registry = j.registry || {};
        var dl = $('puChRegistryList');
        if (dl) {
          dl.innerHTML = ['telegram', 'bale', 'eitaa'].flatMap(function (p) {
            return ((comp.registry[p]) || []).map(function (c) {
              return '<option value="' + esc(c.channel_id) + '">' + esc(platName(p) + ' — ' + (c.title || c.channel_id)) + '</option>';
            });
          }).join('');
        }
        renderComposer();
      }
    }).catch(function () {});
    if (ad) chooseCompAd(ad);
    renderComposer();
  }

  function chooseCompAd(ad) {
    comp.ad = ad;
    comp.type = 'ad';
    comp.media = [];
    ['telegram', 'bale', 'eitaa'].forEach(function (p) {
      api('ad_preview', null, { ad_id: ad.id, platform: p }).then(function (j) {
        if (!j.success) return;
        comp.chans[p].defaultText = j.text || '';
        comp.chans[p].textManual = false;
        if (p === 'telegram') {
          comp.media = (j.media || []).map(function (m, i) {
            var fn = String(m.filename || '').replace(/^.*[\\\/]/, '');
            return { id: String(m.image_id || m.filename || i), src: m.url || m.public_url || ('uploads/' + fn), sel: true };
          });
        }
        renderComposer();
      });
    });
    renderComposer();
  }

  function searchCompAds() {
    var q = ($('puCompAdSearch').value || '').trim();
    api('ad_search', null, q ? { q: q } : null).then(function (j) {
      if (!j.success) return;
      $('puCompAdResults').innerHTML = (j.ads || []).slice(0, 12).map(function (a) {
        return '<div class="pm-result" data-act="comp-pick-ad" data-id="' + esc(a.id) + '">' +
          (a.image ? '<img src="' + esc(a.image) + '" alt="">' : '') +
          '<div class="meta"><div class="ti">' + esc(a.title || a.id) + '</div>' +
          '<div class="pu-mono">' + esc(a.id) + '</div>' +
          '<div>' + esc([a.location, a.area, a.price, a.status].filter(Boolean).join(' — ')) + '</div>' +
          (a.pub_state === 'none' || (!a.published_count && a.pub_state !== undefined)
            ? '<div><span class="pm-badge bad">منتشر نشده</span></div>'
            : '<div><span class="pm-badge ok">منتشرشده</span></div>') + '</div></div>';
      }).join('') || '<div class="pu-empty">نتیجه‌ای پیدا نشد — عبارت دیگری را امتحان کنید.</div>';
    });
  }

  /* اعتبارسنجی زمان‌بندی: زمان هر کانال فعال (زمان جداگانه یا زمان کلی) باید وجود داشته باشد،
     معتبر باشد و در آینده باشد. مقایسه با ساعت واقعی و به وقت ایران انجام می‌شود (نه منطقهٔ زمانی مرورگر). */
  function tehranMs(d) {
    // ایران از ۲۰۲۲ ساعت تابستانی ندارد: UTC+03:30 ثابت است
    return Date.UTC(d.getFullYear(), d.getMonth(), d.getDate(), d.getHours(), d.getMinutes(), 0) - 210 * 60000;
  }

  function scheduleError(onPlats) {
    var nowMs = Date.now();
    for (var i = 0; i < onPlats.length; i++) {
      var p = onPlats[i];
      var w = compWhenFor(p);
      if (!w) return 'تاریخ یا ساعت وارد نشده است.';
      var d = parseJalali(w);
      if (!d) return 'تاریخ معتبر نیست (مثال: ۱۴۰۵/۰۷/۲۰ یا ۱۴۰۵-۰۷-۲۰).';
      if (tehranMs(d) < nowMs - 60000) return 'زمان ' + platName(p) + ' در گذشته است (به وقت ایران).';
    }
    return '';
  }

  /* رندر قالب برای محتوای عمومی؛ همان قاعدهٔ melkinoPublishTemplateRender در سرور.
     {body} همیشه متنی است که کاربر خودش نوشته (نه نتیجهٔ قالب قبلی) تا با درج‌های متوالی تکرار نشود. */
  function renderContentTemplate(tpl, src) {
    var vals = { title: src.title || '', body: src.typed || '', footer: tpl.footer || '' };
    var out = String(tpl.body || '').replace(/\{(title|body|footer|price|area|location|rooms|phone|link)\}/g, function (m, k) {
      return Object.prototype.hasOwnProperty.call(vals, k) ? vals[k] : '';
    }).trim();
    return out + (tpl.footer ? '\n\n' + tpl.footer : '');
  }

  function saveComposer(asDraft) {
    if (!comp) { toast('فرم برنامه آماده نیست — مودال را ببندید و دوباره باز کنید.', false); return; }
    $('puCompChanErr').textContent = '';
    $('puCompWhenErr').textContent = '';
    if (comp.type === 'ad' && !comp.ad) {
      $('puCompChanErr').textContent = 'ابتدا آگهی را انتخاب کنید.';
      $('pmSecAd').scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast('ابتدا آگهی را انتخاب کنید.', false);
      return;
    }
    var onPlats = ['telegram', 'bale', 'eitaa'].filter(function (p) { return comp.chans[p].on; });
    if (!onPlats.length) {
      $('puCompChanErr').textContent = 'حداقل یک پیام‌رسان را انتخاب کنید.';
      $('pmSecChannels').scrollIntoView({ behavior: 'smooth', block: 'center' });
      toast('حداقل یک پیام‌رسان را انتخاب کنید.', false);
      return;
    }
    var when = '';
    if (!asDraft && comp.wm === 'schedule') {
      var timeErr = scheduleError(onPlats);
      if (timeErr) {
        $('puCompWhenErr').textContent = timeErr;
        if ($('puCompWhenWrap')) $('puCompWhenWrap').scrollIntoView({ behavior: 'smooth', block: 'center' });
        toast(timeErr, false);
        return;
      }
      // زمان کلی (نه زمان یک کانال) به‌عنوان زمان آیتم ثبت می‌شود؛ زمان هر کانال جداگانه در target می‌رود
      when = comp.when ? comp.when + ' ' + comp.hour + ':' + comp.minute : '';
    }
    var payload = {
      title: comp.type === 'content' ? (comp.content.title || 'محتوای عمومی') : (comp.ad ? (comp.ad.title || '') : ''),
      body: comp.type === 'content' ? (comp.content.body || '') : '',
      targets: onPlats.map(function (p) {
        var contFiles = (comp.content.media || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
        var t = {
          platform: p,
          channel_id: comp.chans[p].channel || '',
          text: compText(p),
          // متن خودکار آگهی: سرور هنگام ارسال از تنظیمات فعلی بازسازی می‌کند (اگر کاربر متن را دستی تغییر نداده باشد)
          text_auto: comp.type === 'ad' && !comp.chans[p].textManual && !(comp.per && comp.chans[p].text !== ''),
          // باگ r19: برای محتوای عمومی، رسانه باید صریح در هر target بیاید (مثل تب محتوا)
          media_mode: comp.type === 'content' ? (contFiles.length ? 'selected' : 'none')
            : ((comp.per && comp.chans[p].mediaMode) ? comp.chans[p].mediaMode : comp.mediaMode),
          media: comp.type === 'content' ? contFiles : compMediaIds(p),
          scheduled_at: compWhenFor(p),
        };
        return t;
      }),
      scheduled_at: when,
      track_link: comp.track,
      draft: asDraft ? 1 : 0,
    };
    if (comp.type === 'ad') {
      payload.ad_id = comp.ad.id;
    } else {
      var files = (comp.content.media || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
      payload.media = files.map(function (f) { return { filename: f, storage_path: 'uploads/' + f.replace(/^uploads\//, '') }; });
    }
    $('puCompSave').disabled = true;
    api(comp.type === 'content' ? 'content_save' : 'create', payload).then(function (r) {
      $('puCompSave').disabled = false;
      if (r.success) {
        toast(r.message || (asDraft ? 'پیش‌نویس ذخیره شد.' : 'برنامهٔ انتشار ثبت شد.'), true);
        $('puComposerModal').classList.remove('on');
        loadOverview(); loadQueue(); loadContent();
      } else {
        toast(r.message || 'ثبت برنامه انجام نشد.', false);
      }
    });
  }

  /* ═══════ تقویم شمسی بازشونده (بدون وابستگی) ═══════ */
  function mkG2J(gy, gm, gd) {
    var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
      + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
    var jy = -1595 + (33 * Math.floor(days / 12053));
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    var jm = days < 186 ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
    var jd = 1 + (days < 186 ? days % 31 : (days - 186) % 30);
    return [jy, jm, jd];
  }
  function mkJ2G(jy, jm, jd) {
    var gy = jy + 621;
    var a = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var doy = a[jm - 1] + jd;
    var leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
    if (jm > 6 && !(leap && doy > 79)) doy++; else if (jm > 6) doy--;
    return [gy, doy];
  }
  function mkJ2GDate(jy, jm, jd) {
    var g = mkJ2G(jy, jm, jd);
    return new Date(g[0], 0, g[1]);
  }
  var MK_J_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  var MK_J_DOW = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

  function mkAttachJalaliPicker(input) {
    if (!input || input.dataset.mkPicker === '1') return;
    input.dataset.mkPicker = '1';
    input.setAttribute('readonly', 'readonly');
    input.style.cursor = 'pointer';
    var pop = document.getElementById('mkJalaliPop');
    if (!pop) {
      pop = document.createElement('div');
      pop.id = 'mkJalaliPop';
      pop.className = 'mk-jalali-pop';
      document.body.appendChild(pop);
      pop.addEventListener('click', function (e) { if (pop._act) pop._act(e); });
    }
    var state = { jy: 0, jm: 0 };
    var toFa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };
    var render = function () {
      var first = mkJ2GDate(state.jy, state.jm, 1);
      var startDow = (first.getDay() + 1) % 7; // شنبه=0
      var daysInMonth = state.jm <= 6 ? 31 : (state.jm <= 11 ? 30 : 29);
      var now = new Date();
      var nowJ = mkG2J(now.getFullYear(), now.getMonth() + 1, now.getDate());
      var html = '<div class="jp-head"><button type="button" class="jp-nav" data-mv="-1">›</button>' +
        '<span>' + MK_J_MONTHS[state.jm - 1] + ' ' + toFa(state.jy) + '</span>' +
        '<button type="button" class="jp-nav" data-mv="1">‹</button></div><div class="jp-grid">';
      MK_J_DOW.forEach(function (d) { html += '<div class="jp-dow">' + d + '</div>'; });
      for (var i = 0; i < startDow; i++) html += '<div></div>';
      for (var d = 1; d <= daysInMonth; d++) {
        var cls = 'jp-day';
        if (nowJ[0] === state.jy && nowJ[1] === state.jm && nowJ[2] === d) cls += ' today';
        var selStr = state.jy + '/' + String(state.jm).padStart(2, '0') + '/' + String(d).padStart(2, '0');
        var cur = (input.value || '').replace(/[۰-۹]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(x); });
        if (cur.indexOf(selStr) === 0) cls += ' sel';
        html += '<button type="button" class="' + cls + '" data-d="' + d + '">' + toFa(d) + '</button>';
      }
      html += '</div><div class="jp-foot"><button type="button" data-act="today">امروز</button><button type="button" data-act="clear">پاک کردن</button></div>';
      pop.innerHTML = html;
    };
    var open = function () {
      pop._act = handlePopClick;
      var v = (input.value || '').replace(/[۰-۹]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(x); })
        .replace(/[٠-٩]/g, function (x) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(x); });
      var m = v.match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
      var now = new Date();
      var j = m ? [+m[1], +m[2]] : mkG2J(now.getFullYear(), now.getMonth() + 1, now.getDate());
      state.jy = j[0]; state.jm = j[1];
      render();
      var r = input.getBoundingClientRect();
      pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
      pop.style.left = Math.max(8, Math.min(window.scrollX + r.left - 60, window.scrollX + window.innerWidth - 300)) + 'px';
      pop.classList.add('on');
    };
    var close = function () { pop.classList.remove('on'); };
    input.addEventListener('click', function (e) {
      e.stopPropagation();
      if (pop.classList.contains('on') && pop.dataset.for === (input.id || input.dataset.act || '')) { close(); return; }
      pop.dataset.for = input.id || input.dataset.act || '';
      pop._target = input;
      open();
    });
    var handlePopClick = function (e) {
      e.stopPropagation();
      var t = e.target.closest('button');
      if (!t) return;
      if (t.dataset.mv) {
        state.jm += (+t.dataset.mv);
        if (state.jm > 12) { state.jm = 1; state.jy++; }
        if (state.jm < 1) { state.jm = 12; state.jy--; }
        render();
      } else if (t.dataset.d) {
        var val = state.jy + '/' + String(state.jm).padStart(2, '0') + '/' + String(t.dataset.d).padStart(2, '0');
        var tgt = pop._target;
        if (tgt) {
          tgt.value = val;
          tgt.dispatchEvent(new Event('input', { bubbles: true }));
        }
        close();
      } else if (t.dataset.act === 'today') {
        var now2 = new Date();
        var j2 = mkG2J(now2.getFullYear(), now2.getMonth() + 1, now2.getDate());
        var tgt2 = pop._target;
        if (tgt2) {
          tgt2.value = j2[0] + '/' + String(j2[1]).padStart(2, '0') + '/' + String(j2[2]).padStart(2, '0');
          tgt2.dispatchEvent(new Event('input', { bubbles: true }));
        }
        close();
      } else if (t.dataset.act === 'clear') {
        var tgt3 = pop._target;
        if (tgt3) { tgt3.value = ''; tgt3.dispatchEvent(new Event('input', { bubbles: true })); }
        close();
      }
    };
    document.addEventListener('click', function () { close(); });
  }

  /* ═══════ توقف/ادامهٔ سراسری انتشار ═══════ */
  function renderPause(paused) {
    var b = $('puPauseBtn');
    if (!b) return;
    b.classList.toggle('is-running', !paused);
    b.classList.toggle('is-paused', !!paused);
    b.innerHTML = paused ? '⏸ توقف است — ادامه بده' : '▶ ادامه طبق برنامه';
    b.title = paused ? 'انتشار متوقف است؛ برای ادامه کلیک کنید' : 'برای توقف کلی انتشار کلیک کنید';
    $('puPausedBanner').classList.toggle('hidden', !paused);
    window.__puPaused = !!paused;
  }
  function togglePause() {
    var paused = !!window.__puPaused;
    api(paused ? 'publish_resume' : 'publish_pause', {}).then(function (r) {
      if (r.success) {
        renderPause(!paused);
        toast(r.message || (!paused ? 'انتشار سراسری متوقف شد.' : 'انتشار از سر گرفته شد.'), true);
      } else {
        toast(r.message || 'تغییر وضعیت انجام نشد.', false);
      }
    });
  }

  /* ═══════ پشتیبان‌گیری / بازیابی / اکسل ═══════ */
  function mkDownload(filename, blob) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
  }
  function wireBackupTools() {
    var info = $('puBackupInfo');
    $('puBackupBtn').addEventListener('click', function () {
      api('backup').then(function (r) {
        if (!r.success) { toast(r.message || 'پشتیبان‌گیری نشد.', false); return; }
        var counts = r.counts || {};
        var sum = Object.keys(counts).map(function (k) { return k + ': ' + counts[k]; }).join(' · ');
        info.innerHTML = 'پشتیبان آماده شد — <strong>' + esc(sum) + '</strong>';
        mkDownload('melkino-publish-backup-' + new Date().toISOString().slice(0, 10) + '.json',
          new Blob([JSON.stringify(r.backup, null, 1)], { type: 'application/json' }));
        toast('فایل پشتیبان دانلود شد.', true);
      });
    });
    $('puRestoreBtn').addEventListener('click', function () { $('puRestoreFile').click(); });
    $('puRestoreFile').addEventListener('change', function () {
      var f = this.files[0];
      this.value = '';
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function () {
        var data;
        try { data = JSON.parse(reader.result); } catch (e) { toast('فایل JSON معتبر نیست.', false); return; }
        if (!confirm('بازیابی، تمام برنامه‌های فعلی انتشار را با محتوای فایل جایگزین می‌کند. ادامه می‌دهید؟')) return;
        api('restore', { backup: data, confirmed: 1 }).then(function (r) {
          toast(r.message || (r.success ? 'بازیابی انجام شد.' : 'بازیابی نشد.'), !!r.success);
          if (r.success) {
            var restored = r.restored || {};
            info.innerHTML = 'بازیابی شد — ' + Object.keys(restored).map(function (k) { return k + ': ' + restored[k]; }).join(' · ');
            loadOverview(); loadQueue(); loadHistory(); loadContent();
          }
        });
      };
      reader.readAsText(f);
    });
    $('puXlsxExportBtn').addEventListener('click', function () {
      api('xlsx_export').then(function (r) {
        if (!r.success) { toast(r.message || 'خروجی اکسل ساخته نشد.', false); return; }
        var bin = atob(r.xlsx_base64);
        var arr = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        mkDownload(r.filename || 'melkino-publish.xlsx', new Blob([arr], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
        info.innerHTML = 'خروجی اکسل با <strong>' + r.rows + '</strong> سطر دانلود شد.';
        toast('فایل اکسل دانلود شد.', true);
      });
    });
    $('puXlsxImportBtn').addEventListener('click', function () { $('puXlsxImportFile').click(); });
    $('puXlsxImportFile').addEventListener('change', function () {
      var f = this.files[0];
      this.value = '';
      if (!f) return;
      var fd = new FormData();
      fd.append('file', f);
      fetch('publish-api.php?action=xlsx_import', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-Token': window.MELKINO_CSRF || '' },
        credentials: 'same-origin',
        body: fd,
      }).then(function (res) { return res.json(); }).then(function (r) {
        toast(r.message || (r.success ? 'ورود انجام شد.' : 'ورود انجام نشد.'), !!r.success);
        if (r.success) {
          info.innerHTML = 'ورود اکسل: <strong>' + r.created + '</strong> برنامه ساخته شد' +
            (r.errors && r.errors.length ? '؛ ' + r.errors.length + ' سطر رد شد' : '') + '.';
          if (r.errors && r.errors.length) {
            info.innerHTML += '<br>' + r.errors.slice(0, 6).map(esc).join('<br>');
          }
          loadOverview(); loadQueue(); loadContent();
        }
      }).catch(function () { toast('ارسال فایل ناموفق بود.', false); });
    });
  }

  /* ═══════ قوانین هوشمند توزیع آگهی ═══════ */
  var DIST_TX = ['فروش', 'اجاره', 'رهن و اجاره', 'رهن کامل', 'پیش فروش'];
  var DIST_PT = ['آپارتمان', 'ویلا', 'زمین', 'مغازه', 'تجاری', 'اداری', 'باغ'];
  var distEditingId = 0;
  var distOnMatch = 'suggest';

  function distChips(name, values, selected) {
    return values.map(function (v) {
      var on = (selected || []).indexOf(v) !== -1;
      return '<label class="pu-chip" style="cursor:pointer"><input type="checkbox" name="' + name + '" value="' + esc(v) + '"' + (on ? ' checked' : '') + '> ' + esc(v) + '</label>';
    }).join('');
  }
  function distChecked(name) {
    return Array.prototype.map.call(document.querySelectorAll('#puDistModal input[name="' + name + '"]:checked'), function (c) { return c.value; });
  }
  function loadDist() {
    api('dist_rules').then(function (j) {
      if (!j.success) return;
      var tb = document.querySelector('#puDistTable tbody');
      var rows = j.rules || [];
      $('puDistEmpty').classList.toggle('hidden', rows.length > 0);
      tb.innerHTML = rows.map(function (r) {
        return '<tr data-id="' + r.id + '">' +
          '<td><strong>' + esc(r.name) + '</strong></td>' +
          '<td>' + esc(({ telegram: 'تلگرام', bale: 'بله', eitaa: 'ایتا' })[r.platform] || r.platform) + '<div class="pu-mono">' + esc(r.channel_id) + '</div></td>' +
          '<td style="max-width:220px">' + esc(r.match_summary || '—') + '</td>' +
          '<td>' + (r.match_count || 0) + ' آگهی</td>' +
          '<td>' + esc(({ suggest: 'فقط مقصد', enqueue: 'صف انتشار', schedule: 'زمان‌بندی', approval: 'تأیید ادمین' })[r.on_match] || r.on_match) + '</td>' +
          '<td>' + (r.enabled ? '<span class="pm-badge ok">فعال</span>' : '<span class="pm-badge">غیرفعال</span>') + '</td>' +
          '<td class="pu-mono">' + esc(r.last_run_at || '—') + '</td>' +
          '<td><div class="pu-row">' +
          '<button class="pu-btn sm" data-dist="edit" type="button">ویرایش</button>' +
          '<button class="pu-btn sm" data-dist="toggle" type="button">' + (r.enabled ? 'غیرفعال' : 'فعال') + '</button>' +
          '<button class="pu-btn sm dang" data-dist="del" type="button">حذف</button>' +
          '</div></td></tr>';
      }).join('');
    });
    api('dist_unmatched').then(function (j) {
      if (!j.success) return;
      $('puUnmatchedList').innerHTML = (j.ads || []).map(function (a) {
        return '<span class="pu-chip">' + esc(a.title || a.id) + ' <span class="pu-mono">' + esc(a.id) + '</span> <span class="pm-badge bad">منتشر نشده</span></span>';
      }).join('') || '<span class="pu-empty">همهٔ آگهی‌های تأییدشده با قانونی منطبق‌اند.</span>';
    });
  }
  function openDistModal(rule) {
    distEditingId = rule ? (parseInt(rule.id, 10) || 0) : 0;
    $('puDistModalTitle').textContent = rule ? ('ویرایش قانون: ' + rule.name) : 'قانون توزیع جدید';
    $('puDistName').value = rule ? rule.name : '';
    $('puDistPlatform').value = rule ? rule.platform : 'telegram';
    $('puDistChannel').value = rule ? (rule.channel_id || '') : '';
    var m = (rule && rule.match) || {};
    $('puDistTxTypes').innerHTML = distChips('puDistTx', DIST_TX, m.transaction_types || []);
    $('puDistPropTypes').innerHTML = distChips('puDistPt', DIST_PT, m.property_types || []);
    $('puDistVip').value = (m.is_vip === true || m.is_vip === 1 || m.is_vip === '1') ? '1' : ((m.is_vip === false || m.is_vip === 0 || m.is_vip === '0') ? '0' : '');
    $('puDistCity').value = m.city || '';
    $('puDistHood').value = m.neighborhood || '';
    $('puDistPMin').value = m.price_min || '';
    $('puDistPMax').value = m.price_max || '';
    $('puDistAMin').value = m.area_min || '';
    $('puDistAMax').value = m.area_max || '';
    distOnMatch = rule ? rule.on_match : 'suggest';
    Array.prototype.forEach.call(document.querySelectorAll('#puDistModal .pm-choice[data-onm]'), function (el) {
      el.classList.toggle('on', el.dataset.onm === distOnMatch);
      el.querySelector('input').checked = el.dataset.onm === distOnMatch;
    });
    $('puDistScheduleWrap').classList.toggle('hidden', distOnMatch !== 'schedule');
    $('puDistSchedule').value = rule ? (rule.schedule_jalali || '') : '';
    $('puDistEnabled').checked = rule ? !!Number(rule.enabled) : true;
    $('puDistDelete').style.display = rule ? '' : 'none';
    $('puDistPreviewBox').classList.add('hidden');
    $('puDistModal').classList.add('on');
    mkAttachJalaliPicker($('puDistSchedule'));
  }
  function collectDistRule() {
    var vip = $('puDistVip').value;
    return {
      id: distEditingId || undefined,
      name: $('puDistName').value.trim(),
      platform: $('puDistPlatform').value,
      channel_id: $('puDistChannel').value.trim(),
      on_match: distOnMatch,
      schedule_jalali: $('puDistSchedule').value.trim(),
      enabled: $('puDistEnabled').checked ? 1 : 0,
      transaction_types: distChecked('puDistTx'),
      property_types: distChecked('puDistPt'),
      is_vip: vip === '' ? '' : vip,
      city: $('puDistCity').value.trim(),
      neighborhood: $('puDistHood').value.trim(),
      price_min: $('puDistPMin').value,
      price_max: $('puDistPMax').value,
      area_min: $('puDistAMin').value,
      area_max: $('puDistAMax').value,
    };
  }
  function wireDist() {
    $('puDistNewBtn').addEventListener('click', function () { openDistModal(null); });
    $('puDistClose').addEventListener('click', function () { $('puDistModal').classList.remove('on'); });
    $('puDistCancel').addEventListener('click', function () { $('puDistModal').classList.remove('on'); });
    $('puDistEvalAll').addEventListener('click', function () {
      api('dist_evaluate_all', {}).then(function (r) {
        toast(r.message || (r.success ? 'ارزیابی انجام شد.' : 'ارزیابی نشد.'), !!r.success);
        loadDist();
      });
    });
    document.querySelector('#puDistModal .pm-body').addEventListener('click', function (e) {
      var choice = e.target.closest('.pm-choice[data-onm]');
      if (!choice) return;
      distOnMatch = choice.dataset.onm;
      Array.prototype.forEach.call(document.querySelectorAll('#puDistModal .pm-choice[data-onm]'), function (el) {
        el.classList.toggle('on', el === choice);
        el.querySelector('input').checked = el === choice;
      });
      $('puDistScheduleWrap').classList.toggle('hidden', distOnMatch !== 'schedule');
    });
    $('puDistSave').addEventListener('click', function () {
      var rule = collectDistRule();
      if (!rule.name) { toast('نام قانون لازم است.', false); return; }
      api('dist_rule_save', { rule: rule }).then(function (r) {
        toast(r.message || (r.success ? 'ذخیره شد.' : 'ذخیره نشد.'), !!r.success);
        if (r.success) { $('puDistModal').classList.remove('on'); loadDist(); }
      });
    });
    $('puDistDelete').addEventListener('click', function () {
      if (!distEditingId) return;
      if (!confirm('این قانون حذف شود؟ (برنامه‌ها و تاریخچه‌های موجود حذف نمی‌شوند)')) return;
      api('dist_rule_delete', { id: distEditingId, confirmed: 1 }).then(function (r) {
        toast(r.message || (r.success ? 'حذف شد.' : 'حذف نشد.'), !!r.success);
        if (r.success) { $('puDistModal').classList.remove('on'); loadDist(); }
      });
    });
    $('puDistPreviewBtn').addEventListener('click', function () {
      api('dist_preview', { rule: collectDistRule() }).then(function (r) {
        if (!r.success) { toast(r.message || 'پیش‌نمایش نشد.', false); return; }
        var box = $('puDistPreviewBox');
        box.classList.remove('hidden');
        box.innerHTML = '<strong>پیش‌نمایش (بدون انتشار):</strong> ' + r.matched_count + ' آگهی منطبق · ' +
          r.duplicate_count + ' مقصد تکراری · ' + r.unapproved_count + ' آگهی تأییدنشده (کنار گذاشته می‌شوند)<br>' +
          (r.ads || []).slice(0, 10).map(function (a) {
            return '• ' + esc(a.title || a.id) + ' → ' + (a.routes || []).map(function (x) {
              return esc(x.channel_id || x.platform) + (x.duplicate ? ' (تکراری)' : '');
            }).join('، ');
          }).join('<br>') + ((r.ads || []).length > 10 ? '<br>… و ' + (r.ads.length - 10) + ' مورد دیگر' : '');
      });
    });
    document.querySelector('#puDistTable').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-dist]');
      if (!b) return;
      var id = +b.closest('tr').dataset.id;
      if (b.dataset.dist === 'edit') {
        api('dist_rules').then(function (j) {
          var rule = (j.rules || []).filter(function (x) { return +x.id === id; })[0];
          if (rule) openDistModal(rule);
        });
      } else if (b.dataset.dist === 'toggle') {
        api('dist_rule_toggle', { id: id }).then(function (r) {
          toast(r.message || '', !!r.success);
          loadDist();
        });
      } else if (b.dataset.dist === 'del') {
        if (!confirm('این قانون حذف شود؟ (برنامه‌های موجود حذف نمی‌شوند)')) return;
        api('dist_rule_delete', { id: id, confirmed: 1 }).then(function (r) {
          toast(r.message || '', !!r.success);
          loadDist();
        });
      }
    });
  }

  /* پیش‌نمایش زندهٔ تب «مطالب عمومی» (باگ r19: محتوای عمومی اصلاً پیش‌نمایش نداشت) */
  function renderContPreview() {
    var tabs = $('puContPrevTabs'), body = $('puContPrevBody');
    if (!tabs || !body) return;
    var plats = platChecks('puContPlats');
    if (!plats.length) plats = ['telegram'];
    if (!window.__puContPrevTab || plats.indexOf(window.__puContPrevTab) === -1) window.__puContPrevTab = plats[0];
    tabs.innerHTML = plats.map(function (p) {
      return '<button class="pm-tab' + (p === window.__puContPrevTab ? ' on' : '') + '" data-act="cont-prev-tab" data-p="' + p + '" type="button">' + platName(p) + '</button>';
    }).join('');
    var txt = ($('puContBody') ? $('puContBody').value : '') || '';
    var files = ($('puContMedia') ? $('puContMedia').value : '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var thumbs = files.filter(function (n) { return !/\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(n); });
    var vids = files.filter(function (n) { return /\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(n); });
    body.innerHTML =
      '<div class="pu-mono" style="margin-bottom:6px">به ' + platName(window.__puContPrevTab) + '</div>' +
      (thumbs.length ? '<div class="pm-thumbs">' + thumbs.map(function (n) { return '<img src="../uploads/' + encodeURIComponent(n.replace(/^uploads\//, '')) + '" alt="">'; }).join('') + '</div>' : '') +
      (vids.length ? '<div class="pm-chips" style="margin:6px 0">' + vids.map(function (n) { return '<span class="pm-chip">\uD83C\uDFAC ' + esc(n) + '</span>'; }).join('') + '</div>' : '') +
      '<div class="pm-bubble">' + esc(txt || '(بدون متن)') + '</div>' +
      (!txt && !files.length ? '<div class="pm-sub" style="margin-top:6px">متن یا رسانه که اضافه شود همین‌جا دیده می‌شود.</div>' : '');
  }

  /* ═══════ آپلود رسانه (محتوای عمومی / آهنگساز) ═══════ */
  function wireUpload(btnId, fileId, textInputId, chipsId) {
    var btn = $(btnId), file = $(fileId), text = $(textInputId);
    if (!btn || !file) return;
    btn.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files || []);
      this.value = '';
      if (!files.length) return;
      files.forEach(function (f) {
        var fd = new FormData();
        fd.append('file', f);
        fetch('publish-api.php?action=upload_media', {
          method: 'POST',
          headers: { Accept: 'application/json', 'X-CSRF-Token': window.MELKINO_CSRF || '' },
          credentials: 'same-origin',
          body: fd,
        }).then(function (res) { return res.json(); }).then(function (r) {
          if (r.success) {
            var isVid = /\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(r.filename || '');
            var kindFa = isVid ? 'ویدیو' : 'تصویر';
            if (text) {
              var cur = (text.value || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
              cur.push(r.filename);
              text.value = cur.join(', ');
              text.dispatchEvent(new Event('input', { bubbles: true }));
            }
            var chips = (chipsId ? document.getElementById(chipsId) : null) || document.getElementById('puContMediaChips') || document.getElementById('puCompMediaChips');
            if (chips) {
              var list = (text ? (text.value || '') : (r.filename || '')).split(',').map(function (x) { return x.trim(); }).filter(Boolean);
              chips.innerHTML = list.map(function (n) {
                var v = /\.(mp4|webm|mov|m4v|mkv|avi)$/i.test(n);
                return '<span class="pu-chip">' + (v ? '\uD83C\uDFAC' : '\uD83D\uDDBC') + ' ' + esc(n) + '</span>';
              }).join('');
            }
            // باگ r19: بازخورد صریح نوع فایل — «نمیگه ویدیو آپلود شد»
            toast(kindFa + ' «' + r.filename + '» با موفقیت آپلود شد \u2713', true);
          } else {
            toast(r.message || 'آپلود ناموفق بود.', false);
          }
        }).catch(function () { toast('آپلود ناموفق بود.', false); });
      });
    });
  }

  /* ================= راه‌اندازی ================= */
  window.melkinoPublishCenterInit = function () {
    bindTabs();
    loadOverview();

    $('puNewBtn').addEventListener('click', function () { openComposer(null, null); });
    // آپلود تصویر دلخواه برای انتشار همراه آگهی
    var upInput = $('puCompUpload');
    if (upInput) upInput.addEventListener('change', function () {
      if (!comp || !comp.ad) { this.value = ''; return; }
      var files = Array.prototype.slice.call(this.files || []);
      this.value = '';
      if (!files.length) return;
      var done = 0, failed = 0;
      files.forEach(function (f) {
        var fd = new FormData();
        fd.append('file', f);
        fd.append('ad_id', comp.ad.id);
        fd.append('csrf_token', window.MELKINO_CSRF || '');
        fetch('publish-api.php?action=ad_photo_add', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': window.MELKINO_CSRF || (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
          body: fd
        }).then(function (r) { return r.json(); }).then(function (j) {
          done++;
          if (j && j.success) {
            comp.media.push({ id: String(j.image_id), src: j.url || ('uploads/' + String(j.filename || '').replace(/^.*[\\\/]/, '')), sel: true });
            if (comp.mediaMode === 'none') comp.mediaMode = 'selected';
          } else { failed++; }
          renderComposer();
          if (done === files.length) {
            toast(failed ? ('افزودن ' + failed + ' تصویر ناموفق بود.') : 'تصویر(ها) اضافه و برای انتشار انتخاب شد.', !failed);
          }
        }).catch(function () {
          done++; failed++;
          renderComposer();
          if (done === files.length) toast('آپلود تصویر ناموفق بود.', false);
        });
      });
    });
    $('puRunQueueBtn').addEventListener('click', function () {
      $('puRunQueueBtn').disabled = true;
      api('run_due', { max: 10 }).then(function (r) {
        $('puRunQueueBtn').disabled = false;
        toast(r.message || ('پردازش صف: ' + (r.processed || 0) + ' مورد بررسی شد.'), true);
        loadOverview(); loadQueue();
      });
    });

    $('puCalPrev').addEventListener('click', function () { calShift(-1); });
    $('puCalNext').addEventListener('click', function () { calShift(1); });
    $('puCalToday').addEventListener('click', function () { state.jy = 0; loadCalendar(); });
    // لغو انتشار یک مورد از داخل تقویم (رویداد هر روز)
    $('puCalGrid').addEventListener('click', function (e) {
      var x = e.target && e.target.closest ? e.target.closest('.ev-x') : null;
      if (!x) return;
      e.stopPropagation();
      if (!window.confirm('انتشار این مورد در این روز لغو شود؟')) return;
      api('cancel', { target_id: +x.dataset.tid }).then(function (r) {
        if (r && r.success) { toast(r.message || 'لغو شد.', true); loadCalendar(); loadOverview(); }
        else toast((r && r.message) || 'لغو انجام نشد.', false);
      });
    });

    $('puRefreshQueue').addEventListener('click', loadQueue);
    $('puQueueSelAll').addEventListener('change', function () {
      var on = this.checked;
      queueIds.forEach(function (id) { queueSel[id] = on; });
      Array.prototype.forEach.call($('puQueueTable').querySelectorAll('.puQPick'), function (cb) { cb.checked = on; });
      queueSync();
    });
    $('puQueueBulkDelete').addEventListener('click', bulkDeleteQueue);
    $('puBulkSearchBtn').addEventListener('click', function () { searchBulk($('puBulkSearch').value); });
    $('puBulkGo').addEventListener('click', runBulk);
    $('puBulkAll').addEventListener('change', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.puBulkPick'), function (c) { c.checked = $('puBulkAll').checked; });
    });
    $('puContGo').addEventListener('click', runContent);
    $('puHistFilter').addEventListener('click', loadHistory);
    $('puHistRefresh').addEventListener('click', loadHistory);

    $('puComposerClose').addEventListener('click', function () { $('puComposerModal').classList.remove('on'); });
    $('puCompCancel').addEventListener('click', function () { $('puComposerModal').classList.remove('on'); });
    $('puPauseBtn').addEventListener('click', togglePause);
    wireDist();
    wireUpload('puContUploadBtn', 'puContUploadFile', 'puContMedia', 'puContMediaChips');
    wireUpload('puCompUploadBtn', 'puCompUploadFile', 'puContInMedia', 'puCompMediaChips');
    if ($('puContPrevTabs')) {
      $('puContPrevTabs').addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-act="cont-prev-tab"]') : null;
        if (b) { window.__puContPrevTab = b.getAttribute('data-p'); renderContPreview(); }
      });
      ['puContBody', 'puContMedia', 'puContTitle'].forEach(function (id) {
        var el = $(id);
        if (el) el.addEventListener('input', function () { renderContPreview(); });
      });
      var platsBox = $('puContPlats');
      if (platsBox) platsBox.addEventListener('change', function () { renderContPreview(); });
      renderContPreview();
    }
    wireBackupTools();
    loadDist();
    mkAttachJalaliPicker($('puCompWhen'));
    mkAttachJalaliPicker($('puTargetWhen'));
    $('puCompSave').addEventListener('click', function () { saveComposer(false); });
    $('puCompDraft').addEventListener('click', function () { saveComposer(true); });
    $('puCompAdSearchBtn').addEventListener('click', searchCompAds);
    $('puCompAdSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); searchCompAds(); } });
    $('puCompTplFill').addEventListener('click', function () {
      var sel = $('puCompTemplate');
      if (!sel || !sel.value) { toast('ابتدا قالب را انتخاب کنید.', false); return; }
      // آگهی انتخاب شده: قالب با داده‌های همان آگهی در سرور پر می‌شود (مثلاً {title} و {price} جایگزین می‌شوند)
      if (comp.type !== 'content' && comp.ad) {
        var tplId = sel.value;
        var pending = ['telegram', 'bale', 'eitaa'].map(function (p) {
          return api('ad_preview', null, { ad_id: comp.ad.id, platform: p, template_id: tplId }).then(function (j) {
            if (j && j.success) {
              comp.chans[p].text = j.text || '';
              comp.chans[p].defaultText = j.text || '';
              comp.chans[p].textManual = true;
            }
          });
        });
        Promise.all(pending).then(function () {
          renderComposer();
          toast('متن قالب با اطلاعات این آگهی ساخته شد (قابل ویرایش).', true);
        });
        return;
      }
      api('templates').then(function (j) {
        var t = ((j && j.templates) || []).filter(function (x) { return String(x.id) === String(sel.value); })[0];
        if (!t) return;
        var body = (t.body || '') + (t.footer ? '\n\n' + t.footer : '');
        if (comp.type === 'content') {
          // محتوای عمومی آگهی ندارد؛ متغیرها باید همین‌جا پر شوند (وگرنه {title} خام منتشر می‌شود)
          body = renderContentTemplate(t, comp.content);
          comp.content.body = body;
          $('puContInBody').value = body;
        } else {
          ['telegram', 'bale', 'eitaa'].forEach(function (p) { comp.chans[p].text = body; comp.chans[p].defaultText = body; comp.chans[p].textManual = true; });
        }
        renderComposer();
        toast('متن قالب درج شد (قابل ویرایش).', true);
      });
    });
    $('puCompCopyFrom').addEventListener('click', function () {
      var src = $('puCompCopySrc').value;
      ['telegram', 'bale', 'eitaa'].forEach(function (p) {
        if (p === src || !comp.chans[p].on) return;
        comp.chans[p].text = comp.chans[src].text;
        comp.chans[p].mediaMode = comp.chans[src].mediaMode;
        comp.chans[p].when = comp.chans[src].when;
      });
      renderComposer();
      toast('تنظیمات «' + platName(src) + '» به کانال‌های انتخاب‌شده کپی شد.', true);
    });

    // delegation مودال «ثبت برنامهٔ انتشار»
    $('puCompBody').addEventListener('click', function (e) {
      if (!comp) return;
      var t = e.target.closest('[data-act]');
      // انتخاب نوع محتوا / حالت تصاویر / حالت زمان (کارت‌های انتخابی)
      var choice = e.target.closest('.pm-choice');
      if (choice) {
        if (choice.dataset.type) { comp.type = choice.dataset.type; renderComposer(); return; }
        if (choice.dataset.mm) { comp.mediaMode = choice.dataset.mm; renderComposer(); return; }
        if (choice.dataset.wm) { comp.wm = choice.dataset.wm; renderComposer(); return; }
      }
      if (!t) return;
      var act = t.dataset.act;
      if (act === 'comp-pick-ad') {
        var found = null;
        api('ad_search', null, { q: t.dataset.id }).then(function (j) {
          found = ((j && j.ads) || []).filter(function (a) { return String(a.id) === String(t.dataset.id); })[0];
          chooseCompAd(found || { id: t.dataset.id, title: t.querySelector('.ti') ? t.querySelector('.ti').textContent : '' });
        });
      } else if (act === 'comp-change-ad') {
        comp.ad = null; comp.media = [];
        $('puCompAdSearch').focus();
        renderComposer();
      } else if (act === 'comp-clear-ad') {
        comp.ad = null; comp.media = [];
        renderComposer();
      } else if (act === 'comp-gal') {
        var i = +t.dataset.i;
        comp.media[i].sel = !comp.media[i].sel;
        if (comp.mediaMode === 'all') comp.mediaMode = 'selected';
        renderComposer();
      } else if (act === 'comp-gal-add') {
        var fi = $('puCompUpload');
        if (fi) fi.click();
      } else if (act === 'comp-per-tab') {
        comp.perTab = t.dataset.p; renderComposer();
      } else if (act === 'comp-prev-tab') {
        comp.prevTab = t.dataset.p; renderComposer();
      }
    });
    $('puCompBody').addEventListener('change', function (e) {
      if (!comp) return;
      var t = e.target;
      var act = t.dataset ? t.dataset.act : '';
      if (t.id === 'puCompPerChannel') { comp.per = t.checked; renderComposer(); return; }
      if (t.id === 'puCompPerTime') { comp.perTime = t.checked; renderComposer(); return; }
      if (act === 'comp-chan') { comp.chans[t.dataset.p].on = t.checked; renderComposer(); return; }
      if (act === 'comp-chan-dest') { comp.chans[t.dataset.p].channel = t.value; return; }
      if (act === 'comp-per-mm') { comp.chans[comp.perTab].mediaMode = t.value; renderComposer(); return; }
      if (act === 'comp-when-hour') { comp.chans[t.dataset.p].whenHour = t.value; renderComposer(); return; }
      if (act === 'comp-when-min') { comp.chans[t.dataset.p].whenMin = t.value; renderComposer(); return; }
    });
    $('puCompBody').addEventListener('input', function (e) {
      if (!comp) return;
      var t = e.target;
      var act = t.dataset ? t.dataset.act : '';
      if (act === 'comp-when-date') { comp.chans[t.dataset.p].whenDate = t.value; }
      else if (act === 'comp-per-text') { comp.chans[comp.perTab].text = t.value; }
      else if (act === 'comp-per-when') { comp.chans[comp.perTab].when = t.value; }
      else if (t.id === 'puCompWhen') { comp.when = t.value; }
      else if (t.id === 'puContInTitle') { comp.content.title = t.value; }
      else if (t.id === 'puContInBody') { comp.content.body = t.value; comp.content.typed = t.value; }
      else if (t.id === 'puContInMedia') { comp.content.media = t.value; }
      else return;
      // به‌روزرسانی زندهٔ پیش‌نمایش/خلاصه بدون پاک‌شدن فوکوس (بدون re-render کامل)
      clearTimeout(window.__puCompLive);
      window.__puCompLive = setTimeout(function () {
        var active = document.activeElement;
        var id = active ? active.id : '';
        renderComposer();
        if (id) { var el = document.getElementById(id); if (el && el.focus && el.setSelectionRange && el.type !== 'radio' && el.type !== 'checkbox') { try { el.setSelectionRange(el.value.length, el.value.length); } catch (e2) {} } }
      }, 450);
    });
    $('puCompHour').addEventListener('change', function () { comp.hour = this.value; renderComposer(); });
    $('puCompMinute').addEventListener('change', function () { comp.minute = this.value; renderComposer(); });
    $('puCompTrack').addEventListener('change', function () { comp.track = this.value === '1'; renderComposer(); });

    $('puTargetClose').addEventListener('click', function () { $('puTargetModal').classList.remove('on'); });
    $('puTargetReschedule').addEventListener('click', function () {
      api('reschedule', { target_id: state.targetId, scheduled_at: $('puTargetWhen').value }).then(function (r) {
        if (r.success) { toast(r.message || 'زمان‌بندی شد.', true); $('puTargetModal').classList.remove('on'); loadQueue(); }
      });
    });
    $('puTargetRetry').addEventListener('click', function () {
      api('retry', { target_id: state.targetId }).then(function (r) {
        toast(r.message || (r.success ? 'انجام شد.' : 'ناموفق.'), !!r.success);
        $('puTargetModal').classList.remove('on'); loadQueue(); loadOverview();
      });
    });
    $('puTargetEdit').addEventListener('click', function () {
      api('edit_post', { target_id: state.targetId, text: $('puTargetText').value }).then(function (r) {
        toast(r.message || 'انجام شد.', !!r.success);
      });
    });
    $('puTargetDelete').addEventListener('click', function () {
      if (!window.confirm('پست از کانال حذف شود؟ این عملیات ثبت می‌شود.')) return;
      api('delete_post', { target_id: state.targetId }).then(function (r) {
        toast(r.message || 'انجام شد.', !!r.success);
      });
    });
    $('puTargetCancel').addEventListener('click', function () {
      if (!window.confirm('این مقصد لغو شود؟')) return;
      api('cancel', { target_id: state.targetId }).then(function (r) {
        if (r.success) { toast(r.message || 'لغو شد.', true); $('puTargetModal').classList.remove('on'); loadQueue(); }
      });
    });
  };

  /* ================= افزونه‌ها: فیلترها/کارت‌ها/قالب‌ها/تست/حذف گروهی ================= */
  function loadOvList(status) {
    var qs = {
      status: status || '',
      platform: $('puOvPlat') ? $('puOvPlat').value : '',
      from: $('puOvFrom') ? $('puOvFrom').value : '',
      to: $('puOvTo') ? $('puOvTo').value : '',
    };
    api('overview_list', null, qs).then(function (j) {
      var tb = $('puOvList').querySelector('tbody');
      if (!j.success || !(j.rows || []).length) {
        tb.innerHTML = '<tr><td colspan="6" class="pu-empty">موردی با این فیلترها نیست.</td></tr>';
        return;
      }
      tb.innerHTML = j.rows.map(function (r) {
        return '<tr><td>' + jdt(r.scheduled_at || r.published_at) + '</td><td>' + esc(r.title || r.ad_id || '—') + '</td><td>' + platName(r.platform) + '</td><td>' + statusBadge(r.target_status) + '</td><td>' + fa(r.attempts || 0) + '</td><td>' + esc(r.last_error || '—') + '</td></tr>';
      }).join('');
    });
  }

  function loadTemplates() {
    api('templates').then(function (j) {
      if (!j.success) return;
      state.templates = j.templates || [];
      var tb = $('puTplTable').querySelector('tbody');
      tb.innerHTML = state.templates.map(function (t) {
        return '<tr><td>' + esc(t.name) + '</td><td>' + platName(t.platform) + '</td><td>' + (t.kind === 'ad' ? 'آگهی' : 'محتوا') + '</td><td>' + (t.is_default == 1 ? 'بله' : '—') + '</td><td>' +
          '<button class="pu-btn sm" data-tpl-del="' + t.id + '" type="button">حذف</button></td></tr>';
      }).join('') || '<tr><td colspan="5" class="pu-empty">هنوز قالبی ثبت نشده است.</td></tr>';
      Array.prototype.forEach.call(tb.querySelectorAll('[data-tpl-del]'), function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('این قالب حذف شود؟')) return;
          api('template_delete', { id: parseInt(b.dataset.tplDel, 10) }).then(function () { loadTemplates(); });
        });
      });
      var opts = '<option value="">بدون قالب</option>' + state.templates.map(function (t) {
        return '<option value="' + t.id + '">' + esc(t.name) + ' (' + platName(t.platform) + ')</option>';
      }).join('');
      if ($('puContTemplate')) $('puContTemplate').innerHTML = opts;
      if ($('puCompTemplate')) $('puCompTemplate').innerHTML = opts;
    });
  }

  function renderAdPosts(posts, dist) {
    var distBox = $('puAdPostsDist');
    if (distBox) {
      if (!dist) { distBox.innerHTML = ''; }
      else if (dist.no_rule) {
        distBox.innerHTML = '<div class="pu-note warn">برای این آگهی قانون توزیع مشخص نشده است.</div>';
      } else {
        var chip = function (t, cls) { return '<span class="pm-badge ' + (cls || '') + '">' + t + '</span>'; };
        distBox.innerHTML = '<div class="pu-note">' +
          '<strong>قانون‌های منطبق:</strong> ' + (dist.matched_rules || []).map(function (r) { return esc(r.name) + ' ← ' + esc(r.channel_id || r.platform); }).join('، ') + '<br>' +
          '<strong>منتشرشده:</strong> ' + ((dist.published || []).length ? (dist.published || []).map(function (x) { return chip(x.platform + '@' + x.channel_id, 'ok'); }).join(' ') : chip('—')) + ' ' +
          '<strong>در برنامه:</strong> ' + ((dist.planned || []).length ? (dist.planned || []).map(function (x) { return chip(x.platform + '@' + x.channel_id + ' (' + x.status + ')', 'warn'); }).join(' ') : chip('—')) + ' ' +
          '<strong>بدون سابقهٔ موفق:</strong> ' + ((dist.missing || []).length ? (dist.missing || []).map(function (x) { return chip(x.platform + '@' + x.channel_id, 'bad'); }).join(' ') : chip('—')) +
          (!dist.approved ? '<br>' + chip('آگهی تأیید نیست — ورود خودکار ممنوع', 'bad') : '') +
          '</div>';
      }
    }

    var tb = $('puAdPostsTable').querySelector('tbody');
    tb.innerHTML = (posts || []).map(function (p) {
      var ids = (p.message_list || []).map(function (m) { return esc(String(m.message_id || '')); }).join(', ');
      return '<tr><td>' + platName(p.platform) + '</td><td>' + fa(p.message_count || 0) + ' پیام</td><td>' + jdt(p.published_at) + '</td><td class="pu-mono">' + ids + '</td></tr>';
    }).join('') || '<tr><td colspan="4" class="pu-empty">پست منتشرشده‌ای برای این آگهی نیست.</td></tr>';
    return posts || [];
  }

  window.melkinoPublishExtras = function () {
    if (!document.getElementById('puOvFilterBtn')) return;
    $('puOvFilterBtn').addEventListener('click', function () { loadOvList(''); });
    Array.prototype.forEach.call($('puStats').querySelectorAll('.pu-stat'), function (card, idx) {
      card.style.cursor = 'pointer';
      card.title = 'نمایش فهرست این وضعیت';
      card.addEventListener('click', function () {
        var map = ['published', 'queued', 'failed', 'needs_review', 'partial', 'pending'];
        loadOvList(map[idx] || '');
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-test-conn]'), function (b) {
      b.addEventListener('click', function () {
        var parts = b.dataset.testConn.split('|');
        b.disabled = true;
        api('test_connection', { platform: parts[0], kind: parts[1] }).then(function (r) {
          b.disabled = false;
          if (r.success && r.result) toast(r.result.message || 'تست انجام شد.', !!r.result.success);
          else toast(r.message || 'تست ناموفق بود.', false);
        });
      });
    });
    if ($('puTplSave')) $('puTplSave').addEventListener('click', function () {
      api('template_save', {
        name: $('puTplName').value, platform: $('puTplPlat').value, kind: $('puTplKind').value,
        body: $('puTplBody').value, footer: $('puTplFooter').value,
      }).then(function (r) {
        toast(r.message || 'ذخیره شد.', !!r.success);
        if (r.success) { $('puTplName').value = ''; $('puTplBody').value = ''; $('puTplFooter').value = ''; loadTemplates(); }
      });
    });
    if ($('puContTplFill')) $('puContTplFill').addEventListener('click', function () {
      var sel = $('puContTemplate');
      if (!sel || !sel.value) { toast('ابتدا قالب را انتخاب کنید.', false); return; }
      var t = (state.templates || []).filter(function (x) { return String(x.id) === String(sel.value); })[0];
      if (t && $('puContBody')) {
        $('puContBody').value = (t.body || '') + (t.footer ? '\n\n' + t.footer : '');
        toast('متن قالب درج شد.', true);
      }
    });
    if ($('puContDraft')) $('puContDraft').addEventListener('click', function () {
      if (!window.confirm('به‌صورت پیش‌نویس ذخیره شود؟ بعداً از «صف انتشار» می‌توانید زمان‌بندی کنید.')) return;
      var plats = platChecks('puContPlats');
      if (!plats.length) { toast('حداقل یک پیام‌رسان انتخاب کنید.', false); return; }
      api('create', {
        title: $('puContTitle').value, body: $('puContBody').value,
        targets: plats.map(function (p) { return { platform: p, media_mode: 'none', text: $('puContBody').value }; }),
        scheduled_at: '', track_link: false, draft: 1,
      }).then(function (r) {
        toast(r.message || (r.success ? 'پیش‌نویس ذخیره شد.' : 'ذخیره نشد.'), !!r.success);
      });
    });
    if ($('puAdPostsBtn')) $('puAdPostsBtn').addEventListener('click', function () {
      var adId = ($('puAdPostsId').value || '').trim();
      if (!adId) { toast('شناسهٔ آگهی را وارد کنید.', false); return; }
      api('ad_posts', null, { ad_id: adId }).then(function (j) {
        if (j.success) renderAdPosts(j.posts, j.dist || null);
      });
    });
    if ($('puAdPostsDel')) $('puAdPostsDel').addEventListener('click', function () {
      var adId = ($('puAdPostsId').value || '').trim();
      if (!adId) { toast('شناسهٔ آگهی را وارد کنید.', false); return; }
      api('delete_ad_posts', { ad_id: adId }).then(function (pre) {
        if (pre.needs_confirmation) {
          renderAdPosts(pre.posts);
          if (!window.confirm(pre.message + '\nاین عملیات در تاریخچه ثبت می‌شود. ادامه می‌دهید؟')) return;
          api('delete_ad_posts', { ad_id: adId, confirmed: true }).then(function (r) {
            toast(r.message || 'انجام شد.', !!r.success);
          });
        } else {
          toast(pre.message || 'انجام شد.', !!pre.success);
        }
      });
    });
    loadTemplates();
  };

  // راه‌اندازی خودکار اگر پوستهٔ تب از قبل در صفحه بوده
  if (typeof window.MELKINO_PUBLISH_BOOT === 'function') {
    window.MELKINO_PUBLISH_BOOT();
  }
  if (typeof window.melkinoPublishExtras === 'function') {
    setTimeout(function () { window.melkinoPublishExtras(); }, 0);
  }
})();
