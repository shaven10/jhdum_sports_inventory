<?php
/**
 * Create intramural_event_team_positions for per-event Team 1…N settings.
 * Run once: php database/run_event_team_positions_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureEventTeamPositionsTable();
echo "Event team positions table is ready.\n";
