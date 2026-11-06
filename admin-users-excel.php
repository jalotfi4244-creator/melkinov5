<?php
/** Admin-only complete user export; explicit profile-column allowlist, never SELECT *. */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__.'/admin-guard.php';
require_once __DIR__.'/melkino-xlsx.php';
melkinoRequireAdminJson();
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'GET') !== 'GET') melkinoAdminJson(['success'=>false,'message'=>'روش درخواست معتبر نیست.'],405);
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$columns=[
 'id'=>'شناسه کاربر','name'=>'نام نمایشی','first_name'=>'نام','last_name'=>'نام خانوادگی','username'=>'نام کاربری',
 'phone'=>'شماره همراه','phone_verified'=>'شماره تأییدشده','phone_locked'=>'شماره قفل‌شده','name_locked'=>'نام قفل‌شده','is_active'=>'فعال',
 'telegram_id'=>'شناسه تلگرام','telegram_username'=>'نام کاربری تلگرام','telegram_first_name'=>'نام تلگرام','telegram_last_name'=>'نام خانوادگی تلگرام',
 'bale_id'=>'شناسه بله','bale_username'=>'نام کاربری بله','eitaa_id'=>'شناسه ایتا','eitaa_username'=>'نام کاربری ایتا',
 'created_at'=>'تاریخ ثبت','updated_at'=>'آخرین ویرایش','first_login'=>'اولین ورود','last_login'=>'آخرین ورود','login_count'=>'تعداد ورود',
 'last_platform'=>'آخرین مسیر ورود','last_ip'=>'آخرین IP','user_agent'=>'مرورگر و دستگاه','language_code'=>'زبان',
 'photo_url'=>'نشانی تصویر حساب','telegram_language_code'=>'زبان تلگرام','telegram_is_premium'=>'تلگرام پریمیوم','telegram_photo_url'=>'نشانی تصویر تلگرام','telegram_auth_date'=>'تاریخ احراز تلگرام'
];
$tmp=null;
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('database');
    $available=$pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    $columns=array_intersect_key($columns,array_flip($available));
    if (!isset($columns['id'])) throw new RuntimeException('schema');
    $sql='SELECT '.implode(',',array_map(static fn($k)=>'`'.$k.'`',array_keys($columns))).' FROM users ORDER BY id ASC';
    $query=$pdo->query($sql);$rows=[];
    while ($row=$query->fetch(PDO::FETCH_NUM)) $rows[]=array_map(static fn($v)=>$v===null?'':(string)$v,$row);
    // Writer emits inlineStr cells: phone/IDs retain leading zeroes and =/+/@ are not formulas.
    $tmp=tempnam(sys_get_temp_dir(),'mk-users-');
    if ($tmp) register_shutdown_function(static function() use ($tmp) { if (is_file($tmp)) @unlink($tmp); });
    if (!$tmp || !melkinoXlsxWrite($tmp,array_values($columns),$rows,'کاربران')) throw new RuntimeException('xlsx');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="melkino-users-'.date('Y-m-d-His').'.xlsx"');
    header('Content-Length: '.filesize($tmp));
    readfile($tmp);unlink($tmp);
} catch (Throwable $e) {
    if ($tmp && is_file($tmp)) unlink($tmp);
    melkinoAdminJson(['success'=>false,'message'=>'خروجی کاربران ساخته نشد؛ اتصال پایگاه‌داده و فضای موقت هاست را بررسی کنید.'],500);
}
