<?php
declare(strict_types=1);
require_once __DIR__.'/account-link-lib.php';
/** Fixed reason codes and structural types only: never return payload values or credentials. */
class MkPhoneValidationError extends InvalidArgumentException {
    public string $reason;
    public array $diagnostic;
    public function __construct(string $reason,array $diagnostic,string $message='اطلاعات تماس معتبر نیست؛ بررسی پاسخ شماره انجام نشد.') {
        $this->reason='CONTACT_'.$reason;
        $this->diagnostic=$diagnostic;
        parent::__construct($message.' کد: '.$this->reason);
    }
}
function mkPhoneSafeShape(array $data): array {
    $out=['fields'=>[],'contact_fields'=>[]];
    foreach(['auth_date','hash','signature','contact','user','data','phone_number','phone','user_id','id'] as $key){
        if(array_key_exists($key,$data))$out['fields'][$key]=gettype($data[$key]);
    }
    foreach(['contact','user','data'] as $container){
        $object=is_string($data[$container]??null)?json_decode($data[$container],true,16,JSON_BIGINT_AS_STRING):null;
        $out[$container.'_json']=is_array($object)?'object_or_array':'absent_or_invalid';
        $out[$container.'_fields']=[];
        if(is_array($object))foreach(['user_id','id','phone_number','phone','first_name','last_name','user','contact','auth_date'] as $key){
            if(array_key_exists($key,$object))$out[$container.'_fields'][$key]=gettype($object[$key]);
        }
    }
    return $out;
}
/** Verify SIGNED contact payload, not callback booleans, phone strings or initDataUnsafe. */
function mkPhoneVerify(string $raw,string $token,string $expectedId,?int $now=null,string $provider='telegram'): array {
    $data=[];$hashValid=false;
    $bad=static function(string $reason,string $message='اطلاعات تماس معتبر نیست؛ بررسی پاسخ شماره انجام نشد.') use (&$data,&$hashValid): never {
        throw new MkPhoneValidationError($reason,['hash_valid'=>$hashValid]+mkPhoneSafeShape($data),$message);
    };
    if(!in_array($provider,['telegram','eitaa'],true))$bad('PROVIDER');
    if($token==='' || $token==='-' || str_contains($token,'توکن_'))$bad('CONFIG');
    if($expectedId==='')$bad('NO_PENDING_ID');
    if(strlen($raw)>16384)$bad('TOO_LARGE');
    if($raw==='')$bad('EMPTY');
    foreach(explode('&',$raw) as $pair){
        if(!str_contains($pair,'='))$bad('QUERY_FORMAT');[$k,$v]=explode('=',$pair,2);
        if(preg_match('/%(?![a-fA-F0-9]{2})/',$k.$v))$bad('ENCODING');$k=urldecode($k);$v=urldecode($v);
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$k))$bad('KEY_FORMAT');
        if(array_key_exists($k,$data))$bad('DUPLICATE_KEY');
        if(preg_match('/[\x00\r\n]/',$v))$bad('VALUE_FORMAT');
        $data[$k]=$v;if(count($data)>32)$bad('TOO_MANY_FIELDS');
    }
    if(!isset($data['hash']))$bad('HASH_MISSING');
    $hash=$data['hash'];if(!preg_match('/^[a-fA-F0-9]{64}$/D',$hash))$bad('HASH_FORMAT');
    $signed=$data;unset($signed['hash']);ksort($signed,SORT_STRING);
    $lines=[];foreach($signed as $k=>$v)$lines[]=$k.'='.$v;
    $secret=hash_hmac('sha256',trim($token),'WebAppData',true);
    if(!hash_equals(hash_hmac('sha256',implode("\n",$lines),$secret),strtolower($hash)))$bad('HASH_MISMATCH');
    $hashValid=true;
    $now=$now??time();$date=$data['auth_date']??'';
    if($date==='')$bad('DATE_MISSING');
    if(!preg_match('/^[1-9][0-9]{8,10}$/D',$date))$bad('DATE_FORMAT');
    if((int)$date>$now+60)$bad('DATE_FUTURE','ساعت پاسخ تماس جلوتر از سرور است؛ ساعت هاست را بررسی کنید.');
    if((int)$date<$now-600)$bad('DATE_EXPIRED','اعتبار زمانی شماره تمام شده است؛ برنامک را ببندید و دوباره باز کنید.');
    if(!isset($data['contact']))$bad('OBJECT_MISSING');
    $contact=json_decode($data['contact'],true,16,JSON_BIGINT_AS_STRING);
    if(!is_array($contact))$bad('OBJECT_FORMAT');
    if(!isset($contact['user_id']))$bad('USER_ID_MISSING');
    if(!(is_string($contact['user_id'])||is_int($contact['user_id'])))$bad('USER_ID_TYPE');
    $id=(string)$contact['user_id'];if(!preg_match('/^[1-9][0-9]{0,19}$/D',$id))$bad('USER_ID_FORMAT');
    if(!hash_equals($expectedId,$id))$bad('USER_ID_MISMATCH');
    // Eitaa's observed signed contact uses `phone`; Telegram uses `phone_number`.
    // Read aliases ONLY after HMAC, freshness and exact pending-user binding passed.
    $keys=$provider==='eitaa'?['phone','phone_number']:['phone_number'];
    $phone=null;
    foreach($keys as $key){
        if(!array_key_exists($key,$contact))continue;
        $candidate=$contact[$key];if(!is_string($candidate))$bad('PHONE_TYPE');
        $candidate=trim($candidate);
        if(preg_match('/^(?:\+98|0098|98)(9[0-9]{9})$/D',$candidate,$m))$candidate='0'.$m[1];
        if(!preg_match('/^09[0-9]{9}$/D',$candidate))$bad('PHONE_FORMAT','شمارهٔ اشتراک‌گذاشته‌شده باید شمارهٔ موبایل ایران باشد.');
        if($phone!==null&&!hash_equals($phone,$candidate))$bad('PHONE_CONFLICT');
        $phone=$candidate;
    }
    if($phone===null)$bad('PHONE_MISSING');
    return ['phone'=>$phone,'provider_id'=>$id,'auth_date'=>(int)$date,'expires_at'=>min($now+600,(int)$date+600),'proof_hash'=>hash('sha256',strtolower($hash))];
}
function mkPhoneSchema(PDO $db): void {
    mkLinkSchema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS account_phone_proofs (
        user_id INT NOT NULL, provider VARCHAR(16) NOT NULL, provider_id VARCHAR(64) NOT NULL,
        phone VARCHAR(30) NOT NULL, auth_date BIGINT NOT NULL, expires_at BIGINT NOT NULL,
        proof_hash CHAR(64) NOT NULL, fingerprint CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'active',
        PRIMARY KEY(user_id,provider), UNIQUE KEY uq_proof(proof_hash), INDEX ix_phone(phone,state,expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS account_phone_pairs (
        request_id BIGINT UNSIGNED PRIMARY KEY, source_proof CHAR(64) NOT NULL, target_proof CHAR(64) NOT NULL,
        UNIQUE KEY uq_pair(source_proof,target_proof)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function mkPhoneTransactional(PDO $db): void {
    $required=['users','account_link_requests','account_merge_audit','account_phone_proofs','account_phone_pairs'];
    $s=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()");$engines=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($required as $table)if(strtolower((string)($engines[$table]??''))!=='innodb')throw new RuntimeException('Transactional phone storage required');
}
function mkPhoneStore(PDO $db,int $uid,string $provider,array $proof): array {
    mkPhoneTransactional($db);
    if(!in_array($provider,['telegram','eitaa'],true))throw new InvalidArgumentException('تأیید شمارهٔ این پیام‌رسان به مسیر امن جداگانه نیاز دارد.');
    $lock='mk-phone-'.substr(hash('sha256',$proof['phone']),0,40);$locked=false;
    try {
        $s=$db->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);$locked=(int)$s->fetchColumn()===1;if(!$locked)throw new RuntimeException('Phone busy');
        $db->beginTransaction();$u=mkLinkUser($db,$uid,true);
        $check=$db->prepare('SELECT COUNT(*) FROM users WHERE `'.$provider.'_id`=?');$check->execute([$proof['provider_id']]);
        if((int)$check->fetchColumn()!==1 || !hash_equals((string)($u[$provider.'_id']??''),$proof['provider_id']))throw new InvalidArgumentException('هویت پیام‌رسان با حساب جاری یکسان نیست.');
        $phone=trim((string)($u['phone']??''));
        if($phone!=='' && $phone!==$proof['phone'])throw new InvalidArgumentException('شمارهٔ واقعی پیام‌رسان با شمارهٔ ثبت‌شدهٔ حساب متفاوت است؛ اصلاح شماره باید توسط مدیر بررسی شود.');
        $s=$db->prepare('SELECT * FROM account_phone_proofs WHERE user_id=? AND provider=? FOR UPDATE');$s->execute([$uid,$provider]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if($old && ((int)$proof['auth_date']<(int)$old['auth_date'] || ($old['state']!=='active' && (int)$proof['auth_date']<=(int)$old['auth_date'])))throw new InvalidArgumentException('تأیید قبلی دیگر قابل استفاده نیست؛ چند لحظه بعد شماره را دوباره با پیام‌رسان به اشتراک بگذارید.');
        $s=$db->prepare('SELECT id FROM users WHERE phone=? AND id<>? ORDER BY id');$s->execute([$proof['phone'],$uid]);$others=$s->fetchAll(PDO::FETCH_COLUMN);
        if(!$others){
            $db->prepare('UPDATE users SET phone=?,phone_verified=1,phone_locked=1,updated_at=NOW() WHERE id=?')->execute([$proof['phone'],$uid]);
            $u=mkLinkUser($db,$uid);
        }
        // Signature freshness is never extended by replaying the same payload.
        $db->prepare("INSERT INTO account_phone_proofs(user_id,provider,provider_id,phone,auth_date,expires_at,proof_hash,fingerprint,state) VALUES(?,?,?,?,?,?,?,?,'active') ON DUPLICATE KEY UPDATE provider_id=VALUES(provider_id),phone=VALUES(phone),auth_date=VALUES(auth_date),expires_at=VALUES(expires_at),proof_hash=VALUES(proof_hash),fingerprint=VALUES(fingerprint),state='active'")->execute([$uid,$provider,$proof['provider_id'],$proof['phone'],$proof['auth_date'],$proof['expires_at'],$proof['proof_hash'],mkLinkFingerprint($u)]);
        $db->commit();return ['proof_hash'=>$proof['proof_hash']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{if($locked){try{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}catch(Throwable $e){}}}
}
function mkPhoneProof(PDO $db,int $uid,string $hash,bool $lock=false): array {
    $s=$db->prepare('SELECT * FROM account_phone_proofs WHERE user_id=? AND proof_hash=?'.($lock?' FOR UPDATE':''));$s->execute([$uid,$hash]);$p=$s->fetch(PDO::FETCH_ASSOC);
    if(!$p || $p['state']!=='active'||(int)$p['expires_at']<=time())throw new InvalidArgumentException('تأیید شماره منقضی یا لغو شده است؛ دوباره دکمهٔ تأیید شماره را بزنید.');return $p;
}
function mkPhoneProofValid(PDO $db,array $p): array {
    $u=mkLinkUser($db,(int)$p['user_id']);
    if(!hash_equals($p['fingerprint'],mkLinkFingerprint($u))||!hash_equals((string)($u[$p['provider'].'_id']??''),$p['provider_id']))throw new InvalidArgumentException('اطلاعات حساب تغییر کرده است؛ شماره را دوباره تأیید کنید.');
    return $u;
}
/** No account details are disclosed until both accounts have fresh, matching signed phones. */
function mkPhoneState(PDO $db,int $uid,string $hash): array {
    $s=$db->prepare("SELECT r.* FROM account_link_requests r JOIN account_phone_pairs p ON p.request_id=r.id WHERE r.state='done' AND ((r.source_uid=? AND p.source_proof=?) OR (r.target_uid=? AND p.target_proof=?)) ORDER BY r.id DESC LIMIT 1");$s->execute([$uid,$hash,$uid,$hash]);$done=$s->fetch(PDO::FETCH_ASSOC);
    if($done)return ['state'=>'done','keep_uid'=>(int)$done['source_uid']];
    $p=mkPhoneProof($db,$uid,$hash);$u=mkPhoneProofValid($db,$p);
    $phone=$p['phone'];$out=['state'=>'verified','phone'=>$phone,'expires_at'=>(int)$p['expires_at']];
    $s=$db->prepare('SELECT id FROM users WHERE phone=? ORDER BY id');$s->execute([$phone]);$owners=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    if(count($owners)>1)throw new InvalidArgumentException('این شماره چند سابقهٔ قدیمی دارد؛ مدیر باید ابتدا سوابق تکراری را بررسی کند.');
    $s=$db->prepare("SELECT * FROM account_phone_proofs WHERE phone=? AND user_id<>? AND state='active' AND expires_at>? ORDER BY auth_date DESC");$s->execute([$phone,$uid,time()]);$candidates=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $other){
        try{$otherU=mkPhoneProofValid($db,$other);}catch(InvalidArgumentException $e){continue;}
        if($other['provider']===$p['provider'])continue;
        $candidates[(int)$other['user_id']]=[$other,$otherU];
    }
    if(count($candidates)>1)throw new InvalidArgumentException('بیش از دو حساب آمادهٔ اتصال هستند؛ درخواست‌های اضافی را لغو کنید.');
    if(!$candidates){$out['state']=($owners && $owners[0]!==$uid)?'waiting_other':'verified';return $out;}
    [$other,$otherU]=array_values($candidates)[0];
    $keep=$owners[0]??$uid;
    if(!in_array($keep,[$uid,(int)$other['user_id']],true)){$out['state']='waiting_other';return $out;}
    $source=$keep===$uid?$u:$otherU;$target=$keep===$uid?$otherU:$u;
    $sp=$keep===$uid?$p:$other;$tp=$keep===$uid?$other:$p;
    try{mkLinkCompatible($source,$target);}catch(InvalidArgumentException $e){$out['state']='blocked';$out['message']=$e->getMessage();return $out;}
    return $out+['pair'=>['source_uid'=>(int)$source['id'],'target_uid'=>(int)$target['id'],'source_proof'=>$sp['proof_hash'],'target_proof'=>$tp['proof_hash']]];
}
function mkPhonePublicState(array $s): array {
    if(isset($s['pair'])){$s['state']='ready';unset($s['pair']);}
    return $s;
}
function mkPhoneConfirm(PDO $db,int $uid,string $hash): int {
    mkPhoneTransactional($db);
    $state=mkPhoneState($db,$uid,$hash);if(!isset($state['pair']))throw new InvalidArgumentException('شماره در هر دو حساب تأیید نشده است؛ در پیام‌رسان دیگر هم دکمهٔ تأیید را بزنید.');
    $pair=$state['pair'];$ids=[$pair['source_uid'],$pair['target_uid']];sort($ids);
    $db->beginTransaction();try{
        $users=[];foreach($ids as $i)$users[$i]=mkLinkUser($db,$i,true);
        $proofs=[];
        foreach($ids as $i){$h=$i===$pair['source_uid']?$pair['source_proof']:$pair['target_proof'];$proofs[$i]=mkPhoneProof($db,$i,$h,true);mkPhoneProofValid($db,$proofs[$i]);}
        $sp=$proofs[$pair['source_uid']];$tp=$proofs[$pair['target_uid']];
        if($sp['phone']!==$tp['phone']||$sp['provider']===$tp['provider']||$sp['phone']!==trim((string)$users[$pair['source_uid']]['phone']))throw new InvalidArgumentException('تأیید شمارهٔ دو حساب یکسان نیست.');
        $db->prepare("INSERT INTO account_link_requests(code_hash,source_uid,target_uid,state,expires_at,created_at,source_fingerprint,target_fingerprint) VALUES(?,?,?,'waiting',?,?,?,?)")->execute([hash('sha256',random_bytes(32)),$pair['source_uid'],$pair['target_uid'],min((int)$sp['expires_at'],(int)$tp['expires_at']),time(),$sp['fingerprint'],$tp['fingerprint']]);
        $id=(int)$db->lastInsertId();$r=mkLinkRequest($db,$id,true);
        $db->prepare('INSERT INTO account_phone_pairs(request_id,source_proof,target_proof) VALUES(?,?,?)')->execute([$id,$sp['proof_hash'],$tp['proof_hash']]);
        mkLinkMergeLocked($db,$r,$users[$pair['source_uid']],$users[$pair['target_uid']]);
        $db->prepare("UPDATE account_phone_proofs SET state='consumed' WHERE user_id IN (?,?)")->execute($ids);
        $db->prepare("UPDATE account_link_requests SET state='done',completed_at=? WHERE id=?")->execute([time(),$id]);
        $db->commit();return $pair['source_uid'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function mkPhoneCancel(PDO $db,int $uid,string $hash): void {
    mkPhoneTransactional($db);
    $db->beginTransaction();try{
        mkLinkUser($db,$uid,true);$p=mkPhoneProof($db,$uid,$hash,true);
        $db->prepare("UPDATE account_phone_proofs SET state='cancelled' WHERE user_id=? AND proof_hash=?")->execute([$uid,$hash]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
