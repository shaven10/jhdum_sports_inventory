<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

ensureSportGuidelinesColumn();
echo "intramural_sports.guidelines column is ready.\n";