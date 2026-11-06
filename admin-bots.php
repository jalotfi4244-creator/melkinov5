<?php require_once __DIR__ . '/config.php'; require_once __DIR__ . '/db_helpers.php';
/**
|--------------------------------------------------------------------------
| تنظیمات ربات‌ها و کانال (پنل ادمین)
|--------------------------------------------------------------------------
| هم UI تب را می‌سازد و هم endpointهای AJAX را سرو می‌دهد:
|   ?action=get              دریافت تنظیمات
|   ?action=save             ذخیره تنظیمات
|   ?action=test_telegram    تست اتصال تلگرام
|   ?action=test_bale        تست اتصال بله
|   ?action=test_channel     تست دسترسی به کانال
|   ?action=test_sms         تست ارسال پیامک
|   ?action=publish_get      دریافت تنظیمات انتشار (فیلدها + متن ثابت)
|   ?action=publish_save     ذخیره تنظیمات انتشار
|   ?action=publish_preview  پیش‌نمایش متن انتشار
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/admin-guard.php';

$melkinoBotAction = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($melkinoBotAction !== '') {
    melkinoRequireAdminJson();
    melkinoRequirePostFor(['bale_phone_setup', 'save', 'test_section', 'record_client_test', 'publish_save', 'test_sms', 'test_telegram', 'test_bale', 'test_eitaa', 'test_channel', 'test_bale_channel'], $melkinoBotAction);
    require_once __DIR__ . '/bot-settings.php';
    require_once __DIR__ . '/telegram.php';
    require_once __DIR__ . '/bot-settings-write.php';
    require_once __DIR__ . '/bot-api-client.php';
    header('Cache-Control: no-store');
    if (!isset($pdo) || !($pdo instanceof PDO)) melkinoAdminJson(['success'=>false,'message'=>'پایگاه داده در دسترس نیست.'],503);
    $sectionActions = ['test_section','test_telegram','test_bale','test_eitaa','test_channel','test_bale_channel','test_sms'];
    if (in_array($melkinoBotAction, $sectionActions, true)) {
        $data = melkinoAdminJsonBody();
        $legacy = [
            'test_telegram'=>['telegram','connection'], 'test_bale'=>['bale','connection'],
            'test_eitaa'=>['eitaa','configuration'], 'test_channel'=>['telegram','channel'],
            'test_bale_channel'=>['bale','channel'], 'test_sms'=>['sms','message'],
        ];
        [$scope,$kind] = $legacy[$melkinoBotAction] ?? [
            is_string($data['scope'] ?? null) ? $data['scope'] : '',
            is_string($data['kind'] ?? null) ? $data['kind'] : 'connection',
        ];
        try {
            if (!isset(melkinoBotScopes()[$scope]) || !in_array($kind,['connection','channel','message','configuration'],true)) throw new MelkinoBotSettingsError('بخش یا نوع آزمون معتبر نیست.');
            $data['confirmed'] = in_array($data['confirmed'] ?? false,[true,1,'1'],true);
            if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
            $result=melkinoTestBotSection($pdo,$scope,$kind,$data);
            melkinoAdminJson($result,$result['success']?200:502);
        } catch (MelkinoBotSettingsError $e) {
            $log=melkinoBotLog($pdo,$scope,'test_'.$kind,'failed',$e->getMessage());
            melkinoAdminJson(['success'=>false,'scope'=>$scope,'message'=>$e->getMessage(),'field_errors'=>$e->fields,'log'=>$log],422);
        } catch (Throwable $e) {
            $message='آزمون انجام نشد؛ تنظیمات سرویس و اتصال سرور را بررسی کنید.';
            $log=melkinoBotLog($pdo,$scope,'test_'.$kind,'failed',$message);
            melkinoAdminJson(['success'=>false,'scope'=>$scope,'message'=>$message,'log'=>$log],503);
        }
    }

    if($melkinoBotAction==='bale_phone_setup'){
        require_once __DIR__.'/bale-contact-lib.php';
        $data=melkinoAdminJsonBody();
        $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??($data['csrf_token']??'');
        if(!is_string($csrf)||!hash_equals(melkinoCsrfToken(),$csrf))melkinoAdminJson(['success'=>false,'message'=>'توکن امنیتی معتبر نیست.'],403);
        try{
            if(!is_string($data['base']??null))throw new InvalidArgumentException('نشانی سایت معتبر نیست.');
            $mode=$data['mode']??'webhook';
            if(!in_array($mode,['webhook','polling'],true))throw new InvalidArgumentException('روش دریافت معتبر نیست.');
            if($mode==='polling')mkBaleConfigurePolling($pdo,($data['replace']??false)===true);
            else mkBaleConfigure($pdo,$data['base'],($data['replace']??false)===true);
            $phoneStatus=mkBalePublicStatus($pdo);
            $log=melkinoBotLog($pdo,'bale','phone_setup','success',$mode==='polling'?'روش دریافت مستقیم شماره در سرور تنظیم شد؛ ورود واقعی هنوز باید آزمایش شود.':'روش دریافت شماره با وبهوک در سرور تنظیم شد؛ تحویل تماس هنوز باید آزمایش شود.');
            melkinoAdminJson(['success'=>true,'bale_phone'=>$phoneStatus,'log'=>$log,'message'=>$mode==='polling'?'دریافت مستقیم از API بله تنظیم شد و وبهوک غیرفعال است. این نتیجه هنوز تأیید ورود واقعی نیست؛ اکنون اشتراک شماره را داخل بله آزمایش کنید.':'آدرس وبهوک در بله ثبت شد؛ دریافت واقعی پیام هنوز تأیید نشده است. وبهوک روی InfinityFree رایگان مسدود می‌شود؛ برای آن هاست روش دریافت مستقیم را انتخاب کنید.']);
        }catch(InvalidArgumentException $e){melkinoAdminJson(['success'=>false,'message'=>$e->getMessage()],409);}
        catch(Throwable $e){melkinoAdminJson(['success'=>false,'message'=>'تنظیم دریافت شماره تأیید نشد. توکن و دسترسی شبکهٔ هاست را بررسی و دوباره تنظیم کنید؛ موفقیت فرض نشده است.'],503);}
    }

    switch ($melkinoBotAction) {
        case 'get':
            $logs=[];
            foreach (array_keys(melkinoBotScopes()) as $scope) $logs[$scope]=melkinoBotLogs($pdo,$scope);
            require_once __DIR__.'/bale-contact-lib.php';
            melkinoAdminJson(['success'=>true,'bale_phone'=>mkBalePublicStatus($pdo),'settings'=>melkinoBotPublicSettings($pdo),'revisions'=>melkinoBotRevisions($pdo),'logs'=>$logs]);

        case 'logs':
            $scope=is_string($_GET['scope']??null)?$_GET['scope']:'';
            if (!isset(melkinoBotScopes()[$scope]) && $scope!=='all') melkinoAdminJson(['success'=>false,'message'=>'بخش نامعتبر است.'],422);
            melkinoAdminJson(['success'=>true,'logs'=>melkinoBotLogs($pdo,$scope,20)]);

        case 'record_client_test':
            $data=melkinoAdminJsonBody();
            $scope=is_string($data['scope']??null)?$data['scope']:'';
            if (!in_array($scope,['telegram','bale'],true)) melkinoAdminJson(['success'=>false,'message'=>'بخش گزارش معتبر نیست.'],422);
            $ok=($data['ok']??false)===true;
            $message='گزارش مرورگر: آزمون '.melkinoBotScopeLabel($scope).($ok?' موفق بود.':' ناموفق بود.').' این گزارش تأیید مستقل سرور نیست.';
            $log=melkinoBotLog($pdo,$scope,'test_connection',$ok?'success':'failed',$message,'browser_report');
            melkinoAdminJson(['success'=>$log!==null,'message'=>$message,'log'=>$log]);

        case 'save':
            $data=melkinoAdminJsonBody();
            $scope=is_string($data['scope']??null)?$data['scope']:'all';
            try {
                melkinoAdminJson(melkinoSaveBotSection($pdo,$data,$scope));
            } catch (MelkinoBotSettingsError $e) {
                $log=melkinoBotLog($pdo,$scope,'save','failed',$e->getMessage());
                melkinoAdminJson(['success'=>false,'scope'=>$scope,'message'=>$e->getMessage(),'field_errors'=>$e->fields,'log'=>$log],$e->getCode()===409?409:422);
            } catch (Throwable $e) {
                error_log('[melkino][bot-settings] transaction failed code='.$e->getCode());
                $message='ذخیره در دیتابیس انجام نشد؛ هیچ‌یک از تغییرات این درخواست ثبت نشد. اطلاعات فرم را نگه دارید و دوباره تلاش کنید.';
                $log=melkinoBotLog($pdo,$scope,'save','failed',$message);
                melkinoAdminJson(['success'=>false,'scope'=>$scope,'message'=>$message,'log'=>$log],503);
            }

        case 'bale_find_chats':

            $token = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
            if ($token === '' || in_array($token, ['توکن_ربات_بله'], true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'ابتدا توکن ربات بله را وارد و ذخیره کن.',
                ], 422);
            }

            $url      = 'https://tapi.bale.ai/bot' . $token . '/getUpdates?limit=100';
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded  = json_decode((string)$response, true);

            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط با بله برقرار نشد';
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'دریافتِ به‌روزرسانی‌ها ناموفق بود: ' . $desc,
                ], 400);
            }

            $chats = [];
            foreach ((array)($decoded['result'] ?? []) as $upd) {
                $candidates = [];
                if (!empty($upd['message']['chat']))             { $candidates[] = $upd['message']['chat']; }
                if (!empty($upd['channel_post']['chat']))        { $candidates[] = $upd['channel_post']['chat']; }
                if (!empty($upd['my_chat_member']['chat']))      { $candidates[] = $upd['my_chat_member']['chat']; }
                if (!empty($upd['edited_message']['chat']))      { $candidates[] = $upd['edited_message']['chat']; }
                if (!empty($upd['edited_channel_post']['chat'])) { $candidates[] = $upd['edited_channel_post']['chat']; }

                foreach ($candidates as $c) {
                    $id = (string)($c['id'] ?? '');
                    if ($id === '' || isset($chats[$id])) { continue; }
                    $title = (string)($c['title'] ?? '');
                    if ($title === '') {
                        $title = trim(((string)($c['first_name'] ?? '')) . ' ' . ((string)($c['last_name'] ?? '')));
                    }
                    $chats[$id] = [
                        'id'       => $id,
                        'type'     => (string)($c['type'] ?? ''),
                        'title'    => $title,
                        'username' => (string)($c['username'] ?? ''),
                    ];
                }
            }

            if (!$chats) {
                melkinoAdminJson([
                    'success' => true,
                    'chats'   => [],
                    'message' => 'هیچ گفتگویی در به‌روزرسانی‌های اخیر پیدا نشد. برای این‌که ربات کانال را '
                               . 'بشناسد: ربات را به کانال اضافه کن و آن را ادمین (با اجازه‌ی ارسال پیام) '
                               . 'کن؛ سپس یک پیام در کانال بفرست و دوباره این دکمه را بزن.',
                ]);
            }

            melkinoAdminJson([
                'success' => true,
                'chats'   => array_values($chats),
                'message' => count($chats) . ' گفتگو پیدا شد. روی شناسه‌ی عددیِ کانال بزن تا در فیلد قرار بگیرد.',
            ]);

        /* ---------------------------------------------------------------
           بررسیِ توکنِ بله: آیا بله این توکن را قبول دارد؟
           ---------------------------------------------------------------
           خطای «Unauthorized» یعنی خودِ توکن پذیرفته نشده است (برخلافِ
           «no such group or user» که مربوط به شناسه‌ی کانال است). این
           action با فراخوانیِ getMe هویتِ ربات را می‌گیرد تا ادمین مطمئن
           شود توکنِ درستی ذخیره شده است. توکن هرگز به‌طور کامل نمایش
           داده نمی‌شود.
        --------------------------------------------------------------- */
        case 'bale_whoami':

            $token = function_exists('melkinoBaleToken') ? (string)melkinoBaleToken() : '';
            if ($token === '' || in_array($token, ['توکن_ربات_بله'], true)) {
                melkinoAdminJson([
                    'success' => false,
                    'message' => 'توکن ربات بله تنظیم نشده است. ابتدا توکن را وارد و ذخیره کن.',
                ], 422);
            }

            $masked = strlen($token) > 12
                ? substr($token, 0, 6) . '…' . substr($token, -4) . ' (طول: ' . strlen($token) . ')'
                : '(کوتاه)';

            $url      = 'https://tapi.bale.ai/bot' . $token . '/getMe';
            $response = function_exists('melkinoHttpPost') ? melkinoHttpPost($url, '') : @file_get_contents($url);
            $decoded  = json_decode((string)$response, true);

            if (!is_array($decoded) || empty($decoded['ok'])) {
                $desc = is_array($decoded) ? ($decoded['description'] ?? 'پاسخ نامعتبر') : 'ارتباط با بله برقرار نشد';
                $hint = '';
                if (stripos($desc, 'unauthorized') !== false) {
                    $hint = ' یعنی بله این توکن را به‌رسمیت نمی‌شناسد. توکن را دوباره از پنل '
                          . 'توسعه‌دهندگانِ بله (یا @botfather_bale) بگیر و اینجا ذخیره کن؛ '
                          . 'توجه کن که توکنِ تلگرام و توکنِ بله دو چیز کاملاً متفاوت‌اند و '
                          . 'جایگزینِ هم نیستند.';
                }
                melkinoAdminJson([
                    'success' => false,
                    'masked'  => $masked,
                    'message' => 'بله توکن را نپذیرفت: ' . $desc . $hint,
                ], 400);
            }

            $r = $decoded['result'] ?? [];
            melkinoAdminJson([
                'success' => true,
                'masked'  => $masked,
                'message' => 'توکن معتبر است. ربات: '
                           . (isset($r['username']) ? '@' . $r['username'] : '(بدون نام کاربری)')
                           . (isset($r['first_name']) ? ' — ' . $r['first_name'] : '')
                           . (isset($r['id']) ? ' — شناسه: ' . $r['id'] : ''),
            ]);

        /* ---------------------------------------------------------------
           تست ارسال پیامک
        --------------------------------------------------------------- */
        // Publishing templates use their existing independent controls.
        case 'publish_get':
            $data = melkinoAdminJsonBody();
            $platform = melkinoPublishPlatform((string)($data['platform'] ?? $_GET['platform'] ?? 'telegram'));
            $ptype = trim((string)($data['property_type'] ?? ''));
            $trans = trim((string)($data['transaction_type'] ?? ''));
            $settings = melkinoPublishSettings($platform, $ptype, $trans);
            $matrix = melkinoPublishMatrix($platform);
            melkinoAdminJson([
                'success'  => true,
                'platform' => $platform,
                'defs'     => melkinoPublishFieldDefs($ptype !== '' ? $ptype : null, $trans !== '' ? $trans : null),
                'settings' => $settings,
                'combos'   => melkinoPublishCombos(),
                // کلیدهایی که ادمین برایشان تنظیم اختصاصی ذخیره کرده است
                'override_keys' => array_keys($matrix),
            ]);

        case 'publish_save':
            $data = melkinoAdminJsonBody();
            $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??($data['csrf_token']??'');
            if(!is_string($csrf)||!hash_equals(melkinoCsrfToken(),$csrf))melkinoAdminJson(['success'=>false,'message'=>'توکن امنیتی معتبر نیست؛ صفحه را بازخوانی کنید.'],403);
            if(!is_string($data['revision']??null) || !preg_match('/^[a-f0-9]{64}$/D',$data['revision']))melkinoAdminJson(['success'=>false,'message'=>'نسخهٔ فرم قدیمی است؛ صفحه را بازخوانی کنید.'],409);
            $platform = melkinoPublishPlatform((string)($data['platform'] ?? 'telegram'));
            try { $ok = melkinoSavePublishSettings($platform, $data); }
            catch (InvalidArgumentException $e) { melkinoAdminJson(['success'=>false,'message'=>$e->getMessage()],422); }
            catch (Throwable $e) { melkinoAdminJson(['success'=>false,'message'=>$e->getCode()===409?$e->getMessage():'ذخیره انجام نشد؛ دوباره تلاش کنید.'], $e->getCode()===409?409:500); }
            // متن‌های خودکار مقصدهای در صف را با تنظیمات تازه همگام کن
            if ($ok) {
                try { require_once __DIR__ . '/publish-center-lib.php'; global $pdo; if ($pdo instanceof PDO) { melkinoPublishRefreshAutoTexts($pdo); } } catch (Throwable $e) { }
            }
            $isCombo = trim((string)($data['property_type'] ?? '')) !== '' || trim((string)($data['transaction_type'] ?? '')) !== '';
            if (!empty($data['reset_combo'])) {
                $msg = 'تنظیم اختصاصی این ترکیب حذف شد؛ از این پس تنظیمات عمومی اعمال می‌شود.';
            } elseif ($isCombo) {
                $msg = 'تنظیم انتشار برای این ترکیب (نوع ملک/معامله) ذخیره شد.';
            } else {
                $msg = 'تنظیمات انتشار ذخیره شد.';
            }
            melkinoAdminJson([
                'success' => $ok,
                'message' => $ok ? $msg : 'ذخیره‌سازی ناموفق بود.',
            ], $ok ? 200 : 500);

        /* ---------------------------------------------------------------
           پیش‌نمایش متن انتشار با تنظیمات فعلی (روی جدیدترین آگهی منتشرشده،
           یا یک آگهی نمونه اگر هنوز آگهی‌ای وجود ندارد)
        --------------------------------------------------------------- */
        case 'publish_preview':
            if (is_file(__DIR__ . '/consultant_helper.php')) {
                require_once __DIR__ . '/consultant_helper.php';
            }
            $data = melkinoAdminJsonBody();
            $platform = melkinoPublishPlatform((string)($data['platform'] ?? $_GET['platform'] ?? 'telegram'));
            $pType = trim((string)($data['property_type'] ?? ''));
            $pTrans = trim((string)($data['transaction_type'] ?? ''));
            global $pdo;
            $ad = null;
            if ($pdo instanceof PDO) {
                try {
                    // اگر ترکیب خاصی انتخاب شده، اول سعی کن آگهی واقعیِ همان
                    // ترکیب را برای پیش‌نمایش پیدا کنی
                    if ($pType !== '' || $pTrans !== '') {
                        $where = ["status = 'published'"];
                        $params = [];
                        if ($pType !== '') { $where[] = 'property_type = ?'; $params[] = $pType; }
                        if ($pTrans !== '') { $where[] = 'transaction_type = ?'; $params[] = $pTrans; }
                        $st = $pdo->prepare('SELECT * FROM ads WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 1');
                        $st->execute($params);
                        $ad = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    // راند ۱۷: اگر «نوع ملک» انتخاب شده ولی آگهی واقعیِ همان نوع
                    // پیدا نشد، دیگر آگهیِ بی‌ربطِ نوعِ دیگر نشان داده نمی‌شود —
                    // نمونهٔ مخصوصِ همان نوع ساخته می‌شود (پایین‌تر) تا فیلدهای
                    // اختصاصی (pd.*) با مقدار نمونه در پیش‌نمایش دیده شوند.
                    if (!$ad && $pType === '') {
                        $st = $pdo->query("SELECT * FROM ads WHERE status = 'published' ORDER BY created_at DESC, id DESC LIMIT 1");
                        $ad = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
                    }
                } catch (Throwable $e) {
                    $ad = null;
                }
            }
            $isSample = false;
            if (!$ad) {
                $isSample = true;
                $ad = [
                    'id' => 'AD-0000-0000',
                    'title' => 'آپارتمان ۱۲۰ متری در مرکز شهر',
                    'transaction_type' => $pTrans !== '' ? $pTrans : 'فروش',
                    'property_type' => $pType !== '' ? $pType : 'آپارتمان',
                    'location' => 'خیابان امام',
                    'address' => 'خیابان امام، کوچه ۵',
                    'area' => '120',
                    'rooms' => '3',
                    'floor' => '2',
                    'year' => '1398',
                    'price_sell' => '2800000000',
                    'description' => 'آپارتمانی نورگیر با دسترسی عالی.',
                    'last_name' => 'نام نمونه',
                    'phone' => '09123456789',
                ];
                // نمونهٔ سند و معاوضه (راند ۱۷) تا خطوط 📜/🔄 در پیش‌نمایش دیده شوند
                $ad['deed_type'] = 'طلق';
                $ad['deed_notes'] = '';
                $ad['exchange_interested'] = 1;
                $ad['exchange_types'] = 'آپارتمان,خودرو';
                $ad['exchange_with'] = '';
                // نمونهٔ property_details به تفکیک نوع ملک (راند ۱۷) — تا فیلدهای
                // اختصاصی (pd.*) هم در پیش‌نمایش با مقدار نمونه دیده شوند
                $__pdSamples = [
                    'آپارتمان' => ['area' => '120', 'floor' => '2', 'rooms' => '3', 'year' => '1398', 'flooring' => 'سرامیک', 'cabinet' => 'MDF', 'cooling' => 'اسپیلیت', 'heating' => 'پکیج', 'total_units' => '5'],
                    'ویلا'     => ['land_area' => '250', 'area' => '180', 'rooms' => '3', 'year' => '1399', 'flooring' => 'سرامیک', 'cabinet' => 'MDF', 'cooling' => 'اسپیلیت', 'heating' => 'پکیج'],
                    'زمین'     => ['land_area' => '212', 'land_type' => 'مسکونی', 'land_width' => '11.5', 'land_length' => '18.5', 'land_front_width' => '11.5', 'land_blocks' => '1', 'land_direction' => 'شمالی', 'land_shape' => 'مستطیل', 'land_deed_status' => 'دارد', 'land_deed_type' => 'تک‌برگ', 'land_setback_status' => 'ندارد', 'land_ownership' => 'شش‌دانگ'],
                    'باغ'      => ['garden_area' => '1000', 'tree_types' => 'گردو، بادام', 'tree_age' => '۸ سال', 'irrigation_type' => 'قطره‌ای', 'has_well' => '1', 'has_pond' => '0', 'has_building' => '1', 'building_area' => '60', 'document_type' => 'قولنامه'],
                    'اداری'    => ['office_area' => '90', 'office_floor' => '3', 'office_units_per_floor' => '4', 'office_rooms' => '2', 'office_year' => '1395', 'office_condition' => 'بازسازی‌شده', 'office_orientation' => 'جنوبی', 'office_usage' => 'دفتر کار'],
                    'تجاری'    => ['area' => '45', 'front' => '6', 'flooring' => 'سرامیک', 'wall' => 'رنگ روغن', 'cabinet' => 'ندارد', 'cooling' => 'اسپیلیت', 'heating' => 'برقی', 'location_type' => 'دوبر', 'location_features' => 'بر خیابان اصلی', 'jobs' => 'رستوران، کافه'],
                ];
                $__pdKey = (string)($ad['property_type'] ?? '');
                if (isset($__pdSamples[$__pdKey])) {
                    $ad['property_details'] = json_encode($__pdSamples[$__pdKey], JSON_UNESCAPED_UNICODE);
                }
                // عنوان و فیلدهای پایهٔ نمونه هم با نوع ملک هماهنگ شود
                $__sampleBase = [
                    'زمین'  => ['title' => 'زمین ۲۱۲ متری مسکونی', 'area' => null, 'rooms' => null, 'floor' => null, 'year' => null],
                    'باغ'   => ['title' => 'باغ ۱۰۰۰ متری با خانه باغ', 'area' => null, 'rooms' => null, 'floor' => null, 'year' => null],
                    'اداری' => ['title' => 'واحد اداری ۹۰ متری', 'area' => null, 'rooms' => null, 'floor' => null, 'year' => null],
                    'تجاری' => ['title' => 'مغازه ۴۵ متری دوبر', 'area' => '45', 'rooms' => null, 'floor' => null, 'year' => null],
                    'ویلا'  => ['title' => 'ویلای ۲۵۰ متری', 'area' => '180', 'rooms' => '3', 'floor' => null, 'year' => '1399'],
                ];
                if (isset($__sampleBase[$__pdKey])) {
                    $ad = array_merge($ad, $__sampleBase[$__pdKey]);
                }
                // نمونهٔ رهن/اجاره (راند ۱۸) تا خطوط 💵/🗓️/🔑 در پیش‌نمایش دیده شوند
                if (in_array($ad['transaction_type'], ['رهن کامل'], true)) {
                    $ad['price_sell'] = null;
                    $ad['full_rent_enabled'] = 1;
                    $ad['full_rent'] = '300000000';
                } elseif (in_array($ad['transaction_type'], ['اجاره'], true)) {
                    $ad['price_sell'] = null;
                    $ad['deposit'] = '150000000';
                    $ad['rent_monthly'] = '12000000';
                } elseif (in_array($ad['transaction_type'], ['رهن و اجاره'], true)) {
                    $ad['price_sell'] = null;
                    $ad['deposit'] = '100000000';
                    $ad['rent_monthly'] = '20000000';
                }
                // نمونهٔ وام (فقط برای فروش/پیش‌فروش) تا ادمین شکل پیام را ببیند
                if (in_array($ad['transaction_type'], ['فروش', 'پیش فروش', ''], true)) {
                    $ad['total_price'] = '2800000000';
                    $ad['has_loan'] = 1;
                    $ad['loan_amount'] = '300000000';
                    $ad['loan_type'] = 'وام مسکن';
                    $ad['loan_duration'] = '۱۲ سال';
                    $ad['loan_bank'] = 'بانک مسکن';
                    $ad['loan_installment'] = '5000000';
                    $ad['loan_installments_paid'] = '۲۴';
                    $ad['loan_notes'] = 'وام قابل انتقال به خریدار است.';
                }
            } elseif ($pType !== '' || $pTrans !== '') {
                // آگهی واقعیِ ترکیبِ دیگر پیدا نشد؛ نوع‌ها را با ترکیبِ انتخابی
                // تنظیم کن تا حداقل «تنظیمات فیلدهای» همان ترکیب در پیش‌نمایش
                // اعمال شود (سازندهٔ پیام بر اساس نوعِ خودِ آگهی تصمیم می‌گیرد)
                if ($pType !== '') { $ad['property_type'] = $pType; }
                if ($pTrans !== '') { $ad['transaction_type'] = $pTrans; }
            }
            $text = function_exists('melkinoAdMessageText')
                ? melkinoAdMessageText($ad, false, $platform, isset($data['fields']) && is_array($data['fields']) ? array_values(array_filter($data['fields'],'is_string')) : null, isset($data['header'],$data['footer']) && is_string($data['header']) && is_string($data['footer']) ? ['header'=>mb_substr($data['header'],0,2000),'footer'=>mb_substr($data['footer'],0,2000)] : null)
                : (string)($ad['title'] ?? '');
            melkinoAdminJson([
                'success'   => true,
                'platform'  => $platform,
                'text'      => $text,
                'is_sample' => $isSample,
                'ad_title'  => (string)($ad['title'] ?? ''),
            ]);

        default:
            melkinoAdminJson(['success' => false, 'message' => 'عمل نامعتبر'], 400);
    }
}

// ---------------------------------------------------------------
// خروجی HTML تب
// ---------------------------------------------------------------
?>
<?php require_once __DIR__ . '/admin-bot-controls-view.php'; ?>
<!-- =========================================================
     روش‌های ورود کاربران
     =========================================================
     ادمین انتخاب می‌کند کاربران با کدام روش‌ها بتوانند وارد شوند.
     روشِ غیرفعال، هم از صفحه‌ی ورود حذف می‌شود و هم اندپوینتِ
     احراز هویتِ مربوطه در سمت سرور ۴۰۳ برمی‌گرداند. -->
<div class="admin-card" data-bot-section="methods">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('lock') ?> روش‌های ورود کاربران</span>
    </div>

    <div style="padding:0 16px 16px;">
        <div class="admin-field-help" style="margin-bottom:10px;">
            روش‌های غیرفعال، دکمه‌شان در صفحهٔ ورود (<span dir="ltr">login.php</span>) نمایش داده نمی‌شود
            و تلاش برای ورود از آن مسیر هم در سمت سرور رد می‌شود.
            <b>حداقل یکی از روش‌ها باید فعال بماند.</b>
        </div>

        <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e2e2;border-radius:10px;margin-bottom:8px;cursor:pointer;">
            <input disabled type="checkbox" id="loginTelegramEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
            <span style="font-size:14px;"><?= melkinoSvgIcon('send') ?> ورود با تلگرام <span style="color:var(--text-muted);font-size:12px;">(مینی‌اپ ربات تلگرام)</span></span>
        </label>

        <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e2e2;border-radius:10px;margin-bottom:8px;cursor:pointer;">
            <input disabled type="checkbox" id="loginBaleEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
            <span style="font-size:14px;"><?= melkinoSvgIcon('chat') ?> ورود با بله <span style="color:var(--text-muted);font-size:12px;">(مینی‌اپ ربات بله)</span></span>
        </label>

        <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e2e2;border-radius:10px;margin-bottom:8px;cursor:pointer;">
            <input disabled type="checkbox" id="loginEitaaEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
            <span style="font-size:14px;">ورود با ایتا <span style="color:var(--text-muted);font-size:12px;">(برنامک رسمی؛ شماره از خود پیام‌رسان، بدون ورود دستی)</span></span>
        </label>

        <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e2e2;border-radius:10px;cursor:pointer;">
            <input disabled type="checkbox" id="loginSmsEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
            <span style="font-size:14px;"><?= melkinoSvgIcon('phone') ?> ورود با پیامک <span style="color:var(--text-muted);font-size:12px;">(شماره موبایل + کد یک‌بارمصرف)</span></span>
        </label>

        <div class="admin-field-help" style="margin-top:8px;">
            برای ورود پیامکی، کد ابتدا از طریق ربات تلگرام/بله (اگر شماره قبلاً شناخته شود)،
            بعد از پنل پیامک ارسال می‌شود؛ در نبود درگاه، ارسال ناموفق گزارش می‌شود.
            برای اعمال تیک‌ها، دکمهٔ «ذخیره روش‌های ورود» در همین کارت را بزنید؛ ذخیرهٔ هر ربات هم تیک تغییرکردهٔ خودش را ثبت می‌کند.
        </div>
    </div>
    <?php melkinoBotSectionControls('methods'); ?>
</div>

<div class="admin-card" data-bot-section="telegram">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('bot') ?> ربات تلگرام</span>
        
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">توکن ربات تلگرام</label>
        <input disabled type="password" id="botTelegramToken" class="admin-input" dir="ltr" placeholder="123456789:AAE..." autocomplete="off">
        <div class="admin-field-help">از @BotFather دریافت می‌شود. پس از ذخیره، امضای ورود کاربران با این توکن بررسی می‌شود.</div>

        <label class="admin-field-label">شناسه کانال</label>
        <input disabled type="text" id="botTelegramChannel" class="admin-input" dir="ltr" placeholder="@melkino_shahrood">
        <div class="admin-field-help">آگهی‌های منتشرشده می‌توانند به این کانال ارسال شوند.</div>

        <label class="admin-field-label">نام کاربری ربات (بدون @)</label>
        <input disabled type="text" id="botTelegramUsername" class="admin-input" dir="ltr" placeholder="melkino_bot">
        <label class="admin-field-label" for="botTelegramMiniappUrl">پیوند اجرای مستقیم برنامک تلگرام (اختیاری)</label>
        <input disabled type="url" id="botTelegramMiniappUrl" class="admin-input" dir="ltr" placeholder="https://t.me/your_bot?startapp">
        <div class="admin-field-help">پیوند رسمی را از تنظیمات بازو/ربات کپی کنید. بدون این مقدار، پیوند مینی‌اپ اصلی و صفحهٔ ربات بر اساس نام کاربری ساخته می‌شوند.</div>
        <label class="admin-field-label" for="botTelegramEntryUrl">آدرس مقصد برای ثبت در تنظیمات مینی‌اپ تلگرام</label>
        <input disabled type="url" id="botTelegramEntryUrl" class="admin-input" dir="ltr" readonly value="telegram-app.php">
        <div class="admin-field-help">همین نشانی نهایی HTTPS را ثبت کنید؛ ورود همهٔ پیام‌رسان‌ها ظاهر و منطق شروع یکسان دارد و پیش از دریافت هویت ریدایرکت نمی‌کند.</div>

        <div class="admin-field-help">برای ساخت دکمه‌ی «ورود از طریق تلگرام» در مرورگر معمولی استفاده می‌شود.</div>

        
    </div>
    <?php melkinoBotSectionControls('telegram'); ?>
</div>

<div class="admin-card" data-bot-section="bale">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('chat') ?> ربات بله</span>
        
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">توکن ربات بله</label>
        <input disabled type="password" id="botBaleToken" class="admin-input" dir="ltr" placeholder="387417012:..." autocomplete="off">
        <div class="admin-field-help">از پنل توسعه‌دهندگان بله (یا @botfather_bale) دریافت می‌شود. خالی بگذار تا مقدار قبلی حفظ شود؛ برای پاک‌کردن «-» وارد کن.</div>

        <label class="admin-field-label">شناسه کانال بله</label>
        <input disabled type="text" id="botBaleChannel" class="admin-input" dir="ltr" placeholder="@melkino">
        <div class="admin-field-help">
            می‌تواند با @ (مانند <span dir="ltr">@melkino</span>) یا شناسه‌ی عددی کانال باشد.
            ربات باید در کانال، ادمین با اجازه‌ی ارسال باشد.
        </div>

        <label class="admin-field-label">نام کاربری ربات (بدون @)</label>
        <input disabled type="text" id="botBaleUsername" class="admin-input" dir="ltr" placeholder="melkino_bot">
        <label class="admin-field-label" for="botBaleMiniappUrl">پیوند اجرای مستقیم برنامک بله (اختیاری)</label>
        <input disabled type="url" id="botBaleMiniappUrl" class="admin-input" dir="ltr" placeholder="https://ble.ir/your_bot?startapp">
        <div class="admin-field-help">پیوند رسمی را از تنظیمات بازو/ربات کپی کنید. بدون این مقدار، پیوند مینی‌اپ اصلی و صفحهٔ ربات بر اساس نام کاربری ساخته می‌شوند.</div>
        <label class="admin-field-label" for="botBaleEntryUrl">آدرس مقصد برای ثبت در تنظیمات مینی‌اپ بله</label>
        <input disabled type="url" id="botBaleEntryUrl" class="admin-input" dir="ltr" readonly value="bale-app.php">
        <div class="admin-field-help">همین نشانی نهایی HTTPS را ثبت کنید؛ ورود همهٔ پیام‌رسان‌ها ظاهر و منطق شروع یکسان دارد و پیش از دریافت هویت ریدایرکت نمی‌کند.</div>


        
        <div class="admin-field-help" style="margin-top:6px">
            اگر هنگامِ انتشار خطای
            <span dir="ltr">Unauthorized</span>
            می‌بینی، یعنی خودِ توکن پذیرفته نشده است (نه شناسه‌ی کانال). با
            این دکمه مشخص می‌شود بله این توکن را قبول دارد یا نه، و کدام
            ربات به آن وصل است.
        </div>

        <button type="button" class="btn-secondary" style="padding:8px 16px;font-size:13px;margin-top:8px;" onclick="findBaleChats()">
            <?= melkinoSvgIcon('search') ?> یافتن شناسه‌ی کانال (از پیام‌های اخیرِ ربات)
        </button>
        <div class="admin-field-help" style="margin-top:6px">
            اگر هنگامِ انتشار خطای
            <span dir="ltr">no such group or user</span>
            می‌بینی، یعنی ربات این شناسه را نمی‌شناسد. با این دکمه فهرستِ
            گفتگوهایی که ربات اخیراً دیده را ببین و شناسه‌ی <b>عددیِ</b> کانال را
            انتخاب کن؛ شناسه‌ی عددی از آیدیِ @ بسیار مطمئن‌تر است.
        </div>
        <div id="baleChatsBox" style="display:none;margin-top:10px;padding:10px;border:1px solid #e2e2e2;border-radius:10px;background:#fafafa"></div>

        
    </div>
    <?php melkinoBotSectionControls('bale'); ?>
</div>

<div class="admin-card" data-bot-section="eitaa">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('bot') ?> برنامه / برنامک ایتا</span>
        
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">توکن برنامه ایتا</label>
        <input disabled type="password" id="botEitaaToken" class="admin-input" dir="ltr" placeholder="123456789:AAE..." autocomplete="off">
        <div class="admin-field-help">
            از پنل <b>ایتایار</b> برای همان برنامه‌ای که برنامک ملکینو را باز می‌کند بگیر.
            این توکن برای اعتبارسنجی <span dir="ltr">initData</span> سمت سرور لازم است.
            خالی بگذار تا مقدار قبلی حفظ شود؛ برای پاک‌کردن «-» وارد کن.
        </div>

        <label class="admin-field-label">نام کاربری برنامه ایتا (بدون @)</label>
        <input disabled type="text" id="botEitaaUsername" class="admin-input" dir="ltr" placeholder="melkino_bot">
        <label class="admin-field-label" for="botEitaaMiniappUrl">پیوند مستقیم برنامک در ایتا</label>
        <input disabled type="url" id="botEitaaMiniappUrl" class="admin-input" dir="ltr" placeholder="https://eitaa.com/your_app/your_miniapp" autocomplete="off">
        <div class="admin-field-help">پیوند برنامک را از ایتایار کپی کنید. این پیوند با آدرس تارنمای شما متفاوت است.</div>
        <label class="admin-field-label" for="botEitaaEntryUrl">آدرس تارنما / مقصد برای ثبت در ایتایار</label>
        <input disabled type="url" id="botEitaaEntryUrl" class="admin-input" dir="ltr" readonly value="eitaa-app.php">
        <div class="admin-field-help">نشانی کامل بالا را با HTTPS در ایتایار ثبت کنید. این صفحه پیش از ورود ریدایرکت نمی‌کند و فقط SDK رسمی ایتا را بارگذاری می‌کند.</div>
        <div class="admin-field-help">
            برای لینک «باز کردن در ایتا» وقتی کاربر صفحهٔ ورود را در مرورگر معمولی باز کند.
            آدرس برنامک را در ایتایار روی همین سایت تنظیم کن و از داخل برنامه بازش کن تا ورود خودکار شود.
        </div>
    </div>
    <?php melkinoBotSectionControls('eitaa'); ?>
</div>

<div class="admin-card" data-bot-section="eitaa_channel">
    <div class="card-header"><span class="card-title">کانال ایتا — API ایتایار</span></div>
    <div style="padding:0 16px 16px;">
        <p class="admin-field-help">این بخش مخصوص <b>انتشار در کانال</b> است؛ توکن API ایتایار با توکن ورود برنامک یکی نیست. کانال را در پنل ایتایار اضافه کنید و برنامهٔ <span dir="ltr">@sender</span> را طبق راهنمای ایتایار مدیر کانال کنید.</p>
        <label class="admin-field-label" for="botEitaaChannelToken">توکن API ارسال به کانال</label>
        <input disabled type="password" id="botEitaaChannelToken" class="admin-input" dir="ltr" autocomplete="new-password" placeholder="توکن کامل از بخش API ایتایار">
        <div class="admin-field-help">توکن کامل را بدون تغییر پیشوند کپی کنید. خالی یعنی حفظ توکن ذخیره‌شده؛ برای پاک‌کردن مقدار دیتابیس «-» وارد کنید.</div>
        <label class="admin-field-label" for="botEitaaChannel">شناسهٔ کانال در ایتایار یا نام کانال</label>
        <input disabled type="text" id="botEitaaChannel" class="admin-input" dir="ltr" placeholder="1404 یا @your_channel" autocomplete="off">
        <div class="admin-field-help">تست اتصال، اعتبار API را بررسی می‌کند؛ آزمون ارسال فقط با تأیید شما یک پیام واقعی در همین کانال می‌فرستد. انتشار آگهی از تب «آگهی‌ها» و پس از پیش‌نمایش انجام می‌شود.</div>
    </div>
    <?php melkinoBotSectionControls('eitaa_channel'); ?>
</div>


<div class="admin-card" data-bot-section="publish_channels">
    <div class="card-header">
        <span class="card-title">کانال‌های انتشار (چند کانالی)</span>
    </div>
    <div style="padding:0 16px 16px;">
        <p class="admin-field-help">
            برای هر پیام‌رسان می‌توانید چند کانال ثبت کنید و هنگام انتشار آگهی، کانال مقصد را انتخاب کنید.
            کانال پیش‌فرضِ تنظیمات همچنان در «اتصال به پیام‌رسان‌ها» تعیین می‌شود؛ این فهرست فقط برای انتخاب مقصد است.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:12px 0;">
            <div>
                <div class="admin-field-label">تلگرام</div>
                <div id="pubChList-telegram"></div>
            </div>
            <div>
                <div class="admin-field-label">بله</div>
                <div id="pubChList-bale"></div>
            </div>
            <div>
                <div class="admin-field-label">ایتا</div>
                <div id="pubChList-eitaa"></div>
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
            <div>
                <label class="admin-field-label" for="pubChPlatform">پیام‌رسان</label>
                <select id="pubChPlatform" class="admin-input" style="min-width:110px;">
                    <option value="telegram">تلگرام</option>
                    <option value="bale">بله</option>
                    <option value="eitaa">ایتا</option>
                </select>
            </div>
            <div>
                <label class="admin-field-label" for="pubChChannel">شناسهٔ کانال</label>
                <input type="text" id="pubChChannel" class="admin-input" dir="ltr" placeholder="@channel_name یا 1404" autocomplete="off" style="min-width:180px;">
            </div>
            <div>
                <label class="admin-field-label" for="pubChTitle">عنوان (اختیاری)</label>
                <input type="text" id="pubChTitle" class="admin-input" placeholder="مثلاً کانال ویلاها" autocomplete="off" style="min-width:170px;">
            </div>
            <button type="button" id="pubChAddBtn" class="admin-btn primary">افزودن کانال</button>
        </div>
        <div id="pubChStatus" class="admin-field-help" style="margin-top:8px;"></div>
    </div>
</div>

<div class="admin-card" data-bot-section="proxy">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('globe') ?> پروکسی ارتباط با پیام‌رسان‌ها</span>
    </div>

    <div style="padding:0 16px 16px;">
        <label class="admin-field-label">نشانی پروکسی (اختیاری)</label>
        <input disabled type="text" id="botProxy" class="admin-input" dir="ltr" placeholder="http://user:pass@1.2.3.4:8080" autocomplete="off">
        <div class="admin-field-help">
            اگر هاست شما به <span dir="ltr">api.telegram.org</span> دسترسی ندارد
            (برای هاست‌های داخل ایران معمول است)، نشانی یک پروکسی را اینجا وارد کنید تا
            تستِ اتصال و انتشارِ آگهی از طریق آن انجام شود. از
            <span dir="ltr">http://</span> و <span dir="ltr">socks5://</span>
            پشتیبانی می‌شود. در غیر این صورت این فیلد را خالی بگذارید.
        </div>
    </div>
    <?php melkinoBotSectionControls('proxy'); ?>
</div>

<div class="admin-card" data-bot-section="sms">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('phone') ?> پنل پیامک</span>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);cursor:pointer;">
            <input disabled type="checkbox" id="smsEnabled" style="width:18px;height:18px;accent-color:var(--primary);">
            فعال باشد
        </label>
    </div>

    <div style="padding:0 16px 16px;">
        <div class="admin-field-help" style="margin-bottom:10px;">
            برای ارسال پیامک واقعی، پنل و اطلاعات اتصال باید معتبر باشند؛ بدون درگاه ارسال، موفقیت یا تحویل پیامک فرض نمی‌شود.
        </div>

        <label class="admin-field-label">سرویس‌دهنده</label>
        <select disabled id="smsProvider" class="admin-input" style="max-width:260px;">
            <option value="melipayamak">ملی‌پیامک (rest.payamak-panel.com)</option>
            <option value="generic">سرویس عمومی دیگر (api_key + api_url)</option>
        </select>
        <div class="admin-field-help">اتصال برنامهٔ پیامک ملکینو برای ملی‌پیامک تنظیم شده است.</div>

        <label class="admin-field-label">نشانی API سرویس پیامک</label>
        <input disabled type="text" id="smsApiUrl" class="admin-input" dir="ltr" placeholder="https://rest.payamak-panel.com/api/SendSMS/SendSMS" autocomplete="off">
        <div class="admin-field-help">برای ملی‌پیامک خالی بگذار (آدرس رسمی خودکار استفاده می‌شود).</div>

        <label class="admin-field-label">نام کاربری سامانه پیامک</label>
        <input disabled type="text" id="smsApiKey" class="admin-input" dir="ltr" placeholder="..." autocomplete="off">
        <div class="admin-field-help">نام کاربری پنل ملی‌پیامک. خالی = حفظ مقدار قبلی؛ پاک‌کردن: «-».</div>

        <label class="admin-field-label">رمز عبور سامانه پیامک</label>
        <input disabled type="password" id="smsApiPassword" class="admin-input" dir="ltr" placeholder="..." autocomplete="new-password">
        <div class="admin-field-help">رمز پنل ملی‌پیامک. خالی = حفظ مقدار قبلی؛ پاک‌کردن: «-».</div>

        <label class="admin-field-label">خط خدماتی (OTP و اطلاع‌رسانی)</label>
        <input disabled type="text" id="smsSenderLine" class="admin-input" dir="ltr" placeholder="مثلاً 5000...">
        <div class="admin-field-help">خط اصلی ارسال: کد ورود، جستجوی ذخیره‌شده، انطباق درخواست‌ها و هشدارها.</div>

        <label class="admin-field-label">خط OTP (اختیاری)</label>
        <input disabled type="text" id="smsOtpLine" class="admin-input" dir="ltr" placeholder="خالی = همان خط خدماتی">
        <div class="admin-field-help">اگر برای کد ورود خط جداگانه داری؛ در غیر این صورت خالی بگذار.</div>

        <label class="admin-field-label">کد الگوی OTP — bodyId (اختیاری)</label>
        <input disabled type="text" id="smsOtpBodyId" class="admin-input" dir="ltr" placeholder="مثلاً 100024...">
        <div class="admin-field-help">اگر در ملی‌پیامک پترن «کد تایید» ساختی، کدش را بگذار تا ارسال با الگوی تأییدشده انجام شود. در این حالت متن از سامانه می‌آید و قالب زیر استفاده نمی‌شود.</div>

        <label class="admin-field-label">متن پیامک کد ورود</label>
        <textarea disabled id="smsOtpTemplate" class="admin-input" rows="2" placeholder="کد ورود ملکینو: {code} (اعتبار ۲ دقیقه)"></textarea>
        <div class="admin-field-help">متغیر {code} جای کد می‌نشیند. خالی = متن پیش‌فرض.</div>

        <label class="admin-field-label">خط تبلیغاتی (کمپین‌ها)</label>
        <input disabled type="text" id="smsPromoLine" class="admin-input" dir="ltr" placeholder="مثلاً 3000...">
        <div class="admin-field-help">طبق مقررات، کمپین‌های تبلیغاتی فقط از خط تبلیغاتی و فقط ۸ صبح تا ۲۲ شب ارسال می‌شوند.</div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center;">
            <input disabled type="text" id="smsTestPhone" class="admin-input" dir="ltr" placeholder="09123456789" style="max-width:170px;">
            
        </div>
        <div class="admin-field-help" style="margin-top:6px">ابتدا تنظیمات همین بخش را ذخیره کنید؛ آزمون پیامک از تنظیمات ذخیره‌شده استفاده می‌کند.</div>
    </div>
    <?php melkinoBotSectionControls('sms'); ?>
</div>

<div class="admin-field-help" style="padding:12px 16px;">ذخیره و تست هر بخش مستقل است؛ خطای ربات دیگر مانع ذخیرهٔ این بخش نمی‌شود. توکن‌ها در لاگ‌ها نمایش داده نمی‌شوند.<span id="botSettingsStatus" hidden></span></div>

<div class="admin-card" id="publishSharedPanel">
    <div class="card-header"><span class="card-title">محتوای مشترک انتشار در کانال‌ها</span></div>
    <div style="padding:0 16px 16px">
        <p class="admin-field-help">یک قالب مشترک برای <b>تلگرام، بله و ایتا</b>. فیلدها و متن ابتدا/انتهای هر تب در هر سه پیام‌رسان یکسان است. تنظیم عمومی، مبنای انواع بدون قالب اختصاصی است. اطلاعات تماس ثبت‌کننده فقط با انتخاب صریح شما منتشر می‌شود.</p>
        <div id="publishPropertyTabs" role="tablist" aria-label="نوع ملک" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0"></div>
        <div id="publishSharedEditor" role="tabpanel">
            <label>نوع معامله <select id="publishTransShared" class="admin-input"></select></label>
            <button type="button" id="publishResetShared" class="btn-secondary" hidden>حذف تنظیم اختصاصی</button>
            <button type="button" id="publishReloadShared" class="btn-secondary">بازخوانی</button>
            <p id="publishComboStatusShared" class="admin-field-help"></p>
            <div id="publishFieldsShared" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin:12px 0">در حال بارگذاری…</div>
            <label class="admin-field-label" for="publishHeaderShared">متن ابتدای پیام این تب (اختیاری)</label>
            <textarea id="publishHeaderShared" maxlength="2000" rows="2" class="admin-input" style="display:block;width:100%;box-sizing:border-box"></textarea>
            <label class="admin-field-label" for="publishFooterShared">متن انتهای پیام این تب (اختیاری)</label>
            <textarea id="publishFooterShared" maxlength="2000" rows="2" class="admin-input" style="display:block;width:100%;box-sizing:border-box"></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                <button type="button" id="publishSaveShared" class="btn-primary">ذخیره برای هر سه پیام‌رسان</button>
                <button type="button" id="publishPreviewButtonShared" class="btn-secondary">پیش‌نمایش تغییرات فعلی</button>
                <span id="publishStatusShared" role="status" aria-live="polite"></span>
            </div>
            <pre id="publishPreviewShared" hidden dir="rtl" style="white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;font-size:13px;line-height:2;background:#e7f2ec;color:#173c31;border:1px solid #cadfd3;border-radius:16px 16px 4px 16px;padding:16px;max-height:400px;overflow:auto"></pre>
        </div>
    </div>
</div>
<div class="admin-card">
    <div class="card-header">
        <span class="card-title"><?= melkinoSvgIcon('compass') ?> راهنمای هاست‌هایی که دسترسیِ خروجی ندارند</span>
    </div>
    <div style="padding:14px 16px;color:var(--text-muted);font-size:13px;line-height:2;">
        <p style="margin:0 0 10px">
            بعضی هاست‌ها — از جمله <b>InfinityFree</b> — ارتباطِ خروجیِ سرور با
            <span dir="ltr">api.telegram.org</span> را به‌طور کامل مسدود کرده‌اند.
            در این حالت هر کاری که «سرور» انجام دهد با خطا مواجه می‌شود،
            <b>حتی وقتی توکن کاملاً سالم است</b>.
        </p>
        <p style="margin:0 0 10px">
            برای حل این مشکل، سامانه به‌صورت خودکار ابتدا از
            <b>مرورگرِ خودِ شما</b> با تلگرام/بله ارتباط برقرار می‌کند و فقط اگر
            مرورگر هم موفق نشد، از سرور امتحان می‌کند. بنابراین:
        </p>
        <ul style="margin:0 18px 10px;padding:0">
            <li>اگر مرورگر شما به تلگرام دسترسی دارد (مثلاً فیلترشکن روشن است)
                → <b>همه چیز کار می‌کند</b>: تست اتصال و انتشار آگهی.</li>
            <li>اگر مرورگر شما هم به تلگرام دسترسی ندارد
                → یک پروکسی در کارتِ بالا ثبت کنید، یا سایت را به هاستی منتقل کنید
                که ارتباطِ خروجیِ آزاد داشته باشد.</li>
        </ul>
        <p style="margin:0">
            برای این‌که بفهمید دقیقاً کدام حالت برقرار است، به تب
            <b>«عیب‌یاب»</b> بروید و دکمهٔ <b>«شروع تست»</b> را بزنید. در گروه
            «ربات و کانال»، موردِ <b>«ارتباط مستقیم از مرورگر»</b> تعیین‌کننده است.
        </p>
    </div>
</div>
