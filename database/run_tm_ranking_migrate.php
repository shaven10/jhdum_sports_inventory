<?php
/**
 * Create intramural_event_tm_ranking for per-event TM Event Rankings access.
 * Run once: php database/run_tm_ranking_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureTmRankingAccessTable();
echo "Tournament manager ranking access table is ready.\n";
