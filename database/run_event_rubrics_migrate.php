<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

echo "Ensuring socio-cultural event rubric tables...\n";
ensureEventRubricTables();
$created = seedDefaultRubricsForSocioEvents();
echo "Rubric tables are ready.\n";
if ($created > 0) {
    echo "Seeded {$created} default criterion row(s) for events with no rubric yet.\n";
}
echo "Done.\n";
