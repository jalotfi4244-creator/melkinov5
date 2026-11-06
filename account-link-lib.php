<?php
declare(strict_types=1);
// Internal service. The HTTP controller supplies a server-authenticated user ID.
function mkLinkSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS account_link_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code_hash CHAR(64) NOT NULL UNIQUE,
        source_uid INT NOT NULL, target_uid INT NULL, challenge CHAR(6) NULL,
        state VARCHAR(16) NOT NULL DEFAULT 'open', expires_at BIGINT NOT NULL,
        created_at BIGINT NOT NULL, completed_at BIGINT NULL,
        source_fingerprint CHAR(64) NOT NULL, target_fingerprint CHAR(64) NULL,
        INDEX ix_source(source_uid,state), INDEX ix_target(target_uid,state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS account_link_limits (
        user_id INT PRIMARY KEY, window_at BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS account_merge_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id BIGINT NOT NULL UNIQUE,
        keep_uid INT NOT NULL, absorbed_uid INT NOT NULL, merged_at BIGINT NOT NULL,
        absorbed_snapshot LONGTEXT NOT NULL, INDEX ix_keep(keep_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function mkLinkUser(PDO $db, int $id, bool $lock=false): array {
    $st=$db->prepare('SELECT * FROM users WHERE id=?'.($lock?' FOR UPDATE':''));$st->execute([$id]);
    $u=$st->fetch(PDO::FETCH_ASSOC);
    if (!$u || empty($u['is_active'])) throw new InvalidArgumentException('حساب فعال پیدا نشد؛ دوباره از پیام‌رسان وارد شوید.');
    return $u;
}
function mkLinkFingerprint(array $u): string {
    $data=[];
    foreach (['id','telegram_id','bale_id','eitaa_id','phone','is_active'] as $key) $data[$key]=trim((string)($u[$key]??''));
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function mkLinkProviders(array $u): array {
    $out=[];foreach (['telegram_id'=>'تلگرام','bale_id'=>'بله','eitaa_id'=>'ایتا'] as $key=>$label) if (trim((string)($u[$key]??''))!=='') $out[]=$label;
    return $out;
}
function mkLinkCompatible(array $source, array $target, bool $phoneAuthority=false): void {
    if ((int)$source['id']===(int)$target['id']) throw new InvalidArgumentException('این دو نشست همین حالا متعلق به یک حساب هستند؛ اتصال دوباره لازم نیست.');
    if (trim((string)($source['phone']??''))==='') throw new InvalidArgumentException('کد اتصال را از حساب قبلی که شماره روی آن ثبت شده بسازید.');
    if (!$phoneAuthority && (!mkLinkProviders($source) || !mkLinkProviders($target))) throw new InvalidArgumentException('برای اتصال، هر دو حساب باید از پیام‌رسان وارد شده باشند.');
    foreach (['telegram_id','bale_id','eitaa_id'] as $key) {
        $a=trim((string)($source[$key]??''));$b=trim((string)($target[$key]??''));
        if ($a!=='' && $b!=='' && $a!==$b) throw new InvalidArgumentException('دو شناسهٔ متفاوت از یک پیام‌رسان قابل اتصال خودکار نیستند؛ از پشتیبانی کمک بگیرید.');
    }
    $phone=trim((string)($target['phone']??''));
    if ($phone!=='' && $phone!==trim((string)$source['phone'])) throw new InvalidArgumentException('این حساب‌ها شماره‌های متفاوت دارند؛ اتصال خودکار انجام نمی‌شود.');
    foreach (['is_admin','is_superadmin'] as $key) if (!empty($source[$key]) || !empty($target[$key])) throw new InvalidArgumentException('حساب دارای دسترسی ویژه باید توسط مدیر بررسی شود.');
    if (isset($source['role']) || isset($target['role'])) foreach ([$source,$target] as $u) if (!in_array((string)($u['role']??''),['','user','member'],true)) throw new InvalidArgumentException('حساب دارای نقش ویژه نیاز به بررسی مدیر دارد.');
}
function mkLinkRate(PDO $db,int $uid): void {
    $now=time();
    $db->prepare('INSERT IGNORE INTO account_link_limits(user_id,window_at,attempts) VALUES(?,?,0)')->execute([$uid,$now]);
    $db->prepare('UPDATE account_link_limits SET attempts=IF(window_at < ?,1,attempts+1), window_at=IF(window_at < ?,?,window_at) WHERE user_id=?')->execute([$now-600,$now-600,$now,$uid]);
    $s=$db->prepare('SELECT attempts FROM account_link_limits WHERE user_id=?');$s->execute([$uid]);
    if ((int)$s->fetchColumn()>15) throw new InvalidArgumentException('تعداد تلاش‌ها زیاد است؛ ده دقیقه بعد دوباره امتحان کنید.');
}
function mkLinkRequest(PDO $db,int $id,bool $lock=false): array {
    $s=$db->prepare('SELECT * FROM account_link_requests WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);
    $r=$s->fetch(PDO::FETCH_ASSOC);if (!$r) throw new InvalidArgumentException('درخواست اتصال پیدا نشد.');return $r;
}
function mkLinkOpen(array $r,string $state): void {
    if ($r['state']!==$state || (int)$r['expires_at']<=time()) throw new InvalidArgumentException('درخواست منقضی، لغو یا استفاده شده است؛ کد تازه بسازید.');
}
function mkLinkIssue(PDO $db,int $uid): array {
    mkLinkRate($db,$uid);$code=bin2hex(random_bytes(16));$now=time();
    $db->beginTransaction();try {
        $u=mkLinkUser($db,$uid,true);
        if (empty($u['phone']) || !mkLinkProviders($u)) throw new InvalidArgumentException('ابتدا از حساب قبلیِ دارای شماره در پیام‌رسان وارد شوید.');
        $db->prepare("UPDATE account_link_requests SET state='cancelled' WHERE source_uid=? AND state IN ('open','waiting')")->execute([$uid]);
        $db->prepare('INSERT INTO account_link_requests(code_hash,source_uid,expires_at,created_at,source_fingerprint) VALUES(?,?,?,?,?)')->execute([hash('sha256',$code),$uid,$now+600,$now,mkLinkFingerprint($u)]);
        $id=(int)$db->lastInsertId();$db->commit();return ['id'=>$id,'code'=>implode('-',str_split($code,4)),'expires_at'=>$now+600];
    } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
}
function mkLinkClaim(PDO $db,int $uid,string $code): array {
    mkLinkUser($db,$uid);mkLinkRate($db,$uid);
    $code=strtolower(preg_replace('/[\s-]+/','',trim($code))??'');
    if (!preg_match('/^[a-f0-9]{32}$/D',$code)) throw new InvalidArgumentException('کد اتصال معتبر نیست.');
    $db->beginTransaction();try {
        $s=$db->prepare('SELECT * FROM account_link_requests WHERE code_hash=? FOR UPDATE');$s->execute([hash('sha256',$code)]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new InvalidArgumentException('کد اتصال معتبر نیست یا منقضی شده است.');
        mkLinkOpen($r,'open');
        $source=mkLinkUser($db,(int)$r['source_uid']);$target=mkLinkUser($db,$uid);
        if (!hash_equals($r['source_fingerprint'],mkLinkFingerprint($source))) throw new InvalidArgumentException('مشخصات حساب قبلی تغییر کرده است؛ کد تازه بسازید.');
        mkLinkCompatible($source,$target);
        $db->prepare("UPDATE account_link_requests SET state='cancelled' WHERE target_uid=? AND id<>? AND state='waiting'")->execute([$uid,$r['id']]);
        $challenge=(string)random_int(100000,999999);
        $db->prepare("UPDATE account_link_requests SET target_uid=?,target_fingerprint=?,challenge=?,state='waiting' WHERE id=?")->execute([$uid,mkLinkFingerprint($target),$challenge,$r['id']]);
        $db->commit();return ['id'=>(int)$r['id'],'challenge'=>$challenge];
    } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Must run within the confirmation transaction. Any transfer failure aborts ALL changes. */
function mkLinkMergeLocked(PDO $db,array $r,array $keep,array $abs,bool $phoneAuthority=false): void {
    if (!$db->inTransaction() || $r['state']!=='waiting' || (int)$r['source_uid']!==(int)$keep['id'] || (int)$r['target_uid']!==(int)$abs['id']) throw new RuntimeException('Missing confirmation transaction');
    $keepId=(int)$keep['id'];$absId=(int)$abs['id'];mkLinkCompatible($keep,$abs,$phoneAuthority);
    $refs=[];$engines=[];
    foreach($db->query('SHOW TABLE STATUS')->fetchAll(PDO::FETCH_ASSOC) as $table){
        $name=(string)$table['Name'];$engines[$name]=strtolower((string)($table['Engine']??''));
        if (in_array($name,['users','account_link_requests','account_link_limits','account_merge_audit','account_phone_proofs','account_phone_pairs'],true)) continue;
        if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Unsupported table name');
        $cols=$db->query('SHOW COLUMNS FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $c) if(in_array(strtolower($c['Field']),['user_id','owner_user_id'],true)) $refs[$name][$c['Field']]=true;
    }
    // Preserve the server-verified provider history when a canonical account absorbs another.
    if(isset($engines['phone_login_audit']))$refs['phone_login_audit']['canonical_uid']=true;
    // Include actual foreign keys as well; never let DELETE CASCADE discard a user's data.
    $fk=$db->query("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME='id'")->fetchAll(PDO::FETCH_ASSOC);
    $schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    foreach($fk as $f){
        if($f['TABLE_SCHEMA']!==$schema)throw new RuntimeException('Cross-database account reference');
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$f['TABLE_NAME'])||!preg_match('/^[a-zA-Z0-9_]+$/D',$f['COLUMN_NAME'])||$f['TABLE_NAME']==='users')throw new RuntimeException('Unsupported account reference');
        $refs[$f['TABLE_NAME']][$f['COLUMN_NAME']]=true;
    }
    foreach(['users','account_link_requests','account_merge_audit'] as $table) if(($engines[$table]??'')!=='innodb') throw new RuntimeException('Transactional storage required');
    $moves=[];
    foreach($refs as $table=>$cols) foreach(array_keys($cols) as $col){
        $s=$db->prepare("SELECT 1 FROM `$table` WHERE `$col`=? LIMIT 1");$s->execute([$absId]);
        if($s->fetchColumn()){
            if(($engines[$table]??'')!=='innodb')throw new RuntimeException('Nontransactional related data');
            $moves[]=[$table,$col];
        }
    }
    $snapshot=$abs;unset($snapshot['access_token'],$snapshot['last_init_data']);
    $db->prepare('INSERT INTO account_merge_audit(request_id,keep_uid,absorbed_uid,merged_at,absorbed_snapshot) VALUES(?,?,?,?,?)')->execute([$r['id'],$keepId,$absId,time(),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    // Release UNIQUE provider IDs before copying them. Rollback restores them on ANY failure.
    $clear=[];foreach(['telegram_id','bale_id','eitaa_id','phone','access_token'] as $col) if(array_key_exists($col,$abs))$clear[]="`$col`=NULL";
    $db->prepare('UPDATE users SET '.implode(',',$clear).' WHERE id=?')->execute([$absId]);
    $sets=[];$params=[];
    foreach(['telegram_id','bale_id','eitaa_id','telegram_username','bale_username','eitaa_username','username','photo_url','language_code','telegram_first_name','telegram_last_name','telegram_photo_url','first_name','last_name','name'] as $col){
        if($phoneAuthority&&in_array($col,['name','first_name','last_name'],true))continue;
        if(array_key_exists($col,$keep)&&trim((string)($keep[$col]??''))===''&&trim((string)($abs[$col]??''))!==''){$sets[]="`$col`=?";$params[]=$abs[$col];}
    }
    if(array_key_exists('login_count',$keep)){$sets[]='login_count=?';$params[]=max(0,(int)$keep['login_count'])+max(0,(int)($abs['login_count']??0));}
    if(array_key_exists('updated_at',$keep))$sets[]='updated_at=NOW()';
    if($sets){$params[]=$keepId;$db->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($params);}
    foreach($moves as [$table,$col])$db->prepare("UPDATE `$table` SET `$col`=? WHERE `$col`=?")->execute([$keepId,$absId]);
    // No catch-and-continue, IGNORE or disabling constraints: conflicts require manual review.
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$absId]);
    $db->prepare("UPDATE account_link_requests SET state='cancelled' WHERE id<>? AND state IN ('open','waiting') AND (source_uid IN (?,?) OR target_uid IN (?,?))")->execute([$r['id'],$keepId,$absId,$keepId,$absId]);
}
function mkLinkApprove(PDO $db,int $uid,int $id,string $challenge): int {
    $db->beginTransaction();try{
        $r=mkLinkRequest($db,$id,true);mkLinkOpen($r,'waiting');
        if((int)$r['source_uid']!==$uid || !hash_equals((string)$r['challenge'],$challenge))throw new InvalidArgumentException('فقط حساب قبلی با کد تطبیق صحیح می‌تواند اتصال را تأیید کند.');
        $ids=[$uid,(int)$r['target_uid']];sort($ids);$users=[];foreach($ids as $i)$users[$i]=mkLinkUser($db,$i,true);
        $keep=$users[$uid];$abs=$users[(int)$r['target_uid']];
        if(!hash_equals($r['source_fingerprint'],mkLinkFingerprint($keep))||!hash_equals($r['target_fingerprint'],mkLinkFingerprint($abs)))throw new InvalidArgumentException('هویت یا شمارهٔ یکی از حساب‌ها تغییر کرده است؛ کد تازه بسازید.');
        mkLinkMergeLocked($db,$r,$keep,$abs);
        $db->prepare("UPDATE account_link_requests SET state='done',completed_at=? WHERE id=?")->execute([time(),$id]);
        $db->commit();return $uid;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function mkLinkCancel(PDO $db,int $uid,int $id): void {
    $db->beginTransaction();try {
        $r=mkLinkRequest($db,$id,true);
        if(!in_array($uid,[(int)$r['source_uid'],(int)$r['target_uid']],true))throw new InvalidArgumentException('این درخواست متعلق به حساب شما نیست.');
        if(!in_array($r['state'],['open','waiting'],true))throw new InvalidArgumentException('این درخواست دیگر قابل لغو نیست؛ اتصال انجام‌شده با لغو درخواست برنمی‌گردد.');
        $db->prepare("UPDATE account_link_requests SET state='cancelled' WHERE id=?")->execute([$id]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function mkLinkStatus(PDO $db,int $uid,int $id): array {
    $r=mkLinkRequest($db,$id);
    if(!in_array($uid,[(int)$r['source_uid'],(int)$r['target_uid']],true))throw new InvalidArgumentException('این درخواست متعلق به حساب شما نیست.');
    $state=$r['state'];if(in_array($state,['open','waiting'],true)&&(int)$r['expires_at']<=time())$state='expired';
    $out=['id'=>(int)$r['id'],'state'=>$state,'expires_at'=>(int)$r['expires_at'],'side'=>$uid===(int)$r['source_uid']?'source':'target'];
    if($state==='waiting'){
        $u=mkLinkUser($db,(int)$r['target_uid']);$out['challenge']=$r['challenge'];$out['providers']=mkLinkProviders($u);$out['name']=(string)($u['name']??'');
    }
    if($state==='done')$out['keep_uid']=(int)$r['source_uid'];
    return $out;
}
