<?php
/** Real Eitaayar channel publishing, durable attempts, no browser token and no blind retry. */
declare(strict_types=1);
require_once __DIR__ . '/bot-api-client.php';

function melkinoEitaaPublishEnsure(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM melkino_eitaa_publish_attempts LIMIT 1'); }
    catch (PDOException $e) {
        if ((int)($e->errorInfo[1]??0)!==1146) throw $e;
        $pdo->exec("CREATE TABLE IF NOT EXISTS melkino_eitaa_publish_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ad_id VARCHAR(64) NOT NULL, channel_id VARCHAR(128) NOT NULL,
            payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status VARCHAR(16) NOT NULL, media_kind VARCHAR(16) NOT NULL,
            message_id VARCHAR(80) NULL, note VARCHAR(1200) NOT NULL,
            admin_id INT NULL, actor VARCHAR(120) NULL,
            started_epoch BIGINT UNSIGNED NOT NULL, finished_epoch BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eitaa_request (request_id),
            KEY idx_eitaa_ad (ad_id,id), KEY idx_eitaa_target (ad_id,channel_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Existing management history schema, CREATE only when absent (no ALTER).
    try { $pdo->query('SELECT id,ad_id,action,detail,actor,created_at FROM ads_history LIMIT 0'); }
    catch (PDOException $e) {
        if ((int)($e->errorInfo[1]??0)!==1146) throw $e;
        $pdo->exec("CREATE TABLE IF NOT EXISTS ads_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad_id VARCHAR(64) NOT NULL,
            action VARCHAR(60) NOT NULL, detail VARCHAR(500) NULL, actor VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_ah_ad (ad_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function melkinoEitaaPublishAdId(array $data): string {
    $id=is_scalar($data['ad_id']??$data['id']??null)?trim((string)($data['ad_id']??$data['id'])):'';
    if ($id==='' || mb_strlen($id)>64 || preg_match('/[\x00-\x1F]/',$id)) throw new MelkinoBotSettingsError('شناسه آگهی معتبر نیست.');
    return $id;
}
function melkinoEitaaPublicPhoto(PDO $pdo, string $adId, array $ad): ?array {
    if (($ad['publish_photos']??'yes')==='no') return null;
    $root=realpath(__DIR__.'/uploads');if (!$root) return null;
    $q=$pdo->prepare('SELECT filename FROM images WHERE ad_id=? AND is_selected=1 AND publish_publicly=1 ORDER BY is_primary DESC,sort_order ASC,id ASC LIMIT 1');
    $q->execute([$adId]);$name=$q->fetchColumn();
    if (!is_string($name) || $name==='') return null;
    $name=str_replace('\\','/',$name);
    foreach ([__DIR__.'/'.ltrim($name,'/'),__DIR__.'/uploads/'.ltrim($name,'/')] as $candidate) {
        $real=realpath($candidate);
        if (!$real || !str_starts_with($real,$root.DIRECTORY_SEPARATOR) || !is_file($real)) continue;
        if (!in_array(strtolower(pathinfo($real,PATHINFO_EXTENSION)),['jpg','jpeg','png','webp','gif'],true)) continue;
        if (filesize($real)>20*1024*1024) throw new MelkinoBotSettingsError('تصویر برای ارسال خیلی بزرگ است؛ تصویر کوچک‌تر یا ارسال بدون تصویر را انتخاب کنید.');
        return ['path'=>$real,'sha256'=>hash_file('sha256',$real),'name'=>basename($real)];
    }
    return null;
}
function melkinoEitaaPublishFields(array $ad, array $input): array {
    $defs=melkinoPublishFieldDefs((string)($ad['property_type']??''),(string)($ad['transaction_type']??''));
    // Shared defaults exclude identity/contact; only an explicit admin choice enables them.
    $settings=melkinoPublishSettings('eitaa',(string)($ad['property_type']??''),(string)($ad['transaction_type']??''));
    $fields=$settings['fields']??array_keys($defs);
    if (array_key_exists('fields',$input)) {
        if (!is_array($input['fields'])) throw new MelkinoBotSettingsError('فهرست فیلدها معتبر نیست.');
        foreach ($input['fields'] as $v) if (!is_string($v)) throw new MelkinoBotSettingsError('فیلد انتشار معتبر نیست.');
        $fields=$input['fields'];
    }
    return [$defs,array_values(array_intersect(array_keys($defs),$fields))];
}
function melkinoEitaaAdText(array $ad, array $fields): string {
    if (!empty($ad['price_hidden'])) {
        foreach (['price_sell','total_price','display_price','deposit','rent_monthly','full_rent','down_payment','loan_amount','loan_installment'] as $k) $ad[$k]='';
        $ad['has_loan']=0;
    }
    return melkinoAdMessageText($ad,false,'eitaa',$fields);
}
function melkinoPrepareEitaaPublish(PDO $pdo, array $input): array {
    $id=melkinoEitaaPublishAdId($input);
    $st=$pdo->prepare('SELECT * FROM ads WHERE id=? LIMIT 1');$st->execute([$id]);$ad=$st->fetch(PDO::FETCH_ASSOC);
    if (!$ad) throw new MelkinoBotSettingsError('آگهی پیدا نشد.',[],404);
    [$defs,$fields]=melkinoEitaaPublishFields($ad,$input);
    $photo=melkinoEitaaPublicPhoto($pdo,$id,$ad);
    return ['success'=>true,'ad_id'=>$id,'platform'=>'eitaa','title'=>(string)($ad['title']??''),
        'chat_id'=>melkinoBotNormalizeChannel(melkinoBotSetting('eitaa_channel')),
        'configured'=>melkinoBotSetting('eitaa_channel_token')!=='',
        'text'=>melkinoEitaaAdText($ad,$fields),'fields'=>$fields,'field_defs'=>$defs,
        'has_photo'=>$photo!==null,'photo_url'=>$photo && function_exists('melkinoAdImageUrl')?melkinoAdImageUrl($ad):'',
        'request_id'=>bin2hex(random_bytes(16)),
        'hint'=>'ارسال از سرور به کانال ایتایار است؛ متن کوتاه‌تر برای کپشن تصویر مناسب‌تر است. هیچ ارسال مجددی بدون تأیید شما انجام نمی‌شود.'];
}
function melkinoEitaaPublishResult(array $row, bool $reused = false): array {
    $status=(string)$row['status'];
    if ($status==='sending' && time()-(int)$row['started_epoch']>120) $status='unknown';
    return ['success'=>$status==='published','status'=>$status,'platform'=>'eitaa','attempt_id'=>(int)$row['id'],
        'message_id'=>$row['message_id']??null,'channel_id'=>$row['channel_id'],'request_id'=>$row['request_id'],
        'message'=>$row['note'],'reused'=>$reused,'can_force'=>in_array($status,['published','unknown'],true),
        'created_at'=>$row['created_at']??null];
}
/** $transport is an internal dependency, never read from request/settings. */
function melkinoPublishEitaa(PDO $pdo, array $input, array $actor, ?callable $transport = null): array {
    $id=melkinoEitaaPublishAdId($input);
    $request=is_string($input['request_id']??null)?$input['request_id']:'';
    if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$request)) throw new MelkinoBotSettingsError('شناسهٔ تلاش انتشار معتبر نیست؛ پیش‌نمایش را دوباره باز کنید.');
    $channel=melkinoBotValidateValue('eitaa_channel',melkinoBotSetting('eitaa_channel'));
    $token=melkinoBotSetting('eitaa_channel_token');
    $aid=(int)($actor['id']??0);$actorName=mb_substr((string)($actor['name']??'مدیر'),0,120);
    $force=in_array($input['force']??false,[true,1,'1'],true);
    $wantPhoto=in_array($input['has_photo']??false,[true,1,'1'],true);
    melkinoEitaaPublishEnsure($pdo);
    // Legacy format/consultant helpers can initialize tables. Run them BEFORE locking.
    $q=$pdo->prepare('SELECT * FROM ads WHERE id=? LIMIT 1');$q->execute([$id]);$snapshot=$q->fetch(PDO::FETCH_ASSOC);
    if (!$snapshot) throw new MelkinoBotSettingsError('آگهی پیدا نشد.',[],404);
    if (isset($input['text']) && !is_string($input['text'])) throw new MelkinoBotSettingsError('متن انتشار معتبر نیست.');
    [$defs,$fields]=melkinoEitaaPublishFields($snapshot,$input);
    $text=array_key_exists('text',$input)?trim($input['text']):melkinoEitaaAdText($snapshot,$fields);
    if ($text==='' || mb_strlen($text)>16000) throw new MelkinoBotSettingsError('متن باید غیرخالی و حداکثر ۱۶٬۰۰۰ نویسه باشد؛ محدودیت نهایی را سرویس تعیین می‌کند.');
    $pdo->beginTransaction();
    try {
        // Serialize reservations using the EXISTING ad row. No schema change or network under lock.
        $q=$pdo->prepare('SELECT * FROM ads WHERE id=? FOR UPDATE');$q->execute([$id]);$ad=$q->fetch(PDO::FETCH_ASSOC);
        if (!$ad) throw new MelkinoBotSettingsError('آگهی پیدا نشد.',[],404);
        $q=$pdo->prepare('SELECT * FROM melkino_eitaa_publish_attempts WHERE request_id=?');$q->execute([$request]);$old=$q->fetch(PDO::FETCH_ASSOC);
        $photo=$wantPhoto?melkinoEitaaPublicPhoto($pdo,$id,$ad):null;
        if ($wantPhoto && !$photo) throw new MelkinoBotSettingsError('تصویر عمومی منتخب در دسترس نیست؛ بدون تصویر ارسال کنید یا دوباره پیش‌نمایش بگیرید.');
        $digest=hash('sha256',json_encode([$id,$channel,$text,$photo['sha256']??''],JSON_UNESCAPED_UNICODE));
        if ($old) {
            if ((int)$old['admin_id']!==$aid || $old['ad_id']!==$id || !hash_equals($old['payload_sha256'],$digest)) {
                throw new MelkinoBotSettingsError('شناسهٔ این تلاش قبلاً برای محتوای دیگری استفاده شده است.',[],409);
            }
            $pdo->commit();return melkinoEitaaPublishResult($old,true);
        }
        $q=$pdo->prepare('SELECT * FROM melkino_eitaa_publish_attempts WHERE ad_id=? AND channel_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$id,$channel]);$last=$q->fetch(PDO::FETCH_ASSOC);
        if ($last) {
            $state=melkinoEitaaPublishResult($last);
            $live=$state['status']==='sending';
            $uncertain=$state['status']==='unknown';
            $recent=$state['status']==='published' && time()-(int)($last['finished_epoch']??$last['started_epoch'])<120;
            if ($live || (!$force && ($uncertain || $recent))) {
                $pdo->rollBack();
                return array_merge($state,['success'=>false,'blocked'=>true,'can_force'=>!$live,
                    'message'=>$live?'یک ارسال برای همین آگهی و کانال در حال انجام است؛ صبر کنید.':($uncertain?'نتیجهٔ ارسال قبلی نامشخص است؛ پیش از ارسال دوباره کانال را بررسی کنید.':'این آگهی کمتر از دو دقیقه پیش منتشر شده است؛ ارسال مجدد نیاز به تأیید دارد.')]);
            }
        }
        $kind=$photo?'file':'message';$status='sending';$note='ارسال در حال انجام است؛ نتیجه هنوز تأیید نشده.';
        if ($token==='' || $channel==='') {$status='failed';$note='توکن API ایتایار یا کانال تنظیم نشده است؛ هیچ پیامی ارسال نشد.';}
        $q=$pdo->prepare('INSERT INTO melkino_eitaa_publish_attempts (request_id,ad_id,channel_id,payload_sha256,status,media_kind,note,admin_id,actor,started_epoch) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$request,$id,$channel,$digest,$status,$kind,$note,$aid?:null,$actorName,time()]);
        $attempt=(int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $mid=null;
    if ($status==='sending') {
        try {
            $params=['chat_id'=>$channel,'title'=>mb_substr('ملکینو — '.(function_exists('melkinoCleanAdTitle')?melkinoCleanAdTitle((string)($ad['title']??''),(string)$id):(string)($ad['title']??$id)),0,150)];
            $method='sendMessage';$params['text']=$text;
            if ($photo) {
                if (!class_exists('CURLFile')) throw new RuntimeException('curl_missing');
                $method='sendFile';unset($params['text']);$params['caption']=$text;
                $params['file']=new CURLFile($photo['path'],mime_content_type($photo['path'])?:'application/octet-stream',$photo['name']);
            }
            $r=melkinoBotApiRequest('eitaa_channel',$method,$params,$token,$transport);
            if ($r['ok']) {
                $value=is_array($r['result'])?($r['result']['message_id']??null):null;
                if ((is_string($value) || is_int($value)) && (string)$value!=='' && strlen((string)$value)<=80) {
                    $mid=mb_substr((string)$value,0,80);$status='published';
                    $note='انتشار در کانال ایتا تأیید شد؛ شناسهٔ پیام: '.$mid;
                } else {$status='unknown';$note='پاسخ موفق سرویس فاقد شناسهٔ پیام بود؛ وضعیت را در کانال بررسی کنید.';}
            } else {
                $status=$r['state'];$note=$r['message'];
                if ($status==='unknown') $note.=' نتیجهٔ تحویل نامشخص است؛ ارسال خودکار تکرار نشد.';
            }
        } catch (Throwable $e) { $status='unknown';$note='ارتباط ارسال کامل نشد؛ ممکن است پیام رسیده باشد. قبل از تکرار، کانال را بررسی کنید.'; }
    }
    $note=melkinoBotRedact($note,[$token]);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE melkino_eitaa_publish_attempts SET status=?,message_id=?,note=?,finished_epoch=? WHERE id=?')
            ->execute([$status,$mid,$note,time(),$attempt]);
        $detail=mb_substr('ایتا | مقصد: '.$channel.' | '.$note,0,500);
        $pdo->prepare('INSERT INTO ads_history (ad_id,action,detail,actor) VALUES (?,?,?,?)')
            ->execute([$id,'publish_eitaa_'.$status,$detail,$actorName]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success'=>false,'status'=>'unknown','attempt_id'=>$attempt,'can_force'=>false,
            'message'=>'ثبت نهایی نتیجه در دیتابیس ناموفق بود؛ درخواست اولیه محفوظ است. قبل از هر تلاش دیگر کانال را بررسی کنید.'];
    }
    $q=$pdo->prepare('SELECT * FROM melkino_eitaa_publish_attempts WHERE id=?');$q->execute([$attempt]);
    return melkinoEitaaPublishResult($q->fetch(PDO::FETCH_ASSOC));
}
