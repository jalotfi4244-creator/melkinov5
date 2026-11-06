<?php require_once __DIR__ . '/config.php'; ?>
<div role="tabpanel" class="tab-content" id="tab-map">
    <div class="admin-card">
        <div class="card-header"><span class="card-title">داشبورد نقشه</span></div>
        <div class="stats-grid" style="padding:0 16px 16px;">
            <div class="stat-card"><div class="number" id="mpStatWith">…</div><div class="label">دارای موقعیت</div></div>
            <div class="stat-card"><div class="number" id="mpStatWithout">…</div><div class="label">بدون موقعیت</div></div>
            <div class="stat-card"><div class="number" id="mpStatOn">…</div><div class="label">فعال روی نقشه</div></div>
            <div class="stat-card"><div class="number" id="mpStatSell">…</div><div class="label">فروش روی نقشه</div></div>
            <div class="stat-card"><div class="number" id="mpStatRent">…</div><div class="label">اجاره روی نقشه</div></div>
        </div>
    </div>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">تنظیمات نقشه و حریم</span></div>
        <div style="padding:0 16px 16px;display:grid;gap:10px;max-width:640px;">
            <label><input type="checkbox" id="mpEnabled"> نقشه فعال</label>
            <label><input type="checkbox" id="mpRequire"> الزام موقعیت هنگام ثبت</label>
            <label><input type="checkbox" id="mpGps"> GPS</label>
            <label><input type="checkbox" id="mpCircle"> دایره محدوده (خاموش بماند؛ Marker جابه‌جاشده نمایش داده می‌شود)</label>
            <label><input type="checkbox" id="mpMarkers"> نمایش Marker جابه‌جاشده</label>
            <label><input type="checkbox" id="mpCluster"> خوشه‌بندی Marker</label>
            <label>جابه‌جایی Marker عمومی (حدود ۵۰ متر) <input type="number" id="mpRadius" min="40" max="70" step="5"></label>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn-secondary" data-r="40">40</button>
                <button type="button" class="btn-secondary" data-r="50">50</button>
                <button type="button" class="btn-secondary" data-r="60">60</button>
                <button type="button" class="btn-secondary" data-r="70">70</button>
            </div>
            <label>حداکثر فایل در هر درخواست <input type="number" id="mpMax" min="10" max="300"></label>
            <label>حداقل Zoom <input type="number" id="mpZoom" min="1" max="18"></label>
            <label>ارائه‌دهنده نقشه
                <select id="mpProvider"><option value="osm">OpenStreetMap</option></select>
            </label>
            <div id="mpColors"></div>
            <button type="button" class="btn-primary" id="mpSave">ذخیره تنظیمات نقشه</button>
            <span id="mpMsg"></span>
        </div>
    </div>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">فایل‌های بدون موقعیت</span></div>
        <div id="mpMissing" style="padding:0 16px 16px;"></div>
    </div>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">تاریخچه تغییر موقعیت</span></div>
        <div id="mpLogs" style="padding:0 16px 16px;font-size:12px;"></div>
    </div>
</div>
