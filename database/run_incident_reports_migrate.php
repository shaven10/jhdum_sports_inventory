<?php
/**
 * Create incident_reports table.
 * Run once: php database/run_incident_reports_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureIncidentReportsTable();
echo "Incident reports table is ready.\n";
