<?php
/**
|--------------------------------------------------------------------------
| رله (واسط) ارتباط با تلگرام و بله
|--------------------------------------------------------------------------
| بعضی هاست‌ها — از جمله InfinityFree — دسترسیِ خروجی به api.telegram.org
| را به‌طور کامل مسدود کرده‌اند. در این حالت «سرور» نمی‌تواند پیامی به
| تلگرام بفرستد، اما «مرورگرِ ادمین» معمولاً می‌تواند.
|
| این فایل سه کار انجام می‌دهد (همه فقط برای ادمین):
|
|   ?action=token         دریافت توکن برای فراخوانیِ مستقیم از مرورگر
|   ?action=server_call   انجام فراخوانی از سمت سرور (مسیر پشتیبان)
|   ?action=record        ثبتِ نتیجه‌ی ارسال موفق (message_id) برای آگهی
|
| نکته‌ی امنیتی: توکن فقط برای نشستِ معتبرِ ادمین و فقط در صورت درخواستِ
| صریح همین endpoint ارسال می‌شود و هیچ‌گاه در صفحه‌ها چاپ نمی‌شود.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/telegram.php';

melkinoRequireAdminJson();

/*
|--------------------------------------------------------------------------
| خواندنِ درخواست
|--------------------------------------------------------------------------
| نکته‌ی مهم و علتِ یک باگِ واقعی:
|   مرورگر درخواست‌ها را با بدنه‌ی JSON می‌فرستد، بنابراین $_POST در اینجا
|   خالی است. قبلاً پلتفرم فقط از $_POST خوانده می‌شد و چون خالی بود،
|   همیشه «تلگرام» فرض می‌شد؛ در نتیجه انتشارِ «بله» با توکنِ بله به
|   آدرس api.telegram.org می‌رفت و خطای عجیبی می‌داد.
|   حالا ابتدا بدنه‌ی JSON خوانده می‌شود و همه‌ی پارامترها (action،
|   platform و بقیه) از همان منبع برداشته می‌شوند.
|--------------------------------------------------------------------------
*/
$relayBody = function_exists('melkinoAdminJsonBody') ? melkinoAdminJsonBody() : $_POST;
if (!is_array($relayBody)) {
    $relayBody = [];
}

/** خواندن یک پارامتر از بدنه‌ی JSON یا در غیر این صورت از GET/POST */
$relayParam = static function (string $key, $default = '') use ($relayBody) {
    if (array_key_exists($key, $relayBody) && $relayBody[$key] !== null) {
        return $relayBody[$key];
    }
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $default;
};

$relayAction = (string)$relayParam('action', '');

$platform = $relayParam('platform', 'telegram');
if ($platform === '') $platform = 'telegram';
if (!is_string($platform) || !in_array($platform, ['telegram','bale'], true)) {
    melkinoAdminJson(['success'=>false,'message'=>'این رله فقط تلگرام و بله را می‌پذیرد؛ انتشار ایتا مسیر اختصاصی دارد.'],422);
}
$apiBase  = $platform === 'bale' ? 'https://tapi.bale.ai/bot' : 'https://api.telegram.org/bot';

$token = $platform === 'bale'
    ? (function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '')
    : (function_exists('melkinoTelegramToken') ? (string)melkinoTelegramToken() : '');

switch ($relayAction) {
    /* ---------------------------------------------------------------
       دریافت توکن برای فراخوانی مستقیم از مرورگر
       --------------------------------------------------------------- */
    case 'token':
        if ($token === '' || in_array($token, ['توکن_ربات_تلگرام', 'توکن_ربات_بله'], true)) {
            melkinoAdminJson(['success' => false, 'message' => 'توکن ' . ($platform === 'bale' ? 'بله' : 'تلگرام') . ' تنظیم نشده است.'], 422);
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        melkinoAdminJson([
            'success'  => true,
            'token'    => $token,
            'api_base' => $apiBase,
            'https'    => $isHttps,
            'warning'  => $isHttps ? '' : 'سایت روی HTTPS نیست؛ ارسال توکن به مرورگر توصیه نمی‌شود.',
        ]);

    /* ---------------------------------------------------------------
       انجام فراخوانی از سمت سرور (مسیر پشتیبان)
       --------------------------------------------------------------- */
    case 'server_call':
        $method = trim((string)$relayParam('method', ''));
        $params = is_array($relayParam('params', null)) ? $relayParam('params') : [];
        $sendRequestId = trim((string)$relayParam('request_id', ''));

        if ($method === '' || preg_match('/^[a-zA-Z0-9_]+$/', $method) !== 1) {
            melkinoAdminJson(['success' => false, 'message' => 'متد نامعتبر است.'], 422);
        }
        if ($token === '') {
            melkinoAdminJson(['success' => false, 'message' => 'توکن تنظیم نشده است.'], 422);
        }

        // گارد ضدتکرار ارسال: اگر همین request_id قبلاً نتیجهٔ قطعی داشته،
        // همان نتیجه برمی‌گردد و هرگز دوباره پیام ارسال نمی‌شود.
        $isSendMethod = strpos($method, 'send') === 0;
        if ($isSendMethod && $sendRequestId !== '') {
            $guard = melkinoSendGuardTake($pdo, $sendRequestId);
            if ($guard['seen'] && is_array($guard['response'])) {
                $cached = $guard['response'];
                melkinoAdminJson($cached, !empty($cached['success']) ? 200 : 400);
            }
        }

        $url = $apiBase . $token . '/' . $method;
        $postFields = http_build_query($params);
        $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, $postFields) : null;

        if ($response === null || $response === '') {
            $proxySet = function_exists('melkinoProxy') ? trim((string)melkinoProxy()) : '';
            $host = $platform === 'bale' ? 'tapi.bale.ai' : 'api.telegram.org';
            if ($proxySet !== '') {
                $failMsg = 'سرور با پروکسیِ تنظیم‌شده نتوانست به ' . $host
                    . ' وصل شود (پروکسی پاسخ نمی‌دهد؛ نشانی/اعتبار آن را در تب «ربات و کانال» بررسی کن).';
            } else {
                $failMsg = 'سرور نتوانست به ' . $host
                    . ' وصل شود (خروجیِ هاست مسدود است؛ در تب «ربات و کانال» یک پروکسی ثبت کن).';
            }
            melkinoAdminJson([
                'success' => false,
                'message' => $failMsg,
            ], 502);
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            melkinoAdminJson(['success' => false, 'message' => 'پاسخ نامعتبر از سرور پیام‌رسان.'], 502);
        }

        $callPayload = [
            'success' => !empty($decoded['ok']),
            'result'  => $decoded['result'] ?? null,
            'message' => empty($decoded['ok']) ? (string)($decoded['description'] ?? 'خطای نامشخص') : 'انجام شد',
            'raw'     => $decoded,
        ];
        // نتیجهٔ قطعی (پیام‌رسان پاسخ داده) را برای جلوگیری از ارسال تکراری ذخیره کن
        if ($isSendMethod && $sendRequestId !== '' && isset($decoded['ok'])) {
            melkinoSendGuardStore($pdo, $sendRequestId, $callPayload);
        }
        melkinoAdminJson($callPayload, empty($decoded['ok']) ? 400 : 200);

    /* ---------------------------------------------------------------
       آماده‌سازیِ متن و تصویر یک آگهی برای ارسال (توسط مرورگر)
       --------------------------------------------------------------- */
    case 'prepare':
        $adId = trim((string)$relayParam('ad_id', ''));

        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'شناسه آگهی نامعتبر است.'], 422);
        }

        global $pdo;
        if (!($pdo instanceof PDO)) {
            melkinoAdminJson(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], 500);
        }

        try {
            $st = $pdo->prepare("SELECT * FROM ads WHERE id = ? LIMIT 1");
            $st->execute([$adId]);
            $ad = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $ad = false;
        }

        if (!$ad) {
            melkinoAdminJson(['success' => false, 'message' => 'آگهی پیدا نشد.'], 404);
        }

        // راند ۲۵: پنجرهٔ ضدتکرار ۱۲۰ ثانیه‌ای — اگر همین آگهی کمتر از ۲ دقیقه
        // پیش روی همین پلتفرم منتشر شده، اجازهٔ ارسال دوباره نمی‌دهیم تا
        // «هر آگهی ۲ بار منتشر می‌شود» از هر دو مسیر (مرورگر/سرور) بسته شود.
        $__col = $platform === 'bale' ? 'bale_published_at' : 'telegram_published_at';
        $__force = (string)$relayParam('force', '') !== '' && (string)$relayParam('force', '') !== '0';
        $__previewOnly = (string)$relayParam('preview_only', '') !== '' && (string)$relayParam('preview_only', '') !== '0';
        if (!$__force && !$__previewOnly && !empty($ad[$__col])) {
            // مقایسه در خودِ دیتابیس (ساعت MySQL) — راند ۱۰ (رفع اختلاف منطقهٔ زمانی)
            $__freshSt = $pdo->prepare("SELECT ($__col > NOW() - INTERVAL 120 SECOND) FROM ads WHERE id = ? LIMIT 1");
            $__freshSt->execute([$adId]);
            if ((bool)$__freshSt->fetchColumn()) {
                melkinoAdminJson([
                    'success' => false,
                    'duplicate_prevented' => true,
                    'message' => 'این آگهی کمتر از ۲ دقیقه پیش در همین پلتفرم منتشر شده است؛ از انتشار تکراری جلوگیری شد.',
                ], 409);
            }
        }

        $channel = $platform === 'bale'
            ? melkinoBotSetting('bale_channel')
            : (function_exists('melkinoChannelId') ? (string)melkinoChannelId() : melkinoBotSetting('telegram_channel'));

        if (is_file(__DIR__ . '/consultant_helper.php')) {
            require_once __DIR__ . '/consultant_helper.php';
        }
        $fieldsIn = $relayParam('fields', null);
        $fieldsOverride = null;
        if (is_array($fieldsIn)) {
            $fieldsOverride = array_values(array_filter(array_map('strval', $fieldsIn)));
        }
        $text = function_exists('melkinoAdMessageText')
            ? melkinoAdMessageText($ad, $platform !== 'bale', $platform, $fieldsOverride)
            : (string)($ad['title'] ?? '');

        $photoUrl = function_exists('melkinoAdImageUrl') ? melkinoAdImageUrl($ad) : '';
        $fieldDefs = [];
        $enabledFields = [];
        if (function_exists('melkinoPublishFieldDefs')) {
            $fieldDefs = melkinoPublishFieldDefs(
                (string) ($ad['property_type'] ?? ''),
                (string) ($ad['transaction_type'] ?? '')
            );
        }
        if (is_array($fieldsOverride)) {
            $enabledFields = $fieldsOverride;
        } elseif (function_exists('melkinoPublishSettings')) {
            try {
                $pub = melkinoPublishSettings(
                    $platform,
                    (string) ($ad['property_type'] ?? ''),
                    (string) ($ad['transaction_type'] ?? '')
                );
                $enabledFields = is_array($pub['fields'] ?? null) ? array_map('strval', $pub['fields']) : array_keys($fieldDefs);
            } catch (Throwable $ePub) {
                $enabledFields = array_keys($fieldDefs);
            }
        } else {
            $enabledFields = array_keys($fieldDefs);
        }

        melkinoAdminJson([
            'success'   => true,
            'ad_id'     => $adId,
            'chat_id'   => $channel,
            'text'      => $text,
            'photo_url' => $photoUrl,
            'has_photo' => $photoUrl !== '',
            'title'     => (string)($ad['title'] ?? ''),
            'property_type' => (string) ($ad['property_type'] ?? ''),
            'transaction_type' => (string) ($ad['transaction_type'] ?? ''),
            'field_defs' => $fieldDefs,
            'fields' => $enabledFields,
        ]);

    /* ---------------------------------------------------------------
       ثبت نتیجه‌ی ارسال موفق (برای انتشار آگهی)
       --------------------------------------------------------------- */
    case 'record':
        $adId = trim((string)$relayParam('ad_id', ''));
        $messageId = trim((string)$relayParam('message_id', ''));

        if ($adId === '') {
            melkinoAdminJson(['success' => false, 'message' => 'اطلاعات ناقص است.'], 422);
        }
        // شناسهٔ پیام اختیاری است: وقتی کاربر تأیید می‌کند پیام رفته اما
        // message_id در دسترس نیست، فقط «منتشر شد» ثبت می‌شود تا انتشار
        // تکراری ناخواسته (و تلاش مجددِ خودکار) مسدود بماند.

        global $pdo;
        if (!($pdo instanceof PDO)) {
            melkinoAdminJson(['success' => false, 'message' => 'پایگاه داده در دسترس نیست.'], 500);
        }

        try {
            // اطمینان از وجود ستون‌های مورد نیاز
            $columns = [];
            foreach ($pdo->query('SHOW COLUMNS FROM ads')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $columns[strtolower((string)$col['Field'])] = true;
            }

            if ($platform === 'bale') {
                if (!isset($columns['bale_message_id'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN bale_message_id VARCHAR(100) NULL");
                }
                if (!isset($columns['bale_channel_id'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN bale_channel_id VARCHAR(191) NULL");
                }
                if (!isset($columns['bale_published_at'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN bale_published_at DATETIME NULL");
                }

                $channel = melkinoBotSetting('bale_channel');
                if ($messageId !== '') {
                    $st = $pdo->prepare(
                        "UPDATE ads SET bale_message_id = ?, bale_channel_id = ?, bale_published_at = NOW() WHERE id = ?"
                    );
                    $st->execute([$messageId, $channel, $adId]);
                } else {
                    $st = $pdo->prepare(
                        "UPDATE ads SET bale_channel_id = ?, bale_published_at = NOW() WHERE id = ?"
                    );
                    $st->execute([$channel, $adId]);
                }
            } else {
                if (!isset($columns['telegram_message_id'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN telegram_message_id VARCHAR(100) NULL");
                }
                if (!isset($columns['telegram_channel_id'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN telegram_channel_id VARCHAR(191) NULL");
                }
                if (!isset($columns['telegram_published_at'])) {
                    $pdo->exec("ALTER TABLE ads ADD COLUMN telegram_published_at DATETIME NULL");
                }

                $channel = function_exists('melkinoChannelId') ? (string)melkinoChannelId() : melkinoBotSetting('telegram_channel');
                if ($messageId !== '') {
                    $st = $pdo->prepare(
                        "UPDATE ads SET telegram_message_id = ?, telegram_channel_id = ?, telegram_published_at = NOW() WHERE id = ?"
                    );
                    $st->execute([$messageId, $channel, $adId]);
                } else {
                    $st = $pdo->prepare(
                        "UPDATE ads SET telegram_channel_id = ?, telegram_published_at = NOW() WHERE id = ?"
                    );
                    $st->execute([$channel, $adId]);
                }
            }

            // لاگ انتشار (راند ۱۸) — مسیر مرورگرِ ادمین
            if (function_exists('melkinoLogChannelPublish')) {
                melkinoLogChannelPublish($pdo, $adId, $platform === 'bale' ? 'bale' : 'telegram', true, $messageId, 'انتشار از مرورگر ادمین');
            }

            melkinoAdminJson(['success' => true, 'message' => 'ثبت شد.']);
        } catch (Throwable $e) {
            melkinoAdminJson(['success' => false, 'message' => melkinoSafeError($e, 'telegram-relay.record', 'ثبت نتیجه انجام نشد.')], 500);
        }

    default:
        melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
}