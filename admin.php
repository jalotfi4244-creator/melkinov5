<?php
declare(strict_types=1);

/**
 * Melkino V2 — admin dashboard entry (spec §33). New URL (no legacy conflict).
 * All existing admin-*.php pages keep working standalone and are linked from the shell.
 * Converted panel tabs render here via ?tab=<name> (spec §34–§37).
 */
require_once __DIR__ . '/app/Bootstrap/app.php';

$tab = (string) ($_GET['tab'] ?? '');
if ($tab !== '') {
    echo (new \Melkino\Http\Controllers\AdminTabController())->handle($tab);
    exit;
}

echo (new \Melkino\Http\Controllers\AdminDashboardController())->render();
