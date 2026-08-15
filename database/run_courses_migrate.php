<?php
/**
 * Create athlete_courses and seed default JHCSC programs.
 * Run once: php database/run_courses_migrate.php
 */
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    requireRole(['admin']);
}

ensureAthleteCoursesSchema();
echo "Athlete courses schema is ready.\n";
