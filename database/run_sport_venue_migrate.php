<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

ensureSportVenueColumn();
echo "intramural_sports.venue column is ready.\n";
