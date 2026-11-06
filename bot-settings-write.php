<?php
/** Scoped bot settings: opaque Eitaa credentials, atomic PATCH, revisions and redacted logs. */
declare(strict_types=1);
require_once __DIR__ . '/db-settings.php';

if (!class_exists('MelkinoBotSettingsError', false)) {
    class MelkinoBotSettingsError extends InvalidArgumentException {
        public array $fields;
        public function __construct(string $message, array $fields = [], int $code = 422) {
            parent::__construct($message, $code); $this->fields = $fields;
        }
    }
}
function melkinoBotScopes(): array {
    return [
        'methods' => ['login_telegram_enabled','login_bale_enabled','login_eitaa_enabled','login_sms_enabled'],
        'telegram' => ['telegram_token','telegram_channel','telegram_bot_username','telegram_miniapp_url','login_telegram_enabled'],
        'bale' => ['bale_token','bale_channel','bale_bot_username','bale_miniapp_url','login_bale_enabled'],
        'eitaa' => ['eitaa_token','eitaa_bot_username','eitaa_miniapp_url','login_eitaa_enabled'],
        'eitaa_channel' => ['eitaa_channel_token','eitaa_channel'],
        'proxy' => ['http_proxy'],
        'sms' => ['sms_enabled','sms_api_key','sms_api_url','sms_sender_line','sms_provider','sms_password',
            'sms_otp_line','sms_promo_line','sms_otp_body_id','sms_otp_template','login_sms_enabled'],
    ];
}
function melkinoBotScopeLabel(string $scope): string {
    return ['methods'=>'روش‌های ورود','telegram'=>'تلگرام','bale'=>'بله','eitaa'=>'ورود ایتا',
        'eitaa_channel'=>'کانال ایتا','proxy'=>'پروکسی','sms'=>'پیامک','all'=>'ربات‌ها'][$scope] ?? 'تنظیمات';
}
function melkinoBotSecretKeys(): array {
    return ['telegram_token','bale_token','eitaa_token','eitaa_channel_token','sms_api_key','sms_password'];
}
function melkinoBotTrim(string $value): string {
    $value = trim($value);
    return preg_replace('/^[\p{Z}\x{FEFF}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+|[\p{Z}\x{FEFF}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+$/u', '', $value) ?? $value;
}
function melkinoBotNormalizeChannel(string $channel): string {
    $channel = melkinoBotTrim($channel);
    if (preg_match('#^https://(?:www\.)?eitaa\.com/([a-zA-Z0-9_]+)/?$#i', $channel, $m)) $channel = $m[1];
    return ltrim($channel, '@');
}
function melkinoBotValidateValue(string $key, $value): string {
    $bad = static function(string $msg) use ($key): never { throw new MelkinoBotSettingsError($msg, [$key => $msg]); };
    if (str_starts_with($key, 'login_') || $key === 'sms_enabled') {
        if (in_array($value, [true,1,'1','true'], true)) return '1';
        if (in_array($value, [false,0,'0','false'], true)) return '0';
        $bad('مقدار کلید فعال/غیرفعال معتبر نیست.');
    }
    if (!is_string($value) && !is_int($value)) $bad('مقدار این فیلد باید متن باشد.');
    $value = $key==='sms_password' ? (string)$value : melkinoBotTrim((string)$value);
    $secret = in_array($key, melkinoBotSecretKeys(), true);
    if (strlen($value) > ($secret ? 4096 : 2048)) $bad('مقدار واردشده بیش از حد طولانی است.');
    if ($secret) {
        if ($value === '' || $value === '-') return $value;
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) $bad('توکن/کلید نباید خط جدید یا نویسهٔ کنترلی داخلی داشته باشد.');
        if (str_ends_with($key,'_token') && preg_match('#^https?://#i', $value)) $bad('خود توکن را وارد کنید، نه آدرس کامل API.');
        if (in_array($key, ['telegram_token','bale_token'], true) && !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $value)) {
            $bad('قالب توکن این ربات باید شناسه:کلید باشد. این خطا مربوط به همان بخش است، نه ایتا.');
        }
        // Eitaa credentials are OPAQUE. No numeric-prefix/JWT/UUID length guess.
        return $value;
    }
    if (str_ends_with($key, '_bot_username')) {
        $value = ltrim($value, '@');
        if ($value !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value)) $bad('نام کاربری را بدون آدرس سایت، فاصله یا / وارد کنید؛ پیوند برنامک فیلد جدا دارد.');
    }
    if ($key === 'eitaa_channel') {
        $value = melkinoBotNormalizeChannel($value);
        if ($value !== '' && !preg_match('/^(?:-?[1-9][0-9]{0,19}|[A-Za-z][A-Za-z0-9_]{0,63})$/D', $value)) $bad('شناسهٔ عددی ایتایار یا نام کانال ایتا را وارد کنید.');
    } elseif (in_array($key, ['telegram_channel','bale_channel'], true)) {
        if ($value !== '' && !preg_match('/^(?:-?[1-9][0-9]{0,19}|@?[A-Za-z][A-Za-z0-9_]{0,63})$/D', $value)) $bad('شناسهٔ کانال باید عدد یا @نام‌کاربری باشد.');
    }
    if (in_array($key,['telegram_miniapp_url','bale_miniapp_url'],true) && $value !== '') {
        require_once __DIR__.'/messenger-login-lib.php';
        $provider=str_starts_with($key,'telegram')?'telegram':'bale';
        if (!melkinoMessengerLaunchUrlValid($provider,$value)) $bad('پیوند اجرای برنامک باید HTTPS دامنهٔ همان پیام‌رسان باشد؛ اطلاعات ورود یا hash را در آن ذخیره نکنید.');
    }
    if ($key === 'eitaa_miniapp_url' && $value !== '' && !melkinoEitaaValidLaunchUrl($value)) $bad('پیوند برنامک باید HTTPS ایتا باشد؛ مانند https://eitaa.com/app/miniapp');
    if ($key === 'http_proxy' && $value !== '' && !preg_match('#^(https?|socks5h?|socks4)://[^\s]+$#i', $value)) $bad('نشانی پروکسی معتبر نیست.');
    if ($key === 'sms_api_url' && $value !== '' && !preg_match('#^https?://[^\s]+$#i', $value)) $bad('نشانی API پیامک معتبر نیست.');
    if (preg_match('/[\x00\r]/', $value)) $bad('نویسهٔ کنترلی در فیلد مجاز نیست.');
    return $value;
}
function melkinoBotLogEnsure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM melkino_bot_action_logs LIMIT 1'); return true; }
    catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS melkino_bot_action_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(32) NOT NULL, action VARCHAR(32) NOT NULL,
                outcome VARCHAR(24) NOT NULL, source VARCHAR(24) NOT NULL DEFAULT 'server',
                message VARCHAR(1200) NOT NULL, admin_id INT NULL, actor VARCHAR(120) NULL,
                created_epoch BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_bot_scope (scope,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return true;
        } catch (Throwable $ignored) { return false; }
    }
}
function melkinoBotRedact(string $text, array $secrets = []): string {
    foreach ($secrets as $s) {
        if (is_string($s) && $s !== '') $text = str_replace([$s,rawurlencode($s),urlencode($s)], '[پنهان]', $text);
    }
    $text = preg_replace('#(https://eitaayar\.ir/api/)[^/\s]+/#i', '$1[پنهان]/', $text) ?? '';
    $text = preg_replace('#(https://(?:api\.telegram\.org|tapi\.bale\.ai)/bot)[^/\s]+/#i', '$1[پنهان]/', $text) ?? '';
    return mb_substr(strip_tags($text), 0, 1100);
}
function melkinoBotLog(PDO $pdo, string $scope, string $action, string $outcome, string $message, string $source = 'server', array $secrets = []): ?array {
    $at = time();
    $row = ['scope'=>$scope,'action'=>$action,'outcome'=>$outcome,'source'=>$source,
        'message'=>melkinoBotRedact($message,$secrets),'created_epoch'=>$at,
        'actor'=>mb_substr((string)($_SESSION['admin_username'] ?? 'مدیر'),0,120)];
    try {
        if (!melkinoBotLogEnsure($pdo)) return null;
        $pdo->prepare('INSERT INTO melkino_bot_action_logs (scope,action,outcome,source,message,admin_id,actor,created_epoch) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$scope,$action,$outcome,$source,$row['message'],(int)($_SESSION['admin_id']??0)?:null,$row['actor'],$at]);
        $row['id'] = (int)$pdo->lastInsertId();
        return $row;
    } catch (Throwable $e) { return null; }
}
function melkinoBotLogs(PDO $pdo, string $scope, int $limit = 5): array {
    try {
        $q = $pdo->prepare('SELECT id,scope,action,outcome,source,message,actor,created_epoch FROM melkino_bot_action_logs WHERE scope=? ORDER BY id DESC LIMIT '.max(1,min(30,$limit)));
        $q->execute([$scope]); return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
function melkinoBotRevisions(PDO $pdo): array {
    $out=[];
    $q=$pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='bots' AND setting_key LIKE '__rev_%'");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[substr($r['setting_key'],6)] = (int)$r['setting_value'];
    foreach (array_keys(melkinoBotScopes()) as $scope) $out[$scope] = $out[$scope] ?? 0;
    $out['all'] = $out['all'] ?? 0;
    return $out;
}
function melkinoBotPublicSettings(PDO $pdo): array {
    $s = melkinoBotSettings();
    foreach (melkinoBotSecretKeys() as $k) {
        $v = (string)($s[$k] ?? '');
        $s[$k.'_configured'] = $v !== '';
        $s[$k.'_masked'] = $v === '' ? '' : ('••••••'.(strlen($v)>=8 ? substr($v,-4) : ''));
        unset($s[$k]);
    }
    return $s;
}
function melkinoSaveBotSection(PDO $pdo, array $input, string $scope = 'all'): array {
    $scopes = melkinoBotScopes();
    if ($scope !== 'all' && !isset($scopes[$scope])) throw new MelkinoBotSettingsError('بخش تنظیمات شناخته نشد.');
    $allowed = $scope === 'all' ? array_values(array_unique(array_merge(...array_values($scopes)))) : $scopes[$scope];
    $posted = array_intersect_key($input, array_flip($allowed));
    if (!$posted) throw new MelkinoBotSettingsError('هیچ فیلدی برای ذخیره فرستاده نشده است.');
    // Warm the existing helper outside the transaction (no implicit-commit DDL inside it).
    melkinoEnsureSettingsTable($pdo);
    melkinoBotLogEnsure($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO settings (setting_group,setting_key,setting_value,value_type) VALUES ('bots','__write_lock','1','string') ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
        $pdo->query("SELECT setting_value FROM settings WHERE setting_group='bots' AND setting_key='__write_lock' FOR UPDATE")->fetchColumn();
        $q=$pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_group='bots'");
        $current = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        $rev = (int)($current['__rev_'.$scope] ?? 0);
        if (isset($input['expected_revision']) && (!is_scalar($input['expected_revision']) || (int)$input['expected_revision'] !== $rev)) {
            throw new MelkinoBotSettingsError('این بخش در نشست دیگری تغییر کرده است؛ بازخوانی کنید و دوباره ذخیره کنید.', [], 409);
        }
        $changes=[]; $submitted=[]; $secretInputs=[];
        foreach ($posted as $key=>$raw) {
            $secret=in_array($key,melkinoBotSecretKeys(),true);
            if ($secret && is_string($raw)) {
                $raw=$key==='sms_password'?$raw:melkinoBotTrim($raw);
                if ($raw==='') continue; // Never clear a masked/omitted secret.
                $secretInputs[]=$raw;
            }
            // Unchanged legacy values in other parts must not veto this save.
            if (is_scalar($raw) && (string)$raw === (string)($current[$key] ?? '') && !is_bool($raw)) {
                $submitted[]=$key; continue;
            }
            $value = melkinoBotValidateValue($key,$raw);
            if ($secret && $value==='-') $value='';
            $submitted[]=$key;
            if (!array_key_exists($key,$current) || (string)$current[$key]!==$value) $changes[$key]=$value;
        }
        $flagChanges=array_intersect_key($changes,array_flip($scopes['methods']));
        $methodsRev=(int)($current['__rev_methods']??0);
        if (array_intersect_key($posted,array_flip($scopes['methods'])) && $scope!=='methods' && isset($input['expected_methods_revision']) && (!is_scalar($input['expected_methods_revision']) || (int)$input['expected_methods_revision']!==$methodsRev)) {
            throw new MelkinoBotSettingsError('روش‌های ورود تغییر کرده‌اند؛ ابتدا همان بخش را بازخوانی کنید.', [], 409);
        }
        $merged=array_merge(['login_telegram_enabled'=>'1','login_bale_enabled'=>'1','login_eitaa_enabled'=>'1','login_sms_enabled'=>'0'],$current,$changes);
        if (!array_filter(array_intersect_key($merged,array_flip($scopes['methods'])),static fn($v)=>(string)$v==='1')) {
            throw new MelkinoBotSettingsError('حداقل یکی از روش‌های ورود باید فعال بماند.', ['login_eitaa_enabled'=>'حداقل یک روش ورود را روشن نگه دارید.']);
        }
        $aid=(int)($_SESSION['admin_id']??0)?:null;
        foreach ($changes as $key=>$value) {
            if (!dbSettingSet($pdo,'bots',$key,$value,'string',$aid)) throw new RuntimeException('settings_write_failed');
        }
        $versions=[];
        foreach ($scopes as $s=>$keys) {
            // Method flags have their own revision, not the token/channel revision.
            $keys=$s==='methods'?$keys:array_values(array_diff($keys,$scopes['methods']));
            if (array_intersect(array_keys($changes),$keys)) $versions[$s]=(int)($current['__rev_'.$s]??0)+1;
        }
        if ($changes) $versions['all']=(int)($current['__rev_all']??0)+1;
        foreach ($versions as $s=>$v) if (!dbSettingSet($pdo,'bots','__rev_'.$s,(string)$v,'string',$aid)) throw new RuntimeException('revision_write_failed');
        if ($changes && !dbSettingSet($pdo,'bots','updated_at',date('Y-m-d H:i:s'),'string',$aid)) throw new RuntimeException('timestamp_write_failed');
        $pdo->commit();
        $cache=&melkinoSettingsRequestCache(); $cache=[];
        $msg=$changes ? 'تنظیمات «'.melkinoBotScopeLabel($scope).'» ذخیره شد.' : 'تغییری لازم نبود؛ تنظیمات همین بخش ذخیره است.';
        $log=melkinoBotLog($pdo,$scope,'save','success',$msg);
        return ['success'=>true,'scope'=>$scope,'message'=>$msg,'applied_keys'=>$submitted,'changed_keys'=>array_keys($changes),
            'settings'=>melkinoBotPublicSettings($pdo),'revisions'=>melkinoBotRevisions($pdo),'log'=>$log,
            'log_warning'=>$log?null:'تنظیمات ذخیره شد، اما ثبت سابقه در دسترس نیست؛ SQL نسخهٔ جدید را وارد کنید.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $cache=&melkinoSettingsRequestCache(); $cache=[];
        throw $e;
    }
}
