<?php
declare(strict_types=1);

/**
 * Melkino V2 — thin entrypoint (spec §4). Renders via VisitsController.
 * Legacy DEFAULT (kill-switch); append ?v2=1 for V2,
 * legacy forced by ?legacy=1. Old note: append ?legacy=1 to run the
 * preserved legacy copy at views/legacy/visits-legacy.php.
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

if (!melkinoPublicV2()) {
    require __DIR__ . '/views/legacy/visits-legacy.php';
    exit;
}

echo (new \Melkino\Http\Controllers\VisitsController())->render();
