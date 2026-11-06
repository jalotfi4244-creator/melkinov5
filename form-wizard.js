(function (w) {
    if (w.melkinoValidateWizardStep) {
        return;
    }

    function visible(el) {
        if (!el) return false;
        if (el.disabled) return false;
        var n = el;
        while (n && n !== document.body) {
            var st = n.style ? n.style.display : '';
            if (st === 'none') return false;
            if (n.classList && n.classList.contains('active') === false && /^step\d+$/i.test(n.id || '')) {
                return false;
            }
            if (n.classList && n.classList.contains('active') === false && /^reqStep\d+$/i.test(n.id || '')) {
                return false;
            }
            n = n.parentElement;
        }
        if (el.offsetParent === null && (el.type !== 'hidden')) {
            var cs = w.getComputedStyle ? w.getComputedStyle(el) : null;
            if (cs && (cs.display === 'none' || cs.visibility === 'hidden')) {
                return false;
            }
        }
        return true;
    }

    function mark(el, on) {
        if (!el) return;
        if (on) el.classList.add('mk-wiz-err');
        else el.classList.remove('mk-wiz-err');
    }

    function ensureStyle() {
        if (document.getElementById('mkWizErrStyle')) return;
        var s = document.createElement('style');
        s.id = 'mkWizErrStyle';
        s.textContent = '.mk-wiz-err{border-color:#c0392b !important; box-shadow:0 0 0 2px rgba(192,57,43,.18) !important;}';
        document.head.appendChild(s);
    }

    function emptyVal(el) {
        if (!el) return true;
        if (el.type === 'checkbox') return !el.checked && el.required;
        if (el.type === 'radio') return false;
        return String(el.value || '').trim() === '';
    }

    function comboNameMap() {
        return {
            rooms: 'rooms', rooms_apt: 'rooms', rooms_villa: 'rooms', office_rooms: 'rooms',
            flooring: 'flooring', flooring_apt: 'flooring', flooring_villa: 'flooring', floor_comm: 'flooring',
            cabinet: 'cabinet', cabinet_apt: 'cabinet', cabinet_villa: 'cabinet', cabinet_comm: 'cabinet',
            cooling: 'cooling', cooling_apt: 'cooling', cooling_villa: 'cooling', cooling_comm: 'cooling',
            heating: 'heating', heating_apt: 'heating', heating_villa: 'heating', heating_comm: 'heating',
            apartment_type: 'apartment_type', villa_type: 'villa_type',
            units_per_floor: 'units_per_floor', wall_comm: 'wall', wall: 'wall',
            land_direction: 'land_direction', land_shape: 'land_shape',
            land_deed_status: 'land_deed_status', land_deed_type: 'land_deed_type',
            land_setback_status: 'land_setback', irrigation_type: 'irrigation',
            deed_type: 'deed_type', property_type: 'property_type',
            priority_1: 'property_type', priority_2: 'property_type', priority_3: 'property_type'
        };
    }

    function itemsOf(combos, key) {
        if (!combos || !key) return [];
        var v = combos[key];
        if (Array.isArray(v)) return v;
        if (v && Array.isArray(v.items)) return v.items;
        return [];
    }

    function escOpt(t) {
        return String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    }

    w.melkinoFillComboSelects = function (root) {
        var map = comboNameMap();
        var combos = w.MELKINO_FORM_COMBOS || {};
        var box = root || document;
        var sels = box.querySelectorAll ? box.querySelectorAll('select') : [];
        for (var i = 0; i < sels.length; i++) {
            var sel = sels[i];
            var key = sel.getAttribute('data-combo') || map[sel.name] || map[sel.id];
            if (!key) continue;
            var items = itemsOf(combos, key);
            if (!items.length) continue;
            var cur = sel.value;
            var html = '<option value="">انتخاب کنید</option>';
            for (var j = 0; j < items.length; j++) {
                var t = String(items[j] || '').trim();
                if (!t) continue;
                html += '<option value="' + escOpt(t) + '">' + escOpt(t) + '</option>';
            }
            sel.innerHTML = html;
            if (cur) sel.value = cur;
        }
    };

    w.melkinoComboOptionsHtml = function (key, withEmpty) {
        var items = itemsOf(w.MELKINO_FORM_COMBOS, key);
        var html = withEmpty === false ? '' : '<option value="">انتخاب کنید</option>';
        for (var i = 0; i < items.length; i++) {
            var t = String(items[i] || '').trim();
            if (!t) continue;
            html += '<option value="' + escOpt(t) + '">' + escOpt(t) + '</option>';
        }
        return html;
    };

    // برچسب فارسی یک فیلد برای پیام راهنما
    function labelFor(el) {
        var lab = null;
        if (el.id) {
            lab = document.querySelector('label[for="' + String(el.id).replace(/"/g, '') + '"]');
        }
        if (!lab && el.closest) {
            var g = el.closest('.form-group, .mx-field, .field');
            lab = g ? g.querySelector('label') : null;
        }
        var t = lab ? String(lab.textContent || '').replace(/[*؟?]\s*$/, '').trim() : '';
        return t || el.getAttribute('placeholder') || 'این فیلد';
    }

    // پیام کوتاه و غیرمسدودکننده (به جای alert)
    function hint(msg) {
        var h = document.getElementById('mkWizHint');
        if (!h) {
            h = document.createElement('div');
            h.id = 'mkWizHint';
            h.setAttribute('role', 'alert');
            h.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;' +
                'background:#7a1f16;color:#fff;padding:10px 18px;border-radius:12px;font-family:inherit;' +
                'font-size:14px;line-height:1.7;box-shadow:0 8px 24px rgba(0,0,0,.25);max-width:90vw;text-align:center;';
            document.body.appendChild(h);
        }
        h.textContent = msg;
        h.style.display = 'block';
        clearTimeout(hint._t);
        hint._t = setTimeout(function () { h.style.display = 'none'; }, 3500);
    }

    // هر مرحله: آخرین فیلد هدف‌گیری‌شده را نگه می‌داریم تا دکمه‌ی «مرحله بعد»
    // کاربر را یکی‌یکی و به ترتیب به فیلدهای خالی ببرد.
    var targetByBox = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;

    w.melkinoValidateWizardStep = function (stepIdOrEl) {
        ensureStyle();
        var box = typeof stepIdOrEl === 'string' ? document.getElementById(stepIdOrEl) : stepIdOrEl;
        if (!box) return true;
        var nodes = box.querySelectorAll('input, select, textarea');
        var missing = [];
        var seenRadio = {};
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            mark(el, false);
            if (!el.required) continue;
            if (!visible(el)) continue;
            if (el.type === 'radio') {
                var name = el.name;
                if (!name || seenRadio[name]) continue;
                seenRadio[name] = true;
                var grp = box.querySelectorAll('input[type="radio"][name="' + name.replace(/"/g, '') + '"]');
                var checked = false, firstVisible = null;
                for (var g = 0; g < grp.length; g++) {
                    if (!firstVisible && visible(grp[g])) firstVisible = grp[g];
                    if (grp[g].checked) checked = true;
                }
                if (firstVisible && !checked) missing.push(firstVisible);
                continue;
            }
            if (emptyVal(el)) missing.push(el);
        }
        if (!missing.length) {
            if (targetByBox) targetByBox.delete(box);
            return true;
        }
        var last = targetByBox ? targetByBox.get(box) : null;
        var target = null;
        if (last && missing.indexOf(last) !== -1) {
            // هنوز همان فیلد خالی است: روی همان بماند
            target = last;
        } else if (last) {
            // فیلد قبلی پر شده: اولین فیلد خالیِ بعد از آن در ترتیب صفحه
            for (var m = 0; m < missing.length; m++) {
                if (last.compareDocumentPosition(missing[m]) & Node.DOCUMENT_POSITION_FOLLOWING) {
                    target = missing[m];
                    break;
                }
            }
        }
        if (!target) target = missing[0];
        if (targetByBox) targetByBox.set(box, target);
        mark(target, true);
        try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
        try { target.focus({ preventScroll: true }); } catch (e2) {}
        hint('لطفاً «' + labelFor(target) + '» را پر کنید (' + (missing.indexOf(target) + 1) + ' از ' + missing.length + ')');
        return false;
    };

    // شماره‌ی تماس دوم (اختیاری) از مرحله‌ی اطلاعات تماس به فرم ثبت ملک منتقل می‌شود
    function forwardPhone2() {
        var hp = document.getElementById('hidden_phone');
        if (!hp || !hp.form) return;
        var f = hp.form;
        var h = f.querySelector('input[name="phone2"]');
        if (!h) {
            h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'phone2';
            f.appendChild(h);
        }
        try { h.value = sessionStorage.getItem('reg_phone2') || ''; } catch (e) {}
    }

    function flattenCombos(raw) {
        var map = {};
        if (!raw) return map;
        Object.keys(raw).forEach(function (k) {
            var items = itemsOf(raw, k);
            if (items.length) map[k] = items;
        });
        return map;
    }

    function applyLiveCombos(raw) {
        var map = flattenCombos(raw);
        if (!Object.keys(map).length) return;
        w.MELKINO_FORM_COMBOS = map;
        w.melkinoFillComboSelects(document);
    }

    function loadLiveCombos() {
        var urls = ['form-options.php?json=1', 'form-options-api.php'];
        var i = 0;
        function next() {
            if (i >= urls.length) return;
            var u = urls[i++];
            fetch(u, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
                if (!r.ok) throw new Error('bad');
                return r.json();
            }).then(function (j) {
                if (j && j.combos) applyLiveCombos(j.combos);
                else next();
            }).catch(next);
        }
        next();
    }

    function boot() {
        w.melkinoFillComboSelects(document);
        loadLiveCombos();
        forwardPhone2();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
