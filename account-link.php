<?php
declare(strict_types=1);
require_once __DIR__.'/messenger-login-lib.php';
melkinoMessengerSessionStart();
$p=melkinoMessengerContext();
header('Cache-Control: no-store, private');
header('Location: login.php?redirect=profile.php'.($p!==''?'&messenger='.rawurlencode($p):''),true,302);
exit;
