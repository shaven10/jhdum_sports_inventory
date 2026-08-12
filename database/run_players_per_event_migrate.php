<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExists($db, 'intramural_sports', 'players_per_event')) {
    echo "Adding intramural_sports.players_per_event...\n";
    $db->exec('ALTER TABLE intramural_sports ADD COLUMN players_per_event INT DEFAULT NULL AFTER category');
    echo "Column added.\n";
} else {
    echo "intramural_sports.players_per_event already exists.\n";
}

$updated = seedSportPlayersPerEvent($db);
echo "Seeded players_per_event for $updated sport row(s).\n";
echo "Done.\n";
