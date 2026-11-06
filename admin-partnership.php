<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Melkino V2 — thin entrypoint (spec §4). Renders via AdminPartnershipController.
 * Rollback: append ?legacy=1 to run the
 * preserved legacy copy at views/legacy/admin-partnership-legacy.php.
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

if (($_GET['legacy'] ?? '') === '1') {
    require __DIR__ . '/views/legacy/admin-partnership-legacy.php';
    exit;
}

echo (new \Melkino\Http\Controllers\AdminPartnershipController())->handle();
