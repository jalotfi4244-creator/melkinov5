// مدیریت درخواست‌ها
// ==============================================

function requestEsc(value) {
    return escapeHtml(value);
}


function normalizeNumberText(value) {
    if (value === null || value === undefined) return '';
    let text = String(value).trim();
    if (!text) return '';
    text = text.replace(/[٬،,\s]/g, '');
    text = text.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    const n = Number(text);
    return Number.isFinite(n) ? n.toLocaleString('en-US') : String(value);
}

function requestPriceText(r) {
    const tx = String(r.transaction_type || '').trim();
    const isRent = tx.includes('اجاره') || tx.includes('رهن');
    if (isRent) {
        const deposit = r.min_deposit || r.deposit || r.down_payment || r.max_deposit || '';
        const rent = r.min_rent || r.rent_monthly || r.max_rent || '';
        if (deposit || rent) {
            return [deposit ? 'ودیعه: ' + normalizeNumberText(deposit) + ' تومان' : '', rent ? 'اجاره: ' + normalizeNumberText(rent) + ' تومان' : ''].filter(Boolean).join(' | ');
        }
    }
    const min = r.min_price || r.price_sell || r.total_price || '';
    const max = r.max_price || '';
    if (min && max && String(min) !== String(max)) return normalizeNumberText(min) + ' تا ' + normalizeNumberText(max) + ' تومان';
    if (min) return normalizeNumberText(min) + ' تومان';
    return 'تعیین نشده';
}

function getMatchedAdData(match) {
    const matchId = String(match?.id ?? match?.ad_id ?? match?.property_id ?? '').trim();
    const found = adsData.find(ad => String(ad?.id ?? ad?.ad_id ?? '').trim() === matchId);
    return found ? { ...found, ...match } : (match || {});
}

function matchDisplayPrice(ad) {
    const tx = String(ad.transaction_type || '').trim();
    const isRent = tx.includes('اجاره') || tx.includes('رهن');
    if (isRent) {
        const deposit = ad.deposit || ad.down_payment || '';
        const rent = ad.rent_monthly || '';
        const parts = [];
        if (deposit && String(deposit) !== '0') parts.push('ودیعه: ' + normalizeNumberText(deposit) + ' تومان');
        if (rent && String(rent) !== '0') parts.push('اجاره: ' + normalizeNumberText(rent) + ' تومان');
        if (parts.length) return parts.join(' | ');
    }
    const value = ad.display_price || ad.price_sell || ad.total_price || ad.price || '';
    if (!value) return 'تماس بگیرید';
    return String(value).includes('تومان') ? String(value) : normalizeNumberText(value) + ' تومان';
}

function matchArea(ad) {
    const d = ad.property_details && typeof ad.property_details === 'object' ? ad.property_details : {};
    const value = ad.area ?? ad.land_area ?? ad.built_area ?? ad.office_area ?? d.area ?? d.land_area ?? d.built_area ?? d.office_area ?? '';
    return value !== '' && value !== null && value !== undefined ? normalizeNumberText(value) : '—';
}

function matchRooms(ad) {
    const d = ad.property_details && typeof ad.property_details === 'object' ? ad.property_details : {};
    return ad.rooms || ad.bedrooms || d.rooms || d.bedrooms || '—';
}

function matchAmenities(ad) {
    const list = Array.isArray(ad.amenities) ? ad.amenities : [];
    return list.length ? list.join('، ') : '—';
}



function renderRequests() {

    const container =
        document.getElementById(
            'requestsListContainer'
        );


    const countEl =
        document.getElementById(
            'requestsResultCount'
        );


    if (!container) {
        return;
    }


    const toolbar = `

        <div class="requests-toolbar">

            <input
                id="requestsSearch"
                type="search"
                placeholder="جستجو کد رهگیری، نام، تلفن، محدوده..."
                autocomplete="off"
            >

            <select
                id="requestsMatchFilter"
            >

                <option value="all">
                    همه درخواست‌ها
                </option>

                <option value="matched">
                    دارای فایل مطابق
                </option>

                <option value="unmatched">
                    بدون فایل مطابق
                </option>

            </select>

            <select
                id="requestsStatusFilter"
            >

                <option value="all">
                    همه وضعیت‌ها
                </option>

                <option value="new">
                    جدید
                </option>

                <option value="tracking">
                    در حال پیگیری
                </option>

                <option value="archived">
                    بایگانی
                </option>

                <option value="closed">
                    بسته شده
                </option>

            </select>

        </div>
    `;


    const paint = () => {

        const q =
            (
                document.getElementById(
                    'requestsSearch'
                )?.value ||
                ''
            )
                .trim()
                .toLowerCase();


        const filter =
            document.getElementById(
                'requestsMatchFilter'
            )?.value ||
            'all';

        const statusFilter =
            document.getElementById(
                'requestsStatusFilter'
            )?.value ||
            'all';


        const list =
            requestsData.filter(
                r => {

                    const hay = [

                        r.tracking_code,
                        r.last_name,
                        r.phone,
                        r.location,
                        r.property_type,
                        r.transaction_type,
                        r.telegram_id

                    ]
                        .join(' ')
                        .toLowerCase();


                    const matches =
                        Array.isArray(
                            r.matches
                        )
                            ? r.matches
                            : [];


                    if (
                        filter ===
                        'matched' &&
                        matches.length === 0
                    ) {
                        return false;
                    }


                    if (
                        filter ===
                        'unmatched' &&
                        matches.length > 0
                    ) {
                        return false;
                    }

                    if (
                        statusFilter !== 'all' &&
                        (r.status || 'new') !== statusFilter
                    ) {
                        return false;
                    }

                    return (
                        !q ||
                        hay.includes(q)
                    );
                }
            );


        if (countEl) {

            countEl.innerText =
                list.length +
                ' درخواست';
        }


    
    // راند ۳۶: آیکون‌های SVG کارت درخواست (MK_IC مشترک + چند مورد محلی)
    const RQ_IC = Object.assign({
        inbox:  '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5 5h14l3 7v7H2v-7Z"/></svg>',
        ticket: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v3a2 2 0 0 0 0 2v3a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-2Z"/></svg>',
        search: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
        puzzle: '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>',
        link:   '<svg class="mk-icon mk-icon--sm" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>'
    }, window.MK_IC || {});

    const cardsEl = document.getElementById('requestsCards');
        if (!cardsEl) return;

        if (!list.length) {
            cardsEl.innerHTML = `
                <div class="request-empty" data-request-empty>
                    ${RQ_IC.inbox}<br>
                    درخواستی مطابق فیلتر پیدا نشد.
                </div>`;
            return;
        }

        cardsEl.innerHTML =
            list
                .map(
                    r => {

                        const matches =
                            Array.isArray(
                                r.matches
                            )
                                ? r.matches
                                : [];


                        const combos =
                            Array.isArray(
                                r.combinations
                            )
                                ? r.combinations
                                : [];


                        const amenArr = Array.isArray(r.amenities)
                            ? r.amenities.filter(Boolean)
                            : (r.amenities ? String(r.amenities).split(/[،,]+/).map(s => s.trim()).filter(Boolean) : []);
                        const amenities = amenArr.length ? amenArr.join('، ') : 'ثبت نشده';


                        return `

<article class="request-admin-card" data-request-card-code="${requestEsc(r.tracking_code || '')}">

    <div class="request-admin-head">
        <div>
            <div class="request-code">${RQ_IC.ticket} ${requestEsc(r.tracking_code || 'بدون کد رهگیری')}</div>
            <div class="request-date">${requestEsc(r.created_at || '')}</div>
        </div>

        <div class="request-status-wrap">
            <select class="request-status-select" data-request-status data-code="${requestEsc(r.tracking_code || '')}">
                <option value="new" ${(r.status || 'new') === 'new' ? 'selected' : ''}>جدید</option>
                <option value="tracking" ${(r.status || '') === 'tracking' ? 'selected' : ''}>در حال پیگیری</option>
                <option value="archived" ${(r.status || '') === 'archived' ? 'selected' : ''}>بایگانی</option>
                <option value="closed" ${(r.status || '') === 'closed' ? 'selected' : ''}>بسته شده</option>
            </select>
        </div>
    </div>

    <div class="request-admin-grid">
        <div class="request-admin-item"><small>متقاضی</small><strong>${requestEsc(r.last_name || '—')}</strong></div>
        <div class="request-admin-item"><small>شماره تماس</small><strong dir="ltr">${requestEsc(r.phone || '—')}</strong></div>
        <div class="request-admin-item"><small>تلگرام</small><strong>${(typeof mkTgFull === 'function') ? mkTgFull(r.telegram_id, r.username || r.telegram_username || '') : (requestEsc(r.telegram_id || r.username || '—'))}${r.telegram_id ? ' <span dir="ltr" style="font-size:11px;opacity:.75">' + requestEsc(r.telegram_id) + '</span>' : ''}</strong></div>
        <div class="request-admin-item"><small>نوع معامله</small><strong>${requestEsc(r.transaction_type || '—')}</strong></div>
        <div class="request-admin-item"><small>نوع ملک</small><strong>${requestEsc(r.property_type || '—')}</strong></div>
        <div class="request-admin-item"><small>محدوده</small><strong>${requestEsc(r.location || '—')}</strong></div>
        <div class="request-admin-item"><small>متراژ</small><strong>${requestEsc(((r.min_area !== null && r.min_area !== undefined && r.min_area !== '') ? normalizeNumberText(r.min_area) : '—') + ' تا ' + ((r.max_area !== null && r.max_area !== undefined && r.max_area !== '') ? normalizeNumberText(r.max_area) : '—'))}</strong></div>
        <div class="request-admin-item"><small>بودجه</small><strong>${requestEsc(requestPriceText(r))}</strong></div>
        <div class="request-admin-item"><small>امکانات</small><strong>${requestEsc(amenities)}</strong></div>
    </div>

    <div class="request-followup-box">
        <textarea class="request-followup-input" data-request-note data-code="${requestEsc(r.tracking_code || '')}" placeholder="یادداشت پیگیری این درخواست...">${requestEsc(r.followup_note || '')}</textarea>
        <button type="button" class="request-followup-save" data-request-save data-code="${requestEsc(r.tracking_code || '')}">ذخیره پیگیری</button>
        <button type="button" class="request-followup-save" style="background:#1f6feb;" data-request-edit data-code="${requestEsc(r.tracking_code || '')}">✏️ ویرایش درخواست</button>
        <button type="button" class="request-followup-save" style="background:var(--danger,#e74c3c);" data-request-delete data-code="${requestEsc(r.tracking_code || '')}">${RQ_IC.trash} حذف درخواست</button>
        ${matches.length && r.id ? `
        <button type="button" class="request-followup-save" style="background:#0E7C6E;" data-sms-matches="${requestEsc(r.id)}">پیامک فایل‌های منطبق</button>
        <button type="button" class="request-followup-save" style="background:#1f6feb;" data-sms-count="${requestEsc(r.id)}">پیامک تعداد (${matches.length} فایل در سایت)</button>` : ''}
        <span class="request-followup-state" data-request-state data-code="${requestEsc(r.tracking_code || '')}"></span>
    </div>

    <div class="request-matches-toggle-row">
        <button type="button" class="request-collapse-toggle" data-collapse-target="matches-${requestEsc(r.tracking_code || '')}">
            ${RQ_IC.search} فایل‌های نزدیک به درخواست (${matches.length})
            <span class="request-collapse-arrow">◂</span>
        </button>
        ${combos.length ? `
        <button type="button" class="request-collapse-toggle" data-collapse-target="combos-${requestEsc(r.tracking_code || '')}">
            ${RQ_IC.puzzle} ترکیب‌های پیشنهادی (${combos.length})
            <span class="request-collapse-arrow">◂</span>
        </button>` : ''}
    </div>

    <div class="request-matches" id="matches-${requestEsc(r.tracking_code || '')}" style="display:none;">
        ${matches.length ? matches.map(m => {
            const ad = getMatchedAdData(m);
            const propertyId = String(ad.id || ad.ad_id || '').trim();
            const detailUrl = propertyId ? 'property-details.php?id=' + encodeURIComponent(propertyId) : '';
            return `
<div class="request-match-row">
    <div class="request-match-main">
        ${detailUrl ? `<a class="request-match-code" href="${detailUrl}" target="_blank" rel="noopener">${RQ_IC.link} کد ملک: ${requestEsc(propertyId)}</a>` : `<span class="request-match-code">کد ملک: ${requestEsc(propertyId || '—')}</span>`}
        <div class="request-match-title">${requestEsc(ad.title || 'ملک')}</div>
        <div class="request-match-meta">${requestEsc((ad.property_type||'')+' · '+(ad.transaction_type||'')+' · '+(ad.location||''))}</div>
        <div class="request-match-inline">
            <span>قیمت: <strong>${requestEsc(matchDisplayPrice(ad))}</strong></span>
            <span>متراژ: <strong>${requestEsc(matchArea(ad))}</strong></span>
            <span>اتاق: <strong>${requestEsc(matchRooms(ad))}</strong></span>
            <span>امکانات: <strong>${requestEsc(matchAmenities(ad))}</strong></span>
            <span>مالک: <strong>${requestEsc(ad.last_name || ad.owner_last_name || '—')}</strong></span>
            <span>تلفن مالک: <strong dir="ltr">${requestEsc(ad.phone || ad.owner_phone || '—')}</strong></span>
        </div>
    </div>
    <div class="request-match-score">${Number(m.match_percent || 0)}٪</div>
</div>`;
        }).join('') : `<div style="font-size:12px;color:var(--text-secondary);padding-top:7px;">هنوز فایل مطابقی برای این درخواست پیدا نشده است.</div>`}
    </div>

    ${combos.length ? `
    <div class="request-matches" id="combos-${requestEsc(r.tracking_code || '')}" style="display:none;">
        ${combos.map((combo, comboIndex) => {
            const items = Array.isArray(combo.items) ? combo.items : [];
            return `
<div class="request-combo-row">
    <div class="request-combo-head">
        <strong>ترکیب ${comboIndex + 1}</strong>
        <span>${requestEsc(combo.summary || '')}</span>
        <span>جمع قیمت: <strong>${requestEsc(combo.total_price ? normalizeNumberText(combo.total_price) + ' تومان' : '—')}</strong></span>
        <span class="request-match-score">میانگین تطبیق: ${Number(combo.total_score || 0).toFixed(0)}٪</span>
    </div>
    <div class="request-combo-items">
        ${items.map(it => {
            const itemId = String(it.ad_id || it.id || '').trim();
            const itemUrl = itemId ? 'property-details.php?id=' + encodeURIComponent(itemId) : '';
            return `
        <div class="request-match-row">
            <div class="request-match-main">
                ${itemUrl ? `<a class="request-match-code" href="${itemUrl}" target="_blank" rel="noopener">${RQ_IC.link} کد ملک: ${requestEsc(itemId)}</a>` : `<span class="request-match-code">کد ملک: ${requestEsc(itemId || '—')}</span>`}
                <div class="request-match-title">${requestEsc(it.title || 'ملک')}</div>
                <div class="request-match-meta">${requestEsc((it.property_type||'')+' · '+(it.transaction_type||'')+' · '+(it.location||''))}</div>
                <div class="request-match-inline">
                    <span>قیمت: <strong>${requestEsc(it.price || '—')}</strong></span>
                    <span>متراژ: <strong>${requestEsc(it.area || '—')}</strong></span>
                </div>
            </div>
            <div class="request-match-score">${Number(it.match_percent || 0)}٪</div>
        </div>`;
        }).join('')}
    </div>
</div>`;
        }).join('')}
    </div>` : ''}

</article>

                        `;
                    }
                )
                .join('');


        bindRequestFilters();
    };


    container.innerHTML = toolbar + '<div id="requestsCards"></div>';

    bindRequestFilters();
    paint();
}


function applyRequestFiltersInPlace() {
    const search = document.getElementById('requestsSearch');
    const filter = document.getElementById('requestsMatchFilter');
    const statusFilter = document.getElementById('requestsStatusFilter');
    const cardsEl = document.getElementById('requestsCards');
    const countEl = document.getElementById('requestsResultCount');
    if (!search || !filter || !statusFilter || !cardsEl) return;

    const q = (search.value || '').trim().toLowerCase();
    const matchValue = filter.value || 'all';
    const statusValue = statusFilter.value || 'all';
    let visible = 0;

    requestsData.forEach(r => {
        const code = String(r.tracking_code || '');
        const card = cardsEl.querySelector(`[data-request-card-code="${CSS.escape(code)}"]`);
        if (!card) return;

        const hay = [r.tracking_code, r.last_name, r.phone, r.location, r.property_type, r.transaction_type, r.telegram_id]
            .join(' ').toLowerCase();
        const matches = Array.isArray(r.matches) ? r.matches : [];

        const okSearch = !q || hay.includes(q);
        const okMatch = matchValue === 'all' || (matchValue === 'matched' ? matches.length > 0 : matches.length === 0);
        const okStatus = statusValue === 'all' || (r.status || 'new') === statusValue;
        const show = okSearch && okMatch && okStatus;

        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    if (countEl) countEl.innerText = visible + ' درخواست';

    const empty = cardsEl.querySelector('[data-request-empty]');
    if (empty) empty.style.display = visible ? 'none' : '';
}


async function sendRequestLeadSms(action, requestId, button) {
    const id = Number(requestId || 0);
    if (!id) return;
    const prev = button ? button.textContent : '';
    if (button) {
        button.disabled = true;
        button.textContent = 'در حال ارسال…';
    }
    try {
        const response = await fetch('admin-leads-api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8' },
            body: JSON.stringify({ action: action, request_id: id }),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'ارسال نشد');
        if (button) button.textContent = 'ارسال شد';
        alert(result.message || 'پیامک ارسال شد.');
    } catch (error) {
        alert(error.message || 'ارسال پیامک انجام نشد.');
        if (button) button.textContent = prev;
    } finally {
        if (button) {
            setTimeout(() => {
                button.disabled = false;
                if (button.textContent === 'ارسال شد' || button.textContent === 'در حال ارسال…') {
                    button.textContent = prev;
                }
            }, 1600);
        }
    }
}

function bindRequestFilters() {
    const search = document.getElementById('requestsSearch');
    const filter = document.getElementById('requestsMatchFilter');
    const statusFilter = document.getElementById('requestsStatusFilter');

    if (search && !search.dataset.bound) {
        search.dataset.bound = '1';
        search.addEventListener('input', applyRequestFiltersInPlace);
    }

    if (filter && !filter.dataset.bound) {
        filter.dataset.bound = '1';
        filter.addEventListener('change', applyRequestFiltersInPlace);
    }

    if (statusFilter && !statusFilter.dataset.bound) {
        statusFilter.dataset.bound = '1';
        statusFilter.addEventListener('change', applyRequestFiltersInPlace);
    }

    document.querySelectorAll('[data-request-status]').forEach(select => {
        if (select.dataset.bound) return;
        select.dataset.bound = '1';
        select.addEventListener('change', () => saveRequestMeta(select.dataset.code));
    });

    document.querySelectorAll('[data-request-save]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => saveRequestMeta(button.dataset.code));
    });

    document.querySelectorAll('[data-request-edit]').forEach(button => {
        const handler = () => openRequestEdit(button.dataset.code);
        button.removeEventListener('click', handler);
        button.addEventListener('click', handler);
    });
    document.querySelectorAll('[data-request-delete]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => deleteRequest(button.dataset.code));
    });

    document.querySelectorAll('[data-sms-matches]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => sendRequestLeadSms('sms_matches', button.dataset.smsMatches, button));
    });
    document.querySelectorAll('[data-sms-count]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => sendRequestLeadSms('sms_count', button.dataset.smsCount, button));
    });

    document.querySelectorAll('[data-collapse-target]').forEach(button => {
        if (button.dataset.bound) return;
        button.dataset.bound = '1';
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.collapseTarget);
            if (!target) return;
            const isOpen = target.style.display !== 'none';
            target.style.display = isOpen ? 'none' : '';
            button.classList.toggle('open', !isOpen);
        });
    });
}

async function deleteRequest(code) {
    if (!code) return;
    if (!confirm('این درخواست برای همیشه حذف می‌شود. مطمئنی؟')) return;

    const stateEl = document.querySelector(`[data-request-state][data-code="${CSS.escape(code)}"]`);
    if (stateEl) stateEl.textContent = 'در حال حذف...';

    const body = new URLSearchParams();
    body.set('request_action', 'delete_request');
    body.set('tracking_code', code);

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'خطا در حذف');

        requestsData = requestsData.filter(r => String(r.tracking_code || '') !== String(code));
        renderRequests();
    } catch (error) {
        if (stateEl) stateEl.textContent = 'خطا در حذف';
        console.error('request delete error:', error);
        alert(error.message || 'حذف درخواست انجام نشد.');
    }
}

async function saveRequestMeta(code) {
    const statusEl = document.querySelector(`[data-request-status][data-code="${CSS.escape(code)}"]`);
    const noteEl = document.querySelector(`[data-request-note][data-code="${CSS.escape(code)}"]`);
    const stateEl = document.querySelector(`[data-request-state][data-code="${CSS.escape(code)}"]`);
    if (!code || !statusEl) return;

    if (stateEl) stateEl.textContent = 'در حال ذخیره...';

    const body = new URLSearchParams();
    body.set('request_action', 'update_request_meta');
    body.set('tracking_code', code);
    body.set('status', statusEl.value);
    body.set('followup_note', noteEl ? noteEl.value : '');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'خطا در ذخیره‌سازی');

        const request = requestsData.find(r => String(r.tracking_code || '') === String(code));
        if (request) {
            request.status = result.status;
            request.status_label = result.status_label;
            request.followup_note = result.followup_note || '';
        }

        if (stateEl) {
            stateEl.textContent = '✓ ذخیره شد';
            setTimeout(() => { stateEl.textContent = ''; }, 1800);
        }
    } catch (error) {
        if (stateEl) stateEl.textContent = 'خطا در ذخیره';
        console.error('request save error:', error);
    }
}

// ==============================================
// [REQUESTS] ثبت حضوری داخل پنل (همان فرم کاربر داخل مودال) + ویرایش درخواست
// الگو: همان modal-overlay/بستن با closeModal که بقیهٔ پنل استفاده می‌کند
// ==============================================

const RQ_NEW_FIELDS = [
    ['rqNewTransaction', 'transaction_type'],
    ['rqNewProperty', 'property_type'],
    ['rqNewLocation', 'location'],
    ['rqNewMinArea', 'min_area'],
    ['rqNewMaxArea', 'max_area'],
    ['rqNewMinPrice', 'min_price'],
    ['rqNewMaxPrice', 'max_price'],
    ['rqNewMinDeposit', 'min_deposit'],
    ['rqNewMaxDeposit', 'max_deposit'],
    ['rqNewMinRent', 'min_rent'],
    ['rqNewMaxRent', 'max_rent'],
    ['rqNewDateNeeded', 'date_needed'],
    ['rqNewUrgency', 'urgency']
];

function openRequestCreate() {
    document.getElementById('rqNewLastName').value = '';
    document.getElementById('rqNewPhone').value = '';
    document.getElementById('rqNewGender').value = 'آقا';
    RQ_NEW_FIELDS.forEach(([id]) => {
        const el = document.getElementById(id);
        if (el) {
            if (el.tagName === 'SELECT' && (el.id === 'rqNewUrgency')) el.value = 'عادی';
            else if (el.tagName === 'SELECT') el.selectedIndex = 0;
            else el.value = '';
        }
    });
    document.getElementById('rqNewRahn').checked = false;
    document.getElementById('rqNewNotKeyed').checked = false;
    document.getElementById('rqCreateModal').classList.add('active');
}

async function saveRequestCreate() {
    const lastName = document.getElementById('rqNewLastName').value.trim();
    const phone = document.getElementById('rqNewPhone').value.trim();
    if (!lastName) { alert('نام خانوادگی مراجع را وارد کنید.'); return; }
    if (!/^09\d{9}$/.test(phone.replace(/[^0-9]/g, ''))) { alert('شمارهٔ موبایل معتبر نیست (مثل 09123456789).'); return; }

    const saveBtn = document.querySelector('#rqCreateModal .btn-primary');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'در حال ثبت...'; }

    const body = new URLSearchParams();
    body.set('request_action', 'create_request');
    body.set('gender', document.getElementById('rqNewGender').value);
    body.set('last_name', lastName);
    body.set('phone', phone);
    RQ_NEW_FIELDS.forEach(([id, key]) => {
        const el = document.getElementById(id);
        body.set(key, el ? String(el.value).trim() : '');
    });
    body.set('rahn_kamal', document.getElementById('rqNewRahn').checked ? '1' : '');
    body.set('is_not_keyed', document.getElementById('rqNewNotKeyed').checked ? '1' : '');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok || !result.request) throw new Error(result.message || 'ثبت انجام نشد.');

        // همان جریان adminCreateNewAd: افزودن به داده و رندر مجدد
        if (typeof requestsData !== 'undefined' && Array.isArray(requestsData)) {
            requestsData.unshift(result.request);
            renderRequests();
        }
        closeModal('rqCreateModal');
        alert('درخواست با کد پیگیری «' + (result.request.tracking_code || '') + '» ثبت شد.');
    } catch (error) {
        alert(error.message || 'ثبت درخواست انجام نشد.');
    } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'ثبت درخواست'; }
    }
}

const RQ_EDIT_FIELDS = [
    ['rqEditTransaction', 'transaction_type'],
    ['rqEditProperty', 'property_type'],
    ['rqEditLocation', 'location'],
    ['rqEditMinArea', 'min_area'],
    ['rqEditMaxArea', 'max_area'],
    ['rqEditMinPrice', 'min_price'],
    ['rqEditMaxPrice', 'max_price'],
    ['rqEditMinDeposit', 'min_deposit'],
    ['rqEditMaxDeposit', 'max_deposit'],
    ['rqEditMinRent', 'min_rent'],
    ['rqEditMaxRent', 'max_rent'],
    ['rqEditDateNeeded', 'date_needed'],
    ['rqEditUrgency', 'urgency']
];

let rqEditCode = '';

function openRequestEdit(code) {
    const r = (typeof requestsData !== 'undefined' ? requestsData : []).find(x => String(x.tracking_code || '') === String(code));
    if (!r) { alert('درخواست پیدا نشد.'); return; }
    rqEditCode = code;
    document.getElementById('rqEditCode').textContent = code;
    RQ_EDIT_FIELDS.forEach(([id, key]) => {
        const el = document.getElementById(id);
        if (el) el.value = (r[key] === null || r[key] === undefined) ? '' : r[key];
    });
    document.getElementById('rqEditRahn').checked = String(r.rahn_kamal || '') === '1' || r.rahn_kamal === 1;
    document.getElementById('rqEditNotKeyed').checked = String(r.is_not_keyed || '') === '1' || r.is_not_keyed === 1;
    document.getElementById('rqEditModal').classList.add('active');
}

async function saveRequestEdit() {
    if (!rqEditCode) return;
    const saveBtn = document.querySelector('#rqEditModal .btn-primary');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'در حال ذخیره...'; }

    const body = new URLSearchParams();
    body.set('request_action', 'update_request_fields');
    body.set('tracking_code', rqEditCode);
    RQ_EDIT_FIELDS.forEach(([id, key]) => {
        const el = document.getElementById(id);
        body.set(key, el ? String(el.value).trim() : '');
    });
    body.set('rahn_kamal', document.getElementById('rqEditRahn').checked ? '1' : '');
    body.set('is_not_keyed', document.getElementById('rqEditNotKeyed').checked ? '1' : '');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'خطا در ذخیره');

        // به‌روزرسانی محلی ردیف + رندر مجدد (مثل جریان حذف)
        const r = (typeof requestsData !== 'undefined' ? requestsData : []).find(x => String(x.tracking_code || '') === String(rqEditCode));
        if (r) {
            RQ_EDIT_FIELDS.forEach(([id, key]) => {
                const v = body.get(key);
                r[key] = v === '' ? null : v;
            });
            r.rahn_kamal = body.get('rahn_kamal') === '1' ? 1 : 0;
            r.is_not_keyed = body.get('is_not_keyed') === '1' ? 1 : 0;
        }
        renderRequests();
        closeModal('rqEditModal');
    } catch (error) {
        alert(error.message || 'ذخیره انجام نشد.');
    } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'ذخیره تغییرات'; }
    }
}
