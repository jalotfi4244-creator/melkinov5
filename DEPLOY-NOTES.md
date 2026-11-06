# فایل‌های لایو (نسخهٔ نهایی ۲۰۲۶-۱۱-۰۵)

## ۱. فایل‌های کامل (جایگزین کنید)
- program/melkino-require-login.php  ← نسخهٔ نهایی؛ همان کد لایو به‌علاوهٔ خط 'telegram-webhook.php', در $allow
- program/assets/css/admin-light-mode.css  ← نسخهٔ جدید (کارت‌های دستیار هم روشن شده‌اند)
- program/telegram-webhook.php  ← فایل جدید

## ۲. ویرایش یک‌خطی در program/admin-panel.php (اگر قبلاً انجام نشده)
قبل از خط </head> این را اضافه کنید:
<link rel="stylesheet" href="assets/css/admin-light-mode.css?v=2">

بعد از آپلود: Ctrl+F5 در پنل.

## ۳. فعال‌سازی وبهوک (با تأیید شما)
- program/admin-tg-webhook-setup.php  ← یک‌بار مصرف، فقط ادمین. بعد از آپلود، با حساب ادمین در مرورگر باز کنید، دکمهٔ «فعال‌سازی» را بزنید، نتیجه را بفرستید و **فوراً این فایل را از هاست حذف کنید**.
