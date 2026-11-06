<?php
/*
|--------------------------------------------------------------------------
| ارسال پیامک — سرویس‌دهنده: ملی‌پیامک (melipayamak.com)
|--------------------------------------------------------------------------
| تنظیمات از پنل ادمین (تب «ربات و کانال» → کارت پنل پیامک) خوانده می‌شود و
| در صورت نبودن، از ثابت‌های config.php (SMS_API_KEY و...).
|
| قرارداد REST ملی‌پیامک:
|   POST https://rest.payamak-panel.com/api/SendSMS/SendSMS
|        username, password, to, from, text, isFlash
|   پاسخ: {"Value": "recId", "RetStatus": 1, "StrRetStatus": "Ok"}
|   ⚠️ موفقیت فقط RetStatus=1 است؛ پاسخ HTTP 200 به‌تنهایی معیار نیست.
|   خط خدماتی/الگو: POST .../BaseServiceNumber  (username,password,to,text,bodyId)
|
| اگر پنل غیرفعال باشد، ارسال کد انجام نمی‌شود؛ مسیر ورود هرگز
| کد را در مرورگر نمایش نمی‌دهد.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/telegram.php'; // برای melkinoHttpPost مشترک
require_once __DIR__ . '/bot-settings.php'; // برای melkinoSmsSettings

/** نقطهٔ پایانی پیش‌فرض ارسال ملی‌پیامک */
if (!defined('MELIPAYAMAK_SEND_URL')) {
    define('MELIPAYAMAK_SEND_URL', 'https://rest.payamak-panel.com/api/SendSMS/SendSMS');
}
/** نقطهٔ پایانی وضعیت تحویل (دلیوری) ملی‌پیامک — REST: GetDeliveries2 */
if (!defined('MELIPAYAMAK_DELIVERY_URL')) {
    define('MELIPAYAMAK_DELIVERY_URL', 'https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2');
}
/** نقطهٔ پایانی گزارش پیامک‌های دریافتی/ارسالی ملی‌پیامک */
if (!defined('MELIPAYAMAK_MESSAGES_URL')) {
    define('MELIPAYAMAK_MESSAGES_URL', 'https://rest.payamak-panel.com/api/SendSMS/GetMessages');
}
/** نقطهٔ پایانی ارسال با الگو (خط خدماتی) ملی‌پیامک */
if (!defined('MELIPAYAMAK_BASE_URL')) {
    define('MELIPAYAMAK_BASE_URL', 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber');
}

/**
 * ترجمهٔ کدهای بازگشتی ملی‌پیامک به پیام فارسی.
 * (بر اساس جدول رسمی ReturnValue مستندات ملی‌پیامک)
 */
if (!function_exists('melipayamakErrorText')) {
    function melipayamakErrorText($ret): string
    {
        $map = [
            '110' => 'الزام استفاده از ApiKey به‌جای رمز عبور (با پشتیبانی ملی‌پیامک تماس بگیرید).',
            '109' => 'الزام تنظیم IP مجاز برای استفاده از API (در پنل ملی‌پیامک IP سرور را مجاز کنید).',
            '108' => 'IP به دلیل تلاش ناموفق مسدود شده است.',
            '-10' => 'در متغیرهای ارسالی لینک وجود دارد (مقررات الگو).',
            '-9'  => 'زمان ارسال گذشته است.',
            '-8'  => 'زمان ارسال مناسب نیست (بازهٔ مجاز ۷ صبح تا ۲۲).',
            '-7'  => 'خطای شماره فرستنده — با پشتیبانی سامانه تماس بگیرید.',
            '-6'  => 'خطای داخلی سامانه — با پشتیبانی تماس بگیرید.',
            '-5'  => 'متن ارسالی با متغیرهای الگو (پترن) همخوانی ندارد.',
            '-4'  => 'کد الگو صحیح نیست یا توسط مدیر سامانه تأیید نشده است.',
            '-3'  => 'خط ارسالی در سامانه تعریف نشده است.',
            '-2'  => 'محدودیت تعداد شماره: در هر بار ارسال فقط یک شماره مجاز است.',
            '-1'  => 'دسترسی وب‌سرویس غیرفعال است — با پشتیبانی تماس بگیرید.',
            '0'   => 'نام کاربری یا رمز عبور سامانه پیامک صحیح نیست.',
            '2'   => 'اعتبار پنل پیامک کافی نیست — حساب را شارژ کنید.',
            '1'   => 'ارسال انجام نشد.',
            '3'   => 'سقف ارسال روزانهٔ حساب پر شده است.',
            '4'   => 'سقف حجم ارسال حساب پر شده است.',
            '5'   => 'شمارهٔ فرستنده نامعتبر است یا برای این حساب تعریف نشده است.',
            '9'   => 'خط عمومی از طریق وب‌سرویس مجاز نیست — از خط اختصاصی استفاده کنید.',
            '14'  => 'متن پیامک شامل لینک است (ارسال لینک مجاز نیست).',
            '15'  => 'ارسال به چند شماره نیاز به درج «لغو11» دارد.',
            '16'  => 'شمارهٔ گیرنده وارد نشده است.',
            '17'  => 'متن پیامک خالی است.',
            '18'  => 'شمارهٔ گیرنده نامعتبر است.',
            '6'   => 'سامانه پیامک در حال بروزرسانی است — بعداً تلاش کنید.',
            '7'   => 'متن حاوی کلمهٔ فیلترشده است — با واحد اداری سامانه تماس بگیرید.',
            '10'  => 'حساب کاربری سامانه پیامک فعال نیست.',
            '11'  => 'ارسال انجام نشد.',
            '12'  => 'مدارک کاربر در سامانه پیامک کامل نیست.',
            '19'  => 'از محدودیت ساعتی ارسال فراتر رفته‌اید.',
            '35'  => 'شمارهٔ گیرنده در لیست سیاه مخابرات است (دریافت تبلیغات بسته).',
        ];
        $key = (string)$ret;
        return $map[$key] ?? ('خطای نامشخص سامانه پیامک (کد ' . $key . ').');
    }
}

/**
 * ترجمهٔ کد «وضعیت تحویل» (GetDeliveries2) به فارسی.
 */
if (!function_exists('smsDeliveryText')) {
    function smsDeliveryText(int $code): string
    {
        $map = [
            0 => 'ارسال به مخابرات', 1 => 'رسیده به گوشی', 2 => 'نرسیده به گوشی', 3 => 'خطای مخابراتی',
            5 => 'خطای نامشخص', 8 => 'رسیده به مخابرات', 16 => 'نرسیده به مخابرات', 35 => 'لیست سیاه',
            100 => 'نامشخص', 200 => 'ارسال شده', 300 => 'فیلتر شده', 400 => 'در لیست ارسال', 500 => 'عدم پذیرش',
        ];
        return $map[$code] ?? ('وضعیت ' . $code);
    }
}

/**
 * وضعیت تحویل یک پیامک (شناسهٔ recId از پاسخ ارسال).
 * REST: POST GetDeliveries2 با username, password, recId (فرم urlencoded).
 * پاسخ «null/خالی» یعنی گزارش تحویل برای حساب فعال نیست.
 *
 * @return array ['ok'=>bool, 'status'=>?int, 'message'=>string]
 */
if (!function_exists('smsDeliveryStatus')) {
    function smsDeliveryStatus(string $recId): array
    {
        $recId = preg_replace('/\D+/', '', $recId) ?? '';
        if ($recId === '') {
            return ['ok' => false, 'status' => null, 'message' => 'شناسهٔ پیامک نامعتبر است.'];
        }
        $settings = smsEffectiveSettings();
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'status' => null, 'message' => 'پنل پیامک غیرفعال است.'];
        }
        $fields = [
            'username' => (string)$settings['api_key'],
            'password' => (string)($settings['password'] ?? ''),
            'recId'    => $recId,
        ];
        $response = melkinoHttpPost(MELIPAYAMAK_DELIVERY_URL, http_build_query($fields));
        if (!is_string($response) || trim($response) === '') {
            return ['ok' => false, 'status' => null, 'message' => 'گزارش تحویل برای این حساب فعال نیست (پاسخ خالی).'];
        }
        if (strtolower(trim($response)) === 'null') {
            return ['ok' => false, 'status' => null, 'message' => 'گزارش تحویل برای این حساب غیرفعال است (پاسخ null) — از پشتیبانی ملی‌پیامک فعال‌سازی بخواهید.'];
        }
        $data = json_decode($response, true);
        if (is_array($data) && array_key_exists('Value', $data)) {
            $value = $data['Value'];
        } elseif (is_array($data)) {
            $value = $data;
        } else {
            $value = is_numeric(trim($response)) ? (int)trim($response) : trim($response);
        }
        if (is_array($value)) {
            $value = count($value) ? reset($value) : null;
        }
        if ($value === null || $value === '' || $value === false) {
            return ['ok' => false, 'status' => null, 'message' => 'گزارش تحویل برای این پیامک دریافت نشد.'];
        }
        if (!is_numeric($value)) {
            return ['ok' => false, 'status' => null, 'message' => 'پاسخ ناشناخته از سامانه: ' . mb_substr((string)$value, 0, 80)];
        }
        $code = (int)$value;
        $local = [
            -1  => 'ارسال نشده.',
            -2  => 'بیش از ۱۰۰ شناسه در یک درخواست ارسال شده است.',
            -3  => 'نام کاربری یا رمز عبور گزارش تحویل اشتباه است.',
            3   => 'نام کاربری یا رمز عبور گزارش تحویل اشتباه است.',
            -10 => 'خطا در دریافت گزارش تحویل.',
        ];
        if (isset($local[$code])) {
            return ['ok' => false, 'status' => null, 'message' => $local[$code]];
        }
        if (in_array($code, [108, 109, 110], true)) {
            return ['ok' => false, 'status' => null, 'message' => melipayamakErrorText($code)];
        }
        return ['ok' => true, 'status' => $code, 'message' => smsDeliveryText($code)];
    }
}

/**
 * نمایش پیامک‌های دریافتی/ارسالی از خود ملی‌پیامک (REST: GetMessages).
 * location: 1 دریافتی · 2 ارسالی · -1 همه · from: شمارهٔ فرستنده (اختیاری) · index: ۰ · count: ≤ ۱۰۰
 * ساختار هر ردیف در مستندات کامل نیست؛ کلیدهای رایج خوانده می‌شوند.
 *
 * @return array ['ok'=>bool, 'rows'=>array, 'message'=>string]
 */
if (!function_exists('smsProviderMessages')) {
    function smsProviderMessages(int $location, string $from, int $index, int $count): array
    {
        if (!in_array($location, [1, 2, -1], true)) {
            return ['ok' => false, 'rows' => [], 'message' => 'مقدار location نامعتبر است (۱، ۲ یا -۱).'];
        }
        $count = max(1, min(100, $count));
        $index = max(0, $index);
        $from = preg_replace('/\D+/', '', $from) ?? '';
        $settings = smsEffectiveSettings();
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'rows' => [], 'message' => 'پنل پیامک غیرفعال است.'];
        }
        $fields = [
            'username' => (string)$settings['api_key'],
            'password' => (string)($settings['password'] ?? ''),
            'location' => $location,
            'from'     => $from,
            'index'    => $index,
            'count'    => $count,
        ];
        $response = melkinoHttpPost(MELIPAYAMAK_MESSAGES_URL, http_build_query($fields));
        if (!is_string($response) || trim($response) === '') {
            return ['ok' => false, 'rows' => [], 'message' => 'پاسخی از سامانه دریافت نشد.'];
        }
        $t = trim($response);
        if (strtolower($t) === 'null') {
            return ['ok' => true, 'rows' => [], 'message' => 'پیامکی یافت نشد (یا دسترسی گزارش برای حساب فعال نیست).'];
        }
        $data = json_decode($t, true);
        if (is_int($data) || is_float($data)) {
            $code = (int)$data;
            return ['ok' => false, 'rows' => [], 'message' => $code < 0 || in_array($code, [108, 109, 110], true) ? melipayamakErrorText($code) : 'کد ناشناخته: ' . $code];
        }
        if (!is_array($data)) {
            return ['ok' => false, 'rows' => [], 'message' => 'پاسخ ناشناخته از سامانه: ' . mb_substr($t, 0, 80)];
        }
        $list = $data;
        foreach (['ReturnValue', 'Value', 'MessagesBL'] as $k) {
            if (array_key_exists($k, $data)) {
                $list = $data[$k];
                break;
            }
        }
        if (!is_array($list)) {
            $list = [];
        }
        $pick = static function (array $r, array $keys): string {
            foreach ($keys as $k) {
                if (isset($r[$k]) && $r[$k] !== '') {
                    return is_scalar($r[$k]) ? (string)$r[$k] : '';
                }
            }
            return '';
        };
        $rows = [];
        foreach ($list as $r) {
            if (!is_array($r)) {
                continue;
            }
            $body = $pick($r, ['Body', 'body', 'Text', 'text', 'Message']);
            $rows[] = [
                'id'     => $pick($r, ['RecId', 'recId', 'Id', 'id']),
                'date'   => $pick($r, ['Date', 'date', 'SendDate', 'sendDate']),
                'from'   => $pick($r, ['From', 'from', 'Number', 'Sender', 'sender']),
                'to'     => $pick($r, ['To', 'to', 'Recipient', 'Mobile', 'mobile']),
                'body'   => mb_substr(trim(str_replace(["\r", "\n"], ' ', $body)), 0, 120),
                'status' => $pick($r, ['Status', 'status', 'State']),
            ];
        }
        return ['ok' => true, 'rows' => $rows, 'message' => 'تعداد: ' . count($rows)];
    }
}

/**
 * خواندن تنظیمات موثر پیامک (پنل ادمین با fallback به ثابت‌ها).
 *
 * @return array ['enabled','api_key'(نام کاربری),'api_url','sender_line',
 *               'provider','password','otp_line','promo_line','otp_body_id']
 */
function smsEffectiveSettings(): array
{
    if (function_exists('melkinoSmsSettings')) {
        $s = melkinoSmsSettings();
        return array_merge([
            'provider'    => 'melipayamak',
            'password'    => defined('SMS_API_PASSWORD') ? (string)SMS_API_PASSWORD : '',
            'otp_line'    => '',
            'promo_line'  => '',
            'otp_body_id' => '',
        ], $s);
    }
    return [
        'enabled'     => defined('SMS_API_KEY') && (string)SMS_API_KEY !== ''
                      && defined('SMS_API_URL') && (string)SMS_API_URL !== '',
        'api_key'     => defined('SMS_API_KEY') ? (string)SMS_API_KEY : '',
        'api_url'     => defined('SMS_API_URL') ? (string)SMS_API_URL : '',
        'sender_line' => defined('SMS_SENDER_LINE') ? (string)SMS_SENDER_LINE : '',
        'provider'    => 'melipayamak',
        'password'    => defined('SMS_API_PASSWORD') ? (string)SMS_API_PASSWORD : '',
        'otp_line'    => '',
        'promo_line'  => '',
        'otp_body_id' => '',
    ];
}

/**
 * پارس پاسخ JSON ملی‌پیامک.
 * @return array ['ok'=>bool,'ret'=>string,'value'=>string,'message'=>string]
 */
if (!function_exists('melipayamakParseResponse')) {
    function melipayamakParseResponse(?string $body): array
    {
        if ($body === null || trim($body) === '') {
            return ['ok' => false, 'ret' => '', 'value' => '', 'message' => 'اتصال به سرویس پیامک برقرار نشد.'];
        }
        $j = json_decode($body, true);
        if (!is_array($j) || !array_key_exists('RetStatus', $j)) {
            // پاسخ غیرمنتظره — هیچ‌وقت «موفق» فرض نمی‌شود
            return ['ok' => false, 'ret' => '', 'value' => '', 'message' => 'پاسخ نامعتبر از سرویس پیامک.'];
        }
        $ret = (string)$j['RetStatus'];
        $value = (string)($j['Value'] ?? '');
        if ($ret === '1' && $value !== '' && $value !== '0') {
            // recId یک عدد یکتا؛ موفقیت
            return ['ok' => true, 'ret' => $ret, 'value' => $value, 'message' => 'پیامک ارسال شد.'];
        }
        return ['ok' => false, 'ret' => $ret, 'value' => $value, 'message' => melipayamakErrorText($ret)];
    }
}

/**
 * ارسال یک متن دلخواه با پنل پیامک (ملی‌پیامک).
 *
 * @param string|null $fromLine جایگذاری خط فرستنده (مثلاً خط تبلیغاتی برای کمپین)
 * @return array ['success'=>bool, 'message'=>string, 'rec_id'=>string]
 */
function smsSendText(string $phone, string $text, ?string $fromLine = null): array
{
    $settings = smsEffectiveSettings();

    if (!$settings['enabled']) {
        return ['success' => false, 'message' => 'پنل پیامک غیرفعال است. از تب «ربات و کانال» آن را فعال کن.', 'rec_id' => ''];
    }
    if ((string)$settings['api_key'] === '') {
        return ['success' => false, 'message' => 'نام کاربری سامانه پیامک تنظیم نشده است.', 'rec_id' => ''];
    }

    $provider = (string)($settings['provider'] ?? 'melipayamak');
    $from = $fromLine !== null && trim($fromLine) !== '' ? trim($fromLine) : (string)($settings['sender_line'] ?? '');

    if ($provider === 'melipayamak') {
        if ($from === '') {
            return ['success' => false, 'message' => 'شماره خط ارسال‌کننده تنظیم نشده است.', 'rec_id' => ''];
        }
        $fields = [
            'username' => (string)$settings['api_key'],
            'password' => (string)($settings['password'] ?? ''),
            'to'       => $phone,
            'from'     => $from,
            'text'     => $text,
            'isFlash'  => 'false',
        ];
        $url = trim((string)($settings['api_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $url = MELIPAYAMAK_SEND_URL;
        }
        $response = melkinoHttpPost($url, http_build_query($fields));
        $parsed = melipayamakParseResponse($response);
        return ['success' => $parsed['ok'], 'message' => $parsed['message'], 'rec_id' => $parsed['ok'] ? $parsed['value'] : ''];
    }

    // حالت سازگاری: سرویس‌دهندهٔ عمومی قبلی (api_url + apikey)
    if ((string)$settings['api_url'] === '') {
        return ['success' => false, 'message' => 'سرویس پیامک تنظیم نشده است.', 'rec_id' => ''];
    }
    $postFields = http_build_query([
        'apikey'   => $settings['api_key'],
        'sender'   => $from,
        'receptor' => $phone,
        'message'  => $text,
    ]);
    $response = melkinoHttpPost($settings['api_url'], $postFields);
    if ($response === null) {
        return ['success' => false, 'message' => 'اتصال به سرویس پیامک برقرار نشد.', 'rec_id' => ''];
    }
    return ['success' => true, 'message' => 'پیامک ارسال شد.', 'rec_id' => ''];
}

/**
 * ارسال کد ورود (OTP) — طبق مقررات، از خط خدماتی/تراکنشی ارسال می‌شود.
 * اگر «کد الگو (bodyId)» تنظیم شده باشد از BaseServiceNumber استفاده می‌شود
 * (متنِ آزاد ندارد و متن پترن توسط سامانه تأیید شده است).
 *
 * @return array ['success'=>bool, 'message'=>string]
 */
function smsSendCode(string $phone, string $code): array
{
    $settings = smsEffectiveSettings();
    $bodyId = trim((string)($settings['otp_body_id'] ?? ''));
    $otpLine = trim((string)($settings['otp_line'] ?? ''));
    // مسیریابی: اگر هدف «کد ورود» خط اختصاصی دارد، همان استفاده می‌شود
    if (!function_exists('smsProgramRouteNumber') && is_file(__DIR__ . '/sms-program.php')) {
        require_once __DIR__ . '/sms-program.php';
    }
    if (function_exists('smsProgramRouteNumber')) {
        $routed = smsProgramRouteNumber('otp', $settings);
        if ($routed !== '') {
            $otpLine = $routed;
        }
    }

    if ($bodyId !== '' && (string)($settings['provider'] ?? 'melipayamak') === 'melipayamak') {
        if (!$settings['enabled']) {
            return ['success' => false, 'message' => 'پنل پیامک غیرفعال است.'];
        }
        $fields = [
            'username' => (string)$settings['api_key'],
            'password' => (string)($settings['password'] ?? ''),
            'to'       => $phone,
            'text'     => $code, // مقدار متغیر الگو
            'bodyId'   => $bodyId,
        ];
        $response = melkinoHttpPost(MELIPAYAMAK_BASE_URL, http_build_query($fields));
        $parsed = melipayamakParseResponse($response);
        return ['success' => $parsed['ok'], 'message' => $parsed['message']];
    }

    // متن کد ورود قابل تغییر از پنل (تب ربات و کانال) — متغیر {code}
    $tpl = trim((string)melkinoBotSetting('sms_otp_template', ''));
    if ($tpl === '') {
        $tpl = 'کد ورود ملکینو: {code} (اعتبار ۲ دقیقه)';
    }
    $text = str_replace('{code}', $code, $tpl);
    return smsSendText($phone, $text, $otpLine !== '' ? $otpLine : null);
}
