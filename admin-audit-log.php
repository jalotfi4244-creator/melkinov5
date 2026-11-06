<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Melkino V2 — thin entrypoint (spec §4): read-only audit-log viewer.
 * Renders via AdminAuditController inside the admin shell. New page
 * (no legacy copy); fixes the dead dashboard/nav links to this URL.
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

echo (new \Melkino\Http\Controllers\AdminAuditController())->render();
