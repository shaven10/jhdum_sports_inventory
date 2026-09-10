<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

echo "Converting mixed sports to men and seeding women categories...\n";
migrateMixedSportCategories($db);
echo "Category enum updated to men/women only.\n";

$created = seedIntramuralSports($db);
echo "Ensured canonical sports exist ($created new row(s) added).\n";

$seeded = seedSportPlayersPerEvent($db);
echo "Backfilled players_per_event for $seeded row(s).\n";
echo "Done.\n";
