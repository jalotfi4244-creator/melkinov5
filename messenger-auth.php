<?php
/** Unified browser contract. Identification is read-only; only existing verified auth handlers grant identity. */
declare(strict_types=1);
require_once __DIR__.'/messenger-login-lib.php';
melkinoMessengerSessionStart();
require_once __DIR__.'/config.php';
require_once __DIR__.'/bot-settings.php';
require_once __DIR__.'/db_helpers.php';
require_once __DIR__.'/security-lib.php';
require_once __DIR__.'/unified-phone-lib.php';
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
melkinoMessengerSessionCookie();
$reply=static function(array $d,int $status=200):never{http_response_code($status);echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;};
try {
    if(!isset($pdo)||!($pdo instanceof PDO))$reply(['success'=>false,'code'=>'storage','message'=>'اتصال پایگاه داده در دسترس نیست.'],503);
    if(empty($_SESSION['mk_login_probe']))$_SESSION['mk_login_probe']=bin2hex(random_bytes(16));
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $action=$_GET['action']??'';
    if($method==='GET'&&in_array($action,['bootstrap','status'],true)){
        $u=melkinoMessengerCurrentUser($pdo);
        $done=$_SESSION['mk_login_completed']??[];
        $reply(['success'=>true,'authenticated'=>$u!==null,'user_id'=>$u?(int)$u['id']:null,
            'provider'=>melkinoMessengerContext(),'attempt'=>$u?(string)($done['attempt']??''):'',
            'csrf_token'=>melkinoCsrfToken(),'session_probe'=>$_SESSION['mk_login_probe']]);
    }
    if($method!=='POST'){header('Allow: GET, POST');$reply(['success'=>false,'code'=>'method','message'=>'روش درخواست مجاز نیست.'],405);}
    if((int)($_SERVER['CONTENT_LENGTH']??0)>65536)$reply(['success'=>false,'code'=>'too_large','message'=>'حجم دادهٔ ورود نامعتبر است.'],413);
    melkinoCsrfCheck();
    if(function_exists('melkinoRateLimitHit')&&!melkinoRateLimitHit('messenger-start:'.(string)($_SERVER['REMOTE_ADDR']??''),120,60,true)){
        header('Retry-After: 60');$reply(['success'=>false,'code'=>'rate_limit','message'=>'درخواست زیاد است؛ یک دقیقه بعد دوباره تلاش کنید.'],429);
    }
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body))$body=[];
    $action=is_string($body['action']??null)?$body['action']:'';
    melkinoEnsureUserProfileColumns();
    mkUnifiedSchema($pdo);
    if(isset($mkForcedProvider)){ $body['provider']=$mkForcedProvider; $action=$action?:'authenticate'; $body['attempt']=$body['attempt']??bin2hex(random_bytes(16)); }
    if(in_array($action,['phone_contact','phone_poll','phone_name'],true)){
        $pending=mkUnifiedPending();
        if($action==='phone_contact'){
            if(!in_array($pending['provider'],['telegram','eitaa'],true))throw new InvalidArgumentException('شمارهٔ بله فقط از آپدیت امن سرور پذیرفته می‌شود.');
            $rawContact=$body['response']??null;if(!is_string($rawContact))throw new InvalidArgumentException('اطلاعات امضاشدهٔ شماره دریافت نشد.');
            $contact=mkPhoneVerify($rawContact,mkUnifiedToken($pending['provider']),$pending['provider_id'],null,$pending['provider']);
            if($contact['auth_date']<$pending['started_at']-60)throw new InvalidArgumentException('اطلاعات تماس قدیمی است؛ برنامک را دوباره باز کنید.');
            $pending['phone']=$contact['phone'];$pending['proof_hash']=$contact['proof_hash'];$pending['expires_at']=min($pending['expires_at'],$contact['expires_at']);
            mkUnifiedClaimContact($pdo,$pending);
        }elseif($action==='phone_poll'){
            if($pending['provider']!=='bale')throw new InvalidArgumentException('درخواست معتبر نیست.');
            require_once __DIR__.'/bale-contact-lib.php';
            mkBalePoll($pdo,$pending);
            $receipt=mkBaleConsume($pdo,$pending);if(!$receipt)$reply(['success'=>true,'next'=>'waiting_contact','diagnostic'=>mkBaleWaitingDiagnostic($pdo,$pending)]);$pending=$receipt;
        }elseif(empty($pending['phone']))throw new InvalidArgumentException('ابتدا شماره باید تأیید شود.');
        $_SESSION['mk_phone_login']=$pending;
        $names=$action==='phone_name'?['first_name'=>$body['first_name']??'','last_name'=>$body['last_name']??null]:null;
        $reply(mkUnifiedComplete($pdo,$pending,$names));
    }
    $raw=is_string($body['init_data']??null)?$body['init_data']:'';
    if($raw===''||strlen($raw)>32768)$reply(['success'=>false,'code'=>'missing_data','message'=>'اطلاعات امضاشدهٔ شروع برنامه دریافت نشد.'],422);
    if($action==='identify'){
        $valid=[];$stale=[];$disabled=[];
        foreach(array_keys(melkinoMessengerNames())as$p){
            $proof=melkinoMessengerProof($p,$raw);
            if(!empty($proof['user'])){
                if(melkinoLoginMethodEnabled($p))$valid[]=$p;else $disabled[]=$p;
            }elseif(in_array($proof['error']??'',['expired','future'],true))$stale[]=$p;
        }
        if(count($valid)===1)$reply(['success'=>true,'provider'=>$valid[0]]);
        if(count($valid)>1)$reply(['success'=>false,'code'=>'ambiguous','message'=>'پیام‌رسان به‌صورت یکتا تشخیص داده نشد؛ برنامهٔ درست را انتخاب کنید.'],409);
        if($disabled)$reply(['success'=>false,'code'=>'disabled','provider'=>$disabled[0],'message'=>'ورود با این پیام‌رسان توسط مدیر غیرفعال شده است.'],403);
        if($stale)$reply(['success'=>false,'code'=>'expired','provider'=>$stale[0],'message'=>'اطلاعات ورود قدیمی است؛ برنامک را ببندید و از دکمهٔ اجرای آن دوباره باز کنید.'],401);
        $reply(['success'=>false,'code'=>'unrecognized','message'=>'اطلاعات ورود با تنظیمات پیام‌رسان‌ها مطابقت ندارد؛ برنامهٔ درست را انتخاب کنید.'],401);
    }
    if($action!=='authenticate')$reply(['success'=>false,'code'=>'action','message'=>'درخواست شناخته نشد.'],422);
    $p=is_string($body['provider']??null)?$body['provider']:'';
    if(!isset(melkinoMessengerNames()[$p]))$reply(['success'=>false,'code'=>'provider','message'=>'پیام‌رسان را انتخاب کنید.'],422);
    if(!melkinoLoginMethodEnabled($p))$reply(['success'=>false,'code'=>'disabled','message'=>'این روش ورود توسط مدیر غیرفعال شده است.'],403);
    $attempt=is_string($body['attempt']??null)?$body['attempt']:'';
    if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$attempt))$reply(['success'=>false,'code'=>'attempt','message'=>'شناسهٔ درخواست معتبر نیست؛ دوباره تلاش کنید.'],422);
    $proof=melkinoMessengerProof($p,$raw);
    if(empty($proof['user'])){
        $code=(string)($proof['error']??'bad_hash');
        $expired=in_array($code,['expired','future','bad_date'],true);
        $reply(['success'=>false,'code'=>$code,'message'=>$expired?'اطلاعات ورود منقضی شده یا ساعت سرور نادرست است؛ برنامک را ببندید و دوباره باز کنید.':($code==='not_configured'?'توکن این پیام‌رسان در تنظیمات سایت کامل نیست.':'امضای ورود معتبر نیست؛ پیام‌رسان انتخاب‌شده و توکن همان برنامه باید مطابقت داشته باشند.')],$code==='not_configured'?503:401);
    }
    // A previous verified phone connection persists across sessions, but each launch still needs a fresh valid messenger signature.
    $returning=mkUnifiedResume($pdo,$p,$proof['user'],$proof['fingerprint'],$attempt);
    if($returning!==null)$reply($returning);
    $column=['telegram'=>'telegram_id','bale'=>'bale_id','eitaa'=>'eitaa_id'][$p];
    $st=$pdo->prepare("SELECT is_active FROM users WHERE `$column`=? LIMIT 1");$st->execute([$proof['user']['id']]);
    $active=$st->fetchColumn();
    if($active!==false&&(int)$active!==1)$reply(['success'=>false,'message'=>'این حساب غیرفعال است.'],403);
    if($p==='bale'){
        require_once __DIR__.'/bale-contact-lib.php';$hook=mkBaleConfig($pdo);
        if(($hook['bale_ready']??'')!=='1'||!hash_equals($hook['bale_token_hash']??'',hash('sha256',melkinoBaleToken())))throw new InvalidArgumentException('مدیر باید دریافت امن شمارهٔ بله را در تنظیمات ربات فعال کند.');
    }
    mkUnifiedBegin($p,$proof['user'],$proof['fingerprint'],$attempt,(int)$proof['user']['auth_date']+600);
    $reply(['success'=>true,'provider'=>$p,'next'=>'contact','attempt'=>$attempt]);
}catch(MkPhoneValidationError $e){
    $reply(['success'=>false,'code'=>$e->reason,'message'=>$e->getMessage(),'diagnostic'=>$e->diagnostic],409);
}catch(InvalidArgumentException $e){
    $reply(['success'=>false,'code'=>'phone_login','message'=>$e->getMessage()],409);
}catch(Throwable $e){
    unset($_SESSION['mk_login_pending']);
    error_log('[melkino][messenger-start] code='.$e->getCode());
    $reply(['success'=>false,'code'=>'storage','message'=>'تکمیل ورود در سرور انجام نشد؛ دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.'],503);
}
