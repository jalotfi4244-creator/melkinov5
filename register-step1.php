<?php
declare(strict_types=1);

/**
 * Melkino V2 — thin entrypoint (spec §4). Renders via RegisterController.
 * Legacy DEFAULT (kill-switch); append ?v2=1 for V2,
 * legacy forced by ?legacy=1. Old note: append ?legacy=1 to run the
 * preserved legacy copy at views/legacy/register-step1-legacy.php.
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

if (!melkinoPublicV2()) {
    require __DIR__ . '/views/legacy/register-step1-legacy.php';
    exit;
}

echo (new \Melkino\Http\Controllers\RegisterController())->step1();
