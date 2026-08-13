<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Adding roster lock columns to intramural_seasons...\n";

if (!columnExists($db, 'intramural_seasons', 'roster_locked')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_archived');
    echo "Added roster_locked\n";
}

if (!columnExists($db, 'intramural_seasons', 'roster_locked_at')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked_at DATETIME DEFAULT NULL AFTER roster_locked');
    echo "Added roster_locked_at\n";
}

if (!columnExists($db, 'intramural_seasons', 'roster_locked_by')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_locked_by INT DEFAULT NULL AFTER roster_locked_at');
    try {
        $db->exec('ALTER TABLE intramural_seasons ADD CONSTRAINT fk_season_roster_locked_by FOREIGN KEY (roster_locked_by) REFERENCES users(id) ON DELETE SET NULL');
    } catch (PDOException $e) {
        echo "FK note: " . $e->getMessage() . "\n";
    }
    echo "Added roster_locked_by\n";
}

if (!columnExists($db, 'intramural_seasons', 'roster_lock_date')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN roster_lock_date DATE DEFAULT NULL AFTER roster_locked');
    echo "Added roster_lock_date\n";
}

echo "DONE\n";
