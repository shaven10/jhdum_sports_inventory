<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

echo "Ensuring intramural_event_ranks table...\n";
ensureEventRanksTable();
echo "Event rankings table is ready.\n";
echo "Done.\n";
