<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Updating intramural_matches for fixture generation...\n";

// Allow unscheduled fixtures (tabulator assigns datetime later)
try {
    $db->exec('ALTER TABLE intramural_matches MODIFY COLUMN scheduled_at DATETIME NULL DEFAULT NULL');
    echo "scheduled_at is now nullable\n";
} catch (PDOException $e) {
    echo "scheduled_at: " . $e->getMessage() . "\n";
}

// Later bracket rounds may have TBD opponents
try {
    $db->exec('ALTER TABLE intramural_matches MODIFY COLUMN team_a_id INT NULL');
    $db->exec('ALTER TABLE intramural_matches MODIFY COLUMN team_b_id INT NULL');
    echo "team_a_id / team_b_id nullable for TBD bracket slots\n";
} catch (PDOException $e) {
    echo "team columns: " . $e->getMessage() . "\n";
}

$columns = [
    'round_number' => "INT NOT NULL DEFAULT 1 AFTER sport_id",
    'round_label' => "VARCHAR(80) DEFAULT NULL AFTER round_number",
    'match_order' => "INT NOT NULL DEFAULT 0 AFTER round_label",
    'is_generated' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER match_order",
];

foreach ($columns as $col => $def) {
    if (!columnExists($db, 'intramural_matches', $col)) {
        echo "Adding intramural_matches.$col...\n";
        $db->exec("ALTER TABLE intramural_matches ADD COLUMN $col $def");
    }
}

echo "DONE\n";
