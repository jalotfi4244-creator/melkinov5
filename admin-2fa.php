<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

/**
 * Melkino V2 — thin entrypoint (spec §4): admin two-factor auth (TOTP).
 * Setup / verify / revoke via Admin2faController. Requires admin password
 * session; the V2 shell redirects here when 2FA is enrolled but not yet
 * satisfied this session. New page (no legacy copy).
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

echo (new \Melkino\Http\Controllers\Admin2faController())->handle();
