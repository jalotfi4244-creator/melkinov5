// راند ۵۱: تابع نمایش ارقام ممکن است در admin-requests.js تعریف شده باشد که
// دیرتر بارگذاری می‌شود؛ برای جلوگیری از ReferenceError هنگام رندر زودهنگام،
// نسخهٔ پشتیبان با گارد تعریف می‌شود (نسخهٔ اصلی در صورت بارگذاری، جایگزین می‌شود).
if (typeof window.normalizeNumberText !== 'function') {
    window.normalizeNumberText = function (value) {
        if (value === null || value === undefined) return '';
        let text = String(value).trim();
        if (!text) return '';
        text = text.replace(/[٬،,\s]/g, '');
        text = text.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
        const n = Number(text);
        return Number.isFinite(n) ? n.toLocaleString('en-US') : String(value);
    };
}

// مدیریت حرفه‌ای آگهی‌ها
// ==============================================

let adViewState = {

    filter:
        'all',

    search:
        '',

    propertyType:
        'all',

    transactionType:
        'all',

    sort:
        'newest',

    page:
        1,

    pageSize:
        12,

    selected:
        new Set()
};


function getAdImage(ad) {

    try {

        const selected =
            Array.isArray(
                ad.selected_images
            )
                ? ad.selected_images
                : JSON.parse(
                    ad.selected_images ||
                    '[]'
                );


        if (selected.length) {
            return selected[0];
        }


        const images =
            Array.isArray(ad.images)
                ? ad.images
                : JSON.parse(
                    ad.images ||
                    '[]'
                );


        return images.length
            ? images[0]
            : '';

    } catch(e) {

        return '';
    }
}


function getAdSearchText(ad) {

    return [

        ad.id,
        ad.ad_id,
        ad.title,
        ad.location,
        ad.last_name,
        ad.phone,
        ad.phone2 || '',
        ad.property_type,
        ad.transaction_type,
        ad.address

    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
}


function getNumericPrice(ad) {

    const values = [

        ad.price_sell,
        ad.total_price,
        ad.deposit,
        ad.rent_monthly

    ];


    for (const v of values) {

        if (
            v === null ||
            v === undefined ||
            v === ''
        ) {
            continue;
        }


        const n =
            Number(
                String(v)
                    .replace(
                        /[\s,٬،]/g,
                        ''
                    )
                    .replace(
                        /[۰-۹]/g,
                        d =>
                            '۰۱۲۳۴۵۶۷۸۹'
                                .indexOf(d)
                    )
            );


        if (
            !Number.isNaN(n) &&
            n > 0
        ) {
            return n;
        }
    }


    return 0;
}


function getFilteredAds() {

    const search =
        adViewState.search
            .trim()
            .toLowerCase();


    let list =
        adsData.filter(
            ad => {

                if (adViewState.filter === 'vip') {
                    if (!(ad.is_vip === true || ad.is_vip === '1')) return false;
                } else if (
                    adViewState.filter !==
                    'all' &&
                    (ad.status || 'pending') !==
                    adViewState.filter
                ) {
                    return false;
                }


                if (
                    adViewState.propertyType !==
                    'all' &&
                    ad.property_type !==
                    adViewState.propertyType
                ) {
                    return false;
                }


                if (
                    adViewState.transactionType !==
                    'all' &&
                    ad.transaction_type !==
                    adViewState.transactionType
                ) {
                    return false;
                }


                if (
                    search &&
                    !getAdSearchText(ad)
                        .includes(search)
                ) {
                    return false;
                }


                return true;
            }
        );


    list.sort(
        (a,b) => {

            if (
                adViewState.sort ===
                'oldest'
            ) {

                return new Date(
                    a.created_at || 0
                ) -
                new Date(
                    b.created_at || 0
                );
            }


            if (
                adViewState.sort ===
                'priceHigh'
            ) {

                return (
                    getNumericPrice(b) -
                    getNumericPrice(a)
                );
            }


            if (
                adViewState.sort ===
                'priceLow'
            ) {

                return (
                    getNumericPrice(a) -
                    getNumericPrice(b)
                );
            }


            if (
                adViewState.sort ===
                'title'
            ) {

                return String(
                    a.title || ''
                ).localeCompare(
                    String(
                        b.title || ''
                    ),
                    'fa'
                );
            }


            return new Date(
                b.created_at || 0
            ) -
            new Date(
                a.created_at || 0
            );
        }
    );


    return list;
}


// ==============================================
function renderAds() {

    const allFiltered =
        getFilteredAds();


    const totalPages =
        Math.max(
            1,
            Math.ceil(
                allFiltered.length /
                adViewState.pageSize
            )
        );


    if (
        adViewState.page >
        totalPages
    ) {
        adViewState.page =
            totalPages;
    }


    const start =
        (
            adViewState.page -
            1
        ) *
        adViewState.pageSize;


    const pageItems =
        allFiltered.slice(
            start,
            start +
            adViewState.pageSize
        );


    const container =
        document.getElementById(
            'adsListContainer'
        );


    document.getElementById(
        'adsResultCount'
    ).innerText =
        `${allFiltered.length} آگهی`;


    if (!pageItems.length) {

        container.innerHTML =
            `
            <div class="empty-table">
                ${MK_IC.search}
                <br>
                <strong>
                    آگهی‌ای پیدا نشد
                </strong>

                <div style="margin-top:6px">
                    فیلترها یا عبارت جستجو را تغییر دهید.
                </div>
            </div>
            `;

    } else {

        const allPageSelected =
            pageItems.every(
                ad =>
                    adViewState.selected.has(
                        String(ad.id)
                    )
            );


        const rows =
            pageItems
                .map(
                    ad => {

                        const selected =
                            adViewState.selected.has(
                                String(ad.id)
                            );


                        const img =
                            getAdImage(ad);


                        const details =
                            ad.property_details ||
                            {};


                        const rawArea =
                            details.area ??
                            details.built_area ??
                            details.land_area ??
                            ad.area ??
                            ad.built_area ??
                            ad.land_area ??
                            '';
                        const area = rawArea !== '' && rawArea !== null && rawArea !== undefined ? normalizeNumberText(rawArea) : '-';


                        const statusLabel =
                            getStatusLabel(
                                ad.status
                            );


                        const statusClass =
                            getStatusClass(
                                ad.status
                            );


                        let primaryAction =
                            '';


                        if (
                            ad.status ===
                            'pending'
                        ) {

                            primaryAction = `
<button
    class="table-action primary"
    title="تایید و انتشار"
    onclick="event.stopPropagation(); approveAd('${ad.id}')"
>
    ${MK_IC.check}
</button>
<button
    class="table-action"
    title="رد آگهی"
    style="color:var(--danger,#b00020);"
    onclick="event.stopPropagation(); rejectAd('${ad.id}')"
>
    ${MK_IC.x}
</button>
`;

                        } else if (
                            ad.status ===
                                'suspended' ||
                            ad.status ===
                                'sold' ||
                            ad.status ===
                                'rejected'
                        ) {

                            primaryAction = `
<button
    class="table-action primary"
    title="انتشار مجدد"
    onclick="event.stopPropagation(); changeAdStatus('${ad.id}','published')"
>
    ${MK_IC.megaphone}
</button>
`;

                        } else {

                            primaryAction = `
<button
    class="table-action"
    title="معلق کردن"
    onclick="event.stopPropagation(); changeAdStatus('${ad.id}','suspended')"
>
    ${MK_IC.lock}
</button>
`;
                        }


                        return `

<tr>

<td>

<input
    class="selection-check ad-select"
    type="checkbox"
    value="${ad.id}"
    ${
        selected
            ? 'checked'
            : ''
    }
    onchange="toggleAdSelection('${ad.id}', this.checked)"
>

</td>


<td>
    ${ad.id ?? '-'}
</td>


<td>

<div class="ad-row-main">

${
    img
        ?
        `<img
            class="ad-row-thumb"
            src="${img}"
            alt=""
        >`

        :

        `<div
            class="ad-row-thumb"
        ></div>`
}

<div>

<div class="ad-row-title">
    ${escapeHtml(
        ad.title ||
        'بدون عنوان'
    )}
</div>

<div class="ad-row-sub">
    ${escapeHtml(
        ad.location ||
        '-'
    )}
</div>
${ad.is_vip ? '<span class="ad-vip-pill">' + MK_IC.star + ' VIP</span>' : ''}

</div>

</div>

</td>


<td>
    ${escapeHtml(
        ad.property_type ||
        '-'
    )}
</td>


<td>
    ${escapeHtml(
        ad.transaction_type ||
        '-'
    )}
</td>


<td>

<strong>
    ${escapeHtml(
        getDisplayPrice(ad)
            .replace(
                /^.+?: /,
                ''
            ) ||
        '-'
    )}
</strong>

<div class="ad-quick-status">

${
    escapeHtml(area)
}

${
    area !== '-'
        ? ' متر'
        : ''
}

</div>

</td>


<td>

${escapeHtml(
    ad.last_name ||
    '-'
)}

<div
    class="ad-row-sub"
    dir="ltr"
>
    ${escapeHtml(ad.phone || '-')}${ad.phone2 ? ' / ' + escapeHtml(ad.phone2) : ''}
</div>

</td>


<td>

<span
    class="ad-status ${statusClass}"
>
    ${statusLabel}
</span>

</td>


<td>
    ${escapeHtml(
        ad.created_at ||
        '-'
    )}
</td>


<td>

<div class="table-actions">

<button
    class="table-action"
    title="جزئیات"
    onclick="showAdDetails('${ad.id}')"
>
    ${MK_IC.eye}
</button>


<button
    class="table-action"
    title="ویرایش"
    onclick="openAdEditModal('${ad.id}')"
>
    ${MK_IC.edit}
</button>


${primaryAction}


<button
    class="table-action vip ${ad.is_vip ? 'active' : ''}"
    title="${ad.is_vip ? 'حذف از VIP' : 'افزودن به VIP'}"
    onclick="event.stopPropagation(); toggleAdVip('${ad.id}')"
>
    ${MK_IC.star}
</button>


<button
    class="table-action danger"
    title="حذف"
    onclick="deleteAd('${ad.id}')"
>
    ${MK_IC.trash}
</button>

</div>

</td>

</tr>

`;
                    }
                )
                .join('');


        container.innerHTML = `

<div class="ads-table-wrap">

<table class="ads-table">

<thead>

<tr>

<th>

<input
    class="selection-check"
    type="checkbox"
    ${
        allPageSelected
            ? 'checked'
            : ''
    }
    onchange="togglePageSelection(this.checked)"
>

</th>

<th>#</th>

<th>آگهی</th>

<th>نوع ملک</th>

<th>معامله</th>

<th>قیمت / متراژ</th>

<th>مالک</th>

<th>وضعیت</th>

<th>تاریخ</th>

<th>عملیات</th>

</tr>

</thead>


<tbody>
    ${rows}
</tbody>

</table>

</div>

`;
    }


    document.getElementById(
        'adsPaginationInfo'
    ).innerText =
        allFiltered.length
            ?
            `نمایش ${start + 1} تا ${Math.min(
                start +
                adViewState.pageSize,
                allFiltered.length
            )} از ${allFiltered.length}`
            :
            '';


    renderPagination(
        totalPages
    );


    updateBulkBar();
}


function renderPagination(totalPages) {

    const el =
        document.getElementById(
            'adsPagination'
        );


    if (totalPages <= 1) {

        el.innerHTML =
            '';

        return;
    }


    let html =
        '';


    for (
        let i = 1;
        i <= totalPages;
        i++
    ) {

        if (
            i === 1 ||
            i === totalPages ||
            Math.abs(
                i -
                adViewState.page
            ) <= 1
        ) {

            html += `
<button
    class="page-btn ${
        i === adViewState.page
            ? 'active'
            : ''
    }"
    onclick="goToAdPage(${i})"
>
    ${i}
</button>
`;

        } else if (
            i === 2 ||
            i ===
                totalPages - 1
        ) {

            html +=
                '<span style="padding:8px;color:var(--text-secondary)">…</span>';
        }
    }


    el.innerHTML =
        html;
}


function goToAdPage(page) {

    adViewState.page =
        page;

    renderAds();
}


function filterAds(
    filter,
    btn
) {

    adViewState.filter =
        filter;

    adViewState.page =
        1;


    document
        .querySelectorAll(
            '#tab-ads .btn-filter'
        )
        .forEach(
            b =>
                b.classList.remove(
                    'active'
                )
        );


    if (btn) {
        btn.classList.add(
            'active'
        );
    }


    renderAds();
}


function resetAdFilters() {

    adViewState = {

        ...adViewState,

        filter:
            'all',

        search:
            '',

        propertyType:
            'all',

        transactionType:
            'all',

        sort:
            'newest',

        page:
            1
    };


    document.getElementById(
        'adsSearch'
    ).value = '';


    document.getElementById(
        'adsPropertyFilter'
    ).value =
        'all';


    document.getElementById(
        'adsTransactionFilter'
    ).value =
        'all';


    document.getElementById(
        'adsSort'
    ).value =
        'newest';


    document
        .querySelectorAll(
            '#tab-ads .btn-filter'
        )
        .forEach(
            b =>
                b.classList.remove(
                    'active'
                )
        );


    document
        .querySelector(
            '#tab-ads .btn-filter'
        )
        .classList.add(
            'active'
        );


    renderAds();
}


function escapeHtml(value) {

    return String(
        value ??
        ''
    ).replace(
        /[&<>'"]/g,
        ch =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                "'": '&#039;',
                '"': '&quot;'
            })[ch]
    );
}


function editEsc(value) {

    return escapeHtml(
        value
    );
}


function toggleAdSelection(
    id,
    checked
) {

    const key =
        String(id);


    if (checked) {

        adViewState.selected.add(
            key
        );

    } else {

        adViewState.selected.delete(
            key
        );
    }


    updateBulkBar();
}


function togglePageSelection(
    checked
) {

    getFilteredAds()
        .slice(
            (
                adViewState.page -
                1
            ) *
            adViewState.pageSize,

            adViewState.page *
            adViewState.pageSize
        )
        .forEach(
            ad => {

                const key =
                    String(ad.id);


                if (checked) {

                    adViewState.selected.add(
                        key
                    );

                } else {

                    adViewState.selected.delete(
                        key
                    );
                }
            }
        );


    renderAds();
}


function updateBulkBar() {

    const count =
        adViewState.selected.size;


    document.getElementById(
        'selectedCount'
    ).innerText =
        count;


    document.getElementById(
        'bulkBar'
    ).classList.toggle(
        'active',
        count > 0
    );
}


async function bulkChangeStatus(
    status
) {

    const ids =
        [
            ...adViewState.selected
        ];


    if (!ids.length) {
        return;
    }


    const label =
        status ===
        'published'
            ? 'منتشر'
            : 'معلق';


    if (
        !confirm(
            `وضعیت ${ids.length} آگهی به «${label}» تغییر کند؟`
        )
    ) {
        return;
    }


    adsData.forEach(
        ad => {

            if (
                ids.includes(
                    String(ad.id)
                )
            ) {
                ad.status =
                    status;
            }
        }
    );


    await saveAdsToFile();


    adViewState.selected.clear();


    renderAds();

    renderDashboard();
}


async function bulkDeleteAds() {

    const ids =
        [
            ...adViewState.selected
        ];


    if (!ids.length) {
        return;
    }


    if (
        !confirm(
            `آیا ${ids.length} آگهی انتخاب‌شده حذف شوند؟ این عملیات قابل بازگشت نیست.`
        )
    ) {
        return;
    }


    adsData =
        adsData.filter(
            ad =>
                !ids.includes(
                    String(ad.id)
                )
        );


    adViewState.selected.clear();


    await saveAdsToFile();


    renderAds();

    renderDashboard();
}


// ==============================================
// عملیات آگهی
// ==============================================

async function approveAd(id) {

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {
        return;
    }


    ad.status =
        'published';


    await saveAdsToFile([ad]);


    alert(
        'تایید شد'
    );


    renderAds();

    renderDashboard();
}


async function rejectAd(id) {

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {
        return;
    }


    if (
        !confirm(
            'این آگهی رد شود؟ به مالک اعلان «رد شدن آگهی» داده می‌شود.'
        )
    ) {
        return;
    }


    ad.status =
        'rejected';


    await saveAdsToFile([ad]);


    alert(
        'آگهی رد شد.'
    );


    renderAds();

    renderDashboard();
}


async function changeAdStatus(
    id,
    newStatus
) {

    const ad =
        adsData.find(
            a =>
                a.id == id
        );


    if (!ad) {
        return;
    }


    const labels = {

        sold:
            'فروخته شده',

        suspended:
            'معلق',

        rejected:
            'رد شده',

        published:
            'منتشر شده'
    };


    if (
        !confirm(
            `آیا وضعیت را به "${labels[newStatus]}" تغییر می‌دهید؟`
        )
    ) {
        return;
    }


    ad.status =
        newStatus;


    await saveAdsToFile([ad]);


    alert(
        'تغییر کرد'
    );


    renderAds();

    renderDashboard();
}


async function deleteAd(id) {

    if (
        !confirm(
            'حذف شود؟'
        )
    ) {
        return;
    }

    // حذف واقعی از پایگاه‌داده (endpoint موجود admin-panel.php?ad_db_action=delete)
    // + رویداد «deleted» برای قوانین اتوماسیون مرکز انتشار. قبلاً فقط از آرایهٔ
    // محلی حذف می‌شد و سطر در دیتابیس می‌ماند.
    let deleted = false;
    try {
        const res = await fetch(
            window.location.pathname + '?ad_db_action=delete',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: JSON.stringify({ ids: [String(id)] })
            }
        );
        const j = await res.json().catch(() => null);
        deleted = !!(j && j.ok);
        if (!deleted) {
            alert((j && j.message) || 'حذف آگهی انجام نشد.');
        }
    } catch (e) {
        alert('حذف آگهی انجام نشد.');
    }
    if (!deleted) {
        return;
    }

    adsData =
        adsData.filter(
            a =>
                a.id != id
        );

    renderAds();

    renderDashboard();
}


async function toggleAdVip(id){
    const ad=adsData.find(a=>String(a.id)===String(id));
    if(!ad)return;
    ad.is_vip=!ad.is_vip;
    const ok=await saveAdsToFile();
    if(ok){renderAds();renderDashboard();}
    else{ad.is_vip=!ad.is_vip;}
}


// ==============================================
// جزئیات کامل آگهی
// ==============================================

const propertyFieldDefs = {

    'آپارتمان': [

        [
            'area_apt',
            'متراژ',
            'area'
        ],

        [
            'floor',
            'طبقه',
            'floor'
        ],

        [
            'apartment_type',
            'نوع آپارتمان',
            'apartment_type'
        ],

        [
            'total_units',
            'تعداد کل واحدها',
            'total_units'
        ],

        [
            'units_per_floor',
            'تعداد واحد در طبقه',
            'units_per_floor'
        ],

        [
            'rooms_apt',
            'تعداد اتاق',
            'rooms'
        ],

        [
            'year_apt',
            'سال ساخت',
            'year'
        ],

        [
            'flooring_apt',
            'نوع پوشش کف',
            'flooring'
        ],

        [
            'cabinet_apt',
            'نوع کابینت',
            'cabinet'
        ],

        [
            'cooling_apt',
            'سیستم سرمایش',
            'cooling'
        ],

        [
            'heating_apt',
            'سیستم گرمایش',
            'heating'
        ]

    ],


    'ویلا': [

        [
            'villa_type',
            'نوع ویلایی',
            'villa_type'
        ],

        [
            'land_villa',
            'مساحت زمین',
            'land_area'
        ],

        [
            'built_villa',
            'زیربنا',
            'built_area'
        ],

        [
            'rooms_villa',
            'تعداد اتاق',
            'rooms'
        ],

        [
            'year_villa',
            'سال ساخت',
            'year'
        ],

        [
            'flooring_villa',
            'نوع پوشش کف',
            'flooring'
        ],

        [
            'cabinet_villa',
            'نوع کابینت',
            'cabinet'
        ],

        [
            'cooling_villa',
            'سیستم سرمایش',
            'cooling'
        ],

        [
            'heating_villa',
            'سیستم گرمایش',
            'heating'
        ]

    ],


    'زمین': [

        [
            'land_area',
            'مساحت زمین',
            'land_area'
        ],

        [
            'land_usage',
            'نوع کاربری',
            'land_usage'
        ],

        [
            'land_type',
            'نوع زمین',
            'land_type'
        ],

        [
            'land_width',
            'عرض زمین',
            'land_width'
        ],

        [
            'land_length',
            'طول زمین',
            'land_length'
        ],

        [
            'land_front_width',
            'عرض بر',
            'land_front_width'
        ],

        [
            'land_blocks',
            'تعداد بر',
            'land_blocks'
        ],

        [
            'land_direction',
            'جهت ملک',
            'land_direction'
        ],

        [
            'land_shape',
            'شکل زمین',
            'land_shape'
        ],

        [
            'land_deed_status',
            'وضعیت سند',
            'land_deed_status'
        ],

        [
            'land_deed_type',
            'نوع سند',
            'land_deed_type'
        ],

        [
            'land_division_status',
            'وضعیت تفکیک',
            'land_division_status'
        ],

        [
            'land_setback_status',
            'وضعیت عقب‌نشینی',
            'land_setback_status'
        ],

        [
            'land_ownership',
            'وضعیت مالکیت',
            'land_ownership'
        ]

    ],


    'باغ': [

        [
            'garden_area',
            'مساحت باغ',
            'garden_area'
        ],

        [
            'tree_count',
            'تعداد درختان',
            'tree_count'
        ],

        [
            'tree_types',
            'نوع درختان',
            'tree_types'
        ],

        [
            'tree_age',
            'سن درختان',
            'tree_age'
        ],

        [
            'irrigation_type',
            'نوع آبیاری',
            'irrigation_type'
        ],

        [
            'water_source',
            'منبع آب',
            'water_source'
        ],

        [
            'water_share',
            'سهم آب',
            'water_share'
        ],

        [
            'has_well',
            'چاه آب',
            'has_well'
        ],

        [
            'has_pond',
            'استخر آب',
            'has_pond'
        ],

        [
            'has_building',
            'بنا / خانه باغ',
            'has_building'
        ],

        [
            'building_area',
            'متراژ بنا',
            'building_area'
        ],

        [
            'document_type',
            'نوع سند',
            'document_type'
        ]

    ],


    'اداری': [

        [
            'office_area',
            'متراژ واحد',
            'office_area'
        ],

        [
            'office_floor',
            'طبقه',
            'office_floor'
        ],

        [
            'office_units_per_floor',
            'تعداد واحد در طبقه',
            'office_units_per_floor'
        ],

        [
            'office_rooms',
            'تعداد اتاق',
            'office_rooms'
        ],

        [
            'office_year',
            'سال ساخت',
            'office_year'
        ],

        [
            'office_condition',
            'وضعیت واحد',
            'office_condition'
        ],

        [
            'office_orientation',
            'موقعیت واحد',
            'office_orientation'
        ],

        [
            'office_usage',
            'کاربری',
            'office_usage'
        ]

    ],


    'تجاری': [

        [
            'area_comm',
            'متراژ',
            'area_comm'
        ],

        [
            'front_comm',
            'بر مغازه',
            'front_comm'
        ],

        [
            'floor_comm',
            'پوشش کف',
            'floor_comm'
        ],

        [
            'wall_comm',
            'پوشش دیوارها',
            'wall_comm'
        ],

        [
            'cabinet_comm',
            'نوع کابینت',
            'cabinet_comm'
        ],

        [
            'cooling_comm',
            'سیستم سرمایش',
            'cooling_comm'
        ],

        [
            'heating_comm',
            'سیستم گرمایش',
            'heating_comm'
        ],

        [
            'has_balcony',
            'بالکن دارد',
            'has_balcony'
        ],

        [
            'has_basement',
            'زیرزمین دارد',
            'has_basement'
        ],

        [
            'balcony_comm',
            'متراژ بالکن',
            'balcony_comm'
        ],

        [
            'basement_comm',
            'متراژ زیرزمین',
            'basement_comm'
        ],

        [
            'location_type_1',
            'موقعیت ملک تجاری',
            'location_type_1'
        ],

        [
            'location_type_2',
            'موقعیت خیابان / گذر',
            'location_type_2'
        ],

        [
            'jobs_comm',
            'مناسب برای مشاغل',
            'jobs_comm'
        ]

    ]

};


const propertyAmenities = {

    'آپارتمان': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'لابی',
        'مطبخ',
        'بالکن / تراس',
        'حیاط اختصاصی',
        'روف گاردن',
        'لاندری روم',
        'کلوزت',
        'اتاق مستر',
        'نگهبانی',
        'استخر',
        'سونا',
        'جکوزی'
    ],

    'ویلا': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'مطبخ',
        'بالکن / تراس',
        'حیاط اختصاصی',
        'روف گاردن',
        'لاندری روم',
        'کلوزت',
        'اتاق مستر',
        'نگهبانی',
        'استخر',
        'سونا',
        'جکوزی',
        'زیرزمین',
        'گلخانه',
        'حیاط خلوت',
        'دوبلکس'
    ],

    'زمین': [
        'آب',
        'برق',
        'گاز',
        'تلفن',
        'فاضلاب',
        'آب شهری',
        'چاه',
        'دیوارکشی',
        'درب ورودی',
        'آسفالت بودن مسیر',
        'دسترسی به خیابان اصلی',
        'دسترسی به کوچه'
    ],

    'باغ': [
        'سرویس بهداشتی',
        'پارکینگ',
        'انباری',
        'آلاچیق',
        'استخر',
        'سونا',
        'باربیکیو',
        'برق',
        'گاز',
        'آب شهری',
        'دیوارکشی',
        'نگهبانی',
        'درب ورودی خودرو'
    ],

    'اداری': [
        'آسانسور',
        'پارکینگ',
        'انباری',
        'لابی',
        'نگهبانی',
        'دوربین مداربسته',
        'سیستم اعلام حریق',
        'اطفای حریق',
        'سیستم سرمایش',
        'سیستم گرمایش',
        'برق اختصاصی',
        'سه‌فاز',
        'آب',
        'گاز',
        'اینترنت',
        'تلفن',
        'آبدارخانه',
        'سرویس بهداشتی',
        'اتاق مدیریت',
        'اتاق جلسات',
        'پارتیشن‌بندی',
        'سیستم هوشمند',
        'درب ضدسرقت',
        'تابلوخور مناسب',
        'دسترسی به حمل‌ونقل عمومی'
    ],

    'تجاری': [
        'شیشه سکوریت',
        'درب اتوماتیک',
        'درب فلزی',
        'کرکره برقی',
        'کرکره معمولی',
        'سرویس بهداشتی',
        'آسانسور',
        'بالابر',
        'ویترین',
        'نورپردازی',
        'اسپیلت',
        'کولر آبی',
        'آبگرمکن',
        'پکیج',
        'بخاری'
    ]

};


const fieldLabelMap =
    Object.fromEntries(
        Object.values(
            propertyFieldDefs
        )
        .flat()
        .map(
            ([
                key,
                label
            ]) =>
                [
                    key,
                    label
                ]
        )
    );


const valueAliases = {

    area_apt:
        [
            'area',
            'area_apt'
        ],

    flooring_apt:
        [
            'flooring',
            'flooring_apt'
        ],

    cabinet_apt:
        [
            'cabinet',
            'cabinet_apt'
        ],

    cooling_apt:
        [
            'cooling',
            'cooling_apt'
        ],

    heating_apt:
        [
            'heating',
            'heating_apt'
        ],

    rooms_apt:
        [
            'rooms',
            'rooms_apt'
        ],

    year_apt:
        [
            'year',
            'year_apt'
        ],

    land_villa:
        [
            'land_area',
            'land_villa'
        ],

    built_villa:
        [
            'built_area',
            'built_villa'
        ],

    rooms_villa:
        [
            'rooms',
            'rooms_villa'
        ],

    year_villa:
        [
            'year',
            'year_villa'
        ],

    flooring_villa:
        [
            'flooring',
            'flooring_villa'
        ],

    cabinet_villa:
        [
            'cabinet',
            'cabinet_villa'
        ],

    cooling_villa:
        [
            'cooling',
            'cooling_villa'
        ],

    heating_villa:
        [
            'heating',
            'heating_villa'
        ],

    area_comm: ['area', 'area_comm'],
    front_comm: ['front', 'front_comm', 'front_width'],
    floor_comm: ['flooring', 'floor_comm', 'floor_covering'],
    wall_comm: ['wall', 'wall_comm', 'wall_covering'],
    cabinet_comm: ['cabinet', 'cabinet_comm'],
    cooling_comm: ['cooling', 'cooling_comm', 'cooling_system'],
    heating_comm: ['heating', 'heating_comm', 'heating_system'],
    jobs_comm: ['jobs', 'jobs_comm'],
    location_type_1: ['location_type', 'location_type_1'],
    location_type_2: ['location_features', 'location_type_2']

};


function normalizeJsonArray(
    value
) {

    if (
        Array.isArray(value)
    ) {
        return value;
    }


    if (!value) {
        return [];
    }


    if (
        typeof value ===
        'string'
    ) {

        try {

            const x =
                JSON.parse(value);

            return Array.isArray(x)
                ? x
                : [];

        } catch(e) {

            return [];
        }
    }


    return [];
}


function normalizeJsonObject(
    value
) {

    if (
        value &&
        typeof value ===
        'object' &&
        !Array.isArray(value)
    ) {
        return value;
    }


    if (
        typeof value ===
        'string'
    ) {

        try {

            const x =
                JSON.parse(value);


            return (
                x &&
                typeof x ===
                    'object' &&
                !Array.isArray(x)
            )
                ? x
                : {};

        } catch(e) {

            return {};
        }
    }


    return {};
}


// راند ۴۸: نرمال‌سازی ارقام فارسی/عربی به انگلیسی برای مقایسهٔ گزینه‌ها
function mkDigitsEn(v) {
    return String(v === null || v === undefined ? '' : v)
        .replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}


function getPropertyValue(
    details,
    key
) {

    const keys = [
        ...(valueAliases[key] || [key]),
        key
    ];


    for (
        const k of keys
    ) {

        if (
            details[k] !==
                undefined &&
            details[k] !==
                null &&
            details[k] !==
                ''
        ) {

            return details[k];
        }
    }


    return '';
}


function prettyBool(v) {

    return [
        '1',
        'true',
        'yes',
        'on',
        'دارد',
        'بله'
    ].includes(
        String(v).toLowerCase()
    )

        ? 'دارد'

        :

        [
            '0',
            'false',
            'no',
            'off',
            'ندارد',
            'خیر'
        ].includes(
            String(v).toLowerCase()
        )

            ? 'ندارد'

            : String(v || '');
}


function prettyDetailValue(v) {

    if (
        v === undefined ||
        v === null ||
        v === ''
    ) {
        return 'ثبت نشده';
    }


    if (
        String(v) === '1' ||
        String(v) === '0' ||
        [
            'true',
            'false'
        ].includes(
            String(v).toLowerCase()
        )
    ) {
        return prettyBool(v);
    }


    return String(v);
}


function renderKVGrid(
    items
) {

    return `

<div class="detail-kv-grid">

${
    items
        .map(
            ([
                label,
                value
            ]) => `

<div class="detail-kv">

    <span>
        ${escapeHtml(label)}
    </span>

    <strong>
        ${escapeHtml(
            prettyDetailValue(value)
        )}
    </strong>

</div>

`
        )
        .join('')
}

</div>

`;
}


function imageUrl(src) {

    return String(
        src || ''
    ).replace(
        /&amp;/g,
        '&'
    );
}


function renderDetailGallery(
    ad
) {

    const images =
        normalizeJsonArray(
            ad.images
        );


    const selected =
        normalizeJsonArray(
            ad.selected_images
        );


    const userYes =
        String(
            ad.publish_photos ||
            'yes'
        ) === 'yes';


    if (!images.length) {

        return `
<div class="detail-empty">
    این آگهی هیچ تصویری ندارد.
</div>
`;
    }


    return `

<div class="detail-policy">

    <span>
        انتخاب کاربر:
    </span>

    <strong>
        ${
            userYes
                ? 'انتشار تصاویر را می‌خواهد'
                : 'انتشار تصاویر را نمی‌خواهد'
        }
    </strong>

    <small>
        تصاویر نهایی منتشرشده توسط ادمین تعیین می‌شوند.
    </small>

</div>


<div class="detail-gallery">

${
    images
        .map(
            (
                src,
                i
            ) => `

<div
    class="detail-image-card ${
        selected.includes(src)
            ? 'is-selected'
            : ''
    }"
>

<img
    src="${escapeHtml(
        imageUrl(src)
    )}"
    alt="تصویر ${i + 1}"
>

<div class="detail-image-meta">

<span>
    تصویر ${i + 1}
</span>

${
    selected.includes(src)
        ? '<b>' + MK_IC.check + ' برای انتشار</b>'
        : ''
}

</div>

</div>

`
        )
        .join('')
}

</div>

`;
}


function showAdDetails(id) {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(id)
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    const details =
        normalizeJsonObject(
            ad.property_details
        );


    const amenities =
        normalizeJsonArray(
            ad.amenities
        );


    const type =
        ad.property_type ||
        'ملک';


    const fields =
        propertyFieldDefs[type] ||
        [];


    const specItems =
        fields
            .map(
                ([
                    key,
                    label
                ]) => [
                    label,
                    getPropertyValue(
                        details,
                        key
                    )
                ]
            )
            .filter(
                ([,v]) =>
                    v !== '' &&
                    v !== null &&
                    v !== undefined
            );


    const priceRows =
        [];


    if (
        ad.transaction_type ===
        'فروش'
    ) {

        priceRows.push(
            [
                'قیمت فروش',
                ad.price_sell !== null && ad.price_sell !== undefined && Number(ad.price_sell) > 0 ? normalizeNumberText(ad.price_sell) : '-'
            ],
            [
                'وضعیت قیمت',
                ad.price_condition ===
                'fixed'
                    ? 'مقطوع'
                    : 'قابل مذاکره'
            ]
        );

    } else if (
        ad.transaction_type ===
        'اجاره'
    ) {

        if (
            String(
                ad.full_rent_enabled
            ) === '1' ||
            (
                ad.full_rent &&
                !ad.deposit &&
                !ad.rent_monthly
            )
        ) {

            priceRows.push(
                [
                    'رهن کامل',
                    ad.full_rent !== null && ad.full_rent !== undefined && Number(ad.full_rent) > 0 ? normalizeNumberText(ad.full_rent) : '-'
                ]
            );

        } else {

            priceRows.push(
                [
                    'ودیعه',
                    ad.deposit !== null && ad.deposit !== undefined && Number(ad.deposit) > 0 ? normalizeNumberText(ad.deposit) : '-'
                ],

                [
                    'اجاره ماهانه',
                    ad.rent_monthly !== null && ad.rent_monthly !== undefined && Number(ad.rent_monthly) > 0 ? normalizeNumberText(ad.rent_monthly) : '-'
                ]
            );
        }

    } else if (
        ad.transaction_type ===
        'پیش فروش'
    ) {

        priceRows.push(

            [
                'قیمت کل',
                ad.total_price !== null && ad.total_price !== undefined && Number(ad.total_price) > 0 ? normalizeNumberText(ad.total_price) : '-'
            ],

            [
                'پیش‌پرداخت',
                ad.down_payment !== null && ad.down_payment !== undefined && Number(ad.down_payment) > 0 ? normalizeNumberText(ad.down_payment) : '-'
            ],

            [
                'شرایط پرداخت',
                ad.payment_terms ||
                    '-'
            ]

        );
    }


    const contact =
        renderKVGrid(
            [
                [
                    'جنسیت',
                    ad.gender ||
                        '-'
                ],
                [
                    'نام خانوادگی',
                    ad.last_name ||
                        '-'
                ],
                [
                    'شماره تماس',
                    ad.phone ||
                        '-'
                ],
                [
                    'شماره تماس دوم',
                    ad.phone2 ||
                        '-'
                ]
            ]
        );


    const base =
        renderKVGrid(
            [

                [
                    'کد آگهی',
                    ad.ad_id ||
                        ad.id
                ],

                [
                    'عنوان',
                    ad.title ||
                        '-'
                ],

                [
                    'نوع معامله',
                    ad.transaction_type ||
                        '-'
                ],

                [
                    'نوع ملک',
                    type
                ],

                [
                    'محدوده',
                    ad.location ||
                        '-'
                ],

                [
                    'آدرس دقیق',
                    ad.address ||
                        '-'
                ],

                [
                    'لوکیشن',
                    ad.location_received ===
                    '1'
                        ? 'دریافت شده'
                        : 'ثبت نشده'
                ],

                [
                    'وضعیت',
                    getStatusLabel(
                        ad.status
                    )
                ]

            ]
        );


    const price =
        renderKVGrid(
            priceRows
        );


    const amen =
        amenities.length

            ?

            amenities
                .map(
                    x => `
<span class="detail-tag">
    ${MK_IC.check}
    ${escapeHtml(x)}
</span>
`
                )
                .join('')

            :

            `
<span class="detail-muted">
    امکاناتی ثبت نشده است.
</span>
`;


    const content =
        document.getElementById(
            'adDetailContent'
        );


    content.innerHTML = `

<div class="detail-shell">

<div class="detail-hero">

<div>

<div class="detail-title">
    ${escapeHtml(
        ad.title ||
        'بدون عنوان'
    )}
</div>

<div class="detail-sub">
    ${escapeHtml(type)}
    ·
    ${escapeHtml(
        ad.transaction_type ||
        '-'
    )}
    ·
    ${escapeHtml(
        ad.location ||
        ''
    )}
</div>

</div>


<span
    class="ad-status ${getStatusClass(
        ad.status
    )}"
>
    ${getStatusLabel(
        ad.status
    )}
</span>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۱. اطلاعات پایه
</div>

${base}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۲. اطلاعات تماس مالک
</div>

${contact}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۳. مشخصات ${escapeHtml(type)}
</div>

${
    specItems.length
        ? renderKVGrid(
            specItems
        )
        : `
<div class="detail-muted">
    مشخصات اختصاصی ثبت نشده است.
</div>
`
}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۴. قیمت و شرایط معامله
</div>

${
    price ||
    `
<div class="detail-muted">
    اطلاعات قیمت ثبت نشده است.
</div>
`
}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۵. امکانات
</div>

<div class="detail-tags">
    ${amen}
</div>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۶. تصاویر
</div>

${renderDetailGallery(ad)}

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۷. توضیحات
</div>

<div class="detail-description">

${
    escapeHtml(
        ad.description ||
        'توضیحاتی ثبت نشده است.'
    ).replace(
        /\n/g,
        '<br>'
    )
}

</div>

</div>


<div class="detail-section">

<div class="detail-section-title">
    ۸. زمان‌ها
</div>

${renderKVGrid(

    [

        [
            'تاریخ ثبت',
            ad.created_at ||
                '-'
        ],

        [
            'آخرین ویرایش',
            ad.updated_at ||
                '-'
        ],

        [
            'انتشار تصاویر',
            String(
                ad.publish_photos ||
                'yes'
            ) === 'yes'
                ? 'کاربر موافق بوده'
                : 'کاربر مخالف بوده'
        ],

        [
            'تعداد تصاویر',
            normalizeJsonArray(
                ad.images
            ).length
        ],

        [
            'تصاویر انتخاب‌شده',
            normalizeJsonArray(
                ad.selected_images
            ).length
        ]

    ]

)}

</div>


<div class="detail-actions">

<button
    class="btn-secondary"
    onclick="
        openAdEditModal('${String(
            ad.id
        ).replace(
            /'/g,
            "\\'"
        )}');
        closeModal('adDetailModal')
    "
>
    ${MK_IC.edit} ویرایش کامل
</button>


</div>

</div>
`;


    document
        .getElementById(
            'adDetailModal'
        )
        .classList.add(
            'active'
        );
}


// ==============================================
// ویرایش کامل آگهی
// ==============================================

const editFieldDefs = {

    'آپارتمان': [

        [
            'area_apt',
            'متراژ',
            'number'
        ],

        [
            'floor',
            'طبقه',
            'text'
        ],

        [
            'total_units',
            'کل واحدها',
            'number'
        ],

        [
            'apartment_type',
            'نوع آپارتمان',
            'select',
            [
                'فلت',
                'دوبلکس'
            ]
        ],

        [
            'units_per_floor',
            'تعداد واحد در طبقه',
            'select',
            [
                'تک واحد',
                'دو واحدی',
                'سه واحدی',
                'چهار واحدی',
                'بیشتر'
            ]
        ],

        [
            'rooms_apt',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'year_apt',
            'سال ساخت',
            'text'
        ],

        [
            'flooring_apt',
            'نوع پوشش کف',
            'text'
        ],

        [
            'cabinet_apt',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_apt',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_apt',
            'سیستم گرمایش',
            'text'
        ]

    ],


    'ویلا': [

        [
            'villa_type',
            'نوع ویلایی',
            'select',
            [
                'فلت',
                'دوبلکس',
                'تریبلکس'
            ]
        ],

        [
            'land_villa',
            'متراژ زمین',
            'number'
        ],

        [
            'built_villa',
            'زیربنا',
            'number'
        ],

        [
            'rooms_villa',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'year_villa',
            'سال ساخت',
            'text'
        ],

        [
            'flooring_villa',
            'نوع پوشش کف',
            'text'
        ],

        [
            'cabinet_villa',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_villa',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_villa',
            'سیستم گرمایش',
            'text'
        ]

    ],


    'زمین': [

        [
            'land_area',
            'مساحت زمین',
            'number'
        ],

        [
            'land_usage',
            'نوع کاربری',
            'text'
        ],

        [
            'land_type',
            'نوع زمین',
            'select',
            [
                'مسکونی',
                'تجاری',
                'اداری',
                'کشاورزی',
                'باغی'
            ]
        ],

        [
            'land_width',
            'عرض زمین',
            'number'
        ],

        [
            'land_length',
            'طول زمین',
            'number'
        ],

        [
            'land_front_width',
            'عرض بر',
            'number'
        ],

        [
            'land_blocks',
            'تعداد بر',
            'number'
        ],

        [
            'land_direction',
            'جهت ملک',
            'text'
        ],

        [
            'land_shape',
            'شکل زمین',
            'text'
        ],

        [
            'land_deed_status',
            'وضعیت سند',
            'text'
        ],

        [
            'land_deed_type',
            'نوع سند',
            'text'
        ],

        [
            'land_division_status',
            'وضعیت تفکیک',
            'text'
        ],

        [
            'land_setback_status',
            'وضعیت عقب‌نشینی',
            'text'
        ],

        [
            'land_ownership',
            'وضعیت مالکیت',
            'text'
        ]

    ],


    'باغ': [

        [
            'garden_area',
            'مساحت باغ',
            'number'
        ],

        [
            'tree_count',
            'تعداد درختان',
            'number'
        ],

        [
            'tree_types',
            'نوع درختان',
            'text'
        ],

        [
            'tree_age',
            'سن درختان',
            'text'
        ],

        [
            'irrigation_type',
            'نوع آبیاری',
            'text'
        ],

        [
            'water_source',
            'منبع آب',
            'text'
        ],

        [
            'water_share',
            'سهم آب',
            'text'
        ],

        [
            'has_well',
            'چاه آب',
            'boolean'
        ],

        [
            'has_pond',
            'استخر آب',
            'boolean'
        ],

        [
            'has_building',
            'بنا / خانه باغ',
            'boolean'
        ],

        [
            'building_area',
            'متراژ بنا',
            'number'
        ],

        [
            'document_type',
            'نوع سند',
            'text'
        ]

    ],


    'اداری': [

        [
            'office_area',
            'متراژ واحد',
            'number'
        ],

        [
            'office_floor',
            'طبقه',
            'text'
        ],

        [
            'office_units_per_floor',
            'تعداد واحد در طبقه',
            'number'
        ],

        [
            'office_rooms',
            'تعداد اتاق',
            'select',
            [
                '۱',
                '۲',
                '۳',
                '۴',
                '۵',
                '۶'
            ]
        ],

        [
            'office_year',
            'سال ساخت',
            'text'
        ],

        [
            'office_condition',
            'وضعیت واحد',
            'text'
        ],

        [
            'office_orientation',
            'موقعیت واحد',
            'text'
        ],

        [
            'office_usage',
            'کاربری',
            'text'
        ]

    ],


    'تجاری': [

        [
            'area_comm',
            'متراژ',
            'number'
        ],

        [
            'front_comm',
            'بر مغازه',
            'number'
        ],

        [
            'floor_comm',
            'پوشش کف',
            'text'
        ],

        [
            'wall_comm',
            'پوشش دیوارها',
            'text'
        ],

        [
            'cabinet_comm',
            'نوع کابینت',
            'text'
        ],

        [
            'cooling_comm',
            'سیستم سرمایش',
            'text'
        ],

        [
            'heating_comm',
            'سیستم گرمایش',
            'text'
        ],

        [
            'has_balcony',
            'بالکن دارد',
            'boolean'
        ],

        [
            'has_basement',
            'زیرزمین دارد',
            'boolean'
        ],

        [
            'balcony_comm',
            'متراژ بالکن',
            'number'
        ],

        [
            'basement_comm',
            'متراژ زیرزمین',
            'number'
        ],

        [
            'location_type_1',
            'موقعیت ملک تجاری',
            'text'
        ],

        [
            'location_type_2',
            'موقعیت خیابان / گذر',
            'text'
        ],

        [
            'jobs_comm',
            'مناسب برای مشاغل',
            'text'
        ]

    ]

};


function getEditDetailValue(
    details,
    key
) {

    return getPropertyValue(
        details,
        key
    );
}


function buildEditPropertyFields(
    type,
    details
) {

    const defs =
        editFieldDefs[type] ||
        [];


    return defs
        .map(
            ([
                key,
                label,
                control,
                opts
            ]) => {

                const val =
                    getEditDetailValue(
                        details,
                        key
                    );


                if (
                    control ===
                    'boolean'
                ) {

                    return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<label class="edit-bool">

<input
    type="checkbox"
    id="editDetail_${key}"
    data-detail-key="${key}"
    ${
        prettyBool(val) ===
        'دارد'
            ? 'checked'
            : ''
    }
>

<span>
    دارد
</span>

</label>

</div>

`;
                }


                if (
                    control ===
                    'select'
                ) {

                    // راند ۴۸: مقدار ذخیره‌شده ممکن است با ارقام انگلیسی باشد
                    // (نرمال‌سازی راند ۲۹) ولی گزینه‌ها فارسی‌اند → مقایسهٔ بی‌تفاوت به ارقام
                    const valEn =
                        mkDigitsEn(val);

                    return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<select
    id="editDetail_${key}"
    data-detail-key="${key}"
>

<option value="">
    انتخاب کنید
</option>

${
    opts
        .map(
            o =>
                `
<option
    value="${escapeHtml(o)}"
    ${
        mkDigitsEn(o) ===
        valEn
            ? 'selected'
            : ''
    }
>
    ${escapeHtml(o)}
</option>
`
        )
        .join('')
}

</select>

</div>

`;
                }


                return `

<div class="edit-field">

<label>
    ${escapeHtml(label)}
</label>

<input
    id="editDetail_${key}"
    data-detail-key="${key}"
    type="text"
    value="${editEsc(val)}"
>

</div>

`;
            }
        )
        .join('');
}


function collectEditDetails() {

    const type =
        document.getElementById(
            'editAdPropertyType'
        ).value;


    const details =
        normalizeJsonObject(
            window.__editDetails ||
            {}
        );


    (
        editFieldDefs[type] ||
        []
    ).forEach(
        ([key]) => {

            const el =
                document.getElementById(
                    'editDetail_' +
                    key
                );


            if (!el) {
                return;
            }


            if (
                el.type ===
                'checkbox'
            ) {

                details[key] =
                    el.checked
                        ? '1'
                        : '0';

            } else {

                details[key] =
                    el.value.trim();
            }
        }
    );


    // راند ۲۶: اگر نوع آپارتمان/ویلایی انتخاب نشود، «فلت» محاسبه می‌شود
    // (دقیقاً مثل فرم‌های ثبت).
    if (type === 'آپارتمان' && String(details.apartment_type || '').trim() === '') {
        details.apartment_type = 'فلت';
    }
    if ((type === 'ویلا' || type === 'ویلایی') && String(details.villa_type || '').trim() === '') {
        details.villa_type = 'فلت';
    }

    var canon = {
        area_comm: 'area',
        front_comm: 'front',
        floor_comm: 'flooring',
        wall_comm: 'wall',
        cabinet_comm: 'cabinet',
        cooling_comm: 'cooling',
        heating_comm: 'heating',
        jobs_comm: 'jobs',
        location_type_1: 'location_type'
    };
    Object.keys(canon).forEach(function (from) {
        if (details[from] !== undefined && details[from] !== '') {
            details[canon[from]] = details[from];
        }
    });
    if (details.location_type_2) {
        var map2 = { main_street: 'بر خیابان اصلی', side_street: 'بر خیابان فرعی', in_passage: 'در پاساژ', in_garage: 'در گاراژ' };
        details.location_features = map2[details.location_type_2] || details.location_type_2;
    }

    return details;
}


function buildEditAmenities(
    type,
    arr
) {

    const options =
        propertyAmenities[type] ||
        [];


    const values =
        Array.isArray(arr)
            ? arr
            : [];


    const known =
        options
            .map(
                x =>
                    `

<label class="edit-check">

<input
    class="edit-amenity"
    type="checkbox"
    value="${editEsc(x)}"
    ${
        values.includes(x)
            ? 'checked'
            : ''
    }
>

<span>
    ${editEsc(x)}
</span>

</label>

`
            )
            .join('');


    const extra =
        values.filter(
            x =>
                !options.includes(x)
        );


    return known +

        `

<div class="edit-field full">

<label>
    امکانات سفارشی
</label>

<input
    id="editCustomAmenities"
    value="${editEsc(
        extra.join(', ')
    )}"
    placeholder="در صورت نیاز با ویرگول جدا کنید"
>

</div>

`;
}


function buildEditImages(ad) {

    const imgs = normalizeJsonArray(ad.images);
    const selected = normalizeJsonArray(ad.selected_images);

    if (!imgs.length) {
        return `
<div class="detail-empty">
    هیچ تصویر ارسالی برای این آگهی وجود ندارد.
</div>
`;
    }

    const bulkBar = `
<div class="img-bulk-bar">
    <label class="img-bulk-all">
        <input type="checkbox" id="editDeleteAll" onchange="toggleAllDeleteChecks(this.checked)">
        <span>${MK_IC.trash} انتخاب برای حذف</span>
    </label>
    <span id="editDeleteCount" class="img-bulk-count">۰ انتخاب شده</span>
    <button type="button" class="btn-danger img-bulk-btn" id="editDeleteBtn" onclick="bulkDeleteAdImages()" disabled>
        حذف تصاویر انتخاب‌شده
    </button>
</div>
`;

    const tiles = imgs
        .map((src, i) => `
<div class="manage-image ${selected.includes(src) ? 'selected' : ''}">
    <img src="${escapeHtml(imageUrl(src))}" alt="تصویر ${i + 1}">
    <div class="manage-image-footer">
        <strong>تصویر ${i + 1}</strong>
        <div class="manage-image-checks">
            <label>
                <input class="edit-image-select" type="checkbox" value="${editEsc(src)}" ${selected.includes(src) ? 'checked' : ''}
                    onchange="updateEditImageCounter(); this.closest('.manage-image')?.classList.toggle('selected', this.checked)">
                انتشار
            </label>
            <label class="img-delete-label">
                <input class="edit-image-delete" type="checkbox" value="${editEsc(src)}" onchange="updateEditDeleteCount()">
                حذف
            </label>
        </div>
    </div>
</div>
`)
        .join('');

    return `
<div id="editImagesManage">
${bulkBar}
<div class="manage-image-grid">
${tiles}
</div>
</div>
`;
}

// انتخاب همه / لغو انتخاب برای حذف دسته‌جمعی
function toggleAllDeleteChecks(checked) {
    document.querySelectorAll('#adEditModal .edit-image-delete').forEach(c => { c.checked = checked; });
    updateEditDeleteCount();
}

// به‌روزرسانی شمارنده و فعال‌شدن دکمه‌ی حذف
function updateEditDeleteCount() {
    const n = document.querySelectorAll('#adEditModal .edit-image-delete:checked').length;
    const el = document.getElementById('editDeleteCount');
    if (el) el.textContent = n + ' انتخاب شده';
    const btn = document.getElementById('editDeleteBtn');
    if (btn) btn.disabled = n === 0;
}

// حذف دسته‌جمعی تصاویر انتخاب‌شده (فایل‌ها + ردیف‌های دیتابیس)
async function bulkDeleteAdImages() {
    const adId = window.__editAdId;
    const ad = adsData.find(a => String(a.id) === String(adId));
    if (!ad) { alert('آگهی پیدا نشد.'); return; }

    const files = Array.from(document.querySelectorAll('#adEditModal .edit-image-delete:checked')).map(x => x.value);
    if (!files.length) return;
    if (!confirm(files.length + ' تصویر حذف شود؟ این عمل قابل بازگشت نیست.')) return;

    const btn = document.getElementById('editDeleteBtn');
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('admin-images.php?action=delete_bulk', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ ad_id: adId, filenames: files })
        });
        const data = await res.json();
        if (!data || !data.success) {
            alert((data && data.message) || 'حذف ناموفق بود.');
            return;
        }

        const drop = new Set(files);
        ad.images = normalizeJsonArray(ad.images).filter(f => !drop.has(f));
        ad.selected_images = normalizeJsonArray(ad.selected_images).filter(f => !drop.has(f));

        const box = document.getElementById('editImagesManageHost');
        if (box) box.innerHTML = buildEditImages(ad);

        const counter = document.getElementById('editImageCounter');
        if (counter) {
            counter.textContent = document.querySelectorAll('#adEditModal .edit-image-select:checked').length + ' تصویر برای انتشار انتخاب شده';
        }

        alert('' + (data.message || 'تصویرها حذف شدند.'));
    } catch (e) {
        alert('خطا در ارتباط با سرور.');
    }
}


function switchEditPane(
    id,
    btn
) {

    document
        .querySelectorAll(
            '#adEditModal .edit-tab'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    document
        .querySelectorAll(
            '#adEditModal .edit-pane'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    if (btn) {

        btn.classList.add(
            'active'
        );
    }


    document
        .getElementById(id)
        ?.classList.add(
            'active'
        );
}


function updateEditPriceSections() {

    const t =
        document.getElementById(
            'editAdTransactionType'
        )?.value ||
        '';


    document
        .querySelectorAll(
            '#adEditModal .edit-price-box'
        )
        .forEach(
            x =>
                x.classList.remove(
                    'active'
                )
        );


    document
        .getElementById(
            'editPrice_' +
            (
                t === 'فروش'
                    ? 'sell'
                    : t === 'اجاره'
                        ? 'rent'
                        : 'presell'
            )
        )
        ?.classList.add(
            'active'
        );


    // بخش وام فقط برای فروش و پیش‌فروش
    const __loanSec =
        document.getElementById(
            'editLoanSection'
        );

    if (__loanSec) {
        __loanSec.style.display =
            (t === 'فروش' || t === 'پیش فروش')
                ? ''
                : 'none';
    }
}


window.toggleEditLoanFields = function () {
    const chk =
        document.getElementById('editAdHasLoan');
    const grp =
        document.getElementById('editLoanFields');
    if (grp) {
        grp.style.display =
            chk && chk.checked ? '' : 'none';
    }
};


function buildDefaultImagePicker(ad) {
    const map = window.MELKINO_DEFAULT_IMAGES || {};
    const list = map[ad.property_type] || [];
    if (!list || !list.length) return '';
    const cur = parseInt(ad.default_image_no || 0, 10) || 0;
    let html = '<div style="margin-top:16px;"><div style="font-weight:800;margin-bottom:4px;">عکس پیش‌فرض نوع ملک</div>'
        + '<div style="font-size:12px;opacity:.75;margin-bottom:10px;">اگر این آگهی عکس آپلودشده نداشته باشد، یکی از این ۵ عکس تزیینی روی کارت و جزئیات نمایش داده می‌شود. «خودکار» یعنی انتخاب ثابت بر اساس شناسهٔ آگهی.</div>'
        + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;" id="defaultImagePickerHost">'
        + '<label style="display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border:2px solid ' + (cur === 0 ? 'var(--primary,#0b5d5b)' : 'var(--border,#ddd)') + ';border-radius:10px;cursor:pointer;"><input type="radio" name="editDefaultImageNo" value="0"' + (cur === 0 ? ' checked' : '') + '> خودکار</label>';
    list.forEach(function (src, i) {
        html += '<label style="position:relative;border:2px solid ' + (cur === i + 1 ? 'var(--primary,#0b5d5b)' : 'transparent') + ';border-radius:10px;overflow:hidden;cursor:pointer;display:block;">'
            + '<input type="radio" name="editDefaultImageNo" value="' + (i + 1) + '"' + (cur === i + 1 ? ' checked' : '') + ' style="position:absolute;opacity:0;">'
            + '<img src="' + src + '" alt="عکس پیش‌فرض ' + (i + 1) + '" style="width:92px;height:60px;object-fit:cover;display:block;"></label>';
    });
    html += '</div></div>';
    return html;
}

document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'editDefaultImageNo') {
        const host = document.getElementById('defaultImagePickerHost');
        if (!host) return;
        host.querySelectorAll('label').forEach(function (lb) {
            const inp = lb.querySelector('input');
            const on = inp && inp.checked;
            if (inp && inp.value === '0') { lb.style.borderColor = on ? 'var(--primary,#0b5d5b)' : 'var(--border,#ddd)'; }
            else { lb.style.borderColor = on ? 'var(--primary,#0b5d5b)' : 'transparent'; }
        });
    }
});

function mkMelkinoRatingOptions(current) {
    const cur = Number(current) || 0;
    let html = '';
    for (let n = 0; n <= 5; n++) {
        html += '<option value="' + n + '"' + (cur === n ? ' selected' : '') + '>'
            + (n === 0 ? 'بدون امتیاز' : (n + ' ستاره'))
            + '</option>';
    }
    return html;
}

function openAdEditModal(
    id
) {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(id)
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    window.__editAdId =
        ad.id;


    window.__editDetails =
        normalizeJsonObject(
            ad.property_details
        );


    // گزینه‌های سند و معاوضه (راند ۱۹) — همان فهرست‌های فرم ثبت
    const editDeedOptions = [
        'طلق', 'وقفی', 'مشاعی', 'عرصه', 'اعیان',
        'رهنی', 'قولنامه عادی', 'قولنامه شورایی', 'برگه واگذاری',
    ];
    const editCurDeed = String(ad.deed_type || '');
    const editExchangeOptions = [
        'آپارتمان', 'باغ', 'ویلایی', 'اداری', 'مغازه', 'زمین', 'خودرو',
    ];
    const editCurExchange = String(ad.exchange_types || '')
        .split(',')
        .map(x => x.trim())
        .filter(Boolean);
    const editExchangeOn = String(ad.exchange_interested ?? '') === '1' ||
        ad.exchange_interested === 1 || ad.exchange_interested === true;


    window.__editAmenities =
        normalizeJsonArray(
            ad.amenities
        );


    const images =
        normalizeJsonArray(
            ad.images
        );


    const selected =
        normalizeJsonArray(
            ad.selected_images
        );


    const userChoice =
        String(
            ad.publish_photos ||
            'yes'
        ) === 'yes';


    const transaction =
        ad.transaction_type ||
        'فروش';


    document.getElementById(
        'adEditContent'
    ).innerHTML = `

<div class="edit-pro-shell">

<div class="edit-pro-head">

<div>

<h2>
    ویرایش کامل آگهی
</h2>

<p>

کد:
<b>
    ${editEsc(
        ad.ad_id ||
        ad.id
    )}
</b>

·

${editEsc(
    ad.property_type ||
    '-'
)}

·

${editEsc(
    ad.location ||
    '-'
)}

</p>

</div>


<span
    class="ad-status ${getStatusClass(
        ad.status
    )}"
>
    ${getStatusLabel(
        ad.status
    )}
</span>

</div>


<div class="edit-tabs">

<button
    class="edit-tab active"
    onclick="switchEditPane('editBase',this)"
>
    اطلاعات پایه
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editSpecs',this)"
>
    مشخصات ملک
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editPrice',this)"
>
    قیمت
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editAmenities',this)"
>
    امکانات و توضیحات
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editImages',this)"
>
    تصاویر و انتشار
</button>

<button
    class="edit-tab"
    onclick="switchEditPane('editMelkinoVisit',this)"
>
    بازدید ملکینو
</button>

</div>


<div class="edit-pro-body">


<section
    class="edit-pane active"
    id="editBase"
>

<div class="edit-section">

<h3>
    اطلاعات آگهی
</h3>


<div class="edit-grid">

<div class="edit-field full">

<label>
    عنوان آگهی
</label>

<input
    id="editAdTitle"
    value="${editEsc(
        ad.title
    )}"
>

</div>


<div class="edit-field">

<label>
    نوع معامله
</label>

<select
    id="editAdTransactionType"
>

${
    [
        ['فروش', 'خرید و فروش'],
        ['اجاره', 'اجاره'],
        ['رهن کامل', 'رهن کامل'],
        ['رهن و اجاره', 'رهن و اجاره'],
        ['پیش فروش', 'پیش فروش'],
    ]
        .map(
            ([val, lbl]) =>
                `<option value="${val}" ${transaction === val ? 'selected' : ''}>${lbl}</option>`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    نوع ملک
</label>

<select
    id="editAdPropertyType"
>

${
    Object.keys(
        editFieldDefs
    )
        .map(
            x =>
                `
<option
    value="${x}"
    ${
        ad.property_type ===
        x
            ? 'selected'
            : ''
    }
>
    ${x}
</option>
`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    وضعیت آگهی
</label>

<select
    id="editAdStatus"
>

${
    [
        [
            'pending',
            'در انتظار تایید'
        ],
        [
            'published',
            'منتشر شده'
        ],
        [
            'sold',
            'فروخته شده'
        ],
        [
            'suspended',
            'معلق'
        ],
        [
            'rejected',
            'رد شده'
        ],
        [
            'price-pending',
            'قیمت در انتظار'
        ]
    ]
        .map(
            ([
                v,
                l
            ]) =>
                `
<option
    value="${v}"
    ${
        ad.status ===
        v
            ? 'selected'
            : ''
    }
>
    ${l}
</option>
`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    محله / محدوده
</label>

<input
    id="editAdLocation"
    value="${editEsc(
        ad.location
    )}"
>

</div>


<div class="edit-field full">

<label>
    آدرس دقیق
</label>

<textarea
    id="editAdAddress"
>${editEsc(
    ad.address
)}</textarea>

</div>

<div class="edit-field full">

<label>
    موقعیت روی نقشه (کلیک یا کشیدن نشانگر)
</label>

<div
    id="editAdMap"
    style="height:260px;border-radius:10px;overflow:hidden;"
></div>

<div
    style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;font-size:12px;"
>
    <span>عرض جغرافیایی:</span>
    <input
        id="editAdLat"
        type="text"
        inputmode="decimal"
        dir="ltr"
        style="width:140px;"
        value="${editEsc(String(ad.latitude ?? ''))}"
    >
    <span>طول جغرافیایی:</span>
    <input
        id="editAdLng"
        type="text"
        inputmode="decimal"
        dir="ltr"
        style="width:140px;"
        value="${editEsc(String(ad.longitude ?? ''))}"
    >
</div>

</div>

</div>

</div>


<div class="edit-section">

<h3>
    اطلاعات مالک
</h3>


<div class="edit-grid">


<div class="edit-field">

<label>
    جنسیت
</label>

<select
    id="editAdGender"
>

<option
    value="آقا"
    ${
        ad.gender ===
        'آقا'
            ? 'selected'
            : ''
    }
>
    آقا
</option>

<option
    value="خانم"
    ${
        ad.gender ===
        'خانم'
            ? 'selected'
            : ''
    }
>
    خانم
</option>

</select>

</div>


<div class="edit-field">

<label>
    نام خانوادگی
</label>

<input
    id="editAdLastName"
    value="${editEsc(
        ad.last_name
    )}"
>

</div>


<div class="edit-field">

<label>
    شماره تماس
</label>

<input
    id="editAdPhone"
    value="${editEsc(
        ad.phone
    )}"
    dir="ltr"
>

</div>

<div class="edit-field">

<label>
    شماره تماس دوم
</label>

<input
    id="editAdPhone2"
    value="${editEsc(
        ad.phone2 || ''
    )}"
    dir="ltr"
>

</div>


<div class="edit-field">

<label>
    کد آگهی
</label>

<input
    id="editAdCode"
    value="${editEsc(
        ad.ad_id ||
        ad.id
    )}"
>

</div>


</div>

</div>

</section>


<section
    class="edit-pane"
    id="editSpecs"
>

<div class="edit-section">

<h3>
    مشخصات اختصاصی
    ${editEsc(
        ad.property_type ||
        'ملک'
    )}
</h3>


<div
    class="edit-grid"
    id="editPropertyFields"
>

${buildEditPropertyFields(
    ad.property_type ||
    'آپارتمان',
    window.__editDetails
)}

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editPrice"
>

<div class="edit-section">

<h3>
    قیمت و شرایط معامله
</h3>


<div
    id="editPrice_sell"
    class="edit-price-box ${
        transaction ===
        'فروش'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    قیمت فروش
</label>

<input
    id="editAdPriceSell"
    value="${editEsc(
        normalizeNumberText(ad.price_sell)
    )}"
>

<div class="edit-money-words" id="editAdPriceSellWords"></div>

</div>


<div class="edit-field">

<label>
    قیمت
</label>

<select
    id="editAdPriceCondition"
>

<option
    value="negotiable"
    ${
        ad.price_condition !==
        'fixed'
            ? 'selected'
            : ''
    }
>
    قابل مذاکره
</option>

<option
    value="fixed"
    ${
        ad.price_condition ===
        'fixed'
            ? 'selected'
            : ''
    }
>
    مقطوع
</option>

</select>

</div>

</div>

</div>


<div
    id="editPrice_rent"
    class="edit-price-box ${
        transaction ===
        'اجاره'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    ودیعه
</label>

<input
    id="editAdDeposit"
    value="${editEsc(
        normalizeNumberText(ad.deposit)
    )}"
>

<div class="edit-money-words" id="editAdDepositWords"></div>

</div>


<div class="edit-field">

<label>
    اجاره ماهانه
</label>

<input
    id="editAdRentMonthly"
    value="${editEsc(
        normalizeNumberText(ad.rent_monthly)
    )}"
>

<div class="edit-money-words" id="editAdRentMonthlyWords"></div>

</div>


<div class="edit-field">

<label>
    رهن کامل
</label>

<input
    id="editAdFullRent"
    value="${editEsc(
        normalizeNumberText(ad.full_rent)
    )}"
>

<div class="edit-money-words" id="editAdFullRentWords"></div>

</div>


<div class="edit-field full">

<label>

<input
    type="checkbox"
    id="editFullRentEnabled"
    ${
        String(
            ad.full_rent_enabled
        ) === '1'
            ? 'checked'
            : ''
    }
>

این آگهی رهن کامل است

</label>

</div>

</div>

</div>


<div
    id="editPrice_presell"
    class="edit-price-box ${
        transaction ===
        'پیش فروش'
            ? 'active'
            : ''
    }"
>

<div class="edit-grid">


<div class="edit-field">

<label>
    قیمت کل
</label>

<input
    id="editAdTotalPrice"
    value="${editEsc(
        normalizeNumberText(ad.total_price)
    )}"
>

<div class="edit-money-words" id="editAdTotalPriceWords"></div>

</div>


<div class="edit-field">

<label>
    پیش‌پرداخت
</label>

<input
    id="editAdDownPayment"
    value="${editEsc(
        normalizeNumberText(ad.down_payment)
    )}"
>

<div class="edit-money-words" id="editAdDownPaymentWords"></div>

</div>


<div class="edit-field full">

<label>
    شرایط پرداخت
</label>

<textarea
    id="editAdPaymentTerms"
>${editEsc(
    ad.payment_terms
)}</textarea>

</div>

</div>

</div>

<div class="edit-section" id="editLoanSection" style="margin-top:10px;${(transaction === 'فروش' || transaction === 'پیش فروش') ? '' : 'display:none;'}">

<div style="font-size:12px;color:var(--text-muted);line-height:2;margin-bottom:6px;background:var(--gold-bg, #faf6ec);border-radius:10px;padding:8px 10px;">
    ℹ️ مبلغ وام از قیمت درج شده کسر می‌شود؛ در کارت‌ها و صفحهٔ آگهی هر دو قیمت نمایش داده می‌شود (قیمت کامل و «قیمت منهای وام + وام»).
</div>

<label class="edit-bool" style="width:fit-content;">
<input
    type="checkbox"
    id="editAdHasLoan"
    onchange="toggleEditLoanFields()"
    ${ad.has_loan && String(ad.has_loan) !== '0' ? 'checked' : ''}
>
<span>وام دارد</span>
</label>

<div
    id="editLoanFields"
    class="edit-grid"
    style="margin-top:8px;${ad.has_loan && String(ad.has_loan) !== '0' ? '' : 'display:none;'}"
>

<div class="edit-field">
<label>مبلغ وام (تومان)</label>
<input id="editAdLoanAmount" value="${editEsc(normalizeNumberText(ad.loan_amount))}">
</div>

<div class="edit-field">
<label>نوع وام</label>
<input id="editAdLoanType" value="${editEsc(ad.loan_type)}">
</div>

<div class="edit-field">
<label>مدت وام</label>
<input id="editAdLoanDuration" value="${editEsc(ad.loan_duration)}">
</div>

<div class="edit-field">
<label>بانک</label>
<input id="editAdLoanBank" value="${editEsc(ad.loan_bank)}">
</div>

<div class="edit-field">
<label>مبلغ هر قسط (تومان)</label>
<input id="editAdLoanInstallment" value="${editEsc(normalizeNumberText(ad.loan_installment))}">
</div>

<div class="edit-field">
<label>تعداد اقساط پرداخت شده</label>
<input id="editAdLoanPaid" value="${editEsc(ad.loan_installments_paid)}">
</div>

<div class="edit-field full">
<label>توضیحات تکمیلی وام</label>
<textarea id="editAdLoanNotes">${editEsc(ad.loan_notes)}</textarea>
</div>

</div>

</div>

<div class="edit-section" style="margin-top:10px;">

<label class="edit-bool" style="width:fit-content;">
<input
    type="checkbox"
    id="editAdPriceHidden"
    ${ad.price_hidden ? 'checked' : ''}
>
<span>نمایش قیمت برای کاربران مخفی شود و به‌جای آن «برای استعلام قیمت تماس بگیرید» نمایش داده شود</span>
</label>

<label class="admin-field-label" for="editAdPriceNote" style="display:block;margin-top:10px;">متن جایگزین قیمت (اختیاری — اگر پر شود، به‌جای قیمت به کاربران نشان داده می‌شود)</label>
<input
    type="text"
    id="editAdPriceNote"
    class="admin-input"
    maxlength="255"
    placeholder="مثلاً: نیاز به استعلام قیمت از مالک دارد / برای این ملک تماس بگیرید"
    value="${editEsc(ad.price_note || '')}"
>

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editAmenities"
>

<div class="edit-section">

<h3>
    امکانات ملک
</h3>


<div
    class="edit-checks"
    id="editAmenitiesFields"
>

${buildEditAmenities(
    ad.property_type ||
    'آپارتمان',
    window.__editAmenities
)}

</div>

</div>


<div class="edit-section">

<h3>
    توضیحات
</h3>


<div class="edit-field">

<textarea
    id="editAdDescription"
    class="edit-description"
>${editEsc(
    ad.description
)}</textarea>

</div>

</div>


<div class="edit-section">

<h3>
    سند و معاوضه
</h3>


<div class="edit-field">

<label>
    نوع سند
</label>

<select id="editAdDeedType">

<option value="">
    — انتخاب کنید —
</option>

${
    editDeedOptions
        .map(
            o =>
                `<option value="${o}" ${editCurDeed === o ? 'selected' : ''}>${o}</option>`
        )
        .join('')
}

</select>

</div>


<div class="edit-field">

<label>
    توضیحات سند
</label>

<textarea
    id="editAdDeedNotes"
    class="edit-description"
    style="min-height:64px"
>${editEsc(
    ad.deed_notes || ''
)}</textarea>

</div>


<div class="edit-field">

<label class="edit-check">

<input
    type="checkbox"
    id="editAdExchangeInterested"
    ${
        editExchangeOn
            ? 'checked'
            : ''
    }
    onchange="document.getElementById('editAdExchangeTypes').style.display = this.checked ? 'flex' : 'none'"
>

مایل به معاوضه

</label>

<div
    id="editAdExchangeTypes"
    style="display:${
        editExchangeOn
            ? 'flex'
            : 'none'
    };flex-wrap:wrap;gap:6px 18px;margin-top:8px;"
>

${
    editExchangeOptions
        .map(
            o =>
                `<label class="edit-check" style="font-weight:400"><input type="checkbox" class="edit-ad-ex-type" value="${o}" ${editCurExchange.includes(o) ? 'checked' : ''}> ${o}</label>`
        )
        .join('')
}

</div>

</div>

</div>

</section>


<section
    class="edit-pane"
    id="editImages"
>

<div class="edit-section">

<h3>
    مدیریت تصاویر
</h3>


<div
    class="image-choice-banner ${
        userChoice
            ? 'yes'
            : 'no'
    }"
>

<b>
    انتخاب کاربر:
</b>

${
    userChoice
        ? 'کاربر موافق انتشار تصاویر است.'
        : 'کاربر درخواست کرده تصاویر همراه آگهی منتشر نشوند.'
}

<br>

<small>
    این انتخاب به معنی انتخاب عکس نیست؛ انتخاب عکس نهایی با ادمین است.
</small>

</div>


<div class="admin-image-controls">

<label class="edit-check">

<input
    type="checkbox"
    id="editPublishPhotos"
    ${
        userChoice
            ? 'checked'
            : ''
    }
>

انتشار تصاویر در آگهی

</label>


<button
    type="button"
    class="btn-secondary"
    onclick="selectAllAdminImages(true)"
>
    انتخاب همه
</button>


<button
    type="button"
    class="btn-secondary"
    onclick="selectAllAdminImages(false)"
>
    لغو همه
</button>

</div>


<div class="edit-note" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">

    <label class="btn-secondary" style="cursor:pointer; margin:0;">
        ${MK_IC.plus} افزودن عکس جدید
        <input
            type="file"
            id="adminAddImageInput"
            accept="image/jpeg,image/png,image/webp,image/gif"
            multiple
            style="display:none;"
            onchange="uploadAdminAdImage('${editEsc(String(ad.id))}', this)"
        >
    </label>

    <span id="adminAddImageStatus" style="font-size:12px; color:var(--text-secondary);"></span>

</div>


<div class="edit-note">

${
    images.length
        ? 'تمام تصاویر ارسالی کاربر در پایین نمایش داده می‌شوند. فقط تصاویر تیک‌خورده (انتشار) به عنوان عکس انتشار ذخیره می‌شوند. برای حذف، تیک «حذف» را بزنید و دکمه‌ی «حذف تصاویر انتخاب‌شده» را بزنید. تصاویر آپلودی بلافاصله روی کارت‌ها هم اعمال می‌شوند.'
        : 'این کاربر تصویری ارسال نکرده است.'
}

</div>


<div id="editImagesManageHost">${buildEditImages(ad)}</div>
${buildDefaultImagePicker(ad)}

</div>

</section>


<section
    class="edit-pane"
    id="editMelkinoVisit"
>

<div class="edit-section">

<h3>
    بازدید و امتیاز ملکینو
</h3>

<p class="edit-note" style="margin:0 0 12px;line-height:1.9;">
    اگر این آگهی را بازدید کرده‌اید، تیک بزنید. امتیاز روی کارت و نظر فقط در صفحه جزئیات دیده می‌شود — وقتی سوئیچ سایت روشن باشد.
</p>

<label class="edit-check" style="margin-bottom:12px;">
    <input
        type="checkbox"
        id="editAdMelkinoVisited"
        ${Number(ad.melkino_visited) ? 'checked' : ''}
    >
    این آگهی بازدید ملکینو خورده است
</label>

<div class="edit-field" style="max-width:220px;margin-bottom:12px;">
    <label for="editAdMelkinoRating">امتیاز (۱ تا ۵)</label>
    <select id="editAdMelkinoRating">
        ${mkMelkinoRatingOptions(ad.melkino_rating)}
    </select>
</div>

<div class="edit-field">
    <label for="editAdMelkinoReview">نظر ملکینو (صفحه جزئیات)</label>
    <textarea id="editAdMelkinoReview" rows="4" placeholder="مثلاً فایل visیت شد؛ سند و وضعیت ملک مطابق آگهی بود.">${editEsc(ad.melkino_review || '')}</textarea>
</div>

</div>

<div class="editd.melkino_review || '')}</textarea>
</div>

</div>

<div class="edit-section">

<h3>
    نمایش روی سایت
</h3>

<label class="edit-check">
    <input
        type="checkbox"
        id="editMelkinoRatingEnabled"
        ${window.MELKINO_RATING_ENABLED ? 'checked' : ''}
    >
    امتیاز و نظر ملکینو روی کارت‌ها و صفحه جزئیات نشان داده شود
</label>

<p class="edit-note" style="margin-top:8px;">
    پیش‌فرض خاموش است. این سوئیچ برای کل سایت است، نه فقط این آگهی.
</p>

</div>

</section>


</div>


<div class="edit-pro-footer">

<span id="editImageCounter">

${selected.length}
تصویر برای انتشار انتخاب شده

</span>


<div>

<button
    class="btn-secondary"
    onclick="closeModal('adEditModal')"
>
    انصراف
</button>


<button
    class="btn-primary-full"
    style="display:inline-flex;width:auto;padding:0 25px"
    onclick="saveAdEdit()"
>
    ذخیره تغییرات
</button>

</div>

</div>

</div>

`;


    document
        .getElementById(
            'editAdPropertyType'
        )
        .addEventListener(
            'change',
            () => {

                window.__editDetails =
                    {};


                document
                    .getElementById(
                        'editPropertyFields'
                    )
                    .innerHTML =
                        buildEditPropertyFields(
                            document
                                .getElementById(
                                    'editAdPropertyType'
                                )
                                .value,
                            {}
                        );
                if (window.wireEditNumberWords) window.wireEditNumberWords();


                document
                    .getElementById(
                        'editAmenitiesFields'
                    )
                    .innerHTML =
                        buildEditAmenities(
                            document
                                .getElementById(
                                    'editAdPropertyType'
                                )
                                .value,
                            []
                        );
            }
        );


    document
        .getElementById(
            'editAdTransactionType'
        )
        .addEventListener(
            'change',
            updateEditPriceSections
        );


    document
        .getElementById(
            'editPublishPhotos'
        )
        .addEventListener(
            'change',
            updateEditImageCounter
        );


    document
        .getElementById(
            'adEditModal'
        )
        .classList.add(
            'active'
        );


    wireEditMoneyFields();
    wireEditNumberWords();
    if (typeof mkInitAdminEditMap === 'function') mkInitAdminEditMap(ad);

    updateEditImageCounter();
}


function melkinoFaDigitsToLatin(value) {
    return String(value == null ? '' : value)
        .replace(/[۰-۹]/g, function (d) {
            return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
        })
        .replace(/[٠-٩]/g, function (d) {
            return String('٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        });
}


function melkinoGroupDigits(value) {
    var raw = melkinoFaDigitsToLatin(value);
    var digits = raw.replace(/[^0-9]/g, '');
    if (digits === '') return '';
    digits = digits.replace(/^0+(?=\d)/, '');
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}


function melkinoFaNumberWords(n) {
    n = Math.floor(Number(n) || 0);
    if (n === 0) return 'صفر';
    var ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    var teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نزده'];
    var tens = ['', 'ده', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    var hundreds = ['', 'یکصد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    var scales = ['', ' هزار', ' میلیون', ' میلیارد', ' تریلیون'];

    function threeDigits(num) {
        var parts = [];
        var h = Math.floor(num / 100);
        var r = num % 100;
        if (h) parts.push(hundreds[h]);
        if (r) {
            if (r < 10) {
                parts.push(ones[r]);
            } else if (r < 20) {
                parts.push(teens[r - 10]);
            } else {
                var t = Math.floor(r / 10);
                var o = r % 10;
                parts.push(o ? tens[t] + ' و ' + ones[o] : tens[t]);
            }
        }
        return parts.join(' و ');
    }

    var groups = [];
    var idx = 0;
    var num = n;
    while (num > 0 && idx < scales.length) {
        var g = num % 1000;
        if (g) groups.unshift(threeDigits(g) + scales[idx]);
        num = Math.floor(num / 1000);
        idx++;
    }
    return groups.join(' و ');
}


function melkinoMoneyWords(value) {
    var grouped = melkinoGroupDigits(value);
    if (grouped === '') return '';
    var n = Number(grouped.replace(/,/g, ''));
    if (!isFinite(n)) return '';
    return melkinoFaNumberWords(n) + ' تومان';
}


function wireEditMoneyFields() {
    var pairs = [
        ['editAdPriceSell', 'editAdPriceSellWords'],
        ['editAdDeposit', 'editAdDepositWords'],
        ['editAdRentMonthly', 'editAdRentMonthlyWords'],
        ['editAdFullRent', 'editAdFullRentWords'],
        ['editAdTotalPrice', 'editAdTotalPriceWords'],
        ['editAdDownPayment', 'editAdDownPaymentWords']
    ];
    pairs.forEach(function (pair) {
        var input = document.getElementById(pair[0]);
        var hint = document.getElementById(pair[1]);
        if (!input || !hint) return;
        var sync = function () {
            var before = input.value;
            var pos =
                input.selectionStart == null
                    ? before.length
                    : input.selectionStart;
            var digitsBefore = (
                before.slice(0, pos).match(/[0-9۰-۹٠-٩]/g) || []
            ).length;
            var grouped = melkinoGroupDigits(before);
            if (before !== grouped) {
                input.value = grouped;
                var seen = 0;
                var newPos = grouped.length;
                if (digitsBefore === 0) {
                    newPos = 0;
                } else {
                    for (var i = 0; i < grouped.length; i++) {
                        if (/[0-9]/.test(grouped[i])) seen++;
                        if (seen >= digitsBefore) {
                            newPos = i + 1;
                            break;
                        }
                    }
                }
                try {
                    input.setSelectionRange(newPos, newPos);
                } catch (e) {}
            }
            hint.textContent = melkinoMoneyWords(grouped);
        };
        input.addEventListener('input', sync);
        input.addEventListener('blur', sync);
        sync();
    });
}


// ── درج خودکار «عدد به حروف» زیر همهٔ فیلدهای عددی مودال ویرایش آگهی ──
function wireEditNumberWords() {
    var modal = document.getElementById('adEditModal');
    if (!modal) return;
    var isMoney = function (id) {
        return /LoanAmount|LoanInstallment|price|rent|deposit|total/i.test(id);
    };
    var attach = function (input) {
        if (!input || input.dataset.numWordsWired) return;
        if (input.type === 'checkbox' || input.type === 'hidden') return;
        var id = input.id || '';
        if (!id) return;
        input.dataset.numWordsWired = '1';
        var hint = document.createElement('div');
        hint.className = 'ad-price-words';
        hint.id = id + 'NumWords';
        var host =
            input.closest('.edit-field') ||
            input.closest('.ad-edit-group') ||
            input.parentElement;
        if (host && host.parentNode) {
            host.parentNode.insertBefore(hint, host.nextSibling);
        }
        var render = function () {
            var raw = (input.value || '').replace(/,/g, '').trim();
            if (!/^\d+$/.test(raw)) {
                hint.textContent = '';
                hint.classList.remove('is-visible');
                return;
            }
            var n = parseInt(raw, 10);
            hint.textContent =
                '≈ ' +
                melkinoGroupDigits(n) +
                ' — ' +
                melkinoFaNumberWords(n) +
                (isMoney(id) ? ' تومان' : '');
            hint.classList.add('is-visible');
        };
        input.addEventListener('input', render);
        render();
    };
    modal
        .querySelectorAll('input[id^="editDetail_"]')
        .forEach(attach);
    ['editAdLoanAmount', 'editAdLoanInstallment', 'editAdLoanPaid', 'editAdLoanDuration'].forEach(
        function (id) {
            attach(document.getElementById(id));
        }
    );
}
window.wireEditNumberWords = wireEditNumberWords;


function selectAllAdminImages(
    flag
) {

    document
        .querySelectorAll(
            '#adEditModal .edit-image-select'
        )
        .forEach(
            x => {

                x.checked =
                    flag;


                x
                    .closest(
                        '.manage-image'
                    )
                    ?.classList.toggle(
                        'selected',
                        flag
                    );
            }
        );


    updateEditImageCounter();
}


function updateEditImageCounter() {

    const n =
        document
            .querySelectorAll(
                '#adEditModal .edit-image-select:checked'
            )
            .length;


    const el =
        document.getElementById(
            'editImageCounter'
        );


    if (el) {

        el.textContent =
            `${n} تصویر برای انتشار انتخاب شده`;
    }
}


async function adminCreateNewAd() {

    if (!confirm('یک آگهی جدید و خالی ساخته می‌شود تا از همین‌جا تکمیلش کنی. ادامه بدم؟')) {
        return;
    }

    try {

        const response = await fetch('admin-create-ad.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: 'آگهی جدید',
                transaction_type: 'فروش',
                property_type: 'آپارتمان'
            })
        });

        const result = await response.json();

        if (!result.success || !result.ad) {
            alert('' + (result.message || 'ساخت آگهی جدید ناموفق بود.'));
            return;
        }

        const newAd = result.ad;
        newAd.images = [];
        newAd.selected_images = [];

        adsData.unshift(newAd);
        renderAds();
        renderDashboard();

        openAdEditModal(newAd.id);

    } catch (e) {
        alert('خطا در ارتباط با سرور.');
    }
}


async function uploadAdminAdImage(adId, inputEl) {

    const files = inputEl.files;
    if (!files || !files.length) return;

    const statusEl = document.getElementById('adminAddImageStatus');
    if (statusEl) statusEl.textContent = 'در حال آپلود...';

    const ad =
        adsData.find(
            a => String(a.id) === String(adId)
        );

    if (!ad) {
        if (statusEl) statusEl.textContent = 'آگهی پیدا نشد.';
        return;
    }

    const formData = new FormData();
    formData.append('ad_id', adId);
    for (let i = 0; i < files.length; i++) {
        formData.append('images[]', files[i]);
    }

    try {

        const response = await fetch('admin-upload-image.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            if (statusEl) {
                statusEl.textContent =
                    '' + (result.errors && result.errors.length ? result.errors.join(' | ') : 'آپلود ناموفق بود.');
            }
            return;
        }

        const currentImages = normalizeJsonArray(ad.images);
        const newImages = result.images || [];

        ad.images = currentImages.concat(newImages);

        const currentSelected = normalizeJsonArray(ad.selected_images);
        ad.selected_images = currentSelected.concat(newImages);

        // راند ۵۲: در حالت خالی، گرید وجود ندارد و append بی‌اثر بود؛
        // حالا همیشه کل بخش تصاویر بازسازی می‌شود.
        const imgBox = document.getElementById('editImagesManageHost');
        if (imgBox && typeof buildEditImages === 'function') {
            imgBox.innerHTML = buildEditImages(ad);
        }
        if (typeof updateEditDeleteCount === 'function') updateEditDeleteCount();
        if (typeof updateEditImageCounter === 'function') updateEditImageCounter();

        if (statusEl) {
            statusEl.textContent = `${newImages.length} تصویر اضافه شد.` +
                (result.errors && result.errors.length ? ' (' + result.errors.join(' | ') + ')' : '');
        }

        inputEl.value = '';

    } catch (e) {
        if (statusEl) statusEl.textContent = 'خطا در ارتباط با سرور.';
    }
}


async function saveAdEdit() {

    const ad =
        adsData.find(
            a =>
                String(a.id) ===
                String(
                    window.__editAdId
                )
        );


    if (!ad) {

        alert(
            'آگهی یافت نشد'
        );

        return;
    }


    const oldPublish =
        String(
            ad.publish_photos ||
            'yes'
        );


    ad.title =
        document
            .getElementById(
                'editAdTitle'
            )
            .value
            .trim();


    ad.transaction_type =
        document
            .getElementById(
                'editAdTransactionType'
            )
            .value;


    ad.property_type =
        document
            .getElementById(
                'editAdPropertyType'
            )
            .value;


    ad.status =
        document
            .getElementById(
                'editAdStatus'
            )
            .value;


    // راند ۶۴: انتخاب ادمین برای عکس پیش‌فرض نوع ملک
    const defRadio = document.querySelector('input[name="editDefaultImageNo"]:checked');
    ad.default_image_no = defRadio ? (parseInt(defRadio.value, 10) || 0) : (parseInt(ad.default_image_no, 10) || 0);


    ad.location =
        document
            .getElementById(
                'editAdLocation'
            )
            .value
            .trim();


    ad.address =
        document
            .getElementById(
                'editAdAddress'
            )
            .value
            .trim();


    // نقشه: مختصات ویرایش‌شده (عرض/طول) — اگر خالی باشد، مقدار قبلی حفظ می‌شود
    const __latEl = document.getElementById('editAdLat');
    const __lngEl = document.getElementById('editAdLng');
    if (__latEl && __lngEl) {
        const __latRaw = String(__latEl.value || '').trim().replace(',', '.');
        const __lngRaw = String(__lngEl.value || '').trim().replace(',', '.');
        if (__latRaw !== '' || __lngRaw !== '') {
            const __la = Number(__latRaw);
            const __ln = Number(__lngRaw);
            if (__latRaw === '' || __lngRaw === '' ||
                !isFinite(__la) || !isFinite(__ln) ||
                __la < -90 || __la > 90 || __ln < -180 || __ln > 180) {
                alert('مختصات نامعتبر است: عرض بین ۹۰- تا ۹۰ و طول بین ۱۸۰- تا ۱۸۰ باشد (هر دو لازم‌اند).');
                return;
            }
            ad.latitude = Number(__la.toFixed(7));
            ad.longitude = Number(__ln.toFixed(7));
        }
    }

    ad.gender =
        document
            .getElementById(
                'editAdGender'
            )
            .value;


    ad.last_name =
        document
            .getElementById(
                'editAdLastName'
            )
            .value
            .trim();


    ad.phone =
        document
            .getElementById(
                'editAdPhone'
            )
            .value
            .trim();

    ad.phone2 =
        (document.getElementById('editAdPhone2') ? document.getElementById('editAdPhone2').value.trim() : '');


    ad.ad_id =
        document
            .getElementById(
                'editAdCode'
            )
            .value
            .trim() ||
        ad.ad_id ||
        ad.id;


    ad.price_sell =
        document
            .getElementById(
                'editAdPriceSell'
            )
            ?.value
            .trim() ||
        '';


    ad.price_condition =
        document
            .getElementById(
                'editAdPriceCondition'
            )
            ?.value ||
        '';


    ad.deposit =
        document
            .getElementById(
                'editAdDeposit'
            )
            ?.value
            .trim() ||
        '';


    ad.rent_monthly =
        document
            .getElementById(
                'editAdRentMonthly'
            )
            ?.value
            .trim() ||
        '';


    ad.full_rent =
        document
            .getElementById(
                'editAdFullRent'
            )
            ?.value
            .trim() ||
        '';


    ad.full_rent_enabled =
        document
            .getElementById(
                'editFullRentEnabled'
            )
            ?.checked
            ? '1'
            : '0';


    ad.total_price =
        document
            .getElementById(
                'editAdTotalPrice'
            )
            ?.value
            .trim() ||
        '';


    ad.down_payment =
        document
            .getElementById(
                'editAdDownPayment'
            )
            ?.value
            .trim() ||
        '';


    ad.payment_terms =
        document
            .getElementById(
                'editAdPaymentTerms'
            )
            ?.value
            .trim() ||
        '';


    ad.price_hidden =
        !!document
            .getElementById(
                'editAdPriceHidden'
            )
            ?.checked;

    ad.price_note = (
        document.getElementById('editAdPriceNote')?.value || ''
    ).trim();


    // ===== فیلدهای وام =====
    ad.has_loan =
        document
            .getElementById(
                'editAdHasLoan'
            )
            ?.checked
            ? '1'
            : '0';

    ad.loan_amount =
        document
            .getElementById(
                'editAdLoanAmount'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_type =
        document
            .getElementById(
                'editAdLoanType'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_duration =
        document
            .getElementById(
                'editAdLoanDuration'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_bank =
        document
            .getElementById(
                'editAdLoanBank'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_installment =
        document
            .getElementById(
                'editAdLoanInstallment'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_installments_paid =
        document
            .getElementById(
                'editAdLoanPaid'
            )
            ?.value
            .trim() ||
        '';

    ad.loan_notes =
        document
            .getElementById(
                'editAdLoanNotes'
            )
            ?.value
            .trim() ||
        '';


    ad.property_details =
        collectEditDetails();


    ad.amenities =
        Array.from(
            document.querySelectorAll(
                '#adEditModal .edit-amenity:checked'
            )
        )
        .map(
            x =>
                x.value
        );


    const custom =
        (
            document.getElementById(
                'editCustomAmenities'
            )?.value ||
            ''
        )
        .split(',')
        .map(
            x =>
                x.trim()
        )
        .filter(Boolean);


    ad.amenities =
        [
            ...new Set(
                [
                    ...ad.amenities,
                    ...custom
                ]
            )
        ];


    ad.description =
        document
            .getElementById(
                'editAdDescription'
            )
            .value
            .trim();

    const mkVisitedEl = document.getElementById('editAdMelkinoVisited');
    if (mkVisitedEl) {
        ad.melkino_visited = mkVisitedEl.checked ? 1 : 0;
    }
    const mkRatingEl = document.getElementById('editAdMelkinoRating');
    if (mkRatingEl) {
        ad.melkino_rating = mkRatingEl.value || '';
    }
    const mkReviewEl = document.getElementById('editAdMelkinoReview');
    if (mkReviewEl) {
        ad.melkino_review = (mkReviewEl.value || '').trim();
    }
    const ratingToggleEl = document.getElementById('editMelkinoRatingEnabled');
    if (ratingToggleEl) {
        const ratingSiteOn = !!ratingToggleEl.checked;
        window.MELKINO_RATING_ENABLED = ratingSiteOn;
        try {
            fetch('admin-ads-excel.php?action=rating_toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ enabled: ratingSiteOn })
            });
        } catch (e) {}
    }


    // سند و معاوضه (راند ۱۹)
    const __deedSel = document.getElementById('editAdDeedType');
    if (__deedSel) {
        ad.deed_type = __deedSel.value;
    }

    const __deedNotes = document.getElementById('editAdDeedNotes');
    if (__deedNotes) {
        ad.deed_notes = __deedNotes.value.trim();
    }

    const __exInterested = document.getElementById('editAdExchangeInterested');
    if (__exInterested) {
        ad.exchange_interested = __exInterested.checked ? 1 : 0;
        const __exPicked = Array.from(
            document.querySelectorAll('.edit-ad-ex-type:checked')
        ).map(c => c.value);
        ad.exchange_types = __exInterested.checked ? __exPicked.join(',') : '';
    }


    ad.publish_photos =
        document
            .getElementById(
                'editPublishPhotos'
            )
            .checked
            ? 'yes'
            : 'no';


    ad.selected_images =
        Array.from(
            document.querySelectorAll(
                '#adEditModal .edit-image-select:checked'
            )
        )
        .map(
            x =>
                x.value
        );


    if (
        ad.publish_photos ===
            'yes' &&
        normalizeJsonArray(
            ad.images
        ).length &&
        ad.selected_images.length ===
            0
    ) {

        switchEditPane(
            'editImages',
            document.querySelector(
                '#adEditModal .edit-tab[onclick*=\"editImages\"]'
            )
        );


        alert(
            'برای انتشار تصاویر، حداقل یک تصویر را انتخاب کنید.'
        );


        return;
    }


    if (
        ad.publish_photos ===
        'no'
    ) {

        ad.selected_images =
            [];
    }


    ad.updated_at =
        new Date()
            .toISOString()
            .slice(0,19)
            .replace(
                'T',
                ' '
            );


    const ok = await saveAdsToFile([ad]);
    if (!ok) return;


    closeModal(
        'adEditModal'
    );


    renderAds();

    renderDashboard();


    alert(
        oldPublish ===
            ad.publish_photos
            ?

            'آگهی با موفقیت ویرایش شد.'

            :

            'آگهی ذخیره شد؛ انتخاب ادمین درباره انتشار تصاویر اعمال شد.'
    );
}


// ==============================================
// توابع کمکی آگهی
// ==============================================

function getStatusLabel(
    status
) {

    const labels = {

        pending:
            'در انتظار',

        published:
            'منتشر شده',

        sold:
            'فروخته شده',

        suspended:
            'معلق',

        rejected:
            'رد شده'

    };


    return labels[status] ||
        status;
}


function getStatusClass(
    status
) {

    const classes = {

        pending:
            'status-pending',

        published:
            'status-published',

        sold:
            'status-sold',

        suspended:
            'status-suspended',

        rejected:
            'status-rejected'

    };


    return classes[status] ||
        'status-pending';
}


/**
 * فرمت مبلغ تومانی برای لیست آگهی‌ها:
 * جدا‌کننده‌ی سه‌رقمی (4,800,000,000)، حذف ‎.00‎ تهی، تحمل ورودی
 * فارسی/عربی/فاصله‌دار؛ خروجی '' یعنی «قیمت ندارد».
 */
function mkFormatToman(value) {
    if (value === null || value === undefined) return '';
    let text = String(value).trim();
    if (!text) return '';
    text = text.replace(/[٬،,\s]/g, '');
    text = text.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    text = text.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    text = text.replace(/٫/g, '.');
    const m = text.match(/^\+?(\d+)(?:\.(\d+))?$/);
    if (!m || !/[1-9]/.test(m[1] + (m[2] || ''))) return '';
    const whole = (m[1].replace(/^0+/, '') || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = m[2] || '';
    return whole + (/[1-9]/.test(fraction) ? '.' + fraction : '');
}

function getDisplayPrice(
    ad
) {

    if (!ad) {
        return '';
    }

    const sell = mkFormatToman(ad.price_sell);
    if (sell) {
        return '💰 فروش: ' + sell + ' تومان';
    }

    const total = mkFormatToman(ad.total_price);
    if (total) {
        return '📋 قیمت کل: ' + total + ' تومان';
    }

    const dep = mkFormatToman(ad.deposit);
    const rent = mkFormatToman(ad.rent_monthly);
    if (dep) {
        let t = '🏠 ودیعه: ' + dep + ' تومان';
        if (rent) {
            t += ' | اجاره: ' + rent + ' تومان';
        }
        return t;
    }
    if (rent) {
        return '🏠 اجاره: ' + rent + ' تومان';
    }

    const full = mkFormatToman(ad.full_rent);
    if (full) {
        return '🏠 رهن کامل: ' + full + ' تومان';
    }

    const down = mkFormatToman(ad.down_payment);
    if (down) {
        return '📋 پیش‌پرداخت: ' + down + ' تومان';
    }

    return '';
}


// ==============================================
// [NEW] مدیریت لوگوی مرکزی ملکینو
// ==============================================

const logoFileInput = document.getElementById('logoFileInput');
const uploadLogoBtn = document.getElementById('uploadLogoBtn');
const fileNameDisplay = document.getElementById('fileNameDisplay');
const logoPreview = document.getElementById('logoPreview');
const uploadProgress = document.getElementById('uploadProgress');
const progressBar = document.getElementById('progressBar');
const logoStatus = document.getElementById('logoStatus');

// نمایش نام فایل و پیش‌نمایش محلی
if (logoFileInput && fileNameDisplay && logoPreview && logoStatus) logoFileInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        fileNameDisplay.textContent = file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)';
        const reader = new FileReader();
        reader.onload = function(ev) {
            logoPreview.src = ev.target.result;
        };
        reader.readAsDataURL(file);
        logoStatus.textContent = '';
    } else {
        fileNameDisplay.textContent = '';
    }
});


/* =========================================================
   خروجی / ورودی اکسل آگهی‌ها (admin-ads-excel.php)
   دکمه‌ها هم در پنل قدیمی (admin-ads.php با onclick) و هم در
   تب V2 (data-act در admin-tab-ads.js) همین تابع‌ها را صدا می‌زنند.
   ========================================================= */

function adminAdsExcelExport() {
    window.location.href = 'admin-ads-excel.php?action=export';
}

function adminAdsExcelTemplate() {
    window.location.href = 'admin-ads-excel.php?action=template';
}

function adminAdsExcelImportPick() {
    const input = document.getElementById('adsExcelFile');
    if (!input) {
        alert('ورودی فایل اکسل پیدا نشد.');
        return;
    }
    input.value = '';
    input.click();
}

async function adminAdsExcelImportChanged(input) {
    const file = input && input.files && input.files[0];
    if (!file) {
        return;
    }
    if (!/\.xlsx$/i.test(file.name)) {
        alert('فقط فایل xlsx. انتخاب کنید (از دکمهٔ «فایل نمونه» قالب را بگیرید).');
        input.value = '';
        return;
    }
    if (!confirm(`فایل «${file.name}» بارگذاری و آگهی‌هایش ثبت شود؟`)) {
        input.value = '';
        return;
    }
    const fd = new FormData();
    fd.append('action', 'import');
    fd.append('excel_file', file, file.name);
    fd.append('csrf_token', window.MELKINO_CSRF || '');
    let data = null;
    try {
        const res = await fetch('admin-ads-excel.php', { method: 'POST', body: fd });
        data = await res.json();
    } catch (e) {
        alert('بارگذاری ناموفق بود: ' + (e && e.message ? e.message : e));
        input.value = '';
        return;
    }
    input.value = '';
    if (!data) {
        alert('پاسخ نامعتبر از سرور.');
        return;
    }
    let msg = data.message || (data.success ? 'انجام شد.' : 'ناموفق بود.');
    if (data.errors && data.errors.length) {
        msg += '\n\n' + data.errors.join('\n');
    }
    alert(msg);
    if (data.success) {
        window.location.reload();
    }
}




/* ===== پیام به‌جای قیمت (ذخیرهٔ تنظیمات عمومی: price_message_enabled / price_message_text) ===== */
document.addEventListener('click', async function (ev) {
    const btn = ev.target && ev.target.closest ? ev.target.closest('#pmSaveBtn') : null;
    if (!btn) return;
    const enabledEl = document.getElementById('pmEnabled');
    const textEl = document.getElementById('pmText');
    const st = document.getElementById('pmStatus');
    if (!enabledEl || !textEl || !st) return;
    const enabled = !!enabledEl.checked;
    const text = (textEl.value || '').trim();
    if (enabled && text === '') {
        st.textContent = 'برای فعال‌سازی، متن پیام را وارد کنید.';
        st.style.color = 'var(--danger,#c0392b)';
        return;
    }
    btn.disabled = true;
    st.style.color = '';
    st.textContent = 'در حال ذخیره…';
    try {
        const csrf = window.MELKINO_CSRF || '';
        const r = await fetch('save_global_settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify({ price_message_enabled: enabled, price_message_text: text, csrf_token: csrf })
        });
        const j = await r.json();
        if (!r.ok || !j.success) throw new Error(j.message || 'ذخیره انجام نشد.');
        st.textContent = enabled ? '✓ ذخیره شد؛ قیمت‌ها با این پیام جایگزین شد.' : '✓ ذخیره شد؛ قیمت‌ها دوباره نمایش داده می‌شوند.';
        st.style.color = 'var(--success,#1e8449)';
    } catch (e) {
        st.textContent = e.message || 'ذخیره انجام نشد.';
        st.style.color = 'var(--danger,#c0392b)';
    } finally {
        btn.disabled = false;
    }
});
