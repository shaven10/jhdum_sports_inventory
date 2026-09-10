<?php
/**
 * Create per-event results lock table.
 * Run once: php database/run_event_results_lock_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureEventResultsLockTable();
echo "Event results lock table is ready (intramural_event_results_locks).\n";
