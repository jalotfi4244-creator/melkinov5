<?php
declare(strict_types=1);
require_once __DIR__.'/account-phone-lib.php';
// دسترسی مستقیم HTTP به فایل داخلی ممنوع (همان قرارداد .htaccess؛ مستقل از نوع سرور)
if ((isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string)$_SERVER['SCRIPT_FILENAME']) : '') === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}
/** Owner-approved policy: existing recorded phone numbers are authoritative, including legacy manual numbers. */
function mkUnifiedSchema(PDO $db): void {
    mkLinkSchema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS phone_login_proofs(proof_hash CHAR(64) PRIMARY KEY,ticket_hash CHAR(64) NOT NULL,expires_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS phone_login_config(k VARCHAR(64) PRIMARY KEY,v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS phone_login_audit(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,canonical_uid INT NOT NULL,provider VARCHAR(16) NOT NULL,provider_id VARCHAR(64) NOT NULL,proof_hash CHAR(64) NOT NULL,created_at BIGINT NOT NULL,INDEX ix_uid(canonical_uid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bale_contact_receipts(update_id VARCHAR(64) PRIMARY KEY,provider_id VARCHAR(64) NOT NULL,phone VARCHAR(30) NOT NULL,event_at BIGINT NOT NULL,received_at BIGINT NOT NULL,token_hash CHAR(64) NOT NULL,consumed_by CHAR(64) NULL,INDEX ix_actor(provider_id,event_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS bale_contact_diagnostics(token_hash CHAR(64) NOT NULL,provider_id VARCHAR(64) NOT NULL,seen_at BIGINT NOT NULL,detail TEXT NOT NULL,PRIMARY KEY(token_hash,provider_id),INDEX ix_seen(seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $engines=$db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['users','phone_login_proofs','bale_contact_receipts','phone_login_config','phone_login_audit'] as $t)if(strtolower((string)($engines[$t]??''))!=='innodb')throw new RuntimeException('Transactional phone storage required');

}
function mkUnifiedClaimContact(PDO $db,array $p): void {
    $hash=hash('sha256',$p['provider'].'|'.$p['proof_hash']);$ticket=hash('sha256',$p['ticket']);
    $db->prepare('INSERT IGNORE INTO phone_login_proofs(proof_hash,ticket_hash,expires_at) VALUES(?,?,?)')->execute([$hash,$ticket,$p['expires_at']]);
    $s=$db->prepare('SELECT ticket_hash FROM phone_login_proofs WHERE proof_hash=?');$s->execute([$hash]);
    if(!hash_equals((string)$s->fetchColumn(),$ticket))throw new InvalidArgumentException('این پاسخ تماس در نشست دیگری استفاده شده؛ شماره را دوباره از پیام‌رسان به اشتراک بگذارید.');
    $db->prepare('DELETE FROM phone_login_proofs WHERE expires_at<?')->execute([time()-600]);
}
function mkUnifiedPhoneCharacters(): array {
    return ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',' '=>'','-'=>'','('=>'',')'=>'',"\t"=>'',"\r"=>'',"\n"=>'',' '=>''];
}
function mkUnifiedPhone(string $phone): string {
    $phone=trim($phone);
    if(preg_match('/^(?:\+98|0098|98)(9[0-9]{9})$/D',$phone,$m))$phone='0'.$m[1];
    if(!preg_match('/^09[0-9]{9}$/D',$phone))throw new InvalidArgumentException('شمارهٔ موبایل ایران معتبر نیست.');
    return $phone;
}
function mkUnifiedToken(string $p): string {
    $fn=['telegram'=>'melkinoTelegramToken','bale'=>'melkinoBaleToken','eitaa'=>'melkinoEitaaToken'][$p]??null;
    return $fn ? $fn() : '';
}
function mkUnifiedOrdinary(array $u): void {
    if(empty($u['is_active']))throw new InvalidArgumentException('حساب غیرفعال است؛ با پشتیبانی تماس بگیرید.');
    if(!empty($u['is_admin'])||!empty($u['is_superadmin'])||!in_array((string)($u['role']??''),['','user','member'],true))throw new InvalidArgumentException('حساب دارای دسترسی ویژه نیاز به بررسی مدیر دارد.');
}
function mkUnifiedPending(): array {
    $p=$_SESSION['mk_phone_login']??null;
    if(!is_array($p)||(int)($p['expires_at']??0)<=time())throw new InvalidArgumentException('مهلت ورود تمام شده است؛ برنامک را دوباره باز کنید.');
    if(!melkinoLoginMethodEnabled($p['provider']))throw new InvalidArgumentException('این روش ورود غیرفعال شده است.');
    if($p['provider']!=='sms'&&!hash_equals($p['token_hash'],hash('sha256',mkUnifiedToken($p['provider']))))throw new InvalidArgumentException('تنظیمات پیام‌رسان تغییر کرده است؛ ورود را دوباره آغاز کنید.');
    return $p;
}
function mkUnifiedBegin(string $provider,array $user,string $fingerprint,string $attempt,int $expires): array {
    $old=$_SESSION['mk_phone_login']??[];
    if(($old['provider']??'')===$provider && ($old['attempt']??'')===$attempt && ($old['fingerprint']??'')===$fingerprint && (int)($old['expires_at']??0)>time())return $old;
    $keep=[];foreach(['melkino_csrf','mk_login_probe'] as $key)if(isset($_SESSION[$key]))$keep[$key]=$_SESSION[$key];
    $_SESSION=$keep;if(!session_regenerate_id(true))throw new RuntimeException('Session rotation failed');
    // A signed messenger identity is provisional: no full user session until a phone proof succeeds.
    setcookie('melkino_access_token','',['expires'=>time()-3600,'path'=>'/','httponly'=>true,'secure'=>melkinoMessengerHttps(),'samesite'=>'Lax']);unset($_COOKIE['melkino_access_token']);
    $p=['provider'=>$provider,'provider_id'=>(string)($user['id']??''),'fingerprint'=>$fingerprint,'attempt'=>$attempt,'started_at'=>time(),'expires_at'=>min(time()+600,$expires),'ticket'=>bin2hex(random_bytes(32)),'token_hash'=>hash('sha256',mkUnifiedToken($provider))];
    $_SESSION['mk_phone_login']=$p;melkinoMessengerSessionCookie();return $p;
}
/** No name/phone from a browser is an ownership proof. Caller has verified contact or OTP. */
function mkUnifiedResolve(PDO $db,array $p,?array $names=null): array {
    $phone=mkUnifiedPhone($p['phone']);$provider=$p['provider'];
    if(!in_array($provider,['telegram','bale','eitaa','sms'],true)||empty($p['proof_hash'])||(int)$p['expires_at']<=time())throw new InvalidArgumentException('تأیید شماره معتبر نیست.');
    $column=$provider==='sms'?null:$provider.'_id';$pid=(string)$p['provider_id'];
    if($column&&!preg_match('/^[1-9][0-9]{0,19}$/D',$pid))throw new InvalidArgumentException('هویت پیام‌رسان معتبر نیست.');
    if ($names !== null) {
        foreach (['first_name','last_name'] as $k) {
            $v=$names[$k]??($k==='first_name'?'':null);
            if (!is_string($v)) throw new InvalidArgumentException($k==='first_name'?'نام معتبر نیست.':'نام خانوادگی را وارد کنید.');
            $v=trim($v);
            if ($v==='' && $k==='first_name') { $names[$k]=''; continue; }
            if ($v==='') throw new InvalidArgumentException('نام خانوادگی الزامی است.');
            if (mb_strlen($v)>80 || !preg_match("/^[\p{L}\p{M} '\x{200c}.\-]+$/u",$v) || !preg_match('/\p{L}/u',$v)) {
                throw new InvalidArgumentException($k==='first_name'?'نام را درست وارد کنید (حداکثر ۸۰ نویسه).':'نام خانوادگی را درست وارد کنید (حداکثر ۸۰ نویسه).');
            }
            $names[$k]=$v;
        }
    }
    $engines=$db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['users','phone_login_audit','account_link_requests','account_merge_audit'] as $t)if(strtolower((string)($engines[$t]??''))!=='innodb')throw new RuntimeException('Transactional storage required');
    $locks=['mk-phone-'.substr(hash('sha256',$phone),0,40)];if($column)$locks[]='mk-provider-'.substr(hash('sha256',$provider.'|'.$pid),0,40);sort($locks);$held=[];
    try{
        foreach($locks as $lock){$s=$db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Identity busy');$held[]=$lock;}
        $connection=(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
        foreach($held as $lock){$s=$db->prepare('SELECT IS_USED_LOCK(?)');$s->execute([$lock]);if((string)$s->fetchColumn()!==$connection)throw new RuntimeException('Multiple advisory locks required');}
        $db->beginTransaction();
        $variants=[$phone,'+98'.substr($phone,1),'98'.substr($phone,1),'0098'.substr($phone,1)];
        $expr='phone';foreach(mkUnifiedPhoneCharacters() as $a=>$b)$expr='REPLACE('.$expr.','.$db->quote($a).','.$db->quote($b).')';
        $s=$db->prepare('SELECT * FROM users WHERE '.$expr.' IN (?,?,?,?) ORDER BY id');$s->execute($variants);$owners=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($owners)>1)throw new InvalidArgumentException('شماره در چند حساب قدیمی تکرار شده است؛ مدیر باید سوابق را بررسی کند.');
        $owner=$owners[0]??null;$actor=null;
        if($column){$s=$db->prepare("SELECT * FROM users WHERE `$column`=? ORDER BY id");$s->execute([$pid]);$actors=$s->fetchAll(PDO::FETCH_ASSOC);if(count($actors)>1)throw new InvalidArgumentException('شناسهٔ پیام‌رسان تکراری است؛ با پشتیبانی تماس بگیرید.');$actor=$actors[0]??null;}
        $ids=[];foreach([$owner,$actor] as $u)if($u)$ids[]=(int)$u['id'];$ids=array_unique($ids);sort($ids);
        $rows=[];foreach($ids as $id){$rows[$id]=mkLinkUser($db,$id,true);mkUnifiedOrdinary($rows[$id]);}
        if($owner){$old=$owner;$owner=$rows[(int)$owner['id']];if(mkLinkFingerprint($old)!==mkLinkFingerprint($owner))throw new InvalidArgumentException('حساب هم‌زمان تغییر کرده؛ دوباره تلاش کنید.');}
        if($actor){$old=$actor;$actor=$rows[(int)$actor['id']];if(mkLinkFingerprint($old)!==mkLinkFingerprint($actor))throw new InvalidArgumentException('هویت هم‌زمان تغییر کرده؛ دوباره تلاش کنید.');if(trim((string)$actor['phone'])!==''&&mkUnifiedPhone(strtr((string)$actor['phone'],mkUnifiedPhoneCharacters()))!==$phone)throw new InvalidArgumentException('شمارهٔ واقعی پیام‌رسان با حساب متصل قبلی متفاوت است؛ مدیر باید بررسی کند.');}
        if(!$owner && $names===null){$db->rollBack();return ['success'=>true,'next'=>'name','phone'=>$phone,'attempt'=>$p['attempt']??''];}
        if($owner){
            if($column && trim((string)($owner[$column]??''))!=='' && (string)$owner[$column]!==$pid)throw new InvalidArgumentException('این شماره به شناسهٔ دیگری از همین پیام‌رسان متصل است؛ برای جلوگیری از حذف دسترسی قبلی با پشتیبانی تماس بگیرید.');
            $uid=(int)$owner['id'];
            if($actor && (int)$actor['id']!==$uid){
                // Fresh proof of the new provider plus owner-approved legacy-phone policy replaces old-account confirmation.
                $db->prepare("INSERT INTO account_link_requests(code_hash,source_uid,target_uid,state,expires_at,created_at,source_fingerprint,target_fingerprint) VALUES(?,?,?,'waiting',?,?,?,?)")->execute([hash('sha256',random_bytes(32)),$uid,(int)$actor['id'],$p['expires_at'],time(),mkLinkFingerprint($owner),mkLinkFingerprint($actor)]);
                $request=mkLinkRequest($db,(int)$db->lastInsertId());
                mkLinkMergeLocked($db,$request,$owner,$actor,true);
                $db->prepare("UPDATE account_link_requests SET state='done',completed_at=? WHERE id=?")->execute([time(),$request['id']]);
            }elseif($column){$db->prepare("UPDATE users SET `$column`=? WHERE id=?")->execute([$pid,$uid]);}
            // Never overwrite existing names with a messenger nickname or registration input.
        }elseif($actor){
            $uid=(int)$actor['id'];$db->prepare('UPDATE users SET name=?,first_name=?,last_name=?,name_locked=1 WHERE id=?')->execute([trim($names['first_name'].' '.$names['last_name']),$names['first_name'],$names['last_name'],$uid]);
        }else{
            $fields=['phone','name','first_name','last_name','name_locked','is_active'];$values=[$phone,trim($names['first_name'].' '.$names['last_name']),$names['first_name'],$names['last_name'],1,1];
            if($column){$fields[]=$column;$values[]=$pid;}
            $db->prepare('INSERT INTO users(`'.implode('`,`',$fields).'`) VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute($values);$uid=(int)$db->lastInsertId();
        }
        $db->prepare("UPDATE users SET phone=?,phone_verified=1,phone_locked=1,name_locked=IF(COALESCE(name,'')<>'',1,name_locked),login_count=COALESCE(login_count,0)+1,first_login=COALESCE(first_login,NOW()),updated_at=NOW() WHERE id=?")->execute([$phone,$uid]);
        $db->prepare('INSERT INTO phone_login_audit(canonical_uid,provider,provider_id,proof_hash,created_at) VALUES(?,?,?,?,?)')->execute([$uid,$provider,$pid,$p['proof_hash'],time()]);
        $db->commit();return ['success'=>true,'next'=>'done','user_id'=>$uid];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{foreach(array_reverse($held) as $lock){try{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}catch(Throwable $e){}}}
}
function mkUnifiedComplete(PDO $db,array $p,?array $names=null): array {
    $r=mkUnifiedResolve($db,$p,$names);
    if($r['next']==='name'){$_SESSION['mk_phone_login']=$p;return $r;}
    $_SESSION['mk_login_pending']=['provider'=>$p['provider'],'attempt'=>$p['attempt'],'fingerprint'=>$p['fingerprint']];
    $r=array_merge($r,melkinoMessengerEstablish($db,(int)$r['user_id'],$p['provider']));
    $_SESSION['phone_login_verified']=true;unset($_SESSION['mk_phone_login']);
    if(!defined('MELKINO_UNIFIED_LOGIN'))define('MELKINO_UNIFIED_LOGIN',true);
    if(function_exists('melkinoRecordLoginInfo'))melkinoRecordLoginInfo((int)$r['user_id'],$p['provider'],['name'=>$r['name'],$p['provider'].'_id'=>$p['provider_id']]);
    return $r;
}
/** Trusted server input ONLY: authenticated webhook or fixed-origin HTTPS getUpdates. Never browser contact data. */
function mkBaleReceive(PDO $db,array $update,string $tokenHash): bool {
    $check=mkBaleInspectContact($update);
    // Record only our fixed structural fields, not phone/name/raw updates. Diagnostics never authorize login.
    $m=$update['message']??null;$from=is_array($m)?($m['from']['id']??null):null;
    if((is_int($from)||is_string($from))&&preg_match('/^[0-9]{1,20}$/D',(string)$from)&&($check['code']??'')!=='NO_CONTACT'){
        try{
            $safe=$check;unset($safe['phone']);
            $db->prepare('INSERT INTO bale_contact_diagnostics(token_hash,provider_id,seen_at,detail) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at),detail=VALUES(detail)')->execute([$tokenHash,(string)$from,time(),json_encode($safe)]);
            $db->prepare('DELETE FROM bale_contact_diagnostics WHERE seen_at<?')->execute([time()-1200]);
        }catch(Throwable $e){ /* A missing diagnostic table must not break a valid contact. */ }
    }
    if($check['code']!=='VALID')return false;
    $uid=$m['contact']['user_id'];$id=$update['update_id'];$date=$m['date'];$phone=$check['phone'];
    $s=$db->prepare('INSERT IGNORE INTO bale_contact_receipts(update_id,provider_id,phone,event_at,received_at,token_hash) VALUES(?,?,?,?,?,?)');$s->execute([(string)$id,(string)$from,$phone,(int)$date,time(),$tokenHash]);$db->prepare('DELETE FROM bale_contact_receipts WHERE received_at<?')->execute([time()-1200]);return true;
}
function mkBaleConsume(PDO $db,array $p): ?array {
    $ticket=hash('sha256',$p['ticket']);$db->beginTransaction();try{
        $s=$db->prepare('SELECT * FROM bale_contact_receipts WHERE provider_id=? AND event_at>=? AND event_at>? AND token_hash=? AND (consumed_by IS NULL OR consumed_by=?) ORDER BY event_at DESC,received_at DESC LIMIT 1 FOR UPDATE');
        $s->execute([$p['provider_id'],$p['started_at']-2,time()-600,$p['token_hash'],$ticket]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r){$db->rollBack();return null;}
        $db->prepare('UPDATE bale_contact_receipts SET consumed_by=? WHERE update_id=?')->execute([$ticket,$r['update_id']]);$db->commit();
        $p['phone']=$r['phone'];$p['proof_hash']=hash('sha256','bale|'.$r['update_id'].'|'.$r['token_hash']);$p['expires_at']=min($p['expires_at'],(int)$r['event_at']+600);return $p;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

/** Structural contact classifier. Same ownership/freshness requirements as the receipt validator. */
function mkBaleInspectContact(array $update): array {
    $m=$update['message']??null;$c=is_array($m)?($m['contact']??null):null;
    $type=static fn($v)=>match(gettype($v)){'integer'=>'integer','double'=>'number','boolean'=>'boolean','array'=>'array','string'=>'string',default=>'missing_or_other'};
    $fields=[];
    foreach(['user_id','phone_number','phone'] as $key)$fields['contact_'.$key]=$type(is_array($c)?($c[$key]??null):null);
    foreach(['from','chat'] as $key)$fields[$key.'_id']=$type(is_array($m)?($m[$key]['id']??null):null);
    $fields['date']=$type(is_array($m)?($m['date']??null):null);$fields['update_id']=$type($update['update_id']??null);
    $bad=static fn($code)=>['code'=>$code,'fields'=>$fields];
    if(!is_array($m)||!is_array($c))return $bad('NO_CONTACT');
    if(($m['chat']['type']??'')!=='private')return $bad('CHAT_TYPE');
    foreach(['forward_from','forward_from_chat','forward_date','forward_origin'] as $k)if(isset($m[$k]))return $bad('FORWARDED');
    $from=$m['from']['id']??null;$uid=$c['user_id']??null;$id=$update['update_id']??null;$date=$m['date']??null;
    foreach([$from,$uid,$id,$date] as $v)if(!(is_string($v)||is_int($v))||!preg_match('/^[0-9]{1,20}$/D',(string)$v))return $bad('ID_TYPE');
    if((string)$from!==(string)$uid)return $bad('USER_ID_MISMATCH');
    if(!(is_string($m['chat']['id']??null)||is_int($m['chat']['id']??null))||(string)$m['chat']['id']!==(string)$from)return $bad('CHAT_ID_MISMATCH');
    if((int)$date<time()-600)return $bad('STALE');
    if((int)$date>time()+60)return $bad('FUTURE');
    if(!is_string($c['phone_number']??null))return $bad('PHONE_TYPE');
    try{$phone=mkUnifiedPhone($c['phone_number']);}catch(InvalidArgumentException $e){return $bad('PHONE_FORMAT');}
    return ['code'=>'VALID','fields'=>$fields,'phone'=>$phone];
}

/** Read only server-written verification history. Manual IDs/phone flags alone never authorize a returning login. */
function mkUnifiedVerifiedActor(PDO $db,string $provider,string $pid): ?array {
    if(!$db->inTransaction())throw new RuntimeException('Identity transaction required');
    if(!in_array($provider,['telegram','bale','eitaa'],true)||!preg_match('/^[1-9][0-9]{0,19}$/D',$pid))throw new InvalidArgumentException('هویت پیام‌رسان معتبر نیست.');
    $column=$provider.'_id';
    $s=$db->prepare("SELECT * FROM users WHERE `$column`=? ORDER BY id LIMIT 2 FOR UPDATE");$s->execute([$pid]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1)throw new InvalidArgumentException('شناسهٔ پیام‌رسان تکراری است؛ مدیر باید بررسی کند.');
    if(!$rows)return null;
    $u=$rows[0];mkUnifiedOrdinary($u);
    if((int)($u['phone_verified']??0)!==1||(int)($u['phone_locked']??0)!==1)return null;
    try{mkUnifiedPhone(strtr((string)($u['phone']??''),mkUnifiedPhoneCharacters()));}catch(InvalidArgumentException $e){return null;}
    $s=$db->prepare('SELECT proof_hash FROM phone_login_audit WHERE canonical_uid=? AND provider=? AND provider_id=? ORDER BY id DESC LIMIT 1');$s->execute([(int)$u['id'],$provider,$pid]);$proof=$s->fetchColumn();
    if(!is_string($proof)||!preg_match('/^[a-f0-9]{64}$/D',$proof))return null;
    return $u;
}
/** Caller has verified the current signed initData, provider switch and CSRF; no browser phone is used here. */
function mkUnifiedResume(PDO $db,string $provider,array $verifiedUser,string $fingerprint,string $attempt): ?array {
    if(!in_array($provider,['telegram','bale','eitaa'],true)||!melkinoLoginMethodEnabled($provider))throw new InvalidArgumentException('این روش ورود غیرفعال است.');
    $pid=(string)($verifiedUser['id']??'');$date=(int)($verifiedUser['auth_date']??0);
    if(!preg_match('/^[1-9][0-9]{0,19}$/D',$pid)||$date<time()-600||$date>time()+60||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$attempt))throw new InvalidArgumentException('اطلاعات معتبر شروع پیام‌رسان لازم است.');
    $lock='mk-provider-'.substr(hash('sha256',$provider.'|'.$pid),0,40);
    $s=$db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Identity busy');
    $establishing=false;
    try{
        $db->beginTransaction();$u=mkUnifiedVerifiedActor($db,$provider,$pid);
        if(!$u){$db->rollBack();return null;}
        $db->prepare('UPDATE users SET login_count=COALESCE(login_count,0)+1,updated_at=NOW() WHERE id=?')->execute([(int)$u['id']]);
        $_SESSION['mk_login_pending']=['provider'=>$provider,'attempt'=>$attempt,'fingerprint'=>$fingerprint];
        $establishing=true;
        $r=melkinoMessengerEstablish($db,(int)$u['id'],$provider);
        $_SESSION['phone_login_verified']=true;unset($_SESSION['mk_phone_login']);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        // Never leave an authenticated session behind after a failed establishment/commit.
        if($establishing){$keep=[];foreach(['melkino_csrf','mk_login_probe'] as $k)if(isset($_SESSION[$k]))$keep[$k]=$_SESSION[$k];$_SESSION=$keep;}
        throw $e;
    }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    if(!defined('MELKINO_UNIFIED_LOGIN'))define('MELKINO_UNIFIED_LOGIN',true);
    if(function_exists('melkinoRecordLoginInfo'))melkinoRecordLoginInfo((int)$u['id'],$provider,['name'=>$u['name']??'',$provider.'_id'=>$pid]);
    return array_merge($r,['next'=>'done','returning'=>true]);
}
