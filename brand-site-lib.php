<?php
declare(strict_types=1);
// No application bootstrap, DB connection or authentication side effects on the public site.
require_once __DIR__ . '/brand-site-defaults.php';
function mkBrandPath(): string { return __DIR__ . '/storage/brand-site/content.json'; }
function mkBrandLinkKeys(): array {
    return [1=>'tg_bot_url',2=>'bale_bot_url',3=>'eitaa_bot_url',4=>'tg_channel_url',5=>'bale_channel_url',6=>'eitaa_channel_url',7=>'instagram_url',8=>'whatsapp_url',9=>'youtube_url',10=>'linkedin_url'];
}
// Retired social slots retain their IDs so old saved content cannot shift provider URLs.
function mkBrandVisibleLinks(array $links): array {
    return array_values(array_filter($links, static function ($r): bool {
        if (!is_array($r) || in_array((int)($r['id']??0),[9,10],true)) return false;
        $name=(string)($r['network']??'').' '.(string)($r['icon']??'');
        $host=strtolower((string)(parse_url((string)($r['url']??''),PHP_URL_HOST)??''));
        return !preg_match('/youtube|linkedin|یوتیوب|لینکدین|لینکداین/iu',$name)
            && !preg_match('/(^|\.)(youtube\.com|youtu\.be|linkedin\.com)$/i',$host);
    }));
}
function mkBrandCoordinate(mixed $value, float $limit): string {
    $v=mkBrandText($value,64);
    $v=strtr($v,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.','−'=>'-']);
    if ($v==='') return '';
    if (!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',$v) || abs((float)$v)>$limit) throw new InvalidArgumentException('مختصات دفتر معتبر نیست؛ عرض جغرافیایی بین منفی ۹۰ و ۹۰ و طول بین منفی ۱۸۰ و ۱۸۰ است.');
    $v=rtrim(rtrim(number_format((float)$v,6,'.',''),'0'),'.');
    return $v==='-0'?'0':$v;
}
function mkBrandOfficePoint(array $settings): ?array {
    try {
        $lat=mkBrandCoordinate($settings['office_lat']??'',90);
        $lng=mkBrandCoordinate($settings['office_lng']??'',180);
        return $lat!=='' && $lng!=='' ? ['lat'=>$lat,'lng'=>$lng] : null;
    } catch (Throwable $e) { return null; }
}
function mkBrandDefaults(): array {
    $features = array_map(static fn($r) => $r + ['is_active'=>1], mkBrandDefaultFeatures());
    return ['version'=>1,'revision'=>'initial','settings'=>mkBrandDefaultSettings() + ['linkedin_url'=>'','office_lat'=>'','office_lng'=>''], 'features'=>$features, 'links'=>mkBrandVisibleLinks(mkBrandDefaultLinks())];
}
function mkBrandRead(): array {
    $path = mkBrandPath();
    if (!is_file($path)) return mkBrandDefaults();
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) throw new RuntimeException('خواندن تنظیمات سایت ممکن نیست.');
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['settings'] ?? null) || !is_array($data['features'] ?? null) || !is_array($data['links'] ?? null)) throw new RuntimeException('فایل تنظیمات سایت معتبر نیست؛ از نسخهٔ پشتیبان بررسی شود.');
    $data['settings'] = array_replace(mkBrandDefaults()['settings'], $data['settings']);
    // ارتقای مقادیر قدیمیِ پیش‌فرض (لاتین Malakinoo) به نام جدید؛ فقط مقدار دقیقاً قدیمی تغییر می‌کند.
    $legacyLatin = ['About Malakinoo'=>'about_eyebrow','Follow Malakinoo'=>'social_eyebrow','Malakinoo Bot'=>'bot_eyebrow','Malakinoo'=>'brand_latin'];
    foreach ($legacyLatin as $oldText => $key) {
        if (isset($data['settings'][$key]) && $data['settings'][$key] === $oldText) {
            $data['settings'][$key] = mkBrandDefaults()['settings'][$key];
        }
    }
    $data['links'] = mkBrandVisibleLinks($data['links']);
    $data['revision'] = hash('sha256', $raw);
    return $data;
}
function mkBrandText(mixed $value, int $limit = 12000): string {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || strlen($value) > $limit || str_contains($value, "\0")) throw new InvalidArgumentException('متن نامعتبر یا بیش از اندازه طولانی است.');
    return trim($value);
}
function mkBrandUrl(string $value, bool $image = false): string {
    if ($value === '') return '';
    if (strlen($value)>2048 || preg_match('/[\x00-\x20\x7f\\\\\'"<>`()]/', $value) || preg_match('/%(?:0[ad]|5c)/i', $value)) throw new InvalidArgumentException('آدرس لینک یا تصویر معتبر نیست.');
    if ($value[0] === '/' && !str_starts_with($value,'//') && !str_contains(rawurldecode($value),'..') && !preg_match('/%2f/i',$value)) return $value;
    if (!$image && $value[0] === '#') return $value;
    $p = parse_url($value);
    $allowed = $image ? ['https','http'] : ['https','http','tel','mailto'];
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''),$allowed,true) || isset($p['user']) || isset($p['pass'])) throw new InvalidArgumentException('لینک باید با https:// یا / شروع شود.');
    if (in_array(strtolower($p['scheme']),['https','http'],true) && !filter_var($value,FILTER_VALIDATE_URL)) throw new InvalidArgumentException('نشانی وب معتبر نیست.');
    return $value;
}
function mkBrandValidate(array $input, array $existingSettings=[]): array {
    $base = mkBrandDefaults();
    $posted = $input['settings'] ?? null;
    if (!is_array($posted)) throw new InvalidArgumentException('فرم ناقص است؛ صفحه را دوباره باز کنید.');
    $images = ['logo_path','favicon_path','hero_image','about_image','about_image_2'];
    // Only editable known fields. No arbitrary PHP, CSS, auth or app configuration keys.
    foreach ($base['settings'] as $key => $default) {
        $v = mkBrandText($posted[$key] ?? $existingSettings[$key] ?? $default);
        if (in_array($key,$images,true) || str_ends_with($key,'_url') || $key==='map_embed') $v = mkBrandUrl($v,in_array($key,$images,true) || $key==='map_embed');
        $base['settings'][$key] = $v;
    }
    foreach (['office_lat'=>90,'office_lng'=>180] as $key=>$limit) $base['settings'][$key]=mkBrandCoordinate($base['settings'][$key],$limit);
    if (($base['settings']['office_lat']==='') !== ($base['settings']['office_lng']==='')) throw new InvalidArgumentException('هر دو مختصات دفتر را وارد کنید یا هر دو را خالی بگذارید.');
    if ($base['settings']['brand_name']==='') throw new InvalidArgumentException('نام برند را وارد کنید.');
    if ($base['settings']['email']!=='' && !filter_var($base['settings']['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('ایمیل معتبر نیست.');
    $base['settings']['hero_btn1_url']='/program/home.php';
    $base['settings']['hero_btn1_text']=$base['settings']['app_button_text'];
    foreach (['features','links'] as $kind) {
        if (!is_array($input[$kind] ?? null) || count($input[$kind]) > 24) throw new InvalidArgumentException('فهرست کارت‌ها معتبر نیست.');
        $rows=[];$seenIds=[];
        foreach ($input[$kind] as $i=>$r) {
            if (!is_array($r)) throw new InvalidArgumentException('کارت نامعتبر است.');
            if ($kind==='features') {
                $rows[]=['title'=>mkBrandText($r['title']??'',300),'description'=>mkBrandText($r['description']??'',3000),'icon'=>mkBrandText($r['icon']??'fa-solid fa-gem',100),'is_active'=>isset($r['is_active'])?1:0];
            } else {
                $id=filter_var($r['id']??((int)$i+1),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>24]]);
                if ($id===false || isset($seenIds[$id])) throw new InvalidArgumentException('شناسهٔ کارت نامعتبر یا تکراری است.');
                $seenIds[$id]=true;
                $rows[]=['id'=>$id,'network'=>mkBrandText($r['network']??'',300),'description'=>mkBrandText($r['description']??'',3000),'button_text'=>mkBrandText($r['button_text']??'',300),'note'=>mkBrandText($r['note']??'',100), 'url'=>mkBrandUrl(mkBrandText($r['url']??'',2048)), 'icon'=>mkBrandText($r['icon']??'',100),'accent'=>preg_match('/^#[a-f0-9]{6}$/i',(string)($r['accent']??''))?$r['accent']:'#C9A765', 'kind'=>in_array($r['kind']??'',['bot','channel','page','support'],true)?$r['kind']:'page','size'=>($r['size']??'')==='tall'?'tall':'normal', 'is_header'=>isset($r['is_header'])?1:0,'in_quick'=>!empty($r['in_quick'])?1:0,'is_active'=>isset($r['is_active'])?1:0,'sort_order'=>$id];
            }
        }
        $base[$kind]=$rows;
    }
    $base['links']=mkBrandVisibleLinks($base['links']);
    $urls=array_column($base['links'],'url','id');
    foreach (mkBrandLinkKeys() as $id=>$key) $base['settings'][$key]=(string)($urls[$id]??'');
    return $base;
}
function mkBrandUpload(array $file, array &$created): string {
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new InvalidArgumentException('آپلود تصویر کامل نشد.');
    if ((int)($file['size']??0)>3*1024*1024) throw new InvalidArgumentException('حداکثر حجم هر تصویر ۳ مگابایت است.');
    $tmp=$file['tmp_name'];$info=@getimagesize($tmp);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime]??null;
    if (!$ext || !$info || $info[0]>8192 || $info[1]>8192 || $info[0]*$info[1]>32000000) throw new InvalidArgumentException('فقط تصویر معتبر JPG، PNG، WEBP یا GIF با ابعاد مناسب مجاز است؛ SVG مجاز نیست.');
    $dir=dirname(__DIR__).'/brand-media';
    if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('پوشهٔ تصاویر سایت قابل ساخت نیست.');
    $name='brand-'.bin2hex(random_bytes(16)).'.'.$ext;
    if (!move_uploaded_file($tmp,$dir.'/'.$name)) throw new RuntimeException('ذخیرهٔ تصویر انجام نشد.');
    chmod($dir.'/'.$name,0644);$created[]=$dir.'/'.$name;
    return '/brand-media/'.$name;
}
function mkBrandSave(array $input, string $revision, array $files=[]): void {
    $dir=dirname(mkBrandPath());
    if (!is_dir($dir) && !mkdir($dir,0750,true)) throw new RuntimeException('پوشهٔ تنظیمات قابل ساخت نیست.');
    $lock=fopen($dir.'/write.lock','c');
    if (!$lock) throw new RuntimeException('قفل ذخیره‌سازی باز نشد.');
    $temp=null;$created=[];
    try {
        if (!flock($lock,LOCK_EX)) throw new RuntimeException('ذخیره‌سازی در دسترس نیست.');
        $current=mkBrandRead();
        if ($revision==='' || !hash_equals($current['revision'],$revision)) throw new RuntimeException('تنظیمات در پنجرهٔ دیگری تغییر کرده؛ صفحه را تازه کنید و دوباره ذخیره کنید.');
        $data=mkBrandValidate($input,$current['settings']);
        foreach (['logo_path','favicon_path','hero_image','about_image','about_image_2'] as $key) {
            if (isset($files[$key]) && ($files[$key]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) $data['settings'][$key]=mkBrandUpload($files[$key],$created);
        }
        unset($data['revision']);$data['updated_at']=gmdate('c');
        $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        if (strlen($json)>1048576) throw new InvalidArgumentException('حجم تنظیمات بیش از حد مجاز است.');
        $temp=tempnam($dir,'.brand-');
        if ($temp===false || file_put_contents($temp,$json)!==strlen($json)) throw new RuntimeException('نوشتن تنظیمات کامل نشد.');
        chmod($temp,0640);
        if (!rename($temp,mkBrandPath())) throw new RuntimeException('جایگزینی امن تنظیمات انجام نشد.');
        $temp=null;$created=[];
    } finally {
        if (is_string($temp) && is_file($temp)) unlink($temp);
        foreach ($created as $path) @unlink($path);
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function mkBrandAdminSave(): never {
    if (($_SESSION['is_admin']??null)!==true) { http_response_code(403); exit; }
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>0) { http_response_code(413); exit('حجم فرم از محدودیت PHP هاست بیشتر است؛ post_max_size را افزایش دهید یا تعداد تصاویر را کم کنید.'); }
    require_once __DIR__ . '/app/Bootstrap/app.php';
    // Bootstrap can revoke an expired/maintenance-era session; recheck after it runs.
    if (($_SESSION['is_admin']??null)!==true) { http_response_code(403); exit; }
    if (!(\Melkino\Core\Database::pdo() instanceof PDO)) { http_response_code(503); exit('اتصال برنامه به دیتابیس برقرار نیست.'); }
    if (\Melkino\Core\AdminTotp::needsCheck()) { header('Location: admin-2fa.php?redirect=admin.php%3Ftab%3Dbrand',true,303); exit; }
    // Strict check: do not inherit the legacy same-origin/no-header CSRF fallback.
    $sent=$_POST['csrf_token']??'';
    if (!is_string($sent) || $sent==='' || !hash_equals(melkinoCsrfToken(),$sent)) { http_response_code(403); exit('اعتبار فرم منقضی شده است.'); }
    try {
        if (($_POST['brand_complete']??'')!=='1') throw new RuntimeException('فرم ناقص دریافت شد؛ محدودیت حجم/تعداد فیلدهای PHP را بررسی کنید.');
        mkBrandSave($_POST,(string)($_POST['revision']??''),$_FILES);
        $_SESSION['brand_notice']=['ok'=>true,'text'=>'تنظیمات سایت اصلی ذخیره شد.'];
    } catch (InvalidArgumentException|RuntimeException $e) {
        $_SESSION['brand_notice']=['ok'=>false,'text'=>$e->getMessage()];
    } catch (Throwable $e) {
        error_log('[brand] Save failed: '.get_class($e));
        $_SESSION['brand_notice']=['ok'=>false,'text'=>'ذخیره نشد. دسترسی نوشتن پوشه‌ها و گزارش خطای سرور را بررسی کنید.'];
    }
    $return = ($_POST['brand_return'] ?? '') === 'legacy' ? 'admin-panel.php?tab=brand' : 'admin.php?tab=brand';
    header('Location: ' . $return,true,303);exit;
}
