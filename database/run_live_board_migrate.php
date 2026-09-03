<?php
require_once __DIR__ . '/../config/database.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

echo "Adding live board columns to intramural_seasons...\n";

$anchor = 'is_archived';
foreach (['results_locked_by', 'roster_locked_by', 'is_archived'] as $candidate) {
    if (columnExists($db, 'intramural_seasons', $candidate)) {
        $anchor = $candidate;
        break;
    }
}

if (!columnExists($db, 'intramural_seasons', 'live_board_enabled')) {
    $db->exec("ALTER TABLE intramural_seasons ADD COLUMN live_board_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER `{$anchor}`");
    echo "Added live_board_enabled\n";
}

if (!columnExists($db, 'intramural_seasons', 'live_board_updated_at')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN live_board_updated_at DATETIME DEFAULT NULL AFTER live_board_enabled');
    echo "Added live_board_updated_at\n";
}

if (!columnExists($db, 'intramural_seasons', 'live_board_updated_by')) {
    $db->exec('ALTER TABLE intramural_seasons ADD COLUMN live_board_updated_by INT DEFAULT NULL AFTER live_board_updated_at');
    echo "Added live_board_updated_by\n";
}

echo "DONE\n";
