<?php require_once __DIR__ . '/config.php'; require_once __DIR__ . '/db_helpers.php'; ?>
<!-- =========================================================
     REQUESTS
     ========================================================= -->

<div
    class="tab-content"
    id="tab-requests"
>

    <div class="admin-card">

        <div class="card-header">

            <span class="card-title">
                <?= melkinoSvgIcon('inbox') ?> مدیریت درخواست‌ها
            </span>

            <span
                style="font-size:12px;color:var(--text-secondary);"
                id="requestsResultCount"
            >
                <?= count($requestsData) ?> درخواست
            </span>
            <a href="admin-leads.php" class="btn-secondary" style="font-size:12px;font-weight:800;text-decoration:none;">پیامک تطبیق و بازدید تکراری</a>

            <button
                type="button"
                class="btn-primary"
                style="font-size:12px;font-weight:800;"
                onclick="openRequestCreate()"
                title="ثبت درخواست برای مراجع حضوری — مثل ویرایش آگهی، همین‌جا در پنل"
            >
                <?= melkinoSvgIcon('plus') ?> ثبت درخواست ملک
            </button>

        </div>


        <div class="stats-grid" style="padding:0 16px;">

            <div class="stat-card">
                <div class="number" id="reqStatTotal"><?= (int)($requestsTotalCount ?? count($requestsData)) ?></div>
                <div class="label">کل درخواست‌ها</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatNew"><?= (int)($requestsTotals['new_count'] ?? 0) ?></div>
                <div class="label">جدید</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatTracking"><?= (int)($requestsTotals['tracking_count'] ?? 0) ?></div>
                <div class="label">در حال پیگیری</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatMatched"><?= (int)($requestsTotals['matched'] ?? 0) ?></div>
                <div class="label">دارای تطبیق</div>
            </div>

            <div class="stat-card">
                <div class="number" id="reqStatMatches"><?= (int)($requestsTotals['matches'] ?? 0) ?></div>
                <div class="label">کل تطبیق‌ها</div>
            </div>

        </div>


        <div id="requestsListContainer"></div>

    </div>

</div>


<!-- مودال ثبت درخواست (مراجع حضوری) — مثل ویرایش آگهی، خودِ پنل -->
<div class="modal-overlay" id="rqCreateModal">
    <div class="modal-box" style="width:min(680px,94vw);max-height:92vh;overflow:auto;">
        <h3 style="margin:0 0 14px;font-size:15px;">🛎 ثبت درخواست ملک (مراجع حضوری)</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">جنسیت</label>
                <select id="rqNewGender" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;">
                    <option value="آقا">آقا</option><option value="خانم">خانم</option>
                </select></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">نام خانوادگی *</label>
                <input id="rqNewLastName" type="text" placeholder="نام خانوادگی مراجع" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">شماره تماس * (09xxxxxxxxx)</label>
                <input id="rqNewPhone" type="tel" dir="ltr" inputmode="tel" placeholder="09xxxxxxxxx" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">نوع معامله</label>
                <select id="rqNewTransaction" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;">
                    <option value="فروش">فروش</option><option value="رهن کامل">رهن کامل</option><option value="رهن و اجاره">رهن و اجاره</option><option value="اجاره">اجاره</option><option value="پیش‌فروش">پیش‌فروش</option>
                </select></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">نوع ملک</label>
                <input id="rqNewProperty" type="text" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">محله / منطقه</label>
                <input id="rqNewLocation" type="text" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل متراژ</label>
                <input id="rqNewMinArea" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر متراژ</label>
                <input id="rqNewMaxArea" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل قیمت</label>
                <input id="rqNewMinPrice" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر قیمت</label>
                <input id="rqNewMaxPrice" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل ودیعه</label>
                <input id="rqNewMinDeposit" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر ودیعه</label>
                <input id="rqNewMaxDeposit" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل اجاره</label>
                <input id="rqNewMinRent" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر اجاره</label>
                <input id="rqNewMaxRent" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">تاریخ نیاز</label>
                <input id="rqNewDateNeeded" type="date" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">فوریت</label>
                <select id="rqNewUrgency" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;">
                    <option value="فوری">فوری</option><option value="عادی" selected>عادی</option><option value="کم‌فوری">کم‌فوری</option>
                </select></div>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;"><input type="checkbox" id="rqNewRahn"> رهن کامل</label>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;"><input type="checkbox" id="rqNewNotKeyed"> فایل کلیدی نباشد</label>
        </div>
        <div style="margin-top:10px;padding:8px 12px;border-radius:10px;background:rgba(14,124,110,.1);border:1px solid rgba(14,124,110,.3);font-size:11.5px;line-height:1.9;color:var(--text-secondary,#667);">
            درخواست به شمارهٔ مراجع وصل می‌شود؛ بعد از ورود او از بله/تلگرام با همان شماره، در «درخواست‌های من» دیده می‌شود.
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-start;margin-top:14px;">
            <button type="button" class="btn-secondary" style="font-size:12.5px;padding:8px 18px;" onclick="closeModal('rqCreateModal')">انصراف</button>
            <button type="button" class="btn-primary" style="font-size:12.5px;padding:8px 18px;" onclick="saveRequestCreate()">ثبت درخواست</button>
        </div>
    </div>
</div>

<!-- مودال ویرایش درخواست — همان فیلدهای ویرایش سمت کاربر (requests.php) -->
<div class="modal-overlay" id="rqEditModal">
    <div class="modal-box" style="width:min(680px,94vw);max-height:92vh;overflow:auto;">
        <h3 style="margin:0 0 14px;font-size:15px;">✏️ ویرایش درخواست <span id="rqEditCode" style="color:var(--text-secondary,#667);font-weight:700;"></span></h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">نوع معامله</label>
                <select id="rqEditTransaction" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;">
                    <option value="فروش">فروش</option><option value="رهن کامل">رهن کامل</option><option value="رهن و اجاره">رهن و اجاره</option><option value="اجاره">اجاره</option><option value="پیش‌فروش">پیش‌فروش</option>
                </select></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">نوع ملک</label>
                <input id="rqEditProperty" type="text" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div style="grid-column:1/-1;"><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">محله / منطقه</label>
                <input id="rqEditLocation" type="text" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل متراژ</label>
                <input id="rqEditMinArea" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر متراژ</label>
                <input id="rqEditMaxArea" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل قیمت</label>
                <input id="rqEditMinPrice" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر قیمت</label>
                <input id="rqEditMaxPrice" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل ودیعه</label>
                <input id="rqEditMinDeposit" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر ودیعه</label>
                <input id="rqEditMaxDeposit" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداقل اجاره</label>
                <input id="rqEditMinRent" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">حداکثر اجاره</label>
                <input id="rqEditMaxRent" inputmode="numeric" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">تاریخ نیاز</label>
                <input id="rqEditDateNeeded" type="date" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;box-sizing:border-box;"></div>
            <div><label style="font-size:12px;font-weight:800;display:block;margin-bottom:4px;">فوریت</label>
                <select id="rqEditUrgency" style="width:100%;padding:8px 10px;border:1px solid var(--border,#ccc);border-radius:10px;font:inherit;">
                    <option value="فوری">فوری</option><option value="عادی">عادی</option><option value="کم‌فوری">کم‌فوری</option>
                </select></div>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;"><input type="checkbox" id="rqEditRahn"> رهن کامل</label>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;font-weight:700;"><input type="checkbox" id="rqEditNotKeyed"> فایل کلیدی نباشد</label>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-start;margin-top:16px;">
            <button type="button" class="btn-secondary" style="font-size:12.5px;padding:8px 18px;" onclick="closeModal('rqEditModal')">انصراف</button>
            <button type="button" class="btn-primary" style="font-size:12.5px;padding:8px 18px;" onclick="saveRequestEdit()">ذخیره تغییرات</button>
        </div>
    </div>
</div>
