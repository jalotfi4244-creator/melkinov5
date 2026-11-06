<?php
/** Shared publication templates. Old per-provider rows remain read-only and intact. */
function melkinoPublishSharedDocument(): array {
    global $pdo;
    $doc = $pdo instanceof PDO ? dbSettingGet($pdo, 'publish', 'shared_content_v1', null) : null;
    if (is_array($doc) && ($doc['v'] ?? 0) === 1 && isset($doc['general'], $doc['overrides'])) return $doc;
    $origin = 'telegram';
    if ($pdo instanceof PDO) {
        foreach (['telegram','bale','eitaa'] as $pf) {
            foreach (['fields_','header_','footer_','fields_matrix_'] as $prefix) {
                if (dbSettingGet($pdo, 'publish', $prefix.$pf, null) !== null) { $origin=$pf; break 2; }
            }
        }
    }
    $general = melkinoLegacyPublishSettings($origin);
    unset($general['is_override']);
    // Never broaden disclosure to another messenger during automatic migration.
    $general['fields'] = array_values(array_diff($general['fields'], ['phone','contact']));
    $overrides=[];
    foreach (melkinoLegacyPublishMatrix($origin) as $key=>$fields) {
        if (!is_array($fields)) continue;
        $overrides[$key]=['fields'=>array_values(array_intersect(array_diff(array_keys(melkinoPublishFieldDefs()),['phone','contact']), $fields))];
    }
    return ['v'=>1,'general'=>$general,'overrides'=>$overrides,'migrated_from'=>$origin];
}
function melkinoPublishRevision(array $doc): string {
    return hash('sha256', json_encode($doc, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
function melkinoPublishMatrix(string $platform): array { return melkinoPublishSharedDocument()['overrides']; }
function melkinoPublishSettings(string $platform, string $propertyType='', string $transactionType=''): array {
    $doc=melkinoPublishSharedDocument(); $out=$doc['general']; $keyFound='';
    $pt=trim($propertyType);$tr=trim($transactionType);
    if ($pt!=='' || $tr!=='') {
        foreach (array_unique([$pt.'|'.$tr,$pt.'|','|'.$tr]) as $key) {
            if (isset($doc['overrides'][$key]) && is_array($doc['overrides'][$key])) {
                $out=array_replace($out,$doc['overrides'][$key]); $keyFound=$key;break;
            }
        }
    }
    return $out+['is_override'=>$keyFound!=='','matched_key'=>$keyFound,'revision'=>melkinoPublishRevision($doc),'migrated_from'=>$doc['migrated_from']??'telegram'];
}
function melkinoSavePublishSettings(string $platform, array $data): bool {
    global $pdo;
    if (!($pdo instanceof PDO)) return false;
    foreach (['property_type','transaction_type','header','footer','revision'] as $k) {
        if (isset($data[$k]) && !is_string($data[$k])) throw new InvalidArgumentException('قالب ورودی معتبر نیست.');
    }
    $pt=trim($data['property_type']??'');$tr=trim($data['transaction_type']??'');
    if (str_contains($pt.$tr,'|') || mb_strlen($pt)>100 || mb_strlen($tr)>100) throw new InvalidArgumentException('نوع ملک یا معامله معتبر نیست.');
    $key=$pt.'|'.$tr;$combo=$key!=='|';$reset=!empty($data['reset_combo']);
    if (!$reset) {
        if (!isset($data['fields']) || !is_array($data['fields'])) throw new InvalidArgumentException('فیلدهای انتشار مشخص نشده‌اند.');
        foreach ($data['fields'] as $v) if (!is_string($v)) throw new InvalidArgumentException('فیلد انتشار معتبر نیست.');
        $fieldsClean=array_values(array_intersect(array_keys(melkinoPublishFieldDefs()),$data['fields']));
        // قیمت هر متر فقط داخل بلوک قیمت نمایش داده می‌شود؛ بدون «قیمت» ذخیره نمی‌شود تا تنظیم ذخیره‌شده با نمایش واقعی یکی باشد
        if (!in_array('price',$fieldsClean,true)) { $fieldsClean=array_values(array_diff($fieldsClean,['price_per_meter'])); }
        $record=['fields'=>$fieldsClean,
            'header'=>mb_substr(trim($data['header']??''),0,2000),'footer'=>mb_substr(trim($data['footer']??''),0,2000)];
    }
    if ($reset && !$combo) throw new InvalidArgumentException('تنظیم عمومی قابل حذف نیست.');
    // Initialize schema before BEGIN (DDL must not implicitly commit our transaction).
    melkinoEnsureSettingsTable($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO settings(setting_group,setting_key,setting_value,value_type) VALUES('publish','shared_content_v1','null','json') ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)");
        $pdo->query("SELECT setting_value FROM settings WHERE setting_group='publish' AND setting_key='shared_content_v1' FOR UPDATE")->fetchColumn();
        $cache=&melkinoSettingsRequestCache();unset($cache[spl_object_id($pdo)."\0publish\0shared_content_v1"]);
        $doc=melkinoPublishSharedDocument();
        if (isset($data['revision']) && !hash_equals(melkinoPublishRevision($doc),$data['revision'])) throw new RuntimeException('تنظیمات در پنجرهٔ دیگری تغییر کرده؛ بازخوانی کنید و دوباره ذخیره کنید.',409);
        if ($reset) unset($doc['overrides'][$key]);
        elseif ($combo) $doc['overrides'][$key]=$record;
        else $doc['general']=$record;
        if (!dbSettingSet($pdo,'publish','shared_content_v1',$doc,'json',!empty($_SESSION['admin_id'])?(int)$_SESSION['admin_id']:null)) throw new RuntimeException('ذخیره انجام نشد.');
        $pdo->commit();return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $cache=&melkinoSettingsRequestCache();unset($cache[spl_object_id($pdo)."\0publish\0shared_content_v1"]);
        throw $e;
    }
}
