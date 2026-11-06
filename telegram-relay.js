/**
|--------------------------------------------------------------------------
| رله سمت مرورگر برای تلگرام و بله
|--------------------------------------------------------------------------
| InfinityFree خروجی سرور به api.telegram.org را می‌بندد.
| مرورگر ادمین باید مستقیم به API بزند. api.telegram.org هدر CORS می‌دهد،
| پس اگر fetch شکست بخورد یعنی شبکه/فیلتر است — نه «موفقیت خوانده‌نشده».
| موفقیت جعلی (no-cors / فرم مخفی) عمداً حذف شده است.
|--------------------------------------------------------------------------
*/
(function () {
    'use strict';

    var tokenCache = {};
    var API_BASES = {
        telegram: 'https://api.telegram.org/bot',
        bale: 'https://tapi.bale.ai/bot'
    };

    window.melkinoPlatformLabel = function (platform) {
        return platform === 'bale' ? 'بله' : 'تلگرام';
    };

    window.melkinoRelayToken = async function (platform) {
        if (tokenCache[platform]) return tokenCache[platform];
        try {
            const res = await fetch('telegram-relay.php?action=token&platform=' + encodeURIComponent(platform), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data && data.success && data.token) {
                tokenCache[platform] = data;
                return data;
            }
            return null;
        } catch (e) {
            return null;
        }
    };

    function abortableTimeout(ms) {
        var ctrl = new AbortController();
        var t = setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, ms);
        return { signal: ctrl.signal, cancel: function () { clearTimeout(t); } };
    }

    function networkFail(platform, extra) {
        var host = platform === 'bale' ? 'tapi.bale.ai' : 'api.telegram.org';
        return {
            ok: false,
            via: 'browser',
            assumed: false,
            // ارسال ممکن است انجام شده باشد (timeout پس از تحویل)؛
            // هیچ تلاش خودکار دوباره‌ای نباید انجام شود.
            ambiguous: true,
            description: (extra ? extra + ' ' : '')
                + 'پاسخ قطعی از ' + host + ' دریافت نشد؛ ارسال ممکن است انجام شده باشد. اگر پیام در کانال آمده دیگر ارسال نکنید.'
        };
    }

    async function postAndRead(url, body, signal) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            signal: signal
        });
        var data = await res.json();
        return data;
    }

    window.melkinoClientCall = async function (platform, method, params, options) {
        options = options || {};
        var token = options.token || '';
        var apiBase = API_BASES[platform] || API_BASES.telegram;

        if (!token) {
            var tokenData = await window.melkinoRelayToken(platform);
            if (!tokenData || !tokenData.token) {
                return { ok: false, description: 'توکن ' + window.melkinoPlatformLabel(platform) + ' در دسترس نیست.', via: 'none' };
            }
            token = tokenData.token;
            if (tokenData.api_base) apiBase = tokenData.api_base;
        }

        var url = apiBase + token + '/' + method;
        var clean = {};
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] != null && params[k] !== '') clean[k] = String(params[k]);
        });
        var body = new URLSearchParams(clean).toString();
        var timer = abortableTimeout(20000);

        try {
            var data = await postAndRead(url, body, timer.signal);
            data.via = 'browser';
            data.assumed = false;
            return data;
        } catch (e1) {
            // مهم (رفع ارسال تکراری): این درخواست ممکن است به پیام‌رسان رسیده
            // و پیام ارسال شده باشد؛ تلاش دوباره (FormData) که قبلاً اینجا بود
            // باعث ارسال دوباره می‌شد. دیگر هرگز خودکار ارسال نمی‌کنیم.
            var aborted = (e1 && e1.name === 'AbortError');
            return networkFail(platform, aborted ? 'زمان اتصال تمام شد.' : '');
        } finally {
            timer.cancel();
        }
    };

    window.melkinoServerCall = async function (platform, method, params, options) {
        options = options || {};
        try {
            const res = await fetch('telegram-relay.php?action=server_call', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    platform: platform,
                    method: method,
                    params: params || {},
                    request_id: options.requestId || '',
                    csrf_token: window.MELKINO_CSRF || ''
                })
            });
            const data = await res.json();
            return {
                ok: !!(data && data.success),
                result: data ? data.result : null,
                description: data ? (data.message || '') : 'پاسخ نامعتبر',
                via: 'server',
                assumed: false
            };
        } catch (e) {
            // ممکن است درخواست به سرور سایت رسیده و ارسال انجام شده باشد؛
            // پاسخ برنگشته → وضعیت نامشخص (بدون تلاش خودکار دوباره).
            return { ok: false, via: 'server', assumed: false, ambiguous: true, description: 'پاسخ قطعی از سرور سایت دریافت نشد؛ ارسال ممکن است انجام شده باشد.' };
        }
    };

    window.melkinoApiCall = async function (platform, method, params, options) {
        options = options || {};
        var clientDescription = '';
        var isSend = String(method || '').indexOf('send') === 0;

        if (!options.serverOnly) {
            var clientResult = await window.melkinoClientCall(platform, method, params, options);
            if (clientResult && clientResult.ok && !clientResult.assumed) return clientResult;
            if (clientResult && clientResult.description) clientDescription = clientResult.description;
            if (options.clientOnly) {
                return clientResult && clientResult.assumed
                    ? { ok: false, via: 'browser', description: 'پاسخ تلگرام تأیید نشد؛ ارسال موفق فرض نمی‌شود.' }
                    : (clientResult || { ok: false, via: 'browser', description: 'ارسال از مرورگر ناموفق بود.' });
            }
            // برای متدهای ارسال پیام: اگر نتیجه نامشخص است، خودکار مسیر دوم را
            // نمی‌رویم (ممکن است پیام قبلاً رفته باشد و تکراری شود). تصمیم با فراخواننده.
            if (isSend && clientResult && clientResult.ambiguous) {
                return clientResult;
            }
        }

        if (options.clientOnly) {
            return { ok: false, via: 'browser', description: clientDescription || 'ارسال از مرورگر ناموفق بود.' };
        }

        var serverResult = await window.melkinoServerCall(platform, method, params, options);
        if (serverResult && !serverResult.ok && clientDescription && !options.serverOnly) {
            serverResult.description =
                (serverResult.description || 'خطای سرور')
                + ' | مرورگر: ' + clientDescription
                + ' — فیلترشکن را روشن کنید یا در تب «ربات و کانال» پروکسی سرور بگذارید.';
        }
        return serverResult;
    };

    window.melkinoRecordPublish = async function (platform, adId, messageId) {
        try {
            await fetch('telegram-relay.php?action=record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    platform: platform,
                    ad_id: adId,
                    message_id: messageId || '',
                    csrf_token: window.MELKINO_CSRF || ''
                })
            });
        } catch (e) {}
    };

    window.melkinoClearRelayCache = function () {
        tokenCache = {};
    };
})();
