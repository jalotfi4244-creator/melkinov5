<?php
/** Shared presentation/session helpers; a selected app/UA is NEVER authentication. */
declare(strict_types=1);
require_once __DIR__ . '/program-url.php';

function melkinoMessengerNames(): array { return ['telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ایتا']; }
function melkinoMessengerHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
        || (int)($_SERVER['SERVER_PORT']??0)===443 || ($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
}
function melkinoMessengerSessionStart(): void {
    if (session_status()!==PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode','1');
        $p=session_get_cookie_params();
        session_set_cookie_params(['lifetime'=>0,'path'=>$p['path']?:'/','domain'=>$p['domain']??'',
            'secure'=>melkinoMessengerHttps(),'httponly'=>true,'samesite'=>melkinoMessengerHttps()?'None':'Lax']);
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    }
}
function melkinoMessengerSessionCookie(): void {
    if (headers_sent() || session_status()!==PHP_SESSION_ACTIVE) return;
    $p=session_get_cookie_params();
    setcookie(session_name(),session_id(),['expires'=>0,'path'=>$p['path']?:'/','domain'=>$p['domain']??'',
        'secure'=>melkinoMessengerHttps(),'httponly'=>true,'samesite'=>melkinoMessengerHttps()?'None':'Lax']);
}
function melkinoMessengerContext(): string {
    $p=$_SESSION['melkino_messenger_context']??'';
    if (is_string($p) && isset(melkinoMessengerNames()[$p])) return $p;
    return !empty($_SESSION['melkino_eitaa_context'])?'eitaa':'';
}
function melkinoMessengerHint(): string {
    $p=$_GET['messenger']??'';
    if (is_string($p) && isset(melkinoMessengerNames()[$p])) return $p;
    $ua=strtolower((string)($_SERVER['HTTP_USER_AGENT']??''));
    foreach(['eitaa','bale','telegram'] as $p) if(str_contains($ua,$p)) return $p;
    $host=strtolower((string)parse_url((string)($_SERVER['HTTP_REFERER']??''),PHP_URL_HOST));
    if(in_array($host,['web.telegram.org','t.me'],true))return 'telegram';
    if(in_array($host,['web.bale.ai','beta.bale.ai','bale.ai','ble.ir'],true))return 'bale';
    if(in_array($host,['web.eitaa.com','eitaa.com'],true))return 'eitaa';
    return '';
}
function melkinoMessengerSafeTarget($value, string $fallback='home.php'): string {
    if (!is_string($value) || strlen($value)>768) return $fallback;
    // راند ۲۰: مسیرهای ریشهٔ سایت (مثل /listing.php?id=5) باید مطلق بمانند تا
    // بعد از ورود، کاربر به صفحهٔ عمومی برگردد نه /program/listing.php. اگر نام
    // فایل در خودِ برنامه وجود داشته باشد (مثل home.php) رفتار قبلی (نسبی) حفظ می‌شود.
    $abs=str_starts_with($value,'/')&&!str_starts_with($value,'//');
    $value=ltrim($value,'/');
    // Original absolute/protocol-relative paths are not accepted by callers (see below).
    if (!preg_match('/^[A-Za-z0-9_-]+\.php(?:\?[A-Za-z0-9_=&%.,+\-\/]*)?$/D',$value))return $fallback;
    $path=strtok($value,'?');
    if(preg_match('/^(?:admin|auth|login|logout|messenger-|telegram-app|bale-app|eitaa-app|office)/i',$path))return $fallback;
    parse_str((string)(parse_url($value,PHP_URL_QUERY)??''),$q);
    foreach(array_keys($q)as$k)if(in_array(strtolower((string)$k),['t','action','redirect','csrf_token'],true)||stripos((string)$k,'tgwebapp')===0)return $fallback;
    if($abs&&!is_file(__DIR__.'/'.basename($path)))return '/'.$value;
    return $value;
}
function melkinoMessengerTarget($value, string $fallback='home.php'): string {
    if(!is_string($value)||str_starts_with($value,'//')||str_contains($value,'\\')||preg_match('/[\x00-\x20\x7F]/',$value))return $fallback;
    return melkinoMessengerSafeTarget(melkinoAppRelativeTarget($value),$fallback);
}
function melkinoMessengerSdk(string $p): string {
    return ['telegram'=>'telegram-web-app.js','bale'=>'https://tapi.bale.ai/miniapp.js?3','eitaa'=>'https://developer.eitaa.com/eitaa-web-app.js'][$p]??'';
}
function melkinoMessengerLaunchUrlValid(string $provider, string $url): bool {
    if(strlen($url)>1500 || preg_match('/[\x00-\x20\x7F\\\\]/',$url))return false;
    $u=parse_url($url);
    $hosts=['telegram'=>['t.me'],'bale'=>['ble.ir'],'eitaa'=>['eitaa.com']];
    if(!is_array($u)||($u['scheme']??'')!=='https'||!in_array(strtolower($u['host']??''),$hosts[$provider]??[],true)
        ||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['fragment'])
        ||!preg_match('#^/[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+)?/?$#D',$u['path']??''))return false;
    parse_str($u['query']??'',$q);
    foreach(array_keys($q)as$key)if(stripos((string)$key,'tgwebapp')===0||in_array(strtolower((string)$key),['init_data','initdata','token','hash','auth_date','user'],true))return false;
    return true;
}
function melkinoMessengerLinks(string $p): array {
    $username=ltrim(trim(melkinoBotSetting($p.'_bot_username')),'@');
    $base=['telegram'=>'https://t.me/','bale'=>'https://ble.ir/','eitaa'=>'https://eitaa.com/'][$p]??'';
    $bot=preg_match('/^[A-Za-z0-9_]{1,64}$/D',$username)?$base.rawurlencode($username):'';
    $exact=melkinoBotSetting($p.'_miniapp_url');
    if($exact!=='' && melkinoMessengerLaunchUrlValid($p,$exact))return ['launch'=>$exact,'bot'=>$bot?:$exact];
    if($p==='eitaa')return ['launch'=>function_exists('melkinoEitaaMiniappUrl')?melkinoEitaaMiniappUrl():$bot,'bot'=>$bot];
    return ['launch'=>$bot!==''?$bot.'?startapp':'','bot'=>$bot];
}
function melkinoMessengerCurrentUser(?PDO $pdo): ?array {
    if(!$pdo)return null;
    $uid=(int)($_SESSION['melkino_user_id']??$_SESSION['user_id']??0);
    if($uid<=0)return null;
    $q=$pdo->prepare('SELECT id,name,phone,telegram_id,bale_id,eitaa_id,is_active FROM users WHERE id=? LIMIT 1');
    $q->execute([$uid]);$u=$q->fetch(PDO::FETCH_ASSOC);
    return $u && (int)$u['is_active']===1?$u:null;
}
/** Reuse the current, working signature implementation; reject ambiguous query encodings first. */
function melkinoMessengerProof(string $p, string $raw): array {
    if(!isset(melkinoMessengerNames()[$p])||$raw===''||strlen($raw)>32768)return ['user'=>null,'error'=>'invalid_data'];
    $seen=[];
    foreach(explode('&',$raw)as$seg){
        if(!str_contains($seg,'='))return ['user'=>null,'error'=>'invalid_data'];
        [$k,$v]=explode('=',$seg,2);
        if(preg_match('/%(?![0-9A-Fa-f]{2})/',$k.$v))return ['user'=>null,'error'=>'invalid_data'];
        $k=urldecode($k);$v=urldecode($v);
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$k)||isset($seen[$k])||preg_match('/[\x00\r\n]/',$v))return ['user'=>null,'error'=>'invalid_data'];
        $seen[$k]=$v;
        if(count($seen)>64)return ['user'=>null,'error'=>'invalid_data'];
    }
    $tokens=['telegram'=>'melkinoTelegramToken','bale'=>'melkinoBaleToken','eitaa'=>'melkinoEitaaToken'];
    $token=$tokens[$p]();
    if($token===''||str_contains($token,'توکن_'))return ['user'=>null,'error'=>'not_configured'];
    if($p==='eitaa'){
        require_once __DIR__.'/eitaa-auth-lib.php';
        $r=melkinoEitaaVerifyInitDataEx($raw,$token);
    }else $r=melkinoVerifyMiniAppInitDataEx($raw,$token);
    if(!empty($r['user'])){
        $u=$r['user'];$date=(int)($u['auth_date']??0);
        if(!preg_match('/^[1-9][0-9]{0,19}$/D',(string)($u['id']??'')))return ['user'=>null,'error'=>'invalid_data'];
        if(time()-$date>600)return ['user'=>null,'error'=>'expired'];
        if($date-time()>60)return ['user'=>null,'error'=>'future'];
    }
    $r['fingerprint']=hash('sha256',$p.'|'.strtolower((string)($seen['hash']??$seen['signature']??'')));
    return $r;
}
function melkinoMessengerMarkSuccess(string $p, int $uid): void {
    $pending=$_SESSION['mk_login_pending']??[];
    $_SESSION['melkino_messenger_context']=$p;
    if($p==='eitaa')$_SESSION['melkino_eitaa_context']=true;else unset($_SESSION['melkino_eitaa_context']);
    $_SESSION['mk_login_completed']=['provider'=>$p,'uid'=>$uid,'attempt'=>(string)($pending['attempt']??''),'fingerprint'=>(string)($pending['fingerprint']??'')];
    unset($_SESSION['mk_login_pending']);
    melkinoMessengerSessionCookie();
}
function melkinoMessengerEstablish(PDO $pdo, int $uid, string $p): array {
    $q=$pdo->prepare('SELECT id,name,phone,telegram_id,bale_id,eitaa_id,is_active FROM users WHERE id=? LIMIT 1');$q->execute([$uid]);$u=$q->fetch(PDO::FETCH_ASSOC);
    if(!$u||(int)$u['is_active']!==1)throw new RuntimeException('account_disabled',403);
    $keep=[];
    foreach(['melkino_csrf','melkino_eitaa_binding','mk_login_probe','mk_login_pending']as$k)if(isset($_SESSION[$k]))$keep[$k]=$_SESSION[$k];
    $_SESSION=$keep;
    if(!session_regenerate_id(true))throw new RuntimeException('session',503);
    $_SESSION['user_id']=$_SESSION['melkino_user_id']=$uid;
    $_SESSION['user_name']=(string)($u['name']??'');
    if(!empty($u['phone']))$_SESSION['user_phone']=(string)$u['phone'];
    foreach(['telegram_id'=>'reg_telegram_id','bale_id'=>'reg_bale_id','eitaa_id'=>'reg_eitaa_id']as$k=>$s)if(!empty($u[$k]))$_SESSION[$s]=(string)$u[$k];
    $_SESSION['melkino_session_started_at']=time();$_SESSION['melkino_login_recorded']=time();
    setcookie('melkino_access_token','',['expires'=>time()-3600,'path'=>'/','secure'=>melkinoMessengerHttps(),'httponly'=>true,'samesite'=>'Lax']);
    unset($_COOKIE['melkino_access_token']);
    melkinoMessengerMarkSuccess($p,$uid);
    return ['success'=>true,'provider'=>$p,'user_id'=>$uid,'name'=>(string)($u['name']??''),'phone'=>(string)($u['phone']??''),'attempt'=>$_SESSION['mk_login_completed']['attempt']];
}
