<?php
/**
 * Add results lock columns to intramural_seasons.
 * Run once: php database/run_results_lock_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureResultsLockColumns();
echo "Results lock columns are ready on intramural_seasons.\n";
