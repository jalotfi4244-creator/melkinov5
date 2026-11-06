<?php
/** Bale only accepts a URL credential in its documented setWebhook contract. Never render/log this URL. */
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/bot-settings.php';
require_once __DIR__.'/bale-contact-lib.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$reply=static function(int $code): never{http_response_code($code);echo json_encode(['ok'=>$code===200]);exit;};
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$reply(405);
try{
    $cfg=mkBaleConfig($pdo);if(($cfg['bale_mode']??'webhook')!=='webhook')$reply(409);$key=$_GET['k']??null;$secret=$cfg['bale_secret']??'';
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$secret)||!hash_equals($secret,$key)||!hash_equals($cfg['bale_token_hash']??'',hash('sha256',melkinoBaleToken())))$reply(403);
    if(!melkinoLoginMethodEnabled('bale'))$reply(403);
    $raw=(string)file_get_contents('php://input',false,null,0,262145);if(strlen($raw)>262144)$reply(413);
    $data=json_decode($raw,true,32,JSON_BIGINT_AS_STRING);if(!is_array($data))$reply(400);
    mkBaleReceive($pdo,$data,$cfg['bale_token_hash']);$reply(200);
}catch(Throwable $e){error_log('[bale-contact] receipt unavailable');$reply(503);}
