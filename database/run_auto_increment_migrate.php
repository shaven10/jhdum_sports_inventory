<?php
/**
 * Repair legacy tables missing AUTO_INCREMENT on id.
 * Run once: php database/run_auto_increment_migrate.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();
$tables = [
    'audit_logs',
    'notifications',
    'login_history',
    'announcements',
    'intramural_matches',
    'intramural_division_sports',
    'intramural_event_team_positions',
];

foreach ($tables as $table) {
    ensureTablePrimaryAutoIncrement($db, $table);
    echo "Checked $table\n";
}

ensureMatchDivisionColumn();
ensureIntramuralDivisionsSchema();
ensureEventTeamPositionsTable();

echo "AUTO_INCREMENT repair complete.\n";
