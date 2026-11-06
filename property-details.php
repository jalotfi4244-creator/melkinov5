<?php
declare(strict_types=1);

/**
 * Melkino V2 — thin entrypoint (spec §4). Renders via PropertyDetailController.
 * Legacy DEFAULT (kill-switch); append ?v2=1 for V2,
 * legacy forced by ?legacy=1. Old note: append ?legacy=1 (or POST here for the wizard) to run the
 * preserved legacy copy at views/legacy/property-details-legacy.php.
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

require_once __DIR__ . '/shared-property.php';
melkinoPrepareSharedProperty();

if (!melkinoPublicV2()) {
    require __DIR__ . '/views/legacy/property-details-legacy.php';
    exit;
}

echo (new \Melkino\Http\Controllers\PropertyDetailController())->render();
