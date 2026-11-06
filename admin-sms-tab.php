<?php require_once __DIR__ . '/config.php'; ?>
<!-- =========================================================
     برنامهٔ پیامک (ملی‌پیامک) — تب
     رندر کامل با admin-sms.js
     ========================================================= -->

<div role="tabpanel" class="tab-content" id="tab-sms">
    <div class="sms-sub-switch" role="tablist" aria-label="بخش‌های پیامک" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;">
        <button type="button" class="tab-btn active" role="tab" aria-selected="true" data-sms-sub="comm">پنل پیامک</button>
        <button type="button" class="tab-btn" role="tab" aria-selected="false" data-sms-sub="prog">برنامهٔ پیامک</button>
    </div>
    <div id="smsSubComm">
        <div role="tabpanel" class="tab-content active" id="tab-comm"></div>
    </div>
    <div id="smsSubProg" hidden>


    <div class="admin-card">
        <div class="card-header" style="flex-wrap:wrap;gap:8px;">
            <span class="card-title">📡 وضعیت برنامهٔ پیامک</span>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);cursor:pointer;">
                <input type="checkbox" id="smsProgEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
                برنامه فعال باشد
            </label>
        </div>
        <div style="padding:0 16px 16px;">
            <div id="smsProgChips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;"></div>
            <div id="smsProgStatusMsg" class="admin-field-help" style="margin-bottom:0;"></div>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">🎯 هدف‌های پیامک</span>
        </div>
        <div style="padding:0 16px 16px;display:grid;gap:14px;">

            <div style="border:1px solid var(--border);border-radius:12px;padding:12px 14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-primary);cursor:pointer;">
                    <input type="checkbox" id="ssOn" style="width:16px;height:16px;accent-color:var(--primary);">
                    🔍 جستجوی ذخیره‌شده — ملک جدید مطابق فیلتر کاربر
                </label>
                <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;font-size:12px;color:var(--text-secondary);">
                    سقف روزانه برای هر شماره:
                    <input type="number" id="ssCap" min="1" max="10" class="admin-input" style="width:70px;padding:6px 8px;">
                    پیامک در روز · حداقل ملک جدید برای ارسال:
                    <input type="number" id="ssMinNew" min="1" max="20" class="admin-input" style="width:70px;padding:6px 8px;">
                    <span>(۱ = با اولین ملک؛ بیشتر = جمع می‌شود تا حد نصاب، بعد یک پیامک خلاصه)</span>
                </div>
                <label class="admin-field-label" style="margin-top:10px;">متن پیامک تک‌ملکی</label>
                <textarea id="ssTemplate" class="admin-input" rows="4" dir="rtl" style="font-size:12px;line-height:2;"></textarea>
                <div class="admin-field-help">متغیرها: {property} نوع و متراژ و خواب · {location} محله · {price} قیمت فارسی · {title} عنوان · {ad_id} کد آگهی · {link} لینک — «لغو11» خودکار اضافه می‌شود، در متن ننویس.</div>
                <label class="admin-field-label" style="margin-top:8px;">متن پیامک تجمیعی (وقتی حداقل بیشتر از ۱ است)</label>
                <textarea id="ssDigestTemplate" class="admin-input" rows="4" dir="rtl" style="font-size:12px;line-height:2;"></textarea>
                <div class="admin-field-help">متغیرها: {count} تعداد · {list} فهرست شماره‌دار ملک‌ها · {link} لینک</div>
            </div>

            <div style="border:1px solid var(--border);border-radius:12px;padding:12px 14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-primary);cursor:pointer;">
                    <input type="checkbox" id="rmOn" style="width:16px;height:16px;accent-color:var(--primary);">
                    🤝 انطباق درخواست مشتری — فایل جدیدِ منطبق با درخواست
                </label>
                <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;font-size:12px;color:var(--text-secondary);">
                    سقف روزانه:
                    <input type="number" id="rmCap" min="1" max="10" class="admin-input" style="width:70px;padding:6px 8px;">
                    حداقل درصد انطباق:
                    <input type="number" id="rmScore" min="0" max="100" class="admin-input" style="width:70px;padding:6px 8px;">
                    حداقل فایل جدید برای ارسال:
                    <input type="number" id="rmMinNew" min="1" max="20" class="admin-input" style="width:70px;padding:6px 8px;">
                    <span>(مثلاً ۵ = تا ۵ فایل جدید جمع نشده پیامکی نمی‌رود؛ ۴ تا می‌ماند و جمع می‌شود)</span>
                </div>
                <label class="admin-field-label" style="margin-top:10px;">متن پیامک انطباق</label>
                <textarea id="rmTemplate" class="admin-input" rows="3" dir="rtl" style="font-size:12px;line-height:2;"></textarea>
                <div class="admin-field-help">متغیرها: {count} تعداد فایل‌های جدید · {link} لینک صفحهٔ تطبیق‌ها</div>
            </div>

            <div style="border:1px solid var(--border);border-radius:12px;padding:12px 14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-primary);cursor:pointer;">
                    <input type="checkbox" id="aaOn" style="width:16px;height:16px;accent-color:var(--primary);">
                    ⚠️ هشدار به مدیر — صف در انتظار بیش از حد
                </label>
                <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;font-size:12px;color:var(--text-secondary);">
                    حالت:
                    <select id="aaMode" class="admin-input" style="padding:6px 8px;width:auto;">
                        <option value="over">وقتی بیشتر از سقف شد</option>
                        <option value="every">هر N موردِ جدید، یک پیامک</option>
                    </select>
                    <span id="aaOverBox" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        آگهی در انتظار بیشتر از
                        <input type="number" id="aaAds" min="1" class="admin-input" style="width:70px;padding:6px 8px;">
                        · درخواستِ در انتظار بیشتر از
                        <input type="number" id="aaReq" min="1" class="admin-input" style="width:70px;padding:6px 8px;">
                        · حداقل فاصلهٔ دو هشدار:
                        <input type="number" id="aaDeb" min="1" max="72" class="admin-input" style="width:60px;padding:6px 8px;">
                        ساعت
                    </span>
                    <span id="aaEveryBox" style="display:none;gap:10px;align-items:center;flex-wrap:wrap;">
                        هر
                        <input type="number" id="aaEvery" min="1" max="500" class="admin-input" style="width:70px;padding:6px 8px;">
                        درخواستِ جدید · هر
                        <input type="number" id="aaEveryAds" min="1" max="500" class="admin-input" style="width:70px;padding:6px 8px;">
                        آگهیِ جدید (خالی = همان عدد درخواست‌ها)
                    </span>
                    · شمارهٔ مدیر:
                    <input type="text" id="aaPhone" dir="ltr" class="admin-input" placeholder="0912..." style="width:140px;padding:6px 8px;">
                </div>
                <label class="admin-field-label" style="margin-top:10px;">متن پیامک هشدار</label>
                <textarea id="aaTemplate" class="admin-input" rows="3" dir="rtl" style="font-size:12px;line-height:2;"></textarea>
                <div class="admin-field-help">متغیرها: {text} شرح هشدار · {count} تعداد · {threshold} سقف/حد نصاب</div>
            </div>

            <div style="border:1px solid var(--border);border-radius:12px;padding:12px 14px;">
                <label style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;color:var(--text-primary);cursor:pointer;">
                    <input type="checkbox" id="mkOn" style="width:16px;height:16px;accent-color:var(--primary);">
                    📣 کمپین تبلیغاتی زمان‌دار (متن/مخاطب از تب «پنل پیامک» — status زمان‌دار)
                </label>
                <div class="admin-field-help" style="margin-top:6px;margin-bottom:0;">
                    کمپین‌های تبلیغاتی فقط از خط تبلیغاتی، فقط ۸ تا ۲۲ و فقط به کسانی که لغو عضویت نداده‌اند ارسال می‌شود.
                </div>
            </div>
        </div>
    </div>

    <div class="admin-card" id="smsRoutingCard">
        <div class="card-header">
            <span class="card-title">📞 خطوط ارسال و مسیریابی (کدام پیامک از کدام شماره؟)</span>
            <button type="button" class="btn-secondary" style="padding:6px 12px;font-size:12px;" onclick="smsLineAdd()">+ افزودن خط</button>
        </div>
        <div style="padding:0 16px 8px;">
            <div class="admin-field-help">هر خط یک شماره فرستندهٔ ملی‌پیامک است. در «مسیریابی» مشخص می‌کنید هر نوع پیامک از کدام خط برود. هدفی که خط نداشته باشد از خط قدیمی تنظیمات ربات استفاده می‌کند. اگر خطِ یک هدف غیرفعال باشد، آن پیامک ارسال نمی‌شود و در صف می‌ماند.</div>
            <div id="smsLegacyNote" class="admin-field-help" style="margin-top:6px;"></div>
        </div>
        <div style="padding:0 16px 12px;overflow:auto;">
            <table style="width:100%;font-size:12px;border-collapse:collapse;min-width:660px;">
                <thead>
                    <tr style="color:var(--text-secondary);text-align:right;">
                        <th style="padding:6px 8px;">برچسب</th><th style="padding:6px 8px;">شماره فرستنده</th>
                        <th style="padding:6px 8px;text-align:center;">فعال</th><th style="padding:6px 8px;">سقف روزانه (۰ = نامحدود)</th>
                        <th style="padding:6px 8px;">امروز</th><th style="padding:6px 8px;">تست</th><th style="padding:6px 8px;">حذف</th>
                    </tr>
                </thead>
                <tbody id="smsLinesBody"></tbody>
            </table>
        </div>
        <div style="padding:0 16px 16px;">
            <div style="font-weight:800;font-size:13px;margin-bottom:10px;">مسیریابی هر نوع پیامک</div>
            <div id="smsRouteRows" style="display:grid;gap:10px;"></div>
        </div>
        <div class="card-actions" style="padding:16px;">
            <button type="button" class="btn-primary" onclick="smsLinesSave()">💾 ذخیرهٔ خطوط و مسیریابی</button>
            <span id="smsLinesMsg" class="admin-status-msg"></span>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">⚖️ قواعد مقررات ملی پیامک</span>
        </div>
        <div style="padding:0 16px 16px;display:grid;gap:10px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-primary);cursor:pointer;">
                <input type="checkbox" id="cfgLagoo" style="width:16px;height:16px;accent-color:var(--primary);">
                درج خودکار «لغو11» در انتهای پیامک‌های اطلاع‌رسانی/تبلیغاتی (الزام مصوبهٔ رگولاتوری)
            </label>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:var(--text-secondary);">
                ساعات ممنوعهٔ انبوه: از
                <input type="number" id="cfgQuietStart" min="0" max="23" class="admin-input" style="width:60px;padding:6px 8px;">
                شب تا
                <input type="number" id="cfgQuietEnd" min="0" max="23" class="admin-input" style="width:60px;padding:6px 8px;">
                صبح — پیامک‌های این بازه در صف می‌مانند (OTP و هشدار ادمین مستثنی است).
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:var(--text-secondary);">
                سقف ارسال در هر اجرا:
                <input type="number" id="cfgMaxTick" min="1" max="100" class="admin-input" style="width:70px;padding:6px 8px;">
                · دامنهٔ سایت (برای لینک پیامک در اجرای کرون):
                <input type="text" id="cfgSiteUrl" dir="ltr" placeholder="https://melkino.ir" class="admin-input" style="width:220px;padding:6px 8px;">
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">⏱ اجرای خودکار (کرون)</span>
        </div>
        <div style="padding:0 16px 16px;">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12.5px;color:var(--text-secondary);">
                توکن اجرا:
                <input type="text" id="cfgCronToken" dir="ltr" class="admin-input" placeholder="مثلاً یک رشتهٔ تصادفی" style="width:220px;padding:6px 8px;">
                <button type="button" class="btn-secondary" style="padding:8px 14px;font-size:12px;" onclick="smsProgGenToken()">تولید تصادفی</button>
            </div>
            <div class="admin-field-help" style="margin-top:8px;">
                یک cron هر ۵ دقیقه بساز:<br>
                <code dir="ltr" style="background:rgba(127,127,127,.12);padding:2px 6px;border-radius:6px;">curl -s "https/<span></span>/دامنه-سایت/sms-cron.php?token=توکن"</code>
                <br>اگر cron نسازی، سایت خودش با ترافیک روزانه (حداکثر هر ۵ دقیقه) برنامه را اجرا می‌کند.
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                <button type="button" class="btn-primary" style="padding:9px 18px;font-size:13px;" onclick="smsProgRunNow()">▶ اجرای الان</button>
                <button type="button" class="btn-secondary" style="padding:9px 18px;font-size:13px;" onclick="smsProgBalance()">💳 استعلام اعتبار ملی‌پیامک</button>
                <input type="text" id="smsProgTestPhone" dir="ltr" placeholder="09123456789" class="admin-input" style="width:150px;padding:8px 10px;">
                <button type="button" class="btn-secondary" style="padding:9px 18px;font-size:13px;" onclick="smsProgTest()">پیامک تست</button>
                <span id="smsProgActionMsg" style="font-size:12px;font-weight:700;"></span>
            </div>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">📬 گزارش پیامک‌ها (کدام ملک برای کدام مشتری؟)</span>
            <div style="display:flex;gap:8px;align-items:center;">
                <select id="obGoal" class="admin-input" style="padding:6px 10px;font-size:12px;">
                    <option value="all">همهٔ هدف‌ها</option>
                    <option value="saved_search">جستجوی ذخیره‌شده</option>
                    <option value="request_match">انطباق درخواست</option>
                    <option value="admin_alert">هشدار ادمین</option>
                    <option value="campaign">کمپین</option>
                </select>
                <select id="obStatus" class="admin-input" style="padding:6px 10px;font-size:12px;">
                    <option value="all">همهٔ وضعیت‌ها</option>
                    <option value="sent">ارسال‌شده</option>
                    <option value="queued">در صف (ارسال‌نشده)</option>
                    <option value="failed">ناموفق</option>
                </select>
                <button type="button" class="btn-secondary" style="padding:7px 14px;font-size:12px;" onclick="smsProgLoadOutbox()">نمایش</button>
                <button type="button" class="btn-secondary" style="padding:7px 14px;font-size:12px;" onclick="smsDeliveryRefresh()">🔄 بررسی وضعیت تحویل</button>
            </div>
        </div>
        <div style="padding:0 16px 16px;overflow:auto;">
            <table style="width:100%;font-size:11.5px;border-collapse:collapse;min-width:760px;">
                <thead>
                    <tr style="color:var(--text-secondary);text-align:right;">
                        <th style="padding:6px 8px;">زمان</th><th style="padding:6px 8px;">هدف</th>
                        <th style="padding:6px 8px;">شماره</th><th style="padding:6px 8px;">ملک / درخواست</th>
                        <th style="padding:6px 8px;">متن</th><th style="padding:6px 8px;">وضعیت</th><th style="padding:6px 8px;">خط / تحویل</th>
                        <th style="padding:6px 8px;">دلیل عدم ارسال</th>
                    </tr>
                </thead>
                <tbody id="obRows"></tbody>
            </table>
        </div>
    </div>

    <div class="admin-card" id="smsProviderLogCard">
        <div class="card-header">
            <span class="card-title">🗂 گزارش خود ملی‌پیامک (دریافتی/ارسالی)</span>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select id="plLocation" class="admin-input" style="padding:6px 10px;font-size:12px;">
                    <option value="-1">همه</option>
                    <option value="2">ارسالی</option>
                    <option value="1">دریافتی</option>
                </select>
                <input type="text" id="plFrom" dir="ltr" placeholder="شمارهٔ فرستنده (اختیاری)" class="admin-input" style="padding:6px 10px;font-size:12px;width:170px;">
                <button type="button" class="btn-secondary" style="padding:7px 14px;font-size:12px;" onclick="smsProviderLogLoad()">نمایش</button>
            </div>
        </div>
        <div style="padding:0 16px 8px;">
            <div class="admin-field-help">این فهرست مستقیم از سامانه ملی‌پیامک خوانده می‌شود (حداکثر ۳۰ مورد آخر). صف داخلی ملکینو در کارت «گزارش پیامک‌ها» است.</div>
            <span id="plMsg" class="admin-status-msg"></span>
        </div>
        <div style="padding:0 16px 16px;overflow:auto;">
            <table style="width:100%;font-size:11.5px;border-collapse:collapse;min-width:620px;">
                <thead>
                    <tr style="color:var(--text-secondary);text-align:right;">
                        <th style="padding:6px 8px;">تاریخ</th><th style="padding:6px 8px;">از</th>
                        <th style="padding:6px 8px;">به</th><th style="padding:6px 8px;">متن</th><th style="padding:6px 8px;">وضعیت</th>
                    </tr>
                </thead>
                <tbody id="plRows"></tbody>
            </table>
        </div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">🚫 لغو عضویت (مقررات ۲۷۰)</span>
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="optPhone" dir="ltr" placeholder="0912..." class="admin-input" style="width:140px;padding:7px 10px;">
                <select id="optScope" class="admin-input" style="padding:7px 10px;font-size:12px;">
                    <option value="alerts">اطلاع‌رسانی</option>
                    <option value="promo">تبلیغاتی</option>
                    <option value="all">هر دو</option>
                </select>
                <button type="button" class="btn-secondary" style="padding:7px 14px;font-size:12px;" onclick="smsProgOptoutAdd()">افزودن</button>
            </div>
        </div>
        <div style="padding:0 16px 16px;" id="optRows"></div>
    </div>

    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">🔍 جستجوهای ذخیره‌شدهٔ کاربران</span>
        </div>
        <div style="padding:0 16px 16px;" id="ssRows"></div>
    </div>

    <div class="admin-card">
        <div class="card-actions" style="padding:16px;">
            <button type="button" class="btn-primary" onclick="smsProgSave()">💾 ذخیرهٔ تنظیمات برنامهٔ پیامک</button>
            <span id="smsProgSaveMsg" class="admin-status-msg"></span>
        </div>
    </div>

    </div>
</div>
