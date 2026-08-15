<?php
/**
 * Create announcements table (admin broadcast to non-student roles).
 * Run once: php database/run_announcements_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureAnnouncementsTable();
echo "Announcements table is ready.\n";
