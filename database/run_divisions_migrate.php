<?php
/**
 * Create intramural divisions + division sports + team.division_id.
 * Run once: php database/run_divisions_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureIntramuralDivisionsSchema();
ensureDivisionSportsAutoIncrement(getDB());
echo "Intramural divisions schema is ready.\n";
