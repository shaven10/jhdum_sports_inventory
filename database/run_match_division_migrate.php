<?php
/**
 * Add division_id to intramural_matches for per-division brackets.
 * Run once: php database/run_match_division_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureMatchDivisionColumn();
echo "Match division_id column is ready.\n";
