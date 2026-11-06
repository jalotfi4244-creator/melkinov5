<?php
/** Admin-only Eitaa channel preview/send. No client token, alternate API URL or demo mode. */
declare(strict_types=1);
require_once __DIR__ . '/admin-guard.php';
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/bot-settings.php';
require_once __DIR__ . '/eitaa-publish-lib.php';
melkinoRequireAdminJson();
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    header('Allow: POST');melkinoAdminJson(['success'=>false,'message'=>'این مسیر فقط POST می‌پذیرد.'],405);
}
melkinoCsrfCheck();
$data=melkinoAdminJsonBody();
$action=is_string($data['action']??null)?$data['action']:'publish';
$actor=['id'=>(int)($_SESSION['admin_id']??0),'name'=>(string)($_SESSION['admin_username']??'مدیر')];
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('database');
    if ($action==='prepare') melkinoAdminJson(melkinoPrepareEitaaPublish($pdo,$data));
    if ($action!=='publish') throw new MelkinoBotSettingsError('عملیات شناخته نشد.');
    $r=melkinoPublishEitaa($pdo,$data,$actor);
    $status=$r['success']?200:(!empty($r['blocked']) || ($r['status']??'')==='sending'?409:502);
    melkinoAdminJson($r,$status);
} catch (MelkinoBotSettingsError $e) {
    melkinoAdminJson(['success'=>false,'message'=>$e->getMessage(),'field_errors'=>$e->fields],in_array($e->getCode(),[404,409,422],true)?$e->getCode():422);
} catch (Throwable $e) {
    error_log('[melkino][eitaa-publish] storage/setup error; code='.$e->getCode());
    melkinoAdminJson(['success'=>false,'message'=>'آماده‌سازی انتشار انجام نشد؛ دیتابیس، افزونهٔ cURL و SQL نسخهٔ جدید را بررسی کنید.'],503);
}
