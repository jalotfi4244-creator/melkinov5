<?php require_once __DIR__ . '/config.php'; require_once __DIR__ . '/db_helpers.php'; ?>
<!-- =========================================================
     ADS
     ========================================================= -->

<div
    class="tab-content"
    id="tab-ads"
>

<?php
$__pmS = function_exists('getGlobalSettings') ? getGlobalSettings() : [];
$pmEnabled = !empty($__pmS['price_message_enabled']);
$pmText = (string)($__pmS['price_message_text'] ?? '');
?>
<div class="admin-card" id="pmCard" style="margin-bottom:16px">
    <div class="card-header"><span class="card-title">پیام به‌جای قیمت</span></div>
    <div style="padding:12px 16px;display:grid;gap:10px">
        <label style="display:flex;align-items:center;gap:8px;font-weight:700">
            <input type="checkbox" id="pmEnabled"<?= $pmEnabled ? ' checked' : '' ?>>
            به‌جای قیمت همهٔ آگهی‌ها، این پیام نمایش داده شود
        </label>
        <textarea id="pmText" rows="2" maxlength="300" style="width:100%;padding:8px;border:1px solid var(--border,#ccc);border-radius:8px" placeholder="مثلاً: قیمت را از مشاور بپرسید"><?= htmlspecialchars($pmText, ENT_QUOTES, 'UTF-8') ?></textarea>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button type="button" id="pmSaveBtn" class="btn btn-primary">ذخیرهٔ پیام قیمت</button>
            <span id="pmStatus" style="font-size:13px"></span>
        </div>
        <div style="font-size:12px;color:var(--text-secondary)">وقتی فعال است، در سایت عمومی و کارت‌ها قیمت پنهان و فقط این پیام نشان داده می‌شود. در پنل ادمین قیمت‌ها همچنان دیده می‌شود.</div>
    </div>
</div>
    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                مدیریت آگهی‌ها
            </span>

            <span
                style="font-size:12px;color:var(--text-secondary);"
                id="adsResultCount"
            >
                0 آگهی
            </span>

            <button
                type="button"
                class="btn-secondary"
                style="margin-inline-start:auto;"
                onclick="adminAdsExcelExport()"
            >
                <?= melkinoSvgIcon('download') ?> خروجی اکسل
            </button>

            <button
                type="button"
                class="btn-secondary"
                onclick="adminAdsExcelTemplate()"
            >
                <?= melkinoSvgIcon('save') ?> فایل نمونه
            </button>

            <button
                type="button"
                class="btn-secondary"
                onclick="adminAdsExcelImportPick()"
            >
                <?= melkinoSvgIcon('upload') ?> بارگذاری اکسل
            </button>
            <input type="file" id="adsExcelFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none" onchange="adminAdsExcelImportChanged(this)">

            <button
                type="button"
                class="btn-primary"
                onclick="adminCreateNewAd()"
            >
                <?= melkinoSvgIcon('plus') ?> ثبت آگهی جدید
            </button>

        </div>


        <div class="stats-grid" style="padding:0 16px;">

            <div class="stat-card">
                <div class="number" id="adsStatTotal"><?= (int)($adsTotals['total'] ?? 0) ?></div>
                <div class="label">کل آگهی‌ها</div>
            </div>

            <div class="stat-card">
                <div class="number" id="adsStatPending"><?= (int)($adsTotals['pending'] ?? 0) ?></div>
                <div class="label">در انتظار تایید</div>
            </div>

            <div class="stat-card">
                <div class="number" id="adsStatPublished"><?= (int)($adsTotals['published'] ?? 0) ?></div>
                <div class="label">منتشر شده</div>
            </div>

            <div class="stat-card">
                <div class="number" id="adsStatVip"><?= (int)($adsTotals['vip'] ?? 0) ?></div>
                <div class="label">فایل‌های VIP</div>
            </div>

            <div class="stat-card">
                <div class="number" id="adsStatPublishedVip"><?= (int)($adsTotals['published_vip'] ?? 0) ?></div>
                <div class="label">VIP منتشرشده</div>
            </div>

        </div>

        <div style="padding:0 16px 16px;">
            <div id="adminAdsMap" style="height:280px;border-radius:14px;overflow:hidden;border:1px solid var(--border);"></div>
            <p style="font-size:12px;color:var(--text-secondary);margin:8px 0 0;">Markerها موقعیت دقیق فایل‌ها هستند. برای ویرایش روی Marker بزنید.</p>
        </div>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

        <div class="ads-toolbar">

            <input
                id="adsSearch"
                type="search"
                placeholder="جستجو بر اساس عنوان، کد، محله، تلفن یا نام مالک..."
                autocomplete="off"
            >


            <select id="adsPropertyFilter">

                <option value="all">
                    همه نوع ملک
                </option>

                <option value="آپارتمان">
                    آپارتمان
                </option>

                <option value="ویلا">
                    ویلا
                </option>

                <option value="زمین">
                    زمین
                </option>

                <option value="باغ">
                    باغ
                </option>

                <option value="تجاری">
                    تجاری
                </option>

                <option value="اداری">
                    اداری
                </option>

                <option value="مغازه">
                    مغازه
                </option>

            </select>


            <select id="adsTransactionFilter">

                <option value="all">
                    همه معاملات
                </option>

                <option value="فروش">
                    فروش
                </option>

                <option value="اجاره">
                    اجاره
                </option>

                <option value="رهن کامل">
                    رهن کامل
                </option>

                <option value="رهن و اجاره">
                    رهن و اجاره
                </option>

                <option value="پیش فروش">
                    پیش فروش
                </option>

            </select>


            <select id="adsSort">

                <option value="newest">
                    جدیدترین
                </option>

                <option value="oldest">
                    قدیمی‌ترین
                </option>

                <option value="priceHigh">
                    قیمت بیشتر
                </option>

                <option value="priceLow">
                    قیمت کمتر
                </option>

                <option value="title">
                    عنوان
                </option>

            </select>

        </div>


        <div class="ads-toolbar-actions">

            <button
                class="btn-filter active"
                onclick="filterAds('all', this)"
            >
                همه
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('pending', this)"
            >
                در انتظار تایید
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('published', this)"
            >
                فعال
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('vip', this)"
            >
                <?= melkinoSvgIcon('star') ?> VIP
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('suspended', this)"
            >
                معلق
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('sold', this)"
            >
                فروخته شده
            </button>

            <button
                class="btn-filter"
                onclick="filterAds('rejected', this)"
            >
                رد شده
            </button>

            <button
                class="btn-secondary"
                onclick="resetAdFilters()"
            >
                پاک کردن فیلترها
            </button>

        </div>


        <div
            class="bulk-bar"
            id="bulkBar"
        >

            <div>
                <strong id="selectedCount">
                    0
                </strong>

                آگهی انتخاب شده
            </div>


            <div class="bulk-actions">

                <button
                    class="btn-icon-sm success"
                    onclick="bulkChangeStatus('published')"
                >
                    <?= melkinoSvgIcon('check') ?> انتشار
                </button>

                <button
                    class="btn-icon-sm gold"
                    onclick="bulkChangeStatus('suspended')"
                >
                    <?= melkinoSvgIcon('pause') ?> تعلیق
                </button>

                <button
                    class="btn-icon-sm danger"
                    onclick="bulkDeleteAds()"
                >
                    <?= melkinoSvgIcon('trash') ?> حذف
                </button>

            </div>

        </div>


        <div id="adsListContainer"></div>


        <div class="pagination-bar">

            <div
                style="font-size:12px;color:var(--text-secondary);"
                id="adsPaginationInfo"
            ></div>

            <div
                class="pagination"
                id="adsPagination"
            ></div>

        </div>

    </div>

</div>


