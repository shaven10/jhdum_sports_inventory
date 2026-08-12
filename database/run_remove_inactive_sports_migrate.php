<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/intramurals.php';

$db = getDB();

$stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
$stmt->execute(['intramural_sports', 'is_active']);
if ((int) $stmt->fetchColumn() === 0) {
    echo "intramural_sports.is_active already removed.\n";
    exit(0);
}

$inactive = (int) $db->query('SELECT COUNT(*) FROM intramural_sports WHERE is_active = 0')->fetchColumn();
echo "Removing {$inactive} inactive sport(s)...\n";

removeInactiveSports();

echo "Done. intramural_sports.is_active column dropped.\n";
