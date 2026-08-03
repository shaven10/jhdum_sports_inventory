<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Adding rank_first_to_last tournament format...\n";

try {
    $db->exec("ALTER TABLE intramural_sports MODIFY COLUMN tournament_format ENUM(
        'round_robin',
        'single_elimination',
        'single_elimination_consolation',
        'double_elimination',
        'group_knockout',
        'rank_first_to_last',
        'custom'
    ) NOT NULL DEFAULT 'round_robin'");
    echo "tournament_format ENUM updated.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Done.\n";
