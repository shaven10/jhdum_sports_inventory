<?php
/**
 * Add missing audit_logs columns on older installs.
 * Run once: php database/run_audit_logs_migrate.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

ensureAuditLogsSchema();
echo "audit_logs schema is ready.\n";
