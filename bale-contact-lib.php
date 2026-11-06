<?php
declare(strict_types=1);
require_once __DIR__.'/unified-phone-lib.php';
// دسترسی مستقیم HTTP به فایل داخلی ممنوع (همان قرارداد .htaccess؛ مستقل از نوع سرور)
if ((isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string)$_SERVER['SCRIPT_FILENAME']) : '') === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
function mkBaleConfig(PDO $db): array {
    $s=$db->query('SELECT k,v FROM phone_login_config');return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}
/** Fixed-origin API transport; injected transport is CLI-test-only, never HTTP input. */
function mkBaleConfigure(PDO $db,string $base,bool $replace=false,?callable $transport=null): void {
    mkUnifiedSchema($db);
    $lock='mk-bale-phone-setup';$q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Webhook setup busy');
    try{mkBaleConfigureLocked($db,$base,$replace,$transport);}
    finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
function mkBaleConfigureLocked(PDO $db,string $base,bool $replace,?callable $transport): void {
    require_once __DIR__.'/bot-api-client.php';
    $u=parse_url($base);
    if(!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||isset($u['port'])||!preg_match('~^/[A-Za-z0-9_/-]*$~D',($u['path']??'/'))||str_contains($base,'..'))throw new InvalidArgumentException('نشانی HTTPS پوشهٔ برنامه معتبر نیست.');
    $cfg=mkBaleConfig($db);
    if(($cfg['bale_mode']??'webhook')==='polling'&&!$replace)throw new InvalidArgumentException('تغییر از دریافت مستقیم به وبهوک باید توسط مدیر تأیید شود.');
    $token=melkinoBaleToken();$tokenHash=hash('sha256',$token);
    $info=melkinoBotApiRequest('bale','getWebhookInfo',[],$token,$transport);
    if(!$info['ok']||!is_array($info['result'])||!isset($info['result']['url']))throw new RuntimeException('وضعیت وبهوک از بله دریافت نشد.');
    $secret=($cfg['bale_token_hash']??'')===$tokenHash?($cfg['bale_secret']??''):'';
    if(!preg_match('/^[a-f0-9]{64}$/D',$secret))$secret=bin2hex(random_bytes(32));
    $url=rtrim($base,'/').'/bale-contact-hook.php?k='.$secret;
    $old=(string)$info['result']['url'];
    if($old!==''&&$old!==$url&&$old!==($cfg['bale_url']??'')&&!$replace)throw new InvalidArgumentException('این بازو وبهوک دیگری دارد. برای حفظ عملکرد قبلی، ابتدا مدیر باید تعویض آن را تأیید کند.');
    // Provision before setWebhook so an immediately delivered contact can authenticate.
    $put=$db->prepare('INSERT INTO phone_login_config(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
    $db->beginTransaction();
    try{foreach(['bale_ready'=>'0','bale_mode'=>'webhook','bale_secret'=>$secret,'bale_token_hash'=>$tokenHash,'bale_url'=>$url] as $k=>$v)$put->execute([$k,$v]);$db->commit();}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $r=melkinoBotApiRequest('bale','setWebhook',['url'=>$url],$token,$transport);
    if(!$r['ok']||$r['result']!==true)throw new RuntimeException('ثبت وبهوک تأیید نشد؛ مدیر دوباره وضعیت را بررسی کند.');
    $r=melkinoBotApiRequest('bale','getWebhookInfo',[],$token,$transport);
    if(!$r['ok']||($r['result']['url']??'')!==$url)throw new RuntimeException('بله نشانی جدید وبهوک را تأیید نکرد.');
    $put->execute(['bale_ready','1']);
}

/** Admin display contains no token, URL credential, contact, or provider ID. */
function mkBalePublicStatus(PDO $db): array {
    try{$c=mkBaleConfig($db);return ['mode'=>($c['bale_mode']??'webhook')==='polling'?'polling':'webhook','ready'=>($c['bale_ready']??'')==='1'&&hash_equals($c['bale_token_hash']??'',hash('sha256',melkinoBaleToken()))];}
    catch(Throwable $e){return ['mode'=>'webhook','ready'=>false];}
}
/** Explicit admin consent only. No webhook removal from a visitor/login request. */
function mkBaleConfigurePolling(PDO $db,bool $confirmed=false,?callable $transport=null): void {
    if(!$confirmed)throw new InvalidArgumentException('تغییر روش و توقف دریافت‌کننده‌های دیگر این بازو باید توسط مدیر تأیید شود.');
    require_once __DIR__.'/bot-api-client.php';
    mkUnifiedSchema($db);
    $q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute(['mk-bale-phone-setup']);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Phone setup busy');
    try{
        $c=mkBaleConfig($db);$token=melkinoBaleToken();$hash=hash('sha256',$token);
        $info=melkinoBotApiRequest('bale','getWebhookInfo',[],$token,$transport);
        if(!$info['ok']||!is_array($info['result'])||!is_string($info['result']['url']??null))throw new RuntimeException('Webhook state unavailable');
        $put=$db->prepare('INSERT INTO phone_login_config(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        // Fail closed throughout remote transition; retrying setup is safe after any partial failure.
        $put->execute(['bale_ready','0']);
        $r=melkinoBotApiRequest('bale','setWebhook',['url'=>''],$token,$transport);
        if(!$r['ok']||$r['result']!==true)throw new RuntimeException('Webhook removal unconfirmed');
        $info=melkinoBotApiRequest('bale','getWebhookInfo',[],$token,$transport);
        if(!$info['ok']||($info['result']['url']??null)!=='')throw new RuntimeException('Webhook still active');
        $offset=($c['bale_token_hash']??'')===$hash?($c['bale_poll_offset']??'0'):'0';
        mkBaleUpdateId($offset); // Never acknowledge or discard a queue just to configure it.
        $db->beginTransaction();
        try{
            foreach(['bale_mode'=>'polling','bale_token_hash'=>$hash,'bale_poll_offset'=>$offset,'bale_poll_after'=>'0','bale_poll_error'=>'0','bale_poll_state'=>'not_checked','bale_poll_seen_at'=>'0','bale_ready'=>'1'] as $k=>$v)$put->execute([$k,$v]);
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute(['mk-bale-phone-setup']);}
}
/** A representable nonnegative integer with room for offset + 1; never float-cast IDs. */
function mkBaleUpdateId(mixed $id): int {
    if(!(is_int($id)||is_string($id))||!preg_match('/^(?:0|[1-9][0-9]{0,18})$/D',(string)$id))throw new RuntimeException('Invalid update cursor');
    $max=(string)(PHP_INT_MAX-1);$s=(string)$id;
    if(strlen($s)>strlen($max)||(strlen($s)===strlen($max)&&strcmp($s,$max)>0))throw new RuntimeException('Update cursor overflow');
    return (int)$s;
}
/** Called only for a fresh signed Bale pending login. Optional transport is CLI test injection. */
function mkBalePoll(PDO $db,array $pending,?callable $transport=null): void {
    if(($pending['provider']??'')!=='bale'||(int)($pending['expires_at']??0)<=time()
        ||!is_string($pending['token_hash']??null)||!hash_equals($pending['token_hash'],hash('sha256',melkinoBaleToken())))throw new InvalidArgumentException('نشست معتبر بله لازم است.');
    require_once __DIR__.'/bot-api-client.php';
    // One bounded upstream request globally, not one consumer per user. Same lock as admin setup.
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute(['mk-bale-phone-setup']);
    if((int)$q->fetchColumn()!==1)return;
    try{
        $c=mkBaleConfig($db);
        if(($c['bale_ready']??'')!=='1'||!hash_equals($c['bale_token_hash']??'',$pending['token_hash']))throw new InvalidArgumentException('مدیر باید دریافت امن شمارهٔ بله را دوباره تنظیم کند.');
        $mode=$c['bale_mode']??'webhook';
        if($mode==='webhook')return; // Existing webhook installations retain their behaviour.
        if($mode!=='polling')throw new InvalidArgumentException('روش دریافت شماره تنظیم نشده است.');
        $message='دریافت مستقیم از API بله تأیید نشد؛ مدیر اتصال هاست، توکن و نبود وبهوک یا دریافت‌کنندهٔ هم‌زمان را بررسی کند.';
        if((int)($c['bale_poll_after']??0)>time()){
            if(($c['bale_poll_error']??'0')==='1')throw new InvalidArgumentException($message);
            return;
        }
        $put=$db->prepare('INSERT INTO phone_login_config(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        // On transport failure throttle retries; never emit tokens, API URLs or raw responses.
        $put->execute(['bale_poll_after',(string)(time()+10)]);$put->execute(['bale_poll_error','1']);
        try{
            $offset=mkBaleUpdateId($c['bale_poll_offset']??'0');
            $r=melkinoBotApiRequest('bale','getUpdates',['offset'=>(string)$offset,'limit'=>100,'timeout'=>0],melkinoBaleToken(),$transport);
            if(!$r['ok']||!is_array($r['result'])||!array_is_list($r['result'])||count($r['result'])>100)throw new RuntimeException('Unconfirmed update batch');
            $next=$offset;
            foreach($r['result'] as $u){
                if(!is_array($u))throw new RuntimeException('Invalid update');
                $id=mkBaleUpdateId($u['update_id']??null);$next=max($next,$id+1);
            }
            // Persist contacts for ALL concurrent users before advancing the shared cursor.
            // Ordinary updates are consumed just as with this application's contact-only webhook.
            $db->beginTransaction();
            try{
                foreach($r['result'] as $u)if(mkBaleUpdateId($u['update_id'])>=$offset)mkBaleReceive($db,$u,$pending['token_hash']);
                $put->execute(['bale_poll_offset',(string)$next]);
                $put->execute(['bale_poll_state',$r['result']?'updates':'empty']);$put->execute(['bale_poll_seen_at',(string)time()]);
                $put->execute(['bale_poll_after',(string)(time()+3)]);$put->execute(['bale_poll_error','0']);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
        }catch(Throwable $e){throw new InvalidArgumentException($message);}
    }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute(['mk-bale-phone-setup']);}
}

/** Only called after mkUnifiedPending; returns this actor's structural status, never data from other users. */
function mkBaleWaitingDiagnostic(PDO $db,array $p): array {
    $d=['server'=>'bale-trace1','transport'=>'unknown','api'=>'not_checked','api_recent'=>false,'contact'=>'NO_RECEIPT'];
    try{
        $c=mkBaleConfig($db);$mode=$c['bale_mode']??'webhook';
        $d['transport']=in_array($mode,['polling','webhook'],true)?$mode:'unknown';
        $state=$c['bale_poll_state']??'not_checked';
        $d['api']=$mode==='webhook'?'webhook':(($c['bale_poll_error']??'0')==='1'?'error':(in_array($state,['empty','updates'],true)?$state:'not_checked'));
        $d['api_recent']=$mode==='polling'&&(int)($c['bale_poll_seen_at']??0)>=$p['started_at']-2;
        $s=$db->prepare('SELECT detail FROM bale_contact_diagnostics WHERE token_hash=? AND provider_id=? AND seen_at>=?');$s->execute([$p['token_hash'],$p['provider_id'],$p['started_at']-2]);
        $row=$s->fetchColumn();$v=is_string($row)?json_decode($row,true):null;
        $codes=['NO_CONTACT','CHAT_TYPE','FORWARDED','ID_TYPE','USER_ID_MISMATCH','CHAT_ID_MISMATCH','STALE','FUTURE','PHONE_TYPE','PHONE_FORMAT','VALID'];
        if(is_array($v)&&in_array($v['code']??null,$codes,true)){
            $d['contact']=$v['code']==='VALID'?'RECEIPT_NOT_AVAILABLE':$v['code'];
            foreach(['contact_user_id','contact_phone_number','contact_phone','from_id','chat_id','date','update_id'] as $k){$value=$v['fields'][$k]??null;if(in_array($value,['integer','number','boolean','array','string','missing_or_other'],true))$d['fields'][$k]=$value;}
        }
        $s=$db->prepare('SELECT event_at,consumed_by FROM bale_contact_receipts WHERE token_hash=? AND provider_id=? ORDER BY event_at DESC,received_at DESC LIMIT 1');$s->execute([$p['token_hash'],$p['provider_id']]);$receipt=$s->fetch(PDO::FETCH_ASSOC);
        // Prefer an explicit fresh validation rejection; otherwise explain why a stored proof cannot be used.
        if($receipt&&in_array($d['contact'],['NO_RECEIPT','RECEIPT_NOT_AVAILABLE'],true)){
            if((int)$receipt['event_at']<=time()-600)$d['contact']='STALE';
            elseif((int)$receipt['event_at']<$p['started_at']-2)$d['contact']='STALE_FOR_SESSION';
            elseif($receipt['consumed_by']!==null&&!hash_equals($receipt['consumed_by'],hash('sha256',$p['ticket'])))$d['contact']='USED_ELSEWHERE';
        }
    }catch(Throwable $e){$d['diagnostics']='unavailable';}
    return $d;
}
