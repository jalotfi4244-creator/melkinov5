<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/form-options.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(['success' => true, 'combos' => melkinoFormComboCatalog()], JSON_UNESCAPED_UNICODE);
