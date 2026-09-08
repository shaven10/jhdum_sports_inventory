<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "Ensuring equipment.is_borrowable column...\n";
ensureEquipmentBorrowableColumn();
echo "Borrowable equipment setting is ready.\n";
echo "Done.\n";
