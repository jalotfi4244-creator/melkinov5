<?php
/** Shared controls for legacy and V2 bot cards. Contains no settings/credentials. */
if (!function_exists('melkinoBotSectionControls')) {
    function melkinoBotSectionControls(string $scope): void {
        static $styled=false;
        $labels=['methods'=>'روش‌های ورود','telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ورود ایتا','eitaa_channel'=>'کانال ایتا','proxy'=>'پروکسی','sms'=>'پیامک'];
        $label=$labels[$scope]??'این بخش';
        if (!$styled) { $styled=true; ?>
<style>
.mk-bot-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:18px 16px 8px;padding-top:12px;border-top:1px solid var(--border,#dbe4df)}
.mk-bot-status{flex:1 1 260px;font-size:12px;line-height:1.9;white-space:pre-wrap}
.mk-bot-status[data-state="success"]{color:#147454}.mk-bot-status[data-state="failed"]{color:#b43737}.mk-bot-status[data-state="unknown"],.mk-bot-status[data-state="checked"]{color:#916800}
.mk-bot-audit{margin:0 16px 16px;font-size:12px}.mk-bot-audit summary{cursor:pointer;color:var(--text-secondary,#607069)}
.mk-bot-log{padding:0 20px;margin:8px 0;max-height:240px;overflow:auto}.mk-bot-log li{padding:7px 0;border-bottom:1px solid var(--border,#ddd);white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8}
.mk-bot-log time{font-size:11px;opacity:.8;display:block}.mk-bot-log [data-outcome="failed"]{color:#b43737}
[data-theme="dark"] .mk-bot-status[data-state="success"],.mx-admin .mk-bot-status[data-state="success"]{color:#83ddba}
[data-theme="dark"] .mk-bot-status[data-state="failed"],.mx-admin .mk-bot-status[data-state="failed"]{color:#ffabab}
[data-theme="dark"] .mk-bot-status[data-state="checked"],.mx-admin .mk-bot-status[data-state="checked"]{color:#f4d17a}
[data-bot-section] input[aria-invalid="true"]{outline:2px solid #c33}
[data-bot-section][aria-busy="true"] button{cursor:wait}
</style>
<?php } ?>
<?php if($scope==='bale'): ?>
<div class="mk-bot-actions" data-bale-phone-controls data-phone-settings-version="bale-settings-save2">
<label>روش دریافت امن شماره <select data-bale-phone-mode disabled><option value="">روش را انتخاب کنید</option><option value="polling">دریافت مستقیم از API — برای InfinityFree</option><option value="webhook">وبهوک — هاست بدون محدودیت ورودی</option></select></label>
<button type="button" class="btn-secondary" data-bale-phone-setup disabled>ذخیره و اعمال روش شماره</button>
<label><input type="checkbox" data-bale-phone-replace disabled> تغییر روش و حذف/تعویض وبهوک قبلی را تأیید می‌کنم؛ دریافت‌کنندهٔ دیگری برای این بازو فعال نیست.</label>
<small>دریافت مستقیم، وبهوک را غیرفعال و صف پیام‌های بازو را دریافت می‌کند. اگر سامانهٔ دیگری پیام‌های این بازو را می‌خواند، ابتدا آن را متوقف کنید یا بازوی جدا بسازید. فقط تماس معتبرِ خود کاربر برای ورود استفاده می‌شود. برای اعمال روش انتخابی، تیک تأیید را بزنید؛ سپس «ذخیره بله» یا «ذخیره و اعمال روش شماره» را انتخاب کنید. وضعیت پایین، مقدار ذخیره‌شدهٔ سرور را نشان می‌دهد.</small>
<span data-bale-phone-status role="status"></span></div>
<?php endif; ?>
<div class="mk-bot-actions">
    <button type="button" class="btn-primary" data-bot-save="<?= $scope ?>" disabled>ذخیره <?= $label ?></button>
    <?php if (in_array($scope,['telegram','bale','eitaa_channel'],true)): ?>
    <button type="button" class="btn-secondary" data-bot-test="<?= $scope ?>" data-test-kind="connection" disabled>تست اتصال <?= $label ?></button>
    <?php elseif ($scope==='eitaa'): ?>
    <button type="button" class="btn-secondary" data-bot-test="eitaa" data-test-kind="configuration" disabled>تست پیکربندی ورود</button>
    <?php elseif ($scope==='sms'): ?>
    <button type="button" class="btn-secondary" data-bot-test="sms" data-test-kind="message" disabled>ارسال پیامک آزمایشی</button>
    <?php endif; ?>
    <?php if (in_array($scope,['telegram','bale'],true)): ?>
    <button type="button" class="btn-secondary" data-bot-test="<?= $scope ?>" data-test-kind="channel" disabled>بررسی کانال</button>
    <?php elseif ($scope==='eitaa_channel'): ?>
    <button type="button" class="btn-secondary" data-bot-test="eitaa_channel" data-test-kind="message" disabled>ارسال پیام آزمایشی به کانال</button>
    <?php endif; ?>
    <button type="button" class="btn-secondary" data-bot-refresh="<?= $scope ?>">بازخوانی این بخش</button>
    <span class="mk-bot-status" data-bot-status="<?= $scope ?>" role="status" aria-live="polite">در انتظار دریافت تنظیمات…</span>
</div>
<details class="mk-bot-audit" open>
    <summary>سوابق ذخیره و تست همین بخش</summary>
    <ol class="mk-bot-log" data-bot-log="<?= $scope ?>"></ol>
</details>
<?php
    }
}
