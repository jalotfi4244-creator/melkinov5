<?php
require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/security-lib.php';
global $pdo;
if (!$pdo instanceof PDO) melkinoJsonResponse(['success'=>false,'message'=>'اتصال دیتابیس برقرار نیست.'],500);

// اصلاح امنیتی: این endpoint یک mutation است ولی نه محدودیت روش داشت و
// نه بررسی CSRF. (هویت از قبل فقط از سشن خوانده می‌شود و پارامتر
// telegram_id ورودی نادیده گرفته می‌شود — همان رفتار حفظ شده است.)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    melkinoJsonResponse(['success' => false, 'message' => 'روش مجاز نیست.'], 405);
}
melkinoCsrfCheck();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$name = trim((string)($input['name'] ?? ''));
if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
    melkinoJsonResponse(['success'=>false,'message'=>'نام واردشده معتبر نیست.'],422);
}
$identity = melkinoCurrentIdentity((string)($input['telegram_id'] ?? ''));
if (!$identity['user_id']) {
    $telegram = trim((string)$identity['telegram_id']);
    $phone = trim((string)$identity['phone']);
    if ($telegram === '' && $phone !== '') $telegram = 'web-' . preg_replace('/\D+/', '', $phone);
    if ($telegram === '') melkinoJsonResponse(['success'=>false,'message'=>'کاربر فعلی شناسایی نشد.'],401);
    $stmt=$pdo->prepare('SELECT id FROM users WHERE telegram_id=? LIMIT 1');
    $stmt->execute([$telegram]);
    $existing=(int)($stmt->fetchColumn() ?: 0);
    if ($existing) {
        $userId=$existing;
        $stmt=$pdo->prepare('UPDATE users SET name=?, phone=COALESCE(?,phone), updated_at=NOW() WHERE id=?');
        $stmt->execute([$name,$phone!==''?$phone:null,$userId]);
    } else {
        $stmt=$pdo->prepare('INSERT INTO users (telegram_id,name,phone,first_login,last_login,login_count) VALUES (?,?,?,NOW(),NOW(),1)');
        $stmt->execute([$telegram,$name,$phone!==''?$phone:null]);
        $userId=(int)$pdo->lastInsertId();
    }
    $identity = melkinoCurrentIdentity($telegram);
} else {
    $stmt=$pdo->prepare('UPDATE users SET name=?, updated_at=NOW() WHERE id=?');
    $stmt->execute([$name,(int)$identity['user_id']]);
}
$_SESSION['user_name']=$name;
if (!empty($identity['phone'])) $_SESSION['user_phone']=$identity['phone'];
melkinoJsonResponse(['success'=>true,'name'=>$name]);
