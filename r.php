<?php
/**
 * ردیابی کلیک لینک پیامک — عمومی است تا گیرنده بتواند باز کند.
 */
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/db_helpers.php')) {
    require_once __DIR__ . '/db_helpers.php';
}
if (is_file(__DIR__ . '/admin-comm-lib.php')) {
    require_once __DIR__ . '/admin-comm-lib.php';
}
$code = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? ''));
$dest = 'home.php';
if ($code !== '' && isset($pdo) && $pdo instanceof PDO) {
    try {
        commEnsureSchema($pdo);
        $st = $pdo->prepare('SELECT id, destination_url FROM comm_tracking_links WHERE short_code=? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('INSERT INTO comm_clicks(tracking_id) VALUES (?)')->execute([(int)$row['id']]);
            $u = trim((string)$row['destination_url']);
            if (preg_match('#^https?://#i', $u) || preg_match('#^[a-zA-Z0-9_./?=&%-]+\.php#', $u)) {
                $dest = $u;
            }
        }
    } catch (Throwable $e) {
    }
}
header('Location: ' . $dest, true, 302);
exit;
